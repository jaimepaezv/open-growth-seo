<?php
declare(strict_types=1);

use OpenGrowthSolutions\OpenGrowthSEO\Compatibility\Detector;
use OpenGrowthSolutions\OpenGrowthSEO\Compatibility\Importer;
use PHPUnit\Framework\TestCase;

final class CompatibilityModuleRegressionTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['ogs_test_options']            = array();
		$GLOBALS['ogs_test_site_options']       = array();
		$GLOBALS['ogs_test_is_multisite']       = false;
		$GLOBALS['ogs_test_is_admin']           = false;
		$GLOBALS['ogs_test_doing_ajax']         = false;
		$GLOBALS['ogs_test_is_json_request']    = false;
		$GLOBALS['ogs_test_ext_object_cache']   = false;
		$GLOBALS['ogs_test_current_user_caps']  = array( 'manage_options' => true );
		$GLOBALS['ogs_test_current_screen_id']  = '';
		Detector::reset_cache();
	}

	public function test_detector_exposes_supported_providers(): void {
		$providers = Detector::providers();
		$this->assertArrayHasKey( 'yoast', $providers );
		$this->assertArrayHasKey( 'rankmath', $providers );
		$this->assertArrayHasKey( 'aioseo', $providers );
	}

	public function test_detector_report_tracks_multisite_runtime_and_network_active_plugins(): void {
		$GLOBALS['ogs_test_is_multisite'] = true;
		$GLOBALS['ogs_test_options']['active_plugins'] = array();
		$GLOBALS['ogs_test_site_options']['active_sitewide_plugins'] = array(
			'wordpress-seo/wp-seo.php' => time(),
		);
		Detector::reset_cache();

		$report = Detector::report();

		$this->assertTrue( (bool) ( $report['runtime']['multisite'] ?? false ) );
		$this->assertTrue( (bool) ( $report['providers']['yoast']['active'] ?? false ) );
		$this->assertContains( 'yoast', (array) ( $report['active_slugs'] ?? array() ) );
	}

	public function test_importer_detect_returns_runtime_contexts_and_recommendations(): void {
		$GLOBALS['ogs_test_options']['active_plugins'] = array(
			'wordpress-seo/wp-seo.php',
			'litespeed-cache/litespeed-cache.php',
			'elementor/elementor.php',
		);
		$GLOBALS['ogs_test_ext_object_cache'] = true;
		Detector::reset_cache();

		$detected = ( new Importer() )->detect();

		$this->assertArrayHasKey( 'runtime', $detected );
		$this->assertArrayHasKey( 'contexts', $detected );
		$this->assertArrayHasKey( 'integrations', $detected );
		$this->assertArrayHasKey( 'recommendations', $detected );
		$this->assertTrue( (bool) ( $detected['integrations']['cache']['active'] ?? false ) );
		$this->assertTrue( (bool) ( $detected['integrations']['builders']['active'] ?? false ) );
		$this->assertNotEmpty( (array) ( $detected['recommendations'] ?? array() ) );
	}

	public function test_importer_resolve_target_slugs_allows_supported_inactive_provider(): void {
		$importer = new Importer();
		$method   = new ReflectionMethod( $importer, 'resolve_target_slugs' );
		$method->setAccessible( true );

		$result = $method->invoke( $importer, array( 'yoast', 'unknown-provider' ), array() );

		$this->assertSame( array( 'yoast' ), $result );
	}

	public function test_importer_warnings_include_inactive_provider_notice(): void {
		$importer = new Importer();
		$method   = new ReflectionMethod( $importer, 'build_warnings' );
		$method->setAccessible( true );

		$warnings = $method->invoke(
			$importer,
			array(
				'active_slugs'       => array(),
				'has_conflicts'      => false,
				'safe_mode_enabled'  => true,
			),
			array( 'yoast' ),
			array( 'count' => 0 ),
			array( 'total_source_rows' => 0 )
		);

		$this->assertIsArray( $warnings );
		$this->assertNotEmpty( $warnings );
		$this->assertStringContainsString( 'not currently active', implode( ' ', $warnings ) );
	}
}
