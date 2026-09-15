<?php
/**
 * Typed wrapper over wp_options. The single place that touches plugin config.
 *
 * @package Agencoen
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Settings
 */
class Settings {

	const OPT_API_KEY        = 'agencoen_api_key';
	const OPT_BASE_URL       = 'agencoen_base_url';
	const OPT_STORE_ID       = 'agencoen_store_id';
	const OPT_WEBHOOK_SECRET = 'agencoen_webhook_secret';
	const OPT_SYNC_CONFIG    = 'agencoen_sync_config';
	const OPT_CONTENT_TYPES  = 'agencoen_content_types';
	const OPT_AUTO_APPEND    = 'agencoen_auto_append';
	const OPT_PLACEMENT      = 'agencoen_content_placement';
	const OPT_AUTO_SYNC      = 'agencoen_auto_sync';
	const OPT_SYNC_FREQUENCY = 'agencoen_sync_frequency';
	const OPT_AUTO_APPLY     = 'agencoen_auto_apply';
	const OPT_SCHEMA_ENABLED = 'agencoen_schema_enabled';
	const OPT_DEBUG_LOG      = 'agencoen_debug_log';
	const OPT_LAST_SYNC      = 'agencoen_last_sync';
	const OPT_SYNC_CURSOR    = 'agencoen_sync_cursor';
	const OPT_DB_VERSION     = 'agencoen_db_version';

	/* New in 4.0 */
	const OPT_PROJECT_ID     = 'agencoen_project_id';
	const OPT_PLATFORM       = 'agencoen_platform';
	const OPT_FEATURES       = 'agencoen_features';
	const OPT_AB_ENABLED     = 'agencoen_ab_enabled';
	const OPT_AB_REPORT      = 'agencoen_ab_report';
	const OPT_AB_SELECTOR    = 'agencoen_ab_selector';
	const OPT_BOTS_ENABLED   = 'agencoen_bots_enabled';
	const OPT_BOTS_PATHS     = 'agencoen_bots_paths';
	const OPT_BOTS_RETENTION = 'agencoen_bots_retention';
	const OPT_BOTS_REPORT    = 'agencoen_bots_report';
	const OPT_CONTENT_STATUS = 'agencoen_content_status';
	const OPT_CONTENT_FORMAT = 'agencoen_content_format';
	const OPT_CONTENT_AUTHOR = 'agencoen_content_author';
	const OPT_ARTICLE_SCHEMA = 'agencoen_article_schema';

	/* New in 4.1 */
	const OPT_FEED_TOKEN = 'agencoen_feed_token';

	/* New in 4.4 — storefront traffic and sales.
	 *
	 * Deliberately SEPARATE from the bot-report opt-in. WP.org guideline 7
	 * treats these differently and rightly so: bot hits are machine traffic,
	 * pageviews are derived from human behaviour, and folding both under one
	 * consent is the sort of thing that gets a plugin pulled. */
	const OPT_TRAFFIC_ENABLED = 'agencoen_traffic_enabled';
	const OPT_TRAFFIC_REPORT  = 'agencoen_traffic_report';

	/** Per-user dismissal of the optional-connection notice. */
	const META_CONNECT_NOTICE = 'agencoen_connect_notice_dismissed';
	const META_START_CARD     = 'agencoen_start_card_dismissed';
	/** Per-user dismissal of the "measurement is off" notice. */
	const META_TRAFFIC_NOTICE = 'agencoen_traffic_notice_dismissed';

	/**
	 * Seed defaults on activation without clobbering an already-connected store.
	 */
	public static function set_activation_defaults() {
		add_option( self::OPT_BASE_URL, AGENCOEN_DEFAULT_API_URL );
		add_option( self::OPT_AUTO_SYNC, 'no' );
		add_option( self::OPT_SYNC_FREQUENCY, 'daily' );
		add_option( self::OPT_AUTO_APPLY, 'no' );
		add_option( self::OPT_SCHEMA_ENABLED, 'yes' );
		add_option( self::OPT_DEBUG_LOG, 'no' );
		self::get_feed_token();
		update_option( self::OPT_DB_VERSION, AGENCOEN_VERSION );
	}

	/**
	 * Per-site feed token, generated lazily on first use so existing installs
	 * self-heal. Every public feed URL requires it.
	 *
	 * @return string 32 alphanumeric chars.
	 */
	public static function get_feed_token() {
		$token = (string) get_option( self::OPT_FEED_TOKEN, '' );
		if ( '' !== $token ) {
			return $token;
		}
		return self::regenerate_feed_token();
	}

	/**
	 * Rotate the feed token (invalidates every previously issued feed URL).
	 *
	 * @return string The new token.
	 */
	public static function regenerate_feed_token() {
		$token = wp_generate_password( 32, false, false );
		update_option( self::OPT_FEED_TOKEN, $token, false );
		return $token;
	}

	/* --------------------------------------------------------------------- */
	/* Connection                                                            */
	/* --------------------------------------------------------------------- */

	/**
	 * @return string
	 */
	public function get_api_key() {
		return (string) get_option( self::OPT_API_KEY, '' );
	}

	/**
	 * Store the API key (never autoloaded).
	 *
	 * @param string $key API key.
	 */
	public function set_api_key( $key ) {
		update_option( self::OPT_API_KEY, sanitize_text_field( $key ), false );
	}

	/**
	 * Base URL with no trailing slash. Falls back to the compiled-in default.
	 *
	 * @return string
	 */
	public function get_base_url() {
		$url = (string) get_option( self::OPT_BASE_URL, AGENCOEN_DEFAULT_API_URL );
		$url = trim( $url );
		if ( '' === $url ) {
			$url = AGENCOEN_DEFAULT_API_URL;
		}
		return untrailingslashit( $url );
	}

	/**
	 * @param string $url Base URL.
	 */
	public function set_base_url( $url ) {
		update_option( self::OPT_BASE_URL, esc_url_raw( untrailingslashit( trim( $url ) ) ) );
	}

	/**
	 * @return string
	 */
	public function get_store_id() {
		return (string) get_option( self::OPT_STORE_ID, '' );
	}

	/**
	 * @return string
	 */
	public function get_webhook_secret() {
		return (string) get_option( self::OPT_WEBHOOK_SECRET, '' );
	}

	/**
	 * Persist the result of a successful register() call.
	 *
	 * @param string $store_id    Store id.
	 * @param string $secret      Webhook secret.
	 * @param array  $sync_config syncConfig payload.
	 * @param array  $extra       v4 register extras { projectId, platform, features }.
	 */
	public function save_connection( $store_id, $secret, $sync_config = array(), $extra = array() ) {
		update_option( self::OPT_STORE_ID, sanitize_text_field( $store_id ) );
		update_option( self::OPT_WEBHOOK_SECRET, sanitize_text_field( $secret ), false );
		if ( ! empty( $sync_config ) && is_array( $sync_config ) ) {
			update_option( self::OPT_SYNC_CONFIG, $sync_config );
		}
		if ( ! empty( $extra['projectId'] ) ) {
			update_option( self::OPT_PROJECT_ID, sanitize_text_field( (string) $extra['projectId'] ) );
		}
		if ( ! empty( $extra['platform'] ) ) {
			update_option( self::OPT_PLATFORM, sanitize_text_field( (string) $extra['platform'] ) );
		}
		if ( isset( $extra['features'] ) && is_array( $extra['features'] ) ) {
			$this->save_features( $extra['features'] );
		}
		// Where storefront content goes. Mirrored from the platform so a
		// merchant who set it in the web app or the Shopify app sees the same
		// choice here, and the storefront has one answer.
		if ( isset( $extra['contentPlacement'] ) && is_array( $extra['contentPlacement'] ) ) {
			$this->save_placement( $extra['contentPlacement'] );
		}
	}

	/**
	 * Replace the sync config from a source other than register.
	 *
	 * It rides the hourly heartbeat as well, because register reaches a site
	 * exactly once, at connect time. A server that lowers its batch ceiling
	 * afterwards could otherwise never tell the sites already connected, which
	 * is precisely what happened when 4.13 began sending variations inline and
	 * batches grew.
	 *
	 * @param array $sync_config syncConfig payload.
	 */
	public function save_sync_config( $sync_config ) {
		if ( ! empty( $sync_config ) && is_array( $sync_config ) ) {
			update_option( self::OPT_SYNC_CONFIG, $sync_config );
		}
	}

	/**
	 * Forget the connection (keeps automation prefs).
	 */
	public function clear_connection() {
		delete_option( self::OPT_API_KEY );
		delete_option( self::OPT_STORE_ID );
		delete_option( self::OPT_WEBHOOK_SECRET );
		delete_option( self::OPT_SYNC_CONFIG );
		delete_option( self::OPT_PROJECT_ID );
		delete_option( self::OPT_PLATFORM );
		delete_option( self::OPT_FEATURES );
		delete_option( self::OPT_PLACEMENT );
		delete_transient( Dashboard::CREDITS_TRANSIENT );
		delete_transient( Dashboard::CREDITS_BACKOFF );
		Dashboard::reset_memo();
		self::reset_connect_notice();
	}

	/* --------------------------------------------------------------------- */
	/* Connect notice (per-user, so it is never a site-wide permanent nag)   */
	/* --------------------------------------------------------------------- */

	/**
	 * Has the current user dismissed the "connect an account" notice?
	 *
	 * @return bool
	 */
	public static function connect_notice_dismissed() {
		$user_id = get_current_user_id();
		return $user_id > 0 && '1' === (string) get_user_meta( $user_id, self::META_CONNECT_NOTICE, true );
	}

	/**
	 * Hide the notice for the current user.
	 */
	public static function dismiss_connect_notice() {
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			update_user_meta( $user_id, self::META_CONNECT_NOTICE, '1' );
		}
	}

	/**
	 * Show it again for everyone. Called on connect and disconnect so the
	 * notice reflects the current state rather than a stale dismissal.
	 */
	public static function reset_connect_notice() {
		delete_metadata( 'user', 0, self::META_CONNECT_NOTICE, '', true );
	}

	/* --------------------------------------------------------------------- */
	/* Traffic notice (per-user, same discipline as the connect notice)       */
	/* --------------------------------------------------------------------- */

	/**
	 * Has the current user dismissed the "measurement is off" notice?
	 *
	 * @return bool
	 */
	public static function traffic_notice_dismissed() {
		$user_id = get_current_user_id();
		return $user_id > 0 && '1' === (string) get_user_meta( $user_id, self::META_TRAFFIC_NOTICE, true );
	}

	/**
	 * Hide the traffic notice for the current user.
	 */
	public static function dismiss_traffic_notice() {
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			update_user_meta( $user_id, self::META_TRAFFIC_NOTICE, '1' );
		}
	}

	/**
	 * Show it again for everyone.
	 *
	 * Called when the traffic options change, so a site owner who switches
	 * reporting off and later wants it back is offered it again rather than
	 * having silenced the offer permanently with one earlier dismissal.
	 */
	public static function reset_traffic_notice() {
		delete_metadata( 'user', 0, self::META_TRAFFIC_NOTICE, '', true );
	}

	/**
	 * Has the current user dismissed the Dashboard "Start here" card?
	 *
	 * @return bool
	 */
	public static function start_card_dismissed() {
		$user_id = get_current_user_id();
		return $user_id > 0 && '1' === (string) get_user_meta( $user_id, self::META_START_CARD, true );
	}

	/**
	 * Hide the "Start here" card for the current user.
	 */
	public static function dismiss_start_card() {
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			update_user_meta( $user_id, self::META_START_CARD, '1' );
		}
	}

	/**
	 * Rezolve Ai project id linked to this site (from register/bootstrap).
	 *
	 * @return string
	 */
	public function get_project_id() {
		return (string) get_option( self::OPT_PROJECT_ID, '' );
	}

	/**
	 * Server-declared feature flags (contentHub, ecomAeo, botTraffic, ...).
	 *
	 * @return array<string,bool>
	 */
	public function get_features() {
		$features = get_option( self::OPT_FEATURES, array() );
		return is_array( $features ) ? array_map( 'boolval', $features ) : array();
	}

	/**
	 * @param string $feature Feature key.
	 * @return bool True when the server did not say otherwise.
	 */
	public function feature_enabled( $feature ) {
		$features = $this->get_features();
		return ! array_key_exists( $feature, $features ) || $features[ $feature ];
	}

	/**
	 * @param array $features Feature map from register/heartbeat.
	 */
	public function save_features( $features ) {
		update_option( self::OPT_FEATURES, array_map( 'boolval', (array) $features ) );
	}

	/**
	 * Connected = we have both a key and a registered store id.
	 *
	 * @return bool
	 */
	public function is_connected() {
		return '' !== $this->get_api_key() && '' !== $this->get_store_id();
	}

	/**
	 * Server-declared sync configuration (maxBatchSize, supportedFields).
	 *
	 * @return array
	 */
	public function get_sync_config() {
		$config = get_option( self::OPT_SYNC_CONFIG, array() );
		return is_array( $config ) ? $config : array();
	}

	/**
	 * Server batch ceiling; defaults to 100.
	 *
	 * @return int
	 */
	public function get_max_batch_size() {
		$config = $this->get_sync_config();
		$max    = isset( $config['maxBatchSize'] ) ? (int) $config['maxBatchSize'] : 100;
		return $max > 0 ? min( $max, 100 ) : 100;
	}

	/**
	 * Selected content types (defaults to the recommended set).
	 *
	 * @return string[]
	 */
	public function get_content_types() {
		$saved = get_option( self::OPT_CONTENT_TYPES, null );
		if ( ! is_array( $saved ) || empty( $saved ) ) {
			return Content_Types::recommended();
		}
		return Content_Types::sanitize_selection( $saved );
	}

	/**
	 * @param array $types Content type keys.
	 */
	public function set_content_types( $types ) {
		update_option( self::OPT_CONTENT_TYPES, Content_Types::sanitize_selection( $types ) );
	}

	/**
	 * Whether to auto-append IRL cards + internal links to the storefront page.
	 *
	 * SUPERSEDED by get_placement(), which says WHERE rather than just whether.
	 * Kept because it is still the master switch: a merchant who turned
	 * auto-append off before placement existed must not have content reappear
	 * under them on upgrade.
	 *
	 * @return bool
	 */
	public function is_auto_append() {
		return 'yes' === get_option( self::OPT_AUTO_APPEND, 'yes' );
	}

	/**
	 * Valid placements. Mirrors CONTENT_PLACEMENTS in
	 * src/lib/content-placement.ts, which is the platform's vocabulary and the
	 * one the Shopify app speaks too.
	 *
	 * @return string[]
	 */
	public static function placements() {
		return array( 'below_description', 'above_description', 'bottom_of_page', 'manual', 'hidden' );
	}

	/**
	 * Content types whose position the merchant chooses.
	 *
	 * @return string[]
	 */
	public static function placeable_types() {
		return array( 'irl-cards', 'internal-links', 'highlights', 'product-qa' );
	}

	/**
	 * Where a content type renders on the product page.
	 *
	 * The value is mirrored from the platform (bootstrap -> save_placement) so
	 * a merchant who set it in the web app or the Shopify app sees the same
	 * thing here, and vice versa. An unknown or missing value falls back to
	 * 'below_description' rather than to 'hidden': losing content the merchant
	 * approved is a worse failure than putting it somewhere they did not pick.
	 *
	 * @param string $type One of placeable_types().
	 * @return string
	 */
	public function get_placement( $type ) {
		$stored = get_option( self::OPT_PLACEMENT, array() );
		$value  = is_array( $stored ) && isset( $stored[ $type ] ) ? $stored[ $type ] : '';
		if ( in_array( $value, self::placements(), true ) ) {
			return $value;
		}
		return 'below_description';
	}

	/**
	 * @return array<string,string> Every placeable type, defaults filled in.
	 */
	public function get_placements() {
		$out = array();
		foreach ( self::placeable_types() as $type ) {
			$out[ $type ] = $this->get_placement( $type );
		}
		return $out;
	}

	/**
	 * Persist a placement map, dropping anything not in the vocabulary so a
	 * malformed payload cannot blank the storefront.
	 *
	 * @param array $placement Map of content type to placement.
	 */
	public function save_placement( $placement ) {
		$clean = array();
		foreach ( self::placeable_types() as $type ) {
			if ( ! isset( $placement[ $type ] ) ) {
				continue;
			}
			$value = is_string( $placement[ $type ] ) ? $placement[ $type ] : '';
			if ( in_array( $value, self::placements(), true ) ) {
				$clean[ $type ] = $value;
			}
		}
		if ( ! empty( $clean ) ) {
			update_option( self::OPT_PLACEMENT, array_merge( (array) get_option( self::OPT_PLACEMENT, array() ), $clean ) );
		}
	}

	/* --------------------------------------------------------------------- */
	/* Automation prefs                                                      */
	/* --------------------------------------------------------------------- */

	/**
	 * @return bool
	 */
	public function is_auto_sync() {
		return 'yes' === get_option( self::OPT_AUTO_SYNC, 'no' );
	}

	/**
	 * @return string daily|weekly
	 */
	public function get_sync_frequency() {
		$freq = (string) get_option( self::OPT_SYNC_FREQUENCY, 'daily' );
		return in_array( $freq, array( 'daily', 'weekly' ), true ) ? $freq : 'daily';
	}

	/**
	 * @param string $freq daily|weekly.
	 */
	public function set_sync_frequency( $freq ) {
		$freq = in_array( $freq, array( 'daily', 'weekly' ), true ) ? $freq : 'daily';
		update_option( self::OPT_SYNC_FREQUENCY, $freq );
	}

	/**
	 * @return bool
	 */
	public function is_auto_apply() {
		return 'yes' === get_option( self::OPT_AUTO_APPLY, 'no' );
	}

	/**
	 * @return bool
	 */
	public function is_schema_enabled() {
		return 'yes' === get_option( self::OPT_SCHEMA_ENABLED, 'yes' );
	}

	/**
	 * @return bool
	 */
	public function is_debug_log() {
		return 'yes' === get_option( self::OPT_DEBUG_LOG, 'no' );
	}

	/* --------------------------------------------------------------------- */
	/* Sync bookkeeping                                                      */
	/* --------------------------------------------------------------------- */

	/**
	 * @return string MySQL datetime or empty.
	 */
	public function get_last_sync() {
		return (string) get_option( self::OPT_LAST_SYNC, '' );
	}

	/**
	 * Stamp the last sync time (UTC).
	 */
	public function touch_last_sync() {
		update_option( self::OPT_LAST_SYNC, gmdate( 'Y-m-d H:i:s' ) );
	}

	/**
	 * @return int
	 */
	public function get_sync_cursor() {
		return (int) get_option( self::OPT_SYNC_CURSOR, 0 );
	}

	/**
	 * @param int $page Page index.
	 */
	public function set_sync_cursor( $page ) {
		update_option( self::OPT_SYNC_CURSOR, max( 0, (int) $page ) );
	}

	/* --------------------------------------------------------------------- */
	/* Content publishing prefs (4.0)                                        */
	/* --------------------------------------------------------------------- */

	/**
	 * @return string draft|publish — default status for published articles.
	 */
	public function get_content_status() {
		$status = (string) get_option( self::OPT_CONTENT_STATUS, 'draft' );
		return in_array( $status, array( 'draft', 'publish' ), true ) ? $status : 'draft';
	}

	/**
	 * @return string blocks|classic — post_content format.
	 */
	public function get_content_format() {
		$format = (string) get_option( self::OPT_CONTENT_FORMAT, 'blocks' );
		return in_array( $format, array( 'blocks', 'classic' ), true ) ? $format : 'blocks';
	}

	/**
	 * @return int Default author user id (0 = the acting user).
	 */
	public function get_content_author() {
		return max( 0, (int) get_option( self::OPT_CONTENT_AUTHOR, 0 ) );
	}

	/**
	 * @return bool Whether to emit Article JSON-LD for published articles.
	 */
	public function is_article_schema() {
		return 'yes' === get_option( self::OPT_ARTICLE_SCHEMA, 'yes' );
	}

	/* --------------------------------------------------------------------- */
	/* Crawler tracking prefs (4.0)                                          */
	/* --------------------------------------------------------------------- */

	/**
	 * @return bool Master toggle for the native AI crawler tracker.
	 */
	public function is_bots_enabled() {
		return 'yes' === get_option( self::OPT_BOTS_ENABLED, 'yes' );
	}

	/**
	 * @return bool Whether per-page paths are stored (else one row per bot per day).
	 */
	public function is_bots_paths() {
		return 'yes' === get_option( self::OPT_BOTS_PATHS, 'yes' );
	}

	/**
	 * @return int Retention window in days (30|90|180).
	 */
	public function get_bots_retention() {
		$days = (int) get_option( self::OPT_BOTS_RETENTION, 90 );
		return in_array( $days, array( 30, 90, 180 ), true ) ? $days : 90;
	}

	/**
	 * @return bool Whether daily aggregates are reported back to Rezolve Ai (opt-in).
	 */
	public function is_bots_report() {
		return 'yes' === get_option( self::OPT_BOTS_REPORT, 'no' );
	}

	/**
	 * Whether the storefront pageview beacon runs at all. Local counting only;
	 * nothing leaves the site unless is_traffic_report() is also on.
	 *
	 * @return bool
	 */
	public function is_traffic_enabled() {
		return 'yes' === get_option( self::OPT_TRAFFIC_ENABLED, 'no' );
	}

	/**
	 * Whether per-visitor A/B testing runs on the storefront.
	 *
	 * DEFAULT NO, AND THAT IS LOAD-BEARING. Every other tracker in this plugin
	 * is aggregate-only and needs no consent mechanism. This one sets a cookie
	 * and records a per-visitor row, so switching it on changes what the site
	 * collects. An upgrade must never do that on a site owner's behalf.
	 *
	 * @return bool
	 */
	public function is_ab_enabled() {
		return 'yes' === get_option( self::OPT_AB_ENABLED, 'no' );
	}

	/**
	 * Whether A/B exposures are reported to Rezolve Ai.
	 *
	 * Its own switch, separate from running the test, for the same reason the
	 * traffic tracker splits collecting from reporting: a site owner may be
	 * willing to split their own traffic and not willing to send the result
	 * anywhere, and folding the two under one checkbox denies them that.
	 *
	 * @return bool
	 */
	public function is_ab_report_enabled() {
		return 'yes' === get_option( self::OPT_AB_REPORT, 'no' );
	}

	/**
	 * CSS selector for the block holding the product description.
	 *
	 * Empty means "use whatever Rezolve Ai sent", which is the Storefront default.
	 * Themes name this block differently and a selector that matches nothing
	 * means the script swaps nothing: the test runs for four weeks, both arms
	 * read identical words, and it reports no difference with no error anywhere
	 * to explain it. That is the failure this setting exists to prevent.
	 *
	 * @return string
	 */
	public function ab_selector() {
		$value = (string) get_option( self::OPT_AB_SELECTOR, '' );
		$value = trim( $value );
		// Bounded and character-limited: it is interpolated into a JSON config
		// that ends up in a page, and a selector is a short string of a known
		// shape, never markup.
		if ( '' === $value || strlen( $value ) > 120 || preg_match( '/[<>"\']/', $value ) ) {
			return '';
		}
		return $value;
	}

	/**
	 * Whether day-level pageview and sales aggregates are sent to Rezolve Ai.
	 * Default off, and its own checkbox: see the constant above.
	 *
	 * @return bool
	 */
	public function is_traffic_report() {
		return 'yes' === get_option( self::OPT_TRAFFIC_REPORT, 'no' );
	}

	/**
	 * Generic boolean writer used by the settings form.
	 *
	 * @param string $option Option key.
	 * @param mixed  $value  Raw value (truthy or 'yes').
	 */
	public function set_bool( $option, $value ) {
		$yes = ( 'yes' === $value || true === $value || '1' === (string) $value || 'on' === $value );
		update_option( $option, $yes ? 'yes' : 'no' );
	}
}
