<?php
/**
 * Experiments: the list, a five-step create wizard, and a per-experiment
 * cockpit. Every panel is filled by assets/js/screens/experiments.js; nothing
 * here is rendered server-side because none of it lives on this site.
 *
 * ACE owns the experiment, its arms and its result. This site owns only the
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
		<div class="ace-notice ace-notice-info">
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
		<button type="button" class="ace-tab" data-tab="new">
			<?php esc_html_e( 'New experiment', 'agentic-commerce-engine' ); ?>
		</button>
	</div>

	<!-- ── The list ─────────────────────────────────────────────────────── -->
	<div class="ace-panel" data-panel="list">
		<div class="ace-toolbar">
			<div class="ace-toolbar-actions">
				<button type="button" class="ace-btn ace-btn-sm" id="ace-exp-refresh">
					<?php esc_html_e( 'Refresh', 'agentic-commerce-engine' ); ?>
				</button>
			</div>
		</div>
		<div id="ace-exp-list" class="ace-list" aria-live="polite"></div>
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
		<form id="ace-exp-form" class="ace-form">

			<fieldset class="ace-fieldset">
				<legend><?php esc_html_e( '1. What to split', 'agentic-commerce-engine' ); ?></legend>
				<label class="ace-radio">
					<input type="radio" name="type" value="visitor_split" checked />
					<span><?php esc_html_e( 'Split shoppers. Some see one version of your product copy and some see another, and you compare what they bought.', 'agentic-commerce-engine' ); ?></span>
				</label>
				<label class="ace-radio">
					<input type="radio" name="type" value="product_holdout" />
					<span><?php esc_html_e( 'Split products. Some products get new copy and some are held back unchanged, and you compare how the pages performed.', 'agentic-commerce-engine' ); ?></span>
				</label>
			</fieldset>

			<fieldset class="ace-fieldset">
				<legend><?php esc_html_e( '2. Which products', 'agentic-commerce-engine' ); ?></legend>
				<label for="ace-exp-category"><?php esc_html_e( 'Category', 'agentic-commerce-engine' ); ?></label>
				<select id="ace-exp-category" name="category" class="ace-input"></select>
				<p class="description">
					<?php esc_html_e( 'A test needs enough products behind it to tell a real change from noise, so pick a whole category rather than a handful.', 'agentic-commerce-engine' ); ?>
				</p>
			</fieldset>

			<fieldset class="ace-fieldset">
				<legend><?php esc_html_e( '3. How many versions', 'agentic-commerce-engine' ); ?></legend>
				<label for="ace-exp-levels"><?php esc_html_e( 'Versions to compare', 'agentic-commerce-engine' ); ?></label>
				<select id="ace-exp-levels" name="levels" class="ace-input">
					<option value="2"><?php esc_html_e( '2, your copy and one challenger', 'agentic-commerce-engine' ); ?></option>
					<option value="3">3</option>
					<option value="4">4</option>
				</select>
				<p class="description">
					<?php esc_html_e( 'Every extra version splits your shoppers further, so more versions need more traffic to reach an answer.', 'agentic-commerce-engine' ); ?>
				</p>
			</fieldset>

			<fieldset class="ace-fieldset">
				<legend><?php esc_html_e( '4. What to measure', 'agentic-commerce-engine' ); ?></legend>
				<label for="ace-exp-goal"><?php esc_html_e( 'Goal', 'agentic-commerce-engine' ); ?></label>
				<select id="ace-exp-goal" name="goal" class="ace-input"></select>
				<label for="ace-exp-days"><?php esc_html_e( 'Days to run', 'agentic-commerce-engine' ); ?></label>
				<input type="number" id="ace-exp-days" name="days" class="ace-input" value="28" min="7" max="180" />
			</fieldset>

			<fieldset class="ace-fieldset">
				<legend><?php esc_html_e( '5. Check and launch', 'agentic-commerce-engine' ); ?></legend>
				<label for="ace-exp-name"><?php esc_html_e( 'Name this test', 'agentic-commerce-engine' ); ?></label>
				<input type="text" id="ace-exp-name" name="name" class="ace-input" maxlength="120" />

				<div id="ace-exp-verdict" class="ace-verdict" hidden aria-live="polite"></div>

				<label class="ace-checkbox" id="ace-exp-accept-slow" hidden>
					<input type="checkbox" name="acceptSlow" />
					<span><?php esc_html_e( 'Start it anyway, knowing it will take longer than planned', 'agentic-commerce-engine' ); ?></span>
				</label>

				<div class="ace-toolbar-actions">
					<button type="button" class="ace-btn ace-btn-sm" id="ace-exp-check">
						<?php esc_html_e( 'Check this design', 'agentic-commerce-engine' ); ?>
					</button>
					<button type="button" class="ace-btn ace-btn-primary ace-btn-sm" id="ace-exp-launch" disabled>
						<?php esc_html_e( 'Write the versions and launch', 'agentic-commerce-engine' ); ?>
					</button>
				</div>

				<p class="description">
					<?php esc_html_e( 'Launching writes the new versions with AI, which uses credits. Nothing is shown to a visitor until you approve it in Review and it goes live on your site.', 'agentic-commerce-engine' ); ?>
				</p>
			</fieldset>
		</form>
	</div>
</div>
