<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\CLI;

use OpenGrowthSolutions\OpenGrowthSEO\AEO\Analyzer;
use OpenGrowthSolutions\OpenGrowthSEO\Audit\AuditManager;
use OpenGrowthSolutions\OpenGrowthSEO\Compatibility\Importer;
use OpenGrowthSolutions\OpenGrowthSEO\GEO\BotControls;
use OpenGrowthSolutions\OpenGrowthSEO\Integrations\IndexNow;
use OpenGrowthSolutions\OpenGrowthSEO\Integrations\Manager as IntegrationsManager;
use OpenGrowthSolutions\OpenGrowthSEO\SEO\Hreflang;
use OpenGrowthSolutions\OpenGrowthSEO\SEO\Sitemaps;
use OpenGrowthSolutions\OpenGrowthSEO\Schema\SchemaManager;
use OpenGrowthSolutions\OpenGrowthSEO\Support\DeveloperTools;
use OpenGrowthSolutions\OpenGrowthSEO\Support\Settings;

defined( 'ABSPATH' ) || exit;

class Commands {
	public function register(): void {
		$this->add_command( 'ogs-seo audit run', 'audit_run', 'Run a full SEO audit.', array(), "## EXAMPLES\n\n    wp ogs-seo audit run\n" );
		$this->add_command( 'ogs-seo audit status', 'audit_status', 'Show audit status and issue counts.', $this->format_synopsis(), "## EXAMPLES\n\n    wp ogs-seo audit status\n    wp ogs-seo audit status --format=json\n" );
		$this->add_command( 'ogs-seo audit run-incremental', 'audit_run_incremental', 'Run the next incremental audit batch.' );
		$this->add_command( 'ogs-seo audit ignore', 'audit_ignore', 'Ignore one audit issue with a reason.', array(
			array( 'type' => 'assoc', 'name' => 'issue_id', 'optional' => false, 'description' => 'Audit issue identifier.' ),
			array( 'type' => 'assoc', 'name' => 'reason', 'optional' => false, 'description' => 'Why the issue is being ignored.' ),
		) );
		$this->add_command( 'ogs-seo audit unignore', 'audit_unignore', 'Restore one ignored audit issue.', array(
			array( 'type' => 'assoc', 'name' => 'issue_id', 'optional' => false, 'description' => 'Audit issue identifier.' ),
		) );
		$this->add_command( 'ogs-seo sitemap flush', 'sitemap_flush', 'Invalidate sitemap cache.' );
		$this->add_command( 'ogs-seo sitemap status', 'sitemap_status', 'Show sitemap runtime status.', $this->format_synopsis() );
		$this->add_command( 'ogs-seo hreflang status', 'hreflang_status', 'Show hreflang provider and errors.', $this->format_synopsis() );
		$this->add_command( 'ogs-seo schema status', 'schema_status', 'Inspect active schema nodes and validation state.', $this->format_synopsis() );
		$this->add_command( 'ogs-seo aeo analyze', 'aeo_analyze', 'Analyze one post for AEO readiness.', array_merge( $this->post_id_synopsis(), $this->format_synopsis() ) );
		$this->add_command( 'ogs-seo geo analyze', 'geo_analyze', 'Analyze one post for GEO readiness.', array_merge( $this->post_id_synopsis(), $this->format_synopsis() ) );
		$this->add_command( 'ogs-seo integrations status', 'integrations_status', 'Show integration health across providers.', $this->format_synopsis() );
		$this->add_command( 'ogs-seo integrations test', 'integrations_test', 'Run connectivity test for one integration.', array(
			array( 'type' => 'assoc', 'name' => 'integration', 'optional' => false, 'description' => 'Integration slug.' ),
		) );
		$this->add_command( 'ogs-seo integrations disconnect', 'integrations_disconnect', 'Disconnect one integration.', array(
			array( 'type' => 'assoc', 'name' => 'integration', 'optional' => false, 'description' => 'Integration slug.' ),
			array( 'type' => 'flag', 'name' => 'yes', 'optional' => true, 'description' => 'Confirm the disconnect operation.' ),
		) );
		$this->add_command( 'ogs-seo indexnow status', 'indexnow_status', 'Show IndexNow health and queue state.', $this->format_synopsis() );
		$this->add_command( 'ogs-seo indexnow process', 'indexnow_process', 'Process the IndexNow queue now.' );
		$this->add_command( 'ogs-seo indexnow verify-key', 'indexnow_verify_key', 'Verify that the IndexNow key file is reachable.' );
		$this->add_command( 'ogs-seo indexnow generate-key', 'indexnow_generate_key', 'Generate a new IndexNow key.', array(
			array( 'type' => 'flag', 'name' => 'yes', 'optional' => true, 'description' => 'Confirm replacement when a key already exists.' ),
		) );
		$this->add_command( 'ogs-seo compatibility status', 'compatibility_status', 'Show detected SEO plugin compatibility state.', $this->format_synopsis() );
		$this->add_command( 'ogs-seo compatibility dry-run', 'compatibility_dry_run', 'Preview compatibility import changes.', array_merge(
			array(
				array( 'type' => 'assoc', 'name' => 'providers', 'optional' => true, 'description' => 'Comma-separated provider slugs.' ),
			),
			$this->format_synopsis()
		) );
		$this->add_command( 'ogs-seo compatibility import', 'compatibility_import', 'Run compatibility import for supported providers.', array(
			array( 'type' => 'assoc', 'name' => 'providers', 'optional' => true, 'description' => 'Comma-separated provider slugs.' ),
			array( 'type' => 'assoc', 'name' => 'overwrite', 'optional' => true, 'description' => '1 to overwrite existing values, 0 to preserve them.' ),
			array( 'type' => 'assoc', 'name' => 'limit', 'optional' => true, 'description' => 'Optional meta import row cap.' ),
			array( 'type' => 'flag', 'name' => 'yes', 'optional' => true, 'description' => 'Confirm destructive overwrite mode.' ),
		) );
		$this->add_command( 'ogs-seo compatibility rollback', 'compatibility_rollback', 'Rollback the last compatibility import.', array(
			array( 'type' => 'flag', 'name' => 'yes', 'optional' => true, 'description' => 'Confirm rollback.' ),
		) );
		$this->add_command( 'ogs-seo tools diagnostics', 'tools_diagnostics', 'Show developer diagnostics.', array(
			array( 'type' => 'assoc', 'name' => 'format', 'optional' => true, 'description' => 'text or json.' ),
			array( 'type' => 'assoc', 'name' => 'refresh', 'optional' => true, 'description' => '1 to bypass the diagnostics cache.' ),
		) );
		$this->add_command( 'ogs-seo tools export', 'tools_export', 'Export plugin settings and operational state.', array(
			array( 'type' => 'assoc', 'name' => 'path', 'optional' => true, 'description' => 'Write JSON payload to this path. Omit to print to stdout.' ),
		) );
		$this->add_command( 'ogs-seo tools import', 'tools_import', 'Import plugin payload from a JSON file.', array(
			array( 'type' => 'assoc', 'name' => 'path', 'optional' => false, 'description' => 'Path to JSON file.' ),
			array( 'type' => 'assoc', 'name' => 'merge', 'optional' => true, 'description' => '1 to merge into existing settings, 0 to replace.' ),
			array( 'type' => 'flag', 'name' => 'yes', 'optional' => true, 'description' => 'Confirm replacement import when --merge=0.' ),
		) );
		$this->add_command( 'ogs-seo tools reset', 'tools_reset', 'Reset plugin settings to defaults.', array(
			array( 'type' => 'flag', 'name' => 'yes', 'optional' => false, 'description' => 'Confirm reset.' ),
			array( 'type' => 'assoc', 'name' => 'preserve_keep_data', 'optional' => true, 'description' => '1 to preserve keep-data behavior, 0 to clear it.' ),
		) );
		$this->add_command( 'ogs-seo tools logs', 'tools_logs', 'Read or clear developer logs.', array(
			array( 'type' => 'assoc', 'name' => 'limit', 'optional' => true, 'description' => 'Maximum log rows to print.' ),
			array( 'type' => 'flag', 'name' => 'clear', 'optional' => true, 'description' => 'Clear the log instead of reading it.' ),
			array( 'type' => 'flag', 'name' => 'yes', 'optional' => true, 'description' => 'Confirm clearing logs.' ),
			array( 'type' => 'assoc', 'name' => 'format', 'optional' => true, 'description' => 'text or json.' ),
		) );
	}

	public function audit_run(): void {
		$issues = ( new AuditManager() )->run_full();
		\WP_CLI::success( 'Audit completed. Issues: ' . count( $issues ) );
	}

	public function audit_status( array $args = array(), array $assoc_args = array() ): void {
		unset( $args );
		$format = $this->format_arg( $assoc_args );
		$audit  = new AuditManager();
		$issues = $audit->get_latest_issues();
		$ignored = $audit->get_ignored_map();
		$state  = $audit->get_scan_state();
		$data   = array(
			'last_run'       => (int) get_option( 'ogs_seo_audit_last_run', 0 ),
			'active_issues'  => count( $issues ),
			'ignored_issues' => count( $ignored ),
			'scan_mode'      => (string) ( $state['mode'] ?? '' ),
			'scan_offset'    => (int) ( $state['offset'] ?? 0 ),
		);
		$this->emit_status( $data, $format );
	}

	public function audit_run_incremental(): void {
		$issues = ( new AuditManager() )->run_incremental();
		\WP_CLI::success( 'Incremental audit completed. Active issues: ' . count( $issues ) );
	}

	public function audit_ignore( array $args, array $assoc_args ): void {
		unset( $args );
		$issue_id = $this->assoc_string( $assoc_args, 'issue_id' );
		$reason   = $this->assoc_string( $assoc_args, 'reason' );
		if ( '' === $issue_id || '' === $reason ) {
			\WP_CLI::error( 'Missing --issue_id and/or --reason.' );
		}
		$ok = ( new AuditManager() )->ignore_issue( $issue_id, $reason );
		if ( $ok ) {
			\WP_CLI::success( 'Issue ignored.' );
			return;
		}
		\WP_CLI::warning( 'Issue could not be ignored.' );
	}

	public function audit_unignore( array $args, array $assoc_args ): void {
		unset( $args );
		$issue_id = $this->assoc_string( $assoc_args, 'issue_id' );
		if ( '' === $issue_id ) {
			\WP_CLI::error( 'Missing --issue_id.' );
		}
		$ok = ( new AuditManager() )->unignore_issue( $issue_id );
		if ( $ok ) {
			\WP_CLI::success( 'Issue restored.' );
			return;
		}
		\WP_CLI::warning( 'Issue could not be restored.' );
	}

	public function sitemap_flush(): void {
		( new Sitemaps() )->purge_cache();
		\WP_CLI::success( 'Sitemap cache invalidated.' );
	}

	public function sitemap_status( array $args = array(), array $assoc_args = array() ): void {
		unset( $args );
		$format   = $this->format_arg( $assoc_args );
		$settings = Settings::get_all();
		$inspect  = ( new Sitemaps() )->inspect();
		$data     = array(
			'enabled'       => ! empty( $settings['sitemap_enabled'] ),
			'index'         => home_url( '/ogs-sitemap.xml' ),
			'post_types'    => array_values( (array) $settings['sitemap_post_types'] ),
			'cache_version' => (string) ( $inspect['cache_version'] ?? '' ),
			'inspect'       => $inspect,
		);
		$this->emit_status( $data, $format );
	}

	public function hreflang_status( array $args = array(), array $assoc_args = array() ): void {
		unset( $args );
		$format = $this->format_arg( $assoc_args );
		$status = ( new Hreflang() )->status();
		$data   = array(
			'enabled'            => ! empty( $status['enabled'] ),
			'provider'           => (string) ( $status['provider'] ?? 'none' ),
			'languages'          => array_values( (array) ( $status['languages'] ?? array() ) ),
			'current_alternates' => count( (array) ( $status['sample'] ?? array() ) ),
			'x_default'          => (string) ( $status['x_default'] ?? '' ),
			'errors'             => array_values( (array) ( $status['errors'] ?? array() ) ),
		);
		$this->emit_status( $data, $format );
	}

	public function schema_status( array $args = array(), array $assoc_args = array() ): void {
		unset( $args );
		$format = $this->format_arg( $assoc_args );
		$status = ( new SchemaManager() )->inspect( array(), false );
		$nodes  = isset( $status['payload']['@graph'] ) && is_array( $status['payload']['@graph'] ) ? $status['payload']['@graph'] : array();
		$data   = array(
			'context_url' => (string) ( $status['context']['url'] ?? '' ),
			'node_count'  => count( $nodes ),
			'nodes'       => array_map(
				static function ( array $node ): array {
					return array(
						'type' => isset( $node['@type'] ) ? (string) $node['@type'] : 'Unknown',
						'id'   => isset( $node['@id'] ) ? (string) $node['@id'] : '',
					);
				},
				$nodes
			),
			'errors'      => array_values( (array) ( $status['errors'] ?? array() ) ),
			'warnings'    => array_values( (array) ( $status['warnings'] ?? array() ) ),
		);
		$this->emit_status( $data, $format );
	}

	public function aeo_analyze( array $args, array $assoc_args ): void {
		unset( $args );
		$format   = $this->format_arg( $assoc_args );
		$post_id  = $this->resolve_post_id( $assoc_args, 'No post available for AEO analysis.' );
		$analysis = Analyzer::analyze_post( $post_id );
		$summary  = isset( $analysis['summary'] ) && is_array( $analysis['summary'] ) ? $analysis['summary'] : array();
		$data     = array(
			'post_id'        => $post_id,
			'aeo_score'      => (int) ( $summary['aeo_score'] ?? 0 ),
			'priority'       => (string) ( $summary['priority_status'] ?? 'needs-work' ),
			'answer_first'   => ! empty( $summary['answer_first'] ),
			'clarity'        => (string) ( $summary['clarity'] ?? '' ),
			'word_count'     => (int) ( $summary['word_count'] ?? 0 ),
			'opportunities'  => array_values( (array) ( $analysis['opportunities'] ?? array() ) ),
			'actions'        => array_values( (array) ( $analysis['priority_actions'] ?? array() ) ),
			'recommendations'=> array_values( (array) ( $analysis['recommendations'] ?? array() ) ),
		);
		$this->emit_status( $data, $format );
	}

	public function geo_analyze( array $args, array $assoc_args ): void {
		unset( $args );
		$format   = $this->format_arg( $assoc_args );
		$post_id  = $this->resolve_post_id( $assoc_args, 'No content available for GEO analysis.' );
		$analysis = BotControls::analyze_post( $post_id, false );
		$summary  = isset( $analysis['summary'] ) && is_array( $analysis['summary'] ) ? $analysis['summary'] : array();
		$signals  = isset( $analysis['signals'] ) && is_array( $analysis['signals'] ) ? $analysis['signals'] : array();
		$data     = array(
			'post_id'                => $post_id,
			'geo_score'              => (int) ( $summary['geo_score'] ?? 0 ),
			'priority'               => (string) ( $summary['priority_status'] ?? 'needs-work' ),
			'critical_text_visible'  => ! empty( $summary['critical_text_visible'] ),
			'schema_text_mismatch'   => ! empty( $summary['schema_text_mismatch'] ),
			'bot_exposure'           => (string) ( $summary['bot_exposure'] ?? '' ),
			'internal_links'         => (int) ( $signals['internal_links']['count'] ?? 0 ),
			'freshness_days'         => (int) ( $signals['freshness']['days_since_modified'] ?? -1 ),
			'opportunities'          => array_values( (array) ( $analysis['opportunities'] ?? array() ) ),
			'actions'                => array_values( (array) ( $analysis['priority_actions'] ?? array() ) ),
			'recommendations'        => array_values( (array) ( $analysis['recommendations'] ?? array() ) ),
		);
		$this->emit_status( $data, $format );
	}

	public function integrations_status( array $args = array(), array $assoc_args = array() ): void {
		unset( $args );
		$format  = $this->format_arg( $assoc_args );
		$payload = IntegrationsManager::get_status_payload();
		$this->emit_status( $payload, $format );
	}

	public function integrations_test( array $args, array $assoc_args ): void {
		unset( $args );
		$slug = $this->assoc_string( $assoc_args, 'integration' );
		if ( '' === $slug ) {
			\WP_CLI::error( 'Missing required argument --integration=<slug>' );
		}
		$result = IntegrationsManager::test_service( $slug );
		if ( ! empty( $result['ok'] ) ) {
			\WP_CLI::success( (string) ( $result['message'] ?? 'Connection test succeeded.' ) );
			return;
		}
		\WP_CLI::warning( (string) ( $result['message'] ?? 'Connection test failed.' ) );
	}

	public function integrations_disconnect( array $args, array $assoc_args ): void {
		unset( $args );
		$slug = $this->assoc_string( $assoc_args, 'integration' );
		if ( '' === $slug ) {
			\WP_CLI::error( 'Missing required argument --integration=<slug>' );
		}
		$this->require_yes_flag( $assoc_args, 'Use --yes to confirm disconnecting an integration.' );
		$result = IntegrationsManager::disconnect_service( $slug );
		if ( ! empty( $result['ok'] ) ) {
			\WP_CLI::success( (string) ( $result['message'] ?? 'Disconnected.' ) );
			return;
		}
		\WP_CLI::warning( (string) ( $result['message'] ?? 'Disconnect failed.' ) );
	}

	public function indexnow_status( array $args = array(), array $assoc_args = array() ): void {
		unset( $args );
		$format = $this->format_arg( $assoc_args );
		$status = ( new IndexNow() )->inspect_status();
		$this->emit_status( $status, $format );
	}

	public function indexnow_process(): void {
		$result = ( new IndexNow() )->process_queue( 'cli' );
		if ( ! empty( $result['ok'] ) ) {
			\WP_CLI::success( (string) ( $result['message'] ?? 'IndexNow queue processing completed.' ) );
			return;
		}
		\WP_CLI::warning( (string) ( $result['message'] ?? 'IndexNow queue processing did not complete successfully.' ) );
	}

	public function indexnow_verify_key(): void {
		$result = ( new IndexNow() )->verify_key_accessible();
		if ( ! empty( $result['ok'] ) ) {
			\WP_CLI::success( (string) ( $result['message'] ?? 'Key verified.' ) );
			return;
		}
		\WP_CLI::warning( (string) ( $result['message'] ?? 'Key verification failed.' ) );
	}

	public function indexnow_generate_key( array $args = array(), array $assoc_args = array() ): void {
		unset( $args );
		$current_key = trim( (string) Settings::get( 'indexnow_key', '' ) );
		if ( '' !== $current_key ) {
			$this->require_yes_flag( $assoc_args, 'Use --yes to replace the existing IndexNow key.' );
		}
		$key = ( new IndexNow() )->generate_key();
		if ( '' === trim( $key ) ) {
			\WP_CLI::warning( 'Could not generate IndexNow key.' );
			return;
		}
		\WP_CLI::success( 'IndexNow key generated.' );
	}

	public function compatibility_status( array $args = array(), array $assoc_args = array() ): void {
		unset( $args );
		$format   = $this->format_arg( $assoc_args );
		$detected = ( new Importer() )->detect();
		$this->emit_status( $detected, $format );
	}

	public function compatibility_dry_run( array $args, array $assoc_args ): void {
		unset( $args );
		$format = $this->format_arg( $assoc_args );
		$slugs  = isset( $assoc_args['providers'] ) ? $this->csv_to_slugs( (string) $assoc_args['providers'] ) : array();
		$report = ( new Importer() )->dry_run( $slugs );
		$this->emit_status( $report, $format );
		if ( 'text' === $format ) {
			\WP_CLI::success( 'Compatibility dry-run completed.' );
		}
	}

	public function compatibility_import( array $args, array $assoc_args ): void {
		unset( $args );
		$slugs     = isset( $assoc_args['providers'] ) ? $this->csv_to_slugs( (string) $assoc_args['providers'] ) : array();
		$overwrite = $this->assoc_bool( $assoc_args, 'overwrite', false );
		$limit     = $this->assoc_int( $assoc_args, 'limit', 0 );
		if ( $overwrite ) {
			$this->require_yes_flag( $assoc_args, 'Use --yes to confirm overwrite mode for compatibility import.' );
		}
		$report = ( new Importer() )->run_import(
			array(
				'slugs'            => $slugs,
				'overwrite'        => $overwrite,
				'include_settings' => true,
				'include_meta'     => true,
				'limit'            => $limit,
			)
		);
		if ( ! empty( $report['rollback_truncated'] ) ) {
			\WP_CLI::warning( 'Rollback snapshot was truncated due to volume cap.' );
		}
		\WP_CLI::success( 'Compatibility import completed.' );
	}

	public function compatibility_rollback( array $args = array(), array $assoc_args = array() ): void {
		unset( $args );
		$this->require_yes_flag( $assoc_args, 'Use --yes to confirm compatibility rollback.' );
		$report = ( new Importer() )->rollback_last_import();
		if ( ! empty( $report['ok'] ) ) {
			\WP_CLI::success( 'Rollback completed.' );
			return;
		}
		\WP_CLI::warning( (string) ( $report['message'] ?? 'Rollback could not be completed.' ) );
	}

	public function tools_diagnostics( array $args = array(), array $assoc_args = array() ): void {
		unset( $args );
		$format      = $this->format_arg( $assoc_args );
		$refresh     = $this->assoc_bool( $assoc_args, 'refresh', false );
		$diagnostics = DeveloperTools::diagnostics_cached( $refresh );
		$this->emit_status( $diagnostics, $format );
	}

	public function tools_export( array $args, array $assoc_args ): void {
		unset( $args );
		$payload = DeveloperTools::export_payload();
		$json    = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$path    = isset( $assoc_args['path'] ) ? trim( (string) $assoc_args['path'] ) : '';
		if ( '' === $path ) {
			\WP_CLI::line( (string) $json );
			return;
		}
		$directory = dirname( $path );
		if ( ! is_dir( $directory ) || ! is_writable( $directory ) ) {
			\WP_CLI::error( 'Export directory is not writable.' );
		}
		if ( false === file_put_contents( $path, (string) $json, LOCK_EX ) ) {
			\WP_CLI::error( 'Could not write export file.' );
		}
		\WP_CLI::success( 'Settings exported to ' . $path );
	}

	public function tools_import( array $args, array $assoc_args ): void {
		unset( $args );
		$path = isset( $assoc_args['path'] ) ? trim( (string) $assoc_args['path'] ) : '';
		if ( '' === $path ) {
			\WP_CLI::error( 'Missing --path=<json_file>' );
		}
		if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
			\WP_CLI::error( 'Import file does not exist or is not readable.' );
		}
		$content = file_get_contents( $path );
		if ( false === $content ) {
			\WP_CLI::error( 'Could not read import file.' );
		}
		$payload = json_decode( $content, true );
		if ( ! is_array( $payload ) ) {
			\WP_CLI::error( 'Import file is not valid JSON.' );
		}
		$merge = $this->assoc_bool( $assoc_args, 'merge', true );
		if ( ! $merge ) {
			$this->require_yes_flag( $assoc_args, 'Use --yes to confirm replacement import mode.' );
		}
		$result = DeveloperTools::import_payload( $payload, $merge );
		if ( empty( $result['ok'] ) ) {
			\WP_CLI::error( (string) ( $result['message'] ?? 'Import failed.' ) );
		}
		\WP_CLI::success( 'Import completed. Changed keys: ' . (string) ( $result['changed'] ?? 0 ) );
	}

	public function tools_reset( array $args, array $assoc_args ): void {
		unset( $args );
		$this->require_yes_flag( $assoc_args, 'Use --yes to confirm reset.' );
		$preserve = $this->assoc_bool( $assoc_args, 'preserve_keep_data', true );
		$result   = DeveloperTools::reset_settings( $preserve );
		\WP_CLI::success( 'Settings reset completed. Reset keys: ' . (string) ( $result['reset_keys'] ?? 0 ) );
	}

	public function tools_logs( array $args, array $assoc_args ): void {
		unset( $args );
		if ( ! empty( $assoc_args['clear'] ) ) {
			$this->require_yes_flag( $assoc_args, 'Use --yes to confirm clearing developer logs.' );
			DeveloperTools::clear_logs();
			\WP_CLI::success( 'Developer debug logs cleared.' );
			return;
		}
		$limit  = max( 1, min( 200, $this->assoc_int( $assoc_args, 'limit', 20 ) ) );
		$format = $this->format_arg( $assoc_args );
		$logs   = DeveloperTools::get_logs( $limit );
		if ( empty( $logs ) ) {
			if ( 'json' === $format ) {
				$this->emit_json( array() );
				return;
			}
			\WP_CLI::log( 'No logs available.' );
			return;
		}
		if ( 'json' === $format ) {
			$this->emit_json( array_values( $logs ) );
			return;
		}
		foreach ( $logs as $entry ) {
			$time    = isset( $entry['time'] ) ? (int) $entry['time'] : 0;
			$when    = $time > 0 ? gmdate( 'c', $time ) : '-';
			$level   = isset( $entry['level'] ) ? (string) $entry['level'] : '';
			$message = isset( $entry['message'] ) ? (string) $entry['message'] : '';
			$context = isset( $entry['context'] ) && is_array( $entry['context'] ) ? wp_json_encode( $entry['context'] ) : '';
			\WP_CLI::log( sprintf( '[%s] %s %s %s', $when, strtoupper( $level ), $message, (string) $context ) );
		}
	}

	private function add_command( string $name, string $method, string $shortdesc, array $synopsis = array(), string $longdesc = '' ): void {
		$config = array(
			'shortdesc' => $shortdesc,
		);
		if ( ! empty( $synopsis ) ) {
			$config['synopsis'] = $synopsis;
		}
		if ( '' !== $longdesc ) {
			$config['longdesc'] = $longdesc;
		}
		\WP_CLI::add_command( $name, array( $this, $method ), $config );
	}

	private function format_synopsis(): array {
		return array(
			array(
				'type'        => 'assoc',
				'name'        => 'format',
				'optional'    => true,
				'default'     => 'text',
				'options'     => array( 'text', 'json' ),
				'description' => 'Output format.',
			),
		);
	}

	private function post_id_synopsis(): array {
		return array(
			array(
				'type'        => 'assoc',
				'name'        => 'post_id',
				'optional'    => true,
				'description' => 'Post ID to analyze. Defaults to the latest public post/page.',
			),
		);
	}

	private function format_arg( array $assoc_args, array $allowed = array( 'text', 'json' ), string $default = 'text' ): string {
		$format = isset( $assoc_args['format'] ) ? strtolower( trim( (string) $assoc_args['format'] ) ) : $default;
		if ( ! in_array( $format, $allowed, true ) ) {
			\WP_CLI::error( 'Invalid --format value. Allowed: ' . implode( ', ', $allowed ) . '.' );
		}
		return $format;
	}

	private function assoc_string( array $assoc_args, string $key, string $default = '' ): string {
		return isset( $assoc_args[ $key ] ) ? sanitize_text_field( (string) $assoc_args[ $key ] ) : $default;
	}

	private function assoc_int( array $assoc_args, string $key, int $default = 0 ): int {
		return isset( $assoc_args[ $key ] ) ? absint( (string) $assoc_args[ $key ] ) : $default;
	}

	private function assoc_bool( array $assoc_args, string $key, bool $default = false ): bool {
		if ( ! isset( $assoc_args[ $key ] ) ) {
			return $default;
		}
		$value = strtolower( trim( (string) $assoc_args[ $key ] ) );
		if ( '' === $value ) {
			return true;
		}
		return in_array( $value, array( '1', 'true', 'yes', 'on' ), true );
	}

	private function require_yes_flag( array $assoc_args, string $message ): void {
		if ( empty( $assoc_args['yes'] ) ) {
			\WP_CLI::error( $message );
		}
	}

	private function resolve_post_id( array $assoc_args, string $error_message ): int {
		$post_id = $this->assoc_int( $assoc_args, 'post_id', 0 );
		if ( $post_id > 0 ) {
			return $post_id;
		}
		$post_types = get_post_types( array( 'public' => true ), 'names' );
		unset( $post_types['attachment'] );
		$post = get_posts(
			array(
				'post_type'      => array_values( $post_types ),
				'post_status'    => 'publish',
				'numberposts'    => 1,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		$post_id = ! empty( $post ) ? (int) $post[0] : 0;
		if ( $post_id <= 0 ) {
			\WP_CLI::error( $error_message );
		}
		return $post_id;
	}

	private function emit_status( array $data, string $format ): void {
		if ( 'json' === $format ) {
			$this->emit_json( $data );
			return;
		}
		foreach ( $data as $key => $value ) {
			if ( is_scalar( $value ) || null === $value ) {
				\WP_CLI::log( $this->labelize( (string) $key ) . ': ' . (string) $value );
				continue;
			}
			\WP_CLI::log( $this->labelize( (string) $key ) . ': ' . (string) wp_json_encode( $value ) );
		}
	}

	private function emit_json( $data ): void {
		\WP_CLI::line( (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}

	private function labelize( string $key ): string {
		return ucwords( str_replace( '_', ' ', $key ) );
	}

	private function csv_to_slugs( string $csv ): array {
		if ( '' === trim( $csv ) ) {
			return array();
		}
		$parts = array_map( 'trim', explode( ',', $csv ) );
		$parts = array_map( 'sanitize_key', $parts );
		$parts = array_filter( $parts, static fn( $slug ) => '' !== $slug );
		return array_values( array_unique( $parts ) );
	}
}
