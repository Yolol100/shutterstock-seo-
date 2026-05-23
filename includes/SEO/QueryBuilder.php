<?php
namespace Company\SeoShutterstockAssistant\SEO;

use Company\SeoShutterstockAssistant\Admin\Settings;
use Company\SeoShutterstockAssistant\ACF\FieldMapper;
use Company\SeoShutterstockAssistant\Infrastructure\Cache;
use Company\SeoShutterstockAssistant\Queue\QueueManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class QueryBuilder {
	public function find_pages( bool $only_missing = true, array $filters = array() ): array {
		$result = $this->find_pages_result( $only_missing, $filters );
		return $result['items'];
	}

	public function find_pages_result( bool $only_missing = true, array $filters = array() ): array {
		$requested_post_type = sanitize_key( (string) ( $filters['post_type'] ?? '' ) );
		if ( '' !== $requested_post_type && 'page' !== $requested_post_type ) {
			return array(
				'items'    => array(),
				'has_more' => false,
			);
		}

		$limit    = max( 1, min( 50, absint( $filters['per_page'] ?? 25 ) ) );
		$page     = max( 1, min( 100, absint( $filters['page'] ?? 1 ) ) );
		$args     = array(
			'post_type'              => array( 'page' ),
			'post_status'            => $this->allowed_statuses( $filters['status'] ?? '' ),
			'posts_per_page'         => $limit,
			'paged'                  => $page,
			'no_found_rows'          => true,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => false,
			's'                      => sanitize_text_field( (string) ( $filters['search'] ?? '' ) ),
		);


		if ( ! $only_missing ) {
			$args['posts_per_page'] = $limit + 1;
			$args['meta_query']     = $this->default_page_template_meta_query();
			$query = new \WP_Query( $args );
			$posts = array_filter( $query->posts, fn( \WP_Post $post ): bool => current_user_can( 'edit_post', $post->ID ) && $this->is_default_page_template( $post->ID ) );
			wp_reset_postdata();
			return array(
				'items'    => array_map( array( $this, 'present_post' ), array_slice( $posts, 0, $limit ) ),
				'has_more' => count( $posts ) > $limit,
			);
		}

		return $this->find_missing_pages_result( $args, $limit, $page );
	}

	private function find_missing_pages_result( array $base_args, int $limit, int $page ): array {
		$items          = array();
		$verified_seen  = 0;
		$verified_start = max( 0, ( $page - 1 ) * $limit );
		$candidate_page = 1;
		$candidate_size = 100;
		$candidate_count = 0;
		$has_more       = false;

		do {
			$args = $base_args;
			$args['posts_per_page'] = $candidate_size;
			$args['paged']          = $candidate_page;
			$args['meta_query']     = array(
					'relation' => 'AND',
					$this->default_page_template_meta_query(),
					$this->missing_image_meta_query(),
				);
				$args['no_found_rows']  = true;
				$args['fields']         = 'ids';

				$query    = new \WP_Query( $args );
				$post_ids = array_filter( array_map( 'absint', (array) $query->posts ) );

				foreach ( $post_ids as $post_id ) {
					if ( ! current_user_can( 'edit_post', $post_id ) || ! $this->is_default_page_template( $post_id ) ) {
						continue;
					}

					$post = get_post( $post_id );
					if ( ! $post instanceof \WP_Post ) {
						continue;
					}

					$item = $this->present_post( $post );
					if ( empty( $item['missing_slots'] ) ) {
						continue;
					}

					if ( $verified_seen < $verified_start ) {
						++$verified_seen;
						continue;
					}

					if ( count( $items ) >= $limit ) {
						$has_more = true;
						break 2;
					}

					$items[] = $item;
					++$verified_seen;
				}

				$candidate_count = count( $post_ids );
			++$candidate_page;
		} while ( $candidate_count === $candidate_size );

		wp_reset_postdata();
		return array(
			'items'    => $items,
			'has_more' => $has_more,
		);
	}

	public function count_missing_pages(): int {
		$post_types = array( 'page' );

		$cache_key = Cache::key( 'ssia_missing_pages_count', array( 'default_template_pages' ) );
		$cached    = Cache::get( $cache_key );
		if ( null !== $cached ) {
			return absint( $cached );
		}

		$count    = 0;
		$paged    = 1;
		$per_page = 250;
		$post_ids = array();

		do {
			$query = new \WP_Query(
				array(
					'post_type'              => $post_types,
					'post_status'            => array( 'publish' ),
					'posts_per_page'         => $per_page,
					'paged'                  => $paged,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => true,
					'update_post_term_cache' => false,
					'meta_query'             => array(
							'relation' => 'AND',
							$this->default_page_template_meta_query(),
							$this->missing_image_meta_query(),
						),
				)
			);

			$post_ids = array_map( 'absint', (array) $query->posts );
			foreach ( $post_ids as $post_id ) {
				if ( current_user_can( 'edit_post', $post_id ) && $this->is_default_page_template( $post_id ) && ! empty( $this->image_slots( $post_id )['missing'] ) ) {
					++$count;
				}
			}

			++$paged;
		} while ( count( $post_ids ) === $per_page );

		wp_reset_postdata();
		Cache::set( $cache_key, $count, 5 * MINUTE_IN_SECONDS );
		return $count;
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

	private function default_page_template_meta_query(): array {
		return array(
			'relation' => 'OR',
			array(
				'key'     => '_wp_page_template',
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => '_wp_page_template',
				'value'   => '',
				'compare' => '=',
			),
			array(
				'key'     => '_wp_page_template',
				'value'   => 'default',
				'compare' => '=',
			),
		);
	}

	private function is_default_page_template( int $post_id ): bool {
		if ( 'page' !== get_post_type( $post_id ) ) {
			return false;
		}

		$template_slug = get_page_template_slug( $post_id );
		$template_meta = (string) get_post_meta( $post_id, '_wp_page_template', true );

		return '' === $template_slug && in_array( $template_meta, array( '', 'default' ), true );
	}

	private function missing_image_meta_query(): array {
		$settings = Settings::get();
		$mapping  = isset( $settings['acf_mapping'] ) && is_array( $settings['acf_mapping'] ) ? $settings['acf_mapping'] : array();
		$queries  = array(
			'relation' => 'OR',
			array(
				'key'     => '_thumbnail_id',
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => '_thumbnail_id',
				'value'   => array( '', '0' ),
				'compare' => 'IN',
			),
		);

		if ( ! empty( $settings['acf_support'] ) ) {
			foreach ( array( 'image_1', 'image_2', 'image_3' ) as $slot ) {
				$field = (string) ( $mapping[ $slot ] ?? $slot );
				if ( '' === $field ) {
					continue;
				}
				$queries[] = array(
					'key'     => $field,
					'compare' => 'NOT EXISTS',
				);
				$queries[] = array(
					'key'     => $field,
					'value'   => array( '', '0' ),
					'compare' => 'IN',
				);
			}
		}

		return $queries;
	}

	private function present_post( \WP_Post $post ): array {
		$slots  = $this->image_slots( $post->ID );
		$count  = count( $slots['filled'] );
		$queue      = QueueManager::all();
		$queue_item = isset( $queue[ $post->ID ] ) && is_array( $queue[ $post->ID ] ) ? $queue[ $post->ID ] : array();
		$status     = sanitize_key( $queue_item['status'] ?? '' );
		$badge      = empty( $slots['missing'] ) ? __( 'Complete', 'seo-shutterstock-image-assistant' ) : __( 'Missing images', 'seo-shutterstock-image-assistant' );
		if ( 'suggestions_found' === $status ) {
			$badge = __( 'Suggestions ready', 'seo-shutterstock-image-assistant' );
		} elseif ( 'failed' === $status ) {
			$badge = __( 'Failed', 'seo-shutterstock-image-assistant' );
		}

		$can_featured_from_acf = FieldMapper::can_set_featured_image_from_first_acf( $post->ID );

		return array(
			'id'             => $post->ID,
			'title'          => get_the_title( $post ),
			'slug'           => $post->post_name,
			'post_type'      => $post->post_type,
			'status'         => $post->post_status,
			'current_images' => $count,
			'total_images'   => count( $slots['all'] ),
			'missing_slots'  => $slots['missing'],
			'filled_slots'   => $slots['filled'],
			'can_featured_from_acf' => $can_featured_from_acf,
			'edit_link'      => get_edit_post_link( $post->ID, 'raw' ),
			'focus_keyword'  => $this->focus_keyword( $post->ID ),
			'location'       => $this->meta_context( $post->ID, array( 'location', 'city', 'plaats', 'regio' ) ),
			'service'        => $this->meta_context( $post->ID, array( 'service', 'dienst', 'product' ) ),
			'badge'          => $badge,
		);
	}


	private function image_slots( int $post_id ): array {
		$settings = Settings::get();
		$mapping  = isset( $settings['acf_mapping'] ) && is_array( $settings['acf_mapping'] ) ? $settings['acf_mapping'] : array();
		$all      = array( 'featured_image' );
		$filled   = array();
		$missing  = array();

		if ( has_post_thumbnail( $post_id ) ) {
			$filled[] = 'featured_image';
		} else {
			$missing[] = 'featured_image';
		}

		foreach ( array( 'image_1', 'image_2', 'image_3' ) as $slot ) {
			$field = (string) ( $mapping[ $slot ] ?? $slot );
			if ( '' === $field ) {
				continue;
			}
			$all[] = $slot;
			$value = function_exists( 'get_field' ) ? get_field( $field, $post_id ) : get_post_meta( $post_id, $field, true );
			if ( ! empty( $value ) ) {
				$filled[] = $slot;
			} else {
				$missing[] = $slot;
			}
		}

		return array(
			'all'     => array_values( array_unique( $all ) ),
			'filled'  => array_values( array_unique( $filled ) ),
			'missing' => array_values( array_unique( $missing ) ),
		);
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


	private function allowed_statuses( string $requested ): array {
		$allowed = array( 'publish', 'draft', 'pending', 'private' );
		return $requested && in_array( $requested, $allowed, true ) ? array( sanitize_key( $requested ) ) : array( 'publish' );
	}
}
