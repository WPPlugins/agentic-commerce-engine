<?php
/**
 * Catalog of the content types the plugin can request from the Rezolve Ai platform.
 * Keys are the platform's API strings (sent as options.contentTypes). Single
 * source of truth for labels, grouping, recommendation, and apply targets.
 *
 * @package Agencoen
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Content_Types
 */
final class Content_Types {

	const GROUP_ONPAGE  = 'onpage';
	const GROUP_CATALOG = 'catalog';
	const GROUP_FEED    = 'feed';

	/**
	 * The full catalog of enrichment types this plugin can request and apply.
	 *
	 * THE TWO PLATFORM-NATIVE TYPES ARE MIRROR IMAGES. Each writes fields that
	 * exist on one platform only, so each is offered on that platform alone:
	 * "shopify" writes Shopify's native SEO and product fields and is excluded
	 * here because there is no WooCommerce destination for them, and
	 * "woocommerce" is its counterpart, writing product tags, custom attributes
	 * and SEO meta onto the WooCommerce product itself. Rezolve Ai applies the same
	 * rule from the other side in /api/v1/plugin/content-types, keyed on the
	 * store platform, so a Shopify store never sees "woocommerce" either.
	 *
	 * "woocommerce" sits in GROUP_FEED to match the card Rezolve Ai shows under
	 * "Channel feeds", even though nothing about it is served at a URL. That
	 * grouping is presentation, so the entries also carry a `served` flag for
	 * the channels that really do have a tokened feed route, and
	 * {@see feed_keys()} reads that rather than the group.
	 *
	 * This catalog is only reachable from the WooCommerce-gated screens
	 * ({@see Admin::render()} with $needs_woo), so it needs no has_woo() gate.
	 *
	 * @return array<string,array>
	 */
	public static function all() {
		return array(
			'irl-scenarios'       => array(
				'label'       => __( 'IRL scenario cards', 'agentic-commerce-engine' ),
				'description' => __( 'Real-life usage scenarios shown on the product page (highest AI visibility impact).', 'agentic-commerce-engine' ),
				'group'       => self::GROUP_ONPAGE,
				'recommended' => true,
				'requires'    => array(),
			),
			'jsonld-schema'       => array(
				'label'       => __( 'Enhanced JSON-LD schema', 'agentic-commerce-engine' ),
				'description' => __( 'Rich Product structured data (reviews, FAQ, breadcrumbs) injected into the page head.', 'agentic-commerce-engine' ),
				'group'       => self::GROUP_ONPAGE,
				'recommended' => true,
				'requires'    => array(),
			),
			'product-description' => array(
				'label'       => __( 'Product description', 'agentic-commerce-engine' ),
				'description' => __( 'Conversion-optimized description, short description, and CTA.', 'agentic-commerce-engine' ),
				'group'       => self::GROUP_ONPAGE,
				'recommended' => true,
				'requires'    => array(),
			),
			'meta-tags'           => array(
				'label'       => __( 'Meta tags & SEO', 'agentic-commerce-engine' ),
				'description' => __( 'Optimized SEO title, meta description, and social tags.', 'agentic-commerce-engine' ),
				'group'       => self::GROUP_ONPAGE,
				'recommended' => true,
				'requires'    => array(),
			),
			'internal-links'      => array(
				'label'       => __( 'Internal links', 'agentic-commerce-engine' ),
				'description' => __( 'Semantic related-product and category link suggestions for the product page.', 'agentic-commerce-engine' ),
				'group'       => self::GROUP_ONPAGE,
				'recommended' => false,
				'requires'    => array( 'product-description' ),
			),
			/*
			 * The five below are GATED (4.16.0): offered only when Rezolve Ai
			 * lists them in its content-type catalog. An older platform silently
			 * skips a type it cannot generate, so offering one it does not list
			 * would charge nothing and deliver nothing, with no explanation.
			 */
			'product-title'       => array(
				'label'       => __( 'Product title', 'agentic-commerce-engine' ),
				'description' => __( 'A clearer, search-friendly product name, written onto the product when you approve it.', 'agentic-commerce-engine' ),
				'group'       => self::GROUP_ONPAGE,
				'recommended' => false,
				'requires'    => array(),
				'gated'       => true,
			),
			'highlights'          => array(
				'label'       => __( 'Highlights', 'agentic-commerce-engine' ),
				'description' => __( 'Three to six short benefit bullets, shown on the product page and read by AI shopping agents.', 'agentic-commerce-engine' ),
				'group'       => self::GROUP_ONPAGE,
				'recommended' => false,
				'requires'    => array(),
				'gated'       => true,
			),
			'product-qa'          => array(
				'label'       => __( 'Product Q&A', 'agentic-commerce-engine' ),
				'description' => __( 'Shopper questions with grounded answers, shown on the product page and published as FAQ structured data.', 'agentic-commerce-engine' ),
				'group'       => self::GROUP_ONPAGE,
				'recommended' => false,
				'requires'    => array(),
				'gated'       => true,
			),
			'product-attributes'  => array(
				'label'       => __( 'Product attributes', 'agentic-commerce-engine' ),
				'description' => __( 'Structured specs such as material, colour and size, added as product attributes. Existing attributes are never replaced.', 'agentic-commerce-engine' ),
				'group'       => self::GROUP_CATALOG,
				'recommended' => false,
				'requires'    => array(),
				'gated'       => true,
			),
			'image-alt'           => array(
				'label'       => __( 'Image alt text', 'agentic-commerce-engine' ),
				'description' => __( 'Accurate alt text for each product image, for accessibility, image search and AI agents. Replaces the current alt text when you approve it.', 'agentic-commerce-engine' ),
				'group'       => self::GROUP_CATALOG,
				'recommended' => false,
				'requires'    => array(),
				'gated'       => true,
			),
			'google-shopping'     => array(
				'label'       => __( 'Google Merchant feed', 'agentic-commerce-engine' ),
				'description' => __( 'Google Shopping feed records, served at a feed URL.', 'agentic-commerce-engine' ),
				'group'       => self::GROUP_FEED,
				'served'      => true,
				'recommended' => false,
				'requires'    => array(),
			),
			'agentic-commerce'    => array(
				'label'       => __( 'Agentic Commerce feed', 'agentic-commerce-engine' ),
				'description' => __( "OpenAI's Agentic Commerce Protocol feed for AI shopping agents.", 'agentic-commerce-engine' ),
				'group'       => self::GROUP_FEED,
				'served'      => true,
				'recommended' => false,
				'requires'    => array(),
			),
			'microsoft-shopping'  => array(
				'label'       => __( 'Microsoft Bing feed', 'agentic-commerce-engine' ),
				'description' => __( 'Microsoft Bing Shopping feed records, served at a feed URL.', 'agentic-commerce-engine' ),
				'group'       => self::GROUP_FEED,
				'served'      => true,
				'recommended' => false,
				'requires'    => array(),
			),
			'woocommerce'         => array(
				'label'       => __( 'WooCommerce product fields', 'agentic-commerce-engine' ),
				'description' => __( 'Product tags, product attributes, and SEO meta written onto the product itself. Tags and attributes are added, never replaced, and categories are never changed.', 'agentic-commerce-engine' ),
				'group'       => self::GROUP_FEED,
				'recommended' => false,
				'requires'    => array(),
			),
		);
	}

	/**
	 * The enrichment type ids Rezolve Ai says it can generate for this store.
	 *
	 * PURE. Takes the cached `/api/v1/plugin/content-types` response and returns
	 * its `enrichmentTypes[].id` list, or null when there is no usable catalog
	 * (never fetched, an error, a malformed body). Null and an empty list mean
	 * different things to {@see available()}: null is "unknown", so gated types
	 * stay hidden, which is the safe direction.
	 *
	 * @param mixed $catalog Cached catalog, a WP_Error, or false.
	 * @return string[]|null
	 */
	public static function server_ids( $catalog ) {
		if ( ! is_array( $catalog ) || ! isset( $catalog['enrichmentTypes'] ) || ! is_array( $catalog['enrichmentTypes'] ) ) {
			return null;
		}
		$ids = array();
		foreach ( $catalog['enrichmentTypes'] as $type ) {
			if ( is_array( $type ) && isset( $type['id'] ) && is_string( $type['id'] ) ) {
				$ids[] = $type['id'];
			}
		}
		return $ids;
	}

	/**
	 * The catalog a merchant is offered: every ungated type, plus each gated
	 * type Rezolve Ai lists.
	 *
	 * PURE. Labels, groups and descriptions always come from {@see all()}, so
	 * they stay translatable; the server list only decides visibility.
	 *
	 * @param string[]|null $server_ids From {@see server_ids()}.
	 * @return array<string,array>
	 */
	public static function available( $server_ids ) {
		$ids = is_array( $server_ids ) ? $server_ids : array();
		return array_filter(
			self::all(),
			function ( $config, $key ) use ( $ids ) {
				return empty( $config['gated'] ) || in_array( $key, $ids, true );
			},
			ARRAY_FILTER_USE_BOTH
		);
	}

	/**
	 * Valid content-type keys.
	 *
	 * @return string[]
	 */
	public static function keys() {
		return array_keys( self::all() );
	}

	/**
	 * Keys of the channels actually SERVED at a feed URL.
	 *
	 * Read from the `served` flag, not from GROUP_FEED. The group is a display
	 * grouping shared with Rezolve Ai's own wizard, and it now holds "woocommerce",
	 * which writes onto the product and serves nothing. Reading the group here
	 * would have quietly added a channel to a list whose whole meaning is "has a
	 * tokened feed route". The flag lives in this file rather than deriving from
	 * {@see Apply::FEED_CHANNELS} so the catalog keeps no class dependencies.
	 *
	 * @return string[]
	 */
	public static function feed_keys() {
		return array_keys(
			array_filter(
				self::all(),
				function ( $c ) {
					return ! empty( $c['served'] );
				}
			)
		);
	}

	/**
	 * The recommended default selection.
	 *
	 * @return string[]
	 */
	public static function recommended() {
		return array_keys(
			array_filter(
				self::all(),
				function ( $c ) {
					return ! empty( $c['recommended'] );
				}
			)
		);
	}

	/**
	 * Sanitize an incoming selection: keep known keys and resolve dependencies
	 * (e.g. internal-links pulls in product-description).
	 *
	 * @param array $selection Requested keys.
	 * @return string[]
	 */
	public static function sanitize_selection( $selection ) {
		$valid   = self::keys();
		$catalog = self::all();
		$out     = array();
		foreach ( (array) $selection as $key ) {
			$key = sanitize_key( str_replace( '_', '-', (string) $key ) );
			if ( in_array( $key, $valid, true ) && ! in_array( $key, $out, true ) ) {
				$out[] = $key;
			}
		}
		// Resolve dependencies.
		foreach ( $out as $key ) {
			foreach ( $catalog[ $key ]['requires'] as $dep ) {
				if ( ! in_array( $dep, $out, true ) ) {
					$out[] = $dep;
				}
			}
		}
		return $out;
	}

	/**
	 * Human label for a key.
	 *
	 * @param string $key Content type key.
	 * @return string
	 */
	public static function label( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ]['label'] : $key;
	}
}
