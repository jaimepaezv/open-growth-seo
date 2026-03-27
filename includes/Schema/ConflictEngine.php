<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\Schema;

defined( 'ABSPATH' ) || exit;

class ConflictEngine {
	/**
	 * @param array<string, mixed> $context
	 * @param array<string, mixed> $settings
	 * @param array<int, array<string, mixed>> $graph
	 * @param array<int, array<string, mixed>> $node_reports
	 * @param array<int, string> $warnings
	 * @param array<int, string> $errors
	 * @return array<int, array<string, mixed>>
	 */
	public static function detect( array $context, array $settings, array $graph, array $node_reports, array $warnings, array $errors ): array {
		$conflicts = array();
		$is_noindex = ! empty( $context['is_noindex'] );
		$redirect_target = isset( $context['redirect_target'] ) ? (string) $context['redirect_target'] : '';
		$is_redirected = '' !== trim( $redirect_target );
		$post_type = (string) ( $context['post_type'] ?? '' );
		$is_singular = ! empty( $context['is_singular'] );
		$manual_override = isset( $context['schema_override'] ) ? trim( (string) $context['schema_override'] ) : '';
		$manual_override_allowed = ! empty( $context['schema_override_eligible'] );

		$content_nodes = array();
		$global_nodes  = array();
		foreach ( $graph as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$type = isset( $node['@type'] ) ? (string) $node['@type'] : '';
			if ( '' === $type ) {
				continue;
			}
			if ( in_array( $type, array( 'Organization', 'WebSite', 'WebPage', 'BreadcrumbList', 'LocalBusiness' ), true ) ) {
				$global_nodes[] = $type;
				continue;
			}
			$content_nodes[] = $type;
		}

		if ( $is_noindex && ! empty( $content_nodes ) ) {
			$conflicts[] = self::row(
				'schema_noindex_overlap',
				'important',
				__( 'Content schema is active on a noindex URL.', 'open-growth-seo' ),
				__( 'Keep this only when intentionally needed for non-indexing consumers; otherwise remove override or switch to indexable target URL.', 'open-growth-seo' )
			);
		}

		if ( $is_redirected && ! empty( $content_nodes ) ) {
			$conflicts[] = self::row(
				'schema_redirect_overlap',
				'important',
				__( 'Content schema appears on a URL state with redirect consolidation.', 'open-growth-seo' ),
				__( 'Prefer output on the final canonical target and avoid contradictory legacy URL signals.', 'open-growth-seo' )
			);
		}

		if ( '' !== $manual_override && ! $manual_override_allowed ) {
			$conflicts[] = self::row(
				'manual_override_ineligible',
				'important',
				sprintf(
					/* translators: %s: schema type */
					__( 'Manual schema override "%s" is not eligible for this content.', 'open-growth-seo' ),
					$manual_override
				),
				__( 'Switch to Auto or make visible content match the selected schema type.', 'open-growth-seo' )
			);
		}

		$blocking_reports = array_filter(
			$node_reports,
			static function ( $report ): bool {
				return is_array( $report ) && in_array( (string) ( $report['status'] ?? '' ), array( 'blocked', 'conflicting' ), true );
			}
		);
		foreach ( $blocking_reports as $report ) {
			$type = isset( $report['type'] ) ? (string) $report['type'] : '';
			$message = isset( $report['messages'][0] ) ? (string) $report['messages'][0] : __( 'Schema validation failed.', 'open-growth-seo' );
			$conflicts[] = self::row(
				'schema_validation_' . sanitize_key( strtolower( $type ) ),
				'important',
				$message,
				__( 'Review required fields and eligibility state before forcing this type.', 'open-growth-seo' )
			);
		}

		if ( ! empty( $settings['schema_local_business_enabled'] ) && 'product' === $post_type && in_array( 'LocalBusiness', $global_nodes, true ) ) {
			$conflicts[] = self::row(
				'local_business_product_overlap',
				'minor',
				__( 'LocalBusiness appears in product context. Confirm this page is truly the primary location landing page.', 'open-growth-seo' ),
				__( 'Keep LocalBusiness on dedicated location pages and avoid overloading product pages.', 'open-growth-seo' )
			);
		}

		if ( ! $is_singular ) {
			foreach ( $content_nodes as $type ) {
				if ( in_array( $type, array( 'Article', 'BlogPosting', 'NewsArticle', 'FAQPage', 'QAPage', 'Event', 'JobPosting', 'Recipe', 'Dataset', 'SoftwareApplication', 'DiscussionForumPosting', 'VideoObject', 'Product' ), true ) ) {
					$conflicts[] = self::row(
						'non_singular_content_type',
						'important',
						sprintf(
							/* translators: %s: schema type */
							__( '%s should not emit on non-singular contexts.', 'open-growth-seo' ),
							$type
						),
						__( 'Use archive-safe schema and keep content entity markup on singular URLs.', 'open-growth-seo' )
					);
				}
			}
		}

		foreach ( $warnings as $warning ) {
			$message = sanitize_text_field( (string) $warning );
			if ( '' === $message ) {
				continue;
			}
			if ( str_contains( strtolower( $message ), 'unsupported' ) || str_contains( strtolower( $message ), 'skipped' ) ) {
				$conflicts[] = self::row(
					'runtime_warning',
					'minor',
					$message,
					__( 'Use Runtime Inspector to verify effective emitted nodes.', 'open-growth-seo' )
				);
			}
		}

		foreach ( $errors as $error ) {
			$message = sanitize_text_field( (string) $error );
			if ( '' === $message ) {
				continue;
			}
			$conflicts[] = self::row(
				'runtime_error',
				'critical',
				$message,
				__( 'Fix schema validation blockers before relying on rich-result eligibility.', 'open-growth-seo' )
			);
		}

		$canonical = isset( $context['canonical_diagnostics'] ) && is_array( $context['canonical_diagnostics'] ) ? $context['canonical_diagnostics'] : array();
		$canonical_conflicts = isset( $canonical['conflicts'] ) && is_array( $canonical['conflicts'] ) ? $canonical['conflicts'] : array();
		foreach ( $canonical_conflicts as $canonical_conflict ) {
			if ( ! is_array( $canonical_conflict ) ) {
				continue;
			}
			$severity = isset( $canonical_conflict['severity'] ) ? sanitize_key( (string) $canonical_conflict['severity'] ) : 'minor';
			$message  = sanitize_text_field( (string) ( $canonical_conflict['message'] ?? '' ) );
			$recommendation = sanitize_text_field( (string) ( $canonical_conflict['recommendation'] ?? '' ) );
			if ( '' === $message ) {
				continue;
			}
			$conflicts[] = self::row(
				'canonical_' . sanitize_key( (string) ( $canonical_conflict['code'] ?? 'conflict' ) ),
				in_array( $severity, array( 'critical', 'important', 'minor' ), true ) ? $severity : 'minor',
				$message,
				'' !== $recommendation ? $recommendation : __( 'Review canonical policy diagnostics and align URL state signals.', 'open-growth-seo' )
			);
		}

		$support_matrix = SupportMatrix::content_types();
		foreach ( $content_nodes as $type ) {
			$meta = isset( $support_matrix[ $type ] ) && is_array( $support_matrix[ $type ] ) ? $support_matrix[ $type ] : array();
			if ( empty( $meta ) ) {
				continue;
			}
			if ( SupportMatrix::SUPPORT_VOCAB === (string) ( $meta['support'] ?? '' ) ) {
				$conflicts[] = self::row(
					'vocabulary_only_' . sanitize_key( strtolower( $type ) ),
					'minor',
					sprintf(
						/* translators: %s: schema type */
						__( '%s is syntax-valid schema vocabulary but not a guaranteed rich-result type.', 'open-growth-seo' ),
						$type
					),
					__( 'Treat this as semantic enrichment and validate rich-result expectations separately.', 'open-growth-seo' )
				);
			}
		}

		$missing_refs = self::missing_graph_references( $graph );
		if ( ! empty( $missing_refs ) ) {
			$sample = array_slice( $missing_refs, 0, 3 );
			$conflicts[] = self::row(
				'missing_graph_reference',
				'important',
				sprintf(
					/* translators: %s: missing graph ids */
					__( 'Schema graph contains unresolved internal references: %s', 'open-growth-seo' ),
					implode( ', ', $sample )
				),
				__( 'Re-enable the referenced global node or remove the relation so runtime output matches configured graph settings.', 'open-growth-seo' )
			);
		}

		return self::dedupe( $conflicts );
	}

	/**
	 * @param array<int, array<string, mixed>> $conflicts
	 * @return array<int, array<string, mixed>>
	 */
	public static function summary( array $conflicts ): array {
		$counts = array(
			'critical'  => 0,
			'important' => 0,
			'minor'     => 0,
		);
		foreach ( $conflicts as $conflict ) {
			if ( ! is_array( $conflict ) ) {
				continue;
			}
			$severity = isset( $conflict['severity'] ) ? sanitize_key( (string) $conflict['severity'] ) : 'minor';
			if ( isset( $counts[ $severity ] ) ) {
				++$counts[ $severity ];
			}
		}
		return array(
			'total'    => count( $conflicts ),
			'critical' => $counts['critical'],
			'important' => $counts['important'],
			'minor'    => $counts['minor'],
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function row( string $code, string $severity, string $message, string $recommendation ): array {
		return array(
			'code'           => sanitize_key( $code ),
			'severity'       => sanitize_key( $severity ),
			'message'        => $message,
			'recommendation' => $recommendation,
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $conflicts
	 * @return array<int, array<string, mixed>>
	 */
	private static function dedupe( array $conflicts ): array {
		$seen  = array();
		$clean = array();
		foreach ( $conflicts as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$key = sanitize_key( (string) ( $row['code'] ?? '' ) ) . '|' . md5( (string) ( $row['message'] ?? '' ) );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$clean[]      = $row;
		}
		return $clean;
	}

	/**
	 * @param array<int, array<string, mixed>> $graph
	 * @return array<int, string>
	 */
	private static function missing_graph_references( array $graph ): array {
		$node_ids = array();
		$refs     = array();

		foreach ( $graph as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			if ( ! empty( $node['@id'] ) && is_string( $node['@id'] ) ) {
				$node_ids[] = trim( $node['@id'] );
			}
			foreach ( $node as $key => $value ) {
				if ( '@id' === $key ) {
					continue;
				}
				$refs = array_merge( $refs, self::extract_reference_ids( $value ) );
			}
		}

		$node_ids = array_values( array_unique( array_filter( array_map( 'trim', $node_ids ) ) ) );
		$refs     = array_values( array_unique( array_filter( array_map( 'trim', $refs ) ) ) );

		return array_values( array_diff( $refs, $node_ids ) );
	}

	/**
	 * @param mixed $value
	 * @return array<int, string>
	 */
	private static function extract_reference_ids( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$refs = array();
		if ( isset( $value['@id'] ) && is_string( $value['@id'] ) ) {
			$refs[] = trim( $value['@id'] );
		}

		foreach ( $value as $child ) {
			if ( is_array( $child ) ) {
				$refs = array_merge( $refs, self::extract_reference_ids( $child ) );
			}
		}

		return $refs;
	}
}
