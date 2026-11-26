<?php
/**
 * What a merchant may type into a proposal on the Review screen (4.18.0).
 *
 * WHY THIS FILE EXISTS
 * Three surfaces edit the same proposals: the web review page, the Shopify
 * app's Review screen and this plugin's. An edit made here is reported to
 * Rezolve Ai with the review decisions, and from there reaches the apply queue,
 * the feed export and both storefronts. If this plugin accepted a value the
 * platform refuses, the edit would save here and silently never arrive there.
 *
 * THE LIMITS ARE MIRRORED, NOT CHOSEN HERE
 * They are `EDIT_LIMITS` in `src/lib/review-edit-values.ts`, the strictest any
 * surface enforces. PHP cannot import that file, so the numbers below are a
 * copy, and `src/lib/__tests__/review-edit-values.guard.test.ts` reads this
 * source and fails the build if any constant stops matching.
 *
 * REFUSED, NEVER TRUNCATED
 * A blank row is dropped silently: it is what an editor leaves behind when a
 * merchant clicks "add" and changes their mind. Anything the merchant DID type
 * that cannot be published (half a pair, a row over a limit) is an error with
 * the row's index, so the screen can mark the row. Silently cutting what a
 * merchant typed is worse than telling them.
 *
 * PLAIN TEXT EXCEPT THE DESCRIPTION
 * The storefront escapes highlights, answers, specifications and alt text, so
 * markup in them would print as literal tags. They are cleaned with the same
 * WordPress sanitizers {@see Pdp_Content} and {@see Native_Fields} use, and the
 * limits are counted AFTER cleaning, so what is checked is exactly what Apply
 * writes. The description is HTML and goes through wp_kses_post().
 *
 * FIELD VOCABULARY
 * Edits are keyed by the plugin FIELD names the review-decisions report already
 * uses (`title`, `description`, `seo_title`, `seo_description`, `highlights`,
 * `product_qa`, `product_attributes`, `image_alt`), not by content type, because
 * SEO title and meta description are one content type but two fields a merchant
 * edits and the platform records separately.
 *
 * @package Agencoen
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Edit_Values
 */
final class Edit_Values {

	/* Mirrors EDIT_LIMITS in src/lib/review-edit-values.ts. Pinned by a guard test. */
	const HIGHLIGHTS_MAX_ITEMS              = 10;
	const HIGHLIGHT_MAX_CHARS               = 300;
	const QA_MAX_PAIRS                      = 20;
	const QA_MAX_QUESTION_CHARS             = 300;
	const QA_MAX_ANSWER_CHARS               = 2000;
	const ATTRIBUTES_MAX_ROWS               = 20;
	const ATTRIBUTE_MAX_NAME_CHARS          = 200;
	const ATTRIBUTE_MAX_VALUE_CHARS         = 200;
	const ALT_MAX_CHARS                     = 512;
	const ALT_RECOMMENDED_CHARS             = 125;
	const TITLE_MAX_CHARS                   = 255;
	const TITLE_RECOMMENDED_CHARS           = 70;
	const SEO_TITLE_MAX_CHARS               = 255;
	const SEO_TITLE_RECOMMENDED_CHARS       = 60;
	const SEO_DESCRIPTION_MAX_CHARS         = 1000;
	const SEO_DESCRIPTION_RECOMMENDED_CHARS = 160;
	const DESCRIPTION_HTML_MAX_CHARS        = 100000;

	/** Every field with an editor on the Review screen. */
	const FIELDS = array( 'title', 'description', 'seo_title', 'seo_description', 'highlights', 'product_qa', 'product_attributes', 'image_alt' );

	/** Fields whose value travels as a JSON list. */
	const STRUCTURED = array( 'highlights', 'product_qa', 'product_attributes', 'image_alt' );

	/**
	 * Apply-queue wire field => the edited field it would overwrite.
	 *
	 * Used by {@see Apply_Queue} to hold back an approval made in Rezolve Ai
	 * for content the merchant has edited here since.
	 */
	const WIRE_FIELDS = array(
		'title'           => 'title',
		'descriptionHtml' => 'description',
		'seoTitle'        => 'seo_title',
		'seoDescription'  => 'seo_description',
		'highlights'      => 'highlights',
		'productQa'       => 'product_qa',
		'productAttributes' => 'product_attributes',
		'productSpecs'    => 'product_attributes',
		'imageAlt'        => 'image_alt',
	);

	/** List field => its `_agencoen_content` engine key. */
	const CONTENT_KEYS = array(
		'highlights'         => 'highlights',
		'product_qa'         => 'product-qa',
		'product_attributes' => 'product-attributes',
		'image_alt'          => 'image-alt',
	);

	/**
	 * The limits the screen script shows in its counters. The server checks
	 * again whatever the browser did.
	 *
	 * @return array
	 */
	public static function limits() {
		return array(
			'highlights'     => array(
				'maxItems' => self::HIGHLIGHTS_MAX_ITEMS,
				'maxChars' => self::HIGHLIGHT_MAX_CHARS,
			),
			'qa'             => array(
				'maxPairs'         => self::QA_MAX_PAIRS,
				'maxQuestionChars' => self::QA_MAX_QUESTION_CHARS,
				'maxAnswerChars'   => self::QA_MAX_ANSWER_CHARS,
			),
			'attributes'     => array(
				'maxRows'       => self::ATTRIBUTES_MAX_ROWS,
				'maxNameChars'  => self::ATTRIBUTE_MAX_NAME_CHARS,
				'maxValueChars' => self::ATTRIBUTE_MAX_VALUE_CHARS,
			),
			'alt'            => array(
				'maxChars'         => self::ALT_MAX_CHARS,
				'recommendedChars' => self::ALT_RECOMMENDED_CHARS,
			),
			'title'          => array(
				'maxChars'         => self::TITLE_MAX_CHARS,
				'recommendedChars' => self::TITLE_RECOMMENDED_CHARS,
			),
			'seoTitle'       => array(
				'maxChars'         => self::SEO_TITLE_MAX_CHARS,
				'recommendedChars' => self::SEO_TITLE_RECOMMENDED_CHARS,
			),
			'seoDescription' => array(
				'maxChars'         => self::SEO_DESCRIPTION_MAX_CHARS,
				'recommendedChars' => self::SEO_DESCRIPTION_RECOMMENDED_CHARS,
			),
			'descriptionHtml' => array(
				'maxChars' => self::DESCRIPTION_HTML_MAX_CHARS,
			),
		);
	}

	/**
	 * @param string $field Field name.
	 * @return bool
	 */
	public static function is_field( $field ) {
		return in_array( (string) $field, self::FIELDS, true );
	}

	/**
	 * @param string $field Field name.
	 * @return bool
	 */
	public static function is_structured( $field ) {
		return in_array( (string) $field, self::STRUCTURED, true );
	}

	/**
	 * Clean and validate an edited value.
	 *
	 * @param string $field Field name, one of self::FIELDS.
	 * @param mixed  $raw   Decoded list for structured fields, a string otherwise.
	 * @return array{ok:bool,value:mixed,errors:array<int,array{index?:int,part?:string,message:string}>}
	 */
	public static function normalize( $field, $raw ) {
		switch ( (string) $field ) {
			case 'highlights':
				return self::normalize_highlights( $raw );
			case 'product_qa':
				return self::normalize_qa( $raw );
			case 'product_attributes':
				return self::normalize_attributes( $raw );
			case 'image_alt':
				return self::normalize_alt( $raw );
			case 'title':
				return self::normalize_line(
					$raw,
					self::TITLE_MAX_CHARS,
					__( 'The title cannot be empty.', 'agentic-commerce-engine' ),
					/* translators: %d: maximum number of characters. */
					__( 'The title is longer than %d characters. Shorten it to save.', 'agentic-commerce-engine' )
				);
			case 'seo_title':
				return self::normalize_line(
					$raw,
					self::SEO_TITLE_MAX_CHARS,
					__( 'Add an SEO title.', 'agentic-commerce-engine' ),
					/* translators: %d: maximum number of characters. */
					__( 'The SEO title is longer than %d characters. Shorten it to save.', 'agentic-commerce-engine' )
				);
			case 'seo_description':
				return self::normalize_line(
					$raw,
					self::SEO_DESCRIPTION_MAX_CHARS,
					__( 'Add a meta description.', 'agentic-commerce-engine' ),
					/* translators: %d: maximum number of characters. */
					__( 'The meta description is longer than %d characters. Shorten it to save.', 'agentic-commerce-engine' )
				);
			case 'description':
				return self::normalize_description( $raw );
		}
		return self::fail( array( self::error( __( 'This content cannot be edited here.', 'agentic-commerce-engine' ) ) ) );
	}

	/* --------------------------------------------------------------------- */
	/* Per field                                                             */
	/* --------------------------------------------------------------------- */

	/**
	 * @param mixed $raw List, `{ highlights }`, or a JSON string of either.
	 * @return array
	 */
	private static function normalize_highlights( $raw ) {
		$list = self::list_from( $raw, array( 'highlights', 'bullets' ) );
		if ( null === $list ) {
			return self::fail( array( self::error( __( 'Highlights must be a list.', 'agentic-commerce-engine' ) ) ) );
		}
		$errors = array();
		$out    = array();
		$seen   = array();
		foreach ( $list as $index => $entry ) {
			$text = self::plain_line( $entry );
			if ( '' === $text ) {
				continue;
			}
			if ( self::chars( $text ) > self::HIGHLIGHT_MAX_CHARS ) {
				/* translators: %d: maximum number of characters. */
				$errors[] = self::error( sprintf( __( 'This highlight is longer than %d characters. Shorten it to save.', 'agentic-commerce-engine' ), self::HIGHLIGHT_MAX_CHARS ), $index );
				continue;
			}
			$key = self::lower( $text );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $text;
		}
		if ( count( $out ) > self::HIGHLIGHTS_MAX_ITEMS ) {
			/* translators: %d: maximum number of highlights. */
			$errors[] = self::error( sprintf( __( 'Keep %d highlights or fewer. Stores show at most that many.', 'agentic-commerce-engine' ), self::HIGHLIGHTS_MAX_ITEMS ) );
		}
		if ( empty( $errors ) && empty( $out ) ) {
			$errors[] = self::error( __( 'Add at least one highlight, or dismiss this suggestion instead.', 'agentic-commerce-engine' ) );
		}
		return empty( $errors ) ? self::pass( $out ) : self::fail( $errors );
	}

	/**
	 * @param mixed $raw List of { question, answer }, `{ faqs }`, or JSON.
	 * @return array
	 */
	private static function normalize_qa( $raw ) {
		$list = self::list_from( $raw, array( 'faqs' ) );
		if ( null === $list ) {
			return self::fail( array( self::error( __( 'Questions must be a list.', 'agentic-commerce-engine' ) ) ) );
		}
		$errors = array();
		$out    = array();
		foreach ( $list as $index => $entry ) {
			$pair     = is_array( $entry ) ? $entry : array();
			$question = self::plain_line( isset( $pair['question'] ) ? $pair['question'] : '' );
			$answer   = self::plain_multiline( isset( $pair['answer'] ) ? $pair['answer'] : '' );
			if ( '' === $question && '' === $answer ) {
				continue;
			}
			if ( '' === $question ) {
				$errors[] = self::error( __( 'Add the question, or remove this answer.', 'agentic-commerce-engine' ), $index, 'question' );
				continue;
			}
			if ( '' === $answer ) {
				$errors[] = self::error( __( 'Add an answer, or remove this question.', 'agentic-commerce-engine' ), $index, 'answer' );
				continue;
			}
			if ( self::chars( $question ) > self::QA_MAX_QUESTION_CHARS ) {
				/* translators: %d: maximum number of characters. */
				$errors[] = self::error( sprintf( __( 'This question is longer than %d characters. Shorten it to save.', 'agentic-commerce-engine' ), self::QA_MAX_QUESTION_CHARS ), $index, 'question' );
				continue;
			}
			if ( self::chars( $answer ) > self::QA_MAX_ANSWER_CHARS ) {
				/* translators: %d: maximum number of characters. */
				$errors[] = self::error( sprintf( __( 'This answer is longer than %d characters. Shorten it to save.', 'agentic-commerce-engine' ), self::QA_MAX_ANSWER_CHARS ), $index, 'answer' );
				continue;
			}
			$out[] = array(
				'question' => $question,
				'answer'   => $answer,
			);
		}
		if ( count( $out ) > self::QA_MAX_PAIRS ) {
			/* translators: %d: maximum number of questions. */
			$errors[] = self::error( sprintf( __( 'Keep %d questions or fewer.', 'agentic-commerce-engine' ), self::QA_MAX_PAIRS ) );
		}
		if ( empty( $errors ) && empty( $out ) ) {
			$errors[] = self::error( __( 'Add at least one question, or dismiss this suggestion instead.', 'agentic-commerce-engine' ) );
		}
		return empty( $errors ) ? self::pass( $out ) : self::fail( $errors );
	}

	/**
	 * @param mixed $raw List of { name, value, source? }, `{ attributes }`, or JSON.
	 * @return array
	 */
	private static function normalize_attributes( $raw ) {
		$list = self::list_from( $raw, array( 'attributes' ) );
		if ( null === $list ) {
			return self::fail( array( self::error( __( 'Specifications must be a list.', 'agentic-commerce-engine' ) ) ) );
		}
		$errors = array();
		$out    = array();
		$seen   = array();
		foreach ( $list as $index => $entry ) {
			$row   = is_array( $entry ) ? $entry : array();
			$name  = self::plain_line( isset( $row['name'] ) ? $row['name'] : '' );
			$value = self::plain_line( isset( $row['value'] ) ? $row['value'] : '' );
			if ( '' === $name && '' === $value ) {
				continue;
			}
			if ( '' === $name ) {
				$errors[] = self::error( __( 'Add a name for this value, or remove the row.', 'agentic-commerce-engine' ), $index, 'name' );
				continue;
			}
			if ( '' === $value ) {
				$errors[] = self::error( __( 'Add a value, or remove this row.', 'agentic-commerce-engine' ), $index, 'value' );
				continue;
			}
			// The global attribute taxonomy namespace. Never ours to create.
			if ( 0 === strpos( self::lower( $name ), 'pa_' ) ) {
				$errors[] = self::error( __( 'Names starting with pa_ are reserved by WooCommerce.', 'agentic-commerce-engine' ), $index, 'name' );
				continue;
			}
			if ( self::chars( $name ) > self::ATTRIBUTE_MAX_NAME_CHARS ) {
				/* translators: %d: maximum number of characters. */
				$errors[] = self::error( sprintf( __( 'This name is longer than %d characters. Shorten it to save.', 'agentic-commerce-engine' ), self::ATTRIBUTE_MAX_NAME_CHARS ), $index, 'name' );
				continue;
			}
			if ( self::chars( $value ) > self::ATTRIBUTE_MAX_VALUE_CHARS ) {
				/* translators: %d: maximum number of characters. */
				$errors[] = self::error( sprintf( __( 'This value is longer than %d characters. Shorten it to save.', 'agentic-commerce-engine' ), self::ATTRIBUTE_MAX_VALUE_CHARS ), $index, 'value' );
				continue;
			}
			// WooCommerce keys an attribute by its slug. A name with no letters
			// or digits has none, and the writer would skip it without a word.
			if ( '' === sanitize_title( $name ) ) {
				$errors[] = self::error( __( 'Use letters or numbers in this name.', 'agentic-commerce-engine' ), $index, 'name' );
				continue;
			}
			$key = self::lower( $name ) . ' ' . self::lower( $value );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$clean        = array(
				'name'  => $name,
				'value' => $value,
			);
			if ( isset( $row['source'] ) && is_string( $row['source'] ) && '' !== trim( $row['source'] ) ) {
				$clean['source'] = sanitize_key( $row['source'] );
			}
			$out[] = $clean;
		}
		if ( count( $out ) > self::ATTRIBUTES_MAX_ROWS ) {
			/* translators: %d: maximum number of specifications. */
			$errors[] = self::error( sprintf( __( 'Keep %d specifications or fewer.', 'agentic-commerce-engine' ), self::ATTRIBUTES_MAX_ROWS ) );
		}
		if ( empty( $errors ) && empty( $out ) ) {
			$errors[] = self::error( __( 'Add at least one specification, or dismiss this suggestion instead.', 'agentic-commerce-engine' ) );
		}
		return empty( $errors ) ? self::pass( $out ) : self::fail( $errors );
	}

	/**
	 * @param mixed $raw List of { imageUrl, alt }, `{ altTexts }`, or JSON.
	 * @return array
	 */
	private static function normalize_alt( $raw ) {
		$list = self::list_from( $raw, array( 'altTexts' ) );
		if ( null === $list ) {
			return self::fail( array( self::error( __( 'Alt text must be a list.', 'agentic-commerce-engine' ) ) ) );
		}
		$errors = array();
		$out    = array();
		$seen   = array();
		foreach ( $list as $index => $entry ) {
			$row = is_array( $entry ) ? $entry : array();
			$url = isset( $row['imageUrl'] ) && is_string( $row['imageUrl'] ) ? trim( $row['imageUrl'] ) : '';
			$alt = self::plain_line( isset( $row['alt'] ) ? $row['alt'] : '' );
			// An image with no alt text is "leave this picture alone".
			if ( '' === $alt ) {
				continue;
			}
			if ( ! preg_match( '#^https?://#i', $url ) ) {
				$errors[] = self::error( __( 'This image has no address, so its alt text cannot be matched.', 'agentic-commerce-engine' ), $index );
				continue;
			}
			if ( self::chars( $alt ) > self::ALT_MAX_CHARS ) {
				/* translators: %d: maximum number of characters. */
				$errors[] = self::error( sprintf( __( 'This alt text is longer than %d characters. Shorten it to save.', 'agentic-commerce-engine' ), self::ALT_MAX_CHARS ), $index );
				continue;
			}
			if ( isset( $seen[ $url ] ) ) {
				continue;
			}
			$seen[ $url ] = true;
			$out[]        = array(
				'imageUrl' => $url,
				'alt'      => $alt,
			);
		}
		if ( empty( $errors ) && empty( $out ) ) {
			$errors[] = self::error( __( 'Write alt text for at least one image, or dismiss this suggestion instead.', 'agentic-commerce-engine' ) );
		}
		return empty( $errors ) ? self::pass( $out ) : self::fail( $errors );
	}

	/**
	 * One line of plain text with a maximum.
	 *
	 * @param mixed  $raw      Raw value.
	 * @param int    $max      Maximum characters.
	 * @param string $empty    Message when empty.
	 * @param string $too_long Message template with %d.
	 * @return array
	 */
	private static function normalize_line( $raw, $max, $empty, $too_long ) {
		$text = self::plain_line( $raw );
		if ( '' === $text ) {
			return self::fail( array( self::error( $empty ) ) );
		}
		if ( self::chars( $text ) > $max ) {
			return self::fail( array( self::error( sprintf( $too_long, $max ) ) ) );
		}
		return self::pass( $text );
	}

	/**
	 * @param mixed $raw HTML.
	 * @return array
	 */
	private static function normalize_description( $raw ) {
		$html = is_string( $raw ) ? trim( wp_kses_post( $raw ) ) : '';
		if ( '' === $html || ! self::html_has_text( $html ) ) {
			return self::fail( array( self::error( __( 'The description cannot be empty.', 'agentic-commerce-engine' ) ) ) );
		}
		if ( self::utf16_length( $html ) > self::DESCRIPTION_HTML_MAX_CHARS ) {
			return self::fail( array( self::error( __( 'The description is too long to save.', 'agentic-commerce-engine' ) ) ) );
		}
		return self::pass( $html );
	}

	/* --------------------------------------------------------------------- */
	/* Stored shape                                                          */
	/* --------------------------------------------------------------------- */

	/**
	 * The `_agencoen_content` entry an edited list produces, in the shape the
	 * platform stores (`toStoredContent` in review-edit-values.ts): the
	 * structured key AND `bullets`, so no reader can show one and publish the
	 * other. Keys the edit does not own are carried over from `$existing`.
	 *
	 * @param string $field    One of self::STRUCTURED.
	 * @param array  $value    Normalized value.
	 * @param mixed  $existing The entry being replaced.
	 * @return array
	 */
	public static function to_stored( $field, array $value, $existing = null ) {
		$base = ( is_array( $existing ) && array_values( $existing ) !== $existing ) ? $existing : array();
		switch ( $field ) {
			case 'highlights':
				$base['highlights'] = $value;
				$base['bullets']    = $value;
				return $base;
			case 'product_qa':
				$base['faqs']    = $value;
				$base['bullets'] = array_map(
					function ( $pair ) {
						return $pair['question'] . ': ' . $pair['answer'];
					},
					$value
				);
				return $base;
			case 'product_attributes':
				$base['attributes'] = $value;
				$base['bullets']    = array_map(
					function ( $row ) {
						return $row['name'] . ': ' . $row['value'];
					},
					$value
				);
				return $base;
			case 'image_alt':
				$base['altTexts'] = $value;
				$base['bullets']  = array_map(
					function ( $row ) {
						return $row['alt'];
					},
					$value
				);
				return $base;
		}
		return $base;
	}

	/**
	 * Rewrite a stored proposal with an edited value. PURE.
	 *
	 * The flat `Meta::PENDING` keys are what {@see Apply} and the diff read; the
	 * per-type `Meta::CONTENT` entry is what the previews, the decisions report
	 * and the quality request read. Both are rewritten, so no reader is left
	 * holding the text the merchant replaced. `short_description` is derived
	 * from the description by the caller, the same way {@see Poller} derives it.
	 *
	 * @param string $field   Field name.
	 * @param mixed  $value   Normalized value.
	 * @param array  $pending Meta::PENDING.
	 * @param array  $content Meta::CONTENT.
	 * @return array{0:array,1:array} Pending, content.
	 */
	public static function apply_to_proposal( $field, $value, array $pending, array $content ) {
		switch ( $field ) {
			case 'title':
				$pending['title'] = $value;
				if ( isset( $content['product-title'] ) && is_array( $content['product-title'] ) ) {
					$content['product-title']['title'] = $value;
				} elseif ( isset( $content['product-title'] ) ) {
					$content['product-title'] = $value;
				}
				break;
			case 'description':
				$pending['description'] = $value;
				// An edited description is whole composed HTML. Keeping the old
				// generated keys beside it would let a reader of `description`
				// publish the text the merchant replaced.
				if ( isset( $content['product-description'] ) ) {
					$content['product-description'] = array( 'html' => $value );
				}
				break;
			case 'seo_title':
			case 'seo_description':
				$pending[ $field ] = $value;
				$half              = 'seo_title' === $field ? 'title' : 'description';
				foreach ( array( 'meta-tags', 'meta-optimization' ) as $key ) {
					if ( isset( $content[ $key ] ) && is_array( $content[ $key ] ) ) {
						$content[ $key ][ $half ] = $value;
					}
				}
				if ( isset( $content['woocommerce'][ $field ] ) && is_array( $content['woocommerce'] ) ) {
					$content['woocommerce'][ $field ] = $value;
				}
				break;
			default:
				if ( isset( self::CONTENT_KEYS[ $field ] ) && is_array( $value ) ) {
					$key             = self::CONTENT_KEYS[ $field ];
					$content[ $key ] = self::to_stored( $field, $value, isset( $content[ $key ] ) ? $content[ $key ] : null );
				}
		}
		return array( $pending, $content );
	}

	/**
	 * Whether a product holds a proposal for this field at all.
	 *
	 * @param string $field   Field name.
	 * @param array  $pending Meta::PENDING.
	 * @param array  $content Meta::CONTENT.
	 * @return bool
	 */
	public static function has_proposal( $field, array $pending, array $content ) {
		if ( isset( self::CONTENT_KEYS[ $field ] ) ) {
			return ! empty( $content[ self::CONTENT_KEYS[ $field ] ] );
		}
		return isset( $pending[ $field ] ) && '' !== (string) $pending[ $field ];
	}

	/**
	 * The current value of a field, in the shape the review-decisions report
	 * sends it (plugin-field vocabulary). Not validated: it is read back from
	 * what an edit already stored.
	 *
	 * @param string $field   Field name.
	 * @param array  $pending Meta::PENDING.
	 * @param array  $content Meta::CONTENT.
	 * @return mixed|null Null when there is nothing to send.
	 */
	public static function stored_value( $field, array $pending, array $content ) {
		switch ( $field ) {
			case 'title':
			case 'description':
			case 'seo_title':
			case 'seo_description':
				return isset( $pending[ $field ] ) && '' !== (string) $pending[ $field ] ? (string) $pending[ $field ] : null;
			case 'highlights':
				return isset( $content['highlights'] ) ? Pdp_Content::highlights( $content['highlights'] ) : null;
			case 'product_qa':
				return isset( $content['product-qa'] ) ? Pdp_Content::qa( $content['product-qa'] ) : null;
			case 'product_attributes':
				return isset( $content['product-attributes'] ) ? self::attribute_pairs( $content['product-attributes'] ) : null;
			case 'image_alt':
				if ( ! isset( $content['image-alt'] ) ) {
					return null;
				}
				return array_map(
					function ( $entry ) {
						return array(
							'imageUrl' => $entry['url'],
							'alt'      => $entry['alt'],
						);
					},
					Pdp_Content::image_alts( $content['image-alt'] )
				);
		}
		return null;
	}

	/**
	 * Specification rows as { name, value } pairs, one value per row, from any
	 * shape the generator, the wire or an edit stored.
	 *
	 * @param mixed $raw Stored attributes.
	 * @return array<int,array{name:string,value:string,source?:string}>
	 */
	public static function attribute_pairs( $raw ) {
		$list = self::list_from( $raw, array( 'attributes' ) );
		$out  = array();
		foreach ( (array) $list as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['name'] ) || ! is_scalar( $entry['name'] ) ) {
				continue;
			}
			$values = isset( $entry['values'] ) ? $entry['values'] : ( isset( $entry['value'] ) ? $entry['value'] : array() );
			foreach ( is_array( $values ) ? $values : array( $values ) as $value ) {
				if ( ! is_scalar( $value ) ) {
					continue;
				}
				$row = array(
					'name'  => (string) $entry['name'],
					'value' => (string) $value,
				);
				if ( isset( $entry['source'] ) && is_string( $entry['source'] ) && '' !== $entry['source'] ) {
					$row['source'] = $entry['source'];
				}
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * Pairs grouped into the native attribute writer's shape: one attribute per
	 * name, carrying every value typed for it. WooCommerce keys a product's
	 * attributes by name, so a second row with the same name would otherwise
	 * replace the first. Matches the web apply queue's merge.
	 *
	 * @param array $pairs From attribute_pairs() or normalize().
	 * @return array Already normalized by Native_Fields.
	 */
	public static function attributes_for_write( array $pairs ) {
		$grouped = array();
		foreach ( $pairs as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['name'], $row['value'] ) ) {
				continue;
			}
			$key = self::lower( (string) $row['name'] );
			if ( ! isset( $grouped[ $key ] ) ) {
				$grouped[ $key ] = array(
					'name'    => (string) $row['name'],
					'values'  => array(),
					'visible' => true,
				);
			}
			$grouped[ $key ]['values'][] = (string) $row['value'];
		}
		return Native_Fields::normalize_attributes( array_values( $grouped ) );
	}

	/* --------------------------------------------------------------------- */
	/* Text helpers                                                          */
	/* --------------------------------------------------------------------- */

	/**
	 * Tags removed, whitespace collapsed, sanitized as Pdp_Content does.
	 *
	 * @param mixed $raw Raw value.
	 * @return string
	 */
	public static function plain_line( $raw ) {
		if ( ! is_string( $raw ) && ! is_int( $raw ) && ! is_float( $raw ) ) {
			return '';
		}
		$text = preg_replace( '/<[^>]*>/', ' ', (string) $raw );
		$text = preg_replace( '/\s+/u', ' ', (string) $text );
		return trim( (string) sanitize_text_field( wp_strip_all_tags( (string) $text ) ) );
	}

	/**
	 * Tags removed, line breaks kept, runs of blank lines collapsed.
	 *
	 * @param mixed $raw Raw value.
	 * @return string
	 */
	public static function plain_multiline( $raw ) {
		if ( ! is_string( $raw ) && ! is_int( $raw ) && ! is_float( $raw ) ) {
			return '';
		}
		$text  = str_replace( array( "\r\n", "\r" ), "\n", (string) $raw );
		$text  = preg_replace( '/<[^>]*>/', ' ', $text );
		$text  = (string) sanitize_textarea_field( (string) $text );
		$lines = array_map(
			function ( $line ) {
				return trim( (string) preg_replace( '/[ \t\f\v]+/u', ' ', $line ) );
			},
			explode( "\n", $text )
		);
		return trim( (string) preg_replace( "/\n{3,}/", "\n\n", implode( "\n", $lines ) ) );
	}

	/**
	 * Characters as a merchant counts them (code points), matching `charCount`.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	public static function chars( $text ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $text, 'UTF-8' ) : strlen( (string) $text );
	}

	/**
	 * Length in UTF-16 code units, which is what JavaScript's `length` counts
	 * and so what the platform's description limit is measured in.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	private static function utf16_length( $text ) {
		if ( function_exists( 'mb_convert_encoding' ) ) {
			return (int) ( strlen( (string) mb_convert_encoding( (string) $text, 'UTF-16LE', 'UTF-8' ) ) / 2 );
		}
		return strlen( (string) $text );
	}

	/**
	 * @param string $html HTML.
	 * @return bool
	 */
	private static function html_has_text( $html ) {
		$text = preg_replace( '/<[^>]*>/', ' ', (string) $html );
		$text = preg_replace( '/&nbsp;|&#160;/i', ' ', (string) $text );
		return '' !== preg_replace( '/\s+/u', '', (string) $text );
	}

	/**
	 * @param string $text Text.
	 * @return string
	 */
	private static function lower( $text ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $text, 'UTF-8' ) : strtolower( (string) $text );
	}

	/**
	 * A list from a bare array, a wrapper under one of `$keys`, or a JSON
	 * string of either. Null when it cannot be read as a list.
	 *
	 * @param mixed    $raw  Raw value.
	 * @param string[] $keys Wrapper keys.
	 * @return array|null
	 */
	private static function list_from( $raw, array $keys ) {
		if ( is_string( $raw ) ) {
			$trimmed = trim( $raw );
			if ( '' === $trimmed || ( '[' !== $trimmed[0] && '{' !== $trimmed[0] ) ) {
				return null;
			}
			$raw = json_decode( $trimmed, true );
		}
		if ( ! is_array( $raw ) ) {
			return null;
		}
		foreach ( $keys as $key ) {
			if ( isset( $raw[ $key ] ) && is_array( $raw[ $key ] ) ) {
				return array_values( $raw[ $key ] );
			}
		}
		return array_values( $raw ) === $raw ? $raw : null;
	}

	/**
	 * @param string      $message Message.
	 * @param int|null    $index   Row index.
	 * @param string|null $part    Part of the row.
	 * @return array
	 */
	private static function error( $message, $index = null, $part = null ) {
		$error = array();
		if ( null !== $index ) {
			$error['index'] = (int) $index;
		}
		if ( null !== $part ) {
			$error['part'] = (string) $part;
		}
		$error['message'] = (string) $message;
		return $error;
	}

	/**
	 * @param mixed $value Value.
	 * @return array
	 */
	private static function pass( $value ) {
		return array(
			'ok'     => true,
			'value'  => $value,
			'errors' => array(),
		);
	}

	/**
	 * @param array $errors Errors.
	 * @return array
	 */
	private static function fail( array $errors ) {
		return array(
			'ok'     => false,
			'value'  => null,
			'errors' => $errors,
		);
	}
}
