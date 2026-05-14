<?php
namespace Company\SeoShutterstockAssistant\Logs;

use Company\SeoShutterstockAssistant\Admin\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Logger {
	public const OPTION = 'ssia_logs';

	public function error( string $action, array $context = array() ): void {
		$this->write( 'error', $action, $context );
	}

	public function info( string $action, array $context = array() ): void {
		$this->write( 'info', $action, $context );
	}

	public static function latest( int $limit = 20 ): array {
		$logs = get_option( self::OPTION, array() );
		$logs = is_array( $logs ) ? $logs : array();
		$logs = array_values( array_filter( $logs, 'is_array' ) );
		return array_reverse( array_slice( $logs, - absint( $limit ) ) );
	}

	public static function count_level( string $level ): int {
		$logs = get_option( self::OPTION, array() );
		if ( ! is_array( $logs ) ) {
			return 0;
		}
		return count( array_filter( $logs, static fn( mixed $item ): bool => is_array( $item ) && ( $item['level'] ?? '' ) === $level ) );
	}

	private function write( string $level, string $action, array $context ): void {
		$context = $this->redact( $context );
		$logs    = get_option( self::OPTION, array() );
		$logs    = is_array( $logs ) ? $logs : array();
		$logs[]  = array(
			'level'   => sanitize_key( $level ),
			'action'  => sanitize_key( $action ),
			'context' => $this->sanitize_context( $context ),
			'user_id' => get_current_user_id(),
			'time'    => current_time( 'mysql' ),
		);
		$settings  = Settings::get();
		$retention = max( 50, min( 5000, absint( $settings['log_retention'] ?? 500 ) ) );
		update_option( self::OPTION, array_slice( $logs, -$retention ), false );
	}

	private function redact( array $context ): array {
		foreach ( array( 'api_key', 'api_secret', 'access_token', 'refresh_token', 'oauth_client_secret', 'authorization', 'Authorization', 'token', 'password' ) as $key ) {
			if ( isset( $context[ $key ] ) ) {
				$context[ $key ] = '[redacted]';
			}
		}
		return $context;
	}

	private function sanitize_context( array $context ): array {
		$clean = array();
		foreach ( $context as $key => $value ) {
			if ( is_scalar( $value ) || null === $value ) {
				$clean[ sanitize_key( (string) $key ) ] = sanitize_text_field( (string) $value );
			}
		}
		return $clean;
	}
}
