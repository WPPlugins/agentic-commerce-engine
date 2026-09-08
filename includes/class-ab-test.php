<?php
/**
 * Per-visitor A/B testing on a WordPress storefront.
 *
 * WHY THIS IS THE ONE FEATURE IN THE PLUGIN THAT SETS A COOKIE
 * Every other tracker here is aggregate-only by design. Traffic_Tracker says so
 * in its own header: no cookie, no session id, no fingerprint, no IP, because
 * that keeps the plugin clear of WP.org's data-collection requirements and
 * means the site owner needs no consent mechanism to switch it on.
 *
 * Showing one visitor version A and another version B cannot honour that.
 * Remembering which version a person saw IS a cookie, and reporting what they
 * then did IS a per-person record. There is no version of this feature that
 * does not cross that line, so instead of pretending otherwise:
 *
 *   - it is OFF by default and stays off through every upgrade
 *   - it does nothing at all without consent, from the WP Consent API, which
 *     is the standard the consent plugins implement
 *   - with no consent plugin installed the answer is NO, so nothing is stored
 *     and every visitor reads the canonical copy
 *   - the visitor id never leaves the site. It is hashed here, with a salt
 *     generated for this site alone, and only the hash is reported
 *
 * WHY THE SPLIT IS DECIDED IN THE BROWSER AND NOT IN PHP
 * PHP is the obvious place and it is wrong, for the reason this plugin's own
 * traffic tracker already documents: on a cached site PHP does not run. WP
 * Super Cache, LiteSpeed, Varnish and Cloudflare APO all serve the HTML without
 * booting WordPress, so a server-rendered variant would be written into the
 * cache and then served to EVERYONE, including crawlers. That is not a
 * degraded test, it is duplicate content plus cloaking, and it is exactly the
 * failure the storefront design exists to avoid.
 *
 * So the page ships one version, the canonical one, and a consented visitor's
 * browser asks for their version separately. A crawler never asks, because it
 * is never bucketed: no cookie, no consent, no arm. One rule applied to
 * everyone, and no user agent is consulted anywhere.
 *
 * WHY THE JAVASCRIPT IS GENERATED RATHER THAN WRITTEN HERE
 * assets/js/ace-ab-test.js is the SAME file the Shopify theme extension ships,
 * produced by src/lib/experiments/storefront-script.ts. The bucketing hash in it
 * already exists twice: once in the browser and once on the Rezolve Ai server, which
 * reproduces an assignment months later when a result is read out. A third
 * hand-written copy for WordPress would drift, and when it did, a readout would
 * attribute conversions to the wrong arm and the number would be worse than
 * useless. class-ab-test-asset.guard.test.ts fails the build if the shipped
 * file and the generator disagree.
 *
 * @package Agencoen
 */

namespace Agencoen;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ab_Test {

	/** Post meta holding the storefront config Rezolve Ai sent for this product. */
	const META_CONFIG = '_agencoen_ab_test';

	/** Option holding the per-site hashing salt. Generated once, never sent. */
	const OPT_SALT = 'agencoen_ab_salt';

	/** Cookie the shared script writes. Read here, never written here. */
	const VISITOR_COOKIE = '_ace_vid';

	/** Rows kept before the oldest days are dropped. */
	const MAX_ROWS = 50000;

	/** Days of exposure history reported per run. */
	const REPORT_DAYS = 3;

	/** Rows sent per run. */
	const REPORT_ROWS = 2000;

	/**
	 * How far back a conversion may be attributed to an exposure.
	 *
	 * A visitor who reads a product today and buys on Thursday saw the version
	 * that persuaded them today. Attributing only same-day conversions would
	 * undercount every store whose customers think about it first, and would
	 * undercount the two arms unequally if one of them is better at persuading
	 * people who take their time -- which is the whole thing being measured.
	 */
	const ATTRIBUTION_DAYS = 7;

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		// The order-received page is never cached (it is order-specific), so PHP
		// genuinely runs here and the visitor's cookie is on the request.
		add_action( 'woocommerce_thankyou', array( $this, 'record_conversion' ) );
		// The basket step. Fires on the add-to-cart action rather than on a
		// cart page view, so a shopper who adds and never opens the cart is
		// still counted, and a shopper who reloads the cart is not counted
		// twice -- the row is an upsert on (day, experiment, visitor) anyway.
		add_action( 'woocommerce_add_to_cart', array( $this, 'record_cart' ) );
	}

	/** Is the feature switched on for this site? Off unless explicitly enabled. */
	public function is_enabled() {
		return $this->plugin->settings()->is_ab_enabled();
	}

	public static function events_table() {
		global $wpdb;
		return $wpdb->prefix . Db::TABLE_AB_EVENTS;
	}

	/* --------------------------------------------------------------------- */
	/* Configuration                                                          */
	/* --------------------------------------------------------------------- */

	/**
	 * Store the config Rezolve Ai sent for one product, or clear it.
	 *
	 * Clearing on an empty value is not a tidy-up, it is how a test STOPS. Rezolve Ai
	 * sends no config once fewer than two versions are live, and a stale config
	 * left behind would keep bucketing visitors into a test that has ended and
	 * keep reporting conversions that land in no readout.
	 *
	 * @param int   $post_id Product id.
	 * @param array $config  Config from Rezolve Ai, or empty to clear.
	 */
	public function set_config( $post_id, $config ) {
		$post_id = (int) $post_id;
		if ( ! $post_id ) {
			return;
		}
		if ( empty( $config ) || ! is_array( $config ) || empty( $config['e'] ) ) {
			delete_post_meta( $post_id, self::META_CONFIG );
			return;
		}
		update_post_meta( $post_id, self::META_CONFIG, wp_json_encode( $config ) );
	}

	/** The stored config for a product, or null. */
	public function config_for( $post_id ) {
		$raw = get_post_meta( (int) $post_id, self::META_CONFIG, true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}
		$config = json_decode( $raw, true );
		if ( ! is_array( $config ) || empty( $config['e'] ) || empty( $config['arms'] ) ) {
			return null;
		}
		return $config;
	}

	/* --------------------------------------------------------------------- */
	/* Front end                                                              */
	/* --------------------------------------------------------------------- */

	/**
	 * Emit the config and the shared script on a product running a test.
	 *
	 * IDENTIFIERS ONLY, NEVER COPY, which is the whole reason the config can sit
	 * in the page at all. A crawler reading this JSON learns an experiment id, a
	 * product id and some arm keys, and ingests no alternative description.
	 */
	public function enqueue() {
		if ( ! $this->is_enabled() ) {
			return;
		}
		if ( ! is_singular() || is_preview() ) {
			return;
		}
		// Editors see the canonical copy: an editor previewing their own store
		// and getting a challenger would report an exposure that is not a
		// customer, and would confuse them besides.
		if ( current_user_can( 'edit_posts' ) ) {
			return;
		}

		$post_id = get_queried_object_id();
		if ( ! $post_id ) {
			return;
		}
		$config = $this->config_for( $post_id );
		if ( ! $config ) {
			return;
		}

		// The platform adapter. Everything else in the config came from Rezolve Ai.
		$config['p']   = 'wp';
		$config['rep'] = esc_url_raw( rest_url( 'agencoen/v1/arm' ) );

		// The theme's own selector LEADS when the site owner has set one: they
		// are looking at their theme and we are not. It leads rather than
		// replaces, because a selector that matches nothing means the script
		// swaps nothing and the test reports two arms reading identical words,
		// which is the failure this setting exists to prevent. Trying the usual
		// candidates after theirs cannot produce that outcome, and every
		// candidate the server sends is a description container, so a fallback
		// lands on the same kind of block.
		$selector = $this->plugin->settings()->ab_selector();
		if ( $selector ) {
			$config['sel'] = $selector;
			if ( ! empty( $config['els'] ) && is_array( $config['els'] ) ) {
				foreach ( $config['els'] as $i => $el ) {
					if ( ! isset( $el['ct'] ) || 'product-description' !== $el['ct'] ) {
						continue;
					}
					$chain = array();
					if ( ! empty( $el['sels'] ) && is_array( $el['sels'] ) ) {
						$chain = $el['sels'];
					} elseif ( ! empty( $el['sel'] ) ) {
						$chain = array( $el['sel'] );
					}
					array_unshift( $chain, $selector );
					$config['els'][ $i ]['sel']  = $selector;
					$config['els'][ $i ]['sels'] = array_values( array_unique( $chain ) );
				}
			}
		}

		wp_register_script( 'agencoen-ab-test', AGENCOEN_PLUGIN_URL . 'assets/js/ace-ab-test.js', array(), AGENCOEN_VERSION, true );
		wp_enqueue_script( 'agencoen-ab-test' );

		// A JSON script tag rather than wp_localize_script: the shared asset
		// reads its configuration from the DOM by id, because a Shopify theme
		// extension ships files and has no server-side localisation step. One
		// script, one way of being configured.
		add_action(
			'wp_footer',
			function () use ( $config ) {
				printf(
					'<script type="application/json" id="ace-ab-config">%s</script>',
					wp_json_encode( $config )
				);
			},
			5
		);
	}

	/* --------------------------------------------------------------------- */
	/* Recording                                                              */
	/* --------------------------------------------------------------------- */

	public function register_route() {
		register_rest_route(
			'agencoen/v1',
			'/arm',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_exposure' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Record that a visitor saw one arm of one experiment.
	 *
	 * Always 204, whatever happened. The browser can do nothing with a failure,
	 * and a distinguishable error would tell someone probing which experiments
	 * and products exist.
	 */
	public function handle_exposure( \WP_REST_Request $request ) {
		if ( ! $this->is_enabled() ) {
			return new \WP_REST_Response( null, 204 );
		}

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new \WP_REST_Response( null, 204 );
		}

		$experiment = isset( $body['e'] ) ? sanitize_text_field( (string) $body['e'] ) : '';
		$subject    = isset( $body['s'] ) ? sanitize_text_field( (string) $body['s'] ) : '';
		$arm        = isset( $body['arm'] ) ? (string) $body['arm'] : '';

		// An unbucketed pageview reports arm null and is not an exposure: the
		// visitor read the canonical copy. Storing it would flood the canonical
		// arm with unrandomised traffic and pull every comparison toward zero.
		if ( '' === $experiment || '' === $subject || '' === $arm || 'null' === $arm ) {
			return new \WP_REST_Response( null, 204 );
		}
		// Same shape the database and the Rezolve Ai ingest enforce. Anything else is
		// dropped rather than stored and argued about later.
		if ( ! preg_match( '/^[a-z0-9]{1,8}$/', $arm ) || 'control' === $arm ) {
			return new \WP_REST_Response( null, 204 );
		}
		if ( strlen( $experiment ) > 64 || strlen( $subject ) > 191 ) {
			return new \WP_REST_Response( null, 204 );
		}

		$visitor = $this->visitor_hash();
		if ( ! $visitor ) {
			return new \WP_REST_Response( null, 204 );
		}

		$this->record_exposure( $experiment, $subject, $arm, $visitor );
		return new \WP_REST_Response( null, 204 );
	}

	/**
	 * The visitor's cookie, hashed with a salt that never leaves this site.
	 *
	 * The raw value is a random string this plugin did not choose and cannot
	 * reverse into anything, but it is still an identifier for a person, so it
	 * is not what gets reported. The salt is generated once per site, so the
	 * same visitor on two different WordPress sites hashes to two unrelated
	 * values and nothing can be joined across them.
	 */
	private function visitor_hash() {
		if ( empty( $_COOKIE[ self::VISITOR_COOKIE ] ) ) {
			return '';
		}
		$raw = sanitize_text_field( wp_unslash( $_COOKIE[ self::VISITOR_COOKIE ] ) );
		if ( '' === $raw || strlen( $raw ) > 128 ) {
			return '';
		}
		return substr( hash_hmac( 'sha256', $raw, $this->salt() ), 0, 40 );
	}

	private function salt() {
		$salt = get_option( self::OPT_SALT, '' );
		if ( ! is_string( $salt ) || '' === $salt ) {
			$salt = wp_generate_password( 64, false, false );
			update_option( self::OPT_SALT, $salt, false );
		}
		return $salt;
	}

	/** Upsert one (day, experiment, visitor) exposure row. */
	private function record_exposure( $experiment, $subject, $arm, $visitor ) {
		global $wpdb;
		$table = self::events_table();

		// One row per visitor per experiment per day, so a visitor who reloads
		// forty times carries the weight of one. Counting pageviews would make
		// the variance formula on the Rezolve Ai side a lie: it assumes independent
		// observations, and forty reloads are one observation.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (day, experiment, subject, arm, visitor, converted)
				 VALUES (%s, %s, %s, %s, %s, 0)
				 ON DUPLICATE KEY UPDATE subject = VALUES(subject), arm = VALUES(arm)",
				gmdate( 'Y-m-d' ),
				$experiment,
				$subject,
				$arm,
				$visitor
			)
		);
	}

	/**
	 * Mark this visitor as converted, on every experiment they were exposed to.
	 *
	 * EVERY experiment, not just one. A visitor can be in two tests at once, and
	 * a conversion is evidence for each of them: they read both versions and
	 * then bought. Attributing it to one would leave the other's treatment arm
	 * with an exposure and no outcome, which reads as a version that fails to
	 * convert.
	 */
	public function record_conversion( $order_id ) {
		if ( ! $this->is_enabled() || ! $order_id ) {
			return;
		}
		$visitor = $this->visitor_hash();
		if ( ! $visitor ) {
			return;
		}

		global $wpdb;
		$table = self::events_table();
		$since = gmdate( 'Y-m-d', time() - ( self::ATTRIBUTION_DAYS * DAY_IN_SECONDS ) );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET converted = 1 WHERE visitor = %s AND day >= %s",
				$visitor,
				$since
			)
		);
	}

	/**
	 * Mark this visitor's recent exposures as having reached a basket.
	 *
	 * The same shape and the same attribution window as `record_conversion`,
	 * for the same reason: a shopper who reads a product today and adds it on
	 * Thursday was persuaded by what they read today. Kept as a separate flag
	 * rather than folded into `converted`, because a version that wins on
	 * basket adds and loses on checkouts loses the merchant money, and that
	 * contrast is the whole point of drawing the step.
	 */
	public function record_cart() {
		if ( ! $this->is_enabled() ) {
			return;
		}
		$visitor = $this->visitor_hash();
		if ( ! $visitor ) {
			return;
		}

		global $wpdb;
		$table = self::events_table();
		$since = gmdate( 'Y-m-d', time() - ( self::ATTRIBUTION_DAYS * DAY_IN_SECONDS ) );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET carted = 1 WHERE visitor = %s AND day >= %s",
				$visitor,
				$since
			)
		);
	}

	/* --------------------------------------------------------------------- */
	/* Reporting                                                              */
	/* --------------------------------------------------------------------- */

	/**
	 * Send recent exposures to Rezolve Ai.
	 *
	 * Reported as EVENTS rather than as counts, because the readout folds them
	 * by visitor to decide who converted, and a count cannot be folded. Each row
	 * becomes one exposure event and, when the visitor bought, one conversion
	 * event. Both carry a deterministic uid so re-sending a day changes nothing.
	 */
	public function report_back() {
		if ( ! $this->is_enabled() || ! $this->plugin->settings()->is_ab_report_enabled() ) {
			return;
		}
		$store_id = $this->plugin->settings()->get_store_id();
		if ( ! $store_id ) {
			return;
		}

		global $wpdb;
		$table = self::events_table();
		$since = gmdate( 'Y-m-d', time() - ( self::REPORT_DAYS * DAY_IN_SECONDS ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT day, experiment, subject, arm, visitor, converted, carted
				 FROM {$table} WHERE day >= %s ORDER BY day DESC, id ASC LIMIT %d",
				$since,
				self::REPORT_ROWS
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return;
		}

		$events = array();
		foreach ( $rows as $row ) {
			$base = $row['day'] . ':' . $row['experiment'] . ':' . $row['visitor'];
			$events[] = array(
				'uid'        => 'wpab:' . $base,
				'day'        => $row['day'],
				'name'       => 'product_viewed',
				'visitor'    => $row['visitor'],
				'variant'    => $row['arm'],
				'subject'    => $row['subject'],
				// WHICH TEST the arm belongs to. The row has always carried it
				// -- it is half the unique key on this table -- and it was
				// simply never sent. Without it Rezolve Ai could only separate two
				// experiments by time, so a project running two at once pooled
				// their shoppers in both directions.
				'experiment' => $row['experiment'],
			);
			if ( ! empty( $row['carted'] ) ) {
				$events[] = array(
					// ':cart', not ':a', which would read as arm "a" at a glance.
					// Deterministic like its siblings, so re-sending a day is a
					// no-op: 'wpab:X', 'wpab:X:cart' and 'wpab:X:c' are three
					// distinct ids for the three steps of one exposure.
					'uid'        => 'wpab:' . $base . ':cart',
					'day'        => $row['day'],
					'name'       => 'product_added_to_cart',
					'visitor'    => $row['visitor'],
					// The arm, for the same reason the conversion carries it:
					// an event with no arm is a visitor who was never bucketed,
					// and the step would show exposures with no outcome.
					'variant'    => $row['arm'],
					'subject'    => $row['subject'],
					'experiment' => $row['experiment'],
				);
			}
			if ( ! empty( $row['converted'] ) ) {
				$events[] = array(
					'uid'      => 'wpab:' . $base . ':c',
					'day'      => $row['day'],
					'name'     => 'checkout_completed',
					'visitor'  => $row['visitor'],
					// The conversion carries the arm too. Without it the Rezolve Ai
					// readout skips the row entirely, because an event with no
					// arm is a visitor who was never bucketed, and the arm would
					// show exposures with no outcome.
					'variant'  => $row['arm'],
					'subject'  => $row['subject'],
					'experiment' => $row['experiment'],
				);
			}
		}

		$this->plugin->api()->ab_events_ingest( $store_id, $events );
	}

	/**
	 * Pull the current experiment configs from Rezolve Ai and store them per product.
	 *
	 * A FULL RECONCILE, not an apply of changes. The response is the complete
	 * set of products running a test right now, so anything this site is holding
	 * a config for and Rezolve Ai did not send has stopped, and the config is removed.
	 * That is the only signal a stopped test gives: there is no "experiment
	 * ended" event, and a config left behind would keep bucketing visitors into
	 * a test whose results nobody will ever read.
	 */
	public function sync_configs() {
		if ( ! $this->is_enabled() ) {
			// Not just "do nothing": if the feature has been switched OFF, every
			// config this site is holding must go, or a page could keep splitting
			// visitors after the site owner turned the feature off.
			$this->clear_all_configs();
			return;
		}
		$store_id = $this->plugin->settings()->get_store_id();
		if ( ! $store_id ) {
			return;
		}

		$response = $this->plugin->api()->experiment_configs( $store_id );
		if ( is_wp_error( $response ) ) {
			// Left exactly as it was. A failed poll is not evidence that a test
			// ended, and clearing on a network error would stop every running
			// test on the site the first time Rezolve Ai was briefly unreachable.
			return;
		}

		// `Api_Client::parse_response()` UNWRAPS the `data` envelope, so a body of
		// `{"data":{"configs":[...]}}` arrives here as `['configs' => [...]]`.
		// Reaching through a second `data` key therefore always found nothing,
		// and the reconcile loop below reads "nothing" as "no product is running
		// a test" and deletes every stored config -- including the ones the
		// apply queue had just delivered. So a test could never reach the
		// storefront, and any config that did was wiped on the next tick.
		// Every other caller in this plugin already reads the unwrapped shape;
		// `Apply_Queue::drain()` reads `$response['items']`.
		$configs = isset( $response['configs'] ) && is_array( $response['configs'] )
			? $response['configs']
			: array();

		$seen = array();
		foreach ( $configs as $entry ) {
			if ( empty( $entry['productId'] ) || empty( $entry['config'] ) ) {
				continue;
			}
			$post_id = $this->post_id_for_product( (string) $entry['productId'] );
			if ( ! $post_id ) {
				continue;
			}
			$this->set_config( $post_id, $entry['config'] );
			$seen[ $post_id ] = true;
		}

		foreach ( $this->posts_with_config() as $post_id ) {
			if ( ! isset( $seen[ $post_id ] ) ) {
				$this->set_config( $post_id, array() );
			}
		}
	}

	/**
	 * The WordPress post behind a Rezolve Ai product id.
	 *
	 * Rezolve Ai keys content by the identifier the FEED uses, which for this plugin is
	 * the WooCommerce product id as a string. Resolved through the repository so
	 * a store that keys on SKU keeps working.
	 */
	/**
	 * PUBLIC because `Apply_Queue` resolves the same way.
	 *
	 * A queue entry and an A/B config both name a product as Rezolve Ai knows it,
	 * usually the merchant's own SKU. Two resolvers would eventually disagree,
	 * and the failure would be a test configured on one post while its content
	 * was written to another -- with both halves reporting success.
	 */
	public function post_id_for_product( $product_id ) {
		if ( ctype_digit( $product_id ) ) {
			$post = get_post( (int) $product_id );
			if ( $post && 'product' === $post->post_type ) {
				return (int) $product_id;
			}
		}
		$by_sku = function_exists( 'wc_get_product_id_by_sku' ) ? wc_get_product_id_by_sku( $product_id ) : 0;
		return $by_sku ? (int) $by_sku : 0;
	}

	/** Post ids currently holding a config. */
	private function posts_with_config() {
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 5000",
				self::META_CONFIG
			)
		);
		return array_map( 'intval', (array) $ids );
	}

	/** Remove every stored config. Used when the feature is switched off. */
	public function clear_all_configs() {
		foreach ( $this->posts_with_config() as $post_id ) {
			delete_post_meta( $post_id, self::META_CONFIG );
		}
	}

	/** Drop rows past the cap, oldest day first. Mirrors the other trackers. */
	public function prune() {
		global $wpdb;
		$table = self::events_table();
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		if ( $count <= self::MAX_ROWS ) {
			return;
		}
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} ORDER BY day ASC, id ASC LIMIT %d",
				$count - self::MAX_ROWS
			)
		);
	}
}
