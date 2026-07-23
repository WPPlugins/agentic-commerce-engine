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

	/**
	 * Register every wp_ajax_agencoen_* action. Commerce actions are wrapped
	 * with a WooCommerce gate so a hand-crafted request on a Woo-less site can
	 * never reach wc_* functions.
	 */
	public function register() {
		$core     = array( 'connect', 'disconnect', 'refresh_dashboard', 'save_settings', 'regenerate_feed_token', 'dismiss_connect_notice', 'dismiss_start_card' );
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
			'enrich_batch',
			'approve_batch',
			'export',
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

		wp_send_json_success( array( 'queued' => (int) $result['count'] ) );
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
				'score'  => get_post_meta( $id, Meta::SCORE, true ),
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
		$ids     = $this->plugin->repository()->ids_with_status( Meta::STATUS_ENRICHED, 1, self::APPROVE_ALL_CAP );
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
		);
		foreach ( $bools as $field => $option ) {
			if ( isset( $_POST[ $field ] ) ) {
				$settings->set_bool( $option, sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) );
			}
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
			$img_id = $product->get_image_id();
			$status = (string) get_post_meta( $id, Meta::STATUS, true );
			$rows[] = array(
				'id'     => $id,
				'name'   => $product->get_name(),
				'sku'    => $product->get_sku(),
				'image'  => $img_id ? wp_get_attachment_image_url( $img_id, 'thumbnail' ) : wc_placeholder_img_src( 'thumbnail' ),
				'status' => '' !== $status ? $status : 'none',
				'score'  => get_post_meta( $id, Meta::SCORE, true ),
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
			$items[] = array(
				'id'       => $id,
				'name'     => $product->get_name(),
				'score'    => get_post_meta( $id, Meta::SCORE, true ),
				'fields'   => $fields,
				'editLink' => get_edit_post_link( $id, 'raw' ),
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
		wp_send_json_success(
			array(
				'id'      => $id,
				'diff'    => $this->plugin->review()->diff_for( $id ),
				'content' => is_array( $content ) ? $content : array(),
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

		$batch = $this->plugin->repository()->query_products(
			array(
				'status' => Meta::STATUS_ENRICHED,
				'search' => $search,
				'per'    => 100,
				'page'   => 1,
			)
		);
		if ( empty( $batch['ids'] ) ) {
			wp_send_json_success(
				array(
					'processed' => 0,
					'remaining' => 0,
					'done'      => true,
				)
			);
		}

		$applied = 0;
		foreach ( $batch['ids'] as $id ) {
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
			)
		)['total'];

		wp_send_json_success(
			array(
				'processed' => $applied,
				'remaining' => $remaining,
				'done'      => $remaining <= 0,
			)
		);
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
					fputcsv(
						$out,
						array(
							$id,
							$product->get_sku(),
							$product->get_name(),
							$status,
							get_post_meta( $id, Meta::SCORE, true ),
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
