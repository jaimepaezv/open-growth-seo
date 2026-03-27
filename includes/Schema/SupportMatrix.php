<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\Schema;

defined( 'ABSPATH' ) || exit;

class SupportMatrix {
	public const SUPPORT_GOOGLE     = 'google_supported';
	public const SUPPORT_VOCAB      = 'valid_schema_only';
	public const SUPPORT_INTERNAL   = 'internal_graph';
	public const SUPPORT_BLOCKED    = 'blocked';

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		return array_merge( self::global_graph_types(), self::content_types() );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function global_graph_types(): array {
		return array(
			'Organization'  => array(
				'label'         => __( 'Organization / CollegeOrUniversity', 'open-growth-seo' ),
				'support'       => self::SUPPORT_INTERNAL,
				'setting_key'   => 'schema_organization_enabled',
				'contexts'      => array( 'site', 'all_urls' ),
				'risk'          => 'low',
				'expert_only'   => false,
				'description'   => __( 'Primary institution identity node for publisher/site ownership context. You can emit either Organization or CollegeOrUniversity from settings.', 'open-growth-seo' ),
				'prerequisites' => array( __( 'Organization name', 'open-growth-seo' ) ),
			),
			'CollegeOrUniversity' => array(
				'label'         => __( 'CollegeOrUniversity', 'open-growth-seo' ),
				'support'       => self::SUPPORT_VOCAB,
				'setting_key'   => 'schema_organization_enabled',
				'contexts'      => array( 'site', 'all_urls' ),
				'risk'          => 'low',
				'expert_only'   => false,
				'description'   => __( 'Institution-level identity node for universities and colleges when the site primarily represents a real academic institution.', 'open-growth-seo' ),
				'prerequisites' => array( __( 'Institution name', 'open-growth-seo' ) ),
			),
			'WebSite'       => array(
				'label'         => __( 'WebSite', 'open-growth-seo' ),
				'support'       => self::SUPPORT_INTERNAL,
				'setting_key'   => 'schema_website_enabled',
				'contexts'      => array( 'site', 'all_urls' ),
				'risk'          => 'low',
				'expert_only'   => false,
				'description'   => __( 'Site-level node with search action and publisher relationships.', 'open-growth-seo' ),
				'prerequisites' => array( __( 'Indexable site URL', 'open-growth-seo' ) ),
			),
			'WebPage'       => array(
				'label'         => __( 'WebPage', 'open-growth-seo' ),
				'support'       => self::SUPPORT_INTERNAL,
				'setting_key'   => 'schema_webpage_enabled',
				'contexts'      => array( 'all_urls' ),
				'risk'          => 'low',
				'expert_only'   => false,
				'description'   => __( 'Page-level default node and attachment point for content entities.', 'open-growth-seo' ),
				'prerequisites' => array( __( 'Resolvable canonical URL', 'open-growth-seo' ) ),
			),
			'BreadcrumbList' => array(
				'label'         => __( 'BreadcrumbList', 'open-growth-seo' ),
				'support'       => self::SUPPORT_GOOGLE,
				'setting_key'   => 'schema_breadcrumb_enabled',
				'contexts'      => array( 'all_urls_with_trail' ),
				'risk'          => 'low',
				'expert_only'   => false,
				'description'   => __( 'Hierarchy trail for stronger context and navigation clarity in search.', 'open-growth-seo' ),
				'prerequisites' => array( __( 'Valid breadcrumb trail', 'open-growth-seo' ) ),
			),
			'LocalBusiness' => array(
				'label'         => __( 'LocalBusiness', 'open-growth-seo' ),
				'support'       => self::SUPPORT_GOOGLE,
				'setting_key'   => 'schema_local_business_enabled',
				'contexts'      => array( 'location_landing_pages' ),
				'risk'          => 'high',
				'expert_only'   => false,
				'description'   => __( 'Per-location entity emitted only when page content clearly represents that location.', 'open-growth-seo' ),
				'prerequisites' => array(
					__( 'Published location record', 'open-growth-seo' ),
					__( 'Published matching landing page', 'open-growth-seo' ),
					__( 'Complete NAP fields', 'open-growth-seo' ),
				),
			),
		);
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function content_types(): array {
		return array(
			'Article'                => self::content_type(
				__( 'Article', 'open-growth-seo' ),
				self::SUPPORT_GOOGLE,
				'schema_article_enabled',
				'low',
				__( 'General editorial article markup for pages with substantial visible text content.', 'open-growth-seo' )
			),
			'BlogPosting'            => self::content_type(
				__( 'BlogPosting', 'open-growth-seo' ),
				self::SUPPORT_GOOGLE,
				'schema_article_enabled',
				'low',
				__( 'Recommended default for regular blog posts.', 'open-growth-seo' )
			),
			'TechArticle'            => self::content_type(
				__( 'TechArticle', 'open-growth-seo' ),
				self::SUPPORT_VOCAB,
				'schema_tech_article_enabled',
				'medium',
				__( 'Technical documentation, implementation guides, API docs, and engineering tutorials with clearly instructional or technical content.', 'open-growth-seo' )
			),
			'NewsArticle'            => self::content_type(
				__( 'NewsArticle', 'open-growth-seo' ),
				self::SUPPORT_GOOGLE,
				'schema_article_enabled',
				'medium',
				__( 'Use only for true news reporting content with timely publication context.', 'open-growth-seo' )
			),
			'Service'                => self::content_type(
				__( 'Service', 'open-growth-seo' ),
				self::SUPPORT_VOCAB,
				'schema_service_enabled',
				'medium',
				__( 'Best for real service landing pages such as agency services, practice areas, consulting offers, or campus support services.', 'open-growth-seo' )
			),
			'OfferCatalog'           => self::content_type(
				__( 'OfferCatalog', 'open-growth-seo' ),
				self::SUPPORT_VOCAB,
				'schema_offer_catalog_enabled',
				'medium',
				__( 'Use for catalog-style landing pages listing service offers, plans, or grouped offerings.', 'open-growth-seo' )
			),
			'Person'                 => self::content_type(
				__( 'Person', 'open-growth-seo' ),
				self::SUPPORT_VOCAB,
				'schema_person_enabled',
				'medium',
				__( 'Best for faculty, rector, researcher, or staff profile pages where the visible content clearly represents a person.', 'open-growth-seo' )
			),
			'Course'                 => self::content_type(
				__( 'Course', 'open-growth-seo' ),
				self::SUPPORT_VOCAB,
				'schema_course_enabled',
				'medium',
				__( 'Best for individual courses, subjects, or modules with clear academic information and a visible course description.', 'open-growth-seo' )
			),
			'EducationalOccupationalProgram' => self::content_type(
				__( 'EducationalOccupationalProgram', 'open-growth-seo' ),
				self::SUPPORT_VOCAB,
				'schema_program_enabled',
				'medium',
				__( 'Best for full degree or academic program landing pages such as majors, licenciaturas, masters, or doctorate programs.', 'open-growth-seo' )
			),
			'Product'                => self::content_type(
				__( 'Product', 'open-growth-seo' ),
				self::SUPPORT_GOOGLE,
				null,
				'medium',
				__( 'Single-product markup for product detail pages only.', 'open-growth-seo' )
			),
			'FAQPage'                => self::content_type(
				__( 'FAQPage', 'open-growth-seo' ),
				self::SUPPORT_GOOGLE,
				'schema_faq_enabled',
				'high',
				__( 'Requires visible, representative FAQ content with question-and-answer pairs.', 'open-growth-seo' )
			),
			'ProfilePage'            => self::content_type(
				__( 'ProfilePage', 'open-growth-seo' ),
				self::SUPPORT_VOCAB,
				'schema_profile_enabled',
				'medium',
				__( 'Profile context for person/organization profile pages.', 'open-growth-seo' )
			),
			'AboutPage'              => self::content_type(
				__( 'AboutPage', 'open-growth-seo' ),
				self::SUPPORT_VOCAB,
				'schema_about_page_enabled',
				'low',
				__( 'Use for pages whose main purpose is to explain the institution, business, team, mission, or organization background.', 'open-growth-seo' )
			),
			'ContactPage'            => self::content_type(
				__( 'ContactPage', 'open-growth-seo' ),
				self::SUPPORT_VOCAB,
				'schema_contact_page_enabled',
				'low',
				__( 'Use for contact or admissions contact pages with visible phone, email, location, or contact instructions.', 'open-growth-seo' )
			),
			'CollectionPage'         => self::content_type(
				__( 'CollectionPage', 'open-growth-seo' ),
				self::SUPPORT_VOCAB,
				'schema_collection_page_enabled',
				'low',
				__( 'Use for archive-style pages that curate a collection of related resources, courses, programs, publications, or tools.', 'open-growth-seo' )
			),
			'DiscussionForumPosting' => self::content_type(
				__( 'DiscussionForumPosting', 'open-growth-seo' ),
				self::SUPPORT_VOCAB,
				'schema_discussion_enabled',
				'high',
				__( 'Forum/discussion context only; not for generic articles.', 'open-growth-seo' )
			),
			'QAPage'                 => self::content_type(
				__( 'QAPage', 'open-growth-seo' ),
				self::SUPPORT_GOOGLE,
				'schema_qapage_enabled',
				'high',
				__( 'Q&A page context with a clear question and answer structure.', 'open-growth-seo' )
			),
			'VideoObject'            => self::content_type(
				__( 'VideoObject', 'open-growth-seo' ),
				self::SUPPORT_GOOGLE,
				'schema_video_enabled',
				'medium',
				__( 'Requires visible video content and valid video metadata.', 'open-growth-seo' )
			),
			'Event'                  => self::content_type(
				__( 'Event', 'open-growth-seo' ),
				self::SUPPORT_GOOGLE,
				'schema_event_enabled',
				'high',
				__( 'Requires valid event dates and event-specific page context.', 'open-growth-seo' )
			),
			'EventSeries'            => self::content_type(
				__( 'EventSeries', 'open-growth-seo' ),
				self::SUPPORT_VOCAB,
				'schema_event_series_enabled',
				'medium',
				__( 'Use for recurring event series or grouped events that share a common title and purpose.', 'open-growth-seo' )
			),
			'JobPosting'             => self::content_type(
				__( 'JobPosting', 'open-growth-seo' ),
				self::SUPPORT_GOOGLE,
				'schema_jobposting_enabled',
				'high',
				__( 'Requires employment details and hiring context.', 'open-growth-seo' )
			),
			'Recipe'                 => self::content_type(
				__( 'Recipe', 'open-growth-seo' ),
				self::SUPPORT_GOOGLE,
				'schema_recipe_enabled',
				'high',
				__( 'Requires visible ingredients and instructions.', 'open-growth-seo' )
			),
			'SoftwareApplication'    => self::content_type(
				__( 'SoftwareApplication', 'open-growth-seo' ),
				self::SUPPORT_VOCAB,
				'schema_software_enabled',
				'medium',
				__( 'Application/software context with platform and version signals.', 'open-growth-seo' )
			),
			'WebAPI'                 => self::content_type(
				__( 'WebAPI', 'open-growth-seo' ),
				self::SUPPORT_VOCAB,
				'schema_webapi_enabled',
				'medium',
				__( 'Use for API products or developer endpoints with visible documentation, access model, and provider context.', 'open-growth-seo' )
			),
			'Project'                => self::content_type(
				__( 'Project', 'open-growth-seo' ),
				self::SUPPORT_VOCAB,
				'schema_project_enabled',
				'medium',
				__( 'Use for project, initiative, research project, implementation, or case-study pages.', 'open-growth-seo' )
			),
			'Review'                 => self::content_type(
				__( 'Review', 'open-growth-seo' ),
				self::SUPPORT_VOCAB,
				'schema_review_enabled',
				'high',
				__( 'Use only when the page is clearly a review with an explicit rating and visible reviewed item.', 'open-growth-seo' )
			),
			'Guide'                  => self::content_type(
				__( 'Guide', 'open-growth-seo' ),
				self::SUPPORT_VOCAB,
				'schema_guide_enabled',
				'medium',
				__( 'Use for instructional guides, onboarding guides, step-by-step help pages, or procedural walkthroughs.', 'open-growth-seo' )
			),
			'Dataset'                => self::content_type(
				__( 'Dataset', 'open-growth-seo' ),
				self::SUPPORT_VOCAB,
				'schema_dataset_enabled',
				'medium',
				__( 'Dataset landing page context with methodology/data availability signals.', 'open-growth-seo' )
			),
			'DefinedTerm'            => self::content_type(
				__( 'DefinedTerm', 'open-growth-seo' ),
				self::SUPPORT_VOCAB,
				'schema_defined_term_enabled',
				'medium',
				__( 'Use for glossary term pages, controlled vocabulary entries, or individually defined concepts.', 'open-growth-seo' )
			),
			'DefinedTermSet'         => self::content_type(
				__( 'DefinedTermSet', 'open-growth-seo' ),
				self::SUPPORT_VOCAB,
				'schema_defined_term_set_enabled',
				'medium',
				__( 'Use for glossary index pages, terminology sets, taxonomies, or controlled vocabulary collections.', 'open-growth-seo' )
			),
			'ScholarlyArticle'       => self::content_type(
				__( 'ScholarlyArticle', 'open-growth-seo' ),
				self::SUPPORT_VOCAB,
				'schema_scholarly_article_enabled',
				'medium',
				__( 'Best for publications, papers, journal articles, and research repository landing pages.', 'open-growth-seo' )
			),
		);
	}

	/**
	 * @param string|null $type
	 * @return array<string, mixed>
	 */
	public static function record( ?string $type ): array {
		if ( null === $type ) {
			return array();
		}
		$type = trim( sanitize_text_field( $type ) );
		if ( '' === $type ) {
			return array();
		}
		$all = self::all();
		return isset( $all[ $type ] ) && is_array( $all[ $type ] ) ? $all[ $type ] : array();
	}

	public static function support_badge( string $support ): string {
		$support = sanitize_key( $support );
		switch ( $support ) {
			case self::SUPPORT_GOOGLE:
				return __( 'Google supported', 'open-growth-seo' );
			case self::SUPPORT_VOCAB:
				return __( 'Valid schema only', 'open-growth-seo' );
			case self::SUPPORT_INTERNAL:
				return __( 'Plugin internal graph', 'open-growth-seo' );
			default:
				return __( 'Blocked or unavailable', 'open-growth-seo' );
		}
	}

	public static function risk_badge( string $risk ): string {
		$risk = sanitize_key( $risk );
		switch ( $risk ) {
			case 'high':
				return __( 'High misuse risk', 'open-growth-seo' );
			case 'medium':
				return __( 'Moderate risk', 'open-growth-seo' );
			default:
				return __( 'Low risk', 'open-growth-seo' );
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function content_type( string $label, string $support, ?string $setting_key, string $risk, string $description ): array {
		return array(
			'label'         => $label,
			'support'       => $support,
			'setting_key'   => $setting_key,
			'contexts'      => array( 'singular' ),
			'risk'          => $risk,
			'expert_only'   => in_array( $risk, array( 'high', 'medium' ), true ),
			'description'   => $description,
			'prerequisites' => array(),
		);
	}
}
