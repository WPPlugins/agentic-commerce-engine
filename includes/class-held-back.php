<?php
/**
 * Whether a product split is holding back a product this site is about to
 * apply content to (4.29.0).
 *
 * WHY APPLY HAS TO ASK FIRST
 * A product split keeps its control group's content unchanged for the length
 * of the test. Rezolve Ai refuses to approve a control product's content on
 * every path of its own, but approving here IS applying: {@see Apply::apply()}
 * writes to the product at once and only tells Rezolve Ai afterwards, when the
 * report can say nothing but "applied". Rezolve Ai can then only record the
 * control group as broken. So Apply asks here before it writes, and a held
 * product stays waiting in Review with the reason beside it. An edit to live
 * content writes to the product just the same, so {@see Apply::write_edit()}
 * asks too (4.30.1).
 *
 * THE RULE IS REZOLVE AI'S
 * `POST /api/v1/plugin/held-back` runs the same check every Rezolve Ai approval
 * runs. Nothing here decides which products are held; it asks, and keeps the
 * answer.
 *
 * A FAILED CHECK REFUSES. Applying on "we could not tell" is exactly how a
 * control group is lost, while refusing costs one more try. A site that is not
 * connected has nothing to ask and nothing is held: it cannot reach Rezolve Ai
 * for this or for anything else.
 *
 * THE ANSWER IS KEPT ON THE PRODUCT (`Meta::HELD_BACK`) so Review can say why
 * without asking again, and so "Approve all" and the auto-apply sweep can step
 * past held products instead of retrying them on every batch. Every check
 * rewrites it, and the review-sync tick re-checks the products that carry one,
 * so a released product becomes approvable again on its own.
 *
 * @package Agencoen
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Held_Back
 */
class Held_Back {

	/** Items per request: the endpoint's own cap. */
	const CHUNK = 500;

	/** Stored answers re-checked per refresh. */
	const REFRESH_LIMIT = 100;

	/**
	 * The refresh runs at most this often. The review-sync tick re-arms every
	 * minute while a backlog of decisions drains, and that must not become a
	 * request a minute.
	 */
	const REFRESH_INTERVAL = HOUR_IN_SECONDS;

	/** Transient marking the last refresh. */
	const REFRESHED_KEY = 'agencoen_held_back_refreshed';

	/** @var Plugin */
	private $plugin;

	/**
	 * Answers already had in this request, by product id: a note, null for
	 * "not held", or the error a failed check returned. A batch asks once for
	 * all its products and every Apply::apply() after that reads from here.
	 *
	 * @var array<int, array|null|\WP_Error>
	 */
	private $answers = array();

	/**
	 * @param Plugin $plugin Orchestrator.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * What one product's proposal would write, in the endpoint's vocabulary.
	 *
	 * Every content type Apply would write is asked about, not only the fields
	 * the merchant ticked: Apply writes every stored content type whatever the
	 * field subset. Flat fields go as fields; each `Meta::CONTENT` key goes as a
	 * content type in the engine's spelling, which Rezolve Ai maps itself (a
	 * feed channel included).
	 *
	 * @param int $product_id Product id.
	 * @return array[] Each { productId, field } or { productId, contentType }.
	 */
	public static function items_for( $product_id ) {
		$product_id = (int) $product_id;
		$pending    = get_post_meta( $product_id, Meta::PENDING, true );
		$pending    = is_array( $pending ) ? $pending : array();
		$content    = get_post_meta( $product_id, Meta::CONTENT, true );
		$content    = is_array( $content ) ? $content : array();

		// The short description is derived from the description and goes live
		// with it, so it is the description an experiment would measure.
		$flat   = array(
			'title'             => 'title',
			'description'       => 'description',
			'short_description' => 'description',
			'seo_title'         => 'seo_title',
			'seo_description'   => 'seo_description',
		);
		$fields = array();
		foreach ( $flat as $key => $field ) {
			if ( isset( $pending[ $key ] ) && '' !== $pending[ $key ] ) {
				$fields[ $field ] = true;
			}
		}

		$items = array();
		foreach ( array_keys( $fields ) as $field ) {
			$items[] = array(
				'productId' => (string) $product_id,
				'field'     => $field,
			);
		}
		foreach ( $content as $type => $data ) {
			if ( empty( $data ) || ! is_string( $type ) || '' === $type ) {
				continue;
			}
			$items[] = array(
				'productId'   => (string) $product_id,
				'contentType' => $type,
			);
		}
		return $items;
	}

	/**
	 * Ask about several products at once, and keep the answers.
	 *
	 * @param int[] $product_ids Product ids.
	 * @return array<int, array|null>|\WP_Error Product id => note, or null when
	 *                                          not held. An error when Rezolve
	 *                                          Ai could not answer.
	 */
	public function check( array $product_ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $product_ids ) ) ) );
		$out = array();
		if ( empty( $ids ) ) {
			return $out;
		}

		if ( ! $this->plugin->settings()->is_connected() ) {
			foreach ( $ids as $id ) {
				$this->answers[ $id ] = null;
				$out[ $id ]           = null;
			}
			return $out;
		}

		// Products in request-sized batches, never splitting one product's
		// items across two requests.
		$batches = array();
		$batch   = array();
		$size    = 0;
		foreach ( $ids as $id ) {
			$items = self::items_for( $id );
			if ( empty( $items ) ) {
				$this->answers[ $id ] = null;
				$out[ $id ]           = null;
				continue;
			}
			if ( $size > 0 && $size + count( $items ) > self::CHUNK ) {
				$batches[] = $batch;
				$batch     = array();
				$size      = 0;
			}
			$batch[ $id ] = $items;
			$size        += count( $items );
		}
		if ( ! empty( $batch ) ) {
			$batches[] = $batch;
		}

		$store_id = $this->plugin->settings()->get_store_id();
		foreach ( $batches as $index => $batch ) {
			$items = array();
			foreach ( $batch as $product_items ) {
				$items = array_merge( $items, $product_items );
			}
			$response = $this->plugin->api()->held_back( $store_id, $items );
			$held     = is_array( $response ) && isset( $response['heldBack'] ) && is_array( $response['heldBack'] ) ? $response['heldBack'] : null;
			if ( null === $held ) {
				$error = is_wp_error( $response ) ? $response : new \WP_Error( 'agencoen_held_back_shape', 'unreadable held-back answer' );
				$this->plugin->logger()->log( 'Could not check held-back products, so nothing was applied: ' . $error->get_error_message(), 'warning' );
				$refusal = self::unavailable();
				// Every product not answered yet shares the refusal, so the
				// Apply::apply() calls that follow do not ask again one by one.
				foreach ( array_slice( $batches, $index ) as $rest ) {
					foreach ( array_keys( $rest ) as $id ) {
						$this->answers[ $id ] = $refusal;
					}
				}
				return $refusal;
			}

			$notes = array();
			foreach ( $held as $entry ) {
				$id = is_array( $entry ) && isset( $entry['productId'] ) ? (int) $entry['productId'] : 0;
				if ( ! isset( $batch[ $id ] ) ) {
					continue;
				}
				$note = array(
					'experimentName' => isset( $entry['experimentName'] ) && is_string( $entry['experimentName'] ) ? $entry['experimentName'] : null,
					'releaseAt'      => isset( $entry['releaseAt'] ) && is_string( $entry['releaseAt'] ) ? $entry['releaseAt'] : null,
					'checkedAt'      => gmdate( 'c' ),
				);
				// Held by two experiments: not approvable until both let it go.
				if ( ! isset( $notes[ $id ] ) || self::releases_later( $note, $notes[ $id ] ) ) {
					$notes[ $id ] = $note;
				}
			}

			foreach ( array_keys( $batch ) as $id ) {
				$note                 = isset( $notes[ $id ] ) ? $notes[ $id ] : null;
				$this->answers[ $id ] = $note;
				$out[ $id ]           = $note;
				if ( null === $note ) {
					delete_post_meta( $id, Meta::HELD_BACK );
				} else {
					update_post_meta( $id, Meta::HELD_BACK, $note );
				}
			}
		}
		return $out;
	}

	/**
	 * One product's answer, from this request's answers when it has one.
	 *
	 * @param int $product_id Product id.
	 * @return array|null|\WP_Error
	 */
	public function note_for( $product_id ) {
		$product_id = (int) $product_id;
		if ( ! array_key_exists( $product_id, $this->answers ) ) {
			$result = $this->check( array( $product_id ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		return isset( $this->answers[ $product_id ] ) ? $this->answers[ $product_id ] : null;
	}

	/**
	 * The answer kept on the product by the last check, for the Review list.
	 *
	 * @param int $product_id Product id.
	 * @return array|null
	 */
	public static function stored( $product_id ) {
		$note = get_post_meta( (int) $product_id, Meta::HELD_BACK, true );
		return is_array( $note ) ? $note : null;
	}

	/**
	 * Re-check the products that carry a stored answer, so one that has been
	 * released comes back to "Approve all" and the auto-apply sweep without a
	 * merchant having to open it. Runs on the review-sync tick, at most hourly.
	 *
	 * @param int $limit Products per pass.
	 * @return int Products re-checked.
	 */
	public function refresh_stored( $limit = self::REFRESH_LIMIT ) {
		if ( ! $this->plugin->settings()->is_connected() || get_transient( self::REFRESHED_KEY ) ) {
			return 0;
		}
		set_transient( self::REFRESHED_KEY, 1, self::REFRESH_INTERVAL );
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => (int) $limit,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'     => array(
					array(
						'key'     => Meta::HELD_BACK,
						'compare' => 'EXISTS',
					),
				),
			)
		);
		if ( empty( $ids ) ) {
			return 0;
		}
		$waiting = array();
		foreach ( $ids as $id ) {
			// Decided since (applied, dismissed): nothing left to hold back.
			if ( Meta::STATUS_ENRICHED === get_post_meta( (int) $id, Meta::STATUS, true ) ) {
				$waiting[] = (int) $id;
			} else {
				delete_post_meta( (int) $id, Meta::HELD_BACK );
			}
		}
		if ( ! empty( $waiting ) ) {
			$this->check( $waiting );
		}
		return count( $ids );
	}

	/**
	 * One line for a held product: `Held back by "Spring copy" until 24 November 2026`.
	 *
	 * @param array $note { experimentName, releaseAt }.
	 * @return string
	 */
	public static function label( array $note ) {
		$name = isset( $note['experimentName'] ) && is_string( $note['experimentName'] ) && '' !== $note['experimentName'] ? $note['experimentName'] : '';
		$date = self::upcoming( isset( $note['releaseAt'] ) ? $note['releaseAt'] : null );
		if ( '' !== $name && '' !== $date ) {
			/* translators: 1: experiment name, 2: date the experiment releases the product. */
			return sprintf( __( 'Held back by "%1$s" until %2$s', 'agentic-commerce-engine' ), $name, $date );
		}
		if ( '' !== $name ) {
			/* translators: %s: experiment name. */
			return sprintf( __( 'Held back by "%s" until it finishes', 'agentic-commerce-engine' ), $name );
		}
		if ( '' !== $date ) {
			/* translators: %s: date the experiment releases the product. */
			return sprintf( __( 'Held back by an experiment until %s', 'agentic-commerce-engine' ), $date );
		}
		return __( 'Held back by an experiment until it finishes', 'agentic-commerce-engine' );
	}

	/**
	 * What Apply returns for a held product. The label rides along as data so
	 * the Review screen can mark the row without asking again.
	 *
	 * @param array $note { experimentName, releaseAt }.
	 * @return \WP_Error
	 */
	public static function refusal( array $note ) {
		$label = self::label( $note );
		return new \WP_Error(
			'agencoen_held_back',
			sprintf(
				/* translators: %s: why the product is held, e.g. Held back by "Spring copy" until 24 November 2026. */
				__( 'Not applied. %s. This product is in the control group of a product split, which keeps its content unchanged while the experiment measures it. It can be approved after that.', 'agentic-commerce-engine' ),
				$label
			),
			array( 'heldBack' => $label )
		);
	}

	/**
	 * What Apply returns when Rezolve Ai could not say.
	 *
	 * @return \WP_Error
	 */
	public static function unavailable() {
		return new \WP_Error(
			'agencoen_held_back_unavailable',
			__( 'We could not check whether this product is held back by an experiment, so nothing was applied. Try again in a minute.', 'agentic-commerce-engine' )
		);
	}

	/**
	 * What an edit to a held product's live content returns (4.30.1). The
	 * label rides along as it does for {@see refusal()}.
	 *
	 * @param array $note { experimentName, releaseAt }.
	 * @return \WP_Error
	 */
	public static function edit_refusal( array $note ) {
		$label = self::label( $note );
		return new \WP_Error(
			'agencoen_held_back',
			sprintf(
				/* translators: %s: why the product is held, e.g. Held back by "Spring copy" until 24 November 2026. */
				__( 'Your edit was not saved. %s. This product is in the control group of a product split, which keeps its live content unchanged while the experiment measures it. It can be edited after that.', 'agentic-commerce-engine' ),
				$label
			),
			array( 'heldBack' => $label )
		);
	}

	/**
	 * What an edit to live content returns when Rezolve Ai could not say (4.30.1).
	 *
	 * @return \WP_Error
	 */
	public static function edit_unavailable() {
		return new \WP_Error(
			'agencoen_held_back_unavailable',
			__( 'We could not check whether this product is held back by an experiment, so your edit was not saved. Try again in a minute.', 'agentic-commerce-engine' )
		);
	}

	/**
	 * A release date worth naming, in the site's date format, or ''.
	 *
	 * A date already passed is not named: the release runs once a day, so a
	 * product can still be held for a few hours after its date, and "until"
	 * a day that has gone reads as broken.
	 *
	 * @param mixed $release_at ISO 8601 or null.
	 * @return string
	 */
	private static function upcoming( $release_at ) {
		$at = is_string( $release_at ) ? strtotime( $release_at ) : false;
		if ( false === $at || $at <= time() ) {
			return '';
		}
		return date_i18n( get_option( 'date_format' ), $at );
	}

	/**
	 * Whether note `a` releases later than note `b`. No date means "until it
	 * finishes", which is later than any date.
	 *
	 * @param array $a Note.
	 * @param array $b Note.
	 * @return bool
	 */
	private static function releases_later( array $a, array $b ) {
		if ( empty( $a['releaseAt'] ) ) {
			return ! empty( $b['releaseAt'] );
		}
		if ( empty( $b['releaseAt'] ) ) {
			return false;
		}
		return strtotime( $a['releaseAt'] ) > strtotime( $b['releaseAt'] );
	}
}
