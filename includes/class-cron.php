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

		if ( $this->plugin->has_woo() ) {
			add_action( self::SYNC_HOOK, array( $this, 'scheduled_sync' ) );
			add_action( self::SWEEP_HOOK, array( $this, 'auto_apply_sweep' ) );
			add_action( self::SWEEP_CONTINUE, array( $this, 'auto_apply_sweep' ) );
			add_action( Sync::CONTINUE_HOOK, array( $this->plugin->sync(), 'continue_sync' ) );
		}
	}

	/**
	 * Daily crawler-log maintenance: prune old rows, then (when opted in)
	 * report yesterday's aggregates to ACE.
	 */
	public function bot_maintenance() {
		$this->plugin->bots()->prune();
		if ( $this->plugin->settings()->is_bots_report() ) {
			$this->plugin->bots()->report_back();
		}
	}

	/**
	 * Standalone report tick (kept separate so a failed report can be retried
	 * without re-running the prune).
	 */
	public function bot_report() {
		if ( $this->plugin->settings()->is_bots_report() ) {
			$this->plugin->bots()->report_back();
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
	 * Heartbeat: report health and refresh the credit cache.
	 */
	public function heartbeat() {
		$settings = $this->plugin->settings();
		if ( ! $settings->is_connected() ) {
			return;
		}

		$this->plugin->api()->heartbeat(
			array(
				'storeId'       => $settings->get_store_id(),
				'pluginVersion' => AGENCOEN_VERSION,
				'wcVersion'     => defined( 'WC_VERSION' ) ? WC_VERSION : null,
				'productCount'  => $this->plugin->repository()->count_published(),
			)
		);

		// Keep the dashboard balance reasonably fresh.
		Dashboard::credits( $this->plugin, Dashboard::FETCH_FRESH );
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

		$ids     = $this->plugin->repository()->ids_with_status( Meta::STATUS_ENRICHED, 1, self::SWEEP_LIMIT );
		$applied = 0;
		foreach ( $ids as $id ) {
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
