<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\Integrations;

use OpenGrowthSolutions\OpenGrowthSEO\Integrations\Contracts\IntegrationServiceInterface;
use OpenGrowthSolutions\OpenGrowthSEO\Support\Settings;

defined( 'ABSPATH' ) || exit;

class BingWebmaster implements IntegrationServiceInterface {
	public function slug(): string {
		return 'bing_webmaster';
	}

	public function label(): string {
		return __( 'Bing Webmaster Tools', 'open-growth-seo' );
	}

	public function enabled_setting_key(): string {
		return 'bing_enabled';
	}

	public function secret_fields(): array {
		return array( 'api_key' );
	}

	public function status( array $settings, array $state, array $secrets ): array {
		$enabled      = ! empty( $settings[ $this->enabled_setting_key() ] );
		$site_url     = (string) ( $settings['bing_site_url'] ?? '' );
		$configured   = '' !== trim( $site_url ) && ! empty( $secrets['api_key'] );
		$connected    = $configured && ! empty( $state['connected'] );
		$last_error   = sanitize_text_field( (string) ( $state['last_error'] ?? '' ) );
		$last_success = absint( $state['last_success'] ?? 0 );

		return array(
			'slug'         => $this->slug(),
			'label'        => $this->label(),
			'enabled'      => $enabled,
			'configured'   => $configured,
			'connected'    => $connected,
			'mode'         => __( 'Read-only diagnostics', 'open-growth-seo' ),
			'message'      => $configured
				? __( 'Configured. Use Test connection to verify API access.', 'open-growth-seo' )
				: __( 'Set site URL and Bing API key to enable diagnostics.', 'open-growth-seo' ),
			'last_error'   => $last_error,
			'last_success' => $last_success,
		);
	}

	public function test_connection( array $settings, array $secrets ): array {
		$site_url = esc_url_raw( (string) ( $settings['bing_site_url'] ?? '' ) );
		$api_key  = isset( $secrets['api_key'] ) ? sanitize_text_field( (string) $secrets['api_key'] ) : '';
		if ( '' === $site_url ) {
			return array(
				'ok'      => false,
				'message' => __( 'Site URL is missing for Bing Webmaster.', 'open-growth-seo' ),
			);
		}
		if ( '' === $api_key ) {
			return array(
				'ok'      => false,
				'message' => __( 'Bing API key is missing.', 'open-growth-seo' ),
			);
		}

		$timeout = max( 3, min( 20, absint( Settings::get( 'integration_request_timeout', 8 ) ) ) );
		$retries = max( 0, min( 5, absint( Settings::get( 'integration_retry_limit', 2 ) ) ) );
		$url     = add_query_arg(
			array(
				'siteUrl' => $site_url,
				'apikey'  => $api_key,
			),
			'https://ssl.bing.com/webmaster/api.svc/json/GetUrlSubmissionQuota'
		);

		$attempt  = 0;
		$response = null;
		do {
			$response = wp_remote_get(
				$url,
				array(
					'timeout' => $timeout,
				)
			);
			if ( ! is_wp_error( $response ) ) {
				$code = (int) wp_remote_retrieve_response_code( $response );
				if ( $code >= 200 && $code < 300 ) {
					return array(
						'ok'      => true,
						'message' => __( 'Bing Webmaster API endpoint reachable with configured key.', 'open-growth-seo' ),
					);
				}
			}
			++$attempt;
		} while ( $attempt <= $retries );

		$message = is_wp_error( $response )
			? $response->get_error_message()
			: sprintf( __( 'Bing API endpoint returned HTTP %d.', 'open-growth-seo' ), (int) wp_remote_retrieve_response_code( $response ) );
		return array(
			'ok'      => false,
			'message' => sanitize_text_field( $message ),
		);
	}
}
