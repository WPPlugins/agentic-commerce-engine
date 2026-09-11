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
		$apply_keys  = empty( $fields ) ? array_keys( $pending ) : array_intersect( $fields, array_keys( $pending ) );
		$touched     = false;
		$seo_blocked = '';
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
				// Stamped only when the adapter actually wrote. It writes nothing
				// when no SEO plugin is installed, and stamping regardless put
				// "Meta tags" on the Applied list for copy that reached no field.
				case 'seo_title':
					if ( $this->plugin->seo()->write_seo_title( $product_id, $value ) ) {
						$applied_types['meta-tags'] = true;
					} else {
						$seo_blocked = $this->plugin->seo()->write_failure_reason( 'meta title' );
					}
					break;
				case 'seo_description':
					if ( $this->plugin->seo()->write_seo_description( $product_id, $value ) ) {
						$applied_types['meta-tags'] = true;
					} else {
						$seo_blocked = $this->plugin->seo()->write_failure_reason( 'meta description' );
					}
					break;
			}
		}
		if ( $touched ) {
			$product->save();
		}

		// 2) Additive content types.
		$this->apply_content_types( $product_id, $content, $applied_types );

		// Nothing reached the product and an SEO write is why. Marking this
		// applied would leave the merchant reading "Applied" on a product
		// whose meta title is still whatever it was, with nothing on screen
		// saying so. Narrow on purpose: a run that wrote anything at all is
		// still an apply, and a partial one already reports the types that
		// did land.
		if ( '' !== $seo_blocked && ! $touched && empty( $applied_types ) ) {
			return new \WP_Error(
				'agencoen_seo_write_failed',
				sprintf(
					/* translators: %s: reason the SEO write could not be made. */
					__( 'Nothing was applied: %s.', 'agentic-commerce-engine' ),
					$seo_blocked
				)
			);
		}

		update_post_meta( $product_id, Meta::STATUS, Meta::STATUS_APPLIED );
		update_post_meta( $product_id, Meta::APPLIED_AT, gmdate( 'c' ) );
		update_post_meta( $product_id, Meta::APPLIED_TYPES, array_keys( $applied_types ) );

		// Tell Rezolve Ai, so its review queue stops listing this product as
		// waiting and its Published screen can show it as live. A meta write only:
		// the merchant's click must never wait on an HTTP call, and the hourly
		// drain does the talking.
		//
		// Hooked HERE rather than in `Review::approve()`, which merely delegates
		// to this method. Auto-apply and the cron sweep also arrive here, and all
		// three are genuine approvals; one hook covers them.
		$this->plugin->review_sync()->mark_dirty( $product_id, 'approve' );

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
			} elseif ( 'woocommerce' === $type ) {
				// Native WooCommerce fields, not an artifact stored for later
				// rendering. SEO title and description are NOT read here:
				// derive_flat_fields folds them into the flat payload so they
				// travel the same path as meta-tags and inherit backup_once()
				// and revert() unchanged.
				//
				// `categories` and `product_type` keys inside this payload are
				// IGNORED. product_cat is the storefront's navigation spine and
				// a write is not revertible; WooCommerce's product_type is
				// simple|variable|grouped|external and changes how the product
				// is sold.
				if ( is_array( $data ) && $this->apply_woocommerce_fields( $product_id, $data ) ) {
					$applied_types['woocommerce'] = true;
				}
			} elseif ( isset( self::FEED_CHANNELS[ $type ] ) ) {
				update_post_meta( $product_id, Meta::FEED_PREFIX . self::FEED_CHANNELS[ $type ], $data );
				$applied_types[ $type ] = true;
			}
		}
	}

	/**
	 * Write the `woocommerce` payload's tags and attributes onto the product.
	 *
	 * Shares Native_Fields with the Rezolve Ai driven queue path, so a merchant who
	 * enriches from wp-admin and one who approves in the Rezolve Ai app end up with
	 * the same product. Saves once, and only when something actually changed.
	 *
	 * @param int   $product_id Product post id.
	 * @param array $data       The type's payload.
	 * @return bool True when anything was written.
	 */
	private function apply_woocommerce_fields( $product_id, array $data ) {
		$wrote = false;

		$tags = isset( $data['tags'] ) ? Native_Fields::normalize_tags( $data['tags'] ) : array();
		if ( ! empty( $tags ) && Native_Fields::write_tags( $product_id, $tags ) ) {
			$wrote = true;
		}

		$attributes = isset( $data['attributes'] ) ? Native_Fields::normalize_attributes( $data['attributes'] ) : array();
		if ( ! empty( $attributes ) ) {
			$product = wc_get_product( $product_id );
			if ( $product && Native_Fields::write_attributes( $product, $attributes ) ) {
				$product->save();
				$wrote = true;
			}
		}

		return $wrote;
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
		$backup     = is_array( $backup ) ? $backup : array();

		// EITHER a backup or a ledger is enough to have something to undo.
		// A product that only ever received a Rezolve Ai approved queue payload has
		// a Native_Fields ledger and no backup, because the queue path never
		// calls backup_once(). Requiring a backup here left exactly those
		// products' tags and attributes unrevertable.
		if ( empty( $backup ) && ! Native_Fields::has_writes( $product_id ) ) {
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
		// Before the single save, so removing our attributes rides the same
		// write as the restored scalars. Removes exactly what we added and
		// leaves anything the merchant added afterwards alone.
		Native_Fields::undo( $product_id, $product );
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

		// The reason matters, and this is the case it exists for. A revert puts
		// the product back to STATUS_ENRICHED, which by status alone is
		// indistinguishable from a fresh proposal -- so a reporter reading only
		// the status would announce content the merchant just took down as newly
		// ready to review.
		$this->plugin->review_sync()->mark_dirty( $product_id, 'revert' );
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
