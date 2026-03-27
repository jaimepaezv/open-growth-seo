<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\Installation;

use OpenGrowthSolutions\OpenGrowthSEO\Compatibility\Detector;

defined( 'ABSPATH' ) || exit;

class Diagnostics {
	public function run(): array {
		$runtime        = $this->runtime();
		$extensions     = $this->extensions();
		$permissions    = $this->permissions();
		$signals        = $this->signals();
		$classification = SiteClassifier::classify( $signals );
		$compatibility  = $this->compatibility( $signals );
		$findings       = $this->findings( $runtime, $extensions, $permissions, $compatibility, $signals, $classification );

		return array(
			'runtime'        => $runtime,
			'extensions'     => $extensions,
			'permissions'    => $permissions,
			'signals'        => $signals,
			'classification' => $classification,
			'compatibility'  => $compatibility,
			'findings'       => $findings,
		);
	}

	private function runtime(): array {
		$required_php = '8.0';
		$required_wp  = '6.5';
		$wp_version   = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : '';

		return array(
			'php_version'    => PHP_VERSION,
			'wp_version'     => $wp_version,
			'plugin_version' => defined( 'OGS_SEO_VERSION' ) ? (string) OGS_SEO_VERSION : '',
			'required_php'   => $required_php,
			'required_wp'    => $required_wp,
			'meets_php'      => version_compare( PHP_VERSION, $required_php, '>=' ),
			'meets_wp'       => '' !== $wp_version ? version_compare( $wp_version, $required_wp, '>=' ) : true,
			'multisite'      => function_exists( 'is_multisite' ) ? is_multisite() : false,
			'blog_public'    => (bool) get_option( 'blog_public', 1 ),
			'locale'         => function_exists( 'get_locale' ) ? (string) get_locale() : '',
			'site_url'       => function_exists( 'home_url' ) ? (string) home_url( '/' ) : '',
		);
	}

	private function extensions(): array {
		return array(
			'curl'      => array(
				'available' => extension_loaded( 'curl' ),
				'required'  => false,
				'label'     => 'cURL',
			),
			'mbstring'  => array(
				'available' => extension_loaded( 'mbstring' ),
				'required'  => false,
				'label'     => 'mbstring',
			),
			'dom'       => array(
				'available' => extension_loaded( 'dom' ),
				'required'  => false,
				'label'     => 'DOM',
			),
			'simplexml' => array(
				'available' => extension_loaded( 'SimpleXML' ),
				'required'  => false,
				'label'     => 'SimpleXML',
			),
			'openssl'   => array(
				'available' => extension_loaded( 'openssl' ),
				'required'  => false,
				'label'     => 'OpenSSL',
			),
		);
	}

	private function permissions(): array {
		$robots_path = trailingslashit( ABSPATH ) . 'robots.txt';
		$uploads     = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : array();
		$uploads_dir = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';

		return array(
			'robots'  => array(
				'path'     => $robots_path,
				'exists'   => file_exists( $robots_path ),
				'readable' => ! file_exists( $robots_path ) || is_readable( $robots_path ),
				'writable' => ! file_exists( $robots_path ) || is_writable( $robots_path ),
				'source'   => file_exists( $robots_path ) ? 'physical' : 'virtual',
			),
			'uploads' => array(
				'path'      => $uploads_dir,
				'available' => '' !== $uploads_dir,
				'readable'  => '' === $uploads_dir || is_readable( $uploads_dir ),
				'writable'  => '' === $uploads_dir || is_writable( $uploads_dir ),
			),
		);
	}

	private function signals(): array {
		$post_types  = function_exists( 'get_post_types' ) ? get_post_types( array( 'public' => true ), 'names' ) : array();
		$taxonomies  = function_exists( 'get_taxonomies' ) ? get_taxonomies( array( 'public' => true ), 'names' ) : array();
		$post_counts = array();
		foreach ( (array) $post_types as $post_type ) {
			if ( ! function_exists( 'wp_count_posts' ) ) {
				break;
			}
			$counts = wp_count_posts( (string) $post_type );
			$post_counts[ sanitize_key( (string) $post_type ) ] = is_object( $counts ) && isset( $counts->publish ) ? (int) $counts->publish : 0;
		}

		$page_slugs = array();
		if ( function_exists( 'get_posts' ) ) {
			$pages = get_posts(
				array(
					'post_type'      => 'page',
					'post_status'    => 'publish',
					'posts_per_page' => 40,
					'orderby'        => 'menu_order title',
					'order'          => 'ASC',
				)
			);
			foreach ( (array) $pages as $page ) {
				$slug = '';
				if ( is_object( $page ) && isset( $page->post_name ) ) {
					$slug = (string) $page->post_name;
				} elseif ( is_array( $page ) && isset( $page['post_name'] ) ) {
					$slug = (string) $page['post_name'];
				}
				$slug = sanitize_title( $slug );
				if ( '' !== $slug ) {
					$page_slugs[] = $slug;
				}
			}
		}

		return array(
			'site_name'         => function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '',
			'theme'             => $this->theme(),
			'feature_plugins'   => $this->feature_plugins(),
			'seo_providers'     => Detector::detect_providers(),
			'public_post_types' => array_values( array_map( 'sanitize_key', (array) $post_types ) ),
			'public_taxonomies' => array_values( array_map( 'sanitize_key', (array) $taxonomies ) ),
			'post_counts'       => $post_counts,
			'page_slugs'        => array_values( array_unique( $page_slugs ) ),
			'route_flags'       => $this->route_flags( $page_slugs ),
			'posts_page'        => absint( get_option( 'page_for_posts', 0 ) ),
			'front_page'        => absint( get_option( 'page_on_front', 0 ) ),
			'languages'         => $this->languages(),
			'site_icon_url'     => function_exists( 'get_site_icon_url' ) ? (string) get_site_icon_url() : '',
		);
	}

	private function compatibility( array $signals ): array {
		$providers        = isset( $signals['seo_providers'] ) && is_array( $signals['seo_providers'] ) ? $signals['seo_providers'] : array();
		$active_conflicts = array();
		foreach ( $providers as $provider ) {
			if ( ! empty( $provider['active'] ) && ! empty( $provider['label'] ) ) {
				$active_conflicts[] = sanitize_text_field( (string) $provider['label'] );
			}
		}

		return array(
			'active_seo_conflicts' => array_values( array_unique( $active_conflicts ) ),
			'active_theme'         => $signals['theme']['name'] ?? '',
			'feature_plugins'      => $signals['feature_plugins'] ?? array(),
		);
	}

	private function findings( array $runtime, array $extensions, array $permissions, array $compatibility, array $signals, array $classification ): array {
		$findings = array();

		if ( empty( $runtime['meets_php'] ) ) {
			$findings[] = $this->finding( 'critical', 'runtime_php', __( 'PHP version is below the plugin requirement.', 'open-growth-seo' ) );
		}
		if ( empty( $runtime['meets_wp'] ) ) {
			$findings[] = $this->finding( 'critical', 'runtime_wp', __( 'WordPress version is below the plugin requirement.', 'open-growth-seo' ) );
		}
		foreach ( $extensions as $slug => $extension ) {
			if ( ! empty( $extension['required'] ) && empty( $extension['available'] ) ) {
				$findings[] = $this->finding( 'critical', 'ext_' . sanitize_key( $slug ), sprintf( __( '%s is required but not available.', 'open-growth-seo' ), (string) $extension['label'] ) );
			}
		}
		if ( ! empty( $compatibility['active_seo_conflicts'] ) ) {
			$findings[] = $this->finding( 'warning', 'seo_conflict', __( 'Another SEO plugin is active. Safe mode should remain enabled until migration is complete.', 'open-growth-seo' ) );
		}
		if ( empty( $runtime['blog_public'] ) ) {
			$findings[] = $this->finding( 'warning', 'blog_public', __( 'Search visibility is discouraged at site level.', 'open-growth-seo' ) );
		}
		if ( ! empty( $permissions['robots']['exists'] ) && empty( $permissions['robots']['writable'] ) ) {
			$findings[] = $this->finding( 'warning', 'robots_permission', __( 'A physical robots.txt file exists and is not writable.', 'open-growth-seo' ) );
		}
		if ( empty( $permissions['uploads']['writable'] ) ) {
			$findings[] = $this->finding( 'warning', 'uploads_permission', __( 'Uploads directory is not writable, which can affect media-driven previews and assets.', 'open-growth-seo' ) );
		}
		if ( empty( $classification['signals'] ) ) {
			$findings[] = $this->finding( 'info', 'classification_low_confidence', __( 'Site type confidence is low. Conservative defaults will be used.', 'open-growth-seo' ) );
		}
		if ( empty( $signals['public_post_types'] ) ) {
			$findings[] = $this->finding( 'warning', 'no_public_types', __( 'No public post types were detected for sitemap or search appearance defaults.', 'open-growth-seo' ) );
		}

		return $findings;
	}

	private function theme(): array {
		if ( ! function_exists( 'wp_get_theme' ) ) {
			return array(
				'name'     => '',
				'template' => '',
				'version'  => '',
			);
		}
		$theme = wp_get_theme();
		return array(
			'name'     => method_exists( $theme, 'get' ) ? (string) $theme->get( 'Name' ) : '',
			'template' => method_exists( $theme, 'get_template' ) ? (string) $theme->get_template() : '',
			'version'  => method_exists( $theme, 'get' ) ? (string) $theme->get( 'Version' ) : '',
		);
	}

	private function feature_plugins(): array {
		return array(
			'woocommerce' => $this->plugin_active( array( 'woocommerce/woocommerce.php' ), array( 'WooCommerce' ) ),
			'marketplace' => $this->plugin_active( array( 'dokan-lite/dokan.php', 'wc-vendors/class-wc-vendors.php', 'wc-multivendor-marketplace/wc-multivendor-marketplace.php' ), array( 'WeDevs_Dokan', 'WCMp' ) ),
			'membership'  => $this->plugin_active( array( 'paid-memberships-pro/paid-memberships-pro.php', 'memberpress/memberpress.php', 'restrict-content-pro/restrict-content-pro.php' ), array( 'MemberPress', 'PMPRO_REST_API' ), array( 'PMPRO_VERSION' ) ),
			'lms'         => $this->plugin_active( array( 'sfwd-lms/sfwd_lms.php', 'lifterlms/lifterlms.php', 'tutor/tutor.php', 'sensei-lms/sensei-lms.php' ), array( 'SFWD_LMS', 'LifterLMS', 'TUTOR_VERSION', 'Sensei_Main' ) ),
			'directory'   => $this->plugin_active( array( 'geodirectory/geodirectory.php', 'directorist/directorist-base.php', 'business-directory-plugin/wpbusdirman.php' ), array( 'GeoDir_Admin', 'Directorist' ) ),
			'forum'       => $this->plugin_active( array( 'bbpress/bbpress.php', 'buddypress/bp-loader.php' ), array( 'bbPress', 'BuddyPress' ) ),
			'support'     => $this->plugin_active( array( 'awesome-support/awesome-support.php', 'fluent-support/fluent-support.php' ), array( 'Awesome_Support', 'FluentSupport\\App\\App' ) ),
			'saas'        => $this->plugin_active( array( 'easy-digital-downloads/easy-digital-downloads.php' ), array( 'Easy_Digital_Downloads' ) ),
		);
	}

	private function route_flags( array $page_slugs ): array {
		return array(
			'has_cart'     => in_array( 'cart', $page_slugs, true ),
			'has_checkout' => in_array( 'checkout', $page_slugs, true ),
			'has_account'  => self::contains_slug( $page_slugs, array( 'my-account', 'account', 'dashboard', 'members-area' ) ),
			'has_shop'     => in_array( 'shop', $page_slugs, true ),
			'has_courses'  => self::contains_slug( $page_slugs, array( 'courses', 'course-catalog' ) ),
			'has_forum'    => self::contains_slug( $page_slugs, array( 'forum', 'forums', 'community' ) ),
			'has_docs'     => self::contains_slug( $page_slugs, array( 'docs', 'documentation', 'knowledge-base', 'knowledgebase', 'help-center' ) ),
			'has_support'  => self::contains_slug( $page_slugs, array( 'support', 'help', 'contact-support', 'submit-ticket' ) ),
			'has_pricing'  => self::contains_slug( $page_slugs, array( 'pricing', 'plans' ) ),
		);
	}

	private function languages(): array {
		$languages = array();
		if ( function_exists( 'get_locale' ) ) {
			$languages[] = (string) get_locale();
		}
		if ( function_exists( 'pll_languages_list' ) ) {
			$languages = array_merge( $languages, (array) pll_languages_list() );
		}
		if ( defined( 'ICL_SITEPRESS_VERSION' ) && function_exists( 'icl_get_languages' ) ) {
			$wpml = icl_get_languages( 'skip_missing=0' );
			if ( is_array( $wpml ) ) {
				foreach ( $wpml as $language ) {
					if ( ! empty( $language['language_code'] ) ) {
						$languages[] = (string) $language['language_code'];
					}
				}
			}
		}
		return array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $languages ) ) ) );
	}

	private function plugin_active( array $basenames, array $classes = array(), array $constants = array() ): bool {
		$active_plugins = (array) get_option( 'active_plugins', array() );
		foreach ( $constants as $constant ) {
			if ( defined( (string) $constant ) ) {
				return true;
			}
		}
		foreach ( $classes as $class_name ) {
			if ( class_exists( (string) $class_name ) ) {
				return true;
			}
		}
		foreach ( $basenames as $basename ) {
			if ( in_array( (string) $basename, $active_plugins, true ) ) {
				return true;
			}
		}
		return false;
	}

	private function finding( string $severity, string $code, string $message ): array {
		return array(
			'severity' => sanitize_key( $severity ),
			'code'     => sanitize_key( $code ),
			'message'  => sanitize_text_field( $message ),
		);
	}

	private static function contains_slug( array $page_slugs, array $candidates ): bool {
		foreach ( $candidates as $candidate ) {
			if ( in_array( sanitize_title( (string) $candidate ), $page_slugs, true ) ) {
				return true;
			}
		}
		return false;
	}
}
