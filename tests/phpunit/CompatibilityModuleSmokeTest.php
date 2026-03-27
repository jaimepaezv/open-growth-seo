<?php
use PHPUnit\Framework\TestCase;

final class CompatibilityModuleSmokeTest extends TestCase {
	public function test_compatibility_classes_exist(): void {
		$this->assertTrue( class_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Compatibility\\ConflictManager' ) );
		$this->assertTrue( class_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Compatibility\\Importer' ) );
	}

	public function test_importer_exposes_required_methods(): void {
		$reflection = new ReflectionClass( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Compatibility\\Importer' );
		$this->assertTrue( $reflection->hasMethod( 'detect' ) );
		$this->assertTrue( $reflection->hasMethod( 'dry_run' ) );
		$this->assertTrue( $reflection->hasMethod( 'run_import' ) );
		$this->assertTrue( $reflection->hasMethod( 'rollback_last_import' ) );
		$this->assertTrue( $reflection->hasMethod( 'get_state' ) );
		$this->assertTrue( $reflection->hasMethod( 'has_rollback_snapshot' ) );
	}
}
