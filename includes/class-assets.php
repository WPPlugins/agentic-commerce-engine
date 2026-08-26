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
