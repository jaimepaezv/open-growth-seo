<?php
use PHPUnit\Framework\TestCase;

final class DefaultsTest extends TestCase {
	public function test_default_mode_exists(): void {
		$this->assertTrue( class_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Core\\Defaults' ) );
		$defaults = \OpenGrowthSolutions\OpenGrowthSEO\Core\Defaults::settings();
		$this->assertArrayHasKey( 'mode', $defaults );
		$this->assertArrayHasKey( 'seo_masters_plus_enabled', $defaults );
		$this->assertArrayHasKey( 'local_locations', $defaults );
		$this->assertArrayHasKey( 'woo_filter_canonical_target', $defaults );
		$this->assertArrayHasKey( 'woo_archive_schema_mode', $defaults );
	}
}
