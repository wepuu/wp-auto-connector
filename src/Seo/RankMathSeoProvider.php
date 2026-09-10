<?php
/**
 * Fixed Rank Math 1.0.278 SEO read adapter.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Seo;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads five explicit SEO fields through the WordPress Metadata API only.
 */
final class RankMathSeoProvider implements SeoProviderInterface {
	private const SUPPORTED_VERSION = '1.0.278';
	private const ADAPTER_VERSION   = '1';
	private const META_KEYS         = array(
		'title'          => 'rank_math_title',
		'description'    => 'rank_math_description',
		'canonical_url'  => 'rank_math_canonical_url',
		'focus_keywords' => 'rank_math_focus_keyword',
		'robots'         => 'rank_math_robots',
	);

	/**
	 * Explicit runtime version used by deterministic unit tests.
	 *
	 * @var string|null
	 */
	private ?string $fixed_runtime_version;

	/**
	 * Set an optional isolated runtime version.
	 *
	 * @param string|null $runtime_version Optional isolated runtime version.
	 */
	public function __construct( ?string $runtime_version = null ) {
		$this->fixed_runtime_version = $runtime_version;
	}

	/** Return the private internal provider key. */
	public function key(): string {
		return 'rank-math';
	}

	/** Return this adapter's state-token version. */
	public function adapter_version(): string {
		return self::ADAPTER_VERSION;
	}

	/** Return the observed provider runtime version. */
	public function runtime_version(): string {
		if ( null !== $this->fixed_runtime_version ) {
			return $this->fixed_runtime_version;
		}

		return defined( 'RANK_MATH_VERSION' ) ? (string) RANK_MATH_VERSION : '';
	}

	/** Require the admitted exact provider version and active bootstrap. */
	public function is_available(): bool {
		$bootstrap_active = null !== $this->fixed_runtime_version || defined( 'RANK_MATH_FILE' );
		return $bootstrap_active && self::SUPPORTED_VERSION === $this->runtime_version();
	}

	/** Match Rank Math's on-page general SEO permission. */
	public function can_read(): bool {
		return current_user_can( 'rank_math_onpage_general' );
	}

	/**
	 * Read and validate bounded explicit provider state.
	 *
	 * @param int $post_id Target Post or Page ID.
	 * @return array<string,mixed>|WP_Error
	 */
	public function read_state( int $post_id ) {
		try {
			$raw    = array();
			$exists = array();
			foreach ( self::META_KEYS as $field => $meta_key ) {
				$exists[ $field ] = metadata_exists( 'post', $post_id, $meta_key );
				$raw[ $field ]    = $exists[ $field ] ? get_post_meta( $post_id, $meta_key, true ) : '';
			}
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return $this->unsupported_state();
		}

		$title         = $this->bounded_text( $raw['title'], 500 );
		$description   = $this->bounded_text( $raw['description'], 2000 );
		$canonical_url = $this->bounded_text( $raw['canonical_url'], 2048 );
		$keywords      = $this->normalize_keywords( $raw['focus_keywords'] );
		$robots        = $this->normalize_robots( $raw['robots'] );

		if ( null === $title || null === $description || null === $canonical_url || ! $this->is_supported_canonical( $canonical_url ) || null === $keywords || null === $robots ) {
			return $this->unsupported_state();
		}

		return array(
			'title'          => $title,
			'description'    => $description,
			'canonical_url'  => $canonical_url,
			'focus_keywords' => $keywords,
			'robots'         => $robots['public'],
			'protected'      => array(
				'exists'     => $exists,
				'raw_robots' => $robots['raw'],
			),
		);
	}

	/**
	 * Require an empty inherited value or the frozen absolute URL shape.
	 *
	 * @param string $url Candidate canonical URL.
	 */
	private function is_supported_canonical( string $url ): bool {
		if ( '' === $url ) {
			return true;
		}

		$parts = wp_parse_url( $url );
		return is_array( $parts )
			&& isset( $parts['scheme'], $parts['host'] )
			&& in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true )
			&& '' !== (string) $parts['host']
			&& ! isset( $parts['user'] )
			&& ! isset( $parts['pass'] )
			&& ! isset( $parts['fragment'] );
	}

	/**
	 * Return a bounded valid UTF-8 plain string or null.
	 *
	 * @param mixed $value Candidate stored value.
	 * @param int   $maximum Maximum accepted character count.
	 */
	private function bounded_text( $value, int $maximum ): ?string {
		if ( ! is_string( $value ) || 1 !== preg_match( '//u', $value ) || 1 === preg_match( '/[\x00-\x1F\x7F]/u', $value ) || 1 === preg_match( '/<[^>]*>/u', $value ) ) {
			return null;
		}

		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
		return $length <= $maximum ? $value : null;
	}

	/**
	 * Return the provider comma list as the frozen unique keyword array.
	 *
	 * @param mixed $value Candidate stored value.
	 */
	private function normalize_keywords( $value ): ?array {
		if ( ! is_string( $value ) ) {
			return null;
		}
		if ( '' === $value ) {
			return array();
		}

		$keywords = array_map( 'trim', explode( ',', $value ) );
		if ( count( $keywords ) > 5 || count( array_unique( $keywords ) ) !== count( $keywords ) ) {
			return null;
		}
		foreach ( $keywords as $keyword ) {
			if ( '' === $keyword || null === $this->bounded_text( $keyword, 200 ) ) {
				return null;
			}
		}

		return $keywords;
	}

	/**
	 * Return the public index/follow pair while retaining other directives.
	 *
	 * @param mixed $value Candidate stored value.
	 */
	private function normalize_robots( $value ): ?array {
		if ( '' === $value ) {
			$value = array();
		}
		if ( ! is_array( $value ) || count( $value ) > 20 ) {
			return null;
		}

		$raw = array();
		foreach ( $value as $directive ) {
			if ( ! is_string( $directive ) || null === $this->bounded_text( $directive, 100 ) || '' === $directive ) {
				return null;
			}
			$raw[] = $directive;
		}
		if ( count( array_unique( $raw ) ) !== count( $raw ) ) {
			return null;
		}

		$has_index    = in_array( 'index', $raw, true );
		$has_noindex  = in_array( 'noindex', $raw, true );
		$has_follow   = in_array( 'follow', $raw, true );
		$has_nofollow = in_array( 'nofollow', $raw, true );
		if ( ( $has_index && $has_noindex ) || ( $has_follow && $has_nofollow ) ) {
			return null;
		}

		return array(
			'public' => array(
				'index'  => $has_noindex ? 'noindex' : ( $has_index ? 'index' : 'default' ),
				'follow' => $has_nofollow ? 'nofollow' : ( $has_follow ? 'follow' : 'default' ),
			),
			'raw'    => $raw,
		);
	}

	/** Return the stable malformed-provider-state error. */
	private function unsupported_state(): WP_Error {
		return new WP_Error(
			'wp_auto_seo_state_unsupported',
			__( 'The stored SEO state is not supported.', 'wepuu-auto-connector' ),
			array( 'status' => 409 )
		);
	}
}
