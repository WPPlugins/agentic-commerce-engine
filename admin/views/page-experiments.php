<?php
/**
 * Experiments: the list, a five-step create wizard, and a per-experiment
 * cockpit. Every panel is filled by assets/js/screens/experiments.js; nothing
 * here is rendered server-side because none of it lives on this site.
 *
 * Rezolve Ai owns the experiment, its arms and its result. This site owns only the
 * per-product config it serves, which Ab_Test stores and reconciles. So the
 * screen is a view onto the platform, and a stale local copy is exactly the
 * failure it must not have: a test stopped last week still showing as running.
 *
 * @package Agencoen
 * @var Plugin $plugin  Orchestrator.
 * @var bool   $has_woo Whether WooCommerce is active.
 * @var array  $data    Dashboard payload.
 */

defined( 'ABSPATH' ) || exit;

$agencoen_ab_on = $plugin->settings()->is_ab_enabled();
?>
<div id="ace-experiments" class="ace-experiments">

	<?php if ( ! $agencoen_ab_on ) : ?>
		<?php // Not an error. Splitting visitors is off by default on every site, ?>
		<?php // including upgrades, and it stays off until someone turns it on. ?>
		<div class="ace-banner ace-banner-info">
			<p>
				<?php esc_html_e( 'Splitting visitors is switched off for this site, so a test can be set up here but nobody will be shown a different version.', 'agentic-commerce-engine' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=agencoen-settings' ) ); ?>">
					<?php esc_html_e( 'Turn it on in Settings', 'agentic-commerce-engine' ); ?>
				</a>
			</p>
		</div>
	<?php endif; ?>

	<div class="ace-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Experiments', 'agentic-commerce-engine' ); ?>">
		<button type="button" class="ace-tab is-active" data-tab="list">
			<?php esc_html_e( 'Your tests', 'agentic-commerce-engine' ); ?>
		</button>
		<button type="button" class="ace-tab" data-tab="history">
			<?php esc_html_e( 'History', 'agentic-commerce-engine' ); ?>
		</button>
		<button type="button" class="ace-tab" data-tab="new">
			<?php esc_html_e( 'New experiment', 'agentic-commerce-engine' ); ?>
		</button>
	</div>

	<!-- ── The list ─────────────────────────────────────────────────────── -->
	<div class="ace-panel" data-panel="list">
		<h2 class="screen-reader-text"><?php esc_html_e( 'Your tests', 'agentic-commerce-engine' ); ?></h2>
		<div class="ace-toolbar">
			<div class="ace-toolbar-actions">
				<button type="button" class="ace-btn ace-btn-sm" id="ace-exp-refresh">
					<?php esc_html_e( 'Refresh', 'agentic-commerce-engine' ); ?>
				</button>
			</div>
		</div>
		<div id="ace-exp-list" aria-live="polite"></div>
	</div>

	<!-- ── History: finished tests, and what testing has been worth ─────── -->
	<?php // Nothing server-rendered here either. The ledger lives on Rezolve Ai, and a ?>
	<?php // stale local copy of "which version won" is the one thing this      ?>
	<?php // screen must never show.                                            ?>
	<div class="ace-panel" data-panel="history" hidden>
		<h2 class="screen-reader-text"><?php esc_html_e( 'History', 'agentic-commerce-engine' ); ?></h2>
		<div id="ace-exp-value" aria-live="polite"></div>
		<div class="ace-toolbar">
			<label class="ace-label" for="ace-exp-history-search">
				<span class="screen-reader-text">
					<?php esc_html_e( 'Search tests by name', 'agentic-commerce-engine' ); ?>
				</span>
				<input
					type="search"
					id="ace-exp-history-search"
					class="ace-input"
					placeholder="<?php esc_attr_e( 'Search by name', 'agentic-commerce-engine' ); ?>"
				/>
			</label>
		</div>
		<div id="ace-exp-history-facets"></div>
		<div id="ace-exp-history" aria-live="polite"></div>
	</div>

	<!-- ── The cockpit, shown when a test is opened ─────────────────────── -->
	<div class="ace-panel" data-panel="detail" hidden>
		<button type="button" class="ace-btn ace-btn-sm" id="ace-exp-back">
			<?php esc_html_e( 'All experiments', 'agentic-commerce-engine' ); ?>
		</button>
		<div id="ace-exp-detail" aria-live="polite"></div>
	</div>

	<!-- ── The wizard ───────────────────────────────────────────────────── -->
	<div class="ace-panel" data-panel="new" hidden>
		<h2 class="screen-reader-text"><?php esc_html_e( 'New experiment', 'agentic-commerce-engine' ); ?></h2>
		<form id="ace-exp-form">

			<fieldset class="ace-card">
				<legend class="ace-card-title"><?php esc_html_e( '1. What to split', 'agentic-commerce-engine' ); ?></legend>
				<label class="ace-label">
					<input type="radio" name="type" value="visitor_split" checked />
					<span><?php esc_html_e( 'Split shoppers. Some see one version of your product copy and some see another, and you compare what they bought.', 'agentic-commerce-engine' ); ?></span>
				</label>
				<label class="ace-label">
					<input type="radio" name="type" value="product_holdout" />
					<span><?php esc_html_e( 'Split products. Some products get the new content and some are held back unchanged, and you compare how they sold.', 'agentic-commerce-engine' ); ?></span>
				</label>
			</fieldset>

			<fieldset class="ace-card">
				<legend class="ace-card-title"><?php esc_html_e( '2. Which products', 'agentic-commerce-engine' ); ?></legend>

				<?php // Containers only. Every one of these is filled by experiments.js, as with every other panel on this screen. ?>
				<div id="ace-exp-groups-row" hidden>
					<label for="ace-exp-group"><?php esc_html_e( 'Start from a saved group', 'agentic-commerce-engine' ); ?></label>
					<select id="ace-exp-group" class="ace-input"></select>
				</div>

				<label class="ace-label">
					<input type="checkbox" id="ace-exp-everything" />
					<span id="ace-exp-everything-label"><?php esc_html_e( 'Every product', 'agentic-commerce-engine' ); ?></span>
				</label>

				<div id="ace-exp-facets"></div>

				<details id="ace-exp-cohort-box">
					<summary><?php esc_html_e( 'Describe them instead', 'agentic-commerce-engine' ); ?></summary>
					<label for="ace-exp-cohort"><?php esc_html_e( 'Describe the products you want to test', 'agentic-commerce-engine' ); ?></label>
					<textarea id="ace-exp-cohort" class="ace-input" rows="2" maxlength="300"></textarea>
					<p>
						<button type="button" class="button" id="ace-exp-cohort-go"><?php esc_html_e( 'Find products', 'agentic-commerce-engine' ); ?></button>
					</p>
					<div id="ace-exp-cohort-result"></div>
				</details>

				<details id="ace-exp-picker-box">
					<summary><?php esc_html_e( 'Review and adjust', 'agentic-commerce-engine' ); ?></summary>
					<label for="ace-exp-picker-search"><?php esc_html_e( 'Search your catalogue to add a product', 'agentic-commerce-engine' ); ?></label>
					<input type="search" id="ace-exp-picker-search" class="ace-input" />
					<div id="ace-exp-picker-list"></div>
				</details>

				<p id="ace-exp-subject-summary" class="description" aria-live="polite"></p>

				<p>
					<label class="screen-reader-text" for="ace-exp-group-name"><?php esc_html_e( 'Name this group', 'agentic-commerce-engine' ); ?></label>
					<input type="text" id="ace-exp-group-name" class="ace-input" maxlength="60" placeholder="<?php esc_attr_e( 'Name this group', 'agentic-commerce-engine' ); ?>" />
					<button type="button" class="button" id="ace-exp-group-save"><?php esc_html_e( 'Save as group', 'agentic-commerce-engine' ); ?></button>
				</p>
			</fieldset>

			<fieldset class="ace-card">
				<legend class="ace-card-title"><?php esc_html_e( '3. What to test', 'agentic-commerce-engine' ); ?></legend>
				<?php
				/*
				 * Filled by the screen script from the platform's own list, with
				 * the reason beside anything this split cannot test. This form
				 * used to test the description and nothing else.
				 */
				?>
				<div id="ace-exp-content" aria-live="polite"></div>
				<p class="description" id="ace-exp-content-note" hidden>
					<?php esc_html_e( 'Each piece of content you choose goes live on the products in the test and stays off the ones held back. Test one at a time to measure each on its own: content that goes live together cannot be told apart.', 'agentic-commerce-engine' ); ?>
				</p>
			</fieldset>

			<fieldset class="ace-card">
				<legend class="ace-card-title"><?php esc_html_e( '4. How many versions', 'agentic-commerce-engine' ); ?></legend>
				<label for="ace-exp-levels"><?php esc_html_e( 'Versions to write', 'agentic-commerce-engine' ); ?></label>
				<select id="ace-exp-levels" name="levels" class="ace-input">
					<option value="1"><?php esc_html_e( '1, your house style against unchanged products', 'agentic-commerce-engine' ); ?></option>
					<option value="2" selected><?php esc_html_e( '2, your copy and one challenger', 'agentic-commerce-engine' ); ?></option>
					<option value="3">3</option>
					<option value="4">4</option>
				</select>
				<p class="description">
					<?php esc_html_e( 'Every extra version needs more traffic to reach an answer. This applies to the description and meta tags; other content is written once, in your house style.', 'agentic-commerce-engine' ); ?>
				</p>
			</fieldset>

			<fieldset class="ace-card">
				<legend class="ace-card-title"><?php esc_html_e( '5. What to measure', 'agentic-commerce-engine' ); ?></legend>
				<label for="ace-exp-goal"><?php esc_html_e( 'Goal', 'agentic-commerce-engine' ); ?></label>
				<select id="ace-exp-goal" name="goal" class="ace-input"></select>
				<p class="description" id="ace-exp-goal-detail"></p>
				<div id="ace-exp-secondary"></div>
				<label for="ace-exp-days"><?php esc_html_e( 'Days to run', 'agentic-commerce-engine' ); ?></label>
				<input type="number" id="ace-exp-days" name="days" class="ace-input" value="28" min="7" max="180" />
				<?php // A product split only. Shown and hidden by experiments.js with the split type. ?>
				<div id="ace-exp-holdout" hidden>
					<p><strong><?php esc_html_e( 'How many to hold back', 'agentic-commerce-engine' ); ?></strong></p>
					<label class="ace-label">
						<input type="radio" name="holdoutFraction" value="auto" checked />
						<span><strong><?php esc_html_e( 'Choose for me', 'agentic-commerce-engine' ); ?></strong><br />
						<span class="description"><?php esc_html_e( 'Half when these products get few visits, a fifth when they get plenty.', 'agentic-commerce-engine' ); ?></span></span>
					</label>
					<label class="ace-label">
						<input type="radio" name="holdoutFraction" value="0.5" />
						<span><strong><?php esc_html_e( 'Half', 'agentic-commerce-engine' ); ?></strong><br />
						<span class="description"><?php esc_html_e( 'The clearest answer from the same products.', 'agentic-commerce-engine' ); ?></span></span>
					</label>
					<label class="ace-label">
						<input type="radio" name="holdoutFraction" value="0.2" />
						<span><strong><?php esc_html_e( 'About a fifth', 'agentic-commerce-engine' ); ?></strong><br />
						<span class="description"><?php esc_html_e( 'Keeps more products on the new content while the test runs, and needs more visits to give a clear answer.', 'agentic-commerce-engine' ); ?></span></span>
					</label>
				</div>
			</fieldset>

			<fieldset class="ace-card">
				<legend class="ace-card-title"><?php esc_html_e( '6. Check and launch', 'agentic-commerce-engine' ); ?></legend>
				<label for="ace-exp-name"><?php esc_html_e( 'Name this test', 'agentic-commerce-engine' ); ?></label>
				<input type="text" id="ace-exp-name" name="name" class="ace-input" maxlength="120" />

				<div id="ace-exp-verdict" hidden aria-live="polite"></div>

				<?php
				/*
				 * The smallest change this design could spot, against how long
				 * it runs. Filled by the screen script from the platform's own
				 * power check, so the picture and the verdict above it cannot
				 * disagree. Hidden until there is a curve to draw: an empty axis
				 * would read as a measurement of nothing.
				 */
				?>
				<div class="ace-chart" id="ace-exp-sizing" hidden></div>
				<p class="ace-card-sub" id="ace-exp-sizing-note" hidden>
					<?php esc_html_e( 'Lower is better. Fewer versions, or a longer run, both move this line down.', 'agentic-commerce-engine' ); ?>
				</p>

				<label class="ace-toggle" id="ace-exp-accept-slow" hidden>
					<input type="checkbox" name="acceptSlow" />
					<span><?php esc_html_e( 'Start it anyway, knowing it will take longer than planned', 'agentic-commerce-engine' ); ?></span>
				</label>

				<div class="ace-toolbar-actions">
					<button type="button" class="ace-btn ace-btn-sm" id="ace-exp-check">
						<?php esc_html_e( 'Check this design', 'agentic-commerce-engine' ); ?>
					</button>
					<button type="button" class="ace-btn ace-btn-primary ace-btn-sm" id="ace-exp-launch" disabled>
						<?php esc_html_e( 'Write the content and launch', 'agentic-commerce-engine' ); ?>
					</button>
				</div>

				<p class="description">
					<?php esc_html_e( 'Launching writes the content, which uses credits. Nothing is shown to a visitor until you approve it in Review and it goes live on your site.', 'agentic-commerce-engine' ); ?>
				</p>
			</fieldset>
		</form>
	</div>
</div>
