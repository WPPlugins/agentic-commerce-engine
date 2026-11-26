<?php
/**
 * The store's custom fields, as product content for Rezolve Ai.
 *
 * Merchants keep real product copy outside the default WooCommerce fields:
 * ACF fields, custom fields (post meta), product attributes, custom product
 * tabs and custom taxonomies such as brand. Rezolve Ai holds no WooCommerce
 * credentials for a plugin-connected store, so it cannot fetch any of that
 * itself: whatever the writer should know has to travel on the sync, and this
 * class is what puts it there.
 *
 * What is NEVER sent:
 *   - protected meta (keys starting with an underscore) and our own bookkeeping;
 *   - anything that looks private: costs, margins, supplier details, keys,
 *     tokens, passwords, email addresses, phone numbers, bank details. The
 *     patterns mirror src/lib/custom-fields/rules.ts, and the fixture
 *     tests/fixtures/custom-field-keys.json pins both to the same verdicts;
 *   - fields the merchant left out in Settings;
 *   - field types that hold no readable text (images, files, links, maps).
 *
 * Developers can add or remove fields with the `agencoen_custom_fields` filter,
 * and mark keys private with `agencoen_custom_field_is_private`.
 *
 * @package Agencoen
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Custom_Fields
 */
class Custom_Fields {

	/** Extraction scheme sent with every product. The platform appends its settings revision. */
	const SCHEME = 'wp-1';

	/** Most fields sent for one product. */
	const MAX_FIELDS = 60;

	/** Longest value sent, in characters. */
	const MAX_VALUE = 8000;

	/** Most bytes of custom fields sent for one product; lowest priority dropped first. */
	const MAX_BYTES = 49152;

	/** Taxonomies WooCommerce already sends, or that hold settings rather than copy. */
	const SKIP_TAXONOMIES = array( 'product_cat', 'product_tag', 'product_type', 'product_visibility', 'product_shipping_class' );

	/** ACF field types that never hold readable text. */
	const SKIP_ACF_TYPES = array( 'password', 'email', 'url', 'link', 'file', 'image', 'gallery', 'user', 'google_map', 'color_picker', 'oembed', 'message', 'tab', 'accordion', 'clone' );

	/** ACF field types a rewrite can safely write back. */
	const WRITABLE_ACF_TYPES = array( 'text', 'textarea', 'wysiwyg' );

	/**
	 * ACF fields holding one value picked from a list (4.28.0). Their choices
	 * are sent with the sync, and an empty one can be filled with a choice.
	 */
	const SINGLE_CHOICE_ACF_TYPES = array( 'select', 'radio', 'button_group' );

	/** Choices sent for one field, as Shopify caps a definition's. */
	const MAX_CHOICES = 128;

	/**
	 * Post meta written by other plugins that is configuration, not copy. The
	 * platform drops these too; skipping them here keeps the sync small.
	 */
	const SKIP_META_PREFIXES = array( 'rank_math', 'yoast', 'wpseo', 'aioseo', 'elementor', 'wpml', 'icl_', 'pll_', 'fb_', 'gtm', 'wc_', 'woocommerce', 'total_sales', 'agencoen', 'ace_', 'yikes_woo_products_tabs', 'product_brand', 'site-sidebar', 'site-content', 'theme-transparent', 'ast-', 'astra', 'om_disable', 'rs_page', 'slide_template', 'pyre_', 'sbg_', 'mfn-', 'dt_', 'the7', 'porto_', 'kadence', 'blocksy', 'generate_', 'neve_', 'ocean_', 'tm_', 'wp_', '_wp' );

	/**
	 * Private-looking words, matched on word boundaries. Mirrors PRIVATE_WORDS in
	 * src/lib/custom-fields/rules.ts; the shared fixture keeps the two in step.
	 */
	const PRIVATE_PATTERN = '/(^|[\W_])(cost|costs|cogs|margin|markup|wholesale|supplier|suppliers|purchase[\W_]?price|buy[\W_]?price|landed|profit|internal[\W_]?(notes?|use|only|comments?)|private[\W_]?(notes?|data|info|comments?)|secret|secrets|token|tokens|api[\W_]?key|apikey|password|passwd|pwd|auth|credential|credentials|licen[cs]e[\W_]?key|webhook|e[\W_]?mail|email|phone[\W_]?(no|number)|telephone|tel|mobile[\W_]?(no|number)|iban|swift|bank[\W_]?(account|details|name)|account[\W_]?(no|number)|staff[\W_]?notes?|admin[\W_]?notes?|fournisseurs?|proveedor(?:es)?|leveranciers?|lieferanten?|costo|coste|kosten|einkaufspreis|prix[\W_]?d[\W_]?achat|precio[\W_]?de[\W_]?compra|marge|margen)([\W_]|$)/i';

	/**
	 * Whether a key (or its label) looks private.
	 *
	 * @param string $key   Field key.
	 * @param string $label Optional human label.
	 * @return bool
	 */
	public static function is_private_key( $key, $label = '' ) {
		$private = (bool) preg_match( self::PRIVATE_PATTERN, self::word_breaks( (string) $key ) )
			|| ( '' !== (string) $label && (bool) preg_match( self::PRIVATE_PATTERN, self::word_breaks( (string) $label ) ) );
		/**
		 * Mark a custom field private (never sent) or not.
		 *
		 * @param bool   $private Current verdict.
		 * @param string $key     Field key.
		 * @param string $label   Field label, may be empty.
		 */
		return (bool) apply_filters( 'agencoen_custom_field_is_private', $private, (string) $key, (string) $label );
	}

	/**
	 * camelCase to snake_case, so word boundaries exist.
	 *
	 * @param string $value Key or label.
	 * @return string
	 */
	private static function word_breaks( $value ) {
		return (string) preg_replace( '/([a-z0-9])([A-Z])/', '$1_$2', $value );
	}

	/**
	 * Stable identity of a field, matching fieldRefId() on the platform.
	 *
	 * @param string $source    Source.
	 * @param string $namespace Namespace.
	 * @param string $key       Key.
	 * @return string
	 */
	public static function field_id( $source, $namespace, $key ) {
		return $source . ':' . $namespace . '.' . $key;
	}

	/**
	 * Every custom field of a product worth sending, in priority order.
	 *
	 * @param \WC_Product $product  Product.
	 * @param string[]    $excluded Field ids the merchant left out.
	 * @return array[] Each { namespace, key, value, type, source, label?, text?, writable? }.
	 */
	public static function collect( $product, array $excluded = array() ) {
		$product_id = $product->get_id();
		$fields     = array();
		$acf_names  = array();

		foreach ( self::acf_fields( $product_id, $acf_names ) as $field ) {
			$fields[] = $field;
		}
		foreach ( self::tab_fields( $product_id ) as $field ) {
			$fields[] = $field;
		}
		foreach ( self::attribute_fields( $product ) as $field ) {
			$fields[] = $field;
		}
		foreach ( self::taxonomy_fields( $product_id ) as $field ) {
			$fields[] = $field;
		}
		foreach ( self::meta_fields( $product_id, $acf_names ) as $field ) {
			$fields[] = $field;
		}

		/**
		 * Add, change or remove the custom fields sent for a product.
		 *
		 * Each entry: namespace, key, value (string), type, source, and
		 * optionally label, text and writable. Private-looking keys and fields
		 * the merchant left out are removed after this filter runs.
		 *
		 * @param array[]     $fields  Collected fields.
		 * @param \WC_Product $product Product.
		 */
		$fields = (array) apply_filters( 'agencoen_custom_fields', $fields, $product );

		$excluded_set = array_flip( $excluded );
		$out          = array();
		$bytes        = 0;
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || empty( $field['namespace'] ) || empty( $field['key'] ) || empty( $field['type'] ) ) {
				continue;
			}
			$source = isset( $field['source'] ) ? (string) $field['source'] : 'wp_meta';
			if ( isset( $excluded_set[ self::field_id( $source, (string) $field['namespace'], (string) $field['key'] ) ] ) ) {
				continue;
			}
			$label = isset( $field['label'] ) ? (string) $field['label'] : '';
			if ( self::is_private_key( (string) $field['key'], $label ) ) {
				continue;
			}
			$value = isset( $field['value'] ) ? self::clip( (string) $field['value'] ) : '';
			$text  = isset( $field['text'] ) ? self::clip( (string) $field['text'] ) : '';
			if ( '' === trim( $value ) && '' === trim( $text ) ) {
				continue;
			}

			$entry = array(
				'namespace' => (string) $field['namespace'],
				'key'       => (string) $field['key'],
				'value'     => $value,
				'type'      => (string) $field['type'],
				'source'    => $source,
			);
			if ( '' !== $label ) {
				$entry['label'] = $label;
			}
			if ( '' !== $text ) {
				$entry['text'] = $text;
			}
			if ( ! empty( $field['writable'] ) ) {
				$entry['writable'] = true;
			}
			if ( ! empty( $field['choices'] ) && is_array( $field['choices'] ) ) {
				$entry['choices'] = array_slice( array_values( array_filter( array_map( 'strval', array_filter( $field['choices'], 'is_scalar' ) ), 'strlen' ) ), 0, self::MAX_CHOICES );
			}

			$size = strlen( (string) wp_json_encode( $entry ) );
			if ( $bytes + $size > self::MAX_BYTES ) {
				break;
			}
			$bytes += $size;
			$out[]  = $entry;
			if ( count( $out ) >= self::MAX_FIELDS ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Cut a value to MAX_VALUE characters.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function clip( $value ) {
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, self::MAX_VALUE ) : substr( $value, 0, self::MAX_VALUE );
	}

	/* --------------------------------------------------------------------- */
	/* Rewrites: write back and undo (4.24.0)                                */
	/* --------------------------------------------------------------------- */

	/** Sources a rewrite may write back to. Attributes, tabs and taxonomies are read-only. */
	const WRITABLE_SOURCES = array( 'acf', 'wp_meta', 'wc_core' );

	/**
	 * The value of a field as the sync reads it, so its hash matches the one
	 * the suggestion was written from.
	 *
	 * @param int         $product_id Product id.
	 * @param string      $source     Source.
	 * @param string      $key        Key.
	 * @param \WC_Product $product    Loaded product, for the short description.
	 * @return string|null Null when the field cannot be read.
	 */
	public static function current_value( $product_id, $source, $key, $product = null ) {
		if ( 'wc_core' === $source ) {
			if ( 'short_description' !== $key || ! $product ) {
				return null;
			}
			return trim( (string) $product->get_short_description() );
		}
		if ( 'acf' === $source && function_exists( 'get_field' ) ) {
			$value = get_field( $key, $product_id, false );
			return is_scalar( $value ) ? (string) $value : ( null === $value ? '' : null );
		}
		$value = get_post_meta( $product_id, $key, true );
		return is_scalar( $value ) ? trim( (string) $value ) : ( '' === $value ? '' : null );
	}

	/**
	 * A rewrite, cleaned for the field's format.
	 *
	 * @param string $format plain | multiline | html.
	 * @param string $value  Suggested value.
	 * @return string
	 */
	public static function clean_for_format( $format, $value ) {
		switch ( $format ) {
			case 'plain':
				return sanitize_text_field( $value );
			case 'multiline':
				return sanitize_textarea_field( $value );
			case 'html':
				return wp_kses_post( $value );
			default:
				return '';
		}
	}

	/**
	 * A field's value as lines a merchant can read on Review: HTML block ends
	 * and <br> become line breaks, list items become bullets, other tags go,
	 * common entities are decoded, and each line is trimmed.
	 *
	 * Mirrors customFieldDisplayText in the platform's
	 * src/lib/custom-fields/rules.ts, which the web and Shopify Review screens
	 * use; tests/fixtures/custom-field-display.json pins both to the same text.
	 * The result is text, not markup: escape it when it is drawn.
	 *
	 * @param mixed $value Stored or suggested value.
	 * @return string
	 */
	public static function display_text( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$text = (string) preg_replace( '#<br\s*/?>#i', "\n", $value );
		$text = (string) preg_replace( '#</(p|div|li|h[1-6])>#i', "\n", $text );
		$text = (string) preg_replace( '#<li[^>]*>#i', "\u{2022} ", $text );
		$text = (string) preg_replace( '#<[^>]+>#', '', $text );
		$text = str_replace( array( '&nbsp;', '&lt;', '&gt;', '&quot;', '&#39;', '&#039;' ), array( ' ', '<', '>', '"', "'", "'" ), $text );
		// Last, so "&amp;lt;" reads "&lt;" rather than "<".
		$text  = str_replace( '&amp;', '&', $text );
		$lines = array_map(
			function ( $line ) {
				return trim( (string) preg_replace( '/[ \t]+/', ' ', $line ) );
			},
			explode( "\n", $text )
		);
		return trim( (string) preg_replace( "/\n{3,}/", "\n\n", implode( "\n", $lines ) ) );
	}

	/**
	 * Write approved rewrites of a product's custom fields.
	 *
	 * Each field is written only if it still holds the value the suggestion was
	 * written from (`beforeHash`), so an edit the merchant made since is never
	 * overwritten. Private-looking and read-only fields are refused whatever
	 * the payload says.
	 *
	 * @param int   $product_id Product id.
	 * @param array $fields     Wire entries { ref, source, namespace, key, type, format, value, beforeHash,
	 *                          fill?, choice?, acfKey? }. A fill writes a field the product
	 *                          does not have yet; a choice is a label from the field's list.
	 * @return array { written: int, skipped: string[] } Skipped are merchant-facing reasons.
	 */
	public static function write_rewrites( $product_id, array $fields ) {
		$product_id = (int) $product_id;
		$product    = wc_get_product( $product_id );
		$ledger     = get_post_meta( $product_id, Meta::CUSTOM_FIELD_WRITES, true );
		$ledger     = is_array( $ledger ) ? $ledger : array();
		$written    = 0;
		$skipped    = array();
		$save       = false;

		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$source = isset( $field['source'] ) ? (string) $field['source'] : '';
			$key    = isset( $field['key'] ) ? (string) $field['key'] : '';
			$label  = isset( $field['label'] ) && '' !== (string) $field['label'] ? (string) $field['label'] : $key;
			if ( ! in_array( $source, self::WRITABLE_SOURCES, true ) || '' === $key || self::is_private_key( $key ) ) {
				$skipped[] = $label . ': ' . __( 'Rezolve Ai is not allowed to edit this field.', 'agentic-commerce-engine' );
				continue;
			}
			if ( 'wp_meta' === $source && ( is_protected_meta( $key, 'post' ) || self::skipped_meta_key( $key ) ) ) {
				$skipped[] = $label . ': ' . __( 'Rezolve Ai is not allowed to edit this field.', 'agentic-commerce-engine' );
				continue;
			}
			$value = self::clean_for_format( isset( $field['format'] ) ? (string) $field['format'] : '', isset( $field['value'] ) ? (string) $field['value'] : '' );
			if ( '' === trim( $value ) ) {
				$skipped[] = $label . ': ' . __( 'This suggestion has nothing left to publish. Generate it again.', 'agentic-commerce-engine' );
				continue;
			}
			$current = self::current_value( $product_id, $source, $key, $product );
			if ( null === $current ) {
				$skipped[] = $label . ': ' . __( 'This kind of field cannot be rewritten.', 'agentic-commerce-engine' );
				continue;
			}
			$before_hash = isset( $field['beforeHash'] ) ? (string) $field['beforeHash'] : '';
			if ( '' !== $before_hash && ! hash_equals( $before_hash, hash( 'sha256', $current ) ) ) {
				$skipped[] = $label . ': ' . __( 'This field changed in WordPress after the suggestion was written. Generate it again.', 'agentic-commerce-engine' );
				continue;
			}

			// A fill writes a field the product did not have (4.28.0), and a
			// choice is a value picked from the field's own list.
			$fill   = ! empty( $field['fill'] );
			$choice = ! empty( $field['choice'] );
			if ( $fill && '' !== trim( (string) $current ) ) {
				$skipped[] = $label . ': ' . __( 'This field has a value now. Generate it again.', 'agentic-commerce-engine' );
				continue;
			}
			if ( $choice && 'acf' !== $source ) {
				$skipped[] = $label . ': ' . __( 'Rezolve Ai is not allowed to edit this field.', 'agentic-commerce-engine' );
				continue;
			}

			// ACF's own field key when the caller knows it (a content destination
			// chosen from the field group, 4.25.0), or looked up for a fill: by
			// name alone ACF cannot save a value on a product that has none yet.
			$acf_key = isset( $field['acfKey'] ) && is_string( $field['acfKey'] ) && preg_match( '/^field_[A-Za-z0-9]{1,64}$/', $field['acfKey'] ) ? $field['acfKey'] : '';
			$stored  = $value;
			if ( 'acf' === $source && ( $fill || $choice ) ) {
				$object = function_exists( 'acf_get_field' ) ? acf_get_field( '' !== $acf_key ? $acf_key : $key ) : false;
				if ( ! is_array( $object ) || empty( $object['key'] ) ) {
					$skipped[] = $label . ': ' . __( 'This field is not in your ACF field groups any more.', 'agentic-commerce-engine' );
					continue;
				}
				$acf_key = (string) $object['key'];
				$type    = isset( $object['type'] ) ? (string) $object['type'] : '';
				if ( $choice ) {
					$picked = self::single_choice_labels( $object ) ? self::choice_value( $object, $value ) : null;
					if ( null === $picked ) {
						$skipped[] = $label . ': ' . __( 'This value is not one of the field\'s choices any more. Generate it again.', 'agentic-commerce-engine' );
						continue;
					}
					$stored = $picked;
				} elseif ( ! in_array( $type, self::WRITABLE_ACF_TYPES, true ) ) {
					$skipped[] = $label . ': ' . __( 'This kind of field cannot be rewritten.', 'agentic-commerce-engine' );
					continue;
				}
			}

			if ( 'wc_core' === $source ) {
				$product->set_short_description( $stored );
				$save = true;
			} elseif ( 'acf' === $source && function_exists( 'update_field' ) ) {
				update_field( '' !== $acf_key ? $acf_key : $key, $stored, $product_id );
			} else {
				update_post_meta( $product_id, $key, wp_slash( $stored ) );
			}

			$id = self::field_id( $source, isset( $field['namespace'] ) ? (string) $field['namespace'] : '', $key );
			$ledger[ $id ] = array(
				'source'   => $source,
				'key'      => $key,
				// The EARLIEST previous value wins: it is the merchant's original.
				'previous' => isset( $ledger[ $id ]['previous'] ) ? $ledger[ $id ]['previous'] : $current,
				'written'  => $stored,
			);
			++$written;
		}

		if ( $save && $product ) {
			$product->save();
		}
		if ( $written > 0 ) {
			update_post_meta( $product_id, Meta::CUSTOM_FIELD_WRITES, $ledger );
		}
		return array(
			'written' => $written,
			'skipped' => $skipped,
		);
	}

	/**
	 * Whether a product has custom-field writes to undo.
	 *
	 * @param int $product_id Product id.
	 * @return bool
	 */
	public static function has_writes( $product_id ) {
		$ledger = get_post_meta( (int) $product_id, Meta::CUSTOM_FIELD_WRITES, true );
		return is_array( $ledger ) && ! empty( $ledger );
	}

	/**
	 * Undo custom-field rewrites: restore each field that still holds exactly
	 * what was written, leave any field the merchant changed since, then clear
	 * the ledger.
	 *
	 * @param int         $product_id Product id.
	 * @param \WC_Product $product    Loaded product; saved by the caller when given.
	 * @return int Fields restored.
	 */
	public static function undo( $product_id, $product = null ) {
		$product_id = (int) $product_id;
		$ledger     = get_post_meta( $product_id, Meta::CUSTOM_FIELD_WRITES, true );
		if ( ! is_array( $ledger ) || empty( $ledger ) ) {
			return 0;
		}
		$own_product = null === $product;
		if ( $own_product ) {
			$product = wc_get_product( $product_id );
		}
		$restored = 0;
		$save     = false;
		foreach ( $ledger as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['key'] ) || empty( $entry['source'] ) ) {
				continue;
			}
			$current = self::current_value( $product_id, (string) $entry['source'], (string) $entry['key'], $product );
			if ( null === $current || (string) $current !== trim( (string) $entry['written'] ) ) {
				continue;
			}
			$previous = isset( $entry['previous'] ) ? (string) $entry['previous'] : '';
			if ( 'wc_core' === $entry['source'] ) {
				$product->set_short_description( $previous );
				$save = true;
			} elseif ( 'acf' === $entry['source'] && '' === $previous && function_exists( 'delete_field' ) ) {
				// A field Rezolve Ai filled goes back to having no value at all.
				delete_field( (string) $entry['key'], $product_id );
			} elseif ( 'acf' === $entry['source'] && function_exists( 'update_field' ) ) {
				update_field( (string) $entry['key'], $previous, $product_id );
			} elseif ( '' === $previous ) {
				delete_post_meta( $product_id, (string) $entry['key'] );
			} else {
				update_post_meta( $product_id, (string) $entry['key'], wp_slash( $previous ) );
			}
			++$restored;
		}
		if ( $save && $own_product && $product ) {
			$product->save();
		}
		delete_post_meta( $product_id, Meta::CUSTOM_FIELD_WRITES );
		return $restored;
	}

	/* --------------------------------------------------------------------- */
	/* ACF                                                                   */
	/* --------------------------------------------------------------------- */

	/**
	 * ACF fields, when ACF is active. Values are read raw and resolved here, so
	 * a select sends its choice label and a relationship its post titles.
	 *
	 * @param int      $product_id Product id.
	 * @param string[] $acf_names  Filled with the meta keys ACF owns.
	 * @return array[]
	 */
	private static function acf_fields( $product_id, array &$acf_names ) {
		if ( ! function_exists( 'get_field_objects' ) ) {
			return array();
		}
		$objects = get_field_objects( $product_id, false );
		if ( ! is_array( $objects ) ) {
			return array();
		}
		$out = array();
		foreach ( $objects as $object ) {
			if ( ! is_array( $object ) || empty( $object['name'] ) ) {
				continue;
			}
			$acf_names[] = (string) $object['name'];
			$type        = isset( $object['type'] ) ? (string) $object['type'] : 'text';
			if ( in_array( $type, self::SKIP_ACF_TYPES, true ) ) {
				continue;
			}
			$text = self::acf_text( $object );
			if ( '' === $text ) {
				continue;
			}
			$writable = in_array( $type, self::WRITABLE_ACF_TYPES, true ) && is_string( $object['value'] );
			$entry    = array(
				'namespace' => 'acf',
				'key'       => (string) $object['name'],
				'value'     => $writable ? (string) $object['value'] : $text,
				'type'      => 'acf:' . $type,
				'source'    => 'acf',
				'label'     => isset( $object['label'] ) ? wp_strip_all_tags( (string) $object['label'] ) : '',
				'writable'  => $writable,
			);
			// A single-choice field sends its choices (the labels it shows), so
			// a product without a value can be filled with one of them. Its value
			// stays the label it always sent.
			$choices = self::single_choice_labels( $object );
			if ( $choices ) {
				$entry['choices']  = $choices;
				$entry['writable'] = true;
			}
			$out[] = $entry;
		}
		return $out;
	}

	/**
	 * The choice labels of a single-choice ACF field, or none.
	 *
	 * @param array $object ACF field object (or field settings).
	 * @return string[]
	 */
	public static function single_choice_labels( array $object ) {
		$type = isset( $object['type'] ) ? (string) $object['type'] : '';
		if ( ! in_array( $type, self::SINGLE_CHOICE_ACF_TYPES, true ) || ! empty( $object['multiple'] ) ) {
			return array();
		}
		$choices = isset( $object['choices'] ) && is_array( $object['choices'] ) ? $object['choices'] : array();
		$labels  = array();
		foreach ( $choices as $value => $label ) {
			// A choice with no label shows its value, as ACF does.
			$text = trim( wp_strip_all_tags( is_scalar( $label ) ? (string) $label : '' ) );
			if ( '' === $text ) {
				$text = trim( (string) $value );
			}
			if ( '' !== $text ) {
				$labels[] = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 256 ) : substr( $text, 0, 256 );
			}
		}
		return array_slice( array_values( array_unique( $labels ) ), 0, self::MAX_CHOICES );
	}

	/**
	 * The stored value of a choice, from the label (or value) the platform
	 * picked. Null when it is not one of the field's choices.
	 *
	 * @param array  $object ACF field settings.
	 * @param string $picked Picked label.
	 * @return string|null
	 */
	public static function choice_value( array $object, $picked ) {
		$choices = isset( $object['choices'] ) && is_array( $object['choices'] ) ? $object['choices'] : array();
		$picked  = trim( (string) $picked );
		foreach ( $choices as $value => $label ) {
			if ( is_scalar( $label ) && trim( wp_strip_all_tags( (string) $label ) ) === $picked ) {
				return (string) $value;
			}
		}
		return array_key_exists( $picked, $choices ) ? $picked : null;
	}

	/**
	 * Readable text of one ACF field object (raw value).
	 *
	 * @param array $object ACF field object.
	 * @param int   $depth  Nesting depth; groups and repeaters flatten one level.
	 * @return string
	 */
	private static function acf_text( array $object, $depth = 0 ) {
		$type  = isset( $object['type'] ) ? (string) $object['type'] : 'text';
		$value = isset( $object['value'] ) ? $object['value'] : null;
		if ( null === $value || '' === $value || array() === $value ) {
			return '';
		}

		switch ( $type ) {
			case 'select':
			case 'checkbox':
			case 'radio':
			case 'button_group':
				$choices = isset( $object['choices'] ) && is_array( $object['choices'] ) ? $object['choices'] : array();
				$labels  = array();
				foreach ( (array) $value as $choice ) {
					if ( is_scalar( $choice ) ) {
						$labels[] = isset( $choices[ $choice ] ) ? (string) $choices[ $choice ] : (string) $choice;
					}
				}
				return implode( ', ', array_filter( $labels, 'strlen' ) );

			case 'true_false':
				return $value ? __( 'Yes', 'agentic-commerce-engine' ) : __( 'No', 'agentic-commerce-engine' );

			case 'date_picker':
				$date = \DateTime::createFromFormat( 'Ymd', (string) $value );
				return $date ? $date->format( 'Y-m-d' ) : (string) $value;

			case 'relationship':
			case 'post_object':
			case 'page_link':
				$titles = array();
				foreach ( (array) $value as $post_id ) {
					if ( is_numeric( $post_id ) ) {
						$title = get_the_title( (int) $post_id );
						if ( '' !== $title ) {
							$titles[] = $title;
						}
					}
				}
				return implode( ', ', $titles );

			case 'taxonomy':
				$names = array();
				foreach ( (array) $value as $term_id ) {
					$term = is_numeric( $term_id ) ? get_term( (int) $term_id ) : null;
					if ( $term && ! is_wp_error( $term ) ) {
						$names[] = $term->name;
					}
				}
				return implode( ', ', $names );

			case 'group':
			case 'repeater':
			case 'flexible_content':
				if ( $depth > 0 || ! is_array( $value ) ) {
					return '';
				}
				$sub_fields = isset( $object['sub_fields'] ) && is_array( $object['sub_fields'] ) ? $object['sub_fields'] : array();
				$by_key     = array();
				foreach ( $sub_fields as $sub ) {
					if ( isset( $sub['key'] ) ) {
						$by_key[ $sub['key'] ] = $sub;
					}
				}
				$rows = 'group' === $type ? array( $value ) : $value;
				$out  = array();
				foreach ( $rows as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					$parts = array();
					foreach ( $row as $sub_key => $sub_value ) {
						$sub = isset( $by_key[ $sub_key ] ) ? $by_key[ $sub_key ] : null;
						if ( ! $sub || in_array( (string) $sub['type'], self::SKIP_ACF_TYPES, true ) ) {
							continue;
						}
						$sub['value'] = $sub_value;
						$text         = self::acf_text( $sub, $depth + 1 );
						if ( '' !== $text && ! self::is_private_key( (string) $sub['name'], (string) $sub['label'] ) ) {
							$parts[] = wp_strip_all_tags( (string) $sub['label'] ) . ': ' . $text;
						}
					}
					if ( $parts ) {
						$out[] = implode( '; ', $parts );
					}
				}
				return implode( "\n", $out );

			default:
				if ( is_array( $value ) || is_object( $value ) ) {
					return '';
				}
				return trim( (string) $value );
		}
	}

	/* --------------------------------------------------------------------- */
	/* Tabs, attributes, taxonomies                                          */
	/* --------------------------------------------------------------------- */

	/**
	 * Custom product tabs from "Custom Product Tabs for WooCommerce" (YIKES).
	 *
	 * @param int $product_id Product id.
	 * @return array[]
	 */
	private static function tab_fields( $product_id ) {
		$tabs = get_post_meta( $product_id, 'yikes_woo_products_tabs', true );
		if ( ! is_array( $tabs ) ) {
			return array();
		}
		$out = array();
		foreach ( $tabs as $tab ) {
			if ( ! is_array( $tab ) || empty( $tab['content'] ) ) {
				continue;
			}
			$title = isset( $tab['title'] ) ? (string) $tab['title'] : '';
			$key   = ! empty( $tab['id'] ) ? (string) $tab['id'] : sanitize_title( $title );
			if ( '' === $key ) {
				continue;
			}
			$out[] = array(
				'namespace' => 'tab',
				'key'       => $key,
				'value'     => (string) $tab['content'],
				'type'      => 'wc:tab_html',
				'source'    => 'wc_tab',
				'label'     => $title,
			);
		}
		return $out;
	}

	/**
	 * Product-level attributes that are not variation axes (those already ride
	 * on the variations), global and custom alike: "Material: Merino wool".
	 *
	 * @param \WC_Product $product Product.
	 * @return array[]
	 */
	private static function attribute_fields( $product ) {
		$out        = array();
		$is_variable = $product->is_type( 'variable' );
		foreach ( (array) $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute ) {
				continue;
			}
			if ( $is_variable && $attribute->get_variation() ) {
				continue;
			}
			$name   = $attribute->get_name();
			$values = $attribute->is_taxonomy()
				? wc_get_product_terms( $product->get_id(), $name, array( 'fields' => 'names' ) )
				: $attribute->get_options();
			$values = array_filter( array_map( 'strval', (array) $values ), 'strlen' );
			if ( ! $values ) {
				continue;
			}
			$out[] = array(
				'namespace' => 'attribute',
				'key'       => sanitize_title( $name ),
				'value'     => implode( ', ', $values ),
				'type'      => 'wc:attribute',
				'source'    => 'wc_attribute',
				'label'     => wc_attribute_label( $name, $product ),
			);
		}
		return $out;
	}

	/**
	 * Custom product taxonomies (WooCommerce's own Brands among them).
	 *
	 * @param int $product_id Product id.
	 * @return array[]
	 */
	private static function taxonomy_fields( $product_id ) {
		$out = array();
		foreach ( get_object_taxonomies( 'product', 'objects' ) as $taxonomy ) {
			$name = $taxonomy->name;
			if ( in_array( $name, self::SKIP_TAXONOMIES, true ) || 0 === strpos( $name, 'pa_' ) ) {
				continue;
			}
			if ( empty( $taxonomy->public ) && empty( $taxonomy->show_ui ) ) {
				continue;
			}
			$terms = wp_get_post_terms( $product_id, $name, array( 'fields' => 'names' ) );
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}
			$label = 'product_brand' === $name
				? __( 'Brand', 'agentic-commerce-engine' )
				: ( ! empty( $taxonomy->labels->singular_name ) ? $taxonomy->labels->singular_name : $taxonomy->label );
			$out[] = array(
				'namespace' => 'taxonomy',
				'key'       => $name,
				'value'     => implode( ', ', array_map( 'strval', $terms ) ),
				'type'      => 'wc:taxonomy',
				'source'    => 'wc_taxonomy',
				'label'     => (string) $label,
			);
		}
		return $out;
	}

	/* --------------------------------------------------------------------- */
	/* Post meta                                                             */
	/* --------------------------------------------------------------------- */

	/**
	 * Public custom fields (post meta) that are not protected, not owned by
	 * ACF and not known plugin configuration.
	 *
	 * @param int      $product_id Product id.
	 * @param string[] $acf_names  Meta keys ACF owns.
	 * @return array[]
	 */
	private static function meta_fields( $product_id, array $acf_names ) {
		$all = get_post_meta( $product_id );
		if ( ! is_array( $all ) ) {
			return array();
		}
		$acf = array_flip( $acf_names );
		$out = array();
		foreach ( $all as $key => $values ) {
			$key = (string) $key;
			if ( isset( $acf[ $key ] ) || is_protected_meta( $key, 'post' ) || self::skipped_meta_key( $key ) ) {
				continue;
			}
			$raw   = is_array( $values ) && isset( $values[0] ) ? maybe_unserialize( $values[0] ) : null;
			$value = '';
			$plain = false;
			if ( is_scalar( $raw ) ) {
				$value = trim( (string) $raw );
				$plain = true;
			} elseif ( is_array( $raw ) ) {
				$items = array();
				foreach ( $raw as $item ) {
					if ( ! is_scalar( $item ) ) {
						$items = array();
						break;
					}
					$items[] = trim( (string) $item );
				}
				$value = implode( ', ', array_filter( $items, 'strlen' ) );
			}
			if ( '' === $value ) {
				continue;
			}
			$out[] = array(
				'namespace' => 'meta',
				'key'       => $key,
				'value'     => $value,
				'type'      => ( $plain && $value !== wp_strip_all_tags( $value ) ) ? 'wp:html' : 'wp:string',
				'source'    => 'wp_meta',
				'writable'  => $plain,
			);
		}
		return $out;
	}

	/**
	 * Whether a meta key belongs to a known plugin's configuration.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	public static function skipped_meta_key( $key ) {
		$lower = strtolower( $key );
		foreach ( self::SKIP_META_PREFIXES as $prefix ) {
			if ( 0 === strpos( $lower, $prefix ) ) {
				return true;
			}
		}
		return false;
	}
}
