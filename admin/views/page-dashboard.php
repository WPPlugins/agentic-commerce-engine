<?php
/**
 * Dashboard: KPI stat row, charts grid, quick actions. Stat tiles and charts
 * are rendered client-side by assets/js/screens/dashboard.js from the inline
 * window.agencoenBoot payload; this shell provides skeletons, the quick
 * actions, and the review-pending banner.
 *
 * @package Agencoen
 * @var Plugin $plugin    Orchestrator.
 * @var bool   $connected Store connected.
 * @var bool   $has_woo   Whether WooCommerce is active.
 * @var array  $data      Dashboard payload.
 */

defined( 'ABSPATH' ) || exit;

$agencoen_counts  = $data['counts'];
$agencoen_credits = $data['credits'];

/*
 * The crawler spark and the local article counts read this site's own tables,
 * so they render either way. Credits and share-of-answers come from Rezolve Ai.
 */
$agencoen_offline = empty( $connected );

/*
 * A chart card's heading and its help button. The button sits beside the
 * heading rather than inside it, so the heading reads as the chart's title
 * alone, and it is named after its chart: a screen reader listing the
 * buttons on this screen would otherwise hear "Help" six times.
 */
$agencoen_chart_head = static function ( $title, $help ) {
	$label = /* translators: %s: the title of a dashboard chart, for example "Optimization coverage". */
		__( 'About %s', 'agentic-commerce-engine' );
	printf(
		'<div class="ace-chart-head"><h2 class="ace-h3">%1$s</h2><button type="button" class="ace-help-icon" data-help="%2$s" aria-label="%3$s">?</button></div>',
		esc_html( $title ),
		esc_attr( $help ),
		esc_attr( sprintf( $label, $title ) )
	);
};
?>
<div id="ace-dashboard" class="ace-dashboard">

	<?php if ( $agencoen_offline && ! \Agencoen\Settings::start_card_dismissed() ) : ?>
		<?php include AGENCOEN_PLUGIN_DIR . 'admin/views/partial-start-here.php'; ?>
	<?php endif; ?>

	<?php if ( $has_woo && $agencoen_counts['pendingReview'] > 0 ) : ?>
		<div class="ace-banner ace-banner-warning ace-review-cta">
			<span>
				<?php
				printf(
					/* translators: %s: number of products. */
					esc_html( _n( '%s product is ready to review.', '%s products are ready to review.', $agencoen_counts['pendingReview'], 'agentic-commerce-engine' ) ),
					'<strong>' . esc_html( number_format_i18n( $agencoen_counts['pendingReview'] ) ) . '</strong>'
				);
				?>
			</span>
			<a class="ace-btn ace-btn-secondary ace-btn-sm" href="<?php echo esc_url( admin_url( 'admin.php?page=agencoen-review' ) ); ?>"><?php esc_html_e( 'Review now', 'agentic-commerce-engine' ); ?></a>
		</div>
	<?php endif; ?>

	<?php // KPI stat row (rendered by dashboard.js). Without an account there is no credits tile, so the grid drops to four columns. ?>
	<div id="ace-stat-row" class="ace-stat-grid<?php echo $agencoen_offline ? '' : ' cols-5'; ?>">
		<div class="ace-skeleton ace-skeleton-tile"></div>
		<div class="ace-skeleton ace-skeleton-tile"></div>
		<div class="ace-skeleton ace-skeleton-tile"></div>
		<div class="ace-skeleton ace-skeleton-tile"></div>
		<?php if ( ! $agencoen_offline ) : ?>
			<div class="ace-skeleton ace-skeleton-tile"></div>
		<?php endif; ?>
	</div>

	<!-- Quick actions -->
	<div class="ace-actions-bar">
		<?php if ( $has_woo ) : ?>
			<button type="button" class="ace-btn ace-btn-primary ace-btn-sm" id="ace-sync-now" <?php disabled( $agencoen_offline ); ?>><?php esc_html_e( 'Sync now', 'agentic-commerce-engine' ); ?></button>
			<button type="button" class="ace-btn ace-btn-secondary ace-btn-sm" id="ace-enrich-all-dash" <?php disabled( $agencoen_offline ); ?>><?php esc_html_e( 'Enrich all', 'agentic-commerce-engine' ); ?></button>
			<a class="ace-btn ace-btn-secondary ace-btn-sm" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-ajax.php?action=agencoen_export' ), \Agencoen\Ajax::NONCE_ACTION, 'nonce' ) ); ?>"><?php esc_html_e( 'Export CSV', 'agentic-commerce-engine' ); ?></a>
		<?php else : ?>
			<a class="ace-btn ace-btn-primary ace-btn-sm" href="<?php echo esc_url( admin_url( 'admin.php?page=agencoen-content' ) ); ?>"><?php esc_html_e( 'Generate an article', 'agentic-commerce-engine' ); ?></a>
			<a class="ace-btn ace-btn-secondary ace-btn-sm" href="<?php echo esc_url( admin_url( 'admin.php?page=agencoen-aisearch' ) ); ?>"><?php esc_html_e( 'Set up AI research', 'agentic-commerce-engine' ); ?></a>
		<?php endif; ?>
		<button type="button" class="ace-btn ace-btn-secondary ace-btn-sm" id="ace-refresh-dash"><?php esc_html_e( 'Refresh', 'agentic-commerce-engine' ); ?></button>
		<div class="ace-batch" id="ace-batch" hidden>
			<div class="ace-batch-bar" role="progressbar" aria-label="<?php esc_attr_e( 'Enrichment progress', 'agentic-commerce-engine' ); ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span class="ace-batch-fill" id="ace-batch-fill"></span></div>
			<span class="ace-batch-text" id="ace-batch-text" role="status" aria-live="polite"></span>
		</div>
	</div>

	<!-- Charts -->
	<div class="ace-charts-grid">
		<?php if ( $has_woo ) : ?>
			<div class="ace-card ace-chart-card">
				<?php $agencoen_chart_head( __( 'Optimization coverage', 'agentic-commerce-engine' ), __( 'How your catalog breaks down by enrichment status. Click a slice to filter Products.', 'agentic-commerce-engine' ) ); ?>
				<div id="ace-chart-coverage" class="ace-chart"><div class="ace-skeleton ace-skeleton-block ace-skeleton-chart"></div></div>
			</div>
			<div class="ace-card ace-chart-card">
				<?php $agencoen_chart_head( __( 'Quality score distribution', 'agentic-commerce-engine' ), __( 'Quality scores measured by Rezolve Ai for products with content waiting for review or applied, grouped from 0 to 100. Products with nothing measured yet are counted as Not scored.', 'agentic-commerce-engine' ) ); ?>
				<div id="ace-chart-scores" class="ace-chart"><div class="ace-skeleton ace-skeleton-block ace-skeleton-chart"></div></div>
			</div>
			<div class="ace-card ace-chart-card ace-chart-wide">
				<?php $agencoen_chart_head( __( 'Products optimized in the last 14 days', 'agentic-commerce-engine' ), __( 'Number of products you applied new content to each day over the last 14 days.', 'agentic-commerce-engine' ) ); ?>
				<div id="ace-chart-activity" class="ace-chart"><div class="ace-skeleton ace-skeleton-block ace-skeleton-chart"></div></div>
			</div>
		<?php endif; ?>

		<div class="ace-card ace-chart-card">
			<?php $agencoen_chart_head( __( 'AI bot hits, last 7 days', 'agentic-commerce-engine' ), __( 'Visits from AI crawlers such as GPTBot, ClaudeBot, and PerplexityBot, tracked natively on this site.', 'agentic-commerce-engine' ) ); ?>
			<div class="ace-spark-row">
				<span class="ace-spark-num" id="ace-bots-total">0</span>
				<div id="ace-spark-bots" class="ace-chart ace-spark-chart"></div>
			</div>
			<p class="ace-help">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=agencoen-aisearch' ) ); ?>"><?php esc_html_e( 'See the full crawler breakdown', 'agentic-commerce-engine' ); ?></a>
			</p>
		</div>

		<?php if ( ! $agencoen_offline ) : ?>
			<div class="ace-card ace-chart-card">
				<?php $agencoen_chart_head( __( 'Credits', 'agentic-commerce-engine' ), __( 'Your Rezolve Ai service balance. Generating content for one product or one content type uses about one credit.', 'agentic-commerce-engine' ) ); ?>
				<div id="ace-chart-credits" class="ace-chart"></div>
				<p class="ace-muted ace-center-note" id="ace-credits-caption">
					<?php
					printf(
						/* translators: 1: credits used, 2: credit limit. */
						esc_html__( '%1$s used of %2$s', 'agentic-commerce-engine' ),
						'<strong id="ace-credits-used-note">' . esc_html( number_format_i18n( $agencoen_credits['used'] ) ) . '</strong>',
						'<span id="ace-credits-cap-note">' . esc_html( number_format_i18n( max( $agencoen_credits['limit'], $agencoen_credits['balance'] + $agencoen_credits['used'] ) ) ) . '</span>'
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<div class="ace-card ace-chart-card ace-chart-wide" id="ace-card-sov" hidden>
			<?php $agencoen_chart_head( __( 'Your brand in AI answers', 'agentic-commerce-engine' ), __( 'The share of AI answers that name your brand in each AI Search research run.', 'agentic-commerce-engine' ) ); ?>
			<div id="ace-chart-sov" class="ace-chart"></div>
			<p class="ace-help">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=agencoen-aisearch' ) ); ?>"><?php esc_html_e( 'Open AI Search', 'agentic-commerce-engine' ); ?></a>
			</p>
		</div>
	</div>

	<?php if ( $has_woo ) : ?>
		<!-- Content-type picker (used by the Enrich all quick action) -->
		<div class="ace-modal" id="ace-enrich-modal" hidden>
			<div class="ace-modal-backdrop" id="ace-modal-backdrop"></div>
			<div class="ace-modal-card" role="dialog" aria-modal="true" aria-labelledby="ace-modal-title" aria-describedby="ace-modal-scope">
				<h2 class="ace-h2" id="ace-modal-title"><?php esc_html_e( 'Choose what to enrich', 'agentic-commerce-engine' ); ?></h2>
				<p class="ace-muted" id="ace-modal-scope"></p>
				<div id="ace-types-list" class="ace-types-list"></div>
				<div id="ace-modal-error" class="ace-banner ace-banner-error" role="alert" style="display:none;"></div>
				<div class="ace-modal-actions">
					<button type="button" class="ace-btn ace-btn-secondary" id="ace-modal-cancel"><?php esc_html_e( 'Cancel', 'agentic-commerce-engine' ); ?></button>
					<button type="button" class="ace-btn ace-btn-primary" id="ace-modal-confirm"><?php esc_html_e( 'Enrich now', 'agentic-commerce-engine' ); ?></button>
				</div>
			</div>
		</div>
	<?php endif; ?>
</div>
