<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\Integrations;

use OpenGrowthSolutions\OpenGrowthSEO\Integrations\Contracts\IntegrationServiceInterface;
use OpenGrowthSolutions\OpenGrowthSEO\Support\Settings;

defined( 'ABSPATH' ) || exit;

class Manager {
	private const STATE_OPTION = 'ogs_seo_integration_state';

	public function register(): void {
		add_action( 'admin_init', array( $this, 'handle_secret_save' ), 20 );
		add_action( 'admin_post_ogs_seo_integration_test', array( $this, 'handle_test_action' ) );
		add_action( 'admin_post_ogs_seo_integration_disconnect', array( $this, 'handle_disconnect_action' ) );
		add_filter( 'ogs_seo_audit_checks', array( $this, 'register_audit_checks' ) );
	}

	public function register_audit_checks( array $checks ): array {
		$checks['integrations_health'] = array( $this, 'audit_integrations_health' );
		return $checks;
	}

	public function audit_integrations_health(): array {
		$statuses = self::get_statuses();
		$enabled  = 0;
		$misconfigured = 0;
		$failing = 0;
		foreach ( $statuses as $status ) {
			if ( empty( $status['enabled'] ) ) {
				continue;
			}
			++$enabled;
			if ( empty( $status['configured'] ) ) {
				++$misconfigured;
				continue;
			}
			if ( ! empty( $status['needs_attention'] ) && ! empty( $status['last_error'] ) ) {
				++$failing;
			}
		}
		if ( 0 === $enabled || ( 0 === $misconfigured && 0 === $failing ) ) {
			return array();
		}
		return array(
			'severity'       => $failing > 0 ? 'important' : 'minor',
			'title'          => $failing > 0 ? __( 'Some integrations need operator attention', 'open-growth-seo' ) : __( 'Some enabled integrations are not fully configured', 'open-growth-seo' ),
			'recommendation' => sprintf(
				/* translators: 1: misconfigured integrations, 2: failing integrations */
				__( 'Review Integrations settings: %1$d enabled integration(s) are missing required connection data and %2$d are failing recent connection checks.', 'open-growth-seo' ),
				$misconfigured,
				$failing
			),
		);
	}

	public static function get_services(): array {
		$services = array(
			new GoogleSearchConsole(),
			new BingWebmaster(),
			new GA4Reporting(),
		);
		return array_values(
			array_filter(
				$services,
				static fn( $service ) => $service instanceof IntegrationServiceInterface
			)
		);
	}

	public static function get_statuses(): array {
		$settings = Settings::get_all();
		$state    = self::get_state();
		$result   = array();
		foreach ( self::get_services() as $service ) {
			$slug = $service->slug();
			$service_state = isset( $state[ $slug ] ) && is_array( $state[ $slug ] ) ? $state[ $slug ] : array();
			$secrets = CredentialStore::get_service( $slug );
			$status = $service->status(
				$settings,
				$service_state,
				$secrets
			);
			$result[] = self::normalize_status_item( $service, $status, $service_state, $settings );
		}
		return $result;
	}

	public static function get_status_payload(): array {
		$statuses = self::get_statuses();
		$summary  = array(
			'total'      => count( $statuses ),
			'enabled'    => 0,
			'configured' => 0,
			'connected'  => 0,
			'attention'  => 0,
			'errors'     => 0,
		);
		foreach ( $statuses as $status ) {
			if ( ! empty( $status['enabled'] ) ) {
				++$summary['enabled'];
			}
			if ( ! empty( $status['configured'] ) ) {
				++$summary['configured'];
			}
			if ( ! empty( $status['connected'] ) ) {
				++$summary['connected'];
			}
			if ( ! empty( $status['needs_attention'] ) ) {
				++$summary['attention'];
			}
			if ( isset( $status['severity'] ) && 'critical' === $status['severity'] ) {
				++$summary['errors'];
			}
		}
		$summary['healthy'] = max( 0, (int) $summary['connected'] - (int) $summary['errors'] );
		return array(
			'summary' => $summary,
			'items'   => $statuses,
		);
	}

	public static function google_search_console_operational_report( bool $refresh = false ): array {
		$service  = new GoogleSearchConsole();
		$settings = Settings::get_all();
		$state    = self::get_state();
		$secrets  = CredentialStore::get_service( $service->slug() );
		return $service->operational_report(
			$settings,
			isset( $state[ $service->slug() ] ) && is_array( $state[ $service->slug() ] ) ? $state[ $service->slug() ] : array(),
			$secrets,
			$refresh
		);
	}

	public static function test_service( string $slug ): array {
		$service = self::find_service( $slug );
		if ( ! $service ) {
			return array(
				'ok'      => false,
				'message' => __( 'Unknown integration service.', 'open-growth-seo' ),
			);
		}
		$settings = Settings::get_all();
		$secrets  = CredentialStore::get_service( $service->slug() );
		$result   = $service->test_connection( $settings, $secrets );
		self::set_state_result( $service->slug(), ! empty( $result['ok'] ), (string) ( $result['message'] ?? '' ) );
		IntegrationLogger::log(
			$service->slug(),
			! empty( $result['ok'] ) ? 'info' : 'error',
			(string) ( $result['message'] ?? '' ),
			array( 'operation' => 'test_connection' )
		);
		return $result;
	}

	public static function disconnect_service( string $slug ): array {
		$service = self::find_service( $slug );
		if ( ! $service ) {
			return array(
				'ok'      => false,
				'message' => __( 'Unknown integration service.', 'open-growth-seo' ),
			);
		}
		CredentialStore::clear_service( $service->slug() );
		self::clear_runtime_state( $service );
		self::set_state_result( $service->slug(), false, __( 'Disconnected by administrator.', 'open-growth-seo' ) );

		$enabled_key = $service->enabled_setting_key();
		$settings    = Settings::get_all();
		if ( isset( $settings[ $enabled_key ] ) ) {
			$settings[ $enabled_key ] = 0;
			Settings::update( $settings );
		}

		IntegrationLogger::log(
			$service->slug(),
			'warning',
			'Disconnected by administrator.',
			array( 'operation' => 'disconnect' )
		);

		return array(
			'ok'      => true,
			'message' => __( 'Integration disconnected and stored credentials removed.', 'open-growth-seo' ),
		);
	}

	public function handle_secret_save(): void {
		if ( empty( $_POST['ogs_seo_action'] ) || 'save_settings' !== sanitize_key( (string) wp_unslash( $_POST['ogs_seo_action'] ) ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( empty( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( (string) wp_unslash( $_POST['_wpnonce'] ) ), 'ogs_seo_save_settings' ) ) {
			return;
		}
		$posted = isset( $_POST['ogs_secret'] ) ? (array) wp_unslash( $_POST['ogs_secret'] ) : array();
		$clear  = isset( $_POST['ogs_secret_clear'] ) ? (array) wp_unslash( $_POST['ogs_secret_clear'] ) : array();
		if ( empty( $posted ) ) {
			$posted = array();
		}
		foreach ( self::get_services() as $service ) {
			$slug = $service->slug();
			$fields = $service->secret_fields();
			$row = isset( $posted[ $slug ] ) && is_array( $posted[ $slug ] ) ? $posted[ $slug ] : array();
			$clear_row = isset( $clear[ $slug ] ) && is_array( $clear[ $slug ] ) ? $clear[ $slug ] : array();
			$changed = false;
			foreach ( $fields as $field ) {
				if ( ! empty( $clear_row[ $field ] ) ) {
					CredentialStore::clear_service_secret( $slug, $field );
					$changed = true;
					continue;
				}
				if ( ! array_key_exists( $field, $row ) ) {
					continue;
				}
				$value = CredentialStore::normalize_secret_input( (string) $row[ $field ] );
				if ( '' === $value ) {
					continue;
				}
				CredentialStore::set_service_secret( $slug, $field, $value );
				$changed = true;
			}
			if ( $changed ) {
				self::clear_runtime_state( $service );
				self::set_state_result( $slug, false, __( 'Credentials updated. Run connection test to verify.', 'open-growth-seo' ) );
			}
		}
	}

	public function handle_test_action(): void {
		$this->guard_admin_action();
		$slug   = isset( $_GET['integration'] ) ? sanitize_key( (string) wp_unslash( $_GET['integration'] ) ) : '';
		$page   = isset( $_GET['page'] ) ? sanitize_key( (string) wp_unslash( $_GET['page'] ) ) : 'ogs-seo-integrations';
		$result = self::test_service( $slug );
		add_settings_error( 'ogs_seo', 'integration_test', (string) $result['message'], ! empty( $result['ok'] ) ? 'updated' : 'error' );
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . $page ) );
		exit;
	}

	public function handle_disconnect_action(): void {
		$this->guard_admin_action();
		$slug   = isset( $_GET['integration'] ) ? sanitize_key( (string) wp_unslash( $_GET['integration'] ) ) : '';
		$page   = isset( $_GET['page'] ) ? sanitize_key( (string) wp_unslash( $_GET['page'] ) ) : 'ogs-seo-integrations';
		$result = self::disconnect_service( $slug );
		add_settings_error( 'ogs_seo', 'integration_disconnect', (string) $result['message'], ! empty( $result['ok'] ) ? 'updated' : 'error' );
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . $page ) );
		exit;
	}

	private function guard_admin_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage integrations.', 'open-growth-seo' ) );
		}
		check_admin_referer( 'ogs_seo_integration_action' );
	}

	private static function find_service( string $slug ): ?IntegrationServiceInterface {
		$slug = sanitize_key( $slug );
		foreach ( self::get_services() as $service ) {
			if ( $service->slug() === $slug ) {
				return $service;
			}
		}
		return null;
	}

	private static function get_state(): array {
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	private static function set_state_result( string $slug, bool $connected, string $message ): void {
		$slug  = sanitize_key( $slug );
		$state = self::get_state();
		if ( ! isset( $state[ $slug ] ) || ! is_array( $state[ $slug ] ) ) {
			$state[ $slug ] = array();
		}
		$state[ $slug ]['checked_at'] = time();
		$state[ $slug ]['connected']  = $connected ? 1 : 0;
		if ( $connected ) {
			$state[ $slug ]['last_success'] = time();
			$state[ $slug ]['last_error']   = '';
		} else {
			$state[ $slug ]['last_error'] = sanitize_text_field( $message );
		}
		update_option( self::STATE_OPTION, $state, false );
	}

	private static function normalize_status_item( IntegrationServiceInterface $service, array $status, array $state, array $settings ): array {
		$slug       = $service->slug();
		$enabled    = ! empty( $status['enabled'] );
		$configured = ! empty( $status['configured'] );
		$connected  = ! empty( $status['connected'] );
		$last_error = sanitize_text_field( (string) ( $status['last_error'] ?? '' ) );
		$required_settings = self::required_setting_keys( $slug );
		$missing_settings  = array();
		foreach ( $required_settings as $key ) {
			if ( '' === trim( (string) ( $settings[ $key ] ?? '' ) ) ) {
				$missing_settings[] = $key;
			}
		}
		$secret_summary = CredentialStore::describe_service( $slug, $service->secret_fields() );
		$missing_secrets = isset( $secret_summary['missing_required'] ) && is_array( $secret_summary['missing_required'] ) ? $secret_summary['missing_required'] : array();

		$state_key   = 'inactive';
		$state_label = __( 'Inactive', 'open-growth-seo' );
		$severity    = 'minor';
		if ( $enabled && ! $configured ) {
			$state_key   = 'incomplete';
			$state_label = __( 'Incomplete', 'open-growth-seo' );
			$severity    = 'important';
		} elseif ( $configured && ! $connected ) {
			$state_key   = '' !== $last_error ? 'error' : 'configured';
			$state_label = '' !== $last_error ? __( 'Connection issue', 'open-growth-seo' ) : __( 'Ready to verify', 'open-growth-seo' );
			$severity    = '' !== $last_error ? 'critical' : 'important';
		} elseif ( $connected ) {
			$state_key   = 'connected';
			$state_label = __( 'Connected', 'open-growth-seo' );
			$severity    = 'good';
		}

		$status['checked_at']        = absint( $state['checked_at'] ?? 0 );
		$status['state_key']         = $state_key;
		$status['state_label']       = $state_label;
		$status['severity']          = $severity;
		$status['needs_attention']   = in_array( $severity, array( 'important', 'critical' ), true );
		$status['missing_settings']  = $missing_settings;
		$status['missing_settings_labels'] = array_map( array( __CLASS__, 'human_label_for_key' ), $missing_settings );
		$status['missing_secrets']   = $missing_secrets;
		$status['missing_secrets_labels'] = array_map( array( __CLASS__, 'human_label_for_key' ), $missing_secrets );
		$status['required_settings'] = $required_settings;
		$status['secret_summary']    = $secret_summary;
		return $status;
	}

	private static function required_setting_keys( string $slug ): array {
		switch ( sanitize_key( $slug ) ) {
			case 'google_search_console':
				return array( 'gsc_property_url' );
			case 'bing_webmaster':
				return array( 'bing_site_url' );
			case 'ga4_reporting':
				return array( 'ga4_measurement_id' );
			default:
				return array();
		}
	}

	private static function clear_runtime_state( IntegrationServiceInterface $service ): void {
		if ( method_exists( $service, 'clear_runtime_state' ) ) {
			$service->clear_runtime_state();
		}
	}

	private static function human_label_for_key( string $key ): string {
		$map = array(
			'gsc_property_url' => __( 'Property URL', 'open-growth-seo' ),
			'bing_site_url'    => __( 'Site URL', 'open-growth-seo' ),
			'ga4_measurement_id' => __( 'Measurement ID', 'open-growth-seo' ),
			'client_id'        => __( 'Client ID', 'open-growth-seo' ),
			'client_secret'    => __( 'Client Secret', 'open-growth-seo' ),
			'refresh_token'    => __( 'Refresh Token', 'open-growth-seo' ),
			'api_key'          => __( 'API Key', 'open-growth-seo' ),
			'api_secret'       => __( 'API Secret', 'open-growth-seo' ),
		);
		$key = sanitize_key( $key );
		return isset( $map[ $key ] ) ? $map[ $key ] : ucwords( str_replace( '_', ' ', $key ) );
	}
}
