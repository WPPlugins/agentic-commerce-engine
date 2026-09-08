<?php
/**
 * Universal Commerce Protocol surface for this store.
 *
 * Two routes, neither of which Rezolve Ai can serve from its own domain:
 *
 * 1. `/.well-known/ucp` — the business profile. Shopping agents look for it on
 *    the MERCHANT's origin, so it has to be served here. Rezolve Ai builds the
 *    document; this plugin caches and republishes it.
 * 2. `/ucp/buy/{items}` — the permalink handoff. UCP's compact item path is
 *    `{id}:{qty}` comma separated, which Shopify's cart URL already speaks and
 *    WooCommerce does not, so this translates one into the other.
 *
 * @package Agencoen
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Ucp
 */
class Ucp {

	/** Option holding the UCP token Rezolve Ai issued for this project. */
	const OPT_TOKEN = 'agencoen_ucp_token';

	/** Transient holding the profile document fetched from Rezolve Ai. */
	const TRANSIENT_PROFILE = 'agencoen_ucp_profile';

	/**
	 * Profile cache lifetime.
	 *
	 * The spec requires a published profile to be cacheable with `max-age` of
	 * at least 60 seconds and forbids `private`, `no-store` and `no-cache`. An
	 * hour matches what Rezolve Ai serves.
	 */
	const PROFILE_TTL = HOUR_IN_SECONDS;

	/** Query var carrying the compact item path. */
	const QV_ITEMS = 'agencoen_ucp_items';

	/** Query var carrying the `continue_to` destination preference. */
	const QV_CONTINUE = 'agencoen_ucp_continue';

	/** @var Plugin */
	private $plugin;

	/**
	 * @param Plugin $plugin Orchestrator.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Hook rewrite rules and the request handler.
	 */
	public function register() {
		add_action( 'init', array( $this, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'maybe_handle' ) );
	}

	/**
	 * Register both routes.
	 *
	 * Rewrites rather than the REST API on purpose: `/.well-known/ucp` is a
	 * fixed path the protocol names, and the REST API cannot serve it.
	 */
	public function add_rewrite_rules() {
		add_rewrite_rule( '^\.well-known/ucp/?$', 'index.php?agencoen_ucp=profile', 'top' );
		add_rewrite_rule( '^ucp/buy/?$', 'index.php?agencoen_ucp=buy', 'top' );
		add_rewrite_rule(
			'^ucp/buy/(.+)/?$',
			'index.php?agencoen_ucp=buy&' . self::QV_ITEMS . '=$matches[1]',
			'top'
		);

		// `continue_to` arrives as a query parameter on the permalink. Mapping
		// it to a registered query var rather than reading $_GET keeps this a
		// routed read, with no nonce sniff to suppress: the plugin bans those
		// suppressions outright, because a WP.org reviewer reads one as hiding
		// the finding they raised.
		add_filter( 'request', array( $this, 'map_continue_to' ) );
	}

	/**
	 * @param array $vars Registered query vars.
	 * @return array
	 */
	public function add_query_vars( $vars ) {
		$vars[] = 'agencoen_ucp';
		$vars[] = self::QV_ITEMS;
		$vars[] = self::QV_CONTINUE;
		// The permalink spec names this parameter, so it has to be registered
		// under its own name for WordPress to parse it at all. `map_continue_to`
		// then sanitises it into our namespaced var.
		$vars[] = 'continue_to';
		return $vars;
	}

	/**
	 * Dispatch, if this request is ours.
	 */
	public function maybe_handle() {
		$route = get_query_var( 'agencoen_ucp' );
		if ( 'profile' === $route ) {
			$this->serve_profile();
		} elseif ( 'buy' === $route ) {
			$this->handle_buy();
		}
	}

	/**
	 * Serve the business profile.
	 */
	private function serve_profile() {
		$profile = $this->get_profile();

		if ( null === $profile ) {
			// A 404 is the honest answer when the store is not connected or Rezolve Ai
			// is unreachable. Serving a stub profile would be worse: an agent
			// would negotiate against capabilities that do not exist.
			status_header( 404 );
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Cache-Control: no-store' );
			echo wp_json_encode( array( 'error' => 'No UCP profile is published for this store.' ) );
			exit;
		}

		status_header( 200 );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: public, max-age=' . self::PROFILE_TTL );
		header( 'X-Robots-Tag: noindex' );
		echo $profile; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Verified JSON from Rezolve Ai, echoed byte-for-byte so the document stays valid.
		exit;
	}

	/**
	 * The profile document, cached.
	 *
	 * @return string|null Raw JSON, or null when unavailable.
	 */
	private function get_profile() {
		$cached = get_transient( self::TRANSIENT_PROFILE );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$url = $this->profile_url();
		if ( ! $url ) {
			return null;
		}

		$response = wp_remote_get( $url, array( 'timeout' => 10 ) );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );
		// Validated before it is cached and republished. Serving something that
		// is not a UCP profile under this path is worse than serving nothing.
		if ( ! is_array( $decoded ) || ! isset( $decoded['ucp']['version'] ) ) {
			return null;
		}

		set_transient( self::TRANSIENT_PROFILE, $body, self::PROFILE_TTL );
		return $body;
	}

	/**
	 * Where Rezolve Ai serves this store's profile.
	 *
	 * @return string|null
	 */
	private function profile_url() {
		$token = (string) get_option( self::OPT_TOKEN, '' );
		if ( '' === $token ) {
			return null;
		}
		$base = $this->plugin->settings()->get_base_url();
		if ( '' === $base ) {
			return null;
		}
		return $base . '/api/ucp/s/' . rawurlencode( $token ) . '/profile';
	}

	/**
	 * Store the UCP token Rezolve Ai issued for this project, and drop any cached
	 * profile so the next request refetches against the new token.
	 *
	 * @param string $token Opaque token from the Rezolve Ai UCP hub.
	 */
	public static function set_token( $token ) {
		update_option( self::OPT_TOKEN, sanitize_text_field( $token ), false );
		self::flush_profile_cache();
	}

	/**
	 * Drop the cached profile, so the next request refetches it.
	 */
	public static function flush_profile_cache() {
		delete_transient( self::TRANSIENT_PROFILE );
	}

	/**
	 * Turn a UCP compact item path into a WooCommerce cart.
	 *
	 * `/ucp/buy/44:1,55:2` adds two products and redirects to the cart. This is
	 * the only reason the route exists: Woo's add-to-cart is a query parameter
	 * and handles one product at a time, so there is nothing native to point
	 * `dev.ucp.shopping.permalink` at.
	 */
	private function handle_buy() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		$items = self::parse_items( (string) get_query_var( self::QV_ITEMS ) );
		foreach ( $items as $item ) {
			// Loading a permalink is explicitly NOT authorisation to place an
			// order. It only ever fills a cart; the buyer completes checkout.
			$product = wc_get_product( $item['id'] );

			// From 4.13 the catalogue publishes one UCP variant per WooCommerce
			// VARIATION, so the id in a buy link is usually a variation id.
			// Resolved explicitly here, with the parent and the chosen
			// attributes, rather than relying on WooCommerce reading our intent
			// from a variation id passed in the product position.
			if ( $product && $product->is_type( 'variation' ) ) {
				WC()->cart->add_to_cart(
					$product->get_parent_id(),
					$item['quantity'],
					$product->get_id(),
					$product->get_variation_attributes()
				);
				continue;
			}

			WC()->cart->add_to_cart( $item['id'], $item['quantity'] );
		}

		$destination = wc_get_cart_url();
		if ( 'checkout' === get_query_var( self::QV_CONTINUE ) ) {
			$destination = wc_get_checkout_url();
		}

		wp_safe_redirect( $destination );
		exit;
	}

	/**
	 * Copy `continue_to` off the request into our own query var.
	 *
	 * WordPress hands `request` the parsed query for this request, so nothing
	 * here touches the superglobal directly.
	 *
	 * @param array $vars Parsed request vars.
	 * @return array
	 */
	public function map_continue_to( $vars ) {
		if ( isset( $vars['continue_to'] ) ) {
			$vars[ self::QV_CONTINUE ] = sanitize_key( (string) $vars['continue_to'] );
		}
		return $vars;
	}

	/**
	 * Parse `id:qty,id:qty`.
	 *
	 * Pure and public so the test runner can drive it directly: it parses
	 * UNTRUSTED input straight off a URL, which is the part worth pinning.
	 *
	 * @param string $path Compact item path.
	 * @return array<int,array{id:int,quantity:int}>
	 */
	public static function parse_items( $path ) {
		$items = array();
		if ( '' === $path ) {
			return $items;
		}

		foreach ( explode( ',', $path ) as $pair ) {
			$parts = explode( ':', $pair, 2 );
			$id    = absint( $parts[0] );
			if ( ! $id ) {
				continue;
			}
			// The grammar is a positive base-10 integer; anything else is a
			// malformed link, and defaulting to one is friendlier than dropping
			// the item the buyer asked for.
			$quantity = isset( $parts[1] ) ? absint( $parts[1] ) : 1;
			$items[]  = array(
				'id'       => $id,
				'quantity' => max( 1, $quantity ),
			);
		}
		return $items;
	}
}
