<?php
/**
 * Request-local OAuth Bearer identity.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Keeps verified Bearer state in memory for the current MCP request only. */
final class BearerRequestContext {
	/**
	 * Verified identity, or null outside an authenticated Bearer request.
	 *
	 * @var array{user_id:int,grant_id:string,client_id:string,subject_id:string,jti:string,scopes:list<string>}|null
	 */
	private ?array $identity = null;

	/**
	 * Install a verified request identity.
	 *
	 * @param array{user_id:int,grant_id:string,client_id:string,subject_id:string,jti:string,scopes:list<string>} $identity Verified identity.
	 */
	public function install( array $identity ): void {
		$this->identity = $identity;
	}

	/** Clear all request-local identity state. */
	public function clear(): void {
		$this->identity = null;
	}

	/** Whether the current request has a verified Bearer identity. */
	public function authenticated(): bool {
		return null !== $this->identity;
	}

	/** Return the verified local WordPress user ID, or zero. */
	public function user_id(): int {
		return $this->identity['user_id'] ?? 0;
	}

	/**
	 * Whether the verified token contains an exact scope.
	 *
	 * @param string $scope Exact OAuth scope.
	 */
	public function allows( string $scope ): bool {
		return null !== $this->identity && in_array( $scope, $this->identity['scopes'], true );
	}

	/**
	 * Return the content-free identity for tests and authorization policy.
	 *
	 * @return array{user_id:int,grant_id:string,client_id:string,subject_id:string,jti:string,scopes:list<string>}|null
	 */
	public function identity(): ?array {
		return $this->identity;
	}
}
