<?php
/**
 * Settings: AI crawler tracking, content publishing, automation (Woo only),
 * channel feeds (Woo only), advanced. Saved via the agencoen_save_settings
 * ajax action by assets/js/screens/settings.js; the screen only submits the
 * controls it actually renders.
 *
 * Ordered local-first on purpose: the cards a merchant can use straight after
 * activating come before anything that involves the Rezolve Ai service. The account
 * itself is not here at all, it lives on the Connection screen.
 *
 * @package Agencoen
 * @var Plugin $plugin    Orchestrator.
 * @var bool   $connected Whether the site is connected to Rezolve Ai.
 * @var bool   $has_woo   Whether WooCommerce is active.
 * @var array  $data      Dashboard payload.
 */

defined( 'ABSPATH' ) || exit;

$agencoen_settings  = $plugin->settings();
$agencoen_frequency = $agencoen_settings->get_sync_frequency();
$agencoen_seo_label = $data['seoPlugin'];

/*
 * Local preferences only. Every control here is editable without a Rezolve Ai
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
			<span><?php esc_html_e( 'Send daily crawler totals to my Rezolve Ai project', 'agentic-commerce-engine' ); ?></span>
		</label>
		<p class="ace-help ace-indent">
			<?php esc_html_e( 'Optional. When enabled, Rezolve Ai receives one aggregate row per crawler per day (crawler name, date, hit count), and the requests of AI agents that sign them, at most 200 a day, so their signatures can be checked. No IP addresses, cookies, or personal information ever leave your site. This setting covers crawlers only; visitor pageviews are a separate setting below and are off unless you turn them on.', 'agentic-commerce-engine' ); ?>
		</p>
		<?php if ( $agencoen_offline ) : ?>
			<p class="ace-help ace-indent"><?php esc_html_e( 'Tracking, path storage, and retention all run entirely on this site and are active right now. Only the optional daily report, which sends totals to Rezolve Ai, needs an account.', 'agentic-commerce-engine' ); ?></p>
		<?php endif; ?>
	</div>

	<!-- Storefront traffic (4.4) -->
	<div class="ace-card">
		<h2 class="ace-card-title"><?php esc_html_e( 'Visitor pageviews', 'agentic-commerce-engine' ); ?></h2>
		<p class="ace-card-sub"><?php esc_html_e( 'Counts how many times each published page was viewed, so Rezolve Ai can show what your content changes did to real traffic.', 'agentic-commerce-engine' ); ?></p>

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
			<span><?php esc_html_e( 'Send daily pageview and sales totals to my Rezolve Ai project', 'agentic-commerce-engine' ); ?></span>
		</label>
		<p class="ace-help ace-indent">
			<?php esc_html_e( 'Optional, and separate from the crawler setting above. Rezolve Ai receives one row per page per day (page, date, view count, and the referring site for visits from search and AI assistants), and for WooCommerce stores one row per product per day (units and revenue). Orders that came through a buy link given to a shopping agent are also totalled per day against the reference Rezolve Ai put on that link. No order details, customer records, names, email addresses or postal addresses are ever sent.', 'agentic-commerce-engine' ); ?>
		</p>
		<?php if ( $agencoen_offline ) : ?>
			<p class="ace-help ace-indent"><?php esc_html_e( 'Counting works on its own with no account. Only the daily report needs one.', 'agentic-commerce-engine' ); ?></p>
		<?php endif; ?>
	</div>

	<!-- Per-visitor A/B testing (4.5) -->
	<div class="ace-card">
		<h2 class="ace-card-title"><?php esc_html_e( 'A/B test your product copy', 'agentic-commerce-engine' ); ?></h2>
		<p class="ace-card-sub"><?php esc_html_e( 'Shows different versions of a product description to different visitors and measures which one sells better. Experiments are set up in Rezolve Ai; this switch decides whether this site takes part.', 'agentic-commerce-engine' ); ?></p>

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
			<span><?php esc_html_e( 'Send results to my Rezolve Ai project', 'agentic-commerce-engine' ); ?></span>
		</label>
		<p class="ace-help ace-indent">
			<?php esc_html_e( 'Separate from the switch above, because a test can run without the result leaving this site. Rezolve Ai receives which version was shown and whether that visitor reached checkout, against a scrambled id that is unique to this site and cannot be linked back to a person or matched with any other site. No names, email addresses, order details or IP addresses are ever sent.', 'agentic-commerce-engine' ); ?>
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
			<span><?php esc_html_e( 'Output Article schema (JSON-LD) on published Rezolve Ai articles', 'agentic-commerce-engine' ); ?></span>
		</label>
	</div>

	<?php if ( $has_woo ) : ?>
		<!-- Custom fields (4.24) -->
		<div class="ace-card">
			<h2 class="ace-card-title"><?php esc_html_e( 'Custom fields', 'agentic-commerce-engine' ); ?></h2>
			<p class="ace-card-sub"><?php esc_html_e( 'Content you keep outside the main product fields, such as ACF fields, custom fields, attributes, custom tabs and brand, is used as product facts whenever Rezolve Ai writes for a product. Leave out any field you do not want used. Turn on Rewrite for a text field to get improved versions in Review; nothing in your store changes until you approve them.', 'agentic-commerce-engine' ); ?></p>
			<p class="ace-help"><?php esc_html_e( 'Fields that look private, such as costs, supplier details, keys, passwords, email addresses and phone numbers, are never sent.', 'agentic-commerce-engine' ); ?></p>
			<?php if ( $agencoen_offline ) : ?>
				<p class="ace-help"><?php esc_html_e( 'Connect your site to see the fields found on your products.', 'agentic-commerce-engine' ); ?></p>
			<?php else : ?>
				<?php // Not a live region: the table redraws as the merchant types a search, and outcomes are announced by the toast. ?>
				<div id="ace-custom-fields" class="ace-custom-fields">
					<p class="ace-help"><?php esc_html_e( 'Loading your custom fields…', 'agentic-commerce-engine' ); ?></p>
				</div>
			<?php endif; ?>
		</div>

		<!-- Field formats (4.28) -->
		<div class="ace-card">
			<h2 class="ace-card-title"><?php esc_html_e( 'Field formats', 'agentic-commerce-engine' ); ?></h2>
			<p class="ace-card-sub"><?php esc_html_e( 'Rezolve Ai learns how your store writes the product description and each custom field from your own products: the parts, their order, the formatting and the length. New content is written the same way, so it reads like the rest of your store. Pick a preset for any field instead, keep the parts you want untouched, and preview a format on one of your products for free.', 'agentic-commerce-engine' ); ?></p>
			<?php if ( $agencoen_offline ) : ?>
				<p class="ace-help"><?php esc_html_e( 'Connect your site to see how your fields are written.', 'agentic-commerce-engine' ); ?></p>
			<?php else : ?>
				<div id="ace-field-formats" class="ace-field-formats">
					<p class="ace-help"><?php esc_html_e( 'Loading your field formats…', 'agentic-commerce-engine' ); ?></p>
				</div>

				<div class="ace-modal" id="ace-ff-modal" hidden>
					<div class="ace-modal-backdrop"></div>
					<div class="ace-modal-card ace-ff-card" role="dialog" aria-modal="true" aria-labelledby="ace-ff-title">
						<h2 class="ace-h2" id="ace-ff-title" translate="no"></h2>
						<div id="ace-ff-body"></div>
						<div class="ace-modal-actions">
							<button type="button" class="ace-btn ace-btn-secondary" id="ace-ff-cancel"><?php esc_html_e( 'Cancel', 'agentic-commerce-engine' ); ?></button>
							<button type="button" class="ace-btn ace-btn-primary" id="ace-ff-save"><?php esc_html_e( 'Save', 'agentic-commerce-engine' ); ?></button>
						</div>
					</div>
				</div>

				<div class="ace-modal" id="ace-ff-fill-modal" hidden>
					<div class="ace-modal-backdrop"></div>
					<div class="ace-modal-card" role="dialog" aria-modal="true" aria-labelledby="ace-ff-fill-title">
						<h2 class="ace-h2" id="ace-ff-fill-title"></h2>
						<div id="ace-ff-fill-body"></div>
						<div class="ace-modal-actions">
							<button type="button" class="ace-btn ace-btn-secondary" id="ace-ff-fill-cancel"><?php esc_html_e( 'Cancel', 'agentic-commerce-engine' ); ?></button>
							<button type="button" class="ace-btn ace-btn-primary" id="ace-ff-fill-confirm"><?php esc_html_e( 'Turn on filling', 'agentic-commerce-engine' ); ?></button>
						</div>
					</div>
				</div>
			<?php endif; ?>
		</div>

		<!-- Automation (commerce suite) -->
		<div class="ace-card">
			<h2 class="ace-card-title"><?php esc_html_e( 'Automation', 'agentic-commerce-engine' ); ?></h2>
			<p class="ace-card-sub"><?php esc_html_e( 'Keep your catalog and content moving without manual clicks.', 'agentic-commerce-engine' ); ?></p>

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
				<span><?php esc_html_e( 'Auto-apply suggestions without review', 'agentic-commerce-engine' ); ?></span>
			</label>
			<p class="ace-help ace-indent ace-warn-text"><?php esc_html_e( 'When on, suggested changes go live on your products automatically. You can still revert any product from the Review screen.', 'agentic-commerce-engine' ); ?></p>
			<?php if ( $agencoen_offline ) : ?>
				<p class="ace-help ace-indent"><?php esc_html_e( 'Scheduled sync and auto-apply send products to Rezolve Ai for enrichment, so they need an account. The schema and product-page options below work on their own.', 'agentic-commerce-engine' ); ?></p>
			<?php endif; ?>

			<label class="ace-toggle">
				<input type="checkbox" id="ace-schema-enabled" <?php checked( $data['schema'] ); ?> />
				<span><?php esc_html_e( 'Output Product schema (JSON-LD) on product pages', 'agentic-commerce-engine' ); ?></span>
			</label>

			<label class="ace-toggle">
				<input type="checkbox" id="ace-auto-append" <?php checked( $agencoen_settings->is_auto_append() ); ?> />
				<span><?php esc_html_e( 'Show IRL scenarios, internal links, highlights and Q&A on the product page automatically', 'agentic-commerce-engine' ); ?></span>
			</label>
			<p class="ace-help ace-indent"><?php esc_html_e( 'Or place them yourself with the [agencoen_irl], [agencoen_internal_links], [agencoen_highlights] and [agencoen_product_qa] shortcodes, or the Rezolve Ai editor blocks.', 'agentic-commerce-engine' ); ?></p>

			<?php
			/*
			 * Where each piece of generated content goes (4.25.0).
			 *
			 * Each type can stay in Rezolve Ai's own block (and choose where on
			 * the page it sits, including a product tab of its own), go into one
			 * of this store's fields (an ACF field, a custom field, or the short
			 * description), or become a section of the product description. The
			 * same choice is editable in the Rezolve Ai web app and the Shopify
			 * app; all three read and write one value on the platform, which is
			 * why choosing a destination needs a connection. The block position
			 * alone works offline.
			 */
			$agencoen_placement_labels = array(
				'below_description' => __( 'Below the product description', 'agentic-commerce-engine' ),
				'above_description' => __( 'Above the product description', 'agentic-commerce-engine' ),
				'bottom_of_page'    => __( 'Bottom of the product page', 'agentic-commerce-engine' ),
				'product_tab'       => __( 'In a product tab', 'agentic-commerce-engine' ),
				'manual'            => __( 'Wherever I place it myself', 'agentic-commerce-engine' ),
				'hidden'            => __( 'Do not show it on the page', 'agentic-commerce-engine' ),
			);
			$agencoen_type_labels      = array(
				'product-qa'         => __( 'Product Q&A', 'agentic-commerce-engine' ),
				'highlights'         => __( 'Highlights', 'agentic-commerce-engine' ),
				'irl-cards'          => __( 'IRL scenario cards', 'agentic-commerce-engine' ),
				'internal-links'     => __( 'Internal links', 'agentic-commerce-engine' ),
				'product-attributes' => __( 'Product specifications', 'agentic-commerce-engine' ),
			);
			$agencoen_candidates       = $agencoen_offline ? array() : \Agencoen\Content_Destinations::candidates( $plugin );
			$agencoen_destinations     = $agencoen_offline ? array() : \Agencoen\Content_Destinations::all();
			?>
			<h3 class="ace-subhead"><?php esc_html_e( 'Where content goes', 'agentic-commerce-engine' ); ?></h3>
			<p class="ace-help ace-indent">
				<?php esc_html_e( 'Keep each kind of content in the Rezolve Ai block, send it to one of your own fields, or add it as a section of the product description. A field used here is replaced when content is published, and is no longer read as product information. Revert on the Review screen puts it back. Changes apply to content published from now on.', 'agentic-commerce-engine' ); ?>
			</p>
			<?php if ( $agencoen_offline ) : ?>
				<p class="ace-help ace-indent"><?php esc_html_e( 'Connect to Rezolve Ai to send content to your own fields or into the description. Until then it stays in the Rezolve Ai block.', 'agentic-commerce-engine' ); ?></p>
			<?php endif; ?>
			<?php
			foreach ( \Agencoen\Content_Destinations::TYPES as $agencoen_type ) :
				$agencoen_dest   = isset( $agencoen_destinations[ $agencoen_type ] ) ? $agencoen_destinations[ $agencoen_type ] : array( 'kind' => 'block' );
				$agencoen_choice = 'block';
				if ( 'description' === $agencoen_dest['kind'] ) {
					$agencoen_choice = 'description:' . $agencoen_dest['position'];
				} elseif ( 'field' === $agencoen_dest['kind'] ) {
					$agencoen_choice = 'field:' . $agencoen_dest['field']['id'];
				}
				$agencoen_slug    = str_replace( '-', '_', $agencoen_type );
				$agencoen_label   = isset( $agencoen_type_labels[ $agencoen_type ] ) ? $agencoen_type_labels[ $agencoen_type ] : $agencoen_type;
				$agencoen_placeable = in_array( $agencoen_type, \Agencoen\Settings::placeable_types(), true );
				// A routed type's block is stored as hidden. Offering that back
				// when the merchant returns to the block would save it hidden
				// again, so the position starts at the default instead.
				$agencoen_position = $agencoen_placeable ? $agencoen_settings->get_placement( $agencoen_type ) : '';
				if ( 'block' !== $agencoen_dest['kind'] && 'hidden' === $agencoen_position ) {
					$agencoen_position = 'below_description';
				}
				?>
				<div class="ace-field ace-indent" data-ace-destination-row="<?php echo esc_attr( $agencoen_type ); ?>">
					<label for="<?php echo esc_attr( 'ace-destination-' . $agencoen_type ); ?>"><?php echo esc_html( $agencoen_label ); ?></label>
					<select id="<?php echo esc_attr( 'ace-destination-' . $agencoen_type ); ?>" data-ace-destination="<?php echo esc_attr( $agencoen_slug ); ?>" <?php disabled( $agencoen_offline ); ?>>
						<option value="block" <?php selected( $agencoen_choice, 'block' ); ?>><?php esc_html_e( 'Rezolve Ai block', 'agentic-commerce-engine' ); ?></option>
						<option value="description:end" <?php selected( $agencoen_choice, 'description:end' ); ?>><?php esc_html_e( 'Inside the product description, at the end', 'agentic-commerce-engine' ); ?></option>
						<option value="description:start" <?php selected( $agencoen_choice, 'description:start' ); ?>><?php esc_html_e( 'Inside the product description, at the start', 'agentic-commerce-engine' ); ?></option>
						<?php foreach ( \Agencoen\Content_Destinations::candidates_for( $agencoen_type, $agencoen_candidates ) as $agencoen_candidate ) : ?>
							<option value="<?php echo esc_attr( 'field:' . $agencoen_candidate['id'] ); ?>" <?php selected( $agencoen_choice, 'field:' . $agencoen_candidate['id'] ); ?> translate="no">
								<?php
								/* translators: %s: the name of one of the store's own fields, e.g. "Care guide". */
								echo esc_html( sprintf( __( 'Field: %s', 'agentic-commerce-engine' ), $agencoen_candidate['label'] ) );
								?>
							</option>
						<?php endforeach; ?>
					</select>

					<?php if ( $agencoen_placeable ) : ?>
						<span class="ace-sub-field" data-ace-when="block">
							<label for="<?php echo esc_attr( 'ace-placement-' . $agencoen_type ); ?>"><?php esc_html_e( 'Where the block sits', 'agentic-commerce-engine' ); ?></label>
							<select id="<?php echo esc_attr( 'ace-placement-' . $agencoen_type ); ?>" data-ace-placement="<?php echo esc_attr( $agencoen_type ); ?>">
								<?php foreach ( $agencoen_placement_labels as $agencoen_value => $agencoen_plabel ) : ?>
									<option value="<?php echo esc_attr( $agencoen_value ); ?>" <?php selected( $agencoen_position, $agencoen_value ); ?>><?php echo esc_html( $agencoen_plabel ); ?></option>
								<?php endforeach; ?>
							</select>
						</span>
					<?php else : ?>
						<span class="ace-help" data-ace-when="block"><?php esc_html_e( 'Written as WooCommerce product attributes, which your theme shows under Additional information.', 'agentic-commerce-engine' ); ?></span>
					<?php endif; ?>

					<span class="ace-sub-field" data-ace-when="description">
						<label for="<?php echo esc_attr( 'ace-destination-heading-' . $agencoen_type ); ?>"><?php esc_html_e( 'Section heading (optional)', 'agentic-commerce-engine' ); ?></label>
						<input type="text" id="<?php echo esc_attr( 'ace-destination-heading-' . $agencoen_type ); ?>" data-ace-destination-heading="<?php echo esc_attr( $agencoen_slug ); ?>" maxlength="<?php echo esc_attr( (string) \Agencoen\Content_Destinations::MAX_HEADING ); ?>" value="<?php echo esc_attr( 'description' === $agencoen_dest['kind'] ? $agencoen_dest['heading'] : '' ); ?>" <?php disabled( $agencoen_offline ); ?> />
					</span>
				</div>
				<?php
			endforeach;
			?>
			<?php if ( '' !== $agencoen_seo_label ) : ?>
				<p class="ace-help ace-indent">
					<?php
					printf(
						/* translators: %s: SEO plugin name. */
						esc_html__( 'Detected %s. Rezolve Ai automatically suppresses its schema output when your SEO plugin already emits Product schema, to avoid duplicates.', 'agentic-commerce-engine' ),
						'<strong>' . esc_html( $agencoen_seo_label ) . '</strong>'
					);
					?>
				</p>
			<?php else : ?>
				<?php
				// WordPress has no SEO title or meta description of its own: those
				// fields belong to whichever SEO plugin is installed. Without one
				// there is nowhere to write them, and saying so here is the only
				// chance a merchant gets BEFORE spending a run on copy that cannot
				// land. Enrichment reports the same thing afterwards, per product.
				?>
				<p class="ace-help ace-indent">
					<?php esc_html_e( 'No SEO plugin detected. WordPress has no SEO title or meta description field of its own, so Rezolve Ai cannot write those until you install Yoast SEO, Rank Math, or All in One SEO. Everything else Rezolve Ai generates still applies.', 'agentic-commerce-engine' ); ?>
				</p>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if ( $has_woo ) : ?>
		<?php
		/*
		 * ChatGPT product file (4.34.0). OpenAI's own file format, built and
		 * hosted by Rezolve Ai from the synced catalog; the URL is read from
		 * GET /api/v1/plugin/feeds by settings.js through the
		 * agencoen_openai_feed action. Wording matches the Shopify app's Feeds
		 * screen. "Add in Business Hub" rather than "Live": nothing here can see
		 * whether the merchant registered it there.
		 */
		?>
		<div class="ace-card" id="ace-openai-feed" data-connected="<?php echo $agencoen_offline ? '0' : '1'; ?>">
			<h2 class="ace-card-title">
				<?php esc_html_e( 'ChatGPT product file', 'agentic-commerce-engine' ); ?>
				<span class="ace-chip ace-chip-info"><?php esc_html_e( 'Add in Business Hub', 'agentic-commerce-engine' ); ?></span>
			</h2>
			<p class="ace-card-sub"><?php esc_html_e( 'ChatGPT shopping reads your catalog from the ChatGPT product file. In ChatGPT Business Hub, claim your business and verify your identity, then add this file\'s URL as a hosted URL for your products. ChatGPT reads the whole file from that address, and it always holds your latest enriched catalog, so there is nothing to upload again.', 'agentic-commerce-engine' ); ?></p>
			<?php if ( $agencoen_offline ) : ?>
				<p class="ace-help">
					<?php esc_html_e( 'Rezolve Ai builds this file from your synced catalog, so it needs a connected site.', 'agentic-commerce-engine' ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=agencoen-connection' ) ); ?>"><?php esc_html_e( 'Open Connection', 'agentic-commerce-engine' ); ?></a>
				</p>
			<?php else : ?>
				<div class="ace-field">
					<label class="ace-label" for="ace-openai-feed-url"><?php esc_html_e( 'ChatGPT product file URL', 'agentic-commerce-engine' ); ?></label>
					<div class="ace-copy-row">
						<input type="text" id="ace-openai-feed-url" class="ace-input ace-select-on-click" value="" readonly aria-describedby="ace-openai-feed-status" />
						<button type="button" class="ace-btn ace-btn-secondary ace-btn-sm" id="ace-openai-feed-copy" disabled><?php esc_html_e( 'Copy', 'agentic-commerce-engine' ); ?></button>
					</div>
					<p class="ace-help" id="ace-openai-feed-status" role="status"><?php esc_html_e( 'Reading the URL from Rezolve Ai…', 'agentic-commerce-engine' ); ?></p>
				</div>
			<?php endif; ?>
			<p class="ace-help"><?php esc_html_e( 'The file also names your privacy policy, terms and conditions, and refund and returns pages. While your site is connected, the plugin sends their public addresses to Rezolve Ai with its regular check in, for pages that are published and have text in them. Their text is never sent.', 'agentic-commerce-engine' ); ?></p>
		</div>

		<!-- Channel feeds -->
		<div class="ace-card">
			<h2 class="ace-card-title"><?php esc_html_e( 'Channel feeds', 'agentic-commerce-engine' ); ?></h2>
			<p class="ace-card-sub"><?php esc_html_e( 'When you enrich products with a channel feed type, Rezolve Ai serves the feed at these URLs.', 'agentic-commerce-engine' ); ?></p>
			<?php
			$agencoen_feeds = array(
				'google'    => __( 'Google Merchant', 'agentic-commerce-engine' ),
				'microsoft' => __( 'Microsoft Bing', 'agentic-commerce-engine' ),
				'agentic'   => __( 'Agentic Commerce (other AI agents)', 'agentic-commerce-engine' ),
			);
			foreach ( $agencoen_feeds as $agencoen_ch => $agencoen_ch_label ) :
				?>
				<div class="ace-field">
					<label class="ace-label" for="<?php echo esc_attr( 'ace-feed-url-' . $agencoen_ch ); ?>"><?php echo esc_html( $agencoen_ch_label ); ?></label>
					<input type="text" id="<?php echo esc_attr( 'ace-feed-url-' . $agencoen_ch ); ?>" class="ace-input ace-select-on-click" data-channel="<?php echo esc_attr( $agencoen_ch ); ?>" value="<?php echo esc_attr( \Agencoen\Feeds::feed_url( $agencoen_ch ) ); ?>" readonly />
				</div>
			<?php endforeach; ?>
			<p class="ace-help"><?php esc_html_e( 'The Agentic Commerce feed is a JSON feed for other AI shopping agents. ChatGPT does not read it: give ChatGPT Business Hub the ChatGPT product file above.', 'agentic-commerce-engine' ); ?></p>
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
