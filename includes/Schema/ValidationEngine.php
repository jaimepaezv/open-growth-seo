<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\Schema;

defined( 'ABSPATH' ) || exit;

class ValidationEngine {
	/**
	 * @param array<string, mixed> $node
	 * @param array<string, mixed> $context
	 * @return array<string, mixed>
	 */
	public static function validate_node( array $node, array $context ): array {
		$type = isset( $node['@type'] ) ? trim( (string) $node['@type'] ) : '';
		if ( '' === $type ) {
			return self::report( '', 'blocked', array( 'missing_type' ), array(), array( __( 'Schema node has no @type.', 'open-growth-seo' ) ) );
		}

		$record = SupportMatrix::record( $type );
		if ( empty( $record ) ) {
			return self::report(
				$type,
				'blocked',
				array( 'unsupported_type' ),
				array(),
				array( sprintf( __( 'Unsupported schema type emitted: %s', 'open-growth-seo' ), $type ) )
			);
		}

		$required_map = self::required_fields();
		$required     = isset( $required_map[ $type ] ) ? (array) $required_map[ $type ] : array();
		$missing_required = array();
		foreach ( $required as $field ) {
			if ( ! self::has_field_value( $node, $field ) ) {
				$missing_required[] = $field;
			}
		}

		$recommended_map = self::recommended_fields();
		$recommended     = isset( $recommended_map[ $type ] ) ? (array) $recommended_map[ $type ] : array();
		$missing_recommended = array();
		foreach ( $recommended as $field ) {
			if ( ! self::has_field_value( $node, $field ) ) {
				$missing_recommended[] = $field;
			}
		}

		$messages = array();
		$status   = empty( $missing_required ) ? 'valid' : 'blocked';
		if ( ! empty( $missing_required ) ) {
			$messages[] = sprintf(
				/* translators: 1: schema type, 2: missing fields */
				__( '%1$s missing required fields: %2$s', 'open-growth-seo' ),
				$type,
				implode( ', ', $missing_required )
			);
		}
		if ( empty( $missing_required ) && ! empty( $missing_recommended ) ) {
			$status     = 'warning';
			$messages[] = sprintf(
				/* translators: 1: schema type, 2: missing fields */
				__( '%1$s missing recommended fields: %2$s', 'open-growth-seo' ),
				$type,
				implode( ', ', $missing_recommended )
			);
		}

		if ( self::requires_singular_context( $type ) && empty( $context['is_singular'] ) ) {
			$status     = 'blocked';
			$messages[] = sprintf( __( '%s cannot be emitted outside singular content context.', 'open-growth-seo' ), $type );
		}
		if ( 'Product' === $type && ( empty( $context['is_singular'] ) || 'product' !== (string) ( $context['post_type'] ?? '' ) ) ) {
			$status     = 'blocked';
			$messages[] = __( 'Product schema cannot be emitted outside single product context.', 'open-growth-seo' );
		}
		if ( ! empty( $context['is_noindex'] ) && self::is_content_entity( $type ) ) {
			if ( 'blocked' !== $status ) {
				$status = 'conflicting';
			}
			$messages[] = __( 'URL is noindex; verify that content entity schema is still intended for this state.', 'open-growth-seo' );
		}

		$type_checks = self::type_specific_checks( $type, $node, $context );
		$type_required = isset( $type_checks['missing_required'] ) && is_array( $type_checks['missing_required'] ) ? $type_checks['missing_required'] : array();
		$type_recommended = isset( $type_checks['missing_recommended'] ) && is_array( $type_checks['missing_recommended'] ) ? $type_checks['missing_recommended'] : array();
		$type_messages = isset( $type_checks['messages'] ) && is_array( $type_checks['messages'] ) ? $type_checks['messages'] : array();
		$type_status = isset( $type_checks['status'] ) ? sanitize_key( (string) $type_checks['status'] ) : '';

		if ( ! empty( $type_required ) ) {
			$missing_required = array_values( array_unique( array_merge( $missing_required, $type_required ) ) );
			$status = 'blocked';
		}
		if ( 'blocked' !== $status && ! empty( $type_recommended ) ) {
			$missing_recommended = array_values( array_unique( array_merge( $missing_recommended, $type_recommended ) ) );
			if ( 'valid' === $status ) {
				$status = 'warning';
			}
		}
		if ( '' !== $type_status ) {
			if ( 'blocked' === $type_status ) {
				$status = 'blocked';
			} elseif ( 'conflicting' === $type_status && 'blocked' !== $status ) {
				$status = 'conflicting';
			} elseif ( 'warning' === $type_status && in_array( $status, array( 'valid', 'eligible' ), true ) ) {
				$status = 'warning';
			}
		}
		foreach ( $type_messages as $message ) {
			$message = sanitize_text_field( (string) $message );
			if ( '' !== $message ) {
				$messages[] = $message;
			}
		}

		$messages = array_values( array_unique( $messages ) );

		return self::report( $type, $status, $missing_required, $missing_recommended, $messages, $record );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public static function required_fields(): array {
		return array(
			'Organization'           => array( 'name', 'url' ),
			'CollegeOrUniversity'    => array( 'name', 'url' ),
			'LocalBusiness'          => array( 'name', 'url', 'address' ),
			'WebSite'                => array( 'name', 'url' ),
			'WebPage'                => array( 'name', 'url' ),
			'BreadcrumbList'         => array( 'itemListElement' ),
			'Article'                => array( 'headline', 'datePublished', 'author' ),
			'BlogPosting'            => array( 'headline', 'datePublished', 'author' ),
			'TechArticle'            => array( 'headline', 'datePublished', 'author' ),
			'NewsArticle'            => array( 'headline', 'datePublished', 'author' ),
			'Service'                => array( 'name', 'description', 'provider' ),
			'OfferCatalog'           => array( 'name', 'itemListElement' ),
			'Person'                 => array( 'name' ),
			'Course'                 => array( 'name', 'description', 'provider' ),
			'EducationalOccupationalProgram' => array( 'name', 'description', 'provider' ),
			'Product'                => array( 'name', 'offers' ),
			'FAQPage'                => array( 'mainEntity' ),
			'ProfilePage'            => array( 'mainEntity' ),
			'AboutPage'              => array( 'name', 'url' ),
			'ContactPage'            => array( 'name', 'url' ),
			'CollectionPage'         => array( 'name', 'url' ),
			'DiscussionForumPosting' => array( 'headline', 'articleBody' ),
			'QAPage'                 => array( 'mainEntity' ),
			'VideoObject'            => array( 'name', 'uploadDate', 'contentUrl' ),
			'Event'                  => array( 'name', 'startDate' ),
			'EventSeries'            => array( 'name', 'startDate' ),
			'JobPosting'             => array( 'title', 'description', 'datePosted' ),
			'Recipe'                 => array( 'name', 'recipeIngredient', 'recipeInstructions' ),
			'SoftwareApplication'    => array( 'name', 'applicationCategory' ),
			'WebAPI'                 => array( 'name', 'provider', 'documentation' ),
			'Project'                => array( 'name', 'description', 'url' ),
			'Review'                 => array( 'reviewBody', 'reviewRating', 'itemReviewed' ),
			'Guide'                  => array( 'name', 'step' ),
			'Dataset'                => array( 'name', 'description' ),
			'DefinedTerm'            => array( 'name', 'description' ),
			'DefinedTermSet'         => array( 'name', 'description' ),
			'ScholarlyArticle'       => array( 'headline', 'datePublished', 'author' ),
		);
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public static function recommended_fields(): array {
		return array(
			'Organization'        => array( 'logo', 'sameAs' ),
			'CollegeOrUniversity' => array( 'logo', 'sameAs' ),
			'LocalBusiness'       => array( 'telephone', 'geo', 'openingHoursSpecification' ),
			'Article'             => array( 'image', 'description', 'publisher' ),
			'BlogPosting'         => array( 'image', 'description', 'publisher' ),
			'TechArticle'         => array( 'image', 'description', 'publisher', 'proficiencyLevel' ),
			'NewsArticle'         => array( 'image', 'description', 'publisher' ),
			'Service'             => array( 'serviceType', 'areaServed', 'offers', 'availableChannel' ),
			'OfferCatalog'        => array( 'url', 'numberOfItems' ),
			'Person'              => array( 'jobTitle', 'worksFor', 'sameAs', 'image', 'url' ),
			'Course'              => array( 'courseCode', 'educationalCredentialAwarded', 'url' ),
			'EducationalOccupationalProgram' => array( 'educationalCredentialAwarded', 'timeToComplete', 'educationalProgramMode', 'url' ),
			'Product'             => array( 'image', 'aggregateRating' ),
			'FAQPage'             => array(),
			'QAPage'              => array(),
			'AboutPage'           => array( 'about', 'mainEntity' ),
			'ContactPage'         => array( 'mainEntity', 'breadcrumb' ),
			'CollectionPage'      => array( 'mainEntity', 'hasPart' ),
			'VideoObject'         => array( 'thumbnailUrl', 'description' ),
			'Event'               => array( 'location', 'endDate' ),
			'EventSeries'         => array( 'endDate', 'subEvent' ),
			'JobPosting'          => array( 'validThrough', 'employmentType' ),
			'Recipe'              => array( 'description', 'image' ),
			'SoftwareApplication' => array( 'operatingSystem', 'url' ),
			'WebAPI'              => array( 'url', 'audience' ),
			'Project'             => array( 'keywords', 'provider' ),
			'Review'              => array( 'author', 'datePublished' ),
			'Guide'               => array( 'description', 'totalTime' ),
			'Dataset'             => array( 'url' ),
			'DefinedTerm'         => array( 'termCode', 'inDefinedTermSet' ),
			'DefinedTermSet'      => array( 'hasDefinedTerm', 'url' ),
			'ScholarlyArticle'    => array( 'description', 'isPartOf', 'identifier', 'publisher' ),
		);
	}

	private static function requires_singular_context( string $type ): bool {
		return in_array(
			$type,
			array(
				'Article',
				'BlogPosting',
				'TechArticle',
				'NewsArticle',
				'Service',
				'OfferCatalog',
				'Person',
				'Course',
				'EducationalOccupationalProgram',
				'Product',
				'FAQPage',
				'AboutPage',
				'ContactPage',
				'CollectionPage',
				'DiscussionForumPosting',
				'QAPage',
				'VideoObject',
				'Event',
				'EventSeries',
				'JobPosting',
				'Recipe',
				'SoftwareApplication',
				'WebAPI',
				'Project',
				'Review',
				'Guide',
				'Dataset',
				'DefinedTerm',
				'DefinedTermSet',
				'ScholarlyArticle',
			),
			true
		);
	}

	private static function is_content_entity( string $type ): bool {
		return self::requires_singular_context( $type ) || 'ProfilePage' === $type;
	}

	/**
	 * @param array<string, mixed> $node
	 * @param array<string, mixed> $context
	 * @return array<string, mixed>
	 */
	private static function type_specific_checks( string $type, array $node, array $context ): array {
		$checks = array(
			'status'              => 'valid',
			'missing_required'    => array(),
			'missing_recommended' => array(),
			'messages'            => array(),
		);
		$content_plain = isset( $context['content_plain'] ) ? strtolower( (string) $context['content_plain'] ) : '';
		$content_signals = self::content_signals( $context );

		switch ( $type ) {
			case 'FAQPage':
				$entities = isset( $node['mainEntity'] ) && is_array( $node['mainEntity'] ) ? $node['mainEntity'] : array();
				$faq_pair_count = isset( $content_signals['faq_pair_count'] ) ? (int) $content_signals['faq_pair_count'] : 0;
				if ( count( $entities ) < 2 || $faq_pair_count < 2 ) {
					$checks['missing_required'][] = 'visible_faq_pairs';
					$checks['messages'][] = __( 'FAQPage requires at least two visible question/answer pairs.', 'open-growth-seo' );
					$checks['status'] = 'blocked';
				} elseif ( $faq_pair_count < count( $entities ) ) {
					$checks['missing_recommended'][] = 'faq_visible_content_parity';
					$checks['messages'][] = __( 'Some configured FAQ items were not found in visible page content. Keep FAQ schema representative of what users can read.', 'open-growth-seo' );
					if ( 'valid' === $checks['status'] ) {
						$checks['status'] = 'warning';
					}
				}
				break;
			case 'Service':
				if ( ! self::has_field_value( $node, 'offers' ) && ! self::has_field_value( $node, 'hasOfferCatalog' ) ) {
					$checks['missing_recommended'][] = 'service_offer_context';
					$checks['messages'][] = __( 'Service should include an offer or offer catalog when the page sells or defines service packages.', 'open-growth-seo' );
					if ( 'valid' === $checks['status'] ) {
						$checks['status'] = 'warning';
					}
				}
				break;
			case 'OfferCatalog':
				$items = isset( $node['itemListElement'] ) && is_array( $node['itemListElement'] ) ? $node['itemListElement'] : array();
				if ( count( $items ) < 1 ) {
					$checks['missing_required'][] = 'catalog_items';
					$checks['messages'][] = __( 'OfferCatalog requires at least one visible offer entry.', 'open-growth-seo' );
					$checks['status'] = 'blocked';
				}
				break;
			case 'QAPage':
				$main_entity = isset( $node['mainEntity'] ) && is_array( $node['mainEntity'] ) ? $node['mainEntity'] : array();
				$question    = isset( $main_entity['name'] ) ? trim( (string) $main_entity['name'] ) : '';
				$answer      = isset( $main_entity['acceptedAnswer']['text'] ) ? trim( (string) $main_entity['acceptedAnswer']['text'] ) : '';
				$qa_pair_count = isset( $content_signals['qa_pair_count'] ) ? (int) $content_signals['qa_pair_count'] : 0;
				if ( '' === $question || '' === $answer || $qa_pair_count < 1 ) {
					$checks['missing_required'][] = 'qa_question_answer_structure';
					$checks['messages'][] = __( 'QAPage requires a question and accepted answer text.', 'open-growth-seo' );
					$checks['status'] = 'blocked';
				} elseif ( mb_strlen( wp_strip_all_tags( $answer ) ) < 30 ) {
					$checks['missing_recommended'][] = 'qa_answer_depth';
					$checks['messages'][] = __( 'Accepted answer is very short. Expand the answer so the page reads like a real Q&A destination.', 'open-growth-seo' );
					if ( 'valid' === $checks['status'] ) {
						$checks['status'] = 'warning';
					}
				}
				break;
			case 'ProfilePage':
				$entity_type = isset( $node['mainEntity']['@type'] ) ? (string) $node['mainEntity']['@type'] : '';
				if ( ! in_array( $entity_type, array( 'Person', 'Organization' ), true ) ) {
					$checks['missing_required'][] = 'profile_entity_type';
					$checks['messages'][] = __( 'ProfilePage mainEntity should be Person or Organization.', 'open-growth-seo' );
					$checks['status'] = 'blocked';
				}
				break;
			case 'AboutPage':
			case 'ContactPage':
			case 'CollectionPage':
				if ( ! self::has_field_value( $node, 'mainEntityOfPage' ) && ! self::has_field_value( $node, 'url' ) ) {
					$checks['missing_required'][] = 'page_identity';
					$checks['messages'][] = __( 'Page subtypes should keep a resolvable URL or mainEntityOfPage relationship.', 'open-growth-seo' );
					$checks['status'] = 'blocked';
				}
				break;
			case 'Person':
				if ( ! self::has_field_value( $node, 'worksFor' ) && ! self::has_field_value( $node, 'affiliation' ) ) {
					$checks['missing_recommended'][] = 'affiliation';
					$checks['messages'][] = __( 'Person should include affiliation or worksFor to strengthen institutional context.', 'open-growth-seo' );
					if ( 'valid' === $checks['status'] ) {
						$checks['status'] = 'warning';
					}
				}
				if ( ! self::has_field_value( $node, 'jobTitle' ) ) {
					$checks['missing_recommended'][] = 'jobtitle';
					$checks['messages'][] = __( 'Person should include a visible role such as professor, rector, or researcher.', 'open-growth-seo' );
					if ( 'valid' === $checks['status'] ) {
						$checks['status'] = 'warning';
					}
				}
				break;
			case 'Course':
				if ( str_word_count( wp_strip_all_tags( (string) ( $node['description'] ?? '' ) ) ) < 20 ) {
					$checks['missing_required'][] = 'course_description_depth';
					$checks['messages'][] = __( 'Course description appears too thin for reliable academic course markup.', 'open-growth-seo' );
					$checks['status'] = 'blocked';
				}
				break;
			case 'EducationalOccupationalProgram':
				if ( str_word_count( wp_strip_all_tags( (string) ( $node['description'] ?? '' ) ) ) < 30 ) {
					$checks['missing_required'][] = 'program_description_depth';
					$checks['messages'][] = __( 'EducationalOccupationalProgram description appears too thin for a degree or program landing page.', 'open-growth-seo' );
					$checks['status'] = 'blocked';
				}
				if ( ! self::has_field_value( $node, 'educationalCredentialAwarded' ) ) {
					$checks['missing_recommended'][] = 'educationalcredentialawarded';
					$checks['messages'][] = __( 'EducationalOccupationalProgram should state the credential awarded, such as licenciatura, bachelor, master, or doctorate.', 'open-growth-seo' );
					if ( 'valid' === $checks['status'] ) {
						$checks['status'] = 'warning';
					}
				}
				break;
			case 'DiscussionForumPosting':
				$body = isset( $node['articleBody'] ) ? trim( (string) $node['articleBody'] ) : '';
				if ( str_word_count( $body ) < 20 ) {
					$checks['missing_required'][] = 'discussion_body_context';
					$checks['messages'][] = __( 'DiscussionForumPosting needs enough visible discussion context.', 'open-growth-seo' );
					$checks['status'] = 'blocked';
				}
				break;
			case 'VideoObject':
				if ( ! self::has_field_value( $node, 'thumbnailUrl' ) ) {
					$checks['missing_recommended'][] = 'thumbnailUrl';
					$checks['messages'][] = __( 'VideoObject should include a thumbnail URL for stronger search compatibility.', 'open-growth-seo' );
					if ( 'valid' === $checks['status'] ) {
						$checks['status'] = 'warning';
					}
				}
				break;
			case 'Event':
				$start_date = isset( $node['startDate'] ) ? strtotime( (string) $node['startDate'] ) : false;
				if ( false !== $start_date && $start_date < strtotime( '-2 years' ) ) {
					$checks['missing_required'][] = 'current_event_state';
					$checks['messages'][] = __( 'Event appears too old for active event schema output.', 'open-growth-seo' );
					$checks['status'] = 'blocked';
				}
				if ( ! self::has_field_value( $node, 'location' ) ) {
					$checks['missing_recommended'][] = 'location';
					$checks['messages'][] = __( 'Event should include a location or attendance-mode details.', 'open-growth-seo' );
					if ( 'valid' === $checks['status'] ) {
						$checks['status'] = 'warning';
					}
				}
				break;
			case 'EventSeries':
				if ( ! self::has_field_value( $node, 'subEvent' ) ) {
					$checks['missing_recommended'][] = 'subEvent';
					$checks['messages'][] = __( 'EventSeries should describe at least one current or representative sub-event.', 'open-growth-seo' );
					if ( 'valid' === $checks['status'] ) {
						$checks['status'] = 'warning';
					}
				}
				break;
			case 'JobPosting':
				$description = isset( $node['description'] ) ? wp_strip_all_tags( (string) $node['description'] ) : '';
				if ( str_word_count( $description ) < 30 ) {
					$checks['missing_required'][] = 'employment_details';
					$checks['messages'][] = __( 'JobPosting description appears too thin for reliable job markup.', 'open-growth-seo' );
					$checks['status'] = 'blocked';
				}
				if ( ! self::has_field_value( $node, 'hiringOrganization' ) ) {
					$checks['missing_required'][] = 'hiringOrganization';
					$checks['messages'][] = __( 'JobPosting requires hiring organization details.', 'open-growth-seo' );
					$checks['status'] = 'blocked';
				}
				break;
			case 'Recipe':
				$ingredients = isset( $node['recipeIngredient'] ) && is_array( $node['recipeIngredient'] ) ? $node['recipeIngredient'] : array();
				$instructions = isset( $node['recipeInstructions'] ) && is_array( $node['recipeInstructions'] ) ? $node['recipeInstructions'] : array();
				$ingredient_count = isset( $content_signals['recipe_ingredient_count'] ) ? (int) $content_signals['recipe_ingredient_count'] : 0;
				$step_count       = isset( $content_signals['recipe_step_count'] ) ? (int) $content_signals['recipe_step_count'] : 0;
				if ( count( $ingredients ) < 2 || count( $instructions ) < 2 || $ingredient_count < 2 || $step_count < 2 ) {
					$checks['missing_required'][] = 'recipe_structure_depth';
					$checks['messages'][] = __( 'Recipe requires multiple ingredients and instruction steps.', 'open-growth-seo' );
					$checks['status'] = 'blocked';
				} elseif ( $ingredient_count < count( $ingredients ) || $step_count < count( $instructions ) ) {
					$checks['missing_recommended'][] = 'recipe_visible_parity';
					$checks['messages'][] = __( 'Recipe node contains more ingredients or steps than were confidently detected in visible content. Keep recipe schema aligned with the page.', 'open-growth-seo' );
					if ( 'valid' === $checks['status'] ) {
						$checks['status'] = 'warning';
					}
				}
				break;
			case 'Product':
				$offers = self::normalize_offer_rows( $node['offers'] ?? array() );
				if ( empty( $offers ) ) {
					$checks['missing_required'][] = 'merchant_offer_data';
					$checks['messages'][] = __( 'Product requires at least one valid offer row with merchant data.', 'open-growth-seo' );
					$checks['status'] = 'blocked';
					break;
				}
				$has_policy = false;
				foreach ( $offers as $offer ) {
					if ( ! self::offer_has_value( $offer, 'price' ) || ! self::offer_has_value( $offer, 'priceCurrency' ) ) {
						$checks['missing_required'][] = 'merchant_offer_pricing';
						$checks['messages'][] = __( 'Product offers need both price and price currency for reliable merchant-style output.', 'open-growth-seo' );
						$checks['status'] = 'blocked';
						break;
					}
					if ( ! self::offer_has_value( $offer, 'availability' ) ) {
						$checks['missing_recommended'][] = 'merchant_offer_availability';
						$checks['messages'][] = __( 'Product offers should include availability so archive and product detail output stays consistent.', 'open-growth-seo' );
						if ( 'valid' === $checks['status'] ) {
							$checks['status'] = 'warning';
						}
					}
					if ( self::offer_has_value( $offer, 'hasMerchantReturnPolicy' ) || self::offer_has_value( $offer, 'shippingDetails' ) ) {
						$has_policy = true;
					}
				}
				if ( ! $has_policy ) {
					$checks['missing_recommended'][] = 'merchant_offer_policy';
					$checks['messages'][] = __( 'Add merchant return or shipping policy details for stronger product markup and clearer ecommerce expectations.', 'open-growth-seo' );
					if ( 'valid' === $checks['status'] ) {
						$checks['status'] = 'warning';
					}
				}
				if ( str_word_count( wp_strip_all_tags( $content_plain ) ) < 60 ) {
					$checks['missing_recommended'][] = 'thin_product_content';
					$checks['messages'][] = __( 'Product page content appears thin. Strengthen visible product details before relying heavily on Product schema.', 'open-growth-seo' );
					if ( 'valid' === $checks['status'] ) {
						$checks['status'] = 'warning';
					}
				}
				break;
			case 'SoftwareApplication':
				if ( ! self::has_field_value( $node, 'operatingSystem' ) ) {
					$checks['missing_recommended'][] = 'operatingSystem';
					$checks['messages'][] = __( 'SoftwareApplication should include operating system/platform details.', 'open-growth-seo' );
					if ( 'valid' === $checks['status'] ) {
						$checks['status'] = 'warning';
					}
				}
				break;
			case 'WebAPI':
				if ( ! self::has_field_value( $node, 'documentation' ) ) {
					$checks['missing_recommended'][] = 'documentation';
					$checks['messages'][] = __( 'WebAPI should include a documentation URL.', 'open-growth-seo' );
					if ( 'valid' === $checks['status'] ) {
						$checks['status'] = 'warning';
					}
				}
				break;
			case 'Project':
				if ( str_word_count( wp_strip_all_tags( (string) ( $node['description'] ?? '' ) ) ) < 20 ) {
					$checks['missing_required'][] = 'project_description_depth';
					$checks['messages'][] = __( 'Project description appears too thin for reliable project markup.', 'open-growth-seo' );
					$checks['status'] = 'blocked';
				}
				break;
			case 'Review':
				if ( ! self::has_field_value( $node, 'author' ) ) {
					$checks['missing_recommended'][] = 'author';
					$checks['messages'][] = __( 'Review should identify an author or reviewer.', 'open-growth-seo' );
					if ( 'valid' === $checks['status'] ) {
						$checks['status'] = 'warning';
					}
				}
				break;
			case 'Guide':
				$steps = isset( $node['step'] ) && is_array( $node['step'] ) ? $node['step'] : array();
				if ( count( $steps ) < 2 ) {
					$checks['missing_required'][] = 'guide_steps';
					$checks['messages'][] = __( 'Guide requires at least two clear steps.', 'open-growth-seo' );
					$checks['status'] = 'blocked';
				}
				break;
			case 'Dataset':
				if ( ! self::has_field_value( $node, 'url' ) && ! self::has_field_value( $node, 'identifier' ) ) {
					$checks['missing_required'][] = 'dataset_identifier';
					$checks['messages'][] = __( 'Dataset should include a stable URL or identifier.', 'open-growth-seo' );
					$checks['status'] = 'blocked';
				}
				break;
			case 'ScholarlyArticle':
				if ( str_word_count( wp_strip_all_tags( (string) ( $node['description'] ?? '' ) ) ) < 25 ) {
					$checks['missing_recommended'][] = 'scholarly_summary';
					$checks['messages'][] = __( 'ScholarlyArticle should expose a visible abstract or summary to avoid shallow publication markup.', 'open-growth-seo' );
					if ( 'valid' === $checks['status'] ) {
						$checks['status'] = 'warning';
					}
				}
				if ( ! self::has_field_value( $node, 'isPartOf' ) ) {
					$checks['missing_recommended'][] = 'publication_container';
					$checks['messages'][] = __( 'ScholarlyArticle should reference the journal, repository, or publication container when known.', 'open-growth-seo' );
					if ( 'valid' === $checks['status'] ) {
						$checks['status'] = 'warning';
					}
				}
				break;
			case 'DefinedTerm':
				if ( ! self::has_field_value( $node, 'inDefinedTermSet' ) ) {
					$checks['missing_recommended'][] = 'inDefinedTermSet';
					$checks['messages'][] = __( 'DefinedTerm should reference the glossary or term set when known.', 'open-growth-seo' );
					if ( 'valid' === $checks['status'] ) {
						$checks['status'] = 'warning';
					}
				}
				break;
			case 'DefinedTermSet':
				if ( ! self::has_field_value( $node, 'hasDefinedTerm' ) ) {
					$checks['missing_recommended'][] = 'hasDefinedTerm';
					$checks['messages'][] = __( 'DefinedTermSet should expose at least one representative term.', 'open-growth-seo' );
					if ( 'valid' === $checks['status'] ) {
						$checks['status'] = 'warning';
					}
				}
				break;
			case 'LocalBusiness':
				$address = isset( $node['address'] ) && is_array( $node['address'] ) ? $node['address'] : array();
				$street  = isset( $address['streetAddress'] ) ? trim( (string) $address['streetAddress'] ) : '';
				$city    = isset( $address['addressLocality'] ) ? trim( (string) $address['addressLocality'] ) : '';
				$country = isset( $address['addressCountry'] ) ? trim( (string) $address['addressCountry'] ) : '';
				$location_record = isset( $context['location_record'] ) && is_array( $context['location_record'] ) ? $context['location_record'] : array();
				$service_mode = sanitize_key( (string) ( $location_record['service_mode'] ?? 'storefront' ) );
				if ( '' === $street || '' === $city || '' === $country ) {
					$checks['missing_required'][] = 'complete_location_address';
					$checks['messages'][] = __( 'LocalBusiness needs street, city, and country fields.', 'open-growth-seo' );
					$checks['status'] = 'blocked';
				}
				if ( 'storefront' === $service_mode && ! self::has_field_value( $node, 'openingHoursSpecification' ) ) {
					$checks['missing_recommended'][] = 'storefront_opening_hours';
					$checks['messages'][] = __( 'Storefront locations should publish opening hours so local markup reflects real visitability.', 'open-growth-seo' );
					if ( 'valid' === $checks['status'] ) {
						$checks['status'] = 'warning';
					}
				}
				if ( in_array( $service_mode, array( 'service_area', 'hybrid' ), true ) && ! self::has_field_value( $node, 'areaServed' ) ) {
					$checks['missing_recommended'][] = 'service_area_coverage';
					$checks['messages'][] = __( 'Service-area locations should describe the areas served to avoid weak or misleading LocalBusiness output.', 'open-growth-seo' );
					if ( 'valid' === $checks['status'] ) {
						$checks['status'] = 'warning';
					}
				}
				if ( ! empty( $location_record['legacy_migrated'] ) && 'published' === (string) ( $location_record['status'] ?? 'draft' ) ) {
					$checks['missing_recommended'][] = 'legacy_location_review';
					$checks['messages'][] = __( 'This location still relies on legacy-migrated data. Review the row before treating it as a premium local landing page.', 'open-growth-seo' );
					if ( 'valid' === $checks['status'] ) {
						$checks['status'] = 'warning';
					}
				}
				break;
		}

		if ( in_array( $type, array( 'FAQPage', 'QAPage', 'Recipe' ), true ) ) {
			if ( ! empty( $content_signals['has_hidden_content'] ) ) {
				$checks['missing_recommended'][] = 'visible_content_parity';
				$checks['messages'][] = __( 'Some structured-data content may be hidden. Keep schema aligned with visible page content.', 'open-growth-seo' );
				if ( 'valid' === $checks['status'] ) {
					$checks['status'] = 'warning';
				}
			}
		}

		return $checks;
	}

	/**
	 * @param array<string, mixed> $node
	 */
	private static function has_field_value( array $node, string $field ): bool {
		if ( ! array_key_exists( $field, $node ) ) {
			return false;
		}
		$value = $node[ $field ];
		if ( is_array( $value ) ) {
			return ! empty( $value );
		}
		if ( is_string( $value ) ) {
			return '' !== trim( $value );
		}
		return null !== $value;
	}

	/**
	 * @param array<string, mixed> $context
	 * @return array<string, mixed>
	 */
	private static function content_signals( array $context ): array {
		if ( isset( $context['content_signals'] ) && is_array( $context['content_signals'] ) ) {
			return $context['content_signals'];
		}
		$content = isset( $context['content_raw'] ) ? (string) $context['content_raw'] : (string) ( $context['content_plain'] ?? '' );
		return ContentSignals::analyze( $content );
	}

	/**
	 * @param mixed $offers
	 * @return array<int, array<string, mixed>>
	 */
	private static function normalize_offer_rows( $offers ): array {
		if ( ! is_array( $offers ) ) {
			return array();
		}
		if ( isset( $offers['@type'] ) || isset( $offers['price'] ) || isset( $offers['priceCurrency'] ) ) {
			return array( $offers );
		}
		$rows = array();
		foreach ( $offers as $row ) {
			if ( is_array( $row ) ) {
				$rows[] = $row;
			}
		}
		return $rows;
	}

	/**
	 * @param array<string, mixed> $offer
	 */
	private static function offer_has_value( array $offer, string $key ): bool {
		if ( ! array_key_exists( $key, $offer ) ) {
			return false;
		}
		$value = $offer[ $key ];
		if ( is_array( $value ) ) {
			return ! empty( $value );
		}
		return '' !== trim( (string) $value );
	}

	/**
	 * @param array<int, string> $missing_required
	 * @param array<int, string> $missing_recommended
	 * @param array<int, string> $messages
	 * @param array<string, mixed> $record
	 * @return array<string, mixed>
	 */
	private static function report( string $type, string $status, array $missing_required, array $missing_recommended, array $messages, array $record = array() ): array {
		$support = isset( $record['support'] ) ? (string) $record['support'] : SupportMatrix::SUPPORT_BLOCKED;
		$risk    = isset( $record['risk'] ) ? (string) $record['risk'] : 'high';
		return array(
			'type'                => $type,
			'status'              => sanitize_key( $status ),
			'missing_required'    => array_values( array_map( 'sanitize_key', $missing_required ) ),
			'missing_recommended' => array_values( array_map( 'sanitize_key', $missing_recommended ) ),
			'messages'            => array_values( array_map( 'sanitize_text_field', $messages ) ),
			'support'             => $support,
			'support_label'       => SupportMatrix::support_badge( $support ),
			'risk'                => $risk,
			'risk_label'          => SupportMatrix::risk_badge( $risk ),
			'google_supported'    => SupportMatrix::SUPPORT_GOOGLE === $support,
		);
	}
}
