<?php
namespace Company\SeoShutterstockAssistant\Infrastructure;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Cache {
	private const VERSION_OPTION = 'ssia_cache_version';

	public static function version(): string {
		$version = get_option( self::VERSION_OPTION, '' );
		if ( ! is_string( $version ) || '' === $version ) {
			$version = (string) time();
			update_option( self::VERSION_OPTION, $version, false );
		}
		return $version;
	}

	public static function key( string $prefix, array $parts ): string {
		$payload = wp_json_encode( array( self::version(), $parts ) );
		return sanitize_key( $prefix . '_' . md5( is_string( $payload ) ? $payload : serialize( $parts ) ) );
	}

	public static function get( string $key ): mixed {
		$value = get_transient( $key );
		return false === $value ? null : $value;
	}

	public static function set( string $key, mixed $value, int $ttl ): void {
		set_transient( $key, $value, max( MINUTE_IN_SECONDS, $ttl ) );
	}

	public static function bump(): void {
		update_option( self::VERSION_OPTION, (string) time(), false );
	}
}
