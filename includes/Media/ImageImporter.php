<?php
namespace Company\SeoShutterstockAssistant\Media;

use Company\SeoShutterstockAssistant\Logs\Logger;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ImageImporter {
	private const USED_OPTION         = 'ssia_used_shutterstock_ids';
	private const RESERVATIONS_OPTION = 'ssia_reserved_shutterstock_ids';
	private const RESERVATION_TTL     = 900;
	private const RESERVATION_LOCK_TTL = 15;

	public function import_many( array $licensed, int $post_id ): array {
		$attachments = array();
		foreach ( array_slice( array_values( $licensed ), 0, 4 ) as $item ) {
			$attachment_id = $this->import( $item, $post_id );
			if ( $attachment_id ) {
				$attachments[] = $attachment_id;
			}
		}
		return $attachments;
	}


	public function recover_imports( array $licensed, int $post_id ): array {
		$attachments = array();
		foreach ( array_slice( array_values( $licensed ), 0, 4 ) as $item ) {
			$image_id = sanitize_text_field( (string) ( $item['image_id'] ?? '' ) );
			if ( '' === $image_id ) {
				continue;
			}
			$existing = self::attachment_for_image( $image_id, $post_id );
			if ( $existing ) {
				$attachments[] = $existing;
				continue;
			}
			$attachment_id = $this->import( $item, $post_id );
			if ( $attachment_id ) {
				$attachments[] = $attachment_id;
			}
		}
		return array_values( array_unique( array_map( 'absint', $attachments ) ) );
	}

	public static function attachment_for_image( string $image_id, int $post_id = 0 ): int {
		$image_id = sanitize_text_field( $image_id );
		$post_id  = absint( $post_id );
		$used     = self::used();
		if ( '' === $image_id || ! isset( $used[ $image_id ] ) || ! is_array( $used[ $image_id ] ) || empty( $used[ $image_id ]['attachment_id'] ) ) {
			return 0;
		}
		if ( $post_id && absint( $used[ $image_id ]['post_id'] ?? 0 ) !== $post_id ) {
			return 0;
		}
		$attachment_id = absint( $used[ $image_id ]['attachment_id'] );
		return $attachment_id && 'attachment' === get_post_type( $attachment_id ) ? $attachment_id : 0;
	}

	public function import_download( string $image_id, string $download_url, int $post_id = 0, array $metadata = array() ): int|WP_Error {
		$image_id     = sanitize_text_field( $image_id );
		$download_url = esc_url_raw( $download_url );
		$post_id      = absint( $post_id );
		if ( '' === $image_id || '' === $download_url ) {
			return new WP_Error( 'ssia_missing_import_data', __( 'Missing Shutterstock image ID or download URL.', 'seo-shutterstock-image-assistant' ), array( 'status' => 400 ) );
		}
		$url_check = self::validate_download_url( $download_url );
		if ( is_wp_error( $url_check ) ) {
			return $url_check;
		}
		$reservation = self::reserve_images( array( $image_id ), $post_id, true );
		if ( is_wp_error( $reservation ) ) {
			return $reservation;
		}
		$attachment_id = $this->import( array_merge( $metadata, array( 'image_id' => $image_id, 'download_url' => $download_url ) ), $post_id );
		self::release_reservations( array( $image_id ), $post_id );
		return $attachment_id ? $attachment_id : new WP_Error( 'ssia_previous_import_failed', __( 'The licensed asset could not be imported.', 'seo-shutterstock-image-assistant' ), array( 'status' => 500 ) );
	}

	public static function reserve_images( array $image_ids, int $post_id, bool $allow_current_post = false ): true|WP_Error {
		$image_ids = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $image_ids ) ) ) );
		$post_id   = absint( $post_id );
		if ( empty( $image_ids ) ) {
			return new WP_Error( 'ssia_no_images_to_reserve', __( 'No Shutterstock images were selected.', 'seo-shutterstock-image-assistant' ), array( 'status' => 400 ) );
		}

		$lock_key = self::reservation_lock_key( $image_ids );
		if ( ! self::acquire_reservation_lock( $lock_key ) ) {
			return new WP_Error( 'ssia_reservation_in_progress', __( 'Another licensing request is already checking these images. Wait a few seconds and try again.', 'seo-shutterstock-image-assistant' ), array( 'status' => 409 ) );
		}

		try {
			self::cleanup_expired_reservations();
			$used         = self::used();
			$reservations = self::reservations();
			$blocked      = array();
			$now          = time();

			foreach ( $image_ids as $image_id ) {
				if ( isset( $used[ $image_id ] ) && is_array( $used[ $image_id ] ) ) {
					$used_post_id = absint( $used[ $image_id ]['post_id'] ?? 0 );
					if ( ! $allow_current_post || $used_post_id !== $post_id ) {
						$blocked[] = $image_id;
						continue;
					}
				}
				if ( isset( $reservations[ $image_id ] ) && is_array( $reservations[ $image_id ] ) ) {
					$reserved_post_id = absint( $reservations[ $image_id ]['post_id'] ?? 0 );
					$expires_at       = absint( $reservations[ $image_id ]['expires_at'] ?? 0 );
					if ( $expires_at > $now && $reserved_post_id !== $post_id ) {
						$blocked[] = $image_id;
					}
				}
			}

			if ( ! empty( $blocked ) ) {
				( new Logger() )->error( 'duplicate_prelicense_blocked', array( 'image_ids' => implode( ',', $blocked ), 'post_id' => $post_id ) );
				return new WP_Error( 'ssia_duplicate_prelicense_blocked', __( 'One or more selected Shutterstock images are already used or reserved. Refresh suggestions before licensing.', 'seo-shutterstock-image-assistant' ), array( 'status' => 409, 'image_ids' => $blocked ) );
			}

			foreach ( $image_ids as $image_id ) {
				$reservations[ $image_id ] = array(
					'post_id'    => $post_id,
					'expires_at' => $now + self::RESERVATION_TTL,
					'time'       => current_time( 'mysql' ),
				);
			}
			update_option( self::RESERVATIONS_OPTION, $reservations, false );
			( new Logger() )->info( 'images_reserved_prelicense', array( 'post_id' => $post_id, 'count' => count( $image_ids ) ) );
			return true;
		} finally {
			self::release_reservation_lock( $lock_key );
		}
	}

	public static function release_reservations( array $image_ids, int $post_id = 0 ): void {
		$image_ids     = array_values( array_filter( array_map( 'sanitize_text_field', $image_ids ) ) );
		$post_id       = absint( $post_id );
		$reservations  = self::reservations();
		$changed       = false;
		foreach ( $image_ids as $image_id ) {
			if ( ! isset( $reservations[ $image_id ] ) ) {
				continue;
			}
			if ( ! is_array( $reservations[ $image_id ] ) || 0 === $post_id || absint( $reservations[ $image_id ]['post_id'] ?? 0 ) === $post_id ) {
				unset( $reservations[ $image_id ] );
				$changed = true;
			}
		}
		if ( $changed ) {
			update_option( self::RESERVATIONS_OPTION, $reservations, false );
		}
	}

	private function import( array $item, int $post_id ): int {
		$image_id = sanitize_text_field( (string) ( $item['image_id'] ?? '' ) );
		$url      = esc_url_raw( (string) ( $item['download_url'] ?? '' ) );
		$post_id  = absint( $post_id );
		if ( '' === $url || '' === $image_id ) {
			return 0;
		}

		$url_check = self::validate_download_url( $url );
		if ( is_wp_error( $url_check ) ) {
			( new Logger() )->error( 'download_url_blocked', array( 'image_id' => $image_id, 'message' => $url_check->get_error_message() ) );
			return 0;
		}

		$existing_attachment_id = self::attachment_for_image( $image_id, $post_id );
		if ( $existing_attachment_id ) {
			return $existing_attachment_id;
		}

		if ( $this->already_used( $image_id, $post_id ) ) {
			( new Logger() )->error( 'duplicate_blocked', array( 'image_id' => $image_id, 'post_id' => $post_id ) );
			return 0;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $url, 30 );
		if ( is_wp_error( $tmp ) ) {
			( new Logger() )->error( 'download_failed', array( 'image_id' => $image_id, 'message' => $tmp->get_error_message() ) );
			return 0;
		}

		$file          = array(
			'name'     => sanitize_file_name( 'shutterstock-' . $image_id . '.jpg' ),
			'tmp_name' => $tmp,
		);
		$alt           = $this->alt_text( $post_id );
		$attachment_id = media_handle_sideload( $file, $post_id, $alt );
		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $tmp );
			( new Logger() )->error( 'import_failed', array( 'image_id' => $image_id, 'message' => $attachment_id->get_error_message() ) );
			return 0;
		}

		$title            = $post_id ? get_the_title( $post_id ) : __( 'Shutterstock licensed image', 'seo-shutterstock-image-assistant' );
		$contributor_name = sanitize_text_field( (string) ( $item['contributor_name'] ?? $item['photographer'] ?? '' ) );
		$description      = $this->attachment_description( $image_id, $contributor_name );
		wp_update_post(
			array(
				'ID'           => $attachment_id,
				'post_title'   => sanitize_text_field( $title . ' - Shutterstock ' . $image_id ),
				'post_content' => $description,
				/* translators: %s: post title used as Media Library caption context. */
				'post_excerpt' => sanitize_text_field( sprintf( __( 'Shutterstock image licensed for %s.', 'seo-shutterstock-image-assistant' ), $title ) ),
			)
		);
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
		update_post_meta( $attachment_id, '_ssia_shutterstock_id', $image_id );
		update_post_meta( $attachment_id, '_ssia_source', 'shutterstock' );
		if ( '' !== $contributor_name ) {
			update_post_meta( $attachment_id, '_ssia_shutterstock_contributor', $contributor_name );
		}
		if ( ! empty( $item['license_id'] ) ) {
			update_post_meta( $attachment_id, '_ssia_shutterstock_license_id', sanitize_text_field( (string) $item['license_id'] ) );
		}
		$this->mark_used( $image_id, $post_id, (int) $attachment_id );
		self::release_reservations( array( $image_id ), $post_id );
		( new Logger() )->info( 'image_imported', array( 'post_id' => $post_id, 'image_id' => $image_id, 'attachment_id' => $attachment_id ) );
		return (int) $attachment_id;
	}


	public static function validate_download_url( string $url ): true|WP_Error {
		$parts = wp_parse_url( $url );
		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
		$host   = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';

		if ( 'https' !== $scheme || '' === $host ) {
			return new WP_Error( 'ssia_invalid_download_url', __( 'The licensed asset download URL must use HTTPS.', 'seo-shutterstock-image-assistant' ), array( 'status' => 400 ) );
		}

		$allowed_hosts = apply_filters(
			'ssia_allowed_download_hosts',
			array(
				'shutterstock.com',
				'shutterstockcdn.com',
				'sstk.co',
			)
		);
		$allowed_hosts = array_filter( array_map( 'strtolower', array_map( 'sanitize_text_field', (array) $allowed_hosts ) ) );

		foreach ( $allowed_hosts as $allowed_host ) {
			if ( $host === $allowed_host || str_ends_with( $host, '.' . $allowed_host ) ) {
				return true;
			}
		}

		return new WP_Error( 'ssia_download_host_not_allowed', __( 'The licensed asset download host is not allowed.', 'seo-shutterstock-image-assistant' ), array( 'status' => 400, 'host' => $host ) );
	}

	private static function reservation_lock_key( array $image_ids ): string {
		sort( $image_ids );
		return 'ssia_reserve_' . md5( implode( '|', $image_ids ) );
	}

	private static function acquire_reservation_lock( string $lock_key ): bool {
		$expires_at = absint( get_option( $lock_key, 0 ) );
		if ( $expires_at > time() ) {
			return false;
		}
		if ( $expires_at ) {
			delete_option( $lock_key );
		}
		return add_option( $lock_key, time() + self::RESERVATION_LOCK_TTL, '', false );
	}

	private static function release_reservation_lock( string $lock_key ): void {
		delete_option( $lock_key );
	}

	private function already_used( string $image_id, int $post_id = 0 ): bool {
		$used = self::used();
		if ( ! isset( $used[ $image_id ] ) ) {
			return false;
		}
		return is_array( $used[ $image_id ] ) && absint( $used[ $image_id ]['post_id'] ?? 0 ) !== absint( $post_id );
	}

	private function attachment_description( string $image_id, string $contributor_name = '' ): string {
		if ( '' !== $contributor_name ) {
			return sanitize_text_field( sprintf( 'Shutterstock ID: %1$s, Photographer: %2$s', $image_id, $contributor_name ) );
		}

		return sanitize_text_field( sprintf( 'Shutterstock ID: %s', $image_id ) );
	}

	private function mark_used( string $image_id, int $post_id, int $attachment_id ): void {
		$used              = self::used();
		$used[ $image_id ] = array(
			'post_id'       => $post_id,
			'attachment_id' => $attachment_id,
			'time'          => current_time( 'mysql' ),
		);
		update_option( self::USED_OPTION, $used, false );
	}

	private static function used(): array {
		$used = get_option( self::USED_OPTION, array() );
		return is_array( $used ) ? $used : array();
	}

	public static function used_shutterstock_ids(): array {
		$ids  = array();
		$used = self::used();
		foreach ( array_keys( $used ) as $image_id ) {
			$image_id = sanitize_text_field( (string) $image_id );
			if ( '' !== $image_id ) {
				$ids[] = $image_id;
			}
		}

		foreach ( self::shutterstock_ids_from_attachments() as $image_id ) {
			$ids[] = $image_id;
		}

		return array_values( array_unique( array_filter( array_map( 'strval', $ids ) ) ) );
	}

	/**
	 * Synchronize the used-IDs option with attachment postmeta. Side-effecting.
	 * Call this from explicit maintenance paths (e.g. activation hook or a
	 * dedicated REST/tools endpoint), never from the search hot path.
	 */
	public static function sync_used_ids_from_attachments(): void {
		$used    = self::used();
		$changed = false;

		$attachments = get_posts(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'any',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					array(
						'key'     => '_ssia_shutterstock_id',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		foreach ( $attachments as $attachment_id ) {
			$image_id = sanitize_text_field( (string) get_post_meta( absint( $attachment_id ), '_ssia_shutterstock_id', true ) );
			if ( '' === $image_id || ! empty( $used[ $image_id ] ) ) {
				continue;
			}
			$used[ $image_id ] = array(
				'post_id'       => absint( get_post_field( 'post_parent', absint( $attachment_id ) ) ),
				'attachment_id' => absint( $attachment_id ),
				'time'          => current_time( 'mysql' ),
			);
			$changed = true;
		}

		if ( $changed ) {
			update_option( self::USED_OPTION, $used, false );
		}
	}

	private static function shutterstock_ids_from_attachments(): array {
		$attachments = get_posts(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'any',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					array(
						'key'     => '_ssia_shutterstock_id',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$ids = array();
		foreach ( $attachments as $attachment_id ) {
			$image_id = sanitize_text_field( (string) get_post_meta( absint( $attachment_id ), '_ssia_shutterstock_id', true ) );
			if ( '' !== $image_id ) {
				$ids[] = $image_id;
			}
		}
		return $ids;
	}

	private static function reservations(): array {
		$reservations = get_option( self::RESERVATIONS_OPTION, array() );
		return is_array( $reservations ) ? $reservations : array();
	}

	private static function cleanup_expired_reservations(): void {
		$reservations = self::reservations();
		$now          = time();
		$changed      = false;
		foreach ( $reservations as $image_id => $reservation ) {
			if ( ! is_array( $reservation ) || absint( $reservation['expires_at'] ?? 0 ) <= $now ) {
				unset( $reservations[ $image_id ] );
				$changed = true;
			}
		}
		if ( $changed ) {
			update_option( self::RESERVATIONS_OPTION, $reservations, false );
		}
	}

	private function alt_text( int $post_id ): string {
		$title   = $post_id ? get_the_title( $post_id ) : __( 'licensed Shutterstock image', 'seo-shutterstock-image-assistant' );
		$keyword = $post_id ? get_post_meta( $post_id, '_yoast_wpseo_focuskw', true ) ?: get_post_meta( $post_id, 'rank_math_focus_keyword', true ) : '';
		$base    = $keyword ? $keyword : $title;
		/* translators: %s: post title or SEO focus keyword used as image alt text context. */
		$text    = sprintf( __( '%s shown in a professional context', 'seo-shutterstock-image-assistant' ), $base );
		return sanitize_text_field( wp_trim_words( $text, 16, '' ) );
	}
}
