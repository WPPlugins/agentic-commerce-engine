<?php
/**
 * Native AI crawler tracker.
 *
 * Front-end requests from known AI crawlers (see Bot_Detector) are counted
 * into one aggregate row per (day, bot, path) with a single upsert; no IPs,
 * no full user agents, no query strings are ever stored. Cron runs prune()
 * daily (retention + row cap + long-tail folding) and, when the site opted
 * in, report_back() posts day-level aggregates to the Rezolve Ai platform.
 *
 * @package Agencoen
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Bot_Tracker
 */
class Bot_Tracker {

	/** Hard ceiling for the whole table; oldest days fall off first. */
	const MAX_ROWS = 50000;

	/** Per (day, bot): paths kept verbatim before folding into "(other)". */
	const FOLD_KEEP = 300;

	/** Synthetic path markers (never real URLs). */
	const OTHER_PATH     = '(other)';
	const NOT_FOUND_PATH = '(404)';

	/** Paths reported back to Rezolve Ai per bot per day. */
	const REPORT_PATHS = 100;

	/** @var Plugin */
	private $plugin;

	/**
	 * @param Plugin $plugin Orchestrator.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Hook the tracker as early as possible on front-end requests.
	 */
	public function register() {
		add_action( 'template_redirect', array( $this, 'maybe_track' ), 0 );
	}

	/* --------------------------------------------------------------------- */
	/* Recording                                                             */
	/* --------------------------------------------------------------------- */

	/**
	 * Count the current request when (and only when) it comes from a known AI
	 * crawler. Guards are ordered cheapest first; the hot path for regular
	 * visitors is a handful of boolean checks and one strings scan.
	 */
	public function maybe_track() {
		if ( ! $this->plugin->settings()->is_bots_enabled() ) {
			return;
		}
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}
		if ( is_user_logged_in() ) {
			return;
		}

		// A signed agent may look like any browser, so this comes before the user agent is read.
		// Kept only to be sent, so only when the daily report is on.
		if ( $this->plugin->settings()->is_bots_report() ) {
			Signed_Agents::capture();
		}

		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		if ( '' === $ua ) {
			return;
		}
		if ( is_feed() || is_robots() ) {
			return;
		}

		$bot = Bot_Detector::match( $ua );
		if ( null === $bot ) {
			return;
		}

		$path = $this->current_path();
		$this->record( $this->today(), $bot, $path );
	}

	/**
	 * Resolve the storable path for the current request.
	 *
	 * Query strings are never stored; 404s collapse into one "(404)" marker;
	 * with path tracking off everything collapses into one row per bot per day.
	 *
	 * @return string
	 */
	private function current_path() {
		if ( ! $this->plugin->settings()->is_bots_paths() ) {
			return '';
		}
		if ( is_404() ) {
			return self::NOT_FOUND_PATH;
		}

		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		if ( '' === $path ) {
			return '/';
		}
		if ( '/' !== $path ) {
			$path = rtrim( $path, '/' );
			if ( '' === $path ) {
				$path = '/';
			}
		}
		if ( function_exists( 'mb_substr' ) ) {
			$path = mb_substr( $path, 0, 191 );
		} else {
			$path = substr( $path, 0, 191 );
		}
		return $path;
	}

	/**
	 * One-query upsert into the aggregate table.
	 *
	 * @param string $day  Y-m-d (site timezone).
	 * @param string $bot  Bot slug.
	 * @param string $path Normalized path ('' when path tracking is off).
	 */
	private function record( $day, $bot, $path ) {
		global $wpdb;
		$table = Db::bot_hits_table();

		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- custom aggregate table; single prepared upsert, only the table name is interpolated.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (day, bot, path, path_hash, hits) VALUES (%s, %s, %s, %s, 1) ON DUPLICATE KEY UPDATE hits = hits + 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Db::bot_hits_table().
				$day,
				$bot,
				$path,
				self::hash_path( $path )
			)
		);
	}

	/**
	 * Stable short hash used by the unique key ('' path hashes to '-').
	 *
	 * @param string $path Normalized path.
	 * @return string 16 chars max.
	 */
	public static function hash_path( $path ) {
		if ( '' === (string) $path ) {
			return '-';
		}
		return substr( md5( (string) $path ), 0, 16 );
	}

	/* --------------------------------------------------------------------- */
	/* Maintenance (daily cron)                                              */
	/* --------------------------------------------------------------------- */

	/**
	 * Daily maintenance: drop rows past retention, fold per-(day,bot) long
	 * tails into "(other)", and enforce the global row cap oldest-day-first.
	 */
	public function prune() {
		global $wpdb;
		$table = Db::bot_hits_table();

		$retention = $this->plugin->settings()->get_bots_retention();
		$cutoff    = gmdate( 'Y-m-d', strtotime( $this->today() ) - ( $retention * DAY_IN_SECONDS ) );

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE day < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- retention sweep on the plugin's own table; table name from Db::bot_hits_table().

		$this->enforce_row_cap();
		$this->fold_long_tails();

		// Aggregates changed shape; drop the 5-minute summaries early.
		$this->plugin->cache()->bust( 'bots' );
	}

	/**
	 * Keep the table under MAX_ROWS by deleting whole days, oldest first.
	 * The newest day is never dropped.
	 */
	private function enforce_row_cap() {
		global $wpdb;
		$table = Db::bot_hits_table();

		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- single aggregate count on the plugin's own table.
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		if ( $total <= self::MAX_ROWS ) {
			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- per-day row counts on the plugin's own table.
		$per_day = $wpdb->get_results( "SELECT day, COUNT(*) AS n FROM {$table} GROUP BY day ORDER BY day ASC" );
		if ( ! is_array( $per_day ) || count( $per_day ) < 2 ) {
			return;
		}

		$excess = $total - self::MAX_ROWS;
		$last   = count( $per_day ) - 1;
		$cut    = array();
		foreach ( $per_day as $i => $row ) {
			if ( $excess <= 0 || $i >= $last ) {
				break;
			}
			$cut[]   = (string) $row->day;
			$excess -= (int) $row->n;
		}
		if ( empty( $cut ) ) {
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $cut ), '%s' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE day IN ({$placeholders})", $cut ) ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- oldest-day cap enforcement; table name + %s placeholder list built above.
	}

	/**
	 * Bound per-(day,bot) path cardinality: everything beyond the FOLD_KEEP
	 * busiest paths is summed into one "(other)" row and the originals removed.
	 */
	private function fold_long_tails() {
		global $wpdb;
		$table = Db::bot_hits_table();

		$groups = $wpdb->get_results( $wpdb->prepare( "SELECT day, bot, COUNT(*) AS n FROM {$table} GROUP BY day, bot HAVING COUNT(*) > %d", self::FOLD_KEEP + 1 ) ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- cardinality scan on the plugin's own table; table name from Db::bot_hits_table().
		if ( empty( $groups ) ) {
			return;
		}

		foreach ( $groups as $group ) {
			// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- fold candidates beyond the busiest FOLD_KEEP paths.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, hits FROM {$table} WHERE day = %s AND bot = %s AND path <> %s ORDER BY hits DESC, id ASC LIMIT %d, %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Db::bot_hits_table().
					$group->day,
					$group->bot,
					self::OTHER_PATH,
					self::FOLD_KEEP,
					100000
				)
			);
			if ( empty( $rows ) ) {
				continue;
			}

			$sum = 0;
			$ids = array();
			foreach ( $rows as $row ) {
				$sum  += (int) $row->hits;
				$ids[] = (int) $row->id;
			}

			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- fold cleanup of the ids selected just above; table name + %d placeholder list built above.

			// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- single upsert of the fold aggregate.
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$table} (day, bot, path, path_hash, hits) VALUES (%s, %s, %s, %s, %d) ON DUPLICATE KEY UPDATE hits = hits + %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Db::bot_hits_table().
					$group->day,
					$group->bot,
					self::OTHER_PATH,
					self::hash_path( self::OTHER_PATH ),
					$sum,
					$sum
				)
			);
		}
	}

	/* --------------------------------------------------------------------- */
	/* Report-back (opt-in)                                                  */
	/* --------------------------------------------------------------------- */

	/**
	 * Post the last two days of aggregates (yesterday + today so far) to Rezolve Ai.
	 * Re-posting a day is safe: the platform replaces it idempotently.
	 *
	 * @return true|\WP_Error True on success (or nothing to send).
	 */
	public function report_back() {
		$settings = $this->plugin->settings();
		if ( ! $settings->is_connected() ) {
			return new \WP_Error( 'agencoen_not_connected', __( 'Connect your site first.', 'agentic-commerce-engine' ) );
		}

		global $wpdb;
		$table     = Db::bot_hits_table();
		$yesterday = gmdate( 'Y-m-d', strtotime( $this->today() ) - DAY_IN_SECONDS );

		// Ordered so the first REPORT_PATHS rows per (day, bot) are its top paths.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT day, bot, path, hits FROM {$table} WHERE day >= %s ORDER BY day ASC, bot ASC, hits DESC, id ASC", $yesterday ) ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- two-day aggregate read for the opt-in report; table name from Db::bot_hits_table().
		if ( empty( $rows ) ) {
			return true;
		}

		$paths_on = $settings->is_bots_paths();
		$day_map  = array();
		foreach ( $rows as $row ) {
			$day  = (string) $row->day;
			$bot  = (string) $row->bot;
			$hits = (int) $row->hits;
			$path = (string) $row->path;

			if ( ! isset( $day_map[ $day ] ) ) {
				$day_map[ $day ] = array();
			}
			if ( ! isset( $day_map[ $day ][ $bot ] ) ) {
				$day_map[ $day ][ $bot ] = array(
					'total' => 0,
					'paths' => array(),
				);
			}
			$day_map[ $day ][ $bot ]['total'] += $hits;
			if ( $paths_on && '' !== $path && count( $day_map[ $day ][ $bot ]['paths'] ) < self::REPORT_PATHS ) {
				$day_map[ $day ][ $bot ]['paths'][] = array(
					'path' => $path,
					'hits' => $hits,
				);
			}
		}

		$days = array();
		foreach ( $day_map as $date => $bots ) {
			$list = array();
			foreach ( $bots as $slug => $agg ) {
				$entry = array(
					'bot'       => $slug,
					'totalHits' => (int) $agg['total'],
				);
				$info = Bot_Detector::info( $slug );
				if ( $info ) {
					$entry['category'] = $info['category'];
				}
				if ( $paths_on && ! empty( $agg['paths'] ) ) {
					$entry['paths'] = $agg['paths'];
				}
				$list[] = $entry;
			}
			$days[] = array(
				'date' => $date,
				'bots' => $list,
			);
		}

		$result = $this->plugin->api()->bot_traffic_ingest( $settings->get_store_id(), $days );
		if ( is_wp_error( $result ) ) {
			$this->plugin->logger()->error( 'Crawler report to Rezolve Ai failed: ' . $result->get_error_message() );
			return $result;
		}
		return true;
	}

	/* --------------------------------------------------------------------- */
	/* Read models (admin UI)                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Full Crawlers-tab payload for a 7/30/90 day window (cached 5 minutes).
	 *
	 * @param int $days Window length (7|30|90).
	 * @return array{tiles:array,trend:array,byBot:array,topPages:array|null,pathsEnabled:bool,heatmap:array,days:int}
	 */
	public function summary( $days = 30 ) {
		$days     = in_array( (int) $days, array( 7, 30, 90 ), true ) ? (int) $days : 30;
		$paths_on = $this->plugin->settings()->is_bots_paths();
		$key      = 'summary_' . $days . '_' . ( $paths_on ? 'p1' : 'p0' );

		return $this->plugin->cache()->remember(
			'bots',
			$key,
			5 * MINUTE_IN_SECONDS,
			function () use ( $days, $paths_on ) {
				return $this->build_summary( $days, $paths_on );
			}
		);
	}

	/**
	 * Cheap uncached window for the dashboard strip.
	 *
	 * @param int $days Window length.
	 * @return array{total:int,prev:int,byDay:array<int,array{day:string,hits:int}>}
	 */
	public function summary_light( $days = 14 ) {
		global $wpdb;
		$table = Db::bot_hits_table();
		$days  = max( 1, min( 90, (int) $days ) );

		$today     = $this->today();
		$from      = gmdate( 'Y-m-d', strtotime( $today ) - ( ( $days - 1 ) * DAY_IN_SECONDS ) );
		$prev_from = gmdate( 'Y-m-d', strtotime( $from ) - ( $days * DAY_IN_SECONDS ) );
		$prev_to   = gmdate( 'Y-m-d', strtotime( $from ) - DAY_IN_SECONDS );

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT day, SUM(hits) AS n FROM {$table} WHERE day >= %s GROUP BY day", $from ) ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- small per-day aggregate for the dashboard strip; table name from Db::bot_hits_table().
		$prev = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(hits), 0) FROM {$table} WHERE day BETWEEN %s AND %s", $prev_from, $prev_to ) ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- single aggregate for the comparison window; table name from Db::bot_hits_table().

		$map = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$map[ (string) $row->day ] = (int) $row->n;
			}
		}

		$total  = 0;
		$by_day = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$day    = gmdate( 'Y-m-d', strtotime( $today ) - ( $i * DAY_IN_SECONDS ) );
			$hits   = isset( $map[ $day ] ) ? $map[ $day ] : 0;
			$total += $hits;
			$by_day[] = array(
				'day'  => $day,
				'hits' => $hits,
			);
		}

		return array(
			'total' => $total,
			'prev'  => $prev,
			'byDay' => $by_day,
		);
	}

	/**
	 * Uncached summary builder (see summary()).
	 *
	 * @param int  $days     Window length.
	 * @param bool $paths_on Whether per-path rows exist.
	 * @return array
	 */
	private function build_summary( $days, $paths_on ) {
		global $wpdb;
		$table = Db::bot_hits_table();

		$today     = $this->today();
		$from      = gmdate( 'Y-m-d', strtotime( $today ) - ( ( $days - 1 ) * DAY_IN_SECONDS ) );
		$prev_from = gmdate( 'Y-m-d', strtotime( $from ) - ( $days * DAY_IN_SECONDS ) );
		$prev_to   = gmdate( 'Y-m-d', strtotime( $from ) - DAY_IN_SECONDS );

		$bot_rows = $wpdb->get_results( $wpdb->prepare( "SELECT bot, SUM(hits) AS n FROM {$table} WHERE day >= %s GROUP BY bot ORDER BY n DESC", $from ) ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- per-bot aggregate, result cached by summary(); table name from Db::bot_hits_table().
		$prev_hits = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(hits), 0) FROM {$table} WHERE day BETWEEN %s AND %s", $prev_from, $prev_to ) ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- previous-window aggregate, result cached by summary(); table name from Db::bot_hits_table().
		$day_rows = $wpdb->get_results( $wpdb->prepare( "SELECT day, bot, SUM(hits) AS n FROM {$table} WHERE day >= %s GROUP BY day, bot", $from ) ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- day-by-bot aggregate, result cached by summary(); table name from Db::bot_hits_table().

		$by_bot = array();
		$total  = 0;
		if ( is_array( $bot_rows ) ) {
			foreach ( $bot_rows as $row ) {
				$slug  = (string) $row->bot;
				$hits  = (int) $row->n;
				$total += $hits;
				$info  = Bot_Detector::info( $slug );

				$by_bot[] = array(
					'slug'     => $slug,
					'label'    => $info ? $info['label'] : $slug,
					'vendor'   => $info ? $info['vendor'] : '',
					'color'    => $info ? $info['color'] : '#94a3b8',
					'category' => $info ? $info['category'] : '',
					'hits'     => $hits,
				);
			}
		}

		// day => slug => hits.
		$grid = array();
		if ( is_array( $day_rows ) ) {
			foreach ( $day_rows as $row ) {
				$grid[ (string) $row->day ][ (string) $row->bot ] = (int) $row->n;
			}
		}

		$trend        = array();
		$tracked_days = 0;
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$day     = gmdate( 'Y-m-d', strtotime( $today ) - ( $i * DAY_IN_SECONDS ) );
			$per_bot = isset( $grid[ $day ] ) ? $grid[ $day ] : array();
			$sum     = 0;
			foreach ( $per_bot as $hits ) {
				$sum += (int) $hits;
			}
			if ( $sum > 0 ) {
				++$tracked_days;
			}
			$trend[] = array(
				'day'   => $day,
				'total' => $sum,
				'byBot' => $per_bot,
			);
		}

		$top_pages = null;
		if ( $paths_on ) {
			$page_rows = $wpdb->get_results( $wpdb->prepare( "SELECT path, SUM(hits) AS n, MAX(day) AS last_day FROM {$table} WHERE day >= %s AND path <> '' GROUP BY path ORDER BY n DESC LIMIT 20", $from ) ); // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- top-pages aggregate, result cached by summary(); table name from Db::bot_hits_table().
			$top_pages = array();
			if ( is_array( $page_rows ) ) {
				foreach ( $page_rows as $row ) {
					$top_pages[] = array(
						'path'    => (string) $row->path,
						'hits'    => (int) $row->n,
						'lastDay' => (string) $row->last_day,
					);
				}
			}
		}

		return array(
			'days'         => $days,
			'pathsEnabled' => (bool) $paths_on,
			'tiles'        => array(
				'hits'        => $total,
				'prevHits'    => $prev_hits,
				'activeBots'  => count( $by_bot ),
				'topBot'      => ! empty( $by_bot ) ? array(
					'slug'  => $by_bot[0]['slug'],
					'label' => $by_bot[0]['label'],
				) : null,
				'trackedDays' => $tracked_days,
			),
			'trend'        => $trend,
			'byBot'        => $by_bot,
			'topPages'     => $top_pages,
			'heatmap'      => $this->build_heatmap( $trend, $by_bot, $days ),
		);
	}

	/**
	 * Day-by-bot heatmap (columns capped at the last 30 days so the 90-day
	 * range stays readable).
	 *
	 * @param array $trend  Zero-filled day rows from build_summary().
	 * @param array $by_bot Per-bot totals (hits desc).
	 * @param int   $days   Window length.
	 * @return array{cols:array,rows:array}
	 */
	private function build_heatmap( $trend, $by_bot, $days ) {
		$window = array_slice( $trend, -1 * min( (int) $days, 30 ) );

		$cols = array();
		foreach ( $window as $day_row ) {
			$cols[] = date_i18n( 'M j', strtotime( $day_row['day'] ) );
		}

		$rows = array();
		foreach ( $by_bot as $bot ) {
			$cells = array();
			foreach ( $window as $index => $day_row ) {
				$hits    = isset( $day_row['byBot'][ $bot['slug'] ] ) ? (int) $day_row['byBot'][ $bot['slug'] ] : 0;
				$cells[] = array(
					'v'     => $hits,
					'title' => sprintf(
						/* translators: 1: crawler name, 2: date, 3: hit count. */
						__( '%1$s, %2$s: %3$s hits', 'agentic-commerce-engine' ),
						$bot['label'],
						$cols[ $index ],
						number_format_i18n( $hits )
					),
				);
			}
			$rows[] = array(
				'label' => $bot['label'],
				'cells' => $cells,
			);
		}

		return array(
			'cols' => $cols,
			'rows' => $rows,
		);
	}

	/**
	 * Today's date key in the site's timezone.
	 *
	 * @return string Y-m-d.
	 */
	private function today() {
		return current_time( 'Y-m-d' );
	}
}
