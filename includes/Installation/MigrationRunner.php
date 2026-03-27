<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\Installation;

defined( 'ABSPATH' ) || exit;

class MigrationRunner {
	/**
	 * @return array{state: array<string, mixed>, changed: bool, steps: array<int, string>}
	 */
	public static function migrate_state( array $state, array $settings, string $plugin_version, int $target_version ): array {
		if ( empty( $state ) ) {
			return array(
				'state'   => array(),
				'changed' => false,
				'steps'   => array(),
			);
		}

		$current_version = (int) ( $state['schema_version'] ?? 0 );
		$steps           = array();

		if ( $current_version > 0 && $current_version < 2 && $target_version >= 2 ) {
			$state['plugin_version']     = $plugin_version;
			$state['setup_complete']     = ! empty( $settings['wizard_completed'] ) ? 1 : 0;
			$state['wizard_recommended'] = empty( $settings['wizard_completed'] ) ? 1 : 0;
			$state['repair_actions']     = isset( $state['repair_actions'] ) && is_array( $state['repair_actions'] ) ? $state['repair_actions'] : array();
			$state['schema_version']     = 2;
			$steps[]                     = 'migrate_installation_state_v1_to_v2';
			$current_version             = 2;
		}

		if ( $current_version >= 2 && ( ! isset( $state['plugin_version'] ) || ! is_string( $state['plugin_version'] ) || '' === $state['plugin_version'] ) ) {
			$state['plugin_version'] = $plugin_version;
			$steps[]                 = 'backfill_installation_plugin_version';
		}
		if ( $current_version >= 2 && ! isset( $state['setup_complete'] ) ) {
			$state['setup_complete'] = ! empty( $settings['wizard_completed'] ) ? 1 : 0;
			$steps[]                 = 'backfill_installation_setup_complete';
		}
		if ( $current_version >= 2 && ! isset( $state['wizard_recommended'] ) ) {
			$state['wizard_recommended'] = empty( $settings['wizard_completed'] ) ? 1 : 0;
			$steps[]                     = 'backfill_installation_wizard_recommended';
		}
		if ( $current_version >= 2 && ! isset( $state['repair_actions'] ) ) {
			$state['repair_actions'] = array();
			$steps[]                 = 'backfill_installation_repair_actions';
		}
		if ( $current_version > 0 && $target_version > $current_version ) {
			$state['schema_version'] = $target_version;
		}

		return array(
			'state'   => $state,
			'changed' => ! empty( $steps ),
			'steps'   => $steps,
		);
	}
}
