<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\Support;

use OpenGrowthSolutions\OpenGrowthSEO\Core\Defaults;
use OpenGrowthSolutions\OpenGrowthSEO\SEO\LocalSeoLocations;

defined( 'ABSPATH' ) || exit;

class Settings {
	private const ROBOTS_ALLOWED = array(
		'index,follow',
		'noindex,follow',
		'index,nofollow',
		'noindex,nofollow',
	);
	private const PREVIEW_IMAGE_ALLOWED = array( 'none', 'standard', 'large' );

	public static function get_all(): array {
		$defaults = Defaults::settings();
		$current  = get_option( 'ogs_seo_settings', array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}
		return wp_parse_args( $current, $defaults );
	}

	public static function get( string $key, $default = null ) {
		$settings = self::get_all();
		return $settings[ $key ] ?? $default;
	}

	public static function update( array $settings ): void {
		$base  = self::get_all();
		$clean = self::sanitize( array_replace( $base, $settings ) );
		update_option( 'ogs_seo_settings', $clean, false );
	}

	public static function sanitize( array $settings ): array {
		$defaults = Defaults::settings();
		$clean    = array();

		foreach ( $defaults as $key => $value ) {
			$raw = $settings[ $key ] ?? $value;

			if ( in_array( $key, array( 'post_type_title_templates', 'post_type_meta_templates', 'taxonomy_robots_defaults', 'post_type_robots_defaults' ), true ) ) {
				$clean[ $key ] = self::sanitize_assoc_array( (array) $raw, 'post_type_robots_defaults' === $key || 'taxonomy_robots_defaults' === $key );
				continue;
			}
			if ( 'schema_post_type_defaults' === $key ) {
				$clean[ $key ] = self::sanitize_schema_post_type_defaults( (array) $raw );
				continue;
			}
			if ( 'local_locations' === $key ) {
				$clean[ $key ] = LocalSeoLocations::sanitize_records( $raw );
				continue;
			}
			if ( in_array( $key, array( 'post_type_nosnippet_defaults', 'post_type_noarchive_defaults', 'post_type_notranslate_defaults' ), true ) ) {
				$clean[ $key ] = self::sanitize_assoc_toggle_array( (array) $raw );
				continue;
			}
			if ( in_array( $key, array( 'post_type_max_snippet_defaults', 'post_type_max_video_preview_defaults' ), true ) ) {
				$clean[ $key ] = self::sanitize_assoc_numeric_preview_array( (array) $raw );
				continue;
			}
			if ( 'post_type_max_image_preview_defaults' === $key ) {
				$clean[ $key ] = self::sanitize_assoc_image_preview_array( (array) $raw );
				continue;
			}
			if ( 'post_type_unavailable_after_defaults' === $key ) {
				$clean[ $key ] = self::sanitize_assoc_date_array( (array) $raw );
				continue;
			}

			if ( 'mode' === $key ) {
				$clean[ $key ] = ExperienceMode::normalize( (string) $raw );
				continue;
			}

			if ( is_array( $value ) ) {
				$clean[ $key ] = array_values( array_filter( array_map( 'sanitize_key', (array) $raw ) ) );
				continue;
			}
			if ( 'default_index' === $key ) {
				$clean[ $key ] = self::sanitize_robots_directive( (string) $raw );
				continue;
			}
			if ( in_array( $key, array( 'default_nosnippet', 'default_noarchive', 'default_notranslate' ), true ) ) {
				$clean[ $key ] = self::sanitize_toggle( $raw );
				continue;
			}
			if ( in_array( $key, array( 'default_max_snippet', 'default_max_video_preview' ), true ) ) {
				$clean[ $key ] = self::sanitize_numeric_preview( (string) $raw );
				continue;
			}
			if ( 'default_max_image_preview' === $key ) {
				$clean[ $key ] = self::sanitize_image_preview( (string) $raw );
				continue;
			}
			if ( 'default_unavailable_after' === $key ) {
				$clean[ $key ] = self::sanitize_unavailable_after( (string) $raw );
				continue;
			}
			if ( 'hreflang_x_default_mode' === $key ) {
				$mode = sanitize_key( (string) $raw );
				$clean[ $key ] = in_array( $mode, array( 'auto', 'none', 'custom' ), true ) ? $mode : 'auto';
				continue;
			}
			if ( 'hreflang_x_default_url' === $key ) {
				$clean[ $key ] = esc_url_raw( (string) $raw );
				continue;
			}
			if ( 'hreflang_manual_map' === $key ) {
				$clean[ $key ] = sanitize_textarea_field( (string) $raw );
				continue;
			}
			if ( in_array( $key, array( 'schema_org_logo', 'schema_org_contact_url' ), true ) ) {
				$clean[ $key ] = esc_url_raw( (string) $raw );
				continue;
			}
			if ( in_array( $key, array( 'webmaster_google_verification', 'webmaster_bing_verification', 'webmaster_yandex_verification', 'webmaster_pinterest_verification', 'webmaster_baidu_verification' ), true ) ) {
				$clean[ $key ] = self::sanitize_verification_token( (string) $raw );
				continue;
			}
			if ( 'breadcrumbs_home_label' === $key ) {
				$clean[ $key ] = sanitize_text_field( (string) $raw );
				continue;
			}
			if ( 'breadcrumbs_separator' === $key ) {
				$separator = trim( sanitize_text_field( (string) $raw ) );
				if ( '' === $separator ) {
					$clean[ $key ] = '/';
					continue;
				}
				$clean[ $key ] = function_exists( 'mb_substr' ) ? mb_substr( $separator, 0, 5 ) : substr( $separator, 0, 5 );
				continue;
			}
			if ( in_array( $key, array( 'breadcrumbs_enabled', 'breadcrumbs_include_current' ), true ) ) {
				$clean[ $key ] = self::sanitize_toggle( $raw );
				continue;
			}
			if ( in_array( $key, array( 'gsc_property_url', 'bing_site_url' ), true ) ) {
				$clean[ $key ] = self::sanitize_search_property( (string) $raw );
				continue;
			}
			if ( in_array( $key, array( 'rss_before_content', 'rss_after_content', 'llms_txt_content' ), true ) ) {
				$clean[ $key ] = sanitize_textarea_field( (string) $raw );
				continue;
			}
			if ( 'llms_txt_enabled' === $key ) {
				$clean[ $key ] = self::sanitize_toggle( $raw );
				continue;
			}
			if ( 'llms_txt_mode' === $key ) {
				$mode = sanitize_key( (string) $raw );
				$clean[ $key ] = in_array( $mode, array( 'guided', 'custom' ), true ) ? $mode : 'guided';
				continue;
			}
			if ( in_array( $key, array( 'redirects_enabled', 'redirect_404_logging_enabled', 'seo_masters_plus_enabled', 'seo_masters_plus_link_graph', 'seo_masters_plus_redirect_ops', 'seo_masters_plus_canonical_policy', 'seo_masters_plus_schema_guardrails', 'seo_masters_plus_gsc_trends' ), true ) ) {
				$clean[ $key ] = self::sanitize_toggle( $raw );
				continue;
			}
			if ( 'redirect_404_log_limit' === $key ) {
				$clean[ $key ] = max( 20, min( 1000, absint( $raw ) ) );
				continue;
			}
			if ( 'redirect_404_retention_days' === $key ) {
				$clean[ $key ] = max( 1, min( 3650, absint( $raw ) ) );
				continue;
			}
			if ( 'indexnow_endpoint' === $key ) {
				$endpoint = esc_url_raw( (string) $raw );
				$clean[ $key ] = '' !== $endpoint ? $endpoint : 'https://api.indexnow.org/indexnow';
				continue;
			}
			if ( 'indexnow_key' === $key ) {
				$clean[ $key ] = self::sanitize_indexnow_key( (string) $raw );
				continue;
			}
			if ( 'ga4_measurement_id' === $key ) {
				$clean[ $key ] = self::sanitize_ga4_measurement_id( (string) $raw );
				continue;
			}
			if ( in_array( $key, array( 'indexnow_batch_size', 'indexnow_rate_limit_seconds', 'indexnow_max_retries', 'indexnow_queue_max_size' ), true ) ) {
				$clean[ $key ] = self::sanitize_indexnow_limits( $key, $raw );
				continue;
			}
			if ( in_array( $key, array( 'integration_request_timeout', 'integration_retry_limit' ), true ) ) {
				$clean[ $key ] = self::sanitize_integration_limits( $key, $raw );
				continue;
			}
			if ( 'schema_org_sameas' === $key ) {
				$clean[ $key ] = sanitize_textarea_field( (string) $raw );
				continue;
			}
			if ( in_array( $key, array( 'schema_org_contact_phone', 'schema_org_contact_email' ), true ) ) {
				$clean[ $key ] = sanitize_text_field( (string) $raw );
				continue;
			}
			if ( 'schema_org_type' === $key ) {
				$type = sanitize_text_field( (string) $raw );
				$clean[ $key ] = in_array( $type, array( 'Organization', 'CollegeOrUniversity' ), true ) ? $type : 'Organization';
				continue;
			}
			if ( in_array( $key, array( 'author_archive', 'date_archive', 'search_results', 'attachment_pages' ), true ) ) {
				$clean[ $key ] = self::sanitize_archive_robots( (string) $raw );
				continue;
			}
			if ( in_array( $key, array( 'woo_product_archive', 'woo_product_cat_archive', 'woo_product_tag_archive' ), true ) ) {
				$clean[ $key ] = self::sanitize_archive_robots( (string) $raw );
				continue;
			}
			if ( in_array( $key, array( 'woo_filter_results', 'woo_pagination_results' ), true ) ) {
				$clean[ $key ] = self::sanitize_archive_robots( (string) $raw );
				continue;
			}
			if ( in_array( $key, array( 'woo_filter_canonical_target', 'woo_pagination_canonical_target', 'woo_archive_schema_mode' ), true ) ) {
				$mode = sanitize_key( (string) $raw );
				if ( 'woo_filter_canonical_target' === $key ) {
					$clean[ $key ] = in_array( $mode, array( 'base', 'self', 'none' ), true ) ? $mode : 'base';
					continue;
				}
				if ( 'woo_pagination_canonical_target' === $key ) {
					$clean[ $key ] = in_array( $mode, array( 'self', 'first_page' ), true ) ? $mode : 'self';
					continue;
				}
				$clean[ $key ] = in_array( $mode, array( 'none', 'collection' ), true ) ? $mode : 'none';
				continue;
			}
			if ( 'woo_schema_mode' === $key ) {
				$mode = sanitize_key( (string) $raw );
				$clean[ $key ] = in_array( $mode, array( 'native', 'ogs' ), true ) ? $mode : 'native';
				continue;
			}
			if ( 'woo_min_product_description_words' === $key ) {
				$clean[ $key ] = max( 30, min( 500, absint( $raw ) ) );
				continue;
			}
			if ( false !== strpos( $key, '_template' ) ) {
				$clean[ $key ] = self::sanitize_template( (string) $raw );
				continue;
			}
			if ( 'robots_mode' === $key ) {
				$mode = sanitize_key( (string) $raw );
				$clean[ $key ] = in_array( $mode, array( 'managed', 'expert' ), true ) ? $mode : 'managed';
				continue;
			}
			if ( in_array( $key, array( 'robots_global_policy', 'bots_gptbot', 'bots_oai_searchbot' ), true ) ) {
				$policy = sanitize_key( (string) $raw );
				$clean[ $key ] = in_array( $policy, array( 'allow', 'disallow' ), true ) ? $policy : 'allow';
				continue;
			}
			if ( in_array( $key, array( 'robots_custom', 'robots_expert_rules' ), true ) ) {
				$clean[ $key ] = sanitize_textarea_field( (string) $raw );
				continue;
			}
			if ( is_int( $value ) ) {
				$clean[ $key ] = absint( $raw );
				continue;
			}
			$clean[ $key ] = sanitize_text_field( (string) $raw );
		}
		return $clean;
	}

	private static function sanitize_assoc_array( array $value, bool $robots = false ): array {
		$clean = array();
		foreach ( $value as $key => $item ) {
			$map_key = sanitize_key( (string) $key );
			if ( '' === $map_key ) {
				continue;
			}
			$clean[ $map_key ] = $robots ? self::sanitize_robots_directive( (string) $item ) : self::sanitize_template( (string) $item );
		}
		return $clean;
	}

	private static function sanitize_template( string $template ): string {
		$template = wp_strip_all_tags( $template );
		$template = preg_replace( '/\s+/', ' ', $template );
		return trim( (string) $template );
	}

	private static function sanitize_robots_directive( string $directive ): string {
		$directive = strtolower( sanitize_text_field( $directive ) );
		return in_array( $directive, self::ROBOTS_ALLOWED, true ) ? $directive : 'index,follow';
	}

	private static function sanitize_archive_robots( string $directive ): string {
		$directive = strtolower( sanitize_text_field( $directive ) );
		return in_array( $directive, array( 'index', 'noindex' ), true ) ? $directive : 'index';
	}

	private static function sanitize_toggle( $value ): int {
		return in_array( (string) $value, array( '1', 'true', 'yes' ), true ) ? 1 : 0;
	}

	private static function sanitize_numeric_preview( string $value ): string {
		$value = trim( sanitize_text_field( $value ) );
		if ( '' === $value ) {
			return '';
		}
		if ( '-1' === $value ) {
			return '-1';
		}
		return preg_match( '/^\d+$/', $value ) ? $value : '';
	}

	private static function sanitize_image_preview( string $value ): string {
		$value = strtolower( trim( sanitize_text_field( $value ) ) );
		if ( '' === $value ) {
			return '';
		}
		return in_array( $value, self::PREVIEW_IMAGE_ALLOWED, true ) ? $value : '';
	}

	private static function sanitize_unavailable_after( string $value ): string {
		$value = trim( sanitize_text_field( $value ) );
		if ( '' === $value ) {
			return '';
		}
		$timestamp = strtotime( $value );
		return false !== $timestamp ? gmdate( 'd M Y H:i:s', $timestamp ) . ' GMT' : '';
	}

	private static function sanitize_assoc_toggle_array( array $value ): array {
		$clean = array();
		foreach ( $value as $key => $item ) {
			$map_key = sanitize_key( (string) $key );
			if ( '' === $map_key ) {
				continue;
			}
			$clean[ $map_key ] = self::sanitize_toggle( $item );
		}
		return $clean;
	}

	private static function sanitize_schema_post_type_defaults( array $value ): array {
		$clean         = array();
		$allowed_types = array(
			'',
			'Article',
			'BlogPosting',
			'NewsArticle',
			'TechArticle',
			'Service',
			'OfferCatalog',
			'Person',
			'Course',
			'EducationalOccupationalProgram',
			'FAQPage',
			'ProfilePage',
			'AboutPage',
			'ContactPage',
			'CollectionPage',
			'DiscussionForumPosting',
			'QAPage',
			'VideoObject',
			'Event',
			'EventSeries',
			'JobPosting',
			'Recipe',
			'SoftwareApplication',
			'WebAPI',
			'Project',
			'Review',
			'Guide',
			'Dataset',
			'DefinedTerm',
			'DefinedTermSet',
			'ScholarlyArticle',
			'Product',
		);
		foreach ( $value as $post_type => $schema_type ) {
			$post_type   = sanitize_key( (string) $post_type );
			$schema_type = trim( sanitize_text_field( (string) $schema_type ) );
			if ( '' === $post_type ) {
				continue;
			}
			if ( ! in_array( $schema_type, $allowed_types, true ) ) {
				continue;
			}
			$clean[ $post_type ] = $schema_type;
		}
		return $clean;
	}

	private static function sanitize_assoc_numeric_preview_array( array $value ): array {
		$clean = array();
		foreach ( $value as $key => $item ) {
			$map_key = sanitize_key( (string) $key );
			if ( '' === $map_key ) {
				continue;
			}
			$clean[ $map_key ] = self::sanitize_numeric_preview( (string) $item );
		}
		return $clean;
	}

	private static function sanitize_assoc_image_preview_array( array $value ): array {
		$clean = array();
		foreach ( $value as $key => $item ) {
			$map_key = sanitize_key( (string) $key );
			if ( '' === $map_key ) {
				continue;
			}
			$clean[ $map_key ] = self::sanitize_image_preview( (string) $item );
		}
		return $clean;
	}

	private static function sanitize_assoc_date_array( array $value ): array {
		$clean = array();
		foreach ( $value as $key => $item ) {
			$map_key = sanitize_key( (string) $key );
			if ( '' === $map_key ) {
				continue;
			}
			$clean[ $map_key ] = self::sanitize_unavailable_after( (string) $item );
		}
		return $clean;
	}

	private static function sanitize_search_property( string $value ): string {
		$value = trim( sanitize_text_field( $value ) );
		if ( '' === $value ) {
			return '';
		}
		if ( str_starts_with( $value, 'sc-domain:' ) ) {
			return $value;
		}
		$url = esc_url_raw( $value );
		return '' !== $url ? $url : '';
	}

	private static function sanitize_verification_token( string $value ): string {
		$value = trim( wp_strip_all_tags( $value ) );
		$value = preg_replace( '/\s+/', ' ', $value );
		return trim( (string) $value );
	}

	private static function sanitize_ga4_measurement_id( string $value ): string {
		$value = strtoupper( trim( sanitize_text_field( $value ) ) );
		return preg_match( '/^G-[A-Z0-9]+$/', $value ) ? $value : '';
	}

	private static function sanitize_integration_limits( string $key, $value ): int {
		$int = absint( $value );
		if ( 'integration_request_timeout' === $key ) {
			return max( 3, min( 20, $int ) );
		}
		return max( 0, min( 5, $int ) );
	}

	private static function sanitize_indexnow_key( string $value ): string {
		$value = trim( sanitize_text_field( $value ) );
		return preg_match( '/^[a-zA-Z0-9]{8,128}$/', $value ) ? $value : '';
	}

	private static function sanitize_indexnow_limits( string $key, $value ): int {
		$int = absint( $value );
		switch ( $key ) {
			case 'indexnow_batch_size':
				return max( 1, min( 100, $int ) );
			case 'indexnow_rate_limit_seconds':
				return max( 10, min( 3600, $int ) );
			case 'indexnow_max_retries':
				return max( 1, min( 20, $int ) );
			case 'indexnow_queue_max_size':
				return max( 100, min( 50000, $int ) );
			default:
				return $int;
		}
	}
}
