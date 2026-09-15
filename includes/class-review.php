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
	 * @param int $product_id Product id.
	 * @return array{highlights:string[],qa:array,attributes:array,imageAlt:array}
	 */
	public function extras_for( $product_id ) {
		$product_id = (int) $product_id;
		$content    = get_post_meta( $product_id, Meta::CONTENT, true );
		$content    = is_array( $content ) ? $content : array();
		$product    = wc_get_product( $product_id );

		$extras = array(
			'highlights' => isset( $content['highlights'] ) ? Pdp_Content::highlights( $content['highlights'] ) : array(),
			'qa'         => isset( $content['product-qa'] ) ? Pdp_Content::qa( $content['product-qa'] ) : array(),
			'attributes' => array(),
			'imageAlt'   => array(),
		);

		if ( $product && isset( $content['product-attributes'] ) ) {
			$existing = $product->get_attributes();
			$existing = is_array( $existing ) ? $existing : array();
			foreach ( Native_Fields::normalize_attributes( $content['product-attributes'] ) as $attribute ) {
				$extras['attributes'][] = array(
					'name'   => $attribute['name'],
					'values' => $attribute['values'],
					'action' => isset( $existing[ sanitize_title( $attribute['name'] ) ] ) ? 'exists' : 'add',
				);
			}
		}

		if ( $product && isset( $content['image-alt'] ) ) {
			$entries = Pdp_Content::image_alts( $content['image-alt'] );
			$match   = Pdp_Content::match_image_alts( $entries, Native_Fields::image_candidates( $product ) );
			foreach ( $match['matches'] as $attachment_id => $alt ) {
				$current              = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
				$extras['imageAlt'][] = array(
					'id'       => (int) $attachment_id,
					'thumb'    => esc_url_raw( (string) wp_get_attachment_image_url( $attachment_id, 'thumbnail' ) ),
					'current'  => $current,
					'proposed' => $alt,
					'action'   => $current === $alt ? 'same' : 'write',
				);
			}
			foreach ( $match['unmatched'] as $entry ) {
				$extras['imageAlt'][] = array(
					'id'       => 0,
					'thumb'    => '',
					'current'  => '',
					'proposed' => $entry['alt'],
					'action'   => 'unmatched',
				);
			}
		}

		return $extras;
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
