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
		add_menu_page( __( 'SEO Images', 'seo-shutterstock-image-assistant' ), __( 'SEO Images', 'seo-shutterstock-image-assistant' ), Capabilities::EDIT, self::SLUG, array( $this, 'render' ), 'dashicons-format-image', 58 );

		// Keep the WordPress admin sidebar clean: all plugin sections are now handled
		// by the in-page navigation tabs in the React UI instead of submenu entries.
		remove_submenu_page( self::SLUG, self::SLUG );
	}

	public function enqueue( string $hook ): void {
		if ( false === strpos( $hook, 'ssia-' ) ) {
			return;
		}

		$asset = SSIA_PATH . 'build/index.asset.php';
		$meta  = is_readable( $asset ) ? require $asset : array();
		$meta  = is_array( $meta ) ? $meta : array();
		$deps  = isset( $meta['dependencies'] ) && is_array( $meta['dependencies'] ) ? $meta['dependencies'] : array( 'wp-api-fetch', 'wp-components', 'wp-element', 'wp-i18n' );
		$ver   = isset( $meta['version'] ) ? sanitize_text_field( (string) $meta['version'] ) : SSIA_VERSION;

		wp_enqueue_script( 'ssia-admin', SSIA_URL . 'build/index.js', $deps, $ver, true );
		wp_enqueue_style( 'ssia-admin', SSIA_URL . 'build/style-index.css', array(), SSIA_VERSION );

		wp_add_inline_script(
			'ssia-admin',
			'window.ssiaAdmin=' . wp_json_encode(
				array(
					'root'       => esc_url_raw( rest_url( 'ssia/v1' ) ),
					'nonce'      => wp_create_nonce( 'wp_rest' ),
					'screen'     => self::SLUG,
					'canManage'  => current_user_can( Capabilities::MANAGE ),
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
