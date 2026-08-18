<?php
/**
 * Plugin Name:       Cayden Form Manager
 * Plugin URI:        https://acpsmd.org/
 * Description:        First-party page-journey analytics, an accessible feedback system, and a Google-Forms-replacement form builder — one engine, WCAG 2.2 AA / Section 508 throughout. Built to run behind aggressive edge caching (WP Engine Global Edge Security).
 * Version:           1.24.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            ACPS
 * Author URI:        https://acpsmd.org/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       acps-site-toolkit
 * Domain Path:       /languages
 *
 * This is a SINGLE-SITE plugin. It intentionally contains no multisite support
 * (no blog_id columns, no switch_to_blog(), no Network Admin, no manage_network
 * capability checks). See the build spec, section 1.
 *
 * @package ACPS\SiteToolkit
 */

namespace ACPS\SiteToolkit;

// Abort if this file is called directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * ---------------------------------------------------------------------------
 * Constants
 * ---------------------------------------------------------------------------
 */
define( 'ACPS_ST_VERSION', '1.24.0' );

// The DB schema version. Bumped whenever the table structure changes so that
// upgrades apply on load without a deactivate/reactivate cycle (spec §3, §11).
define( 'ACPS_ST_SCHEMA_VERSION', '1.2.2' );

define( 'ACPS_ST_FILE', __FILE__ );
define( 'ACPS_ST_BASENAME', plugin_basename( __FILE__ ) );
define( 'ACPS_ST_PATH', plugin_dir_path( __FILE__ ) );
define( 'ACPS_ST_URL', plugin_dir_url( __FILE__ ) );
define( 'ACPS_ST_REST_NAMESPACE', 'acps-st/v1' );

// Option keys.
define( 'ACPS_ST_OPT_SETTINGS', 'acps_st_settings' );
define( 'ACPS_ST_OPT_SCHEMA', 'acps_st_schema_version' );

/*
 * ---------------------------------------------------------------------------
 * Autoloader
 * ---------------------------------------------------------------------------
 *
 * Lightweight PSR-4-style autoloader for the ACPS\SiteToolkit namespace. Class
 * "ACPS\SiteToolkit\Foo_Bar" resolves to includes/class-foo-bar.php; classes
 * under the Admin sub-namespace resolve to includes/admin/.
 */
spl_autoload_register(
	function ( $class ) {
		$prefix = 'ACPS\\SiteToolkit\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$relative = str_replace( '\\', '/', $relative );

		// Split into directory + class name so we can lowercase the file name
		// (WordPress "class-foo-bar.php") without touching the directory.
		$parts      = explode( '/', $relative );
		$class_name = array_pop( $parts );
		$subdir     = $parts ? strtolower( implode( '/', $parts ) ) . '/' : '';

		$file_name = 'class-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';
		$path      = ACPS_ST_PATH . 'includes/' . $subdir . $file_name;

		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

/*
 * ---------------------------------------------------------------------------
 * Activation / Deactivation
 * ---------------------------------------------------------------------------
 */
register_activation_hook( __FILE__, array( __NAMESPACE__ . '\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( __NAMESPACE__ . '\\Deactivator', 'deactivate' ) );

/*
 * ---------------------------------------------------------------------------
 * Bootstrap
 * ---------------------------------------------------------------------------
 */
/**
 * Return the shared plugin instance.
 *
 * @return Plugin
 */
function plugin() {
	static $instance = null;
	if ( null === $instance ) {
		$instance = new Plugin();
	}
	return $instance;
}

// Kick everything off once all plugins are loaded.
add_action( 'plugins_loaded', __NAMESPACE__ . '\\plugin' );
