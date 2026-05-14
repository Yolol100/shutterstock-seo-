<?php
namespace Company\SeoShutterstockAssistant\Admin;

use Company\SeoShutterstockAssistant\Logs\Logger;
use Company\SeoShutterstockAssistant\Queue\QueueManager;
use Company\SeoShutterstockAssistant\SEO\QueryBuilder;
use Company\SeoShutterstockAssistant\Security\QualityGate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Dashboard {
	public function stats(): array {
		$queue = QueueManager::counts();
		return array(
			'pages_missing_images' => ( new QueryBuilder() )->count_missing_pages(),
			'suggestions_ready'    => $queue['suggestions_found'] ?? 0,
			'licensed_this_month'  => $this->licensed_this_month(),
			'failed_actions'       => Logger::count_level( 'error' ) + ( $queue['failed'] ?? 0 ),
			'api_connected'        => $this->api_connected(),
			'acf_available'        => function_exists( 'update_field' ),
			'queue_status'         => $this->queue_status( $queue ),
			'queue_counts'         => $queue,
			'latest_logs'          => Logger::latest( 8 ),
			'quality_gate'         => ( new QualityGate() )->report(),
		);
	}

	private function api_connected(): bool {
		$settings = Settings::get();
		return ( ! empty( $settings['api_key'] ) && ! empty( $settings['api_secret'] ) ) || ! empty( $settings['access_token'] );
	}

	private function queue_status( array $queue ): string {
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

	private function licensed_this_month(): int {
		$logs  = Logger::latest( 500 );
		$month = gmdate( 'Y-m' );
		$count = 0;
		foreach ( $logs as $log ) {
			if ( 'license_success' === ( $log['action'] ?? '' ) && str_starts_with( (string) ( $log['time'] ?? '' ), $month ) ) {
				++$count;
			}
		}
		return $count;
	}
}
