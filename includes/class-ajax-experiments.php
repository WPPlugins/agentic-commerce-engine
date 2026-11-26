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

	/** One filter cannot carry more values than the platform's parser keeps. */
	const MAX_FACET_VALUES = 50;

	/** Past this, a hand-picked list is really a filter nobody wrote down. */
	const MAX_PICKED = 500;

	/**
	 * Register every experiment action behind the shared gate chain.
	 */
	public function register() {
		$actions = array(
			'experiments_list'      => 'list_experiments',
			'experiment_get'        => 'get_experiment',
			'experiment_subjects'   => 'subjects',
			'experiment_facets'     => 'facets',
			'experiment_resolve'    => 'resolve',
			'experiment_products'   => 'products',
			'experiment_suggest'    => 'suggest',
			'experiment_groups'     => 'groups',
			'experiment_group_save' => 'save_group',
			'experiment_group_delete' => 'delete_group',
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
	 * Every catalogue filter the selection control offers.
	 */
	public function facets() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$this->respond( $this->plugin->experiments()->facets( ! empty( $_POST['fresh'] ) ) );
	}

	/**
	 * What the current selection resolves to: a count, and optionally a page of
	 * products. Never the whole id list -- see `class-api-client.php`.
	 */
	public function resolve() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();

		$body = array(
			'selection' => $this->selection_from_request(),
			'type'      => $this->split_from_request(),
			'countOnly' => empty( $_POST['withProducts'] ),
		);
		if ( ! empty( $_POST['withProducts'] ) ) {
			$body['page']     = isset( $_POST['page'] ) ? max( 0, min( 1000, (int) $_POST['page'] ) ) : 0;
			$body['pageSize'] = 20;
		}
		$this->respond( $this->plugin->experiments()->resolve_subjects( $body ) );
	}

	/**
	 * A page of products for hand-picking.
	 */
	public function products() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$query = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
		$page  = isset( $_POST['page'] ) ? max( 0, min( 1000, (int) $_POST['page'] ) ) : 0;
		$this->respond( $this->plugin->experiments()->products( $query, $page ) );
	}

	/**
	 * A model proposes a cohort from a sentence the merchant typed.
	 *
	 * THIS SPENDS CREDITS, and it is slow: see the raised timeout on
	 * `experiment_suggest_subjects`. A refusal comes back inside a successful
	 * response with `ok: false`, and the screen renders the platform's own
	 * wording unchanged.
	 */
	public function suggest() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$brief = isset( $_POST['brief'] ) ? sanitize_textarea_field( wp_unslash( $_POST['brief'] ) ) : '';
		$brief = trim( mb_substr( $brief, 0, 300 ) );
		if ( '' === $brief ) {
			wp_send_json_error(
				array( 'message' => __( 'Describe the products first.', 'agentic-commerce-engine' ) )
			);
		}
		$body = array( 'brief' => $brief );
		if ( isset( $_POST['arms'] ) ) {
			$body['arms'] = max( 2, min( 8, (int) $_POST['arms'] ) );
		}
		if ( 'visitor_split' === $this->split_from_request() ) {
			// A shopper split holds nothing back, so its power floor is lower.
			$body['holdoutFraction'] = 0;
		}
		$this->respond( $this->plugin->experiments()->suggest_subjects( $body ) );
	}

	/**
	 * Saved product selections.
	 */
	public function groups() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$this->respond( $this->plugin->experiments()->groups( ! empty( $_POST['fresh'] ) ) );
	}

	/**
	 * Save the current selection as a named group.
	 */
	public function save_group() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$name = trim( mb_substr( $name, 0, 60 ) );
		if ( '' === $name ) {
			wp_send_json_error( array( 'message' => __( 'Name this group.', 'agentic-commerce-engine' ) ) );
		}
		$this->respond(
			$this->plugin->experiments()->save_group(
				array(
					'name'      => $name,
					'selection' => $this->selection_from_request(),
				)
			)
		);
	}

	/**
	 * Delete a saved group. Running experiments keep the products they started
	 * with; the screen says so before asking.
	 */
	public function delete_group() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$id = isset( $_POST['groupId'] ) ? sanitize_text_field( wp_unslash( $_POST['groupId'] ) ) : '';
		if ( '' === $id ) {
			wp_send_json_error( array( 'message' => __( 'Nothing to delete.', 'agentic-commerce-engine' ) ) );
		}
		$this->respond( $this->plugin->experiments()->delete_group( $id ) );
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
	/** The split this request describes, held to the two the platform accepts. */
	private function split_from_request() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : 'visitor_split';
		return in_array( $type, array( 'visitor_split', 'product_holdout' ), true ) ? $type : 'visitor_split';
	}

	/**
	 * The product selection this request describes, as a spec.
	 *
	 * BUILT FIELD BY FIELD, never `json_decode` of a raw POST body. Everything
	 * here arrives from a browser on a merchant's own admin: each value is
	 * unslashed and sanitised on its own, every array is capped, and a key this
	 * does not know about is dropped rather than forwarded to the platform.
	 *
	 * An absent `selectionVersion` means a cached pre-release script, which is
	 * handled by the caller: see `design_from_request`.
	 *
	 * @return array
	 */
	private function selection_from_request() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$facets = array(
			'categories'   => array(),
			'collections'  => array(),
			'productTypes' => array(),
			'vendors'      => array(),
			'tags'         => array(),
		);
		foreach ( array_keys( $facets ) as $key ) {
			$field = 'facet_' . $key;
			if ( isset( $_POST[ $field ] ) && is_array( $_POST[ $field ] ) ) {
				$values = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST[ $field ] ) );
				$values = array_values( array_filter( array_unique( $values ), 'strlen' ) );
				$facets[ $key ] = array_slice( $values, 0, self::MAX_FACET_VALUES );
			}
		}

		$ids = array(
			'includeIds' => array(),
			'excludeIds' => array(),
		);
		foreach ( array_keys( $ids ) as $key ) {
			if ( isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ) {
				$values = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST[ $key ] ) );
				$values = array_values( array_filter( array_unique( $values ), 'strlen' ) );
				$ids[ $key ] = array_slice( $values, 0, self::MAX_PICKED );
			}
		}

		$everything = ! empty( $_POST['everything'] );
		$group_id   = isset( $_POST['groupId'] ) ? sanitize_text_field( wp_unslash( $_POST['groupId'] ) ) : '';

		return array(
			'version'    => 1,
			// "all" ONLY when the merchant ticked the everything box. An empty
			// set of facets is "nothing chosen yet", which resolves to no
			// products and is refused -- not a silent enrolment of the catalogue.
			'mode'       => $everything ? 'all' : 'selection',
			'facets'     => $everything ? array(
				'categories'   => array(),
				'collections'  => array(),
				'productTypes' => array(),
				'vendors'      => array(),
				'tags'         => array(),
			) : $facets,
			'query'      => null,
			'includeIds' => $everything ? array() : $ids['includeIds'],
			// Exclusions survive "everything": all of it except these twelve is
			// a real and useful selection.
			'excludeIds' => $ids['excludeIds'],
			// Provenance only. The platform never dereferences it: a group is
			// COPIED into a selection when it is picked, so editing the group
			// afterwards cannot change a test that has already started.
			'origin'     => '' !== $group_id
				? array(
					'kind'        => 'group',
					'fromGroupId' => $group_id,
				)
				: null,
		);
	}

	private function design_from_request() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$type     = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : 'visitor_split';
		$category = isset( $_POST['category'] ) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : '';
		$levels   = isset( $_POST['levels'] ) ? (int) $_POST['levels'] : 0;
		$days     = isset( $_POST['days'] ) ? (int) $_POST['days'] : 28;
		$goal     = isset( $_POST['goal'] ) ? sanitize_text_field( wp_unslash( $_POST['goal'] ) ) : '';
		$watched  = isset( $_POST['guardrailGoals'] ) && is_array( $_POST['guardrailGoals'] )
			? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['guardrailGoals'] ) )
			: array();

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
		// The form disables the boxes past the cap, so this is only reachable
		// from a script cached before it did, or from a POST the form did not
		// make. Refused HERE with the platform's own sentence rather than sent
		// on, because the platform's answer to a design this wide is "Request
		// body failed validation", which names nothing a merchant can act on.
		$cap = self::factor_cap( $options );
		if ( count( $factors ) > $cap['max'] ) {
			wp_send_json_error( array( 'message' => $cap['reason'] ) );
		}

		// THE PRODUCTS, AS A SPEC.
		//
		// The platform resolves it for the power check and again for the create,
		// so the ids never cross this server at all and the three surfaces
		// cannot end up running different tests from the same choices. The
		// slice that used to sit here is gone with them: the platform caps.
		//
		// BACK COMPAT. A POST with no `selectionVersion` came from a script
		// cached before this release, which knows only the category dropdown.
		// It takes the old path unchanged -- the same trick `contentChosen`
		// above plays for the content checkboxes, and for the same reason: a
		// merchant does not update a plugin because we shipped a wizard.
		$subject_key = 'selection';
		if ( empty( $_POST['selectionVersion'] ) ) {
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
			$subject_value = array_slice( $ids, 0, self::MAX_SUBJECTS );
			$subject_key   = 'subjectIds';
		} else {
			$subject_value = $this->selection_from_request();
		}

		$goal   = '' !== $goal ? $goal : self::first_goal( $type, $options );
		$design = array(
			'type'       => $type,
			'factors'    => $factors,
			$subject_key => $subject_value,
			'days'       => $days,
			// Sent to the power check as well as to create: a product split is
			// sized against the goal it will measure, and orders are far rarer
			// than visits.
			'goal'       => $goal,
		);
		// A product split's hold-back share. "auto", or nothing from an older
		// form, sends nothing and the platform chooses on the products' traffic.
		$share = isset( $_POST['holdoutFraction'] ) ? sanitize_text_field( wp_unslash( $_POST['holdoutFraction'] ) ) : '';
		if ( 'product_holdout' === $type && in_array( $share, array( '0.2', '0.5' ), true ) ) {
			$design['holdoutFraction'] = (float) $share;
		}
		// Only when some were ticked, so a design with none is the body every
		// platform build has always accepted. The power check ignores them.
		$secondary = self::secondary_goals( $type, $goal, $watched, $options );
		if ( ! empty( $secondary ) ) {
			$design['guardrailGoals'] = $secondary;
		}
		return $design;
	}

	/**
	 * The goals to watch beside the headline, kept only where the platform
	 * offers them for this split and this headline, once each.
	 *
	 * A tick left over from the other split, or on the goal since chosen as the
	 * headline, is dropped here rather than sent: the platform would store it
	 * and the merchant would see a line for something nobody reads out.
	 *
	 * @param string $type      The split.
	 * @param string $goal      The headline goal.
	 * @param array  $submitted Goal ids the form sent.
	 * @param mixed  $options   The platform's options payload.
	 * @return string[]
	 */
	public static function secondary_goals( $type, $goal, array $submitted, $options ) {
		$goals   = ( is_array( $options ) && isset( $options['goals'] ) && is_array( $options['goals'] ) ) ? $options['goals'] : array();
		$offered = array();
		foreach ( $goals as $g ) {
			if ( is_array( $g ) && isset( $g['id'], $g['secondaryFor'] ) && is_array( $g['secondaryFor'] )
				&& in_array( $type, $g['secondaryFor'], true ) && (string) $g['id'] !== (string) $goal ) {
				$offered[] = (string) $g['id'];
			}
		}
		$out = array();
		foreach ( $submitted as $id ) {
			$id = (string) $id;
			if ( in_array( $id, $offered, true ) && ! in_array( $id, $out, true ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/**
	 * How many pieces of content one design may carry, and why no more.
	 *
	 * From the platform, which enforces the same number in the schema behind
	 * the power check and the launch. The fallback is for a plugin talking to
	 * a platform from before the cap was sent: without one this server would
	 * pass a design of five straight through and hand the merchant "Request
	 * body failed validation", which names neither the limit nor the choice
	 * that crossed it.
	 *
	 * @param mixed $options The platform's options payload.
	 * @return array{max:int,reason:string}
	 */
	private static function factor_cap( $options ) {
		$max = ( is_array( $options ) && isset( $options['maxFactors'] ) ) ? (int) $options['maxFactors'] : 0;
		if ( $max < 1 ) {
			$max = 4;
		}
		$reason = ( is_array( $options ) && ! empty( $options['maxFactorsReason'] ) )
			? (string) $options['maxFactorsReason']
			: sprintf(
				/* translators: %d: how many pieces of content one experiment may cover. */
				__( 'A test compares every combination, so one experiment covers at most %d pieces of content. Take one out to test this instead.', 'agentic-commerce-engine' ),
				$max
			);
		return array(
			'max'    => $max,
			'reason' => $reason,
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
