<?php
/**
 * Writes enriched content onto live products and supports exact revert.
 *
 * Two layers: (1) core on-page fields from the flat payload (name, description,
 * short description, SEO title/description); (2) additive content types stored
 * for storefront rendering or feed serving (JSON-LD schema, IRL cards, internal
 * links, channel feeds). Originals are backed up once at first apply.
 *
 * @package Agencoen
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Apply
 */
class Apply {

	const FEED_CHANNELS = array(
		'google-shopping'    => 'google',
		'microsoft-shopping' => 'microsoft',
		'agentic-commerce'   => 'agentic',
	);

	/** @var Plugin */
	private $plugin;

	/**
	 * @param Plugin $plugin Orchestrator.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Apply some or all enrichment to a product.
	 *
	 * @param int   $product_id Product id.
	 * @param array $fields     Core flat-field keys to apply; empty = all available.
	 * @return true|\WP_Error
	 */
	public function apply( $product_id, array $fields = array() ) {
		$product_id = (int) $product_id;
		$pending    = get_post_meta( $product_id, Meta::PENDING, true );
		$content    = get_post_meta( $product_id, Meta::CONTENT, true );
		$pending    = is_array( $pending ) ? $pending : array();
		$content    = is_array( $content ) ? $content : array();

		if ( empty( $pending ) && empty( $content ) ) {
			return new \WP_Error( 'agencoen_nothing_pending', __( 'No pending enrichment to apply.', 'agentic-commerce-engine' ) );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new \WP_Error( 'agencoen_no_product', __( 'Product not found.', 'agentic-commerce-engine' ) );
		}

		$this->backup_once( $product );
		$applied_types = array();

		// 1) Core on-page fields from the flat payload.
		$apply_keys = empty( $fields ) ? array_keys( $pending ) : array_intersect( $fields, array_keys( $pending ) );
		$touched    = false;
		foreach ( $apply_keys as $key ) {
			$value = $pending[ $key ];
			switch ( $key ) {
				case 'title':
					$product->set_name( sanitize_text_field( $value ) );
					$touched = true;
					break;
				case 'short_description':
					$product->set_short_description( wp_kses_post( $value ) );
					$touched                              = true;
					$applied_types['product-description'] = true;
					break;
				case 'description':
					$product->set_description( wp_kses_post( $value ) );
					$touched                              = true;
					$applied_types['product-description'] = true;
					break;
				case 'seo_title':
					$this->plugin->seo()->write_seo_title( $product_id, $value );
					$applied_types['meta-tags'] = true;
					break;
				case 'seo_description':
					$this->plugin->seo()->write_seo_description( $product_id, $value );
					$applied_types['meta-tags'] = true;
					break;
			}
		}
		if ( $touched ) {
			$product->save();
		}

		// 2) Additive content types.
		$this->apply_content_types( $product_id, $content, $applied_types );

		update_post_meta( $product_id, Meta::STATUS, Meta::STATUS_APPLIED );
		update_post_meta( $product_id, Meta::APPLIED_AT, gmdate( 'c' ) );
		update_post_meta( $product_id, Meta::APPLIED_TYPES, array_keys( $applied_types ) );
		$this->plugin->repository()->bust_counts_cache();
		Feeds::touch();

		return true;
	}

	/**
	 * Store additive content types for rendering / feed serving.
	 *
	 * @param int   $product_id    Product id.
	 * @param array $content       Per-type payload.
	 * @param array $applied_types Accumulator (by reference).
	 */
	private function apply_content_types( $product_id, array $content, array &$applied_types ) {
		foreach ( $content as $type => $data ) {
			if ( 'jsonld-schema' === $type || 'json-ld-schema' === $type ) {
				$graph = is_string( $data ) ? json_decode( $data, true ) : $data;
				if ( is_array( $graph ) && ! empty( $graph ) ) {
					update_post_meta( $product_id, Meta::JSONLD, $graph );
					$applied_types['jsonld-schema'] = true;
				}
			} elseif ( 'irl-scenarios' === $type ) {
				$scenarios = ( isset( $data['scenarios'] ) && is_array( $data['scenarios'] ) ) ? $data['scenarios'] : $data;
				update_post_meta( $product_id, Meta::IRL, $scenarios );
				$applied_types['irl-scenarios'] = true;
			} elseif ( 'internal-links' === $type ) {
				$links = ( isset( $data['links'] ) && is_array( $data['links'] ) ) ? $data['links'] : $data;
				update_post_meta( $product_id, Meta::LINKS, $links );
				$applied_types['internal-links'] = true;
			} elseif ( isset( self::FEED_CHANNELS[ $type ] ) ) {
				update_post_meta( $product_id, Meta::FEED_PREFIX . self::FEED_CHANNELS[ $type ], $data );
				$applied_types[ $type ] = true;
			}
		}
	}

	/**
	 * Restore a product's original values and remove additive content.
	 *
	 * @param int $product_id Product id.
	 * @return true|\WP_Error
	 */
	public function revert( $product_id ) {
		$product_id = (int) $product_id;
		$backup     = get_post_meta( $product_id, Meta::BACKUP, true );
		if ( ! is_array( $backup ) || empty( $backup ) ) {
			return new \WP_Error( 'agencoen_no_backup', __( 'No backup to revert to.', 'agentic-commerce-engine' ) );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new \WP_Error( 'agencoen_no_product', __( 'Product not found.', 'agentic-commerce-engine' ) );
		}

		if ( isset( $backup['title'] ) ) {
			$product->set_name( $backup['title'] );
		}
		if ( isset( $backup['description'] ) ) {
			$product->set_description( $backup['description'] );
		}
		if ( isset( $backup['short_description'] ) ) {
			$product->set_short_description( $backup['short_description'] );
		}
		$product->save();

		if ( isset( $backup['seo_title'] ) ) {
			$this->plugin->seo()->write_seo_title( $product_id, $backup['seo_title'] );
		}
		if ( isset( $backup['seo_description'] ) ) {
			$this->plugin->seo()->write_seo_description( $product_id, $backup['seo_description'] );
		}

		// Remove additive artifacts (schema/IRL/links/feeds).
		delete_post_meta( $product_id, Meta::JSONLD );
		delete_post_meta( $product_id, Meta::IRL );
		delete_post_meta( $product_id, Meta::LINKS );
		foreach ( self::FEED_CHANNELS as $channel ) {
			delete_post_meta( $product_id, Meta::FEED_PREFIX . $channel );
		}
		delete_post_meta( $product_id, Meta::APPLIED_TYPES );

		update_post_meta( $product_id, Meta::STATUS, Meta::STATUS_ENRICHED );
		delete_post_meta( $product_id, Meta::APPLIED_AT );
		$this->plugin->repository()->bust_counts_cache();
		Feeds::touch();

		return true;
	}

	/**
	 * Capture original values once, before the first apply.
	 *
	 * @param \WC_Product $product Product.
	 */
	private function backup_once( $product ) {
		$product_id = $product->get_id();
		if ( get_post_meta( $product_id, Meta::BACKUP, true ) ) {
			return;
		}
		update_post_meta(
			$product_id,
			Meta::BACKUP,
			array(
				'title'             => $product->get_name(),
				'description'       => $product->get_description(),
				'short_description' => $product->get_short_description(),
				'seo_title'         => $this->plugin->seo()->read_seo_title( $product_id ),
				'seo_description'   => $this->plugin->seo()->read_seo_description( $product_id ),
			)
		);
	}
}
