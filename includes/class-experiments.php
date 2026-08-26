<?php
/**
 * Experiments: a thin caching layer over the platform's
 * /api/v1/plugin/experiments/* endpoints.
 *
 * NO STATE IS MIRRORED HERE. ACE owns the experiment, its arms and its result;
 * this site owns only the per-product config it serves, which `Ab_Test` already
 * stores and reconciles. A local table of experiments would be a second source
 * of truth that goes stale the moment the site is offline for a day, and the
 * screen would then show a running test that had been stopped a week ago.
 *
 * WHY WRITES BUST THE WHOLE GROUP
 * A lifecycle action changes both the experiment AND its position in the list,
 * and there are only two cached keys. Forgetting the group is cheaper to reason
 * about than remembering which of them a given action moved, and getting that
 * wrong shows "Running" for a test the merchant has just stopped.
 *
 * WOO-GATED. Every subject is a WooCommerce product, and `post_id_for_product`
 * resolves through `wc_get_product_id_by_sku`. A Woo-less site has nothing to
 * test, which is why this is registered in the has_woo() branch.
 *
 * @package Agencoen
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Experiments
 */
class Experiments {

	const GROUP = 'experiments';

	/** @var Plugin */
	private $plugin;

	/**
	 * @param Plugin $plugin Orchestrator.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/* --------------------------------------------------------------------- */
	/* Reads (cached)                                                        */
	/* --------------------------------------------------------------------- */

	/**
	 * Every experiment on this site's project.
	 *
	 * 60 seconds: a merchant refreshing after starting one should see it, and
	 * an experiment's state otherwise changes at human speed or overnight.
	 *
	 * @param bool $fresh Skip the cache.
	 * @return array|\WP_Error
	 */
	public function all( $fresh = false ) {
		if ( $fresh ) {
			$this->plugin->cache()->forget( self::GROUP, 'list' );
		}
		return $this->plugin->cache()->remember(
			self::GROUP,
			'list',
			MINUTE_IN_SECONDS,
			function () {
				return $this->plugin->api()->experiments_list( $this->store_id() );
			},
			30
		);
	}

	/**
	 * One experiment, with its results and health checks.
	 *
	 * @param string $experiment_id Experiment id.
	 * @param bool   $fresh         Skip the cache.
	 * @return array|\WP_Error
	 */
	public function one( $experiment_id, $fresh = false ) {
		$key = 'detail_' . md5( (string) $experiment_id );
		if ( $fresh ) {
			$this->plugin->cache()->forget( self::GROUP, $key );
		}
		return $this->plugin->cache()->remember(
			self::GROUP,
			$key,
			MINUTE_IN_SECONDS,
			function () use ( $experiment_id ) {
				return $this->plugin->api()->experiment( $this->store_id(), $experiment_id );
			},
			30
		);
	}

	/**
	 * Products and categories a test could run over.
	 *
	 * @param string|null $category Optional category.
	 * @return array|\WP_Error
	 */
	public function subjects( $category = null ) {
		return $this->plugin->api()->experiment_subjects( $this->store_id(), $category );
	}

	/* --------------------------------------------------------------------- */
	/* Writes                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * The costless power check. Never cached: its whole value is that it
	 * answers about the design in front of the merchant right now.
	 *
	 * @param array $body Design and product set.
	 * @return array|\WP_Error
	 */
	public function preflight( array $body ) {
		$body['storeId'] = $this->store_id();
		return $this->plugin->api()->experiment_preflight( $body );
	}

	/**
	 * Create an experiment and start the run that writes its versions.
	 *
	 * @param array $body Design, products, goal.
	 * @return array|\WP_Error
	 */
	public function create( array $body ) {
		$body['storeId'] = $this->store_id();
		$result          = $this->plugin->api()->experiment_create( $body );
		if ( ! is_wp_error( $result ) ) {
			$this->plugin->cache()->bust( self::GROUP );
		}
		return $result;
	}

	/**
	 * Start, pause, resume, stop, abandon or rename.
	 *
	 * The allowed set is checked here as well as on the platform. Not a second
	 * opinion: it means a typo in the admin JS is refused locally rather than
	 * spending a round trip to be told the same thing.
	 *
	 * @param string $experiment_id Experiment id.
	 * @param string $action        Lifecycle action.
	 * @param array  $extra         Extra fields (rename carries a name).
	 * @return array|\WP_Error
	 */
	public function act( $experiment_id, $action, array $extra = array() ) {
		$allowed = array( 'start', 'pause', 'resume', 'stop', 'abandon', 'rename' );
		if ( ! in_array( $action, $allowed, true ) ) {
			return new \WP_Error(
				'agencoen_bad_action',
				__( 'That is not something you can do to an experiment.', 'agentic-commerce-engine' )
			);
		}

		$result = $this->plugin->api()->experiment_action(
			$this->store_id(),
			$experiment_id,
			$action,
			$extra
		);

		if ( ! is_wp_error( $result ) ) {
			// Both keys move: the experiment's own payload and its row in the
			// list. Also pull the storefront configs straight away, because
			// stopping a test must stop splitting visitors now and not on the
			// next daily tick.
			$this->plugin->cache()->bust( self::GROUP );
			$this->plugin->ab_test()->sync_configs();
		}
		return $result;
	}

	/**
	 * Keep the winning version for every shopper, and finish the test.
	 *
	 * The arm is a hint. ACE rebuilds the verdict from the same shaping layer
	 * this screen rendered and refuses if it disagrees, so a merchant who left
	 * the page open overnight cannot publish a version the evidence has since
	 * stopped supporting.
	 *
	 * @param string $experiment_id Experiment id.
	 * @param string $arm           The winning arm key.
	 * @return array|\WP_Error
	 */
	public function apply_winner( $experiment_id, $arm ) {
		if ( ! preg_match( '/^[a-z0-9]{1,8}$/', (string) $arm ) ) {
			return new \WP_Error(
				'agencoen_bad_arm',
				__( 'That version could not be identified.', 'agentic-commerce-engine' )
			);
		}

		$result = $this->plugin->api()->experiment_apply_winner(
			$this->store_id(),
			$experiment_id,
			$arm
		);

		if ( ! is_wp_error( $result ) ) {
			// The test is over, so the cached payload and the list row are both
			// wrong. Pull the storefront configs straight away as well: the
			// split has to stop now, not on the next daily tick.
			$this->plugin->cache()->bust( self::GROUP );
			$this->plugin->ab_test()->sync_configs();
		}
		return $result;
	}

	/* --------------------------------------------------------------------- */

	/**
	 * @return string
	 */
	private function store_id() {
		return (string) $this->plugin->settings()->get_store_id();
	}
}
