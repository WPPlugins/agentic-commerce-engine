<?php
/**
 * Storefront rendering for applied content: IRL scenario cards, internal links,
 * highlights and product Q&A, via shortcodes, dynamic Gutenberg blocks, and
 * placement on the product page.
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

	/** @var string[] Types placed in a product tab of their own (4.25.0). */
	private $tab_types = array();

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
		add_shortcode( 'agencoen_highlights', array( $this, 'shortcode_highlights' ) );
		add_shortcode( 'agencoen_product_qa', array( $this, 'shortcode_qa' ) );
		add_action( 'init', array( $this, 'register_blocks' ) );
		/*
		 * PLACEMENT, NOT ONE HARDCODED HOOK.
		 *
		 * This used to be a single `woocommerce_after_single_product_summary`
		 * registration behind one global on/off toggle, so a merchant could
		 * have the cards or not have them, and that was the whole choice.
		 * Meanwhile the Shopify app guessed at a position by scanning the DOM.
		 * Two platforms, two different answers, neither of them the
		 * merchant's. Now both read the same per-content-type placement from
		 * the platform. See src/lib/content-placement.ts.
		 *
		 * Registered per content type, because a merchant can reasonably want
		 * the scenario card under the description and the internal links at
		 * the very bottom.
		 */
		foreach ( Settings::placeable_types() as $agencoen_type ) {
			$this->register_placement( $agencoen_type );
		}
		if ( ! empty( $this->tab_types ) ) {
			add_filter( 'woocommerce_product_tabs', array( $this, 'product_tabs' ), 25 );
		}
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
	 * Register the four dynamic (server-rendered) blocks; no build step required.
	 */
	public function register_blocks() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		wp_register_script(
			'agencoen-blocks',
			AGENCOEN_PLUGIN_URL . 'assets/js/block.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-server-side-render' ),
			AGENCOEN_VERSION,
			true
		);
		// The storefront stylesheet doubles as the editor style: with
		// apiVersion 3 the editor canvas is an iframe, and only styles a
		// block declares reach it, so the previews look like the storefront.
		wp_register_style( 'agencoen-frontend', AGENCOEN_PLUGIN_URL . 'assets/css/frontend.css', array(), AGENCOEN_VERSION );
		register_block_type(
			'agencoen/irl',
			array(
				'api_version'     => 3,
				'editor_script'   => 'agencoen-blocks',
				'editor_style'    => 'agencoen-frontend',
				'render_callback' => array( $this, 'block_irl' ),
			)
		);
		register_block_type(
			'agencoen/internal-links',
			array(
				'api_version'     => 3,
				'editor_script'   => 'agencoen-blocks',
				'editor_style'    => 'agencoen-frontend',
				'render_callback' => array( $this, 'block_links' ),
			)
		);
		register_block_type(
			'agencoen/highlights',
			array(
				'api_version'     => 3,
				'editor_script'   => 'agencoen-blocks',
				'editor_style'    => 'agencoen-frontend',
				'render_callback' => array( $this, 'block_highlights' ),
			)
		);
		register_block_type(
			'agencoen/product-qa',
			array(
				'api_version'     => 3,
				'editor_script'   => 'agencoen-blocks',
				'editor_style'    => 'agencoen-frontend',
				'render_callback' => array( $this, 'block_qa' ),
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
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function shortcode_highlights( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'agencoen_highlights' );
		$pid  = (int) $atts['id'] ? (int) $atts['id'] : $this->current_product_id();
		return $pid ? $this->render_highlights_html( $pid ) : '';
	}

	/**
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function shortcode_qa( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'agencoen_product_qa' );
		$pid  = (int) $atts['id'] ? (int) $atts['id'] : $this->current_product_id();
		return $pid ? $this->render_qa_html( $pid ) : '';
	}

	/**
	 * @return string
	 */
	public function block_highlights() {
		$pid = $this->current_product_id();
		return $pid ? $this->render_highlights_html( $pid ) : '';
	}

	/**
	 * @return string
	 */
	public function block_qa() {
		$pid = $this->current_product_id();
		return $pid ? $this->render_qa_html( $pid ) : '';
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
	 * Hook one content type onto the action its placement maps to.
	 *
	 * `manual` and `hidden` register nothing: the first means the merchant
	 * places the shortcode or block themselves, the second means they asked
	 * for the content to stay off the page entirely (it still reaches feeds
	 * and AI agents, which is the point of keeping it generated).
	 *
	 * `above_description` runs at priority 19 inside
	 * `woocommerce_single_product_summary`, one below the core
	 * `woocommerce_template_single_excerpt` at 20, so it lands immediately
	 * above the short description rather than somewhere near it.
	 *
	 * @param string $type One of Settings::placeable_types().
	 */
	private function register_placement( $type ) {
		$placement = $this->plugin->settings()->get_placement( $type );

		// A tab of its own (4.25.0): registered once for every such type, in
		// product_tabs(), because WooCommerce builds its tabs in one filter.
		if ( 'product_tab' === $placement ) {
			$this->tab_types[] = $type;
			return;
		}

		$hook     = '';
		$priority = 25;
		if ( 'below_description' === $placement ) {
			$hook = 'woocommerce_after_single_product_summary';
		} elseif ( 'above_description' === $placement ) {
			$hook     = 'woocommerce_single_product_summary';
			$priority = 19;
		} elseif ( 'bottom_of_page' === $placement ) {
			$hook = 'woocommerce_after_single_product';
		}

		if ( '' === $hook ) {
			return;
		}

		add_action(
			$hook,
			function () use ( $type ) {
				$this->render_placed( $type );
			},
			$priority
		);
	}

	/**
	 * Echo one placed content type on a product page.
	 *
	 * `is_auto_append()` remains the master switch. It predates placement, and
	 * a merchant who turned auto-append off must not have content reappear
	 * under them on upgrade just because placement now defaults to a position.
	 *
	 * @param string $type One of Settings::placeable_types().
	 */
	private function render_placed( $type ) {
		if ( ! $this->plugin->settings()->is_auto_append() ) {
			return;
		}
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}
		$pid = (int) get_queried_object_id();
		if ( ! $pid ) {
			return;
		}

		$html = $this->render_type_html( $type, $pid );

		if ( '' !== $html ) {
			echo wp_kses_post( $html );
		}
	}

	/**
	 * Add a tab for each type placed in one, beside Description and
	 * Additional information. A product with nothing to show for a type gets
	 * no empty tab. The auto-append switch governs tabs as it does the other
	 * automatic placements.
	 *
	 * @param array $tabs WooCommerce product tabs.
	 * @return array
	 */
	public function product_tabs( $tabs ) {
		if ( ! $this->plugin->settings()->is_auto_append() || ! is_array( $tabs ) ) {
			return $tabs;
		}
		$pid = (int) get_queried_object_id();
		if ( ! $pid ) {
			return $tabs;
		}
		$titles   = array(
			'irl-cards'      => __( 'How customers use this', 'agentic-commerce-engine' ),
			'internal-links' => __( 'You might also like', 'agentic-commerce-engine' ),
			'highlights'     => __( 'Highlights', 'agentic-commerce-engine' ),
			'product-qa'     => __( 'Questions and answers', 'agentic-commerce-engine' ),
		);
		$priority = 30;
		foreach ( $this->tab_types as $type ) {
			$html = $this->render_type_html( $type, $pid, false );
			if ( '' === $html ) {
				continue;
			}
			$tabs[ 'agencoen_' . str_replace( '-', '_', $type ) ] = array(
				/**
				 * The title of a Rezolve Ai product tab.
				 *
				 * @param string $title Default title, translated.
				 * @param string $type  irl-cards | internal-links | highlights | product-qa.
				 * @param int    $pid   Product id.
				 */
				'title'    => (string) apply_filters( 'agencoen_product_tab_title', isset( $titles[ $type ] ) ? $titles[ $type ] : $type, $type, $pid ),
				'priority' => $priority++,
				'callback' => function () use ( $html ) {
					echo wp_kses_post( $html );
				},
			);
		}
		return $tabs;
	}

	/**
	 * One type's HTML for a product, or ''.
	 *
	 * @param string $type         One of Settings::placeable_types().
	 * @param int    $pid          Product id.
	 * @param bool   $with_heading False inside a tab, which has its own title.
	 * @return string
	 */
	private function render_type_html( $type, $pid, $with_heading = true ) {
		if ( 'irl-cards' === $type ) {
			return $this->render_irl_html( $pid, $with_heading );
		}
		if ( 'internal-links' === $type ) {
			return $this->render_links_html( $pid, $with_heading );
		}
		if ( 'highlights' === $type ) {
			return $this->render_highlights_html( $pid, $with_heading );
		}
		if ( 'product-qa' === $type ) {
			return $this->render_qa_html( $pid, $with_heading );
		}
		return '';
	}

	/* --------------------------------------------------------------------- */
	/* Renderers (return escaped HTML)                                       */
	/* --------------------------------------------------------------------- */

	/**
	 * @param int  $product_id   Product id.
	 * @param bool $with_heading Include the heading.
	 * @return string
	 */
	private function render_irl_html( $product_id, $with_heading = true ) {
		$scenarios = get_post_meta( $product_id, Meta::IRL, true );
		if ( ! is_array( $scenarios ) || empty( $scenarios ) ) {
			return '';
		}
		$out = '<section class="agencoen-irl">' . ( $with_heading ? '<h2 class="agencoen-irl-heading">' . esc_html__( 'How customers use this', 'agentic-commerce-engine' ) . '</h2>' : '' ) . '<div class="agencoen-irl-grid">';
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
	 * @param int  $product_id   Product id.
	 * @param bool $with_heading Include the heading.
	 * @return string
	 */
	private function render_links_html( $product_id, $with_heading = true ) {
		$links = get_post_meta( $product_id, Meta::LINKS, true );
		if ( ! is_array( $links ) || empty( $links ) ) {
			return '';
		}
		$out = '<nav class="agencoen-links" aria-label="' . esc_attr__( 'Related products', 'agentic-commerce-engine' ) . '">' . ( $with_heading ? '<h2 class="agencoen-links-heading">' . esc_html__( 'You might also like', 'agentic-commerce-engine' ) . '</h2>' : '' ) . '<ul>';
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
	 * @param int  $product_id   Product id.
	 * @param bool $with_heading Include the heading.
	 * @return string
	 */
	private function render_highlights_html( $product_id, $with_heading = true ) {
		$highlights = Pdp_Content::highlights( get_post_meta( $product_id, Meta::HIGHLIGHTS, true ) );
		if ( empty( $highlights ) ) {
			return '';
		}
		$out = '<section class="agencoen-highlights" aria-label="' . esc_attr__( 'Product highlights', 'agentic-commerce-engine' ) . '">' . ( $with_heading ? '<h2 class="agencoen-highlights-heading">' . esc_html__( 'Highlights', 'agentic-commerce-engine' ) . '</h2>' : '' ) . '<ul>';
		foreach ( $highlights as $highlight ) {
			$out .= '<li>' . esc_html( $highlight ) . '</li>';
		}
		return $out . '</ul></section>';
	}

	/**
	 * Questions and answers as <details>, which open and close with no
	 * JavaScript, are accessible by default, and keep every answer in the HTML
	 * for crawlers. Tells Schema the Q&A is on the page, which is what allows the
	 * FAQPage markup in the footer.
	 *
	 * @param int  $product_id   Product id.
	 * @param bool $with_heading Include the heading.
	 * @return string
	 */
	private function render_qa_html( $product_id, $with_heading = true ) {
		$qa = Pdp_Content::qa( get_post_meta( $product_id, Meta::QA, true ) );
		if ( empty( $qa ) ) {
			return '';
		}
		$this->plugin->schema()->note_qa_rendered( $product_id );
		$out = '<section class="agencoen-qa" aria-label="' . esc_attr__( 'Questions and answers about this product', 'agentic-commerce-engine' ) . '">' . ( $with_heading ? '<h2 class="agencoen-qa-heading">' . esc_html__( 'Questions and answers', 'agentic-commerce-engine' ) . '</h2>' : '' );
		foreach ( $qa as $pair ) {
			$out .= '<details class="agencoen-qa-item"><summary>' . esc_html( $pair['question'] ) . '</summary><div class="agencoen-qa-answer">' . nl2br( esc_html( $pair['answer'] ) ) . '</div></details>';
		}
		return $out . '</section>';
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
