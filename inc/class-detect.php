<?php
/**
 * Browser-language detection, cookie memory and automatic redirect.
 *
 * @package AgentSteamer_Lang
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Redirects first-time visitors to the language suggested by their browser.
 */
class AgentSteamer_Lang_Detect {

	/**
	 * Cookie name.
	 */
	const COOKIE = 'asl_lang';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 1 );
	}

	/**
	 * Read the visitor's remembered language.
	 *
	 * @return string
	 */
	public static function cookie_lang() {
		if ( empty( $_COOKIE[ self::COOKIE ] ) ) {
			return '';
		}
		return agentsteamer_lang_normalize_slug( sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) );
	}

	/**
	 * Persist the visitor's language choice in a cookie.
	 *
	 * @param string $slug Language slug.
	 */
	public static function set_cookie( $slug ) {
		$days = (int) agentsteamer_lang_get_option( 'cookie_ttl', 30 );
		if ( $days < 1 ) {
			$days = 30;
		}
		$path = ( defined( 'COOKIEPATH' ) && COOKIEPATH ) ? COOKIEPATH : '/';
		setcookie(
			self::COOKIE,
			$slug,
			array(
				'expires'  => time() + ( $days * DAY_IN_SECONDS ),
				'path'     => $path,
				'domain'   => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Choose the best configured language for a browser's Accept-Language.
	 *
	 * @param string $header Header value.
	 * @return string Language code or ''.
	 */
	public static function match_browser_language( $header ) {
		$wanted = agentsteamer_lang_parse_accept_language( $header );
		if ( empty( $wanted ) ) {
			return '';
		}

		$languages = AgentSteamer_Lang_Languages::all( true );
		$map       = array();
		foreach ( $languages as $lang ) {
			$map[ strtolower( $lang['code'] ) ] = $lang['code'];
		}

		// Exact match first.
		foreach ( $wanted as $code ) {
			$key = strtolower( $code );
			if ( isset( $map[ $key ] ) ) {
				return $map[ $key ];
			}
		}
		// Base-language match (e.g. zh-Hans vs zh-CN).
		foreach ( $wanted as $code ) {
			$base = strtolower( preg_replace( '/[-_].*$/', '', $code ) );
			foreach ( $map as $key => $orig ) {
				if ( strtolower( preg_replace( '/[-_].*$/', '', $key ) ) === $base ) {
					return $orig;
				}
			}
		}
		return '';
	}

	/**
	 * Redirect when appropriate.
	 */
	public function maybe_redirect() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}
		if ( ! agentsteamer_lang_is_enabled() || empty( AgentSteamer_Lang_Languages::active_codes() ) ) {
			return;
		}
		if ( is_robots() || is_feed() || is_trackback() ) {
			return;
		}
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'GET' !== $_SERVER['REQUEST_METHOD'] ) {
			return;
		}

		$url_lang = AgentSteamer_Lang_Router::url_lang();

		// Remember an explicit choice (from the URL).
		if ( '' !== $url_lang ) {
			$lang = AgentSteamer_Lang_Languages::get( $url_lang );
			if ( $lang ) {
				self::set_cookie( $lang['slug'] );
			}
			return;
		}

		if ( isset( $_GET['no_redirect'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		if ( ! agentsteamer_lang_get_option( 'auto_redirect', 1 ) ) {
			return;
		}
		if ( '' !== self::cookie_lang() ) {
			return;
		}

		$header  = isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) : '';
		$matched = self::match_browser_language( $header );
		if ( '' === $matched ) {
			return;
		}

		$default = AgentSteamer_Lang_Languages::default_code();
		if ( $matched === $default ) {
			return; // Already on (or will fall back to) the default.
		}

		$target = $this->target_url( $matched );
		if ( '' === $target ) {
			return;
		}

		$lang = AgentSteamer_Lang_Languages::get( $matched );
		if ( $lang ) {
			self::set_cookie( $lang['slug'] );
		}

		nocache_headers();
		wp_safe_redirect( $target, 302 );
		exit;
	}

	/**
	 * Where to send a visitor for a detected language.
	 *
	 * @param string $code Language code.
	 * @return string
	 */
	protected function target_url( $code ) {
		if ( is_singular() ) {
			$post_id = get_queried_object_id();
			$trans   = AgentSteamer_Lang_Translations::permalinks( $post_id );
			if ( ! empty( $trans[ $code ] ) ) {
				return $trans[ $code ];
			}
		}
		if ( is_category() || is_tag() || is_tax() ) {
			$term_id = get_queried_object_id();
			$term_trans = self::term_translation_url( $term_id, $code );
			if ( '' !== $term_trans ) {
				return $term_trans;
			}
		}
		if ( 'home' === agentsteamer_lang_get_option( 'detect_fallback', 'home' ) ) {
			return AgentSteamer_Lang_Router::lang_home_url( $code );
		}
		return '';
	}

	/**
	 * Translated term URL for a language, if any.
	 *
	 * @param int    $term_id Term id.
	 * @param string $code    Language code.
	 * @return string
	 */
	protected function term_translation_url( $term_id, $code ) {
		$trid = get_term_meta( $term_id, '_asl_trid', true );
		if ( ! $trid ) {
			return '';
		}
		$terms = get_terms(
			array(
				'taxonomy'   => get_queried_object()->taxonomy,
				'hide_empty' => false,
				'meta_query' => array(
					array(
						'key'   => '_asl_trid',
						'value' => $trid,
					),
				),
			)
		);
		foreach ( (array) $terms as $term ) {
			if ( get_term_meta( $term->term_id, '_asl_code', true ) === $code ) {
				$link = get_term_link( $term );
				return is_wp_error( $link ) ? '' : $link;
			}
		}
		return '';
	}
}
