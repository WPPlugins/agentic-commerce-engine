<?php
/**
 * Scheduled tasks: heartbeat, auto-sync, the sync continuation tick, and the
 * auto-apply sweep. Also registers a "weekly" schedule and reschedules auto-sync
 * when its settings change.
 *
 * @package Agencoen
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Cron
 */
class Cron {

	const SYNC_HOOK      = 'agencoen_scheduled_sync';
	const HEARTBEAT      = 'agencoen_heartbeat';
	const SWEEP_HOOK     = 'agencoen_auto_apply_sweep';
	const SWEEP_CONTINUE = 'agencoen_auto_apply_drain';
	const BOT_PRUNE      = 'agencoen_bot_prune';
	const BOT_REPORT     = 'agencoen_bot_report';
	const CONTENT_POLL   = 'agencoen_content_poll';
	/** Report this site's review decisions back to Rezolve Ai (4.15.0). */
	const REVIEW_SYNC    = 'agencoen_review_sync';
	/** One-time walk of existing decisions, armed by a version bump. */
	const REVIEW_BACKFILL = 'agencoen_review_backfill';
	/** One full catalog sync, armed by an upgrade that changed what a product syncs as (4.20.1). */
	const CATALOG_RESYNC = 'agencoen_catalog_resync';
	/** Failed first pages counted so far for CATALOG_RESYNC. */
	const OPT_RESYNC_TRIES = 'agencoen_catalog_resync_tries';
	/** Tries in all before the re-sync is left to the next scheduled or manual sync. */
	const RESYNC_MAX_TRIES = 3;
	const SWEEP_LIMIT    = 100;

	/** @var Plugin */
	private $plugin;

	/**
	 * @param Plugin $plugin Orchestrator.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Wire cron hooks + the custom schedule.
	 */
	public function register() {
		add_filter( 'cron_schedules', array( $this, 'add_weekly_schedule' ) );
		add_action( self::HEARTBEAT, array( $this, 'heartbeat' ) );
		add_action( self::BOT_PRUNE, array( $this, 'bot_maintenance' ) );
		add_action( self::BOT_REPORT, array( $this, 'bot_report' ) );
		add_action( self::CONTENT_POLL, array( $this->plugin->articles(), 'poll_job' ), 10, 1 );
		// `run` reports decisions and then refreshes cached quality scores (4.17.0).
		add_action( self::REVIEW_SYNC, array( $this->plugin->review_sync(), 'run' ) );
		add_action( self::REVIEW_BACKFILL, array( $this->plugin->review_sync(), 'backfill' ) );

		if ( $this->plugin->has_woo() ) {
			add_action( self::SYNC_HOOK, array( $this, 'scheduled_sync' ) );
			add_action( self::SWEEP_HOOK, array( $this, 'auto_apply_sweep' ) );
			add_action( self::SWEEP_CONTINUE, array( $this, 'auto_apply_sweep' ) );
			add_action( Sync::CONTINUE_HOOK, array( $this->plugin->sync(), 'continue_sync' ) );
			add_action( self::CATALOG_RESYNC, array( $this, 'catalog_resync' ) );
		}
	}

	/**
	 * Daily crawler-log maintenance: prune old rows, then (when opted in)
	 * report yesterday's aggregates to Rezolve Ai.
	 */
	public function bot_maintenance() {
		$this->plugin->bots()->prune();
		if ( $this->plugin->settings()->is_bots_report() ) {
			$this->plugin->bots()->report_back();
			Signed_Agents::report( $this->plugin );
		}

		// Storefront traffic and sales ride the same daily tick rather than
		// adding two more wp-cron events: they share a retention setting and a
		// single failure window, and one scheduled event is one thing to keep
		// alive instead of three.
		if ( $this->plugin->settings()->is_traffic_enabled() ) {
			$this->plugin->traffic()->prune();
		}
		if ( $this->plugin->settings()->is_traffic_report() ) {
			$this->plugin->traffic()->report_back();
			$this->plugin->sales()->report_back();
		}

		// A/B exposures ride the same tick, but on their OWN switches. They are
		// the one thing here that holds a per-visitor row, so pruning and
		// reporting them must not be reachable from the traffic settings: a site
		// owner who turns storefront traffic on has not thereby agreed to split
		// their visitors or to send per-visitor rows anywhere.
		// The config reconcile runs UNCONDITIONALLY, because its job when the
		// feature is off is to remove every config this site is holding. Gating
		// it on the setting would leave a page splitting visitors after the site
		// owner switched A/B testing off, which is the one outcome that must not
		// be possible.
		$this->plugin->ab_test()->sync_configs();
		if ( $this->plugin->settings()->is_ab_enabled() ) {
			$this->plugin->ab_test()->prune();
			$this->plugin->ab_test()->report_back();
		}
	}

	/**
	 * Standalone report tick (kept separate so a failed report can be retried
	 * without re-running the prune).
	 */
	public function bot_report() {
		if ( $this->plugin->settings()->is_bots_report() ) {
			$this->plugin->bots()->report_back();
			Signed_Agents::report( $this->plugin );
		}
	}

	/**
	 * Add a weekly interval (WP core only ships hourly/twicedaily/daily).
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public function add_weekly_schedule( $schedules ) {
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array(
				'interval' => WEEK_IN_SECONDS,
				'display'  => __( 'Once Weekly', 'agentic-commerce-engine' ),
			);
		}
		return $schedules;
	}

	/**
	 * WooCommerce's base country (Settings, General, Store Address) as an
	 * uppercase ISO 3166-1 alpha-2 code, or '' when WooCommerce is absent or
	 * the setting is not a country.
	 *
	 * @return string
	 */
	public static function base_country() {
		if ( ! function_exists( 'WC' ) ) {
			return '';
		}
		$wc = WC();
		if ( ! $wc || ! isset( $wc->countries ) || ! is_object( $wc->countries ) || ! method_exists( $wc->countries, 'get_base_country' ) ) {
			return '';
		}
		$code = strtoupper( (string) $wc->countries->get_base_country() );
		return preg_match( '/^[A-Z]{2}$/', $code ) ? $code : '';
	}

	/**
	 * Heartbeat: report health and refresh the credit cache.
	 */
	public function heartbeat() {
		$settings = $this->plugin->settings();
		if ( ! $settings->is_connected() ) {
			return;
		}

		$body = array(
			'storeId'       => $settings->get_store_id(),
			'pluginVersion' => AGENCOEN_VERSION,
			'wcVersion'     => defined( 'WC_VERSION' ) ? WC_VERSION : null,
			'productCount'  => $this->plugin->repository()->count_published(),
			// What this site is actually measuring. Sent every hour rather
			// than once at connect time, because both switches live in
			// wp_options and can be turned off without Rezolve Ai ever hearing.
			// Until this shipped, nothing on the Rezolve Ai side could tell a site
			// that had declined tracking from one that had never been asked.
			'traffic'       => array(
				'enabled' => $settings->is_traffic_enabled(),
				'report'  => $settings->is_traffic_report(),
			),
		);
		// The store's own country, for the OpenAI product file's store_country.
		// Sent only when WooCommerce states one, so an unset base country keeps
		// whatever was last reported rather than clearing it.
		$country = self::base_country();
		if ( '' !== $country ) {
			$body['storeCountry'] = $country;
		}

		$response = $this->plugin->api()->heartbeat( $body );

		// Learn (or forget) the UCP delivery token. It rides the heartbeat so
		// the merchant never has to paste anything, and so turning delivery off
		// in Rezolve Ai stops this site serving /.well-known/ucp within the hour
		// rather than leaving a profile pointing at endpoints that now 404.
		//
		// Only acted on when the key is PRESENT: an older Rezolve Ai that does not
		// send it must not read as "delivery is off" and wipe a working token.
		if ( is_array( $response ) && array_key_exists( 'ucp', $response ) ) {
			$ucp = is_array( $response['ucp'] ) ? $response['ucp'] : array();
			Ucp::set_token( isset( $ucp['token'] ) && is_string( $ucp['token'] ) ? $ucp['token'] : '' );
		}

		// Batch ceiling and supported fields, refreshed hourly. Only acted on
		// when PRESENT and non-empty, for the same reason as the token above: an
		// older Rezolve Ai that does not send it must not read as "sync is off"
		// and wipe a working config.
		if ( is_array( $response ) && ! empty( $response['syncConfig'] ) && is_array( $response['syncConfig'] ) ) {
			$settings->save_sync_config( $response['syncConfig'] );
		}

		// Where storefront content goes, refreshed hourly. Same PRESENT-only
		// rule as the two above: an older Rezolve Ai that does not send it must
		// not read as "no placement" and move the merchant's cards. This is the
		// path that keeps a choice made in the web app or the Shopify app
		// honoured on this storefront without the merchant touching anything.
		if ( is_array( $response ) && ! empty( $response['contentPlacement'] ) && is_array( $response['contentPlacement'] ) ) {
			$settings->save_placement( $response['contentPlacement'] );
		}

		// Where each content type is written (4.25.0): the block, one of the
		// store's own fields, or a description section. PRESENT-only, but an
		// EMPTY map is meaningful here: nothing is routed, everything goes to
		// the block, so it is saved rather than skipped.
		if ( is_array( $response ) && isset( $response['contentDestinations'] ) && is_array( $response['contentDestinations'] ) ) {
			Content_Destinations::save( $response['contentDestinations'] );
		}

		// Custom fields the merchant left out, refreshed hourly. PRESENT-only
		// like the others. When a field was brought back, one catalogue sync
		// sends it again (it was stripped from the platform when left out).
		if ( is_array( $response ) && isset( $response['customFields'] ) && is_array( $response['customFields'] ) ) {
			if ( $settings->save_custom_fields( $response['customFields'] ) && ! wp_next_scheduled( self::CATALOG_RESYNC ) ) {
				wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, self::CATALOG_RESYNC );
			}
		}

		// The store's policy pages, for the seller columns of the OpenAI
		// product file (4.34.0). WooCommerce stores only: no other kind of
		// site has a product file. Sent when changed or a day old, never on
		// every tick; see Legal_Policies.
		if ( $this->plugin->has_woo() ) {
			Legal_Policies::report( $this->plugin );
		}

		// Keep the dashboard balance reasonably fresh.
		Dashboard::credits( $this->plugin, Dashboard::FETCH_FRESH );

		// Refresh the content-type catalog. The enrichment picker reads it from
		// cache only, and it is what decides whether highlights, Q&A, attributes,
		// alt text and product title are offered. Fetched FRESH, after the
		// heartbeat above has told Rezolve Ai which version this is: the platform
		// only lists those types to a plugin it knows can apply them, so a
		// catalog cached before an upgrade would hide them for up to twelve hours.
		$this->plugin->articles()->types( true );

		// Pull anything Rezolve Ai has approved for this site and report what landed.
		// HOURLY, not daily: the ack is what makes a version count as live for
		// an A/B test, so a daily tick would put up to a day between a merchant
		// approving a challenger in Rezolve Ai and the test being able to start.
		//
		// Woo-gated inside `drain()` rather than here, because the guard has to
		// hold for the manual path too.
		$this->plugin->apply_queue()->drain();

		// And the opposite direction: what this site's merchant decided.
		// Hourly for the same reason -- a day of disagreement between this
		// Review screen and the one in Rezolve Ai is indistinguishable from the
		// split this exists to close. The same tick refreshes cached quality
		// scores, so the Products screen and dashboard do not go stale.
		$this->plugin->review_sync()->run();
	}

	/**
	 * Recurring auto-sync entry point.
	 */
	public function scheduled_sync() {
		if ( $this->plugin->settings()->is_connected() ) {
			$this->plugin->sync()->kickoff_full_sync();
		}
	}

	/**
	 * The one full sync an upgrade arms when it changes what a product syncs
	 * as. 4.20.1: an image with no alt text used to be sent with the product
	 * name in its place, and Rezolve Ai keeps what it was sent until the product
	 * is synced again. Auto-sync is off by default, so without this most sites
	 * would keep showing the name for months.
	 *
	 * Connected sites only: a site that connects later sends a full sync then.
	 * A first page that fails is tried again an hour later, RESYNC_MAX_TRIES
	 * times in all, after which the next scheduled or manual sync carries the
	 * fix. Later pages already retry through the sync's own continuation.
	 */
	public function catalog_resync() {
		if ( ! $this->plugin->settings()->is_connected() ) {
			delete_option( self::OPT_RESYNC_TRIES );
			return;
		}

		$result = $this->plugin->sync()->kickoff_full_sync();
		if ( ! is_wp_error( $result ) ) {
			delete_option( self::OPT_RESYNC_TRIES );
			return;
		}

		$tries = (int) get_option( self::OPT_RESYNC_TRIES, 0 ) + 1;
		if ( $tries >= self::RESYNC_MAX_TRIES ) {
			delete_option( self::OPT_RESYNC_TRIES );
			$this->plugin->logger()->error( 'Catalog re-sync gave up after ' . $tries . ' tries; the next sync will send the update: ' . $result->get_error_message() );
			return;
		}
		update_option( self::OPT_RESYNC_TRIES, $tries, false );
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, self::CATALOG_RESYNC );
	}

	/**
	 * Apply a bounded batch of enriched-but-unapplied products when auto-apply
	 * is on, then self-reschedule to drain any remaining backlog.
	 *
	 * Processing one full batch (then immediately re-queuing a single near-term
	 * tick while work remains) lets a 10k catalog drain in minutes instead of
	 * one batch per daily run, without an unbounded loop inside a single request.
	 */
	public function auto_apply_sweep() {
		$settings = $this->plugin->settings();
		if ( ! $settings->is_connected() || ! $settings->is_auto_apply() ) {
			return;
		}

		// Products a product split was last found holding are stepped past,
		// and the rest are asked about in one request before any is applied
		// (4.29.0). A check that fails applies nothing and does not re-arm:
		// the next daily sweep tries again.
		$ids     = $this->plugin->repository()->ids_with_status( Meta::STATUS_ENRICHED, 1, self::SWEEP_LIMIT, true );
		$checked = $this->plugin->held_back()->check( $ids );
		if ( is_wp_error( $checked ) ) {
			return;
		}
		$applied = 0;
		foreach ( $ids as $id ) {
			if ( ! empty( $checked[ $id ] ) ) {
				// Held: no longer in the sweep's window, which is progress.
				++$applied;
				continue;
			}
			if ( ! is_wp_error( $this->plugin->apply()->apply( $id ) ) ) {
				++$applied;
			}
		}

		// Re-queue one continuation tick only when a full batch was applied
		// successfully (a backlog likely remains). Stops at the tail, and also
		// stops if a full batch made zero progress, avoiding a hot retry loop on
		// permanently failing items (those wait for the next daily sweep).
		if ( $applied > 0 && count( $ids ) === self::SWEEP_LIMIT && ! wp_next_scheduled( self::SWEEP_CONTINUE ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::SWEEP_CONTINUE );
		}
	}

	/**
	 * Reschedule auto-sync to match current settings. Call after settings save
	 * and after connecting.
	 */
	public function reschedule_sync() {
		wp_clear_scheduled_hook( self::SYNC_HOOK );

		$settings = $this->plugin->settings();
		if ( $settings->is_connected() && $settings->is_auto_sync() ) {
			$recurrence = $settings->get_sync_frequency(); // daily|weekly.
			wp_schedule_event( time() + MINUTE_IN_SECONDS, $recurrence, self::SYNC_HOOK );
		}

		// Keep the auto-apply sweep scheduled while auto-apply is on.
		wp_clear_scheduled_hook( self::SWEEP_HOOK );
		wp_clear_scheduled_hook( self::SWEEP_CONTINUE );
		if ( $settings->is_connected() && $settings->is_auto_apply() ) {
			wp_schedule_event( time() + ( 2 * MINUTE_IN_SECONDS ), 'daily', self::SWEEP_HOOK );
		}
	}
}
