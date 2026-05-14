<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$settings = get_option( 'ssia_settings', array() );
if ( is_array( $settings ) && empty( $settings['delete_data_on_uninstall'] ) ) {
	return;
}

delete_option( 'ssia_settings' );
delete_option( 'ssia_logs' );
delete_option( 'ssia_queue' );
delete_option( 'ssia_used_shutterstock_ids' );
delete_option( 'ssia_reserved_shutterstock_ids' );
delete_transient( 'ssia_queue_lock' );

$role = get_role( 'administrator' );
if ( $role && $role->has_cap( 'ssia_license_images' ) ) {
	$role->remove_cap( 'ssia_license_images' );
}
