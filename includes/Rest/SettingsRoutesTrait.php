<?php
namespace Company\SeoShutterstockAssistant\Rest;

use Company\SeoShutterstockAssistant\Admin\Settings;
use Company\SeoShutterstockAssistant\Shutterstock\Client;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait SettingsRoutesTrait {
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
}