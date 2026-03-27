<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\Integrations;

use OpenGrowthSolutions\OpenGrowthSEO\Support\Settings;

defined( 'ABSPATH' ) || exit;

class IntegrationLogger {
	private const OPTION_KEY = 'ogs_seo_integration_logs';
	private const MAX_ENTRIES = 100;

	public static function log( string $service, string $level, string $message, array $context = array() ): void {
		if ( ! Settings::get( 'integration_logs_enabled', 1 ) ) {
			return;
		}
		$service = sanitize_key( $service );
		$level   = sanitize_key( $level );
		$message = sanitize_text_field( self::redact_message( $message ) );
		if ( '' === $service || '' === $level || '' === $message ) {
			return;
		}
		$entry = array(
			'time'    => time(),
			'service' => $service,
			'level'   => $level,
			'message' => $message,
			'context' => self::redact_context( $context ),
		);
		$logs = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $logs ) ) {
			$logs = array();
		}
		$logs[] = $entry;
		if ( count( $logs ) > self::MAX_ENTRIES ) {
			$logs = array_slice( $logs, -self::MAX_ENTRIES );
		}
		update_option( self::OPTION_KEY, $logs, false );
	}

	private static function redact_context( array $context ): array {
		$clean = array();
		foreach ( $context as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			if ( preg_match( '/(token|secret|password|key|authorization)/i', $key ) ) {
				$clean[ $key ] = '[redacted]';
				continue;
			}
			$clean[ $key ] = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '[complex]';
		}
		return $clean;
	}

	private static function redact_message( string $message ): string {
		$message = (string) $message;
		$patterns = array(
			'/([?&](?:apikey|api_key|key|token|access_token|refresh_token|client_secret)=)[^&\s]+/i',
			'/(Bearer\s+)[A-Za-z0-9\-\._~\+\/]+=*/i',
		);
		$replacements = array(
			'$1[redacted]',
			'$1[redacted]',
		);
		$message = preg_replace( $patterns, $replacements, $message );
		return is_string( $message ) ? $message : '';
	}
}
