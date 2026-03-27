<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\Installation;

use OpenGrowthSolutions\OpenGrowthSEO\Core\Defaults;
use OpenGrowthSolutions\OpenGrowthSEO\SEO\LocalSeoLocations;
use OpenGrowthSolutions\OpenGrowthSEO\Support\ExperienceMode;

defined( 'ABSPATH' ) || exit;

class Configurator {
	public function recommendations( array $diagnostics, array $current_settings ): array {
		$signals         = isset( $diagnostics['signals'] ) && is_array( $diagnostics['signals'] ) ? $diagnostics['signals'] : array();
		$classification  = isset( $diagnostics['classification'] ) && is_array( $diagnostics['classification'] ) ? $diagnostics['classification'] : array();
		$compatibility   = isset( $diagnostics['compatibility'] ) && is_array( $diagnostics['compatibility'] ) ? $diagnostics['compatibility'] : array();
		$languages       = isset( $signals['languages'] ) && is_array( $signals['languages'] ) ? $signals['languages'] : array();
		$primary         = isset( $classification['primary'] ) ? sanitize_key( (string) $classification['primary'] ) : 'general';
		$feature_plugins = isset( $signals['feature_plugins'] ) && is_array( $signals['feature_plugins'] ) ? $signals['feature_plugins'] : array();
		$recommendations = array();

		$recommendations['mode'] = $this->recommendation(
			'mode',
			ExperienceMode::normalize( (string) ( $current_settings['mode'] ?? 'simple' ) ),
			'recommended',
			__( 'Start in Simple mode to reduce initial configuration risk.', 'open-growth-seo' )
		);
		$recommendations['safe_mode_seo_conflict'] = $this->recommendation(
			'safe_mode_seo_conflict',
			! empty( $compatibility['active_seo_conflicts'] ) ? 1 : 0,
			'detected',
			__( 'Safe mode follows detected coexistence risk with another SEO plugin.', 'open-growth-seo' )
		);
		$expert_candidate = ExperienceMode::ADVANCED === ExperienceMode::normalize( (string) ( $current_settings['mode'] ?? 'simple' ) )
			|| in_array( $primary, array( 'ecommerce', 'marketplace', 'directory', 'support' ), true );
		$recommendations['seo_masters_plus_enabled'] = $this->recommendation(
			'seo_masters_plus_enabled',
			$expert_candidate ? 1 : 0,
			'recommended',
			__( 'SEO MASTERS PLUS should remain off for novice-first installs and turn on for advanced operational workflows.', 'open-growth-seo' )
		);
		$recommendations['default_index'] = $this->recommendation(
			'default_index',
			! empty( $diagnostics['runtime']['blog_public'] ) ? 'index,follow' : 'noindex,follow',
			'detected',
			__( 'Default indexability should mirror the current site visibility setting.', 'open-growth-seo' )
		);
		$recommendations['robots_mode'] = $this->recommendation(
			'robots_mode',
			'managed',
			'recommended',
			__( 'Managed robots mode is the safest baseline for new installations.', 'open-growth-seo' )
		);
		$recommendations['sitemap_enabled'] = $this->recommendation(
			'sitemap_enabled',
			1,
			'recommended',
			__( 'Sitemaps should be enabled by default for discoverability.', 'open-growth-seo' )
		);
		$recommendations['sitemap_post_types'] = $this->recommendation(
			'sitemap_post_types',
			$this->recommended_sitemap_post_types( $signals, $primary ),
			'detected',
			__( 'Included post types are based on detected public content models.', 'open-growth-seo' )
		);
		$recommendations['schema_org_name'] = $this->recommendation(
			'schema_org_name',
			sanitize_text_field( (string) ( $signals['site_name'] ?? '' ) ),
			'detected',
			__( 'Organization name can be safely inferred from the site name.', 'open-growth-seo' )
		);
		$recommendations['schema_org_logo'] = $this->recommendation(
			'schema_org_logo',
			esc_url_raw( (string) ( $signals['site_icon_url'] ?? '' ) ),
			'detected',
			__( 'Site icon can seed organization logo when available.', 'open-growth-seo' )
		);
		$recommendations['gsc_property_url'] = $this->recommendation(
			'gsc_property_url',
			esc_url_raw( (string) ( $diagnostics['runtime']['site_url'] ?? '' ) ),
			'detected',
			__( 'Property URL can be prefilled from the canonical site URL.', 'open-growth-seo' )
		);
		$recommendations['bing_site_url'] = $this->recommendation(
			'bing_site_url',
			esc_url_raw( (string) ( $diagnostics['runtime']['site_url'] ?? '' ) ),
			'detected',
			__( 'Bing site URL can be prefilled from the canonical site URL.', 'open-growth-seo' )
		);
		$recommendations['hreflang_enabled'] = $this->recommendation(
			'hreflang_enabled',
			count( $languages ) > 1 ? 1 : 0,
			count( $languages ) > 1 ? 'detected' : 'recommended',
			__( 'hreflang is enabled automatically only when multiple languages are detected.', 'open-growth-seo' )
		);
		$recommendations['schema_article_enabled'] = $this->recommendation(
			'schema_article_enabled',
			in_array( $primary, array( 'blog', 'general', 'corporate', 'saas', 'support' ), true ) || ! empty( ( $signals['post_counts']['post'] ?? 0 ) ) ? 1 : (int) ( $current_settings['schema_article_enabled'] ?? 1 ),
			'recommended',
			__( 'Article schema remains enabled when editorial content exists.', 'open-growth-seo' )
		);
		$recommendations['schema_local_business_enabled'] = $this->recommendation(
			'schema_local_business_enabled',
			in_array( $primary, array( 'corporate', 'directory', 'landing_page' ), true ) ? 1 : (int) ( $current_settings['schema_local_business_enabled'] ?? 0 ),
			'recommended',
			__( 'LocalBusiness schema is recommended for company, directory, and brochure-style sites.', 'open-growth-seo' )
		);
		$recommendations['schema_discussion_enabled'] = $this->recommendation(
			'schema_discussion_enabled',
			! empty( $feature_plugins['forum'] ) || 'forum' === $primary ? 1 : (int) ( $current_settings['schema_discussion_enabled'] ?? 1 ),
			'recommended',
			__( 'Discussion schema is recommended for community and forum-driven sites.', 'open-growth-seo' )
		);
		$recommendations['schema_qapage_enabled'] = $this->recommendation(
			'schema_qapage_enabled',
			in_array( $primary, array( 'forum', 'support' ), true ) ? 1 : (int) ( $current_settings['schema_qapage_enabled'] ?? 1 ),
			'recommended',
			__( 'Q&A schema is most useful when support or discussion content is prominent.', 'open-growth-seo' )
		);

		if ( ! empty( $feature_plugins['woocommerce'] ) || in_array( $primary, array( 'ecommerce', 'marketplace' ), true ) ) {
			$recommendations['woo_schema_mode'] = $this->recommendation(
				'woo_schema_mode',
				'native',
				'recommended',
				__( 'WooCommerce native Product schema is the safest initial source.', 'open-growth-seo' )
			);
			$recommendations['woo_product_archive'] = $this->recommendation(
				'woo_product_archive',
				'index',
				'recommended',
				__( 'Product archives should stay indexable for commerce sites.', 'open-growth-seo' )
			);
			$recommendations['woo_product_cat_archive'] = $this->recommendation(
				'woo_product_cat_archive',
				'index',
				'recommended',
				__( 'Product category archives usually deserve indexability.', 'open-growth-seo' )
			);
			$recommendations['woo_product_tag_archive'] = $this->recommendation(
				'woo_product_tag_archive',
				'noindex',
				'recommended',
				__( 'Product tag archives default to noindex to reduce thin index bloat.', 'open-growth-seo' )
			);
			$recommendations['woo_filter_results'] = $this->recommendation(
				'woo_filter_results',
				'noindex',
				'recommended',
				__( 'Faceted/filter URLs should default to noindex unless there is a deliberate index strategy.', 'open-growth-seo' )
			);
			$recommendations['woo_filter_canonical_target'] = $this->recommendation(
				'woo_filter_canonical_target',
				'base',
				'recommended',
				__( 'Faceted/filter URL canonicals should default to the base archive URL to reduce duplication.', 'open-growth-seo' )
			);
			$recommendations['woo_pagination_results'] = $this->recommendation(
				'woo_pagination_results',
				'index',
				'recommended',
				__( 'Paginated product archives can remain indexable when they expose meaningful inventory pages.', 'open-growth-seo' )
			);
			$recommendations['woo_pagination_canonical_target'] = $this->recommendation(
				'woo_pagination_canonical_target',
				'self',
				'recommended',
				__( 'Paginated archives should self-canonicalize by default for deterministic page-series behavior.', 'open-growth-seo' )
			);
		}

		return apply_filters( 'ogs_seo_installation_recommendations', $recommendations, $diagnostics, $current_settings );
	}

	public function pending_items( array $diagnostics, array $settings ): array {
		$pending   = array();
		$primary   = sanitize_key( (string) ( $diagnostics['classification']['primary'] ?? 'general' ) );
		$conflicts = isset( $diagnostics['compatibility']['active_seo_conflicts'] ) && is_array( $diagnostics['compatibility']['active_seo_conflicts'] ) ? $diagnostics['compatibility']['active_seo_conflicts'] : array();

		if ( ! empty( $settings['schema_local_business_enabled'] ) || in_array( $primary, array( 'corporate', 'directory', 'landing_page' ), true ) ) {
			$locations       = LocalSeoLocations::records( $settings );
			$has_publishable = false;
			foreach ( $locations as $record ) {
				if ( LocalSeoLocations::is_publishable( $record ) ) {
					$has_publishable = true;
					break;
				}
			}
			if ( ! $has_publishable ) {
				$pending[] = array(
					'type'    => 'setting',
					'key'     => 'local_locations',
					'label'   => __( 'Local location records', 'open-growth-seo' ),
					'message' => __( 'Local business markup is enabled, but no complete published location record is available yet.', 'open-growth-seo' ),
				);
			}
			if ( empty( $locations ) ) {
				foreach ( array( 'schema_local_phone', 'schema_local_city', 'schema_local_country' ) as $key ) {
					if ( '' === trim( (string) ( $settings[ $key ] ?? '' ) ) ) {
						$pending[] = array(
							'type'    => 'setting',
							'key'     => $key,
							'label'   => $this->pending_label( $key ),
							'message' => __( 'Legacy local business fallback is incomplete. Configure full location records for reliable LocalBusiness output.', 'open-growth-seo' ),
						);
					}
				}
			}
		}
		if ( ! empty( $conflicts ) ) {
			$pending[] = array(
				'type'    => 'action',
				'key'     => 'review_seo_conflict',
				'label'   => __( 'Review SEO plugin coexistence', 'open-growth-seo' ),
				'message' => __( 'Another SEO plugin is active. Review migration or takeover before disabling safe mode.', 'open-growth-seo' ),
			);
		}
		if ( ! empty( $settings['gsc_enabled'] ) && '' === trim( (string) ( $settings['gsc_property_url'] ?? '' ) ) ) {
			$pending[] = array(
				'type'    => 'setting',
				'key'     => 'gsc_property_url',
				'label'   => __( 'Google Search Console property URL', 'open-growth-seo' ),
				'message' => __( 'Google Search Console is enabled but the property URL is still empty.', 'open-growth-seo' ),
			);
		}
		if ( ! empty( $settings['bing_enabled'] ) && '' === trim( (string) ( $settings['bing_site_url'] ?? '' ) ) ) {
			$pending[] = array(
				'type'    => 'setting',
				'key'     => 'bing_site_url',
				'label'   => __( 'Bing site URL', 'open-growth-seo' ),
				'message' => __( 'Bing integration is enabled but the site URL is still empty.', 'open-growth-seo' ),
			);
		}

		return $pending;
	}

	public function apply( array $current_settings, array $raw_settings, array $recommendations, array $previous_state, bool $fresh_install ): array {
		$updated          = $current_settings;
		$defaults         = Defaults::settings();
		$applied          = array();
		$skipped          = array();
		$raw_settings     = is_array( $raw_settings ) ? $raw_settings : array();
		$previous_applied = isset( $previous_state['applied_settings'] ) && is_array( $previous_state['applied_settings'] ) ? $previous_state['applied_settings'] : array();

		foreach ( $recommendations as $key => $definition ) {
			$key   = sanitize_key( (string) $key );
			$value = $definition['value'] ?? null;
			if ( '' === $key ) {
				continue;
			}

			$current_value  = $current_settings[ $key ] ?? null;
			$default_value  = $defaults[ $key ] ?? null;
			$previous_value = isset( $previous_applied[ $key ]['value'] ) ? $previous_applied[ $key ]['value'] : null;
			$should_apply   = false;

			if ( $fresh_install ) {
				$should_apply = true;
			} elseif ( self::is_empty_value( $current_value ) && ! self::is_empty_value( $value ) ) {
				$should_apply = true;
			} elseif ( array_key_exists( $key, $raw_settings ) ) {
				$should_apply = null !== $previous_value && self::values_equal( $current_value, $previous_value );
			} else {
				$should_apply = self::values_equal( $current_value, $default_value ) || ( null !== $previous_value && self::values_equal( $current_value, $previous_value ) );
			}

			if ( ! $should_apply || self::values_equal( $current_value, $value ) ) {
				$skipped[ $key ] = array(
					'value'  => $value,
					'reason' => $definition['reason'] ?? '',
					'source' => $definition['source'] ?? 'recommended',
				);
				continue;
			}

			$updated[ $key ] = $value;
			$applied[ $key ] = array(
				'value'  => $value,
				'reason' => $definition['reason'] ?? '',
				'source' => $definition['source'] ?? 'recommended',
			);
		}

		return array(
			'settings' => $updated,
			'applied'  => $applied,
			'skipped'  => $skipped,
			'changed'  => count( $applied ),
		);
	}

	private function recommendation( string $key, $value, string $source, string $reason ): array {
		return array(
			'key'    => sanitize_key( $key ),
			'value'  => $value,
			'source' => sanitize_key( $source ),
			'reason' => sanitize_text_field( $reason ),
		);
	}

	private function recommended_sitemap_post_types( array $signals, string $primary ): array {
		$post_types  = array_map( 'sanitize_key', (array) ( $signals['public_post_types'] ?? array() ) );
		$post_counts = isset( $signals['post_counts'] ) && is_array( $signals['post_counts'] ) ? $signals['post_counts'] : array();
		$selected    = array();

		if ( in_array( 'page', $post_types, true ) ) {
			$selected[] = 'page';
		}
		if ( in_array( 'post', $post_types, true ) && ( ! empty( $post_counts['post'] ) || in_array( $primary, array( 'blog', 'general', 'corporate', 'support', 'saas' ), true ) ) ) {
			$selected[] = 'post';
		}
		if ( in_array( 'product', $post_types, true ) ) {
			$selected[] = 'product';
		}
		foreach ( array( 'course', 'sfwd-courses', 'llms_course', 'tutor_course', 'forum', 'topic' ) as $candidate ) {
			if ( in_array( $candidate, $post_types, true ) ) {
				$selected[] = $candidate;
			}
		}
		foreach ( $post_types as $post_type ) {
			if ( preg_match( '/(listing|directory|property|job)/', $post_type ) ) {
				$selected[] = $post_type;
			}
		}

		if ( empty( $selected ) ) {
			$selected = array_intersect( array( 'post', 'page' ), $post_types );
		}

		return array_values( array_unique( array_map( 'sanitize_key', $selected ) ) );
	}

	private function pending_label( string $key ): string {
		$labels = array(
			'schema_local_phone'   => __( 'Local business phone', 'open-growth-seo' ),
			'schema_local_city'    => __( 'Local business city', 'open-growth-seo' ),
			'schema_local_country' => __( 'Local business country', 'open-growth-seo' ),
			'local_locations'      => __( 'Local location records', 'open-growth-seo' ),
		);
		return $labels[ $key ] ?? $key;
	}

	private static function is_empty_value( $value ): bool {
		if ( is_array( $value ) ) {
			return empty( $value );
		}
		return '' === trim( (string) $value );
	}

	private static function values_equal( $left, $right ): bool {
		if ( is_array( $left ) || is_array( $right ) ) {
			return wp_json_encode( $left ) === wp_json_encode( $right );
		}
		return (string) $left === (string) $right;
	}
}
