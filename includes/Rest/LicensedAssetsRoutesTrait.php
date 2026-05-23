<?php
namespace Company\SeoShutterstockAssistant\Rest;

use Company\SeoShutterstockAssistant\Media\ImageImporter;
use Company\SeoShutterstockAssistant\Shutterstock\LicenseService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait LicensedAssetsRoutesTrait {
	public function licensed_assets( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$items = ( new LicenseService() )->previously_licensed( absint( $request->get_param( 'per_page' ) ) ?: 20 );
		if ( is_wp_error( $items ) ) {
			return $items;
		}
		return new WP_REST_Response( array( 'items' => $items ) );
	}

	public function redownload_asset( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$response = ( new LicenseService() )->redownload( (string) $request->get_param( 'license_id' ), (string) $request->get_param( 'size' ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( rest_sanitize_boolean( $request->get_param( 'import' ) ) ) {
			$image_id     = sanitize_text_field( (string) $request->get_param( 'image_id' ) );
			$download_url = esc_url_raw( (string) ( $response['download_url'] ?? '' ) );
			$post_id      = absint( $request->get_param( 'post_id' ) );
			if ( '' === $image_id ) {
				return new WP_Error( 'ssia_missing_image_id', __( 'Add the Shutterstock image ID to import this licensed asset.', 'seo-shutterstock-image-assistant' ), array( 'status' => 400 ) );
			}
			if ( '' === $download_url ) {
				return new WP_Error( 'ssia_missing_download_url', __( 'Shutterstock did not return a download URL for this licensed asset.', 'seo-shutterstock-image-assistant' ), array( 'status' => 502 ) );
			}
			if ( ! $post_id ) {
				return new WP_Error( 'ssia_import_missing_post', __( 'Select a page before importing this licensed asset.', 'seo-shutterstock-image-assistant' ), array( 'status' => 400 ) );
			}
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return new WP_Error( 'ssia_forbidden', __( 'You cannot attach media to this page.', 'seo-shutterstock-image-assistant' ), array( 'status' => 403 ) );
			}
			$attachment_id = ( new ImageImporter() )->import_download( $image_id, $download_url, $post_id, array( 'license_id' => sanitize_text_field( (string) $request->get_param( 'license_id' ) ), 'contributor_name' => sanitize_text_field( (string) $request->get_param( 'contributor_name' ) ) ) );
			if ( is_wp_error( $attachment_id ) ) {
				return $attachment_id;
			}
			$response['attachment_id'] = $attachment_id;
		}

		return new WP_REST_Response( $response );
	}

	public function bulk_redownload_assets( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$assets = $request->get_param( 'assets' );
		if ( ! is_array( $assets ) ) {
			return new WP_Error( 'ssia_missing_bulk_assets', __( 'Select one or more licensed assets first.', 'seo-shutterstock-image-assistant' ), array( 'status' => 400 ) );
		}

		$assets = array_values( array_filter( array_slice( $assets, 0, 25 ), 'is_array' ) );
		if ( empty( $assets ) ) {
			return new WP_Error( 'ssia_missing_bulk_assets', __( 'Select one or more licensed assets first.', 'seo-shutterstock-image-assistant' ), array( 'status' => 400 ) );
		}

		$import = rest_sanitize_boolean( $request->get_param( 'import' ) );
		$post_id = absint( $request->get_param( 'post_id' ) );
		$size = sanitize_key( (string) $request->get_param( 'size' ) );

		if ( $import ) {
			if ( ! $post_id ) {
				return new WP_Error( 'ssia_bulk_import_missing_post', __( 'Select a page before importing multiple assets.', 'seo-shutterstock-image-assistant' ), array( 'status' => 400 ) );
			}
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return new WP_Error( 'ssia_forbidden', __( 'You cannot attach media to this page.', 'seo-shutterstock-image-assistant' ), array( 'status' => 403 ) );
			}
		}

		$downloads = array();
		$imported = array();
		$failed = array();
		$service = new LicenseService();
		$importer = new ImageImporter();

		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) ) {
				continue;
			}

			$license_id = sanitize_text_field( (string) ( $asset['license_id'] ?? '' ) );
			$image_id = sanitize_text_field( (string) ( $asset['image_id'] ?? '' ) );
			$contributor_name = sanitize_text_field( (string) ( $asset['contributor_name'] ?? '' ) );

			if ( '' === $license_id ) {
				$failed[] = array( 'image_id' => $image_id, 'message' => __( 'Missing license ID.', 'seo-shutterstock-image-assistant' ) );
				continue;
			}

			$response = $service->redownload( $license_id, $size );
			if ( is_wp_error( $response ) ) {
				$failed[] = array( 'license_id' => $license_id, 'image_id' => $image_id, 'message' => $response->get_error_message() );
				continue;
			}

			$download_url = esc_url_raw( (string) ( $response['download_url'] ?? '' ) );
			if ( '' === $download_url ) {
				$failed[] = array( 'license_id' => $license_id, 'image_id' => $image_id, 'message' => __( 'Shutterstock did not return a download URL.', 'seo-shutterstock-image-assistant' ) );
				continue;
			}

			$downloads[] = array(
				'license_id' => $license_id,
				'image_id' => $image_id,
				'contributor_name' => $contributor_name,
				'download_url' => $download_url,
				'size' => sanitize_key( (string) ( $response['size'] ?? $size ) ),
			);

			if ( $import ) {
				if ( '' === $image_id ) {
					$failed[] = array( 'license_id' => $license_id, 'message' => __( 'Missing image ID for import.', 'seo-shutterstock-image-assistant' ) );
					continue;
				}
				$attachment_id = $importer->import_download( $image_id, $download_url, $post_id, array( 'license_id' => $license_id, 'contributor_name' => $contributor_name ) );
				if ( is_wp_error( $attachment_id ) ) {
					$failed[] = array( 'license_id' => $license_id, 'image_id' => $image_id, 'message' => $attachment_id->get_error_message() );
					continue;
				}
				$imported[] = array( 'license_id' => $license_id, 'image_id' => $image_id, 'attachment_id' => absint( $attachment_id ) );
			}
		}

		if ( $import ) {
			/* translators: 1: imported count, 2: failed count. */
			$message = sprintf( __( 'Bulk import complete: %1$d imported, %2$d failed.', 'seo-shutterstock-image-assistant' ), count( $imported ), count( $failed ) );
		} else {
			/* translators: 1: download count, 2: failed count. */
			$message = sprintf( __( 'Bulk download links ready: %1$d ready, %2$d failed.', 'seo-shutterstock-image-assistant' ), count( $downloads ), count( $failed ) );
		}

		$response = new WP_REST_Response( array( 'downloads' => $downloads, 'imported' => $imported, 'failed' => $failed, 'message' => $message ) );
		if ( ! empty( $failed ) && ( ! empty( $downloads ) || ! empty( $imported ) ) ) {
			$response->set_status( 207 );
		}
		return $response;
	}
}