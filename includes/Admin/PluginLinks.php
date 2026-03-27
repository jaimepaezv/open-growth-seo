<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\Admin;

defined( 'ABSPATH' ) || exit;

class PluginLinks {
	public function register(): void {
		add_filter( 'plugin_action_links_' . OGS_SEO_BASENAME, array( $this, 'action_links' ) );
	}

	public function action_links( array $links ): array {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=ogs-seo-dashboard' ) ),
			esc_html__( 'Settings', 'open-growth-seo' )
		);

		array_unshift( $links, $settings_link );
		return $links;
	}
}
