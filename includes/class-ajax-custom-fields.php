<?php
/**
 * admin-ajax handlers for the Custom fields card on the Settings screen.
 *
 * The list and every change go through the platform (/api/v1/plugin/custom-fields),
 * because the same choices are edited from the web app and the Shopify app and
 * must hold everywhere. The local option is refreshed from each response, so the
 * very next sync already honours a change.
 *
 * @package Agencoen
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Ajax_Custom_Fields
 */
class Ajax_Custom_Fields extends Ajax_Base {

	/** Changes the card can make. */
	const INTENTS = array( 'exclude', 'include', 'rewrite-on', 'rewrite-off' );

	/** Fields one change may name: far above a page of the table (25). */
	const MAX_FIELDS = 500;

	/**
	 * Register the actions.
	 */
	public function register() {
		add_action( 'wp_ajax_agencoen_custom_fields_list', array( $this, 'list_fields' ) );
		add_action( 'wp_ajax_agencoen_custom_fields_update', array( $this, 'update_field' ) );
	}

	/**
	 * The store's custom fields as the platform lists them.
	 */
	public function list_fields() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$this->require_connected();

		$result = $this->plugin->api()->custom_fields( $this->plugin->settings()->get_store_id() );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $this->error_payload( $result ) );
		}
		wp_send_json_success(
			array(
				'fields' => isset( $result['fields'] ) && is_array( $result['fields'] ) ? $result['fields'] : array(),
			)
		);
	}

	/**
	 * The fields a change names: the table's `fields` list (JSON, so a bulk
	 * Leave out is one request), or the single `id` and `label` that 4.24.0
	 * sent. Deduplicated, capped at MAX_FIELDS, each value sanitized.
	 *
	 * @param array $post Unslashed request values.
	 * @return array<int, array{id: string, label: string}>
	 */
	public static function targets_from_request( array $post ) {
		$entries = array();
		if ( isset( $post['fields'] ) && is_string( $post['fields'] ) ) {
			$decoded = json_decode( $post['fields'], true );
			if ( is_array( $decoded ) ) {
				$entries = $decoded;
			}
		} elseif ( isset( $post['id'] ) ) {
			$entries = array(
				array(
					'id'    => $post['id'],
					'label' => isset( $post['label'] ) ? $post['label'] : '',
				),
			);
		}

		$targets = array();
		$seen    = array();
		foreach ( array_slice( $entries, 0, self::MAX_FIELDS ) as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['id'] ) || ! is_scalar( $entry['id'] ) ) {
				continue;
			}
			$id = sanitize_text_field( (string) $entry['id'] );
			if ( '' === $id || isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$targets[]   = array(
				'id'    => $id,
				'label' => isset( $entry['label'] ) && is_scalar( $entry['label'] ) ? sanitize_text_field( (string) $entry['label'] ) : '',
			);
		}
		return $targets;
	}

	/**
	 * The platform patch for one change to these fields.
	 *
	 * @param string                                       $intent  One of INTENTS.
	 * @param array<int, array{id: string, label: string}> $targets From targets_from_request().
	 * @return array
	 */
	public static function patch_for( $intent, array $targets ) {
		$ids = array_column( $targets, 'id' );
		if ( 'exclude' === $intent ) {
			return array( 'exclude' => $targets );
		}
		if ( 'include' === $intent ) {
			return array( 'include' => $ids );
		}
		if ( 'rewrite-on' === $intent ) {
			return array( 'rewriteOn' => $ids );
		}
		return array( 'rewriteOff' => $ids );
	}

	/**
	 * Leave fields out, use them again, or allow or stop rewriting them.
	 */
	public function update_field() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$this->require_connected();

		$intent = isset( $_POST['intent'] ) ? sanitize_key( wp_unslash( $_POST['intent'] ) ) : '';
		// Unslashed before decoding; every id and label is sanitized in targets_from_request().
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON; each entry is sanitized in targets_from_request().
		$targets = self::targets_from_request( wp_unslash( $_POST ) );
		if ( ! in_array( $intent, self::INTENTS, true ) || empty( $targets ) ) {
			wp_send_json_error( array( 'message' => __( 'That change was not recognised.', 'agentic-commerce-engine' ) ) );
		}

		$settings = $this->plugin->settings();
		$result   = $this->plugin->api()->update_custom_fields( $settings->get_store_id(), self::patch_for( $intent, $targets ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $this->error_payload( $result ) );
		}

		// Mirror the exclusions locally so the next sync honours them, and
		// re-send a field brought back: it was stripped when it was left out.
		$changed = $settings->save_custom_fields(
			array(
				'revision' => isset( $result['revision'] ) ? $result['revision'] : 0,
				'excluded' => isset( $result['excluded'] ) && is_array( $result['excluded'] ) ? $result['excluded'] : array(),
			)
		);
		// Whether "Your custom fields" is offered in the enrichment picker depends
		// on having a field opted in, and the picker reads a cached catalog: fetch
		// it fresh so the choice made here shows up there straight away.
		if ( 'rewrite-on' === $intent || 'rewrite-off' === $intent ) {
			$this->plugin->articles()->types( true );
		}

		$syncing = false;
		if ( 'include' === $intent && $changed ) {
			if ( ! wp_next_scheduled( Cron::CATALOG_RESYNC ) ) {
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, Cron::CATALOG_RESYNC );
			}
			$syncing = (bool) wp_next_scheduled( Cron::CATALOG_RESYNC );
		}

		wp_send_json_success(
			array(
				'fields'       => isset( $result['fields'] ) && is_array( $result['fields'] ) ? $result['fields'] : array(),
				'resyncNeeded' => ! empty( $result['resyncNeeded'] ),
				'syncing'      => $syncing,
			)
		);
	}
}
