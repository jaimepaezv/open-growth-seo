<?php
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'get_post_type_object' ) ) {
	function get_post_type_object( string $post_type ) {
		$post_type = sanitize_key( $post_type );
		if ( in_array( $post_type, array( 'post', 'page', 'product' ), true ) ) {
			return (object) array(
				'name'   => $post_type,
				'public' => true,
			);
		}
		return null;
	}
}

if ( ! function_exists( 'get_post' ) ) {
	function get_post( int $post_id ) {
		if ( $post_id <= 0 ) {
			return null;
		}
		return (object) array(
			'ID'                => $post_id,
			'post_status'       => 'publish',
			'post_type'         => 'post',
			'post_modified_gmt' => gmdate( 'Y-m-d H:i:s' ),
		);
	}
}

final class SitemapsModuleRegressionTest extends TestCase {
	public function test_sitemaps_supports_inspection_and_audit_methods(): void {
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\SEO\\Sitemaps', 'inspect' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\SEO\\Sitemaps', 'audit_runtime_consistency' ) );
	}

	public function test_sitemap_page_map_method_exists_and_returns_array(): void {
		$instance = new \OpenGrowthSolutions\OpenGrowthSEO\SEO\Sitemaps();
		$this->assertTrue( method_exists( $instance, 'page_map_for_post_type' ) );

		$map_method = new \ReflectionMethod( $instance, 'page_map_for_post_type' );
		$map_method->setAccessible( true );
		$map = $map_method->invoke( $instance, 'post' );

		$this->assertIsArray( $map );
		$this->assertSame( array(), $map );
	}

	public function test_total_pages_uses_page_map_count(): void {
		$instance = new \OpenGrowthSolutions\OpenGrowthSEO\SEO\Sitemaps();
		$method   = new \ReflectionMethod( $instance, 'total_pages_for_post_type' );
		$method->setAccessible( true );
		$this->assertSame( 0, $method->invoke( $instance, 'post' ) );
	}

	public function test_page_lastmod_and_total_urls_return_safe_empty_defaults(): void {
		$instance = new \OpenGrowthSolutions\OpenGrowthSEO\SEO\Sitemaps();
		$lastmod  = new \ReflectionMethod( $instance, 'page_lastmod' );
		$lastmod->setAccessible( true );
		$total = new \ReflectionMethod( $instance, 'total_urls_for_post_type' );
		$total->setAccessible( true );

		$this->assertSame( '', $lastmod->invoke( $instance, 'post', 1 ) );
		$this->assertSame( 0, $total->invoke( $instance, 'post' ) );
	}

	public function test_meta_change_purge_handler_accepts_non_scalar_meta_id_payloads(): void {
		$method = new \ReflectionMethod( '\\OpenGrowthSolutions\\OpenGrowthSEO\\SEO\\Sitemaps', 'maybe_purge_cache_on_meta_change' );
		$params = $method->getParameters();
		$this->assertCount( 4, $params );
		$this->assertFalse( $params[0]->hasType(), 'First param must accept array payloads from deleted_post_meta hooks.' );
	}
}
