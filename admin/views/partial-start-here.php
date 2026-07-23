<?php
/**
 * First-run "Start here" card at the top of the Dashboard. Every action is a
 * local feature that already works with no account: the card exists so a new
 * install opens on things to DO, not on setup. Per-user dismissible; hidden
 * once the site is connected (that user is already up and running).
 *
 * @package Agencoen
 * @var Plugin $plugin    Orchestrator.
 * @var bool   $connected Store connected.
 * @var bool   $has_woo   WooCommerce active.
 * @var array  $data      Dashboard payload.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="ace-banner ace-banner-info ace-start-card" id="ace-start-card">
	<div>
		<strong><?php esc_html_e( 'Already running on this site', 'agentic-commerce-engine' ); ?></strong>
		<p class="ace-help">
			<?php esc_html_e( 'AI crawler tracking and schema output switched on when you activated the plugin. Pick up where they lead:', 'agentic-commerce-engine' ); ?>
		</p>
		<div class="ace-start-actions">
			<a class="ace-btn ace-btn-secondary ace-btn-sm" href="<?php echo esc_url( admin_url( 'admin.php?page=agencoen-aisearch' ) ); ?>">
				<?php $has_woo ? esc_html_e( 'See which AI crawlers visit your store', 'agentic-commerce-engine' ) : esc_html_e( 'See which AI crawlers visit your site', 'agentic-commerce-engine' ); ?>
			</a>
			<?php if ( $has_woo ) : ?>
				<a class="ace-btn ace-btn-secondary ace-btn-sm" href="<?php echo esc_url( admin_url( 'admin.php?page=agencoen-products' ) ); ?>">
					<?php esc_html_e( 'Browse your catalog with AI readiness scores', 'agentic-commerce-engine' ); ?>
				</a>
				<a class="ace-btn ace-btn-secondary ace-btn-sm" href="<?php echo esc_url( admin_url( 'admin.php?page=agencoen-settings' ) ); ?>">
					<?php esc_html_e( 'Copy your Google and Microsoft feed URLs', 'agentic-commerce-engine' ); ?>
				</a>
			<?php else : ?>
				<a class="ace-btn ace-btn-secondary ace-btn-sm" href="<?php echo esc_url( admin_url( 'admin.php?page=agencoen-content' ) ); ?>">
					<?php esc_html_e( 'See the articles already on this site', 'agentic-commerce-engine' ); ?>
				</a>
			<?php endif; ?>
			<a class="ace-btn ace-btn-secondary ace-btn-sm" href="<?php echo esc_url( admin_url( 'admin.php?page=agencoen-settings' ) ); ?>">
				<?php esc_html_e( 'Choose how articles publish: drafts, blocks, author', 'agentic-commerce-engine' ); ?>
			</a>
		</div>
		<?php if ( ! $has_woo ) : ?>
			<p class="ace-help ace-start-note">
				<?php esc_html_e( 'Install WooCommerce and the product tools (sync, review queue, feeds, product schema) switch on by themselves.', 'agentic-commerce-engine' ); ?>
			</p>
		<?php endif; ?>
	</div>
	<div class="ace-connect-banner-actions">
		<button type="button" class="ace-link-sm" id="ace-start-dismiss"><?php esc_html_e( 'Dismiss', 'agentic-commerce-engine' ); ?></button>
	</div>
</div>
