<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\Compatibility;

defined( 'ABSPATH' ) || exit;

class Detector {
	private const MINIMUM_PHP = '8.0';
	private const MINIMUM_WP  = '6.5';

	/**
	 * @var array<string, mixed>|null
	 */
	private static ?array $report_cache = null;

	public static function providers(): array {
		return array(
			'yoast'    => array(
				'label'     => 'Yoast SEO',
				'constants' => array( 'WPSEO_VERSION' ),
				'classes'   => array( 'WPSEO_Options' ),
				'basenames' => array(
					'wordpress-seo/wp-seo.php',
					'wordpress-seo-premium/wp-seo-premium.php',
				),
			),
			'rankmath' => array(
				'label'     => 'Rank Math',
				'constants' => array( 'RANK_MATH_VERSION' ),
				'classes'   => array( 'RankMath\\Runner' ),
				'basenames' => array(
					'seo-by-rank-math/rank-math.php',
				),
			),
			'aioseo'   => array(
				'label'     => 'All in One SEO',
				'constants' => array( 'AIOSEO_VERSION' ),
				'classes'   => array( 'AIOSEO\\Plugin\\AIOSEO' ),
				'basenames' => array(
					'aioseo/aioseo.php',
					'all-in-one-seo-pack/all_in_one_seo_pack.php',
					'aioseo-pro/aioseo-pro.php',
				),
			),
		);
	}

	public static function detect_providers(): array {
		$report = self::report();
		return isset( $report['providers'] ) && is_array( $report['providers'] ) ? $report['providers'] : array();
	}

	public static function has_active_seo_plugin(): bool {
		$report = self::report();
		return ! empty( $report['has_conflicts'] );
	}

	/**
	 * Exposed for tests and deterministic re-evaluation after environment changes.
	 */
	public static function reset_cache(): void {
		self::$report_cache = null;
	}

	public static function report(): array {
		if ( null !== self::$report_cache ) {
			return self::$report_cache;
		}

		$active_plugins = self::active_plugins();
		$network_active = self::network_active_plugins();
		$providers      = self::providers();
		foreach ( $providers as $slug => $provider ) {
			$providers[ $slug ]['version'] = self::provider_version( (array) $provider );
			$providers[ $slug ]['active']  = self::is_provider_active( (array) $provider, $active_plugins, $network_active );
		}

		$active = array();
		foreach ( $providers as $slug => $provider ) {
			if ( ! empty( $provider['active'] ) ) {
				$active[ $slug ] = $provider;
			}
		}

		$contexts       = self::contexts();
		$runtime        = self::runtime();
		$integrations   = self::integration_matrix( $active_plugins, $network_active );
		$recommendations = self::recommendations( $active, $runtime, $integrations, $contexts );

		self::$report_cache = array(
			'providers'        => $providers,
			'active'           => $active,
			'active_slugs'     => array_keys( $active ),
			'has_conflicts'    => ! empty( $active ),
			'runtime'          => $runtime,
			'contexts'         => $contexts,
			'integrations'     => $integrations,
			'recommendations'  => $recommendations,
			'safe_mode_recommended' => ! empty( $active ),
		);

		return self::$report_cache;
	}

	private static function runtime(): array {
		$wp_version = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : '';

		return array(
			'php_version'  => PHP_VERSION,
			'wp_version'   => $wp_version,
			'minimum_php'  => self::MINIMUM_PHP,
			'minimum_wp'   => self::MINIMUM_WP,
			'meets_php'    => version_compare( PHP_VERSION, self::MINIMUM_PHP, '>=' ),
			'meets_wp'     => '' === $wp_version ? true : version_compare( $wp_version, self::MINIMUM_WP, '>=' ),
			'multisite'    => function_exists( 'is_multisite' ) && is_multisite(),
			'blog_public'  => (bool) get_option( 'blog_public', 1 ),
			'locale'       => function_exists( 'get_locale' ) ? (string) get_locale() : '',
			'object_cache' => function_exists( 'wp_using_ext_object_cache' ) ? (bool) wp_using_ext_object_cache() : false,
		);
	}

	private static function contexts(): array {
		$is_cli  = defined( 'WP_CLI' ) && WP_CLI;
		$is_rest = ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() );
		$is_ajax = function_exists( 'wp_doing_ajax' ) ? wp_doing_ajax() : ( defined( 'DOING_AJAX' ) && DOING_AJAX );
		$is_cron = defined( 'DOING_CRON' ) && DOING_CRON;
		$is_admin = function_exists( 'is_admin' ) && is_admin();

		return array(
			'admin'                => $is_admin,
			'frontend'             => ! $is_admin && ! $is_rest && ! $is_ajax && ! $is_cron && ! $is_cli,
			'rest'                 => $is_rest,
			'ajax'                 => $is_ajax,
			'cron'                 => $is_cron,
			'cli'                  => $is_cli,
			'rest_available'       => class_exists( 'WP_REST_Server' ) || function_exists( 'rest_url' ),
			'cron_disabled'        => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'gutenberg_available'  => class_exists( 'WP_Block_Type_Registry' ) || function_exists( 'register_block_type' ),
			'classic_editor_forced' => self::plugin_matches(
				array( 'classic-editor/classic-editor.php', 'disable-gutenberg/disable-gutenberg.php' ),
				array( 'Classic_Editor', 'DisableGutenberg' ),
				array()
			),
		);
	}

	private static function integration_matrix( array $active_plugins, array $network_active ): array {
		return array(
			'ecommerce'   => self::integration_group(
				'WooCommerce / ecommerce',
				array( 'woocommerce/woocommerce.php', 'easy-digital-downloads/easy-digital-downloads.php' ),
				array( 'WooCommerce', 'Easy_Digital_Downloads' ),
				array(),
				$active_plugins,
				$network_active
			),
			'builders'    => self::integration_group(
				'Page builders',
				array( 'elementor/elementor.php', 'beaver-builder-lite-version/fl-builder.php', 'siteorigin-panels/siteorigin-panels.php', 'oxygen/functions.php', 'bricks/bricks.php' ),
				array( 'Elementor\\Plugin', 'FLBuilderModel', 'SiteOrigin_Panels', 'OxygenElementorBridge', 'Bricks\\Main' ),
				array( 'ET_BUILDER_VERSION' ),
				$active_plugins,
				$network_active
			),
			'translations' => self::integration_group(
				'Translation / multilingual',
				array( 'polylang/polylang.php', 'sitepress-multilingual-cms/sitepress.php', 'translatepress-multilingual/index.php', 'weglot/weglot.php' ),
				array( 'SitePress', 'TRP_Translate_Press', 'WeglotWP\\Services\\Hooks_Service_Weglot' ),
				array( 'POLYLANG_VERSION', 'ICL_SITEPRESS_VERSION', 'WEGLOT_VERSION' ),
				$active_plugins,
				$network_active
			),
			'cache'       => self::integration_group(
				'Cache / edge caching',
				array( 'wp-rocket/wp-rocket.php', 'w3-total-cache/w3-total-cache.php', 'litespeed-cache/litespeed-cache.php', 'sg-cachepress/sg-cachepress.php', 'autoptimize/autoptimize.php' ),
				array( 'LiteSpeed_Cache', 'autoptimizeCache' ),
				array( 'WP_ROCKET_VERSION', 'W3TC', 'SG_CACHEPRESS_VERSION' ),
				$active_plugins,
				$network_active
			),
			'security'    => self::integration_group(
				'Security / hardening',
				array( 'wordfence/wordfence.php', 'better-wp-security/better-wp-security.php', 'all-in-one-wp-security-and-firewall/wp-security.php', 'sucuri-scanner/sucuri.php' ),
				array( 'wordfence', 'ITSEC_Core', 'AIO_WP_Security' ),
				array( 'SUCURI_VERSION' ),
				$active_plugins,
				$network_active
			),
			'schema'      => self::integration_group(
				'Schema emitters',
				array( 'schema-pro/schema-pro.php', 'schema-and-structured-data-for-wp/schema-and-structured-data-for-wp.php' ),
				array( 'BSF_AIOSRS_Pro_Admin', 'SASWP_PLUGIN_DIR_NAME' ),
				array( 'BSF_AIOSRS_PRO_VER', 'SASWP_VERSION' ),
				$active_plugins,
				$network_active
			),
		);
	}

	private static function recommendations( array $active, array $runtime, array $integrations, array $contexts ): array {
		$recommendations = array();

		if ( ! empty( $active ) ) {
			$recommendations[] = __( 'Keep safe mode enabled while another SEO plugin is active to reduce duplicate meta, canonical, and schema output.', 'open-growth-seo' );
		}
		if ( ! empty( $integrations['cache']['active'] ) ) {
			$recommendations[] = __( 'After changing schema, redirects, or search appearance, purge page/cache layers to avoid stale SEO output.', 'open-growth-seo' );
		}
		if ( ! empty( $integrations['builders']['active'] ) ) {
			$recommendations[] = __( 'Page builders are active. Prefer runtime validation and frontend inspection over assuming editor-side previews fully represent final output.', 'open-growth-seo' );
		}
		if ( ! empty( $integrations['translations']['active'] ) ) {
			$recommendations[] = __( 'Multilingual tooling is active. Review canonical, hreflang, and duplicate-output behavior after compatibility-sensitive changes.', 'open-growth-seo' );
		}
		if ( ! empty( $integrations['schema']['active'] ) ) {
			$recommendations[] = __( 'Another schema emitter appears active. Verify duplicate structured-data output before enabling overlapping schema features.', 'open-growth-seo' );
		}
		if ( ! empty( $runtime['multisite'] ) ) {
			$recommendations[] = __( 'Multisite is active. Validate plugin activation scope and network-active plugin conflicts before broad rollout.', 'open-growth-seo' );
		}
		if ( ! empty( $contexts['classic_editor_forced'] ) ) {
			$recommendations[] = __( 'Classic editor compatibility mode is present. Keep classic-specific save and meta-box flows covered during later module work.', 'open-growth-seo' );
		}

		return $recommendations;
	}

	private static function integration_group( string $label, array $basenames, array $classes, array $constants, array $active_plugins, array $network_active ): array {
		return array(
			'label'  => $label,
			'active' => self::plugin_matches( $basenames, $classes, $constants, $active_plugins, $network_active ),
		);
	}

	private static function provider_version( array $provider ): string {
		foreach ( (array) ( $provider['constants'] ?? array() ) as $constant ) {
			if ( is_string( $constant ) && '' !== $constant && defined( $constant ) ) {
				return (string) constant( $constant );
			}
		}

		return '';
	}

	private static function is_provider_active( array $provider, array $active_plugins, array $network_active ): bool {
		return self::plugin_matches(
			(array) ( $provider['basenames'] ?? array() ),
			(array) ( $provider['classes'] ?? array() ),
			(array) ( $provider['constants'] ?? array() ),
			$active_plugins,
			$network_active
		);
	}

	private static function plugin_matches( array $basenames, array $classes = array(), array $constants = array(), ?array $active_plugins = null, ?array $network_active = null ): bool {
		$active_plugins = is_array( $active_plugins ) ? $active_plugins : self::active_plugins();
		$network_active = is_array( $network_active ) ? $network_active : self::network_active_plugins();

		foreach ( $constants as $constant ) {
			if ( is_string( $constant ) && '' !== $constant && defined( $constant ) ) {
				return true;
			}
		}
		foreach ( $classes as $class_name ) {
			if ( is_string( $class_name ) && '' !== $class_name && class_exists( $class_name ) ) {
				return true;
			}
		}
		foreach ( $basenames as $basename ) {
			if ( ! is_string( $basename ) || '' === $basename ) {
				continue;
			}
			if ( in_array( $basename, $active_plugins, true ) || isset( $network_active[ $basename ] ) ) {
				return true;
			}
			if ( function_exists( 'is_plugin_active' ) && is_plugin_active( $basename ) ) {
				return true;
			}
		}

		return false;
	}

	private static function active_plugins(): array {
		return function_exists( 'get_option' ) ? (array) get_option( 'active_plugins', array() ) : array();
	}

	private static function network_active_plugins(): array {
		if ( ! function_exists( 'is_multisite' ) || ! is_multisite() || ! function_exists( 'get_site_option' ) ) {
			return array();
		}

		$plugins = get_site_option( 'active_sitewide_plugins', array() );
		return is_array( $plugins ) ? $plugins : array();
	}
}
