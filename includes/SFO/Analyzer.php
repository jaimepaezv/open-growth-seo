<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\SFO;

use OpenGrowthSolutions\OpenGrowthSEO\AEO\Analyzer as AeoAnalyzer;
use OpenGrowthSolutions\OpenGrowthSEO\GEO\BotControls;
use OpenGrowthSolutions\OpenGrowthSEO\Schema\SchemaManager;
use OpenGrowthSolutions\OpenGrowthSEO\SEO\FrontendMeta;

defined( 'ABSPATH' ) || exit;

class Analyzer {
	private const HISTORY_OPTION = 'ogs_seo_sfo_history';
	private const MAX_HISTORY = 6;
	private const MAX_TRACKED_POSTS = 30;

	public function register(): void {
		// SFO is currently a read-only analysis layer built on existing modules.
	}

	public static function analyze_post( int $post_id ): array {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 ) {
			return self::empty_analysis();
		}

		$title        = trim( (string) get_the_title( $post_id ) );
		$content      = (string) get_post_field( 'post_content', $post_id );
		$plain        = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $content ) ) );
		$url          = (string) get_permalink( $post_id );
		$seo_title    = trim( (string) get_post_meta( $post_id, 'ogs_seo_title', true ) );
		$seo_desc     = trim( (string) get_post_meta( $post_id, 'ogs_seo_description', true ) );
		$social_title = trim( (string) get_post_meta( $post_id, 'ogs_seo_social_title', true ) );
		$social_desc  = trim( (string) get_post_meta( $post_id, 'ogs_seo_social_description', true ) );
		$social_image = trim( (string) get_post_meta( $post_id, 'ogs_seo_social_image', true ) );

		$effective_title = '' !== $seo_title ? $seo_title : $title;
		$effective_desc  = '' !== $seo_desc ? $seo_desc : self::fallback_description( $plain );

		$aeo     = AeoAnalyzer::analyze_post( $post_id );
		$geo     = BotControls::analyze_post( $post_id, false );
		$inspect = ( new SchemaManager() )->inspect(
			array(
				'post_id' => $post_id,
			),
			false
		);
		$canonical = ( new FrontendMeta() )->canonical_diagnostics_for_post( $post_id );

		$title_length = self::string_length( $effective_title );
		$desc_length  = self::string_length( $effective_desc );
		$schema_nodes = isset( $inspect['summary']['nodes_emitted'] ) ? (int) $inspect['summary']['nodes_emitted'] : 0;
		$indexable    = ! empty( $inspect['context']['is_indexable'] );
		$schema_attention = ! empty( $geo['summary']['schema_runtime_attention'] );
		$snippet_restrictive = ! empty( $geo['summary']['snippet_participation_restrictive'] );
		$answer_ready  = ! empty( $aeo['summary']['answer_first'] );
		$rich_ready    = $schema_nodes > 0 && ! $schema_attention;
		$social_ready  = '' !== $social_title || '' !== $social_desc || '' !== $social_image;

		$feature_readiness = array(
			'search_snippet'  => '' !== $effective_title && '' !== $effective_desc && $title_length >= 35 && $title_length <= 65 && $desc_length >= 70 && $desc_length <= 170 && ! $snippet_restrictive,
			'featured_answer' => $answer_ready && (int) ( $aeo['summary']['follow_up_coverage'] ?? 0 ) >= 50,
			'rich_result'     => $rich_ready,
			'ai_overview'     => $answer_ready && ! $snippet_restrictive && ! empty( $geo['summary']['critical_text_visible'] ),
			'social_preview'  => $social_ready,
		);

		$opportunities = self::build_opportunities(
			$effective_title,
			$effective_desc,
			$title_length,
			$desc_length,
			$indexable,
			$answer_ready,
			$snippet_restrictive,
			$rich_ready,
			$social_ready,
			$feature_readiness,
			$canonical,
			$geo,
			$aeo
		);
		$score    = self::sfo_score( $feature_readiness, $opportunities );
		$priority = self::priority_status( $score, $opportunities );

		$analysis = array(
			'summary' => array(
				'sfo_score'            => $score,
				'priority_status'      => $priority,
				'title_length'         => $title_length,
				'description_length'   => $desc_length,
				'indexable'            => $indexable,
				'canonical_consistent' => empty( $canonical['conflicts'] ),
				'schema_nodes'         => $schema_nodes,
				'schema_attention'     => $schema_attention,
				'snippet_restrictive'  => $snippet_restrictive,
				'answer_ready'         => $answer_ready,
				'opportunity_count'    => count( $opportunities ),
				'quick_win_count'      => count(
					array_filter(
						$opportunities,
						static fn( $row ): bool => is_array( $row ) && ! empty( $row['quick_win'] )
					)
				),
			),
			'feature_readiness' => $feature_readiness,
			'opportunities'     => $opportunities,
			'priority_actions'  => array_slice( $opportunities, 0, 3 ),
			'recommendations'   => array_values(
				array_map(
					static function ( array $row ): string {
						return (string) ( $row['action'] ?? '' );
					},
					array_filter(
						$opportunities,
						static fn( $row ): bool => is_array( $row ) && ! empty( $row['action'] )
					)
				)
			),
		);
		if ( self::should_record_history() ) {
			$history_bundle      = self::record_snapshot( $post_id, $analysis );
			$analysis['history'] = isset( $history_bundle['history'] ) && is_array( $history_bundle['history'] ) ? $history_bundle['history'] : array();
			$analysis['diff']    = isset( $history_bundle['diff'] ) && is_array( $history_bundle['diff'] ) ? $history_bundle['diff'] : array();
			$analysis['trend']   = isset( $history_bundle['trend'] ) && is_array( $history_bundle['trend'] ) ? $history_bundle['trend'] : array();
		} else {
			$analysis['history'] = array();
			$analysis['diff']    = array(
				'message' => __( 'SFO history recording was skipped for this derived analysis.', 'open-growth-seo' ),
			);
			$analysis['trend']   = array(
				'state'   => 'derived',
				'message' => __( 'SFO trend tracking is not recorded for this derived analysis.', 'open-growth-seo' ),
			);
		}

		return $analysis;
	}

	public static function telemetry( int $limit = 8 ): array {
		$post_types = get_post_types(
			array(
				'public' => true,
			),
			'names'
		);
		unset( $post_types['attachment'] );

		$posts = get_posts(
			array(
				'post_type'      => array_values( $post_types ),
				'post_status'    => 'publish',
				'posts_per_page' => max( 1, min( 20, $limit ) ),
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		$entries = array();
		foreach ( $posts as $post_id ) {
			$post_id  = absint( $post_id );
			$analysis = self::analyze_post( $post_id );
			$summary  = isset( $analysis['summary'] ) && is_array( $analysis['summary'] ) ? $analysis['summary'] : array();
			$trend    = isset( $analysis['trend'] ) && is_array( $analysis['trend'] ) ? $analysis['trend'] : array();
			$feature  = isset( $analysis['feature_readiness'] ) && is_array( $analysis['feature_readiness'] ) ? $analysis['feature_readiness'] : array();
			$actions  = array();

			if ( ! empty( $analysis['priority_actions'] ) && is_array( $analysis['priority_actions'] ) ) {
				foreach ( array_slice( $analysis['priority_actions'], 0, 3 ) as $item ) {
					if ( is_array( $item ) && ! empty( $item['action'] ) ) {
						$actions[] = (string) $item['action'];
					}
				}
			}

			$entries[] = array(
				'post_id'              => $post_id,
				'title'                => (string) get_the_title( $post_id ),
				'edit_url'             => (string) ( get_edit_post_link( $post_id ) ?: '' ),
				'sfo_score'            => (int) ( $summary['sfo_score'] ?? 0 ),
				'priority_status'      => (string) ( $summary['priority_status'] ?? 'fix-this-first' ),
				'quick_win_count'      => (int) ( $summary['quick_win_count'] ?? 0 ),
				'opportunity_count'    => (int) ( $summary['opportunity_count'] ?? 0 ),
				'feature_ready_count'  => count( array_filter( $feature ) ),
				'answer_ready'         => ! empty( $summary['answer_ready'] ),
				'schema_attention'     => ! empty( $summary['schema_attention'] ),
				'snippet_restrictive'  => ! empty( $summary['snippet_restrictive'] ),
				'trend'                => (string) ( $trend['state'] ?? 'new' ),
				'sfo_score_delta'      => (int) ( $trend['sfo_score_delta'] ?? 0 ),
				'priority_actions'     => $actions,
			);
		}

		return array(
			'entries' => $entries,
			'rollup'  => self::build_rollup( $entries ),
		);
	}

	public static function history( int $post_id, int $limit = 6 ): array {
		$key   = 'post_' . absint( $post_id );
		$store = get_option( self::HISTORY_OPTION, array() );
		$store = is_array( $store ) ? $store : array();
		$rows  = isset( $store[ $key ] ) && is_array( $store[ $key ] ) ? $store[ $key ] : array();
		return array_slice( $rows, 0, max( 1, $limit ) );
	}

	private static function build_opportunities( string $effective_title, string $effective_desc, int $title_length, int $desc_length, bool $indexable, bool $answer_ready, bool $snippet_restrictive, bool $rich_ready, bool $social_ready, array $feature_readiness, array $canonical, array $geo, array $aeo ): array {
		$recs = array();

		if ( '' === $effective_title ) {
			$recs[] = self::opportunity(
				'title_missing',
				'important',
				__( 'Search title is missing', 'open-growth-seo' ),
				__( 'Search features depend on a clear title to frame page intent and improve snippet quality.', 'open-growth-seo' ),
				__( 'Add a specific SEO title for this page.', 'open-growth-seo' ),
				true
			);
		} elseif ( $title_length < 35 || $title_length > 65 ) {
			$recs[] = self::opportunity(
				'title_length',
				'minor',
				__( 'Search title length needs work', 'open-growth-seo' ),
				__( 'Title length is more likely to truncate or underperform in search feature presentation.', 'open-growth-seo' ),
				__( 'Keep the SEO title concise and specific, ideally within a strong snippet-friendly range.', 'open-growth-seo' ),
				true
			);
		}

		if ( '' === $effective_desc ) {
			$recs[] = self::opportunity(
				'description_missing',
				'important',
				__( 'Meta description is missing', 'open-growth-seo' ),
				__( 'Missing descriptions reduce control over snippet framing and page-summary quality.', 'open-growth-seo' ),
				__( 'Add a useful meta description that explains the outcome and scope of the page.', 'open-growth-seo' ),
				true
			);
		} elseif ( $desc_length < 70 || $desc_length > 170 ) {
			$recs[] = self::opportunity(
				'description_length',
				'minor',
				__( 'Meta description length needs work', 'open-growth-seo' ),
				__( 'Description length is more likely to truncate or feel too thin in search results.', 'open-growth-seo' ),
				__( 'Adjust the meta description so it is concise, descriptive, and snippet-friendly.', 'open-growth-seo' ),
				true
			);
		}

		if ( ! $indexable ) {
			$recs[] = self::opportunity(
				'indexability_blocked',
				'important',
				__( 'Page is not indexable', 'open-growth-seo' ),
				__( 'Search feature optimization has limited value when the page is blocked from indexability.', 'open-growth-seo' ),
				__( 'Review robots and indexability settings before spending effort on search feature optimization.', 'open-growth-seo' ),
				false
			);
		}

		if ( ! empty( $canonical['conflicts'] ) ) {
			$recs[] = self::opportunity(
				'canonical_conflict',
				'important',
				__( 'Canonical behavior needs attention', 'open-growth-seo' ),
				__( 'Canonical conflicts can suppress or dilute the page that search features should represent.', 'open-growth-seo' ),
				__( 'Resolve canonical conflicts so the intended page is eligible to represent this topic in search features.', 'open-growth-seo' ),
				false
			);
		}

		if ( ! $answer_ready ) {
			$recs[] = self::opportunity(
				'answer_ready_missing',
				'important',
				__( 'Answer-first structure is weak', 'open-growth-seo' ),
				__( 'Featured snippet and AI overview readiness is weaker when the page does not answer early and clearly.', 'open-growth-seo' ),
				__( 'Add a direct answer near the top of the page and support it with extractable structure.', 'open-growth-seo' ),
				false
			);
		}

		if ( ! empty( $snippet_restrictive ) ) {
			$recs[] = self::opportunity(
				'snippet_restrictive',
				'important',
				__( 'Snippet controls are restrictive', 'open-growth-seo' ),
				__( 'Overly restrictive snippet settings can block or weaken search feature participation.', 'open-growth-seo' ),
				__( 'Review nosnippet and snippet length controls so this page can participate where intended.', 'open-growth-seo' ),
				false
			);
		}

		if ( ! $rich_ready ) {
			$schema_message = isset( $geo['signals']['schema_runtime']['message'] ) ? (string) $geo['signals']['schema_runtime']['message'] : '';
			$recs[] = self::opportunity(
				'rich_result_readiness',
				'important',
				__( 'Rich result readiness is weak', 'open-growth-seo' ),
				__( 'Structured data runtime issues or missing schema nodes reduce eligibility for richer search features.', 'open-growth-seo' ),
				'' !== $schema_message
					? sprintf(
						/* translators: %s: schema runtime message */
						__( 'Inspect Schema runtime for this page and resolve the active issue: %s', 'open-growth-seo' ),
						$schema_message
					)
					: __( 'Inspect Schema runtime for this page and resolve missing or conflicting structured data.', 'open-growth-seo' ),
				false
			);
		}

		if ( ! $social_ready ) {
			$recs[] = self::opportunity(
				'social_preview_thin',
				'minor',
				__( 'Social preview data is thin', 'open-growth-seo' ),
				__( 'Shared previews are part of how content is discovered and interpreted outside pure search results.', 'open-growth-seo' ),
				__( 'Add a focused social title, description, or image for cleaner preview presentation.', 'open-growth-seo' ),
				true
			);
		}

		if ( empty( $feature_readiness['search_snippet'] ) && ! empty( $feature_readiness['featured_answer'] ) ) {
			$recs[] = self::opportunity(
				'search_snippet_incomplete',
				'minor',
				__( 'Snippet framing is weaker than answer quality', 'open-growth-seo' ),
				__( 'The page answers well, but the search-facing title or description still under-supports search feature presentation.', 'open-growth-seo' ),
				__( 'Tighten title and meta description so search-facing framing matches the strength of the answer content.', 'open-growth-seo' ),
				true
			);
		}

		if ( ! empty( $geo['summary']['bot_exposure'] ) && 'restricted' === (string) $geo['summary']['bot_exposure'] ) {
			$recs[] = self::opportunity(
				'bot_exposure_restricted',
				'minor',
				__( 'Relevant bot exposure is restricted', 'open-growth-seo' ),
				__( 'Restrictive bot policies can reduce the visibility of otherwise strong pages in AI-oriented surfaces.', 'open-growth-seo' ),
				__( 'Review bot access policy if this page should participate in GEO-oriented discovery.', 'open-growth-seo' ),
				false
			);
		}

		if ( (int) ( $aeo['summary']['internal_links'] ?? 0 ) < 2 ) {
			$recs[] = self::opportunity(
				'internal_support_thin',
				'minor',
				__( 'Internal support for search features is thin', 'open-growth-seo' ),
				__( 'Relevant supporting internal links help reinforce topic depth and discoverability.', 'open-growth-seo' ),
				__( 'Add a few high-quality internal links to supporting definitions, comparisons, and deeper guides.', 'open-growth-seo' ),
				true
			);
		}

		usort(
			$recs,
			static function ( array $left, array $right ): int {
				$weights = array(
					'important' => 3,
					'minor'     => 2,
				);
				$compare = ( $weights[ $right['severity'] ?? 'minor' ] ?? 0 ) <=> ( $weights[ $left['severity'] ?? 'minor' ] ?? 0 );
				if ( 0 !== $compare ) {
					return $compare;
				}
				return ( (int) ( $right['quick_win'] ?? false ) ) <=> ( (int) ( $left['quick_win'] ?? false ) );
			}
		);

		return array_values( $recs );
	}

	private static function record_snapshot( int $post_id, array $analysis ): array {
		$key      = 'post_' . absint( $post_id );
		$store    = get_option( self::HISTORY_OPTION, array() );
		$store    = is_array( $store ) ? $store : array();
		$history  = isset( $store[ $key ] ) && is_array( $store[ $key ] ) ? $store[ $key ] : array();
		$current  = self::build_snapshot( $analysis );
		$previous = isset( $history[0] ) && is_array( $history[0] ) ? $history[0] : array();

		if ( self::is_duplicate_snapshot( $current, $previous ) ) {
			$history[0] = array_merge( $previous, array( 'captured_at' => time() ) );
			$current    = $history[0];
		} else {
			array_unshift( $history, $current );
			$history = array_slice( $history, 0, self::MAX_HISTORY );
		}

		$store[ $key ] = $history;
		$store         = self::trim_store( $store );
		update_option( self::HISTORY_OPTION, $store, false );

		return array(
			'history' => $history,
			'diff'    => self::diff_snapshots( $current, $previous ),
			'trend'   => self::build_trend( $current, $previous ),
		);
	}

	private static function build_snapshot( array $analysis ): array {
		$summary = isset( $analysis['summary'] ) && is_array( $analysis['summary'] ) ? $analysis['summary'] : array();

		return array(
			'captured_at'           => time(),
			'sfo_score'             => (int) ( $summary['sfo_score'] ?? 0 ),
			'priority_status'       => (string) ( $summary['priority_status'] ?? 'fix-this-first' ),
			'answer_ready'          => ! empty( $summary['answer_ready'] ),
			'schema_attention'      => ! empty( $summary['schema_attention'] ),
			'snippet_restrictive'   => ! empty( $summary['snippet_restrictive'] ),
			'quick_win_count'       => (int) ( $summary['quick_win_count'] ?? 0 ),
			'opportunity_count'     => (int) ( $summary['opportunity_count'] ?? 0 ),
		);
	}

	private static function is_duplicate_snapshot( array $current, array $previous ): bool {
		if ( empty( $previous ) ) {
			return false;
		}

		$left  = $current;
		$right = $previous;
		unset( $left['captured_at'], $right['captured_at'] );

		return wp_json_encode( $left ) === wp_json_encode( $right );
	}

	private static function diff_snapshots( array $current, array $previous ): array {
		if ( empty( $previous ) ) {
			return array(
				'message' => __( 'No previous SFO snapshot is available for this page yet.', 'open-growth-seo' ),
			);
		}

		return array(
			'sfo_score_delta'        => (int) ( $current['sfo_score'] ?? 0 ) - (int) ( $previous['sfo_score'] ?? 0 ),
			'opportunity_count_delta'=> (int) ( $current['opportunity_count'] ?? 0 ) - (int) ( $previous['opportunity_count'] ?? 0 ),
			'quick_win_count_delta'  => (int) ( $current['quick_win_count'] ?? 0 ) - (int) ( $previous['quick_win_count'] ?? 0 ),
			'priority_changed'       => (string) ( $current['priority_status'] ?? '' ) !== (string) ( $previous['priority_status'] ?? '' ),
		);
	}

	private static function build_trend( array $current, array $previous ): array {
		if ( empty( $previous ) ) {
			return array(
				'state'   => 'new',
				'message' => __( 'This is the first SFO snapshot stored for this page.', 'open-growth-seo' ),
			);
		}

		$score_delta = (int) ( $current['sfo_score'] ?? 0 ) - (int) ( $previous['sfo_score'] ?? 0 );
		$opp_delta   = (int) ( $current['opportunity_count'] ?? 0 ) - (int) ( $previous['opportunity_count'] ?? 0 );
		$state       = 'stable';
		if ( $score_delta >= 5 && $opp_delta <= 0 ) {
			$state = 'improving';
		} elseif ( $score_delta <= -5 || $opp_delta >= 2 ) {
			$state = 'declining';
		}

		return array(
			'state'                   => $state,
			'sfo_score_delta'         => $score_delta,
			'opportunity_count_delta' => $opp_delta,
			'message'                 => 'improving' === $state
				? __( 'SFO readiness improved compared with the previous snapshot.', 'open-growth-seo' )
				: ( 'declining' === $state
					? __( 'SFO readiness declined compared with the previous snapshot.', 'open-growth-seo' )
					: __( 'SFO readiness is broadly stable compared with the previous snapshot.', 'open-growth-seo' ) ),
		);
	}

	private static function build_rollup( array $entries ): array {
		$total       = count( $entries );
		$score_total = 0;
		$priority    = array(
			'strong'         => 0,
			'needs-work'     => 0,
			'fix-this-first' => 0,
		);
		$trend       = array(
			'new'       => 0,
			'improving' => 0,
			'stable'    => 0,
			'declining' => 0,
		);
		$queue       = array();
		$schema_attention = 0;

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$score_total += (int) ( $entry['sfo_score'] ?? 0 );
			$current_priority = (string) ( $entry['priority_status'] ?? 'needs-work' );
			if ( isset( $priority[ $current_priority ] ) ) {
				$priority[ $current_priority ]++;
			}
			$current_trend = (string) ( $entry['trend'] ?? 'new' );
			if ( isset( $trend[ $current_trend ] ) ) {
				$trend[ $current_trend ]++;
			}
			if ( ! empty( $entry['schema_attention'] ) ) {
				$schema_attention++;
			}
			if ( ! empty( $entry['priority_actions'] ) && is_array( $entry['priority_actions'] ) ) {
				foreach ( $entry['priority_actions'] as $action ) {
					$action = trim( (string) $action );
					if ( '' === $action ) {
						continue;
					}
					if ( ! isset( $queue[ $action ] ) ) {
						$queue[ $action ] = 0;
					}
					$queue[ $action ]++;
				}
			}
		}

		arsort( $queue );

		return array(
			'total_pages'        => $total,
			'average_sfo_score'  => $total > 0 ? (int) round( $score_total / $total ) : 0,
			'priority_mix'       => $priority,
			'trend_mix'          => $trend,
			'schema_attention'   => $schema_attention,
			'remediation_queue'  => array_slice( $queue, 0, 5, true ),
		);
	}

	private static function trim_store( array $store ): array {
		if ( count( $store ) <= self::MAX_TRACKED_POSTS ) {
			return $store;
		}

		uasort(
			$store,
			static function ( $left, $right ): int {
				$left_time  = ( is_array( $left ) && ! empty( $left[0]['captured_at'] ) ) ? (int) $left[0]['captured_at'] : 0;
				$right_time = ( is_array( $right ) && ! empty( $right[0]['captured_at'] ) ) ? (int) $right[0]['captured_at'] : 0;
				return $right_time <=> $left_time;
			}
		);

		return array_slice( $store, 0, self::MAX_TRACKED_POSTS, true );
	}

	private static function should_record_history(): bool {
		if ( isset( $GLOBALS['ogs_test_options'] ) && is_array( $GLOBALS['ogs_test_options'] ) && empty( $GLOBALS['ogs_test_enable_sfo_history'] ) ) {
			return false;
		}

		return true;
	}

	private static function sfo_score( array $feature_readiness, array $opportunities ): int {
		$score = 0;
		foreach ( $feature_readiness as $ready ) {
			if ( ! empty( $ready ) ) {
				$score += 18;
			}
		}
		$important = count(
			array_filter(
				$opportunities,
				static fn( $row ): bool => is_array( $row ) && 'important' === (string) ( $row['severity'] ?? '' )
			)
		);
		$minor = count(
			array_filter(
				$opportunities,
				static fn( $row ): bool => is_array( $row ) && 'minor' === (string) ( $row['severity'] ?? '' )
			)
		);
		$score -= ( $important * 10 ) + ( $minor * 4 );
		return max( 0, min( 100, $score ) );
	}

	private static function priority_status( int $score, array $opportunities ): string {
		$important = count(
			array_filter(
				$opportunities,
				static fn( $row ): bool => is_array( $row ) && 'important' === (string) ( $row['severity'] ?? '' )
			)
		);
		if ( $score >= 75 && 0 === $important ) {
			return 'strong';
		}
		if ( $score >= 50 && $important <= 1 ) {
			return 'needs-work';
		}
		return 'fix-this-first';
	}

	private static function fallback_description( string $plain ): string {
		$plain = trim( $plain );
		if ( '' === $plain ) {
			return '';
		}
		if ( function_exists( 'wp_trim_words' ) ) {
			return (string) wp_trim_words( $plain, 26, '' );
		}
		$words = preg_split( '/\s+/', $plain ) ?: array();
		return trim( implode( ' ', array_slice( $words, 0, 26 ) ) );
	}

	private static function string_length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
	}

	private static function opportunity( string $code, string $severity, string $title, string $why, string $action, bool $quick_win ): array {
		return array(
			'code'      => sanitize_key( $code ),
			'severity'  => in_array( $severity, array( 'important', 'minor' ), true ) ? $severity : 'minor',
			'title'     => $title,
			'why'       => $why,
			'action'    => $action,
			'quick_win' => $quick_win,
		);
	}

	private static function empty_analysis(): array {
		return array(
			'summary' => array(
				'sfo_score'            => 0,
				'priority_status'      => 'fix-this-first',
				'title_length'         => 0,
				'description_length'   => 0,
				'indexable'            => false,
				'canonical_consistent' => false,
				'schema_nodes'         => 0,
				'schema_attention'     => false,
				'snippet_restrictive'  => false,
				'answer_ready'         => false,
				'opportunity_count'    => 0,
				'quick_win_count'      => 0,
			),
			'feature_readiness' => array(
				'search_snippet'  => false,
				'featured_answer' => false,
				'rich_result'     => false,
				'ai_overview'     => false,
				'social_preview'  => false,
			),
			'opportunities'    => array(),
			'priority_actions' => array(),
			'recommendations'  => array(),
			'history'          => array(),
			'diff'             => array(),
			'trend'            => array(
				'state'   => 'new',
				'message' => __( 'No SFO history has been captured for this page yet.', 'open-growth-seo' ),
			),
		);
	}
}
