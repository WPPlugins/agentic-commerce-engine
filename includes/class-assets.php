<?php
/**
 * Enqueues the v4 admin bundle on Rezolve Ai screens only: the design-system
 * stylesheet, the shared runtime (ace-core.js), the SVG chart kit
 * (ace-charts.js), and exactly one per-screen script from assets/js/screens/.
 * Localizes agencoenConfig on the core script and inlines the dashboard boot
 * payload (window.agencoenBoot) on the Dashboard page.
 *
 * @package Agencoen
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Assets
 */
class Assets {

	/**
	 * Admin page slug => screen script (assets/js/screens/{screen}.js).
	 *
	 * @var array<string,string>
	 */
	const SCREENS = array(
		'agencoen'            => 'dashboard',
		'agencoen-products'   => 'products',
		'agencoen-review'     => 'review',
		'agencoen-content'    => 'content',
		'agencoen-aisearch'   => 'aisearch',
		'agencoen-experiments' => 'experiments',
		'agencoen-settings'   => 'settings',
		'agencoen-connection' => 'connection',
	);

	/** @var Plugin */
	private $plugin;

	/**
	 * @param Plugin $plugin Orchestrator.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Hook enqueue.
	 */
	public function register() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue assets on Rezolve Ai screens only.
	 *
	 * @param string $hook_suffix Admin page hook suffix passed to admin_enqueue_scripts.
	 */
	public function enqueue( $hook_suffix ) {
		$screen = $this->screen_for_hook( (string) $hook_suffix );
		if ( '' === $screen ) {
			return;
		}

		// Inter is bundled locally via @font-face in admin.css (no external request).
		wp_enqueue_style(
			'agencoen-admin',
			AGENCOEN_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			AGENCOEN_VERSION
		);

		wp_enqueue_script(
			'agencoen-core',
			AGENCOEN_PLUGIN_URL . 'assets/js/ace-core.js',
			array(),
			AGENCOEN_VERSION,
			true
		);

		wp_enqueue_script(
			'agencoen-charts',
			AGENCOEN_PLUGIN_URL . 'assets/js/ace-charts.js',
			array(),
			AGENCOEN_VERSION,
			true
		);

		$screen_deps = array( 'agencoen-core', 'agencoen-charts' );
		// The Custom fields table's ordering, filtering and paging rules, a port
		// of the platform's (see the file header). Settings only.
		if ( 'settings' === $screen ) {
			wp_enqueue_script(
				'agencoen-custom-field-listing',
				AGENCOEN_PLUGIN_URL . 'assets/js/custom-field-listing.js',
				array( 'agencoen-core' ),
				AGENCOEN_VERSION,
				true
			);
			$screen_deps[] = 'agencoen-custom-field-listing';
			// The Field formats card's words, a port of the platform's (4.28.0).
			wp_enqueue_script(
				'agencoen-field-format-listing',
				AGENCOEN_PLUGIN_URL . 'assets/js/field-format-listing.js',
				array( 'agencoen-core' ),
				AGENCOEN_VERSION,
				true
			);
			$screen_deps[] = 'agencoen-field-format-listing';
		}

		wp_enqueue_script(
			'agencoen-screen-' . $screen,
			AGENCOEN_PLUGIN_URL . 'assets/js/screens/' . $screen . '.js',
			$screen_deps,
			AGENCOEN_VERSION,
			true
		);

		// The classic editor (TinyMCE and Quicktags) for editing a product
		// description in place on Review (4.18.0). wp_enqueue_editor() loads
		// what `wp.editor.initialize()` needs; the `wp-editor` script handle is
		// the block editor's and is deliberately not used. Review only, so no
		// other screen pays for an editor it never opens.
		if ( 'review' === $screen && function_exists( 'wp_enqueue_editor' ) ) {
			wp_enqueue_editor();
		}

		wp_localize_script( 'agencoen-core', 'agencoenConfig', $this->config( $screen ) );

		// The dashboard renders its stat row + charts from this initial
		// payload (no extra round-trip). Attached before the core script so
		// it exists ahead of any consumer. Sent regardless of connection state:
		// the crawler summary and local article counts in it are read from this
		// site's own tables and are useful before an account is linked.
		if ( 'dashboard' === $screen ) {
			wp_add_inline_script(
				'agencoen-core',
				'window.agencoenBoot = ' . wp_json_encode( Dashboard::data( $this->plugin ) ) . ';',
				'before'
			);
		}
	}

	/**
	 * Map an admin_enqueue_scripts hook suffix to one of our screen keys,
	 * using the same derivation core used when the pages were registered.
	 *
	 * @param string $hook Hook suffix passed to admin_enqueue_scripts.
	 * @return string Screen key, or '' when this is not a Rezolve Ai screen.
	 */
	private function screen_for_hook( $hook ) {
		foreach ( self::SCREENS as $slug => $screen ) {
			if ( get_plugin_page_hookname( $slug, Admin::SLUG ) === $hook ) {
				return $screen;
			}
		}
		return '';
	}

	/**
	 * The agencoenConfig payload shared by every screen script.
	 *
	 * @param string $screen Screen key (dashboard|products|review|content|aisearch|settings|connection).
	 * @return array
	 */
	private function config( $screen ) {
		$settings = $this->plugin->settings();

		$config = array(
			'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
			'nonce'         => wp_create_nonce( Ajax_Base::NONCE_ACTION ),
			'screen'        => $screen,
			'hasWoo'        => $this->plugin->has_woo(),
			'connected'     => $settings->is_connected(),
			'features'      => $settings->get_features(),
			'contentTypes'  => $this->content_types_for_js(),
			'selectedTypes' => $settings->get_content_types(),
			'adminUrl'      => admin_url( 'admin.php' ),
			'baseUrl'       => $settings->get_base_url(),
			'pollInterval'  => 5000,
			'i18n'          => $this->strings(),
		);

		if ( 'review' === $screen ) {
			// For the editors' counters only. The server checks every limit again.
			$config['editLimits'] = Edit_Values::limits();
		}

		if ( 'aisearch' === $screen ) {
			// Lets "Most cited domains" mark this site's own domain.
			$config['siteHost'] = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		}

		if ( 'settings' === $screen ) {
			$config['settings'] = array(
				'contentStatus' => $settings->get_content_status(),
				'contentFormat' => $settings->get_content_format(),
				'contentAuthor' => $settings->get_content_author(),
				'articleSchema' => $settings->is_article_schema(),
				'botsEnabled'   => $settings->is_bots_enabled(),
				'botsPaths'     => $settings->is_bots_paths(),
				'botsRetention' => $settings->get_bots_retention(),
				'botsReport'    => $settings->is_bots_report(),
				'autoSync'      => $settings->is_auto_sync(),
				'syncFrequency' => $settings->get_sync_frequency(),
				'autoApply'     => $settings->is_auto_apply(),
				'schemaEnabled' => $settings->is_schema_enabled(),
				'autoAppend'    => $settings->is_auto_append(),
				'debugLog'      => $settings->is_debug_log(),
			);
			$config['authors']  = $this->authors_for_js();
		}

		return $config;
	}

	/**
	 * Users eligible as the default article author.
	 *
	 * @return array<int,array{id:int,name:string}>
	 */
	private function authors_for_js() {
		$users = get_users(
			array(
				'capability' => 'edit_posts',
				'number'     => 50,
				'fields'     => array( 'ID', 'display_name' ),
			)
		);
		$out = array();
		foreach ( $users as $user ) {
			$out[] = array(
				'id'   => (int) $user->ID,
				'name' => (string) $user->display_name,
			);
		}
		return $out;
	}

	/**
	 * Front-end strings (shared i18n map for every screen script).
	 *
	 * @return array
	 */
	private function strings() {
		return array(
			/* Generic */
			'genericError'      => __( 'Something went wrong. Please try again.', 'agentic-commerce-engine' ),
			'noData'            => __( 'No data yet', 'agentic-commerce-engine' ),
			'cancel'            => __( 'Cancel', 'agentic-commerce-engine' ),
			'done'              => __( 'Done.', 'agentic-commerce-engine' ),
			'loadMore'          => __( 'Load more', 'agentic-commerce-engine' ),
			'saving'            => __( 'Saving…', 'agentic-commerce-engine' ),
			'accountLink'       => __( 'Open your Rezolve Ai account', 'agentic-commerce-engine' ),
			'expand'            => __( 'Expand', 'agentic-commerce-engine' ),
			'justNow'           => __( 'just now', 'agentic-commerce-engine' ),
			/* translators: %s: number of minutes. */
			'minutesAgo'        => __( '%sm ago', 'agentic-commerce-engine' ),
			/* translators: %s: number of hours. */
			'hoursAgo'          => __( '%sh ago', 'agentic-commerce-engine' ),
			/* translators: %s: number of days. */
			'daysAgo'           => __( '%sd ago', 'agentic-commerce-engine' ),
			/* translators: 1: number processed, 2: total number. */
			'processing'        => __( 'Processing %1$s of %2$s…', 'agentic-commerce-engine' ),

			/* Connection */
			'connecting'        => __( 'Connecting…', 'agentic-commerce-engine' ),
			'connectError'      => __( 'Could not connect. Check your API key and try again.', 'agentic-commerce-engine' ),
			'confirmDisconnect' => __( 'Disconnect this site from Rezolve Ai? Your API key will be removed.', 'agentic-commerce-engine' ),

			/* Sync + enrichment */
			'syncing'           => __( 'Syncing…', 'agentic-commerce-engine' ),
			'syncDone'          => __( 'Sync complete.', 'agentic-commerce-engine' ),
			'enriching'         => __( 'Sending to AI…', 'agentic-commerce-engine' ),
			'enrichQueued'      => __( 'Queued for enrichment. Results appear as they finish.', 'agentic-commerce-engine' ),
			// Products a running experiment is measuring are left out, because
			// their content must not change mid-test.
			/* translators: %s: number of products left out of enrichment. */
			'enrichQueuedSomeInTest' => __( 'Queued for enrichment. %s left out: an experiment is measuring them.', 'agentic-commerce-engine' ),
			'chooseTypes'       => __( 'Choose what to enrich', 'agentic-commerce-engine' ),
			'enrichNow'         => __( 'Enrich now', 'agentic-commerce-engine' ),
			'pickAtLeastOne'    => __( 'Pick at least one content type.', 'agentic-commerce-engine' ),
			'onPage'            => __( 'On the product page', 'agentic-commerce-engine' ),
			'productData'       => __( 'Product data', 'agentic-commerce-engine' ),
			'feeds'             => __( 'Channel feeds', 'agentic-commerce-engine' ),
			'recommended'       => __( 'Recommended', 'agentic-commerce-engine' ),

			/* Products */
			'noResults'         => __( 'No products match.', 'agentic-commerce-engine' ),
			/* translators: %s: number of selected products. */
			'selected'          => __( '%s selected', 'agentic-commerce-engine' ),
			/* translators: %s: number of matching products. */
			'selectAllMatching' => __( 'Select all %s matching', 'agentic-commerce-engine' ),
			'clearAllMatching'  => __( 'Keep only the checked rows', 'agentic-commerce-engine' ),
			/* translators: 1: current page, 2: total pages. */
			'pageOf'            => __( 'Page %1$s of %2$s', 'agentic-commerce-engine' ),
			'statusQueued'      => __( 'Queued', 'agentic-commerce-engine' ),
			'statusReady'       => __( 'Ready to review', 'agentic-commerce-engine' ),
			'statusApplied'     => __( 'Applied', 'agentic-commerce-engine' ),
			'statusDismissed'   => __( 'Dismissed', 'agentic-commerce-engine' ),
			'statusFailed'      => __( 'Failed', 'agentic-commerce-engine' ),
			'statusNone'        => __( 'Not enriched', 'agentic-commerce-engine' ),

			/* Review */
			'noPending'         => __( 'Nothing to review right now.', 'agentic-commerce-engine' ),
			'noApplied'         => __( 'No applied products yet.', 'agentic-commerce-engine' ),
			'confirmApproveAll' => __( 'Apply every pending suggestion to your live products?', 'agentic-commerce-engine' ),
			'applying'          => __( 'Applying…', 'agentic-commerce-engine' ),
			'applied'           => __( 'Applied to your product.', 'agentic-commerce-engine' ),
			'heldBackChip'      => __( 'Held back', 'agentic-commerce-engine' ),
			'heldBackOne'       => __( 'One product was left waiting: an experiment is holding it back. Its row says until when.', 'agentic-commerce-engine' ),
			/* translators: %s: number of products. */
			'heldBackMany'      => __( '%s products were left waiting: an experiment is holding them back. Each row says until when.', 'agentic-commerce-engine' ),
			'dismissed'         => __( 'Dismissed.', 'agentic-commerce-engine' ),
			'reverted'          => __( 'Reverted to the original.', 'agentic-commerce-engine' ),
			'approve'           => __( 'Approve', 'agentic-commerce-engine' ),
			'dismiss'           => __( 'Dismiss', 'agentic-commerce-engine' ),
			'revertToOriginal'  => __( 'Revert to original', 'agentic-commerce-engine' ),
			'edit'              => __( 'Edit', 'agentic-commerce-engine' ),
			'current'           => __( 'Current', 'agentic-commerce-engine' ),
			'proposed'          => __( 'Proposed', 'agentic-commerce-engine' ),
			'noChange'          => __( 'no change', 'agentic-commerce-engine' ),
			'emptyValue'        => __( '(empty)', 'agentic-commerce-engine' ),
			/* translators: %s: measured quality score for a product, 0 to 100. */
			'scoreLabel'        => __( 'Score %s', 'agentic-commerce-engine' ),
			'notScored'         => __( 'Not scored', 'agentic-commerce-engine' ),

			/* Review: product images and the live page (4.20.0). */
			'viewLivePage'      => __( 'View live page', 'agentic-commerce-engine' ),
			/* translators: %s: product name. */
			'viewLivePageFor'   => __( 'View live page for %s (opens in a new tab)', 'agentic-commerce-engine' ),
			'imagesTitle'       => __( 'Product images', 'agentic-commerce-engine' ),
			'imagesHint'        => __( 'Main image first, then the gallery, each with the alt text it has now.', 'agentic-commerce-engine' ),
			'mainImage'         => __( 'Main image', 'agentic-commerce-engine' ),
			'noAltText'         => __( 'No alt text', 'agentic-commerce-engine' ),
			'noImages'          => __( 'This product has no images.', 'agentic-commerce-engine' ),
			/* translators: 1: position of the image, 2: number of images. */
			'openImage'         => __( 'Open image %1$s of %2$s at full size (opens in a new tab)', 'agentic-commerce-engine' ),
			/* translators: 1: number of images shown, 2: number of images on the product. */
			'imagesMore'        => __( 'Showing the first %1$s of %2$s images.', 'agentic-commerce-engine' ),

			/*
			 * Quality scores (4.17.0). The state sentences are the web review
			 * queue's, word for word, so a merchant reads the same thing about
			 * the same score wherever they look.
			 */
			'qualityTitle'       => __( 'Quality scores', 'agentic-commerce-engine' ),
			'qualityContent'     => __( 'Content Quality', 'agentic-commerce-engine' ),
			'qualitySeo'         => __( 'SEO Score', 'agentic-commerce-engine' ),
			'qualityAi'          => __( 'AI Readability', 'agentic-commerce-engine' ),
			'qualityBrand'       => __( 'Brand Voice', 'agentic-commerce-engine' ),
			'qualityExcellent'   => __( 'Excellent', 'agentic-commerce-engine' ),
			'qualityVeryGood'    => __( 'Very Good', 'agentic-commerce-engine' ),
			'qualityGood'        => __( 'Good', 'agentic-commerce-engine' ),
			'qualityNeedsWork'   => __( 'Needs Improvement', 'agentic-commerce-engine' ),
			'qualityNoVoice'     => __( 'No brand voice set', 'agentic-commerce-engine' ),
			'qualityUnscored'    => __( 'Not scored yet. Scores usually appear within a few minutes of the content being saved.', 'agentic-commerce-engine' ),
			'qualityStale'       => __( 'This content was edited after it was scored, so these scores may be out of date. A new score is on its way.', 'agentic-commerce-engine' ),
			'qualityOutdated'    => __( 'These scores were measured under an earlier scoring rubric. An updated score is on its way.', 'agentic-commerce-engine' ),
			/* translators: 1: date the content was scored, 2: overall score, 0 to 100. */
			'qualityScored'      => __( 'Scored %1$s · Overall %2$s', 'agentic-commerce-engine' ),
			/* translators: %s: date the content was scored. */
			'qualityScoredOn'    => __( 'Scored %s', 'agentic-commerce-engine' ),
			'qualityScoreNow'    => __( 'Score now', 'agentic-commerce-engine' ),
			'qualityRescoreNow'  => __( 'Re-score now', 'agentic-commerce-engine' ),
			'qualityRescore'     => __( 'Re-score', 'agentic-commerce-engine' ),
			'qualityScoring'     => __( 'Scoring…', 'agentic-commerce-engine' ),
			'qualityWhy'         => __( 'Why these scores', 'agentic-commerce-engine' ),
			'qualityOtherVersion' => __( 'These scores describe a different version of this content.', 'agentic-commerce-engine' ),
			'qualityNotReceived' => __( 'Not scored yet. Rezolve Ai has not received this content for review yet, so there is nothing to score. It is sent automatically, usually within the hour.', 'agentic-commerce-engine' ),
			'qualityQueued'      => __( 'A new score is being measured. This usually takes under a minute.', 'agentic-commerce-engine' ),
			'qualityTimedOut'    => __( 'The new score is taking longer than usual. It will appear here once it is ready.', 'agentic-commerce-engine' ),
			'qualityUnavailable' => __( 'Quality scores could not be loaded just now. Try again in a moment.', 'agentic-commerce-engine' ),
			'qualityCached'      => __( 'These are the last scores read from Rezolve Ai. They could not be refreshed just now.', 'agentic-commerce-engine' ),
			'qualityOffline'     => __( 'Connect this site to Rezolve Ai to see up to date quality scores.', 'agentic-commerce-engine' ),
			'qualityNothing'     => __( 'There is no content on this product to score.', 'agentic-commerce-engine' ),
			/* translators: %s: number of scenarios. */
			'irlTitle'          => __( 'IRL scenarios (%s)', 'agentic-commerce-engine' ),
			'internalLinks'     => __( 'Internal links', 'agentic-commerce-engine' ),
			'jsonldTitle'       => __( 'JSON-LD schema', 'agentic-commerce-engine' ),
			'highlightsTitle'   => __( 'Highlights', 'agentic-commerce-engine' ),
			'qaTitle'           => __( 'Product Q&A', 'agentic-commerce-engine' ),
			'attributesTitle'   => __( 'Product attributes', 'agentic-commerce-engine' ),
			'attrAdd'           => __( 'Will be added', 'agentic-commerce-engine' ),
			'attrExists'        => __( 'Already on the product, kept', 'agentic-commerce-engine' ),
			'imageAltTitle'     => __( 'Image alt text', 'agentic-commerce-engine' ),
			'altWrite'          => __( 'Will be replaced', 'agentic-commerce-engine' ),
			'altSame'           => __( 'Already matches', 'agentic-commerce-engine' ),
			'altUnmatched'      => __( 'Image no longer on this product, skipped', 'agentic-commerce-engine' ),
			'currentAlt'        => __( 'Current alt text', 'agentic-commerce-engine' ),
			'proposedAlt'       => __( 'Proposed alt text', 'agentic-commerce-engine' ),
			/* translators: %s: feed channel key. */
			'feedTitle'         => __( '%s feed', 'agentic-commerce-engine' ),

			/* Editing on Review (4.18.0) */
			'editLive'          => __( 'Edit live', 'agentic-commerce-engine' ),
			'editSave'          => __( 'Save', 'agentic-commerce-engine' ),
			'editSaveLive'      => __( 'Save and republish', 'agentic-commerce-engine' ),
			'editLiveNote'      => __( 'This is live on your store. Saving republishes it.', 'agentic-commerce-engine' ),
			'editLiveCap'       => __( 'Live on your store', 'agentic-commerce-engine' ),
			'editSaved'         => __( 'Saved. Approving applies your edited version.', 'agentic-commerce-engine' ),
			'editSavedLive'     => __( 'Saved and republished to your store.', 'agentic-commerce-engine' ),
			'editFix'           => __( 'Some of this needs fixing before it can be saved.', 'agentic-commerce-engine' ),
			'editUnsaved'       => __( 'This product has edits you have not saved. Continue without them?', 'agentic-commerce-engine' ),
			'editAddHighlight'  => __( 'Add a highlight', 'agentic-commerce-engine' ),
			'editAddQuestion'   => __( 'Add a question', 'agentic-commerce-engine' ),
			'editAddSpec'       => __( 'Add a specification', 'agentic-commerce-engine' ),
			'editRemove'        => __( 'Remove', 'agentic-commerce-engine' ),
			'editMoveUp'        => __( 'Move up', 'agentic-commerce-engine' ),
			'editMoveDown'      => __( 'Move down', 'agentic-commerce-engine' ),
			'editHighlight'     => __( 'Highlight', 'agentic-commerce-engine' ),
			'editQuestion'      => __( 'Question', 'agentic-commerce-engine' ),
			'editAnswer'        => __( 'Answer', 'agentic-commerce-engine' ),
			'editSpecName'      => __( 'Name', 'agentic-commerce-engine' ),
			'editSpecValue'     => __( 'Value', 'agentic-commerce-engine' ),
			'editAltFor'        => __( 'Alt text for this image', 'agentic-commerce-engine' ),
			'editAltHint'       => __( 'Leave an image blank to keep its current alt text.', 'agentic-commerce-engine' ),
			'editAltGone'       => __( 'This image is no longer on the product, so its alt text is not written.', 'agentic-commerce-engine' ),
			/* translators: 1: number of characters typed, 2: maximum number of characters. */
			'editCount'         => __( '%1$s of %2$s characters', 'agentic-commerce-engine' ),
			/* translators: 1: number of characters typed, 2: recommended number of characters. */
			'editCountRec'      => __( '%1$s characters, %2$s recommended', 'agentic-commerce-engine' ),
			/* translators: %s: comma separated attribute names. */
			'editKeptAttrs'     => __( 'Kept as they are, because you added them yourself: %s', 'agentic-commerce-engine' ),
			'attrOwned'         => __( 'On your store', 'agentic-commerce-engine' ),

			/* Dashboard */
			'credits'           => __( 'Credits', 'agentic-commerce-engine' ),
			/* translators: 1: credits used, 2: credit limit. */
			'creditsUsedOf'     => __( '%1$s used of %2$s', 'agentic-commerce-engine' ),
			'creditsUsed'       => __( 'credits used', 'agentic-commerce-engine' ),
			'productsOptimized' => __( 'Products optimized', 'agentic-commerce-engine' ),
			/* translators: 1: optimized count, 2: total products. */
			'ofProducts'        => __( '%1$s of %2$s products', 'agentic-commerce-engine' ),
			'noWoo'             => __( 'Not installed', 'agentic-commerce-engine' ),
			'noWooSub'          => __( 'Product optimization is available with WooCommerce.', 'agentic-commerce-engine' ),
			'articlesPublished' => __( 'Articles published', 'agentic-commerce-engine' ),
			/* translators: %s: number of draft articles. */
			'draftsWaiting'     => __( '%s drafts waiting', 'agentic-commerce-engine' ),
			'botHits'           => __( 'AI bot hits, 7 days', 'agentic-commerce-engine' ),
			'vsPrior'           => __( 'vs the prior 7 days', 'agentic-commerce-engine' ),
			'yourBrandInAnswers' => __( 'Your brand in AI answers', 'agentic-commerce-engine' ),
			'yourBrandSub'      => __( 'of AI answers name your brand', 'agentic-commerce-engine' ),
			'notAvailable'      => __( 'n/a', 'agentic-commerce-engine' ),
			'notMeasuredRun'    => __( 'Could not be measured for this run.', 'agentic-commerce-engine' ),
			'notMeasured'       => __( 'Not measured yet', 'agentic-commerce-engine' ),
			'sovSetup'          => __( 'Run AI search research to measure this.', 'agentic-commerce-engine' ),
			'optimized'         => __( 'optimized', 'agentic-commerce-engine' ),
			'appliedPerDay'     => __( 'Products applied per day', 'agentic-commerce-engine' ),

			/* AI Search */
			'aeoNotConfigured'  => __( 'AI Search research is not set up for this Rezolve Ai account yet. Finish setup in the Rezolve Ai app, then reload this page. The Crawlers tab works either way.', 'agentic-commerce-engine' ),

			/*
			 * Experiments
			 *
			 * This screen used to hardcode its English, the only one that did,
			 * so nothing on it could be translated even though the rest of the
			 * plugin could. The strings below are the ones the screen script
			 * asks for by key; anything it cannot find falls back to the English
			 * written beside the call, so a missing key degrades to the current
			 * behaviour rather than to an empty label.
			 */
			'progress'          => __( 'Progress', 'agentic-commerce-engine' ),
			'step_done'         => __( 'Done', 'agentic-commerce-engine' ),
			'step_active'       => __( 'Now', 'agentic-commerce-engine' ),
			'step_blocked'      => __( 'Needs you', 'agentic-commerce-engine' ),
			'step_pending'      => __( 'Later', 'agentic-commerce-engine' ),
			'howEachVersion'    => __( 'How each version is doing', 'agentic-commerce-engine' ),
			'whenWillIKnow'     => __( 'When will I know?', 'agentic-commerce-engine' ),
			'projectionPending' => __( 'Once both versions have been seen by enough shoppers, this shows the range narrowing and projects the day it becomes readable.', 'agentic-commerce-engine' ),
			'productSplitReadiness' => __( 'This test compares the products with new content against the held-back products, so it is read once its planned days are done rather than as shoppers arrive. There is no range to draw while it runs.', 'agentic-commerce-engine' ),
			'productSplitGroups'    => __( 'Products were put into the two groups at random when the test was set up, so there is no shopper split to check. Every shopper sees the same content on a given product.', 'agentic-commerce-engine' ),
			'testClosed'            => __( 'This test is closed', 'agentic-commerce-engine' ),
			'notCountingYet'        => __( 'Not counting yet', 'agentic-commerce-engine' ),
			'nothingToEstimate'     => __( 'Nothing to estimate yet', 'agentic-commerce-engine' ),
			'plannedDaysDone'       => __( 'The planned days are done', 'agentic-commerce-engine' ),
			'aboutOneDayToGo'       => __( 'About 1 day to go', 'agentic-commerce-engine' ),
			/* translators: %s: number of days left in the test. */
			'aboutDaysToGo'         => __( 'About %s days to go', 'agentic-commerce-engine' ),
			'whereDropOff'      => __( 'Where shoppers drop off', 'agentic-commerce-engine' ),
			'basketNotCounted'  => __( 'The basket step is not shown because this site does not report it. Rezolve Ai 4.14.0 and later count it, and it appears here on its own once every version has been seen adding to a basket.', 'agentic-commerce-engine' ),
			'measuredProperly'  => __( 'Is this being measured properly?', 'agentic-commerce-engine' ),
			'unattributed'      => __( 'shoppers could not be tied to this test because another split was running at the same time, so they are left out rather than guessed at.', 'agentic-commerce-engine' ),
			'whatItFound'       => __( 'What it found', 'agentic-commerce-engine' ),
			'nothingComputed'   => __( 'Nothing has been computed yet. Results are worked out overnight, once there is enough traffic for a comparison to mean anything.', 'agentic-commerce-engine' ),
			'whatWordsMean'     => __( 'What these words mean', 'agentic-commerce-engine' ),
			'alsoMeasure'       => __( 'Also measure', 'agentic-commerce-engine' ),
			'alsoMeasureProduct' => __( 'Read out beside the main goal, on the same products and the same days. Shown on the experiment page, and never what decides the result.', 'agentic-commerce-engine' ),
			'alsoMeasureShopper' => __( 'Shown for each version on the experiment page, and never what decides the result.', 'agentic-commerce-engine' ),
			'alsoMeasured'      => __( 'Also measured', 'agentic-commerce-engine' ),
			'alsoMeasuredIntro' => __( 'The other goals you asked to watch, on the same products and the same days. They do not decide the result above.', 'agentic-commerce-engine' ),
			'alsoMeasuredUnread' => __( 'These could not be read just now. Reload the page to try again.', 'agentic-commerce-engine' ),
			'colGoal'           => __( 'Goal', 'agentic-commerce-engine' ),
			'rangeOrReason'     => __( 'Range or reason', 'agentic-commerce-engine' ),
			'noFigure'          => __( 'No figure', 'agentic-commerce-engine' ),
			'version'           => __( 'Version', 'agentic-commerce-engine' ),
			'versions'          => __( 'versions', 'agentic-commerce-engine' ),
			'change'            => __( 'Change', 'agentic-commerce-engine' ),
			'range'             => __( 'Range', 'agentic-commerce-engine' ),
			'reading'           => __( 'Reading', 'agentic-commerce-engine' ),
			'differenceLabel'   => __( 'Against your original copy', 'agentic-commerce-engine' ),
			'likelyBetween'     => __( 'likely between', 'agentic-commerce-engine' ),
			'newContent'        => __( 'New content', 'agentic-commerce-engine' ),
			'leftUnchanged'     => __( 'Left unchanged', 'agentic-commerce-engine' ),
			/* translators: %s: what the chart counts, mid-sentence, e.g. "visits" or "shopping impressions". */
			'trendTitleFor'     => __( 'Average %s per product, in each group', 'agentic-commerce-engine' ),
			'trendVisits'       => __( 'Visits', 'agentic-commerce-engine' ),
			'trendTotals'       => __( 'Total visits to each group', 'agentic-commerce-engine' ),
			/* translators: %s: what the chart counts, e.g. "Visits" or "Shopping impressions". */
			'trendNoStartFor'   => __( '%s in each group, averaged per product so the two groups compare fairly.', 'agentic-commerce-engine' ),
			/* translators: %s: the day the test started, e.g. "28 Oct". */
			'trendStartedOn'    => __( 'The test started on %s.', 'agentic-commerce-engine' ),
			'trendBaselineOnly' => __( 'Every day on this chart so far is from before it, when both groups still had the same content. That is the baseline the test is measured against. Days with the new content appear here as they are counted, about a day behind.', 'agentic-commerce-engine' ),
			'trendShaded'       => __( 'Shaded days are from before the test, when both groups had the same content.', 'agentic-commerce-engine' ),
			/* translators: %s: the day the test started, e.g. "28 Oct". */
			'trendFromDay'      => __( 'From %s, one group has the new content.', 'agentic-commerce-engine' ),
			'trendHowToRead'    => __( 'Watch whether the gap between the lines changes after that day. A gap that was already there before the test is not a result.', 'agentic-commerce-engine' ),
			'trendBefore'       => __( 'Before the test', 'agentic-commerce-engine' ),
			'trendRunning'      => __( 'Test running', 'agentic-commerce-engine' ),
			'oneProduct'        => __( '1 product', 'agentic-commerce-engine' ),
			/* translators: %s: a number of products. */
			'nProducts'         => __( '%s products', 'agentic-commerce-engine' ),
			'percentLive'       => __( '% live', 'agentic-commerce-engine' ),
			'applyWinnerConfirm' => __( 'This finishes the test and makes the winning version the copy every shopper sees. It cannot be resumed afterwards.', 'agentic-commerce-engine' ),
			'applyWinnerDone'   => __( 'The winning version is on its way to your store.', 'agentic-commerce-engine' ),
			'didNotGoThrough'   => __( 'That did not go through.', 'agentic-commerce-engine' ),


			/* AI Search screen */
			'vsPreviousPeriod'      => __( 'vs previous period', 'agentic-commerce-engine' ),
			'botsHits'              => __( 'Crawler hits', 'agentic-commerce-engine' ),
			'botsActive'            => __( 'Active crawlers', 'agentic-commerce-engine' ),
			'botsTop'               => __( 'Top crawler', 'agentic-commerce-engine' ),
			'botsNone'              => __( 'None yet', 'agentic-commerce-engine' ),
			'botsTrackedDays'       => __( 'Days with activity', 'agentic-commerce-engine' ),
			'botsOther'             => __( 'Other', 'agentic-commerce-engine' ),
			'botsNoPages'           => __( 'No crawler visits recorded yet. Data appears as soon as an AI crawler hits your site.', 'agentic-commerce-engine' ),
			'botsPage'              => __( 'Page', 'agentic-commerce-engine' ),
			'botsHitsCol'           => __( 'Hits', 'agentic-commerce-engine' ),
			'botsLastSeen'          => __( 'Last seen', 'agentic-commerce-engine' ),
			'saved'                 => __( 'Saved.', 'agentic-commerce-engine' ),
			'botsReported'          => __( 'Crawler summary sent to Rezolve Ai.', 'agentic-commerce-engine' ),
			'vsPrevRun'             => __( 'vs previous run', 'agentic-commerce-engine' ),
			'anyBrandInAnswers'     => __( 'Answers naming any brand', 'agentic-commerce-engine' ),
			'anyBrandSub'           => __( 'How often answers recommend a brand at all.', 'agentic-commerce-engine' ),
			'anyBrand'              => __( 'Any brand', 'agentic-commerce-engine' ),
			/* translators: 1: answers naming your brand, 2: answers analysed. */
			'namedInOf'             => __( 'Named in %1$s of %2$s answers.', 'agentic-commerce-engine' ),
			'yourRank'              => __( 'Your rank', 'agentic-commerce-engine' ),
			/* translators: %1$s: number of brands named in AI answers. */
			'rankAmong'             => __( 'Among %1$s brands named in answers.', 'agentic-commerce-engine' ),
			'notNamed'              => __( 'Not named', 'agentic-commerce-engine' ),
			/* translators: %1$s: number of other brands named in AI answers. */
			'othersNamed'           => __( '%1$s other brands were named instead.', 'agentic-commerce-engine' ),
			'noBrandsNamed'         => __( 'No brand was named in these answers.', 'agentic-commerce-engine' ),
			'brandUnlisted'         => __( 'Your brand was not among the brands listed for this run.', 'agentic-commerce-engine' ),
			/* translators: %1$s: the brand names being matched, each in quotes. */
			'matchedAgainst'        => __( 'Answers are matched against %1$s. If shoppers know your brand by a shorter name, answers that use it are not counted.', 'agentic-commerce-engine' ),
			'trendOneRun'           => __( 'The trend line appears after your second completed run.', 'agentic-commerce-engine' ),
			'trendUnmeasured'       => __( 'Your brand could not be measured in enough runs to draw a line.', 'agentic-commerce-engine' ),
			'enginesVaried'         => __( 'Some runs asked different AI engines, so their points are not strictly comparable.', 'agentic-commerce-engine' ),
			/* translators: %1$s: number of citations of this site. */
			'ownSiteCited'          => __( 'Your site is highlighted: cited %1$s times.', 'agentic-commerce-engine' ),
			'ownSiteNotCited'       => __( 'Your site was not cited in the latest run.', 'agentic-commerce-engine' ),
			/* translators: %1$s: how many of the most-cited domains are kept. */
			'ownSiteNotTop'         => __( 'Your site was not among the %1$s most-cited domains.', 'agentic-commerce-engine' ),
			'aiResponses'           => __( 'AI responses', 'agentic-commerce-engine' ),
			'lastRun'               => __( 'Latest run', 'agentic-commerce-engine' ),
			'promptsNoMatch'        => __( 'No prompts match your filter.', 'agentic-commerce-engine' ),
			'promptsNone'           => __( 'No prompts saved yet. Add prompts from the Runs tab.', 'agentic-commerce-engine' ),
			'promptCol'             => __( 'Prompt', 'agentic-commerce-engine' ),
			'tagsCol'               => __( 'Tags', 'agentic-commerce-engine' ),
			'competitorsNone'       => __( 'No entity data yet. Launch a research run from the Runs tab first.', 'agentic-commerce-engine' ),
			'brandCol'              => __( 'Brand', 'agentic-commerce-engine' ),
			'shareOfAnswersCol'     => __( 'Share of answers', 'agentic-commerce-engine' ),
			'yourBrand'             => __( 'Your brand', 'agentic-commerce-engine' ),
			'promptsOnFlow'         => __( 'prompts saved on your research flow.', 'agentic-commerce-engine' ),
			'flowDefault'           => __( 'Flow default', 'agentic-commerce-engine' ),
			'noSuggestions'         => __( 'No suggestions came back. Try adding a topic.', 'agentic-commerce-engine' ),
			'suggestionsAdded'      => __( 'suggested prompts added to the editor.', 'agentic-commerce-engine' ),
			'facetHelp'             => __( 'Hold Ctrl or Cmd to pick more than one. Leave empty to draw from your whole catalog.', 'agentic-commerce-engine' ),
			'collectionsNotSynced'  => __( 'Sync your catalog to filter by collection.', 'agentic-commerce-engine' ),
			'promptsRequired'       => __( 'Add at least one prompt (three characters or more, one per line).', 'agentic-commerce-engine' ),
			'confirmReplacePrompts' => __( 'Replace all saved prompts with the lines in the editor?', 'agentic-commerce-engine' ),
			'promptsSaved'          => __( 'Prompts saved.', 'agentic-commerce-engine' ),
			'runLaunched'           => __( 'Run launched. Results usually arrive within a few minutes.', 'agentic-commerce-engine' ),
			'flowFieldsRequired'    => __( 'Enter a flow name and your brand name.', 'agentic-commerce-engine' ),
			'flowCreated'           => __( 'Research flow created.', 'agentic-commerce-engine' ),
			'statusCompleted'       => __( 'Completed', 'agentic-commerce-engine' ),
			'statusUnknown'         => __( 'Unknown', 'agentic-commerce-engine' ),
			'snapshotReady'         => __( 'Ready', 'agentic-commerce-engine' ),
			'snapshotBuilding'      => __( 'Building', 'agentic-commerce-engine' ),
			'snapshotPendingRun'    => __( 'After run', 'agentic-commerce-engine' ),
			'runsNone'              => __( 'No research runs yet. Configure your prompts above and launch the first run.', 'agentic-commerce-engine' ),
			'runCol'                => __( 'Run', 'agentic-commerce-engine' ),
			'statusCol'             => __( 'Status', 'agentic-commerce-engine' ),
			'startedCol'            => __( 'Started', 'agentic-commerce-engine' ),
			'endedCol'              => __( 'Ended', 'agentic-commerce-engine' ),
			'snapshotCol'           => __( 'Results', 'agentic-commerce-engine' ),
			'viewRun'               => __( 'View', 'agentic-commerce-engine' ),
			'snapshotStillBuilding' => __( 'The results snapshot is still building. Check back in a minute.', 'agentic-commerce-engine' ),
			'noPromptResults'       => __( 'No per prompt results are available for this run yet.', 'agentic-commerce-engine' ),
			'engineCol'             => __( 'Engine', 'agentic-commerce-engine' ),
			'citationsCol'          => __( 'Citations', 'agentic-commerce-engine' ),
			'entitiesCol'           => __( 'Top entities', 'agentic-commerce-engine' ),
			'mentioned'             => __( 'Mentioned', 'agentic-commerce-engine' ),
			'notMentioned'          => __( 'Not mentioned', 'agentic-commerce-engine' ),

			/* Content Hub screen */
			'stQueued'         => __( 'Queued', 'agentic-commerce-engine' ),
			'stGenerating'     => __( 'Generating', 'agentic-commerce-engine' ),
			'stWithEditor'     => __( 'With editor', 'agentic-commerce-engine' ),
			'stReady'          => __( 'Ready to publish', 'agentic-commerce-engine' ),
			'stPublished'      => __( 'Published', 'agentic-commerce-engine' ),
			'postLive'         => __( 'Live', 'agentic-commerce-engine' ),
			'postDraft'        => __( 'Draft', 'agentic-commerce-engine' ),
			'postPending'      => __( 'Pending', 'agentic-commerce-engine' ),
			'postScheduled'    => __( 'Scheduled', 'agentic-commerce-engine' ),
			'postPrivate'      => __( 'Private', 'agentic-commerce-engine' ),
			'statBriefs'       => __( 'Briefs', 'agentic-commerce-engine' ),
			'statBriefsSub'    => __( 'In your Rezolve Ai project', 'agentic-commerce-engine' ),
			'statDrafts'       => __( 'WP drafts', 'agentic-commerce-engine' ),
			'statDraftsSub'    => __( 'Waiting to go live', 'agentic-commerce-engine' ),
			'statPublished'    => __( 'Published', 'agentic-commerce-engine' ),
			'statPublishedSub' => __( 'Live on this site', 'agentic-commerce-engine' ),
			'addVoice'         => __( 'Add a brand voice in Rezolve Ai', 'agentic-commerce-engine' ),
			/* translators: %s: brand voice name. */
			'writingAs'        => __( 'Writing as %s.', 'agentic-commerce-engine' ),
			'typesBuiltin'     => __( 'Built in', 'agentic-commerce-engine' ),
			'typesPresets'     => __( 'Presets', 'agentic-commerce-engine' ),
			'typesCustom'      => __( 'Custom', 'agentic-commerce-engine' ),
			'typeDefault'      => __( 'Standard article', 'agentic-commerce-engine' ),
			'phaseQueued'      => __( 'Queued', 'agentic-commerce-engine' ),
			'phaseWorking'     => __( 'Working', 'agentic-commerce-engine' ),
			/* translators: %s: number of words in the article. */
			'wordCountLabel'   => __( '%s words', 'agentic-commerce-engine' ),
			'none'             => __( 'None', 'agentic-commerce-engine' ),
			'phaseDone'        => __( 'Article ready', 'agentic-commerce-engine' ),
			'generationFailed' => __( 'Generation failed. Please try again.', 'agentic-commerce-engine' ),
			'titleShort'       => __( 'Give the article a title of at least 3 characters.', 'agentic-commerce-engine' ),
			'generateStarted'  => __( 'Writing has started. This usually takes a minute or two.', 'agentic-commerce-engine' ),
			'generateFailed'   => __( 'Could not start the article.', 'agentic-commerce-engine' ),
			'publishedLive'    => __( 'Published. The article is live.', 'agentic-commerce-engine' ),
			'draftCreated'     => __( 'Draft created.', 'agentic-commerce-engine' ),
			'viewLive'         => __( 'View the live article', 'agentic-commerce-engine' ),
			'publishFailed'    => __( 'Could not publish the article.', 'agentic-commerce-engine' ),
			'discardConfirm'   => __( 'Move this draft to the trash?', 'agentic-commerce-engine' ),
			'discarded'        => __( 'Draft moved to the trash.', 'agentic-commerce-engine' ),
			'discardFailed'    => __( 'Could not discard the draft.', 'agentic-commerce-engine' ),
			'stUnknown'        => __( 'Unknown', 'agentic-commerce-engine' ),
			'prev'             => __( 'Previous', 'agentic-commerce-engine' ),
			'next'             => __( 'Next', 'agentic-commerce-engine' ),
			'noBriefs'         => __( 'No briefs yet. Generate your first article to get started.', 'agentic-commerce-engine' ),
			'rowPublish'       => __( 'Publish', 'agentic-commerce-engine' ),
			'rowCreateDraft'   => __( 'Create draft', 'agentic-commerce-engine' ),
			'colTitle'         => __( 'Title', 'agentic-commerce-engine' ),
			'colType'          => __( 'Type', 'agentic-commerce-engine' ),
			'colStatus'        => __( 'Status', 'agentic-commerce-engine' ),
			'colUpdated'       => __( 'Updated', 'agentic-commerce-engine' ),
			'rowView'          => __( 'View', 'agentic-commerce-engine' ),
			'briefsFailed'     => __( 'Could not load briefs.', 'agentic-commerce-engine' ),
			'noPosts'          => __( 'No Rezolve Ai articles in WordPress yet. Generated drafts will appear here.', 'agentic-commerce-engine' ),
			'colDate'          => __( 'Date', 'agentic-commerce-engine' ),
			'rowRepublish'     => __( 'Republish latest', 'agentic-commerce-engine' ),
			'postsFailed'      => __( 'Could not load articles.', 'agentic-commerce-engine' ),
			'republished'      => __( 'Updated with the latest version from Rezolve Ai.', 'agentic-commerce-engine' ),
			'draftUpdated'     => __( 'Draft updated with the latest version.', 'agentic-commerce-engine' ),

			/* Experiments screen */
			'andBetween'     => __( 'and', 'agentic-commerce-engine' ),
			'ofProductsLive' => __( 'of', 'agentic-commerce-engine' ),
			'productsLive'   => __( 'products have a second version live', 'agentic-commerce-engine' ),
			'productsNewContentLive' => __( 'products have their new content live', 'agentic-commerce-engine' ),

			'whatItIsWorth'    => __( 'What each version is worth', 'agentic-commerce-engine' ),
			'whatItIsWorthSub' => __( 'Revenue divided by shoppers, counted from your own store. Only shown because real order values were reported.', 'agentic-commerce-engine' ),
			'aboutAYear'       => __( 'About', 'agentic-commerce-engine' ),
			'aYearIfYouKeep'   => __( 'a year if you keep the winner', 'agentic-commerce-engine' ),
			'shoppers'         => __( 'Shoppers', 'agentic-commerce-engine' ),
			'revenue'          => __( 'Revenue', 'agentic-commerce-engine' ),
			'perShopper'       => __( 'Per shopper', 'agentic-commerce-engine' ),
			'preflightFailed'  => __( 'Could not check that design.', 'agentic-commerce-engine' ),
			'preflightRefused' => __( 'This design cannot be checked yet', 'agentic-commerce-engine' ),
			'optionsFailed'    => __( 'Could not load what can be tested', 'agentic-commerce-engine' ),

			/* Experiments: which products a test runs over */
			'facetCategories'   => __( 'Categories', 'agentic-commerce-engine' ),
			'facetCollections'  => __( 'Collections', 'agentic-commerce-engine' ),
			'facetProductTypes' => __( 'Product type', 'agentic-commerce-engine' ),
			'facetVendors'      => __( 'Brand', 'agentic-commerce-engine' ),
			'facetTags'         => __( 'Tags', 'agentic-commerce-engine' ),
			/*
			 * These two are NOT interchangeable. "Nothing stored yet" is a read
			 * that has not happened; "this catalogue records none" is a read
			 * that found nothing. Showing the second for the first tells the
			 * merchant a fact about their shop that nothing established.
			 */
			'facetEmpty'        => __( 'This catalogue records none of these.', 'agentic-commerce-engine' ),
			'facetUnsynced'     => __( 'Nothing stored yet. These appear once your catalogue has been read.', 'agentic-commerce-engine' ),
			'facetsFailed'      => __( 'Could not read your catalogue filters.', 'agentic-commerce-engine' ),
			'everyProduct'      => __( 'Every product', 'agentic-commerce-engine' ),
			'groupNone'         => __( 'None', 'agentic-commerce-engine' ),
			'groupAsOf'         => __( 'products as of', 'agentic-commerce-engine' ),
			'groupUncounted'    => __( 'not counted yet', 'agentic-commerce-engine' ),
			'groupSaved'        => __( 'Saved.', 'agentic-commerce-engine' ),
			'groupSaveFailed'   => __( 'Could not save that group.', 'agentic-commerce-engine' ),
			'noProductsChosen'  => __( 'No products chosen yet.', 'agentic-commerce-engine' ),
			'countFailed'       => __( 'Could not count these products.', 'agentic-commerce-engine' ),
			'selectionFreezes'  => __( 'Fixed when you start the test. Products added to these filters later will not join it.', 'agentic-commerce-engine' ),
			'holdoutAbout'      => __( 'About', 'agentic-commerce-engine' ),
			/* translators: 1: products held back at a fifth, 2: products held back at half. */
			'holdoutBetween'    => __( 'Between about %1$s and %2$s of them will be held back unchanged as the control group, depending on how many you choose to hold back.', 'agentic-commerce-engine' ),
			'holdoutHeld'       => __( 'of them will be held back unchanged as the control group.', 'agentic-commerce-engine' ),
			'holdoutTooFew'     => __( 'That is too few for a control group.', 'agentic-commerce-engine' ),
			'holdoutNeed'       => __( 'A product split needs about', 'agentic-commerce-engine' ),
			'holdoutProducts'   => __( 'products.', 'agentic-commerce-engine' ),
			'cohortWorking'     => __( 'Looking through your catalogue...', 'agentic-commerce-engine' ),
			'cohortNone'        => __( 'Nothing matched that.', 'agentic-commerce-engine' ),
			'cohortSmall'       => __( 'That is smaller than a test usually needs. Take it, and the design check will have the last word.', 'agentic-commerce-engine' ),
			'cohortUse'         => __( 'Use these products', 'agentic-commerce-engine' ),
			'cohortApplied'     => __( 'Using the products you picked.', 'agentic-commerce-engine' ),
			'cohortFailed'      => __( 'Could not suggest products just now.', 'agentic-commerce-engine' ),
			'suggestTimedOut'   => __( 'That took longer than expected. Try a shorter description, or pick by category instead.', 'agentic-commerce-engine' ),
			'pickerHint'        => __( 'Search to add a product the filters missed.', 'agentic-commerce-engine' ),
			'pickerNone'        => __( 'Nothing matched that search.', 'agentic-commerce-engine' ),
			'pickerFailed'      => __( 'Could not search products.', 'agentic-commerce-engine' ),

			/* Experiments: history and what testing has been worth */
			'worthHeading'      => __( 'What testing has been worth', 'agentic-commerce-engine' ),
			'worthSub'          => __( 'From', 'agentic-commerce-engine' ),
			'worthSubTail'      => __( 'finished tests, counted as each one was decided.', 'agentic-commerce-engine' ),
			'bankedAYear'       => __( 'A year, from decisions taken', 'agentic-commerce-engine' ),
			'wonByKeeping'      => __( 'Won by keeping a better version', 'agentic-commerce-engine' ),
			'keptByNotShipping' => __( 'Kept by not shipping a worse one', 'agentic-commerce-engine' ),
			'atLeast'           => __( 'At least', 'agentic-commerce-engine' ),
			'onTheCautiousEnd'  => __( 'a year on the cautious end of every range.', 'agentic-commerce-engine' ),
			'plus'              => __( 'Plus', 'agentic-commerce-engine' ),
			'aYearIn'           => __( 'a year in', 'agentic-commerce-engine' ),
			'runningWouldAdd'   => __( 'Tests still running would add about', 'agentic-commerce-engine' ),
			'ifEstimateHolds'   => __( 'a year if their current estimate holds. That is not counted above, because they have not finished.', 'agentic-commerce-engine' ),
			'allTests'          => __( 'All', 'agentic-commerce-engine' ),
			'noTestsMatch'      => __( 'No tests match that.', 'agentic-commerce-engine' ),
			'noHistoryYet'      => __( 'Nothing here yet. Finished tests and what they found will collect here.', 'agentic-commerce-engine' ),
			'noHistory'         => __( 'Could not load your test history.', 'agentic-commerce-engine' ),
			'stillRunning'      => __( 'Running', 'agentic-commerce-engine' ),
			'won'               => __( 'won', 'agentic-commerce-engine' ),
			'notLost'           => __( 'not lost', 'agentic-commerce-engine' ),
			'editNote'          => __( 'Edit what you learned', 'agentic-commerce-engine' ),
			'addNote'           => __( 'Add what you learned', 'agentic-commerce-engine' ),
			'saveNote'          => __( 'Save', 'agentic-commerce-engine' ),
			'noteSaved'         => __( 'Saved.', 'agentic-commerce-engine' ),
			'noteNotSaved'      => __( 'Could not save that note.', 'agentic-commerce-engine' ),
			'test'              => __( 'Test', 'agentic-commerce-engine' ),
			'outcome'           => __( 'Outcome', 'agentic-commerce-engine' ),
			'change'            => __( 'Change', 'agentic-commerce-engine' ),
			'worth'             => __( 'Worth', 'agentic-commerce-engine' ),
			'decided'           => __( 'Decided', 'agentic-commerce-engine' ),
			'previous'          => __( 'Previous', 'agentic-commerce-engine' ),
			'page'              => __( 'Page', 'agentic-commerce-engine' ),

			/* Settings screen: ChatGPT product file (4.34.0) */
			'openaiFeedLoading' => __( 'Reading the URL from Rezolve Ai…', 'agentic-commerce-engine' ),
			'openaiFeedReady'   => __( 'Copy this URL into ChatGPT Business Hub. The file lists the products you have synced to Rezolve Ai.', 'agentic-commerce-engine' ),
			'openaiFeedNone'    => __( 'Rezolve Ai does not serve a ChatGPT product file for this store.', 'agentic-commerce-engine' ),
			'openaiFeedFailed'  => __( 'Could not read your ChatGPT product file URL from Rezolve Ai.', 'agentic-commerce-engine' ),
			'openaiFeedRetry'   => __( 'Try again', 'agentic-commerce-engine' ),
			'openaiFeedCopied'  => __( 'ChatGPT product file URL copied.', 'agentic-commerce-engine' ),
			'copyManually'      => __( 'The URL is selected. Press Ctrl+C, or Cmd+C on a Mac, to copy it.', 'agentic-commerce-engine' ),

			/* Settings screen */
			'feedUrlsRegenerated' => __( 'Feed URLs regenerated. Update them wherever they are configured.', 'agentic-commerce-engine' ),

			/* Settings */
			'settingsSaved'     => __( 'Settings saved.', 'agentic-commerce-engine' ),
			'customFieldsEmpty'        => __( 'No custom fields found on your products yet. They appear here after your next catalog sync.', 'agentic-commerce-engine' ),
			'customFieldsLoading'      => __( 'Loading your custom fields…', 'agentic-commerce-engine' ),
			'customFieldsTabsLabel'    => __( 'Custom field status', 'agentic-commerce-engine' ),
			'customFieldsTabAll'       => __( 'All', 'agentic-commerce-engine' ),
			'customFieldsTabUsed'      => __( 'Used', 'agentic-commerce-engine' ),
			'customFieldsTabRewriting' => __( 'Rewriting', 'agentic-commerce-engine' ),
			'customFieldsExcluded'     => __( 'Left out', 'agentic-commerce-engine' ),
			'customFieldsSkipped'      => __( 'Not used', 'agentic-commerce-engine' ),
			'customFieldsSearch'       => __( 'Search by field name or content', 'agentic-commerce-engine' ),
			'customFieldsSearchLabel'  => __( 'Search custom fields', 'agentic-commerce-engine' ),
			/* translators: %s: number of selected custom fields. */
			'customFieldsSelected'     => __( '%s selected', 'agentic-commerce-engine' ),
			/* translators: %s: number of selected custom fields to leave out. */
			'customFieldsBulkLeaveOut' => __( 'Leave out %s', 'agentic-commerce-engine' ),
			/* translators: %s: number of selected custom fields to use again. */
			'customFieldsBulkUseAgain' => __( 'Use %s again', 'agentic-commerce-engine' ),
			'customFieldsClear'        => __( 'Clear selection', 'agentic-commerce-engine' ),
			'customFieldsColField'     => __( 'Field', 'agentic-commerce-engine' ),
			'customFieldsColProducts'  => __( 'Products', 'agentic-commerce-engine' ),
			'customFieldsColExample'   => __( 'Example', 'agentic-commerce-engine' ),
			'customFieldsColStatus'    => __( 'Status', 'agentic-commerce-engine' ),
			'customFieldsColRewrite'   => __( 'Rewrite', 'agentic-commerce-engine' ),
			'customFieldsColAction'    => __( 'Action', 'agentic-commerce-engine' ),
			'customFieldsSelectPage'   => __( 'Select every field on this page', 'agentic-commerce-engine' ),
			/* translators: %s: a custom field's name. */
			'customFieldsSelectRow'    => __( 'Select %s', 'agentic-commerce-engine' ),
			/* translators: %s: a custom field's name. */
			'customFieldsRewriteRow'   => __( 'Rewrite %s', 'agentic-commerce-engine' ),
			'customFieldsReadOnly'     => __( 'Read only', 'agentic-commerce-engine' ),
			'customFieldsNoPreview'    => __( 'No preview', 'agentic-commerce-engine' ),
			/* translators: %s: the search text. */
			'customFieldsNoMatch'      => __( 'No fields match "%s".', 'agentic-commerce-engine' ),
			'customFieldsNoneHere'     => __( 'No fields here yet.', 'agentic-commerce-engine' ),
			/* translators: 1: first row shown, 2: last row shown, 3: number of fields. */
			'customFieldsShowing'      => __( 'Showing %1$s to %2$s of %3$s fields', 'agentic-commerce-engine' ),
			'customFieldsShowingOne'   => __( 'Showing 1 field', 'agentic-commerce-engine' ),
			'customFieldsLeaveOut'     => __( 'Leave out', 'agentic-commerce-engine' ),
			'customFieldsUseAgain'     => __( 'Use again', 'agentic-commerce-engine' ),
			/* translators: %s: a custom field's name. */
			'customFieldsLeftOutOne'   => __( '%s is left out. Rezolve Ai stops using it straight away.', 'agentic-commerce-engine' ),
			/* translators: %s: number of custom fields. */
			'customFieldsLeftOutMany'  => __( '%s fields are left out. Rezolve Ai stops using them straight away.', 'agentic-commerce-engine' ),
			/* translators: %s: a custom field's name. */
			'customFieldsUsedAgainOne' => __( '%s is used again.', 'agentic-commerce-engine' ),
			/* translators: %s: number of custom fields. */
			'customFieldsUsedAgainMany' => __( '%s fields are used again.', 'agentic-commerce-engine' ),
			/* translators: %s: a custom field's name. */
			'customFieldsSyncingOne'   => __( '%s is used again. Your catalog is resyncing, so it is back on every product once that finishes.', 'agentic-commerce-engine' ),
			/* translators: %s: number of custom fields. */
			'customFieldsSyncingMany'  => __( '%s fields are used again. Your catalog is resyncing, so they are back on every product once that finishes.', 'agentic-commerce-engine' ),
			/* translators: %s: a custom field's name. */
			'customFieldsLaterOne'     => __( '%s is used again. It comes back with your next catalog sync.', 'agentic-commerce-engine' ),
			/* translators: %s: number of custom fields. */
			'customFieldsLaterMany'    => __( '%s fields are used again. They come back with your next catalog sync.', 'agentic-commerce-engine' ),
			/* translators: %s: a custom field's name, or a number of fields. */
			'customFieldsRewriteOn'    => __( 'Rezolve Ai can now suggest rewrites for %s.', 'agentic-commerce-engine' ),
			/* translators: %s: a custom field's name, or a number of fields. */
			'customFieldsRewriteOff'   => __( '%s will no longer be rewritten.', 'agentic-commerce-engine' ),
			'customFieldsLoadError'    => __( 'Your custom fields could not be loaded. Try again in a moment.', 'agentic-commerce-engine' ),
			'customFieldsTitle'        => __( 'Your custom fields', 'agentic-commerce-engine' ),
			'customFieldNew'           => __( 'New: this field was empty', 'agentic-commerce-engine' ),
			'customFieldCheckFacts'    => __( 'Check the facts', 'agentic-commerce-engine' ),
			'layoutTitle'              => __( 'How the description will look', 'agentic-commerce-engine' ),
			// Field formats card (4.28.0).
			'ffModeOwn' => __( 'Your own template', 'agentic-commerce-engine' ),
			'ffModeOwnHint' => __( 'Choose the parts and their order yourself.', 'agentic-commerce-engine' ),
			'ffPartBullets' => __( 'Bullet points', 'agentic-commerce-engine' ),
			'ffPartHeading' => __( 'Heading', 'agentic-commerce-engine' ),
			'ffPartKept' => __( 'Closing sentence', 'agentic-commerce-engine' ),
			'ffPartNumbered' => __( 'Numbered points', 'agentic-commerce-engine' ),
			'ffPartParagraphs' => __( 'Paragraphs', 'agentic-commerce-engine' ),
			'ffPartQa' => __( 'Questions and answers', 'agentic-commerce-engine' ),
			'ffPartSpecs' => __( 'Details list', 'agentic-commerce-engine' ),
			'ffTplAdd' => __( 'Add', 'agentic-commerce-engine' ),
			'ffTplAddPart' => __( 'Add a part', 'agentic-commerce-engine' ),
			'ffTplBold' => __( 'In bold', 'agentic-commerce-engine' ),
			'ffTplBullets' => __( 'Bullets', 'agentic-commerce-engine' ),
			'ffTplDown' => __( 'Down', 'agentic-commerce-engine' ),
			'ffTplFewest' => __( 'Fewest', 'agentic-commerce-engine' ),
			/* translators: %1$s: a setting, e.g. "Fewest"; %2$s: a part of a template, e.g. "Paragraphs" */
			'ffTplFieldFor' => __( '%1$s, %2$s', 'agentic-commerce-engine' ),
			'ffTplHeadingText' => __( 'Heading text, or leave empty for Rezolve Ai to write one', 'agentic-commerce-engine' ),
			'ffTplHeadings' => __( 'As headings', 'agentic-commerce-engine' ),
			'ffTplKeptNote' => __( 'The sentence your values end with, kept as your store writes it.', 'agentic-commerce-engine' ),
			'ffTplLarge' => __( 'Large', 'agentic-commerce-engine' ),
			'ffTplLines' => __( 'As Q and A lines', 'agentic-commerce-engine' ),
			'ffTplList' => __( 'A list', 'agentic-commerce-engine' ),
			'ffTplMarked' => __( 'Marked with', 'agentic-commerce-engine' ),
			'ffTplMost' => __( 'Most', 'agentic-commerce-engine' ),
			/* translators: %s: a part of a template */
			'ffTplMoveDown' => __( 'Move %s down', 'agentic-commerce-engine' ),
			/* translators: %s: a part of a template */
			'ffTplMoveUp' => __( 'Move %s up', 'agentic-commerce-engine' ),
			'ffTplNumbers' => __( 'Numbers', 'agentic-commerce-engine' ),
			'ffTplQuestions' => __( 'Questions shown', 'agentic-commerce-engine' ),
			'ffTplRemove' => __( 'Remove', 'agentic-commerce-engine' ),
			/* translators: %s: a part of a template */
			'ffTplRemoveRow' => __( 'Remove %s', 'agentic-commerce-engine' ),
			'ffTplShownAs' => __( 'Shown as', 'agentic-commerce-engine' ),
			'ffTplSize' => __( 'Size', 'agentic-commerce-engine' ),
			'ffTplSmall' => __( 'Small', 'agentic-commerce-engine' ),
			'ffTplTable' => __( 'A table', 'agentic-commerce-engine' ),
			'ffTplTitle' => __( 'Your template', 'agentic-commerce-engine' ),
			'ffTplUp' => __( 'Up', 'agentic-commerce-engine' ),
			/* translators: %1$s: how many products, e.g. "812 products"; %2$s: a percentage, e.g. "97%" */
			'ffAlike' => __( '%1$s, %2$s alike', 'agentic-commerce-engine' ),
			'ffCaption' => __( 'How Rezolve Ai writes into each field', 'agentic-commerce-engine' ),
			'ffChange' => __( 'Change', 'agentic-commerce-engine' ),
			/* translators: %s: a field name */
			'ffChangeRow' => __( 'Change the format of %s', 'agentic-commerce-engine' ),
			'ffCheckAgain' => __( 'Check again', 'agentic-commerce-engine' ),
			'ffColAction' => __( 'Action', 'agentic-commerce-engine' ),
			'ffColField' => __( 'Field', 'agentic-commerce-engine' ),
			'ffColFill' => __( 'Fill when empty', 'agentic-commerce-engine' ),
			'ffColFormat' => __( 'Format', 'agentic-commerce-engine' ),
			'ffColLearned' => __( 'Learned from', 'agentic-commerce-engine' ),
			/* translators: %s: a number of credits */
			'ffCredits' => __( '%s credits', 'agentic-commerce-engine' ),
			/* translators: %1$s and %2$s: numbers of credits */
			'ffCreditsRange' => __( '%1$s to %2$s credits', 'agentic-commerce-engine' ),
			'ffEmpty' => __( 'Nothing learned yet. Formats are learned after your catalog sync, from your own products.', 'agentic-commerce-engine' ),
			'ffFillCheckFacts' => __( 'Because this field tells a story or a meaning, its fills are marked Check the facts in Review.', 'agentic-commerce-engine' ),
			'ffFillDraft' => __( 'Write this field for products that do not have it', 'agentic-commerce-engine' ),
			'ffFillReview' => __( 'Each fill is written in this field\'s format, only from what your product data says, and goes to Review first. Nothing reaches your store until you approve it.', 'agentic-commerce-engine' ),
			/* translators: %s: a field name */
			'ffFillRow' => __( 'Fill %s when empty', 'agentic-commerce-engine' ),
			/* translators: %1$s: a field name; %2$s: how many products, e.g. "271 products"; %3$s: credits, e.g. "1,355 to 2,710 credits" */
			'ffFillSentence' => __( '%1$s is missing on about %2$s of the types that usually have it. Filling them costs about %3$s, charged only as content is generated.', 'agentic-commerce-engine' ),
			/* translators: %s: a field name */
			'ffFillTitle' => __( 'Fill %s when it is empty?', 'agentic-commerce-engine' ),
			'ffFixedList' => __( 'Always one of the values in this field\'s own list, spelled as the list spells it.', 'agentic-commerce-engine' ),
			'ffHowTitle' => __( 'How Rezolve Ai writes this field', 'agentic-commerce-engine' ),
			'ffInFormat' => __( 'In this format', 'agentic-commerce-engine' ),
			'ffKeepTitle' => __( 'Keep exactly as they are', 'agentic-commerce-engine' ),
			'ffKindDescription' => __( 'Product description', 'agentic-commerce-engine' ),
			'ffKindDestination' => __( 'Holds content Rezolve Ai places there', 'agentic-commerce-engine' ),
			'ffKindField' => __( 'Custom field', 'agentic-commerce-engine' ),
			'ffKindList' => __( 'Custom field with a list of values', 'agentic-commerce-engine' ),
			'ffLabelsTitle' => __( 'Labels by product type', 'agentic-commerce-engine' ),
			/* translators: %s: a date */
			'ffLearnedOn' => __( 'Learned %s', 'agentic-commerce-engine' ),
			/* translators: %s: names of parts of a format, comma separated */
			'ffLeftOut' => __( 'Left out in this format: %s.', 'agentic-commerce-engine' ),
			'ffLoadError' => __( 'Your field formats could not be loaded. Try again in a moment.', 'agentic-commerce-engine' ),
			'ffLoading' => __( 'Loading your field formats…', 'agentic-commerce-engine' ),
			'ffLockKept' => __( 'Closing sentence', 'agentic-commerce-engine' ),
			'ffLockKeptHint' => __( 'The sentence your values end with stays as it is.', 'agentic-commerce-engine' ),
			'ffLockLinks' => __( 'Links', 'agentic-commerce-engine' ),
			'ffLockLinksHint' => __( 'Every link in the current text stays, word for word.', 'agentic-commerce-engine' ),
			'ffLockSpecs' => __( 'Details list', 'agentic-commerce-engine' ),
			'ffLockSpecsHint' => __( 'The current list of details stays row for row.', 'agentic-commerce-engine' ),
			'ffModeCustom' => __( 'Your own template', 'agentic-commerce-engine' ),
			'ffModeDefault' => __( 'Rezolve Ai default', 'agentic-commerce-engine' ),
			'ffModeDefaultHint' => __( 'The layout Rezolve Ai writes when no format is chosen.', 'agentic-commerce-engine' ),
			'ffModeList' => __( 'From its list', 'agentic-commerce-engine' ),
			'ffModeMatch' => __( 'Match my store', 'agentic-commerce-engine' ),
			'ffModeMatchHint' => __( 'Written the way your products already are.', 'agentic-commerce-engine' ),
			'ffModePreset' => __( 'Preset', 'agentic-commerce-engine' ),
			'ffNoLayout' => __( 'Rezolve Ai default has no fixed layout to preview: it follows the content type.', 'agentic-commerce-engine' ),
			'ffNotAlike' => __( 'Not alike enough to copy', 'agentic-commerce-engine' ),
			'ffNotLearned' => __( 'Not learned yet', 'agentic-commerce-engine' ),
			'ffNotOn' => __( 'Field formats are not switched on for this site yet, so content is written in Rezolve Ai\'s own layout for now. What is learned here, and the choices you make, are used as soon as they are.', 'agentic-commerce-engine' ),
			'ffNow' => __( 'Now', 'agentic-commerce-engine' ),
			'ffOwnLayout' => __( 'Rezolve Ai\'s own layout, sized for the content type it writes.', 'agentic-commerce-engine' ),
			'ffPartsTitle' => __( 'Parts, in order', 'agentic-commerce-engine' ),
			'ffPreviewError' => __( 'This format could not be previewed.', 'agentic-commerce-engine' ),
			'ffPreviewNote' => __( 'Your product\'s own words, laid out in this format. Free: nothing is written or charged.', 'agentic-commerce-engine' ),
			'ffPreviewTitle' => __( 'Preview', 'agentic-commerce-engine' ),
			'ffPreviewing' => __( 'Laying out the preview…', 'agentic-commerce-engine' ),
			/* translators: %s: a number of products */
			'ffProductsMany' => __( '%s products', 'agentic-commerce-engine' ),
			/* translators: %s: the number 1 */
			'ffProductsOne' => __( '%s product', 'agentic-commerce-engine' ),
			'ffRelearn' => __( 'Learn again', 'agentic-commerce-engine' ),
			'ffRelearnSent' => __( 'Learning your formats again. This takes a minute or two.', 'agentic-commerce-engine' ),
			'ffSave' => __( 'Save', 'agentic-commerce-engine' ),
			'ffSaveAndFill' => __( 'Save and turn on filling', 'agentic-commerce-engine' ),
			/* translators: %s: a field name */
			'ffSaved' => __( '%s saved.', 'agentic-commerce-engine' ),
			/* translators: %s: names of parts of a format, comma separated */
			'ffWrittenLater' => __( 'Written when content is generated: %s.', 'agentic-commerce-engine' ),
			'currentValue'             => __( 'Now', 'agentic-commerce-engine' ),
			'proposedValue'            => __( 'Rewrite', 'agentic-commerce-engine' ),
			'product'                  => __( 'product', 'agentic-commerce-engine' ),
			'products'                 => __( 'products', 'agentic-commerce-engine' ),
			/* translators: %s: credit balance. */
			'healthOk'          => __( 'Connection healthy. %s credits available.', 'agentic-commerce-engine' ),

			/* Accessibility (4.27.0): control names, table headers and captions */
			'loading'            => __( 'Loading', 'agentic-commerce-engine' ),
			'dismissMessage'     => __( 'Dismiss message', 'agentic-commerce-engine' ),
			/* translators: %s: product name. */
			'selectProduct'      => __( 'Select %s', 'agentic-commerce-engine' ),
			/* translators: %s: product name. */
			'showDetailsFor'     => __( 'Show details for %s', 'agentic-commerce-engine' ),
			'colAttribute'       => __( 'Attribute', 'agentic-commerce-engine' ),
			'colValues'          => __( 'Values', 'agentic-commerce-engine' ),
			'colOutcome'         => __( 'What happens', 'agentic-commerce-engine' ),
			'colImage'           => __( 'Image', 'agentic-commerce-engine' ),
			'colField'           => __( 'Field', 'agentic-commerce-engine' ),
			'colActions'         => __( 'Actions', 'agentic-commerce-engine' ),
			'briefsCaption'      => __( 'Project briefs', 'agentic-commerce-engine' ),
			'postsCaption'       => __( 'Articles in WordPress', 'agentic-commerce-engine' ),
			'botsPagesCaption'   => __( 'Most crawled pages', 'agentic-commerce-engine' ),
			'promptsCaption'     => __( 'Research prompts', 'agentic-commerce-engine' ),
			'competitorsCaption' => __( 'Share of answers by brand', 'agentic-commerce-engine' ),
			'runsCaption'        => __( 'Research runs', 'agentic-commerce-engine' ),
			'runResultsCaption'  => __( 'Results by prompt', 'agentic-commerce-engine' ),
			'colStep'            => __( 'Step', 'agentic-commerce-engine' ),
			'colState'           => __( 'State', 'agentic-commerce-engine' ),
			'colDetail'          => __( 'Detail', 'agentic-commerce-engine' ),
			'colCheck'           => __( 'Check', 'agentic-commerce-engine' ),
			'colTerm'            => __( 'Term', 'agentic-commerce-engine' ),
			'colMeaning'         => __( 'Meaning', 'agentic-commerce-engine' ),
			'checksCaption'      => __( 'Measurement checks', 'agentic-commerce-engine' ),
			'yourTests'          => __( 'Your tests', 'agentic-commerce-engine' ),
			'historyCaption'     => __( 'Finished tests', 'agentic-commerce-engine' ),
			/* translators: %s: the name of an experiment. */
			'noteLabel'          => __( 'What you learned from %s', 'agentic-commerce-engine' ),

			/* Charts: each one's spoken summary and its data table */
			'chart'                  => __( 'Chart', 'agentic-commerce-engine' ),
			'showDataTable'          => __( 'Show data table', 'agentic-commerce-engine' ),
			'hideDataTable'          => __( 'Hide data table', 'agentic-commerce-engine' ),
			'chartNoValue'           => __( 'No data', 'agentic-commerce-engine' ),
			'chartKindLine'          => __( 'Line chart', 'agentic-commerce-engine' ),
			'chartKindArea'          => __( 'Area chart', 'agentic-commerce-engine' ),
			'chartColDate'           => __( 'Date', 'agentic-commerce-engine' ),
			'chartColValue'          => __( 'Value', 'agentic-commerce-engine' ),
			'chartColName'           => __( 'Name', 'agentic-commerce-engine' ),
			'chartColShare'          => __( 'Share', 'agentic-commerce-engine' ),
			'chartColRange'          => __( 'Range', 'agentic-commerce-engine' ),
			'chartColStep'           => __( 'Step', 'agentic-commerce-engine' ),
			'scoreRange'             => __( 'Score range', 'agentic-commerce-engine' ),
			'productsCol'            => __( 'Products', 'agentic-commerce-engine' ),
			'crawlerCol'             => __( 'Crawler', 'agentic-commerce-engine' ),
			'domainCol'              => __( 'Domain', 'agentic-commerce-engine' ),
			'difference'             => __( 'Difference', 'agentic-commerce-engine' ),
			/* translators: 1: low end of a range, 2: high end. */
			'chartRange'             => __( '%1$s to %2$s', 'agentic-commerce-engine' ),
			/* translators: 1: chart title, 2: chart kind such as "Line chart", 3: first date, 4: last date. */
			'chartSummarySeries'     => __( '%1$s. %2$s from %3$s to %4$s.', 'agentic-commerce-engine' ),
			/* translators: 1: series name, 2: lowest value, 3: highest value, 4: latest value. */
			'chartSummarySeriesLine' => __( '%1$s: lowest %2$s, highest %3$s, latest %4$s.', 'agentic-commerce-engine' ),
			/* translators: 1: chart title, 2: first date, 3: last date, 4: lowest value, 5: highest value, 6: latest value. */
			'chartSummarySpark'      => __( '%1$s. Trend from %2$s to %3$s: lowest %4$s, highest %5$s, latest %6$s.', 'agentic-commerce-engine' ),
			/* translators: 1: chart title, 2: number of bars, 3: label of the highest bar, 4: its value. */
			'chartSummaryBars'       => __( '%1$s. Bar chart of %2$s values. Highest: %3$s, %4$s.', 'agentic-commerce-engine' ),
			/* translators: 1: chart title, 2: each slice with its value and share. */
			'chartSummaryDonut'      => __( '%1$s. Donut chart: %2$s.', 'agentic-commerce-engine' ),
			/* translators: 1: what is measured, 2: value, 3: maximum, 4: percentage. */
			'chartSummaryGauge'      => __( '%1$s. %2$s of %3$s, %4$s.', 'agentic-commerce-engine' ),
			/* translators: 1: chart title, 2: a score from 0 to 100. */
			'chartSummaryRing'       => __( '%1$s. %2$s out of 100.', 'agentic-commerce-engine' ),
			/* translators: 1: chart title, 2: first date, 3: last date, 4: latest estimate, 5: its range. */
			'chartSummaryBand'       => __( '%1$s. Estimate from %2$s to %3$s. Latest %4$s, range %5$s.', 'agentic-commerce-engine' ),
			/* translators: 1: chart title, 2: each row with its estimate and range. */
			'chartSummaryForest'     => __( '%1$s. %2$s.', 'agentic-commerce-engine' ),
			/* translators: 1: chart title, 2: each version with its count at every step. */
			'chartSummaryFunnel'     => __( '%1$s. %2$s.', 'agentic-commerce-engine' ),
		);
	}

	/**
	 * Content-type catalog as a JS-friendly list for the picker.
	 *
	 * @return array
	 */
	private function content_types_for_js() {
		$out    = array();
		$server = Content_Types::server_ids( $this->plugin->articles()->cached_types() );
		foreach ( Content_Types::available( $server ) as $key => $config ) {
			$out[] = array(
				'key'         => $key,
				'label'       => $config['label'],
				'description' => $config['description'],
				'group'       => $config['group'],
				'recommended' => (bool) $config['recommended'],
				'requires'    => array_values( $config['requires'] ),
			);
		}
		return $out;
	}
}
