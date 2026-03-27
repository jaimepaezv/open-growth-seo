<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\SEO;

use OpenGrowthSolutions\OpenGrowthSEO\Compatibility\Detector;
use OpenGrowthSolutions\OpenGrowthSEO\Support\Settings;

defined( 'ABSPATH' ) || exit;

class Hreflang {
	private const CODE_REGEX = '/^(x-default|[a-z]{2,3}(?:-[A-Za-z]{4})?(?:-[A-Za-z]{2}|-[0-9]{3})?)$/';

	public function register(): void {
		add_action( 'wp_head', array( $this, 'render' ), 3 );
		add_filter( 'ogs_seo_audit_checks', array( $this, 'register_audit_checks' ) );
	}

	public function render(): void {
		if ( is_admin() || is_feed() || ! Settings::get( 'hreflang_enabled', 0 ) ) {
			return;
		}
		if ( $this->should_skip_output() ) {
			return;
		}

		$resolved   = $this->split_x_default_from_alternates( $this->resolve_current_alternates() );
		$alternates = $resolved['alternates'];
		if ( count( $alternates ) < 2 ) {
			return;
		}
		$post_id = get_queried_object_id();
		if ( $post_id > 0 && $this->has_canonical_conflict( $post_id ) ) {
			return;
		}
		$provider = self::detect_provider();
		if ( $post_id > 0 && ! $this->has_provider_reciprocity( $post_id, $provider ) ) {
			return;
		}
		$validation = $this->validate_alternates( $alternates );
		if ( ! empty( $validation['errors'] ) ) {
			return;
		}
		$x_default = $this->resolve_x_default( $alternates, $resolved['x_default'] );

		foreach ( $alternates as $code => $url ) {
			echo '<link rel="alternate" hreflang="' . esc_attr( $code ) . '" href="' . esc_url( $url ) . '" />' . "\n";
		}
		if ( '' !== $x_default ) {
			echo '<link rel="alternate" hreflang="x-default" href="' . esc_url( $x_default ) . '" />' . "\n";
		}
	}

	public function register_audit_checks( array $checks ): array {
		$checks['hreflang_health'] = array( $this, 'audit_hreflang_health' );
		return $checks;
	}

	public function audit_hreflang_health(): array {
		if ( ! Settings::get( 'hreflang_enabled', 0 ) ) {
			return array();
		}

		$provider = self::detect_provider();
		if ( 'none' === $provider && '' === trim( (string) Settings::get( 'hreflang_manual_map', '' ) ) ) {
			return array(
				'severity'       => 'minor',
				'title'          => __( 'Hreflang enabled without multilingual integration', 'open-growth-seo' ),
				'recommendation' => __( 'Connect a multilingual plugin (Polylang/WPML) or configure a manual mapping for homepage alternates.', 'open-growth-seo' ),
			);
		}

		$invalid_codes = 0;
		$sampled       = 0;
		$reciprocity_issues = 0;
		$canonical_conflicts = 0;

		$posts = get_posts(
			array(
				'post_type'      => get_post_types( array( 'public' => true ), 'names' ),
				'post_status'    => 'publish',
				'posts_per_page' => 12,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		foreach ( $posts as $post_id ) {
			$post_id = (int) $post_id;
			$alternates = $this->resolve_post_alternates( $post_id );
			if ( count( $alternates ) < 2 ) {
				continue;
			}
			++$sampled;
			$validation = $this->validate_alternates( $alternates );
			$invalid_codes += count( $validation['errors'] );
			if ( $this->has_canonical_conflict( $post_id ) ) {
				++$canonical_conflicts;
			}
			if ( ! $this->has_provider_reciprocity( $post_id, $provider ) ) {
				++$reciprocity_issues;
			}
			if ( $sampled >= 4 ) {
				break;
			}
		}

		if ( 0 === $invalid_codes && 0 === $reciprocity_issues && 0 === $canonical_conflicts ) {
			return array();
		}

		return array(
			'severity'       => $invalid_codes > 0 || $canonical_conflicts > 0 ? 'important' : 'minor',
			'title'          => __( 'Hreflang consistency issues detected', 'open-growth-seo' ),
			'recommendation' => sprintf(
				/* translators: 1: invalid entries, 2: reciprocity issues, 3: canonical conflicts */
				__( 'Review hreflang setup: %1$d invalid entry(ies), %2$d reciprocity issue(s), %3$d canonical conflict(s).', 'open-growth-seo' ),
				$invalid_codes,
				$reciprocity_issues,
				$canonical_conflicts
			),
		);
	}

	public function status(): array {
		$provider   = self::detect_provider();
		$languages  = self::detected_languages();
		$manual_raw = (string) Settings::get( 'hreflang_manual_map', '' );
		$manual     = $this->parse_manual_map( $manual_raw );
		$manual_errors = $this->manual_map_errors( $manual_raw );
		$resolved   = $this->split_x_default_from_alternates( $this->resolve_current_alternates() );
		$sample     = $resolved['alternates'];
		$validation = $this->validate_alternates( $sample );
		$post_id    = get_queried_object_id();
		$canonical_conflict = $post_id > 0 ? $this->has_canonical_conflict( (int) $post_id ) : false;
		$reciprocity_ok     = $post_id > 0 ? $this->has_provider_reciprocity( (int) $post_id, $provider ) : true;
		return array(
			'enabled'    => (bool) Settings::get( 'hreflang_enabled', 0 ),
			'provider'   => $provider,
			'languages'  => $languages,
			'sample'     => $sample,
			'x_default'  => $this->resolve_x_default( $sample, $resolved['x_default'] ),
			'manual_map' => $manual,
			'reciprocity_ok' => $reciprocity_ok,
			'canonical_conflict' => $canonical_conflict,
			'errors'     => array_values( array_unique( array_merge( $validation['errors'], $manual_errors ) ) ),
		);
	}

	public static function detect_provider(): string {
		if ( function_exists( 'pll_the_languages' ) ) {
			return 'polylang';
		}
		if ( defined( 'ICL_SITEPRESS_VERSION' ) && has_filter( 'wpml_active_languages' ) ) {
			return 'wpml';
		}
		if ( is_multisite() ) {
			return 'multisite';
		}
		return 'none';
	}

	public static function detected_languages(): array {
		$provider = self::detect_provider();
		if ( 'polylang' === $provider ) {
			return self::polylang_languages();
		}
		if ( 'wpml' === $provider ) {
			return self::wpml_languages();
		}
		if ( 'multisite' === $provider ) {
			return array( determine_locale() );
		}
		return array();
	}

	private function resolve_current_alternates(): array {
		$provider = self::detect_provider();
		if ( 'polylang' === $provider ) {
			return $this->from_polylang_raw();
		}
		if ( 'wpml' === $provider ) {
			return $this->from_wpml_raw();
		}
		if ( is_front_page() || is_home() ) {
			return $this->parse_manual_map( (string) Settings::get( 'hreflang_manual_map', '' ) );
		}
		return array();
	}

	private function resolve_post_alternates( int $post_id ): array {
		$provider = self::detect_provider();
		if ( 'polylang' === $provider && function_exists( 'pll_get_post_translations' ) ) {
			$translations = pll_get_post_translations( $post_id );
			$alts = array();
			foreach ( (array) $translations as $slug => $translated_id ) {
				$url = get_permalink( (int) $translated_id );
				if ( ! $url ) {
					continue;
				}
				$code = function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( (int) $translated_id, 'locale' ) : (string) $slug;
				$alts[ $this->normalize_code( $code ) ] = $this->normalize_url( $url );
			}
			return array_filter( $alts );
		}
		if ( 'wpml' === $provider ) {
			$post_type = get_post_type( $post_id ) ?: 'post';
			$trid = apply_filters( 'wpml_element_trid', null, $post_id, 'post_' . $post_type );
			$translations = apply_filters( 'wpml_get_element_translations', null, $trid, 'post_' . $post_type );
			$alts = array();
			if ( is_array( $translations ) ) {
				foreach ( $translations as $item ) {
					if ( ! is_object( $item ) || empty( $item->element_id ) || empty( $item->language_code ) ) {
						continue;
					}
					$url = get_permalink( (int) $item->element_id );
					if ( ! $url ) {
						continue;
					}
					$alts[ $this->normalize_code( (string) $item->language_code ) ] = $this->normalize_url( $url );
				}
			}
			return array_filter( $alts );
		}
		return array();
	}

	private function from_polylang_raw(): array {
		$list = pll_the_languages(
			array(
				'raw'          => 1,
				'hide_if_empty' => 0,
				'hide_current' => 0,
			)
		);
		$alternates = array();
		if ( is_array( $list ) ) {
			foreach ( $list as $item ) {
				if ( ! is_array( $item ) || empty( $item['url'] ) ) {
					continue;
				}
				$code = '';
				if ( ! empty( $item['locale'] ) ) {
					$code = (string) $item['locale'];
				} elseif ( ! empty( $item['slug'] ) ) {
					$code = (string) $item['slug'];
				}
				$code = $this->normalize_code( $code );
				$url  = $this->normalize_url( (string) $item['url'] );
				if ( '' !== $code && '' !== $url ) {
					$alternates[ $code ] = $url;
				}
			}
		}
		return $alternates;
	}

	private function from_wpml_raw(): array {
		$languages = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
		$alternates = array();
		if ( is_array( $languages ) ) {
			foreach ( $languages as $item ) {
				if ( ! is_array( $item ) || empty( $item['url'] ) || empty( $item['language_code'] ) ) {
					continue;
				}
				$code = $this->normalize_code( (string) $item['language_code'] );
				$url  = $this->normalize_url( (string) $item['url'] );
				if ( '' !== $code && '' !== $url ) {
					$alternates[ $code ] = $url;
				}
			}
		}
		return $alternates;
	}

	private static function polylang_languages(): array {
		$codes = array();
		if ( function_exists( 'pll_languages_list' ) ) {
			$locales = pll_languages_list( array( 'fields' => 'locale' ) );
			if ( is_array( $locales ) ) {
				foreach ( $locales as $locale ) {
					$codes[] = self::normalize_static_code( (string) $locale );
				}
			}
		}
		return array_values( array_filter( array_unique( $codes ) ) );
	}

	private static function wpml_languages(): array {
		$codes = array();
		$languages = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
		if ( is_array( $languages ) ) {
			foreach ( $languages as $item ) {
				if ( ! is_array( $item ) || empty( $item['language_code'] ) ) {
					continue;
				}
				$codes[] = self::normalize_static_code( (string) $item['language_code'] );
			}
		}
		return array_values( array_filter( array_unique( $codes ) ) );
	}

	private function validate_alternates( array $alternates ): array {
		$errors = array();
		foreach ( $alternates as $code => $url ) {
			if ( ! preg_match( self::CODE_REGEX, $code ) ) {
				$errors[] = sprintf( __( 'Invalid hreflang code: %s', 'open-growth-seo' ), $code );
			}
			if ( '' === $this->normalize_url( $url ) ) {
				$errors[] = sprintf( __( 'Invalid hreflang URL for code: %s', 'open-growth-seo' ), $code );
			}
		}
		return array( 'errors' => array_values( array_unique( $errors ) ) );
	}

	private function resolve_x_default( array $alternates, string $manual_x_default = '' ): string {
		$mode = (string) Settings::get( 'hreflang_x_default_mode', 'auto' );
		if ( 'none' === $mode ) {
			return '';
		}
		if ( 'custom' === $mode ) {
			return $this->normalize_url( (string) Settings::get( 'hreflang_x_default_url', '' ) );
		}
		if ( '' !== $manual_x_default ) {
			return $manual_x_default;
		}
		$provider = self::detect_provider();
		if ( 'wpml' === $provider ) {
			$default = apply_filters( 'wpml_default_language', null );
			$default = $this->normalize_code( (string) $default );
			if ( isset( $alternates[ $default ] ) ) {
				return $alternates[ $default ];
			}
		}
		if ( 'polylang' === $provider && function_exists( 'pll_default_language' ) ) {
			$default = pll_default_language( 'locale' );
			$default = $this->normalize_code( (string) $default );
			if ( isset( $alternates[ $default ] ) ) {
				return $alternates[ $default ];
			}
		}
		$first = reset( $alternates );
		return is_string( $first ) ? $first : '';
	}

	private function split_x_default_from_alternates( array $alternates ): array {
		$x_default = '';
		if ( isset( $alternates['x-default'] ) ) {
			$x_default = (string) $alternates['x-default'];
			unset( $alternates['x-default'] );
		}
		return array(
			'alternates' => $alternates,
			'x_default'  => $x_default,
		);
	}

	private function parse_manual_map( string $raw ): array {
		$lines = preg_split( '/\r\n|\r|\n/', $raw ) ?: array();
		$map   = array();
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || false === strpos( $line, '|' ) ) {
				continue;
			}
			list( $code, $url ) = array_map( 'trim', explode( '|', $line, 2 ) );
			$code = $this->normalize_code( $code );
			$url  = $this->normalize_url( $url );
			if ( '' === $code || '' === $url ) {
				continue;
			}
			$map[ $code ] = $url;
		}
		return $map;
	}

	private function manual_map_errors( string $raw ): array {
		$lines  = preg_split( '/\r\n|\r|\n/', $raw ) ?: array();
		$errors = array();
		foreach ( $lines as $i => $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( false === strpos( $line, '|' ) ) {
				$errors[] = sprintf( __( 'Manual hreflang map line %d is invalid. Use code|url format.', 'open-growth-seo' ), $i + 1 );
				continue;
			}
			list( $code, $url ) = array_map( 'trim', explode( '|', $line, 2 ) );
			if ( '' === $this->normalize_code( $code ) || '' === $this->normalize_url( $url ) ) {
				$errors[] = sprintf( __( 'Manual hreflang map line %d has invalid code or URL.', 'open-growth-seo' ), $i + 1 );
			}
		}
		return array_values( array_unique( $errors ) );
	}

	private function has_provider_reciprocity( int $post_id, string $provider ): bool {
		if ( ! in_array( $provider, array( 'polylang', 'wpml' ), true ) ) {
			return true;
		}
		$current = $this->resolve_post_alternates( $post_id );
		if ( count( $current ) < 2 ) {
			return true;
		}
		$self = $this->normalize_url( (string) get_permalink( $post_id ) );
		if ( '' === $self ) {
			return false;
		}
		$self_code = array_search( $self, $current, true );
		if ( ! is_string( $self_code ) || '' === $self_code ) {
			return false;
		}
		foreach ( $current as $code => $url ) {
			$translated_id = url_to_postid( $url );
			if ( $translated_id <= 0 ) {
				continue;
			}
			$reverse = $this->resolve_post_alternates( $translated_id );
			if ( count( $reverse ) < 2 || ! in_array( $self, $reverse, true ) ) {
				return false;
			}
			if ( ! isset( $reverse[ $self_code ] ) || $self !== $reverse[ $self_code ] ) {
				return false;
			}
		}
		return true;
	}

	private function has_canonical_conflict( int $post_id ): bool {
		if ( ! Settings::get( 'canonical_enabled', 1 ) ) {
			return false;
		}
		$canonical = $this->normalize_url( (string) get_post_meta( $post_id, 'ogs_seo_canonical', true ) );
		if ( '' === $canonical ) {
			return false;
		}
		$self = $this->normalize_url( (string) get_permalink( $post_id ) );
		return '' !== $self && $canonical !== $self;
	}

	private function should_skip_output(): bool {
		if ( ! Settings::get( 'safe_mode_seo_conflict', 1 ) ) {
			return false;
		}
		return Detector::has_active_seo_plugin();
	}

	private function normalize_code( string $code ): string {
		return self::normalize_static_code( $code );
	}

	private static function normalize_static_code( string $code ): string {
		$code = trim( str_replace( '_', '-', $code ) );
		if ( '' === $code ) {
			return '';
		}
		if ( 'x-default' === strtolower( $code ) ) {
			return 'x-default';
		}
		$parts = explode( '-', $code );
		$parts = array_values( array_filter( $parts ) );
		if ( empty( $parts ) ) {
			return '';
		}
		$parts[0] = strtolower( $parts[0] );
		if ( isset( $parts[1] ) ) {
			$parts[1] = strlen( $parts[1] ) <= 3 ? strtoupper( $parts[1] ) : ucfirst( strtolower( $parts[1] ) );
		}
		if ( isset( $parts[2] ) ) {
			$parts[2] = strtoupper( $parts[2] );
		}
		$normalized = implode( '-', $parts );
		return preg_match( self::CODE_REGEX, $normalized ) ? $normalized : '';
	}

	private function normalize_url( string $url ): string {
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
		if ( ! in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return '';
		}
		$normalized = strtolower( (string) $parts['scheme'] ) . '://' . strtolower( (string) $parts['host'] );
		if ( ! empty( $parts['port'] ) ) {
			$normalized .= ':' . absint( $parts['port'] );
		}
		$normalized .= isset( $parts['path'] ) && '' !== (string) $parts['path'] ? (string) $parts['path'] : '/';
		if ( ! empty( $parts['query'] ) ) {
			$normalized .= '?' . (string) $parts['query'];
		}
		return $normalized;
	}
}
