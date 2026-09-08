<?php
/**
 * Persistent media-ingestion idempotency claims.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Media;

use WPAuto\Connector\Content\AtomicOwnershipStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Stores one durable non-autoloaded option per media ingestion scope. */
final class MediaIngestionIdempotencyStore {
	private const OPTION_PREFIX = 'wp_auto_connector_media_idempotency_';

	/**
	 * Atomic ownership primitive for initial claims.
	 *
	 * @var AtomicOwnershipStore
	 */
	private AtomicOwnershipStore $ownership;

	/**
	 * Create the store with an optional ownership dependency.
	 *
	 * @param AtomicOwnershipStore|null $ownership Atomic ownership store.
	 */
	public function __construct( ?AtomicOwnershipStore $ownership = null ) {
		$this->ownership = $ownership ?? new AtomicOwnershipStore();
	}

	/**
	 * Atomically claim a media-ingestion scope.
	 *
	 * @param string $ability Ability name.
	 * @param int    $actor_id Current actor.
	 * @param string $key Raw key, never persisted.
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
		if ( 'occupied' !== ( $acquired['status'] ?? null ) || ! isset( $acquired['existing_value'] ) || ! is_array( $acquired['existing_value'] ) || ! $this->is_valid_record( $acquired['existing_value'] ) ) {
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
	 * Persist the known target while retaining ownership.
	 *
	 * @param string               $option_name Option name.
	 * @param array<string, mixed> $record Existing record.
	 * @param int                  $target_id Created attachment ID.
	 */
	public function record_target_in_progress( string $option_name, array $record, int $target_id ): bool {
		if ( ! $this->is_valid_record( $record ) || 'in_progress' !== $record['state'] || $target_id < 1 ) {
			return false;
		}
		$record['target_id']   = $target_id;
		$record['updated_gmt'] = current_time( 'mysql', true );
		return $this->update_and_verify( $option_name, $record );
	}

	/**
	 * Mark the upload audit event as durably recorded.
	 *
	 * @param string               $option_name Option name.
	 * @param array<string, mixed> $record Existing record.
	 */
	public function mark_audit_recorded( string $option_name, array $record ): bool {
		if ( ! $this->is_valid_record( $record ) || 'in_progress' !== $record['state'] || $record['target_id'] < 1 ) {
			return false;
		}
		$record['state']       = 'audit_recorded';
		$record['updated_gmt'] = current_time( 'mysql', true );
		return $this->update_and_verify( $option_name, $record );
	}

	/**
	 * Complete a successfully audited upload.
	 *
	 * @param string               $option_name Option name.
	 * @param array<string, mixed> $record Existing record.
	 */
	public function complete( string $option_name, array $record ): bool {
		if ( ! $this->is_valid_record( $record ) || ! in_array( $record['state'], array( 'audit_recorded', 'completed' ), true ) || $record['target_id'] < 1 ) {
			return false;
		}
		$record['state']       = 'completed';
		$record['updated_gmt'] = current_time( 'mysql', true );
		return $this->update_and_verify( $option_name, $record );
	}

	/**
	 * Release only the exact initial record after proven no-write failure.
	 *
	 * @param string               $option_name Option name.
	 * @param array<string, mixed> $record Exact initial record.
	 * @return array<string, string>
	 */
	public function release( string $option_name, array $record ): array {
		return $this->ownership->release( $option_name, $record );
	}

	/**
	 * Derive the private site/actor/Ability/key option name.
	 *
	 * @param string $ability Ability name.
	 * @param int    $actor_id Current actor.
	 * @param string $key Raw key.
	 */
	private function option_name( string $ability, int $actor_id, string $key ): string {
		$scope = get_current_blog_id() . "\0" . $actor_id . "\0" . $ability . "\0" . $key;
		return self::OPTION_PREFIX . hash( 'sha256', $scope );
	}

	/**
	 * Validate the exact persisted media claim record.
	 *
	 * @param array<string, mixed> $record Persisted record.
	 */
	private function is_valid_record( array $record ): bool {
		$expected = array( 'version', 'actor_user_id', 'ability', 'fingerprint', 'state', 'target_id', 'created_gmt', 'updated_gmt' );
		$keys     = array_keys( $record );
		sort( $expected );
		sort( $keys );

		return $keys === $expected
			&& 1 === $record['version']
			&& is_int( $record['actor_user_id'] ) && $record['actor_user_id'] >= 1
			&& 'wp-auto/media-upload' === $record['ability']
			&& is_string( $record['fingerprint'] ) && 1 === preg_match( '/^[0-9a-f]{64}$/D', $record['fingerprint'] )
			&& in_array( $record['state'], array( 'in_progress', 'audit_recorded', 'completed' ), true )
			&& is_int( $record['target_id'] ) && $record['target_id'] >= 0
			&& ( 'in_progress' === $record['state'] || $record['target_id'] >= 1 )
			&& is_string( $record['created_gmt'] ) && $this->valid_timestamp( $record['created_gmt'] )
			&& is_string( $record['updated_gmt'] ) && $this->valid_timestamp( $record['updated_gmt'] );
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

		return (int) substr( $value, 0, 4 ) > 0
			&& checkdate( (int) substr( $value, 5, 2 ), (int) substr( $value, 8, 2 ), (int) substr( $value, 0, 4 ) )
			&& (int) substr( $value, 11, 2 ) < 24
			&& (int) substr( $value, 14, 2 ) < 60
			&& (int) substr( $value, 17, 2 ) < 60;
	}

	/**
	 * Update and strictly verify the durable non-autoloaded record.
	 *
	 * @param string               $option_name Option name.
	 * @param array<string, mixed> $record Updated record.
	 */
	private function update_and_verify( string $option_name, array $record ): bool {
		update_option( $option_name, $record, false );
		return get_option( $option_name, null ) === $record;
	}
}
