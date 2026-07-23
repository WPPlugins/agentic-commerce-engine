<?php
/**
 * Catalog of the content types the plugin can request from the ACE platform.
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

	const GROUP_ONPAGE = 'onpage';
	const GROUP_FEED   = 'feed';

	/**
	 * The full catalog. "shopify" is intentionally excluded (no WooCommerce
	 * destination).
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
			'google-shopping'     => array(
				'label'       => __( 'Google Merchant feed', 'agentic-commerce-engine' ),
				'description' => __( 'Google Shopping feed records, served at a feed URL.', 'agentic-commerce-engine' ),
				'group'       => self::GROUP_FEED,
				'recommended' => false,
				'requires'    => array(),
			),
			'agentic-commerce'    => array(
				'label'       => __( 'Agentic Commerce feed', 'agentic-commerce-engine' ),
				'description' => __( "OpenAI's Agentic Commerce Protocol feed for AI shopping agents.", 'agentic-commerce-engine' ),
				'group'       => self::GROUP_FEED,
				'recommended' => false,
				'requires'    => array(),
			),
			'microsoft-shopping'  => array(
				'label'       => __( 'Microsoft Bing feed', 'agentic-commerce-engine' ),
				'description' => __( 'Microsoft Bing Shopping feed records, served at a feed URL.', 'agentic-commerce-engine' ),
				'group'       => self::GROUP_FEED,
				'recommended' => false,
				'requires'    => array(),
			),
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
	 * Feed-channel keys.
	 *
	 * @return string[]
	 */
	public static function feed_keys() {
		return array_keys(
			array_filter(
				self::all(),
				function ( $c ) {
					return self::GROUP_FEED === $c['group'];
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
