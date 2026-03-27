<?php
use PHPUnit\Framework\TestCase;

final class RedirectsModuleRegressionTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['ogs_test_options'] = array();
	}

	public function test_redirects_class_and_core_methods_exist(): void {
		$this->assertTrue( class_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\SEO\\Redirects' ) );
		$this->assertTrue( class_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\SEO\\RedirectStore' ) );
		$this->assertTrue( class_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\SEO\\RedirectImporter' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\SEO\\Redirects', 'add_rule' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\SEO\\Redirects', 'delete_rule' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\SEO\\Redirects', 'toggle_rule' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\SEO\\Redirects', 'status_payload' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\SEO\\RedirectImporter', 'dry_run' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\SEO\\RedirectImporter', 'run_import' ) );
	}

	public function test_settings_sanitize_redirect_controls(): void {
		$sanitized = \OpenGrowthSolutions\OpenGrowthSEO\Support\Settings::sanitize(
			array(
				'redirects_enabled'            => '1',
				'redirect_404_logging_enabled' => '0',
				'redirect_404_log_limit'       => '5000',
				'redirect_404_retention_days'  => '7000',
			)
		);
		$this->assertSame( 1, $sanitized['redirects_enabled'] );
		$this->assertSame( 0, $sanitized['redirect_404_logging_enabled'] );
		$this->assertSame( 1000, $sanitized['redirect_404_log_limit'] );
		$this->assertSame( 3650, $sanitized['redirect_404_retention_days'] );
	}

	public function test_redirect_rule_validation_prevents_external_urls_duplicates_and_loops(): void {
		$external = \OpenGrowthSolutions\OpenGrowthSEO\SEO\Redirects::add_rule(
			array(
				'source_path'     => '/old-page',
				'destination_url' => 'https://external.example.com/new-page',
				'match_type'      => 'exact',
				'status_code'     => 301,
				'enabled'         => 1,
			)
		);
		$this->assertFalse( $external['ok'] );

		$first = \OpenGrowthSolutions\OpenGrowthSEO\SEO\Redirects::add_rule(
			array(
				'source_path'     => '/old-page',
				'destination_url' => '/new-page',
				'match_type'      => 'exact',
				'status_code'     => 301,
				'enabled'         => 1,
			)
		);
		$this->assertTrue( $first['ok'] );

		$duplicate = \OpenGrowthSolutions\OpenGrowthSEO\SEO\Redirects::add_rule(
			array(
				'source_path'     => '/old-page',
				'destination_url' => '/another',
				'match_type'      => 'exact',
				'status_code'     => 301,
				'enabled'         => 1,
			)
		);
		$this->assertFalse( $duplicate['ok'] );

		$loop = \OpenGrowthSolutions\OpenGrowthSEO\SEO\Redirects::add_rule(
			array(
				'source_path'     => '/same',
				'destination_url' => '/same',
				'match_type'      => 'exact',
				'status_code'     => 301,
				'enabled'         => 1,
			)
		);
		$this->assertFalse( $loop['ok'] );
	}

	public function test_toggle_and_delete_rule_are_persistent(): void {
		$add = \OpenGrowthSolutions\OpenGrowthSEO\SEO\Redirects::add_rule(
			array(
				'source_path'     => '/legacy',
				'destination_url' => '/modern',
				'match_type'      => 'exact',
				'status_code'     => 301,
				'enabled'         => 1,
			)
		);
		$this->assertTrue( $add['ok'] );

		$rules = \OpenGrowthSolutions\OpenGrowthSEO\SEO\Redirects::get_rules();
		$this->assertCount( 1, $rules );
		$rule_id = (string) $rules[0]['id'];

		$disabled = \OpenGrowthSolutions\OpenGrowthSEO\SEO\Redirects::toggle_rule( $rule_id, false );
		$this->assertTrue( $disabled['ok'] );
		$rules = \OpenGrowthSolutions\OpenGrowthSEO\SEO\Redirects::get_rules();
		$this->assertSame( 0, (int) $rules[0]['enabled'] );

		$deleted = \OpenGrowthSolutions\OpenGrowthSEO\SEO\Redirects::delete_rule( $rule_id );
		$this->assertTrue( $deleted['ok'] );
		$this->assertSame( array(), \OpenGrowthSolutions\OpenGrowthSEO\SEO\Redirects::get_rules() );
	}

	public function test_audit_checks_include_high_volume_404_issue(): void {
		update_option(
			'ogs_seo_404_log',
			array(
				array(
					'path'       => '/missing-page',
					'hits'       => 9,
					'first_seen' => time() - 1000,
					'last_seen'  => time(),
				),
			),
			false
		);
		$redirects = new \OpenGrowthSolutions\OpenGrowthSEO\SEO\Redirects();
		$issues    = $redirects->audit_checks();
		$this->assertNotEmpty( $issues );
		$this->assertSame( 'High-volume 404 URL detected', (string) $issues[0]['title'] );
	}

	public function test_storage_mode_falls_back_to_option_without_database_tables(): void {
		$status = \OpenGrowthSolutions\OpenGrowthSEO\SEO\Redirects::status_payload();
		$this->assertSame( 'option', (string) ( $status['storage_mode'] ?? '' ) );
	}

	public function test_redirect_importer_dry_run_classifies_ready_duplicates_and_conflicts(): void {
		update_option(
			'wpseo-premium-redirects',
			array(
				array(
					'origin' => '/legacy-a',
					'url'    => '/target-a',
					'type'   => 301,
				),
				array(
					'origin' => '/legacy-a',
					'url'    => '/target-a-2',
					'type'   => 301,
				),
				array(
					'origin' => '/bad-source',
					'url'    => 'https://external.example.com/not-allowed',
					'type'   => 301,
				),
			),
			false
		);

		$seed = \OpenGrowthSolutions\OpenGrowthSEO\SEO\Redirects::add_rule(
			array(
				'source_path'     => '/legacy-a',
				'destination_url' => '/target-a',
				'match_type'      => 'exact',
				'status_code'     => 301,
				'enabled'         => 1,
			)
		);
		$this->assertTrue( $seed['ok'] );

		$report = \OpenGrowthSolutions\OpenGrowthSEO\SEO\RedirectImporter::dry_run( array( 'yoast' ), true );
		$this->assertIsArray( $report );
		$this->assertSame( 'merge', (string) ( $report['mode'] ?? '' ) );
		$this->assertArrayHasKey( 'summary', $report );
		$this->assertArrayHasKey( 'rows', $report );
		$this->assertArrayHasKey( 'conflict_preview', $report );
		$this->assertArrayHasKey( 'rollback_plan', $report );
		$this->assertIsArray( $report['conflict_preview'] );
		$this->assertIsArray( $report['rollback_plan'] );
		$this->assertArrayHasKey( 'plan_id', $report['rollback_plan'] );
		$this->assertArrayHasKey( 'requires_confirm', $report['rollback_plan'] );
		$this->assertGreaterThanOrEqual( 1, (int) ( $report['summary']['counts']['duplicate_existing'] ?? 0 ) );
		$this->assertGreaterThanOrEqual( 1, (int) ( $report['summary']['counts']['conflict_existing'] ?? 0 ) );
		$this->assertGreaterThanOrEqual( 1, (int) ( $report['summary']['counts']['invalid_destination'] ?? 0 ) );
	}

	public function test_redirect_importer_persists_rollback_plan_and_execution_metadata(): void {
		update_option(
			'rank_math_redirections',
			array(
				array(
					'source' => '/rank-legacy',
					'url_to' => '/rank-target',
					'type'   => 301,
				),
			),
			false
		);

		$preview = \OpenGrowthSolutions\OpenGrowthSEO\SEO\RedirectImporter::dry_run( array( 'rankmath' ), false );
		$plan    = \OpenGrowthSolutions\OpenGrowthSEO\SEO\RedirectImporter::get_last_rollback_plan();
		$this->assertNotEmpty( $plan );
		$this->assertSame( false, (bool) ( $plan['executed'] ?? true ) );
		$this->assertSame( 'replace', (string) ( $preview['mode'] ?? '' ) );

		$run = \OpenGrowthSolutions\OpenGrowthSEO\SEO\RedirectImporter::run_import( array( 'rankmath' ), true, true );
		$this->assertTrue( (bool) ( $run['ok'] ?? false ) );
		$this->assertArrayHasKey( 'rollback_plan', $run );
		$this->assertTrue( (bool) ( $run['rollback_plan']['executed'] ?? false ) );
		$this->assertArrayHasKey( 'before_rules_hash', $run['rollback_plan'] );
	}

	public function test_redirect_importer_can_rollback_last_import_snapshot(): void {
		$seed = \OpenGrowthSolutions\OpenGrowthSEO\SEO\Redirects::add_rule(
			array(
				'source_path'     => '/pre-import',
				'destination_url' => '/baseline',
				'match_type'      => 'exact',
				'status_code'     => 301,
				'enabled'         => 1,
			)
		);
		$this->assertTrue( $seed['ok'] );

		update_option(
			'aioseo_redirects',
			array(
				array(
					'source' => '/imported-a',
					'url_to' => '/target-a',
					'type'   => 301,
				),
				array(
					'source' => '/imported-b',
					'url_to' => '/target-b',
					'type'   => 301,
				),
			),
			false
		);

		$run = \OpenGrowthSolutions\OpenGrowthSEO\SEO\RedirectImporter::run_import( array( 'aioseo' ), false, true );
		$this->assertTrue( (bool) ( $run['ok'] ?? false ) );
		$this->assertTrue( \OpenGrowthSolutions\OpenGrowthSEO\SEO\RedirectImporter::has_rollback_snapshot() );

		$rollback = \OpenGrowthSolutions\OpenGrowthSEO\SEO\RedirectImporter::rollback_last_import();
		$this->assertTrue( (bool) ( $rollback['ok'] ?? false ) );
		$this->assertFalse( \OpenGrowthSolutions\OpenGrowthSEO\SEO\RedirectImporter::has_rollback_snapshot() );

		$rules = \OpenGrowthSolutions\OpenGrowthSEO\SEO\Redirects::get_rules();
		$this->assertCount( 1, $rules );
		$this->assertSame( '/pre-import', (string) ( $rules[0]['source_path'] ?? '' ) );
		$this->assertSame( '/baseline', (string) wp_parse_url( (string) ( $rules[0]['destination_url'] ?? '' ), PHP_URL_PATH ) );
	}

	public function test_redirect_importer_can_rollback_to_an_empty_rule_set(): void {
		update_option(
			'rank_math_redirections',
			array(
				array(
					'source' => '/fresh-import',
					'url_to' => '/fresh-target',
					'type'   => 301,
				),
			),
			false
		);

		$run = \OpenGrowthSolutions\OpenGrowthSEO\SEO\RedirectImporter::run_import( array( 'rankmath' ), true, true );
		$this->assertTrue( (bool) ( $run['ok'] ?? false ) );
		$this->assertTrue( \OpenGrowthSolutions\OpenGrowthSEO\SEO\RedirectImporter::has_rollback_snapshot() );

		$rollback = \OpenGrowthSolutions\OpenGrowthSEO\SEO\RedirectImporter::rollback_last_import();
		$this->assertTrue( (bool) ( $rollback['ok'] ?? false ) );
		$this->assertSame( array(), \OpenGrowthSolutions\OpenGrowthSEO\SEO\Redirects::get_rules() );
	}

	public function test_redirect_importer_requires_confirmation_for_run(): void {
		update_option(
			'rank_math_redirections',
			array(
				array(
					'source' => '/rank-old',
					'url_to' => '/rank-new',
					'type'   => 301,
				),
			),
			false
		);

		$result = \OpenGrowthSolutions\OpenGrowthSEO\SEO\RedirectImporter::run_import( array( 'rankmath' ), true, false );
		$this->assertFalse( (bool) ( $result['ok'] ?? true ) );
		$this->assertArrayHasKey( 'preview', $result );
	}

	public function test_redirects_are_wired_into_plugin_admin_and_rest(): void {
		$plugin_source = file_get_contents( __DIR__ . '/../../includes/Core/Plugin.php' );
		$admin_source  = file_get_contents( __DIR__ . '/../../includes/Admin/Admin.php' );
		$rest_source   = file_get_contents( __DIR__ . '/../../includes/REST/Routes.php' );
		$rest_domain   = file_get_contents( __DIR__ . '/../../includes/REST/TechnicalRoutesRegistrar.php' );
		$audit_source  = file_get_contents( __DIR__ . '/../../includes/SEO/Redirects.php' );
		$this->assertIsString( $plugin_source );
		$this->assertIsString( $admin_source );
		$this->assertIsString( $rest_source );
		$this->assertIsString( $rest_domain );
		$this->assertIsString( $audit_source );
		$this->assertStringContainsString( "'redirects'", $plugin_source );
		$this->assertStringContainsString( 'Redirects::class', $plugin_source );
		$this->assertStringContainsString( 'new ServiceRegistry', $plugin_source );
		$this->assertStringContainsString( 'ogs-seo-redirects', $admin_source );
		$this->assertStringContainsString( 'ogs_seo_redirect_add', $admin_source );
		$this->assertStringContainsString( 'ogs_seo_redirect_import_dry_run', $admin_source );
		$this->assertStringContainsString( 'ogs_seo_redirect_import_rollback', $admin_source );
		$this->assertStringContainsString( 'new TechnicalRoutesRegistrar()', $rest_source );
		$this->assertStringContainsString( '/redirects/status', $rest_domain );
		$this->assertStringContainsString( '/redirects/import-dry-run', $rest_domain );
		$this->assertStringContainsString( '/redirects/import-rollback', $rest_domain );
		$this->assertStringContainsString( 'last_rollback_plan', $rest_source );
		$this->assertStringContainsString( 'register_audit_checks', $audit_source );
	}
}
