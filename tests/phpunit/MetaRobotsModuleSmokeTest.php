<?php
use PHPUnit\Framework\TestCase;

final class MetaRobotsModuleSmokeTest extends TestCase {
	public function test_frontend_meta_has_advanced_controls_methods(): void {
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\SEO\\FrontendMeta', 'filter_wp_robots' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\SEO\\FrontendMeta', 'send_x_robots' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\SEO\\FrontendMeta', 'apply_data_nosnippet' ) );
	}

	public function test_frontend_meta_normalizes_conflicting_base_robots_pair_safely(): void {
		$instance = new \OpenGrowthSolutions\OpenGrowthSEO\SEO\FrontendMeta();
		$method   = new \ReflectionMethod( $instance, 'normalized_robots_pair' );
		$method->setAccessible( true );

		$conflicting = $method->invoke( $instance, 'index,follow,noindex,nofollow,index,follow' );
		$this->assertSame(
			array(
				'index'  => 'noindex',
				'follow' => 'nofollow',
			),
			$conflicting
		);

		$defaulted = $method->invoke( $instance, 'unexpected,value' );
		$this->assertSame(
			array(
				'index'  => 'index',
				'follow' => 'follow',
			),
			$defaulted
		);
	}

	public function test_preview_and_unavailable_after_sanitizers_are_strict(): void {
		$instance = new \OpenGrowthSolutions\OpenGrowthSEO\SEO\FrontendMeta();
		$numeric  = new \ReflectionMethod( $instance, 'sanitize_numeric_preview' );
		$numeric->setAccessible( true );
		$image = new \ReflectionMethod( $instance, 'sanitize_image_preview' );
		$image->setAccessible( true );
		$unavailable = new \ReflectionMethod( $instance, 'sanitize_unavailable_after' );
		$unavailable->setAccessible( true );

		$this->assertSame( '-1', $numeric->invoke( $instance, '-1' ) );
		$this->assertSame( '160', $numeric->invoke( $instance, '160' ) );
		$this->assertSame( '', $numeric->invoke( $instance, 'abc' ) );
		$this->assertSame( 'large', $image->invoke( $instance, 'large' ) );
		$this->assertSame( '', $image->invoke( $instance, 'xlarge' ) );
		$this->assertSame( '', $unavailable->invoke( $instance, 'not-a-date' ) );
		$this->assertSame( '01 Jan 2030 00:00:00 GMT', $unavailable->invoke( $instance, '2030-01-01 00:00:00 UTC' ) );
	}
}
