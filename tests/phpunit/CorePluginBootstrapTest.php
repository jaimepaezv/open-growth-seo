<?php
declare(strict_types=1);

use OpenGrowthSolutions\OpenGrowthSEO\Core\Plugin;
use PHPUnit\Framework\TestCase;

final class CorePluginBootstrapTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['ogs_test_actions']   = array();
		$GLOBALS['ogs_test_filters']   = array();
		$GLOBALS['ogs_test_options']   = array();
		$GLOBALS['ogs_test_is_admin']  = false;
		$GLOBALS['ogs_test_service_register_count'] = 0;
	}

	public function test_plugin_init_is_idempotent_and_does_not_duplicate_hooks(): void {
		$plugin = new Plugin();
		$plugin->init();
		$first_actions = isset( $GLOBALS['ogs_test_actions']['wp_head'] ) && is_array( $GLOBALS['ogs_test_actions']['wp_head'] )
			? count( $GLOBALS['ogs_test_actions']['wp_head'] )
			: 0;

		$plugin->init();
		$second_actions = isset( $GLOBALS['ogs_test_actions']['wp_head'] ) && is_array( $GLOBALS['ogs_test_actions']['wp_head'] )
			? count( $GLOBALS['ogs_test_actions']['wp_head'] )
			: 0;

		$this->assertGreaterThan( 0, $first_actions );
		$this->assertSame( $first_actions, $second_actions );
		$this->assertNotNull( $plugin->get_service( 'frontend_meta' ) );
	}

	public function test_plugin_skips_admin_only_services_outside_admin_context(): void {
		$GLOBALS['ogs_test_is_admin'] = false;
		$plugin = new Plugin();
		$plugin->init();

		$this->assertNull( $plugin->get_service( 'admin' ) );
		$this->assertNull( $plugin->get_service( 'editor' ) );
		$this->assertNull( $plugin->get_service( 'setup_wizard' ) );
		$this->assertNotNull( $plugin->get_service( 'schema_manager' ) );
		$this->assertNotNull( $plugin->get_service( 'rest_routes' ) );
	}

	public function test_plugin_registers_admin_services_in_admin_context(): void {
		$GLOBALS['ogs_test_is_admin'] = true;
		$plugin = new Plugin();
		$plugin->init();

		$this->assertNotNull( $plugin->get_service( 'admin' ) );
		$this->assertNotNull( $plugin->get_service( 'editor' ) );
		$this->assertNotNull( $plugin->get_service( 'setup_wizard' ) );
		$this->assertNotNull( $plugin->get_service( 'installation_manager' ) );
	}

	public function test_plugin_accepts_filtered_service_definitions(): void {
		add_filter(
			'ogs_seo_core_services',
			static function ( array $definitions ): array {
				$definitions['test_service'] = array(
					'instance' => new class() {
						public function register(): void {
							$GLOBALS['ogs_test_service_register_count'] = (int) ( $GLOBALS['ogs_test_service_register_count'] ?? 0 ) + 1;
						}
					},
				);
				return $definitions;
			}
		);

		$plugin = new Plugin();
		$plugin->init();

		$this->assertSame( 1, (int) $GLOBALS['ogs_test_service_register_count'] );
		$this->assertNotNull( $plugin->get_service( 'test_service' ) );
	}
}
