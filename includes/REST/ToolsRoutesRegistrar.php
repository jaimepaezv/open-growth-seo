<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\REST;

defined( 'ABSPATH' ) || exit;

class ToolsRoutesRegistrar {
	public function register( Routes $controller ): void {
		register_rest_route(
			'ogs-seo/v1',
			'/dev-tools/diagnostics',
			array(
				'methods'             => 'GET',
				'callback'            => array( $controller, 'dev_tools_diagnostics' ),
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
			'/installation/telemetry',
			array(
				'methods'             => 'GET',
				'callback'            => array( $controller, 'installation_telemetry' ),
				'permission_callback' => array( $controller, 'can_manage_options' ),
				'args'                => array(
					'limit'  => array(
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
					'filter' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_key',
					),
					'group'  => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
		register_rest_route(
			'ogs-seo/v1',
			'/dev-tools/export',
			array(
				'methods'             => 'GET',
				'callback'            => array( $controller, 'dev_tools_export' ),
				'permission_callback' => array( $controller, 'can_manage_options' ),
			)
		);
		register_rest_route(
			'ogs-seo/v1',
			'/dev-tools/import',
			array(
				'methods'             => 'POST',
				'callback'            => array( $controller, 'dev_tools_import' ),
				'permission_callback' => array( $controller, 'can_manage_options' ),
				'args'                => array(
					'payload' => array(
						'required' => true,
					),
					'merge'   => array(
						'type'              => 'boolean',
						'required'          => false,
						'sanitize_callback' => static fn( $value ): bool => rest_sanitize_boolean( $value ),
					),
				),
			)
		);
		register_rest_route(
			'ogs-seo/v1',
			'/dev-tools/reset',
			array(
				'methods'             => 'POST',
				'callback'            => array( $controller, 'dev_tools_reset' ),
				'permission_callback' => array( $controller, 'can_manage_options' ),
				'args'                => array(
					'preserve_keep_data' => array(
						'type'              => 'boolean',
						'required'          => false,
						'sanitize_callback' => static fn( $value ): bool => rest_sanitize_boolean( $value ),
					),
				),
			)
		);
		register_rest_route(
			'ogs-seo/v1',
			'/dev-tools/logs',
			array(
				'methods'             => 'GET',
				'callback'            => array( $controller, 'dev_tools_logs' ),
				'permission_callback' => array( $controller, 'can_manage_options' ),
				'args'                => array(
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
			'/dev-tools/logs/clear',
			array(
				'methods'             => 'POST',
				'callback'            => array( $controller, 'dev_tools_logs_clear' ),
				'permission_callback' => array( $controller, 'can_manage_options' ),
			)
		);
	}
}
