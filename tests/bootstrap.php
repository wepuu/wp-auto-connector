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
	$GLOBALS['wp_auto_test_hook_history']        = array();
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
	$GLOBALS['wp_auto_test_query_args_history']   = array();
	$GLOBALS['wp_auto_test_object_capabilities']  = array();
	$GLOBALS['wp_auto_test_filters']              = array();
	$GLOBALS['wp_auto_test_taxonomy_terms']       = array();
	$GLOBALS['wp_auto_test_get_terms_error']      = null;
	$GLOBALS['wp_auto_test_last_term_query_args'] = array();
	$GLOBALS['wp_auto_test_term_query_history']   = array();
	$GLOBALS['wp_auto_test_last_term_clauses']    = array();
	$GLOBALS['wp_auto_test_options']              = array();
	$GLOBALS['wp_auto_test_option_autoload']      = array();
	$GLOBALS['wp_auto_test_option_cache']         = array();
	$GLOBALS['wp_auto_test_notoptions_cache']     = null;
	$GLOBALS['wp_auto_test_alloptions_cache']     = null;
	$GLOBALS['wp_auto_test_use_option_cache']     = false;
	$GLOBALS['wp_auto_test_cache_delete_exception'] = null;
	$GLOBALS['wp_auto_test_cache_delete_history'] = array();
	$GLOBALS['wp_auto_test_db_query_calls']       = 0;
	$GLOBALS['wp_auto_test_db_query_exception']   = null;
	$GLOBALS['wp_auto_test_db_query_after_write_exception'] = null;
	$GLOBALS['wp_auto_test_db_last_error']        = '';
	$GLOBALS['wp_auto_test_db_return_override']   = null;
	$GLOBALS['wp_auto_test_uuid_counter']         = 0;
	$GLOBALS['wp_auto_test_db_prepare_exception'] = null;
	$GLOBALS['wp_auto_test_db_suppress_state']    = false;
	$GLOBALS['wp_auto_test_db_suppress_history']  = array();
	$GLOBALS['wp_auto_test_db_prepared_queries']  = array();
	$GLOBALS['wp_auto_test_before_db_query']      = null;
	$GLOBALS['wp_auto_test_cache_delete_return_override'] = null;
	$GLOBALS['wp_auto_test_update_meta_exception_after_write'] = null;
	$GLOBALS['wp_auto_test_post_meta']            = array();
	$GLOBALS['wp_auto_test_post_meta_values']     = array();
	$GLOBALS['wp_auto_test_next_post_id']         = 1000;
	$GLOBALS['wp_auto_test_last_insert_args']     = array();
	$GLOBALS['wp_auto_test_insert_result']        = null;
	$GLOBALS['wp_auto_test_insert_exception']     = null;
	$GLOBALS['wp_auto_test_insert_calls']         = 0;
	$GLOBALS['wp_auto_test_last_update_args']     = array();
	$GLOBALS['wp_auto_test_update_result']        = null;
	$GLOBALS['wp_auto_test_update_exception']     = null;
	$GLOBALS['wp_auto_test_update_after_exception'] = null;
	$GLOBALS['wp_auto_test_next_modified_gmt']    = '2026-09-01 12:00:01';
	$GLOBALS['wp_auto_test_get_post_calls']        = 0;
	$GLOBALS['wp_auto_test_before_get_post']       = null;
	$GLOBALS['wp_auto_test_get_post_exception']    = null;
	$GLOBALS['wp_auto_test_get_post_exception_on_call'] = null;
	$GLOBALS['wp_auto_test_add_option_exception'] = null;
	$GLOBALS['wp_auto_test_add_option_exception_after_write'] = null;
	$GLOBALS['wp_auto_test_fail_update_option']   = false;
	$GLOBALS['wp_auto_test_fail_update_option_on_call'] = null;
	$GLOBALS['wp_auto_test_update_option_exception_on_call'] = null;
	$GLOBALS['wp_auto_test_update_option_calls']   = 0;
	$GLOBALS['wp_auto_test_fail_delete_option']   = false;
	$GLOBALS['wp_auto_test_delete_option_exception'] = null;
	$GLOBALS['wp_auto_test_delete_option_calls']  = 0;
	$GLOBALS['wp_auto_test_fail_update_meta']     = false;
	$GLOBALS['wp_auto_test_update_meta_exception'] = null;
	$GLOBALS['wp_auto_test_before_update_meta']   = null;
	$GLOBALS['wp_auto_test_update_meta_calls']    = 0;
	$GLOBALS['wp_auto_test_permalink_exception']  = null;
	$GLOBALS['wp_auto_test_edit_link_exception']  = null;
	$GLOBALS['wp_auto_test_get_post_meta_exception'] = null;
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
	class wpdb {
		public string $options = 'wp_options';
		public int $rows_affected = 0;
		public string $last_error = '';

		public function suppress_errors( $suppress = null ): bool {
			$previous = (bool) $GLOBALS['wp_auto_test_db_suppress_state'];
			if ( null !== $suppress ) {
				$GLOBALS['wp_auto_test_db_suppress_state']   = (bool) $suppress;
				$GLOBALS['wp_auto_test_db_suppress_history'][] = (bool) $suppress;
			}

			return $previous;
		}

		public function prepare( string $query, ...$args ) {
			if ( $GLOBALS['wp_auto_test_db_prepare_exception'] instanceof \Throwable ) {
				$exception = $GLOBALS['wp_auto_test_db_prepare_exception'];
				$GLOBALS['wp_auto_test_db_prepare_exception'] = null;
				throw $exception;
			}

			$prepared = array( 'query' => $query, 'args' => $args );
			$GLOBALS['wp_auto_test_db_prepared_queries'][] = $prepared;
			return $prepared;
		}

		public function query( $prepared ) {
			++$GLOBALS['wp_auto_test_db_query_calls'];
			$this->rows_affected = 0;
			$this->last_error    = '';

			if ( is_callable( $GLOBALS['wp_auto_test_before_db_query'] ) ) {
				( $GLOBALS['wp_auto_test_before_db_query'] )( $prepared, $this );
			}
			if ( $GLOBALS['wp_auto_test_db_query_exception'] instanceof \Throwable ) {
				$exception = $GLOBALS['wp_auto_test_db_query_exception'];
				$GLOBALS['wp_auto_test_db_query_exception'] = null;
				throw $exception;
			}
			if ( null !== $GLOBALS['wp_auto_test_db_return_override'] ) {
				return $GLOBALS['wp_auto_test_db_return_override'];
			}

			if ( ! is_array( $prepared ) || ! isset( $prepared['query'], $prepared['args'] ) ) {
				$this->last_error = 'invalid prepared query';
				return false;
			}

			$args = $prepared['args'];
			if ( false !== stripos( $prepared['query'], 'INSERT IGNORE' ) ) {
				if ( $GLOBALS['wp_auto_test_add_option_exception'] instanceof \Throwable ) {
					$exception = $GLOBALS['wp_auto_test_add_option_exception'];
					$GLOBALS['wp_auto_test_add_option_exception'] = null;
					throw $exception;
				}
				$name = (string) ( $args[0] ?? '' );
				if ( array_key_exists( $name, $GLOBALS['wp_auto_test_options'] ) ) {
					$this->rows_affected = 0;
					$this->last_error    = (string) $GLOBALS['wp_auto_test_db_last_error'];
					return 0;
				}

				$GLOBALS['wp_auto_test_options'][ $name ]         = maybe_unserialize( $args[1] ?? '' );
				$GLOBALS['wp_auto_test_option_autoload'][ $name ] = (string) ( $args[2] ?? '' );
				$this->rows_affected = 1;
				if ( $GLOBALS['wp_auto_test_add_option_exception_after_write'] instanceof \Throwable ) {
					$exception = $GLOBALS['wp_auto_test_add_option_exception_after_write'];
					$GLOBALS['wp_auto_test_add_option_exception_after_write'] = null;
					throw $exception;
				}
				if ( $GLOBALS['wp_auto_test_db_query_after_write_exception'] instanceof \Throwable ) {
					$exception = $GLOBALS['wp_auto_test_db_query_after_write_exception'];
					$GLOBALS['wp_auto_test_db_query_after_write_exception'] = null;
					throw $exception;
				}

				$this->last_error = (string) $GLOBALS['wp_auto_test_db_last_error'];
				return 1;
			}

			if ( false !== stripos( $prepared['query'], 'DELETE FROM' ) ) {
				++$GLOBALS['wp_auto_test_delete_option_calls'];
				if ( $GLOBALS['wp_auto_test_delete_option_exception'] instanceof \Throwable ) {
					$exception = $GLOBALS['wp_auto_test_delete_option_exception'];
					$GLOBALS['wp_auto_test_delete_option_exception'] = null;
					throw $exception;
				}
				if ( $GLOBALS['wp_auto_test_fail_delete_option'] ) {
					$this->last_error = (string) $GLOBALS['wp_auto_test_db_last_error'];
					return 0;
				}

				$name       = (string) ( $args[0] ?? '' );
				$serialized = (string) ( $args[1] ?? '' );
				if ( ! array_key_exists( $name, $GLOBALS['wp_auto_test_options'] ) || maybe_serialize( $GLOBALS['wp_auto_test_options'][ $name ] ) !== $serialized ) {
					$this->last_error = (string) $GLOBALS['wp_auto_test_db_last_error'];
					return 0;
				}

				unset( $GLOBALS['wp_auto_test_options'][ $name ], $GLOBALS['wp_auto_test_option_autoload'][ $name ] );
				$this->rows_affected = 1;
				$this->last_error    = (string) $GLOBALS['wp_auto_test_db_last_error'];
				return 1;
			}

			$this->last_error = 'unsupported query';
			return false;
		}
	}
	$GLOBALS['wpdb'] = new wpdb();
	class WP_Term {
		public int $term_id;
		public string $name;
		public string $slug;
		public string $description;
		public int $count;
		public int $parent;
		public string $taxonomy;

		public function __construct( array $data ) {
			$this->term_id     = (int) $data['term_id'];
			$this->name        = (string) ( $data['name'] ?? '' );
			$this->slug        = (string) ( $data['slug'] ?? '' );
			$this->description = (string) ( $data['description'] ?? '' );
			$this->count       = (int) ( $data['count'] ?? 0 );
			$this->parent      = (int) ( $data['parent'] ?? 0 );
			$this->taxonomy    = (string) ( $data['taxonomy'] ?? 'category' );
		}
	}
	class WP_Post {
		public int $ID;
		public string $post_type;
		public string $post_status;
		public string $post_name;
		public string $post_title;
		public string $post_excerpt;
		public string $post_content;
		public string $post_password;
		public int $post_author;
		public int $post_parent;
		public string $post_date;
		public string $post_date_gmt;
		public string $post_modified_gmt;
		public string $comment_status;
		public string $ping_status;
		public int $menu_order;
		public string $post_mime_type;
		public string $guid;
		public string $to_ping;
		public string $pinged;
		public string $post_content_filtered;

		public function __construct( array $data ) {
			$this->ID                 = (int) $data['ID'];
			$this->post_type          = (string) ( $data['post_type'] ?? 'post' );
			$this->post_status        = (string) ( $data['post_status'] ?? 'publish' );
			$this->post_name          = (string) ( $data['post_name'] ?? 'post-' . $this->ID );
			$this->post_title         = (string) ( $data['post_title'] ?? '' );
			$this->post_excerpt       = (string) ( $data['post_excerpt'] ?? '' );
			$this->post_content       = (string) ( $data['post_content'] ?? '' );
			$this->post_password      = (string) ( $data['post_password'] ?? '' );
			$this->post_author        = (int) ( $data['post_author'] ?? 1 );
			$this->post_parent        = (int) ( $data['post_parent'] ?? 0 );
			$this->post_date          = (string) ( $data['post_date'] ?? '2026-01-01 08:00:00' );
			$this->post_date_gmt      = (string) ( $data['post_date_gmt'] ?? '2026-01-01 00:00:00' );
			$this->post_modified_gmt  = (string) ( $data['post_modified_gmt'] ?? $this->post_date_gmt );
			$this->comment_status     = (string) ( $data['comment_status'] ?? 'closed' );
			$this->ping_status        = (string) ( $data['ping_status'] ?? 'closed' );
			$this->menu_order         = (int) ( $data['menu_order'] ?? 0 );
			$this->post_mime_type     = (string) ( $data['post_mime_type'] ?? '' );
			$this->guid               = (string) ( $data['guid'] ?? 'https://example.test/?p=' . $this->ID );
			$this->to_ping            = (string) ( $data['to_ping'] ?? '' );
			$this->pinged             = (string) ( $data['pinged'] ?? '' );
			$this->post_content_filtered = (string) ( $data['post_content_filtered'] ?? '' );
		}
	}

	class WP_Query {
		/** @var array<int, WP_Post> */
		public array $posts;

		public function __construct( array $args ) {
			$GLOBALS['wp_auto_test_last_query_args'] = $args;
			$GLOBALS['wp_auto_test_query_args_history'][] = $args;
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

						$post_type = get_post_type_object( $post->post_type );
						if (
							'private' === $post->post_status
							&& ( ! $post_type || ! wp_auto_test_user_can( $post_type->cap->read_private_posts ) )
						) {
							return $post->post_author === $GLOBALS['wp_auto_test_current_user_id'];
						}

						return true;
					}
				)
			);

			usort(
				$posts,
				static function ( WP_Post $left, WP_Post $right ) use ( $args ): int {
					$properties = array(
						'date'     => 'post_date_gmt',
						'modified' => 'post_modified_gmt',
						'title'    => 'post_title',
						'ID'       => 'ID',
					);

					foreach ( $args['orderby'] as $orderby => $direction ) {
						$property = $properties[ $orderby ];
						$result   = $left->{$property} <=> $right->{$property};
						if ( 0 !== $result ) {
							return 'ASC' === $direction ? $result : -$result;
						}
					}

					return 0;
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
	function get_current_blog_id(): int {
		return 1;
	}

	function current_time( string $type, bool $gmt = false ): string {
		unset( $type, $gmt );
		return '2026-09-01 12:00:00';
	}

	function maybe_serialize( $value ): string {
		return is_array( $value ) || is_object( $value ) || is_bool( $value ) || null === $value
			? serialize( $value )
			: (string) $value;
	}

	function maybe_unserialize( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^(a|O|s|b|i|d|N):/', $value ) ) {
			return $value;
		}

		$unserialized = @unserialize( $value );
		return false === $unserialized && 'b:0;' !== $value ? $value : $unserialized;
	}

	function wp_generate_uuid4(): string {
		++$GLOBALS['wp_auto_test_uuid_counter'];
		return sprintf( '12345678-1234-4%03d-8234-%012d', $GLOBALS['wp_auto_test_uuid_counter'] % 1000, $GLOBALS['wp_auto_test_uuid_counter'] );
	}

	function wp_cache_delete( $key, $group = '' ): bool {
		if ( $GLOBALS['wp_auto_test_cache_delete_exception'] instanceof \Throwable ) {
			$exception = $GLOBALS['wp_auto_test_cache_delete_exception'];
			$GLOBALS['wp_auto_test_cache_delete_exception'] = null;
			throw $exception;
		}

		$GLOBALS['wp_auto_test_cache_delete_history'][] = array( $key, $group );
		if ( 'options' === $group ) {
			if ( 'notoptions' === $key ) {
				$GLOBALS['wp_auto_test_notoptions_cache'] = null;
			} elseif ( 'alloptions' === $key ) {
				$GLOBALS['wp_auto_test_alloptions_cache'] = null;
			} else {
				unset( $GLOBALS['wp_auto_test_option_cache'][ $key ] );
			}
		}

		return null !== $GLOBALS['wp_auto_test_cache_delete_return_override']
			? (bool) $GLOBALS['wp_auto_test_cache_delete_return_override']
			: true;
	}

	function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
		unset( $force );
		$found = false;
		if ( 'options' !== $group ) {
			return false;
		}
		if ( 'notoptions' === $key ) {
			$found = null !== $GLOBALS['wp_auto_test_notoptions_cache'];
			return $GLOBALS['wp_auto_test_notoptions_cache'];
		}
		if ( 'alloptions' === $key ) {
			$found = null !== $GLOBALS['wp_auto_test_alloptions_cache'];
			return $GLOBALS['wp_auto_test_alloptions_cache'];
		}
		if ( array_key_exists( $key, $GLOBALS['wp_auto_test_option_cache'] ) ) {
			$found = true;
			return $GLOBALS['wp_auto_test_option_cache'][ $key ];
		}

		return false;
	}

	function wp_cache_set( $key, $value, $group = '', $expire = 0 ): bool {
		unset( $expire );
		if ( 'options' === $group ) {
			if ( 'notoptions' === $key ) {
				$GLOBALS['wp_auto_test_notoptions_cache'] = $value;
			} elseif ( 'alloptions' === $key ) {
				$GLOBALS['wp_auto_test_alloptions_cache'] = $value;
			} else {
				$GLOBALS['wp_auto_test_option_cache'][ $key ] = $value;
			}
		}
		return true;
	}

	function wp_cache_add( $key, $value, $group = '', $expire = 0 ): bool {
		unset( $expire );
		$found = false;
		wp_cache_get( $key, $group, false, $found );
		return $found ? false : wp_cache_set( $key, $value, $group );
	}

	function wp_slash( $value ) {
		if ( is_array( $value ) ) {
			return array_map( 'wp_slash', $value );
		}

		return is_string( $value ) ? addslashes( $value ) : $value;
	}

	function wp_unslash( $value ) {
		if ( is_array( $value ) ) {
			return array_map( 'wp_unslash', $value );
		}

		return is_string( $value ) ? stripslashes( $value ) : $value;
	}

	function wp_json_encode( $value, int $flags = 0 ) {
		return json_encode( $value, $flags );
	}

	function wp_rand(): int {
		return 123456;
	}

	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$GLOBALS['wp_auto_test_filters'][ $hook ][ $priority ][] = array(
			'callback'      => $callback,
			'accepted_args' => $accepted_args,
		);
	}

	function remove_filter( string $hook, callable $callback, int $priority = 10 ): bool {
		if ( empty( $GLOBALS['wp_auto_test_filters'][ $hook ][ $priority ] ) ) {
			return false;
		}

		foreach ( $GLOBALS['wp_auto_test_filters'][ $hook ][ $priority ] as $index => $registered ) {
			if ( $registered['callback'] === $callback ) {
				unset( $GLOBALS['wp_auto_test_filters'][ $hook ][ $priority ][ $index ] );
				return true;
			}
		}

		return false;
	}

	function apply_filters( string $hook, $value, ...$args ) {
		if ( empty( $GLOBALS['wp_auto_test_filters'][ $hook ] ) ) {
			return $value;
		}

		ksort( $GLOBALS['wp_auto_test_filters'][ $hook ] );
		foreach ( $GLOBALS['wp_auto_test_filters'][ $hook ] as $callbacks ) {
			foreach ( $callbacks as $registered ) {
				$parameters = array_slice(
					array_merge( array( $value ), $args ),
					0,
					$registered['accepted_args']
				);
				$value      = $registered['callback']( ...$parameters );
			}
		}

		return $value;
	}

	function is_wp_error( $value ): bool {
		return $value instanceof WP_Error;
	}

	function wp_auto_test_user_can( string $capability, int $object_id = 0 ): bool {
		if (
			$object_id > 0
			&& isset( $GLOBALS['wp_auto_test_object_capabilities'][ $capability ] )
			&& array_key_exists( $object_id, $GLOBALS['wp_auto_test_object_capabilities'][ $capability ] )
		) {
			return $GLOBALS['wp_auto_test_object_capabilities'][ $capability ][ $object_id ];
		}

		if ( ! in_array( $capability, array( 'read_post', 'edit_post' ), true ) ) {
			return ! empty( $GLOBALS['wp_auto_test_capabilities'][ $capability ] );
		}

		$post = get_post( $object_id );
		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		$post_type = get_post_type_object( $post->post_type );
		if ( ! $post_type || ! isset( $post_type->cap ) ) {
			return false;
		}
		$cap = $post_type->cap;

		if ( 'read_post' === $capability && ( 'publish' === $post->post_status || $post->post_author === $GLOBALS['wp_auto_test_current_user_id'] ) ) {
			return wp_auto_test_user_can( $cap->read );
		}

		if ( 'read_post' === $capability && 'private' === $post->post_status ) {
			return wp_auto_test_user_can( $cap->read_private_posts );
		}

		if ( 'read_post' === $capability ) {
			return wp_auto_test_user_can( 'edit_post', $object_id );
		}

		if ( $post->post_author === $GLOBALS['wp_auto_test_current_user_id'] ) {
			return in_array( $post->post_status, array( 'publish', 'future' ), true )
				? wp_auto_test_user_can( $cap->edit_published_posts )
				: wp_auto_test_user_can( $cap->edit_posts );
		}

		if ( ! wp_auto_test_user_can( $cap->edit_others_posts ) ) {
			return false;
		}

		if ( in_array( $post->post_status, array( 'publish', 'future' ), true ) ) {
			return wp_auto_test_user_can( $cap->edit_published_posts );
		}

		return 'private' !== $post->post_status || wp_auto_test_user_can( $cap->edit_private_posts );
	}

	function get_post( int $post_id ) {
		++$GLOBALS['wp_auto_test_get_post_calls'];
		if (
			$GLOBALS['wp_auto_test_get_post_exception'] instanceof \Throwable
			&& ( null === $GLOBALS['wp_auto_test_get_post_exception_on_call'] || $GLOBALS['wp_auto_test_get_post_calls'] === $GLOBALS['wp_auto_test_get_post_exception_on_call'] )
		) {
			$exception = $GLOBALS['wp_auto_test_get_post_exception'];
			$GLOBALS['wp_auto_test_get_post_exception'] = null;
			throw $exception;
		}
		if ( is_callable( $GLOBALS['wp_auto_test_before_get_post'] ) ) {
			( $GLOBALS['wp_auto_test_before_get_post'] )( $post_id, $GLOBALS['wp_auto_test_get_post_calls'] );
		}
		foreach ( $GLOBALS['wp_auto_test_posts'] as $post ) {
			if ( $post->ID === $post_id ) {
				return $post;
			}
		}

		return null;
	}

	function get_post_meta( int $post_id, string $meta_key, bool $single = false ) {
		if ( $GLOBALS['wp_auto_test_get_post_meta_exception'] instanceof \Throwable ) {
			$exception = $GLOBALS['wp_auto_test_get_post_meta_exception'];
			$GLOBALS['wp_auto_test_get_post_meta_exception'] = null;
			throw $exception;
		}
		if ( isset( $GLOBALS['wp_auto_test_post_meta_values'][ $post_id ][ $meta_key ] ) ) {
			$values = $GLOBALS['wp_auto_test_post_meta_values'][ $post_id ][ $meta_key ];
			return $single ? ( $values[0] ?? array() ) : $values;
		}

		$value = $GLOBALS['wp_auto_test_post_meta'][ $post_id ][ $meta_key ] ?? array();
		return $single ? $value : array( $value );
	}

	function update_post_meta( int $post_id, string $meta_key, $value ) {
		++$GLOBALS['wp_auto_test_update_meta_calls'];
		if ( $GLOBALS['wp_auto_test_update_meta_exception'] instanceof \Throwable ) {
			$exception = $GLOBALS['wp_auto_test_update_meta_exception'];
			$GLOBALS['wp_auto_test_update_meta_exception'] = null;
			throw $exception;
		}
		if ( is_callable( $GLOBALS['wp_auto_test_before_update_meta'] ) ) {
			$callback = $GLOBALS['wp_auto_test_before_update_meta'];
			$GLOBALS['wp_auto_test_before_update_meta'] = null;
			$callback( $post_id, $meta_key, $value );
		}

		if ( $GLOBALS['wp_auto_test_fail_update_meta'] ) {
			return false;
		}

		$GLOBALS['wp_auto_test_post_meta'][ $post_id ][ $meta_key ] = $value;
		if ( $GLOBALS['wp_auto_test_update_meta_exception_after_write'] instanceof \Throwable ) {
			$exception = $GLOBALS['wp_auto_test_update_meta_exception_after_write'];
			$GLOBALS['wp_auto_test_update_meta_exception_after_write'] = null;
			throw $exception;
		}
		return true;
	}

	function add_option( string $option, $value = '', string $deprecated = '', $autoload = null ): bool {
		unset( $deprecated );
		if ( $GLOBALS['wp_auto_test_add_option_exception'] instanceof \Throwable ) {
			$exception = $GLOBALS['wp_auto_test_add_option_exception'];
			$GLOBALS['wp_auto_test_add_option_exception'] = null;
			throw $exception;
		}
		if ( array_key_exists( $option, $GLOBALS['wp_auto_test_options'] ) ) {
			return false;
		}

		$GLOBALS['wp_auto_test_options'][ $option ] = $value;
		$GLOBALS['wp_auto_test_option_autoload'][ $option ] = $autoload;
		if ( $GLOBALS['wp_auto_test_add_option_exception_after_write'] instanceof \Throwable ) {
			$exception = $GLOBALS['wp_auto_test_add_option_exception_after_write'];
			$GLOBALS['wp_auto_test_add_option_exception_after_write'] = null;
			throw $exception;
		}
		return true;
	}

	function get_option( string $option, $default = false ) {
		if ( $GLOBALS['wp_auto_test_use_option_cache'] ) {
			if ( is_array( $GLOBALS['wp_auto_test_alloptions_cache'] ) && array_key_exists( $option, $GLOBALS['wp_auto_test_alloptions_cache'] ) ) {
				return $GLOBALS['wp_auto_test_alloptions_cache'][ $option ];
			}
			if ( is_array( $GLOBALS['wp_auto_test_notoptions_cache'] ) && in_array( $option, $GLOBALS['wp_auto_test_notoptions_cache'], true ) ) {
				return $default;
			}
			if ( array_key_exists( $option, $GLOBALS['wp_auto_test_option_cache'] ) ) {
				return $GLOBALS['wp_auto_test_option_cache'][ $option ];
			}
			if ( array_key_exists( $option, $GLOBALS['wp_auto_test_options'] ) ) {
				wp_cache_set( $option, $GLOBALS['wp_auto_test_options'][ $option ], 'options' );
				return $GLOBALS['wp_auto_test_options'][ $option ];
			}
			if ( ! is_array( $GLOBALS['wp_auto_test_notoptions_cache'] ) ) {
				$GLOBALS['wp_auto_test_notoptions_cache'] = array();
			}
			$GLOBALS['wp_auto_test_notoptions_cache'][] = $option;
			return $default;
		}

		return array_key_exists( $option, $GLOBALS['wp_auto_test_options'] )
			? $GLOBALS['wp_auto_test_options'][ $option ]
			: ( 'permalink_structure' === $option ? $GLOBALS['wp_auto_test_site_info']['permalink_structure'] : $default );
	}

	function update_option( string $option, $value, $autoload = null ): bool {
		unset( $autoload );
		++$GLOBALS['wp_auto_test_update_option_calls'];
		if ( $GLOBALS['wp_auto_test_update_option_calls'] === $GLOBALS['wp_auto_test_update_option_exception_on_call'] ) {
			$GLOBALS['wp_auto_test_update_option_exception_on_call'] = null;
			throw new \RuntimeException( 'sensitive-internal-detail' );
		}
		if ( $GLOBALS['wp_auto_test_fail_update_option'] || $GLOBALS['wp_auto_test_update_option_calls'] === $GLOBALS['wp_auto_test_fail_update_option_on_call'] ) {
			return false;
		}

		$GLOBALS['wp_auto_test_options'][ $option ] = $value;
		return true;
	}

	function delete_option( string $option ): bool {
		++$GLOBALS['wp_auto_test_delete_option_calls'];
		if ( $GLOBALS['wp_auto_test_delete_option_exception'] instanceof \Throwable ) {
			$exception = $GLOBALS['wp_auto_test_delete_option_exception'];
			$GLOBALS['wp_auto_test_delete_option_exception'] = null;
			throw $exception;
		}
		if ( $GLOBALS['wp_auto_test_fail_delete_option'] ) {
			return false;
		}
		unset( $GLOBALS['wp_auto_test_options'][ $option ] );
		return true;
	}

	function get_edit_post_link( $post, string $context = 'display' ): ?string {
		unset( $context );
		if ( $GLOBALS['wp_auto_test_edit_link_exception'] instanceof \Throwable ) {
			$exception = $GLOBALS['wp_auto_test_edit_link_exception'];
			$GLOBALS['wp_auto_test_edit_link_exception'] = null;
			throw $exception;
		}
		$post_id = $post instanceof WP_Post ? $post->ID : (int) $post;
		return 'https://example.test/wp-admin/post.php?post=' . $post_id . '&action=edit';
	}

	function post_type_supports( string $post_type, string $feature ): bool {
		return in_array( $post_type, array( 'post', 'page' ), true )
			&& in_array( $feature, array( 'editor', 'title', 'excerpt' ), true );
	}

	function wp_insert_post( array $postarr, bool $wp_error = false, bool $fire_after_hooks = true ) {
		unset( $wp_error, $fire_after_hooks );
		++$GLOBALS['wp_auto_test_insert_calls'];
		$GLOBALS['wp_auto_test_last_insert_args'] = $postarr;

		if ( $GLOBALS['wp_auto_test_insert_exception'] instanceof \Throwable ) {
			$exception = $GLOBALS['wp_auto_test_insert_exception'];
			$GLOBALS['wp_auto_test_insert_exception'] = null;
			throw $exception;
		}

		if ( $GLOBALS['wp_auto_test_insert_result'] instanceof WP_Error || 0 === $GLOBALS['wp_auto_test_insert_result'] ) {
			$result = $GLOBALS['wp_auto_test_insert_result'];
			$GLOBALS['wp_auto_test_insert_result'] = null;
			return $result;
		}

		$id = ++$GLOBALS['wp_auto_test_next_post_id'];
		$data = array(
			'ID'           => $id,
			'post_type'    => $postarr['post_type'] ?? 'post',
			'post_status'  => $postarr['post_status'] ?? 'draft',
			'post_author'  => $postarr['post_author'] ?? get_current_user_id(),
			'post_parent'  => $postarr['post_parent'] ?? 0,
			'post_title'   => wp_unslash( $postarr['post_title'] ?? '' ),
			'post_content' => wp_unslash( $postarr['post_content'] ?? '' ),
			'post_excerpt' => wp_unslash( $postarr['post_excerpt'] ?? '' ),
			'post_name'    => wp_unslash( $postarr['post_name'] ?? 'draft-' . $id ),
			'post_date_gmt' => '2026-09-01 12:00:00',
			'post_modified_gmt' => '2026-09-01 12:00:00',
		);
		$data = apply_filters( 'wp_insert_post_data', $data, $postarr, $postarr, false );
		$GLOBALS['wp_auto_test_posts'][] = new WP_Post( $data );

		if ( $GLOBALS['wp_auto_test_insert_result'] instanceof \Throwable ) {
			$exception = $GLOBALS['wp_auto_test_insert_result'];
			$GLOBALS['wp_auto_test_insert_result'] = null;
			throw $exception;
		}

		return $id;
	}

	function wp_update_post( array $postarr, bool $wp_error = false, bool $fire_after_hooks = true ) {
		unset( $wp_error, $fire_after_hooks );
		$GLOBALS['wp_auto_test_last_update_args'] = $postarr;

		if ( $GLOBALS['wp_auto_test_update_exception'] instanceof \Throwable ) {
			$exception = $GLOBALS['wp_auto_test_update_exception'];
			$GLOBALS['wp_auto_test_update_exception'] = null;
			throw $exception;
		}
		if ( $GLOBALS['wp_auto_test_update_result'] instanceof WP_Error || 0 === $GLOBALS['wp_auto_test_update_result'] ) {
			$result = $GLOBALS['wp_auto_test_update_result'];
			$GLOBALS['wp_auto_test_update_result'] = null;
			return $result;
		}

		$post_id = (int) ( $postarr['ID'] ?? 0 );
		foreach ( $GLOBALS['wp_auto_test_posts'] as $index => $post ) {
			if ( $post->ID !== $post_id ) {
				continue;
			}

			$data = wp_slash( get_object_vars( $post ) );
			foreach ( array( 'post_title', 'post_content', 'post_excerpt', 'post_name' ) as $field ) {
				if ( array_key_exists( $field, $postarr ) ) {
					$data[ $field ] = $postarr[ $field ];
				}
			}
			$data['post_modified_gmt'] = $GLOBALS['wp_auto_test_next_modified_gmt'];
			$data = apply_filters( 'wp_insert_post_data', $data, $postarr, $postarr, true );
			$data = wp_unslash( $data );
			$GLOBALS['wp_auto_test_posts'][ $index ] = new WP_Post( $data );

			if ( $GLOBALS['wp_auto_test_update_after_exception'] instanceof \Throwable ) {
				$exception = $GLOBALS['wp_auto_test_update_after_exception'];
				$GLOBALS['wp_auto_test_update_after_exception'] = null;
				throw $exception;
			}

			return $post_id;
		}

		return new WP_Error( 'core_missing', 'hidden' );
	}

	function get_post_type_object( string $post_type ) {
		if ( ! in_array( $post_type, array( 'post', 'page' ), true ) ) {
			return null;
		}

		$suffix = 'page' === $post_type ? 'pages' : 'posts';

		return (object) array(
			'cap' => (object) array(
				'read'                 => 'read',
				'edit_posts'           => 'edit_' . $suffix,
				'create_posts'         => 'edit_' . $suffix,
				'read_private_posts'   => 'read_private_' . $suffix,
				'edit_private_posts'   => 'edit_private_' . $suffix,
				'edit_others_posts'    => 'edit_others_' . $suffix,
				'edit_published_posts' => 'edit_published_' . $suffix,
			),
		);
	}

	function get_current_user_id(): int {
		return $GLOBALS['wp_auto_test_current_user_id'];
	}

	function get_permalink( $post ) {
		if ( $GLOBALS['wp_auto_test_permalink_exception'] instanceof \Throwable ) {
			$exception = $GLOBALS['wp_auto_test_permalink_exception'];
			$GLOBALS['wp_auto_test_permalink_exception'] = null;
			throw $exception;
		}
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

	function get_terms( array $args ) {
		$GLOBALS['wp_auto_test_last_term_query_args'] = $args;
		$GLOBALS['wp_auto_test_term_query_history'][] = $args;

		if ( $GLOBALS['wp_auto_test_get_terms_error'] instanceof WP_Error ) {
			return $GLOBALS['wp_auto_test_get_terms_error'];
		}

		$orderby_columns = array(
			'name'    => 't.name',
			'slug'    => 't.slug',
			'count'   => 'tt.count',
			'term_id' => 't.term_id',
		);
		$clauses          = array(
			'fields'   => 't.term_id',
			'join'     => '',
			'where'    => '',
			'distinct' => '',
			'orderby'  => 'ORDER BY ' . $orderby_columns[ $args['orderby'] ],
			'order'    => $args['order'],
			'limits'   => 'LIMIT ' . $args['offset'] . ',' . $args['number'],
		);
		$clauses          = apply_filters( 'terms_clauses', $clauses, array( $args['taxonomy'] ), $args );
		$GLOBALS['wp_auto_test_last_term_clauses'] = $clauses;

		$terms = array_values(
			array_filter(
				$GLOBALS['wp_auto_test_taxonomy_terms'],
				static function ( WP_Term $term ) use ( $args ): bool {
					if ( $term->taxonomy !== $args['taxonomy'] ) {
						return false;
					}

					if ( $args['hide_empty'] && 0 === $term->count ) {
						return false;
					}

					return '' === $args['search'] || false !== stripos( $term->name . ' ' . $term->slug, $args['search'] );
				}
			)
		);

		$properties = array(
			'name'    => 'name',
			'slug'    => 'slug',
			'count'   => 'count',
			'term_id' => 'term_id',
		);
		$property   = $properties[ $args['orderby'] ];
		$direction  = 'ASC' === $args['order'] ? 1 : -1;
		usort(
			$terms,
			static function ( WP_Term $left, WP_Term $right ) use ( $property, $direction ): int {
				$result = $left->{$property} <=> $right->{$property};
				if ( 0 === $result && 'term_id' !== $property ) {
					$result = $left->term_id <=> $right->term_id;
				}

				return $direction * $result;
			}
		);

		return array_slice( $terms, $args['offset'], $args['number'] );
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
		$GLOBALS['wp_auto_test_hook_history'][ $hook ][] = $callback;
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
		$GLOBALS['wp_auto_test_hook_history'][ $hook ][] = $callback;
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

namespace WPAuto\Connector\Abilities\Taxonomy {
	function add_action( string $hook, callable $callback ): void {
		$GLOBALS['wp_auto_test_hooks'][ $hook ] = $callback;
		$GLOBALS['wp_auto_test_hook_history'][ $hook ][] = $callback;
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

	function current_user_can( string $capability ): bool {
		return \wp_auto_test_user_can( $capability );
	}

	function __( string $text ): string {
		return $text;
	}
}

namespace WPAuto\Connector\Taxonomy {
	function is_wp_error( $value ): bool {
		return \is_wp_error( $value );
	}

	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		\add_filter( $hook, $callback, $priority, $accepted_args );
	}

	function remove_filter( string $hook, callable $callback, int $priority = 10 ): bool {
		return \remove_filter( $hook, $callback, $priority );
	}

	function get_terms( array $args ) {
		return \get_terms( $args );
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
		$GLOBALS['wp_auto_test_hook_history'][ $hook ][] = $callback;
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

namespace WPAuto\Connector {
	function is_admin(): bool {
		return false;
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/src/Diagnostics/EnvironmentDiagnostics.php';
	require_once dirname( __DIR__ ) . '/src/Content/ContentReadService.php';
	require_once dirname( __DIR__ ) . '/src/Content/CreateDraftContract.php';
	require_once dirname( __DIR__ ) . '/src/Content/UpdateDraftContract.php';
	require_once dirname( __DIR__ ) . '/src/Content/AtomicOwnershipStore.php';
	require_once dirname( __DIR__ ) . '/src/Content/CreateIdempotencyStore.php';
	require_once dirname( __DIR__ ) . '/src/Content/MutationAuditStore.php';
	require_once dirname( __DIR__ ) . '/src/Content/ContentMutationService.php';
	require_once dirname( __DIR__ ) . '/src/Taxonomy/TaxonomyReadService.php';
	require_once dirname( __DIR__ ) . '/src/Abilities/Site/SiteHealthAbility.php';
	require_once dirname( __DIR__ ) . '/src/Abilities/Site/SiteInfoAbility.php';
	require_once dirname( __DIR__ ) . '/src/Abilities/Content/ContentAbilityCategory.php';
	require_once dirname( __DIR__ ) . '/src/Abilities/Content/PostsSearchAbility.php';
	require_once dirname( __DIR__ ) . '/src/Abilities/Content/PostGetAbility.php';
	require_once dirname( __DIR__ ) . '/src/Abilities/Content/PagesSearchAbility.php';
	require_once dirname( __DIR__ ) . '/src/Abilities/Content/PageGetAbility.php';
	require_once dirname( __DIR__ ) . '/src/Abilities/Content/PostCreateDraftAbility.php';
	require_once dirname( __DIR__ ) . '/src/Abilities/Content/PageCreateDraftAbility.php';
	require_once dirname( __DIR__ ) . '/src/Abilities/Content/PostUpdateAbility.php';
	require_once dirname( __DIR__ ) . '/src/Abilities/Content/PageUpdateAbility.php';
	require_once dirname( __DIR__ ) . '/src/Abilities/Taxonomy/TaxonomyAbilityCategory.php';
	require_once dirname( __DIR__ ) . '/src/Abilities/Taxonomy/CategoriesListAbility.php';
	require_once dirname( __DIR__ ) . '/src/Abilities/Taxonomy/TagsListAbility.php';
	require_once dirname( __DIR__ ) . '/src/Mcp/McpAdapterLoader.php';
	require_once dirname( __DIR__ ) . '/src/Mcp/McpServerRegistrar.php';
	require_once dirname( __DIR__ ) . '/src/Plugin.php';
}
