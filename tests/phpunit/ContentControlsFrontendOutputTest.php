<?php
use OpenGrowthSolutions\OpenGrowthSEO\SEO\FrontendMeta;
use PHPUnit\Framework\TestCase;

if ( ! isset( $GLOBALS['ogs_content_controls_context'] ) ) {
	$GLOBALS['ogs_content_controls_context'] = array();
}

if ( ! function_exists( 'ogs_test_context_value' ) ) {
	function ogs_test_context_value( string $namespace, string $key, $default = null ) {
		$contexts = array(
			$GLOBALS['ogs_search_appearance_context'] ?? array(),
			$GLOBALS['ogs_content_controls_context'] ?? array(),
		);

		if ( 'post_fields' === $namespace ) {
			$contexts[] = $GLOBALS['ogs_test_post_fields'] ?? array();
		}

		foreach ( $contexts as $context ) {
			if ( is_array( $context ) && array_key_exists( $key, $context ) ) {
				return $context[ $key ];
			}
		}

		return $default;
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	function is_admin(): bool {
		return false;
	}
}

if ( ! function_exists( 'is_feed' ) ) {
	function is_feed(): bool {
		return false;
	}
}

if ( ! function_exists( 'is_singular' ) ) {
	function is_singular(): bool {
		return ! empty( ogs_test_context_value( 'flags', 'is_singular', false ) );
	}
}

if ( ! function_exists( 'is_front_page' ) ) {
	function is_front_page(): bool {
		return ! empty( ogs_test_context_value( 'flags', 'is_front_page', false ) );
	}
}

if ( ! function_exists( 'is_home' ) ) {
	function is_home(): bool {
		return ! empty( ogs_test_context_value( 'flags', 'is_home', false ) );
	}
}

if ( ! function_exists( 'is_search' ) ) {
	function is_search(): bool {
		return ! empty( ogs_test_context_value( 'flags', 'is_search', false ) );
	}
}

if ( ! function_exists( 'is_tax' ) ) {
	function is_tax(): bool {
		return ! empty( ogs_test_context_value( 'flags', 'is_tax', false ) );
	}
}

if ( ! function_exists( 'is_category' ) ) {
	function is_category(): bool {
		return ! empty( ogs_test_context_value( 'flags', 'is_category', false ) );
	}
}

if ( ! function_exists( 'is_tag' ) ) {
	function is_tag(): bool {
		return ! empty( ogs_test_context_value( 'flags', 'is_tag', false ) );
	}
}

if ( ! function_exists( 'is_author' ) ) {
	function is_author(): bool {
		return ! empty( ogs_test_context_value( 'flags', 'is_author', false ) );
	}
}

if ( ! function_exists( 'is_date' ) ) {
	function is_date(): bool {
		return ! empty( ogs_test_context_value( 'flags', 'is_date', false ) );
	}
}

if ( ! function_exists( 'is_attachment' ) ) {
	function is_attachment(): bool {
		return ! empty( ogs_test_context_value( 'flags', 'is_attachment', false ) );
	}
}

if ( ! function_exists( 'is_post_type_archive' ) ) {
	function is_post_type_archive( string $post_type = '' ): bool {
		unset( $post_type );
		return false;
	}
}

if ( ! function_exists( 'get_queried_object_id' ) ) {
	function get_queried_object_id(): int {
		return (int) ogs_test_context_value( 'ids', 'post_id', ogs_test_context_value( 'ids', 'queried_object_id', 0 ) );
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( int $post_id, string $key, bool $single = false ) {
		unset( $single );
		$contexts = array(
			$GLOBALS['ogs_content_controls_context']['post_meta'] ?? array(),
			$GLOBALS['ogs_test_post_meta'] ?? array(),
		);
		foreach ( $contexts as $context ) {
			if ( isset( $context[ $post_id ][ $key ] ) ) {
				return $context[ $post_id ][ $key ];
			}
		}
		return '';
	}
}

if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( int $post_id = 0 ): string {
		$permalinks = ogs_test_context_value( 'links', 'permalinks', array() );
		return is_array( $permalinks ) ? (string) ( $permalinks[ $post_id ] ?? '' ) : '';
	}
}

if ( ! function_exists( 'wp_get_document_title' ) ) {
	function wp_get_document_title(): string {
		return (string) ogs_test_context_value( 'title', 'document_title', '' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'get_post_field' ) ) {
	function get_post_field( string $field, int $post_id ): string {
		$post_fields = ogs_test_context_value( 'post_fields', 'post_fields', array() );
		if ( is_array( $post_fields ) && isset( $post_fields[ $post_id ][ $field ] ) ) {
			return (string) $post_fields[ $post_id ][ $field ];
		}
		$fallback = $GLOBALS['ogs_test_post_fields'] ?? array();
		return (string) ( $fallback[ $post_id ][ $field ] ?? '' );
	}
}

if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( int $post_id ): string {
		$titles = ogs_test_context_value( 'titles', 'titles', array() );
		return is_array( $titles ) ? (string) ( $titles[ $post_id ] ?? '' ) : '';
	}
}

if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( int $post_id = 0 ): string {
		$post_types = ogs_test_context_value( 'post_types', 'post_types', array() );
		return is_array( $post_types ) ? (string) ( $post_types[ $post_id ] ?? 'page' ) : 'page';
	}
}

if ( ! function_exists( 'wp_get_canonical_url' ) ) {
	function wp_get_canonical_url(): string {
		return '';
	}
}

if ( ! function_exists( 'get_query_var' ) ) {
	function get_query_var( string $key, $default = '' ) {
		unset( $key );
		return $default;
	}
}

if ( ! function_exists( 'get_search_link' ) ) {
	function get_search_link( string $query = '' ): string {
		return 'https://example.com/?s=' . rawurlencode( $query );
	}
}

if ( ! function_exists( 'get_the_archive_title' ) ) {
	function get_the_archive_title(): string {
		return (string) ogs_test_context_value( 'archive', 'archive_title', '' );
	}
}

if ( ! function_exists( 'single_post_title' ) ) {
	function single_post_title( string $prefix = '', bool $display = true ): string {
		unset( $prefix, $display );
		return (string) ogs_test_context_value( 'title', 'single_post_title', '' );
	}
}

if ( ! function_exists( 'get_search_query' ) ) {
	function get_search_query(): string {
		return (string) ogs_test_context_value( 'search', 'search_query', '' );
	}
}

if ( ! function_exists( 'url_to_postid' ) ) {
	function url_to_postid( string $url ): int {
		unset( $url );
		return 0;
	}
}

if ( ! function_exists( 'remove_action' ) ) {
	function remove_action( string $hook_name, $callback, int $priority = 10 ): bool {
		unset( $hook_name, $callback, $priority );
		return true;
	}
}

if ( ! function_exists( 'user_trailingslashit' ) ) {
	function user_trailingslashit( string $string, string $type_of_url = '' ): string {
		unset( $type_of_url );
		return rtrim( $string, '/' ) . '/';
	}
}

final class ContentControlsFrontendOutputTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['ogs_test_options']              = array();
		$GLOBALS['ogs_content_controls_context']  = array(
			'is_singular'    => true,
			'post_id'        => 42,
			'document_title' => 'Default Title | Site',
			'permalinks'     => array(
				42 => 'https://example.com/privacy-policy',
			),
			'titles'         => array(
				42 => 'Privacy Policy',
			),
			'post_types'     => array(
				42 => 'page',
			),
			'post_fields'    => array(
				42 => array(
					'post_excerpt' => 'Default excerpt text.',
					'post_content' => 'Default content.',
				),
			),
			'post_meta'      => array(
				42 => array(
					'ogs_seo_title'              => 'Privacy Policy | MySite',
					'ogs_seo_description'        => 'Find out how we protect your data and privacy.',
					'ogs_seo_canonical'          => 'https://example.com/privacy-policy',
					'ogs_seo_social_title'       => 'Privacy Policy Social',
					'ogs_seo_social_description' => 'Social description for the privacy page.',
					'ogs_seo_social_image'       => 'https://example.com/uploads/privacy-cover.jpg',
				),
			),
		);
	}

	public function test_render_meta_outputs_saved_social_and_canonical_overrides(): void {
		$meta = new FrontendMeta();

		ob_start();
		$meta->render_meta();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( '<link rel="canonical" href="https://example.com/privacy-policy" />', $output );
		$this->assertStringContainsString( '<meta property="og:title" content="Privacy Policy Social" />', $output );
		$this->assertStringContainsString( '<meta property="og:description" content="Social description for the privacy page." />', $output );
		$this->assertStringContainsString( '<meta property="og:image" content="https://example.com/uploads/privacy-cover.jpg" />', $output );
		$this->assertStringContainsString( '<meta name="twitter:image" content="https://example.com/uploads/privacy-cover.jpg" />', $output );
	}
}
