<?php
/**
 * Provider-neutral, permission-aware SEO reads.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Seo;

use WP_Error;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves one provider and exposes only the frozen five-field record.
 */
final class SeoReadService {
	/**
	 * Provider registry.
	 *
	 * @var SeoProviderRegistry
	 */
	private SeoProviderRegistry $registry;

	/**
	 * Set an optional isolated provider registry.
	 *
	 * @param SeoProviderRegistry|null $registry Optional isolated registry.
	 */
	public function __construct( ?SeoProviderRegistry $registry = null ) {
		$this->registry = $registry ?? new SeoProviderRegistry();
	}

	/** Enforce the Ability-layer baseline plus effective provider permission. */
	public function can_read(): bool {
		return current_user_can( 'read' ) && $this->registry->can_read();
	}

	/**
	 * Read one authorized Post/Page SEO record.
	 *
	 * @param mixed $input Raw Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function get( $input ) {
		if ( ! is_array( $input ) || array( 'id' ) !== array_keys( $input ) || ! is_int( $input['id'] ) || $input['id'] < 1 ) {
			return $this->invalid_request();
		}

		$provider = $this->registry->resolve();
		if ( $provider instanceof WP_Error ) {
			return $provider;
		}

		$post = get_post( $input['id'] );
		if ( ! $provider->can_read() || ! $this->is_readable_target( $post ) ) {
			return $this->not_found();
		}

		$state = $provider->read_state( $post->ID );
		if ( $state instanceof WP_Error ) {
			return $state;
		}

		$record        = array(
			'id'             => (int) $post->ID,
			'type'           => (string) $post->post_type,
			'status'         => (string) $post->post_status,
			'title'          => $state['title'],
			'description'    => $state['description'],
			'canonical_url'  => $state['canonical_url'],
			'focus_keywords' => $state['focus_keywords'],
			'robots'         => $state['robots'],
		);
		$token_payload = array(
			'site_id'          => get_current_blog_id(),
			'object'           => array(
				'id'     => $record['id'],
				'type'   => $record['type'],
				'status' => $record['status'],
			),
			'values'           => array_diff_key(
				$record,
				array(
					'id'     => true,
					'type'   => true,
					'status' => true,
				)
			),
			'protected'        => $state['protected'],
			'provider'         => $provider->key(),
			'adapter_version'  => $provider->adapter_version(),
			'provider_version' => $provider->runtime_version(),
		);
		$encoded       = wp_json_encode( $token_payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $encoded ) ) {
			return $this->unsupported_state();
		}

		$record['state_token'] = hash( 'sha256', $encoded );
		return $record;
	}

	/**
	 * Require a supported status/type and final Core object visibility.
	 *
	 * @param mixed $post Candidate object.
	 */
	private function is_readable_target( $post ): bool {
		if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) || ! in_array( $post->post_status, array( 'publish', 'draft', 'pending', 'private', 'future' ), true ) ) {
			return false;
		}
		if ( ! current_user_can( 'read_post', $post->ID ) ) {
			return false;
		}

		return '' === $post->post_password || current_user_can( 'edit_post', $post->ID );
	}

	/** Return the strict direct-service input error. */
	private function invalid_request(): WP_Error {
		return new WP_Error( 'wp_auto_invalid_request', __( 'The request parameters are invalid.', 'wepuu-auto-connector' ), array( 'status' => 400 ) );
	}

	/** Hide missing, wrong-type, status-rejected, and unauthorized objects. */
	private function not_found(): WP_Error {
		return new WP_Error( 'wp_auto_seo_not_found', __( 'The requested SEO object was not found.', 'wepuu-auto-connector' ), array( 'status' => 404 ) );
	}

	/** Hide any state-token encoding failure behind the frozen semantic error. */
	private function unsupported_state(): WP_Error {
		return new WP_Error( 'wp_auto_seo_state_unsupported', __( 'The stored SEO state is not supported.', 'wepuu-auto-connector' ), array( 'status' => 409 ) );
	}
}
