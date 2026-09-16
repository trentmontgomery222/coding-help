<?php
/**
 * Plugin Name:       ACPS Alert Popups
 * Plugin URI:        https://github.com/trentmontgomery222/coding-help
 * Description:       Turns Beaver Builder Popups into a managed site alert system. Design the alert in Beaver Builder, then enable, schedule, target and throttle it from the WordPress admin.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            ACPS
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       acps-alert-popups
 *
 * @package ACPS_Alert_Popups
 */

defined( 'ABSPATH' ) || exit;

define( 'ACPS_ALERTS_VERSION', '1.0.0' );
define( 'ACPS_ALERTS_FILE', __FILE__ );
define( 'ACPS_ALERTS_DIR', plugin_dir_path( __FILE__ ) );
define( 'ACPS_ALERTS_URL', plugin_dir_url( __FILE__ ) );

require_once ACPS_ALERTS_DIR . 'includes/class-acps-alerts-settings.php';
require_once ACPS_ALERTS_DIR . 'includes/class-acps-alerts-source.php';
require_once ACPS_ALERTS_DIR . 'includes/class-acps-alerts-alert.php';
require_once ACPS_ALERTS_DIR . 'includes/class-acps-alerts-conditions.php';
require_once ACPS_ALERTS_DIR . 'includes/class-acps-alerts-fields.php';
require_once ACPS_ALERTS_DIR . 'includes/class-acps-alerts-admin.php';
require_once ACPS_ALERTS_DIR . 'includes/class-acps-alerts-frontend.php';
require_once ACPS_ALERTS_DIR . 'includes/class-acps-alerts-builder.php';
require_once ACPS_ALERTS_DIR . 'includes/class-acps-alerts-plugin.php';

/**
 * Main plugin instance.
 *
 * @return ACPS_Alerts_Plugin
 */
function acps_alerts() {
	static $instance = null;

	if ( null === $instance ) {
		$instance = new ACPS_Alerts_Plugin();
	}

	return $instance;
}

acps_alerts()->init();

register_activation_hook( __FILE__, array( 'ACPS_Alerts_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ACPS_Alerts_Plugin', 'deactivate' ) );
