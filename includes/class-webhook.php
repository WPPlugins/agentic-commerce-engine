<?php
/**
 * Receives signed callbacks from the Rezolve Ai platform.
 *
 * Since 4.0 the platform's durable webhook queue posts typed events
 * (enrichment.job.*, article.*, content.published) signed with
 * X-Webhook-Signature; legacy stores still send X-ACE-Signature. Both are an
 * HMAC-SHA256 hex digest of the raw body with the shared store secret.
 *
 * Never trust the body for results: payload fields are unnormalized, so this
 * receiver only verifies the HMAC and schedules an authoritative poll. Polls
 * are the source of truth; the webhook just makes them prompt. The route name
 * is kept for back-compat with stores already registered against the platform.
 *
 * @package Agencoen
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Webhook
 */
class Webhook {

	const NAMESPACE_V1     = 'agencoen/v1';
	const ROUTE            = '/enrichment-callback';
	const SIG_HEADER       = 'X-ACE-Signature';
	const SIG_HEADER_QUEUE = 'X-Webhook-Signature';
	const EVENT_HEADER     = 'X-Webhook-Event';

	/** @var Plugin */
	private $plugin;

	/**
	 * @param Plugin $plugin Orchestrator.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Register the REST route + the storefront callback URL helper.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * The public callback URL the platform posts to.
	 *
	 * @return string
	 */
	public static function callback_url() {
		return rest_url( self::NAMESPACE_V1 . self::ROUTE );
	}

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'authorize' ),
			)
		);

		// Lightweight reachability probe. Deliberately version-free: it is
		// public, and the version string would only help fingerprinting.
		register_rest_route(
			self::NAMESPACE_V1,
			'/health',
			array(
				'methods'             => 'GET',
				'callback'            => function () {
					return rest_ensure_response( array( 'ok' => true ) );
				},
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Permission callback: verify the HMAC signature over the raw body. The
	 * durable queue signs as X-Webhook-Signature; legacy callbacks sign as
	 * X-ACE-Signature. Same secret, same digest; accept either.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function authorize( $request ) {
		$secret = $this->plugin->settings()->get_webhook_secret();
		if ( '' === $secret ) {
			return new \WP_Error( 'agencoen_not_connected', __( 'Store not connected.', 'agentic-commerce-engine' ), array( 'status' => 403 ) );
		}

		$signature = (string) $request->get_header( self::SIG_HEADER_QUEUE );
		if ( '' === $signature ) {
			$signature = (string) $request->get_header( self::SIG_HEADER );
		}
		$body = (string) $request->get_body();

		if ( ! self::verify_signature( $body, $signature, $secret ) ) {
			return new \WP_Error( 'agencoen_bad_signature', __( 'Invalid signature.', 'agentic-commerce-engine' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Handle a verified callback: route by event and schedule the matching
	 * authoritative poll. The body is never used for results.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle( $request ) {
		$payload = json_decode( (string) $request->get_body(), true );
		$payload = is_array( $payload ) ? $payload : array();

		$event = (string) $request->get_header( self::EVENT_HEADER );
		if ( '' === $event && isset( $payload['event'] ) ) {
			$event = (string) $payload['event'];
		}

		// Queue payloads nest under `data`; legacy callbacks are flat.
		$data   = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : $payload;
		$job_id = isset( $data['jobId'] ) ? (string) $data['jobId'] : '';

		if ( '' !== $job_id ) {
			if ( 0 === strpos( $event, 'article.' ) ) {
				// Article generation finished (or failed): poll + materialize.
				if ( ! wp_next_scheduled( Cron::CONTENT_POLL, array( $job_id ) ) ) {
					wp_schedule_single_event( time() + 5, Cron::CONTENT_POLL, array( $job_id ) );
				}
				spawn_cron();
			} elseif ( 0 === strpos( $event, 'enrichment.job.' ) || '' === $event ) {
				// Product enrichment (typed, or a legacy unnamed callback).
				$this->plugin->enrichment()->schedule_poll( $job_id, 5 );
				spawn_cron();
			}
		}

		// content.published and any future event names are acknowledged without
		// side effects so the queue does not retry them.
		return rest_ensure_response( array( 'received' => true ) );
	}

	/**
	 * Timing-safe HMAC-SHA256 verification over the raw request body.
	 *
	 * @param string $body      Raw body.
	 * @param string $signature Provided signature (hex).
	 * @param string $secret    Shared secret.
	 * @return bool
	 */
	public static function verify_signature( $body, $signature, $secret ) {
		if ( '' === (string) $signature || '' === (string) $secret ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', (string) $body, (string) $secret );
		return hash_equals( $expected, (string) $signature );
	}
}
