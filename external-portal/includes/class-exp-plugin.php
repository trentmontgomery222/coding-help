<?php
/**
 * Main plugin orchestrator.
 *
 * @package ExternalPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the plugin's pieces together and owns the WordPress hooks.
 */
class EXP_Plugin {

	/**
	 * @var EXP_Plugin|null
	 */
	protected static $instance = null;

	/**
	 * @var bool
	 */
	protected $booted = false;

	/**
	 * Singleton accessor.
	 *
	 * @return EXP_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * The extension registry.
	 *
	 * @return EXP_Registry
	 */
	public function registry() {
		return EXP_Registry::instance();
	}

	/**
	 * Boot the plugin (called once on plugins_loaded).
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		// Public API helper functions for third-party registration.
		require_once EXP_PLUGIN_DIR . 'includes/api.php';

		load_plugin_textdomain( 'external-portal', false, dirname( EXP_PLUGIN_BASENAME ) . '/languages' );

		// Register core modules onto the extension action, then load the registry.
		add_action( 'exp_register_extensions', array( 'EXP_Module_Page_Edit', 'register' ) );
		add_action( 'exp_register_extensions', array( 'EXP_Module_Category_Post', 'register' ) );
		add_action( 'exp_register_extensions', array( 'EXP_Module_General_Submission', 'register' ) );
		add_action( 'exp_register_extensions', array( 'EXP_Module_Google_Calendar', 'register' ) );
		add_action( 'exp_register_extensions', array( 'EXP_Module_My_Activity', 'register' ) );
		add_action( 'exp_register_extensions', array( 'EXP_Module_Account', 'register' ) );

		// Load registration once WordPress is ready (plugins have hooked in).
		add_action( 'init', array( $this->registry(), 'load' ), 20 );

		// Front-end: shortcodes, request routing, assets, cache-busting.
		$shortcodes = new EXP_Shortcodes();
		$shortcodes->register();

		$router = new EXP_Router();
		$router->register();

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
		add_action( 'template_redirect', array( $this, 'maybe_prevent_cache' ), 1 );

		// Admin.
		if ( is_admin() ) {
			$admin = new EXP_Admin();
			$admin->register();
		}

		// Housekeeping cron.
		add_action( 'exp_cron_cleanup', array( $this, 'run_cleanup' ) );
	}

	/**
	 * Bust page caching on any page that hosts a portal shortcode (spec Section 1).
	 */
	public function maybe_prevent_cache() {
		if ( EXP_Cache::current_page_has_portal() ) {
			EXP_Cache::prevent_page_cache();
		}
	}

	/**
	 * Enqueue front-end CSS/JS only on portal pages.
	 */
	public function enqueue_frontend_assets() {
		if ( ! EXP_Cache::current_page_has_portal() ) {
			return;
		}
		wp_enqueue_style( 'dashicons' );
		wp_enqueue_style( 'external-portal', EXP_PLUGIN_URL . 'assets/css/portal.css', array( 'dashicons' ), EXP_VERSION );
		wp_enqueue_script( 'external-portal', EXP_PLUGIN_URL . 'assets/js/portal.js', array(), EXP_VERSION, true );

		// Data for the session-expiry warning (accessibility: ARIA live region).
		$user = EXP_Session::current_user();
		wp_localize_script(
			'external-portal',
			'EXPortal',
			array(
				'warnSeconds'  => (int) EXP_Settings::get( 'session_warn_seconds', 120 ),
				'idleMinutes'  => (int) EXP_Settings::get( 'session_idle_minutes', 30 ),
				'idleExpires'  => $user && isset( $user->_idle_expires ) ? strtotime( $user->_idle_expires . ' UTC' ) : 0,
				'now'          => time(),
				'loginUrl'     => $this->login_url(),
				'i18n'         => array(
					'expiringSoon' => __( 'Your session will expire soon. Select anywhere or press a key to stay signed in.', 'external-portal' ),
					'expired'      => __( 'Your session has expired. Please sign in again.', 'external-portal' ),
				),
			)
		);
	}

	/**
	 * Cron: purge expired sessions/codes.
	 */
	public function run_cleanup() {
		EXP_Session::purge_expired();
		EXP_OTP::purge_expired();
	}

	/**
	 * URL of the configured login page (falls back to home).
	 *
	 * @return string
	 */
	public function login_url() {
		$id = (int) EXP_Settings::get( 'login_page_id', 0 );
		return $id ? get_permalink( $id ) : home_url( '/' );
	}

	/**
	 * URL of the configured dashboard page (falls back to home).
	 *
	 * @return string
	 */
	public function dashboard_url() {
		$id = (int) EXP_Settings::get( 'dashboard_page_id', 0 );
		return $id ? get_permalink( $id ) : home_url( '/' );
	}
}
