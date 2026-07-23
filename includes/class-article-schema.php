<?php
/**
 * Emits Article JSON-LD on singular posts/pages that were materialized from
 * ACE briefs. Mirrors the v3 Product Schema emitter: hook wp_head late, gate
 * on the setting, and self-suppress when an SEO plugin already outputs
 * Article schema for the same page.
 *
 * @package Agencoen
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Article_Schema
 */
class Article_Schema {

	/** @var Plugin */
	private $plugin;

	/**
	 * @param Plugin $plugin Orchestrator.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Hook into the storefront head (registered on every site, Woo or not).
	 */
	public function register() {
		add_action( 'wp_head', array( $this, 'output' ), 99 );
	}

	/**
	 * Decide whether to output, then print one JSON-LD script block.
	 */
	public function output() {
		if ( ! self::gate( $this->plugin->settings()->is_article_schema(), is_singular( array( 'post', 'page' ) ), self::seo_emits_article() ) ) {
			return;
		}

		$post_id = get_queried_object_id();
		if ( ! $post_id ) {
			return;
		}

		$node   = null;
		$stored = get_post_meta( $post_id, Meta::ARTICLE_JSONLD, true );
		if ( ! empty( $stored ) ) {
			$decoded = is_string( $stored ) ? json_decode( $stored, true ) : $stored;
			if ( is_array( $decoded ) && ! empty( $decoded ) ) {
				$node = $decoded;
			}
		}

		// Fallback: ACE-authored posts still get a minimal Article node when the
		// platform graph is missing. Foreign posts get nothing.
		if ( null === $node ) {
			if ( '' === (string) get_post_meta( $post_id, Meta::BRIEF_ID, true ) ) {
				return;
			}
			$node = self::build_fallback( $post_id );
		}

		if ( empty( $node ) ) {
			return;
		}

		$json = wp_json_encode( $node, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) || '' === $json ) {
			return;
		}
		// Defense in depth: a </script> sequence inside a string value must not
		// break out of the tag.
		$json = str_ireplace( '</script', '<\/script', $json );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode output inside a JSON-LD script tag.
		echo "\n<script type=\"application/ld+json\">" . $json . "</script>\n";
	}

	/**
	 * Pure output gate: emit only when enabled, on a singular post/page, and no
	 * SEO plugin already emits Article schema.
	 *
	 * @param bool $enabled      Article schema setting.
	 * @param bool $is_singular  On a singular post/page.
	 * @param bool $seo_emitting An SEO plugin already emits Article schema.
	 * @return bool
	 */
	public static function gate( $enabled, $is_singular, $seo_emitting ) {
		return (bool) $enabled && (bool) $is_singular && ! $seo_emitting;
	}

	/**
	 * Whether an active SEO plugin already outputs Article schema (Yoast,
	 * Rank Math, and AIOSEO all do for singular content).
	 *
	 * @return bool
	 */
	public static function seo_emits_article() {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return true;
		}
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			return true;
		}
		if ( defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Minimal Article node for an ACE post without a stored platform graph.
	 *
	 * @param int $post_id Post id.
	 * @return array
	 */
	public static function build_fallback( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}

		return array(
			'@context'         => 'https://schema.org',
			'@type'            => 'Article',
			'headline'         => get_the_title( $post ),
			'description'      => wp_strip_all_tags( get_the_excerpt( $post ) ),
			'datePublished'    => (string) get_post_time( 'c', true, $post ),
			'dateModified'     => (string) get_post_modified_time( 'c', true, $post ),
			'mainEntityOfPage' => (string) get_permalink( $post ),
			'publisher'        => array(
				'@type' => 'Organization',
				'name'  => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			),
		);
	}
}
