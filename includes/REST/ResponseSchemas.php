<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\REST;

defined( 'ABSPATH' ) || exit;

class ResponseSchemas {
	public static function definitions(): array {
		return array(
			'sitemaps_status' => array(
				'enabled'    => 'boolean',
				'index_url'  => 'string',
				'post_types' => 'array',
			),
			'dev_tools_diagnostics' => array(
				'timestamp'               => 'integer',
				'plugin_version'          => 'string',
				'wp_version'              => 'string',
				'php_version'             => 'string',
				'multisite'               => 'boolean',
				'installation_status'     => 'string',
				'installation_rebuilds'   => 'integer',
				'installation_repair_runs' => 'integer',
				'support_status'          => 'string',
				'support_attention_count' => 'integer',
				'support'                 => 'array',
			),
			'installation_telemetry' => array(
				'filter'  => 'string',
				'group'   => 'string',
				'entries' => 'array',
				'groups'  => 'array',
				'summary' => 'array',
			),
			'geo_telemetry' => array(
				'entries' => 'array',
				'rollup'  => 'array',
			),
			'sfo_telemetry' => array(
				'entries' => 'array',
				'rollup'  => 'array',
			),
			'sfo_analyze' => array(
				'ok'       => 'boolean',
				'post_id'  => 'integer',
				'analysis' => 'array',
			),
			'jobs_status' => array(
				'jobs' => 'array',
			),
			'search_appearance_preview' => array(
				'serp_title'         => 'string',
				'serp_description'   => 'string',
				'social_title'       => 'string',
				'social_description' => 'string',
				'title_count'        => 'integer',
				'description_count'  => 'integer',
				'home_url'           => 'string',
				'schema_hints'       => 'array',
			),
		);
	}
}
