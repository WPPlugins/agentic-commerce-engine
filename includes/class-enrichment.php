<?php
/**
 * Drives the enrichment lifecycle: request -> poll -> ingest into the review queue.
 *
 * @package Agencoen
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Enrichment
 */
class Enrichment {

	const POLL_HOOK = 'agencoen_poll_enrichment';

	/**
	 * Products per enrich request. Each one drives N x M contextual LLM calls on
	 * the platform inside a single serverless invocation, so we keep chunks small
	 * (well under the API's 100 cap) to finish within the function budget; the
	 * poller stitches the chunks back together for the caller.
	 */
	const ENRICH_CHUNK = 20;

	/** @var Plugin */
	private $plugin;

	/**
	 * @param Plugin $plugin Orchestrator.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Sync then queue a set of products for enrichment.
	 *
	 * @param int[] $ids     Product ids.
	 * @param array $options Enrichment options.
	 * @return array|\WP_Error { jobIds, count }
	 */
	public function enrich_ids( array $ids, array $options = array() ) {
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) ) {
			return new \WP_Error( 'agencoen_no_products', __( 'No products selected.', 'agentic-commerce-engine' ) );
		}

		// The platform enriches from its synced copy, so push the latest first.
		$synced = $this->plugin->sync()->sync_ids( $ids );
		if ( is_wp_error( $synced ) ) {
			return $synced;
		}

		$settings = $this->plugin->settings();
		$store_id = $settings->get_store_id();
		$job_ids  = array();

		foreach ( array_chunk( $ids, self::ENRICH_CHUNK ) as $chunk ) {
			$result = $this->plugin->api()->enrich( $store_id, $chunk, $options );
			if ( is_wp_error( $result ) ) {
				// Roll the just-queued chunk back so it doesn't look stuck.
				$this->reset_to_not_enriched( $chunk );
				return $result;
			}

			// v4 unified jobs return the envelope's `id`; tolerate legacy `jobId`.
			$job_id = isset( $result['id'] ) ? (string) $result['id'] : ( isset( $result['jobId'] ) ? (string) $result['jobId'] : '' );
			if ( '' === $job_id ) {
				$this->reset_to_not_enriched( $chunk );
				return new \WP_Error( 'agencoen_no_job', __( 'The platform did not return a job id.', 'agentic-commerce-engine' ) );
			}
			$job_ids[] = $job_id;

			foreach ( $chunk as $id ) {
				update_post_meta( $id, Meta::STATUS, Meta::STATUS_QUEUED );
				update_post_meta( $id, Meta::JOB_ID, $job_id );
				delete_post_meta( $id, Meta::PENDING );
			}

			$this->schedule_poll( $job_id, 30 );
		}

		$this->plugin->repository()->bust_counts_cache();

		return array(
			'jobIds' => $job_ids,
			'count'  => count( $ids ),
		);
	}

	/**
	 * Ingest normalized results from the status endpoint into the review queue.
	 *
	 * ONCE PER PRODUCT, AND ONLY INTO A PRODUCT STILL WAITING ON THIS JOB
	 * A finished job's results stay readable on the platform for as long as the
	 * job exists, and two paths read them: `Ajax::poll_status()` drives the
	 * poller inline while the Products screen is open, and the WP-Cron event
	 * `schedule_poll()` armed keeps its own schedule. Until this guard existed,
	 * whichever ran second ingested the job again. On a real store a
	 * product was enriched, approved and applied, then a storefront page load
	 * fired the stale cron poll and the applied product reappeared as pending in
	 * Review. With auto-apply on, the same replay would apply it a second time.
	 *
	 * So a result lands only on a product that is still STATUS_QUEUED and whose
	 * Meta::JOB_ID is the job being ingested. Anything else was decided already
	 * (enriched, applied, dismissed, reverted, failed) or has been asked for
	 * again under a newer job whose results must not be overwritten by these.
	 *
	 * The status write is a compare-and-swap (`$prev_value`), because the read
	 * above it cannot stop two polls that are running at the same moment. Only
	 * the poll whose swap lands tells Review_Sync and auto-applies; the loser has
	 * at most rewritten the same job's payload over itself.
	 *
	 * @param array  $products Array of { id, status, enrichedData, contentTypes, enrichedAt }.
	 * @param string $job_id   The job these results belong to. Optional only so
	 *                         an older caller keeps working; without it the
	 *                         product must still be queued, but its job is not
	 *                         checked.
	 * @return int Number ingested as ready-for-review.
	 */
	public function ingest_results( array $products, $job_id = '' ) {
		$ingested   = 0;
		$auto_apply = $this->plugin->settings()->is_auto_apply();
		$job_id     = (string) $job_id;

		foreach ( $products as $product ) {
			if ( ! is_array( $product ) ) {
				continue;
			}
			$id     = isset( $product['id'] ) ? (int) $product['id'] : 0;
			$status = isset( $product['status'] ) ? (string) $product['status'] : '';
			if ( ! $id || 'enriched' !== $status ) {
				continue; // 'pending' items stay queued for the next poll.
			}

			if ( ! $this->awaits_results( $id, $job_id ) ) {
				continue; // Already ingested, decided, or re-queued under a newer job.
			}

			$fields  = $this->normalize_enriched( isset( $product['enrichedData'] ) ? (array) $product['enrichedData'] : array() );
			$content = ( isset( $product['contentTypes'] ) && is_array( $product['contentTypes'] ) ) ? $product['contentTypes'] : array();
			if ( empty( $fields ) && empty( $content ) ) {
				continue;
			}

			update_post_meta( $id, Meta::PENDING, $fields );
			update_post_meta( $id, Meta::CONTENT, $content );
			// Scores cached for the previous proposal describe content that is
			// being replaced, so they must not be shown against this one. The
			// next read fetches whatever Rezolve Ai has measured for the new copy.
			Quality::forget( $id );
			update_post_meta( $id, Meta::ENRICHED_AT, isset( $product['enrichedAt'] ) ? (string) $product['enrichedAt'] : gmdate( 'c' ) );
			// Status LAST, so a request that dies part way leaves the product
			// queued and the next poll ingests it, rather than an enriched
			// product with nothing to review.
			if ( ! update_post_meta( $id, Meta::STATUS, Meta::STATUS_ENRICHED, Meta::STATUS_QUEUED ) ) {
				continue; // Another poll claimed this product first.
			}
			// A new proposal is a state Rezolve Ai should know about too, so its
			// queue and this one show the same work.
			$this->plugin->review_sync()->mark_dirty( $id, 'enrich' );
			++$ingested;

			if ( $auto_apply ) {
				$this->plugin->apply()->apply( $id );
			}
		}

		if ( $ingested > 0 ) {
			$this->plugin->repository()->bust_counts_cache();
		}
		return $ingested;
	}

	/**
	 * Whether a product is still waiting on results, from this job if one is named.
	 *
	 * @param int    $id     Product id.
	 * @param string $job_id Job id, or '' to check the status alone.
	 * @return bool
	 */
	private function awaits_results( $id, $job_id ) {
		if ( Meta::STATUS_QUEUED !== get_post_meta( $id, Meta::STATUS, true ) ) {
			return false;
		}
		return '' === $job_id || (string) get_post_meta( $id, Meta::JOB_ID, true ) === $job_id;
	}

	/**
	 * Mark a job's still-queued products as failed.
	 *
	 * @param string $job_id  Job id.
	 * @param string $message Error message.
	 */
	public function mark_job_failed( $job_id, $message = '' ) {
		foreach ( $this->products_for_job( $job_id ) as $id ) {
			if ( Meta::STATUS_QUEUED === get_post_meta( $id, Meta::STATUS, true ) ) {
				update_post_meta( $id, Meta::STATUS, Meta::STATUS_FAILED );
			}
		}
		if ( $message ) {
			$this->plugin->logger()->error( "Enrichment job {$job_id} failed: {$message}" );
		}
		$this->plugin->repository()->bust_counts_cache();
	}

	/**
	 * Product ids attached to a job.
	 *
	 * @param string $job_id Job id.
	 * @return int[]
	 */
	public function products_for_job( $job_id ) {
		$query = new \WP_Query(
			array(
				'post_type'              => 'product',
				'post_status'            => 'any',
				'posts_per_page'         => 200,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => Meta::JOB_ID,
						'value' => (string) $job_id,
					),
				),
			)
		);
		return array_map( 'intval', $query->posts );
	}

	/**
	 * Schedule a status poll for a job (idempotent per job).
	 *
	 * A job the poller has already finished is not scheduled again. The
	 * platform's completion webhook can land after the Products screen has
	 * already ingested the job inline, and an event armed then would only wake
	 * up to find nothing to do.
	 *
	 * @param string $job_id Job id.
	 * @param int    $delay  Seconds from now.
	 */
	public function schedule_poll( $job_id, $delay = 30 ) {
		$job_id = (string) $job_id;
		if ( Poller::is_finished( $job_id ) ) {
			return;
		}
		if ( ! wp_next_scheduled( self::POLL_HOOK, array( $job_id ) ) ) {
			wp_schedule_single_event( time() + (int) $delay, self::POLL_HOOK, array( $job_id ) );
		}
	}

	/**
	 * Clear enrichment bookkeeping so products show as "not enriched".
	 *
	 * @param int[] $ids Product ids.
	 */
	private function reset_to_not_enriched( array $ids ) {
		foreach ( $ids as $id ) {
			if ( Meta::STATUS_QUEUED === get_post_meta( $id, Meta::STATUS, true ) ) {
				delete_post_meta( $id, Meta::STATUS );
				delete_post_meta( $id, Meta::JOB_ID );
			}
		}
	}

	/**
	 * Map a raw/normalized record onto the five canonical fields, trimming empties.
	 *
	 * @param array $data Source.
	 * @return array<string,string>
	 */
	private function normalize_enriched( array $data ) {
		$pick = function ( array $keys ) use ( $data ) {
			foreach ( $keys as $k ) {
				if ( isset( $data[ $k ] ) && is_string( $data[ $k ] ) && '' !== $data[ $k ] ) {
					return $data[ $k ];
				}
			}
			return null;
		};

		$map = array(
			'title'             => array( 'title', 'name', 'product_title', 'optimized_title' ),
			'description'       => array( 'description', 'product_description', 'optimized_description', 'long_description' ),
			'short_description' => array( 'short_description', 'excerpt', 'summary', 'optimized_short_description' ),
			'seo_title'         => array( 'seo_title', 'meta_title', 'optimized_seo_title' ),
			'seo_description'   => array( 'seo_description', 'meta_description', 'optimized_seo_description' ),
		);

		$out = array();
		foreach ( $map as $field => $keys ) {
			$value = $pick( $keys );
			if ( null !== $value ) {
				$out[ $field ] = $value;
			}
		}
		return $out;
	}
}
