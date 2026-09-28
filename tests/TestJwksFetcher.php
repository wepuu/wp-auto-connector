<?php
/**
 * Deterministic test JWKS source.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use WPAuto\Connector\OAuth\JwksFetcherInterface;

/** Supplies one fixed public RSA JWK. */
final class TestJwksFetcher implements JwksFetcherInterface {
	/**
	 * Number of retrievals.
	 *
	 * @var int
	 */
	public int $calls = 0;

	/**
	 * Store the public fixture key.
	 *
	 * @param array<string,mixed> $jwk Public RSA JWK.
	 */
	public function __construct( private array $jwk ) {}

	/**
	 * Replace the authoritative set for rotation/removal tests.
	 *
	 * @param array<string,mixed> $jwk Replacement public RSA JWK.
	 */
	public function replace( array $jwk ): void {
		$this->jwk = $jwk;
	}

	/**
	 * Return the fixture key set.
	 *
	 * @param string $issuer Ignored exact issuer.
	 * @return array{keys:list<array<string,mixed>>}
	 */
	public function fetch( string $issuer ): array {
		unset( $issuer );
		++$this->calls;
		return array( 'keys' => array( $this->jwk ) );
	}
}
