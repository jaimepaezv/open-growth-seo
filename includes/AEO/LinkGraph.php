<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\AEO;

defined( 'ABSPATH' ) || exit;

class LinkGraph {
	private const CACHE_OPTION = 'ogs_seo_link_graph_cache';
	private const CACHE_TTL    = 1800;

	public static function snapshot( bool $force_refresh = false, int $max_posts = 350 ): array {
		$max_posts = max( 40, min( 1200, absint( $max_posts ) ) );
		$cached    = get_option( self::CACHE_OPTION, array() );
		if ( ! $force_refresh && self::is_cache_fresh( $cached ) ) {
			return $cached;
		}

		$built = self::build_snapshot( $max_posts );
		update_option( self::CACHE_OPTION, $built, false );
		return $built;
	}

	public static function inbound_count( int $post_id ): int {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 ) {
			return 0;
		}
		$snapshot = self::snapshot();
		$counts   = isset( $snapshot['inbound_counts'] ) && is_array( $snapshot['inbound_counts'] ) ? $snapshot['inbound_counts'] : array();
		return max( 0, (int) ( $counts[ $post_id ] ?? 0 ) );
	}

	public static function orphan_assessment( int $post_id ): array {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 ) {
			return self::default_orphan_assessment();
		}
		$snapshot     = self::snapshot();
		$assessments  = isset( $snapshot['orphan_assessments'] ) && is_array( $snapshot['orphan_assessments'] ) ? $snapshot['orphan_assessments'] : array();
		if ( isset( $assessments[ $post_id ] ) && is_array( $assessments[ $post_id ] ) ) {
			return wp_parse_args( $assessments[ $post_id ], self::default_orphan_assessment() );
		}
		return self::default_orphan_assessment();
	}

	public static function stale_cornerstone_queue( int $limit = 10 ): array {
		$limit    = max( 1, min( 50, absint( $limit ) ) );
		$snapshot = self::snapshot();
		$queue    = isset( $snapshot['cornerstone_queue'] ) && is_array( $snapshot['cornerstone_queue'] ) ? $snapshot['cornerstone_queue'] : array();
		return array_slice( $queue, 0, $limit );
	}

	public static function remediation_queue( int $limit = 12 ): array {
		$limit    = max( 1, min( 100, absint( $limit ) ) );
		$snapshot = self::snapshot();
		$queue    = isset( $snapshot['remediation_queue'] ) && is_array( $snapshot['remediation_queue'] ) ? $snapshot['remediation_queue'] : array();
		return array_slice( $queue, 0, $limit );
	}

	private static function build_snapshot( int $max_posts ): array {
		if ( ! function_exists( 'get_posts' ) || ! function_exists( 'get_permalink' ) ) {
			return self::empty_snapshot( $max_posts );
		}

		$post_types = function_exists( 'get_post_types' ) ? get_post_types( array( 'public' => true ), 'names' ) : array( 'post', 'page' );
		if ( is_array( $post_types ) ) {
			unset( $post_types['attachment'], $post_types['revision'], $post_types['nav_menu_item'] );
			$post_types = array_values( array_filter( array_map( 'sanitize_key', $post_types ) ) );
		}
		if ( empty( $post_types ) ) {
			$post_types = array( 'post', 'page' );
		}

		$post_ids = get_posts(
			array(
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				'posts_per_page' => $max_posts,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		$post_ids = array_values( array_filter( array_map( 'absint', (array) $post_ids ) ) );
		if ( empty( $post_ids ) ) {
			return self::empty_snapshot( $max_posts );
		}

		$nodes          = array();
		$path_to_post   = array();
		$inbound_counts = array();
		$outbound_counts = array();

		foreach ( $post_ids as $post_id ) {
			$path = self::normalize_path( (string) get_permalink( $post_id ) );
			if ( '' !== $path ) {
				$path_to_post[ $path ] = $post_id;
			}
			$nodes[ $post_id ] = array(
				'post_id'      => $post_id,
				'post_type'    => function_exists( 'get_post_type' ) ? (string) get_post_type( $post_id ) : 'post',
				'path'         => $path,
				'cornerstone'  => function_exists( 'get_post_meta' ) ? ( '1' === (string) get_post_meta( $post_id, 'ogs_seo_cornerstone', true ) ) : false,
				'modified_gmt' => function_exists( 'get_post_modified_time' ) ? (string) get_post_modified_time( 'Y-m-d H:i:s', true, $post_id ) : '',
			);
			$inbound_counts[ $post_id ]  = 0;
			$outbound_counts[ $post_id ] = 0;
		}

		$edge_map = array();
		foreach ( $post_ids as $source_id ) {
			if ( function_exists( 'get_post_field' ) ) {
				$content = (string) get_post_field( 'post_content', $source_id );
			} else {
				$content = '';
			}
			if ( '' === trim( $content ) ) {
				continue;
			}
			$targets = self::extract_internal_paths( $content );
			foreach ( $targets as $target_path ) {
				if ( ! isset( $path_to_post[ $target_path ] ) ) {
					continue;
				}
				$target_id = (int) $path_to_post[ $target_path ];
				if ( $target_id <= 0 || $target_id === $source_id ) {
					continue;
				}
				$edge_key = $source_id . ':' . $target_id;
				if ( isset( $edge_map[ $edge_key ] ) ) {
					continue;
				}
				$edge_map[ $edge_key ] = true;
				++$outbound_counts[ $source_id ];
				++$inbound_counts[ $target_id ];
			}
		}

		$coverage = count( $post_ids ) < $max_posts ? 1.0 : 0.75;
		$orphan_assessments = array();
		$cornerstone_queue  = array();
		$orphan_clusters    = array();
		$remediation_queue  = array();
		foreach ( $post_ids as $post_id ) {
			$inbound  = (int) ( $inbound_counts[ $post_id ] ?? 0 );
			$outbound = (int) ( $outbound_counts[ $post_id ] ?? 0 );
			$status   = 'connected';
			$confidence = 'low';
			if ( $inbound <= 0 ) {
				$status     = 'orphan';
				$confidence = $coverage >= 0.95 ? 'high' : 'medium';
			} elseif ( 1 === $inbound ) {
				$status     = 'weak';
				$confidence = 'medium';
			}
			$cluster_score = min( 100, ( $inbound * 28 ) + ( $outbound * 10 ) + ( ! empty( $nodes[ $post_id ]['cornerstone'] ) ? 12 : 0 ) );
			$orphan_assessments[ $post_id ] = array(
				'status'       => $status,
				'confidence'   => $confidence,
				'inbound'      => $inbound,
				'outbound'     => $outbound,
				'cluster_score' => (int) $cluster_score,
				'coverage'     => $coverage,
			);
			$cluster_key = (string) ( $nodes[ $post_id ]['post_type'] ?? 'unknown' ) . ':' . $status;
			if ( ! isset( $orphan_clusters[ $cluster_key ] ) ) {
				$orphan_clusters[ $cluster_key ] = array(
					'cluster'         => $cluster_key,
					'post_type'       => (string) ( $nodes[ $post_id ]['post_type'] ?? 'unknown' ),
					'status'          => $status,
					'count'           => 0,
					'avg_cluster_score' => 0,
					'high_confidence' => 0,
				);
			}
			$orphan_clusters[ $cluster_key ]['count']++;
			$orphan_clusters[ $cluster_key ]['avg_cluster_score'] += $cluster_score;
			if ( 'high' === $confidence ) {
				$orphan_clusters[ $cluster_key ]['high_confidence']++;
			}

			if ( in_array( $status, array( 'orphan', 'weak' ), true ) ) {
				$priority = 20;
				$reasons  = array();
				if ( 'orphan' === $status ) {
					$priority += 45;
					$reasons[] = __( 'No inbound internal links detected', 'open-growth-seo' );
				}
				if ( $inbound <= 1 ) {
					$priority += 20;
					$reasons[] = __( 'Low inbound support', 'open-growth-seo' );
				}
				if ( ! empty( $nodes[ $post_id ]['cornerstone'] ) ) {
					$priority += 25;
					$reasons[] = __( 'Cornerstone content requires stronger linking support', 'open-growth-seo' );
				}
				$days_since_modified = self::days_since_modified( (string) ( $nodes[ $post_id ]['modified_gmt'] ?? '' ) );
				if ( $days_since_modified > 365 ) {
					$priority += 15;
					$reasons[] = __( 'Stale content', 'open-growth-seo' );
				}
				$remediation_queue[] = array(
					'post_id'             => $post_id,
					'post_type'           => (string) ( $nodes[ $post_id ]['post_type'] ?? '' ),
					'status'              => $status,
					'confidence'          => $confidence,
					'priority'            => min( 100, $priority ),
					'inbound'             => $inbound,
					'outbound'            => $outbound,
					'days_since_modified' => $days_since_modified,
					'reasons'             => $reasons,
				);
			}

			if ( empty( $nodes[ $post_id ]['cornerstone'] ) ) {
				continue;
			}
			$days_since_modified = self::days_since_modified( (string) ( $nodes[ $post_id ]['modified_gmt'] ?? '' ) );
			$score               = 0;
			$reasons             = array();
			if ( $days_since_modified > 365 ) {
				$score += 45;
				$reasons[] = __( 'stale content', 'open-growth-seo' );
			}
			if ( $inbound < 3 ) {
				$score += 35;
				$reasons[] = __( 'weak internal support', 'open-growth-seo' );
			}
			if ( 0 === $inbound ) {
				$score += 15;
				$reasons[] = __( 'orphan risk', 'open-growth-seo' );
			}
			if ( $score > 0 ) {
				$cornerstone_queue[] = array(
					'post_id'             => $post_id,
					'score'               => $score,
					'inbound'             => $inbound,
					'days_since_modified' => $days_since_modified,
					'reasons'             => $reasons,
				);
			}
		}

		usort(
			$cornerstone_queue,
			static function ( array $left, array $right ): int {
				return ( $right['score'] ?? 0 ) <=> ( $left['score'] ?? 0 );
			}
		);
		usort(
			$remediation_queue,
			static function ( array $left, array $right ): int {
				return ( $right['priority'] ?? 0 ) <=> ( $left['priority'] ?? 0 );
			}
		);
		foreach ( $orphan_clusters as $cluster_key => $cluster ) {
			$count = max( 1, (int) ( $cluster['count'] ?? 1 ) );
			$orphan_clusters[ $cluster_key ]['avg_cluster_score'] = (int) round( (float) ( $cluster['avg_cluster_score'] ?? 0 ) / $count );
		}

		return array(
			'generated_at'        => time(),
			'expires_at'          => time() + self::CACHE_TTL,
			'post_count'          => count( $post_ids ),
			'max_posts'           => $max_posts,
			'coverage'            => $coverage,
			'inbound_counts'      => $inbound_counts,
			'outbound_counts'     => $outbound_counts,
			'orphan_assessments'  => $orphan_assessments,
			'orphan_clusters'     => array_values( $orphan_clusters ),
			'cornerstone_queue'   => $cornerstone_queue,
			'remediation_queue'   => $remediation_queue,
			'edge_count'          => count( $edge_map ),
		);
	}

	private static function extract_internal_paths( string $content ): array {
		$paths = array();
		if ( ! preg_match_all( '/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $content, $matches ) ) {
			return $paths;
		}
		foreach ( (array) ( $matches[1] ?? array() ) as $href ) {
			$path = self::normalize_internal_target_path( (string) $href );
			if ( '' === $path ) {
				continue;
			}
			$paths[ $path ] = true;
		}
		return array_keys( $paths );
	}

	private static function normalize_internal_target_path( string $url ): string {
		$url = trim( $url );
		if ( '' === $url || '#' === $url || str_starts_with( $url, 'mailto:' ) || str_starts_with( $url, 'tel:' ) ) {
			return '';
		}
		if ( str_starts_with( $url, '/' ) ) {
			$url = home_url( $url );
		}
		$parsed = wp_parse_url( $url );
		if ( ! is_array( $parsed ) || empty( $parsed['host'] ) ) {
			return '';
		}
		$home = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $home ) || empty( $home['host'] ) ) {
			return '';
		}
		if ( strtolower( (string) $parsed['host'] ) !== strtolower( (string) $home['host'] ) ) {
			return '';
		}
		$path = isset( $parsed['path'] ) ? (string) $parsed['path'] : '/';
		return self::normalize_path( $path );
	}

	private static function normalize_path( string $url_or_path ): string {
		$url_or_path = trim( $url_or_path );
		if ( '' === $url_or_path ) {
			return '';
		}
		$path = $url_or_path;
		if ( str_contains( $url_or_path, '://' ) ) {
			$path = (string) wp_parse_url( $url_or_path, PHP_URL_PATH );
		}
		if ( '' === $path ) {
			$path = '/';
		}
		$path = '/' . ltrim( $path, '/' );
		if ( '/' !== $path ) {
			$path = rtrim( $path, '/' );
		}
		return $path;
	}

	private static function days_since_modified( string $modified_gmt ): int {
		$modified_gmt = trim( $modified_gmt );
		if ( '' === $modified_gmt ) {
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

	private static function default_orphan_assessment(): array {
		return array(
			'status'        => 'connected',
			'confidence'    => 'low',
			'inbound'       => 0,
			'outbound'      => 0,
			'cluster_score' => 0,
			'coverage'      => 0,
		);
	}

	private static function empty_snapshot( int $max_posts ): array {
		return array(
			'generated_at'        => time(),
			'expires_at'          => time() + self::CACHE_TTL,
			'post_count'          => 0,
			'max_posts'           => $max_posts,
			'coverage'            => 0,
			'inbound_counts'      => array(),
			'outbound_counts'     => array(),
			'orphan_assessments'  => array(),
			'orphan_clusters'     => array(),
			'cornerstone_queue'   => array(),
			'remediation_queue'   => array(),
			'edge_count'          => 0,
		);
	}

	private static function is_cache_fresh( $cached ): bool {
		if ( ! is_array( $cached ) ) {
			return false;
		}
		if ( empty( $cached['expires_at'] ) ) {
			return false;
		}
		return (int) $cached['expires_at'] > time();
	}
}
