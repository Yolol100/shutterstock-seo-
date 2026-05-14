<?php
namespace Company\SeoShutterstockAssistant\Shutterstock;

use Company\SeoShutterstockAssistant\Admin\Settings;
use Company\SeoShutterstockAssistant\Logs\Logger;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Client {
	private const BASE       = 'https://api.shutterstock.com/v2';
	private const AUTH_BASE  = 'https://api.shutterstock.com/v2/oauth/authorize';
	private const TOKEN_BASE = 'https://api.shutterstock.com/v2/oauth/access_token';

	public function get( string $path, array $args = array(), bool $require_bearer = false ): array|WP_Error {
		$url = add_query_arg( $args, self::BASE . '/' . ltrim( $path, '/' ) );
		return $this->request( 'GET', $url, array(), $require_bearer );
	}

	public function post( string $path, array $body = array(), bool $require_bearer = true ): array|WP_Error {
		return $this->request( 'POST', self::BASE . '/' . ltrim( $path, '/' ), $body, $require_bearer );
	}

	public function required_scopes(): array {
		return array( 'licenses.create', 'licenses.view', 'purchases.view' );
	}

	public function oauth_authorize_url(): array|WP_Error {
		$settings = Settings::get();
		if ( empty( $settings['oauth_client_id'] ) ) {
			return new WP_Error( 'ssia_missing_oauth_client_id', __( 'Add your Shutterstock OAuth client ID first.', 'seo-shutterstock-image-assistant' ), array( 'status' => 400 ) );
		}

		$redirect_uri = ! empty( $settings['oauth_redirect_uri'] ) ? $settings['oauth_redirect_uri'] : admin_url( 'admin.php?page=ssia-dashboard' );
		$state        = wp_generate_password( 32, false, false );
		Settings::update_partial( array( 'oauth_state' => $state, 'oauth_redirect_uri' => $redirect_uri ) );

		$url = add_query_arg(
			array(
				'client_id'     => $settings['oauth_client_id'],
				'response_type' => 'code',
				'redirect_uri'  => $redirect_uri,
				'scope'         => implode( ' ', $this->required_scopes() ),
				'state'         => $state,
			),
			self::AUTH_BASE
		);

		return array(
			'url'             => esc_url_raw( $url ),
			'redirect_uri'    => esc_url_raw( $redirect_uri ),
			'required_scopes' => $this->required_scopes(),
			'message'         => __( 'Open Shutterstock and approve the app. The callback can exchange the returned authorization code for tokens.', 'seo-shutterstock-image-assistant' ),
		);
	}

	public function exchange_authorization_code( string $code, string $state ): array|WP_Error {
		$settings = Settings::get();
		$code     = sanitize_text_field( $code );
		$state    = sanitize_text_field( $state );

		if ( '' === $code ) {
			return new WP_Error( 'ssia_missing_code', __( 'Missing Shutterstock authorization code.', 'seo-shutterstock-image-assistant' ), array( 'status' => 400 ) );
		}
		if ( empty( $settings['oauth_state'] ) || ! hash_equals( (string) $settings['oauth_state'], $state ) ) {
			return new WP_Error( 'ssia_invalid_oauth_state', __( 'OAuth state check failed. Please start the connection again.', 'seo-shutterstock-image-assistant' ), array( 'status' => 403 ) );
		}
		if ( empty( $settings['oauth_client_id'] ) || empty( $settings['oauth_client_secret'] ) ) {
			return new WP_Error( 'ssia_missing_oauth_credentials', __( 'Add OAuth client ID and client secret before exchanging a code.', 'seo-shutterstock-image-assistant' ), array( 'status' => 400 ) );
		}

		$response = wp_remote_post(
			self::TOKEN_BASE,
			array(
				'timeout' => 20,
				'headers' => $this->base_headers(),
				'body'    => array(
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'client_id'     => $settings['oauth_client_id'],
					'client_secret' => $settings['oauth_client_secret'],
					'redirect_uri'  => $settings['oauth_redirect_uri'],
					'expires'       => 'true',
				),
			)
		);

		$tokens = $this->parse_token_response( $response );
		if ( is_wp_error( $tokens ) ) {
			( new Logger() )->error( 'oauth_exchange_failed', array( 'message' => $tokens->get_error_message() ) );
			return $tokens;
		}

		Settings::update_partial( $tokens + array( 'oauth_state' => '' ) );
		( new Logger() )->info( 'oauth_exchange_success' );
		return array( 'connected' => true, 'message' => __( 'Shutterstock OAuth connection completed.', 'seo-shutterstock-image-assistant' ) );
	}

	public function refresh_access_token(): bool|WP_Error {
		$settings = Settings::get();
		if ( empty( $settings['refresh_token'] ) || empty( $settings['oauth_client_id'] ) || empty( $settings['oauth_client_secret'] ) ) {
			return false;
		}

		$response = wp_remote_post(
			self::TOKEN_BASE,
			array(
				'timeout' => 20,
				'headers' => $this->base_headers(),
				'body'    => array(
					'grant_type'    => 'refresh_token',
					'refresh_token' => $settings['refresh_token'],
					'client_id'     => $settings['oauth_client_id'],
					'client_secret' => $settings['oauth_client_secret'],
				),
			)
		);

		$tokens = $this->parse_token_response( $response );
		if ( is_wp_error( $tokens ) ) {
			return $tokens;
		}
		Settings::update_partial( $tokens );
		return true;
	}

	public function test_connection(): array|WP_Error {
		$settings = Settings::get();
		if ( ! empty( $settings['access_token'] ) ) {
			$response = $this->get( 'images/search', array( 'query' => 'business', 'per_page' => 1, 'image_type' => 'photo', 'safe' => 'true' ), true );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$scope = $this->check_required_scopes();
			return array(
				'connected' => true,
				'auth_mode'  => 'oauth',
				'scope_ok'   => ! is_wp_error( $scope ),
				'message'    => is_wp_error( $scope ) ? $scope->get_error_message() : __( 'Shutterstock OAuth connection and licensing scope check passed.', 'seo-shutterstock-image-assistant' ),
			);
		}

		$response = $this->get( 'images/search', array( 'query' => 'business', 'per_page' => 1, 'image_type' => 'photo', 'safe' => 'true' ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return array(
			'connected' => true,
			'auth_mode'  => 'basic-search-only',
			'scope_ok'   => false,
			'message'    => __( 'Search works. Use OAuth with licensing scopes before licensing images.', 'seo-shutterstock-image-assistant' ),
		);
	}

	public function check_required_scopes(): array|WP_Error {
		$settings = Settings::get();
		if ( empty( $settings['access_token'] ) ) {
			return new WP_Error( 'ssia_missing_access_token', __( 'Add a Shutterstock OAuth access token first.', 'seo-shutterstock-image-assistant' ), array( 'status' => 401 ) );
		}

		$result = $this->get( 'images/licenses', array( 'per_page' => 1 ), true );
		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				'ssia_missing_scopes',
				__( 'The access token could not verify licenses.view. Required licensing scopes are licenses.create, licenses.view and purchases.view.', 'seo-shutterstock-image-assistant' ),
				array( 'status' => 403 )
			);
		}

		$subscription = $this->get( 'user/subscriptions', array(), true );
		if ( is_wp_error( $subscription ) ) {
			return new WP_Error( 'ssia_subscription_check_failed', __( 'The token works, but the subscription check failed. Verify subscription access before licensing.', 'seo-shutterstock-image-assistant' ), array( 'status' => 403 ) );
		}

		return array(
			'ok'              => true,
			'required_scopes' => $this->required_scopes(),
			'subscriptions'   => $subscription['data'] ?? array(),
			'message'         => __( 'Licensing scopes and subscription access look valid. Final purchase scope is verified during License & Attach.', 'seo-shutterstock-image-assistant' ),
		);
	}

	private function request( string $method, string $url, array $body = array(), bool $require_bearer = false, bool $has_retried_refresh = false ): array|WP_Error {
		$settings = Settings::get();
		if ( ! empty( $settings['token_expires_at'] ) && absint( $settings['token_expires_at'] ) < time() + MINUTE_IN_SECONDS && ! empty( $settings['refresh_token'] ) ) {
			$this->refresh_access_token();
			$settings = Settings::get();
		}

		$headers  = $this->base_headers();

		if ( ! empty( $settings['access_token'] ) ) {
			$headers['Authorization'] = 'Bearer ' . $settings['access_token'];
		} elseif ( $require_bearer ) {
			return new WP_Error( 'ssia_missing_access_token', __( 'Add a Shutterstock access token with licensing scopes before this action.', 'seo-shutterstock-image-assistant' ), array( 'status' => 401 ) );
		} elseif ( ! empty( $settings['api_key'] ) && ! empty( $settings['api_secret'] ) ) {
			$headers['Authorization'] = 'Basic ' . base64_encode( $settings['api_key'] . ':' . $settings['api_secret'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Shutterstock Basic Auth header for search-only fallback.
		} else {
			return new WP_Error( 'ssia_missing_credentials', __( 'Connect Shutterstock first.', 'seo-shutterstock-image-assistant' ), array( 'status' => 401 ) );
		}

		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => $headers,
		);

		if ( 'POST' === $method ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = 'GET' === $method ? wp_remote_get( esc_url_raw( $url ), $args ) : wp_remote_post( esc_url_raw( $url ), $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( 401 === $code && ! $has_retried_refresh && ! empty( $settings['refresh_token'] ) ) {
			$refresh = $this->refresh_access_token();
			if ( true === $refresh ) {
				return $this->request( $method, $url, $body, $require_bearer, true );
			}
		}

		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $data ) ? ( $data['message'] ?? $data['error_description'] ?? __( 'Shutterstock API error.', 'seo-shutterstock-image-assistant' ) ) : __( 'Shutterstock API error.', 'seo-shutterstock-image-assistant' );
			if ( is_array( $message ) ) {
				$message = wp_json_encode( $message );
			}
			return new WP_Error( 'ssia_api_error', sanitize_text_field( (string) $message ), array( 'status' => $code ) );
		}

		return is_array( $data ) ? $data : array();
	}

	private function base_headers(): array {
		return array(
			'Accept'     => 'application/json',
			'User-Agent' => 'SEO Shutterstock Image Assistant/' . ( defined( 'SSIA_VERSION' ) ? SSIA_VERSION : 'dev' ) . '; WordPress',
		);
	}

	private function parse_token_response( mixed $response ): array|WP_Error {
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) || empty( $data['access_token'] ) ) {
			$message = is_array( $data ) ? ( $data['error_description'] ?? $data['message'] ?? __( 'OAuth token exchange failed.', 'seo-shutterstock-image-assistant' ) ) : __( 'OAuth token exchange failed.', 'seo-shutterstock-image-assistant' );
			if ( is_array( $message ) ) {
				$message = wp_json_encode( $message );
			}
			return new WP_Error( 'ssia_oauth_token_failed', sanitize_text_field( (string) $message ), array( 'status' => $code ?: 400 ) );
		}
		$expires_in = absint( $data['expires_in'] ?? 3600 );
		return array(
			'access_token'     => sanitize_textarea_field( (string) $data['access_token'] ),
			'refresh_token'    => sanitize_textarea_field( (string) ( $data['refresh_token'] ?? Settings::get()['refresh_token'] ?? '' ) ),
			'token_expires_at' => time() + max( 300, $expires_in - 60 ),
		);
	}
}
