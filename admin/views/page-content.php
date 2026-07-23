<?php
/**
 * Content screen: generate AI articles, resume project briefs, and manage the
 * WordPress posts created from them. All data loads via ajax
 * (assets/js/screens/content.js); this file is the static skeleton.
 *
 * @package Agencoen
 * @var Plugin $plugin    Orchestrator.
 * @var bool   $connected Store connected.
 * @var bool   $has_woo   WooCommerce active.
 * @var array  $data      Dashboard payload.
 */

defined( 'ABSPATH' ) || exit;

$agencoen_content_status = $plugin->settings()->get_content_status();

/*
 * Generating articles and resuming project briefs run on the ACE service, so
 * those controls need a connection. The Published tab is a plain WP_Query over
 * this site's own posts (Articles::local_posts()) and always works.
 */
$agencoen_offline = empty( $connected );
?>
<div id="agencoen-content" class="ace-content-screen" data-content-status="<?php echo esc_attr( $agencoen_content_status ); ?>">

	<div class="ace-toolbar">
		<div class="ace-stat-grid" id="agencoen-content-stats"></div>
		<button type="button" class="ace-btn ace-btn-primary" id="agencoen-content-new" <?php disabled( $agencoen_offline ); ?>><?php esc_html_e( 'New article', 'agentic-commerce-engine' ); ?></button>
	</div>

	<div class="ace-tabs" id="agencoen-content-tabs" role="tablist">
		<button type="button" class="ace-tab is-active" data-tab="generate"><?php esc_html_e( 'Generate', 'agentic-commerce-engine' ); ?></button>
		<button type="button" class="ace-tab" data-tab="briefs"><?php esc_html_e( 'Briefs', 'agentic-commerce-engine' ); ?></button>
		<button type="button" class="ace-tab" data-tab="published"><?php esc_html_e( 'Published', 'agentic-commerce-engine' ); ?></button>
	</div>

	<!-- Generate -->
	<div data-panel="generate">
		<div class="ace-grid-2">
			<div class="ace-card">
				<h2 class="ace-card-title"><?php esc_html_e( 'New article', 'agentic-commerce-engine' ); ?></h2>

				<?php if ( $agencoen_offline ) : ?>
					<p class="ace-help"><?php esc_html_e( 'Writing an article is done by a language model on ACE servers, so this form needs an ACE account. The Published tab below works without one.', 'agentic-commerce-engine' ); ?></p>
				<?php endif; ?>

				<div id="agencoen-content-brand-note" class="ace-help"></div>

				<form id="agencoen-content-form">
					<div class="ace-field">
						<label class="ace-label" for="agencoen-content-title"><?php esc_html_e( 'Title', 'agentic-commerce-engine' ); ?></label>
						<input type="text" class="ace-input" id="agencoen-content-title" maxlength="300" placeholder="<?php esc_attr_e( 'What should the article be about?', 'agentic-commerce-engine' ); ?>" required />
					</div>

					<div class="ace-field">
						<label class="ace-label" for="agencoen-content-type"><?php esc_html_e( 'Content type', 'agentic-commerce-engine' ); ?></label>
						<select class="ace-select" id="agencoen-content-type"></select>
						<p class="ace-help" id="agencoen-content-type-hint"></p>
					</div>

					<div id="agencoen-content-category" class="ace-grid-2" hidden>
						<div class="ace-field">
							<label class="ace-label" for="agencoen-content-category-name"><?php esc_html_e( 'Category name', 'agentic-commerce-engine' ); ?></label>
							<input type="text" class="ace-input" id="agencoen-content-category-name" placeholder="<?php esc_attr_e( 'Running shoes', 'agentic-commerce-engine' ); ?>" />
						</div>
						<div class="ace-field">
							<label class="ace-label" for="agencoen-content-category-path"><?php esc_html_e( 'Category path', 'agentic-commerce-engine' ); ?></label>
							<input type="text" class="ace-input" id="agencoen-content-category-path" placeholder="<?php esc_attr_e( 'Shoes > Running', 'agentic-commerce-engine' ); ?>" />
						</div>
					</div>

					<div class="ace-field">
						<label class="ace-label" for="agencoen-content-keywords"><?php esc_html_e( 'Target keywords', 'agentic-commerce-engine' ); ?></label>
						<input type="text" class="ace-input" id="agencoen-content-keywords" placeholder="<?php esc_attr_e( 'Comma separated, optional', 'agentic-commerce-engine' ); ?>" />
					</div>

					<div class="ace-field">
						<label class="ace-label" for="agencoen-content-overview"><?php esc_html_e( 'Overview', 'agentic-commerce-engine' ); ?></label>
						<textarea class="ace-textarea" id="agencoen-content-overview" rows="4" maxlength="4000" placeholder="<?php esc_attr_e( 'Optional: angle, audience, or anything the writer should know.', 'agentic-commerce-engine' ); ?>"></textarea>
					</div>

					<div class="ace-grid-2">
						<div class="ace-field">
							<label class="ace-label" for="agencoen-content-tone"><?php esc_html_e( 'Tone', 'agentic-commerce-engine' ); ?></label>
							<input type="text" class="ace-input" id="agencoen-content-tone" placeholder="<?php esc_attr_e( 'Optional, e.g. friendly expert', 'agentic-commerce-engine' ); ?>" />
						</div>
						<div class="ace-field">
							<label class="ace-label" for="agencoen-content-wordcount"><?php esc_html_e( 'Word count', 'agentic-commerce-engine' ); ?></label>
							<input type="number" class="ace-input" id="agencoen-content-wordcount" min="100" max="8000" step="50" placeholder="<?php esc_attr_e( 'Auto', 'agentic-commerce-engine' ); ?>" />
						</div>
					</div>

					<div class="ace-field">
						<label class="ace-label" for="agencoen-content-locale"><?php esc_html_e( 'Language', 'agentic-commerce-engine' ); ?></label>
						<input type="text" class="ace-input" id="agencoen-content-locale" placeholder="<?php esc_attr_e( 'auto', 'agentic-commerce-engine' ); ?>" />
					</div>

					<button type="submit" class="ace-btn ace-btn-primary" id="agencoen-content-generate" <?php disabled( $agencoen_offline ); ?>><?php esc_html_e( 'Generate article', 'agentic-commerce-engine' ); ?></button>
				</form>
			</div>

			<div class="ace-card" id="agencoen-content-progress" hidden>
				<h2 class="ace-card-title"><?php esc_html_e( 'Generation', 'agentic-commerce-engine' ); ?></h2>

				<div id="agencoen-content-progress-live">
					<div id="agencoen-content-ring"></div>
					<p class="ace-help" id="agencoen-content-phase"><?php esc_html_e( 'Starting the writer.', 'agentic-commerce-engine' ); ?></p>
				</div>

				<div id="agencoen-content-result" hidden>
					<h3 id="agencoen-content-result-title"></h3>
					<p class="ace-help" id="agencoen-content-result-meta"></p>
					<div class="ace-field">
						<span class="ace-label"><?php esc_html_e( 'SEO title', 'agentic-commerce-engine' ); ?></span>
						<p id="agencoen-content-result-seotitle"></p>
					</div>
					<div class="ace-field">
						<span class="ace-label"><?php esc_html_e( 'SEO description', 'agentic-commerce-engine' ); ?></span>
						<p id="agencoen-content-result-seodesc"></p>
					</div>
					<div class="ace-toolbar">
						<a class="ace-btn ace-btn-secondary" id="agencoen-content-result-edit" href="#" target="_blank" rel="noopener"><?php esc_html_e( 'Open in editor', 'agentic-commerce-engine' ); ?></a>
						<button type="button" class="ace-btn ace-btn-primary" id="agencoen-content-result-publish"><?php esc_html_e( 'Publish now', 'agentic-commerce-engine' ); ?></button>
						<button type="button" class="ace-btn ace-btn-danger" id="agencoen-content-result-discard"><?php esc_html_e( 'Discard', 'agentic-commerce-engine' ); ?></button>
					</div>
				</div>
			</div>
		</div>
	</div>

	<!-- Briefs -->
	<div data-panel="briefs" hidden>
		<div class="ace-card">
			<?php if ( $agencoen_offline ) : ?>
				<p class="ace-help"><?php esc_html_e( 'Briefs are stored in your ACE project, so this list is empty without an account. Articles already in WordPress are on the Published tab.', 'agentic-commerce-engine' ); ?></p>
			<?php endif; ?>
			<div class="ace-toolbar">
				<select class="ace-select" id="agencoen-content-briefs-status" <?php disabled( $agencoen_offline ); ?>>
					<option value="all"><?php esc_html_e( 'All statuses', 'agentic-commerce-engine' ); ?></option>
					<option value="draft"><?php esc_html_e( 'Ready to publish', 'agentic-commerce-engine' ); ?></option>
					<option value="published"><?php esc_html_e( 'Published', 'agentic-commerce-engine' ); ?></option>
				</select>
				<input type="search" class="ace-input" id="agencoen-content-briefs-search" placeholder="<?php esc_attr_e( 'Search briefs', 'agentic-commerce-engine' ); ?>" <?php disabled( $agencoen_offline ); ?> />
			</div>
			<div id="agencoen-content-briefs-table"></div>
			<div class="ace-toolbar" id="agencoen-content-briefs-pagination"></div>
		</div>
	</div>

	<!-- Published -->
	<div data-panel="published" hidden>
		<div class="ace-card">
			<h2 class="ace-card-title"><?php esc_html_e( 'Articles in WordPress', 'agentic-commerce-engine' ); ?></h2>
			<div id="agencoen-content-posts-table"></div>
			<div class="ace-toolbar" id="agencoen-content-posts-pagination"></div>
		</div>
	</div>

</div>
