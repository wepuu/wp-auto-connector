<?php
/**
 * WordPress-local opaque grant mapping.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Grants;

use WPAuto\Connector\Pairing\CanonicalResource;
use WPAuto\Connector\Pairing\ConnectionSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores each grant in an independent non-autoloaded option.
 *
 * Security lookup never depends on a lossy list or capability snapshot.
 */
final class LocalGrantRepository {
	public const OPTION_PREFIX = 'wp_auto_connector_grant_';

	/**
	 * Resolves current local WordPress user state.
	 *
	 * @var LocalUserResolverInterface
	 */
	private LocalUserResolverInterface $users;

	/**
	 * Use current WordPress user state by default.
	 *
	 * @param LocalUserResolverInterface|null $users Local-user resolver.
	 */
	public function __construct( ?LocalUserResolverInterface $users = null ) {
		$this->users = $users ?? new WordPressLocalUserResolver();
	}

	/**
	 * Create one immutable local grant mapping.
	 *
	 * @param string        $grant_id     Opaque platform grant ID.
	 * @param int           $user_id      Local WordPress user ID.
	 * @param string        $site_id      Opaque platform site ID.
	 * @param string        $client_id    OAuth client ID.
	 * @param array<string> $scopes      Exact approved scopes.
	 * @param string        $resource_url Exact MCP resource.
	 * @param string        $site_key_kid Site key that approved the grant.
	 * @param int|null      $now          Optional clock for tests.
	 * @throws \RuntimeException When a conflicting mapping already exists.
	 */
	public function activate(
		string $grant_id,
		int $user_id,
		string $site_id,
		string $client_id,
		array $scopes,
		string $resource_url,
		string $site_key_kid,
		?int $now = null
	): bool {
		$record = array(
			'version'      => '1',
			'grant_id'     => self::opaque_id( $grant_id ),
			'user_id'      => self::positive_user_id( $user_id ),
			'site_id'      => self::opaque_id( $site_id ),
			'client_id'    => self::client_id( $client_id ),
			'scopes'       => self::scopes( $scopes ),
			'resource'     => CanonicalResource::validate( $resource_url ),
			'site_key_kid' => self::site_key_kid( $site_key_kid ),
			'created_at'   => $now ?? time(),
		);
		$option = self::option_name( $grant_id );
		if ( add_option( $option, $record, '', false ) ) {
			return true;
		}

		$existing = get_option( $option, null );
		if ( is_array( $existing ) && hash_equals( self::immutable_digest( $existing ), self::immutable_digest( $record ) ) ) {
			return true;
		}
		throw new \RuntimeException( 'grant_conflict' );
	}

	/**
	 * Resolve only an active mapping bound to the current paired site and key.
	 *
	 * @param string             $grant_id  Opaque grant ID.
	 * @param ConnectionSettings $settings  Current connection settings.
	 * @return array{grant_id:string,user_id:int,site_id:string,client_id:string,scopes:list<string>,resource:string,site_key_kid:string,created_at:int}|null
	 */
	public function find_active( string $grant_id, ConnectionSettings $settings ): ?array {
		try {
			$grant_id = self::opaque_id( $grant_id );
		} catch ( \InvalidArgumentException ) {
			return null;
		}
		$record     = get_option( self::option_name( $grant_id ), null );
		$connection = $settings->load();
		if ( ! is_array( $record ) || null === $connection || 'active' !== $connection['status'] ) {
			return null;
		}

		try {
			$validated = self::validate_record( $record );
		} catch ( \InvalidArgumentException ) {
			return null;
		}
		if (
			! hash_equals( $grant_id, $validated['grant_id'] )
			|| ! $this->users->exists( $validated['user_id'] )
			|| ! isset( $connection['site_id'], $connection['site_key_kid'] )
			|| ! hash_equals( $connection['site_id'], $validated['site_id'] )
			|| ! hash_equals( $connection['site_key_kid'], $validated['site_key_kid'] )
			|| ! hash_equals( $connection['resource'], $validated['resource'] )
		) {
			return null;
		}

		return $validated;
	}

	/**
	 * Revoke locally by deleting the authoritative mapping.
	 *
	 * @param string $grant_id Opaque grant ID.
	 */
	public function revoke( string $grant_id ): bool {
		try {
			$option = self::option_name( self::opaque_id( $grant_id ) );
		} catch ( \InvalidArgumentException ) {
			return false;
		}
		$sentinel = new \stdClass();
		delete_option( $option );
		return get_option( $option, $sentinel ) === $sentinel;
	}

	/**
	 * Create the collision-resistant fixed-family option name.
	 *
	 * @param string $grant_id Opaque grant identifier.
	 */
	public static function option_name( string $grant_id ): string {
		return self::OPTION_PREFIX . hash( 'sha256', $grant_id );
	}

	/**
	 * Validate one stored record.
	 *
	 * @param array<string,mixed> $record Candidate record.
	 * @return array{grant_id:string,user_id:int,site_id:string,client_id:string,scopes:list<string>,resource:string,site_key_kid:string,created_at:int}
	 * @throws \InvalidArgumentException When the record is malformed.
	 */
	private static function validate_record( array $record ): array {
		if ( '1' !== ( $record['version'] ?? null ) || ! is_int( $record['created_at'] ?? null ) || $record['created_at'] < 0 ) {
			throw new \InvalidArgumentException( 'invalid_grant_record' );
		}
		return array(
			'grant_id'     => self::opaque_id( (string) ( $record['grant_id'] ?? '' ) ),
			'user_id'      => self::positive_user_id( (int) ( $record['user_id'] ?? 0 ) ),
			'site_id'      => self::opaque_id( (string) ( $record['site_id'] ?? '' ) ),
			'client_id'    => self::client_id( (string) ( $record['client_id'] ?? '' ) ),
			'scopes'       => self::scopes( is_array( $record['scopes'] ?? null ) ? $record['scopes'] : array() ),
			'resource'     => CanonicalResource::validate( (string) ( $record['resource'] ?? '' ) ),
			'site_key_kid' => self::site_key_kid( (string) ( $record['site_key_kid'] ?? '' ) ),
			'created_at'   => $record['created_at'],
		);
	}

	/**
	 * Hash the immutable portion of a grant record.
	 *
	 * @param array<string,mixed> $record Immutable record.
	 */
	private static function immutable_digest( array $record ): string {
		unset( $record['created_at'] );
		return hash( 'sha256', (string) wp_json_encode( $record ) );
	}

	/**
	 * Validate one opaque identifier.
	 *
	 * @param string $value Candidate identifier.
	 * @throws \InvalidArgumentException When the identifier is malformed.
	 */
	private static function opaque_id( string $value ): string {
		if ( 1 !== preg_match( '/\A[A-Za-z0-9_-]{8,128}\z/', $value ) ) {
			throw new \InvalidArgumentException( 'invalid_identifier' );
		}
		return $value;
	}

	/**
	 * Validate a positive local user identifier.
	 *
	 * @param int $value Candidate user identifier.
	 * @throws \InvalidArgumentException When the identifier is invalid.
	 */
	private static function positive_user_id( int $value ): int {
		if ( 1 > $value ) {
			throw new \InvalidArgumentException( 'invalid_user' );
		}
		return $value;
	}

	/**
	 * Validate an OAuth client identifier.
	 *
	 * @param string $value Candidate client identifier.
	 * @throws \InvalidArgumentException When the identifier is malformed.
	 */
	private static function client_id( string $value ): string {
		if ( 1 !== preg_match( '/\A[A-Za-z0-9._~-]{8,256}\z/', $value ) ) {
			throw new \InvalidArgumentException( 'invalid_client' );
		}
		return $value;
	}

	/**
	 * Validate and de-duplicate the exact scope set.
	 *
	 * @param array<mixed> $values Candidate scopes.
	 * @return list<string>
	 * @throws \InvalidArgumentException When a scope is malformed or duplicated.
	 */
	private static function scopes( array $values ): array {
		if ( count( $values ) < 1 || count( $values ) > 16 ) {
			throw new \InvalidArgumentException( 'invalid_scope' );
		}
		$scopes = array();
		foreach ( $values as $scope ) {
			if ( ! is_string( $scope ) || 1 !== preg_match( '/\Amcp:[a-z][a-z0-9_.-]{0,63}\z/', $scope ) || isset( $scopes[ $scope ] ) ) {
				throw new \InvalidArgumentException( 'invalid_scope' );
			}
			$scopes[ $scope ] = true;
		}
		return array_keys( $scopes );
	}

	/**
	 * Validate a site signing-key identifier.
	 *
	 * @param string $value Candidate key identifier.
	 * @throws \InvalidArgumentException When the identifier is malformed.
	 */
	private static function site_key_kid( string $value ): string {
		if ( 1 !== preg_match( '/\Asite_[A-Za-z0-9_-]{22}\z/', $value ) ) {
			throw new \InvalidArgumentException( 'invalid_site_key' );
		}
		return $value;
	}
}
