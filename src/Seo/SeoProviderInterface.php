<?php
/**
 * Internal provider-neutral SEO read boundary.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Seo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Supplies bounded explicit SEO state without exposing provider contracts.
 */
interface SeoProviderInterface {
	/** Return the stable internal provider key used only in state tokens. */
	public function key(): string;

	/** Return the internal adapter contract version. */
	public function adapter_version(): string;

	/** Return the active provider runtime version. */
	public function runtime_version(): string;

	/** Check whether this exact supported provider runtime is active. */
	public function is_available(): bool;

	/** Enforce the provider's effective SEO read capability. */
	public function can_read(): bool;

	/**
	 * Read normalized public values plus bounded protected state.
	 *
	 * @param int $post_id Target Post or Page ID.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function read_state( int $post_id );
}
