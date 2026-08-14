<?php
/**
 * Human pageview counting, and the nightly report back to ACE.
 *
 * WHY A JAVASCRIPT BEACON AND NOT A template_redirect HOOK
 * The bot tracker counts on template_redirect, which is fine for crawlers: page
 * caches routinely bypass for unrecognised user agents, and AI bots hit
 * long-tail URLs that are rarely warm. For humans the cache hit IS the common
 * case. WP Super Cache in expert mode, LiteSpeed with server-level LSCache,
 * Varnish and Cloudflare APO all serve the HTML without booting PHP, so
 * template_redirect never fires and a server-side counter undercounts by the
 * cache hit ratio, typically 80 to 95 percent. The plugin's own readme already
 * says so. You cannot fix that from PHP: if PHP did not run, PHP cannot emit a
 * cache-buster.
 *
 * A REST route is not page-cached, so a beacon survives every cache layer.
 *
 * WHAT THIS DELIBERATELY DOES NOT COLLECT
 * No cookie, no session id, no fingerprint, and no IP address, hashed or
 * otherwise. That is a deliberate limit, not an oversight: it keeps the plugin
 * clear of WP.org's data-collection requirements and means the site owner does
 * not need a visitor consent mechanism to switch it on. The cost is that this
 * source can report PAGEVIEWS but not sessions or unique visitors, and ACE
 * shows that limit rather than approximating a smaller number.
 *
 * @package Agencoen
 */

namespace Agencoen;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Traffic_Tracker {

	/** Cap on stored rows, mirroring Bot_Tracker. Human path cardinality is far
	 * higher than crawler cardinality, so this is deliberately lower per day. */
	const MAX_ROWS  = 20000;
	const FOLD_KEEP = 200;
	/** Paths reported per day. */
	const REPORT_PAGES = 200;

	/** Referrer hosts worth attributing. Everything else folds to '(other)'. */
	const REFERRER_HOSTS = array(
		'chatgpt.com',
		'chat.openai.com',
		'perplexity.ai',
		'www.perplexity.ai',
		'claude.ai',
		'copilot.microsoft.com',
		'gemini.google.com',
		'google.com',
		'www.google.com',
		'bing.com',
		'www.bing.com',
	);

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_beacon' ) );
	}

	/**
	 * The beacon endpoint.
	 *
	 * permission_callback is __return_true on purpose. A WP nonce is
	 * user-scoped and would be baked into the cached HTML, going stale within
	 * 12 to 24 hours, which is the classic cached-nonce bug: the counter would
	 * silently stop working on exactly the sites that need the beacon most.
	 * The protections are instead a same-origin check and a published-post
	 * allowlist, so the worst a forged request can do is inflate the count of a
	 * page that already exists.
	 */
	public function register_route() {
		register_rest_route(
			'agencoen/v1',
			'/hit',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_hit' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'post_id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'ref' => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/** Enqueue on singular content only. ~700 bytes, no dependencies. */
	public function enqueue_beacon() {
		if ( ! $this->plugin->settings()->is_traffic_enabled() ) {
			return;
		}
		if ( ! is_singular() || is_preview() ) {
			return;
		}
		// Editors and admins are excluded, not all logged-in users: on a
		// WooCommerce store the logged-in customers are precisely the
		// converting cohort, and dropping them would gut the conversion half.
		if ( current_user_can( 'edit_posts' ) ) {
			return;
		}

		$post_id = get_queried_object_id();
		if ( ! $post_id ) {
			return;
		}

		$url = esc_url_raw( rest_url( 'agencoen/v1/hit' ) );
		$js  = sprintf(
			'(function(){try{var d={post_id:%d,ref:document.referrer||""};' .
			'var b=new Blob([JSON.stringify(d)],{type:"application/json"});' .
			'if(navigator.sendBeacon){navigator.sendBeacon(%s,b);}' .
			'else{fetch(%s,{method:"POST",keepalive:true,' .
			'headers:{"Content-Type":"application/json"},body:JSON.stringify(d)});}' .
			'}catch(e){}})();',
			(int) $post_id,
			wp_json_encode( $url ),
			wp_json_encode( $url )
		);

		wp_register_script( 'agencoen-beacon', '', array(), AGENCOEN_VERSION, true );
		wp_enqueue_script( 'agencoen-beacon' );
		wp_add_inline_script( 'agencoen-beacon', $js );
	}

	/**
	 * Record one pageview.
	 *
	 * Always returns 204 regardless of outcome: the browser can do nothing with
	 * a failure, and a distinguishable error would tell someone probing which
	 * post ids exist.
	 */
	public function handle_hit( \WP_REST_Request $request ) {
		if ( ! $this->plugin->settings()->is_traffic_enabled() ) {
			return new \WP_REST_Response( null, 204 );
		}

		// Same-origin only. Not a security boundary on its own, but it stops
		// the endpoint being used as a counter from anywhere on the internet.
		$origin = $request->get_header( 'origin' );
		if ( $origin && ! $this->is_same_origin( $origin ) ) {
			return new \WP_REST_Response( null, 204 );
		}

		$ua = isset( $_SERVER['HTTP_USER_AGENT'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
			: '';
		if ( '' === $ua || $this->looks_automated( $ua ) ) {
			return new \WP_REST_Response( null, 204 );
		}

		$post_id = absint( $request->get_param( 'post_id' ) );
		if ( ! $this->is_countable_post( $post_id ) ) {
			return new \WP_REST_Response( null, 204 );
		}

		$this->record( $this->today(), $post_id, $this->referrer_host( (string) $request->get_param( 'ref' ) ) );

		return new \WP_REST_Response( null, 204 );
	}

	private function is_same_origin( $origin ) {
		$site = wp_parse_url( home_url(), PHP_URL_HOST );
		$sent = wp_parse_url( $origin, PHP_URL_HOST );
		return $site && $sent && strtolower( $site ) === strtolower( $sent );
	}

	/**
	 * Only published, publicly-viewable singular content counts. This is the
	 * allowlist that makes the nonce-free endpoint safe: an arbitrary id cannot
	 * create a row.
	 */
	private function is_countable_post( $post_id ) {
		if ( ! $post_id ) {
			return false;
		}
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return false;
		}
		return is_post_type_viewable( $post->post_type );
	}

	/**
	 * Crawlers that execute JavaScript. The bot tracker's catalog only knows AI
	 * agents, so this adds the generic markers; anything matched here is
	 * counted by that tracker instead, not lost.
	 */
	private function looks_automated( $ua ) {
		if ( null !== Bot_Detector::match( $ua ) ) {
			return true;
		}
		return (bool) preg_match(
			'/bot|crawl|spider|slurp|preview|monitor|headless|lighthouse|pagespeed/i',
			$ua
		);
	}

	/**
	 * Referrer reduced to an allowlisted HOST.
	 *
	 * Never the full URL: a referring search URL carries the query, which is
	 * content about a person. The host alone answers the question that matters
	 * here, which is whether answer engines are sending real people.
	 */
	private function referrer_host( $referrer ) {
		if ( '' === $referrer ) {
			return '';
		}
		$host = wp_parse_url( $referrer, PHP_URL_HOST );
		if ( ! $host ) {
			return '';
		}
		$host = strtolower( $host );
		$site = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( $host === $site ) {
			return '';
		}
		return in_array( $host, self::REFERRER_HOSTS, true ) ? $host : '(other)';
	}

	private function today() {
		return current_time( 'Y-m-d' );
	}

	private function record( $day, $post_id, $referrer ) {
		global $wpdb;
		$table = Db::page_hits_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (day, post_id, referrer, views) VALUES (%s, %d, %s, 1)
				 ON DUPLICATE KEY UPDATE views = views + 1",
				$day,
				$post_id,
				$referrer
			)
		);
	}

	/** Retention and row cap, mirroring Bot_Tracker::prune(). */
	public function prune() {
		global $wpdb;
		$table     = Db::page_hits_table();
		$retention = (int) $this->plugin->settings()->get_bots_retention();
		$cutoff    = gmdate( 'Y-m-d', strtotime( "-{$retention} days" ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE day < %s", $cutoff ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		if ( $total <= self::MAX_ROWS ) {
			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB
		$days = $wpdb->get_col( "SELECT DISTINCT day FROM {$table} ORDER BY day ASC" );
		$last = count( $days ) - 1;
		foreach ( $days as $i => $day ) {
			// Never drop the newest day, however far over the cap we are.
			if ( $i >= $last || $total <= self::MAX_ROWS ) {
				break;
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB
			$removed = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE day = %s", $day ) );
			$total  -= $removed;
		}
	}

	/**
	 * Send day-level aggregates to ACE.
	 *
	 * Opt-in and connection gated, with its OWN checkbox rather than sharing the
	 * bot-report one. WP.org guideline 7 treats these differently and rightly
	 * so: bot hits are machine traffic, pageviews are derived from human
	 * behaviour, and folding both under one consent is the sort of thing that
	 * gets a plugin pulled.
	 */
	public function report_back() {
		$settings = $this->plugin->settings();
		if ( ! $settings->is_traffic_report() ) {
			return false;
		}
		$store_id = $settings->get_store_id();
		if ( ! $store_id ) {
			return false;
		}

		global $wpdb;
		$table     = Db::page_hits_table();
		$yesterday = gmdate( 'Y-m-d', strtotime( '-1 day' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT day, post_id, referrer, views FROM {$table}
				 WHERE day >= %s ORDER BY day ASC, views DESC, id ASC",
				$yesterday
			)
		);
		if ( empty( $rows ) ) {
			return false;
		}

		$days = array();
		foreach ( $rows as $row ) {
			$day = (string) $row->day;
			if ( ! isset( $days[ $day ] ) ) {
				$days[ $day ] = array();
			}
			$post_id = (int) $row->post_id;
			if ( ! isset( $days[ $day ][ $post_id ] ) ) {
				if ( count( $days[ $day ] ) >= self::REPORT_PAGES ) {
					continue;
				}
				$days[ $day ][ $post_id ] = array(
					'postId'    => $post_id,
					'views'     => 0,
					'referrers' => array(),
				);
			}
			$days[ $day ][ $post_id ]['views'] += (int) $row->views;
			$referrer = (string) $row->referrer;
			if ( '' !== $referrer ) {
				$days[ $day ][ $post_id ]['referrers'][] = array(
					'host'  => $referrer,
					'views' => (int) $row->views,
				);
			}
		}

		$payload = array();
		foreach ( $days as $day => $pages ) {
			$payload[] = array(
				'date'  => $day,
				'pages' => array_values( $pages ),
			);
		}

		$result = $this->plugin->api()->traffic_ingest( $store_id, $payload );
		return ! is_wp_error( $result );
	}
}
