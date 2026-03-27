<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\Integrations;

use OpenGrowthSolutions\OpenGrowthSEO\Integrations\Contracts\IntegrationServiceInterface;

defined( 'ABSPATH' ) || exit;

class GA4Reporting implements IntegrationServiceInterface {
	public function slug(): string {
		return 'ga4_reporting';
	}

	public function label(): string {
		return __( 'Google Analytics 4 (Optional)', 'open-growth-seo' );
	}

	public function enabled_setting_key(): string {
		return 'ga4_enabled';
	}

	public function secret_fields(): array {
		return array( 'api_secret' );
	}

	public function status( array $settings, array $state, array $secrets ): array {
		$enabled        = ! empty( $settings[ $this->enabled_setting_key() ] );
		$measurement_id = sanitize_text_field( (string) ( $settings['ga4_measurement_id'] ?? '' ) );
		$configured     = '' !== $measurement_id && ! empty( $secrets['api_secret'] );
		$connected      = $configured && ! empty( $state['connected'] );
		$last_error     = sanitize_text_field( (string) ( $state['last_error'] ?? '' ) );
		$last_success   = absint( $state['last_success'] ?? 0 );

		return array(
			'slug'         => $this->slug(),
			'label'        => $this->label(),
			'enabled'      => $enabled,
			'configured'   => $configured,
			'connected'    => $connected,
			'mode'         => __( 'Optional reporting helper', 'open-growth-seo' ),
			'message'      => $configured
				? __( 'Configured for optional GA4 diagnostics. Core SEO features do not depend on this integration.', 'open-growth-seo' )
				: __( 'Optional: configure measurement ID and API secret if you want complementary analytics diagnostics.', 'open-growth-seo' ),
			'last_error'   => $last_error,
			'last_success' => $last_success,
		);
	}

	public function test_connection( array $settings, array $secrets ): array {
		$measurement_id = sanitize_text_field( (string) ( $settings['ga4_measurement_id'] ?? '' ) );
		if ( '' === $measurement_id || ! preg_match( '/^G-[A-Z0-9]+$/', $measurement_id ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'GA4 measurement ID is missing or invalid.', 'open-growth-seo' ),
			);
		}
		if ( empty( $secrets['api_secret'] ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'GA4 API secret is missing.', 'open-growth-seo' ),
			);
		}

		return array(
			'ok'      => true,
			'message' => __( 'GA4 configuration looks valid. Remote event submission remains optional and disabled by default.', 'open-growth-seo' ),
		);
	}
}
