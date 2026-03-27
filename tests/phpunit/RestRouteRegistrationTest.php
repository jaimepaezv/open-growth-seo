<?php
use OpenGrowthSolutions\OpenGrowthSEO\REST\Routes;
use PHPUnit\Framework\TestCase;

final class RestRouteRegistrationTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['ogs_test_rest_routes'] = array();
		$GLOBALS['ogs_test_current_user_caps'] = array(
			'manage_options' => true,
			'edit_posts'     => true,
			'edit_post'      => true,
		);
	}

	public function test_routes_register_domain_registrars_and_callbacks(): void {
		$routes = new Routes();
		$routes->routes();

		$registered = $GLOBALS['ogs_test_rest_routes'] ?? array();
		$this->assertNotEmpty( $registered );

		$paths = array_map(
			static function ( array $route ): string {
				return (string) $route['route'];
			},
			$registered
		);

		$this->assertContains( '/content-meta/(?P<post_id>\d+)', $paths );
		$this->assertContains( '/audit/status', $paths );
		$this->assertContains( '/installation/telemetry', $paths );
		$this->assertContains( '/geo/telemetry', $paths );
		$this->assertContains( '/sfo/analyze', $paths );
		$this->assertContains( '/sfo/telemetry', $paths );
		$this->assertContains( '/dev-tools/diagnostics', $paths );
		$this->assertContains( '/jobs/status', $paths );
		$this->assertContains( '/jobs/indexnow/process', $paths );
	}

	public function test_permission_helpers_deny_access_without_caps(): void {
		$GLOBALS['ogs_test_current_user_caps'] = array(
			'manage_options' => false,
			'edit_posts'     => false,
			'edit_post'      => false,
		);

		$routes = new Routes();

		$this->assertFalse( $routes->can_manage_options() );
		$this->assertFalse( $routes->can_edit_posts() );
		$this->assertFalse( $routes->can_edit_content_meta( new \WP_REST_Request( array( 'post_id' => 11 ) ) ) );
	}
}
