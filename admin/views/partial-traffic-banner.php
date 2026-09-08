<?php
/**
 * "Nothing is being measured" notice.
 *
 * Shown to a CONNECTED site whose daily reporting is switched off, which is the
 * state every site was in by default. The plugin's two traffic options both
 * ship as 'no' on purpose, and that default is not changed by this release: a
 * plugin update must never start collecting anything the site owner has not
 * agreed to. So it asks instead.
 *
 * Dismissible per user, and re-offered whenever the reporting setting changes,
 * so one click years ago cannot silence it forever.
 *
 * @package Agencoen
 * @var Plugin $plugin    Orchestrator.
 * @var bool   $connected Store connected.
 * @var bool   $has_woo   WooCommerce active.
 * @var array  $data      Dashboard payload.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="ace-banner ace-banner-info ace-connect-banner" id="ace-traffic-notice">
	<div>
		<strong><?php esc_html_e( 'Rezolve Ai cannot yet show what your changes did', 'agentic-commerce-engine' ); ?></strong>
		<p class="ace-help">
			<?php esc_html_e( 'This site is connected, but it is not sending pageview totals, so results, A/B tests and AI referral reporting have nothing to measure. Switching it on sends one row per page per day. No order details, customer records, names, email addresses or postal addresses are ever sent.', 'agentic-commerce-engine' ); ?>
		</p>
	</div>
	<div class="ace-connect-banner-actions">
		<a class="ace-btn ace-btn-primary ace-btn-sm" href="<?php echo esc_url( admin_url( 'admin.php?page=agencoen-settings' ) ); ?>">
			<?php esc_html_e( 'Turn on reporting', 'agentic-commerce-engine' ); ?>
		</a>
		<button type="button" class="ace-link-sm" id="ace-traffic-notice-dismiss"><?php esc_html_e( 'Not now', 'agentic-commerce-engine' ); ?></button>
	</div>
</div>
