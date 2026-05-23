<?php
namespace Company\SeoShutterstockAssistant\Upgrade;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects legacy/duplicate builds of this plugin when this build is activated.
 */
final class LegacyPluginReplacer {
	private const LOG_OPTION = 'ssia_legacy_replacement_log';

	/**
	 * Detect old plugin folders that are clearly the same SSIA product. Never delete automatically on activation.
	 */
	public static function replace(): void {
		if ( ! is_admin() ) {
			return;
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$current_plugin = plugin_basename( SSIA_FILE );
		$plugins        = get_plugins();
		$legacy_plugins = array();

		foreach ( $plugins as $plugin_file => $plugin_data ) {
			if ( $plugin_file === $current_plugin ) {
				continue;
			}

			if ( self::is_legacy_ssia_plugin( $plugin_file, $plugin_data ) ) {
				$legacy_plugins[] = $plugin_file;
			}
		}

		if ( empty( $legacy_plugins ) ) {
			update_option(
				self::LOG_OPTION,
				array(
					'time'     => time(),
					'status'   => 'no_legacy_plugins_found',
					'detected' => array(),
					'failed'   => array(),
				),
				false
			);
			return;
		}

		// Safety hardening: activation must never delete or deactivate other plugins.
		// Store detected duplicates for manual administrator review instead.
		update_option(
			self::LOG_OPTION,
			array(
				'time'     => time(),
				'status'   => 'legacy_plugins_detected_manual_review_required',
				'detected' => array_values( $legacy_plugins ),
				'failed'   => array(),
			),
			false
		);
	}


	/**
	 * Match only plugins that are clearly this plugin or a previous duplicate build.
	 *
	 * @param string $plugin_file Relative plugin basename, e.g. folder/file.php.
	 * @param array<string,mixed> $plugin_data Parsed plugin headers.
	 */
	private static function is_legacy_ssia_plugin( string $plugin_file, array $plugin_data ): bool {
		$name        = strtolower( (string) ( $plugin_data['Name'] ?? '' ) );
		$description = strtolower( (string) ( $plugin_data['Description'] ?? '' ) );
		$text_domain = strtolower( (string) ( $plugin_data['TextDomain'] ?? '' ) );
		$path        = strtolower( $plugin_file );
		$haystack    = $path . ' ' . $name . ' ' . $description . ' ' . $text_domain;

		$strong_markers = array(
			'seo-shutterstock-image-assistant',
			'seo shutterstock image assistant',
		);

		foreach ( $strong_markers as $marker ) {
			if ( false !== strpos( $haystack, $marker ) ) {
				return true;
			}
		}

		// Only match the bare "ssia" token, not substrings inside unrelated words
		// like "russia" or "assassin".
		if ( (bool) preg_match( '/\bssia\b/', $haystack ) ) {
			return true;
		}

		return false !== strpos( $haystack, 'shutterstock' )
			&& false !== strpos( $haystack, 'image' )
			&& (
				false !== strpos( $haystack, 'seo' )
				|| false !== strpos( $haystack, 'acf' )
				|| false !== strpos( $haystack, 'assistant' )
			);
	}
}
