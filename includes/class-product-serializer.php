<?php
/**
 * Serializes a WC_Product into the exact subset the ACE platform reads.
 *
 * The server-side mapper (src/lib/woocommerce/product-mapper.ts) consumes only:
 *   id, name, slug, permalink, sku, price (string), description,
 *   short_description, stock_quantity, images[{src,alt}], tags[{name}],
 *   categories[{name}], variations[ids], meta_data[{key,value}]
 * Anything else is dropped server-side, so we don't send it.
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
			'stock_quantity'    => $product->get_stock_quantity(),
			'images'            => $this->images( $product ),
			'tags'              => $this->term_names( $product->get_id(), 'product_tag' ),
			'categories'        => $this->term_names( $product->get_id(), 'product_cat' ),
			'variations'        => $this->variation_ids( $product ),
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
	 * Child variation ids for variable products (the server only needs ids).
	 *
	 * @param \WC_Product $product Product.
	 * @return array
	 */
	private function variation_ids( $product ) {
		if ( ! $product->is_type( 'variable' ) ) {
			return array();
		}
		return array_map( 'strval', $product->get_children() );
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
