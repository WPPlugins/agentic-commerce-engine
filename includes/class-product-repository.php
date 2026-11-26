<?php
/**
 * Read access to the product catalog and aggregate enrichment counts.
 *
 * Enumeration uses wc_get_products(return=>ids) which is HPOS-agnostic and
 * memory-flat on large catalogs. Status counts are one cached GROUP BY query
 * rather than several full scans.
 *
 * @package Agencoen
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Product_Repository
 */
class Product_Repository {

	const COUNTS_TRANSIENT  = 'agencoen_status_counts';
	/**
	 * Cached quality distribution. A new name in 4.17.0 rather than the old
	 * `agencoen_score_dist`, whose five buckets counted the hardcoded 90s, so no
	 * stale value from before the upgrade can be served in the new shape.
	 */
	const QUALITY_TRANSIENT = 'agencoen_quality_dist';

	/**
	 * One page of published product ids.
	 *
	 * @param int $page 1-based page.
	 * @param int $per  Page size.
	 * @return int[]
	 */
	public function paged_ids( $page, $per ) {
		$ids = wc_get_products(
			array(
				'status'  => 'publish',
				'limit'   => max( 1, (int) $per ),
				'page'    => max( 1, (int) $page ),
				'orderby' => 'ID',
				'order'   => 'ASC',
				'return'  => 'ids',
			)
		);
		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/**
	 * Total published products.
	 *
	 * @return int
	 */
	public function count_published() {
		$counts = wp_count_posts( 'product' );
		return $counts && isset( $counts->publish ) ? (int) $counts->publish : 0;
	}

	/**
	 * Product categories for the scope dropdown (name + slug + count).
	 *
	 * @return array<int,array{slug:string,name:string,count:int}>
	 */
	public function categories() {
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'number'     => 300,
				'orderby'    => 'name',
			)
		);
		$out = array();
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$out[] = array(
					'slug'  => $term->slug,
					'name'  => $term->name,
					'count' => (int) $term->count,
				);
			}
		}
		return $out;
	}

	/**
	 * Map of enrichment status => count for published products (cached 5 min).
	 *
	 * @return array<string,int>
	 */
	public function status_counts() {
		$cached = get_transient( self::COUNTS_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- single aggregate count; the result is cached in a transient above/below.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_value AS status, COUNT(*) AS n
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = %s AND p.post_type = 'product' AND p.post_status = 'publish'
				 GROUP BY pm.meta_value",
				Meta::STATUS
			)
		);

		$counts = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$counts[ (string) $row->status ] = (int) $row->n;
			}
		}

		set_transient( self::COUNTS_TRANSIENT, $counts, 5 * MINUTE_IN_SECONDS );
		return $counts;
	}

	/**
	 * Count of products in a given enrichment status.
	 *
	 * @param string $status Status value.
	 * @return int
	 */
	public function count_with_status( $status ) {
		$counts = $this->status_counts();
		return isset( $counts[ $status ] ) ? (int) $counts[ $status ] : 0;
	}

	/**
	 * Products awaiting review (status = enriched).
	 *
	 * @return int
	 */
	public function pending_review_count() {
		return $this->count_with_status( Meta::STATUS_ENRICHED );
	}

	/**
	 * Drop the cached status counts (after enrich/apply/dismiss).
	 */
	public function bust_counts_cache() {
		delete_transient( self::COUNTS_TRANSIENT );
		delete_transient( self::QUALITY_TRANSIENT );
		Dashboard::reset_memo();
	}

	/**
	 * Status counts with a synthetic "not_enriched" bucket (total minus tracked).
	 *
	 * @return array<string,int>
	 */
	public function status_counts_full() {
		$counts   = $this->status_counts();
		$tracked  = 0;
		foreach ( $counts as $n ) {
			$tracked += (int) $n;
		}
		$counts['not_enriched'] = max( 0, $this->count_published() - $tracked );
		return $counts;
	}

	/**
	 * Scalable product listing with search (name/SKU), status filter, sort and
	 * SQL-level pagination. Built for catalogs in the thousands.
	 *
	 * @param array $args { search, status, orderby, page, per }.
	 * @return array { ids, total, page, per, totalPages }
	 */
	public function query_products( array $args ) {
		global $wpdb;

		$search  = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';
		$status  = isset( $args['status'] ) ? (string) $args['status'] : 'all';
		$orderby = isset( $args['orderby'] ) ? (string) $args['orderby'] : 'recent';
		$per     = max( 1, min( 100, isset( $args['per'] ) ? (int) $args['per'] : 20 ) );
		$page    = max( 1, isset( $args['page'] ) ? (int) $args['page'] : 1 );
		$offset  = ( $page - 1 ) * $per;

		// Build joins + params in lock-step so prepare() placeholders line up.
		$joins       = " LEFT JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = %s ";
		$join_params = array( Meta::STATUS );

		$category = isset( $args['category'] ) ? sanitize_title( (string) $args['category'] ) : '';
		if ( '' !== $category ) {
			$joins        .= " INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID"
				. " INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat'"
				. " INNER JOIN {$wpdb->terms} tm ON tm.term_id = tt.term_id AND tm.slug = %s ";
			$join_params[] = $category;
		}

		if ( '' !== $search ) {
			$joins        .= " LEFT JOIN {$wpdb->postmeta} sk ON sk.post_id = p.ID AND sk.meta_key = %s ";
			$join_params[] = '_sku';
		}
		if ( 'score' === $orderby ) {
			$joins        .= " LEFT JOIN {$wpdb->postmeta} sc ON sc.post_id = p.ID AND sc.meta_key = %s ";
			$join_params[] = Meta::QUALITY_OVERALL;
		}
		// Products a product split holds, as last checked (4.29.0): `exclude`
		// for a batch that applies, `only` to count them.
		$held = isset( $args['held'] ) ? (string) $args['held'] : '';
		if ( 'exclude' === $held || 'only' === $held ) {
			$joins        .= " LEFT JOIN {$wpdb->postmeta} hb ON hb.post_id = p.ID AND hb.meta_key = %s ";
			$join_params[] = Meta::HELD_BACK;
		}

		$where        = " WHERE p.post_type = 'product' AND p.post_status = 'publish' ";
		$where_params = array();
		if ( '' !== $search ) {
			$like           = '%' . $wpdb->esc_like( $search ) . '%';
			$where         .= ' AND ( p.post_title LIKE %s OR sk.meta_value LIKE %s ) ';
			$where_params[] = $like;
			$where_params[] = $like;
		}
		if ( 'exclude' === $held ) {
			$where .= ' AND hb.meta_id IS NULL ';
		} elseif ( 'only' === $held ) {
			$where .= ' AND hb.meta_id IS NOT NULL ';
		}
		if ( 'not_enriched' === $status ) {
			$where .= ' AND st.meta_value IS NULL ';
		} elseif ( in_array( $status, array( 'queued', 'enriched', 'applied', 'dismissed', 'failed' ), true ) ) {
			$where         .= ' AND st.meta_value = %s ';
			$where_params[] = $status;
		}

		switch ( $orderby ) {
			case 'name':
				$order = ' ORDER BY p.post_title ASC ';
				break;
			case 'score':
				// Measured quality, highest first. Products with nothing measured
				// have no QUALITY_OVERALL row at all and sort after every scored
				// one, rather than as a 0 among the weakest.
				$order = ' ORDER BY ( sc.meta_value IS NULL ) ASC, ( sc.meta_value + 0 ) DESC, p.post_modified DESC ';
				break;
			default:
				$order = ' ORDER BY p.post_modified DESC ';
		}

		$base = "FROM {$wpdb->posts} p {$joins} {$where}";

		// phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- admin listing; user input is parameterized, only table names and internal meta-key constants are interpolated; results vary per query so are not cached.
		$count_params = array_merge( $join_params, $where_params );
		$total        = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT( DISTINCT p.ID ) {$base}", $count_params ) );

		$page_params = array_merge( $join_params, $where_params, array( $per, $offset ) );
		$ids         = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT p.ID {$base} {$order} LIMIT %d OFFSET %d", $page_params ) );
		// phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB

		return array(
			'ids'        => array_map( 'intval', (array) $ids ),
			'total'      => $total,
			'page'       => $page,
			'per'        => $per,
			'totalPages' => (int) ceil( $total / $per ),
		);
	}

	/**
	 * Up to $limit ids that still need a first enrichment (no status, or failed),
	 * optionally matching a name/SKU search. For chunked bulk enrichment.
	 *
	 * @param int    $limit  Max ids.
	 * @param string $search Optional search.
	 * @return int[]
	 */
	public function enrichable_ids( $limit, $search = '' ) {
		global $wpdb;
		$search = trim( (string) $search );

		$joins  = " LEFT JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = %s ";
		$params = array( Meta::STATUS );
		$where  = " WHERE p.post_type = 'product' AND p.post_status = 'publish' AND ( st.meta_value IS NULL OR st.meta_value = %s ) ";
		// Status placeholder for failed goes after the join's status meta_key.
		$params[] = Meta::STATUS_FAILED;

		if ( '' !== $search ) {
			$joins   .= " LEFT JOIN {$wpdb->postmeta} sk ON sk.post_id = p.ID AND sk.meta_key = %s ";
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			// sk join meta_key placeholder must precede the WHERE search placeholders;
			// rebuild param order: status_key, sku_key, failed, like, like.
			$params   = array( Meta::STATUS, '_sku', Meta::STATUS_FAILED, $like, $like );
			$where    = " WHERE p.post_type = 'product' AND p.post_status = 'publish' AND ( st.meta_value IS NULL OR st.meta_value = %s ) AND ( p.post_title LIKE %s OR sk.meta_value LIKE %s ) ";
		}

		$params[] = (int) $limit;
		// phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- admin batch selection; user input parameterized, only table names/meta-key constants interpolated; intentionally uncached.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT p.ID FROM {$wpdb->posts} p {$joins} {$where} ORDER BY p.ID ASC LIMIT %d", $params ) );
		// phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * How many products still need a first enrichment (matching optional search).
	 *
	 * @param string $search Optional search.
	 * @return int
	 */
	public function enrichable_count( $search = '' ) {
		global $wpdb;
		$search = trim( (string) $search );

		if ( '' === $search ) {
			$params = array( Meta::STATUS, Meta::STATUS_FAILED );
			$sql    = "SELECT COUNT( DISTINCT p.ID ) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = %s WHERE p.post_type = 'product' AND p.post_status = 'publish' AND ( st.meta_value IS NULL OR st.meta_value = %s )";
		} else {
			$like   = '%' . $wpdb->esc_like( $search ) . '%';
			$params = array( Meta::STATUS, '_sku', Meta::STATUS_FAILED, $like, $like );
			$sql    = "SELECT COUNT( DISTINCT p.ID ) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = %s LEFT JOIN {$wpdb->postmeta} sk ON sk.post_id = p.ID AND sk.meta_key = %s WHERE p.post_type = 'product' AND p.post_status = 'publish' AND ( st.meta_value IS NULL OR st.meta_value = %s ) AND ( p.post_title LIKE %s OR sk.meta_value LIKE %s )";
		}
		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- parameterized aggregate; only table names/constants interpolated.
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Measured quality across products with pending or applied content: five
	 * buckets (0-20 .. 80-100) and a sixth for products with nothing measured.
	 * Cached.
	 *
	 * The population is products that HAVE content to score. A dismissed or
	 * never-enriched product is not "Not scored", it is not in question, and
	 * counting it would bury the real unscored backlog under the whole catalog.
	 *
	 * @return int[] Six counts: the five buckets, then "Not scored".
	 */
	public function quality_distribution() {
		$cached = get_transient( self::QUALITY_TRANSIENT );
		if ( is_array( $cached ) && 6 === count( $cached ) ) {
			return $cached;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- aggregate, cached in a transient just below; only table names are interpolated.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT CASE WHEN q.meta_value IS NULL THEN 5 ELSE FLOOR( LEAST( 99, GREATEST( 0, CAST( q.meta_value AS UNSIGNED ) ) ) / 20 ) END AS bucket, COUNT( DISTINCT p.ID ) AS n
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = %s AND st.meta_value IN ( %s, %s )
				 LEFT JOIN {$wpdb->postmeta} q ON q.post_id = p.ID AND q.meta_key = %s
				 WHERE p.post_type = 'product' AND p.post_status = 'publish'
				 GROUP BY bucket",
				Meta::STATUS,
				Meta::STATUS_ENRICHED,
				Meta::STATUS_APPLIED,
				Meta::QUALITY_OVERALL
			)
		);

		$dist = array_fill( 0, 6, 0 );
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$b = (int) $row->bucket;
				if ( $b >= 0 && $b < 6 ) {
					$dist[ $b ] = (int) $row->n;
				}
			}
		}
		set_transient( self::QUALITY_TRANSIENT, $dist, 5 * MINUTE_IN_SECONDS );
		return $dist;
	}

	/**
	 * Products whose cached quality most needs refreshing, oldest first.
	 *
	 * Pending or applied content only, since nothing else has a score to read.
	 * Never-fetched products come before everything, then the oldest fetch.
	 * Anything fetched since `$fetched_before` is skipped: the Review screen
	 * reads fresh whenever it is open, and re-reading those would spend the
	 * batch on products that are already current.
	 *
	 * @param int $limit          Max ids.
	 * @param int $fetched_before Unix time; skip products fetched after it.
	 * @return int[]
	 */
	public function ids_for_quality_refresh( $limit, $fetched_before ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- background batch selection; parameterized, only table names interpolated; intentionally uncached.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = %s AND st.meta_value IN ( %s, %s )
				 LEFT JOIN {$wpdb->postmeta} qa ON qa.post_id = p.ID AND qa.meta_key = %s
				 WHERE p.post_type = 'product' AND p.post_status = 'publish'
				   AND ( qa.meta_value IS NULL OR ( qa.meta_value + 0 ) < %d )
				 ORDER BY ( qa.meta_value IS NULL ) DESC, ( qa.meta_value + 0 ) ASC, p.ID ASC
				 LIMIT %d",
				Meta::STATUS,
				Meta::STATUS_ENRICHED,
				Meta::STATUS_APPLIED,
				Meta::QUALITY_AT,
				(int) $fetched_before,
				max( 1, (int) $limit )
			)
		);
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Products applied per day over the last N days (oldest first).
	 *
	 * @param int $days Number of days.
	 * @return array<int,array{date:string,count:int}>
	 */
	public function applied_per_day( $days = 14 ) {
		global $wpdb;
		$days = max( 1, min( 60, (int) $days ) );
		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- small aggregate over an indexed meta key.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT DATE( meta_value ) AS d, COUNT(*) AS n FROM {$wpdb->postmeta} WHERE meta_key = %s GROUP BY d ORDER BY d DESC LIMIT %d", Meta::APPLIED_AT, $days ) );

		$map = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$map[ (string) $row->d ] = (int) $row->n;
			}
		}
		$out = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$day   = gmdate( 'Y-m-d', time() - ( $i * DAY_IN_SECONDS ) );
			$out[] = array(
				'date'  => $day,
				'count' => isset( $map[ $day ] ) ? $map[ $day ] : 0,
			);
		}
		return $out;
	}

	/**
	 * Query product ids by enrichment status (paged), for the Review screen.
	 *
	 * Uses WP_Query rather than wc_get_products because product meta lives in
	 * wp_postmeta (products are not affected by HPOS), and meta_query support in
	 * wc_get_products varies by version.
	 *
	 * @param string $status       Status value.
	 * @param int    $page         1-based page.
	 * @param int    $per          Page size.
	 * @param bool   $exclude_held Step past products a product split was last
	 *                             found holding (4.29.0), for batches that apply.
	 * @return int[]
	 */
	public function ids_with_status( $status, $page = 1, $per = 20, $exclude_held = false ) {
		$meta_query = array(
			array(
				'key'   => Meta::STATUS,
				'value' => $status,
			),
		);
		if ( $exclude_held ) {
			$meta_query[] = array(
				'key'     => Meta::HELD_BACK,
				'compare' => 'NOT EXISTS',
			);
		}
		$query = new \WP_Query(
			array(
				'post_type'              => 'product',
				'post_status'            => 'publish',
				'posts_per_page'         => max( 1, (int) $per ),
				'paged'                  => max( 1, (int) $page ),
				'fields'                 => 'ids',
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);
		return array_map( 'intval', $query->posts );
	}
}
