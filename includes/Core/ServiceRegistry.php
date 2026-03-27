<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\Core;

defined( 'ABSPATH' ) || exit;

class ServiceRegistry {
	/**
	 * @var array<string, array<string, mixed>>
	 */
	private array $definitions = array();

	/**
	 * @var array<string, object>
	 */
	private array $services = array();

	private bool $registered = false;

	/**
	 * @param array<string, array<string, mixed>> $definitions
	 */
	public function __construct( array $definitions ) {
		$this->definitions = $definitions;
	}

	public function register(): void {
		if ( $this->registered ) {
			return;
		}

		foreach ( $this->definitions as $service_id => $definition ) {
			if ( ! is_array( $definition ) || ! $this->should_register( $definition ) ) {
				continue;
			}

			$service = $this->resolve_service( $definition );
			if ( ! is_object( $service ) || ! method_exists( $service, 'register' ) ) {
				continue;
			}

			$service->register();
			$this->services[ sanitize_key( (string) $service_id ) ] = $service;

			/**
			 * Fires after a core service is registered.
			 *
			 * @param string $service_id Service identifier.
			 * @param object $service    Registered service instance.
			 * @param array  $definition Original service definition.
			 */
			do_action( 'ogs_seo_core_service_registered', $service_id, $service, $definition );
		}

		$this->registered = true;
	}

	/**
	 * @return array<string, object>
	 */
	public function services(): array {
		return $this->services;
	}

	public function get( string $service_id ): ?object {
		$service_id = sanitize_key( $service_id );
		return $this->services[ $service_id ] ?? null;
	}

	/**
	 * @param array<string, mixed> $definition
	 */
	private function should_register( array $definition ): bool {
		$contexts = isset( $definition['contexts'] ) && is_array( $definition['contexts'] )
			? array_values( array_filter( array_map( 'sanitize_key', $definition['contexts'] ) ) )
			: array( 'always' );

		if ( empty( $contexts ) || in_array( 'always', $contexts, true ) ) {
			return true;
		}
		if ( in_array( 'admin', $contexts, true ) && function_exists( 'is_admin' ) && is_admin() ) {
			return true;
		}
		if ( in_array( 'cli', $contexts, true ) && defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $definition
	 */
	private function resolve_service( array $definition ): ?object {
		if ( isset( $definition['instance'] ) && is_object( $definition['instance'] ) ) {
			return $definition['instance'];
		}

		if ( isset( $definition['factory'] ) && is_callable( $definition['factory'] ) ) {
			$service = call_user_func( $definition['factory'] );
			return is_object( $service ) ? $service : null;
		}

		$class = isset( $definition['class'] ) ? ltrim( (string) $definition['class'], '\\' ) : '';
		if ( '' === $class || ! class_exists( $class ) ) {
			return null;
		}

		return new $class();
	}
}
