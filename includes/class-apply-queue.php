<?php
/**
 * Applying content Rezolve Ai approved, and telling Rezolve Ai it landed.
 *
 * WHY THIS EXISTS, AND WHAT ITS ABSENCE COST
 * This plugin has always had its own review and apply flow: enrichment results
 * arrive from `/api/v1/jobs/{id}/results`, land in post meta, and `Apply`
 * writes them onto the product. Entirely local. Rezolve Ai was never told.
 *
 * That was survivable while the only question was "is the content live", which
 * the site can answer for itself. It stopped being survivable when per-visitor
 * A/B shipped in 4.5.0, because on the Rezolve Ai side a version counts as live only
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
 * catches up on its next tick rather than needing Rezolve Ai to have retried, and a
 * site that never connects simply never asks.
 *
 * WHY IT DOES NOT GO THROUGH `Apply`
 * `Apply::apply()` reads `Meta::PENDING` -- content this site requested and is
 * holding. A queue entry is the opposite direction: Rezolve Ai has already decided,
 * and hands over one field at a time. Routing it through the local flow would
 * mean writing meta purely so it could be read back, and would make a Rezolve Ai
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

	/**
	 * The outcome of an entry held back, neither applied nor failed (4.18.0).
	 * No failure reason is ever this word, so it cannot be mistaken for one.
	 */
	const DEFERRED = 'deferred';

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
	 * A failed pull leaves everything alone: an unreachable Rezolve Ai is not evidence
	 * that anything changed. A failed APPLY is reported as such and the entry
	 * stays queued, because the queue selects on `pushed_at IS NULL` and only a
	 * successful ack clears it.
	 *
	 * A DEFERRED entry is left out of the ack altogether, so it stays queued and
	 * comes back on a later pull. See {@see deferred_by_edit()}.
	 *
	 * @return array{applied:int,failed:int,skipped:int,deferred:int}
	 */
	public function drain() {
		$settings = $this->plugin->settings();
		$empty    = array(
			'applied'  => 0,
			'failed'   => 0,
			'skipped'  => 0,
			'deferred' => 0,
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
			if ( self::DEFERRED === $outcome ) {
				// Not in the ack: an entry reported neither applied nor failed
				// keeps its place in the queue and is delivered again, by then
				// carrying the value the merchant typed here.
				$counts['deferred']++;
				continue;
			}
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
			$this->plugin->logger()->error( 'apply-queue ack failed, content is live but Rezolve Ai was not told: ' . $ack->get_error_message() );
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
	 * @return true|string True, self::DEFERRED, or a reason.
	 */
	private function apply_entry( array $item ) {
		$product_ref = isset( $item['productId'] ) ? (string) $item['productId'] : '';
		$field       = isset( $item['field'] ) ? (string) $item['field'] : '';
		$value       = isset( $item['value'] ) ? $item['value'] : null;

		if ( '' === $product_ref || '' === $field ) {
			return 'malformed entry';
		}

		// ONE resolution rule, shared with Ab_Test. A queue entry names a
		// product the way Rezolve Ai knows it -- usually the merchant's own SKU -- and
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

		if ( self::deferred_by_edit( $post_id, $field ) ) {
			return self::DEFERRED;
		}

		switch ( $field ) {
			// The adapter writes nothing when no SEO plugin is installed, and
			// its boolean used to be discarded. Returning true regardless made
			// drain() ack the row as applied, which stamps pushed_at and drops
			// it out of the queue for good, so the copy could never land even
			// after the merchant installed an SEO plugin. Failing is not a
			// worse outcome than that: the queue selects `pushed_at IS NULL`,
			// so the row simply waits and applies on the first tick after one
			// is present.
			case 'seoTitle':
				return $this->plugin->seo()->write_seo_title( $post_id, $value )
					? true
					: $this->plugin->seo()->write_failure_reason( 'meta title' );

			case 'seoDescription':
				return $this->plugin->seo()->write_seo_description( $post_id, $value )
					? true
					: $this->plugin->seo()->write_failure_reason( 'meta description' );

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

			// The `woocommerce` content type's two additive fields. Both arrive
			// as JSON inside the string value; Native_Fields owns the parsing
			// and the sanitising so the local flow cannot end up with a
			// different idea of what a usable tag is.
			//
			// A payload that sanitises down to nothing returns a REASON, not
			// true. Reporting "applied" for a write that wrote nothing is
			// exactly the lie this class exists to prevent.
			case 'productTags':
				$tags = Native_Fields::normalize_tags( $value );
				if ( empty( $tags ) ) {
					return 'no usable tags in payload';
				}
				return Native_Fields::write_tags( $post_id, $tags )
					? true
					: 'product already carries every tag in the payload';

			case 'productAttributes':
				$attributes = Native_Fields::normalize_attributes( $value );
				if ( empty( $attributes ) ) {
					return 'no usable attributes in payload';
				}
				$product = wc_get_product( $post_id );
				if ( ! $product ) {
					return 'product not loadable';
				}
				// 4.18.0: an attribute this plugin added may be REPLACED, so an
				// edit approved in Rezolve Ai lands on a product that already
				// carries the first version. Attributes the merchant added
				// themselves are still never touched. Nothing is removed here:
				// this one field serves two content types, so a payload that
				// does not mention a key is not asking for it to go.
				$written = Native_Fields::write_owned_attributes( $product, $attributes );
				if ( ! empty( $written['added'] ) || ! empty( $written['replaced'] ) ) {
					$product->save();
					return true;
				}
				// Every attribute already reads exactly what was approved, and
				// this plugin put it there: the product says what was approved.
				if ( ! empty( $written['unchanged'] ) ) {
					return true;
				}
				return 'product already carries every attribute in the payload';

			/*
			 * The three additive content types (4.15.0).
			 *
			 * WHY THEY WERE MISSING. Rezolve Ai's apply queue deliberately did
			 * not send them, on the stated grounds that they were applied by
			 * the plugin's own enrichment flow -- which is true only for
			 * content this site requested. Content a merchant approved in the
			 * Rezolve Ai web app never travelled at all, so approving a schema
			 * graph or a set of IRL cards there did nothing here, for ever.
			 *
			 * They write the SAME meta as `Apply::apply_content_types`, so the
			 * frontend renderers and the feed serializers need no change and
			 * `Apply::revert()` already knows how to take them down.
			 */
			case 'jsonLd':
				$graph = json_decode( $value, true );
				if ( ! is_array( $graph ) || empty( $graph ) ) {
					return 'unreadable JSON-LD payload';
				}
				update_post_meta( $post_id, Meta::JSONLD, $graph );
				return true;

			case 'irlCards':
				$decoded   = json_decode( $value, true );
				// `{ cards, selectedIndex }` is the wire shape; a bare array is
				// accepted too, matching apply_content_types' tolerance.
				$scenarios = ( is_array( $decoded ) && isset( $decoded['cards'] ) && is_array( $decoded['cards'] ) )
					? $decoded['cards']
					: $decoded;
				if ( ! is_array( $scenarios ) || empty( $scenarios ) ) {
					return 'no usable IRL scenarios in payload';
				}
				update_post_meta( $post_id, Meta::IRL, $scenarios );
				return true;

			case 'internalLinks':
				$decoded = json_decode( $value, true );
				$links   = ( is_array( $decoded ) && isset( $decoded['links'] ) && is_array( $decoded['links'] ) )
					? $decoded['links']
					: $decoded;
				if ( ! is_array( $links ) || empty( $links ) ) {
					return 'no usable internal links in payload';
				}
				update_post_meta( $post_id, Meta::LINKS, $links );
				return true;

			/*
			 * Highlights, product Q&A and image alt text (4.16.0). Rezolve Ai only
			 * sends these to a plugin reporting 4.16.0 or later. Pdp_Content does
			 * the reading, shared with Apply, so the queue and the local flow
			 * write the same thing. Product attributes need no case of their own:
			 * they arrive as `productAttributes`, above.
			 */
			case 'highlights':
				$highlights = Pdp_Content::highlights( $value );
				if ( empty( $highlights ) ) {
					return 'no usable highlights in payload';
				}
				update_post_meta( $post_id, Meta::HIGHLIGHTS, $highlights );
				return true;

			case 'productQa':
				$qa = Pdp_Content::qa( $value );
				if ( empty( $qa ) ) {
					return 'no usable questions and answers in payload';
				}
				update_post_meta( $post_id, Meta::QA, $qa );
				return true;

			case 'imageAlt':
				$entries = Pdp_Content::image_alts( $value );
				if ( empty( $entries ) ) {
					return 'no usable alt text in payload';
				}
				$product = wc_get_product( $post_id );
				if ( ! $product ) {
					return 'product not loadable';
				}
				$match = Pdp_Content::match_image_alts( $entries, Native_Fields::image_candidates( $product ) );
				if ( empty( $match['matches'] ) ) {
					return 'no image in payload is on this product any more';
				}
				// Every matched image already carrying the proposed text is a
				// success, not a failure: the product says what was approved.
				Native_Fields::write_image_alts( $post_id, $match['matches'] );
				return true;
		}

		// An unknown field is not a failure to retry forever. A newer Rezolve Ai may
		// send a field this version cannot write, and reporting it applied
		// would be a lie; reporting it failed would re-deliver it on every
		// tick until the site upgrades. Failed is the honest answer, and the
		// message says which field so the log names the version gap.
		//
		// THIS IS ALSO THE CATEGORY GUARD. There is deliberately no `categories`
		// case above, and no writer for one in Native_Fields, so a future Rezolve Ai
		// that started sending product categories would land here and be
		// refused rather than restructuring a merchant's storefront navigation.
		// Same for `productType`: WooCommerce's product_type is
		// simple|variable|grouped|external, which selects a WC_Product subclass
		// and changes how the product is sold, so Shopify's merchandising
		// product_type has no destination here at all.
		return 'unsupported field: ' . $field;
	}

	/**
	 * Whether an entry would overwrite an edit Rezolve Ai has not heard of yet.
	 *
	 * THE RACE THIS CLOSES (4.18.0). A merchant approves a highlight list in
	 * the Rezolve Ai web app, then corrects one bullet on this site's Review
	 * screen before the next pull. Without this, the pull writes the approved
	 * original straight over the correction, and the merchant's edit is gone
	 * with nothing to say why.
	 *
	 * The edit reaches Rezolve Ai with the next review-decisions report, which
	 * writes the edited value into the review row. So an entry for an edited
	 * field is held back until a report made AFTER the edit has succeeded;
	 * the entry delivered after that carries the merchant's value.
	 *
	 * `Meta::REVIEW_SYNCED_AT` is written by {@see Review_Sync::drain()} only
	 * for a product whose whole state went out in that report (its dirty flag
	 * did not change while the report was in flight), and it is the time the
	 * report was BUILT, so an edit that lands mid-flight still counts as unsent.
	 *
	 * @param int    $post_id Product post id.
	 * @param string $field   Wire field.
	 * @return bool
	 */
	public static function deferred_by_edit( $post_id, $field ) {
		if ( ! isset( Edit_Values::WIRE_FIELDS[ $field ] ) ) {
			return false;
		}
		$edited = get_post_meta( (int) $post_id, Meta::EDITED, true );
		$type   = Edit_Values::WIRE_FIELDS[ $field ];
		if ( ! is_array( $edited ) || empty( $edited[ $type ] ) ) {
			return false;
		}
		$edited_at = strtotime( (string) $edited[ $type ] );
		$synced_at = strtotime( (string) get_post_meta( (int) $post_id, Meta::REVIEW_SYNCED_AT, true ) );
		if ( false === $edited_at ) {
			return false;
		}
		return false === $synced_at || $edited_at > $synced_at;
	}
}
