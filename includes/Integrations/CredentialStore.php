<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\Integrations;

defined( 'ABSPATH' ) || exit;

class CredentialStore {
	private const OPTION_KEY = 'ogs_seo_integration_secrets';
	private const META_OPTION_KEY = 'ogs_seo_integration_secret_meta';

	public static function get_all(): array {
		$current = get_option( self::OPTION_KEY, array() );
		return is_array( $current ) ? $current : array();
	}

	public static function get_service( string $service ): array {
		$service = sanitize_key( $service );
		$all     = self::get_all();
		$secrets = isset( $all[ $service ] ) && is_array( $all[ $service ] ) ? $all[ $service ] : array();
		$clean   = array();
		foreach ( $secrets as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			$decoded       = self::decrypt_value( (string) $value );
			$clean[ $key ] = self::normalize_secret_input( $decoded );
		}
		return $clean;
	}

	public static function set_service_secret( string $service, string $key, string $value ): void {
		$service = sanitize_key( $service );
		$key     = sanitize_key( $key );
		$value   = self::normalize_secret_input( $value );
		if ( '' === $service || '' === $key ) {
			return;
		}
		$all = self::get_all();
		if ( ! isset( $all[ $service ] ) || ! is_array( $all[ $service ] ) ) {
			$all[ $service ] = array();
		}
		if ( '' === $value ) {
			unset( $all[ $service ][ $key ] );
			self::set_secret_meta( $service, $key, false );
		} else {
			$all[ $service ][ $key ] = self::encrypt_value( $value );
			self::set_secret_meta( $service, $key, true, $value );
		}
		update_option( self::OPTION_KEY, $all, false );
	}

	public static function normalize_secret_input( string $value ): string {
		if ( function_exists( 'wp_check_invalid_utf8' ) ) {
			$value = wp_check_invalid_utf8( $value, true );
		}
		$value = trim( $value );
		$value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value );
		return is_string( $value ) ? trim( $value ) : '';
	}

	public static function has_required( string $service, array $required_keys ): bool {
		$secrets = self::get_service( $service );
		foreach ( $required_keys as $key ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key || empty( $secrets[ $key ] ) ) {
				return false;
			}
		}
		return true;
	}

	public static function clear_service( string $service ): void {
		$service = sanitize_key( $service );
		if ( '' === $service ) {
			return;
		}
		$all = self::get_all();
		unset( $all[ $service ] );
		update_option( self::OPTION_KEY, $all, false );
		$meta = self::get_all_meta();
		unset( $meta[ $service ] );
		update_option( self::META_OPTION_KEY, $meta, false );
	}

	public static function clear_service_secret( string $service, string $key ): void {
		self::set_service_secret( $service, $key, '' );
	}

	public static function encryption_available(): bool {
		return function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' ) && function_exists( 'random_bytes' ) && '' !== self::crypto_key();
	}

	public static function describe_service( string $service, array $required_keys = array() ): array {
		$service  = sanitize_key( $service );
		$secrets  = self::get_service( $service );
		$meta     = self::get_all_meta();
		$service_meta = isset( $meta[ $service ] ) && is_array( $meta[ $service ] ) ? $meta[ $service ] : array();
		$required = array_values( array_filter( array_map( 'sanitize_key', $required_keys ) ) );
		$fields   = array();
		$missing  = array();
		foreach ( array_values( array_unique( array_merge( array_keys( $service_meta ), array_keys( $secrets ), $required ) ) ) as $field ) {
			$field = sanitize_key( (string) $field );
			if ( '' === $field ) {
				continue;
			}
			$present = ! empty( $secrets[ $field ] );
			$updated = isset( $service_meta[ $field ]['updated_at'] ) ? absint( $service_meta[ $field ]['updated_at'] ) : 0;
			$fields[ $field ] = array(
				'present'    => $present,
				'updated_at' => $updated,
				'masked'     => $present ? self::mask_secret( (string) $secrets[ $field ] ) : '',
			);
			if ( in_array( $field, $required, true ) && ! $present ) {
				$missing[] = $field;
			}
		}
		return array(
			'stored_count'        => count( array_filter( $fields, static fn( array $item ): bool => ! empty( $item['present'] ) ) ),
			'required_count'      => count( $required ),
			'missing_required'    => $missing,
			'encryption_available'=> self::encryption_available(),
			'fields'              => $fields,
		);
	}

	private static function encrypt_value( string $value ): string {
		if ( '' === $value ) {
			return '';
		}
		if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'random_bytes' ) ) {
			return $value;
		}
		$key = self::crypto_key();
		if ( '' === $key ) {
			return $value;
		}
		try {
			$iv        = random_bytes( 16 );
			$encrypted = openssl_encrypt( $value, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
			if ( false === $encrypted ) {
				return $value;
			}
			return 'enc:v1:' . base64_encode( $iv ) . ':' . base64_encode( $encrypted );
		} catch ( \Throwable $e ) {
			unset( $e );
			return $value;
		}
	}

	private static function decrypt_value( string $stored ): string {
		$stored = trim( $stored );
		if ( '' === $stored ) {
			return '';
		}
		if ( ! str_starts_with( $stored, 'enc:v1:' ) ) {
			return $stored;
		}
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$parts = explode( ':', $stored, 4 );
		if ( 4 !== count( $parts ) ) {
			return '';
		}
		$iv = base64_decode( $parts[2], true );
		$cipher = base64_decode( $parts[3], true );
		if ( false === $iv || false === $cipher || 16 !== strlen( $iv ) ) {
			return '';
		}
		$key = self::crypto_key();
		if ( '' === $key ) {
			return '';
		}
		$plain = openssl_decrypt( $cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		return false === $plain ? '' : (string) $plain;
	}

	private static function crypto_key(): string {
		$salt = wp_salt( 'auth' );
		if ( '' === trim( (string) $salt ) ) {
			return '';
		}
		return hash( 'sha256', 'ogs_seo_integrations|' . $salt, true );
	}

	private static function get_all_meta(): array {
		$current = get_option( self::META_OPTION_KEY, array() );
		return is_array( $current ) ? $current : array();
	}

	private static function set_secret_meta( string $service, string $key, bool $present, string $value = '' ): void {
		$service = sanitize_key( $service );
		$key     = sanitize_key( $key );
		if ( '' === $service || '' === $key ) {
			return;
		}
		$meta = self::get_all_meta();
		if ( ! isset( $meta[ $service ] ) || ! is_array( $meta[ $service ] ) ) {
			$meta[ $service ] = array();
		}
		if ( ! $present ) {
			unset( $meta[ $service ][ $key ] );
		} else {
			$meta[ $service ][ $key ] = array(
				'updated_at' => time(),
				'length'     => strlen( $value ),
			);
		}
		update_option( self::META_OPTION_KEY, $meta, false );
	}

	private static function mask_secret( string $value ): string {
		$value = self::normalize_secret_input( $value );
		if ( '' === $value ) {
			return '';
		}
		$length = strlen( $value );
		if ( $length <= 6 ) {
			return str_repeat( '•', $length );
		}
		return substr( $value, 0, 2 ) . str_repeat( '•', max( 0, $length - 4 ) ) . substr( $value, -2 );
	}
}
