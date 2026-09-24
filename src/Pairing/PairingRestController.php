<?php
/**
 * Bounded public pairing proof endpoint.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Pairing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers the fixed verifier-protected pairing proof route. */
final class PairingRestController {
	public const ROUTE_NAMESPACE = 'wp-auto/v1';
	public const ROUTE           = 'pairing/proof';

	/**
	 * Use injectable services for unit and integration tests.
	 *
	 * @param ConnectionSettings|null     $settings   Connection settings.
	 * @param PairingStateRepository|null $state      Pending pairing state.
	 * @param SiteIdentityRepository|null $identities Site identity storage.
	 * @param SiteProofSigner|null        $signer     Proof signer.
	 */
	public function __construct(
		private ?ConnectionSettings $settings = null,
		private ?PairingStateRepository $state = null,
		private ?SiteIdentityRepository $identities = null,
		private ?SiteProofSigner $signer = null
	) {
		$this->settings   = $this->settings ?? new ConnectionSettings();
		$this->state      = $this->state ?? new PairingStateRepository();
		$this->identities = $this->identities ?? new SiteIdentityRepository();
		$this->signer     = $this->signer ?? new SiteProofSigner();
	}

	/** Register WordPress hooks. */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
	}

	/** Register the exact route without exposing other plugin APIs. */
	public function register_route(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/' . self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
	}

	/** The public verifier endpoint exists only after explicit local enablement. */
	public function permission(): bool {
		$connection = $this->settings->load();
		return is_ssl() && null !== $connection && 'pending' === $connection['status'];
	}

	/**
	 * Consume the verifier and return one short-lived public proof.
	 *
	 * @param \WP_REST_Request $request WordPress REST request.
	 * @throws \RuntimeException Only when the response boundary is replaced by a test double.
	 */
	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$content_type = strtolower( trim( explode( ';', $request->get_header( 'content-type' ) )[0] ) );
			$params       = $request->get_json_params();
			if ( 'application/json' !== $content_type || ! is_array( $params ) ) {
				return $this->denied_response();
			}
			$validated = $this->state->consume( $params );
			$identity  = $this->identities->get_or_create();
			if ( ! $this->settings->mark_active( $validated['site_id'], $identity->kid(), $validated['platform_signing_key_pem'], $validated['platform_signing_kid'] ) ) {
				throw new \RuntimeException( 'connection_activation_failed' );
			}
			$proof    = $this->signer->sign_pairing( $identity, $validated );
			$response = new \WP_REST_Response(
				array(
					'proof'     => $proof,
					'publicJwk' => $identity->public_jwk(),
				),
				200
			);
			$response->header( 'Cache-Control', 'no-store' );
			return $response;
		} catch ( \Throwable ) {
			return $this->denied_response();
		}
	}

	/** Return one non-disclosing fail-closed response. */
	private function denied_response(): \WP_REST_Response {
		$response = new \WP_REST_Response( array( 'error' => 'pairing_denied' ), 401 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
