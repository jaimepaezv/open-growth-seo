<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\SEO;

defined( 'ABSPATH' ) || exit;

class RedirectStore {
	private const SCHEMA_VERSION = 1;
	private const SCHEMA_OPTION = 'ogs_seo_redirect_store_schema';
	private const MIGRATION_OPTION = 'ogs_seo_redirect_store_migration';
	private const RULES_OPTION = 'ogs_seo_redirect_rules';
	private const LOG_OPTION = 'ogs_seo_404_log';

	private static ?bool $tables_ready_cache = null;

	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'bootstrap' ), 5 );
	}

	public static function activate(): void {
		self::maybe_upgrade_schema( true );
		self::maybe_migrate_legacy_options();
	}

	public static function bootstrap(): void {
		self::maybe_upgrade_schema( false );
		self::maybe_migrate_legacy_options();
	}

	public static function storage_mode(): string {
		return self::tables_ready() ? 'table' : 'option';
	}

	public static function get_rules(): array {
		if ( self::tables_ready() ) {
			return self::get_rules_from_table();
		}
		return self::get_rules_from_option();
	}

	public static function add_rule( array $rule ): bool {
		$rule = self::sanitize_runtime_rule( $rule );
		if ( empty( $rule ) ) {
			return false;
		}

		if ( self::tables_ready() ) {
			global $wpdb;
			$table = self::rules_table();
			$now   = gmdate( 'Y-m-d H:i:s' );
			$last_hit = ! empty( $rule['last_hit'] ) ? gmdate( 'Y-m-d H:i:s', absint( $rule['last_hit'] ) ) : null;

			$inserted = $wpdb->insert(
				$table,
				array(
					'rule_id'         => (string) $rule['id'],
					'source_path'     => (string) $rule['source_path'],
					'destination_url' => (string) $rule['destination_url'],
					'match_type'      => (string) $rule['match_type'],
					'status_code'     => (int) $rule['status_code'],
					'enabled'         => (int) $rule['enabled'],
					'note'            => (string) $rule['note'],
					'hits'            => (int) $rule['hits'],
					'last_hit'        => $last_hit,
					'created_at'      => $now,
					'updated_at'      => $now,
				),
				array(
					'%s',
					'%s',
					'%s',
					'%s',
					'%d',
					'%d',
					'%s',
					'%d',
					'%s',
					'%s',
					'%s',
				)
			);

			return false !== $inserted;
		}

		$rules   = self::get_rules_from_option();
		$rules[] = $rule;
		update_option( self::RULES_OPTION, array_values( $rules ), false );
		return true;
	}

	public static function replace_rules( array $rules ): array {
		$normalized = array();
		foreach ( $rules as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$rule = self::sanitize_runtime_rule( $row );
			if ( ! empty( $rule ) ) {
				$normalized[] = $rule;
			}
		}

		if ( self::tables_ready() ) {
			global $wpdb;
			$table = self::rules_table();
			$deleted_sql = $wpdb->prepare( 'DELETE FROM %i', $table );
			$deleted = $wpdb->query( $deleted_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is prepared above.
			if ( false === $deleted ) {
				return array(
					'ok'       => false,
					'inserted' => 0,
					'failed'   => count( $normalized ),
				);
			}

			$inserted = 0;
			foreach ( $normalized as $rule ) {
				if ( self::add_rule( $rule ) ) {
					++$inserted;
				}
			}

			return array(
				'ok'       => ( 0 === count( $normalized ) ) || $inserted > 0,
				'inserted' => $inserted,
				'failed'   => max( 0, count( $normalized ) - $inserted ),
			);
		}

		update_option( self::RULES_OPTION, array_values( $normalized ), false );
		return array(
			'ok'       => true,
			'inserted' => count( $normalized ),
			'failed'   => 0,
		);
	}

	public static function delete_rule( string $id ): bool {
		$id = sanitize_key( $id );
		if ( '' === $id ) {
			return false;
		}

		if ( self::tables_ready() ) {
			global $wpdb;
			$deleted = $wpdb->delete( self::rules_table(), array( 'rule_id' => $id ), array( '%s' ) );
			return false !== $deleted && $deleted > 0;
		}

		$rules = self::get_rules_from_option();
		$next  = array();
		$found = false;
		foreach ( $rules as $rule ) {
			if ( $id === sanitize_key( (string) ( $rule['id'] ?? '' ) ) ) {
				$found = true;
				continue;
			}
			$next[] = $rule;
		}
		if ( ! $found ) {
			return false;
		}
		update_option( self::RULES_OPTION, array_values( $next ), false );
		return true;
	}

	public static function toggle_rule( string $id, bool $enabled ): bool {
		$id = sanitize_key( $id );
		if ( '' === $id ) {
			return false;
		}

		if ( self::tables_ready() ) {
			global $wpdb;
			$updated = $wpdb->update(
				self::rules_table(),
				array(
					'enabled'    => $enabled ? 1 : 0,
					'updated_at' => gmdate( 'Y-m-d H:i:s' ),
				),
				array( 'rule_id' => $id ),
				array( '%d', '%s' ),
				array( '%s' )
			);
			return false !== $updated && $updated > 0;
		}

		$rules = self::get_rules_from_option();
		$found = false;
		foreach ( $rules as &$rule ) {
			if ( $id !== sanitize_key( (string) ( $rule['id'] ?? '' ) ) ) {
				continue;
			}
			$rule['enabled']    = $enabled ? 1 : 0;
			$rule['updated_at'] = time();
			$found              = true;
			break;
		}
		unset( $rule );

		if ( ! $found ) {
			return false;
		}
		update_option( self::RULES_OPTION, array_values( $rules ), false );
		return true;
	}

	public static function touch_rule_hit( string $id ): void {
		$id = sanitize_key( $id );
		if ( '' === $id ) {
			return;
		}

		if ( self::tables_ready() ) {
			global $wpdb;
			$now   = gmdate( 'Y-m-d H:i:s' );
			$sql   = $wpdb->prepare(
				'UPDATE %i SET hits = hits + 1, last_hit = %s, updated_at = %s WHERE rule_id = %s',
				self::rules_table(),
				$now,
				$now,
				$id
			);
			$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is prepared above.
			return;
		}

		$rules = self::get_rules_from_option();
		foreach ( $rules as &$rule ) {
			if ( $id !== sanitize_key( (string) ( $rule['id'] ?? '' ) ) ) {
				continue;
			}
			$rule['hits']     = absint( $rule['hits'] ?? 0 ) + 1;
			$rule['last_hit'] = time();
			break;
		}
		unset( $rule );
		update_option( self::RULES_OPTION, array_values( $rules ), false );
	}

	public static function get_404_log(): array {
		if ( self::tables_ready() ) {
			return self::get_404_log_from_table();
		}
		return self::get_404_log_from_option();
	}

	public static function record_404_hit( string $path, string $referrer, int $max_entries, int $retention_days ): void {
		$path = Redirects::normalize_source_path( $path );
		if ( '' === $path || '/' === $path ) {
			return;
		}

		$max_entries    = max( 20, min( 2000, absint( $max_entries ) ) );
		$retention_days = max( 1, min( 3650, absint( $retention_days ) ) );

		if ( self::tables_ready() ) {
			global $wpdb;
			$table = self::log_table();
			$now   = gmdate( 'Y-m-d H:i:s' );
			$row   = $wpdb->get_row(
				$wpdb->prepare( 'SELECT id, hits FROM %i WHERE path = %s LIMIT 1', $table, $path ),
				ARRAY_A
			);
			if ( is_array( $row ) && ! empty( $row['id'] ) ) {
				$wpdb->update(
					$table,
					array(
						'hits'          => absint( $row['hits'] ?? 0 ) + 1,
						'last_seen'     => $now,
						'last_referrer' => self::redact_referrer( $referrer ),
					),
					array( 'id' => absint( $row['id'] ) ),
					array( '%d', '%s', '%s' ),
					array( '%d' )
				);
			} else {
				$wpdb->insert(
					$table,
					array(
						'path'          => $path,
						'hits'          => 1,
						'first_seen'    => $now,
						'last_seen'     => $now,
						'last_referrer' => self::redact_referrer( $referrer ),
					),
					array( '%s', '%d', '%s', '%s', '%s' )
				);
			}
			self::prune_404_table( $max_entries, $retention_days );
			return;
		}

		$entries = self::get_404_log_from_option();
		$map     = array();
		foreach ( $entries as $entry ) {
			$entry_path = (string) ( $entry['path'] ?? '' );
			if ( '' !== $entry_path ) {
				$map[ $entry_path ] = $entry;
			}
		}
		$now_ts = time();
		if ( isset( $map[ $path ] ) ) {
			$entry = $map[ $path ];
			$entry['hits']      = absint( $entry['hits'] ?? 0 ) + 1;
			$entry['last_seen'] = $now_ts;
			$entry['last_referrer'] = self::redact_referrer( $referrer );
			$map[ $path ]       = $entry;
		} else {
			$map[ $path ] = array(
				'path'          => $path,
				'hits'          => 1,
				'first_seen'    => $now_ts,
				'last_seen'     => $now_ts,
				'last_referrer' => self::redact_referrer( $referrer ),
			);
		}

		$threshold = $now_ts - ( DAY_IN_SECONDS * $retention_days );
		$entries   = array_values(
			array_filter(
				$map,
				static function ( $entry ) use ( $threshold ): bool {
					if ( ! is_array( $entry ) ) {
						return false;
					}
					$last_seen = absint( $entry['last_seen'] ?? 0 );
					return $last_seen >= $threshold;
				}
			)
		);

		usort(
			$entries,
			static function ( array $left, array $right ): int {
				$left_hits  = (int) ( $left['hits'] ?? 0 );
				$right_hits = (int) ( $right['hits'] ?? 0 );
				if ( $left_hits === $right_hits ) {
					return (int) ( $right['last_seen'] ?? 0 ) <=> (int) ( $left['last_seen'] ?? 0 );
				}
				return $right_hits <=> $left_hits;
			}
		);

		update_option( self::LOG_OPTION, array_slice( $entries, 0, $max_entries ), false );
	}

	public static function clear_404_log(): void {
		if ( self::tables_ready() ) {
			global $wpdb;
			$wpdb->query( 'DELETE FROM ' . self::log_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static internal table name.
			return;
		}
		update_option( self::LOG_OPTION, array(), false );
	}

	public static function last_migration_report(): array {
		$state = get_option( self::MIGRATION_OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	private static function maybe_upgrade_schema( bool $force ): void {
		if ( ! self::db_ready() ) {
			return;
		}
		$installed = absint( get_option( self::SCHEMA_OPTION, 0 ) );
		if ( ! $force && $installed >= self::SCHEMA_VERSION ) {
			return;
		}

		if ( ! function_exists( 'dbDelta' ) ) {
			$upgrade_file = ABSPATH . 'wp-admin/includes/upgrade.php';
			if ( file_exists( $upgrade_file ) ) {
				require_once $upgrade_file;
			}
		}
		if ( ! function_exists( 'dbDelta' ) ) {
			return;
		}

		global $wpdb;
		$collate = method_exists( $wpdb, 'get_charset_collate' ) ? $wpdb->get_charset_collate() : '';
		$rules_table = self::rules_table();
		$log_table   = self::log_table();

		$sql_rules = "CREATE TABLE {$rules_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			rule_id varchar(32) NOT NULL,
			source_path varchar(191) NOT NULL,
			destination_url text NOT NULL,
			match_type varchar(20) NOT NULL DEFAULT 'exact',
			status_code smallint(5) unsigned NOT NULL DEFAULT 301,
			enabled tinyint(1) unsigned NOT NULL DEFAULT 1,
			note text NULL,
			hits bigint(20) unsigned NOT NULL DEFAULT 0,
			last_hit datetime NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY rule_id (rule_id),
			UNIQUE KEY source_match (source_path, match_type),
			KEY enabled (enabled)
		) {$collate};";

		$sql_log = "CREATE TABLE {$log_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			path varchar(191) NOT NULL,
			hits bigint(20) unsigned NOT NULL DEFAULT 0,
			first_seen datetime NOT NULL,
			last_seen datetime NOT NULL,
			last_referrer varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY path (path),
			KEY last_seen (last_seen),
			KEY hits (hits)
		) {$collate};";

		dbDelta( $sql_rules );
		dbDelta( $sql_log );

		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
		self::$tables_ready_cache = null;
	}

	private static function maybe_migrate_legacy_options(): void {
		if ( ! self::tables_ready() ) {
			return;
		}

		$migration = self::last_migration_report();
		if ( (int) ( $migration['version'] ?? 0 ) >= self::SCHEMA_VERSION ) {
			return;
		}

		global $wpdb;
		$rules_table = self::rules_table();
		$log_table   = self::log_table();
		$existing_rules = absint( $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $rules_table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Static internal table name.
		$existing_log   = absint( $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $log_table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Static internal table name.

		$legacy_rules = self::get_rules_from_option();
		$legacy_log   = self::get_404_log_from_option();
		$inserted_rules = 0;
		$inserted_log   = 0;

		if ( 0 === $existing_rules && ! empty( $legacy_rules ) ) {
			foreach ( $legacy_rules as $rule ) {
				if ( self::add_rule( $rule ) ) {
					++$inserted_rules;
				}
			}
		}

		if ( 0 === $existing_log && ! empty( $legacy_log ) ) {
			foreach ( $legacy_log as $entry ) {
				$path = Redirects::normalize_source_path( (string) ( $entry['path'] ?? '' ) );
				if ( '' === $path || '/' === $path ) {
					continue;
				}
				$hits       = max( 1, absint( $entry['hits'] ?? 1 ) );
				$first_seen = absint( $entry['first_seen'] ?? 0 );
				$last_seen  = absint( $entry['last_seen'] ?? 0 );
				$first_seen_mysql = $first_seen > 0 ? gmdate( 'Y-m-d H:i:s', $first_seen ) : gmdate( 'Y-m-d H:i:s' );
				$last_seen_mysql  = $last_seen > 0 ? gmdate( 'Y-m-d H:i:s', $last_seen ) : gmdate( 'Y-m-d H:i:s' );

				$inserted = $wpdb->insert(
					$log_table,
					array(
						'path'          => $path,
						'hits'          => $hits,
						'first_seen'    => $first_seen_mysql,
						'last_seen'     => $last_seen_mysql,
						'last_referrer' => self::redact_referrer( (string) ( $entry['last_referrer'] ?? '' ) ),
					),
					array( '%s', '%d', '%s', '%s', '%s' )
				);
				if ( false !== $inserted ) {
					++$inserted_log;
				}
			}
		}

		update_option(
			self::MIGRATION_OPTION,
			array(
				'time'           => time(),
				'version'        => self::SCHEMA_VERSION,
				'source_rules'   => count( $legacy_rules ),
				'source_404'     => count( $legacy_log ),
				'imported_rules' => $inserted_rules,
				'imported_404'   => $inserted_log,
				'existing_rules' => $existing_rules,
				'existing_404'   => $existing_log,
			),
			false
		);
	}

	private static function db_ready(): bool {
		global $wpdb;
		return isset( $wpdb ) && is_object( $wpdb ) && isset( $wpdb->prefix ) && method_exists( $wpdb, 'prepare' ) && method_exists( $wpdb, 'get_var' );
	}

	private static function tables_ready(): bool {
		if ( null !== self::$tables_ready_cache ) {
			return self::$tables_ready_cache;
		}
		if ( ! self::db_ready() ) {
			self::$tables_ready_cache = false;
			return false;
		}
		if ( absint( get_option( self::SCHEMA_OPTION, 0 ) ) < self::SCHEMA_VERSION ) {
			self::$tables_ready_cache = false;
			return false;
		}

		global $wpdb;
		$rules_table = self::rules_table();
		$log_table   = self::log_table();
		$rules_ok = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $rules_table ) );
		$log_ok   = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $log_table ) );
		self::$tables_ready_cache = ( $rules_ok === $rules_table && $log_ok === $log_table );
		return self::$tables_ready_cache;
	}

	private static function rules_table(): string {
		global $wpdb;
		return (string) $wpdb->prefix . 'ogs_seo_redirects';
	}

	private static function log_table(): string {
		global $wpdb;
		return (string) $wpdb->prefix . 'ogs_seo_404_events';
	}

	private static function get_rules_from_table(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			'SELECT rule_id, source_path, destination_url, match_type, status_code, enabled, note, hits, last_hit, created_at, updated_at FROM ' . self::rules_table() . ' ORDER BY source_path ASC', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static internal table name.
			ARRAY_A
		);
		$rules = array();
		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$rules[] = array(
				'id'              => sanitize_key( (string) ( $row['rule_id'] ?? '' ) ),
				'source_path'     => Redirects::normalize_source_path( (string) ( $row['source_path'] ?? '' ) ),
				'destination_url' => Redirects::normalize_destination_url( (string) ( $row['destination_url'] ?? '' ) ),
				'match_type'      => in_array( (string) ( $row['match_type'] ?? 'exact' ), array( 'exact', 'prefix' ), true ) ? (string) $row['match_type'] : 'exact',
				'status_code'     => in_array( absint( $row['status_code'] ?? 301 ), array( 301, 302 ), true ) ? absint( $row['status_code'] ) : 301,
				'enabled'         => ! empty( $row['enabled'] ) ? 1 : 0,
				'note'            => sanitize_text_field( (string) ( $row['note'] ?? '' ) ),
				'hits'            => absint( $row['hits'] ?? 0 ),
				'last_hit'        => self::datetime_to_timestamp( (string) ( $row['last_hit'] ?? '' ) ),
				'created_at'      => self::datetime_to_timestamp( (string) ( $row['created_at'] ?? '' ) ),
				'updated_at'      => self::datetime_to_timestamp( (string) ( $row['updated_at'] ?? '' ) ),
			);
		}
		return array_values( array_filter( $rules, static fn( $rule ) => ! empty( $rule['id'] ) && '' !== (string) ( $rule['source_path'] ?? '' ) ) );
	}

	private static function get_404_log_from_table(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			'SELECT path, hits, first_seen, last_seen, last_referrer FROM ' . self::log_table() . ' ORDER BY hits DESC, last_seen DESC', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static internal table name.
			ARRAY_A
		);
		$entries = array();
		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$path = Redirects::normalize_source_path( (string) ( $row['path'] ?? '' ) );
			if ( '' === $path || '/' === $path ) {
				continue;
			}
			$entries[] = array(
				'path'          => $path,
				'hits'          => max( 1, absint( $row['hits'] ?? 1 ) ),
				'first_seen'    => self::datetime_to_timestamp( (string) ( $row['first_seen'] ?? '' ) ),
				'last_seen'     => self::datetime_to_timestamp( (string) ( $row['last_seen'] ?? '' ) ),
				'last_referrer' => esc_url_raw( (string) ( $row['last_referrer'] ?? '' ) ),
			);
		}
		return $entries;
	}

	private static function prune_404_table( int $max_entries, int $retention_days ): void {
		global $wpdb;
		$table = self::log_table();
		$threshold = gmdate( 'Y-m-d H:i:s', time() - ( DAY_IN_SECONDS * $retention_days ) );
		$delete_sql = $wpdb->prepare( 'DELETE FROM %i WHERE last_seen < %s', $table, $threshold );
		$wpdb->query( $delete_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is prepared above.

		$total = absint( $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Static internal table name.
		if ( $total <= $max_entries ) {
			return;
		}

		$offset = max( 0, $max_entries );
		$limit  = max( 1, $total - $max_entries );
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i ORDER BY hits DESC, last_seen DESC LIMIT %d, %d',
				$table,
				$offset,
				$limit
			)
		);
		$ids = array_filter( array_map( 'absint', (array) $rows ) );
		if ( empty( $ids ) ) {
			return;
		}
		$wpdb->query( 'DELETE FROM ' . $table . ' WHERE id IN (' . implode( ',', $ids ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal integer ids only.
	}

	private static function get_rules_from_option(): array {
		$raw = get_option( self::RULES_OPTION, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$rules = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$rule = self::sanitize_runtime_rule( $row );
			if ( ! empty( $rule ) ) {
				$rules[] = $rule;
			}
		}
		return array_values( $rules );
	}

	private static function get_404_log_from_option(): array {
		$raw = get_option( self::LOG_OPTION, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$entries = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$path = Redirects::normalize_source_path( (string) ( $row['path'] ?? '' ) );
			if ( '' === $path || '/' === $path ) {
				continue;
			}
			$entries[] = array(
				'path'          => $path,
				'hits'          => max( 1, absint( $row['hits'] ?? 1 ) ),
				'first_seen'    => absint( $row['first_seen'] ?? 0 ),
				'last_seen'     => absint( $row['last_seen'] ?? 0 ),
				'last_referrer' => esc_url_raw( (string) ( $row['last_referrer'] ?? '' ) ),
			);
		}
		usort(
			$entries,
			static function ( array $left, array $right ): int {
				$left_hits  = (int) ( $left['hits'] ?? 0 );
				$right_hits = (int) ( $right['hits'] ?? 0 );
				if ( $left_hits === $right_hits ) {
					return (int) ( $right['last_seen'] ?? 0 ) <=> (int) ( $left['last_seen'] ?? 0 );
				}
				return $right_hits <=> $left_hits;
			}
		);
		return $entries;
	}

	private static function sanitize_runtime_rule( array $row ): array {
		$source = Redirects::normalize_source_path( (string) ( $row['source_path'] ?? $row['source'] ?? $row['from'] ?? '' ) );
		if ( '' === $source || '/' === $source ) {
			return array();
		}
		$destination = Redirects::normalize_destination_url( (string) ( $row['destination_url'] ?? $row['destination'] ?? $row['to'] ?? '' ) );
		if ( '' === $destination ) {
			return array();
		}
		$match_type = sanitize_key( (string) ( $row['match_type'] ?? $row['type'] ?? 'exact' ) );
		$match_type = in_array( $match_type, array( 'exact', 'prefix' ), true ) ? $match_type : 'exact';
		$status     = absint( $row['status_code'] ?? $row['status'] ?? 301 );
		$status     = in_array( $status, array( 301, 302 ), true ) ? $status : 301;
		$id         = sanitize_key( (string) ( $row['id'] ?? '' ) );
		if ( '' === $id ) {
			$id = sanitize_key( md5( $source . '|' . $destination . '|' . $match_type ) );
		}

		$destination_path = Redirects::normalize_source_path( (string) wp_parse_url( $destination, PHP_URL_PATH ) );
		if ( '' !== $destination_path && $destination_path === $source ) {
			return array();
		}

		return array(
			'id'              => $id,
			'source_path'     => $source,
			'destination_url' => $destination,
			'match_type'      => $match_type,
			'status_code'     => $status,
			'enabled'         => ! empty( $row['enabled'] ) ? 1 : 0,
			'note'            => sanitize_text_field( (string) ( $row['note'] ?? '' ) ),
			'hits'            => absint( $row['hits'] ?? 0 ),
			'last_hit'        => absint( $row['last_hit'] ?? 0 ),
			'created_at'      => absint( $row['created_at'] ?? 0 ),
			'updated_at'      => absint( $row['updated_at'] ?? 0 ),
		);
	}

	private static function redact_referrer( string $raw_referrer ): string {
		$raw_referrer = trim( esc_url_raw( $raw_referrer ) );
		if ( '' === $raw_referrer ) {
			return '';
		}
		$parts = wp_parse_url( $raw_referrer );
		if ( ! is_array( $parts ) ) {
			return '';
		}
		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
		$host   = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
		$path   = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		if ( '' === $scheme || '' === $host ) {
			return '';
		}
		return $scheme . '://' . $host . $path;
	}

	private static function datetime_to_timestamp( string $value ): int {
		$value = trim( $value );
		if ( '' === $value || '0000-00-00 00:00:00' === $value ) {
			return 0;
		}
		$timestamp = strtotime( $value . ' UTC' );
		if ( false === $timestamp ) {
			$timestamp = strtotime( $value );
		}
		return false !== $timestamp ? $timestamp : 0;
	}
}
