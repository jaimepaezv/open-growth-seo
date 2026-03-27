<?php
use OpenGrowthSolutions\OpenGrowthSEO\Support\SiteHealth;
use PHPUnit\Framework\TestCase;

final class SupportSiteHealthTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['ogs_test_options'] = array();
		$GLOBALS['ogs_test_filters'] = array();
		$GLOBALS['ogs_test_actions'] = array();
	}

	public function test_site_health_registers_tests_and_debug_information(): void {
		$service = new SiteHealth();
		$tests   = $service->register_tests( array( 'direct' => array() ) );
		$info    = $service->register_debug_information( array() );

		$this->assertArrayHasKey( 'ogs_seo_support', $tests['direct'] );
		$this->assertArrayHasKey( 'open_growth_seo', $info );
		$this->assertArrayHasKey( 'fields', $info['open_growth_seo'] );
		$this->assertArrayHasKey( 'support_status', $info['open_growth_seo']['fields'] );
	}

	public function test_site_health_status_test_returns_expected_shape(): void {
		$result = ( new SiteHealth() )->support_status_test();

		$this->assertArrayHasKey( 'label', $result );
		$this->assertArrayHasKey( 'status', $result );
		$this->assertArrayHasKey( 'description', $result );
		$this->assertArrayHasKey( 'actions', $result );
		$this->assertContains( $result['status'], array( 'good', 'recommended', 'critical' ) );
	}
}
