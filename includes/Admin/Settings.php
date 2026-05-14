<?php
namespace Company\SeoShutterstockAssistant\Admin;

use Company\SeoShutterstockAssistant\Plugin;
use Company\SeoShutterstockAssistant\Security\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Settings {
	public function register(): void {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	public function register_settings(): void {
		register_setting(
			'ssia_settings_group',
			Plugin::option_key(),
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => $this->defaults(),
			)
		);
	}

	public function defaults(): array {
		return array(
			'api_key'               => '',
			'api_secret'            => '',
			'access_token'          => '',
			'refresh_token'         => '',
			'token_expires_at'      => 0,
			'oauth_client_id'       => '',
			'oauth_client_secret'   => '',
			'oauth_redirect_uri'    => '',
			'oauth_state'           => '',
			'subscription_id'       => '',
			'license_price'         => '',
			'license_customer_id'   => '',
			'allowed_post_types'    => array( 'page' ),
			'yoast_support'         => true,
			'rank_math_support'     => true,
			'acf_support'           => true,
			'acf_return_format'     => 'id',
			'acf_mapping'           => array(
				'image_1'        => 'image_1',
				'image_2'        => 'image_2',
				'image_3'        => 'image_3',
				'featured_image' => '',
			),
			'default_orientation'   => 'horizontal',
			'default_results_count' => 12,
			'editorial_allowed'     => false,
			'safe_search'           => true,
			'batch_size'            => 5,
			'license_format'        => 'jpg',
			'license_size'          => 'huge',
			'log_retention'         => 500,
			'delete_data_on_uninstall' => true,
		);
	}

	public function sanitize( mixed $input ): array {
		$input    = is_array( $input ) ? wp_unslash( $input ) : array();
		$defaults = $this->defaults();
		$existing = self::get_raw();
		$secret   = (string) ( $input['api_secret'] ?? '' );
		$token    = (string) ( $input['access_token'] ?? '' );
		$refresh  = (string) ( $input['refresh_token'] ?? '' );
		$client_secret = (string) ( $input['oauth_client_secret'] ?? '' );

		if ( self::is_masked( $secret ) ) {
			$secret = (string) ( $existing['api_secret'] ?? '' );
		}
		if ( self::is_masked( $token ) ) {
			$token = (string) ( $existing['access_token'] ?? '' );
		}
		if ( self::is_masked( $refresh ) ) {
			$refresh = (string) ( $existing['refresh_token'] ?? '' );
		}
		if ( self::is_masked( $client_secret ) ) {
			$client_secret = (string) ( $existing['oauth_client_secret'] ?? '' );
		}

		$acf_mapping = isset( $input['acf_mapping'] ) && is_array( $input['acf_mapping'] ) ? $input['acf_mapping'] : array();

		$orientation = sanitize_key( $input['default_orientation'] ?? $defaults['default_orientation'] );
		if ( ! in_array( $orientation, array( 'horizontal', 'vertical', 'square' ), true ) ) {
			$orientation = 'horizontal';
		}

		$license_size = sanitize_key( $input['license_size'] ?? 'huge' );
		if ( ! in_array( $license_size, array( 'small', 'medium', 'huge', 'supersize', 'vector' ), true ) ) {
			$license_size = 'huge';
		}

		$return_format = sanitize_key( $input['acf_return_format'] ?? 'id' );
		if ( ! in_array( $return_format, array( 'id', 'array', 'url' ), true ) ) {
			$return_format = 'id';
		}

		$allowed_types = array();
		foreach ( (array) ( $input['allowed_post_types'] ?? $defaults['allowed_post_types'] ) as $type ) {
			$type = sanitize_key( $type );
			if ( post_type_exists( $type ) && is_post_type_viewable( $type ) ) {
				$allowed_types[] = $type;
			}
		}
		if ( empty( $allowed_types ) ) {
			$allowed_types = array( 'page' );
		}

		$price = sanitize_text_field( (string) ( $input['license_price'] ?? '' ) );
		if ( '' !== $price && ! is_numeric( $price ) ) {
			$price = '';
		}

		return array(
			'api_key'               => sanitize_text_field( (string) ( $input['api_key'] ?? '' ) ),
			'api_secret'            => sanitize_text_field( $secret ),
			'access_token'          => sanitize_textarea_field( $token ),
			'refresh_token'         => sanitize_textarea_field( $refresh ),
			'token_expires_at'      => max( 0, absint( $input['token_expires_at'] ?? 0 ) ),
			'oauth_client_id'       => sanitize_text_field( (string) ( $input['oauth_client_id'] ?? '' ) ),
			'oauth_client_secret'   => sanitize_text_field( $client_secret ),
			'oauth_redirect_uri'    => esc_url_raw( (string) ( $input['oauth_redirect_uri'] ?? '' ) ),
			'oauth_state'           => sanitize_text_field( (string) ( $input['oauth_state'] ?? ( $existing['oauth_state'] ?? '' ) ) ),
			'subscription_id'       => sanitize_text_field( (string) ( $input['subscription_id'] ?? '' ) ),
			'license_price'         => $price,
			'license_customer_id'   => sanitize_text_field( (string) ( $input['license_customer_id'] ?? '' ) ),
			'allowed_post_types'    => array_values( array_unique( $allowed_types ) ),
			'yoast_support'         => ! empty( $input['yoast_support'] ),
			'rank_math_support'     => ! empty( $input['rank_math_support'] ),
			'acf_support'           => ! empty( $input['acf_support'] ),
			'acf_return_format'     => $return_format,
			'acf_mapping'           => array(
				'image_1'        => $this->sanitize_acf_identifier( $acf_mapping['image_1'] ?? 'image_1' ),
				'image_2'        => $this->sanitize_acf_identifier( $acf_mapping['image_2'] ?? 'image_2' ),
				'image_3'        => $this->sanitize_acf_identifier( $acf_mapping['image_3'] ?? 'image_3' ),
				'featured_image' => $this->sanitize_acf_identifier( $acf_mapping['featured_image'] ?? '' ),
			),
			'default_orientation'   => $orientation,
			'default_results_count' => max( 3, min( 50, absint( $input['default_results_count'] ?? 12 ) ) ),
			'editorial_allowed'     => ! empty( $input['editorial_allowed'] ),
			'safe_search'           => ! empty( $input['safe_search'] ),
			'batch_size'            => max( 1, min( 5, absint( $input['batch_size'] ?? 5 ) ) ),
			'license_format'        => 'jpg',
			'license_size'          => $license_size,
			'log_retention'         => max( 50, min( 5000, absint( $input['log_retention'] ?? 500 ) ) ),
			'delete_data_on_uninstall' => ! empty( $input['delete_data_on_uninstall'] ),
		);
	}

	public static function get(): array {
		$instance = new self();
		$raw      = wp_parse_args( self::get_raw(), $instance->defaults() );
		$settings = $instance->sanitize( $raw );
		return wp_parse_args( $settings, $instance->defaults() );
	}

	public static function public_settings(): array {
		$settings = self::get();
		$settings['api_secret']          = self::mask( $settings['api_secret'] );
		$settings['access_token']        = self::mask( $settings['access_token'] );
		$settings['refresh_token']       = self::mask( $settings['refresh_token'] );
		$settings['oauth_client_secret'] = self::mask( $settings['oauth_client_secret'] );
		$settings['post_types']          = self::available_post_types();
		$settings['acf_available']       = function_exists( 'update_field' );
		$settings['required_scopes']     = array( 'licenses.create', 'licenses.view', 'purchases.view' );
		$settings['auth_mode']           = '' !== (string) $settings['access_token'] ? 'oauth' : ( '' !== (string) $settings['api_key'] && '' !== (string) $settings['api_secret'] ? 'basic-search-only' : 'none' );
		$settings['oauth_redirect_uri']  = $settings['oauth_redirect_uri'] ?: admin_url( 'admin.php?page=ssia-dashboard' );
		$settings['token_expires_at_readable'] = ! empty( $settings['token_expires_at'] ) ? gmdate( 'c', absint( $settings['token_expires_at'] ) ) : '';
		return $settings;
	}

	public static function update_from_rest( array $input ): array {
		$instance = new self();
		$clean    = $instance->sanitize( $input );
		update_option( Plugin::option_key(), $clean, false );
		return self::public_settings();
	}

	public static function update_partial( array $partial ): array {
		$instance = new self();
		$clean    = $instance->sanitize( array_merge( self::get(), $partial ) );
		update_option( Plugin::option_key(), $clean, false );
		return self::get();
	}

	public static function get_raw(): array {
		$value = get_option( Plugin::option_key(), array() );
		return is_array( $value ) ? $value : array();
	}

	public static function available_post_types(): array {
		$items = array();
		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $type => $object ) {
			if ( is_post_type_viewable( $type ) ) {
				$items[] = array(
					'label' => $object->labels->singular_name,
					'value' => $type,
				);
			}
		}
		return $items;
	}

	public static function can_license(): bool {
		return current_user_can( Capabilities::LICENSE ) || current_user_can( Capabilities::MANAGE );
	}

	private function sanitize_acf_identifier( mixed $value ): string {
		$value = sanitize_text_field( (string) $value );
		return preg_match( '/^[A-Za-z0-9_\-]+$/', $value ) ? $value : '';
	}

	private static function mask( string $value ): string {
		return '' === $value ? '' : '••••••••';
	}

	private static function is_masked( string $value ): bool {
		return str_contains( $value, '•' ) || preg_match( '/^\*+$/', $value );
	}
}
