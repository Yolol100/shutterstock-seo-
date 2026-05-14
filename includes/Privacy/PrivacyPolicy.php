<?php
namespace Company\SeoShutterstockAssistant\Privacy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PrivacyPolicy {
	public function register(): void {
		add_action( 'admin_init', array( $this, 'add_privacy_policy_content' ) );
	}

	public function add_privacy_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = sprintf(
			'<p>%s</p><p>%s</p><p>%s</p>',
			esc_html__( 'SEO Shutterstock Image Assistant connects this WordPress site to the Shutterstock API to search image previews, verify subscription access, license approved images, and download licensed files into the WordPress Media Library.', 'seo-shutterstock-image-assistant' ),
			esc_html__( 'When an authorized user searches or licenses images, page context such as post title, slug, focus keyword, excerpt, selected Shutterstock image IDs, licensing status, WordPress user ID, and timestamps may be stored in plugin logs for operational troubleshooting.', 'seo-shutterstock-image-assistant' ),
			esc_html__( 'API credentials, OAuth access tokens, refresh tokens, and client secrets are masked in the admin interface and redacted from logs. Log retention and uninstall data removal are controlled from the plugin settings.', 'seo-shutterstock-image-assistant' )
		);

		wp_add_privacy_policy_content( 'SEO Shutterstock Image Assistant', wp_kses_post( $content ) );
	}
}
