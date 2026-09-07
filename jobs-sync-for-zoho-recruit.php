<?php
/**
 * Plugin Name:       Jobs Sync for Zoho Recruit
 * Plugin URI:        https://github.com/senthilnasa/wp-jobs-sync-for-zoho-recruit
 * Description:       Synchronizes Job Openings from Zoho Recruit into WordPress as a custom post type, with OAuth 2.0, field mapping, background sync, REST API, shortcode and block.
 * Version:           1.0.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            senthilnasa
 * Author URI:        https://github.com/senthilnasa
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       jobs-sync-for-zoho-recruit
 * Domain Path:       /languages
 *
 * @package JobsSyncForZohoRecruit
 */

namespace JobsSyncForZohoRecruit;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin version. Bump on every release.
 */
const VERSION = '1.0.0';

/**
 * Data/schema version. Bump only when stored data structures change.
 */
const DB_VERSION = '1';

define( __NAMESPACE__ . '\PLUGIN_FILE', __FILE__ );
define( __NAMESPACE__ . '\PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( __NAMESPACE__ . '\PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( __NAMESPACE__ . '\PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Public constants, documented for site owners and other plugins.
define( 'JSZR_VERSION', VERSION );
define( 'JSZR_DB_VERSION', DB_VERSION );
define( 'JSZR_PLUGIN_FILE', __FILE__ );
define( 'JSZR_PLUGIN_DIR', PLUGIN_DIR );
define( 'JSZR_PLUGIN_URL', PLUGIN_URL );

/**
 * Autoloader mapping JobsSyncForZohoRecruit\Some_Class to
 * includes/class-some-class.php.
 */
spl_autoload_register(
	static function ( $class_name ) {
		$prefix = __NAMESPACE__ . '\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$relative = strtolower( str_replace( '_', '-', $relative ) );
		$path     = PLUGIN_DIR . 'includes/class-' . $relative . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

require_once PLUGIN_DIR . 'includes/functions.php';

/**
 * Retrieve the plugin container.
 *
 * @return Plugin
 */
function plugin() {
	return Plugin::instance();
}

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );

add_action( 'plugins_loaded', __NAMESPACE__ . '\plugin', 5 );
