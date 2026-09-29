<?php
/**
 * OAuth scope ceiling around existing WordPress permission callbacks.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\OAuth;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Adds a Bearer-only scope check before every frozen ability callback. */
final class AbilityScopeGate {
	/**
	 * Build the centralized scope decorator.
	 *
	 * @param BearerRequestContext $context Shared request-local identity.
	 * @param ScopePolicy|null     $policy  Frozen tool mapping.
	 */
	public function __construct( private BearerRequestContext $context, private ?ScopePolicy $policy = null ) {
		$this->policy = $this->policy ?? new ScopePolicy();
	}

	/** Register before abilities are initialized. */
	public function register(): void {
		add_filter( 'wp_register_ability_args', array( $this, 'filter' ), 5, 2 );
	}

	/**
	 * Wrap only the frozen connector abilities; public schemas remain untouched.
	 *
	 * @param array<string,mixed> $args         Ability arguments.
	 * @param string              $ability_name Ability name.
	 * @return array<string,mixed>
	 */
	public function filter( array $args, string $ability_name ): array {
		$required = $this->policy->required_scope( $ability_name );
		$original = $args['permission_callback'] ?? null;
		if ( null === $required || ! is_callable( $original ) ) {
			return $args;
		}

		$args['permission_callback'] = function ( ...$arguments ) use ( $original, $required ) {
			if ( $this->context->authenticated() && ! $this->context->allows( $required ) ) {
				return new WP_Error(
					'wp_auto_connector_insufficient_scope',
					__( 'The OAuth grant does not allow this WP-Auto tool.', 'wepuu-auto-connector' ),
					array(
						'status'         => 403,
						'oauth_error'    => 'insufficient_scope',
						'required_scope' => $required,
					)
				);
			}
			return $original( ...$arguments );
		};

		return $args;
	}
}
