<?php
/**
 * Provider registry for the Phase 1.6 SEO abstraction.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Seo;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves exactly one supported active provider and fails closed otherwise.
 */
final class SeoProviderRegistry {
	/**
	 * Registered internal providers.
	 *
	 * @var array<int,SeoProviderInterface>
	 */
	private array $providers;

	/**
	 * Use the production provider set or an isolated test set.
	 *
	 * @param array<int,SeoProviderInterface>|null $providers Optional providers.
	 */
	public function __construct( ?array $providers = null ) {
		$this->providers = $providers ?? array( new RankMathSeoProvider() );
	}

	/**
	 * Resolve exactly one compatible active provider.
	 *
	 * @return SeoProviderInterface|WP_Error
	 */
	public function resolve() {
		$available = array_values(
			array_filter(
				$this->providers,
				static fn( SeoProviderInterface $provider ): bool => $provider->is_available()
			)
		);

		if ( array() === $available ) {
			return new WP_Error(
				'wp_auto_seo_provider_unavailable',
				__( 'A compatible SEO provider is not available.', 'wepuu-auto-connector' ),
				array( 'status' => 409 )
			);
		}

		if ( 1 !== count( $available ) ) {
			return new WP_Error(
				'wp_auto_seo_provider_conflict',
				__( 'More than one compatible SEO provider is active.', 'wepuu-auto-connector' ),
				array( 'status' => 409 )
			);
		}

		return $available[0];
	}

	/**
	 * Enforce provider permission at the Ability boundary when resolvable.
	 *
	 * Absence and conflict remain executable semantic outcomes for an otherwise
	 * read-capable WordPress identity.
	 */
	public function can_read(): bool {
		$provider = $this->resolve();
		return $provider instanceof WP_Error || $provider->can_read();
	}

	/** Enforce provider write permission while preserving unavailable semantics. */
	public function can_write(): bool {
		$provider = $this->resolve();
		return $provider instanceof WP_Error || $provider->can_write();
	}
}
