<?php
/**
 * Explicit-uninstall cleanup for private WP-Auto mutation state.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Uninstall;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Removes only the private persistent families approved by ADR-004/ADR-005.
 */
final class PrivateStateCleanup {
	private const IDEMPOTENCY_PREFIX       = 'wp_auto_connector_idempotency_';
	private const MEDIA_IDEMPOTENCY_PREFIX = 'wp_auto_connector_media_idempotency_';
	private const AUDIT_LOCK_PREFIX        = 'wp_auto_connector_mutation_audit_lock_';
	private const AUDIT_META_KEYS          = array( '_wp_auto_connector_mutation_audit', '_wp_auto_connector_media_mutation_audit' );

	private const IDEMPOTENCY_PATTERN       = '/\Awp_auto_connector_idempotency_[0-9a-f]{64}\z/';
	private const MEDIA_IDEMPOTENCY_PATTERN = '/\Awp_auto_connector_media_idempotency_[0-9a-f]{64}\z/';
	private const AUDIT_LOCK_PATTERN        = '/\Awp_auto_connector_mutation_audit_lock_[0-9a-f]{64}\z/';

	private const OPTION_BATCH_SIZE    = 100;
	private const SITE_BATCH_SIZE      = 50;
	private const MAX_RESTORE_ATTEMPTS = 16;

	/**
	 * Active WordPress database connection.
	 *
	 * @var \wpdb
	 */
	private \wpdb $wpdb;

	/**
	 * Use the active WordPress database connection.
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
	 * Run bounded best-effort cleanup.
	 *
	 * The return value is internal validation state. WordPress does not consume it.
	 */
	public function run(): bool {
		try {
			return is_multisite() ? $this->cleanup_network() : $this->cleanup_current_blog();
		} catch ( \Throwable ) {
			return false;
		}
	}

	/**
	 * Clean the active blog and prove the approved families absent.
	 */
	private function cleanup_current_blog(): bool {
		$deletion_complete = $this->walk_options( true );
		$options_absent    = $this->walk_options( false );
		$audit_absent      = $this->cleanup_audit_metadata();

		return $deletion_complete && $options_absent && $audit_absent;
	}

	/**
	 * Delete or verify exact-valid private options using one keyset pass.
	 *
	 * @param bool $delete Whether this is deletion Pass 1 or verification Pass 2.
	 */
	private function walk_options( bool $delete ): bool {
		$cursor   = 0;
		$complete = true;

		while ( true ) {
			$rows = $this->read_option_batch( $cursor );
			if ( null === $rows ) {
				return false;
			}
			if ( array() === $rows ) {
				return $complete;
			}

			foreach ( $rows as $row ) {
				if ( ! $this->has_exact_keys( $row, array( 'option_id', 'option_name' ) ) || ! is_string( $row['option_name'] ) ) {
					return false;
				}

				$option_id = $this->positive_database_id( $row['option_id'] );
				if ( null === $option_id || $option_id <= $cursor ) {
					return false;
				}
				$cursor = $option_id;

				if ( ! $this->is_owned_option_name( $row['option_name'] ) ) {
					continue;
				}

				if ( ! $delete ) {
					$complete = false;
					continue;
				}

				try {
					delete_option( $row['option_name'] );
				} catch ( \Throwable ) {
					$complete = false;
				}
			}
		}
	}

	/**
	 * Read one bounded option-name batch from the active blog.
	 *
	 * @param int $cursor Last observed option ID.
	 * @return array<int,array<string,mixed>>|null
	 */
	private function read_option_batch( int $cursor ): ?array {
		try {
			$idempotency_like       = $this->wpdb->esc_like( self::IDEMPOTENCY_PREFIX ) . '%';
			$media_idempotency_like = $this->wpdb->esc_like( self::MEDIA_IDEMPOTENCY_PREFIX ) . '%';
			$audit_lock_like        = $this->wpdb->esc_like( self::AUDIT_LOCK_PREFIX ) . '%';
			$prepared               = $this->wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT option_id, option_name FROM {$this->wpdb->options} WHERE option_id > %d AND ( option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s ) ORDER BY option_id ASC LIMIT %d",
				$cursor,
				$idempotency_like,
				$media_idempotency_like,
				$audit_lock_like,
				self::OPTION_BATCH_SIZE
			);
		} catch ( \Throwable ) {
			return null;
		}

		return $this->read_rows( $prepared, array( 'option_id', 'option_name' ), self::OPTION_BATCH_SIZE );
	}

	/**
	 * Remove and independently verify every exact audit metadata key.
	 */
	private function cleanup_audit_metadata(): bool {
		$complete = true;
		foreach ( self::AUDIT_META_KEYS as $meta_key ) {
			try {
				delete_post_meta_by_key( $meta_key );
			} catch ( \Throwable ) {
				$complete = false;
			}

			try {
				$prepared = $this->wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT meta_id FROM {$this->wpdb->postmeta} WHERE meta_key = %s ORDER BY meta_id ASC LIMIT 1",
					$meta_key
				);
			} catch ( \Throwable ) {
				return false;
			}

			$rows = $this->read_rows( $prepared, array( 'meta_id' ), 1 );
			if ( null === $rows || 0 !== count( $rows ) ) {
				return false;
			}
		}

		return $complete;
	}

	/**
	 * Clean every physical blog using a bounded blog-ID keyset traversal.
	 */
	private function cleanup_network(): bool {
		$current_blog_id = $this->positive_database_id( get_current_blog_id() );
		if ( null === $current_blog_id ) {
			return false;
		}

		$cursor       = 0;
		$complete     = true;
		$processed    = false;
		$current_seen = false;

		while ( true ) {
			$rows = $this->read_blog_batch( $cursor );
			if ( null === $rows ) {
				return false;
			}
			if ( array() === $rows ) {
				return $complete && $processed && $current_seen;
			}

			foreach ( $rows as $row ) {
				if ( ! $this->has_exact_keys( $row, array( 'blog_id' ) ) ) {
					return false;
				}

				$blog_id = $this->positive_database_id( $row['blog_id'] );
				if ( null === $blog_id || $blog_id <= $cursor ) {
					return false;
				}
				$cursor    = $blog_id;
				$processed = true;
				if ( $blog_id === $current_blog_id ) {
					$current_seen = true;
				}

				$result   = $this->cleanup_switched_blog( $blog_id );
				$complete = $complete && $result['complete'];
				if ( ! $result['restored'] ) {
					return false;
				}
			}
		}
	}

	/**
	 * Read one bounded batch of physical blog IDs.
	 *
	 * @param int $cursor Last observed blog ID.
	 * @return array<int,array<string,mixed>>|null
	 */
	private function read_blog_batch( int $cursor ): ?array {
		try {
			$prepared = $this->wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT blog_id FROM {$this->wpdb->blogs} WHERE blog_id > %d ORDER BY blog_id ASC LIMIT %d",
				$cursor,
				self::SITE_BATCH_SIZE
			);
		} catch ( \Throwable ) {
			return null;
		}

		return $this->read_rows( $prepared, array( 'blog_id' ), self::SITE_BATCH_SIZE );
	}

	/**
	 * Clean one switched blog and prove the original context restored.
	 *
	 * @param int $blog_id Target blog ID.
	 * @return array{complete:bool,restored:bool}
	 */
	private function cleanup_switched_blog( int $blog_id ): array {
		$before = $this->context_snapshot();
		if ( null === $before ) {
			return array(
				'complete' => false,
				'restored' => false,
			);
		}

		$complete = true;
		try {
			switch_to_blog( $blog_id );
			$after = $this->context_snapshot();
			if ( null === $after || $after['blog_id'] !== $blog_id || count( $after['stack'] ) <= count( $before['stack'] ) ) {
				$complete = false;
			} else {
				$complete = $this->cleanup_current_blog();
			}
		} catch ( \Throwable ) {
			$complete = false;
		} finally {
			$restoration = $this->restore_context( $before );
		}

		return array(
			'complete' => $complete && $restoration['clean'] && $restoration['restored'],
			'restored' => $restoration['restored'],
		);
	}

	/**
	 * Restore all frames created after a context snapshot.
	 *
	 * @param array<string,mixed> $before Original context snapshot.
	 * @return array{clean:bool,restored:bool}
	 */
	private function restore_context( array $before ): array {
		$attempts = 0;
		$clean    = true;
		while ( true ) {
			$current = $this->context_snapshot();
			if ( null === $current || count( $current['stack'] ) < count( $before['stack'] ) ) {
				return array(
					'clean'    => false,
					'restored' => false,
				);
			}
			if ( count( $current['stack'] ) === count( $before['stack'] ) ) {
				return array(
					'clean'    => $clean,
					'restored' => $current === $before,
				);
			}
			if ( $attempts >= self::MAX_RESTORE_ATTEMPTS ) {
				return array(
					'clean'    => false,
					'restored' => false,
				);
			}

			$depth = count( $current['stack'] );
			++$attempts;
			try {
				restore_current_blog();
			} catch ( \Throwable ) {
				$clean = false;
			}

			$after = $this->context_snapshot();
			if ( null === $after || count( $after['stack'] ) >= $depth ) {
				return array(
					'clean'    => false,
					'restored' => false,
				);
			}
		}
	}

	/**
	 * Capture the WordPress multisite state needed to prove restoration.
	 *
	 * @return array<string,mixed>|null
	 */
	private function context_snapshot(): ?array {
		try {
			$fields = array( 'blogid', 'prefix', 'base_prefix', 'options', 'postmeta', 'blogs' );
			$wpdb   = array();
			foreach ( $fields as $field ) {
				if ( ! property_exists( $this->wpdb, $field ) ) {
					return null;
				}
				$value = $this->wpdb->{$field};
				if ( 'blogid' === $field ) {
					$value = $this->positive_database_id( $value );
					if ( null === $value ) {
						return null;
					}
				}
				$wpdb[ $field ] = $value;
			}

			if ( array_key_exists( '_wp_switched_stack', $GLOBALS ) && ! is_array( $GLOBALS['_wp_switched_stack'] ) ) {
				return null;
			}
			if ( ! array_key_exists( 'table_prefix', $GLOBALS ) ) {
				return null;
			}
			$stack    = $GLOBALS['_wp_switched_stack'] ?? array();
			$switched = $GLOBALS['switched'] ?? false;

			return array(
				'blog_id'      => get_current_blog_id(),
				'stack'        => $stack,
				'switched'     => $switched,
				'table_prefix' => $GLOBALS['table_prefix'],
				'wpdb'         => $wpdb,
			);
		} catch ( \Throwable ) {
			return null;
		}
	}

	/**
	 * Execute one prepared read with bounded row and shape validation.
	 *
	 * @param mixed         $prepared Prepared query returned by wpdb::prepare().
	 * @param array<string> $columns Exact selected columns.
	 * @param int           $limit Maximum expected rows.
	 * @return array<int,array<string,mixed>>|null
	 */
	private function read_rows( $prepared, array $columns, int $limit ): ?array {
		if ( ! is_string( $prepared ) && ! is_array( $prepared ) ) {
			return null;
		}

		try {
			$previous_suppress = $this->wpdb->suppress_errors( true );
			try {
				// Every caller constructs one of the three ADR-004 fixed read families.
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$rows       = $this->wpdb->get_results( $prepared, ARRAY_A );
				$last_error = (string) $this->wpdb->last_error;
			} finally {
				$this->wpdb->suppress_errors( $previous_suppress );
			}
		} catch ( \Throwable ) {
			return null;
		}

		if ( ! is_array( $rows ) || count( $rows ) > $limit || '' !== $last_error ) {
			return null;
		}

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! $this->has_exact_keys( $row, $columns ) ) {
				return null;
			}
		}

		return $rows;
	}

	/**
	 * Check whether a row contains exactly the selected columns.
	 *
	 * @param array<mixed>  $row Candidate row.
	 * @param array<string> $columns Expected keys.
	 */
	private function has_exact_keys( array $row, array $columns ): bool {
		$keys = array_keys( $row );
		sort( $keys );
		sort( $columns );

		return $keys === $columns;
	}

	/**
	 * Validate a canonical positive database identifier without lossy casting.
	 *
	 * @param mixed $value Candidate database ID.
	 */
	private function positive_database_id( $value ): ?int {
		if ( is_int( $value ) ) {
			return $value >= 1 ? $value : null;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A[1-9][0-9]*\z/', $value ) ) {
			return null;
		}

		$maximum = (string) PHP_INT_MAX;
		if ( strlen( $value ) > strlen( $maximum ) || ( strlen( $value ) === strlen( $maximum ) && strcmp( $value, $maximum ) > 0 ) ) {
			return null;
		}

		$result = (int) $value;
		return (string) $result === $value ? $result : null;
	}

	/**
	 * Check the closed, case-sensitive option-name allowlist.
	 *
	 * @param string $option_name Candidate option name.
	 */
	private function is_owned_option_name( string $option_name ): bool {
		return 1 === preg_match( self::IDEMPOTENCY_PATTERN, $option_name )
			|| 1 === preg_match( self::MEDIA_IDEMPOTENCY_PATTERN, $option_name )
			|| 1 === preg_match( self::AUDIT_LOCK_PATTERN, $option_name );
	}
}
