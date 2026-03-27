<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\GEO;

use OpenGrowthSolutions\OpenGrowthSEO\AEO\Analyzer as AeoAnalyzer;
use OpenGrowthSolutions\OpenGrowthSEO\Schema\SchemaManager;
use OpenGrowthSolutions\OpenGrowthSEO\Support\Settings;

defined( 'ABSPATH' ) || exit;

class BotControls {
	private const MIN_TEXT_WORDS = 180;
	private const MIN_STRUCTURED_TEXT_WORDS = 35;
	private const FRESH_DAYS_GOOD = 365;
	private const HISTORY_OPTION = 'ogs_seo_geo_history';
	private const MAX_HISTORY    = 6;

	public function register(): void {
		add_filter( 'ogs_seo_audit_checks', array( $this, 'register_checks' ) );
	}

	public function register_checks( array $checks ): array {
		if ( ! (bool) (int) Settings::get( 'geo_enabled', 1 ) ) {
			return $checks;
		}
		$checks['geo_bots']                  = array( $this, 'check_bots' );
		$checks['geo_text_visibility']       = array( $this, 'check_recent_text_visibility' );
		$checks['geo_schema_text_alignment'] = array( $this, 'check_recent_schema_alignment' );
		$checks['geo_citability']            = array( $this, 'check_recent_citability' );
		$checks['geo_discoverability']       = array( $this, 'check_recent_discoverability' );
		$checks['geo_semantic_clarity']      = array( $this, 'check_recent_semantic_clarity' );
		$checks['geo_freshness']             = array( $this, 'check_recent_freshness' );
		$checks['geo_snippet_participation'] = array( $this, 'check_recent_snippet_participation' );
		return $checks;
	}

	public function check_bots(): array {
		$policies = self::bot_policy_snapshot();
		if ( ! $policies['geo_enabled'] ) {
			return array();
		}
		if ( ! $policies['search_open'] && ! $policies['gptbot_open'] && ! $policies['oai_searchbot_open'] ) {
			return array(
				'severity'       => 'important',
				'title'          => __( 'Crawlers are broadly blocked, reducing GEO discoverability', 'open-growth-seo' ),
				'recommendation' => __( 'Allow at least one relevant crawler group in robots.txt if generative citation visibility is desired.', 'open-growth-seo' ),
			);
		}
		if ( ! $policies['gptbot_open'] && ! $policies['oai_searchbot_open'] ) {
			return array(
				'severity'       => 'minor',
				'title'          => __( 'Both GPTBot and OAI-SearchBot are blocked', 'open-growth-seo' ),
				'recommendation' => __( 'Keep this only if intentional. Otherwise allow one or both to improve discoverability for generative engines.', 'open-growth-seo' ),
			);
		}
		return array();
	}

	public function check_recent_text_visibility(): array {
		$post_id = $this->latest_public_post_id();
		if ( $post_id <= 0 ) {
			return array();
		}
		$analysis = self::analyze_post( $post_id );
		if ( ! empty( $analysis['summary']['critical_text_visible'] ) ) {
			return array();
		}
		return array(
			'severity'       => 'important',
			'title'          => __( 'Critical information appears weakly represented in text', 'open-growth-seo' ),
			'recommendation' => __( 'Convert key visual/video-only information into concise, visible text sections near the top of the page.', 'open-growth-seo' ),
		);
	}

	public function check_recent_schema_alignment(): array {
		$post_id = $this->latest_public_post_id();
		if ( $post_id <= 0 ) {
			return array();
		}
		$analysis = self::analyze_post( $post_id );
		if ( empty( $analysis['summary']['schema_text_mismatch'] ) ) {
			return array();
		}
		return array(
			'severity'       => 'important',
			'title'          => __( 'Schema and visible text may be inconsistent', 'open-growth-seo' ),
			'recommendation' => __( 'Adjust schema type or add matching visible content so structured data reflects what users can read.', 'open-growth-seo' ),
		);
	}

	public function check_recent_citability(): array {
		$post_id = $this->latest_public_post_id();
		if ( $post_id <= 0 ) {
			return array();
		}
		$analysis = self::analyze_post( $post_id );
		$score    = isset( $analysis['summary']['citability_score'] ) ? (int) $analysis['summary']['citability_score'] : 0;
		if ( $score >= 2 ) {
			return array();
		}
		return array(
			'severity'       => 'minor',
			'title'          => __( 'Citability signals are weak in recent content', 'open-growth-seo' ),
			'recommendation' => __( 'Add direct answer text, measurable facts, and explicit attribute-value statements to improve citation quality.', 'open-growth-seo' ),
		);
	}

	public function check_recent_discoverability(): array {
		$post_id = $this->latest_public_post_id();
		if ( $post_id <= 0 ) {
			return array();
		}
		$analysis = self::analyze_post( $post_id );
		$count    = isset( $analysis['signals']['internal_links']['count'] ) ? (int) $analysis['signals']['internal_links']['count'] : 0;
		if ( $count >= 2 ) {
			return array();
		}
		return array(
			'severity'       => 'minor',
			'title'          => __( 'Internal discoverability signals are limited', 'open-growth-seo' ),
			'recommendation' => __( 'Add 2-4 internal thematic links to definitions, comparisons, and implementation pages.', 'open-growth-seo' ),
		);
	}

	public function check_recent_semantic_clarity(): array {
		$post_id = $this->latest_public_post_id();
		if ( $post_id <= 0 ) {
			return array();
		}
		$analysis = self::analyze_post( $post_id );
		if ( ! empty( $analysis['summary']['semantic_clear'] ) ) {
			return array();
		}
		return array(
			'severity'       => 'minor',
			'title'          => __( 'Semantic clarity signals are weak', 'open-growth-seo' ),
			'recommendation' => __( 'Use clear headings, definitions, and explicit scope/requirements to reduce ambiguity.', 'open-growth-seo' ),
		);
	}

	public function check_recent_freshness(): array {
		$post_id = $this->latest_public_post_id();
		if ( $post_id <= 0 ) {
			return array();
		}
		$analysis = self::analyze_post( $post_id );
		$days     = isset( $analysis['signals']['freshness']['days_since_modified'] ) ? (int) $analysis['signals']['freshness']['days_since_modified'] : 0;
		if ( $days <= self::FRESH_DAYS_GOOD ) {
			return array();
		}
		return array(
			'severity'       => 'minor',
			'title'          => __( 'Recent sampled content may be stale', 'open-growth-seo' ),
			'recommendation' => __( 'Refresh important pages with current facts, dates, and limitations to preserve contextual reliability.', 'open-growth-seo' ),
		);
	}

	public function check_recent_snippet_participation(): array {
		$post_id = $this->latest_public_post_id();
		if ( $post_id <= 0 ) {
			return array();
		}
		$analysis = self::analyze_post( $post_id );
		if ( empty( $analysis['summary']['snippet_participation_restrictive'] ) ) {
			return array();
		}
		return array(
			'severity'       => 'minor',
			'title'          => __( 'Snippet participation appears highly restricted', 'open-growth-seo' ),
			'recommendation' => __( 'Review nosnippet, max-snippet, and data-nosnippet scope so valuable text can still be quoted where desired.', 'open-growth-seo' ),
		);
	}

	public static function analyze_post( int $post_id, bool $record_history = true ): array {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 ) {
			return self::empty_analysis();
		}
		$content = (string) get_post_field( 'post_content', $post_id );
		$title   = (string) get_the_title( $post_id );
		$url     = (string) get_permalink( $post_id );
		$meta_schema = (string) get_post_meta( $post_id, 'ogs_seo_schema_type', true );
		$data_nosnippet = (string) get_post_meta( $post_id, 'ogs_seo_data_nosnippet_ids', true );
		$modified = (string) get_post_modified_time( 'Y-m-d H:i:s', true, $post_id );
		$snippet_controls = self::resolve_snippet_controls_for_post( $post_id );

		$analysis = self::analyze_content(
			$content,
			$title,
			$url,
			array(
				'schema_type'        => $meta_schema,
				'schema_runtime'     => self::schema_runtime_for_post( $post_id ),
				'data_nosnippet_ids' => $data_nosnippet,
				'modified_gmt'       => $modified,
				'snippet_controls'   => $snippet_controls,
			)
		);
		if ( $record_history ) {
			$history_bundle      = self::record_snapshot( $post_id, $analysis );
			$analysis['history'] = isset( $history_bundle['history'] ) && is_array( $history_bundle['history'] ) ? $history_bundle['history'] : array();
			$analysis['diff']    = isset( $history_bundle['diff'] ) && is_array( $history_bundle['diff'] ) ? $history_bundle['diff'] : array();
			$analysis['trend']   = isset( $history_bundle['trend'] ) && is_array( $history_bundle['trend'] ) ? $history_bundle['trend'] : array();
		} else {
			$analysis['history'] = array();
			$analysis['diff']    = array(
				'message' => __( 'GEO history recording was skipped for this derived analysis.', 'open-growth-seo' ),
			);
			$analysis['trend']   = array(
				'state'   => 'derived',
				'message' => __( 'GEO trend tracking is not recorded for derived analyses.', 'open-growth-seo' ),
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
			$actions  = array();
			if ( ! empty( $analysis['priority_actions'] ) && is_array( $analysis['priority_actions'] ) ) {
				foreach ( array_slice( $analysis['priority_actions'], 0, 3 ) as $item ) {
					if ( is_array( $item ) && ! empty( $item['action'] ) ) {
						$actions[] = (string) $item['action'];
					}
				}
			}
			$entries[] = array(
				'post_id'            => $post_id,
				'title'              => (string) get_the_title( $post_id ),
				'edit_url'           => (string) ( get_edit_post_link( $post_id ) ?: '' ),
				'geo_score'          => (int) ( $summary['geo_score'] ?? 0 ),
				'priority_status'    => (string) ( $summary['priority_status'] ?? 'fix-this-first' ),
				'bot_exposure'       => (string) ( $summary['bot_exposure'] ?? 'restricted' ),
				'quick_win_count'    => (int) ( $summary['quick_win_count'] ?? 0 ),
				'opportunity_count'  => (int) ( $summary['opportunity_count'] ?? 0 ),
				'schema_attention'   => ! empty( $summary['schema_runtime_attention'] ),
				'trend'              => (string) ( $trend['state'] ?? 'new' ),
				'geo_score_delta'    => (int) ( $trend['geo_score_delta'] ?? 0 ),
				'priority_actions'   => $actions,
			);
		}

		return array(
			'entries' => $entries,
			'rollup'  => self::build_rollup( $entries ),
		);
	}

	public static function analyze_content( string $content, string $title = '', string $url = '', array $context = array() ): array {
		$plain      = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $content ) ) );
		$word_count = str_word_count( $plain );
		$images     = (int) preg_match_all( '/<img\b/i', $content );
		$videos     = (int) preg_match_all( '/<video\b|youtube\.com|youtu\.be|vimeo\.com/i', $content );
		$tables     = (int) preg_match_all( '/<table\b/i', $content );
		$headings   = (int) preg_match_all( '/<h[2-4]\b/i', $content );
		$defs       = (int) preg_match_all( '/\b(is|means|defined as|refers to)\b/i', $plain );
		$attributes = (int) preg_match_all( '/\b([A-Za-z][A-Za-z\s]{2,30}):\s*([^\.;\n]{2,80})/u', $plain );
		$facts      = (int) preg_match_all( '/\b\d+[\d\.,]*\s?(%|ms|s|sec|min|minutes|hours|days|usd|eur|\$)\b/i', $plain );
		$entities   = self::extract_entities( $plain );
		$internal   = self::internal_link_stats( $content, $url );

		$aeo        = AeoAnalyzer::analyze_content( $content, $title, $url );
		$answer     = ! empty( $aeo['summary']['answer_first'] );
		$textual    = (
			$word_count >= self::MIN_TEXT_WORDS && ( $images + $videos <= 2 || $word_count >= 420 )
		) || (
			$word_count >= self::MIN_STRUCTURED_TEXT_WORDS &&
			$answer &&
			$headings >= 2 &&
			( $internal['count'] >= 2 || $attributes > 0 )
		);
		$semantic   = $headings >= 2 && ( $defs >= 1 || $attributes >= 1 );
		$citability = ( $answer ? 1 : 0 ) + ( $facts > 0 ? 1 : 0 ) + ( $attributes > 0 ? 1 : 0 );

		$schema_type = sanitize_text_field( (string) ( $context['schema_type'] ?? '' ) );
		$schema_mismatch = self::schema_mismatch( $schema_type, $content, $plain );

		$modified_gmt = (string) ( $context['modified_gmt'] ?? '' );
		$fresh_days   = self::days_since_modified( $modified_gmt );
		$freshness    = array(
			'days_since_modified' => $fresh_days,
			'fresh'               => $fresh_days >= 0 && $fresh_days <= self::FRESH_DAYS_GOOD,
		);

		$data_nosnippet_raw = (string) ( $context['data_nosnippet_ids'] ?? '' );
		$data_nosnippet_ids = self::split_lines( $data_nosnippet_raw );
		$snippet_controls   = self::normalize_snippet_controls( isset( $context['snippet_controls'] ) && is_array( $context['snippet_controls'] ) ? $context['snippet_controls'] : array() );
		$bot_policies       = self::bot_policy_snapshot();
		$snippet_signals    = self::snippet_participation_signals( $snippet_controls, $data_nosnippet_ids, $bot_policies );
		$bot_exposure       = self::bot_exposure_status( $bot_policies );
		$schema_runtime     = isset( $context['schema_runtime'] ) && is_array( $context['schema_runtime'] ) ? $context['schema_runtime'] : array();

		$opportunities = self::build_opportunities(
			$textual,
			$schema_mismatch,
			$citability,
			$semantic,
			$entities,
			$internal,
			$freshness,
			$data_nosnippet_ids,
			$tables,
			$snippet_signals,
			$bot_exposure,
			$schema_runtime
		);
		$recommendations = array_values(
			array_map(
				static function ( array $row ): string {
					return (string) ( $row['action'] ?? '' );
				},
				array_filter(
					$opportunities,
					static fn( $row ): bool => is_array( $row ) && ! empty( $row['action'] )
				)
			)
		);
		$score = self::geo_score( $textual, $schema_mismatch, $citability, $semantic, $entities, $internal, $freshness, $snippet_signals, $bot_exposure );
		$priority = self::priority_status( $score, $opportunities );

		return array(
			'summary' => array(
				'critical_text_visible' => $textual,
				'schema_text_mismatch'  => $schema_mismatch,
				'citability_score'      => $citability,
				'semantic_clear'        => $semantic,
				'discoverable_internal' => ( $internal['count'] >= 2 ),
				'fresh'                 => $freshness['fresh'],
				'entities_count'        => count( $entities ),
				'snippet_participation_restrictive' => ! empty( $snippet_signals['restrictive'] ),
				'recommendations_count' => count( $recommendations ),
				'geo_score'             => $score,
				'priority_status'       => $priority,
				'opportunity_count'     => count( $opportunities ),
				'quick_win_count'       => count(
					array_filter(
						$opportunities,
						static fn( $row ): bool => is_array( $row ) && ! empty( $row['quick_win'] )
					)
				),
				'bot_exposure'          => $bot_exposure,
				'schema_runtime_attention' => ! empty( $schema_runtime['needs_attention'] ),
			),
			'signals' => array(
				'word_count'      => $word_count,
				'media'           => array(
					'images' => $images,
					'videos' => $videos,
					'tables' => $tables,
				),
				'structures'      => array(
					'headings'    => $headings,
					'definitions' => $defs,
					'attributes'  => $attributes,
				),
				'citability'      => array(
					'answer_first' => $answer,
					'facts'        => $facts,
					'attributes'   => $attributes,
				),
				'entities'        => $entities,
				'freshness'       => $freshness,
				'internal_links'  => $internal,
				'preview_control' => array(
					'data_nosnippet_ids' => $data_nosnippet_ids,
					'snippet_controls'   => $snippet_controls,
					'restrictive'        => ! empty( $snippet_signals['restrictive'] ),
				),
				'snippet_participation' => $snippet_signals,
				'bots'            => $bot_policies,
				'schema_runtime'  => $schema_runtime,
			),
			'opportunities' => $opportunities,
			'priority_actions' => array_slice( $opportunities, 0, 3 ),
			'recommendations' => $recommendations,
		);
	}

	public static function bot_policy_snapshot(): array {
		$global_policy = (string) Settings::get( 'robots_global_policy', 'allow' );
		$gptbot        = (string) Settings::get( 'bots_gptbot', 'allow' );
		$oai_searchbot = (string) Settings::get( 'bots_oai_searchbot', 'allow' );

		return array(
			'geo_enabled'        => (bool) (int) Settings::get( 'geo_enabled', 1 ),
			'global_policy'      => $global_policy,
			'gptbot'             => $gptbot,
			'oai_searchbot'      => $oai_searchbot,
			'search_open'        => 'allow' === $global_policy,
			'gptbot_open'        => 'allow' === $gptbot,
			'oai_searchbot_open' => 'allow' === $oai_searchbot,
		);
	}

	private function latest_public_post_id(): int {
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
				'numberposts'    => 1,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		return ! empty( $posts ) ? (int) $posts[0] : 0;
	}

	private static function extract_entities( string $text ): array {
		$entities = array();
		if ( preg_match_all( '/\b([A-Z][a-z]{2,}(?:\s+[A-Z][a-z]{2,}){0,3})\b/u', $text, $matches ) ) {
			foreach ( $matches[1] as $candidate ) {
				$candidate = trim( (string) $candidate );
				if ( strlen( $candidate ) < 4 ) {
					continue;
				}
				$entities[] = $candidate;
			}
		}
		$entities = array_values( array_unique( $entities ) );
		return array_slice( $entities, 0, 12 );
	}

	private static function internal_link_stats( string $content, string $url ): array {
		$site_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$current   = self::normalized_url( $url );
		$count     = 0;
		$targets   = array();
		if ( preg_match_all( '/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $content, $matches ) ) {
			foreach ( $matches[1] as $href ) {
				$href = esc_url_raw( (string) $href );
				if ( '' === $href ) {
					continue;
				}
				$host = wp_parse_url( $href, PHP_URL_HOST );
				if ( empty( $host ) || strtolower( (string) $host ) === strtolower( (string) $site_host ) ) {
					$normalized = self::normalized_url( $href );
					if ( '' !== $normalized && $normalized !== $current ) {
						++$count;
						$targets[] = $normalized;
					}
				}
			}
		}
		return array(
			'count'   => $count,
			'targets' => array_slice( array_values( array_unique( $targets ) ), 0, 12 ),
		);
	}

	private static function normalized_url( string $url ): string {
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
		if ( ! empty( $parts['path'] ) ) {
			$normalized .= rtrim( (string) $parts['path'], '/' );
		}
		if ( ! empty( $parts['query'] ) ) {
			$normalized .= '?' . (string) $parts['query'];
		}
		return $normalized;
	}

	private static function schema_mismatch( string $schema_type, string $content, string $plain ): bool {
		if ( '' === $schema_type ) {
			return false;
		}
		switch ( $schema_type ) {
			case 'FAQPage':
				return preg_match_all( '/<h[2-4][^>]*>[^<]+\?<\/h[2-4]>/i', $content ) < 2;
			case 'QAPage':
				return ! preg_match( '/\bq[:\-].+\ba[:\-]/i', $plain );
			case 'Recipe':
				return ! preg_match( '/\b(ingredients|instructions|servings)\b/i', $plain );
			case 'JobPosting':
				return ! preg_match( '/\b(salary|employment|responsibilit|qualifications?)\b/i', $plain );
			case 'Event':
				return ! preg_match( '/\b(date|time|location|venue|ticket)\b/i', $plain );
			case 'Product':
				return ! preg_match( '/\b(price|sku|availability|specifications?)\b/i', $plain );
			default:
				return false;
		}
	}

	private static function days_since_modified( string $modified_gmt ): int {
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

	private static function split_lines( string $value ): array {
		$lines = preg_split( '/\r\n|\r|\n/', $value ) ?: array();
		$clean = array();
		foreach ( $lines as $line ) {
			$line = sanitize_html_class( trim( str_replace( '#', '', $line ) ) );
			if ( '' !== $line ) {
				$clean[] = $line;
			}
		}
		return array_values( array_unique( $clean ) );
	}

	private static function build_opportunities( bool $textual, bool $schema_mismatch, int $citability, bool $semantic, array $entities, array $internal, array $freshness, array $data_nosnippet_ids, int $tables, array $snippet_signals, string $bot_exposure, array $schema_runtime ): array {
		$recs = array();
		$schema_type = sanitize_text_field( (string) ( $schema_runtime['schema_type'] ?? '' ) );
		if ( ! $textual ) {
			$recs[] = self::opportunity(
				'critical_text_not_visible',
				'important',
				__( 'Critical information is not visible enough in text', 'open-growth-seo' ),
				__( 'Important meaning appears too dependent on media or thin supporting text, which weakens citation and extraction reliability.', 'open-growth-seo' ),
				__( 'Move critical information from images or videos into visible text near the beginning of the page.', 'open-growth-seo' ),
				false
			);
		}
		if ( $schema_mismatch ) {
			$schema_guidance = self::schema_type_guidance( $schema_type );
			$recs[] = self::opportunity(
				'schema_text_mismatch',
				'important',
				__( 'Schema type and visible text may not match', 'open-growth-seo' ),
				__( 'Structured data appears to describe content that is not clearly represented on the page.', 'open-growth-seo' ),
				'' !== $schema_guidance ? $schema_guidance : __( 'Align the schema type with visible content or add the missing visible sections required by that type.', 'open-growth-seo' ),
				false
			);
		}
		if ( ! empty( $schema_runtime['needs_attention'] ) ) {
			$schema_reason = isset( $schema_runtime['message'] ) ? (string) $schema_runtime['message'] : __( 'Schema runtime diagnostics detected issues for this page.', 'open-growth-seo' );
			$schema_guidance = self::schema_type_guidance( $schema_type );
			$recs[] = self::opportunity(
				'schema_runtime_attention',
				'important',
				__( 'Schema runtime needs attention', 'open-growth-seo' ),
				__( 'Current structured data output or validation state may be reducing GEO clarity for this page.', 'open-growth-seo' ),
				sprintf(
					/* translators: 1: schema runtime summary, 2: schema-specific guidance */
					__( 'Open Schema runtime inspection for this page and resolve the active issue: %1$s %2$s', 'open-growth-seo' ),
					$schema_reason,
					'' !== $schema_guidance ? $schema_guidance : ''
				),
				false
			);
		}
		if ( $citability < 2 ) {
			$recs[] = self::opportunity(
				'citability_weak',
				'important',
				__( 'Citability signals are weak', 'open-growth-seo' ),
				__( 'The page has too few direct answer, fact, or attribute signals to support reliable quoting and summarization.', 'open-growth-seo' ),
				__( 'Increase citability with direct answers, concrete metrics, and clear attribute-value statements.', 'open-growth-seo' ),
				false
			);
		}
		if ( ! $semantic ) {
			$recs[] = self::opportunity(
				'semantic_clarity_weak',
				'important',
				__( 'Semantic clarity is weak', 'open-growth-seo' ),
				__( 'The page does not clearly frame scope, definitions, or requirements, which increases ambiguity.', 'open-growth-seo' ),
				__( 'Improve semantic clarity using descriptive headings and explicit definition or scope blocks.', 'open-growth-seo' ),
				true
			);
		}
		if ( count( $entities ) < 2 ) {
			$recs[] = self::opportunity(
				'entity_clarity_thin',
				'minor',
				__( 'Entity naming is thin', 'open-growth-seo' ),
				__( 'Few named entities are present, which can reduce disambiguation and contextual clarity.', 'open-growth-seo' ),
				__( 'Name the key entities, products, systems, and actors explicitly to reduce ambiguity.', 'open-growth-seo' ),
				true
			);
		}
		if ( $internal['count'] < 2 ) {
			$recs[] = self::opportunity(
				'internal_discoverability_thin',
				'minor',
				__( 'Internal discoverability is thin', 'open-growth-seo' ),
				__( 'The page has limited internal pathing to related definitions, comparisons, or implementation content.', 'open-growth-seo' ),
				__( 'Add thematic internal links to related pages to improve discoverability and context.', 'open-growth-seo' ),
				true
			);
		}
		if ( ! empty( $freshness['days_since_modified'] ) && (int) $freshness['days_since_modified'] > self::FRESH_DAYS_GOOD ) {
			$recs[] = self::opportunity(
				'freshness_stale',
				'minor',
				__( 'Freshness signals are stale', 'open-growth-seo' ),
				__( 'The content appears old enough that contextual reliability may be reduced for time-sensitive topics.', 'open-growth-seo' ),
				__( 'Refresh this content with current data points and dates to improve freshness signals.', 'open-growth-seo' ),
				false
			);
		}
		if ( count( $data_nosnippet_ids ) > 4 ) {
			$recs[] = self::opportunity(
				'data_nosnippet_overuse',
				'minor',
				__( 'data-nosnippet scope may be too broad', 'open-growth-seo' ),
				__( 'Too many excluded fragments can reduce useful quoted or excerpted text.', 'open-growth-seo' ),
				__( 'Review data-nosnippet scope. Overuse can reduce useful excerpt visibility.', 'open-growth-seo' ),
				true
			);
		}
		if ( ! empty( $snippet_signals['nosnippet'] ) && ( ! empty( $snippet_signals['gptbot_open'] ) || ! empty( $snippet_signals['oai_searchbot_open'] ) ) ) {
			$recs[] = self::opportunity(
				'nosnippet_conflict',
				'important',
				__( 'Snippet participation is blocked while AI crawl is allowed', 'open-growth-seo' ),
				__( 'Crawlers may access the page, but nosnippet can suppress useful quoting and excerpt participation.', 'open-growth-seo' ),
				__( 'nosnippet is active while AI bot crawl is allowed. Keep this only if intentionally restrictive.', 'open-growth-seo' ),
				false
			);
		}
		if ( ! empty( $snippet_signals['max_snippet_restrictive'] ) ) {
			$recs[] = self::opportunity(
				'max_snippet_too_low',
				'minor',
				__( 'max-snippet is very low', 'open-growth-seo' ),
				__( 'Very low snippet limits can reduce richer excerpting and citation flexibility.', 'open-growth-seo' ),
				__( 'Increase max-snippet if you want richer excerpts and citations.', 'open-growth-seo' ),
				true
			);
		}
		if ( ! empty( $snippet_signals['max_image_none'] ) ) {
			$recs[] = self::opportunity(
				'max_image_none',
				'minor',
				__( 'Image previews are disabled', 'open-growth-seo' ),
				__( 'Image preview restrictions can reduce visual context in surfaces that support image-rich excerpts.', 'open-growth-seo' ),
				__( 'Allow larger image previews where visual context matters.', 'open-growth-seo' ),
				true
			);
		}
		if ( $tables < 1 ) {
			$recs[] = self::opportunity(
				'comparison_table_missing',
				'minor',
				__( 'Comparison-friendly table is missing', 'open-growth-seo' ),
				__( 'A concise table can improve scanning and quoting for pages that compare options, trade-offs, or constraints.', 'open-growth-seo' ),
				__( 'For complex comparisons, add a concise table with criteria, constraints, and trade-offs.', 'open-growth-seo' ),
				true
			);
		}
		if ( 'restricted' === $bot_exposure ) {
			$recs[] = self::opportunity(
				'bot_access_restricted',
				'important',
				__( 'Relevant bot access is restricted', 'open-growth-seo' ),
				__( 'Current bot policy limits crawl participation for GEO-oriented discovery.', 'open-growth-seo' ),
				__( 'Review GPTBot, OAI-SearchBot, and global crawl policy if GEO visibility is desired.', 'open-growth-seo' ),
				false
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
		$unique = array();
		foreach ( $recs as $rec ) {
			if ( ! is_array( $rec ) ) {
				continue;
			}
			$unique[ (string) ( $rec['code'] ?? md5( wp_json_encode( $rec ) ?: '' ) ) ] = $rec;
		}
		return array_values( $unique );
	}

	private static function bot_exposure_status( array $bot_policies ): string {
		if ( ! empty( $bot_policies['gptbot_open'] ) || ! empty( $bot_policies['oai_searchbot_open'] ) ) {
			return 'open';
		}
		if ( ! empty( $bot_policies['search_open'] ) ) {
			return 'search-only';
		}
		return 'restricted';
	}

	private static function schema_runtime_for_post( int $post_id ): array {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 || ! class_exists( SchemaManager::class ) ) {
			return array();
		}

		$inspect = ( new SchemaManager() )->inspect(
			array(
				'post_id' => $post_id,
			),
			false
		);
		$conflict_totals = isset( $inspect['conflict_totals'] ) && is_array( $inspect['conflict_totals'] ) ? $inspect['conflict_totals'] : array();
		$errors          = isset( $inspect['errors'] ) && is_array( $inspect['errors'] ) ? $inspect['errors'] : array();
		$warnings        = isset( $inspect['warnings'] ) && is_array( $inspect['warnings'] ) ? $inspect['warnings'] : array();
		$nodes_emitted   = isset( $inspect['summary']['nodes_emitted'] ) ? (int) $inspect['summary']['nodes_emitted'] : 0;

		$needs_attention = $nodes_emitted <= 0
			|| ! empty( $errors )
			|| ( (int) ( $conflict_totals['important'] ?? 0 ) > 0 )
			|| ( (int) ( $conflict_totals['minor'] ?? 0 ) > 0 );

		$message = '';
		if ( $nodes_emitted <= 0 ) {
			$message = __( 'No schema nodes were emitted for the current page context.', 'open-growth-seo' );
		} elseif ( ! empty( $errors ) ) {
			$message = sanitize_text_field( (string) $errors[0] );
		} elseif ( (int) ( $conflict_totals['important'] ?? 0 ) > 0 ) {
			$message = __( 'Important schema conflicts are active for this page.', 'open-growth-seo' );
		} elseif ( ! empty( $warnings ) ) {
			$message = sanitize_text_field( (string) $warnings[0] );
		}

		return array(
			'needs_attention' => $needs_attention,
			'nodes_emitted'   => $nodes_emitted,
			'conflict_totals' => $conflict_totals,
			'message'         => $message,
			'schema_type'     => (string) get_post_meta( $post_id, 'ogs_seo_schema_type', true ),
		);
	}

	private static function record_snapshot( int $post_id, array $analysis ): array {
		$key     = 'post_' . absint( $post_id );
		$store   = get_option( self::HISTORY_OPTION, array() );
		$store   = is_array( $store ) ? $store : array();
		$history = isset( $store[ $key ] ) && is_array( $store[ $key ] ) ? $store[ $key ] : array();
		$current = self::build_snapshot( $analysis );
		$previous = isset( $history[0] ) && is_array( $history[0] ) ? $history[0] : array();
		if ( self::is_duplicate_snapshot( $current, $previous ) ) {
			$history[0] = array_merge( $previous, array( 'captured_at' => time() ) );
			$current    = $history[0];
		} else {
			array_unshift( $history, $current );
			$history = array_slice( $history, 0, self::MAX_HISTORY );
		}
		$store[ $key ] = $history;
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
			'captured_at'                     => time(),
			'geo_score'                       => (int) ( $summary['geo_score'] ?? 0 ),
			'priority_status'                 => (string) ( $summary['priority_status'] ?? 'fix-this-first' ),
			'citability_score'                => (int) ( $summary['citability_score'] ?? 0 ),
			'critical_text_visible'           => ! empty( $summary['critical_text_visible'] ),
			'schema_text_mismatch'            => ! empty( $summary['schema_text_mismatch'] ),
			'snippet_participation_restrictive' => ! empty( $summary['snippet_participation_restrictive'] ),
			'bot_exposure'                    => (string) ( $summary['bot_exposure'] ?? 'restricted' ),
			'opportunity_count'               => (int) ( $summary['opportunity_count'] ?? 0 ),
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
				'message' => __( 'No previous GEO snapshot is available for this page yet.', 'open-growth-seo' ),
			);
		}
		return array(
			'geo_score_delta'         => (int) ( $current['geo_score'] ?? 0 ) - (int) ( $previous['geo_score'] ?? 0 ),
			'citability_score_delta'  => (int) ( $current['citability_score'] ?? 0 ) - (int) ( $previous['citability_score'] ?? 0 ),
			'opportunity_count_delta' => (int) ( $current['opportunity_count'] ?? 0 ) - (int) ( $previous['opportunity_count'] ?? 0 ),
			'priority_changed'        => (string) ( $current['priority_status'] ?? '' ) !== (string) ( $previous['priority_status'] ?? '' ),
		);
	}

	private static function build_trend( array $current, array $previous ): array {
		if ( empty( $previous ) ) {
			return array(
				'state'   => 'new',
				'message' => __( 'This is the first GEO snapshot stored for this page.', 'open-growth-seo' ),
			);
		}

		$score_delta = (int) ( $current['geo_score'] ?? 0 ) - (int) ( $previous['geo_score'] ?? 0 );
		$opp_delta   = (int) ( $current['opportunity_count'] ?? 0 ) - (int) ( $previous['opportunity_count'] ?? 0 );
		$state       = 'stable';
		if ( $score_delta >= 5 && $opp_delta <= 0 ) {
			$state = 'improving';
		} elseif ( $score_delta <= -5 || $opp_delta >= 2 ) {
			$state = 'declining';
		}

		return array(
			'state'                  => $state,
			'geo_score_delta'        => $score_delta,
			'opportunity_count_delta' => $opp_delta,
			'message'                => 'improving' === $state
				? __( 'GEO signals improved compared with the previous snapshot.', 'open-growth-seo' )
				: ( 'declining' === $state
					? __( 'GEO signals declined compared with the previous snapshot.', 'open-growth-seo' )
					: __( 'GEO signals are broadly stable compared with the previous snapshot.', 'open-growth-seo' ) ),
		);
	}

	public static function history( int $post_id, int $limit = 6 ): array {
		$key   = 'post_' . absint( $post_id );
		$store = get_option( self::HISTORY_OPTION, array() );
		$store = is_array( $store ) ? $store : array();
		$rows  = isset( $store[ $key ] ) && is_array( $store[ $key ] ) ? $store[ $key ] : array();
		return array_slice( $rows, 0, max( 1, $limit ) );
	}

	private static function build_rollup( array $entries ): array {
		$total = count( $entries );
		$score_total = 0;
		$priority = array(
			'strong'         => 0,
			'needs-work'     => 0,
			'fix-this-first' => 0,
		);
		$trend = array(
			'new'       => 0,
			'improving' => 0,
			'stable'    => 0,
			'declining' => 0,
		);
		$exposure = array(
			'open'        => 0,
			'search-only' => 0,
			'restricted'  => 0,
		);
		$schema_attention = 0;
		$clusters = array();

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$score_total += (int) ( $entry['geo_score'] ?? 0 );
			$priority_key = (string) ( $entry['priority_status'] ?? 'fix-this-first' );
			if ( isset( $priority[ $priority_key ] ) ) {
				$priority[ $priority_key ]++;
			}
			$trend_key = (string) ( $entry['trend'] ?? 'new' );
			if ( isset( $trend[ $trend_key ] ) ) {
				$trend[ $trend_key ]++;
			}
			$exposure_key = (string) ( $entry['bot_exposure'] ?? 'restricted' );
			if ( isset( $exposure[ $exposure_key ] ) ) {
				$exposure[ $exposure_key ]++;
			}
			if ( ! empty( $entry['schema_attention'] ) ) {
				$schema_attention++;
				$clusters['schema_runtime_attention'] = ( $clusters['schema_runtime_attention'] ?? 0 ) + 1;
			}
			foreach ( (array) ( $entry['priority_actions'] ?? array() ) as $action ) {
				$action = sanitize_text_field( (string) $action );
				if ( '' === $action ) {
					continue;
				}
				$clusters[ $action ] = ( $clusters[ $action ] ?? 0 ) + 1;
			}
		}

		arsort( $clusters );

		return array(
			'total_pages'       => $total,
			'average_score'     => $total > 0 ? (int) round( $score_total / $total ) : 0,
			'priority'          => $priority,
			'trend'             => $trend,
			'bot_exposure'      => $exposure,
			'schema_attention'  => $schema_attention,
			'remediation_queue' => array_slice( $clusters, 0, 6, true ),
		);
	}

	private static function schema_type_guidance( string $schema_type ): string {
		switch ( $schema_type ) {
			case 'Person':
				return __( 'For Person, keep the profile focused on one real person with a visible role, affiliation, and authoritative profile links.', 'open-growth-seo' );
			case 'Course':
				return __( 'For Course, expose course code, syllabus-level description, and academic provider context visibly on the page.', 'open-growth-seo' );
			case 'EducationalOccupationalProgram':
				return __( 'For EducationalOccupationalProgram, expose degree outcome, duration, study mode, and admissions context visibly on the landing page.', 'open-growth-seo' );
			case 'FAQPage':
				return __( 'For FAQPage, show at least two visible question-and-answer pairs on the page or downgrade the schema type.', 'open-growth-seo' );
			case 'QAPage':
				return __( 'For QAPage, expose one primary question and visible accepted or suggested answers in the main content.', 'open-growth-seo' );
			case 'Recipe':
				return __( 'For Recipe, add visible ingredients, instructions, and time details before keeping recipe schema active.', 'open-growth-seo' );
			case 'JobPosting':
				return __( 'For JobPosting, expose salary, qualifications, responsibilities, and employment details visibly on-page.', 'open-growth-seo' );
			case 'Event':
				return __( 'For Event, expose date, time, location, and attendance details visibly on the landing page.', 'open-growth-seo' );
			case 'Product':
				return __( 'For Product, expose price, availability, and key specifications clearly in the visible product content.', 'open-growth-seo' );
			case 'SoftwareApplication':
				return __( 'For SoftwareApplication, expose platform, category, and core app details visibly before keeping that schema active.', 'open-growth-seo' );
			case 'Dataset':
				return __( 'For Dataset, expose identifier, scope, and distribution context clearly on the landing page.', 'open-growth-seo' );
			case 'ScholarlyArticle':
				return __( 'For ScholarlyArticle, expose title, authorship, publication context, and a stable identifier such as DOI or repository handle visibly on the page.', 'open-growth-seo' );
			case 'VideoObject':
				return __( 'For VideoObject, expose the video visibly with supporting descriptive text, thumbnail context, and topic framing.', 'open-growth-seo' );
			case 'ProfilePage':
				return __( 'For ProfilePage, keep the main entity profile details visibly dominant in the page content.', 'open-growth-seo' );
			case 'DiscussionForumPosting':
				return __( 'For DiscussionForumPosting, expose the thread question, responses, and discussion context visibly on the page.', 'open-growth-seo' );
			default:
				return '';
		}
	}

	private static function geo_score( bool $textual, bool $schema_mismatch, int $citability, bool $semantic, array $entities, array $internal, array $freshness, array $snippet_signals, string $bot_exposure ): int {
		$score = 0;
		if ( $textual ) {
			$score += 24;
		}
		if ( ! $schema_mismatch ) {
			$score += 14;
		}
		$score += min( 16, $citability * 6 );
		if ( $semantic ) {
			$score += 12;
		}
		$score += min( 8, count( $entities ) * 2 );
		if ( (int) ( $internal['count'] ?? 0 ) >= 2 ) {
			$score += 8;
		}
		if ( ! empty( $freshness['fresh'] ) ) {
			$score += 6;
		}
		if ( empty( $snippet_signals['restrictive'] ) ) {
			$score += 6;
		} elseif ( empty( $snippet_signals['nosnippet'] ) ) {
			$score += 3;
		}
		if ( 'open' === $bot_exposure ) {
			$score += 6;
		} elseif ( 'search-only' === $bot_exposure ) {
			$score += 3;
		}
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

	private static function resolve_snippet_controls_for_post( int $post_id ): array {
		$post_type = (string) get_post_type( $post_id );
		$settings  = Settings::get_all();

		$nosnippet_meta = (string) get_post_meta( $post_id, 'ogs_seo_nosnippet', true );
		$nosnippet = '';
		if ( in_array( $nosnippet_meta, array( '0', '1' ), true ) ) {
			$nosnippet = $nosnippet_meta;
		} elseif ( isset( $settings['post_type_nosnippet_defaults'][ $post_type ] ) ) {
			$nosnippet = '1' === (string) $settings['post_type_nosnippet_defaults'][ $post_type ] ? '1' : '0';
		} else {
			$nosnippet = ! empty( $settings['default_nosnippet'] ) ? '1' : '0';
		}

		$max_snippet = (string) get_post_meta( $post_id, 'ogs_seo_max_snippet', true );
		if ( '' === trim( $max_snippet ) ) {
			$max_snippet = isset( $settings['post_type_max_snippet_defaults'][ $post_type ] ) && '' !== (string) $settings['post_type_max_snippet_defaults'][ $post_type ]
				? (string) $settings['post_type_max_snippet_defaults'][ $post_type ]
				: (string) ( $settings['default_max_snippet'] ?? '' );
		}

		$max_image = (string) get_post_meta( $post_id, 'ogs_seo_max_image_preview', true );
		if ( '' === trim( $max_image ) ) {
			$max_image = isset( $settings['post_type_max_image_preview_defaults'][ $post_type ] ) && '' !== (string) $settings['post_type_max_image_preview_defaults'][ $post_type ]
				? (string) $settings['post_type_max_image_preview_defaults'][ $post_type ]
				: (string) ( $settings['default_max_image_preview'] ?? '' );
		}

		$max_video = (string) get_post_meta( $post_id, 'ogs_seo_max_video_preview', true );
		if ( '' === trim( $max_video ) ) {
			$max_video = isset( $settings['post_type_max_video_preview_defaults'][ $post_type ] ) && '' !== (string) $settings['post_type_max_video_preview_defaults'][ $post_type ]
				? (string) $settings['post_type_max_video_preview_defaults'][ $post_type ]
				: (string) ( $settings['default_max_video_preview'] ?? '' );
		}

		return array(
			'nosnippet'         => $nosnippet,
			'max_snippet'       => $max_snippet,
			'max_image_preview' => $max_image,
			'max_video_preview' => $max_video,
		);
	}

	private static function normalize_snippet_controls( array $controls ): array {
		$nosnippet = (string) ( $controls['nosnippet'] ?? '0' );
		if ( ! in_array( $nosnippet, array( '0', '1' ), true ) ) {
			$nosnippet = '0';
		}

		$max_snippet = trim( (string) ( $controls['max_snippet'] ?? '' ) );
		if ( '' !== $max_snippet && '-1' !== $max_snippet && ! preg_match( '/^\d+$/', $max_snippet ) ) {
			$max_snippet = '';
		}

		$max_image = strtolower( trim( (string) ( $controls['max_image_preview'] ?? '' ) ) );
		if ( ! in_array( $max_image, array( '', 'none', 'standard', 'large' ), true ) ) {
			$max_image = '';
		}

		$max_video = trim( (string) ( $controls['max_video_preview'] ?? '' ) );
		if ( '' !== $max_video && '-1' !== $max_video && ! preg_match( '/^\d+$/', $max_video ) ) {
			$max_video = '';
		}

		return array(
			'nosnippet'         => $nosnippet,
			'max_snippet'       => $max_snippet,
			'max_image_preview' => $max_image,
			'max_video_preview' => $max_video,
		);
	}

	private static function snippet_participation_signals( array $snippet_controls, array $data_nosnippet_ids, array $bot_policies ): array {
		$max_snippet = (string) ( $snippet_controls['max_snippet'] ?? '' );
		$max_image   = (string) ( $snippet_controls['max_image_preview'] ?? '' );
		$nosnippet   = '1' === (string) ( $snippet_controls['nosnippet'] ?? '0' );

		$max_snippet_restrictive = false;
		if ( '' !== $max_snippet && '-1' !== $max_snippet && preg_match( '/^\d+$/', $max_snippet ) ) {
			$max_snippet_restrictive = (int) $max_snippet > 0 && (int) $max_snippet < 40;
		}

		$restrictive = $nosnippet || $max_snippet_restrictive || 'none' === $max_image || count( $data_nosnippet_ids ) > 6;

		return array(
			'nosnippet'              => $nosnippet,
			'max_snippet'            => $max_snippet,
			'max_snippet_restrictive' => $max_snippet_restrictive,
			'max_image_none'         => 'none' === $max_image,
			'data_nosnippet_count'   => count( $data_nosnippet_ids ),
			'restrictive'            => $restrictive,
			'gptbot_open'            => ! empty( $bot_policies['gptbot_open'] ),
			'oai_searchbot_open'     => ! empty( $bot_policies['oai_searchbot_open'] ),
		);
	}

	private static function empty_analysis(): array {
		return array(
			'summary' => array(
				'critical_text_visible' => false,
				'schema_text_mismatch'  => false,
				'citability_score'      => 0,
				'semantic_clear'        => false,
				'discoverable_internal' => false,
				'fresh'                 => false,
				'entities_count'        => 0,
				'snippet_participation_restrictive' => false,
				'recommendations_count' => 0,
				'geo_score'             => 0,
				'priority_status'       => 'fix-this-first',
				'opportunity_count'     => 0,
				'quick_win_count'       => 0,
				'bot_exposure'          => 'restricted',
			),
			'signals' => array(
				'word_count'      => 0,
				'media'           => array(
					'images' => 0,
					'videos' => 0,
					'tables' => 0,
				),
				'structures'      => array(
					'headings' => 0,
					'definitions' => 0,
					'attributes' => 0,
				),
				'citability'      => array(
					'answer_first' => false,
					'facts' => 0,
					'attributes' => 0,
				),
				'entities'        => array(),
				'freshness'       => array(
					'days_since_modified' => -1,
					'fresh' => false,
				),
				'internal_links'  => array(
					'count' => 0,
					'targets' => array(),
				),
				'preview_control' => array( 'data_nosnippet_ids' => array() ),
				'snippet_participation' => array(
					'nosnippet'               => false,
					'max_snippet'             => '',
					'max_snippet_restrictive' => false,
					'max_image_none'          => false,
					'data_nosnippet_count'    => 0,
					'restrictive'             => false,
					'gptbot_open'             => false,
					'oai_searchbot_open'      => false,
				),
				'bots'            => self::bot_policy_snapshot(),
			),
			'opportunities' => array(),
			'priority_actions' => array(),
			'recommendations' => array(),
		);
	}
}
