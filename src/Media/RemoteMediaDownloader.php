<?php
/**
 * Bounded, redirect-by-redirect remote image downloader.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Media;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Applies the independent SSRF and resource policy before Core ingestion. */
final class RemoteMediaDownloader implements RemoteMediaDownloaderInterface {
	private const MAX_BYTES     = 10485760;
	private const MAX_REDIRECTS = 3;
	private const MAX_SECONDS   = 15;

	/** URL and address policy.
	 *
	 * @var RemoteUrlPolicy
	 */
	private RemoteUrlPolicy $policy;
	/** DNS resolver seam.
	 *
	 * @var RemoteDnsResolverInterface
	 */
	private RemoteDnsResolverInterface $resolver;
	/** HTTP client seam.
	 *
	 * @var RemoteHttpClientInterface
	 */
	private RemoteHttpClientInterface $client;
	/** Monotonic clock seam used to enforce the whole-download deadline.
	 *
	 * @var RemoteClockInterface
	 */
	private RemoteClockInterface $clock;

	/**
	 * Create the downloader with injectable network seams.
	 *
	 * @param RemoteUrlPolicy|null            $policy URL policy.
	 * @param RemoteDnsResolverInterface|null $resolver DNS resolver.
	 * @param RemoteHttpClientInterface|null  $client HTTP client.
	 * @param RemoteClockInterface|null       $clock Monotonic clock.
	 */
	public function __construct( ?RemoteUrlPolicy $policy = null, ?RemoteDnsResolverInterface $resolver = null, ?RemoteHttpClientInterface $client = null, ?RemoteClockInterface $clock = null ) {
		$this->policy   = $policy ?? new RemoteUrlPolicy();
		$this->resolver = $resolver ?? new WordPressRemoteDnsResolver();
		$this->client   = $client ?? new WordPressRemoteHttpClient();
		$this->clock    = $clock ?? new SystemRemoteClock();
	}

	/**
	 * Download one image into a WordPress-owned temporary file.
	 *
	 * @param string $url Caller supplied URL.
	 * @return array{path:string,url:string}|WP_Error
	 */
	public function download( string $url ) {
		$current = $this->policy->normalize( $url );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if ( ! $this->load_file_functions() ) {
			return $this->rejected();
		}

		$limit = min( self::MAX_BYTES, (int) wp_max_upload_size() );
		if ( $limit < 1 ) {
			return $this->rejected();
		}

		$owned_files    = array();
		$preserved_file = null;
		$deadline       = $this->clock->now() + self::MAX_SECONDS;
		try {
			for ( $redirects = 0; $redirects <= self::MAX_REDIRECTS; ++$redirects ) {
				$remaining = (int) floor( $deadline - $this->clock->now() );
				if ( $remaining < 1 ) {
					return $this->rejected();
				}
				$parts = wp_parse_url( $current );
				if ( ! is_array( $parts ) || ! isset( $parts['host'] ) || ! is_string( $parts['host'] ) ) {
					return $this->rejected();
				}

				$addresses = $this->resolver->resolve( $parts['host'] );
				if ( array() === $addresses ) {
					return $this->rejected();
				}
				foreach ( $addresses as $address ) {
					if ( ! is_string( $address ) || ! $this->policy->is_public_address( $address ) ) {
						return $this->rejected();
					}
				}
				$remaining = (int) floor( $deadline - $this->clock->now() );
				if ( $remaining < 1 ) {
					return $this->rejected();
				}

				$temp = wp_tempnam( 'wp-auto-remote-image' );
				if ( ! is_string( $temp ) || '' === $temp ) {
					return $this->rejected();
				}
				$owned_files[] = $temp;

				$response = $this->client->request( $current, $addresses[0], $temp, $limit, min( 5, $remaining ) );
				if ( is_wp_error( $response ) || ! is_array( $response ) || ! isset( $response['status'], $response['headers'] ) || ! is_int( $response['status'] ) || ! is_array( $response['headers'] ) ) {
					return $this->rejected();
				}

				$status  = $response['status'];
				$headers = $response['headers'];
				if ( $status >= 300 && $status < 400 ) {
					if ( $redirects >= self::MAX_REDIRECTS || ! isset( $headers['location'] ) || ! is_string( $headers['location'] ) || '' === $headers['location'] ) {
						return $this->rejected();
					}
					$next = $this->resolve_location( $current, $headers['location'] );
					if ( is_wp_error( $next ) ) {
						return $this->rejected();
					}
					if ( ! $this->cleanup_temp( $temp ) ) {
						return $this->rejected();
					}
					array_pop( $owned_files );
					$current = $next;
					continue;
				}

				if ( $status < 200 || $status >= 300 ) {
					return $this->rejected();
				}
				if ( isset( $headers['content-encoding'] ) && '' !== trim( strtolower( $headers['content-encoding'] ) ) && 'identity' !== trim( strtolower( $headers['content-encoding'] ) ) ) {
					return $this->rejected();
				}

				$size = filesize( $temp );
				if ( ! is_int( $size ) || $size < 1 || $size > $limit ) {
					return $this->rejected();
				}
				if ( isset( $headers['content-length'] ) ) {
					if ( ! is_string( $headers['content-length'] ) || 1 !== preg_match( '/^[0-9]+$/D', trim( $headers['content-length'] ) ) || (int) $headers['content-length'] !== $size || (int) $headers['content-length'] > $limit ) {
						return $this->rejected();
					}
				}

				$preserved_file = $temp;
				return array(
					'path' => $temp,
					'url'  => $current,
				);
			}
		} catch ( \Throwable ) {
			return $this->rejected();
		} finally {
			foreach ( $owned_files as $owned_file ) {
				if ( $owned_file !== $preserved_file ) {
					$this->cleanup_temp( $owned_file );
				}
			}
		}

		return $this->rejected();
	}

	/** Load the Core file API required by front-end MCP/REST requests. */
	private function load_file_functions(): bool {
		if ( ! function_exists( 'wp_tempnam' ) ) {
			try {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			} catch ( \Throwable ) {
				return false;
			}
		}

		return function_exists( 'wp_tempnam' );
	}

	/**
	 * Resolve an RFC-style Location without trusting its destination.
	 *
	 * @param string $base Current normalized URL.
	 * @param string $location Response Location value.
	 * @return string|WP_Error
	 */
	private function resolve_location( string $base, string $location ) {
		$base_parts = wp_parse_url( $base );
		if ( ! is_array( $base_parts ) || ! isset( $base_parts['scheme'], $base_parts['host'] ) ) {
			return $this->rejected();
		}
		$location_parts = wp_parse_url( $location );
		if ( is_array( $location_parts ) && isset( $location_parts['scheme'] ) ) {
			$normalized = $this->policy->normalize( $location );
			return is_wp_error( $normalized ) ? $normalized : $normalized;
		}
		if ( str_starts_with( $location, '//' ) ) {
			$normalized = $this->policy->normalize( $base_parts['scheme'] . ':' . $location );
			return is_wp_error( $normalized ) ? $normalized : $normalized;
		}

		$scheme = (string) $base_parts['scheme'];
		$host   = (string) $base_parts['host'];
		$port   = isset( $base_parts['port'] ) ? ':' . (int) $base_parts['port'] : '';
		$path   = isset( $base_parts['path'] ) && is_string( $base_parts['path'] ) ? $base_parts['path'] : '/';
		if ( str_starts_with( $location, '/' ) ) {
			$resolved = $scheme . '://' . $host . $port . $location;
		} elseif ( str_starts_with( $location, '?' ) ) {
			$resolved = $scheme . '://' . $host . $port . $path . $location;
		} else {
			$directory = rtrim( str_replace( '\\', '/', dirname( $path ) ), '/' );
			$resolved  = $scheme . '://' . $host . $port . ( '' === $directory ? '/' : $directory . '/' ) . $location;
		}
		$normalized = $this->policy->normalize( $resolved );
		return is_wp_error( $normalized ) ? $normalized : $normalized;
	}

	/**
	 * Delete one service-owned temporary file and verify absence.
	 *
	 * @param string $path Temporary file path.
	 */
	private function cleanup_temp( string $path ): bool {
		if ( ! file_exists( $path ) ) {
			return true;
		}
		wp_delete_file( $path );
		return ! file_exists( $path );
	}

	/** Return the sanitized remote policy error. */
	private function rejected(): WP_Error {
		return new WP_Error( 'wp_auto_remote_media_rejected', __( 'The remote image request was rejected by policy.', 'wp-auto-connector' ), array( 'status' => 400 ) );
	}
}
