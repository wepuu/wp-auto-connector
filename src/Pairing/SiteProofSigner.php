<?php
/**
 * Site proof signing through the reviewed JOSE library.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Pairing;

use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Eddsa;
use Lcobucci\JWT\Signer\Key\InMemory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Produces short-lived EdDSA pairing and consent proofs. */
final class SiteProofSigner {
	private const PROOF_TTL_SECONDS = 60;

	/**
	 * Sign a pairing proof with the site-local key.
	 *
	 * @param SiteIdentity                                                                                                                                                                         $identity Site-local identity.
	 * @param array{tenant_id:string,pairing_attempt_id:string,site_id:string,challenge:string,platform_issuer:string,platform_signing_key_pem:string,platform_signing_kid:string,resource:string} $request  Validated request.
	 * @param \DateTimeImmutable|null                                                                                                                                                              $now      Optional clock for tests.
	 */
	public function sign_pairing( SiteIdentity $identity, array $request, ?\DateTimeImmutable $now = null ): string {
		$issued_at = $this->whole_second( $now ?? new \DateTimeImmutable() );
		$config    = Configuration::forAsymmetricSigner(
			new Eddsa(),
			InMemory::plainText( $identity->secret_key() ),
			InMemory::plainText( $identity->public_key() )
		);

		return $config->builder()
			->withHeader( 'typ', 'wepuu-site-proof+jwt' )
			->withHeader( 'kid', $identity->kid() )
			->withClaim( 'kind', 'pairing' )
			->withClaim( 'protocol_version', '1' )
			->issuedBy( CanonicalResource::hostname( $request['resource'] ) )
			->withClaim( 'platform_issuer', $request['platform_issuer'] )
			->withClaim( 'tenant_id', $request['tenant_id'] )
			->withClaim( 'pairing_attempt_id', $request['pairing_attempt_id'] )
			->withClaim( 'site_id', $request['site_id'] )
			->withClaim( 'platform_signing_key_sha256', SiteIdentity::base64url_encode( hash( 'sha256', $request['platform_signing_key_pem'], true ) ) )
			->withClaim( 'platform_signing_kid', $request['platform_signing_kid'] )
			->withClaim( 'resource', $request['resource'] )
			->withClaim( 'challenge', $request['challenge'] )
			->issuedAt( $issued_at )
			->expiresAt( $issued_at->modify( '+' . self::PROOF_TTL_SECONDS . ' seconds' ) )
			->getToken( $config->signer(), $config->signingKey() )
			->toString();
	}

	/**
	 * Sign a local-user consent proof without exposing the WordPress user ID.
	 *
	 * @param SiteIdentity                                                                                                                                                                          $identity Site-local identity.
	 * @param array{tenant_id:string,site_id:string,grant_id:string,subject_id:string,client_id:string,scopes:list<string>,challenge:string,platform_issuer:string,resource:string,decision:string} $request  Validated consent request.
	 * @param \DateTimeImmutable|null                                                                                                                                                               $now      Optional clock for tests.
	 */
	public function sign_consent( SiteIdentity $identity, array $request, ?\DateTimeImmutable $now = null ): string {
		$issued_at = $this->whole_second( $now ?? new \DateTimeImmutable() );
		$config    = Configuration::forAsymmetricSigner(
			new Eddsa(),
			InMemory::plainText( $identity->secret_key() ),
			InMemory::plainText( $identity->public_key() )
		);

		return $config->builder()
			->withHeader( 'typ', 'wepuu-site-proof+jwt' )
			->withHeader( 'kid', $identity->kid() )
			->withClaim( 'kind', 'consent' )
			->withClaim( 'protocol_version', '1' )
			->issuedBy( CanonicalResource::hostname( $request['resource'] ) )
			->withClaim( 'platform_issuer', $request['platform_issuer'] )
			->withClaim( 'tenant_id', $request['tenant_id'] )
			->withClaim( 'site_id', $request['site_id'] )
			->withClaim( 'grant_id', $request['grant_id'] )
			->withClaim( 'subject_id', $request['subject_id'] )
			->withClaim( 'client_id', $request['client_id'] )
			->permittedFor( $request['resource'] )
			->withClaim( 'scope', $request['scopes'] )
			->withClaim( 'decision', $request['decision'] )
			->withClaim( 'resource', $request['resource'] )
			->withClaim( 'challenge', $request['challenge'] )
			->issuedAt( $issued_at )
			->expiresAt( $issued_at->modify( '+' . self::PROOF_TTL_SECONDS . ' seconds' ) )
			->getToken( $config->signer(), $config->signingKey() )
			->toString();
	}

	/**
	 * Normalize JOSE NumericDate claims to whole Unix seconds.
	 *
	 * @param \DateTimeImmutable $value Candidate timestamp.
	 */
	private function whole_second( \DateTimeImmutable $value ): \DateTimeImmutable {
		return $value->setTime(
			(int) $value->format( 'H' ),
			(int) $value->format( 'i' ),
			(int) $value->format( 's' ),
			0
		);
	}
}
