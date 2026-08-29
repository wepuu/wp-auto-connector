<?php
/**
 * Isolated WordPress function stubs for unit tests.
 *
 * @package WPAutoConnector
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'WP_AUTO_CONNECTOR_VERSION', '0.1.0-test' );
	define( 'WP_AUTO_CONNECTOR_DIR', dirname( __DIR__ ) . '/' );

	$GLOBALS['wp_auto_test_hooks']               = array();
	$GLOBALS['wp_auto_test_registered_ability']  = null;
	$GLOBALS['wp_auto_test_registered_category'] = null;
	$GLOBALS['wp_auto_test_can_read']            = false;
	$GLOBALS['wp_auto_test_logged_in']           = false;
	$GLOBALS['wp_auto_test_is_ssl']              = true;
	$GLOBALS['wp_auto_test_current_user_id']      = 0;
	$GLOBALS['wp_auto_test_capabilities']         = array();
	$GLOBALS['wp_auto_test_posts']                = array();
	$GLOBALS['wp_auto_test_terms']                = array();
	$GLOBALS['wp_auto_test_thumbnail_ids']        = array();
	$GLOBALS['wp_auto_test_last_query_args']      = array();
	$GLOBALS['wp_auto_test_site_info']            = array(
		'name'                => 'WP-Auto Test Site',
		'description'         => 'A safe connector test site.',
		'site_url'            => 'https://example.test/wordpress',
		'home_url'            => 'https://example.test',
		'language'            => 'en-US',
		'timezone'            => 'Asia/Shanghai',
		'permalink_structure' => '/%postname%/',
		'multisite'           => false,
	);

	class WP_Ability {}
	class WP_REST_Server {}
	class WP_Post {
		public int $ID;
		public string $post_type;
		public string $post_status;
		public string $post_name;
		public string $post_title;
		public string $post_excerpt;
		public string $post_content;
		public int $post_author;
		public string $post_date_gmt;
		public string $post_modified_gmt;

		public function __construct( array $data ) {
			$this->ID                 = (int) $data['ID'];
			$this->post_type          = (string) ( $data['post_type'] ?? 'post' );
			$this->post_status        = (string) ( $data['post_status'] ?? 'publish' );
			$this->post_name          = (string) ( $data['post_name'] ?? 'post-' . $this->ID );
			$this->post_title         = (string) ( $data['post_title'] ?? '' );
			$this->post_excerpt       = (string) ( $data['post_excerpt'] ?? '' );
			$this->post_content       = (string) ( $data['post_content'] ?? '' );
			$this->post_author        = (int) ( $data['post_author'] ?? 1 );
			$this->post_date_gmt      = (string) ( $data['post_date_gmt'] ?? '2026-01-01 00:00:00' );
			$this->post_modified_gmt  = (string) ( $data['post_modified_gmt'] ?? $this->post_date_gmt );
		}
	}

	class WP_Query {
		/** @var array<int, WP_Post> */
		public array $posts;

		public function __construct( array $args ) {
			$GLOBALS['wp_auto_test_last_query_args'] = $args;
			$posts = array_values(
				array_filter(
					$GLOBALS['wp_auto_test_posts'],
					static function ( WP_Post $post ) use ( $args ): bool {
						if ( $post->post_type !== $args['post_type'] || $post->post_status !== $args['post_status'] ) {
							return false;
						}

						if ( isset( $args['author'] ) && $post->post_author !== (int) $args['author'] ) {
							return false;
						}

						if ( isset( $args['post__in'] ) && ! in_array( $post->ID, $args['post__in'], true ) ) {
							return false;
						}

						if ( '' !== $args['s'] && false === stripos( $post->post_title . ' ' . $post->post_content, $args['s'] ) ) {
							return false;
						}

						if ( 'private' === $post->post_status && ! wp_auto_test_user_can( 'read_private_posts' ) ) {
							return $post->post_author === $GLOBALS['wp_auto_test_current_user_id'];
						}

						return true;
					}
				)
			);

			$property = array(
				'date'     => 'post_date_gmt',
				'modified' => 'post_modified_gmt',
				'title'    => 'post_title',
				'ID'       => 'ID',
			)[ $args['orderby'] ];
			usort(
				$posts,
				static function ( WP_Post $left, WP_Post $right ) use ( $property, $args ): int {
					$result = $left->{$property} <=> $right->{$property};
					return 'ASC' === $args['order'] ? $result : -$result;
				}
			);

			$this->posts = array_slice( $posts, $args['offset'], $args['posts_per_page'] );
		}
	}

	class WP_Error {
		private string $code;
		private string $message;
		private array $data;

		public function __construct( string $code, string $message, array $data = array() ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data(): array {
			return $this->data;
		}
	}

	function wp_register_ability(): void {}
	function wp_register_ability_category(): void {}
	function rest_get_server(): void {}

	function is_wp_error( $value ): bool {
		return $value instanceof WP_Error;
	}

	function wp_auto_test_user_can( string $capability, int $object_id = 0 ): bool {
		if ( 'read_post' !== $capability ) {
			return ! empty( $GLOBALS['wp_auto_test_capabilities'][ $capability ] );
		}

		$post = get_post( $object_id );
		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		if ( 'publish' === $post->post_status || $post->post_author === $GLOBALS['wp_auto_test_current_user_id'] ) {
			return wp_auto_test_user_can( 'read' );
		}

		if ( 'private' === $post->post_status ) {
			return wp_auto_test_user_can( 'read_private_posts' );
		}

		if ( 'future' === $post->post_status ) {
			return wp_auto_test_user_can( 'edit_others_posts' ) && wp_auto_test_user_can( 'edit_published_posts' );
		}

		return wp_auto_test_user_can( 'edit_others_posts' );
	}

	function get_post( int $post_id ) {
		foreach ( $GLOBALS['wp_auto_test_posts'] as $post ) {
			if ( $post->ID === $post_id ) {
				return $post;
			}
		}

		return null;
	}

	function get_post_type_object( string $post_type ) {
		if ( 'post' !== $post_type ) {
			return null;
		}

		return (object) array(
			'cap' => (object) array(
				'read_private_posts'  => 'read_private_posts',
				'edit_others_posts'   => 'edit_others_posts',
				'edit_published_posts' => 'edit_published_posts',
			),
		);
	}

	function get_current_user_id(): int {
		return $GLOBALS['wp_auto_test_current_user_id'];
	}

	function get_permalink( $post ) {
		$post_id = $post instanceof WP_Post ? $post->ID : (int) $post;
		return 'https://example.test/?p=' . $post_id;
	}

	function wp_get_post_terms( int $post_id, string $taxonomy, array $args ) {
		unset( $args );
		return $GLOBALS['wp_auto_test_terms'][ $post_id ][ $taxonomy ] ?? array();
	}

	function get_post_thumbnail_id( int $post_id ): int {
		return (int) ( $GLOBALS['wp_auto_test_thumbnail_ids'][ $post_id ] ?? 0 );
	}
}

namespace WP\MCP\Core {
	final class McpAdapter {
		public const VERSION = '0.6.1';
		public static int $instance_calls = 0;

		public static function instance(): self {
			++self::$instance_calls;
			return new self();
		}
	}
}

namespace WPAuto\Connector\Abilities\Site {
	function add_action( string $hook, callable $callback ): void {
		$GLOBALS['wp_auto_test_hooks'][ $hook ] = $callback;
	}

	function wp_register_ability( string $name, array $args ): void {
		$GLOBALS['wp_auto_test_registered_ability'] = array(
			'name' => $name,
			'args' => $args,
		);
	}

	function current_user_can( string $capability ): bool {
		return 'read' === $capability && $GLOBALS['wp_auto_test_can_read'];
	}

	function __( string $text ): string {
		return $text;
	}

	function get_bloginfo( string $show ): string {
		return (string) $GLOBALS['wp_auto_test_site_info'][ $show ];
	}

	function get_site_url(): string {
		return $GLOBALS['wp_auto_test_site_info']['site_url'];
	}

	function get_home_url(): string {
		return $GLOBALS['wp_auto_test_site_info']['home_url'];
	}

	function wp_timezone_string(): string {
		return $GLOBALS['wp_auto_test_site_info']['timezone'];
	}

	function get_option( string $option ) {
		return 'permalink_structure' === $option
			? $GLOBALS['wp_auto_test_site_info']['permalink_structure']
			: false;
	}

	function is_multisite(): bool {
		return $GLOBALS['wp_auto_test_site_info']['multisite'];
	}
}

namespace WPAuto\Connector\Abilities\Content {
	function add_action( string $hook, callable $callback ): void {
		$GLOBALS['wp_auto_test_hooks'][ $hook ] = $callback;
	}

	function wp_register_ability( string $name, array $args ): void {
		$GLOBALS['wp_auto_test_registered_ability'] = array(
			'name' => $name,
			'args' => $args,
		);
	}

	function wp_register_ability_category( string $slug, array $args ): void {
		$GLOBALS['wp_auto_test_registered_category'] = array(
			'slug' => $slug,
			'args' => $args,
		);
	}

	function current_user_can( string $capability, int $object_id = 0 ): bool {
		return \wp_auto_test_user_can( $capability, $object_id );
	}

	function __( string $text ): string {
		return $text;
	}
}

namespace WPAuto\Connector\Content {
	function current_user_can( string $capability, int $object_id = 0 ): bool {
		return \wp_auto_test_user_can( $capability, $object_id );
	}

	function get_post( int $post_id ) {
		return \get_post( $post_id );
	}

	function get_post_type_object( string $post_type ) {
		return \get_post_type_object( $post_type );
	}

	function get_current_user_id(): int {
		return \get_current_user_id();
	}

	function get_permalink( $post ) {
		return \get_permalink( $post );
	}

	function wp_get_post_terms( int $post_id, string $taxonomy, array $args ) {
		return \wp_get_post_terms( $post_id, $taxonomy, $args );
	}

	function get_post_thumbnail_id( int $post_id ): int {
		return \get_post_thumbnail_id( $post_id );
	}

	function is_wp_error( $value ): bool {
		return \is_wp_error( $value );
	}

	function __( string $text ): string {
		return $text;
	}
}

namespace WPAuto\Connector\Diagnostics {
	function get_bloginfo( string $show ): string {
		return 'version' === $show ? '6.9-test' : '';
	}

	function is_ssl(): bool {
		return $GLOBALS['wp_auto_test_is_ssl'];
	}
}

namespace WPAuto\Connector\Mcp {
	function add_action( string $hook, callable $callback ): void {
		$GLOBALS['wp_auto_test_hooks'][ $hook ] = $callback;
	}

	function is_user_logged_in(): bool {
		return $GLOBALS['wp_auto_test_logged_in'];
	}

	function current_user_can( string $capability ): bool {
		return 'read' === $capability && $GLOBALS['wp_auto_test_can_read'];
	}

	function __( string $text ): string {
		return $text;
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/src/Diagnostics/EnvironmentDiagnostics.php';
	require_once dirname( __DIR__ ) . '/src/Content/ContentReadService.php';
	require_once dirname( __DIR__ ) . '/src/Abilities/Site/SiteHealthAbility.php';
	require_once dirname( __DIR__ ) . '/src/Abilities/Site/SiteInfoAbility.php';
	require_once dirname( __DIR__ ) . '/src/Abilities/Content/ContentAbilityCategory.php';
	require_once dirname( __DIR__ ) . '/src/Abilities/Content/PostsSearchAbility.php';
	require_once dirname( __DIR__ ) . '/src/Abilities/Content/PostGetAbility.php';
	require_once dirname( __DIR__ ) . '/src/Mcp/McpAdapterLoader.php';
	require_once dirname( __DIR__ ) . '/src/Mcp/McpServerRegistrar.php';
}
