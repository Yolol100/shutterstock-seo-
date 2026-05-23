<?php
namespace Company\SeoShutterstockAssistant\Rest;

use Company\SeoShutterstockAssistant\Admin\Settings;
use Company\SeoShutterstockAssistant\Security\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait PermissionCallbacksTrait {
	public function can_edit(): bool {
		return current_user_can( Capabilities::EDIT );
	}

	public function can_manage(): bool {
		return current_user_can( Capabilities::MANAGE );
	}

	public function can_search(): bool {
		return current_user_can( Capabilities::SEARCH ) || current_user_can( Capabilities::MANAGE );
	}

	public function can_license(): bool {
		return Settings::can_license();
	}
}