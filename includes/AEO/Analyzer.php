<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\AEO;

use OpenGrowthSolutions\OpenGrowthSEO\Support\Settings;

defined( 'ABSPATH' ) || exit;

class Analyzer {
	private const ANSWER_WINDOW_CHARS = 420;

	public function register(): void {
		add_filter( 'ogs_seo_audit_checks', array( $this, 'register_checks' ) );
	}

	public function register_checks( array $checks ): array {
		if ( empty( Settings::get( 'aeo_enabled', 1 ) ) ) {
			return $checks;
		}
		$checks['aeo_answer_first'] = array( $this, 'check_recent_answer_first' );
		$checks['aeo_intent_coverage'] = array( $this, 'check_recent_intent_coverage' );
		$checks['aeo_nontext_dependency'] = array( $this, 'check_recent_nontext_dependency' );
		$checks['aeo_readability'] = array( $this, 'check_recent_readability' );
		$checks['aeo_keyphrase_alignment'] = array( $this, 'check_recent_keyphrase_alignment' );
		return $checks;
	}

	public function check_recent_answer_first(): array {
		$post_id = $this->latest_post_id();
		if ( $post_id <= 0 ) {
			return array();
		}
		$analysis = self::analyze_post( $post_id );
		if ( ! empty( $analysis['summary']['answer_first'] ) ) {
			return array();
		}
		return array(
			'severity'       => 'important',
			'title'          => __( 'Answer-first signal missing in recent content', 'open-growth-seo' ),
			'recommendation' => __( 'Place a direct answer paragraph in the first section to improve extractability.', 'open-growth-seo' ),
		);
	}

	public function check_recent_intent_coverage(): array {
		$post_id = $this->latest_post_id();
		if ( $post_id <= 0 ) {
			return array();
		}
		$analysis = self::analyze_post( $post_id );
		$coverage = isset( $analysis['summary']['intent_coverage'] ) ? (int) $analysis['summary']['intent_coverage'] : 0;
		if ( $coverage >= 2 ) {
			return array();
		}
		return array(
			'severity'       => 'minor',
			'title'          => __( 'Intent coverage appears narrow in recent content', 'open-growth-seo' ),
			'recommendation' => __( 'Add supporting sections for related intents (comparison, steps, cost, troubleshooting) where relevant.', 'open-growth-seo' ),
		);
	}

	public function check_recent_nontext_dependency(): array {
		$post_id = $this->latest_post_id();
		if ( $post_id <= 0 ) {
			return array();
		}
		$analysis = self::analyze_post( $post_id );
		if ( empty( $analysis['summary']['non_text_dependency'] ) ) {
			return array();
		}
		return array(
			'severity'       => 'important',
			'title'          => __( 'Important content may rely too much on non-text elements', 'open-growth-seo' ),
			'recommendation' => __( 'Add concise textual explanations for key visual/video information.', 'open-growth-seo' ),
		);
	}

	public function check_recent_readability(): array {
		$post_id = $this->latest_post_id();
		if ( $post_id <= 0 ) {
			return array();
		}
		$analysis = self::analyze_post( $post_id );
		$status   = (string) ( $analysis['summary']['readability'] ?? 'good' );
		if ( 'fix-this-first' !== $status && 'needs-work' !== $status ) {
			return array();
		}
		return array(
			'severity'       => 'fix-this-first' === $status ? 'important' : 'minor',
			'title'          => __( 'Readability guidance flagged recent content', 'open-growth-seo' ),
			'recommendation' => __( 'Shorten long sentences, tighten paragraphs, and use clearer subheadings to improve scanability.', 'open-growth-seo' ),
		);
	}

	public function check_recent_keyphrase_alignment(): array {
		$post_id = $this->latest_post_id();
		if ( $post_id <= 0 ) {
			return array();
		}
		$analysis = self::analyze_post( $post_id );
		$keyphrase = (string) ( $analysis['signals']['keyphrase']['focus_keyphrase'] ?? '' );
		if ( '' === $keyphrase ) {
			return array();
		}
		if ( ! empty( $analysis['summary']['focus_keyphrase_in_title'] ) && ! empty( $analysis['summary']['focus_keyphrase_in_intro'] ) ) {
			return array();
		}
		return array(
			'severity'       => 'minor',
			'title'          => __( 'Focus keyphrase alignment is weak in recent content', 'open-growth-seo' ),
			'recommendation' => __( 'Place the chosen keyphrase naturally in the title and opening section to make page intent clearer.', 'open-growth-seo' ),
		);
	}

	public static function analyze_post( int $post_id ): array {
		$content = (string) get_post_field( 'post_content', $post_id );
		$title   = (string) get_the_title( $post_id );
		$url     = (string) get_permalink( $post_id );
		return self::analyze_content(
			$content,
			$title,
			$url,
			array(
				'focus_keyphrase' => (string) get_post_meta( $post_id, 'ogs_seo_focus_keyphrase', true ),
				'cornerstone'     => '1' === (string) get_post_meta( $post_id, 'ogs_seo_cornerstone', true ),
			)
		);
	}

	public static function analyze_content( string $content, string $title = '', string $url = '', array $context = array() ): array {
		$plain         = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $content ) ) );
		$word_count    = str_word_count( $plain );
		$intro         = function_exists( 'mb_substr' ) ? mb_substr( $plain, 0, self::ANSWER_WINDOW_CHARS ) : substr( $plain, 0, self::ANSWER_WINDOW_CHARS );
		$paragraphs    = self::extract_paragraphs( $content );
		$answer_paras  = self::detect_answer_paragraphs( $paragraphs );
		$structures    = self::detect_structures( $content );
		$intent        = self::detect_intent_coverage( $title . ' ' . $plain );
		$entities      = self::extract_entities( $plain );
		$facts         = self::extract_facts( $plain );
		$attributes    = self::extract_attributes( $plain );
		$internal      = self::internal_link_stats( $content, $url );
		$non_text      = self::detect_nontext_dependency( $content, $word_count, count( $answer_paras ), $structures );
		$clarity       = self::clarity_status( $plain, $answer_paras, $structures, $intent );
		$readability   = self::readability_status( $plain, $structures );
		$followup_coverage = self::follow_up_coverage( $intent );
		$followups     = self::build_follow_ups( $title, $intent, $structures, $plain );
		$keyphrase     = self::focus_keyphrase_signals( (string) ( $context['focus_keyphrase'] ?? '' ), $title, $plain, $intro );
		$cornerstone   = ! empty( $context['cornerstone'] );
		$answer_first  = self::has_answer_first( $intro, $answer_paras );
		$opportunities = self::build_opportunities( $answer_first, $answer_paras, $structures, $intent, $non_text, $internal, $facts, $attributes, $word_count, $followup_coverage, $keyphrase, $readability, $cornerstone );
		$recs          = array_values(
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
		$score         = self::aeo_score( $answer_first, $structures, $intent, $non_text, $internal, $facts, $attributes, $word_count, $followup_coverage, $keyphrase, $readability, $cornerstone );
		$priority      = self::priority_status( $score, $opportunities );

		return array(
			'summary' => array(
				'word_count'          => $word_count,
				'answer_first'        => $answer_first,
				'answer_paragraphs'   => count( $answer_paras ),
				'intent_coverage'     => count( $intent ),
				'follow_up_coverage'  => $followup_coverage['score'],
				'clarity'             => $clarity,
				'readability'         => $readability,
				'non_text_dependency' => $non_text['high_dependency'],
				'internal_links'      => $internal['count'],
				'focus_keyphrase_set' => '' !== $keyphrase['focus_keyphrase'],
				'focus_keyphrase_in_title' => $keyphrase['in_title'],
				'focus_keyphrase_in_intro' => $keyphrase['in_intro'],
				'cornerstone'         => $cornerstone,
				'aeo_score'           => $score,
				'priority_status'     => $priority,
				'opportunity_count'   => count( $opportunities ),
				'quick_win_count'     => count(
					array_filter(
						$opportunities,
						static fn( $row ): bool => is_array( $row ) && ! empty( $row['quick_win'] )
					)
				),
				'entity_count'        => count( $entities ),
				'fact_count'          => count( $facts ),
				'attribute_count'     => count( $attributes ),
				'heading_count'       => (int) ( $structures['headings'] ?? 0 ),
			),
			'signals' => array(
				'answer_paragraphs' => $answer_paras,
				'structures'        => $structures,
				'intent'            => array_values( array_keys( $intent ) ),
				'entities'          => $entities,
				'facts'             => $facts,
				'attributes'        => $attributes,
				'non_text'          => $non_text,
				'internal_links'    => $internal,
				'follow_up_coverage' => $followup_coverage,
				'keyphrase'         => $keyphrase,
			),
			'follow_up_questions' => $followups,
			'opportunities'       => $opportunities,
			'priority_actions'    => array_slice( $opportunities, 0, 3 ),
			'recommendations'     => $recs,
		);
	}

	private function latest_post_id(): int {
		$post_types = get_post_types(
			array(
				'public' => true,
			),
			'names'
		);
		unset( $post_types['attachment'] );
		$post = get_posts(
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
		return ! empty( $post ) ? (int) $post[0] : 0;
	}

	private static function extract_paragraphs( string $content ): array {
		$paras = array();
		if ( preg_match_all( '/<p[^>]*>(.*?)<\/p>/is', $content, $matches ) ) {
			foreach ( $matches[1] as $raw ) {
				$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $raw ) ) );
				if ( str_word_count( $text ) >= 8 ) {
					$paras[] = $text;
				}
			}
		}
		if ( empty( $paras ) ) {
			$plain = preg_split( '/\n{2,}/', wp_strip_all_tags( $content ) ) ?: array();
			foreach ( $plain as $raw ) {
				$text = trim( preg_replace( '/\s+/', ' ', (string) $raw ) );
				if ( str_word_count( $text ) >= 8 ) {
					$paras[] = $text;
				}
			}
		}
		return array_slice( $paras, 0, 30 );
	}

	private static function detect_answer_paragraphs( array $paragraphs ): array {
		$matches = array();
		$patterns = '/\b(is|are|means|refers to|defined as|in short|in summary|you can)\b/i';
		foreach ( $paragraphs as $idx => $paragraph ) {
			$words = str_word_count( $paragraph );
			if ( $words < 12 || $words > 90 ) {
				continue;
			}
			if ( preg_match( $patterns, $paragraph ) ) {
				$matches[] = array(
					'position' => $idx + 1,
					'text'     => $paragraph,
				);
			}
		}
		return $matches;
	}

	private static function has_answer_first( string $intro, array $answer_paras ): bool {
		if ( preg_match( '/\b(is|are|means|defined as|in short)\b/i', $intro ) ) {
			return true;
		}
		if ( empty( $answer_paras ) ) {
			return false;
		}
		return (int) $answer_paras[0]['position'] <= 2;
	}

	private static function detect_structures( string $content ): array {
		$plain = wp_strip_all_tags( $content );
		return array(
			'definitions' => preg_match_all( '/\b(is|means|defined as|refers to)\b/i', $plain ),
			'lists'       => preg_match_all( '/<(ul|ol)\b/i', $content ),
			'steps'       => preg_match_all( '/\b(step\s*[0-9]+|first|second|third|next|finally)\b/i', $plain ),
			'tables'      => preg_match_all( '/<table\b/i', $content ),
			'headings'    => preg_match_all( '/<h[2-4]\b/i', $content ),
		);
	}

	private static function detect_intent_coverage( string $text ): array {
		$patterns = array(
			'what'        => '/\b(what is|definition|overview|explained)\b/i',
			'how'         => '/\b(how to|steps|process|method|guide)\b/i',
			'comparison'  => '/\b(vs\.?|compare|difference|better than|alternative)\b/i',
			'cost'        => '/\b(price|cost|budget|pricing|fee|roi)\b/i',
			'troubleshoot' => '/\b(error|issue|problem|fix|troubleshoot)\b/i',
		);
		$found = array();
		foreach ( $patterns as $intent => $regex ) {
			if ( preg_match( $regex, $text ) ) {
				$found[ $intent ] = true;
			}
		}
		return $found;
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

	private static function extract_facts( string $text ): array {
		$facts = array();
		if ( preg_match_all( '/\b\d+[\d\.,]*\s?(%|ms|s|sec|seconds|min|minutes|hours|days|k|m|gb|mb|km|mi|usd|eur|\$)\b/i', $text, $matches ) ) {
			$facts = array_values( array_unique( array_map( 'trim', $matches[0] ) ) );
		}
		return array_slice( $facts, 0, 10 );
	}

	private static function extract_attributes( string $text ): array {
		$attributes = array();
		if ( preg_match_all( '/\b([A-Za-z][A-Za-z\s]{2,30}):\s*([^\.;\n]{2,60})/u', $text, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$attributes[] = array(
					'attribute' => trim( (string) $match[1] ),
					'value'     => trim( (string) $match[2] ),
				);
			}
		}
		return array_slice( $attributes, 0, 8 );
	}

	private static function internal_link_stats( string $content, string $current_url ): array {
		$site_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$count     = 0;
		$targets   = array();
		if ( preg_match_all( '/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $content, $matches ) ) {
			foreach ( $matches[1] as $href ) {
				$href = esc_url_raw( (string) $href );
				if ( '' === $href ) {
					continue;
				}
				$host = wp_parse_url( $href, PHP_URL_HOST );
				if ( empty( $host ) || ( is_string( $site_host ) && strtolower( (string) $host ) === strtolower( $site_host ) ) ) {
					++$count;
					$targets[] = $href;
				}
			}
		}
		return array(
			'count'   => $count,
			'targets' => array_slice( array_values( array_unique( $targets ) ), 0, 10 ),
		);
	}

	private static function detect_nontext_dependency( string $content, int $word_count, int $answer_paragraph_count, array $structures ): array {
		$images = preg_match_all( '/<img\b/i', $content );
		$videos = preg_match_all( '/<video\b|youtube\.com|youtu\.be|vimeo\.com/i', $content );
		$tables = preg_match_all( '/<table\b/i', $content );
		$has_text_structure = ( (int) ( $structures['lists'] ?? 0 ) > 0 ) || ( (int) ( $structures['steps'] ?? 0 ) > 0 );
		$high = false;
		if ( $word_count < 260 && ( $images + $videos ) >= 3 && $answer_paragraph_count < 2 ) {
			$high = true;
		}
		if ( $word_count < 180 && $videos >= 1 && ! $has_text_structure ) {
			$high = true;
		}
		if ( $word_count < 200 && $images >= 2 && $answer_paragraph_count < 1 ) {
			$high = true;
		}
		return array(
			'images'          => $images,
			'videos'          => $videos,
			'tables'          => $tables,
			'high_dependency' => $high,
		);
	}

	private static function follow_up_coverage( array $intent ): array {
		$targets = array( 'how', 'comparison', 'cost', 'troubleshoot' );
		$covered = array();
		foreach ( $targets as $target ) {
			if ( isset( $intent[ $target ] ) ) {
				$covered[] = $target;
			}
		}
		$missing = array_values( array_diff( $targets, $covered ) );
		$total   = count( $targets );
		$ratio   = $total > 0 ? count( $covered ) / $total : 0;
		return array(
			'covered' => $covered,
			'missing' => $missing,
			'score'   => (int) round( $ratio * 100 ),
		);
	}

	private static function clarity_status( string $plain, array $answer_paras, array $structures, array $intent ): string {
		$score = 0;
		if ( self::has_answer_first( function_exists( 'mb_substr' ) ? mb_substr( $plain, 0, self::ANSWER_WINDOW_CHARS ) : substr( $plain, 0, self::ANSWER_WINDOW_CHARS ), $answer_paras ) ) {
			++$score;
		}
		if ( (int) $structures['headings'] >= 2 ) {
			++$score;
		}
		if ( (int) $structures['lists'] > 0 || (int) $structures['steps'] > 0 ) {
			++$score;
		}
		if ( count( $intent ) >= 2 ) {
			++$score;
		}
		if ( str_word_count( $plain ) >= 350 ) {
			++$score;
		}
		if ( $score >= 4 ) {
			return 'strong';
		}
		if ( $score >= 2 ) {
			return 'moderate';
		}
		return 'needs-work';
	}

	private static function readability_status( string $plain, array $structures ): string {
		$sentences = preg_split( '/[.!?]+/', $plain ) ?: array();
		$sentences = array_filter( array_map( 'trim', $sentences ) );
		$word_count = max( 1, str_word_count( $plain ) );
		$average = ! empty( $sentences ) ? $word_count / count( $sentences ) : $word_count;

		if ( $word_count < 60 || $average > 28 || (int) ( $structures['headings'] ?? 0 ) < 2 ) {
			return 'fix-this-first';
		}
		if ( $average > 20 || (int) ( $structures['headings'] ?? 0 ) < 3 ) {
			return 'needs-work';
		}
		return 'good';
	}

	private static function focus_keyphrase_signals( string $focus_keyphrase, string $title, string $plain, string $intro ): array {
		$focus_keyphrase = trim( sanitize_text_field( $focus_keyphrase ) );
		$normalized = strtolower( $focus_keyphrase );
		if ( '' === $normalized ) {
			return array(
				'focus_keyphrase' => '',
				'in_title'        => false,
				'in_intro'        => false,
				'mentions'        => 0,
			);
		}

		$title_hit = false !== strpos( strtolower( $title ), $normalized );
		$intro_hit = false !== strpos( strtolower( $intro ), $normalized );
		$mentions  = preg_match_all( '/' . preg_quote( $normalized, '/' ) . '/i', strtolower( $plain ) );

		return array(
			'focus_keyphrase' => $focus_keyphrase,
			'in_title'        => $title_hit,
			'in_intro'        => $intro_hit,
			'mentions'        => (int) $mentions,
		);
	}

	private static function build_follow_ups( string $title, array $intent, array $structures, string $plain ): array {
		$questions = array();
		$topic = trim( $title );
		if ( '' === $topic ) {
			$topic = 'this topic';
		}
		if ( ! isset( $intent['comparison'] ) ) {
			$questions[] = sprintf( __( 'How does %s compare with alternatives?', 'open-growth-seo' ), $topic );
		}
		if ( ! isset( $intent['cost'] ) ) {
			$questions[] = sprintf( __( 'What are the costs, limits, or trade-offs of %s?', 'open-growth-seo' ), $topic );
		}
		if ( ! isset( $intent['troubleshoot'] ) ) {
			$questions[] = sprintf( __( 'What common issues appear when implementing %s and how can they be fixed?', 'open-growth-seo' ), $topic );
		}
		if ( (int) $structures['steps'] < 1 ) {
			$questions[] = sprintf( __( 'What are the exact step-by-step actions to apply %s?', 'open-growth-seo' ), $topic );
		}
		return array_slice( array_values( array_unique( $questions ) ), 0, 5 );
	}

	private static function build_opportunities( bool $answer_first, array $answer_paras, array $structures, array $intent, array $non_text, array $internal, array $facts, array $attributes, int $word_count, array $followup_coverage, array $keyphrase, string $readability, bool $cornerstone ): array {
		$recs = array();
		if ( ! $answer_first ) {
			$recs[] = self::opportunity(
				'answer_first_missing',
				'important',
				__( 'Answer-first summary is missing', 'open-growth-seo' ),
				__( 'The page does not place a direct answer early enough for answer extraction.', 'open-growth-seo' ),
				__( 'Add a concise answer-first paragraph within the first 2 paragraphs (40-80 words).', 'open-growth-seo' ),
				false
			);
		}
		if ( (int) $structures['lists'] < 1 && (int) $structures['steps'] < 1 ) {
			$recs[] = self::opportunity(
				'extractable_structure_missing',
				'important',
				__( 'Extractable structure is weak', 'open-growth-seo' ),
				__( 'The page lacks lists or step-by-step structure that helps answer extraction and scanning.', 'open-growth-seo' ),
				__( 'Add a list or ordered steps to improve extractable answer structure.', 'open-growth-seo' ),
				true
			);
		}
		if ( (int) $structures['tables'] < 1 && isset( $intent['comparison'] ) ) {
			$recs[] = self::opportunity(
				'comparison_table_missing',
				'minor',
				__( 'Comparison intent lacks a table', 'open-growth-seo' ),
				__( 'Comparison-oriented content is present, but there is no table to make differences easier to scan and cite.', 'open-growth-seo' ),
				__( 'Add a comparison table for key options, differences, and constraints.', 'open-growth-seo' ),
				true
			);
		}
		if ( count( $intent ) < 2 ) {
			$recs[] = self::opportunity(
				'intent_coverage_thin',
				'important',
				__( 'Intent coverage is narrow', 'open-growth-seo' ),
				__( 'The page appears to cover only one narrow user intent, which limits answer completeness.', 'open-growth-seo' ),
				__( 'Expand intent coverage with dedicated sections (what/how/comparison/cost/troubleshooting).', 'open-growth-seo' ),
				false
			);
		}
		if ( (int) ( $followup_coverage['score'] ?? 0 ) < 50 ) {
			$missing = isset( $followup_coverage['missing'] ) && is_array( $followup_coverage['missing'] ) ? $followup_coverage['missing'] : array();
			$recs[] = self::opportunity(
				'follow_up_coverage_gap',
				'minor',
				__( 'Follow-up coverage is incomplete', 'open-growth-seo' ),
				__( 'Likely next questions are not fully addressed, which weakens answer depth and journey continuity.', 'open-growth-seo' ),
				sprintf(
					/* translators: %s: comma-separated follow-up intents */
					__( 'Improve follow-up coverage by addressing these intents: %s.', 'open-growth-seo' ),
					empty( $missing ) ? __( 'comparison, cost, troubleshooting, and implementation depth', 'open-growth-seo' ) : implode( ', ', $missing )
				),
				false
			);
		}
		if ( $non_text['high_dependency'] ) {
			$recs[] = self::opportunity(
				'non_text_dependency',
				'important',
				__( 'Important meaning depends too much on non-text elements', 'open-growth-seo' ),
				__( 'The page appears to rely on images or video without enough supporting explanatory text.', 'open-growth-seo' ),
				sprintf(
					/* translators: 1: image count, 2: video count */
					__( 'Convert critical visual-only information into text. Detected %1$d image(s) and %2$d video signal(s) with limited explanatory text.', 'open-growth-seo' ),
					(int) ( $non_text['images'] ?? 0 ),
					(int) ( $non_text['videos'] ?? 0 )
				),
				false
			);
		}
		if ( $internal['count'] < 2 ) {
			$recs[] = self::opportunity(
				'internal_links_thin',
				'minor',
				__( 'Internal support links are thin', 'open-growth-seo' ),
				__( 'The page has too few internal links to supporting material, which weakens navigation and topical reinforcement.', 'open-growth-seo' ),
				__( 'Add 2-4 internal thematic links to related guides, definitions, and supporting pages.', 'open-growth-seo' ),
				true
			);
		}
		if ( '' === (string) ( $keyphrase['focus_keyphrase'] ?? '' ) ) {
			$recs[] = self::opportunity(
				'focus_keyphrase_missing',
				'minor',
				__( 'Focus keyphrase is not set', 'open-growth-seo' ),
				__( 'AEO guidance can be more specific when a target keyphrase is defined.', 'open-growth-seo' ),
				__( 'Set a focus keyphrase so title and introduction checks can guide optimization more directly.', 'open-growth-seo' ),
				true
			);
		} else {
			if ( empty( $keyphrase['in_title'] ) ) {
				$recs[] = self::opportunity(
					'focus_keyphrase_title',
					'minor',
					__( 'Focus keyphrase is missing from the title', 'open-growth-seo' ),
					__( 'The page title does not reinforce the chosen focus topic clearly enough.', 'open-growth-seo' ),
					__( 'Use the focus keyphrase naturally in the title to clarify page intent.', 'open-growth-seo' ),
					true
				);
			}
			if ( empty( $keyphrase['in_intro'] ) ) {
				$recs[] = self::opportunity(
					'focus_keyphrase_intro',
					'minor',
					__( 'Focus keyphrase is missing from the opening section', 'open-growth-seo' ),
					__( 'The introduction does not reinforce the chosen focus topic early enough.', 'open-growth-seo' ),
					__( 'Mention the focus keyphrase in the opening section to reinforce answer relevance.', 'open-growth-seo' ),
					true
				);
			}
		}
		if ( 'good' !== $readability ) {
			$recs[] = self::opportunity(
				'readability_needs_work',
				'fix-this-first' === $readability ? 'important' : 'minor',
				__( 'Readability is limiting extractability', 'open-growth-seo' ),
				__( 'Long sentences or weak section structure make the page harder to scan, quote, and summarize.', 'open-growth-seo' ),
				__( 'Shorten long sentences and tighten paragraphs so the page is easier to scan and quote.', 'open-growth-seo' ),
				false
			);
		}
		if ( $cornerstone && $internal['count'] < 3 ) {
			$recs[] = self::opportunity(
				'cornerstone_support_thin',
				'important',
				__( 'Cornerstone page needs stronger internal support', 'open-growth-seo' ),
				__( 'This page is marked as cornerstone content but is not strongly reinforced by internal linking.', 'open-growth-seo' ),
				__( 'This page is marked as cornerstone content, so it should receive more internal links from related pages.', 'open-growth-seo' ),
				false
			);
		}
		if ( empty( $facts ) ) {
			$recs[] = self::opportunity(
				'citability_facts_missing',
				'minor',
				__( 'Citable facts are limited', 'open-growth-seo' ),
				__( 'The page lacks enough concrete facts or numbers to support strong citation extraction.', 'open-growth-seo' ),
				__( 'Include verifiable facts (numbers, ranges, dates, limits) to improve citation quality.', 'open-growth-seo' ),
				false
			);
		}
		if ( empty( $attributes ) ) {
			$recs[] = self::opportunity(
				'attribute_statements_missing',
				'minor',
				__( 'Attribute-value statements are limited', 'open-growth-seo' ),
				__( 'The page does not expose enough explicit requirement/version/scope statements for structured interpretation.', 'open-growth-seo' ),
				__( 'Add key attribute-value statements (for example: requirement, version, scope, output).', 'open-growth-seo' ),
				true
			);
		}
		if ( $word_count < 260 ) {
			$recs[] = self::opportunity(
				'content_depth_short',
				'minor',
				__( 'Content depth is limited', 'open-growth-seo' ),
				__( 'The page may be too short to satisfy complex informational intent completely.', 'open-growth-seo' ),
				__( 'Content may be too short for complex intent coverage; expand with concise but complete sections.', 'open-growth-seo' ),
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

	private static function aeo_score( bool $answer_first, array $structures, array $intent, array $non_text, array $internal, array $facts, array $attributes, int $word_count, array $followup_coverage, array $keyphrase, string $readability, bool $cornerstone ): int {
		$score = 0;
		if ( $answer_first ) {
			$score += 22;
		}
		$score += min( 18, count( $intent ) * 6 );
		$score += min( 16, (int) ( $followup_coverage['score'] ?? 0 ) / 10 );
		$score += min( 10, (int) ( $structures['headings'] ?? 0 ) * 2 );
		if ( (int) ( $structures['lists'] ?? 0 ) > 0 || (int) ( $structures['steps'] ?? 0 ) > 0 ) {
			$score += 8;
		}
		if ( ! empty( $keyphrase['focus_keyphrase'] ) && ! empty( $keyphrase['in_title'] ) && ! empty( $keyphrase['in_intro'] ) ) {
			$score += 8;
		}
		if ( 'good' === $readability ) {
			$score += 10;
		} elseif ( 'needs-work' === $readability ) {
			$score += 4;
		}
		if ( empty( $non_text['high_dependency'] ) ) {
			$score += 6;
		}
		if ( (int) ( $internal['count'] ?? 0 ) >= ( $cornerstone ? 3 : 2 ) ) {
			$score += 6;
		}
		if ( ! empty( $facts ) ) {
			$score += 4;
		}
		if ( ! empty( $attributes ) ) {
			$score += 4;
		}
		if ( $word_count >= 260 ) {
			$score += 4;
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
}
