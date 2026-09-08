<?php
/**
 * Thin logging wrapper around the WooCommerce logger, gated on the debug toggle.
 *
 * @package Agencoen
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Logger
 */
class Logger {

	const SOURCE = 'agentic-commerce-engine';

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Write a log line when debug logging is enabled.
	 *
	 * @param string $message Message.
	 * @param string $level   emergency|alert|critical|error|warning|notice|info|debug.
	 */
	public function log( $message, $level = 'info' ) {
		if ( ! $this->settings->is_debug_log() ) {
			return;
		}
		$line = self::redact( (string) $message );

		// WooCommerce logger when available; plain error_log on Woo-less sites.
		if ( function_exists( 'wc_get_logger' ) ) {
			$logger = wc_get_logger();
			if ( $logger ) {
				$logger->log( $level, $line, array( 'source' => self::SOURCE ) );
				return;
			}
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- opt-in debug logging only.
		error_log( '[agencoen][' . $level . '] ' . $line );
	}

	/**
	 * @param string $message Message.
	 */
	public function error( $message ) {
		$this->log( $message, 'error' );
	}

	/**
	 * @param string $message Message.
	 */
	public function info( $message ) {
		$this->log( $message, 'info' );
	}

	/**
	 * Remove anything that looks like a Rezolve Ai API key from a log line.
	 *
	 * @param string $message Message.
	 * @return string
	 */
	public static function redact( $message ) {
		return preg_replace( '/ace_[A-Za-z0-9_\-]{6,}/', 'ace_***', $message );
	}
}
