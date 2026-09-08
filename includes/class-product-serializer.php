<?php
/**
 * Serializes a WC_Product into the exact subset the Rezolve Ai platform reads.
 *
 * The server-side mapper (src/lib/woocommerce/product-mapper.ts) consumes only:
 *   id, name, slug, permalink, sku, price (string), description,
 *   short_description, type, stock_status, manage_stock, stock_quantity,
 *   images[{src,alt}], tags[{name}], categories[{name}],
 *   variations[{id,sku,price,regular_price,stock_status,stock_quantity,
 *   attributes[{name,option}]}], meta_data[{key,value}]
 * Anything else is dropped server-side, so we don't send it.
 *
 * Two additions in 4.13, both because the catalogue Rezolve Ai publishes to
 * shopping agents was wrong without them, and Rezolve Ai cannot go and fetch
 * either one: a plugin-registered store has no WooCommerce API credentials.
 *
 * 1. `stock_status`. It is the only authoritative purchasability flag
 *    WooCommerce has. Without it the server had nothing but `stock_quantity`,
 *    which is null for every product with stock management off, and a null read
 *    downstream as zero, and a zero read as an outage. An in-stock catalogue was
 *    published as out of stock, and agents refused to buy from it.
 * 2. Full variation objects rather than bare ids. Ids alone carry no price, SKU,
 *    stock or attributes, so six real variations collapsed into one synthetic
 *    "Default" variant priced at the parent's minimum. VARIATION_LIMIT caps how
 *    many are sent, because these are the field that makes a batch large.
 *
 * @package Agencoen
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Product_Serializer
 */
class Product_Serializer {

	/**
	 * Most variations we will serialize for one product.
	 *
	 * Generous: WooCommerce's own admin paginates variations well below this.
	 * A cap exists because variations are what make a sync batch large, and the
	 * server refuses a body over 3MB.
	 */
	const VARIATION_LIMIT = 200;

	/**
	 * Build the sync payload for one product.
	 *
	 * @param \WC_Product $product Product.
	 * @return array
	 */
	public function to_sync_payload( $product ) {
		return array(
			'id'                => (string) $product->get_id(),
			'name'              => $product->get_name(),
			'slug'              => $product->get_slug(),
			'permalink'         => $product->get_permalink(),
			'sku'               => $product->get_sku(),
			'price'             => (string) $product->get_price(),
			'description'       => $product->get_description(),
			'short_description' => $product->get_short_description(),
			'type'              => $product->get_type(),
			'stock_status'      => $product->get_stock_status(),
			'manage_stock'      => (bool) $product->get_manage_stock(),
			'stock_quantity'    => $product->get_stock_quantity(),
			'images'            => $this->images( $product ),
			'tags'              => $this->term_names( $product->get_id(), 'product_tag' ),
			'categories'        => $this->term_names( $product->get_id(), 'product_cat' ),
			'variations'        => $this->variations( $product ),
			'meta_data'         => $this->meta_data( $product ),
		);
	}

	/**
	 * Featured image first, then gallery, as [{src, alt}].
	 *
	 * @param \WC_Product $product Product.
	 * @return array
	 */
	private function images( $product ) {
		$ids = array();
		if ( $product->get_image_id() ) {
			$ids[] = $product->get_image_id();
		}
		foreach ( (array) $product->get_gallery_image_ids() as $gid ) {
			$ids[] = $gid;
		}

		$images = array();
		foreach ( array_unique( $ids ) as $id ) {
			$src = wp_get_attachment_image_url( $id, 'full' );
			if ( ! $src ) {
				continue;
			}
			$alt = get_post_meta( $id, '_wp_attachment_image_alt', true );
			$images[] = array(
				'src' => $src,
				'alt' => '' !== $alt ? $alt : $product->get_name(),
			);
		}
		return $images;
	}

	/**
	 * Term names for a taxonomy as [{name}].
	 *
	 * @param int    $product_id Product id.
	 * @param string $taxonomy   Taxonomy.
	 * @return array
	 */
	private function term_names( $product_id, $taxonomy ) {
		$terms = get_the_terms( $product_id, $taxonomy );
		$out   = array();
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				if ( isset( $term->name ) && '' !== $term->name ) {
					$out[] = array( 'name' => $term->name );
				}
			}
		}
		return $out;
	}

	/**
	 * Child variations for variable products, in full.
	 *
	 * Ids alone were useless to the server: it has no WooCommerce credentials
	 * with which to look the children up, so every variable product reached the
	 * catalogue as a single "Default" variant priced at the parent's minimum.
	 * Price, SKU, stock and the chosen attributes are what make a variant
	 * purchasable, so they travel with the id.
	 *
	 * @param \WC_Product $product Product.
	 * @return array
	 */
	private function variations( $product ) {
		if ( ! $product->is_type( 'variable' ) ) {
			return array();
		}

		$out = array();
		foreach ( (array) $product->get_children() as $child_id ) {
			if ( count( $out ) >= self::VARIATION_LIMIT ) {
				break;
			}

			$variation = wc_get_product( $child_id );
			// A child can be missing, trashed or draft. Publishing one as
			// purchasable would send an agent to a variation that 404s.
			if ( ! $variation || ! $variation->is_type( 'variation' ) ) {
				continue;
			}
			if ( 'publish' !== get_post_status( $child_id ) ) {
				continue;
			}

			$out[] = array(
				'id'             => (string) $variation->get_id(),
				'sku'            => $variation->get_sku(),
				'price'          => (string) $variation->get_price(),
				'regular_price'  => (string) $variation->get_regular_price(),
				'stock_status'   => $variation->get_stock_status(),
				'stock_quantity' => $variation->get_stock_quantity(),
				'attributes'     => $this->variation_attributes( $variation ),
			);
		}

		return $out;
	}

	/**
	 * A variation's chosen attributes as [{name, option}], in display terms.
	 *
	 * WooCommerce stores the axis as a taxonomy slug and the choice as a term
	 * slug, neither of which is a label. Resolving both here rather than on the
	 * server keeps the translation next to the site that owns the vocabulary.
	 *
	 * @param \WC_Product_Variation $variation Variation.
	 * @return array
	 */
	private function variation_attributes( $variation ) {
		$out = array();
		foreach ( (array) $variation->get_variation_attributes( false ) as $name => $option ) {
			// An empty option means "any": the variation matches every value on
			// that axis, so there is no single choice to publish.
			if ( '' === (string) $option ) {
				continue;
			}

			$label = wc_attribute_label( $name, $variation );
			$value = $option;
			if ( taxonomy_exists( $name ) ) {
				$term = get_term_by( 'slug', $option, $name );
				if ( $term && ! is_wp_error( $term ) ) {
					$value = $term->name;
				}
			}

			$out[] = array(
				'name'   => (string) $label,
				'option' => (string) $value,
			);
		}
		return $out;
	}

	/**
	 * Minimal meta the server actually reads (vendor/brand). Avoids leaking
	 * private post meta and our own _agencoen_* bookkeeping.
	 *
	 * @param \WC_Product $product Product.
	 * @return array
	 */
	private function meta_data( $product ) {
		$wanted = array( 'vendor', '_vendor', 'brand', '_brand' );
		$out    = array();
		foreach ( $wanted as $key ) {
			$value = $product->get_meta( $key );
			if ( is_scalar( $value ) && '' !== (string) $value ) {
				$out[] = array(
					'key'   => $key,
					'value' => (string) $value,
				);
			}
		}
		return $out;
	}
}
