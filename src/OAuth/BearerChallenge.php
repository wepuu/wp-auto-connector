<?php
/**
 * Standards-oriented Bearer challenges for the MCP resource.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\OAuth;

use WPAuto\Connector\Pairing\ConnectionSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Adds challenges only to denied requests for the dedicated MCP route. */
final class BearerChallenge {
	private const MCP_ROUTE = '/wp-auto/mcp';

	/**
	 * Build the response decorator.
	 *
	 * @param ConnectionSettings|null $settings Current connection settings.
	 */
	public function __construct( private ?ConnectionSettings $settings = null ) {
		$this->settings = $this->settings ?? new ConnectionSettings();
	}

	/** Register after REST errors have been converted to responses. */
	public function register(): void {
		add_filter( 'rest_post_dispatch', array( $this, 'filter' ), 900, 3 );
	}

	/**
	 * Add a challenge to an MCP authentication or scope denial.
	 *
	 * @param mixed  $response REST response.
	 * @param object $server   REST server.
	 * @param object $request  REST request.
	 * @return mixed
	 */
	public function filter( $response, object $server, object $request ) {
		unset( $server );
		if ( ! method_exists( $request, 'get_route' ) || self::MCP_ROUTE !== (string) $request->get_route() || ! is_object( $response ) || ! method_exists( $response, 'header' ) ) {
			return $response;
		}
		$status = method_exists( $response, 'get_status' ) ? (int) $response->get_status() : (int) ( $response->status ?? 0 );
		if ( 401 !== $status && 403 !== $status ) {
			return $response;
		}
		$connection = $this->settings->load();
		if ( null === $connection || 'active' !== $connection['status'] ) {
			return $response;
		}
		$data      = $this->response_data( $response );
		$code      = is_string( $data['code'] ?? null ) ? $data['code'] : '';
		$challenge = 'Bearer resource_metadata="' . ProtectedResourceMetadata::metadata_url( $connection['resource'] ) . '"';
		if ( 401 === $status && 'wp_auto_connector_invalid_token' === $code ) {
			$challenge .= ', error="invalid_token"';
		} elseif ( 403 === $status && 'wp_auto_connector_insufficient_scope' === $code ) {
			$challenge .= ', error="insufficient_scope"';
			$required   = $data['data']['required_scope'] ?? null;
			if ( is_string( $required ) && 1 === preg_match( '/\Amcp:[a-z][a-z0-9_.-]{0,63}\z/', $required ) ) {
				$challenge .= ', scope="' . $required . '"';
			}
		} elseif ( 403 === $status ) {
			return $response;
		}
		$response->header( 'WWW-Authenticate', $challenge );
		return $response;
	}

	/**
	 * Read the public WordPress error code without exposing response content.
	 *
	 * @param object $response REST response.
	 */
	private function response_data( object $response ): array {
		$data = method_exists( $response, 'get_data' ) ? $response->get_data() : ( $response->data ?? null );
		return is_array( $data ) ? $data : array();
	}
}
