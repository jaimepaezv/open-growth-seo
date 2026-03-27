<?php
use PHPUnit\Framework\TestCase;
use OpenGrowthSolutions\OpenGrowthSEO\Schema\ValidationEngine;
use OpenGrowthSolutions\OpenGrowthSEO\Schema\ConflictEngine;
use OpenGrowthSolutions\OpenGrowthSEO\Schema\RuntimeInspector;
use OpenGrowthSolutions\OpenGrowthSEO\Schema\ContentSignals;

final class SchemaAdvancedValidationTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['ogs_test_options'] = array();
	}

	public function test_validation_engine_blocks_faq_when_visible_pairs_are_missing(): void {
		$report = ValidationEngine::validate_node(
			array(
				'@type'      => 'FAQPage',
				'mainEntity' => array(
					array(
						'@type' => 'Question',
						'name'  => 'One question only',
					),
				),
			),
			array(
				'is_singular'  => true,
				'post_type'    => 'page',
				'content_plain' => 'FAQ section content',
			)
		);

		$this->assertSame( 'blocked', (string) ( $report['status'] ?? '' ) );
		$this->assertContains( 'visible_faq_pairs', (array) ( $report['missing_required'] ?? array() ) );
	}

	public function test_validation_engine_uses_block_aware_visible_content_checks_for_faq_and_recipe(): void {
		$faq_report = ValidationEngine::validate_node(
			array(
				'@type'      => 'FAQPage',
				'mainEntity' => array(
					array(
						'@type' => 'Question',
						'name'  => 'What is this?',
						'acceptedAnswer' => array(
							'@type' => 'Answer',
							'text'  => 'Answer one.',
						),
					),
					array(
						'@type' => 'Question',
						'name'  => 'How does it work?',
						'acceptedAnswer' => array(
							'@type' => 'Answer',
							'text'  => 'Answer two.',
						),
					),
				),
			),
			array(
				'is_singular'   => true,
				'post_type'     => 'page',
				'content_raw'   => '<div style="display:none"><h2>What is this?</h2><p>Answer one.</p><h2>How does it work?</h2><p>Answer two.</p></div>',
				'content_plain' => 'hidden faq only',
			)
		);

		$recipe_report = ValidationEngine::validate_node(
			array(
				'@type'              => 'Recipe',
				'name'               => 'Example Recipe',
				'recipeIngredient'   => array( '1 apple', '2 pears', '1 orange' ),
				'recipeInstructions' => array( 'Slice fruit.', 'Serve.', 'Plate carefully.' ),
			),
			array(
				'is_singular'   => true,
				'post_type'     => 'post',
				'content_raw'   => '<!-- wp:heading --><h2>Ingredients</h2><!-- /wp:heading --><!-- wp:list --><ul><li>1 apple</li><li>2 pears</li></ul><!-- /wp:list --><!-- wp:heading --><h2>Instructions</h2><!-- /wp:heading --><!-- wp:list --><ol><li>Slice fruit.</li><li>Serve.</li></ol><!-- /wp:list -->',
				'content_plain' => 'recipe content',
			)
		);

		$this->assertSame( 'blocked', (string) ( $faq_report['status'] ?? '' ) );
		$this->assertContains( 'visible_faq_pairs', (array) ( $faq_report['missing_required'] ?? array() ) );
		$this->assertContains(
			'Some structured-data content may be hidden. Keep schema aligned with visible page content.',
			(array) ( $faq_report['messages'] ?? array() )
		);
		$this->assertContains( 'recipe_visible_parity', (array) ( $recipe_report['missing_recommended'] ?? array() ) );
	}

	public function test_content_signals_extracts_block_based_faq_recipe_and_video_signals(): void {
		$signals = ContentSignals::analyze(
			'<!-- wp:heading --><h2>What is this?</h2><!-- /wp:heading -->' .
			'<!-- wp:paragraph --><p>Answer one.</p><!-- /wp:paragraph -->' .
			'<!-- wp:heading --><h2>Why does it matter?</h2><!-- /wp:heading -->' .
			'<!-- wp:paragraph --><p>Answer two.</p><!-- /wp:paragraph -->' .
			'<!-- wp:heading --><h2>Ingredients</h2><!-- /wp:heading -->' .
			'<!-- wp:list --><ul><li>1 apple</li><li>2 pears</li></ul><!-- /wp:list -->' .
			'<!-- wp:heading --><h2>Instructions</h2><!-- /wp:heading -->' .
			'<!-- wp:list --><ol><li>Slice fruit.</li><li>Serve.</li></ol><!-- /wp:list -->' .
			'<!-- wp:embed --><figure><iframe src="https://www.youtube.com/watch?v=abc123xyz00"></iframe></figure><!-- /wp:embed -->'
		);

		$this->assertSame( 2, (int) ( $signals['faq_pair_count'] ?? 0 ) );
		$this->assertSame( 2, (int) ( $signals['recipe_ingredient_count'] ?? 0 ) );
		$this->assertSame( 2, (int) ( $signals['recipe_step_count'] ?? 0 ) );
		$this->assertNotEmpty( $signals['video_urls'] ?? array() );
	}

	public function test_validation_engine_blocks_jobposting_with_thin_or_incomplete_data(): void {
		$report = ValidationEngine::validate_node(
			array(
				'@type'       => 'JobPosting',
				'title'       => 'Junior SEO Specialist',
				'datePosted'  => '2026-03-10',
				'description' => 'Short description.',
			),
			array(
				'is_singular' => true,
				'post_type'   => 'post',
			)
		);

		$this->assertSame( 'blocked', (string) ( $report['status'] ?? '' ) );
		$this->assertContains( 'hiringorganization', (array) ( $report['missing_required'] ?? array() ) );
		$this->assertContains( 'employment_details', (array) ( $report['missing_required'] ?? array() ) );
	}

	public function test_conflict_engine_merges_canonical_conflicts_and_vocab_warnings(): void {
		$conflicts = ConflictEngine::detect(
			array(
				'is_singular' => true,
				'post_type'   => 'post',
				'canonical_diagnostics' => array(
					'conflicts' => array(
						array(
							'code'           => 'redirect_vs_canonical',
							'severity'       => 'important',
							'message'        => 'Redirect target differs from configured canonical.',
							'recommendation' => 'Align canonical target with redirect destination.',
						),
					),
				),
			),
			array(),
			array(
				array(
					'@type' => 'ProfilePage',
				),
			),
			array(
				array(
					'type'    => 'ProfilePage',
					'status'  => 'valid',
					'messages' => array(),
				),
			),
			array(),
			array()
		);

		$codes = array_map(
			static fn( $row ) => is_array( $row ) ? (string) ( $row['code'] ?? '' ) : '',
			$conflicts
		);
		$this->assertContains( 'canonical_redirect_vs_canonical', $codes );
		$this->assertContains( 'vocabulary_only_profilepage', $codes );

		$summary = ConflictEngine::summary( $conflicts );
		$this->assertGreaterThanOrEqual( 1, (int) ( $summary['important'] ?? 0 ) );
	}

	public function test_conflict_engine_flags_missing_internal_graph_references(): void {
		$conflicts = ConflictEngine::detect(
			array(
				'is_singular' => true,
				'post_type'   => 'post',
			),
			array(),
			array(
				array(
					'@type' => 'WebSite',
					'@id'   => 'https://example.com/#website',
					'publisher' => array(
						'@id' => 'https://example.com/#organization',
					),
				),
			),
			array(),
			array(),
			array()
		);

		$codes = array_map(
			static fn( $row ) => is_array( $row ) ? (string) ( $row['code'] ?? '' ) : '',
			$conflicts
		);

		$this->assertContains( 'missing_graph_reference', $codes );
	}

	public function test_runtime_inspector_summary_reports_nodes_and_conflict_totals(): void {
		$summary = RuntimeInspector::summary(
			array(
				'payload' => array(
					'@graph' => array(
						array( '@type' => 'Article' ),
						array( '@type' => 'Article' ),
						array( '@type' => 'BreadcrumbList' ),
					),
				),
				'errors' => array(
					'Example schema error',
				),
				'warnings' => array(
					'Example schema warning',
				),
				'conflicts' => array(
					array(
						'severity' => 'critical',
						'message'  => 'critical',
					),
					array(
						'severity' => 'minor',
						'message'  => 'minor',
					),
				),
				'context' => array(
					'url'          => 'https://example.com/example/',
					'post_id'      => 123,
					'post_type'    => 'post',
					'is_noindex'   => false,
					'is_redirect'  => false,
					'is_indexable' => true,
				),
			)
		);

		$this->assertSame( 3, (int) ( $summary['nodes_emitted'] ?? 0 ) );
		$this->assertSame( 2, (int) ( $summary['nodes_by_type']['Article'] ?? 0 ) );
		$this->assertSame( 1, (int) ( $summary['nodes_by_type']['BreadcrumbList'] ?? 0 ) );
		$this->assertSame( 1, (int) ( $summary['conflict_totals']['critical'] ?? 0 ) );
		$this->assertTrue( (bool) ( $summary['is_indexable'] ?? false ) );
	}

	public function test_runtime_inspector_records_snapshot_history_and_expected_vs_emitted_diff(): void {
		$first = RuntimeInspector::record_snapshot(
			array(
				'context' => array(
					'url'          => 'https://example.com/faq/',
					'post_id'      => 88,
					'post_type'    => 'page',
					'is_indexable' => true,
				),
				'payload' => array(
					'@graph' => array(
						array( '@type' => 'FAQPage' ),
					),
				),
				'conflicts' => array(),
				'type_statuses' => array(
					'FAQPage' => array(
						'eligible' => true,
					),
					'Recipe' => array(
						'eligible' => true,
					),
				),
			)
		);
		$second = RuntimeInspector::record_snapshot(
			array(
				'context' => array(
					'url'          => 'https://example.com/faq/',
					'post_id'      => 88,
					'post_type'    => 'page',
					'is_indexable' => true,
				),
				'payload' => array(
					'@graph' => array(
						array( '@type' => 'FAQPage' ),
						array( '@type' => 'BreadcrumbList' ),
					),
				),
				'conflicts' => array(
					array( 'severity' => 'minor', 'message' => 'minor' ),
				),
				'type_statuses' => array(
					'FAQPage' => array(
						'eligible' => true,
					),
					'Recipe' => array(
						'eligible' => true,
					),
				),
			)
		);

		$this->assertFalse( (bool) ( $first['diff']['has_previous'] ?? true ) );
		$this->assertTrue( (bool) ( $second['diff']['has_previous'] ?? false ) );
		$this->assertSame( 1, (int) ( $second['diff']['nodes_delta'] ?? 0 ) );
		$this->assertContains( 'Recipe', (array) ( $second['diff']['expected_not_emitted'] ?? array() ) );
		$this->assertContains( 'BreadcrumbList', (array) ( $second['diff']['unexpected_emitted'] ?? array() ) );
	}

	public function test_validation_engine_warns_when_person_lacks_affiliation_context(): void {
		$report = ValidationEngine::validate_node(
			array(
				'@type'    => 'Person',
				'name'     => 'Dr. Ana Torres',
				'jobTitle' => 'Professor of Biology',
				'url'      => 'https://example.com/faculty/ana-torres/',
			),
			array(
				'is_singular' => true,
				'post_type'   => 'faculty',
			)
		);

		$this->assertSame( 'warning', (string) ( $report['status'] ?? '' ) );
		$this->assertContains( 'affiliation', (array) ( $report['missing_recommended'] ?? array() ) );
	}

	public function test_validation_engine_warns_when_program_has_no_credential(): void {
		$report = ValidationEngine::validate_node(
			array(
				'@type'       => 'EducationalOccupationalProgram',
				'name'        => 'Computer Science Degree',
				'description' => str_repeat( 'Degree curriculum admissions labs research internships and credits. ', 6 ),
				'provider'    => array(
					'@type' => 'Organization',
					'name'  => 'Example University',
				),
				'url'         => 'https://example.com/programs/computer-science/',
			),
			array(
				'is_singular' => true,
				'post_type'   => 'program',
			)
		);

		$this->assertSame( 'warning', (string) ( $report['status'] ?? '' ) );
		$this->assertContains( 'educationalcredentialawarded', (array) ( $report['missing_recommended'] ?? array() ) );
	}

	public function test_validation_engine_warns_when_scholarly_article_has_no_publication_container(): void {
		$report = ValidationEngine::validate_node(
			array(
				'@type'         => 'ScholarlyArticle',
				'headline'      => 'Research on Urban Mobility',
				'description'   => str_repeat( 'Abstract methodology citations repository context and findings. ', 5 ),
				'datePublished' => '2026-03-01T00:00:00+00:00',
				'author'        => array(
					'@type' => 'Person',
					'name'  => 'Dr. Elena Perez',
				),
			),
			array(
				'is_singular' => true,
				'post_type'   => 'publication',
			)
		);

		$this->assertSame( 'warning', (string) ( $report['status'] ?? '' ) );
		$this->assertContains( 'publication_container', (array) ( $report['missing_recommended'] ?? array() ) );
	}
}
