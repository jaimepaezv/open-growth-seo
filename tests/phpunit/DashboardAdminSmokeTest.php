<?php
use PHPUnit\Framework\TestCase;

final class DashboardAdminSmokeTest extends TestCase {
	public function test_admin_class_exists(): void {
		$this->assertTrue( class_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Admin\\Admin' ) );
		$this->assertTrue( class_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Admin\\SearchAppearancePage' ) );
		$this->assertTrue( class_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Admin\\RedirectsPage' ) );
	}

	public function test_dashboard_methods_exist(): void {
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Admin\\Admin', 'render_dashboard' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Admin\\Admin', 'dashboard_data' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Admin\\Admin', 'build_activity' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Admin\\Admin', 'render_rest_action' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Admin\\Admin', 'render_page_intro' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Admin\\Admin', 'page_documentation_actions' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Admin\\Admin', 'documentation_map' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Admin\\Admin', 'documentation_url' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Admin\\Admin', 'render_workflow_continuity' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Admin\\Admin', 'product_state_snapshot' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Admin\\Admin', 'workflow_stages' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Admin\\Admin', 'page_related_actions' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Admin\\Admin', 'schema_mapping_export_action' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Admin\\Admin', 'schema_mapping_import_action' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Admin\\SearchAppearancePage', 'render' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Admin\\RedirectsPage', 'render' ) );
	}

	public function test_sort_issues_prioritizes_critical_over_minor(): void {
		$admin      = new \OpenGrowthSolutions\OpenGrowthSEO\Admin\Admin();
		$reflection = new \ReflectionClass( $admin );
		$method     = $reflection->getMethod( 'sort_issues' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$admin,
			array( 'severity' => 'critical' ),
			array( 'severity' => 'minor' )
		);

		$this->assertLessThan( 0, $result );
	}

	public function test_has_issue_keywords_is_case_insensitive_and_supports_severity_filter(): void {
		$admin      = new \OpenGrowthSolutions\OpenGrowthSEO\Admin\Admin();
		$reflection = new \ReflectionClass( $admin );
		$method     = $reflection->getMethod( 'has_issue_keywords' );
		$method->setAccessible( true );
		$issues = array(
			array(
				'title'    => 'Answer-First signal missing in recent content',
				'severity' => 'important',
			),
			array(
				'title'    => 'Sitemap index XML is invalid',
				'severity' => 'critical',
			),
		);

		$this->assertTrue( $method->invoke( $admin, $issues, array( 'answer-first' ) ) );
		$this->assertTrue( $method->invoke( $admin, $issues, array( 'sitemap' ), 'critical' ) );
		$this->assertFalse( $method->invoke( $admin, $issues, array( 'sitemap' ), 'minor' ) );
	}

	public function test_dashboard_script_uses_dom_rendering_without_inner_html_assignment(): void {
		$script = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/dashboard.js' );
		$this->assertIsString( $script );
		$this->assertStringContainsString( 'document.createElement', $script );
		$this->assertStringNotContainsString( 'innerHTML =', $script );
	}

	public function test_text_field_options_assigns_specific_types_for_url_and_numeric_fields(): void {
		$admin      = new \OpenGrowthSolutions\OpenGrowthSEO\Admin\Admin();
		$reflection = new \ReflectionClass( $admin );
		$method     = $reflection->getMethod( 'text_field_options' );
		$method->setAccessible( true );

		$url = $method->invoke( $admin, 'indexnow_endpoint' );
		$num = $method->invoke( $admin, 'indexnow_batch_size' );

		$this->assertSame( 'url', $url['type'] );
		$this->assertSame( 'number', $num['type'] );
		$this->assertSame( '1', $num['attrs']['min'] );
		$this->assertSame( '100', $num['attrs']['max'] );
	}

	public function test_normalization_messages_report_invalid_non_empty_values(): void {
		$admin      = new \OpenGrowthSolutions\OpenGrowthSEO\Admin\Admin();
		$reflection = new \ReflectionClass( $admin );
		$method     = $reflection->getMethod( 'normalization_messages' );
		$method->setAccessible( true );

		$messages = $method->invoke(
			$admin,
			array(
				'ga4_measurement_id' => 'bad',
				'indexnow_key'       => '***',
			),
			array(
				'ga4_measurement_id' => '',
				'indexnow_key'       => '',
			)
		);

		$this->assertCount( 2, $messages );
		$this->assertStringContainsString( 'GA4', $messages[0] . ' ' . $messages[1] );
	}

	public function test_admin_rest_debug_uses_authenticated_viewer_instead_of_direct_raw_links(): void {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/Admin/Admin.php' );
		$this->assertIsString( $source );
		$this->assertStringContainsString( 'class="button ogs-rest-action"', $source );
		$this->assertStringNotContainsString( 'target="_blank" rel="noopener noreferrer" href="\' . esc_url( rest_url( \'ogs-seo/v1/', $source );
	}

	public function test_admin_rest_script_uses_rest_nonce_header(): void {
		$script = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/admin-rest.js' );
		$this->assertIsString( $script );
		$this->assertStringContainsString( 'X-WP-Nonce', $script );
		$this->assertStringContainsString( 'credentials: \'same-origin\'', $script );
		$this->assertStringContainsString( 'data-ogs-help-template', $script );
		$this->assertStringContainsString( 'Verification help', $script );
	}

	public function test_simple_mode_hides_advanced_sections_from_navigation(): void {
		$admin_source  = file_get_contents( dirname( __DIR__, 2 ) . '/includes/Admin/Admin.php' );
		$search_source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/Admin/SearchAppearancePage.php' );
		$this->assertIsString( $admin_source );
		$this->assertIsString( $search_source );
		$this->assertStringContainsString( "'advanced'    => true", $admin_source );
		$this->assertStringContainsString( "Show advanced sections", $admin_source );
		$this->assertStringContainsString( 'if ( $simple && ! $show_advanced && ! empty( $pages[ $slug ][\'advanced\'] ) )', $admin_source );
		$this->assertStringContainsString( 'ogs-seo-masters-plus', $admin_source );
		$this->assertStringContainsString( 'SEO MASTERS PLUS', $admin_source );
		$this->assertStringContainsString( 'Workspaces', $admin_source );
		$this->assertStringContainsString( 'Operations', $admin_source );
		$this->assertStringContainsString( 'System', $admin_source );
		$this->assertStringContainsString( 'Advanced hreflang settings are hidden in Simple mode.', $search_source );
	}

	public function test_admin_css_includes_page_header_navigation_and_save_bar_patterns(): void {
		$css = file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/admin.css' );
		$this->assertIsString( $css );
		$this->assertStringContainsString( '.ogs-page-header', $css );
		$this->assertStringContainsString( '.ogs-admin-nav__link', $css );
		$this->assertStringContainsString( '.ogs-save-bar', $css );
		$this->assertStringContainsString( '.ogs-field__label', $css );
		$this->assertStringContainsString( '.ogs-workflow-panel', $css );
		$this->assertStringContainsString( '.ogs-workflow-stage', $css );
	}

	public function test_admin_shell_includes_product_workflow_continuity_panel(): void {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/Admin/Admin.php' );
		$this->assertIsString( $source );
		$this->assertStringContainsString( 'render_workflow_continuity', $source );
		$this->assertStringContainsString( 'Workflow continuity', $source );
		$this->assertStringContainsString( 'Connected next steps', $source );
		$this->assertStringContainsString( 'Search outputs', $source );
		$this->assertStringContainsString( 'Operations and support', $source );
	}

	public function test_page_intro_supports_contextual_documentation_links_when_docs_exist(): void {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/Admin/Admin.php' );
		$this->assertIsString( $source );
		$this->assertStringContainsString( 'private function page_documentation_actions', $source );
		$this->assertStringContainsString( 'private function documentation_map', $source );
		$this->assertStringContainsString( 'private function documentation_url', $source );
		$this->assertStringContainsString( 'USER_GUIDE.md', $source );
		$this->assertStringContainsString( 'TESTING.md', $source );
		$this->assertStringContainsString( 'ARCHITECTURE.md', $source );
		$this->assertStringContainsString( 'SECURITY.md', $source );
	}

	public function test_settings_screen_supports_inline_webmaster_help_actions(): void {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/Admin/Admin.php' );
		$this->assertIsString( $source );
		$this->assertStringContainsString( 'render_webmaster_verification_field', $source );
		$this->assertStringContainsString( 'webmaster_verification_contexts', $source );
		$this->assertStringContainsString( 'How to get it', $source );
		$this->assertStringContainsString( 'Open Google Search Console', $source );
		$this->assertStringContainsString( 'https://search.google.com/search-console/welcome', $source );
		$this->assertStringContainsString( 'ogs-help-template-google-verification', $source );
	}

	public function test_issue_actions_return_guided_targets_for_settings_and_content(): void {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/Admin/Admin.php' );
		$this->assertIsString( $source );
		$this->assertStringContainsString( 'private function issue_actions', $source );
		$this->assertStringContainsString( 'private function issue_target_url', $source );
		$this->assertStringContainsString( 'private function issue_settings_page', $source );
		$this->assertStringContainsString( 'return \'post.php?post=\' . $post_id . \'&action=edit\';', $source );
		$this->assertStringContainsString( "return 'admin.php?page=' . rawurlencode( \$page );", $source );
	}

	public function test_audit_screen_supports_safe_fixes_and_action_cards(): void {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/Admin/Admin.php' );
		$this->assertIsString( $source );
		$this->assertStringContainsString( 'admin_post_ogs_seo_fix_issue', $source );
		$this->assertStringContainsString( 'Apply safe fix', $source );
		$this->assertStringContainsString( 'private function render_issue_card', $source );
		$this->assertStringContainsString( 'private function render_content_coaching_card', $source );
	}

	public function test_schema_admin_supports_cpt_presets(): void {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/Admin/Admin.php' );
		$this->assertIsString( $source );
		$this->assertStringContainsString( 'render_schema_preset_card', $source );
		$this->assertStringContainsString( 'schema_preset_records', $source );
		$this->assertStringContainsString( 'apply_schema_post_type_preset', $source );
		$this->assertStringContainsString( 'Industry presets for CPT schema mappings', $source );
		$this->assertStringContainsString( 'University / Higher education', $source );
		$this->assertStringContainsString( 'Clinic / Healthcare practice', $source );
		$this->assertStringContainsString( 'Marketplace / Directory', $source );
		$this->assertStringContainsString( 'Publisher / Media / Content hub', $source );
		$this->assertStringContainsString( 'Use when:', $source );
		$this->assertStringContainsString( 'preserve unrelated mappings already saved', $source );
		$this->assertStringContainsString( 'Export CPT schema mappings', $source );
		$this->assertStringContainsString( 'Import CPT schema mappings', $source );
		$this->assertStringContainsString( 'schema_mapping_export_payload', $source );
		$this->assertStringContainsString( 'schema_mapping_payload_defaults', $source );
		$this->assertStringContainsString( 'sanitize_schema_post_type_defaults', $source );
		$this->assertStringContainsString( 'Download mapping JSON', $source );
		$this->assertStringContainsString( 'Import mappings', $source );
		$this->assertStringContainsString( 'Apply preset', $source );
	}

	public function test_editor_supports_saved_schema_runtime_preview(): void {
		$editor_source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/Admin/Editor.php' );
		$script_source = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/editor.js' );
		$this->assertIsString( $editor_source );
		$this->assertIsString( $script_source );
		$this->assertStringContainsString( 'schema_preview_ajax', $editor_source );
		$this->assertStringContainsString( 'schema_runtime_preview_state', $editor_source );
		$this->assertStringContainsString( 'render_schema_runtime_preview', $editor_source );
		$this->assertStringContainsString( 'Final schema preview', $editor_source );
		$this->assertStringContainsString( 'schemaPreviewAjaxUrl', $script_source );
		$this->assertStringContainsString( 'schemaPreviewRefreshText', $script_source );
		$this->assertStringContainsString( 'ogs-schema-preview-json', $script_source );
	}
}
