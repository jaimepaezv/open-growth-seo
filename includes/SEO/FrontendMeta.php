<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\SEO;

use OpenGrowthSolutions\OpenGrowthSEO\Compatibility\Detector;
use OpenGrowthSolutions\OpenGrowthSEO\Support\Settings;
use OpenGrowthSolutions\OpenGrowthSEO\SEO\CanonicalPolicy;
use OpenGrowthSolutions\OpenGrowthSEO\SEO\LocalSeoLocations;

defined( 'ABSPATH' ) || exit;

class FrontendMeta {
	public function register(): void {
		add_action( 'wp', array( $this, 'maybe_disable_core_canonical' ) );
		add_filter( 'pre_get_document_title', array( $this, 'filter_document_title' ), 20 );
		add_action( 'wp_head', array( $this, 'render_meta' ), 1 );
		add_filter( 'wp_robots', array( $this, 'filter_wp_robots' ) );
		add_action( 'send_headers', array( $this, 'send_x_robots' ) );
		add_filter( 'the_content', array( $this, 'apply_data_nosnippet' ), 20 );
		add_filter( 'ogs_seo_audit_checks', array( $this, 'register_audit_checks' ) );
	}

	public function maybe_disable_core_canonical(): void {
		if ( $this->should_skip_output() ) {
			return;
		}
		if ( Settings::get( 'canonical_enabled', 1 ) ) {
			remove_action( 'wp_head', 'rel_canonical' );
		}
	}

	public function filter_document_title( string $title ): string {
		if ( is_admin() || is_feed() || $this->should_skip_output() ) {
			return $title;
		}
		$settings = Settings::get_all();
		$post_id  = $this->queried_post_id();
		$override = $post_id ? (string) get_post_meta( $post_id, 'ogs_seo_title', true ) : '';
		if ( '' !== trim( $override ) ) {
			return $override;
		}
		if ( $post_id > 0 ) {
			$location = LocalSeoLocations::record_for_post( $post_id, $settings );
			$fallback = isset( $location['seo_title'] ) ? (string) $location['seo_title'] : '';
			if ( '' !== trim( $fallback ) ) {
				return $fallback;
			}
		}
		$template = $this->title_template();
		$resolved = $this->apply_template( $template );
		return '' !== $resolved ? $resolved : $title;
	}

	public function render_meta(): void {
		if ( is_admin() || is_feed() || $this->should_skip_output() ) {
			return;
		}

		$settings  = Settings::get_all();
		$post_id   = $this->queried_post_id();
		$title     = $this->filter_document_title( wp_get_document_title() );
		$seo_desc  = $post_id ? (string) get_post_meta( $post_id, 'ogs_seo_description', true ) : '';
		$social_title = $post_id ? (string) get_post_meta( $post_id, 'ogs_seo_social_title', true ) : '';
		$social_desc  = $post_id ? (string) get_post_meta( $post_id, 'ogs_seo_social_description', true ) : '';
		$social_image = $post_id ? (string) get_post_meta( $post_id, 'ogs_seo_social_image', true ) : '';
		$location     = $post_id > 0 ? LocalSeoLocations::record_for_post( $post_id, $settings ) : array();
		if ( '' === trim( $seo_desc ) && ! empty( $location['seo_description'] ) ) {
			$seo_desc = (string) $location['seo_description'];
		}
		if ( '' === trim( $social_title ) && ! empty( $location['seo_title'] ) ) {
			$social_title = (string) $location['seo_title'];
		}
		if ( '' === trim( $social_desc ) && ! empty( $location['seo_description'] ) ) {
			$social_desc = (string) $location['seo_description'];
		}
		if ( '' === trim( $social_title ) && $this->is_woo_archive_context() && '' !== trim( (string) ( $settings['woo_archive_social_title_template'] ?? '' ) ) ) {
			$social_title = $this->apply_template( (string) $settings['woo_archive_social_title_template'] );
		}
		if ( '' === trim( $social_desc ) && $this->is_woo_archive_context() && '' !== trim( (string) ( $settings['woo_archive_social_description_template'] ?? '' ) ) ) {
			$social_desc = $this->apply_template( (string) $settings['woo_archive_social_description_template'] );
		}
		$desc      = '' !== trim( $seo_desc ) ? $seo_desc : $this->apply_template( $this->description_template() );
		$desc      = '' !== trim( $desc ) ? $desc : wp_strip_all_tags( get_bloginfo( 'description' ) );
		$canonical_policy = $this->canonical_decision( $settings, $post_id, $location );
		$canonical        = (string) ( $canonical_policy['effective'] ?? '' );
		$og_title  = '' !== trim( $social_title ) ? $social_title : $title;
		$og_desc   = '' !== trim( $social_desc ) ? $social_desc : $desc;

		echo '<meta name="description" content="' . esc_attr( $this->trim_snippet( $desc, 160 ) ) . '" />' . "\n";
		if ( ! empty( $canonical ) && $settings['canonical_enabled'] ) {
			echo '<link rel="canonical" href="' . esc_url( $canonical ) . '" />' . "\n";
		}
		if ( (int) $settings['og_enabled'] ) {
			echo '<meta property="og:title" content="' . esc_attr( $og_title ) . '" />' . "\n";
			echo '<meta property="og:description" content="' . esc_attr( $this->trim_snippet( $og_desc, 200 ) ) . '" />' . "\n";
			echo '<meta property="og:type" content="website" />' . "\n";
			echo '<meta property="og:url" content="' . esc_url( $this->current_url() ) . '" />' . "\n";
			if ( '' !== trim( $social_image ) ) {
				echo '<meta property="og:image" content="' . esc_url( $social_image ) . '" />' . "\n";
			}
		}
		if ( (int) $settings['twitter_enabled'] ) {
			echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
			echo '<meta name="twitter:title" content="' . esc_attr( $og_title ) . '" />' . "\n";
			echo '<meta name="twitter:description" content="' . esc_attr( $this->trim_snippet( $og_desc, 200 ) ) . '" />' . "\n";
			if ( '' !== trim( $social_image ) ) {
				echo '<meta name="twitter:image" content="' . esc_url( $social_image ) . '" />' . "\n";
			}
		}
	}

	public function filter_wp_robots( array $robots ): array {
		if ( $this->should_skip_output() ) {
			return $robots;
		}
		$post_id = $this->queried_post_id();
		$custom  = $post_id ? (string) get_post_meta( $post_id, 'ogs_seo_robots', true ) : '';
		$base    = '' !== $custom ? $custom : $this->default_robots_for_context();
		$pair = $this->normalized_robots_pair( $base );
		unset( $robots['index'], $robots['noindex'], $robots['follow'], $robots['nofollow'] );
		$robots[ $pair['index'] ]  = true;
		$robots[ $pair['follow'] ] = true;
		$advanced = $this->resolve_advanced_directives( $post_id );
		if ( $advanced['nosnippet'] ) {
			$robots['nosnippet'] = true;
			unset( $robots['max-snippet'] );
		} elseif ( '' !== $advanced['max-snippet'] ) {
			$robots['max-snippet'] = $advanced['max-snippet'];
		}
		if ( '' !== $advanced['max-image-preview'] ) {
			$robots['max-image-preview'] = $advanced['max-image-preview'];
		}
		if ( '' !== $advanced['max-video-preview'] ) {
			$robots['max-video-preview'] = $advanced['max-video-preview'];
		}
		if ( $advanced['noarchive'] ) {
			$robots['noarchive'] = true;
		}
		if ( $advanced['notranslate'] ) {
			$robots['notranslate'] = true;
		}
		if ( '' !== $advanced['unavailable_after'] ) {
			$robots['unavailable_after'] = $advanced['unavailable_after'];
		}
		return $robots;
	}

	public function send_x_robots(): void {
		if ( is_admin() || $this->should_skip_output() ) {
			return;
		}
		if ( headers_sent() ) {
			return;
		}
		$post_id = $this->queried_post_id();
		$parts   = array();
		$custom  = $post_id ? (string) get_post_meta( $post_id, 'ogs_seo_robots', true ) : '';
		$base    = '' !== $custom ? $custom : $this->default_robots_for_context();
		$pair    = $this->normalized_robots_pair( $base );
		$parts[] = $pair['index'];
		$parts[] = $pair['follow'];
		$advanced = $this->resolve_advanced_directives( $post_id );
		if ( $advanced['nosnippet'] ) {
			$parts[] = 'nosnippet';
		} elseif ( '' !== $advanced['max-snippet'] ) {
			$parts[] = 'max-snippet:' . $advanced['max-snippet'];
		}
		if ( '' !== $advanced['max-image-preview'] ) {
			$parts[] = 'max-image-preview:' . $advanced['max-image-preview'];
		}
		if ( '' !== $advanced['max-video-preview'] ) {
			$parts[] = 'max-video-preview:' . $advanced['max-video-preview'];
		}
		if ( $advanced['noarchive'] ) {
			$parts[] = 'noarchive';
		}
		if ( $advanced['notranslate'] ) {
			$parts[] = 'notranslate';
		}
		if ( '' !== $advanced['unavailable_after'] ) {
			$parts[] = 'unavailable_after:' . $advanced['unavailable_after'];
		}
		$parts = array_values( array_unique( array_filter( $parts ) ) );
		if ( ! empty( $parts ) ) {
			header( 'X-Robots-Tag: ' . implode( ', ', $parts ) );
		}
	}

	public function apply_data_nosnippet( string $content ): string {
		if ( ! is_singular() || ! in_the_loop() || ! is_main_query() || $this->should_skip_output() ) {
			return $content;
		}
		$post_id = get_queried_object_id();
		if ( ! $post_id ) {
			return $content;
		}
		$ids_raw = (string) get_post_meta( $post_id, 'ogs_seo_data_nosnippet_ids', true );
		if ( '' === trim( $ids_raw ) || false === strpos( $content, 'id=' ) ) {
			return $content;
		}
		$ids = preg_split( '/\r\n|\r|\n/', $ids_raw ) ?: array();
		$ids = array_filter( array_map( 'sanitize_html_class', array_map( 'trim', $ids ) ) );
		if ( empty( $ids ) ) {
			return $content;
		}
		if ( ! class_exists( '\DOMDocument' ) || ! class_exists( '\DOMXPath' ) ) {
			return $content;
		}
		$dom = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$loaded = $dom->loadHTML( '<?xml encoding="utf-8" ?><div id="ogs-seo-content-root">' . $content . '</div>', LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded ) {
			return $content;
		}
		$xpath = new \DOMXPath( $dom );
		foreach ( $ids as $id ) {
			$nodes = $xpath->query( '//*[@id="' . $id . '"]' );
			if ( ! $nodes ) {
				continue;
			}
			foreach ( $nodes as $node ) {
				$node->setAttribute( 'data-nosnippet', '' );
			}
		}
		$root_list = $xpath->query( '//*[@id="ogs-seo-content-root"]' );
		$root      = ( $root_list && $root_list->length > 0 ) ? $root_list->item( 0 ) : null;
		if ( ! $root ) {
			return $content;
		}
		$output = '';
		foreach ( $root->childNodes as $node ) {
			$output .= $dom->saveHTML( $node );
		}
		return $output;
	}

	public function register_audit_checks( array $checks ): array {
		$checks['canonical_health'] = array( $this, 'audit_canonical_health' );
		return $checks;
	}

	public function audit_canonical_health(): array {
		if ( ! Settings::get( 'canonical_enabled', 1 ) ) {
			return array();
		}
		$settings = Settings::get_all();
		$posts = get_posts(
			array(
				'post_type'      => get_post_types( array( 'public' => true ), 'names' ),
				'post_status'    => 'publish',
				'posts_per_page' => 30,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		if ( empty( $posts ) ) {
			return array();
		}
		$invalid = 0;
		$broken  = 0;
		$conflict = 0;
		$policy_conflicts = 0;
		foreach ( $posts as $post_id ) {
			$post_id  = (int) $post_id;
			$override = (string) get_post_meta( $post_id, 'ogs_seo_canonical', true );
			$url      = $this->sanitize_canonical_url( $override );
			if ( '' !== trim( $override ) && '' === $url ) {
				++$invalid;
			}
			if ( '' !== $url && $this->is_same_site_url( $url ) && ! $this->canonical_target_is_accessible( $url ) ) {
				++$broken;
			}
			$target_post_id = url_to_postid( $url );
			if ( '' !== $url && $target_post_id > 0 && $this->is_post_noindex( $target_post_id ) ) {
				++$conflict;
			}
			if ( '' !== $url && $this->is_post_noindex( $post_id ) && $this->normalize_url( get_permalink( $post_id ) ?: '' ) !== $url ) {
				++$conflict;
			}
			$decision = $this->canonical_diagnostics_for_post( $post_id, $settings );
			$policy_conflicts += count( (array) ( $decision['conflicts'] ?? array() ) );
		}
		if ( 0 === $invalid && 0 === $broken && 0 === $conflict && 0 === $policy_conflicts ) {
			return array();
		}
		return array(
			'severity'       => $broken > 0 || $invalid > 0 || $policy_conflicts > 0 ? 'important' : 'minor',
			'title'          => __( 'Canonical override issues detected', 'open-growth-seo' ),
			'recommendation' => sprintf(
				/* translators: 1: invalid count, 2: broken count, 3: conflict count, 4: policy conflict count */
				__( 'Review canonical overrides: %1$d invalid URL(s), %2$d unreachable internal target(s), %3$d noindex/canonical conflict(s), %4$d deterministic policy conflict(s).', 'open-growth-seo' ),
				$invalid,
				$broken,
				$conflict,
				$policy_conflicts
			),
		);
	}

	private function should_skip_output(): bool {
		if ( ! Settings::get( 'safe_mode_seo_conflict', 1 ) ) {
			return false;
		}
		return Detector::has_active_seo_plugin();
	}

	private function title_template(): string {
		$settings = Settings::get_all();
		if ( class_exists( 'WooCommerce' ) ) {
			if ( is_post_type_archive( 'product' ) && '' !== trim( (string) ( $settings['woo_product_archive_title_template'] ?? '' ) ) ) {
				return (string) $settings['woo_product_archive_title_template'];
			}
			if ( is_tax( 'product_cat' ) && '' !== trim( (string) ( $settings['woo_product_cat_title_template'] ?? '' ) ) ) {
				return (string) $settings['woo_product_cat_title_template'];
			}
			if ( is_tax( 'product_tag' ) && '' !== trim( (string) ( $settings['woo_product_tag_title_template'] ?? '' ) ) ) {
				return (string) $settings['woo_product_tag_title_template'];
			}
		}
		if ( is_singular() ) {
			$post_type = get_post_type( get_queried_object_id() ) ?: 'post';
			if ( isset( $settings['post_type_title_templates'][ $post_type ] ) && '' !== $settings['post_type_title_templates'][ $post_type ] ) {
				return (string) $settings['post_type_title_templates'][ $post_type ];
			}
		}
		if ( is_tax() || is_category() || is_tag() ) {
			return (string) $settings['taxonomy_title_template'];
		}
		if ( is_author() ) {
			return (string) $settings['author_title_template'];
		}
		if ( is_date() ) {
			return (string) $settings['date_title_template'];
		}
		if ( is_search() ) {
			return (string) $settings['search_title_template'];
		}
		if ( is_attachment() ) {
			return (string) $settings['attachment_title_template'];
		}
		return (string) $settings['title_template'];
	}

	private function description_template(): string {
		$settings = Settings::get_all();
		if ( class_exists( 'WooCommerce' ) ) {
			if ( is_post_type_archive( 'product' ) && '' !== trim( (string) ( $settings['woo_product_archive_meta_template'] ?? '' ) ) ) {
				return (string) $settings['woo_product_archive_meta_template'];
			}
			if ( is_tax( 'product_cat' ) && '' !== trim( (string) ( $settings['woo_product_cat_meta_template'] ?? '' ) ) ) {
				return (string) $settings['woo_product_cat_meta_template'];
			}
			if ( is_tax( 'product_tag' ) && '' !== trim( (string) ( $settings['woo_product_tag_meta_template'] ?? '' ) ) ) {
				return (string) $settings['woo_product_tag_meta_template'];
			}
		}
		if ( is_singular() ) {
			$post_type = get_post_type( get_queried_object_id() ) ?: 'post';
			if ( isset( $settings['post_type_meta_templates'][ $post_type ] ) && '' !== $settings['post_type_meta_templates'][ $post_type ] ) {
				return (string) $settings['post_type_meta_templates'][ $post_type ];
			}
		}
		if ( is_tax() || is_category() || is_tag() ) {
			return (string) $settings['taxonomy_meta_template'];
		}
		if ( is_author() ) {
			return (string) $settings['author_meta_template'];
		}
		if ( is_date() ) {
			return (string) $settings['date_meta_template'];
		}
		if ( is_search() ) {
			return (string) $settings['search_meta_template'];
		}
		if ( is_attachment() ) {
			return (string) $settings['attachment_meta_template'];
		}
		return (string) $settings['meta_description_template'];
	}

	private function default_robots_for_context(): string {
		$settings = Settings::get_all();
		if ( class_exists( 'WooCommerce' ) ) {
			if ( $this->is_woo_archive_context() ) {
				if ( $this->has_woo_filter_query() ) {
					return $this->robots_pair( (string) ( $settings['woo_filter_results'] ?? 'noindex' ) );
				}
				if ( is_paged() ) {
					return $this->robots_pair( (string) ( $settings['woo_pagination_results'] ?? 'index' ) );
				}
			}
			if ( is_post_type_archive( 'product' ) ) {
				return $this->robots_pair( (string) ( $settings['woo_product_archive'] ?? 'index' ) );
			}
			if ( is_tax( 'product_cat' ) ) {
				if ( isset( $settings['taxonomy_robots_defaults']['product_cat'] ) && '' !== (string) $settings['taxonomy_robots_defaults']['product_cat'] ) {
					return (string) $settings['taxonomy_robots_defaults']['product_cat'];
				}
				return $this->robots_pair( (string) ( $settings['woo_product_cat_archive'] ?? 'index' ) );
			}
			if ( is_tax( 'product_tag' ) ) {
				if ( isset( $settings['taxonomy_robots_defaults']['product_tag'] ) && '' !== (string) $settings['taxonomy_robots_defaults']['product_tag'] ) {
					return (string) $settings['taxonomy_robots_defaults']['product_tag'];
				}
				return $this->robots_pair( (string) ( $settings['woo_product_tag_archive'] ?? 'noindex' ) );
			}
		}
		if ( is_singular() ) {
			$post_type = get_post_type( get_queried_object_id() ) ?: 'post';
			if ( isset( $settings['post_type_robots_defaults'][ $post_type ] ) && '' !== $settings['post_type_robots_defaults'][ $post_type ] ) {
				return (string) $settings['post_type_robots_defaults'][ $post_type ];
			}
		}
		if ( is_tax() || is_category() || is_tag() ) {
			$taxonomy = '';
			$term     = get_queried_object();
			if ( is_object( $term ) && isset( $term->taxonomy ) ) {
				$taxonomy = sanitize_key( (string) $term->taxonomy );
			}
			if ( '' !== $taxonomy && isset( $settings['taxonomy_robots_defaults'][ $taxonomy ] ) ) {
				return (string) $settings['taxonomy_robots_defaults'][ $taxonomy ];
			}
		}
		if ( is_author() ) {
			return $this->robots_pair( (string) $settings['author_archive'] );
		}
		if ( is_date() ) {
			return $this->robots_pair( (string) $settings['date_archive'] );
		}
		if ( is_search() ) {
			return $this->robots_pair( (string) $settings['search_results'] );
		}
		if ( is_attachment() ) {
			return $this->robots_pair( (string) $settings['attachment_pages'] );
		}
		return (string) $settings['default_index'];
	}

	private function robots_pair( string $value ): string {
		return 'noindex' === $value ? 'noindex,follow' : 'index,follow';
	}

	private function normalized_robots_pair( string $base ): array {
		$index  = 'index';
		$follow = 'follow';
		$tokens = array_map( 'trim', explode( ',', strtolower( $base ) ) );
		foreach ( $tokens as $token ) {
			if ( 'noindex' === $token ) {
				$index = 'noindex';
				continue;
			}
			if ( 'nofollow' === $token ) {
				$follow = 'nofollow';
				continue;
			}
			if ( 'index' === $token && 'noindex' !== $index ) {
				$index = 'index';
				continue;
			}
			if ( 'follow' === $token && 'nofollow' !== $follow ) {
				$follow = 'follow';
			}
		}
		return array(
			'index'  => $index,
			'follow' => $follow,
		);
	}

	private function resolve_advanced_directives( int $post_id ): array {
		$settings  = Settings::get_all();
		$post_type = $post_id ? ( get_post_type( $post_id ) ?: '' ) : '';

		$nosnippet = $this->resolve_toggle( $post_id, 'ogs_seo_nosnippet', 'default_nosnippet', 'post_type_nosnippet_defaults', $post_type, $settings );
		$max_snippet = $this->resolve_value( $post_id, 'ogs_seo_max_snippet', 'default_max_snippet', 'post_type_max_snippet_defaults', $post_type, $settings );
		$max_image = $this->resolve_value( $post_id, 'ogs_seo_max_image_preview', 'default_max_image_preview', 'post_type_max_image_preview_defaults', $post_type, $settings );
		$max_video = $this->resolve_value( $post_id, 'ogs_seo_max_video_preview', 'default_max_video_preview', 'post_type_max_video_preview_defaults', $post_type, $settings );
		$noarchive = $this->resolve_toggle( $post_id, 'ogs_seo_noarchive', 'default_noarchive', 'post_type_noarchive_defaults', $post_type, $settings );
		$notranslate = $this->resolve_toggle( $post_id, 'ogs_seo_notranslate', 'default_notranslate', 'post_type_notranslate_defaults', $post_type, $settings );
		$unavailable = $this->resolve_value( $post_id, 'ogs_seo_unavailable_after', 'default_unavailable_after', 'post_type_unavailable_after_defaults', $post_type, $settings );

		if ( $nosnippet ) {
			$max_snippet = '';
		}
		return array(
			'nosnippet'         => $nosnippet,
			'max-snippet'       => $this->sanitize_numeric_preview( $max_snippet ),
			'max-image-preview' => $this->sanitize_image_preview( $max_image ),
			'max-video-preview' => $this->sanitize_numeric_preview( $max_video ),
			'noarchive'         => $noarchive,
			'notranslate'       => $notranslate,
			'unavailable_after' => $this->sanitize_unavailable_after( $unavailable ),
		);
	}

	private function resolve_toggle( int $post_id, string $meta_key, string $global_key, string $post_type_key, string $post_type, array $settings ): bool {
		$value = $post_id ? (string) get_post_meta( $post_id, $meta_key, true ) : '';
		if ( in_array( $value, array( '0', '1' ), true ) ) {
			return '1' === $value;
		}
		if ( '' !== $post_type && isset( $settings[ $post_type_key ][ $post_type ] ) ) {
			return 1 === absint( $settings[ $post_type_key ][ $post_type ] );
		}
		return 1 === absint( $settings[ $global_key ] ?? 0 );
	}

	private function resolve_value( int $post_id, string $meta_key, string $global_key, string $post_type_key, string $post_type, array $settings ): string {
		$value = $post_id ? (string) get_post_meta( $post_id, $meta_key, true ) : '';
		if ( '' !== trim( $value ) ) {
			return $value;
		}
		if ( '' !== $post_type && isset( $settings[ $post_type_key ][ $post_type ] ) && '' !== (string) $settings[ $post_type_key ][ $post_type ] ) {
			return (string) $settings[ $post_type_key ][ $post_type ];
		}
		return (string) ( $settings[ $global_key ] ?? '' );
	}

	private function sanitize_numeric_preview( string $value ): string {
		$value = trim( strtolower( $value ) );
		if ( '' === $value ) {
			return '';
		}
		if ( '-1' === $value ) {
			return '-1';
		}
		return preg_match( '/^\d+$/', $value ) ? $value : '';
	}

	private function sanitize_image_preview( string $value ): string {
		$value = trim( strtolower( $value ) );
		return in_array( $value, array( '', 'none', 'standard', 'large' ), true ) ? $value : '';
	}

	private function sanitize_unavailable_after( string $value ): string {
		$value = trim( sanitize_text_field( $value ) );
		if ( '' === $value ) {
			return '';
		}
		$timestamp = strtotime( $value );
		return false !== $timestamp ? gmdate( 'd M Y H:i:s', $timestamp ) . ' GMT' : '';
	}

	private function canonical_url( array $settings, int $post_id ): string {
		$location = $post_id > 0 ? LocalSeoLocations::record_for_post( $post_id, $settings ) : array();
		$policy   = $this->canonical_decision( $settings, $post_id, $location );
		return (string) ( $policy['effective'] ?? '' );
	}

	/**
	 * @param array<string, mixed> $settings
	 * @param array<string, mixed> $location
	 * @return array<string, mixed>
	 */
	private function canonical_decision( array $settings, int $post_id, array $location = array() ): array {
		$manual = '';
		if ( is_singular() && $post_id > 0 ) {
			$manual = $this->sanitize_canonical_url( (string) get_post_meta( $post_id, 'ogs_seo_canonical', true ) );
			if ( '' === $manual && ! empty( $location['seo_canonical'] ) ) {
				$manual = $this->sanitize_canonical_url( (string) $location['seo_canonical'] );
			}
		}

		$contextual = $this->contextual_canonical_url();
		$fallback   = (string) wp_get_canonical_url();
		if ( '' === trim( $fallback ) ) {
			$fallback = $this->current_url();
		}

		$self_url = $this->contextual_canonical_url();
		if ( is_singular() && $post_id > 0 ) {
			$permalink = get_permalink( $post_id );
			$self_url  = is_string( $permalink ) ? $permalink : $self_url;
		} else {
			$self_url = $this->current_url();
		}
		if ( '' === trim( $self_url ) ) {
			$self_url = $this->current_url();
		}

		$redirect_target = CanonicalPolicy::matching_redirect_target( Redirects::current_request_path(), Redirects::get_rules() );
		$is_noindex      = $post_id > 0 ? $this->is_post_noindex( $post_id ) : ( false !== strpos( strtolower( $this->default_robots_for_context() ), 'noindex' ) );
		$sitemap_included = $this->is_sitemap_candidate( $post_id, $settings );

		$filter_base = $this->contextual_canonical_url();
		if ( is_paged() ) {
			$filter_base = $this->remove_pagination_from_url( $filter_base );
		}

		return CanonicalPolicy::resolve(
			$settings,
			array(
				'self_url'                    => $self_url,
				'manual_canonical'            => $manual,
				'contextual_canonical'        => $contextual,
				'fallback_canonical'          => $fallback,
				'redirect_target'             => $redirect_target,
				'is_noindex'                  => $is_noindex,
				'sitemap_included'            => $sitemap_included,
				'is_filter_surface'           => $this->is_woo_archive_context() && $this->has_woo_filter_query(),
				'filter_canonical_policy'     => (string) ( $settings['woo_filter_canonical_target'] ?? 'base' ),
				'filter_base_url'             => $filter_base,
				'is_paged'                    => is_paged(),
				'pagination_canonical_policy' => (string) ( $settings['woo_pagination_canonical_target'] ?? 'self' ),
				'pagination_base_url'         => $this->remove_pagination_from_url( $this->contextual_canonical_url() ),
			)
		);
	}

	/**
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	public function canonical_diagnostics_for_post( int $post_id, array $settings = array() ): array {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 ) {
			return array();
		}
		if ( empty( $settings ) ) {
			$settings = Settings::get_all();
		}

		$self_url = get_permalink( $post_id );
		$self_url = is_string( $self_url ) ? $self_url : '';
		$manual   = $this->sanitize_canonical_url( (string) get_post_meta( $post_id, 'ogs_seo_canonical', true ) );
		$location = LocalSeoLocations::record_for_post( $post_id, $settings );
		if ( '' === $manual && ! empty( $location['seo_canonical'] ) ) {
			$manual = $this->sanitize_canonical_url( (string) $location['seo_canonical'] );
		}
		$redirect_target = CanonicalPolicy::matching_redirect_target(
			Redirects::normalize_source_path( (string) wp_parse_url( $self_url, PHP_URL_PATH ) ),
			Redirects::get_rules()
		);

		$decision = CanonicalPolicy::resolve(
			$settings,
			array(
				'self_url'                    => $self_url,
				'manual_canonical'            => $manual,
				'contextual_canonical'        => $self_url,
				'fallback_canonical'          => $self_url,
				'redirect_target'             => $redirect_target,
				'is_noindex'                  => $this->is_post_noindex( $post_id ),
				'sitemap_included'            => $this->is_sitemap_candidate( $post_id, $settings ),
				'is_filter_surface'           => false,
				'filter_canonical_policy'     => (string) ( $settings['woo_filter_canonical_target'] ?? 'base' ),
				'filter_base_url'             => '',
				'is_paged'                    => false,
				'pagination_canonical_policy' => 'self',
				'pagination_base_url'         => '',
			)
		);
		$decision['post_id'] = $post_id;
		$decision['url']     = $self_url;
		return $decision;
	}

	private function contextual_canonical_url(): string {
		if ( is_front_page() || is_home() ) {
			$url = home_url( '/' );
		} elseif ( is_singular() ) {
			$post_id = get_queried_object_id();
			$url     = $post_id ? get_permalink( $post_id ) : '';
		} elseif ( is_tax() || is_category() || is_tag() ) {
			$term = get_queried_object();
			$url  = ( is_object( $term ) && isset( $term->term_id ) ) ? get_term_link( (int) $term->term_id ) : '';
		} elseif ( is_author() ) {
			$user = get_queried_object();
			$url  = ( is_object( $user ) && isset( $user->ID ) ) ? get_author_posts_url( (int) $user->ID ) : '';
		} elseif ( is_date() ) {
			$year  = absint( get_query_var( 'year' ) );
			$month = absint( get_query_var( 'monthnum' ) );
			$day   = absint( get_query_var( 'day' ) );
			if ( $year > 0 && $month > 0 && $day > 0 ) {
				$url = get_day_link( $year, $month, $day );
			} elseif ( $year > 0 && $month > 0 ) {
				$url = get_month_link( $year, $month );
			} elseif ( $year > 0 ) {
				$url = get_year_link( $year );
			} else {
				$url = '';
			}
		} elseif ( is_post_type_archive() ) {
			$post_type = get_query_var( 'post_type' );
			$post_type = is_array( $post_type ) ? reset( $post_type ) : $post_type;
			$url       = $post_type ? get_post_type_archive_link( (string) $post_type ) : '';
		} elseif ( is_search() ) {
			$url = get_search_link( get_search_query() );
		} else {
			$url = '';
		}
		if ( is_paged() ) {
			$paged = absint( get_query_var( 'paged' ) );
			if ( $paged > 1 ) {
				$url = get_pagenum_link( $paged );
			}
		}
		return is_string( $url ) ? $url : '';
	}

	private function sanitize_canonical_url( string $url ): string {
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
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || ! in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return '';
		}
		// Preserve manual query args in canonical overrides; query filtering is only for contextual canonicals.
		return $this->normalize_url( $url, false );
	}

	private function normalize_url( string $url, bool $filter_query = true ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		$scheme = strtolower( (string) $parts['scheme'] );
		$host   = strtolower( (string) $parts['host'] );
		$port   = isset( $parts['port'] ) ? ':' . absint( $parts['port'] ) : '';
		$path   = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		if ( '' === $path ) {
			$path = '/';
		}
		$path = preg_replace( '#/+#', '/', $path );
		$query = array();
		if ( ! empty( $parts['query'] ) ) {
			parse_str( (string) $parts['query'], $query );
		}
		if ( $filter_query ) {
			$query = $this->filtered_canonical_query_args( $query );
		}
		$normalized = $scheme . '://' . $host . $port . $path;
		if ( ! empty( $query ) ) {
			$normalized .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		}
		return $normalized;
	}

	private function filtered_canonical_query_args( array $query ): array {
		if ( empty( $query ) ) {
			return array();
		}
		$allowed = array( 'paged', 'page', 'cpage' );
		if ( is_search() ) {
			$allowed[] = 's';
		}
		$clean = array();
		foreach ( $allowed as $key ) {
			if ( isset( $query[ $key ] ) && '' !== (string) $query[ $key ] ) {
				$clean[ $key ] = sanitize_text_field( (string) $query[ $key ] );
			}
		}
		return $clean;
	}

	private function canonical_target_is_accessible( string $url ): bool {
		$url = $this->normalize_url( $url, false );
		if ( '' === $url ) {
			return false;
		}
		if ( ! $this->is_same_site_url( $url ) ) {
			return true;
		}
		$response = wp_remote_head(
			$url,
			array(
				'timeout'     => 2,
				'redirection' => 3,
			)
		);
		if ( is_wp_error( $response ) ) {
			return false;
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 405 === $status ) {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => 2,
					'redirection' => 3,
				)
			);
			if ( is_wp_error( $response ) ) {
				return false;
			}
			$status = (int) wp_remote_retrieve_response_code( $response );
		}
		return $status >= 200 && $status < 400;
	}

	private function is_same_site_url( string $url ): bool {
		$url_host  = wp_parse_url( $url, PHP_URL_HOST );
		$site_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		return is_string( $url_host ) && is_string( $site_host ) && strtolower( $url_host ) === strtolower( $site_host );
	}

	private function is_post_noindex( int $post_id ): bool {
		$robots = (string) get_post_meta( $post_id, 'ogs_seo_robots', true );
		if ( '' === $robots ) {
			$settings  = Settings::get_all();
			$post_type = get_post_type( $post_id ) ?: 'post';
			$robots    = isset( $settings['post_type_robots_defaults'][ $post_type ] ) ? (string) $settings['post_type_robots_defaults'][ $post_type ] : (string) $settings['default_index'];
		}
		return false !== strpos( strtolower( $robots ), 'noindex' );
	}

	private function current_url(): string {
		if ( is_search() ) {
			return get_search_link( get_search_query() );
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) ( function_exists( 'wp_unslash' ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : $_SERVER['REQUEST_URI'] ) : '';
		if ( '' === trim( $uri ) ) {
			global $wp;
			$request = isset( $wp->request ) ? (string) $wp->request : '';
			return home_url( user_trailingslashit( $request ) );
		}
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$url  = home_url( '/' . ltrim( $path, '/' ) );
		$query_raw = (string) wp_parse_url( $uri, PHP_URL_QUERY );
		if ( '' !== $query_raw ) {
			parse_str( $query_raw, $query );
			if ( ! empty( $query ) ) {
				$clean = array();
				foreach ( $query as $key => $value ) {
					$clean_key = sanitize_key( (string) $key );
					if ( '' === $clean_key ) {
						continue;
					}
					if ( is_array( $value ) ) {
						$clean[ $clean_key ] = array_map( static fn( $item ) => sanitize_text_field( (string) $item ), $value );
					} else {
						$clean[ $clean_key ] = sanitize_text_field( (string) $value );
					}
				}
				if ( ! empty( $clean ) ) {
					$url = add_query_arg( $clean, $url );
				}
			}
		}
		return $url;
	}

	private function is_sitemap_candidate( int $post_id, array $settings ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}
		if ( empty( $settings['sitemap_enabled'] ) ) {
			return false;
		}
		$post_type = get_post_type( $post_id );
		if ( ! is_string( $post_type ) || '' === $post_type ) {
			return false;
		}
		$included = isset( $settings['sitemap_post_types'] ) && is_array( $settings['sitemap_post_types'] ) ? array_map( 'sanitize_key', $settings['sitemap_post_types'] ) : array();
		return in_array( sanitize_key( $post_type ), $included, true );
	}

	private function remove_pagination_from_url( string $url ): string {
		$url   = $this->normalize_url( $url );
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return $url;
		}
		$query = array();
		if ( ! empty( $parts['query'] ) ) {
			parse_str( (string) $parts['query'], $query );
		}
		unset( $query['paged'], $query['page'], $query['product-page'] );

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( '' === $scheme || '' === $host ) {
			return $url;
		}
		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		if ( '' === $path ) {
			$path = '/';
		}
		$normalized = $scheme . '://' . $host . $path;
		if ( ! empty( $query ) ) {
			$normalized .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		}
		return $normalized;
	}

	private function is_woo_archive_context(): bool {
		return class_exists( 'WooCommerce' ) && ( is_post_type_archive( 'product' ) || is_tax( 'product_cat' ) || is_tax( 'product_tag' ) );
	}

	private function has_woo_filter_query(): bool {
		if ( ! $this->is_woo_archive_context() ) {
			return false;
		}
		$query = array();
		if ( isset( $_GET ) && is_array( $_GET ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only runtime detection for canonical/robots policy.
			$query = function_exists( 'wp_unslash' ) ? wp_unslash( $_GET ) : $_GET;
		}
		if ( empty( $query ) ) {
			return false;
		}
		$safe_keys = array(
			'paged',
			'page',
			'product-page',
			'utm_source',
			'utm_medium',
			'utm_campaign',
			'utm_term',
			'utm_content',
			'gclid',
			'fbclid',
			'msclkid',
			'srsltid',
			'_ga',
			'_gl',
		);
		$explicit_filter_keys = array(
			'min_price',
			'max_price',
			'orderby',
			'rating_filter',
			'on_sale',
			'in_stock',
			'stock_status',
		);
		foreach ( $query as $raw_key => $raw_value ) {
			$key = sanitize_key( (string) $raw_key );
			if ( in_array( $key, $safe_keys, true ) ) {
				continue;
			}
			if ( str_starts_with( $key, 'utm_' ) ) {
				continue;
			}
			if ( '' === trim( (string) $raw_value ) ) {
				continue;
			}
			if ( str_starts_with( $key, 'filter_' ) || str_starts_with( $key, 'query_type_' ) ) {
				return true;
			}
			if ( in_array( $key, $explicit_filter_keys, true ) ) {
				return true;
			}
			if ( preg_match( '/^pa_[a-z0-9_]+$/', $key ) ) {
				return true;
			}
			return true;
		}
		return false;
	}

	private function trim_snippet( string $text, int $max ): string {
		$text = trim( wp_strip_all_tags( $text ) );
		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			return mb_strlen( $text ) > $max ? mb_substr( $text, 0, $max - 1 ) . '…' : $text;
		}
		return strlen( $text ) > $max ? substr( $text, 0, $max - 1 ) . '…' : $text;
	}

	private function queried_post_id(): int {
		if ( ! is_singular() ) {
			return 0;
		}
		return absint( get_queried_object_id() );
	}

	private function apply_template( string $template ): string {
		$template = (string) $template;
		if ( '' === trim( $template ) ) {
			return '';
		}
		$post_id    = $this->queried_post_id();
		$term       = ( is_tax() || is_category() || is_tag() ) ? get_queried_object() : null;
		$author     = is_author() ? get_queried_object() : null;
		$search_q   = get_search_query();
		$excerpt    = $this->context_excerpt_token( $post_id, $term, $author );
		$title      = $this->context_title_token( $post_id );
		$archive    = wp_strip_all_tags( get_the_archive_title() );
		if ( '' === trim( $archive ) && ( is_home() || is_front_page() ) ) {
			$archive = (string) get_bloginfo( 'name' );
		}

		$replacements = array(
			'%%title%%'            => $title,
			'%%sitename%%'         => (string) get_bloginfo( 'name' ),
			'%%sep%%'              => (string) Settings::get( 'title_separator', '|' ),
			'%%excerpt%%'          => $excerpt,
			'%%term%%'             => ( is_object( $term ) && isset( $term->name ) ) ? (string) $term->name : '',
			'%%term_description%%' => ( is_object( $term ) && isset( $term->description ) ) ? wp_strip_all_tags( (string) $term->description ) : '',
			'%%author%%'           => is_object( $author ) && isset( $author->display_name ) ? (string) $author->display_name : '',
			'%%archive_title%%'    => $archive,
			'%%query%%'            => (string) $search_q,
			'%%site_description%%' => (string) get_bloginfo( 'description' ),
		);

		$output = strtr( $template, $replacements );
		$output = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $output ) ) );
		return $output;
	}

	private function context_title_token( int $post_id ): string {
		if ( $post_id > 0 ) {
			return (string) get_the_title( $post_id );
		}
		$archive_title = wp_strip_all_tags( get_the_archive_title() );
		if ( '' !== trim( $archive_title ) ) {
			return $archive_title;
		}
		$single_title = (string) single_post_title( '', false );
		if ( '' !== trim( $single_title ) ) {
			return $single_title;
		}
		return (string) get_bloginfo( 'name' );
	}

	private function context_excerpt_token( int $post_id, $term, $author ): string {
		if ( $post_id > 0 ) {
			$excerpt = (string) get_post_field( 'post_excerpt', $post_id );
			if ( '' !== trim( $excerpt ) ) {
				return $excerpt;
			}
			return wp_trim_words( wp_strip_all_tags( (string) get_post_field( 'post_content', $post_id ) ), 30 );
		}
		if ( is_object( $term ) && isset( $term->description ) && '' !== trim( (string) $term->description ) ) {
			return wp_strip_all_tags( (string) $term->description );
		}
		if ( is_object( $author ) && isset( $author->description ) && '' !== trim( (string) $author->description ) ) {
			return wp_strip_all_tags( (string) $author->description );
		}
		if ( is_search() ) {
			$query = trim( (string) get_search_query() );
			if ( '' !== $query ) {
				return sprintf( __( 'Search results for %s', 'open-growth-seo' ), $query );
			}
		}
		return (string) get_bloginfo( 'description' );
	}
}
