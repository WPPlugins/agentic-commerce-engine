<?php
/**
 * Settings: AI crawler tracking, content publishing, automation (Woo only),
 * channel feeds (Woo only), advanced. Saved via the agencoen_save_settings
 * ajax action by assets/js/screens/settings.js; the screen only submits the
 * controls it actually renders.
 *
 * Ordered local-first on purpose: the cards a merchant can use straight after
 * activating come before anything that involves the ACE service. The account
 * itself is not here at all, it lives on the Connection screen.
 *
 * @package Agencoen
 * @var Plugin $plugin    Orchestrator.
 * @var bool   $connected Whether the site is connected to ACE.
 * @var bool   $has_woo   Whether WooCommerce is active.
 * @var array  $data      Dashboard payload.
 */

defined( 'ABSPATH' ) || exit;

$agencoen_settings  = $plugin->settings();
$agencoen_frequency = $agencoen_settings->get_sync_frequency();
$agencoen_seo_label = $data['seoPlugin'];

/*
 * Local preferences only. Every control here is editable without an ACE
 * account; the account itself lives on the Connection screen. The handful of
 * controls that do talk to the service (scheduled sync, auto-apply, the
 * optional daily crawler report) say so in place.
 */
$agencoen_offline = empty( $connected );
?>
<div id="ace-settings" class="ace-settings">

	<!-- AI crawler tracking (4.0) -->
	<div class="ace-card">
		<h2 class="ace-card-title"><?php esc_html_e( 'AI crawler tracking', 'agentic-commerce-engine' ); ?></h2>
		<p class="ace-card-sub"><?php esc_html_e( 'Native, on-site logging of visits from AI crawlers such as GPTBot, ClaudeBot, and PerplexityBot. Powers the Crawlers tab on the AI Search screen.', 'agentic-commerce-engine' ); ?></p>

		<label class="ace-toggle">
			<input type="checkbox" id="ace-bots-enabled" <?php checked( $agencoen_settings->is_bots_enabled() ); ?> />
			<span><?php esc_html_e( 'Track AI crawler visits on this site', 'agentic-commerce-engine' ); ?></span>
		</label>

		<label class="ace-toggle">
			<input type="checkbox" id="ace-bots-paths" <?php checked( $agencoen_settings->is_bots_paths() ); ?> />
			<span><?php esc_html_e( 'Store the page path for each visit (per-page breakdowns)', 'agentic-commerce-engine' ); ?></span>
		</label>
		<p class="ace-help ace-indent"><?php esc_html_e( 'Turn this off to keep only one aggregate row per crawler per day.', 'agentic-commerce-engine' ); ?></p>

		<div class="ace-field ace-indent">
			<label class="ace-label" for="ace-bots-retention"><?php esc_html_e( 'Keep crawler logs for', 'agentic-commerce-engine' ); ?></label>
			<select id="ace-bots-retention" class="ace-select ace-input-sm">
				<option value="30" <?php selected( $agencoen_settings->get_bots_retention(), 30 ); ?>><?php esc_html_e( '30 days', 'agentic-commerce-engine' ); ?></option>
				<option value="90" <?php selected( $agencoen_settings->get_bots_retention(), 90 ); ?>><?php esc_html_e( '90 days', 'agentic-commerce-engine' ); ?></option>
				<option value="180" <?php selected( $agencoen_settings->get_bots_retention(), 180 ); ?>><?php esc_html_e( '180 days', 'agentic-commerce-engine' ); ?></option>
			</select>
		</div>

		<label class="ace-toggle">
			<input type="checkbox" id="ace-bots-report" <?php checked( $agencoen_settings->is_bots_report() ); ?> <?php disabled( $agencoen_offline ); ?> />
			<span><?php esc_html_e( 'Send daily crawler totals to my ACE project', 'agentic-commerce-engine' ); ?></span>
		</label>
		<p class="ace-help ace-indent">
			<?php esc_html_e( 'Optional. When enabled, ACE receives one aggregate row per crawler per day (crawler name, date, hit count). No IP addresses, cookies, or personal information ever leave your site. This setting covers crawlers only; visitor pageviews are a separate setting below and are off unless you turn them on.', 'agentic-commerce-engine' ); ?>
		</p>
		<?php if ( $agencoen_offline ) : ?>
			<p class="ace-help ace-indent"><?php esc_html_e( 'Tracking, path storage, and retention all run entirely on this site and are active right now. Only the optional daily report, which sends totals to ACE, needs an account.', 'agentic-commerce-engine' ); ?></p>
		<?php endif; ?>
	</div>

	<!-- Storefront traffic (4.4) -->
	<div class="ace-card">
		<h2 class="ace-card-title"><?php esc_html_e( 'Visitor pageviews', 'agentic-commerce-engine' ); ?></h2>
		<p class="ace-card-sub"><?php esc_html_e( 'Counts how many times each published page was viewed, so ACE can show what your content changes did to real traffic.', 'agentic-commerce-engine' ); ?></p>

		<label class="ace-toggle">
			<input type="checkbox" id="ace-traffic-enabled" <?php checked( $agencoen_settings->is_traffic_enabled() ); ?> />
			<span><?php esc_html_e( 'Count visitor pageviews on this site', 'agentic-commerce-engine' ); ?></span>
		</label>
		<p class="ace-help ace-indent">
			<?php esc_html_e( 'Adds a small script to published pages that records one count per view. It stores no cookie, no session, and no IP address, so it cannot tell one visitor from another and reports pageviews rather than visitors. Logged-in editors and administrators are never counted, and neither are crawlers.', 'agentic-commerce-engine' ); ?>
		</p>
		<p class="ace-help ace-indent">
			<?php esc_html_e( 'A script is used rather than server-side counting because page caching serves most visitors without WordPress ever running, which would undercount heavily.', 'agentic-commerce-engine' ); ?>
		</p>

		<label class="ace-toggle">
			<input type="checkbox" id="ace-traffic-report" <?php checked( $agencoen_settings->is_traffic_report() ); ?> <?php disabled( $agencoen_offline ); ?> />
			<span><?php esc_html_e( 'Send daily pageview and sales totals to my ACE project', 'agentic-commerce-engine' ); ?></span>
		</label>
		<p class="ace-help ace-indent">
			<?php esc_html_e( 'Optional, and separate from the crawler setting above. ACE receives one row per page per day (page, date, view count, and the referring site for visits from search and AI assistants), and for WooCommerce stores one row per product per day (units and revenue). No order details, customer records, names, email addresses or postal addresses are ever sent.', 'agentic-commerce-engine' ); ?>
		</p>
		<?php if ( $agencoen_offline ) : ?>
			<p class="ace-help ace-indent"><?php esc_html_e( 'Counting works on its own with no account. Only the daily report needs one.', 'agentic-commerce-engine' ); ?></p>
		<?php endif; ?>
	</div>

	<!-- Per-visitor A/B testing (4.5) -->
	<div class="ace-card">
		<h2 class="ace-card-title"><?php esc_html_e( 'A/B test your product copy', 'agentic-commerce-engine' ); ?></h2>
		<p class="ace-card-sub"><?php esc_html_e( 'Shows different versions of a product description to different visitors and measures which one sells better. Experiments are set up in ACE; this switch decides whether this site takes part.', 'agentic-commerce-engine' ); ?></p>

		<label class="ace-toggle">
			<input type="checkbox" id="ace-ab-enabled" <?php checked( $agencoen_settings->is_ab_enabled() ); ?> />
			<span><?php esc_html_e( 'Split visitors between versions on this site', 'agentic-commerce-engine' ); ?></span>
		</label>
		<p class="ace-help ace-indent">
			<?php esc_html_e( 'This is the one feature here that stores a cookie. To remember which version a visitor saw, it keeps a random id in their browser. Nothing happens without their consent: it uses the WordPress Consent API, so your cookie banner decides. If you have no consent plugin, nothing is stored and every visitor sees your normal copy.', 'agentic-commerce-engine' ); ?>
		</p>
		<p class="ace-help ace-indent">
			<?php esc_html_e( 'Search engines and AI crawlers always see your normal copy. They carry no cookie and give no consent, so they are never put into a test, and your page never contains more than one version of its description.', 'agentic-commerce-engine' ); ?>
		</p>

		<label class="ace-toggle">
			<input type="checkbox" id="ace-ab-report" <?php checked( $agencoen_settings->is_ab_report_enabled() ); ?> <?php disabled( $agencoen_offline ); ?> />
			<span><?php esc_html_e( 'Send results to my ACE project', 'agentic-commerce-engine' ); ?></span>
		</label>
		<p class="ace-help ace-indent">
			<?php esc_html_e( 'Separate from the switch above, because a test can run without the result leaving this site. ACE receives which version was shown and whether that visitor reached checkout, against a scrambled id that is unique to this site and cannot be linked back to a person or matched with any other site. No names, email addresses, order details or IP addresses are ever sent.', 'agentic-commerce-engine' ); ?>
		</p>

		<label class="ace-field">
			<span class="ace-field-label"><?php esc_html_e( 'Description block selector', 'agentic-commerce-engine' ); ?></span>
			<input type="text" id="ace-ab-selector" class="regular-text" placeholder=".woocommerce-Tabs-panel--description" value="<?php echo esc_attr( $agencoen_settings->ab_selector() ); ?>" />
		</label>
		<p class="ace-help ace-indent">
			<?php esc_html_e( 'Leave blank for the standard WooCommerce layout. On another theme, put the CSS selector for the block holding your product description. If it does not match your theme, the test will run and change nothing, and it will report no difference.', 'agentic-commerce-engine' ); ?>
		</p>
	</div>

	<!-- Content publishing (4.0) -->
	<div class="ace-card">
		<h2 class="ace-card-title"><?php esc_html_e( 'Content publishing', 'agentic-commerce-engine' ); ?></h2>
		<p class="ace-card-sub"><?php esc_html_e( 'How articles from the Content screen land in WordPress.', 'agentic-commerce-engine' ); ?></p>

		<div class="ace-form-row">
			<div class="ace-field">
				<label class="ace-label" for="ace-content-status"><?php esc_html_e( 'Default status', 'agentic-commerce-engine' ); ?></label>
				<select id="ace-content-status" class="ace-select">
					<option value="draft" <?php selected( $agencoen_settings->get_content_status(), 'draft' ); ?>><?php esc_html_e( 'Draft (review before it goes live)', 'agentic-commerce-engine' ); ?></option>
					<option value="publish" <?php selected( $agencoen_settings->get_content_status(), 'publish' ); ?>><?php esc_html_e( 'Publish immediately', 'agentic-commerce-engine' ); ?></option>
				</select>
			</div>
			<div class="ace-field">
				<label class="ace-label" for="ace-content-format"><?php esc_html_e( 'Editor format', 'agentic-commerce-engine' ); ?></label>
				<select id="ace-content-format" class="ace-select">
					<option value="blocks" <?php selected( $agencoen_settings->get_content_format(), 'blocks' ); ?>><?php esc_html_e( 'Block editor (Gutenberg blocks)', 'agentic-commerce-engine' ); ?></option>
					<option value="classic" <?php selected( $agencoen_settings->get_content_format(), 'classic' ); ?>><?php esc_html_e( 'Classic HTML', 'agentic-commerce-engine' ); ?></option>
				</select>
			</div>
			<div class="ace-field">
				<label class="ace-label" for="ace-content-author"><?php esc_html_e( 'Default author', 'agentic-commerce-engine' ); ?></label>
				<select id="ace-content-author" class="ace-select">
					<option value="0"><?php esc_html_e( 'The user who publishes', 'agentic-commerce-engine' ); ?></option>
				</select>
			</div>
		</div>

		<label class="ace-toggle">
			<input type="checkbox" id="ace-article-schema" <?php checked( $agencoen_settings->is_article_schema() ); ?> />
			<span><?php esc_html_e( 'Output Article schema (JSON-LD) on published ACE articles', 'agentic-commerce-engine' ); ?></span>
		</label>
	</div>

	<?php if ( $has_woo ) : ?>
		<!-- Automation (commerce suite) -->
		<div class="ace-card">
			<h2 class="ace-card-title"><?php esc_html_e( 'Automation', 'agentic-commerce-engine' ); ?></h2>
			<p class="ace-card-sub"><?php esc_html_e( 'Keep your catalog and AI content moving without manual clicks.', 'agentic-commerce-engine' ); ?></p>

			<label class="ace-toggle">
				<input type="checkbox" id="ace-auto-sync" <?php checked( $data['autoSync'] ); ?> <?php disabled( $agencoen_offline ); ?> />
				<span><?php esc_html_e( 'Auto-sync my catalog on a schedule', 'agentic-commerce-engine' ); ?></span>
			</label>

			<div class="ace-field ace-indent">
				<label class="ace-label" for="ace-sync-frequency"><?php esc_html_e( 'Frequency', 'agentic-commerce-engine' ); ?></label>
				<select id="ace-sync-frequency" class="ace-select ace-input-sm" <?php disabled( $agencoen_offline ); ?>>
					<option value="daily" <?php selected( $agencoen_frequency, 'daily' ); ?>><?php esc_html_e( 'Daily', 'agentic-commerce-engine' ); ?></option>
					<option value="weekly" <?php selected( $agencoen_frequency, 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'agentic-commerce-engine' ); ?></option>
				</select>
			</div>

			<label class="ace-toggle">
				<input type="checkbox" id="ace-auto-apply" <?php checked( $data['autoApply'] ); ?> <?php disabled( $agencoen_offline ); ?> />
				<span><?php esc_html_e( 'Auto-apply AI suggestions without review', 'agentic-commerce-engine' ); ?></span>
			</label>
			<p class="ace-help ace-indent ace-warn-text"><?php esc_html_e( 'When on, AI changes go live on your products automatically. You can still revert any product from the Review screen.', 'agentic-commerce-engine' ); ?></p>
			<?php if ( $agencoen_offline ) : ?>
				<p class="ace-help ace-indent"><?php esc_html_e( 'Scheduled sync and auto-apply send products to ACE for enrichment, so they need an account. The schema and product-page options below work on their own.', 'agentic-commerce-engine' ); ?></p>
			<?php endif; ?>

			<label class="ace-toggle">
				<input type="checkbox" id="ace-schema-enabled" <?php checked( $data['schema'] ); ?> />
				<span><?php esc_html_e( 'Output Product schema (JSON-LD) on product pages', 'agentic-commerce-engine' ); ?></span>
			</label>

			<label class="ace-toggle">
				<input type="checkbox" id="ace-auto-append" <?php checked( $agencoen_settings->is_auto_append() ); ?> />
				<span><?php esc_html_e( 'Show IRL scenarios and internal links on the product page automatically', 'agentic-commerce-engine' ); ?></span>
			</label>
			<p class="ace-help ace-indent"><?php esc_html_e( 'Or place them yourself with the [agencoen_irl] and [agencoen_internal_links] shortcodes, or the ACE editor blocks.', 'agentic-commerce-engine' ); ?></p>
			<?php if ( '' !== $agencoen_seo_label ) : ?>
				<p class="ace-help ace-indent">
					<?php
					printf(
						/* translators: %s: SEO plugin name. */
						esc_html__( 'Detected %s. ACE automatically suppresses its schema output when your SEO plugin already emits Product schema, to avoid duplicates.', 'agentic-commerce-engine' ),
						'<strong>' . esc_html( $agencoen_seo_label ) . '</strong>'
					);
					?>
				</p>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if ( $has_woo ) : ?>
		<!-- Channel feeds -->
		<div class="ace-card">
			<h2 class="ace-card-title"><?php esc_html_e( 'Channel feeds', 'agentic-commerce-engine' ); ?></h2>
			<p class="ace-card-sub"><?php esc_html_e( 'When you enrich products with a channel feed type, ACE serves the feed at these URLs.', 'agentic-commerce-engine' ); ?></p>
			<?php
			$agencoen_feeds = array(
				'google'    => __( 'Google Merchant', 'agentic-commerce-engine' ),
				'microsoft' => __( 'Microsoft Bing', 'agentic-commerce-engine' ),
				'agentic'   => __( 'Agentic Commerce', 'agentic-commerce-engine' ),
			);
			foreach ( $agencoen_feeds as $agencoen_ch => $agencoen_ch_label ) :
				?>
				<div class="ace-field">
					<label class="ace-label"><?php echo esc_html( $agencoen_ch_label ); ?></label>
					<input type="text" class="ace-input ace-select-on-click" data-channel="<?php echo esc_attr( $agencoen_ch ); ?>" value="<?php echo esc_attr( \Agencoen\Feeds::feed_url( $agencoen_ch ) ); ?>" readonly />
				</div>
			<?php endforeach; ?>
			<button type="button" class="ace-btn ace-btn-secondary ace-btn-sm" id="ace-feed-regenerate"><?php esc_html_e( 'Regenerate feed URLs', 'agentic-commerce-engine' ); ?></button>
			<p class="ace-help"><?php esc_html_e( 'Each URL carries a private token, so only the channels you give it to can read the feed. Regenerating invalidates the old URLs; update them in Google and Microsoft afterwards.', 'agentic-commerce-engine' ); ?></p>
		</div>
	<?php endif; ?>

	<!-- Advanced -->
	<div class="ace-card">
		<h2 class="ace-card-title"><?php esc_html_e( 'Advanced', 'agentic-commerce-engine' ); ?></h2>
		<p class="ace-card-sub"><?php esc_html_e( 'Platform endpoint, webhook, and diagnostics.', 'agentic-commerce-engine' ); ?></p>

		<div class="ace-field">
			<label class="ace-label" for="ace-base-url-setting"><?php esc_html_e( 'Platform URL', 'agentic-commerce-engine' ); ?></label>
			<input type="url" id="ace-base-url-setting" class="ace-input" value="<?php echo esc_attr( $data['baseUrl'] ); ?>" />
		</div>

		<div class="ace-field">
			<label class="ace-label" for="ace-webhook-url"><?php esc_html_e( 'Webhook URL (read-only)', 'agentic-commerce-engine' ); ?></label>
			<input type="text" id="ace-webhook-url" class="ace-input ace-select-on-click" value="<?php echo esc_attr( $data['webhookUrl'] ); ?>" readonly />
		</div>

		<label class="ace-toggle">
			<input type="checkbox" id="ace-debug-log" <?php checked( $agencoen_settings->is_debug_log() ); ?> />
			<span><?php esc_html_e( 'Enable debug logging', 'agentic-commerce-engine' ); ?></span>
		</label>
	</div>

	<div class="ace-settings-save">
		<button type="button" class="ace-btn ace-btn-primary" id="ace-save-settings"><?php esc_html_e( 'Save settings', 'agentic-commerce-engine' ); ?></button>
	</div>

</div>
