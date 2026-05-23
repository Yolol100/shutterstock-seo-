<?php
namespace Company\SeoShutterstockAssistant;

use Company\SeoShutterstockAssistant\Admin\Menu;
use Company\SeoShutterstockAssistant\Admin\OAuthCallback;
use Company\SeoShutterstockAssistant\Admin\Settings;
use Company\SeoShutterstockAssistant\Rest\Routes;
use Company\SeoShutterstockAssistant\Queue\QueueManager;
use Company\SeoShutterstockAssistant\Privacy\PrivacyPolicy;
use Company\SeoShutterstockAssistant\Security\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {
	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		( new Capabilities() )->register();
		( new Routes() )->register();
		( new QueueManager() )->register();
		( new PrivacyPolicy() )->register();
		( new OAuthCallback() )->register();

		if ( is_admin() ) {
			( new Settings() )->register();
			( new Menu() )->register();
		}
	}

	public static function option_key(): string {
		return 'ssia_settings';
	}
}
