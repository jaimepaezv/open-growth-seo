<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\Support;

defined( 'ABSPATH' ) || exit;

class SeoMastersPlus {
	public const SETTING_ENABLED = 'seo_masters_plus_enabled';

	public static function feature_map(): array {
		return array(
			'seo_masters_plus_link_graph' => array(
				'label'       => __( 'Link graph and orphan confidence', 'open-growth-seo' ),
				'description' => __( 'Adds confidence-based orphan detection, cluster context, and stale cornerstone queue scoring.', 'open-growth-seo' ),
			),
			'seo_masters_plus_redirect_ops' => array(
				'label'       => __( 'Advanced redirects and 404 operations', 'open-growth-seo' ),
				'description' => __( 'Keeps redirect conflict checks and 404 remediation tooling in an expert workflow.', 'open-growth-seo' ),
			),
			'seo_masters_plus_canonical_policy' => array(
				'label'       => __( 'Canonical policy diagnostics', 'open-growth-seo' ),
				'description' => __( 'Highlights canonical, noindex, redirect, and sitemap signal conflicts before they cause drift.', 'open-growth-seo' ),
			),
			'seo_masters_plus_schema_guardrails' => array(
				'label'       => __( 'Schema and entity guardrails', 'open-growth-seo' ),
				'description' => __( 'Keeps manual schema overrides eligibility-aware and aligned with visible content.', 'open-growth-seo' ),
			),
			'seo_masters_plus_gsc_trends' => array(
				'label'       => __( 'Search Console trend snapshots', 'open-growth-seo' ),
				'description' => __( 'Shows operational deltas and trend-aware next actions instead of static metrics.', 'open-growth-seo' ),
			),
		);
	}

	public static function is_available( array $settings = array() ): bool {
		if ( empty( $settings ) ) {
			$settings = Settings::get_all();
		}

		return ExperienceMode::ADVANCED === ExperienceMode::current( $settings ) || ExperienceMode::is_advanced_revealed();
	}

	public static function is_enabled( array $settings = array() ): bool {
		if ( empty( $settings ) ) {
			$settings = Settings::get_all();
		}

		return ! empty( $settings[ self::SETTING_ENABLED ] );
	}

	public static function is_active( array $settings = array() ): bool {
		if ( empty( $settings ) ) {
			$settings = Settings::get_all();
		}

		return self::is_available( $settings ) && self::is_enabled( $settings );
	}

	public static function feature_enabled( string $key, array $settings = array() ): bool {
		$key = sanitize_key( $key );
		if ( '' === $key || ! isset( self::feature_map()[ $key ] ) ) {
			return false;
		}
		if ( empty( $settings ) ) {
			$settings = Settings::get_all();
		}
		if ( ! self::is_active( $settings ) ) {
			return false;
		}

		return ! empty( $settings[ $key ] );
	}

	public static function feature_statuses( array $settings = array() ): array {
		if ( empty( $settings ) ) {
			$settings = Settings::get_all();
		}

		$statuses = array();
		foreach ( self::feature_map() as $key => $meta ) {
			$statuses[] = array(
				'key'         => $key,
				'label'       => (string) ( $meta['label'] ?? $key ),
				'description' => (string) ( $meta['description'] ?? '' ),
				'enabled'     => self::feature_enabled( $key, $settings ),
			);
		}

		return $statuses;
	}
}
