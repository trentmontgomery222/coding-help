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
		add_action( 'init', array( $this, 'register_module' ), 20 );
	}

	/**
	 * Loads the module when Beaver Builder is available.
	 *
	 * @return void
	 */
	public function register_module() {
		if ( ! class_exists( 'FLBuilderModule' ) ) {
			return;
		}

		require_once ACPS_ALERTS_DIR . 'modules/alert-trigger/alert-trigger.php';
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
