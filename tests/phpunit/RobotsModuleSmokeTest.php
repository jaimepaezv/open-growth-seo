<?php
use PHPUnit\Framework\TestCase;

final class RobotsModuleSmokeTest extends TestCase {
	public function test_robots_class_exists(): void {
		$this->assertTrue( class_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\SEO\\Robots' ) );
	}

	public function test_managed_content_includes_gptbot_oai_and_sitemap(): void {
		$content = \OpenGrowthSolutions\OpenGrowthSEO\SEO\Robots::build_content(
			array(
				'robots_mode'          => 'managed',
				'robots_global_policy' => 'allow',
				'bots_gptbot'          => 'disallow',
				'bots_oai_searchbot'   => 'allow',
				'robots_custom'        => '',
			),
			true
		);

		$this->assertStringContainsString( 'User-agent: GPTBot', $content );
		$this->assertStringContainsString( 'User-agent: OAI-SearchBot', $content );
		$this->assertStringContainsString( 'Disallow: /', $content );
		$this->assertStringContainsString( 'Allow: /', $content );
		$this->assertStringContainsString( 'Sitemap: https://example.com/ogs-sitemap.xml', $content );
	}

	public function test_validate_syntax_rejects_invalid_lines_and_orphan_directives(): void {
		$invalid = "User-agent: *\nBadDirective: /\nAllow: /";
		$errors  = \OpenGrowthSolutions\OpenGrowthSEO\SEO\Robots::validate_syntax( $invalid );
		$this->assertNotEmpty( $errors );
	}

	public function test_validate_for_save_blocks_public_sitewide_disallow(): void {
		$result = \OpenGrowthSolutions\OpenGrowthSEO\SEO\Robots::validate_for_save(
			array(
				'robots_mode'          => 'expert',
				'robots_expert_rules'  => "User-agent: *\nDisallow: /",
				'robots_global_policy' => 'allow',
				'bots_gptbot'          => 'allow',
				'bots_oai_searchbot'   => 'allow',
				'robots_custom'        => '',
			),
			true
		);
		$this->assertNotEmpty( $result['errors'] );
	}

	public function test_preview_for_bot_prefers_exact_group_with_generic_fallback(): void {
		$content = "User-agent: *\nAllow: /\nUser-agent: GPTBot\nDisallow: /\n";
		$exact   = \OpenGrowthSolutions\OpenGrowthSEO\SEO\Robots::preview_for_bot( $content, 'GPTBot' );
		$generic = \OpenGrowthSolutions\OpenGrowthSEO\SEO\Robots::preview_for_bot( $content, 'UnknownBot' );
		$this->assertContains( 'Disallow: /', $exact );
		$this->assertContains( 'Allow: /', $generic );
	}
}
