<?php
use OpenGrowthSolutions\OpenGrowthSEO\SEO\SiteOutputs;
use OpenGrowthSolutions\OpenGrowthSEO\Support\Settings;
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( int $post_id, string $key = '', bool $single = false ) {
		$store = isset( $GLOBALS['ogs_test_post_meta'] ) && is_array( $GLOBALS['ogs_test_post_meta'] ) ? $GLOBALS['ogs_test_post_meta'] : array();
		$value = $store[ $post_id ][ $key ] ?? '';
		return $single ? $value : array( $value );
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( int $post_id, string $key, $value ) {
		if ( ! isset( $GLOBALS['ogs_test_post_meta'] ) || ! is_array( $GLOBALS['ogs_test_post_meta'] ) ) {
			$GLOBALS['ogs_test_post_meta'] = array();
		}
		if ( ! isset( $GLOBALS['ogs_test_post_meta'][ $post_id ] ) || ! is_array( $GLOBALS['ogs_test_post_meta'][ $post_id ] ) ) {
			$GLOBALS['ogs_test_post_meta'][ $post_id ] = array();
		}
		$GLOBALS['ogs_test_post_meta'][ $post_id ][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( int $post_id ): string {
		$permalinks = isset( $GLOBALS['ogs_test_permalinks'] ) && is_array( $GLOBALS['ogs_test_permalinks'] ) ? $GLOBALS['ogs_test_permalinks'] : array();
		return (string) ( $permalinks[ $post_id ] ?? 'https://example.com/post-' . $post_id );
	}
}

if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( int $post_id ): string {
		$titles = isset( $GLOBALS['ogs_test_titles'] ) && is_array( $GLOBALS['ogs_test_titles'] ) ? $GLOBALS['ogs_test_titles'] : array();
		return (string) ( $titles[ $post_id ] ?? 'Example Title' );
	}
}

final class SiteOutputsSmokeTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['ogs_test_options'] = array();
		$GLOBALS['ogs_test_current_post_id'] = 15;
		$GLOBALS['ogs_test_titles'] = array(
			15 => 'Technical SEO Audit Checklist',
		);
		$GLOBALS['ogs_test_permalinks'] = array(
			15 => 'https://example.com/technical-seo-audit-checklist',
		);
		$GLOBALS['ogs_content_controls_context'] = array(
			'permalinks' => array(
				15 => 'https://example.com/technical-seo-audit-checklist',
			),
			'titles' => array(
				15 => 'Technical SEO Audit Checklist',
			),
			'post_id' => 15,
		);
	}

	public function test_site_outputs_class_exists(): void {
		$this->assertTrue( class_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\SEO\\SiteOutputs' ) );
	}

	public function test_settings_sanitize_keeps_verification_tokens_and_llms_mode_safe(): void {
		$sanitized = Settings::sanitize(
			array(
				'webmaster_google_verification' => '<meta>google-token</meta>',
				'rss_before_content'            => '<b>Source:</b> %%url%%',
				'llms_txt_enabled'              => '1',
				'llms_txt_mode'                 => 'custom',
				'llms_txt_content'              => '<script>x</script>Canonical first',
			)
		);

		$this->assertSame( 'google-token', $sanitized['webmaster_google_verification'] );
		$this->assertSame( 'Source: %%url%%', $sanitized['rss_before_content'] );
		$this->assertSame( 1, $sanitized['llms_txt_enabled'] );
		$this->assertSame( 'custom', $sanitized['llms_txt_mode'] );
		$this->assertStringNotContainsString( '<script>', $sanitized['llms_txt_content'] );
	}

	public function test_render_verification_tags_outputs_enabled_tokens(): void {
		$GLOBALS['ogs_test_options']['ogs_seo_settings'] = Settings::sanitize(
			array(
				'webmaster_google_verification' => 'google-token',
				'webmaster_bing_verification'   => 'bing-token',
			)
		);

		$outputs = new SiteOutputs();

		ob_start();
		$outputs->render_verification_tags();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'google-site-verification', $html );
		$this->assertStringContainsString( 'google-token', $html );
		$this->assertStringContainsString( 'msvalidate.01', $html );
		$this->assertStringContainsString( 'bing-token', $html );
	}

	public function test_filter_feed_content_injects_before_and_after_templates(): void {
		$GLOBALS['ogs_test_options']['ogs_seo_settings'] = Settings::sanitize(
			array(
				'rss_before_content' => 'Originally published on %%sitename%%',
				'rss_after_content'  => 'Read the canonical source: %%url%%',
			)
		);

		$outputs = new SiteOutputs();
		$feed    = $outputs->filter_feed_content( '<p>Body content</p>' );

		$this->assertStringContainsString( 'Originally published on Open Growth Site', $feed );
		$this->assertStringContainsString( 'https://example.com/technical-seo-audit-checklist', $feed );
		$this->assertStringContainsString( 'Body content', $feed );
	}

	public function test_llms_txt_guided_mode_includes_core_site_signals(): void {
		$GLOBALS['ogs_test_options']['ogs_seo_settings'] = Settings::sanitize(
			array(
				'llms_txt_enabled' => '1',
				'sitemap_enabled'  => '1',
			)
		);

		$outputs = new SiteOutputs();
		$body    = $outputs->llms_txt_body();

		$this->assertStringContainsString( '# Open Growth Site', $body );
		$this->assertStringContainsString( 'Sitemap: https://example.com/sitemap.xml', $body );
		$this->assertStringContainsString( 'Canonical policy:', $body );
	}
}
