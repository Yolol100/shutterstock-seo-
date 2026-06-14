<?php
namespace Company\SeoShutterstockAssistant\Admin;

use Company\SeoShutterstockAssistant\Logs\Logger;
use Company\SeoShutterstockAssistant\Security\Capabilities;
use Company\SeoShutterstockAssistant\Shutterstock\Client;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OAuthCallback {
	public function register(): void {
		add_action( 'admin_init', array( $this, 'maybe_handle_callback' ), 1 );
	}

	public function maybe_handle_callback(): void {
		global $pagenow;
		if ( ! is_admin() || 'admin.php' !== (string) $pagenow || empty( $_GET['state'] ) || ( empty( $_GET['code'] ) && empty( $_GET['error'] ) ) ) {
			return;
		}

		if ( ! is_user_logged_in() || ! current_user_can( Capabilities::MANAGE ) ) {
			return;
		}

		$settings = Settings::get();
		$state    = sanitize_text_field( wp_unslash( (string) $_GET['state'] ) );

		if ( empty( $settings['oauth_state'] ) || ! hash_equals( (string) $settings['oauth_state'], $state ) ) {
			return;
		}

		if ( empty( $settings['oauth_state_expires_at'] ) || absint( $settings['oauth_state_expires_at'] ) < time() ) {
			Settings::update_partial( array( 'oauth_state' => '', 'oauth_state_expires_at' => 0 ) );
			$this->redirect_to_dashboard( 'error', __( 'OAuth state expired. Please start the connection again.', 'seo-shutterstock-image-assistant' ) );
		}

		if ( ! empty( $_GET['error'] ) ) {
			$message = ! empty( $_GET['error_description'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['error_description'] ) ) : sanitize_text_field( wp_unslash( (string) $_GET['error'] ) );
			Settings::update_partial( array( 'oauth_state' => '', 'oauth_state_expires_at' => 0 ) );
			( new Logger() )->error( 'oauth_callback_error', array( 'message' => $message ) );
			$this->redirect_to_dashboard( 'error', $message );
		}

		$code   = sanitize_text_field( wp_unslash( (string) $_GET['code'] ) );
		$result = ( new Client() )->exchange_authorization_code( $code, $state );

		if ( is_wp_error( $result ) ) {
			$this->redirect_to_dashboard( 'error', $result->get_error_message() );
		}

		$this->redirect_to_dashboard( 'success', __( 'Shutterstock OAuth connection completed.', 'seo-shutterstock-image-assistant' ) );
	}

	private function redirect_to_dashboard( string $status, string $message ): void {
		$url = add_query_arg(
			array(
				'ssia_oauth_status'  => sanitize_key( $status ),
				'ssia_oauth_message' => rawurlencode( sanitize_text_field( $message ) ),
			),
			admin_url( 'admin.php?page=ssia-dashboard' )
		);

		wp_safe_redirect( $url . '#ssia-settings' );
		exit;
	}
}
