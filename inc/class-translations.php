<?php
/**
 * Translation group registry (links language versions of the same content).
 *
 * @package AgentSteamer_Lang
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the {prefix}asl_translations table and translation groups.
 */
class AgentSteamer_Lang_Translations {

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'asl_translations';
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
			trid bigint(20) unsigned NOT NULL DEFAULT 0,
			element_type varchar(32) NOT NULL DEFAULT 'post',
			element_subtype varchar(32) NOT NULL DEFAULT '',
			element_id bigint(20) unsigned NOT NULL DEFAULT 0,
			lang varchar(12) NOT NULL DEFAULT '',
			source_lang varchar(12) NOT NULL DEFAULT '',
			status varchar(16) NOT NULL DEFAULT 'published',
			ai_generated tinyint(1) NOT NULL DEFAULT 0,
			model varchar(64) NOT NULL DEFAULT '',
			created_at datetime DEFAULT NULL,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY element (element_type,element_id,lang),
			KEY trid (trid),
			KEY element_id (element_id),
			KEY lang_status (lang,status)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Allocate a new translation-group id.
	 *
	 * @return int
	 */
	public static function next_trid() {
		$next = (int) get_option( 'agentsteamer_lang_next_trid', 1 );
		if ( $next < 1 ) {
			$next = 1;
		}
		update_option( 'agentsteamer_lang_next_trid', $next + 1, false );
		return $next;
	}

	/**
	 * Row for a post/language pair.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language code (optional).
	 * @return array|null
	 */
	public static function get_row( $post_id, $lang = '' ) {
		global $wpdb;
		$table = self::table();
		if ( '' !== $lang ) {
			$row = $wpdb->get_row(
				$wpdb->prepare( 'SELECT * FROM ' . $table . ' WHERE element_type = %s AND element_id = %d AND lang = %s', 'post', (int) $post_id, $lang ), // phpcs:ignore WordPress.DB
				ARRAY_A
			);
		} else {
			$row = $wpdb->get_row(
				$wpdb->prepare( 'SELECT * FROM ' . $table . ' WHERE element_type = %s AND element_id = %d ORDER BY id ASC LIMIT 1', 'post', (int) $post_id ), // phpcs:ignore WordPress.DB
				ARRAY_A
			);
		}
		return $row ? $row : null;
	}

	/**
	 * Translation group id for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	public static function trid_for( $post_id ) {
		$row = self::get_row( $post_id );
		return $row ? (int) $row['trid'] : 0;
	}

	/**
	 * Ensure a post is registered in the translation table.
	 *
	 * @param int    $post_id     Post ID.
	 * @param string $lang        Language code.
	 * @param int    $trid        Optional existing group id.
	 * @param string $source_lang Optional source language.
	 * @param array  $extra       Extra columns (status, ai_generated, model).
	 * @return int Translation group id.
	 */
	public static function ensure_link( $post_id, $lang, $trid = 0, $source_lang = '', $extra = array() ) {
		global $wpdb;
		$post_id = (int) $post_id;
		$lang    = agentsteamer_lang_normalize_code( $lang );
		if ( ! $post_id || '' === $lang ) {
			return 0;
		}

		$post   = get_post( $post_id );
		$status = $post && 'publish' === $post->post_status ? 'published' : 'draft';

		$existing = self::get_row( $post_id );
		if ( $existing ) {
			$update = array(
				'lang'        => $lang,
				'source_lang' => $source_lang ? agentsteamer_lang_normalize_code( $source_lang ) : $existing['source_lang'],
				'status'      => $status,
				'updated_at'  => current_time( 'mysql' ),
			);
			if ( $trid ) {
				$update['trid'] = (int) $trid;
			}
			$wpdb->update( self::table(), $update, array( 'id' => (int) $existing['id'] ) ); // phpcs:ignore WordPress.DB
			return (int) ( $trid ? $trid : $existing['trid'] );
		}

		if ( ! $trid ) {
			$trid = self::next_trid();
		}

		$row = array(
			'trid'           => (int) $trid,
			'element_type'   => 'post',
			'element_subtype' => $post ? $post->post_type : '',
			'element_id'     => $post_id,
			'lang'           => $lang,
			'source_lang'    => $source_lang ? agentsteamer_lang_normalize_code( $source_lang ) : '',
			'status'         => isset( $extra['status'] ) ? $extra['status'] : $status,
			'ai_generated'   => ! empty( $extra['ai_generated'] ) ? 1 : 0,
			'model'          => isset( $extra['model'] ) ? sanitize_text_field( $extra['model'] ) : '',
			'created_at'     => current_time( 'mysql' ),
			'updated_at'     => current_time( 'mysql' ),
		);
		$wpdb->insert( self::table(), $row ); // phpcs:ignore WordPress.DB
		return (int) $trid;
	}

	/**
	 * Update the stored status of a translation row.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $status  Status.
	 */
	public static function set_status( $post_id, $status ) {
		global $wpdb;
		$wpdb->update( self::table(), array( 'status' => $status, 'updated_at' => current_time( 'mysql' ) ), array( 'element_id' => (int) $post_id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * All rows sharing a translation group.
	 *
	 * @param int $trid Group id.
	 * @return array
	 */
	public static function rows_for_trid( $trid ) {
		global $wpdb;
		$trid = (int) $trid;
		if ( ! $trid ) {
			return array();
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE trid = %d ORDER BY id ASC', $trid ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * One preferred row per language for a post (published over draft).
	 *
	 * @param int $post_id Post ID.
	 * @return array Map of lang => row.
	 */
	public static function grouped_rows( $post_id ) {
		$trid = self::trid_for( $post_id );
		if ( ! $trid ) {
			return array();
		}
		$out = array();
		foreach ( self::rows_for_trid( $trid ) as $row ) {
			$lang = $row['lang'];
			if ( ! isset( $out[ $lang ] ) ) {
				$out[ $lang ] = $row;
				continue;
			}
			// Prefer a published row.
			if ( 'published' === $row['status'] && 'published' !== $out[ $lang ]['status'] ) {
				$out[ $lang ] = $row;
			}
		}
		return $out;
	}

	/**
	 * Translation records for a post, with resolved URLs.
	 *
	 * @param int  $post_id        Post ID.
	 * @param bool $include_trashed Include trashed posts.
	 * @return array[] Each: lang, locale, post_id, url, status, is_source, ai.
	 */
	public static function translations( $post_id, $include_trashed = false ) {
		$rows   = self::grouped_rows( $post_id );
		$source = self::get_row( $post_id );
		$source_lang = $source ? $source['source_lang'] : '';
		$out    = array();

		foreach ( $rows as $lang => $row ) {
			$post = get_post( (int) $row['element_id'] );
			if ( ! $post ) {
				continue;
			}
			if ( ! $include_trashed && 'trash' === $post->post_status ) {
				continue;
			}
			$out[ $lang ] = array(
				'lang'      => $lang,
				'locale'    => AgentSteamer_Lang_Languages::locale_for( $lang ),
				'post_id'   => (int) $post->ID,
				'url'       => get_permalink( $post ),
				'status'    => $post->post_status,
				'is_source' => ( $source && (int) $source['element_id'] === (int) $post->ID ) || ( '' !== $source_lang && $source_lang === $lang ),
				'ai'        => ! empty( $row['ai_generated'] ),
			);
		}
		return $out;
	}

	/**
	 * Map of the current post's translation permalinks by language.
	 *
	 * @param int $post_id Post ID.
	 * @return array lang => url
	 */
	public static function permalinks( $post_id ) {
		$out = array();
		foreach ( self::translations( $post_id ) as $lang => $t ) {
			$out[ $lang ] = $t['url'];
		}
		return $out;
	}

	/**
	 * Create a linked language version of a post.
	 *
	 * @param int    $source_id Source post ID.
	 * @param string $target    Target language code.
	 * @param array  $overrides Optional field overrides (title, content, excerpt, status, ai_generated, model).
	 * @return int|WP_Error New post id.
	 */
	public static function create_translation( $source_id, $target, array $overrides = array() ) {
		$source = get_post( (int) $source_id );
		if ( ! $source ) {
			return new WP_Error( 'agentsteamer_lang_no_source', __( '源内容不存在。', 'agentsteamer-lang' ) );
		}
		$target = agentsteamer_lang_normalize_code( $target );
		if ( ! AgentSteamer_Lang_Languages::get( $target ) ) {
			return new WP_Error( 'agentsteamer_lang_no_lang', __( '目标语言不存在。', 'agentsteamer-lang' ) );
		}

		// Re-use an existing draft for this language when present.
		$trid = self::ensure_link( $source_id, AgentSteamer_Lang_Taxonomy::get_post_lang( $source_id ) );
		foreach ( self::rows_for_trid( $trid ) as $row ) {
			if ( $row['lang'] === $target && get_post( (int) $row['element_id'] ) ) {
				return new WP_Error( 'agentsteamer_lang_exists', __( '该语言版本已存在。', 'agentsteamer-lang' ), array( 'existing' => (int) $row['element_id'] ) );
			}
		}

		$status = isset( $overrides['status'] ) ? $overrides['status'] : agentsteamer_lang_get_option( 'translate_status', 'draft' );
		if ( ! in_array( $status, array( 'draft', 'publish', 'pending', 'private' ), true ) ) {
			$status = 'draft';
		}

		// Hierarchical content (e.g. the wiki CPT): point the copy at the
		// translated parent when it exists, otherwise make it top-level.
		$parent_target = 0;
		if ( $source->post_parent ) {
			$parent_trans = self::translations( (int) $source->post_parent );
			if ( ! empty( $parent_trans[ $target ]['post_id'] ) ) {
				$parent_target = (int) $parent_trans[ $target ]['post_id'];
			}
		}

		$new_id = wp_insert_post(
			array(
				'post_title'   => isset( $overrides['title'] ) ? $overrides['title'] : $source->post_title,
				'post_content' => isset( $overrides['content'] ) ? $overrides['content'] : $source->post_content,
				'post_excerpt' => isset( $overrides['excerpt'] ) ? $overrides['excerpt'] : $source->post_excerpt,
				'post_type'    => $source->post_type,
				'post_status'  => $status,
				'post_author'  => isset( $overrides['author'] ) ? (int) $overrides['author'] : $source->post_author,
				'post_parent'  => $parent_target,
				'menu_order'   => (int) $source->menu_order,
			),
			true
		);

		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		// Inherit taxonomy terms (categories/tags) and meta.
		if ( empty( $overrides['skip_terms'] ) ) {
			self::copy_terms( $source_id, $new_id, $source->post_type, $target );
			self::copy_meta( $source_id, $new_id );
		}

		$thumb = get_post_thumbnail_id( $source_id );
		if ( $thumb ) {
			set_post_thumbnail( $new_id, $thumb );
		}

		AgentSteamer_Lang_Taxonomy::set_post_lang( $new_id, $target );

		$source_lang = AgentSteamer_Lang_Taxonomy::get_post_lang( $source_id );
		self::ensure_link(
			$new_id,
			$target,
			$trid,
			$source_lang,
			array(
				'ai_generated' => ! empty( $overrides['ai_generated'] ),
				'model'        => isset( $overrides['model'] ) ? $overrides['model'] : '',
				'status'       => ( 'publish' === $status ) ? 'published' : 'draft',
			)
		);

		return (int) $new_id;
	}

	/**
	 * Copy taxonomy terms (excluding the language taxonomy).
	 *
	 * @param int    $from      Source post.
	 * @param int    $to        Target post.
	 * @param string $post_type Post type.
	 */
	protected static function copy_terms( $from, $to, $post_type, $target = '' ) {
		$taxonomies = get_object_taxonomies( $post_type );
		foreach ( $taxonomies as $taxonomy ) {
			if ( AgentSteamer_Lang_Taxonomy::TAX === $taxonomy ) {
				continue;
			}
			$terms = wp_get_object_terms( $from, $taxonomy, array( 'fields' => 'ids' ) );
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}
			// Map each term to its target-language translation when one exists.
			$mapped = array();
			foreach ( $terms as $term_id ) {
				$translated = $target ? AgentSteamer_Lang_Terms::translated_term_id( $term_id, $target ) : 0;
				$mapped[]   = $translated ? $translated : (int) $term_id;
			}
			wp_set_object_terms( $to, $mapped, $taxonomy, false );
		}
	}

	/**
	 * Copy post meta (excluding plugin-owned and editor-internal keys).
	 *
	 * @param int $from Source post.
	 * @param int $to   Target post.
	 */
	protected static function copy_meta( $from, $to ) {
		$meta = get_post_meta( $from );
		if ( ! is_array( $meta ) ) {
			return;
		}
		$skip = array( '_edit_lock', '_edit_last', '_asl_source', '_asl_translated' );
		foreach ( $meta as $key => $values ) {
			if ( in_array( $key, $skip, true ) || 0 === strpos( $key, '_asl_' ) ) {
				continue;
			}
			foreach ( (array) $values as $value ) {
				$value = maybe_unserialize( $value );
				update_post_meta( $to, $key, $value );
			}
		}
	}

	/**
	 * Remove a post from its translation group.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function unlink( $post_id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'element_type' => 'post', 'element_id' => (int) $post_id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Remove all translation rows referencing a deleted post.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function delete_for_post( $post_id ) {
		self::unlink( $post_id );
	}

	/**
	 * Global coverage statistics.
	 *
	 * @return array total, by_lang (lang => count).
	 */
	public static function stats() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT lang, COUNT(*) AS c FROM ' . self::table() . ' GROUP BY lang', ARRAY_A ); // phpcs:ignore WordPress.DB
		$by   = array();
		$total = 0;
		foreach ( (array) $rows as $row ) {
			$by[ $row['lang'] ] = (int) $row['c'];
			$total             += (int) $row['c'];
		}
		return array(
			'total'   => $total,
			'by_lang' => $by,
		);
	}

	/**
	 * Per-content-type coverage, including custom post types (e.g. wiki).
	 *
	 * @return array Map of post type => { label, published, translated }.
	 */
	public static function coverage() {
		global $wpdb;

		// Translation groups that have at least two languages, grouped by type.
		$rows = $wpdb->get_results(
			'SELECT element_subtype AS sub, COUNT(*) AS c FROM (' .
			'SELECT trid, MAX(element_subtype) AS element_subtype FROM ' . self::table() .
			' GROUP BY trid HAVING COUNT(DISTINCT lang) >= 2' .
			') t GROUP BY element_subtype', // phpcs:ignore WordPress.DB
			ARRAY_A
		);
		$translated = array();
		foreach ( (array) $rows as $row ) {
			$type                = $row['sub'] ? $row['sub'] : 'post';
			$translated[ $type ] = (int) $row['c'];
		}

		$out = array();
		foreach ( agentsteamer_lang_supported_post_types() as $type ) {
			$object    = get_post_type_object( $type );
			$counts    = wp_count_posts( $type );
			$published = isset( $counts->publish ) ? (int) $counts->publish : 0;

			$out[ $type ] = array(
				'label'      => $object ? $object->labels->name : $type,
				'published'  => $published,
				'translated' => isset( $translated[ $type ] ) ? $translated[ $type ] : 0,
			);
		}
		return $out;
	}
}
