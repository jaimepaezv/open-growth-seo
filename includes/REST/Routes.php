<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\REST;

use OpenGrowthSolutions\OpenGrowthSEO\Admin\Editor;
use OpenGrowthSolutions\OpenGrowthSEO\AEO\Analyzer;
use OpenGrowthSolutions\OpenGrowthSEO\Audit\AuditManager;
use OpenGrowthSolutions\OpenGrowthSEO\Compatibility\Importer;
use OpenGrowthSolutions\OpenGrowthSEO\GEO\BotControls;
use OpenGrowthSolutions\OpenGrowthSEO\SFO\Analyzer as SfoAnalyzer;
use OpenGrowthSolutions\OpenGrowthSEO\Integrations\Manager as IntegrationsManager;
use OpenGrowthSolutions\OpenGrowthSEO\Integrations\IndexNow;
use OpenGrowthSolutions\OpenGrowthSEO\Installation\Manager as InstallationManager;
use OpenGrowthSolutions\OpenGrowthSEO\Jobs\Queue as JobsQueue;
use OpenGrowthSolutions\OpenGrowthSEO\SEO\Hreflang;
use OpenGrowthSolutions\OpenGrowthSEO\SEO\RedirectImporter;
use OpenGrowthSolutions\OpenGrowthSEO\SEO\Redirects;
use OpenGrowthSolutions\OpenGrowthSEO\SEO\SearchAppearancePreview;
use OpenGrowthSolutions\OpenGrowthSEO\SEO\Sitemaps;
use OpenGrowthSolutions\OpenGrowthSEO\Schema\SchemaManager;
use OpenGrowthSolutions\OpenGrowthSEO\Support\DeveloperTools;
use OpenGrowthSolutions\OpenGrowthSEO\Support\Settings;

defined( 'ABSPATH' ) || exit;

class Routes {
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes(): void {
		foreach ( $this->registrars() as $registrar ) {
			$registrar->register( $this );
		}
	}

	/**
	 * @return array<int, object>
	 */
	private function registrars(): array {
		return array(
			new ContentRoutesRegistrar(),
			new AuditRoutesRegistrar(),
			new TechnicalRoutesRegistrar(),
			new JobsRoutesRegistrar(),
			new IntegrationRoutesRegistrar(),
			new CompatibilityRoutesRegistrar(),
			new ToolsRoutesRegistrar(),
		);
	}

	public function can_manage_options(): bool {
		return current_user_can( 'manage_options' );
	}

	public function can_edit_posts(): bool {
		return current_user_can( 'edit_posts' );
	}

	public function redirect_import_args( bool $include_confirm ): array {
		$args = array(
			'providers' => array(
				'type'              => 'array',
				'required'          => false,
				'sanitize_callback' => array( $this, 'sanitize_key_array' ),
			),
			'mode'      => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => static fn( $value ): string => sanitize_key( (string) $value ),
				'validate_callback' => static function ( $value ): bool {
					$value = sanitize_key( (string) $value );
					return '' === $value || in_array( $value, array( 'merge', 'replace' ), true );
				},
			),
		);
		if ( $include_confirm ) {
			$args['confirm'] = array(
				'type'              => 'boolean',
				'required'          => false,
				'sanitize_callback' => static fn( $value ): bool => rest_sanitize_boolean( $value ),
			);
		}
		return $args;
	}

	public function sanitize_key_array( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_values( array_unique( array_filter( array_map( 'sanitize_key', $value ) ) ) );
	}

	private function ok( array $data, int $status = 200 ): \WP_REST_Response {
		return new \WP_REST_Response( $data, $status );
	}

	private function fail( string $message, int $status = 400, string $code = 'ogs_seo_rest_error', array $extra = array() ): \WP_REST_Response {
		return new \WP_REST_Response(
			array_merge(
				array(
					'ok'      => false,
					'code'    => sanitize_key( $code ),
					'error'   => $message,
					'message' => $message,
				),
				$extra
			),
			$status
		);
	}

	private function latest_public_post_id(): int {
		$post_types = get_post_types(
			array(
				'public' => true,
			),
			'names'
		);
		unset( $post_types['attachment'] );
		$post = get_posts(
			array(
				'post_type'     => array_values( $post_types ),
				'post_status'   => 'publish',
				'numberposts'   => 1,
				'orderby'       => 'modified',
				'order'         => 'DESC',
				'fields'        => 'ids',
				'no_found_rows' => true,
			)
		);
		return ! empty( $post ) ? (int) $post[0] : 0;
	}

	public function run_audit(): \WP_REST_Response {
		$issues = ( new AuditManager() )->run_full();
		return new \WP_REST_Response( array( 'issues' => $issues ), 200 );
	}

	public function audit_status(): \WP_REST_Response {
		$audit = new AuditManager();
		return new \WP_REST_Response(
			array(
				'last_run' => (int) get_option( 'ogs_seo_audit_last_run', 0 ),
				'state'    => $audit->get_scan_state(),
				'summary'  => $audit->summary(),
				'groups'   => array_map( 'count', $audit->grouped_issues() ),
				'issues'   => $audit->get_latest_issues(),
				'ignored'  => $audit->get_ignored_map(),
			),
			200
		);
	}

	public function audit_ignore( \WP_REST_Request $request ): \WP_REST_Response {
		$issue_id = sanitize_key( (string) $request->get_param( 'issue_id' ) );
		$reason   = sanitize_text_field( (string) $request->get_param( 'reason' ) );
		$ok       = ( new AuditManager() )->ignore_issue( $issue_id, $reason );
		return $this->ok(
			array(
				'ok'      => $ok,
				'message' => $ok ? __( 'Issue ignored.', 'open-growth-seo' ) : __( 'Invalid issue id or reason.', 'open-growth-seo' ),
			),
			$ok ? 200 : 400
		);
	}

	public function audit_unignore( \WP_REST_Request $request ): \WP_REST_Response {
		$issue_id = sanitize_key( (string) $request->get_param( 'issue_id' ) );
		$ok       = ( new AuditManager() )->unignore_issue( $issue_id );
		return $this->ok(
			array(
				'ok'      => $ok,
				'message' => $ok ? __( 'Issue restored.', 'open-growth-seo' ) : __( 'Issue could not be restored.', 'open-growth-seo' ),
			),
			$ok ? 200 : 400
		);
	}

	public function sitemaps_status(): \WP_REST_Response {
		$settings   = Settings::get_all();
		$post_types = isset( $settings['sitemap_post_types'] ) && is_array( $settings['sitemap_post_types'] ) ? $settings['sitemap_post_types'] : array();
		return new \WP_REST_Response(
			array(
				'enabled'    => (bool) (int) $settings['sitemap_enabled'],
				'index_url'  => home_url( '/ogs-sitemap.xml' ),
				'post_types' => array_values( array_map( 'sanitize_key', $post_types ) ),
			),
			200
		);
	}

	public function sitemaps_inspect(): \WP_REST_Response {
		return new \WP_REST_Response( ( new Sitemaps() )->inspect(), 200 );
	}

	public function redirects_status(): \WP_REST_Response {
		return new \WP_REST_Response( Redirects::status_payload(), 200 );
	}

	public function redirects_404_log(): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'entries' => Redirects::get_404_log(),
			),
			200
		);
	}

	public function redirects_import_sources(): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'sources'            => RedirectImporter::detect_sources(),
				'last_report'        => RedirectImporter::get_last_report(),
				'last_rollback_plan' => RedirectImporter::get_last_rollback_plan(),
				'rollback_available' => RedirectImporter::has_rollback_snapshot(),
			),
			200
		);
	}

	public function redirects_import_dry_run( \WP_REST_Request $request ): \WP_REST_Response {
		$providers = $this->sanitize_key_array( $request->get_param( 'providers' ) );
		$mode      = sanitize_key( (string) $request->get_param( 'mode' ) );
		$merge     = 'replace' !== $mode;
		return $this->ok( RedirectImporter::dry_run( $providers, $merge ), 200 );
	}

	public function redirects_import_run( \WP_REST_Request $request ): \WP_REST_Response {
		$providers = $this->sanitize_key_array( $request->get_param( 'providers' ) );
		$mode      = sanitize_key( (string) $request->get_param( 'mode' ) );
		$merge     = 'replace' !== $mode;
		$confirmed = rest_sanitize_boolean( $request->get_param( 'confirm' ) );
		$result    = RedirectImporter::run_import( $providers, $merge, $confirmed );
		$status    = ! empty( $result['ok'] ) ? 200 : 400;
		return $this->ok( $result, $status );
	}

	public function redirects_import_rollback(): \WP_REST_Response {
		$result = RedirectImporter::rollback_last_import();
		$status = ! empty( $result['ok'] ) ? 200 : 400;
		return $this->ok( $result, $status );
	}

	public function search_appearance_preview( \WP_REST_Request $request ): \WP_REST_Response {
		$settings = Settings::get_all();
		$payload  = array(
			'title_template'            => (string) $request->get_param( 'title_template' ),
			'meta_description_template' => (string) $request->get_param( 'meta_description_template' ),
			'title_separator'           => (string) $request->get_param( 'title_separator' ),
			'sample_title'              => (string) $request->get_param( 'sample_title' ),
			'sample_excerpt'            => (string) $request->get_param( 'sample_excerpt' ),
			'search_query'              => (string) $request->get_param( 'search_query' ),
			'site_name'                 => (string) get_bloginfo( 'name' ),
			'site_description'          => (string) get_bloginfo( 'description' ),
			'home_url'                  => home_url( '/' ),
			'schema_article_enabled'    => (int) ( $settings['schema_article_enabled'] ?? 0 ),
			'schema_faq_enabled'        => (int) ( $settings['schema_faq_enabled'] ?? 0 ),
			'schema_video_enabled'      => (int) ( $settings['schema_video_enabled'] ?? 0 ),
			'og_enabled'                => ! empty( $request->get_param( 'og_enabled' ) ),
			'twitter_enabled'           => ! empty( $request->get_param( 'twitter_enabled' ) ),
		);
		$preview = SearchAppearancePreview::resolve( $payload );
		$preview['og_enabled']      = ! empty( $payload['og_enabled'] );
		$preview['twitter_enabled'] = ! empty( $payload['twitter_enabled'] );
		return new \WP_REST_Response( $preview, 200 );
	}

	public function can_edit_content_meta( \WP_REST_Request $request ): bool {
		$post_id = absint( (string) $request->get_param( 'post_id' ) );
		return $post_id > 0 && current_user_can( 'edit_post', $post_id );
	}

	public function content_meta_get( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = absint( (string) $request->get_param( 'post_id' ) );
		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			return $this->fail( __( 'Requested content could not be found.', 'open-growth-seo' ), 404, 'ogs_seo_missing_post' );
		}
		$editor  = new Editor();
		return $this->ok(
			array(
				'post_id' => $post_id,
				'meta'    => $editor->meta_snapshot( $post_id ),
			),
			200
		);
	}

	public function content_meta_save( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = absint( (string) $request->get_param( 'post_id' ) );
		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			return $this->fail( __( 'Requested content could not be found.', 'open-growth-seo' ), 404, 'ogs_seo_missing_post' );
		}
		$meta    = $request->get_param( 'meta' );
		if ( null !== $meta && ! is_array( $meta ) ) {
			return $this->fail( __( 'Meta payload must be an object.', 'open-growth-seo' ), 400, 'ogs_seo_invalid_meta_payload' );
		}
		if ( ! is_array( $meta ) ) {
			$meta = array();
		}
		$editor = new Editor();
		return $this->ok(
			array(
				'ok'      => true,
				'post_id' => $post_id,
				'meta'    => $editor->persist_meta_values( $post_id, $meta ),
			),
			200
		);
	}

	public function hreflang_status(): \WP_REST_Response {
		return new \WP_REST_Response( ( new Hreflang() )->status(), 200 );
	}

	public function schema_inspect( \WP_REST_Request $request ): \WP_REST_Response {
		$probe = array(
			'post_id' => absint( (string) $request->get_param( 'post_id' ) ),
			'url'     => esc_url_raw( (string) $request->get_param( 'url' ) ),
		);
		return new \WP_REST_Response( ( new SchemaManager() )->inspect( $probe ), 200 );
	}

	public function aeo_analyze( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = absint( (string) $request->get_param( 'post_id' ) );
		if ( $post_id <= 0 ) {
			$post_id = $this->latest_public_post_id();
		}
		if ( $post_id <= 0 ) {
			return $this->fail( __( 'No post available for AEO analysis.', 'open-growth-seo' ), 404, 'ogs_seo_no_aeo_post' );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return $this->fail( __( 'You are not allowed to analyze this content.', 'open-growth-seo' ), 403, 'ogs_seo_forbidden_content_analysis' );
		}
		return $this->ok(
			array(
				'ok'       => true,
				'post_id'  => $post_id,
				'analysis' => Analyzer::analyze_post( $post_id ),
			),
			200
		);
	}

	public function geo_analyze( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = absint( (string) $request->get_param( 'post_id' ) );
		if ( $post_id <= 0 ) {
			$post_id = $this->latest_public_post_id();
		}
		if ( $post_id <= 0 ) {
			return $this->fail( __( 'No content available for GEO analysis.', 'open-growth-seo' ), 404, 'ogs_seo_no_geo_post' );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return $this->fail( __( 'You are not allowed to analyze this content.', 'open-growth-seo' ), 403, 'ogs_seo_forbidden_content_analysis' );
		}
		return $this->ok(
			array(
				'ok'       => true,
				'post_id'  => $post_id,
				'analysis' => BotControls::analyze_post( $post_id ),
			),
			200
		);
	}

	public function geo_telemetry( \WP_REST_Request $request ): \WP_REST_Response {
		$limit = absint( (string) $request->get_param( 'limit' ) );
		if ( $limit <= 0 ) {
			$limit = 8;
		}
		return $this->ok( BotControls::telemetry( $limit ), 200 );
	}

	public function sfo_analyze( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = absint( (string) $request->get_param( 'post_id' ) );
		if ( $post_id <= 0 ) {
			$post_id = $this->latest_public_post_id();
		}
		if ( $post_id <= 0 ) {
			return $this->fail( __( 'No content available for SFO analysis.', 'open-growth-seo' ), 404, 'ogs_seo_no_sfo_post' );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return $this->fail( __( 'You are not allowed to analyze this content.', 'open-growth-seo' ), 403, 'ogs_seo_forbidden_content_analysis' );
		}
		return $this->ok(
			array(
				'ok'       => true,
				'post_id'  => $post_id,
				'analysis' => SfoAnalyzer::analyze_post( $post_id ),
			),
			200
		);
	}

	public function sfo_telemetry( \WP_REST_Request $request ): \WP_REST_Response {
		$limit = absint( (string) $request->get_param( 'limit' ) );
		if ( $limit <= 0 ) {
			$limit = 8;
		}
		return $this->ok( SfoAnalyzer::telemetry( $limit ), 200 );
	}

	public function integration_test( \WP_REST_Request $request ): \WP_REST_Response {
		$integration = sanitize_key( (string) $request->get_param( 'integration' ) );
		if ( '' === $integration ) {
			return $this->fail( __( 'Integration slug is required.', 'open-growth-seo' ), 400, 'ogs_seo_missing_integration' );
		}
		$result      = IntegrationsManager::test_service( $integration );
		$status      = ! empty( $result['ok'] ) ? 200 : 400;
		return $this->ok( $result, $status );
	}

	public function integration_gsc_operational( \WP_REST_Request $request ): \WP_REST_Response {
		$refresh = ! empty( $request->get_param( 'refresh' ) );
		return new \WP_REST_Response( IntegrationsManager::google_search_console_operational_report( $refresh ), 200 );
	}

	public function integration_disconnect( \WP_REST_Request $request ): \WP_REST_Response {
		$integration = sanitize_key( (string) $request->get_param( 'integration' ) );
		if ( '' === $integration ) {
			return $this->fail( __( 'Integration slug is required.', 'open-growth-seo' ), 400, 'ogs_seo_missing_integration' );
		}
		$result      = IntegrationsManager::disconnect_service( $integration );
		$status      = ! empty( $result['ok'] ) ? 200 : 400;
		return $this->ok( $result, $status );
	}

	public function indexnow_process(): \WP_REST_Response {
		$indexnow = new IndexNow();
		$indexnow->process_queue();
		return new \WP_REST_Response(
			array(
				'message' => __( 'IndexNow queue processing triggered.', 'open-growth-seo' ),
				'status'  => $indexnow->inspect_status(),
			),
			200
		);
	}

	public function indexnow_verify_key(): \WP_REST_Response {
		$result = ( new IndexNow() )->verify_key_accessible();
		$status = ! empty( $result['ok'] ) ? 200 : 400;
		return new \WP_REST_Response( $result, $status );
	}

	public function indexnow_generate_key(): \WP_REST_Response {
		$key = ( new IndexNow() )->generate_key();
		if ( '' === trim( $key ) ) {
			return new \WP_REST_Response(
				array(
					'ok'      => false,
					'message' => __( 'Could not generate IndexNow key.', 'open-growth-seo' ),
				),
				400
			);
		}
		return new \WP_REST_Response(
			array(
				'ok'      => true,
				'message' => __( 'IndexNow key generated.', 'open-growth-seo' ),
				'status'  => ( new IndexNow() )->inspect_status(),
			),
			200
		);
	}

	public function jobs_status(): \WP_REST_Response {
		$indexnow = new IndexNow();
		$queue    = new JobsQueue();
		return $this->ok(
			array(
				'jobs' => array(
					'indexnow' => array(
						'status' => $indexnow->inspect_status(),
						'queue'  => $queue->inspect(),
					),
				),
			),
			200
		);
	}

	public function jobs_indexnow_process(): \WP_REST_Response {
		$result = ( new IndexNow() )->process_queue( 'rest' );
		return $this->ok( $result, ! empty( $result['ok'] ) ? 200 : 400 );
	}

	public function jobs_indexnow_requeue_failed( \WP_REST_Request $request ): \WP_REST_Response {
		$limit = absint( (string) $request->get_param( 'limit' ) );
		if ( $limit <= 0 ) {
			$limit = 20;
		}
		$count = ( new JobsQueue() )->requeue_failed_recent( $limit );
		return $this->ok(
			array(
				'ok'      => true,
				'count'   => $count,
				'message' => 0 === $count ? __( 'No failed IndexNow URLs were available to requeue.', 'open-growth-seo' ) : sprintf( __( 'Requeued %d failed IndexNow URLs.', 'open-growth-seo' ), $count ),
			),
			200
		);
	}

	public function jobs_indexnow_release_lock(): \WP_REST_Response {
		( new JobsQueue() )->force_release_lock();
		return $this->ok(
			array(
				'ok'      => true,
				'message' => __( 'IndexNow queue lock released.', 'open-growth-seo' ),
			),
			200
		);
	}

	public function compatibility_status(): \WP_REST_Response {
		$importer = new Importer();
		return $this->ok(
			array(
				'detected'       => $importer->detect(),
				'state'          => $importer->get_state(),
				'rollback_ready' => $importer->has_rollback_snapshot(),
			),
			200
		);
	}

	public function compatibility_dry_run( \WP_REST_Request $request ): \WP_REST_Response {
		$slugs  = $this->sanitize_key_array( $request->get_param( 'slugs' ) );
		$report = ( new Importer() )->dry_run( $slugs );
		return $this->ok( $report, 200 );
	}

	public function compatibility_import( \WP_REST_Request $request ): \WP_REST_Response {
		$slugs     = $this->sanitize_key_array( $request->get_param( 'slugs' ) );
		$overwrite = rest_sanitize_boolean( $request->get_param( 'overwrite' ) );
		$limit     = absint( (string) $request->get_param( 'limit' ) );
		$report    = ( new Importer() )->run_import(
			array(
				'slugs'            => $slugs,
				'overwrite'        => $overwrite,
				'include_settings' => true,
				'include_meta'     => true,
				'limit'            => $limit,
			)
		);
		return $this->ok( $report, ! empty( $report['ok'] ) || ! isset( $report['ok'] ) ? 200 : 400 );
	}

	public function compatibility_rollback(): \WP_REST_Response {
		$report = ( new Importer() )->rollback_last_import();
		return $this->ok( $report, ! empty( $report['ok'] ) ? 200 : 400 );
	}

	public function dev_tools_diagnostics( \WP_REST_Request $request ): \WP_REST_Response {
		$refresh = rest_sanitize_boolean( $request->get_param( 'refresh' ) );
		return $this->ok( DeveloperTools::diagnostics_cached( $refresh ), 200 );
	}

	public function installation_telemetry( \WP_REST_Request $request ): \WP_REST_Response {
		$limit  = absint( (string) $request->get_param( 'limit' ) );
		$filter = sanitize_key( (string) $request->get_param( 'filter' ) );
		$group  = sanitize_key( (string) $request->get_param( 'group' ) );
		if ( $limit <= 0 ) {
			$limit = 12;
		}
		if ( '' === $filter ) {
			$filter = 'all';
		}
		if ( '' === $group ) {
			$group = 'none';
		}
		return $this->ok( ( new InstallationManager() )->telemetry( $limit, $filter, $group ), 200 );
	}

	public function dev_tools_export(): \WP_REST_Response {
		return $this->ok( DeveloperTools::export_payload(), 200 );
	}

	public function dev_tools_import( \WP_REST_Request $request ): \WP_REST_Response {
		$payload = $request->get_param( 'payload' );
		$merge   = ! empty( $request->get_param( 'merge' ) );
		if ( is_string( $payload ) ) {
			if ( strlen( $payload ) > DeveloperTools::max_import_bytes() ) {
				return $this->fail(
					sprintf( __( 'Import payload is too large. Max allowed size is %d KB.', 'open-growth-seo' ), (int) ( DeveloperTools::max_import_bytes() / 1024 ) ),
					400,
					'ogs_seo_dev_tools_payload_too_large'
				);
			}
			$payload = json_decode( $payload, true );
		}
		if ( ! is_array( $payload ) ) {
			return $this->fail( __( 'Import payload must be an object or JSON string.', 'open-growth-seo' ), 400, 'ogs_seo_dev_tools_invalid_payload' );
		}
		$result = DeveloperTools::import_payload( $payload, $merge );
		return $this->ok( $result, ! empty( $result['ok'] ) ? 200 : 400 );
	}

	public function dev_tools_reset( \WP_REST_Request $request ): \WP_REST_Response {
		$preserve = rest_sanitize_boolean( $request->get_param( 'preserve_keep_data' ) );
		return $this->ok( DeveloperTools::reset_settings( $preserve ), 200 );
	}

	public function dev_tools_logs( \WP_REST_Request $request ): \WP_REST_Response {
		$limit = absint( (string) $request->get_param( 'limit' ) );
		if ( $limit <= 0 ) {
			$limit = 50;
		}
		return $this->ok( array( 'logs' => DeveloperTools::get_logs( $limit ) ), 200 );
	}

	public function dev_tools_logs_clear(): \WP_REST_Response {
		DeveloperTools::clear_logs();
		return $this->ok(
			array(
				'ok'      => true,
				'message' => __( 'Developer debug logs cleared.', 'open-growth-seo' ),
			),
			200
		);
	}
}
