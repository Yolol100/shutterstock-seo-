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

	public function post_with_query( string $path, array $query_args = array(), array $body = array(), bool $require_bearer = true ): array|WP_Error {
		$url = add_query_arg( $query_args, self::BASE . '/' . ltrim( $path, '/' ) );
		return $this->request( 'POST', $url, $body, $require_bearer );
	}

	public function active_subscriptions( string $media_type = 'image' ): array|WP_Error {
		$response = $this->get( 'user/subscriptions', array(), true );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$subscriptions = array_values( array_filter( (array) ( $response['data'] ?? array() ), 'is_array' ) );
		$candidates    = array();

		foreach ( $subscriptions as $subscription ) {
			if ( ! $this->subscription_is_current( $subscription ) ) {
				continue;
			}
			if ( ! $this->subscription_supports_media_type( $subscription, $media_type ) ) {
				continue;
			}
			if ( 0 === $this->subscription_downloads_left( $subscription ) ) {
				continue;
			}
			$candidates[] = $subscription;
		}

		usort(
			$candidates,
			fn( array $a, array $b ): int => $this->subscription_downloads_left( $b ) <=> $this->subscription_downloads_left( $a )
		);

		return $candidates;
	}

	public function selected_subscription_id( string $preferred = '', string $media_type = 'image' ): string|WP_Error {
		$preferred = sanitize_text_field( $preferred );
		if ( '' !== $preferred ) {
			return $preferred;
		}

		$subscriptions = $this->active_subscriptions( $media_type );
		if ( is_wp_error( $subscriptions ) ) {
			return $subscriptions;
		}

		foreach ( $subscriptions as $subscription ) {
			$id = $this->subscription_id_from_item( $subscription );
			if ( '' !== $id ) {
				return $id;
			}
		}

		return new WP_Error(
			'ssia_no_active_subscription',
			__( 'No active Shutterstock image subscription was found for this connected account. Reconnect OAuth and choose the corporate/team account, or enter the corporate subscription ID in Settings.', 'seo-shutterstock-image-assistant' ),
			array( 'status' => 400 )
		);
	}

	public function subscription_summary( array $subscriptions ): array {
		$summary = array();
		foreach ( $subscriptions as $subscription ) {
			if ( ! is_array( $subscription ) ) {
				continue;
			}
			$summary[] = array(
				'id'             => $this->subscription_id_from_item( $subscription ),
				'license'        => sanitize_text_field( (string) ( $subscription['license'] ?? '' ) ),
				'downloads_left' => $this->subscription_downloads_left( $subscription ),
				'expiration'     => sanitize_text_field( (string) ( $subscription['expiration_time'] ?? $subscription['expires'] ?? '' ) ),
			);
		}
		return $summary;
	}

	private function subscription_id_from_item( array $subscription ): string {
		return sanitize_text_field( (string) ( $subscription['id'] ?? $subscription['subscription_id'] ?? '' ) );
	}

	private function subscription_is_current( array $subscription ): bool {
		$expiration = sanitize_text_field( (string) ( $subscription['expiration_time'] ?? $subscription['expires'] ?? '' ) );
		if ( '' === $expiration ) {
			return true;
		}
		$timestamp = strtotime( $expiration );
		return false === $timestamp || $timestamp >= time();
	}

	private function subscription_downloads_left( array $subscription ): int {
		$values = array();
		$allotment = isset( $subscription['allotment'] ) && is_array( $subscription['allotment'] ) ? $subscription['allotment'] : array();
		foreach ( array( $subscription, $allotment ) as $source ) {
			if ( isset( $source['downloads_left'] ) && is_numeric( $source['downloads_left'] ) ) {
				$values[] = absint( $source['downloads_left'] );
			}
			if ( isset( $source['remaining_downloads'] ) && is_numeric( $source['remaining_downloads'] ) ) {
				$values[] = absint( $source['remaining_downloads'] );
			}
		}

		$content_tiers = isset( $allotment['content_tiers'] ) && is_array( $allotment['content_tiers'] ) ? $allotment['content_tiers'] : array();
		foreach ( $content_tiers as $tier ) {
			if ( is_array( $tier ) && isset( $tier['downloads_left'] ) && is_numeric( $tier['downloads_left'] ) ) {
				$values[] = absint( $tier['downloads_left'] );
			}
		}

		return empty( $values ) ? 1 : max( $values );
	}

	private function subscription_supports_media_type( array $subscription, string $media_type ): bool {
		$media_type = sanitize_key( $media_type ?: 'image' );
		$signals    = strtolower( wp_json_encode( $subscription ) ?: '' );

		if ( 'image' === $media_type ) {
			if ( preg_match( '/\b(video|audio|sfx|sound_effect)\b/', $signals ) && ! preg_match( '/\b(image|images|photo|photos|jpg|jpeg|vector|eps)\b/', $signals ) ) {
				return false;
			}
			if ( preg_match( '/\b(image|images|photo|photos|jpg|jpeg|vector|eps)\b/', $signals ) ) {
				return true;
			}
		}

		$license = sanitize_key( (string) ( $subscription['license'] ?? '' ) );
		if ( '' !== $license && in_array( $license, $this->supported_subscription_licenses( $media_type ), true ) ) {
			return true;
		}

		// Corporate/team subscriptions sometimes use account-specific license labels.
		// If Shutterstock gives no explicit non-image signal, keep the subscription as a candidate and let the license endpoint validate it.
		return 'image' === $media_type;
	}

	public function supported_subscription_licenses( string $media_type = 'image' ): array {
		if ( 'editorial' === sanitize_key( $media_type ) ) {
			return array(
				'premier_editorial_all_digital',
				'premier_editorial_all_media',
			);
		}

		return array(
			'standard',
			'enhanced',
			'image',
			'multi_share',
			'premier',
			'premier_digital',
			'media',
			'media_digital',
		);
	}

	public function required_scopes(): array {
		return array( 'licenses.create', 'licenses.view', 'purchases.view', 'user.view' );
	}

	public function oauth_authorize_url(): array|WP_Error {
		$settings    = Settings::get();
		$credentials = $this->oauth_credentials( $settings );
		if ( empty( $credentials['client_id'] ) ) {
			return new WP_Error( 'ssia_missing_oauth_client_id', __( 'Shutterstock OAuth is not configured on the server. Define SSIA_SHUTTERSTOCK_CLIENT_ID before connecting.', 'seo-shutterstock-image-assistant' ), array( 'status' => 400 ) );
		}

		$redirect_uri = $this->oauth_redirect_uri( $settings );
		$state        = wp_generate_password( 32, false, false );
		Settings::update_partial( array( 'oauth_state' => $state, 'oauth_state_expires_at' => time() + 10 * MINUTE_IN_SECONDS, 'oauth_redirect_uri' => $redirect_uri ) );

		$url = add_query_arg(
			array(
				'client_id'     => $credentials['client_id'],
				'response_type' => 'code',
				'redirect_uri'  => $redirect_uri,
				'scope'         => implode( ' ', $this->required_scopes() ),
				'state'         => $state,
				'realm'         => 'customer',
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
		if ( empty( $settings['oauth_state'] ) || empty( $settings['oauth_state_expires_at'] ) || absint( $settings['oauth_state_expires_at'] ) < time() || ! hash_equals( (string) $settings['oauth_state'], $state ) ) {
			Settings::update_partial( array( 'oauth_state' => '', 'oauth_state_expires_at' => 0 ) );
			return new WP_Error( 'ssia_invalid_oauth_state', __( 'OAuth state check failed or expired. Please start the connection again.', 'seo-shutterstock-image-assistant' ), array( 'status' => 403 ) );
		}
		$credentials = $this->oauth_credentials( $settings );
		if ( empty( $credentials['client_id'] ) || empty( $credentials['client_secret'] ) ) {
			return new WP_Error( 'ssia_missing_oauth_credentials', __( 'Shutterstock OAuth is not configured on the server. Define SSIA_SHUTTERSTOCK_CLIENT_ID and SSIA_SHUTTERSTOCK_CLIENT_SECRET before connecting.', 'seo-shutterstock-image-assistant' ), array( 'status' => 400 ) );
		}

		$response = wp_safe_remote_post(
			self::TOKEN_BASE,
			array(
				'timeout'     => 20,
				'redirection' => 3,
				'sslverify'   => true,
				'headers'     => $this->base_headers(),
				'body'    => array(
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'client_id'     => $credentials['client_id'],
					'client_secret' => $credentials['client_secret'],
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

		Settings::update_partial( $tokens + array( 'oauth_state' => '', 'oauth_state_expires_at' => 0 ) );
		( new Logger() )->info( 'oauth_exchange_success' );
		return array( 'connected' => true, 'message' => __( 'Shutterstock OAuth connection completed.', 'seo-shutterstock-image-assistant' ) );
	}

	public function refresh_access_token(): bool|WP_Error {
		$settings    = Settings::get();
		$credentials = $this->oauth_credentials( $settings );
		if ( empty( $settings['refresh_token'] ) || empty( $credentials['client_id'] ) || empty( $credentials['client_secret'] ) ) {
			return false;
		}

		$response = wp_safe_remote_post(
			self::TOKEN_BASE,
			array(
				'timeout'     => 20,
				'redirection' => 3,
				'sslverify'   => true,
				'headers'     => $this->base_headers(),
				'body'    => array(
					'grant_type'    => 'refresh_token',
					'refresh_token' => $settings['refresh_token'],
					'client_id'     => $credentials['client_id'],
					'client_secret' => $credentials['client_secret'],
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
				'connected'              => true,
				'auth_mode'               => 'oauth',
				'scope_ok'                => ! is_wp_error( $scope ),
				'subscription_id'         => is_wp_error( $scope ) ? '' : (string) ( $scope['selected_subscription_id'] ?? '' ),
				'active_subscriptions'    => is_wp_error( $scope ) ? array() : (array) ( $scope['subscriptions'] ?? array() ),
				'active_subscription_count' => is_wp_error( $scope ) ? 0 : count( (array) ( $scope['subscriptions'] ?? array() ) ),
				'message'                 => is_wp_error( $scope ) ? $scope->get_error_message() : __( 'Shutterstock OAuth connection and subscription check passed.', 'seo-shutterstock-image-assistant' ),
			);
		}

		return new WP_Error( 'ssia_not_connected', __( 'Connect Shutterstock before searching or licensing images.', 'seo-shutterstock-image-assistant' ), array( 'status' => 401 ) );
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
				__( 'The access token could not verify licenses.view. Required licensing scopes are licenses.create, licenses.view, purchases.view and user.view.', 'seo-shutterstock-image-assistant' ),
				array( 'status' => 403 )
			);
		}

		$subscriptions = $this->active_subscriptions();
		if ( is_wp_error( $subscriptions ) ) {
			return new WP_Error( 'ssia_subscription_check_failed', __( 'The token works, but the subscription check failed. Verify subscription access before licensing.', 'seo-shutterstock-image-assistant' ), array( 'status' => 403 ) );
		}

		$selected = $this->selected_subscription_id( (string) ( $settings['subscription_id'] ?? '' ) );
		if ( is_wp_error( $selected ) ) {
			return $selected;
		}

		return array(
			'ok'                       => true,
			'required_scopes'          => $this->required_scopes(),
			'selected_subscription_id' => $selected,
			'subscriptions'            => $this->subscription_summary( $subscriptions ),
			'message'                  => __( 'Licensing scopes and active subscription access look valid. Final purchase scope is verified during License & Attach.', 'seo-shutterstock-image-assistant' ),
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

		$url_check = $this->validate_api_url( $url );
		if ( is_wp_error( $url_check ) ) {
			return $url_check;
		}

		$args['redirection'] = 3;
		$args['sslverify']   = true;
		$response = 'GET' === $method ? wp_safe_remote_get( esc_url_raw( $url ), $args ) : wp_safe_remote_post( esc_url_raw( $url ), $args );
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


	private function validate_api_url( string $url ): bool|WP_Error {
		$parts  = wp_parse_url( $url );
		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
		$host   = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';

		if ( 'https' !== $scheme || 'api.shutterstock.com' !== $host ) {
			return new WP_Error( 'ssia_invalid_api_url', __( 'Blocked unexpected Shutterstock API URL.', 'seo-shutterstock-image-assistant' ), array( 'status' => 400 ) );
		}

		return true;
	}


	private function oauth_redirect_uri( array $settings ): string {
		$candidate = ! empty( $settings['oauth_redirect_uri'] ) ? esc_url_raw( (string) $settings['oauth_redirect_uri'] ) : '';
		$default   = admin_url( 'admin.php' );

		if ( '' === $candidate ) {
			return esc_url_raw( $default );
		}

		$parts = wp_parse_url( $candidate );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['path'] ) ) {
			return esc_url_raw( $default );
		}

		$path = (string) $parts['path'];
		if ( str_ends_with( $path, '/wp-admin/admin.php' ) ) {
			$scheme = ! empty( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : 'https';
			$host   = strtolower( (string) $parts['host'] );
			return esc_url_raw( $scheme . '://' . $host . $path );
		}

		return esc_url_raw( $candidate );
	}

	private function oauth_credentials( array $settings ): array {
		$client_id     = defined( 'SSIA_SHUTTERSTOCK_CLIENT_ID' ) ? (string) SSIA_SHUTTERSTOCK_CLIENT_ID : (string) ( $settings['oauth_client_id'] ?? '' );
		$client_secret = defined( 'SSIA_SHUTTERSTOCK_CLIENT_SECRET' ) ? (string) SSIA_SHUTTERSTOCK_CLIENT_SECRET : (string) ( $settings['oauth_client_secret'] ?? '' );

		return array(
			'client_id'     => sanitize_text_field( $client_id ),
			'client_secret' => sanitize_text_field( $client_secret ),
		);
	}

	private function base_headers(): array {
		$version = defined( 'SSIA_VERSION' ) ? SSIA_VERSION : 'dev';

		return array(
			'Accept'                    => 'application/json',
			'User-Agent'                => 'SEO Image Assistant for Shutterstock/' . $version . '; WordPress',
			'x-shutterstock-application' => 'Wordpress/' . $version,
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
