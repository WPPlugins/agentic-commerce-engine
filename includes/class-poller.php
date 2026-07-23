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
	 * @param string $job_id Job id.
	 */
	public function poll( $job_id ) {
		$job_id   = (string) $job_id;
		$settings = $this->plugin->settings();
		if ( '' === $job_id || ! $settings->is_connected() ) {
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
				$this->ingest_job( $job_id );
				$this->clear_attempts( $job_id );
				if ( 'partial' === $status ) {
					// Whatever did not come back enriched is failed, not pending.
					$this->plugin->enrichment()->mark_job_failed( $job_id, __( 'Some products failed to enrich.', 'agentic-commerce-engine' ) );
				}
				break;

			case 'failed':
			case 'cancelled':
				$this->clear_attempts( $job_id );
				$message = isset( $job['error']['message'] ) ? (string) $job['error']['message'] : '';
				$this->plugin->enrichment()->mark_job_failed( $job_id, $message );
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
	 * @param string $job_id Job id.
	 */
	private function ingest_job( $job_id ) {
		$page = 1;
		do {
			$batch = $this->plugin->api()->job_results( $job_id, $page, self::RESULTS_PAGE );
			if ( is_wp_error( $batch ) ) {
				$this->plugin->logger()->error( "Results fetch failed for job {$job_id}: " . $batch->get_error_message() );
				return;
			}
			$rows = isset( $batch['items'] ) && is_array( $batch['items'] ) ? $batch['items'] : array();
			if ( empty( $rows ) ) {
				return;
			}

			$this->plugin->enrichment()->ingest_results( self::map_job_results( $rows ) );

			$meta       = isset( $batch['_meta']['pagination'] ) ? $batch['_meta']['pagination'] : array();
			$total_page = isset( $meta['totalPages'] ) ? (int) $meta['totalPages'] : 1;
			++$page;
		} while ( $page <= $total_page && $page <= 50 );
	}

	/* --------------------------------------------------------------------- */
	/* Pure mapping (unit-tested directly)                                   */
	/* --------------------------------------------------------------------- */

	/**
	 * Map unified-job result rows ({ productId, content: {type: value} }) into
	 * the ingest shape ({ id, status, enrichedData, contentTypes, ... }).
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
				'score'        => 90,
				'enrichedAt'   => gmdate( 'c' ),
			);
		}
		return $out;
	}

	/**
	 * Derive the flat review-diff fields from per-content-type results. Mirrors
	 * the platform's deriveFlatFields: product-description feeds description +
	 * a 160-char short description; meta-tags feeds seo_title/seo_description.
	 *
	 * @param array $per_type Per-content-type payloads.
	 * @return array<string,string>
	 */
	public static function derive_flat_fields( array $per_type ) {
		$flat = array();

		$pd = isset( $per_type['product-description'] ) && is_array( $per_type['product-description'] )
			? $per_type['product-description']
			: array();
		if ( isset( $pd['description'] ) && is_string( $pd['description'] ) && '' !== $pd['description'] ) {
			$flat['description'] = $pd['description'];
			$text                = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $pd['description'] ) ) );
			if ( function_exists( 'mb_substr' ) && function_exists( 'mb_strlen' ) ) {
				$flat['short_description'] = mb_strlen( $text ) > 160 ? mb_substr( $text, 0, 157 ) . '…' : $text;
			} else {
				$flat['short_description'] = strlen( $text ) > 160 ? substr( $text, 0, 157 ) . '…' : $text;
			}
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
			$this->clear_attempts( $job_id );
			$this->plugin->enrichment()->mark_job_failed(
				$job_id,
				$message ? $message : __( 'Enrichment timed out.', 'agentic-commerce-engine' )
			);
			return;
		}

		set_transient( $key, $attempts, HOUR_IN_SECONDS );
		$this->plugin->enrichment()->schedule_poll( $job_id, self::INTERVAL );
	}

	/**
	 * @param string $job_id Job id.
	 */
	private function clear_attempts( $job_id ) {
		delete_transient( 'agencoen_poll_' . md5( $job_id ) );
	}
}
