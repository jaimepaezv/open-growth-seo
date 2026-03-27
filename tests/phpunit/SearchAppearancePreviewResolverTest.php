<?php
declare(strict_types=1);

use OpenGrowthSolutions\OpenGrowthSEO\SEO\SearchAppearancePreview;
use PHPUnit\Framework\TestCase;

final class SearchAppearancePreviewResolverTest extends TestCase {
	public function test_resolve_applies_templates_and_returns_counts(): void {
		$result = SearchAppearancePreview::resolve(
			array(
				'title_template'            => '%%title%% %%sep%% %%sitename%%',
				'meta_description_template' => 'Summary: %%excerpt%%',
				'title_separator'           => '-',
				'site_name'                 => 'Open Growth',
				'site_description'          => 'SEO platform',
				'sample_title'              => 'Service Page',
				'sample_excerpt'            => 'Useful summary text',
				'search_query'              => 'seo software',
				'home_url'                  => 'https://example.com/',
				'schema_article_enabled'    => 1,
				'schema_faq_enabled'        => 0,
				'schema_video_enabled'      => 0,
			)
		);

		$this->assertSame( 'Service Page - Open Growth', $result['serp_title'] );
		$this->assertSame( 'Summary: Useful summary text', $result['serp_description'] );
		$this->assertSame( strlen( 'Service Page - Open Growth' ), $result['title_count'] );
		$this->assertSame( strlen( 'Summary: Useful summary text' ), $result['description_count'] );
		$this->assertSame( 'example.com', $result['home_host'] );
		$this->assertNotEmpty( $result['schema_hints'] );
	}

	public function test_resolve_sanitizes_html_and_falls_back_when_template_empty(): void {
		$result = SearchAppearancePreview::resolve(
			array(
				'title_template'            => '',
				'meta_description_template' => '<script>bad</script>',
				'title_separator'           => '|',
				'site_name'                 => 'Site Name',
				'site_description'          => 'Site Desc',
				'sample_title'              => 'Example Title',
				'sample_excerpt'            => 'Description',
				'search_query'              => 'query',
			)
		);

		$this->assertSame( 'Example Title | Site Name', $result['serp_title'] );
		$this->assertSame( 'bad', $result['serp_description'] );
		$this->assertSame( '', $result['social_image'] );
	}
}
