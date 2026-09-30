<?php
/**
 * Multilingual SEO head output: html lang/dir, hreflang, og:locale.
 *
 * hreflang is owned by this plugin; the AgentSteamer SEO/GEO plugin must not
 * output its own alternates (see doc §5.5).
 *
 * @package AgentSteamer_Lang
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Emits language-related head tags.
 */
class AgentSteamer_Lang_Meta {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'language_attributes', array( $this, 'language_attributes' ), 10, 1 );
		add_action( 'wp_head', array( $this, 'output' ), 5 );
		add_filter( 'option_date_format', array( $this, 'option_date_format' ) );
		add_filter( 'option_time_format', array( $this, 'option_time_format' ) );
	}

	/**
	 * Whether the front-end date/time format should be localized right now.
	 *
	 * @return bool
	 */
	protected function can_localize_dates() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		return agentsteamer_lang_is_enabled();
	}

	/**
	 * Per-language date format (falls back to a sensible locale default).
	 *
	 * @param string $format Option value.
	 * @return string
	 */
	public function option_date_format( $format ) {
		if ( ! $this->can_localize_dates() ) {
			return $format;
		}
		$lang = AgentSteamer_Lang_Languages::get( AgentSteamer_Lang_Router::current() );
		if ( ! $lang ) {
			return $format;
		}
		return ! empty( $lang['date_format'] ) ? $lang['date_format'] : agentsteamer_lang_default_date_format( $lang['code'] );
	}

	/**
	 * Per-language time format (falls back to a sensible locale default).
	 *
	 * @param string $format Option value.
	 * @return string
	 */
	public function option_time_format( $format ) {
		if ( ! $this->can_localize_dates() ) {
			return $format;
		}
		$lang = AgentSteamer_Lang_Languages::get( AgentSteamer_Lang_Router::current() );
		if ( ! $lang ) {
			return $format;
		}
		return ! empty( $lang['time_format'] ) ? $lang['time_format'] : agentsteamer_lang_default_time_format( $lang['code'] );
	}

	/**
	 * Override the <html lang> / dir attributes for the current language.
	 *
	 * @param string $output Attributes.
	 * @return string
	 */
	public function language_attributes( $output ) {
		if ( ! agentsteamer_lang_is_enabled() ) {
			return $output;
		}
		$lang = AgentSteamer_Lang_Languages::get( AgentSteamer_Lang_Router::current() );
		if ( ! $lang ) {
			return $output;
		}
		$locale = ! empty( $lang['locale'] ) ? $lang['locale'] : $lang['code'];
		$locale = str_replace( '_', '-', $locale );
		$dir    = ! empty( $lang['dir'] ) ? $lang['dir'] : 'ltr';
		return 'lang="' . esc_attr( $locale ) . '" dir="' . esc_attr( $dir ) . '"';
	}

	/**
	 * Alternates for the current view.
	 *
	 * @return array[] Each: [ 'hreflang' => string, 'href' => string ].
	 */
	public static function alternates() {
		$out = array();
		if ( ! agentsteamer_lang_is_enabled() || empty( AgentSteamer_Lang_Languages::active_codes() ) ) {
			return $out;
		}

		$default = AgentSteamer_Lang_Languages::default_code();
		$pairs   = array();

		if ( is_singular() ) {
			$post_id = get_queried_object_id();
			foreach ( AgentSteamer_Lang_Translations::translations( $post_id ) as $code => $t ) {
				if ( 'publish' === $t['status'] ) {
					$pairs[ $code ] = $t['url'];
				}
			}
		} elseif ( is_front_page() || is_home() ) {
			foreach ( AgentSteamer_Lang_Languages::active_codes() as $code ) {
				$pairs[ $code ] = AgentSteamer_Lang_Router::lang_home_url( $code );
			}
		}

		if ( empty( $pairs ) ) {
			return $out;
		}

		foreach ( $pairs as $code => $url ) {
			$lang = AgentSteamer_Lang_Languages::get( $code );
			$hl   = $lang && ! empty( $lang['locale'] ) ? $lang['locale'] : $code;
			$hl   = str_replace( '_', '-', $hl );
			$out[] = array(
				'hreflang' => $hl,
				'href'     => $url,
			);
		}

		// x-default.
		$xdefault = agentsteamer_lang_get_option( 'x_default', 'default' );
		if ( 'none' !== $xdefault && $default && isset( $pairs[ $default ] ) ) {
			$out[] = array(
				'hreflang' => 'x-default',
				'href'     => $pairs[ $default ],
			);
		}

		/**
		 * Filter the hreflang alternates.
		 *
		 * @param array[] $out Alternates.
		 */
		return apply_filters( 'agentsteamer_lang_hreflang', $out );
	}

	/**
	 * Output head tags.
	 */
	public function output() {
		$alternates = self::alternates();
		foreach ( $alternates as $alt ) {
			echo '<link rel="alternate" hreflang="' . esc_attr( $alt['hreflang'] ) . '" href="' . esc_url( $alt['href'] ) . '" />' . "\n";
		}

		$current = AgentSteamer_Lang_Languages::get( AgentSteamer_Lang_Router::current() );
		if ( ! $current ) {
			return;
		}

		$locale = ! empty( $current['locale'] ) ? $current['locale'] : $current['code'];
		if ( false === strpos( $locale, '_' ) ) {
			$locale = str_replace( '-', '_', $locale );
		}
		echo '<meta property="og:locale" content="' . esc_attr( $locale ) . '" />' . "\n";

		foreach ( $alternates as $alt ) {
			if ( 'x-default' === $alt['hreflang'] || str_replace( '-', '_', $alt['hreflang'] ) === $locale ) {
				continue;
			}
			echo '<meta property="og:locale:alternate" content="' . esc_attr( str_replace( '-', '_', $alt['hreflang'] ) ) . '" />' . "\n";
		}
	}
}
