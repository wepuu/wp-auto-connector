<?php
/**
 * Permission-aware provider-neutral SEO updates.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Seo;

use WP_Error;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Implements the frozen Phase 1.6.2 SEO Update workflow. */
final class SeoUpdateService {
	private const ABILITY = 'wp-auto/seo-update';
	private const FIELDS  = array( 'title', 'description', 'canonical_url', 'focus_keywords', 'robots' );

	/**
	 * Provider registry.
	 *
	 * @var SeoProviderRegistry
	 */
	private SeoProviderRegistry $registry;
	/**
	 * Private bounded attribution store.
	 *
	 * @var SeoMutationAuditStore
	 */
	private SeoMutationAuditStore $audit;

	/**
	 * Create the update service.
	 *
	 * @param SeoProviderRegistry|null   $registry Optional provider registry.
	 * @param SeoMutationAuditStore|null $audit Optional audit store.
	 */
	public function __construct( ?SeoProviderRegistry $registry = null, ?SeoMutationAuditStore $audit = null ) {
		$this->registry = $registry ?? new SeoProviderRegistry();
		$this->audit    = $audit ?? new SeoMutationAuditStore();
	}

	/** Require the generic read identity and provider write permission. */
	public function can_update(): bool {
		return current_user_can( 'read' ) && $this->registry->can_write();
	}

	/**
	 * Validate and update an authorized draft's explicit SEO overrides.
	 *
	 * @param mixed $input Raw Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function update( $input ) {
		$normalized = $this->normalize_input( $input );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}
		try {
			return $this->perform_update( $normalized );
		} catch ( \Throwable ) {
			return $this->uncertain();
		}
	}

	/**
	 * Apply a previously normalized update request.
	 *
	 * @param array<string,mixed> $normalized Normalized request.
	 * @return array<string,mixed>|WP_Error
	 */
	private function perform_update( array $normalized ) {

		$actor_id = get_current_user_id();
		if ( $actor_id < 1 || ! current_user_can( 'read' ) ) {
			return $this->not_found();
		}

		$provider = $this->registry->resolve();
		if ( $provider instanceof WP_Error ) {
			return $provider;
		}
		if ( ! $provider->can_write() ) {
			return $this->not_found();
		}

		$post = get_post( $normalized['id'] );
		if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) || ! $this->has_edit_baseline( $post ) || ! current_user_can( 'read_post', $post->ID ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $this->not_found();
		}
		if ( 'draft' !== $post->post_status ) {
			return $this->status_conflict();
		}

		$current_state = $provider->read_state( $post->ID );
		if ( $current_state instanceof WP_Error ) {
			return $current_state;
		}
		$current_record = SeoReadService::record_from_state( $post, $current_state );
		$current_token  = SeoReadService::token_for( $post, $current_record, $current_state, $provider );
		if ( null === $current_token ) {
			return $this->unsupported_state();
		}

		$desired = $this->merge_state( $current_state, $normalized );
		$changed = $this->changed_fields( $current_record, $desired );
		if ( array() === $changed ) {
			$current_record['state_token']    = $current_token;
			$current_record['changed_fields'] = array();
			$current_record['no_op']          = true;
			return $current_record;
		}
		if ( ! hash_equals( $current_token, $normalized['expected_state_token'] ) ) {
			return $this->conflict();
		}

		$snapshot = $this->protected_snapshot( $post );
		if ( null === $snapshot ) {
			return $this->unsupported_state();
		}
		if ( get_current_user_id() !== $actor_id || ! $this->has_edit_baseline( $post ) || ! current_user_can( 'edit_post', $post->ID ) || ! $provider->can_write() ) {
			return $this->not_found();
		}

		$state_may_have_changed = true;
		try {
			$write_result = $provider->write_state( $post->ID, $desired, $changed );
		} catch ( \Throwable ) {
			$write_result = $this->write_failed();
		}

		$final_post  = get_post( $post->ID );
		$final_state = $final_post instanceof WP_Post ? $provider->read_state( $post->ID ) : $this->unsupported_state();
		$final_ok    = $final_post instanceof WP_Post && is_array( $final_state ) && $this->protected_snapshot_matches( $final_post, $snapshot ) && $this->state_matches( $final_state, $desired );
		if ( ! $final_ok ) {
			$proven_unapplied = $final_post instanceof WP_Post && is_array( $final_state ) && $this->protected_snapshot_matches( $final_post, $snapshot ) && $this->state_matches( $final_state, $current_state );
			if ( $proven_unapplied || ( ! $state_may_have_changed && ! is_wp_error( $write_result ) ) ) {
				return $this->write_failed();
			}
			return $this->uncertain();
		}
		$final_record = SeoReadService::record_from_state( $final_post, $final_state );
		$result_token = SeoReadService::token_for( $final_post, $final_record, $final_state, $provider );
		if ( null === $result_token ) {
			return $this->uncertain();
		}
		$final_record['state_token']    = $result_token;
		$final_record['changed_fields'] = $changed;
		$final_record['no_op']          = false;

		$event = array(
			'version'              => 1,
			'operation'            => 'update',
			'ability'              => self::ABILITY,
			'actor_user_id'        => $actor_id,
			'target_object_id'     => $final_post->ID,
			'timestamp_gmt'        => current_time( 'mysql', true ),
			'expected_state_token' => $normalized['expected_state_token'],
			'result_state_token'   => $result_token,
			'changed_fields'       => $changed,
		);
		if ( ! $this->audit->append( $final_post->ID, $event ) ) {
			return $this->uncertain();
		}

		return $final_record;
	}

	/**
	 * Normalize and strictly validate the public request.
	 *
	 * @param mixed $input Raw request.
	 * @return array<string,mixed>|WP_Error
	 */
	private function normalize_input( $input ) {
		if ( ! is_array( $input ) ) {
			return $this->invalid_request();
		}
		$allowed = array_merge( array( 'id', 'expected_state_token' ), self::FIELDS );
		if ( array_diff( array_keys( $input ), $allowed ) || ! array_key_exists( 'id', $input ) || ! array_key_exists( 'expected_state_token', $input ) || ! is_int( $input['id'] ) || $input['id'] < 1 || ! is_string( $input['expected_state_token'] ) || 1 !== preg_match( '/^[0-9a-f]{64}$/D', $input['expected_state_token'] ) || array() === array_intersect( self::FIELDS, array_keys( $input ) ) ) {
			return $this->invalid_request();
		}
		$limits = array(
			'title'         => 500,
			'description'   => 2000,
			'canonical_url' => 2048,
		);
		foreach ( $limits as $field => $maximum ) {
			if ( array_key_exists( $field, $input ) && ( ! is_string( $input[ $field ] ) || null === $this->bounded_text( $input[ $field ], $maximum ) || ( 'canonical_url' === $field && ! $this->valid_canonical( $input[ $field ] ) ) ) ) {
				return $this->invalid_request();
			}
		}
		if ( array_key_exists( 'focus_keywords', $input ) && ! $this->valid_keywords( $input['focus_keywords'] ) ) {
			return $this->invalid_request();
		}
		if ( array_key_exists( 'focus_keywords', $input ) ) {
			$keywords = array();
			foreach ( $input['focus_keywords'] as $keyword ) {
				$keywords[] = trim( $keyword );
			}
			$input['focus_keywords'] = $keywords;
		}
		if ( array_key_exists( 'robots', $input ) ) {
			$keys = is_array( $input['robots'] ) ? array_keys( $input['robots'] ) : array();
			sort( $keys );
			if ( ! is_array( $input['robots'] ) || array( 'follow', 'index' ) !== $keys || ! in_array( $input['robots']['index'] ?? null, array( 'default', 'index', 'noindex' ), true ) || ! in_array( $input['robots']['follow'] ?? null, array( 'default', 'follow', 'nofollow' ), true ) ) {
				return $this->invalid_request();
			}
		}
		return $input;
	}

	/**
	 * Merge only supplied fields onto the complete provider state.
	 *
	 * @param array<string,mixed> $current Current provider state.
	 * @param array<string,mixed> $input Normalized input.
	 */
	private function merge_state( array $current, array $input ): array {
		$desired = $current;
		foreach ( self::FIELDS as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$desired[ $field ] = $input[ $field ];
			}
		}
		return $desired;
	}

	/**
	 * Return changed fields in the frozen stable order.
	 *
	 * @param array<string,mixed> $current Current public record.
	 * @param array<string,mixed> $desired Desired state.
	 */
	private function changed_fields( array $current, array $desired ): array {
		return array_values( array_filter( self::FIELDS, static fn( string $field ): bool => ( $current[ $field ] ?? null ) !== ( $desired[ $field ] ?? null ) ) );
	}

	/**
	 * Verify the final provider values, including non-target robots state indirectly.
	 *
	 * @param array<string,mixed> $actual Final provider state.
	 * @param array<string,mixed> $desired Desired provider state.
	 */
	private function state_matches( array $actual, array $desired ): bool {
		foreach ( self::FIELDS as $field ) {
			if ( ( $actual[ $field ] ?? null ) !== ( $desired[ $field ] ?? null ) ) {
				return false;
			}
		}
		$strip_target = static function ( $raw ): ?array {
			if ( ! is_array( $raw ) ) {
				return null;
			}
			return array_values( array_filter( $raw, static fn( $directive ): bool => ! in_array( $directive, array( 'index', 'noindex', 'follow', 'nofollow' ), true ) ) );
		};
		$actual_raw   = $strip_target( $actual['protected']['raw_robots'] ?? null );
		$desired_raw  = $strip_target( $desired['protected']['raw_robots'] ?? null );
		return null !== $actual_raw && null !== $desired_raw && $actual_raw === $desired_raw;
	}

	/**
	 * Snapshot Core fields, taxonomy relationships, and featured media.
	 *
	 * @param WP_Post $post Target object.
	 */
	private function protected_snapshot( WP_Post $post ): ?array {
		$categories = wp_get_post_terms( $post->ID, 'category', array( 'fields' => 'ids' ) );
		$tags       = wp_get_post_terms( $post->ID, 'post_tag', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $categories ) || is_wp_error( $tags ) || ! is_array( $categories ) || ! is_array( $tags ) ) {
			return null;
		}
		$normalize = static function ( array $ids ): array {
			$ids = array_map( 'intval', $ids );
			sort( $ids, SORT_NUMERIC );
			return $ids;
		};
		return array(
			'post'       => get_object_vars( $post ),
			'categories' => $normalize( $categories ),
			'tags'       => $normalize( $tags ),
			'featured'   => (int) get_post_thumbnail_id( $post->ID ),
		);
	}

	/**
	 * Verify that no protected Core state moved during metadata writes.
	 *
	 * @param WP_Post             $post Final object.
	 * @param array<string,mixed> $snapshot Initial protected state.
	 */
	private function protected_snapshot_matches( WP_Post $post, array $snapshot ): bool {
		$current = $this->protected_snapshot( $post );
		return is_array( $current ) && $current === $snapshot;
	}

	/**
	 * Require the actual built-in Post/Page edit baseline capability.
	 *
	 * @param WP_Post $post Target object.
	 */
	private function has_edit_baseline( WP_Post $post ): bool {
		$post_type = get_post_type_object( $post->post_type );
		if ( ! is_object( $post_type ) || ! isset( $post_type->cap->edit_posts ) ) {
			return false;
		}
		return current_user_can( (string) $post_type->cap->edit_posts );
	}

	/**
	 * Validate a bounded UTF-8 plain value.
	 *
	 * @param mixed $value Candidate value.
	 * @param int   $maximum Maximum character count.
	 */
	private function bounded_text( $value, int $maximum ): ?string {
		if ( ! is_string( $value ) || 1 !== preg_match( '//u', $value ) || 1 === preg_match( '/[\x00-\x1F\x7F]/u', $value ) || 1 === preg_match( '/<[^>]*>/u', $value ) ) {
			return null;
		}
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
		return $length <= $maximum ? $value : null;
	}

	/**
	 * Validate the frozen absolute canonical URL syntax.
	 *
	 * @param string $url Candidate URL.
	 */
	private function valid_canonical( string $url ): bool {
		if ( '' === $url ) {
			return true;
		}
		$parts = wp_parse_url( $url );
		return is_array( $parts ) && isset( $parts['scheme'], $parts['host'] ) && in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true ) && '' !== (string) $parts['host'] && ! isset( $parts['user'] ) && ! isset( $parts['pass'] ) && ! isset( $parts['fragment'] );
	}

	/**
	 * Validate the bounded unique focus keyword array.
	 *
	 * @param mixed $keywords Candidate keywords.
	 */
	private function valid_keywords( $keywords ): bool {
		if ( ! is_array( $keywords ) || array_values( $keywords ) !== $keywords || count( $keywords ) > 5 ) {
			return false;
		}
		$seen = array();
		foreach ( $keywords as $keyword ) {
			if ( ! is_string( $keyword ) ) {
				return false;
			}
			$keyword = trim( $keyword );
			if ( '' === $keyword || false !== strpos( $keyword, ',' ) || null === $this->bounded_text( $keyword, 200 ) || in_array( $keyword, $seen, true ) ) {
				return false;
			}
			$seen[] = $keyword;
		}
		return true;
	}

	/** Return the stable invalid-input error. */
	private function invalid_request(): WP_Error {
		return new WP_Error( 'wp_auto_invalid_request', __( 'The request parameters are invalid.', 'wepuu-auto-connector' ), array( 'status' => 400 ) );
	}

	/** Hide inaccessible or unsupported objects. */
	private function not_found(): WP_Error {
		return new WP_Error( 'wp_auto_seo_not_found', __( 'The requested SEO object was not found.', 'wepuu-auto-connector' ), array( 'status' => 404 ) );
	}

	/** Return the authorized non-draft status conflict. */
	private function status_conflict(): WP_Error {
		return new WP_Error( 'wp_auto_seo_status_conflict', __( 'SEO updates are limited to drafts.', 'wepuu-auto-connector' ), array( 'status' => 409 ) );
	}

	/** Return the stale state-token conflict. */
	private function conflict(): WP_Error {
		return new WP_Error( 'wp_auto_seo_conflict', __( 'The SEO state changed after it was read.', 'wepuu-auto-connector' ), array( 'status' => 409 ) );
	}

	/** Return malformed provider state. */
	private function unsupported_state(): WP_Error {
		return new WP_Error( 'wp_auto_seo_state_unsupported', __( 'The stored SEO state is not supported.', 'wepuu-auto-connector' ), array( 'status' => 409 ) );
	}

	/** Return a proven-unapplied write error. */
	private function write_failed(): WP_Error {
		return new WP_Error( 'wp_auto_seo_write_failed', __( 'The SEO state could not be updated.', 'wepuu-auto-connector' ), array( 'status' => 500 ) );
	}

	/** Return a possible-partial-state error. */
	private function uncertain(): WP_Error {
		return new WP_Error( 'wp_auto_seo_state_uncertain', __( 'The SEO operation state could not be confirmed.', 'wepuu-auto-connector' ), array( 'status' => 500 ) );
	}
}
