<?php
/**
 * In-admin help: a "Getting Started" hub, an interactive spotlight tour that
 * walks across every screen, WordPress contextual Help tabs, and a first-run
 * invitation. Everything is wrapped so it can never break wp-admin.
 *
 * @package ACPS_Link_Shortener
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Help + guided tour controller.
 */
class ACPS_LS_Help {

	const HELP_SLUG = 'acps-link-shortener-help';

	/**
	 * Hook everything (guarded).
	 */
	public function register() {
		try {
			add_action( 'admin_menu', array( $this, 'add_menu' ), 11 );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
			add_action( 'current_screen', array( $this, 'add_help_tabs' ) );
			add_action( 'admin_notices', array( $this, 'first_run_notice' ) );
			add_action( 'admin_init', array( $this, 'maybe_dismiss' ) );
		} catch ( Throwable $e ) {
			acps_ls_log_error( 'help register', $e );
		}
	}

	/**
	 * Add the visible "Getting Started" submenu (first item under the menu).
	 */
	public function add_menu() {
		add_submenu_page(
			'acps-link-shortener',
			__( 'Getting Started', 'acps-link-shortener' ),
			__( '★ Getting Started', 'acps-link-shortener' ),
			acps_ls_manage_capability(),
			self::HELP_SLUG,
			array( $this, 'render_page' ),
			0
		);
	}

	/**
	 * Map the current admin page to a tour "screen" key.
	 *
	 * @return string
	 */
	private function current_screen_key() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$map  = array(
			self::HELP_SLUG                    => 'overview',
			'acps-link-shortener'              => 'links',
			'acps-link-shortener-add'          => 'add',
			'acps-link-shortener-settings'     => 'settings',
			'acps-link-shortener-checker'      => 'checker',
			'acps-link-shortener-api'          => 'api',
			'acps-link-shortener-updates'      => 'updates',
		);
		return isset( $map[ $page ] ) ? $map[ $page ] : '';
	}

	/**
	 * Is the current admin screen one of ours?
	 *
	 * @return bool
	 */
	private function on_our_screen() {
		return '' !== $this->current_screen_key();
	}

	/**
	 * Enqueue the tour assets + data on our screens.
	 *
	 * @param string $hook Current admin page hook (unused; we detect by page).
	 */
	public function enqueue( $hook ) {
		if ( ! $this->on_our_screen() ) {
			return;
		}

		wp_enqueue_style( 'acps-ls-tour', ACPS_LS_URL . 'admin/css/tour.css', array(), ACPS_LS_VERSION );
		wp_enqueue_script( 'acps-ls-tour', ACPS_LS_URL . 'admin/js/tour.js', array(), ACPS_LS_VERSION, true );

		wp_localize_script(
			'acps-ls-tour',
			'acpsLsTour',
			array(
				'screen' => $this->current_screen_key(),
				'i18n'   => array(
					'next'    => __( 'Next →', 'acps-link-shortener' ),
					'back'    => __( '← Back', 'acps-link-shortener' ),
					'end'     => __( 'End tour', 'acps-link-shortener' ),
					'done'    => __( 'Finish ✓', 'acps-link-shortener' ),
					'stepfmt' => __( 'Step %1$d of %2$d', 'acps-link-shortener' ),
					'gonext'  => __( 'Take me there →', 'acps-link-shortener' ),
					'welcome' => __( 'Welcome! This quick tour points at each button and explains it. Use Next / Back, or End tour any time.', 'acps-link-shortener' ),
					'finish'  => __( 'That\'s the whole plugin! You can replay any tour from the ★ Getting Started page.', 'acps-link-shortener' ),
				),
				'stops'  => $this->tour_stops(),
			)
		);
	}

	/**
	 * The tour definition: ordered stops (one per screen) with steps. Each step
	 * targets a real element by CSS selector; if a target is missing the step is
	 * shown as a centered card so the guidance never disappears.
	 *
	 * @return array
	 */
	private function tour_stops() {
		$links_url    = admin_url( 'admin.php?page=acps-link-shortener' );
		$add_url      = admin_url( 'admin.php?page=acps-link-shortener-add' );
		$settings_url = admin_url( 'options-general.php?page=acps-link-shortener-settings' );
		$checker_url  = admin_url( 'admin.php?page=acps-link-shortener-checker' );
		$api_url      = admin_url( 'admin.php?page=acps-link-shortener-api' );
		$updates_url  = admin_url( 'admin.php?page=acps-link-shortener-updates' );
		$help_url     = admin_url( 'admin.php?page=' . self::HELP_SLUG );

		return array(
			array(
				'key'   => 'overview',
				'url'   => $help_url,
				'label' => __( 'Welcome', 'acps-link-shortener' ),
				'steps' => array(
					array( 'target' => '#acps-ls-help-hero', 'title' => __( 'Welcome to Link Shortener', 'acps-link-shortener' ), 'html' => __( 'This is your home base. Below are step-by-step guides. This tour will now walk you through the actual screens — just keep clicking <strong>Next</strong>.', 'acps-link-shortener' ) ),
					array( 'target' => '#toplevel_page_acps-link-shortener', 'title' => __( 'Your menu', 'acps-link-shortener' ), 'html' => __( 'Everything lives under this <strong>Link Shortener</strong> menu on the left. Let\'s look at each screen.', 'acps-link-shortener' ) ),
				),
			),
			array(
				'key'   => 'links',
				'url'   => $links_url,
				'label' => __( 'All Links', 'acps-link-shortener' ),
				'steps' => array(
					array( 'target' => '.wp-heading-inline', 'title' => __( 'All Links', 'acps-link-shortener' ), 'html' => __( 'This table lists every short link, how many clicks each got, and whether it is on or off.', 'acps-link-shortener' ) ),
					array( 'target' => '.page-title-action', 'title' => __( 'Add a new link', 'acps-link-shortener' ), 'html' => __( 'Click this button to make a new short link. The tour will open that form next.', 'acps-link-shortener' ) ),
				),
			),
			array(
				'key'   => 'add',
				'url'   => $add_url,
				'label' => __( 'Add New Link', 'acps-link-shortener' ),
				'steps' => array(
					array( 'target' => '#acps-ls-destination', 'title' => __( '1. Where should it go?', 'acps-link-shortener' ), 'html' => __( 'Paste the long URL you want to shorten here. This is the only required field.', 'acps-link-shortener' ) ),
					array( 'target' => '#acps-ls-slug', 'title' => __( '2. The short part', 'acps-link-shortener' ), 'html' => __( 'Choose the ending, e.g. <code>open-house</code> gives <code>yoursite.org/open-house</code>. Leave typing simple: letters, numbers and dashes.', 'acps-link-shortener' ) ),
					array( 'target' => '#acps-ls-title', 'title' => __( '3. A label (optional)', 'acps-link-shortener' ), 'html' => __( 'A friendly name just for you, so the link is easy to find later in the list.', 'acps-link-shortener' ) ),
					array( 'target' => '#acps-ls-active', 'title' => __( '4. Turn it on', 'acps-link-shortener' ), 'html' => __( 'Keep this checked so the link works immediately. Uncheck to save it but keep it off.', 'acps-link-shortener' ) ),
					array( 'target' => '#acps_ls_save', 'title' => __( '5. Create it', 'acps-link-shortener' ), 'html' => __( 'Click <strong>Create Link</strong> and you are done. Your short link is live right away.', 'acps-link-shortener' ) ),
				),
			),
			array(
				'key'   => 'settings',
				'url'   => $settings_url,
				'label' => __( 'Settings', 'acps-link-shortener' ),
				'steps' => array(
					array( 'target' => '#acps-ls-link-domain', 'title' => __( 'Custom domain (optional)', 'acps-link-shortener' ), 'html' => __( 'Leave blank to use your normal site address. Only fill this in if you have a separate short domain that points here.', 'acps-link-shortener' ) ),
					array( 'target' => '#acps-ls-person-label-0', 'title' => __( 'Staff accounts', 'acps-link-shortener' ), 'html' => __( 'Add people here so they can create links from the front-end form with their own name + password. Leave empty if you don\'t need that.', 'acps-link-shortener' ) ),
					array( 'target' => '#acps-ls-shortcode-page', 'title' => __( 'The staff page', 'acps-link-shortener' ), 'html' => __( 'If you use staff accounts, put the page address that holds the <code>[acps_link_shortener]</code> shortcode here.', 'acps-link-shortener' ) ),
				),
			),
			array(
				'key'   => 'checker',
				'url'   => $checker_url,
				'label' => __( 'Link Manager', 'acps-link-shortener' ),
				'steps' => array(
					array( 'target' => '.acps-ls-status-panel', 'title' => __( 'Health at a glance', 'acps-link-shortener' ), 'html' => __( 'This panel shows how many links are broken and how many are waiting to be checked.', 'acps-link-shortener' ) ),
					array( 'target' => '.subsubsub', 'title' => __( 'Filter tabs', 'acps-link-shortener' ), 'html' => __( 'Click <strong>Broken</strong> to see only links that need attention. Each broken row shows how long it has been broken.', 'acps-link-shortener' ) ),
				),
			),
			array(
				'key'   => 'api',
				'url'   => $api_url,
				'label' => __( 'API (advanced)', 'acps-link-shortener' ),
				'steps' => array(
					array( 'target' => '#acps-ls-key-label', 'title' => __( 'Make an API key', 'acps-link-shortener' ), 'html' => __( 'Advanced: to create links from another program, generate a key here and send it as the <code>X-Api-Key</code> header. Skip this if you don\'t need it.', 'acps-link-shortener' ) ),
				),
			),
			array(
				'key'   => 'updates',
				'url'   => $updates_url,
				'label' => __( 'Updates (advanced)', 'acps-link-shortener' ),
				'steps' => array(
					array( 'target' => '#acps-ls-update-manifest', 'title' => __( 'Automatic updates', 'acps-link-shortener' ), 'html' => __( 'Advanced: point this at a file you host and the plugin can update itself. Most people can leave this alone.', 'acps-link-shortener' ) ),
				),
			),
		);
	}

	/* --------------------------------------------------------------------- */
	/* Contextual Help tabs (the "Help" button top-right of each screen)      */
	/* --------------------------------------------------------------------- */

	/**
	 * Add a Help tab to each of our screens.
	 *
	 * @param WP_Screen $screen Current screen.
	 */
	public function add_help_tabs( $screen ) {
		try {
			if ( ! is_object( $screen ) || ! $this->on_our_screen() ) {
				return;
			}
			$key = $this->current_screen_key();
			$map = array(
				'overview' => __( 'This is the Getting Started hub. Use the guides and the "Start the guided tour" button. Everything else lives under the Link Shortener menu.', 'acps-link-shortener' ),
				'links'    => __( 'This is the list of all your short links. Use "Add New Link" to make one, the row actions to edit/turn off/delete, and the search box to find one.', 'acps-link-shortener' ),
				'add'      => __( 'Paste the long URL into Destination, pick a short ending in Slug, keep Active checked, and click Create Link. A link\'s slug and destination are locked after creation.', 'acps-link-shortener' ),
				'settings' => __( 'Optional: a custom short domain, staff accounts for the front-end form, Google Sheet sync, and the link checker schedule.', 'acps-link-shortener' ),
				'checker'  => __( 'The Link Manager checks that your links still work. Use the tabs to see Broken ones; each broken row shows how long it has been broken.', 'acps-link-shortener' ),
				'api'      => __( 'Advanced: a REST API so other programs can create/manage links with an API key. Manage keys and limits here.', 'acps-link-shortener' ),
				'updates'  => __( 'Advanced: let the plugin update itself from a file you host or from GitHub, plus a secret force-update URL.', 'acps-link-shortener' ),
			);
			if ( empty( $map[ $key ] ) ) {
				return;
			}
			$screen->add_help_tab(
				array(
					'id'      => 'acps_ls_help_' . $key,
					'title'   => __( 'Link Shortener', 'acps-link-shortener' ),
					'content' => '<p>' . esc_html( $map[ $key ] ) . '</p><p><a href="' . esc_url( admin_url( 'admin.php?page=' . self::HELP_SLUG ) ) . '">' . esc_html__( 'Open the full Getting Started guide →', 'acps-link-shortener' ) . '</a></p>',
				)
			);
		} catch ( Throwable $e ) {
			acps_ls_log_error( 'help tabs', $e );
		}
	}

	/* --------------------------------------------------------------------- */
	/* First-run invitation                                                   */
	/* --------------------------------------------------------------------- */

	/**
	 * Show a friendly one-time banner inviting the guided tour.
	 */
	public function first_run_notice() {
		if ( ! $this->on_our_screen() || ! current_user_can( acps_ls_manage_capability() ) ) {
			return;
		}
		if ( get_user_meta( get_current_user_id(), 'acps_ls_help_dismissed', true ) ) {
			return;
		}
		// Don't show it on the Getting Started page itself (the button is right there).
		if ( 'overview' === $this->current_screen_key() ) {
			return;
		}
		$help_url    = admin_url( 'admin.php?page=' . self::HELP_SLUG );
		$dismiss_url = wp_nonce_url( add_query_arg( 'acps_ls_help_dismiss', '1' ), 'acps_ls_help_dismiss' );
		echo '<div class="notice notice-info"><p><strong>'
			. esc_html__( 'New to Link Shortener?', 'acps-link-shortener' ) . '</strong> '
			. esc_html__( 'Take the 2-minute guided tour — it points at every button and explains what to do.', 'acps-link-shortener' )
			. '</p><p><a href="' . esc_url( $help_url ) . '" class="button button-primary">'
			. esc_html__( 'Open Getting Started', 'acps-link-shortener' ) . '</a> '
			. '<a href="' . esc_url( add_query_arg( 'acps_ls_start_tour', 'full', $help_url ) ) . '" class="button">'
			. esc_html__( 'Start the guided tour', 'acps-link-shortener' ) . '</a> '
			. '<a href="' . esc_url( $dismiss_url ) . '" style="margin-left:.5rem;">'
			. esc_html__( 'Dismiss', 'acps-link-shortener' ) . '</a>'
			. '</p></div>';
	}

	/**
	 * Handle the dismiss link.
	 */
	public function maybe_dismiss() {
		if ( ! isset( $_GET['acps_ls_help_dismiss'] ) ) {
			return;
		}
		if ( ! current_user_can( acps_ls_manage_capability() ) ) {
			return;
		}
		check_admin_referer( 'acps_ls_help_dismiss' );
		update_user_meta( get_current_user_id(), 'acps_ls_help_dismissed', 1 );
		wp_safe_redirect( remove_query_arg( array( 'acps_ls_help_dismiss', '_wpnonce' ) ) );
		exit;
	}

	/* --------------------------------------------------------------------- */
	/* Getting Started page                                                   */
	/* --------------------------------------------------------------------- */

	/**
	 * Render the Getting Started hub.
	 */
	public function render_page() {
		if ( ! current_user_can( acps_ls_manage_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'acps-link-shortener' ) );
		}

		$add_url      = admin_url( 'admin.php?page=acps-link-shortener-add' );
		$settings_url = admin_url( 'options-general.php?page=acps-link-shortener-settings' );
		$checker_url  = admin_url( 'admin.php?page=acps-link-shortener-checker' );
		$api_url      = admin_url( 'admin.php?page=acps-link-shortener-api' );
		require ACPS_LS_PATH . 'includes/views/help-page.php';
	}
}
