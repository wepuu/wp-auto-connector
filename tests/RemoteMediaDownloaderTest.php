<?php
/**
 * Bounded remote media downloader tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WP_Error;
use WPAuto\Connector\Media\RemoteDnsResolverInterface;
use WPAuto\Connector\Media\RemoteClockInterface;
use WPAuto\Connector\Media\RemoteHttpClientInterface;
use WPAuto\Connector\Media\RemoteMediaDownloader;

/** Covers redirects, DNS policy, byte bounds, and temporary-file cleanup. */
final class RemoteMediaDownloaderTest extends TestCase {
	/** Reset downloader fixtures. */
	protected function setUp(): void {
		$GLOBALS['wp_auto_test_max_upload_size']     = 10485760;
		$GLOBALS['wp_auto_test_tempnam_failure']     = false;
		$GLOBALS['wp_auto_test_delete_file_failure'] = false;
	}

	/** Follow one relative redirect and validate its destination independently. */
	public function test_follows_revalidated_relative_redirect(): void {
		$resolver = new class() implements RemoteDnsResolverInterface {
			/**
			 * Resolve the fixture host.
			 *
			 * @param string $host Host.
			 * @return array<int, string>
			 */
			public function resolve( string $host ): array {
				$addresses = 'images.example.com' === $host ? array( '93.184.216.34' ) : array();
				return $addresses;
			}
		};
		$client   = new class() implements RemoteHttpClientInterface {
			/** Number of requests made.
			 *
			 * @var int
			 */
			public int $calls = 0;

			/**
			 * Return the next fixture response.
			 *
			 * @param string $url URL.
			 * @param string $address Address.
			 * @param string $filename File.
			 * @param int    $byte_cap Cap.
			 * @param int    $timeout Remaining request timeout.
			 * @return array{status:int,headers:array<string,string>}|WP_Error
			 */
			public function request( string $url, string $address, string $filename, int $byte_cap, int $timeout ) {
				unset( $address, $byte_cap, $timeout );
				++$this->calls;
				if ( 1 === $this->calls ) {
					return array(
						'status'  => 302,
						'headers' => array( 'location' => '/final.png' ),
					);
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writes a deterministic fixture file.
				file_put_contents( $filename, 'png-bytes' );
				return array(
					'status'  => 200,
					'headers' => array( 'content-length' => '9' ),
				);
			}
		};

		$downloader = new RemoteMediaDownloader( null, $resolver, $client );
		$result     = $downloader->download( 'https://images.example.com/source.png' );

		self::assertIsArray( $result );
		self::assertSame( 'https://images.example.com/final.png', $result['url'] );
		self::assertSame( 2, $client->calls );
		self::assertFileExists( $result['path'] );
		wp_delete_file( $result['path'] );
	}

	/** Any private DNS answer rejects before making an HTTP request. */
	public function test_rejects_private_dns_answer_without_request(): void {
		$resolver = new class() implements RemoteDnsResolverInterface {
			/**
			 * Resolve the fixture host to a mixed answer set.
			 *
			 * @param string $host Host.
			 * @return array<int, string>
			 */
			public function resolve( string $host ): array {
				unset( $host );
				return array( '93.184.216.34', '127.0.0.1' );
			}
		};
		$client   = new class() implements RemoteHttpClientInterface {
			/** Number of calls made.
			 *
			 * @var int
			 */
			public int $calls = 0;

			/**
			 * Return a marker response if the policy calls the client.
			 *
			 * @param string $url URL.
			 * @param string $address Address.
			 * @param string $filename File.
			 * @param int    $byte_cap Cap.
			 * @param int    $timeout Remaining request timeout.
			 * @return array{status:int,headers:array<string,string>}|WP_Error
			 */
			public function request( string $url, string $address, string $filename, int $byte_cap, int $timeout ) {
				unset( $url, $address, $filename, $byte_cap, $timeout );
				++$this->calls;
				return new WP_Error( 'unexpected_request' );
			}
		};

		$error = ( new RemoteMediaDownloader( null, $resolver, $client ) )->download( 'https://images.example.com/a.png' );
		self::assertInstanceOf( WP_Error::class, $error );
		self::assertSame( 'wp_auto_remote_media_rejected', $error->get_error_code() );
		self::assertSame( 0, $client->calls );
	}

	/** A Content-Length mismatch rejects and removes the owned temporary file. */
	public function test_rejects_truncated_content_and_cleans_temp_file(): void {
		$paths    = array();
		$resolver = new class() implements RemoteDnsResolverInterface {
			/**
			 * Resolve the fixture host.
			 *
			 * @param string $host Host.
			 * @return array<int, string>
			 */
			public function resolve( string $host ): array {
				unset( $host );
				return array( '93.184.216.34' );
			}
		};
		$client   = new class( $paths ) implements RemoteHttpClientInterface {
			/** Captured temporary paths.
			 *
			 * @var array<int, string>
			 */
			public array $paths;

			/**
			 * Capture the shared path list.
			 *
			 * @param array<int, string> $paths Paths to capture.
			 */
			public function __construct( array &$paths ) {
				$this->paths =& $paths;
			}

			/**
			 * Write a short response with a false length header.
			 *
			 * @param string $url URL.
			 * @param string $address Address.
			 * @param string $filename File.
			 * @param int    $byte_cap Cap.
			 * @param int    $timeout Remaining request timeout.
			 * @return array{status:int,headers:array<string,string>}|WP_Error
			 */
			public function request( string $url, string $address, string $filename, int $byte_cap, int $timeout ) {
				unset( $url, $address, $byte_cap, $timeout );
				$this->paths[] = $filename;
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writes a deterministic fixture file.
				file_put_contents( $filename, 'short' );
				return array(
					'status'  => 200,
					'headers' => array( 'content-length' => '99' ),
				);
			}
		};

		$error = ( new RemoteMediaDownloader( null, $resolver, $client ) )->download( 'https://images.example.com/a.png' );
		self::assertInstanceOf( WP_Error::class, $error );
		self::assertSame( 'wp_auto_remote_media_rejected', $error->get_error_code() );
		self::assertNotEmpty( $paths );
		self::assertFileDoesNotExist( $paths[0] );
	}

	/** The whole redirect chain stops once its fixed fifteen-second budget expires. */
	public function test_rejects_redirect_chain_after_total_timeout(): void {
		$resolver = new class() implements RemoteDnsResolverInterface {
			/**
			 * Resolve the public fixture host.
			 *
			 * @param string $host Hostname.
			 */
			public function resolve( string $host ): array {
				unset( $host );
				return array( '93.184.216.34' );
			}
		};
		$client   = new class() implements RemoteHttpClientInterface {
			/** Requests reaching the transport seam.
			 *
			 * @var int
			 */
			public int $calls = 0;

			/**
			 * Return one redirect without writing a final response.
			 *
			 * @param string $url URL.
			 * @param string $address Address.
			 * @param string $filename File.
			 * @param int    $byte_cap Cap.
			 * @param int    $timeout Remaining request timeout.
			 * @return array{status:int,headers:array<string,string>}
			 */
			public function request( string $url, string $address, string $filename, int $byte_cap, int $timeout ) {
				unset( $url, $address, $filename, $byte_cap, $timeout );
				++$this->calls;
				return array(
					'status'  => 302,
					'headers' => array( 'location' => '/next.png' ),
				);
			}
		};
		$clock    = new class() implements RemoteClockInterface {
			/** Deterministic timestamps: start, first hop, then deadline.
			 *
			 * @var array<int, float>
			 */
			private array $times = array( 0.0, 0.0, 0.0, 15.0 );

			/** Return the next deterministic timestamp. */
			public function now(): float {
				return array_shift( $this->times ) ?? 15.0;
			}
		};

		$error = ( new RemoteMediaDownloader( null, $resolver, $client, $clock ) )->download( 'https://images.example.com/a.png' );
		self::assertInstanceOf( WP_Error::class, $error );
		self::assertSame( 'wp_auto_remote_media_rejected', $error->get_error_code() );
		self::assertSame( 1, $client->calls );
	}

	/** DNS resolution itself cannot consume the whole-download deadline. */
	public function test_rejects_when_dns_exhausts_total_timeout(): void {
		$resolver = new class() implements RemoteDnsResolverInterface {
			/**
			 * Return the public fixture address.
			 *
			 * @param string $host Hostname.
			 * @return array<int, string>
			 */
			public function resolve( string $host ): array {
				unset( $host );
				return array( '93.184.216.34' );
			}
		};
		$client   = new class() implements RemoteHttpClientInterface {
			/** Number of unexpected transport calls.
			 *
			 * @var int
			 */
			public int $calls = 0;

			/**
			 * Count any request that reaches the transport seam.
			 *
			 * @param string $url URL.
			 * @param string $address Address.
			 * @param string $filename File.
			 * @param int    $byte_cap Cap.
			 * @param int    $timeout Remaining request timeout.
			 * @return WP_Error
			 */
			public function request( string $url, string $address, string $filename, int $byte_cap, int $timeout ) {
				unset( $url, $address, $filename, $byte_cap, $timeout );
				++$this->calls;
				return new WP_Error( 'unexpected_request' );
			}
		};
		$clock    = new class() implements RemoteClockInterface {
			/** Start, pre-DNS, then post-DNS deadline timestamps.
			 *
			 * @var array<int, float>
			 */
			private array $times = array( 0.0, 0.0, 15.0 );

			/** Return the next deterministic timestamp. */
			public function now(): float {
				return array_shift( $this->times ) ?? 15.0;
			}
		};

		$error = ( new RemoteMediaDownloader( null, $resolver, $client, $clock ) )->download( 'https://images.example.com/a.png' );
		self::assertInstanceOf( WP_Error::class, $error );
		self::assertSame( 'wp_auto_remote_media_rejected', $error->get_error_code() );
		self::assertSame( 0, $client->calls );
	}

	/** A redirect pivot to a private host is rejected before a second request. */
	public function test_rejects_redirect_pivot_to_private_destination(): void {
		$resolver = new class() implements RemoteDnsResolverInterface {
			/**
			 * Resolve only the initial public host.
			 *
			 * @param string $host Hostname.
			 * @return array<int, string> Addresses.
			 */
			public function resolve( string $host ): array {
				return 'images.example.com' === $host ? array( '93.184.216.34' ) : array();
			}
		};
		$client   = new class() implements RemoteHttpClientInterface {
			/** Number of requests reaching the transport.
			 *
			 * @var int
			 */
			public int $calls = 0;

			/**
			 * Return a redirect into a private address space.
			 *
			 * @param string $url URL.
			 * @param string $address Resolved address.
			 * @param string $filename Temporary file.
			 * @param int    $byte_cap Byte limit.
			 * @param int    $timeout Request timeout.
			 * @return array{status:int,headers:array<string,string>} Response.
			 */
			public function request( string $url, string $address, string $filename, int $byte_cap, int $timeout ) {
				unset( $url, $address, $filename, $byte_cap, $timeout );
				++$this->calls;
				return array(
					'status'  => 302,
					'headers' => array( 'location' => 'http://127.0.0.1/private.png' ),
				);
			}
		};

		$error = ( new RemoteMediaDownloader( null, $resolver, $client ) )->download( 'https://images.example.com/source.png' );
		self::assertInstanceOf( WP_Error::class, $error );
		self::assertSame( 'wp_auto_remote_media_rejected', $error->get_error_code() );
		self::assertSame( 1, $client->calls );
	}

	/** More than three redirects are rejected and every redirect temp file is removed. */
	public function test_rejects_redirect_loop_after_three_hops(): void {
		$paths    = array();
		$resolver = new class() implements RemoteDnsResolverInterface {
			/**
			 * Resolve every fixture hostname to a public address.
			 *
			 * @param string $host Hostname.
			 * @return array<int, string> Addresses.
			 */
			public function resolve( string $host ): array {
				unset( $host );
				return array( '93.184.216.34' );
			}
		};
		$client   = new class( $paths ) implements RemoteHttpClientInterface {
			/** Number of requests.
			 *
			 * @var int
			 */
			public int $calls = 0;
			/** Owned temporary paths.
			 *
			 * @var array<int, string>
			 */
			public array $paths;

			/**
			 * Capture each redirect request path.
			 *
			 * @param array<int, string> $paths Paths to capture.
			 */
			public function __construct( array &$paths ) {
				$this->paths =& $paths;
			}

			/**
			 * Return a redirect loop.
			 *
			 * @param string $url URL.
			 * @param string $address Resolved address.
			 * @param string $filename Temporary file.
			 * @param int    $byte_cap Byte limit.
			 * @param int    $timeout Request timeout.
			 * @return array{status:int,headers:array<string,string>} Response.
			 */
			public function request( string $url, string $address, string $filename, int $byte_cap, int $timeout ) {
				unset( $url, $address, $byte_cap, $timeout );
				++$this->calls;
				$this->paths[] = $filename;
				return array(
					'status'  => 302,
					'headers' => array( 'location' => '/loop.png' ),
				);
			}
		};

		$error = ( new RemoteMediaDownloader( null, $resolver, $client ) )->download( 'https://images.example.com/source.png' );
		self::assertInstanceOf( WP_Error::class, $error );
		self::assertSame( 'wp_auto_remote_media_rejected', $error->get_error_code() );
		self::assertSame( 4, $client->calls );
		foreach ( $paths as $path ) {
			self::assertFileDoesNotExist( $path );
		}
	}

	/** Encoded responses are rejected even when the body and length otherwise match. */
	public function test_rejects_non_identity_content_encoding(): void {
		$paths    = array();
		$resolver = new class() implements RemoteDnsResolverInterface {
			/**
			 * Resolve the fixture host.
			 *
			 * @param string $host Hostname.
			 * @return array<int, string> Addresses.
			 */
			public function resolve( string $host ): array {
				unset( $host );
				return array( '93.184.216.34' );
			}
		};
		$client   = new class( $paths ) implements RemoteHttpClientInterface {
			/** Captured temporary paths.
			 *
			 * @var array<int, string>
			 */
			public array $paths;

			/**
			 * Capture the path list.
			 *
			 * @param array<int, string> $paths Paths to capture.
			 */
			public function __construct( array &$paths ) {
				$this->paths =& $paths;
			}

			/**
			 * Write a body with a forbidden content encoding.
			 *
			 * @param string $url URL.
			 * @param string $address Resolved address.
			 * @param string $filename Temporary file.
			 * @param int    $byte_cap Byte limit.
			 * @param int    $timeout Request timeout.
			 * @return array{status:int,headers:array<string,string>} Response.
			 */
			public function request( string $url, string $address, string $filename, int $byte_cap, int $timeout ) {
				unset( $url, $address, $byte_cap, $timeout );
				$this->paths[] = $filename;
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writes a deterministic fixture file.
				file_put_contents( $filename, 'png-bytes' );
				return array(
					'status'  => 200,
					'headers' => array(
						'content-length'   => '9',
						'content-encoding' => 'gzip',
					),
				);
			}
		};

		$error = ( new RemoteMediaDownloader( null, $resolver, $client ) )->download( 'https://images.example.com/a.png' );
		self::assertInstanceOf( WP_Error::class, $error );
		self::assertSame( 'wp_auto_remote_media_rejected', $error->get_error_code() );
		self::assertFileDoesNotExist( $paths[0] );
	}

	/** A streamed body over the effective site limit is rejected and cleaned. */
	public function test_rejects_body_over_effective_upload_limit(): void {
		$GLOBALS['wp_auto_test_max_upload_size'] = 4;
		$paths                                   = array();
		$resolver                                = new class() implements RemoteDnsResolverInterface {
			/**
			 * Resolve the fixture host.
			 *
			 * @param string $host Hostname.
			 * @return array<int, string> Addresses.
			 */
			public function resolve( string $host ): array {
				unset( $host );
				return array( '93.184.216.34' );
			}
		};
		$client                                  = new class( $paths ) implements RemoteHttpClientInterface {
			/** Captured temporary paths.
			 *
			 * @var array<int, string>
			 */
			public array $paths;

			/**
			 * Capture the path list.
			 *
			 * @param array<int, string> $paths Paths to capture.
			 */
			public function __construct( array &$paths ) {
				$this->paths =& $paths;
			}

			/**
			 * Write five bytes against a four-byte effective limit.
			 *
			 * @param string $url URL.
			 * @param string $address Resolved address.
			 * @param string $filename Temporary file.
			 * @param int    $byte_cap Byte limit.
			 * @param int    $timeout Request timeout.
			 * @return array{status:int,headers:array<string,string>} Response.
			 */
			public function request( string $url, string $address, string $filename, int $byte_cap, int $timeout ) {
				unset( $url, $address, $byte_cap, $timeout );
				$this->paths[] = $filename;
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writes a deterministic fixture file.
				file_put_contents( $filename, '12345' );
				return array(
					'status'  => 200,
					'headers' => array( 'content-length' => '5' ),
				);
			}
		};

		$error = ( new RemoteMediaDownloader( null, $resolver, $client ) )->download( 'https://images.example.com/a.png' );
		self::assertInstanceOf( WP_Error::class, $error );
		self::assertSame( 'wp_auto_remote_media_rejected', $error->get_error_code() );
		self::assertFileDoesNotExist( $paths[0] );
	}
}
