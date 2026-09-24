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
	 * Whether the hooks have already been wired.
	 *
	 * @var bool
	 */
	protected $wired = false;

	/**
	 * The alert post type.
	 *
	 * @var ACPS_Alerts_Post_Type
	 */
	public $post_type;

	/**
	 * Status board and the daily archive sweep.
	 *
	 * @var ACPS_Alerts_Status
	 */
	public $status;

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
	 * The [schoolstatus] shortcodes.
	 *
	 * @var ACPS_Alerts_Shortcodes
	 */
	public $shortcodes;

	/**
	 * Tours, guides and contextual help. Null when the optional help files are
	 * not installed.
	 *
	 * @var ACPS_Alerts_Help|null
	 */
	public $help = null;

	/**
	 * Boots the plugin.
	 *
	 * @return void
	 */
	public function init() {
		// Wire once. Everything below registers hooks, so running it twice would
		// double every menu, notice and fragment the plugin outputs.
		if ( $this->wired ) {
			return;
		}

		$this->wired = true;

		$this->post_type = new ACPS_Alerts_Post_Type();
		$this->status    = new ACPS_Alerts_Status();
		$this->admin     = new ACPS_Alerts_Admin();
		$this->frontend  = new ACPS_Alerts_Frontend();
		$this->builder  = new ACPS_Alerts_Builder();
		$this->updater  = new ACPS_Alerts_Updater();
		$this->panel    = new ACPS_Alerts_Panel( $this->updater );
		$this->shortcodes = new ACPS_Alerts_Shortcodes();

		ACPS_Alerts_Failsafe::action( 'init', array( $this, 'load_textdomain' ), 'plugin/textdomain' );

		// Each subsystem is wired independently and guarded: if one of them
		// cannot register, the others still do, and the site is untouched either
		// way. Order matters only in that the front end is the most important to
		// get up, so it goes first.
		$subsystems = array(
			// The post type goes up first: everything else reads from it.
			'post-type' => array( $this->post_type, 'init' ),
			'status'   => array( $this->status, 'init' ),
			'frontend' => array( $this->frontend, 'init' ),
			'builder'  => array( $this->builder, 'init' ),
			'popup-src' => array( 'ACPS_Alerts_Popup_Source', 'init' ),
			'shortcodes' => array( $this->shortcodes, 'init' ),
			'updater'  => array( $this->updater, 'register' ),
			'panel'    => array( $this->panel, 'register' ),
		);

		if ( is_admin() ) {
			$subsystems['admin'] = array( $this->admin, 'init' );

			// The teaching layer is loaded on demand and only in the admin. It
			// is an optional file, so it is required by hand and skipped
			// entirely if it is not there.
			$help_file = ACPS_ALERTS_DIR . 'includes/class-acps-alerts-help.php';
			$art_file  = ACPS_ALERTS_DIR . 'includes/class-acps-alerts-art.php';

			if ( is_readable( $help_file ) && is_readable( $art_file ) ) {
				require_once $art_file;
				require_once $help_file;

				$this->help          = new ACPS_Alerts_Help();
				$subsystems['help']  = array( $this->help, 'init' );
			}
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

		// A saved alert changes what the whole site shows, so rebuild every
		// cached page — posting a status behaves as if every page were edited.
		ACPS_Alerts_Failsafe::action(
			'acps_alerts_saved',
			array( 'ACPS_Alerts_Status', 'flush_page_caches' ),
			'plugin/flush-page-caches'
		);

		// Remember the first real use, for the setup checklist.
		ACPS_Alerts_Failsafe::action(
			'acps_alerts_saved',
			array( 'ACPS_Alerts_Status', 'note_first_use' ),
			'plugin/first-use',
			10,
			2
		);
		ACPS_Alerts_Failsafe::action(
			'acps_alerts_enabled_changed',
			array( 'ACPS_Alerts_Status', 'note_first_use' ),
			'plugin/first-use-toggle',
			10,
			2
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
