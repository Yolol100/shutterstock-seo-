<?php
/**
 * Plugin Name: SEO Shutterstock Image Assistant
 * Description: Premium workflow tool for finding, reviewing, licensing, and attaching Shutterstock images to SEO pages via ACF fields.
 * Version: 1.3.8
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: Webactueel
 * Text Domain: seo-shutterstock-image-assistant
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SSIA_VERSION', '1.3.8' );
define( 'SSIA_FILE', __FILE__ );
define( 'SSIA_PATH', plugin_dir_path( __FILE__ ) );
define( 'SSIA_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'Company\\SeoShutterstockAssistant\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$file     = SSIA_PATH . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'Company\\SeoShutterstockAssistant\\Security\\Capabilities', 'activate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		load_plugin_textdomain( 'seo-shutterstock-image-assistant', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
		Company\SeoShutterstockAssistant\Plugin::instance()->boot();
	}
);
