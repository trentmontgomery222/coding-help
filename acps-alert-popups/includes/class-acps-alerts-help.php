<?php
/**
 * The teaching layer: guided tours, a getting-started checklist, illustrated
 * guides, contextual help and first-run coaching.
 *
 * This whole file is optional. If it goes missing the plugin keeps working —
 * it simply stops explaining itself — so nothing here is on the required-files
 * list and every hook it registers is guarded.
 *
 * @package ACPS_Alert_Popups
 */

defined( 'ABSPATH' ) || exit;

/**
 * Help and tutorials.
 */
class ACPS_Alerts_Help {

	const PAGE_SLUG     = 'acps-alerts-help';
	const TOUR_META     = 'acps_alerts_tours';
	const DISMISS_META  = 'acps_alerts_welcome_dismissed';
	const AJAX_ACTION   = 'acps_alerts_tour_state';

	/**
	 * Hooks the help system up.
	 *
	 * @return void
	 */
	public function init() {
		ACPS_Alerts_Failsafe::action( 'admin_menu', array( $this, 'register_menu' ), 'help/menu', 11 );
		ACPS_Alerts_Failsafe::action( 'admin_enqueue_scripts', array( $this, 'enqueue' ), 'help/assets' );
		ACPS_Alerts_Failsafe::action( 'current_screen', array( $this, 'add_contextual_help' ), 'help/contextual' );
		ACPS_Alerts_Failsafe::action( 'admin_notices', array( $this, 'welcome_notice' ), 'help/welcome' );
		ACPS_Alerts_Failsafe::action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_tour_state' ), 'help/ajax' );
		ACPS_Alerts_Failsafe::action( 'admin_post_acps_alerts_dismiss_welcome', array( $this, 'dismiss_welcome' ), 'help/dismiss' );
	}

	/**
	 * Adds the Help submenu.
	 *
	 * @return void
	 */
	public function register_menu() {
		// No page to draw means no menu entry, rather than a page that only says
		// something is missing.
		if ( ! is_readable( ACPS_ALERTS_DIR . 'includes/views/help-page.php' ) ) {
			return;
		}

		add_submenu_page(
			ACPS_Alerts_Admin::MENU_SLUG,
			__( 'Help & Tutorials', 'acps-alert-popups' ),
			__( 'Help & Tutorials', 'acps-alert-popups' ),
			ACPS_Alerts_Admin::capability(),
			self::PAGE_SLUG,
			array( $this, 'render_page_safely' )
		);
	}

	/**
	 * Draws the Help page under the failsafe: a failure anywhere in it draws
	 * nothing rather than breaking the admin screen around it.
	 *
	 * @return void
	 */
	public function render_page_safely() {
		echo ACPS_Alerts_Failsafe::capture( array( $this, 'render_page' ), array(), 'help/page' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside render_page().
	}

	/* ------------------------------------------------------------------ *
	 * Assets.
	 * ------------------------------------------------------------------ */

	/**
	 * Whether this screen belongs to the plugin (or is a popup being edited).
	 *
	 * @return string One of: list, post, edit, settings, help, popup, or '' for none.
	 */
	public static function current_screen_key() {
		$screen = get_current_screen();

		if ( ! $screen ) {
			return '';
		}

		// Only this plugin's own alert post type counts. Beaver Builder's own
		// popup screens belong to Beaver Builder, and nothing of ours goes on
		// them.
		if ( ACPS_Alerts_Post_Type::SLUG === (string) $screen->post_type && in_array( $screen->base, array( 'post', 'edit' ), true ) ) {
			return 'popup';
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( self::PAGE_SLUG === $page ) {
			return 'help';
		}

		if ( ACPS_Alerts_Admin::SETTINGS_SLUG === $page ) {
			return 'settings';
		}

		if ( ACPS_Alerts_Admin::MENU_SLUG === $page ) {
			$view = isset( $_GET['acps_view'] ) ? sanitize_key( wp_unslash( $_GET['acps_view'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			if ( 'edit' === $view ) {
				return 'edit';
			}

			if ( in_array( $view, array( 'post', 'wording', 'archive' ), true ) ) {
				return $view;
			}

			return 'list';
		}

		return '';
	}

	/**
	 * The screen key the tour engine runs under.
	 *
	 * The plugin's own screens, plus — only while a guided tour has brought the
	 * user there, which it marks in the URL — one of WordPress's own screens
	 * (the Pages list, to find the status page). Nothing of ours ever loads on
	 * those screens otherwise.
	 *
	 * @return string
	 */
	public static function tour_screen_key() {
		$key = self::current_screen_key();

		if ( '' !== $key ) {
			return $key;
		}

		if ( ! isset( $_GET['acps_tour'] ) || ! function_exists( 'get_current_screen' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return '';
		}

		$screen = get_current_screen();

		return $screen ? 'wp:' . sanitize_key( (string) $screen->id ) : '';
	}

	/**
	 * Loads the tour and help assets where they are useful.
	 *
	 * @return void
	 */
	public function enqueue() {
		$key = self::tour_screen_key();

		if ( '' === $key ) {
			return;
		}

		// Assets are optional files; a missing one costs the tour, not the page.
		if ( ACPS_Alerts_Failsafe::has_file( 'assets/css/tour.css' ) ) {
			wp_enqueue_style( 'acps-alerts-tour', ACPS_ALERTS_URL . 'assets/css/tour.css', array(), ACPS_Alerts_Failsafe::asset_version( 'assets/css/tour.css' ) );
		}

		if ( 'help' === $key && ACPS_Alerts_Failsafe::has_file( 'assets/css/help.css' ) ) {
			wp_enqueue_style( 'acps-alerts-help', ACPS_ALERTS_URL . 'assets/css/help.css', array( 'acps-alerts-tour' ), ACPS_Alerts_Failsafe::asset_version( 'assets/css/help.css' ) );
		}

		// The Help page's own enhancements do not depend on the tours.
		if ( 'help' === $key && ACPS_Alerts_Failsafe::has_file( 'assets/js/help.js' ) ) {
			wp_enqueue_script( 'acps-alerts-help', ACPS_ALERTS_URL . 'assets/js/help.js', array(), ACPS_Alerts_Failsafe::asset_version( 'assets/js/help.js' ), true );
		}

		if ( ! ACPS_Alerts_Failsafe::has_file( 'assets/js/tour.js' ) ) {
			return;
		}

		wp_enqueue_script( 'acps-alerts-tour', ACPS_ALERTS_URL . 'assets/js/tour.js', array(), ACPS_Alerts_Failsafe::asset_version( 'assets/js/tour.js' ), true );

		$resume = array();

		if ( isset( $_GET['acps_tour'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$resume = array(
				'tour' => sanitize_key( wp_unslash( $_GET['acps_tour'] ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'step' => isset( $_GET['acps_tour_step'] ) ? absint( $_GET['acps_tour_step'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			);
		}

		wp_localize_script(
			'acps-alerts-tour',
			'ACPSAlertsTour',
			array(
				'screen'  => $key,
				// Whether this is one of the plugin's own screens. Anything the
				// tour adds to a page beyond its own bubble is only ever added
				// on those.
				'own'     => '' !== self::current_screen_key(),
				'tours'   => $this->tours(),
				'resume'  => $resume,
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::AJAX_ACTION ),
				'i18n'    => array(
					'next'        => __( 'Next', 'acps-alert-popups' ),
					'back'        => __( 'Back', 'acps-alert-popups' ),
					'skip'        => __( 'Skip the tour', 'acps-alert-popups' ),
					'finish'      => __( 'Finish', 'acps-alert-popups' ),
					'close'       => __( 'Close the tour', 'acps-alert-popups' ),
					'takeMeThere' => __( 'Take me there', 'acps-alert-popups' ),
					'stepOf'      => __( 'Step %1$s of %2$s', 'acps-alert-popups' ),
					'done'        => __( 'Tour finished. You can run it again any time from Help & Tutorials.', 'acps-alert-popups' ),
				),
			)
		);

	}

	/* ------------------------------------------------------------------ *
	 * Tours.
	 * ------------------------------------------------------------------ */

	/**
	 * Every tour, as step definitions the JavaScript walks through.
	 *
	 * A step may name a `screen` it belongs to and a `url` to reach it, so one
	 * tour can walk across several screens.
	 *
	 * @return array
	 */
	public function tours() {
		$page = function ( array $args = array() ) {
			return add_query_arg( array_merge( array( 'page' => ACPS_Alerts_Admin::MENU_SLUG ), $args ), admin_url( 'admin.php' ) );
		};

		$list_url     = $page();
		$post_url     = $page( array( 'acps_view' => 'post' ) );
		$wording_url  = $page( array( 'acps_view' => 'wording' ) );
		$archive_url  = $page( array( 'acps_view' => 'archive' ) );
		$settings_url = admin_url( 'admin.php?page=' . ACPS_Alerts_Admin::SETTINGS_SLUG );
		$help_url     = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		$pages_url    = admin_url( 'edit.php?post_type=page' );
		$first        = $this->first_popup_id();
		$edit_url     = $first ? $page( array( 'acps_view' => 'edit', 'alert' => $first ) ) : '';

		// The status page, once the modules have been placed on one.
		$board      = (int) get_option( 'acps_alerts_board_page', 0 );
		$board_link = $board ? get_permalink( $board ) : '';
		$open_board = $board_link
			? '<p><a class="button" href="' . esc_url( add_query_arg( 'fl_builder', '', $board_link ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open the status page in Beaver Builder (new tab)', 'acps-alert-popups' ) . '</a></p>'
			: '';

		/*
		 * Each feature's walkthrough is one block of steps. The first step of
		 * every block carries its screen's url, so a block can open a tour or
		 * follow another one, and the tour can always take the user there.
		 */

		// The alerts list.
		$list = array(
			array(
				'screen'    => 'list',
				'url'       => $list_url,
				'goLabel'   => __( 'Take me to the alerts list', 'acps-alert-popups' ),
				'selector'  => '.acps-alerts-table|.wrap h1',
				'title'     => __( 'Your two alerts', 'acps-alert-popups' ),
				'html'      => '<p>' . esc_html__( 'Two rows, always: the Current Alert, which is the one you put up when something is happening, and the Normal Alert, which is the resting state. They are made for you and can never be deleted, so this list never grows.', 'acps-alert-popups' ) . '</p>',
				'placement' => 'auto',
			),
			array(
				'screen'   => 'list',
				'selector' => '#acps-col-status|.acps-alerts-table',
				'title'    => __( 'Is it up right now?', 'acps-alert-popups' ),
				'html'     => '<p>' . esc_html__( 'The Status column tells you plainly: "Live" means visitors see it now, "On, not showing" means it is switched on but outside its start and end times, and "Off" means nobody sees it.', 'acps-alert-popups' ) . '</p>',
			),
			array(
				'screen'   => 'list',
				'selector' => '.acps-row-actions|.acps-alerts-table',
				'title'    => __( 'Switch it on or off in one click', 'acps-alert-popups' ),
				'html'     => '<p>' . esc_html__( 'Hover a row to see its links. "Switch on" and "Switch off" take an alert up or down straight away, without changing its wording. "Archive" takes the Current Alert down and files it in the archive.', 'acps-alert-popups' ) . '</p>',
			),
		);

		// Posting an alert.
		$post = array(
			array(
				'screen'   => 'post',
				'url'      => $post_url,
				'goLabel'  => __( 'Open Post an Alert with me', 'acps-alert-popups' ),
				'selector' => '.acps-post-form|.wrap h1',
				'title'    => __( 'Post an Alert: the everyday job', 'acps-alert-popups' ),
				'html'     => '<p>' . esc_html__( 'This one form is all you need to put an alert up. It writes the words into the popup and onto the status page, sets the level, and switches the alert on — in one press.', 'acps-alert-popups' ) . '</p>'
					. '<p>' . esc_html__( 'The boxes start filled with what the alert says now, so a small change is a small edit.', 'acps-alert-popups' ) . '</p>',
			),
			array(
				'screen'   => 'post',
				'selector' => '#acps-post-level',
				'title'    => __( '1. The level', 'acps-alert-popups' ),
				'html'     => '<p>' . esc_html__( 'Pick what kind of alert this is — Hold, Secure, Lockdown, Evacuate, Shelter, Bus, or Information. The level sets the word and the badge the popup shows, and the colour of any status dots on your pages.', 'acps-alert-popups' ) . '</p>',
			),
			array(
				'screen'   => 'post',
				'selector' => '#acps-post-heading',
				'title'    => __( '2. The header', 'acps-alert-popups' ),
				'html'     => '<p>' . esc_html__( 'The big line at the top, on the popup and on the status page banner. Keep it short: "Schools closed today", "Two-hour delay".', 'acps-alert-popups' ) . '</p>',
			),
			array(
				'screen'   => 'post',
				'selector' => '#acps-post-text',
				'title'    => __( '3. The message', 'acps-alert-popups' ),
				'html'     => '<p>' . esc_html__( 'The details underneath: what is happening, what families should do, and when you will say more.', 'acps-alert-popups' ) . '</p>',
			),
			array(
				'screen'   => 'post',
				'selector' => '#acps-post-start|#acps-post-heading',
				'title'    => __( '4. When it runs (optional)', 'acps-alert-popups' ),
				'html'     => '<p>' . esc_html__( 'Leave both empty to put it up now on its usual schedule (unless changed, it comes down at the daily cut-off). Or set a start to post it ahead of time, and an end to take it down by itself at that time.', 'acps-alert-popups' ) . '</p>',
			),
			array(
				'screen'   => 'post',
				'selector' => '.acps-post-form .button-primary|.acps-post-form',
				'title'    => __( '5. Post it', 'acps-alert-popups' ),
				'html'     => '<p>' . esc_html__( 'One press and it is live everywhere at once: the popup across the site, the status page banner, and every status dot. Page caches are cleared for you so nobody sees an old version.', 'acps-alert-popups' ) . '</p>'
					. '<p class="acps-tour-tip">' . esc_html__( 'The popup keeps coming back for each visitor until they press its X. Pressing Escape or clicking outside only hides it for that page.', 'acps-alert-popups' ) . '</p>',
			),
		);

		// The wording.
		$wording = array(
			array(
				'screen'   => 'wording',
				'url'      => $wording_url,
				'goLabel'  => __( 'Open Wording with me', 'acps-alert-popups' ),
				'selector' => '.acps-wording-form|.wrap h1',
				'title'    => __( 'Wording: the site\'s own text', 'acps-alert-popups' ),
				'html'     => '<p>' . esc_html__( 'Everything the site says in its own voice, in one place, so nobody has to open the page builder to fix a word.', 'acps-alert-popups' ) . '</p>',
			),
			array(
				'screen'   => 'wording',
				'selector' => '#acps-rest-heading|.acps-wording-form',
				'title'    => __( 'The normal-day message', 'acps-alert-popups' ),
				'html'     => '<p>' . esc_html__( 'What the status page says when no alert is up — for example "School Status: NORMAL" and a line about regular hours.', 'acps-alert-popups' ) . '</p>',
			),
			array(
				'screen'   => 'wording',
				'selector' => '.acps-wording-levels|.acps-wording-form',
				'title'    => __( 'The words for each level', 'acps-alert-popups' ),
				'html'     => '<p>' . esc_html__( 'For each level, the word shown on the popup and banner, and the directive line beneath it. Change them to match how your district says it; leave a box empty to keep the standard wording.', 'acps-alert-popups' ) . '</p>',
			),
		);

		// The archive.
		$archive = array(
			array(
				'screen'   => 'archive',
				'url'      => $archive_url,
				'goLabel'  => __( 'Open the archive with me', 'acps-alert-popups' ),
				'selector' => '.acps-archive-table|.wrap h1',
				'title'    => __( 'The archive is for staff only', 'acps-alert-popups' ),
				'html'     => '<p>' . esc_html__( 'Every alert that comes down is filed here with its level, wording and dates, so the office can look back at what was said and when. Visitors never see it.', 'acps-alert-popups' ) . '</p>'
					. '<p>' . esc_html__( 'Entries are kept for 270 days and then removed by themselves. Delete one sooner from its row if it was posted by mistake.', 'acps-alert-popups' ) . '</p>',
			),
		);

		// The status page, on WordPress's own Pages screen.
		$status_page = array(
			array(
				'screen'   => 'wp:edit-page',
				'url'      => $pages_url,
				'goLabel'  => __( 'Take me to Pages', 'acps-alert-popups' ),
				'selector' => ( $board ? '#post-' . $board . '|' : '' ) . '.wp-list-table|.wrap h1',
				'title'    => $board ? __( 'This is your status page', 'acps-alert-popups' ) : __( 'The status page lives in Pages', 'acps-alert-popups' ),
				'html'     => ( $board
						? '<p>' . esc_html__( 'The highlighted page is your status page: it shows the current status and holds the alert\'s design. Open it in Beaver Builder to change how the popup and the banner look.', 'acps-alert-popups' ) . '</p>'
						: '<p>' . esc_html__( 'The status page is an ordinary page — make one here (for example "School Status") and open it in Beaver Builder. The plugin finds it by itself once its modules are on it.', 'acps-alert-popups' ) . '</p>' )
					. $open_board,
			),
			array(
				'screen' => 'wp:edit-page',
				'title'  => __( 'The two main modules', 'acps-alert-popups' ),
				'html'   => '<p>' . esc_html__( 'In Beaver Builder, the "Site Alerts" group holds this plugin\'s modules. Two go on the status page:', 'acps-alert-popups' ) . '</p>'
					. '<ul><li><strong>' . esc_html__( 'Current Alert', 'acps-alert-popups' ) . '</strong> — ' . esc_html__( 'the popup itself. Design it here; it is hidden on the status page and shown on every other page while an alert is up.', 'acps-alert-popups' ) . '</li>'
					. '<li><strong>' . esc_html__( 'School Status Board', 'acps-alert-popups' ) . '</strong> — ' . esc_html__( 'the banner showing the current status, or the normal-day message. Pick its colours in the module.', 'acps-alert-popups' ) . '</li></ul>'
					. '<p class="acps-tour-tip">' . esc_html__( 'Saving the page never switches an alert on or off, so you can edit the page freely while an alert is up.', 'acps-alert-popups' ) . '</p>',
			),
			array(
				'screen' => 'wp:edit-page',
				'title'  => __( 'Status dots and buttons, anywhere', 'acps-alert-popups' ),
				'html'   => '<p>' . esc_html__( 'Two more modules can go on any page:', 'acps-alert-popups' ) . '</p>'
					. '<ul><li><strong>' . esc_html__( 'Status Dot', 'acps-alert-popups' ) . '</strong> — ' . esc_html__( 'a coloured dot in the colour of the current level, with an optional label. Pick it from the module list, no typing.', 'acps-alert-popups' ) . '</li>'
					. '<li><strong>' . esc_html__( 'Alert Trigger', 'acps-alert-popups' ) . '</strong> — ' . esc_html__( 'a button or link that opens the alert when clicked.', 'acps-alert-popups' ) . '</li></ul>',
			),
		);

		// Shortcodes, on the Help page.
		$anywhere = array(
			array(
				'screen'   => 'help',
				'url'      => $help_url,
				'goLabel'  => __( 'Show me on the Help page', 'acps-alert-popups' ),
				'selector' => '#acps-guide-anywhere|.acps-help',
				'title'    => __( 'Or type it in: shortcodes', 'acps-alert-popups' ),
				'html'     => '<p>' . esc_html__( 'Where a module will not fit — a text block, a post, a widget — the same things can be typed:', 'acps-alert-popups' ) . '</p>'
					. '<ul><li><code>[schoolstatus]</code> — ' . esc_html__( 'the current status: its badge, level word, header or message.', 'acps-alert-popups' ) . '</li>'
					. '<li><code>[statusdot]</code> — ' . esc_html__( 'a status dot.', 'acps-alert-popups' ) . '</li>'
					. '<li><code>[acps_alert_trigger]</code> — ' . esc_html__( 'a button that opens the alert.', 'acps-alert-popups' ) . '</li></ul>'
					. '<p>' . esc_html__( 'This section lists every option for each one.', 'acps-alert-popups' ) . '</p>',
			),
			array(
				'screen'   => 'help',
				'selector' => '#acps-guide-button|.acps-help',
				'title'    => __( 'A button that opens the alert', 'acps-alert-popups' ),
				'html'     => '<p>' . esc_html__( 'Handy for a "Read the full alert" link in a header or a news post, after a visitor has closed the popup.', 'acps-alert-popups' ) . '</p>',
			),
		);

		// Settings.
		$settings = array(
			array(
				'screen'   => 'settings',
				'url'      => $settings_url,
				'goLabel'  => __( 'Open Settings with me', 'acps-alert-popups' ),
				'selector' => '#acps-settings-general|.wrap h1',
				'title'    => __( 'Settings: how the whole site behaves', 'acps-alert-popups' ),
				'html'     => '<p>' . esc_html__( 'These settings apply to every alert: when alerts come down each day, how a visitor\'s browser remembers closing one, what staff see, how the popup is drawn, and how everything looks.', 'acps-alert-popups' ) . '</p>'
					. '<p>' . esc_html__( 'Nothing here is needed to post an alert; the defaults work for most sites.', 'acps-alert-popups' ) . '</p>',
			),
			array(
				'screen'   => 'settings',
				'selector' => '#acps-set-cutoff',
				'title'    => __( 'The daily cut-off', 'acps-alert-popups' ),
				'html'     => '<p>' . esc_html__( 'Alerts set to come down by themselves are taken down and filed in the archive at this time each day. An alert posted after the cut-off stays up until the next day\'s.', 'acps-alert-popups' ) . '</p>',
			),
			array(
				'screen'   => 'settings',
				'selector' => '#acps-set-storage',
				'title'    => __( 'Remembering who closed it', 'acps-alert-popups' ),
				'html'     => '<p>' . esc_html__( 'Where a visitor\'s browser remembers that they pressed the X. Local storage (the default) keeps it on that device; session storage forgets when they close the browser; a cookie works like local storage for browsers that block it.', 'acps-alert-popups' ) . '</p>',
			),
			array(
				'screen'   => 'settings',
				'selector' => '#acps-set-editors',
				'title'    => __( 'Staff and previews', 'acps-alert-popups' ),
				'html'     => '<p>' . esc_html__( 'Hide the popup from staff who edit the site, so it does not get in the way while they work — and let editors preview an alert on the live site before anyone else sees it.', 'acps-alert-popups' ) . '</p>',
			),
			array(
				'screen'   => 'settings',
				'selector' => '#acps-set-rendering',
				'title'    => __( 'How the popup is drawn', 'acps-alert-popups' ),
				'html'     => '<p>' . esc_html__( 'Leave this on Automatic. It uses Beaver Builder\'s own popup when it can, and this plugin\'s accessible popup when it cannot.', 'acps-alert-popups' ) . '</p>',
			),
			array(
				'screen'   => 'settings',
				'selector' => '#acps-set-css',
				'title'    => __( 'The Main CSS editor', 'acps-alert-popups' ),
				'html'     => '<p>' . esc_html__( 'Change how anything the plugin draws looks. "Load the plugin\'s CSS" puts all of its styles in the box to edit; "Reset to defaults" clears your changes and goes back to the original look.', 'acps-alert-popups' ) . '</p>',
			),
		);

		// The fine-grained alert settings.
		$details = array();

		if ( $edit_url ) {
			$details = array(
				array(
					'screen'   => 'edit',
					'url'      => $edit_url,
					'goLabel'  => __( 'Open the alert settings with me', 'acps-alert-popups' ),
					'selector' => '[data-acps-section="status"]',
					'title'    => __( 'An alert\'s own settings', 'acps-alert-popups' ),
					'html'     => '<p>' . esc_html__( 'This walks the settings on the Normal Alert, where you can look safely without touching anything live. The Current Alert has the same settings on its module on the status page.', 'acps-alert-popups' ) . '</p>',
				),
				array(
					'screen'   => 'edit',
					'selector' => '[data-acps-section="schedule"]',
					'title'    => __( 'Schedule', 'acps-alert-popups' ),
					'html'     => '<p>' . esc_html__( 'Start and end times in your site timezone, or "come down at the daily cut-off", or "stay up until I take it down".', 'acps-alert-popups' ) . '</p>',
				),
				array(
					'screen'   => 'edit',
					'selector' => '[data-acps-section="where"]',
					'title'    => __( 'Where it shows', 'acps-alert-popups' ),
					'html'     => '<p>' . esc_html__( 'The whole site, the front page, or pages you choose. Leaving a page out always wins over including it. A path like /news/* covers everything under /news.', 'acps-alert-popups' ) . '</p>',
				),
				array(
					'screen'   => 'edit',
					'selector' => '[data-acps-section="who"]',
					'title'    => __( 'Who sees it', 'acps-alert-popups' ),
					'html'     => '<p>' . esc_html__( 'Everyone, only visitors, only logged-in users, or particular roles — for a staff-only notice.', 'acps-alert-popups' ) . '</p>',
				),
				array(
					'screen'   => 'edit',
					'selector' => '[data-acps-section="how"]',
					'title'    => __( 'How it opens', 'acps-alert-popups' ),
					'html'     => '<p>' . esc_html__( 'Straight away, after a delay, after some scrolling, as a visitor goes to leave, or only when a button opens it.', 'acps-alert-popups' ) . '</p>',
				),
				array(
					'screen'   => 'edit',
					'selector' => '[data-acps-section="appearance"]',
					'title'    => __( 'How it looks', 'acps-alert-popups' ),
					'html'     => '<p>' . esc_html__( 'Where it sits on the screen, how wide it gets, whether the page dims behind it, and how it can be closed.', 'acps-alert-popups' ) . '</p>',
				),
			);
		}

		// Help, and finding more.
		$finish = array(
			array(
				'screen'   => 'help',
				'url'      => $help_url,
				'goLabel'  => __( 'Take me to Help', 'acps-alert-popups' ),
				'selector' => '.acps-help-checklist|.acps-help',
				'title'    => __( 'Your setup checklist', 'acps-alert-popups' ),
				'html'     => '<p>' . esc_html__( 'This ticks itself off as you set things up, so you can see at a glance what is left.', 'acps-alert-popups' ) . '</p>',
			),
			array(
				'screen'   => 'help',
				'selector' => '#acps-guide-trouble|.acps-help',
				'title'    => __( 'When something is not showing', 'acps-alert-popups' ),
				'html'     => '<p>' . esc_html__( 'Go through this list first — it covers every common reason an alert does not appear. Every tour on this page can be run again any time.', 'acps-alert-popups' ) . '</p>',
			),
		);

		$intro = array(
			'title' => __( 'Welcome — here is everything, one screen at a time', 'acps-alert-popups' ),
			'html'  => '<p>' . esc_html__( 'This tour takes you to every part of the plugin in turn, on the real screens, and shows you what each control does. It moves you from page to page by itself — just press the button in each box.', 'acps-alert-popups' ) . '</p>'
				. '<p class="acps-tour-tip">' . esc_html__( 'Press Escape to stop at any point, and pick it up again from Help & Tutorials.', 'acps-alert-popups' ) . '</p>',
		);

		$tours = array();

		$tours['full-setup'] = array(
			'title'       => __( 'The complete guided tour', 'acps-alert-popups' ),
			'description' => __( 'Every feature, start to finish: posting an alert, wording, the archive, the status page and its modules, dots and shortcodes, and settings.', 'acps-alert-popups' ),
			'steps'       => array_merge( array( $intro ), $list, $post, $wording, $archive, $status_page, $anywhere, $settings, $details, $finish ),
		);

		$tours['first-alert'] = array(
			'title'       => __( 'Post an alert (the quick way)', 'acps-alert-popups' ),
			'description' => __( 'The everyday job, in under a minute.', 'acps-alert-popups' ),
			'steps'       => array_merge( $list, $post ),
		);

		$tours['status-page'] = array(
			'title'       => __( 'Set up the status page', 'acps-alert-popups' ),
			'description' => __( 'Where the status page lives, the modules that go on it, and the dots, buttons and shortcodes for other pages.', 'acps-alert-popups' ),
			'steps'       => array_merge( $status_page, $anywhere ),
		);

		$tours['wording-archive'] = array(
			'title'       => __( 'Wording and the archive', 'acps-alert-popups' ),
			'description' => __( 'Change the normal-day message and the level words, and look back at past alerts.', 'acps-alert-popups' ),
			'steps'       => array_merge( $wording, $archive ),
		);

		$tours['settings-tour'] = array(
			'title'       => __( 'Settings', 'acps-alert-popups' ),
			'description' => __( 'The daily cut-off, dismissals, staff and previews, rendering, and the Main CSS editor.', 'acps-alert-popups' ),
			'steps'       => $settings,
		);

		if ( $details ) {
			$tours['settings-deep'] = array(
				'title'       => __( 'An alert\'s fine-grained settings', 'acps-alert-popups' ),
				'description' => __( 'Schedule, where it shows, who sees it, how it opens and how it looks.', 'acps-alert-popups' ),
				'steps'       => $details,
			);
		}

		$tours['troubleshoot'] = array(
			'title'       => __( 'When something is not working', 'acps-alert-popups' ),
			'description' => __( 'The quickest checks when an alert is not showing as expected.', 'acps-alert-popups' ),
			'steps'       => array_merge( array( $finish[1] + array( 'url' => $help_url ) ), array( $settings[3] + array( 'url' => $settings_url ) ), array( $list[1] + array( 'url' => $list_url ) ) ),
		);

		/**
		 * Filters the guided tours.
		 *
		 * @param array $tours Tour definitions.
		 */
		return (array) apply_filters( 'acps_alerts_tours', $tours );
	}

	/**
	 * The alert whose settings screen the tours can demonstrate on.
	 *
	 * The Current Alert is deliberately *not* it: that one is edited on the
	 * status page, so its admin screen is a signpost rather than a form, and a
	 * tour pointing at controls there would find nothing. The Normal Alert
	 * still has the full form, and every control on it is the same control.
	 *
	 * @return int
	 */
	protected function first_popup_id() {
		if ( class_exists( 'ACPS_Alerts_Post_Type' ) ) {
			$normal = ACPS_Alerts_Post_Type::get_alert( ACPS_Alerts_Post_Type::ROLE_NORMAL );

			if ( $normal ) {
				return (int) $normal;
			}
		}

		$popups = ACPS_Alerts_Source::get_popups( array( 'posts_per_page' => 1 ) );

		return ! empty( $popups[0] ) ? (int) $popups[0]->ID : 0;
	}

	/**
	 * Records that a tour was finished or abandoned.
	 *
	 * @return void
	 */
	public function ajax_tour_state() {
		check_ajax_referer( self::AJAX_ACTION, 'nonce' );

		if ( ! current_user_can( ACPS_Alerts_Admin::capability() ) ) {
			wp_send_json_error( array(), 403 );
		}

		$tour   = isset( $_POST['tour'] ) ? sanitize_key( wp_unslash( $_POST['tour'] ) ) : '';
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';

		if ( '' === $tour ) {
			wp_send_json_error( array(), 400 );
		}

		$state = get_user_meta( get_current_user_id(), self::TOUR_META, true );

		if ( ! is_array( $state ) ) {
			$state = array();
		}

		$state[ $tour ] = array(
			'status' => $status,
			'time'   => time(),
		);

		update_user_meta( get_current_user_id(), self::TOUR_META, $state );

		wp_send_json_success();
	}

	/**
	 * Whether the current user has finished a tour.
	 *
	 * @param string $tour Tour id.
	 * @return bool
	 */
	public static function tour_done( $tour ) {
		$state = get_user_meta( get_current_user_id(), self::TOUR_META, true );

		return is_array( $state ) && isset( $state[ $tour ]['status'] ) && 'done' === $state[ $tour ]['status'];
	}

	/* ------------------------------------------------------------------ *
	 * Getting-started checklist.
	 * ------------------------------------------------------------------ */

	/**
	 * The setup checklist, with each item's state worked out live.
	 *
	 * @return array[]
	 */
	public function checklist() {
		$popups  = ACPS_Alerts_Source::get_popups();
		$enabled = 0;
		$live    = 0;

		foreach ( $popups as $post ) {
			$alert = new ACPS_Alerts_Alert( $post );

			if ( $alert->get( 'enabled' ) ) {
				$enabled++;

				if ( 'publish' === get_post_status( $post ) && ACPS_Alerts_Conditions::passes_schedule( $alert ) ) {
					$live++;
				}
			}
		}

		// Setup steps only: things a person does. Whether the plugin's own parts
		// (its post type, its two alerts) came up is a failure check, reported
		// in the remote console rather than on screen.
		return array(
			array(
				'key'   => 'builder',
				'done'  => ACPS_Alerts_Source::builder_active(),
				'label' => __( 'Beaver Builder is active (optional)', 'acps-alert-popups' ),
				'why'   => __( 'With it you design alerts in the builder. Without it they still work — you write them in the normal editor.', 'acps-alert-popups' ),
				'fix'   => __( 'Activate Beaver Builder under Plugins if you want to design alerts in the builder.', 'acps-alert-popups' ),
				'url'   => admin_url( 'plugins.php' ),
				'cta'   => __( 'Open Plugins', 'acps-alert-popups' ),
			),
			array(
				'key'   => 'board',
				'done'  => (bool) get_option( 'acps_alerts_board_page', 0 ),
				'label' => __( 'The status page is set up', 'acps-alert-popups' ),
				'why'   => __( 'Two modules go on it, both in the Site Alerts group: "Current Alert", which is the popup you write and switch on, and "School Status Board", which draws the banner.', 'acps-alert-popups' ),
				'fix'   => __( 'Edit your status page in Beaver Builder and add both modules from the Site Alerts group.', 'acps-alert-popups' ),
				'url'   => admin_url( 'edit.php?post_type=page' ),
				'cta'   => __( 'Open Pages', 'acps-alert-popups' ),
			),
			array(
				'key'   => 'used',
				'done'  => $enabled > 0 || (int) get_option( 'acps_alerts_used_once', 0 ) > 0,
				'label' => __( 'You have used the Current Alert once', 'acps-alert-popups' ),
				'why'   => __( 'Worth doing on a quiet day, so the whole path is proven before you need it in a hurry.', 'acps-alert-popups' ),
				'fix'   => __( 'Post an alert from Site Alerts → Post an Alert (or open the Current Alert popup on the status page), then switch it off again.', 'acps-alert-popups' ),
				'url'   => admin_url( 'admin.php?page=' . ACPS_Alerts_Admin::MENU_SLUG ),
				'cta'   => __( 'See the alerts', 'acps-alert-popups' ),
			),
			array(
				'key'   => 'live',
				'done'  => $live > 0,
				'label' => __( 'The Current Alert is showing right now', 'acps-alert-popups' ),
				'why'   => __( 'Only true when something is actually happening. On a normal day this stays unticked, and that is correct.', 'acps-alert-popups' ),
				'fix'   => __( 'Nothing to do — this ticks itself when you switch the Current Alert on.', 'acps-alert-popups' ),
				'url'   => admin_url( 'admin.php?page=' . ACPS_Alerts_Admin::MENU_SLUG ),
				'cta'   => __( 'Check status', 'acps-alert-popups' ),
			),
		);
	}

	/**
	 * How far through setup the site is.
	 *
	 * @return array { done, total, percent }
	 */
	public function progress() {
		$items = $this->checklist();
		$done  = 0;

		foreach ( $items as $item ) {
			if ( $item['done'] ) {
				$done++;
			}
		}

		$total = max( 1, count( $items ) );

		return array(
			'done'    => $done,
			'total'   => count( $items ),
			'percent' => (int) round( $done / $total * 100 ),
		);
	}

	/* ------------------------------------------------------------------ *
	 * First-run coaching.
	 * ------------------------------------------------------------------ */

	/**
	 * Offers the tour the first time someone opens one of the plugin screens.
	 *
	 * @return void
	 */
	public function welcome_notice() {
		$key = self::current_screen_key();

		if ( ! in_array( $key, array( 'list', 'edit', 'new' ), true ) ) {
			return;
		}

		if ( ! current_user_can( ACPS_Alerts_Admin::capability() ) ) {
			return;
		}

		if ( get_user_meta( get_current_user_id(), self::DISMISS_META, true ) || self::tour_done( 'first-alert' ) ) {
			return;
		}

		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=acps_alerts_dismiss_welcome' ), 'acps_alerts_dismiss_welcome' );
		?>
		<div class="notice notice-info acps-welcome">
			<h3><?php esc_html_e( 'New here? Let me show you around.', 'acps-alert-popups' ); ?></h3>
			<p><?php esc_html_e( 'The guided tour takes you to each screen in turn, points at the real controls and explains what each one does. No reading required.', 'acps-alert-popups' ); ?></p>
			<p>
				<button type="button" class="button button-primary" data-acps-tour="full-setup"><?php esc_html_e( 'Start the guided tour', 'acps-alert-popups' ); ?></button>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>"><?php esc_html_e( 'Open Help & Tutorials', 'acps-alert-popups' ); ?></a>
				<a class="button-link" href="<?php echo esc_url( $dismiss ); ?>"><?php esc_html_e( 'No thanks, hide this', 'acps-alert-popups' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Hides the welcome notice for this user.
	 *
	 * @return void
	 */
	public function dismiss_welcome() {
		check_admin_referer( 'acps_alerts_dismiss_welcome' );

		if ( ! current_user_can( ACPS_Alerts_Admin::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'acps-alert-popups' ), '', array( 'response' => 403 ) );
		}

		update_user_meta( get_current_user_id(), self::DISMISS_META, 1 );

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=' . ACPS_Alerts_Admin::MENU_SLUG ) );
		exit;
	}

	/* ------------------------------------------------------------------ *
	 * WordPress contextual help tabs.
	 * ------------------------------------------------------------------ */

	/**
	 * Fills in the Help tab at the top right of each of our screens.
	 *
	 * @return void
	 */
	public function add_contextual_help() {
		$screen = get_current_screen();
		$key    = self::current_screen_key();

		if ( ! $screen || '' === $key ) {
			return;
		}

		$screen->add_help_tab(
			array(
				'id'      => 'acps-alerts-overview',
				'title'   => __( 'Alerts overview', 'acps-alert-popups' ),
				'content' => '<p>' . esc_html__( 'This site has two alerts and no more: the Current Alert you switch on when something is happening, and the Normal Alert resting state. Neither can be deleted.', 'acps-alert-popups' ) . '</p>'
					. '<p>' . esc_html__( 'Post an update the quick way from Site Alerts → Post an Alert — a level, a header, a message, one button — or open the Current Alert popup on the status page in Beaver Builder to change how it is built. Both edit the same popup.', 'acps-alert-popups' ) . '</p>',
			)
		);

		if ( in_array( $key, array( 'edit', 'popup' ), true ) ) {
			$screen->add_help_tab(
				array(
					'id'      => 'acps-alerts-settings-help',
					'title'   => __( 'The settings', 'acps-alert-popups' ),
					'content' => '<p><strong>' . esc_html__( 'Status', 'acps-alert-popups' ) . '</strong> — ' . esc_html__( 'whether it is live, and its status level, which sets the colour and the word shown.', 'acps-alert-popups' ) . '</p>'
						. '<p><strong>' . esc_html__( 'Schedule', 'acps-alert-popups' ) . '</strong> — ' . esc_html__( 'start and end in your site timezone; leave empty for open-ended.', 'acps-alert-popups' ) . '</p>'
						. '<p><strong>' . esc_html__( 'Where it shows', 'acps-alert-popups' ) . '</strong> — ' . esc_html__( 'the whole site or a list you choose. Exclusions always win.', 'acps-alert-popups' ) . '</p>'
						. '<p><strong>' . esc_html__( 'How it opens', 'acps-alert-popups' ) . '</strong> — ' . esc_html__( 'the trigger, and how often it may come back.', 'acps-alert-popups' ) . '</p>',
				)
			);
		}

		$screen->add_help_tab(
			array(
				'id'      => 'acps-alerts-trouble',
				'title'   => __( 'It is not showing', 'acps-alert-popups' ),
				'content' => '<p>' . esc_html__( 'Work down this list — it is almost always one of these:', 'acps-alert-popups' ) . '</p>'
					. '<ol><li>' . esc_html__( 'The popup is still a draft. Publish it.', 'acps-alert-popups' ) . '</li>'
					. '<li>' . esc_html__( 'The alert is not switched on.', 'acps-alert-popups' ) . '</li>'
					. '<li>' . esc_html__( 'Its schedule has not started, or has already ended.', 'acps-alert-popups' ) . '</li>'
					. '<li>' . esc_html__( 'You already closed it, and it is set to show once. Try a private window.', 'acps-alert-popups' ) . '</li>'
					. '<li>' . esc_html__( 'The page is on the never-show list.', 'acps-alert-popups' ) . '</li>'
					. '<li>' . esc_html__( 'Alerts are hidden from editors in Settings, and you are logged in.', 'acps-alert-popups' ) . '</li></ol>',
			)
		);

		$screen->set_help_sidebar(
			'<p><strong>' . esc_html__( 'More help', 'acps-alert-popups' ) . '</strong></p>'
			. '<p><a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) . '">' . esc_html__( 'Help & Tutorials', 'acps-alert-popups' ) . '</a></p>'
		);
	}

	/**
	 * Renders the Help & Tutorials screen.
	 *
	 * @return void
	 */
	public function render_page() {
		$view = ACPS_ALERTS_DIR . 'includes/views/help-page.php';

		// An optional file, so never require it blindly. (The menu entry is not
		// even registered without it; this covers a file removed mid-request.)
		if ( ! is_readable( $view ) ) {
			return;
		}

		require $view;
	}
}
