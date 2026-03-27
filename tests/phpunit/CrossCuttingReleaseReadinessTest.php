<?php
use PHPUnit\Framework\TestCase;

final class CrossCuttingReleaseReadinessTest extends TestCase {
	public function test_rest_routes_define_permission_callback_for_each_route_registration(): void {
		$rest_dir = dirname( __DIR__, 2 ) . '/includes/REST';
		$files    = glob( $rest_dir . '/*.php' );
		$this->assertIsArray( $files );

		$content = '';
		foreach ( $files as $file ) {
			$content .= (string) file_get_contents( $file );
		}
		$this->assertNotSame( '', $content, 'REST PHP files must be readable.' );

		$routes_count     = preg_match_all( '/register_rest_route\s*\(/', $content );
		$permission_count = preg_match_all( '/[\'"]permission_callback[\'"]\s*=>/', $content );

		$this->assertGreaterThan( 0, $routes_count, 'At least one REST route must exist.' );
		$this->assertSame(
			$routes_count,
			$permission_count,
			'Every register_rest_route() call must define permission_callback.'
		);
	}

	public function test_deactivate_clears_background_schedules(): void {
		$activator_file = dirname( __DIR__, 2 ) . '/includes/Core/Activator.php';
		$content        = (string) file_get_contents( $activator_file );

		$this->assertStringContainsString( "wp_clear_scheduled_hook( 'ogs_seo_run_audit' )", $content );
		$this->assertStringContainsString( "wp_clear_scheduled_hook( 'ogs_seo_process_indexnow_queue' )", $content );
	}

	public function test_uninstall_handles_multisite_cleanup_and_keep_data_flag(): void {
		$uninstall_file = dirname( __DIR__, 2 ) . '/uninstall.php';
		$content        = (string) file_get_contents( $uninstall_file );

		$this->assertStringContainsString( 'is_multisite()', $content );
		$this->assertStringContainsString( 'get_sites(', $content );
		$this->assertStringContainsString( 'switch_to_blog(', $content );
		$this->assertStringContainsString( 'restore_current_blog()', $content );
		$this->assertStringContainsString( 'keep_data_on_uninstall', $content );
	}

	public function test_e2e_scripts_include_deterministic_classic_provisioning_path(): void {
		$package_file = dirname( __DIR__, 2 ) . '/package.json';
		$package_json = (string) file_get_contents( $package_file );
		$this->assertNotSame( '', $package_json );
		$decoded = json_decode( $package_json, true );
		$this->assertIsArray( $decoded );
		$scripts = isset( $decoded['scripts'] ) && is_array( $decoded['scripts'] ) ? $decoded['scripts'] : array();

		$this->assertArrayHasKey( 'test:e2e:editor-classic:provision', $scripts );
		$this->assertArrayHasKey( 'test:e2e:editor-classic:strict', $scripts );
		$this->assertArrayHasKey( 'test:e2e:editor-classic:ci', $scripts );
	}
}
