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
		if ( count( $image_ids ) < 1 || count( $image_ids ) > 4 ) {
			return new WP_Error( 'ssia_select_one_to_four', __( 'Select 1 to 4 images for this page.', 'seo-shutterstock-image-assistant' ), array( 'status' => 400 ) );
		}
		if ( empty( $settings['access_token'] ) ) {
			return new WP_Error( 'ssia_missing_access_token', __( 'Add a Shutterstock access token with licensing scopes before licensing.', 'seo-shutterstock-image-assistant' ), array( 'status' => 400 ) );
		}
		$size = sanitize_key( (string) ( $settings['license_size'] ?? 'huge' ) );
		if ( 'supersize' === $size ) {
			$size = 'huge';
		}
		if ( ! in_array( $size, array( 'small', 'medium', 'huge', 'vector' ), true ) ) {
			$size = 'huge';
		}

		$client = new Client();
		$subscription_id = $client->selected_subscription_id( (string) ( $settings['subscription_id'] ?? '' ) );
		if ( is_wp_error( $subscription_id ) ) {
			return $subscription_id;
		}

		$payload = array(
			'images' => array_map(
				static function ( string $image_id ) use ( $settings, $size, $subscription_id ): array {
					return array_filter(
						array(
							'image_id'        => $image_id,
							'subscription_id' => $subscription_id,
							'size'            => $size,
							'price'           => '' !== (string) ( $settings['license_price'] ?? '' ) ? (float) $settings['license_price'] : null,
							'metadata'        => array( 'customer_id' => (string) ( $settings['license_customer_id'] ?: 'wordpress-site' ) ),
						),
						static fn( mixed $value ): bool => null !== $value
					);
				},
				$image_ids
			),
		);

		// Shutterstock's current examples send subscription_id with each image item.
		// Keep size in the item as well, so the license request is self-contained.
		$response = $client->post( 'images/licenses', $payload );
		if ( is_wp_error( $response ) ) {
			( new Logger() )->error( 'license_failed', array( 'message' => $response->get_error_message() ) );
			return $response;
		}

		$response_errors = array();
		if ( ! empty( $response['errors'] ) ) {
			$response_errors[] = is_array( $response['errors'] ) ? wp_json_encode( $response['errors'] ) : (string) $response['errors'];
		}

		$licensed = array();
		$item_errors = array();
		foreach ( (array) ( $response['data'] ?? array() ) as $item ) {
			if ( is_array( $item ) && ! empty( $item['error'] ) ) {
				$item_errors[] = sanitize_text_field( (string) $item['error'] );
				( new Logger() )->error( 'license_item_error', array( 'message' => sanitize_text_field( (string) $item['error'] ), 'subscription_id' => $subscription_id ) );
				continue;
			}
			if ( ! is_array( $item ) ) {
				continue;
			}
			$download = isset( $item['download'] ) && is_array( $item['download'] ) ? $item['download'] : array();
			$image_id = sanitize_text_field( (string) ( $item['image_id'] ?? $item['id'] ?? '' ) );
			$url      = esc_url_raw( (string) ( $download['url'] ?? $item['download_url'] ?? '' ) );
			if ( '' !== $image_id && '' !== $url ) {
				$licensed[] = array(
					'image_id'         => $image_id,
					'download_url'     => $url,
					'size'             => $size,
					'license_id'       => sanitize_text_field( (string) ( $item['license_id'] ?? $item['id'] ?? '' ) ),
					'contributor_name' => $this->extract_contributor_name( $item ),
				);
				( new Logger() )->info( 'license_success', array( 'image_id' => $image_id ) );
			}
		}

		if ( ! empty( $response_errors ) || ! empty( $item_errors ) || count( $licensed ) !== count( $image_ids ) ) {
			$message = __( 'Licensing did not return all selected download URLs. Successful licenses were saved for recovery where possible.', 'seo-shutterstock-image-assistant' );
			( new Logger() )->error( 'license_partial_response', array( 'received' => count( $licensed ), 'expected' => count( $image_ids ), 'errors' => implode( ' | ', array_merge( $response_errors, $item_errors ) ) ) );
			return new WP_Error(
				empty( $licensed ) ? 'ssia_license_response_errors' : 'ssia_partial_license',
				$message,
				array(
					'status'              => empty( $licensed ) ? 400 : 207,
					'licensed'            => $licensed,
					'requested_image_ids' => $image_ids,
					'errors'              => array_values( array_filter( array_merge( $response_errors, $item_errors ) ) ),
					'recovery'            => empty( $licensed ) ? '' : 'retry_import',
				)
			);
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
				'image_id'         => $image_id,
				'license_id'       => sanitize_text_field( (string) ( $item['id'] ?? $item['license_id'] ?? '' ) ),
				'is_downloadable'  => ! empty( $item['is_downloadable'] ),
				'size'             => sanitize_text_field( (string) ( $format['size'] ?? '' ) ),
				'created_at'       => sanitize_text_field( (string) ( $item['download_time'] ?? $item['created_time'] ?? $item['created_at'] ?? '' ) ),
				'contributor_name' => $this->extract_contributor_name( $item ),
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
		if ( 'supersize' === $size ) {
			$size = 'huge';
		}
		if ( ! in_array( $size, array( 'small', 'medium', 'huge', 'vector' ), true ) ) {
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

	private function extract_contributor_name( array $item ): string {
		$contributor = isset( $item['contributor'] ) && is_array( $item['contributor'] ) ? $item['contributor'] : array();
		$image       = isset( $item['image'] ) && is_array( $item['image'] ) ? $item['image'] : array();
		$image_contributor = isset( $image['contributor'] ) && is_array( $image['contributor'] ) ? $image['contributor'] : array();

		return sanitize_text_field( (string) (
			$item['contributor_name']
			?? $contributor['display_name']
			?? $contributor['name']
			?? $contributor['id']
			?? $image_contributor['display_name']
			?? $image_contributor['name']
			?? $image_contributor['id']
			?? ''
		) );
	}
}
