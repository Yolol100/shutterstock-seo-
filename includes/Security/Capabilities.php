<?php
namespace Company\SeoShutterstockAssistant\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Capabilities {
	public const MANAGE  = 'manage_options';
	public const EDIT    = 'edit_posts';
	public const LICENSE = 'ssia_license_images';

	public function register(): void {
		add_action( 'admin_init', array( $this, 'ensure_caps' ) );
	}

	public function ensure_caps(): void {
		self::grant_caps();
	}

	public static function activate(): void {
		self::grant_caps();
	}

	public static function grant_caps(): void {
		$role = get_role( 'administrator' );
		if ( $role && ! $role->has_cap( self::LICENSE ) ) {
			$role->add_cap( self::LICENSE );
		}
	}
}
