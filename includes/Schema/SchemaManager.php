<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\Schema;

use OpenGrowthSolutions\OpenGrowthSEO\Compatibility\Detector;
use OpenGrowthSolutions\OpenGrowthSEO\SEO\Breadcrumbs;
use OpenGrowthSolutions\OpenGrowthSEO\SEO\CanonicalPolicy;
use OpenGrowthSolutions\OpenGrowthSEO\SEO\FrontendMeta;
use OpenGrowthSolutions\OpenGrowthSEO\SEO\LocalSeoLocations;
use OpenGrowthSolutions\OpenGrowthSEO\SEO\Redirects;
use OpenGrowthSolutions\OpenGrowthSEO\Support\Settings;

defined( 'ABSPATH' ) || exit;

class SchemaManager {
	private array $errors = array();
	private array $warnings = array();
	private array $node_reports = array();
	private array $conflicts = array();
	private array $type_statuses = array();

	public function register(): void {
		add_action( 'wp_head', array( $this, 'render' ), 20 );
		add_filter( 'ogs_seo_audit_checks', array( $this, 'register_audit_checks' ) );
	}

	public function render(): void {
		if ( is_admin() || is_feed() || $this->should_skip_output() ) {
			return;
		}
		$settings = Settings::get_all();
		$context  = $this->build_context( $settings );
		$payload  = $this->build_payload( $context, $settings );
		if ( empty( $payload['@graph'] ) ) {
			return;
		}
		echo '<script type="application/ld+json">' . wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
	}

	public function register_audit_checks( array $checks ): array {
		$checks['schema_health'] = array( $this, 'audit_schema_health' );
		return $checks;
	}

	public function audit_schema_health(): array {
		$settings = Settings::get_all();
		$sample   = get_posts(
			array(
				'post_type'      => get_post_types( array( 'public' => true ), 'names' ),
				'post_status'    => 'publish',
				'posts_per_page' => 6,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		if ( empty( $sample ) ) {
			return array();
		}

		$invalid   = 0;
		$thin      = 0;
		$conflicts = 0;
		foreach ( $sample as $post_id ) {
			$context = $this->build_context( $settings, (int) $post_id );
			$payload = $this->build_payload( $context, $settings );
			if ( empty( $payload['@graph'] ) ) {
				++$thin;
				continue;
			}
			if ( ! empty( $this->errors ) ) {
				$invalid += count( $this->errors );
			}
			if ( ! empty( $this->conflicts ) ) {
				$conflicts += count( $this->conflicts );
			}
		}
		if ( 0 === $invalid && 0 === $thin && 0 === $conflicts ) {
			return array();
		}
		return array(
			'severity'       => $invalid > 0 || $conflicts > 0 ? 'important' : 'minor',
			'title'          => __( 'Schema quality issues detected', 'open-growth-seo' ),
			'recommendation' => sprintf(
				/* translators: 1: invalid count, 2: thin count, 3: conflict count */
				__( 'Review structured data setup: %1$d validation issue(s), %2$d page(s) with missing contextual schema, %3$d conflict(s).', 'open-growth-seo' ),
				$invalid,
				$thin,
				$conflicts
			),
		);
	}

	/**
	 * @param array<string, mixed> $probe
	 * @return array<string, mixed>
	 */
	public function inspect( array $probe = array(), bool $record_history = true ): array {
		$settings = Settings::get_all();
		$probe    = RuntimeInspector::normalize_probe( $probe );
		$context  = $this->build_context( $settings, (int) ( $probe['post_id'] ?? 0 ), (string) ( $probe['url'] ?? '' ) );
		$payload  = $this->build_payload( $context, $settings );
		$inspect = array(
			'context'       => array(
				'url'         => $context['url'],
				'post_id'     => $context['post_id'],
				'post_type'   => $context['post_type'],
				'is_singular' => ! empty( $context['is_singular'] ),
				'is_noindex'  => ! empty( $context['is_noindex'] ),
				'is_indexable' => ! empty( $context['is_indexable'] ),
				'is_location_landing' => ! empty( $context['is_location_landing'] ),
				'is_redirect' => '' !== (string) ( $context['redirect_target'] ?? '' ),
				'location_record' => isset( $context['location_record'] ) && is_array( $context['location_record'] ) ? $context['location_record'] : array(),
			),
			'payload'       => $payload,
			'errors'        => $this->errors,
			'warnings'      => $this->warnings,
			'node_reports'  => $this->node_reports,
			'type_statuses' => $this->type_statuses,
			'conflicts'     => $this->conflicts,
			'conflict_totals' => ConflictEngine::summary( $this->conflicts ),
			'support_matrix' => SupportMatrix::all(),
		);

		$inspect['summary'] = RuntimeInspector::summary( $inspect );
		if ( $record_history ) {
			$history_bundle      = RuntimeInspector::record_snapshot( $inspect );
			$inspect['snapshot'] = isset( $history_bundle['current'] ) && is_array( $history_bundle['current'] ) ? $history_bundle['current'] : array();
			$inspect['history']  = isset( $history_bundle['history'] ) && is_array( $history_bundle['history'] ) ? $history_bundle['history'] : array();
			$inspect['diff']     = isset( $history_bundle['diff'] ) && is_array( $history_bundle['diff'] ) ? $history_bundle['diff'] : array();
		} else {
			$inspect['snapshot'] = array();
			$inspect['history']  = array();
			$inspect['diff']     = array();
		}

		return $inspect;
	}

	private function build_payload( array $context, array $settings ): array {
		$this->errors       = array();
		$this->warnings     = array();
		$this->node_reports = array();
		$this->conflicts    = array();
		$this->type_statuses = $this->build_type_statuses( $context, $settings );

		$graph = array();
		if ( (int) $settings['schema_organization_enabled'] ) {
			$graph[] = $this->organization_node( $context, $settings );
		}
		if ( (int) $settings['schema_local_business_enabled'] ) {
			$graph[] = $this->local_business_node( $context, $settings );
		}
		if ( (int) $settings['schema_website_enabled'] ) {
			$graph[] = $this->website_node( $context, $settings );
		}
		if ( (int) $settings['schema_webpage_enabled'] ) {
			$graph[] = $this->webpage_node( $context, $settings );
		}
		if ( (int) $settings['schema_breadcrumb_enabled'] ) {
			$graph[] = $this->breadcrumb_node( $context );
		}

		$content_node = $this->content_node( $context, $settings );
		if ( ! empty( $content_node ) ) {
			$graph[] = $content_node;
		}

		$graph = apply_filters( 'ogs_seo_schema_graph', $graph, $context, $settings );
		$graph = $this->sanitize_graph( $graph );
		$graph = $this->dedupe_graph( $graph );

		$valid = array();
		foreach ( $graph as $node ) {
			$report = ValidationEngine::validate_node( $node, $context );
			$this->node_reports[] = $report;
			$status = isset( $report['status'] ) ? (string) $report['status'] : 'blocked';
			if ( in_array( $status, array( 'blocked' ), true ) ) {
				$messages = isset( $report['messages'] ) && is_array( $report['messages'] ) ? $report['messages'] : array();
				foreach ( $messages as $message ) {
					$this->errors[] = sanitize_text_field( (string) $message );
				}
				continue;
			}
			if ( in_array( $status, array( 'warning', 'conflicting' ), true ) ) {
				$messages = isset( $report['messages'] ) && is_array( $report['messages'] ) ? $report['messages'] : array();
				foreach ( $messages as $message ) {
					$this->warnings[] = sanitize_text_field( (string) $message );
				}
			}
			$valid[] = $node;
		}
		$emitted_types = array();
		foreach ( $valid as $node ) {
			$type = isset( $node['@type'] ) ? (string) $node['@type'] : '';
			if ( '' === $type ) {
				continue;
			}
			$emitted_types[ $type ] = true;
		}
		foreach ( $this->type_statuses as $type => $status_row ) {
			if ( ! is_array( $status_row ) ) {
				continue;
			}
			$this->type_statuses[ $type ]['emitted'] = isset( $emitted_types[ $type ] );
		}
		$this->conflicts = ConflictEngine::detect( $context, $settings, $valid, $this->node_reports, $this->warnings, $this->errors );

		return array(
			'@context' => 'https://schema.org',
			'@graph'   => array_values( $valid ),
		);
	}

	private function build_context( array $settings, int $forced_post_id = 0, string $forced_url = '' ): array {
		$post_id = $forced_post_id > 0 ? $forced_post_id : get_queried_object_id();
		$url     = '';
		if ( '' !== trim( $forced_url ) ) {
			$url = $this->normalize_url( $forced_url );
		}
		if ( '' === $url && $post_id > 0 ) {
			$resolved = get_permalink( $post_id );
			$url      = is_string( $resolved ) ? $this->normalize_url( $resolved ) : '';
		}
		if ( '' === $url ) {
			$url = $this->normalize_url( $this->current_url() );
		}
		if ( '' === $url ) {
			$url = $this->normalize_url( home_url( '/' ) );
		}
		if ( $post_id <= 0 && '' !== $url && function_exists( 'url_to_postid' ) ) {
			$inferred = absint( url_to_postid( $url ) );
			if ( $inferred > 0 ) {
				$post_id = $inferred;
			}
		}

		$post      = $post_id > 0 ? get_post( $post_id ) : null;
		$post_type = $post ? (string) $post->post_type : '';
		$content_raw = $post_id > 0 ? (string) get_post_field( 'post_content', $post_id ) : '';
		$content_signals = ContentSignals::analyze( $content_raw );
		$content_plain = isset( $content_signals['plain_text'] ) ? (string) $content_signals['plain_text'] : trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $content_raw ) ) );
		$is_runtime_context = '' === trim( $forced_url ) && $forced_post_id <= 0;
		$is_singular = $post_id > 0 && ( $is_runtime_context ? is_singular() : true );
		$is_author   = $is_runtime_context ? is_author() : false;
		$is_search   = $is_runtime_context ? is_search() : false;
		$is_woo_archive = class_exists( 'WooCommerce' ) && ( $is_runtime_context ? ( is_post_type_archive( 'product' ) || is_tax( 'product_cat' ) || is_tax( 'product_tag' ) ) : false );
		$is_noindex = $post_id > 0 ? $this->is_noindex_post( $post_id, $settings ) : false;
		$request_path = Redirects::normalize_source_path( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$redirect_target = CanonicalPolicy::matching_redirect_target( $request_path, Redirects::get_rules() );
		$schema_override = $post_id > 0 ? trim( (string) get_post_meta( $post_id, 'ogs_seo_schema_type', true ) ) : '';
		$schema_override_eligible = false;
		if ( '' !== $schema_override && $post_id > 0 ) {
			$override_eval = EligibilityEngine::evaluate_for_post( $schema_override, $post_id, $settings );
			$schema_override_eligible = ! empty( $override_eval['eligible'] );
		}
		$canonical_diagnostics = array();
		if ( $post_id > 0 ) {
			$canonical_diagnostics = ( new FrontendMeta() )->canonical_diagnostics_for_post( $post_id, $settings );
		}
		$location_record = $post_id > 0 ? LocalSeoLocations::record_for_post( $post_id, $settings ) : array();
		$is_location_landing = ! empty( $location_record );
		$is_indexable = ! $is_noindex && '' === $redirect_target;

		return array(
			'url'                      => $url,
			'post_id'                  => $post_id,
			'post_type'                => $post_type,
			'content_raw'              => $content_raw,
			'content_plain'            => $content_plain,
			'content_signals'          => $content_signals,
			'is_singular'              => $is_singular,
			'is_author'                => $is_author,
			'is_search'                => $is_search,
			'is_woo_archive'           => $is_woo_archive,
			'is_noindex'               => $is_noindex,
			'is_indexable'             => $is_indexable,
			'redirect_target'          => $redirect_target,
			'schema_override'          => $schema_override,
			'schema_override_eligible' => $schema_override_eligible,
			'canonical_diagnostics'    => $canonical_diagnostics,
			'is_location_landing'      => $is_location_landing,
			'location_record'          => $location_record,
			'settings'                 => $settings,
		);
	}

	/**
	 * @param array<string, mixed> $context
	 * @param array<string, mixed> $settings
	 * @return array<string, array<string, mixed>>
	 */
	private function build_type_statuses( array $context, array $settings ): array {
		$statuses      = array();
		$all_types     = SupportMatrix::all();
		$content_types = array_keys( SupportMatrix::content_types() );
		$post_id       = absint( $context['post_id'] ?? 0 );

		foreach ( $all_types as $type => $record ) {
			$setting_key = isset( $record['setting_key'] ) ? (string) $record['setting_key'] : '';
			$enabled     = '' === $setting_key ? true : ! empty( $settings[ $setting_key ] );
			$status      = $enabled ? 'eligible' : 'disabled';
			$eligible    = $enabled;
			$reason      = $enabled
				? __( 'Configured and available.', 'open-growth-seo' )
				: __( 'Disabled in schema settings.', 'open-growth-seo' );

			if ( in_array( $type, $content_types, true ) ) {
				if ( $post_id > 0 ) {
					$evaluation = EligibilityEngine::evaluate_for_post( $type, $post_id, $settings );
					$eligible   = ! empty( $evaluation['eligible'] );
					$status     = $eligible ? 'eligible' : 'blocked';
					$reason     = (string) ( $evaluation['reason'] ?? $reason );
				} elseif ( empty( $context['is_singular'] ) ) {
					$eligible = false;
					$status   = 'blocked';
					$reason   = __( 'Content-type schema requires singular content context.', 'open-growth-seo' );
				}
			}

			if ( 'LocalBusiness' === $type && $enabled ) {
				$candidate = LocalSeoLocations::schema_candidate_for_context( $context, $settings );
				$node      = isset( $candidate['node'] ) && is_array( $candidate['node'] ) ? $candidate['node'] : array();
				$warnings  = isset( $candidate['warnings'] ) && is_array( $candidate['warnings'] ) ? $candidate['warnings'] : array();
				if ( empty( $node ) ) {
					$eligible = false;
					$status   = 'blocked';
					$reason   = ! empty( $warnings[0] ) ? sanitize_text_field( (string) $warnings[0] ) : __( 'No eligible location node for this URL context.', 'open-growth-seo' );
				}
			}

			if ( in_array( $type, array( 'Organization', 'CollegeOrUniversity' ), true ) && $enabled ) {
				$current_org_type = in_array( (string) ( $settings['schema_org_type'] ?? 'Organization' ), array( 'Organization', 'CollegeOrUniversity' ), true ) ? (string) $settings['schema_org_type'] : 'Organization';
				if ( $type !== $current_org_type ) {
					$eligible = false;
					$status   = 'blocked';
					$reason   = sprintf(
						/* translators: 1: current type */
						__( 'Global institution graph is currently configured to emit %s instead.', 'open-growth-seo' ),
						$current_org_type
					);
				}
			}

			$statuses[ $type ] = array(
				'type'          => $type,
				'label'         => (string) ( $record['label'] ?? $type ),
				'support'       => (string) ( $record['support'] ?? SupportMatrix::SUPPORT_VOCAB ),
				'support_label' => SupportMatrix::support_badge( (string) ( $record['support'] ?? SupportMatrix::SUPPORT_VOCAB ) ),
				'risk'          => (string) ( $record['risk'] ?? 'medium' ),
				'risk_label'    => SupportMatrix::risk_badge( (string) ( $record['risk'] ?? 'medium' ) ),
				'configured'    => $enabled,
				'eligible'      => $eligible,
				'status'        => $status,
				'reason'        => $reason,
				'emitted'       => false,
				'setting_key'   => $setting_key,
			);
		}

		return $statuses;
	}

	private function organization_node( array $context, array $settings ): array {
		$name = trim( (string) $settings['schema_org_name'] );
		if ( '' === $name ) {
			$name = (string) get_bloginfo( 'name' );
		}
		$type = in_array( (string) ( $settings['schema_org_type'] ?? 'Organization' ), array( 'Organization', 'CollegeOrUniversity' ), true )
			? (string) $settings['schema_org_type']
			: 'Organization';
		$node = array(
			'@type' => $type,
			'@id'   => home_url( '/#organization' ),
			'name'  => $name,
			'url'   => home_url( '/' ),
		);
		$logo = esc_url_raw( (string) $settings['schema_org_logo'] );
		if ( '' !== $logo ) {
			$node['logo'] = array(
				'@type' => 'ImageObject',
				'url'   => $logo,
			);
		}
		$sameas = $this->split_lines_urls( (string) $settings['schema_org_sameas'] );
		if ( ! empty( $sameas ) ) {
			$node['sameAs'] = $sameas;
		}
		$contact_point = $this->contact_point_node( $settings, home_url( '/' ) );
		if ( ! empty( $contact_point ) ) {
			$node['contactPoint'] = array( $contact_point );
		}
		return apply_filters( 'ogs_seo_schema_node', $node, $type, $context, $settings );
	}

	private function local_business_node( array $context, array $settings ): array {
		$candidate = LocalSeoLocations::schema_candidate_for_context( $context, $settings );
		$warnings  = isset( $candidate['warnings'] ) && is_array( $candidate['warnings'] ) ? $candidate['warnings'] : array();
		foreach ( $warnings as $warning ) {
			if ( is_string( $warning ) && '' !== trim( $warning ) ) {
				$this->warnings[] = $warning;
			}
		}
		$node = isset( $candidate['node'] ) && is_array( $candidate['node'] ) ? $candidate['node'] : array();
		if ( ! empty( $node ) ) {
			return apply_filters( 'ogs_seo_schema_node', $node, 'LocalBusiness', $context, $settings );
		}

		// When multi-location data exists, emit location schema only on matching location landing pages.
		if ( ! empty( LocalSeoLocations::records( $settings ) ) ) {
			return array();
		}

		$name = trim( (string) $settings['schema_org_name'] );
		if ( '' === $name ) {
			$name = (string) get_bloginfo( 'name' );
		}
		$type = sanitize_text_field( (string) $settings['schema_local_business_type'] );
		if ( '' === $type ) {
			$type = 'LocalBusiness';
		}
		$address = array_filter(
			array(
				'@type'           => 'PostalAddress',
				'streetAddress'   => sanitize_text_field( (string) $settings['schema_local_street'] ),
				'addressLocality' => sanitize_text_field( (string) $settings['schema_local_city'] ),
				'addressRegion'   => sanitize_text_field( (string) $settings['schema_local_region'] ),
				'postalCode'      => sanitize_text_field( (string) $settings['schema_local_postal_code'] ),
				'addressCountry'  => sanitize_text_field( (string) $settings['schema_local_country'] ),
			)
		);
		$node = array(
			'@type'    => 'LocalBusiness',
			'@id'      => home_url( '/#localbusiness' ),
			'name'     => $name,
			'url'      => home_url( '/' ),
			'telephone' => sanitize_text_field( (string) $settings['schema_local_phone'] ),
			'address'  => count( $address ) > 1 ? $address : null,
		);
		if ( 'LocalBusiness' !== $type ) {
			$node['additionalType'] = 'https://schema.org/' . rawurlencode( $type );
		}
		return apply_filters( 'ogs_seo_schema_node', $node, 'LocalBusiness', $context, $settings );
	}

	private function website_node( array $context, array $settings ): array {
		$node = array(
			'@type' => 'WebSite',
			'@id'   => home_url( '/#website' ),
			'url'   => home_url( '/' ),
			'name'  => (string) get_bloginfo( 'name' ),
		);
		if ( $this->is_schema_type_enabled( 'Organization', $settings ) ) {
			$node['publisher'] = array( '@id' => home_url( '/#organization' ) );
		}
		if ( $this->supports_native_site_search() ) {
			$node['potentialAction'] = array(
				'@type'       => 'SearchAction',
				'target'      => home_url( '/?s={search_term_string}' ),
				'query-input' => 'required name=search_term_string',
			);
		}
		return apply_filters( 'ogs_seo_schema_node', $node, 'WebSite', $context, $settings );
	}

	private function webpage_node( array $context, array $settings ): array {
		$post_id = (int) $context['post_id'];
		$title   = $post_id > 0 ? get_the_title( $post_id ) : wp_get_document_title();
		$node = array(
			'@type'     => 'WebPage',
			'@id'       => $context['url'] . '#webpage',
			'url'       => $context['url'],
			'name'      => wp_strip_all_tags( (string) $title ),
			'inLanguage' => $this->language_code(),
		);
		if ( $this->is_schema_type_enabled( 'WebSite', $settings ) ) {
			$node['isPartOf'] = array( '@id' => home_url( '/#website' ) );
		}
		if ( ! empty( $context['is_woo_archive'] ) && 'collection' === (string) ( $settings['woo_archive_schema_mode'] ?? 'none' ) ) {
			$node['additionalType'] = 'https://schema.org/CollectionPage';
		}
		if ( ! empty( $context['is_singular'] ) && (int) $settings['schema_paywall_enabled'] && $this->is_paywalled( $post_id ) ) {
			$node['isAccessibleForFree'] = false;
			$node['hasPart'] = array(
				'@type'                 => 'WebPageElement',
				'isAccessibleForFree'   => false,
				'cssSelector'           => '.paywall,.premium,.subscriber-only',
			);
		}
		return apply_filters( 'ogs_seo_schema_node', $node, 'WebPage', $context, $settings );
	}

	private function breadcrumb_node( array $context ): array {
		$trail = Breadcrumbs::trail_items( (int) $context['post_id'] );
		if ( empty( $trail ) ) {
			return array();
		}

		$items = array();
		$position = 1;
		foreach ( $trail as $item ) {
			$name = isset( $item['name'] ) ? (string) $item['name'] : '';
			$url  = isset( $item['url'] ) ? (string) $item['url'] : '';
			if ( '' === $name ) {
				continue;
			}
			$row = array(
				'@type'    => 'ListItem',
				'position' => $position++,
				'name'     => $name,
			);
			if ( '' !== $url ) {
				$row['item'] = $url;
			}
			$items[] = $row;
		}

		$node = array(
			'@type'           => 'BreadcrumbList',
			'@id'             => $context['url'] . '#breadcrumb',
			'itemListElement' => $items,
		);
		return apply_filters( 'ogs_seo_schema_node', $node, 'BreadcrumbList', $context, $context['settings'] );
	}

	private function content_node( array $context, array $settings ): array {
		$post_id = (int) $context['post_id'];
		if ( ! empty( $context['is_author'] ) ) {
			if ( ! $this->is_schema_type_enabled( 'ProfilePage', $settings ) ) {
				$this->warnings[] = __( 'ProfilePage schema is disabled in settings.', 'open-growth-seo' );
				return array();
			}
			return $this->profile_page_node( $context, $settings );
		}
		if ( ! empty( $context['is_search'] ) ) {
			return array();
		}
		if ( ! empty( $context['is_singular'] ) && $post_id > 0 ) {
			if ( $this->is_noindex_post( $post_id, $settings ) ) {
				$this->warnings[] = __( 'Content schema skipped because this URL is explicitly marked noindex.', 'open-growth-seo' );
				return array();
			}
			$override = sanitize_text_field( (string) get_post_meta( $post_id, 'ogs_seo_schema_type', true ) );
			$type     = '';
			if ( '' !== $override ) {
				$eligibility = Eligibility::evaluate_for_post( $override, $post_id, $settings );
				if ( ! empty( $eligibility['eligible'] ) ) {
					$type = $override;
				} else {
					$this->warnings[] = sprintf(
						/* translators: 1: schema type, 2: reason */
						__( 'Schema override %1$s was ignored for this URL. %2$s', 'open-growth-seo' ),
						$override,
						(string) $eligibility['reason']
					);
				}
			}
			if ( '' === $type ) {
				$type = $this->detect_content_type( $post_id, $settings );
			}
			return $this->build_node_for_type( $type, $context, $settings );
		}
		return array();
	}

	private function is_noindex_post( int $post_id, array $settings ): bool {
		$robots = (string) get_post_meta( $post_id, 'ogs_seo_robots', true );
		if ( '' === trim( $robots ) ) {
			$post_type = (string) get_post_type( $post_id );
			if ( '' !== $post_type && isset( $settings['post_type_robots_defaults'][ $post_type ] ) ) {
				$robots = (string) $settings['post_type_robots_defaults'][ $post_type ];
			} else {
				$robots = (string) ( $settings['default_index'] ?? 'index,follow' );
			}
		}
		return str_contains( strtolower( $robots ), 'noindex' );
	}

	private function detect_content_type( int $post_id, array $settings ): string {
		$auto = EligibilityEngine::detect_auto_type_for_post( $post_id, $settings );
		if ( '' !== $auto ) {
			return $auto;
		}
		if ( ! empty( $settings['schema_article_enabled'] ) ) {
			$post_type = (string) get_post_type( $post_id );
			if ( 'post' === $post_type ) {
				return 'BlogPosting';
			}
			return 'Article';
		}
		return '';
	}

	private function build_node_for_type( string $type, array $context, array $settings ): array {
		$allowed = array_keys( Eligibility::supported_types() );
		if ( '' === trim( $type ) ) {
			return array();
		}
		if ( ! in_array( $type, $allowed, true ) ) {
			$this->warnings[] = sprintf( __( 'Unsupported schema type override ignored: %s', 'open-growth-seo' ), $type );
			return array();
		}
		if ( ! $this->is_schema_type_enabled( $type, $settings ) ) {
			$this->warnings[] = sprintf( __( 'Schema type %s is disabled in settings and was not emitted.', 'open-growth-seo' ), $type );
			return array();
		}
		if ( ! empty( $context['post_id'] ) ) {
			$eligibility = Eligibility::evaluate_for_post( $type, (int) $context['post_id'], $settings );
			if ( empty( $eligibility['eligible'] ) ) {
				$this->warnings[] = sprintf(
					/* translators: 1: schema type, 2: reason */
					__( 'Schema type %1$s was skipped for this URL. %2$s', 'open-growth-seo' ),
					$type,
					(string) $eligibility['reason']
				);
				return array();
			}
		}
		switch ( $type ) {
			case 'Service':
				return $this->service_node( $context, $settings );
			case 'OfferCatalog':
				return $this->offer_catalog_node( $context, $settings );
			case 'Person':
				return $this->person_node( $context, $settings );
			case 'Course':
				return $this->course_node( $context, $settings );
			case 'EducationalOccupationalProgram':
				return $this->program_node( $context, $settings );
			case 'Product':
				return $this->product_node( $context, $settings );
			case 'FAQPage':
				return $this->faq_node( $context );
			case 'ProfilePage':
				return $this->profile_page_node( $context, $settings );
			case 'AboutPage':
				return $this->webpage_subtype_node( $context, 'AboutPage' );
			case 'ContactPage':
				return $this->contact_page_node( $context, $settings );
			case 'CollectionPage':
				return $this->collection_page_node( $context );
			case 'DiscussionForumPosting':
				return $this->discussion_node( $context );
			case 'QAPage':
				return $this->qa_node( $context );
			case 'VideoObject':
				return $this->video_node( $context );
			case 'Event':
				return $this->event_node( $context );
			case 'EventSeries':
				return $this->event_series_node( $context );
			case 'JobPosting':
				return $this->job_node( $context );
			case 'Recipe':
				return $this->recipe_node( $context );
			case 'SoftwareApplication':
				return $this->software_node( $context );
			case 'WebAPI':
				return $this->webapi_node( $context, $settings );
			case 'Project':
				return $this->project_node( $context, $settings );
			case 'Review':
				return $this->review_node( $context );
			case 'Guide':
				return $this->guide_node( $context );
			case 'Dataset':
				return $this->dataset_node( $context );
			case 'DefinedTerm':
				return $this->defined_term_node( $context );
			case 'DefinedTermSet':
				return $this->defined_term_set_node( $context );
			case 'ScholarlyArticle':
				return $this->scholarly_article_node( $context, $settings );
			case 'TechArticle':
			case 'BlogPosting':
			case 'NewsArticle':
			case 'Article':
			default:
				return $this->article_node( $context, $type );
		}
	}

	private function is_schema_type_enabled( string $type, array $settings ): bool {
		$key = self::setting_key_for_type( $type );
		if ( '' === $type && null === $key ) {
			return false;
		}
		if ( null === $key ) {
			return true;
		}
		return ! empty( $settings[ $key ] );
	}

	public static function setting_key_for_type( string $type ): ?string {
		$map = array(
			'Article'                => 'schema_article_enabled',
			'BlogPosting'            => 'schema_article_enabled',
			'TechArticle'            => 'schema_tech_article_enabled',
			'NewsArticle'            => 'schema_article_enabled',
			'Service'                => 'schema_service_enabled',
			'OfferCatalog'           => 'schema_offer_catalog_enabled',
			'Person'                 => 'schema_person_enabled',
			'Course'                 => 'schema_course_enabled',
			'EducationalOccupationalProgram' => 'schema_program_enabled',
			'Product'                => null,
			'FAQPage'                => 'schema_faq_enabled',
			'ProfilePage'            => 'schema_profile_enabled',
			'AboutPage'              => 'schema_about_page_enabled',
			'ContactPage'            => 'schema_contact_page_enabled',
			'CollectionPage'         => 'schema_collection_page_enabled',
			'DiscussionForumPosting' => 'schema_discussion_enabled',
			'QAPage'                 => 'schema_qapage_enabled',
			'VideoObject'            => 'schema_video_enabled',
			'Event'                  => 'schema_event_enabled',
			'EventSeries'            => 'schema_event_series_enabled',
			'JobPosting'             => 'schema_jobposting_enabled',
			'Recipe'                 => 'schema_recipe_enabled',
			'SoftwareApplication'    => 'schema_software_enabled',
			'WebAPI'                 => 'schema_webapi_enabled',
			'Project'                => 'schema_project_enabled',
			'Review'                 => 'schema_review_enabled',
			'Guide'                  => 'schema_guide_enabled',
			'Dataset'                => 'schema_dataset_enabled',
			'DefinedTerm'            => 'schema_defined_term_enabled',
			'DefinedTermSet'         => 'schema_defined_term_set_enabled',
			'ScholarlyArticle'       => 'schema_scholarly_article_enabled',
		);
		return array_key_exists( $type, $map ) ? $map[ $type ] : '';
	}

	private function article_node( array $context, string $type ): array {
		$post_id = (int) $context['post_id'];
		if ( $post_id <= 0 ) {
			return array();
		}
		$author = $this->author_person_node( $post_id );
		$node = array(
			'@type'         => $type,
			'@id'           => $context['url'] . '#primary',
			'mainEntityOfPage' => array( '@id' => $context['url'] . '#webpage' ),
			'headline'      => get_the_title( $post_id ),
			'description'   => $this->post_description( $post_id ),
			'datePublished' => get_post_time( DATE_W3C, true, $post_id ),
			'dateModified'  => get_post_modified_time( DATE_W3C, true, $post_id ),
			'author'        => $author,
		);
		if ( $this->is_schema_type_enabled( 'Organization', (array) ( $context['settings'] ?? array() ) ) ) {
			$node['publisher'] = array( '@id' => home_url( '/#organization' ) );
		}
		$image = get_the_post_thumbnail_url( $post_id, 'full' );
		if ( $image ) {
			$node['image'] = array(
				'@type' => 'ImageObject',
				'url' => esc_url_raw( $image ),
			);
		}
		return apply_filters( 'ogs_seo_schema_node', $node, $type, $context, $context['settings'] );
	}

	private function service_node( array $context, array $settings ): array {
		$post_id = (int) $context['post_id'];
		if ( $post_id <= 0 ) {
			return array();
		}

		$service_type = sanitize_text_field( (string) get_post_meta( $post_id, 'ogs_seo_schema_service_type', true ) );
		$service_area = sanitize_text_field( (string) get_post_meta( $post_id, 'ogs_seo_schema_service_area', true ) );
		$offer = $this->service_offer_node( $context );
		$catalog = $this->service_offer_catalog_fragment( $context );
		$channel = $this->service_channel_node( $context );

		$node = array(
			'@type'       => 'Service',
			'@id'         => $context['url'] . '#service',
			'name'        => get_the_title( $post_id ),
			'description' => $this->post_description( $post_id ),
			'url'         => $context['url'],
			'provider'    => $this->provider_node( $settings ),
			'serviceType' => '' !== $service_type ? $service_type : get_the_title( $post_id ),
			'areaServed'  => '' !== $service_area ? $service_area : '',
		);
		if ( ! empty( $offer ) ) {
			$node['offers'] = $offer;
		}
		if ( ! empty( $catalog ) ) {
			$node['hasOfferCatalog'] = $catalog;
		}
		if ( ! empty( $channel ) ) {
			$node['availableChannel'] = $channel;
		}
		return apply_filters( 'ogs_seo_schema_node', $node, 'Service', $context, $settings );
	}

	private function offer_catalog_node( array $context, array $settings ): array {
		$catalog = $this->service_offer_catalog_fragment( $context );
		if ( empty( $catalog ) ) {
			return array();
		}
		$catalog['provider'] = $this->provider_node( $settings );
		return apply_filters( 'ogs_seo_schema_node', $catalog, 'OfferCatalog', $context, $settings );
	}

	private function webpage_subtype_node( array $context, string $type ): array {
		$post_id = (int) $context['post_id'];
		return array(
			'@type'            => $type,
			'@id'              => $context['url'] . '#' . strtolower( preg_replace( '/[^a-z0-9]+/i', '-', $type ) ),
			'mainEntityOfPage' => array( '@id' => $context['url'] . '#webpage' ),
			'name'             => $post_id > 0 ? get_the_title( $post_id ) : wp_get_document_title(),
			'description'      => $post_id > 0 ? $this->post_description( $post_id ) : wp_get_document_title(),
			'url'              => $context['url'],
		);
	}

	private function contact_page_node( array $context, array $settings ): array {
		$node = $this->webpage_subtype_node( $context, 'ContactPage' );
		$contact_point = $this->contact_point_node( $settings, $context['url'] );
		if ( ! empty( $contact_point ) ) {
			$node['mainEntity'] = $contact_point;
		}
		return apply_filters( 'ogs_seo_schema_node', $node, 'ContactPage', $context, $settings );
	}

	private function collection_page_node( array $context ): array {
		$node = $this->webpage_subtype_node( $context, 'CollectionPage' );
		$parts = $this->collection_part_nodes( (int) $context['post_id'] );
		if ( ! empty( $parts ) ) {
			$node['hasPart'] = $parts;
		}
		return $node;
	}

	private function person_node( array $context, array $settings ): array {
		$post_id = (int) $context['post_id'];
		if ( $post_id <= 0 ) {
			return array();
		}

		$job_title   = sanitize_text_field( (string) get_post_meta( $post_id, 'ogs_seo_schema_person_job_title', true ) );
		$affiliation = sanitize_text_field( (string) get_post_meta( $post_id, 'ogs_seo_schema_person_affiliation', true ) );
		$same_as     = $this->split_lines_urls( (string) get_post_meta( $post_id, 'ogs_seo_schema_same_as', true ) );
		$image       = get_the_post_thumbnail_url( $post_id, 'full' );

		$node = array(
			'@type'            => 'Person',
			'@id'              => $context['url'] . '#person',
			'mainEntityOfPage' => array( '@id' => $context['url'] . '#webpage' ),
			'name'             => get_the_title( $post_id ),
			'description'      => $this->post_description( $post_id ),
			'url'              => $context['url'],
			'jobTitle'         => $job_title,
			'sameAs'           => $same_as,
		);

		if ( $this->is_schema_type_enabled( 'Organization', $settings ) ) {
			$node['worksFor'] = array( '@id' => home_url( '/#organization' ) );
		} elseif ( '' !== $affiliation ) {
			$node['affiliation'] = array(
				'@type' => 'Organization',
				'name'  => $affiliation,
			);
		}

		if ( '' !== $affiliation && ! isset( $node['affiliation'] ) ) {
			$node['affiliation'] = array(
				'@type' => 'Organization',
				'name'  => $affiliation,
			);
		}

		if ( is_string( $image ) && '' !== trim( $image ) ) {
			$node['image'] = array(
				'@type' => 'ImageObject',
				'url'   => esc_url_raw( $image ),
			);
		}

		return apply_filters( 'ogs_seo_schema_node', $node, 'Person', $context, $settings );
	}

	private function course_node( array $context, array $settings ): array {
		$post_id = (int) $context['post_id'];
		if ( $post_id <= 0 ) {
			return array();
		}

		$course_code = sanitize_text_field( (string) get_post_meta( $post_id, 'ogs_seo_schema_course_code', true ) );
		$credential  = sanitize_text_field( (string) get_post_meta( $post_id, 'ogs_seo_schema_course_credential', true ) );
		$provider    = $this->provider_node( $settings );

		$node = array(
			'@type'       => 'Course',
			'@id'         => $context['url'] . '#course',
			'name'        => get_the_title( $post_id ),
			'description' => $this->post_description( $post_id ),
			'provider'    => $provider,
			'url'         => $context['url'],
			'courseCode'  => $course_code,
		);

		if ( '' !== $credential ) {
			$node['educationalCredentialAwarded'] = $credential;
		}

		return apply_filters( 'ogs_seo_schema_node', $node, 'Course', $context, $settings );
	}

	private function program_node( array $context, array $settings ): array {
		$post_id = (int) $context['post_id'];
		if ( $post_id <= 0 ) {
			return array();
		}

		$credential = sanitize_text_field( (string) get_post_meta( $post_id, 'ogs_seo_schema_program_credential', true ) );
		$duration   = sanitize_text_field( (string) get_post_meta( $post_id, 'ogs_seo_schema_duration', true ) );
		$mode       = sanitize_text_field( (string) get_post_meta( $post_id, 'ogs_seo_schema_program_mode', true ) );

		$node = array(
			'@type'       => 'EducationalOccupationalProgram',
			'@id'         => $context['url'] . '#program',
			'name'        => get_the_title( $post_id ),
			'description' => $this->post_description( $post_id ),
			'provider'    => $this->provider_node( $settings ),
			'url'         => $context['url'],
		);

		if ( '' !== $credential ) {
			$node['educationalCredentialAwarded'] = $credential;
		}
		if ( '' !== $duration ) {
			$node['timeToComplete'] = $duration;
		}
		if ( '' !== $mode ) {
			$node['educationalProgramMode'] = $mode;
		}

		return apply_filters( 'ogs_seo_schema_node', $node, 'EducationalOccupationalProgram', $context, $settings );
	}

	private function scholarly_article_node( array $context, array $settings ): array {
		$post_id = (int) $context['post_id'];
		if ( $post_id <= 0 ) {
			return array();
		}

		$identifier  = sanitize_text_field( (string) get_post_meta( $post_id, 'ogs_seo_schema_scholarly_identifier', true ) );
		$publication = sanitize_text_field( (string) get_post_meta( $post_id, 'ogs_seo_schema_scholarly_publication', true ) );
		$image       = get_the_post_thumbnail_url( $post_id, 'full' );

		$node = array(
			'@type'            => 'ScholarlyArticle',
			'@id'              => $context['url'] . '#scholarly-article',
			'mainEntityOfPage' => array( '@id' => $context['url'] . '#webpage' ),
			'headline'         => get_the_title( $post_id ),
			'description'      => $this->post_description( $post_id ),
			'datePublished'    => get_post_time( DATE_W3C, true, $post_id ),
			'dateModified'     => get_post_modified_time( DATE_W3C, true, $post_id ),
			'author'           => $this->author_person_node( $post_id ),
			'url'              => $context['url'],
		);

		if ( $this->is_schema_type_enabled( 'Organization', $settings ) ) {
			$node['publisher'] = array( '@id' => home_url( '/#organization' ) );
		}
		if ( '' !== $identifier ) {
			$node['identifier'] = $identifier;
		}
		if ( '' !== $publication ) {
			$node['isPartOf'] = array(
				'@type' => 'Periodical',
				'name'  => $publication,
			);
		}
		if ( is_string( $image ) && '' !== trim( $image ) ) {
			$node['image'] = array(
				'@type' => 'ImageObject',
				'url'   => esc_url_raw( $image ),
			);
		}

		return apply_filters( 'ogs_seo_schema_node', $node, 'ScholarlyArticle', $context, $settings );
	}

	private function product_node( array $context, array $settings ): array {
		$post_id = (int) $context['post_id'];
		if ( $post_id <= 0 || ! class_exists( 'WooCommerce' ) || 'product' !== get_post_type( $post_id ) ) {
			return array();
		}
		if ( ! $this->should_emit_ogs_product_schema( $settings ) ) {
			$this->warnings[] = __( 'WooCommerce native Product schema is active; Open Growth Product schema was skipped to avoid duplicates.', 'open-growth-seo' );
			return array();
		}
		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			return array();
		}
		$offers = array();
		if ( $product->is_type( 'variable' ) && method_exists( $product, 'get_children' ) ) {
			$offers = $this->build_woocommerce_variation_offers( $product, (string) $context['url'] );
		} else {
			$single_offer = $this->build_woocommerce_offer_from_product( $product, (string) $context['url'] );
			if ( ! empty( $single_offer ) ) {
				$offers[] = $single_offer;
			}
		}
		$node = array(
			'@type'       => 'Product',
			'@id'         => $context['url'] . '#product',
			'name'        => get_the_title( $post_id ),
			'description' => $this->post_description( $post_id ),
			'sku'         => method_exists( $product, 'get_sku' ) ? (string) $product->get_sku() : '',
			'offers'      => count( $offers ) > 1 ? $offers : ( $offers[0] ?? array() ),
		);
		$image = wp_get_attachment_image_url( $product->get_image_id(), 'full' );
		if ( $image ) {
			$node['image'] = esc_url_raw( $image );
		}
		$rating_count = method_exists( $product, 'get_rating_count' ) ? (int) $product->get_rating_count() : 0;
		$review_count = method_exists( $product, 'get_review_count' ) ? (int) $product->get_review_count() : 0;
		$avg_rating   = method_exists( $product, 'get_average_rating' ) ? (string) $product->get_average_rating() : '';
		if ( $rating_count > 0 && '' !== $avg_rating ) {
			$node['aggregateRating'] = array(
				'@type'       => 'AggregateRating',
				'ratingValue' => $avg_rating,
				'reviewCount' => max( $review_count, $rating_count ),
			);
		}
		$reviews = $this->build_woocommerce_reviews( $post_id );
		if ( ! empty( $reviews ) ) {
			$node['review'] = $reviews;
		}
		$return_page_id = absint( (string) get_option( 'woocommerce_return_policy_page_id', 0 ) );
		if ( $return_page_id <= 0 ) {
			$return_page_id = absint( (string) get_option( 'woocommerce_shipping_and_returns_page_id', 0 ) );
		}
		$shipping_page_id = absint( (string) get_option( 'woocommerce_shipping_policy_page_id', 0 ) );
		if ( $shipping_page_id <= 0 ) {
			$shipping_page_id = absint( (string) get_option( 'woocommerce_shipping_and_returns_page_id', 0 ) );
		}
		$return_url = '';
		if ( $return_page_id > 0 ) {
			$resolved = get_permalink( $return_page_id );
			if ( is_string( $resolved ) ) {
				$return_url = esc_url_raw( $resolved );
			}
		}
		$shipping_url = '';
		if ( $shipping_page_id > 0 ) {
			$resolved = get_permalink( $shipping_page_id );
			if ( is_string( $resolved ) ) {
				$shipping_url = esc_url_raw( $resolved );
			}
		}
		$shipping_country = sanitize_text_field( (string) ( $settings['schema_local_country'] ?? '' ) );
		if ( ! empty( $node['offers'] ) ) {
			$node['offers'] = $this->attach_offer_policy_details( $node['offers'], $return_url, $shipping_url, $shipping_country );
		}
		if ( empty( $offers ) ) {
			$this->warnings[] = __( 'Product schema emitted without Offer because no valid WooCommerce price data was available.', 'open-growth-seo' );
			unset( $node['offers'] );
		}
		return apply_filters( 'ogs_seo_schema_node', $node, 'Product', $context, $settings );
	}

	private function should_emit_ogs_product_schema( array $settings ): bool {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return false;
		}
		$mode = isset( $settings['woo_schema_mode'] ) ? sanitize_key( (string) $settings['woo_schema_mode'] ) : 'native';
		if ( 'ogs' === $mode ) {
			return true;
		}
		return false;
	}

	private function build_woocommerce_offer_from_product( $product, string $url ): array {
		if ( ! $product || ! is_object( $product ) || ! method_exists( $product, 'get_price' ) ) {
			return array();
		}
		$price = (string) $product->get_price();
		$price = $this->normalize_product_price( $price );
		if ( '' === trim( $price ) ) {
			return array();
		}
		$stock_status = method_exists( $product, 'get_stock_status' ) ? (string) $product->get_stock_status() : '';
		$is_in_stock  = method_exists( $product, 'is_in_stock' ) ? (bool) $product->is_in_stock() : false;
		$offer = array(
			'@type'         => 'Offer',
			'priceCurrency' => function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : 'USD',
			'price'         => $price,
			'availability'  => $this->map_woocommerce_availability( $stock_status, $is_in_stock ),
			'url'           => esc_url_raw( $url ),
		);
		if ( method_exists( $product, 'get_sku' ) ) {
			$sku = (string) $product->get_sku();
			if ( '' !== trim( $sku ) ) {
				$offer['sku'] = $sku;
			}
		}
		return $offer;
	}

	private function normalize_product_price( string $price ): string {
		$price = trim( $price );
		if ( '' === $price ) {
			return '';
		}
		if ( function_exists( 'wc_format_decimal' ) ) {
			$decimals  = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
			$formatted = wc_format_decimal( $price, $decimals );
			return is_string( $formatted ) ? trim( $formatted ) : '';
		}
		$price = preg_replace( '/[^0-9\.\,]/', '', $price );
		if ( ! is_string( $price ) ) {
			return '';
		}
		$price = str_replace( ',', '.', $price );
		return preg_match( '/^\d+(\.\d+)?$/', $price ) ? $price : '';
	}

	private function build_woocommerce_variation_offers( $product, string $url ): array {
		$offers = array();
		if ( ! $product || ! is_object( $product ) || ! method_exists( $product, 'get_children' ) ) {
			return $offers;
		}
		$children = (array) $product->get_children();
		foreach ( array_slice( $children, 0, 25 ) as $variation_id ) {
			$variation = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $variation_id ) : null;
			if ( ! $variation || ! is_object( $variation ) ) {
				continue;
			}
			$variation_offer = $this->build_woocommerce_offer_from_product( $variation, $url );
			if ( empty( $variation_offer ) ) {
				continue;
			}
			$var_url = get_permalink( (int) $variation_id );
			if ( is_string( $var_url ) && '' !== $var_url ) {
				$variation_offer['url'] = esc_url_raw( $var_url );
			}
			$offers[] = $variation_offer;
		}
		return $offers;
	}

	private function attach_offer_policy_details( $offers, string $return_url, string $shipping_url, string $shipping_country ) {
		if ( ! is_array( $offers ) ) {
			return $offers;
		}
		if ( isset( $offers[0] ) && is_array( $offers[0] ) ) {
			foreach ( $offers as $index => $offer_row ) {
				if ( ! is_array( $offer_row ) ) {
					continue;
				}
				$offers[ $index ] = $this->attach_single_offer_policy_details( $offer_row, $return_url, $shipping_url, $shipping_country );
			}
			return $offers;
		}
		return $this->attach_single_offer_policy_details( $offers, $return_url, $shipping_url, $shipping_country );
	}

	private function attach_single_offer_policy_details( array $offer, string $return_url, string $shipping_url, string $shipping_country ): array {
		if ( '' !== $return_url ) {
			$offer['hasMerchantReturnPolicy'] = array(
				'@type'                => 'MerchantReturnPolicy',
				'url'                  => esc_url_raw( $return_url ),
				'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
			);
		}
		if ( '' !== $shipping_url ) {
			$offer['shippingDetails'] = array(
				'@type'               => 'OfferShippingDetails',
				'shippingDestination' => array(
					'@type'          => 'DefinedRegion',
					'addressCountry' => '' !== $shipping_country ? $shipping_country : 'US',
				),
				'url'                 => esc_url_raw( $shipping_url ),
			);
		}
		return $offer;
	}

	private function map_woocommerce_availability( string $stock_status, bool $is_in_stock ): string {
		$stock_status = strtolower( trim( $stock_status ) );
		if ( in_array( $stock_status, array( 'onbackorder', 'backorder' ), true ) ) {
			return 'https://schema.org/BackOrder';
		}
		if ( in_array( $stock_status, array( 'outofstock', 'out_of_stock' ), true ) ) {
			return 'https://schema.org/OutOfStock';
		}
		return $is_in_stock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock';
	}

	private function build_woocommerce_reviews( int $post_id ): array {
		$reviews = array();
		if ( $post_id <= 0 ) {
			return $reviews;
		}
		$comments = get_comments(
			array(
				'post_id' => $post_id,
				'status'  => 'approve',
				'type'    => 'review',
				'number'  => 3,
			)
		);
		foreach ( $comments as $comment ) {
			$rating = (int) get_comment_meta( (int) $comment->comment_ID, 'rating', true );
			if ( $rating <= 0 ) {
				continue;
			}
			$reviews[] = array(
				'@type'         => 'Review',
				'author'        => array(
					'@type' => 'Person',
					'name' => (string) $comment->comment_author,
				),
				'reviewBody'    => wp_trim_words( wp_strip_all_tags( (string) $comment->comment_content ), 60 ),
				'datePublished' => get_comment_date( DATE_W3C, $comment ),
				'reviewRating'  => array(
					'@type'       => 'Rating',
					'ratingValue' => $rating,
					'bestRating'  => 5,
					'worstRating' => 1,
				),
			);
		}
		return $reviews;
	}

	private function faq_node( array $context ): array {
		$signals = isset( $context['content_signals'] ) && is_array( $context['content_signals'] ) ? $context['content_signals'] : array();
		$pairs   = isset( $signals['faq_pairs'] ) && is_array( $signals['faq_pairs'] ) ? $signals['faq_pairs'] : array();
		if ( count( $pairs ) < 2 ) {
			$this->warnings[] = __( 'FAQ schema not emitted because fewer than two visible Q&A pairs were detected.', 'open-growth-seo' );
			return array();
		}
		$main = array();
		foreach ( $pairs as $pair ) {
			$main[] = array(
				'@type' => 'Question',
				'name'  => $pair['q'],
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => $pair['a'],
				),
			);
		}
		return array(
			'@type'      => 'FAQPage',
			'@id'        => $context['url'] . '#faq',
			'mainEntity' => $main,
		);
	}

	private function profile_page_node( array $context, array $settings ): array {
		$user = get_queried_object();
		if ( ! is_object( $user ) || empty( $user->ID ) ) {
			$post_id = (int) $context['post_id'];
			if ( $post_id <= 0 ) {
				return array();
			}
			$user_id = (int) get_post_field( 'post_author', $post_id );
			$user    = get_userdata( $user_id );
			if ( ! $user ) {
				return array();
			}
		}
		return array(
			'@type' => 'ProfilePage',
			'@id'   => $context['url'] . '#profile',
			'url'   => $context['url'],
			'mainEntity' => array(
				'@type' => 'Person',
				'name'  => (string) $user->display_name,
				'url'   => get_author_posts_url( (int) $user->ID ),
			),
		);
	}

	private function discussion_node( array $context ): array {
		$post_id = (int) $context['post_id'];
		return array(
			'@type'       => 'DiscussionForumPosting',
			'@id'         => $context['url'] . '#discussion',
			'headline'    => get_the_title( $post_id ),
			'articleBody' => wp_trim_words( wp_strip_all_tags( (string) get_post_field( 'post_content', $post_id ) ), 120 ),
			'datePublished' => get_post_time( DATE_W3C, true, $post_id ),
			'author'      => array(
				'@type' => 'Person',
				'name' => get_the_author_meta( 'display_name', (int) get_post_field( 'post_author', $post_id ) ),
			),
		);
	}

	private function qa_node( array $context ): array {
		$signals = isset( $context['content_signals'] ) && is_array( $context['content_signals'] ) ? $context['content_signals'] : array();
		$pairs   = isset( $signals['qa_pairs'] ) && is_array( $signals['qa_pairs'] ) ? $signals['qa_pairs'] : array();
		if ( empty( $pairs ) ) {
			return array();
		}
		$first = $pairs[0];
		return array(
			'@type' => 'QAPage',
			'@id'   => $context['url'] . '#qa',
			'mainEntity' => array(
				'@type' => 'Question',
				'name'  => $first['q'],
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => $first['a'],
				),
			),
		);
	}

	private function video_node( array $context ): array {
		$post_id = (int) $context['post_id'];
		$signals = isset( $context['content_signals'] ) && is_array( $context['content_signals'] ) ? $context['content_signals'] : array();
		$videos  = isset( $signals['video_urls'] ) && is_array( $signals['video_urls'] ) ? $signals['video_urls'] : array();
		$video   = (string) ( $videos[0] ?? '' );
		if ( '' === $video ) {
			return array();
		}
		$thumb = get_the_post_thumbnail_url( $post_id, 'full' );
		return array(
			'@type'        => 'VideoObject',
			'@id'          => $context['url'] . '#video',
			'name'         => get_the_title( $post_id ),
			'description'  => $this->post_description( $post_id ),
			'uploadDate'   => get_post_time( DATE_W3C, true, $post_id ),
			'thumbnailUrl' => $thumb ? esc_url_raw( $thumb ) : '',
			'contentUrl'   => $video,
		);
	}

	private function event_node( array $context ): array {
		$post_id = (int) $context['post_id'];
		$start   = $this->event_meta( $post_id, array( '_event_start_date', 'event_start_date', 'start_date' ) );
		$end     = $this->event_meta( $post_id, array( '_event_end_date', 'event_end_date', 'end_date' ) );
		$location_name = sanitize_text_field( (string) get_post_meta( $post_id, 'ogs_seo_schema_event_location_name', true ) );
		$attendance_mode = sanitize_text_field( (string) get_post_meta( $post_id, 'ogs_seo_schema_event_attendance_mode', true ) );
		if ( '' === $start ) {
			$this->warnings[] = __( 'Event schema skipped because no start date was found in visible event metadata.', 'open-growth-seo' );
			return array();
		}
		$node = array(
			'@type'      => 'Event',
			'@id'        => $context['url'] . '#event',
			'name'       => get_the_title( $post_id ),
			'description' => $this->post_description( $post_id ),
			'startDate'  => $start,
			'endDate'    => $end,
			'eventStatus' => 'https://schema.org/EventScheduled',
			'eventAttendanceMode' => '' !== $attendance_mode ? 'https://schema.org/' . $attendance_mode : 'https://schema.org/OfflineEventAttendanceMode',
			'url'        => $context['url'],
		);
		if ( '' !== $location_name ) {
			$node['location'] = array(
				'@type' => 'Place',
				'name'  => $location_name,
			);
		}
		return $node;
	}

	private function event_series_node( array $context ): array {
		$event = $this->event_node( $context );
		if ( empty( $event ) ) {
			return array();
		}

		return array(
			'@type'      => 'EventSeries',
			'@id'        => $context['url'] . '#event-series',
			'name'       => (string) ( $event['name'] ?? get_the_title( (int) $context['post_id'] ) ),
			'description' => (string) ( $event['description'] ?? $this->post_description( (int) $context['post_id'] ) ),
			'startDate'  => (string) ( $event['startDate'] ?? '' ),
			'endDate'    => (string) ( $event['endDate'] ?? '' ),
			'url'        => $context['url'],
			'subEvent'   => $event,
		);
	}

	private function job_node( array $context ): array {
		$post_id = (int) $context['post_id'];
		$valid_through = $this->event_meta( $post_id, array( '_job_valid_through', 'job_valid_through', 'valid_through' ) );
		$employment    = sanitize_text_field( (string) get_post_meta( $post_id, '_job_employment_type', true ) );
		$settings      = isset( $context['settings'] ) && is_array( $context['settings'] ) ? $context['settings'] : array();
		$hiring_org    = $this->is_schema_type_enabled( 'Organization', $settings )
			? array( '@id' => home_url( '/#organization' ) )
			: array(
				'@type' => 'Organization',
				'name'  => (string) get_bloginfo( 'name' ),
				'url'   => home_url( '/' ),
			);
		return array(
			'@type'        => 'JobPosting',
			'@id'          => $context['url'] . '#job',
			'title'        => get_the_title( $post_id ),
			'description'  => wp_kses_post( (string) get_post_field( 'post_content', $post_id ) ),
			'datePosted'   => get_post_time( DATE_W3C, true, $post_id ),
			'validThrough' => $valid_through,
			'employmentType' => $employment,
			'hiringOrganization' => $hiring_org,
		);
	}

	private function recipe_node( array $context ): array {
		$post_id  = (int) $context['post_id'];
		$signals  = isset( $context['content_signals'] ) && is_array( $context['content_signals'] ) ? $context['content_signals'] : array();
		$ing      = isset( $signals['recipe_ingredients'] ) && is_array( $signals['recipe_ingredients'] ) ? $signals['recipe_ingredients'] : array();
		$steps    = isset( $signals['recipe_steps'] ) && is_array( $signals['recipe_steps'] ) ? $signals['recipe_steps'] : array();
		if ( empty( $ing ) || empty( $steps ) ) {
			$this->warnings[] = __( 'Recipe schema skipped because ingredients or instructions were not clearly detectable.', 'open-growth-seo' );
			return array();
		}
		return array(
			'@type'             => 'Recipe',
			'@id'               => $context['url'] . '#recipe',
			'name'              => get_the_title( $post_id ),
			'description'       => $this->post_description( $post_id ),
			'recipeIngredient'  => $ing,
			'recipeInstructions' => $steps,
		);
	}

	private function software_node( array $context ): array {
		$post_id = (int) $context['post_id'];
		return array(
			'@type'               => 'SoftwareApplication',
			'@id'                 => $context['url'] . '#software',
			'name'                => get_the_title( $post_id ),
			'applicationCategory' => 'BusinessApplication',
			'operatingSystem'     => 'Web',
			'description'         => $this->post_description( $post_id ),
			'url'                 => $context['url'],
		);
	}

	private function webapi_node( array $context, array $settings ): array {
		$post_id = (int) $context['post_id'];
		$docs_url = sanitize_text_field( (string) get_post_meta( $post_id, 'ogs_seo_schema_api_docs_url', true ) );
		$documentation = '' !== $docs_url ? esc_url_raw( $docs_url ) : $context['url'];
		return array(
			'@type'         => 'WebAPI',
			'@id'           => $context['url'] . '#webapi',
			'name'          => get_the_title( $post_id ),
			'description'   => $this->post_description( $post_id ),
			'url'           => $context['url'],
			'documentation' => $documentation,
			'provider'      => $this->provider_node( $settings ),
		);
	}

	private function project_node( array $context, array $settings ): array {
		$post_id = (int) $context['post_id'];
		$status = sanitize_text_field( (string) get_post_meta( $post_id, 'ogs_seo_schema_project_status', true ) );
		$node = array(
			'@type'       => 'Project',
			'@id'         => $context['url'] . '#project',
			'name'        => get_the_title( $post_id ),
			'description' => $this->post_description( $post_id ),
			'url'         => $context['url'],
			'provider'    => $this->provider_node( $settings ),
		);
		if ( '' !== $status ) {
			$node['keywords'] = $status;
		}
		return $node;
	}

	private function review_node( array $context ): array {
		$post_id = (int) $context['post_id'];
		$rating  = max( 1, min( 5, absint( get_post_meta( $post_id, 'ogs_seo_schema_review_rating', true ) ) ) );
		$item_name = sanitize_text_field( (string) get_post_meta( $post_id, 'ogs_seo_schema_reviewed_item_name', true ) );
		$item_type = sanitize_text_field( (string) get_post_meta( $post_id, 'ogs_seo_schema_reviewed_item_type', true ) );
		$item_type = '' !== $item_type ? $item_type : 'Thing';
		if ( $rating <= 0 ) {
			return array();
		}
		return array(
			'@type'         => 'Review',
			'@id'           => $context['url'] . '#review',
			'author'        => $this->author_person_node( $post_id ),
			'datePublished' => get_post_time( DATE_W3C, true, $post_id ),
			'reviewBody'    => $this->post_description( $post_id ),
			'itemReviewed'  => array(
				'@type' => $item_type,
				'name'  => '' !== $item_name ? $item_name : get_the_title( $post_id ),
			),
			'reviewRating'  => array(
				'@type'       => 'Rating',
				'ratingValue' => $rating,
				'bestRating'  => 5,
				'worstRating' => 1,
			),
		);
	}

	private function guide_node( array $context ): array {
		$post_id = (int) $context['post_id'];
		$steps = $this->extract_guide_steps( (string) ( $context['content_raw'] ?? '' ) );
		if ( count( $steps ) < 2 ) {
			return array();
		}
		return array(
			'@type'       => 'Guide',
			'@id'         => $context['url'] . '#guide',
			'name'        => get_the_title( $post_id ),
			'description' => $this->post_description( $post_id ),
			'url'         => $context['url'],
			'step'        => $steps,
		);
	}

	private function dataset_node( array $context ): array {
		$post_id = (int) $context['post_id'];
		$identifier = sanitize_text_field( (string) get_post_meta( $post_id, 'ogs_seo_schema_defined_term_code', true ) );
		return array(
			'@type'       => 'Dataset',
			'@id'         => $context['url'] . '#dataset',
			'name'        => get_the_title( $post_id ),
			'description' => $this->post_description( $post_id ),
			'identifier'  => $identifier,
			'url'         => $context['url'],
		);
	}

	private function defined_term_node( array $context ): array {
		$post_id = (int) $context['post_id'];
		$term_code = sanitize_text_field( (string) get_post_meta( $post_id, 'ogs_seo_schema_defined_term_code', true ) );
		$term_set  = sanitize_text_field( (string) get_post_meta( $post_id, 'ogs_seo_schema_defined_term_set', true ) );
		$node = array(
			'@type'       => 'DefinedTerm',
			'@id'         => $context['url'] . '#defined-term',
			'name'        => get_the_title( $post_id ),
			'description' => $this->post_description( $post_id ),
			'url'         => $context['url'],
			'termCode'    => $term_code,
		);
		if ( '' !== $term_set ) {
			$node['inDefinedTermSet'] = array(
				'@type' => 'DefinedTermSet',
				'name'  => $term_set,
			);
		}
		return $node;
	}

	private function defined_term_set_node( array $context ): array {
		$post_id = (int) $context['post_id'];
		$terms = $this->extract_defined_terms( (string) ( $context['content_raw'] ?? '' ) );
		$node = array(
			'@type'       => 'DefinedTermSet',
			'@id'         => $context['url'] . '#defined-term-set',
			'name'        => get_the_title( $post_id ),
			'description' => $this->post_description( $post_id ),
			'url'         => $context['url'],
		);
		if ( ! empty( $terms ) ) {
			$node['hasDefinedTerm'] = $terms;
		}
		return $node;
	}

	private function sanitize_graph( array $graph ): array {
		$clean = array();
		foreach ( $graph as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$node = $this->clean_node( $node );
			if ( ! empty( $node['@type'] ) ) {
				$clean[] = $node;
			}
		}
		return $clean;
	}

	private function dedupe_graph( array $graph ): array {
		$seen  = array();
		$clean = array();
		foreach ( $graph as $node ) {
			$key = '';
			if ( isset( $node['@id'] ) ) {
				$key = (string) $node['@id'];
			} elseif ( isset( $node['@type'] ) ) {
				$key = (string) $node['@type'] . ':' . md5( wp_json_encode( $node ) );
			}
			if ( '' !== $key && isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$clean[] = $node;
		}
		return $clean;
	}

	private function validate_node( array $node, array $context ) {
		$report   = ValidationEngine::validate_node( $node, $context );
		$status   = isset( $report['status'] ) ? sanitize_key( (string) $report['status'] ) : 'blocked';
		$messages = isset( $report['messages'] ) && is_array( $report['messages'] ) ? $report['messages'] : array();
		$is_valid = in_array( $status, array( 'valid', 'warning' ), true );
		if ( ! $is_valid ) {
			foreach ( $messages as $message ) {
				$message = sanitize_text_field( (string) $message );
				if ( '' !== $message ) {
					$this->errors[] = $message;
				}
			}
		}
		return $is_valid;
	}

	private function clean_node( array $node ): array {
		foreach ( $node as $key => $value ) {
			if ( is_array( $value ) ) {
				$node[ $key ] = $this->clean_node( $value );
			}
			if ( isset( $node[ $key ] ) && is_array( $node[ $key ] ) && empty( $node[ $key ] ) ) {
				unset( $node[ $key ] );
			}
			if ( isset( $node[ $key ] ) && is_string( $node[ $key ] ) ) {
				$node[ $key ] = trim( $node[ $key ] );
				if ( '' === $node[ $key ] ) {
					unset( $node[ $key ] );
				}
			}
			if ( array_key_exists( $key, $node ) && null === $node[ $key ] ) {
				unset( $node[ $key ] );
			}
		}
		return $node;
	}

	private function extract_question_answer_pairs( string $content ): array {
		$signals = ContentSignals::analyze( $content );
		return isset( $signals['faq_pairs'] ) && is_array( $signals['faq_pairs'] ) ? $signals['faq_pairs'] : array();
	}

	private function extract_video_url( string $content ): string {
		$signals = ContentSignals::analyze( $content );
		$videos  = isset( $signals['video_urls'] ) && is_array( $signals['video_urls'] ) ? $signals['video_urls'] : array();
		return (string) ( $videos[0] ?? '' );
	}

	private function extract_recipe_ingredients( string $content ): array {
		$signals = ContentSignals::analyze( $content );
		return isset( $signals['recipe_ingredients'] ) && is_array( $signals['recipe_ingredients'] ) ? $signals['recipe_ingredients'] : array();
	}

	private function extract_recipe_steps( string $content ): array {
		$signals = ContentSignals::analyze( $content );
		return isset( $signals['recipe_steps'] ) && is_array( $signals['recipe_steps'] ) ? $signals['recipe_steps'] : array();
	}

	private function event_meta( int $post_id, array $keys ): string {
		foreach ( $keys as $key ) {
			$raw = (string) get_post_meta( $post_id, $key, true );
			if ( '' === trim( $raw ) ) {
				continue;
			}
			$ts = strtotime( $raw );
			if ( false !== $ts ) {
				return gmdate( DATE_W3C, $ts );
			}
		}
		return '';
	}

	private function has_video_signal( int $post_id ): bool {
		$content = (string) get_post_field( 'post_content', $post_id );
		return false !== stripos( $content, 'youtube.com' ) || false !== stripos( $content, 'vimeo.com' ) || false !== stripos( $content, '<video' );
	}

	private function post_description( int $post_id ): string {
		$desc = (string) get_post_field( 'post_excerpt', $post_id );
		if ( '' === trim( $desc ) ) {
			$desc = wp_trim_words( wp_strip_all_tags( (string) get_post_field( 'post_content', $post_id ) ), 35 );
		}
		return trim( $desc );
	}

	private function author_person_node( int $post_id ): array {
		$author_id = (int) get_post_field( 'post_author', $post_id );
		$author    = array(
			'@type' => 'Person',
			'name'  => get_the_author_meta( 'display_name', $author_id ),
		);
		if ( function_exists( 'get_author_posts_url' ) ) {
			$author_url = get_author_posts_url( $author_id );
			if ( is_string( $author_url ) && '' !== trim( $author_url ) ) {
				$author['url'] = esc_url_raw( $author_url );
			}
		}
		if ( function_exists( 'get_avatar_url' ) ) {
			$avatar_url = get_avatar_url( $author_id, array( 'size' => 256 ) );
			if ( is_string( $avatar_url ) && '' !== trim( $avatar_url ) ) {
				$author['image'] = esc_url_raw( $avatar_url );
			}
		}
		return $author;
	}

	private function provider_node( array $settings ): array {
		if ( $this->is_schema_type_enabled( 'Organization', $settings ) ) {
			return array( '@id' => home_url( '/#organization' ) );
		}

		$name = trim( (string) ( $settings['schema_org_name'] ?? '' ) );
		if ( '' === $name ) {
			$name = (string) get_bloginfo( 'name' );
		}

		return array(
			'@type' => 'Organization',
			'name'  => $name,
			'url'   => home_url( '/' ),
		);
	}

	private function contact_point_node( array $settings, string $url ): array {
		$phone = sanitize_text_field( (string) ( $settings['schema_org_contact_phone'] ?? '' ) );
		$email = sanitize_text_field( (string) ( $settings['schema_org_contact_email'] ?? '' ) );
		$contact_url = esc_url_raw( (string) ( $settings['schema_org_contact_url'] ?? $url ) );
		if ( '' === $phone && '' === $email && '' === $contact_url ) {
			return array();
		}
		return array_filter(
			array(
				'@type'       => 'ContactPoint',
				'url'         => '' !== $contact_url ? $contact_url : $url,
				'telephone'   => $phone,
				'email'       => $email,
				'contactType' => 'customer support',
			)
		);
	}

	private function service_channel_node( array $context ): array {
		$post_id = (int) $context['post_id'];
		$contact_url = sanitize_text_field( (string) get_post_meta( $post_id, 'ogs_seo_schema_service_channel_url', true ) );
		if ( '' === $contact_url ) {
			$contact_url = $context['url'];
		}
		return array(
			'@type'        => 'ServiceChannel',
			'serviceUrl'   => esc_url_raw( $contact_url ),
			'availableLanguage' => $this->language_code(),
		);
	}

	private function service_offer_node( array $context ): array {
		$post_id = (int) $context['post_id'];
		$price = $this->normalize_product_price( (string) get_post_meta( $post_id, 'ogs_seo_schema_offer_price', true ) );
		$currency = sanitize_text_field( (string) get_post_meta( $post_id, 'ogs_seo_schema_offer_currency', true ) );
		if ( '' === $price || '' === $currency ) {
			return array();
		}
		return array(
			'@type'         => 'Offer',
			'price'         => $price,
			'priceCurrency' => strtoupper( $currency ),
			'url'           => $context['url'],
			'category'      => sanitize_text_field( (string) get_post_meta( (int) $context['post_id'], 'ogs_seo_schema_offer_category', true ) ),
		);
	}

	private function service_offer_catalog_fragment( array $context ): array {
		$post_id = (int) $context['post_id'];
		$offer = $this->service_offer_node( $context );
		$items = array();
		if ( ! empty( $offer ) ) {
			$items[] = $offer;
		}
		$parts = $this->collection_part_nodes( $post_id );
		foreach ( $parts as $part ) {
			$items[] = array(
				'@type' => 'Offer',
				'name'  => (string) ( $part['name'] ?? '' ),
				'url'   => (string) ( $part['url'] ?? '' ),
			);
		}
		if ( empty( $items ) ) {
			return array();
		}
		return array(
			'@type'           => 'OfferCatalog',
			'@id'             => $context['url'] . '#offer-catalog',
			'name'            => get_the_title( $post_id ),
			'url'             => $context['url'],
			'numberOfItems'   => count( $items ),
			'itemListElement' => $items,
		);
	}

	private function collection_part_nodes( int $post_id ): array {
		if ( $post_id <= 0 || ! function_exists( 'get_children' ) ) {
			return array();
		}
		$children = get_children(
			array(
				'post_parent'    => $post_id,
				'post_type'      => 'any',
				'post_status'    => 'publish',
				'posts_per_page' => 6,
			)
		);
		$parts = array();
		foreach ( array_slice( array_values( is_array( $children ) ? $children : array() ), 0, 6 ) as $child ) {
			if ( ! is_object( $child ) || empty( $child->ID ) ) {
				continue;
			}
			$url = get_permalink( (int) $child->ID );
			if ( ! is_string( $url ) || '' === trim( $url ) ) {
				continue;
			}
			$parts[] = array(
				'@type' => 'WebPage',
				'name'  => get_the_title( (int) $child->ID ),
				'url'   => esc_url_raw( $url ),
			);
		}
		return $parts;
	}

	private function extract_guide_steps( string $content ): array {
		$plain = preg_replace( '/\r\n|\r/', "\n", wp_strip_all_tags( $content ) );
		$lines = preg_split( '/\n+/', (string) $plain ) ?: array();
		$steps = array();
		foreach ( $lines as $line ) {
			$line = trim( preg_replace( '/^\s*(?:step\s*\d+[:\-]?|\d+\.)\s*/i', '', (string) $line ) );
			if ( mb_strlen( $line ) < 12 ) {
				continue;
			}
			$steps[] = array(
				'@type' => 'HowToStep',
				'name'  => sanitize_text_field( $line ),
				'text'  => sanitize_text_field( $line ),
			);
			if ( count( $steps ) >= 8 ) {
				break;
			}
		}
		return $steps;
	}

	private function extract_defined_terms( string $content ): array {
		$plain = preg_replace( '/\r\n|\r/', "\n", wp_strip_all_tags( $content ) );
		$lines = preg_split( '/\n+/', (string) $plain ) ?: array();
		$terms = array();
		foreach ( $lines as $line ) {
			$line = trim( (string) $line );
			if ( mb_strlen( $line ) < 3 || mb_strlen( $line ) > 80 ) {
				continue;
			}
			$terms[] = array(
				'@type' => 'DefinedTerm',
				'name'  => sanitize_text_field( $line ),
			);
			if ( count( $terms ) >= 8 ) {
				break;
			}
		}
		return $terms;
	}

	private function split_lines_urls( string $raw ): array {
		$lines = preg_split( '/\r\n|\r|\n/', $raw ) ?: array();
		$urls  = array();
		foreach ( $lines as $line ) {
			$url = esc_url_raw( trim( $line ) );
			if ( '' !== $url ) {
				$urls[] = $url;
			}
		}
		return array_values( array_unique( $urls ) );
	}

	private function normalize_url( string $url ): string {
		$url = esc_url_raw( trim( $url ) );
		if ( '' === $url ) {
			return '';
		}
		if ( str_starts_with( $url, '/' ) ) {
			$url = home_url( $url );
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		$normalized = strtolower( (string) $parts['scheme'] ) . '://' . strtolower( (string) $parts['host'] );
		if ( ! empty( $parts['port'] ) ) {
			$normalized .= ':' . absint( $parts['port'] );
		}
		$normalized .= isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		if ( ! empty( $parts['query'] ) ) {
			$normalized .= '?' . (string) $parts['query'];
		}
		return $normalized;
	}

	private function language_code(): string {
		$locale = get_locale();
		$locale = str_replace( '_', '-', (string) $locale );
		return strtolower( $locale );
	}

	private function is_paywalled( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}
		if ( '1' === (string) get_post_meta( $post_id, 'ogs_schema_paywalled', true ) ) {
			return true;
		}
		$content = strtolower( (string) get_post_field( 'post_content', $post_id ) );
		return false !== strpos( $content, 'paywall' ) || false !== strpos( $content, 'subscriber-only' ) || false !== strpos( $content, 'premium-content' );
	}

	private function supports_native_site_search(): bool {
		return true;
	}

	private function current_url(): string {
		if ( is_search() ) {
			return get_search_link( get_search_query() );
		}
		global $wp;
		$request = isset( $wp->request ) ? (string) $wp->request : '';
		return home_url( user_trailingslashit( $request ) );
	}

	private function should_skip_output(): bool {
		if ( ! Settings::get( 'safe_mode_seo_conflict', 1 ) ) {
			return false;
		}
		return Detector::has_active_seo_plugin();
	}
}
