<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\Admin;

use OpenGrowthSolutions\OpenGrowthSEO\SEO\RedirectImporter;
use OpenGrowthSolutions\OpenGrowthSEO\SEO\Redirects;

defined( 'ABSPATH' ) || exit;

class RedirectsPage {
	private Admin $admin;

	public function __construct( Admin $admin ) {
		$this->admin = $admin;
	}

	public function render( array $settings ): void {
		$status              = Redirects::status_payload();
		$rules               = Redirects::get_rules();
		$log                 = Redirects::get_404_log();
		$integrity           = isset( $status['integrity'] ) && is_array( $status['integrity'] )
			? $status['integrity']
			: array(
				'errors'   => array(),
				'warnings' => array(),
			);
		$errors              = isset( $integrity['errors'] ) && is_array( $integrity['errors'] ) ? $integrity['errors'] : array();
		$warnings            = isset( $integrity['warnings'] ) && is_array( $integrity['warnings'] ) ? $integrity['warnings'] : array();
		$source_query        = isset( $_GET['ogs_redirect_source'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['ogs_redirect_source'] ) ) : '';
		$destination_query   = isset( $_GET['ogs_redirect_destination'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['ogs_redirect_destination'] ) ) : '';
		$source_prefill      = Redirects::normalize_source_path( $source_query );
		$destination_prefill = Redirects::normalize_destination_url( $destination_query );
		if ( '' === $destination_prefill ) {
			$destination_prefill = home_url( '/' );
		}

		echo '<p class="description">' . esc_html__( 'Redirects are intentionally conservative: rules run only for unresolved 404 requests so valid URLs are never overridden by mistake.', 'open-growth-seo' ) . '</p>';
		echo '<div class="ogs-actions">';
		$this->admin->render_rest_action( __( 'REST Redirect Status', 'open-growth-seo' ), 'ogs-seo/v1/redirects/status', false );
		$this->admin->render_rest_action( __( 'REST 404 Log', 'open-growth-seo' ), 'ogs-seo/v1/redirects/404-log', false );
		echo '</div>';

		echo '<table class="widefat striped" style="max-width:920px"><tbody>';
		echo '<tr><th>' . esc_html__( 'Storage mode', 'open-growth-seo' ) . '</th><td>' . esc_html( 'table' === (string) ( $status['storage_mode'] ?? 'option' ) ? __( 'Database tables', 'open-growth-seo' ) : __( 'Legacy options fallback', 'open-growth-seo' ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Rules total', 'open-growth-seo' ) . '</th><td>' . esc_html( (string) absint( $status['rules_total'] ?? 0 ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Rules enabled', 'open-growth-seo' ) . '</th><td>' . esc_html( (string) absint( $status['rules_enabled'] ?? 0 ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Tracked 404 paths', 'open-growth-seo' ) . '</th><td>' . esc_html( (string) absint( $status['log_entries'] ?? 0 ) ) . '</td></tr>';
		echo '</tbody></table>';
		$migration = isset( $status['migration'] ) && is_array( $status['migration'] ) ? $status['migration'] : array();
		if ( ! empty( $migration['time'] ) ) {
			echo '<p class="description">' . esc_html(
				sprintf(
					/* translators: 1: imported redirect rules count, 2: imported 404 rows count */
					__( 'Legacy migration report: imported %1$d redirect rules and %2$d 404 entries.', 'open-growth-seo' ),
					(int) ( $migration['imported_rules'] ?? 0 ),
					(int) ( $migration['imported_404'] ?? 0 )
				)
			) . '</p>';
		}

		if ( ! empty( $errors ) ) {
			echo '<div class="notice notice-error inline"><p><strong>' . esc_html__( 'Redirect conflicts detected', 'open-growth-seo' ) . '</strong><br/>' . esc_html__( 'At least one rule is invalid or can create a redirect loop. Disable or remove conflicting rules before relying on automation.', 'open-growth-seo' ) . '</p></div>';
		}
		if ( ! empty( $warnings ) ) {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Redirect warnings', 'open-growth-seo' ) . '</strong><br/>' . esc_html__( 'Some prefix rules are broad. Keep them only when intentional and monitor resulting 404 trends.', 'open-growth-seo' ) . '</p></div>';
		}

		echo '<h2>' . esc_html__( 'Redirect Behavior', 'open-growth-seo' ) . '</h2>';
		echo '<form method="post">';
		wp_nonce_field( 'ogs_seo_save_settings' );
		echo '<input type="hidden" name="ogs_seo_action" value="save_settings" />';
		$this->admin->field_select(
			'redirects_enabled',
			__( 'Enable redirects', 'open-growth-seo' ),
			(string) $settings['redirects_enabled'],
			array(
				'1' => __( 'Enabled', 'open-growth-seo' ),
				'0' => __( 'Disabled', 'open-growth-seo' ),
			)
		);
		$this->admin->field_select(
			'redirect_404_logging_enabled',
			__( 'Track unresolved 404 URLs', 'open-growth-seo' ),
			(string) $settings['redirect_404_logging_enabled'],
			array(
				'1' => __( 'Enabled', 'open-growth-seo' ),
				'0' => __( 'Disabled', 'open-growth-seo' ),
			)
		);
		$this->admin->field_text( 'redirect_404_log_limit', __( '404 log max entries', 'open-growth-seo' ), (string) $settings['redirect_404_log_limit'] );
		$this->admin->field_text( 'redirect_404_retention_days', __( '404 retention days', 'open-growth-seo' ), (string) ( $settings['redirect_404_retention_days'] ?? 90 ) );
		echo '<p><button class="button button-primary" type="submit">' . esc_html__( 'Save redirect behavior', 'open-growth-seo' ) . '</button></p>';
		echo '</form>';

		echo '<h2>' . esc_html__( 'Add Redirect Rule', 'open-growth-seo' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'ogs_seo_redirect_action' );
		echo '<input type="hidden" name="action" value="ogs_seo_redirect_add" />';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label for="ogs_redirect_source_path">' . esc_html__( 'Source path', 'open-growth-seo' ) . '</label></th><td><input id="ogs_redirect_source_path" class="regular-text" type="text" name="source_path" value="' . esc_attr( $source_prefill ) . '" placeholder="/old-path/" required /><p class="description">' . esc_html__( 'Use a site-relative path. Redirects run only on 404 requests.', 'open-growth-seo' ) . '</p></td></tr>';
		echo '<tr><th><label for="ogs_redirect_destination_url">' . esc_html__( 'Destination URL', 'open-growth-seo' ) . '</label></th><td><input id="ogs_redirect_destination_url" class="regular-text" type="url" name="destination_url" value="' . esc_attr( $destination_prefill ) . '" required /><p class="description">' . esc_html__( 'Use an absolute URL or root-relative path on this site.', 'open-growth-seo' ) . '</p></td></tr>';
		echo '<tr><th><label for="ogs_redirect_match_type">' . esc_html__( 'Match type', 'open-growth-seo' ) . '</label></th><td><select id="ogs_redirect_match_type" name="match_type"><option value="exact">' . esc_html__( 'Exact path', 'open-growth-seo' ) . '</option><option value="prefix">' . esc_html__( 'Path prefix (advanced)', 'open-growth-seo' ) . '</option></select></td></tr>';
		echo '<tr><th><label for="ogs_redirect_status_code">' . esc_html__( 'Redirect type', 'open-growth-seo' ) . '</label></th><td><select id="ogs_redirect_status_code" name="status_code"><option value="301">' . esc_html__( '301 Permanent', 'open-growth-seo' ) . '</option><option value="302">' . esc_html__( '302 Temporary', 'open-growth-seo' ) . '</option></select></td></tr>';
		echo '<tr><th><label for="ogs_redirect_note">' . esc_html__( 'Note', 'open-growth-seo' ) . '</label></th><td><input id="ogs_redirect_note" class="regular-text" type="text" name="note" value="" /><p class="description">' . esc_html__( 'Optional context for future maintenance.', 'open-growth-seo' ) . '</p></td></tr>';
		echo '</tbody></table>';
		echo '<p><button class="button button-primary" type="submit">' . esc_html__( 'Add redirect rule', 'open-growth-seo' ) . '</button></p>';
		echo '</form>';

		echo '<h2>' . esc_html__( 'Redirect Rules', 'open-growth-seo' ) . '</h2>';
		if ( empty( $rules ) ) {
			echo '<p class="ogs-empty">' . esc_html__( 'No redirect rules yet. Add only paths that should resolve and keep unknown paths as 404 when no equivalent URL exists.', 'open-growth-seo' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Source', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Match', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Destination', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Type', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Hits', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Actions', 'open-growth-seo' ) . '</th></tr></thead><tbody>';
			foreach ( $rules as $rule ) {
				$rule_id    = sanitize_key( (string) ( $rule['id'] ?? '' ) );
				$toggle_url = wp_nonce_url(
					admin_url( 'admin-post.php?action=ogs_seo_redirect_toggle&rule_id=' . rawurlencode( $rule_id ) . '&enabled=' . ( ! empty( $rule['enabled'] ) ? '0' : '1' ) ),
					'ogs_seo_redirect_action'
				);
				$delete_url = wp_nonce_url(
					admin_url( 'admin-post.php?action=ogs_seo_redirect_delete&rule_id=' . rawurlencode( $rule_id ) ),
					'ogs_seo_redirect_action'
				);
				echo '<tr>';
				echo '<td><code>' . esc_html( (string) ( $rule['source_path'] ?? '' ) ) . '</code>';
				if ( ! empty( $rule['note'] ) ) {
					echo '<br/><span class="description">' . esc_html( (string) $rule['note'] ) . '</span>';
				}
				echo '</td>';
				echo '<td>' . esc_html( 'prefix' === (string) ( $rule['match_type'] ?? 'exact' ) ? __( 'Prefix', 'open-growth-seo' ) : __( 'Exact', 'open-growth-seo' ) ) . '</td>';
				echo '<td><code>' . esc_html( (string) ( $rule['destination_url'] ?? '' ) ) . '</code></td>';
				echo '<td>' . esc_html( (string) absint( $rule['status_code'] ?? 301 ) ) . '</td>';
				echo '<td>' . esc_html( (string) absint( $rule['hits'] ?? 0 ) ) . '</td>';
				echo '<td><a class="button" href="' . esc_url( $toggle_url ) . '">' . esc_html( ! empty( $rule['enabled'] ) ? __( 'Disable', 'open-growth-seo' ) : __( 'Enable', 'open-growth-seo' ) ) . '</a> ';
				echo '<a class="button" href="' . esc_url( $delete_url ) . '" onclick="return confirm(\'' . esc_js( __( 'Delete this redirect rule?', 'open-growth-seo' ) ) . '\');">' . esc_html__( 'Delete', 'open-growth-seo' ) . '</a></td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		echo '<h2>' . esc_html__( 'Unresolved 404 Paths', 'open-growth-seo' ) . '</h2>';
		if ( empty( $log ) ) {
			echo '<p class="ogs-empty">' . esc_html__( 'No tracked 404 paths yet. Enable tracking and revisit after real traffic.', 'open-growth-seo' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Path', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Hits', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Last seen', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Referrer', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Action', 'open-growth-seo' ) . '</th></tr></thead><tbody>';
			foreach ( array_slice( $log, 0, 50 ) as $entry ) {
				$path        = isset( $entry['path'] ) ? (string) $entry['path'] : '';
				$prefill_url = add_query_arg(
					array(
						'page'                => 'ogs-seo-redirects',
						'ogs_redirect_source' => $path,
					),
					admin_url( 'admin.php' )
				);
				echo '<tr><td><code>' . esc_html( $path ) . '</code></td><td>' . esc_html( (string) absint( $entry['hits'] ?? 0 ) ) . '</td><td>' . esc_html( ! empty( $entry['last_seen'] ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), absint( $entry['last_seen'] ) ) : '-' ) . '</td><td><code>' . esc_html( (string) ( $entry['last_referrer'] ?? '-' ) ) . '</code></td><td><a class="button" href="' . esc_url( $prefill_url ) . '">' . esc_html__( 'Use in new rule', 'open-growth-seo' ) . '</a></td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'' . esc_js( __( 'Clear tracked 404 log entries?', 'open-growth-seo' ) ) . '\');">';
		wp_nonce_field( 'ogs_seo_redirect_action' );
		echo '<input type="hidden" name="action" value="ogs_seo_redirect_clear_404_log" />';
		echo '<p><button class="button" type="submit">' . esc_html__( 'Clear 404 log', 'open-growth-seo' ) . '</button></p>';
		echo '</form>';

		$provider_labels        = RedirectImporter::provider_labels();
		$detected_sources       = RedirectImporter::detect_sources();
		$last_report            = RedirectImporter::get_last_report();
		$last_rollback_plan     = RedirectImporter::get_last_rollback_plan();
		$last_rows              = isset( $last_report['rows'] ) && is_array( $last_report['rows'] ) ? $last_report['rows'] : array();
		$last_summary           = isset( $last_report['summary'] ) && is_array( $last_report['summary'] ) ? $last_report['summary'] : array();
		$last_counts            = isset( $last_summary['counts'] ) && is_array( $last_summary['counts'] ) ? $last_summary['counts'] : array();
		$last_conflict_preview  = isset( $last_report['conflict_preview'] ) && is_array( $last_report['conflict_preview'] ) ? $last_report['conflict_preview'] : array();

		echo '<h2>' . esc_html__( 'Import Redirect Rules', 'open-growth-seo' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Import redirects from major SEO plugins. Run dry run first to preview duplicates/conflicts. Replace mode is destructive and requires explicit confirmation.', 'open-growth-seo' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'ogs_seo_redirect_action' );
		echo '<input type="hidden" name="action" value="ogs_seo_redirect_import_dry_run" />';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>' . esc_html__( 'Providers', 'open-growth-seo' ) . '</th><td>';
		foreach ( $provider_labels as $slug => $label ) {
			$source_info   = isset( $detected_sources[ $slug ] ) && is_array( $detected_sources[ $slug ] ) ? $detected_sources[ $slug ] : array();
			$detected      = ! empty( $source_info['detected'] );
			$detected_note = $detected ? __( 'detected', 'open-growth-seo' ) : __( 'not detected', 'open-growth-seo' );
			echo '<label style="display:block;margin:4px 0;"><input type="checkbox" name="providers[]" value="' . esc_attr( (string) $slug ) . '" checked="checked" /> ' . esc_html( (string) $label ) . ' <span class="description">(' . esc_html( $detected_note ) . ')</span></label>';
		}
		echo '</td></tr>';
		echo '<tr><th><label for="ogs-redirect-import-mode">' . esc_html__( 'Import mode', 'open-growth-seo' ) . '</label></th><td><select id="ogs-redirect-import-mode" name="import_mode"><option value="merge">' . esc_html__( 'Merge safely (append only)', 'open-growth-seo' ) . '</option><option value="replace">' . esc_html__( 'Replace existing rules (destructive)', 'open-growth-seo' ) . '</option></select></td></tr>';
		echo '</tbody></table>';
		echo '<p><button class="button button-secondary" type="submit">' . esc_html__( 'Run redirect import dry run', 'open-growth-seo' ) . '</button></p>';
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'' . esc_js( __( 'Run redirect import now? This can change redirect behavior immediately.', 'open-growth-seo' ) ) . '\');">';
		wp_nonce_field( 'ogs_seo_redirect_action' );
		echo '<input type="hidden" name="action" value="ogs_seo_redirect_import_run" />';
		echo '<p><strong>' . esc_html__( 'Providers to import now', 'open-growth-seo' ) . '</strong><br/>';
		foreach ( $provider_labels as $slug => $label ) {
			echo '<label style="display:inline-block;margin:0 10px 6px 0;"><input type="checkbox" name="providers[]" value="' . esc_attr( (string) $slug ) . '" checked="checked" /> ' . esc_html( (string) $label ) . '</label>';
		}
		echo '</p>';
		echo '<p><label><input type="radio" name="import_mode" value="merge" checked="checked" /> ' . esc_html__( 'Merge safely (recommended)', 'open-growth-seo' ) . '</label><br/>';
		echo '<label><input type="radio" name="import_mode" value="replace" /> ' . esc_html__( 'Replace all existing redirect rules (advanced, destructive)', 'open-growth-seo' ) . '</label></p>';
		echo '<p><label><input type="checkbox" name="confirm_import" value="1" required /> ' . esc_html__( 'I understand this action changes redirect routing and I want to continue.', 'open-growth-seo' ) . '</label></p>';
		echo '<p><button class="button button-primary" type="submit">' . esc_html__( 'Run redirect import', 'open-growth-seo' ) . '</button></p>';
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'' . esc_js( __( 'Rollback will restore the previous redirect rule set from the last import snapshot. Continue?', 'open-growth-seo' ) ) . '\');">';
		wp_nonce_field( 'ogs_seo_redirect_action' );
		echo '<input type="hidden" name="action" value="ogs_seo_redirect_import_rollback" />';
		echo '<p><button class="button" type="submit" ' . ( RedirectImporter::has_rollback_snapshot() ? '' : 'disabled="disabled"' ) . '>' . esc_html__( 'Rollback last redirect import', 'open-growth-seo' ) . '</button></p>';
		echo '</form>';

		if ( ! empty( $last_report ) ) {
			echo '<h3>' . esc_html__( 'Last Redirect Import Report', 'open-growth-seo' ) . '</h3>';
			echo '<table class="widefat striped" style="max-width:980px"><tbody>';
			echo '<tr><th>' . esc_html__( 'Mode', 'open-growth-seo' ) . '</th><td>' . esc_html( (string) ( $last_report['mode'] ?? 'dry-run' ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Rows scanned', 'open-growth-seo' ) . '</th><td>' . esc_html( (string) absint( $last_summary['total'] ?? count( $last_rows ) ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Ready', 'open-growth-seo' ) . '</th><td>' . esc_html( (string) absint( $last_counts['ready'] ?? 0 ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Conflicts', 'open-growth-seo' ) . '</th><td>' . esc_html( (string) absint( $last_counts['conflict_existing'] ?? 0 ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Duplicates', 'open-growth-seo' ) . '</th><td>' . esc_html( (string) ( absint( $last_counts['duplicate_existing'] ?? 0 ) + absint( $last_counts['duplicate_payload'] ?? 0 ) ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Invalid', 'open-growth-seo' ) . '</th><td>' . esc_html( (string) ( absint( $last_counts['invalid_source'] ?? 0 ) + absint( $last_counts['invalid_destination'] ?? 0 ) + absint( $last_counts['loop'] ?? 0 ) ) ) . '</td></tr>';
			echo '</tbody></table>';

			if ( ! empty( $last_rollback_plan ) ) {
				echo '<h4>' . esc_html__( 'Rollback planning metadata', 'open-growth-seo' ) . '</h4>';
				echo '<table class="widefat striped" style="max-width:980px"><tbody>';
				echo '<tr><th>' . esc_html__( 'Plan ID', 'open-growth-seo' ) . '</th><td><code>' . esc_html( (string) ( $last_rollback_plan['plan_id'] ?? '' ) ) . '</code></td></tr>';
				echo '<tr><th>' . esc_html__( 'Mode', 'open-growth-seo' ) . '</th><td>' . esc_html( (string) ( $last_rollback_plan['mode'] ?? '' ) ) . '</td></tr>';
				echo '<tr><th>' . esc_html__( 'Destructive', 'open-growth-seo' ) . '</th><td>' . esc_html( ! empty( $last_rollback_plan['destructive'] ) ? __( 'Yes', 'open-growth-seo' ) : __( 'No', 'open-growth-seo' ) ) . '</td></tr>';
				echo '<tr><th>' . esc_html__( 'Estimated restore rows', 'open-growth-seo' ) . '</th><td>' . esc_html( (string) absint( $last_rollback_plan['estimated_restore'] ?? $last_rollback_plan['existing_rule_count'] ?? 0 ) ) . '</td></tr>';
				echo '<tr><th>' . esc_html__( 'Rollback available', 'open-growth-seo' ) . '</th><td>' . esc_html( RedirectImporter::has_rollback_snapshot() ? __( 'Yes', 'open-growth-seo' ) : __( 'No', 'open-growth-seo' ) ) . '</td></tr>';
				echo '<tr><th>' . esc_html__( 'Notes', 'open-growth-seo' ) . '</th><td>' . esc_html( (string) ( $last_rollback_plan['notes'] ?? '' ) ) . '</td></tr>';
				echo '</tbody></table>';
			}

			if ( ! empty( $last_conflict_preview ) ) {
				echo '<h4>' . esc_html__( 'Conflict preview', 'open-growth-seo' ) . '</h4>';
				echo '<div class="ogs-grid ogs-grid-overview">';
				$duplicate_existing = isset( $last_conflict_preview['duplicate_existing'] ) && is_array( $last_conflict_preview['duplicate_existing'] ) ? $last_conflict_preview['duplicate_existing'] : array();
				$conflict_existing  = isset( $last_conflict_preview['conflict_existing'] ) && is_array( $last_conflict_preview['conflict_existing'] ) ? $last_conflict_preview['conflict_existing'] : array();
				$invalid_rows       = isset( $last_conflict_preview['invalid_rows'] ) && is_array( $last_conflict_preview['invalid_rows'] ) ? $last_conflict_preview['invalid_rows'] : array();
				echo '<div class="ogs-card"><h5>' . esc_html__( 'Duplicates vs existing', 'open-growth-seo' ) . '</h5><p class="ogs-status">' . esc_html( (string) count( $duplicate_existing ) ) . '</p></div>';
				echo '<div class="ogs-card"><h5>' . esc_html__( 'Conflicts vs existing', 'open-growth-seo' ) . '</h5><p class="ogs-status ' . esc_attr( count( $conflict_existing ) > 0 ? 'ogs-status-warn' : 'ogs-status-good' ) . '">' . esc_html( (string) count( $conflict_existing ) ) . '</p></div>';
				echo '<div class="ogs-card"><h5>' . esc_html__( 'Invalid rows', 'open-growth-seo' ) . '</h5><p class="ogs-status ' . esc_attr( count( $invalid_rows ) > 0 ? 'ogs-status-warn' : 'ogs-status-good' ) . '">' . esc_html( (string) count( $invalid_rows ) ) . '</p></div>';
				echo '</div>';
			}

			if ( ! empty( $last_rows ) ) {
				echo '<p class="description">' . esc_html__( 'Preview of first import records and classifications:', 'open-growth-seo' ) . '</p>';
				echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Provider', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Source', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Destination', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Status', 'open-growth-seo' ) . '</th><th>' . esc_html__( 'Classification', 'open-growth-seo' ) . '</th></tr></thead><tbody>';
				foreach ( array_slice( $last_rows, 0, 40 ) as $item ) {
					if ( ! is_array( $item ) ) {
						continue;
					}
					echo '<tr><td>' . esc_html( (string) ( $item['provider'] ?? '' ) ) . '</td><td><code>' . esc_html( (string) ( $item['source_path'] ?? '' ) ) . '</code></td><td><code>' . esc_html( (string) ( $item['destination_url'] ?? '' ) ) . '</code></td><td>' . esc_html( (string) absint( $item['status_code'] ?? 301 ) ) . '</td><td>' . esc_html( (string) ( $item['classification'] ?? '' ) ) . '</td></tr>';
				}
				echo '</tbody></table>';
			}
		}

		if ( ! empty( $last_rollback_plan ) ) {
			echo '<p class="description">' . esc_html(
				sprintf(
					/* translators: 1: restore count, 2: delete count */
					__( 'Rollback snapshot ready: restore %1$d previous rules and remove %2$d imported rules.', 'open-growth-seo' ),
					(int) ( $last_rollback_plan['restore_count'] ?? 0 ),
					(int) ( $last_rollback_plan['delete_count'] ?? 0 )
				)
			) . '</p>';
		}
	}
}
