<?php
use OpenGrowthSolutions\OpenGrowthSEO\Admin\Editor;
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( int $post_id, string $meta_key, $meta_value ) {
		if ( ! isset( $GLOBALS['ogs_test_post_meta'] ) || ! is_array( $GLOBALS['ogs_test_post_meta'] ) ) {
			$GLOBALS['ogs_test_post_meta'] = array();
		}
		if ( ! isset( $GLOBALS['ogs_test_post_meta'][ $post_id ] ) || ! is_array( $GLOBALS['ogs_test_post_meta'][ $post_id ] ) ) {
			$GLOBALS['ogs_test_post_meta'][ $post_id ] = array();
		}
		$GLOBALS['ogs_test_post_meta'][ $post_id ][ $meta_key ] = $meta_value;
		return true;
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( int $post_id, string $meta_key, bool $single = false ) {
		unset( $single );
		return $GLOBALS['ogs_test_post_meta'][ $post_id ][ $meta_key ] ?? '';
	}
}

final class EditorMetaPersistenceTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['ogs_test_post_meta'] = array(
			55 => array(
				'ogs_seo_social_image' => 'https://example.com/uploads/old.jpg',
				'ogs_seo_index'        => 'index',
				'ogs_seo_follow'       => 'follow',
				'ogs_seo_robots'       => 'index,follow',
			),
		);
	}

	public function test_persist_meta_values_saves_social_and_canonical_fields(): void {
		$editor = new Editor();
		$saved  = $editor->persist_meta_values(
			55,
			array(
				'ogs_seo_social_image'       => 'https://example.com/uploads/new.jpg',
				'ogs_seo_social_title'       => 'Social title',
				'ogs_seo_social_description' => 'Social description',
				'ogs_seo_canonical'          => '/privacy-policy',
				'ogs_seo_index'              => 'noindex',
				'ogs_seo_follow'             => 'nofollow',
			)
		);

		$this->assertSame( 'https://example.com/uploads/new.jpg', $saved['ogs_seo_social_image'] );
		$this->assertSame( 'Social title', $saved['ogs_seo_social_title'] );
		$this->assertSame( 'Social description', $saved['ogs_seo_social_description'] );
		$this->assertSame( 'https://example.com/privacy-policy', $saved['ogs_seo_canonical'] );
		$this->assertSame( 'noindex', $saved['ogs_seo_index'] );
		$this->assertSame( 'nofollow', $saved['ogs_seo_follow'] );
		$this->assertSame( 'noindex,nofollow', $saved['ogs_seo_robots'] );
	}

	public function test_meta_snapshot_returns_saved_values_for_editor_rehydration(): void {
		$editor = new Editor();
		$saved  = $editor->meta_snapshot( 55 );

		$this->assertSame( 'https://example.com/uploads/old.jpg', $saved['ogs_seo_social_image'] );
		$this->assertSame( 'index', $saved['ogs_seo_index'] );
		$this->assertSame( 'follow', $saved['ogs_seo_follow'] );
	}

	public function test_editor_exposes_schema_preview_methods(): void {
		$this->assertTrue( method_exists( Editor::class, 'schema_preview_ajax' ) );
		$this->assertTrue( method_exists( Editor::class, 'schema_runtime_preview_state' ) );
		$this->assertTrue( method_exists( Editor::class, 'render_schema_runtime_preview' ) );
	}
}
