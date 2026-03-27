<?php
use PHPUnit\Framework\TestCase;

final class ContentControlsSmokeTest extends TestCase {
	public function test_editor_class_exists(): void {
		$this->assertTrue( class_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Admin\\Editor' ) );
	}

	public function test_editor_sanitizers_keep_safe_values(): void {
		$this->assertSame(
			'noindex,nofollow',
			\OpenGrowthSolutions\OpenGrowthSEO\Admin\Editor::sanitize_robots_pair_meta( 'noindex,nofollow' )
		);
		$this->assertSame(
			'1',
			\OpenGrowthSolutions\OpenGrowthSEO\Admin\Editor::sanitize_cornerstone_meta( '1' )
		);
		$this->assertSame(
			'42',
			\OpenGrowthSolutions\OpenGrowthSEO\Admin\Editor::sanitize_primary_term_meta( '42' )
		);
		$this->assertSame(
			'',
			\OpenGrowthSolutions\OpenGrowthSEO\Admin\Editor::sanitize_primary_term_meta( '0' )
		);
		$this->assertSame(
			'',
			\OpenGrowthSolutions\OpenGrowthSEO\Admin\Editor::sanitize_cornerstone_meta( '0' )
		);
		$this->assertSame(
			'index,follow',
			\OpenGrowthSolutions\OpenGrowthSEO\Admin\Editor::sanitize_robots_pair_meta( 'invalid-value' )
		);
		$this->assertSame(
			'https://example.com/services/seo',
			\OpenGrowthSolutions\OpenGrowthSEO\Admin\Editor::sanitize_canonical_url_meta( '/services/seo' )
		);
		$this->assertSame(
			'',
			\OpenGrowthSolutions\OpenGrowthSEO\Admin\Editor::sanitize_canonical_url_meta( 'javascript:alert(1)' )
		);
		$this->assertSame(
			'index',
			\OpenGrowthSolutions\OpenGrowthSEO\Admin\Editor::sanitize_index_meta( 'invalid-index' )
		);
		$this->assertSame(
			'noindex',
			\OpenGrowthSolutions\OpenGrowthSEO\Admin\Editor::sanitize_index_meta( 'noindex' )
		);
		$this->assertSame(
			'follow',
			\OpenGrowthSolutions\OpenGrowthSEO\Admin\Editor::sanitize_follow_meta( 'invalid-follow' )
		);
		$this->assertSame(
			'nofollow',
			\OpenGrowthSolutions\OpenGrowthSEO\Admin\Editor::sanitize_follow_meta( 'nofollow' )
		);
		$this->assertSame(
			'',
			\OpenGrowthSolutions\OpenGrowthSEO\Admin\Editor::sanitize_schema_type_meta( 'InvalidType' )
		);
		$this->assertSame(
			'31 Dec 2026 23:59:00 GMT',
			\OpenGrowthSolutions\OpenGrowthSEO\Admin\Editor::sanitize_unavailable_after_meta( '2026-12-31T23:59' )
		);
	}

	public function test_editor_exposes_hardened_meta_auth_callback(): void {
		$reflection = new ReflectionClass( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Admin\\Editor' );
		$this->assertTrue( $reflection->hasMethod( 'can_edit_meta' ) );
	}

	public function test_frontend_meta_has_singular_guard_for_per_url_overrides(): void {
		$reflection = new ReflectionClass( '\\OpenGrowthSolutions\\OpenGrowthSEO\\SEO\\FrontendMeta' );
		$this->assertTrue( $reflection->hasMethod( 'queried_post_id' ) );
	}

	public function test_editor_field_help_meta_exposes_core_seo_guidance(): void {
		$editor     = new \OpenGrowthSolutions\OpenGrowthSEO\Admin\Editor();
		$reflection = new ReflectionClass( $editor );
		$method     = $reflection->getMethod( 'content_field_meta' );
		$method->setAccessible( true );

		$meta = $method->invoke( $editor );
		$this->assertIsArray( $meta );
		$this->assertArrayHasKey( 'ogs_seo_title', $meta );
		$this->assertStringContainsString( 'Google', $meta['ogs_seo_title']['help'] );
		$this->assertArrayHasKey( 'ogs_seo_focus_keyphrase', $meta );
		$this->assertStringContainsString( 'Primary phrase', $meta['ogs_seo_focus_keyphrase']['help'] );
		$this->assertArrayHasKey( 'ogs_seo_cornerstone', $meta );
		$this->assertArrayHasKey( 'ogs_seo_primary_term', $meta );
		$this->assertArrayHasKey( 'ogs_seo_canonical', $meta );
		$this->assertStringContainsString( 'duplicate content', $meta['ogs_seo_canonical']['help'] );
	}

	public function test_social_image_supports_manual_url_and_media_library_selection(): void {
		$editor_source = file_get_contents( __DIR__ . '/../../includes/Admin/Editor.php' );
		$script_source = file_get_contents( __DIR__ . '/../../assets/js/editor.js' );

		$this->assertIsString( $editor_source );
		$this->assertIsString( $script_source );
		$this->assertStringContainsString( 'wp_enqueue_media();', $editor_source );
		$this->assertStringContainsString( 'ogs-select-social-image', $editor_source );
		$this->assertStringContainsString( 'wp.media', $script_source );
		$this->assertStringContainsString( 'MediaUpload', $script_source );
	}

	public function test_editor_registers_assets_for_both_block_and_classic_editors(): void {
		$editor_source = file_get_contents( __DIR__ . '/../../includes/Admin/Editor.php' );

		$this->assertIsString( $editor_source );
		$this->assertStringContainsString( "enqueue_block_editor_assets", $editor_source );
		$this->assertStringContainsString( "admin_enqueue_scripts", $editor_source );
		$this->assertStringContainsString( 'classic_editor_assets', $editor_source );
	}

	public function test_schema_options_are_restricted_in_simple_mode(): void {
		$editor     = new \OpenGrowthSolutions\OpenGrowthSEO\Admin\Editor();
		$reflection = new ReflectionClass( $editor );
		$method     = $reflection->getMethod( 'schema_type_options' );
		$method->setAccessible( true );

		$simple = $method->invoke( $editor, true );
		$advanced = $method->invoke( $editor, false );

		$this->assertArrayHasKey( '', $simple );
		$this->assertArrayHasKey( 'Article', $simple );
		$this->assertArrayHasKey( 'BlogPosting', $simple );
		$this->assertArrayNotHasKey( 'FAQPage', $simple );
		$this->assertArrayHasKey( 'FAQPage', $advanced );
	}

	public function test_humanize_status_translates_operational_labels(): void {
		$editor     = new \OpenGrowthSolutions\OpenGrowthSEO\Admin\Editor();
		$reflection = new ReflectionClass( $editor );
		$method     = $reflection->getMethod( 'humanize_status' );
		$method->setAccessible( true );

		$this->assertSame( 'Good', $method->invoke( $editor, 'healthy' ) );
		$this->assertSame( 'Needs work', $method->invoke( $editor, 'needs-attention' ) );
		$this->assertSame( 'Fix this first', $method->invoke( $editor, 'critical' ) );
	}

	public function test_editor_groups_controls_and_applies_contextual_schema_guardrails(): void {
		$source = file_get_contents( __DIR__ . '/../../includes/Admin/Editor.php' );
		$this->assertIsString( $source );
		$this->assertStringContainsString( 'Advanced snippet controls', $source );
		$this->assertStringContainsString( 'Focus keyphrase', $source );
		$this->assertStringContainsString( 'Cornerstone content', $source );
		$this->assertStringContainsString( 'Primary topic', $source );
		$this->assertStringContainsString( 'Suggested internal links to add from this page', $source );
		$this->assertStringContainsString( 'SEO MASTERS PLUS', $source );
		$this->assertStringContainsString( 'private function render_editor_section_start', $source );
		$this->assertStringContainsString( 'private function schema_override_state', $source );
		$this->assertStringContainsString( 'Eligibility::sanitize_override_for_post', $source );
	}
}
