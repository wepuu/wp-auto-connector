<?php
/**
 * Persistent Create Draft idempotency claims.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Content;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores one durable, non-autoloaded option per Create Draft scope.
 */
final class CreateIdempotencyStore {
	private const OPTION_PREFIX = 'wp_auto_connector_idempotency_';

	/**
	 * Atomic ownership primitive for initial claims.
	 *
	 * @var AtomicOwnershipStore
	 */
	private AtomicOwnershipStore $ownership;

	/**
	 * Create the store with an optional ownership dependency for tests.
	 *
	 * @param AtomicOwnershipStore|null $ownership Atomic ownership store.
	 */
	public function __construct( ?AtomicOwnershipStore $ownership = null ) {
		$this->ownership = $ownership ?? new AtomicOwnershipStore();
	}

	/**
	 * Atomically claim a scope or return the existing record.
	 *
	 * @param string $ability Ability name.
	 * @param int    $actor_id Current actor.
	 * @param string $key Raw key (never persisted).
	 * @param string $fingerprint Canonical payload fingerprint.
	 * @return array<string, mixed>
	 */
	public function claim( string $ability, int $actor_id, string $key, string $fingerprint ): array {
		$option_name = $this->option_name( $ability, $actor_id, $key );
		$now         = current_time( 'mysql', true );
		$record      = array(
			'version'       => 1,
			'actor_user_id' => $actor_id,
			'ability'       => $ability,
			'fingerprint'   => $fingerprint,
			'state'         => 'in_progress',
			'target_id'     => 0,
			'created_gmt'   => $now,
			'updated_gmt'   => $now,
		);

		$acquired = $this->ownership->acquire( $option_name, $record );
		if ( 'acquired' === ( $acquired['status'] ?? null ) ) {
			return array(
				'status' => 'claimed',
				'name'   => $option_name,
				'record' => $record,
			);
		}

		if ( 'occupied' !== ( $acquired['status'] ?? null ) || ! isset( $acquired['existing_value'] ) || ! is_array( $acquired['existing_value'] ) ) {
			return array(
				'status' => 'unresolved',
				'name'   => $option_name,
				'record' => null,
			);
		}

		return array(
			'status' => 'existing',
			'name'   => $option_name,
			'record' => $acquired['existing_value'],
		);
	}

	/**
	 * Persist the known target ID while retaining finalization ownership.
	 *
	 * @param string               $option_name Option name.
	 * @param array<string, mixed> $record Existing record.
	 * @param int                  $target_id Created object ID.
	 */
	public function record_target_in_progress( string $option_name, array $record, int $target_id ): bool {
		if ( $target_id < 1 || 'in_progress' !== ( $record['state'] ?? null ) ) {
			return false;
		}

		$record['state']       = 'in_progress';
		$record['target_id']   = $target_id;
		$record['updated_gmt'] = current_time( 'mysql', true );

		return $this->update_and_verify( $option_name, $record );
	}

	/**
	 * Mark a target as audited before completing the claim.
	 *
	 * @param string               $option_name Option name.
	 * @param array<string, mixed> $record Existing record.
	 */
	public function mark_audit_recorded( string $option_name, array $record ): bool {
		if ( 'in_progress' !== ( $record['state'] ?? null ) || ! isset( $record['target_id'] ) || ! is_int( $record['target_id'] ) || $record['target_id'] < 1 ) {
			return false;
		}

		$record['state']       = 'audit_recorded';
		$record['updated_gmt'] = current_time( 'mysql', true );

		return $this->update_and_verify( $option_name, $record );
	}

	/**
	 * Finalize a successfully audited operation.
	 *
	 * @param string               $option_name Option name.
	 * @param array<string, mixed> $record Existing record.
	 */
	public function complete( string $option_name, array $record ): bool {
		if ( ! in_array( $record['state'] ?? null, array( 'audit_recorded', 'completed' ), true ) || ! isset( $record['target_id'] ) || ! is_int( $record['target_id'] ) || $record['target_id'] < 1 ) {
			return false;
		}

		$record['state']       = 'completed';
		$record['updated_gmt'] = current_time( 'mysql', true );

		return $this->update_and_verify( $option_name, $record );
	}

	/**
	 * Release a claim only after Core has proven that no object was created.
	 *
	 * @param string               $option_name Option name.
	 * @param array<string, mixed> $expected_record Exact initial record.
	 * @return array<string, string>
	 */
	public function release( string $option_name, array $expected_record ): array {
		return $this->ownership->release( $option_name, $expected_record );
	}

	/**
	 * Return the option name without exposing the raw key.
	 *
	 * @param string $ability Ability name.
	 * @param int    $actor_id Current actor.
	 * @param string $key Raw key.
	 */
	private function option_name( string $ability, int $actor_id, string $key ): string {
		$blog_id = function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0;
		$scope   = $blog_id . "\0" . $actor_id . "\0" . $ability . "\0" . $key;

		return self::OPTION_PREFIX . hash( 'sha256', $scope );
	}

	/**
	 * Update a record and verify the stored value, including non-autoload state.
	 *
	 * @param string               $option_name Option name.
	 * @param array<string, mixed> $record New record.
	 */
	private function update_and_verify( string $option_name, array $record ): bool {
		update_option( $option_name, $record, false );

		return get_option( $option_name, null ) === $record;
	}
}
