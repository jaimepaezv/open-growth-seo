<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\Audit;

use OpenGrowthSolutions\OpenGrowthSEO\AEO\LinkSuggestions;
use OpenGrowthSolutions\OpenGrowthSEO\AEO\LinkGraph;
use OpenGrowthSolutions\OpenGrowthSEO\Support\Settings;

defined( 'ABSPATH' ) || exit;

class AuditManager {
	private const ISSUES_OPTION = 'ogs_seo_audit_issues';
	private const LAST_RUN_OPTION = 'ogs_seo_audit_last_run';
	private const STATE_OPTION = 'ogs_seo_audit_scan_state';
	private const CACHE_OPTION = 'ogs_seo_audit_cache';
	private const IGNORED_OPTION = 'ogs_seo_audit_ignored';

	public function register(): void {
		add_action( 'init', array( $this, 'schedule' ) );
		add_action( 'ogs_seo_run_audit', array( $this, 'run_incremental' ) );
	}

	public function schedule(): void {
		if ( ! wp_next_scheduled( 'ogs_seo_run_audit' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'ogs_seo_run_audit' );
		}
	}

	public function run_full(): array {
		return $this->run_internal( true, false );
	}

	public function run_incremental(): array {
		return $this->run_internal( false, false );
	}

	public function run(): array {
		return $this->run_full();
	}

	public function get_latest_issues( bool $include_ignored = false ): array {
		$issues = get_option( self::ISSUES_OPTION, array() );
		if ( ! is_array( $issues ) ) {
			$issues = array();
		}
		return $include_ignored ? $issues : $this->without_ignored( $issues );
	}

	public function get_scan_state(): array {
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	public function summary( bool $include_ignored = false ): array {
		$issues = $this->get_latest_issues( $include_ignored );
		$counts = array(
			'critical'  => 0,
			'important' => 0,
			'minor'     => 0,
		);
		$categories = array();
		$quick_wins = 0;
		foreach ( $issues as $issue ) {
			if ( ! is_array( $issue ) ) {
				continue;
			}
			$severity = sanitize_key( (string) ( $issue['severity'] ?? 'minor' ) );
			if ( isset( $counts[ $severity ] ) ) {
				++$counts[ $severity ];
			}
			$category = sanitize_key( (string) ( $issue['category'] ?? 'technical' ) );
			if ( '' !== $category ) {
				$categories[ $category ] = isset( $categories[ $category ] ) ? $categories[ $category ] + 1 : 1;
			}
			if ( ! empty( $issue['quick_win'] ) ) {
				++$quick_wins;
			}
		}
		arsort( $categories );

		return array(
			'total'       => count( $issues ),
			'critical'    => $counts['critical'],
			'important'   => $counts['important'],
			'minor'       => $counts['minor'],
			'quick_wins'  => $quick_wins,
			'categories'  => $categories,
			'last_run'    => (int) get_option( self::LAST_RUN_OPTION, 0 ),
			'scan_state'  => $this->get_scan_state(),
		);
	}

	public function grouped_issues( bool $include_ignored = false ): array {
		$groups = array();
		foreach ( $this->get_latest_issues( $include_ignored ) as $issue ) {
			if ( ! is_array( $issue ) ) {
				continue;
			}
			$category = sanitize_key( (string) ( $issue['category'] ?? 'technical' ) );
			if ( '' === $category ) {
				$category = 'technical';
			}
			if ( ! isset( $groups[ $category ] ) ) {
				$groups[ $category ] = array();
			}
			$groups[ $category ][] = $issue;
		}

		uksort(
			$groups,
			function ( string $left, string $right ): int {
				return $this->category_weight( $right ) <=> $this->category_weight( $left );
			}
		);

		return $groups;
	}

	public function ignore_issue( string $issue_id, string $reason ): bool {
		$issue_id = sanitize_key( $issue_id );
		$reason   = sanitize_text_field( $reason );
		if ( '' === $issue_id || '' === $reason ) {
			return false;
		}
		$ignored = get_option( self::IGNORED_OPTION, array() );
		if ( ! is_array( $ignored ) ) {
			$ignored = array();
		}
		$ignored[ $issue_id ] = array(
			'reason'  => $reason,
			'time'    => time(),
			'user_id' => get_current_user_id(),
		);
		update_option( self::IGNORED_OPTION, $ignored, false );
		return true;
	}

	public function unignore_issue( string $issue_id ): bool {
		$issue_id = sanitize_key( $issue_id );
		if ( '' === $issue_id ) {
			return false;
		}
		$ignored = get_option( self::IGNORED_OPTION, array() );
		if ( ! is_array( $ignored ) || ! isset( $ignored[ $issue_id ] ) ) {
			return false;
		}
		unset( $ignored[ $issue_id ] );
		update_option( self::IGNORED_OPTION, $ignored, false );
		return true;
	}

	public function get_ignored_map(): array {
		$ignored = get_option( self::IGNORED_OPTION, array() );
		return is_array( $ignored ) ? $ignored : array();
	}

	private function run_internal( bool $force_full, bool $allow_cache ): array {
		if ( $allow_cache && ! $force_full ) {
			$cached = get_option( self::CACHE_OPTION, array() );
			if ( is_array( $cached ) && ! empty( $cached['expires'] ) && (int) $cached['expires'] > time() && ! empty( $cached['issues'] ) && is_array( $cached['issues'] ) ) {
				return $this->without_ignored( (array) $cached['issues'] );
			}
		}

		$core = $this->core_checks();
		$mod  = $this->run_registered_checks();
		$scan = $this->scan_content( $force_full );
		update_option( self::STATE_OPTION, (array) ( $scan['state'] ?? array() ), false );

		$content_issues   = isset( $scan['issues'] ) && is_array( $scan['issues'] ) ? $scan['issues'] : array();
		$scanned_post_ids = isset( $scan['scanned_post_ids'] ) && is_array( $scan['scanned_post_ids'] ) ? $scan['scanned_post_ids'] : array();

		if ( $force_full ) {
			$issues = $this->normalize_issues( array_merge( $core, $mod, $content_issues ) );
		} else {
			$existing = $this->drop_issues_for_scanned_posts( $this->get_latest_issues( true ), $scanned_post_ids );
			$issues   = $this->normalize_issues( array_merge( $existing, $core, $mod, $content_issues ) );
		}

		update_option( self::ISSUES_OPTION, $issues, false );
		update_option( self::LAST_RUN_OPTION, time(), false );
		update_option(
			self::CACHE_OPTION,
			array(
				'expires' => time() + 600,
				'issues' => $issues,
			),
			false
		);

		return $this->without_ignored( $issues );
	}
	private function core_checks(): array {
		$issues   = array();
		$settings = Settings::get_all();
		$public   = (bool) get_option( 'blog_public' );

		if ( ! $public ) {
			$issues[] = $this->build_issue(
				'critical',
				__( 'Search engines are discouraged site-wide', 'open-growth-seo' ),
				__( 'Site visibility is configured to discourage indexing.', 'open-growth-seo' ),
				__( 'Enable indexing in Settings > Reading for public websites.', 'open-growth-seo' ),
				array(
					'setting' => 'blog_public',
					'actual' => '0',
				),
				'core'
			);
		}
		if ( ! Settings::get( 'sitemap_enabled', 1 ) ) {
			$issues[] = $this->build_issue(
				'important',
				__( 'Sitemaps are disabled', 'open-growth-seo' ),
				__( 'XML sitemap generation is disabled, reducing URL discoverability.', 'open-growth-seo' ),
				__( 'Enable sitemaps and include relevant public post types.', 'open-growth-seo' ),
				array(
					'setting' => 'sitemap_enabled',
					'actual' => '0',
				),
				'core'
			);
		}
		if ( 'disallow' === (string) ( $settings['robots_global_policy'] ?? 'allow' ) && 'index,follow' === (string) ( $settings['default_index'] ?? 'index,follow' ) ) {
			$issues[] = $this->build_issue( 'important', __( 'Global crawl policy conflicts with index defaults', 'open-growth-seo' ), __( 'robots.txt global disallow blocks crawl while defaults still encourage indexing.', 'open-growth-seo' ), __( 'Align crawl and index policies to avoid inconsistent behavior.', 'open-growth-seo' ), array( 'settings' => 'robots_global_policy,default_index' ), 'core' );
		}
		if ( $public && str_contains( strtolower( (string) ( $settings['default_index'] ?? '' ) ), 'noindex' ) ) {
			$issues[] = $this->build_issue(
				'important',
				__( 'Global index defaults are set to noindex', 'open-growth-seo' ),
				__( 'The site is publicly crawlable but default robots settings discourage indexation across content.', 'open-growth-seo' ),
				__( 'Use noindex selectively instead of site-wide defaults when public visibility is intended.', 'open-growth-seo' ),
				array(
					'setting' => 'default_index',
					'actual' => (string) ( $settings['default_index'] ?? '' ),
				),
				'core'
			);
		}
		if ( ! empty( $settings['default_nosnippet'] ) && '' !== (string) ( $settings['default_max_snippet'] ?? '' ) ) {
			$issues[] = $this->build_issue( 'minor', __( 'Global snippet controls are contradictory', 'open-growth-seo' ), __( 'nosnippet is enabled globally, so max-snippet values are ignored.', 'open-growth-seo' ), __( 'Disable nosnippet or clear max-snippet to keep snippet behavior intentional.', 'open-growth-seo' ), array( 'settings' => 'default_nosnippet,default_max_snippet' ), 'core' );
		}
		$has_other = defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' );
		if ( $has_other ) {
			$safe_mode = ! empty( $settings['safe_mode_seo_conflict'] );
			$issues[]  = $this->build_issue( $safe_mode ? 'important' : 'critical', __( 'Another SEO plugin is active', 'open-growth-seo' ), __( 'Potential duplicate meta/schema output risk detected due to multiple SEO plugins.', 'open-growth-seo' ), $safe_mode ? __( 'Keep safe mode enabled and plan a controlled migration/takeover.', 'open-growth-seo' ) : __( 'Enable safe mode immediately or disable conflicting SEO outputs.', 'open-growth-seo' ), array( 'safe_mode_seo_conflict' => $safe_mode ? '1' : '0' ), 'core' );
		}
		if ( class_exists( 'WooCommerce' ) && 'index' === (string) ( $settings['woo_product_tag_archive'] ?? 'noindex' ) ) {
			$issues[] = $this->build_issue(
				'minor',
				__( 'Product tag archives are indexable', 'open-growth-seo' ),
				__( 'Product tag archives can become low-value index bloat on many stores.', 'open-growth-seo' ),
				__( 'Review whether product tags should be noindex for your catalog strategy.', 'open-growth-seo' ),
				array(
					'setting' => 'woo_product_tag_archive',
					'actual' => 'index',
				),
				'core'
			);
		}

		return $issues;
	}

	private function run_registered_checks(): array {
		$issues = array();
		$checks = apply_filters( 'ogs_seo_audit_checks', array() );
		foreach ( $checks as $callback ) {
			if ( ! is_callable( $callback ) ) {
				continue;
			}
			$result = call_user_func( $callback );
			if ( empty( $result ) ) {
				continue;
			}
			if ( isset( $result[0] ) && is_array( $result[0] ) ) {
				foreach ( $result as $row ) {
					if ( is_array( $row ) ) {
						$issues[] = $row;
					}
				}
				continue;
			}
			if ( is_array( $result ) ) {
				$issues[] = $result;
			}
		}
		return $issues;
	}

	private function scan_content( bool $full ): array {
		$issues            = array();
		$title_map         = array();
		$meta_map          = array();
		$orphan_candidates = array();
		$scanned_post_ids  = array();
		$settings          = Settings::get_all();
		$sitemap_enabled   = ! empty( $settings['sitemap_enabled'] );
		$sitemap_types     = isset( $settings['sitemap_post_types'] ) && is_array( $settings['sitemap_post_types'] ) ? array_values( array_map( 'sanitize_key', $settings['sitemap_post_types'] ) ) : array();
		$size              = $full ? 200 : max( 10, min( 100, absint( Settings::get( 'audit_scan_batch_size', 40 ) ) ) );
		$state             = $this->get_scan_state();
		$offset            = $full ? 0 : absint( $state['offset'] ?? 0 );
		$post_types        = get_post_types( array( 'public' => true ), 'names' );
		unset( $post_types['attachment'] );
		$post_types = array_values( $post_types );
		$link_graph = LinkGraph::snapshot( $full );

		if ( $full ) {
			$current_offset = 0;
			while ( true ) {
				$batch = get_posts(
					array(
						'post_type' => $post_types,
						'post_status' => 'publish',
						'posts_per_page' => $size,
						'offset' => $current_offset,
						'orderby' => 'ID',
						'order' => 'ASC',
						'fields' => 'ids',
						'no_found_rows' => true,
					)
				);
				if ( empty( $batch ) ) {
					break;
				}
				$this->scan_posts_batch( $batch, $issues, $title_map, $meta_map, $orphan_candidates, $scanned_post_ids, $settings, $sitemap_enabled, $sitemap_types, $link_graph );
				$current_offset += count( $batch );
				if ( count( $batch ) < $size ) {
					break;
				}
			}
			$this->append_duplicate_and_orphan_issues( $issues, $title_map, $meta_map, $orphan_candidates, $link_graph );
			return array(
				'issues' => $issues,
				'scanned_post_ids' => $scanned_post_ids,
				'state' => array(
					'offset' => 0,
					'finished' => true,
					'last_batch' => count( $scanned_post_ids ),
					'mode' => 'full',
					'time' => time(),
				),
			);
		}

		$posts = get_posts(
			array(
				'post_type' => $post_types,
				'post_status' => 'publish',
				'posts_per_page' => $size,
				'offset' => $offset,
				'orderby' => 'modified',
				'order' => 'DESC',
				'fields' => 'ids',
				'no_found_rows' => true,
			)
		);
		if ( ! empty( $posts ) ) {
			$this->scan_posts_batch( $posts, $issues, $title_map, $meta_map, $orphan_candidates, $scanned_post_ids, $settings, $sitemap_enabled, $sitemap_types, $link_graph );
			$this->append_duplicate_and_orphan_issues( $issues, $title_map, $meta_map, $orphan_candidates, $link_graph );
		}
		$new_offset = $offset + count( $posts );
		$finished   = false;
		if ( empty( $posts ) || count( $posts ) < $size ) {
			$new_offset = 0;
			$finished   = true;
		}
		return array(
			'issues' => $issues,
			'scanned_post_ids' => $scanned_post_ids,
			'state' => array(
				'offset' => $new_offset,
				'finished' => $finished,
				'last_batch' => count( $posts ),
				'mode' => 'incremental',
				'time' => time(),
			),
		);
	}
	private function scan_posts_batch( array $posts, array &$issues, array &$title_map, array &$meta_map, array &$orphan_candidates, array &$scanned_post_ids, array $settings, bool $sitemap_enabled, array $sitemap_types, array $link_graph ): void {
		foreach ( $posts as $post_id ) {
			$post_id = (int) $post_id;
			if ( $post_id <= 0 ) {
				continue;
			}
			$scanned_post_ids[] = $post_id;
			$url                = (string) get_permalink( $post_id );
			$post_type          = (string) get_post_type( $post_id );

			$title = $this->effective_title_for_post( $post_id, $settings );
			if ( '' === $title ) {
				$issues[] = $this->build_issue(
					'important',
					__( 'Missing effective SEO title', 'open-growth-seo' ),
					__( 'This URL resolves to an empty SEO title after template and fallback resolution.', 'open-growth-seo' ),
					__( 'Set a custom SEO title or adjust title templates for this content type.', 'open-growth-seo' ),
					array(
						'post_id' => (string) $post_id,
						'url' => $url,
					),
					'content'
				);
			} else {
				$key = $this->lower( $title );
				$title_map[ $key ]   = $title_map[ $key ] ?? array();
				$title_map[ $key ][] = $post_id;
			}

			$meta = $this->effective_meta_for_post( $post_id, $settings );
			if ( '' === $meta ) {
				$issues[] = $this->build_issue(
					'minor',
					__( 'Missing effective meta description', 'open-growth-seo' ),
					__( 'This URL resolves to an empty meta description after template and fallback resolution.', 'open-growth-seo' ),
					__( 'Add a custom description or improve meta templates/excerpts for this content type.', 'open-growth-seo' ),
					array(
						'post_id' => (string) $post_id,
						'url' => $url,
					),
					'content'
				);
			} else {
				$key = $this->lower( $meta );
				$meta_map[ $key ]   = $meta_map[ $key ] ?? array();
				$meta_map[ $key ][] = $post_id;
			}

			$this->append_length_issues( $issues, $post_id, $url, $title, $meta );

			$robots    = (string) get_post_meta( $post_id, 'ogs_seo_robots', true );
			$noindex   = str_contains( strtolower( $robots ), 'noindex' );
			$canonical = trim( (string) get_post_meta( $post_id, 'ogs_seo_canonical', true ) );

			if ( $sitemap_enabled && in_array( $post_type, $sitemap_types, true ) && $noindex ) {
				$issues[] = $this->build_issue(
					'important',
					__( 'Noindex URL is still eligible for sitemap inclusion', 'open-growth-seo' ),
					__( 'This content has a noindex override while its post type is configured for sitemap output.', 'open-growth-seo' ),
					__( 'Confirm the URL should remain noindex or remove the conflicting override.', 'open-growth-seo' ),
					array(
						'post_id' => (string) $post_id,
						'url' => $url,
					),
					'content'
				);
			}
			if ( $noindex && '' !== $canonical ) {
				$issues[] = $this->build_issue(
					'important',
					__( 'noindex with canonical override detected', 'open-growth-seo' ),
					__( 'Content has noindex plus manual canonical, which can cause mixed index signals.', 'open-growth-seo' ),
					__( 'Review whether canonical override is necessary when URL is noindex.', 'open-growth-seo' ),
					array(
						'post_id' => (string) $post_id,
						'url' => $url,
						'canonical' => $canonical,
					),
					'content'
				);
			}
			if ( '' !== $canonical && '' !== $url && $this->normalize_url( $canonical ) !== $this->normalize_url( $url ) ) {
				$issues[] = $this->build_issue(
					'important',
					__( 'Canonical points to different URL than sitemap candidate', 'open-growth-seo' ),
					__( 'This URL has a canonical override to another URL. Source URL should not be in sitemap and may indicate duplication.', 'open-growth-seo' ),
					__( 'Confirm canonical target and ensure only canonical URL remains indexable/discoverable.', 'open-growth-seo' ),
					array(
						'post_id' => (string) $post_id,
						'canonical' => $this->normalize_url( $canonical ),
						'url' => $this->normalize_url( $url ),
					),
					'content'
				);
			}

			$nosnippet = (string) get_post_meta( $post_id, 'ogs_seo_nosnippet', true );
			$max_snip  = (string) get_post_meta( $post_id, 'ogs_seo_max_snippet', true );
			if ( '1' === $nosnippet && '' !== $max_snip ) {
				$issues[] = $this->build_issue(
					'minor',
					__( 'nosnippet overrides max-snippet', 'open-growth-seo' ),
					__( 'max-snippet has no effect when nosnippet is enabled for this URL.', 'open-growth-seo' ),
					__( 'Remove max-snippet or disable nosnippet for this content.', 'open-growth-seo' ),
					array(
						'post_id' => (string) $post_id,
						'url' => $url,
					),
					'content'
				);
			}

			$content = (string) get_post_field( 'post_content', $post_id );
			$this->append_content_quality_issues( $issues, $post_id, $url, $content, $post_type, $noindex );
			if ( '' !== trim( $content ) && $this->is_schema_override_incoherent( trim( (string) get_post_meta( $post_id, 'ogs_seo_schema_type', true ) ), $content ) ) {
				$issues[] = $this->build_issue(
					'important',
					__( 'Schema override may not match visible content', 'open-growth-seo' ),
					__( 'A manual schema override was detected but required visible content signals were not found.', 'open-growth-seo' ),
					__( 'Adjust the override or update visible content so it matches the selected schema type.', 'open-growth-seo' ),
					array(
						'post_id' => (string) $post_id,
						'url' => $url,
						'schema_type' => (string) get_post_meta( $post_id, 'ogs_seo_schema_type', true ),
					),
					'content'
				);
			}

			if ( '1' === (string) get_post_meta( $post_id, 'ogs_seo_cornerstone', true ) ) {
				$inbound_links = LinkGraph::inbound_count( $post_id );
				if ( $inbound_links <= 0 ) {
					$inbound_links = LinkSuggestions::inbound_link_count( $post_id );
				}
				$suggestions   = LinkSuggestions::inbound_opportunities( $post_id, 3 );
				$orphan_view   = LinkGraph::orphan_assessment( $post_id );
				if ( $inbound_links < 2 ) {
					$issues[] = $this->build_issue(
						'important',
						__( 'Cornerstone content has weak internal support', 'open-growth-seo' ),
						__( 'This page is marked as cornerstone content but still receives very few internal links from related content.', 'open-growth-seo' ),
						__( 'Add links from related guides, hub pages, or support content so this page becomes easier to discover and reinforce.', 'open-growth-seo' ),
						array(
							'post_id'              => (string) $post_id,
							'url'                  => $url,
							'inbound_internal_links' => (string) $inbound_links,
							'link_graph_confidence' => (string) ( $orphan_view['confidence'] ?? 'low' ),
							'suggested_source_ids' => $this->suggestion_ids_csv( $suggestions ),
						),
						'content'
					);
				}

				$days_since_modified = $this->days_since_modified( (string) get_post_modified_time( 'Y-m-d H:i:s', true, $post_id ) );
				if ( $days_since_modified > 365 ) {
					$issues[] = $this->build_issue(
						'important',
						__( 'Cornerstone content appears stale', 'open-growth-seo' ),
						__( 'This cornerstone page has not been refreshed recently, which weakens its value as a primary reference page.', 'open-growth-seo' ),
						__( 'Review key facts, examples, dates, and internal links so the page stays current and worth promoting across the site.', 'open-growth-seo' ),
						array(
							'post_id'              => (string) $post_id,
							'url'                  => $url,
							'days_since_modified'  => (string) $days_since_modified,
							'suggested_source_ids' => $this->suggestion_ids_csv( $suggestions ),
						),
						'content'
					);
				}
			}

			if ( '' !== trim( $content ) ) {
				$orphan_candidates[] = $post_id;
			}
			if ( class_exists( 'WooCommerce' ) && 'product' === $post_type ) {
				$this->scan_woocommerce_product( $post_id, $issues );
			}
		}
	}

	private function append_duplicate_and_orphan_issues( array &$issues, array $title_map, array $meta_map, array $orphan_candidates, array $link_graph ): void {
		$graph_coverage = isset( $link_graph['coverage'] ) ? (float) $link_graph['coverage'] : 0.0;
		foreach ( $title_map as $post_ids ) {
			if ( count( $post_ids ) > 1 ) {
				$issues[] = $this->build_issue( 'important', __( 'Duplicate SEO title detected', 'open-growth-seo' ), __( 'Multiple URLs share the same resolved SEO title.', 'open-growth-seo' ), __( 'Differentiate titles to match each URL intent.', 'open-growth-seo' ), array( 'post_ids' => implode( ',', array_slice( $post_ids, 0, 12 ) ) ), 'content' );
			}
		}
		foreach ( $meta_map as $post_ids ) {
			if ( count( $post_ids ) > 1 ) {
				$issues[] = $this->build_issue( 'minor', __( 'Duplicate meta description detected', 'open-growth-seo' ), __( 'Multiple URLs share the same resolved meta description.', 'open-growth-seo' ), __( 'Adjust descriptions to reflect each page summary.', 'open-growth-seo' ), array( 'post_ids' => implode( ',', array_slice( $post_ids, 0, 12 ) ) ), 'content' );
			}
		}
		foreach ( array_slice( $orphan_candidates, 0, 15 ) as $candidate_id ) {
			$assessment = LinkGraph::orphan_assessment( (int) $candidate_id );
			if ( 'orphan' === (string) ( $assessment['status'] ?? '' ) ) {
				$suggestions = LinkSuggestions::inbound_opportunities( (int) $candidate_id, 3 );
				$issues[] = $this->build_issue(
					'high' === (string) ( $assessment['confidence'] ?? 'medium' ) ? 'important' : 'minor',
					__( 'Potential orphan page detected', 'open-growth-seo' ),
					__( 'No internal inbound links were found from the internal link graph sample.', 'open-growth-seo' ),
					__( 'Add internal links from relevant sections/pages to improve discoverability.', 'open-growth-seo' ),
					array(
						'post_id'              => (string) $candidate_id,
						'url'                  => (string) get_permalink( (int) $candidate_id ),
						'orphan_confidence'    => (string) ( $assessment['confidence'] ?? 'medium' ),
						'cluster_score'        => (string) ( $assessment['cluster_score'] ?? 0 ),
						'graph_coverage'       => (string) round( $graph_coverage, 2 ),
						'suggested_source_ids' => $this->suggestion_ids_csv( $suggestions ),
					),
					'content'
				);
			}
		}

		$queue = LinkGraph::stale_cornerstone_queue( 5 );
		foreach ( $queue as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$post_id = isset( $item['post_id'] ) ? absint( $item['post_id'] ) : 0;
			if ( $post_id <= 0 ) {
				continue;
			}
			$issues[] = $this->build_issue(
				'important',
				__( 'Cornerstone queue needs review', 'open-growth-seo' ),
				__( 'A cornerstone URL was flagged by the link graph queue for freshness/support follow-up.', 'open-growth-seo' ),
				__( 'Update this cornerstone page and reinforce links from related content.', 'open-growth-seo' ),
				array(
					'post_id'            => (string) $post_id,
					'url'                => (string) get_permalink( $post_id ),
					'queue_score'        => (string) ( $item['score'] ?? 0 ),
					'days_since_modified' => (string) ( $item['days_since_modified'] ?? -1 ),
					'inbound_internal_links' => (string) ( $item['inbound'] ?? 0 ),
				),
				'content'
			);
		}
	}

	private function effective_title_for_post( int $post_id, array $settings ): string {
		$title = trim( (string) get_post_meta( $post_id, 'ogs_seo_title', true ) );
		if ( '' !== $title ) {
			return $title;
		}
		$post_type = (string) get_post_type( $post_id );
		$template  = isset( $settings['post_type_title_templates'][ $post_type ] ) && '' !== (string) $settings['post_type_title_templates'][ $post_type ] ? (string) $settings['post_type_title_templates'][ $post_type ] : (string) ( $settings['title_template'] ?? '' );
		$resolved  = $this->apply_template_tokens( $template, $post_id, $settings );
		return '' !== $resolved ? $resolved : trim( (string) get_the_title( $post_id ) );
	}

	private function effective_meta_for_post( int $post_id, array $settings ): string {
		$meta = trim( (string) get_post_meta( $post_id, 'ogs_seo_description', true ) );
		if ( '' !== $meta ) {
			return $meta;
		}
		$post_type = (string) get_post_type( $post_id );
		$template  = isset( $settings['post_type_meta_templates'][ $post_type ] ) && '' !== (string) $settings['post_type_meta_templates'][ $post_type ] ? (string) $settings['post_type_meta_templates'][ $post_type ] : (string) ( $settings['meta_description_template'] ?? '' );
		$resolved  = $this->apply_template_tokens( $template, $post_id, $settings );
		if ( '' !== $resolved ) {
			return $resolved;
		}
		$excerpt = trim( (string) get_post_field( 'post_excerpt', $post_id ) );
		if ( '' !== $excerpt ) {
			return $excerpt;
		}
		return trim( wp_trim_words( wp_strip_all_tags( (string) get_post_field( 'post_content', $post_id ) ), 30 ) );
	}

	private function apply_template_tokens( string $template, int $post_id, array $settings ): string {
		$template = trim( wp_strip_all_tags( $template ) );
		if ( '' === $template ) {
			return '';
		}
		$excerpt = trim( (string) get_post_field( 'post_excerpt', $post_id ) );
		if ( '' === $excerpt ) {
			$excerpt = wp_trim_words( wp_strip_all_tags( (string) get_post_field( 'post_content', $post_id ) ), 30 );
		}
		$tokens = array(
			'%%title%%' => (string) get_the_title( $post_id ),
			'%%sitename%%' => (string) get_bloginfo( 'name' ),
			'%%sep%%' => (string) ( $settings['title_separator'] ?? '|' ),
			'%%excerpt%%' => $excerpt,
			'%%query%%' => '',
			'%%site_description%%' => (string) get_bloginfo( 'description' ),
			'%%archive_title%%' => '',
			'%%term%%' => '',
			'%%term_description%%' => '',
			'%%author%%' => '',
		);
		return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( strtr( $template, $tokens ) ) ) );
	}

	private function is_schema_override_incoherent( string $schema_type, string $content ): bool {
		$schema_type = trim( $schema_type );
		if ( '' === $schema_type ) {
			return false;
		}
		$plain = strtolower( wp_strip_all_tags( $content ) );
		if ( 'FAQPage' === $schema_type ) {
			return preg_match_all( '/<h[2-4][^>]*>[^<]+\?<\/h[2-4]>/i', $content ) < 2;
		}
		if ( 'QAPage' === $schema_type ) {
			return ! preg_match( '/\bq[:\-].+\ba[:\-]/i', $plain );
		}
		if ( 'Recipe' === $schema_type ) {
			return ! preg_match( '/\b(ingredients|instructions|servings)\b/i', $plain );
		}
		if ( 'JobPosting' === $schema_type ) {
			return ! preg_match( '/\b(salary|employment|responsibilit|qualifications?)\b/i', $plain );
		}
		if ( 'Event' === $schema_type ) {
			return ! preg_match( '/\b(date|time|location|venue|ticket)\b/i', $plain );
		}
		if ( 'Product' === $schema_type ) {
			return ! preg_match( '/\b(price|sku|availability|specifications?)\b/i', $plain );
		}
		return false;
	}

	private function scan_woocommerce_product( int $post_id, array &$issues ): void {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $post_id ) : null;
		if ( ! $product ) {
			return;
		}
		$price = method_exists( $product, 'get_price' ) ? (string) $product->get_price() : '';
		if ( '' === trim( $price ) ) {
			$issues[] = $this->build_issue( 'important', __( 'WooCommerce product missing price', 'open-growth-seo' ), __( 'Product has no configured price.', 'open-growth-seo' ), __( 'Set product price and availability before indexing.', 'open-growth-seo' ), array( 'post_id' => (string) $post_id ), 'woocommerce' );
		}
		if ( method_exists( $product, 'is_type' ) && method_exists( $product, 'get_children' ) && $product->is_type( 'variable' ) ) {
			$variation_ids = array_map( 'absint', (array) $product->get_children() );
			if ( empty( $variation_ids ) ) {
				$issues[] = $this->build_issue(
					'important',
					__( 'Variable product has no purchasable variations', 'open-growth-seo' ),
					__( 'Variable product does not contain variation rows to generate valid offers.', 'open-growth-seo' ),
					__( 'Add at least one in-stock variation with price data.', 'open-growth-seo' ),
					array( 'post_id' => (string) $post_id ),
					'woocommerce'
				);
			} elseif ( ! $this->has_priced_variation( $variation_ids ) ) {
				$issues[] = $this->build_issue(
					'important',
					__( 'Variable product variations are missing prices', 'open-growth-seo' ),
					__( 'All detected variations are missing valid prices, so Product offers become weak or absent.', 'open-growth-seo' ),
					__( 'Set price and availability on at least one variation.', 'open-growth-seo' ),
					array( 'post_id' => (string) $post_id ),
					'woocommerce'
				);
			}
		}
		$settings   = Settings::get_all();
		$min_words  = max( 30, min( 500, absint( $settings['woo_min_product_description_words'] ?? 80 ) ) );
		$word_count = $this->word_count( (string) get_post_field( 'post_content', $post_id ) );
		if ( $word_count > 0 && $word_count < $min_words ) {
			$issues[] = $this->build_issue(
				'minor',
				__( 'Product content appears thin', 'open-growth-seo' ),
				__( 'Product description has limited textual detail for search and answer extraction.', 'open-growth-seo' ),
				__( 'Expand core product details (use cases, specs, compatibility, returns/shipping context).', 'open-growth-seo' ),
				array(
					'post_id'    => (string) $post_id,
					'word_count' => (string) $word_count,
					'min_words'  => (string) $min_words,
				),
				'woocommerce'
			);
		}
	}

	private function has_priced_variation( array $variation_ids ): bool {
		foreach ( array_slice( $variation_ids, 0, 50 ) as $variation_id ) {
			$variation = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $variation_id ) : null;
			if ( ! $variation || ! is_object( $variation ) || ! method_exists( $variation, 'get_price' ) ) {
				continue;
			}
			$price = trim( (string) $variation->get_price() );
			if ( '' !== $price ) {
				return true;
			}
		}
		return false;
	}

	private function word_count( string $content ): int {
		$content = wp_strip_all_tags( $content );
		$words   = preg_split( '/\s+/', trim( $content ) ) ?: array();
		return count( array_filter( $words, static fn( $word ) => '' !== trim( (string) $word ) ) );
	}

	private function days_since_modified( string $modified_gmt ): int {
		if ( '' === trim( $modified_gmt ) ) {
			return -1;
		}
		$timestamp = strtotime( $modified_gmt . ' GMT' );
		if ( false === $timestamp ) {
			return -1;
		}
		$diff = time() - $timestamp;
		if ( $diff < 0 ) {
			return 0;
		}
		return (int) floor( $diff / DAY_IN_SECONDS );
	}

	private function suggestion_ids_csv( array $suggestions ): string {
		$ids = array();
		foreach ( $suggestions as $suggestion ) {
			$post_id = isset( $suggestion['post_id'] ) ? absint( $suggestion['post_id'] ) : 0;
			if ( $post_id > 0 ) {
				$ids[] = $post_id;
			}
		}
		return implode( ',', array_slice( array_values( array_unique( $ids ) ), 0, 5 ) );
	}

	private function drop_issues_for_scanned_posts( array $issues, array $scanned_post_ids ): array {
		if ( empty( $scanned_post_ids ) ) {
			return $issues;
		}
		$map = array();
		foreach ( $scanned_post_ids as $id ) {
			$map[ (string) (int) $id ] = true;
		}
		return array_values(
			array_filter(
				$issues,
				static function ( $issue ) use ( $map ) {
					if ( ! is_array( $issue ) ) {
						return false;
					}
					$trace   = isset( $issue['trace'] ) && is_array( $issue['trace'] ) ? $issue['trace'] : array();
					$post_id = isset( $trace['post_id'] ) ? (string) $trace['post_id'] : '';
					if ( '' !== $post_id && isset( $map[ $post_id ] ) ) {
						return false;
					}
					return true;
				}
			)
		);
	}

	private function normalize_issues( array $issues ): array {
		$normalized = array();
		foreach ( $issues as $issue ) {
			if ( ! is_array( $issue ) || empty( $issue['title'] ) ) {
				continue;
			}
			$severity = isset( $issue['severity'] ) ? (string) $issue['severity'] : 'minor';
			if ( ! in_array( $severity, array( 'critical', 'important', 'minor' ), true ) ) {
				$severity = 'minor';
			}
			$trace = isset( $issue['trace'] ) && is_array( $issue['trace'] ) ? $issue['trace'] : array();
			$id    = isset( $issue['id'] ) ? sanitize_key( (string) $issue['id'] ) : '';
			if ( '' === $id ) {
				$id = sanitize_key( substr( md5( sanitize_key( (string) ( $issue['source'] ?? 'external' ) ) . '|' . sanitize_text_field( (string) $issue['title'] ) . '|' . wp_json_encode( $trace ) ), 0, 16 ) );
			}
			$normalized[] = array(
				'id' => $id,
				'severity' => $severity,
				'title' => sanitize_text_field( (string) $issue['title'] ),
				'explanation' => sanitize_text_field( (string) ( $issue['explanation'] ?? $issue['title'] ) ),
				'recommendation' => sanitize_text_field( (string) ( $issue['recommendation'] ?? '' ) ),
				'trace' => $this->sanitize_trace( $trace ),
				'source' => sanitize_key( (string) ( $issue['source'] ?? 'external' ) ),
				'category' => $this->issue_category( $issue, $trace ),
				'confidence' => $this->issue_confidence( $issue, $trace ),
				'quick_win' => $this->is_quick_win_issue( $issue, $trace ),
				'impact_score' => $this->issue_impact_score( $issue, $trace ),
				'detected_at' => time(),
			);
		}
		usort(
			$normalized,
			static function ( array $a, array $b ): int {
				$impact = (int) ( $b['impact_score'] ?? 0 ) <=> (int) ( $a['impact_score'] ?? 0 );
				if ( 0 !== $impact ) {
					return $impact;
				}
				$w = array( 'critical' => 3, 'important' => 2, 'minor' => 1 );
				return ( $w[ $b['severity'] ] ?? 0 ) <=> ( $w[ $a['severity'] ] ?? 0 );
			}
		);
		$unique = array();
		foreach ( $normalized as $issue ) {
			$unique[ $issue['id'] ] = $issue;
		}
		return array_values( $unique );
	}

	private function sanitize_trace( array $trace ): array {
		$clean = array();
		foreach ( $trace as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			$clean[ $key ] = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '[complex]';
		}
		return $clean;
	}

	private function lower( string $value ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value );
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
		$normalized .= isset( $parts['path'] ) && '' !== (string) $parts['path'] ? (string) $parts['path'] : '/';
		if ( ! empty( $parts['query'] ) ) {
			$normalized .= '?' . (string) $parts['query'];
		}
		return $normalized;
	}

	private function without_ignored( array $issues ): array {
		$ignored = $this->get_ignored_map();
		if ( empty( $ignored ) ) {
			return $issues;
		}
		return array_values( array_filter( $issues, static fn( $issue ) => empty( $ignored[ $issue['id'] ?? '' ] ) ) );
	}

	private function build_issue( string $severity, string $title, string $explanation, string $recommendation, array $trace, string $source ): array {
		return array(
			'severity' => $severity,
			'title' => $title,
			'explanation' => $explanation,
			'recommendation' => $recommendation,
			'trace' => $trace,
			'source' => $source,
		);
	}

	private function append_length_issues( array &$issues, int $post_id, string $url, string $title, string $meta ): void {
		$title_length = function_exists( 'mb_strlen' ) ? mb_strlen( $title ) : strlen( $title );
		if ( '' !== $title && $title_length < 20 ) {
			$issues[] = $this->build_issue(
				'minor',
				__( 'SEO title is very short', 'open-growth-seo' ),
				__( 'The effective SEO title may not provide enough context or differentiation in search results.', 'open-growth-seo' ),
				__( 'Expand the title with clearer intent, entity, or qualifier details.', 'open-growth-seo' ),
				array( 'post_id' => (string) $post_id, 'url' => $url, 'title_length' => (string) $title_length ),
				'content'
			);
		} elseif ( $title_length > 65 ) {
			$issues[] = $this->build_issue(
				'minor',
				__( 'SEO title may be too long', 'open-growth-seo' ),
				__( 'The effective SEO title may truncate or dilute its primary topic in search results.', 'open-growth-seo' ),
				__( 'Tighten the title so the main topic appears earlier and more clearly.', 'open-growth-seo' ),
				array( 'post_id' => (string) $post_id, 'url' => $url, 'title_length' => (string) $title_length ),
				'content'
			);
		}

		$meta_length = function_exists( 'mb_strlen' ) ? mb_strlen( $meta ) : strlen( $meta );
		if ( '' !== $meta && $meta_length < 70 ) {
			$issues[] = $this->build_issue(
				'minor',
				__( 'Meta description is very short', 'open-growth-seo' ),
				__( 'The effective meta description may be too brief to explain the page value clearly.', 'open-growth-seo' ),
				__( 'Expand the description so it better summarizes the page and sets click expectations.', 'open-growth-seo' ),
				array( 'post_id' => (string) $post_id, 'url' => $url, 'meta_length' => (string) $meta_length ),
				'content'
			);
		} elseif ( $meta_length > 160 ) {
			$issues[] = $this->build_issue(
				'minor',
				__( 'Meta description may be too long', 'open-growth-seo' ),
				__( 'The effective meta description is likely to truncate in search results.', 'open-growth-seo' ),
				__( 'Shorten the description and keep the strongest summary earlier in the sentence.', 'open-growth-seo' ),
				array( 'post_id' => (string) $post_id, 'url' => $url, 'meta_length' => (string) $meta_length ),
				'content'
			);
		}
	}

	private function append_content_quality_issues( array &$issues, int $post_id, string $url, string $content, string $post_type, bool $noindex ): void {
		$content = (string) $content;
		if ( '' === trim( $content ) || $noindex ) {
			return;
		}

		$word_count = $this->word_count( $content );
		if ( in_array( $post_type, array( 'post', 'page' ), true ) && $word_count > 0 && $word_count < 120 ) {
			$issues[] = $this->build_issue(
				'minor',
				__( 'Content appears thin for indexable page', 'open-growth-seo' ),
				__( 'This page has limited visible text for an indexable URL, which can weaken SEO and answer extraction.', 'open-growth-seo' ),
				__( 'Add more original, user-facing detail that better explains the page topic and intent.', 'open-growth-seo' ),
				array( 'post_id' => (string) $post_id, 'url' => $url, 'word_count' => (string) $word_count ),
				'content'
			);
		}

		$heading_count = $this->heading_count( $content );
		if ( $word_count >= 350 && $heading_count <= 0 ) {
			$issues[] = $this->build_issue(
				'minor',
				__( 'Long content lacks subheadings', 'open-growth-seo' ),
				__( 'This page has substantial text but no clear H2/H3 structure for scanning and semantic organization.', 'open-growth-seo' ),
				__( 'Break the page into clearer sections with descriptive subheadings.', 'open-growth-seo' ),
				array( 'post_id' => (string) $post_id, 'url' => $url, 'word_count' => (string) $word_count, 'heading_count' => (string) $heading_count ),
				'content'
			);
		}

		$image_audit = $this->image_alt_audit( $content );
		if ( (int) $image_audit['missing_alt'] > 0 ) {
			$issues[] = $this->build_issue(
				(int) $image_audit['missing_alt'] >= 3 ? 'important' : 'minor',
				__( 'Some content images are missing alt text', 'open-growth-seo' ),
				__( 'One or more images in the main content do not include alt text, which weakens accessibility and image context.', 'open-growth-seo' ),
				__( 'Add concise, descriptive alt text to informative content images.', 'open-growth-seo' ),
				array(
					'post_id' => (string) $post_id,
					'url' => $url,
					'image_count' => (string) $image_audit['images'],
					'missing_alt' => (string) $image_audit['missing_alt'],
				),
				'content'
			);
		}
	}

	private function heading_count( string $content ): int {
		if ( ! preg_match_all( '/<h[2-3][^>]*>/i', $content, $matches ) ) {
			return 0;
		}
		return count( (array) $matches[0] );
	}

	private function image_alt_audit( string $content ): array {
		$images = 0;
		$missing_alt = 0;
		if ( preg_match_all( '/<img\b[^>]*>/i', $content, $matches ) ) {
			foreach ( (array) $matches[0] as $img ) {
				++$images;
				if ( ! preg_match( '/\balt\s*=\s*(["\'])(.*?)\1/i', (string) $img, $alt_match ) ) {
					++$missing_alt;
					continue;
				}
				$alt = trim( wp_strip_all_tags( (string) ( $alt_match[2] ?? '' ) ) );
				if ( '' === $alt ) {
					++$missing_alt;
				}
			}
		}
		return array(
			'images' => $images,
			'missing_alt' => $missing_alt,
		);
	}

	private function issue_category( array $issue, array $trace ): string {
		$source = sanitize_key( (string) ( $issue['source'] ?? 'external' ) );
		$title  = strtolower( sanitize_text_field( (string) ( $issue['title'] ?? '' ) ) );
		$blob   = $title . ' ' . strtolower( wp_json_encode( $trace ) ?: '' );

		if ( str_contains( $blob, 'cornerstone' ) || str_contains( $blob, 'orphan' ) || str_contains( $blob, 'internal link' ) ) {
			return 'linking';
		}
		if ( str_contains( $blob, 'schema' ) ) {
			return 'schema';
		}
		if ( str_contains( $blob, 'canonical' ) || str_contains( $blob, 'noindex' ) || str_contains( $blob, 'robots' ) || str_contains( $blob, 'sitemap' ) ) {
			return 'technical';
		}
		if ( 'woocommerce' === $source || str_contains( $blob, 'product' ) ) {
			return 'commerce';
		}
		if ( str_contains( $blob, 'title' ) || str_contains( $blob, 'meta description' ) || str_contains( $blob, 'image' ) || str_contains( $blob, 'content' ) || str_contains( $blob, 'heading' ) ) {
			return 'content';
		}
		if ( 'core' === $source ) {
			return 'technical';
		}
		return 'content';
	}

	private function issue_confidence( array $issue, array $trace ): string {
		if ( ! empty( $trace['orphan_confidence'] ) ) {
			return sanitize_key( (string) $trace['orphan_confidence'] );
		}
		if ( ! empty( $trace['link_graph_confidence'] ) ) {
			return sanitize_key( (string) $trace['link_graph_confidence'] );
		}
		$title = strtolower( sanitize_text_field( (string) ( $issue['title'] ?? '' ) ) );
		if ( str_contains( $title, 'potential orphan' ) ) {
			return 'medium';
		}
		return 'high';
	}

	private function is_quick_win_issue( array $issue, array $trace ): bool {
		$title = strtolower( sanitize_text_field( (string) ( $issue['title'] ?? '' ) ) );
		if ( isset( $trace['missing_alt'] ) || isset( $trace['meta_length'] ) || isset( $trace['title_length'] ) ) {
			return true;
		}
		return str_contains( $title, 'snippet controls are contradictory' )
			|| str_contains( $title, 'nosnippet overrides max-snippet' )
			|| str_contains( $title, 'meta description')
			|| str_contains( $title, 'seo title');
	}

	private function issue_impact_score( array $issue, array $trace ): int {
		$severity = sanitize_key( (string) ( $issue['severity'] ?? 'minor' ) );
		$score    = array(
			'critical'  => 100,
			'important' => 70,
			'minor'     => 35,
		)[ $severity ] ?? 35;
		$category = $this->issue_category( $issue, $trace );
		if ( in_array( $category, array( 'technical', 'schema', 'commerce' ), true ) ) {
			$score += 10;
		}
		if ( $this->is_quick_win_issue( $issue, $trace ) ) {
			$score += 6;
		}
		$confidence = $this->issue_confidence( $issue, $trace );
		if ( 'high' === $confidence ) {
			$score += 8;
		} elseif ( 'low' === $confidence ) {
			$score -= 6;
		}
		return max( 1, min( 120, $score ) );
	}

	private function category_weight( string $category ): int {
		$weights = array(
			'technical' => 5,
			'schema'    => 4,
			'commerce'  => 3,
			'linking'   => 2,
			'content'   => 1,
		);
		return $weights[ sanitize_key( $category ) ] ?? 0;
	}
}
