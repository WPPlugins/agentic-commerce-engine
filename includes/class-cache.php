<?php
/**
 * Transient cache with group-level invalidation.
 *
 * Keys are namespaced by a per-group version salt stored in one option, so a
 * "Refresh" button (or a data-changing action) can invalidate a whole group
 * with a single option write instead of enumerating transients. Groups keep
 * the plugin far inside the platform's per-key API rate limit.
 *
 * @package Agencoen
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Cache
 */
class Cache {

	const OPT_VERSIONS = 'agencoen_cache_versions';

	/**
	 * Read a cached value.
	 *
	 * @param string $group Cache group (e.g. 'aeo', 'content', 'bootstrap').
	 * @param string $key   Key within the group.
	 * @return mixed|false False on miss (mirrors get_transient).
	 */
	public function get( $group, $key ) {
		return get_transient( $this->name( $group, $key ) );
	}

	/**
	 * Store a value.
	 *
	 * @param string $group Cache group.
	 * @param string $key   Key within the group.
	 * @param mixed  $value Value (arrays fine; avoid objects).
	 * @param int    $ttl   TTL in seconds.
	 */
	public function set( $group, $key, $value, $ttl ) {
		set_transient( $this->name( $group, $key ), $value, max( 30, (int) $ttl ) );
	}

	/**
	 * Drop one entry.
	 *
	 * @param string $group Cache group.
	 * @param string $key   Key within the group.
	 */
	public function forget( $group, $key ) {
		delete_transient( $this->name( $group, $key ) );
	}

	/**
	 * Invalidate an entire group by bumping its version salt.
	 *
	 * @param string $group Cache group.
	 */
	public function bust( $group ) {
		$versions           = $this->versions();
		$versions[ $group ] = isset( $versions[ $group ] ) ? ( (int) $versions[ $group ] + 1 ) : 2;
		update_option( self::OPT_VERSIONS, $versions, false );
	}

	/**
	 * Fetch-or-compute helper with optional negative caching.
	 *
	 * When $error_ttl > 0 a WP_Error from the resolver is stored (as a plain
	 * sentinel array; transients should not hold objects) for that many
	 * seconds and rehydrated on later hits, so a down platform is re-tried at
	 * most once per window instead of on every call. With $error_ttl <= 0 the
	 * behavior is unchanged: errors are never cached.
	 *
	 * @param string   $group     Cache group.
	 * @param string   $key       Key within the group.
	 * @param int      $ttl       Success TTL seconds.
	 * @param callable $resolver  Returns the fresh value (may return WP_Error).
	 * @param int      $error_ttl Error TTL seconds (0 = do not cache errors).
	 * @return mixed
	 */
	public function remember( $group, $key, $ttl, callable $resolver, $error_ttl = 0 ) {
		$hit = $this->get( $group, $key );
		if ( false !== $hit ) {
			$error = self::unwrap_error( $hit );
			return null !== $error ? $error : $hit;
		}
		$value = $resolver();
		if ( is_wp_error( $value ) ) {
			if ( (int) $error_ttl > 0 ) {
				$this->set( $group, $key, self::wrap_error( $value ), (int) $error_ttl );
			}
		} elseif ( false !== $value && null !== $value ) {
			$this->set( $group, $key, $value, $ttl );
		}
		return $value;
	}

	/**
	 * WP_Error -> storable sentinel array.
	 *
	 * @param \WP_Error $error Error.
	 * @return array
	 */
	private static function wrap_error( $error ) {
		return array(
			'__agencoen_wp_error' => array(
				'code'    => (string) $error->get_error_code(),
				'message' => (string) $error->get_error_message(),
				'data'    => $error->get_error_data(),
			),
		);
	}

	/**
	 * Sentinel array -> WP_Error (null when the value is not a sentinel).
	 *
	 * @param mixed $value Cached value.
	 * @return \WP_Error|null
	 */
	private static function unwrap_error( $value ) {
		if ( ! is_array( $value ) || ! isset( $value['__agencoen_wp_error'] ) || ! is_array( $value['__agencoen_wp_error'] ) ) {
			return null;
		}
		$e = $value['__agencoen_wp_error'];
		return new \WP_Error(
			isset( $e['code'] ) ? $e['code'] : 'agencoen_cached_error',
			isset( $e['message'] ) ? $e['message'] : '',
			isset( $e['data'] ) ? $e['data'] : null
		);
	}

	/**
	 * Versioned transient name (capped well under the 172-char limit).
	 *
	 * @param string $group Cache group.
	 * @param string $key   Key.
	 * @return string
	 */
	private function name( $group, $key ) {
		$versions = $this->versions();
		$version  = isset( $versions[ $group ] ) ? (int) $versions[ $group ] : 1;
		return 'agencoen_' . $group . '_v' . $version . '_' . md5( $key );
	}

	/**
	 * @return array<string,int>
	 */
	private function versions() {
		$versions = get_option( self::OPT_VERSIONS, array() );
		return is_array( $versions ) ? $versions : array();
	}
}
