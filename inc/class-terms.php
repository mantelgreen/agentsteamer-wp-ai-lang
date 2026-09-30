<?php
/**
 * Taxonomy term translation (categories, tags, custom taxonomies).
 *
 * Each term gets a language and a translation group (term meta), mirroring the
 * post model. Translated posts are re-mapped to the target-language terms, and
 * front-end term queries are filtered to the current language.
 *
 * @package AgentSteamer_Lang
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages language + translation links for taxonomy terms.
 */
class AgentSteamer_Lang_Terms {

	/**
	 * Term language meta key.
	 */
	const LANG_META = '_asl_lang';

	/**
	 * Term translation-group meta key.
	 */
	const TRID_META = '_asl_trid';

	/**
	 * Internal-call depth: while > 0, front-end language filters are bypassed.
	 *
	 * @var int
	 */
	protected static $internal = 0;

	/**
	 * Term ids created during the current request.
	 *
	 * @var array
	 */
	protected static $new_terms = array();

	/**
	 * Post ids whose terms were set during the current request.
	 *
	 * @var array
	 */
	protected static $touched_posts = array();

	/**
	 * Run a callback with the front-end term filters bypassed.
	 *
	 * @param callable $callback Callback.
	 * @return mixed
	 */
	protected static function raw( $callback ) {
		self::$internal++;
		$result = call_user_func( $callback );
		self::$internal--;
		return $result;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_hooks' ), 30 );

		add_action( 'create_term', array( $this, 'on_create_term' ), 10, 3 );
		add_action( 'edited_term', array( $this, 'on_edit_term' ), 10, 3 );

		add_action( 'admin_post_agentsteamer_lang_term_translate', array( $this, 'handle_create_translation' ) );

		add_action( 'save_post', array( $this, 'assign_post_terms_language' ), 30, 3 );

		// Editor-created tags/categories: defer language assignment to shutdown,
		// once the post's language taxonomy has been applied.
		add_action( 'set_object_terms', array( $this, 'on_set_object_terms' ), 10, 6 );
		add_action( 'shutdown', array( $this, 'flush_term_languages' ) );

		add_filter( 'get_terms_args', array( $this, 'filter_terms_args' ), 10, 2 );
	}

	/**
	 * Translatable taxonomies (public, excluding the language taxonomy itself).
	 *
	 * @return string[]
	 */
	public static function taxonomies() {
		$taxes = get_taxonomies( array( 'public' => true ), 'names' );
		$taxes = array_values( array_diff( $taxes, array( AgentSteamer_Lang_Taxonomy::TAX ) ) );

		/**
		 * Filter the taxonomies that support translation.
		 *
		 * @param string[] $taxes Taxonomy names.
		 */
		return apply_filters( 'agentsteamer_lang_translatable_taxonomies', $taxes );
	}

	/**
	 * Register admin term fields once all taxonomies are known.
	 */
	public function register_hooks() {
		foreach ( self::taxonomies() as $tax ) {
			add_action( $tax . '_add_form_fields', array( $this, 'add_form_field' ) );
			add_action( $tax . '_edit_form_fields', array( $this, 'edit_form_field' ), 10, 2 );
		}
		add_action( 'created_term', array( $this, 'save_term_field' ), 10, 3 );
		add_action( 'edited_term', array( $this, 'save_term_field' ), 10, 3 );
	}

	/**
	 * Language of a term (content terms use _asl_lang; language terms _asl_code).
	 *
	 * @param int $term_id Term id.
	 * @return string
	 */
	public static function get_term_lang( $term_id ) {
		$code = get_term_meta( (int) $term_id, self::LANG_META, true );
		if ( ! $code ) {
			$code = get_term_meta( (int) $term_id, '_asl_code', true );
		}
		return $code ? agentsteamer_lang_normalize_code( $code ) : '';
	}

	/**
	 * Assign a language to a term.
	 *
	 * @param int    $term_id Term id.
	 * @param string $code    Language code.
	 */
	public static function set_term_lang( $term_id, $code ) {
		$code = agentsteamer_lang_normalize_code( $code );
		if ( '' === $code || ! AgentSteamer_Lang_Languages::get( $code ) ) {
			delete_term_meta( (int) $term_id, self::LANG_META );
			return;
		}
		update_term_meta( (int) $term_id, self::LANG_META, $code );
		self::ensure_link( $term_id, $code );
	}

	/**
	 * Translation group id of a term.
	 *
	 * @param int $term_id Term id.
	 * @return int
	 */
	public static function get_trid( $term_id ) {
		return (int) get_term_meta( (int) $term_id, self::TRID_META, true );
	}

	/**
	 * Allocate a term translation-group id.
	 *
	 * @return int
	 */
	public static function next_trid() {
		$next = (int) get_option( 'agentsteamer_lang_next_term_trid', 1 );
		if ( $next < 1 ) {
			$next = 1;
		}
		update_option( 'agentsteamer_lang_next_term_trid', $next + 1, false );
		return $next;
	}

	/**
	 * Ensure a term belongs to a translation group.
	 *
	 * @param int    $term_id Term id.
	 * @param string $lang    Optional language code.
	 * @return int Group id.
	 */
	public static function ensure_link( $term_id, $lang = '' ) {
		$term_id = (int) $term_id;
		if ( ! $term_id ) {
			return 0;
		}
		$trid = self::get_trid( $term_id );
		if ( ! $trid ) {
			$trid = self::next_trid();
			update_term_meta( $term_id, self::TRID_META, $trid );
		}
		if ( '' !== $lang ) {
			update_term_meta( $term_id, self::LANG_META, agentsteamer_lang_normalize_code( $lang ) );
		}
		return $trid;
	}

	/**
	 * Terms in the same translation group, keyed by language code.
	 *
	 * @param int $term_id Term id.
	 * @return WP_Term[] lang => term.
	 */
	public static function translations( $term_id ) {
		$term = self::raw(
			static function () use ( $term_id ) {
				return get_term( (int) $term_id );
			}
		);
		if ( ! $term || is_wp_error( $term ) ) {
			return array();
		}
		$trid = self::get_trid( $term_id );
		if ( ! $trid ) {
			return array();
		}
		$terms = self::raw(
			static function () use ( $term, $trid ) {
				return get_terms(
					array(
						'taxonomy'   => $term->taxonomy,
						'hide_empty' => false,
						'meta_query' => array(
							array(
								'key'   => self::TRID_META,
								'value' => $trid,
							),
						),
					)
				);
			}
		);
		$out = array();
		foreach ( (array) $terms as $t ) {
			$code = self::get_term_lang( $t->term_id );
			if ( $code ) {
				$out[ $code ] = $t;
			}
		}
		return $out;
	}

	/**
	 * Term id of a translation in a target language (0 when missing).
	 *
	 * @param int    $term_id Term id.
	 * @param string $target  Target language.
	 * @return int
	 */
	public static function translated_term_id( $term_id, $target ) {
		$all = self::translations( $term_id );
		$target = agentsteamer_lang_normalize_code( $target );
		return isset( $all[ $target ] ) ? (int) $all[ $target ]->term_id : 0;
	}

	/**
	 * Create a linked term in a target language.
	 *
	 * @param int    $source_term_id Source term id.
	 * @param string $target         Target language.
	 * @param array  $overrides      name / description / slug.
	 * @return int|WP_Error New term id.
	 */
	public static function create_translation( $source_term_id, $target, array $overrides = array() ) {
		$source = self::raw(
			static function () use ( $source_term_id ) {
				return get_term( (int) $source_term_id );
			}
		);
		if ( ! $source || is_wp_error( $source ) ) {
			return new WP_Error( 'agentsteamer_lang_noterm', __( '源术语不存在。', 'agentsteamer-lang' ) );
		}
		$target = agentsteamer_lang_normalize_code( $target );
		if ( ! AgentSteamer_Lang_Languages::get( $target ) ) {
			return new WP_Error( 'agentsteamer_lang_nolang', __( '目标语言不存在。', 'agentsteamer-lang' ) );
		}

		$existing = self::translated_term_id( $source_term_id, $target );
		if ( $existing ) {
			return new WP_Error( 'agentsteamer_lang_exists', __( '该语言的分类已存在。', 'agentsteamer-lang' ), array( 'existing' => $existing ) );
		}

		$trid = self::ensure_link( $source_term_id, self::get_term_lang( $source_term_id ) );

		$parent = 0;
		if ( $source->parent ) {
			$parent = self::translated_term_id( (int) $source->parent, $target );
		}

		$name = isset( $overrides['name'] ) ? $overrides['name'] : $source->name;

		$args = array(
			'description' => isset( $overrides['description'] ) ? $overrides['description'] : $source->description,
			'parent'      => $parent,
		);
		if ( ! empty( $overrides['slug'] ) ) {
			$args['slug'] = $overrides['slug'];
		} elseif ( '' !== trim( (string) $name ) ) {
			// Prefer a readable "{slug}-{lang}" over WordPress's "{slug}-2".
			$desired = sanitize_title( $name );
			if ( '' !== $desired && term_exists( $desired, $source->taxonomy ) ) {
				$args['slug'] = $desired . '-' . strtolower( preg_replace( '/[-_].*$/', '', $target ) );
			}
		}

		$result = wp_insert_term( $name, $source->taxonomy, $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$new_id = (int) $result['term_id'];
		update_term_meta( $new_id, self::TRID_META, $trid );
		update_term_meta( $new_id, self::LANG_META, $target );

		// Move any already-translated posts from the source term to the new one.
		self::backfill_posts( $source_term_id, $target );

		return $new_id;
	}

	/**
	 * Re-map every translated post's terms to its own language.
	 *
	 * Fixes posts translated before their terms were translated (their terms
	 * still point at the source-language term).
	 *
	 * @return int Number of posts updated.
	 */
	public static function resync_post_terms() {
		global $wpdb;
		$rows  = $wpdb->get_results( 'SELECT element_id, lang FROM ' . AgentSteamer_Lang_Translations::table() . " WHERE element_type = 'post'", ARRAY_A ); // phpcs:ignore WordPress.DB
		$count = 0;

		foreach ( (array) $rows as $row ) {
			$post_id = (int) $row['element_id'];
			$lang    = agentsteamer_lang_normalize_code( $row['lang'] );
			$post    = get_post( $post_id );
			if ( ! $post || '' === $lang ) {
				continue;
			}
			foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
				if ( AgentSteamer_Lang_Taxonomy::TAX === $taxonomy ) {
					continue;
				}
				$terms = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
				if ( is_wp_error( $terms ) || empty( $terms ) ) {
					continue;
				}
				$mapped  = array();
				$changed = false;
				foreach ( $terms as $term_id ) {
					if ( self::get_term_lang( $term_id ) === $lang ) {
						$mapped[] = (int) $term_id;
						continue;
					}
					$target = self::translated_term_id( $term_id, $lang );
					if ( $target ) {
						$mapped[] = $target;
						$changed  = true;
					} else {
						$mapped[] = (int) $term_id;
					}
				}
				if ( $changed ) {
					wp_set_object_terms( $post_id, array_values( array_unique( $mapped ) ), $taxonomy, false );
					$count++;
				}
			}
		}
		return $count;
	}

	/**
	 * Assign the target-language term to posts whose translation already exists.
	 *
	 * Covers the case where post translations were made before the term was
	 * translated (otherwise the new term stays empty and is hidden).
	 *
	 * @param int    $source_term_id Source term id.
	 * @param string $target         Target language.
	 * @return int Number of posts updated.
	 */
	public static function backfill_posts( $source_term_id, $target ) {
		$source = self::raw(
			static function () use ( $source_term_id ) {
				return get_term( (int) $source_term_id );
			}
		);
		if ( ! $source || is_wp_error( $source ) ) {
			return 0;
		}
		$target = agentsteamer_lang_normalize_code( $target );
		$new_id = self::translated_term_id( $source_term_id, $target );
		if ( ! $new_id ) {
			return 0;
		}

		$objects = get_objects_in_term( (int) $source_term_id, $source->taxonomy );
		if ( is_wp_error( $objects ) || empty( $objects ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $objects as $object_id ) {
			$trans = AgentSteamer_Lang_Translations::translations( $object_id );
			if ( empty( $trans[ $target ]['post_id'] ) ) {
				continue;
			}
			$target_post = (int) $trans[ $target ]['post_id'];
			$current     = wp_get_object_terms( $target_post, $source->taxonomy, array( 'fields' => 'ids' ) );
			if ( is_wp_error( $current ) ) {
				continue;
			}
			$current = array_diff( (array) $current, array( (int) $source_term_id, $new_id ) );
			$current[] = $new_id;
			wp_set_object_terms( $target_post, array_values( array_unique( $current ) ), $source->taxonomy, false );
			$count++;
		}
		return $count;
	}

	/**
	 * AI-translate a term's name and description into a target language.
	 *
	 * @param int    $term_id Term id.
	 * @param string $target  Target language.
	 * @return int|WP_Error New term id.
	 */
	public function translate_term( $term_id, $target ) {
		$source = self::raw(
			static function () use ( $term_id ) {
				return get_term( (int) $term_id );
			}
		);
		if ( ! $source || is_wp_error( $source ) ) {
			return new WP_Error( 'agentsteamer_lang_noterm', __( '源术语不存在。', 'agentsteamer-lang' ) );
		}

		$existing = self::translated_term_id( $term_id, $target );
		if ( $existing ) {
			// Re-translate into the existing term.
			$translator = new AgentSteamer_Lang_Translator();
			$name       = $translator->translate_text( $source->name, $target );
			$desc       = '' !== trim( (string) $source->description ) ? $translator->translate_text( $source->description, $target ) : '';
			if ( is_wp_error( $name ) ) {
				return $name;
			}
			wp_update_term(
				$existing,
				$source->taxonomy,
				array(
					'name'        => $name,
					'description' => is_wp_error( $desc ) ? $source->description : $desc,
				)
			);
			self::backfill_posts( $term_id, $target );
			return $existing;
		}

		$translator = new AgentSteamer_Lang_Translator();
		$name       = $translator->translate_text( $source->name, $target );
		if ( is_wp_error( $name ) ) {
			return $name;
		}
		$desc = '';
		if ( '' !== trim( (string) $source->description ) ) {
			$d    = $translator->translate_text( $source->description, $target );
			$desc = is_wp_error( $d ) ? '' : $d;
		}

		return self::create_translation(
			$term_id,
			$target,
			array(
				'name'        => $name,
				'description' => $desc,
			)
		);
	}

	/**
	 * Term creation: default the language when none provided.
	 *
	 * @param int    $term_id  Term id.
	 * @param int    $tt_id    Term taxonomy id.
	 * @param string $taxonomy Taxonomy.
	 */
	public function on_create_term( $term_id, $tt_id, $taxonomy ) {
		if ( ! in_array( $taxonomy, self::taxonomies(), true ) ) {
			return;
		}
		self::$new_terms[ (int) $term_id ] = true;
		$lang = self::get_term_lang( $term_id );
		if ( '' !== $lang ) {
			self::ensure_link( $term_id, $lang );
		}
		// Otherwise leave the language unassigned: terms created inline in the
		// editor inherit the post's language at shutdown (flush_term_languages).
	}

	/**
	 * Record posts whose terms were set (to resolve inline term languages later).
	 *
	 * @param int    $object_id  Object id.
	 * @param array  $terms      Terms.
	 * @param array  $tt_ids     Term taxonomy ids.
	 * @param string $taxonomy   Taxonomy.
	 * @param bool   $append     Append flag.
	 * @param array  $old_tt_ids Old term taxonomy ids.
	 */
	public function on_set_object_terms( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {
		if ( ! in_array( $taxonomy, self::taxonomies(), true ) ) {
			return;
		}
		if ( in_array( get_post_type( $object_id ), agentsteamer_lang_supported_post_types(), true ) ) {
			self::$touched_posts[ (int) $object_id ] = true;
		}
	}

	/**
	 * At shutdown, give editor-created terms the language of the post they were
	 * attached to (the post's language taxonomy is applied by then).
	 */
	public function flush_term_languages() {
		if ( empty( self::$touched_posts ) || empty( self::$new_terms ) ) {
			return;
		}
		foreach ( array_keys( self::$touched_posts ) as $post_id ) {
			$lang = AgentSteamer_Lang_Taxonomy::get_post_lang( $post_id );
			if ( '' === $lang ) {
				$lang = AgentSteamer_Lang_Languages::default_code();
			}
			if ( '' === $lang ) {
				continue;
			}
			foreach ( self::taxonomies() as $taxonomy ) {
				$terms = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
				if ( is_wp_error( $terms ) ) {
					continue;
				}
				foreach ( (array) $terms as $term_id ) {
					if ( isset( self::$new_terms[ (int) $term_id ] ) && '' === self::get_term_lang( $term_id ) ) {
						self::set_term_lang( $term_id, $lang );
					}
				}
			}
		}
	}

	/**
	 * Term edit: keep the translation group in sync.
	 *
	 * @param int    $term_id  Term id.
	 * @param int    $tt_id    Term taxonomy id.
	 * @param string $taxonomy Taxonomy.
	 */
	public function on_edit_term( $term_id, $tt_id, $taxonomy ) {
		if ( ! in_array( $taxonomy, self::taxonomies(), true ) ) {
			return;
		}
		$lang = self::get_term_lang( $term_id );
		if ( '' !== $lang ) {
			self::ensure_link( $term_id, $lang );
		}
	}

	/**
	 * Give terms created inline in the editor (tags, categories) the post's
	 * language when they have none, so an English post's new tags are English.
	 *
	 * @param int     $post_id Post id.
	 * @param WP_Post $post    Post.
	 * @param bool    $update  Update flag.
	 */
	public function assign_post_terms_language( $post_id, $post, $update ) {
		if ( ! $post instanceof WP_Post || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! in_array( $post->post_type, agentsteamer_lang_supported_post_types(), true ) ) {
			return;
		}
		$lang = AgentSteamer_Lang_Taxonomy::get_post_lang( $post_id );
		if ( '' === $lang ) {
			return;
		}
		foreach ( self::taxonomies() as $taxonomy ) {
			$terms = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			foreach ( (array) $terms as $term_id ) {
				if ( '' === self::get_term_lang( $term_id ) ) {
					self::set_term_lang( $term_id, $lang );
				}
			}
		}
	}

	/* -------------------------------------------------------------------------
	 * Front-end: filter term queries and localize term names to the language.
	 * ---------------------------------------------------------------------- */

	/**
	 * Restrict front-end term queries to the current language.
	 *
	 * @param array        $args       Query args.
	 * @param string|array $taxonomies Taxonomies.
	 * @return array
	 */
	public function filter_terms_args( $args, $taxonomies ) {
		if ( self::$internal > 0 ) {
			return $args;
		}
		// Explicit-ID lookups (term_exists, etc.) and object-scoped queries
		// (a specific post's terms) must not be language-filtered.
		if ( ! empty( $args['include'] ) || ! empty( $args['object_ids'] ) ) {
			return $args;
		}
		if ( ! empty( $args['suppress_filter'] ) ) {
			return $args;
		}
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $args;
		}
		// Never filter editor/background term pickers (REST / AJAX): the editor
		// must be able to see every language's terms.
		if ( wp_doing_ajax() ) {
			return $args;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return $args;
		}
		if ( ! agentsteamer_lang_is_enabled() ) {
			return $args;
		}
		$current = AgentSteamer_Lang_Router::current();
		if ( '' === $current ) {
			return $args;
		}
		$taxes = is_array( $taxonomies ) ? $taxonomies : array( $taxonomies );
		if ( ! array_intersect( $taxes, self::taxonomies() ) ) {
			return $args;
		}

		$default = AgentSteamer_Lang_Languages::default_code();
		$meta    = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();
		if ( $current === $default ) {
			$meta[] = array(
				'relation' => 'OR',
				array(
					'key'   => self::LANG_META,
					'value' => $current,
				),
				array(
					'key'     => self::LANG_META,
					'compare' => 'NOT EXISTS',
				),
			);
		} else {
			$meta[] = array(
				'key'   => self::LANG_META,
				'value' => $current,
			);
		}
		$args['meta_query'] = $meta;
		return $args;
	}

	/* -------------------------------------------------------------------------
	 * Admin: term language field + translation actions.
	 * ---------------------------------------------------------------------- */

	/**
	 * Language options for selects.
	 *
	 * @param string $selected Selected code.
	 * @return string
	 */
	protected function lang_options( $selected = '' ) {
		$out = '<option value="">' . esc_html__( '（未指定）', 'agentsteamer-lang' ) . '</option>';
		foreach ( AgentSteamer_Lang_Languages::all() as $lang ) {
			$out .= sprintf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $lang['code'] ),
				selected( $selected, $lang['code'], false ),
				esc_html( $lang['native_name'] ? $lang['native_name'] : $lang['name'] )
			);
		}
		return $out;
	}

	/**
	 * Add-form language field.
	 */
	public function add_form_field() {
		wp_nonce_field( 'asl_term_lang', 'asl_term_lang_nonce' );
		echo '<div class="form-field"><label for="asl_term_lang">' . esc_html__( '语言', 'agentsteamer-lang' ) . '</label>';
		echo '<select name="asl_term_lang" id="asl_term_lang">' . $this->lang_options( AgentSteamer_Lang_Languages::default_code() ) . '</select>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p>' . esc_html__( '该分类所属语言；可稍后在编辑页创建其它语言版本。', 'agentsteamer-lang' ) . '</p></div>';
	}

	/**
	 * Edit-form language field + translation list.
	 *
	 * @param WP_Term $term     Term.
	 * @param string  $taxonomy Taxonomy.
	 */
	public function edit_form_field( $term, $taxonomy ) {
		wp_nonce_field( 'asl_term_lang', 'asl_term_lang_nonce' );
		$current = self::get_term_lang( $term->term_id );
		$default = AgentSteamer_Lang_Languages::default_code();

		echo '<tr class="form-field"><th scope="row"><label for="asl_term_lang">' . esc_html__( '语言', 'agentsteamer-lang' ) . '</label></th><td>';
		echo '<select name="asl_term_lang" id="asl_term_lang">' . $this->lang_options( $current ? $current : $default ) . '</select>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</td></tr>';

		echo '<tr class="form-field"><th scope="row">' . esc_html__( '语言版本', 'agentsteamer-lang' ) . '</th><td>';
		$translations = self::translations( $term->term_id );
		echo '<ul style="margin:0 0 8px;">';
		foreach ( AgentSteamer_Lang_Languages::all() as $lang ) {
			$code = $lang['code'];
			$name = $lang['native_name'] ? $lang['native_name'] : $lang['name'];
			echo '<li><strong>' . esc_html( strtoupper( $code ) ) . '</strong> ';
			if ( $code === $current ) {
				echo esc_html__( '（本文）', 'agentsteamer-lang' );
			} elseif ( isset( $translations[ $code ] ) ) {
				$edit = get_edit_term_link( $translations[ $code ]->term_id, $taxonomy );
				echo '<a href="' . esc_url( $edit ) . '">' . esc_html( $name ) . '</a> — <a href="' . esc_url( get_term_link( $translations[ $code ] ) ) . '">' . esc_html__( '查看', 'agentsteamer-lang' ) . '</a>';
			} else {
				$ai = $this->action_url( $term->term_id, $code, 'ai' );
				echo '<a class="button button-small button-primary" href="' . esc_url( $ai ) . '">' . esc_html__( '创建AI翻译副本', 'agentsteamer-lang' ) . '</a>';
			}
			echo '</li>';
		}
		echo '</ul>';
		echo '<p class="description">' . esc_html__( '为分类创建其它语言版本，内容站按语言显示对应分类。', 'agentsteamer-lang' ) . '</p>';
		echo '</td></tr>';
	}

	/**
	 * Build a nonced term translation action URL.
	 *
	 * @param int    $term_id Term id.
	 * @param string $target  Target language.
	 * @param string $mode    copy | ai.
	 * @return string
	 */
	protected function action_url( $term_id, $target, $mode ) {
		$url = admin_url( 'admin-post.php?action=agentsteamer_lang_term_translate&term=' . (int) $term_id . '&target=' . urlencode( $target ) . '&mode=' . urlencode( $mode ) );
		return wp_nonce_url( $url, 'asl_term_translate_' . $term_id . '_' . $target );
	}

	/**
	 * Persist the term language.
	 *
	 * @param int    $term_id  Term id.
	 * @param int    $tt_id    Term taxonomy id.
	 * @param string $taxonomy Taxonomy.
	 */
	public function save_term_field( $term_id, $tt_id, $taxonomy ) {
		if ( ! in_array( $taxonomy, self::taxonomies(), true ) ) {
			return;
		}
		if ( ! isset( $_POST['asl_term_lang_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['asl_term_lang_nonce'] ) ), 'asl_term_lang' ) ) {
			return;
		}
		$code = isset( $_POST['asl_term_lang'] ) ? sanitize_text_field( wp_unslash( $_POST['asl_term_lang'] ) ) : '';
		self::set_term_lang( $term_id, $code );
	}

	/**
	 * Handle the create/AI term translation action.
	 */
	public function handle_create_translation() {
		$term_id = isset( $_GET['term'] ) ? (int) $_GET['term'] : 0;
		$target  = isset( $_GET['target'] ) ? sanitize_text_field( wp_unslash( $_GET['target'] ) ) : '';
		$mode    = isset( $_GET['mode'] ) ? sanitize_text_field( wp_unslash( $_GET['mode'] ) ) : 'copy';

		if ( ! $term_id || ! current_user_can( 'manage_categories' ) ) {
			wp_die( esc_html__( '权限不足。', 'agentsteamer-lang' ) );
		}
		check_admin_referer( 'asl_term_translate_' . $term_id . '_' . $target );

		$term = get_term( $term_id );
		if ( ! $term || is_wp_error( $term ) ) {
			wp_die( esc_html__( '术语不存在。', 'agentsteamer-lang' ) );
		}

		$result = ( 'ai' === $mode )
			? $this->translate_term( $term_id, $target )
			: self::create_translation( $term_id, $target );

		$redirect = admin_url( 'term.php?taxonomy=' . urlencode( $term->taxonomy ) . '&tag_ID=' . $term_id );
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			if ( is_array( $data ) && ! empty( $data['existing'] ) ) {
				self::backfill_posts( $term_id, $target );
				$redirect = admin_url( 'term.php?taxonomy=' . urlencode( $term->taxonomy ) . '&tag_ID=' . (int) $data['existing'] );
			} else {
				$redirect = add_query_arg( 'asl_error', urlencode( $result->get_error_message() ), $redirect );
			}
		} else {
			$redirect = add_query_arg( 'asl_done', '1', $redirect );
		}

		wp_safe_redirect( $redirect );
		exit;
	}
}
