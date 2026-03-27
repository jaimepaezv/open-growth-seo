<?php
use PHPUnit\Framework\TestCase;

final class DeveloperToolsModuleSmokeTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['ogs_test_options'] = array();
		$GLOBALS['ogs_test_filters'] = array();
		$GLOBALS['ogs_test_actions'] = array();
	}

	protected function tearDown(): void {
		remove_all_filters( 'ogs_seo_dev_tools_diagnostics' );
		remove_all_filters( 'ogs_seo_dev_tools_export_payload' );
		remove_all_filters( 'ogs_seo_dev_tools_import_payload' );
		parent::tearDown();
	}

	public function test_developer_tools_class_exists(): void {
		$this->assertTrue( class_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Support\\DeveloperTools' ) );
	}

	public function test_diagnostics_and_export_include_expected_fields_and_allow_filters(): void {
		add_filter(
			'ogs_seo_dev_tools_diagnostics',
			static function ( array $payload ): array {
				$payload['custom_flag'] = 'ok';
				return $payload;
			}
		);
		add_filter(
			'ogs_seo_dev_tools_export_payload',
			static function ( array $payload ): array {
				$payload['schema_version'] = 2;
				return $payload;
			}
		);

		$diagnostics = \OpenGrowthSolutions\OpenGrowthSEO\Support\DeveloperTools::diagnostics();
		$export      = \OpenGrowthSolutions\OpenGrowthSEO\Support\DeveloperTools::export_payload();

		$this->assertArrayHasKey( 'timestamp', $diagnostics );
		$this->assertArrayHasKey( 'plugin_version', $diagnostics );
		$this->assertArrayHasKey( 'wp_version', $diagnostics );
		$this->assertArrayHasKey( 'installation_status', $diagnostics );
		$this->assertArrayHasKey( 'installation_primary', $diagnostics );
		$this->assertArrayHasKey( 'support_status', $diagnostics );
		$this->assertArrayHasKey( 'support_attention_count', $diagnostics );
		$this->assertArrayHasKey( 'support', $diagnostics );
		$this->assertIsArray( $diagnostics['support'] );
		$this->assertArrayHasKey( 'health_checks', $diagnostics['support'] );
		$this->assertArrayHasKey( 'privacy', $diagnostics['support'] );
		$this->assertSame( 'ok', $diagnostics['custom_flag'] );

		$this->assertArrayHasKey( 'settings', $export );
		$this->assertSame( 2, $export['schema_version'] );
		$this->assertIsArray( $export['settings'] );
	}

	public function test_import_payload_rejects_invalid_data_and_accepts_filtered_settings_payload(): void {
		$invalid = \OpenGrowthSolutions\OpenGrowthSEO\Support\DeveloperTools::import_payload( array(), true );
		$this->assertFalse( $invalid['ok'] );
		$this->assertStringContainsString( 'missing settings object', strtolower( (string) $invalid['message'] ) );

		add_filter(
			'ogs_seo_dev_tools_import_payload',
			static function ( array $payload ): array {
				$payload['settings']['title_separator'] = '-';
				return $payload;
			}
		);

		$result = \OpenGrowthSolutions\OpenGrowthSEO\Support\DeveloperTools::import_payload(
			array(
				'settings' => array(
					'title_separator' => '|',
					'mode'            => 'advanced',
				),
			),
			true
		);

		$this->assertTrue( $result['ok'] );
		$this->assertGreaterThan( 0, (int) $result['changed'] );
		$this->assertContains( 'title_separator', (array) $result['changed_keys'] );

		$settings = \OpenGrowthSolutions\OpenGrowthSEO\Support\Settings::get_all();
		$this->assertSame( '-', (string) $settings['title_separator'] );
		$this->assertSame( 'advanced', (string) $settings['mode'] );
	}

	public function test_debug_logs_are_redacted_and_can_be_cleared(): void {
		\OpenGrowthSolutions\OpenGrowthSEO\Support\Settings::update(
			array(
				'diagnostic_mode'    => 1,
				'debug_logs_enabled' => 1,
			)
		);

		\OpenGrowthSolutions\OpenGrowthSEO\Support\DeveloperTools::log(
			'info',
			'Log for test',
			array(
				'token'      => 'super-secret',
				'api_key'    => 'sensitive-key',
				'public_tag' => 'safe',
			)
		);

		$logs = \OpenGrowthSolutions\OpenGrowthSEO\Support\DeveloperTools::get_logs( 10 );
		$this->assertNotEmpty( $logs );
		$this->assertSame( '[redacted]', (string) $logs[0]['context']['token'] );
		$this->assertSame( '[redacted]', (string) $logs[0]['context']['api_key'] );
		$this->assertSame( 'safe', (string) $logs[0]['context']['public_tag'] );

		\OpenGrowthSolutions\OpenGrowthSEO\Support\DeveloperTools::clear_logs();
		$this->assertSame( array(), \OpenGrowthSolutions\OpenGrowthSEO\Support\DeveloperTools::get_logs( 10 ) );
	}

	public function test_diagnostics_support_report_includes_next_steps_and_resources(): void {
		$diagnostics = \OpenGrowthSolutions\OpenGrowthSEO\Support\DeveloperTools::diagnostics();
		$this->assertIsArray( $diagnostics['support'] );
		$this->assertArrayHasKey( 'next_steps', $diagnostics['support'] );
		$this->assertArrayHasKey( 'resources', $diagnostics['support'] );
		$this->assertArrayHasKey( 'module_statuses', $diagnostics['support'] );
		$this->assertIsArray( $diagnostics['support']['resources'] );
	}

	public function test_reset_settings_preserves_uninstall_choice_when_requested(): void {
		\OpenGrowthSolutions\OpenGrowthSEO\Support\Settings::update(
			array(
				'keep_data_on_uninstall' => 0,
				'mode'                   => 'advanced',
			)
		);
		$result = \OpenGrowthSolutions\OpenGrowthSEO\Support\DeveloperTools::reset_settings( true );
		$this->assertTrue( $result['ok'] );

		$settings = \OpenGrowthSolutions\OpenGrowthSEO\Support\Settings::get_all();
		$this->assertSame( 0, (int) $settings['keep_data_on_uninstall'] );
		$this->assertSame( 'simple', (string) $settings['mode'] );
	}
}
