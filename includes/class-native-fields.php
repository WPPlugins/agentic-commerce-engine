<?php
/**
 * Writing WooCommerce's own product fields, and taking them back exactly.
 *
 * WHAT THIS WRITES
 * Product tags (`product_tag` terms) and custom per-product attributes. SEO
 * title and meta description are NOT here: they travel the existing flat-field
 * path through {@see Apply} and {@see Seo_Adapter}, so they inherit
 * {@see Apply::backup_once()} and {@see Apply::revert()} unchanged.
 *
 * ONE WRITER, TWO CALLERS
 * {@see Apply} (the local flow, driven by Meta::PENDING and Meta::CONTENT) and
 * {@see Apply_Queue} (Rezolve Ai approved it and hands over one field at a time) both
 * come through here. The same principle Apply_Queue already applies to product
 * RESOLUTION, applied to the WRITE: a merchant who enriches from wp-admin and
 * one who approves in the Rezolve Ai app must end up with the same product.
 *
 * WHY ADDITIVE, AND WHY A LEDGER RATHER THAN A SNAPSHOT
 * The Shopify app replaces a product's whole tag array and restores the old one
 * on revert. That is safe there because Shopify tags are a flat string list.
 * Here they are terms with ids, archives, and other plugins pointing at them.
 * Three things break a snapshot:
 *
 *   1. Restoring one months later DELETES tags the merchant added by hand in
 *      between. Revert must not destroy data the merchant created.
 *   2. Apply runs twice by design. Apply_Queue::drain() re-applies when an ack
 *      fails, because the content IS live and only the report failed. A
 *      snapshot is exact only if the field has exactly one writer, ever.
 *   3. Meta::BACKUP is captured once, before the FIRST apply. It would have to
 *      snapshot every product's tags whether or not that product ever receives
 *      a woocommerce payload.
 *
 * So every write here is additive, and its exact inverse is "remove exactly
 * what we added". {@see Meta::NATIVE_WRITES} records that, merged across
 * repeated applies. Correct under concurrent merchant edits, correct on
 * re-apply, and correct on a revert months later.
 *
 * WHAT THIS REFUSES TO WRITE
 * Product categories. `product_cat` is the storefront's navigation spine:
 * archives, breadcrumbs, menus, layered nav, category-scoped coupons and
 * shipping rules, and this plugin's own category filter. A generated name
 * rarely matches an existing term, so a write would create top-level categories
 * that appear immediately in the merchant's menu, and revert can undo the
 * membership but not a changed archive URL. There is deliberately no method
 * here that writes one, so Apply_Queue's "unsupported field" fall-through
 * refuses it rather than restructuring a catalogue.
 *
 * Global attribute taxonomies (`pa_*`) are refused for the same shape of
 * reason: creating one adds a row to wp_woocommerce_attribute_taxonomies plus
 * terms, which is a schema change to the merchant's store that cannot be
 * cleanly undone. Custom per-product attributes create no terms and are undone
 * by removing an array key.
 *
 * @package Agencoen
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Native_Fields
 */
class Native_Fields {

	/**
	 * Caps, so a runaway generation cannot bury a merchant in terms. Values over
	 * the cap are truncated rather than refused: a partial write the merchant
	 * can see beats a failure they have to diagnose.
	 */
	const MAX_TAGS                  = 30;
	const MAX_ATTRIBUTES            = 20;
	const MAX_VALUES_PER_ATTRIBUTE  = 30;
	const MAX_TERM_LENGTH           = 200;

	/**
	 * Clean a list of term names.
	 *
	 * PURE, and the reason the caller passes raw input straight in: this is
	 * where a JSON array, a comma-separated string and a malformed payload all
	 * become the same thing, so the two callers cannot disagree about what
	 * counts as a usable tag.
	 *
	 * @param mixed $raw JSON string, comma-separated string, or array.
	 * @return string[] Cleaned, de-duplicated, capped. Empty when nothing survives.
	 */
	public static function normalize_tags( $raw ) {
		$list = self::to_list( $raw );
		if ( ! is_array( $list ) ) {
			return array();
		}

		$out  = array();
		$seen = array();
		foreach ( $list as $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$name = self::clean_term( (string) $value );
			if ( '' === $name ) {
				continue;
			}
			$key = strtolower( $name );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $name;
			if ( count( $out ) >= self::MAX_TAGS ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Clean a list of custom attributes.
	 *
	 * PURE. Accepts the canonical wire shape, an array of
	 * `{ name, values, visible, variation }`, and tolerates a scalar `values`.
	 * `variation` is forced false whatever the payload claims: a variation axis
	 * is catalog structure, and writing over a variable product's axis breaks
	 * every variation on it.
	 *
	 * @param mixed $raw JSON string or array.
	 * @return array<int,array{name:string,values:string[],visible:bool,variation:bool}>
	 */
	public static function normalize_attributes( $raw ) {
		$list = self::to_list( $raw );
		if ( ! is_array( $list ) ) {
			return array();
		}
		// The `product-attributes` generator wraps its list as { attributes }.
		if ( isset( $list['attributes'] ) && is_array( $list['attributes'] ) ) {
			$list = $list['attributes'];
		}

		$out  = array();
		$seen = array();
		foreach ( $list as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$name = isset( $entry['name'] ) && is_scalar( $entry['name'] )
				? self::clean_term( (string) $entry['name'] )
				: '';
			if ( '' === $name ) {
				continue;
			}
			// The global attribute-taxonomy namespace. Never ours to create.
			if ( 0 === strpos( strtolower( $name ), 'pa_' ) ) {
				continue;
			}

			// `values` is the wire shape; the generator emits one `value` per
			// attribute, so accept that too rather than dropping every one.
			$raw_values = isset( $entry['values'] ) ? $entry['values'] : ( isset( $entry['value'] ) ? $entry['value'] : null );
			if ( is_scalar( $raw_values ) ) {
				$raw_values = array( $raw_values );
			}
			if ( ! is_array( $raw_values ) ) {
				continue;
			}

			$values     = array();
			$seen_value = array();
			foreach ( $raw_values as $value ) {
				if ( ! is_scalar( $value ) ) {
					continue;
				}
				$clean = self::clean_term( (string) $value );
				if ( '' === $clean ) {
					continue;
				}
				$key = strtolower( $clean );
				if ( isset( $seen_value[ $key ] ) ) {
					continue;
				}
				$seen_value[ $key ] = true;
				$values[]           = $clean;
				if ( count( $values ) >= self::MAX_VALUES_PER_ATTRIBUTE ) {
					break;
				}
			}
			if ( empty( $values ) ) {
				continue;
			}

			$key = strtolower( $name );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = array(
				'name'      => $name,
				'values'    => $values,
				'visible'   => ! isset( $entry['visible'] ) || false !== $entry['visible'],
				'variation' => false,
			);
			if ( count( $out ) >= self::MAX_ATTRIBUTES ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Append product tags, recording exactly what was added.
	 *
	 * @param int      $product_id Product post id.
	 * @param string[] $tags       Already normalized.
	 * @return bool True when at least one term was newly attached.
	 */
	public static function write_tags( $product_id, array $tags ) {
		if ( empty( $tags ) ) {
			return false;
		}
		$product_id = (int) $product_id;

		$before = self::term_ids( $product_id );
		// Which of these names had no term at all before we asked. Checked
		// BEFORE the write, because wp_set_object_terms creates one silently and
		// afterwards we could no longer tell ours from the merchant's.
		$new_terms = array();
		foreach ( $tags as $name ) {
			if ( ! term_exists( $name, 'product_tag' ) ) {
				$new_terms[ strtolower( $name ) ] = true;
			}
		}

		// $append = true. The whole point: never remove what is already there.
		$result = wp_set_object_terms( $product_id, $tags, 'product_tag', true );
		if ( is_wp_error( $result ) ) {
			return false;
		}

		$after = self::term_ids( $product_id );
		$added = array_values( array_diff( $after, $before ) );
		if ( empty( $added ) ) {
			return false;
		}

		$created = array();
		foreach ( $added as $term_id ) {
			$term = get_term( $term_id, 'product_tag' );
			if ( $term && ! is_wp_error( $term ) && isset( $new_terms[ strtolower( $term->name ) ] ) ) {
				$created[] = (int) $term_id;
			}
		}

		self::record( $product_id, 'tags', $added, $created );
		return true;
	}

	/**
	 * Merge custom attributes onto a product, recording exactly which keys were
	 * added. Does NOT save; the caller saves once.
	 *
	 * NEVER OVERWRITES AN EXISTING KEY. A merchant's own attribute is theirs,
	 * and one with variation=true is a variable product's axis, so replacing
	 * either is a data loss the merchant did not ask for.
	 *
	 * @param \WC_Product $product    Product.
	 * @param array       $attributes Already normalized.
	 * @return bool True when at least one attribute was added.
	 */
	public static function write_attributes( $product, array $attributes ) {
		if ( empty( $attributes ) || ! is_object( $product ) ) {
			return false;
		}

		$existing = $product->get_attributes();
		$existing = is_array( $existing ) ? $existing : array();
		$added    = array();
		$position = count( $existing );

		foreach ( $attributes as $attribute ) {
			$key = sanitize_title( $attribute['name'] );
			if ( '' === $key || isset( $existing[ $key ] ) ) {
				continue;
			}

			$object = new \WC_Product_Attribute();
			$object->set_id( 0 ); // 0 = custom (local), not a taxonomy.
			$object->set_name( $attribute['name'] );
			$object->set_options( $attribute['values'] );
			$object->set_position( $position );
			$object->set_visible( (bool) $attribute['visible'] );
			$object->set_variation( false );

			$existing[ $key ] = $object;
			$added[]          = $key;
			++$position;
		}

		if ( empty( $added ) ) {
			return false;
		}

		$product->set_attributes( $existing );
		self::record( (int) $product->get_id(), 'attributes', $added, array() );
		return true;
	}

	/**
	 * Remove exactly what this class wrote, and nothing else.
	 *
	 * Does NOT save the product; the caller saves once, so revert stays a single
	 * write like the rest of {@see Apply::revert()}.
	 *
	 * @param int              $product_id Product post id.
	 * @param \WC_Product|null $product    Loaded product, when the caller has one.
	 * @return void
	 */
	public static function undo( $product_id, $product = null ) {
		$product_id = (int) $product_id;
		$ledger     = get_post_meta( $product_id, Meta::NATIVE_WRITES, true );
		if ( ! is_array( $ledger ) || empty( $ledger ) ) {
			return;
		}

		$tags = isset( $ledger['tags'] ) && is_array( $ledger['tags'] ) ? $ledger['tags'] : array();
		$added = isset( $tags['added'] ) && is_array( $tags['added'] ) ? array_map( 'intval', $tags['added'] ) : array();
		if ( ! empty( $added ) ) {
			wp_remove_object_terms( $product_id, $added, 'product_tag' );

			// Delete a term we created ONLY if nothing points at it any more. A
			// term another product has picked up since is now the merchant's.
			$created = isset( $tags['created'] ) && is_array( $tags['created'] ) ? array_map( 'intval', $tags['created'] ) : array();
			foreach ( $created as $term_id ) {
				$term = get_term( $term_id, 'product_tag' );
				if ( $term && ! is_wp_error( $term ) && 0 === (int) $term->count ) {
					wp_delete_term( $term_id, 'product_tag' );
				}
			}
		}

		// Alt text goes back only where it is still exactly what we wrote. An
		// image whose alt the merchant has since edited is theirs now, and an
		// attachment that no longer exists has nothing to restore.
		$alts = isset( $ledger['image_alt'] ) && is_array( $ledger['image_alt'] ) ? $ledger['image_alt'] : array();
		foreach ( $alts as $attachment_id => $entry ) {
			$attachment_id = (int) $attachment_id;
			if ( $attachment_id <= 0 || ! is_array( $entry ) || ! isset( $entry['written'] ) ) {
				continue;
			}
			$current = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
			if ( $current !== (string) $entry['written'] ) {
				continue;
			}
			$previous = isset( $entry['previous'] ) ? (string) $entry['previous'] : '';
			if ( '' === $previous ) {
				delete_post_meta( $attachment_id, '_wp_attachment_image_alt' );
			} else {
				update_post_meta( $attachment_id, '_wp_attachment_image_alt', $previous );
			}
		}

		$attributes = isset( $ledger['attributes'] ) && is_array( $ledger['attributes'] ) ? $ledger['attributes'] : array();
		$keys       = isset( $attributes['keys'] ) && is_array( $attributes['keys'] ) ? $attributes['keys'] : array();
		if ( ! empty( $keys ) && is_object( $product ) ) {
			$current = $product->get_attributes();
			$current = is_array( $current ) ? $current : array();
			foreach ( $keys as $key ) {
				unset( $current[ (string) $key ] );
			}
			$product->set_attributes( $current );
		}

		delete_post_meta( $product_id, Meta::NATIVE_WRITES );
	}

	/**
	 * The product's own images, with every URL each one is known by.
	 *
	 * Featured image first, then the gallery, the same order the serializer
	 * sends to Rezolve Ai. Several URLs per attachment because the one the alt
	 * text was generated from may be the full size, the original before
	 * WordPress scaled it, or the attachment URL itself.
	 *
	 * @param \WC_Product $product Product.
	 * @return array<int,string[]> attachment_id => URLs.
	 */
	public static function image_candidates( $product ) {
		if ( ! is_object( $product ) ) {
			return array();
		}
		$ids = array();
		if ( $product->get_image_id() ) {
			$ids[] = (int) $product->get_image_id();
		}
		foreach ( (array) $product->get_gallery_image_ids() as $gallery_id ) {
			$ids[] = (int) $gallery_id;
		}

		$out = array();
		foreach ( array_unique( array_filter( $ids ) ) as $id ) {
			$urls = array(
				wp_get_attachment_image_url( $id, 'full' ),
				wp_get_attachment_url( $id ),
			);
			if ( function_exists( 'wp_get_original_image_url' ) ) {
				$urls[] = wp_get_original_image_url( $id );
			}
			$urls = array_values( array_unique( array_filter( $urls, 'is_string' ) ) );
			if ( ! empty( $urls ) ) {
				$out[ $id ] = $urls;
			}
		}
		return $out;
	}

	/**
	 * Write approved alt text onto attachments, recording what each held first.
	 *
	 * OVERWRITES, unlike everything else in this class: the merchant saw each
	 * image's current and proposed alt text in Review and approved the change.
	 * An image already carrying exactly the proposed text is left alone.
	 *
	 * @param int                $product_id Product post id (owns the ledger).
	 * @param array<int,string>  $alt_by_id  attachment_id => alt text.
	 * @return int How many images were written.
	 */
	public static function write_image_alts( $product_id, array $alt_by_id ) {
		$product_id = (int) $product_id;
		$written    = array();
		foreach ( $alt_by_id as $attachment_id => $alt ) {
			$attachment_id = (int) $attachment_id;
			$alt           = trim( sanitize_text_field( (string) $alt ) );
			if ( $attachment_id <= 0 || '' === $alt ) {
				continue;
			}
			$current = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
			if ( $current === $alt ) {
				continue;
			}
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
			$written[ $attachment_id ] = array(
				'previous' => $current,
				'written'  => $alt,
			);
		}
		if ( ! empty( $written ) ) {
			self::record_image_alts( $product_id, $written );
		}
		return count( $written );
	}

	/**
	 * Merge alt writes into the ledger, keeping each image's EARLIEST previous
	 * value so a second apply cannot make our own first write look like the
	 * merchant's original.
	 *
	 * @param int   $product_id Product post id.
	 * @param array $written    attachment_id => { previous, written }.
	 * @return void
	 */
	private static function record_image_alts( $product_id, array $written ) {
		$ledger = get_post_meta( $product_id, Meta::NATIVE_WRITES, true );
		$ledger = is_array( $ledger ) ? $ledger : array();
		$alts   = isset( $ledger['image_alt'] ) && is_array( $ledger['image_alt'] ) ? $ledger['image_alt'] : array();
		foreach ( $written as $attachment_id => $entry ) {
			$previous               = isset( $alts[ $attachment_id ]['previous'] ) ? $alts[ $attachment_id ]['previous'] : $entry['previous'];
			$alts[ $attachment_id ] = array(
				'previous' => (string) $previous,
				'written'  => (string) $entry['written'],
			);
		}
		$ledger['image_alt'] = $alts;
		update_post_meta( $product_id, Meta::NATIVE_WRITES, $ledger );
	}

	/**
	 * Is there anything recorded to undo? Used by {@see Apply::revert()}, which
	 * would otherwise bail on a product that only ever received a queue payload
	 * and therefore has a ledger but no backup.
	 *
	 * @param int $product_id Product post id.
	 * @return bool
	 */
	public static function has_writes( $product_id ) {
		$ledger = get_post_meta( (int) $product_id, Meta::NATIVE_WRITES, true );
		return is_array( $ledger ) && ! empty( $ledger );
	}

	/**
	 * Merge one write into the ledger. De-duplicated, because apply can run
	 * twice for the same row and the second pass must not double the entry.
	 *
	 * @param int    $product_id Product post id.
	 * @param string $bucket     'tags' or 'attributes'.
	 * @param array  $added      Term ids, or attribute keys.
	 * @param array  $created    Term ids this write brought into existence.
	 * @return void
	 */
	private static function record( $product_id, $bucket, array $added, array $created ) {
		$ledger = get_post_meta( $product_id, Meta::NATIVE_WRITES, true );
		$ledger = is_array( $ledger ) ? $ledger : array();

		if ( 'attributes' === $bucket ) {
			$keys                     = isset( $ledger['attributes']['keys'] ) && is_array( $ledger['attributes']['keys'] ) ? $ledger['attributes']['keys'] : array();
			$ledger['attributes']     = array( 'keys' => array_values( array_unique( array_merge( $keys, $added ) ) ) );
		} else {
			$prev_added   = isset( $ledger['tags']['added'] ) && is_array( $ledger['tags']['added'] ) ? $ledger['tags']['added'] : array();
			$prev_created = isset( $ledger['tags']['created'] ) && is_array( $ledger['tags']['created'] ) ? $ledger['tags']['created'] : array();
			$ledger['tags'] = array(
				'added'   => array_values( array_unique( array_merge( $prev_added, $added ) ) ),
				'created' => array_values( array_unique( array_merge( $prev_created, $created ) ) ),
			);
		}

		update_post_meta( $product_id, Meta::NATIVE_WRITES, $ledger );
	}

	/**
	 * Current product_tag term ids for a product.
	 *
	 * @param int $product_id Product post id.
	 * @return int[]
	 */
	private static function term_ids( $product_id ) {
		$terms = get_the_terms( $product_id, 'product_tag' );
		if ( ! is_array( $terms ) ) {
			return array();
		}
		$ids = array();
		foreach ( $terms as $term ) {
			if ( isset( $term->term_id ) ) {
				$ids[] = (int) $term->term_id;
			}
		}
		return $ids;
	}

	/**
	 * Decode a wire value into a list. Accepts a JSON array, an array, or a
	 * comma-separated string (which the Rezolve Ai side does not send, but the Shopify
	 * app's tag handling has always tolerated and a hand-built payload might use).
	 *
	 * @param mixed $raw Wire value.
	 * @return array|null Null when it cannot be read as a list.
	 */
	private static function to_list( $raw ) {
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( ! is_string( $raw ) ) {
			return null;
		}
		$trimmed = trim( $raw );
		if ( '' === $trimmed ) {
			return null;
		}
		if ( '[' === $trimmed[0] || '{' === $trimmed[0] ) {
			$decoded = json_decode( $trimmed, true );
			return is_array( $decoded ) ? $decoded : null;
		}
		return explode( ',', $trimmed );
	}

	/**
	 * Strip markup, sanitize, trim, and refuse anything over the term cap.
	 *
	 * @param string $value Raw value.
	 * @return string Empty when nothing usable survives.
	 */
	private static function clean_term( $value ) {
		$clean = trim( sanitize_text_field( wp_strip_all_tags( $value ) ) );
		if ( '' === $clean ) {
			return '';
		}
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $clean ) : strlen( $clean );
		return $length > self::MAX_TERM_LENGTH ? '' : $clean;
	}
}
