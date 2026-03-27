<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\REST;

defined( 'ABSPATH' ) || exit;

class ContentRoutesRegistrar {
	public function register( Routes $controller ): void {
		register_rest_route(
			'ogs-seo/v1',
			'/content-meta/(?P<post_id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $controller, 'content_meta_get' ),
				'permission_callback' => array( $controller, 'can_edit_content_meta' ),
			)
		);
		register_rest_route(
			'ogs-seo/v1',
			'/content-meta/(?P<post_id>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $controller, 'content_meta_save' ),
				'permission_callback' => array( $controller, 'can_edit_content_meta' ),
				'args'                => array(
					'meta' => array(
						'type'     => 'object',
						'required' => false,
					),
				),
			)
		);
		register_rest_route(
			'ogs-seo/v1',
			'/settings',
			array(
				'methods'             => 'GET',
				'callback'            => static fn() => rest_ensure_response( \OpenGrowthSolutions\OpenGrowthSEO\Support\Settings::get_all() ),
				'permission_callback' => array( $controller, 'can_manage_options' ),
			)
		);
		register_rest_route(
			'ogs-seo/v1',
			'/search-appearance/preview',
			array(
				'methods'             => 'POST',
				'callback'            => array( $controller, 'search_appearance_preview' ),
				'permission_callback' => array( $controller, 'can_manage_options' ),
				'args'                => array(
					'title_template'            => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'meta_description_template' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'title_separator'           => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'sample_title'              => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'sample_excerpt'            => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'search_query'              => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'og_enabled'                => array(
						'type'              => 'boolean',
						'required'          => false,
						'sanitize_callback' => static fn( $value ): bool => rest_sanitize_boolean( $value ),
					),
					'twitter_enabled'           => array(
						'type'              => 'boolean',
						'required'          => false,
						'sanitize_callback' => static fn( $value ): bool => rest_sanitize_boolean( $value ),
					),
				),
			)
		);
		register_rest_route(
			'ogs-seo/v1',
			'/aeo/analyze',
			array(
				'methods'             => 'GET',
				'callback'            => array( $controller, 'aeo_analyze' ),
				'permission_callback' => array( $controller, 'can_edit_posts' ),
				'args'                => array(
					'post_id' => array(
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
		register_rest_route(
			'ogs-seo/v1',
			'/geo/analyze',
			array(
				'methods'             => 'GET',
				'callback'            => array( $controller, 'geo_analyze' ),
				'permission_callback' => array( $controller, 'can_edit_posts' ),
				'args'                => array(
					'post_id' => array(
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
		register_rest_route(
			'ogs-seo/v1',
			'/geo/telemetry',
			array(
				'methods'             => 'GET',
				'callback'            => array( $controller, 'geo_telemetry' ),
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
			'/sfo/analyze',
			array(
				'methods'             => 'GET',
				'callback'            => array( $controller, 'sfo_analyze' ),
				'permission_callback' => array( $controller, 'can_edit_posts' ),
				'args'                => array(
					'post_id' => array(
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
		register_rest_route(
			'ogs-seo/v1',
			'/sfo/telemetry',
			array(
				'methods'             => 'GET',
				'callback'            => array( $controller, 'sfo_telemetry' ),
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
	}
}
