<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\Installation;

defined( 'ABSPATH' ) || exit;

class SiteClassifier {
	public static function classify( array $signals ): array {
		$scores  = array();
		$reasons = array();
		foreach ( self::site_types() as $type ) {
			$scores[ $type ]  = 0;
			$reasons[ $type ] = array();
		}

		$feature_plugins = isset( $signals['feature_plugins'] ) && is_array( $signals['feature_plugins'] ) ? $signals['feature_plugins'] : array();
		$post_types      = array_map( 'sanitize_key', (array) ( $signals['public_post_types'] ?? array() ) );
		$taxonomies      = array_map( 'sanitize_key', (array) ( $signals['public_taxonomies'] ?? array() ) );
		$page_slugs      = array_map( 'sanitize_title', (array) ( $signals['page_slugs'] ?? array() ) );
		$post_counts     = isset( $signals['post_counts'] ) && is_array( $signals['post_counts'] ) ? $signals['post_counts'] : array();
		$route_flags     = isset( $signals['route_flags'] ) && is_array( $signals['route_flags'] ) ? $signals['route_flags'] : array();
		$posts_count     = isset( $post_counts['post'] ) ? absint( $post_counts['post'] ) : 0;
		$pages_count     = isset( $post_counts['page'] ) ? absint( $post_counts['page'] ) : 0;
		$product_count   = isset( $post_counts['product'] ) ? absint( $post_counts['product'] ) : 0;

		$adder = static function ( string $type, int $weight, string $reason ) use ( &$scores, &$reasons ): void {
			if ( ! isset( $scores[ $type ] ) ) {
				return;
			}
			$scores[ $type ] += $weight;
			$reasons[ $type ][] = $reason;
		};

		if ( ! empty( $feature_plugins['woocommerce'] ) ) {
			$adder( 'ecommerce', 65, __( 'WooCommerce is active.', 'open-growth-seo' ) );
			$adder( 'catalog', 20, __( 'WooCommerce product data is available.', 'open-growth-seo' ) );
		}
		if ( ! empty( $feature_plugins['marketplace'] ) ) {
			$adder( 'marketplace', 75, __( 'A marketplace extension is active.', 'open-growth-seo' ) );
			$adder( 'ecommerce', 20, __( 'Marketplace functionality implies transactional catalog flows.', 'open-growth-seo' ) );
		}
		if ( ! empty( $feature_plugins['membership'] ) ) {
			$adder( 'membership', 70, __( 'A membership plugin is active.', 'open-growth-seo' ) );
			$adder( 'saas', 15, __( 'Membership gates often indicate recurring access flows.', 'open-growth-seo' ) );
		}
		if ( ! empty( $feature_plugins['lms'] ) ) {
			$adder( 'lms', 75, __( 'An LMS plugin is active.', 'open-growth-seo' ) );
			$adder( 'membership', 20, __( 'Courses often require gated or enrolled access.', 'open-growth-seo' ) );
		}
		if ( ! empty( $feature_plugins['directory'] ) ) {
			$adder( 'directory', 75, __( 'A directory/listings plugin is active.', 'open-growth-seo' ) );
			$adder( 'corporate', 15, __( 'Directory sites often need local-business style structure.', 'open-growth-seo' ) );
		}
		if ( ! empty( $feature_plugins['forum'] ) ) {
			$adder( 'forum', 75, __( 'A forum/community plugin is active.', 'open-growth-seo' ) );
			$adder( 'support', 20, __( 'Forum content often overlaps with support/discussion workflows.', 'open-growth-seo' ) );
		}
		if ( ! empty( $feature_plugins['support'] ) ) {
			$adder( 'support', 75, __( 'A support/helpdesk plugin is active.', 'open-growth-seo' ) );
			$adder( 'corporate', 10, __( 'Support flows typically coexist with product or service sites.', 'open-growth-seo' ) );
		}
		if ( ! empty( $feature_plugins['saas'] ) ) {
			$adder( 'saas', 60, __( 'Signals for a SaaS/product-led site were detected.', 'open-growth-seo' ) );
		}

		if ( in_array( 'product', $post_types, true ) || in_array( 'product_cat', $taxonomies, true ) || $product_count > 0 ) {
			$adder( 'ecommerce', 30, __( 'Public product content exists.', 'open-growth-seo' ) );
			$adder( 'catalog', 25, __( 'Catalog/product signals were found.', 'open-growth-seo' ) );
		}
		if ( self::contains_any( $post_types, array( 'course', 'sfwd-courses', 'lesson', 'llms_course', 'tutor_course' ) ) ) {
			$adder( 'lms', 35, __( 'Course or lesson post types are public.', 'open-growth-seo' ) );
		}
		if ( self::contains_any( $post_types, array( 'forum', 'topic' ) ) ) {
			$adder( 'forum', 30, __( 'Forum/topic post types are public.', 'open-growth-seo' ) );
		}
		if ( self::contains_fragment( $post_types, array( 'listing', 'directory', 'property', 'job' ) ) ) {
			$adder( 'directory', 30, __( 'Listing or directory-style post types are public.', 'open-growth-seo' ) );
		}
		if ( self::contains_fragment( $post_types, array( 'ticket', 'support', 'knowledgebase', 'kb' ) ) ) {
			$adder( 'support', 30, __( 'Support or ticket-oriented post types are public.', 'open-growth-seo' ) );
		}

		if ( ! empty( $route_flags['has_cart'] ) ) {
			$adder( 'ecommerce', 20, __( 'Cart route/page was detected.', 'open-growth-seo' ) );
		}
		if ( ! empty( $route_flags['has_checkout'] ) ) {
			$adder( 'ecommerce', 25, __( 'Checkout route/page was detected.', 'open-growth-seo' ) );
		}
		if ( ! empty( $route_flags['has_account'] ) ) {
			$adder( 'membership', 15, __( 'Account/dashboard route was detected.', 'open-growth-seo' ) );
			$adder( 'saas', 10, __( 'Account flows often support product-led onboarding.', 'open-growth-seo' ) );
		}
		if ( ! empty( $route_flags['has_pricing'] ) ) {
			$adder( 'saas', 18, __( 'Pricing route/page was detected.', 'open-growth-seo' ) );
			$adder( 'landing_page', 8, __( 'Pricing is common on marketing-focused sites.', 'open-growth-seo' ) );
		}
		if ( ! empty( $route_flags['has_docs'] ) ) {
			$adder( 'saas', 12, __( 'Documentation route/page was detected.', 'open-growth-seo' ) );
			$adder( 'support', 10, __( 'Documentation usually supports support or product education.', 'open-growth-seo' ) );
		}
		if ( ! empty( $route_flags['has_support'] ) ) {
			$adder( 'support', 20, __( 'Support/help route/page was detected.', 'open-growth-seo' ) );
		}
		if ( ! empty( $route_flags['has_courses'] ) ) {
			$adder( 'lms', 20, __( 'Courses route/page was detected.', 'open-growth-seo' ) );
		}
		if ( ! empty( $route_flags['has_forum'] ) ) {
			$adder( 'forum', 20, __( 'Forum route/page was detected.', 'open-growth-seo' ) );
		}

		if ( $posts_count >= 12 ) {
			$adder( 'blog', 35, __( 'Published posts indicate an active editorial archive.', 'open-growth-seo' ) );
		}
		if ( $posts_count > $pages_count && $posts_count >= 4 ) {
			$adder( 'blog', 20, __( 'Posts outnumber pages.', 'open-growth-seo' ) );
		}
		if ( in_array( 'category', $taxonomies, true ) || in_array( 'post_tag', $taxonomies, true ) ) {
			$adder( 'blog', 10, __( 'Default editorial taxonomies are present.', 'open-growth-seo' ) );
		}
		if ( ! empty( $signals['posts_page'] ) ) {
			$adder( 'blog', 10, __( 'A dedicated posts page is configured.', 'open-growth-seo' ) );
		}

		if ( $pages_count <= 8 && $posts_count <= 3 && empty( $feature_plugins['woocommerce'] ) ) {
			$adder( 'landing_page', 28, __( 'Small brochure-style content footprint detected.', 'open-growth-seo' ) );
		}
		if ( $pages_count > $posts_count && $pages_count >= 6 ) {
			$adder( 'corporate', 25, __( 'Pages dominate site structure.', 'open-growth-seo' ) );
		}
		if ( self::contains_any( $page_slugs, array( 'about', 'services', 'team', 'contact' ) ) ) {
			$adder( 'corporate', 18, __( 'Corporate/company page patterns were detected.', 'open-growth-seo' ) );
		}
		if ( self::contains_any( $page_slugs, array( 'pricing', 'features', 'demo', 'book-demo', 'login', 'signup', 'register', 'api', 'docs' ) ) ) {
			$adder( 'saas', 22, __( 'SaaS-style marketing or product pages were detected.', 'open-growth-seo' ) );
		}
		if ( self::contains_any( $page_slugs, array( 'catalog', 'collections', 'shop' ) ) && empty( $route_flags['has_checkout'] ) ) {
			$adder( 'catalog', 18, __( 'Catalog-style routes exist without a strong checkout signal.', 'open-growth-seo' ) );
		}

		arsort( $scores );
		$top_type  = (string) key( $scores );
		$top_score = (int) current( $scores );

		// Prefer more specific commerce models over the generic ecommerce bucket when evidence is close.
		if ( ! empty( $feature_plugins['marketplace'] ) && isset( $scores['marketplace'], $scores['ecommerce'] ) ) {
			$marketplace_score = (int) $scores['marketplace'];
			if ( $marketplace_score >= 75 ) {
				$top_type  = 'marketplace';
				$top_score = $marketplace_score;
			}
		}

		if ( $top_score < 35 ) {
			$top_type  = 'general';
			$top_score = 25;
		}

		$secondary = array();
		foreach ( $scores as $type => $score ) {
			$score = (int) $score;
			if ( $type === $top_type || 'general' === $type ) {
				continue;
			}
			if ( $score >= 35 && ( $top_score - $score ) <= 18 ) {
				$secondary[] = $type;
			}
		}

		$confidence = min( 95, max( 20, $top_score ) );
		if ( 'general' === $top_type ) {
			$confidence = min( 55, $confidence );
		}

		return array(
			'primary'          => $top_type,
			'secondary'        => array_values( $secondary ),
			'confidence'       => $confidence,
			'scores'           => $scores,
			'signals'          => array_values( array_unique( $reasons[ $top_type ] ?? array() ) ),
			'wizard_site_type' => self::map_to_wizard_site_type( $top_type ),
		);
	}

	public static function map_to_wizard_site_type( string $classification ): string {
		$classification = sanitize_key( $classification );
		$map            = array(
			'ecommerce'   => 'ecommerce',
			'marketplace' => 'ecommerce',
			'forum'       => 'forum',
			'support'     => 'forum',
			'blog'        => 'blog',
			'corporate'   => 'business',
			'directory'   => 'business',
			'landing_page' => 'business',
			'lms'         => 'business',
			'membership'  => 'business',
			'catalog'     => 'business',
			'saas'        => 'business',
		);
		return $map[ $classification ] ?? 'general';
	}

	private static function site_types(): array {
		return array(
			'general',
			'ecommerce',
			'membership',
			'blog',
			'catalog',
			'landing_page',
			'lms',
			'directory',
			'marketplace',
			'corporate',
			'support',
			'saas',
			'forum',
		);
	}

	private static function contains_any( array $haystack, array $needles ): bool {
		foreach ( $needles as $needle ) {
			if ( in_array( sanitize_key( (string) $needle ), $haystack, true ) ) {
				return true;
			}
		}
		return false;
	}

	private static function contains_fragment( array $haystack, array $fragments ): bool {
		foreach ( $haystack as $value ) {
			$value = sanitize_key( (string) $value );
			foreach ( $fragments as $fragment ) {
				if ( false !== strpos( $value, sanitize_key( (string) $fragment ) ) ) {
					return true;
				}
			}
		}
		return false;
	}
}
