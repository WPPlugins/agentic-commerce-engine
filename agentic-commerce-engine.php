<?php
/**
 * Plugin Name:       Agentic Commerce Engine
 * Plugin URI:        https://ace.authoritas.com
 * Description:       Free AI crawler analytics, schema output and publishing tools for any WordPress site. Article generation and AI search research additionally use the ACE service.
 * Version:           4.7.0
 * Author:            Authoritas
 * Author URI:        https://authoritas.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       agentic-commerce-engine
 * Domain Path:       /languages
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * WC requires at least: 7.0
 * WC tested up to:   10.9
 *
 * WooCommerce is OPTIONAL since 4.0.0: on a plain WordPress site the Content,
 * AI Search, and Crawler analytics screens work fully; the commerce suite
 * (sync, enrich, review, feeds, product schema) is available when WooCommerce is
 * active.
 *
 * @package Agencoen
 */

defined( 'ABSPATH' ) || exit;

/*
 * -------------------------------------------------------------------------
 * Constants
 * -------------------------------------------------------------------------
 */
define( 'AGENCOEN_VERSION', '4.7.0' );
define( 'AGENCOEN_PLUGIN_FILE', __FILE__ );
define( 'AGENCOEN_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AGENCOEN_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AGENCOEN_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

if ( ! defined( 'AGENCOEN_DEFAULT_API_URL' ) ) {
	define( 'AGENCOEN_DEFAULT_API_URL', 'https://ace.authoritas.com' );
}

/*
 * -------------------------------------------------------------------------
 * Autoloader
 *
 * Maps Agencoen\Some_Class  ->  includes/class-some-class.php
 * -------------------------------------------------------------------------
 */
spl_autoload_register(
	function ( $class ) {
		if ( strpos( $class, 'Agencoen\\' ) !== 0 ) {
			return;
		}
		$relative = substr( $class, strlen( 'Agencoen\\' ) );
		$relative = strtolower( str_replace( '_', '-', $relative ) );
		$file     = AGENCOEN_PLUGIN_DIR . 'includes/class-' . $relative . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

/*
 * -------------------------------------------------------------------------
 * High-Performance Order Storage (HPOS) compatibility
 * (The hook only fires when WooCommerce is active; harmless otherwise.)
 * -------------------------------------------------------------------------
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				AGENCOEN_PLUGIN_FILE,
				true
			);
		}
	}
);

/*
 * -------------------------------------------------------------------------
 * Requirement checks + boot
 * -------------------------------------------------------------------------
 */

/**
 * Determine whether the plugin's requirements are met.
 *
 * PHP 8.0 is the only hard requirement since 4.0.0; WooCommerce gates the
 * commerce screens at runtime instead of blocking activation.
 *
 * @return string Empty string when OK, otherwise a human-readable reason.
 */
function agencoen_requirements_error() {
	if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
		return sprintf(
			/* translators: %s: current PHP version. */
			__( 'Agentic Commerce Engine requires PHP 8.0 or higher. You are running %s.', 'agentic-commerce-engine' ),
			PHP_VERSION
		);
	}
	return '';
}

/**
 * Boot the plugin once all plugins (including WooCommerce, if any) are loaded.
 */
function agencoen_boot() {
	$error = agencoen_requirements_error();
	if ( '' !== $error ) {
		add_action(
			'admin_notices',
			function () use ( $error ) {
				echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
			}
		);
		return;
	}

	\Agencoen\Plugin::instance();
}
add_action( 'plugins_loaded', 'agencoen_boot', 20 );

/*
 * -------------------------------------------------------------------------
 * Activation / Deactivation
 * -------------------------------------------------------------------------
 */
register_activation_hook(
	__FILE__,
	function () {
		require_once AGENCOEN_PLUGIN_DIR . 'includes/class-settings.php';
		require_once AGENCOEN_PLUGIN_DIR . 'includes/class-db.php';
		\Agencoen\Settings::set_activation_defaults();
		\Agencoen\Db::install();

		// Schedule the recurring heartbeat.
		if ( ! wp_next_scheduled( 'agencoen_heartbeat' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'agencoen_heartbeat' );
		}
		// Daily crawler-log maintenance (prune + optional report-back).
		if ( ! wp_next_scheduled( 'agencoen_bot_prune' ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'agencoen_bot_prune' );
		}
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		// Clear every recurring/queued event this plugin owns.
		$hooks = array(
			'agencoen_heartbeat',
			'agencoen_scheduled_sync',
			'agencoen_sync_continue',
			'agencoen_auto_apply_sweep',
			'agencoen_auto_apply_drain',
			'agencoen_poll_enrichment',
			'agencoen_bot_prune',
			'agencoen_bot_report',
			'agencoen_content_poll',
		);
		foreach ( $hooks as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}
);
