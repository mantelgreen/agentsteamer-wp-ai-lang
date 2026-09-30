<?php
/**
 * Uninstall routine.
 *
 * @package AgentSteamer_Lang
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'agentsteamer_lang_settings' );
delete_option( 'agentsteamer_lang_flush_rewrites' );
delete_option( 'agentsteamer_lang_db_version' );
delete_option( 'agentsteamer_lang_next_trid' );

global $wpdb;

// Remove plugin-owned post meta.
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_asl\_%'" ); // phpcs:ignore WordPress.DB

// Remove plugin-owned term meta.
$wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE '\_asl\_%'" ); // phpcs:ignore WordPress.DB

// Remove language taxonomy terms.
$term_ids = $wpdb->get_col( $wpdb->prepare( "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", 'asl_language' ) ); // phpcs:ignore WordPress.DB
if ( ! empty( $term_ids ) ) {
	foreach ( $term_ids as $term_id ) {
		wp_delete_term( (int) $term_id, 'asl_language' );
	}
}

// Drop plugin tables.
foreach ( array( 'asl_languages', 'asl_translations', 'asl_glossary', 'asl_reviews' ) as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore WordPress.DB
}

flush_rewrite_rules();
