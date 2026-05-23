<?php
namespace Company\SeoShutterstockAssistant\Rest;

use Company\SeoShutterstockAssistant\Admin\Dashboard;
use Company\SeoShutterstockAssistant\Security\Capabilities;
use Company\SeoShutterstockAssistant\Security\QualityGate;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait OverviewRoutesTrait {
	public function dashboard(): WP_REST_Response {
		$stats = ( new Dashboard() )->stats();

		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			$queue_counts = $this->queue_counts_from_items( $this->visible_queue_items() );
			unset( $stats['latest_logs'], $stats['quality_gate'] );
			$stats['suggestions_ready'] = $queue_counts['suggestions_found'] ?? 0;
			$stats['failed_actions']    = $queue_counts['failed'] ?? 0;
			$stats['queue_counts']      = $queue_counts;
			$stats['queue_status']      = $this->queue_status_from_counts( $queue_counts );
		}

		return new WP_REST_Response( $stats );
	}

	public function quality_gate(): WP_REST_Response {
		return new WP_REST_Response( ( new QualityGate() )->report() );
	}
}