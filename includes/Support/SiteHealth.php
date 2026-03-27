<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\Support;

defined( 'ABSPATH' ) || exit;

class SiteHealth {
	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'register_tests' ) );
		add_filter( 'debug_information', array( $this, 'register_debug_information' ) );
	}

	public function register_tests( array $tests ): array {
		$tests['direct']['ogs_seo_support'] = array(
			'label' => __( 'Open Growth SEO support status', 'open-growth-seo' ),
			'test'  => array( $this, 'support_status_test' ),
		);

		return $tests;
	}

	public function support_status_test(): array {
		$diagnostics      = DeveloperTools::diagnostics_cached();
		$support          = isset( $diagnostics['support'] ) && is_array( $diagnostics['support'] ) ? $diagnostics['support'] : array();
		$status           = isset( $support['status'] ) ? (string) $support['status'] : 'good';
		$attention_count  = (int) ( $support['attention_count'] ?? 0 );
		$recommended_step = '';
		if ( ! empty( $support['next_steps'][0]['action'] ) ) {
			$recommended_step = (string) $support['next_steps'][0]['action'];
		}

		$site_status = 'good';
		$label       = __( 'Open Growth SEO is operating normally.', 'open-growth-seo' );
		if ( 'critical' === $status ) {
			$site_status = 'critical';
			$label       = __( 'Open Growth SEO has support items that need immediate attention.', 'open-growth-seo' );
		} elseif ( 'warn' === $status ) {
			$site_status = 'recommended';
			$label       = __( 'Open Growth SEO has support items that should be reviewed.', 'open-growth-seo' );
		}

		$description = $attention_count > 0
			? sprintf( __( 'Support checks currently show %d attention item(s).', 'open-growth-seo' ), $attention_count )
			: __( 'Support checks are clear. Use the Tools screen if you need a detailed diagnostics snapshot.', 'open-growth-seo' );

		if ( '' !== $recommended_step ) {
			$description .= ' ' . $recommended_step;
		}

		return array(
			'label'       => $label,
			'status'      => $site_status,
			'badge'       => array(
				'label' => __( 'Open Growth SEO', 'open-growth-seo' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => sprintf(
				'<p><a class="button button-primary" href="%s">%s</a></p>',
				esc_url( admin_url( 'admin.php?page=ogs-seo-tools&ogs_show_advanced=1' ) ),
				esc_html__( 'Open Support Tools', 'open-growth-seo' )
			),
			'test'        => 'ogs_seo_support',
		);
	}

	public function register_debug_information( array $info ): array {
		$diagnostics = DeveloperTools::diagnostics_cached();
		$support     = isset( $diagnostics['support'] ) && is_array( $diagnostics['support'] ) ? $diagnostics['support'] : array();
		$jobs        = isset( $diagnostics['jobs'] ) && is_array( $diagnostics['jobs'] ) ? $diagnostics['jobs'] : array();

		$info['open_growth_seo'] = array(
			'label'  => __( 'Open Growth SEO', 'open-growth-seo' ),
			'fields' => array(
				'plugin_version' => array(
					'label' => __( 'Plugin version', 'open-growth-seo' ),
					'value' => (string) ( $diagnostics['plugin_version'] ?? 'unknown' ),
				),
				'support_status' => array(
					'label' => __( 'Support status', 'open-growth-seo' ),
					'value' => (string) ( $support['status'] ?? 'good' ),
				),
				'support_attention' => array(
					'label' => __( 'Support attention items', 'open-growth-seo' ),
					'value' => (string) (int) ( $support['attention_count'] ?? 0 ),
				),
				'installation_status' => array(
					'label' => __( 'Installation status', 'open-growth-seo' ),
					'value' => (string) ( $diagnostics['installation_status'] ?? '' ),
				),
				'audit_last_run' => array(
					'label' => __( 'Last audit run', 'open-growth-seo' ),
					'value' => ! empty( $diagnostics['audit_last_run'] ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $diagnostics['audit_last_run'] ) : __( 'Never', 'open-growth-seo' ),
				),
				'jobs_pending' => array(
					'label' => __( 'Pending background jobs', 'open-growth-seo' ),
					'value' => (string) (int) ( $jobs['pending'] ?? 0 ),
				),
				'debug_logs' => array(
					'label' => __( 'Developer logs enabled', 'open-growth-seo' ),
					'value' => ! empty( $diagnostics['debug_logs_enabled'] ) ? __( 'Yes', 'open-growth-seo' ) : __( 'No', 'open-growth-seo' ),
				),
			),
		);

		return $info;
	}
}
