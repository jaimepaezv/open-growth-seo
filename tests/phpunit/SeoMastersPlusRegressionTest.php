<?php
use PHPUnit\Framework\TestCase;
use OpenGrowthSolutions\OpenGrowthSEO\Support\SeoMastersPlus;
use OpenGrowthSolutions\OpenGrowthSEO\Support\ExperienceMode;
use OpenGrowthSolutions\OpenGrowthSEO\Support\Settings;

final class SeoMastersPlusRegressionTest extends TestCase {
	public function test_seo_masters_plus_service_exists_and_exposes_feature_map(): void {
		$this->assertTrue( class_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Support\\SeoMastersPlus' ) );
		$map = SeoMastersPlus::feature_map();
		$this->assertIsArray( $map );
		$this->assertArrayHasKey( 'seo_masters_plus_link_graph', $map );
		$this->assertArrayHasKey( 'seo_masters_plus_gsc_trends', $map );
	}

	public function test_settings_sanitize_handles_seo_masters_plus_toggles(): void {
		$sanitized = Settings::sanitize(
			array(
				'mode'                             => 'advanced',
				'seo_masters_plus_enabled'         => '1',
				'seo_masters_plus_link_graph'      => 'yes',
				'seo_masters_plus_redirect_ops'    => '1',
				'seo_masters_plus_canonical_policy' => '0',
				'seo_masters_plus_schema_guardrails' => '1',
				'seo_masters_plus_gsc_trends'      => 'true',
			)
		);

		$this->assertSame( 'advanced', $sanitized['mode'] );
		$this->assertSame( 1, $sanitized['seo_masters_plus_enabled'] );
		$this->assertSame( 1, $sanitized['seo_masters_plus_link_graph'] );
		$this->assertSame( 1, $sanitized['seo_masters_plus_redirect_ops'] );
		$this->assertSame( 0, $sanitized['seo_masters_plus_canonical_policy'] );
		$this->assertSame( 1, $sanitized['seo_masters_plus_schema_guardrails'] );
		$this->assertSame( 1, $sanitized['seo_masters_plus_gsc_trends'] );
	}

	public function test_editor_defaults_include_seo_masters_plus_section(): void {
		$simple_defaults = ExperienceMode::editor_section_defaults(
			array(
				'mode'                     => 'simple',
				'seo_masters_plus_enabled' => 0,
			)
		);
		$advanced_defaults = ExperienceMode::editor_section_defaults(
			array(
				'mode'                     => 'advanced',
				'seo_masters_plus_enabled' => 1,
			)
		);

		$this->assertArrayHasKey( 'masters_plus', $simple_defaults );
		$this->assertFalse( (bool) $simple_defaults['masters_plus'] );
		$this->assertTrue( (bool) $advanced_defaults['masters_plus'] );
	}
}
