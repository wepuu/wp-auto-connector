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

		if ( ! $this->is_valid_persisted_record( $acquired['existing_value'] ) ) {
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
		if ( ! $this->is_valid_persisted_record( $record ) || $target_id < 1 || 'in_progress' !== $record['state'] ) {
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
		if ( ! $this->is_valid_persisted_record( $record ) || 'in_progress' !== $record['state'] || $record['target_id'] < 1 ) {
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
		if ( ! $this->is_valid_persisted_record( $record ) || ! in_array( $record['state'], array( 'audit_recorded', 'completed' ), true ) || $record['target_id'] < 1 ) {
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
	 * Validate the exact persisted idempotency record schema and invariants.
	 *
	 * @param array<string, mixed> $record Persisted record.
	 */
	private function is_valid_persisted_record( array $record ): bool {
		$required = array(
			'version',
			'actor_user_id',
			'ability',
			'fingerprint',
			'state',
			'target_id',
			'created_gmt',
			'updated_gmt',
		);
		$keys     = array_keys( $record );
		$expected = $required;
		sort( $keys );
		sort( $expected );

		if ( $keys !== $expected
			|| 1 !== $record['version']
			|| ! is_int( $record['actor_user_id'] )
			|| $record['actor_user_id'] < 1
			|| ! in_array( $record['ability'], array( 'wp-auto/post-create-draft', 'wp-auto/page-create-draft' ), true )
			|| ! is_string( $record['fingerprint'] )
			|| 1 !== preg_match( '/^[0-9a-f]{64}$/D', $record['fingerprint'] )
			|| ! in_array( $record['state'], array( 'in_progress', 'audit_recorded', 'completed' ), true )
			|| ! is_int( $record['target_id'] )
			|| $record['target_id'] < 0
			|| ( 'in_progress' !== $record['state'] && $record['target_id'] < 1 )
			|| ! is_string( $record['created_gmt'] )
			|| ! $this->valid_timestamp( $record['created_gmt'] )
			|| ! is_string( $record['updated_gmt'] )
			|| ! $this->valid_timestamp( $record['updated_gmt'] )
		) {
			return false;
		}

		return true;
	}

	/**
	 * Validate a real Gregorian GMT timestamp.
	 *
	 * @param string $value Timestamp.
	 */
	private function valid_timestamp( string $value ): bool {
		if ( 1 !== preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $value ) ) {
			return false;
		}

		$year   = (int) substr( $value, 0, 4 );
		$month  = (int) substr( $value, 5, 2 );
		$day    = (int) substr( $value, 8, 2 );
		$hour   = (int) substr( $value, 11, 2 );
		$minute = (int) substr( $value, 14, 2 );
		$second = (int) substr( $value, 17, 2 );

		return $year > 0 && checkdate( $month, $day, $year ) && $hour < 24 && $minute < 60 && $second < 60;
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
