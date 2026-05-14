<?php
namespace Company\SeoShutterstockAssistant\Shutterstock;

use Company\SeoShutterstockAssistant\Admin\Settings;
use Company\SeoShutterstockAssistant\Logs\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SearchService {
	public function search_many( array $queries ): array {
		$queries = array_slice( array_values( array_filter( array_map( 'sanitize_text_field', $queries ) ) ), 0, 5 );
		$items   = array();
		foreach ( $queries as $query ) {
			$items = array_merge( $items, $this->search( $query ) );
		}
		return $this->remove_already_used( $this->dedupe( $items ) );
	}

	private function search( string $query ): array {
		$settings = Settings::get();
		$key      = 'ssia_search_' . md5( $query . $settings['default_orientation'] . $settings['default_results_count'] . (int) $settings['safe_search'] . (int) $settings['editorial_allowed'] );
		$cached   = get_transient( $key );
		if ( is_array( $cached ) ) {
			return array_values( array_filter( $cached, 'is_array' ) );
		}

		$args = array(
			'query'       => $query,
			'image_type'  => 'photo',
			'orientation' => $settings['default_orientation'],
			'per_page'    => absint( $settings['default_results_count'] ),
			'safe'        => $settings['safe_search'] ? 'true' : 'false',
			'view'        => 'full',
		);

		$response = ( new Client() )->get( 'images/search', $args );
		if ( is_wp_error( $response ) ) {
			( new Logger() )->error( 'search_failed', array( 'query' => $query, 'message' => $response->get_error_message() ) );
			return array();
		}

		$raw_items = array_values( array_filter( (array) ( $response['data'] ?? array() ), 'is_array' ) );
		if ( empty( $settings['editorial_allowed'] ) ) {
			$raw_items = array_values( array_filter( $raw_items, array( $this, 'is_non_editorial' ) ) );
		}

		$data = array_map( fn( array $item ): array => $this->normalize_item( $item, $query ), $raw_items );
		set_transient( $key, $data, HOUR_IN_SECONDS );
		return $data;
	}

	private function is_non_editorial( array $item ): bool {
		foreach ( array( 'is_editorial', 'editorial' ) as $key ) {
			if ( isset( $item[ $key ] ) && rest_sanitize_boolean( $item[ $key ] ) ) {
				return false;
			}
		}
		$type = strtolower( (string) ( $item['image_type'] ?? $item['media_type'] ?? '' ) );
		return ! str_contains( $type, 'editorial' );
	}

	private function normalize_item( array $item, string $query ): array {
		$contributor = isset( $item['contributor'] ) && is_array( $item['contributor'] ) ? $item['contributor'] : array();
		$assets      = isset( $item['assets'] ) && is_array( $item['assets'] ) ? $item['assets'] : array();
		$preview     = isset( $assets['preview'] ) && is_array( $assets['preview'] ) ? $assets['preview'] : array();
		$small_thumb = isset( $assets['small_thumb'] ) && is_array( $assets['small_thumb'] ) ? $assets['small_thumb'] : array();

		return array(
			'id'          => sanitize_text_field( (string) ( $item['id'] ?? '' ) ),
			'description' => sanitize_text_field( (string) ( $item['description'] ?? '' ) ),
			'orientation' => sanitize_text_field( (string) ( $item['aspect'] ?? '' ) ),
			'contributor' => sanitize_text_field( (string) ( $contributor['id'] ?? '' ) ),
			'thumbnail'   => esc_url_raw( (string) ( $preview['url'] ?? $small_thumb['url'] ?? '' ) ),
			'query'       => sanitize_text_field( $query ),
			'match_score' => $this->score( (string) ( $item['description'] ?? '' ), $query ),
		);
	}

	private function score( string $description, string $query ): string {
		$words = array_unique( array_filter( preg_split( '/\s+/', strtolower( $query ) ) ) );
		$hits  = 0;
		foreach ( $words as $word ) {
			if ( strlen( $word ) > 3 && str_contains( strtolower( $description ), $word ) ) {
				++$hits;
			}
		}
		return $hits >= 3 ? 'Good match' : ( $hits >= 1 ? 'Medium match' : 'Low match' );
	}

	private function dedupe( array $items ): array {
		$seen = array();
		return array_values( array_filter( $items, static function ( array $item ) use ( &$seen ): bool {
			$id = sanitize_text_field( (string) ( $item['id'] ?? '' ) );
			if ( '' === $id || isset( $seen[ $id ] ) ) {
				return false;
			}
			$seen[ $id ] = true;
			return true;
		} ) );
	}

	private function remove_already_used( array $items ): array {
		$used         = get_option( 'ssia_used_shutterstock_ids', array() );
		$reservations = get_option( 'ssia_reserved_shutterstock_ids', array() );
		$blocked      = is_array( $used ) ? array_map( 'strval', array_keys( $used ) ) : array();
		if ( is_array( $reservations ) ) {
			$now = time();
			foreach ( $reservations as $image_id => $reservation ) {
				if ( ! is_array( $reservation ) ) {
					continue;
				}
				if ( absint( $reservation['expires_at'] ?? 0 ) > $now ) {
					$blocked[] = (string) $image_id;
				}
			}
		}
		$blocked = array_values( array_unique( $blocked ) );
		return array_values( array_filter( $items, static fn( array $item ): bool => ! in_array( (string) ( $item['id'] ?? '' ), $blocked, true ) ) );
	}
}
