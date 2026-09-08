<?php
/**
 * Optional-account notice. Never shown on a fresh install: Admin::connect_notice_due()
 * waits until the local crawler tracker has recorded real AI traffic, so this
 * leads with value the plugin has already measured. Shown above a screen until
 * the current user dismisses it (per-user, so it is never a permanent site-wide nag).
 *
 * Additive chrome, never a substitute for the screen. Nothing the plugin does
 * locally depends on the account this points at.
 *
 * @package Agencoen
 * @var Plugin $plugin    Orchestrator.
 * @var bool   $connected Store connected.
 * @var bool   $has_woo   WooCommerce active.
 * @var array  $data      Dashboard payload.
 */

defined( 'ABSPATH' ) || exit;

$agencoen_notice_hits = isset( $data['bots']['total'] ) ? (int) $data['bots']['total'] : 0;
?>
<div class="ace-banner ace-banner-info ace-connect-banner" id="ace-connect-notice">
	<div>
		<strong>
			<?php
			printf(
				/* translators: %s: number of AI crawler visits recorded in the last 7 days. */
				esc_html( _n( '%s AI crawler visit tracked this week. Want to know what the AI engines say?', '%s AI crawler visits tracked this week. Want to know what the AI engines say?', $agencoen_notice_hits, 'agentic-commerce-engine' ) ),
				esc_html( number_format_i18n( $agencoen_notice_hits ) )
			);
			?>
		</strong>
		<p class="ace-help">
			<?php esc_html_e( 'The crawler analytics, schema output, and publishing tools you are using stay free and local either way. Connecting an optional Rezolve Ai account adds work this site cannot do by itself: querying the AI answer engines about your brand, writing articles, and generating product content on Rezolve Ai servers.', 'agentic-commerce-engine' ); ?>
		</p>
	</div>
	<div class="ace-connect-banner-actions">
		<a class="ace-btn ace-btn-primary ace-btn-sm" href="<?php echo esc_url( admin_url( 'admin.php?page=agencoen-connection' ) ); ?>">
			<?php esc_html_e( 'Connect', 'agentic-commerce-engine' ); ?>
		</a>
		<button type="button" class="ace-link-sm" id="ace-connect-notice-dismiss"><?php esc_html_e( 'Dismiss', 'agentic-commerce-engine' ); ?></button>
	</div>
</div>
