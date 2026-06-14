<?php
namespace Company\SeoShutterstockAssistant\Rest;

use Company\SeoShutterstockAssistant\ACF\FieldMapper;
use Company\SeoShutterstockAssistant\Media\ImageImporter;
use Company\SeoShutterstockAssistant\Queue\QueueManager;
use Company\SeoShutterstockAssistant\SEO\QueryBuilder;
use Company\SeoShutterstockAssistant\Shutterstock\LicenseService;
use Company\SeoShutterstockAssistant\Shutterstock\SearchService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait PageWorkflowRoutesTrait {
	public function pages( WP_REST_Request $request ): WP_REST_Response {
		$filters = array(
			'post_type' => sanitize_key( (string) $request->get_param( 'post_type' ) ),
			'status'    => 'publish',
			'search'    => sanitize_text_field( (string) $request->get_param( 'search' ) ),
			'per_page'  => absint( $request->get_param( 'per_page' ) ),
			'page'      => absint( $request->get_param( 'page' ) ),
		);
		$result = ( new QueryBuilder() )->find_pages_result( rest_sanitize_boolean( $request->get_param( 'missing' ) ), $filters );
		return new WP_REST_Response( array(
			'items'    => $result['items'],
			'has_more' => ! empty( $result['has_more'] ),
		) );
	}


	public function featured_from_acf( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $request->get_param( 'post_ids' ) ) ) ) );
		$post_id  = absint( $request->get_param( 'post_id' ) );
		if ( $post_id && empty( $post_ids ) ) {
			$post_ids = array( $post_id );
		}
		$post_ids = array_slice( $post_ids, 0, 50 );

		if ( empty( $post_ids ) ) {
			return new WP_Error( 'ssia_missing_post_ids', __( 'Select at least one page.', 'seo-shutterstock-image-assistant' ), array( 'status' => 400 ) );
		}

		$mapper  = new FieldMapper();
		$results = array();
		foreach ( $post_ids as $id ) {
			if ( ! current_user_can( 'edit_post', $id ) ) {
				$results[] = array( 'post_id' => $id, 'status' => 'skipped', 'reason' => 'permission_denied', 'message' => __( 'Permission denied.', 'seo-shutterstock-image-assistant' ) );
				continue;
			}

			$result = $mapper->maybe_set_featured_image_from_first_acf( $id );
			if ( is_wp_error( $result ) ) {
				$results[] = array( 'post_id' => $id, 'status' => 'skipped', 'reason' => $result->get_error_code(), 'message' => $result->get_error_message() );
				continue;
			}

			QueueManager::update_status( $id, 'featured_from_acf', array( 'message' => __( 'Featured image set from first ACF image.', 'seo-shutterstock-image-assistant' ) ) );
			$results[] = array( 'post_id' => $id, 'status' => 'set', 'reason' => 'thumbnail_set', 'message' => __( 'Featured image set from first ACF image.', 'seo-shutterstock-image-assistant' ) );
		}

		return new WP_REST_Response( array( 'results' => $results ) );
	}


	public function keyword_search( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$params = (array) $request->get_params();
		$query  = sanitize_text_field( (string) ( $params['query'] ?? '' ) );
			$query  = substr( $query, 0, 160 );
		if ( '' === trim( $query ) ) {
			return new WP_Error( 'ssia_missing_query', __( 'Enter a keyword before searching.', 'seo-shutterstock-image-assistant' ), array( 'status' => 400 ) );
		}
		$filters = array(
			'orientation' => sanitize_key( (string) ( $params['orientation'] ?? 'horizontal' ) ),
			'image_type'  => sanitize_key( (string) ( $params['image_type'] ?? 'photo' ) ),
			'category'    => sanitize_text_field( (string) ( $params['category'] ?? '' ) ),
			'color'       => sanitize_hex_color( (string) ( $params['color'] ?? '' ) ),
			'safe'        => rest_sanitize_boolean( $params['safe'] ?? true ),
			'sort'        => sanitize_key( (string) ( $params['sort'] ?? 'relevance' ) ),
			'per_page'    => max( 1, min( 50, absint( $params['per_page'] ?? 24 ) ) ),
			'page'        => max( 1, min( 100, absint( $params['page'] ?? 1 ) ) ),
		);
		$items = ( new SearchService() )->search_keyword( $query, $filters );
		return new WP_REST_Response( array( 'items' => $items ) );
	}

	public function suggestions( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = absint( $request->get_param( 'post_id' ) );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'ssia_forbidden', __( 'You cannot edit this page.', 'seo-shutterstock-image-assistant' ), array( 'status' => 403 ) );
		}
		$queries = ( new QueryBuilder() )->build_queries( $post_id );
		$results = ( new SearchService() )->search_many( $queries );
		QueueManager::update_status( $post_id, empty( $results ) ? 'failed' : 'suggestions_found', array( 'suggestions' => wp_list_pluck( array_slice( $results, 0, 12 ), 'id' ) ) );
		return new WP_REST_Response( array( 'queries' => $queries, 'items' => $results ) );
	}

	public function license_attach( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id   = absint( $request->get_param( 'post_id' ) );
		$image_ids = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', (array) $request->get_param( 'image_ids' ) ) ) ) );
		if ( count( $image_ids ) < 1 || count( $image_ids ) > 4 ) {
			return new WP_Error( 'ssia_select_one_to_four', __( 'Select 1 to 4 images for this page.', 'seo-shutterstock-image-assistant' ), array( 'status' => 400 ) );
		}
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'ssia_forbidden', __( 'You cannot edit this page.', 'seo-shutterstock-image-assistant' ), array( 'status' => 403 ) );
		}

		$reservation = ImageImporter::reserve_images( $image_ids, $post_id );
		if ( is_wp_error( $reservation ) ) {
			QueueManager::update_status( $post_id, 'failed', array( 'message' => $reservation->get_error_message(), 'selected' => $image_ids ) );
			return $reservation;
		}

		$licensed = ( new LicenseService() )->license_images( $image_ids );
		if ( is_wp_error( $licensed ) ) {
			ImageImporter::release_reservations( $image_ids, $post_id );
			$error_data = $licensed->get_error_data( $licensed->get_error_code() );
			$error_data = is_array( $error_data ) ? $error_data : array();
			$recoverable = $this->recoverable_license_records( (array) ( $error_data['licensed'] ?? array() ) );
			$status_extra = array( 'message' => $licensed->get_error_message(), 'selected' => $image_ids );
			if ( ! empty( $recoverable ) ) {
				$status_extra['licensed'] = $recoverable;
				$status_extra['recovery'] = 'retry_import';
			}
			QueueManager::update_status( $post_id, 'failed', $status_extra );
			return $licensed;
		}
		QueueManager::update_status( $post_id, 'licensed', array( 'selected' => $image_ids ) );

		$attachments = ( new ImageImporter() )->import_many( $licensed, $post_id );
		if ( count( $attachments ) !== count( $image_ids ) ) {
			ImageImporter::release_reservations( $image_ids, $post_id );
			QueueManager::update_status( $post_id, 'failed', array( 'message' => __( 'Not all selected images could be imported.', 'seo-shutterstock-image-assistant' ), 'selected' => $image_ids, 'licensed' => $this->licensed_recovery_records( $licensed ), 'attachments' => $attachments, 'recovery' => 'redownload_or_retry_import' ) );
			return new WP_Error( 'ssia_import_incomplete', __( 'Not all selected images could be imported. Licensed images were not attached. Use Logs to redownload or retry import.', 'seo-shutterstock-image-assistant' ), array( 'status' => 500 ) );
		}

		$attached = ( new FieldMapper() )->attach( $post_id, $attachments );
		if ( is_wp_error( $attached ) ) {
			QueueManager::update_status( $post_id, 'imported_needs_acf', array( 'message' => $attached->get_error_message(), 'attachments' => $attachments, 'selected' => $image_ids, 'recovery' => 'fix_acf_mapping_then_retry_attach' ) );
			return $attached;
		}

		QueueManager::update_status( $post_id, 'attached', array( 'attachments' => $attachments, 'selected' => $image_ids, 'message' => __( 'Images attached successfully', 'seo-shutterstock-image-assistant' ) ) );
		return new WP_REST_Response( array( 'attachments' => $attachments, 'message' => __( 'Images attached successfully', 'seo-shutterstock-image-assistant' ) ) );
	}
	public function recover( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = absint( $request->get_param( 'post_id' ) );
		$action  = sanitize_key( (string) $request->get_param( 'action_type' ) );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'ssia_forbidden', __( 'You cannot recover this page.', 'seo-shutterstock-image-assistant' ), array( 'status' => 403 ) );
		}

		$item = QueueManager::get_item( $post_id );
		if ( empty( $item ) ) {
			return new WP_Error( 'ssia_recovery_missing_queue_item', __( 'No queue recovery item was found for this page.', 'seo-shutterstock-image-assistant' ), array( 'status' => 404 ) );
		}

		if ( 'retry_acf' === $action ) {
			$attachments = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $item['attachments'] ?? array() ) ) ) ) );
			if ( count( $attachments ) < 1 || count( $attachments ) > 4 ) {
				return new WP_Error( 'ssia_recovery_missing_attachments', __( 'One to four imported attachments are required before retrying ACF attach.', 'seo-shutterstock-image-assistant' ), array( 'status' => 400 ) );
			}
			$attached = ( new FieldMapper() )->attach( $post_id, $attachments );
			if ( is_wp_error( $attached ) ) {
				QueueManager::update_status( $post_id, 'imported_needs_acf', array( 'message' => $attached->get_error_message(), 'attachments' => $attachments, 'recovery' => 'fix_acf_mapping_then_retry_attach' ) );
				return $attached;
			}
			QueueManager::update_status( $post_id, 'attached', array( 'attachments' => $attachments, 'message' => __( 'ACF attach recovered successfully.', 'seo-shutterstock-image-assistant' ) ) );
			return new WP_REST_Response( array( 'attachments' => $attachments, 'message' => __( 'ACF attach recovered successfully.', 'seo-shutterstock-image-assistant' ) ) );
		}

		if ( 'retry_import' === $action ) {
			$licensed = $this->recoverable_license_records( (array) ( $item['licensed'] ?? array() ) );
			$licensed_count = count( $licensed );
			if ( $licensed_count < 1 || $licensed_count > 4 ) {
				return new WP_Error( 'ssia_recovery_missing_license_data', __( 'One to four licensed records are required before retrying import.', 'seo-shutterstock-image-assistant' ), array( 'status' => 400 ) );
			}
			$licensed = $this->ensure_recovery_downloads( $licensed );
			if ( is_wp_error( $licensed ) ) {
				return $licensed;
			}
			$attachments = ( new ImageImporter() )->recover_imports( $licensed, $post_id );
			if ( count( $attachments ) !== $licensed_count ) {
				QueueManager::update_status( $post_id, 'failed', array( 'message' => __( 'Recovery import still did not produce all selected attachments.', 'seo-shutterstock-image-assistant' ), 'licensed' => $this->licensed_recovery_records( $licensed ), 'attachments' => $attachments, 'recovery' => 'retry_import' ) );
				return new WP_Error( 'ssia_recovery_import_incomplete', __( 'Recovery import still did not produce all selected attachments.', 'seo-shutterstock-image-assistant' ), array( 'status' => 500 ) );
			}
			$attached = ( new FieldMapper() )->attach( $post_id, $attachments );
			if ( is_wp_error( $attached ) ) {
				QueueManager::update_status( $post_id, 'imported_needs_acf', array( 'message' => $attached->get_error_message(), 'attachments' => $attachments, 'licensed' => $this->licensed_recovery_records( $licensed ), 'recovery' => 'fix_acf_mapping_then_retry_attach' ) );
				return $attached;
			}
			QueueManager::update_status( $post_id, 'attached', array( 'attachments' => $attachments, 'licensed' => $this->licensed_recovery_records( $licensed ), 'message' => __( 'Import and ACF attach recovered successfully.', 'seo-shutterstock-image-assistant' ) ) );
			return new WP_REST_Response( array( 'attachments' => $attachments, 'message' => __( 'Import and ACF attach recovered successfully.', 'seo-shutterstock-image-assistant' ) ) );
		}

		if ( 'retry_suggestions' === $action ) {
			( new QueueManager() )->enqueue( $post_id );
			return new WP_REST_Response( array( 'message' => __( 'Page was queued again.', 'seo-shutterstock-image-assistant' ) ) );
		}

		return new WP_Error( 'ssia_unknown_recovery_action', __( 'Unknown recovery action.', 'seo-shutterstock-image-assistant' ), array( 'status' => 400 ) );
	}

	private function licensed_recovery_records( array $licensed ): array {
		$records = array();
		foreach ( array_slice( array_values( $licensed ), 0, 4 ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$image_id   = sanitize_text_field( (string) ( $item['image_id'] ?? '' ) );
			$license_id = sanitize_text_field( (string) ( $item['license_id'] ?? '' ) );
			if ( '' === $image_id || '' === $license_id ) {
				continue;
			}
			$records[] = array_filter(
				array(
					'image_id'         => $image_id,
					'license_id'       => $license_id,
					'size'             => sanitize_key( (string) ( $item['size'] ?? '' ) ),
					'contributor_name' => sanitize_text_field( (string) ( $item['contributor_name'] ?? '' ) ),
				),
				static fn( mixed $value ): bool => '' !== $value
			);
		}
		return $records;
	}

	private function recoverable_license_records( array $licensed ): array {
		$records = array();
		foreach ( array_slice( array_values( $licensed ), 0, 4 ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$image_id   = sanitize_text_field( (string) ( $item['image_id'] ?? '' ) );
			$license_id = sanitize_text_field( (string) ( $item['license_id'] ?? '' ) );
			if ( '' === $image_id || '' === $license_id ) {
				continue;
			}
			$record = array(
				'image_id'         => $image_id,
				'license_id'       => $license_id,
				'size'             => sanitize_key( (string) ( $item['size'] ?? '' ) ),
				'contributor_name' => sanitize_text_field( (string) ( $item['contributor_name'] ?? '' ) ),
			);
			if ( ! empty( $item['download_url'] ) ) {
				$record['download_url'] = esc_url_raw( (string) $item['download_url'] );
			}
			$records[] = $record;
		}
		return $records;
	}

	private function ensure_recovery_downloads( array $licensed ): array|WP_Error {
		$service = new LicenseService();
		$records = array();
		foreach ( $licensed as $item ) {
			$download_url = esc_url_raw( (string) ( $item['download_url'] ?? '' ) );
			if ( '' === $download_url ) {
				$download = $service->redownload( (string) ( $item['license_id'] ?? '' ), (string) ( $item['size'] ?? '' ) );
				if ( is_wp_error( $download ) ) {
					return $download;
				}
				$download_url = esc_url_raw( (string) ( $download['download_url'] ?? '' ) );
			}
			if ( '' === $download_url ) {
				return new WP_Error( 'ssia_recovery_missing_download_url', __( 'Shutterstock did not return a fresh download URL for recovery.', 'seo-shutterstock-image-assistant' ), array( 'status' => 502 ) );
			}
			$records[] = array_merge( $item, array( 'download_url' => $download_url ) );
		}
		return $records;
	}

}