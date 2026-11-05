<?php
/**
 * Isolated WordPress logging regression using fake sentinel values only.
 *
 * Runs in disposable CI: no actual Shutterstock token, customer account,
 * license, media file, or site integration is used.
 */

wp_set_current_user( 1 );
delete_option( 'ssia_logs' );

$sentinels = array(
    'CI_SENTINEL_ACCESS_123',
    'CI_SENTINEL_BEARER_123',
    'CI_SENTINEL_CLIENT_123',
    'CI_SENTINEL_APIKEY_123',
    'CI_SENTINEL_PASSWORD_123',
);

try {
    $logger = new \Company\SeoShutterstockAssistant\Logs\Logger();
    $logger->info(
        'ci_token_redaction',
        array(
            'access_token' => $sentinels[0],
            'request' => array(
                'Authorization' => 'Bearer ' . $sentinels[1],
                'child' => array(
                    'ClientSecret' => $sentinels[2],
                    'api_key' => $sentinels[3],
                ),
            ),
            'API-KEY' => $sentinels[3],
            'password' => $sentinels[4],
            'message' => 'CI safe diagnostic',
        )
    );

    $logs = get_option( 'ssia_logs', array() );
    if ( ! is_array( $logs ) || count( $logs ) !== 1 ) {
        throw new RuntimeException( 'Synthetic log entry missing.' );
    }

    $encoded = wp_json_encode( $logs );
    if ( ! is_string( $encoded ) ) {
        throw new RuntimeException( 'Could not inspect synthetic log entry.' );
    }

    foreach ( $sentinels as $needle ) {
        if ( strpos( $encoded, $needle ) !== false ) {
            throw new RuntimeException( 'Sensitive nested key was not redacted: ' . $needle );
        }
    }

    $context = $logs[0]['context'] ?? array();
    if ( ( $context['message'] ?? '' ) !== 'CI safe diagnostic'
        || ( $context['access_token'] ?? '' ) !== '[redacted]'
        || ( $context['request']['authorization'] ?? '' ) !== '[redacted]'
        || ( $context['request']['child']['clientsecret'] ?? '' ) !== '[redacted]'
        || ( $context['api-key'] ?? '' ) !== '[redacted]' ) {
        throw new RuntimeException( 'Safe diagnostic message or nested redaction contract was changed.' );
    }

    echo "Shutterstock nested log redaction: OK\n";
} finally {
    delete_option( 'ssia_logs' );
}
