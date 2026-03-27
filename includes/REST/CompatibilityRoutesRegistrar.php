<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\REST;

defined( 'ABSPATH' ) || exit;

class CompatibilityRoutesRegistrar {
	public function register( Routes $controller ): void {
		register_rest_route(
			'ogs-seo/v1',
			'/compatibility/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $controller, 'compatibility_status' ),
				'permission_callback' => array( $controller, 'can_manage_options' ),
			)
		);
		register_rest_route(
			'ogs-seo/v1',
			'/compatibility/dry-run',
			array(
				'methods'             => 'POST',
				'callback'            => array( $controller, 'compatibility_dry_run' ),
				'permission_callback' => array( $controller, 'can_manage_options' ),
				'args'                => array(
					'slugs' => array(
						'type'              => 'array',
						'required'          => false,
						'sanitize_callback' => array( $controller, 'sanitize_key_array' ),
					),
				),
			)
		);
		register_rest_route(
			'ogs-seo/v1',
			'/compatibility/import',
			array(
				'methods'             => 'POST',
				'callback'            => array( $controller, 'compatibility_import' ),
				'permission_callback' => array( $controller, 'can_manage_options' ),
				'args'                => array(
					'slugs' => array(
						'type'              => 'array',
						'required'          => false,
						'sanitize_callback' => array( $controller, 'sanitize_key_array' ),
					),
					'overwrite' => array(
						'type'              => 'boolean',
						'required'          => false,
						'sanitize_callback' => static fn( $value ): bool => rest_sanitize_boolean( $value ),
					),
					'limit' => array(
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
		register_rest_route(
			'ogs-seo/v1',
			'/compatibility/rollback',
			array(
				'methods'             => 'POST',
				'callback'            => array( $controller, 'compatibility_rollback' ),
				'permission_callback' => array( $controller, 'can_manage_options' ),
			)
		);
	}
}
