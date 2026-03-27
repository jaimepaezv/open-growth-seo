<?php
use OpenGrowthSolutions\OpenGrowthSEO\Installation\Manager;
use OpenGrowthSolutions\OpenGrowthSEO\REST\Routes;
use PHPUnit\Framework\TestCase;

final class InstallationTelemetryRouteTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['ogs_test_options'] = array();
	}

	public function test_installation_telemetry_route_returns_machine_readable_history(): void {
		$manager = new Manager();
		$manager->rebuild_state( 'admin_rebuild', false );
		$manager->bootstrap( 'repair', false, false );

		$request  = new \WP_REST_Request(
			array(
				'limit'  => 12,
				'filter' => 'repairs',
				'group'  => 'source',
			)
		);
		$response = ( new Routes() )->installation_telemetry( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'repairs', $data['filter'] );
		$this->assertSame( 'source', $data['group'] );
		$this->assertArrayHasKey( 'summary', $data );
		$this->assertArrayHasKey( 'groups', $data );
		$this->assertIsArray( $data['entries'] );
	}
}
