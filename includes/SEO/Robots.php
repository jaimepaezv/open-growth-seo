<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\SEO;

use OpenGrowthSolutions\OpenGrowthSEO\Support\Settings;

defined( 'ABSPATH' ) || exit;

class Robots {
	private const DIRECTIVE_REGEX = '/^(User-agent|Allow|Disallow|Sitemap|Crawl-delay):\s*(.*)$/i';

	public function register(): void {
		add_filter( 'robots_txt', array( $this, 'render' ), 10, 2 );
	}

	public function render( string $output, bool $public ): string {
		$settings = Settings::get_all();
		return self::build_content( $settings, $public );
	}

	public static function build_content( array $settings, bool $public ): string {
		if ( 'expert' === ( $settings['robots_mode'] ?? 'managed' ) ) {
			$expert = trim( (string) ( $settings['robots_expert_rules'] ?? '' ) );
			if ( '' === $expert ) {
				$expert = self::managed_content( $settings, $public );
			}
			if ( false === stripos( $expert, 'Sitemap:' ) ) {
				$expert .= "\nSitemap: " . esc_url_raw( home_url( '/ogs-sitemap.xml' ) );
			}
			return trim( $expert ) . "\n";
		}
		return self::managed_content( $settings, $public );
	}

	private static function managed_content( array $settings, bool $public ): string {
		$global = (string) ( $settings['robots_global_policy'] ?? 'allow' );
		$lines  = array( '# Open Growth SEO managed robots.txt', 'User-agent: *' );
		$lines[] = ( ! $public || 'disallow' === $global ) ? 'Disallow: /' : 'Allow: /';
		$lines[] = 'User-agent: GPTBot';
		$lines[] = 'allow' === ( $settings['bots_gptbot'] ?? 'allow' ) ? 'Allow: /' : 'Disallow: /';
		$lines[] = 'User-agent: OAI-SearchBot';
		$lines[] = 'allow' === ( $settings['bots_oai_searchbot'] ?? 'allow' ) ? 'Allow: /' : 'Disallow: /';

		$custom = trim( (string) ( $settings['robots_custom'] ?? '' ) );
		if ( '' !== $custom ) {
			$lines[] = '# Additional rules';
			$lines[] = $custom;
		}
		$lines[] = 'Sitemap: ' . esc_url_raw( home_url( '/ogs-sitemap.xml' ) );
		return implode( "\n", $lines ) . "\n";
	}

	public static function validate_for_save( array $settings, bool $public ): array {
		$content = self::build_content( $settings, $public );
		$errors  = self::validate_syntax( $content );
		$alerts  = self::critical_safeguards( $content, $public );
		return array(
			'errors'   => array_values( array_unique( array_merge( $errors, $alerts ) ) ),
			'warnings' => self::warnings( $content, $public ),
			'content'  => $content,
		);
	}

	public static function validate_syntax( string $content ): array {
		$errors         = array();
		$lines          = preg_split( '/\r\n|\r|\n/', $content ) ?: array();
		$has_user_agent = false;
		$current_agent  = false;

		foreach ( $lines as $index => $line ) {
			$line = trim( $line );
			if ( '' === $line || str_starts_with( $line, '#' ) ) {
				continue;
			}
			if ( ! preg_match( self::DIRECTIVE_REGEX, $line, $match ) ) {
				$errors[] = sprintf( __( 'Invalid robots directive at line %d.', 'open-growth-seo' ), $index + 1 );
				continue;
			}
			$directive = strtolower( $match[1] );
			$value     = trim( (string) $match[2] );
			if ( '' === $value ) {
				$errors[] = sprintf( __( 'Missing value at line %d.', 'open-growth-seo' ), $index + 1 );
				continue;
			}
			if ( 'user-agent' === $directive ) {
				$has_user_agent = true;
				$current_agent  = true;
			}
			if ( in_array( $directive, array( 'allow', 'disallow' ), true ) && ! $current_agent ) {
				$errors[] = sprintf( __( 'Allow/Disallow requires a preceding User-agent at line %d.', 'open-growth-seo' ), $index + 1 );
			}
		}
		if ( ! $has_user_agent ) {
			$errors[] = __( 'robots.txt must include at least one User-agent group.', 'open-growth-seo' );
		}
		return $errors;
	}

	private static function critical_safeguards( string $content, bool $public ): array {
		$errors = array();
		$parsed = self::parse_groups( $content );

		if ( $public && isset( $parsed['*'] ) ) {
			$disallow_root = in_array( 'Disallow: /', $parsed['*'], true );
			$allow_root    = in_array( 'Allow: /', $parsed['*'], true );
			if ( $disallow_root && ! $allow_root ) {
				$errors[] = __( 'Critical safeguard: User-agent * is fully blocked. This can stop useful crawling site-wide.', 'open-growth-seo' );
			}
		}
		return $errors;
	}

	private static function warnings( string $content, bool $public ): array {
		$warnings = array();
		if ( false === stripos( $content, 'Sitemap:' ) ) {
			$warnings[] = __( 'No Sitemap directive found in robots.txt.', 'open-growth-seo' );
		}
		if ( false !== stripos( $content, 'Disallow: /wp-content/' ) ) {
			$warnings[] = __( 'Blocking /wp-content/ may hide assets required for rendering.', 'open-growth-seo' );
		}
		if ( false !== stripos( $content, 'Disallow: /wp-admin/' ) && false === stripos( $content, 'Allow: /wp-admin/admin-ajax.php' ) ) {
			$warnings[] = __( 'Disallowing /wp-admin/ without allowing /wp-admin/admin-ajax.php can block valid crawler requests.', 'open-growth-seo' );
		}
		if ( $public ) {
			$groups = self::parse_groups( $content );
			foreach ( array( 'GPTBot', 'OAI-SearchBot' ) as $bot ) {
				$rules = $groups[ $bot ] ?? array();
				if ( in_array( 'Disallow: /', $rules, true ) && ! in_array( 'Allow: /', $rules, true ) ) {
					$warnings[] = sprintf(
						/* translators: %s: bot user-agent */
						__( '%s is fully disallowed. This bot will not crawl site content.', 'open-growth-seo' ),
						$bot
					);
				}
			}
		}
		return $warnings;
	}

	public static function preview_for_bot( string $content, string $bot ): array {
		$groups = self::parse_groups( $content );
		$bot    = trim( $bot );
		if ( '' === $bot ) {
			$bot = '*';
		}
		$exact = array();
		foreach ( $groups as $ua => $rules ) {
			if ( strtolower( $ua ) === strtolower( $bot ) ) {
				$exact = $rules;
				break;
			}
		}
		if ( empty( $exact ) ) {
			foreach ( $groups as $ua => $rules ) {
				if ( '*' === $ua ) {
					$exact = $rules;
					break;
				}
			}
		}
		return $exact;
	}

	private static function parse_groups( string $content ): array {
		$groups      = array();
		$current_uas = array();
		$in_group    = false;
		$lines       = preg_split( '/\r\n|\r|\n/', $content ) ?: array();
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || str_starts_with( $line, '#' ) ) {
				continue;
			}
			if ( ! preg_match( self::DIRECTIVE_REGEX, $line, $match ) ) {
				continue;
			}
			$directive = strtolower( $match[1] );
			$value     = trim( (string) $match[2] );
			if ( 'user-agent' === $directive ) {
				if ( ! $in_group ) {
					$current_uas = array();
				}
				$current_uas[] = $value;
				$current_uas   = array_values( array_unique( $current_uas ) );
				if ( ! isset( $groups[ $value ] ) ) {
					$groups[ $value ] = array();
				}
				$in_group = true;
				continue;
			}
			if ( in_array( $directive, array( 'allow', 'disallow', 'crawl-delay' ), true ) ) {
				foreach ( $current_uas as $ua ) {
					$groups[ $ua ][] = ucfirst( $directive ) . ': ' . $value;
				}
				$in_group = false;
			}
		}
		return $groups;
	}

	public static function has_physical_robots(): bool {
		return file_exists( trailingslashit( ABSPATH ) . 'robots.txt' );
	}
}
