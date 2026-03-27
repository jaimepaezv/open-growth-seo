<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\SEO;

use OpenGrowthSolutions\OpenGrowthSEO\Support\Settings;

defined( 'ABSPATH' ) || exit;

class Redirects {
	private const MAX_RULES = 1000;

	public function register(): void {
		RedirectStore::register();
		RedirectStore::bootstrap();

		add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 1 );
		add_action( 'template_redirect', array( $this, 'capture_404' ), 99 );
		add_filter( 'ogs_seo_audit_checks', array( $this, 'register_audit_checks' ) );
	}

	public function register_audit_checks( array $checks ): array {
		$checks['redirects_health'] = array( $this, 'audit_checks' );
		return $checks;
	}

	public function maybe_redirect(): void {
		if ( ! Settings::get( 'redirects_enabled', 1 ) ) {
			return;
		}
		if ( is_admin() || is_feed() || ! function_exists( 'is_404' ) || ! is_404() ) {
			return;
		}
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return;
		}

		$path = self::current_request_path();
		if ( '' === $path ) {
			return;
		}

		$match = self::match_rule( $path, self::get_rules() );
		if ( empty( $match ) ) {
			return;
		}

		$destination = isset( $match['destination_url'] ) ? (string) $match['destination_url'] : '';
		$status      = isset( $match['status_code'] ) ? (int) $match['status_code'] : 301;
		if ( '' === $destination || ! in_array( $status, array( 301, 302 ), true ) ) {
			return;
		}

		$destination_path = self::normalize_source_path( (string) wp_parse_url( $destination, PHP_URL_PATH ) );
		if ( '' !== $destination_path && $destination_path === $path ) {
			return;
		}

		self::touch_rule_hit( (string) ( $match['id'] ?? '' ) );
		wp_safe_redirect( $destination, $status, 'Open Growth SEO Redirects' );
		exit;
	}

	public function capture_404(): void {
		if ( ! Settings::get( 'redirect_404_logging_enabled', 1 ) ) {
			return;
		}
		if ( is_admin() || is_feed() || ! function_exists( 'is_404' ) || ! is_404() ) {
			return;
		}
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return;
		}

		$path = self::current_request_path();
		if ( '' === $path || '/' === $path ) {
			return;
		}

		$referrer = function_exists( 'wp_get_referer' ) ? (string) wp_get_referer() : '';
		self::record_404_hit( $path, $referrer );
	}

	public function audit_checks(): array {
		$issues     = array();
		$integrity  = self::integrity_report();
		$errors     = isset( $integrity['errors'] ) && is_array( $integrity['errors'] ) ? $integrity['errors'] : array();
		$warnings   = isset( $integrity['warnings'] ) && is_array( $integrity['warnings'] ) ? $integrity['warnings'] : array();
		$unresolved = self::top_unresolved_404( 5, 3 );

		foreach ( array_slice( $errors, 0, 5 ) as $error ) {
			if ( ! is_array( $error ) ) {
				continue;
			}
			$issues[] = array(
				'severity'       => 'critical',
				'title'          => __( 'Redirect rule conflict detected', 'open-growth-seo' ),
				'detail'         => (string) ( $error['message'] ?? __( 'Invalid redirect configuration was detected.', 'open-growth-seo' ) ),
				'recommendation' => __( 'Review the Redirects screen and disable or remove the conflicting rule.', 'open-growth-seo' ),
				'source'         => 'redirects',
				'trace'          => array(
					'rule_id'     => (string) ( $error['rule_id'] ?? '' ),
					'source_path' => (string) ( $error['source_path'] ?? '' ),
					'setting'     => 'redirects_enabled',
				),
			);
		}

		foreach ( array_slice( $warnings, 0, 3 ) as $warning ) {
			if ( ! is_array( $warning ) ) {
				continue;
			}
			$issues[] = array(
				'severity'       => 'important',
				'title'          => __( 'Redirect rule needs review', 'open-growth-seo' ),
				'detail'         => (string) ( $warning['message'] ?? __( 'A redirect may be too broad for current routes.', 'open-growth-seo' ) ),
				'recommendation' => __( 'Review source path matching and keep broad prefix rules intentional.', 'open-growth-seo' ),
				'source'         => 'redirects',
				'trace'          => array(
					'rule_id'     => (string) ( $warning['rule_id'] ?? '' ),
					'source_path' => (string) ( $warning['source_path'] ?? '' ),
					'setting'     => 'redirects_enabled',
				),
			);
		}

		foreach ( $unresolved as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$path = isset( $entry['path'] ) ? (string) $entry['path'] : '';
			$hits = isset( $entry['hits'] ) ? (int) $entry['hits'] : 0;
			$issues[] = array(
				'severity'       => $hits >= 10 ? 'important' : 'minor',
				'title'          => __( 'High-volume 404 URL detected', 'open-growth-seo' ),
				'detail'         => sprintf(
					/* translators: 1: missing path, 2: hit count */
					__( 'Path %1$s generated %2$d recent 404 requests and has no matching redirect rule.', 'open-growth-seo' ),
					$path,
					$hits
				),
				'recommendation' => __( 'Create a redirect only if this URL should resolve. Otherwise keep it as 404 to avoid masking real content gaps.', 'open-growth-seo' ),
				'source'         => 'redirects',
				'trace'          => array(
					'source_path' => $path,
					'hits'        => (string) $hits,
				),
			);
		}

		return $issues;
	}

	public static function get_rules(): array {
		return RedirectStore::get_rules();
	}

	public static function get_404_log(): array {
		return RedirectStore::get_404_log();
	}

	public static function status_payload(): array {
		$rules   = self::get_rules();
		$enabled = 0;
		foreach ( $rules as $rule ) {
			if ( ! empty( $rule['enabled'] ) ) {
				++$enabled;
			}
		}
		$log        = self::get_404_log();
		$unresolved = self::top_unresolved_404( 10, 1, $rules, $log );
		return array(
			'storage_mode'     => RedirectStore::storage_mode(),
			'migration'        => RedirectStore::last_migration_report(),
			'rules_total'      => count( $rules ),
			'rules_enabled'    => $enabled,
			'rules_disabled'   => count( $rules ) - $enabled,
			'log_entries'      => count( $log ),
			'unresolved_top'   => $unresolved,
			'integrity'        => self::integrity_report( $rules ),
		);
	}

	public static function add_rule( array $payload ): array {
		$source = self::normalize_source_path( (string) ( $payload['source_path'] ?? '' ) );
		if ( '' === $source || '/' === $source ) {
			return array(
				'ok'      => false,
				'message' => __( 'Source path is required and must be a site-relative path like /old-page/.', 'open-growth-seo' ),
			);
		}
		if ( str_starts_with( $source, '/wp-admin' ) || '/wp-login.php' === $source ) {
			return array(
				'ok'      => false,
				'message' => __( 'Protected WordPress core paths cannot be redirected from this UI.', 'open-growth-seo' ),
			);
		}

		$destination = self::normalize_destination_url( (string) ( $payload['destination_url'] ?? '' ) );
		if ( '' === $destination ) {
			return array(
				'ok'      => false,
				'message' => __( 'Destination URL is required and must stay on this site.', 'open-growth-seo' ),
			);
		}
		$destination_path = self::normalize_source_path( (string) wp_parse_url( $destination, PHP_URL_PATH ) );
		if ( '' !== $destination_path && $destination_path === $source ) {
			return array(
				'ok'      => false,
				'message' => __( 'Source and destination resolve to the same path. This would create a redirect loop.', 'open-growth-seo' ),
			);
		}

		$match_type = sanitize_key( (string) ( $payload['match_type'] ?? 'exact' ) );
		$match_type = in_array( $match_type, array( 'exact', 'prefix' ), true ) ? $match_type : 'exact';
		$status     = absint( $payload['status_code'] ?? 301 );
		$status     = in_array( $status, array( 301, 302 ), true ) ? $status : 301;
		$enabled    = ! empty( $payload['enabled'] ) ? 1 : 0;
		$note       = sanitize_text_field( (string) ( $payload['note'] ?? '' ) );

		$rules = self::get_rules();
		if ( count( $rules ) >= self::MAX_RULES ) {
			return array(
				'ok'      => false,
				'message' => __( 'Redirect rule limit reached. Remove unused rules before adding new ones.', 'open-growth-seo' ),
			);
		}

		foreach ( $rules as $rule ) {
			if ( $source === (string) ( $rule['source_path'] ?? '' ) && $match_type === (string) ( $rule['match_type'] ?? 'exact' ) ) {
				return array(
					'ok'      => false,
					'message' => __( 'A redirect with the same source path and match type already exists.', 'open-growth-seo' ),
				);
			}
		}

		$rule = array(
			'id'              => sanitize_key( substr( md5( $source . '|' . $destination . '|' . microtime( true ) ), 0, 18 ) ),
			'source_path'     => $source,
			'destination_url' => $destination,
			'match_type'      => $match_type,
			'status_code'     => $status,
			'enabled'         => $enabled,
			'hits'            => 0,
			'last_hit'        => 0,
			'note'            => $note,
			'created_at'      => time(),
			'updated_at'      => time(),
		);

		$candidate = array_merge( $rules, array( $rule ) );
		$integrity = self::integrity_report( $candidate );
		if ( ! empty( $integrity['errors'] ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'The new rule conflicts with existing redirects and could cause loops.', 'open-growth-seo' ),
			);
		}

		if ( ! RedirectStore::add_rule( $rule ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Redirect rule could not be persisted due to a storage error.', 'open-growth-seo' ),
			);
		}

		return array(
			'ok'      => true,
			'message' => __( 'Redirect rule added.', 'open-growth-seo' ),
		);
	}

	public static function replace_rules( array $rules ): array {
		$result = RedirectStore::replace_rules( $rules );
		if ( empty( $result['ok'] ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Redirect replacement failed due to a storage error.', 'open-growth-seo' ),
			);
		}
		return array(
			'ok'      => true,
			'message' => sprintf(
				/* translators: 1: inserted rules, 2: failed rules */
				__( 'Redirect replacement completed. Imported: %1$d. Failed: %2$d.', 'open-growth-seo' ),
				(int) ( $result['inserted'] ?? 0 ),
				(int) ( $result['failed'] ?? 0 )
			),
			'stats'   => $result,
		);
	}

	public static function delete_rule( string $id ): array {
		$id = sanitize_key( $id );
		if ( '' === $id ) {
			return array(
				'ok'      => false,
				'message' => __( 'Redirect rule id is required.', 'open-growth-seo' ),
			);
		}

		if ( ! RedirectStore::delete_rule( $id ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Redirect rule not found.', 'open-growth-seo' ),
			);
		}
		return array(
			'ok'      => true,
			'message' => __( 'Redirect rule removed.', 'open-growth-seo' ),
		);
	}

	public static function toggle_rule( string $id, bool $enabled ): array {
		$id = sanitize_key( $id );
		if ( '' === $id ) {
			return array(
				'ok'      => false,
				'message' => __( 'Redirect rule id is required.', 'open-growth-seo' ),
			);
		}

		if ( ! RedirectStore::toggle_rule( $id, $enabled ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Redirect rule not found.', 'open-growth-seo' ),
			);
		}

		return array(
			'ok'      => true,
			'message' => $enabled ? __( 'Redirect rule enabled.', 'open-growth-seo' ) : __( 'Redirect rule disabled.', 'open-growth-seo' ),
		);
	}

	public static function clear_404_log(): array {
		RedirectStore::clear_404_log();
		return array(
			'ok'      => true,
			'message' => __( '404 log cleared.', 'open-growth-seo' ),
		);
	}

	public static function current_request_path(): string {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		return self::normalize_source_path( $path );
	}

	public static function normalize_source_path( string $raw ): string {
		$raw = trim( wp_strip_all_tags( $raw ) );
		if ( '' === $raw ) {
			return '';
		}
		if ( str_contains( $raw, '://' ) ) {
			$host = (string) wp_parse_url( $raw, PHP_URL_HOST );
			if ( '' !== $host ) {
				$site_host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
				if ( '' !== $site_host && strtolower( $host ) !== strtolower( $site_host ) ) {
					return '';
				}
			}
			$raw = (string) wp_parse_url( $raw, PHP_URL_PATH );
		}

		$raw = str_replace( '\\', '/', $raw );
		$raw = preg_replace( '#/+#', '/', $raw );
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}
		if ( '/' !== $raw[0] ) {
			$raw = '/' . $raw;
		}
		$raw = rtrim( $raw, '/' );
		if ( '' === $raw ) {
			$raw = '/';
		}
		return strtolower( $raw );
	}

	public static function normalize_destination_url( string $raw ): string {
		$raw = trim( wp_strip_all_tags( $raw ) );
		if ( '' === $raw ) {
			return '';
		}
		if ( '/' === $raw[0] ) {
			return esc_url_raw( home_url( $raw ) );
		}
		$url = esc_url_raw( $raw );
		if ( '' === $url ) {
			return '';
		}
		$url_host  = (string) wp_parse_url( $url, PHP_URL_HOST );
		$site_host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		if ( '' !== $url_host && '' !== $site_host && strtolower( $url_host ) !== strtolower( $site_host ) ) {
			return '';
		}
		return $url;
	}

	public static function integrity_report( ?array $rules = null ): array {
		$rules = is_array( $rules ) ? $rules : self::get_rules();
		$errors   = array();
		$warnings = array();
		$seen     = array();

		foreach ( $rules as $rule ) {
			if ( empty( $rule['enabled'] ) ) {
				continue;
			}
			$rule_id     = sanitize_key( (string) ( $rule['id'] ?? '' ) );
			$source      = (string) ( $rule['source_path'] ?? '' );
			$match_type  = (string) ( $rule['match_type'] ?? 'exact' );
			$destination = (string) ( $rule['destination_url'] ?? '' );
			$status      = (int) ( $rule['status_code'] ?? 301 );
			$dedupe_key  = $source . '|' . $match_type;
			$target_path = self::normalize_source_path( (string) wp_parse_url( $destination, PHP_URL_PATH ) );

			if ( '' === $source || '' === $destination || ! in_array( $status, array( 301, 302 ), true ) ) {
				$errors[] = array(
					'rule_id'     => $rule_id,
					'source_path' => $source,
					'message'     => __( 'A redirect rule has incomplete or invalid fields.', 'open-growth-seo' ),
				);
				continue;
			}

			if ( isset( $seen[ $dedupe_key ] ) ) {
				$errors[] = array(
					'rule_id'     => $rule_id,
					'source_path' => $source,
					'message'     => __( 'Duplicate enabled redirect source detected.', 'open-growth-seo' ),
				);
				continue;
			}
			$seen[ $dedupe_key ] = 1;

			if ( '' !== $target_path && $target_path === $source ) {
				$errors[] = array(
					'rule_id'     => $rule_id,
					'source_path' => $source,
					'message'     => __( 'A redirect points to itself and would loop.', 'open-growth-seo' ),
				);
			}
			if ( 'prefix' === $match_type && '' !== $target_path && str_starts_with( $target_path, $source ) ) {
				$warnings[] = array(
					'rule_id'     => $rule_id,
					'source_path' => $source,
					'message'     => __( 'A prefix redirect points into its own source path space and may be too broad.', 'open-growth-seo' ),
				);
			}
		}

		$map = array();
		foreach ( $rules as $rule ) {
			if ( empty( $rule['enabled'] ) || 'exact' !== (string) ( $rule['match_type'] ?? 'exact' ) ) {
				continue;
			}
			$source      = (string) ( $rule['source_path'] ?? '' );
			$target_path = self::normalize_source_path( (string) wp_parse_url( (string) ( $rule['destination_url'] ?? '' ), PHP_URL_PATH ) );
			$map[ $source ] = $target_path;
		}
		foreach ( $map as $source => $target ) {
			if ( '' === $source || '' === $target ) {
				continue;
			}
			$seen_path = array( $source => true );
			$current   = $target;
			$steps     = 0;
			while ( isset( $map[ $current ] ) && $steps < 10 ) {
				if ( isset( $seen_path[ $current ] ) ) {
					$errors[] = array(
						'rule_id'     => '',
						'source_path' => $source,
						'message'     => __( 'An exact redirect chain loop was detected across multiple rules.', 'open-growth-seo' ),
					);
					break;
				}
				$seen_path[ $current ] = true;
				$current               = $map[ $current ];
				++$steps;
			}
		}

		return array(
			'errors'   => $errors,
			'warnings' => $warnings,
		);
	}

	private static function match_rule( string $path, array $rules ): array {
		$exact_match  = array();
		$prefix_match = array();
		$path         = self::normalize_source_path( $path );
		if ( '' === $path ) {
			return array();
		}

		foreach ( $rules as $rule ) {
			if ( empty( $rule['enabled'] ) ) {
				continue;
			}
			$source = (string) ( $rule['source_path'] ?? '' );
			$type   = (string) ( $rule['match_type'] ?? 'exact' );
			if ( '' === $source ) {
				continue;
			}
			if ( 'exact' === $type && $source === $path ) {
				$exact_match = $rule;
				break;
			}
			if ( 'prefix' === $type && ( str_starts_with( $path, $source . '/' ) || $source === $path ) ) {
				if ( empty( $prefix_match ) || strlen( (string) $prefix_match['source_path'] ) < strlen( $source ) ) {
					$prefix_match = $rule;
				}
			}
		}

		if ( ! empty( $exact_match ) ) {
			return $exact_match;
		}
		return $prefix_match;
	}

	private static function touch_rule_hit( string $id ): void {
		RedirectStore::touch_rule_hit( $id );
	}

	private static function record_404_hit( string $path, string $referrer ): void {
		$limit     = max( 20, min( 2000, absint( Settings::get( 'redirect_404_log_limit', 200 ) ) ) );
		$retention = max( 1, min( 3650, absint( Settings::get( 'redirect_404_retention_days', 90 ) ) ) );
		RedirectStore::record_404_hit( $path, $referrer, $limit, $retention );
	}

	private static function top_unresolved_404( int $limit, int $minimum_hits, ?array $rules = null, ?array $log = null ): array {
		$rules = is_array( $rules ) ? $rules : self::get_rules();
		$log   = is_array( $log ) ? $log : self::get_404_log();
		$out   = array();
		foreach ( $log as $entry ) {
			$path = isset( $entry['path'] ) ? (string) $entry['path'] : '';
			$hits = isset( $entry['hits'] ) ? (int) $entry['hits'] : 0;
			if ( '' === $path || $hits < $minimum_hits ) {
				continue;
			}
			if ( ! empty( self::match_rule( $path, $rules ) ) ) {
				continue;
			}
			$out[] = array(
				'path'      => $path,
				'hits'      => $hits,
				'last_seen' => (int) ( $entry['last_seen'] ?? 0 ),
			);
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}
}
