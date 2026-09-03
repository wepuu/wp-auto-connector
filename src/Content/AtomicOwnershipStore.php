<?php
/**
 * Atomic ownership for WP-Auto internal coordination rows.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Content;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides the private insert-if-absent and exact-value release primitive.
 */
final class AtomicOwnershipStore {
	private const IDEMPOTENCY_PATTERN = '/^wp_auto_connector_idempotency_[0-9a-f]{64}$/D';
	private const AUDIT_LOCK_PATTERN  = '/^wp_auto_connector_mutation_audit_lock_[0-9a-f]{64}$/D';
	private const UUID_PATTERN        = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';
	private const TIMESTAMP_PATTERN   = '/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D';

	private const ACQUIRED   = 'acquired';
	private const OCCUPIED   = 'occupied';
	private const UNRESOLVED = 'unresolved';
	private const RELEASED   = 'released';
	private const NOT_OWNER  = 'not_owner';

	/**
	 * Database handle.
	 *
	 * @var \wpdb
	 */
	private \wpdb $wpdb;

	/**
	 * Use the active site's WordPress database connection.
	 *
	 * @param \wpdb|null $wpdb Optional database connection for tests.
	 */
	public function __construct( ?\wpdb $wpdb = null ) {
		if ( $wpdb instanceof \wpdb ) {
			$this->wpdb = $wpdb;
			return;
		}

		global $wpdb;
		$this->wpdb = $wpdb;
	}

	/**
	 * Attempt to acquire an ownership row.
	 *
	 * @param string                     $option_name Canonical private option name.
	 * @param array<string,mixed>|string $value Initial record or UUID token.
	 * @return array<string,mixed>
	 */
	public function acquire( string $option_name, array|string $value ): array {
		$family = $this->validate_input_pair( $option_name, $value );
		if ( null === $family ) {
			return $this->unresolved_result();
		}

		try {
			$serialized = maybe_serialize( $value );
			$prepared   = $this->wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"INSERT IGNORE INTO {$this->wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
				$option_name,
				$serialized,
				'off'
			);
		} catch ( \Throwable ) {
			return $this->unresolved_result();
		}

		$query = $this->run_query( $prepared );
		if ( null === $query ) {
			return $this->unresolved_result();
		}

		if ( 1 === $query['rows'] ) {
			$readback = $this->finalize_cache( $option_name );
			if ( ! $readback['ok'] || $readback['value'] !== $value ) {
				return $this->unresolved_result();
			}

			return array( 'status' => self::ACQUIRED );
		}

		$readback = $this->finalize_cache( $option_name );
		if ( ! $readback['ok'] ) {
			return $this->unresolved_result();
		}

		$observed = $readback['value'];
		if ( 'idempotency' === $family && is_array( $observed ) ) {
			return array(
				'status'         => self::OCCUPIED,
				'existing_value' => $observed,
			);
		}
		if ( 'audit' === $family && is_string( $observed ) && 1 === preg_match( self::UUID_PATTERN, $observed ) ) {
			return array(
				'status'         => self::OCCUPIED,
				'existing_value' => $observed,
			);
		}

		return $this->unresolved_result();
	}

	/**
	 * Release an ownership row only when its exact persisted value still owns it.
	 *
	 * @param string                     $option_name Canonical private option name.
	 * @param array<string,mixed>|string $expected_value Exact initial value/token.
	 * @return array<string,string>
	 */
	public function release( string $option_name, array|string $expected_value ): array {
		if ( null === $this->validate_input_pair( $option_name, $expected_value ) ) {
			return $this->unresolved_result();
		}

		try {
			$serialized = maybe_serialize( $expected_value );
			$prepared   = $this->wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"DELETE FROM {$this->wpdb->options} WHERE option_name = %s AND CAST(option_value AS BINARY) = CAST(%s AS BINARY)",
				$option_name,
				$serialized
			);
		} catch ( \Throwable ) {
			return $this->unresolved_result();
		}

		$query = $this->run_query( $prepared );
		if ( null === $query ) {
			return $this->unresolved_result();
		}
		if ( 0 === $query['rows'] ) {
			return array( 'status' => self::NOT_OWNER );
		}

		$readback = $this->finalize_cache( $option_name );
		if ( ! $readback['ok'] || null !== $readback['value'] ) {
			return $this->unresolved_result();
		}

		return array( 'status' => self::RELEASED );
	}

	/**
	 * Validate the namespace and the only value type allowed for that namespace.
	 *
	 * @param string                     $option_name Canonical private option name.
	 * @param array<string,mixed>|string $value Ownership value.
	 * @return string|null
	 */
	private function validate_input_pair( string $option_name, $value ): ?string {
		if ( 1 === preg_match( self::IDEMPOTENCY_PATTERN, $option_name ) ) {
			return is_array( $value ) && $this->valid_initial_record( $value ) ? 'idempotency' : null;
		}

		if ( 1 === preg_match( self::AUDIT_LOCK_PATTERN, $option_name ) ) {
			return is_string( $value ) && 1 === preg_match( self::UUID_PATTERN, $value ) ? 'audit' : null;
		}

		return null;
	}

	/**
	 * Validate the exact initial Create claim record.
	 *
	 * @param array<string,mixed> $record Candidate record.
	 */
	private function valid_initial_record( array $record ): bool {
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
		sort( $keys );
		$expected = $required;
		sort( $expected );

		return $keys === $expected
			&& 1 === $record['version']
			&& is_int( $record['actor_user_id'] )
			&& $record['actor_user_id'] >= 1
			&& in_array( $record['ability'], array( 'wp-auto/post-create-draft', 'wp-auto/page-create-draft' ), true )
			&& is_string( $record['fingerprint'] )
			&& 1 === preg_match( '/^[0-9a-f]{64}$/D', $record['fingerprint'] )
			&& 'in_progress' === $record['state']
			&& is_int( $record['target_id'] )
			&& 0 === $record['target_id']
			&& is_string( $record['created_gmt'] )
			&& $this->valid_timestamp( $record['created_gmt'] )
			&& is_string( $record['updated_gmt'] )
			&& $this->valid_timestamp( $record['updated_gmt'] );
	}

	/**
	 * Validate a Core GMT timestamp used by the idempotency record.
	 *
	 * @param string $value Candidate timestamp.
	 */
	private function valid_timestamp( string $value ): bool {
		if ( 1 !== preg_match( self::TIMESTAMP_PATTERN, $value ) ) {
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
	 * Run one prepared ownership query with a temporary wpdb suppression boundary.
	 *
	 * @param mixed $prepared Prepared query.
	 * @return array{rows:int}|null
	 */
	private function run_query( $prepared ): ?array {
		try {
			$previous_suppress = $this->wpdb->suppress_errors( true );
			try {
				// The caller has just prepared this query and no other SQL reaches this method.
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$result     = $this->wpdb->query( $prepared );
				$last_error = (string) $this->wpdb->last_error;
			} finally {
				$this->wpdb->suppress_errors( $previous_suppress );
			}
		} catch ( \Throwable ) {
			return null;
		}

		if ( false === $result || '' !== $last_error || ! is_int( $result ) || ! is_int( $this->wpdb->rows_affected ) || $result !== $this->wpdb->rows_affected || ! in_array( $result, array( 0, 1 ), true ) ) {
			return null;
		}

		return array( 'rows' => $result );
	}

	/**
	 * Invalidate every relevant option cache and return the logical Core value.
	 *
	 * @param string $option_name Option name being finalized.
	 * @return array{ok:bool,value:mixed}
	 */
	private function finalize_cache( string $option_name ): array {
		try {
			wp_cache_delete( $option_name, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			$observed = get_option( $option_name, null );
		} catch ( \Throwable ) {
			return array(
				'ok'    => false,
				'value' => null,
			);
		}

		return array(
			'ok'    => true,
			'value' => $observed,
		);
	}

	/**
	 * Return an unresolved result.
	 *
	 * @return array<string,string>
	 */
	private function unresolved_result(): array {
		return array( 'status' => self::UNRESOLVED );
	}
}
