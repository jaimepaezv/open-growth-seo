<?php
use OpenGrowthSolutions\OpenGrowthSEO\REST\Routes;
use PHPUnit\Framework\TestCase;

final class RestRoutesRegressionTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['ogs_test_options'] = array();
		$GLOBALS['ogs_test_current_user_caps'] = array(
			'manage_options' => true,
			'edit_posts'     => true,
			'edit_post'      => true,
		);
	}

	public function test_content_meta_save_rejects_non_object_payloads(): void {
		$request  = new \WP_REST_Request(
			array(
				'post_id' => 11,
				'meta'    => 'invalid',
			)
		);
		$response = ( new Routes() )->content_meta_save( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertFalse( $data['ok'] );
		$this->assertSame( 'ogs_seo_invalid_meta_payload', $data['code'] );
	}

	public function test_integration_test_requires_integration_slug(): void {
		$request  = new \WP_REST_Request( array( 'integration' => '' ) );
		$response = ( new Routes() )->integration_test( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertFalse( $data['ok'] );
		$this->assertSame( 'ogs_seo_missing_integration', $data['code'] );
	}

	public function test_aeo_analyze_returns_not_found_when_no_content_exists(): void {
		$GLOBALS['ogs_test_get_posts_callback'] = static function (): array {
			return array();
		};

		$request  = new \WP_REST_Request( array( 'post_id' => 0 ) );
		$response = ( new Routes() )->aeo_analyze( $request );
		$data     = $response->get_data();

		$this->assertSame( 404, $response->get_status() );
		$this->assertFalse( $data['ok'] );
		$this->assertSame( 'ogs_seo_no_aeo_post', $data['code'] );
	}

	public function test_jobs_indexnow_process_returns_disabled_status_when_feature_is_off(): void {
		$request  = new \WP_REST_Request();
		$response = ( new Routes() )->jobs_indexnow_process();
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertFalse( $data['ok'] );
		$this->assertSame( 'disabled', $data['status'] );
	}

	public function test_audit_status_exposes_summary_and_group_counts(): void {
		$GLOBALS['ogs_test_options']['ogs_seo_audit_issues'] = array(
			array(
				'id' => 'i1',
				'severity' => 'critical',
				'title' => 'Critical technical issue',
				'category' => 'technical',
				'quick_win' => false,
			),
			array(
				'id' => 'i2',
				'severity' => 'minor',
				'title' => 'Meta description is very short',
				'category' => 'content',
				'quick_win' => true,
			),
		);

		$response = ( new Routes() )->audit_status();
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 2, (int) ( $data['summary']['total'] ?? 0 ) );
		$this->assertSame( 1, (int) ( $data['groups']['technical'] ?? 0 ) );
		$this->assertSame( 1, (int) ( $data['summary']['quick_wins'] ?? 0 ) );
	}
}
