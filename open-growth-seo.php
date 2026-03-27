<?php
/**
 * Plugin Name: Open Growth SEO
 * Plugin URI: http://opengrowthsolutions.com
 * Description: Professional SEO + AEO + GEO plugin for technical SEO, schema, bots control, audits, and search appearance.
 * Version: 1.1.0
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Author: Open Growth Solutions
 * Author URI: http://opengrowthsolutions.com
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: open-growth-seo
 * Domain Path: /languages
 *
 * @package OpenGrowthSEO
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'OGS_SEO_VERSION' ) ) {
	define( 'OGS_SEO_VERSION', '1.1.0' );
}
if ( ! defined( 'OGS_SEO_FILE' ) ) {
	define( 'OGS_SEO_FILE', __FILE__ );
}
if ( ! defined( 'OGS_SEO_PATH' ) ) {
	define( 'OGS_SEO_PATH', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'OGS_SEO_URL' ) ) {
	define( 'OGS_SEO_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'OGS_SEO_BASENAME' ) ) {
	define( 'OGS_SEO_BASENAME', plugin_basename( __FILE__ ) );
}

require_once OGS_SEO_PATH . 'includes/Support/Autoloader.php';
\OpenGrowthSolutions\OpenGrowthSEO\Support\Autoloader::register();

register_activation_hook( OGS_SEO_FILE, array( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Core\\Activator', 'activate' ) );
register_deactivation_hook( OGS_SEO_FILE, array( '\\OpenGrowthSolutions\\OpenGrowthSEO\\Core\\Activator', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		load_plugin_textdomain( 'open-growth-seo', false, dirname( OGS_SEO_BASENAME ) . '/languages' );
		\OpenGrowthSolutions\OpenGrowthSEO\Core\Plugin::boot();
	}
);
