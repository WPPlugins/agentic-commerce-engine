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
	const TABLE_AB_EVENTS = 'agencoen_ab_events';

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
	 * A/B exposures. The ONLY table in this plugin that holds a per-visitor row.
	 *
	 * A separate table rather than a column on agencoen_page_hits, because the
	 * two have opposite privacy postures and must be able to be dropped
	 * independently: page hits are aggregate and always on, this is per-visitor
	 * and off unless the site owner switches it on and a visitor consents. A
	 * shared table would mean disabling A/B could not remove its data without
	 * also removing the pageview history.
	 *
	 * @return string
	 */
	public static function ab_events_table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_AB_EVENTS;
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

		// A/B exposures. Two outcome flags per exposure row rather than two more
		// rows: the unique key is one row per visitor per experiment per day, and
		// a shopper who added to a basket and then bought is still one shopper.
		// `carted` is new in 4.14.0 and arrives by dbDelta on the version bump;
		// it defaults to 0, so existing rows read as "did not add", which is the
		// truth for them -- nothing was watching that step when they were written.
		//
		// A/B exposures. `visitor` is a salted hash computed at write time; the
		// raw cookie value is never stored. The unique key is one row per
		// visitor per experiment per day, so a visitor who reloads forty times
		// carries the weight of one -- the Rezolve Ai estimator's variance assumes
		// independent observations, and forty reloads are one observation.
		$ab     = self::ab_events_table();
		$ab_sql = "CREATE TABLE {$ab} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			day date NOT NULL,
			experiment varchar(64) NOT NULL,
			subject varchar(191) NOT NULL default '',
			arm varchar(8) NOT NULL default '',
			visitor char(40) NOT NULL,
			converted tinyint(1) unsigned NOT NULL default 0,
			carted tinyint(1) unsigned NOT NULL default 0,
			PRIMARY KEY  (id),
			UNIQUE KEY day_exp_visitor (day,experiment,visitor),
			KEY day_exp (day,experiment),
			KEY visitor_day (visitor,day)
		) {$charset};";
		dbDelta( $ab_sql );

		// New-in-4.5 defaults. A/B testing is the one feature here that sets a
		// cookie, so it defaults off and an upgrade must never switch it on.
		add_option( Settings::OPT_AB_ENABLED, 'no' );
		add_option( Settings::OPT_AB_REPORT, 'no' );
		add_option( Settings::OPT_AB_SELECTOR, '' );

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

		// 4.17.0: delete the old "score". Every value under that key was the
		// literal 90 the poller wrote for every enriched product, so there is
		// nothing in it worth migrating, and leaving it would keep a number that
		// measured nothing sitting next to the real scores. The cached chart
		// built from it goes too. A fresh install matches nothing, harmlessly.
		if ( version_compare( $installed, '4.17.0', '<' ) ) {
			delete_post_meta_by_key( Meta::LEGACY_SCORE );
			delete_transient( 'agencoen_score_dist' );
		}

		// Self-heal the daily crawler maintenance event on upgrades.
		if ( ! wp_next_scheduled( 'agencoen_bot_prune' ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'agencoen_bot_prune' );
		}

		// Arm the one-time walk that tells Rezolve Ai about decisions this site
		// made before it could report them.
		//
		// This is the ONLY way that history can ever reach the web review queue.
		// The Shopify half of the same work backfills with one SQL pass because
		// those rows live in Rezolve Ai's own database; this site's live in
		// `wp_postmeta` on the merchant's server, where no query from Rezolve Ai
		// can reach. So the plugin has to volunteer it.
		//
		// A cursor of -1 means a previous run finished, and must not restart.
		$backfill_cursor = get_option( 'agencoen_review_backfill_cursor', null );
		if ( -1 !== (int) $backfill_cursor && ! wp_next_scheduled( Cron::REVIEW_BACKFILL ) ) {
			if ( null === $backfill_cursor ) {
				add_option( 'agencoen_review_backfill_cursor', 0, '', false );
			}
			wp_schedule_single_event( time() + 120, Cron::REVIEW_BACKFILL );
		}
	}
}
