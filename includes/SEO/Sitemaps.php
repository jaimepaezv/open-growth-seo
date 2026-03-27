<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\SEO;

use OpenGrowthSolutions\OpenGrowthSEO\Support\Settings;

defined( 'ABSPATH' ) || exit;

class Sitemaps {
	private const QUERY_VAR_TYPE       = 'ogs_sitemap';
	private const QUERY_VAR_PAGE       = 'ogs_sitemap_page';
	private const CACHE_VERSION_OPTION = 'ogs_seo_sitemap_cache_version';
	private const URLS_PER_SITEMAP     = 500;
	private const INDEX_CACHE_PREFIX   = 'ogs_sitemap_index_';
	private const MAP_CACHE_PREFIX     = 'ogs_sitemap_map_';
	private const CACHE_TTL            = 6 * HOUR_IN_SECONDS;

	public function register(): void {
		add_action( 'init', array( $this, 'rewrite' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'template_redirect', array( $this, 'render' ) );
		add_action( 'save_post', array( $this, 'maybe_purge_cache_for_post' ), 10, 2 );
		add_action( 'deleted_post', array( $this, 'maybe_purge_cache_for_post_id' ) );
		add_action( 'trashed_post', array( $this, 'maybe_purge_cache_for_post_id' ) );
		add_action( 'transition_post_status', array( $this, 'maybe_purge_on_status_transition' ), 10, 3 );
		add_action( 'updated_post_meta', array( $this, 'maybe_purge_cache_on_meta_change' ), 10, 4 );
		add_action( 'added_post_meta', array( $this, 'maybe_purge_cache_on_meta_change' ), 10, 4 );
		add_action( 'deleted_post_meta', array( $this, 'maybe_purge_cache_on_meta_change' ), 10, 4 );
		add_action( 'update_option_ogs_seo_settings', array( $this, 'maybe_purge_cache_on_settings_update' ), 10, 2 );
		add_filter( 'ogs_seo_audit_checks', array( $this, 'register_audit_checks' ) );
	}

	public function rewrite(): void {
		add_rewrite_rule( '^ogs-sitemap\\.xml$', 'index.php?' . self::QUERY_VAR_TYPE . '=index', 'top' );
		add_rewrite_rule( '^ogs-sitemap-([a-z0-9_-]+)\\.xml$', 'index.php?' . self::QUERY_VAR_TYPE . '=$matches[1]&' . self::QUERY_VAR_PAGE . '=1', 'top' );
		add_rewrite_rule( '^ogs-sitemap-([a-z0-9_-]+)-([0-9]+)\\.xml$', 'index.php?' . self::QUERY_VAR_TYPE . '=$matches[1]&' . self::QUERY_VAR_PAGE . '=$matches[2]', 'top' );
	}

	public function query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR_TYPE;
		$vars[] = self::QUERY_VAR_PAGE;
		return $vars;
	}

	public function render(): void {
		$target = (string) get_query_var( self::QUERY_VAR_TYPE );
		if ( '' === $target || ! Settings::get( 'sitemap_enabled', 1 ) ) {
			return;
		}

		nocache_headers();
		header( 'Content-Type: application/xml; charset=UTF-8' );

		if ( 'index' === $target ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML payload is generated with escaped nodes in index_xml().
			echo $this->index_xml();
			exit;
		}

		$post_type = sanitize_key( $target );
		$page      = max( 1, absint( get_query_var( self::QUERY_VAR_PAGE, 1 ) ) );
		if ( ! $this->is_allowed_post_type( $post_type ) ) {
			status_header( 404 );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML payload is static empty sitemap template.
			echo $this->empty_urlset();
			exit;
		}
		$total_pages = $this->total_pages_for_post_type( $post_type );
		if ( $total_pages < 1 || $page > $total_pages ) {
			status_header( 404 );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML payload is static empty sitemap template.
			echo $this->empty_urlset();
			exit;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML payload is generated with escaped nodes in type_xml().
		echo $this->type_xml( $post_type, $page );
		exit;
	}

	private function index_xml(): string {
		$cache_key = self::INDEX_CACHE_PREFIX . $this->cache_version();
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) ) {
			return $cached;
		}
		$post_types = $this->allowed_post_types();
		$xml        = '<?xml version="1.0" encoding="UTF-8"?>';
		$xml       .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

		foreach ( $post_types as $post_type ) {
			$total_pages = $this->total_pages_for_post_type( $post_type );
			if ( $total_pages < 1 ) {
				continue;
			}
			for ( $page = 1; $page <= $total_pages; $page++ ) {
				$lastmod = $this->page_lastmod( $post_type, $page );
				$xml .= '<sitemap><loc>' . esc_url( $this->sitemap_url( $post_type, $page ) ) . '</loc>';
				if ( '' !== $lastmod ) {
					$xml .= '<lastmod>' . esc_html( $lastmod ) . '</lastmod>';
				}
				$xml .= '</sitemap>';
			}
		}
		$xml .= '</sitemapindex>';
		set_transient( $cache_key, $xml, self::CACHE_TTL );
		return $xml;
	}

	private function type_xml( string $post_type, int $page ): string {
		$cache_key = $this->cache_key( $post_type, $page );
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) ) {
			return $cached;
		}

		$page_map = $this->page_map_for_post_type( $post_type );
		$post_ids = isset( $page_map[ $page - 1 ] ) && is_array( $page_map[ $page - 1 ] ) ? $page_map[ $page - 1 ] : array();

		$xml = '<?xml version="1.0" encoding="UTF-8"?>';
		$rows      = array();
		$has_image = false;

		foreach ( $post_ids as $post_id ) {
			$post_id = (int) $post_id;
			$permalink = get_permalink( $post_id );
			if ( ! $permalink ) {
				continue;
			}
			$image_xml = $this->image_xml( $post_id );
			if ( '' !== $image_xml ) {
				$has_image = true;
			}
			$row  = '<url><loc>' . esc_url( $permalink ) . '</loc>';
			$row .= '<lastmod>' . esc_html( (string) get_post_modified_time( DATE_W3C, true, $post_id ) ) . '</lastmod>';
			$row .= $image_xml;
			$row .= '</url>';
			$rows[] = $row;
		}
		$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';
		if ( $has_image ) {
			$xml .= ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"';
		}
		$xml .= '>';
		$xml .= implode( '', $rows );
		$xml .= '</urlset>';
		set_transient( $cache_key, $xml, self::CACHE_TTL );
		return $xml;
	}

	public function purge_cache(): void {
		update_option( self::CACHE_VERSION_OPTION, (string) time(), false );
	}

	private function allowed_post_types(): array {
		$configured = (array) Settings::get( 'sitemap_post_types', array( 'post', 'page' ) );
		$allowed    = array();
		foreach ( $configured as $post_type ) {
			$post_type = sanitize_key( (string) $post_type );
			$object    = get_post_type_object( $post_type );
			if ( ! $object || ! $object->public ) {
				continue;
			}
			$allowed[] = $post_type;
		}
		return array_values( array_unique( $allowed ) );
	}

	private function is_allowed_post_type( string $post_type ): bool {
		return in_array( $post_type, $this->allowed_post_types(), true );
	}

	private function total_pages_for_post_type( string $post_type ): int {
		return count( $this->page_map_for_post_type( $post_type ) );
	}

	private function sitemap_url( string $post_type, int $page ): string {
		if ( $page <= 1 ) {
			return home_url( '/ogs-sitemap-' . $post_type . '.xml' );
		}
		return home_url( '/ogs-sitemap-' . $post_type . '-' . $page . '.xml' );
	}

	private function should_include_post( int $post_id ): bool {
		if ( '' !== (string) get_post_field( 'post_password', $post_id ) ) {
			return false;
		}
		$post_type = get_post_type( $post_id );
		if ( ! $post_type || ! $this->is_allowed_post_type( $post_type ) ) {
			return false;
		}
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return false;
		}
		$robots = (string) get_post_meta( $post_id, 'ogs_seo_robots', true );
		if ( '' === $robots ) {
			$settings  = Settings::get_all();
			$post_type = get_post_type( $post_id ) ?: 'post';
			$robots    = isset( $settings['post_type_robots_defaults'][ $post_type ] ) ? (string) $settings['post_type_robots_defaults'][ $post_type ] : (string) $settings['default_index'];
		}
		if ( false !== strpos( strtolower( $robots ), 'noindex' ) ) {
			return false;
		}
		if ( Settings::get( 'canonical_enabled', 1 ) ) {
			$canonical_override = $this->normalize_url( (string) get_post_meta( $post_id, 'ogs_seo_canonical', true ) );
			if ( '' !== $canonical_override ) {
				$permalink = $this->normalize_url( (string) get_permalink( $post_id ) );
				if ( '' !== $permalink && $canonical_override !== $permalink ) {
					return false;
				}
			}
		}
		return true;
	}

	private function post_type_lastmod( string $post_type ): string {
		return $this->page_lastmod( $post_type, 1 );
	}

	private function page_lastmod( string $post_type, int $page ): string {
		$page_map = $this->page_map_for_post_type( $post_type );
		$index    = max( 0, $page - 1 );
		if ( isset( $page_map[ $index ][0] ) ) {
			return (string) get_post_modified_time( DATE_W3C, true, (int) $page_map[ $index ][0] );
		}
		return '';
	}

	private function total_urls_for_post_type( string $post_type ): int {
		$page_map = $this->page_map_for_post_type( $post_type );
		$total    = 0;
		foreach ( $page_map as $ids ) {
			if ( is_array( $ids ) ) {
				$total += count( $ids );
			}
		}
		return $total;
	}

	private function image_xml( int $post_id ): string {
		$thumbnail_id = get_post_thumbnail_id( $post_id );
		if ( ! $thumbnail_id ) {
			return '';
		}
		$image_url = wp_get_attachment_image_url( $thumbnail_id, 'full' );
		if ( ! $image_url ) {
			return '';
		}
		return '<image:image><image:loc>' . esc_url( $image_url ) . '</image:loc></image:image>';
	}

	private function cache_key( string $post_type, int $page ): string {
		return 'ogs_sitemap_' . get_current_blog_id() . '_' . $post_type . '_' . $page . '_' . $this->cache_version();
	}

	private function map_cache_key( string $post_type ): string {
		return self::MAP_CACHE_PREFIX . get_current_blog_id() . '_' . $post_type . '_' . $this->cache_version();
	}

	private function page_map_for_post_type( string $post_type ): array {
		$cache_key = $this->map_cache_key( $post_type );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$pages        = array();
		$current_page = array();
		$paged        = 1;

		while ( true ) {
			$post_ids = get_posts(
				array(
					'post_type'              => $post_type,
					'post_status'            => 'publish',
					'posts_per_page'         => self::URLS_PER_SITEMAP,
					'paged'                  => $paged,
					'orderby'                => 'modified',
					'order'                  => 'DESC',
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'ignore_sticky_posts'    => true,
					'update_post_meta_cache' => true,
					'update_post_term_cache' => false,
				)
			);
			if ( empty( $post_ids ) ) {
				break;
			}
			foreach ( $post_ids as $post_id ) {
				$post_id = (int) $post_id;
				if ( ! $this->should_include_post( $post_id ) ) {
					continue;
				}
				$current_page[] = $post_id;
				if ( count( $current_page ) >= self::URLS_PER_SITEMAP ) {
					$pages[]      = $current_page;
					$current_page = array();
				}
			}
			++$paged;
		}

		if ( ! empty( $current_page ) ) {
			$pages[] = $current_page;
		}

		set_transient( $cache_key, $pages, self::CACHE_TTL );
		return $pages;
	}

	private function empty_urlset(): string {
		return '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>';
	}

	public function register_audit_checks( array $checks ): array {
		$checks['sitemap_post_type_config'] = array( $this, 'audit_post_type_config' );
		$checks['sitemap_runtime_consistency'] = array( $this, 'audit_runtime_consistency' );
		return $checks;
	}

	public function audit_post_type_config(): array {
		if ( ! Settings::get( 'sitemap_enabled', 1 ) ) {
			return array();
		}
		if ( empty( $this->allowed_post_types() ) ) {
			return array(
				'severity'       => 'important',
				'title'          => __( 'Sitemap has no eligible post types', 'open-growth-seo' ),
				'recommendation' => __( 'Select at least one public post type in Open Growth SEO > Sitemaps.', 'open-growth-seo' ),
			);
		}
		return array();
	}

	public function audit_runtime_consistency(): array {
		if ( ! Settings::get( 'sitemap_enabled', 1 ) ) {
			return array();
		}
		$index = $this->index_xml();
		if ( false === strpos( $index, '<sitemapindex' ) ) {
			return array(
				'severity'       => 'critical',
				'title'          => __( 'Sitemap index XML is invalid', 'open-growth-seo' ),
				'recommendation' => __( 'Check sitemap rewrite rules and XML output for malformed content.', 'open-growth-seo' ),
			);
		}
		return array();
	}

	public function inspect(): array {
		$post_types = $this->allowed_post_types();
		$items      = array();
		foreach ( $post_types as $post_type ) {
			$total_pages = $this->total_pages_for_post_type( $post_type );
			$items[]     = array(
				'post_type'   => $post_type,
				'pages'       => $total_pages,
				'urls'        => $this->total_urls_for_post_type( $post_type ),
				'lastmod'     => $this->post_type_lastmod( $post_type ),
				'sitemap_url' => $this->sitemap_url( $post_type, 1 ),
			);
		}
		return array(
			'enabled'       => (bool) Settings::get( 'sitemap_enabled', 1 ),
			'index_url'     => home_url( '/ogs-sitemap.xml' ),
			'cache_version' => $this->cache_version(),
			'post_types'    => $items,
		);
	}

	public function maybe_purge_cache_for_post( int $post_id, \WP_Post $post ): void {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! $this->can_post_affect_sitemaps( $post ) ) {
			return;
		}
		$this->purge_cache();
	}

	public function maybe_purge_cache_for_post_id( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post || ! $this->can_post_affect_sitemaps( $post ) ) {
			return;
		}
		$this->purge_cache();
	}

	public function maybe_purge_on_status_transition( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( $new_status === $old_status ) {
			return;
		}
		if ( ! $this->can_post_affect_sitemaps( $post ) ) {
			return;
		}
		$relevant = array( 'publish', 'private', 'draft', 'pending', 'future', 'trash' );
		if ( in_array( $new_status, $relevant, true ) || in_array( $old_status, $relevant, true ) ) {
			$this->purge_cache();
		}
	}

	public function maybe_purge_cache_on_meta_change( $meta_id, $post_id, $meta_key, $meta_value ): void {
		unset( $meta_id, $meta_value );
		$post_id  = absint( $post_id );
		$meta_key = sanitize_key( (string) $meta_key );
		if ( $post_id <= 0 ) {
			return;
		}
		if ( ! in_array( $meta_key, array( 'ogs_seo_robots', 'ogs_seo_canonical', '_thumbnail_id' ), true ) ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post || ! $this->can_post_affect_sitemaps( $post ) ) {
			return;
		}
		$this->purge_cache();
	}

	public function maybe_purge_cache_on_settings_update( $old_value, $value ): void {
		if ( ! is_array( $old_value ) || ! is_array( $value ) ) {
			$this->purge_cache();
			return;
		}
		$keys = array(
			'sitemap_enabled',
			'sitemap_post_types',
			'default_index',
			'post_type_robots_defaults',
			'canonical_enabled',
		);
		foreach ( $keys as $key ) {
			$old = $old_value[ $key ] ?? null;
			$new = $value[ $key ] ?? null;
			if ( $old !== $new ) {
				$this->purge_cache();
				return;
			}
		}
	}

	private function can_post_affect_sitemaps( \WP_Post $post ): bool {
		$object = get_post_type_object( $post->post_type );
		return (bool) ( $object && $object->public );
	}

	private function cache_version(): string {
		return (string) get_option( self::CACHE_VERSION_OPTION, '1' );
	}

	private function normalize_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		if ( str_starts_with( $url, '/' ) ) {
			$url = home_url( $url );
		}
		$url = esc_url_raw( $url );
		if ( '' === $url ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		$normalized = strtolower( (string) $parts['scheme'] ) . '://' . strtolower( (string) $parts['host'] );
		if ( ! empty( $parts['port'] ) ) {
			$normalized .= ':' . absint( $parts['port'] );
		}
		$normalized .= isset( $parts['path'] ) && '' !== (string) $parts['path'] ? (string) $parts['path'] : '/';
		if ( ! empty( $parts['query'] ) ) {
			$normalized .= '?' . (string) $parts['query'];
		}
		return $normalized;
	}
}
