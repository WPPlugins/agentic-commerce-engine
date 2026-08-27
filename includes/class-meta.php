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
	const APPLIED_TYPES = '_agencoen_applied_types';  // which content types were applied.
	const FEED_PREFIX   = '_agencoen_feed_';          // + channel (google|microsoft|agentic).

	/**
	 * What the `woocommerce` content type ADDED to a product, for exact undo:
	 * array( 'tags' => array( 'added' => int[], 'created' => int[] ),
	 *        'attributes' => array( 'keys' => string[] ) ).
	 *
	 * A ledger and not part of Meta::BACKUP on purpose. BACKUP is a once-only
	 * whole-value snapshot, which is right for scalars this plugin exclusively
	 * owns and wrong for terms: restoring one months later would delete tags the
	 * merchant added by hand in between. See Native_Fields for the full argument.
	 */
	const NATIVE_WRITES = '_agencoen_native_writes';

	const STATUS_QUEUED    = 'queued';
	const STATUS_ENRICHED  = 'enriched';
	const STATUS_APPLIED   = 'applied';
	const STATUS_DISMISSED = 'dismissed';
	const STATUS_FAILED    = 'failed';

	// Content Hub (4.0): posts materialized from ACE article briefs.
	const BRIEF_ID             = '_agencoen_brief_id';       // ACE brief id backing the post.
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
