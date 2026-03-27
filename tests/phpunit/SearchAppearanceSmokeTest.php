<?php
use OpenGrowthSolutions\OpenGrowthSEO\SEO\FrontendMeta;
use OpenGrowthSolutions\OpenGrowthSEO\Support\Settings;
use PHPUnit\Framework\TestCase;

if ( ! isset( $GLOBALS['ogs_search_appearance_context'] ) ) {
	$GLOBALS['ogs_search_appearance_context'] = array();
}

if ( ! function_exists( 'is_singular' ) ) {
	function is_singular(): bool {
		return ! empty( $GLOBALS['ogs_search_appearance_context']['is_singular'] );
	}
}

if ( ! function_exists( 'is_tax' ) ) {
	function is_tax(): bool {
		return ! empty( $GLOBALS['ogs_search_appearance_context']['is_tax'] );
	}
}

if ( ! function_exists( 'is_category' ) ) {
	function is_category(): bool {
		return ! empty( $GLOBALS['ogs_search_appearance_context']['is_category'] );
	}
}

if ( ! function_exists( 'is_tag' ) ) {
	function is_tag(): bool {
		return ! empty( $GLOBALS['ogs_search_appearance_context']['is_tag'] );
	}
}

if ( ! function_exists( 'is_author' ) ) {
	function is_author(): bool {
		return ! empty( $GLOBALS['ogs_search_appearance_context']['is_author'] );
	}
}

if ( ! function_exists( 'is_search' ) ) {
	function is_search(): bool {
		return ! empty( $GLOBALS['ogs_search_appearance_context']['is_search'] );
	}
}

if ( ! function_exists( 'is_home' ) ) {
	function is_home(): bool {
		return ! empty( $GLOBALS['ogs_search_appearance_context']['is_home'] );
	}
}

if ( ! function_exists( 'is_front_page' ) ) {
	function is_front_page(): bool {
		return ! empty( $GLOBALS['ogs_search_appearance_context']['is_front_page'] );
	}
}

if ( ! function_exists( 'get_queried_object_id' ) ) {
	function get_queried_object_id(): int {
		return (int) ( $GLOBALS['ogs_search_appearance_context']['queried_object_id'] ?? 0 );
	}
}

if ( ! function_exists( 'get_queried_object' ) ) {
	function get_queried_object() {
		return $GLOBALS['ogs_search_appearance_context']['queried_object'] ?? null;
	}
}

if ( ! function_exists( 'get_search_query' ) ) {
	function get_search_query(): string {
		return (string) ( $GLOBALS['ogs_search_appearance_context']['search_query'] ?? '' );
	}
}

if ( ! function_exists( 'get_post_field' ) ) {
	function get_post_field( string $field, int $post_id ): string {
		$post_fields = isset( $GLOBALS['ogs_search_appearance_context']['post_fields'] ) && is_array( $GLOBALS['ogs_search_appearance_context']['post_fields'] )
			? $GLOBALS['ogs_search_appearance_context']['post_fields']
			: array();
		if ( isset( $post_fields[ $post_id ][ $field ] ) ) {
			return (string) $post_fields[ $post_id ][ $field ];
		}
		return '';
	}
}

if ( ! function_exists( 'wp_trim_words' ) ) {
	function wp_trim_words( string $text, int $num_words = 55, string $more = null ): string {
		unset( $more );
		$words = preg_split( '/\s+/', trim( $text ) ) ?: array();
		if ( count( $words ) <= $num_words ) {
			return implode( ' ', $words );
		}
		return implode( ' ', array_slice( $words, 0, $num_words ) );
	}
}

if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( int $post_id ): string {
		$titles = isset( $GLOBALS['ogs_search_appearance_context']['titles'] ) && is_array( $GLOBALS['ogs_search_appearance_context']['titles'] )
			? $GLOBALS['ogs_search_appearance_context']['titles']
			: array();
		return (string) ( $titles[ $post_id ] ?? '' );
	}
}

if ( ! function_exists( 'single_post_title' ) ) {
	function single_post_title( string $prefix = '', bool $display = true ): string {
		unset( $prefix, $display );
		return (string) ( $GLOBALS['ogs_search_appearance_context']['single_post_title'] ?? '' );
	}
}

if ( ! function_exists( 'get_the_archive_title' ) ) {
	function get_the_archive_title(): string {
		return (string) ( $GLOBALS['ogs_search_appearance_context']['archive_title'] ?? '' );
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( string $show = '', string $filter = 'raw' ): string {
		unset( $filter );
		if ( 'description' === $show ) {
			return (string) ( $GLOBALS['ogs_search_appearance_context']['site_description'] ?? '' );
		}
		return (string) ( $GLOBALS['ogs_search_appearance_context']['site_name'] ?? '' );
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( string $value ): string {
		return trim( strip_tags( $value ) );
	}
}

final class SearchAppearanceSmokeTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['ogs_search_appearance_context'] = array(
			'is_singular'       => false,
			'is_tax'            => false,
			'is_category'       => false,
			'is_tag'            => false,
			'is_author'         => false,
			'is_search'         => false,
			'is_home'           => false,
			'is_front_page'     => false,
			'queried_object_id' => 0,
			'queried_object'    => null,
			'search_query'      => '',
			'post_fields'       => array(),
			'titles'            => array(),
			'single_post_title' => '',
			'archive_title'     => '',
			'site_name'         => 'Open Growth Site',
			'site_description'  => 'Default site description',
		);
	}

	public function test_frontend_meta_class_exists(): void {
		$this->assertTrue( class_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\SEO\\FrontendMeta' ) );
	}

	public function test_admin_and_editor_preview_methods_exist(): void {
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Admin\\Admin', 'render_snippet_preview' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Admin\\Editor', 'render_snippet_preview' ) );
	}

	public function test_archive_title_is_used_for_title_token_outside_singular(): void {
		$GLOBALS['ogs_search_appearance_context']['archive_title'] = 'Category: Guides';
		$GLOBALS['ogs_search_appearance_context']['site_name']     = 'Open Growth Site';

		$meta    = new FrontendMeta();
		$result  = $this->call_apply_template( $meta, '%%title%% %%sep%% %%sitename%%' );

		$this->assertSame( 'Category: Guides | Open Growth Site', $result );
	}

	public function test_term_description_is_used_for_excerpt_token_on_taxonomy_context(): void {
		$GLOBALS['ogs_search_appearance_context']['is_tax'] = true;
		$GLOBALS['ogs_search_appearance_context']['queried_object'] = (object) array(
			'name'        => 'Guides',
			'description' => '<p>Useful taxonomy summary for users.</p>',
		);

		$meta   = new FrontendMeta();
		$result = $this->call_apply_template( $meta, '%%excerpt%%' );

		$this->assertSame( 'Useful taxonomy summary for users.', $result );
	}

	public function test_settings_sanitize_enforces_valid_robots_defaults(): void {
		$sanitized = Settings::sanitize(
			array(
				'title_template'            => '<strong>%%title%%</strong> <script>alert(1)</script>',
				'post_type_robots_defaults' => array(
					'book' => 'invalid-value',
				),
				'taxonomy_robots_defaults'  => array(
					'category' => 'NOINDEX,FOLLOW',
				),
			)
		);

		$this->assertArrayHasKey( 'book', $sanitized['post_type_robots_defaults'] );
		$this->assertSame( 'index,follow', $sanitized['post_type_robots_defaults']['book'] );
		$this->assertSame( 'noindex,follow', $sanitized['taxonomy_robots_defaults']['category'] );
		$this->assertStringNotContainsString( '<', $sanitized['title_template'] );
		$this->assertStringContainsString( '%%title%%', $sanitized['title_template'] );
	}

	private function call_apply_template( FrontendMeta $meta, string $template ): string {
		$method = new \ReflectionMethod( $meta, 'apply_template' );
		$method->setAccessible( true );
		$result = $method->invoke( $meta, $template );
		return is_string( $result ) ? $result : '';
	}
}
