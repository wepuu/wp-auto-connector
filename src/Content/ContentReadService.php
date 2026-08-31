<?php
/**
 * Permission-aware WordPress content reads.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Content;

use WP_Error;
use WP_Post;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides bounded, object-authorized post search and retrieval.
 */
final class ContentReadService {
	private const MAX_SEARCH_LENGTH = 200;
	private const MAX_PER_PAGE      = 50;
	private const SCAN_CHUNK_SIZE   = 100;
	private const MAX_SCAN_POSTS    = 1000;

	/**
	 * Search readable posts using the frozen Phase 1.2 contract.
	 *
	 * @param mixed $input Raw ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function search_posts( $input = array() ) {

		return $this->search_content( 'post', $input );
	}

	/**
	 * Search readable pages using the frozen Phase 1.2 contract.
	 *
	 * @param mixed $input Raw ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function search_pages( $input = array() ) {

		return $this->search_content( 'page', $input );
	}

	/**
	 * Search readable content using a fixed WordPress post type.
	 *
	 * @param string $post_type Fixed supported post type.
	 * @param mixed  $input Raw ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	private function search_content( string $post_type, $input = array() ) {

		$parameters = $this->normalize_search_input( $input );
		if ( is_wp_error( $parameters ) ) {
			return $parameters;
		}

		$logical_offset = $this->calculate_offset( $parameters['page'], $parameters['per_page'] );
		if ( is_wp_error( $logical_offset ) ) {
			return $logical_offset;
		}

		if ( $logical_offset > self::MAX_SCAN_POSTS - $parameters['per_page'] - 1 ) {
			return $this->pagination_window_exceeded();
		}

		$query_args = array(
			'post_type'           => $post_type,
			'post_status'         => $parameters['status'],
			's'                   => $parameters['search'],
			'orderby'             => $this->map_orderby( $parameters['orderby'], $parameters['order'] ),
			'order'               => 'asc' === $parameters['order'] ? 'ASC' : 'DESC',
			'perm'                => 'readable',
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		);

		$this->apply_visibility_scope( $query_args, $post_type, $parameters['status'] );

		$authorized = $this->scan_authorized_content( $query_args, $logical_offset, $parameters['per_page'] + 1 );
		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		$has_more = count( $authorized ) > $parameters['per_page'];
		$items    = array_map(
			array( $this, 'normalize_search_item' ),
			array_slice( $authorized, 0, $parameters['per_page'] )
		);

		return array(
			'items'    => $items,
			'page'     => $parameters['page'],
			'per_page' => $parameters['per_page'],
			'returned' => count( $items ),
			'has_more' => $has_more,
		);
	}

	/**
	 * Retrieve one readable post without disclosing rejected-object existence.
	 *
	 * @param mixed $input Raw ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_post( $input ) {

		$post = $this->get_readable_content( $input, 'post' );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$categories = wp_get_post_terms( $post->ID, 'category', array( 'fields' => 'ids' ) );
		$tags       = wp_get_post_terms( $post->ID, 'post_tag', array( 'fields' => 'ids' ) );

		return array(
			'id'                => (int) $post->ID,
			'type'              => 'post',
			'status'            => (string) $post->post_status,
			'slug'              => (string) $post->post_name,
			'title'             => (string) $post->post_title,
			'excerpt'           => (string) $post->post_excerpt,
			'content'           => (string) $post->post_content,
			'link'              => $this->post_link( $post ),
			'author_id'         => (int) $post->post_author,
			'date_gmt'          => (string) $post->post_date_gmt,
			'modified_gmt'      => (string) $post->post_modified_gmt,
			'featured_media_id' => (int) get_post_thumbnail_id( $post->ID ),
			'categories'        => $this->term_ids_or_empty( $categories ),
			'tags'              => $this->term_ids_or_empty( $tags ),
		);
	}

	/**
	 * Retrieve one readable page without disclosing rejected-object existence.
	 *
	 * @param mixed $input Raw ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_page( $input ) {

		$page = $this->get_readable_content( $input, 'page' );
		if ( is_wp_error( $page ) ) {
			return $page;
		}

		return array(
			'id'                => (int) $page->ID,
			'type'              => 'page',
			'status'            => (string) $page->post_status,
			'slug'              => (string) $page->post_name,
			'title'             => (string) $page->post_title,
			'excerpt'           => (string) $page->post_excerpt,
			'content'           => (string) $page->post_content,
			'link'              => $this->post_link( $page ),
			'author_id'         => (int) $page->post_author,
			'date_gmt'          => (string) $page->post_date_gmt,
			'modified_gmt'      => (string) $page->post_modified_gmt,
			'featured_media_id' => (int) get_post_thumbnail_id( $page->ID ),
			'parent_id'         => (int) $page->post_parent,
		);
	}

	/**
	 * Validate and retrieve one authorized object of a fixed post type.
	 *
	 * @param mixed  $input Raw ability input.
	 * @param string $post_type Fixed supported post type.
	 * @return WP_Post|WP_Error
	 */
	private function get_readable_content( $input, string $post_type ) {

		if (
			! is_array( $input )
			|| array( 'id' ) !== array_keys( $input )
			|| ! is_int( $input['id'] )
			|| $input['id'] < 1
		) {
			return $this->invalid_request();
		}

		$post = get_post( $input['id'] );
		if (
			! $post instanceof WP_Post
		|| $post_type !== $post->post_type
		|| ! $this->is_content_readable( $post )
		) {
			return $this->content_not_found();
		}

		return $post;
	}
	/**
	 * Apply frozen defaults and reject values outside the public schema.
	 *
	 * @param mixed $input Raw search input.
	 * @return array<string, int|string>|WP_Error
	 */
	private function normalize_search_input( $input ) {
		if ( null === $input ) {
			$input = array();
		}

		if ( ! is_array( $input ) ) {
			return $this->invalid_request();
		}

		$allowed_keys = array( 'search', 'status', 'page', 'per_page', 'orderby', 'order' );
		if ( array_diff( array_keys( $input ), $allowed_keys ) ) {
			return $this->invalid_request();
		}

		$parameters = array_merge(
			array(
				'search'   => '',
				'status'   => 'publish',
				'page'     => 1,
				'per_page' => 10,
				'orderby'  => 'modified',
				'order'    => 'desc',
			),
			$input
		);

		if (
			! is_string( $parameters['search'] )
			|| ! is_string( $parameters['status'] )
			|| ! in_array( $parameters['status'], array( 'publish', 'draft', 'pending', 'private', 'future' ), true )
			|| ! is_int( $parameters['page'] )
			|| $parameters['page'] < 1
			|| ! is_int( $parameters['per_page'] )
			|| $parameters['per_page'] < 1
			|| $parameters['per_page'] > self::MAX_PER_PAGE
			|| ! is_string( $parameters['orderby'] )
			|| ! in_array( $parameters['orderby'], array( 'date', 'modified', 'title', 'id' ), true )
			|| ! is_string( $parameters['order'] )
			|| ! in_array( $parameters['order'], array( 'asc', 'desc' ), true )
		) {
			return $this->invalid_request();
		}

		$search_length = function_exists( 'mb_strlen' )
			? mb_strlen( $parameters['search'] )
			: strlen( $parameters['search'] );

		if ( $search_length > self::MAX_SEARCH_LENGTH ) {
			return $this->invalid_request();
		}

		return $parameters;
	}

	/**
	 * Calculate a non-negative logical offset without integer overflow.
	 *
	 * @param int $page Requested logical page.
	 * @param int $per_page Effective bounded page size.
	 * @return int|WP_Error
	 */
	private function calculate_offset( int $page, int $per_page ) {
		if ( ( $page - 1 ) > intdiv( PHP_INT_MAX, $per_page ) ) {
			return $this->invalid_request();
		}

		return ( $page - 1 ) * $per_page;
	}

	/**
	 * Scan bounded raw query chunks and apply the logical offset after authorization.
	 *
	 * @param array<string, mixed> $query_args Fixed safe query arguments.
	 * @param int                  $logical_offset Number of authorized posts to skip.
	 * @param int                  $required Number of authorized posts to collect.
	 * @return array<int, WP_Post>|WP_Error
	 */
	private function scan_authorized_content( array $query_args, int $logical_offset, int $required ) {

		$authorized_seen = 0;
		$raw_offset      = 0;
		$selected        = array();

		while ( $raw_offset < self::MAX_SCAN_POSTS ) {
			$chunk_size                   = min( self::SCAN_CHUNK_SIZE, self::MAX_SCAN_POSTS - $raw_offset );
			$query_args['posts_per_page'] = $chunk_size;
			$query_args['offset']         = $raw_offset;
			$query                        = new WP_Query( $query_args );
			$raw_count                    = count( $query->posts );

			foreach ( $query->posts as $post ) {
				if ( ! $post instanceof WP_Post || ! $this->is_content_readable( $post ) ) {
					continue;
				}

				if ( $authorized_seen >= $logical_offset ) {
					$selected[] = $post;
					if ( count( $selected ) >= $required ) {
						return $selected;
					}
				}

				++$authorized_seen;
			}

			$raw_offset += $raw_count;
			if ( $raw_count < $chunk_size ) {
				return $selected;
			}
		}

		return $this->pagination_window_exceeded();
	}

	/**
	 * Apply the shared final eligibility rule used by search and get.
	 *
	 * Password-protected posts require object-level edit permission because this
	 * contract deliberately has no password input.
	 *
	 * @param WP_Post $post Candidate post.
	 */
	private function is_content_readable( WP_Post $post ): bool {

		if ( ! current_user_can( 'read_post', $post->ID ) ) {
			return false;
		}

		return '' === $post->post_password || current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * Limit non-public queries to the same capability scope as Core read_post.
	 *
	 * @param array<string, mixed> $query_args Query arguments passed by reference.
	 * @param string               $post_type Fixed supported post type.
	 * @param string               $status Requested post status.
	 */
	private function apply_visibility_scope( array &$query_args, string $post_type, string $status ): void {

		$post_type_object = get_post_type_object( $post_type );
		if ( ! $post_type_object || ! isset( $post_type_object->cap ) ) {
			$query_args['post__in'] = array( 0 );
			return;
		}

		$can_read_others = true;

		if ( 'private' === $status ) {
			$can_read_others = current_user_can( $post_type_object->cap->read_private_posts );
		} elseif ( in_array( $status, array( 'draft', 'pending' ), true ) ) {
			$can_read_others = current_user_can( $post_type_object->cap->edit_others_posts );
		} elseif ( 'future' === $status ) {
			$can_read_others = current_user_can( $post_type_object->cap->edit_others_posts )
				&& current_user_can( $post_type_object->cap->edit_published_posts );
		}

		if ( ! $can_read_others ) {
			$query_args['author'] = get_current_user_id();
		}
	}

	/**
	 * Map the public ordering enum to known-safe WP_Query values.
	 *
	 * @param string $orderby Frozen public ordering value.
	 * @param string $order Frozen public ordering direction.
	 * @return array<string, string>
	 */
	private function map_orderby( string $orderby, string $order ): array {
		$mapping = array(
			'date'     => 'date',
			'modified' => 'modified',
			'title'    => 'title',
			'id'       => 'ID',
		);

		$direction = 'asc' === $order ? 'ASC' : 'DESC';
		if ( 'id' === $orderby ) {
			return array( 'ID' => $direction );
		}

		return array(
			$mapping[ $orderby ] => $direction,
			'ID'                 => $direction,
		);
	}

	/**
	 * Normalize a lightweight search record.
	 *
	 * @param WP_Post $post Authorized post object.
	 * @return array<string, int|string>
	 */
	private function normalize_search_item( WP_Post $post ): array {
		return array(
			'id'           => (int) $post->ID,
			'title'        => (string) $post->post_title,
			'slug'         => (string) $post->post_name,
			'status'       => (string) $post->post_status,
			'link'         => $this->post_link( $post ),
			'author_id'    => (int) $post->post_author,
			'date_gmt'     => (string) $post->post_date_gmt,
			'modified_gmt' => (string) $post->post_modified_gmt,
		);
	}

	/**
	 * Return a WordPress-derived link or a deterministic empty value.
	 *
	 * @param WP_Post $post Authorized post object.
	 */
	private function post_link( WP_Post $post ): string {
		$link = get_permalink( $post );

		return is_string( $link ) ? $link : '';
	}

	/**
	 * Normalize term IDs and hide taxonomy lookup failures.
	 *
	 * @param array<int, int|string>|WP_Error $terms Term lookup result.
	 * @return array<int, int>
	 */
	private function term_ids_or_empty( $terms ): array {
		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}

		return array_values( array_map( 'intval', $terms ) );
	}

	/**
	 * Return the stable public invalid-input error.
	 */
	private function invalid_request(): WP_Error {
		return new WP_Error(
			'wp_auto_invalid_request',
			__( 'The request parameters are invalid.', 'wp-auto-connector' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Return the existence-hiding content error.
	 */
	private function content_not_found(): WP_Error {
		return new WP_Error(
			'wp_auto_content_not_found',
			__( 'The requested content was not found.', 'wp-auto-connector' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Return the stable bounded-scanner error.
	 */
	private function pagination_window_exceeded(): WP_Error {
		return new WP_Error(
			'wp_auto_pagination_window_exceeded',
			__( 'The requested page exceeds the supported search window.', 'wp-auto-connector' ),
			array( 'status' => 400 )
		);
	}
}
