<?php
/**
 * Phase 1.6.1 provider-neutral SEO Get Ability.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Abilities\Seo;

use WPAuto\Connector\Seo\SeoReadService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers the fixed read-only SEO Get contract. */
final class SeoGetAbility {
	public const NAME = 'wp-auto/seo-get';

	/** Register the Ability hook. */
	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_ability' ) );
	}

	/** Register the Ability with WordPress Core. */
	public function register_ability(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			self::NAME,
			array(
				'label'               => __( 'WP-Auto SEO Get', 'wepuu-auto-connector' ),
				'description'         => __( 'Returns explicit SEO overrides for one authorized WordPress Post or Page.', 'wepuu-auto-connector' ),
				'category'            => SeoAbilityCategory::SLUG,
				'input_schema'        => self::input_schema(),
				'output_schema'       => self::output_schema(),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'meta'                => array(
					'annotations' => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);
	}

	/**
	 * Execute the provider-neutral SEO read.
	 *
	 * @param mixed $input Validated Ability input.
	 */
	public function execute( $input ) {
		return ( new SeoReadService() )->get( $input );
	}

	/** Enforce Core and provider read permissions at Ability entry. */
	public function check_permission(): bool {
		return ( new SeoReadService() )->can_read();
	}

	/** Return the strict positive-ID input schema. */
	private static function input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'id' ),
		);
	}

	/** Return the exact provider-neutral output schema. */
	private static function output_schema(): array {
		$properties = array(
			'id'             => array( 'type' => 'integer' ),
			'type'           => array(
				'type' => 'string',
				'enum' => array( 'post', 'page' ),
			),
			'status'         => array(
				'type' => 'string',
				'enum' => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			),
			'title'          => array(
				'type'      => 'string',
				'maxLength' => 500,
			),
			'description'    => array(
				'type'      => 'string',
				'maxLength' => 2000,
			),
			'canonical_url'  => array(
				'type'      => 'string',
				'maxLength' => 2048,
			),
			'focus_keywords' => array(
				'type'        => 'array',
				'maxItems'    => 5,
				'uniqueItems' => true,
				'items'       => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
			),
			'robots'         => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => array(
					'index'  => array(
						'type' => 'string',
						'enum' => array( 'default', 'index', 'noindex' ),
					),
					'follow' => array(
						'type' => 'string',
						'enum' => array( 'default', 'follow', 'nofollow' ),
					),
				),
				'required'             => array( 'index', 'follow' ),
			),
			'state_token'    => array(
				'type'    => 'string',
				'pattern' => '^[0-9a-f]{64}$',
			),
		);

		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => $properties,
			'required'             => array_keys( $properties ),
		);
	}
}
