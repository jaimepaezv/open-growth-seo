<?php
/**
 * Uninstall Open Growth SEO.
 *
 * @package OpenGrowthSEO
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! function_exists( 'ogs_seo_cleanup_site_data' ) ) {
	/**
	 * Cleanup per-site plugin data.
	 */
	function ogs_seo_cleanup_site_data(): void {
		delete_option( 'ogs_seo_settings' );
		delete_option( 'ogs_seo_wizard_draft' );
		delete_option( 'ogs_seo_data_retention' );
		delete_option( 'ogs_seo_indexnow_queue' );
		delete_option( 'ogs_seo_indexnow_failed' );
		delete_option( 'ogs_seo_indexnow_status' );
		delete_option( 'ogs_seo_indexnow_last_sent' );
		delete_option( 'ogs_seo_indexnow_key_verified' );
		delete_option( 'ogs_seo_audit_issues' );
		delete_option( 'ogs_seo_audit_last_run' );
		delete_option( 'ogs_seo_audit_scan_state' );
		delete_option( 'ogs_seo_audit_cache' );
		delete_option( 'ogs_seo_audit_ignored' );
		delete_option( 'ogs_seo_robots_last_saved' );
		delete_option( 'ogs_seo_integration_secrets' );
		delete_option( 'ogs_seo_integration_state' );
		delete_option( 'ogs_seo_integration_logs' );
		delete_option( 'ogs_seo_debug_logs' );
		delete_option( 'ogs_seo_installation_state' );
		delete_option( 'ogs_seo_import_state' );
		delete_option( 'ogs_seo_import_rollback' );
		delete_option( 'ogs_seo_sitemap_cache_version' );
		delete_option( 'ogs_seo_redirect_store_schema' );
		delete_option( 'ogs_seo_redirect_store_migration' );
		delete_option( 'ogs_seo_redirect_rules' );
		delete_option( 'ogs_seo_404_log' );
		delete_option( 'ogs_seo_redirect_import_last_report' );
		delete_option( 'ogs_seo_gsc_operational_cache' );

		global $wpdb;
		if ( isset( $wpdb ) && is_object( $wpdb ) && isset( $wpdb->prefix ) ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'ogs_seo_redirects' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Internal uninstall cleanup.
			$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'ogs_seo_404_events' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Internal uninstall cleanup.
		}
	}
}

if ( ! function_exists( 'ogs_seo_keep_data_enabled' ) ) {
	/**
	 * Check keep-data flag for current site.
	 */
	function ogs_seo_keep_data_enabled(): bool {
		$settings = get_option( 'ogs_seo_settings', array() );
		$keep     = isset( $settings['keep_data_on_uninstall'] ) ? absint( $settings['keep_data_on_uninstall'] ) : 1;
		return 1 === $keep;
	}
}

wp_clear_scheduled_hook( 'ogs_seo_run_audit' );
wp_clear_scheduled_hook( 'ogs_seo_process_indexnow_queue' );

if ( is_multisite() ) {
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( (array) $site_ids as $site_id ) {
		$site_id = absint( $site_id );
		if ( $site_id <= 0 ) {
			continue;
		}
		switch_to_blog( $site_id );
		if ( ! ogs_seo_keep_data_enabled() ) {
			ogs_seo_cleanup_site_data();
		}
		restore_current_blog();
	}
	return;
}

if ( ! ogs_seo_keep_data_enabled() ) {
	ogs_seo_cleanup_site_data();
}
