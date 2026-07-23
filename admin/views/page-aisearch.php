<?php
/**
 * AI Search: research console (Overview, Prompts, Competitors, Runs) plus the
 * native AI crawler analytics (Crawlers). The markup here is a stable skeleton
 * that assets/js/screens/aisearch.js hydrates over admin-ajax.
 *
 * The Crawlers tab works on local data alone (no connection needed). The four
 * research tabs need a connected store and the server-side ecomAeo feature.
 *
 * @package Agencoen
 * @var Plugin $plugin    Orchestrator.
 * @var bool   $connected Whether the store is connected to ACE.
 * @var bool   $has_woo   Whether WooCommerce is active (unused here).
 * @var array  $data      Dashboard payload (unused here).
 */

defined( 'ABSPATH' ) || exit;

$agencoen_aeo_enabled    = $plugin->settings()->feature_enabled( 'ecomAeo' );
$agencoen_research_ready = $connected && $agencoen_aeo_enabled;
$agencoen_default_tab    = $agencoen_research_ready ? 'overview' : 'crawlers';
$agencoen_research_tabs  = array(
	'overview'    => __( 'Overview', 'agentic-commerce-engine' ),
	'prompts'     => __( 'Prompts', 'agentic-commerce-engine' ),
	'competitors' => __( 'Competitors', 'agentic-commerce-engine' ),
);
$agencoen_all_tabs       = $agencoen_research_tabs + array(
	'crawlers' => __( 'Crawlers', 'agentic-commerce-engine' ),
	'runs'     => __( 'Runs', 'agentic-commerce-engine' ),
);
// Tab order: overview | prompts | competitors | crawlers | runs.
$agencoen_tab_order = array( 'overview', 'prompts', 'competitors', 'crawlers', 'runs' );
?>
<div id="agencoen-aisearch" class="agencoen-aisearch" data-default-tab="<?php echo esc_attr( $agencoen_default_tab ); ?>">

	<div class="ace-tabs" role="tablist">
		<?php foreach ( $agencoen_tab_order as $agencoen_tab_key ) : ?>
			<button
				type="button"
				class="ace-tab<?php echo esc_attr( $agencoen_tab_key === $agencoen_default_tab ? ' is-active' : '' ); ?>"
				data-tab="<?php echo esc_attr( $agencoen_tab_key ); ?>"
				role="tab"
				aria-selected="<?php echo esc_attr( $agencoen_tab_key === $agencoen_default_tab ? 'true' : 'false' ); ?>"
			><?php echo esc_html( $agencoen_all_tabs[ $agencoen_tab_key ] ); ?></button>
		<?php endforeach; ?>
	</div>

	<?php if ( ! $agencoen_research_ready ) : ?>

		<?php foreach ( array( 'overview', 'prompts', 'competitors', 'runs' ) as $agencoen_tab_key ) : ?>
			<section data-panel="<?php echo esc_attr( $agencoen_tab_key ); ?>" <?php echo esc_attr( $agencoen_tab_key === $agencoen_default_tab ? '' : 'hidden' ); ?>>
				<?php if ( ! $agencoen_aeo_enabled ) : ?>
					<div class="ace-card ace-empty">
						<h2 class="ace-card-title"><?php esc_html_e( 'AI Search research is not part of this ACE plan', 'agentic-commerce-engine' ); ?></h2>
						<p><?php esc_html_e( 'Research runs query AI answer engines on ACE servers, and that capability is not included on the ACE account this site is linked to. The Crawlers tab is unaffected: it tracks AI crawler visits directly on this site and needs no account at all.', 'agentic-commerce-engine' ); ?></p>
					</div>
				<?php else : ?>
					<div class="ace-card ace-empty">
						<h2 class="ace-card-title"><?php esc_html_e( 'AI Search research runs on ACE servers', 'agentic-commerce-engine' ); ?></h2>
						<p><?php esc_html_e( 'Track how AI assistants answer questions about your products, which brands they recommend, and which pages they cite. Querying those engines is work this site cannot do by itself, so it needs an ACE account. The Crawlers tab works without one.', 'agentic-commerce-engine' ); ?></p>
						<a class="ace-btn ace-btn-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=agencoen-connection' ) ); ?>"><?php esc_html_e( 'Open Connection', 'agentic-commerce-engine' ); ?></a>
					</div>
				<?php endif; ?>
			</section>
		<?php endforeach; ?>

	<?php else : ?>

		<!-- Overview ------------------------------------------------------- -->
		<section data-panel="overview" <?php echo esc_attr( 'overview' === $agencoen_default_tab ? '' : 'hidden' ); ?>>
			<div id="agencoen-aeo-overview-empty" hidden>
				<div class="ace-card ace-empty">
					<h2 class="ace-card-title"><?php esc_html_e( 'No research results yet', 'agentic-commerce-engine' ); ?></h2>
					<p><?php esc_html_e( 'Launch your first research run to see how often AI assistants mention your brand and which competitors they recommend instead.', 'agentic-commerce-engine' ); ?></p>
					<button type="button" class="ace-btn ace-btn-primary" id="agencoen-aeo-goto-runs"><?php esc_html_e( 'Go to Runs', 'agentic-commerce-engine' ); ?></button>
				</div>
			</div>
			<div id="agencoen-aeo-overview-body">
				<div class="ace-stat-grid" id="agencoen-aeo-overview-stats">
					<div class="ace-skeleton"></div>
				</div>
				<div class="ace-grid-2">
					<div class="ace-card">
						<h2 class="ace-card-title"><?php esc_html_e( 'Share of answers over time', 'agentic-commerce-engine' ); ?></h2>
						<div class="ace-chart" id="agencoen-aeo-trend"><div class="ace-skeleton"></div></div>
					</div>
					<div class="ace-card">
						<h2 class="ace-card-title"><?php esc_html_e( 'Brand presence in AI answers', 'agentic-commerce-engine' ); ?></h2>
						<div class="ace-chart" id="agencoen-aeo-presence"><div class="ace-skeleton"></div></div>
					</div>
					<div class="ace-card">
						<h2 class="ace-card-title"><?php esc_html_e( 'Most cited domains', 'agentic-commerce-engine' ); ?></h2>
						<div class="ace-chart" id="agencoen-aeo-domains"><div class="ace-skeleton"></div></div>
					</div>
					<div class="ace-card">
						<h2 class="ace-card-title"><?php esc_html_e( 'Most mentioned brands', 'agentic-commerce-engine' ); ?></h2>
						<div class="ace-chart" id="agencoen-aeo-entities"><div class="ace-skeleton"></div></div>
					</div>
				</div>
			</div>
		</section>

		<!-- Prompts -------------------------------------------------------- -->
		<section data-panel="prompts" hidden>
			<div class="ace-card">
				<h2 class="ace-card-title"><?php esc_html_e( 'Research prompts', 'agentic-commerce-engine' ); ?></h2>
				<p class="ace-help"><?php esc_html_e( 'These are the questions ACE asks the AI engines on every run. Add or replace prompts from the Runs tab.', 'agentic-commerce-engine' ); ?></p>
				<div class="ace-toolbar">
					<input
						type="search"
						class="ace-input"
						id="agencoen-aeo-prompt-search"
						placeholder="<?php esc_attr_e( 'Filter prompts', 'agentic-commerce-engine' ); ?>"
						aria-label="<?php esc_attr_e( 'Filter prompts', 'agentic-commerce-engine' ); ?>"
					/>
				</div>
				<div id="agencoen-aeo-prompts-table"><div class="ace-skeleton"></div></div>
			</div>
		</section>

		<!-- Competitors ---------------------------------------------------- -->
		<section data-panel="competitors" hidden>
			<div class="ace-card">
				<h2 class="ace-card-title"><?php esc_html_e( 'Brands AI assistants recommend', 'agentic-commerce-engine' ); ?></h2>
				<p class="ace-help"><?php esc_html_e( 'How often each brand appears across all AI answers in the latest run. Your brand is highlighted.', 'agentic-commerce-engine' ); ?></p>
				<div class="ace-chart" id="agencoen-aeo-competitors-chart"><div class="ace-skeleton"></div></div>
			</div>
			<div class="ace-card">
				<h2 class="ace-card-title"><?php esc_html_e( 'Mention counts', 'agentic-commerce-engine' ); ?></h2>
				<div id="agencoen-aeo-competitors-table"><div class="ace-skeleton"></div></div>
			</div>
		</section>

		<!-- Runs ------------------------------------------------------------ -->
		<section data-panel="runs" hidden>
			<div class="ace-card" id="agencoen-aeo-run-config">
				<h2 class="ace-card-title"><?php esc_html_e( 'Research configuration', 'agentic-commerce-engine' ); ?></h2>

				<p class="ace-help" id="agencoen-aeo-config-note" hidden></p>

				<div id="agencoen-aeo-flow-form" hidden>
					<p><?php esc_html_e( 'Create a research flow to start tracking your brand in AI answers. This takes a few seconds and only happens once.', 'agentic-commerce-engine' ); ?></p>
					<div class="ace-grid-2">
						<div class="ace-field">
							<label class="ace-label" for="agencoen-aeo-flow-name"><?php esc_html_e( 'Flow name', 'agentic-commerce-engine' ); ?></label>
							<input type="text" class="ace-input" id="agencoen-aeo-flow-name" placeholder="<?php esc_attr_e( 'My store research', 'agentic-commerce-engine' ); ?>" />
						</div>
						<div class="ace-field">
							<label class="ace-label" for="agencoen-aeo-flow-brand"><?php esc_html_e( 'Brand name', 'agentic-commerce-engine' ); ?></label>
							<input type="text" class="ace-input" id="agencoen-aeo-flow-brand" placeholder="<?php esc_attr_e( 'Your brand as customers know it', 'agentic-commerce-engine' ); ?>" />
						</div>
					</div>
					<button type="button" class="ace-btn ace-btn-primary" id="agencoen-aeo-flow-create"><?php esc_html_e( 'Create research flow', 'agentic-commerce-engine' ); ?></button>
				</div>

				<div id="agencoen-aeo-run-form" hidden>
					<div class="ace-field">
						<label class="ace-label" for="agencoen-aeo-prompts-input"><?php esc_html_e( 'Prompts (one per line)', 'agentic-commerce-engine' ); ?></label>
						<textarea
							class="ace-textarea"
							id="agencoen-aeo-prompts-input"
							rows="6"
							placeholder="<?php esc_attr_e( 'What are the best waterproof hiking boots?', 'agentic-commerce-engine' ); ?>"
						></textarea>
						<p class="ace-help" id="agencoen-aeo-promptcount"></p>
					</div>

					<div class="ace-toolbar">
						<input
							type="text"
							class="ace-input"
							id="agencoen-aeo-suggest-topic"
							placeholder="<?php esc_attr_e( 'Topic for suggestions (optional)', 'agentic-commerce-engine' ); ?>"
							aria-label="<?php esc_attr_e( 'Topic for suggestions', 'agentic-commerce-engine' ); ?>"
						/>
						<label class="ace-label" for="agencoen-aeo-branded">
							<input type="checkbox" id="agencoen-aeo-branded" />
							<?php esc_html_e( 'Include branded prompts', 'agentic-commerce-engine' ); ?>
						</label>
						<button type="button" class="ace-btn ace-btn-secondary ace-btn-sm" id="agencoen-aeo-suggest"><?php esc_html_e( 'Suggest prompts', 'agentic-commerce-engine' ); ?></button>
					</div>

					<div class="ace-toolbar">
						<label class="ace-label">
							<input type="radio" name="agencoen_aeo_prompt_mode" value="append" checked />
							<?php esc_html_e( 'Append to saved prompts', 'agentic-commerce-engine' ); ?>
						</label>
						<label class="ace-label">
							<input type="radio" name="agencoen_aeo_prompt_mode" value="replace" />
							<?php esc_html_e( 'Replace saved prompts', 'agentic-commerce-engine' ); ?>
						</label>
						<button type="button" class="ace-btn ace-btn-secondary ace-btn-sm" id="agencoen-aeo-prompts-save"><?php esc_html_e( 'Save prompts', 'agentic-commerce-engine' ); ?></button>
					</div>

					<div class="ace-grid-3">
						<div class="ace-field">
							<label class="ace-label" for="agencoen-aeo-models"><?php esc_html_e( 'AI engines', 'agentic-commerce-engine' ); ?></label>
							<select class="ace-select" id="agencoen-aeo-models" multiple size="6"></select>
							<p class="ace-help"><?php esc_html_e( 'Hold Ctrl or Cmd to pick more than one engine. Leave empty to use the flow defaults.', 'agentic-commerce-engine' ); ?></p>
						</div>
						<div class="ace-field">
							<label class="ace-label" for="agencoen-aeo-region"><?php esc_html_e( 'Region', 'agentic-commerce-engine' ); ?></label>
							<select class="ace-select" id="agencoen-aeo-region"></select>
						</div>
						<div class="ace-field">
							<label class="ace-label" for="agencoen-aeo-language"><?php esc_html_e( 'Language', 'agentic-commerce-engine' ); ?></label>
							<select class="ace-select" id="agencoen-aeo-language"></select>
						</div>
					</div>

					<div class="ace-toolbar">
						<button type="button" class="ace-btn ace-btn-secondary" id="agencoen-aeo-estimate"><?php esc_html_e( 'Estimate credits', 'agentic-commerce-engine' ); ?></button>
						<button type="button" class="ace-btn ace-btn-primary" id="agencoen-aeo-launch"><?php esc_html_e( 'Launch run', 'agentic-commerce-engine' ); ?></button>
					</div>
				</div>
			</div>

			<div class="ace-card">
				<h2 class="ace-card-title"><?php esc_html_e( 'Runs', 'agentic-commerce-engine' ); ?></h2>
				<div id="agencoen-aeo-runs-table"><div class="ace-skeleton"></div></div>
			</div>
		</section>

	<?php endif; ?>

	<!-- Crawlers (always live: local data only) --------------------------- -->
	<section data-panel="crawlers" <?php echo esc_attr( 'crawlers' === $agencoen_default_tab ? '' : 'hidden' ); ?>>

		<?php if ( ! $plugin->settings()->is_bots_enabled() ) : ?>
			<div class="ace-card">
				<p class="ace-help"><?php esc_html_e( 'AI crawler tracking is currently turned off, so new visits are not being recorded. You can turn it back on under Settings.', 'agentic-commerce-engine' ); ?></p>
			</div>
		<?php endif; ?>

		<div class="ace-toolbar">
			<label class="ace-label" for="agencoen-aeo-bots-range"><?php esc_html_e( 'Range', 'agentic-commerce-engine' ); ?></label>
			<select class="ace-select" id="agencoen-aeo-bots-range">
				<option value="7"><?php esc_html_e( 'Last 7 days', 'agentic-commerce-engine' ); ?></option>
				<option value="30" selected><?php esc_html_e( 'Last 30 days', 'agentic-commerce-engine' ); ?></option>
				<option value="90"><?php esc_html_e( 'Last 90 days', 'agentic-commerce-engine' ); ?></option>
			</select>
			<label class="ace-toggle">
				<input type="checkbox" id="agencoen-aeo-bots-report" <?php checked( $plugin->settings()->is_bots_report() ); ?> <?php disabled( ! $connected ); ?> />
				<span><?php esc_html_e( 'Send daily summary to ACE', 'agentic-commerce-engine' ); ?></span>
			</label>
			<button type="button" class="ace-btn ace-btn-secondary ace-btn-sm" id="agencoen-aeo-bots-send" <?php disabled( ! $connected ); ?>><?php esc_html_e( 'Send now', 'agentic-commerce-engine' ); ?></button>
		</div>

		<div class="ace-stat-grid" id="agencoen-aeo-bots-stats">
			<div class="ace-skeleton"></div>
		</div>

		<div class="ace-grid-2">
			<div class="ace-card">
				<h2 class="ace-card-title"><?php esc_html_e( 'Crawler visits over time', 'agentic-commerce-engine' ); ?></h2>
				<div class="ace-chart" id="agencoen-aeo-bots-trend"><div class="ace-skeleton"></div></div>
			</div>
			<div class="ace-card">
				<h2 class="ace-card-title"><?php esc_html_e( 'Hits by crawler', 'agentic-commerce-engine' ); ?></h2>
				<div class="ace-chart" id="agencoen-aeo-bots-bybot"><div class="ace-skeleton"></div></div>
			</div>
		</div>

		<div class="ace-card" id="agencoen-aeo-bots-pages-card">
			<h2 class="ace-card-title"><?php esc_html_e( 'Most crawled pages', 'agentic-commerce-engine' ); ?></h2>
			<div id="agencoen-aeo-bots-pages"><div class="ace-skeleton"></div></div>
		</div>

		<div class="ace-card">
			<h2 class="ace-card-title"><?php esc_html_e( 'Daily activity by crawler', 'agentic-commerce-engine' ); ?></h2>
			<div class="ace-chart" id="agencoen-aeo-bots-heatmap"><div class="ace-skeleton"></div></div>
		</div>

		<p class="ace-help">
			<?php esc_html_e( 'Counts are a lower bound: page caches and CDNs can serve crawlers without reaching WordPress. For privacy, only the date, crawler name, path, and hit count are stored. IP addresses and full user agents are never recorded.', 'agentic-commerce-engine' ); ?>
		</p>
	</section>

</div>
