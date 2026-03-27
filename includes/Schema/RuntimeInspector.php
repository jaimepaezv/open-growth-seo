<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\Schema;

defined( 'ABSPATH' ) || exit;

class RuntimeInspector {
	private const HISTORY_OPTION = 'ogs_seo_schema_inspector_history';
	private const MAX_PROBE_HISTORY = 8;
	private const MAX_PROBES = 30;

	/**
	 * @param array<string, mixed> $raw
	 * @return array<string, mixed>
	 */
	public static function normalize_probe( array $raw ): array {
		$post_id = absint( $raw['post_id'] ?? 0 );
		$url     = '';
		if ( isset( $raw['url'] ) ) {
			$url = esc_url_raw( (string) $raw['url'] );
		}
		if ( '' !== $url && str_starts_with( $url, '/' ) ) {
			$url = esc_url_raw( home_url( $url ) );
		}
		return array(
			'post_id' => $post_id,
			'url'     => $url,
		);
	}

	/**
	 * @param array<string, mixed> $inspect
	 */
	public static function summary( array $inspect ): array {
		$graph  = isset( $inspect['payload']['@graph'] ) && is_array( $inspect['payload']['@graph'] ) ? $inspect['payload']['@graph'] : array();
		$errors = isset( $inspect['errors'] ) && is_array( $inspect['errors'] ) ? $inspect['errors'] : array();
		$warnings = isset( $inspect['warnings'] ) && is_array( $inspect['warnings'] ) ? $inspect['warnings'] : array();
		$conflicts = isset( $inspect['conflicts'] ) && is_array( $inspect['conflicts'] ) ? $inspect['conflicts'] : array();
		$context = isset( $inspect['context'] ) && is_array( $inspect['context'] ) ? $inspect['context'] : array();
		$conflict_totals = ConflictEngine::summary( $conflicts );
		$nodes = array();
		foreach ( $graph as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$type = isset( $node['@type'] ) ? sanitize_text_field( (string) $node['@type'] ) : '';
			if ( '' === $type ) {
				continue;
			}
			$nodes[ $type ] = isset( $nodes[ $type ] ) ? $nodes[ $type ] + 1 : 1;
		}
		return array(
			'url'           => (string) ( $context['url'] ?? '' ),
			'post_id'       => absint( $context['post_id'] ?? 0 ),
			'post_type'     => sanitize_key( (string) ( $context['post_type'] ?? '' ) ),
			'is_noindex'    => ! empty( $context['is_noindex'] ),
			'is_redirect'   => ! empty( $context['is_redirect'] ),
			'is_indexable'  => ! empty( $context['is_indexable'] ),
			'nodes_emitted' => count( $graph ),
			'nodes_by_type' => $nodes,
			'errors'        => count( $errors ),
			'warnings'      => count( $warnings ),
			'conflicts'     => count( $conflicts ),
			'conflict_totals' => $conflict_totals,
		);
	}

	/**
	 * @param array<string, mixed> $inspect
	 * @return array<string, mixed>
	 */
	public static function record_snapshot( array $inspect ): array {
		$current = self::build_snapshot( $inspect );
		$key     = (string) ( $current['probe_key'] ?? 'global' );
		$store   = get_option( self::HISTORY_OPTION, array() );
		$store   = is_array( $store ) ? $store : array();
		$history = isset( $store[ $key ] ) && is_array( $store[ $key ] ) ? $store[ $key ] : array();
		$previous = isset( $history[0] ) && is_array( $history[0] ) ? $history[0] : array();

		array_unshift( $history, $current );
		$history       = array_slice( $history, 0, self::MAX_PROBE_HISTORY );
		$store[ $key ] = $history;
		$store         = self::trim_probe_store( $store );

		update_option( self::HISTORY_OPTION, $store, false );

		return array(
			'current'  => $current,
			'previous' => $previous,
			'diff'     => self::diff_snapshots( $current, $previous ),
			'history'  => $history,
		);
	}

	/**
	 * @param array<string, mixed> $inspect
	 * @return array<string, mixed>
	 */
	private static function build_snapshot( array $inspect ): array {
		$summary       = self::summary( $inspect );
		$payload       = isset( $inspect['payload'] ) ? $inspect['payload'] : array();
		$type_statuses = isset( $inspect['type_statuses'] ) && is_array( $inspect['type_statuses'] ) ? $inspect['type_statuses'] : array();
		$expected      = array();
		$emitted       = array_keys( isset( $summary['nodes_by_type'] ) && is_array( $summary['nodes_by_type'] ) ? $summary['nodes_by_type'] : array() );

		foreach ( $type_statuses as $type => $row ) {
			if ( ! is_array( $row ) || empty( $row['eligible'] ) ) {
				continue;
			}
			$expected[] = (string) $type;
		}

		sort( $expected );
		sort( $emitted );

		$url     = (string) ( $summary['url'] ?? '' );
		$post_id = absint( $summary['post_id'] ?? 0 );
		$key     = $post_id > 0 ? 'post:' . $post_id : 'url:' . sanitize_key( substr( md5( $url ), 0, 16 ) );

		return array(
			'captured_at'           => time(),
			'probe_key'             => $key,
			'probe_label'           => $post_id > 0 ? 'post:' . $post_id : $url,
			'url'                   => $url,
			'post_id'               => $post_id,
			'post_type'             => (string) ( $summary['post_type'] ?? '' ),
			'payload_hash'          => md5( wp_json_encode( $payload ) ?: '' ),
			'nodes_emitted'         => (int) ( $summary['nodes_emitted'] ?? 0 ),
			'nodes_by_type'         => isset( $summary['nodes_by_type'] ) && is_array( $summary['nodes_by_type'] ) ? $summary['nodes_by_type'] : array(),
			'conflict_totals'       => isset( $summary['conflict_totals'] ) && is_array( $summary['conflict_totals'] ) ? $summary['conflict_totals'] : array(),
			'expected_types'        => $expected,
			'emitted_types'         => $emitted,
			'expected_not_emitted'  => array_values( array_diff( $expected, $emitted ) ),
			'unexpected_emitted'    => array_values( array_diff( $emitted, $expected ) ),
		);
	}

	/**
	 * @param array<string, mixed> $current
	 * @param array<string, mixed> $previous
	 * @return array<string, mixed>
	 */
	private static function diff_snapshots( array $current, array $previous ): array {
		if ( empty( $previous ) ) {
			return array(
				'has_previous' => false,
				'message'      => __( 'No previous inspection snapshot is available for this target yet.', 'open-growth-seo' ),
			);
		}

		$current_nodes  = isset( $current['nodes_by_type'] ) && is_array( $current['nodes_by_type'] ) ? $current['nodes_by_type'] : array();
		$previous_nodes = isset( $previous['nodes_by_type'] ) && is_array( $previous['nodes_by_type'] ) ? $previous['nodes_by_type'] : array();
		$node_deltas    = array();
		$all_types      = array_values( array_unique( array_merge( array_keys( $current_nodes ), array_keys( $previous_nodes ) ) ) );
		sort( $all_types );
		foreach ( $all_types as $type ) {
			$delta = (int) ( $current_nodes[ $type ] ?? 0 ) - (int) ( $previous_nodes[ $type ] ?? 0 );
			if ( 0 === $delta ) {
				continue;
			}
			$node_deltas[ $type ] = $delta;
		}

		$current_conflicts  = isset( $current['conflict_totals'] ) && is_array( $current['conflict_totals'] ) ? $current['conflict_totals'] : array();
		$previous_conflicts = isset( $previous['conflict_totals'] ) && is_array( $previous['conflict_totals'] ) ? $previous['conflict_totals'] : array();

		return array(
			'has_previous'            => true,
			'payload_changed'         => (string) ( $current['payload_hash'] ?? '' ) !== (string) ( $previous['payload_hash'] ?? '' ),
			'nodes_delta'             => (int) ( $current['nodes_emitted'] ?? 0 ) - (int) ( $previous['nodes_emitted'] ?? 0 ),
			'node_type_deltas'        => $node_deltas,
			'expected_not_emitted'    => array_values( array_unique( array_map( 'strval', (array) ( $current['expected_not_emitted'] ?? array() ) ) ) ),
			'unexpected_emitted'      => array_values( array_unique( array_map( 'strval', (array) ( $current['unexpected_emitted'] ?? array() ) ) ) ),
			'conflict_delta'          => array(
				'critical'  => (int) ( $current_conflicts['critical'] ?? 0 ) - (int) ( $previous_conflicts['critical'] ?? 0 ),
				'important' => (int) ( $current_conflicts['important'] ?? 0 ) - (int) ( $previous_conflicts['important'] ?? 0 ),
				'minor'     => (int) ( $current_conflicts['minor'] ?? 0 ) - (int) ( $previous_conflicts['minor'] ?? 0 ),
			),
			'previous_captured_at'    => (int) ( $previous['captured_at'] ?? 0 ),
		);
	}

	/**
	 * @param array<string, array<int, array<string, mixed>>> $store
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private static function trim_probe_store( array $store ): array {
		if ( count( $store ) <= self::MAX_PROBES ) {
			return $store;
		}
		uasort(
			$store,
			static function ( $left, $right ): int {
				$left_time  = isset( $left[0]['captured_at'] ) ? (int) $left[0]['captured_at'] : 0;
				$right_time = isset( $right[0]['captured_at'] ) ? (int) $right[0]['captured_at'] : 0;
				return $right_time <=> $left_time;
			}
		);
		return array_slice( $store, 0, self::MAX_PROBES, true );
	}
}
