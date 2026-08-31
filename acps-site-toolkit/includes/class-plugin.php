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
	/** @var Updater|null Self-updater. Null if the class file failed to load. */
	public $updater;

	/**
	 * Boot.
	 */
	public function __construct() {
		// Apply any pending schema upgrade without a deactivate/reactivate
		// cycle (spec §11).
		Schema::maybe_upgrade();
		$this->maybe_upgrade_templates();

		$this->settings     = new Settings();
		$this->rest         = new REST_Controller();
		$this->privacy      = new Privacy();
		$this->integrations = new Integrations();

		// Self-hosted/GitHub plugin updates (see /Self-Update-System-Spec.md,
		// PART A). Guarded so a missing/failed class file pauses just this
		// feature instead of fataling the whole plugin (spec §A7/§A8).
		$this->updater = class_exists( __NAMESPACE__ . '\\Updater' ) ? new Updater() : null;

		$this->hooks();
	}

	/**
	 * When the plugin files are updated in place (no reactivation), make sure
	 * the built-in form templates exist. Guarded by a version option so the DB
	 * work happens once per version, not every request.
	 */
	private function maybe_upgrade_templates() {
		if ( get_option( 'acps_st_version' ) === ACPS_ST_VERSION ) {
			return;
		}
		Feedback::ensure_feedback_form();
		Help::ensure_contact_form();
		Help::ensure_media_request_form();
		Error_Log::ensure_form();

		// The floating button is now the contact form. If the label is still the
		// old default, flip it to match (leaves any custom label alone).
		$settings = get_option( ACPS_ST_OPT_SETTINGS );
		if ( is_array( $settings ) && isset( $settings['trigger_label'] ) && 'Feedback' === $settings['trigger_label'] ) {
			$settings['trigger_label'] = 'Chat with us';
			update_option( ACPS_ST_OPT_SETTINGS, $settings );
		}

		update_option( 'acps_st_version', ACPS_ST_VERSION );
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
		// Secret-link forms open as an auto popup when ?acps_key is present.
		add_action( 'wp_footer', array( __NAMESPACE__ . '\\Access', 'render_token_popup' ) );

		// Never let the tracking/token endpoints be cached by WP Engine.
		add_filter( 'rest_pre_serve_request', array( $this, 'ensure_rest_uncached' ), 10, 4 );

		// Keep feedback categories synced when settings change.
		add_action( 'update_option_' . ACPS_ST_OPT_SETTINGS, array( __NAMESPACE__ . '\\Feedback', 'sync_categories' ) );

		// Self-updater: hooks itself (checks `update_enabled`; spec §A1), plus
		// flush its cache immediately when settings change so a changed
		// source takes effect right away rather than after the old cached
		// lookup expires (spec §A8). Both gated on the class having loaded.
		if ( $this->updater ) {
			$this->updater->register();
			add_action( 'update_option_' . ACPS_ST_OPT_SETTINGS, array( __NAMESPACE__ . '\\Updater', 'flush_cache' ) );
		}

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

		// Surface a silent save failure so "email arrived but nothing in Entries"
		// can never go unnoticed again.
		add_action( 'admin_notices', array( $this, 'maybe_show_save_error_notice' ) );

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
		$analytics_on = (bool) Settings::get( 'analytics_enabled' );
		$beacon_on    = $analytics_on && ( (bool) Settings::get( 'track_pageviews' ) || (bool) Settings::get( 'track_visitors' ) );

		$config = array(
			'restUrl'       => esc_url_raw( rest_url( ACPS_ST_REST_NAMESPACE ) ),
			// Master switch — when off, tracking.js does nothing at all.
			'analytics'     => $analytics_on,
			// Whether the one-per-pageview beacon should fire.
			'beacon'        => $beacon_on,
			'tracking'      => $beacon_on,
			// Only this % of pageviews actually send a beacon — the single
			// biggest lever for origin load on a cached site.
			'sampleRate'    => max( 1, min( 100, (int) Settings::get( 'analytics_sample_rate', 100 ) ) ),
			// Don't record analytics for logged-in site admins browsing their own
			// site — keeps their views out of the numbers.
			'suppress'      => is_user_logged_in() && current_user_can( 'manage_options' ),
			'restNonce'     => wp_create_nonce( 'wp_rest' ),
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

		$feedback_on   = (bool) Settings::get( 'feedback_enabled' );
		$restricted_on = (bool) Settings::get( 'restricted_forms_enabled' );
		$qa_on         = (bool) Settings::get( 'qa_enabled' );

		// Forms runtime (token fetch, validation UI, conditional logic, paging).
		// This is the shared base for every embedded form, so it always loads —
		// a form can appear on any page via shortcode/block/Beaver module, which
		// we can't detect ahead of a cached render.
		wp_enqueue_script( 'acps-st-forms', ACPS_ST_URL . 'assets/js/forms.js', array( 'acps-st-tracking' ), ACPS_ST_VERSION, true );

		// Feedback modal behaviour (focus trap, page picker pre-fill) — only when
		// the feedback/contact widget is enabled.
		if ( $feedback_on ) {
			wp_enqueue_script( 'acps-st-feedback', ACPS_ST_URL . 'assets/js/feedback.js', array( 'acps-st-forms' ), ACPS_ST_VERSION, true );
		}

		// Password gate / secret-link popup for restricted forms — only when on.
		if ( $restricted_on ) {
			wp_enqueue_script( 'acps-st-access', ACPS_ST_URL . 'assets/js/access.js', array( 'acps-st-forms' ), ACPS_ST_VERSION, true );
		}

		// Q&A / help widget — only when on.
		if ( $qa_on ) {
			wp_enqueue_script( 'acps-st-qa', ACPS_ST_URL . 'assets/js/qa.js', array(), ACPS_ST_VERSION, true );
		}

		// Auto-log 404s: fire a diagnostic beacon on the 404 page. Independent of
		// analytics. Config is static (safe to cache); per-visitor data is
		// gathered client-side or on the uncached REST request.
		if ( Settings::get( 'autolog_404' ) && is_404() ) {
			wp_enqueue_script( 'acps-st-autolog', ACPS_ST_URL . 'assets/js/autolog.js', array(), ACPS_ST_VERSION, true );
			wp_localize_script(
				'acps-st-autolog',
				'ACPS_ST_AUTOLOG',
				array(
					'url'    => esc_url_raw( rest_url( ACPS_ST_REST_NAMESPACE . '/auto-log' ) ),
					'postId' => 0,
				)
			);
		}

		wp_enqueue_style( 'acps-st-frontend', ACPS_ST_URL . 'assets/css/frontend.css', array(), ACPS_ST_VERSION );

		// Custom CSS loads AFTER the base stylesheet so it can override anything
		// without letting admins delete the accessibility/spam-critical rules.
		$custom_css = trim( (string) Settings::get( 'custom_css', '' ) );
		if ( '' !== $custom_css ) {
			wp_add_inline_style( 'acps-st-frontend', $custom_css );
		}

		// Per-device sizing (popup width + button diameter) loads LAST as CSS
		// variables, so these numeric settings stay authoritative. The popup
		// still shrinks below its max on small screens via the min() in the base
		// rule; the button size steps down at tablet and phone breakpoints.
		$modal  = (int) Settings::get( 'modal_max_width', 1200 );
		$td     = (int) Settings::get( 'trigger_size', 64 );
		$tt     = (int) Settings::get( 'trigger_size_tablet', 60 );
		$tm     = (int) Settings::get( 'trigger_size_mobile', 52 );
		$device = ":root{--acps-modal-width:{$modal}px;--acps-trigger-size:{$td}px;}"
			. "@media (max-width:1024px){:root{--acps-trigger-size:{$tt}px;}}"
			. "@media (max-width:600px){:root{--acps-trigger-size:{$tm}px;}}";
		wp_add_inline_style( 'acps-st-frontend', $device );
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
	 * Show an admin notice when the most recent form submission failed to save,
	 * with the exact database reason and a one-click path to repair the schema.
	 * The notice clears itself automatically the next time a submission saves.
	 */
	public function maybe_show_save_error_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$err = Entries::last_save_error();
		if ( ! $err ) {
			return;
		}
		$repair_url = wp_nonce_url(
			add_query_arg(
				array( 'action' => 'acps_st_db_action', 'do' => 'repair' ),
				admin_url( 'admin-post.php' )
			),
			'acps_st_db_action'
		);
		$when = isset( $err['when'] ) ? $err['when'] : '';
		$msg  = isset( $err['message'] ) ? $err['message'] : '';
		echo '<div class="notice notice-error"><p><strong>'
			. esc_html__( 'Cayden Form Manager: a form submission could not be saved.', 'acps-site-toolkit' )
			. '</strong><br>'
			. esc_html( sprintf(
				/* translators: 1: date/time, 2: database error */
				__( 'Last problem at %1$s — %2$s', 'acps-site-toolkit' ),
				$when,
				$msg
			) )
			. '</p><p>'
			. esc_html__( 'Click Repair database to rebuild any missing tables or columns, then submit the form again to confirm. This notice disappears automatically once a submission saves cleanly.', 'acps-site-toolkit' )
			. '</p><p><a href="' . esc_url( $repair_url ) . '" class="button button-primary">'
			. esc_html__( 'Repair database', 'acps-site-toolkit' )
			. '</a></p></div>';
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
		$url  = Admin\Admin::settings_url();
		$link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'acps-site-toolkit' ) . '</a>';
		array_unshift( $links, $link );
		return $links;
	}
}
