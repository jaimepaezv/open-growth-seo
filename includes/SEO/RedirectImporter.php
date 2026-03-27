<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\SEO;

defined( 'ABSPATH' ) || exit;

class RedirectImporter {
	private const LAST_REPORT_OPTION = 'ogs_seo_redirect_import_last_report';
	private const LAST_ROLLBACK_PLAN_OPTION = 'ogs_seo_redirect_import_last_rollback_plan';
	private const LAST_ROLLBACK_SNAPSHOT_OPTION = 'ogs_seo_redirect_import_last_rollback_snapshot';
	private const MAX_SCAN_ROWS = 5000;

	public static function provider_labels(): array {
		return array(
			'yoast'       => 'Yoast SEO',
			'rankmath'    => 'Rank Math',
			'aioseo'      => 'All in One SEO',
			'redirection' => 'Redirection',
		);
	}

	public static function detect_sources(): array {
		$labels = self::provider_labels();
		$sources = array();
		foreach ( array_keys( $labels ) as $slug ) {
			$preview = self::collect_provider_rows( $slug, 5 );
			$sources[ $slug ] = array(
				'label'       => $labels[ $slug ],
				'detected'    => ! empty( $preview ),
				'sample_size' => count( $preview ),
			);
		}
		return $sources;
	}

	public static function get_last_report(): array {
		$report = get_option( self::LAST_REPORT_OPTION, array() );
		return is_array( $report ) ? $report : array();
	}

	public static function get_last_rollback_plan(): array {
		$plan = get_option( self::LAST_ROLLBACK_PLAN_OPTION, array() );
		return is_array( $plan ) ? $plan : array();
	}

	public static function has_rollback_snapshot(): bool {
		$snapshot = get_option( self::LAST_ROLLBACK_SNAPSHOT_OPTION, array() );
		return is_array( $snapshot )
			&& ! empty( $snapshot['captured_at'] )
			&& array_key_exists( 'rules', $snapshot )
			&& is_array( $snapshot['rules'] );
	}

	public static function dry_run( array $providers = array(), bool $merge = true ): array {
		$providers = self::resolve_providers( $providers );
		$raw_rows  = self::collect_rows( $providers );
		$existing_map   = self::existing_rule_map();
		$classified     = self::classify_rows( $raw_rows, $existing_map );
		$rows           = isset( $classified['rows'] ) && is_array( $classified['rows'] ) ? $classified['rows'] : array();
		$counts         = isset( $classified['counts'] ) && is_array( $classified['counts'] ) ? $classified['counts'] : array();
		$existing_rules = Redirects::get_rules();

		$report = array(
			'time'              => time(),
			'providers'         => $providers,
			'mode'              => $merge ? 'merge' : 'replace',
			'summary'           => array(
				'total'             => count( $rows ),
				'counts'            => $counts,
				'existing_rules'    => count( $existing_rules ),
				'destructive_merge' => ! $merge,
			),
			'conflict_preview'  => self::build_conflict_preview( $rows ),
			'rollback_plan'     => self::build_rollback_plan( $providers, $merge, count( $existing_rules ), count( $rows ) ),
			'rows'              => array_slice( $rows, 0, 300 ),
			'requires_confirm'  => ! $merge && count( $existing_rules ) > 0,
		);
		update_option( self::LAST_REPORT_OPTION, $report, false );
		update_option( self::LAST_ROLLBACK_PLAN_OPTION, (array) $report['rollback_plan'], false );
		return $report;
	}

	public static function run_import( array $providers = array(), bool $merge = true, bool $confirmed = false ): array {
		$preview = self::dry_run( $providers, $merge );
		if ( empty( $confirmed ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Import requires explicit confirmation before applying changes.', 'open-growth-seo' ),
				'preview' => $preview,
			);
		}
		$providers = self::resolve_providers( $providers );
		$raw_rows  = self::collect_rows( $providers );
		$classify  = self::classify_rows( $raw_rows, self::existing_rule_map() );
		$rows      = isset( $classify['rows'] ) && is_array( $classify['rows'] ) ? $classify['rows'] : array();
		$importable = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$class = (string) ( $row['classification'] ?? '' );
			if ( $merge && 'ready' !== $class ) {
				continue;
			}
			if ( ! $merge && ! in_array( $class, array( 'ready', 'duplicate_existing', 'conflict_existing' ), true ) ) {
				continue;
			}
			$key = self::rule_key( (string) ( $row['source_path'] ?? '' ), (string) ( $row['match_type'] ?? 'exact' ) );
			if ( '' === $key ) {
				continue;
			}
			$importable[ $key ] = array(
				'id'              => sanitize_key( substr( md5( $key . '|' . (string) ( $row['destination_url'] ?? '' ) ), 0, 18 ) ),
				'source_path'     => (string) ( $row['source_path'] ?? '' ),
				'destination_url' => (string) ( $row['destination_url'] ?? '' ),
				'match_type'      => (string) ( $row['match_type'] ?? 'exact' ),
				'status_code'     => (int) ( $row['status_code'] ?? 301 ),
				'enabled'         => ! empty( $row['enabled'] ) ? 1 : 0,
				'note'            => (string) ( $row['note'] ?? '' ),
			);
		}
		$importable = array_values( $importable );

		$inserted = 0;
		$failed   = 0;
		$result_detail = array();
		$rules_before = Redirects::get_rules();
		$before_count = count( $rules_before );
		$before_hash  = md5( wp_json_encode( $rules_before ) ?: '' );
		$snapshot     = array(
			'captured_at' => time(),
			'providers'   => $providers,
			'mode'        => $merge ? 'merge' : 'replace',
			'rule_count'  => $before_count,
			'rules_hash'  => $before_hash,
			'has_snapshot' => true,
			'rules'       => $rules_before,
		);

		if ( $merge ) {
			foreach ( $importable as $rule ) {
				$result = Redirects::add_rule( $rule );
				if ( ! empty( $result['ok'] ) ) {
					++$inserted;
				} else {
					++$failed;
				}
			}
		} else {
			$replace = Redirects::replace_rules( $importable );
			$result_detail = isset( $replace['stats'] ) && is_array( $replace['stats'] ) ? $replace['stats'] : array();
			$inserted      = (int) ( $result_detail['inserted'] ?? 0 );
			$failed        = (int) ( $result_detail['failed'] ?? 0 );
			if ( empty( $replace['ok'] ) ) {
				$failed = max( $failed, count( $importable ) );
			}
		}

		$report = array(
			'ok'            => true,
			'time'          => time(),
			'providers'     => $providers,
			'mode'          => $merge ? 'merge' : 'replace',
			'imported'      => $inserted,
			'failed'        => $failed,
			'skipped'       => max( 0, (int) ( $preview['summary']['total'] ?? 0 ) - $inserted - $failed ),
			'preview'       => $preview,
			'storage_mode'  => RedirectStore::storage_mode(),
			'detail'        => $result_detail,
			'rollback_plan' => array(
				'plan_id'            => sanitize_key( substr( md5( implode( '|', $providers ) . '|' . ( $merge ? 'merge' : 'replace' ) . '|' . time() ), 0, 16 ) ),
				'created_at'         => time(),
				'executed'           => true,
				'mode'               => $merge ? 'merge' : 'replace',
				'destructive'        => ! $merge,
				'before_rule_count'  => $before_count,
				'before_rules_hash'  => $before_hash,
				'estimated_restore'  => $before_count,
				'providers'          => $providers,
				'rollback_available' => true,
				'snapshot_rule_count' => $before_count,
				'snapshot_rules_hash' => $before_hash,
			),
		);
		update_option( self::LAST_REPORT_OPTION, $report, false );
		update_option( self::LAST_ROLLBACK_PLAN_OPTION, (array) $report['rollback_plan'], false );
		update_option( self::LAST_ROLLBACK_SNAPSHOT_OPTION, $snapshot, false );
		return $report;
	}

	public static function rollback_last_import(): array {
		$plan     = self::get_last_rollback_plan();
		$snapshot = get_option( self::LAST_ROLLBACK_SNAPSHOT_OPTION, array() );
		if ( ! is_array( $snapshot ) || ! array_key_exists( 'rules', $snapshot ) || ! is_array( $snapshot['rules'] ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'No redirect import snapshot is available for rollback.', 'open-growth-seo' ),
			);
		}

		$restored_rules = array_values(
			array_filter(
				(array) $snapshot['rules'],
				static fn( $rule ) => is_array( $rule )
			)
		);
		$result = Redirects::replace_rules( $restored_rules );
		if ( empty( $result['ok'] ) ) {
			return array(
				'ok'      => false,
				'message' => (string) ( $result['message'] ?? __( 'Redirect rollback failed while restoring stored rules.', 'open-growth-seo' ) ),
			);
		}

		$report = array(
			'ok'              => true,
			'time'            => time(),
			'mode'            => 'rollback',
			'restored'        => count( $restored_rules ),
			'storage_mode'    => RedirectStore::storage_mode(),
			'rollback_plan'   => array_merge(
				$plan,
				array(
					'rollback_available' => false,
					'rolled_back_at'     => time(),
					'rollback_restored'  => count( $restored_rules ),
				)
			),
			'rollback_result' => $result,
		);

		update_option( self::LAST_REPORT_OPTION, $report, false );
		update_option( self::LAST_ROLLBACK_PLAN_OPTION, (array) $report['rollback_plan'], false );
		delete_option( self::LAST_ROLLBACK_SNAPSHOT_OPTION );

		return $report;
	}

	private static function existing_rule_map(): array {
		$existing_rules = Redirects::get_rules();
		$existing_map   = array();
		foreach ( $existing_rules as $rule ) {
			$key = self::rule_key( (string) ( $rule['source_path'] ?? '' ), (string) ( $rule['match_type'] ?? 'exact' ) );
			if ( '' === $key ) {
				continue;
			}
			$existing_map[ $key ] = array(
				'destination_url' => (string) ( $rule['destination_url'] ?? '' ),
				'status_code'     => (int) ( $rule['status_code'] ?? 301 ),
			);
		}
		return $existing_map;
	}

	private static function classify_rows( array $raw_rows, array $existing_map ): array {
		$seen_payload = array();
		$rows         = array();
		$counts       = array(
			'ready'               => 0,
			'duplicate_existing'  => 0,
			'conflict_existing'   => 0,
			'duplicate_payload'   => 0,
			'invalid_source'      => 0,
			'invalid_destination' => 0,
			'loop'                => 0,
		);

		foreach ( $raw_rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$source      = Redirects::normalize_source_path( (string) ( $row['source_path'] ?? '' ) );
			$destination = Redirects::normalize_destination_url( (string) ( $row['destination_url'] ?? '' ) );
			$match_type  = sanitize_key( (string) ( $row['match_type'] ?? 'exact' ) );
			$match_type  = in_array( $match_type, array( 'exact', 'prefix' ), true ) ? $match_type : 'exact';
			$status      = absint( $row['status_code'] ?? 301 );
			$status      = in_array( $status, array( 301, 302 ), true ) ? $status : 301;

			$class = 'ready';
			$note  = '';
			if ( '' === $source || '/' === $source ) {
				$class = 'invalid_source';
				$note  = __( 'Source path is empty or invalid.', 'open-growth-seo' );
			} elseif ( '' === $destination ) {
				$class = 'invalid_destination';
				$note  = __( 'Destination URL is invalid or points outside this site.', 'open-growth-seo' );
			} else {
				$destination_path = Redirects::normalize_source_path( (string) wp_parse_url( $destination, PHP_URL_PATH ) );
				if ( '' !== $destination_path && $destination_path === $source ) {
					$class = 'loop';
					$note  = __( 'Source and destination resolve to the same path.', 'open-growth-seo' );
				}
			}

			$key = self::rule_key( $source, $match_type );
			if ( 'ready' === $class && '' !== $key && isset( $existing_map[ $key ] ) ) {
				$existing_destination = (string) ( $existing_map[ $key ]['destination_url'] ?? '' );
				$existing_status      = (int) ( $existing_map[ $key ]['status_code'] ?? 301 );
				if ( $existing_destination === $destination && $existing_status === $status ) {
					$class = 'duplicate_existing';
					$note  = __( 'An identical redirect already exists.', 'open-growth-seo' );
				} else {
					$class = 'conflict_existing';
					$note  = __( 'An existing redirect with same source/match points to a different target.', 'open-growth-seo' );
				}
			}
			if ( 'ready' === $class && '' !== $key && isset( $seen_payload[ $key ] ) ) {
				$class = 'duplicate_payload';
				$note  = __( 'Duplicate source/match pair inside import payload.', 'open-growth-seo' );
			}
			if ( 'ready' === $class && '' !== $key ) {
				$seen_payload[ $key ] = 1;
			}

			if ( isset( $counts[ $class ] ) ) {
				++$counts[ $class ];
			}

			$rows[] = array(
				'provider'        => sanitize_key( (string) ( $row['provider'] ?? 'unknown' ) ),
				'source_path'     => $source,
				'destination_url' => $destination,
				'match_type'      => $match_type,
				'status_code'     => $status,
				'enabled'         => ! empty( $row['enabled'] ) ? 1 : 0,
				'note'            => sanitize_text_field( (string) ( $row['note'] ?? '' ) ),
				'classification'  => $class,
				'message'         => $note,
				'source_hash'     => sanitize_key( substr( md5( $source . '|' . $destination . '|' . $match_type ), 0, 12 ) ),
			);
		}

		return array(
			'rows'   => $rows,
			'counts' => $counts,
		);
	}

	private static function resolve_providers( array $providers ): array {
		$supported = array_keys( self::provider_labels() );
		if ( empty( $providers ) ) {
			return $supported;
		}
		$providers = array_values( array_unique( array_map( 'sanitize_key', $providers ) ) );
		$providers = array_values( array_intersect( $providers, $supported ) );
		return empty( $providers ) ? $supported : $providers;
	}

	private static function collect_rows( array $providers ): array {
		$rows = array();
		foreach ( $providers as $provider ) {
			$rows = array_merge( $rows, self::collect_provider_rows( (string) $provider, self::MAX_SCAN_ROWS ) );
		}
		return array_values( array_slice( $rows, 0, self::MAX_SCAN_ROWS ) );
	}

	private static function collect_provider_rows( string $provider, int $limit ): array {
		$provider = sanitize_key( $provider );
		$limit    = max( 1, min( self::MAX_SCAN_ROWS, absint( $limit ) ) );
		switch ( $provider ) {
			case 'yoast':
				return self::collect_yoast_rows( $limit );
			case 'rankmath':
				return self::collect_rankmath_rows( $limit );
			case 'aioseo':
				return self::collect_aioseo_rows( $limit );
			case 'redirection':
				return self::collect_redirection_rows( $limit );
			default:
				return array();
		}
	}

	private static function collect_yoast_rows( int $limit ): array {
		$rows = array();
		$options = array(
			'wpseo-premium-redirects',
			'wpseo-premium-redirect-export-plain',
		);
		foreach ( $options as $option_name ) {
			$value = get_option( $option_name, array() );
			$rows  = array_merge( $rows, self::normalize_provider_payload( 'yoast', $value, $limit ) );
			if ( count( $rows ) >= $limit ) {
				break;
			}
		}
		return array_slice( $rows, 0, $limit );
	}

	private static function collect_rankmath_rows( int $limit ): array {
		$rows = array();
		$options = array(
			'rank_math_redirections',
			'rank_math_options_redirections',
		);
		foreach ( $options as $option_name ) {
			$value = get_option( $option_name, array() );
			$rows  = array_merge( $rows, self::normalize_provider_payload( 'rankmath', $value, $limit ) );
			if ( count( $rows ) >= $limit ) {
				break;
			}
		}
		return array_slice( $rows, 0, $limit );
	}

	private static function collect_aioseo_rows( int $limit ): array {
		$rows = array();
		$aioseo_redirects = get_option( 'aioseo_redirects', array() );
		$rows = array_merge( $rows, self::normalize_provider_payload( 'aioseo', $aioseo_redirects, $limit ) );

		$aioseo_options = get_option( 'aioseo_options', array() );
		if ( is_array( $aioseo_options ) ) {
			$redirect_section = $aioseo_options['redirects'] ?? array();
			$rows = array_merge( $rows, self::normalize_provider_payload( 'aioseo', $redirect_section, $limit ) );
		}

		return array_slice( $rows, 0, $limit );
	}

	private static function collect_redirection_rows( int $limit ): array {
		$rows = array();
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! isset( $wpdb->prefix ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return $rows;
		}
		$table = (string) $wpdb->prefix . 'redirection_items';
		$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $table !== $exists ) {
			return $rows;
		}

		$raw = $wpdb->get_results(
			'SELECT * FROM ' . $table . ' ORDER BY id DESC LIMIT ' . absint( $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static table name and internal integer limit.
			ARRAY_A
		);
		foreach ( (array) $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$destination = '';
			if ( ! empty( $item['action_data'] ) ) {
				$decoded = json_decode( (string) $item['action_data'], true );
				if ( is_array( $decoded ) ) {
					$destination = (string) ( $decoded['url'] ?? $decoded['target'] ?? '' );
				}
				if ( '' === $destination && function_exists( 'maybe_unserialize' ) ) {
					$decoded = maybe_unserialize( $item['action_data'] );
					if ( is_array( $decoded ) ) {
						$destination = (string) ( $decoded['url'] ?? $decoded['target'] ?? '' );
					}
				}
			}
			$rows[] = array(
				'provider'        => 'redirection',
				'source_path'     => (string) ( $item['url'] ?? $item['match_url'] ?? '' ),
				'destination_url' => '' !== $destination ? $destination : (string) ( $item['target'] ?? '' ),
				'match_type'      => ! empty( $item['regex'] ) ? 'prefix' : 'exact',
				'status_code'     => absint( $item['action_code'] ?? 301 ),
				'enabled'         => ( isset( $item['status'] ) && 'enabled' === strtolower( (string) $item['status'] ) ) ? 1 : 1,
				'note'            => sanitize_text_field( (string) ( $item['title'] ?? '' ) ),
			);
		}
		return array_slice( $rows, 0, $limit );
	}

	private static function normalize_provider_payload( string $provider, $payload, int $limit ): array {
		$rows = array();
		if ( is_object( $payload ) ) {
			$payload = (array) $payload;
		}
		if ( ! is_array( $payload ) ) {
			return $rows;
		}

		foreach ( $payload as $key => $value ) {
			if ( count( $rows ) >= $limit ) {
				break;
			}
			if ( is_array( $value ) ) {
				$source = (string) ( $value['source_path'] ?? $value['source'] ?? $value['from'] ?? $value['origin'] ?? $value['url_from'] ?? $key );
				$destination = (string) ( $value['destination_url'] ?? $value['destination'] ?? $value['to'] ?? $value['target'] ?? $value['url_to'] ?? $value['url'] ?? '' );
				$rows[] = array(
					'provider'        => $provider,
					'source_path'     => $source,
					'destination_url' => $destination,
					'match_type'      => (string) ( $value['match_type'] ?? $value['type'] ?? 'exact' ),
					'status_code'     => absint( $value['status_code'] ?? $value['status'] ?? $value['type'] ?? 301 ),
					'enabled'         => array_key_exists( 'enabled', $value ) ? ( ! empty( $value['enabled'] ) ? 1 : 0 ) : 1,
					'note'            => sanitize_text_field( (string) ( $value['note'] ?? $value['title'] ?? '' ) ),
				);
				continue;
			}
			if ( is_string( $key ) && is_scalar( $value ) ) {
				$rows[] = array(
					'provider'        => $provider,
					'source_path'     => (string) $key,
					'destination_url' => (string) $value,
					'match_type'      => 'exact',
					'status_code'     => 301,
					'enabled'         => 1,
					'note'            => '',
				);
			}
		}
		return $rows;
	}

	private static function rule_key( string $source, string $match_type ): string {
		$source = Redirects::normalize_source_path( $source );
		if ( '' === $source || '/' === $source ) {
			return '';
		}
		$match_type = sanitize_key( $match_type );
		$match_type = in_array( $match_type, array( 'exact', 'prefix' ), true ) ? $match_type : 'exact';
		return $source . '|' . $match_type;
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 * @return array<string, mixed>
	 */
	private static function build_conflict_preview( array $rows ): array {
		$preview = array(
			'duplicate_existing' => array(),
			'conflict_existing'  => array(),
			'invalid_rows'       => array(),
		);
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$classification = sanitize_key( (string) ( $row['classification'] ?? '' ) );
			$item = array(
				'provider'        => sanitize_key( (string) ( $row['provider'] ?? '' ) ),
				'source_path'     => (string) ( $row['source_path'] ?? '' ),
				'destination_url' => (string) ( $row['destination_url'] ?? '' ),
				'message'         => (string) ( $row['message'] ?? '' ),
			);
			if ( 'duplicate_existing' === $classification ) {
				$preview['duplicate_existing'][] = $item;
			} elseif ( 'conflict_existing' === $classification ) {
				$preview['conflict_existing'][] = $item;
			} elseif ( in_array( $classification, array( 'invalid_source', 'invalid_destination', 'loop', 'duplicate_payload' ), true ) ) {
				$preview['invalid_rows'][] = $item;
			}
		}
		$preview['duplicate_existing'] = array_slice( $preview['duplicate_existing'], 0, 20 );
		$preview['conflict_existing']  = array_slice( $preview['conflict_existing'], 0, 20 );
		$preview['invalid_rows']       = array_slice( $preview['invalid_rows'], 0, 20 );
		return $preview;
	}

	/**
	 * @param array<int, string> $providers
	 * @return array<string, mixed>
	 */
	private static function build_rollback_plan( array $providers, bool $merge, int $existing_rules, int $rows ): array {
		return array(
			'plan_id'               => sanitize_key( substr( md5( implode( '|', $providers ) . '|' . ( $merge ? 'merge' : 'replace' ) . '|' . time() ), 0, 16 ) ),
			'created_at'            => time(),
			'executed'              => false,
			'providers'             => $providers,
			'mode'                  => $merge ? 'merge' : 'replace',
			'destructive'           => ! $merge,
			'existing_rule_count'   => max( 0, $existing_rules ),
			'estimated_import_rows' => max( 0, $rows ),
			'estimated_restore'     => max( 0, $existing_rules ),
			'storage_mode'          => RedirectStore::storage_mode(),
			'requires_confirm'      => ! $merge && $existing_rules > 0,
			'rollback_available'    => false,
			'notes'                 => ! $merge
				? __( 'Replace mode will remove current rules before inserting imported rows. A one-click rollback snapshot will be captured when the import runs.', 'open-growth-seo' )
				: __( 'Merge mode appends safe rows. A one-click rollback snapshot will be captured when the import runs.', 'open-growth-seo' ),
		);
	}
}
