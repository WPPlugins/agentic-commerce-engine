<?php
/**
 * Plugin orchestrator.
 *
 * Constructs every subsystem once and exposes them via accessors. Coordinators
 * (Cron, Ajax, Admin, Webhook, Poller) receive this instance and reach siblings
 * through the accessor methods, which keeps wiring shallow and avoids circular
 * constructor dependencies.
 *
 * Since 4.0 the plugin runs in two modes: with WooCommerce the full commerce
 * suite registers its hooks; on a plain WordPress site those subsystems are
 * still constructed (their constructors are side-effect free) but never
 * register, and the admin explains the dependency instead. `has_woo()` is the one
 * switch everything gates on.
 *
 * @package Agencoen
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Plugin
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/** @var Settings */
	private $settings;
	/** @var Logger */
	private $logger;
	/** @var Cache */
	private $cache;
	/** @var Api_Client */
	private $api;
	/** @var Product_Serializer */
	private $serializer;
	/** @var Product_Repository */
	private $repository;
	/** @var Seo_Adapter */
	private $seo;
	/** @var Sync */
	private $sync;
	/** @var Apply */
	private $apply;
	/** @var Held_Back */
	private $held_back;
	/** @var Enrichment */
	private $enrichment;
	/** @var Poller */
	private $poller;
	/** @var Review */
	private $review;
	/** @var Schema */
	private $schema;
	/** @var Articles */
	private $articles;
	/** @var Publisher */
	private $publisher;
	/** @var Article_Schema */
	private $article_schema;
	/** @var Aeo */
	private $aeo;
	/** @var Bot_Tracker */
	private $bots;
	/** @var Traffic_Tracker */
	private $traffic;
	/** @var Sales_Tracker */
	private $sales;
	/** @var Ab_Test */
	private $ab_test;

	/** @var Apply_Queue */
	private $apply_queue;

	/** @var Review_Sync */
	private $review_sync;

	/** @var Quality */
	private $quality;

	/** @var Experiments */
	private $experiments;

	/** @var Ajax_Experiments */
	private $ajax_experiments;
	/** @var Brand */
	private $brand;
	/** @var Cron */
	private $cron;
	/** @var Webhook */
	private $webhook;
	/** @var Ajax */
	private $ajax;
	/** @var Ajax_Content */
	private $ajax_content;
	/** @var Ajax_Aeo */
	private $ajax_aeo;
	/** @var Ajax_Bots */
	private $ajax_bots;
	/** @var Ajax_Custom_Fields */
	private $ajax_custom_fields;
	/** @var Ajax_Field_Formats */
	private $ajax_field_formats;
	/** @var Assets */
	private $assets;
	/** @var Admin */
	private $admin;
	/** @var Frontend */
	private $frontend;
	/** @var Feeds */
	private $feeds;

	/** @var Ucp */
	private $ucp;

	/**
	 * Get (and lazily build) the singleton.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Whether WooCommerce is active (the commerce-suite switch).
	 *
	 * @return bool
	 */
	public function has_woo() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Private constructor — build the service graph.
	 */
	private function __construct() {
		$this->settings       = new Settings();
		$this->logger         = new Logger( $this->settings );
		$this->cache          = new Cache();
		$this->api            = new Api_Client( $this->settings, $this->logger );
		$this->serializer     = new Product_Serializer();
		$this->repository     = new Product_Repository();
		$this->seo            = new Seo_Adapter();
		$this->sync           = new Sync( $this );
		$this->apply          = new Apply( $this );
		$this->held_back      = new Held_Back( $this );
		$this->enrichment     = new Enrichment( $this );
		$this->poller         = new Poller( $this );
		$this->review         = new Review( $this );
		$this->schema         = new Schema( $this );
		$this->articles       = new Articles( $this );
		$this->publisher      = new Publisher( $this );
		$this->article_schema = new Article_Schema( $this );
		$this->aeo            = new Aeo( $this );
		$this->bots           = new Bot_Tracker( $this );
		$this->traffic        = new Traffic_Tracker( $this );
		$this->sales          = new Sales_Tracker( $this );
		$this->ab_test        = new Ab_Test( $this );
		$this->apply_queue    = new Apply_Queue( $this );
		$this->review_sync    = new Review_Sync( $this );
		$this->quality        = new Quality( $this );
		$this->experiments    = new Experiments( $this );
		$this->brand          = new Brand( $this );
		$this->cron           = new Cron( $this );
		$this->webhook        = new Webhook( $this );
		$this->ajax           = new Ajax( $this );
		$this->ajax_content   = new Ajax_Content( $this );
		$this->ajax_aeo       = new Ajax_Aeo( $this );
		$this->ajax_experiments = new Ajax_Experiments( $this );
		$this->ajax_bots      = new Ajax_Bots( $this );
		$this->ajax_custom_fields = new Ajax_Custom_Fields( $this );
		$this->ajax_field_formats = new Ajax_Field_Formats( $this );
		$this->assets         = new Assets( $this );
		$this->admin          = new Admin( $this );
		$this->frontend       = new Frontend( $this );
		$this->feeds          = new Feeds( $this );
		$this->ucp            = new Ucp( $this );
	}

	/**
	 * Register hooks for subsystems that have side effects.
	 * Commerce subsystems only register when WooCommerce is active.
	 */
	private function boot() {
		// Versioned upgrade path (3.x -> 4.x installs the crawler table).
		add_action( 'admin_init', array( Db::class, 'maybe_upgrade' ) );

		if ( $this->has_woo() ) {
			$this->poller->register();
			$this->schema->register();
			$this->frontend->register();
			$this->feeds->register();
		}

		// Registered unconditionally: the profile route is useful on any site,
		// and handle_buy() bails on its own when WooCommerce is absent.
		$this->ucp->register();

		$this->article_schema->register();
		$this->bots->register();
		// Registered unconditionally; both gate internally on their own opt-in,
		// so a site with the feature off pays only a boolean check per request.
		$this->traffic->register();
		$this->ab_test->register();
		$this->cron->register();
		$this->webhook->register();

		// admin-ajax handlers can only ever fire on an admin-ajax request, so
		// front-end page loads skip these hook registrations entirely.
		if ( wp_doing_ajax() ) {
			$this->ajax->register();
			$this->ajax_content->register();
			$this->ajax_aeo->register();
			$this->ajax_bots->register();
			$this->ajax_custom_fields->register();
			$this->ajax_field_formats->register();
			// Woo-gated inside its own gate chain, like the commerce actions
			// in class-ajax.php: the nonce is checked before the Woo state, so
			// a hand-crafted request cannot probe whether Woo is active.
			if ( $this->has_woo() ) {
				$this->ajax_experiments->register();
			}
		}

		$this->assets->register();
		$this->admin->register();
	}

	/* --------------------------------------------------------------------- */
	/* Accessors                                                              */
	/* --------------------------------------------------------------------- */

	/** @return Settings */
	public function settings() {
		return $this->settings;
	}
	/** @return Logger */
	public function logger() {
		return $this->logger;
	}
	/** @return Cache */
	public function cache() {
		return $this->cache;
	}
	/** @return Api_Client */
	public function api() {
		return $this->api;
	}
	/** @return Product_Serializer */
	public function serializer() {
		return $this->serializer;
	}
	/** @return Product_Repository */
	public function repository() {
		return $this->repository;
	}
	/** @return Seo_Adapter */
	public function seo() {
		return $this->seo;
	}
	/** @return Sync */
	public function sync() {
		return $this->sync;
	}
	/** @return Held_Back */
	public function held_back() {
		return $this->held_back;
	}
	/** @return Apply */
	public function apply() {
		return $this->apply;
	}
	/** @return Enrichment */
	public function enrichment() {
		return $this->enrichment;
	}
	/** @return Poller */
	public function poller() {
		return $this->poller;
	}
	/** @return Review */
	public function review() {
		return $this->review;
	}
	/** @return Schema */
	public function schema() {
		return $this->schema;
	}
	/** @return Articles */
	public function articles() {
		return $this->articles;
	}
	/** @return Publisher */
	public function publisher() {
		return $this->publisher;
	}
	/** @return Article_Schema */
	public function article_schema() {
		return $this->article_schema;
	}
	/** @return Aeo */
	public function aeo() {
		return $this->aeo;
	}
	/** @return Bot_Tracker */
	public function bots() {
		return $this->bots;
	}
	/** @return Traffic_Tracker */
	public function traffic() {
		return $this->traffic;
	}
	/** @return Sales_Tracker */
	public function sales() {
		return $this->sales;
	}
	/** @return Ab_Test */
	public function apply_queue() {
		return $this->apply_queue;
	}

	/** @return Review_Sync */
	public function review_sync() {
		return $this->review_sync;
	}

	/** @return Quality */
	public function quality() {
		return $this->quality;
	}

	public function experiments() {
		return $this->experiments;
	}

	public function ab_test() {
		return $this->ab_test;
	}
	/** @return Brand */
	public function brand() {
		return $this->brand;
	}
	/** @return Cron */
	public function cron() {
		return $this->cron;
	}
}
