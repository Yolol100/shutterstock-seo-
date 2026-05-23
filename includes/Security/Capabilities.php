<?php
namespace Company\SeoShutterstockAssistant\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Capabilities {
	public const MANAGE  = 'manage_options';
	public const EDIT    = 'edit_posts';
	public const LICENSE = 'ssia_license_images';
	public const SEARCH  = 'ssia_search_images';
	private const CAPS_VERSION_OPTION = 'ssia_caps_version';
	private const CAPS_VERSION        = '1.6.31';

	public function register(): void {
		add_action( 'admin_init', array( self::class, 'maybe_upgrade_caps' ) );
	}

	public static function activate(): void {
		self::grant_caps();
		update_option( self::CAPS_VERSION_OPTION, self::CAPS_VERSION, false );
	}

	public static function maybe_upgrade_caps(): void {
		if ( self::CAPS_VERSION === (string) get_option( self::CAPS_VERSION_OPTION, '' ) ) {
			return;
		}

		self::grant_caps();
		update_option( self::CAPS_VERSION_OPTION, self::CAPS_VERSION, false );
	}

	public static function grant_caps(): void {
		$role = get_role( 'administrator' );
		if ( $role && ! $role->has_cap( self::LICENSE ) ) {
			$role->add_cap( self::LICENSE );
		}
		if ( $role && ! $role->has_cap( self::SEARCH ) ) {
			$role->add_cap( self::SEARCH );
		}
	}
}
