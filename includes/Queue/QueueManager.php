<?php
namespace Company\SeoShutterstockAssistant\Queue;

use Company\SeoShutterstockAssistant\Logs\Logger;
use Company\SeoShutterstockAssistant\SEO\QueryBuilder;
use Company\SeoShutterstockAssistant\Shutterstock\SearchService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class QueueManager {
	public const OPTION   = 'ssia_queue';
	public const STATUSES = array( 'pending', 'retrying', 'suggestions_found', 'selected', 'licensed', 'imported_needs_acf', 'attached', 'featured_from_acf', 'failed', 'skipped' );
	private const PRUNE_AFTER_DAYS = 90;
	private const PROCESSING_LOCK_TTL = 10 * MINUTE_IN_SECONDS;
	private const MAX_QUEUE_ITEMS = 1000;

	public function register(): void {
		add_action( 'ssia_process_page', array( $this, 'process_page_action' ), 10, 1 );
		add_action( 'ssia_process_queue', array( $this, 'process_page_action' ), 10, 1 );
	}

	public function enqueue( int $post_id ): array {
		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return array();
		}

		$queue              = self::all();
		$queue[ $post_id ]  = array(
			'post_id'     => $post_id,
			'status'      => 'pending',
			'attempts'    => 0,
			'updated_at'  => current_time( 'mysql' ),
			'suggestions' => array(),
			'selected'    => array(),
		);
		self::save( $queue );

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			if ( ! function_exists( 'as_has_scheduled_action' ) || ! as_has_scheduled_action( 'ssia_process_page', array( 'post_id' => $post_id ), 'ssia' ) ) {
				as_enqueue_async_action( 'ssia_process_page', array( 'post_id' => $post_id ), 'ssia' );
			}
		} elseif ( ! wp_next_scheduled( 'ssia_process_queue', array( $post_id ) ) ) {
			wp_schedule_single_event( time() + 60, 'ssia_process_queue', array( $post_id ) );
		}

		( new Logger() )->info( 'queued', array( 'post_id' => $post_id ) );
		return $queue[ $post_id ];
	}

	public function enqueue_many( array $post_ids ): array {
		$items = array();
		foreach ( array_slice( array_values( array_unique( array_map( 'absint', $post_ids ) ) ), 0, 50 ) as $post_id ) {
			if ( $post_id ) {
				$items[] = $this->enqueue( $post_id );
			}
		}
		return $items;
	}

	public function process_page_action( mixed $payload ): void {
		$post_id = is_array( $payload ) ? absint( $payload['post_id'] ?? 0 ) : absint( $payload );
		if ( ! $post_id ) {
			return;
		}

		if ( ! self::acquire_processing_lock( $post_id ) ) {
			( new Logger() )->info( 'queue_processing_skipped_locked', array( 'post_id' => $post_id ) );
			return;
		}

		try {
			$queue = self::all();
			$item  = $queue[ $post_id ] ?? array( 'post_id' => $post_id, 'attempts' => 0 );
			$item  = is_array( $item ) ? $item : array( 'post_id' => $post_id, 'attempts' => 0 );
			$item['status'] = 'pending';

			try {
				$queries     = ( new QueryBuilder() )->build_queries( $post_id );
				$suggestions = ( new SearchService() )->search_many( $queries );
				$item['attempts']    = absint( $item['attempts'] ?? 0 ) + 1;
				$item['updated_at']  = current_time( 'mysql' );
				$item['suggestions'] = wp_list_pluck( array_slice( $suggestions, 0, 12 ), 'id' );

				if ( empty( $suggestions ) ) {
					$item = $this->mark_retry_or_failed( $item, __( 'No suggestions found. The queue will retry automatically if attempts remain.', 'seo-shutterstock-image-assistant' ) );
				} else {
					$item['status']   = 'suggestions_found';
					$item['message']  = __( 'Suggestions ready.', 'seo-shutterstock-image-assistant' );
					$item['retry_at'] = 0;
				}
				( new Logger() )->info( 'queue_processed', array( 'post_id' => $post_id, 'status' => $item['status'], 'attempts' => $item['attempts'] ) );
			} catch ( \Throwable $throwable ) {
				$item['attempts']   = absint( $item['attempts'] ?? 0 ) + 1;
				$item['updated_at'] = current_time( 'mysql' );
				$item              = $this->mark_retry_or_failed( $item, $throwable->getMessage() );
				( new Logger() )->error( 'queue_failed', array( 'post_id' => $post_id, 'message' => $throwable->getMessage(), 'status' => $item['status'], 'attempts' => $item['attempts'] ) );
			}

			$queue[ $post_id ] = $item;
			self::save( $queue );
		} finally {
			self::release_processing_lock( $post_id );
		}
	}

	private function mark_retry_or_failed( array $item, string $message ): array {
		$attempts = absint( $item['attempts'] ?? 0 );
		$post_id  = absint( $item['post_id'] ?? 0 );
		if ( $attempts < 3 && $post_id ) {
			$delay             = min( HOUR_IN_SECONDS, 5 * MINUTE_IN_SECONDS * ( 2 ** max( 0, $attempts - 1 ) ) );
			$item['status']    = 'retrying';
			$item['message']   = sanitize_text_field( $message );
			$item['retry_at']  = time() + $delay;
			$this->schedule_retry( $post_id, $delay );
			( new Logger() )->info( 'queue_retry_scheduled', array( 'post_id' => $post_id, 'attempts' => $attempts, 'delay' => $delay ) );
			return $item;
		}

		$item['status']   = 'failed';
		$item['message']  = sanitize_text_field( $message );
		$item['retry_at'] = 0;
		return $item;
	}

	private function schedule_retry( int $post_id, int $delay ): void {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			if ( ! function_exists( 'as_has_scheduled_action' ) || ! as_has_scheduled_action( 'ssia_process_page', array( 'post_id' => $post_id ), 'ssia' ) ) {
				as_schedule_single_action( time() + $delay, 'ssia_process_page', array( 'post_id' => $post_id ), 'ssia' );
			}
			return;
		}
		if ( ! wp_next_scheduled( 'ssia_process_queue', array( $post_id ) ) ) {
			wp_schedule_single_event( time() + $delay, 'ssia_process_queue', array( $post_id ) );
		}
	}


	private static function processing_lock_key( int $post_id ): string {
		return 'ssia_queue_lock_' . absint( $post_id );
	}

	private static function acquire_processing_lock( int $post_id ): bool {
		$lock_key   = self::processing_lock_key( $post_id );
		$expires_at = absint( get_option( $lock_key, 0 ) );
		if ( $expires_at > time() ) {
			return false;
		}
		if ( $expires_at ) {
			delete_option( $lock_key );
		}
		return add_option( $lock_key, time() + self::PROCESSING_LOCK_TTL, '', false );
	}

	private static function release_processing_lock( int $post_id ): void {
		delete_option( self::processing_lock_key( $post_id ) );
	}

	public static function clear_scheduled_events(): void {
		wp_clear_scheduled_hook( 'ssia_process_queue' );
		wp_clear_scheduled_hook( 'ssia_sync_used_ids_batch' );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'ssia_process_page', null, 'ssia' );
		}
	}

	public static function clear_runtime_locks(): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				'ssia_queue_lock_%'
			)
		);
	}

	public static function all(): array {
		$queue = get_option( self::OPTION, array() );
		return is_array( $queue ) ? $queue : array();
	}

	public static function save( array $queue ): void {
		update_option( self::OPTION, self::prune( $queue ), false );
	}

	private static function prune( array $queue ): array {
		$cutoff = time() - ( self::PRUNE_AFTER_DAYS * DAY_IN_SECONDS );
		foreach ( $queue as $post_id => $item ) {
			if ( ! is_array( $item ) ) {
				unset( $queue[ $post_id ] );
				continue;
			}
			$status = sanitize_key( (string) ( $item['status'] ?? '' ) );
			if ( ! in_array( $status, array( 'attached', 'failed', 'skipped' ), true ) ) {
				continue;
			}
			$updated_at = strtotime( (string) ( $item['updated_at'] ?? '' ) );
			if ( $updated_at && $updated_at < $cutoff ) {
				unset( $queue[ $post_id ] );
			}
		}

		// Hard cap: keep the most-recently-updated items if the queue still exceeds the maximum.
		if ( count( $queue ) > self::MAX_QUEUE_ITEMS ) {
			uasort(
				$queue,
				static function ( $a, $b ): int {
					$a_time = is_array( $a ) ? (int) strtotime( (string) ( $a['updated_at'] ?? '' ) ) : 0;
					$b_time = is_array( $b ) ? (int) strtotime( (string) ( $b['updated_at'] ?? '' ) ) : 0;
					return $b_time <=> $a_time;
				}
			);
			$queue = array_slice( $queue, 0, self::MAX_QUEUE_ITEMS, true );
		}

		return $queue;
	}

	public static function get_item( int $post_id ): array {
		$post_id = absint( $post_id );
		$queue   = self::all();
		$item    = $queue[ $post_id ] ?? array();
		return is_array( $item ) ? $item : array();
	}

	public static function update_status( int $post_id, string $status, array $extra = array() ): void {
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return;
		}
		$queue             = self::all();
		$current           = $queue[ $post_id ] ?? array( 'post_id' => $post_id );
		$current           = is_array( $current ) ? $current : array( 'post_id' => $post_id );
		$current['status'] = $status;
		$current['updated_at'] = current_time( 'mysql' );
		$queue[ $post_id ] = array_merge( $current, self::sanitize_extra( $extra ) );
		self::save( $queue );
	}


	private static function sanitize_extra( array $extra ): array {
		$clean = array();
		foreach ( $extra as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key || self::is_sensitive_extra_key( $key ) ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$clean[ $key ] = self::sanitize_extra_array( $value );
			} elseif ( is_scalar( $value ) || null === $value ) {
				$clean[ $key ] = sanitize_text_field( (string) $value );
			}
		}
		return $clean;
	}

	private static function sanitize_extra_array( array $values ): array {
		$clean = array();
		foreach ( $values as $key => $value ) {
			$clean_key = is_string( $key ) ? sanitize_key( $key ) : $key;
			if ( is_string( $clean_key ) && ( '' === $clean_key || self::is_sensitive_extra_key( $clean_key ) ) ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$clean[ $clean_key ] = self::sanitize_extra_array( $value );
			} elseif ( is_scalar( $value ) || null === $value ) {
				$clean[ $clean_key ] = sanitize_text_field( (string) $value );
			}
		}
		return $clean;
	}

	private static function is_sensitive_extra_key( string $key ): bool {
		return in_array( $key, array( 'download_url', 'access_token', 'refresh_token', 'oauth_client_secret', 'client_secret', 'authorization' ), true );
	}

	public static function counts(): array {
		$counts = array_fill_keys( self::STATUSES, 0 );
		foreach ( self::all() as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$status = sanitize_key( $item['status'] ?? 'pending' );
			if ( isset( $counts[ $status ] ) ) {
				++$counts[ $status ];
			}
		}
		return $counts;
	}
}
