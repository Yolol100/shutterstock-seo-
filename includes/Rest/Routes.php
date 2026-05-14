<?php
namespace Company\SeoShutterstockAssistant\Rest;

use Company\SeoShutterstockAssistant\Admin\Dashboard;
use Company\SeoShutterstockAssistant\Admin\Settings;
use Company\SeoShutterstockAssistant\ACF\FieldMapper;
use Company\SeoShutterstockAssistant\Media\ImageImporter;
use Company\SeoShutterstockAssistant\Queue\QueueManager;
use Company\SeoShutterstockAssistant\SEO\QueryBuilder;
use Company\SeoShutterstockAssistant\Security\Capabilities;
use Company\SeoShutterstockAssistant\Security\QualityGate;
use Company\SeoShutterstockAssistant\Shutterstock\Client;
use Company\SeoShutterstockAssistant\Shutterstock\LicenseService;
use Company\SeoShutterstockAssistant\Shutterstock\SearchService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Routes {
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes(): void {
		register_rest_route( 'ssia/v1', '/dashboard', array( 'methods' => 'GET', 'callback' => array( $this, 'dashboard' ), 'permission_callback' => array( $this, 'can_edit' ) ) );
		register_rest_route( 'ssia/v1', '/quality-gate', array( 'methods' => 'GET', 'callback' => array( $this, 'quality_gate' ), 'permission_callback' => array( $this, 'can_manage' ) ) );
		register_rest_route( 'ssia/v1', '/pages', array( 'methods' => 'GET', 'callback' => array( $this, 'pages' ), 'permission_callback' => array( $this, 'can_edit' ), 'args' => $this->page_args() ) );
		register_rest_route( 'ssia/v1', '/suggestions', array( 'methods' => 'POST', 'callback' => array( $this, 'suggestions' ), 'permission_callback' => array( $this, 'can_edit' ), 'args' => $this->post_id_args() ) );
		register_rest_route( 'ssia/v1', '/license-attach', array( 'methods' => 'POST', 'callback' => array( $this, 'license_attach' ), 'permission_callback' => array( $this, 'can_license' ), 'args' => $this->license_args() ) );
		register_rest_route( 'ssia/v1', '/settings', array(
			array( 'methods' => 'GET', 'callback' => array( $this, 'settings' ), 'permission_callback' => array( $this, 'can_manage' ) ),
			array( 'methods' => 'POST', 'callback' => array( $this, 'save_settings' ), 'permission_callback' => array( $this, 'can_manage' ) ),
		) );
		register_rest_route( 'ssia/v1', '/test-connection', array( 'methods' => 'POST', 'callback' => array( $this, 'test_connection' ), 'permission_callback' => array( $this, 'can_manage' ) ) );
		register_rest_route( 'ssia/v1', '/oauth-url', array( 'methods' => 'POST', 'callback' => array( $this, 'oauth_url' ), 'permission_callback' => array( $this, 'can_manage' ) ) );
		register_rest_route( 'ssia/v1', '/oauth-exchange', array( 'methods' => 'POST', 'callback' => array( $this, 'oauth_exchange' ), 'permission_callback' => array( $this, 'can_manage' ), 'args' => array( 'code' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ), 'state' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ) ) ) );
		register_rest_route( 'ssia/v1', '/scope-check', array( 'methods' => 'POST', 'callback' => array( $this, 'scope_check' ), 'permission_callback' => array( $this, 'can_manage' ) ) );
		register_rest_route( 'ssia/v1', '/licensed-assets', array( 'methods' => 'GET', 'callback' => array( $this, 'licensed_assets' ), 'permission_callback' => array( $this, 'can_manage' ), 'args' => array( 'per_page' => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ) ) ) );
		register_rest_route( 'ssia/v1', '/licensed-assets/download', array( 'methods' => 'POST', 'callback' => array( $this, 'redownload_asset' ), 'permission_callback' => array( $this, 'can_manage' ), 'args' => array( 'license_id' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ), 'image_id' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ), 'post_id' => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ), 'import' => array( 'type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean' ), 'size' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ) ) ) );
		register_rest_route( 'ssia/v1', '/licensed-assets/bulk-download', array( 'methods' => 'POST', 'callback' => array( $this, 'bulk_redownload_assets' ), 'permission_callback' => array( $this, 'can_manage' ), 'args' => array( 'assets' => array( 'type' => 'array', 'required' => true ), 'post_id' => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ), 'import' => array( 'type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean' ), 'size' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ) ) ) );
		register_rest_route( 'ssia/v1', '/queue', array(
			array( 'methods' => 'GET', 'callback' => array( $this, 'queue' ), 'permission_callback' => array( $this, 'can_edit' ) ),
			array( 'methods' => 'POST', 'callback' => array( $this, 'enqueue' ), 'permission_callback' => array( $this, 'can_edit' ) ),
		) );
		register_rest_route( 'ssia/v1', '/recover', array( 'methods' => 'POST', 'callback' => array( $this, 'recover' ), 'permission_callback' => array( $this, 'can_license' ), 'args' => $this->recover_args() ) );
		register_rest_route( 'ssia/v1', '/logs', array( 'methods' => 'GET', 'callback' => array( $this, 'logs' ), 'permission_callback' => array( $this, 'can_manage' ) ) );
	}

	public function can_edit(): bool {
		return current_user_can( Capabilities::EDIT );
	}

	public function can_manage(): bool {
		return current_user_can( Capabilities::MANAGE );
	}

	public function can_license(): bool {
		return Settings::can_license();
	}

	public function dashboard(): WP_REST_Response {
		return new WP_REST_Response( ( new Dashboard() )->stats() );
	}

	public function quality_gate(): WP_REST_Response {
		return new WP_REST_Response( ( new QualityGate() )->report() );
	}

	public function settings(): WP_REST_Response {
		return new WP_REST_Response( Settings::public_settings() );
	}

	public function save_settings( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( Settings::update_from_rest( (array) $request->get_json_params() ) );
	}

	public function test_connection(): WP_REST_Response|WP_Error {
		$response = ( new Client() )->test_connection();
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return new WP_REST_Response( $response );
	}


	public function oauth_url(): WP_REST_Response|WP_Error {
		$response = ( new Client() )->oauth_authorize_url();
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return new WP_REST_Response( $response );
	}

	public function oauth_exchange( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$response = ( new Client() )->exchange_authorization_code( (string) $request->get_param( 'code' ), (string) $request->get_param( 'state' ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return new WP_REST_Response( $response );
	}

	public function scope_check(): WP_REST_Response|WP_Error {
		$response = ( new Client() )->check_required_scopes();
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return new WP_REST_Response( $response );
	}

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
			$attachment_id = ( new ImageImporter() )->import_download( $image_id, $download_url, $post_id );
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
				'download_url' => $download_url,
				'size' => sanitize_key( (string) ( $response['size'] ?? $size ) ),
			);

			if ( $import ) {
				if ( '' === $image_id ) {
					$failed[] = array( 'license_id' => $license_id, 'message' => __( 'Missing image ID for import.', 'seo-shutterstock-image-assistant' ) );
					continue;
				}
				$attachment_id = $importer->import_download( $image_id, $download_url, $post_id );
				if ( is_wp_error( $attachment_id ) ) {
					$failed[] = array( 'license_id' => $license_id, 'image_id' => $image_id, 'message' => $attachment_id->get_error_message() );
					continue;
				}
				$imported[] = array( 'license_id' => $license_id, 'image_id' => $image_id, 'attachment_id' => absint( $attachment_id ) );
			}
		}

		$message = $import
			? sprintf( /* translators: 1: imported count, 2: failed count */ __( 'Bulk import complete: %1$d imported, %2$d failed.', 'seo-shutterstock-image-assistant' ), count( $imported ), count( $failed ) )
			: sprintf( /* translators: 1: download count, 2: failed count */ __( 'Bulk download links ready: %1$d ready, %2$d failed.', 'seo-shutterstock-image-assistant' ), count( $downloads ), count( $failed ) );

		$response = new WP_REST_Response( array( 'downloads' => $downloads, 'imported' => $imported, 'failed' => $failed, 'message' => $message ) );
		if ( ! empty( $failed ) && ( ! empty( $downloads ) || ! empty( $imported ) ) ) {
			$response->set_status( 207 );
		}
		return $response;
	}

	public function pages( WP_REST_Request $request ): WP_REST_Response {
		$filters = array(
			'post_type' => sanitize_key( (string) $request->get_param( 'post_type' ) ),
			'status'    => sanitize_key( (string) $request->get_param( 'status' ) ),
			'search'    => sanitize_text_field( (string) $request->get_param( 'search' ) ),
			'per_page'  => absint( $request->get_param( 'per_page' ) ),
			'page'      => absint( $request->get_param( 'page' ) ),
		);
		$items = ( new QueryBuilder() )->find_pages( rest_sanitize_boolean( $request->get_param( 'missing' ) ), $filters );
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
		if ( 3 !== count( $image_ids ) ) {
			return new WP_Error( 'ssia_exactly_three', __( 'Select exactly 3 images for this page.', 'seo-shutterstock-image-assistant' ), array( 'status' => 400 ) );
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
			QueueManager::update_status( $post_id, 'failed', array( 'message' => $licensed->get_error_message(), 'selected' => $image_ids ) );
			return $licensed;
		}
		QueueManager::update_status( $post_id, 'licensed', array( 'selected' => $image_ids ) );

		$attachments = ( new ImageImporter() )->import_many( $licensed, $post_id );
		if ( 3 !== count( $attachments ) ) {
			ImageImporter::release_reservations( $image_ids, $post_id );
			QueueManager::update_status( $post_id, 'failed', array( 'message' => __( 'Not all images could be imported.', 'seo-shutterstock-image-assistant' ), 'selected' => $image_ids, 'licensed' => $licensed, 'attachments' => $attachments, 'recovery' => 'redownload_or_retry_import' ) );
			return new WP_Error( 'ssia_import_incomplete', __( 'Not all images could be imported. Licensed images were not attached to ACF. Use Logs to redownload or retry import.', 'seo-shutterstock-image-assistant' ), array( 'status' => 500 ) );
		}

		$attached = ( new FieldMapper() )->attach( $post_id, $attachments );
		if ( is_wp_error( $attached ) ) {
			QueueManager::update_status( $post_id, 'imported_needs_acf', array( 'message' => $attached->get_error_message(), 'attachments' => $attachments, 'selected' => $image_ids, 'recovery' => 'fix_acf_mapping_then_retry_attach' ) );
			return $attached;
		}

		QueueManager::update_status( $post_id, 'attached', array( 'attachments' => $attachments, 'selected' => $image_ids, 'message' => __( 'Images attached successfully', 'seo-shutterstock-image-assistant' ) ) );
		return new WP_REST_Response( array( 'attachments' => $attachments, 'message' => __( 'Images attached successfully', 'seo-shutterstock-image-assistant' ) ) );
	}

	public function queue(): WP_REST_Response {
		return new WP_REST_Response( array( 'items' => array_values( array_filter( QueueManager::all(), 'is_array' ) ), 'counts' => QueueManager::counts() ) );
	}

	public function enqueue( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_ids = array_filter( array_map( 'absint', (array) $request->get_param( 'post_ids' ) ) );
		if ( empty( $post_ids ) ) {
			$post_ids = array_filter( array( absint( $request->get_param( 'post_id' ) ) ) );
		}
		if ( empty( $post_ids ) ) {
			return new WP_Error( 'ssia_missing_queue_posts', __( 'Select one or more pages before adding them to the queue.', 'seo-shutterstock-image-assistant' ), array( 'status' => 400 ) );
		}
		foreach ( $post_ids as $post_id ) {
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return new WP_Error( 'ssia_forbidden', __( 'You cannot edit one or more selected pages.', 'seo-shutterstock-image-assistant' ), array( 'status' => 403 ) );
			}
		}
		$items = ( new QueueManager() )->enqueue_many( $post_ids );
		return new WP_REST_Response( array( 'items' => $items, 'message' => __( 'Pages added to the queue.', 'seo-shutterstock-image-assistant' ) ) );
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
			$attachments = array_values( array_filter( array_map( 'absint', (array) ( $item['attachments'] ?? array() ) ) ) );
			if ( 3 !== count( $attachments ) ) {
				return new WP_Error( 'ssia_recovery_missing_attachments', __( 'Three imported attachments are required before retrying ACF attach.', 'seo-shutterstock-image-assistant' ), array( 'status' => 400 ) );
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
			$licensed = array_values( array_filter( (array) ( $item['licensed'] ?? array() ), 'is_array' ) );
			if ( 3 !== count( $licensed ) ) {
				return new WP_Error( 'ssia_recovery_missing_license_data', __( 'Three licensed download records are required before retrying import.', 'seo-shutterstock-image-assistant' ), array( 'status' => 400 ) );
			}
			$attachments = ( new ImageImporter() )->recover_imports( $licensed, $post_id );
			if ( 3 !== count( $attachments ) ) {
				QueueManager::update_status( $post_id, 'failed', array( 'message' => __( 'Recovery import still did not produce all 3 attachments.', 'seo-shutterstock-image-assistant' ), 'licensed' => $licensed, 'attachments' => $attachments, 'recovery' => 'retry_import' ) );
				return new WP_Error( 'ssia_recovery_import_incomplete', __( 'Recovery import still did not produce all 3 attachments.', 'seo-shutterstock-image-assistant' ), array( 'status' => 500 ) );
			}
			$attached = ( new FieldMapper() )->attach( $post_id, $attachments );
			if ( is_wp_error( $attached ) ) {
				QueueManager::update_status( $post_id, 'imported_needs_acf', array( 'message' => $attached->get_error_message(), 'attachments' => $attachments, 'licensed' => $licensed, 'recovery' => 'fix_acf_mapping_then_retry_attach' ) );
				return $attached;
			}
			QueueManager::update_status( $post_id, 'attached', array( 'attachments' => $attachments, 'licensed' => $licensed, 'message' => __( 'Import and ACF attach recovered successfully.', 'seo-shutterstock-image-assistant' ) ) );
			return new WP_REST_Response( array( 'attachments' => $attachments, 'message' => __( 'Import and ACF attach recovered successfully.', 'seo-shutterstock-image-assistant' ) ) );
		}

		if ( 'retry_suggestions' === $action ) {
			( new QueueManager() )->enqueue( $post_id );
			return new WP_REST_Response( array( 'message' => __( 'Page was queued again.', 'seo-shutterstock-image-assistant' ) ) );
		}

		return new WP_Error( 'ssia_unknown_recovery_action', __( 'Unknown recovery action.', 'seo-shutterstock-image-assistant' ), array( 'status' => 400 ) );
	}

	public function logs(): WP_REST_Response {
		return new WP_REST_Response( array( 'items' => \Company\SeoShutterstockAssistant\Logs\Logger::latest( 50 ) ) );
	}

	private function post_id_args(): array {
		return array( 'post_id' => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint', 'validate_callback' => static fn( $value ): bool => absint( $value ) > 0 ) );
	}

	private function license_args(): array {
		return array_merge( $this->post_id_args(), array( 'image_ids' => array( 'required' => true, 'type' => 'array', 'validate_callback' => static fn( $value ): bool => is_array( $value ) && 3 === count( array_filter( $value ) ) ) ) );
	}

	private function recover_args(): array {
		return array(
			'post_id'     => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint', 'validate_callback' => static fn( $value ): bool => absint( $value ) > 0 ),
			'action_type' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_key', 'validate_callback' => static fn( $value ): bool => in_array( sanitize_key( (string) $value ), array( 'retry_acf', 'retry_import', 'retry_suggestions' ), true ) ),
		);
	}

	private function page_args(): array {
		return array(
			'missing'   => array( 'type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean' ),
			'post_type' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
			'status'    => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
			'search'    => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
			'per_page'  => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
			'page'      => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
		);
	}
}
