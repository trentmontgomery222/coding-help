<?php
/**
 * Plugin container.
 *
 * @package ACPS_Alert_Popups
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin's pieces together.
 */
class ACPS_Alerts_Plugin {

	/**
	 * Admin handler.
	 *
	 * @var ACPS_Alerts_Admin
	 */
	public $admin;

	/**
	 * Front end handler.
	 *
	 * @var ACPS_Alerts_Frontend
	 */
	public $frontend;

	/**
	 * Beaver Builder integration.
	 *
	 * @var ACPS_Alerts_Builder
	 */
	public $builder;

	/**
	 * Boots the plugin.
	 *
	 * @return void
	 */
	public function init() {
		$this->admin    = new ACPS_Alerts_Admin();
		$this->frontend = new ACPS_Alerts_Frontend();
		$this->builder  = new ACPS_Alerts_Builder();

		add_action( 'init', array( $this, 'load_textdomain' ) );

		if ( is_admin() ) {
			$this->admin->init();
		}

		$this->frontend->init();
		$this->builder->init();
	}

	/**
	 * Loads translations.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'acps-alert-popups', false, dirname( plugin_basename( ACPS_ALERTS_FILE ) ) . '/languages' );
	}

	/**
	 * Activation: store defaults so the settings screen has something to show.
	 *
	 * @return void
	 */
	public static function activate() {
		if ( false === get_option( ACPS_Alerts_Settings::OPTION, false ) ) {
			add_option( ACPS_Alerts_Settings::OPTION, ACPS_Alerts_Settings::defaults() );
		}
	}

	/**
	 * Deactivation: nothing to tear down, settings are kept.
	 *
	 * @return void
	 */
	public static function deactivate() {
		// Alert settings live on popup posts and are intentionally preserved.
	}
}
