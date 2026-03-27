<?php
declare(strict_types=1);

use OpenGrowthSolutions\OpenGrowthSEO\Compatibility\ConflictManager;
use OpenGrowthSolutions\OpenGrowthSEO\Compatibility\Detector;
use PHPUnit\Framework\TestCase;

final class CompatibilityConflictManagerTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['ogs_test_options']           = array(
			'active_plugins' => array( 'wordpress-seo/wp-seo.php' ),
		);
		$GLOBALS['ogs_test_site_options']      = array();
		$GLOBALS['ogs_test_is_admin']          = true;
		$GLOBALS['ogs_test_is_multisite']      = false;
		$GLOBALS['ogs_test_current_screen_id'] = 'open-growth-seo_page_ogs-seo-tools';
		$GLOBALS['ogs_test_current_user_caps'] = array( 'manage_options' => true );
		Detector::reset_cache();
	}

	public function test_notice_renders_actionable_conflict_guidance_on_plugin_screens(): void {
		$manager = new ConflictManager();

		ob_start();
		$manager->notice();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Compatibility attention needed', $output );
		$this->assertStringContainsString( 'Yoast SEO', $output );
		$this->assertStringContainsString( 'Review Settings', $output );
		$this->assertStringContainsString( 'Open Compatibility Tools', $output );
	}

	public function test_notice_does_not_render_on_unrelated_screens(): void {
		$GLOBALS['ogs_test_current_screen_id'] = 'dashboard';
		Detector::reset_cache();
		$manager = new ConflictManager();

		ob_start();
		$manager->notice();
		$output = (string) ob_get_clean();

		$this->assertSame( '', $output );
	}
}
