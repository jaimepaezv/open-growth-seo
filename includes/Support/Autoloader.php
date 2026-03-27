<?php
namespace OpenGrowthSolutions\OpenGrowthSEO\Support;

defined( 'ABSPATH' ) || exit;

class Autoloader {
	private const PREFIX = 'OpenGrowthSolutions\\OpenGrowthSEO\\';

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}

		spl_autoload_register( array( __CLASS__, 'autoload' ) );
		self::$registered = true;
	}

	public static function autoload( string $class ): void {
		if ( ! defined( 'OGS_SEO_PATH' ) || 0 !== strpos( $class, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class, strlen( self::PREFIX ) );
		$relative = str_replace( '\\', '/', $relative );
		$file     = OGS_SEO_PATH . 'includes/' . $relative . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
