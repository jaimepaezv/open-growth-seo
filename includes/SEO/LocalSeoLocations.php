<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\SEO;

use OpenGrowthSolutions\OpenGrowthSEO\Schema\ContentSignals;
use OpenGrowthSolutions\OpenGrowthSEO\Support\Settings;

defined( 'ABSPATH' ) || exit;

class LocalSeoLocations {
	private const MAX_LOCATIONS = 50;

	private const REQUIRED_FIELDS = array(
		'name',
		'phone',
		'street',
		'city',
		'country',
		'landing_page_id',
	);

	private const ALLOWED_STATUSES = array( 'published', 'draft' );
	private const ALLOWED_SERVICE_MODE = array( 'storefront', 'service_area', 'hybrid' );
	private const ALLOWED_BUSINESS_TYPES = array(
		'LocalBusiness',
		'AutomotiveBusiness',
		'ChildCare',
		'Dentist',
		'DryCleaningOrLaundry',
		'Electrician',
		'EmergencyService',
		'EmploymentAgency',
		'EntertainmentBusiness',
		'FinancialService',
		'FoodEstablishment',
		'GovernmentOffice',
		'HealthAndBeautyBusiness',
		'HomeAndConstructionBusiness',
		'InternetCafe',
		'LegalService',
		'Library',
		'LodgingBusiness',
		'MedicalBusiness',
		'MedicalClinic',
		'Physician',
		'MovingCompany',
		'Notary',
		'ProfessionalService',
		'RadioStation',
		'RealEstateAgent',
		'RecyclingCenter',
		'SelfStorage',
		'ShoppingCenter',
		'SportsActivityLocation',
		'Store',
		'TouristInformationCenter',
		'TravelAgency',
	);

	/**
	 * @var array<string, string>
	 */
	private const DAY_MAP = array(
		'mo' => 'Monday',
		'tu' => 'Tuesday',
		'we' => 'Wednesday',
		'th' => 'Thursday',
		'fr' => 'Friday',
		'sa' => 'Saturday',
		'su' => 'Sunday',
	);

	public function register(): void {
		add_filter( 'ogs_seo_audit_checks', array( $this, 'register_audit_checks' ) );
	}

	public function register_audit_checks( array $checks ): array {
		$checks['local_location_health'] = array( $this, 'audit_checks' );
		return $checks;
	}

	/**
	 * @return array<int, string>
	 */
	public static function business_type_options(): array {
		return self::ALLOWED_BUSINESS_TYPES;
	}

	public function audit_checks(): array {
		$settings = Settings::get_all();
		if ( empty( $settings['schema_local_business_enabled'] ) ) {
			return array();
		}

		$diagnostics = self::diagnostics( $settings );
		$issues      = array();

		foreach ( array_slice( (array) $diagnostics['errors'], 0, 8 ) as $error ) {
			if ( ! is_array( $error ) ) {
				continue;
			}
			$issues[] = array(
				'severity'       => 'important',
				'title'          => __( 'Local SEO location data requires attention', 'open-growth-seo' ),
				'detail'         => (string) ( $error['message'] ?? __( 'A local location configuration issue was detected.', 'open-growth-seo' ) ),
				'recommendation' => __( 'Complete required location data and keep one canonical landing page per real location.', 'open-growth-seo' ),
				'source'         => 'local',
				'trace'          => array(
					'location_id' => (string) ( $error['location_id'] ?? '' ),
					'code'        => (string) ( $error['code'] ?? '' ),
				),
			);
		}

		foreach ( array_slice( (array) $diagnostics['warnings'], 0, 8 ) as $warning ) {
			if ( ! is_array( $warning ) ) {
				continue;
			}
			$issues[] = array(
				'severity'       => 'minor',
				'title'          => __( 'Local SEO location warning', 'open-growth-seo' ),
				'detail'         => (string) ( $warning['message'] ?? __( 'A local location warning was detected.', 'open-growth-seo' ) ),
				'recommendation' => __( 'Review this location row and keep local data complete, unique, and page-specific.', 'open-growth-seo' ),
				'source'         => 'local',
				'trace'          => array(
					'location_id' => (string) ( $warning['location_id'] ?? '' ),
					'code'        => (string) ( $warning['code'] ?? '' ),
				),
			);
		}

		return $issues;
	}

	/**
	 * @param mixed $raw
	 * @return array<int, array<string, mixed>>
	 */
	public static function sanitize_records( $raw ): array {
		$rows = $raw;
		if ( is_string( $rows ) ) {
			$decoded = json_decode( $rows, true );
			$rows    = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		$clean = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$status = sanitize_key( (string) ( $row['status'] ?? 'draft' ) );
			if ( ! in_array( $status, self::ALLOWED_STATUSES, true ) ) {
				$status = 'draft';
			}

			$landing_page_id = absint( $row['landing_page_id'] ?? 0 );
			if ( $landing_page_id <= 0 && ! empty( $row['landing_url'] ) && function_exists( 'url_to_postid' ) ) {
				$landing_page_id = absint( url_to_postid( (string) $row['landing_url'] ) );
			}

			$name    = sanitize_text_field( (string) ( $row['name'] ?? '' ) );
			$phone   = sanitize_text_field( (string) ( $row['phone'] ?? '' ) );
			$street  = sanitize_text_field( (string) ( $row['street'] ?? '' ) );
			$city    = sanitize_text_field( (string) ( $row['city'] ?? '' ) );
			$region  = sanitize_text_field( (string) ( $row['region'] ?? '' ) );
			$postal  = sanitize_text_field( (string) ( $row['postal_code'] ?? '' ) );
			$country = strtoupper( sanitize_text_field( (string) ( $row['country'] ?? '' ) ) );

			$all_empty = '' === $name
				&& '' === $phone
				&& '' === $street
				&& '' === $city
				&& '' === $region
				&& '' === $postal
				&& '' === $country
				&& $landing_page_id <= 0
				&& '' === trim( sanitize_textarea_field( (string) ( $row['opening_hours'] ?? '' ) ) )
				&& '' === trim( sanitize_text_field( (string) ( $row['seo_title'] ?? '' ) ) )
				&& '' === trim( sanitize_textarea_field( (string) ( $row['seo_description'] ?? '' ) ) )
				&& '' === trim( esc_url_raw( (string) ( $row['seo_canonical'] ?? '' ) ) );
			if ( $all_empty ) {
				continue;
			}

			$service_mode = sanitize_key( (string) ( $row['service_mode'] ?? 'storefront' ) );
			if ( ! in_array( $service_mode, self::ALLOWED_SERVICE_MODE, true ) ) {
				$service_mode = 'storefront';
			}

			$raw_lat = sanitize_text_field( (string) ( $row['latitude'] ?? '' ) );
			$raw_lng = sanitize_text_field( (string) ( $row['longitude'] ?? '' ) );
			$lat     = self::sanitize_coordinate_axis( $raw_lat, 'lat' );
			$lng     = self::sanitize_coordinate_axis( $raw_lng, 'lng' );
			$geo_invalid = ( '' !== trim( $raw_lat ) && '' === $lat ) || ( '' !== trim( $raw_lng ) && '' === $lng );

			$business_type_raw = sanitize_text_field( (string) ( $row['business_type'] ?? 'LocalBusiness' ) );
			$business_type     = self::sanitize_business_type( $business_type_raw );
			$business_type_invalid = '' !== trim( $business_type_raw ) && ! self::is_allowed_business_type( $business_type_raw );

			$id = sanitize_key( (string) ( $row['id'] ?? '' ) );
			if ( '' === $id ) {
				$id_seed = '' !== $name ? $name : 'location-' . (string) ( count( $clean ) + 1 );
				if ( function_exists( 'sanitize_title' ) ) {
					$id = sanitize_key( sanitize_title( $id_seed ) );
				} else {
					$id = sanitize_key( strtolower( preg_replace( '/[^a-zA-Z0-9]+/', '-', $id_seed ) ) );
				}
			}
			if ( '' === $id ) {
				$id = 'location-' . (string) ( count( $clean ) + 1 );
			}

			$canonical = esc_url_raw( (string) ( $row['seo_canonical'] ?? '' ) );
			if ( '' !== $canonical && str_starts_with( $canonical, '/' ) ) {
				$canonical = esc_url_raw( home_url( $canonical ) );
			}

			$clean[] = array(
				'id'                => $id,
				'status'            => $status,
				'name'              => $name,
				'business_type'     => $business_type,
				'landing_page_id'   => $landing_page_id,
				'phone'             => $phone,
				'street'            => $street,
				'city'              => $city,
				'region'            => $region,
				'postal_code'       => $postal,
				'country'           => $country,
				'latitude'          => $lat,
				'longitude'         => $lng,
				'opening_hours'     => sanitize_textarea_field( (string) ( $row['opening_hours'] ?? '' ) ),
				'department_name'   => sanitize_text_field( (string) ( $row['department_name'] ?? '' ) ),
				'service_mode'      => $service_mode,
				'service_areas'     => sanitize_text_field( (string) ( $row['service_areas'] ?? '' ) ),
				'seo_title'         => sanitize_text_field( (string) ( $row['seo_title'] ?? '' ) ),
				'seo_description'   => sanitize_textarea_field( (string) ( $row['seo_description'] ?? '' ) ),
				'seo_canonical'     => $canonical,
				'legacy_migrated'   => ! empty( $row['legacy_migrated'] ) ? 1 : 0,
				'_geo_invalid'      => $geo_invalid ? 1 : 0,
				'_business_type_invalid' => $business_type_invalid ? 1 : 0,
			);
		}

		$clean = array_slice( array_values( $clean ), 0, self::MAX_LOCATIONS );
		$seen  = array();
		foreach ( $clean as &$row ) {
			$id = (string) ( $row['id'] ?? '' );
			if ( '' === $id ) {
				$id = 'location-' . (string) ( count( $seen ) + 1 );
			}
			$base = $id;
			$inc  = 2;
			while ( isset( $seen[ $id ] ) ) {
				$id = $base . '-' . $inc;
				++$inc;
			}
			$seen[ $id ] = true;
			$row['id']   = $id;
		}
		unset( $row );

		return $clean;
	}

	/**
	 * @param array<string, mixed> $settings
	 * @return array<int, array<string, mixed>>
	 */
	public static function records( array $settings = array() ): array {
		if ( empty( $settings ) ) {
			$settings = Settings::get_all();
		}
		$records = self::sanitize_records( $settings['local_locations'] ?? array() );
		if ( ! empty( $records ) ) {
			return $records;
		}

		$legacy = self::legacy_record_from_settings( $settings );
		return empty( $legacy ) ? array() : array( $legacy );
	}

	/**
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	public static function record_for_post( int $post_id, array $settings = array() ): array {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 ) {
			return array();
		}
		foreach ( self::records( $settings ) as $record ) {
			if ( $post_id === absint( $record['landing_page_id'] ?? 0 ) ) {
				return $record;
			}
		}
		return array();
	}

	/**
	 * @param array<string, mixed> $record
	 */
	public static function is_complete( array $record ): bool {
		foreach ( self::REQUIRED_FIELDS as $field ) {
			$value = $record[ $field ] ?? '';
			if ( 'landing_page_id' === $field ) {
				if ( absint( $value ) <= 0 ) {
					return false;
				}
				continue;
			}
			if ( '' === trim( (string) $value ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param array<string, mixed> $record
	 */
	public static function is_publishable( array $record ): bool {
		$page_id = absint( $record['landing_page_id'] ?? 0 );
		return 'published' === (string) ( $record['status'] ?? 'draft' ) && self::is_complete( $record ) && self::landing_page_is_published( $page_id );
	}

	/**
	 * @param array<string, mixed> $context
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	public static function schema_candidate_for_context( array $context, array $settings ): array {
		$post_id = absint( $context['post_id'] ?? 0 );
		if ( $post_id <= 0 ) {
			return array(
				'node'     => array(),
				'warnings' => array(),
			);
		}

		$record = self::record_for_post( $post_id, $settings );
		if ( empty( $record ) ) {
			return array(
				'node'     => array(),
				'warnings' => array(),
			);
		}

		$warnings = array();
		if ( ! self::is_publishable( $record ) ) {
			$warnings[] = __( 'LocalBusiness schema skipped for this page because the location record is incomplete or not published.', 'open-growth-seo' );
			return array(
				'node'     => array(),
				'warnings' => $warnings,
			);
		}

		$location_url = self::location_url( $record );
		$context_url  = self::normalize_url( (string) ( $context['url'] ?? '' ) );
		if ( '' !== $location_url && '' !== $context_url && $location_url !== $context_url ) {
			$warnings[] = __( 'LocalBusiness schema skipped because current URL does not match the location landing page URL.', 'open-growth-seo' );
			return array(
				'node'     => array(),
				'warnings' => $warnings,
			);
		}

		if ( ! self::location_has_substantive_visible_content( $record ) ) {
			$warnings[] = __( 'LocalBusiness schema skipped because location page content is too thin to represent this location reliably.', 'open-growth-seo' );
			return array(
				'node'     => array(),
				'warnings' => $warnings,
			);
		}

		$node = self::build_schema_node( $record, $settings );
		return array(
			'node'     => $node,
			'warnings' => $warnings,
		);
	}

	/**
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	public static function diagnostics( array $settings = array() ): array {
		$records   = self::records( $settings );
		$errors    = array();
		$warnings  = array();
		$seen_page = array();
		$seen_nap  = array();

		foreach ( $records as $record ) {
			$location_id = (string) ( $record['id'] ?? '' );
			$page_id     = absint( $record['landing_page_id'] ?? 0 );
			$name        = (string) ( $record['name'] ?? '' );
			$status      = (string) ( $record['status'] ?? 'draft' );
			$country     = strtoupper( trim( (string) ( $record['country'] ?? '' ) ) );

			if ( 'published' === $status && ! self::is_complete( $record ) ) {
				$errors[] = array(
					'code'        => 'incomplete_published_location',
					'location_id' => $location_id,
					'message'     => sprintf(
						/* translators: %s: location name */
						__( 'Location "%s" is marked published but missing required business data.', 'open-growth-seo' ),
						'' !== $name ? $name : __( 'Untitled', 'open-growth-seo' )
					),
				);
			}

			if ( $page_id > 0 ) {
				if ( isset( $seen_page[ $page_id ] ) ) {
					$errors[] = array(
						'code'        => 'duplicate_landing_page',
						'location_id' => $location_id,
						'message'     => __( 'More than one location record points to the same landing page.', 'open-growth-seo' ),
					);
				}
				$seen_page[ $page_id ] = true;

				$post_status = function_exists( 'get_post_status' ) ? (string) get_post_status( $page_id ) : 'publish';
				if ( 'published' === $status && '' !== $post_status && 'publish' !== $post_status ) {
					$errors[] = array(
						'code'        => 'published_location_unpublished_page',
						'location_id' => $location_id,
						'message'     => __( 'Location is marked published but its landing page is not published.', 'open-growth-seo' ),
					);
				} elseif ( '' !== $post_status && 'publish' !== $post_status ) {
					$warnings[] = array(
						'code'        => 'landing_not_published',
						'location_id' => $location_id,
						'message'     => __( 'This location points to an unpublished landing page. Keep status as draft until the page is publish-ready.', 'open-growth-seo' ),
					);
				}
			}

			if ( ! empty( $record['_business_type_invalid'] ) ) {
				$warnings[] = array(
					'code'        => 'invalid_business_type',
					'location_id' => $location_id,
					'message'     => __( 'Business type was invalid and has been normalized to LocalBusiness.', 'open-growth-seo' ),
				);
			}

			if ( ! empty( $record['_geo_invalid'] ) ) {
				$warnings[] = array(
					'code'        => 'invalid_geo_coordinates',
					'location_id' => $location_id,
					'message'     => __( 'Latitude/longitude values were invalid and have been cleared. Use numeric coordinates (lat: -90..90, lng: -180..180).', 'open-growth-seo' ),
				);
			}

			if ( 'published' === $status && ! preg_match( '/^[A-Z]{2}$/', $country ) ) {
				$warnings[] = array(
					'code'        => 'invalid_country_code',
					'location_id' => $location_id,
					'message'     => __( 'Published location should use a valid two-letter country code (ISO 3166-1 alpha-2).', 'open-growth-seo' ),
				);
			}

			$nap = self::nap_signature( $record );
			if ( '' !== $nap ) {
				if ( isset( $seen_nap[ $nap ] ) ) {
					$warnings[] = array(
						'code'        => 'duplicate_nap',
						'location_id' => $location_id,
						'message'     => __( 'Duplicate NAP signature detected across location records.', 'open-growth-seo' ),
					);
				}
				$seen_nap[ $nap ] = true;
			}

			$hours_validation = self::validate_opening_hours( (string) ( $record['opening_hours'] ?? '' ) );
			if ( ! empty( $hours_validation['invalid_count'] ) ) {
				$warnings[] = array(
					'code'        => 'invalid_opening_hours',
					'location_id' => $location_id,
					'message'     => __( 'Opening hours include invalid lines. Use format like "Mo-Fr 09:00-17:00".', 'open-growth-seo' ),
				);
			}
			if ( 'published' === $status && 'storefront' === sanitize_key( (string) ( $record['service_mode'] ?? 'storefront' ) ) && empty( $hours_validation['total'] ) ) {
				$warnings[] = array(
					'code'        => 'missing_storefront_hours',
					'location_id' => $location_id,
					'message'     => __( 'Published storefront locations should include opening hours so the local landing page describes real visitability.', 'open-growth-seo' ),
				);
			}

			$service_mode = sanitize_key( (string) ( $record['service_mode'] ?? 'storefront' ) );
			$service_areas = self::split_comma_values( (string) ( $record['service_areas'] ?? '' ) );
			if ( in_array( $service_mode, array( 'service_area', 'hybrid' ), true ) && empty( $service_areas ) ) {
				$warnings[] = array(
					'code'        => 'missing_service_areas',
					'location_id' => $location_id,
					'message'     => __( 'Service-area mode is enabled but no service areas were provided.', 'open-growth-seo' ),
				);
			}
			if ( 'storefront' === $service_mode && ! empty( $service_areas ) ) {
				$warnings[] = array(
					'code'        => 'storefront_with_service_areas',
					'location_id' => $location_id,
					'message'     => __( 'Storefront mode has service areas configured. Use hybrid/service-area mode if this location serves off-site areas.', 'open-growth-seo' ),
				);
			}

			$lat = (string) ( $record['latitude'] ?? '' );
			$lng = (string) ( $record['longitude'] ?? '' );
			if ( ( '' !== $lat && '' === $lng ) || ( '' === $lat && '' !== $lng ) ) {
				$warnings[] = array(
					'code'        => 'partial_geo',
					'location_id' => $location_id,
					'message'     => __( 'Geo coordinates are incomplete. Provide both latitude and longitude or leave both empty.', 'open-growth-seo' ),
				);
			}

			$seo_canonical = self::normalize_url( (string) ( $record['seo_canonical'] ?? '' ) );
			$location_url  = self::location_url( $record );
			if ( '' !== $seo_canonical && '' !== $location_url && $seo_canonical !== $location_url ) {
				$warnings[] = array(
					'code'        => 'location_canonical_mismatch',
					'location_id' => $location_id,
					'message'     => __( 'Per-location canonical override does not match the location landing URL.', 'open-growth-seo' ),
				);
			}

			if ( $page_id > 0 && ! self::location_has_substantive_visible_content( $record ) ) {
				$warnings[] = array(
					'code'        => 'thin_location_page',
					'location_id' => $location_id,
					'message'     => __( 'Location landing page content appears thin. Add unique, location-specific visible content before treating it as a full SEO target.', 'open-growth-seo' ),
				);
			}
			if ( $page_id > 0 && 'published' === $status && 'noindex' === (string) get_post_meta( $page_id, 'ogs_seo_index', true ) ) {
				$warnings[] = array(
					'code'        => 'location_page_noindex',
					'location_id' => $location_id,
					'message'     => __( 'Published location landing page is marked noindex. LocalBusiness schema should usually point to an indexable landing page.', 'open-growth-seo' ),
				);
			}
			if ( 'published' === $status && ! empty( $record['legacy_migrated'] ) ) {
				$warnings[] = array(
					'code'        => 'legacy_location_review',
					'location_id' => $location_id,
					'message'     => __( 'This location still uses legacy-migrated data. Review the row before relying on it as a primary local SEO target.', 'open-growth-seo' ),
				);
			}
		}

		return array(
			'records'   => $records,
			'errors'    => $errors,
			'warnings'  => $warnings,
			'count'     => count( $records ),
			'errorCount' => count( $errors ),
			'warningCount' => count( $warnings ),
		);
	}

	/**
	 * @param array<string, mixed> $record
	 */
	public static function location_url( array $record ): string {
		$page_id = absint( $record['landing_page_id'] ?? 0 );
		if ( $page_id > 0 && function_exists( 'get_permalink' ) ) {
			$url = get_permalink( $page_id );
			if ( is_string( $url ) && '' !== trim( $url ) ) {
				return self::normalize_url( $url );
			}
		}
		return '';
	}

	/**
	 * @param array<string, mixed> $record
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	public static function build_schema_node( array $record, array $settings = array() ): array {
		$type = self::sanitize_business_type( (string) ( $record['business_type'] ?? 'LocalBusiness' ) );
		$url  = self::location_url( $record );
		$node = array(
			'@type'              => $type,
			'@id'                => '' !== $url ? $url . '#localbusiness' : home_url( '/#localbusiness' ),
			'name'               => sanitize_text_field( (string) ( $record['name'] ?? '' ) ),
			'url'                => '' !== $url ? $url : home_url( '/' ),
			'telephone'          => sanitize_text_field( (string) ( $record['phone'] ?? '' ) ),
			'parentOrganization' => array( '@id' => home_url( '/#organization' ) ),
			'address'            => array(
				'@type'           => 'PostalAddress',
				'streetAddress'   => sanitize_text_field( (string) ( $record['street'] ?? '' ) ),
				'addressLocality' => sanitize_text_field( (string) ( $record['city'] ?? '' ) ),
				'addressRegion'   => sanitize_text_field( (string) ( $record['region'] ?? '' ) ),
				'postalCode'      => sanitize_text_field( (string) ( $record['postal_code'] ?? '' ) ),
				'addressCountry'  => sanitize_text_field( (string) ( $record['country'] ?? '' ) ),
			),
		);

		$lat = (string) ( $record['latitude'] ?? '' );
		$lng = (string) ( $record['longitude'] ?? '' );
		if ( '' !== $lat && '' !== $lng ) {
			$node['geo'] = array(
				'@type'     => 'GeoCoordinates',
				'latitude'  => $lat,
				'longitude' => $lng,
			);
		}

		$hours = self::opening_hours_specifications( (string) ( $record['opening_hours'] ?? '' ) );
		if ( ! empty( $hours ) ) {
			$node['openingHoursSpecification'] = $hours;
		}

		$department = sanitize_text_field( (string) ( $record['department_name'] ?? '' ) );
		if ( '' !== $department ) {
			$node['department'] = array(
				'@type' => 'LocalBusiness',
				'name'  => $department,
			);
		}

		$service_mode = sanitize_key( (string) ( $record['service_mode'] ?? 'storefront' ) );
		if ( in_array( $service_mode, array( 'service_area', 'hybrid' ), true ) ) {
			$areas = self::split_comma_values( (string) ( $record['service_areas'] ?? '' ) );
			if ( ! empty( $areas ) ) {
				$node['areaServed'] = $areas;
			}
		}

		return $node;
	}

	private static function sanitize_coordinate_axis( $value, string $axis ): string {
		$value = trim( sanitize_text_field( (string) $value ) );
		if ( '' === $value ) {
			return '';
		}
		if ( ! preg_match( '/^-?\d{1,3}(?:\.\d+)?$/', $value ) ) {
			return '';
		}
		$float = (float) $value;
		if ( 'lat' === $axis && ( $float < -90 || $float > 90 ) ) {
			return '';
		}
		if ( 'lng' === $axis && ( $float < -180 || $float > 180 ) ) {
			return '';
		}
		return (string) $float;
	}

	private static function sanitize_business_type( string $value ): string {
		$value = self::normalize_business_type( $value );
		if ( '' === $value ) {
			return 'LocalBusiness';
		}
		foreach ( self::ALLOWED_BUSINESS_TYPES as $type ) {
			if ( strtolower( $type ) === strtolower( $value ) ) {
				return $type;
			}
		}
		return 'LocalBusiness';
	}

	private static function is_allowed_business_type( string $value ): bool {
		$value = self::normalize_business_type( $value );
		if ( '' === $value ) {
			return false;
		}
		foreach ( self::ALLOWED_BUSINESS_TYPES as $type ) {
			if ( strtolower( $type ) === strtolower( $value ) ) {
				return true;
			}
		}
		return false;
	}

	private static function normalize_business_type( string $value ): string {
		return trim( preg_replace( '/[^A-Za-z]/', '', sanitize_text_field( $value ) ) );
	}

	private static function landing_page_is_published( int $page_id ): bool {
		if ( $page_id <= 0 ) {
			return false;
		}
		if ( ! function_exists( 'get_post_status' ) ) {
			return true;
		}
		$status = (string) get_post_status( $page_id );
		return '' === $status || 'publish' === $status;
	}

	/**
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	private static function legacy_record_from_settings( array $settings ): array {
		$has_legacy = ! empty( $settings['schema_local_phone'] )
			|| ! empty( $settings['schema_local_street'] )
			|| ! empty( $settings['schema_local_city'] )
			|| ! empty( $settings['schema_local_country'] );
		if ( ! $has_legacy ) {
			return array();
		}

		$front_page = absint( get_option( 'page_on_front', 0 ) );
		$status     = $front_page > 0 ? 'published' : 'draft';

		return array(
			'id'              => 'legacy-main-location',
			'status'          => $status,
			'name'            => sanitize_text_field( (string) ( $settings['schema_org_name'] ?? get_bloginfo( 'name' ) ) ),
			'business_type'   => self::sanitize_business_type( (string) ( $settings['schema_local_business_type'] ?? 'LocalBusiness' ) ),
			'landing_page_id' => $front_page,
			'phone'           => sanitize_text_field( (string) ( $settings['schema_local_phone'] ?? '' ) ),
			'street'          => sanitize_text_field( (string) ( $settings['schema_local_street'] ?? '' ) ),
			'city'            => sanitize_text_field( (string) ( $settings['schema_local_city'] ?? '' ) ),
			'region'          => sanitize_text_field( (string) ( $settings['schema_local_region'] ?? '' ) ),
			'postal_code'     => sanitize_text_field( (string) ( $settings['schema_local_postal_code'] ?? '' ) ),
			'country'         => strtoupper( sanitize_text_field( (string) ( $settings['schema_local_country'] ?? '' ) ) ),
			'latitude'        => '',
			'longitude'       => '',
			'opening_hours'   => '',
			'department_name' => '',
			'service_mode'    => 'storefront',
			'service_areas'   => '',
			'seo_title'       => '',
			'seo_description' => '',
			'seo_canonical'   => '',
			'legacy_migrated' => 1,
		);
	}

	/**
	 * @param array<string, mixed> $record
	 */
	private static function nap_signature( array $record ): string {
		$parts = array(
			strtolower( trim( (string) ( $record['name'] ?? '' ) ) ),
			preg_replace( '/\D+/', '', (string) ( $record['phone'] ?? '' ) ),
			strtolower( trim( (string) ( $record['street'] ?? '' ) ) ),
			strtolower( trim( (string) ( $record['city'] ?? '' ) ) ),
			strtolower( trim( (string) ( $record['postal_code'] ?? '' ) ) ),
			strtolower( trim( (string) ( $record['country'] ?? '' ) ) ),
		);
		$parts = array_values( array_filter( $parts, static fn( $value ) => '' !== trim( (string) $value ) ) );
		return empty( $parts ) ? '' : implode( '|', $parts );
	}

	private static function normalize_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		$scheme = strtolower( (string) $parts['scheme'] );
		$host   = strtolower( (string) $parts['host'] );
		$path   = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		if ( '' === $path ) {
			$path = '/';
		}
		$path = preg_replace( '#/+#', '/', $path );
		$query = '';
		if ( ! empty( $parts['query'] ) ) {
			parse_str( (string) $parts['query'], $query_args );
			if ( ! empty( $query_args ) ) {
				$query = '?' . http_build_query( $query_args, '', '&', PHP_QUERY_RFC3986 );
			}
		}
		return $scheme . '://' . $host . $path . $query;
	}

	private static function location_has_substantive_visible_content( array $record ): bool {
		$post_id = absint( $record['landing_page_id'] ?? 0 );
		if ( $post_id <= 0 || ! function_exists( 'get_post_field' ) ) {
			return false;
		}
		$content = (string) get_post_field( 'post_content', $post_id );
		$signals = ContentSignals::analyze( $content );
		$plain   = trim( (string) ( $signals['plain_text'] ?? '' ) );
		if ( '' === $plain ) {
			return false;
		}
		return (int) ( $signals['word_count'] ?? 0 ) >= 80;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function opening_hours_specifications( string $raw ): array {
		$specs = array();
		foreach ( self::opening_hours_lines( $raw ) as $line ) {
			$parsed = self::parse_opening_hours_line( $line );
			if ( empty( $parsed ) ) {
				continue;
			}
			$specs[] = $parsed;
		}
		return $specs;
	}

	/**
	 * @return array<string, int>
	 */
	private static function validate_opening_hours( string $raw ): array {
		$lines         = self::opening_hours_lines( $raw );
		$invalid_count = 0;
		foreach ( $lines as $line ) {
			if ( empty( self::parse_opening_hours_line( $line ) ) ) {
				++$invalid_count;
			}
		}
		return array(
			'total'         => count( $lines ),
			'invalid_count' => $invalid_count,
		);
	}

	/**
	 * @return array<int, string>
	 */
	private static function opening_hours_lines( string $raw ): array {
		$lines = preg_split( '/\r\n|\r|\n/', sanitize_textarea_field( $raw ) ) ?: array();
		$lines = array_map( 'trim', $lines );
		return array_values( array_filter( $lines, static fn( $line ) => '' !== $line ) );
	}

	/**
	 * Accepts lines like:
	 * Mo-Fr 09:00-17:00
	 * Sa 10:00-13:00
	 *
	 * @return array<string, mixed>
	 */
	private static function parse_opening_hours_line( string $line ): array {
		$line = trim( $line );
		if ( '' === $line ) {
			return array();
		}
		if ( ! preg_match( '/^([A-Za-z]{2})(?:-([A-Za-z]{2}))?\s+([0-2][0-9]:[0-5][0-9])-([0-2][0-9]:[0-5][0-9])$/', $line, $match ) ) {
			return array();
		}
		$from = strtolower( (string) $match[1] );
		$to   = strtolower( (string) ( $match[2] ?? '' ) );
		$open = (string) $match[3];
		$close = (string) $match[4];
		if ( ! isset( self::DAY_MAP[ $from ] ) ) {
			return array();
		}

		$days = array();
		if ( '' === $to || ! isset( self::DAY_MAP[ $to ] ) ) {
			$days[] = 'https://schema.org/' . self::DAY_MAP[ $from ];
		} else {
			$order = array_keys( self::DAY_MAP );
			$from_index = array_search( $from, $order, true );
			$to_index   = array_search( $to, $order, true );
			if ( false === $from_index || false === $to_index ) {
				return array();
			}
			if ( $from_index <= $to_index ) {
				$range = array_slice( $order, $from_index, $to_index - $from_index + 1 );
			} else {
				$range = array_merge( array_slice( $order, $from_index ), array_slice( $order, 0, $to_index + 1 ) );
			}
			foreach ( $range as $code ) {
				$days[] = 'https://schema.org/' . self::DAY_MAP[ $code ];
			}
		}

		$spec = array(
			'@type'     => 'OpeningHoursSpecification',
			'dayOfWeek' => count( $days ) > 1 ? $days : $days[0],
			'opens'     => $open,
			'closes'    => $close,
		);

		return $spec;
	}

	/**
	 * @return array<int, string>
	 */
	private static function split_comma_values( string $value ): array {
		$values = array_map( 'trim', explode( ',', sanitize_text_field( $value ) ) );
		return array_values( array_filter( $values, static fn( $row ) => '' !== $row ) );
	}
}
