<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\Installation;

use OpenGrowthSolutions\OpenGrowthSEO\Support\DeveloperTools;
use OpenGrowthSolutions\OpenGrowthSEO\Support\Settings;

defined( 'ABSPATH' ) || exit;

class Manager {
	public const STATE_OPTION = 'ogs_seo_installation_state';
	public const HISTORY_OPTION = 'ogs_seo_installation_history';
	private const SCHEMA_VERSION = 2;
	private const VERSION_OPTION = 'ogs_seo_installation_plugin_version';
	private const HISTORY_LIMIT = 24;

	private Diagnostics $diagnostics;
	private Configurator $configurator;

	public function __construct( ?Diagnostics $diagnostics = null, ?Configurator $configurator = null ) {
		$this->diagnostics  = $diagnostics ?: new Diagnostics();
		$this->configurator = $configurator ?: new Configurator();
	}

	public function register(): void {
		add_action( 'admin_init', array( $this, 'ensure_state' ) );
	}

	public static function bootstrap_after_activation( bool $fresh_install ): array {
		$manager = new self();
		return $manager->bootstrap( 'activation', true, $fresh_install );
	}

	public function ensure_state(): void {
		$state = $this->get_state();
		$migration = MigrationRunner::migrate_state( $state, Settings::get_all(), $this->plugin_version(), self::SCHEMA_VERSION );
		if ( ! empty( $migration['changed'] ) ) {
			$state = isset( $migration['state'] ) && is_array( $migration['state'] ) ? $migration['state'] : $state;
			update_option( self::STATE_OPTION, $state, false );
		}
		if ( ! $this->needs_bootstrap( $state ) ) {
			return;
		}

		$source = empty( $state ) ? 'backfill' : 'repair';
		if ( $this->version_changed( $state ) ) {
			$source = 'upgrade';
		}

		$this->bootstrap( $source, false, false );
	}

	public function rebuild_state( string $source = 'rebuild', bool $apply_recommendations = false ): array {
		delete_option( self::STATE_OPTION );
		return $this->bootstrap( $source, $apply_recommendations, false );
	}

	public function rerun( string $source = 'manual', bool $apply_recommendations = true ): array {
		return $this->bootstrap( $source, $apply_recommendations, false );
	}

	public function bootstrap( string $source = 'manual', bool $apply_recommendations = true, bool $fresh_install = false ): array {
		$repair_actions    = array();
		$raw_settings      = get_option( 'ogs_seo_settings', array() );
		$settings_corrupt  = ! is_array( $raw_settings );
		$raw_settings      = is_array( $raw_settings ) ? $raw_settings : array();
		$current_settings  = Settings::get_all();
		$previous_state    = $this->get_state();
		$state_corrupt     = ! empty( $previous_state ) && ! $this->is_valid_state( $previous_state );

		if ( $settings_corrupt ) {
			$repair_actions[] = 'settings_option_repaired';
			Settings::update( $current_settings );
		}
		if ( $state_corrupt ) {
			$repair_actions[] = 'state_option_repaired';
		}
		if ( $this->version_changed( $previous_state ) ) {
			$repair_actions[] = 'plugin_version_advanced';
		}

		$diagnostics      = $this->diagnostics->run();
		$recommendations  = $this->configurator->recommendations( $diagnostics, $current_settings );
		$apply_result     = array(
			'settings' => $current_settings,
			'applied'  => array(),
			'skipped'  => array(),
			'changed'  => 0,
		);

		if ( $apply_recommendations ) {
			$apply_result = $this->configurator->apply( $current_settings, $raw_settings, $recommendations, $previous_state, $fresh_install );
			if ( ! empty( $apply_result['changed'] ) ) {
				Settings::update( $apply_result['settings'] );
				$current_settings = Settings::get_all();
			} else {
				$current_settings = $apply_result['settings'];
			}
		}

		$pending = $this->configurator->pending_items( $diagnostics, $current_settings );
		$status  = self::status_from_state( $diagnostics, $pending );
		$events  = isset( $previous_state['events'] ) && is_array( $previous_state['events'] ) ? $previous_state['events'] : array();
		$events[] = array(
			'time'    => time(),
			'source'  => sanitize_key( $source ),
			'status'  => $status,
			'applied' => (int) ( $apply_result['changed'] ?? 0 ),
			'repairs' => array_values( $repair_actions ),
		);
		$events = array_slice( $events, -12 );

		$state = array(
			'schema_version'    => self::SCHEMA_VERSION,
			'plugin_version'    => $this->plugin_version(),
			'last_run'          => time(),
			'source'            => sanitize_key( $source ),
			'status'            => $status,
			'fresh_install'     => $fresh_install ? 1 : 0,
			'setup_complete'    => ! empty( $current_settings['wizard_completed'] ) ? 1 : 0,
			'wizard_recommended' => ( 'ready' !== $status || empty( $current_settings['wizard_completed'] ) ) ? 1 : 0,
			'classification'    => $diagnostics['classification'],
			'diagnostics'       => $diagnostics,
			'recommendations'   => $recommendations,
			'applied_settings'  => $apply_result['applied'],
			'skipped_settings'  => $apply_result['skipped'],
			'pending'           => $pending,
			'repair_actions'    => array_values( $repair_actions ),
			'events'            => $events,
		);

		$state = apply_filters( 'ogs_seo_installation_state', $state, $diagnostics, $apply_result, $current_settings );
		update_option( self::STATE_OPTION, $state, false );
		update_option( self::VERSION_OPTION, $this->plugin_version(), false );
		DeveloperTools::clear_diagnostics_cache();
		$this->append_history_entry(
			array(
				'time'               => time(),
				'source'             => sanitize_key( $source ),
				'status'             => $status,
				'applied'            => (int) ( $apply_result['changed'] ?? 0 ),
				'pending'            => count( (array) $pending ),
				'repair_count'       => count( $repair_actions ),
				'repairs'            => array_values( $repair_actions ),
				'setup_complete'     => ! empty( $state['setup_complete'] ),
				'wizard_recommended' => ! empty( $state['wizard_recommended'] ),
				'fresh_install'      => $fresh_install ? 1 : 0,
			)
		);

		DeveloperTools::log(
			'info',
			'Installation diagnostics executed.',
			array(
				'source'        => sanitize_key( $source ),
				'status'        => $status,
				'primary_type'  => (string) ( $state['classification']['primary'] ?? 'general' ),
				'applied_count' => (string) ( $apply_result['changed'] ?? 0 ),
				'pending_count' => (string) count( (array) $pending ),
				'repairs'       => implode( ',', $repair_actions ),
			)
		);

		return $state;
	}

	public function get_state(): array {
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	public function summary(): array {
		$state          = $this->get_state();
		$classification = isset( $state['classification'] ) && is_array( $state['classification'] ) ? $state['classification'] : array();
		$pending        = isset( $state['pending'] ) && is_array( $state['pending'] ) ? $state['pending'] : array();
		$repairs        = isset( $state['repair_actions'] ) && is_array( $state['repair_actions'] ) ? $state['repair_actions'] : array();
		$history        = $this->history( self::HISTORY_LIMIT );
		$repair_runs    = 0;
		$rebuilds       = 0;
		$last_repair    = 0;
		$last_rebuild   = 0;

		foreach ( $history as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$source = isset( $entry['source'] ) ? (string) $entry['source'] : '';
			$time   = isset( $entry['time'] ) ? (int) $entry['time'] : 0;
			if ( ! empty( $entry['repair_count'] ) ) {
				++$repair_runs;
				if ( $time > $last_repair ) {
					$last_repair = $time;
				}
			}
			if ( false !== strpos( $source, 'rebuild' ) ) {
				++$rebuilds;
				if ( $time > $last_rebuild ) {
					$last_rebuild = $time;
				}
			}
		}

		return array(
			'status'             => (string) ( $state['status'] ?? 'partial_configuration' ),
			'last_run'           => (int) ( $state['last_run'] ?? 0 ),
			'source'             => (string) ( $state['source'] ?? '' ),
			'primary'            => (string) ( $classification['primary'] ?? 'general' ),
			'secondary'          => array_values( (array) ( $classification['secondary'] ?? array() ) ),
			'confidence'         => (int) ( $classification['confidence'] ?? 0 ),
			'pending'            => count( $pending ),
			'applied'            => count( (array) ( $state['applied_settings'] ?? array() ) ),
			'plugin_version'     => (string) ( $state['plugin_version'] ?? $this->plugin_version() ),
			'setup_complete'     => ! empty( $state['setup_complete'] ),
			'wizard_recommended' => ! empty( $state['wizard_recommended'] ),
			'repairs'            => count( $repairs ),
			'repair_runs'        => $repair_runs,
			'rebuilds'           => $rebuilds,
			'last_repair'        => $last_repair,
			'last_rebuild'       => $last_rebuild,
		);
	}

	public function history( int $limit = 10 ): array {
		$history = get_option( self::HISTORY_OPTION, array() );
		if ( ! is_array( $history ) ) {
			return array();
		}

		$limit = max( 1, min( self::HISTORY_LIMIT, $limit ) );
		return array_slice( array_values( $history ), -$limit );
	}

	public function telemetry( int $limit = 10, string $filter = 'all', string $group = 'none' ): array {
		$entries = $this->history( $limit );
		$entries = array_values(
			array_filter(
				$entries,
				static function ( $entry ) use ( $filter ): bool {
					if ( ! is_array( $entry ) ) {
						return false;
					}
					switch ( $filter ) {
						case 'rebuilds':
							return false !== strpos( (string) ( $entry['source'] ?? '' ), 'rebuild' );
						case 'repairs':
							return ! empty( $entry['repair_count'] );
						case 'setup-review':
							return ! empty( $entry['wizard_recommended'] );
						default:
							return true;
					}
				}
			)
		);

		$groups = array();
		foreach ( $entries as $entry ) {
			$key = 'all';
			switch ( $group ) {
				case 'source':
					$key = (string) ( $entry['source'] ?? 'unknown' );
					break;
				case 'day':
					$timestamp = isset( $entry['time'] ) ? (int) $entry['time'] : 0;
					$key       = $timestamp > 0 ? wp_date( 'Y-m-d', $timestamp ) : 'unknown';
					break;
			}
			if ( ! isset( $groups[ $key ] ) || ! is_array( $groups[ $key ] ) ) {
				$groups[ $key ] = array();
			}
			$groups[ $key ][] = $entry;
		}

		return array(
			'filter'  => $filter,
			'group'   => $group,
			'entries' => $entries,
			'groups'  => $groups,
			'summary' => $this->summary(),
		);
	}

	public static function status_from_state( array $diagnostics, array $pending ): string {
		$findings = isset( $diagnostics['findings'] ) && is_array( $diagnostics['findings'] ) ? $diagnostics['findings'] : array();
		foreach ( $findings as $finding ) {
			if ( isset( $finding['severity'] ) && 'critical' === $finding['severity'] ) {
				return 'needs_attention';
			}
		}
		foreach ( $findings as $finding ) {
			if ( isset( $finding['severity'] ) && 'warning' === $finding['severity'] ) {
				return 'needs_attention';
			}
		}
		if ( ! empty( $pending ) ) {
			return 'partial_configuration';
		}
		return 'ready';
	}

	private function needs_bootstrap( array $state ): bool {
		if ( empty( $state ) ) {
			return true;
		}

		if ( ! $this->is_valid_state( $state ) ) {
			return true;
		}

		return $this->version_changed( $state );
	}

	private function is_valid_state( array $state ): bool {
		if ( (int) ( $state['schema_version'] ?? 0 ) < self::SCHEMA_VERSION ) {
			return false;
		}

		if ( empty( $state['status'] ) || empty( $state['classification'] ) || ! isset( $state['diagnostics'] ) ) {
			return false;
		}

		if ( ! is_array( $state['classification'] ) || ! is_array( $state['diagnostics'] ) ) {
			return false;
		}

		if ( ! isset( $state['pending'] ) || ! is_array( $state['pending'] ) ) {
			return false;
		}

		if ( ! isset( $state['recommendations'] ) || ! is_array( $state['recommendations'] ) ) {
			return false;
		}

		return true;
	}

	private function version_changed( array $state ): bool {
		$recorded = isset( $state['plugin_version'] ) ? (string) $state['plugin_version'] : (string) get_option( self::VERSION_OPTION, '' );
		return '' === $recorded || $recorded !== $this->plugin_version();
	}

	private function plugin_version(): string {
		return defined( 'OGS_SEO_VERSION' ) ? (string) OGS_SEO_VERSION : 'unknown';
	}

	private function append_history_entry( array $entry ): void {
		$history = get_option( self::HISTORY_OPTION, array() );
		if ( ! is_array( $history ) ) {
			$history = array();
		}
		$history[] = $entry;
		if ( count( $history ) > self::HISTORY_LIMIT ) {
			$history = array_slice( $history, -self::HISTORY_LIMIT );
		}
		update_option( self::HISTORY_OPTION, array_values( $history ), false );
	}
}
