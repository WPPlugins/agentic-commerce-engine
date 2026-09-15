<?php
/**
 * Polls the platform for enrichment job results via WP-Cron single events.
 *
 * v4 speaks the unified jobs API: GET /api/v1/jobs/{id} for status and
 * GET /api/v1/jobs/{id}/results for the per-product payloads, which are
 * mapped back into the v3-era ingest shape (enrichedData + contentTypes) so
 * the review queue, diffs, and apply pipeline carry over unchanged.
 *
 * @package Agencoen
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Poller
 */
class Poller {

	const INTERVAL     = 30;
	const MAX_POLLS    = 40;  // ~20 minutes at 30s.
	const RESULTS_PAGE = 100; // Per results-page fetch.

	/** @var Plugin */
	private $plugin;

	/**
	 * @param Plugin $plugin Orchestrator.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Register the cron callback.
	 */
	public function register() {
		add_action( Enrichment::POLL_HOOK, array( $this, 'poll' ), 10, 1 );
	}

	/**
	 * Poll one job. Reschedules itself until a terminal state or the cap.
	 *
	 * Two callers reach this for the same job: the WP-Cron event and
	 * `Ajax::poll_status()`, which drives it inline while the Products screen
	 * is open. Whichever finishes the job first records that (see `finish()`),
	 * so the other, and any cron event still armed, returns here without
	 * reading the results again. `Enrichment::ingest_results()` refuses a
	 * second ingest per product on its own; this stops the wasted round trips
	 * and the polling itself.
	 *
	 * @param string $job_id Job id.
	 */
	public function poll( $job_id ) {
		$job_id   = (string) $job_id;
		$settings = $this->plugin->settings();
		if ( '' === $job_id || ! $settings->is_connected() ) {
			return;
		}

		if ( self::is_finished( $job_id ) ) {
			wp_clear_scheduled_hook( Enrichment::POLL_HOOK, array( $job_id ) );
			return;
		}

		$job = $this->plugin->api()->job( $job_id );

		if ( is_wp_error( $job ) ) {
			$this->reschedule_or_give_up( $job_id, $job->get_error_message() );
			return;
		}

		$status = isset( $job['status'] ) ? (string) $job['status'] : '';

		switch ( $status ) {
			case 'completed':
			case 'partial':
				if ( ! $this->ingest_job( $job_id ) ) {
					// The results could not be read, so nothing below may run: a
					// partial job would mark every product that is only waiting on
					// this page as failed, and finishing would leave the rest queued
					// with no poll left to collect them. Try again instead.
					$this->reschedule_or_give_up( $job_id );
					return;
				}
				if ( 'partial' === $status ) {
					// Whatever did not come back enriched is failed, not pending.
					$this->plugin->enrichment()->mark_job_failed( $job_id, __( 'Some products failed to enrich.', 'agentic-commerce-engine' ) );
				}
				$this->finish( $job_id );
				break;

			case 'failed':
			case 'cancelled':
				$message = isset( $job['error']['message'] ) ? (string) $job['error']['message'] : '';
				$this->plugin->enrichment()->mark_job_failed( $job_id, $message );
				$this->finish( $job_id );
				break;

			case 'pending':
			case 'running':
			default:
				$this->reschedule_or_give_up( $job_id );
				break;
		}
	}

	/**
	 * Pull every results page for a finished job and hand the mapped products
	 * to the enrichment ingester.
	 *
	 * A page that fails part way is safe to retry from page one: the pages
	 * already read have moved their products out of STATUS_QUEUED, and the
	 * ingester skips those.
	 *
	 * @param string $job_id Job id.
	 * @return bool False when a results page could not be read.
	 */
	private function ingest_job( $job_id ) {
		$page = 1;
		do {
			$batch = $this->plugin->api()->job_results( $job_id, $page, self::RESULTS_PAGE );
			if ( is_wp_error( $batch ) ) {
				$this->plugin->logger()->error( "Results fetch failed for job {$job_id}: " . $batch->get_error_message() );
				return false;
			}
			$rows = isset( $batch['items'] ) && is_array( $batch['items'] ) ? $batch['items'] : array();
			if ( empty( $rows ) ) {
				return true;
			}

			$this->plugin->enrichment()->ingest_results( self::map_job_results( $rows ), $job_id );

			$meta       = isset( $batch['_meta']['pagination'] ) ? $batch['_meta']['pagination'] : array();
			$total_page = isset( $meta['totalPages'] ) ? (int) $meta['totalPages'] : 1;
			++$page;
		} while ( $page <= $total_page && $page <= 50 );

		return true;
	}

	/* --------------------------------------------------------------------- */
	/* Pure mapping (unit-tested directly)                                   */
	/* --------------------------------------------------------------------- */

	/**
	 * Map unified-job result rows ({ productId, content: {type: value} }) into
	 * the ingest shape ({ id, status, enrichedData, contentTypes, ... }).
	 *
	 * There is deliberately no `score`. Until 4.17.0 this set it to a literal 90
	 * for every row, and that number was shown to merchants as a quality score
	 * although nothing had measured anything. Quality now comes from Rezolve
	 * Ai's own scoring, read by {@see Quality}, and is absent until it exists.
	 *
	 * @param array $rows Result rows from /api/v1/jobs/{id}/results.
	 * @return array
	 */
	public static function map_job_results( array $rows ) {
		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$pid = isset( $row['productId'] ) ? (string) $row['productId'] : ( isset( $row['id'] ) ? (string) $row['id'] : '' );
			if ( '' === $pid ) {
				continue;
			}
			$per_type = isset( $row['content'] ) && is_array( $row['content'] ) ? $row['content'] : array();
			if ( empty( $per_type ) ) {
				continue;
			}
			$out[] = array(
				'id'           => (int) $pid,
				'status'       => 'enriched',
				'enrichedData' => self::derive_flat_fields( $per_type ),
				'contentTypes' => $per_type,
				'enrichedAt'   => gmdate( 'c' ),
			);
		}
		return $out;
	}

	/**
	 * Shorten prose to fit a length, without cutting a word in half.
	 *
	 * `post_excerpt` is shown on the live product page, so a hard character
	 * slice is visible to shoppers: a real store came back reading "...outdoor
	 * enthusiasts who deman…". Backing up to the last space before the limit
	 * costs a few characters and never produces a fragment. A single word
	 * longer than the limit has no space to back up to, so it still gets the
	 * hard cut rather than an empty string.
	 *
	 * @param string $text  Plain text, already whitespace-collapsed.
	 * @param int    $limit Maximum length including the ellipsis.
	 * @return string
	 */
	private static function shorten( $text, $limit ) {
		$mb  = function_exists( 'mb_substr' ) && function_exists( 'mb_strlen' );
		$len = $mb ? mb_strlen( $text ) : strlen( $text );
		if ( $len <= $limit ) {
			return $text;
		}
		$room = $limit - 1; // The ellipsis is one character.
		$cut  = $mb ? mb_substr( $text, 0, $room ) : substr( $text, 0, $room );
		$space = $mb ? mb_strrpos( $cut, ' ' ) : strrpos( $cut, ' ' );
		if ( false !== $space && $space > 0 ) {
			$cut = $mb ? mb_substr( $cut, 0, $space ) : substr( $cut, 0, $space );
		}
		return rtrim( $cut, " \t\n\r\0\x0B,;:." ) . '…';
	}

	/**
	 * The short description derived from a description.
	 *
	 * Public since 4.18.0 so an edited description on the Review screen derives
	 * its short description exactly the way an ingested one does.
	 *
	 * @param string $description Description HTML.
	 * @return string
	 */
	public static function short_description_for( $description ) {
		$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $description ) ) );
		return self::shorten( $text, 160 );
	}

	/**
	 * Derive the flat review-diff fields from per-content-type results.
	 *
	 * `product-description` feeds description and a short description;
	 * `meta-tags` feeds seo_title/seo_description.
	 *
	 * NOTE: the platform's `product-description` payload carries `description`,
	 * `cta`, `attributes` and `specifications` -- there is no short description
	 * in it, so this derives one. `Enrichment::normalize_enriched` still
	 * accepts a `short_description` alias for the day one is generated, and it
	 * takes precedence over anything derived here.
	 *
	 * @param array $per_type Per-content-type payloads.
	 * @return array<string,string>
	 */
	public static function derive_flat_fields( array $per_type ) {
		$flat = array();

		// `product-title` feeds the product name. Before 4.16.0 nothing here read
		// it, so `title` was listed as a reviewable field and `Apply` had a
		// set_name() path that nothing ever filled. The generator returns
		// { title, shortTitle, ... }; `title` is the product name, `shortTitle` is
		// a feed-only variant and is deliberately not used.
		$pt    = isset( $per_type['product-title'] ) ? $per_type['product-title'] : null;
		$title = is_array( $pt ) && isset( $pt['title'] ) ? $pt['title'] : $pt;
		if ( is_string( $title ) && '' !== trim( $title ) ) {
			$flat['title'] = trim( $title );
		}

		$pd = isset( $per_type['product-description'] ) && is_array( $per_type['product-description'] )
			? $per_type['product-description']
			: array();
		if ( isset( $pd['description'] ) && is_string( $pd['description'] ) && '' !== $pd['description'] ) {
			$flat['description']       = $pd['description'];
			$flat['short_description'] = self::short_description_for( $pd['description'] );
		}

		$meta = array();
		if ( isset( $per_type['meta-tags'] ) && is_array( $per_type['meta-tags'] ) ) {
			$meta = $per_type['meta-tags'];
		} elseif ( isset( $per_type['meta-optimization'] ) && is_array( $per_type['meta-optimization'] ) ) {
			$meta = $per_type['meta-optimization'];
		}
		if ( isset( $meta['title'] ) && is_string( $meta['title'] ) && '' !== $meta['title'] ) {
			$flat['seo_title'] = $meta['title'];
		}
		if ( isset( $meta['description'] ) && is_string( $meta['description'] ) && '' !== $meta['description'] ) {
			$flat['seo_description'] = $meta['description'];
		}

		// The `woocommerce` type carries SEO too, and a merchant may well select
		// it without also selecting meta tags. LAST in precedence, and only for
		// a field still empty: when both ran, Rezolve Ai folded the meta-tags output
		// onto the feed record before enriching it, so the two agree, and
		// preferring the dedicated type keeps one obvious source of truth.
		//
		// Note the key names differ. meta-tags uses title/description; the
		// woocommerce payload uses the feed spelling.
		$woo = isset( $per_type['woocommerce'] ) && is_array( $per_type['woocommerce'] )
			? $per_type['woocommerce']
			: array();
		if ( ! isset( $flat['seo_title'] ) && isset( $woo['seo_title'] ) && is_string( $woo['seo_title'] ) && '' !== $woo['seo_title'] ) {
			$flat['seo_title'] = $woo['seo_title'];
		}
		if ( ! isset( $flat['seo_description'] ) && isset( $woo['seo_description'] ) && is_string( $woo['seo_description'] ) && '' !== $woo['seo_description'] ) {
			$flat['seo_description'] = $woo['seo_description'];
		}

		return $flat;
	}

	/**
	 * Bump the attempt counter and either reschedule or mark the job failed.
	 *
	 * @param string $job_id  Job id.
	 * @param string $message Optional transport error to log.
	 */
	private function reschedule_or_give_up( $job_id, $message = '' ) {
		$key      = 'agencoen_poll_' . md5( $job_id );
		$attempts = (int) get_transient( $key );
		++$attempts;

		if ( $attempts >= self::MAX_POLLS ) {
			$this->plugin->enrichment()->mark_job_failed(
				$job_id,
				$message ? $message : __( 'Enrichment timed out.', 'agentic-commerce-engine' )
			);
			$this->finish( $job_id );
			return;
		}

		set_transient( $key, $attempts, HOUR_IN_SECONDS );
		$this->plugin->enrichment()->schedule_poll( $job_id, self::INTERVAL );
	}

	/**
	 * Whether a job has already reached a terminal state and been handled.
	 *
	 * @param string $job_id Job id.
	 * @return bool
	 */
	public static function is_finished( $job_id ) {
		return false !== get_transient( self::finished_key( (string) $job_id ) );
	}

	/**
	 * Record that a job is done and stop polling it.
	 *
	 * The marker is a transient rather than an option so it cannot pile up one
	 * row per job forever: a day comfortably outlives any poll that was already
	 * in flight when the job finished, and the armed event is cleared here
	 * anyway. It is an optimisation, not the guarantee. If it expires or an
	 * object cache evicts it early, a late poll reads the results again and
	 * `Enrichment::ingest_results()` still ingests nothing twice.
	 *
	 * @param string $job_id Job id.
	 */
	private function finish( $job_id ) {
		$this->clear_attempts( $job_id );
		set_transient( self::finished_key( $job_id ), 1, DAY_IN_SECONDS );
		// schedule_poll() passes the job id as the only arg, and WP-Cron matches
		// events on their args, so this must be the same one-element array.
		wp_clear_scheduled_hook( Enrichment::POLL_HOOK, array( (string) $job_id ) );
	}

	/**
	 * @param string $job_id Job id.
	 * @return string
	 */
	private static function finished_key( $job_id ) {
		return 'agencoen_poll_done_' . md5( $job_id );
	}

	/**
	 * @param string $job_id Job id.
	 */
	private function clear_attempts( $job_id ) {
		delete_transient( 'agencoen_poll_' . md5( $job_id ) );
	}
}
