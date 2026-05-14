<?php
namespace Company\SeoShutterstockAssistant\Shutterstock;

use Company\SeoShutterstockAssistant\Admin\Settings;
use Company\SeoShutterstockAssistant\Logs\Logger;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LicenseService {
	public function license_images( array $image_ids ): array|WP_Error {
		$settings  = Settings::get();
		$image_ids = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $image_ids ) ) ) );
		if ( 3 !== count( $image_ids ) ) {
			return new WP_Error( 'ssia_exactly_three', __( 'Select exactly 3 images for this page.', 'seo-shutterstock-image-assistant' ), array( 'status' => 400 ) );
		}
		if ( empty( $settings['access_token'] ) ) {
			return new WP_Error( 'ssia_missing_access_token', __( 'Add a Shutterstock access token with licensing scopes before licensing.', 'seo-shutterstock-image-assistant' ), array( 'status' => 400 ) );
		}
		if ( empty( $settings['subscription_id'] ) ) {
			return new WP_Error( 'ssia_missing_subscription', __( 'Add a Shutterstock subscription ID before licensing.', 'seo-shutterstock-image-assistant' ), array( 'status' => 400 ) );
		}

		$size = sanitize_key( (string) ( $settings['license_size'] ?? 'huge' ) );
		if ( ! in_array( $size, array( 'small', 'medium', 'huge', 'supersize', 'vector' ), true ) ) {
			$size = 'huge';
		}

		$payload = array(
			'images' => array_map(
				static function ( string $image_id ) use ( $settings, $size ): array {
					return array(
						'image_id'        => $image_id,
						'subscription_id' => $settings['subscription_id'],
						'format'          => $settings['license_format'] ?? 'jpg',
						'size'            => $size,
						'price'           => '' !== (string) ( $settings['license_price'] ?? '' ) ? (float) $settings['license_price'] : 0.0,
						'metadata'        => array( 'customer_id' => (string) ( $settings['license_customer_id'] ?: 'wordpress-site' ) ),
					);
				},
				$image_ids
			),
		);

		$response = ( new Client() )->post( 'images/licenses', $payload );
		if ( is_wp_error( $response ) ) {
			( new Logger() )->error( 'license_failed', array( 'message' => $response->get_error_message() ) );
			return $response;
		}

		$licensed = array();
		foreach ( (array) ( $response['data'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$download = isset( $item['download'] ) && is_array( $item['download'] ) ? $item['download'] : array();
			$image_id = sanitize_text_field( (string) ( $item['image_id'] ?? $item['id'] ?? '' ) );
			$url      = esc_url_raw( (string) ( $download['url'] ?? $item['download_url'] ?? '' ) );
			if ( '' !== $image_id && '' !== $url ) {
				$licensed[] = array( 'image_id' => $image_id, 'download_url' => $url, 'size' => $size );
				( new Logger() )->info( 'license_success', array( 'image_id' => $image_id ) );
			}
		}

		if ( 3 !== count( $licensed ) ) {
			( new Logger() )->error( 'license_partial_response', array( 'received' => count( $licensed ) ) );
			return new WP_Error( 'ssia_partial_license', __( 'Licensing did not return all 3 download URLs. Nothing was attached automatically.', 'seo-shutterstock-image-assistant' ), array( 'status' => 502 ) );
		}

		return $licensed;
	}

	public function previously_licensed( int $per_page = 20 ): array|WP_Error {
		$per_page = max( 1, min( 50, $per_page ) );
		$response = ( new Client() )->get( 'images/licenses', array( 'per_page' => $per_page ), true );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$items = array();
		foreach ( (array) ( $response['data'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$image    = isset( $item['image'] ) && is_array( $item['image'] ) ? $item['image'] : array();
			$format   = isset( $image['format'] ) && is_array( $image['format'] ) ? $image['format'] : array();
			$image_id = sanitize_text_field( (string) ( $image['id'] ?? $item['image_id'] ?? '' ) );
			if ( '' === $image_id ) {
				continue;
			}
			$items[] = array(
				'image_id'        => $image_id,
				'license_id'      => sanitize_text_field( (string) ( $item['id'] ?? $item['license_id'] ?? '' ) ),
				'is_downloadable' => ! empty( $item['is_downloadable'] ),
				'size'            => sanitize_text_field( (string) ( $format['size'] ?? '' ) ),
				'created_at'      => sanitize_text_field( (string) ( $item['download_time'] ?? $item['created_time'] ?? $item['created_at'] ?? '' ) ),
			);
		}

		return $items;
	}

	public function redownload( string $license_id, string $size = '' ): array|WP_Error {
		$settings   = Settings::get();
		$license_id = sanitize_text_field( $license_id );
		$size       = sanitize_key( $size ?: ( $settings['license_size'] ?? 'huge' ) );
		if ( '' === $license_id ) {
			return new WP_Error( 'ssia_missing_license_id', __( 'Missing Shutterstock license ID.', 'seo-shutterstock-image-assistant' ), array( 'status' => 400 ) );
		}
		if ( ! in_array( $size, array( 'small', 'medium', 'huge', 'supersize', 'vector' ), true ) ) {
			$size = 'huge';
		}
		$response = ( new Client() )->post( 'images/licenses/' . rawurlencode( $license_id ) . '/downloads', array( 'size' => $size ) );
		if ( is_wp_error( $response ) ) {
			( new Logger() )->error( 'redownload_failed', array( 'license_id' => $license_id, 'message' => $response->get_error_message() ) );
			return $response;
		}
		$download = isset( $response['download'] ) && is_array( $response['download'] ) ? $response['download'] : array();
		$url      = esc_url_raw( (string) ( $response['url'] ?? $download['url'] ?? '' ) );
		if ( '' === $url ) {
			return new WP_Error( 'ssia_redownload_missing_url', __( 'Shutterstock did not return a redownload URL for this license.', 'seo-shutterstock-image-assistant' ), array( 'status' => 502 ) );
		}
		( new Logger() )->info( 'redownload_success', array( 'license_id' => $license_id ) );
		return array( 'license_id' => $license_id, 'download_url' => $url, 'size' => $size );
	}
}
