<?php
/**
 * admin-ajax handlers for the Field formats card on the Settings screen (4.28.0).
 *
 * Rezolve Ai learns how the store writes each field (the product description,
 * ACF fields, custom fields) from the store's own products, and writes new
 * content the same way. The list, every change, the free preview and "Learn
 * again" go through the platform (/api/v1/plugin/field-formats), because the
 * same choices are made from the web app and the Shopify app and must hold
 * everywhere. Nothing is stored on the site.
 *
 * @package Agencoen
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Ajax_Field_Formats
 */
class Ajax_Field_Formats extends Ajax_Base {

	/** Modes a merchant can choose here. */
	const MODES = array( 'match', 'preset', 'default', 'custom' );

	/** Parts a template may have; the platform checks each one strictly. */
	const MAX_TEMPLATE_PARTS = 8;

	/** Parts a rewrite can keep exactly as they are. */
	const LOCKS = array( 'links', 'specs', 'kept' );

	/**
	 * Register the actions.
	 */
	public function register() {
		add_action( 'wp_ajax_agencoen_field_formats_list', array( $this, 'list_formats' ) );
		add_action( 'wp_ajax_agencoen_field_formats_detail', array( $this, 'detail' ) );
		add_action( 'wp_ajax_agencoen_field_formats_update', array( $this, 'update_format' ) );
		add_action( 'wp_ajax_agencoen_field_formats_preview', array( $this, 'preview' ) );
		add_action( 'wp_ajax_agencoen_field_formats_relearn', array( $this, 'relearn' ) );
	}

	/**
	 * The opaque target a request names, sanitized. '' when absent.
	 *
	 * @param array $post Unslashed request values.
	 * @return string
	 */
	public static function target_from_request( array $post ) {
		if ( ! isset( $post['target'] ) || ! is_scalar( $post['target'] ) ) {
			return '';
		}
		return substr( sanitize_text_field( (string) $post['target'] ), 0, 1400 );
	}

	/**
	 * The parts of a requested change the platform accepts: mode, preset,
	 * locks and fillEmpty, each optional. Anything else is dropped.
	 *
	 * @param array $post Unslashed request values; `patch` is JSON.
	 * @return array
	 */
	public static function patch_from_request( array $post ) {
		$raw = array();
		if ( isset( $post['patch'] ) && is_string( $post['patch'] ) ) {
			$decoded = json_decode( $post['patch'], true );
			if ( is_array( $decoded ) ) {
				$raw = $decoded;
			}
		}
		$patch = array();
		if ( isset( $raw['mode'] ) && is_string( $raw['mode'] ) && in_array( $raw['mode'], self::MODES, true ) ) {
			$patch['mode'] = $raw['mode'];
		}
		if ( isset( $raw['preset'] ) && is_string( $raw['preset'] ) && preg_match( '/^[a-z0-9-]{1,64}$/', $raw['preset'] ) ) {
			$patch['preset'] = $raw['preset'];
		}
		if ( isset( $raw['locks'] ) && is_array( $raw['locks'] ) ) {
			$locks = array();
			foreach ( self::LOCKS as $lock ) {
				if ( isset( $raw['locks'][ $lock ] ) && is_bool( $raw['locks'][ $lock ] ) ) {
					$locks[ $lock ] = $raw['locks'][ $lock ];
				}
			}
			if ( $locks ) {
				$patch['locks'] = $locks;
			}
		}
		if ( isset( $raw['fillEmpty'] ) && is_bool( $raw['fillEmpty'] ) ) {
			$patch['fillEmpty'] = $raw['fillEmpty'];
		}
		if ( isset( $raw['template'] ) && is_array( $raw['template'] ) ) {
			$template = self::template_from_request( $raw['template'] );
			if ( $template ) {
				$patch['template'] = $template;
			}
		}
		return $patch;
	}

	/**
	 * A merchant's own template, rebuilt from known keys only: each part's kind,
	 * its counts as whole numbers, its style from a fixed list, a heading's
	 * level and its text. Anything else is dropped; the platform checks the rest.
	 *
	 * @param array $parts Decoded parts.
	 * @return array[]
	 */
	public static function template_from_request( array $parts ) {
		$out    = array();
		$counts = function ( $part ) {
			return array(
				'min' => isset( $part['min'] ) ? (int) $part['min'] : 1,
				'max' => isset( $part['max'] ) ? (int) $part['max'] : 1,
			);
		};
		foreach ( array_slice( array_values( $parts ), 0, self::MAX_TEMPLATE_PARTS ) as $part ) {
			if ( ! is_array( $part ) || ! isset( $part['kind'] ) || ! is_string( $part['kind'] ) ) {
				continue;
			}
			switch ( $part['kind'] ) {
				case 'specs':
					$out[] = array_merge( array( 'kind' => 'specs', 'style' => isset( $part['style'] ) && 'table' === $part['style'] ? 'table' : 'label_colon' ), $counts( $part ) );
					break;
				case 'paragraphs':
					$out[] = array_merge( array( 'kind' => 'paragraphs' ), $counts( $part ) );
					break;
				case 'bullets':
					$out[] = array_merge( array( 'kind' => 'bullets', 'ordered' => ! empty( $part['ordered'] ) ), $counts( $part ) );
					break;
				case 'heading':
					$heading = array( 'kind' => 'heading', 'level' => isset( $part['level'] ) && 2 === (int) $part['level'] ? 2 : 3 );
					$text    = isset( $part['text'] ) && is_string( $part['text'] ) ? trim( sanitize_text_field( wp_strip_all_tags( $part['text'] ) ) ) : '';
					if ( '' !== $text ) {
						$heading['text'] = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 80 ) : substr( $text, 0, 80 );
					}
					$out[] = $heading;
					break;
				case 'qa':
					$style = isset( $part['style'] ) && in_array( $part['style'], array( 'bold_question', 'heading_question', 'q_a_lines' ), true ) ? $part['style'] : 'bold_question';
					$out[] = array_merge( array( 'kind' => 'qa', 'style' => $style ), $counts( $part ) );
					break;
				case 'kept':
					$out[] = array( 'kind' => 'kept' );
					break;
			}
		}
		return $out;
	}

	/**
	 * Every field and its format, as the platform lists them.
	 */
	public function list_formats() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$this->require_connected();

		$result = $this->plugin->api()->field_formats( $this->plugin->settings()->get_store_id() );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $this->error_payload( $result ) );
		}
		wp_send_json_success(
			array(
				'enabled' => ! empty( $result['enabled'] ),
				'fields'  => isset( $result['fields'] ) && is_array( $result['fields'] ) ? $result['fields'] : array(),
			)
		);
	}

	/**
	 * One field in full: its labels per product type and the store's examples.
	 */
	public function detail() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$this->require_connected();

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the target is sanitized in target_from_request().
		$target = self::target_from_request( wp_unslash( $_POST ) );
		if ( '' === $target ) {
			wp_send_json_error( array( 'message' => __( 'That field was not recognised.', 'agentic-commerce-engine' ) ) );
		}
		$result = $this->plugin->api()->field_formats( $this->plugin->settings()->get_store_id(), $target );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $this->error_payload( $result ) );
		}
		wp_send_json_success( array( 'field' => isset( $result['field'] ) && is_array( $result['field'] ) ? $result['field'] : array() ) );
	}

	/**
	 * Save one field's choice.
	 */
	public function update_format() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$this->require_connected();

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the target is sanitized and the JSON patch rebuilt from known keys below.
		$post   = wp_unslash( $_POST );
		$target = self::target_from_request( $post );
		$patch  = self::patch_from_request( $post );
		if ( '' === $target || empty( $patch ) ) {
			wp_send_json_error( array( 'message' => __( 'That change was not recognised.', 'agentic-commerce-engine' ) ) );
		}

		$result = $this->plugin->api()->update_field_format( $this->plugin->settings()->get_store_id(), array_merge( array( 'target' => $target ), $patch ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $this->error_payload( $result ) );
		}
		// Whether "Your custom fields" is offered in the enrichment picker
		// depends on having a field to fill: fetch the catalog fresh.
		if ( isset( $patch['fillEmpty'] ) ) {
			$this->plugin->articles()->types( true );
		}
		wp_send_json_success( array( 'field' => isset( $result['field'] ) && is_array( $result['field'] ) ? $result['field'] : array() ) );
	}

	/**
	 * A product's current value laid out in a format. Free: nothing is written.
	 */
	public function preview() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$this->require_connected();

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the target is sanitized and the JSON patch rebuilt from known keys below.
		$post   = wp_unslash( $_POST );
		$target = self::target_from_request( $post );
		if ( '' === $target ) {
			wp_send_json_error( array( 'message' => __( 'That field was not recognised.', 'agentic-commerce-engine' ) ) );
		}
		$result = $this->plugin->api()->preview_field_format(
			$this->plugin->settings()->get_store_id(),
			array_merge( array( 'target' => $target ), self::patch_from_request( $post ) )
		);
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $this->error_payload( $result ) );
		}
		// The layouts are drawn as HTML on the card: cleaned here, not trusted.
		$before = isset( $result['before']['html'] ) && is_string( $result['before']['html'] ) ? wp_kses_post( $result['before']['html'] ) : '';
		$after  = isset( $result['after'] ) && is_array( $result['after'] ) ? $result['after'] : null;
		wp_send_json_success(
			array(
				'product' => array(
					'title' => isset( $result['product']['title'] ) && is_string( $result['product']['title'] ) ? sanitize_text_field( $result['product']['title'] ) : '',
				),
				'parts'   => isset( $result['parts'] ) && is_array( $result['parts'] ) ? $result['parts'] : array(),
				'before'  => $before,
				'after'   => null === $after ? null : array(
					'html'         => isset( $after['html'] ) && is_string( $after['html'] ) ? wp_kses_post( $after['html'] ) : '',
					'leftOut'      => isset( $after['leftOut'] ) && is_array( $after['leftOut'] ) ? array_map( 'sanitize_text_field', $after['leftOut'] ) : array(),
					'writtenLater' => isset( $after['writtenLater'] ) && is_array( $after['writtenLater'] ) ? array_map( 'sanitize_text_field', $after['writtenLater'] ) : array(),
				),
			)
		);
	}

	/**
	 * Learn every format again now. Asynchronous; at most once a minute.
	 */
	public function relearn() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$this->require_connected();

		$result = $this->plugin->api()->relearn_field_formats( $this->plugin->settings()->get_store_id() );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $this->error_payload( $result ) );
		}
		wp_send_json_success( array( 'requested' => true ) );
	}
}
