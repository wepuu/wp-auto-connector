<?php
/**
 * Remote URL and address policy tests.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Tests;

use PHPUnit\Framework\TestCase;
use WP_Error;
use WPAuto\Connector\Media\RemoteUrlPolicy;

/** Covers the independent SSRF destination policy. */
final class RemoteUrlPolicyTest extends TestCase {
	/** Policy under test.
	 *
	 * @var RemoteUrlPolicy
	 */
	private RemoteUrlPolicy $policy;

	/** Create a fresh policy. */
	protected function setUp(): void {
		$this->policy = new RemoteUrlPolicy();
	}

	/** Canonical URLs lower-case the scheme/host and remove default ports. */
	public function test_normalizes_public_url_shape(): void {
		self::assertSame( 'https://example.com/a.png?x=1', $this->policy->normalize( 'HTTPS://Example.COM:443/a.png?x=1' ) );
		self::assertSame( 'http://example.com/', $this->policy->normalize( 'http://Example.COM:80' ) );
	}

	/** Credentials, fragments, IP literals, ambiguous names, and ports are rejected by policy. */
	public function test_rejects_ambiguous_url_inputs(): void {
		$urls = array(
			'https://user:pass@example.com/a.png',
			'https://example.com/a.png#fragment',
			'https://127.0.0.1/a.png',
			'https://2130706433/a.png',
			'https://0177.0.0.1/a.png',
			'https://0x7f.0x0.0x0.0x1/a.png',
			'https://example.com:8080/a.png',
			'file:///tmp/a.png',
		);
		foreach ( $urls as $url ) {
			$error = $this->policy->normalize( $url );
			self::assertInstanceOf( WP_Error::class, $error );
			self::assertSame( 'wp_auto_remote_media_rejected', $error->get_error_code() );
		}
		self::assertSame( 'wp_auto_invalid_request', $this->policy->normalize( 'not-a-url' )->get_error_code() );
	}

	/** Public addresses are accepted while all fixed special-use ranges fail. */
	public function test_classifies_ipv4_and_ipv6_destinations(): void {
		$public = array( '8.8.8.8', '1.1.1.1', '2606:4700:4700::1111' );
		foreach ( $public as $address ) {
			self::assertTrue( $this->policy->is_public_address( $address ), $address );
		}

		$private = array( '0.0.0.0', '10.0.0.1', '100.64.0.1', '127.0.0.1', '169.254.1.1', '172.16.0.1', '192.0.2.1', '192.168.1.1', '198.18.0.1', '203.0.113.1', '224.0.0.1', '::1', '::ffff:127.0.0.1', 'fc00::1', 'fe80::1', '2001:db8::1', 'ff02::1' );
		foreach ( $private as $address ) {
			self::assertFalse( $this->policy->is_public_address( $address ), $address );
		}
	}
}
