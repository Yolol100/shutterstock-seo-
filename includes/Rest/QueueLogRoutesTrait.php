<?php
namespace Company\SeoShutterstockAssistant\Rest;

use Company\SeoShutterstockAssistant\Logs\Logger;
use Company\SeoShutterstockAssistant\Queue\QueueManager;
use Company\SeoShutterstockAssistant\Security\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait QueueLogRoutesTrait {
	public function queue(): WP_REST_Response {
		$items = $this->visible_queue_items();

		return new WP_REST_Response( array(
			'items'  => array_values( array_map( array( $this, 'prepare_queue_item_for_response' ), $items ) ),
			'counts' => $this->queue_counts_from_items( $items ),
		) );
	}


	private function visible_queue_items(): array {
		$items = array_values( array_filter( QueueManager::all(), 'is_array' ) );

		if ( current_user_can( Capabilities::MANAGE ) ) {
			return $items;
		}

		return array_values( array_filter( $items, static function ( array $item ): bool {
			$post_id = absint( $item['post_id'] ?? 0 );
			return $post_id && current_user_can( 'edit_post', $post_id );
		} ) );
	}

	private function prepare_queue_item_for_response( array $item ): array {
		$clean = array(
			'post_id'    => absint( $item['post_id'] ?? 0 ),
			'status'     => sanitize_key( (string) ( $item['status'] ?? 'pending' ) ),
			'attempts'   => absint( $item['attempts'] ?? 0 ),
			'updated_at' => sanitize_text_field( (string) ( $item['updated_at'] ?? '' ) ),
		);

		if ( isset( $item['message'] ) ) {
			$clean['message'] = sanitize_text_field( (string) $item['message'] );
		}

		if ( isset( $item['retry_at'] ) ) {
			$clean['retry_at'] = absint( $item['retry_at'] );
		}

		if ( isset( $item['recovery'] ) ) {
			$clean['recovery'] = sanitize_key( (string) $item['recovery'] );
		}

		return $clean;
	}

	private function queue_counts_from_items( array $items ): array {
		$counts = array_fill_keys( QueueManager::STATUSES, 0 );
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$status = sanitize_key( (string) ( $item['status'] ?? 'pending' ) );
			if ( isset( $counts[ $status ] ) ) {
				++$counts[ $status ];
			}
		}
		return $counts;
	}

	private function queue_status_from_counts( array $queue ): string {
		if ( ! empty( $queue['failed'] ) || ! empty( $queue['imported_needs_acf'] ) ) {
			return 'attention_needed';
		}
		if ( ! empty( $queue['retrying'] ) ) {
			return 'retrying';
		}
		if ( ! empty( $queue['pending'] ) ) {
			return 'pending';
		}
		return 'idle';
	}

	public function enqueue( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $request->get_param( 'post_ids' ) ) ) ) );
			$post_ids = array_slice( $post_ids, 0, 50 );
		if ( empty( $post_ids ) ) {
			$post_ids = array_filter( array( absint( $request->get_param( 'post_id' ) ) ) );
		}
		if ( empty( $post_ids ) ) {
			return new WP_Error( 'ssia_missing_queue_posts', __( 'Select one or more pages before adding them to the queue.', 'seo-shutterstock-image-assistant' ), array( 'status' => 400 ) );
		}
		foreach ( $post_ids as $post_id ) {
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return new WP_Error( 'ssia_forbidden', __( 'You cannot edit one or more selected pages.', 'seo-shutterstock-image-assistant' ), array( 'status' => 403 ) );
			}
		}
		$items = ( new QueueManager() )->enqueue_many( $post_ids );
		return new WP_REST_Response( array( 'items' => $items, 'message' => __( 'Pages added to the queue.', 'seo-shutterstock-image-assistant' ) ) );
	}
	public function logs(): WP_REST_Response {
		return new WP_REST_Response( array( 'items' => Logger::latest( 50 ) ) );
	}
}