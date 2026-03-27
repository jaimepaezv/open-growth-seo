<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\REST;

defined( 'ABSPATH' ) || exit;

class JobsRoutesRegistrar {
	public function register( Routes $controller ): void {
		register_rest_route(
			'ogs-seo/v1',
			'/jobs/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $controller, 'jobs_status' ),
				'permission_callback' => array( $controller, 'can_manage_options' ),
			)
		);
		register_rest_route(
			'ogs-seo/v1',
			'/jobs/indexnow/process',
			array(
				'methods'             => 'POST',
				'callback'            => array( $controller, 'jobs_indexnow_process' ),
				'permission_callback' => array( $controller, 'can_manage_options' ),
			)
		);
		register_rest_route(
			'ogs-seo/v1',
			'/jobs/indexnow/requeue-failed',
			array(
				'methods'             => 'POST',
				'callback'            => array( $controller, 'jobs_indexnow_requeue_failed' ),
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
			'/jobs/indexnow/release-lock',
			array(
				'methods'             => 'POST',
				'callback'            => array( $controller, 'jobs_indexnow_release_lock' ),
				'permission_callback' => array( $controller, 'can_manage_options' ),
			)
		);
	}
}
