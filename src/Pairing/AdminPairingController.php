<?php
/**
 * Nonce-protected platform settings actions.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Pairing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Handles explicit administrator enable and local disconnect actions. */
final class AdminPairingController {
	public const ENABLE_ACTION     = 'wp_auto_connector_enable_platform';
	public const CONNECT_ACTION    = 'wp_auto_connector_connect_platform';
	public const DISCONNECT_ACTION = 'wp_auto_connector_disconnect_platform';

	/**
	 * Use production repositories by default.
	 *
	 * @param ConnectionSettings|null     $settings   Connection settings.
	 * @param PairingStateRepository|null $state      Pending pairing state.
	 * @param SiteIdentityRepository|null $identities Local signing identity.
	 */
	public function __construct(
		private ?ConnectionSettings $settings = null,
		private ?PairingStateRepository $state = null,
		private ?SiteIdentityRepository $identities = null
	) {
		$this->settings   = $this->settings ?? new ConnectionSettings();
		$this->state      = $this->state ?? new PairingStateRepository();
		$this->identities = $this->identities ?? new SiteIdentityRepository();
	}

	/** Register admin-post actions only; page loads never contact the platform. */
	public function register(): void {
		add_action( 'admin_post_' . self::ENABLE_ACTION, array( $this, 'enable' ) );
		add_action( 'admin_post_' . self::CONNECT_ACTION, array( $this, 'connect' ) );
		add_action( 'admin_post_' . self::DISCONNECT_ACTION, array( $this, 'disconnect' ) );
	}

	/**
	 * Save public platform metadata after capability and nonce checks.
	 *
	 * @throws \RuntimeException When settings cannot be persisted before the redirect boundary.
	 */
	public function enable(): void {
		$this->authorize( self::ENABLE_ACTION );
		try {
			$control_origin  = $this->post_string( 'control_origin' );
			$platform_issuer = $this->post_string( 'platform_issuer' );
			$tenant_id       = $this->post_string( 'tenant_id' );
			if ( ! $this->settings->enable( $control_origin, $platform_issuer, $tenant_id ) ) {
				throw new \RuntimeException( 'settings_write_failed' );
			}
			$this->redirect( 'enabled' );
		} catch ( \Throwable ) {
			$this->redirect( 'invalid' );
		}
	}

	/**
	 * Start pairing only after a second explicit administrator action.
	 *
	 * The verifier travels in the URL fragment. Browsers do not send fragments
	 * in HTTP requests, and the platform start page removes it before posting
	 * the bounded same-origin request with the existing platform session.
	 *
	 * @throws \RuntimeException Only when the WordPress redirect boundary is replaced by a test double.
	 */
	public function connect(): void {
		$this->authorize( self::CONNECT_ACTION );
		try {
			$connection = $this->settings->load();
			if ( null === $connection || 'unpaired' !== $connection['status'] ) {
				throw new \RuntimeException( 'connection_not_ready' );
			}
			$verifier = SiteIdentity::base64url_encode( random_bytes( 32 ) );
			if ( ! $this->state->begin( $verifier, $connection['platform_issuer'], $connection['tenant_id'], $connection['resource'] ) ) {
				throw new \RuntimeException( 'pairing_state_write_failed' );
			}
			if ( ! $this->settings->mark_pending() ) {
				$this->state->cancel();
				throw new \RuntimeException( 'connection_state_write_failed' );
			}
			$payload = SiteIdentity::base64url_encode(
				(string) wp_json_encode(
					array(
						'tenant_id' => $connection['tenant_id'],
						'resource'  => $connection['resource'],
						'verifier'  => $verifier,
					)
				)
			);
			nocache_headers();
			header( 'Referrer-Policy: no-referrer' );
			// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Exact stored destination passed strict HTTPS origin validation.
			wp_redirect( $connection['control_origin'] . '/v1/pairing/start#payload=' . rawurlencode( $payload ), 303, 'WePuu Auto Connector' );
			exit;
		} catch ( \Throwable ) {
			$this->state->cancel();
			$this->redirect( 'pairing_failed' );
		}
	}

	/** Revoke all currently implemented local trust before returning. */
	public function disconnect(): void {
		$this->authorize( self::DISCONNECT_ACTION );
		$this->state->cancel();
		$this->settings->disconnect();
		$this->identities->delete();
		$this->redirect( 'disconnected' );
	}

	/**
	 * Enforce the browser mutation boundary.
	 *
	 * @param string $action Nonce action.
	 */
	private function authorize( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage this connection.', 'wepuu-auto-connector' ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Read one bounded scalar form value.
	 *
	 * @param string $name POST field name.
	 * @throws \InvalidArgumentException When the value is absent or unbounded.
	 */
	private function post_string( string $name ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Verified before this helper; strict validators consume the unsanitized protocol value.
		$value = isset( $_POST[ $name ] ) ? wp_unslash( $_POST[ $name ] ) : '';
		if ( ! is_string( $value ) || strlen( $value ) > 2048 ) {
			throw new \InvalidArgumentException( 'invalid_settings' );
		}
		return trim( $value );
	}

	/**
	 * Return to the local settings page with a bounded status only.
	 *
	 * @param string $status Safe status code.
	 */
	private function redirect( string $status ): void {
		$url = add_query_arg(
			'platform_status',
			$status,
			admin_url( 'options-general.php?page=wepuu-auto-connector' )
		);
		wp_safe_redirect( $url, 303 );
		exit;
	}
}
