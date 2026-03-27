<?php
use PHPUnit\Framework\TestCase;

final class HreflangModuleSmokeTest extends TestCase {
	public function test_hreflang_class_exists(): void {
		$this->assertTrue( class_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\SEO\\Hreflang' ) );
	}

	public function test_hreflang_has_status_method(): void {
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\SEO\\Hreflang', 'status' ) );
	}

	public function test_hreflang_normalize_code_and_manual_x_default_split(): void {
		$instance = new \OpenGrowthSolutions\OpenGrowthSEO\SEO\Hreflang();

		$normalize = new \ReflectionMethod( $instance, 'normalize_static_code' );
		$normalize->setAccessible( true );
		$this->assertSame( 'en-US', $normalize->invoke( null, 'en_us' ) );
		$this->assertSame( 'es-419', $normalize->invoke( null, 'es-419' ) );
		$this->assertSame( 'x-default', $normalize->invoke( null, 'X-DEFAULT' ) );
		$this->assertSame( '', $normalize->invoke( null, 'english' ) );

		$split = new \ReflectionMethod( $instance, 'split_x_default_from_alternates' );
		$split->setAccessible( true );
		$result = $split->invoke(
			$instance,
			array(
				'en-US'     => 'https://example.com/en/',
				'es-ES'     => 'https://example.com/es/',
				'x-default' => 'https://example.com/',
			)
		);
		$this->assertSame( 'https://example.com/', $result['x_default'] );
		$this->assertArrayHasKey( 'en-US', $result['alternates'] );
		$this->assertArrayHasKey( 'es-ES', $result['alternates'] );
		$this->assertArrayNotHasKey( 'x-default', $result['alternates'] );
	}

	public function test_hreflang_validation_reports_invalid_manual_lines_and_invalid_alternates(): void {
		$instance = new \OpenGrowthSolutions\OpenGrowthSEO\SEO\Hreflang();

		$manual_errors = new \ReflectionMethod( $instance, 'manual_map_errors' );
		$manual_errors->setAccessible( true );
		$errors = $manual_errors->invoke( $instance, "es|https://example.com/es/\ninvalid-line\nxxxy|notaurl" );
		$this->assertNotEmpty( $errors );

		$validate = new \ReflectionMethod( $instance, 'validate_alternates' );
		$validate->setAccessible( true );
		$validation = $validate->invoke(
			$instance,
			array(
				'english' => 'https://example.com/en/',
				'es-ES'   => 'notaurl',
			)
		);
		$this->assertArrayHasKey( 'errors', $validation );
		$this->assertGreaterThanOrEqual( 2, count( $validation['errors'] ) );
	}
}
