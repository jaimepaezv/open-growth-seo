<?php
use OpenGrowthSolutions\OpenGrowthSEO\SFO\Analyzer;
use PHPUnit\Framework\TestCase;

final class SfoModuleRegressionTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['ogs_test_enable_sfo_history'] = true;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['ogs_test_enable_sfo_history'] );
		unset( $GLOBALS['ogs_test_get_posts_callback'] );
		unset( $GLOBALS['ogs_test_post_titles'], $GLOBALS['ogs_test_permalinks'], $GLOBALS['ogs_test_post_fields'], $GLOBALS['ogs_test_post_meta'] );
		parent::tearDown();
	}

	public function test_sfo_analyzer_exists_and_exposes_analysis_methods(): void {
		$this->assertTrue( class_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\SFO\\Analyzer' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\SFO\\Analyzer', 'analyze_post' ) );
	}

	public function test_sfo_analyzer_detects_search_feature_readiness_and_priority_actions(): void {
		$GLOBALS['ogs_test_post_titles'][ 501 ] = 'Technical SEO audit checklist';
		$GLOBALS['ogs_test_permalinks'][ 501 ] = 'https://example.com/technical-seo-audit-checklist';
		$GLOBALS['ogs_test_post_fields'][ 501 ] = array(
			'post_content' => '<p>Technical SEO audit is the process of reviewing crawlability, indexation, and site health in a clear, structured way.</p><h2>Checklist</h2><p>Requirement: XML sitemap coverage. Version: 2026 baseline.</p><h2>How to implement</h2><ol><li>Audit crawl logs.</li><li>Fix canonical conflicts.</li></ol><p>Expected impact: 20% lower crawl waste in 30 days.</p><p><a href="/guides/log-analysis">Log guide</a><a href="/guides/indexing">Indexing guide</a></p>',
		);
		$GLOBALS['ogs_test_post_meta'][ 501 ] = array(
			'ogs_seo_title'            => 'Technical SEO Audit Checklist for Enterprise Sites',
			'ogs_seo_description'      => 'Review crawlability, indexation, and canonical risks with a clear technical SEO checklist built for larger sites.',
			'ogs_seo_focus_keyphrase'  => 'technical seo audit',
			'ogs_seo_social_title'     => 'Technical SEO Audit Checklist',
			'ogs_seo_social_image'     => 'https://example.com/image.jpg',
			'ogs_seo_schema_type'      => 'Article',
		);

		$analysis = Analyzer::analyze_post( 501 );

		$this->assertGreaterThan( 0, (int) ( $analysis['summary']['sfo_score'] ?? 0 ) );
		$this->assertArrayHasKey( 'feature_readiness', $analysis );
		$this->assertArrayHasKey( 'search_snippet', $analysis['feature_readiness'] );
		$this->assertArrayHasKey( 'priority_actions', $analysis );
		$this->assertIsArray( $analysis['priority_actions'] );
		$this->assertArrayHasKey( 'history', $analysis );
		$this->assertArrayHasKey( 'trend', $analysis );
	}

	public function test_sfo_analyzer_flags_missing_snippet_and_schema_gaps(): void {
		$GLOBALS['ogs_test_post_titles'][ 502 ] = 'Migration';
		$GLOBALS['ogs_test_permalinks'][ 502 ] = 'https://example.com/migration';
		$GLOBALS['ogs_test_post_fields'][ 502 ] = array(
			'post_content' => '<p>Short note only.</p><img src="https://example.com/chart.jpg" alt="Chart" />',
		);
		$GLOBALS['ogs_test_post_meta'][ 502 ] = array(
			'ogs_seo_schema_type' => 'FAQPage',
			'ogs_seo_nosnippet'   => '1',
		);

		$analysis = Analyzer::analyze_post( 502 );

		$this->assertSame( 'fix-this-first', (string) ( $analysis['summary']['priority_status'] ?? '' ) );
		$this->assertContains( 'snippet_restrictive', array_column( (array) $analysis['opportunities'], 'code' ) );
		$this->assertContains( 'rich_result_readiness', array_column( (array) $analysis['opportunities'], 'code' ) );
	}

	public function test_sfo_telemetry_exposes_rollup_and_entries(): void {
		$GLOBALS['ogs_test_post_titles'][ 503 ] = 'Search snippet readiness';
		$GLOBALS['ogs_test_permalinks'][ 503 ] = 'https://example.com/search-snippet-readiness';
		$GLOBALS['ogs_test_post_fields'][ 503 ] = array(
			'post_content' => '<p>Search snippet readiness improves when title, description, and answer framing are clear.</p><h2>Checklist</h2><p>Requirement: write a strong description and answer paragraph.</p>',
		);
		$GLOBALS['ogs_test_post_meta'][ 503 ] = array(
			'ogs_seo_title'       => 'Search Snippet Readiness Checklist',
			'ogs_seo_description' => 'Improve snippet framing with better titles, descriptions, and early answer blocks.',
		);
		$GLOBALS['ogs_test_get_posts_callback'] = static function (): array {
			return array( 503 );
		};

		$telemetry = Analyzer::telemetry( 1 );

		$this->assertArrayHasKey( 'entries', $telemetry );
		$this->assertArrayHasKey( 'rollup', $telemetry );
		$this->assertCount( 1, $telemetry['entries'] );
		$this->assertArrayHasKey( 'average_sfo_score', $telemetry['rollup'] );
		$this->assertArrayHasKey( 'remediation_queue', $telemetry['rollup'] );
	}
}
