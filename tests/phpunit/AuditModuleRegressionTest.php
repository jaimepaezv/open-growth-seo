<?php
use PHPUnit\Framework\TestCase;

final class AuditModuleRegressionTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['ogs_test_options'] = array();
		$GLOBALS['ogs_test_post_meta'] = array();
		$GLOBALS['ogs_test_post_fields'] = array();
		$GLOBALS['ogs_test_post_titles'] = array();
		$GLOBALS['ogs_test_post_types_map'] = array();
	}

	public function test_audit_manager_has_incremental_and_ignore_api(): void {
		$this->assertTrue( class_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Audit\\AuditManager' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Audit\\AuditManager', 'run_full' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Audit\\AuditManager', 'run_incremental' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Audit\\AuditManager', 'get_scan_state' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Audit\\AuditManager', 'ignore_issue' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Audit\\AuditManager', 'unignore_issue' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Audit\\AuditManager', 'get_ignored_map' ) );
	}

	public function test_rest_routes_include_audit_status_and_ignore_actions(): void {
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\REST\\Routes', 'audit_status' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\REST\\Routes', 'audit_ignore' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\REST\\Routes', 'audit_unignore' ) );
	}

	public function test_audit_source_includes_cornerstone_and_orphan_remediation_signals(): void {
		$source = file_get_contents( __DIR__ . '/../../includes/Audit/AuditManager.php' );
		$this->assertIsString( $source );
		$this->assertStringContainsString( 'Cornerstone content has weak internal support', $source );
		$this->assertStringContainsString( 'Cornerstone content appears stale', $source );
		$this->assertStringContainsString( 'suggested_source_ids', $source );
		$this->assertStringContainsString( 'LinkGraph::orphan_assessment', $source );
		$this->assertStringContainsString( 'Cornerstone queue needs review', $source );
	}

	public function test_link_graph_service_is_available_for_orphan_and_cornerstone_scoring(): void {
		$this->assertTrue( class_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\AEO\\LinkGraph' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\AEO\\LinkGraph', 'orphan_assessment' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\AEO\\LinkGraph', 'stale_cornerstone_queue' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\AEO\\LinkGraph', 'remediation_queue' ) );
	}

	public function test_cli_commands_include_audit_subcommands(): void {
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\CLI\\Commands', 'audit_status' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\CLI\\Commands', 'audit_run_incremental' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\CLI\\Commands', 'audit_ignore' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\CLI\\Commands', 'audit_unignore' ) );
	}

	public function test_normalize_issues_adds_category_confidence_quick_win_and_impact_score(): void {
		$audit  = new \OpenGrowthSolutions\OpenGrowthSEO\Audit\AuditManager();
		$method = new ReflectionMethod( $audit, 'normalize_issues' );
		$method->setAccessible( true );

		$normalized = $method->invoke(
			$audit,
			array(
				array(
					'severity' => 'minor',
					'title' => 'Meta description is very short',
					'explanation' => 'Short meta',
					'recommendation' => 'Expand it',
					'trace' => array(
						'post_id' => '12',
						'meta_length' => '45',
					),
					'source' => 'content',
				),
			)
		);

		$this->assertIsArray( $normalized );
		$this->assertSame( 'content', (string) ( $normalized[0]['category'] ?? '' ) );
		$this->assertSame( 'high', (string) ( $normalized[0]['confidence'] ?? '' ) );
		$this->assertTrue( (bool) ( $normalized[0]['quick_win'] ?? false ) );
		$this->assertGreaterThan( 0, (int) ( $normalized[0]['impact_score'] ?? 0 ) );
	}

	public function test_scan_posts_batch_adds_length_heading_and_image_alt_issues(): void {
		$audit = new \OpenGrowthSolutions\OpenGrowthSEO\Audit\AuditManager();
		$method = new ReflectionMethod( $audit, 'scan_posts_batch' );
		$method->setAccessible( true );

		$post_id = 101;
		$GLOBALS['ogs_test_post_titles'][ $post_id ] = 'Short title';
		$GLOBALS['ogs_test_post_types_map'][ $post_id ] = 'post';
		$GLOBALS['ogs_test_post_fields'][ $post_id ] = array(
			'post_content' => str_repeat( 'Long audit content ', 140 ) . '<img src="a.jpg"><img src="b.jpg" alt=""><img src="c.jpg">',
			'post_excerpt' => '',
		);
		$GLOBALS['ogs_test_post_meta'][ $post_id ] = array(
			'ogs_seo_title' => 'Short title',
			'ogs_seo_description' => str_repeat( 'Long description ', 20 ),
			'ogs_seo_robots' => '',
			'ogs_seo_canonical' => '',
		);

		$issues = array();
		$title_map = array();
		$meta_map = array();
		$orphan_candidates = array();
		$scanned_post_ids = array();
		$settings = array();
		$sitemap_enabled = false;
		$sitemap_types = array();
		$link_graph = array( 'coverage' => 1.0 );

		$method->invokeArgs(
			$audit,
			array(
				array( $post_id ),
				&$issues,
				&$title_map,
				&$meta_map,
				&$orphan_candidates,
				&$scanned_post_ids,
				$settings,
				$sitemap_enabled,
				$sitemap_types,
				$link_graph,
			)
		);

		$titles = array_map(
			static fn( array $issue ): string => (string) ( $issue['title'] ?? '' ),
			$issues
		);

		$this->assertContains( 'SEO title is very short', $titles );
		$this->assertContains( 'Meta description may be too long', $titles );
		$this->assertContains( 'Long content lacks subheadings', $titles );
		$this->assertContains( 'Some content images are missing alt text', $titles );
	}

	public function test_audit_summary_counts_quick_wins_and_categories(): void {
		$GLOBALS['ogs_test_options']['ogs_seo_audit_issues'] = array(
			array(
				'id' => 'a1',
				'severity' => 'critical',
				'title' => 'Technical issue',
				'category' => 'technical',
				'quick_win' => false,
			),
			array(
				'id' => 'a2',
				'severity' => 'minor',
				'title' => 'Meta description is very short',
				'category' => 'content',
				'quick_win' => true,
			),
		);

		$audit = new \OpenGrowthSolutions\OpenGrowthSEO\Audit\AuditManager();
		$summary = $audit->summary();

		$this->assertSame( 2, (int) ( $summary['total'] ?? 0 ) );
		$this->assertSame( 1, (int) ( $summary['critical'] ?? 0 ) );
		$this->assertSame( 1, (int) ( $summary['quick_wins'] ?? 0 ) );
		$this->assertSame( 1, (int) ( $summary['categories']['technical'] ?? 0 ) );
	}
}
