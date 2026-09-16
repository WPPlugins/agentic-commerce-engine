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
			'experiment_options'    => 'options',
			'experiment_preflight'  => 'preflight',
			'experiment_create'     => 'create',
			'experiment_action'     => 'act',
			'experiment_apply_winner' => 'apply_winner',
			'experiment_history'    => 'history',
			'experiment_value'      => 'value',
			'experiment_note'       => 'save_note',
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
	 * Finished tests and what each one found.
	 */
	public function history() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$query = array(
			'verdict' => isset( $_POST['verdict'] ) ? sanitize_text_field( wp_unslash( $_POST['verdict'] ) ) : '',
			'q'       => isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '',
			'page'    => isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 0,
		);
		$this->respond( $this->plugin->experiments()->history( $query ) );
	}

	/**
	 * What testing has been worth.
	 */
	public function value() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$fresh = ! empty( $_POST['fresh'] );
		$this->respond( $this->plugin->experiments()->value( (bool) $fresh ) );
	}

	/**
	 * Save what the merchant learned from a finished test.
	 */
	public function save_note() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$id = isset( $_POST['experimentId'] ) ? sanitize_text_field( wp_unslash( $_POST['experimentId'] ) ) : '';
		if ( '' === $id ) {
			wp_send_json_error( array( 'message' => __( 'Which experiment?', 'agentic-commerce-engine' ) ) );
		}
		// `sanitize_textarea_field`, not `sanitize_text_field`: a note is prose
		// and its line breaks are part of what the merchant wrote.
		$notes = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';
		$this->respond( $this->plugin->experiments()->save_note( $id, $notes ) );
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
	 * What the create form may offer, from the platform.
	 */
	public function options() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$this->respond( $this->plugin->experiments()->options() );
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

	/**
	 * Keep the winning version for every shopper.
	 */
	public function apply_winner() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$id  = isset( $_POST['experimentId'] ) ? sanitize_text_field( wp_unslash( $_POST['experimentId'] ) ) : '';
		$arm = isset( $_POST['arm'] ) ? sanitize_text_field( wp_unslash( $_POST['arm'] ) ) : '';
		if ( '' === $id || '' === $arm ) {
			wp_send_json_error( array( 'message' => __( 'Nothing to do.', 'agentic-commerce-engine' ) ) );
		}

		$this->respond( $this->plugin->experiments()->apply_winner( $id, $arm ) );
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
		$levels   = isset( $_POST['levels'] ) ? (int) $_POST['levels'] : 0;
		$days     = isset( $_POST['days'] ) ? (int) $_POST['days'] : 28;
		$goal     = isset( $_POST['goal'] ) ? sanitize_text_field( wp_unslash( $_POST['goal'] ) ) : '';

		// Sent by the 4.19.0 form, which also sends `contentChosen` because an
		// empty selection posts no `contentTypes` at all. A script cached from an
		// earlier version sends neither, and gets the description it has always
		// tested.
		$content = empty( $_POST['contentChosen'] ) ? array( 'product-description' ) : array();
		if ( isset( $_POST['contentTypes'] ) && is_array( $_POST['contentTypes'] ) ) {
			$content = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['contentTypes'] ) );
		}

		if ( ! in_array( $type, array( 'visitor_split', 'product_holdout' ), true ) ) {
			$type = 'visitor_split';
		}
		$days = max( 7, min( 180, $days ) );

		$options = $this->plugin->experiments()->options();
		if ( is_wp_error( $options ) ) {
			wp_send_json_error( $this->error_payload( $options ) );
		}
		$factors = self::build_factors( $type, $content, $levels, $options );
		if ( empty( $factors ) ) {
			wp_send_json_error( array( 'message' => __( 'Choose something to test.', 'agentic-commerce-engine' ) ) );
		}

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
			'factors'    => $factors,
			'subjectIds' => array_slice( $ids, 0, self::MAX_SUBJECTS ),
			'days'       => $days,
			// Sent to the power check as well as to create: a product split is
			// sized against the goal it will measure, and orders are far rarer
			// than visits.
			'goal'       => '' !== $goal ? $goal : self::first_goal( $type, $options ),
		);
	}

	/**
	 * The factors a design sends, held to the rules the platform validates.
	 *
	 * A shopper split swaps versions, so each factor needs at least two. A
	 * product split compares against products left unchanged, so one version is
	 * a whole test. Content with no writing angles is written once, in house
	 * style, because asking for more would bill copies that come back identical.
	 * Only content the split can use is sent. The platform refuses a design that
	 * breaks these rules; applying them here keeps a merchant from being refused
	 * for a combination the form let them make.
	 *
	 * @param string $type    Split.
	 * @param array  $content Content type ids the merchant chose.
	 * @param int    $levels  Versions asked for.
	 * @param mixed  $options The platform's options payload.
	 * @return array
	 */
	public static function build_factors( $type, array $content, $levels, $options ) {
		$min    = 'visitor_split' === $type ? 2 : 1;
		$levels = max( $min, min( 4, (int) $levels ) );

		$offered = array();
		$list    = ( is_array( $options ) && isset( $options['factors'][ $type ] ) && is_array( $options['factors'][ $type ] ) )
			? $options['factors'][ $type ]
			: array();
		foreach ( $list as $factor ) {
			if ( is_array( $factor ) && ! empty( $factor['available'] ) && isset( $factor['contentTypeId'] ) ) {
				$offered[ (string) $factor['contentTypeId'] ] = ! empty( $factor['angleTestable'] );
			}
		}

		$factors = array();
		foreach ( array_values( array_unique( array_map( 'strval', $content ) ) ) as $id ) {
			if ( ! array_key_exists( $id, $offered ) ) {
				continue;
			}
			if ( $offered[ $id ] ) {
				$factors[] = array(
					'contentTypeId' => $id,
					'levels'        => array_slice( array( 'a', 'b', 'c', 'd' ), 0, $levels ),
				);
			} elseif ( 'product_holdout' === $type ) {
				$factors[] = array(
					'contentTypeId' => $id,
					'levels'        => array( 'a' ),
				);
			}
		}
		return $factors;
	}

	/**
	 * The first goal a split can headline, in the platform's order.
	 *
	 * @param string $type    Split.
	 * @param mixed  $options The platform's options payload.
	 * @return string
	 */
	private static function first_goal( $type, $options ) {
		$goals = ( is_array( $options ) && isset( $options['goals'] ) && is_array( $options['goals'] ) ) ? $options['goals'] : array();
		foreach ( $goals as $goal ) {
			if ( is_array( $goal ) && empty( $goal['guardrailOnly'] ) && isset( $goal['types'], $goal['id'] )
				&& is_array( $goal['types'] ) && in_array( $type, $goal['types'], true ) ) {
				return (string) $goal['id'];
			}
		}
		return '';
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
