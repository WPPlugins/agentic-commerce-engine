<?php
/**
 * Detects the active SEO plugin and reads/writes its title + meta-description
 * fields, so enrichment never double-writes across plugins.
 *
 * Supported writers in v1: Yoast and Rank Math (both post-meta based). AIOSEO
 * stores SEO in a custom table, so SEO-meta writes are deferred there (the
 * product's own title/description still apply).
 *
 * @package Agencoen
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Seo_Adapter
 */
class Seo_Adapter {

	const TRANSIENT = 'agencoen_seo_plugin';

	const KEYS = array(
		'yoast'    => array(
			'title' => '_yoast_wpseo_title',
			'desc'  => '_yoast_wpseo_metadesc',
		),
		'rankmath' => array(
			'title' => 'rank_math_title',
			'desc'  => 'rank_math_description',
		),
	);

	/**
	 * Which SEO plugin is active: yoast|rankmath|aioseo|''. Cached briefly.
	 *
	 * @return string
	 */
	public function detect() {
		$cached = get_transient( self::TRANSIENT );
		if ( is_string( $cached ) ) {
			return $cached;
		}

		$detected = '';
		if ( defined( 'WPSEO_VERSION' ) ) {
			$detected = 'yoast';
		} elseif ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			$detected = 'rankmath';
		} elseif ( defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' ) ) {
			$detected = 'aioseo';
		}

		set_transient( self::TRANSIENT, $detected, 10 * MINUTE_IN_SECONDS );
		return $detected;
	}

	/**
	 * Human-readable name for UI notices.
	 *
	 * @return string
	 */
	public function label() {
		switch ( $this->detect() ) {
			case 'yoast':
				return 'Yoast SEO';
			case 'rankmath':
				return 'Rank Math';
			case 'aioseo':
				return 'All in One SEO';
			default:
				return '';
		}
	}

	/**
	 * Whether SEO title/description writes are supported for the active plugin.
	 *
	 * @return bool
	 */
	public function supports_seo_write() {
		$plugin = $this->detect();
		if ( isset( self::KEYS[ $plugin ] ) ) {
			return true;
		}
		return 'aioseo' === $plugin && $this->aioseo_available();
	}

	/**
	 * @param int $product_id Product id.
	 * @return string
	 */
	public function read_seo_title( $product_id ) {
		if ( 'aioseo' === $this->detect() ) {
			$model = $this->aioseo_model( $product_id );
			return ( $model && ! empty( $model->title ) ) ? (string) $model->title : '';
		}
		$key = $this->key( 'title' );
		return $key ? (string) get_post_meta( $product_id, $key, true ) : '';
	}

	/**
	 * @param int $product_id Product id.
	 * @return string
	 */
	public function read_seo_description( $product_id ) {
		if ( 'aioseo' === $this->detect() ) {
			$model = $this->aioseo_model( $product_id );
			return ( $model && ! empty( $model->description ) ) ? (string) $model->description : '';
		}
		$key = $this->key( 'desc' );
		return $key ? (string) get_post_meta( $product_id, $key, true ) : '';
	}

	/**
	 * @param int    $product_id Product id.
	 * @param string $value      Title.
	 * @return bool Written?
	 */
	public function write_seo_title( $product_id, $value ) {
		if ( 'aioseo' === $this->detect() ) {
			return $this->aioseo_write( $product_id, 'title', sanitize_text_field( $value ) );
		}
		$key = $this->key( 'title' );
		if ( ! $key ) {
			return false;
		}
		update_post_meta( $product_id, $key, sanitize_text_field( $value ) );
		return true;
	}

	/**
	 * @param int    $product_id Product id.
	 * @param string $value      Description.
	 * @return bool Written?
	 */
	public function write_seo_description( $product_id, $value ) {
		if ( 'aioseo' === $this->detect() ) {
			return $this->aioseo_write( $product_id, 'description', sanitize_text_field( $value ) );
		}
		$key = $this->key( 'desc' );
		if ( ! $key ) {
			return false;
		}
		update_post_meta( $product_id, $key, sanitize_text_field( $value ) );
		return true;
	}

	/**
	 * Whether another plugin already emits Product JSON-LD (so we should not).
	 *
	 * @return bool
	 */
	public function is_emitting_product_schema() {
		// Yoast emits Product schema via its WooCommerce SEO add-on.
		if ( class_exists( 'WPSEO_WooCommerce' ) ) {
			return true;
		}
		// Rank Math emits Product schema when active on WooCommerce; be conservative.
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			return true;
		}
		// AIOSEO outputs Product schema for WooCommerce products.
		if ( defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Meta key for the active plugin, or null when unsupported.
	 *
	 * @param string $which title|desc.
	 * @return string|null
	 */
	private function key( $which ) {
		$plugin = $this->detect();
		return isset( self::KEYS[ $plugin ][ $which ] ) ? self::KEYS[ $plugin ][ $which ] : null;
	}

	/* --------------------------------------------------------------------- */
	/* AIOSEO (custom table via its Post model)                              */
	/* --------------------------------------------------------------------- */

	const AIOSEO_MODEL = 'AIOSEO\\Plugin\\Common\\Models\\Post';

	/**
	 * Whether AIOSEO's Post model is callable (read via getPost, write via savePost).
	 *
	 * @return bool
	 */
	private function aioseo_available() {
		return class_exists( self::AIOSEO_MODEL )
			&& method_exists( self::AIOSEO_MODEL, 'getPost' )
			&& method_exists( self::AIOSEO_MODEL, 'savePost' );
	}

	/**
	 * Fetch the AIOSEO Post model for a product (a fresh model for unseen posts).
	 *
	 * @param int $product_id Product id.
	 * @return object|null
	 */
	private function aioseo_model( $product_id ) {
		if ( ! $this->aioseo_available() ) {
			return null;
		}
		try {
			$model = call_user_func( array( self::AIOSEO_MODEL, 'getPost' ), (int) $product_id );
			return is_object( $model ) ? $model : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Write a single field through AIOSEO's savePost (which owns its custom table).
	 * savePost only touches the keys it is given, so per-field writes are safe.
	 *
	 * @param int    $product_id Product id.
	 * @param string $field      title|description.
	 * @param string $value      Value.
	 * @return bool
	 */
	private function aioseo_write( $product_id, $field, $value ) {
		if ( ! $this->aioseo_available() ) {
			return false;
		}
		try {
			call_user_func( array( self::AIOSEO_MODEL, 'savePost' ), (int) $product_id, array( $field => $value ) );
			return true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}
}
