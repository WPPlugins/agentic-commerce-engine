<?php
/**
 * Writes enriched content onto live products and supports exact revert.
 *
 * Two layers: (1) core on-page fields from the flat payload (name, description,
 * short description, SEO title/description); (2) content types stored for
 * storefront rendering or feed serving (JSON-LD schema, IRL cards, internal
 * links, highlights, Q&A, channel feeds) or written to native fields through
 * Native_Fields (tags, attributes, image alt text). Originals are backed up once
 * at first apply; native writes keep their own ledger.
 *
 * @package Agencoen
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Apply
 */
class Apply {

	/**
	 * Engine content keys whose destination the merchant chooses, to the
	 * platform's content type (4.25.0). See Content_Destinations.
	 */
	const DESTINATION_TYPES = array(
		'irl-scenarios'      => 'irl-cards',
		'internal-links'     => 'internal-links',
		'highlights'         => 'highlights',
		'product-qa'         => 'product-qa',
		'product-attributes' => 'product-attributes',
	);

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

		// A product split's control product keeps its content until the
		// experiment releases it (4.29.0). Asked before anything is written,
		// so a refusal, or a check that could not be made, changes nothing.
		// Every approval arrives here: Review, Approve all, auto-apply and the
		// sweep. See Held_Back.
		$held = $this->plugin->held_back()->note_for( $product_id );
		if ( is_wp_error( $held ) ) {
			return $held;
		}
		if ( null !== $held ) {
			return Held_Back::refusal( $held );
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
					self::set_core_field( $product, $key, $value );
					$touched                        = true;
					$applied_types['product-title'] = true;
					break;
				case 'short_description':
				case 'description':
					self::set_core_field( $product, $key, $value );
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
			// Where the merchant sends this type (4.25.0): one of their own
			// fields or a section of the description. Null keeps the block,
			// which the branches below write exactly as before.
			$destination_type = isset( self::DESTINATION_TYPES[ $type ] ) ? self::DESTINATION_TYPES[ $type ] : '';
			if ( '' !== $destination_type ) {
				$routed = Content_Destinations::route( $this->plugin, $product_id, $destination_type, $data );
				if ( true === $routed ) {
					$applied_types[ $type ] = true;
					continue;
				}
				if ( null !== $routed ) {
					$this->plugin->logger()->log( 'content not written to its destination: ' . $routed, 'warning' );
					continue;
				}
			}
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
			} elseif ( 'highlights' === $type ) {
				if ( self::write_highlights( $product_id, $data ) ) {
					$applied_types['highlights'] = true;
				}
			} elseif ( 'product-qa' === $type ) {
				if ( self::write_qa( $product_id, $data ) ) {
					$applied_types['product-qa'] = true;
				}
			} elseif ( 'product-attributes' === $type ) {
				// The same add-only writer as the `woocommerce` type: an attribute
				// the product already has is kept, never replaced. Rows sharing a
				// name are merged into one attribute (4.18.0), as the web apply
				// queue does and as the Review preview shows; before, every row
				// after the first for a name was dropped without a word.
				$attributes = Edit_Values::attributes_for_write( Edit_Values::attribute_pairs( $data ) );
				$product    = empty( $attributes ) ? null : wc_get_product( $product_id );
				if ( $product && Native_Fields::write_attributes( $product, $attributes ) ) {
					$product->save();
					$applied_types['product-attributes'] = true;
				}
			} elseif ( 'image-alt' === $type ) {
				$product = wc_get_product( $product_id );
				if ( $product && self::write_image_alts( $product, $data ) > 0 ) {
					$applied_types['image-alt'] = true;
				}
			} elseif ( 'custom-fields' === $type ) {
				// The merchant's own custom fields, rewritten (4.24.0). Each is
				// written only if it still holds what the suggestion was written
				// from; Custom_Fields keeps the ledger revert() reads.
				$fields = ( is_array( $data ) && isset( $data['fields'] ) && is_array( $data['fields'] ) ) ? $data['fields'] : ( is_array( $data ) ? $data : array() );
				$result = Custom_Fields::write_rewrites( $product_id, $fields );
				if ( $result['written'] > 0 ) {
					$applied_types['custom-fields'] = true;
				}
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
	 * Put one merchant edit onto a live product (4.18.0).
	 *
	 * THE SAME WRITERS APPROVE USES, ONE TYPE AT A TIME. A merchant correcting
	 * a highlight on something already live must end up with exactly what an
	 * approval of that text would have written, so every branch below is the
	 * code {@see apply()} runs for that type, and nothing else on the product
	 * is touched.
	 *
	 * `Meta::BACKUP` is NEVER written here. It holds what the product was
	 * before Rezolve Ai first changed it, and a revert after any number of
	 * edits must still restore that. For the same reason alt text goes through
	 * Native_Fields, whose ledger keeps each image's EARLIEST previous value.
	 *
	 * Attributes are the one type that does not simply re-run the add-only
	 * writer: see {@see Native_Fields::write_owned_attributes()}. Attributes the
	 * merchant added themselves are never changed; ones Rezolve Ai added are
	 * replaced, and ones the merchant deleted from THIS editor are removed.
	 *
	 * A PRODUCT SPLIT'S CONTROL PRODUCT IS NOT EDITED (4.30.1). An edit lands on
	 * the storefront at once, exactly as an approval would, so it asks
	 * {@see Held_Back} first, as {@see apply()} does, and a check that could not
	 * be made writes nothing either.
	 *
	 * @param int    $product_id       Product id.
	 * @param string $field            One of Edit_Values::FIELDS.
	 * @param mixed  $value            Normalized by Edit_Values::normalize().
	 * @param array  $previous_content Meta::CONTENT before the edit.
	 * @return array|\WP_Error Notes for the screen, e.g. { kept: string[] }.
	 */
	public function write_edit( $product_id, $field, $value, array $previous_content = array() ) {
		$product_id = (int) $product_id;
		$product    = wc_get_product( $product_id );
		if ( ! $product ) {
			return new \WP_Error( 'agencoen_no_product', __( 'Product not found.', 'agentic-commerce-engine' ) );
		}

		$held = $this->plugin->held_back()->note_for( $product_id );
		if ( is_wp_error( $held ) ) {
			return Held_Back::edit_unavailable();
		}
		if ( null !== $held ) {
			return Held_Back::edit_refusal( $held );
		}

		$notes = array();
		$type  = '';
		switch ( $field ) {
			case 'title':
				self::set_core_field( $product, 'title', $value );
				$product->save();
				$type = 'product-title';
				break;

			case 'description':
				// The short description was derived from the description and
				// applied with it. It follows the edit only while it is still
				// exactly what was applied: one the merchant rewrote by hand in
				// WooCommerce is theirs.
				$pending = get_post_meta( $product_id, Meta::PENDING, true );
				$derived = is_array( $pending ) && isset( $pending['short_description'] ) ? (string) $pending['short_description'] : '';
				if ( '' !== $derived && (string) $product->get_short_description( 'edit' ) === wp_kses_post( $derived ) ) {
					self::set_core_field( $product, 'short_description', Poller::short_description_for( $value ) );
				}
				self::set_core_field( $product, 'description', $value );
				$product->save();
				$type = 'product-description';
				break;

			case 'seo_title':
			case 'seo_description':
				$wrote = 'seo_title' === $field
					? $this->plugin->seo()->write_seo_title( $product_id, $value )
					: $this->plugin->seo()->write_seo_description( $product_id, $value );
				if ( ! $wrote ) {
					return new \WP_Error(
						'agencoen_seo_write_failed',
						sprintf(
							/* translators: %s: reason the SEO write could not be made. */
							__( 'Nothing was saved: %s.', 'agentic-commerce-engine' ),
							$this->plugin->seo()->write_failure_reason( 'seo_title' === $field ? 'meta title' : 'meta description' )
						)
					);
				}
				$type = 'meta-tags';
				break;

			case 'highlights':
				$routed = Content_Destinations::route( $this->plugin, $product_id, 'highlights', $value );
				if ( is_string( $routed ) ) {
					return new \WP_Error( 'agencoen_edit_destination', $routed );
				}
				if ( null === $routed && ! self::write_highlights( $product_id, $value ) ) {
					return new \WP_Error( 'agencoen_edit_empty', __( 'Nothing usable was left to save.', 'agentic-commerce-engine' ) );
				}
				$type = 'highlights';
				break;

			case 'product_qa':
				$routed = Content_Destinations::route( $this->plugin, $product_id, 'product-qa', $value );
				if ( is_string( $routed ) ) {
					return new \WP_Error( 'agencoen_edit_destination', $routed );
				}
				if ( null === $routed && ! self::write_qa( $product_id, $value ) ) {
					return new \WP_Error( 'agencoen_edit_empty', __( 'Nothing usable was left to save.', 'agentic-commerce-engine' ) );
				}
				// The applied schema may carry its own FAQPage, built from the
				// questions being replaced. Markup that disagrees with the
				// visible Q&A is worse than none.
				$graph = get_post_meta( $product_id, Meta::JSONLD, true );
				list( $graph, $changed ) = Schema::with_faq_questions( $graph, Pdp_Content::qa( $value ) );
				if ( $changed ) {
					update_post_meta( $product_id, Meta::JSONLD, $graph );
				}
				$type = 'product-qa';
				break;

			case 'product_attributes':
				$routed = Content_Destinations::route( $this->plugin, $product_id, 'product-attributes', $value );
				if ( is_string( $routed ) ) {
					return new \WP_Error( 'agencoen_edit_destination', $routed );
				}
				if ( true === $routed ) {
					$type = 'product-attributes';
					break;
				}
				$attributes = Edit_Values::attributes_for_write( $value );
				if ( empty( $attributes ) ) {
					return new \WP_Error( 'agencoen_edit_empty', __( 'Nothing usable was left to save.', 'agentic-commerce-engine' ) );
				}
				$new_keys = array();
				foreach ( $attributes as $attribute ) {
					$new_keys[] = sanitize_title( $attribute['name'] );
				}
				// Removed only if THIS editor showed it and the merchant took it
				// out. A key another content type added is not this editor's.
				$shown = array();
				if ( isset( $previous_content['product-attributes'] ) ) {
					foreach ( Edit_Values::attribute_pairs( $previous_content['product-attributes'] ) as $pair ) {
						$shown[] = sanitize_title( $pair['name'] );
					}
				}
				$written = Native_Fields::write_owned_attributes( $product, $attributes, array_diff( array_unique( $shown ), $new_keys ) );
				if ( ! empty( $written['added'] ) || ! empty( $written['replaced'] ) || ! empty( $written['removed'] ) ) {
					$product->save();
				}
				$notes['kept'] = $written['kept'];
				$type          = 'product-attributes';
				break;

			case 'image_alt':
				$matched = 0;
				self::write_image_alts( $product, $value, $matched );
				if ( 0 === $matched ) {
					return new \WP_Error( 'agencoen_edit_no_image', __( 'None of these images is on this product any more, so nothing was saved.', 'agentic-commerce-engine' ) );
				}
				$type = 'image-alt';
				break;

			default:
				return new \WP_Error( 'agencoen_edit_type', __( 'This content cannot be edited here.', 'agentic-commerce-engine' ) );
		}

		$applied_types = get_post_meta( $product_id, Meta::APPLIED_TYPES, true );
		$applied_types = is_array( $applied_types ) ? $applied_types : array();
		if ( '' !== $type && ! in_array( $type, $applied_types, true ) ) {
			$applied_types[] = $type;
			update_post_meta( $product_id, Meta::APPLIED_TYPES, $applied_types );
		}

		Feeds::touch();
		return $notes;
	}

	/**
	 * Title, description or short description onto a loaded product, sanitized
	 * the one way apply() and write_edit() both use. Does not save.
	 *
	 * @param \WC_Product $product Product.
	 * @param string      $key     title|description|short_description.
	 * @param string      $value   Value.
	 */
	private static function set_core_field( $product, $key, $value ) {
		if ( 'title' === $key ) {
			$product->set_name( sanitize_text_field( $value ) );
		} elseif ( 'description' === $key ) {
			// Sections the merchant sends into the description (4.25.0) were
			// approved on their own and are not this write's to remove.
			$product->set_description( wp_kses_post( Content_Destinations::description_for( $product, $value ) ) );
		} elseif ( 'short_description' === $key ) {
			$product->set_short_description( wp_kses_post( $value ) );
		}
	}

	/**
	 * Highlights into their live meta. Never blanks what is live: a value that
	 * cleans down to nothing writes nothing.
	 *
	 * @param int   $product_id Product id.
	 * @param mixed $data       Any shape Pdp_Content reads.
	 * @return bool Written.
	 */
	private static function write_highlights( $product_id, $data ) {
		$highlights = Pdp_Content::highlights( $data );
		if ( empty( $highlights ) ) {
			return false;
		}
		update_post_meta( $product_id, Meta::HIGHLIGHTS, $highlights );
		return true;
	}

	/**
	 * Q&A pairs into their live meta, on the same terms as highlights.
	 *
	 * @param int   $product_id Product id.
	 * @param mixed $data       Any shape Pdp_Content reads.
	 * @return bool Written.
	 */
	private static function write_qa( $product_id, $data ) {
		$qa = Pdp_Content::qa( $data );
		if ( empty( $qa ) ) {
			return false;
		}
		update_post_meta( $product_id, Meta::QA, $qa );
		return true;
	}

	/**
	 * Alt text onto the product's own images, matched by URL.
	 *
	 * @param \WC_Product $product Product.
	 * @param mixed       $data    Any shape Pdp_Content reads.
	 * @param int|null    $matched Set to the number of images matched.
	 * @return int Images written.
	 */
	private static function write_image_alts( $product, $data, &$matched = null ) {
		$match   = Pdp_Content::match_image_alts(
			Pdp_Content::image_alts( $data ),
			Native_Fields::image_candidates( $product )
		);
		$matched = count( $match['matches'] );
		return Native_Fields::write_image_alts( (int) $product->get_id(), $match['matches'] );
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
		if (
			empty( $backup )
			&& ! Native_Fields::has_writes( $product_id )
			&& ! Custom_Fields::has_writes( $product_id )
			&& ! Content_Destinations::has_sections( get_post_field( 'post_content', $product_id ) )
		) {
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
		// Sections sent into the description (4.25.0) go with the rest of the
		// additive content, whether or not a backup was taken before them.
		$product->set_description( Content_Destinations::strip_sections( (string) $product->get_description( 'edit' ) ) );
		// Before the single save, so removing our attributes rides the same
		// write as the restored scalars. Removes exactly what we added and
		// leaves anything the merchant added afterwards alone.
		Native_Fields::undo( $product_id, $product );
		// Custom fields a rewrite wrote (4.24.0): restored where they still hold
		// our text, left alone where the merchant has changed them since.
		Custom_Fields::undo( $product_id, $product );
		$product->save();

		if ( isset( $backup['seo_title'] ) ) {
			$this->plugin->seo()->write_seo_title( $product_id, $backup['seo_title'] );
		}
		if ( isset( $backup['seo_description'] ) ) {
			$this->plugin->seo()->write_seo_description( $product_id, $backup['seo_description'] );
		}

		// Remove additive artifacts (schema/IRL/links/highlights/Q&A/feeds).
		// Attributes and alt text were already taken back by Native_Fields::undo.
		delete_post_meta( $product_id, Meta::JSONLD );
		delete_post_meta( $product_id, Meta::IRL );
		delete_post_meta( $product_id, Meta::LINKS );
		delete_post_meta( $product_id, Meta::HIGHLIGHTS );
		delete_post_meta( $product_id, Meta::QA );
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
	 * Capture original values once, before the first apply. Public for
	 * Content_Destinations, whose description sections are a first change too.
	 *
	 * @param \WC_Product $product Product.
	 */
	public function backup_once( $product ) {
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
