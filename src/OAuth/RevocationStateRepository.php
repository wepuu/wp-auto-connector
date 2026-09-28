<?php
/**
 * Bounded WordPress-local revocation deny state.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Persists only hashed or already-pseudonymous deny markers. */
final class RevocationStateRepository {
	public const OPTION_NAME = 'wp_auto_connector_revocation_state';
	public const LOCK_NAME   = 'wp_auto_connector_revocation_lock';
	private const MAX_DENIES = 2048;

	/**
	 * Apply an already verified event monotonically.
	 *
	 * @param array<string,mixed> $event Verified revocation claims.
	 */
	public function apply( array $event ): bool {
		if ( ! add_option( self::LOCK_NAME, array( 'acquired_at' => time() ), '', false ) ) {
			return false;
		}
		try {
			$current  = $this->load();
			$sequence = $event['sequence'] ?? null;
			$digest   = hash( 'sha256', (string) wp_json_encode( $event ) );
			if ( ! is_int( $sequence ) || 1 > $sequence || $sequence < $current['last_sequence'] ) {
				return false;
			}
			if ( $sequence === $current['last_sequence'] ) {
				return hash_equals( $current['last_event_digest'], $digest );
			}
			$type = $event['event_type'] ?? '';
			if ( 'grant' === $type ) {
				$current['grants'][ hash( 'sha256', (string) $event['grant_id'] ) ] = true;
			} elseif ( 'token' === $type ) {
				$current['tokens'][ (string) $event['token_jti_hash'] ] = true;
			} elseif ( 'key' === $type ) {
				$current['keys'][ hash( 'sha256', (string) $event['key_id'] ) ] = true;
			} elseif ( in_array( $type, array( 'site', 'subject' ), true ) ) {
				$current['deny_all'] = true;
			} else {
				return false;
			}
			if ( count( $current['grants'] ) + count( $current['tokens'] ) + count( $current['keys'] ) > self::MAX_DENIES ) {
				$current['deny_all'] = true;
				$current['grants']   = array();
				$current['tokens']   = array();
				$current['keys']     = array();
			}
			$current['last_sequence']     = $sequence;
			$current['last_event_digest'] = $digest;
			$existing                     = get_option( self::OPTION_NAME, null );
			$saved                        = null === $existing
				? add_option( self::OPTION_NAME, $current, '', false )
				: update_option( self::OPTION_NAME, $current, false );
			return false !== $saved || get_option( self::OPTION_NAME, null ) === $current;
		} finally {
			delete_option( self::LOCK_NAME );
		}
	}

	/**
	 * Check a grant deny marker.
	 *
	 * @param string $grant_id Opaque grant ID.
	 */
	public function denies_grant( string $grant_id ): bool {
		$state = $this->load();
		return $state['deny_all'] || isset( $state['grants'][ hash( 'sha256', $grant_id ) ] );
	}

	/**
	 * Check a token deny marker.
	 *
	 * @param string $jti_hash Platform-provided token-JTI digest.
	 */
	public function denies_token_hash( string $jti_hash ): bool {
		$state = $this->load();
		return $state['deny_all'] || isset( $state['tokens'][ $jti_hash ] );
	}

	/**
	 * Check a key deny marker.
	 *
	 * @param string $kid Public signing-key identifier.
	 */
	public function denies_key( string $kid ): bool {
		$state = $this->load();
		return $state['deny_all'] || isset( $state['keys'][ hash( 'sha256', $kid ) ] );
	}

	/**
	 * Load state, returning deny-all for malformed persisted data.
	 *
	 * @return array{version:string,last_sequence:int,last_event_digest:string,deny_all:bool,grants:array<string,bool>,tokens:array<string,bool>,keys:array<string,bool>}
	 */
	private function load(): array {
		$empty = array(
			'version'           => '1',
			'last_sequence'     => 0,
			'last_event_digest' => str_repeat( '0', 64 ),
			'deny_all'          => false,
			'grants'            => array(),
			'tokens'            => array(),
			'keys'              => array(),
		);
		$value = get_option( self::OPTION_NAME, $empty );
		if ( ! is_array( $value ) || array_keys( $value ) !== array_keys( $empty ) || '1' !== $value['version'] || ! is_int( $value['last_sequence'] ) || ! is_string( $value['last_event_digest'] ) || ! is_bool( $value['deny_all'] ) || ! is_array( $value['grants'] ) || ! is_array( $value['tokens'] ) || ! is_array( $value['keys'] ) ) {
			return array_merge( $empty, array( 'deny_all' => true ) );
		}
		return $value;
	}
}
