<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\REST;

defined( 'ABSPATH' ) || exit;

class AuditRoutesRegistrar {
	public function register( Routes $controller ): void {
		register_rest_route(
			'ogs-seo/v1',
			'/audit/run',
			array(
				'methods'             => 'POST',
				'callback'            => array( $controller, 'run_audit' ),
				'permission_callback' => array( $controller, 'can_manage_options' ),
			)
		);
		register_rest_route(
			'ogs-seo/v1',
			'/audit/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $controller, 'audit_status' ),
				'permission_callback' => array( $controller, 'can_manage_options' ),
			)
		);
		register_rest_route(
			'ogs-seo/v1',
			'/audit/ignore',
			array(
				'methods'             => 'POST',
				'callback'            => array( $controller, 'audit_ignore' ),
				'permission_callback' => array( $controller, 'can_manage_options' ),
				'args'                => array(
					'issue_id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
					'reason'   => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
		register_rest_route(
			'ogs-seo/v1',
			'/audit/unignore',
			array(
				'methods'             => 'POST',
				'callback'            => array( $controller, 'audit_unignore' ),
				'permission_callback' => array( $controller, 'can_manage_options' ),
				'args'                => array(
					'issue_id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}
}
