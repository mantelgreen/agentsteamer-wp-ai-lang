<?php
/**
 * Language registry.
 *
 * @package AgentSteamer_Lang
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores configured languages and resolves the current/default language.
 */
class AgentSteamer_Lang_Languages {

	/**
	 * Per-request caches.
	 *
	 * @var array
	 */
	protected static $get_cache = array();

	/**
	 * Slug to row cache.
	 *
	 * @var array
	 */
	protected static $slug_cache = array();

	/**
	 * List cache.
	 *
	 * @var array
	 */
	protected static $all_cache = array();

	/**
	 * Cached default code.
	 *
	 * @var string|null
	 */
	protected static $default_cache = null;

	/**
	 * Whether the default code has been loaded.
	 *
	 * @var bool
	 */
	protected static $default_loaded = false;

	/**
	 * Clear the per-request caches (call after any write).
	 */
	public static function flush_cache() {
		self::$get_cache      = array();
		self::$slug_cache     = array();
		self::$all_cache      = array();
		self::$default_cache  = null;
		self::$default_loaded = false;
	}

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'asl_languages';
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
			code varchar(12) NOT NULL DEFAULT '',
			locale varchar(20) NOT NULL DEFAULT '',
			name varchar(64) NOT NULL DEFAULT '',
			native_name varchar(64) NOT NULL DEFAULT '',
			slug varchar(12) NOT NULL DEFAULT '',
			flag varchar(16) NOT NULL DEFAULT '',
			dir varchar(3) NOT NULL DEFAULT 'ltr',
			date_format varchar(32) NOT NULL DEFAULT '',
			time_format varchar(32) NOT NULL DEFAULT '',
			active tinyint(1) NOT NULL DEFAULT 1,
			is_default tinyint(1) NOT NULL DEFAULT 0,
			sort_order int NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY code (code),
			KEY active (active)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * All languages ordered for display.
	 *
	 * @param bool $active_only Only active languages.
	 * @return array
	 */
	public static function all( $active_only = false ) {
		global $wpdb;
		$key = $active_only ? 'active' : 'all';
		if ( isset( self::$all_cache[ $key ] ) ) {
			return self::$all_cache[ $key ];
		}
		$table = self::table();
		$sql   = 'SELECT * FROM ' . $table;
		if ( $active_only ) {
			$sql .= ' WHERE active = 1';
		}
		$sql .= ' ORDER BY sort_order ASC, id ASC';
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB
		self::$all_cache[ $key ] = is_array( $rows ) ? $rows : array();
		return self::$all_cache[ $key ];
	}

	/**
	 * Get a language by code.
	 *
	 * @param string $code Language code.
	 * @return array|null
	 */
	public static function get( $code ) {
		global $wpdb;
		$code = agentsteamer_lang_normalize_code( $code );
		if ( '' === $code ) {
			return null;
		}
		if ( array_key_exists( $code, self::$get_cache ) ) {
			return self::$get_cache[ $code ];
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE code = %s', $code ), ARRAY_A ); // phpcs:ignore WordPress.DB
		self::$get_cache[ $code ] = $row ? $row : null;
		return self::$get_cache[ $code ];
	}

	/**
	 * Get a language by its URL slug.
	 *
	 * @param string $slug Slug.
	 * @return array|null
	 */
	public static function get_by_slug( $slug ) {
		global $wpdb;
		$slug = agentsteamer_lang_normalize_slug( $slug );
		if ( '' === $slug ) {
			return null;
		}
		if ( array_key_exists( $slug, self::$slug_cache ) ) {
			return self::$slug_cache[ $slug ];
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE slug = %s', $slug ), ARRAY_A ); // phpcs:ignore WordPress.DB
		self::$slug_cache[ $slug ] = $row ? $row : null;
		return self::$slug_cache[ $slug ];
	}

	/**
	 * Insert or update a language.
	 *
	 * @param array $data Language fields.
	 * @return true|WP_Error
	 */
	public static function save( array $data ) {
		global $wpdb;

		$code = agentsteamer_lang_normalize_code( isset( $data['code'] ) ? $data['code'] : '' );
		if ( '' === $code ) {
			return new WP_Error( 'agentsteamer_lang_bad_code', __( '语言代码无效。', 'agentsteamer-lang' ) );
		}

		$slug = agentsteamer_lang_normalize_slug( isset( $data['slug'] ) ? $data['slug'] : $code );
		if ( '' === $slug ) {
			$slug = strtolower( $code );
		}

		$row = array(
			'code'        => $code,
			'locale'      => isset( $data['locale'] ) ? sanitize_text_field( $data['locale'] ) : '',
			'name'        => isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : $code,
			'native_name' => isset( $data['native_name'] ) ? sanitize_text_field( $data['native_name'] ) : '',
			'slug'        => $slug,
			'flag'        => isset( $data['flag'] ) ? sanitize_text_field( $data['flag'] ) : '',
			'dir'         => ( isset( $data['dir'] ) && 'rtl' === $data['dir'] ) ? 'rtl' : 'ltr',
			'date_format' => isset( $data['date_format'] ) ? sanitize_text_field( $data['date_format'] ) : '',
			'time_format' => isset( $data['time_format'] ) ? sanitize_text_field( $data['time_format'] ) : '',
			'active'      => empty( $data['active'] ) ? 0 : 1,
			'sort_order'  => isset( $data['sort_order'] ) ? (int) $data['sort_order'] : 0,
		);
		if ( '' === $row['locale'] ) {
			$row['locale'] = $code;
		}

		self::flush_cache();

		$existing = self::get( $code );
		if ( $existing ) {
			$wpdb->update( self::table(), $row, array( 'code' => $code ) ); // phpcs:ignore WordPress.DB
		} else {
			if ( self::is_slug_taken( $slug, $code ) ) {
				return new WP_Error( 'agentsteamer_lang_slug_taken', __( '该 URL 段已被其它语言使用。', 'agentsteamer-lang' ) );
			}
			$wpdb->insert( self::table(), $row ); // phpcs:ignore WordPress.DB
		}
		self::flush_cache();

		// Ensure a default language exists.
		if ( null === self::default_code() ) {
			self::set_default( $code );
		}

		// Keep the language taxonomy term in sync.
		AgentSteamer_Lang_Taxonomy::sync_term( $code, $row );

		AgentSteamer_Lang_Plugin::flush_rewrites();

		return true;
	}

	/**
	 * Whether a slug is used by a different language.
	 *
	 * @param string $slug Slug.
	 * @param string $code Language code to exclude.
	 * @return bool
	 */
	public static function is_slug_taken( $slug, $code = '' ) {
		foreach ( self::all() as $lang ) {
			if ( $lang['slug'] === $slug && $lang['code'] !== $code ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Delete a language.
	 *
	 * @param string $code Language code.
	 * @return bool
	 */
	public static function delete( $code ) {
		global $wpdb;
		$code = agentsteamer_lang_normalize_code( $code );
		$lang = self::get( $code );
		if ( ! $lang ) {
			return false;
		}
		$wpdb->delete( self::table(), array( 'code' => $code ) ); // phpcs:ignore WordPress.DB

		AgentSteamer_Lang_Taxonomy::delete_term( $code );

		self::flush_cache();
		if ( self::default_code() === null || self::default_code() === $code ) {
			$remaining = self::all();
			if ( ! empty( $remaining ) ) {
				self::set_default( $remaining[0]['code'] );
			}
		}

		AgentSteamer_Lang_Plugin::flush_rewrites();
		return true;
	}

	/**
	 * Mark a language as the default.
	 *
	 * @param string $code Language code.
	 * @return bool
	 */
	public static function set_default( $code ) {
		global $wpdb;
		$code = agentsteamer_lang_normalize_code( $code );
		if ( ! self::get( $code ) ) {
			return false;
		}
		$table = self::table();
		$wpdb->query( 'UPDATE ' . $table . ' SET is_default = 0' ); // phpcs:ignore WordPress.DB
		$wpdb->update( $table, array( 'is_default' => 1 ), array( 'code' => $code ) ); // phpcs:ignore WordPress.DB
		self::flush_cache();
		return true;
	}

	/**
	 * The default language code.
	 *
	 * @return string|null
	 */
	public static function default_code() {
		global $wpdb;
		if ( self::$default_loaded ) {
			return self::$default_cache;
		}
		$table = self::table();
		$code  = $wpdb->get_var( 'SELECT code FROM ' . $table . ' WHERE is_default = 1 AND active = 1 ORDER BY id ASC LIMIT 1' ); // phpcs:ignore WordPress.DB
		if ( ! $code ) {
			$code = $wpdb->get_var( 'SELECT code FROM ' . $table . ' WHERE is_default = 1 ORDER BY id ASC LIMIT 1' ); // phpcs:ignore WordPress.DB
		}
		self::$default_cache  = $code ? $code : null;
		self::$default_loaded = true;
		return self::$default_cache;
	}

	/**
	 * The default language row.
	 *
	 * @return array|null
	 */
	public static function default_lang() {
		$code = self::default_code();
		return $code ? self::get( $code ) : null;
	}

	/**
	 * Active language codes.
	 *
	 * @return string[]
	 */
	public static function active_codes() {
		$out = array();
		foreach ( self::all( true ) as $lang ) {
			$out[] = $lang['code'];
		}
		return $out;
	}

	/**
	 * Locale for a language code.
	 *
	 * @param string $code Language code.
	 * @return string
	 */
	public static function locale_for( $code ) {
		$lang = self::get( $code );
		return $lang && ! empty( $lang['locale'] ) ? $lang['locale'] : $code;
	}
}
