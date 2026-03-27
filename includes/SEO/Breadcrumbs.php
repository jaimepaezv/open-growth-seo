<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\SEO;

use OpenGrowthSolutions\OpenGrowthSEO\Support\Settings;

defined( 'ABSPATH' ) || exit;

class Breadcrumbs {
	public function register(): void {
		add_shortcode( 'ogs_seo_breadcrumbs', array( $this, 'shortcode' ) );
	}

	public function shortcode( array $atts = array() ): string {
		if ( ! Settings::get( 'breadcrumbs_enabled', 1 ) ) {
			return '';
		}

		$atts = shortcode_atts(
			array(
				'separator'       => '',
				'include_current' => '',
			),
			$atts,
			'ogs_seo_breadcrumbs'
		);

		return $this->render(
			array(
				'separator'       => (string) $atts['separator'],
				'include_current' => '' !== (string) $atts['include_current'] ? ! empty( $atts['include_current'] ) : null,
			)
		);
	}

	public function render( array $args = array() ): string {
		if ( ! Settings::get( 'breadcrumbs_enabled', 1 ) ) {
			return '';
		}

		$items = self::trail_items();
		if ( empty( $items ) ) {
			return '';
		}

		$include_current = array_key_exists( 'include_current', $args ) && null !== $args['include_current']
			? (bool) $args['include_current']
			: (bool) Settings::get( 'breadcrumbs_include_current', 1 );
		if ( ! $include_current && count( $items ) > 1 ) {
			array_pop( $items );
		}
		if ( count( $items ) < 2 ) {
			return '';
		}

		$separator = isset( $args['separator'] ) && '' !== trim( (string) $args['separator'] )
			? trim( (string) $args['separator'] )
			: trim( (string) Settings::get( 'breadcrumbs_separator', '/' ) );
		if ( '' === $separator ) {
			$separator = '/';
		}

		ob_start();
		echo '<nav class="ogs-seo-breadcrumbs" aria-label="' . esc_attr__( 'Breadcrumb', 'open-growth-seo' ) . '">';
		echo '<ol class="ogs-seo-breadcrumbs__list">';
		foreach ( $items as $index => $item ) {
			$name      = isset( $item['name'] ) ? (string) $item['name'] : '';
			$url       = isset( $item['url'] ) ? (string) $item['url'] : '';
			$is_last   = $index === ( count( $items ) - 1 );
			$is_linked = '' !== $url && ! $is_last;
			echo '<li class="ogs-seo-breadcrumbs__item">';
			if ( $is_linked ) {
				echo '<a href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a>';
			} else {
				echo '<span' . ( $is_last ? ' aria-current="page"' : '' ) . '>' . esc_html( $name ) . '</span>';
			}
			if ( ! $is_last ) {
				echo '<span class="ogs-seo-breadcrumbs__separator" aria-hidden="true"> ' . esc_html( $separator ) . ' </span>';
			}
			echo '</li>';
		}
		echo '</ol>';
		echo '</nav>';
		return (string) ob_get_clean();
	}

	public static function trail_items( int $forced_post_id = 0 ): array {
		$items = array();
		$home  = home_url( '/' );
		$items[] = array(
			'name' => self::home_label(),
			'url'  => $home,
		);

		$post_id = absint( $forced_post_id );
		if ( $post_id > 0 || is_singular() ) {
			$post_id = $post_id > 0 ? $post_id : absint( get_queried_object_id() );
			$items   = array_merge( $items, self::singular_items( $post_id ) );
		} elseif ( is_home() ) {
			$posts_page = absint( get_option( 'page_for_posts', 0 ) );
			if ( $posts_page > 0 ) {
				$items[] = array(
					'name' => (string) get_the_title( $posts_page ),
					'url'  => (string) get_permalink( $posts_page ),
				);
			}
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$items = array_merge( $items, self::term_archive_items() );
		} elseif ( is_post_type_archive() ) {
			$post_type = get_query_var( 'post_type' );
			$post_type = is_array( $post_type ) ? (string) reset( $post_type ) : (string) $post_type;
			$object    = '' !== $post_type ? get_post_type_object( $post_type ) : null;
			if ( $object ) {
				$items[] = array(
					'name' => (string) ( $object->labels->singular_name ?? $object->label ?? $post_type ),
					'url'  => (string) get_post_type_archive_link( $post_type ),
				);
			}
		}

		$items = array_values(
			array_filter(
				$items,
				static function ( array $item ): bool {
					return ! empty( $item['name'] );
				}
			)
		);

		return apply_filters( 'ogs_seo_breadcrumb_items', $items, $forced_post_id );
	}

	public static function primary_term_option_records( int $post_id ): array {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 ) {
			return array();
		}

		$terms = self::candidate_terms( $post_id );
		if ( empty( $terms ) ) {
			return array();
		}

		$options = array(
			array(
				'value' => '',
				'label' => __( 'Automatic (recommended)', 'open-growth-seo' ),
			),
		);
		foreach ( $terms as $term ) {
			$options[] = array(
				'value' => (string) (int) $term->term_id,
				'label' => self::term_option_label( $term ),
			);
		}

		return $options;
	}

	public static function sanitize_primary_term_for_post( string $value, int $post_id ): string {
		$term_id = absint( $value );
		if ( $term_id <= 0 ) {
			return '';
		}

		foreach ( self::candidate_terms( $post_id ) as $term ) {
			if ( (int) $term->term_id === $term_id ) {
				return (string) $term_id;
			}
		}

		return '';
	}

	private static function singular_items( int $post_id ): array {
		if ( $post_id <= 0 ) {
			return array();
		}

		$items     = array();
		$post_type = (string) get_post_type( $post_id );
		if ( 'page' === $post_type ) {
			$ancestors = array_reverse( array_map( 'absint', get_post_ancestors( $post_id ) ) );
			foreach ( $ancestors as $ancestor_id ) {
				$items[] = array(
					'name' => (string) get_the_title( $ancestor_id ),
					'url'  => (string) get_permalink( $ancestor_id ),
				);
			}
		} else {
			$items = array_merge( $items, self::post_type_archive_items( $post_id ) );
			$items = array_merge( $items, self::term_chain_for_post( $post_id ) );
		}

		$items[] = array(
			'name' => (string) get_the_title( $post_id ),
			'url'  => (string) get_permalink( $post_id ),
		);

		return $items;
	}

	private static function post_type_archive_items( int $post_id ): array {
		$items     = array();
		$post_type = (string) get_post_type( $post_id );
		if ( 'post' === $post_type ) {
			$posts_page = absint( get_option( 'page_for_posts', 0 ) );
			if ( $posts_page > 0 ) {
				$items[] = array(
					'name' => (string) get_the_title( $posts_page ),
					'url'  => (string) get_permalink( $posts_page ),
				);
			}
			return $items;
		}

		$object = get_post_type_object( $post_type );
		if ( $object && ! empty( $object->has_archive ) ) {
			$archive = get_post_type_archive_link( $post_type );
			if ( is_string( $archive ) && '' !== $archive ) {
				$items[] = array(
					'name' => (string) ( $object->labels->singular_name ?? $object->label ?? $post_type ),
					'url'  => $archive,
				);
			}
		}

		return $items;
	}

	private static function term_archive_items(): array {
		$items = array();
		$term  = get_queried_object();
		if ( ! is_object( $term ) || empty( $term->term_id ) || empty( $term->taxonomy ) ) {
			return $items;
		}

		$ancestors = array_reverse( array_map( 'absint', get_ancestors( (int) $term->term_id, (string) $term->taxonomy, 'taxonomy' ) ) );
		foreach ( $ancestors as $ancestor_id ) {
			$ancestor = get_term( $ancestor_id, (string) $term->taxonomy );
			if ( ! $ancestor || is_wp_error( $ancestor ) ) {
				continue;
			}
			$link = get_term_link( $ancestor );
			if ( is_wp_error( $link ) ) {
				continue;
			}
			$items[] = array(
				'name' => (string) $ancestor->name,
				'url'  => (string) $link,
			);
		}

		$link = get_term_link( $term );
		if ( ! is_wp_error( $link ) ) {
			$items[] = array(
				'name' => (string) $term->name,
				'url'  => (string) $link,
			);
		}

		return $items;
	}

	private static function term_chain_for_post( int $post_id ): array {
		$term = self::preferred_term_for_post( $post_id );
		if ( ! $term ) {
			return array();
		}

		$items     = array();
		$ancestors = array_reverse( array_map( 'absint', get_ancestors( (int) $term->term_id, (string) $term->taxonomy, 'taxonomy' ) ) );
		foreach ( $ancestors as $ancestor_id ) {
			$ancestor = get_term( $ancestor_id, (string) $term->taxonomy );
			if ( ! $ancestor || is_wp_error( $ancestor ) ) {
				continue;
			}
			$link = get_term_link( $ancestor );
			if ( is_wp_error( $link ) ) {
				continue;
			}
			$items[] = array(
				'name' => (string) $ancestor->name,
				'url'  => (string) $link,
			);
		}

		$link = get_term_link( $term );
		if ( ! is_wp_error( $link ) ) {
			$items[] = array(
				'name' => (string) $term->name,
				'url'  => (string) $link,
			);
		}

		return $items;
	}

	private static function preferred_term_for_post( int $post_id ) {
		$post_id    = absint( $post_id );
		$primary_id = absint( (string) get_post_meta( $post_id, 'ogs_seo_primary_term', true ) );
		$terms      = self::candidate_terms( $post_id );
		if ( empty( $terms ) ) {
			return null;
		}

		if ( $primary_id > 0 ) {
			foreach ( $terms as $term ) {
				if ( (int) $term->term_id === $primary_id ) {
					return $term;
				}
			}
		}

		return $terms[0];
	}

	private static function candidate_terms( int $post_id ): array {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 ) {
			return array();
		}

		$terms       = array();
		$taxonomies  = get_object_taxonomies( get_post_type( $post_id ) ?: 'post', 'objects' );
		$prioritized = array();
		foreach ( $taxonomies as $taxonomy => $taxonomy_object ) {
			if ( empty( $taxonomy_object->public ) || empty( $taxonomy_object->hierarchical ) ) {
				continue;
			}
			$prioritized[] = sanitize_key( (string) $taxonomy );
		}

		usort(
			$prioritized,
			static function ( string $left, string $right ): int {
				$weights = array(
					'category'    => 0,
					'product_cat' => 1,
				);
				return ( $weights[ $left ] ?? 10 ) <=> ( $weights[ $right ] ?? 10 );
			}
		);

		foreach ( $prioritized as $taxonomy ) {
			$assigned = get_the_terms( $post_id, $taxonomy );
			if ( empty( $assigned ) || is_wp_error( $assigned ) ) {
				continue;
			}
			foreach ( $assigned as $term ) {
				$terms[ (int) $term->term_id ] = $term;
			}
		}

		return array_values( $terms );
	}

	private static function term_option_label( \WP_Term $term ): string {
		$taxonomy_object = get_taxonomy( (string) $term->taxonomy );
		$prefix          = $taxonomy_object && ! empty( $taxonomy_object->labels->singular_name )
			? (string) $taxonomy_object->labels->singular_name
			: ucfirst( str_replace( '_', ' ', (string) $term->taxonomy ) );

		$names = array( (string) $term->name );
		$ancestors = array_reverse( array_map( 'absint', get_ancestors( (int) $term->term_id, (string) $term->taxonomy, 'taxonomy' ) ) );
		foreach ( $ancestors as $ancestor_id ) {
			$ancestor = get_term( $ancestor_id, (string) $term->taxonomy );
			if ( ! $ancestor || is_wp_error( $ancestor ) ) {
				continue;
			}
			array_unshift( $names, (string) $ancestor->name );
		}

		return $prefix . ': ' . implode( ' > ', $names );
	}

	private static function home_label(): string {
		$label = trim( (string) Settings::get( 'breadcrumbs_home_label', 'Home' ) );
		return '' !== $label ? $label : __( 'Home', 'open-growth-seo' );
	}
}
