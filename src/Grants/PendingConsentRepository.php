<?php
/**
 * One-shot, non-autoloaded local consent state.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Grants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Stores only verified claims; never stores the signed request itself. */
final class PendingConsentRepository {
	public const OPTION_PREFIX = 'wp_auto_connector_pending_consent_';
	public const LOCK_PREFIX   = 'wp_auto_connector_pending_consent_lock_';

	/**
	 * Store verified consent claims for one local user.
	 *
	 * @param array<string,mixed> $claims  Verified request claims.
	 * @param int                 $user_id Local user identifier.
	 * @throws \RuntimeException When state cannot be stored.
	 */
	public function create( array $claims, int $user_id ): string {
		if ( 1 > $user_id || ! isset( $claims['expires_at'] ) || ! is_int( $claims['expires_at'] ) ) {
			throw new \RuntimeException( 'invalid_pending_consent' );
		}
		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Cryptographic base64url handle.
			$handle = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
			$record = array(
				'version' => '1',
				'user_id' => $user_id,
				'claims'  => $claims,
			);
			if ( add_option( self::option_name( $handle ), $record, '', false ) ) {
				return $handle;
			}
		}
		throw new \RuntimeException( 'pending_consent_collision' );
	}

	/**
	 * Find unexpired state owned by one local user.
	 *
	 * @param string   $handle  Opaque state handle.
	 * @param int      $user_id Local user identifier.
	 * @param int|null $now     Optional test clock.
	 * @return array<string,mixed>|null
	 */
	public function find( string $handle, int $user_id, ?int $now = null ): ?array {
		if ( ! self::valid_handle( $handle ) || 1 > $user_id ) {
			return null;
		}
		$record = get_option( self::option_name( $handle ), null );
		if ( ! is_array( $record ) || '1' !== ( $record['version'] ?? null ) || ( $record['user_id'] ?? null ) !== $user_id || ! is_array( $record['claims'] ?? null ) ) {
			return null;
		}
		$expires = $record['claims']['expires_at'] ?? null;
		// phpcs:disable WordPress.PHP.YodaConditions.NotYoda -- Both operands are variables; this is an expiry comparison.
		if ( ! is_int( $expires ) || $expires <= ( $now ?? time() ) ) {
			return null;
		}
		// phpcs:enable WordPress.PHP.YodaConditions.NotYoda
		return $record['claims'];
	}

	/**
	 * Atomically consume pending state once.
	 *
	 * @param string   $handle  Opaque state handle.
	 * @param int      $user_id Local user identifier.
	 * @param int|null $now     Optional test clock.
	 * @return array<string,mixed>
	 * @throws \RuntimeException When state is invalid or already consumed.
	 */
	public function consume( string $handle, int $user_id, ?int $now = null ): array {
		$claims = $this->find( $handle, $user_id, $now );
		if ( null === $claims ) {
			throw new \RuntimeException( 'invalid_pending_consent' );
		}
		if ( ! add_option( self::lock_name( $handle ), array( 'used_at' => $now ?? time() ), '', false ) ) {
			throw new \RuntimeException( 'consent_already_used' );
		}
		$locked_claims = $this->find( $handle, $user_id, $now );
		if ( null === $locked_claims || ! hash_equals( self::claims_digest( $claims ), self::claims_digest( $locked_claims ) ) ) {
			throw new \RuntimeException( 'invalid_pending_consent' );
		}
		delete_option( self::option_name( $handle ) );
		$sentinel = new \stdClass();
		if ( get_option( self::option_name( $handle ), $sentinel ) !== $sentinel ) {
			throw new \RuntimeException( 'pending_consent_delete_failed' );
		}
		delete_option( self::lock_name( $handle ) );
		return $locked_claims;
	}

	/**
	 * Return the fixed-family pending option name.
	 *
	 * @param string $handle Opaque state handle.
	 */
	public static function option_name( string $handle ): string {
		return self::OPTION_PREFIX . hash( 'sha256', $handle );
	}

	/**
	 * Return the fixed-family one-shot lock name.
	 *
	 * @param string $handle Opaque state handle.
	 */
	public static function lock_name( string $handle ): string {
		return self::LOCK_PREFIX . hash( 'sha256', $handle );
	}

	/**
	 * Check an opaque handle before deriving option names.
	 *
	 * @param string $handle Opaque state handle.
	 */
	private static function valid_handle( string $handle ): bool {
		return 1 === preg_match( '/\A[A-Za-z0-9_-]{43}\z/', $handle );
	}

	/**
	 * Hash normalized pending claims for the post-lock consistency check.
	 *
	 * @param array<string,mixed> $claims Verified pending claims.
	 */
	private static function claims_digest( array $claims ): string {
		return hash( 'sha256', (string) wp_json_encode( $claims ) );
	}
}
