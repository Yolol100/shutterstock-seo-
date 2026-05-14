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
				'label'  => __( 'Shutterstock OAuth configured', 'seo-shutterstock-image-assistant' ),
				'passed' => ! empty( $settings['oauth_client_id'] ) && ! empty( $settings['oauth_client_secret'] ) && ! empty( $settings['oauth_redirect_uri'] ),
				'help'   => __( 'Client ID, client secret and redirect URI are needed for the premium connection flow.', 'seo-shutterstock-image-assistant' ),
			),
			'licensing_ready' => array(
				'label'  => __( 'Licensing token and subscription ready', 'seo-shutterstock-image-assistant' ),
				'passed' => ! empty( $settings['access_token'] ) && ! empty( $settings['subscription_id'] ),
				'help'   => __( 'A token with licensing scopes and a subscription ID are required before purchase actions.', 'seo-shutterstock-image-assistant' ),
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
