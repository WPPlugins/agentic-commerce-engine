<?php
/**
 * Uninstall cleanup. Removes all plugin options, per-product meta, transients,
 * and scheduled events. Runs only when the user deletes the plugin.
 *
 * @package Agencoen
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// One-time bulk cleanup on uninstall; caching does not apply, and dropping the
// plugin's own crawler-analytics table is the entire point of uninstalling.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange

// Options (agencoen_*).
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'agencoen\\_%'" );

// Transients (agencoen_*), both value and timeout rows.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_agencoen\\_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_timeout\\_agencoen\\_%'" );

// Per-product meta (_agencoen_*).
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_agencoen\\_%'" );

// Per-user meta (agencoen_*: notice and start-card dismissals).
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'agencoen\\_%'" );

// Crawler-analytics table (4.0).
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}agencoen_bot_hits" );

// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange

// Scheduled events.
$agencoen_hooks = array(
	'agencoen_heartbeat',
	'agencoen_scheduled_sync',
	'agencoen_sync_continue',
	'agencoen_auto_apply_sweep',
	'agencoen_auto_apply_drain',
	'agencoen_poll_enrichment',
	'agencoen_bot_prune',
	'agencoen_bot_report',
	'agencoen_content_poll',
	'agencoen_review_sync',
	'agencoen_review_backfill',
);
foreach ( $agencoen_hooks as $agencoen_hook ) {
	wp_clear_scheduled_hook( $agencoen_hook );
}
