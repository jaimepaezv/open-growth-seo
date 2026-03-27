<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\REST;

defined( 'ABSPATH' ) || exit;

class TechnicalRoutesRegistrar {
	public function register( Routes $controller ): void {
		register_rest_route(
			'ogs-seo/v1',
			'/sitemaps/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $controller, 'sitemaps_status' ),
				'permission_callback' => array( $controller, 'can_manage_options' ),
			)
		);
		register_rest_route(
			'ogs-seo/v1',
			'/sitemaps/inspect',
			array(
				'methods'             => 'GET',
				'callback'            => array( $controller, 'sitemaps_inspect' ),
				'permission_callback' => array( $controller, 'can_manage_options' ),
			)
		);
		register_rest_route(
			'ogs-seo/v1',
			'/redirects/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $controller, 'redirects_status' ),
				'permission_callback' => array( $controller, 'can_manage_options' ),
			)
		);
		register_rest_route(
			'ogs-seo/v1',
			'/redirects/404-log',
			array(
				'methods'             => 'GET',
				'callback'            => array( $controller, 'redirects_404_log' ),
				'permission_callback' => array( $controller, 'can_manage_options' ),
			)
		);
		register_rest_route(
			'ogs-seo/v1',
			'/redirects/import-sources',
			array(
				'methods'             => 'GET',
				'callback'            => array( $controller, 'redirects_import_sources' ),
				'permission_callback' => array( $controller, 'can_manage_options' ),
			)
		);
		register_rest_route(
			'ogs-seo/v1',
			'/redirects/import-dry-run',
			array(
				'methods'             => 'POST',
				'callback'            => array( $controller, 'redirects_import_dry_run' ),
				'permission_callback' => array( $controller, 'can_manage_options' ),
				'args'                => $controller->redirect_import_args( false ),
			)
		);
		register_rest_route(
			'ogs-seo/v1',
			'/redirects/import-run',
			array(
				'methods'             => 'POST',
				'callback'            => array( $controller, 'redirects_import_run' ),
				'permission_callback' => array( $controller, 'can_manage_options' ),
				'args'                => $controller->redirect_import_args( true ),
			)
		);
		register_rest_route(
			'ogs-seo/v1',
			'/redirects/import-rollback',
			array(
				'methods'             => 'POST',
				'callback'            => array( $controller, 'redirects_import_rollback' ),
				'permission_callback' => array( $controller, 'can_manage_options' ),
			)
		);
		register_rest_route(
			'ogs-seo/v1',
			'/hreflang/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $controller, 'hreflang_status' ),
				'permission_callback' => array( $controller, 'can_manage_options' ),
			)
		);
		register_rest_route(
			'ogs-seo/v1',
			'/schema/inspect',
			array(
				'methods'             => 'GET',
				'callback'            => array( $controller, 'schema_inspect' ),
				'permission_callback' => array( $controller, 'can_manage_options' ),
				'args'                => array(
					'post_id' => array(
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
					'url'     => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'esc_url_raw',
					),
				),
			)
		);
		register_rest_route(
			'ogs-seo/v1',
			'/indexnow/status',
			array(
				'methods'             => 'GET',
				'callback'            => static fn() => new \WP_REST_Response( ( new \OpenGrowthSolutions\OpenGrowthSEO\Integrations\IndexNow() )->inspect_status(), 200 ),
				'permission_callback' => array( $controller, 'can_manage_options' ),
			)
		);
		register_rest_route(
			'ogs-seo/v1',
			'/indexnow/process',
			array(
				'methods'             => 'POST',
				'callback'            => array( $controller, 'indexnow_process' ),
				'permission_callback' => array( $controller, 'can_manage_options' ),
			)
		);
		register_rest_route(
			'ogs-seo/v1',
			'/indexnow/verify-key',
			array(
				'methods'             => 'POST',
				'callback'            => array( $controller, 'indexnow_verify_key' ),
				'permission_callback' => array( $controller, 'can_manage_options' ),
			)
		);
		register_rest_route(
			'ogs-seo/v1',
			'/indexnow/generate-key',
			array(
				'methods'             => 'POST',
				'callback'            => array( $controller, 'indexnow_generate_key' ),
				'permission_callback' => array( $controller, 'can_manage_options' ),
			)
		);
	}
}
