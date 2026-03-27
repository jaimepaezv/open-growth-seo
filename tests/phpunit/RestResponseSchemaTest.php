<?php
use OpenGrowthSolutions\OpenGrowthSEO\REST\ResponseSchemas;
use OpenGrowthSolutions\OpenGrowthSEO\REST\Routes;
use PHPUnit\Framework\TestCase;

final class RestResponseSchemaTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['ogs_test_options'] = array();
		$GLOBALS['ogs_test_current_user_caps'] = array(
			'manage_options' => true,
			'edit_posts'     => true,
			'edit_post'      => true,
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['ogs_test_get_posts_callback'] );
		unset( $GLOBALS['ogs_test_post_titles'], $GLOBALS['ogs_test_permalinks'], $GLOBALS['ogs_test_post_fields'], $GLOBALS['ogs_test_post_meta'] );
		parent::tearDown();
	}

	public function test_sitemaps_status_matches_documented_schema(): void {
		$data = ( new Routes() )->sitemaps_status()->get_data();
		$this->assertMatchesSchema( $data, ResponseSchemas::definitions()['sitemaps_status'] );
	}

	public function test_dev_tools_diagnostics_matches_documented_schema(): void {
		$data = ( new Routes() )->dev_tools_diagnostics( new \WP_REST_Request() )->get_data();
		$this->assertMatchesSchema( $data, ResponseSchemas::definitions()['dev_tools_diagnostics'] );
	}

	public function test_installation_telemetry_matches_documented_schema(): void {
		$data = ( new Routes() )->installation_telemetry( new \WP_REST_Request() )->get_data();
		$this->assertMatchesSchema( $data, ResponseSchemas::definitions()['installation_telemetry'] );
	}

	public function test_search_preview_matches_documented_schema(): void {
		$request = new \WP_REST_Request(
			array(
				'sample_title'   => 'Example Title',
				'sample_excerpt' => 'Example excerpt',
			)
		);
		$data = ( new Routes() )->search_appearance_preview( $request )->get_data();
		$this->assertMatchesSchema( $data, ResponseSchemas::definitions()['search_appearance_preview'] );
	}

	public function test_geo_telemetry_matches_documented_schema(): void {
		$data = ( new Routes() )->geo_telemetry( new \WP_REST_Request() )->get_data();
		$this->assertMatchesSchema( $data, ResponseSchemas::definitions()['geo_telemetry'] );
	}

	public function test_sfo_analyze_matches_documented_schema(): void {
		$GLOBALS['ogs_test_post_titles'][ 601 ] = 'SFO sample';
		$GLOBALS['ogs_test_permalinks'][ 601 ] = 'https://example.com/sfo-sample';
		$GLOBALS['ogs_test_post_fields'][ 601 ] = array(
			'post_content' => '<p>Technical SEO is the practice of improving crawlability and indexability with measurable controls.</p><h2>Scope</h2><p>Requirement: XML sitemap coverage.</p>',
		);
		$request = new \WP_REST_Request(
			array(
				'post_id' => 601,
			)
		);
		$data = ( new Routes() )->sfo_analyze( $request )->get_data();
		$this->assertMatchesSchema( $data, ResponseSchemas::definitions()['sfo_analyze'] );
	}

	public function test_sfo_telemetry_matches_documented_schema(): void {
		$GLOBALS['ogs_test_get_posts_callback'] = static function (): array {
			return array( 601 );
		};
		$data = ( new Routes() )->sfo_telemetry( new \WP_REST_Request() )->get_data();
		$this->assertMatchesSchema( $data, ResponseSchemas::definitions()['sfo_telemetry'] );
	}

	public function test_jobs_status_matches_documented_schema(): void {
		$data = ( new Routes() )->jobs_status()->get_data();
		$this->assertMatchesSchema( $data, ResponseSchemas::definitions()['jobs_status'] );
	}

	private function assertMatchesSchema( array $data, array $schema ): void {
		foreach ( $schema as $key => $type ) {
			$this->assertArrayHasKey( $key, $data );
			switch ( $type ) {
				case 'boolean':
					$this->assertIsBool( $data[ $key ] );
					break;
				case 'integer':
					$this->assertIsInt( $data[ $key ] );
					break;
				case 'string':
					$this->assertIsString( $data[ $key ] );
					break;
				case 'array':
					$this->assertIsArray( $data[ $key ] );
					break;
			}
		}
	}
}
