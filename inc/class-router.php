<?php
/**
 * Language-aware URL routing.
 *
 * Detects the language from the URL (directory prefix or ?asl_lang=) and
 * rewrites generated URLs so every link carries the right language.
 *
 * Because get_permalink() builds its path through home_url(), the home_url
 * filter adds the *current* language, and the object-aware filters
 * (post_link / term_link / ...) then strip any existing language segment and
 * re-apply the language that actually belongs to that object.
 *
 * @package AgentSteamer_Lang
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the current language and prefixes generated URLs.
 */
class AgentSteamer_Lang_Router {

	/**
	 * Current language code.
	 *
	 * @var string
	 */
	protected static $current = '';

	/**
	 * Language explicitly present in the request URL.
	 *
	 * @var string
	 */
	protected static $url_lang = '';

	/**
	 * Cached home path (no trailing slash).
	 *
	 * @var string|null
	 */
	protected $base_path = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Runs right after the plugin singleton is instantiated (plugins_loaded:1),
		// and before determine_locale()/load_default_textdomain().
		add_action( 'plugins_loaded', array( $this, 'capture_request' ), 2 );

		add_filter( 'home_url', array( $this, 'filter_home_url' ), 10, 1 );
		add_filter( 'post_link', array( $this, 'filter_post_url' ), 10, 2 );
		add_filter( 'page_link', array( $this, 'filter_post_url' ), 10, 2 );
		add_filter( 'post_type_link', array( $this, 'filter_post_url' ), 10, 2 );
		add_filter( 'attachment_link', array( $this, 'filter_post_url' ), 10, 2 );
		add_filter( 'term_link', array( $this, 'filter_term_url' ), 10, 2 );
		add_filter( 'post_type_archive_link', array( $this, 'filter_url' ), 10, 1 );
		add_filter( 'get_pagenum_link', array( $this, 'filter_url' ), 10, 1 );
		add_filter( 'year_link', array( $this, 'filter_url' ), 10, 1 );
		add_filter( 'month_link', array( $this, 'filter_url' ), 10, 1 );
		add_filter( 'day_link', array( $this, 'filter_url' ), 10, 1 );
		add_filter( 'search_link', array( $this, 'filter_url' ), 10, 1 );
		add_filter( 'feed_link', array( $this, 'filter_url' ), 10, 1 );

		add_filter( 'locale', array( $this, 'filter_locale' ), 5 );
	}

	/**
	 * Current language code (falls back to the default).
	 *
	 * @return string
	 */
	public static function current() {
		if ( '' !== self::$current ) {
			return self::$current;
		}
		$default = AgentSteamer_Lang_Languages::default_code();
		return $default ? $default : '';
	}

	/**
	 * Language explicitly requested in the URL ('' when absent).
	 *
	 * @return string
	 */
	public static function url_lang() {
		return self::$url_lang;
	}

	/**
	 * Set the current language.
	 *
	 * @param string $code Language code.
	 */
	public static function set_current( $code ) {
		self::$current = agentsteamer_lang_normalize_code( $code );
	}

	/**
	 * Detect the language prefix and strip it so WordPress can route the rest.
	 */
	public function capture_request() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}
		if ( ! agentsteamer_lang_is_enabled() || empty( AgentSteamer_Lang_Languages::active_codes() ) ) {
			return;
		}

		$mode = agentsteamer_lang_get_option( 'url_mode', 'directory' );

		if ( 'query' === $mode ) {
			if ( ! empty( $_GET['asl_lang'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				$lang = AgentSteamer_Lang_Languages::get_by_slug( sanitize_text_field( wp_unslash( $_GET['asl_lang'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
				if ( $lang && ! empty( $lang['active'] ) ) {
					self::$current  = $lang['code'];
					self::$url_lang = $lang['code'];
				}
			}
			return;
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		if ( '' === $uri ) {
			return;
		}
		$bits  = explode( '?', $uri, 2 );
		$path  = $bits[0];
		$query = isset( $bits[1] ) ? '?' . $bits[1] : '';

		$base = $this->base_path();
		if ( '' !== $base && 0 !== strpos( $path, $base ) ) {
			return;
		}
		$rest = substr( $path, strlen( $base ) );
		if ( '' === $rest ) {
			return;
		}

		if ( ! preg_match( '#^/([a-zA-Z0-9-]+)(/.*)?$#', $rest, $m ) ) {
			return;
		}
		$lang = AgentSteamer_Lang_Languages::get_by_slug( $m[1] );
		if ( ! $lang || empty( $lang['active'] ) ) {
			return;
		}

		// Detect the language but DO NOT strip the prefix from REQUEST_URI:
		// WordPress computes its home path from home_url(), which our filter
		// prefixes for the current language, so the prefix must stay in place
		// for WP_Query's request parsing to resolve the remaining path.
		self::$current  = $lang['code'];
		self::$url_lang = $lang['code'];
	}

	/**
	 * Filter for context-free URLs (home, archives, pagination): current language.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public function filter_url( $url ) {
		return self::apply_language( $url, self::$current );
	}

	/**
	 * home_url filter: current language.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public function filter_home_url( $url ) {
		return self::apply_language( $url, self::$current );
	}

	/**
	 * Prefix a post's permalink with that post's own language.
	 *
	 * @param string      $url  URL.
	 * @param WP_Post|int $post Post object or id.
	 * @return string
	 */
	public function filter_post_url( $url, $post = null ) {
		$post_id = is_object( $post ) ? (int) $post->ID : (int) $post;
		$code    = $post_id ? AgentSteamer_Lang_Taxonomy::get_post_lang( $post_id ) : '';
		return self::apply_language( $url, $code ? $code : self::$current );
	}

	/**
	 * Prefix a term link with that term's language.
	 *
	 * @param string       $url  URL.
	 * @param WP_Term|null $term Term.
	 * @return string
	 */
	public function filter_term_url( $url, $term = null ) {
		$code = '';
		if ( $term instanceof WP_Term ) {
			$code = AgentSteamer_Lang_Terms::get_term_lang( $term->term_id );
			if ( ! $code ) {
				$lang = AgentSteamer_Lang_Languages::get_by_slug( $term->slug );
				$code = $lang ? $lang['code'] : '';
			}
		}
		return self::apply_language( $url, $code ? $code : self::$current );
	}

	/**
	 * Locale filter: switch the front-end locale to the current language.
	 *
	 * @param string $locale Locale.
	 * @return string
	 */
	public function filter_locale( $locale ) {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return $locale;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return $locale;
		}
		// determine_locale() may run before our plugins_loaded hook; resolve on demand.
		if ( '' === self::$current ) {
			$this->capture_request();
		}
		if ( '' === self::$current ) {
			return $locale;
		}
		$lang = AgentSteamer_Lang_Languages::get( self::$current );
		return ( $lang && ! empty( $lang['locale'] ) ) ? $lang['locale'] : $locale;
	}

	/**
	 * Whether URL output should be transformed right now.
	 *
	 * @return bool
	 */
	protected static function can_filter() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		if ( isset( $GLOBALS['pagenow'] ) && in_array( $GLOBALS['pagenow'], array( 'wp-login.php', 'wp-register.php' ), true ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Apply (or strip) the language segment for a URL.
	 *
	 * @param string $url  URL.
	 * @param string $code Language code ('' = no change).
	 * @return string
	 */
	public static function apply_language( $url, $code ) {
		$url = (string) $url;
		if ( '' === $url || ! self::can_filter() ) {
			return $url;
		}

		$mode    = agentsteamer_lang_get_option( 'url_mode', 'directory' );
		$default = AgentSteamer_Lang_Languages::default_code();
		$hide    = (int) agentsteamer_lang_get_option( 'hide_default_prefix', 1 );
		$lang    = $code ? AgentSteamer_Lang_Languages::get( $code ) : null;

		// Decide the target prefix slug ('' = none).
		$prefix = '';
		if ( $lang && ! empty( $lang['slug'] ) ) {
			if ( ! ( $code === $default && $hide ) ) {
				$prefix = $lang['slug'];
			}
		}

		if ( 'query' === $mode ) {
			if ( '' === $prefix ) {
				return remove_query_arg( 'asl_lang', $url );
			}
			return add_query_arg( 'asl_lang', $prefix, remove_query_arg( 'asl_lang', $url ) );
		}

		if ( '' === $prefix && ! $lang ) {
			return $url; // Unknown language: leave untouched.
		}

		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return $url;
		}
		$base = self::home_path_static();
		$path = isset( $parts['path'] ) ? $parts['path'] : '/';
		if ( '' !== $base && 0 !== strpos( $path, $base ) ) {
			return $url;
		}
		$rest = substr( $path, strlen( $base ) );

		// Never touch admin / REST / asset endpoints.
		if ( preg_match( '#^/(wp-admin|wp-login\.php|wp-json|wp-content|wp-includes)#i', $rest ) ) {
			return $url;
		}

		// Strip an existing language segment (any active language).
		$rest = self::strip_language_segment( $rest );

		$new_path = $base . ( '' !== $prefix ? '/' . $prefix : '' ) . $rest;
		if ( '' === $new_path ) {
			$new_path = '/';
		}

		$out = $parts['scheme'] . '://' . $parts['host'];
		if ( ! empty( $parts['port'] ) ) {
			$out .= ':' . $parts['port'];
		}
		$out .= $new_path;
		if ( ! empty( $parts['query'] ) ) {
			$out .= '?' . $parts['query'];
		}
		if ( ! empty( $parts['fragment'] ) ) {
			$out .= '#' . $parts['fragment'];
		}
		return $out;
	}

	/**
	 * Remove a leading active-language segment from a path fragment.
	 *
	 * @param string $rest Path fragment starting with '/'.
	 * @return string
	 */
	protected static function strip_language_segment( $rest ) {
		if ( ! preg_match( '#^/([a-zA-Z0-9-]+)(/.*)?$#', $rest, $m ) ) {
			return $rest;
		}
		$lang = AgentSteamer_Lang_Languages::get_by_slug( $m[1] );
		if ( ! $lang ) {
			return $rest;
		}
		return isset( $m[2] ) && '' !== $m[2] ? $m[2] : '/';
	}

	/**
	 * Current instance home path.
	 *
	 * @return string
	 */
	protected function base_path() {
		if ( null === $this->base_path ) {
			$this->base_path = self::home_path_static();
		}
		return $this->base_path;
	}

	/**
	 * Home path derived from the raw home option.
	 *
	 * @return string
	 */
	protected static function home_path_static() {
		static $path = null;
		if ( null !== $path ) {
			return $path;
		}
		$home = get_option( 'home' );
		$p    = wp_parse_url( (string) $home, PHP_URL_PATH );
		$p    = '/' . trim( (string) $p, '/' );
		$path = ( '/' === $p ) ? '' : $p;
		return $path;
	}

	/**
	 * URL of the home page in a given language (built without filters).
	 *
	 * @param string $code Language code.
	 * @return string
	 */
	public static function lang_home_url( $code ) {
		$lang = AgentSteamer_Lang_Languages::get( $code );
		$raw  = trailingslashit( set_url_scheme( (string) get_option( 'home' ) ) );
		if ( ! $lang ) {
			return $raw;
		}
		$default = AgentSteamer_Lang_Languages::default_code();
		if ( $code === $default && agentsteamer_lang_get_option( 'hide_default_prefix', 1 ) ) {
			return $raw;
		}
		if ( 'query' === agentsteamer_lang_get_option( 'url_mode', 'directory' ) ) {
			return add_query_arg( 'asl_lang', $lang['slug'], $raw );
		}
		return $raw . $lang['slug'] . '/';
	}
}
