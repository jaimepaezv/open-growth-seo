<?php
use PHPUnit\Framework\TestCase;
use OpenGrowthSolutions\OpenGrowthSEO\AEO\Analyzer;

final class AeoModuleRegressionTest extends TestCase {
	public function test_aeo_analyzer_has_content_analysis_methods(): void {
		$this->assertTrue( class_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\AEO\\Analyzer' ) );
		$this->assertTrue( class_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\AEO\\LinkSuggestions' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\AEO\\Analyzer', 'analyze_post' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\AEO\\Analyzer', 'analyze_content' ) );
	}

	public function test_aeo_analyzer_detects_answer_first_and_structures(): void {
		$content  = '<p>Technical SEO is the practice of improving crawlability and indexability with clear architecture.</p>';
		$content .= '<h2>How to implement</h2><ol><li>Audit crawling.</li><li>Fix canonicals.</li></ol>';
		$content .= '<p>Step 1: collect logs. Step 2: map issues.</p><p>Compare options in your process.</p>';
		$content .= '<p>Cost depends on crawl depth and implementation scope.</p>';
		$content .= '<p>Troubleshoot by validating directives and index status.</p>';
		$content .= '<p><a href="/guide/internal-linking">Internal guide</a> and <a href="https://example.com/help">help</a>.</p>';

		$analysis = Analyzer::analyze_content( $content, 'Technical SEO guide', 'https://example.com/technical-seo' );

		$this->assertTrue( is_array( $analysis ) );
		$this->assertTrue( ! empty( $analysis['summary']['answer_first'] ) );
		$this->assertGreaterThanOrEqual( 1, (int) $analysis['summary']['answer_paragraphs'] );
		$this->assertGreaterThanOrEqual( 1, (int) $analysis['signals']['structures']['lists'] );
		$this->assertGreaterThanOrEqual( 1, (int) $analysis['signals']['structures']['steps'] );
		$this->assertGreaterThanOrEqual( 2, (int) $analysis['summary']['internal_links'] );
		$this->assertContains( 'how', (array) $analysis['signals']['intent'] );
		$this->assertContains( 'comparison', (array) $analysis['signals']['intent'] );
		$this->assertContains( 'cost', (array) $analysis['signals']['intent'] );
		$this->assertContains( 'troubleshoot', (array) $analysis['signals']['intent'] );
		$this->assertGreaterThanOrEqual( 50, (int) $analysis['summary']['follow_up_coverage'] );
	}

	public function test_aeo_analyzer_tracks_focus_keyphrase_readability_and_cornerstone_signals(): void {
		$content  = '<p>Technical SEO audit is the process of reviewing crawlability, indexation, and site health in a clear, structured way.</p>';
		$content .= '<h2>Checklist</h2><p>Use short steps, clear headings, and direct summaries to improve readability for editorial teams and non-technical reviewers.</p>';
		$content .= '<h2>What to review</h2><p>Check indexation, canonical consistency, crawl blockers, internal links, XML sitemaps, and snippet controls before publishing large content updates.</p>';
		$content .= '<p><a href="/guides/log-analysis">Log analysis</a> and <a href="/guides/indexing">Indexing guide</a> support this page.</p>';

		$analysis = Analyzer::analyze_content(
			$content,
			'Technical SEO Audit Checklist',
			'https://example.com/technical-seo-audit-checklist',
			array(
				'focus_keyphrase' => 'technical seo audit',
				'cornerstone'     => true,
			)
		);

		$this->assertTrue( ! empty( $analysis['summary']['focus_keyphrase_set'] ) );
		$this->assertTrue( ! empty( $analysis['summary']['focus_keyphrase_in_title'] ) );
		$this->assertTrue( ! empty( $analysis['summary']['focus_keyphrase_in_intro'] ) );
		$this->assertSame( 'needs-work', $analysis['summary']['readability'] );
		$this->assertTrue( ! empty( $analysis['summary']['cornerstone'] ) );
		$this->assertArrayHasKey( 'keyphrase', $analysis['signals'] );
		$this->assertArrayHasKey( 'aeo_score', $analysis['summary'] );
		$this->assertArrayHasKey( 'priority_status', $analysis['summary'] );
		$this->assertIsArray( $analysis['opportunities'] );
		$this->assertIsArray( $analysis['priority_actions'] );
		$this->assertGreaterThan( 0, (int) $analysis['summary']['aeo_score'] );
	}

	public function test_aeo_analyzer_flags_non_text_dependency_when_visuals_dominate(): void {
		$content  = '<img src="https://example.com/a.jpg" alt="Diagram" />';
		$content .= '<img src="https://example.com/b.jpg" alt="Table capture" />';
		$content .= '<img src="https://example.com/c.jpg" alt="Flow chart" />';
		$content .= '<p>Short summary only.</p>';

		$analysis = Analyzer::analyze_content( $content, 'Product comparison', 'https://example.com/product-comparison' );

		$this->assertTrue( ! empty( $analysis['summary']['non_text_dependency'] ) );
		$this->assertGreaterThanOrEqual( 1, count( (array) $analysis['recommendations'] ) );
		$this->assertContains( 'non_text_dependency', array_column( (array) $analysis['opportunities'], 'code' ) );
	}

	public function test_aeo_analyzer_prioritizes_answer_structure_gaps_as_fix_first(): void {
		$content  = '<p>Technical SEO overview for teams planning a cleanup.</p>';
		$content .= '<p>Teams often review crawl signals, architecture patterns, and publishing workflows.</p>';
		$content .= '<p>Short copy with no steps, no lists, and no direct explanatory statement.</p>';

		$analysis = Analyzer::analyze_content( $content, 'Technical SEO overview', 'https://example.com/technical-seo-overview' );

		$this->assertSame( 'fix-this-first', $analysis['summary']['priority_status'] );
		$this->assertGreaterThanOrEqual( 1, (int) $analysis['summary']['opportunity_count'] );
		$this->assertContains( 'answer_first_missing', array_column( (array) $analysis['opportunities'], 'code' ) );
		$this->assertNotEmpty( $analysis['priority_actions'] );
	}

	public function test_aeo_analyzer_does_not_flag_nontext_dependency_when_textual_explanation_is_present(): void {
		$content  = '<p>This comparison explains the options with concise text before the visuals.</p>';
		$content .= '<p>Option A is faster, Option B is cheaper, and Option C is easier to maintain.</p>';
		$content .= '<table><tr><th>Option</th><th>Best for</th></tr><tr><td>A</td><td>Speed</td></tr></table>';
		$content .= '<p>Step 1: choose the main constraint. Step 2: validate implementation costs.</p>';
		$content .= '<img src="https://example.com/a.jpg" alt="Comparison chart" />';

		$analysis = Analyzer::analyze_content( $content, 'Options comparison', 'https://example.com/options' );

		$this->assertFalse( ! empty( $analysis['summary']['non_text_dependency'] ) );
	}

	public function test_editor_and_link_suggestions_source_include_internal_link_workflow(): void {
		$editor_source = file_get_contents( __DIR__ . '/../../includes/Admin/Editor.php' );
		$js_source     = file_get_contents( __DIR__ . '/../../assets/js/editor.js' );
		$link_source   = file_get_contents( __DIR__ . '/../../includes/AEO/LinkSuggestions.php' );

		$this->assertIsString( $editor_source );
		$this->assertIsString( $js_source );
		$this->assertIsString( $link_source );
		$this->assertStringContainsString( 'Suggested internal links to add from this page', $editor_source );
		$this->assertStringContainsString( 'Pages to review for a link back to this cornerstone', $editor_source );
		$this->assertStringContainsString( 'Suggested internal links to add from this page', $js_source );
		$this->assertStringContainsString( 'suggest_related_posts', $link_source );
	}
}
