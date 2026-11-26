<?php
/**
 * Review: paginated, searchable, lazy-diff approval queue. Lists are fetched
 * and rendered by assets/js/screens/review.js; the pending/applied panels are
 * SegmentedTabs wired through agencoen.core.tabs (hash-synced).
 *
 * @package Agencoen
 * @var Plugin $plugin  Orchestrator.
 * @var bool   $has_woo Whether WooCommerce is active.
 * @var array  $data    Dashboard payload.
 */

defined( 'ABSPATH' ) || exit;

$agencoen_counts = $data['counts'];
?>
<div id="ace-review" class="ace-review">

	<div class="ace-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Review queues', 'agentic-commerce-engine' ); ?>">
		<button type="button" class="ace-tab is-active" data-tab="pending">
			<?php esc_html_e( 'Pending', 'agentic-commerce-engine' ); ?>
			<span class="ace-tab-count" id="ace-count-pending"><?php echo esc_html( number_format_i18n( $agencoen_counts['pendingReview'] ) ); ?></span>
		</button>
		<button type="button" class="ace-tab" data-tab="applied">
			<?php esc_html_e( 'Applied', 'agentic-commerce-engine' ); ?>
			<span class="ace-tab-count" id="ace-count-applied"><?php echo esc_html( number_format_i18n( $agencoen_counts['applied'] ) ); ?></span>
		</button>
	</div>

	<div class="ace-toolbar">
		<label class="screen-reader-text" for="ace-review-search"><?php esc_html_e( 'Search pending products by name or SKU', 'agentic-commerce-engine' ); ?></label>
		<input type="search" id="ace-review-search" class="ace-input ace-search" placeholder="<?php esc_attr_e( 'Search by name or SKU', 'agentic-commerce-engine' ); ?>" autocomplete="off" />
		<div class="ace-toolbar-actions">
			<button type="button" class="ace-btn ace-btn-primary ace-btn-sm" id="ace-approve-all"><?php esc_html_e( 'Approve all', 'agentic-commerce-engine' ); ?></button>
		</div>
	</div>

	<div class="ace-batch" id="ace-batch" hidden>
		<div class="ace-batch-bar" role="progressbar" aria-label="<?php esc_attr_e( 'Approval progress', 'agentic-commerce-engine' ); ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span class="ace-batch-fill" id="ace-batch-fill"></span></div>
		<span class="ace-batch-text" id="ace-batch-text" role="status" aria-live="polite"></span>
	</div>

	<div class="ace-tabpanel" data-panel="pending">
		<h2 class="screen-reader-text"><?php esc_html_e( 'Products waiting for review', 'agentic-commerce-engine' ); ?></h2>
		<div id="ace-review-list" class="ace-review-list">
			<div class="ace-loading"><?php esc_html_e( 'Loading', 'agentic-commerce-engine' ); ?>&hellip;</div>
		</div>
		<div class="ace-loadmore"><button type="button" class="ace-btn ace-btn-secondary ace-btn-sm" id="ace-review-more" hidden><?php esc_html_e( 'Load more', 'agentic-commerce-engine' ); ?></button></div>
	</div>

	<div class="ace-tabpanel" data-panel="applied" hidden>
		<h2 class="screen-reader-text"><?php esc_html_e( 'Products with applied content', 'agentic-commerce-engine' ); ?></h2>
		<div id="ace-applied-list" class="ace-review-list"></div>
		<div class="ace-loadmore"><button type="button" class="ace-btn ace-btn-secondary ace-btn-sm" id="ace-applied-more" hidden><?php esc_html_e( 'Load more', 'agentic-commerce-engine' ); ?></button></div>
	</div>
</div>
