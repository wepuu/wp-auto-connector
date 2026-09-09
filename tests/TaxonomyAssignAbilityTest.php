<?php
/**
 * Taxonomy assignment ability tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WPAuto\Connector\Abilities\Taxonomy\TaxonomyAssignAbility;
use WPAuto\Connector\Taxonomy\TaxonomyAssignContract;

/** Covers registration metadata and permission delegation. */
final class TaxonomyAssignAbilityTest extends TestCase {
	/** Reset hook and registration state. */
	protected function setUp(): void {
		$GLOBALS['wp_auto_test_hooks']              = array();
		$GLOBALS['wp_auto_test_registered_ability'] = null;
		$GLOBALS['wp_auto_test_current_user_id']    = 7;
		$GLOBALS['wp_auto_test_capabilities']       = array(
			'assign_terms' => true,
			'edit_posts'   => true,
			'edit_post'    => true,
		);
		$GLOBALS['wp_auto_test_taxonomies']         = array(
			'category' => (object) array(
				'cap' => (object) array(
					'manage_terms' => 'manage_categories',
					'assign_terms' => 'assign_terms',
				),
			),
			'post_tag' => (object) array(
				'cap' => (object) array(
					'manage_terms' => 'manage_categories',
					'assign_terms' => 'assign_terms',
				),
			),
		);
		$GLOBALS['wp_auto_test_posts']              = array(
			new \WP_Post(
				array(
					'ID'          => 100,
					'post_type'   => 'post',
					'post_status' => 'draft',
					'post_author' => 7,
				)
			),
		);
	}

	/** Registration exposes the exact contract and destructive annotation. */
	public function test_registers_contract_and_safety_annotations(): void {
		$ability = new TaxonomyAssignAbility();
		$ability->register();
		self::assertArrayHasKey( 'wp_abilities_api_init', $GLOBALS['wp_auto_test_hooks'] );

		$ability->register_ability();
		$args = $GLOBALS['wp_auto_test_registered_ability']['args'];
		self::assertSame( TaxonomyAssignContract::input_schema(), $args['input_schema'] );
		self::assertSame( TaxonomyAssignContract::output_schema(), $args['output_schema'] );
		self::assertFalse( $args['meta']['annotations']['readonly'] );
		self::assertTrue( $args['meta']['annotations']['destructive'] );
		self::assertTrue( $args['meta']['annotations']['idempotent'] );
	}

	/** Permission checks are delegated to the service with the request input. */
	public function test_permission_requires_fixed_baseline(): void {
		$ability = new TaxonomyAssignAbility();
		self::assertTrue(
			$ability->check_permission(
				array(
					'target_id'         => 100,
					'taxonomy'          => 'category',
					'term_ids'          => array( 10 ),
					'expected_term_ids' => array(),
				)
			)
		);
		$GLOBALS['wp_auto_test_capabilities']['assign_terms'] = false;
		self::assertFalse(
			$ability->check_permission(
				array(
					'target_id'         => 100,
					'taxonomy'          => 'category',
					'term_ids'          => array( 10 ),
					'expected_term_ids' => array(),
				)
			)
		);
	}
}
