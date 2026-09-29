<?php
/**
 * RFC 9728 metadata for the canonical MCP resource.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\OAuth;

use WPAuto\Connector\Pairing\ConnectionSettings;
use WPAuto\Connector\Pairing\CanonicalResource;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Publishes path-specific metadata without proxying any MCP traffic. */
final class ProtectedResourceMetadata {
	public const QUERY_VAR        = 'wp_auto_connector_prm';
	public const VERSION_OPTION   = 'wp_auto_connector_prm_rewrite_version';
	private const PATH_PREFIX     = '.well-known/oauth-protected-resource';
	private const REWRITE_VERSION = '1';
	private const SCOPES          = array(
		'mcp:read',
		'mcp:content.write',
		'mcp:media.write',
		'mcp:taxonomy.write',
		'mcp:seo.write',
	);

	/**
	 * Build the metadata publisher.
	 *
	 * @param ConnectionSettings|null $settings Current connection settings.
	 */
	public function __construct( private ?ConnectionSettings $settings = null ) {
		$this->settings = $this->settings ?? new ConnectionSettings();
	}

	/** Register the exact well-known resource route. */
	public function register(): void {
		add_action( 'init', array( self::class, 'register_rewrite' ), 5 );
		add_action( 'init', array( self::class, 'ensure_rewrite_version' ), 100 );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'template_redirect', array( $this, 'serve' ) );
	}

	/** Register the rewrite rule; public for activation-time flushing. */
	public static function register_rewrite(): void {
		add_rewrite_rule( '^' . self::PATH_PREFIX . '(?:/.+)?/wp-json/wp-auto/mcp/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
	}

	/** Persist the current rule on activation. */
	public static function activate_rewrite(): void {
		self::register_rewrite();
		flush_rewrite_rules( false );
		update_option( self::VERSION_OPTION, self::REWRITE_VERSION, false );
	}

	/** Flush once after an in-place plugin update changes the rule contract. */
	public static function ensure_rewrite_version(): void {
		if ( self::REWRITE_VERSION === get_option( self::VERSION_OPTION, '' ) ) {
			return;
		}
		flush_rewrite_rules( false );
		update_option( self::VERSION_OPTION, self::REWRITE_VERSION, false );
	}

	/** Remove the persisted marker and rule on deactivation. */
	public static function deactivate_rewrite(): void {
		delete_option( self::VERSION_OPTION );
		flush_rewrite_rules( false );
	}

	/**
	 * Admit the one private rewrite query variable.
	 *
	 * @param array<string> $variables Public query variables.
	 * @return list<string>
	 */
	public function query_vars( array $variables ): array {
		$variables[] = self::QUERY_VAR;
		return array_values( array_unique( $variables ) );
	}

	/**
	 * Return the absolute metadata URI for an exact canonical resource.
	 *
	 * @param string $resource_uri Exact canonical MCP URI.
	 * @throws \RuntimeException When the resource is not the frozen HTTPS path.
	 */
	public static function metadata_url( string $resource_uri ): string {
		try {
			$resource_uri = CanonicalResource::validate( $resource_uri );
		} catch ( \InvalidArgumentException ) {
			throw new \RuntimeException( 'invalid_resource' );
		}
		$parts  = wp_parse_url( $resource_uri );
		$origin = 'https://' . strtolower( $parts['host'] );
		return $origin . '/' . self::PATH_PREFIX . $parts['path'];
	}

	/**
	 * Return the public document only for an active exact pairing.
	 *
	 * @return array{resource:string,authorization_servers:list<string>,scopes_supported:list<string>,bearer_methods_supported:list<string>}|null
	 */
	public function document(): ?array {
		$connection = $this->settings->load();
		if ( null === $connection || 'active' !== $connection['status'] ) {
			return null;
		}
		return array(
			'resource'                 => $connection['resource'],
			'authorization_servers'    => array( $connection['platform_issuer'] ),
			'scopes_supported'         => self::SCOPES,
			'bearer_methods_supported' => array( 'header' ),
		);
	}

	/** Serve the content-free metadata response and stop template rendering. */
	public function serve(): void {
		if ( '1' !== (string) get_query_var( self::QUERY_VAR, '' ) ) {
			return;
		}
		$document = $this->document();
		if ( null === $document ) {
			status_header( 404 );
			exit;
		}
		status_header( 200 );
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Cache-Control: public, max-age=300' );
		echo wp_json_encode( $document, JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response.
		exit;
	}
}
