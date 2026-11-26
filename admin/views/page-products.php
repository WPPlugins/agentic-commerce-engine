<?php
/**
 * Products: search, filter, sort, and bulk-enrich at catalog scale. All rows
 * are fetched and rendered by assets/js/screens/products.js.
 *
 * @package Agencoen
 * @var Plugin $plugin    Orchestrator.
 * @var bool   $connected Store connected.
 * @var bool   $has_woo   Whether WooCommerce is active.
 * @var array  $data      Dashboard payload.
 */

defined( 'ABSPATH' ) || exit;

/*
 * Browsing, filtering and exporting the catalog is all local. Only enrichment
 * itself is sent to Rezolve Ai, so only those two buttons need a connection.
 */
$agencoen_offline = empty( $connected );

$agencoen_chips = array(
	'all'          => __( 'All', 'agentic-commerce-engine' ),
	'not_enriched' => __( 'Not enriched', 'agentic-commerce-engine' ),
	'queued'       => __( 'Queued', 'agentic-commerce-engine' ),
	'enriched'     => __( 'Ready', 'agentic-commerce-engine' ),
	'applied'      => __( 'Applied', 'agentic-commerce-engine' ),
	'failed'       => __( 'Failed', 'agentic-commerce-engine' ),
);
?>
<div id="ace-products" class="ace-products">

	<!-- Status filter tabs -->
	<div class="ace-tabs" id="ace-status-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Filter by enrichment status', 'agentic-commerce-engine' ); ?>">
		<?php foreach ( $agencoen_chips as $agencoen_key => $agencoen_label ) : ?>
			<button type="button" class="ace-tab<?php echo 'all' === $agencoen_key ? ' is-active' : ''; ?>" id="<?php echo esc_attr( 'ace-status-tab-' . $agencoen_key ); ?>" role="tab" aria-selected="<?php echo 'all' === $agencoen_key ? 'true' : 'false'; ?>" aria-controls="ace-products-panel" tabindex="<?php echo 'all' === $agencoen_key ? '0' : '-1'; ?>" data-status="<?php echo esc_attr( $agencoen_key ); ?>">
				<?php echo esc_html( $agencoen_label ); ?>
				<span class="ace-tab-count" data-count="<?php echo esc_attr( $agencoen_key ); ?>"></span>
			</button>
		<?php endforeach; ?>
	</div>

	<!-- Toolbar -->
	<div class="ace-toolbar">
		<label class="screen-reader-text" for="ace-search"><?php esc_html_e( 'Search products by name or SKU', 'agentic-commerce-engine' ); ?></label>
		<input type="search" id="ace-search" class="ace-input ace-search" placeholder="<?php esc_attr_e( 'Search by name or SKU', 'agentic-commerce-engine' ); ?>" autocomplete="off" />
		<label class="screen-reader-text" for="ace-category"><?php esc_html_e( 'Category', 'agentic-commerce-engine' ); ?></label>
		<select id="ace-category" class="ace-select ace-input-sm">
			<option value=""><?php esc_html_e( 'All categories', 'agentic-commerce-engine' ); ?></option>
			<?php foreach ( $plugin->repository()->categories() as $agencoen_cat ) : ?>
				<option value="<?php echo esc_attr( $agencoen_cat['slug'] ); ?>"><?php echo esc_html( $agencoen_cat['name'] . ' (' . $agencoen_cat['count'] . ')' ); ?></option>
			<?php endforeach; ?>
		</select>
		<label class="screen-reader-text" for="ace-sort"><?php esc_html_e( 'Sort by', 'agentic-commerce-engine' ); ?></label>
		<select id="ace-sort" class="ace-select ace-input-sm">
			<option value="recent"><?php esc_html_e( 'Recently updated', 'agentic-commerce-engine' ); ?></option>
			<option value="name"><?php esc_html_e( 'Name (A to Z)', 'agentic-commerce-engine' ); ?></option>
			<option value="score"><?php esc_html_e( 'Highest score', 'agentic-commerce-engine' ); ?></option>
		</select>
		<div class="ace-toolbar-actions">
			<a class="ace-btn ace-btn-secondary ace-btn-sm" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-ajax.php?action=agencoen_export' ), \Agencoen\Ajax::NONCE_ACTION, 'nonce' ) ); ?>"><?php esc_html_e( 'Export CSV', 'agentic-commerce-engine' ); ?></a>
			<button type="button" class="ace-btn ace-btn-primary ace-btn-sm" id="ace-enrich-all" <?php disabled( $agencoen_offline ); ?>><?php esc_html_e( 'Enrich all', 'agentic-commerce-engine' ); ?></button>
		</div>
	</div>

	<!-- Selection bar -->
	<div class="ace-selbar" id="ace-selbar" hidden>
		<span id="ace-sel-count"></span>
		<button type="button" class="ace-link-sm" id="ace-select-all-matching"></button>
		<span class="ace-selbar-spacer"></span>
		<button type="button" class="ace-btn ace-btn-primary ace-btn-sm" id="ace-enrich-selected" <?php disabled( $agencoen_offline ); ?>><?php esc_html_e( 'Enrich selected', 'agentic-commerce-engine' ); ?></button>
		<button type="button" class="ace-link-sm" id="ace-clear-sel"><?php esc_html_e( 'Clear', 'agentic-commerce-engine' ); ?></button>
	</div>

	<!-- Batch progress -->
	<div class="ace-batch" id="ace-batch" hidden>
		<div class="ace-batch-bar" role="progressbar" aria-label="<?php esc_attr_e( 'Enrichment progress', 'agentic-commerce-engine' ); ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span class="ace-batch-fill" id="ace-batch-fill"></span></div>
		<span class="ace-batch-text" id="ace-batch-text" role="status" aria-live="polite"></span>
	</div>

	<!-- Product table -->
	<div class="ace-card ace-table-card" id="ace-products-panel" role="tabpanel" aria-labelledby="ace-status-tab-all">
		<div class="ace-table-scroll">
			<table class="ace-table">
				<caption class="screen-reader-text"><?php esc_html_e( 'Products', 'agentic-commerce-engine' ); ?></caption>
				<thead>
					<tr>
						<th scope="col" class="ace-col-check"><input type="checkbox" id="ace-select-all" aria-label="<?php esc_attr_e( 'Select all rows on this page', 'agentic-commerce-engine' ); ?>" /></th>
						<th scope="col" class="ace-col-img"><span class="screen-reader-text"><?php esc_html_e( 'Image', 'agentic-commerce-engine' ); ?></span></th>
						<th scope="col"><?php esc_html_e( 'Product', 'agentic-commerce-engine' ); ?></th>
						<th scope="col"><?php esc_html_e( 'SKU', 'agentic-commerce-engine' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'agentic-commerce-engine' ); ?></th>
						<th scope="col" class="ace-col-score"><?php esc_html_e( 'Score', 'agentic-commerce-engine' ); ?></th>
					</tr>
				</thead>
				<tbody id="ace-products-tbody">
					<tr><td colspan="6" class="ace-loading"><?php esc_html_e( 'Loading products', 'agentic-commerce-engine' ); ?>&hellip;</td></tr>
				</tbody>
			</table>
		</div>
		<div class="ace-pagination">
			<?php // Pagination buttons start disabled and are enabled by products.js; unrelated to the Rezolve Ai account. ?>
			<button type="button" class="ace-btn ace-btn-secondary ace-btn-sm" id="ace-prev-page" disabled><?php esc_html_e( 'Previous', 'agentic-commerce-engine' ); ?></button>
			<span id="ace-page-info" class="ace-muted"></span>
			<button type="button" class="ace-btn ace-btn-secondary ace-btn-sm" id="ace-next-page" disabled><?php esc_html_e( 'Next', 'agentic-commerce-engine' ); ?></button>
		</div>
	</div>

	<!-- Content-type picker -->
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
</div>
