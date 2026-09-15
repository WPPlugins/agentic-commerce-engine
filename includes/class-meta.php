<?php
/**
 * Single source of truth for per-product meta keys and enrichment statuses.
 *
 * @package Agencoen
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Meta
 */
final class Meta {

	const STATUS      = '_agencoen_status';
	const JOB_ID      = '_agencoen_job_id';
	const PENDING     = '_agencoen_pending';
	const SCORE       = '_agencoen_score';
	const ENRICHED_AT = '_agencoen_enriched_at';
	const BACKUP      = '_agencoen_backup';
	const APPLIED_AT  = '_agencoen_applied_at';

	// Per-content-type results + applied artifacts.
	const CONTENT       = '_agencoen_content';       // full per-type payload from the platform.
	const JSONLD        = '_agencoen_jsonld';         // applied Product JSON-LD graph.
	const IRL           = '_agencoen_irl';            // applied IRL scenario cards.
	const LINKS         = '_agencoen_links';          // applied internal links.
	const HIGHLIGHTS    = '_agencoen_highlights';     // applied highlight bullets (4.16.0).
	const QA            = '_agencoen_qa';             // applied product Q&A pairs (4.16.0).
	const APPLIED_TYPES = '_agencoen_applied_types';  // which content types were applied.
	const FEED_PREFIX   = '_agencoen_feed_';          // + channel (google|microsoft|agentic).

	/**
	 * What the `woocommerce` content type ADDED to a product, for exact undo:
	 * array( 'tags' => array( 'added' => int[], 'created' => int[] ),
	 *        'attributes' => array( 'keys' => string[] ),
	 *        'image_alt' => array( attachment_id => array( 'previous' => string, 'written' => string ) ) ).
	 *
	 * `image_alt` (4.16.0) is the one entry that OVERWRITES rather than adds,
	 * because the merchant approved replacing each image's alt text after seeing
	 * both. It keeps the value from before the FIRST write, so a revert after two
	 * applies still restores the merchant's own text.
	 *
	 * A ledger and not part of Meta::BACKUP on purpose. BACKUP is a once-only
	 * whole-value snapshot, which is right for scalars this plugin exclusively
	 * owns and wrong for terms: restoring one months later would delete tags the
	 * merchant added by hand in between. See Native_Fields for the full argument.
	 */
	const NATIVE_WRITES = '_agencoen_native_writes';

	/**
	 * Review-sync bookkeeping (plugin 4.15.0).
	 *
	 * SYNC_DIRTY carries `{ reason, at }`, not merely a flag, and the reason is
	 * load-bearing: `Apply::revert()` puts a product back to STATUS_ENRICHED, so a
	 * reporter that read only the current status would announce a just-reverted
	 * product to Rezolve Ai as a FRESH proposal, and the web review queue would
	 * offer the merchant content they had explicitly taken down.
	 */
	const SYNC_DIRTY    = '_agencoen_review_dirty';
	/** contentType => Rezolve Ai review item id, returned by the last report. */
	const REVIEW_IDS    = '_agencoen_review_ids';
	/** Consecutive failed report attempts, for backoff and for the admin notice. */
	const SYNC_ATTEMPTS = '_agencoen_review_attempts';

	const STATUS_QUEUED    = 'queued';
	const STATUS_ENRICHED  = 'enriched';
	const STATUS_APPLIED   = 'applied';
	const STATUS_DISMISSED = 'dismissed';
	const STATUS_FAILED    = 'failed';

	// Content Hub (4.0): posts materialized from Rezolve Ai article briefs.
	const BRIEF_ID             = '_agencoen_brief_id';       // Rezolve Ai brief id backing the post.
	const ARTICLE_SOURCE       = '_agencoen_source';          // 'ace' for plugin-authored posts.
	const ARTICLE_JSONLD       = '_agencoen_article_jsonld';  // Article JSON-LD from the platform.
	const ARTICLE_PUBLISHED_AT = '_agencoen_published_at';    // when the post went live (ISO 8601).
	const CONTENT_JOB          = '_agencoen_content_job';     // article generation job id.

	/**
	 * The five fields enrichment can touch, with human labels.
	 *
	 * @return array<string,string>
	 */
	public static function fields() {
		return array(
			'title'             => __( 'Title', 'agentic-commerce-engine' ),
			'short_description' => __( 'Short description', 'agentic-commerce-engine' ),
			'description'       => __( 'Description', 'agentic-commerce-engine' ),
			'seo_title'         => __( 'SEO title', 'agentic-commerce-engine' ),
			'seo_description'   => __( 'SEO meta description', 'agentic-commerce-engine' ),
		);
	}
}
