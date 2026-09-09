<?php
/**
 * WordPress HTTP API client with a pinned, direct destination.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Media;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Performs one redirect-disabled request and binds it to a validated address. */
final class WordPressRemoteHttpClient implements RemoteHttpClientInterface {
	/** Request URL being configured.
	 *
	 * @var string
	 */
	private string $request_url = '';

	/** Validated public address being pinned.
	 *
	 * @var string
	 */
	private string $request_address = '';

	/** Whether the curl transport accepted all required options.
	 *
	 * @var bool
	 */
	private bool $configured = false;

	/**
	 * Fetch a URL through the WordPress HTTP API.
	 *
	 * @param string $url       Normalized URL.
	 * @param string $address   Validated public address.
	 * @param string $filename  Temporary destination.
	 * @param int    $byte_cap  Effective byte cap.
	 * @param int    $timeout   Remaining whole-request timeout in seconds.
	 * @return array{status:int,headers:array<string,string>}|WP_Error
	 */
	public function request( string $url, string $address, string $filename, int $byte_cap, int $timeout ) {
		if ( $timeout < 1 || ! function_exists( 'wp_safe_remote_get' ) || ! function_exists( 'add_filter' ) || ! function_exists( 'remove_filter' ) || ! function_exists( 'curl_setopt' ) || ! defined( 'CURLOPT_RESOLVE' ) ) {
			return $this->rejected();
		}

		$this->request_url     = $url;
		$this->request_address = $address;
		$this->configured      = false;
		add_filter( 'http_api_curl', array( $this, 'configure_curl' ), PHP_INT_MAX, 3 );
		try {
			$response = wp_safe_remote_get(
				$url,
				array(
					'timeout'             => min( 5, $timeout ),
					'redirection'         => 0,
					'stream'              => true,
					'filename'            => $filename,
					'limit_response_size' => $byte_cap + 1,
					'reject_unsafe_urls'  => true,
					'headers'             => array(
						'Accept'          => 'image/*',
						'Accept-Encoding' => 'identity',
					),
				)
			);
		} catch ( \Throwable ) {
			$response = new WP_Error( 'wp_auto_remote_media_rejected' );
		} finally {
			remove_filter( 'http_api_curl', array( $this, 'configure_curl' ), PHP_INT_MAX );
		}

		if ( ! $this->configured || is_wp_error( $response ) ) {
			return $this->rejected();
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( ! is_int( $status ) || $status < 100 || $status > 599 ) {
			return $this->rejected();
		}

		$headers = wp_remote_retrieve_headers( $response );
		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$headers = $headers->getAll();
		}
		if ( ! is_array( $headers ) ) {
			$headers = array();
		}
		$normalized_headers = array();
		foreach ( $headers as $name => $value ) {
			if ( is_string( $name ) && ( is_string( $value ) || is_numeric( $value ) ) ) {
				$normalized_headers[ strtolower( $name ) ] = (string) $value;
			}
		}

		return array(
			'status'  => $status,
			'headers' => $normalized_headers,
		);
	}

	/**
	 * Pin the curl connection to the already validated address.
	 *
	 * @param resource|CurlHandle $handle Curl handle.
	 * @param array<string,mixed> $parsed_args WordPress HTTP arguments.
	 * @param string              $url Request URL.
	 * @return resource|\CurlHandle
	 */
	public function configure_curl( $handle, array $parsed_args, string $url ) {
		unset( $parsed_args );
		if ( $this->request_url !== $url || '' === $this->request_address ) {
			return $handle;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['host'], $parts['scheme'] ) || ! is_string( $parts['host'] ) || ! is_string( $parts['scheme'] ) ) {
			return $handle;
		}
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === strtolower( $parts['scheme'] ) ? 443 : 80 );
		$ip   = str_contains( $this->request_address, ':' ) ? '[' . $this->request_address . ']' : $this->request_address;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- CURLOPT_RESOLVE is the narrow WordPress HTTP API pinning seam required by ADR-005.
		$ok = curl_setopt( $handle, CURLOPT_RESOLVE, array( $parts['host'] . ':' . $port . ':' . $ip ) );
		if ( defined( 'CURLOPT_FOLLOWLOCATION' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Disable transport-level redirects; the service validates each hop.
			$ok = curl_setopt( $handle, CURLOPT_FOLLOWLOCATION, false ) && $ok;
		}
		if ( defined( 'CURLOPT_CONNECTTIMEOUT' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Bound connection establishment independently from transfer time.
			$ok = curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT, 5 ) && $ok;
		}
		if ( defined( 'CURLOPT_PROXY' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Prevent a caller-controlled proxy from bypassing the validated destination.
			$ok = curl_setopt( $handle, CURLOPT_PROXY, '' ) && $ok;
		}
		if ( defined( 'CURLOPT_NOPROXY' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Keep this fixed media request direct for DNS/peer consistency.
			$ok = curl_setopt( $handle, CURLOPT_NOPROXY, '*' ) && $ok;
		}
		$this->configured = (bool) $ok;
		return $handle;
	}

	/** Return the sanitized policy error. */
	private function rejected(): WP_Error {
		return new WP_Error( 'wp_auto_remote_media_rejected', __( 'The remote image request was rejected by policy.', 'wp-auto-connector' ), array( 'status' => 400 ) );
	}
}
