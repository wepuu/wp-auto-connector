<?php
/**
 * Frozen OAuth scope ceiling for the exact MCP tool catalog.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Maps each existing ability to one and only one approved OAuth scope. */
final class ScopePolicy {
	/**
	 * Exact ability-to-scope mapping.
	 *
	 * @var array<string,string>
	 */
	private const ABILITY_SCOPES = array(
		'wp-auto/site-health'        => 'mcp:read',
		'wp-auto/site-info'          => 'mcp:read',
		'wp-auto/posts-search'       => 'mcp:read',
		'wp-auto/post-get'           => 'mcp:read',
		'wp-auto/pages-search'       => 'mcp:read',
		'wp-auto/page-get'           => 'mcp:read',
		'wp-auto/categories-list'    => 'mcp:read',
		'wp-auto/tags-list'          => 'mcp:read',
		'wp-auto/post-create-draft'  => 'mcp:content.write',
		'wp-auto/page-create-draft'  => 'mcp:content.write',
		'wp-auto/post-update'        => 'mcp:content.write',
		'wp-auto/page-update'        => 'mcp:content.write',
		'wp-auto/media-search'       => 'mcp:read',
		'wp-auto/media-get'          => 'mcp:read',
		'wp-auto/media-upload'       => 'mcp:media.write',
		'wp-auto/media-update'       => 'mcp:media.write',
		'wp-auto/media-set-featured' => 'mcp:media.write',
		'wp-auto/media-import-url'   => 'mcp:media.write',
		'wp-auto/category-create'    => 'mcp:taxonomy.write',
		'wp-auto/tag-create'         => 'mcp:taxonomy.write',
		'wp-auto/taxonomy-assign'    => 'mcp:taxonomy.write',
		'wp-auto/seo-get'            => 'mcp:read',
		'wp-auto/seo-update'         => 'mcp:seo.write',
	);

	/**
	 * Return the exact required scope, or null for abilities outside the catalog.
	 *
	 * @param string $ability_name Exact registered ability name.
	 */
	public function required_scope( string $ability_name ): ?string {
		return self::ABILITY_SCOPES[ $ability_name ] ?? null;
	}

	/**
	 * Return the frozen mapping for validation.
	 *
	 * @return array<string,string>
	 */
	public function mappings(): array {
		return self::ABILITY_SCOPES;
	}
}
