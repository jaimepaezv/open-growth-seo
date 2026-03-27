<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\Admin;

use OpenGrowthSolutions\OpenGrowthSEO\AEO\Analyzer;
use OpenGrowthSolutions\OpenGrowthSEO\AEO\LinkGraph;
use OpenGrowthSolutions\OpenGrowthSEO\Audit\AuditManager;
use OpenGrowthSolutions\OpenGrowthSEO\Compatibility\Detector;
use OpenGrowthSolutions\OpenGrowthSEO\Compatibility\Importer;
use OpenGrowthSolutions\OpenGrowthSEO\Core\Defaults;
use OpenGrowthSolutions\OpenGrowthSEO\GEO\BotControls;
use OpenGrowthSolutions\OpenGrowthSEO\Integrations\IndexNow as IndexNowIntegration;
use OpenGrowthSolutions\OpenGrowthSEO\Integrations\Manager as IntegrationsManager;
use OpenGrowthSolutions\OpenGrowthSEO\Installation\Manager as InstallationManager;
use OpenGrowthSolutions\OpenGrowthSEO\SEO\Hreflang;
use OpenGrowthSolutions\OpenGrowthSEO\SEO\FrontendMeta;
use OpenGrowthSolutions\OpenGrowthSEO\SEO\LocalSeoLocations;
use OpenGrowthSolutions\OpenGrowthSEO\SEO\RedirectImporter;
use OpenGrowthSolutions\OpenGrowthSEO\SEO\Redirects;
use OpenGrowthSolutions\OpenGrowthSEO\SEO\SearchAppearancePreview;
use OpenGrowthSolutions\OpenGrowthSEO\SEO\Robots;
use OpenGrowthSolutions\OpenGrowthSEO\Schema\SupportMatrix;
use OpenGrowthSolutions\OpenGrowthSEO\Schema\SchemaManager;
use OpenGrowthSolutions\OpenGrowthSEO\Schema\RuntimeInspector;
use OpenGrowthSolutions\OpenGrowthSEO\SFO\Analyzer as SfoAnalyzer;
use OpenGrowthSolutions\OpenGrowthSEO\Support\DeveloperTools;
use OpenGrowthSolutions\OpenGrowthSEO\Support\ExperienceMode;
use OpenGrowthSolutions\OpenGrowthSEO\Support\SeoMastersPlus;
use OpenGrowthSolutions\OpenGrowthSEO\Support\Settings;

defined( 'ABSPATH' ) || exit;

class Admin {
	private array $pending_settings_override = array();

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'network_admin_menu', array( $this, 'network_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_ogs_seo_run_audit', array( $this, 'run_audit_action' ) );
		add_action( 'admin_post_ogs_seo_ignore_issue', array( $this, 'ignore_issue_action' ) );
		add_action( 'admin_post_ogs_seo_unignore_issue', array( $this, 'unignore_issue_action' ) );
		add_action( 'admin_post_ogs_seo_fix_issue', array( $this, 'fix_issue_action' ) );
		add_action( 'admin_post_ogs_seo_import_dry_run', array( $this, 'import_dry_run_action' ) );
		add_action( 'admin_post_ogs_seo_import_run', array( $this, 'import_run_action' ) );
		add_action( 'admin_post_ogs_seo_import_rollback', array( $this, 'import_rollback_action' ) );
		add_action( 'admin_post_ogs_seo_rerun_installation_diagnostics', array( $this, 'rerun_installation_diagnostics_action' ) );
		add_action( 'admin_post_ogs_seo_rebuild_installation_state', array( $this, 'rebuild_installation_state_action' ) );
		add_action( 'admin_post_ogs_seo_dev_export', array( $this, 'dev_export_action' ) );
		add_action( 'admin_post_ogs_seo_dev_import', array( $this, 'dev_import_action' ) );
		add_action( 'admin_post_ogs_seo_dev_reset', array( $this, 'dev_reset_action' ) );
		add_action( 'admin_post_ogs_seo_dev_clear_logs', array( $this, 'dev_clear_logs_action' ) );
		add_action( 'admin_post_ogs_seo_schema_mapping_export', array( $this, 'schema_mapping_export_action' ) );
		add_action( 'admin_post_ogs_seo_schema_mapping_import', array( $this, 'schema_mapping_import_action' ) );
		add_action( 'admin_post_ogs_seo_redirect_add', array( $this, 'redirect_add_action' ) );
		add_action( 'admin_post_ogs_seo_redirect_delete', array( $this, 'redirect_delete_action' ) );
		add_action( 'admin_post_ogs_seo_redirect_toggle', array( $this, 'redirect_toggle_action' ) );
		add_action( 'admin_post_ogs_seo_redirect_clear_404_log', array( $this, 'redirect_clear_404_log_action' ) );
		add_action( 'admin_post_ogs_seo_redirect_import_dry_run', array( $this, 'redirect_import_dry_run_action' ) );
		add_action( 'admin_post_ogs_seo_redirect_import_run', array( $this, 'redirect_import_run_action' ) );
		add_action( 'admin_post_ogs_seo_redirect_import_rollback', array( $this, 'redirect_import_rollback_action' ) );
	}

	public function menu(): void {
		$cap = 'manage_options';
		add_menu_page( __( 'Open Growth SEO', 'open-growth-seo' ), __( 'Open Growth SEO', 'open-growth-seo' ), $cap, 'ogs-seo-dashboard', array( $this, 'render_dashboard' ), 'dashicons-chart-line', 65 );
		foreach ( $this->admin_pages() as $slug => $meta ) {
			if ( 'ogs-seo-dashboard' === $slug ) {
				continue;
			}
			$title = isset( $meta['title'] ) ? (string) $meta['title'] : '';
			add_submenu_page( 'ogs-seo-dashboard', $title, $title, $cap, $slug, array( $this, 'render_generic' ) );
		}
	}

	public function network_menu(): void {
		if ( ! is_multisite() ) {
			return;
		}
		add_submenu_page(
			'settings.php',
			__( 'Open Growth SEO Network', 'open-growth-seo' ),
			__( 'Open Growth SEO', 'open-growth-seo' ),
			'manage_network_options',
			'ogs-seo-network-installation',
			array( $this, 'render_network_installation' )
		);
	}

	private function admin_pages(): array {
		return array(
			'ogs-seo-dashboard'         => array(
				'title'       => __( 'Dashboard', 'open-growth-seo' ),
				'description' => __( 'Site-wide SEO, AEO, and GEO health overview with prioritized actions.', 'open-growth-seo' ),
				'advanced'    => false,
			),
			'ogs-seo-setup'             => array(
				'title'       => __( 'Setup Wizard', 'open-growth-seo' ),
				'description' => __( 'Configure safe defaults with minimal friction. You can re-run this wizard anytime.', 'open-growth-seo' ),
				'advanced'    => false,
			),
			'ogs-seo-search-appearance' => array(
				'title'       => __( 'Search Appearance', 'open-growth-seo' ),
				'description' => __( 'Define global templates, robots defaults, canonical behavior, and realistic snippet previews.', 'open-growth-seo' ),
				'advanced'    => false,
			),
			'ogs-seo-content'           => array(
				'title'       => __( 'Content Controls', 'open-growth-seo' ),
				'description' => __( 'Review AEO content signals and content-level optimization guidance.', 'open-growth-seo' ),
				'advanced'    => false,
			),
			'ogs-seo-sfo'               => array(
				'title'       => __( 'SFO', 'open-growth-seo' ),
				'description' => __( 'Review search feature readiness for snippets, answers, schema, and preview quality.', 'open-growth-seo' ),
				'advanced'    => false,
			),
			'ogs-seo-schema'            => array(
				'title'       => __( 'Schema', 'open-growth-seo' ),
				'description' => __( 'Configure contextual structured data outputs and inspect active schema diagnostics.', 'open-growth-seo' ),
				'advanced'    => true,
			),
			'ogs-seo-sitemaps'          => array(
				'title'       => __( 'Sitemaps', 'open-growth-seo' ),
				'description' => __( 'Manage XML sitemap coverage and validate runtime sitemap health.', 'open-growth-seo' ),
				'advanced'    => false,
			),
			'ogs-seo-redirects'         => array(
				'title'       => __( 'Redirects & 404', 'open-growth-seo' ),
				'description' => __( 'Manage safe redirects, review unresolved 404 paths, and remediate route-level crawl issues.', 'open-growth-seo' ),
				'advanced'    => false,
			),
			'ogs-seo-bots'              => array(
				'title'       => __( 'Bots & Crawlers', 'open-growth-seo' ),
				'description' => __( 'Control robots.txt policies and preview bot-specific crawl directives safely.', 'open-growth-seo' ),
				'advanced'    => true,
			),
			'ogs-seo-integrations'      => array(
				'title'       => __( 'Integrations', 'open-growth-seo' ),
				'description' => __( 'Manage optional external services without coupling core plugin behavior.', 'open-growth-seo' ),
				'advanced'    => true,
			),
			'ogs-seo-masters-plus'      => array(
				'title'       => __( 'SEO MASTERS PLUS', 'open-growth-seo' ),
				'description' => __( 'Expert operational layer with advanced diagnostics, guardrails, and confidence-based remediation.', 'open-growth-seo' ),
				'advanced'    => true,
			),
			'ogs-seo-audits'            => array(
				'title'       => __( 'Audits', 'open-growth-seo' ),
				'description' => __( 'Run and review prioritized technical findings with actionable remediation.', 'open-growth-seo' ),
				'advanced'    => false,
			),
			'ogs-seo-tools'             => array(
				'title'       => __( 'Tools', 'open-growth-seo' ),
				'description' => __( 'Developer diagnostics, import/export, and safe maintenance utilities.', 'open-growth-seo' ),
				'advanced'    => true,
			),
			'ogs-seo-settings'          => array(
				'title'       => __( 'Settings', 'open-growth-seo' ),
				'description' => __( 'Core plugin defaults and social/canonical controls.', 'open-growth-seo' ),
				'advanced'    => false,
			),
		);
	}

	public function assets( string $hook ): void {
		$is_ogs_screen = 0 === strpos( $hook, 'toplevel_page_ogs-seo-' ) || 0 === strpos( $hook, 'open-growth-seo_page_ogs-seo-' );
		if ( ! $is_ogs_screen ) {
			return;
		}

		wp_enqueue_style( 'ogs-seo-admin', OGS_SEO_URL . 'assets/css/admin.css', array(), OGS_SEO_VERSION );
		wp_enqueue_script( 'ogs-seo-admin-rest', OGS_SEO_URL . 'assets/js/admin-rest.js', array(), OGS_SEO_VERSION, true );
		wp_localize_script(
			'ogs-seo-admin-rest',
			'ogsSeoAdminRest',
			array(
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'texts' => array(
					'loading'        => __( 'Loading REST response...', 'open-growth-seo' ),
					'copied'         => __( 'Route copied.', 'open-growth-seo' ),
					'copyFailed'     => __( 'Copy the route manually from the code field.', 'open-growth-seo' ),
					'fetchFailed'    => __( 'REST request failed.', 'open-growth-seo' ),
					'showResponse'   => __( 'Show response', 'open-growth-seo' ),
					'refreshResponse' => __( 'Refresh response', 'open-growth-seo' ),
					'hideResponse'   => __( 'Hide response', 'open-growth-seo' ),
				),
			)
		);
		if ( 'toplevel_page_ogs-seo-dashboard' === $hook ) {
			wp_enqueue_script( 'ogs-seo-dashboard', OGS_SEO_URL . 'assets/js/dashboard.js', array(), OGS_SEO_VERSION, true );
			wp_localize_script(
				'ogs-seo-dashboard',
				'ogsSeoDashboard',
				array(
					'endpoints' => array(
						'sitemaps'     => esc_url_raw( rest_url( 'ogs-seo/v1/sitemaps/status' ) ),
						'audit'        => esc_url_raw( rest_url( 'ogs-seo/v1/audit/status' ) ),
						'integrations' => esc_url_raw( rest_url( 'ogs-seo/v1/integrations/status' ) ),
					),
					'nonce'     => wp_create_nonce( 'wp_rest' ),
					'texts'     => array(
						'loadingText'                    => __( 'Loading live status checks...', 'open-growth-seo' ),
						'errorText'                      => __( 'Live status unavailable. Check REST access and try again.', 'open-growth-seo' ),
						'emptyText'                      => __( 'No live data was returned by runtime checks.', 'open-growth-seo' ),
						'updatedText'                    => __( 'Live checks refreshed from runtime endpoints.', 'open-growth-seo' ),
						'sitemapsLabel'                  => __( 'Sitemaps runtime', 'open-growth-seo' ),
						'sitemapsEnabledText'            => __( 'Sitemaps are enabled and configured.', 'open-growth-seo' ),
						'sitemapsDisabledText'           => __( 'Sitemaps are disabled or have no included post type.', 'open-growth-seo' ),
						'auditLabel'                     => __( 'Audit runtime', 'open-growth-seo' ),
						'auditPendingText'               => __( 'No completed audit yet.', 'open-growth-seo' ),
						'auditHealthyText'               => __( 'No current audit issues detected.', 'open-growth-seo' ),
						'auditNeedsAttentionText'        => __( 'Important issues detected in latest audit.', 'open-growth-seo' ),
						'auditCriticalText'              => __( 'Critical issues detected in latest audit.', 'open-growth-seo' ),
						'auditInProgressText'            => __( 'Incremental scan appears in progress.', 'open-growth-seo' ),
						'integrationsLabel'              => __( 'Integrations runtime', 'open-growth-seo' ),
						'integrationsOptionalText'       => __( 'No optional integrations enabled.', 'open-growth-seo' ),
						'integrationsHealthyText'        => __( 'Enabled integrations are configured.', 'open-growth-seo' ),
						'integrationsNeedsConfigText'    => __( 'One or more enabled integrations need configuration.', 'open-growth-seo' ),
						'integrationsNeedConnectionText' => __( 'Some enabled integrations are not currently connected.', 'open-growth-seo' ),
						'statusUnavailableText'          => __( 'Status unavailable.', 'open-growth-seo' ),
						'postTypesLabel'                 => __( 'Post types', 'open-growth-seo' ),
						'indexUrlLabel'                  => __( 'Index URL', 'open-growth-seo' ),
						'lastRunLabel'                   => __( 'Last audit run', 'open-growth-seo' ),
						'issuesLabel'                    => __( 'Issues', 'open-growth-seo' ),
						'enabledLabel'                   => __( 'Enabled', 'open-growth-seo' ),
						'connectedLabel'                 => __( 'Connected', 'open-growth-seo' ),
						'configuredLabel'                => __( 'Configured', 'open-growth-seo' ),
						'criticalLabel'                  => __( 'Critical', 'open-growth-seo' ),
						'importantLabel'                 => __( 'Important', 'open-growth-seo' ),
						'minorLabel'                     => __( 'Minor', 'open-growth-seo' ),
						'noneLabel'                      => __( 'None', 'open-growth-seo' ),
					),
				)
			);
		}
		if ( 'open-growth-seo_page_ogs-seo-setup' === $hook ) {
			wp_enqueue_script( 'ogs-seo-setup', OGS_SEO_URL . 'assets/js/setup-wizard.js', array(), OGS_SEO_VERSION, true );
			wp_localize_script(
				'ogs-seo-setup',
				'ogsSeoSetupWizard',
				array(
					'confirmPrivateText' => __( 'Confirm indexing warning before continuing.', 'open-growth-seo' ),
				)
			);
		}
		if ( 'open-growth-seo_page_ogs-seo-search-appearance' === $hook ) {
			wp_enqueue_script( 'ogs-seo-search-appearance', OGS_SEO_URL . 'assets/js/search-appearance.js', array(), OGS_SEO_VERSION, true );
			wp_localize_script(
				'ogs-seo-search-appearance',
				'ogsSeoSearchAppearance',
				array(
					'siteName'        => (string) get_bloginfo( 'name' ),
					'siteDescription' => (string) get_bloginfo( 'description' ),
					'homeUrl'         => home_url( '/' ),
					'sampleTitle'     => __( 'Example Service Page', 'open-growth-seo' ),
					'sampleExcerpt'   => __( 'Short, useful summary aligned with user intent.', 'open-growth-seo' ),
					'searchQuery'     => __( 'example query', 'open-growth-seo' ),
					'enabledLabel'    => __( 'Enabled', 'open-growth-seo' ),
					'disabledLabel'   => __( 'Disabled', 'open-growth-seo' ),
					'previewEndpoint' => esc_url_raw( rest_url( 'ogs-seo/v1/search-appearance/preview' ) ),
					'nonce'           => wp_create_nonce( 'wp_rest' ),
					'loadingText'     => __( 'Updating preview...', 'open-growth-seo' ),
					'updatedText'     => __( 'Preview updated from live resolver.', 'open-growth-seo' ),
					'errorText'       => __( 'Live preview unavailable. Showing local fallback.', 'open-growth-seo' ),
				)
			);
		}
	}

	public function handle_save(): void {
		if ( empty( $_POST['ogs_seo_action'] ) || empty( $_POST['_wpnonce'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'ogs_seo_save_settings' ) ) {
			return;
		}
		$action = sanitize_key( (string) wp_unslash( $_POST['ogs_seo_action'] ) );
		if ( 'restore_robots_defaults' === $action ) {
			$defaults = Defaults::settings();
			$current  = Settings::get_all();
			$current['robots_mode']          = $defaults['robots_mode'];
			$current['robots_custom']        = $defaults['robots_custom'];
			$current['robots_expert_rules']  = $defaults['robots_expert_rules'];
			$current['robots_global_policy'] = $defaults['robots_global_policy'];
			$current['bots_gptbot']          = $defaults['bots_gptbot'];
			$current['bots_oai_searchbot']   = $defaults['bots_oai_searchbot'];
			Settings::update( $current );
			add_settings_error( 'ogs_seo', 'robots_restored', __( 'robots.txt defaults restored.', 'open-growth-seo' ), 'updated' );
			return;
		}

		if ( 'save_settings' === $action ) {
			$raw = isset( $_POST['ogs'] ) ? (array) wp_unslash( $_POST['ogs'] ) : array();
			$preset = isset( $_POST['ogs_schema_preset_apply'] ) ? sanitize_key( (string) wp_unslash( $_POST['ogs_schema_preset_apply'] ) ) : '';
			if ( '' !== $preset ) {
				$raw = $this->apply_schema_post_type_preset( $preset, $raw );
			}
			$previous_settings = Settings::get_all();
			$candidate = Settings::sanitize( array_replace( Settings::get_all(), $raw ) );
			$this->pending_settings_override = $candidate;
			if ( isset( $raw['robots_mode'] ) || isset( $raw['robots_custom'] ) || isset( $raw['robots_expert_rules'] ) ) {
				$validation = Robots::validate_for_save( $candidate, (bool) get_option( 'blog_public' ) );
				if ( ! empty( $validation['errors'] ) ) {
					foreach ( $validation['errors'] as $error ) {
						add_settings_error( 'ogs_seo', 'robots_error', $error, 'error' );
					}
					return;
				}
				if ( ! empty( $validation['warnings'] ) ) {
					foreach ( $validation['warnings'] as $warning ) {
						add_settings_error( 'ogs_seo', 'robots_warning', $warning, 'warning' );
					}
				}
				update_option( 'ogs_seo_robots_last_saved', time(), false );
			}
			foreach ( $this->snippet_validation_messages( $candidate, $raw ) as $message ) {
				add_settings_error( 'ogs_seo', 'snippet_warning', $message, 'warning' );
			}
			foreach ( $this->hreflang_validation_messages( $candidate ) as $message ) {
				add_settings_error( 'ogs_seo', 'hreflang_warning', $message, 'warning' );
			}
			foreach ( $this->normalization_messages( $raw, $candidate ) as $message ) {
				add_settings_error( 'ogs_seo', 'normalization_warning', $message, 'warning' );
			}
			foreach ( $this->local_location_validation_messages( $candidate ) as $validation ) {
				if ( ! is_array( $validation ) ) {
					continue;
				}
				$message = isset( $validation['message'] ) ? (string) $validation['message'] : '';
				if ( '' === trim( $message ) ) {
					continue;
				}
				$type = isset( $validation['type'] ) && 'error' === $validation['type'] ? 'error' : 'warning';
				add_settings_error( 'ogs_seo', 'local_location_validation', $message, $type );
			}
			foreach ( $this->woocommerce_archive_validation_messages( $candidate ) as $message ) {
				add_settings_error( 'ogs_seo', 'woo_archive_validation', $message, 'warning' );
			}
			Settings::update( $candidate );
			$previous_key = isset( $previous_settings['indexnow_key'] ) ? (string) $previous_settings['indexnow_key'] : '';
			$current_key  = isset( $candidate['indexnow_key'] ) ? (string) $candidate['indexnow_key'] : '';
			if ( $previous_key !== $current_key ) {
				delete_option( 'ogs_seo_indexnow_key_verified' );
			}
			if ( '' !== $preset ) {
				$preset_record = $this->schema_preset_records();
				$preset_label  = isset( $preset_record[ $preset ]['label'] ) ? (string) $preset_record[ $preset ]['label'] : $preset;
				add_settings_error( 'ogs_seo', 'schema_preset_applied', sprintf( __( 'Schema preset applied: %s', 'open-growth-seo' ), $preset_label ), 'updated' );
			}
			add_settings_error( 'ogs_seo', 'saved', __( 'Settings saved.', 'open-growth-seo' ), 'updated' );
		}
	}

	public function run_audit_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to run audits.', 'open-growth-seo' ) );
		}
		check_admin_referer( 'ogs_seo_run_audit' );
		( new AuditManager() )->run_full();
		wp_safe_redirect( admin_url( 'admin.php?page=ogs-seo-dashboard&ogs_audit=ran' ) );
		exit;
	}

	public function ignore_issue_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage audit issues.', 'open-growth-seo' ) );
		}
		check_admin_referer( 'ogs_seo_issue_action' );
		$issue_id = isset( $_POST['issue_id'] ) ? sanitize_key( (string) wp_unslash( $_POST['issue_id'] ) ) : '';
		$reason   = isset( $_POST['reason'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['reason'] ) ) : '';
		$ok = ( new AuditManager() )->ignore_issue( $issue_id, $reason );
		add_settings_error( 'ogs_seo', 'audit_ignore', $ok ? __( 'Issue ignored.', 'open-growth-seo' ) : __( 'Could not ignore issue. Add a reason.', 'open-growth-seo' ), $ok ? 'updated' : 'error' );
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( admin_url( 'admin.php?page=ogs-seo-audits' ) );
		exit;
	}

	public function unignore_issue_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage audit issues.', 'open-growth-seo' ) );
		}
		check_admin_referer( 'ogs_seo_issue_action' );
		$issue_id = isset( $_GET['issue_id'] ) ? sanitize_key( (string) wp_unslash( $_GET['issue_id'] ) ) : '';
		$ok = ( new AuditManager() )->unignore_issue( $issue_id );
		add_settings_error( 'ogs_seo', 'audit_unignore', $ok ? __( 'Issue restored to active list.', 'open-growth-seo' ) : __( 'Issue could not be restored.', 'open-growth-seo' ), $ok ? 'updated' : 'error' );
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( admin_url( 'admin.php?page=ogs-seo-audits' ) );
		exit;
	}

	public function fix_issue_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to fix audit issues.', 'open-growth-seo' ) );
		}
		check_admin_referer( 'ogs_seo_issue_action' );
		$issue_id = isset( $_GET['issue_id'] ) ? sanitize_key( (string) wp_unslash( $_GET['issue_id'] ) ) : '';
		$issue    = $this->issue_by_id( $issue_id );
		if ( empty( $issue ) ) {
			add_settings_error( 'ogs_seo', 'audit_fix_missing', __( 'The selected issue is no longer active. Run the audit again if needed.', 'open-growth-seo' ), 'warning' );
		} else {
			$result = $this->apply_issue_safe_fix( $issue );
			add_settings_error(
				'ogs_seo',
				'audit_fix',
				(string) ( $result['message'] ?? __( 'No safe fix is available for this issue.', 'open-growth-seo' ) ),
				! empty( $result['ok'] ) ? 'updated' : 'warning'
			);
		}
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( admin_url( 'admin.php?page=ogs-seo-audits' ) );
		exit;
	}

	public function redirect_add_action(): void {
		$this->guard_redirect_action();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce and capability verified in guard_redirect_action().
		$payload = array(
			'source_path'     => isset( $_POST['source_path'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['source_path'] ) ) : '',
			'destination_url' => isset( $_POST['destination_url'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['destination_url'] ) ) : '',
			'match_type'      => isset( $_POST['match_type'] ) ? sanitize_key( (string) wp_unslash( $_POST['match_type'] ) ) : 'exact',
			'status_code'     => isset( $_POST['status_code'] ) ? absint( (string) wp_unslash( $_POST['status_code'] ) ) : 301,
			'note'            => isset( $_POST['note'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['note'] ) ) : '',
			'enabled'         => 1,
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$result = Redirects::add_rule( $payload );
		add_settings_error( 'ogs_seo', 'redirect_add', (string) ( $result['message'] ?? __( 'Redirect rule could not be saved.', 'open-growth-seo' ) ), ! empty( $result['ok'] ) ? 'updated' : 'error' );
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( admin_url( 'admin.php?page=ogs-seo-redirects' ) );
		exit;
	}

	public function redirect_delete_action(): void {
		$this->guard_redirect_action();
		$rule_id = isset( $_GET['rule_id'] ) ? sanitize_key( (string) wp_unslash( $_GET['rule_id'] ) ) : '';
		$result  = Redirects::delete_rule( $rule_id );
		add_settings_error( 'ogs_seo', 'redirect_delete', (string) ( $result['message'] ?? __( 'Redirect rule could not be removed.', 'open-growth-seo' ) ), ! empty( $result['ok'] ) ? 'updated' : 'error' );
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( admin_url( 'admin.php?page=ogs-seo-redirects' ) );
		exit;
	}

	public function redirect_toggle_action(): void {
		$this->guard_redirect_action();
		$rule_id  = isset( $_GET['rule_id'] ) ? sanitize_key( (string) wp_unslash( $_GET['rule_id'] ) ) : '';
		$enabled  = isset( $_GET['enabled'] ) && '1' === (string) wp_unslash( $_GET['enabled'] );
		$result   = Redirects::toggle_rule( $rule_id, $enabled );
		add_settings_error( 'ogs_seo', 'redirect_toggle', (string) ( $result['message'] ?? __( 'Redirect rule state could not be updated.', 'open-growth-seo' ) ), ! empty( $result['ok'] ) ? 'updated' : 'error' );
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( admin_url( 'admin.php?page=ogs-seo-redirects' ) );
		exit;
	}

	public function redirect_clear_404_log_action(): void {
		$this->guard_redirect_action();
		$result = Redirects::clear_404_log();
		add_settings_error( 'ogs_seo', 'redirect_clear_404', (string) ( $result['message'] ?? __( '404 log could not be cleared.', 'open-growth-seo' ) ), ! empty( $result['ok'] ) ? 'updated' : 'error' );
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( admin_url( 'admin.php?page=ogs-seo-redirects' ) );
		exit;
	}

	public function redirect_import_dry_run_action(): void {
		$this->guard_redirect_action();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce and capability verified in guard_redirect_action().
		$providers = isset( $_POST['providers'] ) && is_array( $_POST['providers'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['providers'] ) ) : array();
		$mode      = isset( $_POST['import_mode'] ) ? sanitize_key( (string) wp_unslash( $_POST['import_mode'] ) ) : 'merge';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( empty( $providers ) ) {
			add_settings_error( 'ogs_seo', 'redirect_import_dry_run_missing', __( 'Select at least one provider for redirect import dry run.', 'open-growth-seo' ), 'error' );
			set_transient( 'settings_errors', get_settings_errors(), 30 );
			wp_safe_redirect( admin_url( 'admin.php?page=ogs-seo-redirects' ) );
			exit;
		}
		$merge     = 'replace' !== $mode;
		$report    = RedirectImporter::dry_run( $providers, $merge );
		$total     = (int) ( $report['summary']['total'] ?? 0 );
		$ready     = (int) ( $report['summary']['counts']['ready'] ?? 0 );
		$conflicts = (int) ( $report['summary']['counts']['conflict_existing'] ?? 0 );

		add_settings_error(
			'ogs_seo',
			'redirect_import_dry_run',
			sprintf(
				/* translators: 1: total rows, 2: ready rows, 3: conflicts */
				__( 'Redirect dry run complete. Total: %1$d. Ready: %2$d. Conflicts: %3$d.', 'open-growth-seo' ),
				$total,
				$ready,
				$conflicts
			),
			'updated'
		);
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( admin_url( 'admin.php?page=ogs-seo-redirects' ) );
		exit;
	}

	public function redirect_import_run_action(): void {
		$this->guard_redirect_action();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce and capability verified in guard_redirect_action().
		$providers = isset( $_POST['providers'] ) && is_array( $_POST['providers'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['providers'] ) ) : array();
		$mode      = isset( $_POST['import_mode'] ) ? sanitize_key( (string) wp_unslash( $_POST['import_mode'] ) ) : 'merge';
		$merge     = 'replace' !== $mode;
		$confirm   = ! empty( $_POST['confirm_import'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( empty( $providers ) ) {
			add_settings_error( 'ogs_seo', 'redirect_import_run_missing', __( 'Select at least one provider before running redirect import.', 'open-growth-seo' ), 'error' );
			set_transient( 'settings_errors', get_settings_errors(), 30 );
			wp_safe_redirect( admin_url( 'admin.php?page=ogs-seo-redirects' ) );
			exit;
		}
		$report    = RedirectImporter::run_import( $providers, $merge, $confirm );

		$ok      = ! empty( $report['ok'] );
		$message = (string) ( $report['message'] ?? '' );
		if ( '' === $message ) {
			$message = sprintf(
				/* translators: 1: imported rows, 2: failed rows */
				__( 'Redirect import finished. Imported: %1$d. Failed: %2$d.', 'open-growth-seo' ),
				(int) ( $report['imported'] ?? 0 ),
				(int) ( $report['failed'] ?? 0 )
			);
		}

		add_settings_error( 'ogs_seo', 'redirect_import_run', $message, $ok ? 'updated' : 'error' );
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( admin_url( 'admin.php?page=ogs-seo-redirects' ) );
		exit;
	}

	public function redirect_import_rollback_action(): void {
		$this->guard_redirect_action();
		$report = RedirectImporter::rollback_last_import();
		add_settings_error(
			'ogs_seo',
			'redirect_import_rollback',
			(string) ( $report['message'] ?? ( ! empty( $report['ok'] ) ? __( 'Redirect rollback completed.', 'open-growth-seo' ) : __( 'Redirect rollback could not be completed.', 'open-growth-seo' ) ) ),
			! empty( $report['ok'] ) ? 'updated' : 'error'
		);
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( admin_url( 'admin.php?page=ogs-seo-redirects' ) );
		exit;
	}

	public function import_dry_run_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to run imports.', 'open-growth-seo' ) );
		}
		check_admin_referer( 'ogs_seo_import_action' );
		$slugs = isset( $_POST['slugs'] ) && is_array( $_POST['slugs'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['slugs'] ) ) : array();
		$report = ( new Importer() )->dry_run( $slugs );
		$count = (int) ( $report['settings_preview']['count'] ?? 0 ) + (int) ( $report['meta_preview']['total_source_rows'] ?? 0 );
		add_settings_error( 'ogs_seo', 'import_dry_run', sprintf( __( 'Import dry run finished. Potential changes/signals: %d.', 'open-growth-seo' ), $count ), 'updated' );
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( admin_url( 'admin.php?page=ogs-seo-tools' ) );
		exit;
	}

	public function import_run_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to run imports.', 'open-growth-seo' ) );
		}
		check_admin_referer( 'ogs_seo_import_action' );
		$slugs = isset( $_POST['slugs'] ) && is_array( $_POST['slugs'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['slugs'] ) ) : array();
		$overwrite = ! empty( $_POST['overwrite'] );
		$report = ( new Importer() )->run_import(
			array(
				'slugs'            => $slugs,
				'overwrite'        => $overwrite,
				'include_settings' => true,
				'include_meta'     => true,
			)
		);
		$meta_changed = (int) ( $report['changes']['meta_changed'] ?? 0 );
		$settings_changed = count( (array) ( $report['changes']['settings_changed'] ?? array() ) );
		add_settings_error( 'ogs_seo', 'import_run', sprintf( __( 'Import completed. Settings changed: %1$d. Meta rows updated: %2$d.', 'open-growth-seo' ), $settings_changed, $meta_changed ), 'updated' );
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( admin_url( 'admin.php?page=ogs-seo-tools' ) );
		exit;
	}

	public function import_rollback_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to run imports.', 'open-growth-seo' ) );
		}
		check_admin_referer( 'ogs_seo_import_action' );
		$report = ( new Importer() )->rollback_last_import();
		if ( ! empty( $report['ok'] ) ) {
			add_settings_error( 'ogs_seo', 'import_rollback', __( 'Rollback completed.', 'open-growth-seo' ), 'updated' );
		} else {
			add_settings_error( 'ogs_seo', 'import_rollback', __( 'Rollback could not be completed.', 'open-growth-seo' ), 'error' );
		}
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( admin_url( 'admin.php?page=ogs-seo-tools' ) );
		exit;
	}

	public function rerun_installation_diagnostics_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to run installation diagnostics.', 'open-growth-seo' ) );
		}
		check_admin_referer( 'ogs_seo_installation_action' );
		$state = ( new InstallationManager() )->rerun( 'manual_rerun', true );
		$summary = ( new InstallationManager() )->summary();
		$status  = isset( $summary['status'] ) ? str_replace( '_', ' ', (string) $summary['status'] ) : __( 'updated', 'open-growth-seo' );
		$applied = isset( $summary['applied'] ) ? (int) $summary['applied'] : 0;
		add_settings_error(
			'ogs_seo',
			'installation_rerun',
			sprintf(
				/* translators: 1: status label, 2: applied settings count. */
				__( 'Installation diagnostics re-ran successfully. Status: %1$s. Auto-applied settings: %2$d.', 'open-growth-seo' ),
				sanitize_text_field( ucfirst( $status ) ),
				$applied
			),
			'updated'
		);
		if ( empty( $state['schema_version'] ) ) {
			add_settings_error( 'ogs_seo', 'installation_rerun_empty', __( 'Installation diagnostics did not return a valid state.', 'open-growth-seo' ), 'error' );
		}
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( admin_url( 'admin.php?page=ogs-seo-tools' ) );
		exit;
	}

	public function rebuild_installation_state_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to rebuild installation state.', 'open-growth-seo' ) );
		}
		check_admin_referer( 'ogs_seo_installation_action' );
		$summary = ( new InstallationManager() )->rebuild_state( 'admin_rebuild', false );
		$repairs = isset( $summary['repairs'] ) ? (int) $summary['repairs'] : 0;
		$status  = isset( $summary['status'] ) ? str_replace( '_', ' ', (string) $summary['status'] ) : __( 'rebuilt', 'open-growth-seo' );
		add_settings_error(
			'ogs_seo',
			'installation_rebuild',
			sprintf(
				/* translators: 1: installation status, 2: repair actions count. */
				__( 'Installation state rebuilt successfully. Status: %1$s. Repairs recorded: %2$d.', 'open-growth-seo' ),
				sanitize_text_field( ucfirst( $status ) ),
				$repairs
			),
			'updated'
		);
		if ( ! empty( $summary['wizard_recommended'] ) ) {
			add_settings_error(
				'ogs_seo',
				'installation_rebuild_wizard',
				__( 'Setup review is still recommended. Open the Setup Wizard to finish the first-run configuration.', 'open-growth-seo' ),
				'warning'
			);
		}
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( admin_url( 'admin.php?page=ogs-seo-tools' ) );
		exit;
	}

	public function dev_export_action(): void {
		$this->guard_dev_tools_action();
		$payload = DeveloperTools::export_payload();
		$filename = 'open-growth-seo-settings-' . gmdate( 'Ymd-His' ) . '.json';
		nocache_headers();
		header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}

	public function dev_import_action(): void {
		$this->guard_dev_tools_action();
		check_admin_referer( 'ogs_seo_dev_tools_action' );
		$raw_payload = isset( $_POST['ogs_dev_payload'] ) ? (string) wp_unslash( $_POST['ogs_dev_payload'] ) : '';
		$merge       = isset( $_POST['ogs_dev_merge'] ) ? ! empty( $_POST['ogs_dev_merge'] ) : true;
		if ( '' === trim( $raw_payload ) ) {
			add_settings_error( 'ogs_seo', 'dev_import', __( 'Import payload is empty.', 'open-growth-seo' ), 'error' );
			set_transient( 'settings_errors', get_settings_errors(), 30 );
			wp_safe_redirect( admin_url( 'admin.php?page=ogs-seo-tools' ) );
			exit;
		}
		if ( strlen( $raw_payload ) > DeveloperTools::max_import_bytes() ) {
			add_settings_error( 'ogs_seo', 'dev_import', sprintf( __( 'Import payload is too large. Max allowed size is %d KB.', 'open-growth-seo' ), (int) ( DeveloperTools::max_import_bytes() / 1024 ) ), 'error' );
			set_transient( 'settings_errors', get_settings_errors(), 30 );
			wp_safe_redirect( admin_url( 'admin.php?page=ogs-seo-tools' ) );
			exit;
		}
		$decoded = json_decode( $raw_payload, true );
		if ( ! is_array( $decoded ) ) {
			add_settings_error( 'ogs_seo', 'dev_import', __( 'Import payload is not valid JSON.', 'open-growth-seo' ), 'error' );
			set_transient( 'settings_errors', get_settings_errors(), 30 );
			wp_safe_redirect( admin_url( 'admin.php?page=ogs-seo-tools' ) );
			exit;
		}
		$result = DeveloperTools::import_payload( $decoded, $merge );
		if ( ! empty( $result['ok'] ) ) {
			add_settings_error( 'ogs_seo', 'dev_import', sprintf( __( 'Import completed. Changed keys: %d.', 'open-growth-seo' ), (int) ( $result['changed'] ?? 0 ) ), 'updated' );
		} else {
			add_settings_error( 'ogs_seo', 'dev_import', (string) ( $result['message'] ?? __( 'Import failed.', 'open-growth-seo' ) ), 'error' );
		}
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( admin_url( 'admin.php?page=ogs-seo-tools' ) );
		exit;
	}

	public function dev_reset_action(): void {
		$this->guard_dev_tools_action();
		check_admin_referer( 'ogs_seo_dev_tools_action' );
		$preserve = isset( $_POST['ogs_dev_preserve_keep_data'] ) ? ! empty( $_POST['ogs_dev_preserve_keep_data'] ) : true;
		DeveloperTools::reset_settings( $preserve );
		add_settings_error( 'ogs_seo', 'dev_reset', __( 'Plugin settings were reset to defaults.', 'open-growth-seo' ), 'updated' );
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( admin_url( 'admin.php?page=ogs-seo-tools' ) );
		exit;
	}

	public function dev_clear_logs_action(): void {
		$this->guard_dev_tools_action();
		DeveloperTools::clear_logs();
		add_settings_error( 'ogs_seo', 'dev_logs', __( 'Developer debug logs cleared.', 'open-growth-seo' ), 'updated' );
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( admin_url( 'admin.php?page=ogs-seo-tools' ) );
		exit;
	}

	public function schema_mapping_export_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to export schema mappings.', 'open-growth-seo' ) );
		}
		check_admin_referer( 'ogs_seo_schema_mapping_export' );

		$payload  = $this->schema_mapping_export_payload( Settings::get_all() );
		$filename = 'open-growth-seo-schema-mappings-' . gmdate( 'Ymd-His' ) . '.json';

		nocache_headers();
		header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	public function schema_mapping_import_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to import schema mappings.', 'open-growth-seo' ) );
		}
		check_admin_referer( 'ogs_seo_schema_mapping_import' );

		$raw_payload = '';
		if ( ! empty( $_POST['schema_mapping_payload'] ) ) {
			$raw_payload = (string) wp_unslash( $_POST['schema_mapping_payload'] );
		} elseif ( ! empty( $_FILES['schema_mapping_file']['tmp_name'] ) ) {
			$file = $_FILES['schema_mapping_file'];
			if ( isset( $file['error'] ) && UPLOAD_ERR_OK === (int) $file['error'] ) {
				$contents = file_get_contents( (string) $file['tmp_name'] );
				if ( false !== $contents ) {
					$raw_payload = $contents;
				}
			}
		}

		$raw_payload = trim( $raw_payload );
		if ( '' === $raw_payload ) {
			add_settings_error( 'ogs_seo', 'schema_mapping_import', __( 'Paste a schema mapping payload or choose a JSON file to import.', 'open-growth-seo' ), 'error' );
			set_transient( 'settings_errors', get_settings_errors(), 30 );
			wp_safe_redirect( admin_url( 'admin.php?page=ogs-seo-schema' ) );
			exit;
		}

		$decoded = json_decode( $raw_payload, true );
		if ( ! is_array( $decoded ) ) {
			add_settings_error( 'ogs_seo', 'schema_mapping_import', __( 'Schema mapping import payload is not valid JSON.', 'open-growth-seo' ), 'error' );
			set_transient( 'settings_errors', get_settings_errors(), 30 );
			wp_safe_redirect( admin_url( 'admin.php?page=ogs-seo-schema' ) );
			exit;
		}

		$incoming = $this->schema_mapping_payload_defaults( $decoded );
		if ( empty( $incoming ) ) {
			add_settings_error( 'ogs_seo', 'schema_mapping_import', __( 'Schema mapping import payload did not include any valid CPT defaults.', 'open-growth-seo' ), 'error' );
			set_transient( 'settings_errors', get_settings_errors(), 30 );
			wp_safe_redirect( admin_url( 'admin.php?page=ogs-seo-schema' ) );
			exit;
		}

		$mode = isset( $_POST['schema_mapping_import_mode'] ) ? sanitize_key( (string) wp_unslash( $_POST['schema_mapping_import_mode'] ) ) : 'merge';
		$current = Settings::get_all();
		$existing = isset( $current['schema_post_type_defaults'] ) && is_array( $current['schema_post_type_defaults'] ) ? $current['schema_post_type_defaults'] : array();
		$current['schema_post_type_defaults'] = 'replace' === $mode ? $incoming : array_replace( $existing, $incoming );
		Settings::update( Settings::sanitize( $current ) );

		add_settings_error(
			'ogs_seo',
			'schema_mapping_import',
			sprintf( __( 'Schema CPT mappings imported: %d entries applied.', 'open-growth-seo' ), count( $incoming ) ),
			'updated'
		);
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( admin_url( 'admin.php?page=ogs-seo-schema' ) );
		exit;
	}

	public function render_dashboard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$this->rehydrate_settings_errors();

		$data     = $this->dashboard_data();
		$settings = $data['settings'];
		$installation = isset( $data['installation'] ) && is_array( $data['installation'] ) ? $data['installation'] : array();
		$simple   = ExperienceMode::is_simple( $settings );
		$show_advanced = ExperienceMode::is_advanced_revealed();
		?>
		<div class="wrap ogs-seo-wrap">
			<?php $this->render_page_intro( 'ogs-seo-dashboard', __( 'Open Growth SEO Dashboard', 'open-growth-seo' ), __( 'Technical SEO health at a glance with prioritized next actions.', 'open-growth-seo' ), $simple, $show_advanced, $settings ); ?>
			<?php $this->render_admin_section_nav( 'ogs-seo-dashboard', $simple, $show_advanced ); ?>
			<?php $this->render_workflow_continuity( 'ogs-seo-dashboard', $settings ); ?>
			<?php settings_errors( 'ogs_seo' ); ?>
			<?php if ( isset( $_GET['ogs_audit'] ) && 'ran' === sanitize_key( wp_unslash( $_GET['ogs_audit'] ) ) ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Audit completed and dashboard refreshed.', 'open-growth-seo' ); ?></p></div>
			<?php endif; ?>
			<?php if ( $simple && ! $show_advanced ) : ?>
				<div class="notice notice-info inline">
					<p>
						<?php esc_html_e( 'Simple mode hides advanced sections such as Schema, Bots & Crawlers, Integrations, and Tools.', 'open-growth-seo' ); ?>
						<a class="button button-secondary" href="<?php echo esc_url( add_query_arg( 'ogs_show_advanced', '1', 'admin.php?page=ogs-seo-dashboard' ) ); ?>"><?php esc_html_e( 'Show advanced sections', 'open-growth-seo' ); ?></a>
					</p>
				</div>
			<?php endif; ?>
			<?php if ( ! empty( $installation['status'] ) && 'ready' !== (string) $installation['status'] ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<?php echo esc_html( sprintf( __( 'Installation profile status: %s.', 'open-growth-seo' ), $this->installation_status_label( (string) $installation['status'] ) ) ); ?>
						<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=ogs-seo-tools' ) ); ?>"><?php esc_html_e( 'Review installation profile', 'open-growth-seo' ); ?></a>
					</p>
				</div>
			<?php endif; ?>
			<?php if ( ! empty( $installation['wizard_recommended'] ) ) : ?>
				<div class="notice notice-warning inline ogs-installation-notice">
					<p><strong><?php esc_html_e( 'Setup review recommended.', 'open-growth-seo' ); ?></strong></p>
					<p>
						<?php esc_html_e( 'The plugin is running, but the initial SEO setup is still incomplete or needs review. Finish the wizard to confirm safe defaults before moving on to advanced controls.', 'open-growth-seo' ); ?>
						<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=ogs-seo-setup' ) ); ?>"><?php esc_html_e( 'Open Setup Wizard', 'open-growth-seo' ); ?></a>
						<a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ogs_seo_rebuild_installation_state' ), 'ogs_seo_installation_action' ) ); ?>" onclick="return window.confirm('<?php echo esc_js( __( 'Rebuild the stored installation state from current diagnostics and defaults?', 'open-growth-seo' ) ); ?>');"><?php esc_html_e( 'Rebuild installation state', 'open-growth-seo' ); ?></a>
					</p>
					<?php if ( ! empty( $installation['repair_runs'] ) ) : ?>
						<p class="description"><?php echo esc_html( sprintf( __( 'Recent recovery activity detected: %d repair runs recorded in installation history.', 'open-growth-seo' ), (int) $installation['repair_runs'] ) ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<section aria-labelledby="ogs-overview-title" class="ogs-section">
				<h2 id="ogs-overview-title"><?php esc_html_e( 'Overview', 'open-growth-seo' ); ?></h2>
				<div class="ogs-grid ogs-grid-overview">
					<?php foreach ( $data['overview_cards'] as $card ) : ?>
						<div class="ogs-card" role="group" aria-label="<?php echo esc_attr( $card['label'] ); ?>">
							<h3><?php echo esc_html( $card['label'] ); ?></h3>
							<p class="ogs-status ogs-status-<?php echo esc_attr( $card['status'] ); ?>"><?php echo esc_html( $card['value'] ); ?></p>
							<p class="description"><?php echo esc_html( $card['note'] ); ?></p>
						</div>
					<?php endforeach; ?>
				</div>
			</section>

			<section aria-labelledby="ogs-issues-title" class="ogs-section">
				<h2 id="ogs-issues-title"><?php esc_html_e( 'Issues and Actions', 'open-growth-seo' ); ?></h2>
				<div class="ogs-grid ogs-grid-2">
					<div class="ogs-card">
						<h3><?php esc_html_e( 'Issue Summary', 'open-growth-seo' ); ?></h3>
						<ul class="ogs-inline-stats" aria-label="<?php esc_attr_e( 'Issue counts by severity', 'open-growth-seo' ); ?>">
							<li><strong><?php echo esc_html( (string) $data['issues_count']['critical'] ); ?></strong> <?php esc_html_e( 'Critical', 'open-growth-seo' ); ?></li>
							<li><strong><?php echo esc_html( (string) $data['issues_count']['important'] ); ?></strong> <?php esc_html_e( 'Important', 'open-growth-seo' ); ?></li>
							<li><strong><?php echo esc_html( (string) $data['issues_count']['minor'] ); ?></strong> <?php esc_html_e( 'Minor', 'open-growth-seo' ); ?></li>
						</ul>
						<?php if ( empty( $data['top_issues'] ) ) : ?>
							<p class="ogs-empty"><?php esc_html_e( 'No issues found yet. Run an audit to populate technical findings.', 'open-growth-seo' ); ?></p>
						<?php else : ?>
							<ol>
								<?php foreach ( $data['top_issues'] as $issue ) : ?>
									<li><strong><?php echo esc_html( $issue['title'] ); ?></strong> <span class="ogs-pill ogs-pill-<?php echo esc_attr( $issue['severity'] ); ?>"><?php echo esc_html( ucfirst( $issue['severity'] ) ); ?></span></li>
								<?php endforeach; ?>
							</ol>
						<?php endif; ?>
					</div>
					<div class="ogs-card">
						<h3><?php esc_html_e( 'Prioritized Recommendations', 'open-growth-seo' ); ?></h3>
						<?php if ( empty( $data['recommendations'] ) ) : ?>
							<p class="ogs-empty"><?php esc_html_e( 'No immediate recommendations. Keep monitoring weekly.', 'open-growth-seo' ); ?></p>
						<?php else : ?>
							<ol>
								<?php foreach ( $data['recommendations'] as $recommendation ) : ?>
									<li><?php echo esc_html( $recommendation ); ?></li>
								<?php endforeach; ?>
							</ol>
						<?php endif; ?>
					</div>
				</div>
			</section>

			<?php if ( ! $simple || $show_advanced ) : ?>
			<section aria-labelledby="ogs-activity-title" class="ogs-section">
				<h2 id="ogs-activity-title"><?php esc_html_e( 'Recent Activity', 'open-growth-seo' ); ?></h2>
				<div class="ogs-card">
					<?php if ( empty( $data['activity'] ) ) : ?>
						<p class="ogs-empty"><?php esc_html_e( 'No recent activity available yet.', 'open-growth-seo' ); ?></p>
					<?php else : ?>
						<ul>
							<?php foreach ( $data['activity'] as $item ) : ?>
								<li><?php echo esc_html( $item ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
			</section>
			<?php endif; ?>

			<section aria-labelledby="ogs-actions-title" class="ogs-section">
				<h2 id="ogs-actions-title"><?php esc_html_e( 'Quick Actions', 'open-growth-seo' ); ?></h2>
				<div class="ogs-actions">
					<?php foreach ( $this->quick_actions() as $action ) : ?>
						<a class="button <?php echo ! empty( $action['primary'] ) ? 'button-primary' : ''; ?>" href="<?php echo esc_url( $action['url'] ); ?>"><?php echo esc_html( $action['label'] ); ?></a>
					<?php endforeach; ?>
				</div>
			</section>

			<section aria-labelledby="ogs-live-title" class="ogs-section">
				<h2 id="ogs-live-title"><?php esc_html_e( 'Live Checks', 'open-growth-seo' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Runtime checks validate sitemap, audit, and integration endpoints with current data.', 'open-growth-seo' ); ?></p>
				<div class="ogs-card" data-ogs-live-status aria-live="polite" aria-busy="true" data-loading="<?php esc_attr_e( 'Loading live status checks...', 'open-growth-seo' ); ?>">
					<p class="description"><?php esc_html_e( 'Loading live status checks...', 'open-growth-seo' ); ?></p>
				</div>
			</section>
		</div>
		<?php
	}

	private function dashboard_data(): array {
		$raw_settings = get_option( 'ogs_seo_settings', array() );
		$settings     = Settings::get_all();
		$installation_manager = new InstallationManager();
		$installation_state   = $installation_manager->get_state();
		$installation_summary = $installation_manager->summary();
		$issues       = ( new AuditManager() )->get_latest_issues();
		$issues       = array_values( array_filter( $issues, 'is_array' ) );
		if ( ! is_array( $raw_settings ) ) {
			$issues[] = array(
				'severity'       => 'important',
				'title'          => __( 'Plugin settings were restored from defaults', 'open-growth-seo' ),
				'recommendation' => __( 'Review Open Growth SEO settings and save them again to persist a clean configuration.', 'open-growth-seo' ),
			);
		}
		usort( $issues, array( $this, 'sort_issues' ) );
		$audit_last_run = (int) get_option( 'ogs_seo_audit_last_run', 0 );
		$has_audit_run  = $audit_last_run > 0;
		$audit_recent   = $has_audit_run && ( ( time() - $audit_last_run ) <= ( 7 * DAY_IN_SECONDS ) );

		$issue_count = array(
			'critical'  => $this->count_issues_by_severity( $issues, 'critical' ),
			'important' => $this->count_issues_by_severity( $issues, 'important' ),
			'minor'     => $this->count_issues_by_severity( $issues, 'minor' ),
		);

		$indexable            = (bool) get_option( 'blog_public' );
		$sitemaps_enabled     = (bool) (int) $settings['sitemap_enabled'] && ! empty( $settings['sitemap_post_types'] );
		$schema_keys          = array(
			'schema_organization_enabled',
			'schema_local_business_enabled',
			'schema_website_enabled',
			'schema_webpage_enabled',
			'schema_breadcrumb_enabled',
			'schema_article_enabled',
			'schema_service_enabled',
			'schema_offer_catalog_enabled',
			'schema_faq_enabled',
			'schema_profile_enabled',
			'schema_about_page_enabled',
			'schema_contact_page_enabled',
			'schema_collection_page_enabled',
			'schema_discussion_enabled',
			'schema_qapage_enabled',
			'schema_video_enabled',
			'schema_event_enabled',
			'schema_event_series_enabled',
			'schema_jobposting_enabled',
			'schema_recipe_enabled',
			'schema_software_enabled',
			'schema_webapi_enabled',
			'schema_project_enabled',
			'schema_review_enabled',
			'schema_guide_enabled',
			'schema_tech_article_enabled',
			'schema_dataset_enabled',
			'schema_defined_term_enabled',
			'schema_defined_term_set_enabled',
			'schema_paywall_enabled',
		);
		$schema_enabled_count = 0;
		foreach ( $schema_keys as $schema_key ) {
			if ( ! empty( $settings[ $schema_key ] ) ) {
				++$schema_enabled_count;
			}
		}
		$schema_enabled       = $schema_enabled_count > 0;
		$bots_policy_valid    = in_array( (string) $settings['bots_gptbot'], array( 'allow', 'disallow' ), true ) && in_array( (string) $settings['bots_oai_searchbot'], array( 'allow', 'disallow' ), true );
		$bots_open_any        = 'allow' === (string) $settings['bots_gptbot'] || 'allow' === (string) $settings['bots_oai_searchbot'] || 'allow' === (string) $settings['robots_global_policy'];
		$snippet_conflict     = ! empty( $settings['default_nosnippet'] ) && '' !== (string) $settings['default_max_snippet'];
		$preview_customized   = ! empty( $settings['default_nosnippet'] ) || ! empty( $settings['default_noarchive'] ) || ! empty( $settings['default_notranslate'] ) || '' !== (string) $settings['default_max_snippet'] || 'large' !== (string) $settings['default_max_image_preview'] || '' !== (string) $settings['default_max_video_preview'] || '' !== (string) $settings['default_unavailable_after'];
		$indexnow_enabled     = (bool) (int) $settings['indexnow_enabled'];
		$indexnow_has_key     = ! empty( $settings['indexnow_key'] );
		$integration_payload  = IntegrationsManager::get_status_payload();
		$integration_summary  = isset( $integration_payload['summary'] ) && is_array( $integration_payload['summary'] ) ? $integration_payload['summary'] : array(
			'connected' => 0,
			'enabled'   => 0,
			'configured' => 0,
		);
		$enabled_integrations    = (int) ( $integration_summary['enabled'] ?? 0 ) + ( $indexnow_enabled ? 1 : 0 );
		$configured_integrations = (int) ( $integration_summary['configured'] ?? 0 ) + ( ( $indexnow_enabled && $indexnow_has_key ) ? 1 : 0 );
		$connected_integrations  = (int) ( $integration_summary['connected'] ?? 0 ) + ( ( $indexnow_enabled && $indexnow_has_key ) ? 1 : 0 );
		$aeo_issue_signals       = $this->has_issue_keywords( $issues, array( 'answer-first', 'answer first', 'intent coverage', 'non-text', 'extractability', 'answer paragraph' ) );
		$geo_issue_signals       = $this->has_issue_keywords( $issues, array( 'gptbot', 'oai-searchbot', 'discoverability', 'citability', 'semantic clarity', 'crawler', 'schema and visible text' ) );
		$sitemap_critical_issue  = $this->has_issue_keywords( $issues, array( 'sitemap' ), 'critical' );
		$sitemap_issue           = $this->has_issue_keywords( $issues, array( 'sitemap' ) );
		$schema_issue            = $this->has_issue_keywords( $issues, array( 'schema', 'structured data', 'json-ld' ) );

		$seo_value  = __( 'Healthy', 'open-growth-seo' );
		$seo_status = 'good';
		$seo_note   = __( 'Combines indexability, sitemap setup, and latest audit severity.', 'open-growth-seo' );
		if ( ! $indexable ) {
			$seo_value  = __( 'Blocked', 'open-growth-seo' );
			$seo_status = 'bad';
			$seo_note   = __( 'Site-level indexability is blocked in Reading settings.', 'open-growth-seo' );
		} elseif ( $issue_count['critical'] > 0 ) {
			$seo_value  = __( 'Critical issues', 'open-growth-seo' );
			$seo_status = 'bad';
		} elseif ( ! $sitemaps_enabled ) {
			$seo_value  = __( 'Needs setup', 'open-growth-seo' );
			$seo_status = 'warn';
		} elseif ( ! $has_audit_run ) {
			$seo_value  = __( 'Pending audit', 'open-growth-seo' );
			$seo_status = 'warn';
			$seo_note   = __( 'Run the first audit to validate real runtime SEO health.', 'open-growth-seo' );
		} elseif ( ! $audit_recent ) {
			$seo_value  = __( 'Audit outdated', 'open-growth-seo' );
			$seo_status = 'warn';
		} elseif ( $issue_count['important'] > 0 ) {
			$seo_value  = __( 'Needs attention', 'open-growth-seo' );
			$seo_status = 'warn';
		}

		$aeo_value  = __( 'Active', 'open-growth-seo' );
		$aeo_status = 'good';
		$aeo_note   = __( 'Based on answer-first and clarity findings from latest technical audit signals.', 'open-growth-seo' );
		if ( empty( $settings['aeo_enabled'] ) ) {
			$aeo_value  = __( 'Disabled', 'open-growth-seo' );
			$aeo_status = 'warn';
		} elseif ( ! $has_audit_run ) {
			$aeo_value  = __( 'Pending audit', 'open-growth-seo' );
			$aeo_status = 'warn';
		} elseif ( ! $audit_recent ) {
			$aeo_value  = __( 'Audit outdated', 'open-growth-seo' );
			$aeo_status = 'warn';
		} elseif ( $aeo_issue_signals ) {
			$aeo_value  = __( 'Needs attention', 'open-growth-seo' );
			$aeo_status = 'warn';
		}

		$geo_value  = __( 'Active', 'open-growth-seo' );
		$geo_status = 'good';
		$geo_note   = __( 'Combines bot policy exposure with GEO diagnostics from recent content checks.', 'open-growth-seo' );
		if ( empty( $settings['geo_enabled'] ) ) {
			$geo_value  = __( 'Disabled', 'open-growth-seo' );
			$geo_status = 'warn';
		} elseif ( ! $bots_open_any ) {
			$geo_value  = __( 'Crawl-restricted', 'open-growth-seo' );
			$geo_status = 'warn';
		} elseif ( ! $has_audit_run ) {
			$geo_value  = __( 'Pending audit', 'open-growth-seo' );
			$geo_status = 'warn';
		} elseif ( $geo_issue_signals ) {
			$geo_value  = __( 'Needs attention', 'open-growth-seo' );
			$geo_status = 'warn';
		}

		$sitemaps_value  = __( 'Enabled', 'open-growth-seo' );
		$sitemaps_status = 'good';
		if ( ! $sitemaps_enabled ) {
			$sitemaps_value  = __( 'Needs setup', 'open-growth-seo' );
			$sitemaps_status = 'warn';
		} elseif ( $sitemap_critical_issue ) {
			$sitemaps_value  = __( 'Critical issue', 'open-growth-seo' );
			$sitemaps_status = 'bad';
		} elseif ( $sitemap_issue ) {
			$sitemaps_value  = __( 'Needs attention', 'open-growth-seo' );
			$sitemaps_status = 'warn';
		}

		$schema_value  = sprintf( __( '%d enabled', 'open-growth-seo' ), $schema_enabled_count );
		$schema_status = 'good';
		if ( ! $schema_enabled ) {
			$schema_value  = __( 'Limited', 'open-growth-seo' );
			$schema_status = 'warn';
		} elseif ( $schema_issue ) {
			$schema_value  = __( 'Needs attention', 'open-growth-seo' );
			$schema_status = 'warn';
		}

		$bots_preview_value  = __( 'Configured', 'open-growth-seo' );
		$bots_preview_status = 'good';
		$bots_preview_note   = $preview_customized ? __( 'Crawler policy and snippet preview directives include custom controls.', 'open-growth-seo' ) : __( 'Crawler policy is valid with conservative default snippet directives.', 'open-growth-seo' );
		if ( ! $bots_policy_valid ) {
			$bots_preview_value  = __( 'Invalid policy', 'open-growth-seo' );
			$bots_preview_status = 'warn';
		} elseif ( $snippet_conflict ) {
			$bots_preview_value  = __( 'Conflict detected', 'open-growth-seo' );
			$bots_preview_status = 'warn';
		}

		$integrations_value  = __( 'Optional', 'open-growth-seo' );
		$integrations_status = 'warn';
		if ( $enabled_integrations > 0 ) {
			if ( $configured_integrations < $enabled_integrations ) {
				$integrations_value = __( 'Needs configuration', 'open-growth-seo' );
			} else {
				$integrations_value = sprintf( __( '%d connected', 'open-growth-seo' ), $connected_integrations );
			}
			if ( $configured_integrations >= $enabled_integrations && $connected_integrations >= $enabled_integrations ) {
				$integrations_status = 'good';
			}
		}
		$masters_plus_available = SeoMastersPlus::is_available( $settings );
		$masters_plus_active    = SeoMastersPlus::is_active( $settings );
		$masters_plus_value     = $masters_plus_active ? __( 'Active', 'open-growth-seo' ) : __( 'Disabled', 'open-growth-seo' );
		$masters_plus_status    = $masters_plus_active ? 'good' : ( $masters_plus_available ? 'warn' : 'good' );
		$masters_plus_note      = $masters_plus_active
			? __( 'Expert diagnostics are enabled, including trend deltas, link graph confidence, and advanced remediation controls.', 'open-growth-seo' )
			: __( 'Expert controls remain off in novice-safe workflows. Enable SEO MASTERS PLUS only when operational depth is required.', 'open-growth-seo' );

		$installation_status = isset( $installation_summary['status'] ) ? (string) $installation_summary['status'] : 'partial_configuration';
		$installation_value  = $this->installation_status_label( $installation_status );
		$installation_note   = sprintf(
			/* translators: 1: primary site type, 2: confidence percent. */
			__( 'Detected profile: %1$s (%2$d%% confidence).', 'open-growth-seo' ),
			ucfirst( str_replace( '_', ' ', (string) ( $installation_summary['primary'] ?? 'general' ) ) ),
			(int) ( $installation_summary['confidence'] ?? 0 )
		);
		if ( ! empty( $installation_summary['pending'] ) ) {
			$installation_note .= ' ' . sprintf(
				/* translators: %d: number of pending items. */
				__( '%d follow-up items still need review.', 'open-growth-seo' ),
				(int) $installation_summary['pending']
			);
		}

		$overview_cards = array(
			array(
				'label'  => __( 'Configuration', 'open-growth-seo' ),
				'value'  => $installation_value,
				'status' => $this->installation_status_class( $installation_status ),
				'note'   => $installation_note,
			),
			array(
				'label'  => __( 'SEO', 'open-growth-seo' ),
				'value'  => $seo_value,
				'status' => $seo_status,
				'note'   => $seo_note,
			),
			array(
				'label'  => __( 'AEO', 'open-growth-seo' ),
				'value'  => $aeo_value,
				'status' => $aeo_status,
				'note'   => $aeo_note,
			),
			array(
				'label'  => __( 'GEO', 'open-growth-seo' ),
				'value'  => $geo_value,
				'status' => $geo_status,
				'note'   => $geo_note,
			),
			array(
				'label'  => __( 'Indexability', 'open-growth-seo' ),
				'value'  => $indexable ? __( 'Indexable', 'open-growth-seo' ) : __( 'Blocked', 'open-growth-seo' ),
				'status' => $indexable ? 'good' : 'bad',
				'note'   => $indexable ? __( 'Search engines are allowed at site level.', 'open-growth-seo' ) : __( 'Reading setting discourages indexing.', 'open-growth-seo' ),
			),
			array(
				'label'  => __( 'Sitemaps', 'open-growth-seo' ),
				'value'  => $sitemaps_value,
				'status' => $sitemaps_status,
				'note'   => __( 'Tracks runtime sitemap eligibility and latest sitemap-related audit findings.', 'open-growth-seo' ),
			),
			array(
				'label'  => __( 'Schema', 'open-growth-seo' ),
				'value'  => $schema_value,
				'status' => $schema_status,
				'note'   => __( 'Counts enabled schema modules and flags schema-related audit issues.', 'open-growth-seo' ),
			),
			array(
				'label'  => __( 'Bots & Preview', 'open-growth-seo' ),
				'value'  => $bots_preview_value,
				'status' => $bots_preview_status,
				'note'   => $bots_preview_note,
			),
			array(
				'label'  => __( 'Integrations', 'open-growth-seo' ),
				'value'  => $integrations_value,
				'status' => $integrations_status,
				'note'   => __( 'Google Search Console, Bing, GA4 (optional), and IndexNow are decoupled from core SEO behavior.', 'open-growth-seo' ),
			),
			array(
				'label'  => __( 'SEO MASTERS PLUS', 'open-growth-seo' ),
				'value'  => $masters_plus_value,
				'status' => $masters_plus_status,
				'note'   => $masters_plus_note,
			),
		);

		$top_issues      = array_slice( $issues, 0, 5 );
		$recommendations = $this->build_recommendations( $issues, $indexable, $sitemaps_enabled, $schema_enabled, $bots_open_any, $has_audit_run, $audit_recent );
		$activity        = $this->build_activity();

		return array(
			'settings'        => $settings,
			'installation'    => $installation_state,
			'overview_cards'  => $overview_cards,
			'issues_count'    => $issue_count,
			'top_issues'      => $top_issues,
			'recommendations' => $recommendations,
			'activity'        => $activity,
		);
	}

	private function build_recommendations( array $issues, bool $indexable, bool $sitemaps, bool $schema, bool $bots, bool $has_audit_run, bool $audit_recent ): array {
		$recommendations = array();
		$installation    = ( new InstallationManager() )->get_state();
		$pending         = isset( $installation['pending'] ) && is_array( $installation['pending'] ) ? $installation['pending'] : array();
		$findings        = isset( $installation['diagnostics']['findings'] ) && is_array( $installation['diagnostics']['findings'] ) ? $installation['diagnostics']['findings'] : array();
		if ( ! $has_audit_run ) {
			$recommendations[] = __( 'Run your first full audit now to replace provisional states with measured runtime findings.', 'open-growth-seo' );
		} elseif ( ! $audit_recent ) {
			$recommendations[] = __( 'Run a new audit. Current findings are older than 7 days.', 'open-growth-seo' );
		}
		foreach ( array_slice( $pending, 0, 3 ) as $item ) {
			if ( is_array( $item ) && ! empty( $item['message'] ) ) {
				$recommendations[] = sanitize_text_field( (string) $item['message'] );
			}
		}
		foreach ( array_slice( $findings, 0, 2 ) as $finding ) {
			if ( is_array( $finding ) && ! empty( $finding['message'] ) && 'info' !== ( $finding['severity'] ?? '' ) ) {
				$recommendations[] = sanitize_text_field( (string) $finding['message'] );
			}
		}
		foreach ( $issues as $issue ) {
			if ( empty( $issue['recommendation'] ) ) {
				continue;
			}
			$recommendations[] = sanitize_text_field( (string) $issue['recommendation'] );
		}
		if ( ! $indexable ) {
			$recommendations[] = __( 'Allow indexing from Settings > Reading if this site is public.', 'open-growth-seo' );
		}
		if ( ! $sitemaps ) {
			$recommendations[] = __( 'Enable sitemaps and include at least one public post type.', 'open-growth-seo' );
		}
		if ( ! $schema ) {
			$recommendations[] = __( 'Enable baseline schema outputs for organization and content types.', 'open-growth-seo' );
		}
		if ( ! $bots ) {
			$recommendations[] = __( 'Review bot policy to ensure your GEO strategy matches crawl permissions.', 'open-growth-seo' );
		}
		$settings = Settings::get_all();
		if ( ExperienceMode::ADVANCED === ExperienceMode::current( $settings ) && ! SeoMastersPlus::is_active( $settings ) ) {
			$recommendations[] = __( 'Enable SEO MASTERS PLUS when your team needs advanced diagnostics and expert remediation workflows.', 'open-growth-seo' );
		}

		$recommendations = array_values( array_unique( $recommendations ) );
		return array_slice( $recommendations, 0, 6 );
	}

	private function build_activity(): array {
		$activity       = array();
		$audit_last_run = (int) get_option( 'ogs_seo_audit_last_run', 0 );
		if ( $audit_last_run > 0 ) {
			$activity[] = sprintf(
				/* translators: %s: human-readable date/time. */
				__( 'Audit last run: %s', 'open-growth-seo' ),
				wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $audit_last_run )
			);
		} else {
			$activity[] = __( 'Audit not run yet.', 'open-growth-seo' );
		}

		$post_types = get_post_types( array( 'public' => true ), 'names' );
		unset( $post_types['attachment'] );
		$recent_posts = array();
		if ( ! empty( $post_types ) ) {
			$recent_posts = get_posts(
				array(
					'post_type'      => array_values( $post_types ),
					'post_status'    => 'publish',
					'posts_per_page' => 3,
					'orderby'        => 'modified',
					'order'          => 'DESC',
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
		}

		foreach ( $recent_posts as $post_id ) {
			$modified_timestamp = (int) get_post_modified_time( 'U', true, (int) $post_id );
			$activity[] = sprintf(
				/* translators: 1: post title, 2: modified date. */
				__( 'Updated: %1$s (%2$s)', 'open-growth-seo' ),
				wp_strip_all_tags( get_the_title( (int) $post_id ) ),
				wp_date( get_option( 'date_format' ), $modified_timestamp )
			);
		}

		$queue = get_option( 'ogs_seo_indexnow_queue', array() );
		if ( is_array( $queue ) && count( $queue ) > 0 ) {
			$activity[] = sprintf(
				/* translators: %d: queue size. */
				__( 'IndexNow queue: %d URLs pending', 'open-growth-seo' ),
				count( $queue )
			);
		}

		return array_slice( $activity, 0, 6 );
	}

	private function quick_actions(): array {
		$installation_summary     = ( new InstallationManager() )->summary();
		$run_audit_url = wp_nonce_url( 'admin-post.php?action=ogs_seo_run_audit', 'ogs_seo_run_audit' );
		$rerun_installation_url = wp_nonce_url( admin_url( 'admin-post.php?action=ogs_seo_rerun_installation_diagnostics' ), 'ogs_seo_installation_action' );
		$rebuild_installation_url = wp_nonce_url( admin_url( 'admin-post.php?action=ogs_seo_rebuild_installation_state' ), 'ogs_seo_installation_action' );
		$show_advanced = ExperienceMode::is_advanced_revealed();
		$actions = array(
			array(
				'label'   => __( 'Run Audit Now', 'open-growth-seo' ),
				'url'     => $run_audit_url,
				'primary' => true,
			),
			array(
				'label' => __( 'Search Appearance', 'open-growth-seo' ),
				'url'   => 'admin.php?page=ogs-seo-search-appearance',
			),
			array(
				'label' => __( 'Setup Wizard', 'open-growth-seo' ),
				'url'   => 'admin.php?page=ogs-seo-setup',
				'primary' => ! empty( $installation_summary['wizard_recommended'] ),
			),
			array(
				'label' => __( 'Re-run diagnostics', 'open-growth-seo' ),
				'url'   => $rerun_installation_url,
			),
			array(
				'label' => __( 'Rebuild installation state', 'open-growth-seo' ),
				'url'   => $rebuild_installation_url,
			),
			array(
				'label' => __( 'Sitemaps', 'open-growth-seo' ),
				'url'   => 'admin.php?page=ogs-seo-sitemaps',
			),
			array(
				'label' => __( 'Schema', 'open-growth-seo' ),
				'url'   => 'admin.php?page=ogs-seo-schema',
			),
			array(
				'label' => __( 'Bots & Crawlers', 'open-growth-seo' ),
				'url'   => 'admin.php?page=ogs-seo-bots',
			),
			array(
				'label' => __( 'Integrations', 'open-growth-seo' ),
				'url'   => 'admin.php?page=ogs-seo-integrations',
			),
			array(
				'label' => __( 'SEO MASTERS PLUS', 'open-growth-seo' ),
				'url'   => 'admin.php?page=ogs-seo-masters-plus',
			),
		);
		$settings = Settings::get_all();
		if ( ExperienceMode::is_simple( $settings ) && ! $show_advanced ) {
			$advanced_targets = array(
				'admin.php?page=ogs-seo-schema',
				'admin.php?page=ogs-seo-bots',
				'admin.php?page=ogs-seo-integrations',
				'admin.php?page=ogs-seo-masters-plus',
				'admin.php?page=ogs-seo-tools',
			);
			$actions = array_values(
				array_filter(
					$actions,
					static function ( array $action ) use ( $advanced_targets ): bool {
						$url = isset( $action['url'] ) ? (string) $action['url'] : '';
						return ! in_array( $url, $advanced_targets, true );
					}
				)
			);
			$actions[] = array(
				'label' => __( 'Show advanced sections', 'open-growth-seo' ),
				'url'   => add_query_arg( 'ogs_show_advanced', '1', 'admin.php?page=ogs-seo-dashboard' ),
			);
		}
		return $actions;
	}

	private function installation_status_label( string $status ): string {
		switch ( sanitize_key( $status ) ) {
			case 'ready':
				return __( 'Ready', 'open-growth-seo' );
			case 'needs_attention':
				return __( 'Needs attention', 'open-growth-seo' );
			default:
				return __( 'Partial configuration', 'open-growth-seo' );
		}
	}

	private function installation_status_class( string $status ): string {
		switch ( sanitize_key( $status ) ) {
			case 'ready':
				return 'good';
			case 'needs_attention':
				return 'bad';
			default:
				return 'warn';
		}
	}

	private function count_issues_by_severity( array $issues, string $severity ): int {
		return count(
			array_filter(
				$issues,
				static fn( $issue ) => isset( $issue['severity'] ) && $severity === $issue['severity']
			)
		);
	}

	private function has_issue_keywords( array $issues, array $keywords, string $severity = '' ): bool {
		$normalized_keywords = array();
		foreach ( $keywords as $keyword ) {
			$keyword = trim( strtolower( (string) $keyword ) );
			if ( '' !== $keyword ) {
				$normalized_keywords[] = $keyword;
			}
		}
		if ( empty( $normalized_keywords ) ) {
			return false;
		}
		foreach ( $issues as $issue ) {
			$issue_severity = strtolower( (string) ( $issue['severity'] ?? '' ) );
			if ( '' !== $severity && strtolower( $severity ) !== $issue_severity ) {
				continue;
			}
			$title = strtolower( (string) ( $issue['title'] ?? '' ) );
			if ( '' === $title ) {
				continue;
			}
			foreach ( $normalized_keywords as $keyword ) {
				if ( false !== strpos( $title, $keyword ) ) {
					return true;
				}
			}
		}
		return false;
	}

	private function count_issues_by_keywords( array $issues, array $keywords ): int {
		$count = 0;
		foreach ( $issues as $issue ) {
			if ( ! is_array( $issue ) ) {
				continue;
			}
			$title = strtolower( (string) ( $issue['title'] ?? '' ) );
			if ( '' === $title ) {
				continue;
			}
			foreach ( $keywords as $keyword ) {
				$keyword = strtolower( trim( (string) $keyword ) );
				if ( '' === $keyword ) {
					continue;
				}
				if ( false !== strpos( $title, $keyword ) ) {
					++$count;
					break;
				}
			}
		}
		return $count;
	}

	private function sort_issues( array $a, array $b ): int {
		$weights = array(
			'critical'  => 3,
			'important' => 2,
			'minor'     => 1,
		);
		$left  = isset( $weights[ $a['severity'] ?? '' ] ) ? $weights[ $a['severity'] ] : 0;
		$right = isset( $weights[ $b['severity'] ?? '' ] ) ? $weights[ $b['severity'] ] : 0;
		return $right <=> $left;
	}

	private function issue_actions( array $issue ): array {
		$actions = array();
		$trace   = isset( $issue['trace'] ) && is_array( $issue['trace'] ) ? $issue['trace'] : array();
		$source  = strtolower( (string) ( $issue['source'] ?? '' ) );
		$title   = strtolower( (string) ( $issue['title'] ?? '' ) );
		$safe_fix_url = $this->issue_safe_fix_url( $issue );
		if ( '' !== $safe_fix_url ) {
			$actions[] = array(
				'label'   => __( 'Apply safe fix', 'open-growth-seo' ),
				'url'     => $safe_fix_url,
				'primary' => true,
			);
		}
		$target  = $this->issue_target_url( $trace, $source, $title );
		if ( '' !== $target ) {
			$actions[] = array(
				'label' => ! empty( $trace['post_id'] ) || ! empty( $trace['post_ids'] ) ? __( 'Edit content', 'open-growth-seo' ) : __( 'Go to setting', 'open-growth-seo' ),
				'url'   => $target,
			);
		}
		$settings_page = $this->issue_settings_page( $trace, $source, $title );
		if ( '' !== $settings_page && ( empty( $actions ) || $actions[0]['url'] !== $settings_page ) ) {
			$actions[] = array(
				'label' => __( 'Open section', 'open-growth-seo' ),
				'url'   => $settings_page,
			);
		}
		$unique = array();
		foreach ( $actions as $action ) {
			$url = isset( $action['url'] ) ? (string) $action['url'] : '';
			if ( '' === $url || isset( $unique[ $url ] ) ) {
				continue;
			}
			$unique[ $url ] = $action;
		}
		return array_values( $unique );
	}

	private function issue_safe_fix_url( array $issue ): string {
		$action = $this->issue_safe_fix_key( $issue );
		if ( '' === $action || empty( $issue['id'] ) ) {
			return '';
		}

		return wp_nonce_url(
			admin_url( 'admin-post.php?action=ogs_seo_fix_issue&issue_id=' . rawurlencode( (string) $issue['id'] ) . '&fix=' . rawurlencode( $action ) ),
			'ogs_seo_issue_action'
		);
	}

	private function issue_safe_fix_key( array $issue ): string {
		$trace    = isset( $issue['trace'] ) && is_array( $issue['trace'] ) ? $issue['trace'] : array();
		$title    = strtolower( (string) ( $issue['title'] ?? '' ) );
		$settings = strtolower( (string) ( $trace['settings'] ?? $trace['setting'] ?? '' ) );

		if ( str_contains( $title, 'sitemaps are disabled' ) || 'sitemap_enabled' === $settings ) {
			return 'enable_sitemaps';
		}
		if ( str_contains( $title, 'global snippet controls are contradictory' ) || str_contains( $settings, 'default_nosnippet' ) ) {
			return 'clear_global_max_snippet';
		}
		if ( str_contains( $title, 'another seo plugin is active' ) ) {
			return 'enable_safe_mode_conflict';
		}
		if ( str_contains( $title, 'nosnippet overrides max-snippet' ) && ! empty( $trace['post_id'] ) ) {
			return 'clear_post_max_snippet';
		}
		if ( str_contains( $title, 'redirect rule conflict detected' ) && ! empty( $trace['rule_id'] ) ) {
			return 'disable_redirect_rule';
		}

		return '';
	}

	private function issue_by_id( string $issue_id ): array {
		$issue_id = sanitize_key( $issue_id );
		if ( '' === $issue_id ) {
			return array();
		}

		foreach ( ( new AuditManager() )->get_latest_issues( true ) as $issue ) {
			if ( ! is_array( $issue ) ) {
				continue;
			}
			if ( $issue_id === sanitize_key( (string) ( $issue['id'] ?? '' ) ) ) {
				return $issue;
			}
		}

		return array();
	}

	private function apply_issue_safe_fix( array $issue ): array {
		$action = $this->issue_safe_fix_key( $issue );
		$trace  = isset( $issue['trace'] ) && is_array( $issue['trace'] ) ? $issue['trace'] : array();
		$settings = Settings::get_all();

		switch ( $action ) {
			case 'enable_sitemaps':
				$settings['sitemap_enabled'] = 1;
				if ( empty( $settings['sitemap_post_types'] ) || ! is_array( $settings['sitemap_post_types'] ) ) {
					$settings['sitemap_post_types'] = array( 'post', 'page' );
				}
				Settings::update( $settings );
				return array(
					'ok'      => true,
					'message' => __( 'Sitemaps were enabled with safe default post types.', 'open-growth-seo' ),
				);
			case 'clear_global_max_snippet':
				$settings['default_max_snippet'] = '';
				Settings::update( $settings );
				return array(
					'ok'      => true,
					'message' => __( 'Global max-snippet was cleared because nosnippet already overrides it.', 'open-growth-seo' ),
				);
			case 'enable_safe_mode_conflict':
				$settings['safe_mode_seo_conflict'] = 1;
				Settings::update( $settings );
				return array(
					'ok'      => true,
					'message' => __( 'Safe mode was enabled to reduce duplicate output risk with another SEO plugin.', 'open-growth-seo' ),
				);
			case 'clear_post_max_snippet':
				$post_id = isset( $trace['post_id'] ) ? absint( $trace['post_id'] ) : 0;
				if ( $post_id <= 0 ) {
					break;
				}
				update_post_meta( $post_id, 'ogs_seo_max_snippet', '' );
				return array(
					'ok'      => true,
					'message' => __( 'Per-URL max-snippet was cleared because nosnippet already overrides it.', 'open-growth-seo' ),
				);
			case 'disable_redirect_rule':
				$rule_id = isset( $trace['rule_id'] ) ? sanitize_key( (string) $trace['rule_id'] ) : '';
				if ( '' === $rule_id ) {
					break;
				}
				$result = Redirects::toggle_rule( $rule_id, false );
				return array(
					'ok'      => ! empty( $result['ok'] ),
					'message' => (string) ( $result['message'] ?? __( 'Redirect rule could not be disabled.', 'open-growth-seo' ) ),
				);
		}

		return array(
			'ok'      => false,
			'message' => __( 'No safe fix is available for this issue. Use the guided edit links instead.', 'open-growth-seo' ),
		);
	}

	private function issue_settings_page( array $trace, string $source, string $title ): string {
		$page = 'ogs-seo-dashboard';
		$map  = array(
			'schema'       => 'ogs-seo-schema',
			'json-ld'      => 'ogs-seo-schema',
			'structured'   => 'ogs-seo-schema',
			'sitemap'      => 'ogs-seo-sitemaps',
			'robots'       => 'ogs-seo-bots',
			'crawl'        => 'ogs-seo-bots',
			'bots'         => 'ogs-seo-bots',
			'integration'  => 'ogs-seo-integrations',
			'indexnow'     => 'ogs-seo-integrations',
			'indexability' => 'ogs-seo-search-appearance',
			'noindex'      => 'ogs-seo-search-appearance',
			'snippet'      => 'ogs-seo-search-appearance',
			'aeo'          => 'ogs-seo-content',
			'answer'       => 'ogs-seo-content',
			'geo'          => 'ogs-seo-content',
			'hreflang'     => 'ogs-seo-search-appearance',
			'redirect'     => 'ogs-seo-redirects',
		);
		$haystack = strtolower( json_encode( $trace ) . ' ' . $source . ' ' . $title );
		foreach ( $map as $needle => $slug ) {
			if ( false !== strpos( $haystack, $needle ) ) {
				$page = $slug;
				break;
			}
		}
		return 'admin.php?page=' . rawurlencode( $page );
	}

	private function issue_target_url( array $trace, string $source, string $title ): string {
		if ( ! empty( $trace['post_id'] ) ) {
			$post_id = absint( $trace['post_id'] );
			if ( $post_id > 0 ) {
				return 'post.php?post=' . $post_id . '&action=edit';
			}
		}
		if ( ! empty( $trace['post_ids'] ) && is_string( $trace['post_ids'] ) ) {
			$ids = array_filter( array_map( 'absint', explode( ',', $trace['post_ids'] ) ) );
			if ( ! empty( $ids ) ) {
				return 'post.php?post=' . (int) $ids[0] . '&action=edit';
			}
		}
		if ( ! empty( $trace['source_path'] ) ) {
			return add_query_arg(
				array(
					'page'                => 'ogs-seo-redirects',
					'ogs_redirect_source' => sanitize_text_field( (string) $trace['source_path'] ),
				),
				admin_url( 'admin.php' )
			);
		}
		if ( ! empty( $trace['setting'] ) || ! empty( $trace['settings'] ) || str_contains( $source, 'core' ) || str_contains( $title, 'sitemap' ) || str_contains( $title, 'schema' ) || str_contains( $title, 'robots' ) || str_contains( $title, 'aeo' ) || str_contains( $title, 'geo' ) ) {
			return $this->issue_settings_page( $trace, $source, $title );
		}
		return '';
	}

	private function operational_priority_label( string $severity ): string {
		switch ( sanitize_key( $severity ) ) {
			case 'critical':
				return __( 'Fix this first', 'open-growth-seo' );
			case 'important':
				return __( 'Needs work', 'open-growth-seo' );
			default:
				return __( 'Improve next', 'open-growth-seo' );
		}
	}

	private function audit_category_label( string $category ): string {
		switch ( sanitize_key( $category ) ) {
			case 'technical':
				return __( 'Technical SEO', 'open-growth-seo' );
			case 'schema':
				return __( 'Schema', 'open-growth-seo' );
			case 'linking':
				return __( 'Internal Linking', 'open-growth-seo' );
			case 'commerce':
				return __( 'Commerce', 'open-growth-seo' );
			default:
				return __( 'Content', 'open-growth-seo' );
		}
	}

	private function audit_confidence_label( string $confidence ): string {
		switch ( sanitize_key( $confidence ) ) {
			case 'high':
				return __( 'High confidence', 'open-growth-seo' );
			case 'low':
				return __( 'Lower confidence', 'open-growth-seo' );
			default:
				return __( 'Medium confidence', 'open-growth-seo' );
		}
	}

	private function aeo_status_label( string $clarity ): string {
		$clarity = strtolower( trim( $clarity ) );
		if ( in_array( $clarity, array( 'strong', 'good' ), true ) ) {
			return __( 'Good', 'open-growth-seo' );
		}
		if ( in_array( $clarity, array( 'moderate', 'needs-work' ), true ) ) {
			return __( 'Needs work', 'open-growth-seo' );
		}
		return __( 'Fix this first', 'open-growth-seo' );
	}

	private function aeo_priority_label( string $priority ): string {
		$priority = strtolower( trim( $priority ) );
		if ( 'strong' === $priority ) {
			return __( 'Strong', 'open-growth-seo' );
		}
		if ( 'needs-work' === $priority ) {
			return __( 'Needs work', 'open-growth-seo' );
		}
		return __( 'Fix this first', 'open-growth-seo' );
	}

	private function aeo_priority_pill_class( string $priority ): string {
		$priority = strtolower( trim( $priority ) );
		if ( 'strong' === $priority ) {
			return 'ogs-pill-good';
		}
		if ( 'needs-work' === $priority ) {
			return 'ogs-pill-important';
		}
		return 'ogs-pill-critical';
	}

	private function sfo_priority_label( string $priority ): string {
		$priority = strtolower( trim( $priority ) );
		if ( 'strong' === $priority ) {
			return __( 'Strong', 'open-growth-seo' );
		}
		if ( 'needs-work' === $priority ) {
			return __( 'Needs work', 'open-growth-seo' );
		}
		return __( 'Fix this first', 'open-growth-seo' );
	}

	private function sfo_priority_pill_class( string $priority ): string {
		$priority = strtolower( trim( $priority ) );
		if ( 'strong' === $priority ) {
			return 'ogs-pill-good';
		}
		if ( 'needs-work' === $priority ) {
			return 'ogs-pill-important';
		}
		return 'ogs-pill-critical';
	}

	private function geo_citability_label( int $score ): string {
		if ( $score >= 3 ) {
			return __( 'Good', 'open-growth-seo' );
		}
		if ( $score >= 2 ) {
			return __( 'Needs work', 'open-growth-seo' );
		}
		return __( 'Fix this first', 'open-growth-seo' );
	}

	private function geo_freshness_label( int $days ): string {
		if ( $days < 0 ) {
			return __( 'Needs work', 'open-growth-seo' );
		}
		if ( $days <= 180 ) {
			return __( 'Good', 'open-growth-seo' );
		}
		if ( $days <= 365 ) {
			return __( 'Needs work', 'open-growth-seo' );
		}
		return __( 'Fix this first', 'open-growth-seo' );
	}

	private function geo_priority_label( string $priority ): string {
		$priority = strtolower( trim( $priority ) );
		if ( 'strong' === $priority ) {
			return __( 'Strong', 'open-growth-seo' );
		}
		if ( 'needs-work' === $priority ) {
			return __( 'Needs work', 'open-growth-seo' );
		}
		return __( 'Fix this first', 'open-growth-seo' );
	}

	private function geo_priority_pill_class( string $priority ): string {
		$priority = strtolower( trim( $priority ) );
		if ( 'strong' === $priority ) {
			return 'ogs-pill-good';
		}
		if ( 'needs-work' === $priority ) {
			return 'ogs-pill-important';
		}
		return 'ogs-pill-critical';
	}

	private function geo_bot_exposure_label( string $state ): string {
		$state = sanitize_key( $state );
		if ( 'open' === $state ) {
			return __( 'AI bot access open', 'open-growth-seo' );
		}
		if ( 'search-only' === $state ) {
			return __( 'Search-only access', 'open-growth-seo' );
		}
		return __( 'Restricted bot access', 'open-growth-seo' );
	}

	private function render_issue_card( array $issue ): void {
		$severity = (string) ( $issue['severity'] ?? 'minor' );
		$title    = (string) ( $issue['title'] ?? '' );
		$why      = (string) ( $issue['explanation'] ?? '' );
		$next     = (string) ( $issue['recommendation'] ?? '' );
		$issue_id = isset( $issue['id'] ) ? sanitize_key( (string) $issue['id'] ) : '';
		$actions  = $this->issue_actions( $issue );
		$trace    = isset( $issue['trace'] ) && is_array( $issue['trace'] ) ? $issue['trace'] : array();
		$category = (string) ( $issue['category'] ?? 'content' );
		$confidence = (string) ( $issue['confidence'] ?? '' );
		$quick_win = ! empty( $issue['quick_win'] );

		echo '<article class="ogs-card ogs-issue-card ogs-issue-card-' . esc_attr( $severity ) . '">';
		echo '<div class="ogs-issue-card-header">';
		echo '<h3>' . esc_html( $title ) . '</h3>';
		echo '<span class="ogs-pill ogs-pill-' . esc_attr( $severity ) . '">' . esc_html( $this->operational_priority_label( $severity ) ) . '</span>';
		echo '</div>';
		echo '<div class="ogs-actions ogs-audit-meta">';
		echo '<span class="ogs-schema-chip ogs-schema-chip-neutral">' . esc_html( $this->audit_category_label( $category ) ) . '</span>';
		if ( $quick_win ) {
			echo '<span class="ogs-schema-chip ogs-schema-chip-good">' . esc_html__( 'Quick win', 'open-growth-seo' ) . '</span>';
		}
		if ( '' !== $confidence ) {
			echo '<span class="ogs-schema-chip ogs-schema-chip-minor">' . esc_html( $this->audit_confidence_label( $confidence ) ) . '</span>';
		}
		echo '</div>';
		echo '<p><strong>' . esc_html__( 'What is happening', 'open-growth-seo' ) . '</strong><br/>' . esc_html( $title ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Why it matters', 'open-growth-seo' ) . '</strong><br/>' . esc_html( $why ) . '</p>';
		echo '<p><strong>' . esc_html__( 'What to change now', 'open-growth-seo' ) . '</strong><br/>' . esc_html( $next ) . '</p>';
		$trace_confidence = '';
		if ( ! empty( $trace['orphan_confidence'] ) ) {
			$trace_confidence = sanitize_text_field( (string) $trace['orphan_confidence'] );
		} elseif ( ! empty( $trace['link_graph_confidence'] ) ) {
			$trace_confidence = sanitize_text_field( (string) $trace['link_graph_confidence'] );
		}
		if ( '' !== $trace_confidence ) {
			echo '<p class="description">' . esc_html( sprintf( __( 'Trace confidence: %s', 'open-growth-seo' ), ucfirst( $trace_confidence ) ) ) . '</p>';
		}
		$this->render_issue_related_posts( $trace );
		if ( ! empty( $actions ) ) {
			echo '<div class="ogs-actions ogs-issue-actions">';
			foreach ( $actions as $action ) {
				$class = ! empty( $action['primary'] ) ? 'button button-primary button-small' : 'button button-small';
				echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( (string) $action['url'] ) . '">' . esc_html( (string) $action['label'] ) . '</a>';
			}
			echo '</div>';
		}
		echo '<form class="ogs-issue-ignore" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'ogs_seo_issue_action' );
		echo '<input type="hidden" name="action" value="ogs_seo_ignore_issue"/>';
		echo '<input type="hidden" name="issue_id" value="' . esc_attr( $issue_id ) . '"/>';
		echo '<label for="ogs-ignore-' . esc_attr( $issue_id ) . '" class="screen-reader-text">' . esc_html__( 'Ignore reason', 'open-growth-seo' ) . '</label>';
		echo '<input id="ogs-ignore-' . esc_attr( $issue_id ) . '" type="text" name="reason" class="regular-text" placeholder="' . esc_attr__( 'Ignore reason', 'open-growth-seo' ) . '" required/>';
		echo '<button class="button button-small" type="submit">' . esc_html__( 'Ignore', 'open-growth-seo' ) . '</button>';
		echo '</form>';
		echo '</article>';
	}

	private function render_issue_related_posts( array $trace ): void {
		$post_ids = $this->related_post_ids_from_trace( $trace );
		if ( empty( $post_ids ) ) {
			return;
		}

		echo '<p><strong>' . esc_html__( 'Suggested pages to review', 'open-growth-seo' ) . '</strong></p>';
		echo '<ul class="ogs-link-suggestions">';
		foreach ( $post_ids as $post_id ) {
			$title    = (string) get_the_title( $post_id );
			$edit_url = (string) ( get_edit_post_link( $post_id ) ?: '' );
			echo '<li>';
			if ( '' !== $edit_url ) {
				echo '<a href="' . esc_url( $edit_url ) . '">' . esc_html( $title ) . '</a>';
			} else {
				echo esc_html( $title );
			}
			echo '</li>';
		}
		echo '</ul>';
	}

	private function related_post_ids_from_trace( array $trace ): array {
		$raw = '';
		if ( ! empty( $trace['suggested_source_ids'] ) ) {
			$raw = (string) $trace['suggested_source_ids'];
		} elseif ( ! empty( $trace['suggested_post_ids'] ) ) {
			$raw = (string) $trace['suggested_post_ids'];
		}
		if ( '' === $raw ) {
			return array();
		}

		return array_slice( array_values( array_filter( array_map( 'absint', explode( ',', $raw ) ) ) ), 0, 5 );
	}

	private function render_content_coaching_card( array $args ): void {
		$title       = (string) ( $args['title'] ?? '' );
		$url         = (string) ( $args['url'] ?? '' );
		$status      = (string) ( $args['status'] ?? __( 'Needs work', 'open-growth-seo' ) );
		$summary     = isset( $args['summary'] ) && is_array( $args['summary'] ) ? $args['summary'] : array();
		$next_action = (string) ( $args['next_action'] ?? __( 'No immediate recommendation.', 'open-growth-seo' ) );
		$meta        = isset( $args['meta'] ) && is_array( $args['meta'] ) ? array_filter( array_map( 'strval', $args['meta'] ) ) : array();
		$actions     = isset( $args['actions'] ) && is_array( $args['actions'] ) ? array_filter( array_map( 'strval', $args['actions'] ) ) : array();
		$links       = isset( $args['links'] ) && is_array( $args['links'] ) ? $args['links'] : array();
		$status_class = (string) ( $args['status_class'] ?? 'ogs-pill-important' );

		echo '<article class="ogs-card ogs-coaching-card">';
		echo '<div class="ogs-issue-card-header"><h3>';
		if ( '' !== $url ) {
			echo '<a href="' . esc_url( $url ) . '">' . esc_html( $title ) . '</a>';
		} else {
			echo esc_html( $title );
		}
		echo '</h3><span class="ogs-pill ' . esc_attr( $status_class ) . '">' . esc_html( $status ) . '</span></div>';
		if ( ! empty( $meta ) ) {
			echo '<div class="ogs-actions ogs-aeo-meta">';
			foreach ( $meta as $item ) {
				echo '<span class="ogs-schema-chip ogs-schema-chip-neutral">' . esc_html( $item ) . '</span>';
			}
			echo '</div>';
		}
		if ( ! empty( $summary ) ) {
			echo '<ul class="ogs-coaching-list">';
			foreach ( $summary as $item ) {
				echo '<li>' . esc_html( (string) $item ) . '</li>';
			}
			echo '</ul>';
		}
		echo '<p><strong>' . esc_html__( 'Next best action', 'open-growth-seo' ) . '</strong><br/>' . esc_html( $next_action ) . '</p>';
		if ( ! empty( $actions ) ) {
			echo '<p><strong>' . esc_html__( 'Priority actions', 'open-growth-seo' ) . '</strong></p><ul class="ogs-coaching-actions">';
			foreach ( array_slice( $actions, 0, 3 ) as $item ) {
				echo '<li>' . esc_html( $item ) . '</li>';
			}
			echo '</ul>';
		}
		if ( '' !== $url || ! empty( $links ) ) {
			echo '<div class="ogs-actions">';
			if ( '' !== $url ) {
				echo '<a class="button button-small" href="' . esc_url( $url ) . '">' . esc_html__( 'Edit content', 'open-growth-seo' ) . '</a>';
			}
			foreach ( $links as $link ) {
				if ( ! is_array( $link ) || empty( $link['url'] ) || empty( $link['label'] ) ) {
					continue;
				}
				echo '<a class="button button-small" href="' . esc_url( (string) $link['url'] ) . '">' . esc_html( (string) $link['label'] ) . '</a>';
			}
			echo '</div>';
		}
		echo '</article>';
	}

	private function render_installation_profile_section( array $state ): void {
		$installation_manager = new InstallationManager();
		$summary        = $installation_manager->summary();
		$history_filter = isset( $_GET['ogs_installation_history_filter'] ) ? sanitize_key( (string) wp_unslash( $_GET['ogs_installation_history_filter'] ) ) : 'all';
		$history_group  = isset( $_GET['ogs_installation_history_group'] ) ? sanitize_key( (string) wp_unslash( $_GET['ogs_installation_history_group'] ) ) : 'none';
		$telemetry      = $installation_manager->telemetry( 12, $history_filter, $history_group );
		$history_groups = isset( $telemetry['groups'] ) && is_array( $telemetry['groups'] ) ? $telemetry['groups'] : array();
		$classification = isset( $state['classification'] ) && is_array( $state['classification'] ) ? $state['classification'] : array();
		$pending        = isset( $state['pending'] ) && is_array( $state['pending'] ) ? $state['pending'] : array();
		$applied        = isset( $state['applied_settings'] ) && is_array( $state['applied_settings'] ) ? $state['applied_settings'] : array();
		$findings       = isset( $state['diagnostics']['findings'] ) && is_array( $state['diagnostics']['findings'] ) ? $state['diagnostics']['findings'] : array();
		$rerun_url      = wp_nonce_url( admin_url( 'admin-post.php?action=ogs_seo_rerun_installation_diagnostics' ), 'ogs_seo_installation_action' );
		$rebuild_url    = wp_nonce_url( admin_url( 'admin-post.php?action=ogs_seo_rebuild_installation_state' ), 'ogs_seo_installation_action' );
		$last_run       = isset( $summary['last_run'] ) ? (int) $summary['last_run'] : 0;
		$status         = isset( $summary['status'] ) ? (string) $summary['status'] : 'partial_configuration';
		$repair_actions = isset( $state['repair_actions'] ) && is_array( $state['repair_actions'] ) ? array_values( array_filter( array_map( 'strval', $state['repair_actions'] ) ) ) : array();
		$plugin_version = isset( $summary['plugin_version'] ) ? (string) $summary['plugin_version'] : '';

		echo '<h2>' . esc_html__( 'Installation Profile', 'open-growth-seo' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'This profile tracks what the plugin detected on install/activation, which defaults were auto-applied, and what still needs review.', 'open-growth-seo' ) . '</p>';
		if ( ! empty( $summary['wizard_recommended'] ) ) {
			echo '<div class="notice notice-warning inline ogs-installation-notice"><p><strong>' . esc_html__( 'Setup review recommended.', 'open-growth-seo' ) . '</strong> ' . esc_html__( 'This looks like an incomplete or partially repaired installation state. Review the setup wizard before relying on advanced configuration.', 'open-growth-seo' ) . ' <a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=ogs-seo-setup' ) ) . '">' . esc_html__( 'Open Setup Wizard', 'open-growth-seo' ) . '</a></p></div>';
		}
		echo '<table class="widefat striped" style="max-width:920px"><tbody>';
		echo '<tr><th>' . esc_html__( 'Status', 'open-growth-seo' ) . '</th><td><span class="ogs-status ogs-status-' . esc_attr( $this->installation_status_class( $status ) ) . '">' . esc_html( $this->installation_status_label( $status ) ) . '</span></td></tr>';
		echo '<tr><th>' . esc_html__( 'Setup completed', 'open-growth-seo' ) . '</th><td>' . esc_html( ! empty( $summary['setup_complete'] ) ? __( 'Yes', 'open-growth-seo' ) : __( 'No', 'open-growth-seo' ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Setup review recommended', 'open-growth-seo' ) . '</th><td>' . esc_html( ! empty( $summary['wizard_recommended'] ) ? __( 'Yes', 'open-growth-seo' ) : __( 'No', 'open-growth-seo' ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Detected primary type', 'open-growth-seo' ) . '</th><td>' . esc_html( ucfirst( str_replace( '_', ' ', (string) ( $summary['primary'] ?? 'general' ) ) ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Secondary types', 'open-growth-seo' ) . '</th><td>' . esc_html( empty( $summary['secondary'] ) ? __( 'None', 'open-growth-seo' ) : implode( ', ', array_map( static fn( $item ) => ucfirst( str_replace( '_', ' ', (string) $item ) ), (array) $summary['secondary'] ) ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Confidence', 'open-growth-seo' ) . '</th><td>' . esc_html( sprintf( '%d%%', (int) ( $summary['confidence'] ?? 0 ) ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Last run', 'open-growth-seo' ) . '</th><td>' . esc_html( $last_run > 0 ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_run ) : __( 'Not run yet', 'open-growth-seo' ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Source', 'open-growth-seo' ) . '</th><td>' . esc_html( ucfirst( str_replace( '_', ' ', (string) ( $summary['source'] ?? 'manual' ) ) ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Plugin version', 'open-growth-seo' ) . '</th><td>' . esc_html( '' !== $plugin_version ? $plugin_version : __( 'Unknown', 'open-growth-seo' ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Recorded rebuilds', 'open-growth-seo' ) . '</th><td>' . esc_html( (string) (int) ( $summary['rebuilds'] ?? 0 ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Recorded repair runs', 'open-growth-seo' ) . '</th><td>' . esc_html( (string) (int) ( $summary['repair_runs'] ?? 0 ) ) . '</td></tr>';
		echo '</tbody></table>';
		echo '<p><a class="button button-secondary" href="' . esc_url( $rerun_url ) . '">' . esc_html__( 'Re-run installation diagnostics', 'open-growth-seo' ) . '</a> <a class="button button-secondary" href="' . esc_url( $rebuild_url ) . '" onclick="return window.confirm(\'' . esc_js( __( 'Rebuild the stored installation state from current diagnostics and defaults?', 'open-growth-seo' ) ) . '\');">' . esc_html__( 'Rebuild installation state', 'open-growth-seo' ) . '</a></p>';

		if ( ! empty( $repair_actions ) ) {
			echo '<h3>' . esc_html__( 'Recent repair actions', 'open-growth-seo' ) . '</h3><ul>';
			foreach ( array_slice( $repair_actions, 0, 8 ) as $repair_action ) {
				echo '<li>' . esc_html( ucfirst( str_replace( '_', ' ', $repair_action ) ) ) . '</li>';
			}
			echo '</ul>';
		}

		if ( ! empty( $history_groups ) ) {
			echo '<h3>' . esc_html__( 'Installation telemetry history', 'open-growth-seo' ) . '</h3>';
			echo '<form method="get" class="ogs-installation-history-filters">';
			echo '<input type="hidden" name="page" value="' . esc_attr( isset( $_GET['page'] ) ? sanitize_key( (string) wp_unslash( $_GET['page'] ) ) : 'ogs-seo-tools' ) . '"/>';
			if ( ! empty( $_GET['ogs_show_advanced'] ) ) {
				echo '<input type="hidden" name="ogs_show_advanced" value="1"/>';
			}
			echo '<label><span class="screen-reader-text">' . esc_html__( 'Filter installation history', 'open-growth-seo' ) . '</span><select name="ogs_installation_history_filter">';
			foreach ( array(
				'all'          => __( 'All events', 'open-growth-seo' ),
				'rebuilds'     => __( 'Rebuilds only', 'open-growth-seo' ),
				'repairs'      => __( 'Repair runs only', 'open-growth-seo' ),
				'setup-review' => __( 'Setup review items', 'open-growth-seo' ),
			) as $value => $label ) {
				echo '<option value="' . esc_attr( $value ) . '"' . selected( $history_filter, $value, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select></label> ';
			echo '<label><span class="screen-reader-text">' . esc_html__( 'Group installation history', 'open-growth-seo' ) . '</span><select name="ogs_installation_history_group">';
			foreach ( array(
				'none'   => __( 'No grouping', 'open-growth-seo' ),
				'source' => __( 'Group by source', 'open-growth-seo' ),
				'day'    => __( 'Group by day', 'open-growth-seo' ),
			) as $value => $label ) {
				echo '<option value="' . esc_attr( $value ) . '"' . selected( $history_group, $value, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select></label> ';
			echo '<button class="button button-secondary" type="submit">' . esc_html__( 'Apply history view', 'open-growth-seo' ) . '</button>';
			echo '</form>';
			foreach ( $history_groups as $group_key => $entries ) {
				$group_title = 'none' === $history_group ? __( 'Recent events', 'open-growth-seo' ) : ucfirst( str_replace( '_', ' ', (string) $group_key ) );
				echo '<h4>' . esc_html( $group_title ) . '</h4>';
				echo '<table class="widefat striped" style="max-width:920px"><thead><tr><th>' . esc_html__( 'Time', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Source', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Status', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Repairs', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Notes', 'open-growth-seo' ) . '</th></tr></thead><tbody>';
				foreach ( $entries as $entry ) {
					if ( ! is_array( $entry ) ) {
						continue;
					}
					$entry_time   = isset( $entry['time'] ) ? (int) $entry['time'] : 0;
					$entry_source = isset( $entry['source'] ) ? (string) $entry['source'] : '';
					$entry_status = isset( $entry['status'] ) ? (string) $entry['status'] : '';
					$entry_repairs = isset( $entry['repair_count'] ) ? (int) $entry['repair_count'] : 0;
					$entry_notes  = array();
					if ( ! empty( $entry['wizard_recommended'] ) ) {
						$entry_notes[] = __( 'Setup review recommended', 'open-growth-seo' );
					}
					if ( ! empty( $entry['fresh_install'] ) ) {
						$entry_notes[] = __( 'Fresh install', 'open-growth-seo' );
					}
					if ( ! empty( $entry['applied'] ) ) {
						$entry_notes[] = sprintf( __( '%d settings applied', 'open-growth-seo' ), (int) $entry['applied'] );
					}
					if ( ! empty( $entry['pending'] ) ) {
						$entry_notes[] = sprintf( __( '%d follow-up items', 'open-growth-seo' ), (int) $entry['pending'] );
					}
					echo '<tr><td>' . esc_html( $entry_time > 0 ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $entry_time ) : __( 'Unknown', 'open-growth-seo' ) ) . '</td><td>' . esc_html( ucfirst( str_replace( '_', ' ', $entry_source ) ) ) . '</td><td>' . esc_html( $this->installation_status_label( $entry_status ) ) . '</td><td>' . esc_html( (string) $entry_repairs ) . '</td><td>' . esc_html( empty( $entry_notes ) ? __( 'No additional notes.', 'open-growth-seo' ) : implode( ' | ', $entry_notes ) ) . '</td></tr>';
				}
				echo '</tbody></table>';
			}
		}

		if ( ! empty( $classification['signals'] ) ) {
			echo '<h3>' . esc_html__( 'Detection signals', 'open-growth-seo' ) . '</h3><ul>';
			foreach ( array_slice( (array) $classification['signals'], 0, 8 ) as $signal ) {
				echo '<li>' . esc_html( (string) $signal ) . '</li>';
			}
			echo '</ul>';
		}

		if ( ! empty( $applied ) ) {
			echo '<h3>' . esc_html__( 'Auto-applied settings', 'open-growth-seo' ) . '</h3>';
			echo '<table class="widefat striped" style="max-width:920px"><thead><tr><th>' . esc_html__( 'Setting', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Source', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Reason', 'open-growth-seo' ) . '</th></tr></thead><tbody>';
			foreach ( $applied as $key => $item ) {
				echo '<tr><td><code>' . esc_html( (string) $key ) . '</code></td><td>' . esc_html( ucfirst( (string) ( $item['source'] ?? 'recommended' ) ) ) . '</td><td>' . esc_html( (string) ( $item['reason'] ?? '' ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		if ( ! empty( $pending ) ) {
			echo '<h3>' . esc_html__( 'Pending follow-up', 'open-growth-seo' ) . '</h3><ul>';
			foreach ( $pending as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$label   = isset( $item['label'] ) ? (string) $item['label'] : '';
				$message = isset( $item['message'] ) ? (string) $item['message'] : '';
				echo '<li><strong>' . esc_html( $label ) . '</strong>: ' . esc_html( $message ) . '</li>';
			}
			echo '</ul>';
		}

		if ( ! empty( $findings ) ) {
			echo '<h3>' . esc_html__( 'Environment findings', 'open-growth-seo' ) . '</h3><ul>';
			foreach ( array_slice( $findings, 0, 8 ) as $finding ) {
				if ( ! is_array( $finding ) ) {
					continue;
				}
				$severity = isset( $finding['severity'] ) ? sanitize_key( (string) $finding['severity'] ) : 'info';
				$message  = isset( $finding['message'] ) ? (string) $finding['message'] : '';
				echo '<li><strong>' . esc_html( ucfirst( $severity ) ) . ':</strong> ' . esc_html( $message ) . '</li>';
			}
			echo '</ul>';
		}
	}

	public function render_network_installation(): void {
		if ( ! is_multisite() || ! current_user_can( 'manage_network_options' ) ) {
			return;
		}

		$installation_manager = new InstallationManager();
		$summary              = $installation_manager->summary();
		$state                = $installation_manager->get_state();
		?>
		<div class="wrap ogs-seo-wrap">
			<h1><?php esc_html_e( 'Open Growth SEO Network Installation', 'open-growth-seo' ); ?></h1>
			<p class="description"><?php esc_html_e( 'This network view summarizes the installation baseline for the current site context. Use it to verify first-run health, recovery history, and setup readiness before rolling out deeper SEO changes across multisite.', 'open-growth-seo' ); ?></p>
			<div class="notice notice-info inline ogs-installation-notice">
				<p><strong><?php esc_html_e( 'Multisite mode uses per-site installation profiles.', 'open-growth-seo' ); ?></strong></p>
				<p>
					<?php esc_html_e( 'Review the active site profile here, then use the site-level Setup Wizard and Tools workspace for recovery actions. Network-level rollout decisions should not assume every site shares the same installation state.', 'open-growth-seo' ); ?>
					<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=ogs-seo-setup' ) ); ?>"><?php esc_html_e( 'Open site setup wizard', 'open-growth-seo' ); ?></a>
					<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=ogs-seo-tools&ogs_show_advanced=1' ) ); ?>"><?php esc_html_e( 'Open site installation tools', 'open-growth-seo' ); ?></a>
				</p>
				<p class="description"><?php echo esc_html( sprintf( __( 'Current site status: %1$s. Rebuilds recorded: %2$d. Repair runs recorded: %3$d.', 'open-growth-seo' ), $this->installation_status_label( (string) ( $summary['status'] ?? 'partial_configuration' ) ), (int) ( $summary['rebuilds'] ?? 0 ), (int) ( $summary['repair_runs'] ?? 0 ) ) ); ?></p>
			</div>
			<?php $this->render_installation_profile_section( $state ); ?>
		</div>
		<?php
	}

	public function render_generic(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$this->rehydrate_settings_errors();
		$page     = isset( $_GET['page'] ) ? sanitize_key( (string) wp_unslash( $_GET['page'] ) ) : 'ogs-seo-dashboard';
		$pages    = $this->admin_pages();
		$page_meta = isset( $pages[ $page ] ) && is_array( $pages[ $page ] ) ? $pages[ $page ] : array();
		$settings = Settings::get_all();
		if ( ! empty( $this->pending_settings_override ) ) {
			$settings = $this->pending_settings_override;
		}
		$simple = ExperienceMode::is_simple( $settings );
		$show_advanced = ExperienceMode::is_advanced_revealed();
		$is_advanced_page = ! empty( $page_meta['advanced'] );
		$title = isset( $page_meta['title'] ) ? (string) $page_meta['title'] : ucwords( str_replace( array( 'ogs-seo-', '-' ), array( '', ' ' ), $page ) );
		$description = isset( $page_meta['description'] ) ? (string) $page_meta['description'] : '';
		$internal_form_pages = array( 'ogs-seo-setup', 'ogs-seo-audits', 'ogs-seo-tools', 'ogs-seo-redirects' );
		?>
		<div class="wrap ogs-seo-wrap">
			<?php $this->render_page_intro( $page, $title, $description, $simple, $show_advanced, $settings ); ?>
			<?php $this->render_admin_section_nav( $page, $simple, $show_advanced ); ?>
			<?php $this->render_workflow_continuity( $page, $settings ); ?>
			<?php settings_errors( 'ogs_seo' ); ?>
			<?php
			$installation_summary = ( new InstallationManager() )->summary();
			if ( 'ogs-seo-setup' !== $page && ( ! empty( $installation_summary['wizard_recommended'] ) || ! empty( $installation_summary['repair_runs'] ) ) ) :
				?>
				<div class="notice notice-warning inline ogs-installation-notice">
					<p><strong><?php echo esc_html( ! empty( $installation_summary['wizard_recommended'] ) ? __( 'Setup is not fully completed yet.', 'open-growth-seo' ) : __( 'Installation baseline was repaired recently.', 'open-growth-seo' ) ); ?></strong></p>
					<p>
						<?php echo esc_html( ! empty( $installation_summary['wizard_recommended'] ) ? __( 'Core settings and recommendations are available, but the guided setup should still be reviewed to confirm indexing, site identity, and first-run defaults.', 'open-growth-seo' ) : __( 'This workspace depends on a healthy installation baseline. Review the profile or rebuild the stored installation state before making deeper configuration changes.', 'open-growth-seo' ) ); ?>
						<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=ogs-seo-setup' ) ); ?>"><?php esc_html_e( 'Finish setup review', 'open-growth-seo' ); ?></a>
						<a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ogs_seo_rebuild_installation_state' ), 'ogs_seo_installation_action' ) ); ?>" onclick="return window.confirm('<?php echo esc_js( __( 'Rebuild the stored installation state from current diagnostics and defaults?', 'open-growth-seo' ) ); ?>');"><?php esc_html_e( 'Rebuild installation state', 'open-growth-seo' ); ?></a>
						<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=ogs-seo-tools&ogs_show_advanced=1' ) ); ?>"><?php esc_html_e( 'Open installation profile', 'open-growth-seo' ); ?></a>
					</p>
					<?php if ( ! empty( $installation_summary['repair_runs'] ) ) : ?>
						<p class="description"><?php echo esc_html( sprintf( __( 'Repair runs recorded: %d. Review recent installation history if behavior looks inconsistent.', 'open-growth-seo' ), (int) $installation_summary['repair_runs'] ) ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			<?php if ( $simple && $is_advanced_page && ! $show_advanced ) : ?>
				<div class="notice notice-info inline">
					<p>
						<?php esc_html_e( 'This section is hidden in Simple mode to reduce complexity for novice users.', 'open-growth-seo' ); ?>
						<a class="button button-secondary" href="<?php echo esc_url( add_query_arg( 'ogs_show_advanced', '1', 'admin.php?page=' . rawurlencode( $page ) ) ); ?>"><?php esc_html_e( 'Show advanced section', 'open-growth-seo' ); ?></a>
					</p>
				</div>
				<?php echo '</div>'; ?>
				<?php return; ?>
			<?php endif; ?>
			<?php if ( in_array( $page, $internal_form_pages, true ) ) : ?>
				<?php $this->render_page_fields( $page, $settings ); ?>
			<?php else : ?>
				<form method="post">
					<?php wp_nonce_field( 'ogs_seo_save_settings' ); ?>
					<input type="hidden" name="ogs_seo_action" value="save_settings" />
					<?php $this->render_page_fields( $page, $settings ); ?>
					<div class="ogs-save-bar">
						<p class="description"><?php esc_html_e( 'Changes on this screen are validated before saving. Review warnings carefully when you are working with schema, bots, redirects, or integrations.', 'open-growth-seo' ); ?></p>
						<p><button class="button button-primary" type="submit"><?php esc_html_e( 'Save changes', 'open-growth-seo' ); ?></button></p>
					</div>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_page_fields( string $page, array $settings ): void {
		if ( 'ogs-seo-setup' === $page ) {
			( new SetupWizard() )->render();
			return;
		}
		if ( 'ogs-seo-search-appearance' === $page ) {
			( new SearchAppearancePage( $this ) )->render( $settings );
			return;
		}
		if ( 'ogs-seo-settings' === $page ) {
			$this->field_text( 'title_separator', __( 'Title Separator', 'open-growth-seo' ), $settings['title_separator'] );
			$this->field_select(
				'canonical_enabled',
				__( 'Canonical Tags', 'open-growth-seo' ),
				(string) $settings['canonical_enabled'],
				array(
					'1' => __( 'Enabled', 'open-growth-seo' ),
					'0' => __( 'Disabled', 'open-growth-seo' ),
				)
			);
			$this->field_select(
				'og_enabled',
				__( 'Open Graph', 'open-growth-seo' ),
				(string) $settings['og_enabled'],
				array(
					'1' => __( 'Enabled', 'open-growth-seo' ),
					'0' => __( 'Disabled', 'open-growth-seo' ),
				)
			);
			$this->field_select(
				'twitter_enabled',
				__( 'Twitter/X Cards', 'open-growth-seo' ),
				(string) $settings['twitter_enabled'],
				array(
					'1' => __( 'Enabled', 'open-growth-seo' ),
					'0' => __( 'Disabled', 'open-growth-seo' ),
				)
			);
			echo '<h2>' . esc_html__( 'Webmaster Verification', 'open-growth-seo' ) . '</h2>';
			echo '<p class="description">' . esc_html__( 'Paste verification tokens here when you need to prove site ownership without editing theme templates or third-party plugins.', 'open-growth-seo' ) . '</p>';
			$this->render_inline_help_modal_shell();
			$this->render_webmaster_verification_field( 'webmaster_google_verification', __( 'Google Verification Token', 'open-growth-seo' ), (string) $settings['webmaster_google_verification'] );
			$this->render_webmaster_verification_field( 'webmaster_bing_verification', __( 'Bing Verification Token', 'open-growth-seo' ), (string) $settings['webmaster_bing_verification'] );
			$this->render_webmaster_verification_field( 'webmaster_yandex_verification', __( 'Yandex Verification Token', 'open-growth-seo' ), (string) $settings['webmaster_yandex_verification'] );
			$this->render_webmaster_verification_field( 'webmaster_pinterest_verification', __( 'Pinterest Verification Token', 'open-growth-seo' ), (string) $settings['webmaster_pinterest_verification'] );
			$this->render_webmaster_verification_field( 'webmaster_baidu_verification', __( 'Baidu Verification Token', 'open-growth-seo' ), (string) $settings['webmaster_baidu_verification'] );
		}
		if ( 'ogs-seo-schema' === $page ) {
			$this->render_schema_settings_page( $settings );
		}
		if ( 'ogs-seo-content' === $page ) {
			echo '<p class="description">' . esc_html__( 'AEO analysis is heuristic and intended for actionable content structure improvements, not ranking guarantees.', 'open-growth-seo' ) . '</p>';
			$this->render_rest_action( __( 'REST AEO Analyze (latest)', 'open-growth-seo' ), 'ogs-seo/v1/aeo/analyze' );
			$posts = get_posts(
				array(
					'post_type'      => get_post_types( array( 'public' => true ), 'names' ),
					'post_status'    => 'publish',
					'posts_per_page' => 6,
					'orderby'        => 'modified',
					'order'          => 'DESC',
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			if ( empty( $posts ) ) {
				echo '<p class="ogs-empty">' . esc_html__( 'No published content found for AEO analysis yet.', 'open-growth-seo' ) . '</p>';
			} else {
				$overview = array(
					'analyzed'    => 0,
					'avg_score'   => 0,
					'priority'    => array(
						'strong'         => 0,
						'needs-work'     => 0,
						'fix-this-first' => 0,
					),
					'quick_wins'  => 0,
					'opportunities' => 0,
				);
				$cards = array();
				foreach ( $posts as $post_id ) {
					$post_id       = (int) $post_id;
					$analysis      = Analyzer::analyze_post( $post_id );
					$summary       = isset( $analysis['summary'] ) && is_array( $analysis['summary'] ) ? $analysis['summary'] : array();
					$signals       = isset( $analysis['signals'] ) && is_array( $analysis['signals'] ) ? $analysis['signals'] : array();
					$intent        = isset( $signals['intent'] ) && is_array( $signals['intent'] ) ? $signals['intent'] : array();
					$priority      = (string) ( $summary['priority_status'] ?? 'needs-work' );
					$priority_text = $this->aeo_priority_label( $priority );
					$next_action   = isset( $analysis['priority_actions'][0]['action'] ) ? (string) $analysis['priority_actions'][0]['action'] : ( isset( $analysis['recommendations'][0] ) ? (string) $analysis['recommendations'][0] : __( 'No immediate recommendation.', 'open-growth-seo' ) );
					$actions       = array();
					if ( ! empty( $analysis['priority_actions'] ) && is_array( $analysis['priority_actions'] ) ) {
						foreach ( $analysis['priority_actions'] as $item ) {
							if ( is_array( $item ) && ! empty( $item['action'] ) ) {
								$actions[] = (string) $item['action'];
							}
						}
					}
					$overview['analyzed']++;
					$overview['avg_score'] += (int) ( $summary['aeo_score'] ?? 0 );
					$overview['quick_wins'] += (int) ( $summary['quick_win_count'] ?? 0 );
					$overview['opportunities'] += (int) ( $summary['opportunity_count'] ?? 0 );
					if ( isset( $overview['priority'][ $priority ] ) ) {
						$overview['priority'][ $priority ]++;
					}
					$cards[] = array(
						'title'        => get_the_title( $post_id ),
						'url'          => (string) ( get_edit_post_link( $post_id ) ?: '' ),
						'status'       => $priority_text,
						'status_class' => $this->aeo_priority_pill_class( $priority ),
						'meta'         => array(
							sprintf( __( 'AEO score: %d/100', 'open-growth-seo' ), (int) ( $summary['aeo_score'] ?? 0 ) ),
							sprintf( __( 'Quick wins: %d', 'open-growth-seo' ), (int) ( $summary['quick_win_count'] ?? 0 ) ),
							sprintf( __( 'Opportunities: %d', 'open-growth-seo' ), (int) ( $summary['opportunity_count'] ?? 0 ) ),
						),
						'summary'      => array(
							! empty( $summary['answer_first'] ) ? __( 'Primary answer appears early enough.', 'open-growth-seo' ) : __( 'Primary answer is not early enough.', 'open-growth-seo' ),
							sprintf( __( 'Intent signals: %s', 'open-growth-seo' ), empty( $intent ) ? __( 'none detected', 'open-growth-seo' ) : implode( ', ', array_slice( $intent, 0, 4 ) ) ),
							sprintf( __( 'Follow-up coverage: %d%%', 'open-growth-seo' ), (int) ( $summary['follow_up_coverage'] ?? 0 ) ),
							sprintf( __( 'Entities / facts / attributes: %1$d / %2$d / %3$d', 'open-growth-seo' ), (int) ( $summary['entity_count'] ?? 0 ), (int) ( $summary['fact_count'] ?? 0 ), (int) ( $summary['attribute_count'] ?? 0 ) ),
						),
						'next_action'  => $next_action,
						'actions'      => $actions,
					);
				}
				$overview['avg_score'] = $overview['analyzed'] > 0 ? (int) round( $overview['avg_score'] / $overview['analyzed'] ) : 0;
				echo '<div class="ogs-grid ogs-grid-overview ogs-aeo-overview">';
				echo '<div class="ogs-card"><h3>' . esc_html__( 'Average AEO score', 'open-growth-seo' ) . '</h3><p class="ogs-status">' . esc_html( (string) $overview['avg_score'] ) . '/100</p><p class="description">' . esc_html__( 'Average extractability and answer-clarity score across the latest analyzed content.', 'open-growth-seo' ) . '</p></div>';
				echo '<div class="ogs-card"><h3>' . esc_html__( 'Fix this first', 'open-growth-seo' ) . '</h3><p class="ogs-status ogs-status-bad">' . esc_html( (string) ( $overview['priority']['fix-this-first'] ?? 0 ) ) . '</p><p class="description">' . esc_html__( 'Pages with answer structure or readability gaps that should be addressed first.', 'open-growth-seo' ) . '</p></div>';
				echo '<div class="ogs-card"><h3>' . esc_html__( 'Quick wins', 'open-growth-seo' ) . '</h3><p class="ogs-status ogs-status-warn">' . esc_html( (string) $overview['quick_wins'] ) . '</p><p class="description">' . esc_html__( 'Fast, low-risk improvements detected across the current content sample.', 'open-growth-seo' ) . '</p></div>';
				echo '</div>';
				echo '<div class="ogs-grid ogs-grid-2">';
				foreach ( $cards as $card ) {
					$this->render_content_coaching_card(
						$card
					);
				}
				echo '</div>';
			}
		}
		if ( 'ogs-seo-sfo' === $page ) {
			echo '<p class="description">' . esc_html__( 'SFO focuses on search feature readiness: search snippets, direct answers, structured results, AI-overview-friendly clarity, and social preview quality.', 'open-growth-seo' ) . '</p>';
			$this->render_rest_action( __( 'REST SFO Analyze (latest)', 'open-growth-seo' ), 'ogs-seo/v1/sfo/analyze' );
			$this->render_rest_action( __( 'REST SFO Telemetry', 'open-growth-seo' ), 'ogs-seo/v1/sfo/telemetry' );
			$posts = get_posts(
				array(
					'post_type'      => get_post_types( array( 'public' => true ), 'names' ),
					'post_status'    => 'publish',
					'posts_per_page' => 6,
					'orderby'        => 'modified',
					'order'          => 'DESC',
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			if ( empty( $posts ) ) {
				echo '<p class="ogs-empty">' . esc_html__( 'No published content found for SFO analysis yet.', 'open-growth-seo' ) . '</p>';
			} else {
				$overview = array(
					'analyzed'      => 0,
					'avg_score'     => 0,
					'feature_ready' => 0,
					'fix_first'     => 0,
					'quick_wins'    => 0,
				);
				$cards = array();
				foreach ( $posts as $post_id ) {
					$post_id  = (int) $post_id;
					$analysis = SfoAnalyzer::analyze_post( $post_id );
					$summary  = isset( $analysis['summary'] ) && is_array( $analysis['summary'] ) ? $analysis['summary'] : array();
					$feature  = isset( $analysis['feature_readiness'] ) && is_array( $analysis['feature_readiness'] ) ? $analysis['feature_readiness'] : array();
					$trend    = isset( $analysis['trend'] ) && is_array( $analysis['trend'] ) ? $analysis['trend'] : array();
					$priority = (string) ( $summary['priority_status'] ?? 'needs-work' );
					$overview['analyzed']++;
					$overview['avg_score'] += (int) ( $summary['sfo_score'] ?? 0 );
					$overview['quick_wins'] += (int) ( $summary['quick_win_count'] ?? 0 );
					if ( 'fix-this-first' === $priority ) {
						$overview['fix_first']++;
					}
					if ( count( array_filter( $feature ) ) >= 3 ) {
						$overview['feature_ready']++;
					}
					$actions = array();
					if ( ! empty( $analysis['priority_actions'] ) && is_array( $analysis['priority_actions'] ) ) {
						foreach ( $analysis['priority_actions'] as $item ) {
							if ( is_array( $item ) && ! empty( $item['action'] ) ) {
								$actions[] = (string) $item['action'];
							}
						}
					}
					$cards[] = array(
						'title'        => get_the_title( $post_id ),
						'url'          => (string) ( get_edit_post_link( $post_id ) ?: '' ),
						'status'       => $this->sfo_priority_label( $priority ),
						'status_class' => $this->sfo_priority_pill_class( $priority ),
						'meta'         => array(
							sprintf( __( 'SFO score: %d/100', 'open-growth-seo' ), (int) ( $summary['sfo_score'] ?? 0 ) ),
							sprintf( __( 'Quick wins: %d', 'open-growth-seo' ), (int) ( $summary['quick_win_count'] ?? 0 ) ),
							sprintf( __( 'Feature readiness: %d/5', 'open-growth-seo' ), count( array_filter( $feature ) ) ),
							sprintf( __( 'Trend: %s', 'open-growth-seo' ), ucfirst( str_replace( '-', ' ', (string) ( $trend['state'] ?? 'new' ) ) ) ),
						),
						'summary'      => array(
							! empty( $feature['search_snippet'] ) ? __( 'Search snippet framing looks ready.', 'open-growth-seo' ) : __( 'Search snippet framing still needs work.', 'open-growth-seo' ),
							! empty( $feature['featured_answer'] ) ? __( 'Direct-answer structure supports search extraction.', 'open-growth-seo' ) : __( 'Direct-answer structure is weak for search extraction.', 'open-growth-seo' ),
							! empty( $feature['rich_result'] ) ? __( 'Structured result readiness looks healthy.', 'open-growth-seo' ) : __( 'Structured result readiness needs work.', 'open-growth-seo' ),
							! empty( $feature['social_preview'] ) ? __( 'Social preview coverage is present.', 'open-growth-seo' ) : __( 'Social preview coverage is thin.', 'open-growth-seo' ),
							(string) ( $trend['message'] ?? __( 'No SFO trend is available yet.', 'open-growth-seo' ) ),
						),
						'next_action'  => isset( $analysis['recommendations'][0] ) ? (string) $analysis['recommendations'][0] : __( 'No immediate recommendation.', 'open-growth-seo' ),
						'actions'      => $actions,
						'links'        => array(
							array(
								'label' => __( 'Inspect schema runtime', 'open-growth-seo' ),
								'url'   => admin_url( 'admin.php?page=ogs-seo-schema&schema_tab=runtime&schema_inspect_post_id=' . $post_id ),
							),
						),
					);
				}
				$overview['avg_score'] = $overview['analyzed'] > 0 ? (int) round( $overview['avg_score'] / $overview['analyzed'] ) : 0;
				echo '<div class="ogs-grid ogs-grid-overview ogs-sfo-overview">';
				echo '<div class="ogs-card"><h3>' . esc_html__( 'Average SFO score', 'open-growth-seo' ) . '</h3><p class="ogs-status">' . esc_html( (string) $overview['avg_score'] ) . '/100</p><p class="description">' . esc_html__( 'Average readiness for snippets, answer extraction, rich results, and preview quality.', 'open-growth-seo' ) . '</p></div>';
				echo '<div class="ogs-card"><h3>' . esc_html__( 'Feature-ready pages', 'open-growth-seo' ) . '</h3><p class="ogs-status ogs-status-good">' . esc_html( (string) $overview['feature_ready'] ) . '</p><p class="description">' . esc_html__( 'Pages that already meet most core search feature readiness checks in the current sample.', 'open-growth-seo' ) . '</p></div>';
				echo '<div class="ogs-card"><h3>' . esc_html__( 'Fix this first', 'open-growth-seo' ) . '</h3><p class="ogs-status ogs-status-bad">' . esc_html( (string) $overview['fix_first'] ) . '</p><p class="description">' . esc_html__( 'Pages with indexability, snippet, schema, or answer-quality gaps blocking feature readiness.', 'open-growth-seo' ) . '</p></div>';
				echo '<div class="ogs-card"><h3>' . esc_html__( 'Quick wins', 'open-growth-seo' ) . '</h3><p class="ogs-status ogs-status-warn">' . esc_html( (string) $overview['quick_wins'] ) . '</p><p class="description">' . esc_html__( 'Fast fixes detected across the current content sample.', 'open-growth-seo' ) . '</p></div>';
				echo '</div>';
				$telemetry = SfoAnalyzer::telemetry( 6 );
				$rollup    = isset( $telemetry['rollup'] ) && is_array( $telemetry['rollup'] ) ? $telemetry['rollup'] : array();
				$queue     = isset( $rollup['remediation_queue'] ) && is_array( $rollup['remediation_queue'] ) ? $rollup['remediation_queue'] : array();
				$trend_mix = isset( $rollup['trend_mix'] ) && is_array( $rollup['trend_mix'] ) ? $rollup['trend_mix'] : array();
				$entries   = isset( $telemetry['entries'] ) && is_array( $telemetry['entries'] ) ? $telemetry['entries'] : array();
				$entry_map = array();
				foreach ( $entries as $entry ) {
					if ( is_array( $entry ) && ! empty( $entry['post_id'] ) ) {
						$entry_map[ (int) $entry['post_id'] ] = $entry;
					}
				}
				echo '<div class="ogs-card ogs-sfo-telemetry">';
				echo '<h3>' . esc_html__( 'Cross-page SFO rollup', 'open-growth-seo' ) . '</h3>';
				echo '<p class="description">' . esc_html__( 'Use this when SFO becomes an ongoing monitoring workflow instead of a one-off page review.', 'open-growth-seo' ) . '</p>';
				echo '<div class="ogs-editor-grid">';
				echo '<p><strong>' . esc_html__( 'Average score', 'open-growth-seo' ) . ':</strong> ' . esc_html( (string) ( $rollup['average_sfo_score'] ?? 0 ) ) . '/100</p>';
				echo '<p><strong>' . esc_html__( 'Schema attention', 'open-growth-seo' ) . ':</strong> ' . esc_html( (string) ( $rollup['schema_attention'] ?? 0 ) ) . '</p>';
				echo '<p><strong>' . esc_html__( 'Improving', 'open-growth-seo' ) . ':</strong> ' . esc_html( (string) ( $trend_mix['improving'] ?? 0 ) ) . '</p>';
				echo '<p><strong>' . esc_html__( 'Declining', 'open-growth-seo' ) . ':</strong> ' . esc_html( (string) ( $trend_mix['declining'] ?? 0 ) ) . '</p>';
				echo '</div>';
				if ( ! empty( $queue ) ) {
					echo '<p><strong>' . esc_html__( 'Repeated remediation patterns', 'open-growth-seo' ) . '</strong></p><ul class="ogs-coaching-list">';
					foreach ( $queue as $action => $count ) {
						echo '<li>' . esc_html( sprintf( __( '%1$s (%2$d pages)', 'open-growth-seo' ), (string) $action, (int) $count ) ) . '</li>';
					}
					echo '</ul>';
				}
				echo '</div>';
				$history_rows = array();
				foreach ( $posts as $post_id ) {
					$post_id = (int) $post_id;
					$history = SfoAnalyzer::history( $post_id, 2 );
					if ( empty( $history[0] ) || ! is_array( $history[0] ) ) {
						continue;
					}
					$current  = $history[0];
					$previous = isset( $history[1] ) && is_array( $history[1] ) ? $history[1] : array();
					$history_rows[] = array(
						'post_id'  => $post_id,
						'title'    => (string) get_the_title( $post_id ),
						'captured' => (int) ( $current['captured_at'] ?? 0 ),
						'current'  => (int) ( $current['sfo_score'] ?? 0 ),
						'previous' => (int) ( $previous['sfo_score'] ?? 0 ),
						'trend'    => (string) ( $entry_map[ $post_id ]['trend'] ?? 'new' ),
					);
				}
				if ( ! empty( $history_rows ) ) {
					echo '<div class="ogs-card ogs-sfo-history">';
					echo '<h3>' . esc_html__( 'Recent SFO snapshot history', 'open-growth-seo' ) . '</h3>';
					echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Page', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Captured', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Current', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Previous', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Trend', 'open-growth-seo' ) . '</th></tr></thead><tbody>';
					foreach ( $history_rows as $row ) {
						echo '<tr>';
						echo '<td><a href="' . esc_url( get_edit_post_link( (int) $row['post_id'] ) ?: '' ) . '">' . esc_html( $row['title'] ) . '</a></td>';
						echo '<td>' . esc_html( ! empty( $row['captured'] ) ? wp_date( 'Y-m-d H:i', (int) $row['captured'] ) : __( 'Just now', 'open-growth-seo' ) ) . '</td>';
						echo '<td>' . esc_html( (string) $row['current'] ) . '/100</td>';
						echo '<td>' . esc_html( (string) $row['previous'] ) . '/100</td>';
						echo '<td>' . esc_html( ucfirst( str_replace( '-', ' ', (string) $row['trend'] ) ) ) . '</td>';
						echo '</tr>';
					}
					echo '</tbody></table>';
					echo '</div>';
				}
				echo '<div class="ogs-grid ogs-grid-2">';
				foreach ( $cards as $card ) {
					$this->render_content_coaching_card( $card );
				}
				echo '</div>';
			}
		}
		if ( 'ogs-seo-sitemaps' === $page ) {
			echo '<p class="description">' . esc_html__( 'Only indexable URLs are included. noindex and non-self canonical URLs are excluded to reduce conflicts.', 'open-growth-seo' ) . '</p>';
			$this->field_select(
				'sitemap_enabled',
				__( 'Enable XML Sitemaps', 'open-growth-seo' ),
				(string) $settings['sitemap_enabled'],
				array(
					'1' => __( 'Enabled', 'open-growth-seo' ),
					'0' => __( 'Disabled', 'open-growth-seo' ),
				)
			);
			$this->field_checkboxes( 'sitemap_post_types', __( 'Included Post Types', 'open-growth-seo' ), (array) $settings['sitemap_post_types'], $this->public_post_type_options() );
			echo '<p><a class="button" target="_blank" rel="noopener noreferrer" href="' . esc_url( home_url( '/ogs-sitemap.xml' ) ) . '">' . esc_html__( 'Open Sitemap Index', 'open-growth-seo' ) . '</a></p>';
			echo '<div class="ogs-actions">';
			$this->render_rest_action( __( 'REST Status', 'open-growth-seo' ), 'ogs-seo/v1/sitemaps/status', false );
			$this->render_rest_action( __( 'REST Inspect', 'open-growth-seo' ), 'ogs-seo/v1/sitemaps/inspect', false );
			echo '</div>';
			echo '<p class="description">' . esc_html( sprintf( __( 'Cache version: %s', 'open-growth-seo' ), (string) get_option( 'ogs_seo_sitemap_cache_version', '1' ) ) ) . '</p>';
		}
		if ( 'ogs-seo-redirects' === $page ) {
			( new RedirectsPage( $this ) )->render( $settings );
		}
		if ( 'ogs-seo-bots' === $page ) {
			$public      = (bool) get_option( 'blog_public' );
			$preview_bot = isset( $_GET['ogs_bot_preview'] ) ? sanitize_text_field( wp_unslash( $_GET['ogs_bot_preview'] ) ) : 'GPTBot';
			$content     = Robots::build_content( $settings, $public );
			$preview     = Robots::preview_for_bot( $content, $preview_bot );

			echo '<p class="description">' . esc_html__( 'robots.txt controls crawling only. Use noindex settings for deindexing.', 'open-growth-seo' ) . '</p>';
			echo '<p class="description">' . esc_html__( 'Managed mode gives safe bot controls for most sites. Expert mode is for full raw rules and requires valid robots syntax.', 'open-growth-seo' ) . '</p>';
			if ( Robots::has_physical_robots() ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'A physical robots.txt file exists in site root. Web server may serve it before WordPress virtual rules.', 'open-growth-seo' ) . '</p></div>';
			}
			$this->field_select(
				'robots_mode',
				__( 'Editing Mode', 'open-growth-seo' ),
				$settings['robots_mode'],
				array(
					'managed' => __( 'Visual (managed)', 'open-growth-seo' ),
					'expert' => __( 'Expert (raw rules)', 'open-growth-seo' ),
				)
			);
			$this->field_select(
				'robots_global_policy',
				__( 'Global crawler policy', 'open-growth-seo' ),
				$settings['robots_global_policy'],
				array(
					'allow' => __( 'Allow crawl', 'open-growth-seo' ),
					'disallow' => __( 'Disallow crawl (dangerous)', 'open-growth-seo' ),
				)
			);
			$this->field_select(
				'bots_gptbot',
				__( 'GPTBot', 'open-growth-seo' ),
				$settings['bots_gptbot'],
				array(
					'allow' => __( 'Allow', 'open-growth-seo' ),
					'disallow' => __( 'Disallow', 'open-growth-seo' ),
				)
			);
			$this->field_select(
				'bots_oai_searchbot',
				__( 'OAI-SearchBot', 'open-growth-seo' ),
				$settings['bots_oai_searchbot'],
				array(
					'allow' => __( 'Allow', 'open-growth-seo' ),
					'disallow' => __( 'Disallow', 'open-growth-seo' ),
				)
			);
			$this->field_textarea( 'robots_custom', __( 'Additional rules (managed mode)', 'open-growth-seo' ), $settings['robots_custom'] );
			$this->field_textarea( 'robots_expert_rules', __( 'Expert robots.txt rules', 'open-growth-seo' ), $settings['robots_expert_rules'] );
			echo '<p><strong>' . esc_html__( 'Simulate by bot', 'open-growth-seo' ) . ':</strong> ';
			echo '<a class="button" href="' . esc_url( add_query_arg( 'ogs_bot_preview', 'GPTBot' ) ) . '">GPTBot</a> ';
			echo '<a class="button" href="' . esc_url( add_query_arg( 'ogs_bot_preview', 'OAI-SearchBot' ) ) . '">OAI-SearchBot</a> ';
			echo '<a class="button" href="' . esc_url( add_query_arg( 'ogs_bot_preview', '*' ) ) . '">* (generic)</a></p>';
			echo '<div class="ogs-card"><p><strong>' . esc_html( sprintf( __( 'Preview for %s', 'open-growth-seo' ), $preview_bot ) ) . '</strong></p>';
			if ( empty( $preview ) ) {
				echo '<p class="ogs-empty">' . esc_html__( 'No specific rules for this bot; generic rules may apply.', 'open-growth-seo' ) . '</p>';
			} else {
				echo '<pre>' . esc_html( implode( "\n", $preview ) ) . '</pre>';
			}
			echo '<p class="description">' . esc_html__( 'Rendered robots.txt output', 'open-growth-seo' ) . '</p>';
			echo '<pre>' . esc_html( $content ) . '</pre></div>';
			$bot_snapshot = BotControls::bot_policy_snapshot();
			echo '<h2>' . esc_html__( 'GEO Diagnostics', 'open-growth-seo' ) . '</h2>';
			echo '<p class="description">' . esc_html__( 'GEO diagnostics focus on crawl visibility, textual clarity, citability, schema-text consistency, entity signals, freshness, and internal discoverability.', 'open-growth-seo' ) . '</p>';
			$this->render_rest_action( __( 'REST GEO Analyze (latest)', 'open-growth-seo' ), 'ogs-seo/v1/geo/analyze' );
			$this->render_rest_action( __( 'REST GEO Telemetry', 'open-growth-seo' ), 'ogs-seo/v1/geo/telemetry', false );
			echo '<table class="widefat striped" style="max-width:900px"><tbody>';
			echo '<tr><th>' . esc_html__( 'Global crawl policy', 'open-growth-seo' ) . '</th><td>' . esc_html( (string) $bot_snapshot['global_policy'] ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'GPTBot', 'open-growth-seo' ) . '</th><td>' . esc_html( (string) $bot_snapshot['gptbot'] ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'OAI-SearchBot', 'open-growth-seo' ) . '</th><td>' . esc_html( (string) $bot_snapshot['oai_searchbot'] ) . '</td></tr>';
			echo '</tbody></table>';
			$geo_telemetry = BotControls::telemetry( 8 );
			$geo_rollup = isset( $geo_telemetry['rollup'] ) && is_array( $geo_telemetry['rollup'] ) ? $geo_telemetry['rollup'] : array();
			if ( ! empty( $geo_rollup ) ) {
				echo '<h3>' . esc_html__( 'Cross-page GEO rollup', 'open-growth-seo' ) . '</h3>';
				echo '<table class="widefat striped" style="max-width:960px"><tbody>';
				echo '<tr><th>' . esc_html__( 'Pages sampled', 'open-growth-seo' ) . '</th><td>' . esc_html( (string) (int) ( $geo_rollup['total_pages'] ?? 0 ) ) . '</td></tr>';
				echo '<tr><th>' . esc_html__( 'Average score', 'open-growth-seo' ) . '</th><td>' . esc_html( (string) (int) ( $geo_rollup['average_score'] ?? 0 ) ) . '/100</td></tr>';
				echo '<tr><th>' . esc_html__( 'Priority mix', 'open-growth-seo' ) . '</th><td>' . esc_html( sprintf( __( 'Strong %1$d / Needs work %2$d / Fix this first %3$d', 'open-growth-seo' ), (int) ( $geo_rollup['priority']['strong'] ?? 0 ), (int) ( $geo_rollup['priority']['needs-work'] ?? 0 ), (int) ( $geo_rollup['priority']['fix-this-first'] ?? 0 ) ) ) . '</td></tr>';
				echo '<tr><th>' . esc_html__( 'Trend mix', 'open-growth-seo' ) . '</th><td>' . esc_html( sprintf( __( 'Improving %1$d / Stable %2$d / Declining %3$d / New %4$d', 'open-growth-seo' ), (int) ( $geo_rollup['trend']['improving'] ?? 0 ), (int) ( $geo_rollup['trend']['stable'] ?? 0 ), (int) ( $geo_rollup['trend']['declining'] ?? 0 ), (int) ( $geo_rollup['trend']['new'] ?? 0 ) ) ) . '</td></tr>';
				echo '<tr><th>' . esc_html__( 'Bot exposure mix', 'open-growth-seo' ) . '</th><td>' . esc_html( sprintf( __( 'Open %1$d / Search-only %2$d / Restricted %3$d', 'open-growth-seo' ), (int) ( $geo_rollup['bot_exposure']['open'] ?? 0 ), (int) ( $geo_rollup['bot_exposure']['search-only'] ?? 0 ), (int) ( $geo_rollup['bot_exposure']['restricted'] ?? 0 ) ) ) . '</td></tr>';
				echo '<tr><th>' . esc_html__( 'Schema runtime attention', 'open-growth-seo' ) . '</th><td>' . esc_html( (string) (int) ( $geo_rollup['schema_attention'] ?? 0 ) ) . '</td></tr>';
				echo '</tbody></table>';
				$remediation_queue = isset( $geo_rollup['remediation_queue'] ) && is_array( $geo_rollup['remediation_queue'] ) ? $geo_rollup['remediation_queue'] : array();
				if ( ! empty( $remediation_queue ) ) {
					echo '<p><strong>' . esc_html__( 'Top remediation patterns', 'open-growth-seo' ) . '</strong></p><ul class="ogs-coaching-actions">';
					foreach ( $remediation_queue as $label => $count ) {
						echo '<li>' . esc_html( sprintf( __( '%1$s (%2$d page(s))', 'open-growth-seo' ), (string) $label, (int) $count ) ) . '</li>';
					}
					echo '</ul>';
				}
			}

			$geo_posts = get_posts(
				array(
					'post_type'      => array_values( array_diff( get_post_types( array( 'public' => true ), 'names' ), array( 'attachment' ) ) ),
					'post_status'    => 'publish',
					'posts_per_page' => 6,
					'orderby'        => 'modified',
					'order'          => 'DESC',
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			if ( empty( $geo_posts ) ) {
				echo '<p class="ogs-empty">' . esc_html__( 'No published content found for GEO diagnostics yet.', 'open-growth-seo' ) . '</p>';
			} else {
				$overview = array(
					'analyzed'    => 0,
					'avg_score'   => 0,
					'priority'    => array(
						'strong'         => 0,
						'needs-work'     => 0,
						'fix-this-first' => 0,
					),
					'quick_wins'  => 0,
					'restrictive' => 0,
					'improving'   => 0,
					'declining'   => 0,
				);
				$cards = array();
				$history_rows = array();
				foreach ( $geo_posts as $post_id ) {
					$post_id  = (int) $post_id;
					$analysis = BotControls::analyze_post( $post_id );
					$summary  = isset( $analysis['summary'] ) && is_array( $analysis['summary'] ) ? $analysis['summary'] : array();
					$signals  = isset( $analysis['signals'] ) && is_array( $analysis['signals'] ) ? $analysis['signals'] : array();
					$fresh    = isset( $signals['freshness']['days_since_modified'] ) ? (int) $signals['freshness']['days_since_modified'] : -1;
					$priority = (string) ( $summary['priority_status'] ?? 'needs-work' );
					$next_action = isset( $analysis['priority_actions'][0]['action'] ) ? (string) $analysis['priority_actions'][0]['action'] : ( isset( $analysis['recommendations'][0] ) ? (string) $analysis['recommendations'][0] : __( 'No immediate recommendation.', 'open-growth-seo' ) );
					$trend = isset( $analysis['trend'] ) && is_array( $analysis['trend'] ) ? $analysis['trend'] : array();
					$history = isset( $analysis['history'] ) && is_array( $analysis['history'] ) ? $analysis['history'] : array();
					$schema_runtime = isset( $signals['schema_runtime'] ) && is_array( $signals['schema_runtime'] ) ? $signals['schema_runtime'] : array();
					$actions = array();
					if ( ! empty( $analysis['priority_actions'] ) && is_array( $analysis['priority_actions'] ) ) {
						foreach ( $analysis['priority_actions'] as $item ) {
							if ( is_array( $item ) && ! empty( $item['action'] ) ) {
								$actions[] = (string) $item['action'];
							}
						}
					}
					$overview['analyzed']++;
					$overview['avg_score'] += (int) ( $summary['geo_score'] ?? 0 );
					$overview['quick_wins'] += (int) ( $summary['quick_win_count'] ?? 0 );
					if ( 'restricted' === (string) ( $summary['bot_exposure'] ?? '' ) ) {
						$overview['restrictive']++;
					}
					if ( 'improving' === (string) ( $trend['state'] ?? '' ) ) {
						$overview['improving']++;
					} elseif ( 'declining' === (string) ( $trend['state'] ?? '' ) ) {
						$overview['declining']++;
					}
					if ( isset( $overview['priority'][ $priority ] ) ) {
						$overview['priority'][ $priority ]++;
					}
					$meta = array(
						sprintf( __( 'GEO score: %d/100', 'open-growth-seo' ), (int) ( $summary['geo_score'] ?? 0 ) ),
						sprintf( __( 'Quick wins: %d', 'open-growth-seo' ), (int) ( $summary['quick_win_count'] ?? 0 ) ),
						$this->geo_bot_exposure_label( (string) ( $summary['bot_exposure'] ?? 'restricted' ) ),
					);
					if ( ! empty( $trend['state'] ) && 'new' !== (string) $trend['state'] ) {
						$score_delta = isset( $trend['geo_score_delta'] ) ? (int) $trend['geo_score_delta'] : 0;
						$meta[] = sprintf( __( 'Trend: %1$s (%2$s%d)', 'open-growth-seo' ), ucfirst( (string) $trend['state'] ), $score_delta >= 0 ? '+' : '', $score_delta );
					}
					$links = array();
					if ( ! empty( $summary['schema_runtime_attention'] ) ) {
						$links[] = array(
							'label' => __( 'Inspect schema runtime', 'open-growth-seo' ),
							'url'   => admin_url( 'admin.php?page=ogs-seo-schema&schema_tab=runtime&schema_inspect_post_id=' . $post_id ),
						);
					}
					$cards[] = array(
						'title'        => get_the_title( $post_id ),
						'url'          => (string) ( get_edit_post_link( $post_id ) ?: '' ),
						'status'       => $this->geo_priority_label( $priority ),
						'status_class' => $this->geo_priority_pill_class( $priority ),
						'meta'         => $meta,
						'summary'      => array(
							! empty( $summary['critical_text_visible'] ) ? __( 'Critical content is visible in text.', 'open-growth-seo' ) : __( 'Critical content is too dependent on non-text elements.', 'open-growth-seo' ),
							! empty( $summary['schema_text_mismatch'] ) ? __( 'Schema and visible text may conflict.', 'open-growth-seo' ) : __( 'Schema and visible text look aligned.', 'open-growth-seo' ),
							sprintf( __( 'Citability: %s', 'open-growth-seo' ), $this->geo_citability_label( (int) ( $summary['citability_score'] ?? 0 ) ) ),
							sprintf( __( 'Freshness: %s', 'open-growth-seo' ), $this->geo_freshness_label( $fresh ) ),
							! empty( $schema_runtime['message'] ) ? sprintf( __( 'Schema runtime: %s', 'open-growth-seo' ), (string) $schema_runtime['message'] ) : __( 'Schema runtime looks aligned for current page context.', 'open-growth-seo' ),
						),
						'next_action'  => $next_action,
						'actions'      => $actions,
						'links'        => $links,
					);
					$history_rows[] = array(
						'title'        => get_the_title( $post_id ),
						'captured_at'  => isset( $history[0]['captured_at'] ) ? (int) $history[0]['captured_at'] : 0,
						'current'      => isset( $history[0]['geo_score'] ) ? (int) $history[0]['geo_score'] : (int) ( $summary['geo_score'] ?? 0 ),
						'previous'     => isset( $history[1]['geo_score'] ) ? (int) $history[1]['geo_score'] : null,
						'trend'        => (string) ( $trend['state'] ?? 'new' ),
					);
				}
				$overview['avg_score'] = $overview['analyzed'] > 0 ? (int) round( $overview['avg_score'] / $overview['analyzed'] ) : 0;
				echo '<div class="ogs-grid ogs-grid-overview ogs-geo-overview">';
				echo '<div class="ogs-card"><h3>' . esc_html__( 'Average GEO score', 'open-growth-seo' ) . '</h3><p class="ogs-status">' . esc_html( (string) $overview['avg_score'] ) . '/100</p><p class="description">' . esc_html__( 'Average crawl visibility, citability, and semantic-clarity score across the latest analyzed content.', 'open-growth-seo' ) . '</p></div>';
				echo '<div class="ogs-card"><h3>' . esc_html__( 'Fix this first', 'open-growth-seo' ) . '</h3><p class="ogs-status ogs-status-bad">' . esc_html( (string) ( $overview['priority']['fix-this-first'] ?? 0 ) ) . '</p><p class="description">' . esc_html__( 'Pages with visible-text, schema-alignment, or bot-access issues that deserve first attention.', 'open-growth-seo' ) . '</p></div>';
				echo '<div class="ogs-card"><h3>' . esc_html__( 'Restrictive exposure', 'open-growth-seo' ) . '</h3><p class="ogs-status ogs-status-warn">' . esc_html( (string) $overview['restrictive'] ) . '</p><p class="description">' . esc_html__( 'Pages currently analyzed while relevant bot exposure remains restricted.', 'open-growth-seo' ) . '</p></div>';
				echo '<div class="ogs-card"><h3>' . esc_html__( 'Trend snapshot', 'open-growth-seo' ) . '</h3><p class="ogs-status">' . esc_html( sprintf( __( '%1$d improving / %2$d declining', 'open-growth-seo' ), (int) $overview['improving'], (int) $overview['declining'] ) ) . '</p><p class="description">' . esc_html__( 'Compares the latest GEO snapshot for each sampled page with the previous stored snapshot.', 'open-growth-seo' ) . '</p></div>';
				echo '</div>';
				echo '<div class="ogs-grid ogs-grid-2">';
				foreach ( $cards as $card ) {
					$this->render_content_coaching_card( $card );
				}
				echo '</div>';
				if ( ! empty( $history_rows ) ) {
					echo '<h3>' . esc_html__( 'Recent GEO snapshot history', 'open-growth-seo' ) . '</h3>';
					echo '<table class="widefat striped" style="max-width:960px"><thead><tr><th>' . esc_html__( 'Page', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Captured', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Current score', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Previous score', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Trend', 'open-growth-seo' ) . '</th></tr></thead><tbody>';
					foreach ( $history_rows as $row ) {
						echo '<tr>';
						echo '<td>' . esc_html( (string) $row['title'] ) . '</td>';
						echo '<td>' . esc_html( ! empty( $row['captured_at'] ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $row['captured_at'] ) : __( 'Not yet captured', 'open-growth-seo' ) ) . '</td>';
						echo '<td>' . esc_html( (string) (int) $row['current'] ) . '</td>';
						echo '<td>' . esc_html( null === $row['previous'] ? __( 'n/a', 'open-growth-seo' ) : (string) (int) $row['previous'] ) . '</td>';
						echo '<td>' . esc_html( ucfirst( str_replace( '-', ' ', (string) $row['trend'] ) ) ) . '</td>';
						echo '</tr>';
					}
					echo '</tbody></table>';
				}
			}
			$last_saved = (int) get_option( 'ogs_seo_robots_last_saved', 0 );
			if ( $last_saved > 0 ) {
				echo '<p class="description">' . esc_html( sprintf( __( 'Last validated save: %s', 'open-growth-seo' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_saved ) ) ) . '</p>';
			}
			echo '<p><button class="button" name="ogs_seo_action" value="restore_robots_defaults">' . esc_html__( 'Restore safe defaults', 'open-growth-seo' ) . '</button></p>';
		}
		if ( 'ogs-seo-integrations' === $page ) {
			$integration_payload = IntegrationsManager::get_status_payload();
			$integrations = isset( $integration_payload['items'] ) && is_array( $integration_payload['items'] ) ? $integration_payload['items'] : array();
			$integration_summary = isset( $integration_payload['summary'] ) && is_array( $integration_payload['summary'] ) ? $integration_payload['summary'] : array();
			echo '<p class="description">' . esc_html__( 'External integrations are optional. Core SEO, schema, AEO, GEO, and crawl controls work without them.', 'open-growth-seo' ) . '</p>';
			echo '<div class="ogs-grid ogs-grid-overview ogs-integrations-overview">';
			echo '<div class="ogs-card"><h3>' . esc_html__( 'Connected', 'open-growth-seo' ) . '</h3><p class="ogs-status ogs-status-good">' . esc_html( (string) ( $integration_summary['connected'] ?? 0 ) ) . '</p><p class="description">' . esc_html__( 'Integrations that last verified successfully.', 'open-growth-seo' ) . '</p></div>';
			echo '<div class="ogs-card"><h3>' . esc_html__( 'Needs Attention', 'open-growth-seo' ) . '</h3><p class="ogs-status ogs-status-warn">' . esc_html( (string) ( $integration_summary['attention'] ?? 0 ) ) . '</p><p class="description">' . esc_html__( 'Enabled or configured integrations that still need operator action.', 'open-growth-seo' ) . '</p></div>';
			echo '<div class="ogs-card"><h3>' . esc_html__( 'Configured', 'open-growth-seo' ) . '</h3><p class="ogs-status">' . esc_html( (string) ( $integration_summary['configured'] ?? 0 ) ) . '</p><p class="description">' . esc_html__( 'Providers with required settings and secrets stored.', 'open-growth-seo' ) . '</p></div>';
			echo '</div>';
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Integration', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Status', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Mode', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Last check', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Actions', 'open-growth-seo' ) . '</th></tr></thead><tbody>';
			foreach ( $integrations as $item ) {
				$status = isset( $item['state_label'] ) ? (string) $item['state_label'] : ( ! empty( $item['connected'] ) ? __( 'Connected', 'open-growth-seo' ) : ( ! empty( $item['configured'] ) ? __( 'Configured (not tested)', 'open-growth-seo' ) : __( 'Not configured', 'open-growth-seo' ) ) );
				$last   = ! empty( $item['last_success'] ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $item['last_success'] ) : ( ! empty( $item['checked_at'] ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $item['checked_at'] ) : __( 'Never', 'open-growth-seo' ) );
				$test_url = wp_nonce_url(
					admin_url( 'admin-post.php?action=ogs_seo_integration_test&integration=' . rawurlencode( (string) $item['slug'] ) . '&page=ogs-seo-integrations' ),
					'ogs_seo_integration_action'
				);
				$disconnect_url = wp_nonce_url(
					admin_url( 'admin-post.php?action=ogs_seo_integration_disconnect&integration=' . rawurlencode( (string) $item['slug'] ) . '&page=ogs-seo-integrations' ),
					'ogs_seo_integration_action'
				);
				echo '<tr>';
				echo '<td><strong>' . esc_html( (string) $item['label'] ) . '</strong><br/><span class="description">' . esc_html( (string) $item['message'] ) . '</span>';
				if ( ! empty( $item['missing_settings'] ) || ! empty( $item['missing_secrets'] ) ) {
					$missing_chunks = array();
					if ( ! empty( $item['missing_settings_labels'] ) ) {
						$missing_chunks[] = sprintf( __( 'Settings: %s', 'open-growth-seo' ), implode( ', ', array_map( 'esc_html', (array) $item['missing_settings_labels'] ) ) );
					}
					if ( ! empty( $item['missing_secrets_labels'] ) ) {
						$missing_chunks[] = sprintf( __( 'Secrets: %s', 'open-growth-seo' ), implode( ', ', array_map( 'esc_html', (array) $item['missing_secrets_labels'] ) ) );
					}
					echo '<br/><span class="description">' . esc_html( implode( ' | ', $missing_chunks ) ) . '</span>';
				}
				if ( ! empty( $item['last_error'] ) ) {
					echo '<br/><span style="color:#b32d2e;">' . esc_html( (string) $item['last_error'] ) . '</span>';
				}
				echo '</td>';
				echo '<td><span class="ogs-schema-chip ogs-schema-chip-' . esc_attr( (string) ( $item['severity'] ?? 'minor' ) ) . '">' . esc_html( $status ) . '</span></td>';
				echo '<td>' . esc_html( (string) $item['mode'] ) . '</td>';
				echo '<td>' . esc_html( $last ) . '</td>';
				echo '<td><a class="button" href="' . esc_url( $test_url ) . '">' . esc_html__( 'Test connection', 'open-growth-seo' ) . '</a> ';
				echo '<a class="button" href="' . esc_url( $disconnect_url ) . '">' . esc_html__( 'Disconnect', 'open-growth-seo' ) . '</a></td>';
				echo '</tr>';
			}
			echo '</tbody></table>';

			$gsc_report = IntegrationsManager::google_search_console_operational_report( false );
			$gsc_status = isset( $gsc_report['state_label'] ) ? (string) $gsc_report['state_label'] : __( 'Needs work', 'open-growth-seo' );
			echo '<h2>' . esc_html__( 'Search Console Operational Report', 'open-growth-seo' ) . '</h2>';
			echo '<p class="description">' . esc_html__( 'Operational report prioritizes indexing visibility and next actions. It uses cached data by default and only refreshes on explicit request.', 'open-growth-seo' ) . '</p>';
			$this->render_rest_action( __( 'REST Refresh GSC Report', 'open-growth-seo' ), 'ogs-seo/v1/integrations/gsc-operational?refresh=1' );
			echo '<p><strong>' . esc_html__( 'Status', 'open-growth-seo' ) . ':</strong> ' . esc_html( $gsc_status ) . '</p>';
			echo '<p class="description">' . esc_html( (string) ( $gsc_report['message'] ?? __( 'No report data available yet.', 'open-growth-seo' ) ) ) . '</p>';
			if ( ! empty( $gsc_report['fetched_at'] ) ) {
				echo '<p class="description">' . esc_html( sprintf( __( 'Last fetched: %s', 'open-growth-seo' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), absint( $gsc_report['fetched_at'] ) ) ) ) . '</p>';
			}
			if ( ! empty( $gsc_report['stale'] ) ) {
				echo '<p class="description" style="color:#8a6100;">' . esc_html__( 'This report is currently using stale cached data because live refresh is unavailable.', 'open-growth-seo' ) . '</p>';
			}
			if ( ! empty( $gsc_report['totals'] ) && is_array( $gsc_report['totals'] ) ) {
				echo '<table class="widefat striped" style="max-width:640px"><tbody>';
				echo '<tr><th>' . esc_html__( 'Clicks (current window)', 'open-growth-seo' ) . '</th><td>' . esc_html( (string) ( $gsc_report['totals']['clicks'] ?? 0 ) ) . '</td></tr>';
				echo '<tr><th>' . esc_html__( 'Impressions (current window)', 'open-growth-seo' ) . '</th><td>' . esc_html( (string) ( $gsc_report['totals']['impressions'] ?? 0 ) ) . '</td></tr>';
				echo '</tbody></table>';
			}
			if ( ! empty( $gsc_report['trend'] ) && is_array( $gsc_report['trend'] ) ) {
				$trend = $gsc_report['trend'];
				echo '<p><strong>' . esc_html__( 'Trend snapshot (vs previous window)', 'open-growth-seo' ) . '</strong></p>';
				echo '<ul>';
				echo '<li>' . esc_html( sprintf( __( 'Clicks delta: %s', 'open-growth-seo' ), isset( $trend['clicks_delta_pct'] ) ? sprintf( '%+.2f%%', (float) $trend['clicks_delta_pct'] ) : __( 'Unavailable', 'open-growth-seo' ) ) ) . '</li>';
				echo '<li>' . esc_html( sprintf( __( 'Impressions delta: %s', 'open-growth-seo' ), isset( $trend['impressions_delta_pct'] ) ? sprintf( '%+.2f%%', (float) $trend['impressions_delta_pct'] ) : __( 'Unavailable', 'open-growth-seo' ) ) ) . '</li>';
				echo '<li>' . esc_html( sprintf( __( 'CTR delta (pp): %s', 'open-growth-seo' ), isset( $trend['ctr_delta_pp'] ) ? sprintf( '%+.2f', (float) $trend['ctr_delta_pp'] ) : __( 'Unavailable', 'open-growth-seo' ) ) ) . '</li>';
				echo '<li>' . esc_html( sprintf( __( 'Average position delta: %s', 'open-growth-seo' ), isset( $trend['position_delta'] ) ? sprintf( '%+.2f', (float) $trend['position_delta'] ) : __( 'Unavailable', 'open-growth-seo' ) ) ) . '</li>';
				echo '</ul>';
			} elseif ( empty( $settings['seo_masters_plus_gsc_trends'] ) ) {
				echo '<p class="description">' . esc_html__( 'Trend snapshots are disabled in SEO MASTERS PLUS settings.', 'open-growth-seo' ) . '</p>';
			}
			if ( ! empty( $gsc_report['issue_trends'] ) && is_array( $gsc_report['issue_trends'] ) ) {
				echo '<p><strong>' . esc_html__( 'Issue trend summary', 'open-growth-seo' ) . '</strong></p><ul>';
				echo '<li>' . esc_html( sprintf( __( 'Low CTR opportunities: %d', 'open-growth-seo' ), (int) ( $gsc_report['issue_trends']['low_ctr_opportunities'] ?? 0 ) ) ) . '</li>';
				echo '<li>' . esc_html( sprintf( __( 'Weak position pages: %d', 'open-growth-seo' ), (int) ( $gsc_report['issue_trends']['weak_position_pages'] ?? 0 ) ) ) . '</li>';
				echo '<li>' . esc_html( sprintf( __( 'Improving pages in top set: %d', 'open-growth-seo' ), (int) ( $gsc_report['issue_trends']['improving_pages'] ?? 0 ) ) ) . '</li>';
				echo '</ul>';
			}
			if ( ! empty( $gsc_report['next_actions'] ) && is_array( $gsc_report['next_actions'] ) ) {
				echo '<p><strong>' . esc_html__( 'Next actions', 'open-growth-seo' ) . '</strong></p><ul>';
				foreach ( array_slice( (array) $gsc_report['next_actions'], 0, 3 ) as $action ) {
					echo '<li>' . esc_html( (string) $action ) . '</li>';
				}
				echo '</ul>';
			}
			if ( ! empty( $gsc_report['top_pages'] ) && is_array( $gsc_report['top_pages'] ) ) {
				echo '<table class="widefat striped" style="max-width:920px"><thead><tr><th>' . esc_html__( 'Page', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Clicks', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Impressions', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'CTR %', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Avg position', 'open-growth-seo' ) . '</th></tr></thead><tbody>';
				foreach ( array_slice( (array) $gsc_report['top_pages'], 0, 8 ) as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					echo '<tr><td><code>' . esc_html( (string) ( $row['page'] ?? '' ) ) . '</code></td><td>' . esc_html( (string) ( $row['clicks'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $row['impressions'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $row['ctr'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $row['position'] ?? '' ) ) . '</td></tr>';
				}
				echo '</tbody></table>';
			}

			echo '<h2>' . esc_html__( 'Google Search Console', 'open-growth-seo' ) . '</h2>';
			$this->field_select(
				'gsc_enabled',
				__( 'Enable Google Search Console', 'open-growth-seo' ),
				(string) $settings['gsc_enabled'],
				array(
					'1' => __( 'Enabled', 'open-growth-seo' ),
					'0' => __( 'Disabled', 'open-growth-seo' ),
				)
			);
			$this->field_text( 'gsc_property_url', __( 'Property URL', 'open-growth-seo' ), (string) $settings['gsc_property_url'] );
			$this->field_secret( 'google_search_console', 'client_id', __( 'OAuth Client ID', 'open-growth-seo' ) );
			$this->field_secret( 'google_search_console', 'client_secret', __( 'OAuth Client Secret', 'open-growth-seo' ) );
			$this->field_secret( 'google_search_console', 'refresh_token', __( 'OAuth Refresh Token', 'open-growth-seo' ) );

			echo '<h2>' . esc_html__( 'Bing Webmaster Tools', 'open-growth-seo' ) . '</h2>';
			$this->field_select(
				'bing_enabled',
				__( 'Enable Bing Webmaster', 'open-growth-seo' ),
				(string) $settings['bing_enabled'],
				array(
					'1' => __( 'Enabled', 'open-growth-seo' ),
					'0' => __( 'Disabled', 'open-growth-seo' ),
				)
			);
			$this->field_text( 'bing_site_url', __( 'Site URL', 'open-growth-seo' ), (string) $settings['bing_site_url'] );
			$this->field_secret( 'bing_webmaster', 'api_key', __( 'Bing API Key', 'open-growth-seo' ) );

			echo '<h2>' . esc_html__( 'GA4 (Optional)', 'open-growth-seo' ) . '</h2>';
			$this->field_select(
				'ga4_enabled',
				__( 'Enable GA4 helper', 'open-growth-seo' ),
				(string) $settings['ga4_enabled'],
				array(
					'1' => __( 'Enabled', 'open-growth-seo' ),
					'0' => __( 'Disabled', 'open-growth-seo' ),
				)
			);
			$this->field_text( 'ga4_measurement_id', __( 'GA4 Measurement ID', 'open-growth-seo' ), (string) $settings['ga4_measurement_id'] );
			$this->field_secret( 'ga4_reporting', 'api_secret', __( 'GA4 API Secret', 'open-growth-seo' ) );
			echo '<p class="description">' . esc_html__( 'GA4 integration is optional and limited to complementary diagnostics. Core SEO logic does not depend on analytics connectivity.', 'open-growth-seo' ) . '</p>';

			echo '<h2>' . esc_html__( 'IndexNow', 'open-growth-seo' ) . '</h2>';
			$this->field_select(
				'indexnow_enabled',
				__( 'Enable IndexNow', 'open-growth-seo' ),
				(string) $settings['indexnow_enabled'],
				array(
					'1' => __( 'Enabled', 'open-growth-seo' ),
					'0' => __( 'Disabled', 'open-growth-seo' ),
				)
			);
			$this->field_text( 'indexnow_key', __( 'IndexNow Key', 'open-growth-seo' ), (string) $settings['indexnow_key'] );
			$this->field_text( 'indexnow_endpoint', __( 'IndexNow Endpoint', 'open-growth-seo' ), (string) $settings['indexnow_endpoint'] );
			$this->field_text( 'indexnow_batch_size', __( 'Batch size (1-100)', 'open-growth-seo' ), (string) $settings['indexnow_batch_size'] );
			$this->field_text( 'indexnow_rate_limit_seconds', __( 'Rate limit seconds', 'open-growth-seo' ), (string) $settings['indexnow_rate_limit_seconds'] );
			$this->field_text( 'indexnow_max_retries', __( 'Max retries per URL', 'open-growth-seo' ), (string) $settings['indexnow_max_retries'] );
			$this->field_text( 'indexnow_queue_max_size', __( 'Max queue size', 'open-growth-seo' ), (string) $settings['indexnow_queue_max_size'] );
			$indexnow_status = ( new IndexNowIntegration() )->inspect_status();
			$key_generate_url = wp_nonce_url( admin_url( 'admin-post.php?action=ogs_seo_indexnow_generate_key' ), 'ogs_seo_indexnow_action' );
			$key_verify_url   = wp_nonce_url( admin_url( 'admin-post.php?action=ogs_seo_indexnow_verify_key' ), 'ogs_seo_indexnow_action' );
			$process_url      = wp_nonce_url( admin_url( 'admin-post.php?action=ogs_seo_indexnow_process_now' ), 'ogs_seo_indexnow_action' );
			$requeue_failed_url = wp_nonce_url( admin_url( 'admin-post.php?action=ogs_seo_indexnow_requeue_failed' ), 'ogs_seo_indexnow_action' );
			$clear_failed_url   = wp_nonce_url( admin_url( 'admin-post.php?action=ogs_seo_indexnow_clear_failed' ), 'ogs_seo_indexnow_action' );
			$release_lock_url   = wp_nonce_url( admin_url( 'admin-post.php?action=ogs_seo_indexnow_release_lock' ), 'ogs_seo_indexnow_action' );
			$queue_runner       = isset( $indexnow_status['queue']['runner'] ) && is_array( $indexnow_status['queue']['runner'] ) ? $indexnow_status['queue']['runner'] : array();
			$is_running         = ! empty( $queue_runner['is_running'] );
			echo '<p><a class="button" href="' . esc_url( $key_generate_url ) . '">' . esc_html__( 'Generate Key', 'open-growth-seo' ) . '</a> ';
			echo '<a class="button" href="' . esc_url( $key_verify_url ) . '">' . esc_html__( 'Verify Key', 'open-growth-seo' ) . '</a> ';
			echo '<a class="button button-primary" href="' . esc_url( $process_url ) . '">' . esc_html__( 'Process Queue Now', 'open-growth-seo' ) . '</a> ';
			echo '<a class="button" href="' . esc_url( $requeue_failed_url ) . '">' . esc_html__( 'Requeue Failed URLs', 'open-growth-seo' ) . '</a> ';
			echo '<a class="button" href="' . esc_url( $clear_failed_url ) . '">' . esc_html__( 'Clear Failed History', 'open-growth-seo' ) . '</a> ';
			if ( $is_running ) {
				echo '<a class="button" href="' . esc_url( $release_lock_url ) . '">' . esc_html__( 'Release Queue Lock', 'open-growth-seo' ) . '</a>';
			}
			echo '</p>';
			echo '<div class="ogs-seo-installation-status-card ogs-seo-jobs-status-card">';
			echo '<h3>' . esc_html__( 'Queue Health', 'open-growth-seo' ) . '</h3>';
			echo '<p class="description">' . esc_html__( 'Background processing uses WordPress cron with queue claiming and lock protection. Use recovery actions only when the queue looks stalled.', 'open-growth-seo' ) . '</p>';
			echo '<table class="widefat striped" style="max-width:920px"><tbody>';
			echo '<tr><th>' . esc_html__( 'Queue pending', 'open-growth-seo' ) . '</th><td>' . esc_html( (string) ( $indexnow_status['queue']['pending'] ?? 0 ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Due now', 'open-growth-seo' ) . '</th><td>' . esc_html( (string) ( $indexnow_status['queue']['due_now'] ?? 0 ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Claimed in progress', 'open-growth-seo' ) . '</th><td>' . esc_html( (string) ( $indexnow_status['queue']['claimed'] ?? 0 ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Runner state', 'open-growth-seo' ) . '</th><td>' . esc_html( $is_running ? __( 'Running', 'open-growth-seo' ) : __( 'Idle', 'open-growth-seo' ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Next scheduled run', 'open-growth-seo' ) . '</th><td>' . esc_html( ! empty( $indexnow_status['queue']['schedule']['next_scheduled'] ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $indexnow_status['queue']['schedule']['next_scheduled'] ) : __( 'Not scheduled', 'open-growth-seo' ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Last sent', 'open-growth-seo' ) . '</th><td>' . esc_html( ! empty( $indexnow_status['last_sent'] ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $indexnow_status['last_sent'] ) : __( 'Never', 'open-growth-seo' ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Key verified', 'open-growth-seo' ) . '</th><td>' . esc_html( ! empty( $indexnow_status['key_verified'] ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $indexnow_status['key_verified'] ) : __( 'Not verified yet', 'open-growth-seo' ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Last status', 'open-growth-seo' ) . '</th><td>' . esc_html( (string) ( $indexnow_status['last_status']['message'] ?? __( 'No status yet.', 'open-growth-seo' ) ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Last run result', 'open-growth-seo' ) . '</th><td>' . esc_html( (string) ( $queue_runner['last_status'] ?? __( 'idle', 'open-growth-seo' ) ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Last run time', 'open-growth-seo' ) . '</th><td>' . esc_html( ! empty( $queue_runner['last_run_at'] ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $queue_runner['last_run_at'] ) : __( 'Never', 'open-growth-seo' ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Consecutive failures', 'open-growth-seo' ) . '</th><td>' . esc_html( (string) ( $queue_runner['consecutive_failures'] ?? 0 ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Failed items retained', 'open-growth-seo' ) . '</th><td>' . esc_html( (string) ( $indexnow_status['queue']['failed_count'] ?? 0 ) ) . '</td></tr>';
			echo '</tbody></table>';
			if ( ! empty( $queue_runner['last_message'] ) ) {
				echo '<p class="description">' . esc_html( (string) $queue_runner['last_message'] ) . '</p>';
			}
			echo '</div>';
			$failed_items = isset( $indexnow_status['queue']['failed'] ) && is_array( $indexnow_status['queue']['failed'] ) ? $indexnow_status['queue']['failed'] : array();
			if ( ! empty( $failed_items ) ) {
				echo '<p><strong>' . esc_html__( 'Recent failed URLs', 'open-growth-seo' ) . '</strong></p><ul>';
				foreach ( array_slice( array_reverse( $failed_items ), 0, 5 ) as $failed ) {
					$url  = isset( $failed['url'] ) ? (string) $failed['url'] : '';
					$err  = isset( $failed['error'] ) ? (string) $failed['error'] : '';
					$time = isset( $failed['time'] ) ? (int) $failed['time'] : 0;
					echo '<li>' . esc_html( $url . ' | ' . $err . ' | ' . ( $time > 0 ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) : '-' ) ) . '</li>';
				}
				echo '</ul>';
			}
			$this->field_text( 'integration_request_timeout', __( 'Integration request timeout (seconds)', 'open-growth-seo' ), (string) $settings['integration_request_timeout'] );
			$this->field_text( 'integration_retry_limit', __( 'Integration retry limit', 'open-growth-seo' ), (string) $settings['integration_retry_limit'] );
			$this->field_select(
				'integration_logs_enabled',
				__( 'Integration logs', 'open-growth-seo' ),
				(string) $settings['integration_logs_enabled'],
				array(
					'1' => __( 'Enabled', 'open-growth-seo' ),
					'0' => __( 'Disabled', 'open-growth-seo' ),
				)
			);
			echo '<p class="description">' . esc_html__( 'Secrets are stored separately from general settings and are never rendered back in clear text.', 'open-growth-seo' ) . '</p>';
		}
		if ( 'ogs-seo-masters-plus' === $page ) {
			$this->render_masters_plus_fields( $settings );
		}
		if ( 'ogs-seo-audits' === $page ) {
			$audit = new AuditManager();
			$issues = $audit->get_latest_issues();
			$all_issues = $audit->get_latest_issues( true );
			$ignored_map = $audit->get_ignored_map();
			$state = $audit->get_scan_state();
			$summary = $audit->summary();
			$groups  = $audit->grouped_issues();
			echo '<p>' . esc_html__( 'Audit scans run hourly incrementally. Manual scan runs a broader pass and refreshes findings.', 'open-growth-seo' ) . '</p>';
			echo '<p class="description">' . esc_html( sprintf( __( 'Last run: %1$s | Scan mode: %2$s | Last batch: %3$d', 'open-growth-seo' ), (int) get_option( 'ogs_seo_audit_last_run', 0 ) > 0 ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) get_option( 'ogs_seo_audit_last_run', 0 ) ) : __( 'never', 'open-growth-seo' ), (string) ( $state['mode'] ?? 'n/a' ), (int) ( $state['last_batch'] ?? 0 ) ) ) . '</p>';
			$run_audit_url = wp_nonce_url( admin_url( 'admin-post.php?action=ogs_seo_run_audit' ), 'ogs_seo_run_audit' );
			echo '<p><a class="button button-primary" href="' . esc_url( $run_audit_url ) . '">' . esc_html__( 'Run Full Re-scan', 'open-growth-seo' ) . '</a></p>';
			echo '<div class="ogs-grid ogs-grid-4 ogs-audit-summary-grid">';
			echo '<div class="ogs-card"><h3>' . esc_html__( 'Active issues', 'open-growth-seo' ) . '</h3><p class="ogs-status ogs-status-warn">' . esc_html( (string) ( $summary['total'] ?? 0 ) ) . '</p><p class="description">' . esc_html__( 'Current actionable findings after ignored issues are excluded.', 'open-growth-seo' ) . '</p></div>';
			echo '<div class="ogs-card"><h3>' . esc_html__( 'Critical now', 'open-growth-seo' ) . '</h3><p class="ogs-status ' . esc_attr( ( (int) ( $summary['critical'] ?? 0 ) ) > 0 ? 'ogs-status-bad' : 'ogs-status-good' ) . '">' . esc_html( (string) ( $summary['critical'] ?? 0 ) ) . '</p><p class="description">' . esc_html__( 'Problems that can directly undermine crawl, index, or output integrity.', 'open-growth-seo' ) . '</p></div>';
			echo '<div class="ogs-card"><h3>' . esc_html__( 'Important next', 'open-growth-seo' ) . '</h3><p class="ogs-status ogs-status-warn">' . esc_html( (string) ( $summary['important'] ?? 0 ) ) . '</p><p class="description">' . esc_html__( 'High-value improvements that should be handled before minor cleanup.', 'open-growth-seo' ) . '</p></div>';
			echo '<div class="ogs-card"><h3>' . esc_html__( 'Quick wins', 'open-growth-seo' ) . '</h3><p class="ogs-status ogs-status-good">' . esc_html( (string) ( $summary['quick_wins'] ?? 0 ) ) . '</p><p class="description">' . esc_html__( 'Fast fixes with clear impact and low implementation effort.', 'open-growth-seo' ) . '</p></div>';
			echo '</div>';
			if ( empty( $issues ) ) {
				echo '<p>' . esc_html__( 'No active audit issues. Keep monitoring and run scans after major changes.', 'open-growth-seo' ) . '</p>';
			} else {
				$quick_wins = array_values( array_filter( $issues, static fn( $issue ) => is_array( $issue ) && ! empty( $issue['quick_win'] ) ) );
				if ( ! empty( $quick_wins ) ) {
					echo '<div class="ogs-card ogs-audit-callout"><h2>' . esc_html__( 'Quick Wins', 'open-growth-seo' ) . '</h2><p class="description">' . esc_html__( 'These fixes are usually low-friction and worth handling early.', 'open-growth-seo' ) . '</p><ul>';
					foreach ( array_slice( $quick_wins, 0, 5 ) as $quick_issue ) {
						echo '<li><strong>' . esc_html( (string) ( $quick_issue['title'] ?? '' ) ) . '</strong> <span class="description">- ' . esc_html( (string) ( $quick_issue['recommendation'] ?? '' ) ) . '</span></li>';
					}
					echo '</ul></div>';
				}
				foreach ( $groups as $category => $group_issues ) {
					if ( empty( $group_issues ) || ! is_array( $group_issues ) ) {
						continue;
					}
					echo '<section class="ogs-audit-group">';
					echo '<div class="ogs-issue-card-header"><h2>' . esc_html( $this->audit_category_label( (string) $category ) ) . '</h2><span class="ogs-schema-chip ogs-schema-chip-neutral">' . esc_html( sprintf( __( '%d issues', 'open-growth-seo' ), count( $group_issues ) ) ) . '</span></div>';
					echo '<div class="ogs-grid ogs-grid-2">';
					foreach ( $group_issues as $issue ) {
						$this->render_issue_card( $issue );
					}
					echo '</div>';
					echo '</section>';
				}
			}
			$ignored_issues = array();
			foreach ( $all_issues as $item ) {
				$id = isset( $item['id'] ) ? (string) $item['id'] : '';
				if ( '' !== $id && isset( $ignored_map[ $id ] ) ) {
					$ignored_issues[] = $item;
				}
			}
			if ( ! empty( $ignored_issues ) ) {
				echo '<h2>' . esc_html__( 'Ignored Issues', 'open-growth-seo' ) . '</h2>';
				echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Issue', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Reason', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Action', 'open-growth-seo' ) . '</th></tr></thead><tbody>';
				foreach ( $ignored_issues as $issue ) {
					$id = isset( $issue['id'] ) ? sanitize_key( (string) $issue['id'] ) : '';
					$reason = isset( $ignored_map[ $id ]['reason'] ) ? (string) $ignored_map[ $id ]['reason'] : '';
					$restore_url = wp_nonce_url( admin_url( 'admin-post.php?action=ogs_seo_unignore_issue&issue_id=' . rawurlencode( $id ) ), 'ogs_seo_issue_action' );
					echo '<tr><td>' . esc_html( (string) ( $issue['title'] ?? '' ) ) . '</td><td>' . esc_html( $reason ) . '</td><td><a class="button" href="' . esc_url( $restore_url ) . '">' . esc_html__( 'Restore', 'open-growth-seo' ) . '</a></td></tr>';
				}
				echo '</tbody></table>';
			}
		}
		if ( 'ogs-seo-tools' === $page ) {
			echo '<p>' . esc_html__( 'Use REST endpoints under /wp-json/ogs-seo/v1 and WP-CLI command group `wp ogs-seo`.', 'open-growth-seo' ) . '</p>';
			echo '<form method="post">';
			wp_nonce_field( 'ogs_seo_save_settings' );
			echo '<input type="hidden" name="ogs_seo_action" value="save_settings" />';
			$this->field_select(
				'keep_data_on_uninstall',
				__( 'Keep data on uninstall', 'open-growth-seo' ),
				(string) $settings['keep_data_on_uninstall'],
				array(
					'1' => __( 'Yes', 'open-growth-seo' ),
					'0' => __( 'No', 'open-growth-seo' ),
				)
			);
			$this->field_select(
				'diagnostic_mode',
				__( 'Diagnostic mode', 'open-growth-seo' ),
				(string) $settings['diagnostic_mode'],
				array(
					'1' => __( 'Enabled', 'open-growth-seo' ),
					'0' => __( 'Disabled', 'open-growth-seo' ),
				)
			);
			$this->field_select(
				'debug_logs_enabled',
				__( 'Developer debug logs', 'open-growth-seo' ),
				(string) $settings['debug_logs_enabled'],
				array(
					'1' => __( 'Enabled', 'open-growth-seo' ),
					'0' => __( 'Disabled', 'open-growth-seo' ),
				)
			);
			echo '<p><button class="button button-primary" type="submit">' . esc_html__( 'Save changes', 'open-growth-seo' ) . '</button></p>';
			echo '</form>';
			$installation = ( new InstallationManager() )->get_state();
			$diagnostics = DeveloperTools::diagnostics();
			$debug_logs  = DeveloperTools::get_logs( 15 );
			$support     = isset( $diagnostics['support'] ) && is_array( $diagnostics['support'] ) ? $diagnostics['support'] : array();
			$this->render_installation_profile_section( $installation );
			$this->render_support_overview_section( $support, $diagnostics );
			$this->render_support_health_section( $support );
			$this->render_support_resources_section( $support );
			echo '<h2>' . esc_html__( 'Raw diagnostics snapshot', 'open-growth-seo' ) . '</h2>';
			echo '<details class="ogs-support-details"><summary>' . esc_html__( 'Show detailed diagnostic payload', 'open-growth-seo' ) . '</summary>';
			echo '<table class="widefat striped" style="max-width:920px"><tbody>';
			foreach ( $diagnostics as $key => $value ) {
				$key = sanitize_text_field( (string) $key );
				$display = is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
				echo '<tr><th>' . esc_html( $key ) . '</th><td><code>' . esc_html( (string) $display ) . '</code></td></tr>';
			}
			echo '</tbody></table>';
			echo '</details>';
			echo '<p class="description">' . esc_html__( 'REST diagnostics route (admin capability and REST nonce required):', 'open-growth-seo' ) . ' <code>' . esc_html( (string) rest_url( 'ogs-seo/v1/dev-tools/diagnostics' ) ) . '</code></p>';
			echo '<p class="description">' . esc_html__( 'REST installation telemetry route (admin capability and REST nonce required):', 'open-growth-seo' ) . ' <code>' . esc_html( (string) rest_url( 'ogs-seo/v1/installation/telemetry' ) ) . '</code></p>';

			echo '<h2>' . esc_html__( 'Configuration Export and Import', 'open-growth-seo' ) . '</h2>';
			echo '<p class="description">' . esc_html__( 'Export/import includes plugin settings only. Stored credentials/secrets are excluded by design.', 'open-growth-seo' ) . '</p>';
			echo '<p class="description">' . esc_html( sprintf( __( 'Import payload maximum size: %d KB.', 'open-growth-seo' ), (int) ( DeveloperTools::max_import_bytes() / 1024 ) ) ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'ogs_seo_dev_tools_action' );
			echo '<input type="hidden" name="action" value="ogs_seo_dev_export"/>';
			echo '<p><button class="button" type="submit">' . esc_html__( 'Export Settings JSON', 'open-growth-seo' ) . '</button></p>';
			echo '</form>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'ogs_seo_dev_tools_action' );
			echo '<input type="hidden" name="action" value="ogs_seo_dev_import"/>';
			echo '<p><label><strong>' . esc_html__( 'Import payload (JSON)', 'open-growth-seo' ) . '</strong><br/><textarea class="large-text code" rows="8" name="ogs_dev_payload" required></textarea></label></p>';
			echo '<p><label><input type="checkbox" name="ogs_dev_merge" value="1" checked="checked"/> ' . esc_html__( 'Merge with current settings (recommended)', 'open-growth-seo' ) . '</label></p>';
			echo '<p><button class="button button-primary" type="submit">' . esc_html__( 'Import Settings', 'open-growth-seo' ) . '</button></p>';
			echo '</form>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'' . esc_js( __( 'This will reset plugin settings to defaults. Continue?', 'open-growth-seo' ) ) . '\');">';
			wp_nonce_field( 'ogs_seo_dev_tools_action' );
			echo '<input type="hidden" name="action" value="ogs_seo_dev_reset"/>';
			echo '<p><label><input type="checkbox" name="ogs_dev_preserve_keep_data" value="1" checked="checked"/> ' . esc_html__( 'Preserve current uninstall data-retention choice', 'open-growth-seo' ) . '</label></p>';
			echo '<p><button class="button" type="submit">' . esc_html__( 'Reset Plugin Settings', 'open-growth-seo' ) . '</button></p>';
			echo '</form>';
			echo '<h2>' . esc_html__( 'Developer Debug Logs', 'open-growth-seo' ) . '</h2>';
			$this->render_support_log_summary( $support );
			if ( empty( $debug_logs ) ) {
				echo '<p class="ogs-empty">' . esc_html__( 'No debug logs yet. Enable Diagnostic mode or Developer debug logs to collect entries.', 'open-growth-seo' ) . '</p>';
			} else {
				echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Time', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Level', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Message', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Context', 'open-growth-seo' ) . '</th></tr></thead><tbody>';
				foreach ( $debug_logs as $entry ) {
					$time = isset( $entry['time'] ) ? (int) $entry['time'] : 0;
					$level = isset( $entry['level'] ) ? (string) $entry['level'] : '';
					$message = isset( $entry['message'] ) ? (string) $entry['message'] : '';
					$context = isset( $entry['context'] ) && is_array( $entry['context'] ) ? wp_json_encode( $entry['context'] ) : '';
					echo '<tr><td>' . esc_html( $time > 0 ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) : '-' ) . '</td><td>' . esc_html( $level ) . '</td><td>' . esc_html( $message ) . '</td><td><code>' . esc_html( (string) $context ) . '</code></td></tr>';
				}
				echo '</tbody></table>';
			}
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'' . esc_js( __( 'Clear all developer debug logs?', 'open-growth-seo' ) ) . '\');">';
			wp_nonce_field( 'ogs_seo_dev_tools_action' );
			echo '<input type="hidden" name="action" value="ogs_seo_dev_clear_logs"/>';
			echo '<p><button class="button" type="submit">' . esc_html__( 'Clear Developer Logs', 'open-growth-seo' ) . '</button></p>';
			echo '</form>';
			$importer = new Importer();
			$detected = $importer->detect();
			$state    = $importer->get_state();
			$providers = isset( $detected['providers'] ) && is_array( $detected['providers'] ) ? $detected['providers'] : array();
			$active    = isset( $detected['active'] ) && is_array( $detected['active'] ) ? $detected['active'] : array();
			$runtime   = isset( $detected['runtime'] ) && is_array( $detected['runtime'] ) ? $detected['runtime'] : array();
			$contexts  = isset( $detected['contexts'] ) && is_array( $detected['contexts'] ) ? $detected['contexts'] : array();
			$integrations = isset( $detected['integrations'] ) && is_array( $detected['integrations'] ) ? $detected['integrations'] : array();
			$recommendations = isset( $detected['recommendations'] ) && is_array( $detected['recommendations'] ) ? $detected['recommendations'] : array();
			echo '<h2>' . esc_html__( 'Compatibility and Import', 'open-growth-seo' ) . '</h2>';
			echo '<p class="description">' . esc_html__( 'Use this workspace to verify coexistence risks, environment constraints, and migration readiness before changing ownership of SEO output.', 'open-growth-seo' ) . '</p>';
			echo '<div class="ogs-grid ogs-compatibility-overview">';
			echo '<div class="ogs-card"><h3>' . esc_html__( 'SEO coexistence', 'open-growth-seo' ) . '</h3><p class="ogs-status ' . esc_attr( ! empty( $active ) ? 'ogs-status-warn' : 'ogs-status-good' ) . '">' . esc_html( ! empty( $active ) ? __( 'Needs attention', 'open-growth-seo' ) : __( 'Clear', 'open-growth-seo' ) ) . '</p><p class="description">' . esc_html( ! empty( $active ) ? implode( ', ', array_map( static fn( $provider ): string => (string) ( $provider['label'] ?? '' ), $active ) ) : __( 'No supported SEO conflicts currently detected.', 'open-growth-seo' ) ) . '</p></div>';
			echo '<div class="ogs-card"><h3>' . esc_html__( 'Runtime baseline', 'open-growth-seo' ) . '</h3><p class="ogs-status ' . esc_attr( ( ! empty( $runtime['meets_php'] ) && ! empty( $runtime['meets_wp'] ) ) ? 'ogs-status-good' : 'ogs-status-warn' ) . '">' . esc_html( ( ! empty( $runtime['meets_php'] ) && ! empty( $runtime['meets_wp'] ) ) ? __( 'Supported', 'open-growth-seo' ) : __( 'Review runtime', 'open-growth-seo' ) ) . '</p><p class="description">' . esc_html( sprintf( __( 'PHP %1$s | WordPress %2$s', 'open-growth-seo' ), (string) ( $runtime['php_version'] ?? '-' ), (string) ( $runtime['wp_version'] ?? '-' ) ) ) . '</p></div>';
			echo '<div class="ogs-card"><h3>' . esc_html__( 'Execution contexts', 'open-growth-seo' ) . '</h3><p class="ogs-status ' . esc_attr( ! empty( $contexts['rest_available'] ) ? 'ogs-status-good' : 'ogs-status-warn' ) . '">' . esc_html( ! empty( $contexts['rest_available'] ) ? __( 'REST ready', 'open-growth-seo' ) : __( 'REST unavailable', 'open-growth-seo' ) ) . '</p><p class="description">' . esc_html( implode( ' | ', array_filter( array( ! empty( $runtime['multisite'] ) ? __( 'Multisite', 'open-growth-seo' ) : '', ! empty( $contexts['gutenberg_available'] ) ? __( 'Block editor available', 'open-growth-seo' ) : __( 'Block editor unavailable', 'open-growth-seo' ), ! empty( $contexts['classic_editor_forced'] ) ? __( 'Classic editor forced', 'open-growth-seo' ) : '', ! empty( $contexts['cron_disabled'] ) ? __( 'WP-Cron disabled', 'open-growth-seo' ) : __( 'WP-Cron enabled', 'open-growth-seo' ) ) ) ) ) . '</p></div>';
			echo '</div>';
			if ( ! empty( $integrations ) ) {
				echo '<table class="widefat striped" style="max-width:920px;margin-top:16px;"><thead><tr><th>' . esc_html__( 'Compatibility area', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Status', 'open-growth-seo' ) . '</th></tr></thead><tbody>';
				foreach ( $integrations as $group ) {
					if ( ! is_array( $group ) ) {
						continue;
					}
					echo '<tr><td>' . esc_html( (string) ( $group['label'] ?? '' ) ) . '</td><td>' . esc_html( ! empty( $group['active'] ) ? __( 'Detected', 'open-growth-seo' ) : __( 'Not detected', 'open-growth-seo' ) ) . '</td></tr>';
				}
				echo '</tbody></table>';
			}
			if ( ! empty( $recommendations ) ) {
				echo '<div class="ogs-card" style="max-width:920px;margin-top:16px;"><h3>' . esc_html__( 'Operator guidance', 'open-growth-seo' ) . '</h3><ul>';
				foreach ( array_slice( $recommendations, 0, 6 ) as $message ) {
					echo '<li>' . esc_html( (string) $message ) . '</li>';
				}
				echo '</ul></div>';
			}
			if ( empty( $providers ) ) {
				echo '<p class="ogs-empty">' . esc_html__( 'No supported SEO providers are registered.', 'open-growth-seo' ) . '</p>';
			} else {
				echo '<table class="widefat striped" style="max-width:920px"><thead><tr><th>' . esc_html__( 'Plugin', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Version', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Status', 'open-growth-seo' ) . '</th></tr></thead><tbody>';
				foreach ( $providers as $slug => $item ) {
					$status = ! empty( $item['active'] ) ? __( 'Active', 'open-growth-seo' ) : __( 'Inactive', 'open-growth-seo' );
					echo '<tr><td>' . esc_html( (string) ( $item['label'] ?? $slug ) ) . '</td><td>' . esc_html( (string) ( $item['version'] ?? '-' ) ) . '</td><td>' . esc_html( $status ) . '</td></tr>';
				}
				echo '</tbody></table>';
			}
			if ( empty( $active ) ) {
				echo '<p class="description">' . esc_html__( 'No active SEO conflict detected. You can still import legacy metadata from previously used providers.', 'open-growth-seo' ) . '</p>';
			}
				echo '<p class="description">' . esc_html( ! empty( $detected['safe_mode_enabled'] ) ? __( 'Safe mode is currently enabled for coexistence.', 'open-growth-seo' ) : __( 'Safe mode is currently disabled; enable it to reduce duplicate output during coexistence.', 'open-growth-seo' ) ) . '</p>';

				echo '<h3>' . esc_html__( 'Migration Flow', 'open-growth-seo' ) . '</h3>';
				echo '<p class="description">' . esc_html__( 'Run a dry run first. Import is non-destructive and supports rollback of imported settings/meta snapshot.', 'open-growth-seo' ) . '</p>';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				wp_nonce_field( 'ogs_seo_import_action' );
				echo '<input type="hidden" name="action" value="ogs_seo_import_dry_run"/>';
			foreach ( $providers as $slug => $item ) {
				$status  = ! empty( $item['active'] ) ? __( 'active', 'open-growth-seo' ) : __( 'inactive', 'open-growth-seo' );
				echo '<label style="display:block;margin:4px 0;"><input type="checkbox" name="slugs[]" value="' . esc_attr( (string) $slug ) . '"' . checked( ! empty( $item['active'] ), true, false ) . '/> ' . esc_html( (string) ( $item['label'] ?? $slug ) ) . ' <span class="description">(' . esc_html( $status ) . ')</span></label>';
			}
				echo '<p><button class="button" type="submit">' . esc_html__( 'Run Dry Run', 'open-growth-seo' ) . '</button></p>';
				echo '</form>';

				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				wp_nonce_field( 'ogs_seo_import_action' );
				echo '<input type="hidden" name="action" value="ogs_seo_import_run"/>';
			foreach ( $providers as $slug => $item ) {
				$status  = ! empty( $item['active'] ) ? __( 'active', 'open-growth-seo' ) : __( 'inactive', 'open-growth-seo' );
				echo '<label style="display:block;margin:4px 0;"><input type="checkbox" name="slugs[]" value="' . esc_attr( (string) $slug ) . '"' . checked( ! empty( $item['active'] ), true, false ) . '/> ' . esc_html( (string) ( $item['label'] ?? $slug ) ) . ' <span class="description">(' . esc_html( $status ) . ')</span></label>';
			}
				echo '<label style="display:block;margin:8px 0;"><input type="checkbox" name="overwrite" value="1"/> ' . esc_html__( 'Overwrite existing Open Growth SEO metadata/settings when source has data', 'open-growth-seo' ) . '</label>';
				echo '<p><button class="button button-primary" type="submit">' . esc_html__( 'Run Import', 'open-growth-seo' ) . '</button></p>';
				echo '</form>';

				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'' . esc_js( __( 'Rollback will restore the last import snapshot. Continue?', 'open-growth-seo' ) ) . '\');">';
				wp_nonce_field( 'ogs_seo_import_action' );
				echo '<input type="hidden" name="action" value="ogs_seo_import_rollback"/>';
				echo '<p><button class="button" type="submit" ' . ( $importer->has_rollback_snapshot() ? '' : 'disabled="disabled"' ) . '>' . esc_html__( 'Rollback Last Import', 'open-growth-seo' ) . '</button></p>';
				echo '</form>';

			if ( ! empty( $state['last_dry_run'] ) && is_array( $state['last_dry_run'] ) ) {
				$dry = $state['last_dry_run'];
				echo '<h3>' . esc_html__( 'Last Dry Run', 'open-growth-seo' ) . '</h3>';
				echo '<p class="description">' . esc_html( sprintf( __( 'Time: %1$s | Settings changes: %2$d | Source meta rows: %3$d', 'open-growth-seo' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) ( $dry['time'] ?? time() ) ), (int) ( $dry['settings_preview']['count'] ?? 0 ), (int) ( $dry['meta_preview']['total_source_rows'] ?? 0 ) ) ) . '</p>';
				$preview_fields = isset( $dry['settings_preview']['fields'] ) && is_array( $dry['settings_preview']['fields'] ) ? $dry['settings_preview']['fields'] : array();
				if ( ! empty( $preview_fields ) ) {
					echo '<p><strong>' . esc_html__( 'Planned settings changes', 'open-growth-seo' ) . '</strong></p><ul>';
					foreach ( array_slice( array_keys( $preview_fields ), 0, 12 ) as $field_name ) {
						echo '<li><code>' . esc_html( (string) $field_name ) . '</code></li>';
					}
					echo '</ul>';
				}
				$dry_warnings = isset( $dry['warnings'] ) && is_array( $dry['warnings'] ) ? $dry['warnings'] : array();
				if ( ! empty( $dry_warnings ) ) {
					echo '<p><strong>' . esc_html__( 'Warnings', 'open-growth-seo' ) . '</strong></p><ul>';
					foreach ( $dry_warnings as $warning ) {
						echo '<li>' . esc_html( (string) $warning ) . '</li>';
					}
					echo '</ul>';
				}
			}
			if ( ! empty( $state['last_import'] ) && is_array( $state['last_import'] ) ) {
				$run = $state['last_import'];
				echo '<h3>' . esc_html__( 'Last Import', 'open-growth-seo' ) . '</h3>';
				echo '<p class="description">' . esc_html( sprintf( __( 'Time: %1$s | Settings changed: %2$d | Meta changed: %3$d | Rollback items: %4$d', 'open-growth-seo' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) ( $run['time'] ?? time() ) ), count( (array) ( $run['changes']['settings_changed'] ?? array() ) ), (int) ( $run['changes']['meta_changed'] ?? 0 ), (int) ( $run['rollback_items'] ?? 0 ) ) ) . '</p>';
				if ( ! empty( $run['rollback_truncated'] ) ) {
					echo '<p class="description" style="color:#b32d2e;">' . esc_html__( 'Rollback snapshot was truncated because migration volume exceeded safety cap.', 'open-growth-seo' ) . '</p>';
				}
				$before_settings = isset( $run['before']['settings_preview']['count'] ) ? (int) $run['before']['settings_preview']['count'] : 0;
				$after_settings  = isset( $run['after']['settings_preview']['count'] ) ? (int) $run['after']['settings_preview']['count'] : 0;
				echo '<p class="description">' . esc_html( sprintf( __( 'Before import pending settings: %1$d | After import pending settings: %2$d', 'open-growth-seo' ), $before_settings, $after_settings ) ) . '</p>';
			}
		}
	}

	private function render_masters_plus_fields( array $settings ): void {
		$available = SeoMastersPlus::is_available( $settings );
		$active    = SeoMastersPlus::is_active( $settings );
		$issues    = ( new AuditManager() )->get_latest_issues();
		$orphan_issue_count = $this->count_issues_by_keywords( $issues, array( 'orphan', 'cornerstone queue' ) );
		$canonical_issue_count = $this->count_issues_by_keywords( $issues, array( 'canonical', 'noindex with canonical', 'duplicate' ) );
		$redirect_issue_count  = $this->count_issues_by_keywords( $issues, array( 'redirect', '404' ) );

		echo '<p class="description">' . esc_html__( 'SEO MASTERS PLUS is a controlled expert layer: advanced controls stay out of novice workflows unless explicitly enabled.', 'open-growth-seo' ) . '</p>';
		$this->field_select(
			'seo_masters_plus_enabled',
			__( 'Enable SEO MASTERS PLUS', 'open-growth-seo' ),
			(string) ( $settings['seo_masters_plus_enabled'] ?? 0 ),
			array(
				'0' => __( 'Disabled (novice-safe)', 'open-growth-seo' ),
				'1' => __( 'Enabled (expert workflows)', 'open-growth-seo' ),
			)
		);

		if ( ! $available ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'SEO MASTERS PLUS is available only in Advanced mode (or when advanced sections are explicitly revealed).', 'open-growth-seo' ) . '</p></div>';
			return;
		}

		echo '<h2>' . esc_html__( 'Expert Feature Flags', 'open-growth-seo' ) . '</h2>';
		foreach ( SeoMastersPlus::feature_map() as $key => $feature ) {
			$this->field_select(
				$key,
				(string) ( $feature['label'] ?? $key ),
				(string) ( $settings[ $key ] ?? 0 ),
				array(
					'1' => __( 'Enabled', 'open-growth-seo' ),
					'0' => __( 'Disabled', 'open-growth-seo' ),
				)
			);
			if ( ! empty( $feature['description'] ) ) {
				echo '<p class="description">' . esc_html( (string) $feature['description'] ) . '</p>';
			}
		}

		if ( ! $active ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Feature flags are configured, but SEO MASTERS PLUS is currently disabled.', 'open-growth-seo' ) . '</p></div>';
			return;
		}

		$snapshot   = LinkGraph::snapshot();
		$queue      = LinkGraph::stale_cornerstone_queue( 5 );
		$post_count = (int) ( $snapshot['post_count'] ?? 0 );
		$edge_count = (int) ( $snapshot['edge_count'] ?? 0 );
		$coverage   = isset( $snapshot['coverage'] ) ? (float) $snapshot['coverage'] : 0.0;
		$gsc_report = IntegrationsManager::google_search_console_operational_report( false );
		$gsc_delta  = isset( $gsc_report['trend']['clicks_delta_pct'] ) ? (float) $gsc_report['trend']['clicks_delta_pct'] : null;

		echo '<h2>' . esc_html__( 'Operational Snapshot', 'open-growth-seo' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:920px"><tbody>';
		echo '<tr><th>' . esc_html__( 'Link graph nodes', 'open-growth-seo' ) . '</th><td>' . esc_html( (string) $post_count ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Link graph edges', 'open-growth-seo' ) . '</th><td>' . esc_html( (string) $edge_count ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Graph coverage estimate', 'open-growth-seo' ) . '</th><td>' . esc_html( sprintf( '%.0f%%', $coverage * 100 ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Orphan/cornerstone issues', 'open-growth-seo' ) . '</th><td>' . esc_html( (string) $orphan_issue_count ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Canonical issues', 'open-growth-seo' ) . '</th><td>' . esc_html( (string) $canonical_issue_count ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Redirect/404 issues', 'open-growth-seo' ) . '</th><td>' . esc_html( (string) $redirect_issue_count ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'GSC clicks delta', 'open-growth-seo' ) . '</th><td>' . esc_html( null === $gsc_delta ? __( 'Unavailable', 'open-growth-seo' ) : sprintf( '%+.2f%%', $gsc_delta ) ) . '</td></tr>';
		echo '</tbody></table>';

		$canonical_samples = get_posts(
			array(
				'post_type'      => get_post_types( array( 'public' => true ), 'names' ),
				'post_status'    => 'publish',
				'posts_per_page' => 5,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		$canonical_samples = array_values( array_filter( array_map( 'absint', (array) $canonical_samples ) ) );
		if ( ! empty( $canonical_samples ) ) {
			$meta_engine = new FrontendMeta();
			echo '<h2>' . esc_html__( 'Canonical Policy Diagnostics', 'open-growth-seo' ) . '</h2>';
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'URL', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Intended', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Effective', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Source', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Why this applies', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Conflicts', 'open-growth-seo' ) . '</th></tr></thead><tbody>';
			foreach ( $canonical_samples as $post_id ) {
				$diagnostics = $meta_engine->canonical_diagnostics_for_post( $post_id, $settings );
				if ( empty( $diagnostics ) ) {
					continue;
				}
				$title = (string) get_the_title( $post_id );
				$url   = (string) ( $diagnostics['url'] ?? '' );
				$edit  = get_edit_post_link( $post_id );
				$conflict_messages = array();
				foreach ( (array) ( $diagnostics['conflicts'] ?? array() ) as $conflict ) {
					if ( ! is_array( $conflict ) || empty( $conflict['message'] ) ) {
						continue;
					}
					$conflict_messages[] = (string) $conflict['message'];
				}
				echo '<tr><td>';
				if ( $edit ) {
					echo '<a href="' . esc_url( (string) $edit ) . '">' . esc_html( '' !== $title ? $title : $url ) . '</a><br/>';
				} else {
					echo esc_html( '' !== $title ? $title : $url );
				}
				echo '<code>' . esc_html( $url ) . '</code></td>';
				echo '<td><code>' . esc_html( (string) ( $diagnostics['intended'] ?? '' ) ) . '</code></td>';
				echo '<td><code>' . esc_html( (string) ( $diagnostics['effective'] ?? '' ) ) . '</code></td>';
				echo '<td>' . esc_html( (string) ( $diagnostics['effective_source'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $diagnostics['reason'] ?? '' ) ) . '</td>';
				echo '<td>' . ( empty( $conflict_messages ) ? esc_html__( 'None', 'open-growth-seo' ) : esc_html( implode( ' | ', $conflict_messages ) ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
			echo '<p class="description">' . esc_html__( 'Intended = target inferred from manual/context defaults. Effective = final canonical after redirect/filter/pagination reconciliation. Why this applies = deterministic policy explanation for the effective target.', 'open-growth-seo' ) . '</p>';
		}

		if ( ! empty( $queue ) ) {
			echo '<h2>' . esc_html__( 'Stale Cornerstone Queue', 'open-growth-seo' ) . '</h2>';
			echo '<ul class="ogs-link-suggestions">';
			foreach ( $queue as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$post_id = isset( $item['post_id'] ) ? absint( $item['post_id'] ) : 0;
				if ( $post_id <= 0 ) {
					continue;
				}
				$edit_url = get_edit_post_link( $post_id );
				$title    = get_the_title( $post_id );
				$reasons  = isset( $item['reasons'] ) && is_array( $item['reasons'] ) ? implode( ', ', array_map( 'sanitize_text_field', $item['reasons'] ) ) : '';
				$meta     = sprintf(
					/* translators: 1: queue score, 2: inbound links count. */
					__( 'score %1$d | inbound links %2$d', 'open-growth-seo' ),
					(int) ( $item['score'] ?? 0 ),
					(int) ( $item['inbound'] ?? 0 )
				);
				echo '<li>';
				if ( $edit_url ) {
					echo '<a href="' . esc_url( (string) $edit_url ) . '">' . esc_html( (string) $title ) . '</a>';
				} else {
					echo esc_html( (string) $title );
				}
				echo '<span class="description"> - ' . esc_html( $meta ) . ( '' !== $reasons ? ' | ' . esc_html( $reasons ) : '' ) . '</span>';
				echo '</li>';
			}
			echo '</ul>';
		}
	}

	private function render_schema_settings_page( array $settings ): void {
		$tabs = $this->schema_tab_definitions();
		$tab  = isset( $_GET['schema_tab'] ) ? sanitize_key( (string) wp_unslash( $_GET['schema_tab'] ) ) : 'overview';
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'overview';
		}

		$probe = RuntimeInspector::normalize_probe(
			array(
				'post_id' => isset( $_GET['schema_inspect_post_id'] ) ? absint( (string) wp_unslash( $_GET['schema_inspect_post_id'] ) ) : 0,
				'url'     => isset( $_GET['schema_inspect_url'] ) ? esc_url_raw( (string) wp_unslash( $_GET['schema_inspect_url'] ) ) : '',
			)
		);
		$inspect = ( new SchemaManager() )->inspect( $probe );

		echo '<p class="description">' . esc_html__( 'Schema output is rule-based and context-aware. Enabled does not always mean emitted.', 'open-growth-seo' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Use this workspace to understand effective output, eligibility, conflicts, and Google-supported vs vocabulary-only types.', 'open-growth-seo' ) . '</p>';
		$this->render_schema_tab_nav( $tab );
		$this->render_schema_overview_cards( $inspect );

		switch ( $tab ) {
			case 'global':
				$this->render_schema_global_graph_tab( $settings, $inspect );
				break;
			case 'rules':
				$this->render_schema_rules_tab( $settings, $inspect );
				break;
			case 'local':
				$this->render_schema_local_tab( $settings );
				break;
			case 'woo':
				$this->render_schema_woo_tab( $settings );
				break;
			case 'expert':
				$this->render_schema_expert_tab( $settings, $inspect );
				break;
			case 'validation':
				$this->render_schema_validation_tab( $inspect );
				break;
			case 'runtime':
				$this->render_schema_runtime_tab( $inspect, $probe );
				break;
			case 'overview':
			default:
				$this->render_schema_overview_tab( $inspect );
				break;
		}
	}

	/**
	 * @return array<string, string>
	 */
	private function schema_tab_definitions(): array {
		return array(
			'overview'   => __( 'Schema Overview', 'open-growth-seo' ),
			'global'     => __( 'Global Graph', 'open-growth-seo' ),
			'rules'      => __( 'Page-Type Schema Rules', 'open-growth-seo' ),
			'local'      => __( 'Local / Multi-Location Schema', 'open-growth-seo' ),
			'woo'        => __( 'WooCommerce / Product Schema', 'open-growth-seo' ),
			'expert'     => __( 'Expert Schema Controls', 'open-growth-seo' ),
			'validation' => __( 'Validation & Conflicts', 'open-growth-seo' ),
			'runtime'    => __( 'Runtime Inspector / Debug', 'open-growth-seo' ),
		);
	}

	private function render_schema_tab_nav( string $current ): void {
		echo '<div class="ogs-schema-tabs" role="tablist" aria-label="' . esc_attr__( 'Schema workspace tabs', 'open-growth-seo' ) . '">';
		foreach ( $this->schema_tab_definitions() as $slug => $label ) {
			$is_current = $slug === $current;
			$url = add_query_arg(
				array(
					'page'       => 'ogs-seo-schema',
					'schema_tab' => $slug,
				),
				admin_url( 'admin.php' )
			);
			echo '<a class="ogs-schema-tab' . ( $is_current ? ' is-active' : '' ) . '" href="' . esc_url( $url ) . '" role="tab" aria-selected="' . esc_attr( $is_current ? 'true' : 'false' ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</div>';
	}

	/**
	 * @param array<string, mixed> $inspect
	 */
	private function render_schema_overview_cards( array $inspect ): void {
		$summary = isset( $inspect['summary'] ) && is_array( $inspect['summary'] ) ? $inspect['summary'] : array();
		$conflicts = isset( $inspect['conflict_totals'] ) && is_array( $inspect['conflict_totals'] ) ? $inspect['conflict_totals'] : array();
		$nodes = (int) ( $summary['nodes_emitted'] ?? 0 );
		$errors = (int) ( $summary['errors'] ?? 0 );
		$warnings = (int) ( $summary['warnings'] ?? 0 );
		$conflict_total = (int) ( $conflicts['total'] ?? 0 );
		$status = ( $errors > 0 || $conflict_total > 0 ) ? __( 'Needs work', 'open-growth-seo' ) : ( $nodes > 0 ? __( 'Good', 'open-growth-seo' ) : __( 'Fix this first', 'open-growth-seo' ) );

		echo '<div class="ogs-grid ogs-grid-overview ogs-schema-overview-grid">';
		echo '<div class="ogs-card ogs-schema-overview-card"><h3>' . esc_html__( 'Effective output state', 'open-growth-seo' ) . '</h3><p class="ogs-status ' . esc_attr( ( $errors > 0 || $conflict_total > 0 ) ? 'ogs-status-warn' : 'ogs-status-good' ) . '">' . esc_html( $status ) . '</p><p class="description">' . esc_html__( 'Snapshot based on Runtime Inspector context.', 'open-growth-seo' ) . '</p></div>';
		echo '<div class="ogs-card ogs-schema-overview-card"><h3>' . esc_html__( 'Nodes emitted', 'open-growth-seo' ) . '</h3><p class="ogs-status">' . esc_html( (string) $nodes ) . '</p><p class="description">' . esc_html__( 'Final JSON-LD graph nodes active for current inspect target.', 'open-growth-seo' ) . '</p></div>';
		echo '<div class="ogs-card ogs-schema-overview-card"><h3>' . esc_html__( 'Validation issues', 'open-growth-seo' ) . '</h3><p class="ogs-status ' . esc_attr( $errors > 0 ? 'ogs-status-bad' : 'ogs-status-good' ) . '">' . esc_html( (string) $errors ) . '</p><p class="description">' . esc_html__( 'Blocked node validations that prevent reliable schema output.', 'open-growth-seo' ) . '</p></div>';
		echo '<div class="ogs-card ogs-schema-overview-card"><h3>' . esc_html__( 'Conflicts', 'open-growth-seo' ) . '</h3><p class="ogs-status ' . esc_attr( $conflict_total > 0 ? 'ogs-status-warn' : 'ogs-status-good' ) . '">' . esc_html( (string) $conflict_total ) . '</p><p class="description">' . esc_html__( 'Cross-signal conflicts (canonical/noindex/redirect/schema context).', 'open-growth-seo' ) . '</p></div>';
		echo '</div>';

		echo '<p class="description">';
		echo esc_html(
			sprintf(
				/* translators: 1: warnings count, 2: conflicts count */
				__( 'Warnings: %1$d | Conflicts: %2$d. Use Validation & Conflicts tab for guided fixes.', 'open-growth-seo' ),
				$warnings,
				$conflict_total
			)
		);
		echo '</p>';
	}

	/**
	 * @param array<string, mixed> $inspect
	 */
	private function render_schema_overview_tab( array $inspect ): void {
		$type_statuses = isset( $inspect['type_statuses'] ) && is_array( $inspect['type_statuses'] ) ? $inspect['type_statuses'] : array();
		echo '<div class="ogs-card">';
		echo '<h2>' . esc_html__( 'Type Status Matrix', 'open-growth-seo' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Each type shows configuration state, eligibility, support level, and whether it was emitted in the current runtime snapshot.', 'open-growth-seo' ) . '</p>';
		echo '<table class="widefat striped ogs-schema-table"><thead><tr><th>' . esc_html__( 'Type', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Support', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Risk', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Configured', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Eligible', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Status', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Emitted', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Reason', 'open-growth-seo' ) . '</th></tr></thead><tbody>';
		foreach ( $type_statuses as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$support = (string) ( $row['support_label'] ?? '' );
			$risk = (string) ( $row['risk_label'] ?? '' );
			$status = (string) ( $row['status'] ?? 'blocked' );
			$emitted = ! empty( $row['emitted'] ) ? __( 'Yes', 'open-growth-seo' ) : __( 'No', 'open-growth-seo' );
			$configured = ! empty( $row['configured'] ) ? __( 'Yes', 'open-growth-seo' ) : __( 'No', 'open-growth-seo' );
			$eligible = ! empty( $row['eligible'] ) ? __( 'Yes', 'open-growth-seo' ) : __( 'No', 'open-growth-seo' );
			echo '<tr>';
			echo '<td><strong>' . esc_html( (string) ( $row['label'] ?? $row['type'] ?? '' ) ) . '</strong></td>';
			echo '<td>' . esc_html( $support ) . '</td>';
			echo '<td>' . esc_html( $risk ) . '</td>';
			echo '<td>' . esc_html( $configured ) . '</td>';
			echo '<td>' . esc_html( $eligible ) . '</td>';
			echo '<td><span class="ogs-schema-chip ogs-schema-chip-' . esc_attr( sanitize_html_class( $status ) ) . '">' . esc_html( ucfirst( str_replace( '_', ' ', $status ) ) ) . '</span></td>';
			echo '<td>' . esc_html( $emitted ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['reason'] ?? '' ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * @param array<string, mixed> $settings
	 * @param array<string, mixed> $inspect
	 */
	private function render_schema_global_graph_tab( array $settings, array $inspect ): void {
		echo '<div class="ogs-card">';
		echo '<h2>' . esc_html__( 'Global Graph Controls', 'open-growth-seo' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Keep Organization separate from per-location LocalBusiness entities. Global graph should define site identity, not duplicate location pages.', 'open-growth-seo' ) . '</p>';
		$this->field_select(
			'schema_organization_enabled',
			__( 'Organization Schema', 'open-growth-seo' ),
			(string) $settings['schema_organization_enabled'],
			array(
				'1' => __( 'Enabled', 'open-growth-seo' ),
				'0' => __( 'Disabled', 'open-growth-seo' ),
			)
		);
		$this->field_select(
			'schema_org_type',
			__( 'Institution Type', 'open-growth-seo' ),
			(string) $settings['schema_org_type'],
			array(
				'Organization'        => __( 'Organization', 'open-growth-seo' ),
				'CollegeOrUniversity' => __( 'CollegeOrUniversity', 'open-growth-seo' ),
			)
		);
		echo '<p class="description">' . esc_html__( 'Use CollegeOrUniversity when the site primarily represents a real university, college, or higher-education institution.', 'open-growth-seo' ) . '</p>';
		$this->field_select(
			'schema_website_enabled',
			__( 'WebSite Schema', 'open-growth-seo' ),
			(string) $settings['schema_website_enabled'],
			array(
				'1' => __( 'Enabled', 'open-growth-seo' ),
				'0' => __( 'Disabled', 'open-growth-seo' ),
			)
		);
		$this->field_select(
			'schema_webpage_enabled',
			__( 'WebPage Schema', 'open-growth-seo' ),
			(string) $settings['schema_webpage_enabled'],
			array(
				'1' => __( 'Enabled', 'open-growth-seo' ),
				'0' => __( 'Disabled', 'open-growth-seo' ),
			)
		);
		$this->field_select(
			'schema_breadcrumb_enabled',
			__( 'BreadcrumbList Schema', 'open-growth-seo' ),
			(string) $settings['schema_breadcrumb_enabled'],
			array(
				'1' => __( 'Enabled', 'open-growth-seo' ),
				'0' => __( 'Disabled', 'open-growth-seo' ),
			)
		);
		$this->field_select(
			'schema_local_business_enabled',
			__( 'LocalBusiness Schema', 'open-growth-seo' ),
			(string) $settings['schema_local_business_enabled'],
			array(
				'1' => __( 'Enabled', 'open-growth-seo' ),
				'0' => __( 'Disabled', 'open-growth-seo' ),
			)
		);
		$this->field_text( 'schema_org_name', __( 'Organization Name', 'open-growth-seo' ), (string) $settings['schema_org_name'] );
		$this->field_text( 'schema_org_logo', __( 'Organization Logo URL', 'open-growth-seo' ), (string) $settings['schema_org_logo'] );
		$this->field_text( 'schema_org_contact_phone', __( 'Organization Contact Phone', 'open-growth-seo' ), (string) $settings['schema_org_contact_phone'] );
		$this->field_text( 'schema_org_contact_email', __( 'Organization Contact Email', 'open-growth-seo' ), (string) $settings['schema_org_contact_email'] );
		$this->field_text( 'schema_org_contact_url', __( 'Organization Contact URL', 'open-growth-seo' ), (string) $settings['schema_org_contact_url'] );
		$this->field_textarea( 'schema_org_sameas', __( 'Organization sameAs URLs (one per line)', 'open-growth-seo' ), (string) $settings['schema_org_sameas'] );
		echo '</div>';

		$graph = isset( $inspect['payload']['@graph'] ) && is_array( $inspect['payload']['@graph'] ) ? $inspect['payload']['@graph'] : array();
		echo '<div class="ogs-card">';
		echo '<h3>' . esc_html__( 'Effective emitted graph preview', 'open-growth-seo' ) . '</h3>';
		if ( empty( $graph ) ) {
			echo '<p class="ogs-empty">' . esc_html__( 'No nodes emitted for current inspect context. Open Runtime Inspector tab and inspect a specific post/page.', 'open-growth-seo' ) . '</p>';
		} else {
			echo '<ul class="ogs-preview-hints">';
			foreach ( $graph as $node ) {
				if ( ! is_array( $node ) ) {
					continue;
				}
				$type = isset( $node['@type'] ) ? (string) $node['@type'] : __( 'Unknown', 'open-growth-seo' );
				$id   = isset( $node['@id'] ) ? (string) $node['@id'] : '';
				echo '<li>' . esc_html( '' !== $id ? $type . ' - ' . $id : $type ) . '</li>';
			}
			echo '</ul>';
		}
		echo '</div>';

		$relationships = $this->schema_graph_relationship_rows( $graph );
		echo '<div class="ogs-card">';
		echo '<h3>' . esc_html__( 'Graph relationship map', 'open-growth-seo' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Shows node linkage, inheritance, and broken graph references for the current runtime snapshot.', 'open-growth-seo' ) . '</p>';
		if ( empty( $relationships ) ) {
			echo '<p class="ogs-empty">' . esc_html__( 'No graph relationships were detected in the current payload.', 'open-growth-seo' ) . '</p>';
		} else {
			echo '<table class="widefat striped ogs-schema-table"><thead><tr><th>' . esc_html__( 'Node', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Relationships', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Diagnostics', 'open-growth-seo' ) . '</th></tr></thead><tbody>';
			foreach ( $relationships as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$links = isset( $row['links'] ) && is_array( $row['links'] ) ? $row['links'] : array();
				$diagnostics = isset( $row['diagnostics'] ) && is_array( $row['diagnostics'] ) ? $row['diagnostics'] : array();
				echo '<tr>';
				echo '<td><strong>' . esc_html( (string) ( $row['label'] ?? '' ) ) . '</strong><br/><code>' . esc_html( (string) ( $row['id'] ?? '' ) ) . '</code></td>';
				echo '<td>' . ( ! empty( $links ) ? esc_html( implode( ' | ', array_map( 'strval', $links ) ) ) : esc_html__( 'No outbound relationships', 'open-growth-seo' ) ) . '</td>';
				echo '<td>' . ( ! empty( $diagnostics ) ? esc_html( implode( ' | ', array_map( 'strval', $diagnostics ) ) ) : esc_html__( 'No linkage issues detected', 'open-growth-seo' ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}

	/**
	 * @param array<string, mixed> $settings
	 * @param array<string, mixed> $inspect
	 */
	private function render_schema_rules_tab( array $settings, array $inspect ): void {
		echo '<div class="ogs-card">';
		echo '<h2>' . esc_html__( 'Page-Type Schema Rules', 'open-growth-seo' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Auto mode is the safe default. Disable types globally if they are out of scope for your site.', 'open-growth-seo' ) . '</p>';

		$auto_toggle_options = array(
			'1' => __( 'Auto enabled', 'open-growth-seo' ),
			'0' => __( 'Disabled', 'open-growth-seo' ),
		);
		$schema_rule_fields = array(
			'schema_article_enabled'              => __( 'Article / BlogPosting / NewsArticle', 'open-growth-seo' ),
			'schema_tech_article_enabled'         => __( 'TechArticle', 'open-growth-seo' ),
			'schema_service_enabled'              => __( 'Service', 'open-growth-seo' ),
			'schema_offer_catalog_enabled'        => __( 'OfferCatalog', 'open-growth-seo' ),
			'schema_faq_enabled'                  => __( 'FAQPage', 'open-growth-seo' ),
			'schema_person_enabled'               => __( 'Person', 'open-growth-seo' ),
			'schema_course_enabled'               => __( 'Course', 'open-growth-seo' ),
			'schema_program_enabled'              => __( 'EducationalOccupationalProgram', 'open-growth-seo' ),
			'schema_profile_enabled'              => __( 'ProfilePage', 'open-growth-seo' ),
			'schema_about_page_enabled'           => __( 'AboutPage', 'open-growth-seo' ),
			'schema_contact_page_enabled'         => __( 'ContactPage', 'open-growth-seo' ),
			'schema_collection_page_enabled'      => __( 'CollectionPage', 'open-growth-seo' ),
			'schema_discussion_enabled'           => __( 'DiscussionForumPosting', 'open-growth-seo' ),
			'schema_qapage_enabled'               => __( 'QAPage', 'open-growth-seo' ),
			'schema_video_enabled'                => __( 'VideoObject', 'open-growth-seo' ),
			'schema_event_enabled'                => __( 'Event', 'open-growth-seo' ),
			'schema_event_series_enabled'         => __( 'EventSeries', 'open-growth-seo' ),
			'schema_jobposting_enabled'           => __( 'JobPosting', 'open-growth-seo' ),
			'schema_recipe_enabled'               => __( 'Recipe', 'open-growth-seo' ),
			'schema_software_enabled'             => __( 'SoftwareApplication', 'open-growth-seo' ),
			'schema_webapi_enabled'               => __( 'WebAPI', 'open-growth-seo' ),
			'schema_project_enabled'              => __( 'Project', 'open-growth-seo' ),
			'schema_review_enabled'               => __( 'Review', 'open-growth-seo' ),
			'schema_guide_enabled'                => __( 'Guide', 'open-growth-seo' ),
			'schema_dataset_enabled'              => __( 'Dataset', 'open-growth-seo' ),
			'schema_defined_term_enabled'         => __( 'DefinedTerm', 'open-growth-seo' ),
			'schema_defined_term_set_enabled'     => __( 'DefinedTermSet', 'open-growth-seo' ),
			'schema_scholarly_article_enabled'    => __( 'ScholarlyArticle', 'open-growth-seo' ),
		);
		foreach ( $schema_rule_fields as $field_key => $field_label ) {
			$this->field_select(
				$field_key,
				$field_label,
				(string) ( $settings[ $field_key ] ?? 0 ),
				$auto_toggle_options
			);
		}
		$this->field_select(
			'schema_paywall_enabled',
			__( 'Subscription / Paywall support', 'open-growth-seo' ),
			(string) $settings['schema_paywall_enabled'],
			array(
				'1' => __( 'Enabled', 'open-growth-seo' ),
				'0' => __( 'Disabled', 'open-growth-seo' ),
			)
		);
		echo '</div>';

		echo '<div class="ogs-card">';
		echo '<h3>' . esc_html__( 'How to use CPT schema safely', 'open-growth-seo' ) . '</h3>';
		echo '<div class="ogs-grid ogs-grid-3">';
		echo '<div><strong>' . esc_html__( 'Novice', 'open-growth-seo' ) . '</strong><p class="description">' . esc_html__( 'Leave post types on Auto. The plugin will use built-in CPT suggestions and content checks before emitting schema.', 'open-growth-seo' ) . '</p></div>';
		echo '<div><strong>' . esc_html__( 'Intermediate', 'open-growth-seo' ) . '</strong><p class="description">' . esc_html__( 'Map fixed-purpose CPTs like course, faculty, or publication to one default schema type for more predictable output.', 'open-growth-seo' ) . '</p></div>';
		echo '<div><strong>' . esc_html__( 'Expert', 'open-growth-seo' ) . '</strong><p class="description">' . esc_html__( 'Keep global defaults stable and use editor overrides only on the minority of URLs that genuinely need a different type.', 'open-growth-seo' ) . '</p></div>';
		echo '</div>';
		echo '</div>';

		$this->render_schema_preset_card( $settings );
		$this->render_schema_mapping_transfer_card( $settings );

		$this->render_schema_post_type_defaults_card( $settings );

		$type_statuses = isset( $inspect['type_statuses'] ) && is_array( $inspect['type_statuses'] ) ? $inspect['type_statuses'] : array();
		echo '<div class="ogs-card">';
		echo '<h3>' . esc_html__( 'Rule eligibility state (current inspect context)', 'open-growth-seo' ) . '</h3>';
		echo '<div class="ogs-grid ogs-grid-2">';
		foreach ( SupportMatrix::content_types() as $type => $meta ) {
			$row = isset( $type_statuses[ $type ] ) && is_array( $type_statuses[ $type ] ) ? $type_statuses[ $type ] : array();
			$status = (string) ( $row['status'] ?? 'blocked' );
			$prerequisites = isset( $meta['prerequisites'] ) && is_array( $meta['prerequisites'] ) ? $meta['prerequisites'] : array();
			echo '<div class="ogs-card ogs-schema-type-card">';
			echo '<h4>' . esc_html( (string) ( $meta['label'] ?? $type ) ) . '</h4>';
			echo '<p><span class="ogs-schema-chip ogs-schema-chip-' . esc_attr( sanitize_html_class( $status ) ) . '">' . esc_html( ucfirst( str_replace( '_', ' ', $status ) ) ) . '</span> ';
			echo '<span class="ogs-schema-chip ogs-schema-chip-support">' . esc_html( SupportMatrix::support_badge( (string) ( $meta['support'] ?? '' ) ) ) . '</span> ';
			echo '<span class="ogs-schema-chip ogs-schema-chip-risk">' . esc_html( SupportMatrix::risk_badge( (string) ( $meta['risk'] ?? 'medium' ) ) ) . '</span></p>';
			echo '<p class="description">' . esc_html( (string) ( $row['reason'] ?? ( $meta['description'] ?? '' ) ) ) . '</p>';
			if ( ! empty( $prerequisites ) ) {
				echo '<p class="description"><strong>' . esc_html__( 'Prerequisites:', 'open-growth-seo' ) . '</strong> ' . esc_html( implode( ', ', array_map( 'strval', $prerequisites ) ) ) . '</p>';
			}
			echo '<p class="description"><strong>' . esc_html__( 'Mode:', 'open-growth-seo' ) . '</strong> ' . esc_html__( 'Auto (recommended), manual override only in editor expert controls.', 'open-growth-seo' ) . '</p>';
			echo '</div>';
		}
		echo '</div>';
		echo '</div>';
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	private function render_schema_post_type_defaults_card( array $settings ): void {
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $post_types['attachment'] );

		echo '<div class="ogs-card">';
		echo '<h3>' . esc_html__( 'Custom Post Type Schema Defaults', 'open-growth-seo' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Map public post types to the schema type they usually represent. This is the most reliable way to make university CPTs emit consistent schema without forcing manual overrides on every page.', 'open-growth-seo' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Suggested academic defaults: faculty/staff CPT -> Person, course CPT -> Course, degree/program CPT -> EducationalOccupationalProgram, publication/research CPT -> ScholarlyArticle, event CPT -> Event.', 'open-growth-seo' ) . '</p>';
		if ( empty( $post_types ) ) {
			echo '<p class="ogs-empty">' . esc_html__( 'No public post types are available for schema mapping.', 'open-growth-seo' ) . '</p>';
			echo '</div>';
			return;
		}

		$options = $this->schema_post_type_default_options();
		$mapped  = isset( $settings['schema_post_type_defaults'] ) && is_array( $settings['schema_post_type_defaults'] ) ? $settings['schema_post_type_defaults'] : array();

		echo '<table class="widefat striped ogs-schema-table"><thead><tr><th>' . esc_html__( 'Post type', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Default schema type', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Suggested auto default', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'How Auto behaves', 'open-growth-seo' ) . '</th></tr></thead><tbody>';
		foreach ( $post_types as $post_type => $object ) {
			if ( ! is_object( $object ) ) {
				continue;
			}
			$label    = isset( $object->labels->singular_name ) && is_string( $object->labels->singular_name ) ? $object->labels->singular_name : (string) $post_type;
			$selected = isset( $mapped[ $post_type ] ) ? (string) $mapped[ $post_type ] : '';
			$suggested = \OpenGrowthSolutions\OpenGrowthSEO\Schema\EligibilityEngine::suggested_type_for_post_type( (string) $post_type );
			$suggested_type = isset( $suggested['type'] ) ? (string) $suggested['type'] : '';
			$suggested_reason = isset( $suggested['reason'] ) ? (string) $suggested['reason'] : '';
			echo '<tr>';
			echo '<td><strong>' . esc_html( $label ) . '</strong><br/><code>' . esc_html( (string) $post_type ) . '</code></td>';
			echo '<td><select name="ogs[schema_post_type_defaults][' . esc_attr( (string) $post_type ) . ']">';
			foreach ( $options as $value => $name ) {
				echo '<option value="' . esc_attr( (string) $value ) . '" ' . selected( $selected, (string) $value, false ) . '>' . esc_html( (string) $name ) . '</option>';
			}
			echo '</select></td>';
			echo '<td>' . ( '' !== $suggested_type ? '<strong>' . esc_html( $suggested_type ) . '</strong><br/><span class="description">' . esc_html( $suggested_reason ) . '</span>' : esc_html__( 'No fixed CPT suggestion. Auto falls back to visible-content heuristics.', 'open-growth-seo' ) ) . '</td>';
			echo '<td>' . esc_html( '' !== $selected ? __( 'Uses your saved CPT default first, then validates content signals.', 'open-growth-seo' ) : ( '' !== $suggested_type ? __( 'Uses the built-in CPT suggestion first, then validates content signals.', 'open-growth-seo' ) : __( 'Uses content-based heuristics only.', 'open-growth-seo' ) ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	private function render_schema_preset_card( array $settings ): void {
		unset( $settings );
		$presets = $this->schema_preset_records();
		if ( empty( $presets ) ) {
			return;
		}

		echo '<div class="ogs-card">';
		echo '<h3>' . esc_html__( 'Industry presets for CPT schema mappings', 'open-growth-seo' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Use a preset to fill likely CPT-to-schema defaults in one click. Presets only update matching CPTs and preserve unrelated mappings already saved.', 'open-growth-seo' ) . '</p>';
		echo '<div class="ogs-grid ogs-grid-2">';
		foreach ( $presets as $slug => $preset ) {
			if ( ! is_array( $preset ) ) {
				continue;
			}
			$label = isset( $preset['label'] ) ? (string) $preset['label'] : $slug;
			$description = isset( $preset['description'] ) ? (string) $preset['description'] : '';
			$examples = isset( $preset['examples'] ) && is_array( $preset['examples'] ) ? $preset['examples'] : array();
			$use_when = isset( $preset['use_when'] ) ? (string) $preset['use_when'] : '';
			$notes = isset( $preset['notes'] ) && is_array( $preset['notes'] ) ? $preset['notes'] : array();
			echo '<div class="ogs-card ogs-schema-preset-card">';
			echo '<h4>' . esc_html( $label ) . '</h4>';
			if ( '' !== $description ) {
				echo '<p class="description">' . esc_html( $description ) . '</p>';
			}
			if ( '' !== $use_when ) {
				echo '<p class="description"><strong>' . esc_html__( 'Use when:', 'open-growth-seo' ) . '</strong> ' . esc_html( $use_when ) . '</p>';
			}
			if ( ! empty( $examples ) ) {
				echo '<p class="description"><strong>' . esc_html__( 'Typical mappings:', 'open-growth-seo' ) . '</strong> ' . esc_html( implode( ', ', array_map( 'strval', $examples ) ) ) . '</p>';
			}
			if ( ! empty( $notes ) ) {
				echo '<ul class="ogs-coaching-list">';
				foreach ( array_slice( $notes, 0, 3 ) as $note ) {
					echo '<li>' . esc_html( (string) $note ) . '</li>';
				}
				echo '</ul>';
			}
			echo '<p><button class="button button-secondary" type="submit" name="ogs_schema_preset_apply" value="' . esc_attr( $slug ) . '">' . esc_html__( 'Apply preset', 'open-growth-seo' ) . '</button></p>';
			echo '</div>';
		}
		echo '</div>';
		echo '</div>';
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	private function render_schema_mapping_transfer_card( array $settings ): void {
		$defaults = isset( $settings['schema_post_type_defaults'] ) && is_array( $settings['schema_post_type_defaults'] ) ? $settings['schema_post_type_defaults'] : array();
		$export   = $this->schema_mapping_export_payload( $settings );

		echo '<div class="ogs-grid ogs-grid-2">';
		echo '<div class="ogs-card">';
		echo '<h3>' . esc_html__( 'Export CPT schema mappings', 'open-growth-seo' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Download only the current CPT-to-schema defaults so you can reuse them on another site without exporting the rest of the plugin configuration.', 'open-growth-seo' ) . '</p>';
		echo '<p class="description"><strong>' . esc_html__( 'Mapped CPTs in this site:', 'open-growth-seo' ) . '</strong> ' . esc_html( (string) count( $defaults ) ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'ogs_seo_schema_mapping_export' );
		echo '<input type="hidden" name="action" value="ogs_seo_schema_mapping_export"/>';
		echo '<p><button class="button button-secondary" type="submit">' . esc_html__( 'Download mapping JSON', 'open-growth-seo' ) . '</button></p>';
		echo '</form>';
		echo '<details class="ogs-support-details"><summary>' . esc_html__( 'Preview export payload', 'open-growth-seo' ) . '</summary>';
		echo '<pre class="ogs-rest-response">' . esc_html( wp_json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . '</pre>';
		echo '</details>';
		echo '</div>';

		echo '<div class="ogs-card">';
		echo '<h3>' . esc_html__( 'Import CPT schema mappings', 'open-growth-seo' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Paste a mapping payload exported from another site or upload the JSON file directly. Use Merge to preserve current mappings and Replace to fully overwrite them.', 'open-growth-seo' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" enctype="multipart/form-data">';
		wp_nonce_field( 'ogs_seo_schema_mapping_import' );
		echo '<input type="hidden" name="action" value="ogs_seo_schema_mapping_import"/>';
		echo '<p><label><strong>' . esc_html__( 'Import mode', 'open-growth-seo' ) . '</strong><br/>';
		echo '<select name="schema_mapping_import_mode"><option value="merge">' . esc_html__( 'Merge into current mappings', 'open-growth-seo' ) . '</option><option value="replace">' . esc_html__( 'Replace current mappings', 'open-growth-seo' ) . '</option></select>';
		echo '</label></p>';
		echo '<p><label><strong>' . esc_html__( 'JSON file (optional)', 'open-growth-seo' ) . '</strong><br/><input type="file" name="schema_mapping_file" accept="application/json,.json"/></label></p>';
		echo '<p><label><strong>' . esc_html__( 'Paste JSON payload', 'open-growth-seo' ) . '</strong><br/><textarea class="widefat" rows="10" name="schema_mapping_payload" placeholder="' . esc_attr__( 'Paste the exported schema mapping JSON here if you are not uploading a file.', 'open-growth-seo' ) . '"></textarea></label></p>';
		echo '<p class="description">' . esc_html__( 'Only valid CPT defaults supported by the current plugin version will be imported.', 'open-growth-seo' ) . '</p>';
		echo '<p><button class="button button-primary" type="submit">' . esc_html__( 'Import mappings', 'open-growth-seo' ) . '</button></p>';
		echo '</form>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function schema_preset_records(): array {
		return array(
			'university' => array(
				'label'       => __( 'University / Higher education', 'open-growth-seo' ),
				'description' => __( 'Best for faculties, courses, degree programs, publications, glossary terms, admissions pages, and academic events.', 'open-growth-seo' ),
				'use_when'    => __( 'Your site uses dedicated CPTs for faculty, programs, research, admissions, events, or academic glossary content.', 'open-growth-seo' ),
				'examples'    => array( 'faculty -> Person', 'course -> Course', 'program -> EducationalOccupationalProgram', 'publication -> ScholarlyArticle', 'event -> Event' ),
				'notes'       => array(
					__( 'Keep Auto for mixed-purpose CPTs. Force a CPT default only when that post type always represents the same academic entity.', 'open-growth-seo' ),
					__( 'Use person-like CPTs for professors, researchers, deans, and leadership pages.', 'open-growth-seo' ),
					__( 'Use program/course mappings only when the visible content consistently represents a real degree, module, or course page.', 'open-growth-seo' ),
				),
				'rules'       => array(
					'Person' => array( 'faculty', 'staff', 'professor', 'teacher', 'researcher', 'lecturer', 'rector', 'dean', 'speaker' ),
					'Course' => array( 'course', 'subject', 'module', 'class' ),
					'EducationalOccupationalProgram' => array( 'program', 'degree', 'career', 'major', 'licenciatura', 'master', 'doctorado' ),
					'ScholarlyArticle' => array( 'publication', 'research', 'paper', 'journal', 'repository', 'thesis' ),
					'Event' => array( 'event', 'conference', 'seminar', 'webinar', 'ceremony' ),
					'ContactPage' => array( 'admissions', 'contact' ),
					'DefinedTerm' => array( 'term', 'definition' ),
					'DefinedTermSet' => array( 'glossary', 'vocabulary' ),
					'CollectionPage' => array( 'catalog', 'directory', 'library' ),
				),
			),
			'agency' => array(
				'label'       => __( 'Agency / Professional services', 'open-growth-seo' ),
				'description' => __( 'Best for service pages, case studies, team profiles, reviews, and contact flows.', 'open-growth-seo' ),
				'use_when'    => __( 'Your site sells services, showcases projects or case studies, and uses separate CPTs for team members or testimonials.', 'open-growth-seo' ),
				'examples'    => array( 'service -> Service', 'pricing -> OfferCatalog', 'case-study -> Project', 'team -> Person', 'testimonial -> Review' ),
				'notes'       => array(
					__( 'Use OfferCatalog for pricing or package CPTs, not for general landing pages.', 'open-growth-seo' ),
					__( 'Map team/staff CPTs to Person only when each entry is a real profile page.', 'open-growth-seo' ),
					__( 'Keep About and Contact pages on dedicated page templates when possible; force the CPT only if the post type is fixed-purpose.', 'open-growth-seo' ),
				),
				'rules'       => array(
					'Service' => array( 'service', 'practice', 'consulting', 'solution', 'support' ),
					'OfferCatalog' => array( 'pricing', 'plans', 'offers', 'catalog' ),
					'Project' => array( 'project', 'case-study', 'portfolio', 'initiative' ),
					'Person' => array( 'team', 'staff', 'consultant', 'advisor' ),
					'Review' => array( 'review', 'testimonial' ),
					'AboutPage' => array( 'about', 'company' ),
					'ContactPage' => array( 'contact' ),
				),
			),
			'saas' => array(
				'label'       => __( 'Software / SaaS / developer docs', 'open-growth-seo' ),
				'description' => __( 'Best for product pages, API docs, technical guides, release docs, and feature collections.', 'open-growth-seo' ),
				'use_when'    => __( 'Your site uses CPTs for docs, API references, changelogs, features, or product modules.', 'open-growth-seo' ),
				'examples'    => array( 'software -> SoftwareApplication', 'api -> WebAPI', 'docs -> TechArticle', 'guide -> Guide', 'features -> CollectionPage' ),
				'notes'       => array(
					__( 'Use WebAPI only when the page documents a real API endpoint or API product, not every developer article.', 'open-growth-seo' ),
					__( 'Map docs/help/tutorial CPTs to TechArticle or Guide depending on whether the content is reference-style or step-by-step.', 'open-growth-seo' ),
					__( 'Keep feature catalogs as CollectionPage unless the CPT itself represents the software product.', 'open-growth-seo' ),
				),
				'rules'       => array(
					'SoftwareApplication' => array( 'software', 'app', 'product' ),
					'WebAPI' => array( 'api', 'developer', 'endpoint' ),
					'TechArticle' => array( 'docs', 'documentation', 'knowledgebase', 'kb' ),
					'Guide' => array( 'guide', 'tutorial', 'manual', 'howto' ),
					'CollectionPage' => array( 'features', 'library', 'catalog' ),
					'Review' => array( 'review' ),
				),
			),
			'software' => array(
				'label'       => __( 'Software / SaaS / developer docs', 'open-growth-seo' ),
				'description' => __( 'Best for product pages, API docs, technical guides, release docs, and feature collections.', 'open-growth-seo' ),
				'use_when'    => __( 'Legacy alias for the SaaS preset. Use it for software products, developer docs, and API-focused CPTs.', 'open-growth-seo' ),
				'examples'    => array( 'software -> SoftwareApplication', 'api -> WebAPI', 'docs -> TechArticle', 'guide -> Guide', 'features -> CollectionPage' ),
				'notes'       => array(
					__( 'This alias is kept for backward compatibility with earlier preset submissions.', 'open-growth-seo' ),
					__( 'For new setups, use the SaaS preset card above; both apply the same schema mappings.', 'open-growth-seo' ),
				),
				'rules'       => array(
					'SoftwareApplication' => array( 'software', 'app', 'product' ),
					'WebAPI' => array( 'api', 'developer', 'endpoint' ),
					'TechArticle' => array( 'docs', 'documentation', 'knowledgebase', 'kb' ),
					'Guide' => array( 'guide', 'tutorial', 'manual', 'howto' ),
					'CollectionPage' => array( 'features', 'library', 'catalog' ),
					'Review' => array( 'review' ),
				),
			),
			'clinic' => array(
				'label'       => __( 'Clinic / Healthcare practice', 'open-growth-seo' ),
				'description' => __( 'Best for doctors, services, treatment pages, locations, booking/contact pages, and patient reviews.', 'open-growth-seo' ),
				'use_when'    => __( 'Your site uses CPTs for specialists, treatments, clinic locations, testimonials, or appointment flows.', 'open-growth-seo' ),
				'examples'    => array( 'doctor -> Person', 'treatment -> Service', 'services -> OfferCatalog', 'location -> LocalBusiness', 'testimonial -> Review' ),
				'notes'       => array(
					__( 'Use Person for real practitioner profile pages with visible credentials and role context.', 'open-growth-seo' ),
					__( 'Use LocalBusiness only when entries represent real clinic branches or departments with physical contact details.', 'open-growth-seo' ),
					__( 'Use ContactPage for appointment or contact flows, not generic service landing pages.', 'open-growth-seo' ),
				),
				'rules'       => array(
					'Person' => array( 'doctor', 'physician', 'specialist', 'provider', 'therapist', 'surgeon' ),
					'Service' => array( 'service', 'treatment', 'therapy', 'procedure', 'care' ),
					'OfferCatalog' => array( 'pricing', 'plans', 'services', 'catalog' ),
					'LocalBusiness' => array( 'location', 'clinic', 'branch', 'campus' ),
					'Review' => array( 'review', 'testimonial' ),
					'ContactPage' => array( 'contact', 'appointment', 'booking' ),
					'AboutPage' => array( 'about', 'practice' ),
				),
			),
			'marketplace' => array(
				'label'       => __( 'Marketplace / Directory', 'open-growth-seo' ),
				'description' => __( 'Best for product listings, vendor/service directories, category hubs, offer collections, and trust/review content.', 'open-growth-seo' ),
				'use_when'    => __( 'Your site has CPTs for listings, vendors, products, collections, review content, or comparison hubs.', 'open-growth-seo' ),
				'examples'    => array( 'listing -> Product', 'vendor -> LocalBusiness', 'category -> CollectionPage', 'offers -> OfferCatalog', 'review -> Review' ),
				'notes'       => array(
					__( 'Use Product only when the page is a real offerable item, not a generic category or search result.', 'open-growth-seo' ),
					__( 'Use CollectionPage for curated category, deals, or directory index pages.', 'open-growth-seo' ),
					__( 'Use LocalBusiness for vendor/storefront profiles only when they represent real businesses with contact details.', 'open-growth-seo' ),
				),
				'rules'       => array(
					'Product' => array( 'product', 'listing', 'item', 'deal' ),
					'LocalBusiness' => array( 'vendor', 'seller', 'merchant', 'store', 'shop' ),
					'CollectionPage' => array( 'collection', 'category', 'directory', 'catalog', 'marketplace' ),
					'OfferCatalog' => array( 'offers', 'pricing', 'plans' ),
					'Review' => array( 'review', 'comparison', 'testimonial' ),
					'ContactPage' => array( 'contact', 'support' ),
				),
			),
			'publisher' => array(
				'label'       => __( 'Publisher / Media / Content hub', 'open-growth-seo' ),
				'description' => __( 'Best for editorial articles, explainers, guides, author profiles, topic hubs, glossary sets, and event coverage.', 'open-growth-seo' ),
				'use_when'    => __( 'Your site uses CPTs for articles, guides, authors, hubs, glossaries, podcasts, or recurring event coverage.', 'open-growth-seo' ),
				'examples'    => array( 'article -> Article', 'guide -> Guide', 'author -> Person', 'topic-hub -> CollectionPage', 'glossary -> DefinedTermSet' ),
				'notes'       => array(
					__( 'Use TechArticle only for technical reference or implementation content, not every editorial post.', 'open-growth-seo' ),
					__( 'Use DefinedTerm and DefinedTermSet for glossary-style content where terms are explicitly defined.', 'open-growth-seo' ),
					__( 'Use CollectionPage for topic hubs and index pages that group related articles or resources.', 'open-growth-seo' ),
				),
				'rules'       => array(
					'Article' => array( 'article', 'story', 'news', 'post' ),
					'Guide' => array( 'guide', 'tutorial', 'howto', 'playbook' ),
					'Person' => array( 'author', 'editor', 'columnist', 'speaker' ),
					'CollectionPage' => array( 'hub', 'collection', 'topic', 'series' ),
					'DefinedTerm' => array( 'term', 'definition' ),
					'DefinedTermSet' => array( 'glossary', 'lexicon', 'dictionary' ),
					'Event' => array( 'event', 'conference', 'summit', 'webinar' ),
				),
			),
			'nonprofit' => array(
				'label'       => __( 'Nonprofit / Foundation / Association', 'open-growth-seo' ),
				'description' => __( 'Best for programs, initiatives, impact projects, team profiles, events, service channels, and donation/contact flows.', 'open-growth-seo' ),
				'use_when'    => __( 'Your site uses CPTs for campaigns, programs, projects, team members, chapters, or help resources.', 'open-growth-seo' ),
				'examples'    => array( 'program -> Service', 'initiative -> Project', 'team -> Person', 'chapter -> LocalBusiness', 'events -> Event' ),
				'notes'       => array(
					__( 'Use Service for real assistance or support offerings, not every informational page about your mission.', 'open-growth-seo' ),
					__( 'Use Project for campaigns and initiatives with defined scope or impact context.', 'open-growth-seo' ),
					__( 'Use LocalBusiness cautiously for physical branches or chapter offices with actual address/contact data.', 'open-growth-seo' ),
				),
				'rules'       => array(
					'Service' => array( 'program', 'service', 'support', 'help', 'resource' ),
					'Project' => array( 'project', 'initiative', 'campaign', 'impact' ),
					'Person' => array( 'team', 'staff', 'board', 'member', 'leader' ),
					'Event' => array( 'event', 'fundraiser', 'webinar', 'training' ),
					'LocalBusiness' => array( 'chapter', 'office', 'location', 'branch' ),
					'ContactPage' => array( 'contact', 'donate', 'volunteer' ),
					'AboutPage' => array( 'about', 'mission' ),
				),
			),
		);
	}

	/**
	 * @param array<string, mixed> $raw
	 * @return array<string, mixed>
	 */
	private function apply_schema_post_type_preset( string $preset, array $raw ): array {
		$records = $this->schema_preset_records();
		if ( ! isset( $records[ $preset ] ) || ! is_array( $records[ $preset ] ) ) {
			return $raw;
		}

		$existing = isset( $raw['schema_post_type_defaults'] ) && is_array( $raw['schema_post_type_defaults'] )
			? $raw['schema_post_type_defaults']
			: Settings::get( 'schema_post_type_defaults', array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		$raw['schema_post_type_defaults'] = array_replace( $existing, $this->resolve_schema_preset_defaults( $records[ $preset ] ) );
		return $raw;
	}

	/**
	 * @param array<string, mixed> $preset
	 * @return array<string, string>
	 */
	private function resolve_schema_preset_defaults( array $preset ): array {
		$rules = isset( $preset['rules'] ) && is_array( $preset['rules'] ) ? $preset['rules'] : array();
		if ( empty( $rules ) ) {
			return array();
		}

		$matches = array();
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $post_types['attachment'] );

		foreach ( $post_types as $post_type => $object ) {
			if ( ! is_object( $object ) ) {
				continue;
			}
			$haystack_parts = array( (string) $post_type );
			$haystack_parts[] = isset( $object->label ) ? (string) $object->label : '';
			if ( isset( $object->labels ) && is_object( $object->labels ) ) {
				$haystack_parts[] = isset( $object->labels->singular_name ) ? (string) $object->labels->singular_name : '';
				$haystack_parts[] = isset( $object->labels->name ) ? (string) $object->labels->name : '';
			}
			$haystack_parts[] = isset( $object->description ) ? (string) $object->description : '';
			$haystack = strtolower( implode( ' ', array_filter( array_map( 'strval', $haystack_parts ) ) ) );

			foreach ( $rules as $schema_type => $needles ) {
				if ( ! is_array( $needles ) ) {
					continue;
				}
				foreach ( $needles as $needle ) {
					$needle = strtolower( trim( (string) $needle ) );
					if ( '' !== $needle && str_contains( $haystack, $needle ) ) {
						$matches[ (string) $post_type ] = (string) $schema_type;
						break 2;
					}
				}
			}
		}

		return $matches;
	}

	/**
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	private function schema_mapping_export_payload( array $settings ): array {
		$defaults = isset( $settings['schema_post_type_defaults'] ) && is_array( $settings['schema_post_type_defaults'] ) ? $settings['schema_post_type_defaults'] : array();

		return array(
			'schema_version'            => 1,
			'generated_at'              => time(),
			'plugin_version'            => defined( 'OGS_SEO_VERSION' ) ? (string) OGS_SEO_VERSION : 'unknown',
			'site_url'                  => home_url( '/' ),
			'export_type'               => 'schema_post_type_defaults',
			'schema_post_type_defaults' => $this->sanitize_schema_post_type_defaults( $defaults ),
		);
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, string>
	 */
	private function schema_mapping_payload_defaults( array $payload ): array {
		$defaults = isset( $payload['schema_post_type_defaults'] ) && is_array( $payload['schema_post_type_defaults'] ) ? $payload['schema_post_type_defaults'] : array();
		return $this->sanitize_schema_post_type_defaults( $defaults );
	}

	/**
	 * @param array<string, mixed> $defaults
	 * @return array<string, string>
	 */
	private function sanitize_schema_post_type_defaults( array $defaults ): array {
		$allowed_types = array_keys( $this->schema_post_type_default_options() );
		$allowed_types = array_filter( array_map( 'strval', $allowed_types ) );
		$public_post_types = get_post_types( array( 'public' => true ), 'names' );
		$public_post_types = array_map( 'sanitize_key', is_array( $public_post_types ) ? $public_post_types : array() );

		$clean = array();
		foreach ( $defaults as $post_type => $schema_type ) {
			$post_type   = sanitize_key( (string) $post_type );
			$schema_type = trim( (string) $schema_type );
			if ( '' === $post_type || ! in_array( $post_type, $public_post_types, true ) ) {
				continue;
			}
			if ( '' !== $schema_type && ! in_array( $schema_type, $allowed_types, true ) ) {
				continue;
			}
			$clean[ $post_type ] = $schema_type;
		}

		return $clean;
	}

	/**
	 * @return array<string, string>
	 */
	private function schema_post_type_default_options(): array {
		return array(
			''                              => __( 'Auto detect (recommended unless this CPT is fixed-purpose)', 'open-growth-seo' ),
			'Article'                       => 'Article',
			'BlogPosting'                   => 'BlogPosting',
			'NewsArticle'                   => 'NewsArticle',
			'TechArticle'                   => 'TechArticle',
			'Service'                       => 'Service',
			'OfferCatalog'                  => 'OfferCatalog',
			'Person'                        => 'Person',
			'Course'                        => 'Course',
			'EducationalOccupationalProgram' => 'EducationalOccupationalProgram',
			'FAQPage'                       => 'FAQPage',
			'ProfilePage'                   => 'ProfilePage',
			'AboutPage'                     => 'AboutPage',
			'ContactPage'                   => 'ContactPage',
			'CollectionPage'                => 'CollectionPage',
			'DiscussionForumPosting'        => 'DiscussionForumPosting',
			'QAPage'                        => 'QAPage',
			'VideoObject'                   => 'VideoObject',
			'Event'                         => 'Event',
			'EventSeries'                   => 'EventSeries',
			'JobPosting'                    => 'JobPosting',
			'Recipe'                        => 'Recipe',
			'SoftwareApplication'           => 'SoftwareApplication',
			'WebAPI'                        => 'WebAPI',
			'Project'                       => 'Project',
			'Review'                        => 'Review',
			'Guide'                         => 'Guide',
			'Dataset'                       => 'Dataset',
			'DefinedTerm'                   => 'DefinedTerm',
			'DefinedTermSet'                => 'DefinedTermSet',
			'ScholarlyArticle'              => 'ScholarlyArticle',
			'Product'                       => 'Product',
		);
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	private function render_schema_local_tab( array $settings ): void {
		$records     = LocalSeoLocations::records( $settings );
		$diagnostics = LocalSeoLocations::diagnostics( $settings );
		$error_count = (int) ( $diagnostics['errorCount'] ?? 0 );
		$warning_count = (int) ( $diagnostics['warningCount'] ?? 0 );

		$published = 0;
		$draft     = 0;
		$publishable = 0;
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			if ( 'published' === (string) ( $record['status'] ?? 'draft' ) ) {
				++$published;
			} else {
				++$draft;
			}
			if ( LocalSeoLocations::is_publishable( $record ) ) {
				++$publishable;
			}
		}

		echo '<div class="ogs-card">';
		echo '<h2>' . esc_html__( 'Local / Multi-Location Schema', 'open-growth-seo' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'One canonical landing page per real location. LocalBusiness emits only when the location record is complete and page content is substantive.', 'open-growth-seo' ) . '</p>';
		echo '<div class="ogs-actions">';
		echo '<a class="button button-secondary" href="' . esc_url(
			add_query_arg(
				array(
					'page'       => 'ogs-seo-schema',
					'schema_tab' => 'local',
				),
				admin_url( 'admin.php' )
			)
		) . '">' . esc_html__( 'Recheck all locations', 'open-growth-seo' ) . '</a>';
		echo '<a class="button button-secondary" href="' . esc_url(
			add_query_arg(
				array(
					'page'       => 'ogs-seo-schema',
					'schema_tab' => 'validation',
				),
				admin_url( 'admin.php' )
			)
		) . '">' . esc_html__( 'Open validation tab', 'open-growth-seo' ) . '</a>';
		echo '</div>';
		$this->render_local_locations_table( $settings );
		echo '</div>';

		$duplicate_nap = 0;
		foreach ( (array) ( $diagnostics['warnings'] ?? array() ) as $warning ) {
			if ( is_array( $warning ) && 'duplicate_nap' === (string) ( $warning['code'] ?? '' ) ) {
				++$duplicate_nap;
			}
		}
		echo '<div class="ogs-grid ogs-grid-overview">';
		echo '<div class="ogs-card"><h3>' . esc_html__( 'Total location records', 'open-growth-seo' ) . '</h3><p class="ogs-status">' . esc_html( (string) count( $records ) ) . '</p></div>';
		echo '<div class="ogs-card"><h3>' . esc_html__( 'Published records', 'open-growth-seo' ) . '</h3><p class="ogs-status">' . esc_html( (string) $published ) . '</p><p class="description">' . esc_html__( 'Rows intended to emit LocalBusiness when eligible.', 'open-growth-seo' ) . '</p></div>';
		echo '<div class="ogs-card"><h3>' . esc_html__( 'Publishable now', 'open-growth-seo' ) . '</h3><p class="ogs-status ' . esc_attr( $publishable >= $published ? 'ogs-status-good' : 'ogs-status-warn' ) . '">' . esc_html( (string) $publishable ) . '</p><p class="description">' . esc_html__( 'Published rows with complete data and published landing pages.', 'open-growth-seo' ) . '</p></div>';
		echo '<div class="ogs-card"><h3>' . esc_html__( 'Validation errors', 'open-growth-seo' ) . '</h3><p class="ogs-status ' . esc_attr( $error_count > 0 ? 'ogs-status-bad' : 'ogs-status-good' ) . '">' . esc_html( (string) $error_count ) . '</p></div>';
		echo '<div class="ogs-card"><h3>' . esc_html__( 'Warnings', 'open-growth-seo' ) . '</h3><p class="ogs-status ' . esc_attr( $warning_count > 0 ? 'ogs-status-warn' : 'ogs-status-good' ) . '">' . esc_html( (string) $warning_count ) . '</p></div>';
		echo '<div class="ogs-card"><h3>' . esc_html__( 'Duplicate NAP clusters', 'open-growth-seo' ) . '</h3><p class="ogs-status ' . esc_attr( $duplicate_nap > 0 ? 'ogs-status-warn' : 'ogs-status-good' ) . '">' . esc_html( (string) $duplicate_nap ) . '</p></div>';
		echo '<div class="ogs-card"><h3>' . esc_html__( 'Draft records', 'open-growth-seo' ) . '</h3><p class="ogs-status">' . esc_html( (string) $draft ) . '</p><p class="description">' . esc_html__( 'Draft rows are excluded from runtime output.', 'open-growth-seo' ) . '</p></div>';
		echo '</div>';

		if ( ! empty( $diagnostics['errors'] ) ) {
			echo '<div class="notice notice-error inline"><p><strong>' . esc_html__( 'Location data errors detected', 'open-growth-seo' ) . '</strong></p><ul>';
			foreach ( (array) $diagnostics['errors'] as $error ) {
				if ( ! is_array( $error ) || empty( $error['message'] ) ) {
					continue;
				}
				echo '<li>' . esc_html( (string) $error['message'] ) . '</li>';
			}
			echo '</ul></div>';
		}
		if ( ! empty( $diagnostics['warnings'] ) ) {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Location data warnings', 'open-growth-seo' ) . '</strong></p><ul>';
			foreach ( (array) $diagnostics['warnings'] as $warning ) {
				if ( ! is_array( $warning ) || empty( $warning['message'] ) ) {
					continue;
				}
				echo '<li>' . esc_html( (string) $warning['message'] ) . '</li>';
			}
			echo '</ul></div>';
		}

		$landing_clusters = $this->schema_location_duplicate_landing_clusters( $records );
		if ( ! empty( $landing_clusters ) ) {
			echo '<div class="ogs-card">';
			echo '<h3>' . esc_html__( 'Duplicate landing page clusters', 'open-growth-seo' ) . '</h3>';
			echo '<p class="description">' . esc_html__( 'Each landing page should map to a single real location. Resolve duplicates by keeping one canonical location record per page.', 'open-growth-seo' ) . '</p>';
			echo '<table class="widefat striped ogs-schema-table"><thead><tr><th>' . esc_html__( 'Landing page', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Location IDs', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Next action', 'open-growth-seo' ) . '</th></tr></thead><tbody>';
			foreach ( $landing_clusters as $cluster ) {
				if ( ! is_array( $cluster ) ) {
					continue;
				}
				$page_id = absint( $cluster['landing_page_id'] ?? 0 );
				$label = $page_id > 0 ? (string) get_the_title( $page_id ) : __( 'Unknown page', 'open-growth-seo' );
				$edit_url = $page_id > 0 ? get_edit_post_link( $page_id ) : '';
				$ids = isset( $cluster['location_ids'] ) && is_array( $cluster['location_ids'] ) ? $cluster['location_ids'] : array();
				echo '<tr>';
				echo '<td>';
				if ( ! empty( $edit_url ) ) {
					echo '<a href="' . esc_url( (string) $edit_url ) . '">' . esc_html( $label ) . '</a>';
				} else {
					echo esc_html( $label );
				}
				echo '</td>';
				echo '<td><code>' . esc_html( implode( ', ', array_map( 'strval', $ids ) ) ) . '</code></td>';
				echo '<td>' . esc_html__( 'Keep one row mapped to this page and set other duplicates to draft or another landing page.', 'open-growth-seo' ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
			echo '</div>';
		}

		$nap_clusters = $this->schema_location_duplicate_nap_clusters( $records );
		if ( ! empty( $nap_clusters ) ) {
			echo '<div class="ogs-card">';
			echo '<h3>' . esc_html__( 'Duplicate NAP clusters', 'open-growth-seo' ) . '</h3>';
			echo '<p class="description">' . esc_html__( 'Potential duplicate NAP patterns can create local-entity ambiguity.', 'open-growth-seo' ) . '</p>';
			echo '<table class="widefat striped ogs-schema-table"><thead><tr><th>' . esc_html__( 'NAP signature', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Location IDs', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Resolution hint', 'open-growth-seo' ) . '</th></tr></thead><tbody>';
			foreach ( $nap_clusters as $signature => $ids ) {
				if ( ! is_array( $ids ) ) {
					continue;
				}
				echo '<tr>';
				echo '<td><code>' . esc_html( (string) $signature ) . '</code></td>';
				echo '<td><code>' . esc_html( implode( ', ', array_map( 'strval', $ids ) ) ) . '</code></td>';
				echo '<td>' . esc_html__( 'Ensure each physical location has unique NAP or merge duplicate records.', 'open-growth-seo' ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
			echo '</div>';
		}

		echo '<div class="ogs-card">';
		echo '<h3>' . esc_html__( 'Legacy fallback (single location)', 'open-growth-seo' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Used only when no multi-location records exist. Keep this only for backward compatibility.', 'open-growth-seo' ) . '</p>';
		$this->field_text( 'schema_local_business_type', __( 'LocalBusiness subtype', 'open-growth-seo' ), (string) $settings['schema_local_business_type'] );
		$this->field_text( 'schema_local_phone', __( 'Local phone', 'open-growth-seo' ), (string) $settings['schema_local_phone'] );
		$this->field_text( 'schema_local_street', __( 'Street address', 'open-growth-seo' ), (string) $settings['schema_local_street'] );
		$this->field_text( 'schema_local_city', __( 'City', 'open-growth-seo' ), (string) $settings['schema_local_city'] );
		$this->field_text( 'schema_local_region', __( 'Region/State', 'open-growth-seo' ), (string) $settings['schema_local_region'] );
		$this->field_text( 'schema_local_postal_code', __( 'Postal code', 'open-growth-seo' ), (string) $settings['schema_local_postal_code'] );
		$this->field_text( 'schema_local_country', __( 'Country code', 'open-growth-seo' ), (string) $settings['schema_local_country'] );
		echo '</div>';
	}

	/**
	 * @param array<int, array<string, mixed>> $records
	 * @return array<int, array<string, mixed>>
	 */
	private function schema_location_duplicate_landing_clusters( array $records ): array {
		$clusters = array();
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			$page_id = absint( $record['landing_page_id'] ?? 0 );
			if ( $page_id <= 0 ) {
				continue;
			}
			if ( ! isset( $clusters[ $page_id ] ) ) {
				$clusters[ $page_id ] = array();
			}
			$clusters[ $page_id ][] = (string) ( $record['id'] ?? '' );
		}
		$result = array();
		foreach ( $clusters as $page_id => $location_ids ) {
			$location_ids = array_values( array_filter( array_map( 'sanitize_key', (array) $location_ids ) ) );
			if ( count( $location_ids ) < 2 ) {
				continue;
			}
			$result[] = array(
				'landing_page_id' => (int) $page_id,
				'location_ids'    => $location_ids,
			);
		}
		return $result;
	}

	/**
	 * @param array<int, array<string, mixed>> $records
	 * @return array<string, array<int, string>>
	 */
	private function schema_location_duplicate_nap_clusters( array $records ): array {
		$clusters = array();
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			$signature = strtolower(
				trim( (string) ( $record['name'] ?? '' ) ) . '|' .
				preg_replace( '/\D+/', '', (string) ( $record['phone'] ?? '' ) ) . '|' .
				strtolower( trim( (string) ( $record['street'] ?? '' ) ) ) . '|' .
				strtolower( trim( (string) ( $record['city'] ?? '' ) ) ) . '|' .
				strtolower( trim( (string) ( $record['postal_code'] ?? '' ) ) ) . '|' .
				strtolower( trim( (string) ( $record['country'] ?? '' ) ) )
			);
			$signature = trim( $signature, '|' );
			if ( '' === $signature ) {
				continue;
			}
			if ( ! isset( $clusters[ $signature ] ) ) {
				$clusters[ $signature ] = array();
			}
			$clusters[ $signature ][] = (string) ( $record['id'] ?? '' );
		}
		foreach ( $clusters as $signature => $ids ) {
			$ids = array_values( array_filter( array_map( 'sanitize_key', (array) $ids ) ) );
			if ( count( $ids ) < 2 ) {
				unset( $clusters[ $signature ] );
				continue;
			}
			$clusters[ $signature ] = $ids;
		}
		return $clusters;
	}

	/**
	 * @param array<int, array<string, mixed>> $graph
	 * @return array<int, array<string, mixed>>
	 */
	private function schema_graph_relationship_rows( array $graph ): array {
		$known_ids = array();
		foreach ( $graph as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$node_id = isset( $node['@id'] ) ? trim( (string) $node['@id'] ) : '';
			if ( '' !== $node_id ) {
				$known_ids[ $node_id ] = true;
			}
		}

		$rows = array();
		foreach ( $graph as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$type = isset( $node['@type'] ) ? (string) $node['@type'] : __( 'Unknown', 'open-growth-seo' );
			$id   = isset( $node['@id'] ) ? (string) $node['@id'] : '';
			$links = array();
			$this->schema_graph_collect_relationships( $node, $links );
			$diagnostics = array();
			if ( '' === $id ) {
				$diagnostics[] = __( 'Node has no @id, so relationship tracing is weaker than it should be.', 'open-growth-seo' );
			}
			if ( 'LocalBusiness' === $type && empty( $node['parentOrganization']['@id'] ) ) {
				$diagnostics[] = __( 'LocalBusiness should reference the site Organization node via parentOrganization.', 'open-growth-seo' );
			}
			if ( 'WebPage' === $type && empty( $node['isPartOf']['@id'] ) ) {
				$diagnostics[] = __( 'WebPage should point back to WebSite via isPartOf for clearer graph inheritance.', 'open-growth-seo' );
			}
			foreach ( $links as $link ) {
				$parts = explode( ' -> ', (string) $link, 2 );
				$target = isset( $parts[1] ) ? trim( (string) $parts[1] ) : '';
				if ( '' === $target || isset( $known_ids[ $target ] ) ) {
					continue;
				}
				if ( str_contains( $target, '#' ) && str_starts_with( $target, home_url( '/' ) ) ) {
					$diagnostics[] = sprintf( __( 'Relationship target %s is referenced but not present in the current graph.', 'open-growth-seo' ), $target );
				}
			}

			$rows[] = array(
				'type'        => $type,
				'label'       => $this->schema_graph_node_label( $node ),
				'id'          => $id,
				'links'       => array_values( array_unique( $links ) ),
				'diagnostics' => array_values( array_unique( $diagnostics ) ),
			);
		}

		return $rows;
	}

	/**
	 * @param array<string, mixed> $node
	 * @param array<int, string>   $links
	 */
	private function schema_graph_collect_relationships( array $node, array &$links ): void {
		$relations = array(
			'publisher',
			'isPartOf',
			'mainEntity',
			'mainEntityOfPage',
			'parentOrganization',
			'department',
			'itemListElement',
			'item',
			'author',
			'acceptedAnswer',
			'offers',
			'review',
			'hasMerchantReturnPolicy',
			'shippingDetails',
		);
		foreach ( $relations as $relation ) {
			if ( ! array_key_exists( $relation, $node ) ) {
				continue;
			}
			$this->schema_graph_collect_relationship_value( $relation, $node[ $relation ], $links );
		}
	}

	/**
	 * @param mixed $value
	 * @param array<int, string> $links
	 */
	private function schema_graph_collect_relationship_value( string $relation, $value, array &$links ): void {
		if ( is_array( $value ) && isset( $value['@id'] ) ) {
			$links[] = $relation . ' -> ' . (string) $value['@id'];
			return;
		}
		if ( is_array( $value ) && isset( $value['item']['@id'] ) ) {
			$links[] = $relation . ' -> ' . (string) $value['item']['@id'];
			return;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( is_array( $item ) ) {
					$this->schema_graph_collect_relationship_value( $relation, $item, $links );
				}
			}
		}
	}

	/**
	 * @param array<string, mixed> $node
	 */
	private function schema_graph_node_label( array $node ): string {
		if ( ! empty( $node['name'] ) ) {
			return sanitize_text_field( (string) $node['name'] );
		}
		if ( ! empty( $node['headline'] ) ) {
			return sanitize_text_field( (string) $node['headline'] );
		}
		if ( ! empty( $node['title'] ) ) {
			return sanitize_text_field( (string) $node['title'] );
		}
		return isset( $node['@type'] ) ? (string) $node['@type'] : __( 'Schema node', 'open-growth-seo' );
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	private function render_schema_woo_tab( array $settings ): void {
		echo '<div class="ogs-card">';
		echo '<h2>' . esc_html__( 'WooCommerce / Product Schema', 'open-growth-seo' ) . '</h2>';
		if ( ! class_exists( 'WooCommerce' ) ) {
			echo '<p class="ogs-empty">' . esc_html__( 'WooCommerce is not active. Product schema controls stay hidden until WooCommerce is detected.', 'open-growth-seo' ) . '</p>';
			echo '</div>';
			return;
		}
		$this->field_select(
			'woo_schema_mode',
			__( 'Product schema source', 'open-growth-seo' ),
			(string) $settings['woo_schema_mode'],
			array(
				'native' => __( 'WooCommerce native (recommended)', 'open-growth-seo' ),
				'ogs'    => __( 'Open Growth SEO takeover', 'open-growth-seo' ),
			)
		);
		$this->field_text( 'woo_min_product_description_words', __( 'Minimum product description words', 'open-growth-seo' ), (string) $settings['woo_min_product_description_words'] );
		$this->field_select(
			'woo_archive_schema_mode',
			__( 'Archive schema mode', 'open-growth-seo' ),
			(string) ( $settings['woo_archive_schema_mode'] ?? 'none' ),
			array(
				'none'       => __( 'None (safe default)', 'open-growth-seo' ),
				'collection' => __( 'CollectionPage hints', 'open-growth-seo' ),
			)
		);
		$this->field_select(
			'woo_product_archive',
			__( 'Shop archive indexing', 'open-growth-seo' ),
			(string) ( $settings['woo_product_archive'] ?? 'index' ),
			array(
				'index'   => __( 'Index', 'open-growth-seo' ),
				'noindex' => __( 'Noindex', 'open-growth-seo' ),
			)
		);
		$this->field_select(
			'woo_product_cat_archive',
			__( 'Product category indexing', 'open-growth-seo' ),
			(string) ( $settings['woo_product_cat_archive'] ?? 'index' ),
			array(
				'index'   => __( 'Index', 'open-growth-seo' ),
				'noindex' => __( 'Noindex', 'open-growth-seo' ),
			)
		);
		$this->field_select(
			'woo_product_tag_archive',
			__( 'Product tag indexing', 'open-growth-seo' ),
			(string) ( $settings['woo_product_tag_archive'] ?? 'index' ),
			array(
				'index'   => __( 'Index', 'open-growth-seo' ),
				'noindex' => __( 'Noindex', 'open-growth-seo' ),
			)
		);
		$this->field_select(
			'woo_filter_results',
			__( 'Faceted/filter URL indexing', 'open-growth-seo' ),
			(string) ( $settings['woo_filter_results'] ?? 'noindex' ),
			array(
				'noindex' => __( 'Noindex (recommended)', 'open-growth-seo' ),
				'index'   => __( 'Index (expert)', 'open-growth-seo' ),
			)
		);
		$this->field_select(
			'woo_filter_canonical_target',
			__( 'Faceted/filter canonical policy', 'open-growth-seo' ),
			(string) ( $settings['woo_filter_canonical_target'] ?? 'base' ),
			array(
				'base' => __( 'Base archive URL (recommended)', 'open-growth-seo' ),
				'self' => __( 'Self canonical', 'open-growth-seo' ),
				'none' => __( 'No canonical (expert risk)', 'open-growth-seo' ),
			)
		);
		$this->field_select(
			'woo_pagination_results',
			__( 'Pagination indexing', 'open-growth-seo' ),
			(string) ( $settings['woo_pagination_results'] ?? 'index' ),
			array(
				'index'   => __( 'Index', 'open-growth-seo' ),
				'noindex' => __( 'Noindex', 'open-growth-seo' ),
			)
		);
		$this->field_select(
			'woo_pagination_canonical_target',
			__( 'Pagination canonical policy', 'open-growth-seo' ),
			(string) ( $settings['woo_pagination_canonical_target'] ?? 'self' ),
			array(
				'self'       => __( 'Self canonical (recommended)', 'open-growth-seo' ),
				'first_page' => __( 'First page canonical (expert)', 'open-growth-seo' ),
			)
		);
		$this->field_text( 'woo_archive_social_title_template', __( 'Archive social title template', 'open-growth-seo' ), (string) ( $settings['woo_archive_social_title_template'] ?? '' ) );
		$this->field_text( 'woo_archive_social_description_template', __( 'Archive social description template', 'open-growth-seo' ), (string) ( $settings['woo_archive_social_description_template'] ?? '' ) );
		echo '<p class="description">' . esc_html__( 'Single Product schema is emitted only on single product pages. Archive/search/category pages are handled with separate archive policy controls.', 'open-growth-seo' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Archive/search controls coordinate indexing, canonical policy, and schema mode to avoid contradictory WooCommerce output states.', 'open-growth-seo' ) . '</p>';

		$messages = $this->woocommerce_archive_validation_messages( $settings );
		if ( ! empty( $messages ) ) {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Archive policy warnings', 'open-growth-seo' ) . '</strong></p><ul>';
			foreach ( $messages as $message ) {
				echo '<li>' . esc_html( (string) $message ) . '</li>';
			}
			echo '</ul></div>';
		} else {
			echo '<p class="description">' . esc_html__( 'Archive/indexing/canonical settings currently look coherent for Woo surfaces.', 'open-growth-seo' ) . '</p>';
		}

		$archive_state = array(
			'shop'       => (string) ( $settings['woo_product_archive'] ?? 'index' ),
			'categories' => (string) ( $settings['woo_product_cat_archive'] ?? 'index' ),
			'tags'       => (string) ( $settings['woo_product_tag_archive'] ?? 'index' ),
			'filters'    => (string) ( $settings['woo_filter_results'] ?? 'noindex' ),
			'pagination' => (string) ( $settings['woo_pagination_results'] ?? 'index' ),
		);
		echo '<table class="widefat striped ogs-schema-table"><thead><tr><th>' . esc_html__( 'Surface', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Indexing', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Runtime intent', 'open-growth-seo' ) . '</th></tr></thead><tbody>';
		foreach ( $archive_state as $surface => $intent ) {
			$label = ucfirst( str_replace( '_', ' ', $surface ) );
			$runtime = 'index' === $intent
				? __( 'Can be indexed when canonical is stable.', 'open-growth-seo' )
				: __( 'Excluded from indexing to reduce low-value crawl surfaces.', 'open-growth-seo' );
			echo '<tr><td>' . esc_html( $label ) . '</td><td><span class="ogs-schema-chip ogs-schema-chip-' . esc_attr( 'index' === $intent ? 'valid' : 'warning' ) . '">' . esc_html( strtoupper( $intent ) ) . '</span></td><td>' . esc_html( $runtime ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * @param array<string, mixed> $settings
	 * @param array<string, mixed> $inspect
	 */
	private function render_schema_expert_tab( array $settings, array $inspect ): void {
		echo '<div class="ogs-card">';
		echo '<h2>' . esc_html__( 'Expert Schema Controls', 'open-growth-seo' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Advanced controls remain validation-aware. Forcing a risky state still surfaces warnings and conflicts.', 'open-growth-seo' ) . '</p>';
		$this->field_select(
			'seo_masters_plus_schema_guardrails',
			__( 'Schema guardrails (SEO MASTERS PLUS)', 'open-growth-seo' ),
			(string) ( $settings['seo_masters_plus_schema_guardrails'] ?? 1 ),
			array(
				'1' => __( 'Enabled (recommended)', 'open-growth-seo' ),
				'0' => __( 'Disabled (expert risk)', 'open-growth-seo' ),
			)
		);
		$this->field_select(
			'schema_paywall_enabled',
			__( 'Subscription / Paywall support', 'open-growth-seo' ),
			(string) $settings['schema_paywall_enabled'],
			array(
				'1' => __( 'Enabled', 'open-growth-seo' ),
				'0' => __( 'Disabled', 'open-growth-seo' ),
			)
		);
		echo '</div>';

		$support = SupportMatrix::all();
		echo '<div class="ogs-card">';
		echo '<h3>' . esc_html__( 'Support matrix and badges', 'open-growth-seo' ) . '</h3>';
		echo '<table class="widefat striped ogs-schema-table"><thead><tr><th>' . esc_html__( 'Type', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Support', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Risk', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Description', 'open-growth-seo' ) . '</th></tr></thead><tbody>';
		foreach ( $support as $type => $meta ) {
			if ( ! is_array( $meta ) ) {
				continue;
			}
			echo '<tr>';
			echo '<td><strong>' . esc_html( (string) ( $meta['label'] ?? $type ) ) . '</strong></td>';
			echo '<td><span class="ogs-schema-chip ogs-schema-chip-support">' . esc_html( SupportMatrix::support_badge( (string) ( $meta['support'] ?? '' ) ) ) . '</span></td>';
			echo '<td><span class="ogs-schema-chip ogs-schema-chip-risk">' . esc_html( SupportMatrix::risk_badge( (string) ( $meta['risk'] ?? 'medium' ) ) ) . '</span></td>';
			echo '<td>' . esc_html( (string) ( $meta['description'] ?? '' ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '</div>';

		$link_snapshot = LinkGraph::snapshot();
		$remediation_queue = isset( $link_snapshot['remediation_queue'] ) && is_array( $link_snapshot['remediation_queue'] ) ? $link_snapshot['remediation_queue'] : array();
		echo '<div class="ogs-card">';
		echo '<h3>' . esc_html__( 'Link graph remediation queue', 'open-growth-seo' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Confidence-ranked queue for orphan/weak-link remediation. Use this to prioritize schema-ready pages that lack internal support.', 'open-growth-seo' ) . '</p>';
		if ( empty( $remediation_queue ) ) {
			echo '<p class="ogs-empty">' . esc_html__( 'No orphan or weak-link pages detected in current graph snapshot.', 'open-growth-seo' ) . '</p>';
		} else {
			echo '<table class="widefat striped ogs-schema-table"><thead><tr><th>' . esc_html__( 'Post', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Status', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Confidence', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Priority', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Reasons', 'open-growth-seo' ) . '</th></tr></thead><tbody>';
			foreach ( array_slice( $remediation_queue, 0, 15 ) as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$post_id = absint( $item['post_id'] ?? 0 );
				$title = $post_id > 0 ? get_the_title( $post_id ) : __( 'Unknown post', 'open-growth-seo' );
				$edit_url = $post_id > 0 ? get_edit_post_link( $post_id ) : '';
				$status = sanitize_key( (string) ( $item['status'] ?? 'orphan' ) );
				$confidence = sanitize_key( (string) ( $item['confidence'] ?? 'low' ) );
				$reasons = isset( $item['reasons'] ) && is_array( $item['reasons'] ) ? $item['reasons'] : array();
				echo '<tr><td>';
				if ( ! empty( $edit_url ) ) {
					echo '<a href="' . esc_url( (string) $edit_url ) . '">' . esc_html( (string) $title ) . '</a>';
				} else {
					echo esc_html( (string) $title );
				}
				echo '</td><td><span class="ogs-schema-chip ogs-schema-chip-' . esc_attr( sanitize_html_class( $status ) ) . '">' . esc_html( ucfirst( $status ) ) . '</span></td><td>' . esc_html( ucfirst( $confidence ) ) . '</td><td>' . esc_html( (string) absint( $item['priority'] ?? 0 ) ) . '</td><td>' . esc_html( implode( ' | ', array_map( 'strval', $reasons ) ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';

		$gsc_report = IntegrationsManager::google_search_console_operational_report( false );
		echo '<div class="ogs-card">';
		echo '<h3>' . esc_html__( 'Search Console operational trends', 'open-growth-seo' ) . '</h3>';
		if ( ! is_array( $gsc_report ) || empty( $gsc_report['ok'] ) ) {
			echo '<p class="ogs-empty">' . esc_html__( 'Search Console operational report is unavailable. Connect credentials in Integrations to enable trend snapshots and action queues.', 'open-growth-seo' ) . '</p>';
		} else {
			$window_deltas = isset( $gsc_report['window_deltas'] ) && is_array( $gsc_report['window_deltas'] ) ? $gsc_report['window_deltas'] : array();
			$action_queue = isset( $gsc_report['action_queue'] ) && is_array( $gsc_report['action_queue'] ) ? $gsc_report['action_queue'] : array();
			echo '<p class="description">' . esc_html__( 'This queue prioritizes pages by operational impact instead of vanity metrics.', 'open-growth-seo' ) . '</p>';
			echo '<table class="widefat striped ogs-schema-table"><tbody>';
			echo '<tr><th>' . esc_html__( 'Property', 'open-growth-seo' ) . '</th><td>' . esc_html( (string) ( $gsc_report['property'] ?? '' ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Status', 'open-growth-seo' ) . '</th><td>' . esc_html( (string) ( $gsc_report['state_label'] ?? '' ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Clicks delta', 'open-growth-seo' ) . '</th><td>' . esc_html( isset( $window_deltas['clicks_delta_pct'] ) && null !== $window_deltas['clicks_delta_pct'] ? (string) $window_deltas['clicks_delta_pct'] . '%' : __( 'n/a', 'open-growth-seo' ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Impressions delta', 'open-growth-seo' ) . '</th><td>' . esc_html( isset( $window_deltas['impressions_delta_pct'] ) && null !== $window_deltas['impressions_delta_pct'] ? (string) $window_deltas['impressions_delta_pct'] . '%' : __( 'n/a', 'open-growth-seo' ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Snapshots stored', 'open-growth-seo' ) . '</th><td>' . esc_html( (string) count( (array) ( $gsc_report['snapshots'] ?? array() ) ) ) . '</td></tr>';
			echo '</tbody></table>';
			if ( ! empty( $action_queue ) ) {
				echo '<h4>' . esc_html__( 'Action queue', 'open-growth-seo' ) . '</h4>';
				echo '<table class="widefat striped ogs-schema-table"><thead><tr><th>' . esc_html__( 'Priority', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Page', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Reason', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Next action', 'open-growth-seo' ) . '</th></tr></thead><tbody>';
				foreach ( array_slice( $action_queue, 0, 8 ) as $action_row ) {
					if ( ! is_array( $action_row ) ) {
						continue;
					}
					$page_url = (string) ( $action_row['page'] ?? '' );
					echo '<tr><td>' . esc_html( (string) absint( $action_row['priority'] ?? 0 ) ) . '</td><td>' . ( '' !== $page_url ? '<code>' . esc_html( $page_url ) . '</code>' : esc_html__( 'Portfolio-level', 'open-growth-seo' ) ) . '</td><td>' . esc_html( (string) ( $action_row['reason'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $action_row['action'] ?? '' ) ) . '</td></tr>';
				}
				echo '</tbody></table>';
			}
		}
		echo '</div>';

		$this->render_schema_validation_tab( $inspect );
	}

	/**
	 * @param array<string, mixed> $inspect
	 */
	private function render_schema_validation_tab( array $inspect ): void {
		$conflicts = isset( $inspect['conflicts'] ) && is_array( $inspect['conflicts'] ) ? $inspect['conflicts'] : array();
		$node_reports = isset( $inspect['node_reports'] ) && is_array( $inspect['node_reports'] ) ? $inspect['node_reports'] : array();

		echo '<div class="ogs-card">';
		echo '<h2>' . esc_html__( 'Validation & Conflicts', 'open-growth-seo' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Each issue includes what is wrong and what to change next. Use Runtime Inspector for exact emitted JSON-LD.', 'open-growth-seo' ) . '</p>';
		echo '<div class="ogs-actions">';
		echo '<a class="button button-secondary" href="' . esc_url(
			add_query_arg(
				array(
					'page'       => 'ogs-seo-schema',
					'schema_tab' => 'runtime',
				),
				admin_url( 'admin.php' )
			)
		) . '">' . esc_html__( 'Inspect runtime now', 'open-growth-seo' ) . '</a>';
		echo '<a class="button button-secondary" href="' . esc_url(
			add_query_arg(
				array(
					'page'       => 'ogs-seo-schema',
					'schema_tab' => 'rules',
				),
				admin_url( 'admin.php' )
			)
		) . '">' . esc_html__( 'Go to schema rules', 'open-growth-seo' ) . '</a>';
		echo '</div>';
		if ( empty( $conflicts ) ) {
			echo '<p class="ogs-empty">' . esc_html__( 'No active schema conflicts detected for current inspect target.', 'open-growth-seo' ) . '</p>';
		} else {
			echo '<table class="widefat striped ogs-schema-table"><thead><tr><th>' . esc_html__( 'Severity', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Issue', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Recommended action', 'open-growth-seo' ) . '</th></tr></thead><tbody>';
			foreach ( $conflicts as $conflict ) {
				if ( ! is_array( $conflict ) ) {
					continue;
				}
				$severity = sanitize_key( (string) ( $conflict['severity'] ?? 'minor' ) );
				echo '<tr>';
				echo '<td><span class="ogs-schema-chip ogs-schema-chip-' . esc_attr( sanitize_html_class( $severity ) ) . '">' . esc_html( ucfirst( $severity ) ) . '</span></td>';
				echo '<td>' . esc_html( (string) ( $conflict['message'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $conflict['recommendation'] ?? '' ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';

		echo '<div class="ogs-card">';
		echo '<h3>' . esc_html__( 'Node validation reports', 'open-growth-seo' ) . '</h3>';
		if ( empty( $node_reports ) ) {
			echo '<p class="ogs-empty">' . esc_html__( 'No node reports available for this inspect context.', 'open-growth-seo' ) . '</p>';
		} else {
			echo '<table class="widefat striped ogs-schema-table"><thead><tr><th>' . esc_html__( 'Type', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Status', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Support', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Risk', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Notes', 'open-growth-seo' ) . '</th></tr></thead><tbody>';
			foreach ( $node_reports as $report ) {
				if ( ! is_array( $report ) ) {
					continue;
				}
				$messages = isset( $report['messages'] ) && is_array( $report['messages'] ) ? $report['messages'] : array();
				echo '<tr>';
				echo '<td>' . esc_html( (string) ( $report['type'] ?? '' ) ) . '</td>';
				echo '<td><span class="ogs-schema-chip ogs-schema-chip-' . esc_attr( sanitize_html_class( (string) ( $report['status'] ?? 'blocked' ) ) ) . '">' . esc_html( ucfirst( str_replace( '_', ' ', (string) ( $report['status'] ?? 'blocked' ) ) ) ) . '</span></td>';
				echo '<td>' . esc_html( (string) ( $report['support_label'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $report['risk_label'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( implode( ' | ', array_map( 'strval', $messages ) ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}

	/**
	 * @param array<string, mixed> $inspect
	 * @param array<string, mixed> $probe
	 */
	private function render_schema_runtime_tab( array $inspect, array $probe ): void {
		echo '<div class="ogs-card">';
		echo '<h2>' . esc_html__( 'Runtime Inspector', 'open-growth-seo' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Inspect effective schema by post ID or URL to verify emitted nodes, blocked nodes, and conflict reasons.', 'open-growth-seo' ) . '</p>';
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="ogs-seo-schema" />';
		echo '<input type="hidden" name="schema_tab" value="runtime" />';
		echo '<p><label><strong>' . esc_html__( 'Inspect by post ID', 'open-growth-seo' ) . '</strong><br/><input type="number" min="0" class="small-text" name="schema_inspect_post_id" value="' . esc_attr( (string) ( $probe['post_id'] ?? 0 ) ) . '" /></label></p>';
		echo '<p><label><strong>' . esc_html__( 'Inspect by URL', 'open-growth-seo' ) . '</strong><br/><input type="url" class="regular-text" name="schema_inspect_url" value="' . esc_attr( (string) ( $probe['url'] ?? '' ) ) . '" placeholder="https://example.com/page/" /></label></p>';
		echo '<p><button class="button button-secondary" type="submit">' . esc_html__( 'Inspect runtime output', 'open-growth-seo' ) . '</button></p>';
		echo '</form>';
		echo '</div>';

		$summary = RuntimeInspector::summary( $inspect );
		echo '<div class="ogs-card">';
		echo '<h3>' . esc_html__( 'Inspector summary', 'open-growth-seo' ) . '</h3>';
		echo '<table class="widefat striped" style="max-width:820px"><tbody>';
		echo '<tr><th>' . esc_html__( 'URL', 'open-growth-seo' ) . '</th><td><code>' . esc_html( (string) ( $summary['url'] ?? '' ) ) . '</code></td></tr>';
		echo '<tr><th>' . esc_html__( 'Post ID', 'open-growth-seo' ) . '</th><td>' . esc_html( (string) ( $summary['post_id'] ?? 0 ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Post type', 'open-growth-seo' ) . '</th><td>' . esc_html( (string) ( $summary['post_type'] ?? '' ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Indexable', 'open-growth-seo' ) . '</th><td>' . esc_html( ! empty( $summary['is_indexable'] ) ? __( 'Yes', 'open-growth-seo' ) : __( 'No', 'open-growth-seo' ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Nodes emitted', 'open-growth-seo' ) . '</th><td>' . esc_html( (string) ( $summary['nodes_emitted'] ?? 0 ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Errors', 'open-growth-seo' ) . '</th><td>' . esc_html( (string) ( $summary['errors'] ?? 0 ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Warnings', 'open-growth-seo' ) . '</th><td>' . esc_html( (string) ( $summary['warnings'] ?? 0 ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Conflicts', 'open-growth-seo' ) . '</th><td>' . esc_html( (string) ( $summary['conflicts'] ?? 0 ) ) . '</td></tr>';
		echo '</tbody></table>';
		$nodes_by_type = isset( $summary['nodes_by_type'] ) && is_array( $summary['nodes_by_type'] ) ? $summary['nodes_by_type'] : array();
		if ( ! empty( $nodes_by_type ) ) {
			$nodes_by_type_text = implode(
				', ',
				array_map(
					static function ( $type, $count ): string {
						return (string) $type . ' (' . (int) $count . ')';
					},
					array_keys( $nodes_by_type ),
					array_values( $nodes_by_type )
				)
			);
			echo '<p class="description"><strong>' . esc_html__( 'Nodes by type:', 'open-growth-seo' ) . '</strong> ' . esc_html( $nodes_by_type_text ) . '</p>';
		}
		echo '</div>';

		$diff = isset( $inspect['diff'] ) && is_array( $inspect['diff'] ) ? $inspect['diff'] : array();
		echo '<div class="ogs-card">';
		echo '<h3>' . esc_html__( 'Inspection diff vs previous snapshot', 'open-growth-seo' ) . '</h3>';
		if ( empty( $diff['has_previous'] ) ) {
			echo '<p class="ogs-empty">' . esc_html( (string) ( $diff['message'] ?? __( 'Run another inspect request to compare changes over time.', 'open-growth-seo' ) ) ) . '</p>';
		} else {
			$node_type_deltas = isset( $diff['node_type_deltas'] ) && is_array( $diff['node_type_deltas'] ) ? $diff['node_type_deltas'] : array();
			$conflict_delta = isset( $diff['conflict_delta'] ) && is_array( $diff['conflict_delta'] ) ? $diff['conflict_delta'] : array();
			echo '<table class="widefat striped ogs-schema-table"><tbody>';
			echo '<tr><th>' . esc_html__( 'Payload changed', 'open-growth-seo' ) . '</th><td>' . esc_html( ! empty( $diff['payload_changed'] ) ? __( 'Yes', 'open-growth-seo' ) : __( 'No', 'open-growth-seo' ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Node count delta', 'open-growth-seo' ) . '</th><td>' . esc_html( (string) (int) ( $diff['nodes_delta'] ?? 0 ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Expected but not emitted', 'open-growth-seo' ) . '</th><td>' . esc_html( implode( ', ', array_map( 'strval', (array) ( $diff['expected_not_emitted'] ?? array() ) ) ) ?: __( 'None', 'open-growth-seo' ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Unexpected emitted nodes', 'open-growth-seo' ) . '</th><td>' . esc_html( implode( ', ', array_map( 'strval', (array) ( $diff['unexpected_emitted'] ?? array() ) ) ) ?: __( 'None', 'open-growth-seo' ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Conflict delta', 'open-growth-seo' ) . '</th><td>' . esc_html( sprintf( 'critical %1$d | important %2$d | minor %3$d', (int) ( $conflict_delta['critical'] ?? 0 ), (int) ( $conflict_delta['important'] ?? 0 ), (int) ( $conflict_delta['minor'] ?? 0 ) ) ) . '</td></tr>';
			echo '</tbody></table>';
			if ( ! empty( $node_type_deltas ) ) {
				echo '<p class="description"><strong>' . esc_html__( 'Node type deltas:', 'open-growth-seo' ) . '</strong> ' . esc_html(
					implode(
						' | ',
						array_map(
							static function ( $type, $delta ): string {
								return (string) $type . ' ' . ( $delta > 0 ? '+' : '' ) . (int) $delta;
							},
							array_keys( $node_type_deltas ),
							array_values( $node_type_deltas )
						)
					)
				) . '</p>';
			}
		}
		echo '</div>';

		$history = isset( $inspect['history'] ) && is_array( $inspect['history'] ) ? $inspect['history'] : array();
		echo '<div class="ogs-card">';
		echo '<h3>' . esc_html__( 'Inspector snapshot history', 'open-growth-seo' ) . '</h3>';
		if ( empty( $history ) ) {
			echo '<p class="ogs-empty">' . esc_html__( 'No snapshot history recorded yet for this inspect target.', 'open-growth-seo' ) . '</p>';
		} else {
			echo '<table class="widefat striped ogs-schema-table"><thead><tr><th>' . esc_html__( 'Captured', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Nodes emitted', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Expected vs emitted gaps', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Payload hash', 'open-growth-seo' ) . '</th></tr></thead><tbody>';
			foreach ( array_slice( $history, 0, 6 ) as $snapshot ) {
				if ( ! is_array( $snapshot ) ) {
					continue;
				}
				$captured_at = isset( $snapshot['captured_at'] ) ? (int) $snapshot['captured_at'] : 0;
				$missing = implode( ', ', array_map( 'strval', (array) ( $snapshot['expected_not_emitted'] ?? array() ) ) );
				$unexpected = implode( ', ', array_map( 'strval', (array) ( $snapshot['unexpected_emitted'] ?? array() ) ) );
				$gap_text = '';
				if ( '' !== $missing ) {
					$gap_text .= sprintf( __( 'Missing: %s', 'open-growth-seo' ), $missing );
				}
				if ( '' !== $unexpected ) {
					$gap_text .= ( '' !== $gap_text ? ' | ' : '' ) . sprintf( __( 'Unexpected: %s', 'open-growth-seo' ), $unexpected );
				}
				echo '<tr>';
				echo '<td>' . esc_html( $captured_at > 0 ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $captured_at ) : '-' ) . '</td>';
				echo '<td>' . esc_html( (string) (int) ( $snapshot['nodes_emitted'] ?? 0 ) ) . '</td>';
				echo '<td>' . esc_html( '' !== $gap_text ? $gap_text : __( 'No expected-vs-emitted gaps recorded', 'open-growth-seo' ) ) . '</td>';
				echo '<td><code>' . esc_html( substr( (string) ( $snapshot['payload_hash'] ?? '' ), 0, 12 ) ) . '</code></td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';

		echo '<div class="ogs-card">';
		echo '<h3>' . esc_html__( 'JSON-LD output preview', 'open-growth-seo' ) . '</h3>';
		$payload = isset( $inspect['payload'] ) ? $inspect['payload'] : array();
		echo '<pre class="ogs-rest-response">' . esc_html( wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) ?: '{}' ) . '</pre>';
		$route = 'ogs-seo/v1/schema/inspect';
		$query = array();
		if ( ! empty( $probe['post_id'] ) ) {
			$query['post_id'] = absint( $probe['post_id'] );
		}
		if ( ! empty( $probe['url'] ) ) {
			$query['url'] = (string) $probe['url'];
		}
		if ( ! empty( $query ) ) {
			$route .= '?' . http_build_query( $query );
		}
		$this->render_rest_action( __( 'REST Schema Inspect', 'open-growth-seo' ), $route );
		echo '</div>';

		$node_reports = isset( $inspect['node_reports'] ) && is_array( $inspect['node_reports'] ) ? $inspect['node_reports'] : array();
		echo '<div class="ogs-card">';
		echo '<h3>' . esc_html__( 'Runtime node diagnostics', 'open-growth-seo' ) . '</h3>';
		if ( empty( $node_reports ) ) {
			echo '<p class="ogs-empty">' . esc_html__( 'No node diagnostics available for this inspect request.', 'open-growth-seo' ) . '</p>';
		} else {
			echo '<table class="widefat striped ogs-schema-table"><thead><tr><th>' . esc_html__( 'Node type', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Status', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Why', 'open-growth-seo' ) . '</th></tr></thead><tbody>';
			foreach ( $node_reports as $report ) {
				if ( ! is_array( $report ) ) {
					continue;
				}
				$type = (string) ( $report['type'] ?? '' );
				$status = (string) ( $report['status'] ?? 'blocked' );
				$messages = isset( $report['messages'] ) && is_array( $report['messages'] ) ? $report['messages'] : array();
				echo '<tr><td>' . esc_html( $type ) . '</td><td><span class="ogs-schema-chip ogs-schema-chip-' . esc_attr( sanitize_html_class( $status ) ) . '">' . esc_html( ucfirst( str_replace( '_', ' ', $status ) ) ) . '</span></td><td>' . esc_html( implode( ' | ', array_map( 'strval', $messages ) ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}

	private function render_page_intro( string $page, string $title, string $description, bool $simple, bool $show_advanced, array $settings ): void {
		$installation = ( new InstallationManager() )->summary();
		$snapshot     = $this->product_state_snapshot( $settings );
		$chips        = array(
			array(
				'label' => $simple ? __( 'Simple mode', 'open-growth-seo' ) : __( 'Advanced mode', 'open-growth-seo' ),
				'class' => $simple ? 'ogs-chip-neutral' : 'ogs-chip-good',
			),
		);

		if ( ! empty( $installation['wizard_recommended'] ) ) {
			$chips[] = array(
				'label' => __( 'Setup review recommended', 'open-growth-seo' ),
				'class' => 'ogs-chip-warn',
			);
		} else {
			$chips[] = array(
				'label' => __( 'Setup baseline ready', 'open-growth-seo' ),
				'class' => 'ogs-chip-good',
			);
		}

		if ( ! empty( $this->admin_pages()[ $page ]['advanced'] ) ) {
			$chips[] = array(
				'label' => __( 'Advanced workspace', 'open-growth-seo' ),
				'class' => 'ogs-chip-neutral',
			);
		}

		$chips[] = array(
			'label' => $this->workflow_status_label( (string) ( $snapshot['audit']['status'] ?? 'warn' ), __( 'Audit', 'open-growth-seo' ) ),
			'class' => $this->workflow_status_chip_class( (string) ( $snapshot['audit']['status'] ?? 'warn' ) ),
		);

		if ( ! empty( $snapshot['jobs']['runner']['stale_lock'] ) || ! empty( $snapshot['jobs']['runner']['consecutive_failures'] ) ) {
			$chips[] = array(
				'label' => __( 'Jobs need attention', 'open-growth-seo' ),
				'class' => 'ogs-chip-warn',
			);
		} else {
			$chips[] = array(
				'label' => __( 'Jobs healthy', 'open-growth-seo' ),
				'class' => 'ogs-chip-good',
			);
		}

		if ( ! empty( $snapshot['integrations']['needs_attention'] ) ) {
			$chips[] = array(
				'label' => __( 'Integrations need attention', 'open-growth-seo' ),
				'class' => 'ogs-chip-warn',
			);
		}

		$actions = array();
		if ( 'ogs-seo-dashboard' !== $page ) {
			$actions[] = array(
				'label'   => __( 'Open Dashboard', 'open-growth-seo' ),
				'url'     => admin_url( 'admin.php?page=ogs-seo-dashboard' ),
				'primary' => false,
			);
		}

		if ( ! empty( $installation['wizard_recommended'] ) && 'ogs-seo-setup' !== $page ) {
			$actions[] = array(
				'label'   => __( 'Review setup', 'open-growth-seo' ),
				'url'     => admin_url( 'admin.php?page=ogs-seo-setup' ),
				'primary' => true,
			);
		}

		if ( $simple && ! $show_advanced ) {
			$actions[] = array(
				'label'   => __( 'Show advanced sections', 'open-growth-seo' ),
				'url'     => add_query_arg( 'ogs_show_advanced', '1', 'admin.php?page=' . rawurlencode( $page ) ),
				'primary' => false,
			);
		}

		foreach ( $this->page_documentation_actions( $page ) as $doc_action ) {
			$actions[] = $doc_action;
		}

		echo '<header class="ogs-page-header">';
		echo '<div class="ogs-page-header__content">';
		echo '<p class="ogs-page-header__eyebrow">' . esc_html__( 'Open Growth SEO Admin', 'open-growth-seo' ) . '</p>';
		echo '<h1>' . esc_html( $title ) . '</h1>';
		if ( '' !== trim( $description ) ) {
			echo '<p class="ogs-page-header__description">' . esc_html( $description ) . '</p>';
		}
		if ( ! empty( $chips ) ) {
			echo '<div class="ogs-page-header__chips" aria-label="' . esc_attr__( 'Current page context', 'open-growth-seo' ) . '">';
			foreach ( $chips as $chip ) {
				echo '<span class="ogs-chip ' . esc_attr( (string) ( $chip['class'] ?? 'ogs-chip-neutral' ) ) . '">' . esc_html( (string) ( $chip['label'] ?? '' ) ) . '</span>';
			}
			echo '</div>';
		}
		echo '</div>';
		if ( ! empty( $actions ) ) {
			echo '<div class="ogs-page-header__actions">';
			foreach ( $actions as $action ) {
				echo '<a class="button ' . ( ! empty( $action['primary'] ) ? 'button-primary' : 'button-secondary' ) . '" href="' . esc_url( (string) $action['url'] ) . '">' . esc_html( (string) $action['label'] ) . '</a>';
			}
			echo '</div>';
		}
		echo '</header>';
	}

	private function render_workflow_continuity( string $page, array $settings ): void {
		$snapshot = $this->product_state_snapshot( $settings );
		$stages   = $this->workflow_stages( $settings, $snapshot );
		$actions  = $this->page_related_actions( $page, $snapshot );

		if ( empty( $stages ) && empty( $actions ) ) {
			return;
		}

		echo '<section class="ogs-section ogs-workflow-panel" aria-labelledby="ogs-workflow-title">';
		echo '<div class="ogs-grid ogs-grid-2">';
		echo '<div class="ogs-card">';
		echo '<h2 id="ogs-workflow-title">' . esc_html__( 'Workflow continuity', 'open-growth-seo' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'This plugin works best as a sequence: baseline setup, search output controls, analysis, optimization, then operational support. The statuses below keep those handoffs visible.', 'open-growth-seo' ) . '</p>';
		echo '<div class="ogs-workflow-stage-list">';
		foreach ( $stages as $stage ) {
			$is_current = ! empty( $stage['current'] );
			echo '<article class="ogs-workflow-stage' . ( $is_current ? ' is-current' : '' ) . '">';
			echo '<div class="ogs-workflow-stage__head">';
			echo '<h3>' . esc_html( (string) $stage['label'] ) . '</h3>';
			echo '<span class="ogs-chip ' . esc_attr( $this->workflow_status_chip_class( (string) $stage['status'] ) ) . '">' . esc_html( $this->workflow_status_label( (string) $stage['status'] ) ) . '</span>';
			echo '</div>';
			echo '<p class="description">' . esc_html( (string) $stage['summary'] ) . '</p>';
			if ( ! empty( $stage['url'] ) && ! empty( $stage['action'] ) ) {
				echo '<p><a class="button button-secondary" href="' . esc_url( (string) $stage['url'] ) . '">' . esc_html( (string) $stage['action'] ) . '</a></p>';
			}
			echo '</article>';
		}
		echo '</div>';
		echo '</div>';

		echo '<div class="ogs-card">';
		echo '<h2>' . esc_html__( 'Connected next steps', 'open-growth-seo' ) . '</h2>';
		if ( empty( $actions ) ) {
			echo '<p class="ogs-empty">' . esc_html__( 'No immediate handoff is needed from this workspace right now.', 'open-growth-seo' ) . '</p>';
		} else {
			echo '<ul class="ogs-support-next-steps">';
			foreach ( $actions as $action ) {
				$status = isset( $action['status'] ) ? (string) $action['status'] : 'good';
				echo '<li><strong>' . esc_html( (string) ( $action['label'] ?? '' ) ) . '</strong> <span class="ogs-chip ' . esc_attr( $this->workflow_status_chip_class( $status ) ) . '">' . esc_html( $this->workflow_status_label( $status ) ) . '</span><br/>' . esc_html( (string) ( $action['summary'] ?? '' ) );
				if ( ! empty( $action['url'] ) && ! empty( $action['action'] ) ) {
					echo '<br/><a href="' . esc_url( (string) $action['url'] ) . '">' . esc_html( (string) $action['action'] ) . '</a>';
				}
				echo '</li>';
			}
			echo '</ul>';
		}
		echo '</div>';
		echo '</div>';
		echo '</section>';
	}

	private function product_state_snapshot( array $settings ): array {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}

		$installation = ( new InstallationManager() )->summary();
		$integrations = IntegrationsManager::get_status_payload();
		$diagnostics  = DeveloperTools::diagnostics_cached();
		$audit_last   = (int) get_option( 'ogs_seo_audit_last_run', 0 );
		$issues       = ( new AuditManager() )->get_latest_issues();
		$issues       = array_values( array_filter( (array) $issues, 'is_array' ) );

		$issue_count = array(
			'critical'  => $this->count_issues_by_severity( $issues, 'critical' ),
			'important' => $this->count_issues_by_severity( $issues, 'important' ),
			'minor'     => $this->count_issues_by_severity( $issues, 'minor' ),
		);

		$audit_status = 'warn';
		if ( $issue_count['critical'] > 0 ) {
			$audit_status = 'critical';
		} elseif ( 0 === $audit_last ) {
			$audit_status = 'warn';
		} elseif ( ( time() - $audit_last ) > ( 7 * DAY_IN_SECONDS ) ) {
			$audit_status = 'warn';
		} elseif ( $issue_count['important'] > 0 ) {
			$audit_status = 'warn';
		} else {
			$audit_status = 'good';
		}

		$integration_summary = isset( $integrations['summary'] ) && is_array( $integrations['summary'] ) ? $integrations['summary'] : array();
		$jobs                = isset( $diagnostics['jobs'] ) && is_array( $diagnostics['jobs'] ) ? $diagnostics['jobs'] : array();
		$support             = isset( $diagnostics['support'] ) && is_array( $diagnostics['support'] ) ? $diagnostics['support'] : array();

		$cache = array(
			'installation' => is_array( $installation ) ? $installation : array(),
			'integrations' => array(
				'summary'         => $integration_summary,
				'needs_attention' => (int) ( $integrations['attention'] ?? 0 ) > 0 || (int) ( $integrations['errors'] ?? 0 ) > 0,
			),
			'jobs'         => $jobs,
			'support'      => $support,
			'audit'        => array(
				'status'     => $audit_status,
				'last_run'   => $audit_last,
				'issue_count' => $issue_count,
			),
			'settings'     => $settings,
		);

		return $cache;
	}

	private function workflow_stages( array $settings, array $snapshot ): array {
		$installation = isset( $snapshot['installation'] ) && is_array( $snapshot['installation'] ) ? $snapshot['installation'] : array();
		$audit        = isset( $snapshot['audit'] ) && is_array( $snapshot['audit'] ) ? $snapshot['audit'] : array();
		$jobs         = isset( $snapshot['jobs'] ) && is_array( $snapshot['jobs'] ) ? $snapshot['jobs'] : array();
		$integrations = isset( $snapshot['integrations'] ) && is_array( $snapshot['integrations'] ) ? $snapshot['integrations'] : array();
		$support      = isset( $snapshot['support'] ) && is_array( $snapshot['support'] ) ? $snapshot['support'] : array();

		$search_status = ! empty( $settings['canonical_enabled'] ) && ! empty( $settings['og_enabled'] ) && ! empty( $settings['twitter_enabled'] ) ? 'good' : 'warn';
		$ops_status    = ! empty( $jobs['runner']['stale_lock'] ) ? 'critical' : ( ! empty( $integrations['needs_attention'] ) || (int) ( $support['attention_count'] ?? 0 ) > 0 ? 'warn' : 'good' );
		$current_stage = $this->page_stage_map();

		return array(
			array(
				'key'     => 'setup',
				'label'   => __( 'Setup baseline', 'open-growth-seo' ),
				'status'  => ! empty( $installation['wizard_recommended'] ) || 'ready' !== (string) ( $installation['status'] ?? 'ready' ) ? 'warn' : 'good',
				'summary' => ! empty( $installation['wizard_recommended'] ) ? __( 'Setup is still guiding safe defaults and identity choices.', 'open-growth-seo' ) : __( 'Installation profile and first-run defaults look stable.', 'open-growth-seo' ),
				'url'     => admin_url( 'admin.php?page=ogs-seo-setup' ),
				'action'  => ! empty( $installation['wizard_recommended'] ) ? __( 'Finish setup review', 'open-growth-seo' ) : __( 'Review setup baseline', 'open-growth-seo' ),
				'current' => isset( $current_stage[ $this->current_admin_page() ] ) && 'setup' === $current_stage[ $this->current_admin_page() ],
			),
			array(
				'key'     => 'outputs',
				'label'   => __( 'Search outputs', 'open-growth-seo' ),
				'status'  => $search_status,
				'summary' => 'good' === $search_status ? __( 'Search appearance, canonicals, and social previews are configured.', 'open-growth-seo' ) : __( 'Search output defaults still need review for canonicals or social preview coverage.', 'open-growth-seo' ),
				'url'     => admin_url( 'admin.php?page=ogs-seo-search-appearance' ),
				'action'  => __( 'Review search output controls', 'open-growth-seo' ),
				'current' => isset( $current_stage[ $this->current_admin_page() ] ) && 'outputs' === $current_stage[ $this->current_admin_page() ],
			),
			array(
				'key'     => 'analysis',
				'label'   => __( 'Analysis and audits', 'open-growth-seo' ),
				'status'  => (string) ( $audit['status'] ?? 'warn' ),
				'summary' => 'good' === (string) ( $audit['status'] ?? 'warn' ) ? __( 'Audits are current and no high-severity blockers are visible.', 'open-growth-seo' ) : __( 'Run or review audits before trusting optimization signals across the plugin.', 'open-growth-seo' ),
				'url'     => admin_url( 'admin.php?page=ogs-seo-audits' ),
				'action'  => __( 'Open audits workspace', 'open-growth-seo' ),
				'current' => isset( $current_stage[ $this->current_admin_page() ] ) && 'analysis' === $current_stage[ $this->current_admin_page() ],
			),
			array(
				'key'     => 'optimization',
				'label'   => __( 'Content optimization', 'open-growth-seo' ),
				'status'  => ! empty( $settings['aeo_enabled'] ) ? 'good' : 'warn',
				'summary' => ! empty( $settings['aeo_enabled'] ) ? __( 'AEO, GEO, and SFO workspaces are available for content and search feature refinement.', 'open-growth-seo' ) : __( 'Content intelligence is available, but answer-engine guidance is currently disabled.', 'open-growth-seo' ),
				'url'     => admin_url( 'admin.php?page=ogs-seo-sfo' ),
				'action'  => __( 'Open optimization workspace', 'open-growth-seo' ),
				'current' => isset( $current_stage[ $this->current_admin_page() ] ) && 'optimization' === $current_stage[ $this->current_admin_page() ],
			),
			array(
				'key'     => 'operations',
				'label'   => __( 'Operations and support', 'open-growth-seo' ),
				'status'  => $ops_status,
				'summary' => 'good' === $ops_status ? __( 'Jobs, integrations, and support diagnostics look stable.', 'open-growth-seo' ) : __( 'Operational state needs review in jobs, integrations, or support diagnostics.', 'open-growth-seo' ),
				'url'     => admin_url( 'admin.php?page=ogs-seo-tools&ogs_show_advanced=1' ),
				'action'  => __( 'Open support and operations', 'open-growth-seo' ),
				'current' => isset( $current_stage[ $this->current_admin_page() ] ) && 'operations' === $current_stage[ $this->current_admin_page() ],
			),
		);
	}

	private function page_related_actions( string $page, array $snapshot ): array {
		$installation = isset( $snapshot['installation'] ) && is_array( $snapshot['installation'] ) ? $snapshot['installation'] : array();
		$audit        = isset( $snapshot['audit'] ) && is_array( $snapshot['audit'] ) ? $snapshot['audit'] : array();
		$jobs         = isset( $snapshot['jobs'] ) && is_array( $snapshot['jobs'] ) ? $snapshot['jobs'] : array();
		$integrations = isset( $snapshot['integrations'] ) && is_array( $snapshot['integrations'] ) ? $snapshot['integrations'] : array();
		$support      = isset( $snapshot['support'] ) && is_array( $snapshot['support'] ) ? $snapshot['support'] : array();

		$map = array(
			'ogs-seo-dashboard' => array(
				array(
					'label'   => __( 'Complete setup baseline', 'open-growth-seo' ),
					'status'  => ! empty( $installation['wizard_recommended'] ) ? 'warn' : 'good',
					'summary' => ! empty( $installation['wizard_recommended'] ) ? __( 'The guided setup still needs confirmation before deeper optimization work.', 'open-growth-seo' ) : __( 'Setup baseline is already confirmed.', 'open-growth-seo' ),
					'url'     => admin_url( 'admin.php?page=ogs-seo-setup' ),
					'action'  => __( 'Open setup wizard', 'open-growth-seo' ),
				),
				array(
					'label'   => __( 'Turn findings into actions', 'open-growth-seo' ),
					'status'  => (string) ( $audit['status'] ?? 'warn' ),
					'summary' => 'good' === (string) ( $audit['status'] ?? 'warn' ) ? __( 'Latest audits are current; use them to guide the next content or technical changes.', 'open-growth-seo' ) : __( 'Audit findings should be reviewed before trusting downstream optimization guidance.', 'open-growth-seo' ),
					'url'     => admin_url( 'admin.php?page=ogs-seo-audits' ),
					'action'  => __( 'Open audits', 'open-growth-seo' ),
				),
				array(
					'label'   => __( 'Check operations', 'open-growth-seo' ),
					'status'  => ! empty( $jobs['runner']['stale_lock'] ) || ! empty( $integrations['needs_attention'] ) ? 'warn' : 'good',
					'summary' => ! empty( $jobs['runner']['stale_lock'] ) ? __( 'Background jobs need intervention before relying on external publishing signals.', 'open-growth-seo' ) : __( 'Integrations, jobs, and diagnostics are the final validation layer.', 'open-growth-seo' ),
					'url'     => admin_url( 'admin.php?page=ogs-seo-tools&ogs_show_advanced=1' ),
					'action'  => __( 'Open tools and support', 'open-growth-seo' ),
				),
			),
			'ogs-seo-search-appearance' => array(
				array(
					'label'   => __( 'Validate output quality', 'open-growth-seo' ),
					'status'  => (string) ( $audit['status'] ?? 'warn' ),
					'summary' => __( 'Snippet and canonical defaults should be validated by the audit layer, not trusted in isolation.', 'open-growth-seo' ),
					'url'     => admin_url( 'admin.php?page=ogs-seo-audits' ),
					'action'  => __( 'Review audit findings', 'open-growth-seo' ),
				),
				array(
					'label'   => __( 'Connect schema and previews', 'open-growth-seo' ),
					'status'  => 'good',
					'summary' => __( 'Schema runtime and search previews should align so visible content, canonicals, and structured data tell the same story.', 'open-growth-seo' ),
					'url'     => admin_url( 'admin.php?page=ogs-seo-schema&ogs_show_advanced=1' ),
					'action'  => __( 'Open schema workspace', 'open-growth-seo' ),
				),
			),
			'ogs-seo-content' => array(
				array(
					'label'   => __( 'Escalate to SFO', 'open-growth-seo' ),
					'status'  => 'good',
					'summary' => __( 'Content controls become more useful when they are connected to search feature readiness, not reviewed alone.', 'open-growth-seo' ),
					'url'     => admin_url( 'admin.php?page=ogs-seo-sfo' ),
					'action'  => __( 'Open SFO workspace', 'open-growth-seo' ),
				),
				array(
					'label'   => __( 'Validate technical blockers', 'open-growth-seo' ),
					'status'  => (string) ( $audit['status'] ?? 'warn' ),
					'summary' => __( 'AEO guidance depends on indexability, canonicals, and other technical constraints being healthy.', 'open-growth-seo' ),
					'url'     => admin_url( 'admin.php?page=ogs-seo-audits' ),
					'action'  => __( 'Open audits', 'open-growth-seo' ),
				),
			),
			'ogs-seo-sfo' => array(
				array(
					'label'   => __( 'Refine answer and bot readiness', 'open-growth-seo' ),
					'status'  => 'good',
					'summary' => __( 'SFO findings usually need follow-through in AEO/GEO-oriented content and crawl controls.', 'open-growth-seo' ),
					'url'     => admin_url( 'admin.php?page=ogs-seo-bots&ogs_show_advanced=1' ),
					'action'  => __( 'Open bots and GEO controls', 'open-growth-seo' ),
				),
				array(
					'label'   => __( 'Resolve schema blockers', 'open-growth-seo' ),
					'status'  => 'good',
					'summary' => __( 'Rich-result and feature readiness improve only when runtime schema remains aligned with visible content.', 'open-growth-seo' ),
					'url'     => admin_url( 'admin.php?page=ogs-seo-schema&ogs_show_advanced=1' ),
					'action'  => __( 'Inspect schema runtime', 'open-growth-seo' ),
				),
			),
			'ogs-seo-integrations' => array(
				array(
					'label'   => __( 'Check jobs and publishing', 'open-growth-seo' ),
					'status'  => ! empty( $jobs['runner']['stale_lock'] ) ? 'critical' : 'good',
					'summary' => ! empty( $jobs['runner']['stale_lock'] ) ? __( 'An unhealthy job runner can make integrations look connected while delayed actions are still blocked.', 'open-growth-seo' ) : __( 'Connected services should be paired with healthy queue execution and support diagnostics.', 'open-growth-seo' ),
					'url'     => admin_url( 'admin.php?page=ogs-seo-tools&ogs_show_advanced=1' ),
					'action'  => __( 'Open operational diagnostics', 'open-growth-seo' ),
				),
				array(
					'label'   => __( 'Use integrations in context', 'open-growth-seo' ),
					'status'  => ( ! empty( $integrations['needs_attention'] ) ? 'warn' : 'good' ),
					'summary' => __( 'Integration health matters most when it supports audits, indexing, and search-feature workflows elsewhere in the plugin.', 'open-growth-seo' ),
					'url'     => admin_url( 'admin.php?page=ogs-seo-dashboard' ),
					'action'  => __( 'Return to dashboard', 'open-growth-seo' ),
				),
			),
			'ogs-seo-tools' => array(
				array(
					'label'   => __( 'Support reflects the whole plugin', 'open-growth-seo' ),
					'status'  => (int) ( $support['attention_count'] ?? 0 ) > 0 ? 'warn' : 'good',
					'summary' => __( 'Use diagnostics to confirm that installation, integrations, jobs, and audits all agree with what the UI reports elsewhere.', 'open-growth-seo' ),
					'url'     => admin_url( 'admin.php?page=ogs-seo-dashboard' ),
					'action'  => __( 'Compare with dashboard state', 'open-growth-seo' ),
				),
			),
		);

		return isset( $map[ $page ] ) && is_array( $map[ $page ] ) ? $map[ $page ] : array();
	}

	private function current_admin_page(): string {
		return isset( $_GET['page'] ) ? sanitize_key( (string) wp_unslash( $_GET['page'] ) ) : 'ogs-seo-dashboard';
	}

	private function page_stage_map(): array {
		return array(
			'ogs-seo-dashboard'         => 'analysis',
			'ogs-seo-setup'             => 'setup',
			'ogs-seo-search-appearance' => 'outputs',
			'ogs-seo-content'           => 'optimization',
			'ogs-seo-sfo'               => 'optimization',
			'ogs-seo-schema'            => 'outputs',
			'ogs-seo-sitemaps'          => 'outputs',
			'ogs-seo-redirects'         => 'outputs',
			'ogs-seo-bots'              => 'optimization',
			'ogs-seo-integrations'      => 'operations',
			'ogs-seo-audits'            => 'analysis',
			'ogs-seo-tools'             => 'operations',
			'ogs-seo-settings'          => 'outputs',
			'ogs-seo-masters-plus'      => 'operations',
		);
	}

	private function workflow_status_label( string $status, string $context = '' ): string {
		switch ( sanitize_key( $status ) ) {
			case 'critical':
				return '' !== $context ? sprintf( __( '%s critical', 'open-growth-seo' ), $context ) : __( 'Critical', 'open-growth-seo' );
			case 'warn':
				return '' !== $context ? sprintf( __( '%s needs review', 'open-growth-seo' ), $context ) : __( 'Needs review', 'open-growth-seo' );
			default:
				return '' !== $context ? sprintf( __( '%s healthy', 'open-growth-seo' ), $context ) : __( 'Healthy', 'open-growth-seo' );
		}
	}

	private function workflow_status_chip_class( string $status ): string {
		switch ( sanitize_key( $status ) ) {
			case 'critical':
				return 'ogs-chip-critical';
			case 'warn':
				return 'ogs-chip-warn';
			default:
				return 'ogs-chip-good';
		}
	}

	/**
	 * @return array<int, array<string, string|bool>>
	 */
	private function page_documentation_actions( string $page ): array {
		$docs = $this->documentation_map();
		if ( empty( $docs[ $page ] ) || ! is_array( $docs[ $page ] ) ) {
			return array();
		}

		$action = $docs[ $page ];
		$url    = isset( $action['file'] ) ? $this->documentation_url( (string) $action['file'] ) : '';
		if ( '' === $url ) {
			return array();
		}

		return array(
			array(
				'label'   => isset( $action['label'] ) ? (string) $action['label'] : __( 'Open help', 'open-growth-seo' ),
				'url'     => $url,
				'primary' => false,
			),
		);
	}

	/**
	 * @return array<string, array<string, string>>
	 */
	private function documentation_map(): array {
		return array(
			'ogs-seo-dashboard'         => array(
				'label' => __( 'Open user guide', 'open-growth-seo' ),
				'file'  => 'USER_GUIDE.md',
			),
			'ogs-seo-setup'             => array(
				'label' => __( 'Setup help', 'open-growth-seo' ),
				'file'  => 'USER_GUIDE.md',
			),
			'ogs-seo-search-appearance' => array(
				'label' => __( 'Search appearance help', 'open-growth-seo' ),
				'file'  => 'USER_GUIDE.md',
			),
			'ogs-seo-content'           => array(
				'label' => __( 'Content controls help', 'open-growth-seo' ),
				'file'  => 'USER_GUIDE.md',
			),
			'ogs-seo-schema'            => array(
				'label' => __( 'Schema help', 'open-growth-seo' ),
				'file'  => 'USER_GUIDE.md',
			),
			'ogs-seo-sitemaps'          => array(
				'label' => __( 'Sitemaps help', 'open-growth-seo' ),
				'file'  => 'USER_GUIDE.md',
			),
			'ogs-seo-redirects'         => array(
				'label' => __( 'Redirects help', 'open-growth-seo' ),
				'file'  => 'USER_GUIDE.md',
			),
			'ogs-seo-bots'              => array(
				'label' => __( 'Bots and GEO help', 'open-growth-seo' ),
				'file'  => 'USER_GUIDE.md',
			),
			'ogs-seo-integrations'      => array(
				'label' => __( 'Integrations help', 'open-growth-seo' ),
				'file'  => 'USER_GUIDE.md',
			),
			'ogs-seo-audits'            => array(
				'label' => __( 'Audit help', 'open-growth-seo' ),
				'file'  => 'USER_GUIDE.md',
			),
			'ogs-seo-sfo'               => array(
				'label' => __( 'SFO help', 'open-growth-seo' ),
				'file'  => 'USER_GUIDE.md',
			),
			'ogs-seo-tools'             => array(
				'label' => __( 'Testing and support docs', 'open-growth-seo' ),
				'file'  => 'TESTING.md',
			),
			'ogs-seo-settings'          => array(
				'label' => __( 'Architecture notes', 'open-growth-seo' ),
				'file'  => 'ARCHITECTURE.md',
			),
			'ogs-seo-masters-plus'      => array(
				'label' => __( 'Security and architecture notes', 'open-growth-seo' ),
				'file'  => 'SECURITY.md',
			),
		);
	}

	private function documentation_url( string $file ): string {
		$file = trim( wp_normalize_path( $file ) );
		if ( '' === $file || false !== strpos( $file, '..' ) ) {
			return '';
		}

		$absolute = trailingslashit( OGS_SEO_PATH ) . 'docs/' . ltrim( $file, '/' );
		if ( ! file_exists( $absolute ) ) {
			return '';
		}

		return trailingslashit( OGS_SEO_URL ) . 'docs/' . rawurlencode( basename( $file ) );
	}

	private function render_admin_section_nav( string $current_page, bool $simple = false, bool $show_advanced = false ): void {
		$pages = $this->admin_pages();
		if ( empty( $pages ) ) {
			return;
		}
		$groups = array(
			__( 'Workspaces', 'open-growth-seo' ) => array(
				'ogs-seo-dashboard',
				'ogs-seo-setup',
				'ogs-seo-search-appearance',
				'ogs-seo-content',
				'ogs-seo-sfo',
				'ogs-seo-audits',
			),
			__( 'Operations', 'open-growth-seo' ) => array(
				'ogs-seo-schema',
				'ogs-seo-sitemaps',
				'ogs-seo-redirects',
				'ogs-seo-bots',
				'ogs-seo-integrations',
			),
			__( 'System', 'open-growth-seo' ) => array(
				'ogs-seo-settings',
				'ogs-seo-tools',
				'ogs-seo-masters-plus',
			),
		);

		echo '<nav class="ogs-admin-nav" aria-label="' . esc_attr( __( 'Open Growth SEO sections', 'open-growth-seo' ) ) . '">';
		foreach ( $groups as $group_label => $slugs ) {
			$items = array();
			foreach ( $slugs as $slug ) {
				if ( empty( $pages[ $slug ] ) || ! is_array( $pages[ $slug ] ) ) {
					continue;
				}
				if ( $simple && ! $show_advanced && ! empty( $pages[ $slug ]['advanced'] ) ) {
					continue;
				}
				$items[ $slug ] = $pages[ $slug ];
			}
			if ( empty( $items ) ) {
				continue;
			}
			echo '<div class="ogs-admin-nav__group">';
			echo '<p class="ogs-admin-nav__label">' . esc_html( $group_label ) . '</p>';
			echo '<div class="ogs-admin-nav__links">';
			foreach ( $items as $slug => $meta ) {
				$title = isset( $meta['title'] ) ? (string) $meta['title'] : $slug;
				$url   = 'admin.php?page=' . rawurlencode( $slug );
				$class = 'ogs-admin-nav__link';
				if ( $current_page === $slug ) {
					$class .= ' is-active';
				}
				echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '" ' . ( $current_page === $slug ? 'aria-current="page"' : '' ) . '>';
				echo '<span>' . esc_html( $title ) . '</span>';
				if ( ! empty( $meta['advanced'] ) ) {
					echo '<span class="ogs-chip ogs-chip-neutral">' . esc_html__( 'Advanced', 'open-growth-seo' ) . '</span>';
				}
				echo '</a>';
			}
			echo '</div>';
			echo '</div>';
		}
		echo '</nav>';
	}

	private function rehydrate_settings_errors(): void {
		$stored = get_transient( 'settings_errors' );
		if ( ! is_array( $stored ) || empty( $stored ) ) {
			return;
		}
		foreach ( $stored as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$code    = isset( $item['code'] ) ? sanitize_key( (string) $item['code'] ) : 'ogs_seo_notice';
			$message = isset( $item['message'] ) ? sanitize_text_field( (string) $item['message'] ) : '';
			$type    = isset( $item['type'] ) ? sanitize_key( (string) $item['type'] ) : 'error';
			if ( '' === $message ) {
				continue;
			}
			add_settings_error( 'ogs_seo', $code, $message, $type );
		}
		delete_transient( 'settings_errors' );
	}

	private function guard_redirect_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage redirects.', 'open-growth-seo' ) );
		}
		check_admin_referer( 'ogs_seo_redirect_action' );
	}

	private function guard_dev_tools_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to use developer tools.', 'open-growth-seo' ) );
		}
		check_admin_referer( 'ogs_seo_dev_tools_action' );
	}

	private function render_local_locations_table( array $settings ): void {
		$records = LocalSeoLocations::records( $settings );
		if ( empty( $records ) ) {
			$records = array(
				array(
					'id'              => '',
					'status'          => 'draft',
					'name'            => '',
					'business_type'   => 'LocalBusiness',
					'landing_page_id' => 0,
					'phone'           => '',
					'street'          => '',
					'city'            => '',
					'region'          => '',
					'postal_code'     => '',
					'country'         => '',
					'latitude'        => '',
					'longitude'       => '',
					'opening_hours'   => '',
					'department_name' => '',
					'service_mode'    => 'storefront',
					'service_areas'   => '',
					'seo_title'       => '',
					'seo_description' => '',
					'seo_canonical'   => '',
				),
			);
		}
		$page_options = $this->location_page_options();
		$row_index    = 0;

		echo '<div class="ogs-location-editor">';
		echo '<table class="widefat striped ogs-location-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Status', 'open-growth-seo' ) . '</th>';
		echo '<th>' . esc_html__( 'Business name', 'open-growth-seo' ) . '</th>';
		echo '<th>' . esc_html__( 'Landing page', 'open-growth-seo' ) . '</th>';
		echo '<th>' . esc_html__( 'Phone', 'open-growth-seo' ) . '</th>';
		echo '<th>' . esc_html__( 'Address', 'open-growth-seo' ) . '</th>';
		echo '<th>' . esc_html__( 'Geo', 'open-growth-seo' ) . '</th>';
		echo '<th>' . esc_html__( 'Opening hours', 'open-growth-seo' ) . '</th>';
		echo '<th>' . esc_html__( 'Service model', 'open-growth-seo' ) . '</th>';
		echo '<th>' . esc_html__( 'SEO overrides', 'open-growth-seo' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'open-growth-seo' ) . '</th>';
		echo '</tr></thead><tbody data-role="ogs-location-rows">';
		foreach ( $records as $record ) {
			$this->render_local_location_row( $record, $row_index, $page_options );
			++$row_index;
		}
		echo '</tbody></table>';
		echo '<p><button type="button" class="button button-secondary" data-role="ogs-location-add">' . esc_html__( 'Add location', 'open-growth-seo' ) . '</button></p>';
		echo '<p class="description">' . esc_html__( 'Required for published locations: business name, landing page, phone, street, city, and country. Keep one location per landing page and unique NAP per real place.', 'open-growth-seo' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Organization schema remains site-level. LocalBusiness schema is emitted only on the matching location landing page when status is published and required local fields are complete.', 'open-growth-seo' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Opening hours format: one line per range, e.g. "Mo-Fr 09:00-17:00". For service-area businesses use service mode and service areas instead of storefront-only assumptions.', 'open-growth-seo' ) . '</p>';
		echo '</div>';
		echo '<script>(function(){const root=document.querySelector(".ogs-location-editor");if(!root){return;}const tbody=root.querySelector("[data-role=ogs-location-rows]");const addBtn=root.querySelector("[data-role=ogs-location-add]");if(!tbody||!addBtn){return;}const renumber=()=>{tbody.querySelectorAll("tr.ogs-location-row").forEach((row,index)=>{row.querySelectorAll("[name]").forEach((input)=>{const name=input.getAttribute("name");if(!name){return;}input.setAttribute("name",name.replace(/ogs\\[local_locations\\]\\[\\d+\\]/,`ogs[local_locations][${index}]`));});});};const bindRemove=(row)=>{const btn=row.querySelector("[data-role=ogs-location-remove]");if(!btn){return;}btn.addEventListener("click",()=>{row.remove();if(!tbody.querySelector("tr.ogs-location-row")){addBtn.click();}renumber();});};tbody.querySelectorAll("tr.ogs-location-row").forEach((row)=>bindRemove(row));addBtn.addEventListener("click",()=>{const source=tbody.querySelector("tr.ogs-location-row:last-child");if(!source){return;}const clone=source.cloneNode(true);clone.querySelectorAll("input,textarea,select").forEach((field)=>{if(field.tagName==="SELECT"){const name=field.getAttribute("name")||\"\";if(name.includes(\"[status]\")){field.value=\"draft\";}else if(name.includes(\"[service_mode]\")){field.value=\"storefront\";}else{field.selectedIndex=0;}}else if(field.tagName===\"TEXTAREA\"){field.value=\"\";}else{const name=field.getAttribute(\"name\")||\"\";if(name.includes(\"[landing_page_id]\")){field.value=\"0\";}else{field.value=\"\";}}});tbody.appendChild(clone);bindRemove(clone);renumber();});})();</script>';
	}

	/**
	 * @param array<string, mixed> $record
	 * @param array<int, array<string, mixed>> $page_options
	 */
	private function render_local_location_row( array $record, int $index, array $page_options ): void {
		$name_prefix = 'ogs[local_locations][' . absint( $index ) . ']';
		$landing_id  = absint( $record['landing_page_id'] ?? 0 );
		$business_type_options = LocalSeoLocations::business_type_options();
		echo '<tr class="ogs-location-row">';
		echo '<td>';
		echo '<input type="hidden" name="' . esc_attr( $name_prefix . '[id]' ) . '" value="' . esc_attr( (string) ( $record['id'] ?? '' ) ) . '" />';
		echo '<select name="' . esc_attr( $name_prefix . '[status]' ) . '">';
		echo '<option value="draft" ' . selected( (string) ( $record['status'] ?? 'draft' ), 'draft', false ) . '>' . esc_html__( 'Draft', 'open-growth-seo' ) . '</option>';
		echo '<option value="published" ' . selected( (string) ( $record['status'] ?? 'draft' ), 'published', false ) . '>' . esc_html__( 'Published', 'open-growth-seo' ) . '</option>';
		echo '</select>';
		echo '</td>';

		echo '<td>';
		echo '<input type="text" class="regular-text" name="' . esc_attr( $name_prefix . '[name]' ) . '" value="' . esc_attr( (string) ( $record['name'] ?? '' ) ) . '" placeholder="' . esc_attr__( 'Downtown Clinic', 'open-growth-seo' ) . '" />';
		echo '<select style="margin-top:6px" name="' . esc_attr( $name_prefix . '[business_type]' ) . '">';
		foreach ( $business_type_options as $business_type_option ) {
			$value = (string) $business_type_option;
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( (string) ( $record['business_type'] ?? 'LocalBusiness' ), $value, false ) . '>' . esc_html( $value ) . '</option>';
		}
		echo '</select>';
		echo '<span class="description">' . esc_html__( 'Business entity type for this location.', 'open-growth-seo' ) . '</span>';
		echo '</td>';

		echo '<td><select name="' . esc_attr( $name_prefix . '[landing_page_id]' ) . '">';
		echo '<option value="0">' . esc_html__( 'Select landing page', 'open-growth-seo' ) . '</option>';
		foreach ( $page_options as $option ) {
			if ( ! is_array( $option ) ) {
				continue;
			}
			$option_id = absint( $option['id'] ?? 0 );
			$label     = (string) ( $option['label'] ?? '' );
			echo '<option value="' . esc_attr( (string) $option_id ) . '" ' . selected( $landing_id, $option_id, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td>';

		echo '<td><input type="text" class="regular-text" name="' . esc_attr( $name_prefix . '[phone]' ) . '" value="' . esc_attr( (string) ( $record['phone'] ?? '' ) ) . '" placeholder="+1 555 0100" /></td>';

		echo '<td>';
		echo '<input type="text" class="regular-text" name="' . esc_attr( $name_prefix . '[street]' ) . '" value="' . esc_attr( (string) ( $record['street'] ?? '' ) ) . '" placeholder="' . esc_attr__( '123 Main St', 'open-growth-seo' ) . '" />';
		echo '<input type="text" class="regular-text" style="margin-top:6px" name="' . esc_attr( $name_prefix . '[city]' ) . '" value="' . esc_attr( (string) ( $record['city'] ?? '' ) ) . '" placeholder="' . esc_attr__( 'City', 'open-growth-seo' ) . '" />';
		echo '<input type="text" class="regular-text" style="margin-top:6px" name="' . esc_attr( $name_prefix . '[region]' ) . '" value="' . esc_attr( (string) ( $record['region'] ?? '' ) ) . '" placeholder="' . esc_attr__( 'State/Region', 'open-growth-seo' ) . '" />';
		echo '<input type="text" class="regular-text" style="margin-top:6px" name="' . esc_attr( $name_prefix . '[postal_code]' ) . '" value="' . esc_attr( (string) ( $record['postal_code'] ?? '' ) ) . '" placeholder="' . esc_attr__( 'Postal code', 'open-growth-seo' ) . '" />';
		echo '<input type="text" class="regular-text" style="margin-top:6px" maxlength="2" pattern="[A-Za-z]{2}" name="' . esc_attr( $name_prefix . '[country]' ) . '" value="' . esc_attr( (string) ( $record['country'] ?? '' ) ) . '" placeholder="US" />';
		echo '<span class="description">' . esc_html__( 'Use ISO country code (2 letters), e.g. US, GB, EC.', 'open-growth-seo' ) . '</span>';
		echo '</td>';

		echo '<td>';
		echo '<input type="number" min="-90" max="90" step="0.000001" class="small-text" name="' . esc_attr( $name_prefix . '[latitude]' ) . '" value="' . esc_attr( (string) ( $record['latitude'] ?? '' ) ) . '" placeholder="40.7128" />';
		echo '<input type="number" min="-180" max="180" step="0.000001" class="small-text" style="margin-top:6px" name="' . esc_attr( $name_prefix . '[longitude]' ) . '" value="' . esc_attr( (string) ( $record['longitude'] ?? '' ) ) . '" placeholder="-74.0060" />';
		echo '<span class="description">' . esc_html__( 'Optional. If set, provide both latitude and longitude.', 'open-growth-seo' ) . '</span>';
		echo '</td>';

		echo '<td><textarea class="large-text code" rows="4" name="' . esc_attr( $name_prefix . '[opening_hours]' ) . '" placeholder="Mo-Fr 09:00-17:00&#10;Sa 10:00-13:00">' . esc_textarea( (string) ( $record['opening_hours'] ?? '' ) ) . '</textarea></td>';

		echo '<td>';
		echo '<select name="' . esc_attr( $name_prefix . '[service_mode]' ) . '">';
		echo '<option value="storefront" ' . selected( (string) ( $record['service_mode'] ?? 'storefront' ), 'storefront', false ) . '>' . esc_html__( 'Storefront', 'open-growth-seo' ) . '</option>';
		echo '<option value="service_area" ' . selected( (string) ( $record['service_mode'] ?? 'storefront' ), 'service_area', false ) . '>' . esc_html__( 'Service area', 'open-growth-seo' ) . '</option>';
		echo '<option value="hybrid" ' . selected( (string) ( $record['service_mode'] ?? 'storefront' ), 'hybrid', false ) . '>' . esc_html__( 'Hybrid', 'open-growth-seo' ) . '</option>';
		echo '</select>';
		echo '<input type="text" class="regular-text" style="margin-top:6px" name="' . esc_attr( $name_prefix . '[department_name]' ) . '" value="' . esc_attr( (string) ( $record['department_name'] ?? '' ) ) . '" placeholder="' . esc_attr__( 'Department (optional)', 'open-growth-seo' ) . '" />';
		echo '<input type="text" class="regular-text" style="margin-top:6px" name="' . esc_attr( $name_prefix . '[service_areas]' ) . '" value="' . esc_attr( (string) ( $record['service_areas'] ?? '' ) ) . '" placeholder="' . esc_attr__( 'City A, City B', 'open-growth-seo' ) . '" />';
		echo '</td>';

		echo '<td>';
		echo '<input type="text" class="regular-text" name="' . esc_attr( $name_prefix . '[seo_title]' ) . '" value="' . esc_attr( (string) ( $record['seo_title'] ?? '' ) ) . '" placeholder="' . esc_attr__( 'Location SEO title (optional)', 'open-growth-seo' ) . '" />';
		echo '<textarea class="large-text" rows="2" style="margin-top:6px" name="' . esc_attr( $name_prefix . '[seo_description]' ) . '" placeholder="' . esc_attr__( 'Location SEO description (optional)', 'open-growth-seo' ) . '">' . esc_textarea( (string) ( $record['seo_description'] ?? '' ) ) . '</textarea>';
		echo '<input type="url" class="regular-text" style="margin-top:6px" name="' . esc_attr( $name_prefix . '[seo_canonical]' ) . '" value="' . esc_attr( (string) ( $record['seo_canonical'] ?? '' ) ) . '" placeholder="https://example.com/location/" />';
		echo '</td>';

		echo '<td><button type="button" class="button-link-delete" data-role="ogs-location-remove">' . esc_html__( 'Remove', 'open-growth-seo' ) . '</button></td>';
		echo '</tr>';
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function location_page_options(): array {
		$options = array();
		$pages   = get_posts(
			array(
				'post_type'      => array( 'page' ),
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		foreach ( $pages as $page_id ) {
			$page_id = absint( $page_id );
			if ( $page_id <= 0 ) {
				continue;
			}
			$options[] = array(
				'id'    => $page_id,
				'label' => sprintf(
					/* translators: 1: title, 2: post id */
					__( '%1$s (#%2$d)', 'open-growth-seo' ),
					(string) get_the_title( $page_id ),
					$page_id
				),
			);
		}
		return $options;
	}

	public function field_text( string $key, string $label, string $value, array $context = array() ): void {
		$options  = $this->text_field_options( $key );
		$field_id = 'ogs-field-' . sanitize_html_class( $key );
		$type     = isset( $options['type'] ) ? (string) $options['type'] : 'text';
		$class    = isset( $options['class'] ) ? (string) $options['class'] : 'regular-text';
		$attrs    = isset( $options['attrs'] ) && is_array( $options['attrs'] ) ? $options['attrs'] : array();
		$help     = isset( $options['help'] ) ? (string) $options['help'] : '';
		$help_id  = '' !== $help ? $field_id . '-help' : '';
		$actions  = isset( $context['actions'] ) && is_array( $context['actions'] ) ? $context['actions'] : array();
		$template = isset( $context['template'] ) ? (string) $context['template'] : '';
		echo '<div class="ogs-field ogs-field-text">';
		echo '<div class="ogs-field__label-row">';
		echo '<label class="ogs-field__label" for="' . esc_attr( $field_id ) . '">' . esc_html( $label ) . '</label>';
		if ( ! empty( $actions ) ) {
			echo '<span class="ogs-field__label-actions">';
			foreach ( $actions as $action ) {
				if ( ! is_array( $action ) ) {
					continue;
				}
				$action_type  = isset( $action['type'] ) ? (string) $action['type'] : 'link';
				$action_label = isset( $action['label'] ) ? (string) $action['label'] : '';
				$action_url   = isset( $action['url'] ) ? (string) $action['url'] : '';
				if ( '' === $action_label ) {
					continue;
				}
				if ( 'modal' === $action_type && '' !== $template ) {
					echo '<button type="button" class="button-link ogs-inline-help-link" data-ogs-help-template="' . esc_attr( $template ) . '">' . esc_html( $action_label ) . '</button>';
					continue;
				}
				if ( '' !== $action_url ) {
					echo '<a class="ogs-inline-help-link" href="' . esc_url( $action_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $action_label ) . '</a>';
				}
			}
			echo '</span>';
		}
		echo '</div>';
		echo '<input id="' . esc_attr( $field_id ) . '" type="' . esc_attr( $type ) . '" class="' . esc_attr( $class ) . '" name="ogs[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '"';
		if ( '' !== $help_id ) {
			echo ' aria-describedby="' . esc_attr( $help_id ) . '"';
		}
		foreach ( $attrs as $attr_name => $attr_value ) {
			$attr_name  = sanitize_key( (string) $attr_name );
			$attr_value = (string) $attr_value;
			if ( '' === $attr_name || '' === $attr_value ) {
				continue;
			}
			echo ' ' . esc_attr( $attr_name ) . '="' . esc_attr( $attr_value ) . '"';
		}
		echo '/>';
		if ( '' !== $help ) {
			echo '<span id="' . esc_attr( $help_id ) . '" class="description ogs-field__help">' . esc_html( $help ) . '</span>';
		}
		if ( '' !== $template ) {
			echo '<template id="' . esc_attr( $template ) . '">';
			if ( ! empty( $context['modal_title'] ) ) {
				echo '<h3>' . esc_html( (string) $context['modal_title'] ) . '</h3>';
			}
			if ( ! empty( $context['modal_intro'] ) ) {
				echo '<p>' . esc_html( (string) $context['modal_intro'] ) . '</p>';
			}
			if ( ! empty( $context['steps'] ) && is_array( $context['steps'] ) ) {
				echo '<ol class="ogs-help-modal__steps">';
				foreach ( $context['steps'] as $step ) {
					if ( '' === trim( (string) $step ) ) {
						continue;
					}
					echo '<li>' . esc_html( (string) $step ) . '</li>';
				}
				echo '</ol>';
			}
			if ( ! empty( $context['modal_note'] ) ) {
				echo '<p class="description">' . esc_html( (string) $context['modal_note'] ) . '</p>';
			}
			if ( ! empty( $context['url'] ) ) {
				echo '<p><a class="button button-primary" href="' . esc_url( (string) $context['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open official verification page', 'open-growth-seo' ) . '</a></p>';
			}
			echo '</template>';
		}
		echo '</div>';
	}

	private function render_webmaster_verification_field( string $key, string $label, string $value ): void {
		$contexts = $this->webmaster_verification_contexts();
		$context  = isset( $contexts[ $key ] ) && is_array( $contexts[ $key ] ) ? $contexts[ $key ] : array();
		$this->field_text( $key, $label, $value, $context );
	}

	private function webmaster_verification_contexts(): array {
		return array(
			'webmaster_google_verification' => array(
				'actions'     => array(
					array(
						'type'  => 'modal',
						'label' => __( 'How to get it', 'open-growth-seo' ),
					),
					array(
						'type'  => 'link',
						'label' => __( 'Open Google Search Console', 'open-growth-seo' ),
						'url'   => 'https://search.google.com/search-console/welcome',
					),
				),
				'template'    => 'ogs-help-template-google-verification',
				'modal_title' => __( 'Google verification token', 'open-growth-seo' ),
				'modal_intro' => __( 'Use Google Search Console site ownership verification and choose the HTML tag method.', 'open-growth-seo' ),
				'steps'       => array(
					__( 'Open Google Search Console and add or select your property.', 'open-growth-seo' ),
					__( 'Choose the HTML tag verification method if Google shows several options.', 'open-growth-seo' ),
					__( 'Copy only the content value from the meta tag, not the full tag.', 'open-growth-seo' ),
					__( 'Paste that token here and save changes. The plugin will print the meta tag for you.', 'open-growth-seo' ),
				),
				'modal_note'  => __( 'Example: if Google gives a meta tag with content=\"abc123\", paste only abc123.', 'open-growth-seo' ),
				'url'         => 'https://search.google.com/search-console/welcome',
			),
			'webmaster_bing_verification' => array(
				'actions'     => array(
					array(
						'type'  => 'modal',
						'label' => __( 'How to get it', 'open-growth-seo' ),
					),
					array(
						'type'  => 'link',
						'label' => __( 'Open Bing Webmaster Tools', 'open-growth-seo' ),
						'url'   => 'https://www.bing.com/webmasters/about',
					),
				),
				'template'    => 'ogs-help-template-bing-verification',
				'modal_title' => __( 'Bing verification token', 'open-growth-seo' ),
				'modal_intro' => __( 'Use Bing Webmaster Tools site verification and choose the meta tag option.', 'open-growth-seo' ),
				'steps'       => array(
					__( 'Open Bing Webmaster Tools and add your site.', 'open-growth-seo' ),
					__( 'Choose the meta tag verification method.', 'open-growth-seo' ),
					__( 'Copy only the token from the msvalidate.01 meta tag content value.', 'open-growth-seo' ),
					__( 'Paste the token here and save changes.', 'open-growth-seo' ),
				),
				'modal_note'  => __( 'Do not paste the full HTML snippet. Paste only the token value.', 'open-growth-seo' ),
				'url'         => 'https://www.bing.com/webmasters/about',
			),
			'webmaster_yandex_verification' => array(
				'actions'     => array(
					array(
						'type'  => 'modal',
						'label' => __( 'How to get it', 'open-growth-seo' ),
					),
					array(
						'type'  => 'link',
						'label' => __( 'Open Yandex Webmaster', 'open-growth-seo' ),
						'url'   => 'https://webmaster.yandex.com/',
					),
				),
				'template'    => 'ogs-help-template-yandex-verification',
				'modal_title' => __( 'Yandex verification token', 'open-growth-seo' ),
				'modal_intro' => __( 'Use Yandex Webmaster ownership verification and select the meta tag option if available for your property.', 'open-growth-seo' ),
				'steps'       => array(
					__( 'Open Yandex Webmaster and add your site.', 'open-growth-seo' ),
					__( 'Start site ownership verification.', 'open-growth-seo' ),
					__( 'Copy the verification token value from the Yandex meta tag prompt.', 'open-growth-seo' ),
					__( 'Paste only that token here and save changes.', 'open-growth-seo' ),
				),
				'modal_note'  => __( 'If Yandex offers several verification methods, use the meta tag method when you want the plugin to manage it.', 'open-growth-seo' ),
				'url'         => 'https://webmaster.yandex.com/',
			),
			'webmaster_pinterest_verification' => array(
				'actions'     => array(
					array(
						'type'  => 'modal',
						'label' => __( 'How to get it', 'open-growth-seo' ),
					),
					array(
						'type'  => 'link',
						'label' => __( 'Open Pinterest claim guide', 'open-growth-seo' ),
						'url'   => 'https://help.pinterest.com/pt-br/business/article/claim-your-website',
					),
				),
				'template'    => 'ogs-help-template-pinterest-verification',
				'modal_title' => __( 'Pinterest verification token', 'open-growth-seo' ),
				'modal_intro' => __( 'Pinterest website claiming can provide a domain verification token for a meta tag.', 'open-growth-seo' ),
				'steps'       => array(
					__( 'Open the Pinterest website claim flow for your business account.', 'open-growth-seo' ),
					__( 'Choose the HTML tag or meta tag verification option.', 'open-growth-seo' ),
					__( 'Copy only the token from the p:domain_verify content value.', 'open-growth-seo' ),
					__( 'Paste that token here and save changes.', 'open-growth-seo' ),
				),
				'modal_note'  => __( 'Pinterest sometimes offers file upload as well. Use the meta tag method when you want this plugin to handle it.', 'open-growth-seo' ),
				'url'         => 'https://help.pinterest.com/pt-br/business/article/claim-your-website',
			),
			'webmaster_baidu_verification' => array(
				'actions'     => array(
					array(
						'type'  => 'modal',
						'label' => __( 'How to get it', 'open-growth-seo' ),
					),
					array(
						'type'  => 'link',
						'label' => __( 'Open Baidu Search Resource Platform', 'open-growth-seo' ),
						'url'   => 'https://ziyuan.baidu.com/doc/index',
					),
				),
				'template'    => 'ogs-help-template-baidu-verification',
				'modal_title' => __( 'Baidu verification token', 'open-growth-seo' ),
				'modal_intro' => __( 'Baidu site verification is managed from the Baidu Search Resource Platform after you add your site.', 'open-growth-seo' ),
				'steps'       => array(
					__( 'Open the Baidu Search Resource Platform and add your site.', 'open-growth-seo' ),
					__( 'Open the site verification workflow inside site management.', 'open-growth-seo' ),
					__( 'Choose the meta tag method when available and copy the verification token value.', 'open-growth-seo' ),
					__( 'Paste only that token here and save changes.', 'open-growth-seo' ),
				),
				'modal_note'  => __( 'Baidu can present the flow after sign-in and site creation, so the direct link opens the official platform entry point.', 'open-growth-seo' ),
				'url'         => 'https://ziyuan.baidu.com/doc/index',
			),
		);
	}

	private function render_inline_help_modal_shell(): void {
		static $rendered = false;
		if ( $rendered ) {
			return;
		}
		$rendered = true;

		echo '<div class="ogs-help-modal ogs-is-hidden" data-ogs-help-modal hidden>';
		echo '<div class="ogs-help-modal__backdrop" data-ogs-help-close="1"></div>';
		echo '<div class="ogs-help-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="ogs-help-modal-title">';
		echo '<div class="ogs-help-modal__header"><h2 id="ogs-help-modal-title">' . esc_html__( 'Verification help', 'open-growth-seo' ) . '</h2><button type="button" class="button-link ogs-help-modal__close" data-ogs-help-close="1" aria-label="' . esc_attr__( 'Close help', 'open-growth-seo' ) . '">&times;</button></div>';
		echo '<div class="ogs-help-modal__content" data-ogs-help-modal-content></div>';
		echo '</div>';
		echo '</div>';
	}

	public function field_textarea( string $key, string $label, string $value ): void {
		$field_id = 'ogs-field-' . sanitize_html_class( $key );
		echo '<div class="ogs-field ogs-field-textarea"><label class="ogs-field__label" for="' . esc_attr( $field_id ) . '">' . esc_html( $label ) . '</label><textarea id="' . esc_attr( $field_id ) . '" class="large-text code" rows="8" name="ogs[' . esc_attr( $key ) . ']">' . esc_textarea( $value ) . '</textarea></div>';
	}

	private function field_secret( string $service, string $field, string $label ): void {
		$field_id = 'ogs-secret-' . sanitize_html_class( $service . '-' . $field );
		$help_id  = $field_id . '-help';
		$summary  = \OpenGrowthSolutions\OpenGrowthSEO\Integrations\CredentialStore::describe_service( $service, array( $field ) );
		$field_meta = isset( $summary['fields'][ $field ] ) && is_array( $summary['fields'][ $field ] ) ? $summary['fields'][ $field ] : array();
		$has_value  = ! empty( $field_meta['present'] );
		$help       = $has_value
			? sprintf(
				/* translators: 1: masked secret fragment, 2: updated date */
				__( 'Stored: %1$s. Last updated: %2$s. Leave empty to keep it, or tick clear to remove it.', 'open-growth-seo' ),
				(string) ( $field_meta['masked'] ?? '••••' ),
				! empty( $field_meta['updated_at'] ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $field_meta['updated_at'] ) : __( 'unknown', 'open-growth-seo' )
			)
			: __( 'No secret stored yet. Paste a value to save it.', 'open-growth-seo' );
		echo '<div class="ogs-field ogs-field-secret"><label class="ogs-field__label" for="' . esc_attr( $field_id ) . '">' . esc_html( $label ) . '</label><input id="' . esc_attr( $field_id ) . '" type="password" class="regular-text" name="ogs_secret[' . esc_attr( $service ) . '][' . esc_attr( $field ) . ']" value="" autocomplete="new-password" aria-describedby="' . esc_attr( $help_id ) . '"/>';
		echo '<span id="' . esc_attr( $help_id ) . '" class="description ogs-field__help">' . esc_html( $help ) . '</span>';
		if ( $has_value ) {
			echo '<label class="ogs-field__checkbox"><input type="checkbox" name="ogs_secret_clear[' . esc_attr( $service ) . '][' . esc_attr( $field ) . ']" value="1" /> ' . esc_html__( 'Clear stored secret on save', 'open-growth-seo' ) . '</label>';
		}
		echo '</div>';
	}

	public function field_select( string $key, string $label, string $selected, array $options ): void {
		$field_id = 'ogs-field-' . sanitize_html_class( $key );
		echo '<div class="ogs-field ogs-field-select"><label class="ogs-field__label" for="' . esc_attr( $field_id ) . '">' . esc_html( $label ) . '</label><select id="' . esc_attr( $field_id ) . '" name="ogs[' . esc_attr( $key ) . ']">';
		foreach ( $options as $value => $name ) {
			echo '<option value="' . esc_attr( (string) $value ) . '" ' . selected( $selected, (string) $value, false ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select></div>';
	}

	public function field_checkboxes( string $key, string $label, array $selected, array $options ): void {
		$group_id = 'ogs-field-' . sanitize_html_class( $key );
		echo '<fieldset id="' . esc_attr( $group_id ) . '" class="ogs-field ogs-field-checkboxes"><legend class="ogs-field__label">' . esc_html( $label ) . '</legend>';
		foreach ( $options as $value => $name ) {
			$option_id = $group_id . '-' . sanitize_html_class( (string) $value );
			echo '<label class="ogs-field__checkbox" for="' . esc_attr( $option_id ) . '"><input id="' . esc_attr( $option_id ) . '" type="checkbox" name="ogs[' . esc_attr( $key ) . '][]" value="' . esc_attr( $value ) . '" ' . checked( in_array( $value, $selected, true ), true, false ) . '/> ' . esc_html( $name ) . '</label>';
		}
		echo '</fieldset>';
	}

	private function text_field_options( string $key ): array {
		$url_keys = array(
			'schema_org_logo',
			'gsc_property_url',
			'bing_site_url',
			'indexnow_endpoint',
			'hreflang_x_default_url',
		);
		if ( in_array( $key, $url_keys, true ) ) {
			return array(
				'type'  => 'url',
				'class' => 'regular-text',
				'help'  => __( 'Use an absolute URL with http:// or https://.', 'open-growth-seo' ),
			);
		}

		$numeric_fields = array(
			'indexnow_batch_size' => array(
				'min' => '1',
				'max' => '100',
			),
			'indexnow_rate_limit_seconds' => array(
				'min' => '10',
				'max' => '3600',
			),
			'indexnow_max_retries' => array(
				'min' => '1',
				'max' => '20',
			),
			'indexnow_queue_max_size' => array(
				'min' => '100',
				'max' => '50000',
			),
			'integration_request_timeout' => array(
				'min' => '3',
				'max' => '20',
			),
			'integration_retry_limit' => array(
				'min' => '0',
				'max' => '5',
			),
			'woo_min_product_description_words' => array(
				'min' => '30',
				'max' => '500',
			),
			'default_max_snippet' => array(
				'min' => '-1',
			),
			'default_max_video_preview' => array(
				'min' => '-1',
			),
			'redirect_404_log_limit' => array(
				'min' => '20',
				'max' => '1000',
			),
			'redirect_404_retention_days' => array(
				'min' => '1',
				'max' => '3650',
			),
		);
		if ( isset( $numeric_fields[ $key ] ) ) {
			$attrs = $numeric_fields[ $key ];
			$attrs['step']      = '1';
			$attrs['inputmode'] = 'numeric';
			$help = '';
			if ( 'redirect_404_log_limit' === $key ) {
				$help = __( 'Maximum unresolved paths kept for remediation. Older/low-signal rows are pruned first.', 'open-growth-seo' );
			}
			if ( 'redirect_404_retention_days' === $key ) {
				$help = __( 'Automatically remove 404 entries older than this window to keep diagnostics focused.', 'open-growth-seo' );
			}
			return array(
				'type'  => 'number',
				'class' => 'small-text',
				'attrs' => $attrs,
				'help'  => $help,
			);
		}

		if ( 'ga4_measurement_id' === $key ) {
			return array(
				'type'  => 'text',
				'class' => 'regular-text',
				'attrs' => array(
					'pattern' => 'G-[A-Za-z0-9]+',
				),
				'help'  => __( 'Expected format: G-XXXXXXXXXX', 'open-growth-seo' ),
			);
		}
		if ( 'indexnow_key' === $key ) {
			return array(
				'type'  => 'text',
				'class' => 'regular-text',
				'attrs' => array(
					'pattern' => '[A-Za-z0-9]{8,128}',
				),
				'help'  => __( 'Use 8 to 128 alphanumeric characters.', 'open-growth-seo' ),
			);
		}
		$verification_fields = array(
			'webmaster_google_verification'    => __( 'Paste only the verification token, not the full meta tag.', 'open-growth-seo' ),
			'webmaster_bing_verification'      => __( 'Paste only the Bing verification token, not the full HTML snippet.', 'open-growth-seo' ),
			'webmaster_yandex_verification'    => __( 'Paste only the Yandex verification token.', 'open-growth-seo' ),
			'webmaster_pinterest_verification' => __( 'Paste only the Pinterest domain verification token.', 'open-growth-seo' ),
			'webmaster_baidu_verification'     => __( 'Paste only the Baidu verification token.', 'open-growth-seo' ),
		);
		if ( isset( $verification_fields[ $key ] ) ) {
			return array(
				'type'  => 'text',
				'class' => 'regular-text',
				'help'  => $verification_fields[ $key ],
			);
		}

		return array(
			'type'  => 'text',
			'class' => 'regular-text',
		);
	}

	public function render_snippet_preview( array $settings ): void {
		$preview = SearchAppearancePreview::resolve(
			array(
				'title_template'            => (string) $settings['title_template'],
				'meta_description_template' => (string) $settings['meta_description_template'],
				'title_separator'           => (string) $settings['title_separator'],
				'site_name'                 => (string) get_bloginfo( 'name' ),
				'site_description'          => (string) get_bloginfo( 'description' ),
				'sample_title'              => __( 'Example Service Page', 'open-growth-seo' ),
				'sample_excerpt'            => __( 'Short, useful summary aligned with user intent.', 'open-growth-seo' ),
				'search_query'              => __( 'example query', 'open-growth-seo' ),
				'home_url'                  => home_url( '/' ),
				'schema_article_enabled'    => (int) ( $settings['schema_article_enabled'] ?? 0 ),
				'schema_faq_enabled'        => (int) ( $settings['schema_faq_enabled'] ?? 0 ),
				'schema_video_enabled'      => (int) ( $settings['schema_video_enabled'] ?? 0 ),
			)
		);
		$schema_hints = isset( $preview['schema_hints'] ) && is_array( $preview['schema_hints'] ) ? $preview['schema_hints'] : array();

		echo '<h2>' . esc_html__( 'Search Appearance Preview', 'open-growth-seo' ) . '</h2>';
		echo '<div class="ogs-card" id="ogs-snippet-preview" aria-live="polite">';
		echo '<p class="description">' . esc_html__( 'Indicative preview only. Final rendering depends on search engine and social platform policies.', 'open-growth-seo' ) . '</p>';
		echo '<p class="description" data-role="preview-status" role="status" aria-live="polite">' . esc_html__( 'Preview ready.', 'open-growth-seo' ) . '</p>';
		echo '<div class="ogs-preview-grid">';
		echo '<section class="ogs-preview-block ogs-preview-block-serp" aria-labelledby="ogs-preview-serp-title">';
		echo '<h3 id="ogs-preview-serp-title">' . esc_html__( 'SERP (Web)', 'open-growth-seo' ) . '</h3>';
		echo '<div class="ogs-serp-result">';
		echo '<p class="ogs-status ogs-status-good ogs-snippet-title">' . esc_html( (string) ( $preview['serp_title'] ?? '' ) ) . '</p>';
		echo '<p class="description ogs-snippet-url">' . esc_html( (string) ( $preview['home_url'] ?? home_url( '/' ) ) ) . '</p>';
		echo '<p class="ogs-snippet-desc">' . esc_html( (string) ( $preview['serp_description'] ?? '' ) ) . '</p>';
		echo '<p class="description"><span class="ogs-char-count" data-role="serp-title-count">' . esc_html( (string) ( $preview['title_count'] ?? 0 ) ) . '</span> ' . esc_html__( 'title chars', 'open-growth-seo' ) . ' | <span class="ogs-char-count" data-role="serp-desc-count">' . esc_html( (string) ( $preview['description_count'] ?? 0 ) ) . '</span> ' . esc_html__( 'description chars', 'open-growth-seo' ) . '</p>';
		echo '</div>';
		echo '</section>';

		echo '<section class="ogs-preview-block ogs-preview-block-social" aria-labelledby="ogs-preview-social-title">';
		echo '<h3 id="ogs-preview-social-title">' . esc_html__( 'Social Card', 'open-growth-seo' ) . '</h3>';
		echo '<p class="description"><strong>' . esc_html__( 'Open Graph', 'open-growth-seo' ) . ':</strong> <span data-role="og-enabled">' . esc_html( ! empty( $settings['og_enabled'] ) ? __( 'Enabled', 'open-growth-seo' ) : __( 'Disabled', 'open-growth-seo' ) ) . '</span> | <strong>' . esc_html__( 'Twitter/X', 'open-growth-seo' ) . ':</strong> <span data-role="twitter-enabled">' . esc_html( ! empty( $settings['twitter_enabled'] ) ? __( 'Enabled', 'open-growth-seo' ) : __( 'Disabled', 'open-growth-seo' ) ) . '</span></p>';
		echo '<div class="ogs-social-card">';
		echo '<div class="ogs-social-image" data-role="social-image-placeholder">' . esc_html__( 'Image optional', 'open-growth-seo' ) . '</div>';
		echo '<div class="ogs-social-card-body">';
		echo '<p class="ogs-social-title" data-role="social-title">' . esc_html( (string) ( $preview['social_title'] ?? '' ) ) . '</p>';
		echo '<p class="ogs-social-desc" data-role="social-desc">' . esc_html( (string) ( $preview['social_description'] ?? '' ) ) . '</p>';
		echo '<p class="description ogs-social-url">' . esc_html( (string) ( $preview['home_host'] ?? '' ) ) . '</p>';
		echo '</div>';
		echo '</div>';
		echo '</section>';

		echo '<section class="ogs-preview-block ogs-preview-block-rich" aria-labelledby="ogs-preview-rich-title">';
		echo '<h3 id="ogs-preview-rich-title">' . esc_html__( 'Rich Result Hints', 'open-growth-seo' ) . '</h3>';
		echo '<div class="ogs-rich-hint-card">';
		echo '<p class="ogs-rich-hint-kicker">' . esc_html__( 'Eligibility-focused signals', 'open-growth-seo' ) . '</p>';
		echo '<ul class="ogs-preview-hints" data-role="rich-hints" aria-live="polite">';
		foreach ( $schema_hints as $hint ) {
			echo '<li>' . esc_html( $hint ) . '</li>';
		}
		echo '</ul>';
		echo '<p class="description ogs-rich-hint-note">' . esc_html__( 'Hints are eligibility-oriented, not guarantees.', 'open-growth-seo' ) . '</p>';
		echo '</div>';
		echo '</section>';
		echo '</div>';
		echo '</div>';
	}

	private function preview_template( string $template, array $tokens, string $fallback ): string {
		$template = trim( $template );
		if ( '' === $template ) {
			return $fallback;
		}
		$resolved = strtr( $template, $tokens );
		$resolved = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $resolved ) ) );
		return '' !== $resolved ? $resolved : $fallback;
	}

	private function render_support_overview_section( array $support, array $diagnostics ): void {
		$status = isset( $support['status'] ) ? (string) $support['status'] : 'good';
		$attention_count = (int) ( $support['attention_count'] ?? 0 );
		$healthy_count   = (int) ( $support['healthy_count'] ?? 0 );
		$privacy         = isset( $support['privacy'] ) && is_array( $support['privacy'] ) ? $support['privacy'] : array();
		$module_statuses = isset( $support['module_statuses'] ) && is_array( $support['module_statuses'] ) ? $support['module_statuses'] : array();

		echo '<h2>' . esc_html__( 'Support overview', 'open-growth-seo' ) . '</h2>';
		echo '<div class="ogs-grid ogs-grid-overview ogs-support-overview">';
		echo '<div class="ogs-card"><h3>' . esc_html__( 'Support status', 'open-growth-seo' ) . '</h3><p class="ogs-status ' . esc_attr( $this->support_status_class( $status ) ) . '">' . esc_html( $this->support_status_label( $status ) ) . '</p><p class="description">' . esc_html( sprintf( __( '%1$d attention item(s) | %2$d healthy checks', 'open-growth-seo' ), $attention_count, $healthy_count ) ) . '</p></div>';
		echo '<div class="ogs-card"><h3>' . esc_html__( 'Privacy posture', 'open-growth-seo' ) . '</h3><p class="ogs-status ogs-status-good">' . esc_html__( 'Protected', 'open-growth-seo' ) . '</p><p class="description">' . esc_html( (string) ( $privacy['note'] ?? __( 'Credentials are excluded from exports and logs are redacted.', 'open-growth-seo' ) ) ) . '</p></div>';
		echo '<div class="ogs-card"><h3>' . esc_html__( 'Diagnostics capture', 'open-growth-seo' ) . '</h3><p class="ogs-status ' . esc_attr( ! empty( $diagnostics['diagnostic_mode'] ) || ! empty( $diagnostics['debug_logs_enabled'] ) ? 'ogs-status-warn' : 'ogs-status-good' ) . '">' . esc_html( ! empty( $diagnostics['diagnostic_mode'] ) || ! empty( $diagnostics['debug_logs_enabled'] ) ? __( 'Enabled', 'open-growth-seo' ) : __( 'Minimal', 'open-growth-seo' ) ) . '</p><p class="description">' . esc_html( sprintf( __( 'Diagnostic mode: %1$s | Debug logs: %2$s', 'open-growth-seo' ), ! empty( $diagnostics['diagnostic_mode'] ) ? __( 'On', 'open-growth-seo' ) : __( 'Off', 'open-growth-seo' ), ! empty( $diagnostics['debug_logs_enabled'] ) ? __( 'On', 'open-growth-seo' ) : __( 'Off', 'open-growth-seo' ) ) ) . '</p></div>';
		echo '<div class="ogs-card"><h3>' . esc_html__( 'Operational surface', 'open-growth-seo' ) . '</h3><p class="ogs-status ogs-status-good">' . esc_html__( 'Aligned', 'open-growth-seo' ) . '</p><p class="description">' . esc_html( sprintf( __( 'Published content: %1$d | Public post types: %2$d', 'open-growth-seo' ), (int) ( $diagnostics['published_posts'] ?? 0 ), count( (array) ( $diagnostics['public_post_types'] ?? array() ) ) ) ) . '</p></div>';
		echo '</div>';

		if ( ! empty( $module_statuses ) ) {
			echo '<div class="ogs-card ogs-support-module-status"><h3>' . esc_html__( 'Module status', 'open-growth-seo' ) . '</h3><div class="ogs-support-chip-list">';
			foreach ( $module_statuses as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$row_status = isset( $row['status'] ) ? (string) $row['status'] : 'good';
				echo '<span class="ogs-chip ' . esc_attr( $this->support_chip_class( $row_status ) ) . '">' . esc_html( sprintf( '%s: %s', (string) ( $row['label'] ?? '' ), (string) ( $row['summary'] ?? '' ) ) ) . '</span>';
			}
			echo '</div></div>';
		}
	}

	private function render_support_health_section( array $support ): void {
		$health_checks = isset( $support['health_checks'] ) && is_array( $support['health_checks'] ) ? $support['health_checks'] : array();
		$next_steps    = isset( $support['next_steps'] ) && is_array( $support['next_steps'] ) ? $support['next_steps'] : array();

		echo '<div class="ogs-grid ogs-grid-2">';
		echo '<div class="ogs-card"><h3>' . esc_html__( 'Health checks', 'open-growth-seo' ) . '</h3>';
		if ( empty( $health_checks ) ) {
			echo '<p class="ogs-empty">' . esc_html__( 'No health checks are available yet.', 'open-growth-seo' ) . '</p>';
		} else {
			echo '<table class="widefat striped ogs-support-table"><thead><tr><th>' . esc_html__( 'Area', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Status', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'What this means', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Recommended next step', 'open-growth-seo' ) . '</th></tr></thead><tbody>';
			foreach ( $health_checks as $check ) {
				if ( ! is_array( $check ) ) {
					continue;
				}
				$status = isset( $check['status'] ) ? (string) $check['status'] : 'good';
				echo '<tr><td>' . esc_html( (string) ( $check['label'] ?? '' ) ) . '</td><td><span class="ogs-chip ' . esc_attr( $this->support_chip_class( $status ) ) . '">' . esc_html( $this->support_status_label( $status ) ) . '</span></td><td>' . esc_html( (string) ( $check['summary'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $check['action'] ?? '' ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';

		echo '<div class="ogs-card"><h3>' . esc_html__( 'Recommended next steps', 'open-growth-seo' ) . '</h3>';
		if ( empty( $next_steps ) ) {
			echo '<p class="ogs-empty">' . esc_html__( 'Support checks are clear. No immediate follow-up is recommended.', 'open-growth-seo' ) . '</p>';
		} else {
			echo '<ol class="ogs-support-next-steps">';
			foreach ( $next_steps as $step ) {
				if ( ! is_array( $step ) ) {
					continue;
				}
				$status = isset( $step['status'] ) ? (string) $step['status'] : 'good';
				echo '<li><strong>' . esc_html( (string) ( $step['label'] ?? '' ) ) . '</strong> <span class="ogs-chip ' . esc_attr( $this->support_chip_class( $status ) ) . '">' . esc_html( $this->support_status_label( $status ) ) . '</span><br/>' . esc_html( (string) ( $step['action'] ?? '' ) ) . '</li>';
			}
			echo '</ol>';
		}
		echo '</div>';
		echo '</div>';
	}

	private function render_support_resources_section( array $support ): void {
		$resources = isset( $support['resources'] ) && is_array( $support['resources'] ) ? $support['resources'] : array();
		if ( empty( $resources ) ) {
			return;
		}

		echo '<div class="ogs-card ogs-support-resources"><h3>' . esc_html__( 'Support resources', 'open-growth-seo' ) . '</h3><ul class="ogs-support-resource-list">';
		foreach ( $resources as $resource ) {
			if ( ! is_array( $resource ) ) {
				continue;
			}
			echo '<li><strong>' . esc_html( (string) ( $resource['label'] ?? '' ) ) . ':</strong> <code>' . esc_html( (string) ( $resource['value'] ?? '' ) ) . '</code></li>';
		}
		echo '</ul></div>';
	}

	private function render_support_log_summary( array $support ): void {
		$log_summary = isset( $support['log_summary'] ) && is_array( $support['log_summary'] ) ? $support['log_summary'] : array();
		if ( empty( $log_summary ) ) {
			return;
		}

		$levels = isset( $log_summary['levels'] ) && is_array( $log_summary['levels'] ) ? $log_summary['levels'] : array();
		$level_bits = array();
		foreach ( $levels as $level => $count ) {
			$level_bits[] = sprintf( '%s: %d', sanitize_text_field( (string) $level ), (int) $count );
		}

		echo '<div class="ogs-card ogs-support-log-summary"><h3>' . esc_html__( 'Log summary', 'open-growth-seo' ) . '</h3>';
		echo '<p class="description">' . esc_html( sprintf( __( 'Stored entries: %1$d | Collection active: %2$s | Retention cap: %3$d', 'open-growth-seo' ), (int) ( $log_summary['total'] ?? 0 ), ! empty( $log_summary['collecting'] ) ? __( 'Yes', 'open-growth-seo' ) : __( 'No', 'open-growth-seo' ), (int) ( $log_summary['bounded_to'] ?? 0 ) ) ) . '</p>';
		if ( ! empty( $level_bits ) ) {
			echo '<p class="description">' . esc_html__( 'Levels:', 'open-growth-seo' ) . ' ' . esc_html( implode( ' | ', $level_bits ) ) . '</p>';
		}
		if ( ! empty( $log_summary['latest_time'] ) ) {
			echo '<p class="description">' . esc_html( sprintf( __( 'Most recent entry: %1$s (%2$s)', 'open-growth-seo' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $log_summary['latest_time'] ), (string) ( $log_summary['latest_level'] ?? '' ) ) ) . '</p>';
		}
		echo '</div>';
	}

	private function support_status_label( string $status ): string {
		switch ( $status ) {
			case 'critical':
				return __( 'Critical', 'open-growth-seo' );
			case 'warn':
				return __( 'Needs attention', 'open-growth-seo' );
			case 'info':
				return __( 'Informational', 'open-growth-seo' );
			default:
				return __( 'Healthy', 'open-growth-seo' );
		}
	}

	private function support_status_class( string $status ): string {
		switch ( $status ) {
			case 'critical':
				return 'ogs-status-bad';
			case 'warn':
			case 'info':
				return 'ogs-status-warn';
			default:
				return 'ogs-status-good';
		}
	}

	private function support_chip_class( string $status ): string {
		switch ( $status ) {
			case 'critical':
				return 'ogs-chip-critical';
			case 'warn':
				return 'ogs-chip-warn';
			case 'info':
				return 'ogs-chip-neutral';
			default:
				return 'ogs-chip-good';
		}
	}

	public function robots_options(): array {
		return array(
			'index,follow'    => 'index,follow',
			'noindex,follow'  => 'noindex,follow',
			'index,nofollow'  => 'index,nofollow',
			'noindex,nofollow' => 'noindex,nofollow',
		);
	}

	public function has_seo_plugin_conflict(): bool {
		return Detector::has_active_seo_plugin();
	}

	private function public_post_type_options(): array {
		$options = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $post_type => $object ) {
			$options[ $post_type ] = $object->labels->singular_name ?? $post_type;
		}
		return $options;
	}

	private function snippet_validation_messages( array $candidate, array $raw ): array {
		$messages = array();
		if ( ! empty( $candidate['default_nosnippet'] ) && '' !== (string) $candidate['default_max_snippet'] ) {
			$messages[] = __( 'Global nosnippet is enabled, so max-snippet will be ignored.', 'open-growth-seo' );
		}
		if ( isset( $raw['default_unavailable_after'] ) && '' !== trim( (string) $raw['default_unavailable_after'] ) && '' === (string) $candidate['default_unavailable_after'] ) {
			$messages[] = __( 'Global unavailable_after value was invalid and has been cleared.', 'open-growth-seo' );
		}
		$input_post_type_unavailable = isset( $raw['post_type_unavailable_after_defaults'] ) && is_array( $raw['post_type_unavailable_after_defaults'] ) ? $raw['post_type_unavailable_after_defaults'] : array();
		foreach ( $input_post_type_unavailable as $post_type => $value ) {
			$post_type = sanitize_key( (string) $post_type );
			if ( '' === $post_type || '' === trim( (string) $value ) ) {
				continue;
			}
			$sanitized = isset( $candidate['post_type_unavailable_after_defaults'][ $post_type ] ) ? (string) $candidate['post_type_unavailable_after_defaults'][ $post_type ] : '';
			if ( '' === $sanitized ) {
				$messages[] = sprintf( __( 'unavailable_after for %s was invalid and has been cleared.', 'open-growth-seo' ), $post_type );
			}
		}
		return array_values( array_unique( $messages ) );
	}

	private function hreflang_validation_messages( array $candidate ): array {
		$messages = array();
		if ( empty( $candidate['hreflang_enabled'] ) ) {
			return $messages;
		}
		$status = ( new Hreflang() )->status();
		if ( 'none' === $status['provider'] && empty( $status['manual_map'] ) ) {
			$messages[] = __( 'Hreflang is enabled but no multilingual provider or manual mapping is available.', 'open-growth-seo' );
		}
		if ( ! empty( $status['errors'] ) ) {
			foreach ( $status['errors'] as $error ) {
				$messages[] = $error;
			}
		}
		if ( 'custom' === (string) $candidate['hreflang_x_default_mode'] && '' === trim( (string) $candidate['hreflang_x_default_url'] ) ) {
			$messages[] = __( 'x-default custom mode requires a valid URL.', 'open-growth-seo' );
		}
		return array_values( array_unique( $messages ) );
	}

	private function normalization_messages( array $raw, array $candidate ): array {
		$messages = array();
		$checks   = array(
			'indexnow_endpoint'            => __( 'IndexNow endpoint was invalid and reset to a safe default endpoint.', 'open-growth-seo' ),
			'indexnow_key'                 => __( 'IndexNow key format was invalid and has been cleared.', 'open-growth-seo' ),
			'ga4_measurement_id'           => __( 'GA4 Measurement ID format was invalid and has been cleared.', 'open-growth-seo' ),
			'gsc_property_url'             => __( 'Google Search Console property URL was invalid and has been cleared.', 'open-growth-seo' ),
			'bing_site_url'                => __( 'Bing site URL was invalid and has been cleared.', 'open-growth-seo' ),
			'hreflang_x_default_url'       => __( 'x-default custom URL was invalid and has been cleared.', 'open-growth-seo' ),
			'schema_org_logo'              => __( 'Organization logo URL was invalid and has been cleared.', 'open-growth-seo' ),
			'default_max_snippet'          => __( 'Default max-snippet value was invalid and has been cleared.', 'open-growth-seo' ),
			'default_max_video_preview'    => __( 'Default max-video-preview value was invalid and has been cleared.', 'open-growth-seo' ),
			'default_unavailable_after'    => __( 'Default unavailable_after value was invalid and has been cleared.', 'open-growth-seo' ),
		);
		foreach ( $checks as $key => $message ) {
			if ( ! isset( $raw[ $key ] ) ) {
				continue;
			}
			$input_raw = trim( (string) $raw[ $key ] );
			if ( '' === $input_raw ) {
				continue;
			}
			$normalized = isset( $candidate[ $key ] ) ? trim( (string) $candidate[ $key ] ) : '';
			if ( '' === $normalized ) {
				$messages[] = $message;
			}
		}
		return array_values( array_unique( $messages ) );
	}

	/**
	 * @param array<string, mixed> $candidate
	 * @return array<int, array<string, string>>
	 */
	private function local_location_validation_messages( array $candidate ): array {
		$out = array();
		if ( empty( $candidate['schema_local_business_enabled'] ) ) {
			return $out;
		}
		$diagnostics = LocalSeoLocations::diagnostics( $candidate );
		foreach ( (array) ( $diagnostics['errors'] ?? array() ) as $error ) {
			if ( ! is_array( $error ) || empty( $error['message'] ) ) {
				continue;
			}
			$out[] = array(
				'type'    => 'error',
				'message' => (string) $error['message'],
			);
		}
		foreach ( (array) ( $diagnostics['warnings'] ?? array() ) as $warning ) {
			if ( ! is_array( $warning ) || empty( $warning['message'] ) ) {
				continue;
			}
			$out[] = array(
				'type'    => 'warning',
				'message' => (string) $warning['message'],
			);
		}
		return $out;
	}

	private function woocommerce_archive_validation_messages( array $candidate ): array {
		$messages = array();
		if ( ! class_exists( 'WooCommerce' ) ) {
			return $messages;
		}
		if ( 'index' === (string) ( $candidate['woo_filter_results'] ?? 'noindex' ) && 'none' === (string) ( $candidate['woo_filter_canonical_target'] ?? 'base' ) ) {
			$messages[] = __( 'Faceted/filter URLs are indexable without canonical consolidation. This can increase duplicate archive risk.', 'open-growth-seo' );
		}
		if ( 'index' === (string) ( $candidate['woo_filter_results'] ?? 'noindex' ) && 'self' === (string) ( $candidate['woo_filter_canonical_target'] ?? 'base' ) ) {
			$messages[] = __( 'Faceted/filter URLs are indexable with self-canonical policy. Confirm this is intentional and that filter combinations are high-value landing pages.', 'open-growth-seo' );
		}
		if ( 'noindex' === (string) ( $candidate['woo_filter_results'] ?? 'noindex' ) && 'none' === (string) ( $candidate['woo_filter_canonical_target'] ?? 'base' ) ) {
			$messages[] = __( 'Faceted/filter URLs are noindex and emit no canonical target. Prefer base canonical policy unless this crawl surface is intentionally diagnostic-only.', 'open-growth-seo' );
		}
		if ( 'noindex' === (string) ( $candidate['woo_pagination_results'] ?? 'index' ) && 'self' === (string) ( $candidate['woo_pagination_canonical_target'] ?? 'self' ) ) {
			$messages[] = __( 'Woo archive pagination is set to noindex while still self-canonicalizing. Review if first-page canonical consolidation is more appropriate.', 'open-growth-seo' );
		}
		if ( 'index' === (string) ( $candidate['woo_pagination_results'] ?? 'index' ) && 'first_page' === (string) ( $candidate['woo_pagination_canonical_target'] ?? 'self' ) ) {
			$messages[] = __( 'Woo archive pagination is indexable but canonicalized to first page. This can create conflicting indexation signals for deeper paginated URLs.', 'open-growth-seo' );
		}
		if ( 'index' === (string) ( $candidate['woo_product_tag_archive'] ?? 'noindex' ) && 'noindex' === (string) ( $candidate['woo_filter_results'] ?? 'noindex' ) ) {
			$messages[] = __( 'Product tags are indexable while faceted URLs are noindex. Confirm this is intentional to avoid thin taxonomy drift.', 'open-growth-seo' );
		}
		return array_values( array_unique( $messages ) );
	}

	public function render_hreflang_fields( array $settings ): void {
		$status   = ( new Hreflang() )->status();
		$provider = (string) ( $status['provider'] ?? 'none' );
		echo '<h2>' . esc_html__( 'Hreflang and International SEO', 'open-growth-seo' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Emit alternates only when equivalent pages exist across language/region variants.', 'open-growth-seo' ) . '</p>';
		$this->field_select(
			'hreflang_enabled',
			__( 'Enable hreflang', 'open-growth-seo' ),
			(string) $settings['hreflang_enabled'],
			array(
				'1' => __( 'Enabled', 'open-growth-seo' ),
				'0' => __( 'Disabled', 'open-growth-seo' ),
			)
		);
		$this->field_select(
			'hreflang_x_default_mode',
			__( 'x-default mode', 'open-growth-seo' ),
			(string) $settings['hreflang_x_default_mode'],
			array(
				'auto' => __( 'Auto', 'open-growth-seo' ),
				'custom' => __( 'Custom URL', 'open-growth-seo' ),
				'none' => __( 'Disabled', 'open-growth-seo' ),
			)
		);
		$this->field_text( 'hreflang_x_default_url', __( 'x-default custom URL', 'open-growth-seo' ), (string) $settings['hreflang_x_default_url'] );
		$this->field_textarea( 'hreflang_manual_map', __( 'Manual alternates map (one per line: code|url)', 'open-growth-seo' ), (string) $settings['hreflang_manual_map'] );
		echo '<p class="description">' . esc_html__( 'Manual map is used as safe fallback, especially for homepage when no multilingual plugin is detected.', 'open-growth-seo' ) . '</p>';
		echo '<table class="widefat striped" style="max-width:920px"><tbody>';
		echo '<tr><th>' . esc_html__( 'Detected provider', 'open-growth-seo' ) . '</th><td>' . esc_html( $provider ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Detected languages', 'open-growth-seo' ) . '</th><td>' . esc_html( implode( ', ', (array) ( $status['languages'] ?? array() ) ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Current page alternates', 'open-growth-seo' ) . '</th><td>' . esc_html( (string) count( (array) ( $status['sample'] ?? array() ) ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'x-default', 'open-growth-seo' ) . '</th><td>' . esc_html( (string) ( $status['x_default'] ?? '' ) ) . '</td></tr>';
		echo '</tbody></table>';
		$this->render_rest_action( __( 'REST Hreflang Status', 'open-growth-seo' ), 'ogs-seo/v1/hreflang/status' );
	}

	public function render_rest_action( string $label, string $route, bool $wrap = true ): void {
		$endpoint = esc_url_raw( rest_url( ltrim( $route, '/' ) ) );
		if ( $wrap ) {
			echo '<div class="ogs-rest-viewer">';
		}
		echo '<div class="ogs-rest-toolbar">';
		echo '<button type="button" class="button ogs-rest-action" data-endpoint="' . esc_attr( $endpoint ) . '" data-default-label="' . esc_attr__( 'Show response', 'open-growth-seo' ) . '" data-refresh-label="' . esc_attr__( 'Refresh response', 'open-growth-seo' ) . '" data-hide-label="' . esc_attr__( 'Hide response', 'open-growth-seo' ) . '" aria-expanded="false">' . esc_html( $label ) . '</button> ';
		echo '<button type="button" class="button-link ogs-rest-copy" data-endpoint="' . esc_attr( $endpoint ) . '">' . esc_html__( 'Copy route', 'open-growth-seo' ) . '</button>';
		echo '</div>';
		echo '<p class="description ogs-rest-note">' . esc_html__( 'Protected REST routes are fetched here with your admin session and REST nonce. Opening the raw route directly in a new tab will usually return rest_forbidden.', 'open-growth-seo' ) . '</p>';
		echo '<code class="ogs-rest-route">' . esc_html( $endpoint ) . '</code>';
		echo '<pre class="ogs-rest-response ogs-is-hidden" aria-live="polite"></pre>';
		if ( $wrap ) {
			echo '</div>';
		}
	}
}


