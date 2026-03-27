<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\Support;

defined( 'ABSPATH' ) || exit;

class Privacy {
	public function register(): void {
		add_action( 'admin_init', array( $this, 'policy' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	public function policy(): void {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content(
				'Open Growth SEO',
				'<p>' . esc_html__( 'Open Growth SEO stores technical SEO configuration and optional integration keys. It does not track visitors by default. Debug and integration logs are minimal, redacted, and can be cleared by administrators.', 'open-growth-seo' ) . '</p>'
			);
		}
	}

	public function register_exporter( array $exporters ): array {
		$exporters['open-growth-seo'] = array(
			'exporter_friendly_name' => __( 'Open Growth SEO technical data', 'open-growth-seo' ),
			'callback'               => array( $this, 'exporter_callback' ),
		);
		return $exporters;
	}

	public function register_eraser( array $erasers ): array {
		$erasers['open-growth-seo'] = array(
			'eraser_friendly_name' => __( 'Open Growth SEO technical data', 'open-growth-seo' ),
			'callback'             => array( $this, 'eraser_callback' ),
		);
		return $erasers;
	}

	public function exporter_callback( string $email, int $page = 1 ): array {
		unset( $email, $page );
		return array(
			'data' => array(),
			'done' => true,
		);
	}

	public function eraser_callback( string $email, int $page = 1 ): array {
		unset( $email, $page );
		return array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array(
				__( 'Open Growth SEO does not store visitor-level personal data by default.', 'open-growth-seo' ),
			),
			'done'           => true,
		);
	}
}
