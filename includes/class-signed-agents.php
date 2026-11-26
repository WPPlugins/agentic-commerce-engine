<?php
/**
 * Signed AI agent requests (Web Bot Auth).
 *
 * Some AI agents sign every request they make: ChatGPT's agent, part of
 * Google's, and agents paying with Visa or Mastercard. The signature is in
 * three request headers (Signature-Agent, Signature-Input, Signature) and can
 * be checked against keys the agent publishes, which is the only reliable way
 * to tell a real agent from anything that copies its name.
 *
 * This class keeps such a request exactly as it arrived, because a signature
 * covers exact bytes and any cleaning would break it: the method, host, path,
 * query, the three headers and any other header the signature covers. It is
 * validated instead of cleaned: printable ASCII only, bounded in length, and
 * never a Cookie or Authorization header. When the site owner has opted in to
 * the daily crawler report, Rezolve Ai receives them and checks each
 * signature. No IP address, user agent or cookie is kept or sent.
 *
 * At most CAP_PER_DAY are kept a day and CAP_PENDING in all, so a flood of
 * signed requests costs one small option, not a table.
 *
 * @package Agencoen
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Signed_Agents
 */
class Signed_Agents {

	const OPTION        = 'agencoen_signed_agent_samples';
	const CAP_PER_DAY   = 200;
	const CAP_PENDING   = 500;
	const MAX_HEADER    = 4000;
	const MAX_HEADERS   = 20;
	const MAX_PATH      = 2000;
	const REPORT_BATCH  = 200;

	/** Headers a signature may cover that are never kept, whatever the agent signed. */
	const NEVER_KEPT = array( 'cookie', 'authorization', 'proxy-authorization' );

	/**
	 * A header value safe to keep as is: printable ASCII, no line breaks, bounded.
	 *
	 * @param mixed $value Raw value.
	 * @return string|null The value, or null when it may not be kept.
	 */
	public static function clean_value( $value ) {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > self::MAX_HEADER ) {
			return null;
		}
		return preg_match( '/^[\x20-\x7E]*$/', $value ) ? $value : null;
	}

	/**
	 * The request as a sample to verify, or null when it carries no Web Bot Auth signature
	 * or anything it covers cannot be kept.
	 *
	 * @param array  $server   $_SERVER, unslashed.
	 * @param bool   $is_ssl   Whether the request came over HTTPS.
	 * @param string $observed ISO 8601 time the request arrived.
	 * @return array|null
	 */
	public static function from_server( array $server, $is_ssl, $observed ) {
		$input = self::clean_value( isset( $server['HTTP_SIGNATURE_INPUT'] ) ? $server['HTTP_SIGNATURE_INPUT'] : null );
		$sig   = self::clean_value( isset( $server['HTTP_SIGNATURE'] ) ? $server['HTTP_SIGNATURE'] : null );
		if ( null === $input || null === $sig || false === strpos( $input, 'web-bot-auth' ) ) {
			return null;
		}

		$headers = array(
			'signature-input' => $input,
			'signature'       => $sig,
		);
		$agent = self::clean_value( isset( $server['HTTP_SIGNATURE_AGENT'] ) ? $server['HTTP_SIGNATURE_AGENT'] : null );
		if ( null !== $agent ) {
			$headers['signature-agent'] = $agent;
		}

		// Every other header the signature covers, by name, so the base can be rebuilt.
		preg_match_all( '/"([A-Za-z0-9-]+)"/', $input, $names );
		foreach ( array_unique( array_map( 'strtolower', $names[1] ) ) as $name ) {
			if ( isset( $headers[ $name ] ) || count( $headers ) >= self::MAX_HEADERS ) {
				continue;
			}
			if ( in_array( $name, self::NEVER_KEPT, true ) ) {
				return null;
			}
			$key   = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
			$value = isset( $server[ $key ] ) ? self::clean_value( $server[ $key ] ) : null;
			if ( null !== $value ) {
				$headers[ $name ] = $value;
			}
		}

		$host = self::clean_value( isset( $server['HTTP_HOST'] ) ? $server['HTTP_HOST'] : null );
		$uri  = isset( $server['REQUEST_URI'] ) && is_string( $server['REQUEST_URI'] ) ? $server['REQUEST_URI'] : '/';
		if ( null === $host || strlen( $uri ) > self::MAX_PATH || ! preg_match( '/^[\x20-\x7E]*$/', $uri ) ) {
			return null;
		}
		$q_at  = strpos( $uri, '?' );
		$path  = false === $q_at ? $uri : substr( $uri, 0, $q_at );
		$query = false === $q_at ? '' : substr( $uri, $q_at + 1 );

		return array(
			'observedAt' => $observed,
			'method'     => strtoupper( isset( $server['REQUEST_METHOD'] ) && is_string( $server['REQUEST_METHOD'] ) ? substr( $server['REQUEST_METHOD'], 0, 10 ) : 'GET' ),
			'scheme'     => $is_ssl ? 'https' : 'http',
			'authority'  => strtolower( $host ),
			'path'       => '' === $path ? '/' : $path,
			'query'      => $query,
			'headers'    => $headers,
		);
	}

	/**
	 * Add a sample to the pending queue, within the caps.
	 *
	 * @param array  $queue  { day, count, samples }.
	 * @param array  $sample From from_server().
	 * @param string $day    Y-m-d today.
	 * @return array The queue.
	 */
	public static function enqueue( array $queue, array $sample, $day ) {
		$samples = isset( $queue['samples'] ) && is_array( $queue['samples'] ) ? $queue['samples'] : array();
		$count   = ( isset( $queue['day'] ) && $queue['day'] === $day && isset( $queue['count'] ) ) ? (int) $queue['count'] : 0;
		if ( $count >= self::CAP_PER_DAY || count( $samples ) >= self::CAP_PENDING ) {
			return array(
				'day'     => $day,
				'count'   => $count,
				'samples' => $samples,
			);
		}
		$samples[] = $sample;
		return array(
			'day'     => $day,
			'count'   => $count + 1,
			'samples' => $samples,
		);
	}

	/**
	 * Keep the current request when it is signed. Called on front-end requests.
	 */
	public static function capture() {
		// The raw values are checked by clean_value(): a signature covers exact bytes, so they cannot be sanitized.
		$server = wp_unslash( $_SERVER ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated byte for byte in from_server(); a signed header is useless once altered.
		$sample = self::from_server( is_array( $server ) ? $server : array(), is_ssl(), gmdate( 'c' ) );
		if ( null === $sample ) {
			return;
		}
		$queue = get_option( self::OPTION, array() );
		update_option( self::OPTION, self::enqueue( is_array( $queue ) ? $queue : array(), $sample, gmdate( 'Y-m-d' ) ), false );
	}

	/**
	 * Send the pending samples to Rezolve Ai, which checks each signature. Kept until sent.
	 *
	 * @param Plugin $plugin Orchestrator.
	 * @return true|\WP_Error
	 */
	public static function report( Plugin $plugin ) {
		$queue   = get_option( self::OPTION, array() );
		$samples = is_array( $queue ) && isset( $queue['samples'] ) && is_array( $queue['samples'] ) ? $queue['samples'] : array();
		if ( empty( $samples ) ) {
			return true;
		}
		$store_id = $plugin->settings()->get_store_id();
		foreach ( array_chunk( $samples, self::REPORT_BATCH ) as $batch ) {
			$result = $plugin->api()->signed_agents_ingest( $store_id, $batch );
			if ( is_wp_error( $result ) ) {
				$plugin->logger()->error( 'Signed agent report to Rezolve Ai failed: ' . $result->get_error_message() );
				return $result;
			}
			$samples = array_slice( $samples, count( $batch ) );
			$queue['samples'] = $samples;
			update_option( self::OPTION, $queue, false );
		}
		return true;
	}
}
