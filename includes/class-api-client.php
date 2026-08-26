<?php
/**
 * HTTP client for the ACE platform API (v4 surface: /api/v1/plugin/* plus the
 * public jobs + enrichment + usage endpoints).
 *
 * Speaks the platform envelope: success bodies are `{ "data": ... }` and error
 * bodies are `{ "error": { "code", "message" } }`. Every public method returns
 * the unwrapped `data` payload on success, or a WP_Error carrying the server's
 * error code + message on failure. List endpoints that paginate return the
 * envelope's `meta.pagination` under an `_meta` key.
 *
 * @package Agencoen
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Api_Client
 */
class Api_Client {

	const TIMEOUT     = 30;
	const MAX_RETRIES = 3;

	const TIMEOUT_INTERACTIVE  = 12;
	const ATTEMPTS_INTERACTIVE = 1;

	/** @var Settings */
	private $settings;
	/** @var Logger */
	private $logger;
	/** @var array{timeout?:int,attempts?:int} Per-instance profile overrides. */
	private $options = array();

	/**
	 * @param Settings $settings Settings.
	 * @param Logger   $logger   Logger.
	 */
	public function __construct( Settings $settings, Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * Clone with per-request profile overrides, e.g. a longer timeout for the
	 * one interactive call that provisions server-side (connect/register).
	 *
	 * @param array $opts { timeout?: int, attempts?: int }.
	 * @return self
	 */
	public function with_options( array $opts ) {
		$clone          = clone $this;
		$clone->options = array_merge( $this->options, $opts );
		return $clone;
	}

	/**
	 * Resolve the timeout/attempts profile for a request. Pure: context in,
	 * profile out.
	 *
	 * Interactive contexts (admin screens, admin-ajax) fail fast with no
	 * retries so a slow or down platform can never hang the admin; background
	 * contexts (cron, webhook-triggered polling, CLI) keep the patient
	 * profile with retries.
	 *
	 * @param bool  $interactive Whether the request serves an interactive admin context.
	 * @param array $overrides   Per-call overrides { timeout?: int, attempts?: int }.
	 * @return array{timeout:int,attempts:int}
	 */
	public static function resolve_profile( $interactive, array $overrides = array() ) {
		$profile = $interactive
			? array(
				'timeout'  => self::TIMEOUT_INTERACTIVE,
				'attempts' => self::ATTEMPTS_INTERACTIVE,
			)
			: array(
				'timeout'  => self::TIMEOUT,
				'attempts' => self::MAX_RETRIES,
			);

		if ( isset( $overrides['timeout'] ) && (int) $overrides['timeout'] > 0 ) {
			$profile['timeout'] = (int) $overrides['timeout'];
		}
		if ( isset( $overrides['attempts'] ) && (int) $overrides['attempts'] > 0 ) {
			$profile['attempts'] = (int) $overrides['attempts'];
		}
		return $profile;
	}

	/**
	 * Whether the current request is an interactive admin context.
	 *
	 * @return bool
	 */
	private static function is_interactive_context() {
		$doing_cron = function_exists( 'wp_doing_cron' ) && wp_doing_cron();
		if ( $doing_cron ) {
			return false;
		}
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return true;
		}
		return function_exists( 'is_admin' ) && is_admin();
	}

	/* --------------------------------------------------------------------- */
	/* Core: connection + catalog                                            */
	/* --------------------------------------------------------------------- */

	/**
	 * POST /api/v1/plugin/register
	 *
	 * @param array $args { siteUrl, siteTitle, platform, wpVersion, wcVersion, pluginVersion, webhookUrl }.
	 * @return array|\WP_Error
	 */
	public function register( array $args ) {
		return $this->request( 'POST', '/api/v1/plugin/register', $args );
	}

	/**
	 * POST /api/v1/plugin/sync
	 *
	 * @param string $store_id Store id.
	 * @param array  $products Serialized products (<= maxBatchSize).
	 * @return array|\WP_Error
	 */
	public function sync_products( $store_id, array $products ) {
		return $this->request(
			'POST',
			'/api/v1/plugin/sync',
			array(
				'storeId'  => $store_id,
				'products' => array_values( $products ),
			)
		);
	}

	/**
	 * POST /api/v1/plugin/heartbeat
	 *
	 * @param array $args { storeId, pluginVersion, wpVersion, wcVersion, productCount }.
	 * @return array|\WP_Error
	 */
	public function heartbeat( array $args ) {
		return $this->request( 'POST', '/api/v1/plugin/heartbeat', $args );
	}

	/**
	 * GET /api/v1/plugin/bootstrap — one-call dashboard payload.
	 *
	 * @param string $store_id Store id.
	 * @return array|\WP_Error
	 */
	public function bootstrap( $store_id ) {
		return $this->request( 'GET', '/api/v1/plugin/bootstrap', null, array( 'storeId' => $store_id ) );
	}

	/**
	 * GET /api/v1/usage — credits + rate limit + job counts.
	 *
	 * @return array|\WP_Error
	 */
	public function usage() {
		return $this->request( 'GET', '/api/v1/usage' );
	}

	/**
	 * GET /api/v1/plugin/content-types — enrichment + article type catalog.
	 *
	 * @param string $store_id Store id.
	 * @return array|\WP_Error
	 */
	public function content_types( $store_id ) {
		return $this->request( 'GET', '/api/v1/plugin/content-types', null, array( 'storeId' => $store_id ) );
	}

	/**
	 * GET /api/v1/plugin/brand — identity + voice summary.
	 *
	 * @param string $store_id Store id.
	 * @return array|\WP_Error
	 */
	public function brand( $store_id ) {
		return $this->request( 'GET', '/api/v1/plugin/brand', null, array( 'storeId' => $store_id ) );
	}

	/* --------------------------------------------------------------------- */
	/* Enrichment (durable jobs)                                             */
	/* --------------------------------------------------------------------- */

	/**
	 * POST /api/v1/enrichment/pipeline with a store source: rules + content for
	 * synced products, as a durable job. Returns the job envelope (202).
	 *
	 * @param string $store_id    Store id.
	 * @param array  $product_ids Product ids (strings, <= 100).
	 * @param array  $options     { contentTypes?, config?, targetLanguage? }.
	 * @return array|\WP_Error
	 */
	public function enrich( $store_id, array $product_ids, array $options = array() ) {
		$body = array(
			'source' => array(
				'type'       => 'store',
				'storeId'    => $store_id,
				'productIds' => array_values( array_map( 'strval', $product_ids ) ),
			),
		);
		if ( ! empty( $options['contentTypes'] ) ) {
			$body['contentTypes'] = array_values( $options['contentTypes'] );
		}
		if ( ! empty( $options['config'] ) ) {
			$body['config'] = $options['config'];
		}
		if ( ! empty( $options['targetLanguage'] ) ) {
			$body['targetLanguage'] = $options['targetLanguage'];
		}
		return $this->request( 'POST', '/api/v1/enrichment/pipeline', $body );
	}

	/**
	 * GET /api/v1/jobs/{id} — poll a durable job.
	 *
	 * @param string $job_id Job id.
	 * @return array|\WP_Error
	 */
	public function job( $job_id ) {
		return $this->request( 'GET', '/api/v1/jobs/' . rawurlencode( $job_id ) );
	}

	/**
	 * GET /api/v1/jobs/{id}/results — paginated per-product results.
	 *
	 * @param string $job_id Job id.
	 * @param int    $page   Page (1-based).
	 * @param int    $size   Page size.
	 * @return array|\WP_Error
	 */
	public function job_results( $job_id, $page = 1, $size = 50 ) {
		return $this->request(
			'GET',
			'/api/v1/jobs/' . rawurlencode( $job_id ) . '/results',
			null,
			array(
				'page'     => (string) max( 1, (int) $page ),
				'pageSize' => (string) max( 1, (int) $size ),
			)
		);
	}

	/* --------------------------------------------------------------------- */
	/* Content Hub (articles)                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * POST /api/v1/plugin/articles/generate — start a durable article job.
	 *
	 * @param array $args { storeId, title, type?, locale?, overview?, keywords?, tone?, wordCount?, productIds?, categoryTarget? }.
	 * @return array|\WP_Error { id (jobId), briefId, status, ... }
	 */
	public function articles_generate( array $args ) {
		return $this->request( 'POST', '/api/v1/plugin/articles/generate', $args );
	}

	/**
	 * GET /api/v1/plugin/articles — list project briefs (includes app-authored).
	 *
	 * @param string $store_id Store id.
	 * @param array  $query    { status?, search?, page?, pageSize? }.
	 * @return array|\WP_Error List rows; pagination under `_meta`.
	 */
	public function articles( $store_id, array $query = array() ) {
		$query['storeId'] = $store_id;
		return $this->request( 'GET', '/api/v1/plugin/articles', null, $query );
	}

	/**
	 * GET /api/v1/plugin/articles/{briefId} — full article payload.
	 *
	 * @param string $store_id Store id.
	 * @param string $brief_id Brief id.
	 * @return array|\WP_Error
	 */
	public function article( $store_id, $brief_id ) {
		return $this->request(
			'GET',
			'/api/v1/plugin/articles/' . rawurlencode( $brief_id ),
			null,
			array( 'storeId' => $store_id )
		);
	}

	/**
	 * POST /api/v1/plugin/articles/{briefId}/published — report the WP post.
	 *
	 * @param string $store_id Store id.
	 * @param string $brief_id Brief id.
	 * @param array  $args     { url, wpPostId, status }.
	 * @return array|\WP_Error
	 */
	public function article_published( $store_id, $brief_id, array $args ) {
		$args['storeId'] = $store_id;
		return $this->request(
			'POST',
			'/api/v1/plugin/articles/' . rawurlencode( $brief_id ) . '/published',
			$args
		);
	}

	/* --------------------------------------------------------------------- */
	/* AI Search (Ecom AEO)                                                  */
	/* --------------------------------------------------------------------- */

	/**
	 * GET /api/v1/plugin/aeo/config — configurator payload.
	 *
	 * @param string $store_id Store id.
	 * @return array|\WP_Error
	 */
	public function aeo_config( $store_id ) {
		return $this->request( 'GET', '/api/v1/plugin/aeo/config', null, array( 'storeId' => $store_id ) );
	}

	/**
	 * POST /api/v1/plugin/aeo/flows — create + link a research flow.
	 *
	 * @param array $args { storeId, name, brandName, description? }.
	 * @return array|\WP_Error
	 */
	public function aeo_create_flow( array $args ) {
		return $this->request( 'POST', '/api/v1/plugin/aeo/flows', $args );
	}

	/**
	 * GET /api/v1/plugin/aeo/prompts — list research prompts.
	 *
	 * @param string $store_id Store id.
	 * @param int    $page     Page.
	 * @param int    $size     Page size.
	 * @return array|\WP_Error
	 */
	public function aeo_prompts( $store_id, $page = 1, $size = 100 ) {
		return $this->request(
			'GET',
			'/api/v1/plugin/aeo/prompts',
			null,
			array(
				'storeId'  => $store_id,
				'page'     => (string) max( 1, (int) $page ),
				'pageSize' => (string) max( 1, (int) $size ),
			)
		);
	}

	/**
	 * PUT /api/v1/plugin/aeo/prompts — append or replace the prompt set.
	 *
	 * @param string $store_id Store id.
	 * @param array  $prompts  [{ text, tags? }].
	 * @param string $mode     append|replace.
	 * @return array|\WP_Error
	 */
	public function aeo_save_prompts( $store_id, array $prompts, $mode = 'append' ) {
		return $this->request(
			'PUT',
			'/api/v1/plugin/aeo/prompts',
			array(
				'storeId' => $store_id,
				'prompts' => array_values( $prompts ),
				'mode'    => $mode,
			)
		);
	}

	/**
	 * POST /api/v1/plugin/aeo/prompts/suggest — AI prompt ideas from the catalog.
	 *
	 * @param array $args { storeId, topic?, branded?, regionLabel?, languageLabel? }.
	 * @return array|\WP_Error
	 */
	public function aeo_suggest_prompts( array $args ) {
		return $this->request( 'POST', '/api/v1/plugin/aeo/prompts/suggest', $args );
	}

	/**
	 * GET /api/v1/plugin/aeo/runs — list runs (+ snapshot presence).
	 *
	 * @param string $store_id Store id.
	 * @return array|\WP_Error
	 */
	public function aeo_runs( $store_id ) {
		return $this->request( 'GET', '/api/v1/plugin/aeo/runs', null, array( 'storeId' => $store_id ) );
	}

	/**
	 * POST /api/v1/plugin/aeo/runs — launch a run (or estimate it).
	 *
	 * @param array $args { storeId, models?, regionCode?, languageCode?, useFlowDefaults?, estimateOnly? }.
	 * @return array|\WP_Error
	 */
	public function aeo_start_run( array $args ) {
		return $this->request( 'POST', '/api/v1/plugin/aeo/runs', $args );
	}

	/**
	 * GET /api/v1/plugin/aeo/runs/{runId} — snapshot detail / status.
	 *
	 * @param string $store_id Store id.
	 * @param string $run_id   Run id.
	 * @return array|\WP_Error
	 */
	public function aeo_run( $store_id, $run_id ) {
		return $this->request(
			'GET',
			'/api/v1/plugin/aeo/runs/' . rawurlencode( $run_id ),
			null,
			array( 'storeId' => $store_id )
		);
	}

	/**
	 * GET /api/v1/plugin/aeo/overview — KPI band + trend from snapshots.
	 *
	 * @param string      $store_id Store id.
	 * @param string|null $run_id   Optional run to focus.
	 * @return array|\WP_Error
	 */
	public function aeo_overview( $store_id, $run_id = null ) {
		$query = array( 'storeId' => $store_id );
		if ( $run_id ) {
			$query['runId'] = $run_id;
		}
		return $this->request( 'GET', '/api/v1/plugin/aeo/overview', null, $query );
	}

	/* --------------------------------------------------------------------- */
	/* Crawler analytics report-back                                         */
	/* --------------------------------------------------------------------- */

	/**
	 * POST /api/v1/plugin/bot-traffic/ingest — opt-in daily aggregates.
	 *
	 * @param string $store_id Store id.
	 * @param array  $days     [{ date, bots: [...] }].
	 * @return array|\WP_Error
	 */
	/**
	 * Day-level human pageview aggregates. No visitor identifiers of any kind.
	 *
	 * @param string $store_id Store id.
	 * @param array  $days     Day payloads.
	 * @return array|\WP_Error
	 */
	public function traffic_ingest( $store_id, array $days ) {
		return $this->request(
			'POST',
			'/api/v1/plugin/traffic/ingest',
			array(
				'storeId' => $store_id,
				'days'    => array_values( $days ),
			)
		);
	}

	/**
	 * Day-level per-product sales aggregates. No order or customer records.
	 *
	 * @param string $store_id Store id.
	 * @param array  $days     Day payloads.
	 * @return array|\WP_Error
	 */
	public function sales_ingest( $store_id, array $days ) {
		return $this->request(
			'POST',
			'/api/v1/plugin/sales/ingest',
			array(
				'storeId' => $store_id,
				'days'    => array_values( $days ),
			)
		);
	}

	/**
	 * Which of this store's products are running an A/B test, and their configs.
	 *
	 * The FULL current state, not a changes feed. A product that has dropped off
	 * this list is running no test, and that is the only signal a stopped test
	 * produces -- there is no "experiment ended" event to listen for.
	 *
	 * @param string $store_id Store id.
	 * @return array|\WP_Error
	 */
	public function experiment_configs( $store_id ) {
		return $this->request(
			'GET',
			'/api/v1/plugin/experiments',
			null,
			array( 'storeId' => $store_id )
		);
	}

	/* --------------------------------------------------------------------- */
	/* Experiments                                                           */
	/*                                                                       */
	/* SIBLING PATHS to /plugin/experiments, which is the config-reconcile    */
	/* feed this plugin has polled since 4.5.0. That endpoint's contract is   */
	/* "a product absent from this list is running no test", and it runs      */
	/* unattended on sites that cannot be force-upgraded, so nothing is added */
	/* to its payload.                                                       */
	/* --------------------------------------------------------------------- */

	/**
	 * Every experiment on this site's project.
	 *
	 * @param string $store_id Store id.
	 * @return array|\WP_Error
	 */
	public function experiments_list( $store_id ) {
		return $this->request(
			'GET',
			'/api/v1/plugin/experiments/list',
			null,
			array( 'storeId' => $store_id )
		);
	}

	/**
	 * Finished tests and what each one found.
	 *
	 * @param string $store_id Store id.
	 * @param array  $query    Optional verdict, q, page.
	 * @return array|\WP_Error
	 */
	public function experiment_history( $store_id, $query = array() ) {
		$args = array( 'storeId' => $store_id );
		foreach ( array( 'verdict', 'q', 'page' ) as $key ) {
			if ( isset( $query[ $key ] ) && '' !== $query[ $key ] ) {
				$args[ $key ] = (string) $query[ $key ];
			}
		}
		return $this->request( 'GET', '/api/v1/plugin/experiments/history', null, $args );
	}

	/**
	 * What testing has been worth: banked value, and running tests kept apart.
	 *
	 * @param string $store_id Store id.
	 * @return array|\WP_Error
	 */
	public function experiment_value( $store_id ) {
		return $this->request(
			'GET',
			'/api/v1/plugin/experiments/value',
			null,
			array( 'storeId' => $store_id )
		);
	}

	/**
	 * Write what the merchant learned onto one finished test.
	 *
	 * @param string $store_id      Store id.
	 * @param string $experiment_id Experiment id.
	 * @param string $notes         Their note. Empty clears it.
	 * @return array|\WP_Error
	 */
	public function experiment_note( $store_id, $experiment_id, $notes ) {
		return $this->request(
			'PATCH',
			'/api/v1/plugin/experiments/history',
			array(
				'storeId'      => $store_id,
				'experimentId' => $experiment_id,
				'notes'        => $notes,
			)
		);
	}

	/**
	 * One experiment: arms, exposures, results and the health checks.
	 *
	 * @param string $store_id      Store id.
	 * @param string $experiment_id Experiment id.
	 * @return array|\WP_Error
	 */
	public function experiment( $store_id, $experiment_id ) {
		return $this->request(
			'GET',
			'/api/v1/plugin/experiments/' . rawurlencode( $experiment_id ),
			null,
			array( 'storeId' => $store_id )
		);
	}

	/**
	 * Products a test could run over, and the categories to choose between.
	 *
	 * Both in one call: a test needs roughly 150 products behind it, so the
	 * wizard picks a whole category rather than products one at a time.
	 *
	 * @param string      $store_id Store id.
	 * @param string|null $category Optional category filter.
	 * @return array|\WP_Error
	 */
	public function experiment_subjects( $store_id, $category = null ) {
		$query = array( 'storeId' => $store_id );
		if ( is_string( $category ) && '' !== $category ) {
			$query['category'] = $category;
		}
		return $this->request( 'GET', '/api/v1/plugin/experiments/subjects', null, $query );
	}

	/**
	 * The costless power check. Spends nothing and creates nothing.
	 *
	 * @param array $body Design and product set.
	 * @return array|\WP_Error
	 */
	public function experiment_preflight( array $body ) {
		return $this->request( 'POST', '/api/v1/plugin/experiments/preflight', $body );
	}

	/**
	 * Create an experiment and start the run that writes its versions.
	 *
	 * THIS SPENDS CREDITS, which is why it is the one experiment call on the
	 * background profile: a slow success reported as a failure would leave the
	 * merchant with an experiment they cannot see and a retry the one-split
	 * guard refuses.
	 *
	 * @param array $body Design, products, goal.
	 * @return array|\WP_Error
	 */
	public function experiment_create( array $body ) {
		return $this->request(
			'POST',
			'/api/v1/plugin/experiments/create',
			$body,
			null,
			array(
				'timeout'  => self::TIMEOUT,
				'attempts' => 1,
			)
		);
	}

	/**
	 * Start, pause, resume, stop, abandon or rename.
	 *
	 * @param string $store_id      Store id.
	 * @param string $experiment_id Experiment id.
	 * @param string $action        Lifecycle action.
	 * @param array  $extra         Extra fields (rename carries a name).
	 * @return array|\WP_Error
	 */
	public function experiment_action( $store_id, $experiment_id, $action, array $extra = array() ) {
		return $this->request(
			'PATCH',
			'/api/v1/plugin/experiments/' . rawurlencode( $experiment_id ),
			array_merge(
				array(
					'storeId' => $store_id,
					'action'  => $action,
				),
				$extra
			)
		);
	}

	/**
	 * Keep the winning version for every shopper, and finish the test.
	 *
	 * A separate endpoint rather than a sixth action, because it does not only
	 * change a status: it republishes the winner's copy to this site's own
	 * products. ACE recomputes the verdict and refuses if the evidence no
	 * longer supports the arm named here, so this plugin never has to decide.
	 *
	 * @param string $store_id      Store id.
	 * @param string $experiment_id Experiment id.
	 * @param string $arm           The winning arm key.
	 * @return array|\WP_Error
	 */
	public function experiment_apply_winner( $store_id, $experiment_id, $arm ) {
		return $this->request(
			'POST',
			'/api/v1/plugin/experiments/' . rawurlencode( $experiment_id ) . '/apply-winner',
			array(
				'storeId' => $store_id,
				'arm'     => $arm,
			)
		);
	}

	/**
	 * Content ACE has approved and is waiting for this site to apply.
	 *
	 * The same endpoint the Shopify app pulls. Entries are per FIELD, not per
	 * product, and the A/B config rides the same batch as the challenger copy
	 * that makes a test possible -- which is why it can only ever be emitted
	 * once, alongside that copy.
	 *
	 * @param string $store_id Store id.
	 * @return array|\WP_Error
	 */
	public function apply_queue( $store_id ) {
		return $this->request(
			'GET',
			'/api/v1/plugin/apply-queue',
			null,
			array( 'storeId' => $store_id )
		);
	}

	/**
	 * Report what landed.
	 *
	 * This is the half that was missing until 4.6.0. An "applied" result is
	 * what stamps `pushed_at` on the ACE side, which is what removes the entry
	 * from the next pull, what makes a version count as live for an A/B test,
	 * and what puts the push on the merchant's Published screen.
	 *
	 * @param string $store_id Store id.
	 * @param array  $results  [{ id, status: applied|failed, error? }].
	 * @return array|\WP_Error
	 */
	public function apply_queue_ack( $store_id, array $results ) {
		return $this->request(
			'POST',
			'/api/v1/plugin/apply-queue/ack',
			array(
				'storeId' => $store_id,
				'results' => array_values( $results ),
			)
		);
	}

	/**
	 * Per-visitor A/B exposures.
	 *
	 * The ONLY payload this client sends that carries a per-visitor value, and
	 * it is a salted hash computed on the site, not the cookie itself. Every
	 * other ingest here is day-level aggregate.
	 *
	 * @param string $store_id Store id.
	 * @param array  $events   Exposure and conversion events.
	 * @return array|\WP_Error
	 */
	public function ab_events_ingest( $store_id, array $events ) {
		return $this->request(
			'POST',
			'/api/v1/plugin/ab-events/ingest',
			array(
				'storeId' => $store_id,
				'events'  => array_values( $events ),
			)
		);
	}

	public function bot_traffic_ingest( $store_id, array $days ) {
		return $this->request(
			'POST',
			'/api/v1/plugin/bot-traffic/ingest',
			array(
				'storeId' => $store_id,
				'days'    => array_values( $days ),
			)
		);
	}

	/* --------------------------------------------------------------------- */
	/* Transport                                                             */
	/* --------------------------------------------------------------------- */

	/**
	 * Perform a request with retries and envelope unwrapping.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path beginning with '/'.
	 * @param array|null $body   JSON body for POST/PUT.
	 * @param array|null $query  Query parameters.
	 * @return array|\WP_Error
	 */
	private function request( $method, $path, $body = null, $query = null, array $overrides = array() ) {
		$key = $this->settings->get_api_key();
		if ( '' === $key ) {
			return new \WP_Error( 'agencoen_no_key', __( 'No API key configured. Connect your site first.', 'agentic-commerce-engine' ) );
		}

		$url = $this->settings->get_base_url() . $path;
		if ( ! empty( $query ) ) {
			$url = add_query_arg( array_map( 'rawurlencode', $query ), $url );
		}

		// Per-call overrides beat the instance's, because the reason for one is
		// always about the specific endpoint: creating an experiment starts a
		// generation run over thousands of products, and the 12s interactive
		// default would abort a slow success -- leaving the merchant an
		// experiment they cannot see and a retry the one-split guard refuses.
		$profile = self::resolve_profile(
			self::is_interactive_context(),
			array_merge( $this->options, $overrides )
		);

		$args = array(
			'method'  => $method,
			'timeout' => $profile['timeout'],
			'headers' => array(
				'Authorization'        => 'Bearer ' . $key,
				'Accept'               => 'application/json',
				'X-ACE-Plugin-Version' => AGENCOEN_VERSION,
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$attempt = 0;
		while ( true ) {
			++$attempt;
			$response = wp_remote_request( $url, $args );

			if ( is_wp_error( $response ) ) {
				if ( $attempt < $profile['attempts'] ) {
					$this->backoff( $attempt );
					continue;
				}
				$this->logger->error( "Transport error calling {$path}: " . $response->get_error_message() );
				return $response;
			}

			$status = (int) wp_remote_retrieve_response_code( $response );

			if ( self::should_retry( $status ) && $attempt < $profile['attempts'] ) {
				$this->backoff( $attempt );
				continue;
			}

			$raw     = wp_remote_retrieve_body( $response );
			$decoded = json_decode( $raw, true );
			$result  = self::parse_response( $status, $decoded );

			if ( is_wp_error( $result ) ) {
				$this->logger->error( "API {$status} on {$path}: " . $result->get_error_message() );
			}
			return $result;
		}
	}

	/**
	 * Sleep with exponential backoff (0.5s, 1s, 2s...), capped at 4s.
	 *
	 * @param int $attempt 1-based attempt number.
	 */
	private function backoff( $attempt ) {
		$ms = (int) min( 4000, 500 * ( 2 ** ( $attempt - 1 ) ) );
		usleep( $ms * 1000 );
	}

	/* --------------------------------------------------------------------- */
	/* Pure helpers (unit-tested directly)                                   */
	/* --------------------------------------------------------------------- */

	/**
	 * Whether a status code is worth retrying (transient).
	 *
	 * @param int $status HTTP status.
	 * @return bool
	 */
	public static function should_retry( $status ) {
		return in_array( (int) $status, array( 429, 500, 502, 503, 504 ), true );
	}

	/**
	 * Whether an array is a zero-indexed list (works on every supported PHP).
	 *
	 * @param array $arr Array to test.
	 * @return bool
	 */
	public static function is_list( array $arr ) {
		if ( array() === $arr ) {
			return true;
		}
		return array_keys( $arr ) === range( 0, count( $arr ) - 1 );
	}

	/**
	 * Unwrap the platform envelope.
	 *
	 * Success: returns the `data` payload. When the envelope carries `meta`
	 * (paginated lists), it is preserved under `_meta`; list payloads are
	 * wrapped as `{ items: [...], _meta: {...} }` so callers never index a
	 * mixed array.
	 *
	 * @param int   $status  HTTP status.
	 * @param mixed $decoded Decoded JSON body (associative array or null).
	 * @return array|\WP_Error The `data` payload, or a WP_Error.
	 */
	public static function parse_response( $status, $decoded ) {
		if ( $status >= 200 && $status < 300 ) {
			if ( is_array( $decoded ) && array_key_exists( 'data', $decoded ) ) {
				$data = $decoded['data'];
				$meta = isset( $decoded['meta'] ) && is_array( $decoded['meta'] ) ? $decoded['meta'] : null;
				if ( ! is_array( $data ) ) {
					$data = array( 'value' => $data );
				}
				if ( null !== $meta ) {
					if ( self::is_list( $data ) ) {
						return array(
							'items' => $data,
							'_meta' => $meta,
						);
					}
					$data['_meta'] = $meta;
				}
				return $data;
			}
			return is_array( $decoded ) ? $decoded : array();
		}

		$code    = 'agencoen_api_error';
		$message = sprintf(
			/* translators: %d: HTTP status code. */
			__( 'The ACE platform returned an error (HTTP %d).', 'agentic-commerce-engine' ),
			(int) $status
		);

		if ( is_array( $decoded ) && isset( $decoded['error'] ) && is_array( $decoded['error'] ) ) {
			if ( ! empty( $decoded['error']['code'] ) ) {
				$code = (string) $decoded['error']['code'];
			}
			if ( ! empty( $decoded['error']['message'] ) ) {
				$message = (string) $decoded['error']['message'];
			}
		}

		return new \WP_Error( $code, $message, array( 'status' => (int) $status ) );
	}
}
