<?php
/**
 * Read-only brand identity + voice summary from the platform. Shown on the
 * Content screen ("Writing as <brand>"); editing stays in the ACE app's Brand
 * tab, which is the single source of truth.
 *
 * @package Agencoen
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Brand
 */
class Brand {

	const TTL = 12 * HOUR_IN_SECONDS;

	/** @var Plugin */
	private $plugin;

	/**
	 * @param Plugin $plugin Orchestrator.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Reduced brand payload for the UI, cached 12h. Null when unavailable.
	 *
	 * @param bool $fresh Force a refetch.
	 * @return array|null { name, logoUrl, hasVoice, voiceSummary, editUrl }
	 */
	public function summary( $fresh = false ) {
		if ( $fresh ) {
			$this->plugin->cache()->forget( 'content', 'brand' );
		}

		$payload = $this->plugin->cache()->remember(
			'content',
			'brand',
			self::TTL,
			function () {
				return $this->plugin->api()->brand( $this->plugin->settings()->get_store_id() );
			},
			3 * MINUTE_IN_SECONDS
		);

		if ( is_wp_error( $payload ) || ! is_array( $payload ) ) {
			return null;
		}

		$organization = array();
		if ( isset( $payload['identity']['organization'] ) && is_array( $payload['identity']['organization'] ) ) {
			$organization = $payload['identity']['organization'];
		}
		$voice = isset( $payload['voice'] ) && is_array( $payload['voice'] ) ? $payload['voice'] : array();

		return array(
			'name'         => isset( $organization['name'] ) ? (string) $organization['name'] : '',
			'logoUrl'      => isset( $organization['logoUrl'] ) ? (string) $organization['logoUrl'] : '',
			'hasVoice'     => ! empty( $voice['hasVoice'] ),
			'voiceSummary' => isset( $voice['summary'] ) ? (string) $voice['summary'] : '',
			'editUrl'      => $this->plugin->settings()->get_base_url() . '/settings/brand',
		);
	}

	/**
	 * Invalidate the content cache group (brand + content types).
	 */
	public function bust() {
		$this->plugin->cache()->bust( 'content' );
	}
}
