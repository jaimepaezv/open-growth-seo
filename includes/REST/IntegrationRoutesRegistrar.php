<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\REST;

defined( 'ABSPATH' ) || exit;

class IntegrationRoutesRegistrar {
	public function register( Routes $controller ): void {
		register_rest_route(
			'ogs-seo/v1',
			'/integrations/status',
			array(
				'methods'             => 'GET',
				'callback'            => static fn() => new \WP_REST_Response( \OpenGrowthSolutions\OpenGrowthSEO\Integrations\Manager::get_status_payload(), 200 ),
				'permission_callback' => array( $controller, 'can_manage_options' ),
			)
		);
		register_rest_route(
			'ogs-seo/v1',
			'/integrations/gsc-operational',
			array(
				'methods'             => 'GET',
				'callback'            => array( $controller, 'integration_gsc_operational' ),
				'permission_callback' => array( $controller, 'can_manage_options' ),
				'args'                => array(
					'refresh' => array(
						'type'              => 'boolean',
						'required'          => false,
						'sanitize_callback' => static fn( $value ): bool => rest_sanitize_boolean( $value ),
					),
				),
			)
		);
		register_rest_route(
			'ogs-seo/v1',
			'/integrations/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $controller, 'integration_test' ),
				'permission_callback' => array( $controller, 'can_manage_options' ),
				'args'                => array(
					'integration' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
		register_rest_route(
			'ogs-seo/v1',
			'/integrations/disconnect',
			array(
				'methods'             => 'POST',
				'callback'            => array( $controller, 'integration_disconnect' ),
				'permission_callback' => array( $controller, 'can_manage_options' ),
				'args'                => array(
					'integration' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}
}
