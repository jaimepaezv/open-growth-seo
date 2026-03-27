<?php
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'is_search' ) ) {
	function is_search(): bool {
		return false;
	}
}

final class CanonicalModuleSmokeTest extends TestCase {
	public function test_frontend_meta_has_canonical_audit_methods(): void {
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\SEO\\FrontendMeta', 'register_audit_checks' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\SEO\\FrontendMeta', 'audit_canonical_health' ) );
	}

	public function test_manual_canonical_sanitizer_accepts_relative_and_preserves_valid_query(): void {
		$instance = new \OpenGrowthSolutions\OpenGrowthSEO\SEO\FrontendMeta();
		$method   = new \ReflectionMethod( $instance, 'sanitize_canonical_url' );
		$method->setAccessible( true );

		$this->assertSame(
			'https://example.com/path/?ref=abc',
			$method->invoke( $instance, '/path/?ref=abc' )
		);
		$this->assertSame(
			'https://example.com/landing/?variant=1',
			$method->invoke( $instance, 'https://example.com/landing/?variant=1' )
		);
		$this->assertSame(
			'',
			$method->invoke( $instance, 'javascript:alert(1)' )
		);
	}

	public function test_contextual_normalization_filters_non_canonical_query_args(): void {
		$instance = new \OpenGrowthSolutions\OpenGrowthSEO\SEO\FrontendMeta();
		$method   = new \ReflectionMethod( $instance, 'normalize_url' );
		$method->setAccessible( true );

		$this->assertSame(
			'https://example.com/products/?paged=2',
			$method->invoke( $instance, 'https://example.com/products/?utm_source=x&paged=2', true )
		);
		$this->assertSame(
			'https://example.com/products/?utm_source=x&paged=2',
			$method->invoke( $instance, 'https://example.com/products/?utm_source=x&paged=2', false )
		);
	}
}
