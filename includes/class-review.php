<?php
/**
 * Read model + actions for the Review screen: build before/after diffs, approve
 * (apply), dismiss, and revert.
 *
 * @package Agencoen
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Review
 */
class Review {

	/** @var Plugin */
	private $plugin;

	/**
	 * @param Plugin $plugin Orchestrator.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Items awaiting review (status = enriched).
	 *
	 * @param int $page 1-based page.
	 * @param int $per  Page size.
	 * @return array
	 */
	public function pending_items( $page = 1, $per = 20 ) {
		return $this->items_for_status( Meta::STATUS_ENRICHED, $page, $per );
	}

	/**
	 * Items already applied (for the Applied tab / revert).
	 *
	 * @param int $page 1-based page.
	 * @param int $per  Page size.
	 * @return array
	 */
	public function applied_items( $page = 1, $per = 20 ) {
		return $this->items_for_status( Meta::STATUS_APPLIED, $page, $per );
	}

	/**
	 * Build a per-field before/after diff for a product.
	 *
	 * @param int $product_id Product id.
	 * @return array List of { key, label, before, after, changed }.
	 */
	public function diff_for( $product_id ) {
		$product_id = (int) $product_id;
		$pending    = get_post_meta( $product_id, Meta::PENDING, true );
		if ( ! is_array( $pending ) ) {
			return array();
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return array();
		}

		$before = array(
			'title'             => $product->get_name(),
			'short_description' => $product->get_short_description(),
			'description'       => $product->get_description(),
			'seo_title'         => $this->plugin->seo()->read_seo_title( $product_id ),
			'seo_description'   => $this->plugin->seo()->read_seo_description( $product_id ),
		);

		$diff = array();
		foreach ( Meta::fields() as $key => $label ) {
			if ( ! isset( $pending[ $key ] ) || '' === $pending[ $key ] ) {
				continue;
			}
			$after  = (string) $pending[ $key ];
			$source = isset( $before[ $key ] ) ? (string) $before[ $key ] : '';
			$diff[] = array(
				'key'     => $key,
				'label'   => $label,
				'before'  => $source,
				'after'   => $after,
				'changed' => trim( wp_strip_all_tags( $source ) ) !== trim( wp_strip_all_tags( $after ) ),
			);
		}
		return $diff;
	}

	/**
	 * What approving would do with highlights, Q&A, attributes and alt text,
	 * computed here rather than in the browser so the preview uses the same
	 * reading and the same image matching as {@see Apply}. A merchant approving
	 * alt text sees each image, its current alt and what replaces it.
	 *
	 * `$live` (4.18.0) reads a live product's highlights and Q&A from what is on
	 * the store rather than from the stored proposal, so "Edit live" opens with
	 * what shoppers see. Each image row carries the URL its alt text was written
	 * for, and `attributePairs` lists specifications one value per row, which is
	 * the shape the editor sends back.
	 *
	 * @param int  $product_id Product id.
	 * @param bool $live       Read the live values where they exist.
	 * @return array{highlights:string[],qa:array,attributes:array,attributePairs:array,imageAlt:array}
	 */
	public function extras_for( $product_id, $live = false ) {
		$product_id = (int) $product_id;
		$content    = get_post_meta( $product_id, Meta::CONTENT, true );
		$content    = is_array( $content ) ? $content : array();
		$product    = wc_get_product( $product_id );

		$extras = array(
			'highlights'     => isset( $content['highlights'] ) ? Pdp_Content::highlights( $content['highlights'] ) : array(),
			'qa'             => isset( $content['product-qa'] ) ? Pdp_Content::qa( $content['product-qa'] ) : array(),
			'attributes'     => array(),
			'attributePairs' => isset( $content['product-attributes'] ) ? Edit_Values::attribute_pairs( $content['product-attributes'] ) : array(),
			'imageAlt'       => array(),
		);

		if ( $live ) {
			$live_highlights = Pdp_Content::highlights( get_post_meta( $product_id, Meta::HIGHLIGHTS, true ) );
			$live_qa         = Pdp_Content::qa( get_post_meta( $product_id, Meta::QA, true ) );
			if ( ! empty( $extras['highlights'] ) && ! empty( $live_highlights ) ) {
				$extras['highlights'] = $live_highlights;
			}
			if ( ! empty( $extras['qa'] ) && ! empty( $live_qa ) ) {
				$extras['qa'] = $live_qa;
			}
		}

		if ( $product && isset( $content['product-attributes'] ) ) {
			$existing = $product->get_attributes();
			$existing = is_array( $existing ) ? $existing : array();
			$owned    = $live ? Native_Fields::owned_attribute_keys( $product_id ) : array();
			foreach ( Edit_Values::attributes_for_write( $extras['attributePairs'] ) as $attribute ) {
				$key    = sanitize_title( $attribute['name'] );
				$action = isset( $existing[ $key ] ) ? 'exists' : 'add';
				if ( $live && isset( $existing[ $key ] ) && in_array( $key, $owned, true ) ) {
					$action = 'owned';
				}
				$extras['attributes'][] = array(
					'name'   => $attribute['name'],
					'values' => $attribute['values'],
					'action' => $action,
				);
			}
		}

		if ( $product && isset( $content['image-alt'] ) ) {
			$entries = Pdp_Content::image_alts( $content['image-alt'] );
			$match   = Pdp_Content::match_image_alts( $entries, Native_Fields::image_candidates( $product ) );
			foreach ( $entries as $index => $entry ) {
				if ( ! isset( $match['by_entry'][ $index ] ) ) {
					$extras['imageAlt'][] = array(
						'id'       => 0,
						'imageUrl' => esc_url_raw( $entry['url'] ),
						'thumb'    => '',
						'current'  => '',
						'proposed' => $entry['alt'],
						'action'   => 'unmatched',
					);
					continue;
				}
				$attachment_id        = (int) $match['by_entry'][ $index ];
				$current              = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
				$extras['imageAlt'][] = array(
					'id'       => $attachment_id,
					'imageUrl' => esc_url_raw( $entry['url'] ),
					'thumb'    => esc_url_raw( (string) wp_get_attachment_image_url( $attachment_id, 'thumbnail' ) ),
					'current'  => $current,
					'proposed' => $entry['alt'],
					'action'   => $current === $entry['alt'] ? 'same' : 'write',
				);
			}
		}

		return $extras;
	}

	/**
	 * How the Review screen may edit a product: `draft` for a proposal still
	 * waiting, `live` for one already applied, '' for anything else. Dismissed,
	 * failed and queued products have nothing a merchant can usefully edit.
	 *
	 * @param int $product_id Product id.
	 * @return string
	 */
	public static function edit_mode( $product_id ) {
		$status = (string) get_post_meta( (int) $product_id, Meta::STATUS, true );
		if ( Meta::STATUS_APPLIED === $status ) {
			return 'live';
		}
		return Meta::STATUS_ENRICHED === $status ? 'draft' : '';
	}

	/**
	 * Save a merchant's edit to one field of a product (4.18.0).
	 *
	 * DRAFT (a proposal still waiting). The proposal is rewritten in place:
	 * the flat `Meta::PENDING` key Apply reads and the `Meta::CONTENT` entry
	 * the previews and the report read. Approving afterwards applies the edited
	 * text, and nothing reaches the store before that.
	 *
	 * LIVE (already applied). Only this field is written to the store, through
	 * {@see Apply::write_edit()}, and then the stored proposal is rewritten the
	 * same way, so the Review screen shows what is live. `Meta::BACKUP` is not
	 * touched, so revert still restores the product as it was before Rezolve Ai.
	 *
	 * BOTH. The edit is recorded in `Meta::EDITED` and the product is marked for
	 * the review-decisions report, which carries the edited value to Rezolve
	 * Ai. The mark is written LAST, after everything the report reads: a report
	 * already in flight then sees its flag change and does not claim the edit
	 * as sent (see Review_Sync::drain()).
	 *
	 * The mode the screen asked for must match the product's state. A screen
	 * left open while the product was approved elsewhere would otherwise save a
	 * "draft" that goes nowhere, or write to a store the merchant thought they
	 * were only drafting for.
	 *
	 * @param int    $product_id Product id.
	 * @param string $field      One of Edit_Values::FIELDS.
	 * @param string $mode       draft|live.
	 * @param mixed  $raw        Decoded list for structured fields, a string otherwise.
	 * @return array|\WP_Error `{ ok: false, errors }` for a value the merchant can
	 *                         fix, `{ ok: true, ... }` with the refreshed preview, or
	 *                         an error for a product that cannot be edited.
	 */
	public function save_edit( $product_id, $field, $mode, $raw ) {
		$product_id = (int) $product_id;
		$field      = (string) $field;
		if ( ! Edit_Values::is_field( $field ) ) {
			return new \WP_Error( 'agencoen_edit_type', __( 'This content cannot be edited here.', 'agentic-commerce-engine' ) );
		}
		if ( ! $product_id || ! wc_get_product( $product_id ) ) {
			return new \WP_Error( 'agencoen_no_product', __( 'Product not found.', 'agentic-commerce-engine' ) );
		}

		$expected = self::edit_mode( $product_id );
		if ( '' === $expected ) {
			return new \WP_Error( 'agencoen_edit_state', __( 'This suggestion is no longer waiting for review or live, so it cannot be edited.', 'agentic-commerce-engine' ) );
		}
		if ( $mode !== $expected ) {
			return new \WP_Error(
				'agencoen_edit_stale',
				'live' === $expected
					? __( 'This product was approved in the meantime. Reload the page to edit what is live.', 'agentic-commerce-engine' )
					: __( 'This product is no longer live. Reload the page to see where it stands.', 'agentic-commerce-engine' )
			);
		}

		$pending = get_post_meta( $product_id, Meta::PENDING, true );
		$pending = is_array( $pending ) ? $pending : array();
		$content = get_post_meta( $product_id, Meta::CONTENT, true );
		$content = is_array( $content ) ? $content : array();
		if ( ! Edit_Values::has_proposal( $field, $pending, $content ) ) {
			return new \WP_Error( 'agencoen_edit_missing', __( 'There is no suggestion of this kind on this product to edit.', 'agentic-commerce-engine' ) );
		}

		$normalized = Edit_Values::normalize( $field, $raw );
		if ( ! $normalized['ok'] ) {
			return array(
				'ok'     => false,
				'errors' => $normalized['errors'],
			);
		}
		$value = $normalized['value'];

		$notes = array();
		if ( 'live' === $mode ) {
			$notes = $this->plugin->apply()->write_edit( $product_id, $field, $value, $content );
			if ( is_wp_error( $notes ) ) {
				return $notes;
			}
		}

		list( $pending, $content ) = Edit_Values::apply_to_proposal( $field, $value, $pending, $content );
		if ( 'description' === $field && isset( $pending['short_description'] ) ) {
			// Derived, never edited on its own. See Poller::derive_flat_fields().
			$pending['short_description'] = Poller::short_description_for( $value );
		}
		if ( 'product_qa' === $field ) {
			foreach ( array( 'jsonld-schema', 'json-ld-schema' ) as $key ) {
				if ( ! isset( $content[ $key ] ) ) {
					continue;
				}
				$graph = is_string( $content[ $key ] ) ? json_decode( $content[ $key ], true ) : $content[ $key ];
				list( $graph, $changed ) = Schema::with_faq_questions( $graph, $value );
				if ( $changed ) {
					// Stored as an array, which Apply reads just the same. A JSON
					// string inside post meta is unslashed on save, and a quote in
					// an answer would leave it undecodable.
					$content[ $key ] = $graph;
				}
			}
		}
		update_post_meta( $product_id, Meta::PENDING, $pending );
		update_post_meta( $product_id, Meta::CONTENT, $content );

		$edited           = get_post_meta( $product_id, Meta::EDITED, true );
		$edited           = is_array( $edited ) ? $edited : array();
		$edited[ $field ] = gmdate( 'c' );
		update_post_meta( $product_id, Meta::EDITED, $edited );

		// A revert not yet reported stays a revert: reporting this product as a
		// fresh proposal would put content the merchant took down back in the
		// web queue. The edit rides the next decision instead.
		$flag   = get_post_meta( $product_id, Meta::SYNC_DIRTY, true );
		$reason = ( is_array( $flag ) && isset( $flag['reason'] ) && 'revert' === $flag['reason'] ) ? 'revert' : 'edit';
		$this->plugin->review_sync()->mark_dirty( $product_id, $reason );

		return array(
			'ok'     => true,
			'id'     => $product_id,
			'field'  => $field,
			'mode'   => $mode,
			'kept'   => isset( $notes['kept'] ) ? array_values( $notes['kept'] ) : array(),
			'diff'   => $this->diff_for( $product_id ),
			'extras' => $this->extras_for( $product_id, 'live' === $mode ),
			'content' => $content,
		);
	}

	/**
	 * Approve (apply) a product's enrichment.
	 *
	 * @param int   $product_id Product id.
	 * @param array $fields     Optional subset of field keys.
	 * @return true|\WP_Error
	 */
	public function approve( $product_id, array $fields = array() ) {
		return $this->plugin->apply()->apply( $product_id, $fields );
	}

	/**
	 * Dismiss a suggestion without applying it.
	 *
	 * @param int $product_id Product id.
	 * @return true
	 */
	public function dismiss( $product_id ) {
		$product_id = (int) $product_id;
		// Capture the fields BEFORE the pending meta goes. Dismissing deletes the
		// only record of what was proposed, so a reporter run an hour later would
		// find nothing to dismiss and the decision would never reach Rezolve Ai.
		$decisions = $this->plugin->review_sync()->build_decisions( $product_id, 'dismiss' );
		$fields    = array();
		foreach ( $decisions as $decision ) {
			$fields[] = $decision['field'];
		}
		delete_post_meta( $product_id, Meta::PENDING );
		update_post_meta( $product_id, Meta::STATUS, Meta::STATUS_DISMISSED );
		$this->plugin->review_sync()->mark_dirty( $product_id, 'dismiss', $fields );
		$this->plugin->repository()->bust_counts_cache();
		return true;
	}

	/**
	 * Revert an applied product to its backup.
	 *
	 * @param int $product_id Product id.
	 * @return true|\WP_Error
	 */
	public function revert( $product_id ) {
		return $this->plugin->apply()->revert( $product_id );
	}

	/**
	 * Build item view-models for a status.
	 *
	 * @param string $status Status value.
	 * @param int    $page   1-based page.
	 * @param int    $per    Page size.
	 * @return array
	 */
	private function items_for_status( $status, $page, $per ) {
		$ids   = $this->plugin->repository()->ids_with_status( $status, $page, $per );
		$items = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product ) {
				continue;
			}
			$items[] = array(
				'id'        => $id,
				'name'      => $product->get_name(),
				'edit_link' => get_edit_post_link( $id, 'raw' ),
				// Measured product-level quality, or null. See Quality::product_overall().
				'score'     => Quality::cached_overall( $id ),
				'diff'      => $this->diff_for( $id ),
			);
		}
		return $items;
	}
}
