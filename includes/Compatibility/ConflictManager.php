<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\Compatibility;

defined( 'ABSPATH' ) || exit;

class ConflictManager {
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	public function notice(): void {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$screen_id = is_object( $screen ) && isset( $screen->id ) ? sanitize_key( (string) $screen->id ) : '';
		if ( ! $this->should_render_notice_for_screen( $screen_id ) ) {
			return;
		}

		$detected  = ( new Importer() )->detect();
		$active    = isset( $detected['active'] ) && is_array( $detected['active'] ) ? $detected['active'] : array();
		$runtime   = isset( $detected['runtime'] ) && is_array( $detected['runtime'] ) ? $detected['runtime'] : array();
		$contexts  = isset( $detected['contexts'] ) && is_array( $detected['contexts'] ) ? $detected['contexts'] : array();
		$guidance  = isset( $detected['recommendations'] ) && is_array( $detected['recommendations'] ) ? $detected['recommendations'] : array();
		$integrations = isset( $detected['integrations'] ) && is_array( $detected['integrations'] ) ? $detected['integrations'] : array();

		$issues = array();
		if ( ! empty( $active ) ) {
			$issues[] = __( 'Another SEO plugin is active and can duplicate meta, canonical, schema, or social output.', 'open-growth-seo' );
		}
		if ( array_key_exists( 'meets_php', $runtime ) && empty( $runtime['meets_php'] ) ) {
			$issues[] = sprintf(
				/* translators: 1: required PHP version, 2: current PHP version */
				__( 'PHP baseline mismatch detected (%1$s required, %2$s running).', 'open-growth-seo' ),
				(string) ( $runtime['minimum_php'] ?? '' ),
				(string) ( $runtime['php_version'] ?? '' )
			);
		}
		if ( array_key_exists( 'meets_wp', $runtime ) && empty( $runtime['meets_wp'] ) ) {
			$issues[] = sprintf(
				/* translators: 1: required WordPress version, 2: current WordPress version */
				__( 'WordPress baseline mismatch detected (%1$s required, %2$s running).', 'open-growth-seo' ),
				(string) ( $runtime['minimum_wp'] ?? '' ),
				(string) ( $runtime['wp_version'] ?? '' )
			);
		}
		if ( empty( $issues ) ) {
			return;
		}

		$providers = array();
		foreach ( $active as $provider ) {
			$label = isset( $provider['label'] ) ? trim( (string) $provider['label'] ) : '';
			if ( '' !== $label ) {
				$providers[] = $label;
			}
		}

		$context_bits = array();
		if ( ! empty( $runtime['multisite'] ) ) {
			$context_bits[] = __( 'Multisite', 'open-growth-seo' );
		}
		if ( ! empty( $contexts['classic_editor_forced'] ) ) {
			$context_bits[] = __( 'Classic editor compatibility mode', 'open-growth-seo' );
		}
		if ( ! empty( $integrations['cache']['active'] ) ) {
			$context_bits[] = __( 'Cache layer detected', 'open-growth-seo' );
		}
		if ( ! empty( $integrations['schema']['active'] ) ) {
			$context_bits[] = __( 'Additional schema emitter detected', 'open-growth-seo' );
		}

		$safe_mode = ! empty( $detected['safe_mode_enabled'] );
		$status    = $safe_mode
			? __( 'Safe mode is active. Open Growth SEO will hold back frontend output where coexistence would be risky.', 'open-growth-seo' )
			: __( 'Safe mode is disabled. Duplicate SEO output remains likely until coexistence is resolved.', 'open-growth-seo' );
		$settings_url = function_exists( 'admin_url' ) ? admin_url( 'admin.php?page=ogs-seo-settings' ) : '';
		$tools_url    = function_exists( 'admin_url' ) ? admin_url( 'admin.php?page=ogs-seo-tools' ) : '';

		echo '<div class="notice notice-warning inline ogs-compatibility-notice">';
		echo '<div class="ogs-compatibility-notice__header">';
		echo '<h3>' . esc_html__( 'Compatibility attention needed', 'open-growth-seo' ) . '</h3>';
		echo '<p>' . esc_html( $status ) . '</p>';
		echo '</div>';

		if ( ! empty( $providers ) ) {
			echo '<p><strong>' . esc_html__( 'Active SEO providers:', 'open-growth-seo' ) . '</strong> ' . esc_html( implode( ', ', $providers ) ) . '</p>';
		}

		echo '<ul class="ogs-compatibility-notice__list">';
		foreach ( $issues as $issue ) {
			echo '<li>' . esc_html( $issue ) . '</li>';
		}
		echo '</ul>';

		if ( ! empty( $context_bits ) ) {
			echo '<p><strong>' . esc_html__( 'Relevant environment signals:', 'open-growth-seo' ) . '</strong> ' . esc_html( implode( ' | ', $context_bits ) ) . '</p>';
		}

		if ( ! empty( $guidance ) ) {
			echo '<details class="ogs-compatibility-notice__details"><summary>' . esc_html__( 'Operator guidance', 'open-growth-seo' ) . '</summary><ul class="ogs-compatibility-notice__list">';
			foreach ( array_slice( $guidance, 0, 4 ) as $message ) {
				echo '<li>' . esc_html( (string) $message ) . '</li>';
			}
			echo '</ul></details>';
		}

		echo '<p class="ogs-compatibility-notice__actions">';
		if ( '' !== $settings_url ) {
			echo '<a class="button button-secondary" href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Review Settings', 'open-growth-seo' ) . '</a> ';
		}
		if ( '' !== $tools_url ) {
			echo '<a class="button" href="' . esc_url( $tools_url ) . '">' . esc_html__( 'Open Compatibility Tools', 'open-growth-seo' ) . '</a>';
		}
		echo '</p>';
		echo '</div>';
	}

	private function should_render_notice_for_screen( string $screen_id ): bool {
		if ( '' === $screen_id ) {
			return false;
		}

		return false !== strpos( $screen_id, 'ogs-seo' ) || 'plugins' === $screen_id;
	}
}
