<?php
/**
 * Content Hub service: article generation jobs, brief listings, and the local
 * WordPress posts materialized from them.
 *
 * Generation is a durable platform job. The browser polls it live; a cron
 * fallback (Cron::CONTENT_POLL -> poll_job) finishes the loop when the tab
 * closes, so a completed article always lands as a WP draft. Job bookkeeping
 * lives in one option so the UI can resume after a reload.
 *
 * @package Agencoen
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Articles
 */
class Articles {

	const OPT_JOBS          = 'agencoen_content_jobs';
	const MAX_JOBS          = 20;
	const MAX_POLL_ATTEMPTS = 40;
	const POLL_DELAY        = 120;
	const TYPES_TTL         = 12 * HOUR_IN_SECONDS;
	const POSTS_PER_PAGE    = 20;

	/** @var Plugin */
	private $plugin;

	/**
	 * @param Plugin $plugin Orchestrator.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/* --------------------------------------------------------------------- */
	/* Job bookkeeping (one option, capped)                                  */
	/* --------------------------------------------------------------------- */

	/**
	 * All tracked generation jobs, keyed by job id.
	 *
	 * @return array<string,array>
	 */
	public function get_jobs() {
		$jobs = get_option( self::OPT_JOBS, array() );
		return is_array( $jobs ) ? $jobs : array();
	}

	/**
	 * One tracked job entry.
	 *
	 * @param string $job_id Job id.
	 * @return array|null
	 */
	public function get_job( $job_id ) {
		$jobs = $this->get_jobs();
		return isset( $jobs[ $job_id ] ) && is_array( $jobs[ $job_id ] ) ? $jobs[ $job_id ] : null;
	}

	/**
	 * Merge-write a job entry, dropping the oldest entries beyond the cap.
	 *
	 * @param string $job_id Job id.
	 * @param array  $entry  Fields to merge into the stored entry.
	 * @return array The stored entry after the merge.
	 */
	public function put_job( $job_id, array $entry ) {
		$jobs            = $this->get_jobs();
		$existing        = isset( $jobs[ $job_id ] ) && is_array( $jobs[ $job_id ] ) ? $jobs[ $job_id ] : array();
		$jobs[ $job_id ] = array_merge( $existing, $entry );

		if ( count( $jobs ) > self::MAX_JOBS ) {
			uasort(
				$jobs,
				function ( $a, $b ) {
					return (int) ( isset( $a['startedAt'] ) ? $a['startedAt'] : 0 ) <=> (int) ( isset( $b['startedAt'] ) ? $b['startedAt'] : 0 );
				}
			);
			$jobs = array_slice( $jobs, count( $jobs ) - self::MAX_JOBS, null, true );
		}

		update_option( self::OPT_JOBS, $jobs, false );
		return $jobs[ $job_id ];
	}

	/**
	 * Drop one job entry.
	 *
	 * @param string $job_id Job id.
	 */
	public function forget_job( $job_id ) {
		$jobs = $this->get_jobs();
		if ( isset( $jobs[ $job_id ] ) ) {
			unset( $jobs[ $job_id ] );
			update_option( self::OPT_JOBS, $jobs, false );
		}
	}

	/* --------------------------------------------------------------------- */
	/* Platform reads                                                        */
	/* --------------------------------------------------------------------- */

	/**
	 * Content-type catalog (enrichment types + article types), cached 12h.
	 *
	 * @param bool $fresh Force a refetch.
	 * @return array|\WP_Error
	 */
	public function types( $fresh = false ) {
		if ( $fresh ) {
			$this->plugin->cache()->forget( 'content', 'types' );
		}
		return $this->plugin->cache()->remember(
			'content',
			'types',
			self::TYPES_TTL,
			function () {
				return $this->plugin->api()->content_types( $this->plugin->settings()->get_store_id() );
			}
		);
	}

	/**
	 * The cached catalog, WITHOUT ever fetching it.
	 *
	 * For callers on a hot path, such as every admin page load building the
	 * enrichment picker, where waiting on Rezolve Ai is not acceptable. False when
	 * nothing is cached yet; {@see Cron::heartbeat()} keeps it warm.
	 *
	 * @return array|false
	 */
	public function cached_types() {
		$hit = $this->plugin->cache()->get( 'content', 'types' );
		return is_array( $hit ) ? $hit : false;
	}

	/**
	 * List project briefs (uncached; the list changes as jobs land).
	 *
	 * @param array $args { status?, search?, page?, pageSize? }.
	 * @return array|\WP_Error { items, _meta }.
	 */
	public function briefs( array $args = array() ) {
		return $this->plugin->api()->articles( $this->plugin->settings()->get_store_id(), $args );
	}

	/**
	 * Full article payload for one brief.
	 *
	 * @param string $brief_id Brief id.
	 * @return array|\WP_Error
	 */
	public function article( $brief_id ) {
		return $this->plugin->api()->article( $this->plugin->settings()->get_store_id(), $brief_id );
	}

	/* --------------------------------------------------------------------- */
	/* Generation lifecycle                                                  */
	/* --------------------------------------------------------------------- */

	/**
	 * Start a durable article generation job.
	 *
	 * @param array $args { title, type?, locale?, overview?, keywords?, tone?, wordCount?, productIds?, categoryTarget? }.
	 * @return array|\WP_Error { jobId, briefId }.
	 */
	public function generate( array $args ) {
		$args['storeId'] = $this->plugin->settings()->get_store_id();

		$result = $this->plugin->api()->articles_generate( $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$job_id   = isset( $result['id'] ) ? (string) $result['id'] : '';
		$brief_id = isset( $result['briefId'] ) ? (string) $result['briefId'] : '';
		if ( '' === $job_id ) {
			return new \WP_Error( 'agencoen_bad_response', __( 'The platform did not return a job id.', 'agentic-commerce-engine' ) );
		}

		$this->put_job(
			$job_id,
			array(
				'briefId'   => $brief_id,
				'title'     => isset( $args['title'] ) ? (string) $args['title'] : '',
				'startedAt' => time(),
				'status'    => isset( $result['status'] ) ? (string) $result['status'] : 'pending',
				'progress'  => array(),
				'attempts'  => 0,
			)
		);

		// Cron fallback: the job finishes into a WP draft even if the tab closes.
		wp_schedule_single_event( time() + self::POLL_DELAY, Cron::CONTENT_POLL, array( $job_id ) );

		return array(
			'jobId'   => $job_id,
			'briefId' => $brief_id,
		);
	}

	/**
	 * Poll the platform for a job, sync the stored entry, and materialize the
	 * article as a draft post on completion (idempotent).
	 *
	 * @param string $job_id Job id.
	 * @return array|\WP_Error { status, progress, briefId, postId?, editLink?, previewLink?, article?, error? }.
	 */
	public function job_status( $job_id ) {
		$job = $this->plugin->api()->job( $job_id );
		if ( is_wp_error( $job ) ) {
			return $job;
		}

		$status   = isset( $job['status'] ) ? (string) $job['status'] : 'pending';
		$progress = isset( $job['progress'] ) && is_array( $job['progress'] ) ? $job['progress'] : array();
		$result   = isset( $job['result'] ) && is_array( $job['result'] ) ? $job['result'] : array();

		$entry    = $this->get_job( $job_id );
		$entry    = is_array( $entry ) ? $entry : array( 'startedAt' => time() );
		$brief_id = '';
		if ( ! empty( $entry['briefId'] ) ) {
			$brief_id = (string) $entry['briefId'];
		} elseif ( ! empty( $result['briefId'] ) ) {
			$brief_id = (string) $result['briefId'];
		}

		$patch = array(
			'briefId'  => $brief_id,
			'status'   => $status,
			'progress' => $progress,
		);
		if ( isset( $job['error'] ) && ! empty( $job['error'] ) ) {
			$patch['error'] = is_array( $job['error'] ) ? $job['error'] : array( 'message' => (string) $job['error'] );
		}

		$out = array(
			'status'   => $status,
			'progress' => $progress,
			'briefId'  => $brief_id,
		);
		if ( isset( $patch['error'] ) ) {
			$out['error'] = $patch['error'];
		}

		// Article jobs are single-unit, so 'partial' is completion too.
		$done = in_array( $status, array( 'completed', 'partial' ), true );

		if ( $done && '' !== $brief_id ) {
			if ( empty( $entry['postId'] ) ) {
				$detail = $this->article( $brief_id );
				if ( ! is_wp_error( $detail ) ) {
					$post_id = $this->plugin->publisher()->materialize( $detail );
					if ( ! is_wp_error( $post_id ) ) {
						update_post_meta( $post_id, Meta::CONTENT_JOB, $job_id );
						$patch['postId']      = (int) $post_id;
						$patch['editLink']    = get_edit_post_link( $post_id, 'raw' );
						$patch['previewLink'] = get_preview_post_link( $post_id );
					} else {
						$this->plugin->logger()->error( 'Content materialize failed for job ' . $job_id . ': ' . $post_id->get_error_message() );
					}
					$out['article'] = $detail;
				}
			} else {
				$patch['postId']      = (int) $entry['postId'];
				$patch['editLink']    = isset( $entry['editLink'] ) ? $entry['editLink'] : get_edit_post_link( (int) $entry['postId'], 'raw' );
				$patch['previewLink'] = isset( $entry['previewLink'] ) ? $entry['previewLink'] : get_preview_post_link( (int) $entry['postId'] );
				$detail               = $this->article( $brief_id );
				if ( ! is_wp_error( $detail ) ) {
					$out['article'] = $detail;
				}
			}
		}

		$stored = $this->put_job( $job_id, $patch );

		if ( ! empty( $stored['postId'] ) ) {
			$out['postId']      = (int) $stored['postId'];
			$out['editLink']    = isset( $stored['editLink'] ) ? $stored['editLink'] : '';
			$out['previewLink'] = isset( $stored['previewLink'] ) ? $stored['previewLink'] : '';
		}

		return $out;
	}

	/**
	 * Cron callback for Cron::CONTENT_POLL. Re-arms itself while the job is
	 * still running, capped at MAX_POLL_ATTEMPTS.
	 *
	 * @param string $job_id Job id.
	 */
	public function poll_job( $job_id ) {
		$job_id = (string) $job_id;
		$entry  = $this->get_job( $job_id );
		if ( null === $entry ) {
			return;
		}

		// Terminal: failed jobs stop; completed jobs stop once materialized (a
		// completed entry without a post retries the materialize).
		$status = isset( $entry['status'] ) ? (string) $entry['status'] : 'pending';
		if ( 'failed' === $status ) {
			return;
		}
		if ( in_array( $status, array( 'completed', 'partial' ), true ) && ! empty( $entry['postId'] ) ) {
			return;
		}

		$attempts = isset( $entry['attempts'] ) ? (int) $entry['attempts'] : 0;
		$this->put_job( $job_id, array( 'attempts' => $attempts + 1 ) );

		$result = $this->job_status( $job_id );
		$state  = is_wp_error( $result ) ? 'pending' : ( isset( $result['status'] ) ? (string) $result['status'] : 'pending' );

		if ( in_array( $state, array( 'pending', 'running' ), true ) && $attempts + 1 < self::MAX_POLL_ATTEMPTS ) {
			if ( ! wp_next_scheduled( Cron::CONTENT_POLL, array( $job_id ) ) ) {
				wp_schedule_single_event( time() + self::POLL_DELAY, Cron::CONTENT_POLL, array( $job_id ) );
			}
		}
	}

	/* --------------------------------------------------------------------- */
	/* Publish report-back                                                   */
	/* --------------------------------------------------------------------- */

	/**
	 * Tell the platform where an article landed. Non-fatal on error (the WP
	 * post is already live; the platform just misses the audit entry).
	 *
	 * @param string $brief_id Brief id.
	 * @param int    $post_id  WP post id.
	 * @param bool   $live     True when the post is publicly published.
	 * @return bool
	 */
	public function report_published( $brief_id, $post_id, $live ) {
		$result = $this->plugin->api()->article_published(
			$this->plugin->settings()->get_store_id(),
			$brief_id,
			array(
				'url'      => (string) get_permalink( $post_id ),
				'wpPostId' => (int) $post_id,
				'status'   => $live ? 'published' : 'draft',
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->plugin->logger()->error( 'Publish report-back failed for brief ' . $brief_id . ': ' . $result->get_error_message() );
			return false;
		}
		return true;
	}

	/* --------------------------------------------------------------------- */
	/* Local posts (materialized articles)                                   */
	/* --------------------------------------------------------------------- */

	/**
	 * Counts of Rezolve Ai authored posts, split live vs not-yet-live.
	 *
	 * @return array { drafts: int, published: int }
	 */
	public function local_counts() {
		return array(
			'drafts'    => $this->count_local( array( 'draft', 'pending', 'future', 'private' ) ),
			'published' => $this->count_local( array( 'publish' ) ),
		);
	}

	/**
	 * Rows for the Published tab: every post carrying a brief id.
	 *
	 * @param int $paged 1-based page.
	 * @return array { items: array[], total, page, pageSize, totalPages, counts }
	 */
	public function local_posts( $paged = 1 ) {
		$paged = max( 1, (int) $paged );

		$query = new \WP_Query(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => self::POSTS_PER_PAGE,
				'paged'          => $paged,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded admin query on an indexed meta key.
				'meta_query'     => array(
					array(
						'key'     => Meta::BRIEF_ID,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = array(
				'postId'      => (int) $post->ID,
				'title'       => get_the_title( $post ),
				'status'      => (string) $post->post_status,
				'briefId'     => (string) get_post_meta( $post->ID, Meta::BRIEF_ID, true ),
				'publishedAt' => (string) $post->post_date,
				'permalink'   => (string) get_permalink( $post ),
				'editLink'    => (string) get_edit_post_link( $post->ID, 'raw' ),
			);
		}

		return array(
			'items'      => $items,
			'total'      => (int) $query->found_posts,
			'page'       => $paged,
			'pageSize'   => self::POSTS_PER_PAGE,
			'totalPages' => max( 1, (int) $query->max_num_pages ),
			'counts'     => $this->local_counts(),
		);
	}

	/**
	 * Cheap found_posts count of Rezolve Ai authored posts in the given statuses.
	 *
	 * @param string[] $statuses Post statuses.
	 * @return int
	 */
	private function count_local( array $statuses ) {
		$query = new \WP_Query(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => $statuses,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- count-only admin query on an indexed meta key.
				'meta_query'     => array(
					array(
						'key'     => Meta::BRIEF_ID,
						'compare' => 'EXISTS',
					),
				),
			)
		);
		return (int) $query->found_posts;
	}
}
