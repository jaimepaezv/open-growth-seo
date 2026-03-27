<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\Integrations;

use OpenGrowthSolutions\OpenGrowthSEO\Integrations\Contracts\IntegrationServiceInterface;
use OpenGrowthSolutions\OpenGrowthSEO\Support\Settings;

defined( 'ABSPATH' ) || exit;

class GoogleSearchConsole implements IntegrationServiceInterface {
	private const REPORT_CACHE_OPTION = 'ogs_seo_gsc_operational_cache';
	private const SNAPSHOT_OPTION = 'ogs_seo_gsc_operational_snapshots';
	private const MAX_SNAPSHOTS_PER_PROPERTY = 18;

	public function slug(): string {
		return 'google_search_console';
	}

	public function label(): string {
		return __( 'Google Search Console', 'open-growth-seo' );
	}

	public function enabled_setting_key(): string {
		return 'gsc_enabled';
	}

	public function secret_fields(): array {
		return array( 'client_id', 'client_secret', 'refresh_token' );
	}

	public function status( array $settings, array $state, array $secrets ): array {
		$enabled      = ! empty( $settings[ $this->enabled_setting_key() ] );
		$property     = $this->normalize_property( (string) ( $settings['gsc_property_url'] ?? '' ) );
		$configured   = '' !== trim( $property ) && $this->has_required_secrets( $secrets );
		$connected    = $configured && ! empty( $state['connected'] );
		$last_error   = sanitize_text_field( (string) ( $state['last_error'] ?? '' ) );
		$last_success = absint( $state['last_success'] ?? 0 );

		return array(
			'slug'         => $this->slug(),
			'label'        => $this->label(),
			'enabled'      => $enabled,
			'configured'   => $configured,
			'connected'    => $connected,
			'mode'         => __( 'Read-only diagnostics', 'open-growth-seo' ),
			'message'      => $configured
				? __( 'Configured. Use Test connection to verify OAuth token refresh.', 'open-growth-seo' )
				: __( 'Set property URL and OAuth credentials to enable API diagnostics.', 'open-growth-seo' ),
			'last_error'   => $last_error,
			'last_success' => $last_success,
		);
	}

	public function test_connection( array $settings, array $secrets ): array {
		$property = $this->normalize_property( (string) ( $settings['gsc_property_url'] ?? '' ) );
		if ( '' === trim( $property ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Property URL is missing. Add site URL or sc-domain property.', 'open-growth-seo' ),
			);
		}
		if ( ! $this->is_valid_property( $property ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Property URL must be a valid site URL or sc-domain property.', 'open-growth-seo' ),
			);
		}
		if ( ! $this->has_required_secrets( $secrets ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'OAuth credentials are incomplete. Add client ID, client secret, and refresh token.', 'open-growth-seo' ),
			);
		}

		$token = $this->request_access_token( $secrets );
		if ( ! empty( $token['ok'] ) ) {
			return array(
				'ok'      => true,
				'message' => __( 'OAuth token refresh succeeded. Search Console integration is reachable.', 'open-growth-seo' ),
			);
		}
		return array(
			'ok'      => false,
			'message' => (string) ( $token['message'] ?? __( 'OAuth token refresh failed.', 'open-growth-seo' ) ),
		);
	}

	public function operational_report( array $settings, array $state, array $secrets, bool $refresh = false ): array {
		$enabled  = ! empty( $settings[ $this->enabled_setting_key() ] );
		$property = $this->normalize_property( (string) ( $settings['gsc_property_url'] ?? '' ) );
		if ( ! $enabled ) {
			return array(
				'ok'           => false,
				'status'       => 'disabled',
				'state_label'  => __( 'Needs work', 'open-growth-seo' ),
				'message'      => __( 'Google Search Console integration is disabled.', 'open-growth-seo' ),
				'next_actions' => array(
					__( 'Enable Google Search Console in Integrations.', 'open-growth-seo' ),
				),
			);
		}
		if ( '' === $property || ! $this->is_valid_property( $property ) || ! $this->has_required_secrets( $secrets ) ) {
			return array(
				'ok'           => false,
				'status'       => 'missing_configuration',
				'state_label'  => __( 'Fix this first', 'open-growth-seo' ),
				'message'      => __( 'Search Console property or OAuth credentials are incomplete or invalid.', 'open-growth-seo' ),
				'next_actions' => array(
					__( 'Set a valid property URL and all OAuth credentials.', 'open-growth-seo' ),
					__( 'Run Test connection before expecting report data.', 'open-growth-seo' ),
				),
			);
		}
		if ( empty( $state['connected'] ) ) {
			return array(
				'ok'           => false,
				'status'       => 'not_connected',
				'state_label'  => __( 'Fix this first', 'open-growth-seo' ),
				'message'      => __( 'Search Console is configured but not connected yet.', 'open-growth-seo' ),
				'next_actions' => array(
					__( 'Use Test connection to validate OAuth refresh token.', 'open-growth-seo' ),
				),
			);
		}

		$cache = get_option( self::REPORT_CACHE_OPTION, array() );
		if ( ! is_array( $cache ) ) {
			$cache = array();
		}
		$cached = isset( $cache[ $property ] ) && is_array( $cache[ $property ] ) ? $cache[ $property ] : array();
		$fresh  = ! empty( $cached['fetched_at'] ) && ( time() - absint( $cached['fetched_at'] ) ) < HOUR_IN_SECONDS * 6;

		if ( ! $refresh && $fresh ) {
			return $this->enrich_report_with_actions( $cached );
		}
		if ( ! $refresh && ! empty( $cached ) ) {
			$cached['stale'] = true;
			return $this->enrich_report_with_actions( $cached );
		}

		$token = $this->request_access_token( $secrets );
		if ( empty( $token['ok'] ) || empty( $token['access_token'] ) ) {
			if ( ! empty( $cached ) ) {
				$cached['stale']   = true;
				$cached['status']  = 'stale_fallback';
				$cached['message'] = __( 'Live refresh failed. Showing last cached Search Console report.', 'open-growth-seo' );
				return $this->enrich_report_with_actions( $cached );
			}
			return array(
				'ok'           => false,
				'status'       => 'oauth_failed',
				'state_label'  => __( 'Fix this first', 'open-growth-seo' ),
				'message'      => (string) ( $token['message'] ?? __( 'OAuth token refresh failed.', 'open-growth-seo' ) ),
				'next_actions' => array(
					__( 'Regenerate OAuth refresh token and test connection again.', 'open-growth-seo' ),
				),
			);
		}

		$timeout = max( 3, min( 20, absint( Settings::get( 'integration_request_timeout', 8 ) ) ) );
		$current_window = $this->query_window(
			$property,
			(string) $token['access_token'],
			gmdate( 'Y-m-d', strtotime( '-28 days' ) ),
			gmdate( 'Y-m-d', strtotime( '-1 day' ) ),
			$timeout
		);
		if ( empty( $current_window['ok'] ) ) {
			if ( ! empty( $cached ) ) {
				$cached['stale']   = true;
				$cached['status']  = 'stale_fallback';
				$cached['message'] = __( 'Search Console request failed. Showing last cached report.', 'open-growth-seo' );
				return $this->enrich_report_with_actions( $cached );
			}
			return array(
				'ok'           => false,
				'status'       => 'remote_error',
				'state_label'  => __( 'Needs work', 'open-growth-seo' ),
				'message'      => (string) ( $current_window['message'] ?? __( 'Search Console request failed.', 'open-growth-seo' ) ),
				'next_actions' => array(
					__( 'Retry later. If this persists, verify Google API access and credentials.', 'open-growth-seo' ),
				),
			);
		}

		$enable_trends   = ! empty( Settings::get( 'seo_masters_plus_gsc_trends', 1 ) );
		$previous_window = $enable_trends
			? $this->query_window(
				$property,
				(string) $token['access_token'],
				gmdate( 'Y-m-d', strtotime( '-56 days' ) ),
				gmdate( 'Y-m-d', strtotime( '-29 days' ) ),
				$timeout
			)
			: array(
				'ok'    => false,
				'start' => '',
				'end'   => '',
				'totals' => array(
					'clicks'      => 0,
					'impressions' => 0,
					'ctr'         => 0,
					'position'    => 0,
				),
			);
		$previous_totals = isset( $previous_window['totals'] ) && is_array( $previous_window['totals'] ) ? $previous_window['totals'] : array(
			'clicks'      => 0,
			'impressions' => 0,
			'ctr'         => 0,
			'position'    => 0,
		);
		$current_totals = isset( $current_window['totals'] ) && is_array( $current_window['totals'] ) ? $current_window['totals'] : array(
			'clicks'      => 0,
			'impressions' => 0,
			'ctr'         => 0,
			'position'    => 0,
		);
		$trend = $enable_trends
			? array(
				'clicks_delta_pct'      => $this->percent_delta( (float) $current_totals['clicks'], (float) $previous_totals['clicks'] ),
				'impressions_delta_pct' => $this->percent_delta( (float) $current_totals['impressions'], (float) $previous_totals['impressions'] ),
				'ctr_delta_pp'          => round( (float) $current_totals['ctr'] - (float) $previous_totals['ctr'], 2 ),
				'position_delta'        => round( (float) $current_totals['position'] - (float) $previous_totals['position'], 2 ),
			)
			: array();

		$report_rows = isset( $current_window['rows'] ) && is_array( $current_window['rows'] ) ? $current_window['rows'] : array();
		$report      = array(
			'ok'               => true,
			'status'           => empty( $report_rows ) ? 'connected_no_data' : 'connected',
			'state_label'      => empty( $report_rows ) ? __( 'Needs work', 'open-growth-seo' ) : __( 'Good', 'open-growth-seo' ),
			'message'          => empty( $report_rows ) ? __( 'Connected but no recent Search Console page data was returned.', 'open-growth-seo' ) : __( 'Search Console operational report refreshed.', 'open-growth-seo' ),
			'property'         => $property,
			'fetched_at'       => time(),
			'stale'            => false,
			'totals'           => array(
				'clicks'      => round( (float) $current_totals['clicks'], 2 ),
				'impressions' => round( (float) $current_totals['impressions'], 2 ),
			),
			'top_pages'        => array_slice( $report_rows, 0, 10 ),
			'trend'            => $trend,
			'issue_trends'     => $enable_trends ? $this->build_issue_trends( $report_rows, $trend ) : array(),
			'trend_windows'    => array(
				'current'  => array(
					'start' => (string) ( $current_window['start'] ?? '' ),
					'end'   => (string) ( $current_window['end'] ?? '' ),
				),
				'previous' => array(
					'start' => (string) ( $previous_window['start'] ?? '' ),
					'end'   => (string) ( $previous_window['end'] ?? '' ),
				),
			),
		);
		if ( $enable_trends && empty( $previous_window['ok'] ) ) {
			$report['status']  = 'connected_partial';
			$report['message'] = __( 'Current Search Console data refreshed, but previous-window trend data is temporarily unavailable.', 'open-growth-seo' );
		}

		$snapshots = $this->record_snapshot(
			$property,
			array(
				'time'         => time(),
				'status'       => (string) ( $report['status'] ?? 'connected' ),
				'window_start' => (string) ( $current_window['start'] ?? '' ),
				'window_end'   => (string) ( $current_window['end'] ?? '' ),
				'totals'       => (array) ( $report['totals'] ?? array() ),
				'issue_trends' => (array) ( $report['issue_trends'] ?? array() ),
			)
		);
		$report['snapshots'] = $snapshots;
		$report['window_deltas'] = $this->build_window_deltas( $snapshots );
		$report['grouped_pages'] = $this->group_pages_for_ops( $report_rows );
		$report['action_queue']  = $this->build_action_queue( $report_rows, (array) ( $report['issue_trends'] ?? array() ), $trend );

		$report = $this->enrich_report_with_actions( $report );
		$cache[ $property ] = $report;
		update_option( self::REPORT_CACHE_OPTION, $cache, false );
		return $report;
	}

	public function clear_runtime_state(): void {
		delete_option( self::REPORT_CACHE_OPTION );
	}

	private function has_required_secrets( array $secrets ): bool {
		foreach ( $this->secret_fields() as $field ) {
			if ( empty( $secrets[ $field ] ) ) {
				return false;
			}
		}
		return true;
	}

	private function normalize_property( string $property ): string {
		$property = trim( sanitize_text_field( $property ) );
		if ( str_starts_with( $property, 'sc-domain:' ) ) {
			return 'sc-domain:' . strtolower( trim( substr( $property, 10 ) ) );
		}
		return esc_url_raw( $property );
	}

	private function is_valid_property( string $property ): bool {
		if ( '' === $property ) {
			return false;
		}
		if ( str_starts_with( $property, 'sc-domain:' ) ) {
			return preg_match( '/^sc-domain:[a-z0-9.-]+\.[a-z]{2,}$/i', $property ) === 1;
		}
		return '' !== esc_url_raw( $property );
	}

	private function request_access_token( array $secrets ): array {
		$timeout = max( 3, min( 20, absint( Settings::get( 'integration_request_timeout', 8 ) ) ) );
		$retries = max( 0, min( 5, absint( Settings::get( 'integration_retry_limit', 2 ) ) ) );
		$body    = array(
			'client_id'     => (string) $secrets['client_id'],
			'client_secret' => (string) $secrets['client_secret'],
			'refresh_token' => (string) $secrets['refresh_token'],
			'grant_type'    => 'refresh_token',
		);
		$attempt  = 0;
		$response = null;
		do {
			$response = wp_remote_post(
				'https://oauth2.googleapis.com/token',
				array(
					'timeout' => $timeout,
					'body'    => $body,
				)
			);
			if ( ! is_wp_error( $response ) ) {
				$code = (int) wp_remote_retrieve_response_code( $response );
				$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
				if ( $code >= 200 && $code < 300 && is_array( $data ) && ! empty( $data['access_token'] ) ) {
					return array(
						'ok'           => true,
						'access_token' => (string) $data['access_token'],
					);
				}
			}
			++$attempt;
		} while ( $attempt <= $retries );

		$message = is_wp_error( $response )
			? $response->get_error_message()
			: sprintf( __( 'Google OAuth endpoint returned HTTP %d.', 'open-growth-seo' ), (int) wp_remote_retrieve_response_code( $response ) );
		return array(
			'ok'      => false,
			'message' => sanitize_text_field( $message ),
		);
	}

	private function query_window( string $property, string $access_token, string $start_date, string $end_date, int $timeout ): array {
		$request = wp_remote_post(
			'https://searchconsole.googleapis.com/webmasters/v3/sites/' . rawurlencode( $property ) . '/searchAnalytics/query',
			array(
				'timeout' => $timeout,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'startDate'  => $start_date,
						'endDate'    => $end_date,
						'dimensions' => array( 'page' ),
						'rowLimit'   => 25,
					)
				),
			)
		);
		if ( is_wp_error( $request ) ) {
			return array(
				'ok'      => false,
				'message' => sanitize_text_field( $request->get_error_message() ),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $request );
		$body = json_decode( (string) wp_remote_retrieve_body( $request ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
			return array(
				'ok'      => false,
				'message' => sprintf( __( 'Search Console API returned HTTP %d.', 'open-growth-seo' ), $code ),
			);
		}

		$rows              = isset( $body['rows'] ) && is_array( $body['rows'] ) ? $body['rows'] : array();
		$normalized_rows   = array();
		$total_clicks      = 0.0;
		$total_impressions = 0.0;
		$ctr_weighted      = 0.0;
		$position_weighted = 0.0;
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$page = isset( $row['keys'][0] ) ? esc_url_raw( (string) $row['keys'][0] ) : '';
			if ( '' === $page ) {
				continue;
			}
			$clicks      = isset( $row['clicks'] ) ? (float) $row['clicks'] : 0.0;
			$impressions = isset( $row['impressions'] ) ? (float) $row['impressions'] : 0.0;
			$ctr         = isset( $row['ctr'] ) ? (float) $row['ctr'] : 0.0;
			$position    = isset( $row['position'] ) ? (float) $row['position'] : 0.0;
			$total_clicks      += $clicks;
			$total_impressions += $impressions;
			$ctr_weighted      += ( $ctr * $impressions );
			$position_weighted += ( $position * $impressions );
			$normalized_rows[] = array(
				'page'        => $page,
				'clicks'      => round( $clicks, 2 ),
				'impressions' => round( $impressions, 2 ),
				'ctr'         => round( $ctr * 100, 2 ),
				'position'    => round( $position, 2 ),
			);
		}
		$avg_ctr      = $total_impressions > 0 ? ( $ctr_weighted / $total_impressions ) * 100 : 0;
		$avg_position = $total_impressions > 0 ? ( $position_weighted / $total_impressions ) : 0;

		return array(
			'ok'    => true,
			'start' => $start_date,
			'end'   => $end_date,
			'rows'  => $normalized_rows,
			'totals' => array(
				'clicks'      => round( $total_clicks, 2 ),
				'impressions' => round( $total_impressions, 2 ),
				'ctr'         => round( $avg_ctr, 2 ),
				'position'    => round( $avg_position, 2 ),
			),
		);
	}

	private function percent_delta( float $current, float $previous ): ?float {
		if ( $previous <= 0.0 ) {
			if ( $current <= 0.0 ) {
				return 0.0;
			}
			return null;
		}
		return round( ( ( $current - $previous ) / $previous ) * 100, 2 );
	}

	private function build_issue_trends( array $pages, array $trend ): array {
		$low_ctr = 0;
		$weak_position = 0;
		foreach ( $pages as $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}
			$impressions = isset( $page['impressions'] ) ? (float) $page['impressions'] : 0.0;
			$ctr         = isset( $page['ctr'] ) ? (float) $page['ctr'] : 0.0;
			$position    = isset( $page['position'] ) ? (float) $page['position'] : 0.0;
			if ( $impressions >= 100 && $ctr < 1.5 ) {
				++$low_ctr;
			}
			if ( $impressions >= 60 && $position > 20 ) {
				++$weak_position;
			}
		}

		$improving = 0;
		if ( isset( $trend['clicks_delta_pct'] ) && is_numeric( $trend['clicks_delta_pct'] ) && (float) $trend['clicks_delta_pct'] > 0 ) {
			++$improving;
		}
		if ( isset( $trend['impressions_delta_pct'] ) && is_numeric( $trend['impressions_delta_pct'] ) && (float) $trend['impressions_delta_pct'] > 0 ) {
			++$improving;
		}
		if ( isset( $trend['position_delta'] ) && is_numeric( $trend['position_delta'] ) && (float) $trend['position_delta'] < 0 ) {
			++$improving;
		}

		return array(
			'low_ctr_opportunities' => $low_ctr,
			'weak_position_pages'   => $weak_position,
			'improving_pages'       => $improving,
		);
	}

	private function enrich_report_with_actions( array $report ): array {
		$next_actions = array();
		$pages        = isset( $report['top_pages'] ) && is_array( $report['top_pages'] ) ? $report['top_pages'] : array();
		foreach ( $pages as $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}
			$impressions = isset( $page['impressions'] ) ? (float) $page['impressions'] : 0.0;
			$ctr         = isset( $page['ctr'] ) ? (float) $page['ctr'] : 0.0;
			$position    = isset( $page['position'] ) ? (float) $page['position'] : 0.0;
			$page_url    = isset( $page['page'] ) ? (string) $page['page'] : '';
			if ( $impressions >= 100 && $ctr < 1.5 ) {
				$next_actions[] = sprintf(
					/* translators: %s: page url */
					__( 'Improve title/meta snippet clarity on %s because impressions are high but CTR is low.', 'open-growth-seo' ),
					$page_url
				);
			}
			if ( $impressions >= 50 && $position > 20 ) {
				$next_actions[] = sprintf(
					/* translators: %s: page url */
					__( 'Prioritize content refresh and internal links for %s because average position is weak.', 'open-growth-seo' ),
					$page_url
				);
			}
			if ( count( $next_actions ) >= 3 ) {
				break;
			}
		}
		$issue_trends = isset( $report['issue_trends'] ) && is_array( $report['issue_trends'] ) ? $report['issue_trends'] : array();
		if ( (int) ( $issue_trends['low_ctr_opportunities'] ?? 0 ) >= 3 ) {
			$next_actions[] = __( 'Fix this first: improve titles/descriptions on high-impression pages with weak CTR.', 'open-growth-seo' );
		}
		if ( (int) ( $issue_trends['weak_position_pages'] ?? 0 ) >= 3 ) {
			$next_actions[] = __( 'Needs work: refresh weak-position pages and strengthen internal links from related hubs.', 'open-growth-seo' );
		}
		$trend = isset( $report['trend'] ) && is_array( $report['trend'] ) ? $report['trend'] : array();
		if ( isset( $trend['impressions_delta_pct'] ) && is_numeric( $trend['impressions_delta_pct'] ) && (float) $trend['impressions_delta_pct'] < -10 ) {
			$next_actions[] = __( 'Fix this first: impressions dropped sharply versus the previous window. Recheck index coverage and recent content changes.', 'open-growth-seo' );
		}
		if ( isset( $trend['position_delta'] ) && is_numeric( $trend['position_delta'] ) && (float) $trend['position_delta'] > 1.5 ) {
			$next_actions[] = __( 'Needs work: average position declined. Prioritize content refreshes on pages with stable impressions.', 'open-growth-seo' );
		}
		if ( empty( $next_actions ) ) {
			$next_actions[] = __( 'Use this report with Audits to prioritize pages with real impression potential.', 'open-growth-seo' );
		}
		$report['next_actions'] = array_values( array_slice( $next_actions, 0, 3 ) );
		return $report;
	}

	/**
	 * @param array<string, mixed> $snapshot
	 * @return array<int, array<string, mixed>>
	 */
	private function record_snapshot( string $property, array $snapshot ): array {
		$property = trim( $property );
		if ( '' === $property ) {
			return array();
		}
		$all = get_option( self::SNAPSHOT_OPTION, array() );
		if ( ! is_array( $all ) ) {
			$all = array();
		}
		$current = isset( $all[ $property ] ) && is_array( $all[ $property ] ) ? $all[ $property ] : array();
		$current[] = $snapshot;
		usort(
			$current,
			static function ( array $left, array $right ): int {
				return ( (int) ( $right['time'] ?? 0 ) ) <=> ( (int) ( $left['time'] ?? 0 ) );
			}
		);
		$current = array_slice( $current, 0, self::MAX_SNAPSHOTS_PER_PROPERTY );
		$all[ $property ] = $current;
		update_option( self::SNAPSHOT_OPTION, $all, false );
		return $current;
	}

	/**
	 * @param array<int, array<string, mixed>> $snapshots
	 * @return array<string, mixed>
	 */
	private function build_window_deltas( array $snapshots ): array {
		if ( count( $snapshots ) < 2 ) {
			return array(
				'ready' => false,
			);
		}
		$current = (array) ( $snapshots[0] ?? array() );
		$previous = (array) ( $snapshots[1] ?? array() );
		$current_totals = isset( $current['totals'] ) && is_array( $current['totals'] ) ? $current['totals'] : array();
		$previous_totals = isset( $previous['totals'] ) && is_array( $previous['totals'] ) ? $previous['totals'] : array();
		return array(
			'ready' => true,
			'clicks_delta_pct' => $this->percent_delta( (float) ( $current_totals['clicks'] ?? 0 ), (float) ( $previous_totals['clicks'] ?? 0 ) ),
			'impressions_delta_pct' => $this->percent_delta( (float) ( $current_totals['impressions'] ?? 0 ), (float) ( $previous_totals['impressions'] ?? 0 ) ),
			'current_window' => array(
				'start' => (string) ( $current['window_start'] ?? '' ),
				'end'   => (string) ( $current['window_end'] ?? '' ),
			),
			'previous_window' => array(
				'start' => (string) ( $previous['window_start'] ?? '' ),
				'end'   => (string) ( $previous['window_end'] ?? '' ),
			),
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function group_pages_for_ops( array $rows ): array {
		$groups = array(
			'low_ctr_high_impression' => array(),
			'weak_position'           => array(),
			'healthy'                 => array(),
		);
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$impressions = (float) ( $row['impressions'] ?? 0 );
			$ctr = (float) ( $row['ctr'] ?? 0 );
			$position = (float) ( $row['position'] ?? 0 );
			if ( $impressions >= 100 && $ctr < 1.5 ) {
				$groups['low_ctr_high_impression'][] = $row;
			} elseif ( $impressions >= 60 && $position > 20 ) {
				$groups['weak_position'][] = $row;
			} else {
				$groups['healthy'][] = $row;
			}
		}
		foreach ( $groups as $key => $items ) {
			$groups[ $key ] = array_slice( $items, 0, 12 );
		}
		return $groups;
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 * @param array<string, mixed> $issue_trends
	 * @param array<string, mixed> $trend
	 * @return array<int, array<string, mixed>>
	 */
	private function build_action_queue( array $rows, array $issue_trends, array $trend ): array {
		$queue = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$impressions = (float) ( $row['impressions'] ?? 0 );
			$ctr = (float) ( $row['ctr'] ?? 0 );
			$position = (float) ( $row['position'] ?? 0 );
			$page = (string) ( $row['page'] ?? '' );
			if ( '' === $page ) {
				continue;
			}
			$priority = 0;
			$reason = '';
			if ( $impressions >= 100 && $ctr < 1.5 ) {
				$priority = 90;
				$reason = __( 'High impressions with low CTR', 'open-growth-seo' );
			} elseif ( $impressions >= 60 && $position > 20 ) {
				$priority = 80;
				$reason = __( 'High visibility but weak average position', 'open-growth-seo' );
			} elseif ( $impressions >= 35 && $position > 10 && $ctr < 2.0 ) {
				$priority = 65;
				$reason = __( 'Mid-tier page with snippet and ranking improvement potential', 'open-growth-seo' );
			}
			if ( $priority <= 0 ) {
				continue;
			}
			$queue[] = array(
				'page'     => $page,
				'priority' => $priority,
				'reason'   => $reason,
				'action'   => __( 'Edit title/meta for intent clarity and strengthen internal links from related hubs.', 'open-growth-seo' ),
			);
		}

		if ( isset( $trend['impressions_delta_pct'] ) && is_numeric( $trend['impressions_delta_pct'] ) && (float) $trend['impressions_delta_pct'] < -10 ) {
			$queue[] = array(
				'page'     => '',
				'priority' => 95,
				'reason'   => __( 'Impressions trend dropped significantly', 'open-growth-seo' ),
				'action'   => __( 'Fix this first: inspect indexing and canonical consistency before content optimization.', 'open-growth-seo' ),
			);
		}
		if ( (int) ( $issue_trends['low_ctr_opportunities'] ?? 0 ) >= 3 ) {
			$queue[] = array(
				'page'     => '',
				'priority' => 88,
				'reason'   => __( 'Multiple low-CTR opportunities detected', 'open-growth-seo' ),
				'action'   => __( 'Create a snippet optimization batch for the top low-CTR pages.', 'open-growth-seo' ),
			);
		}

		usort(
			$queue,
			static function ( array $left, array $right ): int {
				return ( (int) ( $right['priority'] ?? 0 ) ) <=> ( (int) ( $left['priority'] ?? 0 ) );
			}
		);
		return array_slice( $queue, 0, 12 );
	}
}
