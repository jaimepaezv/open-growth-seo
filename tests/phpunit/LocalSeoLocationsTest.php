<?php
use OpenGrowthSolutions\OpenGrowthSEO\SEO\LocalSeoLocations;
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( int $post_id ) {
		return $GLOBALS['ogs_test_permalinks'][ $post_id ] ?? '';
	}
}

if ( ! function_exists( 'get_post_status' ) ) {
	function get_post_status( int $post_id ): string {
		return (string) ( $GLOBALS['ogs_test_post_statuses'][ $post_id ] ?? 'publish' );
	}
}

if ( ! function_exists( 'get_post_field' ) ) {
	function get_post_field( string $field, int $post_id ) {
		if ( 'post_content' !== $field ) {
			return '';
		}
		return $GLOBALS['ogs_test_post_contents'][ $post_id ] ?? '';
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( int $post_id, string $key, bool $single = false ) {
		$value = $GLOBALS['ogs_test_post_meta'][ $post_id ][ $key ] ?? ( $single ? '' : array() );
		if ( $single ) {
			return $value;
		}
		return is_array( $value ) ? $value : array( $value );
	}
}

final class LocalSeoLocationsTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['ogs_test_permalinks'] = array(
			10 => 'https://example.com/locations/quito/',
			11 => 'https://example.com/locations/guayaquil/',
		);
		$GLOBALS['ogs_test_post_statuses'] = array(
			10 => 'publish',
			11 => 'publish',
		);
		$GLOBALS['ogs_test_post_contents'] = array(
			10 => str_repeat( 'Quito location details ', 35 ),
			11 => str_repeat( 'Guayaquil location details ', 35 ),
		);
		$GLOBALS['ogs_test_post_meta'] = array();
	}

	public function test_sanitize_records_keeps_required_structure_and_normalizes_values(): void {
		$records = LocalSeoLocations::sanitize_records(
			array(
				array(
					'status'          => 'published',
					'name'            => 'Quito Central',
					'business_type'   => 'MedicalClinic',
					'landing_page_id' => '10',
					'phone'           => '+593 2 555 1000',
					'street'          => 'Av. Amazonas 100',
					'city'            => 'Quito',
					'country'         => 'ec',
					'latitude'        => '-0.1807',
					'longitude'       => '-78.4678',
					'opening_hours'   => "Mo-Fr 08:00-17:00\nSa 09:00-12:00",
					'service_mode'    => 'service_area',
					'service_areas'   => 'Quito, Cumbaya',
				),
				array(
					'status'          => 'published',
					'name'            => '',
					'landing_page_id' => 0,
					'phone'           => '',
				),
			)
		);

		$this->assertCount( 1, $records );
		$this->assertSame( 'published', $records[0]['status'] );
		$this->assertSame( 'Quito Central', $records[0]['name'] );
		$this->assertSame( 10, $records[0]['landing_page_id'] );
		$this->assertSame( 'EC', $records[0]['country'] );
		$this->assertSame( 'service_area', $records[0]['service_mode'] );
	}

	public function test_diagnostics_detects_duplicate_nap_and_landing_conflicts(): void {
		$settings = array(
			'local_locations' => array(
				array(
					'id'              => 'loc-1',
					'status'          => 'published',
					'name'            => 'Quito Central',
					'business_type'   => 'LocalBusiness',
					'landing_page_id' => 10,
					'phone'           => '+593 2 555 1000',
					'street'          => 'Av. Amazonas 100',
					'city'            => 'Quito',
					'country'         => 'EC',
					'opening_hours'   => "Mo-Fr 08:00-17:00\nINVALID",
				),
				array(
					'id'              => 'loc-2',
					'status'          => 'published',
					'name'            => 'Quito Central',
					'business_type'   => 'LocalBusiness',
					'landing_page_id' => 10,
					'phone'           => '+593 2 555 1000',
					'street'          => 'Av. Amazonas 100',
					'city'            => 'Quito',
					'country'         => 'EC',
				),
			),
		);

		$diagnostics = LocalSeoLocations::diagnostics( $settings );
		$this->assertNotEmpty( $diagnostics['errors'] );
		$this->assertNotEmpty( $diagnostics['warnings'] );
	}

	public function test_schema_node_can_be_built_from_publishable_location_record(): void {
		$settings = array(
			'local_locations' => array(
				array(
					'id'              => 'loc-1',
					'status'          => 'published',
					'name'            => 'Quito Central',
					'business_type'   => 'LocalBusiness',
					'landing_page_id' => 10,
					'phone'           => '+593 2 555 1000',
					'street'          => 'Av. Amazonas 100',
					'city'            => 'Quito',
					'country'         => 'EC',
					'opening_hours'   => "Mo-Fr 08:00-17:00\nSa 09:00-12:00",
				),
			),
		);

		$records = LocalSeoLocations::records( $settings );
		$this->assertCount( 1, $records );
		$this->assertTrue( LocalSeoLocations::is_publishable( $records[0] ) );

		$node = LocalSeoLocations::build_schema_node( $records[0], $settings );
		$this->assertNotEmpty( $node );
		$this->assertSame( 'Quito Central', $node['name'] );
	}

	public function test_diagnostics_flags_published_location_with_unpublished_page_and_missing_service_areas(): void {
		$GLOBALS['ogs_test_post_statuses'][11] = 'draft';
		$settings = array(
			'local_locations' => array(
				array(
					'id'              => 'loc-service',
					'status'          => 'published',
					'name'            => 'Guayaquil Service Area',
					'business_type'   => 'LocalBusiness',
					'landing_page_id' => 11,
					'phone'           => '+593 4 555 1000',
					'street'          => 'Av. Malecon 100',
					'city'            => 'Guayaquil',
					'country'         => 'EC',
					'service_mode'    => 'service_area',
					'service_areas'   => '',
				),
			),
		);

		$diagnostics = LocalSeoLocations::diagnostics( $settings );
		$error_codes = array_map(
			static fn( $item ) => is_array( $item ) ? (string) ( $item['code'] ?? '' ) : '',
			(array) ( $diagnostics['errors'] ?? array() )
		);
		$warning_codes = array_map(
			static fn( $item ) => is_array( $item ) ? (string) ( $item['code'] ?? '' ) : '',
			(array) ( $diagnostics['warnings'] ?? array() )
		);

		$this->assertContains( 'published_location_unpublished_page', $error_codes );
		$this->assertContains( 'missing_service_areas', $warning_codes );
	}

	public function test_diagnostics_flags_invalid_business_type_geo_and_country_for_published_location(): void {
		$settings = array(
			'local_locations' => array(
				array(
					'id'              => 'loc-invalid',
					'status'          => 'published',
					'name'            => 'Invalid Data Location',
					'business_type'   => 'NotARealType',
					'landing_page_id' => 10,
					'phone'           => '+593 2 555 1000',
					'street'          => 'Av. Amazonas 100',
					'city'            => 'Quito',
					'country'         => 'Ecuador',
					'latitude'        => '999',
					'longitude'       => '-78.4678',
				),
			),
		);

		$diagnostics = LocalSeoLocations::diagnostics( $settings );
		$warning_codes = array_map(
			static fn( $item ) => is_array( $item ) ? (string) ( $item['code'] ?? '' ) : '',
			(array) ( $diagnostics['warnings'] ?? array() )
		);

		$this->assertContains( 'invalid_business_type', $warning_codes );
		$this->assertContains( 'invalid_geo_coordinates', $warning_codes );
		$this->assertContains( 'invalid_country_code', $warning_codes );
	}

	public function test_schema_candidate_skips_when_landing_page_is_not_published(): void {
		$GLOBALS['ogs_test_post_statuses'][11] = 'draft';
		$settings = array(
			'local_locations' => array(
				array(
					'id'              => 'loc-draft-page',
					'status'          => 'published',
					'name'            => 'Draft Landing',
					'business_type'   => 'LocalBusiness',
					'landing_page_id' => 11,
					'phone'           => '+593 4 555 1000',
					'street'          => 'Av. Malecon 100',
					'city'            => 'Guayaquil',
					'country'         => 'EC',
				),
			),
		);

		$candidate = LocalSeoLocations::schema_candidate_for_context(
			array(
				'post_id' => 11,
				'url'     => 'https://example.com/locations/guayaquil/',
			),
			$settings
		);

		$this->assertSame( array(), (array) ( $candidate['node'] ?? array() ) );
		$this->assertNotEmpty( $candidate['warnings'] ?? array() );
	}

	public function test_diagnostics_flags_storefront_without_hours_and_noindex_landing_page(): void {
		$GLOBALS['ogs_test_post_meta'][10]['ogs_seo_index'] = 'noindex';
		$settings = array(
			'local_locations' => array(
				array(
					'id'              => 'loc-hours',
					'status'          => 'published',
					'name'            => 'Quito Storefront',
					'business_type'   => 'LocalBusiness',
					'landing_page_id' => 10,
					'phone'           => '+593 2 555 1000',
					'street'          => 'Av. Amazonas 100',
					'city'            => 'Quito',
					'country'         => 'EC',
					'service_mode'    => 'storefront',
					'legacy_migrated' => 1,
				),
			),
		);

		$diagnostics = LocalSeoLocations::diagnostics( $settings );
		$warning_codes = array_map(
			static fn( $item ) => is_array( $item ) ? (string) ( $item['code'] ?? '' ) : '',
			(array) ( $diagnostics['warnings'] ?? array() )
		);

		$this->assertContains( 'missing_storefront_hours', $warning_codes );
		$this->assertContains( 'location_page_noindex', $warning_codes );
		$this->assertContains( 'legacy_location_review', $warning_codes );
	}

	public function test_schema_candidate_skips_hidden_only_location_content(): void {
		$GLOBALS['ogs_test_post_contents'][10] = '<div style="display:none">' . str_repeat( 'Hidden Quito location content ', 120 ) . '</div>';
		$settings = array(
			'local_locations' => array(
				array(
					'id'              => 'loc-hidden',
					'status'          => 'published',
					'name'            => 'Quito Hidden',
					'business_type'   => 'LocalBusiness',
					'landing_page_id' => 10,
					'phone'           => '+593 2 555 1000',
					'street'          => 'Av. Amazonas 100',
					'city'            => 'Quito',
					'country'         => 'EC',
				),
			),
		);

		$candidate = LocalSeoLocations::schema_candidate_for_context(
			array(
				'post_id' => 10,
				'url'     => 'https://example.com/locations/quito/',
			),
			$settings
		);

		$this->assertSame( array(), (array) ( $candidate['node'] ?? array() ) );
		$this->assertNotEmpty( $candidate['warnings'] ?? array() );
	}
}
