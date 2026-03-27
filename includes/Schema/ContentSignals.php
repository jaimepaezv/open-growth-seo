<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\Schema;

defined( 'ABSPATH' ) || exit;

class ContentSignals {
	/**
	 * @return array<string, mixed>
	 */
	public static function analyze( string $content ): array {
		$normalized    = self::normalize_content( $content );
		$visible       = self::strip_hidden_fragments( $normalized );
		$block_signals = self::extract_block_signals( $content );
		$plain_source  = '' !== trim( (string) ( $block_signals['visible_text'] ?? '' ) )
			? (string) $block_signals['visible_text']
			: $visible;
		$plain_text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $plain_source ) ) );
		$faq_pairs  = ! empty( $block_signals['faq_pairs'] ) && is_array( $block_signals['faq_pairs'] )
			? (array) $block_signals['faq_pairs']
			: self::extract_faq_pairs( $normalized );
		$qa_pairs   = ! empty( $block_signals['qa_pairs'] ) && is_array( $block_signals['qa_pairs'] )
			? (array) $block_signals['qa_pairs']
			: self::extract_qa_pairs( $normalized, $faq_pairs );
		$recipe = ! empty( $block_signals['recipe'] ) && is_array( $block_signals['recipe'] )
			? (array) $block_signals['recipe']
			: self::extract_recipe_sections( $normalized );
		$video_urls = array_values(
			array_unique(
				array_merge(
					(array) ( $block_signals['video_urls'] ?? array() ),
					self::extract_video_urls( $normalized )
				)
			)
		);

		return array(
			'plain_text'              => $plain_text,
			'word_count'              => self::word_count( $plain_text ),
			'has_block_markup'        => str_contains( $normalized, '<!-- wp:' ),
			'has_hidden_content'      => self::has_hidden_content( $normalized ),
			'faq_pairs'               => $faq_pairs,
			'faq_pair_count'          => count( $faq_pairs ),
			'qa_pairs'                => $qa_pairs,
			'qa_pair_count'           => count( $qa_pairs ),
			'recipe_ingredients'      => $recipe['ingredients'],
			'recipe_ingredient_count' => count( $recipe['ingredients'] ),
			'recipe_steps'            => $recipe['steps'],
			'recipe_step_count'       => count( $recipe['steps'] ),
			'video_urls'              => $video_urls,
			'faq_representative'      => count( $faq_pairs ) >= 2,
			'qa_representative'       => count( $qa_pairs ) >= 1,
			'recipe_representative'   => count( $recipe['ingredients'] ) >= 2 && count( $recipe['steps'] ) >= 2,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function extract_block_signals( string $content ): array {
		$empty = array(
			'visible_text' => '',
			'faq_pairs'    => array(),
			'qa_pairs'     => array(),
			'recipe'       => array(
				'ingredients' => array(),
				'steps'       => array(),
			),
			'video_urls'   => array(),
		);

		if ( ! function_exists( 'parse_blocks' ) || ! str_contains( $content, '<!-- wp:' ) ) {
			return $empty;
		}

		$blocks = parse_blocks( $content );
		if ( ! is_array( $blocks ) || empty( $blocks ) ) {
			return $empty;
		}

		$flat_blocks = self::flatten_blocks( $blocks );
		if ( empty( $flat_blocks ) ) {
			return $empty;
		}

		$visible_text = array();
		foreach ( $flat_blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$text = self::block_visible_text( $block );
			if ( '' !== $text ) {
				$visible_text[] = $text;
			}
		}

		return array(
			'visible_text' => implode( ' ', $visible_text ),
			'faq_pairs'    => self::extract_faq_pairs_from_blocks( $flat_blocks ),
			'qa_pairs'     => self::extract_qa_pairs_from_blocks( $flat_blocks ),
			'recipe'       => self::extract_recipe_sections_from_blocks( $flat_blocks ),
			'video_urls'   => self::extract_video_urls_from_blocks( $flat_blocks ),
		);
	}

	private static function normalize_content( string $content ): string {
		$content = str_replace( array( "\r\n", "\r" ), "\n", $content );
		$content = preg_replace( '/<!--\s+\/?wp:[^>]*-->/', "\n", $content );
		return (string) $content;
	}

	private static function strip_hidden_fragments( string $content ): string {
		$patterns = array(
			'/<(div|section|aside|details|p|span|ul|ol|li|figure|blockquote)[^>]*(?:hidden|aria-hidden\s*=\s*["\']true["\']|style\s*=\s*["\'][^"\']*display\s*:\s*none[^"\']*["\'])[^>]*>.*?<\/\\1>/is',
			'/<(div|section|aside|details|p|span|ul|ol|li|figure|blockquote)[^>]*class\s*=\s*["\'][^"\']*(?:screen-reader-text|sr-only|hidden)[^"\']*["\'][^>]*>.*?<\/\\1>/is',
		);
		foreach ( $patterns as $pattern ) {
			$content = preg_replace( $pattern, ' ', $content );
		}
		return (string) $content;
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 * @return array<int, array<string, mixed>>
	 */
	private static function flatten_blocks( array $blocks ): array {
		$flat = array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$flat[] = $block;
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$flat = array_merge( $flat, self::flatten_blocks( $block['innerBlocks'] ) );
			}
		}
		return $flat;
	}

	/**
	 * @param array<string, mixed> $block
	 */
	private static function block_visible_text( array $block ): string {
		$html = '';
		if ( isset( $block['innerHTML'] ) ) {
			$html = (string) $block['innerHTML'];
		} elseif ( isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
			$html = implode( '', array_map( 'strval', $block['innerContent'] ) );
		}
		$html = self::strip_hidden_fragments( $html );
		return self::normalize_text_fragment( $html );
	}

	/**
	 * @param array<string, mixed> $block
	 */
	private static function block_name( array $block ): string {
		$name = strtolower( sanitize_text_field( (string) ( $block['blockName'] ?? '' ) ) );
		if ( '' !== $name && ! str_contains( $name, '/' ) ) {
			$name = 'core/' . ltrim( $name, '/' );
		}
		return $name;
	}

	/**
	 * @param array<string, mixed> $block
	 * @return array<int, string>
	 */
	private static function block_list_items( array $block, int $limit, int $minimum_length ): array {
		$html = isset( $block['innerHTML'] ) ? (string) $block['innerHTML'] : '';
		return self::extract_list_items_from_fragment( $html, $limit, $minimum_length );
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 * @return array<int, array{q:string, a:string}>
	 */
	private static function extract_faq_pairs_from_blocks( array $blocks ): array {
		$pairs = array();
		$count = count( $blocks );
		for ( $index = 0; $index < $count; ++$index ) {
			$block = $blocks[ $index ];
			if ( ! is_array( $block ) ) {
				continue;
			}
			$question = '';
			$text     = self::block_visible_text( $block );
			$name     = self::block_name( $block );
			if ( self::is_heading_block( $name ) && '' !== $text && str_ends_with( $text, '?' ) ) {
				$question = $text;
			} elseif ( preg_match( '/^Q[:\-]\s*(.+)$/i', $text, $match ) ) {
				$question = self::normalize_text_fragment( (string) ( $match[1] ?? '' ) );
			}
			if ( '' === $question ) {
				continue;
			}
			$answer = self::following_block_text( $blocks, $index + 1 );
			if ( '' === $answer ) {
				continue;
			}
			$pairs[] = array(
				'q' => $question,
				'a' => $answer,
			);
		}
		return self::dedupe_pairs( $pairs );
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 * @return array<int, array{q:string, a:string}>
	 */
	private static function extract_qa_pairs_from_blocks( array $blocks ): array {
		$pairs = array();
		$count = count( $blocks );
		for ( $index = 0; $index < $count; ++$index ) {
			$block = $blocks[ $index ];
			if ( ! is_array( $block ) ) {
				continue;
			}
			$text = self::block_visible_text( $block );
			if ( ! preg_match( '/^Q(?:uestion)?[:\-]\s*(.+)$/i', $text, $question_match ) ) {
				continue;
			}
			$answer = self::following_block_text( $blocks, $index + 1 );
			if ( preg_match( '/^A(?:nswer)?[:\-]\s*(.+)$/i', $answer, $answer_match ) ) {
				$answer = self::normalize_text_fragment( (string) ( $answer_match[1] ?? '' ) );
			}
			if ( '' === $answer ) {
				continue;
			}
			$pairs[] = array(
				'q' => self::normalize_text_fragment( (string) ( $question_match[1] ?? '' ) ),
				'a' => $answer,
			);
		}
		return self::dedupe_pairs( $pairs );
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 * @return array<string, array<int, string>>
	 */
	private static function extract_recipe_sections_from_blocks( array $blocks ): array {
		$ingredients = array();
		$steps       = array();
		$section     = '';

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$name = self::block_name( $block );
			$text = self::block_visible_text( $block );
			if ( self::is_heading_block( $name ) ) {
				$heading = strtolower( $text );
				if ( in_array( $heading, array( 'ingredients', 'ingredient list' ), true ) ) {
					$section = 'ingredients';
					continue;
				}
				if ( in_array( $heading, array( 'instructions', 'method', 'directions' ), true ) ) {
					$section = 'steps';
					continue;
				}
				$section = '';
				continue;
			}
			if ( '' === $section ) {
				continue;
			}

			if ( 'ingredients' === $section ) {
				$items = self::is_list_like_block( $name )
					? self::block_list_items( $block, 30, 2 )
					: self::extract_text_items( $text, 30, 2 );
				$ingredients = array_merge( $ingredients, $items );
				continue;
			}

			$items = self::is_list_like_block( $name )
				? self::block_list_items( $block, 20, 4 )
				: self::extract_text_items( $text, 20, 4 );
			$steps = array_merge( $steps, $items );
		}

		return array(
			'ingredients' => array_values( array_unique( array_filter( $ingredients ) ) ),
			'steps'       => array_values( array_unique( array_filter( $steps ) ) ),
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 * @return array<int, string>
	 */
	private static function extract_video_urls_from_blocks( array $blocks ): array {
		$urls = array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$name = self::block_name( $block );
			if ( ! in_array( $name, array( 'core/video', 'core/embed', 'core-embed', 'core/embed-youtube', 'core/embed-vimeo' ), true ) ) {
				continue;
			}
			$html = isset( $block['innerHTML'] ) ? (string) $block['innerHTML'] : '';
			$urls = array_merge( $urls, self::extract_video_urls( $html ) );
		}
		return array_values( array_unique( array_filter( $urls ) ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 */
	private static function following_block_text( array $blocks, int $start_index ): string {
		$total = count( $blocks );
		for ( $index = $start_index; $index < $total; ++$index ) {
			$block = $blocks[ $index ];
			if ( ! is_array( $block ) ) {
				continue;
			}
			$name = self::block_name( $block );
			$text = self::block_visible_text( $block );
			if ( '' === $text ) {
				continue;
			}
			if ( self::is_heading_block( $name ) ) {
				return '';
			}
			return $text;
		}
		return '';
	}

	private static function is_heading_block( string $name ): bool {
		return in_array( $name, array( 'core/heading', 'core-heading' ), true );
	}

	private static function is_list_like_block( string $name ): bool {
		return in_array( $name, array( 'core/list', 'core-list' ), true );
	}

	private static function has_hidden_content( string $content ): bool {
		return (bool) preg_match(
			'/(hidden|aria-hidden\s*=\s*["\']true["\']|display\s*:\s*none|class\s*=\s*["\'][^"\']*(?:screen-reader-text|sr-only|hidden)[^"\']*["\'])/i',
			$content
		);
	}

	/**
	 * @return array<int, array{q:string, a:string}>
	 */
	private static function extract_faq_pairs( string $content ): array {
		$pairs = array();
		$visible = self::strip_hidden_fragments( $content );

		$patterns = array(
			'/<details[^>]*>\s*<summary[^>]*>(.*?)<\/summary>\s*(.*?)<\/details>/is',
			'/<h[2-4][^>]*>([^<]{5,}\?)<\/h[2-4]>\s*(?:<p[^>]*>|<div[^>]*>|<ul[^>]*>|<ol[^>]*>)(.*?)(?:<\/p>|<\/div>|<\/ul>|<\/ol>)/is',
			'/<(?:div|section)[^>]*class\s*=\s*["\'][^"\']*(?:faq[-_ ]question|schema-faq-question)[^"\']*["\'][^>]*>(.*?)<\/(?:div|section)>\s*<(?:div|section)[^>]*class\s*=\s*["\'][^"\']*(?:faq[-_ ]answer|schema-faq-answer)[^"\']*["\'][^>]*>(.*?)<\/(?:div|section)>/is',
		);

		foreach ( $patterns as $pattern ) {
			if ( ! preg_match_all( $pattern, $visible, $matches, PREG_SET_ORDER ) ) {
				continue;
			}
			foreach ( $matches as $match ) {
				$q = self::normalize_text_fragment( (string) ( $match[1] ?? '' ) );
				$a = self::normalize_text_fragment( (string) ( $match[2] ?? '' ) );
				if ( '' === $q || '' === $a ) {
					continue;
				}
				$pairs[] = array(
					'q' => $q,
					'a' => $a,
				);
			}
		}

		if ( empty( $pairs ) && preg_match_all( '/\bQ[:\-]\s*(.+?)\s*\bA[:\-]\s*(.+?)(?=(\bQ[:\-])|$)/is', wp_strip_all_tags( $visible ), $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$q = self::normalize_text_fragment( (string) ( $match[1] ?? '' ) );
				$a = self::normalize_text_fragment( (string) ( $match[2] ?? '' ) );
				if ( '' === $q || '' === $a ) {
					continue;
				}
				$pairs[] = array(
					'q' => $q,
					'a' => $a,
				);
			}
		}

		return self::dedupe_pairs( $pairs );
	}

	/**
	 * @param array<int, array{q:string, a:string}> $faq_pairs
	 * @return array<int, array{q:string, a:string}>
	 */
	private static function extract_qa_pairs( string $content, array $faq_pairs ): array {
		$visible = self::strip_hidden_fragments( $content );
		$pairs   = array();
		$plain   = wp_strip_all_tags( $visible );

		if ( preg_match_all( '/\bQ(?:uestion)?[:\-]\s*(.+?)\s*\bA(?:nswer)?[:\-]\s*(.+?)(?=(\bQ(?:uestion)?[:\-])|$)/is', $plain, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$q = self::normalize_text_fragment( (string) ( $match[1] ?? '' ) );
				$a = self::normalize_text_fragment( (string) ( $match[2] ?? '' ) );
				if ( '' === $q || '' === $a ) {
					continue;
				}
				$pairs[] = array(
					'q' => $q,
					'a' => $a,
				);
			}
		}

		if ( empty( $pairs ) && ! empty( $faq_pairs ) ) {
			$pairs[] = $faq_pairs[0];
		}

		return self::dedupe_pairs( $pairs );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	private static function extract_recipe_sections( string $content ): array {
		$visible     = self::strip_hidden_fragments( $content );
		$ingredients = array();
		$steps       = array();

		if ( preg_match( '/<h[2-4][^>]*>\s*ingredients\s*<\/h[2-4]>(.*?)(?=<h[2-4][^>]*>\s*(instructions|method|directions)\s*<\/h[2-4]>)/is', $visible, $match ) ) {
			$ingredients = self::extract_list_items_from_fragment( (string) ( $match[1] ?? '' ), 30, 2 );
		}
		if ( preg_match( '/<h[2-4][^>]*>\s*(instructions|method|directions)\s*<\/h[2-4]>(.*)$/is', $visible, $match ) ) {
			$steps = self::extract_list_items_from_fragment( (string) ( $match[2] ?? '' ), 20, 4 );
		}

		$plain = wp_strip_all_tags( $visible );
		if ( empty( $ingredients ) && preg_match( '/ingredients(.*?)(instructions|method|directions)/is', $plain, $match ) ) {
			$ingredients = self::extract_text_items( (string) ( $match[1] ?? '' ), 30, 2 );
		}
		if ( empty( $steps ) && preg_match( '/(instructions|method|directions)(.*)$/is', $plain, $match ) ) {
			$steps = self::extract_text_items( (string) ( $match[2] ?? '' ), 20, 4 );
		}

		return array(
			'ingredients' => $ingredients,
			'steps'       => $steps,
		);
	}

	/**
	 * @return array<int, string>
	 */
	private static function extract_video_urls( string $content ): array {
		$matches = array();
		preg_match_all( '/https?:\/\/(?:www\.)?(?:youtube\.com\/watch\?v=|youtu\.be\/|vimeo\.com\/)[^\s"\']+/i', $content, $matches );
		$urls = isset( $matches[0] ) && is_array( $matches[0] ) ? $matches[0] : array();
		$urls = array_map( 'esc_url_raw', array_map( 'strval', $urls ) );
		$urls = array_values( array_filter( $urls, static fn( $url ) => '' !== trim( (string) $url ) ) );
		return array_values( array_unique( $urls ) );
	}

	private static function normalize_text_fragment( string $fragment ): string {
		$fragment = html_entity_decode( $fragment, ENT_QUOTES, 'UTF-8' );
		$fragment = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $fragment ) ) );
		return sanitize_text_field( $fragment );
	}

	/**
	 * @param array<int, array{q:string, a:string}> $pairs
	 * @return array<int, array{q:string, a:string}>
	 */
	private static function dedupe_pairs( array $pairs ): array {
		$seen = array();
		$clean = array();
		foreach ( $pairs as $pair ) {
			$key = md5( strtolower( (string) ( $pair['q'] ?? '' ) ) . '|' . strtolower( (string) ( $pair['a'] ?? '' ) ) );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$clean[] = $pair;
		}
		return array_values( $clean );
	}

	/**
	 * @return array<int, string>
	 */
	private static function extract_list_items_from_fragment( string $fragment, int $limit, int $minimum_length ): array {
		$items = array();
		if ( preg_match_all( '/<li[^>]*>(.*?)<\/li>/is', $fragment, $matches ) ) {
			$items = array_map( array( __CLASS__, 'normalize_text_fragment' ), (array) ( $matches[1] ?? array() ) );
		}
		if ( empty( $items ) ) {
			$fragment = preg_replace( '/<br\s*\/?>/i', "\n", $fragment );
			$items    = self::extract_text_items( wp_strip_all_tags( (string) $fragment ), $limit, $minimum_length );
		}

		$items = array_values(
			array_filter(
				array_slice( array_unique( $items ), 0, $limit ),
				static fn( $item ) => mb_strlen( trim( (string) $item ) ) >= $minimum_length
			)
		);

		return $items;
	}

	/**
	 * @return array<int, string>
	 */
	private static function extract_text_items( string $text, int $limit, int $minimum_length ): array {
		$rows = preg_split( '/\r\n|\r|\n|(?<=\.)\s+/', (string) $text ) ?: array();
		$rows = array_map(
			static function ( $row ): string {
				$row = preg_replace( '/^\s*(?:[-*]|\d+\.)\s*/', '', (string) $row );
				return sanitize_text_field( trim( (string) $row ) );
			},
			$rows
		);

		return array_values(
			array_filter(
				array_slice( array_unique( $rows ), 0, $limit ),
				static fn( $row ) => mb_strlen( trim( (string) $row ) ) >= $minimum_length
			)
		);
	}

	private static function word_count( string $plain ): int {
		$parts = preg_split( '/\s+/', trim( $plain ) ) ?: array();
		return count( array_filter( $parts, static fn( $part ) => '' !== trim( (string) $part ) ) );
	}
}
