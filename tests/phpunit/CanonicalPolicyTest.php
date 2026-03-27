<?php
use OpenGrowthSolutions\OpenGrowthSEO\SEO\CanonicalPolicy;
use PHPUnit\Framework\TestCase;

final class CanonicalPolicyTest extends TestCase {
	public function test_redirect_target_overrides_conflicting_manual_canonical(): void {
		$decision = CanonicalPolicy::resolve(
			array(
				'canonical_enabled' => 1,
			),
			array(
				'self_url'             => 'https://example.com/old-page/',
				'manual_canonical'     => 'https://example.com/manual-target/',
				'contextual_canonical' => 'https://example.com/old-page/',
				'fallback_canonical'   => 'https://example.com/old-page/',
				'redirect_target'      => 'https://example.com/new-page/',
				'is_noindex'           => false,
				'sitemap_included'     => true,
			)
		);

		$this->assertSame( 'https://example.com/new-page/', $decision['effective'] );
		$this->assertSame( 'redirect', $decision['effective_source'] );
		$this->assertNotSame( '', (string) ( $decision['reason'] ?? '' ) );
		$this->assertNotEmpty( $decision['conflicts'] );
	}

	public function test_filter_policy_can_disable_canonical_output_for_faceted_urls(): void {
		$decision = CanonicalPolicy::resolve(
			array(
				'canonical_enabled' => 1,
			),
			array(
				'self_url'                 => 'https://example.com/shop/?filter_color=blue',
				'manual_canonical'         => '',
				'contextual_canonical'     => 'https://example.com/shop/',
				'fallback_canonical'       => 'https://example.com/shop/',
				'redirect_target'          => '',
				'is_filter_surface'        => true,
				'filter_canonical_policy'  => 'none',
				'filter_base_url'          => 'https://example.com/shop/',
				'is_noindex'               => true,
				'sitemap_included'         => false,
			)
		);

		$this->assertSame( '', $decision['effective'] );
		$this->assertSame( 'filter_none', $decision['effective_source'] );
	}

	public function test_sitemap_and_noindex_signal_conflict_is_reported(): void {
		$decision = CanonicalPolicy::resolve(
			array(
				'canonical_enabled' => 1,
			),
			array(
				'self_url'             => 'https://example.com/guide/',
				'manual_canonical'     => '',
				'contextual_canonical' => 'https://example.com/guide/',
				'fallback_canonical'   => 'https://example.com/guide/',
				'redirect_target'      => '',
				'is_noindex'           => true,
				'sitemap_included'     => true,
			)
		);

		$codes = array_map(
			static fn( $item ) => is_array( $item ) ? (string) ( $item['code'] ?? '' ) : '',
			(array) ( $decision['conflicts'] ?? array() )
		);
		$this->assertContains( 'sitemap_noindex_conflict', $codes );
	}

	public function test_matching_redirect_target_prefers_exact_and_longest_prefix(): void {
		$target = CanonicalPolicy::matching_redirect_target(
			'/shop/blue/shoes',
			array(
				array(
					'enabled'         => 1,
					'source_path'     => '/shop',
					'match_type'      => 'prefix',
					'destination_url' => '/catalog/',
				),
				array(
					'enabled'         => 1,
					'source_path'     => '/shop/blue',
					'match_type'      => 'prefix',
					'destination_url' => '/catalog/blue/',
				),
			)
		);
		$this->assertSame( 'https://example.com/catalog/blue/', $target );
	}

	public function test_indexable_filter_surface_without_canonical_is_reported(): void {
		$decision = CanonicalPolicy::resolve(
			array(
				'canonical_enabled' => 1,
			),
			array(
				'self_url'                 => 'https://example.com/shop/?filter_color=blue',
				'manual_canonical'         => '',
				'contextual_canonical'     => 'https://example.com/shop/',
				'fallback_canonical'       => 'https://example.com/shop/',
				'redirect_target'          => '',
				'is_filter_surface'        => true,
				'filter_canonical_policy'  => 'none',
				'filter_base_url'          => 'https://example.com/shop/',
				'is_noindex'               => false,
				'sitemap_included'         => false,
			)
		);

		$codes = array_map(
			static fn( $item ) => is_array( $item ) ? (string) ( $item['code'] ?? '' ) : '',
			(array) ( $decision['conflicts'] ?? array() )
		);
		$this->assertContains( 'indexable_filter_without_canonical', $codes );
	}

	public function test_noindex_and_redirect_overlap_is_reported(): void {
		$decision = CanonicalPolicy::resolve(
			array(
				'canonical_enabled' => 1,
			),
			array(
				'self_url'             => 'https://example.com/legacy-url/',
				'manual_canonical'     => '',
				'contextual_canonical' => 'https://example.com/legacy-url/',
				'fallback_canonical'   => 'https://example.com/legacy-url/',
				'redirect_target'      => 'https://example.com/new-url/',
				'is_noindex'           => true,
				'sitemap_included'     => false,
			)
		);

		$codes = array_map(
			static fn( $item ) => is_array( $item ) ? (string) ( $item['code'] ?? '' ) : '',
			(array) ( $decision['conflicts'] ?? array() )
		);
		$this->assertContains( 'noindex_redirect_overlap', $codes );
		$this->assertSame( 'redirect', (string) ( $decision['effective_source'] ?? '' ) );
	}
}
