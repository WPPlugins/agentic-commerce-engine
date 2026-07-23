<?php
/**
 * Connection: the one place an ACE account is set up or removed.
 *
 * Deliberately kept off the Settings screen. Nothing this plugin does on the
 * site itself depends on anything here, so a merchant who never opens this page
 * still has a complete, working plugin.
 *
 * @package Agencoen
 * @var Plugin $plugin    Orchestrator.
 * @var bool   $connected Store connected.
 * @var bool   $has_woo   WooCommerce active.
 * @var array  $data      Dashboard payload.
 */

defined( 'ABSPATH' ) || exit;
?>
<div id="ace-connection" class="ace-connection">

	<div class="ace-card">
		<h2 class="ace-card-title"><?php esc_html_e( 'The plugin is free and works on its own', 'agentic-commerce-engine' ); ?></h2>
		<p class="ace-card-sub">
			<?php esc_html_e( 'AI crawler analytics, schema output, and the publishing tools are free and always on, with or without an ACE account. Nothing on this page is needed to use them.', 'agentic-commerce-engine' ); ?>
		</p>
		<p class="ace-help">
			<?php esc_html_e( 'ACE is a separate service that writes content and runs AI search research on its own servers. Writing an article or querying an AI answer engine is work your site cannot do by itself, so those features send a request to ACE and need an account.', 'agentic-commerce-engine' ); ?>
		</p>
	</div>

	<?php if ( empty( $connected ) ) : ?>
		<?php include AGENCOEN_PLUGIN_DIR . 'admin/views/partial-empty-state.php'; ?>
	<?php else : ?>

		<div class="ace-card">
			<h2 class="ace-card-title"><?php esc_html_e( 'Account', 'agentic-commerce-engine' ); ?></h2>
			<p class="ace-card-sub"><?php esc_html_e( 'This site is linked to your ACE project. The key is stored server-side and never shown again.', 'agentic-commerce-engine' ); ?></p>
			<div class="ace-form-row">
				<div class="ace-field">
					<label class="ace-label"><?php esc_html_e( 'API key', 'agentic-commerce-engine' ); ?></label>
					<input type="text" class="ace-input" value="ace_&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;" disabled />
				</div>
				<div class="ace-field">
					<label class="ace-label"><?php esc_html_e( 'Store ID', 'agentic-commerce-engine' ); ?></label>
					<input type="text" class="ace-input ace-select-on-click" value="<?php echo esc_attr( $data['storeId'] ); ?>" readonly />
				</div>
			</div>
			<button type="button" class="ace-btn ace-btn-secondary ace-btn-sm" id="ace-health-check"><?php esc_html_e( 'Run health check', 'agentic-commerce-engine' ); ?></button>
		</div>

		<div class="ace-card">
			<h2 class="ace-card-title"><?php esc_html_e( 'Remove the connection', 'agentic-commerce-engine' ); ?></h2>
			<p class="ace-card-sub"><?php esc_html_e( 'Disconnecting removes the API key and store link from this site. Your products, articles, and crawler logs stay untouched, the local features keep working, and you can reconnect at any time.', 'agentic-commerce-engine' ); ?></p>
			<button type="button" class="ace-btn ace-btn-danger ace-btn-sm" id="ace-disconnect"><?php esc_html_e( 'Disconnect', 'agentic-commerce-engine' ); ?></button>
		</div>

	<?php endif; ?>

</div>
