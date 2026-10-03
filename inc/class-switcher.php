<?php
/**
 * Front-end language switcher (widget, shortcode, template tag).
 *
 * @package AgentSteamer_Lang
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the language switcher.
 */
class AgentSteamer_Lang_Switcher {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_shortcode( 'agentsteamer_lang_switcher', array( $this, 'shortcode' ) );
		add_shortcode( 'agentsteamer_language_switcher', array( $this, 'shortcode' ) );
		add_action( 'widgets_init', array( $this, 'register_widget' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
	}

	/**
	 * Front-end assets for the custom dropdown switcher.
	 */
	public function assets() {
		wp_enqueue_style( 'agentsteamer-lang-switcher', AGENTSTEAMER_LANG_URL . 'assets/css/switcher.css', array(), AGENTSTEAMER_LANG_VERSION );
		wp_enqueue_script( 'agentsteamer-lang-switcher', AGENTSTEAMER_LANG_URL . 'assets/js/switcher.js', array(), AGENTSTEAMER_LANG_VERSION, true );
	}

	/**
	 * Short language code for the switcher (e.g. zh / en / ja).
	 *
	 * @param string $code Language code.
	 * @return string
	 */
	protected static function code_chip( $code ) {
		return strtolower( preg_replace( '/[-_].*$/', '', (string) $code ) );
	}

	/**
	 * Flag markup for a language: custom value (emoji or image URL) or derived emoji.
	 *
	 * @param array $lang Language row.
	 * @return string
	 */
	protected static function flag_html( $lang ) {
		// Explicit custom flag (emoji/text or image URL).
		if ( ! empty( $lang['flag'] ) ) {
			$flag = (string) $lang['flag'];
			if ( preg_match( '#^(https?://|/)#i', $flag ) || preg_match( '#\.(png|jpe?g|svg|gif|webp)$#i', $flag ) ) {
				return '<img class="asl-flag-img" src="' . esc_url( $flag ) . '" alt="" width="20" height="14" loading="lazy" />';
			}
			return '<span class="asl-flag" aria-hidden="true">' . esc_html( $flag ) . '</span>';
		}

		// Bundled SVG flag (renders on every platform, including Windows).
		$cc = agentsteamer_lang_country_code( $lang['code'] );
		if ( '' !== $cc && file_exists( AGENTSTEAMER_LANG_DIR . 'assets/flags/' . $cc . '.svg' ) ) {
			return '<img class="asl-flag-img" src="' . esc_url( AGENTSTEAMER_LANG_URL . 'assets/flags/' . $cc . '.svg' ) . '" alt="" width="20" height="14" />';
		}

		// Fallback: emoji flag.
		$emoji = agentsteamer_lang_flag_emoji( $lang['code'] );
		return '' !== $emoji ? '<span class="asl-flag" aria-hidden="true">' . esc_html( $emoji ) . '</span>' : '';
	}

	/**
	 * Resolve the target URL for a language in the current context.
	 *
	 * @param string $code Language code.
	 * @return string
	 */
	public static function target_url( $code ) {
		if ( is_singular() ) {
			$post_id = get_queried_object_id();
			$trans   = AgentSteamer_Lang_Translations::permalinks( $post_id );
			if ( ! empty( $trans[ $code ] ) ) {
				return $trans[ $code ];
			}
		}
		return AgentSteamer_Lang_Router::lang_home_url( $code );
	}

	/**
	 * Build the switcher HTML.
	 *
	 * @param array $args Options: style, show_flags, echo.
	 * @return string
	 */
	public static function render( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'style'      => agentsteamer_lang_get_option( 'switcher_style', 'list' ),
				'show_flags' => (int) agentsteamer_lang_get_option( 'switcher_show_flags', 0 ),
				'echo'       => false,
			)
		);

		$languages = AgentSteamer_Lang_Languages::all( true );
		if ( count( $languages ) < 1 ) {
			return '';
		}
		$current = AgentSteamer_Lang_Router::current();

		if ( 'dropdown' === $args['style'] ) {
			$current_lang = AgentSteamer_Lang_Languages::get( $current );

			$html  = '<div class="asl-dd" data-asl-dd>';
			$html .= '<button type="button" class="asl-dd-toggle" aria-haspopup="listbox" aria-expanded="false">';
			$html .= '<span class="asl-dd-value">';
			if ( $current_lang && $args['show_flags'] ) {
				$html .= self::flag_html( $current_lang );
			}
			$current_name = $current_lang ? ( $current_lang['native_name'] ? $current_lang['native_name'] : $current_lang['name'] ) : __( '语言', 'agentsteamer-lang' );
			$html        .= '<span class="asl-dd-name">' . esc_html( $current_name ) . '</span>';
			$html        .= '</span>';
			$html        .= '<svg class="asl-dd-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>';
			$html        .= '</button>';

			$html .= '<ul class="asl-dd-menu" role="listbox" aria-label="' . esc_attr__( '语言', 'agentsteamer-lang' ) . '">';
			foreach ( $languages as $lang ) {
				$url   = self::target_url( $lang['code'] );
				$is    = ( $lang['code'] === $current );
				$name  = $lang['native_name'] ? $lang['native_name'] : $lang['name'];
				$html .= '<li class="asl-dd-item' . ( $is ? ' is-current' : '' ) . '" role="option" aria-selected="' . ( $is ? 'true' : 'false' ) . '">';
				$html .= '<a href="' . esc_url( $url ) . '" hreflang="' . esc_attr( $lang['code'] ) . '" lang="' . esc_attr( $lang['code'] ) . '">';
				if ( $args['show_flags'] ) {
					$html .= self::flag_html( $lang );
				}
				$html .= '<span class="asl-dd-item-name">' . esc_html( $name ) . '</span>';
				$html .= '<span class="asl-dd-code">' . esc_html( self::code_chip( $lang['code'] ) ) . '</span>';
				$html .= '</a></li>';
			}
			$html .= '</ul></div>';
		} else {
			$html = '<ul class="asl-switcher asl-switcher-list">';
			foreach ( $languages as $lang ) {
				$url   = self::target_url( $lang['code'] );
				$flag  = ! empty( $lang['flag'] ) ? $lang['flag'] : agentsteamer_lang_flag_emoji( $lang['code'] );
				$label = $args['show_flags'] && $flag ? $flag . ' ' : '';
				$label .= $lang['native_name'] ? $lang['native_name'] : $lang['name'];
				$class = ( $lang['code'] === $current ) ? ' class="asl-current"' : '';
				$html .= '<li' . $class . '><a href="' . esc_url( $url ) . '" hreflang="' . esc_attr( $lang['code'] ) . '">' . esc_html( $label ) . '</a></li>';
			}
			$html .= '</ul>';
		}

		/**
		 * Filter the switcher HTML.
		 *
		 * @param string $html HTML.
		 * @param string $current Current language code.
		 */
		$html = apply_filters( 'agentsteamer_lang_switcher_html', $html, $current );

		if ( $args['echo'] ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		return $html;
	}

	/**
	 * Shortcode handler.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'style'      => '',
				'show_flags' => '',
			),
			$atts,
			'agentsteamer_lang_switcher'
		);
		$args = array();
		if ( '' !== $atts['style'] ) {
			$args['style'] = $atts['style'];
		}
		if ( '' !== $atts['show_flags'] ) {
			$args['show_flags'] = (int) $atts['show_flags'];
		}
		return self::render( $args );
	}

	/**
	 * Register the switcher widget.
	 */
	public function register_widget() {
		register_widget( 'AgentSteamer_Lang_Switcher_Widget' );
	}
}

/**
 * Language switcher widget.
 */
class AgentSteamer_Lang_Switcher_Widget extends WP_Widget {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			'agentsteamer_lang_switcher',
			__( '语言切换器（AgentSteamer）', 'agentsteamer-lang' ),
			array( 'description' => __( '在侧边栏显示语言切换器。', 'agentsteamer-lang' ) )
		);
	}

	/**
	 * Output the widget.
	 *
	 * @param array $args     Sidebar args.
	 * @param array $instance Settings.
	 */
	public function widget( $args, $instance ) {
		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( ! empty( $instance['title'] ) ) {
			echo $args['before_title'] . esc_html( $instance['title'] ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo AgentSteamer_Lang_Switcher::render( array( 'echo' => false ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Widget settings form.
	 *
	 * @param array $instance Settings.
	 */
	public function form( $instance ) {
		$title = isset( $instance['title'] ) ? $instance['title'] : '';
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( '标题：', 'agentsteamer-lang' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
		</p>
		<?php
	}

	/**
	 * Save widget settings.
	 *
	 * @param array $new New instance.
	 * @param array $old Old instance.
	 * @return array
	 */
	public function update( $new, $old ) {
		return array( 'title' => sanitize_text_field( isset( $new['title'] ) ? $new['title'] : '' ) );
	}
}

if ( ! function_exists( 'agentsteamer_lang_switcher' ) ) {
	/**
	 * Template tag: print (or return) the language switcher.
	 *
	 * @param array $args Options: style, show_flags, echo.
	 * @return string
	 */
	function agentsteamer_lang_switcher( $args = array() ) {
		return AgentSteamer_Lang_Switcher::render( $args );
	}
}

if ( ! function_exists( 'agentsteamer_lang_current' ) ) {
	/**
	 * Template tag: current language code ('' when none configured).
	 *
	 * @return string
	 */
	function agentsteamer_lang_current() {
		return AgentSteamer_Lang_Router::current();
	}
}

if ( ! function_exists( 'agentsteamer_lang_default' ) ) {
	/**
	 * Template tag: default language code.
	 *
	 * @return string
	 */
	function agentsteamer_lang_default() {
		$code = AgentSteamer_Lang_Languages::default_code();
		return $code ? $code : '';
	}
}

if ( ! function_exists( 'agentsteamer_lang_current_slug' ) ) {
	/**
	 * Template tag: current language URL slug (e.g. zh / en).
	 *
	 * @return string
	 */
	function agentsteamer_lang_current_slug() {
		$lang = AgentSteamer_Lang_Languages::get( AgentSteamer_Lang_Router::current() );
		return ( $lang && ! empty( $lang['slug'] ) ) ? $lang['slug'] : '';
	}
}

if ( ! function_exists( 'agentsteamer_lang_locale' ) ) {
	/**
	 * Template tag: current language locale (e.g. en_US).
	 *
	 * @return string
	 */
	function agentsteamer_lang_locale() {
		return AgentSteamer_Lang_Languages::locale_for( AgentSteamer_Lang_Router::current() );
	}
}

if ( ! function_exists( 'agentsteamer_lang_languages' ) ) {
	/**
	 * Template tag: active languages.
	 *
	 * @return array
	 */
	function agentsteamer_lang_languages() {
		return AgentSteamer_Lang_Languages::all( true );
	}
}

if ( ! function_exists( 'agentsteamer_lang_post_lang' ) ) {
	/**
	 * Template tag: language code assigned to a post ('' when none).
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	function agentsteamer_lang_post_lang( $post_id ) {
		return AgentSteamer_Lang_Taxonomy::get_post_lang( $post_id );
	}
}

if ( ! function_exists( 'agentsteamer_lang_post_language_name' ) ) {
	/**
	 * Template tag: human-readable language name for a post.
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	function agentsteamer_lang_post_language_name( $post_id ) {
		$lang = AgentSteamer_Lang_Languages::get( AgentSteamer_Lang_Taxonomy::get_post_lang( $post_id ) );
		if ( ! $lang ) {
			return '';
		}
		return $lang['native_name'] ? $lang['native_name'] : $lang['name'];
	}
}

if ( ! function_exists( 'agentsteamer_lang_permalink' ) ) {
	/**
	 * Template tag: permalink of the current object's translation in a language.
	 *
	 * @param int    $post_id Post id.
	 * @param string $code    Language code.
	 * @return string
	 */
	function agentsteamer_lang_permalink( $post_id, $code ) {
		$map = AgentSteamer_Lang_Translations::permalinks( $post_id );
		return isset( $map[ $code ] ) ? $map[ $code ] : '';
	}
}
