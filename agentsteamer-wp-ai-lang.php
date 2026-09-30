<?php
/**
 * Plugin Name:       AgentSteamer WP AI Lang
 * Plugin URI:        https://www.agentsteamer.com/
 * Description:       WordPress 多语言插件：语言切换、按浏览器语言自动跳转、文章多语言语言稿关联，并可通过大模型接口自动生成多语言译本。自包含，不依赖外部平台。
 * Update URI:        https://github.com/mantelgreen/agentsteamer-wp-ai-lang
 * Version:           0.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            上海临境绘谷信息科技有限公司
 * Author URI:        https://www.agentsteamer.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       agentsteamer-lang
 * Domain Path:       /languages
 *
 * @package AgentSteamer_Lang
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AGENTSTEAMER_LANG_VERSION', '0.1.0' );
define( 'AGENTSTEAMER_LANG_FILE', __FILE__ );
define( 'AGENTSTEAMER_LANG_DIR', plugin_dir_path( __FILE__ ) );
define( 'AGENTSTEAMER_LANG_URL', plugin_dir_url( __FILE__ ) );
define( 'AGENTSTEAMER_LANG_BASENAME', plugin_basename( __FILE__ ) );

require_once AGENTSTEAMER_LANG_DIR . 'inc/helpers.php';
require_once AGENTSTEAMER_LANG_DIR . 'inc/class-plugin.php';

register_activation_hook( __FILE__, array( 'AgentSteamer_Lang_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'AgentSteamer_Lang_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'AgentSteamer_Lang_Plugin', 'instance' ), 1 );
