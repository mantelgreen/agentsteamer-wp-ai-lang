<?php
/**
 * Translation glossary (fixed terminology and do-not-translate terms).
 *
 * @package AgentSteamer_Lang
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores glossary entries used to steer AI translation.
 */
class AgentSteamer_Lang_Glossary {

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'asl_glossary';
	}

	/**
	 * Create the table.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$table   = self::table();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source text NOT NULL,
			target text NOT NULL,
			lang varchar(12) NOT NULL DEFAULT '',
			case_sensitive tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY lang (lang)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * All entries, optionally for one language (plus global '' entries).
	 *
	 * @param string $lang Target language code.
	 * @return array
	 */
	public static function all( $lang = '' ) {
		global $wpdb;
		$table = self::table();
		if ( '' === $lang ) {
			$rows = $wpdb->get_results( 'SELECT * FROM ' . $table . ' ORDER BY id DESC', ARRAY_A ); // phpcs:ignore WordPress.DB
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $table . ' WHERE lang = %s OR lang = %s ORDER BY id DESC', $lang, '' ), ARRAY_A ); // phpcs:ignore WordPress.DB
		}
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Add an entry.
	 *
	 * @param string $source Source term.
	 * @param string $target Target term.
	 * @param string $lang   Target language ('' = all).
	 * @param bool   $case   Case sensitive.
	 * @return int
	 */
	public static function add( $source, $target, $lang = '', $case = false ) {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB
			self::table(),
			array(
				'source'         => sanitize_text_field( $source ),
				'target'         => sanitize_text_field( $target ),
				'lang'           => agentsteamer_lang_normalize_code( $lang ),
				'case_sensitive' => $case ? 1 : 0,
				'created_at'     => current_time( 'mysql' ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Delete an entry.
	 *
	 * @param int $id Entry id.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;
		return (bool) $wpdb->delete( self::table(), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Build a glossary block for the translation prompt.
	 *
	 * @param string $lang Target language code.
	 * @return string
	 */
	public static function prompt_block( $lang ) {
		$entries = self::all( $lang );
		if ( empty( $entries ) ) {
			return '';
		}
		$lines = array( '术语表（必须严格遵守）：' );
		foreach ( $entries as $entry ) {
			if ( '' === $entry['target'] ) {
				$lines[] = '- 「' . $entry['source'] . '」：保持原文，不翻译';
			} else {
				$lines[] = '- 「' . $entry['source'] . '」→「' . $entry['target'] . '」';
			}
		}
		return implode( "\n", $lines );
	}
}
