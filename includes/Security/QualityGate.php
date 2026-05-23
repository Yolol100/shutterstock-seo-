<?php
namespace Company\SeoShutterstockAssistant\Security;

use Company\SeoShutterstockAssistant\Admin\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class QualityGate {
	public function report(): array {
		$settings = Settings::get();
		$mapping  = isset( $settings['acf_mapping'] ) && is_array( $settings['acf_mapping'] ) ? $settings['acf_mapping'] : array();
		$oauth_configured = ( defined( 'SSIA_SHUTTERSTOCK_CLIENT_ID' ) && defined( 'SSIA_SHUTTERSTOCK_CLIENT_SECRET' ) ) || ( ! empty( $settings['oauth_client_id'] ) && ! empty( $settings['oauth_client_secret'] ) );
		$checks   = array(
			'wp_runtime' => array(
				'label'  => __( 'WordPress 6.5+ runtime', 'seo-shutterstock-image-assistant' ),
				'passed' => version_compare( get_bloginfo( 'version' ), '6.5', '>=' ),
				'help'   => __( 'Required for the supported admin and dependency baseline.', 'seo-shutterstock-image-assistant' ),
			),
			'php_runtime' => array(
				'label'  => __( 'PHP 8.1+ runtime', 'seo-shutterstock-image-assistant' ),
				'passed' => version_compare( PHP_VERSION, '8.1', '>=' ),
				'help'   => __( 'Required by the plugin type hints and syntax.', 'seo-shutterstock-image-assistant' ),
			),
			'oauth_configured' => array(
				'label'  => __( 'Shutterstock server OAuth configured', 'seo-shutterstock-image-assistant' ),
				'passed' => $oauth_configured,
				'help'   => __( 'OAuth app credentials should be configured server-side; they are not editable in the dashboard.', 'seo-shutterstock-image-assistant' ),
			),
			'licensing_ready' => array(
				'label'  => __( 'Connected account ready for licensing', 'seo-shutterstock-image-assistant' ),
				'passed' => ! empty( $settings['access_token'] ),
				'help'   => __( 'A connected Shutterstock account with licensing scopes is required before purchase actions. Team or corporate subscriptions are auto-detected or can be targeted with a subscription ID.', 'seo-shutterstock-image-assistant' ),
			),
			'acf_ready' => array(
				'label'  => __( 'ACF available or gracefully disabled', 'seo-shutterstock-image-assistant' ),
				'passed' => empty( $settings['acf_support'] ) || function_exists( 'update_field' ),
				'help'   => __( 'Enable ACF support only when ACF is active on the site.', 'seo-shutterstock-image-assistant' ),
			),
			'mapping_ready' => array(
				'label'  => __( 'Three image field mappings configured', 'seo-shutterstock-image-assistant' ),
				'passed' => ! empty( $mapping['image_1'] ) && ! empty( $mapping['image_2'] ) && ! empty( $mapping['image_3'] ),
				'help'   => __( 'The workflow attaches exactly three approved images.', 'seo-shutterstock-image-assistant' ),
			),
			'safe_license_policy' => array(
				'label'  => __( 'Safe licensing policy', 'seo-shutterstock-image-assistant' ),
				'passed' => ! empty( $settings['safe_search'] ) && empty( $settings['editorial_allowed'] ),
				'help'   => __( 'Safe search on and editorial off is the recommended default for client SEO pages.', 'seo-shutterstock-image-assistant' ),
			),
			'privacy_policy' => array(
				'label'  => __( 'Privacy and retention controls', 'seo-shutterstock-image-assistant' ),
				'passed' => absint( $settings['log_retention'] ?? 0 ) >= 50,
				'help'   => __( 'Logs are retained with a bounded retention limit and secrets are masked.', 'seo-shutterstock-image-assistant' ),
			),
		);

		$passed = count( array_filter( $checks, static fn( array $check ): bool => ! empty( $check['passed'] ) ) );
		$total  = count( $checks );
		$score  = (int) round( ( $passed / max( 1, $total ) ) * 100 );

		return array(
			'score'       => $score,
			'passed'      => $passed,
			'total'       => $total,
			'status'      => 100 === $score ? 'production-ready' : 'environment-action-needed',
			'label'       => 100 === $score ? __( '100/100 environment ready', 'seo-shutterstock-image-assistant' ) : __( 'Environment checks still needed', 'seo-shutterstock-image-assistant' ),
			'checks'      => $checks,
			'static_note' => __( 'Static package checks can be green before live Shutterstock and WordPress runtime checks are proven.', 'seo-shutterstock-image-assistant' ),
		);
	}
}
