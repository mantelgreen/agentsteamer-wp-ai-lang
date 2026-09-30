<?php
/**
 * Helper functions and shared defaults.
 *
 * @package AgentSteamer_Lang
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Default plugin settings.
 *
 * Languages themselves live in the {prefix}asl_languages table; only the
 * scalar runtime options live here.
 *
 * @return array
 */
function agentsteamer_lang_default_settings() {
	return array(
		// General.
		'enabled'             => 1,

		// URL & detection.
		'url_mode'            => 'directory', // directory | query.
		'hide_default_prefix' => 1,
		'auto_redirect'       => 1,
		'cookie_ttl'          => 30, // days.
		'detect_fallback'     => 'home', // home | none.
		'x_default'           => 'default', // default | none.

		// Translation (AI).
		'ai_enabled'          => 1,
		'auto_translate'      => 0,
		'translate_status'    => 'draft', // draft | publish.
		'translate_seo'       => 1,
		'polish'              => 0,
		'chunk_chars'         => 4000,

		// AI provider.
		'provider'            => 'openai',
		'base_url'            => 'https://api.openai.com/v1',
		'api_key'             => '',
		'model'               => 'gpt-4o-mini',
		'api_version'         => '2024-06-01',
		'thinking_mode'       => 'auto',
		'temperature'         => 0.3,
		'max_tokens'          => 4096,
		'timeout'             => 60,

		// Switcher output.
		'switcher_show_flags' => 0,
		'switcher_style'      => 'list', // list | dropdown.
	);
}

/**
 * All settings merged with defaults.
 *
 * @return array
 */
function agentsteamer_lang_get_settings() {
	$stored = get_option( 'agentsteamer_lang_settings', array() );
	if ( ! is_array( $stored ) ) {
		$stored = array();
	}
	return wp_parse_args( $stored, agentsteamer_lang_default_settings() );
}

/**
 * A single setting.
 *
 * @param string $key     Setting key.
 * @param mixed  $default Fallback.
 * @return mixed
 */
function agentsteamer_lang_get_option( $key, $default = null ) {
	$settings = agentsteamer_lang_get_settings();
	if ( array_key_exists( $key, $settings ) ) {
		return $settings[ $key ];
	}
	return $default;
}

/**
 * Whether the plugin is globally enabled.
 *
 * @return bool
 */
function agentsteamer_lang_is_enabled() {
	return (bool) agentsteamer_lang_get_option( 'enabled', 1 );
}

/**
 * Post-meta key prefix for plugin-owned metadata.
 *
 * Uses `_asl_` to avoid colliding with the AgentSteamer SEO/GEO plugin (`_asi_`).
 *
 * @param string $field Field.
 * @return string
 */
function agentsteamer_lang_meta_key( $field ) {
	return '_asl_' . $field;
}

/**
 * Content types that support languages.
 *
 * @return string[]
 */
function agentsteamer_lang_supported_post_types() {
	$types   = get_post_types( array( 'public' => true ), 'names' );
	$exclude = array( 'attachment' );
	$types   = array_values( array_diff( $types, $exclude ) );

	/**
	 * Filter the translated post types.
	 *
	 * @param string[] $types Post type names.
	 */
	return apply_filters( 'agentsteamer_lang_supported_post_types', $types );
}

/**
 * Normalise a language code for storage (keeps a BCP-47 style casing).
 *
 * @param string $code Raw code.
 * @return string
 */
function agentsteamer_lang_normalize_code( $code ) {
	$code = trim( (string) $code );
	$code = str_replace( '_', '-', $code );
	if ( preg_match( '/^([a-z]{2,3})(?:-([a-z]{2}))?$/i', $code, $m ) ) {
		$code = strtolower( $m[1] );
		if ( ! empty( $m[2] ) ) {
			$code .= '-' . strtoupper( $m[2] );
		}
	}
	return $code;
}

/**
 * Normalise a URL slug for a language.
 *
 * @param string $slug Raw slug.
 * @return string
 */
function agentsteamer_lang_normalize_slug( $slug ) {
	$slug = strtolower( trim( (string) $slug ) );
	$slug = preg_replace( '/[^a-z0-9-]/', '-', $slug );
	$slug = trim( preg_replace( '/-+/', '-', $slug ), '-' );
	return $slug;
}

/**
 * Default date format for a language code (used when the language sets none).
 *
 * @param string $code Language code.
 * @return string
 */
function agentsteamer_lang_default_date_format( $code ) {
	$base = strtolower( preg_replace( '/[-_].*$/', '', (string) $code ) );
	return in_array( $base, array( 'zh', 'ja', 'ko' ), true ) ? 'Y年n月j日' : 'F j, Y';
}

/**
 * Default time format for a language code (used when the language sets none).
 *
 * @param string $code Language code.
 * @return string
 */
function agentsteamer_lang_default_time_format( $code ) {
	$base = strtolower( preg_replace( '/[-_].*$/', '', (string) $code ) );
	return in_array( $base, array( 'zh', 'ja', 'ko' ), true ) ? 'H:i' : 'g:i a';
}

/**
 * Best-effort flag emoji for a language code (used when no custom flag is set).
 *
 * @param string $code Language code, e.g. en or zh-CN.
 * @return string Emoji, or '' when unknown.
 */
function agentsteamer_lang_flag_emoji( $code ) {
	$base = strtolower( preg_replace( '/[-_].*$/', '', (string) $code ) );
	$map  = array(
		'zh' => '🇨🇳',
		'cn' => '🇨🇳',
		'en' => '🇬🇧',
		'ja' => '🇯🇵',
		'jp' => '🇯🇵',
		'ko' => '🇰🇷',
		'de' => '🇩🇪',
		'fr' => '🇫🇷',
		'es' => '🇪🇸',
		'it' => '🇮🇹',
		'pt' => '🇵🇹',
		'ru' => '🇷🇺',
		'ar' => '🇸🇦',
		'hi' => '🇮🇳',
		'th' => '🇹🇭',
		'vi' => '🇻🇳',
		'id' => '🇮🇩',
		'ms' => '🇲🇾',
		'nl' => '🇳🇱',
		'tr' => '🇹🇷',
		'pl' => '🇵🇱',
		'sv' => '🇸🇪',
		'da' => '🇩🇰',
		'fi' => '🇫🇮',
		'no' => '🇳🇴',
		'cs' => '🇨🇿',
		'uk' => '🇺🇦',
	);
	return isset( $map[ $base ] ) ? $map[ $base ] : '';
}

/**
 * Best-effort ISO 3166 country code for a language code (for bundled flag SVGs).
 *
 * @param string $code Language code.
 * @return string Lowercase country code, or ''.
 */
function agentsteamer_lang_country_code( $code ) {
	$base = strtolower( preg_replace( '/[-_].*$/', '', (string) $code ) );
	$map  = array(
		'zh' => 'cn',
		'en' => 'gb',
		'ja' => 'jp',
		'ko' => 'kr',
		'de' => 'de',
		'fr' => 'fr',
		'es' => 'es',
		'it' => 'it',
		'pt' => 'pt',
		'ru' => 'ru',
		'nl' => 'nl',
		'tr' => 'tr',
		'pl' => 'pl',
		'sv' => 'se',
		'da' => 'dk',
		'fi' => 'fi',
		'no' => 'no',
		'cs' => 'cz',
		'uk' => 'ua',
	);
	return isset( $map[ $base ] ) ? $map[ $base ] : '';
}

/**
 * Trim text to a maximum number of characters (multibyte safe).
 *
 * @param string $text   Text.
 * @param int    $length Max length.
 * @return string
 */
function agentsteamer_lang_trim( $text, $length ) {
	$text = wp_strip_all_tags( (string) $text );
	$text = preg_replace( '/\s+/', ' ', $text );
	$text = trim( $text );
	if ( function_exists( 'mb_strlen' ) && mb_strlen( $text ) > $length ) {
		return mb_substr( $text, 0, $length - 1 ) . '…';
	}
	if ( strlen( $text ) > $length ) {
		return substr( $text, 0, $length - 1 ) . '…';
	}
	return $text;
}

/**
 * Parse the Accept-Language header into an ordered list of language codes.
 *
 * @param string $header Header value.
 * @return string[] Ordered codes by q-value.
 */
function agentsteamer_lang_parse_accept_language( $header ) {
	$header = (string) $header;
	if ( '' === trim( $header ) ) {
		return array();
	}

	$items = array();
	foreach ( explode( ',', $header ) as $part ) {
		$part = trim( $part );
		if ( '' === $part ) {
			continue;
		}
		$q     = 1.0;
		$code  = $part;
		if ( false !== strpos( $part, ';' ) ) {
			$bits = array_map( 'trim', explode( ';', $part ) );
			$code = array_shift( $bits );
			foreach ( $bits as $bit ) {
				if ( 0 === stripos( $bit, 'q=' ) ) {
					$q = (float) substr( $bit, 2 );
				}
			}
		}
		if ( '*' === $code ) {
			continue;
		}
		$items[] = array(
			'code' => $code,
			'q'    => $q,
		);
	}

	usort(
		$items,
		function ( $a, $b ) {
			return $b['q'] <=> $a['q'];
		}
	);

	$codes = array();
	foreach ( $items as $item ) {
		$code = preg_replace( '/[^A-Za-z0-9-]/', '', $item['code'] );
		if ( '' !== $code ) {
			$codes[] = $code;
		}
	}
	return $codes;
}

/**
 * Built-in default prompts, keyed by task.
 *
 * @return array
 */
function agentsteamer_lang_prompt_defaults() {
	return array(
		'translate'      => implode(
			"\n",
			array(
				'你是专业本地化译者与目标语言母语编辑。把用户提供的内容翻译为目标语言。',
				'硬性要求：',
				'1. 只输出译文本身，不要输出任何解释、前言、Markdown 代码围栏或 JSON。',
				'2. 完整保留原文的 HTML 标签、标签属性、短代码与占位符（形如 {{ASL_n}} 的占位符必须原样保留、位置不变）。',
				'3. 不要翻译 URL、邮箱、代码块内容与占位符内的内容。',
				'4. 保持段落、标题层级与列表结构。',
				'5. 术语表给出的译法必须严格采用；标记为「不翻译」的词保持原文。',
				'6. 译文自然、通顺，符合目标语言表达习惯，不要逐字硬译。',
			)
		),
		'translate_meta' => implode(
			"\n",
			array(
				'你是多语言 SEO 编辑。根据给定的原文标题、摘要与焦点关键词，输出目标语言的对应字段。',
				'只输出一个 JSON 对象，不要推理或解释。',
				'JSON 结构：{"title":"译文标题","excerpt":"译文摘要","meta_title":"SEO标题","meta_description":"Meta描述","focus_keyword":"焦点关键词"}',
				'要求：SEO 标题与描述需符合目标语言的搜索习惯与长度习惯，但语义与原文一致。',
			)
		),
		'polish'         => '你是目标语言资深编辑。请在不改变事实、观点与结构（含 HTML 标签与占位符）的前提下润色以下译文，使其更自然地道。只输出润色后的译文本身，不要解释。',
	);
}

/**
 * Resolve a system prompt: site override or built-in default.
 *
 * @param string $key Prompt key.
 * @return string
 */
function agentsteamer_lang_prompt( $key ) {
	$defaults = agentsteamer_lang_prompt_defaults();
	$default  = isset( $defaults[ $key ] ) ? $defaults[ $key ] : '';
	$override = agentsteamer_lang_get_option( 'prompt_' . $key, '' );
	if ( is_string( $override ) && '' !== trim( $override ) ) {
		return $override;
	}
	return $default;
}
