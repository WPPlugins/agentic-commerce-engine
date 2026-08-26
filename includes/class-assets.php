<?php
/**
 * Enqueues the v4 admin bundle on ACE screens only: the design-system
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
	 * Enqueue assets on ACE screens only.
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

		wp_enqueue_script(
			'agencoen-screen-' . $screen,
			AGENCOEN_PLUGIN_URL . 'assets/js/screens/' . $screen . '.js',
			array( 'agencoen-core', 'agencoen-charts' ),
			AGENCOEN_VERSION,
			true
		);

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
	 * @return string Screen key, or '' when this is not an ACE screen.
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
			'accountLink'       => __( 'Open your ACE account', 'agentic-commerce-engine' ),
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
			'confirmDisconnect' => __( 'Disconnect this site from ACE? Your API key will be removed.', 'agentic-commerce-engine' ),

			/* Sync + enrichment */
			'syncing'           => __( 'Syncing…', 'agentic-commerce-engine' ),
			'syncDone'          => __( 'Sync complete.', 'agentic-commerce-engine' ),
			'enriching'         => __( 'Sending to AI…', 'agentic-commerce-engine' ),
			'enrichQueued'      => __( 'Queued for enrichment. Results appear as they finish.', 'agentic-commerce-engine' ),
			'chooseTypes'       => __( 'Choose what to enrich', 'agentic-commerce-engine' ),
			'enrichNow'         => __( 'Enrich now', 'agentic-commerce-engine' ),
			'pickAtLeastOne'    => __( 'Pick at least one content type.', 'agentic-commerce-engine' ),
			'onPage'            => __( 'On the product page', 'agentic-commerce-engine' ),
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
			'dismissed'         => __( 'Dismissed.', 'agentic-commerce-engine' ),
			'reverted'          => __( 'Reverted to the original.', 'agentic-commerce-engine' ),
			'approve'           => __( 'Approve', 'agentic-commerce-engine' ),
			'dismiss'           => __( 'Dismiss', 'agentic-commerce-engine' ),
			'revertToOriginal'  => __( 'Revert to original', 'agentic-commerce-engine' ),
			'edit'              => __( 'Edit', 'agentic-commerce-engine' ),
			'current'           => __( 'Current', 'agentic-commerce-engine' ),
			'aiSuggestion'      => __( 'AI suggestion', 'agentic-commerce-engine' ),
			'noChange'          => __( 'no change', 'agentic-commerce-engine' ),
			'emptyValue'        => __( '(empty)', 'agentic-commerce-engine' ),
			/* translators: %s: AI quality score. */
			'scoreLabel'        => __( 'Score %s', 'agentic-commerce-engine' ),
			/* translators: %s: number of scenarios. */
			'irlTitle'          => __( 'IRL scenarios (%s)', 'agentic-commerce-engine' ),
			'internalLinks'     => __( 'Internal links', 'agentic-commerce-engine' ),
			'jsonldTitle'       => __( 'JSON-LD schema', 'agentic-commerce-engine' ),
			/* translators: %s: feed channel key. */
			'feedTitle'         => __( '%s feed', 'agentic-commerce-engine' ),

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
			'shareOfMentions'   => __( 'Share of Brand Mentions', 'agentic-commerce-engine' ),
			'sovSub'            => __( 'of AI answers mention your brand', 'agentic-commerce-engine' ),
			'notMeasured'       => __( 'Not measured yet', 'agentic-commerce-engine' ),
			'sovSetup'          => __( 'Run AI search research to measure this.', 'agentic-commerce-engine' ),
			'optimized'         => __( 'optimized', 'agentic-commerce-engine' ),
			'appliedPerDay'     => __( 'Products applied per day', 'agentic-commerce-engine' ),

			/* AI Search */
			'aeoNotConfigured'  => __( 'AI Search research is not set up for this ACE account yet. Finish setup in the ACE app, then reload this page. The Crawlers tab works either way.', 'agentic-commerce-engine' ),
			'estimatePrefix'    => __( 'Estimated cost:', 'agentic-commerce-engine' ),

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
			'whereDropOff'      => __( 'Where shoppers drop off', 'agentic-commerce-engine' ),
			'measuredProperly'  => __( 'Is this being measured properly?', 'agentic-commerce-engine' ),
			'unattributed'      => __( 'shoppers could not be tied to this test because another split was running at the same time, so they are left out rather than guessed at.', 'agentic-commerce-engine' ),
			'whatItFound'       => __( 'What it found', 'agentic-commerce-engine' ),
			'nothingComputed'   => __( 'Nothing has been computed yet. Results are worked out overnight, once there is enough traffic for a comparison to mean anything.', 'agentic-commerce-engine' ),
			'whatWordsMean'     => __( 'What these words mean', 'agentic-commerce-engine' ),
			'version'           => __( 'Version', 'agentic-commerce-engine' ),
			'versions'          => __( 'versions', 'agentic-commerce-engine' ),
			'change'            => __( 'Change', 'agentic-commerce-engine' ),
			'range'             => __( 'Range', 'agentic-commerce-engine' ),
			'reading'           => __( 'Reading', 'agentic-commerce-engine' ),
			'differenceLabel'   => __( 'Against your original copy', 'agentic-commerce-engine' ),
			'likelyBetween'     => __( 'likely between', 'agentic-commerce-engine' ),
			'newContent'        => __( 'New content', 'agentic-commerce-engine' ),
			'leftUnchanged'     => __( 'Left unchanged', 'agentic-commerce-engine' ),
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
			'botsReported'          => __( 'Crawler summary sent to ACE.', 'agentic-commerce-engine' ),
			'vsPrevRun'             => __( 'vs previous run', 'agentic-commerce-engine' ),
			'shareOfAnswers'        => __( 'Share of answers', 'agentic-commerce-engine' ),
			'promptsTracked'        => __( 'Prompts tracked', 'agentic-commerce-engine' ),
			'aiResponses'           => __( 'AI responses', 'agentic-commerce-engine' ),
			'lastRun'               => __( 'Latest run', 'agentic-commerce-engine' ),
			'mentionRate'           => __( 'Brand mention rate', 'agentic-commerce-engine' ),
			'answersWithBrand'      => __( 'Mention your brand', 'agentic-commerce-engine' ),
			'answersWithoutBrand'   => __( 'No brand mention', 'agentic-commerce-engine' ),
			'promptsNoMatch'        => __( 'No prompts match your filter.', 'agentic-commerce-engine' ),
			'promptsNone'           => __( 'No prompts saved yet. Add prompts from the Runs tab.', 'agentic-commerce-engine' ),
			'promptCol'             => __( 'Prompt', 'agentic-commerce-engine' ),
			'tagsCol'               => __( 'Tags', 'agentic-commerce-engine' ),
			'competitorsNone'       => __( 'No entity data yet. Launch a research run from the Runs tab first.', 'agentic-commerce-engine' ),
			'brandCol'              => __( 'Brand', 'agentic-commerce-engine' ),
			'mentionsCol'           => __( 'Answer mentions', 'agentic-commerce-engine' ),
			'yourBrand'             => __( 'Your brand', 'agentic-commerce-engine' ),
			'promptsOnFlow'         => __( 'prompts saved on your research flow.', 'agentic-commerce-engine' ),
			'flowDefault'           => __( 'Flow default', 'agentic-commerce-engine' ),
			'noSuggestions'         => __( 'No suggestions came back. Try adding a topic.', 'agentic-commerce-engine' ),
			'suggestionsAdded'      => __( 'suggested prompts added to the editor.', 'agentic-commerce-engine' ),
			'promptsRequired'       => __( 'Add at least one prompt (three characters or more, one per line).', 'agentic-commerce-engine' ),
			'confirmReplacePrompts' => __( 'Replace all saved prompts with the lines in the editor?', 'agentic-commerce-engine' ),
			'promptsSaved'          => __( 'Prompts saved.', 'agentic-commerce-engine' ),
			'runLaunched'           => __( 'Run launched. Results usually arrive within a few minutes.', 'agentic-commerce-engine' ),
			'flowFieldsRequired'    => __( 'Enter a flow name and your brand name.', 'agentic-commerce-engine' ),
			'flowCreated'           => __( 'Research flow created.', 'agentic-commerce-engine' ),
			'estimateReady'         => __( 'Estimate:', 'agentic-commerce-engine' ),
			'estimateEmpty'         => __( 'The estimate came back empty. You can launch the run directly.', 'agentic-commerce-engine' ),
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
			'statBriefsSub'    => __( 'In your ACE project', 'agentic-commerce-engine' ),
			'statDrafts'       => __( 'WP drafts', 'agentic-commerce-engine' ),
			'statDraftsSub'    => __( 'Waiting to go live', 'agentic-commerce-engine' ),
			'statPublished'    => __( 'Published', 'agentic-commerce-engine' ),
			'statPublishedSub' => __( 'Live on this site', 'agentic-commerce-engine' ),
			'addVoice'         => __( 'Add a brand voice in ACE', 'agentic-commerce-engine' ),
			'writingAs'        => __( 'Writing as %s.', 'agentic-commerce-engine' ),
			'typesBuiltin'     => __( 'Built in', 'agentic-commerce-engine' ),
			'typesPresets'     => __( 'Presets', 'agentic-commerce-engine' ),
			'typesCustom'      => __( 'Custom', 'agentic-commerce-engine' ),
			'typeDefault'      => __( 'Standard article', 'agentic-commerce-engine' ),
			'phaseQueued'      => __( 'Queued', 'agentic-commerce-engine' ),
			'phaseWorking'     => __( 'Working', 'agentic-commerce-engine' ),
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
			'noPosts'          => __( 'No ACE articles in WordPress yet. Generated drafts will appear here.', 'agentic-commerce-engine' ),
			'colDate'          => __( 'Date', 'agentic-commerce-engine' ),
			'rowRepublish'     => __( 'Republish latest', 'agentic-commerce-engine' ),
			'postsFailed'      => __( 'Could not load articles.', 'agentic-commerce-engine' ),
			'republished'      => __( 'Updated with the latest version from ACE.', 'agentic-commerce-engine' ),
			'draftUpdated'     => __( 'Draft updated with the latest version.', 'agentic-commerce-engine' ),

			/* Experiments screen */
			'andBetween'     => __( 'and', 'agentic-commerce-engine' ),
			'ofProductsLive' => __( 'of', 'agentic-commerce-engine' ),
			'productsLive'   => __( 'products have a second version live', 'agentic-commerce-engine' ),

			'whatItIsWorth'    => __( 'What each version is worth', 'agentic-commerce-engine' ),
			'whatItIsWorthSub' => __( 'Revenue divided by shoppers, counted from your own store. Only shown because real order values were reported.', 'agentic-commerce-engine' ),
			'aboutAYear'       => __( 'About', 'agentic-commerce-engine' ),
			'aYearIfYouKeep'   => __( 'a year if you keep the winner', 'agentic-commerce-engine' ),
			'shoppers'         => __( 'Shoppers', 'agentic-commerce-engine' ),
			'revenue'          => __( 'Revenue', 'agentic-commerce-engine' ),
			'perShopper'       => __( 'Per shopper', 'agentic-commerce-engine' ),
			'preflightFailed'  => __( 'Could not check that design.', 'agentic-commerce-engine' ),
			'preflightRefused' => __( 'This design cannot be checked yet', 'agentic-commerce-engine' ),

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

			/* Settings screen */
			'feedUrlsRegenerated' => __( 'Feed URLs regenerated. Update them wherever they are configured.', 'agentic-commerce-engine' ),

			/* Settings */
			'settingsSaved'     => __( 'Settings saved.', 'agentic-commerce-engine' ),
			/* translators: %s: credit balance. */
			'healthOk'          => __( 'Connection healthy. %s credits available.', 'agentic-commerce-engine' ),
		);
	}

	/**
	 * Content-type catalog as a JS-friendly list for the picker.
	 *
	 * @return array
	 */
	private function content_types_for_js() {
		$out = array();
		foreach ( Content_Types::all() as $key => $config ) {
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
