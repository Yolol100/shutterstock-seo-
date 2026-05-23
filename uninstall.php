<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ssia_settings = get_option( 'ssia_settings', array() );
if ( is_array( $ssia_settings ) && empty( $ssia_settings['delete_data_on_uninstall'] ) ) {
	return;
}

delete_option( 'ssia_settings' );
delete_option( 'ssia_logs' );
delete_option( 'ssia_queue' );
delete_option( 'ssia_used_shutterstock_ids' );
delete_option( 'ssia_reserved_shutterstock_ids' );
delete_option( 'ssia_caps_version' );
delete_option( 'ssia_cache_version' );
global $wpdb;
$ssia_like_patterns = array(
	'_transient_ssia_search_%',
	'_transient_timeout_ssia_search_%',
	'ssia_reserve_%',
	'ssia_queue_lock_%',
);
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		...$ssia_like_patterns
	)
);

$ssia_role = get_role( 'administrator' );
foreach ( array( 'ssia_license_images', 'ssia_search_images' ) as $ssia_cap ) {
	if ( $ssia_role && $ssia_role->has_cap( $ssia_cap ) ) {
		$ssia_role->remove_cap( $ssia_cap );
	}
}
