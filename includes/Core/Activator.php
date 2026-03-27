<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\Core;

use OpenGrowthSolutions\OpenGrowthSEO\Installation\Manager as InstallationManager;
use OpenGrowthSolutions\OpenGrowthSEO\SEO\RedirectStore;
use OpenGrowthSolutions\OpenGrowthSEO\SEO\Sitemaps;

defined( 'ABSPATH' ) || exit;

class Activator {
	public static function activate(): void {
		$current       = get_option( 'ogs_seo_settings', null );
		$fresh_install = ! is_array( $current ) || empty( $current );
		if ( $fresh_install ) {
			update_option( 'ogs_seo_settings', Defaults::settings(), false );
		} elseif ( is_array( $current ) ) {
			update_option( 'ogs_seo_settings', array_replace( Defaults::settings(), $current ), false );
		}
		if ( ! get_option( 'ogs_seo_data_retention' ) ) {
			add_option( 'ogs_seo_data_retention', 'keep' );
		}
		update_option(
			'ogs_seo_activation_state',
			array(
				'time'         => time(),
				'fresh_install' => $fresh_install ? 1 : 0,
				'plugin_version' => defined( 'OGS_SEO_VERSION' ) ? (string) OGS_SEO_VERSION : 'unknown',
			),
			false
		);
		InstallationManager::bootstrap_after_activation( $fresh_install );
		RedirectStore::activate();
		$sitemaps = new Sitemaps();
		$sitemaps->rewrite();
		flush_rewrite_rules( false );
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'ogs_seo_run_audit' );
		wp_clear_scheduled_hook( 'ogs_seo_process_indexnow_queue' );
		delete_option( 'ogs_seo_activation_state' );
		flush_rewrite_rules( false );
	}
}
