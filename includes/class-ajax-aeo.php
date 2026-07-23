<?php
/**
 * admin-ajax handlers for the AI Search research console. Every action runs
 * behind the shared nonce + capability guard, requires a connected store, and
 * is gated on the server-declared ecomAeo feature flag.
 *
 * @package Agencoen
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Ajax_Aeo
 */
class Ajax_Aeo extends Ajax_Base {

	const MAX_PROMPTS      = 200;
	const MAX_PROMPT_CHARS = 300;
	const MIN_PROMPT_CHARS = 3;

	/**
	 * Register every AI Search action behind the shared gate chain.
	 */
	public function register() {
		$actions = array(
			'aeo_overview'        => 'overview',
			'aeo_config'          => 'config',
			'aeo_prompts'         => 'prompts',
			'aeo_prompts_save'    => 'prompts_save',
			'aeo_prompts_suggest' => 'prompts_suggest',
			'aeo_runs'            => 'runs',
			'aeo_run_get'         => 'run_get',
			'aeo_run_start'       => 'run_start',
			'aeo_estimate'        => 'estimate',
			'aeo_flow_create'     => 'flow_create',
		);
		foreach ( $actions as $action => $method ) {
			add_action(
				'wp_ajax_agencoen_' . $action,
				function () use ( $method ) {
					// Verified here so nothing, not even the connection state,
					// is probed before the nonce. Each handler repeats the
					// check as its own first statement, so the guarantee is
					// local to the method that reads the input.
					check_ajax_referer( self::NONCE_ACTION, 'nonce' );
					$this->require_manage();
					$this->require_connected();
					$this->require_feature();
					$this->$method();
				}
			);
		}
	}

	/**
	 * Stop unless the platform enabled AI Search research for this deployment.
	 */
	private function require_feature() {
		if ( ! $this->plugin->settings()->feature_enabled( 'ecomAeo' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'AI Search research is not enabled for this ACE deployment.', 'agentic-commerce-engine' ) )
			);
		}
	}

	/**
	 * Overview KPIs + trend, optionally focused on one run.
	 */
	public function overview() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$run_id = isset( $_POST['run_id'] ) ? sanitize_text_field( wp_unslash( $_POST['run_id'] ) ) : '';
		$this->respond( $this->plugin->aeo()->overview( '' !== $run_id ? $run_id : null ) );
	}

	/**
	 * Research configurator payload.
	 */
	public function config() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$fresh = ! empty( $_POST['fresh'] );
		$this->respond( $this->plugin->aeo()->config( $fresh ) );
	}

	/**
	 * Prompt list.
	 */
	public function prompts() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$page = isset( $_POST['page'] ) ? max( 1, absint( wp_unslash( $_POST['page'] ) ) ) : 1;
		$this->respond( $this->plugin->aeo()->prompts( $page ) );
	}

	/**
	 * Append or replace the prompt set.
	 */
	public function prompts_save() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$lines   = isset( $_POST['prompts'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['prompts'] ) ) : array();
		$prompts = $this->normalize_prompts( $lines );
		if ( empty( $prompts ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Add at least one prompt (three characters or more, one per line).', 'agentic-commerce-engine' ) )
			);
		}

		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'append';
		if ( ! in_array( $mode, array( 'append', 'replace' ), true ) ) {
			$mode = 'append';
		}

		$this->respond( $this->plugin->aeo()->save_prompts( $prompts, $mode ) );
	}

	/**
	 * AI prompt suggestions.
	 */
	public function prompts_suggest() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$args  = array();
		$topic = isset( $_POST['topic'] ) ? sanitize_text_field( wp_unslash( $_POST['topic'] ) ) : '';
		if ( '' !== $topic ) {
			$args['topic'] = $this->clip( $topic, 300 );
		}
		if ( ! empty( $_POST['branded'] ) ) {
			$args['branded'] = true;
		}

		$this->respond( $this->plugin->aeo()->suggest( $args ) );
	}

	/**
	 * Run list (+ snapshot presence).
	 */
	public function runs() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$this->respond( $this->plugin->aeo()->runs( ! empty( $_POST['fresh'] ) ) );
	}

	/**
	 * Per-run detail (snapshot rows or live status).
	 */
	public function run_get() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$run_id = isset( $_POST['run_id'] ) ? sanitize_text_field( wp_unslash( $_POST['run_id'] ) ) : '';
		if ( '' === $run_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing run id.', 'agentic-commerce-engine' ) ) );
		}
		$this->respond( $this->plugin->aeo()->run( $run_id ) );
	}

	/**
	 * Launch a research run.
	 */
	public function run_start() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$models       = isset( $_POST['models'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['models'] ) ) : array();
		$region       = isset( $_POST['region_code'] ) ? sanitize_text_field( wp_unslash( $_POST['region_code'] ) ) : '';
		$language     = isset( $_POST['language_code'] ) ? sanitize_text_field( wp_unslash( $_POST['language_code'] ) ) : '';
		$use_defaults = ! empty( $_POST['use_defaults'] );
		$this->respond( $this->plugin->aeo()->start_run( $this->build_run_args( $models, $region, $language, $use_defaults ) ) );
	}

	/**
	 * Credit estimate for the current run configuration.
	 */
	public function estimate() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$models       = isset( $_POST['models'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['models'] ) ) : array();
		$region       = isset( $_POST['region_code'] ) ? sanitize_text_field( wp_unslash( $_POST['region_code'] ) ) : '';
		$language     = isset( $_POST['language_code'] ) ? sanitize_text_field( wp_unslash( $_POST['language_code'] ) ) : '';
		$use_defaults = ! empty( $_POST['use_defaults'] );
		$this->respond( $this->plugin->aeo()->estimate( $this->build_run_args( $models, $region, $language, $use_defaults ) ) );
	}

	/**
	 * Create + link the research flow.
	 */
	public function flow_create() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$name  = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$brand = isset( $_POST['brand_name'] ) ? sanitize_text_field( wp_unslash( $_POST['brand_name'] ) ) : '';
		if ( strlen( $name ) < 2 || '' === $brand ) {
			wp_send_json_error(
				array( 'message' => __( 'Enter a flow name and your brand name.', 'agentic-commerce-engine' ) )
			);
		}

		$args = array(
			'name'      => $this->clip( $name, 120 ),
			'brandName' => $this->clip( $brand, 120 ),
		);

		$description = isset( $_POST['description'] ) ? sanitize_text_field( wp_unslash( $_POST['description'] ) ) : '';
		if ( '' !== $description ) {
			$args['description'] = $this->clip( $description, 500 );
		}

		$this->respond( $this->plugin->aeo()->create_flow( $args ) );
	}

	/* --------------------------------------------------------------------- */
	/* Input readers                                                         */
	/* --------------------------------------------------------------------- */

	/**
	 * Normalize sanitized prompt lines: drop short lines, clip long ones, and
	 * enforce the platform's batch cap.
	 *
	 * @param string[] $lines Sanitized prompt lines.
	 * @return array<int,array{text:string}>
	 */
	private function normalize_prompts( array $lines ) {
		$prompts = array();
		foreach ( $lines as $line ) {
			$text = trim( (string) $line );
			if ( strlen( $text ) < self::MIN_PROMPT_CHARS ) {
				continue;
			}
			$prompts[] = array( 'text' => $this->clip( $text, self::MAX_PROMPT_CHARS ) );
			if ( count( $prompts ) >= self::MAX_PROMPTS ) {
				break;
			}
		}
		return $prompts;
	}

	/**
	 * Shape sanitized run inputs into the platform's run-config array.
	 *
	 * @param string[] $models       Sanitized model keys.
	 * @param string   $region       Sanitized region code ('' = flow default).
	 * @param string   $language     Sanitized language code ('' = flow default).
	 * @param bool     $use_defaults Use the flow's saved defaults.
	 * @return array { models?, regionCode?, languageCode?, useFlowDefaults? }
	 */
	private function build_run_args( array $models, $region, $language, $use_defaults ) {
		$args  = array();
		$clean = array();
		foreach ( $models as $model ) {
			$model = trim( (string) $model );
			if ( '' !== $model ) {
				$clean[] = $model;
			}
		}
		if ( ! empty( $clean ) ) {
			$args['models'] = array_slice( array_values( array_unique( $clean ) ), 0, 20 );
		}

		if ( '' !== $region ) {
			$args['regionCode'] = $this->clip( $region, 10 );
		}

		if ( '' !== $language ) {
			$args['languageCode'] = $this->clip( $language, 10 );
		}

		if ( $use_defaults ) {
			$args['useFlowDefaults'] = true;
		}

		return $args;
	}

	/* --------------------------------------------------------------------- */
	/* Shared plumbing                                                       */
	/* --------------------------------------------------------------------- */

	/**
	 * Send the standard success/error envelope for a service result.
	 *
	 * @param array|\WP_Error $result Service result.
	 */
	private function respond( $result ) {
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $this->error_payload( $result ) );
		}
		wp_send_json_success( $result );
	}

	/**
	 * Multibyte-safe truncation.
	 *
	 * @param string $value  Input.
	 * @param int    $length Max characters.
	 * @return string
	 */
	private function clip( $value, $length ) {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( (string) $value, 0, $length );
		}
		return substr( (string) $value, 0, $length );
	}
}
