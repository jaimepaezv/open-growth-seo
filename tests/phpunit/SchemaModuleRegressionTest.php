<?php
use PHPUnit\Framework\TestCase;

final class SchemaModuleRegressionTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['ogs_test_post_types_map'] = array();
		$GLOBALS['ogs_test_post_fields']    = array();
		$GLOBALS['ogs_test_post_titles']    = array();
		$GLOBALS['ogs_test_post_meta']      = array();
		$GLOBALS['ogs_test_post_dates']     = array();
		$GLOBALS['ogs_test_post_thumbnails'] = array();
	}

	public function test_schema_manager_exists_and_has_inspect(): void {
		$this->assertTrue( class_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Schema\\SchemaManager' ) );
		$this->assertTrue( method_exists( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Schema\\SchemaManager', 'inspect' ) );
	}

	public function test_build_node_for_type_ignores_unsupported_override(): void {
		$manager = new \OpenGrowthSolutions\OpenGrowthSEO\Schema\SchemaManager();
		$method  = new ReflectionMethod( $manager, 'build_node_for_type' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$manager,
			'FakeType',
			array(
				'url'       => 'https://example.com/post/',
				'post_id'   => 0,
				'post_type' => 'post',
				'settings'  => array(),
			),
			array()
		);

		$warnings_property = new ReflectionProperty( $manager, 'warnings' );
		$warnings_property->setAccessible( true );
		$warnings = (array) $warnings_property->getValue( $manager );

		$this->assertSame( array(), $result );
		$this->assertNotEmpty( $warnings );
	}

	public function test_build_node_for_type_respects_disabled_setting(): void {
		$manager = new \OpenGrowthSolutions\OpenGrowthSEO\Schema\SchemaManager();
		$method  = new ReflectionMethod( $manager, 'build_node_for_type' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$manager,
			'FAQPage',
			array(
				'url'         => 'https://example.com/post/',
				'post_id'     => 0,
				'post_type'   => 'post',
				'is_singular' => true,
				'settings'    => array(),
			),
			array(
				'schema_faq_enabled' => 0,
			)
		);

		$warnings_property = new ReflectionProperty( $manager, 'warnings' );
		$warnings_property->setAccessible( true );
		$warnings = (array) $warnings_property->getValue( $manager );

		$this->assertSame( array(), $result );
		$this->assertNotEmpty( $warnings );
	}

	public function test_content_node_skips_profile_page_when_disabled(): void {
		$manager = new \OpenGrowthSolutions\OpenGrowthSEO\Schema\SchemaManager();
		$method  = new ReflectionMethod( $manager, 'content_node' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$manager,
			array(
				'is_author'   => true,
				'is_search'   => false,
				'is_singular' => false,
				'post_id'     => 0,
				'post_type'   => '',
				'url'         => 'https://example.com/author/jane/',
				'settings'    => array(),
			),
			array(
				'schema_profile_enabled' => 0,
			)
		);

		$warnings_property = new ReflectionProperty( $manager, 'warnings' );
		$warnings_property->setAccessible( true );
		$warnings = (array) $warnings_property->getValue( $manager );

		$this->assertSame( array(), $result );
		$this->assertNotEmpty( $warnings );
	}

	public function test_validate_node_rejects_product_outside_product_context(): void {
		$manager = new \OpenGrowthSolutions\OpenGrowthSEO\Schema\SchemaManager();
		$method  = new ReflectionMethod( $manager, 'validate_node' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$manager,
			array(
				'@type'  => 'Product',
				'name'   => 'Example Product',
				'offers' => array(
					'@type'         => 'Offer',
					'priceCurrency' => 'USD',
					'price'         => '10.00',
					'availability'  => 'https://schema.org/InStock',
				),
			),
			array(
				'is_singular' => true,
				'post_type'   => 'post',
			)
		);

		$this->assertFalse( $result );
	}

	public function test_validate_node_rejects_content_schema_on_non_singular_context(): void {
		$manager = new \OpenGrowthSolutions\OpenGrowthSEO\Schema\SchemaManager();
		$method  = new ReflectionMethod( $manager, 'validate_node' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$manager,
			array(
				'@type'         => 'Article',
				'headline'      => 'Example',
				'datePublished' => '2026-03-17T12:00:00+00:00',
				'author'        => array(
					'@type' => 'Person',
					'name'  => 'Author',
				),
			),
			array(
				'is_singular' => false,
				'post_type'   => '',
			)
		);

		$this->assertFalse( $result );
	}

	public function test_website_node_omits_publisher_reference_when_organization_schema_is_disabled(): void {
		$manager = new \OpenGrowthSolutions\OpenGrowthSEO\Schema\SchemaManager();
		$method  = new ReflectionMethod( $manager, 'website_node' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$manager,
			array(
				'is_search' => false,
			),
			array(
				'schema_organization_enabled' => 0,
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( 'publisher', $result );
		$this->assertArrayHasKey( 'potentialAction', $result );
	}

	public function test_webpage_node_omits_website_reference_when_website_schema_is_disabled(): void {
		$manager = new \OpenGrowthSolutions\OpenGrowthSEO\Schema\SchemaManager();
		$method  = new ReflectionMethod( $manager, 'webpage_node' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$manager,
			array(
				'post_id' => 0,
				'url'     => 'https://example.com/example/',
			),
			array(
				'schema_website_enabled' => 0,
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( 'isPartOf', $result );
	}

	public function test_organization_node_can_emit_college_or_university(): void {
		$manager = new \OpenGrowthSolutions\OpenGrowthSEO\Schema\SchemaManager();
		$method  = new ReflectionMethod( $manager, 'organization_node' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$manager,
			array(
				'url' => 'https://example.com/',
			),
			array(
				'schema_org_type' => 'CollegeOrUniversity',
				'schema_org_name' => 'Example University',
				'schema_org_logo' => '',
				'schema_org_sameas' => '',
			)
		);

		$this->assertSame( 'CollegeOrUniversity', (string) ( $result['@type'] ?? '' ) );
		$this->assertSame( 'Example University', (string) ( $result['name'] ?? '' ) );
	}

	public function test_academic_schema_builders_emit_expected_nodes(): void {
		$GLOBALS['ogs_test_post_titles'][55] = 'Licenciatura en Derecho';
		$GLOBALS['ogs_test_post_fields'][55] = array(
			'post_content' => str_repeat( 'Programa universitario con admision, duracion, plan de estudios y salida profesional. ', 8 ),
			'post_excerpt' => 'Programa universitario orientado a formacion juridica.',
			'post_author'  => 3,
		);
		$GLOBALS['ogs_test_post_fields'][56] = array(
			'post_content' => str_repeat( 'Research article abstract methodology citations peer reviewed repository. ', 8 ),
			'post_excerpt' => 'Research publication abstract.',
			'post_author'  => 3,
		);
		$GLOBALS['ogs_test_post_titles'][56] = 'Research Methods in Public Policy';
		$GLOBALS['ogs_test_post_meta'][55] = array(
			'ogs_seo_schema_program_credential' => 'Licenciatura en Derecho',
			'ogs_seo_schema_duration'           => '8 semesters',
			'ogs_seo_schema_program_mode'       => 'On-campus',
		);
		$GLOBALS['ogs_test_post_meta'][56] = array(
			'ogs_seo_schema_scholarly_identifier' => 'doi:10.1000/xyz123',
			'ogs_seo_schema_scholarly_publication' => 'Journal of Public Policy Research',
		);
		$GLOBALS['ogs_test_post_dates'][56] = array(
			'published' => '2026-03-01T00:00:00+00:00',
			'modified'  => '2026-03-05T00:00:00+00:00',
		);
		$GLOBALS['ogs_test_users'][3] = array(
			'display_name' => 'Dra. Elena Perez',
		);

		$manager = new \OpenGrowthSolutions\OpenGrowthSEO\Schema\SchemaManager();

		$program_method = new ReflectionMethod( $manager, 'program_node' );
		$program_method->setAccessible( true );
		$program = $program_method->invoke(
			$manager,
			array(
				'url'     => 'https://example.com/programs/law/',
				'post_id' => 55,
			),
			array(
				'schema_organization_enabled' => 1,
			)
		);

		$article_method = new ReflectionMethod( $manager, 'scholarly_article_node' );
		$article_method->setAccessible( true );
		$article = $article_method->invoke(
			$manager,
			array(
				'url'     => 'https://example.com/research/policy/',
				'post_id' => 56,
			),
			array(
				'schema_organization_enabled' => 1,
			)
		);

		$this->assertSame( 'EducationalOccupationalProgram', (string) ( $program['@type'] ?? '' ) );
		$this->assertSame( 'Licenciatura en Derecho', (string) ( $program['educationalCredentialAwarded'] ?? '' ) );
		$this->assertSame( 'ScholarlyArticle', (string) ( $article['@type'] ?? '' ) );
		$this->assertSame( 'doi:10.1000/xyz123', (string) ( $article['identifier'] ?? '' ) );
		$this->assertSame( 'Journal of Public Policy Research', (string) ( $article['isPartOf']['name'] ?? '' ) );
	}

	public function test_setting_key_for_type_includes_new_academic_types(): void {
		$this->assertSame( 'schema_person_enabled', \OpenGrowthSolutions\OpenGrowthSEO\Schema\SchemaManager::setting_key_for_type( 'Person' ) );
		$this->assertSame( 'schema_course_enabled', \OpenGrowthSolutions\OpenGrowthSEO\Schema\SchemaManager::setting_key_for_type( 'Course' ) );
		$this->assertSame( 'schema_program_enabled', \OpenGrowthSolutions\OpenGrowthSEO\Schema\SchemaManager::setting_key_for_type( 'EducationalOccupationalProgram' ) );
		$this->assertSame( 'schema_scholarly_article_enabled', \OpenGrowthSolutions\OpenGrowthSEO\Schema\SchemaManager::setting_key_for_type( 'ScholarlyArticle' ) );
		$this->assertSame( 'schema_service_enabled', \OpenGrowthSolutions\OpenGrowthSEO\Schema\SchemaManager::setting_key_for_type( 'Service' ) );
		$this->assertSame( 'schema_webapi_enabled', \OpenGrowthSolutions\OpenGrowthSEO\Schema\SchemaManager::setting_key_for_type( 'WebAPI' ) );
	}

	public function test_detect_auto_type_for_post_respects_post_type_defaults(): void {
		$GLOBALS['ogs_test_post_types_map'][90] = 'faculty';
		$GLOBALS['ogs_test_post_fields'][90]    = array(
			'post_content' => str_repeat( 'Faculty profile biography professor researcher publications department. ', 8 ),
		);

		$type = \OpenGrowthSolutions\OpenGrowthSEO\Schema\EligibilityEngine::detect_auto_type_for_post(
			90,
			array(
				'schema_person_enabled' => 1,
				'schema_post_type_defaults' => array(
					'faculty' => 'Person',
				),
			)
		);

		$this->assertSame( 'Person', $type );
	}

	public function test_suggested_type_for_post_type_uses_cpt_slug_and_labels(): void {
		$GLOBALS['ogs_test_post_type_objects']['research_publication'] = (object) array(
			'name'        => 'research_publication',
			'label'       => 'Research Publications',
			'public'      => true,
			'labels'      => (object) array(
				'singular_name' => 'Research Publication',
				'name'          => 'Research Publications',
			),
			'description' => 'Scientific papers and repository records.',
		);

		$report = \OpenGrowthSolutions\OpenGrowthSEO\Schema\EligibilityEngine::suggested_type_for_post_type( 'research_publication' );

		$this->assertSame( 'ScholarlyArticle', (string) ( $report['type'] ?? '' ) );
		$this->assertNotSame( '', (string) ( $report['reason'] ?? '' ) );
	}

	public function test_auto_detection_report_prefers_builtin_cpt_suggestion_before_generic_heuristics(): void {
		$GLOBALS['ogs_test_post_type_objects']['degree_program'] = (object) array(
			'name'   => 'degree_program',
			'label'  => 'Degree Programs',
			'public' => true,
			'labels' => (object) array(
				'singular_name' => 'Degree Program',
				'name'          => 'Degree Programs',
			),
		);
		$GLOBALS['ogs_test_post_types_map'][91] = 'degree_program';
		$GLOBALS['ogs_test_post_fields'][91]    = array(
			'post_content' => str_repeat( 'Curriculum admissions credits degree outcomes faculty and study duration. ', 20 ),
		);

		$report = \OpenGrowthSolutions\OpenGrowthSEO\Schema\EligibilityEngine::auto_detection_report_for_post(
			91,
			array(
				'schema_program_enabled' => 1,
			)
		);

		$this->assertSame( 'EducationalOccupationalProgram', (string) ( $report['type'] ?? '' ) );
		$this->assertSame( 'suggested_post_type_default', (string) ( $report['source'] ?? '' ) );
	}

	public function test_support_matrix_includes_extended_cpt_ready_types(): void {
		$types = \OpenGrowthSolutions\OpenGrowthSEO\Schema\SupportMatrix::content_types();

		$this->assertArrayHasKey( 'Service', $types );
		$this->assertArrayHasKey( 'OfferCatalog', $types );
		$this->assertArrayHasKey( 'TechArticle', $types );
		$this->assertArrayHasKey( 'ContactPage', $types );
		$this->assertArrayHasKey( 'AboutPage', $types );
		$this->assertArrayHasKey( 'CollectionPage', $types );
		$this->assertArrayHasKey( 'EventSeries', $types );
		$this->assertArrayHasKey( 'WebAPI', $types );
		$this->assertArrayHasKey( 'Project', $types );
		$this->assertArrayHasKey( 'Review', $types );
		$this->assertArrayHasKey( 'Guide', $types );
		$this->assertArrayHasKey( 'DefinedTerm', $types );
		$this->assertArrayHasKey( 'DefinedTermSet', $types );
	}

	public function test_service_builder_can_emit_offer_and_service_channel(): void {
		$GLOBALS['ogs_test_post_titles'][120] = 'Admissions Consulting';
		$GLOBALS['ogs_test_post_fields'][120] = array(
			'post_content' => str_repeat( 'Service page with consulting support, clear outcomes, admissions help and booking details. ', 8 ),
			'post_excerpt' => 'Consulting support for admissions workflows.',
		);
		$GLOBALS['ogs_test_post_meta'][120] = array(
			'ogs_seo_schema_service_type'        => 'Admissions consulting',
			'ogs_seo_schema_service_area'        => 'Latin America',
			'ogs_seo_schema_service_channel_url' => 'https://example.com/contact/',
			'ogs_seo_schema_offer_price'         => '99.00',
			'ogs_seo_schema_offer_currency'      => 'USD',
			'ogs_seo_schema_offer_category'      => 'Premium plan',
		);

		$manager = new \OpenGrowthSolutions\OpenGrowthSEO\Schema\SchemaManager();
		$method  = new ReflectionMethod( $manager, 'service_node' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$manager,
			array(
				'url'     => 'https://example.com/services/admissions-consulting/',
				'post_id' => 120,
			),
			array(
				'schema_organization_enabled' => 1,
			)
		);

		$this->assertSame( 'Service', (string) ( $result['@type'] ?? '' ) );
		$this->assertSame( 'Admissions consulting', (string) ( $result['serviceType'] ?? '' ) );
		$this->assertSame( 'Offer', (string) ( $result['offers']['@type'] ?? '' ) );
		$this->assertSame( 'USD', (string) ( $result['offers']['priceCurrency'] ?? '' ) );
		$this->assertSame( 'ServiceChannel', (string) ( $result['availableChannel']['@type'] ?? '' ) );
	}

	public function test_suggested_type_for_post_type_supports_new_operational_cpts(): void {
		$GLOBALS['ogs_test_post_type_objects']['developer_api'] = (object) array(
			'name'   => 'developer_api',
			'label'  => 'Developer API',
			'public' => true,
			'labels' => (object) array(
				'singular_name' => 'Developer API',
				'name'          => 'Developer APIs',
			),
		);

		$api_report = \OpenGrowthSolutions\OpenGrowthSEO\Schema\EligibilityEngine::suggested_type_for_post_type( 'developer_api' );
		$this->assertSame( 'WebAPI', (string) ( $api_report['type'] ?? '' ) );
	}
}
