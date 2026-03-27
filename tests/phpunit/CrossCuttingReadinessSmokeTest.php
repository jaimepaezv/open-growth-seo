<?php
use PHPUnit\Framework\TestCase;

final class CrossCuttingReadinessSmokeTest extends TestCase {
	public function test_privacy_and_devtools_classes_exist(): void {
		$this->assertTrue( class_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Support\\Privacy' ) );
		$this->assertTrue( class_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Support\\DeveloperTools' ) );
	}

	public function test_rest_and_cli_classes_exist(): void {
		$this->assertTrue( class_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\REST\\Routes' ) );
		$this->assertTrue( class_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\CLI\\Commands' ) );
	}
}
