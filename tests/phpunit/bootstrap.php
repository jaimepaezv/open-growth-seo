<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/wp/' );
}
if ( ! defined( 'OGS_SEO_PATH' ) ) {
	define( 'OGS_SEO_PATH', dirname( __DIR__, 2 ) . '/' );
}
if ( ! defined( 'OGS_SEO_FILE' ) ) {
	define( 'OGS_SEO_FILE', OGS_SEO_PATH . 'open-growth-seo.php' );
}
if ( ! defined( 'OGS_SEO_URL' ) ) {
	define( 'OGS_SEO_URL', 'https://example.com/wp-content/plugins/open-growth-seo/' );
}
if ( ! defined( 'OGS_SEO_BASENAME' ) ) {
	define( 'OGS_SEO_BASENAME', 'open-growth-seo/open-growth-seo.php' );
}
if ( ! defined( 'OGS_SEO_VERSION' ) ) {
	define( 'OGS_SEO_VERSION', '1.0.0-test' );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! class_exists( 'WP_CLI' ) ) {
	class WP_CLI {
		public static array $added_commands = array();
		public static array $logs = array();
		public static array $successes = array();
		public static array $warnings = array();
		public static array $lines = array();
		public static array $errors = array();

		public static function add_command( string $name, $callable, array $args = array() ): void {
			self::$added_commands[ $name ] = array(
				'callable' => $callable,
				'args'     => $args,
			);
		}

		public static function log( string $message ): void {
			self::$logs[] = $message;
		}

		public static function success( string $message ): void {
			self::$successes[] = $message;
		}

		public static function warning( string $message ): void {
			self::$warnings[] = $message;
		}

		public static function line( string $message ): void {
			self::$lines[] = $message;
		}

		public static function error( string $message ): void {
			self::$errors[] = $message;
			throw new RuntimeException( $message );
		}

		public static function reset(): void {
			self::$added_commands = array();
			self::$logs = array();
			self::$successes = array();
			self::$warnings = array();
			self::$lines = array();
			self::$errors = array();
		}
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, ?string $domain = null ): string {
		unset( $domain );
		return $text;
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( string $text ): string {
		return strip_tags( $text );
	}
}

if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( array $args, array $defaults ): array {
		return array_merge( $defaults, $args );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $value ): string {
		return rtrim( $value, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '/' ): string {
		return 'https://example.com' . $path;
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ) {
		if ( -1 === $component ) {
			return parse_url( $url );
		}
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( string $url ): string {
		if ( str_starts_with( $url, '/' ) ) {
			return 'https://example.com' . $url;
		}
		return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : '';
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $value ): string {
		return trim( strip_tags( $value ) );
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( string $value ): string {
		$value = str_replace( array( "\r\n", "\r" ), "\n", $value );
		return trim( strip_tags( $value ) );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', $key ) );
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( string $title ): string {
		$title = strtolower( trim( strip_tags( $title ) ) );
		$title = preg_replace( '/[^a-z0-9]+/', '-', $title );
		return trim( (string) $title, '-' );
	}
}

if ( ! function_exists( 'sanitize_html_class' ) ) {
	function sanitize_html_class( string $class ): string {
		return preg_replace( '/[^A-Za-z0-9_-]/', '', $class );
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ): int {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $name, $default = false ) {
		if ( isset( $GLOBALS['ogs_test_options'] ) && is_array( $GLOBALS['ogs_test_options'] ) && array_key_exists( $name, $GLOBALS['ogs_test_options'] ) ) {
			return $GLOBALS['ogs_test_options'][ $name ];
		}
		return $default;
	}
}

if ( ! function_exists( 'add_option' ) ) {
	function add_option( string $name, $value = '', string $deprecated = '', bool $autoload = true ): bool {
		unset( $deprecated, $autoload );
		if ( isset( $GLOBALS['ogs_test_options'] ) && is_array( $GLOBALS['ogs_test_options'] ) && array_key_exists( $name, $GLOBALS['ogs_test_options'] ) ) {
			return false;
		}
		if ( ! isset( $GLOBALS['ogs_test_options'] ) || ! is_array( $GLOBALS['ogs_test_options'] ) ) {
			$GLOBALS['ogs_test_options'] = array();
		}
		$GLOBALS['ogs_test_options'][ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $name, $value, bool $autoload = true ): bool {
		unset( $autoload );
		if ( ! isset( $GLOBALS['ogs_test_options'] ) || ! is_array( $GLOBALS['ogs_test_options'] ) ) {
			$GLOBALS['ogs_test_options'] = array();
		}
		$GLOBALS['ogs_test_options'][ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $name ): bool {
		if ( isset( $GLOBALS['ogs_test_options'] ) && is_array( $GLOBALS['ogs_test_options'] ) ) {
			unset( $GLOBALS['ogs_test_options'][ $name ] );
		}
		return true;
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( int $post_id, string $key = '', bool $single = false ) {
		$post_id = absint( $post_id );
		$context_meta = isset( $GLOBALS['ogs_content_controls_context']['post_meta'][ $post_id ] ) && is_array( $GLOBALS['ogs_content_controls_context']['post_meta'][ $post_id ] )
			? $GLOBALS['ogs_content_controls_context']['post_meta'][ $post_id ]
			: array();
		$meta         = isset( $GLOBALS['ogs_test_post_meta'][ $post_id ] ) && is_array( $GLOBALS['ogs_test_post_meta'][ $post_id ] )
			? array_merge( $context_meta, $GLOBALS['ogs_test_post_meta'][ $post_id ] )
			: $context_meta;
		if ( '' === $key ) {
			return $meta;
		}
		$value = $meta[ $key ] ?? ( $single ? '' : array() );
		if ( $single ) {
			return is_array( $value ) ? reset( $value ) : $value;
		}
		return is_array( $value ) ? $value : array( $value );
	}
}

if ( ! function_exists( 'get_site_option' ) ) {
	function get_site_option( string $name, $default = false ) {
		if ( isset( $GLOBALS['ogs_test_site_options'] ) && is_array( $GLOBALS['ogs_test_site_options'] ) && array_key_exists( $name, $GLOBALS['ogs_test_site_options'] ) ) {
			return $GLOBALS['ogs_test_site_options'][ $name ];
		}
		return $default;
	}
}

if ( ! function_exists( 'get_current_blog_id' ) ) {
	function get_current_blog_id(): int {
		return 1;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $transient ) {
		$store = $GLOBALS['ogs_test_transients'] ?? array();
		return $store[ $transient ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $transient, $value, int $expiration = 0 ): bool {
		unset( $expiration );
		if ( ! isset( $GLOBALS['ogs_test_transients'] ) || ! is_array( $GLOBALS['ogs_test_transients'] ) ) {
			$GLOBALS['ogs_test_transients'] = array();
		}
		$GLOBALS['ogs_test_transients'][ $transient ] = $value;
		return true;
	}
}

if ( ! function_exists( 'get_post_types' ) ) {
	function get_post_types( array $args = array(), string $output = 'names' ): array {
		unset( $args );
		$types = isset( $GLOBALS['ogs_test_post_types'] ) && is_array( $GLOBALS['ogs_test_post_types'] )
			? $GLOBALS['ogs_test_post_types']
			: array( 'post' => 'post', 'page' => 'page' );
		if ( 'objects' !== $output ) {
			return $types;
		}
		$objects = array();
		foreach ( $types as $post_type ) {
			$post_type = sanitize_key( (string) $post_type );
			$objects[ $post_type ] = get_post_type_object( $post_type );
		}
		return $objects;
	}
}

if ( ! function_exists( 'get_taxonomies' ) ) {
	function get_taxonomies( array $args = array(), string $output = 'names' ): array {
		unset( $args, $output );
		if ( isset( $GLOBALS['ogs_test_taxonomies'] ) && is_array( $GLOBALS['ogs_test_taxonomies'] ) ) {
			return $GLOBALS['ogs_test_taxonomies'];
		}
		return array( 'category' => 'category', 'post_tag' => 'post_tag' );
	}
}

if ( ! function_exists( 'get_object_taxonomies' ) ) {
	function get_object_taxonomies( $object_type, string $output = 'names' ): array {
		unset( $output );
		$map = isset( $GLOBALS['ogs_test_object_taxonomies'] ) && is_array( $GLOBALS['ogs_test_object_taxonomies'] )
			? $GLOBALS['ogs_test_object_taxonomies']
			: array();
		if ( is_array( $object_type ) ) {
			$object_type = reset( $object_type );
		}
		$key = sanitize_key( (string) $object_type );
		if ( isset( $map[ $key ] ) && is_array( $map[ $key ] ) ) {
			return $map[ $key ];
		}
		return array( 'category', 'post_tag' );
	}
}

if ( ! function_exists( 'get_post_type_object' ) ) {
	function get_post_type_object( string $post_type ) {
		$post_type = sanitize_key( $post_type );
		$map       = isset( $GLOBALS['ogs_test_post_type_objects'] ) && is_array( $GLOBALS['ogs_test_post_type_objects'] )
			? $GLOBALS['ogs_test_post_type_objects']
			: array();
		if ( isset( $map[ $post_type ] ) && is_object( $map[ $post_type ] ) ) {
			return $map[ $post_type ];
		}
		if ( in_array( $post_type, array( 'post', 'page', 'product' ), true ) ) {
			return (object) array(
				'name'   => $post_type,
				'label'  => ucfirst( $post_type ),
				'public' => true,
			);
		}
		return null;
	}
}

if ( ! function_exists( 'wp_count_posts' ) ) {
	function wp_count_posts( string $post_type = 'post' ) {
		$counts = $GLOBALS['ogs_test_post_counts'][ $post_type ] ?? 0;
		return (object) array( 'publish' => (int) $counts );
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( string $show = '' ): string {
		if ( 'version' === $show ) {
			return '6.8';
		}
		if ( 'name' === $show ) {
			return 'Open Growth Site';
		}
		if ( 'description' === $show ) {
			return 'Test site description';
		}
		return '';
	}
}

if ( ! function_exists( 'get_locale' ) ) {
	function get_locale(): string {
		return (string) ( $GLOBALS['ogs_test_locale'] ?? 'en_US' );
	}
}

if ( ! function_exists( 'wp_get_document_title' ) ) {
	function wp_get_document_title(): string {
		return (string) ( $GLOBALS['ogs_test_document_title'] ?? 'Open Growth Document' );
	}
}

if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( int $post_id = 0 ): string {
		$post_id = absint( $post_id );
		if ( isset( $GLOBALS['ogs_content_controls_context']['titles'][ $post_id ] ) ) {
			return (string) $GLOBALS['ogs_content_controls_context']['titles'][ $post_id ];
		}
		return (string) ( $GLOBALS['ogs_test_post_titles'][ $post_id ] ?? 'Test Post Title' );
	}
}

if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( int $post_id = 0 ): string {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 ) {
			return (string) ( $GLOBALS['ogs_test_current_post_type'] ?? 'post' );
		}
		if ( isset( $GLOBALS['ogs_content_controls_context']['post_types'][ $post_id ] ) ) {
			return (string) $GLOBALS['ogs_content_controls_context']['post_types'][ $post_id ];
		}
		return (string) ( $GLOBALS['ogs_test_post_types_map'][ $post_id ] ?? 'post' );
	}
}

if ( ! function_exists( 'get_post_field' ) ) {
	function get_post_field( string $field, int $post_id = 0 ) {
		$post_id = absint( $post_id );
		$context_fields = isset( $GLOBALS['ogs_content_controls_context']['post_fields'][ $post_id ] ) && is_array( $GLOBALS['ogs_content_controls_context']['post_fields'][ $post_id ] )
			? $GLOBALS['ogs_content_controls_context']['post_fields'][ $post_id ]
			: array();
		$fields         = isset( $GLOBALS['ogs_test_post_fields'][ $post_id ] ) && is_array( $GLOBALS['ogs_test_post_fields'][ $post_id ] )
			? array_merge( $context_fields, $GLOBALS['ogs_test_post_fields'][ $post_id ] )
			: $context_fields;
		return $fields[ $field ] ?? '';
	}
}

if ( ! function_exists( 'get_post_time' ) ) {
	function get_post_time( string $format = 'U', bool $gmt = false, int $post_id = 0 ) {
		unset( $gmt );
		$post_id = absint( $post_id );
		$value   = (string) ( $GLOBALS['ogs_test_post_dates'][ $post_id ]['published'] ?? '2026-01-01T00:00:00+00:00' );
		if ( 'U' === $format ) {
			return strtotime( $value );
		}
		return $value;
	}
}

if ( ! function_exists( 'get_post_modified_time' ) ) {
	function get_post_modified_time( string $format = 'U', bool $gmt = false, int $post_id = 0 ) {
		unset( $gmt );
		$post_id = absint( $post_id );
		$value   = (string) ( $GLOBALS['ogs_test_post_dates'][ $post_id ]['modified'] ?? '2026-01-02T00:00:00+00:00' );
		if ( 'U' === $format ) {
			return strtotime( $value );
		}
		return $value;
	}
}

if ( ! function_exists( 'get_the_author_meta' ) ) {
	function get_the_author_meta( string $field, int $user_id = 0 ): string {
		$user_id = absint( $user_id );
		$users   = isset( $GLOBALS['ogs_test_users'][ $user_id ] ) && is_array( $GLOBALS['ogs_test_users'][ $user_id ] )
			? $GLOBALS['ogs_test_users'][ $user_id ]
			: array();
		return (string) ( $users[ $field ] ?? ( 'display_name' === $field ? 'Test Author' : '' ) );
	}
}

if ( ! function_exists( 'get_author_posts_url' ) ) {
	function get_author_posts_url( int $user_id ): string {
		return 'https://example.com/author/' . absint( $user_id ) . '/';
	}
}

if ( ! function_exists( 'get_avatar_url' ) ) {
	function get_avatar_url( int $user_id, array $args = array() ): string {
		unset( $args );
		return 'https://example.com/avatar/' . absint( $user_id ) . '.jpg';
	}
}

if ( ! function_exists( 'get_the_post_thumbnail_url' ) ) {
	function get_the_post_thumbnail_url( int $post_id = 0, string $size = 'post-thumbnail' ) {
		unset( $size );
		$post_id = absint( $post_id );
		return $GLOBALS['ogs_test_post_thumbnails'][ $post_id ] ?? false;
	}
}

if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( int $post_id = 0 ) {
		$post_id = absint( $post_id );
		$context_permalinks = isset( $GLOBALS['ogs_content_controls_context']['permalinks'] ) && is_array( $GLOBALS['ogs_content_controls_context']['permalinks'] )
			? $GLOBALS['ogs_content_controls_context']['permalinks']
			: array();
		if ( isset( $context_permalinks[ $post_id ] ) ) {
			return (string) $context_permalinks[ $post_id ];
		}
		$permalinks = isset( $GLOBALS['ogs_test_permalinks'] ) && is_array( $GLOBALS['ogs_test_permalinks'] )
			? $GLOBALS['ogs_test_permalinks']
			: array();
		if ( isset( $permalinks[ $post_id ] ) ) {
			return (string) $permalinks[ $post_id ];
		}
		return 'https://example.com/post-' . $post_id . '/';
	}
}

if ( ! function_exists( 'get_edit_post_link' ) ) {
	function get_edit_post_link( int $post_id = 0 ) {
		$post_id = absint( $post_id );
		return 'https://example.com/wp-admin/post.php?post=' . $post_id . '&action=edit';
	}
}

if ( ! function_exists( 'wp_trim_words' ) ) {
	function wp_trim_words( string $text, int $num_words = 55, string $more = null ): string {
		unset( $more );
		$words = preg_split( '/\s+/', trim( wp_strip_all_tags( $text ) ) ) ?: array();
		if ( count( $words ) <= $num_words ) {
			return implode( ' ', $words );
		}
		return implode( ' ', array_slice( $words, 0, $num_words ) );
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return (int) ( $GLOBALS['ogs_test_current_user_id'] ?? 1 );
	}
}

if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite(): bool {
		return ! empty( $GLOBALS['ogs_test_is_multisite'] );
	}
}

if ( ! function_exists( 'wp_get_theme' ) ) {
	function wp_get_theme() {
		$data = isset( $GLOBALS['ogs_test_theme'] ) && is_array( $GLOBALS['ogs_test_theme'] ) ? $GLOBALS['ogs_test_theme'] : array();
		return new class( $data ) {
			private array $data;

			public function __construct( array $data ) {
				$this->data = $data;
			}

			public function get( string $key ): string {
				$map = array(
					'Name'    => 'name',
					'Version' => 'version',
				);
				$lookup = $map[ $key ] ?? strtolower( $key );
				return (string) ( $this->data[ $lookup ] ?? '' );
			}

			public function get_template(): string {
				return (string) ( $this->data['template'] ?? '' );
			}
		};
	}
}

if ( ! function_exists( 'get_site_icon_url' ) ) {
	function get_site_icon_url(): string {
		return (string) ( $GLOBALS['ogs_test_site_icon_url'] ?? '' );
	}
}

if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir(): array {
		return isset( $GLOBALS['ogs_test_upload_dir'] ) && is_array( $GLOBALS['ogs_test_upload_dir'] )
			? $GLOBALS['ogs_test_upload_dir']
			: array( 'basedir' => ABSPATH . 'wp-content/uploads' );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook_name, $value, ...$args ) {
		$callbacks = $GLOBALS['ogs_test_filters'][ $hook_name ] ?? array();
		foreach ( (array) $callbacks as $callback ) {
			$value = $callback( $value, ...$args );
		}
		return $value;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		unset( $priority, $accepted_args );
		if ( ! isset( $GLOBALS['ogs_test_filters'] ) || ! is_array( $GLOBALS['ogs_test_filters'] ) ) {
			$GLOBALS['ogs_test_filters'] = array();
		}
		if ( ! isset( $GLOBALS['ogs_test_filters'][ $hook_name ] ) || ! is_array( $GLOBALS['ogs_test_filters'][ $hook_name ] ) ) {
			$GLOBALS['ogs_test_filters'][ $hook_name ] = array();
		}
		$GLOBALS['ogs_test_filters'][ $hook_name ][] = $callback;
		return true;
	}
}

if ( ! function_exists( 'remove_all_filters' ) ) {
	function remove_all_filters( string $hook_name ): bool {
		if ( isset( $GLOBALS['ogs_test_filters'] ) && is_array( $GLOBALS['ogs_test_filters'] ) ) {
			unset( $GLOBALS['ogs_test_filters'][ $hook_name ] );
		}
		return true;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook_name, ...$args ): void {
		$callbacks = $GLOBALS['ogs_test_actions'][ $hook_name ] ?? array();
		foreach ( (array) $callbacks as $callback ) {
			$callback( ...$args );
		}
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		unset( $priority, $accepted_args );
		if ( ! isset( $GLOBALS['ogs_test_actions'] ) || ! is_array( $GLOBALS['ogs_test_actions'] ) ) {
			$GLOBALS['ogs_test_actions'] = array();
		}
		if ( ! isset( $GLOBALS['ogs_test_actions'][ $hook_name ] ) || ! is_array( $GLOBALS['ogs_test_actions'][ $hook_name ] ) ) {
			$GLOBALS['ogs_test_actions'][ $hook_name ] = array();
		}
		$GLOBALS['ogs_test_actions'][ $hook_name ][] = $callback;
		return true;
	}
}

if ( ! function_exists( 'add_shortcode' ) ) {
	function add_shortcode( string $tag, callable $callback ): bool {
		if ( ! isset( $GLOBALS['ogs_test_shortcodes'] ) || ! is_array( $GLOBALS['ogs_test_shortcodes'] ) ) {
			$GLOBALS['ogs_test_shortcodes'] = array();
		}

		$GLOBALS['ogs_test_shortcodes'][ $tag ] = $callback;
		return true;
	}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( string $namespace, string $route, array $args, bool $override = false ): bool {
		unset( $override );
		if ( ! isset( $GLOBALS['ogs_test_rest_routes'] ) || ! is_array( $GLOBALS['ogs_test_rest_routes'] ) ) {
			$GLOBALS['ogs_test_rest_routes'] = array();
		}
		$GLOBALS['ogs_test_rest_routes'][] = array(
			'namespace' => $namespace,
			'route'     => $route,
			'args'      => $args,
		);
		return true;
	}
}

if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( array $args ): array {
		if ( isset( $GLOBALS['ogs_test_get_posts_callback'] ) && is_callable( $GLOBALS['ogs_test_get_posts_callback'] ) ) {
			return (array) call_user_func( $GLOBALS['ogs_test_get_posts_callback'], $args );
		}
		unset( $args );
		return array();
	}
}

if ( ! function_exists( 'get_post' ) ) {
	function get_post( int $post_id ) {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 ) {
			return null;
		}
		$status = (string) ( $GLOBALS['ogs_test_post_statuses'][ $post_id ] ?? 'publish' );
		$type   = (string) ( $GLOBALS['ogs_test_post_types_map'][ $post_id ] ?? 'post' );
		$modified = (string) ( $GLOBALS['ogs_test_post_modified'][ $post_id ] ?? gmdate( 'Y-m-d H:i:s' ) );
		return (object) array(
			'ID'                => $post_id,
			'post_status'       => $status,
			'post_type'         => $type,
			'post_modified_gmt' => $modified,
		);
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	function is_admin(): bool {
		return ! empty( $GLOBALS['ogs_test_is_admin'] );
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability ): bool {
		$caps = isset( $GLOBALS['ogs_test_current_user_caps'] ) && is_array( $GLOBALS['ogs_test_current_user_caps'] )
			? $GLOBALS['ogs_test_current_user_caps']
			: array( 'manage_options' => true );
		return ! empty( $caps[ $capability ] );
	}
}

if ( ! function_exists( 'get_current_screen' ) ) {
	function get_current_screen() {
		$screen_id = isset( $GLOBALS['ogs_test_current_screen_id'] ) ? (string) $GLOBALS['ogs_test_current_screen_id'] : '';
		if ( '' === $screen_id ) {
			return null;
		}

		return (object) array( 'id' => $screen_id );
	}
}

if ( ! function_exists( 'is_feed' ) ) {
	function is_feed(): bool {
		return false;
	}
}

if ( ! function_exists( 'wp_doing_ajax' ) ) {
	function wp_doing_ajax(): bool {
		return ! empty( $GLOBALS['ogs_test_doing_ajax'] );
	}
}

if ( ! function_exists( 'wp_is_json_request' ) ) {
	function wp_is_json_request(): bool {
		return ! empty( $GLOBALS['ogs_test_is_json_request'] );
	}
}

if ( ! function_exists( 'wp_using_ext_object_cache' ) ) {
	function wp_using_ext_object_cache(): bool {
		return ! empty( $GLOBALS['ogs_test_ext_object_cache'] );
	}
}

if ( ! function_exists( 'status_header' ) ) {
	function status_header( int $code ): void {
		$GLOBALS['ogs_test_status_header'] = $code;
	}
}

if ( ! function_exists( 'nocache_headers' ) ) {
	function nocache_headers(): void {
		$GLOBALS['ogs_test_nocache_headers'] = true;
	}
}

if ( ! function_exists( 'get_the_ID' ) ) {
	function get_the_ID(): int {
		return (int) ( $GLOBALS['ogs_test_current_post_id'] ?? 0 );
	}
}

if ( ! function_exists( 'is_paged' ) ) {
	function is_paged(): bool {
		return false;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( string $url ): string {
		return esc_url_raw( $url );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, ?string $domain = null ): string {
		return esc_html( __( $text, $domain ) );
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '' ): string {
		return 'https://example.com/wp-admin/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( string $scheme = 'auth' ): string {
		return 'ogs-test-salt-' . $scheme;
	}
}

if ( ! function_exists( 'add_rewrite_rule' ) ) {
	function add_rewrite_rule( string $regex, string $query, string $after = 'bottom' ): bool {
		unset( $regex, $query, $after );
		return true;
	}
}

if ( ! function_exists( 'flush_rewrite_rules' ) ) {
	function flush_rewrite_rules( bool $hard = true ): bool {
		unset( $hard );
		return true;
	}
}

if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	function wp_clear_scheduled_hook( string $hook ): bool {
		if ( isset( $GLOBALS['ogs_test_scheduled_events'] ) && is_array( $GLOBALS['ogs_test_scheduled_events'] ) ) {
			$GLOBALS['ogs_test_scheduled_events'] = array_values(
				array_filter(
					$GLOBALS['ogs_test_scheduled_events'],
					static function ( array $event ) use ( $hook ): bool {
						return (string) ( $event['hook'] ?? '' ) !== $hook;
					}
				)
			);
		}
		return true;
	}
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( string $hook ) {
		$events = isset( $GLOBALS['ogs_test_scheduled_events'] ) && is_array( $GLOBALS['ogs_test_scheduled_events'] )
			? $GLOBALS['ogs_test_scheduled_events']
			: array();
		$times = array();
		foreach ( $events as $event ) {
			if ( (string) ( $event['hook'] ?? '' ) === $hook ) {
				$times[] = (int) ( $event['timestamp'] ?? 0 );
			}
		}
		if ( empty( $times ) ) {
			return false;
		}
		sort( $times );
		return $times[0];
	}
}

if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( int $timestamp, string $recurrence, string $hook ): bool {
		if ( ! isset( $GLOBALS['ogs_test_scheduled_events'] ) || ! is_array( $GLOBALS['ogs_test_scheduled_events'] ) ) {
			$GLOBALS['ogs_test_scheduled_events'] = array();
		}
		$GLOBALS['ogs_test_scheduled_events'][] = array(
			'timestamp'  => $timestamp,
			'recurrence' => $recurrence,
			'hook'       => $hook,
		);
		return true;
	}
}

if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( int $timestamp, string $hook ): bool {
		if ( ! isset( $GLOBALS['ogs_test_scheduled_events'] ) || ! is_array( $GLOBALS['ogs_test_scheduled_events'] ) ) {
			$GLOBALS['ogs_test_scheduled_events'] = array();
		}
		$GLOBALS['ogs_test_scheduled_events'][] = array(
			'timestamp'  => $timestamp,
			'recurrence' => 'single',
			'hook'       => $hook,
		);
		return true;
	}
}

if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( int $length = 12, bool $special_chars = true, bool $extra_special_chars = false ): string {
		unset( $special_chars, $extra_special_chars );
		$counter = isset( $GLOBALS['ogs_test_password_counter'] ) ? (int) $GLOBALS['ogs_test_password_counter'] + 1 : 1;
		$GLOBALS['ogs_test_password_counter'] = $counter;
		return substr( str_repeat( 'abc123xyz' . $counter, 10 ), 0, max( 1, $length ) );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, int $flags = 0, int $depth = 512 ) {
		return json_encode( $value, $flags, $depth );
	}
}

if ( ! function_exists( 'rest_sanitize_boolean' ) ) {
	function rest_sanitize_boolean( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_numeric( $value ) ) {
			return (bool) (int) $value;
		}
		$value = strtolower( trim( (string) $value ) );
		return in_array( $value, array( '1', 'true', 'yes', 'on' ), true );
	}
}

if ( ! function_exists( 'rest_ensure_response' ) ) {
	function rest_ensure_response( $value ) {
		if ( $value instanceof WP_REST_Response ) {
			return $value;
		}
		return new WP_REST_Response( $value, 200 );
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private array $params;

		public function __construct( array $params = array() ) {
			$this->params = $params;
		}

		public function get_param( string $key ) {
			return $this->params[ $key ] ?? null;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		private $data;
		private int $status;

		public function __construct( $data = null, int $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}

		public function get_data() {
			return $this->data;
		}

		public function get_status(): int {
			return $this->status;
		}
	}
}

if ( ! function_exists( 'parse_blocks' ) ) {
	function parse_blocks( string $content ): array {
		$pattern = '/<!--\s+wp:([a-z0-9\-\/]+)(?:\s+(\{.*?\}))?\s+-->(.*?)<!--\s+\/wp:\1\s+-->/is';
		if ( ! preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER ) ) {
			return array();
		}

		$blocks = array();
		foreach ( $matches as $match ) {
			$attrs = array();
			if ( ! empty( $match[2] ) ) {
				$decoded = json_decode( (string) $match[2], true );
				$attrs   = is_array( $decoded ) ? $decoded : array();
			}
			$blocks[] = array(
				'blockName'   => (string) ( $match[1] ?? '' ),
				'attrs'       => $attrs,
				'innerHTML'   => (string) ( $match[3] ?? '' ),
				'innerBlocks' => array(),
			);
		}
		return $blocks;
	}
}

require dirname( __DIR__, 2 ) . '/includes/Support/Autoloader.php';
\OpenGrowthSolutions\OpenGrowthSEO\Support\Autoloader::register();
