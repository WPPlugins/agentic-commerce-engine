<?php
/**
 * admin-ajax endpoints for the admin UI. Every handler verifies the nonce and
 * the manage_woocommerce capability, then delegates to a subsystem.
 *
 * @package Agencoen
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Ajax (core + commerce handler group)
 */
class Ajax extends Ajax_Base {

	const MAX_ENRICH_ALL  = 200;
	const APPROVE_ALL_CAP = 200;

	/** Transient holding the ChatGPT product file URL: { storeId, url }. */
	const OPENAI_FEED_CACHE = 'agencoen_openai_feed_url';

	/**
	 * Register every wp_ajax_agencoen_* action. Commerce actions are wrapped
	 * with a WooCommerce gate so a hand-crafted request on a Woo-less site can
	 * never reach wc_* functions.
	 */
	public function register() {
		$core     = array( 'connect', 'disconnect', 'refresh_dashboard', 'save_settings', 'regenerate_feed_token', 'dismiss_connect_notice', 'dismiss_start_card', 'dismiss_traffic_notice' );
		$commerce = array(
			'sync',
			'enrich',
			'poll_status',
			'approve',
			'approve_all',
			'dismiss',
			'revert',
			'products',
			'review_list',
			'review_diff',
			'review_save_edit',
			'quality_get',
			'quality_rescore',
			'review_held_back',
			'enrich_batch',
			'approve_batch',
			'export',
			'openai_feed',
		);
		foreach ( $core as $action ) {
			add_action( 'wp_ajax_agencoen_' . $action, array( $this, $action ) );
		}
		foreach ( $commerce as $action ) {
			add_action(
				'wp_ajax_agencoen_' . $action,
				function () use ( $action ) {
					// Verified here so the WooCommerce check cannot be probed
					// before the nonce. Each handler repeats the check as its
					// own first statement.
					check_ajax_referer( self::NONCE_ACTION, 'nonce' );
					$this->require_manage();
					$this->require_woo();
					$this->$action();
				}
			);
		}
	}

	/* --------------------------------------------------------------------- */
	/* Connection                                                            */
	/* --------------------------------------------------------------------- */

	/**
	 * Connect: store key, register the store, persist credentials.
	 */
	public function connect() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();

		$settings = $this->plugin->settings();
		$key      = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
		if ( '' === $key ) {
			wp_send_json_error( array( 'message' => __( 'Please paste your API key.', 'agentic-commerce-engine' ) ) );
		}
		if ( ! empty( $_POST['base_url'] ) ) {
			$settings->set_base_url( esc_url_raw( wp_unslash( $_POST['base_url'] ) ) );
		}
		$settings->set_api_key( $key );

		// Registration provisions server-side; give it more headroom than the
		// interactive default (a human is watching a spinner, not a page load).
		$result = $this->plugin->api()->with_options( array( 'timeout' => 20 ) )->register(
			array(
				'siteUrl'       => home_url(),
				'siteTitle'     => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'platform'      => $this->plugin->has_woo() ? 'woocommerce' : 'wordpress',
				'wpVersion'     => get_bloginfo( 'version' ),
				'wcVersion'     => defined( 'WC_VERSION' ) ? WC_VERSION : null,
				'pluginVersion' => AGENCOEN_VERSION,
				'webhookUrl'    => Webhook::callback_url(),
			)
		);

		if ( is_wp_error( $result ) ) {
			// Don't leave a half-connected state on a bad key.
			$settings->clear_connection();
			wp_send_json_error( $this->error_payload( $result ) );
		}

		$settings->save_connection(
			isset( $result['storeId'] ) ? (string) $result['storeId'] : '',
			isset( $result['webhookSecret'] ) ? (string) $result['webhookSecret'] : '',
			isset( $result['syncConfig'] ) && is_array( $result['syncConfig'] ) ? $result['syncConfig'] : array(),
			array(
				'projectId' => isset( $result['projectId'] ) ? (string) $result['projectId'] : '',
				'platform'  => isset( $result['platform'] ) ? (string) $result['platform'] : '',
				'features'  => isset( $result['features'] ) && is_array( $result['features'] ) ? $result['features'] : array(),
			)
		);

		$this->plugin->cron()->reschedule_sync();
		Dashboard::reset_memo();
		Dashboard::credits( $this->plugin, Dashboard::FETCH_FRESH );

		wp_send_json_success( Dashboard::data( $this->plugin, Dashboard::FETCH_WARM ) );
	}

	/**
	 * Hide the optional-account notice for the current user.
	 */
	public function dismiss_connect_notice() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		Settings::dismiss_connect_notice();
		wp_send_json_success( array( 'dismissed' => true ) );
	}

	/**
	 * Hide the "measurement is off" notice for the current user.
	 */
	public function dismiss_traffic_notice() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		Settings::dismiss_traffic_notice();
		wp_send_json_success( array( 'dismissed' => true ) );
	}

	/**
	 * Hide the Dashboard "Start here" card for the current user.
	 */
	public function dismiss_start_card() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		Settings::dismiss_start_card();
		wp_send_json_success( array( 'dismissed' => true ) );
	}

	/**
	 * Disconnect: forget credentials.
	 */
	public function disconnect() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$this->plugin->settings()->clear_connection();
		$this->plugin->cron()->reschedule_sync();
		wp_send_json_success( array( 'connected' => false ) );
	}

	/* --------------------------------------------------------------------- */
	/* Sync / enrich                                                         */
	/* --------------------------------------------------------------------- */

	/**
	 * Manual "Sync now".
	 */
	public function sync() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$this->require_connected();

		$result = $this->plugin->sync()->kickoff_full_sync();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $this->error_payload( $result ) );
		}
		$this->plugin->repository()->bust_counts_cache();
		Dashboard::reset_memo();
		wp_send_json_success(
			array(
				'sync'      => $result,
				'dashboard' => Dashboard::data( $this->plugin ),
			)
		);
	}

	/**
	 * Enrich an explicit set of products with the chosen content types.
	 */
	public function enrich() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$this->require_connected();

		$ids = isset( $_POST['ids'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['ids'] ) ) : array();
		if ( empty( $ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No products selected.', 'agentic-commerce-engine' ) ) );
		}
		$requested = isset( $_POST['content_types'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['content_types'] ) ) : array();

		$result = $this->plugin->enrichment()->enrich_ids( $ids, array( 'contentTypes' => $this->resolve_content_types( $requested ) ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $this->error_payload( $result ) );
		}

		wp_send_json_success(
			array(
				'queued' => (int) $result['count'],
				// Left out because a running experiment is measuring them. Not
				// an error: the products are simply not available to enrich
				// until the experiment finishes.
				'skipped' => isset( $result['skipped'] ) ? (int) $result['skipped'] : 0,
			)
		);
	}

	/**
	 * Foreground status poll: advance in-flight jobs and report fresh statuses.
	 */
	public function poll_status() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$this->require_connected();

		$ids = isset( $_POST['ids'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['ids'] ) ) : array();

		// Advance any jobs these products belong to.
		$jobs = array();
		foreach ( $ids as $id ) {
			if ( Meta::STATUS_QUEUED === get_post_meta( $id, Meta::STATUS, true ) ) {
				$job = (string) get_post_meta( $id, Meta::JOB_ID, true );
				if ( '' !== $job ) {
					$jobs[ $job ] = true;
				}
			}
		}
		foreach ( array_keys( $jobs ) as $job_id ) {
			// Drive the unified-jobs poller inline so the UI sees fresh state.
			$this->plugin->poller()->poll( $job_id );
		}

		$out = array();
		foreach ( $ids as $id ) {
			$status = (string) get_post_meta( $id, Meta::STATUS, true );
			$out[]  = array(
				'id'     => $id,
				'status' => '' !== $status ? $status : 'none',
				'score'  => Quality::cached_overall( $id ),
			);
		}

		wp_send_json_success(
			array(
				'products' => $out,
				'counts'   => Dashboard::data( $this->plugin )['counts'],
			)
		);
	}

	/* --------------------------------------------------------------------- */
	/* Review actions                                                        */
	/* --------------------------------------------------------------------- */

	/**
	 * Approve (apply) one product.
	 */
	public function approve() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$id     = isset( $_POST['product_id'] ) ? (int) $_POST['product_id'] : 0;
		$fields = isset( $_POST['fields'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['fields'] ) ) : array();

		$result = $this->plugin->review()->approve( $id, $fields );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $this->error_payload( $result ) );
		}
		wp_send_json_success( array( 'id' => $id ) );
	}

	/**
	 * Approve all pending products (capped per call).
	 */
	public function approve_all() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		// Products a product split is known to hold are stepped past, and the
		// rest are asked about in one request before any is applied (4.29.0).
		$ids     = $this->plugin->repository()->ids_with_status( Meta::STATUS_ENRICHED, 1, self::APPROVE_ALL_CAP, true );
		$checked = $this->plugin->held_back()->check( $ids );
		if ( is_wp_error( $checked ) ) {
			wp_send_json_error( $this->error_payload( $checked ) );
		}
		$applied = 0;
		foreach ( $ids as $id ) {
			if ( true === $this->plugin->review()->approve( $id ) ) {
				++$applied;
			}
		}
		wp_send_json_success(
			array(
				'applied'   => $applied,
				'remaining' => $this->plugin->repository()->pending_review_count(),
				'held'      => $this->held_count(),
			)
		);
	}

	/**
	 * Dismiss one suggestion.
	 */
	public function dismiss() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$id = isset( $_POST['product_id'] ) ? (int) $_POST['product_id'] : 0;
		$this->plugin->review()->dismiss( $id );
		wp_send_json_success( array( 'id' => $id ) );
	}

	/**
	 * Revert one applied product to its backup.
	 */
	public function revert() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$id     = isset( $_POST['product_id'] ) ? (int) $_POST['product_id'] : 0;
		$result = $this->plugin->review()->revert( $id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $this->error_payload( $result ) );
		}
		wp_send_json_success( array( 'id' => $id ) );
	}

	/* --------------------------------------------------------------------- */
	/* Data                                                                  */
	/* --------------------------------------------------------------------- */

	/**
	 * Refresh dashboard payload. Serves two callers: the post-paint warm fill
	 * (no args; remote only where the cache is cold/stale) and the manual
	 * Refresh button / health check (`fresh=1`; forces a credits refetch).
	 */
	public function refresh_dashboard() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		if ( ! empty( $_POST['fresh'] ) ) {
			Dashboard::reset_memo();
			Dashboard::credits( $this->plugin, Dashboard::FETCH_FRESH );
		}
		wp_send_json_success( Dashboard::data( $this->plugin, Dashboard::FETCH_WARM ) );
	}

	/**
	 * Rotate the feed token and hand back the new tokened URLs so the
	 * Settings screen can swap them in place.
	 */
	public function regenerate_feed_token() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		Settings::regenerate_feed_token();
		wp_send_json_success(
			array(
				'urls' => array(
					'google'    => Feeds::feed_url( 'google' ),
					'microsoft' => Feeds::feed_url( 'microsoft' ),
					'agentic'   => Feeds::feed_url( 'agentic' ),
				),
			)
		);
	}

	/**
	 * The ChatGPT product file URL for this store (4.34.0), as the platform
	 * reports it from GET /api/v1/plugin/feeds (`openaiFeedUrl`). The file is
	 * built and hosted by Rezolve Ai from the synced catalog, so a site that
	 * is not connected has none.
	 *
	 * A URL is cached for an hour: it is the store's own address and does not
	 * change. A failed read is an error, never "no file", so the screen can
	 * say it could not read it and offer to try again.
	 *
	 * Response: { connected: bool, url: string|null }.
	 */
	public function openai_feed() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();

		$settings = $this->plugin->settings();
		if ( ! $settings->is_connected() ) {
			wp_send_json_success(
				array(
					'connected' => false,
					'url'       => null,
				)
			);
		}

		$store_id = $settings->get_store_id();
		$cached   = get_transient( self::OPENAI_FEED_CACHE );
		if ( is_array( $cached ) && isset( $cached['storeId'], $cached['url'] ) && $cached['storeId'] === $store_id ) {
			wp_send_json_success(
				array(
					'connected' => true,
					'url'       => $cached['url'],
				)
			);
		}

		$feeds = $this->plugin->api()->plugin_feeds( $store_id );
		if ( is_wp_error( $feeds ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not read your ChatGPT product file URL from Rezolve Ai.', 'agentic-commerce-engine' ) ) );
		}
		$url = Legal_Policies::http_url( is_array( $feeds ) && isset( $feeds['openaiFeedUrl'] ) ? $feeds['openaiFeedUrl'] : null );
		if ( null !== $url ) {
			set_transient(
				self::OPENAI_FEED_CACHE,
				array(
					'storeId' => $store_id,
					'url'     => $url,
				),
				HOUR_IN_SECONDS
			);
		}
		wp_send_json_success(
			array(
				'connected' => true,
				'url'       => $url,
			)
		);
	}

	/**
	 * Persist automation, content publishing, crawler tracking, and advanced
	 * settings. Boolean fields update only when the form submitted them
	 * (screens omit controls that don't apply, e.g. commerce toggles on a
	 * Woo-less site, and absent controls must never clobber saved values);
	 * the settings screen always posts an explicit yes/no for every control
	 * it renders.
	 */
	public function save_settings() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$settings = $this->plugin->settings();

		$bools = array(
			'auto_sync'      => Settings::OPT_AUTO_SYNC,
			'auto_apply'     => Settings::OPT_AUTO_APPLY,
			'schema_enabled' => Settings::OPT_SCHEMA_ENABLED,
			'auto_append'    => Settings::OPT_AUTO_APPEND,
			'debug_log'      => Settings::OPT_DEBUG_LOG,
			'article_schema' => Settings::OPT_ARTICLE_SCHEMA,
			'bots_enabled'   => Settings::OPT_BOTS_ENABLED,
			'bots_paths'     => Settings::OPT_BOTS_PATHS,
			'bots_report'    => Settings::OPT_BOTS_REPORT,
			'traffic_enabled' => Settings::OPT_TRAFFIC_ENABLED,
			'traffic_report'  => Settings::OPT_TRAFFIC_REPORT,
			'ab_enabled'      => Settings::OPT_AB_ENABLED,
			'ab_report'       => Settings::OPT_AB_REPORT,
		);
		$agencoen_traffic_before = $settings->is_traffic_report();
		foreach ( $bools as $field => $option ) {
			if ( isset( $_POST[ $field ] ) ) {
				$settings->set_bool( $option, sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) );
			}
		}
		// A dismissal answered the question "do you want to switch this on?".
		// Once the answer changes, the question is open again, so the notice
		// comes back rather than staying silenced by a months-old click.
		if ( $agencoen_traffic_before !== $settings->is_traffic_report() ) {
			Settings::reset_traffic_notice();
		}

		if ( isset( $_POST['ab_selector'] ) ) {
			// Stored raw and validated on READ, in Settings::ab_selector(), so a
			// value that predates the validation cannot slip through by having
			// been saved before the rule existed.
			update_option( Settings::OPT_AB_SELECTOR, sanitize_text_field( wp_unslash( $_POST['ab_selector'] ) ) );
		}
		if ( isset( $_POST['sync_frequency'] ) ) {
			$settings->set_sync_frequency( sanitize_key( wp_unslash( $_POST['sync_frequency'] ) ) );
		}
		if ( isset( $_POST['content_status'] ) ) {
			$status = sanitize_key( wp_unslash( $_POST['content_status'] ) );
			update_option( Settings::OPT_CONTENT_STATUS, in_array( $status, array( 'draft', 'publish' ), true ) ? $status : 'draft' );
		}
		if ( isset( $_POST['content_format'] ) ) {
			$format = sanitize_key( wp_unslash( $_POST['content_format'] ) );
			update_option( Settings::OPT_CONTENT_FORMAT, in_array( $format, array( 'blocks', 'classic' ), true ) ? $format : 'blocks' );
		}
		if ( isset( $_POST['content_author'] ) ) {
			update_option( Settings::OPT_CONTENT_AUTHOR, max( 0, (int) $_POST['content_author'] ) );
		}
		/*
		 * Where IRL cards and internal links sit on the product page.
		 *
		 * Saved locally AND pushed to the platform, because this is one choice
		 * with three editors: the web app, the Shopify app and this screen. A
		 * local-only save would be overwritten by the next hourly heartbeat,
		 * which mirrors the platform's value down. Pushing first means the
		 * merchant's change survives that round trip.
		 *
		 * Validation lives in Settings::save_placement(), which drops anything
		 * outside the vocabulary rather than letting a malformed value blank
		 * the storefront.
		 */
		$agencoen_placement = array();
		foreach ( Settings::placeable_types() as $agencoen_type ) {
			$agencoen_field = 'placement_' . str_replace( '-', '_', $agencoen_type );
			if ( isset( $_POST[ $agencoen_field ] ) ) {
				$agencoen_placement[ $agencoen_type ] = sanitize_key( wp_unslash( $_POST[ $agencoen_field ] ) );
			}
		}
		/*
		 * Where each content type is written (4.25.0): the block, one of this
		 * store's fields, or a section of the description. Only a screen that
		 * shows the choice posts it. The platform validates it and is the
		 * source of truth, so it is pushed with the placement in one request
		 * and saved locally from the platform's answer, never from the post.
		 */
		$agencoen_destinations = array();
		$agencoen_candidates   = null;
		foreach ( Content_Destinations::TYPES as $agencoen_type ) {
			$agencoen_field = 'destination_' . str_replace( '-', '_', $agencoen_type );
			if ( ! isset( $_POST[ $agencoen_field ] ) ) {
				continue;
			}
			if ( null === $agencoen_candidates ) {
				$agencoen_candidates = Content_Destinations::candidates( $this->plugin );
			}
			$agencoen_heading     = isset( $_POST[ 'destination_heading_' . str_replace( '-', '_', $agencoen_type ) ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'destination_heading_' . str_replace( '-', '_', $agencoen_type ) ] ) ) : '';
			$agencoen_destination = Content_Destinations::from_choice(
				$agencoen_type,
				sanitize_text_field( wp_unslash( $_POST[ $agencoen_field ] ) ),
				$agencoen_heading,
				$agencoen_candidates
			);
			if ( null !== $agencoen_destination ) {
				$agencoen_destinations[ $agencoen_type ] = $agencoen_destination;
			}
		}
		if ( ! empty( $agencoen_destinations ) ) {
			if ( ! empty( $agencoen_placement ) ) {
				$settings->save_placement( $agencoen_placement );
			}
			if ( ! $settings->is_connected() ) {
				wp_send_json_error( array( 'message' => __( 'Connect to Rezolve Ai to choose where content goes.', 'agentic-commerce-engine' ) ) );
			}
			$agencoen_saved = $this->plugin->api()->update_content_placement(
				$settings->get_store_id(),
				$settings->get_placements(),
				$agencoen_destinations
			);
			if ( is_wp_error( $agencoen_saved ) ) {
				wp_send_json_error( array( 'message' => $agencoen_saved->get_error_message() ) );
			}
			if ( isset( $agencoen_saved['placement'] ) && is_array( $agencoen_saved['placement'] ) ) {
				$settings->save_placement( $agencoen_saved['placement'] );
			}
			if ( isset( $agencoen_saved['destinations'] ) && is_array( $agencoen_saved['destinations'] ) ) {
				Content_Destinations::save( $agencoen_saved['destinations'] );
			}
			$agencoen_placement = array();
		}

		if ( ! empty( $agencoen_placement ) ) {
			$settings->save_placement( $agencoen_placement );
			if ( $settings->is_connected() ) {
				// Best effort: the local save already happened, and the merchant
				// should not see an error because the platform was briefly
				// unreachable. The next heartbeat reconciles either way.
				$agencoen_pushed = $this->plugin->api()->update_content_placement(
					$settings->get_store_id(),
					$settings->get_placements()
				);
				// A platform older than 4.16.0's server change rejects the whole
				// request over the two keys it does not know (highlights and
				// product-qa), which would silently drop the merchant's IRL and
				// links choice too, and the next heartbeat would then overwrite
				// it locally. Retry with only the keys every platform accepts;
				// the other two stay local until the platform learns them.
				if ( is_wp_error( $agencoen_pushed ) ) {
					$agencoen_error = $agencoen_pushed->get_error_data();
					if ( is_array( $agencoen_error ) && isset( $agencoen_error['status'] ) && 400 === (int) $agencoen_error['status'] ) {
						$this->plugin->api()->update_content_placement(
							$settings->get_store_id(),
							array_intersect_key( $settings->get_placements(), array_flip( array( 'irl-cards', 'internal-links' ) ) )
						);
					}
				}
			}
		}

		if ( isset( $_POST['bots_retention'] ) ) {
			$days = (int) $_POST['bots_retention'];
			update_option( Settings::OPT_BOTS_RETENTION, in_array( $days, array( 30, 90, 180 ), true ) ? $days : 90 );
		}

		$base_url = isset( $_POST['base_url'] ) ? esc_url_raw( wp_unslash( $_POST['base_url'] ) ) : '';
		if ( '' !== $base_url ) {
			$settings->set_base_url( $base_url );
		}

		$this->plugin->cron()->reschedule_sync();
		Dashboard::reset_memo();
		wp_send_json_success( Dashboard::data( $this->plugin ) );
	}

	/**
	 * Paged, searchable, filterable, sortable product list.
	 */
	public function products() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();

		$args = array(
			'search'   => isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '',
			'status'   => isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'all',
			'category' => isset( $_POST['category'] ) ? sanitize_title( wp_unslash( $_POST['category'] ) ) : '',
			'orderby'  => isset( $_POST['orderby'] ) ? sanitize_key( wp_unslash( $_POST['orderby'] ) ) : 'recent',
			'page'     => isset( $_POST['page'] ) ? max( 1, (int) $_POST['page'] ) : 1,
			'per'      => 20,
		);
		$result = $this->plugin->repository()->query_products( $args );

		$rows = array();
		foreach ( $result['ids'] as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product ) {
				continue;
			}
			$media  = Review::row_media( $product );
			$status = (string) get_post_meta( $id, Meta::STATUS, true );
			$rows[] = array(
				'id'      => $id,
				'name'    => $product->get_name(),
				'sku'     => $product->get_sku(),
				// The Products table keeps WooCommerce's placeholder; Review
				// reads `thumb`, which is '' when the product has no image.
				'image'   => '' !== $media['thumb'] ? $media['thumb'] : wc_placeholder_img_src( 'thumbnail' ),
				'thumb'   => $media['thumb'],
				'liveUrl' => $media['liveUrl'],
				'status'  => '' !== $status ? $status : 'none',
				// Measured product-level quality, or null for "Not scored".
				'score'   => Quality::cached_overall( $id ),
			);
		}

		wp_send_json_success(
			array(
				'rows'       => $rows,
				'page'       => $result['page'],
				'per'        => $result['per'],
				'total'      => $result['total'],
				'totalPages' => $result['totalPages'],
				'counts'     => $this->plugin->repository()->status_counts_full(),
			)
		);
	}

	/**
	 * Paged list of review items as lightweight summaries (no full diff).
	 */
	public function review_list() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$page   = isset( $_POST['page'] ) ? max( 1, (int) $_POST['page'] ) : 1;
		$search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';

		$result = $this->plugin->repository()->query_products(
			array(
				'status'  => Meta::STATUS_ENRICHED,
				'search'  => $search,
				'orderby' => 'score',
				'page'    => $page,
				'per'     => 20,
			)
		);

		$labels = Meta::fields();
		$items  = array();
		foreach ( $result['ids'] as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product ) {
				continue;
			}
			$content = get_post_meta( $id, Meta::CONTENT, true );
			$pending = get_post_meta( $id, Meta::PENDING, true );
			$fields  = array();
			if ( is_array( $content ) && ! empty( $content ) ) {
				foreach ( array_keys( $content ) as $type ) {
					$fields[] = Content_Types::label( $type );
				}
			} elseif ( is_array( $pending ) ) {
				foreach ( $labels as $key => $label ) {
					if ( ! empty( $pending[ $key ] ) ) {
						$fields[] = $label;
					}
				}
			}
			// The main image and the live page only; the full gallery is read
			// on expand (review_diff), so twenty rows stay light.
			$media   = Review::row_media( $product );
			$items[] = array(
				'id'       => $id,
				'name'     => $product->get_name(),
				'thumb'    => $media['thumb'],
				'liveUrl'  => $media['liveUrl'],
				// From the cache, so the chip can paint at once. The screen then
				// asks `quality_get` for fresh numbers. `known` separates "never
				// read" (no chip yet) from "read, and nothing is measured".
				'quality'  => array(
					'known'   => '' !== get_post_meta( $id, Meta::QUALITY_AT, true ),
					'overall' => Quality::cached_overall( $id ),
				),
				'fields'   => $fields,
				'editLink' => get_edit_post_link( $id, 'raw' ),
				// As last checked, so the note paints at once. The screen then
				// asks `review_held_back` for the current answer.
				'heldBack' => $this->held_label( Held_Back::stored( $id ) ),
			);
		}

		wp_send_json_success(
			array(
				'items'      => $items,
				'page'       => $result['page'],
				'total'      => $result['total'],
				'totalPages' => $result['totalPages'],
			)
		);
	}

	/**
	 * Full before/after diff for one product (loaded lazily on expand).
	 */
	public function review_diff() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$id      = isset( $_POST['product_id'] ) ? (int) $_POST['product_id'] : 0;
		$content = get_post_meta( $id, Meta::CONTENT, true );
		// Decided here, from the product's state, never by the screen: an
		// applied product is edited live and a waiting one as a draft.
		$mode = Review::edit_mode( $id );
		wp_send_json_success(
			array(
				'id'      => $id,
				'mode'    => $mode,
				'diff'    => $this->plugin->review()->diff_for( $id ),
				'content' => is_array( $content ) ? $content : array(),
				'extras'  => $this->plugin->review()->extras_for( $id, 'live' === $mode ),
				'gallery' => Review::gallery( wc_get_product( $id ) ),
			)
		);
	}

	/**
	 * Save a merchant's edit to one field of a product on the Review screen.
	 *
	 * `value` is JSON for list fields and HTML for the description. Nothing the
	 * browser checked is trusted: Edit_Values::normalize() cleans and limits
	 * every field again, and a value it refuses comes back as `errors` with row
	 * indexes, so the screen can mark the rows rather than show one toast.
	 */
	public function review_save_edit() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();

		$id    = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$field = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		$mode  = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';

		$raw = null;
		if ( isset( $_POST['value'] ) ) {
			if ( 'description' === $field ) {
				$raw = wp_kses_post( wp_unslash( $_POST['value'] ) );
			} elseif ( Edit_Values::is_structured( $field ) ) {
				// Unslashed BEFORE decoding, or every quote inside the JSON is
				// still escaped and nothing decodes. Each field of the decoded
				// list is sanitized by Edit_Values::normalize() below.
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON; every field is sanitized by Edit_Values::normalize().
				$raw = json_decode( wp_unslash( $_POST['value'] ), true );
			} else {
				$raw = sanitize_text_field( wp_unslash( $_POST['value'] ) );
			}
		}

		$result = $this->plugin->review()->save_edit( $id, $field, $mode, $raw );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $this->error_payload( $result ) );
		}
		if ( empty( $result['ok'] ) ) {
			$errors = isset( $result['errors'] ) && is_array( $result['errors'] ) ? $result['errors'] : array();
			wp_send_json_error(
				array(
					'message' => 1 === count( $errors ) && isset( $errors[0]['message'] )
						? $errors[0]['message']
						: __( 'Some of this needs fixing before it can be saved.', 'agentic-commerce-engine' ),
					'errors'  => $errors,
				)
			);
		}
		wp_send_json_success( $result );
	}

	/**
	 * Measured quality scores for the products on the Review screen.
	 *
	 * Reads fresh from Rezolve Ai and caches what came back, so the Products
	 * screen, dashboard and CSV catch up whenever Review is opened. A product
	 * whose read failed keeps its cached record and carries `error`, so the
	 * screen can say the numbers may be old rather than showing a failed read
	 * as "Not scored". Not connected, it serves the cache and says so.
	 */
	public function quality_get() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();

		$ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : array();
		$ids = array_slice( array_values( array_unique( array_filter( $ids ) ) ), 0, Quality::MAX_PRODUCTS );
		if ( empty( $ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No products selected.', 'agentic-commerce-engine' ) ) );
		}

		$connected = $this->plugin->settings()->is_connected();
		$fetched   = $connected
			? $this->plugin->quality()->fetch( $ids )
			: array(
				'records' => array(),
				'errors'  => array(),
			);

		$products = array();
		foreach ( $ids as $id ) {
			$record = isset( $fetched['records'][ $id ] ) ? $fetched['records'][ $id ] : Quality::read_record( $id );
			$view   = Quality::view( $record );

			$view['id'] = $id;
			if ( isset( $fetched['errors'][ $id ] ) ) {
				$view['error'] = $fetched['errors'][ $id ];
			}
			if ( ! $connected ) {
				$view['offline'] = true;
			}
			$products[] = $view;
		}

		wp_send_json_success( array( 'products' => $products ) );
	}

	/**
	 * Which products on the Review page a product split is holding back now
	 * (4.29.0), asked after the list has painted.
	 *
	 * DISPLAY ONLY, so a failed check is an error the screen ignores: the row
	 * keeps whatever it showed, and Apply asks again and refuses on its own.
	 */
	public function review_held_back() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();

		$ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : array();
		$ids = array_slice( array_values( array_unique( array_filter( $ids ) ) ), 0, 100 );
		if ( empty( $ids ) ) {
			wp_send_json_success( array( 'products' => (object) array() ) );
		}

		$checked = $this->plugin->held_back()->check( $ids );
		if ( is_wp_error( $checked ) ) {
			wp_send_json_error( $this->error_payload( $checked ) );
		}
		$products = array();
		foreach ( $ids as $id ) {
			$products[ (string) $id ] = $this->held_label( isset( $checked[ $id ] ) ? $checked[ $id ] : null );
		}
		wp_send_json_success( array( 'products' => (object) $products ) );
	}

	/**
	 * A held product's line for the screen, or '' when it is not held.
	 *
	 * @param array|null $note Held_Back note.
	 * @return string
	 */
	private function held_label( $note ) {
		return is_array( $note ) ? Held_Back::label( $note ) : '';
	}

	/**
	 * Ask Rezolve Ai to score some of one product's content again.
	 *
	 * Answers with what was queued, not with new numbers: scoring takes 10 to
	 * 20 seconds, and the screen polls `quality_get` until the score moves.
	 */
	public function quality_rescore() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$this->require_connected();

		$id     = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$fields = isset( $_POST['fields'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['fields'] ) ) : array();
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'No products selected.', 'agentic-commerce-engine' ) ) );
		}

		$result = $this->plugin->quality()->rescore( $id, $fields );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $this->error_payload( $result ) );
		}

		$view       = Quality::view( $result['record'] );
		$view['id'] = $id;
		wp_send_json_success(
			array(
				'product' => $view,
				'queued'  => (int) $result['queued'],
			)
		);
	}

	/**
	 * Enrich the next chunk of products that still need it (optionally filtered).
	 * The UI calls this repeatedly to drive a progress bar across large catalogs.
	 */
	public function enrich_batch() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$this->require_connected();

		$search    = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$category  = isset( $_POST['category'] ) ? sanitize_title( wp_unslash( $_POST['category'] ) ) : '';
		$status    = isset( $_POST['scope_status'] ) ? sanitize_key( wp_unslash( $_POST['scope_status'] ) ) : 'not_enriched';
		$requested = isset( $_POST['content_types'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['content_types'] ) ) : array();
		if ( 'all' === $status ) {
			$status = 'not_enriched'; // Don't re-enrich already-processed products by default.
		}
		$filter = array(
			'status'   => $status,
			'search'   => $search,
			'category' => $category,
		);

		$batch = $this->plugin->repository()->query_products( array_merge( $filter, array( 'per' => 100, 'page' => 1 ) ) );
		if ( empty( $batch['ids'] ) ) {
			wp_send_json_success(
				array(
					'processed' => 0,
					'remaining' => 0,
					'done'      => true,
				)
			);
		}

		$result = $this->plugin->enrichment()->enrich_ids( $batch['ids'], array( 'contentTypes' => $this->resolve_content_types( $requested ) ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $this->error_payload( $result ) );
		}

		// Enriched products become "queued" and drop out of the filter, so the
		// remaining count shrinks each round and the UI loop terminates.
		$remaining = $this->plugin->repository()->query_products( array_merge( $filter, array( 'per' => 1, 'page' => 1 ) ) )['total'];
		wp_send_json_success(
			array(
				'processed' => count( $batch['ids'] ),
				'remaining' => $remaining,
				'done'      => $remaining <= 0,
			)
		);
	}

	/**
	 * Approve the next chunk of pending products (optionally filtered).
	 */
	public function approve_batch() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();
		$search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';

		// Products a product split holds stay waiting (4.29.0). Each batch
		// steps past the ones already known to be held, so they cannot fill
		// every batch and stall the rest, and asks about the others in one
		// request before any is applied.
		$batch = $this->plugin->repository()->query_products(
			array(
				'status' => Meta::STATUS_ENRICHED,
				'search' => $search,
				'per'    => 100,
				'page'   => 1,
				'held'   => 'exclude',
			)
		);
		if ( empty( $batch['ids'] ) ) {
			wp_send_json_success(
				array(
					'processed' => 0,
					'remaining' => 0,
					'done'      => true,
					'held'      => $this->held_count(),
				)
			);
		}

		$checked = $this->plugin->held_back()->check( $batch['ids'] );
		if ( is_wp_error( $checked ) ) {
			wp_send_json_error( $this->error_payload( $checked ) );
		}

		$applied = 0;
		$held    = 0;
		foreach ( $batch['ids'] as $id ) {
			if ( ! empty( $checked[ $id ] ) ) {
				++$held;
				continue;
			}
			if ( true === $this->plugin->review()->approve( $id ) ) {
				++$applied;
			}
		}

		$remaining = $this->plugin->repository()->query_products(
			array(
				'status' => Meta::STATUS_ENRICHED,
				'search' => $search,
				'per'    => 1,
				'page'   => 1,
				'held'   => 'exclude',
			)
		)['total'];

		wp_send_json_success(
			array(
				// A batch that only found held products still moved on: they
				// are stepped past from now on. Counted as progress so the
				// screen asks for the next batch rather than stopping here.
				'processed' => $applied + $held,
				'remaining' => $remaining,
				'done'      => $remaining <= 0,
				'held'      => $this->held_count(),
			)
		);
	}

	/**
	 * Waiting products a product split is holding back, as last checked.
	 *
	 * @return int
	 */
	private function held_count() {
		return (int) $this->plugin->repository()->query_products(
			array(
				'status' => Meta::STATUS_ENRICHED,
				'per'    => 1,
				'page'   => 1,
				'held'   => 'only',
			)
		)['total'];
	}

	/**
	 * Stream a CSV of enriched/applied AI content (capped for memory safety).
	 */
	public function export() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_manage();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=ace-enriched-' . gmdate( 'Ymd-His' ) . '.csv' );

		// phpcs:disable WordPress.WP.AlternativeFunctions -- streaming a CSV download to php://output; WP_Filesystem targets files, not the output stream.
		$out = fopen( 'php://output', 'w' );
		// The explicit escape argument keeps PHP 8.4+ from deprecation-warning on every row.
		fputcsv( $out, array( 'product_id', 'sku', 'name', 'status', 'score', 'ai_title', 'ai_short_description', 'ai_description', 'ai_seo_title', 'ai_seo_description' ), ',', '"', '\\' );

		$cap   = 5000;
		$count = 0;
		foreach ( array( Meta::STATUS_ENRICHED, Meta::STATUS_APPLIED ) as $status ) {
			$page = 1;
			while ( $count < $cap ) {
				$batch = $this->plugin->repository()->query_products(
					array(
						'status' => $status,
						'per'    => 100,
						'page'   => $page,
					)
				);
				if ( empty( $batch['ids'] ) ) {
					break;
				}
				foreach ( $batch['ids'] as $id ) {
					$product = wc_get_product( $id );
					if ( ! $product ) {
						continue;
					}
					$p = get_post_meta( $id, Meta::PENDING, true );
					$p = is_array( $p ) ? $p : array();
					// Blank, not 0, when nothing is measured.
					$overall = Quality::cached_overall( $id );
					fputcsv(
						$out,
						array(
							$id,
							$product->get_sku(),
							$product->get_name(),
							$status,
							null === $overall ? '' : $overall,
							isset( $p['title'] ) ? $p['title'] : '',
							isset( $p['short_description'] ) ? $p['short_description'] : '',
							isset( $p['description'] ) ? $p['description'] : '',
							isset( $p['seo_title'] ) ? $p['seo_title'] : '',
							isset( $p['seo_description'] ) ? $p['seo_description'] : '',
						),
						',',
						'"',
						'\\'
					);
					++$count;
					if ( $count >= $cap ) {
						break;
					}
				}
				++$page;
			}
		}
		fclose( $out );
		// phpcs:enable WordPress.WP.AlternativeFunctions
		exit;
	}

	/* --------------------------------------------------------------------- */
	/* Helpers                                                               */
	/* --------------------------------------------------------------------- */

	/**
	 * Verify nonce + capability or stop with a JSON error.
	 */
	/*
	 * guard(), require_connected(), require_woo(), and error_payload() are
	 * inherited from Ajax_Base (shared with the Content / AEO / Bots handler
	 * groups).
	 */

	/**
	 * Collect product ids that still need a first enrichment, up to a limit.
	 *
	 * @param int $limit Max ids.
	 * @return int[]
	 */
	private function ids_needing_enrichment( $limit ) {
		$skip    = array( Meta::STATUS_QUEUED, Meta::STATUS_ENRICHED, Meta::STATUS_APPLIED );
		$per     = 100;
		$page    = 1;
		$collect = array();

		while ( count( $collect ) < $limit ) {
			$ids = $this->plugin->repository()->paged_ids( $page, $per );
			if ( empty( $ids ) ) {
				break;
			}
			foreach ( $ids as $id ) {
				$status = (string) get_post_meta( $id, Meta::STATUS, true );
				if ( ! in_array( $status, $skip, true ) ) {
					$collect[] = $id;
					if ( count( $collect ) >= $limit ) {
						break;
					}
				}
			}
			++$page;
		}
		return $collect;
	}

	/**
	 * Resolve + persist the merchant's content-type selection for this enrich.
	 *
	 * @param string[] $requested Sanitized content-type keys posted by the UI (may be empty).
	 * @return string[]
	 */
	private function resolve_content_types( array $requested ) {
		$types = Content_Types::sanitize_selection( $requested );
		if ( ! empty( $types ) ) {
			$this->plugin->settings()->set_content_types( $types );
			return $types;
		}
		return $this->plugin->settings()->get_content_types();
	}
}
