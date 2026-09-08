<?php
/**
 * Serves the generated channel feeds (Google, Microsoft, Agentic Commerce) from
 * the per-product records the platform produced and the plugin stored on apply.
 *
 * @package Agencoen
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Feeds
 */
class Feeds {

	const NAMESPACE_V1 = 'agencoen/v1';

	/** Products fetched (and flushed) per streaming batch. No total cap. */
	const BATCH_SIZE = 500;

	/** Option holding the unix time feed records last changed (for 304s). */
	const OPT_MODIFIED = 'agencoen_feed_modified';

	const CHANNELS = array(
		'google'    => 'google',
		'microsoft' => 'microsoft',
		'agentic'   => 'agentic',
	);

	/** @var Plugin */
	private $plugin;

	/**
	 * @param Plugin $plugin Orchestrator.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Hook REST routes.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Tokened feed URL for a channel. Anyone with the URL can read the feed,
	 * so it is only handed out on the Settings screen.
	 *
	 * @param string $channel google|microsoft|agentic.
	 * @return string
	 */
	public static function feed_url( $channel ) {
		return add_query_arg(
			'token',
			rawurlencode( Settings::get_feed_token() ),
			rest_url( self::NAMESPACE_V1 . '/feed/' . $channel )
		);
	}

	/**
	 * Register the three feed routes (read-only, feed-token gated).
	 */
	public function register_routes() {
		foreach ( array_keys( self::CHANNELS ) as $channel ) {
			register_rest_route(
				self::NAMESPACE_V1,
				'/feed/' . $channel,
				array(
					'methods'             => 'GET',
					'permission_callback' => array( $this, 'check_permission' ),
					'callback'            => function () use ( $channel ) {
						return $this->serve( $channel );
					},
					'args'                => array(
						'token' => array(
							'type'              => 'string',
							'description'       => __( 'Per-site feed token from the Rezolve Ai Settings screen.', 'agentic-commerce-engine' ),
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				)
			);
		}
	}

	/**
	 * Feed-token gate for the public feed routes. Runs before args validation,
	 * so a bad or missing token never reaches the streaming callback.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function check_permission( $request ) {
		if ( self::token_matches( Settings::get_feed_token(), $request->get_param( 'token' ) ) ) {
			return true;
		}
		return new \WP_Error(
			'agencoen_feed_forbidden',
			__( 'Invalid or missing feed token. Copy the feed URL from the Rezolve Ai Settings screen.', 'agentic-commerce-engine' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Constant-time token comparison. Pure.
	 *
	 * @param string $expected Stored token.
	 * @param mixed  $given    token= query value.
	 * @return bool
	 */
	public static function token_matches( $expected, $given ) {
		if ( ! is_string( $expected ) || '' === $expected || ! is_string( $given ) || '' === $given ) {
			return false;
		}
		return hash_equals( $expected, $given );
	}

	/**
	 * Stamp the feed-modified time (call whenever feed records change).
	 *
	 * @return void
	 */
	public static function touch() {
		update_option( self::OPT_MODIFIED, time(), false );
	}

	/**
	 * Serve a channel feed (XML for google/microsoft, JSON for agentic).
	 *
	 * Streams the document in {@see self::BATCH_SIZE}-product batches with a
	 * flush() after each, so a 10k+ catalog never builds the whole feed in
	 * memory and is never silently truncated.
	 *
	 * @param string $channel Channel key.
	 * @return void
	 */
	private function serve( $channel ) {
		// Conditional GET: Google/Microsoft fetch daily with If-Modified-Since;
		// an unchanged catalog costs one option read instead of a full stream.
		$modified = (int) get_option( self::OPT_MODIFIED, 0 );
		if ( $modified > 0 && isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) {
			$since = strtotime( sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) );
			if ( $since && $since >= $modified ) {
				status_header( 304 );
				exit;
			}
		}

		status_header( 200 );
		if ( $modified > 0 ) {
			header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $modified ) . ' GMT' );
		}
		// `private` keeps shared caches from storing tokened URLs; 15 minutes
		// of client-side reuse is harmless for daily fetchers.
		header( 'Cache-Control: private, max-age=900' );

		if ( 'agentic' === $channel ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			$first = true;
			self::emit(
				'{"feed":"agentic-commerce","store":' . wp_json_encode( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ) . ',"products":['
			);
			$this->stream_records(
				$channel,
				function ( $record ) use ( &$first ) {
					self::emit( ( $first ? '' : ',' ) . wp_json_encode( $record ) );
					$first = false;
				}
			);
			self::emit( ']}' );
			exit;
		}

		// Google / Microsoft → RSS 2.0 with the g: namespace.
		header( 'Content-Type: application/xml; charset=utf-8' );
		self::emit(
			'<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0"><channel>'
			. '<title>' . self::x( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ) . '</title>'
			. '<link>' . self::x( home_url() ) . '</link>'
			. '<description>' . self::x( sprintf( 'Rezolve Ai %s feed', $channel ) ) . '</description>'
		);
		$this->stream_records(
			$channel,
			function ( $record ) {
				$item = '<item>';
				foreach ( $record as $key => $value ) {
					if ( ! is_scalar( $value ) || '' === (string) $value ) {
						continue;
					}
					$tag   = 'g:' . preg_replace( '/[^a-z0-9_]/', '_', strtolower( (string) $key ) );
					$item .= '<' . $tag . '>' . self::x( (string) $value ) . '</' . $tag . '>';
				}
				$item .= '</item>';
				self::emit( $item );
			}
		);
		self::emit( '</channel></rss>' );
		exit;
	}

	/**
	 * Walk every product carrying a feed record for the channel, in batches,
	 * invoking $emit per record and flushing output after each batch.
	 *
	 * @param string   $channel Channel key.
	 * @param callable $emit    Receives each non-empty array record.
	 * @return void
	 */
	private function stream_records( $channel, $emit ) {
		$meta_key = Meta::FEED_PREFIX . $channel;
		$paged    = 1;

		do {
			$query = new \WP_Query(
				array(
					'post_type'              => 'product',
					'post_status'            => 'publish',
					'posts_per_page'         => self::BATCH_SIZE,
					'paged'                  => $paged,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'ignore_sticky_posts'    => true,
					'update_post_term_cache' => false,
					'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'     => $meta_key,
							'compare' => 'EXISTS',
						),
					),
				)
			);

			$ids = $query->posts;
			foreach ( $ids as $id ) {
				$record = get_post_meta( (int) $id, $meta_key, true );
				if ( is_array( $record ) && ! empty( $record ) ) {
					$emit( $record );
				}
			}

			// Release this batch's cached objects and push bytes to the client.
			wp_reset_postdata();
			self::flush();
			++$paged;
		} while ( count( $ids ) === self::BATCH_SIZE );
	}

	/**
	 * Echo a pre-escaped feed fragment. Centralizes the one escaping exemption:
	 * XML is encoded via {@see self::x()}, JSON via wp_json_encode().
	 *
	 * @param string $fragment Pre-escaped markup.
	 * @return void
	 */
	private static function emit( $fragment ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- caller pre-escapes (self::x for XML, wp_json_encode for JSON).
		echo $fragment;
	}

	/**
	 * Best-effort flush of any output buffers so the client receives the batch.
	 *
	 * @return void
	 */
	private static function flush() {
		while ( ob_get_level() > 0 ) {
			ob_end_flush();
		}
		flush();
	}

	/**
	 * XML-escape a value.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function x( $value ) {
		return htmlspecialchars( $value, ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}
}
