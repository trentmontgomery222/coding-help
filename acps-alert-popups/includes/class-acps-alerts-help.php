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

			if ( 'edit' === $view ) {
				return 'edit';
			}

			if ( 'post' === $view ) {
				return 'post';
			}

			return 'list';
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
			wp_enqueue_style( 'acps-alerts-tour', ACPS_ALERTS_URL . 'assets/css/tour.css', array(), ACPS_Alerts_Failsafe::asset_version( 'assets/css/tour.css' ) );
		}

		if ( 'help' === $key && ACPS_Alerts_Failsafe::has_file( 'assets/css/help.css' ) ) {
			wp_enqueue_style( 'acps-alerts-help', ACPS_ALERTS_URL . 'assets/css/help.css', array( 'acps-alerts-tour' ), ACPS_Alerts_Failsafe::asset_version( 'assets/css/help.css' ) );
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
			wp_enqueue_script( 'acps-alerts-help', ACPS_ALERTS_URL . 'assets/js/help.js', array( 'acps-alerts-tour' ), ACPS_Alerts_Failsafe::asset_version( 'assets/js/help.js' ), true );
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
		$post_url  = add_query_arg(
			array(
				'page'      => ACPS_Alerts_Admin::MENU_SLUG,
				'acps_view' => 'post',
			),
			admin_url( 'admin.php' )
		);
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

		/* ---- Tour 1: posting an alert, the everyday way ---- */
		$steps = array(
			array(
				'title' => __( 'Welcome — posting an alert takes under a minute', 'acps-alert-popups' ),
				'html'  => '<p>' . esc_html__( 'This site has exactly two alerts, and always will: the Normal Alert, which is the resting state, and the Current Alert, which is the one you switch on when something is happening. You never create or delete either one.', 'acps-alert-popups' ) . '</p>'
					. '<p>' . esc_html__( 'To post an update you change the Current Alert. There are two ways, and this tour shows the quick one: the Post an Alert form, right here in wp-admin — pick a level, type a header and a message, and press one button.', 'acps-alert-popups' ) . '</p>'
					. '<p class="acps-tour-tip">' . esc_html__( 'Leave any time with the Escape key, and pick the tour up again from Help & Tutorials.', 'acps-alert-popups' ) . '</p>',
			),
			array(
				'screen'    => 'list',
				'url'       => $list_url,
				'selector'  => '.acps-alerts-table, .wrap h1',
				'title'     => __( 'This is your alerts list', 'acps-alert-popups' ),
				'html'      => '<p>' . esc_html__( 'Two rows, always: the Current Alert and the Normal Alert. They are created for you and cannot be deleted, so this list never grows.', 'acps-alert-popups' ) . '</p>'
					. '<p>' . esc_html__( 'The Status column tells you the truth: "Live" means visitors are seeing it now, "On, not showing" means it is switched on but outside its schedule, and "Off" means nobody sees it.', 'acps-alert-popups' ) . '</p>',
				'placement' => 'auto',
			),
			array(
				'screen'   => 'list',
				'selector' => '.page-title-action, .wrap h1',
				'title'    => __( 'The fast way in: Post an Alert', 'acps-alert-popups' ),
				'html'     => '<p>' . esc_html__( 'This button opens the quick form. There is one on this list and a matching item in the Site Alerts menu — either one takes you to the same place.', 'acps-alert-popups' ) . '</p>',
			),
			array(
				'screen'   => 'post',
				'url'      => $post_url,
				'goLabel'  => __( 'Open the form with me', 'acps-alert-popups' ),
				'selector' => '.acps-post-form, .wrap h1',
				'title'    => __( 'Three fields, one button', 'acps-alert-popups' ),
				'html'     => '<p>' . esc_html__( 'Pick the level, type the header and the message, and press Post alert. In one step that writes the header and text straight into the popup, sets the level, and switches the alert on.', 'acps-alert-popups' ) . '</p>'
					. '<p>' . esc_html__( 'The boxes come pre-filled with what the popup says now, so a small change is a small edit, and an empty box leaves that piece alone.', 'acps-alert-popups' ) . '</p>'
					. '<p class="acps-tour-tip">' . esc_html__( 'Changing the wording of an alert that is already up does not restart its daily cut-off.', 'acps-alert-popups' ) . '</p>',
			),
			array(
				'screen' => 'post',
				'title'  => __( 'When you want to change more than the words', 'acps-alert-popups' ),
				'html'   => '<p>' . esc_html__( 'The form changes the level, header and text. Everything else about the popup — extra content, images, buttons, styling, and where and how it appears — lives on the Current Alert popup on your status page, edited in Beaver Builder.', 'acps-alert-popups' ) . '</p>'
					. '<p>' . esc_html__( 'The form and the builder edit the same popup, so you never end up with two. Use the form for the everyday update; open the builder when you want to change how the popup is built.', 'acps-alert-popups' ) . '</p>',
			),
			array(
				'screen'   => 'help',
				'url'      => $help_url,
				'goLabel'  => __( 'Show me the guides', 'acps-alert-popups' ),
				'selector' => '.acps-help-checklist',
				'title'    => __( 'That is the whole everyday job', 'acps-alert-popups' ),
				'html'     => '<p>' . esc_html__( 'This checklist tracks your setup and ticks itself off as you go.', 'acps-alert-popups' ) . '</p>'
					. '<p>' . esc_html__( 'Below it are illustrated guides for the status levels, the status board, the shortcode, scheduling, targeting and frequency — plus troubleshooting for when something does not appear.', 'acps-alert-popups' ) . '</p>',
			),
		);

		$tours['first-alert'] = array(
			'title' => __( 'Post an alert (the quick way)', 'acps-alert-popups' ),
			'steps' => $steps,
		);

		/* ---- Tour 2: the settings screen in detail ---- */
		if ( $edit_url ) {
			$tours['settings-deep'] = array(
				'title' => __( 'The fine-grained settings', 'acps-alert-popups' ),
				'steps' => array(
					array(
						'screen'   => 'edit',
						'url'      => $edit_url,
						'selector' => '[data-acps-section="status"]',
						'title'    => __( 'Status', 'acps-alert-popups' ),
						'html'     => '<p>' . esc_html__( 'This walks the settings on the Normal Alert, where you can see them safely without touching anything live. The Current Alert has the same settings on its popup on the status page.', 'acps-alert-popups' ) . '</p>'
							. '<p>' . esc_html__( 'Status is whether the alert is live. Post an alert or switch it on and off from Site Alerts → Post an Alert; saving the status page never changes it.', 'acps-alert-popups' ) . '</p>',
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
						'html'     => '<p>' . esc_html__( 'Changes take effect immediately. Use the preview link further down to check it on the live site before anyone else sees it.', 'acps-alert-popups' ) . '</p>'
							. '<p class="acps-tour-tip">' . esc_html__( 'Every one of these settings is on the Current Alert module too, so for a real alert you never have to come in here at all.', 'acps-alert-popups' ) . '</p>',
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
