<?php
/**
 * Detects other multilingual plugins to avoid conflicting installs.
 *
 * @package AgentSteamer_Lang
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Warns when another multilingual plugin is active.
 */
class AgentSteamer_Lang_Conflict {

	/**
	 * Known plugins: file => label.
	 *
	 * @return array
	 */
	public static function known() {
		return array(
			'polylang/polylang.php'                        => 'Polylang',
			'polylang-pro/polylang.php'                    => 'Polylang Pro',
			'sitepress-multilingual-cms/sitepress.php'     => 'WPML',
			'translatepress-multilingual/index.php'        => 'TranslatePress',
			'weglot/weglot.php'                            => 'Weglot',
		);
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( is_admin() ) {
			add_action( 'admin_notices', array( $this, 'notice' ) );
		}
	}

	/**
	 * Active conflicting plugins.
	 *
	 * @return array
	 */
	public static function active_conflicts() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$found = array();
		foreach ( self::known() as $file => $label ) {
			if ( is_plugin_active( $file ) ) {
				$found[ $file ] = $label;
			}
		}
		return $found;
	}

	/**
	 * Admin notice.
	 */
	public function notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && false === strpos( (string) $screen->id, 'agentsteamer-lang' ) && 'plugins' !== $screen->id ) {
			return;
		}
		$conflicts = self::active_conflicts();
		if ( empty( $conflicts ) ) {
			return;
		}
		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'AgentSteamer Lang：检测到其它多语言插件', 'agentsteamer-lang' ); ?></strong>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: comma-separated plugin list. */
						__( '同时启用 %s 可能导致 URL、重定向与语言标记冲突。建议只保留一个多语言插件，或在设置中关闭本插件的前台功能。', 'agentsteamer-lang' ),
						implode( '、', $conflicts )
					)
				);
				?>
			</p>
		</div>
		<?php
	}
}
