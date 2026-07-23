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
	 * @param array $products Array of { id, status, enrichedData, score, enrichedAt }.
	 * @return int Number ingested as ready-for-review.
	 */
	public function ingest_results( array $products ) {
		$ingested   = 0;
		$auto_apply = $this->plugin->settings()->is_auto_apply();

		foreach ( $products as $product ) {
			if ( ! is_array( $product ) ) {
				continue;
			}
			$id     = isset( $product['id'] ) ? (int) $product['id'] : 0;
			$status = isset( $product['status'] ) ? (string) $product['status'] : '';
			if ( ! $id || 'enriched' !== $status ) {
				continue; // 'pending' items stay queued for the next poll.
			}

			$fields  = $this->normalize_enriched( isset( $product['enrichedData'] ) ? (array) $product['enrichedData'] : array() );
			$content = ( isset( $product['contentTypes'] ) && is_array( $product['contentTypes'] ) ) ? $product['contentTypes'] : array();
			if ( empty( $fields ) && empty( $content ) ) {
				continue;
			}

			update_post_meta( $id, Meta::PENDING, $fields );
			update_post_meta( $id, Meta::CONTENT, $content );
			update_post_meta( $id, Meta::SCORE, isset( $product['score'] ) ? $product['score'] : '' );
			update_post_meta( $id, Meta::ENRICHED_AT, isset( $product['enrichedAt'] ) ? (string) $product['enrichedAt'] : gmdate( 'c' ) );
			update_post_meta( $id, Meta::STATUS, Meta::STATUS_ENRICHED );
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
	 * @param string $job_id Job id.
	 * @param int    $delay  Seconds from now.
	 */
	public function schedule_poll( $job_id, $delay = 30 ) {
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
