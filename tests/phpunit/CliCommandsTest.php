<?php
use OpenGrowthSolutions\OpenGrowthSEO\CLI\Commands;
use PHPUnit\Framework\TestCase;

final class CliCommandsTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		WP_CLI::reset();
		$GLOBALS['ogs_test_options'] = array();
	}

	public function test_register_adds_command_metadata_with_synopsis(): void {
		$commands = new Commands();
		$commands->register();

		$this->assertArrayHasKey( 'ogs-seo audit status', WP_CLI::$added_commands );
		$this->assertArrayHasKey( 'ogs-seo tools logs', WP_CLI::$added_commands );
		$this->assertSame( 'Show audit status and issue counts.', WP_CLI::$added_commands['ogs-seo audit status']['args']['shortdesc'] );
		$this->assertNotEmpty( WP_CLI::$added_commands['ogs-seo audit status']['args']['synopsis'] );
		$this->assertNotEmpty( WP_CLI::$added_commands['ogs-seo audit run']['args']['longdesc'] );
	}

	public function test_integrations_disconnect_requires_yes_flag(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Use --yes to confirm disconnecting an integration.' );
		( new Commands() )->integrations_disconnect( array(), array( 'integration' => 'google_search_console' ) );
	}

	public function test_compatibility_rollback_requires_yes_flag(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Use --yes to confirm compatibility rollback.' );
		( new Commands() )->compatibility_rollback( array(), array() );
	}

	public function test_indexnow_generate_key_requires_yes_when_key_exists(): void {
		$GLOBALS['ogs_test_options']['ogs_seo_settings'] = array(
			'indexnow_key' => 'existingkey1234',
		);
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Use --yes to replace the existing IndexNow key.' );
		( new Commands() )->indexnow_generate_key( array(), array() );
	}

	public function test_tools_logs_clear_requires_yes_flag(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Use --yes to confirm clearing developer logs.' );
		( new Commands() )->tools_logs( array(), array( 'clear' => true ) );
	}

	public function test_format_arg_rejects_invalid_formats(): void {
		$commands   = new Commands();
		$reflection = new ReflectionClass( $commands );
		$method     = $reflection->getMethod( 'format_arg' );
		$method->setAccessible( true );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Invalid --format value. Allowed: text, json.' );
		$method->invoke( $commands, array( 'format' => 'table' ) );
	}

	public function test_audit_status_can_emit_json(): void {
		$commands = new Commands();
		$commands->audit_status( array(), array( 'format' => 'json' ) );

		$this->assertNotEmpty( WP_CLI::$lines );
		$this->assertStringContainsString( 'active_issues', WP_CLI::$lines[0] );
		$this->assertStringContainsString( 'ignored_issues', WP_CLI::$lines[0] );
	}
}
