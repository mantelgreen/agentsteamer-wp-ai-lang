<?php
/**
 * Database installer.
 *
 * @package AgentSteamer_Lang
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and upgrades plugin tables.
 */
class AgentSteamer_Lang_Install {

	/**
	 * Schema version.
	 */
	const VERSION = '3';

	/**
	 * Run all installers.
	 */
	public static function run() {
		AgentSteamer_Lang_Languages::install();
		AgentSteamer_Lang_Translations::install();
		AgentSteamer_Lang_Glossary::install();
		AgentSteamer_Lang_Review::install();

		// One-time: re-map existing translated posts to their language's terms.
		if ( class_exists( 'AgentSteamer_Lang_Terms' ) ) {
			AgentSteamer_Lang_Terms::resync_post_terms();
		}

		update_option( 'agentsteamer_lang_db_version', self::VERSION );
	}

	/**
	 * Run installers when the schema is outdated.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'agentsteamer_lang_db_version' ) !== self::VERSION ) {
			self::run();
		}
	}
}
