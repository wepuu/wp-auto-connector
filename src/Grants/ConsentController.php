<?php
/**
 * WordPress-local consent ceremony.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Grants;

use WPAuto\Connector\Pairing\ConnectionSettings;
use WPAuto\Connector\Pairing\SiteIdentity;
use WPAuto\Connector\Pairing\SiteIdentityRepository;
use WPAuto\Connector\Pairing\SiteProofSigner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Keeps the signed request in the browser fragment until local authentication. */
final class ConsentController {
	private const PAGE_SLUG = 'wp-auto-connector-consent';
	/**
	 * Signed-request verifier.
	 *
	 * @var ConsentRequestVerifier
	 */
	private ConsentRequestVerifier $verifier;
	/**
	 * One-shot pending consent storage.
	 *
	 * @var PendingConsentRepository
	 */
	private PendingConsentRepository $pending;
	/**
	 * Active local grant storage.
	 *
	 * @var LocalGrantRepository
	 */
	private LocalGrantRepository $grants;
	/**
	 * Paired connection settings.
	 *
	 * @var ConnectionSettings
	 */
	private ConnectionSettings $settings;
	/**
	 * Site identity storage.
	 *
	 * @var SiteIdentityRepository
	 */
	private SiteIdentityRepository $identities;
	/**
	 * Site proof signer.
	 *
	 * @var SiteProofSigner
	 */
	private SiteProofSigner $signer;

	/**
	 * Construct the consent controller with injectable services.
	 *
	 * @param ConsentRequestVerifier|null   $verifier   Signed-request verifier.
	 * @param PendingConsentRepository|null $pending    Pending state storage.
	 * @param LocalGrantRepository|null     $grants     Local grant storage.
	 * @param ConnectionSettings|null       $settings   Paired settings.
	 * @param SiteIdentityRepository|null   $identities Site identity storage.
	 * @param SiteProofSigner|null          $signer     Site proof signer.
	 */
	public function __construct(
		?ConsentRequestVerifier $verifier = null,
		?PendingConsentRepository $pending = null,
		?LocalGrantRepository $grants = null,
		?ConnectionSettings $settings = null,
		?SiteIdentityRepository $identities = null,
		?SiteProofSigner $signer = null
	) {
		$this->settings   = $settings ?? new ConnectionSettings();
		$this->verifier   = $verifier ?? new ConsentRequestVerifier( $this->settings );
		$this->pending    = $pending ?? new PendingConsentRepository();
		$this->grants     = $grants ?? new LocalGrantRepository();
		$this->identities = $identities ?? new SiteIdentityRepository();
		$this->signer     = $signer ?? new SiteProofSigner();
	}

	/** Register consent hooks. */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'protect_consent_page_headers' ), 1000 );
		add_action( 'admin_post_wp_auto_connector_consent_start', array( $this, 'bootstrap' ) );
		add_action( 'admin_post_nopriv_wp_auto_connector_consent_start', array( $this, 'bootstrap' ) );
		add_action( 'admin_post_wp_auto_connector_consent_preview', array( $this, 'preview' ) );
		add_action( 'admin_post_wp_auto_connector_consent_approve', array( $this, 'approve' ) );
		add_action( 'admin_post_wp_auto_connector_consent_deny', array( $this, 'deny' ) );
		add_action( 'admin_menu', array( $this, 'register_page' ) );
	}

	/** Prevent the one-time consent handle from entering same-origin Referer logs. */
	public function protect_consent_page_headers(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page selection used only to harden response headers.
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::PAGE_SLUG !== $page ) {
			return;
		}
		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
	}

	/** Register the hidden, authenticated consent page. */
	public function register_page(): void {
		add_submenu_page( null, __( 'WePuu consent', 'wepuu-auto-connector' ), __( 'WePuu consent', 'wepuu-auto-connector' ), 'read', self::PAGE_SLUG, array( $this, 'render_page' ) );
	}

	/** Render the fragment bootstrap; the signed JWT never enters a URL query. */
	public function bootstrap(): void {
		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
		$logged_in = is_user_logged_in();
		$preview   = admin_url( 'admin-post.php?action=wp_auto_connector_consent_preview' );
		$login     = wp_login_url( admin_url( 'admin-post.php?action=wp_auto_connector_consent_start' ) );
		$nonce     = $logged_in ? wp_create_nonce( 'wp_auto_connector_consent_preview' ) : '';
		$config    = wp_json_encode(
			array(
				'loggedIn' => $logged_in,
				'preview'  => $preview,
				'login'    => $login,
				'nonce'    => $nonce,
			)
		);
		$script    = "'use strict';const c=" . $config . ";const k='wepuu.consent.request';const p=new URLSearchParams(location.hash.slice(1));let r=p.get('request')||sessionStorage.getItem(k);history.replaceState(null,'',location.pathname+location.search);if(!r||r.length>16384){document.body.textContent='Invalid consent request.';}else if(!c.loggedIn){sessionStorage.setItem(k,r);location.replace(c.login);}else{sessionStorage.removeItem(k);const f=document.createElement('form');f.method='post';f.action=c.preview;for(const [n,v] of Object.entries({request:r,_wpnonce:c.nonce})){const i=document.createElement('input');i.type='hidden';i.name=n;i.value=v;f.appendChild(i);}document.body.appendChild(f);f.submit();}";
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- CSP SHA-256 source encoding.
		$hash = base64_encode( hash( 'sha256', $script, true ) );
		header( "Content-Security-Policy: default-src 'none'; script-src 'sha256-{$hash}'; base-uri 'none'; form-action 'self'" );
		echo '<!doctype html><html><head><meta charset="utf-8"><title>' . esc_html__( 'WePuu consent', 'wepuu-auto-connector' ) . '</title></head><body><p>' . esc_html__( 'Preparing secure consent…', 'wepuu-auto-connector' ) . '</p><script>' . $script . '</script></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Script values are JSON encoded and CSP hash-bound.
		exit;
	}

	/** Verify the signed request and convert it to one-time local state. */
	public function preview(): void {
		$this->require_user();
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'wp_auto_connector_consent_preview' ) ) {
			wp_die( esc_html__( 'Invalid consent session.', 'wepuu-auto-connector' ), '', array( 'response' => 403 ) );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Compact JWT must remain byte exact and is verified below.
		$request = isset( $_POST['request'] ) ? wp_unslash( $_POST['request'] ) : '';
		try {
			$claims = $this->verifier->verify( is_string( $request ) ? $request : '' );
			$handle = $this->pending->create( $claims, get_current_user_id() );
		} catch ( \Throwable ) {
			wp_die( esc_html__( 'The consent request is invalid or expired.', 'wepuu-auto-connector' ), '', array( 'response' => 400 ) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&consent=' . rawurlencode( $handle ) ), 303 );
		exit;
	}

	/** Render the authenticated local decision page. */
	public function render_page(): void {
		$this->require_user();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page; decision POSTs use distinct nonces.
		$handle = isset( $_GET['consent'] ) ? sanitize_text_field( wp_unslash( $_GET['consent'] ) ) : '';
		$claims = $this->pending->find( $handle, get_current_user_id() );
		if ( null === $claims ) {
			wp_die( esc_html__( 'The consent request is invalid or expired.', 'wepuu-auto-connector' ), '', array( 'response' => 400 ) );
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Approve WePuu access', 'wepuu-auto-connector' ) . '</h1>';
		echo '<p><strong>' . esc_html__( 'Client:', 'wepuu-auto-connector' ) . '</strong> ' . esc_html( $claims['client_id'] ) . '</p><ul>';
		foreach ( $claims['scopes'] as $scope ) {
			echo '<li><code>' . esc_html( $scope ) . '</code></li>';
		}
		echo '</ul>';
		$this->decision_form( 'wp_auto_connector_consent_approve', __( 'Approve', 'wepuu-auto-connector' ), $handle, 'button button-primary' );
		$this->decision_form( 'wp_auto_connector_consent_deny', __( 'Deny', 'wepuu-auto-connector' ), $handle, 'button' );
		echo '</div>';
	}

	/** Approve the displayed request. */
	public function approve(): void {
		$this->decide( 'approved' );
	}

	/** Deny the displayed request. */
	public function deny(): void {
		$this->decide( 'denied' );
	}

	/**
	 * Finish one approved or denied request exactly once.
	 *
	 * @param string $decision Exact decision value.
	 * @throws \RuntimeException When local state is inconsistent.
	 */
	private function decide( string $decision ): void {
		$this->require_user();
		$handle       = isset( $_POST['consent'] ) ? sanitize_text_field( wp_unslash( $_POST['consent'] ) ) : '';
		$nonce_action = 'approved' === $decision ? 'wp_auto_connector_consent_approve' : 'wp_auto_connector_consent_deny';
		check_admin_referer( $nonce_action, '_wpnonce' );
		try {
			$claims   = $this->pending->consume( $handle, get_current_user_id() );
			$identity = $this->identities->load();
			if ( ! $identity instanceof SiteIdentity ) {
				throw new \RuntimeException( 'connection_not_active' );
			}
			$connection = $this->verifier->require_current_binding( $claims, $identity );
			$proof      = $this->signer->sign_consent( $identity, array_merge( $claims, array( 'decision' => $decision ) ) );
			if ( 'approved' === $decision ) {
				$this->grants->activate( $claims['grant_id'], get_current_user_id(), $claims['site_id'], $claims['client_id'], $claims['scopes'], $claims['resource'], $identity->kid() );
			}
			$result = array(
				'tenant_id'       => $claims['tenant_id'],
				'grant_id'        => $claims['grant_id'],
				'proof'           => $proof,
				'challenge'       => $claims['challenge'],
				'decision'        => $decision,
				'idempotency_key' => SiteIdentity::base64url_encode( random_bytes( 32 ) ),
			);
			$target = $connection['control_origin'] . '/v1/consent/complete#result=' . SiteIdentity::base64url_encode( (string) wp_json_encode( $result ) );
		} catch ( \Throwable ) {
			wp_die( esc_html__( 'Consent could not be completed.', 'wepuu-auto-connector' ), '', array( 'response' => 400 ) );
		}
		wp_redirect( $target, 303, 'WePuu Auto Connector' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Exact paired HTTPS origin is validated before storage.
		exit;
	}

	/**
	 * Render one nonce-protected decision form.
	 *
	 * @param string $action    WordPress action name.
	 * @param string $label     Button label.
	 * @param string $handle    Opaque state handle.
	 * @param string $css_class Button CSS class.
	 */
	private function decision_form( string $action, string $label, string $handle, string $css_class ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:8px">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '"><input type="hidden" name="consent" value="' . esc_attr( $handle ) . '">';
		wp_nonce_field( $action, '_wpnonce' );
		echo '<button type="submit" class="' . esc_attr( $css_class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	/** Require an authenticated local user with the baseline read capability. */
	private function require_user(): void {
		if ( ! is_user_logged_in() || ! current_user_can( 'read' ) || 1 > get_current_user_id() ) {
			wp_die( esc_html__( 'Authentication is required.', 'wepuu-auto-connector' ), '', array( 'response' => 403 ) );
		}
	}
}
