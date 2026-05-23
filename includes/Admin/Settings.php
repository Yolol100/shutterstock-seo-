<?php
namespace Company\SeoShutterstockAssistant\Admin;

use Company\SeoShutterstockAssistant\Infrastructure\Cache;
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
			'access_token'          => '',
			'refresh_token'         => '',
			'token_expires_at'      => 0,
			'oauth_client_id'       => '',
			'oauth_client_secret'   => '',
			'oauth_redirect_uri'    => '',
			'oauth_state'           => '',
			'oauth_state_expires_at'    => 0,
			'subscription_id'       => '',
			'license_price'         => '',
			'license_customer_id'   => '',
			'yoast_support'         => true,
			'rank_math_support'     => true,
			'acf_support'           => true,
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
			'license_size'          => 'huge',
			'log_retention'         => 500,
			'delete_data_on_uninstall' => true,
		);
	}

	public function sanitize( mixed $input ): array {
		$input    = is_array( $input ) ? wp_unslash( $input ) : array();
		$defaults = $this->defaults();
		$existing = self::get_raw();
		// AI-PATCH: Technical Shutterstock credentials/tokens are hidden from the admin UI.
		// Preserve existing server-side values unless an internal OAuth flow updates them.
		$token         = (string) ( $existing['access_token'] ?? '' );
		$refresh       = (string) ( $existing['refresh_token'] ?? '' );
		$client_id     = (string) ( $existing['oauth_client_id'] ?? '' );
		$client_secret = (string) ( $existing['oauth_client_secret'] ?? '' );

		if ( array_key_exists( 'access_token', $input ) && ! self::is_masked( (string) $input['access_token'] ) ) {
			$token = (string) $input['access_token'];
		}
		if ( array_key_exists( 'refresh_token', $input ) && ! self::is_masked( (string) $input['refresh_token'] ) ) {
			$refresh = (string) $input['refresh_token'];
		}
		if ( array_key_exists( 'oauth_client_id', $input ) ) {
			$client_id = (string) $input['oauth_client_id'];
		}
		if ( array_key_exists( 'oauth_client_secret', $input ) && ! self::is_masked( (string) $input['oauth_client_secret'] ) ) {
			$client_secret = (string) $input['oauth_client_secret'];
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


		$price = sanitize_text_field( (string) ( $input['license_price'] ?? '' ) );
		if ( '' !== $price && ! is_numeric( $price ) ) {
			$price = '';
		}

		return array(
			'access_token'          => sanitize_textarea_field( $token ),
			'refresh_token'         => sanitize_textarea_field( $refresh ),
			'token_expires_at'      => max( 0, absint( $input['token_expires_at'] ?? ( $existing['token_expires_at'] ?? 0 ) ) ),
			'oauth_client_id'       => sanitize_text_field( $client_id ),
			'oauth_client_secret'   => sanitize_text_field( $client_secret ),
			'oauth_redirect_uri'    => $this->sanitize_oauth_redirect_uri( (string) ( $input['oauth_redirect_uri'] ?? ( $existing['oauth_redirect_uri'] ?? '' ) ) ),
			'oauth_state'           => sanitize_text_field( (string) ( $input['oauth_state'] ?? ( $existing['oauth_state'] ?? '' ) ) ),
			'oauth_state_expires_at'    => max( 0, absint( $input['oauth_state_expires_at'] ?? ( $existing['oauth_state_expires_at'] ?? 0 ) ) ),
			'subscription_id'       => sanitize_text_field( (string) ( $input['subscription_id'] ?? ( $existing['subscription_id'] ?? '' ) ) ),
			'license_price'         => $price,
			'license_customer_id'   => sanitize_text_field( (string) ( $input['license_customer_id'] ?? '' ) ),
			'yoast_support'         => ! empty( $input['yoast_support'] ),
			'rank_math_support'     => ! empty( $input['rank_math_support'] ),
			'acf_support'           => array_key_exists( 'acf_support', $input ) ? ! empty( $input['acf_support'] ) : (bool) ( $existing['acf_support'] ?? true ),
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
			'license_size'          => $license_size,
			'log_retention'         => max( 50, min( 5000, absint( $input['log_retention'] ?? 500 ) ) ),
			'delete_data_on_uninstall' => ! empty( $input['delete_data_on_uninstall'] ),
		);
	}

	/** @var array<string,mixed>|null Request-level cache to avoid re-sanitizing on every get. */
	private static ?array $cache = null;

	public static function get(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$instance     = new self();
		$raw          = wp_parse_args( self::get_raw(), $instance->defaults() );
		$settings     = $instance->sanitize( $raw );
		self::$cache  = wp_parse_args( $settings, $instance->defaults() );
		return self::$cache;
	}

	public static function flush_cache(): void {
		self::$cache = null;
	}

	public static function public_settings(): array {
		$settings = self::get();
		$connected = '' !== (string) $settings['access_token'];

		// Expose only editable connection values and masked secrets to the admin UI.
		$settings['oauth_client_id']     = (string) ( $settings['oauth_client_id'] ?? '' );
		$settings['oauth_client_secret'] = self::mask( (string) ( $settings['oauth_client_secret'] ?? '' ) );
		$settings['access_token']        = self::mask( (string) ( $settings['access_token'] ?? '' ) );
		$settings['has_access_token']    = $connected;
		unset( $settings['refresh_token'], $settings['oauth_state'], $settings['oauth_state_expires_at'] );
		$settings['acf_available']       = function_exists( 'update_field' );
		$settings['acf_image_fields']    = self::available_acf_image_fields();
		$settings['required_scopes']     = array( 'licenses.create', 'licenses.view', 'purchases.view', 'user.view' );
		$settings['connected']           = $connected;
		$settings['auth_mode']           = $connected ? 'oauth' : 'none';
		$settings['oauth_redirect_uri']  = self::callback_url( (string) ( $settings['oauth_redirect_uri'] ?? '' ) );
		$settings['token_expires_at_readable'] = ! empty( $settings['token_expires_at'] ) ? gmdate( 'c', absint( $settings['token_expires_at'] ) ) : '';
		unset( $settings['token_expires_at'] );
		return $settings;
	}

	public static function update_from_rest( array $input ): array {
		$instance = new self();
		$clean    = $instance->sanitize( $input );
		update_option( Plugin::option_key(), $clean, false );
		self::flush_cache();
		Cache::bump();
		return self::public_settings();
	}

	public static function update_partial( array $partial ): array {
		$instance = new self();
		$clean    = $instance->sanitize( array_merge( self::get(), $partial ) );
		update_option( Plugin::option_key(), $clean, false );
		self::flush_cache();
		Cache::bump();
		return self::get();
	}

	public static function get_raw(): array {
		$value = get_option( Plugin::option_key(), array() );
		return is_array( $value ) ? $value : array();
	}


	public static function available_acf_image_fields(): array {
		$items = array();
		if ( function_exists( 'acf_get_field_groups' ) && function_exists( 'acf_get_fields' ) ) {
			foreach ( (array) acf_get_field_groups() as $group ) {
				$fields = acf_get_fields( $group );
				if ( ! is_array( $fields ) ) {
					continue;
				}
				foreach ( $fields as $field ) {
					if ( ! is_array( $field ) || 'image' !== (string) ( $field['type'] ?? '' ) ) {
						continue;
					}
					$name = sanitize_key( (string) ( $field['name'] ?? '' ) );
					if ( '' === $name ) {
						continue;
					}
					$items[ $name ] = array(
						'label' => sanitize_text_field( (string) ( $field['label'] ?? $name ) ),
						'value' => $name,
					);
				}
			}
		}
		foreach ( array( 'image_1', 'image_2', 'image_3' ) as $fallback ) {
			if ( ! isset( $items[ $fallback ] ) ) {
				$items[ $fallback ] = array( 'label' => $fallback, 'value' => $fallback );
			}
		}
		return array_values( $items );
	}

	public static function can_license(): bool {
		return current_user_can( Capabilities::LICENSE ) || current_user_can( Capabilities::MANAGE );
	}

	private static function callback_url( string $candidate = '' ): string {
		$default = admin_url( 'admin.php' );
		if ( '' === $candidate ) {
			return esc_url_raw( $default );
		}

		$parts = wp_parse_url( $candidate );
		if ( is_array( $parts ) && ! empty( $parts['host'] ) && ! empty( $parts['path'] ) && str_ends_with( (string) $parts['path'], '/wp-admin/admin.php' ) ) {
			$scheme = ! empty( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : 'https';
			$host   = strtolower( (string) $parts['host'] );
			return esc_url_raw( $scheme . '://' . $host . (string) $parts['path'] );
		}

		return esc_url_raw( $candidate );
	}

	private function sanitize_oauth_redirect_uri( string $candidate ): string {
		return self::callback_url( $candidate );
	}

	private function sanitize_acf_identifier( mixed $value ): string {
		$value = sanitize_text_field( (string) $value );
		return preg_match( '/^[A-Za-z0-9_\-]+$/', $value ) ? $value : '';
	}

	private static function mask( string $value ): string {
		return '' === $value ? '' : '••••••••';
	}

	private static function is_masked( string $value ): bool {
		return str_contains( $value, '•' ) || 1 === preg_match( '/^\*+$/', $value );
	}
}
