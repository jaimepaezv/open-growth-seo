<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\SEO;

defined( 'ABSPATH' ) || exit;

class CanonicalPolicy {
	/**
	 * @param array<string, mixed> $settings
	 * @param array<string, mixed> $context
	 * @return array<string, mixed>
	 */
	public static function resolve( array $settings, array $context ): array {
		$enabled = ! empty( $settings['canonical_enabled'] );
		$self    = self::normalize_url( (string) ( $context['self_url'] ?? '' ), false );
		$manual  = self::normalize_url( (string) ( $context['manual_canonical'] ?? '' ), false );
		$base    = self::normalize_url( (string) ( $context['contextual_canonical'] ?? '' ), true );
		$fallback = self::normalize_url( (string) ( $context['fallback_canonical'] ?? '' ), true );
		$redirect_target = self::normalize_url( (string) ( $context['redirect_target'] ?? '' ), false );

		$intended        = '';
		$intended_source = 'none';
		if ( '' !== $manual ) {
			$intended        = $manual;
			$intended_source = 'manual';
		} elseif ( '' !== $base ) {
			$intended        = $base;
			$intended_source = 'contextual';
		} elseif ( '' !== $fallback ) {
			$intended        = $fallback;
			$intended_source = 'fallback';
		}

		$effective        = $intended;
		$effective_source = $intended_source;
		$conflicts        = array();
		$has_redirect     = '' !== $redirect_target;

		if ( ! $enabled ) {
			return array(
				'enabled'          => false,
				'intended'         => $intended,
				'intended_source'  => $intended_source,
				'effective'        => '',
				'effective_source' => 'disabled',
				'reason'           => __( 'Canonical output is disabled in plugin settings.', 'open-growth-seo' ),
				'conflicts'        => array(),
				'messages'         => array(),
			);
		}

		if ( $has_redirect && '' !== $manual && $manual !== $redirect_target ) {
			$conflicts[] = self::conflict(
				'redirect_canonical_conflict',
				'important',
				__( 'An enabled redirect points to a different URL than the manual canonical override.', 'open-growth-seo' ),
				__( 'Prefer redirect-driven consolidation and align canonical with the final destination.', 'open-growth-seo' )
			);
		}

		$is_filter_surface = ! empty( $context['is_filter_surface'] );
		if ( $is_filter_surface ) {
			$filter_policy = sanitize_key( (string) ( $context['filter_canonical_policy'] ?? 'base' ) );
			if ( ! in_array( $filter_policy, array( 'base', 'self', 'none' ), true ) ) {
				$filter_policy = 'base';
			}
			$filter_base = self::normalize_url( (string) ( $context['filter_base_url'] ?? '' ), true );
			if ( 'none' === $filter_policy ) {
				$effective        = '';
				$effective_source = 'filter_none';
			} elseif ( 'base' === $filter_policy ) {
				if ( '' !== $filter_base ) {
					$effective        = $filter_base;
					$effective_source = 'filter_base';
				} else {
					$conflicts[] = self::conflict(
						'filter_base_missing',
						'minor',
						__( 'Filter URL canonicalization is set to base URL, but no stable base URL could be resolved.', 'open-growth-seo' ),
						__( 'Use self-canonical for this filter surface or review archive URL structure.', 'open-growth-seo' )
					);
				}
			}
		}

		$is_paged = ! empty( $context['is_paged'] );
		if ( $is_paged ) {
			$pagination_policy = sanitize_key( (string) ( $context['pagination_canonical_policy'] ?? 'self' ) );
			if ( ! in_array( $pagination_policy, array( 'self', 'first_page' ), true ) ) {
				$pagination_policy = 'self';
			}
			$page_base = self::normalize_url( (string) ( $context['pagination_base_url'] ?? '' ), true );
			if ( 'first_page' === $pagination_policy ) {
				if ( '' !== $page_base ) {
					$effective        = $page_base;
					$effective_source = 'pagination_first_page';
				} else {
					$conflicts[] = self::conflict(
						'pagination_base_missing',
						'minor',
						__( 'Pagination canonical is set to first page, but the first-page URL could not be resolved.', 'open-growth-seo' ),
						__( 'Use self-canonical pagination or fix permalink routing for this archive.', 'open-growth-seo' )
					);
				}
			}
		}

		if ( $has_redirect ) {
			if ( '' !== $effective && $effective !== $redirect_target ) {
				$conflicts[] = self::conflict(
					'redirect_surface_policy_conflict',
					'important',
					__( 'Archive/filter canonical policy conflicts with an enabled redirect target.', 'open-growth-seo' ),
					__( 'Keep one deterministic consolidation strategy and align archive/filter canonical policy with redirect intent.', 'open-growth-seo' )
				);
			}
			$effective        = $redirect_target;
			$effective_source = 'redirect';
		}

		$is_noindex      = ! empty( $context['is_noindex'] );
		$sitemap_included = ! empty( $context['sitemap_included'] );
		if ( $is_filter_surface && 'filter_none' === $effective_source && ! $is_noindex ) {
			$conflicts[] = self::conflict(
				'indexable_filter_without_canonical',
				'important',
				__( 'Indexable faceted/filter URL is configured without canonical output.', 'open-growth-seo' ),
				__( 'Use base or self canonical policy when keeping faceted/filter URLs indexable.', 'open-growth-seo' )
			);
		}
		if ( $is_paged && 'pagination_first_page' === $effective_source && ! $is_noindex ) {
			$conflicts[] = self::conflict(
				'indexable_pagination_first_page_canonical',
				'minor',
				__( 'Paginated URL is indexable while canonicalizing to first page.', 'open-growth-seo' ),
				__( 'Use self-canonical pagination when paginated URLs are intended to remain indexable.', 'open-growth-seo' )
			);
		}
		if ( $is_noindex && $has_redirect ) {
			$conflicts[] = self::conflict(
				'noindex_redirect_overlap',
				'important',
				__( 'URL has both noindex intent and redirect consolidation signals.', 'open-growth-seo' ),
				__( 'Use redirects for deprecated URLs and keep noindex for URLs that remain independently accessible.', 'open-growth-seo' )
			);
		}
		if ( $is_noindex && '' !== $manual ) {
			$conflicts[] = self::conflict(
				'noindex_manual_canonical',
				'important',
				__( 'This URL is noindex while also using a manual canonical override.', 'open-growth-seo' ),
				__( 'If the URL should not remain indexable, prefer redirect consolidation or remove unnecessary canonical overrides.', 'open-growth-seo' )
			);
		}
		if ( $sitemap_included && $is_noindex ) {
			$conflicts[] = self::conflict(
				'sitemap_noindex_conflict',
				'important',
				__( 'Sitemap inclusion conflicts with noindex state.', 'open-growth-seo' ),
				__( 'Exclude this URL from sitemap or make indexability intent explicit and consistent.', 'open-growth-seo' )
			);
		}
		if ( $sitemap_included && '' !== $self && '' !== $effective && $self !== $effective ) {
			$conflicts[] = self::conflict(
				'sitemap_non_self_canonical',
				'important',
				__( 'Sitemap URL does not match effective canonical target.', 'open-growth-seo' ),
				__( 'Keep only the canonical target indexable and remove duplicate source URLs from sitemap coverage.', 'open-growth-seo' )
			);
		}
		if ( $sitemap_included && '' === $effective ) {
			$conflicts[] = self::conflict(
				'sitemap_missing_canonical',
				'minor',
				__( 'Sitemap URL has no effective canonical target.', 'open-growth-seo' ),
				__( 'Keep canonical output deterministic for sitemap URLs or remove unstable surfaces from sitemap coverage.', 'open-growth-seo' )
			);
		}
		if ( '' !== $self && $has_redirect && $self === $redirect_target ) {
			$conflicts[] = self::conflict(
				'redirect_self_target',
				'minor',
				__( 'Redirect and canonical both point to the same URL state.', 'open-growth-seo' ),
				__( 'Keep one consolidation strategy and avoid redundant redirect/canonical overlap.', 'open-growth-seo' )
			);
		}

		$messages = array();
		foreach ( $conflicts as $conflict ) {
			$messages[] = (string) ( $conflict['message'] ?? '' );
		}

		$reason = self::decision_reason( $effective_source );

		return array(
			'enabled'          => true,
			'intended'         => $intended,
			'intended_source'  => $intended_source,
			'effective'        => $effective,
			'effective_source' => $effective_source,
			'reason'           => $reason,
			'conflicts'        => $conflicts,
			'messages'         => array_values( array_filter( $messages ) ),
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $rules
	 */
	public static function matching_redirect_target( string $request_path, array $rules ): string {
		$path = Redirects::normalize_source_path( $request_path );
		if ( '' === $path ) {
			return '';
		}

		$exact  = array();
		$prefix = array();
		foreach ( $rules as $rule ) {
			if ( empty( $rule['enabled'] ) ) {
				continue;
			}
			$source = Redirects::normalize_source_path( (string) ( $rule['source_path'] ?? '' ) );
			$type   = sanitize_key( (string) ( $rule['match_type'] ?? 'exact' ) );
			if ( '' === $source ) {
				continue;
			}
			if ( 'exact' === $type && $source === $path ) {
				$exact = $rule;
				break;
			}
			if ( 'prefix' === $type && ( $source === $path || str_starts_with( $path, $source . '/' ) ) ) {
				if ( empty( $prefix ) || strlen( (string) ( $prefix['source_path'] ?? '' ) ) < strlen( $source ) ) {
					$prefix = $rule;
				}
			}
		}

		$match       = ! empty( $exact ) ? $exact : $prefix;
		$destination = Redirects::normalize_destination_url( (string) ( $match['destination_url'] ?? '' ) );
		return '' !== $destination ? self::normalize_url( $destination, false ) : '';
	}

	/**
	 * @return array<string, string>
	 */
	private static function conflict( string $code, string $severity, string $message, string $recommendation ): array {
		return array(
			'code'           => sanitize_key( $code ),
			'severity'       => sanitize_key( $severity ),
			'message'        => $message,
			'recommendation' => $recommendation,
		);
	}

	private static function normalize_url( string $url, bool $filter_query ): string {
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
		$path   = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		if ( '' === $path ) {
			$path = '/';
		}
		$path = preg_replace( '#/+#', '/', $path );

		$query_args = array();
		if ( ! empty( $parts['query'] ) ) {
			parse_str( (string) $parts['query'], $query_args );
		}

		if ( $filter_query ) {
			$filtered = array();
			foreach ( array( 'paged', 'page', 'cpage', 's' ) as $allowed ) {
				if ( isset( $query_args[ $allowed ] ) && '' !== (string) $query_args[ $allowed ] ) {
					$filtered[ $allowed ] = sanitize_text_field( (string) $query_args[ $allowed ] );
				}
			}
			$query_args = $filtered;
		}

		$query = '';
		if ( ! empty( $query_args ) ) {
			$query = '?' . http_build_query( $query_args, '', '&', PHP_QUERY_RFC3986 );
		}

		return $scheme . '://' . $host . $path . $query;
	}

	private static function decision_reason( string $effective_source ): string {
		switch ( $effective_source ) {
			case 'manual':
				return __( 'Manual canonical override is active for this URL.', 'open-growth-seo' );
			case 'contextual':
				return __( 'Canonical uses the contextual URL resolved from current request state.', 'open-growth-seo' );
			case 'fallback':
				return __( 'Canonical falls back to WordPress canonical resolution.', 'open-growth-seo' );
			case 'redirect':
				return __( 'Canonical follows an enabled redirect target to keep consolidation deterministic.', 'open-growth-seo' );
			case 'filter_base':
				return __( 'Faceted/filter URL canonical policy points to the base archive URL.', 'open-growth-seo' );
			case 'filter_none':
				return __( 'Faceted/filter URL canonical output is intentionally disabled by policy.', 'open-growth-seo' );
			case 'pagination_first_page':
				return __( 'Paginated URL canonical policy points to the first page URL.', 'open-growth-seo' );
			case 'self':
				return __( 'Canonical points to the current URL.', 'open-growth-seo' );
			case 'none':
				return __( 'No canonical target was resolved for this URL state.', 'open-growth-seo' );
			default:
				return __( 'Canonical policy applied using current settings and URL state signals.', 'open-growth-seo' );
		}
	}
}
