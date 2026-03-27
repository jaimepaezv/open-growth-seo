<?php
use OpenGrowthSolutions\OpenGrowthSEO\Support\Settings;
use PHPUnit\Framework\TestCase;

final class BreadcrumbsModuleSmokeTest extends TestCase {
	public function test_breadcrumbs_class_exists(): void {
		$this->assertTrue( class_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\SEO\\Breadcrumbs' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\SEO\\Breadcrumbs', 'trail_items' ) );
	}

	public function test_settings_sanitize_breadcrumb_controls(): void {
		$sanitized = Settings::sanitize(
			array(
				'breadcrumbs_enabled'         => '1',
				'breadcrumbs_home_label'      => '<strong>Start</strong>',
				'breadcrumbs_separator'       => ' >>>>>> ',
				'breadcrumbs_include_current' => '0',
			)
		);

		$this->assertSame( 1, $sanitized['breadcrumbs_enabled'] );
		$this->assertSame( 'Start', $sanitized['breadcrumbs_home_label'] );
		$this->assertSame( '>>>>>', $sanitized['breadcrumbs_separator'] );
		$this->assertSame( 0, $sanitized['breadcrumbs_include_current'] );
	}

	public function test_breadcrumbs_are_wired_into_plugin_schema_and_admin(): void {
		$plugin_source = file_get_contents( __DIR__ . '/../../includes/Core/Plugin.php' );
		$schema_source = file_get_contents( __DIR__ . '/../../includes/Schema/SchemaManager.php' );
		$admin_source  = file_get_contents( __DIR__ . '/../../includes/Admin/SearchAppearancePage.php' );
		$editor_source = file_get_contents( __DIR__ . '/../../includes/Admin/Editor.php' );

		$this->assertIsString( $plugin_source );
		$this->assertIsString( $schema_source );
		$this->assertIsString( $admin_source );
		$this->assertIsString( $editor_source );
		$this->assertStringContainsString( "'breadcrumbs'", $plugin_source );
		$this->assertStringContainsString( 'Breadcrumbs::class', $plugin_source );
		$this->assertStringContainsString( 'new ServiceRegistry', $plugin_source );
		$this->assertStringContainsString( 'Breadcrumbs::trail_items', $schema_source );
		$this->assertStringContainsString( '[ogs_seo_breadcrumbs]', $admin_source );
		$this->assertStringContainsString( 'breadcrumbs_home_label', $admin_source );
		$this->assertStringContainsString( 'breadcrumbs_separator', $admin_source );
		$this->assertStringContainsString( 'ogs_seo_primary_term', $editor_source );
		$this->assertStringContainsString( 'Primary topic', $editor_source );
	}
}
