<?php
/**
 * Assembles the dashboard payload (counts, credits, connection health, chart
 * data, plus the 4.0 cross-mode blocks: local article counts, native AI
 * crawler summary, and the AI-search overview preview) shared by the
 * server-rendered views, the inline window.agencoenBoot boot payload, and the
 * refresh ajax handler.
 *
 * On a Woo-less site the commerce blocks (counts, coverage, scores, activity)
 * return empty-safe defaults without touching the product tables.
 *
 * @package Agencoen
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Dashboard
 */
class Dashboard {

	const CREDITS_TRANSIENT = 'agencoen_credits_cache';
	const CREDITS_BACKOFF   = 'agencoen_credits_backoff';

	/**
	 * Fetch modes. Render paths use CACHED (never remote HTTP); the post-paint
	 * ajax fill uses WARM (remote only when the cache is cold or stale); the
	 * manual Refresh button and the hourly heartbeat use FRESH.
	 */
	const FETCH_CACHED = 'cached';
	const FETCH_WARM   = 'warm';
	const FETCH_FRESH  = 'fresh';

	/**
	 * Per-request memo so Assets::enqueue() and Admin::render() (which both
	 * run during one admin page load) share a single payload computation.
	 *
	 * @var array<string,array>
	 */
	private static $memo = array();

	/** Drop the per-request memo (call after any payload-affecting mutation). */
	public static function reset_memo() {
		self::$memo = array();
	}

	/**
	 * Full dashboard payload.
	 *
	 * @param Plugin $plugin Orchestrator.
	 * @param string $fetch  One of the FETCH_* modes.
	 * @return array
	 */
	public static function data( Plugin $plugin, $fetch = self::FETCH_CACHED ) {
		if ( isset( self::$memo[ $fetch ] ) ) {
			return self::$memo[ $fetch ];
		}

		$settings  = $plugin->settings();
		$repo      = $plugin->repository();
		$has_woo   = $plugin->has_woo();
		$connected = $settings->is_connected();

		if ( $has_woo ) {
			$counts = array(
				'total'         => $repo->count_published(),
				'enriched'      => $repo->count_with_status( Meta::STATUS_ENRICHED ),
				'applied'       => $repo->count_with_status( Meta::STATUS_APPLIED ),
				'queued'        => $repo->count_with_status( Meta::STATUS_QUEUED ),
				'failed'        => $repo->count_with_status( Meta::STATUS_FAILED ),
				'pendingReview' => $repo->pending_review_count(),
			);
		} else {
			$counts = array(
				'total'         => 0,
				'enriched'      => 0,
				'applied'       => 0,
				'queued'        => 0,
				'failed'        => 0,
				'pendingReview' => 0,
			);
		}

		$credits = self::credits( $plugin, $fetch );
		$aeo     = $connected ? $plugin->aeo()->overview_preview( self::FETCH_CACHED !== $fetch ) : null;

		$payload = array(
			'connected'  => $connected,
			'hasWoo'     => $has_woo,
			'baseUrl'    => $settings->get_base_url(),
			'storeId'    => $settings->get_store_id(),
			'credits'    => $credits,
			'counts'     => $counts,
			'lastSync'   => self::last_sync_human( $settings ),
			'autoSync'   => $settings->is_auto_sync(),
			'autoApply'  => $settings->is_auto_apply(),
			'schema'     => $settings->is_schema_enabled(),
			'seoPlugin'  => $plugin->seo()->label(),
			'webhookUrl' => Webhook::callback_url(),
			'charts'     => self::charts( $plugin ),
			// 4.0 blocks. The crawler tracker and local article counts work
			// without a connection; the AI-search preview needs the API.
			'bots'       => $plugin->bots()->summary_light( 7 ),
			'content'    => $plugin->articles()->local_counts(),
			'aeo'        => $aeo,
			// Tells the dashboard JS whether a post-paint warm fill is worth it.
			'meta'       => array(
				'creditsStale' => ! empty( $credits['stale'] ),
				'aeoCold'      => $connected
					&& $settings->feature_enabled( 'ecomAeo' )
					&& ! $plugin->aeo()->has_cached_overview(),
			),
		);

		self::$memo[ $fetch ] = $payload;
		if ( self::FETCH_CACHED !== $fetch ) {
			// A warm/fresh payload is strictly newer than a cached one.
			self::$memo[ self::FETCH_CACHED ] = $payload;
		}
		return $payload;
	}

	/**
	 * Chart-ready data: coverage breakdown, score distribution, activity trend.
	 * Empty-safe defaults on a Woo-less site (no product queries run).
	 *
	 * @param Plugin $plugin Orchestrator.
	 * @return array
	 */
	public static function charts( Plugin $plugin ) {
		$has_woo = $plugin->has_woo();
		$repo    = $plugin->repository();
		$counts  = $has_woo ? $repo->status_counts_full() : array();

		$coverage = array(
			array(
				'key'   => Meta::STATUS_APPLIED,
				'label' => __( 'Applied', 'agentic-commerce-engine' ),
				'count' => isset( $counts[ Meta::STATUS_APPLIED ] ) ? (int) $counts[ Meta::STATUS_APPLIED ] : 0,
				'color' => '#10b981',
			),
			array(
				'key'   => Meta::STATUS_ENRICHED,
				'label' => __( 'Ready to review', 'agentic-commerce-engine' ),
				'count' => isset( $counts[ Meta::STATUS_ENRICHED ] ) ? (int) $counts[ Meta::STATUS_ENRICHED ] : 0,
				'color' => '#f59e0b',
			),
			array(
				'key'   => Meta::STATUS_QUEUED,
				'label' => __( 'Queued', 'agentic-commerce-engine' ),
				'count' => isset( $counts[ Meta::STATUS_QUEUED ] ) ? (int) $counts[ Meta::STATUS_QUEUED ] : 0,
				'color' => '#6366f1',
			),
			array(
				'key'   => Meta::STATUS_FAILED,
				'label' => __( 'Failed', 'agentic-commerce-engine' ),
				'count' => isset( $counts[ Meta::STATUS_FAILED ] ) ? (int) $counts[ Meta::STATUS_FAILED ] : 0,
				'color' => '#f43f5e',
			),
			array(
				'key'   => Meta::STATUS_DISMISSED,
				'label' => __( 'Dismissed', 'agentic-commerce-engine' ),
				'count' => isset( $counts[ Meta::STATUS_DISMISSED ] ) ? (int) $counts[ Meta::STATUS_DISMISSED ] : 0,
				'color' => '#9ca3af',
			),
			array(
				'key'   => 'not_enriched',
				'label' => __( 'Not enriched', 'agentic-commerce-engine' ),
				'count' => isset( $counts['not_enriched'] ) ? (int) $counts['not_enriched'] : 0,
				'color' => '#e5e7eb',
			),
		);

		return array(
			'coverage' => $coverage,
			'scores'   => array(
				'labels'  => array( '0-20', '20-40', '40-60', '60-80', '80-100' ),
				'buckets' => $has_woo ? $repo->score_distribution() : array_fill( 0, 5, 0 ),
			),
			'activity' => $has_woo ? $repo->applied_per_day( 14 ) : self::empty_activity( 14 ),
		);
	}

	/**
	 * A zero-filled applied-per-day series (Woo-less sites).
	 *
	 * @param int $days Number of days.
	 * @return array<int,array{date:string,count:int}>
	 */
	private static function empty_activity( $days ) {
		$out = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$out[] = array(
				'date'  => gmdate( 'Y-m-d', time() - ( $i * DAY_IN_SECONDS ) ),
				'count' => 0,
			);
		}
		return $out;
	}

	/**
	 * Credit balance with stale-if-error.
	 *
	 * The transient stores the last successful fetch (plus `fetched_at`) for a
	 * week; freshness (1 hour) is enforced in code, so during a platform
	 * outage the tile keeps showing the last known balance flagged
	 * `stale => true` instead of zeros. Failures arm a short backoff transient
	 * so remote refetches happen at most once per window, and FETCH_CACHED
	 * never performs remote HTTP at all.
	 *
	 * @param Plugin $plugin Orchestrator.
	 * @param string $fetch  One of the FETCH_* modes.
	 * @return array { balance, used, limit, stale }
	 */
	public static function credits( Plugin $plugin, $fetch = self::FETCH_CACHED ) {
		if ( ! $plugin->settings()->is_connected() ) {
			return array(
				'balance' => 0,
				'used'    => 0,
				'limit'   => 0,
				'stale'   => false,
			);
		}

		$cached = get_transient( self::CREDITS_TRANSIENT );
		$cached = is_array( $cached ) ? $cached : null;
		$now    = time();
		$fresh  = null !== $cached && self::is_fresh( $cached, $now );

		if ( self::FETCH_CACHED === $fetch ) {
			return self::serve_cached( $cached, $fresh );
		}
		if ( self::FETCH_WARM === $fetch && $fresh ) {
			return self::serve_cached( $cached, true );
		}
		if ( false !== get_transient( self::CREDITS_BACKOFF ) ) {
			return self::serve_cached( $cached, $fresh );
		}

		$result = $plugin->api()->usage();
		if ( is_wp_error( $result ) ) {
			set_transient( self::CREDITS_BACKOFF, 1, 3 * MINUTE_IN_SECONDS );
			return self::serve_cached( $cached, false );
		}

		$credits               = self::shape_credits( is_array( $result ) ? $result : array() );
		$credits['fetched_at'] = $now;
		set_transient( self::CREDITS_TRANSIENT, $credits, WEEK_IN_SECONDS );
		unset( $credits['fetched_at'] );
		$credits['stale'] = false;
		return $credits;
	}

	/**
	 * Normalize the /usage payload into the credits shape. Pure.
	 *
	 * @param array $result Unwrapped /usage payload.
	 * @return array { balance, used, limit }
	 */
	public static function shape_credits( array $result ) {
		$bucket  = isset( $result['credits'] ) && is_array( $result['credits'] ) ? $result['credits'] : array();
		$balance = (int) ( $bucket['balance'] ?? 0 );
		$limit   = (int) ( $bucket['limit'] ?? 0 );
		return array(
			'balance' => $balance,
			'used'    => max( 0, $limit - $balance ),
			'limit'   => $limit,
		);
	}

	/**
	 * Whether a stored credits blob is within the 1-hour freshness window. Pure.
	 *
	 * @param array $cached Stored credits blob (with fetched_at).
	 * @param int   $now    Current unix time.
	 * @return bool
	 */
	public static function is_fresh( $cached, $now ) {
		$fetched = isset( $cached['fetched_at'] ) ? (int) $cached['fetched_at'] : 0;
		return $fetched > ( (int) $now - HOUR_IN_SECONDS );
	}

	/**
	 * Shape a stored blob (or nothing) for callers.
	 *
	 * @param array|null $cached Stored credits blob.
	 * @param bool       $fresh  Whether it is within the freshness window.
	 * @return array { balance, used, limit, stale }
	 */
	private static function serve_cached( $cached, $fresh ) {
		if ( null === $cached ) {
			return array(
				'balance' => 0,
				'used'    => 0,
				'limit'   => 0,
				'stale'   => true,
			);
		}
		return array(
			'balance' => (int) ( $cached['balance'] ?? 0 ),
			'used'    => (int) ( $cached['used'] ?? 0 ),
			'limit'   => (int) ( $cached['limit'] ?? 0 ),
			'stale'   => ! $fresh,
		);
	}

	/**
	 * Last sync time as a relative string, or "Never".
	 *
	 * @param Settings $settings Settings.
	 * @return string
	 */
	private static function last_sync_human( Settings $settings ) {
		$last = $settings->get_last_sync();
		if ( '' === $last ) {
			return __( 'Never', 'agentic-commerce-engine' );
		}
		$ts = strtotime( $last . ' UTC' );
		if ( ! $ts ) {
			return __( 'Never', 'agentic-commerce-engine' );
		}
		return sprintf(
			/* translators: %s: human-readable time difference. */
			__( '%s ago', 'agentic-commerce-engine' ),
			human_time_diff( $ts, time() )
		);
	}
}
