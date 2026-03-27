<?php
use PHPUnit\Framework\TestCase;

final class SitemapsSmokeTest extends TestCase {
	public function test_sitemaps_class_exists(): void {
		$this->assertTrue( class_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\SEO\\Sitemaps' ) );
	}
}
