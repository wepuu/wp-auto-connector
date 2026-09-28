<?php
/**
 * Fixed signed revocation endpoint.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\OAuth;

use WPAuto\Connector\Pairing\ConnectionSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Receives only compact signed control events for an active pairing. */
final class RevocationController {
	public const ROUTE_NAMESPACE = 'wp-auto/v1';
	public const ROUTE           = 'revocations';

	/**
	 * Construct injectable endpoint services.
	 *
	 * @param ConnectionSettings|null        $settings Current pairing settings.
	 * @param RevocationEventVerifier|null   $verifier Signed-event verifier.
	 * @param RevocationStateRepository|null $state    Local deny state.
	 * @param RevocationRateLimiter|null     $limiter  Endpoint limiter.
	 */
	public function __construct( private ?ConnectionSettings $settings = null, private ?RevocationEventVerifier $verifier = null, private ?RevocationStateRepository $state = null, private ?RevocationRateLimiter $limiter = null ) {
		$this->settings = $this->settings ?? new ConnectionSettings();
		$this->verifier = $this->verifier ?? new RevocationEventVerifier( $this->settings );
		$this->state    = $this->state ?? new RevocationStateRepository();
		$this->limiter  = $this->limiter ?? new RevocationRateLimiter();
	}

	/** Register the REST initialization hook. */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
	}

	/** Register the single fixed route. */
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

	/** Require HTTPS and an active explicit pairing. */
	public function permission(): bool {
		$connection = $this->settings->load();
		return is_ssl() && null !== $connection && 'active' === $connection['status'];
	}

	/**
	 * Verify and apply one compact event.
	 *
	 * @param \WP_REST_Request $request Incoming compact JWS request.
	 * @throws \RuntimeException Only when the response boundary is replaced by a test double.
	 */
	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			if ( ! $this->limiter->allow() ) {
				throw new \RuntimeException( 'revocation_denied' );
			}
			$content_type = strtolower( trim( explode( ';', $request->get_header( 'content-type' ) )[0] ) );
			if ( 'application/jwt' !== $content_type ) {
				throw new \RuntimeException( 'revocation_denied' );
			}
			$claims = $this->verifier->verify( $request->get_body() );
			if ( ! $this->state->apply( $claims ) ) {
				throw new \RuntimeException( 'revocation_denied' );
			}
			$response = new \WP_REST_Response( null, 204 );
			$response->header( 'Cache-Control', 'no-store' );
			return $response;
		} catch ( \Throwable ) {
			$response = new \WP_REST_Response( array( 'error' => 'revocation_denied' ), 401 );
			$response->header( 'Cache-Control', 'no-store' );
			return $response;
		}
	}
}
