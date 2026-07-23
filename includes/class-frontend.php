<?php
/**
 * Storefront rendering for applied content: IRL scenario cards and internal
 * links, via shortcodes, dynamic Gutenberg blocks, and an optional auto-append
 * below the product summary.
 *
 * @package Agencoen
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Frontend
 */
class Frontend {

	/** @var Plugin */
	private $plugin;

	/**
	 * @param Plugin $plugin Orchestrator.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Hook shortcodes, blocks, auto-append, and styles.
	 */
	public function register() {
		add_shortcode( 'agencoen_irl', array( $this, 'shortcode_irl' ) );
		add_shortcode( 'agencoen_internal_links', array( $this, 'shortcode_links' ) );
		add_action( 'init', array( $this, 'register_blocks' ) );
		add_action( 'woocommerce_after_single_product_summary', array( $this, 'auto_append' ), 25 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
	}

	/**
	 * Enqueue the small storefront stylesheet on product pages.
	 */
	public function enqueue_styles() {
		if ( function_exists( 'is_product' ) && is_product() ) {
			wp_enqueue_style( 'agencoen-frontend', AGENCOEN_PLUGIN_URL . 'assets/css/frontend.css', array(), AGENCOEN_VERSION );
		}
	}

	/**
	 * Register two dynamic (server-rendered) blocks; no build step required.
	 */
	public function register_blocks() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		wp_register_script(
			'agencoen-blocks',
			AGENCOEN_PLUGIN_URL . 'assets/js/block.js',
			array( 'wp-blocks', 'wp-element', 'wp-server-side-render' ),
			AGENCOEN_VERSION,
			true
		);
		register_block_type(
			'agencoen/irl',
			array(
				'api_version'     => 2,
				'editor_script'   => 'agencoen-blocks',
				'render_callback' => array( $this, 'block_irl' ),
			)
		);
		register_block_type(
			'agencoen/internal-links',
			array(
				'api_version'     => 2,
				'editor_script'   => 'agencoen-blocks',
				'render_callback' => array( $this, 'block_links' ),
			)
		);
	}

	/* --------------------------------------------------------------------- */
	/* Shortcodes + blocks                                                   */
	/* --------------------------------------------------------------------- */

	/**
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function shortcode_irl( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'agencoen_irl' );
		$pid  = (int) $atts['id'] ? (int) $atts['id'] : $this->current_product_id();
		return $pid ? $this->render_irl_html( $pid ) : '';
	}

	/**
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function shortcode_links( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'agencoen_internal_links' );
		$pid  = (int) $atts['id'] ? (int) $atts['id'] : $this->current_product_id();
		return $pid ? $this->render_links_html( $pid ) : '';
	}

	/**
	 * @return string
	 */
	public function block_irl() {
		$pid = $this->current_product_id();
		return $pid ? $this->render_irl_html( $pid ) : '';
	}

	/**
	 * @return string
	 */
	public function block_links() {
		$pid = $this->current_product_id();
		return $pid ? $this->render_links_html( $pid ) : '';
	}

	/**
	 * Auto-append IRL cards + internal links below the product summary.
	 */
	public function auto_append() {
		if ( ! $this->plugin->settings()->is_auto_append() ) {
			return;
		}
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}
		$pid  = (int) get_queried_object_id();
		$html = $this->render_irl_html( $pid ) . $this->render_links_html( $pid );
		if ( '' !== $html ) {
			echo wp_kses_post( $html );
		}
	}

	/* --------------------------------------------------------------------- */
	/* Renderers (return escaped HTML)                                       */
	/* --------------------------------------------------------------------- */

	/**
	 * @param int $product_id Product id.
	 * @return string
	 */
	private function render_irl_html( $product_id ) {
		$scenarios = get_post_meta( $product_id, Meta::IRL, true );
		if ( ! is_array( $scenarios ) || empty( $scenarios ) ) {
			return '';
		}
		$out = '<section class="agencoen-irl"><h2 class="agencoen-irl-heading">' . esc_html__( 'How customers use this', 'agentic-commerce-engine' ) . '</h2><div class="agencoen-irl-grid">';
		foreach ( $scenarios as $scenario ) {
			if ( ! is_array( $scenario ) ) {
				continue;
			}
			$title = isset( $scenario['title'] ) ? (string) $scenario['title'] : '';
			$desc  = isset( $scenario['description'] ) ? (string) $scenario['description'] : '';
			$out  .= '<div class="agencoen-irl-card">';
			if ( '' !== $title ) {
				$out .= '<h3>' . esc_html( $title ) . '</h3>';
			}
			if ( '' !== $desc ) {
				$out .= '<p>' . esc_html( $desc ) . '</p>';
			}
			$features = isset( $scenario['keyFeatures'] ) && is_array( $scenario['keyFeatures'] ) ? $scenario['keyFeatures'] : array();
			if ( ! empty( $features ) ) {
				$out .= '<ul>';
				foreach ( $features as $feature ) {
					$out .= '<li>' . esc_html( (string) $feature ) . '</li>';
				}
				$out .= '</ul>';
			}
			$out .= '</div>';
		}
		$out .= '</div></section>';
		return $out;
	}

	/**
	 * @param int $product_id Product id.
	 * @return string
	 */
	private function render_links_html( $product_id ) {
		$links = get_post_meta( $product_id, Meta::LINKS, true );
		if ( ! is_array( $links ) || empty( $links ) ) {
			return '';
		}
		$out = '<nav class="agencoen-links" aria-label="' . esc_attr__( 'Related products', 'agentic-commerce-engine' ) . '"><h2 class="agencoen-links-heading">' . esc_html__( 'You might also like', 'agentic-commerce-engine' ) . '</h2><ul>';
		foreach ( $links as $link ) {
			if ( ! is_array( $link ) ) {
				continue;
			}
			$url = isset( $link['targetUrl'] ) ? (string) $link['targetUrl'] : '';
			if ( '' === $url ) {
				continue;
			}
			$anchor = isset( $link['anchorText'] ) && '' !== $link['anchorText'] ? (string) $link['anchorText'] : $url;
			$out   .= '<li><a href="' . esc_url( $url ) . '">' . esc_html( $anchor ) . '</a></li>';
		}
		$out .= '</ul></nav>';
		return $out;
	}

	/**
	 * Resolve the product id in the current rendering context.
	 *
	 * @return int
	 */
	private function current_product_id() {
		if ( function_exists( 'is_product' ) && is_product() ) {
			return (int) get_queried_object_id();
		}
		return (int) get_the_ID();
	}
}
