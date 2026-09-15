<?php
/**
 * Measured quality scores for this site's proposals, read from Rezolve Ai.
 *
 * WHAT THIS REPLACES
 * Until 4.17.0 every enriched product carried "Score 90". `Poller` set it as a
 * literal for every result row, `Enrichment` saved it, and the Review chip, the
 * Products column and sort, the dashboard chart and the CSV export all showed
 * it as though something had judged the content. Nothing had. Rezolve Ai does
 * score content (Content Quality, SEO, AI Readability, Brand Voice) on its
 * review rows; the plugin simply never read those numbers.
 *
 * WHERE THE NUMBERS COME FROM
 * `POST /api/v1/plugin/quality`, one entry per (product, field), in request
 * order. The platform owns what a score means: its `state`, which dimensions
 * are null, and the per-row `overall`. Nothing in this class computes a
 * dimension or defaults one. The single number it derives is the product-level
 * overall, and {@see product_overall()} explains exactly how.
 *
 * WHERE THEY ARE KEPT
 * Per product, in `Meta::QUALITY` with two sortable companions (see Meta). The
 * Review screen reads fresh on every page; the Products screen, dashboard and
 * CSV read the cache, which `Review_Sync::refresh_quality()` keeps current.
 *
 * @package Agencoen
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Quality
 */
class Quality {

	/** Items per read. The platform caps `/plugin/quality` at 200. */
	const READ_BATCH = 200;

	/** Items per rescore. The platform caps it at 25: each one is a paid AI call. */
	const RESCORE_BATCH = 25;

	/** Products the Review screen may ask about at once (a page is 20). */
	const MAX_PRODUCTS = 50;

	/** Products per background refresh. */
	const REFRESH_LIMIT = 200;

	/**
	 * Minimum seconds between background refreshes. Just under an hour, so the
	 * hourly heartbeat is never skipped by a few seconds of cron jitter.
	 */
	const REFRESH_INTERVAL = 3000;

	/** Transient that holds the refresh back for REFRESH_INTERVAL. */
	const REFRESH_LOCK = 'agencoen_quality_refresh';

	/** The platform's `QualityView.state` vocabulary. */
	const STATES = array( 'unscored', 'stale', 'outdated', 'scored' );

	/** Numeric fields of a `QualityView`. */
	const NUMBERS = array( 'contentQuality', 'seoScore', 'aiReadability', 'brandVoice', 'overall' );

	/** @var Plugin */
	private $plugin;

	/**
	 * @param Plugin $plugin Orchestrator.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/* --------------------------------------------------------------------- */
	/* Remote                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Read the current scores for a set of products and cache each one.
	 *
	 * Batched by WHOLE products, so one failed call leaves every product in it
	 * with its previous cache rather than half of its fields replaced. A failed
	 * product is reported under `errors` and its cache is not touched: a read
	 * that failed is not a measurement of nothing.
	 *
	 * @param int[] $product_ids Product ids.
	 * @return array{records:array<int,array>,errors:array<int,string>}
	 */
	public function fetch( array $product_ids ) {
		$result = array(
			'records' => array(),
			'errors'  => array(),
		);

		$items_by_product = array();
		foreach ( $product_ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 && ! isset( $items_by_product[ $id ] ) ) {
				$items_by_product[ $id ] = self::request_items( $id );
			}
		}
		if ( empty( $items_by_product ) ) {
			return $result;
		}

		$store_id = $this->plugin->settings()->get_store_id();
		$now      = gmdate( 'c' );

		foreach ( self::pack( $items_by_product, self::READ_BATCH ) as $batch ) {
			$items = array();
			foreach ( $batch as $id ) {
				$items = array_merge( $items, $items_by_product[ $id ] );
			}

			// A product with no proposal fields has nothing to ask about. That is
			// an answer in itself, so it is cached, and no call is spent on it.
			$entries = array();
			if ( ! empty( $items ) ) {
				$response = $this->plugin->api()->quality( $store_id, $items );
				$entries  = self::entries_from_response( $response, count( $items ) );
				if ( is_wp_error( $entries ) ) {
					foreach ( $batch as $id ) {
						$result['errors'][ $id ] = $entries->get_error_message();
					}
					continue;
				}
			}

			// Entries come back in request order, so position (not the echoed
			// productId, which the platform normalises) says whose each one is.
			$offset = 0;
			foreach ( $batch as $id ) {
				$count  = count( $items_by_product[ $id ] );
				$record = array(
					'fetched_at' => $now,
					'entries'    => array_slice( $entries, $offset, $count ),
				);
				$offset += $count;
				self::store( $id, $record );
				$result['records'][ $id ] = $record;
			}
		}

		if ( ! empty( $result['records'] ) ) {
			delete_transient( Product_Repository::QUALITY_TRANSIENT );
		}
		return $result;
	}

	/**
	 * Ask Rezolve Ai to score some of a product's content again.
	 *
	 * The response only says what was queued; the new numbers arrive later and
	 * the caller reads them with {@see fetch()}. Whatever the 202 did describe
	 * is merged into the cache, so a "scoring queued" flag shows at once.
	 *
	 * @param int      $product_id Product id.
	 * @param string[] $fields     Fields to re-score. Empty means every field.
	 * @return array{record:array,queued:int}|\WP_Error
	 */
	public function rescore( $product_id, array $fields = array() ) {
		$product_id = (int) $product_id;
		$items      = array();
		foreach ( self::request_items( $product_id ) as $item ) {
			if ( empty( $fields ) || in_array( $item['field'], $fields, true ) ) {
				$items[] = $item;
			}
		}
		if ( empty( $items ) ) {
			return new \WP_Error( 'agencoen_quality_nothing', __( 'There is no content on this product to score.', 'agentic-commerce-engine' ) );
		}
		$items = array_slice( $items, 0, self::RESCORE_BATCH );

		$response = $this->plugin->api()->quality_rescore( $this->plugin->settings()->get_store_id(), $items );
		$entries  = self::entries_from_response( $response, count( $items ) );
		if ( is_wp_error( $entries ) ) {
			return $entries;
		}

		$record = self::read_record( $product_id );
		$record = self::merge( null === $record ? array( 'entries' => array() ) : $record, $entries );

		$record['fetched_at'] = gmdate( 'c' );
		self::store( $product_id, $record );
		delete_transient( Product_Repository::QUALITY_TRANSIENT );

		return array(
			'record' => $record,
			'queued' => is_array( $response ) && isset( $response['queued'] ) ? (int) $response['queued'] : 0,
		);
	}

	/* --------------------------------------------------------------------- */
	/* Cache                                                                 */
	/* --------------------------------------------------------------------- */

	/**
	 * Cache one product's record, and the two companions derived from it.
	 *
	 * Slashed because `update_post_meta()` unslashes what it is given, and a
	 * JSON string is full of backslashes (escaped quotes in the reasoning,
	 * `\u` escapes) that would otherwise be stripped into invalid JSON. The same
	 * reason `Publisher` slashes its JSON-LD.
	 *
	 * @param int   $product_id Product id.
	 * @param array $record     { fetched_at, entries }.
	 */
	public static function store( $product_id, array $record ) {
		$product_id = (int) $product_id;
		$entries    = isset( $record['entries'] ) && is_array( $record['entries'] ) ? array_values( $record['entries'] ) : array();
		$fetched_at = isset( $record['fetched_at'] ) ? (string) $record['fetched_at'] : gmdate( 'c' );

		update_post_meta(
			$product_id,
			Meta::QUALITY,
			wp_slash(
				(string) wp_json_encode(
					array(
						'fetched_at' => $fetched_at,
						'entries'    => $entries,
					)
				)
			)
		);

		$at = strtotime( $fetched_at );
		update_post_meta( $product_id, Meta::QUALITY_AT, false === $at ? time() : $at );

		$overall = self::product_overall( $entries );
		if ( null === $overall ) {
			// Absent, never 0: SQL sorts and buckets this key, and a 0 would
			// read as a measured score of zero.
			delete_post_meta( $product_id, Meta::QUALITY_OVERALL );
		} else {
			update_post_meta( $product_id, Meta::QUALITY_OVERALL, $overall );
		}
	}

	/**
	 * The cached record for a product, or null when it has never been read.
	 *
	 * @param int $product_id Product id.
	 * @return array{fetched_at:string,entries:array}|null
	 */
	public static function read_record( $product_id ) {
		$raw = get_post_meta( (int) $product_id, Meta::QUALITY, true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || ! isset( $data['entries'] ) || ! is_array( $data['entries'] ) ) {
			return null;
		}
		$entries = array();
		foreach ( $data['entries'] as $entry ) {
			$entry = self::normalize_entry( $entry );
			if ( null !== $entry ) {
				$entries[] = $entry;
			}
		}
		return array(
			'fetched_at' => isset( $data['fetched_at'] ) ? (string) $data['fetched_at'] : '',
			'entries'    => $entries,
		);
	}

	/**
	 * The cached product-level overall, or null when nothing is measured.
	 *
	 * @param int $product_id Product id.
	 * @return int|null
	 */
	public static function cached_overall( $product_id ) {
		$value = get_post_meta( (int) $product_id, Meta::QUALITY_OVERALL, true );
		return is_numeric( $value ) ? (int) $value : null;
	}

	/**
	 * Drop a product's cached scores (its proposal was replaced).
	 *
	 * @param int $product_id Product id.
	 */
	public static function forget( $product_id ) {
		$product_id = (int) $product_id;
		delete_post_meta( $product_id, Meta::QUALITY );
		delete_post_meta( $product_id, Meta::QUALITY_OVERALL );
		delete_post_meta( $product_id, Meta::QUALITY_AT );
	}

	/* --------------------------------------------------------------------- */
	/* Pure helpers (unit-tested directly)                                   */
	/* --------------------------------------------------------------------- */

	/**
	 * What to ask Rezolve Ai about one product.
	 *
	 * The fields come from {@see Review_Sync::proposal_fields()}, the same
	 * derivation the decisions report uses, so the plugin asks for the score of
	 * exactly the content it reports decisions about. `jobId` lets the platform
	 * say when its score describes a different version than this site holds.
	 *
	 * @param int $product_id Product id.
	 * @return array List of { productId, field, jobId? }.
	 */
	public static function request_items( $product_id ) {
		$product_id = (int) $product_id;
		$status     = (string) get_post_meta( $product_id, Meta::STATUS, true );
		$job_id     = (string) get_post_meta( $product_id, Meta::JOB_ID, true );

		$items = array();
		foreach ( Review_Sync::proposal_fields( $product_id, $status ) as $field ) {
			$item = array(
				'productId' => (string) $product_id,
				'field'     => (string) $field,
			);
			// The platform rejects a jobId over 64 characters, and one bad item
			// fails the whole batch. Leaving it off only loses the version note.
			if ( '' !== $job_id && strlen( $job_id ) <= 64 ) {
				$item['jobId'] = $job_id;
			}
			$items[] = $item;
		}
		return $items;
	}

	/**
	 * Split products into batches of at most `$max` items, never splitting one
	 * product across two calls.
	 *
	 * @param array<int,array> $items_by_product Product id => its items.
	 * @param int              $max              Items per batch.
	 * @return int[][] Batches of product ids.
	 */
	public static function pack( array $items_by_product, $max ) {
		$batches = array();
		$current = array();
		$size    = 0;
		foreach ( $items_by_product as $id => $items ) {
			$n = count( $items );
			if ( ! empty( $current ) && $size + $n > $max ) {
				$batches[] = $current;
				$current   = array();
				$size      = 0;
			}
			$current[] = (int) $id;
			$size     += $n;
		}
		if ( ! empty( $current ) ) {
			$batches[] = $current;
		}
		return $batches;
	}

	/**
	 * Normalised entries from a quality response, or an error.
	 *
	 * A response with a different number of entries than was asked for cannot
	 * be matched back to products by position, so it is refused whole rather
	 * than cached against the wrong fields.
	 *
	 * @param array|\WP_Error $response Api_Client result.
	 * @param int             $expected Items that were sent.
	 * @return array|\WP_Error
	 */
	public static function entries_from_response( $response, $expected ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$raw = is_array( $response ) && isset( $response['items'] ) && is_array( $response['items'] ) ? array_values( $response['items'] ) : null;
		if ( null === $raw || count( $raw ) !== (int) $expected ) {
			return new \WP_Error( 'agencoen_quality_shape', __( 'Rezolve Ai returned quality scores this plugin could not read.', 'agentic-commerce-engine' ) );
		}
		$entries = array();
		foreach ( $raw as $entry ) {
			$entry = self::normalize_entry( $entry );
			if ( null === $entry ) {
				return new \WP_Error( 'agencoen_quality_shape', __( 'Rezolve Ai returned quality scores this plugin could not read.', 'agentic-commerce-engine' ) );
			}
			$entries[] = $entry;
		}
		return $entries;
	}

	/**
	 * One `PluginQualityEntry`, reduced to known keys and checked types.
	 *
	 * Reading, not judging. A number outside 0 to 100 or of the wrong type
	 * becomes null ("Not scored"), never a clamped or substituted value. An
	 * unrecognised `state` is read as unscored with no numbers, because this
	 * plugin cannot know how a state it has never heard of should be presented
	 * and must not show its numbers as current.
	 *
	 * @param mixed $entry Raw entry.
	 * @return array|null Null when it is not an entry at all.
	 */
	public static function normalize_entry( $entry ) {
		if ( ! is_array( $entry ) || ! isset( $entry['productId'] ) || ! is_scalar( $entry['productId'] ) ) {
			return null;
		}
		$out = array(
			'productId'   => (string) $entry['productId'],
			'field'       => isset( $entry['field'] ) && is_string( $entry['field'] ) ? $entry['field'] : null,
			'contentType' => isset( $entry['contentType'] ) && is_string( $entry['contentType'] ) ? $entry['contentType'] : null,
			'found'       => isset( $entry['found'] ) && true === $entry['found'],
		);

		if ( ! $out['found'] ) {
			$out['reason'] = isset( $entry['reason'] ) && is_string( $entry['reason'] ) ? $entry['reason'] : 'no_matching_item';
			return $out;
		}

		$out['reviewItemId']     = isset( $entry['reviewItemId'] ) && is_scalar( $entry['reviewItemId'] ) ? (string) $entry['reviewItemId'] : '';
		$out['scoringQueued']    = isset( $entry['scoringQueued'] ) && true === $entry['scoringQueued'];
		$out['differentVersion'] = isset( $entry['differentVersion'] ) && true === $entry['differentVersion'];

		$raw   = isset( $entry['quality'] ) && is_array( $entry['quality'] ) ? $entry['quality'] : array();
		$state = isset( $raw['state'] ) && in_array( $raw['state'], self::STATES, true ) ? $raw['state'] : 'unscored';

		$quality = array( 'state' => $state );
		foreach ( self::NUMBERS as $key ) {
			$quality[ $key ] = 'unscored' === $state ? null : self::score( isset( $raw[ $key ] ) ? $raw[ $key ] : null );
		}
		$quality['brandVoiceConfigured'] = isset( $raw['brandVoiceConfigured'] ) && is_bool( $raw['brandVoiceConfigured'] ) ? $raw['brandVoiceConfigured'] : null;
		$quality['reasoning']            = 'unscored' !== $state && isset( $raw['reasoning'] ) && is_string( $raw['reasoning'] ) && '' !== trim( $raw['reasoning'] ) ? trim( $raw['reasoning'] ) : null;
		$quality['scoredAt']             = 'unscored' !== $state && isset( $raw['scoredAt'] ) && is_string( $raw['scoredAt'] ) && '' !== $raw['scoredAt'] ? $raw['scoredAt'] : null;

		$out['quality'] = $quality;
		return $out;
	}

	/**
	 * A measured score, or null.
	 *
	 * @param mixed $value Raw value.
	 * @return int|float|null
	 */
	private static function score( $value ) {
		if ( ( ! is_int( $value ) && ! is_float( $value ) ) || ! is_finite( (float) $value ) ) {
			return null;
		}
		if ( $value < 0 || $value > 100 ) {
			return null;
		}
		return is_float( $value ) && floor( $value ) === $value ? (int) $value : $value;
	}

	/**
	 * The one number for a whole product: list sort, dashboard chart, CSV.
	 *
	 * THE DEFINITION. The unweighted mean of the platform's own `overall`, over
	 * the distinct content types of this product that have one, rounded half
	 * away from zero. Null when no content type has one.
	 *
	 * - Distinct content types, not fields. `seo_title` and `seo_description`
	 *   share one meta-optimization review row and one score; counting it twice
	 *   would weight SEO copy double for no reason but the plugin's field layout.
	 * - Unweighted. The platform weights dimensions within a row but has no rule
	 *   for weighting a title against a description, and inventing one here
	 *   would be inventing a score.
	 * - Any row with an `overall`, whatever its state. Stale and outdated rows
	 *   still describe a real measurement (the Review screen says which), while
	 *   an unscored row has no `overall` by the platform's own contract.
	 * - Null, never a default. A product with nothing measured reads
	 *   "Not scored" and sorts last.
	 *
	 * @param array $entries Normalised entries.
	 * @return int|null
	 */
	public static function product_overall( array $entries ) {
		$by_type = array();
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['found'] ) || ! isset( $entry['quality'] ) || ! is_array( $entry['quality'] ) ) {
				continue;
			}
			$overall = isset( $entry['quality']['overall'] ) ? $entry['quality']['overall'] : null;
			if ( ! is_int( $overall ) && ! is_float( $overall ) ) {
				continue;
			}
			$by_type[ self::group_key( $entry ) ] = (float) $overall;
		}
		if ( empty( $by_type ) ) {
			return null;
		}
		return (int) round( array_sum( $by_type ) / count( $by_type ) );
	}

	/**
	 * Replace cached entries with fresher ones for the same fields.
	 *
	 * @param array $record  Cached record.
	 * @param array $entries Fresh normalised entries.
	 * @return array Record.
	 */
	public static function merge( array $record, array $entries ) {
		$by_field = array();
		foreach ( isset( $record['entries'] ) && is_array( $record['entries'] ) ? $record['entries'] : array() as $entry ) {
			$by_field[ self::field_key( $entry ) ] = $entry;
		}
		foreach ( $entries as $entry ) {
			$by_field[ self::field_key( $entry ) ] = $entry;
		}
		$record['entries'] = array_values( $by_field );
		return $record;
	}

	/**
	 * What the Review screen renders for one product.
	 *
	 * Grouped by content type, so the shared meta-optimization score is shown
	 * once, under both of the fields it describes.
	 *
	 * @param array|null $record Cached or fresh record.
	 * @return array{known:bool,overall:int|null,fetchedAt:string|null,groups:array}
	 */
	public static function view( $record ) {
		if ( ! is_array( $record ) ) {
			return array(
				'known'     => false,
				'overall'   => null,
				'fetchedAt' => null,
				'groups'    => array(),
			);
		}

		$entries = isset( $record['entries'] ) && is_array( $record['entries'] ) ? $record['entries'] : array();
		$groups  = array();
		foreach ( $entries as $entry ) {
			// An unknown field is a plugin and platform vocabulary mismatch, not
			// something a merchant can act on. The drift-guard tests catch it.
			if ( empty( $entry['found'] ) && isset( $entry['reason'] ) && 'unknown_field' === $entry['reason'] ) {
				continue;
			}
			$key = self::group_key( $entry );
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'key'              => $key,
					'contentType'      => $entry['contentType'],
					'fields'           => array(),
					'found'            => ! empty( $entry['found'] ),
					'reason'           => isset( $entry['reason'] ) ? $entry['reason'] : null,
					'scoringQueued'    => ! empty( $entry['scoringQueued'] ),
					'differentVersion' => ! empty( $entry['differentVersion'] ),
					'quality'          => isset( $entry['quality'] ) ? $entry['quality'] : null,
				);
			}
			if ( null !== $entry['field'] && ! in_array( $entry['field'], $groups[ $key ]['fields'], true ) ) {
				$groups[ $key ]['fields'][] = $entry['field'];
			}
		}

		foreach ( $groups as $key => $group ) {
			$groups[ $key ]['label'] = self::group_label( $group['contentType'], $group['fields'] );
		}

		return array(
			'known'     => true,
			'overall'   => self::product_overall( $entries ),
			'fetchedAt' => isset( $record['fetched_at'] ) && '' !== $record['fetched_at'] ? (string) $record['fetched_at'] : null,
			'groups'    => array_values( $groups ),
		);
	}

	/**
	 * A human name for one scored piece of content.
	 *
	 * Reuses the enrichment picker's labels, so the Review screen calls a piece
	 * of content what the merchant chose when they asked for it. The platform
	 * spells some content types differently from the picker, hence the map.
	 *
	 * @param string|null $content_type Platform content type.
	 * @param string[]    $fields       Plugin fields in the group.
	 * @return string
	 */
	public static function group_label( $content_type, array $fields ) {
		$picker = array(
			'product-title'       => 'product-title',
			'product-description' => 'product-description',
			'meta-optimization'   => 'meta-tags',
			'schema-markup'       => 'jsonld-schema',
			'irl-cards'           => 'irl-scenarios',
			'internal-links'      => 'internal-links',
			'highlights'          => 'highlights',
			'product-qa'          => 'product-qa',
			'product-attributes'  => 'product-attributes',
			'image-alt'           => 'image-alt',
		);
		if ( is_string( $content_type ) && isset( $picker[ $content_type ] ) ) {
			return Content_Types::label( $picker[ $content_type ] );
		}
		return implode( ', ', $fields );
	}

	/**
	 * @param array $entry Normalised entry.
	 * @return string
	 */
	private static function group_key( array $entry ) {
		return isset( $entry['contentType'] ) && null !== $entry['contentType'] ? (string) $entry['contentType'] : 'field:' . ( isset( $entry['field'] ) ? (string) $entry['field'] : '' );
	}

	/**
	 * @param array $entry Normalised entry.
	 * @return string
	 */
	private static function field_key( array $entry ) {
		return isset( $entry['field'] ) && null !== $entry['field'] ? 'field:' . $entry['field'] : 'type:' . ( isset( $entry['contentType'] ) ? (string) $entry['contentType'] : '' );
	}
}
