<?php
/**
 * Bounded WordPress-local revocation deny state.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\OAuth;

use WPAuto\Connector\Pairing\ConnectionSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Persists only hashed or already-pseudonymous deny markers. */
final class RevocationStateRepository {
	public const OPTION_NAME = 'wp_auto_connector_revocation_state';
	public const LOCK_NAME   = 'wp_auto_connector_revocation_lock';
	private const MAX_DENIES = 2048;

	/**
	 * Use the current pairing as the authoritative state binding.
	 *
	 * @param ConnectionSettings|null $settings Current pairing settings.
	 */
	public function __construct( private ?ConnectionSettings $settings = null ) {
		$this->settings = $this->settings ?? new ConnectionSettings();
	}

	/**
	 * Reset state only while completing an explicit pending pairing.
	 *
	 * @param string $site_id Newly assigned opaque site ID.
	 */
	public function initialize_for_pairing( string $site_id ): bool {
		$connection = $this->settings->load();
		if ( null === $connection || 'pending' !== $connection['status'] || ! self::valid_site_id( $site_id ) ) {
			return false;
		}
		if ( ! add_option( self::LOCK_NAME, array( 'acquired_at' => time() ), '', false ) ) {
			return false;
		}
		try {
			$state    = self::empty_state( $site_id );
			$existing = get_option( self::OPTION_NAME, null );
			$saved    = null === $existing
				? add_option( self::OPTION_NAME, $state, '', false )
				: update_option( self::OPTION_NAME, $state, false );
			return false !== $saved || get_option( self::OPTION_NAME, null ) === $state;
		} finally {
			delete_option( self::LOCK_NAME );
		}
	}

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
			$current = $this->load();
			if ( null === $current ) {
				return false;
			}
			$sequence = $event['sequence'] ?? null;
			$site_id  = $event['site_id'] ?? null;
			$digest   = hash( 'sha256', (string) wp_json_encode( $event ) );
			if ( ! is_string( $site_id ) || ! hash_equals( $current['site_id'], $site_id ) || ! is_int( $sequence ) || 1 > $sequence || $sequence < $current['last_sequence'] ) {
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
		return null === $state || $state['deny_all'] || isset( $state['grants'][ hash( 'sha256', $grant_id ) ] );
	}

	/**
	 * Check a token deny marker.
	 *
	 * @param string $jti_hash Platform-provided token-JTI digest.
	 */
	public function denies_token_hash( string $jti_hash ): bool {
		$state = $this->load();
		return null === $state || $state['deny_all'] || isset( $state['tokens'][ $jti_hash ] );
	}

	/**
	 * Check a key deny marker.
	 *
	 * @param string $kid Public signing-key identifier.
	 */
	public function denies_key( string $kid ): bool {
		$state = $this->load();
		return null === $state || $state['deny_all'] || isset( $state['keys'][ hash( 'sha256', $kid ) ] );
	}

	/**
	 * Load state, returning null for a fail-closed uncertainty.
	 *
	 * @return array{version:string,site_id:string,last_sequence:int,last_event_digest:string,deny_all:bool,grants:array<string,bool>,tokens:array<string,bool>,keys:array<string,bool>}|null
	 */
	private function load(): ?array {
		$connection = $this->settings->load();
		$site_id    = is_array( $connection ) && 'active' === $connection['status'] && isset( $connection['site_id'] )
			? $connection['site_id']
			: '';
		$empty      = self::empty_state( $site_id );
		if ( '' === $site_id ) {
			return null;
		}
		$sentinel = new \stdClass();
		$value    = get_option( self::OPTION_NAME, $sentinel );
		if ( $value === $sentinel ) {
			return null;
		}
		$legacy = array(
			'version'           => '1',
			'last_sequence'     => 0,
			'last_event_digest' => str_repeat( '0', 64 ),
			'deny_all'          => false,
			'grants'            => array(),
			'tokens'            => array(),
			'keys'              => array(),
		);
		if ( is_array( $value ) && array_keys( $value ) === array_keys( $legacy ) && '1' === $value['version'] && self::valid_payload( $value ) && ! isset( $connection['revocation_state_version'] ) ) {
			return array_merge( $empty, array_diff_key( $value, array( 'version' => true ) ) );
		}
		if ( ! is_array( $value ) || array_keys( $value ) !== array_keys( $empty ) || '2' !== $value['version'] || ! is_string( $value['site_id'] ) || ! hash_equals( $site_id, $value['site_id'] ) || ! self::valid_payload( $value ) ) {
			return null;
		}
		return $value;
	}

	/**
	 * Construct one empty state bound to an explicit site.
	 *
	 * @param string $site_id Opaque platform site ID.
	 * @return array{version:string,site_id:string,last_sequence:int,last_event_digest:string,deny_all:bool,grants:array<string,bool>,tokens:array<string,bool>,keys:array<string,bool>}
	 */
	private static function empty_state( string $site_id ): array {
		return array(
			'version'           => '2',
			'site_id'           => $site_id,
			'last_sequence'     => 0,
			'last_event_digest' => str_repeat( '0', 64 ),
			'deny_all'          => false,
			'grants'            => array(),
			'tokens'            => array(),
			'keys'              => array(),
		);
	}

	/**
	 * Validate the bounded deny-state payload shared by v1 and v2.
	 *
	 * @param array<string,mixed> $value Persisted state candidate.
	 */
	private static function valid_payload( array $value ): bool {
		if ( ! (
			is_int( $value['last_sequence'] ?? null )
			&& 0 <= $value['last_sequence']
			&& is_string( $value['last_event_digest'] ?? null )
			&& 1 === preg_match( '/\A[0-9a-f]{64}\z/', $value['last_event_digest'] )
			&& is_bool( $value['deny_all'] ?? null )
			&& is_array( $value['grants'] ?? null )
			&& is_array( $value['tokens'] ?? null )
			&& is_array( $value['keys'] ?? null )
		) ) {
			return false;
		}
		foreach ( array( $value['grants'], $value['tokens'], $value['keys'] ) as $markers ) {
			if ( count( $markers ) > self::MAX_DENIES ) {
				return false;
			}
			foreach ( $markers as $digest => $denied ) {
				if ( ! is_string( $digest ) || 1 !== preg_match( '/\A[0-9a-f]{64}\z/', $digest ) || true !== $denied ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Validate an opaque platform site identifier.
	 *
	 * @param string $site_id Candidate site ID.
	 */
	private static function valid_site_id( string $site_id ): bool {
		return 1 === preg_match( '/\A[A-Za-z0-9_-]{8,128}\z/', $site_id );
	}
}
