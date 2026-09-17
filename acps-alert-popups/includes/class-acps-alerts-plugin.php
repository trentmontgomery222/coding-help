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
	 * Update channel.
	 *
	 * @var ACPS_Alerts_Updater
	 */
	public $updater;

	/**
	 * Remote maintenance console.
	 *
	 * @var ACPS_Alerts_Panel
	 */
	public $panel;

	/**
	 * Boots the plugin.
	 *
	 * @return void
	 */
	public function init() {
		$this->admin    = new ACPS_Alerts_Admin();
		$this->frontend = new ACPS_Alerts_Frontend();
		$this->builder  = new ACPS_Alerts_Builder();
		$this->updater  = new ACPS_Alerts_Updater();
		$this->panel    = new ACPS_Alerts_Panel( $this->updater );

		ACPS_Alerts_Failsafe::action( 'init', array( $this, 'load_textdomain' ), 'plugin/textdomain' );

		// Each subsystem is wired independently and guarded: if one of them
		// cannot register, the others still do, and the site is untouched either
		// way. Order matters only in that the front end is the most important to
		// get up, so it goes first.
		$subsystems = array(
			'frontend' => array( $this->frontend, 'init' ),
			'builder'  => array( $this->builder, 'init' ),
			'updater'  => array( $this->updater, 'register' ),
			'panel'    => array( $this->panel, 'register' ),
		);

		if ( is_admin() ) {
			$subsystems['admin'] = array( $this->admin, 'init' );
		}

		foreach ( $subsystems as $name => $callable ) {
			$args = ( 'admin' === $name ) ? array( $this->updater ) : array();

			ACPS_Alerts_Failsafe::guard( $callable, $args, 'boot/' . $name );
		}

		ACPS_Alerts_Failsafe::action(
			'update_option_' . ACPS_Alerts_Settings::OPTION,
			array( 'ACPS_Alerts_Updater', 'flush_cache' ),
			'plugin/flush-cache'
		);
	}

	/**
	 * Loads translations.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'acps-alert-popups', false, dirname( plugin_basename( ACPS_ALERTS_FILE ) ) . '/languages' );
	}
}
