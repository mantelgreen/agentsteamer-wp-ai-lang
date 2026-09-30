<?php
/**
 * Main plugin bootstrap.
 *
 * @package AgentSteamer_Lang
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the plugin modules together.
 */
final class AgentSteamer_Lang_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var AgentSteamer_Lang_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Instantiated modules.
	 *
	 * @var array
	 */
	public $modules = array();

	/**
	 * Get the singleton instance.
	 *
	 * @return AgentSteamer_Lang_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		self::load_files();
		$this->boot();
	}

	/**
	 * Load class files.
	 */
	public static function load_files() {
		$files = array(
			'inc/providers/interface-provider.php',
			'inc/providers/class-provider-openai.php',
			'inc/providers/class-provider-anthropic.php',
			'inc/providers/class-provider-gemini.php',
			'inc/providers/class-provider-manager.php',
			'inc/class-install.php',
			'inc/class-languages.php',
			'inc/class-translations.php',
			'inc/class-glossary.php',
			'inc/class-taxonomy.php',
			'inc/class-terms.php',
			'inc/class-metabox.php',
			'inc/class-editor.php',
			'inc/class-translator.php',
			'inc/class-router.php',
			'inc/class-detect.php',
			'inc/class-switcher.php',
			'inc/class-meta.php',
			'inc/class-review.php',
			'inc/class-settings.php',
			'inc/class-rest.php',
			'inc/class-cli.php',
			'inc/class-conflict.php',
			'inc/class-updater.php',
			'inc/class-admin.php',
		);
		foreach ( $files as $file ) {
			require_once AGENTSTEAMER_LANG_DIR . $file;
		}
	}

	/**
	 * Instantiate modules.
	 */
	private function boot() {
		$this->modules['settings']  = new AgentSteamer_Lang_Settings();
		$this->modules['taxonomy']   = new AgentSteamer_Lang_Taxonomy();
		$this->modules['terms']      = new AgentSteamer_Lang_Terms();
		$this->modules['metabox']    = new AgentSteamer_Lang_Metabox();
		$this->modules['editor']     = new AgentSteamer_Lang_Editor();
		$this->modules['translator'] = new AgentSteamer_Lang_Translator();
		$this->modules['router']    = new AgentSteamer_Lang_Router();
		$this->modules['detect']    = new AgentSteamer_Lang_Detect();
		$this->modules['switcher']  = new AgentSteamer_Lang_Switcher();
		$this->modules['meta']      = new AgentSteamer_Lang_Meta();
		$this->modules['review']    = new AgentSteamer_Lang_Review();
		$this->modules['rest']      = new AgentSteamer_Lang_Rest();
		$this->modules['cli']       = new AgentSteamer_Lang_CLI();
		$this->modules['conflict']  = new AgentSteamer_Lang_Conflict();
		$this->modules['updater']   = new AgentSteamer_Lang_Updater();

		if ( is_admin() ) {
			$this->modules['admin'] = new AgentSteamer_Lang_Admin();
		}

		add_action( 'init', array( __CLASS__, 'maybe_flush_rewrites' ), 99 );
		add_action( 'init', array( 'AgentSteamer_Lang_Install', 'maybe_upgrade' ), 5 );
	}

	/**
	 * Flush rewrite rules once after activation/URL changes.
	 */
	public static function flush_rewrites() {
		update_option( 'agentsteamer_lang_flush_rewrites', 1 );
	}

	/**
	 * Perform a deferred rewrite flush.
	 */
	public static function maybe_flush_rewrites() {
		if ( get_option( 'agentsteamer_lang_flush_rewrites' ) ) {
			delete_option( 'agentsteamer_lang_flush_rewrites' );
			flush_rewrite_rules();
		}
	}

	/**
	 * Activation routine.
	 */
	public static function activate() {
		self::load_files();

		$settings = get_option( 'agentsteamer_lang_settings' );
		if ( false === $settings || ! is_array( $settings ) ) {
			$settings = array();
		}
		update_option( 'agentsteamer_lang_settings', wp_parse_args( $settings, agentsteamer_lang_default_settings() ) );

		AgentSteamer_Lang_Install::run();
		self::flush_rewrites();
	}

	/**
	 * Deactivation routine.
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}
}
