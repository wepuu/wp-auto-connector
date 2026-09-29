<?php
/**
 * Request-scoped MCP Bearer authentication bridge.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\OAuth;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Authenticates Bearer only on the dedicated MCP REST route. */
final class BearerAuthenticator {
	private const MCP_ROUTE = '/wp-auto/mcp';

	/**
	 * WordPress identity that must be restored after dispatch.
	 *
	 * @var int|null
	 */
	private ?int $previous_user_id = null;

	/**
	 * Build the request bridge.
	 *
	 * @param BearerRequestContext      $context   Shared request identity.
	 * @param BearerTokenExtractor|null $extractor Strict header parser.
	 * @param AccessTokenVerifier|null  $verifier  Signed-token verifier.
	 */
	public function __construct(
		private BearerRequestContext $context,
		private ?BearerTokenExtractor $extractor = null,
		private ?AccessTokenVerifier $verifier = null
	) {
		$this->extractor = $this->extractor ?? new BearerTokenExtractor();
		$this->verifier  = $this->verifier ?? new AccessTokenVerifier();
	}

	/** Register the narrow REST request lifecycle hooks. */
	public function register(): void {
		add_filter( 'rest_pre_dispatch', array( $this, 'authenticate' ), 5, 3 );
		add_filter( 'rest_post_dispatch', array( $this, 'restore' ), 999, 3 );
	}

	/**
	 * Establish a verified local user before MCP transport permission callbacks.
	 *
	 * @param mixed  $result  Prior pre-dispatch result.
	 * @param object $server  REST server.
	 * @param object $request REST request.
	 * @return mixed
	 * @throws \RuntimeException Never escapes; converted to a generic WP_Error.
	 */
	public function authenticate( $result, object $server, object $request ) {
		unset( $server );
		$this->context->clear();
		$this->previous_user_id = null;
		if ( null !== $result || ! $this->is_mcp_request( $request ) ) {
			return $result;
		}

		try {
			$authorization = method_exists( $request, 'get_header' ) ? (string) $request->get_header( 'authorization' ) : '';
			$token         = $this->extractor->extract( $authorization );
			if ( null === $token ) {
				return $result;
			}
			$identity               = $this->verifier->verify( $token );
			$this->previous_user_id = get_current_user_id();
			wp_set_current_user( $identity['user_id'] );
			if ( get_current_user_id() !== $identity['user_id'] ) {
				throw new \RuntimeException( 'local_user_unavailable' );
			}
			$this->context->install( $identity );
			return $result;
		} catch ( \Throwable ) {
			$this->restore_user();
			return new WP_Error(
				'wp_auto_connector_invalid_token',
				__( 'The OAuth access token is invalid or no longer authorized.', 'wepuu-auto-connector' ),
				array(
					'status'      => 401,
					'oauth_error' => 'invalid_token',
				)
			);
		}
	}

	/**
	 * Restore the previous WordPress identity after the exact MCP request.
	 *
	 * @param mixed  $response REST response.
	 * @param object $server   REST server.
	 * @param object $request  REST request.
	 * @return mixed
	 */
	public function restore( $response, object $server, object $request ) {
		unset( $server );
		if ( $this->is_mcp_request( $request ) ) {
			$this->restore_user();
		}
		return $response;
	}

	/** Clear Bearer state and restore the pre-dispatch WordPress user. */
	private function restore_user(): void {
		$this->context->clear();
		if ( null !== $this->previous_user_id ) {
			wp_set_current_user( $this->previous_user_id );
			$this->previous_user_id = null;
		}
	}

	/**
	 * Match only the dedicated MCP REST route.
	 *
	 * @param object $request REST request.
	 */
	private function is_mcp_request( object $request ): bool {
		return method_exists( $request, 'get_route' ) && self::MCP_ROUTE === (string) $request->get_route();
	}
}
