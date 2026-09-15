<?php
/**
 * Reading highlights, product Q&A and image alt text out of whatever shape they
 * arrive in (4.16.0).
 *
 * THREE CALLERS, ONE READING
 * {@see Apply} (content this site requested), {@see Apply_Queue} (content a
 * merchant approved in Rezolve Ai, as a JSON string) and {@see Review} (the
 * preview) all come through here, so what a merchant previews is exactly what
 * gets written. The platform has sent each of these both bare and wrapped
 * (`{ highlights }`, `{ faqs }`, `{ altTexts }`), so both are accepted.
 *
 * Every method is PURE apart from WordPress's sanitizers, which is what lets
 * tests/test-runner.php cover them without a database.
 *
 * @package Agencoen
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Pdp_Content
 */
final class Pdp_Content {

	/** Caps, so a runaway generation cannot bury a product page. */
	const MAX_HIGHLIGHTS      = 10;
	const MAX_HIGHLIGHT_CHARS = 300;
	const MAX_QA_PAIRS        = 20;

	/**
	 * Clean highlight bullets.
	 *
	 * @param mixed $raw Array, `{ highlights }`, or a JSON string of either.
	 * @return string[]
	 */
	public static function highlights( $raw ) {
		$out  = array();
		$seen = array();
		foreach ( self::list_in( $raw, array( 'highlights', 'bullets' ) ) as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}
			$text = trim( sanitize_text_field( wp_strip_all_tags( (string) $item ) ) );
			$len  = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
			if ( '' === $text || $len > self::MAX_HIGHLIGHT_CHARS ) {
				continue;
			}
			$key = strtolower( $text );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $text;
			if ( count( $out ) >= self::MAX_HIGHLIGHTS ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Clean question and answer pairs. A pair missing either half is dropped.
	 *
	 * @param mixed $raw Array, `{ faqs }`, or a JSON string of either.
	 * @return array<int,array{question:string,answer:string}>
	 */
	public static function qa( $raw ) {
		$out = array();
		foreach ( self::list_in( $raw, array( 'faqs' ) ) as $pair ) {
			if ( ! is_array( $pair ) ) {
				continue;
			}
			$question = isset( $pair['question'] ) && is_scalar( $pair['question'] ) ? trim( sanitize_text_field( wp_strip_all_tags( (string) $pair['question'] ) ) ) : '';
			$answer   = isset( $pair['answer'] ) && is_scalar( $pair['answer'] ) ? trim( sanitize_textarea_field( wp_strip_all_tags( (string) $pair['answer'] ) ) ) : '';
			if ( '' === $question || '' === $answer ) {
				continue;
			}
			$out[] = array(
				'question' => $question,
				'answer'   => $answer,
			);
			if ( count( $out ) >= self::MAX_QA_PAIRS ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Clean alt text entries. Only http(s) image URLs and non-empty alt text.
	 *
	 * @param mixed $raw Array, `{ altTexts }`, or a JSON string of either.
	 * @return array<int,array{url:string,alt:string}>
	 */
	public static function image_alts( $raw ) {
		$out = array();
		foreach ( self::list_in( $raw, array( 'altTexts' ) ) as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$url = isset( $entry['imageUrl'] ) && is_string( $entry['imageUrl'] ) ? trim( $entry['imageUrl'] ) : '';
			$alt = isset( $entry['alt'] ) && is_scalar( $entry['alt'] ) ? trim( sanitize_text_field( wp_strip_all_tags( (string) $entry['alt'] ) ) ) : '';
			if ( '' === $alt || ! preg_match( '#^https?://#i', $url ) ) {
				continue;
			}
			$out[] = array(
				'url' => $url,
				'alt' => $alt,
			);
		}
		return $out;
	}

	/**
	 * Pair alt text entries with the product's own attachments, by URL.
	 *
	 * NEVER BY POSITION. The generator skips an image it could not read, which
	 * shifts every index after it, and a merchant can reorder the gallery between
	 * generation and approval. A URL identifies the picture.
	 *
	 * Matching is on a normalised path (no scheme, query or fragment, host
	 * lowercased), then on the file stem with WordPress's generated suffixes
	 * removed (`-scaled`, `-rotated`, `-300x200`), which covers a full-size URL
	 * against a resized one. A stem shared by two attachments is ambiguous and
	 * matches neither. Each attachment is matched at most once, and a repeated
	 * entry for an attachment already claimed is reported unmatched.
	 *
	 * @param array $entries    From {@see image_alts()}.
	 * @param array $candidates attachment_id => string[] of URLs for that attachment.
	 * @return array{matches:array<int,string>,unmatched:array<int,array{url:string,alt:string}>,by_entry:array<int,int>}
	 *               `matches` is attachment_id => alt text. `by_entry` is entry
	 *               index => attachment_id for every entry that matched, so the
	 *               Review screen's editor can keep each image's own URL (4.18.0).
	 */
	public static function match_image_alts( array $entries, array $candidates ) {
		$by_path = array();
		$by_stem = array();
		foreach ( $candidates as $attachment_id => $urls ) {
			foreach ( (array) $urls as $url ) {
				if ( ! is_string( $url ) || '' === $url ) {
					continue;
				}
				$by_path[ self::normalize_url( $url ) ] = (int) $attachment_id;
				$stem                                   = self::stem( $url );
				if ( ! isset( $by_stem[ $stem ] ) ) {
					$by_stem[ $stem ] = array();
				}
				$by_stem[ $stem ][ (int) $attachment_id ] = true;
			}
		}

		$matches   = array();
		$unmatched = array();
		$by_entry  = array();
		foreach ( $entries as $index => $entry ) {
			$path = self::normalize_url( $entry['url'] );
			$id   = 0;
			if ( isset( $by_path[ $path ] ) ) {
				$id = isset( $matches[ $by_path[ $path ] ] ) ? 0 : $by_path[ $path ];
			} else {
				$stem = self::stem( $entry['url'] );
				$ids  = isset( $by_stem[ $stem ] ) ? array_keys( $by_stem[ $stem ] ) : array();
				$ids  = array_values(
					array_filter(
						$ids,
						function ( $candidate ) use ( $matches ) {
							return ! isset( $matches[ $candidate ] );
						}
					)
				);
				$id   = 1 === count( $ids ) ? (int) $ids[0] : 0;
			}
			if ( $id > 0 ) {
				$matches[ $id ]     = $entry['alt'];
				$by_entry[ $index ] = $id;
			} else {
				$unmatched[] = $entry;
			}
		}
		return array(
			'matches'   => $matches,
			'unmatched' => $unmatched,
			'by_entry'  => $by_entry,
		);
	}

	/**
	 * Host and path, host lowercased, no scheme, query or fragment.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function normalize_url( $url ) {
		$parts = wp_parse_url( trim( (string) $url ) );
		if ( ! is_array( $parts ) || empty( $parts['path'] ) ) {
			return strtolower( trim( (string) $url ) );
		}
		$host = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
		return $host . rawurldecode( $parts['path'] );
	}

	/**
	 * The file name without extension or WordPress's generated size suffixes,
	 * lowercased: `bottle-scaled.jpg`, `bottle-300x200.jpg` and `bottle.jpg` all
	 * become `bottle`.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function stem( $url ) {
		$path = self::normalize_url( $url );
		$name = strtolower( (string) basename( $path ) );
		$name = preg_replace( '/\.[a-z0-9]+$/', '', $name );
		$name = preg_replace( '/-(\d+x\d+)$/', '', $name );
		return (string) preg_replace( '/-(scaled|rotated)$/', '', $name );
	}

	/**
	 * A list out of a bare array, a wrapper under one of `$keys`, or a JSON
	 * string of either.
	 *
	 * @param mixed    $raw  Raw value.
	 * @param string[] $keys Wrapper keys to look under.
	 * @return array
	 */
	private static function list_in( $raw, array $keys ) {
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : null;
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		foreach ( $keys as $key ) {
			if ( isset( $raw[ $key ] ) && is_array( $raw[ $key ] ) ) {
				return $raw[ $key ];
			}
		}
		// A list, not an associative wrapper under an unknown key.
		return array_values( $raw ) === $raw ? $raw : array();
	}
}
