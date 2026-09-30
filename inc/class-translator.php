<?php
/**
 * AI translation service.
 *
 * @package AgentSteamer_Lang
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translates posts into linked language versions using the configured provider.
 */
class AgentSteamer_Lang_Translator {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'agentsteamer_lang_auto_translate', array( $this, 'run_auto_translate' ), 10, 1 );
		add_action( 'agentsteamer_lang_translate_job', array( $this, 'run_job' ), 10, 1 );
		add_action( 'save_post', array( $this, 'maybe_schedule_auto' ), 30, 3 );
	}

	/**
	 * Queue an asynchronous translation job (one language per cron tick).
	 *
	 * Long documents can take ~50s per language, so translating all languages
	 * in a single HTTP request would hit the web-server timeout. This queues a
	 * background job processed one language at a time and reports progress.
	 *
	 * @param int    $post_id Source post id.
	 * @param string $source  Source language (optional).
	 * @param array  $targets Target language codes (empty = all other active).
	 * @return array|WP_Error Job snapshot.
	 */
	public function queue_job( $post_id, $source = '', $targets = array() ) {
		$available = $this->is_available();
		if ( is_wp_error( $available ) ) {
			return $available;
		}

		$post_id = (int) $post_id;
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'agentsteamer_lang_nopost', __( '源文章不存在。', 'agentsteamer-lang' ) );
		}

		$source    = agentsteamer_lang_normalize_code( $source );
		$post_lang = AgentSteamer_Lang_Taxonomy::get_post_lang( $post_id );
		if ( '' !== $source && $source !== $post_lang && AgentSteamer_Lang_Languages::get( $source ) ) {
			AgentSteamer_Lang_Taxonomy::set_post_lang( $post_id, $source );
			AgentSteamer_Lang_Translations::ensure_link( $post_id, $source );
			$post_lang = $source;
		}
		if ( '' === $post_lang ) {
			return new WP_Error( 'agentsteamer_lang_nosrclang', __( '请先为文章指定语言。', 'agentsteamer-lang' ) );
		}

		$queue = array();
		if ( ! empty( $targets ) ) {
			foreach ( (array) $targets as $t ) {
				$t = agentsteamer_lang_normalize_code( $t );
				if ( '' !== $t && $t !== $post_lang && AgentSteamer_Lang_Languages::get( $t ) && ! in_array( $t, $queue, true ) ) {
					$queue[] = $t;
				}
			}
		} else {
			foreach ( AgentSteamer_Lang_Languages::all( true ) as $lang ) {
				if ( $lang['code'] !== $post_lang ) {
					$queue[] = $lang['code'];
				}
			}
		}

		if ( empty( $queue ) ) {
			return new WP_Error( 'agentsteamer_lang_notarget', __( '没有需要翻译的目标语言。', 'agentsteamer-lang' ) );
		}

		$job = array(
			'post_id' => $post_id,
			'source'  => $post_lang,
			'queue'   => $queue,
			'done'    => array(),
			'errors'  => array(),
			'running' => true,
			'started' => time(),
			'updated' => time(),
		);
		update_option( self::job_key( $post_id ), $job, false );

		return $this->job_snapshot( $job );
	}

	/**
	 * Process the next language of a queued job (bounded to one language).
	 *
	 * Driven by the editor's polling requests so it works even when WP-Cron's
	 * loopback is unavailable (e.g. behind a reverse-proxy subdirectory).
	 *
	 * @param int $post_id Post id.
	 * @return array Job snapshot.
	 */
	public function step_job( $post_id ) {
		$post_id = (int) $post_id;
		$key     = self::job_key( $post_id );
		$job     = get_option( $key );
		if ( ! is_array( $job ) || empty( $job['queue'] ) ) {
			return $this->job_status( $post_id );
		}

		@set_time_limit( 0 );

		// Peek (don't shift) so a request killed by the gateway timeout leaves
		// the language queued and it is retried on the next poll.
		$target = isset( $job['queue'][0] ) ? $job['queue'][0] : '';
		$result = $this->translate_post( $post_id, $target, array( 'create_review' => true ) );

		array_shift( $job['queue'] );
		if ( is_wp_error( $result ) ) {
			$job['errors'][ $target ] = $result->get_error_message();
		} else {
			$job['done'][] = $target;
		}

		$job['updated'] = time();
		$job['running'] = ! empty( $job['queue'] );
		update_option( $key, $job, false );

		return $this->job_snapshot( $job );
	}

	/**
	 * Job option key.
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	public static function job_key( $post_id ) {
		return 'agentsteamer_lang_job_' . (int) $post_id;
	}

	/**
	 * Cron handler: translate one language and reschedule until done.
	 *
	 * @param int $post_id Post id.
	 */
	public function run_job( $post_id ) {
		$post_id = (int) $post_id;
		$key     = self::job_key( $post_id );
		$job     = get_option( $key );
		if ( ! is_array( $job ) || empty( $job['queue'] ) ) {
			return;
		}

		@set_time_limit( 0 );

		$target = array_shift( $job['queue'] );
		$result = $this->translate_post( $post_id, $target, array( 'create_review' => true ) );
		if ( is_wp_error( $result ) ) {
			$job['errors'][ $target ] = $result->get_error_message();
		} else {
			$job['done'][] = $target;
		}
		$job['updated'] = time();
		$job['running'] = ! empty( $job['queue'] );
		update_option( $key, $job, false );

		if ( ! empty( $job['queue'] ) ) {
			if ( ! wp_next_scheduled( 'agentsteamer_lang_translate_job', array( $post_id ) ) ) {
				wp_schedule_single_event( time() + 2, 'agentsteamer_lang_translate_job', array( $post_id ) );
			}
			if ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) {
				spawn_cron();
			}
		}
	}

	/**
	 * Snapshot of a job's status for the editor.
	 *
	 * @param int $post_id Post id.
	 * @return array
	 */
	public function job_status( $post_id ) {
		$job = get_option( self::job_key( $post_id ) );
		if ( ! is_array( $job ) ) {
			return array(
				'post_id' => (int) $post_id,
				'state'   => 'none',
				'done'    => array(),
				'queue'   => array(),
				'errors'  => array(),
			);
		}
		return $this->job_snapshot( $job );
	}

	/**
	 * Build the job snapshot payload.
	 *
	 * @param array $job Job data.
	 * @return array
	 */
	protected function job_snapshot( $job ) {
		$queue    = isset( $job['queue'] ) ? (array) $job['queue'] : array();
		$done     = isset( $job['done'] ) ? (array) $job['done'] : array();
		$finished = empty( $queue );
		return array(
			'post_id'  => (int) $job['post_id'],
			'source'   => isset( $job['source'] ) ? $job['source'] : '',
			'state'    => $finished ? 'done' : 'running',
			'done'     => array_values( $done ),
			'queue'    => array_values( $queue ),
			'errors'   => isset( $job['errors'] ) ? $job['errors'] : array(),
			'total'    => count( $done ) + count( $queue ),
			'review_url' => admin_url( 'admin.php?page=agentsteamer-lang-reviews' ),
		);
	}

	/**
	 * Whether AI translation is usable.
	 *
	 * @return true|WP_Error
	 */
	public function is_available() {
		if ( ! agentsteamer_lang_get_option( 'ai_enabled', 1 ) ) {
			return new WP_Error( 'agentsteamer_lang_ai_off', __( 'AI 翻译未启用。', 'agentsteamer-lang' ) );
		}
		$provider = AgentSteamer_Lang_Provider_Manager::get_provider();
		if ( is_wp_error( $provider ) ) {
			return $provider;
		}
		return true;
	}

	/**
	 * Translate a post into a target language (draft) with review entry.
	 *
	 * @param int    $source_id Source post id.
	 * @param string $target    Target language code.
	 * @param array  $args      Options: polish (bool), create_review (bool).
	 * @return array|WP_Error { post_id, review_id, edit_url, review_url, summary }.
	 */
	public function translate_post( $source_id, $target, array $args = array() ) {
		$available = $this->is_available();
		if ( is_wp_error( $available ) ) {
			return $available;
		}

		$source_id = (int) $source_id;
		$source    = get_post( $source_id );
		if ( ! $source ) {
			return new WP_Error( 'agentsteamer_lang_nopost', __( '源文章不存在。', 'agentsteamer-lang' ) );
		}

		$target = agentsteamer_lang_normalize_code( $target );
		$target_lang = AgentSteamer_Lang_Languages::get( $target );
		if ( ! $target_lang ) {
			return new WP_Error( 'agentsteamer_lang_nolang', __( '目标语言不存在。', 'agentsteamer-lang' ) );
		}

		$source_lang = AgentSteamer_Lang_Taxonomy::get_post_lang( $source_id );
		if ( '' === $source_lang ) {
			return new WP_Error( 'agentsteamer_lang_nosrclang', __( '请先为源文章指定语言。', 'agentsteamer-lang' ) );
		}
		if ( $source_lang === $target ) {
			return new WP_Error( 'agentsteamer_lang_same', __( '目标语言与源语言相同。', 'agentsteamer-lang' ) );
		}

		$trid      = AgentSteamer_Lang_Translations::ensure_link( $source_id, $source_lang );
		$existing  = $this->find_translation_id( $trid, $target );
		$old_body  = '';

		if ( $existing ) {
			$target_id = $existing;
			$old_body  = (string) get_post_field( 'post_content', $existing );
		} else {
			$target_id = AgentSteamer_Lang_Translations::create_translation(
				$source_id,
				$target,
				array( 'status' => agentsteamer_lang_get_option( 'translate_status', 'draft' ) )
			);
			if ( is_wp_error( $target_id ) ) {
				return $target_id;
			}
		}

		$provider = AgentSteamer_Lang_Provider_Manager::get_provider();
		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		// Title / excerpt.
		$title = $this->translate_text( $source->post_title, $target );
		if ( is_wp_error( $title ) ) {
			return $title;
		}
		$excerpt = '';
		if ( '' !== trim( (string) $source->post_excerpt ) ) {
			$excerpt = $this->translate_text( $source->post_excerpt, $target );
			if ( is_wp_error( $excerpt ) ) {
				$excerpt = '';
			}
		}

		// Body (HTML).
		$body = $this->translate_html( (string) $source->post_content, $target );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		// Optional polish pass.
		$polish = ! empty( $args['polish'] ) || agentsteamer_lang_get_option( 'polish', 0 );
		if ( $polish ) {
			$polished = $this->polish( $body, $target );
			if ( ! is_wp_error( $polished ) && '' !== trim( $polished ) ) {
				$body = $polished;
			}
		}

		$status = get_post_status( $target_id );
		if ( ! in_array( $status, array( 'draft', 'pending', 'publish', 'private' ), true ) ) {
			$status = 'draft';
		}

		wp_update_post(
			array(
				'ID'           => $target_id,
				'post_title'   => $title,
				'post_excerpt' => $excerpt,
				'post_content' => wp_kses_post( $body ),
			)
		);

		// SEO fields (AgentSteamer SEO/GEO plugin), when present.
		if ( agentsteamer_lang_get_option( 'translate_seo', 1 ) ) {
			$this->translate_seo_fields( $source_id, $target_id, $target );
		}

		// Record on the translation row.
		AgentSteamer_Lang_Translations::ensure_link( $target_id, $target, $trid, $source_lang, array( 'ai_generated' => 1, 'model' => $this->model_label() ) );
		AgentSteamer_Lang_Translations::set_status( $target_id, ( 'publish' === $status ) ? 'published' : 'draft' );

		$summary = sprintf(
			/* translators: 1: source language, 2: target language */
			__( '由 %1$s 翻译为 %2$s（模型：%3$s）', 'agentsteamer-lang' ),
			$source_lang,
			$target,
			$this->model_label()
		);

		$review_id = 0;
		if ( ! isset( $args['create_review'] ) || $args['create_review'] ) {
			$review    = new AgentSteamer_Lang_Review();
			$review_id = $review->add(
				$source_id,
				'content',
				$old_body,
				(string) get_post_field( 'post_content', $target_id ),
				$summary,
				array( sprintf( __( '标题：%s', 'agentsteamer-lang' ), $title ) ),
				$target_id
			);
		}

		return array(
			'post_id'    => $target_id,
			'review_id'  => $review_id,
			'edit_url'   => get_edit_post_link( $target_id, 'raw' ),
			'review_url' => admin_url( 'admin.php?page=agentsteamer-lang-reviews' ),
			'summary'    => $summary,
		);
	}

	/**
	 * Translate a post from its own language into every other active language.
	 *
	 * @param int    $post_id Source post id.
	 * @param string $source  Optional source language code (saved if the post has none).
	 * @param array  $args    Options passed to translate_post().
	 * @return array|WP_Error { source, results: [ { lang, ok, post_id?, edit_url?, review_id?, error? } ] }.
	 */
	public function translate_all( $post_id, $source = '', array $args = array() ) {
		$post_id = (int) $post_id;
		$source  = agentsteamer_lang_normalize_code( $source );
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'agentsteamer_lang_nopost', __( '源文章不存在。', 'agentsteamer-lang' ) );
		}

		$post_lang = AgentSteamer_Lang_Taxonomy::get_post_lang( $post_id );
		if ( '' !== $source && $source !== $post_lang && AgentSteamer_Lang_Languages::get( $source ) ) {
			AgentSteamer_Lang_Taxonomy::set_post_lang( $post_id, $source );
			AgentSteamer_Lang_Translations::ensure_link( $post_id, $source );
			$post_lang = $source;
		}
		if ( '' === $post_lang ) {
			return new WP_Error( 'agentsteamer_lang_nosrclang', __( '请先为文章指定语言。', 'agentsteamer-lang' ) );
		}

		$languages = AgentSteamer_Lang_Languages::all( true );
		if ( count( $languages ) < 2 ) {
			return new WP_Error( 'agentsteamer_lang_onlyone', __( '至少需要配置两种语言。', 'agentsteamer-lang' ) );
		}

		$results = array();
		foreach ( $languages as $lang ) {
			if ( $lang['code'] === $post_lang ) {
				continue;
			}
			$r = $this->translate_post( $post_id, $lang['code'], $args );
			if ( is_wp_error( $r ) ) {
				$results[] = array(
					'lang'  => $lang['code'],
					'ok'    => false,
					'error' => $r->get_error_message(),
				);
			} else {
				$results[] = array(
					'lang'      => $lang['code'],
					'ok'        => true,
					'post_id'   => $r['post_id'],
					'edit_url'  => $r['edit_url'],
					'review_id' => $r['review_id'],
				);
			}
		}

		return array(
			'source'  => $post_lang,
			'results' => $results,
		);
	}

	/**
	 * Find an existing translation post id for a language in a group.
	 *
	 * @param int    $trid   Group id.
	 * @param string $target Target language.
	 * @return int
	 */
	protected function find_translation_id( $trid, $target ) {
		foreach ( AgentSteamer_Lang_Translations::rows_for_trid( $trid ) as $row ) {
			if ( $row['lang'] === $target && get_post( (int) $row['element_id'] ) ) {
				return (int) $row['element_id'];
			}
		}
		return 0;
	}

	/**
	 * Translate a plain string.
	 *
	 * @param string $text   Text.
	 * @param string $target Target language.
	 * @return string|WP_Error
	 */
	public function translate_text( $text, $target ) {
		$text = (string) $text;
		if ( '' === trim( $text ) ) {
			return '';
		}
		$chunk = agentsteamer_lang_trim( $text, (int) agentsteamer_lang_get_option( 'chunk_chars', 4000 ) );

		$protected = $this->protect( $chunk );
		$result    = $this->chat( $protected['text'], $target, 'translate' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->restore( $result, $protected['map'] );
	}

	/**
	 * Translate an HTML body (chunked, structure-preserving).
	 *
	 * @param string $html   HTML.
	 * @param string $target Target language.
	 * @return string|WP_Error
	 */
	public function translate_html( $html, $target ) {
		$html = (string) $html;
		if ( '' === trim( $html ) ) {
			return '';
		}

		$protected = $this->protect( $html );
		$chunks    = $this->chunk( $protected['text'], (int) agentsteamer_lang_get_option( 'chunk_chars', 4000 ) );

		$out = array();
		foreach ( $chunks as $chunk ) {
			if ( '' === trim( $chunk ) ) {
				$out[] = $chunk;
				continue;
			}
			$translated = $this->chat( $chunk, $target, 'translate' );
			if ( is_wp_error( $translated ) ) {
				return $translated;
			}
			$out[] = $translated;
		}

		return $this->restore( implode( "\n", $out ), $protected['map'] );
	}

	/**
	 * Optional polish pass.
	 *
	 * @param string $html   HTML.
	 * @param string $target Target language.
	 * @return string|WP_Error
	 */
	public function polish( $html, $target ) {
		$protected = $this->protect( (string) $html );
		$chunks    = $this->chunk( $protected['text'], (int) agentsteamer_lang_get_option( 'chunk_chars', 4000 ) );
		$out       = array();
		foreach ( $chunks as $chunk ) {
			if ( '' === trim( $chunk ) ) {
				continue;
			}
			$r = $this->chat( $chunk, $target, 'polish' );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$out[] = $r;
		}
		return $this->restore( implode( "\n", $out ), $protected['map'] );
	}

	/**
	 * Translate SEO meta fields stored by the AgentSteamer SEO/GEO plugin.
	 *
	 * @param int    $source_id Source post.
	 * @param int    $target_id Target post.
	 * @param string $target    Target language.
	 */
	protected function translate_seo_fields( $source_id, $target_id, $target ) {
		if ( ! function_exists( 'agentsteamer_ai_meta_key' ) ) {
			return;
		}
		$fields = array(
			'title'         => '',
			'description'   => '',
			'focus_keyword' => '',
		);
		$has = false;
		foreach ( array_keys( $fields ) as $field ) {
			$meta = get_post_meta( $source_id, agentsteamer_ai_meta_key( $field ), true );
			if ( '' !== (string) $meta ) {
				$fields[ $field ] = (string) $meta;
				$has              = true;
			}
		}
		if ( ! $has ) {
			return;
		}

		$translated = $this->translate_meta( $fields, $target );
		if ( is_wp_error( $translated ) ) {
			return;
		}
		foreach ( $translated as $field => $value ) {
			if ( '' !== (string) $value ) {
				update_post_meta( $target_id, agentsteamer_ai_meta_key( $field ), $value );
			}
		}
	}

	/**
	 * Translate a set of meta fields in one JSON call.
	 *
	 * @param array  $fields Map field => value.
	 * @param string $target Target language.
	 * @return array|WP_Error
	 */
	protected function translate_meta( array $fields, $target ) {
		$provider = AgentSteamer_Lang_Provider_Manager::get_provider();
		if ( is_wp_error( $provider ) ) {
			return $provider;
		}
		$lang     = AgentSteamer_Lang_Languages::get( $target );
		$langname = $lang ? ( $lang['native_name'] ? $lang['native_name'] : $lang['name'] ) : $target;

		$system = agentsteamer_lang_prompt( 'translate_meta' );
		$user   = '目标语言：' . $langname . ' (' . $target . ")\n\n原文 JSON：\n" . wp_json_encode( $fields, JSON_UNESCAPED_UNICODE );

		$result = $provider->chat(
			array(
				array( 'role' => 'system', 'content' => $system ),
				array( 'role' => 'user', 'content' => $user ),
			),
			array(
				'max_tokens'  => 900,
				'timeout'     => (int) agentsteamer_lang_get_option( 'timeout', 60 ),
				'temperature' => 0.2,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = AgentSteamer_Lang_Provider_Manager::extract_json( $result['content'] );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'agentsteamer_lang_parse', __( '无法解析模型返回的元数据。', 'agentsteamer-lang' ) );
		}
		$out = array();
		foreach ( array_keys( $fields ) as $field ) {
			$out[ $field ] = isset( $data[ $field ] ) ? sanitize_text_field( $data[ $field ] ) : '';
		}
		return $out;
	}

	/**
	 * Single chat call with the translation prompt.
	 *
	 * @param string $text       Text (already protected).
	 * @param string $target     Target language.
	 * @param string $prompt_key Prompt key.
	 * @return string|WP_Error
	 */
	protected function chat( $text, $target, $prompt_key ) {
		$provider = AgentSteamer_Lang_Provider_Manager::get_provider();
		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		$lang     = AgentSteamer_Lang_Languages::get( $target );
		$langname = $lang ? ( $lang['native_name'] ? $lang['native_name'] : $lang['name'] ) : $target;

		$system = agentsteamer_lang_prompt( $prompt_key );
		$gloss  = AgentSteamer_Lang_Glossary::prompt_block( $target );

		$user = '目标语言：' . $langname . ' (' . $target . ')' . "\n";
		if ( '' !== $gloss ) {
			$user .= $gloss . "\n";
		}
		$user .= "\n待翻译内容：\n" . $text;

		$result = $provider->chat(
			array(
				array( 'role' => 'system', 'content' => $system ),
				array( 'role' => 'user', 'content' => $user ),
			),
			array(
				'max_tokens'  => max( 1024, (int) agentsteamer_lang_get_option( 'max_tokens', 4096 ) ),
				'timeout'     => (int) agentsteamer_lang_get_option( 'timeout', 60 ),
				'temperature' => (float) agentsteamer_lang_get_option( 'temperature', 0.3 ),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$content = (string) $result['content'];
		$content = preg_replace( '/^```[a-z]*\s*/i', '', trim( $content ) );
		$content = preg_replace( '/\s*```\s*$/', '', $content );
		return trim( $content );
	}

	/**
	 * Replace structure that must not be translated with placeholders.
	 *
	 * @param string $text Input.
	 * @return array { text, map }.
	 */
	protected function protect( $text ) {
		$map  = array();
		$text = (string) $text;

		$store = function ( $value ) use ( &$map ) {
			$i         = count( $map );
			$map[ $i ] = $value;
			return '{{ASL_' . $i . '}}';
		};

		// <pre>/<code> blocks.
		$text = preg_replace_callback( '#<(pre|code)\b[^>]*>.*?</\1>#is', function ( $m ) use ( $store ) {
			return $store( $m[0] );
		}, $text );

		// Shortcodes.
		$text = preg_replace_callback( '/\[[^\]]+\]/', function ( $m ) use ( $store ) {
			return $store( $m[0] );
		}, $text );

		// URLs and emails.
		$text = preg_replace_callback( '#(https?://[^\s"\'<>]+|mailto:[^\s"\'<>]+)#i', function ( $m ) use ( $store ) {
			return $store( $m[0] );
		}, $text );

		return array(
			'text' => $text,
			'map'  => $map,
		);
	}

	/**
	 * Restore placeholders.
	 *
	 * @param string $text Output.
	 * @param array  $map  Placeholder map.
	 * @return string
	 */
	protected function restore( $text, $map ) {
		$text = (string) $text;
		if ( empty( $map ) ) {
			return $text;
		}
		return preg_replace_callback( '/\{\{\s*ASL_(\d+)\s*\}\}/i', function ( $m ) use ( $map ) {
			$i = (int) $m[1];
			return isset( $map[ $i ] ) ? $map[ $i ] : '';
		}, $text );
	}

	/**
	 * Split text into size-bounded chunks on block boundaries.
	 *
	 * @param string $text  Text.
	 * @param int    $limit Max chunk length.
	 * @return string[]
	 */
	protected function chunk( $text, $limit ) {
		$text = (string) $text;
		if ( $limit < 200 ) {
			$limit = 200;
		}
		$len = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
		if ( $len <= $limit ) {
			return array( $text );
		}

		$parts = preg_split( '#(?<=</p>|</h2>|</h3>|</h4>|</ul>|</ol>|</blockquote>|</figure>|</div>)\s*#i', $text );
		if ( ! $parts ) {
			return array( $text );
		}

		$chunks = array();
		$buffer = '';
		foreach ( $parts as $part ) {
			$buffer_len = function_exists( 'mb_strlen' ) ? mb_strlen( $buffer ) : strlen( $buffer );
			$part_len   = function_exists( 'mb_strlen' ) ? mb_strlen( $part ) : strlen( $part );
			if ( '' !== $buffer && ( $buffer_len + $part_len ) > $limit ) {
				$chunks[] = $buffer;
				$buffer   = '';
			}
			$buffer .= $part;
		}
		if ( '' !== trim( $buffer ) ) {
			$chunks[] = $buffer;
		}
		return $chunks;
	}

	/**
	 * Provider/model label.
	 *
	 * @return string
	 */
	protected function model_label() {
		$provider = AgentSteamer_Lang_Provider_Manager::get_provider();
		$label    = is_wp_error( $provider ) ? 'N/A' : $provider->get_label();
		return $label . ' / ' . agentsteamer_lang_get_option( 'model' );
	}

	/**
	 * Schedule auto-translation when configured.
	 *
	 * @param int     $post_id Post id.
	 * @param WP_Post $post    Post.
	 * @param bool    $update  Update flag.
	 */
	public function maybe_schedule_auto( $post_id, $post, $update ) {
		if ( ! $post instanceof WP_Post || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}
		if ( 'publish' !== $post->post_status ) {
			return;
		}
		if ( ! agentsteamer_lang_get_option( 'auto_translate', 0 ) || ! agentsteamer_lang_get_option( 'ai_enabled', 1 ) ) {
			return;
		}
		if ( ! in_array( $post->post_type, agentsteamer_lang_supported_post_types(), true ) ) {
			return;
		}
		$lang = AgentSteamer_Lang_Taxonomy::get_post_lang( $post_id );
		if ( '' === $lang ) {
			return;
		}
		wp_schedule_single_event( time() + 30, 'agentsteamer_lang_auto_translate', array( (int) $post_id ) );
	}

	/**
	 * Cron handler: translate a post into every missing language.
	 *
	 * @param int $post_id Post id.
	 */
	public function run_auto_translate( $post_id ) {
		$source_lang = AgentSteamer_Lang_Taxonomy::get_post_lang( $post_id );
		if ( '' === $source_lang ) {
			return;
		}
		$existing = array_keys( AgentSteamer_Lang_Translations::translations( $post_id ) );
		foreach ( AgentSteamer_Lang_Languages::all( true ) as $lang ) {
			if ( $lang['code'] === $source_lang || in_array( $lang['code'], $existing, true ) ) {
				continue;
			}
			$this->translate_post( $post_id, $lang['code'] );
		}
	}
}
