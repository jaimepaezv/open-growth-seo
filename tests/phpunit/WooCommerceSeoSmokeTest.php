<?php
use PHPUnit\Framework\TestCase;

final class WooCommerceSeoSmokeTest extends TestCase {
	public function test_woocommerce_defaults_exist(): void {
		$defaults = \OpenGrowthSolutions\OpenGrowthSEO\Core\Defaults::settings();
		$this->assertArrayHasKey( 'woo_schema_mode', $defaults );
		$this->assertArrayHasKey( 'woo_product_archive', $defaults );
		$this->assertArrayHasKey( 'woo_product_cat_archive', $defaults );
		$this->assertArrayHasKey( 'woo_product_tag_archive', $defaults );
		$this->assertArrayHasKey( 'woo_min_product_description_words', $defaults );
	}

	public function test_woocommerce_related_methods_exist(): void {
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Schema\\SchemaManager', 'product_node' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\SEO\\FrontendMeta', 'default_robots_for_context' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Admin\\SetupWizard', 'detect_site_type' ) );
	}
}
