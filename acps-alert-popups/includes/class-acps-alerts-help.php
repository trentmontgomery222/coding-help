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
		add_submenu_page(
			ACPS_Alerts_Admin::MENU_SLUG,
			__( 'Help & Tutorials', 'acps-alert-popups' ),
			__( 'Help & Tutorials', 'acps-alert-popups' ),
			ACPS_Alerts_Admin::capability(),
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/* ------------------------------------------------------------------ *
	 * Assets.
	 * ------------------------------------------------------------------ */

	/**
	 * Whether this screen belongs to the plugin (or is a popup being edited).
	 *
	 * @return string One of: list, edit, settings, help, popup, or '' for none.
	 */
	public static function current_screen_key() {
		$screen = get_current_screen();

		if ( ! $screen ) {
			return '';
		}

		$post_type = ACPS_Alerts_Source::post_type();

		if ( $post_type && $screen->post_type === $post_type && in_array( $screen->base, array( 'post', 'edit' ), true ) ) {
			return 'popup';
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( self::PAGE_SLUG === $page ) {
			return 'help';
		}

		if ( ACPS_Alerts_Admin::SETTINGS_SLUG === $page ) {
			return 'settings';
		}

		if ( 'acps-alerts-new' === $page ) {
			return 'new';
		}

		if ( ACPS_Alerts_Admin::MENU_SLUG === $page ) {
			$view = isset( $_GET['acps_view'] ) ? sanitize_key( wp_unslash( $_GET['acps_view'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			return ( 'edit' === $view ) ? 'edit' : 'list';
		}

		return '';
	}

	/**
	 * Loads the tour and help assets where they are useful.
	 *
	 * @return void
	 */
	public function enqueue() {
		$key = self::current_screen_key();

		if ( '' === $key ) {
			return;
		}

		// Assets are optional files; a missing one costs the tour, not the page.
		if ( ACPS_Alerts_Failsafe::has_file( 'assets/css/tour.css' ) ) {
			wp_enqueue_style( 'acps-alerts-tour', ACPS_ALERTS_URL . 'assets/css/tour.css', array(), ACPS_ALERTS_VERSION );
		}

		if ( 'help' === $key && ACPS_Alerts_Failsafe::has_file( 'assets/css/help.css' ) ) {
			wp_enqueue_style( 'acps-alerts-help', ACPS_ALERTS_URL . 'assets/css/help.css', array( 'acps-alerts-tour' ), ACPS_ALERTS_VERSION );
		}

		if ( ! ACPS_Alerts_Failsafe::has_file( 'assets/js/tour.js' ) ) {
			return;
		}

		wp_enqueue_script( 'acps-alerts-tour', ACPS_ALERTS_URL . 'assets/js/tour.js', array(), ACPS_ALERTS_VERSION, true );

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

		if ( 'help' === $key && ACPS_Alerts_Failsafe::has_file( 'assets/js/help.js' ) ) {
			wp_enqueue_script( 'acps-alerts-help', ACPS_ALERTS_URL . 'assets/js/help.js', array( 'acps-alerts-tour' ), ACPS_ALERTS_VERSION, true );
		}
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
		$list_url  = admin_url( 'admin.php?page=' . ACPS_Alerts_Admin::MENU_SLUG );
		$help_url  = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		$first     = $this->first_popup_id();
		$edit_url  = $first
			? add_query_arg(
				array(
					'page'      => ACPS_Alerts_Admin::MENU_SLUG,
					'acps_view' => 'edit',
					'alert'     => $first,
				),
				admin_url( 'admin.php' )
			)
			: '';

		$tours = array();

		/* ---- Tour 1: the whole job, start to finish ---- */
		$steps = array(
			array(
				'title' => __( 'Welcome — this takes about two minutes', 'acps-alert-popups' ),
				'html'  => '<p>' . esc_html__( 'An alert is just a Beaver Builder popup that this plugin switches on and aims at the right people.', 'acps-alert-popups' ) . '</p>'
					. '<p>' . esc_html__( 'You only ever do two jobs: design it in Beaver Builder, then switch it on here. I will show you both.', 'acps-alert-popups' ) . '</p>'
					. '<p class="acps-tour-tip">' . esc_html__( 'You can leave at any time with the Escape key, and pick the tour up again from Help & Tutorials.', 'acps-alert-popups' ) . '</p>',
			),
			array(
				'screen'   => 'list',
				'url'      => $list_url,
				'selector' => '#toplevel_page_acps-alerts, .acps-alerts-table, .wrap h1',
				'title'    => __( 'This is your alerts list', 'acps-alert-popups' ),
				'html'     => '<p>' . esc_html__( 'Every Beaver Builder popup on the site shows up here on its own. Nothing to import, nothing to connect.', 'acps-alert-popups' ) . '</p>'
					. '<p>' . esc_html__( 'If the list is empty, you simply have not made a popup yet — that is the next step.', 'acps-alert-popups' ) . '</p>',
				'placement' => 'auto',
			),
			array(
				'screen'   => 'list',
				'selector' => '.page-title-action',
				'title'    => __( 'Making a new alert', 'acps-alert-popups' ),
				'html'     => '<p>' . esc_html__( 'This button walks you into Beaver Builder to create the popup itself.', 'acps-alert-popups' ) . '</p>'
					. '<p>' . esc_html__( 'In Beaver Builder you write the words and pick the colours, exactly like building any other page. Then publish it and come back here.', 'acps-alert-popups' ) . '</p>'
					. '<p class="acps-tour-tip">' . esc_html__( 'Nothing you do in Beaver Builder can switch an alert on. Being live is always decided here.', 'acps-alert-popups' ) . '</p>',
			),
			array(
				'screen'   => 'list',
				'selector' => '.acps-alerts-table tbody tr:first-child .acps-status, .acps-alerts-table',
				'title'    => __( 'The Status column tells you the truth', 'acps-alert-popups' ),
				'html'     => '<p><strong>' . esc_html__( 'Live', 'acps-alert-popups' ) . '</strong> — ' . esc_html__( 'visitors are seeing it right now.', 'acps-alert-popups' ) . '</p>'
					. '<p><strong>' . esc_html__( 'On, not showing', 'acps-alert-popups' ) . '</strong> — ' . esc_html__( 'switched on, but its schedule has not started, has finished, or the popup is still a draft.', 'acps-alert-popups' ) . '</p>'
					. '<p><strong>' . esc_html__( 'Off', 'acps-alert-popups' ) . '</strong> — ' . esc_html__( 'nobody is seeing it.', 'acps-alert-popups' ) . '</p>',
			),
			array(
				'screen'   => 'list',
				'selector' => '.acps-alerts-table tbody tr:first-child .row-actions, .acps-alerts-table',
				'title'    => __( 'Everything you can do to an alert', 'acps-alert-popups' ),
				'html'     => '<p>' . esc_html__( 'Hover a row and these appear:', 'acps-alert-popups' ) . '</p>'
					. '<p><strong>' . esc_html__( 'Alert settings', 'acps-alert-popups' ) . '</strong> — ' . esc_html__( 'when, where and who.', 'acps-alert-popups' ) . '</p>'
					. '<p><strong>' . esc_html__( 'Edit in Beaver Builder', 'acps-alert-popups' ) . '</strong> — ' . esc_html__( 'change what it says and looks like.', 'acps-alert-popups' ) . '</p>'
					. '<p><strong>' . esc_html__( 'Switch on / Switch off', 'acps-alert-popups' ) . '</strong> — ' . esc_html__( 'the one-click on/off.', 'acps-alert-popups' ) . '</p>',
			),
		);

		if ( $edit_url ) {
			$steps[] = array(
				'screen'    => 'edit',
				'url'       => $edit_url,
				'goLabel'   => __( 'Open an alert with me', 'acps-alert-popups' ),
				'selector'  => '[data-acps-section="status"]',
				'title'     => __( 'Switching it on', 'acps-alert-popups' ),
				'html'      => '<p>' . esc_html__( 'This first box is the important one. Tick "Alert is live" and the alert starts working as soon as you save.', 'acps-alert-popups' ) . '</p>'
					. '<p>' . esc_html__( 'Severity only sets the colour of the stripe along the top. Priority decides who wins if two alerts qualify at once.', 'acps-alert-popups' ) . '</p>',
			);

			$steps[] = array(
				'screen'   => 'edit',
				'selector' => '[data-acps-section="schedule"]',
				'title'    => __( 'Set it and forget it', 'acps-alert-popups' ),
				'html'     => '<p>' . esc_html__( 'Fill in an end date and the alert stops on its own. You do not have to remember to come back and switch it off.', 'acps-alert-popups' ) . '</p>'
					. '<p>' . esc_html__( 'Leave both empty and it simply runs until you switch it off.', 'acps-alert-popups' ) . '</p>'
					. '<p class="acps-tour-tip">' . esc_html__( 'Times use your site timezone, not the visitor’s.', 'acps-alert-popups' ) . '</p>',
			);

			$steps[] = array(
				'screen'   => 'edit',
				'selector' => '[data-acps-section="where"]',
				'title'    => __( 'Choosing which pages show it', 'acps-alert-popups' ),
				'html'     => '<p>' . esc_html__( 'Entire site is the usual answer for a closure or an emergency.', 'acps-alert-popups' ) . '</p>'
					. '<p>' . esc_html__( 'Pick "Selected locations" and you can list pages, post types, or URL paths like /news/*.', 'acps-alert-popups' ) . '</p>'
					. '<p class="acps-tour-tip">' . esc_html__( 'The never-show box always wins. Use it to keep alerts off checkout or thank-you pages.', 'acps-alert-popups' ) . '</p>',
			);

			$steps[] = array(
				'screen'   => 'edit',
				'selector' => '[data-acps-section="how"]',
				'title'    => __( 'How often people see it', 'acps-alert-popups' ),
				'html'     => '<p>' . esc_html__( 'This is the setting people get wrong most often.', 'acps-alert-popups' ) . '</p>'
					. '<p><strong>' . esc_html__( 'Once per browser session', 'acps-alert-popups' ) . '</strong> ' . esc_html__( 'is the friendly default: they see it once, then it leaves them alone.', 'acps-alert-popups' ) . '</p>'
					. '<p>' . esc_html__( 'Every page view is for genuine emergencies only — it reappears on every single page.', 'acps-alert-popups' ) . '</p>',
			);

			$steps[] = array(
				'screen'   => 'edit',
				'selector' => '[data-acps-section="appearance"]',
				'title'    => __( 'One accessibility rule', 'acps-alert-popups' ),
				'html'     => '<p>' . esc_html__( 'Leave at least one way to close the alert switched on: the close button, clicking the background, or the Escape key.', 'acps-alert-popups' ) . '</p>'
					. '<p>' . esc_html__( 'Turn all three off and a keyboard visitor is trapped with no way out.', 'acps-alert-popups' ) . '</p>',
			);
		}

		$steps[] = array(
			'screen'   => 'help',
			'url'      => $help_url,
			'goLabel'  => __( 'Show me the guides', 'acps-alert-popups' ),
			'selector' => '.acps-help-checklist',
			'title'    => __( 'That is the whole plugin', 'acps-alert-popups' ),
			'html'     => '<p>' . esc_html__( 'This checklist tracks your setup and ticks itself off as you go.', 'acps-alert-popups' ) . '</p>'
				. '<p>' . esc_html__( 'Below it are illustrated guides for every setting, plus troubleshooting for when something does not appear.', 'acps-alert-popups' ) . '</p>',
		);

		$tours['first-alert'] = array(
			'title' => __( 'Make your first alert', 'acps-alert-popups' ),
			'steps' => $steps,
		);

		/* ---- Tour 2: the settings screen in detail ---- */
		if ( $edit_url ) {
			$tours['settings-deep'] = array(
				'title' => __( 'Every setting explained', 'acps-alert-popups' ),
				'steps' => array(
					array(
						'screen'   => 'edit',
						'url'      => $edit_url,
						'selector' => '[data-acps-section="status"]',
						'title'    => __( 'Status', 'acps-alert-popups' ),
						'html'     => '<p>' . esc_html__( 'Live on or off, the colour of the stripe, and which alert wins when two qualify at once.', 'acps-alert-popups' ) . '</p>',
					),
					array(
						'screen'   => 'edit',
						'selector' => '[data-acps-section="schedule"]',
						'title'    => __( 'Schedule', 'acps-alert-popups' ),
						'html'     => '<p>' . esc_html__( 'Start and end times in your site timezone. Either can be left empty.', 'acps-alert-popups' ) . '</p>',
					),
					array(
						'screen'   => 'edit',
						'selector' => '[data-acps-section="where"]',
						'title'    => __( 'Where it shows', 'acps-alert-popups' ),
						'html'     => '<p>' . esc_html__( 'Entire site, the front page, or a list you choose. Exclusions always beat inclusions.', 'acps-alert-popups' ) . '</p>'
							. '<p>' . esc_html__( 'A path like /news/* matches everything under /news. Add /news* to match /news itself too.', 'acps-alert-popups' ) . '</p>',
					),
					array(
						'screen'   => 'edit',
						'selector' => '[data-acps-section="who"]',
						'title'    => __( 'Who sees it', 'acps-alert-popups' ),
						'html'     => '<p>' . esc_html__( 'Everyone, only logged-out visitors, only logged-in users, or particular roles — handy for staff-only notices.', 'acps-alert-popups' ) . '</p>',
					),
					array(
						'screen'   => 'edit',
						'selector' => '[data-acps-section="how"]',
						'title'    => __( 'How it opens, and how often', 'acps-alert-popups' ),
						'html'     => '<p>' . esc_html__( 'Straight away, after a delay, once they scroll, as they go to leave, or only when a button opens it.', 'acps-alert-popups' ) . '</p>',
					),
					array(
						'screen'   => 'edit',
						'selector' => '[data-acps-section="appearance"]',
						'title'    => __( 'Appearance', 'acps-alert-popups' ),
						'html'     => '<p>' . esc_html__( 'Where it sits, how wide it gets, whether the page dims behind it, and how it can be closed.', 'acps-alert-popups' ) . '</p>',
					),
					array(
						'screen'   => 'edit',
						'selector' => '.submit .button-primary, p.submit',
						'title'    => __( 'Save, and you are done', 'acps-alert-popups' ),
						'html'     => '<p>' . esc_html__( 'Changes take effect immediately. Use the preview link further down to check it on the live site before anyone else sees it.', 'acps-alert-popups' ) . '</p>',
					),
				),
			);
		}

		/**
		 * Filters the guided tours.
		 *
		 * @param array $tours Tour definitions.
		 */
		return (array) apply_filters( 'acps_alerts_tours', $tours );
	}

	/**
	 * The most useful popup to demonstrate on, if there is one.
	 *
	 * @return int
	 */
	protected function first_popup_id() {
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
		$popups    = ACPS_Alerts_Source::get_popups();
		$published = 0;
		$enabled   = 0;
		$live      = 0;

		foreach ( $popups as $post ) {
			if ( 'publish' === get_post_status( $post ) ) {
				$published++;
			}

			$alert = new ACPS_Alerts_Alert( $post );

			if ( $alert->get( 'enabled' ) ) {
				$enabled++;

				if ( 'publish' === get_post_status( $post ) && ACPS_Alerts_Conditions::passes_schedule( $alert ) ) {
					$live++;
				}
			}
		}

		return array(
			array(
				'key'   => 'builder',
				'done'  => ACPS_Alerts_Source::builder_active(),
				'label' => __( 'Beaver Builder is active', 'acps-alert-popups' ),
				'why'   => __( 'Alerts are Beaver Builder popups, so the builder has to be running.', 'acps-alert-popups' ),
				'fix'   => __( 'Activate Beaver Builder under Plugins.', 'acps-alert-popups' ),
				'url'   => admin_url( 'plugins.php' ),
				'cta'   => __( 'Open Plugins', 'acps-alert-popups' ),
			),
			array(
				'key'   => 'type',
				'done'  => '' !== ACPS_Alerts_Source::post_type(),
				'label' => __( 'Popups were found', 'acps-alert-popups' ),
				'why'   => __( 'The plugin finds Beaver Builder’s popups on its own.', 'acps-alert-popups' ),
				'fix'   => __( 'If this stays unticked, choose the popup type by hand in Settings.', 'acps-alert-popups' ),
				'url'   => admin_url( 'admin.php?page=' . ACPS_Alerts_Admin::SETTINGS_SLUG ),
				'cta'   => __( 'Open Settings', 'acps-alert-popups' ),
			),
			array(
				'key'   => 'created',
				'done'  => count( $popups ) > 0,
				'label' => __( 'You have made at least one popup', 'acps-alert-popups' ),
				'why'   => __( 'This is the alert itself: the words and the design.', 'acps-alert-popups' ),
				'fix'   => __( 'Create one in Beaver Builder — it appears here by itself.', 'acps-alert-popups' ),
				'url'   => admin_url( 'admin.php?page=acps-alerts-new' ),
				'cta'   => __( 'Make a popup', 'acps-alert-popups' ),
			),
			array(
				'key'   => 'published',
				'done'  => $published > 0,
				'label' => __( 'A popup is published', 'acps-alert-popups' ),
				'why'   => __( 'A draft popup never shows, even when the alert is switched on.', 'acps-alert-popups' ),
				'fix'   => __( 'Open the popup and publish it.', 'acps-alert-popups' ),
				'url'   => admin_url( 'admin.php?page=' . ACPS_Alerts_Admin::MENU_SLUG ),
				'cta'   => __( 'See my popups', 'acps-alert-popups' ),
			),
			array(
				'key'   => 'enabled',
				'done'  => $enabled > 0,
				'label' => __( 'An alert is switched on', 'acps-alert-popups' ),
				'why'   => __( 'This is the switch that makes an alert real.', 'acps-alert-popups' ),
				'fix'   => __( 'Open the alert and tick "Alert is live".', 'acps-alert-popups' ),
				'url'   => admin_url( 'admin.php?page=' . ACPS_Alerts_Admin::MENU_SLUG ),
				'cta'   => __( 'Switch one on', 'acps-alert-popups' ),
			),
			array(
				'key'   => 'live',
				'done'  => $live > 0,
				'label' => __( 'An alert is live right now', 'acps-alert-popups' ),
				'why'   => __( 'Switched on, published, and inside its schedule.', 'acps-alert-popups' ),
				'fix'   => __( 'Check the Status column — "On, not showing" usually means a schedule or a draft.', 'acps-alert-popups' ),
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
			<h3><?php esc_html_e( 'New here? Let me walk you through it.', 'acps-alert-popups' ); ?></h3>
			<p><?php esc_html_e( 'A two-minute guided tour points at each control on the real screen and explains what it does. No reading required.', 'acps-alert-popups' ); ?></p>
			<p>
				<button type="button" class="button button-primary" data-acps-tour="first-alert"><?php esc_html_e( 'Start the guided tour', 'acps-alert-popups' ); ?></button>
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
				'content' => '<p>' . esc_html__( 'An alert is a Beaver Builder popup this plugin switches on and aims. You design it in Beaver Builder; you decide when, where and who sees it here.', 'acps-alert-popups' ) . '</p>'
					. '<p>' . esc_html__( 'Nothing you do in Beaver Builder makes an alert live. That switch is always on the alert settings screen.', 'acps-alert-popups' ) . '</p>',
			)
		);

		if ( in_array( $key, array( 'edit', 'popup' ), true ) ) {
			$screen->add_help_tab(
				array(
					'id'      => 'acps-alerts-settings-help',
					'title'   => __( 'The settings', 'acps-alert-popups' ),
					'content' => '<p><strong>' . esc_html__( 'Status', 'acps-alert-popups' ) . '</strong> — ' . esc_html__( 'live or not, colour, and which alert wins when two qualify.', 'acps-alert-popups' ) . '</p>'
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

		// An optional file, so never require it blindly.
		if ( ! is_readable( $view ) ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Help &amp; Tutorials', 'acps-alert-popups' ) . '</h1>';
			echo '<div class="notice notice-warning"><p>'
				. esc_html__( 'The help pages are not installed. Re-upload the plugin to get them back — alerts themselves are unaffected.', 'acps-alert-popups' )
				. '</p></div></div>';

			return;
		}

		require $view;
	}
}
