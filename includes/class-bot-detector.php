<?php
/**
 * AI crawler detection: a data-driven catalog of known AI user agents.
 *
 * Pure PHP on purpose (no WordPress functions) so tests/test-runner.php can
 * cover the matching logic without a WordPress environment. The catalog is
 * ordered most-specific first because match() returns the first substring hit
 * (e.g. "applebot-extended" must win before "applebot").
 *
 * Each entry: slug (doubles as the case-insensitive UA substring), label,
 * vendor, chart color, and category:
 *  - training  crawls to build model training corpora
 *  - search    indexes for an AI search engine or answer product
 *  - assistant fetches pages live on behalf of a user's question
 *
 * @package Agencoen
 */

namespace Agencoen;

defined( 'ABSPATH' ) || exit;

/**
 * Class Bot_Detector
 */
class Bot_Detector {

	/**
	 * Memoized catalog: match() runs on every anonymous front-end request, so
	 * the array is built once per process instead of once per call.
	 *
	 * @var array<int,array{slug:string,label:string,vendor:string,color:string,category:string}>|null
	 */
	private static $catalog = null;

	/**
	 * Ordered catalog of known AI crawlers (most specific substrings first).
	 *
	 * @return array<int,array{slug:string,label:string,vendor:string,color:string,category:string}>
	 */
	public static function catalog() {
		if ( null !== self::$catalog ) {
			return self::$catalog;
		}
		self::$catalog = array(
			// OpenAI.
			array(
				'slug'     => 'oai-searchbot',
				'label'    => 'OAI-SearchBot',
				'vendor'   => 'OpenAI',
				'color'    => '#10b981',
				'category' => 'search',
			),
			array(
				'slug'     => 'chatgpt-user',
				'label'    => 'ChatGPT-User',
				'vendor'   => 'OpenAI',
				'color'    => '#34d399',
				'category' => 'assistant',
			),
			array(
				'slug'     => 'gptbot',
				'label'    => 'GPTBot',
				'vendor'   => 'OpenAI',
				'color'    => '#059669',
				'category' => 'training',
			),
			// Anthropic.
			array(
				'slug'     => 'claude-searchbot',
				'label'    => 'Claude-SearchBot',
				'vendor'   => 'Anthropic',
				'color'    => '#f59e0b',
				'category' => 'search',
			),
			array(
				'slug'     => 'claude-user',
				'label'    => 'Claude-User',
				'vendor'   => 'Anthropic',
				'color'    => '#fbbf24',
				'category' => 'assistant',
			),
			array(
				'slug'     => 'claudebot',
				'label'    => 'ClaudeBot',
				'vendor'   => 'Anthropic',
				'color'    => '#d97706',
				'category' => 'training',
			),
			array(
				'slug'     => 'anthropic-ai',
				'label'    => 'Anthropic-AI',
				'vendor'   => 'Anthropic',
				'color'    => '#b45309',
				'category' => 'training',
			),
			// Perplexity.
			array(
				'slug'     => 'perplexity-user',
				'label'    => 'Perplexity-User',
				'vendor'   => 'Perplexity',
				'color'    => '#22d3ee',
				'category' => 'assistant',
			),
			array(
				'slug'     => 'perplexitybot',
				'label'    => 'PerplexityBot',
				'vendor'   => 'Perplexity',
				'color'    => '#06b6d4',
				'category' => 'search',
			),
			// Google.
			array(
				'slug'     => 'google-extended',
				'label'    => 'Google-Extended',
				'vendor'   => 'Google',
				'color'    => '#6366f1',
				'category' => 'training',
			),
			array(
				'slug'     => 'googleother',
				'label'    => 'GoogleOther',
				'vendor'   => 'Google',
				'color'    => '#818cf8',
				'category' => 'training',
			),
			// Apple ("applebot-extended" must precede "applebot").
			array(
				'slug'     => 'applebot-extended',
				'label'    => 'Applebot-Extended',
				'vendor'   => 'Apple',
				'color'    => '#8b5cf6',
				'category' => 'training',
			),
			array(
				'slug'     => 'applebot',
				'label'    => 'Applebot',
				'vendor'   => 'Apple',
				'color'    => '#a78bfa',
				'category' => 'search',
			),
			// Meta.
			array(
				'slug'     => 'meta-externalagent',
				'label'    => 'Meta-ExternalAgent',
				'vendor'   => 'Meta',
				'color'    => '#f43f5e',
				'category' => 'training',
			),
			array(
				'slug'     => 'facebookbot',
				'label'    => 'FacebookBot',
				'vendor'   => 'Meta',
				'color'    => '#fb7185',
				'category' => 'training',
			),
			// Amazon.
			array(
				'slug'     => 'amazonbot',
				'label'    => 'Amazonbot',
				'vendor'   => 'Amazon',
				'color'    => '#f97316',
				'category' => 'search',
			),
			// ByteDance.
			array(
				'slug'     => 'bytespider',
				'label'    => 'Bytespider',
				'vendor'   => 'ByteDance',
				'color'    => '#84cc16',
				'category' => 'training',
			),
			// Common Crawl.
			array(
				'slug'     => 'ccbot',
				'label'    => 'CCBot',
				'vendor'   => 'Common Crawl',
				'color'    => '#65a30d',
				'category' => 'training',
			),
			// DuckDuckGo.
			array(
				'slug'     => 'duckassistbot',
				'label'    => 'DuckAssistBot',
				'vendor'   => 'DuckDuckGo',
				'color'    => '#fb923c',
				'category' => 'assistant',
			),
			// You.com.
			array(
				'slug'     => 'youbot',
				'label'    => 'YouBot',
				'vendor'   => 'You.com',
				'color'    => '#0891b2',
				'category' => 'search',
			),
			// Mistral.
			array(
				'slug'     => 'mistralai-user',
				'label'    => 'MistralAI-User',
				'vendor'   => 'Mistral',
				'color'    => '#ea580c',
				'category' => 'assistant',
			),
			// Ai2.
			array(
				'slug'     => 'ai2bot',
				'label'    => 'AI2Bot',
				'vendor'   => 'Ai2',
				'color'    => '#4f46e5',
				'category' => 'training',
			),
			// Cohere.
			array(
				'slug'     => 'cohere-ai',
				'label'    => 'Cohere-AI',
				'vendor'   => 'Cohere',
				'color'    => '#7c3aed',
				'category' => 'training',
			),
			// Timpi.
			array(
				'slug'     => 'timpibot',
				'label'    => 'Timpibot',
				'vendor'   => 'Timpi',
				'color'    => '#e11d48',
				'category' => 'search',
			),
		);
		return self::$catalog;
	}

	/**
	 * Match a user agent against the catalog (first hit wins).
	 *
	 * @param string $ua Raw User-Agent header.
	 * @return string|null Bot slug, or null when no AI crawler matched.
	 */
	public static function match( $ua ) {
		$ua = (string) $ua;
		if ( '' === $ua ) {
			return null;
		}
		foreach ( self::catalog() as $entry ) {
			if ( false !== stripos( $ua, $entry['slug'] ) ) {
				return $entry['slug'];
			}
		}
		return null;
	}

	/**
	 * Catalog entry for a slug.
	 *
	 * @param string $slug Bot slug.
	 * @return array|null
	 */
	public static function info( $slug ) {
		$slug = (string) $slug;
		foreach ( self::catalog() as $entry ) {
			if ( $entry['slug'] === $slug ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * Slug to label map.
	 *
	 * @return array<string,string>
	 */
	public static function labels() {
		$labels = array();
		foreach ( self::catalog() as $entry ) {
			$labels[ $entry['slug'] ] = $entry['label'];
		}
		return $labels;
	}
}
