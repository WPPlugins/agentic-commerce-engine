<?php
/**
 * Turns an ACE article payload into a WordPress post.
 *
 * materialize() is idempotent (the brief id is the natural key), writes SEO
 * meta through the Seo_Adapter, and converts the article HTML into Gutenberg
 * block markup when the site prefers blocks. html_to_blocks() is a pure static
 * so the block mapping is unit-testable without WordPress.
 *
 * @package Agencoen
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Publisher
 */
class Publisher {

	const MAX_TAGS = 8;

	/** @var Plugin */
	private $plugin;

	/**
	 * @param Plugin $plugin Orchestrator.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/* --------------------------------------------------------------------- */
	/* Materialize                                                           */
	/* --------------------------------------------------------------------- */

	/**
	 * Create or update the WP post for an article payload (keyed by brief id).
	 *
	 * @param array $detail Article payload from GET /plugin/articles/{briefId}.
	 * @param array $opts   { status? } Explicit post status; defaults to 'draft'
	 *                      (materialize-on-complete). A live post is never
	 *                      demoted back to draft by a re-materialize.
	 * @return int|\WP_Error Post id.
	 */
	public function materialize( array $detail, array $opts = array() ) {
		$brief_id = isset( $detail['id'] ) ? (string) $detail['id'] : '';
		if ( '' === $brief_id ) {
			return new \WP_Error( 'agencoen_no_brief', __( 'The article payload is missing its brief id.', 'agentic-commerce-engine' ) );
		}

		$html = isset( $detail['html'] ) ? (string) $detail['html'] : '';
		if ( '' === trim( $html ) ) {
			return new \WP_Error( 'agencoen_no_article', __( 'This brief has no generated article yet.', 'agentic-commerce-engine' ) );
		}

		$settings = $this->plugin->settings();
		$existing = $this->find_by_brief( $brief_id );

		$status = isset( $opts['status'] ) ? sanitize_key( (string) $opts['status'] ) : 'draft';
		if ( ! in_array( $status, array( 'draft', 'publish', 'pending', 'private', 'future' ), true ) ) {
			$status = 'draft';
		}
		if ( $existing && 'publish' === get_post_status( $existing ) && 'publish' !== $status ) {
			$status = 'publish';
		}

		$author = $settings->get_content_author();
		if ( $author <= 0 ) {
			$author = (int) get_current_user_id(); // 0 in cron context; WP assigns a default owner.
		}

		$content = 'blocks' === $settings->get_content_format()
			? self::html_to_blocks( $html )
			: wp_kses_post( $html );

		$postarr = array(
			'post_title'   => sanitize_text_field( isset( $detail['title'] ) ? (string) $detail['title'] : '' ),
			'post_status'  => $status,
			'post_content' => $content,
		);
		if ( $author > 0 ) {
			$postarr['post_author'] = $author;
		}

		if ( $existing ) {
			$postarr['ID'] = $existing; // keep the existing post_type (post or page).
			$post_id       = wp_update_post( wp_slash( $postarr ), true );
		} else {
			$postarr['post_type'] = 'post';
			$post_id              = wp_insert_post( wp_slash( $postarr ), true );
		}
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		$post_id = (int) $post_id;

		update_post_meta( $post_id, Meta::BRIEF_ID, $brief_id );
		update_post_meta( $post_id, Meta::ARTICLE_SOURCE, 'ace' );

		if ( $settings->is_article_schema() && ! empty( $detail['schema'] ) ) {
			$schema = $detail['schema'];
			if ( is_string( $schema ) ) {
				$decoded = json_decode( $schema, true );
				$schema  = is_array( $decoded ) ? $decoded : null;
			}
			if ( is_array( $schema ) && ! empty( $schema ) ) {
				update_post_meta( $post_id, Meta::ARTICLE_JSONLD, wp_slash( (string) wp_json_encode( $schema ) ) );
			}
		}

		if ( 'publish' === get_post_status( $post_id ) ) {
			update_post_meta( $post_id, Meta::ARTICLE_PUBLISHED_AT, gmdate( 'c' ) );
		}

		// SEO title/description through the adapter (Yoast, Rank Math, AIOSEO).
		if ( ! empty( $detail['seoTitle'] ) ) {
			$this->plugin->seo()->write_seo_title( $post_id, (string) $detail['seoTitle'] );
		}
		if ( ! empty( $detail['seoDescription'] ) ) {
			$this->plugin->seo()->write_seo_description( $post_id, (string) $detail['seoDescription'] );
		}

		if ( 'post' === get_post_type( $post_id ) && ! empty( $detail['seoKeywords'] ) && is_array( $detail['seoKeywords'] ) ) {
			$tags = array_slice(
				array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'strval', $detail['seoKeywords'] ) ) ) ),
				0,
				self::MAX_TAGS
			);
			if ( ! empty( $tags ) ) {
				wp_set_post_terms( $post_id, $tags, 'post_tag', false );
			}
		}

		return $post_id;
	}

	/**
	 * Flip an ACE-authored post live.
	 *
	 * @param int $post_id Post id.
	 * @return string|\WP_Error Permalink of the live post.
	 */
	public function publish( $post_id ) {
		$post_id = (int) $post_id;
		if ( '' === (string) get_post_meta( $post_id, Meta::BRIEF_ID, true ) ) {
			return new \WP_Error( 'agencoen_not_ace', __( 'This post was not created by ACE.', 'agentic-commerce-engine' ) );
		}

		$result = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		update_post_meta( $post_id, Meta::ARTICLE_PUBLISHED_AT, gmdate( 'c' ) );
		return (string) get_permalink( $post_id );
	}

	/**
	 * Trash an ACE-authored post (guarded: never trashes foreign posts).
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	public function discard( $post_id ) {
		$post_id = (int) $post_id;
		if ( '' === (string) get_post_meta( $post_id, Meta::BRIEF_ID, true ) ) {
			return false;
		}
		return (bool) wp_trash_post( $post_id );
	}

	/**
	 * Find the post backing a brief (trash excluded so a discarded article can
	 * be re-created cleanly).
	 *
	 * @param string $brief_id Brief id.
	 * @return int Post id, or 0.
	 */
	private function find_by_brief( $brief_id ) {
		$query = new \WP_Query(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- single-row lookup on an indexed meta key.
				'meta_query'     => array(
					array(
						'key'   => Meta::BRIEF_ID,
						'value' => $brief_id,
					),
				),
			)
		);
		return ! empty( $query->posts ) ? (int) $query->posts[0] : 0;
	}

	/* --------------------------------------------------------------------- */
	/* Pure HTML -> Gutenberg blocks (unit-tested directly)                  */
	/* --------------------------------------------------------------------- */

	/**
	 * Convert article HTML into Gutenberg block markup.
	 *
	 * Top-level mapping: h1..h4 -> core/heading (h1 demoted to h2), p ->
	 * core/paragraph, ul/ol -> core/list, img -> core/image, blockquote ->
	 * core/quote, table -> core/table, hr -> core/separator; anything else
	 * (divs, sections, product cards) is preserved verbatim in a core/html
	 * block. Malformed input degrades to a single core/html block.
	 *
	 * Pure: no WP functions except wp_kses_post, which is skipped when absent
	 * so the standalone test runner can exercise the mapping.
	 *
	 * @param string $html Article HTML.
	 * @return string Block markup.
	 */
	public static function html_to_blocks( $html ) {
		$html = (string) $html;
		if ( function_exists( 'wp_kses_post' ) ) {
			$html = wp_kses_post( $html );
		}
		if ( '' === trim( $html ) ) {
			return '';
		}
		if ( ! class_exists( '\DOMDocument' ) ) {
			return self::block( 'html', trim( $html ) );
		}

		try {
			$previous = libxml_use_internal_errors( true );
			$doc      = new \DOMDocument( '1.0', 'UTF-8' );
			$loaded   = $doc->loadHTML(
				'<html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>' . $html . '</body></html>',
				LIBXML_HTML_NODEFDTD
			);
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );

			$body = $doc->getElementsByTagName( 'body' )->item( 0 );
			if ( ! $loaded || ! $body ) {
				return self::block( 'html', trim( $html ) );
			}

			$blocks = array();
			foreach ( $body->childNodes as $node ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API.
				$piece = self::node_to_block( $doc, $node );
				if ( '' !== $piece ) {
					$blocks[] = $piece;
				}
			}

			if ( empty( $blocks ) ) {
				return self::block( 'html', trim( $html ) );
			}
			return implode( "\n\n", $blocks );
		} catch ( \Throwable $e ) {
			return self::block( 'html', trim( $html ) );
		}
	}

	/**
	 * Map one top-level DOM node onto a serialized block.
	 *
	 * @param \DOMDocument $doc  Owning document (serializer).
	 * @param \DOMNode     $node Node.
	 * @return string Block markup, or '' to skip.
	 */
	private static function node_to_block( \DOMDocument $doc, $node ) {
		if ( XML_COMMENT_NODE === $node->nodeType ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API.
			return '';
		}

		// Bare top-level text becomes a paragraph.
		if ( XML_TEXT_NODE === $node->nodeType || XML_CDATA_SECTION_NODE === $node->nodeType ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API.
			$text = trim( $node->textContent ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API.
			if ( '' === $text ) {
				return '';
			}
			return self::block( 'paragraph', '<p>' . htmlspecialchars( $text, ENT_QUOTES ) . '</p>' );
		}

		if ( XML_ELEMENT_NODE !== $node->nodeType ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API.
			return '';
		}

		$tag = strtolower( $node->nodeName ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API.

		switch ( $tag ) {
			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
				$level = 'h1' === $tag ? 2 : (int) substr( $tag, 1 );
				$inner = self::serialize( $doc, $node );
				if ( 'h1' === $tag ) {
					$inner = preg_replace( '/^<h1(\b[^>]*)?>/i', '<h2$1>', $inner );
					$inner = preg_replace( '/<\/h1>\s*$/i', '</h2>', $inner );
				}
				$attrs = 2 === $level ? '' : ' {"level":' . $level . '}';
				return '<!-- wp:heading' . $attrs . " -->\n" . $inner . "\n<!-- /wp:heading -->";

			case 'p':
				// Skip visually empty paragraphs (no text, no embedded elements).
				if ( '' === trim( $node->textContent ) && 0 === $node->getElementsByTagName( '*' )->length ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM API.
					return '';
				}
				return self::block( 'paragraph', self::serialize( $doc, $node ) );

			case 'ul':
				return self::block( 'list', self::serialize( $doc, $node ) );

			case 'ol':
				return '<!-- wp:list {"ordered":true} -->' . "\n" . self::serialize( $doc, $node ) . "\n<!-- /wp:list -->";

			case 'img':
				return self::block( 'image', '<figure class="wp-block-image">' . self::serialize( $doc, $node ) . '</figure>' );

			case 'blockquote':
				$class = trim( (string) $node->getAttribute( 'class' ) );
				if ( false === strpos( ' ' . $class . ' ', ' wp-block-quote ' ) ) {
					$node->setAttribute( 'class', trim( $class . ' wp-block-quote' ) );
				}
				return self::block( 'quote', self::serialize( $doc, $node ) );

			case 'table':
				return self::block( 'table', '<figure class="wp-block-table">' . self::serialize( $doc, $node ) . '</figure>' );

			case 'hr':
				return self::block( 'separator', '<hr class="wp-block-separator has-alpha-channel-opacity"/>' );

			default:
				return self::block( 'html', self::serialize( $doc, $node ) );
		}
	}

	/**
	 * Serialize a node back to HTML.
	 *
	 * @param \DOMDocument $doc  Owning document.
	 * @param \DOMNode     $node Node.
	 * @return string
	 */
	private static function serialize( \DOMDocument $doc, $node ) {
		$html = $doc->saveHTML( $node );
		return is_string( $html ) ? trim( $html ) : '';
	}

	/**
	 * Wrap inner HTML in block comments.
	 *
	 * @param string $name  Block name without the core/ prefix.
	 * @param string $inner Inner HTML.
	 * @return string
	 */
	private static function block( $name, $inner ) {
		return '<!-- wp:' . $name . " -->\n" . $inner . "\n<!-- /wp:" . $name . ' -->';
	}
}
