<?php
use PHPUnit\Framework\TestCase;
use OpenGrowthSolutions\OpenGrowthSEO\Integrations\Manager;
use OpenGrowthSolutions\OpenGrowthSEO\Integrations\GoogleSearchConsole;
use OpenGrowthSolutions\OpenGrowthSEO\Integrations\BingWebmaster;
use OpenGrowthSolutions\OpenGrowthSEO\Integrations\GA4Reporting;

final class IntegrationsModuleSmokeTest extends TestCase {
	public function test_integrations_manager_exists_and_exposes_payload(): void {
		$this->assertTrue( class_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Integrations\\Manager' ) );
		$payload = Manager::get_status_payload();
		$this->assertIsArray( $payload );
		$this->assertArrayHasKey( 'summary', $payload );
		$this->assertArrayHasKey( 'items', $payload );
		$this->assertArrayHasKey( 'attention', $payload['summary'] );
	}

	public function test_integrations_payload_contains_required_services(): void {
		$payload = Manager::get_status_payload();
		$items   = isset( $payload['items'] ) && is_array( $payload['items'] ) ? $payload['items'] : array();
		$slugs   = array_map(
			static fn( $item ) => is_array( $item ) && isset( $item['slug'] ) ? (string) $item['slug'] : '',
			$items
		);
		$this->assertContains( 'google_search_console', $slugs );
		$this->assertContains( 'bing_webmaster', $slugs );
		$this->assertContains( 'ga4_reporting', $slugs );
		$this->assertArrayHasKey( 'state_label', $items[0] );
		$this->assertArrayHasKey( 'secret_summary', $items[0] );
	}

	public function test_integration_services_fail_safely_when_credentials_missing(): void {
		$gsc  = new GoogleSearchConsole();
		$bing = new BingWebmaster();
		$ga4  = new GA4Reporting();

		$gsc_result = $gsc->test_connection( array(), array() );
		$this->assertFalse( (bool) $gsc_result['ok'] );
		$this->assertStringContainsString( 'Property URL', (string) $gsc_result['message'] );

		$bing_result = $bing->test_connection( array(), array() );
		$this->assertFalse( (bool) $bing_result['ok'] );
		$this->assertStringContainsString( 'Site URL', (string) $bing_result['message'] );

		$ga4_result = $ga4->test_connection( array(), array() );
		$this->assertFalse( (bool) $ga4_result['ok'] );
		$this->assertStringContainsString( 'measurement ID', (string) $ga4_result['message'] );
	}

	public function test_google_search_console_operational_report_has_safe_fallback_states(): void {
		$service = new GoogleSearchConsole();
		$disabled = $service->operational_report(
			array( 'gsc_enabled' => 0 ),
			array(),
			array(),
			false
		);
		$this->assertFalse( (bool) $disabled['ok'] );
		$this->assertSame( 'disabled', (string) $disabled['status'] );

		$missing = $service->operational_report(
			array(
				'gsc_enabled'      => 1,
				'gsc_property_url' => '',
			),
			array(),
			array(),
			false
		);
		$this->assertFalse( (bool) $missing['ok'] );
		$this->assertSame( 'missing_configuration', (string) $missing['status'] );
		$this->assertArrayHasKey( 'next_actions', $missing );
	}

	public function test_manager_source_exposes_gsc_operational_report_api(): void {
		$source = file_get_contents( __DIR__ . '/../../includes/Integrations/Manager.php' );
		$this->assertIsString( $source );
		$this->assertStringContainsString( 'google_search_console_operational_report', $source );
	}

	public function test_google_search_console_source_contains_trend_snapshot_support(): void {
		$source = file_get_contents( __DIR__ . '/../../includes/Integrations/GoogleSearchConsole.php' );
		$this->assertIsString( $source );
		$this->assertStringContainsString( 'query_window', $source );
		$this->assertStringContainsString( 'issue_trends', $source );
		$this->assertStringContainsString( 'percent_delta', $source );
		$this->assertStringContainsString( 'seo_masters_plus_gsc_trends', $source );
	}

	public function test_google_search_console_window_deltas_and_action_queue_helpers(): void {
		$service = new GoogleSearchConsole();
		$window_method = new ReflectionMethod( $service, 'build_window_deltas' );
		$window_method->setAccessible( true );

		$window_deltas = $window_method->invoke(
			$service,
			array(
				array(
					'time'         => 200,
					'window_start' => '2026-02-22',
					'window_end'   => '2026-03-20',
					'totals'       => array(
						'clicks'      => 200,
						'impressions' => 2000,
					),
				),
				array(
					'time'         => 100,
					'window_start' => '2026-01-25',
					'window_end'   => '2026-02-21',
					'totals'       => array(
						'clicks'      => 150,
						'impressions' => 1800,
					),
				),
			)
		);

		$this->assertTrue( (bool) ( $window_deltas['ready'] ?? false ) );
		$this->assertSame( '2026-02-22', (string) ( $window_deltas['current_window']['start'] ?? '' ) );
		$this->assertArrayHasKey( 'clicks_delta_pct', $window_deltas );

		$group_method = new ReflectionMethod( $service, 'group_pages_for_ops' );
		$group_method->setAccessible( true );
		$grouped = $group_method->invoke(
			$service,
			array(
				array(
					'page'        => 'https://example.com/a/',
					'impressions' => 220,
					'ctr'         => 1.2,
					'position'    => 12,
				),
				array(
					'page'        => 'https://example.com/b/',
					'impressions' => 130,
					'ctr'         => 2.1,
					'position'    => 28,
				),
			)
		);
		$this->assertNotEmpty( $grouped['low_ctr_high_impression'] );
		$this->assertNotEmpty( $grouped['weak_position'] );

		$queue_method = new ReflectionMethod( $service, 'build_action_queue' );
		$queue_method->setAccessible( true );
		$queue = $queue_method->invoke(
			$service,
			array(
				array(
					'page'        => 'https://example.com/a/',
					'impressions' => 220,
					'ctr'         => 1.2,
					'position'    => 12,
				),
				array(
					'page'        => 'https://example.com/b/',
					'impressions' => 130,
					'ctr'         => 2.1,
					'position'    => 28,
				),
			),
			array(
				'low_ctr_opportunities' => 4,
			),
			array(
				'impressions_delta_pct' => -14.0,
			)
		);

		$this->assertNotEmpty( $queue );
		$this->assertGreaterThanOrEqual( 80, (int) ( $queue[0]['priority'] ?? 0 ) );
	}
}
