<?php
/**
 * Schema install + versioned upgrades.
 *
 * dbDelta runs on activation and (via maybe_upgrade on admin_init) exactly
 * once per version bump, so future schema changes apply in place without a
 * reconnect.
 *
 * @package Agencoen
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Db
 */
class Db {

	const TABLE_BOT_HITS  = 'agencoen_bot_hits';
	const TABLE_PAGE_HITS = 'agencoen_page_hits';

	/**
	 * Full table name with the site prefix.
	 *
	 * @return string
	 */
	public static function bot_hits_table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_BOT_HITS;
	}

	/**
	 * Human pageviews. A SEPARATE table, not a synthetic bot slug in
	 * agencoen_bot_hits: that table's unique key is (day, bot, path_hash) and
	 * every aggregate over it, the byBot chart, build_heatmap() and
	 * report_back() all iterate its distinct bot values. A pseudo-slug would
	 * silently pollute all of them.
	 *
	 * @return string
	 */
	public static function page_hits_table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_PAGE_HITS;
	}

	/**
	 * Create/upgrade tables and seed new-in-4.0 option defaults.
	 * Idempotent: dbDelta diffs the schema, add_option never clobbers.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::bot_hits_table();
		$charset = $wpdb->get_charset_collate();

		// path_hash exists solely so the unique key fits utf8mb4 index limits
		// on older MariaDB; path stays readable for the top-pages table.
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			day date NOT NULL,
			bot varchar(32) NOT NULL,
			path varchar(191) NOT NULL default '',
			path_hash char(16) NOT NULL default '',
			hits int(10) unsigned NOT NULL default 1,
			PRIMARY KEY  (id),
			UNIQUE KEY day_bot_path (day,bot,path_hash),
			KEY day_bot (day,bot)
		) {$charset};";
		dbDelta( $sql );

		// Human pageviews, keyed by post id rather than path so a slug change
		// does not split a page's history in two.
		$pages = self::page_hits_table();
		$pages_sql = "CREATE TABLE {$pages} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			day date NOT NULL,
			post_id bigint(20) unsigned NOT NULL,
			referrer varchar(120) NOT NULL default '',
			views int(10) unsigned NOT NULL default 1,
			PRIMARY KEY  (id),
			UNIQUE KEY day_post_ref (day,post_id,referrer),
			KEY day_views (day,views)
		) {$charset};";
		dbDelta( $pages_sql );

		// New-in-4.4 defaults. Both default to 'no': storefront tracking is
		// opt-in, and an upgrade must never silently start collecting.
		add_option( Settings::OPT_TRAFFIC_ENABLED, 'no' );
		add_option( Settings::OPT_TRAFFIC_REPORT, 'no' );

		// New-in-4.0 defaults (never clobber existing values).
		add_option( Settings::OPT_BOTS_ENABLED, 'yes' );
		add_option( Settings::OPT_BOTS_PATHS, 'yes' );
		add_option( Settings::OPT_BOTS_RETENTION, '90' );
		add_option( Settings::OPT_BOTS_REPORT, 'no' );
		add_option( Settings::OPT_CONTENT_STATUS, 'draft' );
		add_option( Settings::OPT_CONTENT_FORMAT, 'blocks' );
		add_option( Settings::OPT_ARTICLE_SCHEMA, 'yes' );

		update_option( Settings::OPT_DB_VERSION, AGENCOEN_VERSION );
	}

	/**
	 * Run install() once per version bump (fires on admin_init).
	 */
	public static function maybe_upgrade() {
		$installed = (string) get_option( Settings::OPT_DB_VERSION, '' );
		if ( version_compare( $installed, AGENCOEN_VERSION, '>=' ) ) {
			return;
		}
		self::install();

		// Self-heal the daily crawler maintenance event on upgrades.
		if ( ! wp_next_scheduled( 'agencoen_bot_prune' ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'agencoen_bot_prune' );
		}
	}
}
