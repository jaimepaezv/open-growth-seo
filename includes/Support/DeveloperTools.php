<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\Support;

use OpenGrowthSolutions\OpenGrowthSEO\Audit\AuditManager;
use OpenGrowthSolutions\OpenGrowthSEO\Compatibility\Importer;
use OpenGrowthSolutions\OpenGrowthSEO\Core\Defaults;
use OpenGrowthSolutions\OpenGrowthSEO\Installation\Manager as InstallationManager;
use OpenGrowthSolutions\OpenGrowthSEO\Integrations\Manager as IntegrationsManager;
use OpenGrowthSolutions\OpenGrowthSEO\Jobs\Queue as JobsQueue;

defined( 'ABSPATH' ) || exit;

class DeveloperTools {
	private const LOG_OPTION             = 'ogs_seo_debug_logs';
	private const MAX_LOG_ENTRIES        = 200;
	private const MAX_IMPORT_BYTES       = 1048576;
	private const DIAGNOSTICS_CACHE_KEY  = 'ogs_seo_dev_tools_diagnostics_cache';
	private const DIAGNOSTICS_CACHE_TTL  = 30;
	private const AUDIT_STALE_SECONDS    = 604800;
	private const MAX_RECURSION_DEPTH    = 4;
	private const MAX_ARRAY_ITEMS        = 80;
	private const MAX_STRING_LENGTH      = 240;

	public static function diagnostics(): array {
		$settings      = Settings::get_all();
		$post_types    = get_post_types( array( 'public' => true ), 'names' );
		$post_count    = 0;
		$jobs          = ( new JobsQueue() )->inspect();
		$installation  = ( new InstallationManager() )->summary();
		$audit_last_run = (int) get_option( 'ogs_seo_audit_last_run', 0 );

		if ( function_exists( 'wp_count_posts' ) && is_array( $post_types ) && ! empty( $post_types ) ) {
			foreach ( $post_types as $post_type ) {
				$counts = wp_count_posts( (string) $post_type );
				if ( is_object( $counts ) && isset( $counts->publish ) ) {
					$post_count += (int) $counts->publish;
				}
			}
		}

		$compatibility = array();
		if ( class_exists( Importer::class ) ) {
			$compatibility = ( new Importer() )->detect();
		}

		$integrations = array();
		if ( class_exists( IntegrationsManager::class ) ) {
			$integrations = IntegrationsManager::get_status_payload();
		}

		$payload = array(
			'timestamp'                => time(),
			'plugin_version'           => defined( 'OGS_SEO_VERSION' ) ? (string) OGS_SEO_VERSION : 'unknown',
			'wp_version'               => (string) get_bloginfo( 'version' ),
			'php_version'              => PHP_VERSION,
			'multisite'                => is_multisite(),
			'diagnostic_mode'          => (bool) (int) ( $settings['diagnostic_mode'] ?? 0 ),
			'debug_logs_enabled'       => (bool) (int) ( $settings['debug_logs_enabled'] ?? 0 ),
			'audit_last_run'           => $audit_last_run,
			'sitemap_cache_version'    => (string) get_option( 'ogs_seo_sitemap_cache_version', '1' ),
			'indexnow_queue_pending'   => count( (array) get_option( 'ogs_seo_indexnow_queue', array() ) ),
			'integration_logs_count'   => count( (array) get_option( 'ogs_seo_integration_logs', array() ) ),
			'debug_logs_count'         => count( self::get_logs( self::MAX_LOG_ENTRIES ) ),
			'public_post_types'        => array_values( array_map( 'sanitize_key', (array) $post_types ) ),
			'published_posts'          => $post_count,
			'jobs'                     => $jobs,
			'installation_status'      => (string) ( $installation['status'] ?? '' ),
			'installation_primary'     => (string) ( $installation['primary'] ?? '' ),
			'installation_confidence'  => (int) ( $installation['confidence'] ?? 0 ),
			'installation_pending'     => (int) ( $installation['pending'] ?? 0 ),
			'installation_last_run'    => (int) ( $installation['last_run'] ?? 0 ),
			'installation_rebuilds'    => (int) ( $installation['rebuilds'] ?? 0 ),
			'installation_repair_runs' => (int) ( $installation['repair_runs'] ?? 0 ),
			'installation_last_rebuild' => (int) ( $installation['last_rebuild'] ?? 0 ),
			'installation_last_repair' => (int) ( $installation['last_repair'] ?? 0 ),
		);

		$payload['support']                 = self::build_support_report( $payload, $settings, $jobs, $installation, $compatibility, $integrations );
		$payload['support_status']          = (string) ( $payload['support']['status'] ?? 'good' );
		$payload['support_attention_count'] = (int) ( $payload['support']['attention_count'] ?? 0 );

		/**
		 * Filter diagnostics payload before exposing in admin/REST/CLI.
		 *
		 * @param array $payload  Diagnostics payload.
		 * @param array $settings Current plugin settings.
		 */
		$payload = apply_filters( 'ogs_seo_dev_tools_diagnostics', $payload, $settings );

		return self::sanitize_payload_for_support( is_array( $payload ) ? $payload : array() );
	}

	public static function diagnostics_cached( bool $refresh = false ): array {
		if ( ! $refresh ) {
			$cached = get_transient( self::DIAGNOSTICS_CACHE_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$payload = self::diagnostics();
		set_transient( self::DIAGNOSTICS_CACHE_KEY, $payload, self::DIAGNOSTICS_CACHE_TTL );
		return $payload;
	}

	public static function clear_diagnostics_cache(): void {
		delete_option( '_transient_' . self::DIAGNOSTICS_CACHE_KEY );
		delete_option( '_transient_timeout_' . self::DIAGNOSTICS_CACHE_KEY );
	}

	public static function export_payload(): array {
		$payload = array(
			'schema_version' => 1,
			'generated_at'   => time(),
			'plugin_version' => defined( 'OGS_SEO_VERSION' ) ? (string) OGS_SEO_VERSION : 'unknown',
			'site_url'       => home_url( '/' ),
			'settings'       => Settings::get_all(),
		);

		/**
		 * Filter export payload before serialization.
		 *
		 * @param array $payload Export payload.
		 */
		$payload = apply_filters( 'ogs_seo_dev_tools_export_payload', $payload );
		return is_array( $payload ) ? $payload : array();
	}

	public static function import_payload( array $payload, bool $merge = true ): array {
		$incoming = isset( $payload['settings'] ) && is_array( $payload['settings'] ) ? $payload['settings'] : null;
		if ( null === $incoming ) {
			return array(
				'ok'      => false,
				'message' => __( 'Import payload is invalid: missing settings object.', 'open-growth-seo' ),
			);
		}

		/**
		 * Filter incoming import payload before sanitize/update.
		 *
		 * @param array $payload Full incoming payload.
		 */
		$payload   = apply_filters( 'ogs_seo_dev_tools_import_payload', $payload );
		$incoming  = isset( $payload['settings'] ) && is_array( $payload['settings'] ) ? $payload['settings'] : array();
		$current   = Settings::get_all();
		$base      = $merge ? $current : Defaults::settings();
		$clean     = Settings::sanitize( array_replace( $base, $incoming ) );
		$changed   = array();

		foreach ( $clean as $key => $value ) {
			if ( ! array_key_exists( $key, $current ) || $current[ $key ] !== $value ) {
				$changed[] = (string) $key;
			}
		}

		Settings::update( $clean );
		self::clear_diagnostics_cache();
		self::log(
			'info',
			'Developer tools import executed.',
			array(
				'changed_count' => (string) count( $changed ),
				'merge'         => $merge ? '1' : '0',
			)
		);

		/**
		 * Fires after settings import through developer tools.
		 *
		 * @param array $changed Changed setting keys.
		 * @param array $payload Original import payload.
		 */
		do_action( 'ogs_seo_dev_tools_after_import', $changed, $payload );

		return array(
			'ok'           => true,
			'changed_keys' => $changed,
			'changed'      => count( $changed ),
		);
	}

	public static function max_import_bytes(): int {
		return self::MAX_IMPORT_BYTES;
	}

	public static function reset_settings( bool $preserve_uninstall_choice = true ): array {
		$current  = Settings::get_all();
		$defaults = Defaults::settings();

		if ( $preserve_uninstall_choice ) {
			$defaults['keep_data_on_uninstall'] = (int) ( $current['keep_data_on_uninstall'] ?? 1 );
		}

		Settings::update( $defaults );
		( new InstallationManager() )->rerun( 'settings_reset', true );
		self::clear_diagnostics_cache();
		self::log(
			'warning',
			'Developer tools reset executed.',
			array(
				'preserve_uninstall_choice' => $preserve_uninstall_choice ? '1' : '0',
			)
		);

		/**
		 * Fires after settings reset through developer tools.
		 *
		 * @param array $new_settings New settings values.
		 * @param array $old_settings Previous settings values.
		 */
		do_action( 'ogs_seo_dev_tools_after_reset', $defaults, $current );

		return array(
			'ok'             => true,
			'reset_keys'     => count( $defaults ),
			'preserved_keep' => $preserve_uninstall_choice,
		);
	}

	public static function log( string $level, string $message, array $context = array() ): void {
		$settings = Settings::get_all();
		if ( empty( $settings['diagnostic_mode'] ) && empty( $settings['debug_logs_enabled'] ) ) {
			return;
		}

		$level   = sanitize_key( $level );
		$message = sanitize_text_field( $message );
		if ( '' === $level || '' === $message ) {
			return;
		}

		$entry = array(
			'time'    => time(),
			'level'   => $level,
			'message' => $message,
			'context' => self::redact_context( $context ),
		);
		$logs = get_option( self::LOG_OPTION, array() );
		if ( ! is_array( $logs ) ) {
			$logs = array();
		}
		$logs[] = $entry;
		if ( count( $logs ) > self::MAX_LOG_ENTRIES ) {
			$logs = array_slice( $logs, -self::MAX_LOG_ENTRIES );
		}
		update_option( self::LOG_OPTION, $logs, false );
	}

	public static function get_logs( int $limit = 50 ): array {
		$logs = get_option( self::LOG_OPTION, array() );
		if ( ! is_array( $logs ) ) {
			return array();
		}
		$limit = max( 1, min( self::MAX_LOG_ENTRIES, $limit ) );
		return array_slice( array_reverse( $logs ), 0, $limit );
	}

	public static function clear_logs(): void {
		delete_option( self::LOG_OPTION );
		self::clear_diagnostics_cache();
	}

	private static function build_support_report( array $payload, array $settings, array $jobs, array $installation, array $compatibility, array $integrations ): array {
		$health_checks = self::build_health_checks( $payload, $settings, $jobs, $installation, $compatibility, $integrations );
		$summary       = self::build_support_summary( $health_checks, $jobs, $settings );

		return array(
			'status'          => $summary['status'],
			'attention_count' => $summary['attention_count'],
			'healthy_count'   => $summary['healthy_count'],
			'module_statuses' => self::build_module_statuses( $payload, $jobs, $installation, $compatibility, $integrations ),
			'health_checks'   => $health_checks,
			'next_steps'      => self::build_next_steps( $health_checks ),
			'privacy'         => self::build_privacy_summary( $settings ),
			'resources'       => self::build_support_resources(),
			'log_summary'     => self::build_log_summary(),
		);
	}

	private static function build_health_checks( array $payload, array $settings, array $jobs, array $installation, array $compatibility, array $integrations ): array {
		$checks = array();

		$installation_status = (string) ( $installation['status'] ?? '' );
		$installation_pending = (int) ( $installation['pending'] ?? 0 );
		$checks[] = array(
			'key'     => 'installation',
			'label'   => __( 'Installation state', 'open-growth-seo' ),
			'status'  => ( 'ready' === $installation_status && 0 === $installation_pending ) ? 'good' : ( $installation_pending > 0 ? 'warn' : 'critical' ),
			'summary' => 'ready' === $installation_status
				? ( 0 === $installation_pending ? __( 'Installation state is complete and ready for normal operation.', 'open-growth-seo' ) : sprintf( __( 'Installation is usable, but %d setup follow-up items still need review.', 'open-growth-seo' ), $installation_pending ) )
				: __( 'Installation state is incomplete or needs repair.', 'open-growth-seo' ),
			'action'  => ( 'ready' === $installation_status && 0 === $installation_pending )
				? __( 'No action required.', 'open-growth-seo' )
				: __( 'Open Setup or rebuild installation state from Tools before troubleshooting deeper issues.', 'open-growth-seo' ),
		);

		$audit_last_run = (int) ( $payload['audit_last_run'] ?? 0 );
		$checks[] = array(
			'key'     => 'audit_freshness',
			'label'   => __( 'Audit freshness', 'open-growth-seo' ),
			'status'  => 0 === $audit_last_run ? 'warn' : ( ( time() - $audit_last_run ) > self::AUDIT_STALE_SECONDS ? 'warn' : 'good' ),
			'summary' => 0 === $audit_last_run
				? __( 'No audit run has been recorded yet.', 'open-growth-seo' )
				: ( ( time() - $audit_last_run ) > self::AUDIT_STALE_SECONDS
					? __( 'Audit findings are stale and may not reflect current output.', 'open-growth-seo' )
					: __( 'Audit findings are recent enough for support triage.', 'open-growth-seo' ) ),
			'action'  => 0 === $audit_last_run || ( ( time() - $audit_last_run ) > self::AUDIT_STALE_SECONDS )
				? __( 'Run a fresh audit before acting on older diagnostics.', 'open-growth-seo' )
				: __( 'Use current findings to prioritize the next fixes.', 'open-growth-seo' ),
		);

		$runner = isset( $jobs['runner'] ) && is_array( $jobs['runner'] ) ? $jobs['runner'] : array();
		$stale_lock = ! empty( $runner['stale_lock'] );
		$consecutive_failures = (int) ( $runner['consecutive_failures'] ?? 0 );
		$due_now = (int) ( $jobs['due_now'] ?? 0 );
		$checks[] = array(
			'key'     => 'jobs_queue',
			'label'   => __( 'Background jobs', 'open-growth-seo' ),
			'status'  => $stale_lock ? 'critical' : ( $consecutive_failures > 0 || $due_now > 0 ? 'warn' : 'good' ),
			'summary' => $stale_lock
				? __( 'A stale queue lock was detected. Deferred processing may be blocked.', 'open-growth-seo' )
				: ( $consecutive_failures > 0
					? sprintf( __( 'Background processing has %d consecutive failures.', 'open-growth-seo' ), $consecutive_failures )
					: ( $due_now > 0
						? sprintf( __( '%d queued job items are due now and still pending.', 'open-growth-seo' ), $due_now )
						: __( 'Background jobs look healthy.', 'open-growth-seo' ) ) ),
			'action'  => $stale_lock
				? __( 'Release the queue lock or inspect the jobs status before retrying queued tasks.', 'open-growth-seo' )
				: ( $consecutive_failures > 0 || $due_now > 0
					? __( 'Review Jobs or Integrations status and process the queue again after resolving the cause.', 'open-growth-seo' )
					: __( 'No action required.', 'open-growth-seo' ) ),
		);

		$active_providers = isset( $compatibility['active'] ) && is_array( $compatibility['active'] ) ? $compatibility['active'] : array();
		$safe_mode = ! empty( $compatibility['safe_mode_enabled'] );
		$checks[] = array(
			'key'     => 'compatibility',
			'label'   => __( 'SEO coexistence', 'open-growth-seo' ),
			'status'  => empty( $active_providers ) ? 'good' : ( $safe_mode ? 'warn' : 'critical' ),
			'summary' => empty( $active_providers )
				? __( 'No supported SEO coexistence risk is currently detected.', 'open-growth-seo' )
				: ( $safe_mode
					? __( 'Another supported SEO plugin is active, but safe mode is reducing duplicate output risk.', 'open-growth-seo' )
					: __( 'Another supported SEO plugin is active and safe mode is disabled.', 'open-growth-seo' ) ),
			'action'  => empty( $active_providers )
				? __( 'No action required.', 'open-growth-seo' )
				: __( 'Review Compatibility tools and enable safe mode before troubleshooting duplicate output.', 'open-growth-seo' ),
		);

		$integration_summary = isset( $integrations['summary'] ) && is_array( $integrations['summary'] ) ? $integrations['summary'] : array();
		$integration_attention = (int) ( $integration_summary['attention'] ?? 0 );
		$checks[] = array(
			'key'     => 'integrations',
			'label'   => __( 'Integrations', 'open-growth-seo' ),
			'status'  => $integration_attention > 0 ? 'warn' : 'good',
			'summary' => $integration_attention > 0
				? sprintf( __( '%d integration entries need attention or revalidation.', 'open-growth-seo' ), $integration_attention )
				: __( 'Integration status is clear or safely inactive.', 'open-growth-seo' ),
			'action'  => $integration_attention > 0
				? __( 'Open Integrations to re-test providers and review missing credentials or failing connections.', 'open-growth-seo' )
				: __( 'No action required.', 'open-growth-seo' ),
		);

		$diag_enabled = ! empty( $settings['diagnostic_mode'] ) || ! empty( $settings['debug_logs_enabled'] );
		$checks[] = array(
			'key'     => 'support_capture',
			'label'   => __( 'Diagnostic capture', 'open-growth-seo' ),
			'status'  => $diag_enabled ? 'info' : 'good',
			'summary' => $diag_enabled
				? __( 'Diagnostic capture is enabled to collect extra troubleshooting data.', 'open-growth-seo' )
				: __( 'Diagnostic capture is off, which is appropriate for steady-state operation.', 'open-growth-seo' ),
			'action'  => $diag_enabled
				? __( 'Disable extra logging after troubleshooting to keep support data minimal.', 'open-growth-seo' )
				: __( 'Enable it temporarily only when deeper troubleshooting is needed.', 'open-growth-seo' ),
		);

		return array_values( $checks );
	}

	private static function build_support_summary( array $checks, array $jobs, array $settings ): array {
		$worst = 'good';
		$attention = 0;
		$healthy = 0;

		foreach ( $checks as $check ) {
			$status = isset( $check['status'] ) ? (string) $check['status'] : 'good';
			if ( in_array( $status, array( 'critical', 'warn' ), true ) ) {
				++$attention;
			} else {
				++$healthy;
			}
			$worst = self::merge_status( $worst, $status );
		}

		if ( ! empty( $settings['debug_logs_enabled'] ) && ! empty( $jobs['runner']['stale_lock'] ) ) {
			$worst = self::merge_status( $worst, 'critical' );
		}

		return array(
			'status'          => $worst,
			'attention_count' => $attention,
			'healthy_count'   => $healthy,
		);
	}

	private static function build_module_statuses( array $payload, array $jobs, array $installation, array $compatibility, array $integrations ): array {
		$statuses = array();

		$statuses[] = array(
			'key'     => 'installation',
			'label'   => __( 'Installation', 'open-growth-seo' ),
			'status'  => ( 'ready' === (string) ( $installation['status'] ?? '' ) ) ? 'good' : 'warn',
			'summary' => (string) ( $installation['status'] ?? __( 'Unknown', 'open-growth-seo' ) ),
		);
		$statuses[] = array(
			'key'     => 'audit',
			'label'   => __( 'Audit', 'open-growth-seo' ),
			'status'  => 0 === (int) ( $payload['audit_last_run'] ?? 0 ) ? 'warn' : 'good',
			'summary' => 0 === (int) ( $payload['audit_last_run'] ?? 0 ) ? __( 'No run recorded', 'open-growth-seo' ) : __( 'Diagnostics available', 'open-growth-seo' ),
		);
		$statuses[] = array(
			'key'     => 'jobs',
			'label'   => __( 'Jobs', 'open-growth-seo' ),
			'status'  => ! empty( $jobs['runner']['stale_lock'] ) ? 'critical' : ( (int) ( $jobs['runner']['consecutive_failures'] ?? 0 ) > 0 ? 'warn' : 'good' ),
			'summary' => ! empty( $jobs['runner']['stale_lock'] ) ? __( 'Stale lock detected', 'open-growth-seo' ) : __( 'Queue readable', 'open-growth-seo' ),
		);
		$statuses[] = array(
			'key'     => 'compatibility',
			'label'   => __( 'Compatibility', 'open-growth-seo' ),
			'status'  => empty( $compatibility['active'] ) ? 'good' : ( ! empty( $compatibility['safe_mode_enabled'] ) ? 'warn' : 'critical' ),
			'summary' => empty( $compatibility['active'] ) ? __( 'No supported conflicts', 'open-growth-seo' ) : __( 'Coexistence review needed', 'open-growth-seo' ),
		);
		$statuses[] = array(
			'key'     => 'integrations',
			'label'   => __( 'Integrations', 'open-growth-seo' ),
			'status'  => ( (int) ( $integrations['summary']['attention'] ?? 0 ) > 0 ) ? 'warn' : 'good',
			'summary' => ( (int) ( $integrations['summary']['attention'] ?? 0 ) > 0 ) ? __( 'Attention items present', 'open-growth-seo' ) : __( 'Healthy or inactive', 'open-growth-seo' ),
		);

		return $statuses;
	}

	private static function build_next_steps( array $checks ): array {
		$steps = array();
		foreach ( $checks as $check ) {
			$status = isset( $check['status'] ) ? (string) $check['status'] : 'good';
			if ( ! in_array( $status, array( 'critical', 'warn', 'info' ), true ) ) {
				continue;
			}
			$steps[] = array(
				'label'  => (string) ( $check['label'] ?? '' ),
				'status' => $status,
				'action' => (string) ( $check['action'] ?? '' ),
			);
		}

		return array_slice( $steps, 0, 6 );
	}

	private static function build_privacy_summary( array $settings ): array {
		return array(
			'credentials_in_exports' => false,
			'logs_redacted'          => true,
			'logs_enabled'           => ! empty( $settings['debug_logs_enabled'] ),
			'diagnostic_mode'        => ! empty( $settings['diagnostic_mode'] ),
			'max_log_entries'        => self::MAX_LOG_ENTRIES,
			'max_import_kb'          => (int) ( self::MAX_IMPORT_BYTES / 1024 ),
			'note'                   => __( 'Exports exclude stored credentials. Debug logs are bounded and redact sensitive-looking fields before storage.', 'open-growth-seo' ),
		);
	}

	private static function build_support_resources(): array {
		$resources = array(
			array(
				'label' => __( 'REST diagnostics route', 'open-growth-seo' ),
				'value' => function_exists( 'rest_url' ) ? (string) rest_url( 'ogs-seo/v1/dev-tools/diagnostics' ) : '/wp-json/ogs-seo/v1/dev-tools/diagnostics',
			),
			array(
				'label' => __( 'REST installation telemetry', 'open-growth-seo' ),
				'value' => function_exists( 'rest_url' ) ? (string) rest_url( 'ogs-seo/v1/installation/telemetry' ) : '/wp-json/ogs-seo/v1/installation/telemetry',
			),
			array(
				'label' => __( 'WP-CLI command group', 'open-growth-seo' ),
				'value' => 'wp ogs-seo',
			),
			array(
				'label' => __( 'Recommended support export', 'open-growth-seo' ),
				'value' => __( 'Export Settings JSON and include the diagnostics snapshot when escalating an issue.', 'open-growth-seo' ),
			),
		);

		return $resources;
	}

	private static function build_log_summary(): array {
		$raw_logs = get_option( self::LOG_OPTION, array() );
		if ( ! is_array( $raw_logs ) ) {
			$raw_logs = array();
		}

		$levels = array();
		foreach ( $raw_logs as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$level = sanitize_key( (string) ( $entry['level'] ?? 'info' ) );
			if ( '' === $level ) {
				$level = 'info';
			}
			$levels[ $level ] = isset( $levels[ $level ] ) ? ( (int) $levels[ $level ] + 1 ) : 1;
		}

		$latest = ! empty( $raw_logs ) ? end( $raw_logs ) : array();
		if ( false === $latest ) {
			$latest = array();
		}

		return array(
			'total'         => count( $raw_logs ),
			'levels'        => $levels,
			'latest_time'   => (int) ( is_array( $latest ) ? ( $latest['time'] ?? 0 ) : 0 ),
			'latest_level'  => (string) ( is_array( $latest ) ? ( $latest['level'] ?? '' ) : '' ),
			'collecting'    => ! empty( Settings::get_all()['diagnostic_mode'] ) || ! empty( Settings::get_all()['debug_logs_enabled'] ),
			'bounded_to'    => self::MAX_LOG_ENTRIES,
		);
	}

	private static function merge_status( string $current, string $candidate ): string {
		$order = array(
			'good'     => 0,
			'info'     => 1,
			'warn'     => 2,
			'critical' => 3,
		);

		$current_score   = $order[ $current ] ?? 0;
		$candidate_score = $order[ $candidate ] ?? 0;
		return $candidate_score > $current_score ? $candidate : $current;
	}

	private static function redact_context( array $context ): array {
		$clean = array();
		foreach ( $context as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			$clean[ $key ] = self::sanitize_payload_value( $key, $value, 0 );
		}
		return $clean;
	}

	private static function sanitize_payload_for_support( array $payload ): array {
		$clean = array();
		foreach ( $payload as $key => $value ) {
			$key = is_string( $key ) ? sanitize_key( $key ) : (int) $key;
			$clean[ $key ] = self::sanitize_payload_value( is_string( $key ) ? $key : '', $value, 0 );
		}
		return $clean;
	}

	private static function sanitize_payload_value( string $key, $value, int $depth ) {
		if ( $depth > self::MAX_RECURSION_DEPTH ) {
			return '[truncated]';
		}

		if ( preg_match( '/(token|secret|password|key|authorization|cookie|nonce|email|login|user_pass)/i', $key ) ) {
			return '[redacted]';
		}

		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
			return $value;
		}

		if ( is_string( $value ) ) {
			$clean = sanitize_text_field( $value );
			if ( strlen( $clean ) > self::MAX_STRING_LENGTH ) {
				$clean = substr( $clean, 0, self::MAX_STRING_LENGTH ) . '...';
			}
			return $clean;
		}

		if ( is_array( $value ) ) {
			$clean = array();
			$count = 0;
			foreach ( $value as $child_key => $child_value ) {
				if ( $count >= self::MAX_ARRAY_ITEMS ) {
					$clean['truncated'] = true;
					break;
				}
				$normalized_key = is_string( $child_key ) ? sanitize_key( $child_key ) : $child_key;
				$clean[ $normalized_key ] = self::sanitize_payload_value( is_string( $normalized_key ) ? $normalized_key : $key, $child_value, $depth + 1 );
				++$count;
			}
			return $clean;
		}

		if ( is_object( $value ) ) {
			return '[object]';
		}

		return '[unsupported]';
	}
}
