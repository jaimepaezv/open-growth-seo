<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\Schema;

defined( 'ABSPATH' ) || exit;

class EligibilityEngine {
	/**
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	public static function evaluate_for_post( string $type, int $post_id, array $settings ): array {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 ) {
			return self::blocked_result(
				$type,
				__( 'This schema type requires a valid post context.', 'open-growth-seo' ),
				array( 'missing_post_context' )
			);
		}
		$context = array(
			'post_id'   => $post_id,
			'post_type' => (string) get_post_type( $post_id ),
			'content'   => (string) get_post_field( 'post_content', $post_id ),
		);
		return self::evaluate_for_context( $type, $context, $settings );
	}

	/**
	 * @param array<string, mixed> $context
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	public static function evaluate_for_context( string $type, array $context, array $settings ): array {
		$type = trim( sanitize_text_field( $type ) );
		if ( '' === $type ) {
			return array(
				'eligible' => true,
				'status'   => 'eligible',
				'reason'   => __( 'Automatic schema selection is always available.', 'open-growth-seo' ),
				'support'  => SupportMatrix::SUPPORT_INTERNAL,
				'risk'     => 'low',
				'missing'  => array(),
			);
		}

		$record = SupportMatrix::record( $type );
		if ( empty( $record ) ) {
			return self::blocked_result(
				$type,
				__( 'This schema type is not supported by Open Growth SEO.', 'open-growth-seo' ),
				array( 'unsupported_type' )
			);
		}

		$setting_key = isset( $record['setting_key'] ) ? (string) $record['setting_key'] : '';
		if ( '' !== $setting_key && empty( $settings[ $setting_key ] ) ) {
			return self::blocked_result(
				$type,
				__( 'This schema type is disabled in global Schema settings.', 'open-growth-seo' ),
				array( 'disabled_in_settings' ),
				$record
			);
		}

		$post_type = sanitize_key( (string) ( $context['post_type'] ?? '' ) );
		$content   = (string) ( $context['content'] ?? '' );
		$signals   = ContentSignals::analyze( $content );
		$plain     = strtolower( (string) ( $signals['plain_text'] ?? wp_strip_all_tags( $content ) ) );

		$missing = array();
		switch ( $type ) {
			case 'Article':
			case 'BlogPosting':
				if ( self::word_count( $plain ) < 120 ) {
					$missing[] = 'visible_substantive_text';
				}
				break;
			case 'TechArticle':
				if ( ! self::contains_any( $post_type, array( 'docs', 'documentation', 'api', 'developer', 'kb', 'knowledgebase', 'guide', 'manual' ) ) && ! self::contains_any( $plain, array( 'api', 'endpoint', 'request', 'response', 'implementation', 'setup', 'configuration', 'developer', 'technical' ) ) ) {
					$missing[] = 'technical_article_context';
				}
				if ( self::word_count( $plain ) < 120 ) {
					$missing[] = 'visible_substantive_text';
				}
				break;
			case 'NewsArticle':
				if ( ! str_contains( $post_type, 'news' ) && ! self::contains_any( $plain, array( 'breaking', 'reported', 'update', 'newsroom' ) ) ) {
					$missing[] = 'news_context';
				}
				if ( self::word_count( $plain ) < 150 ) {
					$missing[] = 'visible_substantive_text';
				}
				break;
			case 'Person':
				if ( ! self::contains_any( $post_type, array( 'person', 'people', 'faculty', 'teacher', 'professor', 'staff', 'researcher', 'author', 'rector' ) ) && ! self::contains_any( $plain, array( 'biography', 'profile', 'professor', 'researcher', 'faculty', 'rector', 'cv', 'publications' ) ) ) {
					$missing[] = 'person_profile_context';
				}
				break;
			case 'Service':
				if ( ! self::contains_any( $post_type, array( 'service', 'services', 'practice', 'consulting', 'support', 'solution' ) ) && ! self::contains_any( $plain, array( 'service', 'services', 'consulting', 'support', 'what we offer', 'how we help', 'book a call' ) ) ) {
					$missing[] = 'service_context';
				}
				break;
			case 'OfferCatalog':
				if ( ! self::contains_any( $post_type, array( 'catalog', 'services', 'offers', 'plans', 'pricing', 'courses' ) ) && ! self::contains_any( $plain, array( 'catalog', 'plans', 'pricing', 'services', 'packages', 'offerings' ) ) ) {
					$missing[] = 'offer_catalog_context';
				}
				break;
			case 'Course':
				if ( ! self::contains_any( $post_type, array( 'course', 'subject', 'module', 'class' ) ) && ! self::contains_any( $plain, array( 'course', 'credits', 'syllabus', 'learning outcomes', 'instructor', 'semester' ) ) ) {
					$missing[] = 'course_context';
				}
				if ( self::word_count( $plain ) < 80 ) {
					$missing[] = 'visible_course_description';
				}
				break;
			case 'EducationalOccupationalProgram':
				if ( ! self::contains_any( $post_type, array( 'program', 'degree', 'career', 'major', 'licenciatura', 'master', 'doctor' ) ) && ! self::contains_any( $plain, array( 'program', 'degree', 'curriculum', 'duration', 'admission', 'credits', 'licenciatura', 'maestria', 'doctorado', 'bachelor' ) ) ) {
					$missing[] = 'program_context';
				}
				if ( self::word_count( $plain ) < 120 ) {
					$missing[] = 'visible_program_description';
				}
				break;
			case 'Product':
				if ( ! ( class_exists( 'WooCommerce' ) && 'product' === $post_type ) ) {
					$missing[] = 'product_post_context';
				}
				break;
			case 'FAQPage':
				if ( (int) ( $signals['faq_pair_count'] ?? 0 ) < 2 ) {
					$missing[] = 'visible_faq_pairs';
				}
				break;
			case 'ProfilePage':
				if ( ! str_contains( $post_type, 'profile' ) && ! self::contains_any( $plain, array( 'about', 'biography', 'profile' ) ) ) {
					$missing[] = 'profile_context';
				}
				break;
			case 'AboutPage':
				if ( ! self::contains_any( $post_type, array( 'about', 'company', 'institution', 'mission', 'team' ) ) && ! self::contains_any( $plain, array( 'about us', 'our mission', 'our story', 'about the university', 'institutional profile' ) ) ) {
					$missing[] = 'about_page_context';
				}
				break;
			case 'ContactPage':
				if ( ! self::contains_any( $post_type, array( 'contact', 'contacts', 'admissions' ) ) && ! self::contains_any( $plain, array( 'contact', 'email', 'phone', 'call us', 'address', 'admissions office' ) ) ) {
					$missing[] = 'contact_page_context';
				}
				break;
			case 'CollectionPage':
				if ( ! self::contains_any( $post_type, array( 'archive', 'collection', 'catalog', 'directory', 'library' ) ) && ! self::contains_any( $plain, array( 'browse', 'collection', 'catalog', 'directory', 'all resources' ) ) ) {
					$missing[] = 'collection_context';
				}
				break;
			case 'DiscussionForumPosting':
				if ( ! self::is_discussion_context( $post_type, $plain ) ) {
					$missing[] = 'discussion_context';
				}
				break;
			case 'QAPage':
				if ( (int) ( $signals['qa_pair_count'] ?? 0 ) < 1 ) {
					$missing[] = 'qa_structure';
				}
				break;
			case 'VideoObject':
				if ( ! self::has_video_signal( $content ) ) {
					$missing[] = 'visible_video';
				}
				break;
			case 'Event':
				if ( ! str_contains( $post_type, 'event' ) && ! self::contains_any( $plain, array( 'event', 'venue', 'ticket', 'starts' ) ) ) {
					$missing[] = 'event_context';
				}
				break;
			case 'EventSeries':
				if ( ! str_contains( $post_type, 'event' ) && ! self::contains_any( $plain, array( 'series', 'every week', 'every month', 'recurring', 'event series' ) ) ) {
					$missing[] = 'event_series_context';
				}
				break;
			case 'JobPosting':
				if ( ! str_contains( $post_type, 'job' ) && ! self::contains_any( $plain, array( 'salary', 'employment', 'requirements', 'responsibilities' ) ) ) {
					$missing[] = 'job_context';
				}
				break;
			case 'Recipe':
				if ( ! empty( $signals['recipe_representative'] ) ) {
					break;
				}
				if ( ! self::contains_any( $plain, array( 'ingredients', 'instructions', 'servings' ) ) ) {
					$missing[] = 'recipe_signals';
				}
				break;
			case 'SoftwareApplication':
				if ( ! self::contains_any( $plain, array( 'version', 'download', 'platform', 'license', 'app' ) ) ) {
					$missing[] = 'software_signals';
				}
				break;
			case 'WebAPI':
				if ( ! self::contains_any( $post_type, array( 'api', 'developer', 'docs', 'documentation' ) ) && ! self::contains_any( $plain, array( 'api', 'endpoint', 'authentication', 'request', 'response', 'developer' ) ) ) {
					$missing[] = 'webapi_signals';
				}
				break;
			case 'Project':
				if ( ! self::contains_any( $post_type, array( 'project', 'case-study', 'case_study', 'initiative', 'portfolio' ) ) && ! self::contains_any( $plain, array( 'project', 'initiative', 'timeline', 'deliverables', 'outcomes' ) ) ) {
					$missing[] = 'project_context';
				}
				break;
			case 'Review':
				if ( ! self::contains_any( $post_type, array( 'review', 'testimonial' ) ) && ! self::contains_any( $plain, array( 'review', 'rating', 'pros', 'cons', 'verdict' ) ) ) {
					$missing[] = 'review_context';
				}
				break;
			case 'Guide':
				if ( ! self::contains_any( $post_type, array( 'guide', 'tutorial', 'howto', 'manual', 'help' ) ) && ! self::contains_any( $plain, array( 'step 1', 'how to', 'guide', 'follow these steps', 'tutorial' ) ) ) {
					$missing[] = 'guide_context';
				}
				break;
			case 'Dataset':
				if ( ! self::contains_any( $plain, array( 'dataset', 'variables', 'methodology', 'api', 'csv' ) ) ) {
					$missing[] = 'dataset_signals';
				}
				break;
			case 'DefinedTerm':
				if ( ! self::contains_any( $post_type, array( 'glossary', 'term', 'definition' ) ) && ! self::contains_any( $plain, array( 'definition', 'defined as', 'term', 'glossary' ) ) ) {
					$missing[] = 'defined_term_context';
				}
				break;
			case 'DefinedTermSet':
				if ( ! self::contains_any( $post_type, array( 'glossary', 'terminology', 'vocabulary', 'taxonomy' ) ) && ! self::contains_any( $plain, array( 'glossary', 'defined terms', 'terminology', 'vocabulary' ) ) ) {
					$missing[] = 'defined_term_set_context';
				}
				break;
			case 'ScholarlyArticle':
				if ( ! self::contains_any( $post_type, array( 'publication', 'paper', 'research', 'journal', 'article', 'repository' ) ) && ! self::contains_any( $plain, array( 'abstract', 'doi', 'journal', 'research', 'methodology', 'citation', 'references', 'peer reviewed' ) ) ) {
					$missing[] = 'scholarly_context';
				}
				if ( self::word_count( $plain ) < 150 ) {
					$missing[] = 'visible_abstract_or_summary';
				}
				break;
		}

		if ( ! empty( $missing ) ) {
			return self::blocked_result(
				$type,
				self::missing_reason_message( $type, $missing ),
				$missing,
				$record
			);
		}

		return array(
			'eligible' => true,
			'status'   => 'eligible',
			'reason'   => self::eligible_reason_message( $type ),
			'support'  => (string) ( $record['support'] ?? SupportMatrix::SUPPORT_VOCAB ),
			'risk'     => (string) ( $record['risk'] ?? 'medium' ),
			'missing'  => array(),
		);
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	public static function detect_auto_type_for_post( int $post_id, array $settings ): string {
		$report = self::auto_detection_report_for_post( $post_id, $settings );
		return isset( $report['type'] ) ? (string) $report['type'] : '';
	}

	/**
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	public static function auto_detection_report_for_post( int $post_id, array $settings ): array {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 ) {
			return array(
				'type'   => '',
				'source' => 'none',
				'reason' => __( 'No valid post context was available for schema auto-detection.', 'open-growth-seo' ),
			);
		}
		$post_type = (string) get_post_type( $post_id );
		$mapped    = isset( $settings['schema_post_type_defaults'][ $post_type ] ) ? trim( (string) $settings['schema_post_type_defaults'][ $post_type ] ) : '';
		if ( '' !== $mapped ) {
			$mapped_eligibility = self::evaluate_for_context(
				$mapped,
				array(
					'post_id'   => $post_id,
					'post_type' => $post_type,
					'content'   => (string) get_post_field( 'post_content', $post_id ),
				),
				$settings
			);
			if ( ! empty( $mapped_eligibility['eligible'] ) ) {
				return array(
					'type'   => $mapped,
					'source' => 'manual_post_type_default',
					'reason' => sprintf(
						/* translators: 1: post type, 2: schema type */
						__( 'Auto-detection uses the saved default mapping for CPT %1$s -> %2$s.', 'open-growth-seo' ),
						$post_type,
						$mapped
					),
				);
			}
		}

		$suggested = self::suggested_type_for_post_type( $post_type );
		if ( ! empty( $suggested['type'] ) ) {
			$suggested_type = (string) $suggested['type'];
			$suggested_eligibility = self::evaluate_for_context(
				$suggested_type,
				array(
					'post_id'   => $post_id,
					'post_type' => $post_type,
					'content'   => (string) get_post_field( 'post_content', $post_id ),
				),
				$settings
			);
			if ( ! empty( $suggested_eligibility['eligible'] ) ) {
				return array(
					'type'   => $suggested_type,
					'source' => 'suggested_post_type_default',
					'reason' => (string) ( $suggested['reason'] ?? '' ),
				);
			}
		}

		$content = strtolower( wp_strip_all_tags( (string) get_post_field( 'post_content', $post_id ) ) );

		$candidates = array(
			'Product',
			'Recipe',
			'JobPosting',
			'Service',
			'OfferCatalog',
			'Event',
			'EventSeries',
			'Course',
			'EducationalOccupationalProgram',
			'TechArticle',
			'SoftwareApplication',
			'WebAPI',
			'Project',
			'Review',
			'Guide',
			'Dataset',
			'DefinedTerm',
			'DefinedTermSet',
			'AboutPage',
			'ContactPage',
			'CollectionPage',
			'QAPage',
			'FAQPage',
			'Person',
			'ScholarlyArticle',
			'DiscussionForumPosting',
			'VideoObject',
			'NewsArticle',
			'BlogPosting',
			'Article',
		);

		foreach ( $candidates as $type ) {
			if ( in_array( $type, array( 'BlogPosting', 'Article', 'NewsArticle' ), true ) ) {
				if ( empty( $settings['schema_article_enabled'] ) ) {
					continue;
				}
			}
			$eligibility = self::evaluate_for_context(
				$type,
				array(
					'post_id'   => $post_id,
					'post_type' => $post_type,
					'content'   => (string) get_post_field( 'post_content', $post_id ),
				),
				$settings
			);
			if ( empty( $eligibility['eligible'] ) ) {
				continue;
			}
			if ( 'BlogPosting' === $type && 'post' !== $post_type ) {
				continue;
			}
			if ( 'NewsArticle' === $type && ! str_contains( strtolower( $post_type ), 'news' ) ) {
				continue;
			}
			if ( in_array( $type, array( 'BlogPosting', 'Article' ), true ) && self::word_count( $content ) < 120 ) {
				continue;
			}
			return array(
				'type'   => $type,
				'source' => 'content_heuristics',
				'reason' => sprintf(
					/* translators: %s: schema type */
					__( 'Auto-detection selected %s from visible content and post-type signals.', 'open-growth-seo' ),
					$type
				),
			);
		}
		return array(
			'type'   => '',
			'source' => 'none',
			'reason' => __( 'No eligible schema type was confidently detected for this content.', 'open-growth-seo' ),
		);
	}

	/**
	 * @return array<string, string>
	 */
	public static function suggested_type_for_post_type( string $post_type ): array {
		$post_type = sanitize_key( $post_type );
		if ( '' === $post_type ) {
			return array(
				'type'   => '',
				'reason' => '',
			);
		}

		$object = get_post_type_object( $post_type );
		$label_parts = array( $post_type );
		if ( is_object( $object ) ) {
			$label_parts[] = isset( $object->label ) ? (string) $object->label : '';
			if ( isset( $object->labels ) && is_object( $object->labels ) ) {
				$label_parts[] = isset( $object->labels->singular_name ) ? (string) $object->labels->singular_name : '';
				$label_parts[] = isset( $object->labels->name ) ? (string) $object->labels->name : '';
			}
			$label_parts[] = isset( $object->description ) ? (string) $object->description : '';
		}

		$haystack = strtolower( implode( ' ', array_filter( array_map( 'strval', $label_parts ) ) ) );
		$map = array(
			'Person' => array( 'faculty', 'staff', 'teacher', 'professor', 'people', 'person', 'researcher', 'lecturer', 'rector', 'dean', 'speaker' ),
			'Course' => array( 'course', 'courses', 'subject', 'module', 'class', 'syllabus', 'curriculum-unit' ),
			'EducationalOccupationalProgram' => array( 'program', 'degree', 'career', 'major', 'minor', 'licenciatura', 'maestria', 'doctorado', 'phd', 'masters', 'bachelor', 'programme' ),
			'ScholarlyArticle' => array( 'publication', 'publications', 'paper', 'papers', 'research', 'journal', 'repository', 'thesis', 'dissertation', 'article' ),
			'Event' => array( 'event', 'events', 'conference', 'seminar', 'webinar', 'ceremony', 'open day', 'open-day', 'workshop' ),
			'EventSeries' => array( 'event series', 'series', 'season', 'lecture series' ),
			'Service' => array( 'service', 'services', 'practice', 'consulting', 'support', 'solution' ),
			'OfferCatalog' => array( 'catalog', 'pricing', 'plans', 'offers', 'offerings' ),
			'WebAPI' => array( 'api', 'developer', 'endpoint', 'api docs', 'developer docs' ),
			'Project' => array( 'project', 'projects', 'case study', 'initiative', 'portfolio' ),
			'Review' => array( 'review', 'reviews', 'testimonial', 'testimonials' ),
			'Guide' => array( 'guide', 'tutorial', 'howto', 'manual', 'playbook' ),
			'TechArticle' => array( 'docs', 'documentation', 'knowledge base', 'kb', 'developer guide', 'technical article' ),
			'CollectionPage' => array( 'collection', 'directory', 'catalog', 'library', 'archive' ),
			'ContactPage' => array( 'contact', 'admissions', 'contact us' ),
			'AboutPage' => array( 'about', 'about us', 'mission', 'our story' ),
			'DefinedTerm' => array( 'term', 'definition', 'glossary term' ),
			'DefinedTermSet' => array( 'glossary', 'terminology', 'vocabulary', 'taxonomy' ),
		);

		foreach ( $map as $type => $needles ) {
			foreach ( $needles as $needle ) {
				if ( '' !== $needle && str_contains( $haystack, strtolower( $needle ) ) ) {
					return array(
						'type'   => $type,
						'reason' => sprintf(
							/* translators: 1: post type, 2: schema type */
							__( 'Auto-detection suggests %2$s for CPT %1$s based on its slug or labels.', 'open-growth-seo' ),
							$post_type,
							$type
						),
					);
				}
			}
		}

		return array(
			'type'   => '',
			'reason' => '',
		);
	}

	/**
	 * @param array<int, string> $missing
	 * @param array<string, mixed> $record
	 * @return array<string, mixed>
	 */
	private static function blocked_result( string $type, string $reason, array $missing = array(), array $record = array() ): array {
		return array(
			'eligible' => false,
			'status'   => 'blocked',
			'reason'   => $reason,
			'support'  => (string) ( $record['support'] ?? SupportMatrix::SUPPORT_BLOCKED ),
			'risk'     => (string) ( $record['risk'] ?? 'high' ),
			'missing'  => array_values( array_map( 'sanitize_key', $missing ) ),
		);
	}

	private static function has_video_signal( string $content ): bool {
		$signals = ContentSignals::analyze( $content );
		return ! empty( $signals['video_urls'] ) || false !== stripos( $content, '<video' );
	}

	private static function is_discussion_context( string $post_type, string $plain ): bool {
		if ( str_contains( $post_type, 'forum' ) || str_contains( $post_type, 'topic' ) || str_contains( $post_type, 'reply' ) ) {
			return true;
		}
		return self::contains_any( $plain, array( 'thread', 'reply', 'discussion', 'posted by' ) );
	}

	/**
	 * @param array<int, string> $needles
	 */
	private static function contains_any( string $plain, array $needles ): bool {
		foreach ( $needles as $needle ) {
			if ( '' !== $needle && str_contains( $plain, strtolower( $needle ) ) ) {
				return true;
			}
		}
		return false;
	}

	private static function word_count( string $plain ): int {
		$signals = ContentSignals::analyze( $plain );
		return (int) ( $signals['word_count'] ?? 0 );
	}

	/**
	 * @param array<int, string> $missing
	 */
	private static function missing_reason_message( string $type, array $missing ): string {
		$mapped = array();
		foreach ( $missing as $item ) {
			$mapped[] = str_replace( '_', ' ', sanitize_key( (string) $item ) );
		}
		return sprintf(
			/* translators: 1: type, 2: missing requirements */
			__( '%1$s is blocked because required signals are missing: %2$s.', 'open-growth-seo' ),
			$type,
			implode( ', ', array_slice( $mapped, 0, 6 ) )
		);
	}

	private static function eligible_reason_message( string $type ): string {
		return sprintf(
			/* translators: %s: schema type */
			__( '%s is eligible for this content context.', 'open-growth-seo' ),
			$type
		);
	}
}
