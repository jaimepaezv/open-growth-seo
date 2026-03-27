<?php
use PHPUnit\Framework\TestCase;

final class SetupWizardSmokeTest extends TestCase {
	public function test_setup_wizard_class_exists(): void {
		$this->assertTrue( class_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Admin\\SetupWizard' ) );
	}

	public function test_setup_wizard_methods_exist(): void {
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Admin\\SetupWizard', 'render' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Admin\\SetupWizard', 'handle_actions' ) );
	}

	public function test_sanitize_choices_enforces_allowed_values_and_safe_mode_with_conflicts(): void {
		$wizard     = new \OpenGrowthSolutions\OpenGrowthSEO\Admin\SetupWizard();
		$reflection = new \ReflectionClass( $wizard );
		$method     = $reflection->getMethod( 'sanitize_choices' );
		$method->setAccessible( true );

		$context = array(
			'site_type'    => 'blog',
			'seo_plugins'  => array( 'Yoast SEO' ),
		);

		$result = $method->invoke(
			$wizard,
			array(
				'mode'                   => 'invalid',
				'site_type'              => 'unknown',
				'visibility'             => 'private',
				'safe_mode_seo_conflict' => '0',
				'confirm_private'        => '1',
			),
			$context
		);

		$this->assertSame( 'simple', $result['mode'] );
		$this->assertSame( 'blog', $result['site_type'] );
		$this->assertSame( 'private', $result['visibility'] );
		$this->assertSame( '1', $result['safe_mode_seo_conflict'] );
		$this->assertSame( '1', $result['confirm_private'] );
	}

	public function test_validate_step_blocks_dangerous_choices(): void {
		$wizard     = new \OpenGrowthSolutions\OpenGrowthSEO\Admin\SetupWizard();
		$reflection = new \ReflectionClass( $wizard );
		$method     = $reflection->getMethod( 'validate_step' );
		$method->setAccessible( true );

		$context = array(
			'seo_plugins' => array( 'Rank Math' ),
		);
		$choices = array(
			'visibility'             => 'private',
			'confirm_private'        => '0',
			'safe_mode_seo_conflict' => '0',
		);

		$errors = $method->invoke( $wizard, 2, $choices, $context );
		$this->assertNotEmpty( $errors );
		$this->assertCount( 2, $errors );
	}

	public function test_validate_step_blocks_ecommerce_without_woocommerce(): void {
		$wizard     = new \OpenGrowthSolutions\OpenGrowthSEO\Admin\SetupWizard();
		$reflection = new \ReflectionClass( $wizard );
		$method     = $reflection->getMethod( 'validate_step' );
		$method->setAccessible( true );

		$context = array(
			'woo'         => false,
			'seo_plugins' => array(),
		);
		$choices = array(
			'site_type'              => 'ecommerce',
			'visibility'             => 'keep',
			'confirm_private'        => '0',
			'safe_mode_seo_conflict' => '0',
		);

		$errors = $method->invoke( $wizard, 2, $choices, $context );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'WooCommerce', $errors[0] );
	}

	public function test_wizard_apply_source_wires_seo_masters_plus_to_advanced_mode(): void {
		$source = file_get_contents( __DIR__ . '/../../includes/Admin/SetupWizard.php' );
		$this->assertIsString( $source );
		$this->assertStringContainsString( 'seo_masters_plus_enabled', $source );
		$this->assertStringContainsString( 'SEO MASTERS PLUS', $source );
	}
}
