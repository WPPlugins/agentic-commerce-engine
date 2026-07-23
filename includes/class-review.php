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
		delete_post_meta( $product_id, Meta::PENDING );
		update_post_meta( $product_id, Meta::STATUS, Meta::STATUS_DISMISSED );
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
				'score'     => get_post_meta( $id, Meta::SCORE, true ),
				'diff'      => $this->diff_for( $id ),
			);
		}
		return $items;
	}
}
