<?php
/**
 * The store's policy pages, reported to Rezolve Ai for the seller columns of
 * its OpenAI product file (`return_policy`, `seller_privacy_policy`,
 * `seller_tos`). Sent to POST /api/v1/plugin/legal-policies from the hourly
 * heartbeat (4.34.0).
 *
 * WHAT IS A PAGE. Each column names one page the site itself points at:
 *
 *   - privacy: WordPress's own privacy policy page (Settings, Privacy), read
 *     through get_privacy_policy_url(), which already requires it published;
 *   - terms: WooCommerce's terms and conditions page (WooCommerce, Settings,
 *     Advanced, Page setup), through wc_terms_and_conditions_page_id();
 *   - returns: WooCommerce's refund and returns page, the option
 *     `woocommerce_refund_returns_page_id`.
 *
 * A page counts only when it is published, not password protected, and has
 * text in it. WooCommerce and WordPress both create these pages as DRAFTS of
 * template text, and a draft or an empty page states nothing a shopper could
 * read at that address. Only the exact page fills a column: a terms page is
 * not a returns page, however it is titled.
 *
 * WHAT IS A RETURN TERM. `acceptsReturns` is always null. WooCommerce has no
 * setting that states whether a store takes returns; its returns page is prose.
 * Reading "no returns" out of that prose would be inferring, and a row of the
 * file speaks for one product. The platform refuses `true` for the same
 * reason. Should WooCommerce ever state it, this is the one place to read it.
 *
 * WHEN. No hook reports a change to a page's text, so it rides the heartbeat.
 * The same report is not sent again within REPORT_TTL; a changed one goes at
 * once. The platform stops stating a value a week after it arrived, so a site
 * whose heartbeat stops does not keep stating old pages. A failed send changes
 * nothing here and is tried again on the next heartbeat.
 *
 * @package Agencoen
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Legal_Policies
 */
class Legal_Policies {

	/** What was last reported, and when: { hash, at }. */
	const OPT_SENT = 'agencoen_legal_policies_sent';

	/** An unchanged report is sent again after this long. Well inside the platform's week. */
	const REPORT_TTL = DAY_IN_SECONDS;

	/**
	 * Whether a page's content states anything once markup is gone. Block
	 * comments, tags and non-breaking spaces alone are an empty page.
	 *
	 * @param string $content Raw post content.
	 * @return bool
	 */
	public static function has_text( $content ) {
		$text = preg_replace( '/<[^>]*>/', ' ', (string) $content );
		$text = str_replace( array( '&nbsp;', "\xC2\xA0" ), ' ', (string) $text );
		return '' !== trim( $text );
	}

	/**
	 * An http(s) URL, or null.
	 *
	 * @param mixed $url Candidate.
	 * @return string|null
	 */
	public static function http_url( $url ) {
		if ( ! is_string( $url ) ) {
			return null;
		}
		$url = trim( $url );
		return ( '' !== $url && strlen( $url ) <= 2000 && preg_match( '#^https?://#i', $url ) ) ? $url : null;
	}

	/**
	 * Whether a page stated by id can be pointed at: published, open to
	 * everyone, and with text in it.
	 *
	 * @param int $page_id Page id; 0 or less means none is set.
	 * @return bool
	 */
	public static function page_states_something( $page_id ) {
		$page_id = (int) $page_id;
		if ( $page_id <= 0 || 'publish' !== get_post_status( $page_id ) ) {
			return false;
		}
		$post = get_post( $page_id );
		if ( ! $post || '' !== (string) $post->post_password ) {
			return false;
		}
		return self::has_text( $post->post_content );
	}

	/**
	 * A page's public address, when it states something.
	 *
	 * @param int $page_id Page id.
	 * @return string|null
	 */
	public static function page_url( $page_id ) {
		return self::page_states_something( $page_id ) ? self::http_url( get_permalink( (int) $page_id ) ) : null;
	}

	/**
	 * The privacy policy address. get_privacy_policy_url() answers '' unless
	 * the page is published, and lets a privacy plugin point it elsewhere
	 * through its filter. The page WordPress holds must still have text.
	 *
	 * @return string|null
	 */
	public static function privacy_url() {
		if ( ! function_exists( 'get_privacy_policy_url' ) ) {
			return null;
		}
		$url = self::http_url( get_privacy_policy_url() );
		if ( null === $url ) {
			return null;
		}
		$page_id = (int) get_option( 'wp_page_for_privacy_policy', 0 );
		if ( $page_id > 0 && 'publish' === get_post_status( $page_id ) && ! self::page_states_something( $page_id ) ) {
			return null;
		}
		return $url;
	}

	/**
	 * WooCommerce's terms and conditions page id, or 0.
	 *
	 * @return int
	 */
	public static function terms_page_id() {
		if ( function_exists( 'wc_terms_and_conditions_page_id' ) ) {
			return (int) wc_terms_and_conditions_page_id();
		}
		return (int) get_option( 'woocommerce_terms_page_id', 0 );
	}

	/**
	 * What this site states, in the shape the platform route takes.
	 *
	 * @return array { returnPolicyUrl, privacyPolicyUrl, termsOfServiceUrl, acceptsReturns }
	 */
	public static function read() {
		return array(
			'returnPolicyUrl'   => self::page_url( (int) get_option( 'woocommerce_refund_returns_page_id', 0 ) ),
			'privacyPolicyUrl'  => self::privacy_url(),
			'termsOfServiceUrl' => self::page_url( self::terms_page_id() ),
			// Never inferred. See the header.
			'acceptsReturns'    => null,
		);
	}

	/**
	 * Whether a report is due: never sent, changed (store or pages), or older
	 * than REPORT_TTL.
	 *
	 * @param mixed  $sent     Stored OPT_SENT value.
	 * @param string $hash     Hash of what would be sent now.
	 * @param int    $now      Unix time.
	 * @return bool
	 */
	public static function is_due( $sent, $hash, $now ) {
		if ( ! is_array( $sent ) || ! isset( $sent['hash'], $sent['at'] ) || $sent['hash'] !== $hash ) {
			return true;
		}
		return ( (int) $now - (int) $sent['at'] ) >= self::REPORT_TTL;
	}

	/**
	 * Hash of one report, the store id included, so a reconnect to another
	 * store reports again at once.
	 *
	 * @param string $store_id Store id.
	 * @param array  $policies From read().
	 * @return string
	 */
	public static function hash( $store_id, array $policies ) {
		return md5( (string) $store_id . '|' . wp_json_encode( $policies ) );
	}

	/**
	 * Report this site's pages when due. Never throws: it runs inside the
	 * heartbeat, and a failure leaves the platform's last value standing.
	 *
	 * @param Plugin $plugin Orchestrator (untyped so the tests can pass a stand-in; Plugin is final).
	 * @param int    $now    Unix time (tests pass one).
	 * @return bool|null True when sent, false when it failed, null when not due.
	 */
	public static function report( $plugin, $now = null ) {
		$settings = $plugin->settings();
		if ( ! $settings->is_connected() ) {
			return null;
		}
		$now      = null === $now ? time() : (int) $now;
		$store_id = $settings->get_store_id();
		$policies = self::read();
		$hash     = self::hash( $store_id, $policies );
		if ( ! self::is_due( get_option( self::OPT_SENT, null ), $hash, $now ) ) {
			return null;
		}

		$result = $plugin->api()->legal_policies( $store_id, $policies );
		if ( is_wp_error( $result ) ) {
			$plugin->logger()->error( 'Could not report the store\'s policy pages: ' . $result->get_error_message() );
			return false;
		}
		update_option(
			self::OPT_SENT,
			array(
				'hash' => $hash,
				'at'   => $now,
			),
			false
		);
		return true;
	}
}
