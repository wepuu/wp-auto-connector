<?php
/**
 * Admin screen for WePuu Auto Connector.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Admin;

use WPAuto\Connector\Diagnostics\EnvironmentDiagnostics;
use WPAuto\Connector\Mcp\McpAdapterLoader;
use WPAuto\Connector\Mcp\McpServerRegistrar;
use WPAuto\Connector\Pairing\AdminPairingController;
use WPAuto\Connector\Pairing\ConnectionSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Displays the Phase 1.1 connector diagnostics.
 */
final class AdminPage {
	/**
	 * Register admin hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
	}

	/**
	 * Add the connector settings page.
	 */
	public function add_menu_page(): void {
		add_options_page(
			esc_html__( 'WePuu Auto Connector', 'wepuu-auto-connector' ),
			esc_html__( 'WePuu Auto Connector', 'wepuu-auto-connector' ),
			'manage_options',
			'wepuu-auto-connector',
			array( $this, 'render' )
		);
	}

	/**
	 * Render connector diagnostics for administrators.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$diagnostics = EnvironmentDiagnostics::site_health();
		$compatible  = McpAdapterLoader::is_compatible();
		$mcp_ready   = $diagnostics['abilities_api_available']
			&& $diagnostics['mcp_adapter_available']
			&& $diagnostics['rest_api_available']
			&& $compatible;
		$endpoint    = rest_url( McpServerRegistrar::ROUTE_NAMESPACE . '/' . McpServerRegistrar::ROUTE );
		$warnings    = $this->warnings( $diagnostics, $compatible );
		$connection  = ( new ConnectionSettings() )->load();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'WePuu Auto Connector', 'wepuu-auto-connector' ); ?></h1>
			<p><?php echo esc_html__( 'Direct MCP remains available through WordPress authentication. Optional platform pairing is a separate, administrator-controlled connection.', 'wepuu-auto-connector' ); ?></p>

			<?php foreach ( $warnings as $warning ) : ?>
				<div class="notice notice-warning inline"><p><?php echo esc_html( $warning ); ?></p></div>
			<?php endforeach; ?>

			<table class="widefat striped" style="max-width: 900px;">
				<tbody>
					<tr>
						<th scope="row"><?php echo esc_html__( 'MCP availability', 'wepuu-auto-connector' ); ?></th>
						<td><?php echo esc_html( $this->status_label( $mcp_ready ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Direct endpoint', 'wepuu-auto-connector' ); ?></th>
						<td><code><?php echo esc_html( $endpoint ); ?></code></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Abilities API', 'wepuu-auto-connector' ); ?></th>
						<td><?php echo esc_html( $this->status_label( $diagnostics['abilities_api_available'] ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'MCP Adapter', 'wepuu-auto-connector' ); ?></th>
						<td>
							<?php echo esc_html( $this->status_label( $diagnostics['mcp_adapter_available'] ) ); ?>
							<?php if ( $diagnostics['mcp_adapter_version'] ) : ?>
								<?php echo esc_html( sprintf( /* translators: %s: MCP Adapter version. */ __( '(version %s)', 'wepuu-auto-connector' ), $diagnostics['mcp_adapter_version'] ) ); ?>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'REST API', 'wepuu-auto-connector' ); ?></th>
						<td><?php echo esc_html( $this->status_label( $diagnostics['rest_api_available'] ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'HTTPS', 'wepuu-auto-connector' ); ?></th>
						<td><?php echo esc_html( $this->status_label( $diagnostics['https'] ) ); ?></td>
					</tr>
				</tbody>
			</table>

			<hr />
			<h2><?php echo esc_html__( 'Optional WePuu Platform pairing', 'wepuu-auto-connector' ); ?></h2>
			<p>
				<?php echo esc_html__( 'The platform manages connection and consent metadata only. MCP requests, tool inputs, tool results, WordPress content, passwords, and Application Passwords are not sent to the platform.', 'wepuu-auto-connector' ); ?>
			</p>
			<p>
				<?php echo esc_html__( 'Saving these settings enables the local pairing endpoint but does not contact the platform. A later explicit Connect action is required before the first external request.', 'wepuu-auto-connector' ); ?>
			</p>

			<?php if ( null === $connection ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width: 900px;">
					<input type="hidden" name="action" value="<?php echo esc_attr( AdminPairingController::ENABLE_ACTION ); ?>" />
					<?php wp_nonce_field( AdminPairingController::ENABLE_ACTION ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="wp-auto-control-origin"><?php echo esc_html__( 'Control-plane origin', 'wepuu-auto-connector' ); ?></label></th>
							<td><input class="regular-text code" id="wp-auto-control-origin" name="control_origin" type="url" required placeholder="https://platform.example.com" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="wp-auto-platform-issuer"><?php echo esc_html__( 'Authorization issuer', 'wepuu-auto-connector' ); ?></label></th>
							<td><input class="regular-text code" id="wp-auto-platform-issuer" name="platform_issuer" type="url" required placeholder="https://auth.example.com" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="wp-auto-tenant-id"><?php echo esc_html__( 'Tenant ID', 'wepuu-auto-connector' ); ?></label></th>
							<td><input class="regular-text code" id="wp-auto-tenant-id" name="tenant_id" type="text" required maxlength="36" /></td>
						</tr>
					</table>
					<?php submit_button( __( 'Enable platform pairing locally', 'wepuu-auto-connector' ) ); ?>
				</form>
			<?php else : ?>
				<table class="widefat striped" style="max-width: 900px;">
					<tbody>
						<tr><th scope="row"><?php echo esc_html__( 'Status', 'wepuu-auto-connector' ); ?></th><td><?php echo esc_html( $connection['status'] ); ?></td></tr>
						<tr><th scope="row"><?php echo esc_html__( 'Control plane', 'wepuu-auto-connector' ); ?></th><td><code><?php echo esc_html( $connection['control_origin'] ); ?></code></td></tr>
						<tr><th scope="row"><?php echo esc_html__( 'Issuer', 'wepuu-auto-connector' ); ?></th><td><code><?php echo esc_html( $connection['platform_issuer'] ); ?></code></td></tr>
						<tr><th scope="row"><?php echo esc_html__( 'Resource', 'wepuu-auto-connector' ); ?></th><td><code><?php echo esc_html( $connection['resource'] ); ?></code></td></tr>
					</tbody>
				</table>
				<?php if ( 'unpaired' === $connection['status'] ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="<?php echo esc_attr( AdminPairingController::CONNECT_ACTION ); ?>" />
						<?php wp_nonce_field( AdminPairingController::CONNECT_ACTION ); ?>
						<?php submit_button( __( 'Connect to WePuu Platform', 'wepuu-auto-connector' ), 'primary' ); ?>
					</form>
				<?php elseif ( 'pending' === $connection['status'] ) : ?>
					<p><?php echo esc_html__( 'Pairing is pending. Complete the platform window or disconnect to cancel and start again.', 'wepuu-auto-connector' ); ?></p>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( AdminPairingController::DISCONNECT_ACTION ); ?>" />
					<?php wp_nonce_field( AdminPairingController::DISCONNECT_ACTION ); ?>
					<?php submit_button( __( 'Disconnect and remove local pairing trust', 'wepuu-auto-connector' ), 'delete' ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Return configuration warnings.
	 *
	 * @param array<string, bool|string> $diagnostics Runtime diagnostics.
	 * @param bool                       $compatible Whether the adapter version is compatible.
	 * @return list<string>
	 */
	private function warnings( array $diagnostics, bool $compatible ): array {
		$warnings = array();

		if ( ! $diagnostics['abilities_api_available'] ) {
			$warnings[] = __( 'The WordPress Abilities API is unavailable. WordPress 6.9 or later is required.', 'wepuu-auto-connector' );
		}

		if ( ! $diagnostics['mcp_adapter_available'] ) {
			$warnings[] = __( 'The official WordPress MCP Adapter is unavailable. Install production Composer dependencies in the plugin build.', 'wepuu-auto-connector' );
		} elseif ( ! $compatible ) {
			$warnings[] = __( 'The loaded MCP Adapter version is incompatible. WP-Auto currently supports Adapter 0.6.1 and later 0.6.x releases.', 'wepuu-auto-connector' );
		}

		if ( ! $diagnostics['rest_api_available'] ) {
			$warnings[] = __( 'The WordPress REST API is unavailable, so the direct MCP endpoint cannot be registered.', 'wepuu-auto-connector' );
		}

		if ( ! $diagnostics['https'] ) {
			$warnings[] = __( 'HTTPS is required for remote MCP connections. Use HTTP only for local development.', 'wepuu-auto-connector' );
		}

		return $warnings;
	}

	/**
	 * Return a translated availability label.
	 *
	 * @param bool $available Whether the component is available.
	 */
	private function status_label( bool $available ): string {
		return $available
			? __( 'Available', 'wepuu-auto-connector' )
			: __( 'Unavailable', 'wepuu-auto-connector' );
	}
}
