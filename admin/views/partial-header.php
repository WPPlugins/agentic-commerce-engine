<?php
/**
 * Branded page header: static aurora accent, brand row (logo + ACE wordmark +
 * screen title), connection pill, and quick links.
 *
 * @package Agencoen
 * @var Plugin $plugin    Orchestrator.
 * @var bool   $connected Whether the site is connected to ACE.
 * @var bool   $has_woo   Whether WooCommerce is active.
 * @var array  $data      Dashboard payload.
 * @var string $title     Current screen title.
 */

defined( 'ABSPATH' ) || exit;

$agencoen_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
$agencoen_base = isset( $data['baseUrl'] ) ? (string) $data['baseUrl'] : '';
?>
<div class="ace-header">
	<div class="ace-aurora" aria-hidden="true">
		<span class="ace-aurora-a"></span>
		<span class="ace-aurora-b"></span>
	</div>
	<div class="ace-header-main">
		<div class="ace-header-brand">
			<img class="ace-logo" src="<?php echo esc_url( AGENCOEN_PLUGIN_URL . 'assets/img/authoritas-logo.svg' ); ?>" alt="Authoritas" />
			<span class="ace-brand-sep" aria-hidden="true"></span>
			<div class="ace-brand-copy">
				<span class="ace-brand-name">ACE</span>
				<span class="ace-brand-title"><?php echo esc_html( isset( $title ) ? $title : '' ); ?></span>
			</div>
		</div>
		<div class="ace-header-side">
			<nav class="ace-header-links" aria-label="<?php esc_attr_e( 'ACE quick links', 'agentic-commerce-engine' ); ?>">
				<a href="<?php echo esc_url( $agencoen_base ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open ACE app', 'agentic-commerce-engine' ); ?></a>
				<a href="<?php echo esc_url( $agencoen_base . '/docs' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Docs', 'agentic-commerce-engine' ); ?></a>
			</nav>
			<?php if ( ! empty( $connected ) ) : ?>
				<span class="ace-pill ace-pill-success">
					<?php esc_html_e( 'Connected', 'agentic-commerce-engine' ); ?>
					<?php if ( '' !== $agencoen_host ) : ?>
						<span class="ace-pill-host"><?php echo esc_html( $agencoen_host ); ?></span>
					<?php endif; ?>
				</span>
			<?php else : ?>
				<span class="ace-pill ace-pill-neutral"><?php esc_html_e( 'Not connected', 'agentic-commerce-engine' ); ?></span>
				<a class="ace-btn ace-btn-primary ace-btn-sm" href="<?php echo esc_url( admin_url( 'admin.php?page=agencoen-connection' ) ); ?>"><?php esc_html_e( 'Connect', 'agentic-commerce-engine' ); ?></a>
			<?php endif; ?>
		</div>
	</div>
</div>
