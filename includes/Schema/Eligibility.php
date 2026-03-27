<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\Schema;

defined( 'ABSPATH' ) || exit;

class Eligibility {
	/**
	 * @return array<string, string>
	 */
	public static function supported_types(): array {
		$records = SupportMatrix::content_types();
		$types   = array();
		foreach ( $records as $type => $meta ) {
			$types[ $type ] = isset( $meta['label'] ) ? (string) $meta['label'] : (string) $type;
		}
		return $types;
	}

	/**
	 * @return array<string, string>
	 */
	public static function simple_mode_types(): array {
		return array(
			'Article'     => 'Article',
			'BlogPosting' => 'BlogPosting',
		);
	}

	/**
	 * @param array<string, mixed> $settings
	 * @return array<string, string>
	 */
	public static function option_labels( int $post_id, array $settings, bool $simple_mode ): array {
		$options = array(
			'' => __( 'Auto (recommended)', 'open-growth-seo' ),
		);
		foreach ( self::option_records( $post_id, $settings, $simple_mode ) as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			$value = isset( $record['value'] ) ? (string) $record['value'] : '';
			$label = isset( $record['label'] ) ? (string) $record['label'] : '';
			if ( '' === $value || '' === $label ) {
				continue;
			}
			$options[ $value ] = $label;
		}
		return $options;
	}

	/**
	 * @param array<string, mixed> $settings
	 * @return array<int, array<string, mixed>>
	 */
	public static function option_records( int $post_id, array $settings, bool $simple_mode ): array {
		$records = array(
			array(
				'value'   => '',
				'label'   => __( 'Auto (recommended)', 'open-growth-seo' ),
				'reason'  => __( 'Let Open Growth SEO choose the safest contextual schema for this content.', 'open-growth-seo' ),
				'support' => SupportMatrix::SUPPORT_INTERNAL,
				'risk'    => 'low',
			),
		);

		foreach ( self::supported_types() as $type => $label ) {
			if ( $simple_mode && ! array_key_exists( $type, self::simple_mode_types() ) ) {
				continue;
			}
			$eligibility = self::evaluate_for_post( $type, $post_id, $settings );
			if ( empty( $eligibility['eligible'] ) ) {
				continue;
			}
			$support = isset( $eligibility['support'] ) ? (string) $eligibility['support'] : SupportMatrix::SUPPORT_VOCAB;
			$records[] = array(
				'value'         => $type,
				'label'         => sprintf(
					/* translators: 1: schema label, 2: support label */
					__( '%1$s (%2$s)', 'open-growth-seo' ),
					$label,
					SupportMatrix::support_badge( $support )
				),
				'reason'        => (string) ( $eligibility['reason'] ?? '' ),
				'support'       => $support,
				'support_label' => SupportMatrix::support_badge( $support ),
				'risk'          => (string) ( $eligibility['risk'] ?? 'medium' ),
				'risk_label'    => SupportMatrix::risk_badge( (string) ( $eligibility['risk'] ?? 'medium' ) ),
			);
		}

		return $records;
	}

	/**
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	public static function evaluate_for_post( string $type, int $post_id, array $settings ): array {
		return EligibilityEngine::evaluate_for_post( $type, $post_id, $settings );
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	public static function sanitize_override_for_post( string $value, int $post_id, array $settings ): string {
		$value = trim( sanitize_text_field( $value ) );
		if ( '' === $value ) {
			return '';
		}
		$eligibility = self::evaluate_for_post( $value, $post_id, $settings );
		return ! empty( $eligibility['eligible'] ) ? $value : '';
	}
}
