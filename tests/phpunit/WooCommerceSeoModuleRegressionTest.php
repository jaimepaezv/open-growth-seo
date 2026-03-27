<?php
declare(strict_types=1);

use OpenGrowthSolutions\OpenGrowthSEO\Audit\AuditManager;
use OpenGrowthSolutions\OpenGrowthSEO\Schema\SchemaManager;
use OpenGrowthSolutions\OpenGrowthSEO\Schema\ValidationEngine;
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'wc_get_product' ) ) {
	function wc_get_product( int $product_id ) {
		$map = isset( $GLOBALS['ogs_test_wc_products'] ) && is_array( $GLOBALS['ogs_test_wc_products'] ) ? $GLOBALS['ogs_test_wc_products'] : array();
		return $map[ $product_id ] ?? null;
	}
}

if ( ! function_exists( 'get_woocommerce_currency' ) ) {
	function get_woocommerce_currency(): string {
		return 'USD';
	}
}

if ( ! function_exists( 'get_post_field' ) ) {
	function get_post_field( string $field, int $post_id ): string {
		$fields = isset( $GLOBALS['ogs_test_post_fields'] ) && is_array( $GLOBALS['ogs_test_post_fields'] ) ? $GLOBALS['ogs_test_post_fields'] : array();
		return (string) ( $fields[ $post_id ][ $field ] ?? '' );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $name, $default = false ) {
		unset( $name );
		return $default;
	}
}

final class WooCommerceSeoModuleRegressionTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['ogs_test_wc_products'] = array();
		$GLOBALS['ogs_test_post_fields'] = array();
		if ( ! isset( $GLOBALS['ogs_search_appearance_context'] ) || ! is_array( $GLOBALS['ogs_search_appearance_context'] ) ) {
			$GLOBALS['ogs_search_appearance_context'] = array();
		}
		$GLOBALS['ogs_search_appearance_context']['post_fields'] = array();
	}

	public function test_schema_offer_normalizes_price_and_sets_availability(): void {
		$manager  = new SchemaManager();
		$product  = new class() {
			public function get_price(): string {
				return ' $ 19,99 ';
			}
			public function get_stock_status(): string {
				return 'instock';
			}
			public function is_in_stock(): bool {
				return true;
			}
			public function get_sku(): string {
				return 'SKU-1';
			}
		};
		$method   = new ReflectionMethod( $manager, 'build_woocommerce_offer_from_product' );
		$method->setAccessible( true );
		$offer = $method->invoke( $manager, $product, 'https://example.com/product/a/' );

		$this->assertSame( 'Offer', $offer['@type'] ?? '' );
		$this->assertSame( '19.99', $offer['price'] ?? '' );
		$this->assertSame( 'https://schema.org/InStock', $offer['availability'] ?? '' );
		$this->assertSame( 'SKU-1', $offer['sku'] ?? '' );
	}

	public function test_attach_offer_policy_details_applies_return_and_shipping_to_each_offer(): void {
		$manager = new SchemaManager();
		$method  = new ReflectionMethod( $manager, 'attach_offer_policy_details' );
		$method->setAccessible( true );

		$offers = array(
			array(
				'@type' => 'Offer',
				'price' => '12.00',
			),
			array(
				'@type' => 'Offer',
				'price' => '15.00',
			),
		);
		$result = $method->invoke( $manager, $offers, 'https://example.com/returns/', 'https://example.com/shipping/', 'US' );

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result );
		$this->assertArrayHasKey( 'hasMerchantReturnPolicy', $result[0] );
		$this->assertArrayHasKey( 'shippingDetails', $result[0] );
		$this->assertSame( 'US', $result[0]['shippingDetails']['shippingDestination']['addressCountry'] ?? '' );
	}

	public function test_audit_detects_thin_product_content_and_missing_variation_prices(): void {
		$audit = new AuditManager();
		$product = new class() {
			public function get_price(): string {
				return '';
			}
			public function is_type( string $type ): bool {
				return 'variable' === $type;
			}
			public function get_children(): array {
				return array( 2001, 2002 );
			}
		};
		$variation_a = new class() {
			public function get_price(): string {
				return '';
			}
		};
		$variation_b = new class() {
			public function get_price(): string {
				return '';
			}
		};

		$GLOBALS['ogs_test_wc_products'] = array(
			1001 => $product,
			2001 => $variation_a,
			2002 => $variation_b,
		);
		$GLOBALS['ogs_test_post_fields'][1001] = array(
			'post_content' => 'Short product text.',
		);
		$GLOBALS['ogs_search_appearance_context']['post_fields'][1001] = array(
			'post_content' => 'Short product text.',
		);

		$issues  = array();
		$method  = new ReflectionMethod( $audit, 'scan_woocommerce_product' );
		$method->setAccessible( true );
		$method->invokeArgs( $audit, array( 1001, &$issues ) );

		$titles = array_map(
			static fn( array $issue ): string => (string) ( $issue['title'] ?? '' ),
			$issues
		);
		$this->assertContains( 'WooCommerce product missing price', $titles );
		$this->assertContains( 'Variable product variations are missing prices', $titles );
		$this->assertContains( 'Product content appears thin', $titles );
	}

	public function test_product_validation_warns_when_merchant_policy_details_are_missing(): void {
		$report = ValidationEngine::validate_node(
			array(
				'@type' => 'Product',
				'name'  => 'Example Product',
				'offers' => array(
					'@type'         => 'Offer',
					'price'         => '19.99',
					'priceCurrency' => 'USD',
					'availability'  => 'https://schema.org/InStock',
				),
			),
			array(
				'is_singular'  => true,
				'post_type'    => 'product',
				'content_plain' => str_repeat( 'Detailed product content ', 8 ),
			)
		);

		$this->assertSame( 'warning', (string) ( $report['status'] ?? '' ) );
		$this->assertContains( 'merchant_offer_policy', (array) ( $report['missing_recommended'] ?? array() ) );
	}
}
