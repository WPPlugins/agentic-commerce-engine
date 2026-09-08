<?php
/**
 * admin-ajax handlers for the native AI crawler tracker. The summary read
 * works entirely from local data (no connection required); only the manual
 * report-back needs a connected store.
 *
 * @package Agencoen
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Ajax_Bots
 */
class Ajax_Bots extends Ajax_Base {

	/**
	 * Register the crawler actions.
	 */
	public function register() {
		add_action( 'wp_ajax_agencoen_bots_summary', array( $this, 'summary' ) );
		add_action( 'wp_ajax_agencoen_bots_report_now', array( $this, 'report_now' ) );
	}

	/**
	 * Crawlers-tab payload for a 7/30/90 day window (local data only).
	 */
	public function summary() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();

		$days = isset( $_POST['days'] ) ? absint( wp_unslash( $_POST['days'] ) ) : 30;
		if ( ! in_array( $days, array( 7, 30, 90 ), true ) ) {
			$days = 30;
		}

		wp_send_json_success( $this->plugin->bots()->summary( $days ) );
	}

	/**
	 * Manual "Send now": push the last two days of aggregates to Rezolve Ai.
	 */
	public function report_now() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$this->require_connected();

		$result = $this->plugin->bots()->report_back();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $this->error_payload( $result ) );
		}

		wp_send_json_success( array( 'reported' => true ) );
	}
}
