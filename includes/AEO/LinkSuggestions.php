<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\AEO;

defined( 'ABSPATH' ) || exit;

class LinkSuggestions {
	public static function outbound_targets( int $post_id, int $limit = 4 ): array {
		return self::suggest_related_posts( $post_id, $limit, false );
	}

	public static function inbound_opportunities( int $post_id, int $limit = 4 ): array {
		return self::suggest_related_posts( $post_id, $limit, true );
	}

	public static function inbound_link_count( int $post_id ): int {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 ) {
			return 0;
		}

		$graph_count = LinkGraph::inbound_count( $post_id );
		if ( $graph_count > 0 ) {
			return $graph_count;
		}

		$url = (string) get_permalink( $post_id );
		if ( '' === $url ) {
			return 0;
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( '' === $path ) {
			return 0;
		}

		global $wpdb;
		$like  = '%' . $wpdb->esc_like( $path ) . '%';
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type NOT IN ('revision','nav_menu_item') AND ID <> %d AND post_content LIKE %s",
				$post_id,
				$like
			)
		);

		return max( 0, (int) $count );
	}

	private static function suggest_related_posts( int $post_id, int $limit, bool $for_inbound ): array {
		$post_id = absint( $post_id );
		$limit   = max( 1, min( 8, absint( $limit ) ) );
		if ( $post_id <= 0 ) {
			return array();
		}

		$current_url     = self::normalize_url( (string) get_permalink( $post_id ) );
		$current_content = (string) get_post_field( 'post_content', $post_id );
		$current_type    = (string) get_post_type( $post_id );
		$current_title   = (string) get_the_title( $post_id );
		$keyphrase       = trim( (string) get_post_meta( $post_id, 'ogs_seo_focus_keyphrase', true ) );
		$existing_links  = self::internal_target_map( $current_content );
		$candidates      = self::candidate_ids( $post_id, $current_type );
		$suggestions     = array();

		foreach ( $candidates as $candidate_id ) {
			$candidate_id = absint( $candidate_id );
			if ( $candidate_id <= 0 || $candidate_id === $post_id ) {
				continue;
			}

			$candidate_url = self::normalize_url( (string) get_permalink( $candidate_id ) );
			if ( '' === $candidate_url ) {
				continue;
			}
			if ( ! $for_inbound && isset( $existing_links[ $candidate_url ] ) ) {
				continue;
			}
			if ( $for_inbound && self::post_links_to_target( $candidate_id, $current_url ) ) {
				continue;
			}

			$shared_terms     = self::shared_term_count( $post_id, $candidate_id );
			$keyword_overlap  = self::keyword_overlap_score( $keyphrase, $current_title, $candidate_id );
			$same_type        = $current_type === (string) get_post_type( $candidate_id );
			$score            = 0;
			$reason_fragments = array();

			if ( $same_type ) {
				$score += 2;
				$reason_fragments[] = __( 'same content type', 'open-growth-seo' );
			}
			if ( $shared_terms > 0 ) {
				$score += min( 6, $shared_terms * 2 );
				$reason_fragments[] = sprintf(
					/* translators: %d: shared term count */
					_n( '%d shared taxonomy match', '%d shared taxonomy matches', $shared_terms, 'open-growth-seo' ),
					$shared_terms
				);
			}
			if ( $keyword_overlap > 0 ) {
				$score += min( 4, $keyword_overlap );
				$reason_fragments[] = __( 'title/keyphrase overlap', 'open-growth-seo' );
			}

			if ( $score <= 0 ) {
				continue;
			}

			$suggestions[] = array(
				'post_id'  => $candidate_id,
				'title'    => (string) get_the_title( $candidate_id ),
				'url'      => $candidate_url,
				'edit_url' => (string) ( get_edit_post_link( $candidate_id ) ?: '' ),
				'reason'   => implode( ' | ', array_slice( $reason_fragments, 0, 2 ) ),
				'score'    => $score,
				'confidence' => self::confidence_bucket( $score ),
			);
		}

		usort(
			$suggestions,
			static function ( array $left, array $right ): int {
				return ( $right['score'] ?? 0 ) <=> ( $left['score'] ?? 0 );
			}
		);

		return array_slice( $suggestions, 0, $limit );
	}

	private static function confidence_bucket( int $score ): string {
		if ( $score >= 8 ) {
			return 'high';
		}
		if ( $score >= 4 ) {
			return 'medium';
		}
		return 'low';
	}

	private static function candidate_ids( int $post_id, string $post_type ): array {
		$post_type = sanitize_key( $post_type );
		$pool      = array( $post_type );
		if ( ! in_array( $post_type, array( 'post', 'page' ), true ) ) {
			$pool[] = 'post';
			$pool[] = 'page';
		}

		$posts = get_posts(
			array(
				'post_type'      => array_values( array_unique( array_filter( $pool ) ) ),
				'post_status'    => 'publish',
				'posts_per_page' => 24,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'post__not_in'   => array( $post_id ),
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		return array_values( array_filter( array_map( 'absint', $posts ) ) );
	}

	private static function shared_term_count( int $post_id, int $candidate_id ): int {
		$taxonomies = get_object_taxonomies( get_post_type( $post_id ) ?: 'post', 'names' );
		$count      = 0;
		foreach ( $taxonomies as $taxonomy ) {
			$left_terms  = get_the_terms( $post_id, (string) $taxonomy );
			$right_terms = get_the_terms( $candidate_id, (string) $taxonomy );
			if ( empty( $left_terms ) || empty( $right_terms ) || is_wp_error( $left_terms ) || is_wp_error( $right_terms ) ) {
				continue;
			}

			$left_ids  = array_map( static fn( $term ) => (int) $term->term_id, $left_terms );
			$right_ids = array_map( static fn( $term ) => (int) $term->term_id, $right_terms );
			$count    += count( array_intersect( $left_ids, $right_ids ) );
		}

		return $count;
	}

	private static function keyword_overlap_score( string $keyphrase, string $title, int $candidate_id ): int {
		$needles = array_filter(
			array_map(
				static function ( string $part ): string {
					$part = strtolower( trim( $part ) );
					return strlen( $part ) >= 4 ? $part : '';
				},
				preg_split( '/\s+/', trim( $keyphrase . ' ' . $title ) ) ?: array()
			)
		);
		if ( empty( $needles ) ) {
			return 0;
		}

		$haystack = strtolower(
			(string) get_the_title( $candidate_id ) . ' ' .
			(string) get_post_field( 'post_excerpt', $candidate_id )
		);
		$score = 0;
		foreach ( array_unique( $needles ) as $needle ) {
			if ( false !== strpos( $haystack, $needle ) ) {
				++$score;
			}
		}

		return $score;
	}

	private static function internal_target_map( string $content ): array {
		$targets = array();
		if ( preg_match_all( '/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $content, $matches ) ) {
			foreach ( $matches[1] as $href ) {
				$url = self::normalize_url( (string) $href );
				if ( '' !== $url ) {
					$targets[ $url ] = true;
				}
			}
		}
		return $targets;
	}

	private static function post_links_to_target( int $post_id, string $target ): bool {
		if ( '' === $target ) {
			return false;
		}

		$content = (string) get_post_field( 'post_content', $post_id );
		$targets = self::internal_target_map( $content );
		return isset( $targets[ $target ] );
	}

	private static function normalize_url( string $url ): string {
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
		$normalized .= ! empty( $parts['path'] ) ? rtrim( (string) $parts['path'], '/' ) : '';
		if ( ! empty( $parts['query'] ) ) {
			$normalized .= '?' . (string) $parts['query'];
		}

		return $normalized;
	}
}
