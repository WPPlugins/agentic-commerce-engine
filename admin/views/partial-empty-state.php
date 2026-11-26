<?php
/**
 * Connect screen, shown until the site is linked to Rezolve Ai. Two columns: the API
 * key form on the left (wired by ace-core.js), the brand blurb plus a
 * mode-aware list of what the Rezolve Ai service adds on the right.
 *
 * @package Agencoen
 * @var Plugin $plugin  Orchestrator.
 * @var bool   $has_woo Whether WooCommerce is active.
 * @var array  $data    Dashboard payload.
 */

defined( 'ABSPATH' ) || exit;

$agencoen_base = isset( $data['baseUrl'] ) ? (string) $data['baseUrl'] : '';
?>
<div class="ace-connect">
	<div class="ace-card ace-connect-card">
		<div class="ace-connect-grid">
			<div class="ace-connect-form">
				<h2 class="ace-h1"><?php esc_html_e( 'Connect your site to Rezolve Ai', 'agentic-commerce-engine' ); ?></h2>
				<p class="ace-muted">
					<?php esc_html_e( 'Paste your API key from the Rezolve Ai platform to add content writing and AI search research. The crawler analytics and publishing tools on this site do not use it.', 'agentic-commerce-engine' ); ?>
				</p>

				<div id="ace-connect-error" class="ace-banner ace-banner-error" role="alert" style="display:none;"></div>

				<div class="ace-field">
					<label class="ace-label" for="ace-api-key"><?php esc_html_e( 'API key', 'agentic-commerce-engine' ); ?></label>
					<input type="password" id="ace-api-key" class="ace-input" placeholder="ace_" autocomplete="off" spellcheck="false" aria-required="true" />
				</div>

				<button type="button" class="ace-btn ace-btn-primary" id="ace-connect-btn"><?php esc_html_e( 'Connect site', 'agentic-commerce-engine' ); ?></button>

				<p class="ace-help">
					<a href="<?php echo esc_url( $agencoen_base . '/settings/api-keys' ); ?>" target="_blank" rel="noopener">
						<?php esc_html_e( 'Where do I find my API key?', 'agentic-commerce-engine' ); ?>
					</a>
				</p>

				<details class="ace-advanced">
					<summary><?php esc_html_e( 'Advanced', 'agentic-commerce-engine' ); ?></summary>
					<div class="ace-field">
						<label class="ace-label" for="ace-base-url"><?php esc_html_e( 'Platform URL', 'agentic-commerce-engine' ); ?></label>
						<input type="url" id="ace-base-url" class="ace-input" value="<?php echo esc_attr( $agencoen_base ); ?>" />
					</div>
				</details>
			</div>

			<div class="ace-connect-side">
				<img class="ace-logo" src="<?php echo esc_url( AGENCOEN_PLUGIN_URL . 'assets/img/rezolve-ai-logo.svg' ); ?>" alt="Rezolve Ai" />
				<h2 class="ace-h3"><?php esc_html_e( 'What the Rezolve Ai service adds', 'agentic-commerce-engine' ); ?></h2>
				<?php if ( $has_woo ) : ?>
					<ul class="ace-checklist">
						<li><?php esc_html_e( 'Product titles, descriptions, and SEO meta written for you, with a before/after review queue', 'agentic-commerce-engine' ); ?></li>
						<li><?php esc_html_e( 'Articles drafted for you and published straight into WordPress', 'agentic-commerce-engine' ); ?></li>
						<li><?php esc_html_e( 'AI search visibility research for your brand and market', 'agentic-commerce-engine' ); ?></li>
					</ul>
				<?php else : ?>
					<ul class="ace-checklist">
						<li><?php esc_html_e( 'Articles drafted for you and published straight into WordPress', 'agentic-commerce-engine' ); ?></li>
						<li><?php esc_html_e( 'AI search visibility research for your brand and market', 'agentic-commerce-engine' ); ?></li>
						<li class="is-muted"><?php esc_html_e( 'Product content is available when WooCommerce is active', 'agentic-commerce-engine' ); ?></li>
					</ul>
				<?php endif; ?>
				<p class="ace-help">
					<?php esc_html_e( 'Crawler analytics, Product and Article JSON-LD, the channel feed URLs, and the publishing tools are part of the plugin and stay free either way.', 'agentic-commerce-engine' ); ?>
				</p>
			</div>
		</div>
	</div>
</div>
