<?php
/**
 * Telling Rezolve Ai what this site's merchant decided.
 *
 * WHAT WAS MISSING, AND WHAT IT COST
 * Everything between Rezolve Ai and this plugin ran one way. `Apply_Queue`
 * pulls what Rezolve Ai approved and acks whether the write landed, but a
 * merchant doing their reviewing HERE -- approving, dismissing, reverting on
 * the plugin's own Review screen -- had no way to tell Rezolve Ai anything.
 * Those decisions lived in post meta on this server and nowhere else, so the
 * web review queue went on listing the same products as still waiting. The
 * same split existed for the Shopify app and was measured there: one shop with
 * 41 decided proposals and zero rows in the web queue for its project.
 *
 * WHY IT PUSHES WHERE `Apply_Queue` PULLS
 * `Apply_Queue`'s header explains why it polls: a site offline for a week
 * catches up on its next tick rather than needing Rezolve Ai to have retried.
 * That argument does not transfer here, because Rezolve Ai cannot read
 * `wp_postmeta` on the merchant's own server -- there is nothing for it to
 * poll. The outbound analogue is what this follows: `Bot_Tracker::report_back`,
 * `Traffic_Tracker::report_back`, `Sales_Tracker::report_back`,
 * `Ab_Test::report_back`, all cron-driven, batched and offline-tolerant.
 *
 * WHY MARKING IS SEPARATE FROM SENDING
 * `mark_dirty()` is a single meta write and nothing else. A merchant clicking
 * Approve in wp-admin must never wait on an HTTP call to Rezolve Ai, and a
 * failed call must never make the click look like it failed. `drain()` does the
 * talking, on the hourly heartbeat, and a site that is offline simply reports
 * later.
 *
 * @package Agentic_Commerce_Engine
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Review_Sync
 */
class Review_Sync {

	/** Products per drain. The endpoint caps a report at 200 decisions. */
	const BATCH = 50;

	/**
	 * Attempts before the plugin stops retrying a product every hour.
	 *
	 * Not a give-up on the decision itself: the flag stays and the next version
	 * bump or a manual sync retries. It stops the log filling for a site whose
	 * key was revoked months ago.
	 */
	const MAX_ATTEMPTS = 8;

	/** @var Plugin */
	private $plugin;

	/**
	 * @param Plugin $plugin Orchestrator.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Flag a product as having something to tell Rezolve Ai.
	 *
	 * `$fields` exists for one case and it is not optional there: `dismiss()`
	 * DELETES the pending meta, so by the time the hourly drain runs there is
	 * nothing left to name the fields the merchant refused. The caller reads them
	 * first and hands them over; every other caller can leave it empty and let
	 * the drain derive them.
	 *
	 * @param int      $product_id Product id.
	 * @param string   $reason     One of enrich|approve|dismiss|revert|backfill.
	 * @param string[] $fields     Field names, when the caller is about to
	 *                             destroy the meta they would be read from.
	 */
	public function mark_dirty( $product_id, $reason, array $fields = array() ) {
		$product_id = (int) $product_id;
		if ( ! $product_id ) {
			return;
		}
		$flag = array(
			'reason' => (string) $reason,
			'at'     => gmdate( 'c' ),
		);
		if ( ! empty( $fields ) ) {
			$flag['fields'] = array_values( array_unique( array_map( 'strval', $fields ) ) );
		}
		update_post_meta( $product_id, Meta::SYNC_DIRTY, $flag );

		// Near-real-time for a site with wp-cron alive, hourly for everyone
		// else. Same self-scheduling trick as `Cron::auto_apply_sweep()`.
		if ( ! wp_next_scheduled( Cron::REVIEW_SYNC ) ) {
			wp_schedule_single_event( time() + 60, Cron::REVIEW_SYNC );
		}
	}

	/**
	 * Rezolve Ai's status vocabulary for a product's local state.
	 *
	 * `revert` is why `mark_dirty()` records a reason. `Apply::revert()` puts
	 * the product back to STATUS_ENRICHED, which is indistinguishable from a
	 * fresh proposal by status alone -- and reporting it as `pending` would put
	 * content the merchant just took down back in front of them as new.
	 *
	 * @param string $status Meta::STATUS value.
	 * @param string $reason Dirty reason.
	 * @return string|null Null when there is nothing to report.
	 */
	public static function remote_status( $status, $reason ) {
		if ( 'revert' === $reason ) {
			return 'reverted';
		}
		switch ( $status ) {
			case Meta::STATUS_ENRICHED:
				return 'pending';
			case Meta::STATUS_APPLIED:
				return 'applied';
			case Meta::STATUS_DISMISSED:
				return 'dismissed';
			case Meta::STATUS_FAILED:
				return 'failed';
			default:
				// STATUS_QUEUED is "asked for, nothing back yet". There is no
				// proposal to decide, so there is nothing to say.
				return null;
		}
	}

	/**
	 * The decisions to report for one product.
	 *
	 * Rezolve Ai keys its review rows by CONTENT TYPE and this plugin holds
	 * flat FIELDS, so the field names below are the shared vocabulary and are
	 * mirrored from `src/lib/review-field-mapping.ts` in the platform repo. PHP
	 * cannot import that file; `tests/test-runner.php` pins this list against
	 * it, and `src/lib/__tests__/review-field-mapping.test.ts` pins the other
	 * side, so a change to either fails a build rather than silently filing a
	 * merchant's decision against the wrong content.
	 *
	 * `short_description` is deliberately absent. It is a WooCommerce-only
	 * native field with no review content type of its own; the platform would
	 * report it as an unknown field, and `description` already carries the
	 * decision for that row.
	 *
	 * @param int      $product_id Product id.
	 * @param string   $reason     Dirty reason.
	 * @param string[] $known      Fields captured before the meta was destroyed.
	 * @return array List of { productId, field, status, decidedAt }.
	 */
	public function build_decisions( $product_id, $reason, array $known = array() ) {
		$product_id = (int) $product_id;
		$status     = (string) get_post_meta( $product_id, Meta::STATUS, true );
		$remote     = self::remote_status( $status, $reason );
		if ( null === $remote ) {
			return array();
		}

		// Fields the caller captured before deleting the meta they live in win
		// outright: re-deriving from an emptied `Meta::PENDING` would report
		// nothing and the dismissal would never reach Rezolve Ai.
		if ( ! empty( $known ) ) {
			$decisions = array();
			foreach ( $known as $field ) {
				$decisions[] = array(
					'productId' => (string) $product_id,
					'field'     => (string) $field,
					'status'    => $remote,
					'decidedAt' => gmdate( 'c' ),
				);
			}
			return $decisions;
		}

		$fields = self::proposal_fields( $product_id, $status );
		if ( empty( $fields ) ) {
			return array();
		}

		$decided_at = (string) get_post_meta( $product_id, Meta::APPLIED_AT, true );
		if ( '' === $decided_at ) {
			$decided_at = (string) get_post_meta( $product_id, Meta::ENRICHED_AT, true );
		}

		$decisions = array();
		foreach ( $fields as $field ) {
			$decision = array(
				'productId' => (string) $product_id,
				'field'     => $field,
				'status'    => $remote,
			);
			if ( '' !== $decided_at ) {
				$decision['decidedAt'] = $decided_at;
			}
			$decisions[] = $decision;
		}
		return $decisions;
	}

	/**
	 * The platform field names a product currently holds a proposal for.
	 *
	 * Shared by the decisions report above and by {@see Quality}, which asks
	 * Rezolve Ai for the scores of exactly these fields. One derivation, so the
	 * plugin can never report a decision about a field it does not ask the
	 * score of, or the other way round.
	 *
	 * @param int    $product_id Product id.
	 * @param string $status     Meta::STATUS value.
	 * @return string[] Unique field names, in a stable order.
	 */
	public static function proposal_fields( $product_id, $status ) {
		$product_id = (int) $product_id;
		$pending    = get_post_meta( $product_id, Meta::PENDING, true );
		$pending    = is_array( $pending ) ? $pending : array();
		$content    = get_post_meta( $product_id, Meta::CONTENT, true );
		$content    = is_array( $content ) ? $content : array();

		$fields = array();
		foreach ( array( 'title', 'description', 'seo_title', 'seo_description' ) as $field ) {
			if ( isset( $pending[ $field ] ) && '' !== $pending[ $field ] ) {
				$fields[] = $field;
			}
		}

		// Content types the plugin applies through `Apply::apply_content_types`.
		// Every spelling the platform has emitted is accepted, matching that
		// method and the Shopify app's `deriveProposals`.
		$from_content = array(
			'json_ld'            => array( 'jsonld-schema', 'json-ld-schema' ),
			'irl_cards'          => array( 'irl-scenarios', 'irl-cards' ),
			'internal_links'     => array( 'internal-links' ),
			'highlights'         => array( 'highlights' ),
			'product_qa'         => array( 'product-qa' ),
			'product_attributes' => array( 'product-attributes' ),
			'image_alt'          => array( 'image-alt' ),
		);
		foreach ( $from_content as $field => $keys ) {
			foreach ( $keys as $key ) {
				if ( isset( $content[ $key ] ) && ! empty( $content[ $key ] ) ) {
					$fields[] = $field;
					break;
				}
			}
		}

		// An APPLIED product whose pending meta was cleared still has a decision
		// worth reporting: the applied ledger says which types went live.
		if ( empty( $fields ) && Meta::STATUS_APPLIED === $status ) {
			$applied = get_post_meta( $product_id, Meta::APPLIED_TYPES, true );
			$applied = is_array( $applied ) ? $applied : array();
			$map     = array(
				'meta-tags'          => array( 'seo_title', 'seo_description' ),
				'jsonld-schema'      => array( 'json_ld' ),
				'json-ld-schema'     => array( 'json_ld' ),
				'irl-scenarios'      => array( 'irl_cards' ),
				'internal-links'     => array( 'internal_links' ),
				'product-title'      => array( 'title' ),
				'highlights'         => array( 'highlights' ),
				'product-qa'         => array( 'product_qa' ),
				'product-attributes' => array( 'product_attributes' ),
				'image-alt'          => array( 'image_alt' ),
			);
			// A plain LIST of type ids (Apply stores `array_keys( $applied_types )`).
			// This used to loop over `array_keys( $applied )`, i.e. 0, 1, 2, so
			// the map above never matched anything and an applied product whose
			// pending meta was cleared reported no decision at all.
			foreach ( $applied as $type ) {
				if ( isset( $map[ $type ] ) ) {
					$fields = array_merge( $fields, $map[ $type ] );
				}
			}
		}

		return array_values( array_unique( $fields ) );
	}

	/**
	 * The review-sync tick: report decisions, then refresh cached quality scores.
	 *
	 * One tick rather than a new cron event, because both are "keep this site's
	 * review state and Rezolve Ai's in step", both already ride the hourly
	 * heartbeat, and one scheduled event is one thing to keep alive. Decisions
	 * go first: a product whose decision just reached Rezolve Ai is one whose
	 * review row, and so whose score, now exists.
	 *
	 * @return array{reported:int,skipped:int,failed:int}
	 */
	public function run() {
		$counts = $this->drain();
		$this->refresh_quality();
		return $counts;
	}

	/**
	 * Refresh cached quality scores for products with pending or applied content.
	 *
	 * Without this the Products screen and the dashboard, which read only the
	 * cache, would show whatever was true the last time a merchant happened to
	 * open Review, and a product scored after that would read "Not scored" for
	 * ever.
	 *
	 * Bounded three ways, since every product is a read against Rezolve Ai:
	 * at most `Quality::REFRESH_LIMIT` products, oldest fetch first (never
	 * fetched before anything), none fetched within the last hour, and the whole
	 * refresh at most once per `Quality::REFRESH_INTERVAL`. `drain()` re-arms a
	 * tick every minute while a backlog of decisions clears, and that must not
	 * turn into a read of 200 products a minute.
	 *
	 * @param int $limit Products per pass.
	 * @return int Products refreshed.
	 */
	public function refresh_quality( $limit = Quality::REFRESH_LIMIT ) {
		if ( ! $this->plugin->settings()->is_connected() ) {
			return 0;
		}
		if ( false !== get_transient( Quality::REFRESH_LOCK ) ) {
			return 0;
		}
		set_transient( Quality::REFRESH_LOCK, 1, Quality::REFRESH_INTERVAL );

		$ids = $this->plugin->repository()->ids_for_quality_refresh( (int) $limit, time() - HOUR_IN_SECONDS );
		if ( empty( $ids ) ) {
			return 0;
		}

		$result = $this->plugin->quality()->fetch( $ids );
		if ( ! empty( $result['errors'] ) ) {
			// Cached scores stay as they were: a failed read is not a reason to
			// forget a measurement. The next tick retries the same products,
			// because their fetch time did not move.
			$this->plugin->logger()->log(
				'quality refresh could not read ' . count( $result['errors'] ) . ' products: ' . reset( $result['errors'] ),
				'warning'
			);
		}
		return count( $result['records'] );
	}

	/**
	 * Report a bounded batch of flagged products, then self-reschedule if more
	 * remain. Mirrors `Cron::auto_apply_sweep()`, so a large backlog drains in
	 * minutes rather than one batch per hour.
	 *
	 * @param int $limit Products per pass.
	 * @return array{reported:int,skipped:int,failed:int}
	 */
	public function drain( $limit = self::BATCH ) {
		$empty = array(
			'reported' => 0,
			'skipped'  => 0,
			'failed'   => 0,
		);

		if ( ! $this->plugin->settings()->is_connected() ) {
			return $empty;
		}

		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => (int) $limit,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'     => array(
					array(
						'key'     => Meta::SYNC_DIRTY,
						'compare' => 'EXISTS',
					),
				),
			)
		);
		if ( empty( $ids ) ) {
			return $empty;
		}

		$decisions   = array();
		$by_product  = array();
		$counts      = $empty;
		foreach ( $ids as $id ) {
			$flag   = get_post_meta( $id, Meta::SYNC_DIRTY, true );
			$reason = ( is_array( $flag ) && isset( $flag['reason'] ) ) ? (string) $flag['reason'] : '';
			$known  = ( is_array( $flag ) && isset( $flag['fields'] ) && is_array( $flag['fields'] ) ) ? $flag['fields'] : array();
			$built  = $this->build_decisions( $id, $reason, $known );
			if ( empty( $built ) ) {
				// Nothing to say about this product. Clear the flag rather than
				// carrying it for ever: a product with no proposals is not a
				// failure, and leaving it flagged would make every drain report
				// the same empty work.
				delete_post_meta( $id, Meta::SYNC_DIRTY );
				delete_post_meta( $id, Meta::SYNC_ATTEMPTS );
				++$counts['skipped'];
				continue;
			}
			$decisions          = array_merge( $decisions, $built );
			$by_product[ $id ]  = true;
		}

		if ( empty( $decisions ) ) {
			return $counts;
		}

		$response = $this->plugin->api()->review_decisions(
			$this->plugin->settings()->get_store_id(),
			$decisions
		);

		if ( is_wp_error( $response ) ) {
			// The decisions are still true; only the telling failed. Leave every
			// flag in place so the next tick retries, and count the attempt so a
			// permanently unreachable platform stops filling the log.
			foreach ( array_keys( $by_product ) as $id ) {
				$attempts = (int) get_post_meta( $id, Meta::SYNC_ATTEMPTS, true ) + 1;
				update_post_meta( $id, Meta::SYNC_ATTEMPTS, $attempts );
				if ( $attempts >= self::MAX_ATTEMPTS ) {
					delete_post_meta( $id, Meta::SYNC_DIRTY );
				}
			}
			$counts['failed'] = count( $by_product );
			$this->plugin->logger()->log(
				'review sync failed, decisions stay queued: ' . $response->get_error_message(),
				'warning'
			);
			return $counts;
		}

		foreach ( array_keys( $by_product ) as $id ) {
			delete_post_meta( $id, Meta::SYNC_DIRTY );
			delete_post_meta( $id, Meta::SYNC_ATTEMPTS );
		}
		$counts['reported'] = count( $by_product );

		// More flagged products behind this batch: re-arm a near-term tick
		// rather than waiting an hour per batch.
		if ( count( $ids ) >= (int) $limit && ! wp_next_scheduled( Cron::REVIEW_SYNC ) ) {
			wp_schedule_single_event( time() + 60, Cron::REVIEW_SYNC );
		}

		return $counts;
	}

	/**
	 * Flag every product that already carries a decision, once, on upgrade.
	 *
	 * WHY THIS EXISTS AT ALL. The Shopify half of this work backfills its
	 * history with one SQL pass, because those rows live in the platform's own
	 * database. This site's review state lives in `wp_postmeta` on the
	 * merchant's server, where no query from Rezolve Ai can reach it. The only
	 * way its history can ever appear in the web review queue is for the plugin
	 * to volunteer it, so that is what this does.
	 *
	 * Bounded and resumable: a page of products per pass, a cursor in options,
	 * self-rescheduling while work remains. A 50k-product store must not try to
	 * do this in one request.
	 *
	 * @param int $limit Products per pass.
	 * @return int Products flagged in this pass.
	 */
	public function backfill( $limit = self::BATCH ) {
		$cursor = (int) get_option( 'agencoen_review_backfill_cursor', 0 );
		if ( $cursor < 0 ) {
			return 0;
		}

		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => (int) $limit,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'include'        => array(),
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'     => array(
					array(
						'key'     => Meta::STATUS,
						'value'   => array( Meta::STATUS_ENRICHED, Meta::STATUS_APPLIED, Meta::STATUS_DISMISSED ),
						'compare' => 'IN',
					),
				),
				'post__not_in'   => array(),
				'offset'         => $cursor,
			)
		);

		foreach ( $ids as $id ) {
			$this->mark_dirty( $id, 'backfill' );
		}

		if ( count( $ids ) < (int) $limit ) {
			// Done. -1 rather than deleting the option, so a later upgrade does
			// not silently start the whole walk again.
			update_option( 'agencoen_review_backfill_cursor', -1, false );
		} else {
			update_option( 'agencoen_review_backfill_cursor', $cursor + count( $ids ), false );
			if ( ! wp_next_scheduled( Cron::REVIEW_BACKFILL ) ) {
				wp_schedule_single_event( time() + 120, Cron::REVIEW_BACKFILL );
			}
		}

		return count( $ids );
	}
}
