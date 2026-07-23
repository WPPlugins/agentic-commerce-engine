<?php
/**
 * AI Search (Ecom AEO) service: a thin caching layer over the platform's
 * /api/v1/plugin/aeo/* endpoints plus pure normalizers for chart shapes.
 *
 * Reads are cached per group 'aeo' (config 15 min, overview 5 min, runs 60s);
 * every write (prompts, run launch, flow creation) busts the whole group so
 * the next read is fresh. The static normalizers are pure PHP (no WordPress
 * calls) so tests/test-runner.php can cover them directly.
 *
 * @package Agencoen
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Aeo
 */
class Aeo {

	const GROUP = 'aeo';

	/** Highlight color for the site's own brand in entity bars. */
	const COLOR_YOURS = '#6366f1';

	/** Muted color for competitor entities. */
	const COLOR_OTHERS = '#94a3b8';

	/** @var Plugin */
	private $plugin;

	/**
	 * @param Plugin $plugin Orchestrator.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/* --------------------------------------------------------------------- */
	/* Reads (cached)                                                        */
	/* --------------------------------------------------------------------- */

	/**
	 * Research configurator payload (flow, defaults, engine/region/language
	 * catalogs, prompt count).
	 *
	 * @param bool $fresh Skip the cache.
	 * @return array|\WP_Error
	 */
	public function config( $fresh = false ) {
		if ( $fresh ) {
			$this->plugin->cache()->forget( self::GROUP, 'config' );
		}
		return $this->plugin->cache()->remember(
			self::GROUP,
			'config',
			15 * MINUTE_IN_SECONDS,
			function () {
				return $this->plugin->api()->aeo_config( $this->store_id() );
			},
			3 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * Overview KPIs + trend (snapshot-backed; safe to poll).
	 *
	 * @param string|null $run_id Optional run to focus.
	 * @param bool        $fresh  Skip the cache.
	 * @return array|\WP_Error
	 */
	public function overview( $run_id = null, $fresh = false ) {
		$run_id = is_string( $run_id ) && '' !== $run_id ? $run_id : null;
		$key    = 'overview_' . ( $run_id ? md5( $run_id ) : 'latest' );
		if ( $fresh ) {
			$this->plugin->cache()->forget( self::GROUP, $key );
		}
		return $this->plugin->cache()->remember(
			self::GROUP,
			$key,
			5 * MINUTE_IN_SECONDS,
			function () use ( $run_id ) {
				return $this->plugin->api()->aeo_overview( $this->store_id(), $run_id );
			},
			3 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * Reduced overview for the dashboard tile. Never throws; anything short of
	 * a usable snapshot (not connected, feature off, error, no data) is null.
	 *
	 * @param bool $allow_remote When false (render paths), only an already
	 *                           cached overview is used; a cold cache returns
	 *                           null without any remote HTTP.
	 * @return array{shareOfAnswersPct:float|null,brandMentionRatePct:float|null,trend:array}|null
	 */
	public function overview_preview( $allow_remote = true ) {
		$settings = $this->plugin->settings();
		if ( ! $settings->is_connected() || ! $settings->feature_enabled( 'ecomAeo' ) ) {
			return null;
		}

		if ( ! $allow_remote && ! $this->has_cached_overview() ) {
			return null;
		}

		$overview = $this->overview();
		if ( is_wp_error( $overview ) || ! is_array( $overview ) || empty( $overview['hasData'] ) ) {
			return null;
		}

		$kpis = isset( $overview['kpis'] ) && is_array( $overview['kpis'] ) ? $overview['kpis'] : array();
		return array(
			'shareOfAnswersPct'   => isset( $kpis['shareOfAnswersPct'] ) && is_numeric( $kpis['shareOfAnswersPct'] ) ? (float) $kpis['shareOfAnswersPct'] : null,
			'brandMentionRatePct' => isset( $kpis['brandMentionRatePct'] ) && is_numeric( $kpis['brandMentionRatePct'] ) ? (float) $kpis['brandMentionRatePct'] : null,
			'trend'               => isset( $overview['trend'] ) && is_array( $overview['trend'] ) ? $overview['trend'] : array(),
		);
	}

	/**
	 * Whether the latest overview is present in the cache (a stored error
	 * sentinel counts: it means a fetch just failed and is backing off).
	 *
	 * @return bool
	 */
	public function has_cached_overview() {
		return false !== $this->plugin->cache()->get( self::GROUP, 'overview_latest' );
	}

	/**
	 * Run list with snapshot presence (60s cache so polling stays cheap).
	 *
	 * @param bool $fresh Skip the cache.
	 * @return array|\WP_Error
	 */
	public function runs( $fresh = false ) {
		if ( $fresh ) {
			$this->plugin->cache()->forget( self::GROUP, 'runs' );
		}
		return $this->plugin->cache()->remember(
			self::GROUP,
			'runs',
			MINUTE_IN_SECONDS,
			function () {
				return $this->plugin->api()->aeo_runs( $this->store_id() );
			},
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Per-run detail. Cached 5 minutes once the snapshot exists; while the
	 * snapshot is still building the response is never cached, so the next
	 * poll sees fresh status.
	 *
	 * @param string $run_id Run id.
	 * @return array|\WP_Error
	 */
	public function run( $run_id ) {
		$run_id = (string) $run_id;
		$key    = 'run_' . md5( $run_id );
		$cache  = $this->plugin->cache();

		$hit = $cache->get( self::GROUP, $key );
		if ( false !== $hit ) {
			return $hit;
		}

		$result = $this->plugin->api()->aeo_run( $this->store_id(), $run_id );
		if ( ! is_wp_error( $result ) && is_array( $result ) && isset( $result['snapshotPending'] ) && false === (bool) $result['snapshotPending'] ) {
			$cache->set( self::GROUP, $key, $result, 5 * MINUTE_IN_SECONDS );
		}
		return $result;
	}

	/**
	 * Research prompt list (short cache; every save busts the group).
	 *
	 * @param int $page Page (1-based).
	 * @return array|\WP_Error
	 */
	public function prompts( $page = 1 ) {
		$page = max( 1, (int) $page );
		return $this->plugin->cache()->remember(
			self::GROUP,
			'prompts_' . $page,
			2 * MINUTE_IN_SECONDS,
			function () use ( $page ) {
				return $this->plugin->api()->aeo_prompts( $this->store_id(), $page );
			}
		);
	}

	/* --------------------------------------------------------------------- */
	/* Writes (bust the group)                                               */
	/* --------------------------------------------------------------------- */

	/**
	 * Append or replace the flow's prompt set.
	 *
	 * @param array  $prompts [{ text, tags? }].
	 * @param string $mode    append|replace.
	 * @return array|\WP_Error
	 */
	public function save_prompts( array $prompts, $mode = 'append' ) {
		$result = $this->plugin->api()->aeo_save_prompts( $this->store_id(), $prompts, $mode );
		if ( ! is_wp_error( $result ) ) {
			$this->plugin->cache()->bust( self::GROUP );
		}
		return $result;
	}

	/**
	 * AI prompt suggestions grounded in the synced catalog (billed; uncached).
	 *
	 * @param array $args { topic?, branded?, regionLabel?, languageLabel? }.
	 * @return array|\WP_Error
	 */
	public function suggest( array $args = array() ) {
		$args['storeId'] = $this->store_id();
		return $this->plugin->api()->aeo_suggest_prompts( $args );
	}

	/**
	 * Launch a research run.
	 *
	 * @param array $args { models?, regionCode?, languageCode?, useFlowDefaults? }.
	 * @return array|\WP_Error
	 */
	public function start_run( array $args = array() ) {
		$args['storeId'] = $this->store_id();
		$result          = $this->plugin->api()->aeo_start_run( $args );
		if ( ! is_wp_error( $result ) ) {
			$this->plugin->cache()->bust( self::GROUP );
		}
		return $result;
	}

	/**
	 * Credit estimate for a run configuration (no launch, no cache bust).
	 *
	 * @param array $args Same shape as start_run().
	 * @return array|\WP_Error
	 */
	public function estimate( array $args = array() ) {
		$args['storeId']      = $this->store_id();
		$args['estimateOnly'] = true;
		return $this->plugin->api()->aeo_start_run( $args );
	}

	/**
	 * Create + link the project's research flow.
	 *
	 * @param array $args { name, brandName, description? }.
	 * @return array|\WP_Error
	 */
	public function create_flow( array $args ) {
		$args['storeId'] = $this->store_id();
		$result          = $this->plugin->api()->aeo_create_flow( $args );
		if ( ! is_wp_error( $result ) ) {
			$this->plugin->cache()->bust( self::GROUP );
		}
		return $result;
	}

	/**
	 * @return string
	 */
	private function store_id() {
		return $this->plugin->settings()->get_store_id();
	}

	/* --------------------------------------------------------------------- */
	/* Pure normalizers (no WordPress calls; unit-tested)                    */
	/* --------------------------------------------------------------------- */

	/**
	 * Overview trend rows -> SOV line points.
	 *
	 * @param array $trend [{ runId, date, shareOfAnswersPct, brandMentionRatePct }].
	 * @return array<int,array{x:string,y:float}>
	 */
	public static function normalize_trend( array $trend ) {
		$points = array();
		foreach ( $trend as $row ) {
			if ( ! is_array( $row ) || empty( $row['date'] ) ) {
				continue;
			}
			$timestamp = strtotime( (string) $row['date'] );
			if ( false === $timestamp ) {
				continue;
			}
			if ( ! isset( $row['shareOfAnswersPct'] ) || ! is_numeric( $row['shareOfAnswersPct'] ) ) {
				continue;
			}
			$points[] = array(
				'x' => gmdate( 'M j', $timestamp ),
				'y' => (float) $row['shareOfAnswersPct'],
			);
		}
		return $points;
	}

	/**
	 * Snapshot entities -> horizontal bar rows (top 10, own brand highlighted).
	 *
	 * @param array $entities [{ entity, total, isYours }].
	 * @return array<int,array{label:string,value:int,color:string}>
	 */
	public static function entities_to_bars( array $entities ) {
		$rows = array();
		foreach ( $entities as $entity ) {
			if ( ! is_array( $entity ) || ! isset( $entity['entity'] ) || '' === (string) $entity['entity'] ) {
				continue;
			}
			$rows[] = array(
				'label' => (string) $entity['entity'],
				'value' => isset( $entity['total'] ) && is_numeric( $entity['total'] ) ? (int) $entity['total'] : 0,
				'color' => ! empty( $entity['isYours'] ) ? self::COLOR_YOURS : self::COLOR_OTHERS,
			);
		}
		usort(
			$rows,
			function ( $a, $b ) {
				return $b['value'] <=> $a['value'];
			}
		);
		return array_slice( $rows, 0, 10 );
	}

	/**
	 * Snapshot prompt row -> flat table row.
	 *
	 * @param array $p { question, model, brandMentioned, citations[], topEntities[] }.
	 * @return array{question:string,model:string,brandMentioned:bool,citations:int,topEntities:string}
	 */
	public static function prompt_row( array $p ) {
		$entities = array();
		if ( isset( $p['topEntities'] ) && is_array( $p['topEntities'] ) ) {
			foreach ( $p['topEntities'] as $entity ) {
				if ( is_scalar( $entity ) && '' !== (string) $entity ) {
					$entities[] = (string) $entity;
				}
			}
		}

		return array(
			'question'       => isset( $p['question'] ) && is_scalar( $p['question'] ) ? (string) $p['question'] : '',
			'model'          => isset( $p['model'] ) && is_scalar( $p['model'] ) ? (string) $p['model'] : '',
			'brandMentioned' => ! empty( $p['brandMentioned'] ),
			'citations'      => isset( $p['citations'] ) && is_array( $p['citations'] ) ? count( $p['citations'] ) : 0,
			'topEntities'    => implode( ', ', $entities ),
		);
	}
}
