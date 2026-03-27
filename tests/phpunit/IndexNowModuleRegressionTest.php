<?php
use PHPUnit\Framework\TestCase;

final class IndexNowModuleRegressionTest extends TestCase {
	public function test_indexnow_class_and_methods_exist(): void {
		$this->assertTrue( class_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Integrations\\IndexNow' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Integrations\\IndexNow', 'process_queue' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Integrations\\IndexNow', 'generate_key' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Integrations\\IndexNow', 'verify_key_accessible' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Integrations\\IndexNow', 'inspect_status' ) );
	}

	public function test_queue_class_supports_batch_retry_and_inspection(): void {
		$this->assertTrue( class_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Jobs\\Queue' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Jobs\\Queue', 'enqueue' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Jobs\\Queue', 'next_batch' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Jobs\\Queue', 'claim_batch' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Jobs\\Queue', 'mark_failure' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Jobs\\Queue', 'acquire_lock' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Jobs\\Queue', 'inspect' ) );
	}

	public function test_rest_routes_expose_indexnow_operational_actions(): void {
		$this->assertTrue( class_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\REST\\Routes' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\REST\\Routes', 'indexnow_process' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\REST\\Routes', 'indexnow_verify_key' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\REST\\Routes', 'indexnow_generate_key' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\REST\\Routes', 'jobs_status' ) );
	}

	public function test_indexnow_audit_flags_stale_queue_lock(): void {
		\OpenGrowthSolutions\OpenGrowthSEO\Support\Settings::update(
			array_replace(
				\OpenGrowthSolutions\OpenGrowthSEO\Support\Settings::get_all(),
				array(
					'indexnow_enabled' => 1,
					'indexnow_key'     => 'abc123xyzabc123xyz',
				)
			)
		);
		update_option(
			'ogs_seo_indexnow_queue_state',
			array(
				'lock_token'      => 'stale-lock',
				'lock_expires_at' => time() - 60,
			),
			false
		);
		$issue = ( new \OpenGrowthSolutions\OpenGrowthSEO\Integrations\IndexNow() )->audit_health();
		$this->assertSame( 'important', (string) ( $issue['severity'] ?? '' ) );
		$this->assertStringContainsString( 'stale', strtolower( (string) ( $issue['title'] ?? '' ) ) );
	}
}
