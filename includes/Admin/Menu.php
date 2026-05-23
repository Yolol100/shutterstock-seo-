<?php
namespace Company\SeoShutterstockAssistant\Admin;

use Company\SeoShutterstockAssistant\Security\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Menu {
	private const SLUG = 'ssia-dashboard';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function add_menu(): void {
		add_menu_page( __( 'SEO Images', 'seo-shutterstock-image-assistant' ), __( 'SEO Images', 'seo-shutterstock-image-assistant' ), Capabilities::SEARCH, self::SLUG, array( $this, 'render' ), 'dashicons-format-image', 58 );

		// Keep the WordPress admin sidebar clean: all plugin sections are now handled
		// by the in-page navigation tabs in the React UI instead of submenu entries.
		remove_submenu_page( self::SLUG, self::SLUG );
	}

	public function enqueue( string $hook ): void {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}

		$asset = SSIA_PATH . 'build/index.asset.php';
		$meta  = is_readable( $asset ) ? require $asset : array();
		$meta  = is_array( $meta ) ? $meta : array();
		$deps  = isset( $meta['dependencies'] ) && is_array( $meta['dependencies'] ) ? $meta['dependencies'] : array( 'wp-api-fetch', 'wp-components', 'wp-element', 'wp-i18n' );
		$ver   = isset( $meta['version'] ) ? sanitize_text_field( (string) $meta['version'] ) : SSIA_VERSION;

		$script_file     = SSIA_PATH . 'build/index.js';
		$foundation_file = SSIA_PATH . 'build/css/admin-foundation.css';
		$premium_file    = SSIA_PATH . 'build/css/admin-premium.css';
		$responsive_file = SSIA_PATH . 'build/css/admin-responsive.css';

		$script_ver     = is_readable( $script_file ) ? (string) filemtime( $script_file ) : $ver;
		$foundation_ver = is_readable( $foundation_file ) ? (string) filemtime( $foundation_file ) : SSIA_VERSION;
		$premium_ver    = is_readable( $premium_file ) ? (string) filemtime( $premium_file ) : SSIA_VERSION;
		$responsive_ver = is_readable( $responsive_file ) ? (string) filemtime( $responsive_file ) : SSIA_VERSION;

		wp_enqueue_script( 'ssia-admin', SSIA_URL . 'build/index.js', $deps, $script_ver, true );
		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style( 'ssia-admin-foundation', SSIA_URL . 'build/css/admin-foundation.css', array( 'wp-components' ), $foundation_ver );
		wp_enqueue_style( 'ssia-admin-premium', SSIA_URL . 'build/css/admin-premium.css', array( 'ssia-admin-foundation' ), $premium_ver );
		wp_enqueue_style( 'ssia-admin-responsive', SSIA_URL . 'build/css/admin-responsive.css', array( 'ssia-admin-premium' ), $responsive_ver );


		wp_add_inline_script(
			'ssia-admin',
			'window.ssiaAdmin=' . wp_json_encode(
				array(
					'root'       => esc_url_raw( rest_url( 'ssia/v1' ) ),
					'nonce'      => wp_create_nonce( 'wp_rest' ),
					'screen'     => self::SLUG,
					'canManage'  => current_user_can( Capabilities::MANAGE ),
					'canSearch'  => current_user_can( Capabilities::SEARCH ) || current_user_can( Capabilities::MANAGE ),
					'canLicense' => Settings::can_license(),
				)
			) . ';',
			'before'
		);
	}

	public function render(): void {
		echo '<div id="ssia-admin-root" class="ssia-admin"><noscript>' . esc_html__( 'JavaScript is required to use SEO Images.', 'seo-shutterstock-image-assistant' ) . '</noscript></div>';
	}
}
