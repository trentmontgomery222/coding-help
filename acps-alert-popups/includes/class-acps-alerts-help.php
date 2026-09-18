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

		$types = ACPS_Alerts_Source::source_post_types();

		if ( in_array( (string) $screen->post_type, $types, true ) && in_array( $screen->base, array( 'post', 'edit' ), true ) ) {
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
				'html'  => '<p>' . esc_html__( 'This site has exactly two alerts, and it always will: the Normal Alert, which is the resting state, and the Current Alert, which is the one you switch on when something is happening.', 'acps-alert-popups' ) . '</p>'
					. '<p>' . esc_html__( 'You never create or delete either of them. You edit the Current Alert from the Status Board on your status page, and design how it looks in Beaver Builder.', 'acps-alert-popups' ) . '</p>'
					. '<p class="acps-tour-tip">' . esc_html__( 'You can leave at any time with the Escape key, and pick the tour up again from Help & Tutorials.', 'acps-alert-popups' ) . '</p>',
			),
			array(
				'screen'   => 'list',
				'url'      => $list_url,
				'selector' => '#toplevel_page_acps-alerts, .acps-alerts-table, .wrap h1',
				'title'    => __( 'This is your alerts list', 'acps-alert-popups' ),
				'html'     => '<p>' . esc_html__( 'Two rows, always: the Current Alert and the Normal Alert. They are created for you and cannot be deleted, so this list never grows.', 'acps-alert-popups' ) . '</p>'
					. '<p>' . esc_html__( 'This screen is for checking state and for the fine-grained settings. The day-to-day writing happens on the status page.', 'acps-alert-popups' ) . '</p>',
				'placement' => 'auto',
			),
			array(
				'screen'   => 'list',
				'selector' => '.acps-alerts-table, .wrap h1',
				'title'    => __( 'Where you actually write an update', 'acps-alert-popups' ),
				'html'     => '<p>' . esc_html__( 'Open your status page in Beaver Builder and edit the School Status Board module. What you type there is the Current Alert.', 'acps-alert-popups' ) . '</p>'
					. '<p>' . esc_html__( 'Editing it changes the update in place — it never creates a second one. Switching it on in that module is what puts it in front of visitors.', 'acps-alert-popups' ) . '</p>'
					. '<p class="acps-tour-tip">' . esc_html__( 'At the daily cut-off the Current Alert files itself into the archive and switches itself off. The words stay, ready to be rewritten next time.', 'acps-alert-popups' ) . '</p>',
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
		// There are only ever two alerts, and the Current Alert is the one
		// people actually work with, so demonstrate on that.
		if ( class_exists( 'ACPS_Alerts_Post_Type' ) ) {
			$current = ACPS_Alerts_Post_Type::get_alert( ACPS_Alerts_Post_Type::ROLE_CURRENT );

			if ( $current ) {
				return (int) $current;
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

		return array(
			array(
				'key'   => 'type',
				'done'  => '' !== ACPS_Alerts_Source::post_type(),
				'label' => __( 'The plugin is ready', 'acps-alert-popups' ),
				'why'   => __( 'Its alert post type is registered, so alerts can be created and saved.', 'acps-alert-popups' ),
				'fix'   => __( 'Deactivate and reactivate the plugin.', 'acps-alert-popups' ),
				'url'   => admin_url( 'plugins.php' ),
				'cta'   => __( 'Open Plugins', 'acps-alert-popups' ),
			),
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
				'label' => __( 'The Status Board is on a page', 'acps-alert-popups' ),
				'why'   => __( 'Drop the "School Status Board" module onto your status page. After that you post every update from there.', 'acps-alert-popups' ),
				'fix'   => __( 'Edit your status page in Beaver Builder and add the module from the Site Alerts group.', 'acps-alert-popups' ),
				'url'   => admin_url( 'edit.php?post_type=page' ),
				'cta'   => __( 'Open Pages', 'acps-alert-popups' ),
			),
			array(
				'key'   => 'alerts',
				'done'  => count( $popups ) >= 2,
				'label' => __( 'The two alerts exist', 'acps-alert-popups' ),
				'why'   => __( 'This site has exactly two: the Current Alert you switch on and edit, and the Normal Alert that is the resting state. They are created for you.', 'acps-alert-popups' ),
				'fix'   => __( 'Deactivate and reactivate the plugin to create them.', 'acps-alert-popups' ),
				'url'   => admin_url( 'plugins.php' ),
				'cta'   => __( 'Open Plugins', 'acps-alert-popups' ),
			),
			array(
				'key'   => 'used',
				'done'  => $enabled > 0 || (int) get_option( 'acps_alerts_used_once', 0 ) > 0,
				'label' => __( 'You have used the Current Alert once', 'acps-alert-popups' ),
				'why'   => __( 'Worth doing on a quiet day, so the whole path is proven before you need it in a hurry.', 'acps-alert-popups' ),
				'fix'   => __( 'Open the Status Board on your status page, write something, switch it on, then switch it off again.', 'acps-alert-popups' ),
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
