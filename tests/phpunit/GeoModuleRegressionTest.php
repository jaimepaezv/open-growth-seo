<?php
use PHPUnit\Framework\TestCase;
use OpenGrowthSolutions\OpenGrowthSEO\GEO\BotControls;

final class GeoModuleRegressionTest extends TestCase {
	public function test_geo_analyzer_has_expected_methods(): void {
		$this->assertTrue( class_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\GEO\\BotControls' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\GEO\\BotControls', 'analyze_content' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\GEO\\BotControls', 'bot_policy_snapshot' ) );
	}

	public function test_geo_analyzer_detects_strong_textual_and_citability_signals(): void {
		$content  = '<p>Technical SEO is the practice of improving crawlability and indexability with measurable controls.</p>';
		$content .= '<h2>Scope</h2><p>Requirement: XML sitemap coverage. Version: 2026 baseline.</p>';
		$content .= '<h2>How to implement</h2><ol><li>Audit crawl logs.</li><li>Fix canonical conflicts.</li></ol>';
		$content .= '<p>Expected impact: 25% lower crawl waste in 30 days.</p>';
		$content .= '<p><a href="/guides/log-analysis">Log guide</a> and <a href="/guides/canonicals">Canonical guide</a>.</p>';

		$analysis = BotControls::analyze_content( $content, 'Technical SEO implementation', 'https://example.com/technical-seo' );

		$this->assertTrue( ! empty( $analysis['summary']['critical_text_visible'] ) );
		$this->assertGreaterThanOrEqual( 2, (int) $analysis['summary']['citability_score'] );
		$this->assertTrue( ! empty( $analysis['summary']['semantic_clear'] ) );
		$this->assertGreaterThanOrEqual( 2, (int) $analysis['signals']['internal_links']['count'] );
		$this->assertArrayHasKey( 'geo_score', $analysis['summary'] );
		$this->assertArrayHasKey( 'priority_status', $analysis['summary'] );
		$this->assertIsArray( $analysis['opportunities'] );
		$this->assertSame( 'strong', $analysis['summary']['priority_status'] );
	}

	public function test_geo_analyzer_flags_weak_visual_only_content(): void {
		$content  = '<img src="https://example.com/a.jpg" alt="diagram"/>';
		$content .= '<img src="https://example.com/b.jpg" alt="chart"/>';
		$content .= '<img src="https://example.com/c.jpg" alt="table"/>';
		$content .= '<p>Short note only.</p>';

		$analysis = BotControls::analyze_content( $content, 'Migration guide', 'https://example.com/migration' );

		$this->assertFalse( ! empty( $analysis['summary']['critical_text_visible'] ) );
		$this->assertGreaterThanOrEqual( 1, count( (array) $analysis['recommendations'] ) );
		$this->assertContains( 'critical_text_not_visible', array_column( (array) $analysis['opportunities'], 'code' ) );
		$this->assertSame( 'fix-this-first', $analysis['summary']['priority_status'] );
	}

	public function test_geo_analyzer_detects_restrictive_snippet_participation_and_adds_guidance(): void {
		$content  = '<p>Technical implementation details are available for crawlers.</p>';
		$content .= '<h2>Scope</h2><p>Requirement: API token rotation.</p>';
		$content .= '<p><a href="/docs/security">Security docs</a><a href="/docs/ops">Operations docs</a></p>';

		$analysis = BotControls::analyze_content(
			$content,
			'Operational playbook',
			'https://example.com/ops-playbook',
			array(
				'snippet_controls' => array(
					'nosnippet'         => '1',
					'max_snippet'       => '30',
					'max_image_preview' => 'none',
					'max_video_preview' => '',
				),
				'data_nosnippet_ids' => "sec-a\nsec-b\nsec-c\nsec-d\nsec-e\nsec-f\nsec-g",
			)
		);

		$this->assertTrue( ! empty( $analysis['summary']['snippet_participation_restrictive'] ) );
		$this->assertTrue( ! empty( $analysis['signals']['snippet_participation']['nosnippet'] ) );
		$this->assertTrue( ! empty( $analysis['signals']['snippet_participation']['max_snippet_restrictive'] ) );
		$this->assertGreaterThanOrEqual( 1, count( (array) $analysis['recommendations'] ) );
		$this->assertContains( 'nosnippet_conflict', array_column( (array) $analysis['opportunities'], 'code' ) );
	}

	public function test_geo_analyzer_adds_schema_runtime_remediation_when_schema_runtime_has_attention(): void {
		$content  = '<p>Operational guidance with some visible text.</p>';
		$content .= '<h2>Scope</h2><p>Requirement: token rotation.</p>';

		$analysis = BotControls::analyze_content(
			$content,
			'Operational guidance',
			'https://example.com/ops-guidance',
			array(
				'schema_runtime' => array(
					'needs_attention' => true,
					'message'         => 'No schema nodes were emitted for the current page context.',
				),
			)
		);

		$this->assertTrue( ! empty( $analysis['summary']['schema_runtime_attention'] ) );
		$this->assertContains( 'schema_runtime_attention', array_column( (array) $analysis['opportunities'], 'code' ) );
	}

	public function test_geo_analyzer_exposes_restricted_bot_access_when_all_relevant_bots_are_blocked(): void {
		update_option(
			'ogs_seo_settings',
			array(
				'geo_enabled'         => 1,
				'robots_global_policy' => 'disallow',
				'bots_gptbot'         => 'disallow',
				'bots_oai_searchbot'  => 'disallow',
			)
		);

		$content  = '<p>Technical implementation note.</p>';
		$content .= '<h2>Scope</h2><p>Requirement: token rotation.</p>';

		$analysis = BotControls::analyze_content( $content, 'Operational note', 'https://example.com/note' );

		$this->assertSame( 'restricted', $analysis['summary']['bot_exposure'] );
		$this->assertContains( 'bot_access_restricted', array_column( (array) $analysis['opportunities'], 'code' ) );
	}

	public function test_geo_analyzer_records_history_and_trend_for_post(): void {
		$GLOBALS['ogs_test_post_fields'][ 321 ] = array(
			'post_content' => '<p>Technical SEO is the practice of improving crawlability and indexability with measurable controls.</p><h2>Scope</h2><p>Requirement: XML sitemap coverage.</p>',
		);
		$GLOBALS['ogs_test_post_titles'][ 321 ] = 'Technical SEO controls';
		$GLOBALS['ogs_test_permalinks'][ 321 ] = 'https://example.com/technical-seo-controls';

		$first = BotControls::analyze_post( 321 );
		$this->assertSame( 'new', $first['trend']['state'] );
		$this->assertNotEmpty( $first['history'] );

		$GLOBALS['ogs_test_post_fields'][ 321 ]['post_content'] .= '<p>Expected impact: 20% lower crawl waste in 30 days.</p><p><a href="/guides/log-analysis">Log guide</a><a href="/guides/canonicals">Canonical guide</a></p>';
		$second = BotControls::analyze_post( 321 );

		$this->assertNotEmpty( $second['history'] );
		$this->assertArrayHasKey( 'geo_score_delta', $second['trend'] );
		$this->assertContains( $second['trend']['state'], array( 'improving', 'stable', 'declining' ) );
	}

	public function test_geo_telemetry_builds_cross_page_rollup(): void {
		$GLOBALS['ogs_test_post_types'] = array( 'page' => 'page' );
		$GLOBALS['ogs_test_get_posts_callback'] = static function ( array $args ): array {
			unset( $args );
			return array( 401, 402 );
		};
		$GLOBALS['ogs_test_post_titles'][ 401 ] = 'Geo telemetry one';
		$GLOBALS['ogs_test_post_titles'][ 402 ] = 'Geo telemetry two';
		$GLOBALS['ogs_test_permalinks'][ 401 ] = 'https://example.com/geo-telemetry-one';
		$GLOBALS['ogs_test_permalinks'][ 402 ] = 'https://example.com/geo-telemetry-two';
		$GLOBALS['ogs_test_post_fields'][ 401 ] = array(
			'post_content' => '<p>Technical SEO is the practice of improving crawlability with measurable controls.</p><h2>Scope</h2><p>Requirement: XML sitemap coverage.</p><p>Expected impact: 20% lower crawl waste.</p><p><a href="/guides/a">Guide A</a><a href="/guides/b">Guide B</a></p>',
		);
		$GLOBALS['ogs_test_post_fields'][ 402 ] = array(
			'post_content' => '<p>Short visual summary only.</p><img src="https://example.com/chart.jpg" alt="Chart" />',
		);

		$telemetry = BotControls::telemetry( 2 );

		$this->assertArrayHasKey( 'entries', $telemetry );
		$this->assertArrayHasKey( 'rollup', $telemetry );
		$this->assertCount( 2, (array) $telemetry['entries'] );
		$this->assertSame( 2, (int) ( $telemetry['rollup']['total_pages'] ?? 0 ) );
		$this->assertArrayHasKey( 'remediation_queue', $telemetry['rollup'] );
	}
}
