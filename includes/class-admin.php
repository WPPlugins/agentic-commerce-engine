<?php
/**
 * Registers the admin menu and renders the six screens.
 *
 * Every screen always renders. Nothing local is withheld from a site that has
 * not linked a Rezolve Ai account: the connect banner is additive, and only the
 * individual controls that call the remote service are disabled. The one
 * substitution left is the commerce screens without WooCommerce, which is a
 * plugin-dependency state rather than an account state.
 *
 * Render states per screen:
 *  - Woo missing, commerce screen -> "install WooCommerce" state
 *  - not connected                -> live screen + connect banner,
 *                                    remote-only controls disabled
 *  - connected                    -> live screen
 *
 * @package Agencoen
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Admin
 */
class Admin {

	const SLUG = 'agencoen';

	/**
	 * AI crawler visits (7-day window) a site records before the optional
	 * connect notice first appears. Until then no screen mentions an account;
	 * the notice, when it does show, can point at value the plugin has
	 * already measured.
	 */
	const CONNECT_NOTICE_MIN_HITS = 25;

	/** @var Plugin */
	private $plugin;

	/**
	 * @param Plugin $plugin Orchestrator.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Hook the menu.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_filter( 'plugin_action_links_' . AGENCOEN_PLUGIN_BASENAME, array( $this, 'action_links' ) );
	}

	/**
	 * Build the top-level menu + subpages.
	 */
	public function menu() {
		$cap = Capabilities::manage();

		add_menu_page(
			__( 'Rezolve Ai', 'agentic-commerce-engine' ),
			'Rezolve Ai',
			$cap,
			self::SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-superhero-alt',
			58
		);
		add_submenu_page( self::SLUG, __( 'Dashboard', 'agentic-commerce-engine' ), __( 'Dashboard', 'agentic-commerce-engine' ), $cap, self::SLUG, array( $this, 'render_dashboard' ) );
		add_submenu_page( self::SLUG, __( 'Products', 'agentic-commerce-engine' ), __( 'Products', 'agentic-commerce-engine' ), $cap, 'agencoen-products', array( $this, 'render_products' ) );
		add_submenu_page( self::SLUG, __( 'Review', 'agentic-commerce-engine' ), __( 'Review', 'agentic-commerce-engine' ), $cap, 'agencoen-review', array( $this, 'render_review' ) );
		add_submenu_page( self::SLUG, __( 'Content', 'agentic-commerce-engine' ), __( 'Content', 'agentic-commerce-engine' ), $cap, 'agencoen-content', array( $this, 'render_content' ) );
		add_submenu_page( self::SLUG, __( 'AI Search', 'agentic-commerce-engine' ), __( 'AI Search', 'agentic-commerce-engine' ), $cap, 'agencoen-aisearch', array( $this, 'render_aisearch' ) );
		add_submenu_page( self::SLUG, __( 'Experiments', 'agentic-commerce-engine' ), __( 'Experiments', 'agentic-commerce-engine' ), $cap, 'agencoen-experiments', array( $this, 'render_experiments' ) );
		add_submenu_page( self::SLUG, __( 'Settings', 'agentic-commerce-engine' ), __( 'Settings', 'agentic-commerce-engine' ), $cap, 'agencoen-settings', array( $this, 'render_settings' ) );
		add_submenu_page( self::SLUG, __( 'Connection', 'agentic-commerce-engine' ), __( 'Connection', 'agentic-commerce-engine' ), $cap, 'agencoen-connection', array( $this, 'render_connection' ) );
	}

	/**
	 * Add a "Settings" link on the Plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ) {
		$url = admin_url( 'admin.php?page=agencoen-settings' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'agentic-commerce-engine' ) . '</a>' );
		return $links;
	}

	/** Render: Dashboard. */
	public function render_dashboard() {
		$this->render( 'page-dashboard.php', __( 'Dashboard', 'agentic-commerce-engine' ) );
	}

	/** Render: Products (WooCommerce only). */
	public function render_products() {
		$this->render( 'page-products.php', __( 'Products', 'agentic-commerce-engine' ), true );
	}

	/** Render: Review (WooCommerce only). */
	public function render_review() {
		$this->render( 'page-review.php', __( 'Review', 'agentic-commerce-engine' ), true );
	}

	/** Render: Content. */
	public function render_content() {
		$this->render( 'page-content.php', __( 'Content', 'agentic-commerce-engine' ) );
	}

	/** Render: AI Search. */
	public function render_aisearch() {
		$this->render( 'page-aisearch.php', __( 'AI Search', 'agentic-commerce-engine' ) );
	}

	/**
	 * Render: Experiments (WooCommerce only).
	 *
	 * The third argument is the Woo gate, so a Woo-less site sees the same
	 * "install WooCommerce" state Products and Review show rather than an empty
	 * screen that looks broken. Every subject is a WooCommerce product.
	 */
	public function render_experiments() {
		$this->render( 'page-experiments.php', __( 'Experiments', 'agentic-commerce-engine' ), true );
	}

	/** Render: Settings (local preferences only). */
	public function render_settings() {
		$this->render( 'page-settings.php', __( 'Settings', 'agentic-commerce-engine' ) );
	}

	/** Render: Connection (the optional Rezolve Ai account lives here, and only here). */
	public function render_connection() {
		$this->render( 'page-connection.php', __( 'Connection', 'agentic-commerce-engine' ) );
	}

	/**
	 * Shared chrome. The WooCommerce dependency is the only state that can
	 * replace a screen; connection state only adds a banner.
	 *
	 * @param string $view      View file under admin/views/.
	 * @param string $title     Screen title for the header.
	 * @param bool   $needs_woo Commerce screen: show the install state without WooCommerce.
	 */
	private function render( $view, $title, $needs_woo = false ) {
		$plugin    = $this->plugin;
		$connected = $plugin->settings()->is_connected();
		$has_woo   = $plugin->has_woo();
		$data      = Dashboard::data( $plugin );

		echo '<div class="wrap agencoen-wrap">';
		include AGENCOEN_PLUGIN_DIR . 'admin/views/partial-header.php';

		if ( $needs_woo && ! $has_woo ) {
			include AGENCOEN_PLUGIN_DIR . 'admin/views/partial-requires-woo.php';
		} else {
			// The Connection screen hosts the connect form itself, and the notice
			// is dismissible, so it is not a permanent nag on the other screens.
			// A fresh install never sees it: it waits until the local crawler
			// tracker has recorded real AI traffic worth talking about.
			$agencoen_recent_hits = isset( $data['bots']['total'] ) ? (int) $data['bots']['total'] : 0;
			if ( 'page-connection.php' !== $view && self::connect_notice_due( $connected, Settings::connect_notice_dismissed(), $agencoen_recent_hits ) ) {
				include AGENCOEN_PLUGIN_DIR . 'admin/views/partial-connect-banner.php';
			}
			// Connected, but sending nothing. Not shown on Settings itself,
			// which is where the switches are and where the banner would be
			// pointing at a control already on screen.
			if ( 'page-settings.php' !== $view && self::traffic_notice_due(
				$connected,
				$plugin->settings()->is_traffic_report(),
				Settings::traffic_notice_dismissed()
			) ) {
				include AGENCOEN_PLUGIN_DIR . 'admin/views/partial-traffic-banner.php';
			}
			include AGENCOEN_PLUGIN_DIR . 'admin/views/' . $view;
		}
		echo '</div>';
	}

	/**
	 * Pure gate for the optional connect notice: only for unconnected users who
	 * have not dismissed it, and only once the site's own crawler analytics
	 * have something to show (so the ask leads with measured value, and a
	 * fresh install is never prompted for an account).
	 *
	 * @param bool $connected   Store connected.
	 * @param bool $dismissed   Current user dismissed the notice.
	 * @param int  $recent_hits AI crawler hits in the recent window.
	 * @return bool
	 */
	public static function connect_notice_due( $connected, $dismissed, $recent_hits ) {
		return ! $connected && ! $dismissed && (int) $recent_hits >= self::CONNECT_NOTICE_MIN_HITS;
	}

	/**
	 * Pure gate for the "measurement is off" notice.
	 *
	 * Only for CONNECTED sites: an unconnected one has nowhere to report to and
	 * is already being offered an account by the notice above. Reporting is the
	 * switch that matters here, not collection: a site can count its own
	 * pageviews locally all it likes and Rezolve Ai still measures nothing.
	 *
	 * WHY THIS EXISTS. Both traffic options default to 'no' and nothing ever
	 * asked. On the day it was added, every experiment and attribution feature
	 * on the Rezolve Ai side was reading a table that had never received a row
	 * from any site, and no screen anywhere said so.
	 *
	 * @param bool $connected Store connected.
	 * @param bool $reporting Daily totals are being sent.
	 * @param bool $dismissed Current user dismissed the notice.
	 * @return bool
	 */
	public static function traffic_notice_due( $connected, $reporting, $dismissed ) {
		return (bool) $connected && ! $reporting && ! $dismissed;
	}

	/* --------------------------------------------------------------------- */
	/* View helpers (used inside view files)                                 */
	/* --------------------------------------------------------------------- */

	/**
	 * Render a status badge.
	 *
	 * @param string $status Status value.
	 * @return string HTML.
	 */
	public static function status_badge( $status ) {
		$map = array(
			Meta::STATUS_QUEUED    => array( 'info', __( 'Queued', 'agentic-commerce-engine' ) ),
			Meta::STATUS_ENRICHED  => array( 'warning', __( 'Ready to review', 'agentic-commerce-engine' ) ),
			Meta::STATUS_APPLIED   => array( 'success', __( 'Applied', 'agentic-commerce-engine' ) ),
			Meta::STATUS_DISMISSED => array( 'neutral', __( 'Dismissed', 'agentic-commerce-engine' ) ),
			Meta::STATUS_FAILED    => array( 'error', __( 'Failed', 'agentic-commerce-engine' ) ),
		);
		if ( ! isset( $map[ $status ] ) ) {
			return '<span class="ace-badge ace-badge-neutral">' . esc_html__( 'Not enriched', 'agentic-commerce-engine' ) . '</span>';
		}
		list( $tone, $label ) = $map[ $status ];
		return '<span class="ace-badge ace-badge-' . esc_attr( $tone ) . '">' . esc_html( $label ) . '</span>';
	}
}
