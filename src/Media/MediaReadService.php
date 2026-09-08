<?php
/**
 * Permission-aware, bounded media reads.
 *
 * @package WPAutoConnector
 */

namespace WPAuto\Connector\Media;

use WP_Error;
use WP_Post;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Implements the frozen Phase 1.4.1 read behavior through WordPress APIs. */
final class MediaReadService {
	private const MAX_PER_PAGE      = 50;
	private const MAX_SEARCH_LENGTH = 200;
	private const SCAN_CHUNK_SIZE   = 100;
	private const MAX_SCAN_POSTS    = 1000;

	/**
	 * Search supported images.
	 *
	 * @param mixed $input Raw Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function search( $input = array() ) {
		$parameters = $this->normalize_search_input( $input );
		if ( is_wp_error( $parameters ) ) {
			return $parameters;
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			return $this->media_not_found();
		}

		$logical_offset = $this->calculate_offset( $parameters['page'], $parameters['per_page'] );
		if ( is_wp_error( $logical_offset ) || $logical_offset > self::MAX_SCAN_POSTS - $parameters['per_page'] - 1 ) {
			return $this->pagination_window_exceeded();
		}

		$query_args = array(
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'post_mime_type'         => 'all' === $parameters['mime_type'] ? MediaReadContract::MIME_TYPES : $parameters['mime_type'],
			's'                      => $parameters['search'],
			'orderby'                => $this->map_orderby( $parameters['orderby'], $parameters['order'] ),
			'order'                  => 'asc' === $parameters['order'] ? 'ASC' : 'DESC',
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		$items = $this->scan_authorized_media( $query_args, $logical_offset, $parameters['per_page'] + 1 );
		if ( is_wp_error( $items ) ) {
			return $items;
		}
		$has_more = count( $items ) > $parameters['per_page'];
		if ( $has_more ) {
			array_pop( $items );
		}

		return array(
			'items'    => $items,
			'page'     => $parameters['page'],
			'per_page' => $parameters['per_page'],
			'returned' => count( $items ),
			'has_more' => $has_more,
		);
	}

	/**
	 * Get one supported image.
	 *
	 * @param mixed $input Raw Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function get( $input ) {
		if ( ! is_array( $input ) || array( 'id' ) !== array_keys( $input ) || ! is_int( $input['id'] ) || $input['id'] < 1 ) {
			return $this->invalid_request();
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			return $this->media_not_found();
		}

		$attachment = get_post( $input['id'] );
		if ( ! $attachment instanceof WP_Post || ! $this->is_authorized_image( $attachment ) ) {
			return $this->media_not_found();
		}

		$record = $this->normalize_full_record( $attachment );
		return is_array( $record ) ? $record : $this->media_read_failed();
	}

	/**
	 * Apply defaults and reject non-contract Search input.
	 *
	 * @param mixed $input Raw Search input.
	 * @return array<string, int|string>|WP_Error
	 */
	private function normalize_search_input( $input ) {
		if ( null === $input ) {
			$input = array();
		}
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'search', 'mime_type', 'page', 'per_page', 'orderby', 'order' ) ) ) {
			return $this->invalid_request();
		}

		$parameters = array_merge(
			array(
				'search'    => '',
				'mime_type' => 'all',
				'page'      => 1,
				'per_page'  => 10,
				'orderby'   => 'modified',
				'order'     => 'desc',
			),
			$input
		);
		if (
			! is_string( $parameters['search'] )
			|| $this->string_length( $parameters['search'] ) > self::MAX_SEARCH_LENGTH
			|| ! is_string( $parameters['mime_type'] )
			|| ! in_array( $parameters['mime_type'], array_merge( array( 'all' ), MediaReadContract::MIME_TYPES ), true )
			|| ! is_int( $parameters['page'] ) || $parameters['page'] < 1
			|| ! is_int( $parameters['per_page'] ) || $parameters['per_page'] < 1 || $parameters['per_page'] > self::MAX_PER_PAGE
			|| ! is_string( $parameters['orderby'] ) || ! in_array( $parameters['orderby'], array( 'date', 'modified', 'title', 'id' ), true )
			|| ! is_string( $parameters['order'] ) || ! in_array( $parameters['order'], array( 'asc', 'desc' ), true )
		) {
			return $this->invalid_request();
		}
		return $parameters;
	}

	/**
	 * Calculate a non-negative logical offset without overflow.
	 *
	 * @param int $page Requested page.
	 * @param int $per_page Effective page size.
	 * @return int|WP_Error
	 */
	private function calculate_offset( int $page, int $per_page ) {
		if ( ( $page - 1 ) > intdiv( PHP_INT_MAX, $per_page ) ) {
			return $this->invalid_request();
		}
		return ( $page - 1 ) * $per_page;
	}

	/**
	 * Scan a bounded raw query window after authorization.
	 *
	 * @param array<string, mixed> $query_args Fixed query arguments.
	 * @param int                  $logical_offset Number of eligible records to skip.
	 * @param int                  $required Maximum eligible records to collect.
	 * @return array<int, array<string, int|string>>|WP_Error
	 */
	private function scan_authorized_media( array $query_args, int $logical_offset, int $required ) {
		$eligible_seen = 0;
		$raw_offset    = 0;
		$selected      = array();
		while ( $raw_offset < self::MAX_SCAN_POSTS ) {
			$chunk_size                   = min( self::SCAN_CHUNK_SIZE, self::MAX_SCAN_POSTS - $raw_offset );
			$query_args['posts_per_page'] = $chunk_size;
			$query_args['offset']         = $raw_offset;
			$query                        = new WP_Query( $query_args );
			$raw_count                    = count( $query->posts );

			foreach ( $query->posts as $attachment ) {
				if ( ! $attachment instanceof WP_Post || ! $this->is_authorized_image( $attachment ) ) {
					continue;
				}
				$item = $this->normalize_search_item( $attachment );
				if ( ! is_array( $item ) || ! $this->has_valid_dimensions( $attachment->ID ) ) {
					continue;
				}
				if ( $eligible_seen >= $logical_offset ) {
					$selected[] = $item;
					if ( count( $selected ) >= $required ) {
						return $selected;
					}
				}
				++$eligible_seen;
			}

			$raw_offset += $raw_count;
			if ( $raw_count < $chunk_size ) {
				return $selected;
			}
		}
		return $this->pagination_window_exceeded();
	}

	/**
	 * Apply fixed type, MIME, attachment, and parent authorization.
	 *
	 * @param WP_Post $attachment Candidate attachment.
	 */
	private function is_authorized_image( WP_Post $attachment ): bool {
		if ( 'attachment' !== $attachment->post_type || 'inherit' !== $attachment->post_status || ! in_array( $attachment->post_mime_type, MediaReadContract::MIME_TYPES, true ) || ! current_user_can( 'read_post', $attachment->ID ) ) {
			return false;
		}
		if ( $attachment->post_parent < 1 ) {
			return true;
		}

		$parent = get_post( $attachment->post_parent );
		if ( ! $parent instanceof WP_Post || ! current_user_can( 'read_post', $parent->ID ) ) {
			return false;
		}
		return '' === $parent->post_password || current_user_can( 'edit_post', $parent->ID );
	}

	/**
	 * Normalize a lightweight record without exposing its local path.
	 *
	 * @param WP_Post $attachment Authorized attachment.
	 * @return array<string, int|string>|WP_Error
	 */
	private function normalize_search_item( WP_Post $attachment ) {
		$url  = wp_get_attachment_url( $attachment->ID );
		$file = get_attached_file( $attachment->ID );
		if ( ! is_string( $url ) || '' === $url || ! is_string( $file ) || '' === $file ) {
			return $this->media_read_failed();
		}
		$filename = wp_basename( $file );
		if ( '' === $filename ) {
			return $this->media_read_failed();
		}
		return array(
			'id'           => (int) $attachment->ID,
			'title'        => (string) $attachment->post_title,
			'filename'     => $filename,
			'mime_type'    => (string) $attachment->post_mime_type,
			'source_url'   => $url,
			'parent_id'    => (int) $attachment->post_parent,
			'date_gmt'     => (string) $attachment->post_date_gmt,
			'modified_gmt' => (string) $attachment->post_modified_gmt,
		);
	}

	/**
	 * Normalize the full Get record.
	 *
	 * @param WP_Post $attachment Authorized attachment.
	 * @return array<string, mixed>|WP_Error
	 */
	private function normalize_full_record( WP_Post $attachment ) {
		$item       = $this->normalize_search_item( $attachment );
		$dimensions = $this->image_dimensions( $attachment->ID );
		$alt_text   = get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true );
		if ( ! is_array( $item ) || ! is_array( $dimensions ) || ! is_string( $alt_text ) ) {
			return $this->media_read_failed();
		}
		return $item + array(
			'alt_text'    => $alt_text,
			'caption'     => (string) $attachment->post_excerpt,
			'description' => (string) $attachment->post_content,
			'width'       => $dimensions['width'],
			'height'      => $dimensions['height'],
		);
	}

	/**
	 * Read validated Core image dimensions.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array{width: int, height: int}|WP_Error
	 */
	private function image_dimensions( int $attachment_id ) {
		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( ! is_array( $metadata ) || ! isset( $metadata['width'], $metadata['height'] ) || ! is_numeric( $metadata['width'] ) || ! is_numeric( $metadata['height'] ) || (int) $metadata['width'] < 1 || (int) $metadata['height'] < 1 ) {
			return $this->media_read_failed();
		}
		return array(
			'width'  => (int) $metadata['width'],
			'height' => (int) $metadata['height'],
		);
	}

	/**
	 * Check whether dimensions satisfy the contract.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function has_valid_dimensions( int $attachment_id ): bool {
		return is_array( $this->image_dimensions( $attachment_id ) );
	}

	/**
	 * Map public ordering to fixed deterministic Core values.
	 *
	 * @param string $orderby Public order field.
	 * @param string $order Public direction.
	 * @return array<string, string>
	 */
	private function map_orderby( string $orderby, string $order ): array {
		$mapping   = array(
			'date'     => 'date',
			'modified' => 'modified',
			'title'    => 'title',
			'id'       => 'ID',
		);
		$direction = 'asc' === $order ? 'ASC' : 'DESC';
		return 'id' === $orderby ? array( 'ID' => $direction ) : array(
			$mapping[ $orderby ] => $direction,
			'ID'                 => $direction,
		);
	}

	/**
	 * Return a character-aware length.
	 *
	 * @param string $value Value to measure.
	 */
	private function string_length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
	}

	/** Return the stable invalid-input error. */
	private function invalid_request(): WP_Error {
		return new WP_Error( 'wp_auto_invalid_request', __( 'The request parameters are invalid.', 'wp-auto-connector' ), array( 'status' => 400 ) );
	}

	/** Return the existence-hiding media error. */
	private function media_not_found(): WP_Error {
		return new WP_Error( 'wp_auto_media_not_found', __( 'The requested media was not found.', 'wp-auto-connector' ), array( 'status' => 404 ) );
	}

	/** Return the stable media representation error. */
	private function media_read_failed(): WP_Error {
		return new WP_Error( 'wp_auto_media_read_failed', __( 'The media could not be read.', 'wp-auto-connector' ), array( 'status' => 500 ) );
	}

	/** Return the stable bounded-scanner error. */
	private function pagination_window_exceeded(): WP_Error {
		return new WP_Error( 'wp_auto_pagination_window_exceeded', __( 'The requested page exceeds the supported search window.', 'wp-auto-connector' ), array( 'status' => 400 ) );
	}
}
