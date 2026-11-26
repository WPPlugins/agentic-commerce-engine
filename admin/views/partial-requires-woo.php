<?php
/**
 * Missing-dependency state for the commerce screens (Products, Review) on
 * sites without WooCommerce. These screens read WooCommerce's own product
 * tables, so there is nothing for them to show until it is installed.
 * Everything else in the plugin keeps working.
 *
 * @package Agencoen
 * @var Plugin $plugin    Orchestrator.
 * @var bool   $connected Whether the site is connected to Rezolve Ai.
 * @var array  $data      Dashboard payload.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="ace-dependency">
	<div class="ace-card ace-dependency-card">
		<span class="ace-chip ace-chip-neutral"><?php esc_html_e( 'WooCommerce required', 'agentic-commerce-engine' ); ?></span>
		<h2 class="ace-h1"><?php esc_html_e( 'Product optimization needs WooCommerce', 'agentic-commerce-engine' ); ?></h2>
		<p class="ace-muted">
			<?php esc_html_e( 'This screen works with your WooCommerce products, so it has nothing to show until WooCommerce is installed. Activate it and this screen starts working straight away, with no other setup.', 'agentic-commerce-engine' ); ?>
		</p>
		<ul class="ace-checklist">
			<li><?php esc_html_e( 'Sync your product catalog to Rezolve Ai', 'agentic-commerce-engine' ); ?></li>
			<li><?php esc_html_e( 'Generate product content and approve it in a before/after review queue', 'agentic-commerce-engine' ); ?></li>
			<li><?php esc_html_e( 'Emit enhanced Product JSON-LD on every product page', 'agentic-commerce-engine' ); ?></li>
			<li><?php esc_html_e( 'Serve Google, Bing, and Agentic Commerce channel feeds', 'agentic-commerce-engine' ); ?></li>
			<li><?php esc_html_e( 'See measured quality scores for the content on every product', 'agentic-commerce-engine' ); ?></li>
		</ul>
		<div class="ace-dependency-actions">
			<?php if ( current_user_can( 'install_plugins' ) ) : ?>
				<a class="ace-btn ace-btn-primary" href="<?php echo esc_url( admin_url( 'plugin-install.php?s=woocommerce&tab=search&type=term' ) ); ?>">
					<?php esc_html_e( 'Install WooCommerce', 'agentic-commerce-engine' ); ?>
				</a>
			<?php endif; ?>
			<a class="ace-btn ace-btn-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=agencoen-content' ) ); ?>">
				<?php esc_html_e( 'Explore the Content screen', 'agentic-commerce-engine' ); ?>
			</a>
		</div>
	</div>
</div>
