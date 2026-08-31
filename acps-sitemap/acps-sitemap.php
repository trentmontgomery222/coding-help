<?php
/**
 * Plugin Name:       ACPS Sitemap
 * Plugin URI:        https://github.com/trentmontgomery222/coding-help
 * Description:       Single-site XML and HTML sitemap generator, fully managed from the WordPress admin. No multisite or network install required.
 * Version:           1.1.0
 * Requires at least: 5.0
 * Requires PHP:      7.0
 * Author:            ACPS
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       acps-sitemap
 * Network:           false
 *
 * @package ACPS_Sitemap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'ACPS_SITEMAP_VERSION', '1.1.0' );
define( 'ACPS_SITEMAP_FILE', __FILE__ );
define( 'ACPS_SITEMAP_DIR', plugin_dir_path( __FILE__ ) );
define( 'ACPS_SITEMAP_URL', plugin_dir_url( __FILE__ ) );

require_once ACPS_SITEMAP_DIR . 'includes/class-acps-sitemap.php';

/**
 * Boot the plugin.
 *
 * @return ACPS_Sitemap
 */
function acps_sitemap() {
	return ACPS_Sitemap::instance();
}
acps_sitemap();

/*
 * Activation / deactivation.
 *
 * These are declared here (in the main file) so WordPress can register them
 * before the plugin's classes are loaded on the activation request.
 */
register_activation_hook( __FILE__, array( 'ACPS_Sitemap', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ACPS_Sitemap', 'deactivate' ) );
