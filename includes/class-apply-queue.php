<?php
/**
 * Applying content ACE approved, and telling ACE it landed.
 *
 * WHY THIS EXISTS, AND WHAT ITS ABSENCE COST
 * This plugin has always had its own review and apply flow: enrichment results
 * arrive from `/api/v1/jobs/{id}/results`, land in post meta, and `Apply`
 * writes them onto the product. Entirely local. ACE was never told.
 *
 * That was survivable while the only question was "is the content live", which
 * the site can answer for itself. It stopped being survivable when per-visitor
 * A/B shipped in 4.5.0, because on the ACE side a version counts as live only
 * when its review row is `approved` AND carries a `pushed_at` -- and nothing
 * here ever set one. So `GET /api/v1/plugin/experiments` returned an empty
 * config set on every poll, for every site, forever, and the challenger-copy
 * endpoint would have refused even if a config had reached the page. The
 * feature worked perfectly and measured nothing.
 *
 * The Shopify app has had this loop from the start: pull the queue, apply, ack.
 * This is the same contract, against the same two endpoints, because the
 * difference between the platforms is in the WRITE and not in the protocol.
 *
 * WHY IT PULLS RATHER THAN BEING PUSHED
 * Same reason `Ab_Test::sync_configs()` polls. A site offline for a week
 * catches up on its next tick rather than needing ACE to have retried, and a
 * site that never connects simply never asks.
 *
 * WHY IT DOES NOT GO THROUGH `Apply`
 * `Apply::apply()` reads `Meta::PENDING` -- content this site requested and is
 * holding. A queue entry is the opposite direction: ACE has already decided,
 * and hands over one field at a time. Routing it through the local flow would
 * mean writing meta purely so it could be read back, and would make an ACE
 * approval indistinguishable from a local one in the audit trail.
 *
 * @package Agentic_Commerce_Engine
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Apply_Queue
 */
class Apply_Queue {

	/** Entries pulled per run. The queue is a working list, not an export. */
	const BATCH = 100;

	/** @var Plugin */
	private $plugin;

	/**
	 * @param Plugin $plugin Orchestrator.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Pull, apply, ack.
	 *
	 * A failed pull leaves everything alone: an unreachable ACE is not evidence
	 * that anything changed. A failed APPLY is reported as such and the entry
	 * stays queued, because the queue selects on `pushed_at IS NULL` and only a
	 * successful ack clears it.
	 *
	 * @return array{applied:int,failed:int,skipped:int}
	 */
	public function drain() {
		$settings = $this->plugin->settings();
		$empty    = array(
			'applied' => 0,
			'failed'  => 0,
			'skipped' => 0,
		);

		if ( ! $settings->is_connected() || ! $this->plugin->has_woo() ) {
			return $empty;
		}

		$store_id = $settings->get_store_id();
		$response = $this->plugin->api()->apply_queue( $store_id );
		if ( is_wp_error( $response ) ) {
			$this->plugin->logger()->log( 'apply-queue pull failed: ' . $response->get_error_message(), 'warning' );
			return $empty;
		}

		$items = isset( $response['items'] ) && is_array( $response['items'] ) ? $response['items'] : array();
		if ( empty( $items ) ) {
			return $empty;
		}

		$items   = array_slice( $items, 0, self::BATCH );
		$results = array();
		$counts  = $empty;

		foreach ( $items as $item ) {
			$id = isset( $item['id'] ) ? (string) $item['id'] : '';
			if ( '' === $id ) {
				continue;
			}

			$outcome = $this->apply_entry( $item );
			if ( true === $outcome ) {
				$results[] = array(
					'id'     => $id,
					'status' => 'applied',
				);
				$counts['applied']++;
				continue;
			}

			$results[] = array(
				'id'     => $id,
				'status' => 'failed',
				// Bounded: the ack schema caps this, and a stack trace in a
				// remote error field helps nobody.
				'error'  => substr( (string) $outcome, 0, 500 ),
			);
			$counts['failed']++;
		}

		if ( empty( $results ) ) {
			return $counts;
		}

		$ack = $this->plugin->api()->apply_queue_ack( $store_id, $results );
		if ( is_wp_error( $ack ) ) {
			// The content IS live; only the report failed. Saying so matters:
			// the next pull will return the same entries, this will apply them
			// again, and applying the same value twice is harmless. Silently
			// swallowing it would leave a merchant's Published screen empty
			// with no account of why.
			$this->plugin->logger()->error( 'apply-queue ack failed, content is live but ACE was not told: ' . $ack->get_error_message() );
			return $counts;
		}

		if ( isset( $ack['skipped'] ) && is_array( $ack['skipped'] ) ) {
			$counts['skipped'] = count( $ack['skipped'] );
		}

		$this->plugin->repository()->bust_counts_cache();
		Feeds::touch();

		return $counts;
	}

	/**
	 * Apply one entry.
	 *
	 * @param array $item Queue entry.
	 * @return true|string True, or a reason.
	 */
	private function apply_entry( array $item ) {
		$product_ref = isset( $item['productId'] ) ? (string) $item['productId'] : '';
		$field       = isset( $item['field'] ) ? (string) $item['field'] : '';
		$value       = isset( $item['value'] ) ? $item['value'] : null;

		if ( '' === $product_ref || '' === $field ) {
			return 'malformed entry';
		}

		// ONE resolution rule, shared with Ab_Test. A queue entry names a
		// product the way ACE knows it -- usually the merchant's own SKU -- and
		// a second SKU-to-post resolver here would drift from the one the A/B
		// config already uses, so a test could be configured on one post and
		// its content written to another.
		$post_id = $this->plugin->ab_test()->post_id_for_product( $product_ref );
		if ( ! $post_id ) {
			return 'no product matches ' . $product_ref;
		}

		// The A/B config is not product content and does not touch the product.
		if ( 'abTestConfig' === $field ) {
			$config = is_string( $value ) ? json_decode( $value, true ) : $value;
			if ( ! is_array( $config ) ) {
				return 'unreadable A/B config';
			}
			$this->plugin->ab_test()->set_config( $post_id, $config );
			return true;
		}

		if ( ! is_string( $value ) || '' === $value ) {
			return 'empty value for ' . $field;
		}

		switch ( $field ) {
			case 'seoTitle':
				$this->plugin->seo()->write_seo_title( $post_id, $value );
				return true;

			case 'seoDescription':
				$this->plugin->seo()->write_seo_description( $post_id, $value );
				return true;

			case 'title':
			case 'descriptionHtml':
				$product = wc_get_product( $post_id );
				if ( ! $product ) {
					return 'product not loadable';
				}
				if ( 'title' === $field ) {
					$product->set_name( sanitize_text_field( $value ) );
				} else {
					$product->set_description( wp_kses_post( $value ) );
				}
				$product->save();
				return true;
		}

		// An unknown field is not a failure to retry forever. A newer ACE may
		// send a field this version cannot write, and reporting it applied
		// would be a lie; reporting it failed would re-deliver it on every
		// tick until the site upgrades. Failed is the honest answer, and the
		// message says which field so the log names the version gap.
		return 'unsupported field: ' . $field;
	}
}
