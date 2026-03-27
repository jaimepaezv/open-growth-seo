<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\Support;

defined( 'ABSPATH' ) || exit;

class ExperienceMode {
	public const SIMPLE = 'simple';
	public const ADVANCED = 'advanced';
	public const REVEAL_QUERY_ARG = 'ogs_show_advanced';

	public static function normalize( string $mode ): string {
		$mode = sanitize_key( $mode );
		return in_array( $mode, array( self::SIMPLE, self::ADVANCED ), true ) ? $mode : self::SIMPLE;
	}

	public static function current( array $settings = array() ): string {
		if ( empty( $settings ) ) {
			$settings = Settings::get_all();
		}
		return self::normalize( (string) ( $settings['mode'] ?? self::SIMPLE ) );
	}

	public static function is_simple( array $settings = array() ): bool {
		return self::SIMPLE === self::current( $settings );
	}

	public static function is_advanced_revealed(): bool {
		return isset( $_GET[ self::REVEAL_QUERY_ARG ] ) && '1' === sanitize_key( (string) wp_unslash( $_GET[ self::REVEAL_QUERY_ARG ] ) );
	}

	public static function hides_advanced( array $settings = array() ): bool {
		return self::is_simple( $settings ) && ! self::is_advanced_revealed();
	}

	public static function editor_section_defaults( array $settings = array() ): array {
		$simple = self::is_simple( $settings );
		$masters_plus_open = ! $simple && ! empty( $settings['seo_masters_plus_enabled'] );
		return array(
			'basics'           => true,
			'social'           => true,
			'advanced_snippet' => ! $simple,
			'schema'           => ! $simple,
			'aeo_hints'        => true,
			'masters_plus'     => $masters_plus_open,
			'preview'          => true,
		);
	}
}
