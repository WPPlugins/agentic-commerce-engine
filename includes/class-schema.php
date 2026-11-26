<?php
/**
 * Emits schema.org Product JSON-LD on single product pages, built from the live
 * (post-apply, AI-optimized) product. Self-suppresses when another SEO plugin
 * already outputs Product schema, and suppresses WooCommerce core's basic
 * Product entity when this richer graph is emitted, so a page never carries
 * two Product blocks.
 *
 * @package Agencoen
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Schema
 */
class Schema {

	/** @var Plugin */
	private $plugin;

	/**
	 * Lowercased @type slugs this plugin actually emitted on the current page.
	 * Filled by output() (wp_head), consumed by filter_wc_types() (wp_footer).
	 *
	 * @var string[]
	 */
	private $emitted_types = array();

	/**
	 * Product ids whose Q&A was actually rendered on this page, by any of the
	 * shortcode, the block or auto-placement. FAQPage markup is emitted only for
	 * these: FAQ structured data has to describe content a shopper can see.
	 *
	 * @var array<int,bool>
	 */
	private $qa_rendered = array();

	/**
	 * @param Plugin $plugin Orchestrator.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Hook into the storefront head.
	 */
	public function register() {
		add_action( 'wp_head', array( $this, 'output' ), 99 );
		// The footer, not the head: whether Q&A is on the page is only known once
		// the page body has rendered it.
		add_action( 'wp_footer', array( $this, 'output_faq' ), 20 );
		add_filter( 'woocommerce_structured_data_type_for_page', array( $this, 'filter_wc_types' ) );
	}

	/**
	 * Drop from WooCommerce core's structured data every entity type this
	 * plugin already emitted on the page (Product, BreadcrumbList, ...), so the
	 * page never carries duplicate entities. Woo types we did not emit are kept.
	 *
	 * @param array $types Structured-data type slugs Woo plans to output.
	 * @return array
	 */
	public function filter_wc_types( $types ) {
		return self::strip_emitted_types( (array) $types, $this->emitted_types );
	}

	/**
	 * Pure filter body: remove the types we emitted from Woo's type list. Used
	 * by filter_wc_types() and tests.
	 *
	 * @param array    $types   Structured-data type slugs Woo plans to output.
	 * @param string[] $emitted Lowercased type slugs this plugin emitted.
	 * @return array
	 */
	public static function strip_emitted_types( array $types, array $emitted ) {
		if ( empty( $emitted ) ) {
			return $types;
		}
		return array_values( array_diff( $types, $emitted ) );
	}

	/**
	 * Collect the lowercased @type slugs present in an emitted JSON-LD array
	 * (either a single entity or a @graph of entities). Pure.
	 *
	 * @param array $graph Emitted JSON-LD structure.
	 * @return string[]
	 */
	public static function graph_types( array $graph ) {
		$entities = isset( $graph['@graph'] ) && is_array( $graph['@graph'] ) ? $graph['@graph'] : array( $graph );
		$found    = array();
		foreach ( $entities as $entity ) {
			if ( ! is_array( $entity ) || empty( $entity['@type'] ) ) {
				continue;
			}
			foreach ( (array) $entity['@type'] as $type ) {
				if ( is_string( $type ) && '' !== $type ) {
					$found[] = strtolower( $type );
				}
			}
		}
		return array_values( array_unique( $found ) );
	}

	/**
	 * Decide whether to output, then print one JSON-LD script block.
	 */
	public function output() {
		if ( ! $this->should_output() ) {
			return;
		}

		$pid = get_queried_object_id();

		// Prefer the platform-generated schema graph if it has been applied.
		$graph  = null;
		$stored = get_post_meta( $pid, Meta::JSONLD, true );
		if ( ! empty( $stored ) ) {
			$decoded = is_string( $stored ) ? json_decode( $stored, true ) : $stored;
			if ( is_array( $decoded ) && ! empty( $decoded ) ) {
				$graph = ( isset( $decoded['@graph'] ) || isset( $decoded['@context'] ) )
					? $decoded
					: array( '@context' => 'https://schema.org', '@graph' => array_values( $decoded ) );
			}
		}

		// Otherwise build locally from the live product.
		if ( ! is_array( $graph ) || empty( $graph ) ) {
			$product = wc_get_product( $pid );
			if ( ! $product ) {
				return;
			}
			$graph = self::build_graph( $this->context( $product ) );
		}

		if ( empty( $graph ) || ( isset( $graph['@graph'] ) && empty( $graph['@graph'] ) ) ) {
			return;
		}

		$json = wp_json_encode( $graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) || '' === $json ) {
			return;
		}
		$this->emitted_types = self::graph_types( $graph );
		// Defense in depth: a </script> sequence inside a string value must not
		// break out of the tag.
		$json = str_ireplace( '</script', '<\/script', $json );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode output inside a JSON-LD script tag.
		echo "\n<script type=\"application/ld+json\">" . $json . "</script>\n";
	}

	/**
	 * Record that a product's Q&A is visible on this page. Called by Frontend.
	 *
	 * @param int $product_id Product id.
	 */
	public function note_qa_rendered( $product_id ) {
		$this->qa_rendered[ (int) $product_id ] = true;
	}

	/**
	 * Print FAQPage JSON-LD for the product's approved Q&A (4.16.0).
	 *
	 * A SEPARATE block from the Product graph, and NOT suppressed when an SEO
	 * plugin emits Product schema: Yoast WooCommerce SEO, Rank Math and AIOSEO
	 * describe the product but not its Q&A, so suppressing this with them would
	 * leave the questions with no markup at all.
	 */
	public function output_faq() {
		$pid = (int) get_queried_object_id();
		if ( ! $pid ) {
			return;
		}
		$seo_faq_block = function_exists( 'has_block' )
			&& ( has_block( 'yoast/faq-block', $pid ) || has_block( 'rank-math/faq-block', $pid ) );
		if ( ! self::faq_gate(
			$this->plugin->settings()->is_schema_enabled(),
			function_exists( 'is_product' ) && is_product(),
			isset( $this->qa_rendered[ $pid ] ),
			in_array( 'faqpage', $this->emitted_types, true ),
			$seo_faq_block
		) ) {
			return;
		}

		$graph = self::build_faq_graph( Pdp_Content::qa( get_post_meta( $pid, Meta::QA, true ) ), (string) get_permalink( $pid ) );
		if ( empty( $graph ) ) {
			return;
		}
		$json = wp_json_encode( $graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) || '' === $json ) {
			return;
		}
		$json = str_ireplace( '</script', '<\/script', $json );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode output inside a JSON-LD script tag.
		echo "\n<script type=\"application/ld+json\">" . $json . "</script>\n";
	}

	/**
	 * Pure FAQPage gate.
	 *
	 * @param bool $enabled         Schema output enabled.
	 * @param bool $is_product      On a single product page.
	 * @param bool $qa_rendered     The Q&A is visible on this page.
	 * @param bool $already_emitted Our Product graph already carried a FAQPage.
	 * @param bool $seo_faq_block   The post content has an SEO plugin's FAQ block.
	 * @return bool
	 */
	public static function faq_gate( $enabled, $is_product, $qa_rendered, $already_emitted, $seo_faq_block ) {
		return (bool) $enabled && (bool) $is_product && (bool) $qa_rendered && ! $already_emitted && ! $seo_faq_block;
	}

	/**
	 * Pure FAQPage builder. Empty when there are no pairs.
	 *
	 * @param array  $qa  From Pdp_Content::qa().
	 * @param string $url Product permalink, for the @id.
	 * @return array
	 */
	public static function build_faq_graph( array $qa, $url ) {
		$questions = array();
		foreach ( $qa as $pair ) {
			if ( ! is_array( $pair ) || empty( $pair['question'] ) || empty( $pair['answer'] ) ) {
				continue;
			}
			$questions[] = array(
				'@type'          => 'Question',
				'name'           => (string) $pair['question'],
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => (string) $pair['answer'],
				),
			);
		}
		if ( empty( $questions ) ) {
			return array();
		}
		$graph = array(
			'@context' => 'https://schema.org',
			'@type'    => 'FAQPage',
		);
		if ( '' !== (string) $url ) {
			$graph['@id'] = $url . '#faq';
		}
		$graph['mainEntity'] = $questions;
		return $graph;
	}

	/**
	 * A schema graph with every FAQPage node's questions rebuilt from `$qa`.
	 * PURE (4.18.0).
	 *
	 * An approved JSON-LD graph can carry its own FAQPage, built from the Q&A
	 * that was generated. When a merchant edits those questions, the markup
	 * would otherwise go on describing the text they replaced, which is exactly
	 * the mismatch between visible content and structured data that search
	 * engines treat as spam. Only `mainEntity` changes; the node's @id and
	 * everything else in the graph are kept.
	 *
	 * @param mixed $graph Graph: a node, a list of nodes, or `{ @graph }`.
	 * @param array $qa    Pairs from Pdp_Content::qa().
	 * @return array{0:mixed,1:bool} The graph, and whether anything changed.
	 */
	public static function with_faq_questions( $graph, array $qa ) {
		$faq       = self::build_faq_graph( $qa, '' );
		$questions = isset( $faq['mainEntity'] ) ? $faq['mainEntity'] : array();
		if ( ! is_array( $graph ) || empty( $questions ) ) {
			return array( $graph, false );
		}

		$changed = false;
		$rewrite = function ( array $nodes ) use ( $questions, &$changed ) {
			foreach ( $nodes as $i => $node ) {
				if ( ! is_array( $node ) || ! isset( $node['@type'] ) ) {
					continue;
				}
				$types = array_map( 'strtolower', array_map( 'strval', (array) $node['@type'] ) );
				if ( in_array( 'faqpage', $types, true ) ) {
					$nodes[ $i ]['mainEntity'] = $questions;
					$changed                   = true;
				}
			}
			return $nodes;
		};

		if ( isset( $graph['@graph'] ) && is_array( $graph['@graph'] ) ) {
			$graph['@graph'] = $rewrite( $graph['@graph'] );
		} elseif ( array_values( $graph ) === $graph ) {
			$graph = $rewrite( $graph );
		} else {
			$single = $rewrite( array( $graph ) );
			$graph  = $single[0];
		}
		return array( $graph, $changed );
	}

	/**
	 * Build the JSON-LD @graph for a specific product (used by output() and tests).
	 *
	 * @param \WC_Product $product Product.
	 * @return array
	 */
	public function graph_for( $product ) {
		return self::build_graph( $this->context( $product ) );
	}

	/**
	 * Output gate: enabled, on a product page, and not duplicated by an SEO plugin.
	 *
	 * @return bool
	 */
	public function should_output() {
		$is_product = function_exists( 'is_product' ) && is_product();
		return self::gate(
			$this->plugin->settings()->is_schema_enabled(),
			$is_product,
			$this->plugin->seo()->is_emitting_product_schema()
		);
	}

	/**
	 * Pure output gate: emit only when enabled, on a product page, and no other
	 * plugin already emits Product schema.
	 *
	 * @param bool $enabled       Schema output enabled.
	 * @param bool $is_product    On a single product page.
	 * @param bool $seo_emitting  An SEO plugin already emits Product schema.
	 * @return bool
	 */
	public static function gate( $enabled, $is_product, $seo_emitting ) {
		return (bool) $enabled && (bool) $is_product && ! $seo_emitting;
	}

	/**
	 * Gather the context array from a live product (WP-coupled).
	 *
	 * @param \WC_Product $product Product.
	 * @return array
	 */
	private function context( $product ) {
		$description = $product->get_short_description();
		if ( '' === $description ) {
			$description = wp_trim_words( wp_strip_all_tags( $product->get_description() ), 50 );
		} else {
			$description = wp_strip_all_tags( $description );
		}

		$images = array();
		$ids    = array_filter( array_merge( array( $product->get_image_id() ), (array) $product->get_gallery_image_ids() ) );
		foreach ( array_unique( $ids ) as $img_id ) {
			$src = wp_get_attachment_image_url( $img_id, 'full' );
			if ( $src ) {
				$images[] = $src;
			}
		}

		$rating = null;
		if ( (int) $product->get_rating_count() > 0 ) {
			$rating = array(
				'value' => (string) $product->get_average_rating(),
				'count' => (int) $product->get_rating_count(),
			);
		}

		// Variable products have one PDP with a price range, so represent them as
		// an AggregateOffer rather than a single Offer (and rather than enriching
		// each variation, which has no page of its own to optimize).
		$price       = (string) $product->get_price();
		$price_range = null;
		if ( $product->is_type( 'variable' ) ) {
			$min = $product->get_variation_price( 'min', true );
			$max = $product->get_variation_price( 'max', true );
			if ( '' !== (string) $min ) {
				$price_range = array(
					'low'   => (string) $min,
					'high'  => (string) $max,
					'count' => count( $product->get_children() ),
				);
				$price = '';
			}
		}

		return array(
			'name'         => $product->get_name(),
			'description'  => $description,
			'sku'          => $product->get_sku(),
			'url'          => $product->get_permalink(),
			'price'           => $price,
			'priceRange'      => $price_range,
			'priceValidUntil' => self::price_valid_until( $product ),
			'currency'        => get_woocommerce_currency(),
			'availability'    => self::availability_url( $product->get_stock_status() ),
			'images'          => $images,
			'rating'          => $rating,
		);
	}

	/**
	 * The day the current price stops applying, or '' when the store does not
	 * say. Only a running sale with an end date does: a regular price has no end,
	 * and until a scheduled sale starts its end date belongs to a price nobody
	 * is being charged. Google only recommends priceValidUntil, so leaving it out
	 * is safe, and a made-up date is not.
	 *
	 * In the site's timezone, so this is the day the merchant picked. WooCommerce
	 * stores the end of that day, which in UTC can already be the next one.
	 *
	 * @param \WC_Product $product Product.
	 * @return string Y-m-d, or ''.
	 */
	public static function price_valid_until( $product ) {
		if ( ! $product->is_on_sale() ) {
			return '';
		}
		$ends = $product->get_date_on_sale_to();
		return $ends ? $ends->date( 'Y-m-d' ) : '';
	}

	/* --------------------------------------------------------------------- */
	/* Pure builder (unit-tested directly)                                   */
	/* --------------------------------------------------------------------- */

	/**
	 * Build the schema.org @graph from a plain context array.
	 *
	 * @param array $ctx Context.
	 * @return array
	 */
	public static function build_graph( array $ctx ) {
		$url = isset( $ctx['url'] ) ? (string) $ctx['url'] : '';

		$offer = self::strip_empty(
			array(
				'@type'           => 'Offer',
				'price'           => isset( $ctx['price'] ) ? (string) $ctx['price'] : '',
				'priceCurrency'   => isset( $ctx['currency'] ) ? (string) $ctx['currency'] : '',
				'availability'    => isset( $ctx['availability'] ) ? (string) $ctx['availability'] : '',
				'url'             => $url,
				'priceValidUntil' => isset( $ctx['priceValidUntil'] ) ? (string) $ctx['priceValidUntil'] : '',
			)
		);

		$product = array(
			'@type'       => 'Product',
			'@id'         => $url ? $url . '#product' : null,
			'name'        => isset( $ctx['name'] ) ? (string) $ctx['name'] : '',
			'description' => isset( $ctx['description'] ) ? (string) $ctx['description'] : '',
			'sku'         => isset( $ctx['sku'] ) ? (string) $ctx['sku'] : '',
			'image'       => ! empty( $ctx['images'] ) ? array_values( (array) $ctx['images'] ) : null,
		);

		// Variable products: AggregateOffer with a price range. Simple products:
		// a single Offer. Otherwise: no offer node.
		$range = ( isset( $ctx['priceRange'] ) && is_array( $ctx['priceRange'] ) ) ? $ctx['priceRange'] : null;
		if ( $range && '' !== (string) ( isset( $range['low'] ) ? $range['low'] : '' ) ) {
			$product['offers'] = self::strip_empty(
				array(
					'@type'         => 'AggregateOffer',
					'priceCurrency' => isset( $ctx['currency'] ) ? (string) $ctx['currency'] : '',
					'lowPrice'      => (string) $range['low'],
					'highPrice'     => isset( $range['high'] ) ? (string) $range['high'] : '',
					'offerCount'    => isset( $range['count'] ) ? (int) $range['count'] : null,
					'availability'  => isset( $ctx['availability'] ) ? (string) $ctx['availability'] : '',
					'url'           => $url,
				)
			);
		} elseif ( '' !== ( isset( $ctx['price'] ) ? (string) $ctx['price'] : '' ) ) {
			$product['offers'] = $offer;
		}

		if ( ! empty( $ctx['rating'] ) && isset( $ctx['rating']['count'] ) && (int) $ctx['rating']['count'] > 0 ) {
			$product['aggregateRating'] = array(
				'@type'       => 'AggregateRating',
				'ratingValue' => (string) $ctx['rating']['value'],
				'reviewCount' => (int) $ctx['rating']['count'],
			);
		}

		$graph = array( self::strip_empty( $product ) );

		if ( ! empty( $ctx['breadcrumb'] ) && is_array( $ctx['breadcrumb'] ) ) {
			$items = array();
			$pos   = 1;
			foreach ( $ctx['breadcrumb'] as $crumb ) {
				$items[] = self::strip_empty(
					array(
						'@type'    => 'ListItem',
						'position' => $pos++,
						'name'     => isset( $crumb['name'] ) ? (string) $crumb['name'] : '',
						'item'     => isset( $crumb['url'] ) ? (string) $crumb['url'] : '',
					)
				);
			}
			if ( ! empty( $items ) ) {
				$graph[] = array(
					'@type'           => 'BreadcrumbList',
					'itemListElement' => $items,
				);
			}
		}

		return array(
			'@context' => 'https://schema.org',
			'@graph'   => $graph,
		);
	}

	/**
	 * Map a WooCommerce stock status onto a schema.org availability URL.
	 *
	 * A status this does not know, such as one an extension added, maps to ''
	 * rather than to in stock: the Offer then says nothing about availability
	 * instead of promising what the store did not say.
	 *
	 * @param string $status instock|outofstock|onbackorder.
	 * @return string
	 */
	public static function availability_url( $status ) {
		switch ( $status ) {
			case 'outofstock':
				return 'https://schema.org/OutOfStock';
			case 'onbackorder':
				return 'https://schema.org/BackOrder';
			case 'instock':
				return 'https://schema.org/InStock';
			default:
				return '';
		}
	}

	/**
	 * Remove null/'' entries from an associative array.
	 *
	 * @param array $arr Source.
	 * @return array
	 */
	public static function strip_empty( array $arr ) {
		return array_filter(
			$arr,
			function ( $value ) {
				return null !== $value && '' !== $value && array() !== $value;
			}
		);
	}
}
