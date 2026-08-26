<?php
/**
 * admin-ajax handlers for the Experiments screen.
 *
 * Every action runs behind the shared gate chain: nonce, then capability, then
 * connection, then WooCommerce. The order matters and each failing on its own
 * matters more: verifying the nonce FIRST means a request that cannot prove it
 * came from this admin learns nothing at all, not even whether the site is
 * connected or whether WooCommerce is active.
 *
 * WHY THESE ARE WOO-GATED
 * Every subject is a WooCommerce product. A per-visitor split needs a product
 * page to swap copy on and a purchase to attribute, and `Ab_Test` hooks
 * `woocommerce_thankyou` for the second half. A Woo-less WordPress site running
 * this plugin has content and crawler analytics, and nothing to experiment on.
 *
 * @package Agencoen
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Ajax_Experiments
 */
class Ajax_Experiments extends Ajax_Base {

	/** Bounded so a runaway selection cannot turn a preview into a scan. */
	const MAX_SUBJECTS = 5000;

	/**
	 * Register every experiment action behind the shared gate chain.
	 */
	public function register() {
		$actions = array(
			'experiments_list'      => 'list_experiments',
			'experiment_get'        => 'get_experiment',
			'experiment_subjects'   => 'subjects',
			'experiment_preflight'  => 'preflight',
			'experiment_create'     => 'create',
			'experiment_action'     => 'act',
		);
		foreach ( $actions as $action => $method ) {
			add_action(
				'wp_ajax_agencoen_' . $action,
				function () use ( $method ) {
					check_ajax_referer( self::NONCE_ACTION, 'nonce' );
					$this->require_manage();
					$this->require_connected();
					$this->require_woo();
					$this->$method();
				}
			);
		}
	}

	/* --------------------------------------------------------------------- */

	/**
	 * The list.
	 */
	public function list_experiments() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$fresh  = ! empty( $_POST['fresh'] );
		$result = $this->plugin->experiments()->all( (bool) $fresh );
		$this->respond( $result );
	}

	/**
	 * One experiment.
	 */
	public function get_experiment() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$id = isset( $_POST['experimentId'] ) ? sanitize_text_field( wp_unslash( $_POST['experimentId'] ) ) : '';
		if ( '' === $id ) {
			wp_send_json_error( array( 'message' => __( 'Which experiment?', 'agentic-commerce-engine' ) ) );
		}
		$fresh = ! empty( $_POST['fresh'] );
		$this->respond( $this->plugin->experiments()->one( $id, (bool) $fresh ) );
	}

	/**
	 * Products and categories to choose between.
	 */
	public function subjects() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$category = isset( $_POST['category'] ) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : '';
		$this->respond( $this->plugin->experiments()->subjects( '' !== $category ? $category : null ) );
	}

	/**
	 * The costless power check.
	 */
	public function preflight() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$this->respond( $this->plugin->experiments()->preflight( $this->design_from_request() ) );
	}

	/**
	 * Create, which spends credits.
	 */
	public function create() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$body = $this->design_from_request();

		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		if ( '' === $name ) {
			wp_send_json_error( array( 'message' => __( 'Give this test a name.', 'agentic-commerce-engine' ) ) );
		}
		$body['name']       = $name;
		$body['goal']       = isset( $_POST['goal'] ) ? sanitize_text_field( wp_unslash( $_POST['goal'] ) ) : 'checkout';
		$body['acceptSlow'] = ! empty( $_POST['acceptSlow'] );

		$this->respond( $this->plugin->experiments()->create( $body ) );
	}

	/**
	 * A lifecycle action.
	 */
	public function act() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$id     = isset( $_POST['experimentId'] ) ? sanitize_text_field( wp_unslash( $_POST['experimentId'] ) ) : '';
		$action = isset( $_POST['experimentAction'] ) ? sanitize_text_field( wp_unslash( $_POST['experimentAction'] ) ) : '';
		if ( '' === $id || '' === $action ) {
			wp_send_json_error( array( 'message' => __( 'Nothing to do.', 'agentic-commerce-engine' ) ) );
		}

		$extra = array();
		if ( 'rename' === $action ) {
			$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
			if ( '' === $name ) {
				wp_send_json_error( array( 'message' => __( 'Give this test a name.', 'agentic-commerce-engine' ) ) );
			}
			$extra['name'] = $name;
		}

		$this->respond( $this->plugin->experiments()->act( $id, $action, $extra ) );
	}

	/* --------------------------------------------------------------------- */

	/**
	 * The design half of a preflight or create body, resolved server-side.
	 *
	 * The PRODUCT IDS ARE RESOLVED HERE and not accepted from the browser.
	 * A category holding three thousand products would otherwise be a form post
	 * the size of the catalogue, and one a client could edit into a set the
	 * merchant never chose.
	 *
	 * @return array
	 */
	private function design_from_request() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$type     = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : 'visitor_split';
		$category = isset( $_POST['category'] ) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : '';
		$levels   = isset( $_POST['levels'] ) ? (int) $_POST['levels'] : 2;
		$days     = isset( $_POST['days'] ) ? (int) $_POST['days'] : 28;

		if ( ! in_array( $type, array( 'visitor_split', 'product_holdout' ), true ) ) {
			$type = 'visitor_split';
		}
		$levels = max( 2, min( 4, $levels ) );
		$days   = max( 7, min( 180, $days ) );

		$subjects = $this->plugin->experiments()->subjects( '' !== $category ? $category : null );
		if ( is_wp_error( $subjects ) ) {
			wp_send_json_error( $this->error_payload( $subjects ) );
		}
		$ids = isset( $subjects['ids'] ) && is_array( $subjects['ids'] ) ? $subjects['ids'] : array();
		if ( empty( $ids ) ) {
			wp_send_json_error(
				array( 'message' => __( 'No products matched that selection.', 'agentic-commerce-engine' ) )
			);
		}

		return array(
			'type'       => $type,
			'factors'    => array(
				array(
					'contentTypeId' => 'product-description',
					'levels'        => array_slice( array( 'a', 'b', 'c', 'd' ), 0, $levels ),
				),
			),
			'subjectIds' => array_slice( $ids, 0, self::MAX_SUBJECTS ),
			'days'       => $days,
		);
	}

	/**
	 * One shape for every answer.
	 *
	 * A platform refusal is the MERCHANT'S answer, not an internal error:
	 * "not enough traffic", "a split is already running", "too few products"
	 * are all things they can act on, so the message travels through unchanged
	 * rather than being replaced with something generic.
	 *
	 * @param array|\WP_Error $result Result.
	 */
	private function respond( $result ) {
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $this->error_payload( $result ) );
		}
		wp_send_json_success( $result );
	}
}
