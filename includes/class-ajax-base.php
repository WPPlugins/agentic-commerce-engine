<?php
/**
 * Shared plumbing for every admin-ajax handler group.
 *
 * One nonce (agencoen_admin), one capability resolution (Capabilities::manage,
 * mode-aware), one error payload shape. Handler groups extend this and
 * register their own wp_ajax_agencoen_* actions.
 *
 * @package Agencoen
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Ajax_Base
 */
abstract class Ajax_Base {

	const NONCE_ACTION = 'agencoen_admin';

	/** @var Plugin */
	protected $plugin;

	/**
	 * @param Plugin $plugin Orchestrator.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Nonce + capability gate, in that order and each failing on its own.
	 *
	 * Used by the registration wrappers so that nothing at all, not even the
	 * connection state, is probed before the nonce is verified. Handlers do
	 * not rely on this: each one calls check_ajax_referer() itself as its
	 * literal first statement, then require_manage().
	 */
	protected function guard() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
	}

	/**
	 * Capability half of the gate. Always preceded by a nonce check.
	 */
	protected function require_manage() {
		if ( ! Capabilities::current_user_can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'agentic-commerce-engine' ) ), 403 );
		}
	}

	/**
	 * Stop unless connected.
	 */
	protected function require_connected() {
		if ( ! $this->plugin->settings()->is_connected() ) {
			wp_send_json_error( array( 'message' => __( 'Connect your site first.', 'agentic-commerce-engine' ) ) );
		}
	}

	/**
	 * Stop unless WooCommerce is active (commerce-suite handlers).
	 */
	protected function require_woo() {
		if ( ! $this->plugin->has_woo() ) {
			wp_send_json_error( array( 'message' => __( 'This feature needs WooCommerce.', 'agentic-commerce-engine' ) ), 400 );
		}
	}

	/**
	 * Build a UI-friendly error payload, flagging credit exhaustion.
	 *
	 * @param \WP_Error $error Error.
	 * @return array
	 */
	protected function error_payload( $error ) {
		$code   = $error->get_error_code();
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;

		$payload = array(
			'message' => $error->get_error_message(),
			'code'    => $code,
		);

		if ( 'INSUFFICIENT_CREDITS' === $code || 402 === $status ) {
			$payload['insufficientCredits'] = true;
			$payload['accountUrl']          = $this->plugin->settings()->get_base_url();
		}
		return $payload;
	}
}
