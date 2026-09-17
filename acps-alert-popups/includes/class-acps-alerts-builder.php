<?php
/**
 * Beaver Builder integration: the Alert Trigger module.
 *
 * Lets an editor drop a button into any Beaver Builder row, page or popup that
 * opens one of the managed alerts.
 *
 * @package ACPS_Alert_Popups
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin's Beaver Builder module.
 */
class ACPS_Alerts_Builder {

	/**
	 * Hooks the module registration up.
	 *
	 * @return void
	 */
	public function init() {
		ACPS_Alerts_Failsafe::action( 'init', array( $this, 'register_module' ), 'builder/register', 20 );
	}

	/**
	 * Loads the module when Beaver Builder is available.
	 *
	 * Registering a module depends on another plugin's class staying the shape
	 * we expect across versions, so every precondition is checked and the whole
	 * thing is skipped rather than risked if anything is missing.
	 *
	 * @return void
	 */
	public function register_module() {
		if ( ! class_exists( 'FLBuilderModule' ) || ! class_exists( 'FLBuilder' ) ) {
			return;
		}

		if ( ! method_exists( 'FLBuilder', 'register_module' ) ) {
			return;
		}

		$file = ACPS_ALERTS_DIR . 'modules/alert-trigger/alert-trigger.php';

		// Optional file: a missing module costs the trigger button, not the site.
		if ( ! is_readable( $file ) ) {
			return;
		}

		if ( class_exists( 'ACPS_Alert_Trigger_Module' ) ) {
			return; // Already loaded.
		}

		require_once $file;
	}

	/**
	 * Alert choices for module and field dropdowns.
	 *
	 * @return array Popup ID => title.
	 */
	public static function get_alert_choices() {
		$choices = array( '' => __( 'Choose an alert&hellip;', 'acps-alert-popups' ) );

		foreach ( ACPS_Alerts_Source::get_popups() as $post ) {
			$alert                        = new ACPS_Alerts_Alert( $post );
			$choices[ (string) $post->ID ] = $alert->get_title();
		}

		return $choices;
	}
}
