<?php
namespace Company\SeoShutterstockAssistant\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Routes {
	use PermissionCallbacksTrait;
	use OverviewRoutesTrait;
	use SettingsRoutesTrait;
	use LicensedAssetsRoutesTrait;
	use PageWorkflowRoutesTrait;
	use QueueLogRoutesTrait;
	use RouteArgsTrait;

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes(): void {
		register_rest_route( 'ssia/v1', '/dashboard', array( 'methods' => 'GET', 'callback' => array( $this, 'dashboard' ), 'permission_callback' => array( $this, 'can_edit' ) ) );
		register_rest_route( 'ssia/v1', '/quality-gate', array( 'methods' => 'GET', 'callback' => array( $this, 'quality_gate' ), 'permission_callback' => array( $this, 'can_manage' ) ) );
		register_rest_route( 'ssia/v1', '/pages', array( 'methods' => 'GET', 'callback' => array( $this, 'pages' ), 'permission_callback' => array( $this, 'can_edit' ), 'args' => $this->page_args() ) );
		register_rest_route( 'ssia/v1', '/search', array( 'methods' => 'POST', 'callback' => array( $this, 'keyword_search' ), 'permission_callback' => array( $this, 'can_search' ), 'args' => $this->search_args() ) );
		register_rest_route( 'ssia/v1', '/suggestions', array( 'methods' => 'POST', 'callback' => array( $this, 'suggestions' ), 'permission_callback' => array( $this, 'can_search' ), 'args' => $this->post_id_args() ) );
		register_rest_route( 'ssia/v1', '/license-attach', array( 'methods' => 'POST', 'callback' => array( $this, 'license_attach' ), 'permission_callback' => array( $this, 'can_license' ), 'args' => $this->license_args() ) );
		register_rest_route( 'ssia/v1', '/featured-from-acf', array( 'methods' => 'POST', 'callback' => array( $this, 'featured_from_acf' ), 'permission_callback' => array( $this, 'can_edit' ), 'args' => $this->featured_from_acf_args() ) );
		register_rest_route( 'ssia/v1', '/settings', array(
			array( 'methods' => 'GET', 'callback' => array( $this, 'settings' ), 'permission_callback' => array( $this, 'can_manage' ) ),
			array( 'methods' => 'POST', 'callback' => array( $this, 'save_settings' ), 'permission_callback' => array( $this, 'can_manage' ) ),
		) );
		register_rest_route( 'ssia/v1', '/test-connection', array( 'methods' => 'POST', 'callback' => array( $this, 'test_connection' ), 'permission_callback' => array( $this, 'can_manage' ) ) );
		register_rest_route( 'ssia/v1', '/oauth-url', array( 'methods' => 'POST', 'callback' => array( $this, 'oauth_url' ), 'permission_callback' => array( $this, 'can_manage' ) ) );
		register_rest_route( 'ssia/v1', '/oauth-exchange', array( 'methods' => 'POST', 'callback' => array( $this, 'oauth_exchange' ), 'permission_callback' => array( $this, 'can_manage' ), 'args' => array( 'code' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field', 'validate_callback' => static fn( $value ): bool => is_string( $value ) && '' !== trim( $value ) && strlen( $value ) <= 512 ), 'state' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field', 'validate_callback' => static fn( $value ): bool => is_string( $value ) && '' !== trim( $value ) && strlen( $value ) <= 128 ) ) ) );
		register_rest_route( 'ssia/v1', '/scope-check', array( 'methods' => 'POST', 'callback' => array( $this, 'scope_check' ), 'permission_callback' => array( $this, 'can_manage' ) ) );
		register_rest_route( 'ssia/v1', '/licensed-assets', array( 'methods' => 'GET', 'callback' => array( $this, 'licensed_assets' ), 'permission_callback' => array( $this, 'can_manage' ), 'args' => array( 'per_page' => $this->per_page_arg() ) ) );
		register_rest_route( 'ssia/v1', '/licensed-assets/download', array( 'methods' => 'POST', 'callback' => array( $this, 'redownload_asset' ), 'permission_callback' => array( $this, 'can_manage' ), 'args' => array( 'license_id' => $this->license_id_arg(), 'image_id' => $this->optional_text_arg(), 'contributor_name' => $this->optional_text_arg(), 'post_id' => array( 'type' => 'integer', 'sanitize_callback' => 'absint', 'validate_callback' => static fn( $value ): bool => 0 <= absint( $value ) ), 'import' => array( 'type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean' ), 'size' => $this->size_arg() ) ) );
		register_rest_route( 'ssia/v1', '/licensed-assets/bulk-download', array( 'methods' => 'POST', 'callback' => array( $this, 'bulk_redownload_assets' ), 'permission_callback' => array( $this, 'can_manage' ), 'args' => array( 'assets' => $this->assets_arg(), 'post_id' => array( 'type' => 'integer', 'sanitize_callback' => 'absint', 'validate_callback' => static fn( $value ): bool => 0 <= absint( $value ) ), 'import' => array( 'type' => 'boolean', 'sanitize_callback' => 'rest_sanitize_boolean' ), 'size' => $this->size_arg() ) ) );
		register_rest_route( 'ssia/v1', '/queue', array(
			array( 'methods' => 'GET', 'callback' => array( $this, 'queue' ), 'permission_callback' => array( $this, 'can_edit' ) ),
			array( 'methods' => 'POST', 'callback' => array( $this, 'enqueue' ), 'permission_callback' => array( $this, 'can_search' ), 'args' => $this->queue_args() ),
		) );
		register_rest_route( 'ssia/v1', '/recover', array( 'methods' => 'POST', 'callback' => array( $this, 'recover' ), 'permission_callback' => array( $this, 'can_license' ), 'args' => $this->recover_args() ) );
		register_rest_route( 'ssia/v1', '/logs', array( 'methods' => 'GET', 'callback' => array( $this, 'logs' ), 'permission_callback' => array( $this, 'can_manage' ) ) );
	}
}
