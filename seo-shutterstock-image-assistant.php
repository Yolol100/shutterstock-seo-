<?php
/**
 * Plugin Name: SEO Image Assistant for Shutterstock
 * Plugin URI: https://webactueel.nl/
 * Description: Premium workflow tool for finding, reviewing, licensing, and attaching Shutterstock images to SEO pages via ACF fields.
 * Version: 1.6.78
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: Webactueel
 * Author URI: https://webactueel.nl/
 * Text Domain: seo-shutterstock-image-assistant
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SSIA_VERSION', '1.6.78' );
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

register_activation_hook(
	__FILE__,
	static function (): void {
		Company\SeoShutterstockAssistant\Upgrade\LegacyPluginReplacer::replace();
		Company\SeoShutterstockAssistant\Security\Capabilities::activate();
		Company\SeoShutterstockAssistant\Media\ImageImporter::schedule_used_ids_sync();
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		Company\SeoShutterstockAssistant\Queue\QueueManager::clear_scheduled_events();
		Company\SeoShutterstockAssistant\Queue\QueueManager::clear_runtime_locks();
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		Company\SeoShutterstockAssistant\Plugin::instance()->boot();
	}
);
