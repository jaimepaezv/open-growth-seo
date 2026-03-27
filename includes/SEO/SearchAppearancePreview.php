<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\SEO;

defined( 'ABSPATH' ) || exit;

class SearchAppearancePreview {
	public static function resolve( array $context ): array {
		$site_name    = self::sanitize_text( (string) ( $context['site_name'] ?? '' ) );
		$site_desc    = self::sanitize_text( (string) ( $context['site_description'] ?? '' ) );
		$sample_title = self::sanitize_text( (string) ( $context['sample_title'] ?? '' ) );
		$sample_desc  = self::sanitize_text( (string) ( $context['sample_excerpt'] ?? '' ) );
		$query        = self::sanitize_text( (string) ( $context['search_query'] ?? '' ) );
		$separator    = self::sanitize_text( (string) ( $context['title_separator'] ?? '' ) );
		$home_url     = esc_url_raw( (string) ( $context['home_url'] ?? '' ) );
		$social_image = esc_url_raw( (string) ( $context['social_image'] ?? '' ) );

		$site_name    = '' !== $site_name ? $site_name : 'Site';
		$site_desc    = '' !== $site_desc ? $site_desc : 'Site description';
		$sample_title = '' !== $sample_title ? $sample_title : 'Example Service Page';
		$sample_desc  = '' !== $sample_desc ? $sample_desc : $site_desc;
		$query        = '' !== $query ? $query : 'example query';
		$separator    = '' !== $separator ? $separator : '|';
		$home_url     = '' !== $home_url ? $home_url : home_url( '/' );

		$title_template = self::sanitize_template( (string) ( $context['title_template'] ?? '' ) );
		$desc_template  = self::sanitize_template( (string) ( $context['meta_description_template'] ?? '' ) );

		$title = self::apply_template(
			$title_template,
			array(
				'%%title%%'            => $sample_title,
				'%%sitename%%'         => $site_name,
				'%%sep%%'              => $separator,
				'%%site_description%%' => $site_desc,
			),
			$sample_title . ' ' . $separator . ' ' . $site_name
		);
		$desc  = self::apply_template(
			$desc_template,
			array(
				'%%excerpt%%'          => $sample_desc,
				'%%sitename%%'         => $site_name,
				'%%site_description%%' => $site_desc,
				'%%query%%'            => $query,
			),
			$sample_desc
		);

		$serp_title = self::trim_for_preview( $title, 60 );
		$serp_desc  = self::trim_for_preview( $desc, 160 );
		$social_title = self::trim_for_preview( $title, 90 );
		$social_desc  = self::trim_for_preview( $desc, 200 );

		return array(
			'serp_title'       => $serp_title,
			'serp_description' => $serp_desc,
			'social_title'     => $social_title,
			'social_description' => $social_desc,
			'social_image'     => $social_image,
			'title_count'      => self::string_length( $serp_title ),
			'description_count' => self::string_length( $serp_desc ),
			'home_url'         => $home_url,
			'home_host'        => wp_parse_url( $home_url, PHP_URL_HOST ) ?: $home_url,
			'schema_hints'     => self::schema_hints( $context ),
		);
	}

	private static function schema_hints( array $context ): array {
		$hints = array();
		if ( self::as_bool( $context['schema_article_enabled'] ?? 0 ) ) {
			$hints[] = __( 'Article-like pages can be eligible for article enhancements when visible content supports it.', 'open-growth-seo' );
		}
		if ( self::as_bool( $context['schema_faq_enabled'] ?? 0 ) ) {
			$hints[] = __( 'FAQ rich results require visible question-answer pairs on the page.', 'open-growth-seo' );
		}
		if ( self::as_bool( $context['schema_video_enabled'] ?? 0 ) ) {
			$hints[] = __( 'Video enhancements require visible video content and valid video metadata.', 'open-growth-seo' );
		}
		if ( empty( $hints ) ) {
			$hints[] = __( 'No rich result hint is currently enabled in defaults.', 'open-growth-seo' );
		}
		return $hints;
	}

	private static function as_bool( $value ): bool {
		return in_array( (string) $value, array( '1', 'true', 'yes' ), true );
	}

	private static function sanitize_template( string $template ): string {
		$template = wp_strip_all_tags( $template );
		$template = preg_replace( '/\s+/', ' ', $template );
		return trim( (string) $template );
	}

	private static function sanitize_text( string $value ): string {
		return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $value ) ) );
	}

	private static function apply_template( string $template, array $tokens, string $fallback ): string {
		if ( '' === trim( $template ) ) {
			return $fallback;
		}
		$resolved = strtr( $template, $tokens );
		$resolved = self::sanitize_text( $resolved );
		return '' !== $resolved ? $resolved : $fallback;
	}

	private static function trim_for_preview( string $value, int $limit ): string {
		$value = self::sanitize_text( $value );
		if ( self::string_length( $value ) <= $limit ) {
			return $value;
		}
		if ( function_exists( 'mb_substr' ) ) {
			return trim( mb_substr( $value, 0, max( 0, $limit - 3 ) ) ) . '...';
		}
		return trim( substr( $value, 0, max( 0, $limit - 3 ) ) ) . '...';
	}

	private static function string_length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
	}
}
