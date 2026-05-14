<?php
namespace Company\SeoShutterstockAssistant\SEO;

use Company\SeoShutterstockAssistant\Admin\Settings;
use Company\SeoShutterstockAssistant\Queue\QueueManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class QueryBuilder {
	public function find_pages( bool $only_missing = true, array $filters = array() ): array {
		$settings = Settings::get();
		$args     = array(
			'post_type'      => $this->allowed_post_types( $filters['post_type'] ?? '' ),
			'post_status'    => $this->allowed_statuses( $filters['status'] ?? '' ),
			'posts_per_page' => max( 1, min( 50, absint( $filters['per_page'] ?? 25 ) ) ),
			'paged'          => max( 1, absint( $filters['page'] ?? 1 ) ),
			'no_found_rows'  => false,
			's'              => sanitize_text_field( (string) ( $filters['search'] ?? '' ) ),
		);

		if ( empty( $args['post_type'] ) ) {
			$args['post_type'] = array_map( 'sanitize_key', (array) $settings['allowed_post_types'] );
		}

		$query = new \WP_Query( $args );
		$posts = array_filter( $query->posts, static fn( \WP_Post $post ): bool => current_user_can( 'edit_post', $post->ID ) );
		$items = array_map( array( $this, 'present_post' ), $posts );
		if ( $only_missing ) {
			$items = array_values( array_filter( $items, static fn( array $item ): bool => absint( $item['current_images'] ?? 0 ) < 3 ) );
		}
		return $items;
	}

	public function count_missing_pages(): int {
		$items = $this->find_pages( true, array( 'per_page' => 50 ) );
		return count( $items );
	}

	public function build_queries( int $post_id ): array {
		$title   = get_the_title( $post_id );
		$slug    = get_post_field( 'post_name', $post_id );
		$keyword = $this->focus_keyword( $post_id );
		$acf     = $this->acf_context( $post_id );
		$excerpt = get_the_excerpt( $post_id );
		$content = wp_strip_all_tags( (string) get_post_field( 'post_content', $post_id ) );
		$base    = trim( implode( ' ', array_filter( array( $keyword, str_replace( '-', ' ', (string) $slug ), $title, $acf, $excerpt, wp_trim_words( $content, 12, '' ) ) ) ) );
		$base    = $this->normalize_context( $base );

		return array_values( array_filter( array_unique( array(
			$this->to_english_intent( $base, 'atmosphere' ),
			$this->to_english_intent( $base, 'service' ),
			$this->to_english_intent( $base, 'local' ),
		) ) ) );
	}

	private function present_post( \WP_Post $post ): array {
		$count  = $this->image_count( $post->ID );
		$queue      = QueueManager::all();
		$queue_item = isset( $queue[ $post->ID ] ) && is_array( $queue[ $post->ID ] ) ? $queue[ $post->ID ] : array();
		$status     = sanitize_key( $queue_item['status'] ?? '' );
		$badge      = $count >= 3 ? __( 'Complete', 'seo-shutterstock-image-assistant' ) : __( 'Missing images', 'seo-shutterstock-image-assistant' );
		if ( 'suggestions_found' === $status ) {
			$badge = __( 'Suggestions ready', 'seo-shutterstock-image-assistant' );
		} elseif ( 'failed' === $status ) {
			$badge = __( 'Failed', 'seo-shutterstock-image-assistant' );
		}

		return array(
			'id'             => $post->ID,
			'title'          => get_the_title( $post ),
			'slug'           => $post->post_name,
			'post_type'      => $post->post_type,
			'status'         => $post->post_status,
			'current_images' => $count,
			'focus_keyword'  => $this->focus_keyword( $post->ID ),
			'location'       => $this->meta_context( $post->ID, array( 'location', 'city', 'plaats', 'regio' ) ),
			'service'        => $this->meta_context( $post->ID, array( 'service', 'dienst', 'product' ) ),
			'badge'          => $badge,
		);
	}

	private function image_count( int $post_id ): int {
		$settings = Settings::get();
		$mapping  = isset( $settings['acf_mapping'] ) && is_array( $settings['acf_mapping'] ) ? $settings['acf_mapping'] : array();
		$count    = has_post_thumbnail( $post_id ) ? 1 : 0;
		foreach ( array( 'image_1', 'image_2', 'image_3' ) as $slot ) {
			$field = $mapping[ $slot ] ?? '';
			if ( $field && function_exists( 'get_field' ) && get_field( $field, $post_id ) ) {
				++$count;
			}
		}
		return min( 3, $count );
	}

	private function focus_keyword( int $post_id ): string {
		$settings = Settings::get();
		$yoast    = $settings['yoast_support'] ? get_post_meta( $post_id, '_yoast_wpseo_focuskw', true ) : '';
		$rankmath = $settings['rank_math_support'] ? get_post_meta( $post_id, 'rank_math_focus_keyword', true ) : '';
		return sanitize_text_field( (string) ( $yoast ?: $rankmath ) );
	}

	private function acf_context( int $post_id ): string {
		if ( ! function_exists( 'get_fields' ) ) {
			return '';
		}
		$values = get_fields( $post_id );
		if ( ! is_array( $values ) ) {
			return '';
		}
		$text = array();
		foreach ( $values as $key => $value ) {
			if ( is_scalar( $value ) && ! str_contains( (string) $key, 'image' ) ) {
				$text[] = (string) $value;
			}
		}
		return implode( ' ', array_slice( $text, 0, 8 ) );
	}

	private function meta_context( int $post_id, array $keys ): string {
		foreach ( $keys as $key ) {
			$value = get_post_meta( $post_id, $key, true );
			if ( $value ) {
				return sanitize_text_field( (string) $value );
			}
		}
		return '';
	}

	private function to_english_intent( string $base, string $intent ): string {
		$base = $this->translate_common_dutch_terms( $base );
		$words = array_slice( array_filter( preg_split( '/\s+/', strtolower( remove_accents( $base ) ) ) ), 0, 8 );
		$base  = implode( ' ', $words );
		return match ( $intent ) {
			'atmosphere' => trim( $base . ' professional lifestyle photo' ),
			'service'    => trim( $base . ' service business photo' ),
			'local'      => trim( $base . ' local exterior context' ),
			default      => trim( $base ),
		};
	}

	private function normalize_context( string $value ): string {
		$value = wp_strip_all_tags( $value );
		$value = preg_replace( '/[^\p{L}\p{N}\s\-]/u', ' ', $value );
		return trim( preg_replace( '/\s+/', ' ', (string) $value ) );
	}

	private function translate_common_dutch_terms( string $value ): string {
		$map = array(
			'dakdekker'    => 'roofer',
			'dakreparatie' => 'roof repair',
			'woning'       => 'house',
			'bedrijf'      => 'business',
			'tuin'         => 'garden',
			'zorg'         => 'care',
			'advocaat'     => 'lawyer',
			'makelaar'     => 'real estate agent',
		);
		return str_ireplace( array_keys( $map ), array_values( $map ), $value );
	}

	private function allowed_post_types( string $requested ): array {
		$settings = Settings::get();
		if ( $requested && in_array( $requested, (array) $settings['allowed_post_types'], true ) ) {
			return array( sanitize_key( $requested ) );
		}
		return array_map( 'sanitize_key', (array) $settings['allowed_post_types'] );
	}

	private function allowed_statuses( string $requested ): array {
		$allowed = array( 'publish', 'draft', 'pending', 'private' );
		return $requested && in_array( $requested, $allowed, true ) ? array( sanitize_key( $requested ) ) : array( 'publish', 'draft' );
	}
}
