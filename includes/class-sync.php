<?php
/**
 * Pushes WooCommerce products to the ACE platform in batches of <= maxBatchSize.
 *
 * Manual "Sync now" runs the first page inline (instant feedback) and, when the
 * catalog spans more pages, hands the rest to a self-rescheduling cron event so
 * large catalogs never hit max_execution_time.
 *
 * @package Agencoen
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Sync
 */
class Sync {

	const CONTINUE_HOOK = 'agencoen_sync_continue';

	/** @var Plugin */
	private $plugin;

	/**
	 * @param Plugin $plugin Orchestrator.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Sync a specific set of product ids (used before enrichment). Chunks to the
	 * server batch ceiling. Returns aggregate totals or the first error.
	 *
	 * @param int[] $ids Product ids.
	 * @return array|\WP_Error
	 */
	public function sync_ids( array $ids ) {
		$settings = $this->plugin->settings();
		$per      = $settings->get_max_batch_size();
		$totals   = array(
			'received' => 0,
			'created'  => 0,
			'updated'  => 0,
		);

		foreach ( array_chunk( array_values( array_unique( array_map( 'intval', $ids ) ) ), $per ) as $chunk ) {
			$payloads = $this->serialize_ids( $chunk );
			if ( empty( $payloads ) ) {
				continue;
			}
			$result = $this->plugin->api()->sync_products( $settings->get_store_id(), $payloads );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$totals['received'] += (int) ( $result['productsReceived'] ?? 0 );
			$totals['created']  += (int) ( $result['productsCreated'] ?? 0 );
			$totals['updated']  += (int) ( $result['productsUpdated'] ?? 0 );
		}

		$settings->touch_last_sync();
		return $totals;
	}

	/**
	 * Sync a single page of the catalog.
	 *
	 * @param int $page 1-based page.
	 * @param int $per  Page size.
	 * @return array|\WP_Error { received, created, updated, in_page }
	 */
	public function sync_page( $page, $per ) {
		$ids = $this->plugin->repository()->paged_ids( $page, $per );
		if ( empty( $ids ) ) {
			return array(
				'received' => 0,
				'created'  => 0,
				'updated'  => 0,
				'in_page'  => 0,
			);
		}

		$payloads = $this->serialize_ids( $ids );
		$result   = $this->plugin->api()->sync_products( $this->plugin->settings()->get_store_id(), $payloads );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'received' => (int) ( $result['productsReceived'] ?? 0 ),
			'created'  => (int) ( $result['productsCreated'] ?? 0 ),
			'updated'  => (int) ( $result['productsUpdated'] ?? 0 ),
			'in_page'  => count( $ids ),
		);
	}

	/**
	 * Start a full catalog sync: page 1 inline, rest via cron.
	 *
	 * @return array|\WP_Error { received, created, updated, total, done }
	 */
	public function kickoff_full_sync() {
		$settings = $this->plugin->settings();
		$per      = $settings->get_max_batch_size();

		$settings->set_sync_cursor( 1 );
		$result = $this->sync_page( 1, $per );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$total = $this->plugin->repository()->count_published();
		$done  = $result['in_page'] < $per;

		if ( $done ) {
			$this->finalize();
		} else {
			$settings->set_sync_cursor( 2 );
			$this->schedule_continue();
		}

		$this->plugin->repository()->bust_counts_cache();

		return array(
			'received' => $result['received'],
			'created'  => $result['created'],
			'updated'  => $result['updated'],
			'total'    => $total,
			'done'     => $done,
		);
	}

	/**
	 * Cron continuation: sync the cursor page, then advance or finalize.
	 */
	public function continue_sync() {
		$settings = $this->plugin->settings();
		$per      = $settings->get_max_batch_size();
		$page     = max( 1, $settings->get_sync_cursor() );

		$result = $this->sync_page( $page, $per );
		if ( is_wp_error( $result ) ) {
			// Back off and retry the same page shortly.
			$this->plugin->logger()->error( 'Sync continuation failed: ' . $result->get_error_message() );
			$this->schedule_continue( 5 * MINUTE_IN_SECONDS );
			return;
		}

		if ( $result['in_page'] < $per ) {
			$this->finalize();
		} else {
			$settings->set_sync_cursor( $page + 1 );
			$this->schedule_continue();
		}
		$this->plugin->repository()->bust_counts_cache();
	}

	/**
	 * Stamp completion and reset the cursor.
	 */
	private function finalize() {
		$this->plugin->settings()->touch_last_sync();
		$this->plugin->settings()->set_sync_cursor( 0 );
	}

	/**
	 * Schedule the next continuation tick (idempotent).
	 *
	 * @param int $delay Seconds from now.
	 */
	private function schedule_continue( $delay = 20 ) {
		if ( ! wp_next_scheduled( self::CONTINUE_HOOK ) ) {
			wp_schedule_single_event( time() + (int) $delay, self::CONTINUE_HOOK );
		}
	}

	/**
	 * Hydrate + serialize a list of ids, skipping anything that no longer loads.
	 *
	 * @param int[] $ids Product ids.
	 * @return array
	 */
	private function serialize_ids( array $ids ) {
		$serializer = $this->plugin->serializer();
		$payloads   = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( $product ) {
				$payloads[] = $serializer->to_sync_payload( $product );
			}
		}
		return $payloads;
	}
}
