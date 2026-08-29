<?php
/**
 * Admin screen for WP-Auto Connector.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Admin;

use WPAuto\Connector\Diagnostics\EnvironmentDiagnostics;
use WPAuto\Connector\Mcp\McpAdapterLoader;
use WPAuto\Connector\Mcp\McpServerRegistrar;

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
			esc_html__( 'WP-Auto Connector', 'wp-auto-connector' ),
			esc_html__( 'WP-Auto Connector', 'wp-auto-connector' ),
			'manage_options',
			'wp-auto-connector',
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
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'WP-Auto Connector', 'wp-auto-connector' ); ?></h1>
			<p><?php echo esc_html__( 'Phase 1.1 exposes one authenticated, read-only site-health tool through the official WordPress MCP Adapter.', 'wp-auto-connector' ); ?></p>

			<?php foreach ( $warnings as $warning ) : ?>
				<div class="notice notice-warning inline"><p><?php echo esc_html( $warning ); ?></p></div>
			<?php endforeach; ?>

			<table class="widefat striped" style="max-width: 900px;">
				<tbody>
					<tr>
						<th scope="row"><?php echo esc_html__( 'MCP availability', 'wp-auto-connector' ); ?></th>
						<td><?php echo esc_html( $this->status_label( $mcp_ready ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Direct endpoint', 'wp-auto-connector' ); ?></th>
						<td><code><?php echo esc_html( $endpoint ); ?></code></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Abilities API', 'wp-auto-connector' ); ?></th>
						<td><?php echo esc_html( $this->status_label( $diagnostics['abilities_api_available'] ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'MCP Adapter', 'wp-auto-connector' ); ?></th>
						<td>
							<?php echo esc_html( $this->status_label( $diagnostics['mcp_adapter_available'] ) ); ?>
							<?php if ( $diagnostics['mcp_adapter_version'] ) : ?>
								<?php echo esc_html( sprintf( /* translators: %s: MCP Adapter version. */ __( '(version %s)', 'wp-auto-connector' ), $diagnostics['mcp_adapter_version'] ) ); ?>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'REST API', 'wp-auto-connector' ); ?></th>
						<td><?php echo esc_html( $this->status_label( $diagnostics['rest_api_available'] ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'HTTPS', 'wp-auto-connector' ); ?></th>
						<td><?php echo esc_html( $this->status_label( $diagnostics['https'] ) ); ?></td>
					</tr>
				</tbody>
			</table>
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
			$warnings[] = __( 'The WordPress Abilities API is unavailable. WordPress 6.9 or later is required.', 'wp-auto-connector' );
		}

		if ( ! $diagnostics['mcp_adapter_available'] ) {
			$warnings[] = __( 'The official WordPress MCP Adapter is unavailable. Install production Composer dependencies in the plugin build.', 'wp-auto-connector' );
		} elseif ( ! $compatible ) {
			$warnings[] = __( 'The loaded MCP Adapter version is incompatible. WP-Auto currently supports Adapter 0.6.1 and later 0.6.x releases.', 'wp-auto-connector' );
		}

		if ( ! $diagnostics['rest_api_available'] ) {
			$warnings[] = __( 'The WordPress REST API is unavailable, so the direct MCP endpoint cannot be registered.', 'wp-auto-connector' );
		}

		if ( ! $diagnostics['https'] ) {
			$warnings[] = __( 'HTTPS is required for remote MCP connections. Use HTTP only for local development.', 'wp-auto-connector' );
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
			? __( 'Available', 'wp-auto-connector' )
			: __( 'Unavailable', 'wp-auto-connector' );
	}
}
