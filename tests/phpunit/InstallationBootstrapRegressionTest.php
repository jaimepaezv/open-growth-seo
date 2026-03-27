<?php
use OpenGrowthSolutions\OpenGrowthSEO\Installation\Configurator;
use OpenGrowthSolutions\OpenGrowthSEO\Installation\Diagnostics;
use OpenGrowthSolutions\OpenGrowthSEO\Installation\Manager;
use OpenGrowthSolutions\OpenGrowthSEO\Installation\MigrationRunner;
use OpenGrowthSolutions\OpenGrowthSEO\Installation\SiteClassifier;
use OpenGrowthSolutions\OpenGrowthSEO\Core\Activator;
use OpenGrowthSolutions\OpenGrowthSEO\Support\DeveloperTools;
use OpenGrowthSolutions\OpenGrowthSEO\Support\Settings;
use PHPUnit\Framework\TestCase;

final class InstallationBootstrapRegressionTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['ogs_test_options'] = array();
		$GLOBALS['ogs_test_filters'] = array();
		$GLOBALS['ogs_test_actions'] = array();
		$GLOBALS['ogs_test_post_types'] = array( 'post' => 'post', 'page' => 'page', 'product' => 'product' );
		$GLOBALS['ogs_test_taxonomies'] = array( 'category' => 'category', 'post_tag' => 'post_tag', 'product_cat' => 'product_cat' );
		$GLOBALS['ogs_test_post_counts'] = array( 'post' => 8, 'page' => 4, 'product' => 12 );
		$GLOBALS['ogs_test_locale'] = 'en_US';
		$GLOBALS['ogs_test_theme'] = array( 'name' => 'Test Theme', 'template' => 'test-theme', 'version' => '1.0.0' );
		$GLOBALS['ogs_test_site_icon_url'] = 'https://example.com/icon.png';
		$GLOBALS['ogs_test_upload_dir'] = array( 'basedir' => sys_get_temp_dir() );
		$GLOBALS['ogs_test_get_posts_callback'] = static function ( array $args ): array {
			if ( isset( $args['post_type'] ) && 'page' === $args['post_type'] ) {
				return array(
					(object) array( 'post_name' => 'shop' ),
					(object) array( 'post_name' => 'cart' ),
					(object) array( 'post_name' => 'checkout' ),
					(object) array( 'post_name' => 'pricing' ),
				);
			}
			return array();
		};
	}

	public function test_site_classifier_detects_marketplace_and_ecommerce_signals(): void {
		$result = SiteClassifier::classify(
			array(
				'feature_plugins' => array(
					'woocommerce' => true,
					'marketplace' => true,
				),
				'public_post_types' => array( 'post', 'page', 'product' ),
				'public_taxonomies' => array( 'category', 'product_cat' ),
				'page_slugs' => array( 'shop', 'cart', 'checkout' ),
				'post_counts' => array( 'post' => 4, 'page' => 5, 'product' => 20 ),
				'route_flags' => array(
					'has_cart' => true,
					'has_checkout' => true,
					'has_account' => true,
				),
			)
		);

		$this->assertSame( 'marketplace', $result['primary'] );
		$this->assertContains( 'ecommerce', (array) $result['secondary'] );
		$this->assertGreaterThanOrEqual( 70, (int) $result['confidence'] );
		$this->assertSame( 'ecommerce', $result['wizard_site_type'] );
	}

	public function test_site_classifier_falls_back_to_general_when_signals_are_weak(): void {
		$result = SiteClassifier::classify(
			array(
				'feature_plugins' => array(),
				'public_post_types' => array( 'page' ),
				'public_taxonomies' => array(),
				'page_slugs' => array( 'home' ),
				'post_counts' => array( 'page' => 1 ),
				'route_flags' => array(),
			)
		);

		$this->assertSame( 'general', $result['primary'] );
		$this->assertLessThanOrEqual( 55, (int) $result['confidence'] );
	}

	public function test_configurator_apply_preserves_manual_settings_on_rerun(): void {
		$configurator = new Configurator();
		$current = Settings::sanitize(
			array(
				'schema_org_name' => 'Custom Brand',
			)
		);
		$raw = $current;
		$recommendations = array(
			'schema_org_name' => array(
				'value' => 'Detected Brand',
				'source' => 'detected',
				'reason' => 'Detected from site name',
			),
			'schema_org_logo' => array(
				'value' => 'https://example.com/icon.png',
				'source' => 'detected',
				'reason' => 'Detected from site icon',
			),
		);
		$previous = array(
			'applied_settings' => array(
				'schema_org_name' => array( 'value' => 'Open Growth Site' ),
			),
		);

		$result = $configurator->apply( $current, $raw, $recommendations, $previous, false );

		$this->assertArrayNotHasKey( 'schema_org_name', $result['applied'] );
		$this->assertSame( 'Custom Brand', $result['settings']['schema_org_name'] );
		$this->assertArrayHasKey( 'schema_org_logo', $result['applied'] );
	}

	public function test_manager_bootstrap_applies_detected_settings_for_fresh_install(): void {
		update_option( 'active_plugins', array( 'woocommerce/woocommerce.php' ), false );
		$manager = new Manager();
		$state   = $manager->bootstrap( 'activation', true, true );
		$settings = Settings::get_all();

		$this->assertSame( 2, (int) $state['schema_version'] );
		$this->assertSame( 'ready', (string) $state['status'] );
		$this->assertSame( 1, (int) $state['fresh_install'] );
		$this->assertSame( '1.0.0-test', (string) $state['plugin_version'] );
		$this->assertContains( 'product', (array) $settings['sitemap_post_types'] );
		$this->assertSame( 'https://example.com/', (string) $settings['gsc_property_url'] );
		$this->assertSame( 'https://example.com/', (string) $settings['bing_site_url'] );
		$this->assertSame( 'https://example.com/icon.png', (string) $settings['schema_org_logo'] );
	}

	public function test_activator_creates_settings_and_installation_state_on_clean_install(): void {
		Activator::activate();

		$this->assertIsArray( get_option( 'ogs_seo_settings', array() ) );
		$this->assertIsArray( get_option( Manager::STATE_OPTION, array() ) );
		$this->assertSame( 2, (int) ( get_option( Manager::STATE_OPTION, array() )['schema_version'] ?? 0 ) );
		$this->assertIsArray( get_option( 'ogs_seo_activation_state', array() ) );
	}

	public function test_manager_handles_missing_dependency_findings_without_failing_bootstrap(): void {
		$diagnostics = new class() extends Diagnostics {
			public function run(): array {
				return array(
					'runtime' => array(
						'blog_public' => true,
					),
					'extensions' => array(),
					'permissions' => array(),
					'signals' => array(
						'site_name' => 'Open Growth Site',
						'languages' => array( 'en_US' ),
						'public_post_types' => array( 'post', 'page' ),
						'post_counts' => array( 'post' => 2, 'page' => 2 ),
					),
					'classification' => array(
						'primary' => 'general',
						'secondary' => array(),
						'confidence' => 30,
					),
					'compatibility' => array(
						'active_seo_conflicts' => array(),
					),
					'findings' => array(
						array(
							'severity' => 'critical',
							'code' => 'runtime_php',
							'message' => 'PHP version is below the plugin requirement.',
						),
					),
				);
			}
		};

		$manager = new Manager( $diagnostics, new Configurator() );
		$state   = $manager->bootstrap( 'activation', true, true );

		$this->assertSame( 'needs_attention', (string) $state['status'] );
		$this->assertNotEmpty( $state['diagnostics']['findings'] );
		$this->assertArrayHasKey( 'recommendations', $state );
	}

	public function test_manager_repairs_corrupt_settings_and_tracks_repair_actions(): void {
		update_option( 'ogs_seo_settings', 'corrupt', false );

		$manager = new Manager();
		$state   = $manager->bootstrap( 'repair', false, false );

		$this->assertContains( 'settings_option_repaired', (array) ( $state['repair_actions'] ?? array() ) );
		$this->assertIsArray( get_option( 'ogs_seo_settings', array() ) );
	}

	public function test_ensure_state_rebuilds_legacy_installation_state(): void {
		update_option(
			Manager::STATE_OPTION,
			array(
				'schema_version' => 1,
				'status'         => 'ready',
			),
			false
		);

		$manager = new Manager();
		$manager->ensure_state();
		$state = $manager->get_state();

		$this->assertSame( 2, (int) ( $state['schema_version'] ?? 0 ) );
		$this->assertNotEmpty( $state['diagnostics'] );
		$this->assertArrayHasKey( 'plugin_version', $state );
	}

	public function test_migration_runner_backfills_v2_fields_for_legacy_state(): void {
		$migration = MigrationRunner::migrate_state(
			array(
				'schema_version' => 1,
				'status'         => 'ready',
				'classification' => array(
					'primary'    => 'general',
					'secondary'  => array(),
					'confidence' => 55,
				),
				'diagnostics'    => array(),
				'pending'        => array(),
				'recommendations' => array(),
			),
			array(
				'wizard_completed' => 0,
			),
			'1.0.0-test',
			2
		);

		$this->assertTrue( $migration['changed'] );
		$this->assertSame( 2, (int) $migration['state']['schema_version'] );
		$this->assertSame( '1.0.0-test', (string) $migration['state']['plugin_version'] );
		$this->assertSame( 0, (int) $migration['state']['setup_complete'] );
		$this->assertSame( 1, (int) $migration['state']['wizard_recommended'] );
		$this->assertSame( array(), $migration['state']['repair_actions'] );
	}

	public function test_rebuild_state_recreates_installation_state_without_losing_structure(): void {
		Settings::update(
			Settings::sanitize(
				array(
					'wizard_completed' => 0,
				)
			)
		);

		$manager = new Manager();
		$state   = $manager->rebuild_state( 'admin_rebuild', false );

		$this->assertSame( 'admin_rebuild', (string) $state['source'] );
		$this->assertSame( 2, (int) $state['schema_version'] );
		$this->assertArrayHasKey( 'diagnostics', $state );
		$this->assertTrue( ! empty( $state['wizard_recommended'] ) );
		$this->assertArrayHasKey( 'events', $state );
	}

	public function test_installation_history_tracks_rebuilds_and_repairs(): void {
		update_option( 'ogs_seo_settings', 'corrupt', false );

		$manager = new Manager();
		$manager->bootstrap( 'repair', false, false );
		$manager->rebuild_state( 'admin_rebuild', false );

		$history = $manager->history( 10 );
		$summary = $manager->summary();

		$this->assertNotEmpty( $history );
		$this->assertGreaterThanOrEqual( 1, (int) $summary['repair_runs'] );
		$this->assertGreaterThanOrEqual( 1, (int) $summary['rebuilds'] );
		$this->assertGreaterThan( 0, (int) $summary['last_repair'] );
		$this->assertGreaterThan( 0, (int) $summary['last_rebuild'] );
	}

	public function test_developer_tools_diagnostics_expose_installation_history_metrics(): void {
		$manager = new Manager();
		$manager->rebuild_state( 'admin_rebuild', false );

		$diagnostics = DeveloperTools::diagnostics();

		$this->assertArrayHasKey( 'installation_rebuilds', $diagnostics );
		$this->assertArrayHasKey( 'installation_repair_runs', $diagnostics );
		$this->assertArrayHasKey( 'installation_last_rebuild', $diagnostics );
		$this->assertArrayHasKey( 'installation_last_repair', $diagnostics );
	}
}
