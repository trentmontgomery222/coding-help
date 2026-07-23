<?php
/**
 * Main plugin bootstrap. Wires the pillars together and registers all hooks.
 *
 * @package ACPS\SiteToolkit
 */

namespace ACPS\SiteToolkit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin.
 */
class Plugin {

	/** @var Settings */
	public $settings;
	/** @var REST_Controller */
	public $rest;
	/** @var Privacy */
	public $privacy;
	/** @var Integrations */
	public $integrations;

	/**
	 * Boot.
	 */
	public function __construct() {
		// Apply any pending schema upgrade without a deactivate/reactivate
		// cycle (spec §11).
		Schema::maybe_upgrade();

		$this->settings     = new Settings();
		$this->rest         = new REST_Controller();
		$this->privacy      = new Privacy();
		$this->integrations = new Integrations();

		$this->hooks();
	}

	/**
	 * Register hooks.
	 */
	private function hooks() {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// REST — the cache-safe surface (spec §4.2, §7.5).
		add_action( 'rest_api_init', array( $this->rest, 'register_routes' ) );

		// Settings API.
		add_action( 'admin_init', array( $this->settings, 'register' ) );
		add_action( 'admin_init', array( $this, 'sync_editor_capability' ) );

		// Front-end assets + feedback modal.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend' ) );
		add_action( 'wp_footer', array( __NAMESPACE__ . '\\Feedback', 'render_modal' ) );

		// Never let the tracking/token endpoints be cached by WP Engine.
		add_filter( 'rest_pre_serve_request', array( $this, 'ensure_rest_uncached' ), 10, 4 );

		// Keep feedback categories synced when settings change.
		add_action( 'update_option_' . ACPS_ST_OPT_SETTINGS, array( __NAMESPACE__ . '\\Feedback', 'sync_categories' ) );

		// Privacy hooks + purge cron.
		$this->privacy->register();

		// Integrations: shortcode / block / Beaver module.
		$this->integrations->register();

		// Admin UI.
		if ( is_admin() ) {
			$admin = new Admin\Admin();
			$admin->register();
		}

		// Admin assets.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin' ) );

		// Grant the read-only reports cap to anyone who can manage options.
		add_filter( 'user_has_cap', array( $this, 'grant_reports_cap' ), 10, 3 );

		// Settings link on the Plugins screen.
		add_filter( 'plugin_action_links_' . ACPS_ST_BASENAME, array( $this, 'action_links' ) );
	}

	/**
	 * Load translations.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'acps-site-toolkit', false, dirname( ACPS_ST_BASENAME ) . '/languages' );
	}

	/**
	 * Enqueue front-end tracking + forms assets. The scripts are tiny and carry
	 * the REST config needed to run cache-safely.
	 */
	public function enqueue_frontend() {
		$config = array(
			'restUrl'       => esc_url_raw( rest_url( ACPS_ST_REST_NAMESPACE ) ),
			'tracking'      => (bool) Settings::get( 'tracking_enabled' ),
			'consentMode'   => (bool) Settings::get( 'consent_mode' ),
			'idleMinutes'   => (int) Settings::get( 'session_idle_minutes', 30 ),
			'recentCount'   => (int) Settings::get( 'recent_pages_count', 3 ),
			'postId'        => get_queried_object_id(),
			'strings'       => array(
				'thePageIWasOn' => __( 'The page I was just on', 'acps-site-toolkit' ),
				'submitting'    => __( 'Sending…', 'acps-site-toolkit' ),
				'genericError'  => __( 'Something went wrong. Please try again.', 'acps-site-toolkit' ),
				'errorsFound'   => __( 'There is a problem', 'acps-site-toolkit' ),
			),
		);

		// Tracking beacon — the ONLY place visits are recorded (spec §4.2).
		wp_register_script( 'acps-st-tracking', ACPS_ST_URL . 'assets/js/tracking.js', array(), ACPS_ST_VERSION, true );
		wp_localize_script( 'acps-st-tracking', 'ACPS_ST', $config );
		wp_enqueue_script( 'acps-st-tracking' );

		// Forms runtime (token fetch, validation UI, conditional logic, paging).
		wp_enqueue_script( 'acps-st-forms', ACPS_ST_URL . 'assets/js/forms.js', array( 'acps-st-tracking' ), ACPS_ST_VERSION, true );

		// Feedback modal behaviour (focus trap, page picker pre-fill).
		wp_enqueue_script( 'acps-st-feedback', ACPS_ST_URL . 'assets/js/feedback.js', array( 'acps-st-forms' ), ACPS_ST_VERSION, true );

		wp_enqueue_style( 'acps-st-frontend', ACPS_ST_URL . 'assets/css/frontend.css', array(), ACPS_ST_VERSION );
	}

	/**
	 * Enqueue admin assets on our screens only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin( $hook ) {
		if ( false === strpos( $hook, 'acps-st' ) ) {
			return;
		}
		wp_enqueue_style( 'acps-st-admin', ACPS_ST_URL . 'assets/css/admin.css', array(), ACPS_ST_VERSION );
		wp_enqueue_script( 'acps-st-admin', ACPS_ST_URL . 'assets/js/admin-builder.js', array( 'wp-i18n' ), ACPS_ST_VERSION, true );
		wp_localize_script(
			'acps-st-admin',
			'ACPS_ST_ADMIN',
			array(
				'fieldTypes' => Field_Types::all(),
				'nonce'      => wp_create_nonce( 'acps_st_admin' ),
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			)
		);
	}

	/**
	 * Belt-and-braces no-cache headers on our REST namespace.
	 *
	 * @param bool              $served  Whether already served.
	 * @param mixed             $result  Result.
	 * @param \WP_REST_Request  $request Request.
	 * @param \WP_REST_Server   $server  Server.
	 * @return bool
	 */
	public function ensure_rest_uncached( $served, $result, $request, $server ) {
		if ( 0 === strpos( ltrim( $request->get_route(), '/' ), ACPS_ST_REST_NAMESPACE ) ) {
			if ( ! headers_sent() ) {
				header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
			}
		}
		return $served;
	}

	/**
	 * Grant the read-only reports capability to users who can manage options.
	 *
	 * @param array $allcaps All caps.
	 * @param array $caps    Requested caps.
	 * @param array $args    Args.
	 * @return array
	 */
	public function grant_reports_cap( $allcaps, $caps, $args ) {
		if ( ! empty( $allcaps['manage_options'] ) ) {
			$allcaps[ Settings::CAP_READ ] = true;
		}
		return $allcaps;
	}

	/**
	 * Add/remove the read-only reports cap on the Editor role based on the
	 * setting (spec §9.1).
	 */
	public function sync_editor_capability() {
		$role = get_role( 'editor' );
		if ( ! $role ) {
			return;
		}
		$want = (bool) Settings::get( 'editors_view_reports' );
		$has  = $role->has_cap( Settings::CAP_READ );
		if ( $want && ! $has ) {
			$role->add_cap( Settings::CAP_READ );
		} elseif ( ! $want && $has ) {
			$role->remove_cap( Settings::CAP_READ );
		}
	}

	/**
	 * Add a Settings link on the Plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ) {
		$url  = admin_url( 'admin.php?page=acps-st-settings' );
		$link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'acps-site-toolkit' ) . '</a>';
		array_unshift( $links, $link );
		return $links;
	}
}
