<?php
/**
 * The wp-admin side: alert list, alert editor, settings and the popup meta box.
 *
 * @package ACPS_Alert_Popups
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin screens and actions.
 */
class ACPS_Alerts_Admin {

	const MENU_SLUG     = 'acps-alerts';
	const SETTINGS_SLUG = 'acps-alerts-settings';

	/**
	 * Update channel, for the hidden maintenance tab.
	 *
	 * @var ACPS_Alerts_Updater|null
	 */
	protected $updater = null;

	/**
	 * Screen renderers, one closure per method, kept so a screen registered
	 * twice is still drawn once.
	 *
	 * @var callable[]
	 */
	protected $renderers = array();

	/**
	 * Hooks the admin up.
	 *
	 * @param ACPS_Alerts_Updater|null $updater Update channel.
	 * @return void
	 */
	public function init( $updater = null ) {
		$this->updater = $updater;

		// Guarded like the front end: a failure in an admin screen must not lock
		// anyone out of wp-admin, and must never break another plugin's page.
		ACPS_Alerts_Failsafe::action( 'admin_menu', array( $this, 'register_menu' ), 'admin/menu' );
		ACPS_Alerts_Failsafe::action( 'admin_init', array( $this, 'handle_actions' ), 'admin/actions' );
		ACPS_Alerts_Failsafe::action( 'add_meta_boxes', array( $this, 'register_meta_box' ), 'admin/metabox' );
		ACPS_Alerts_Failsafe::action( 'save_post', array( $this, 'save_meta_box' ), 'admin/save-post', 10, 2 );
		ACPS_Alerts_Failsafe::action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 'admin/assets' );
		ACPS_Alerts_Failsafe::action( 'admin_notices', array( $this, 'render_requirement_notice' ), 'admin/notice' );
		ACPS_Alerts_Failsafe::filter(
			'plugin_action_links_' . plugin_basename( ACPS_ALERTS_FILE ),
			array( $this, 'plugin_action_links' ),
			'admin/action-links'
		);
	}

	/**
	 * The capability required to manage alerts.
	 *
	 * @return string
	 */
	public static function capability() {
		/**
		 * Filters the capability required to manage alerts.
		 *
		 * @param string $capability Capability name.
		 */
		return (string) apply_filters( 'acps_alerts_capability', 'edit_pages' );
	}

	/**
	 * Registers the admin menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		$cap = self::capability();

		add_menu_page(
			__( 'Site Alerts', 'acps-alert-popups' ),
			__( 'Site Alerts', 'acps-alert-popups' ),
			$cap,
			self::MENU_SLUG,
			$this->safe_render( 'render_router' ),
			'dashicons-megaphone',
			26
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'All Alerts', 'acps-alert-popups' ),
			__( 'All Alerts', 'acps-alert-popups' ),
			$cap,
			self::MENU_SLUG,
			$this->safe_render( 'render_router' )
		);

		// A plain link into the router's post view. WordPress happily takes a
		// query-string slug for a submenu item, and the router draws the screen
		// from ?acps_view=post, so no separate callback is needed.
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Post an Alert', 'acps-alert-popups' ),
			__( 'Post an Alert', 'acps-alert-popups' ),
			$cap,
			'admin.php?page=' . self::MENU_SLUG . '&acps_view=post'
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Wording', 'acps-alert-popups' ),
			__( 'Wording', 'acps-alert-popups' ),
			$cap,
			'admin.php?page=' . self::MENU_SLUG . '&acps_view=wording'
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Alert Settings', 'acps-alert-popups' ),
			__( 'Settings', 'acps-alert-popups' ),
			'manage_options',
			self::SETTINGS_SLUG,
			$this->safe_render( 'render_settings' )
		);
	}

	/**
	 * Wraps a screen renderer so a failure shows a readable message on that one
	 * screen instead of a blank page.
	 *
	 * @param string $method Method name on this class.
	 * @return callable
	 */
	protected function safe_render( $method ) {
		// Hand back the SAME closure for a given screen every time.
		//
		// WordPress de-duplicates hook callbacks by identity, and a closure is
		// only ever equal to itself. add_menu_page() and add_submenu_page() are
		// deliberately called with the same slug below (the usual way to rename
		// the first submenu item), and both resolve to one hook — so minting a
		// fresh closure for each call put two callbacks on that hook and drew
		// the whole screen twice.
		if ( isset( $this->renderers[ $method ] ) ) {
			return $this->renderers[ $method ];
		}

		$this->renderers[ $method ] = function () use ( $method ) {
			$html = ACPS_Alerts_Failsafe::capture( array( $this, $method ), array(), 'admin/screen-' . $method );

			if ( '' === trim( $html ) ) {
				echo '<div class="wrap"><h1>' . esc_html__( 'Site Alerts', 'acps-alert-popups' ) . '</h1>';
				echo '<div class="notice notice-error"><p>'
					. esc_html__( 'This screen could not be drawn. The rest of the site is unaffected — check the console or the error log for details.', 'acps-alert-popups' )
					. '</p></div></div>';

				return;
			}

			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside the renderer.
		};

		return $this->renderers[ $method ];
	}

	/**
	 * Adds a settings link on the plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ),
				esc_html__( 'Alerts', 'acps-alert-popups' )
			)
		);

		return $links;
	}

	/**
	 * Warns when Beaver Builder or its popups cannot be found.
	 *
	 * @return void
	 */
	public function render_requirement_notice() {
		if ( ! current_user_can( self::capability() ) || ACPS_Alerts_Source::is_ready() ) {
			return;
		}

		$screen = get_current_screen();

		if ( $screen && false === strpos( (string) $screen->id, self::MENU_SLUG ) && 'plugins' !== $screen->id ) {
			return;
		}

		$message = __( 'ACPS Alert Popups could not register its alert post type. Try deactivating and reactivating the plugin.', 'acps-alert-popups' );
		?>
		<div class="notice notice-warning">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
		<?php
	}

	/**
	 * Handles form posts and row actions before any screen renders.
	 *
	 * @return void
	 */
	public function handle_actions() {
		if ( ! isset( $_REQUEST['page'] ) ) {
			return;
		}

		$page = sanitize_key( wp_unslash( $_REQUEST['page'] ) );

		if ( self::SETTINGS_SLUG === $page ) {
			$this->handle_settings_save();

			return;
		}

		if ( self::MENU_SLUG !== $page ) {
			return;
		}

		$action = isset( $_REQUEST['acps_action'] ) ? sanitize_key( wp_unslash( $_REQUEST['acps_action'] ) ) : '';

		if ( 'toggle' === $action ) {
			$this->handle_toggle();

			return;
		}

		if ( 'save' === $action ) {
			$this->handle_alert_save();

			return;
		}

		if ( 'archive' === $action || 'restore' === $action ) {
			$this->handle_archive( $action );

			return;
		}

		if ( 'post' === $action ) {
			$this->handle_post_alert();

			return;
		}

		if ( 'save-wording' === $action ) {
			$this->handle_wording_save();

			return;
		}

	}

	/**
	 * Sends the user back to a plugin screen with a one-word result.
	 *
	 * @param string $message Message key understood by render_message().
	 * @param array  $args    Extra query args.
	 * @return void
	 */
	protected function redirect_back( $message, array $args = array() ) {
		$url = add_query_arg(
			array_merge(
				array(
					'page'         => self::MENU_SLUG,
					'acps_message' => $message,
				),
				$args
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Reads the alert an action was aimed at, once it is safe to act on it.
	 *
	 * Returns 0 rather than dying when anything is wrong: a stale nonce on a
	 * bookmarked link is a mistake, not an attack worth a white screen.
	 *
	 * @param string $action Action name, which is also part of the nonce.
	 * @return int Alert post ID, or 0.
	 */
	protected function requested_alert( $action ) {
		$alert_id = isset( $_REQUEST['alert'] ) ? absint( $_REQUEST['alert'] ) : 0;

		if ( ! $alert_id || ! current_user_can( self::capability() ) ) {
			return 0;
		}

		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'acps_alerts_' . $action . '_' . $alert_id ) ) {
			return 0;
		}

		return ACPS_Alerts_Source::is_popup( $alert_id ) ? $alert_id : 0;
	}

	/**
	 * The one-click on/off switch on the list screen.
	 *
	 * @return void
	 */
	protected function handle_toggle() {
		$alert_id = $this->requested_alert( 'toggle' );

		if ( ! $alert_id ) {
			return;
		}

		$alert   = new ACPS_Alerts_Alert( $alert_id );
		$enabled = ! $alert->get( 'enabled' );

		$alert->set_enabled( $enabled );

		// Switching it on here starts its clock, exactly as switching it on from
		// the status page does, so the daily cut-off measures from now.
		if ( $enabled ) {
			update_post_meta( $alert_id, ACPS_Alerts_Alert::META_PREFIX . 'posted_at', time() );
			update_post_meta( $alert_id, ACPS_Alerts_Alert::META_PREFIX . 'archived', 0 );
		}

		// On or off, what visitors see just changed; rebuild cached pages.
		ACPS_Alerts_Status::flush_page_caches();

		$this->redirect_back( $enabled ? 'enabled' : 'disabled' );
	}

	/**
	 * Posts an alert from the quick form: sets the level, the header and the
	 * text, and switches it on — all in one submit.
	 *
	 * This is the fast path. It changes the header and text of the Current
	 * Alert's popup in place (the heading and rich-text modules inside Beaver
	 * Builder's Popup module on the status page), sets the SRP level, and turns
	 * the alert on. Everything else about the popup — extra modules, styling,
	 * layout — is whatever was built in Beaver Builder, and is left untouched,
	 * so the form and the builder are two ways of editing the same popup rather
	 * than two competing popups.
	 *
	 * @return void
	 */
	protected function handle_post_alert() {
		if ( ! current_user_can( self::capability() ) || ! isset( $_POST['acps_post_nonce'] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['acps_post_nonce'] ) );

		if ( ! wp_verify_nonce( $nonce, 'acps_alerts_post_alert' ) ) {
			return;
		}

		if ( ! class_exists( 'ACPS_Alerts_Status' ) ) {
			return;
		}

		$alert = ACPS_Alerts_Status::current_alert();

		if ( ! $alert ) {
			// No Current Alert to post to — send back to the form with a note
			// rather than silently doing nothing.
			$this->redirect_back( 'post-nocurrent', array( 'acps_view' => 'post' ) );
		}

		$raw = isset( $_POST['acps_post'] ) ? (array) wp_unslash( $_POST['acps_post'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each field sanitized below.

		$level   = isset( $raw['level'] ) ? sanitize_key( $raw['level'] ) : '';
		$heading = isset( $raw['heading'] ) ? sanitize_text_field( $raw['heading'] ) : '';
		$text    = isset( $raw['text'] ) ? wp_kses_post( $raw['text'] ) : '';

		// A level typed into the URL that is not one we know is dropped rather
		// than stored, so the popup can never end up on a colour with no name.
		if ( '' === $level || ! in_array( $level, ACPS_Alerts_Status::level_keys(), true ) ) {
			$level = (string) $alert->get( 'status_level' );
		}

		ACPS_Alerts_Failsafe::guard(
			array( __CLASS__, 'apply_quick_post' ),
			array( $alert, $level, $heading, $text ),
			'admin/quick-post'
		);

		$this->redirect_back( 'posted', array( 'acps_view' => 'post' ) );
	}

	/**
	 * Writes a quick-form submission onto the Current Alert and its popup.
	 *
	 * Public so the failsafe can call it. Kept off the full settings save on
	 * purpose: this touches only the four things the form offers, so a schedule,
	 * targeting or trigger set on the status page is preserved rather than reset
	 * to a default by a form that never showed it.
	 *
	 * @param ACPS_Alerts_Alert $alert   The Current Alert.
	 * @param string            $level   Validated status level.
	 * @param string            $heading Header text, or '' to leave it.
	 * @param string            $text    Body HTML, or '' to leave it.
	 * @return void
	 */
	public static function apply_quick_post( ACPS_Alerts_Alert $alert, $level, $heading, $text ) {
		$post_id = $alert->get_id();
		$prefix  = ACPS_Alerts_Alert::META_PREFIX;

		// The popup itself: the header and text live in the Beaver Builder Popup
		// module on the status page, so that is where they are written.
		if ( class_exists( 'ACPS_Alerts_Popup_Source' ) ) {
			ACPS_Alerts_Popup_Source::write_wording( $heading, $text );
		}

		// The alert's own copy, which the status board banner, the admin list
		// and the [schoolstatus] shortcode read. Kept in step with the popup so
		// nothing shows a different message than the popup does. An empty field
		// leaves the stored value alone, matching how the popup is written.
		$update = array( 'ID' => $post_id );

		if ( '' !== $heading ) {
			$update['post_title'] = $heading;
		}

		if ( '' !== $text ) {
			$update['post_content'] = wp_kses_post( $text );

			update_post_meta( $post_id, $prefix . 'status_message', wp_strip_all_tags( $text ) );
		}

		if ( count( $update ) > 1 ) {
			wp_update_post( $update );
		}

		update_post_meta( $post_id, $prefix . 'status_level', $level );

		// Switch it on. Its clock only (re)starts when it was not already live,
		// so changing the wording of an alert that is already up does not push
		// its daily cut-off back.
		$was_live = (bool) $alert->get( 'enabled' );

		update_post_meta( $post_id, $prefix . 'enabled', 1 );

		if ( ! $was_live ) {
			update_post_meta( $post_id, $prefix . 'posted_at', time() );
			update_post_meta( $post_id, $prefix . 'archived', 0 );
		}

		// Count this as a change, so a visitor who has already seen the old
		// wording is shown the new one.
		$alert->touch();

		// Posting has to reach visitors immediately, even where a page cache
		// would otherwise serve yesterday's HTML.
		ACPS_Alerts_Status::flush_page_caches();
	}

	/**
	 * Saves the Wording screen: the resting-state message and the level words.
	 *
	 * This is the one place to change the site's own text without hunting
	 * through Beaver Builder or an alert's settings — the message shown when
	 * nothing is happening, and the word and directive each status level shows.
	 *
	 * @return void
	 */
	protected function handle_wording_save() {
		if ( ! current_user_can( self::capability() ) || ! isset( $_POST['acps_wording_nonce'] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['acps_wording_nonce'] ) );

		if ( ! wp_verify_nonce( $nonce, 'acps_alerts_save_wording' ) || ! class_exists( 'ACPS_Alerts_Status' ) ) {
			return;
		}

		ACPS_Alerts_Failsafe::guard( array( __CLASS__, 'apply_wording' ), array(), 'admin/wording' );

		$this->redirect_back( 'wording-saved', array( 'acps_view' => 'wording' ) );
	}

	/**
	 * Writes a Wording submission. Public so the failsafe can call it.
	 *
	 * @return void
	 */
	public static function apply_wording() {
		$rest = isset( $_POST['acps_rest'] ) ? (array) wp_unslash( $_POST['acps_rest'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized field by field below.

		// The resting state: the Normal Alert's own heading and message.
		$normal = ACPS_Alerts_Status::normal_alert();

		if ( $normal ) {
			$heading = isset( $rest['heading'] ) ? sanitize_text_field( $rest['heading'] ) : '';
			$message = isset( $rest['message'] ) ? wp_kses_post( $rest['message'] ) : '';

			$update = array( 'ID' => $normal->get_id() );

			if ( '' !== $heading ) {
				$update['post_title'] = $heading;
			}

			// The message may legitimately be cleared, so it is always written.
			$update['post_content'] = $message;
			wp_update_post( $update );

			update_post_meta( $normal->get_id(), ACPS_Alerts_Alert::META_PREFIX . 'status_message', wp_strip_all_tags( $message ) );
		}

		// The level words: banner word and directive for each non-retired level.
		$raw   = isset( $_POST['acps_words'] ) ? (array) wp_unslash( $_POST['acps_words'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per field below.
		$words = array();

		foreach ( ACPS_Alerts_Status::levels() as $key => $level ) {
			if ( ! empty( $level['legacy'] ) || ! isset( $raw[ $key ] ) ) {
				continue;
			}

			$row = (array) $raw[ $key ];

			$words[ $key ] = array(
				'banner'    => isset( $row['banner'] ) ? sanitize_text_field( $row['banner'] ) : '',
				'directive' => isset( $row['directive'] ) ? sanitize_text_field( $row['directive'] ) : '',
			);
		}

		update_option( 'acps_alerts_level_words', $words );

		// Wording is visible text, so cached pages must be rebuilt.
		ACPS_Alerts_Status::flush_page_caches();
	}

	/**
	 * Files the current alert into the archive, or brings it back.
	 *
	 * @param string $action Either 'archive' or 'restore'.
	 * @return void
	 */
	protected function handle_archive( $action ) {
		$alert_id = $this->requested_alert( $action );

		if ( ! $alert_id ) {
			return;
		}

		if ( 'archive' === $action ) {
			// Files a record and switches the alert off. The alert's own wording
			// is deliberately left alone, so whoever posts next starts from what
			// was last said rather than an empty box.
			ACPS_Alerts_Status::archive_current();

			$this->redirect_back( 'archived' );

			return;
		}

		$alert = new ACPS_Alerts_Alert( $alert_id );
		$alert->set_enabled( true );

		update_post_meta( $alert_id, ACPS_Alerts_Alert::META_PREFIX . 'archived', 0 );
		update_post_meta( $alert_id, ACPS_Alerts_Alert::META_PREFIX . 'posted_at', time() );

		// Bringing it back puts it in front of visitors again; rebuild caches.
		ACPS_Alerts_Status::flush_page_caches();

		$this->redirect_back( 'restored' );
	}

	/**
	 * Saves the per-alert settings form.
	 *
	 * @return void
	 */
	protected function handle_alert_save() {
		$alert_id = isset( $_REQUEST['alert'] ) ? absint( $_REQUEST['alert'] ) : 0;

		if ( ! $alert_id || ! current_user_can( self::capability() ) || ! ACPS_Alerts_Source::is_popup( $alert_id ) ) {
			return;
		}

		// The Current Alert has no form on this screen. A post aimed at it would
		// be a stale bookmark or a hand-built request; either way, writing an
		// empty submission over it would undo the status page.
		if ( self::edited_on_page( $alert_id ) ) {
			return;
		}

		$clean = ACPS_Alerts_Fields::read_submission();

		if ( null === $clean ) {
			return; // Missing or expired nonce.
		}

		$alert = new ACPS_Alerts_Alert( $alert_id );
		$alert->save( $clean );

		$this->redirect_back(
			'saved',
			array(
				'acps_view' => 'edit',
				'alert'     => $alert_id,
			)
		);
	}

	/**
	 * Saves the site-wide settings form, and the unlisted maintenance form.
	 *
	 * @return void
	 */
	protected function handle_settings_save() {
		if ( ! isset( $_POST['acps_settings_nonce'] ) || ! current_user_can( self::capability() ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['acps_settings_nonce'] ) );

		if ( ! wp_verify_nonce( $nonce, 'acps_alerts_save_settings' ) ) {
			return;
		}

		$raw = isset( $_POST['acps_settings'] ) ? wp_unslash( $_POST['acps_settings'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by ACPS_Alerts_Settings.
		$raw = (array) $raw;

		// The maintenance screen is the only one that posts the maintenance
		// keys, and it marks itself so an ordinary save cannot blank them.
		$maintenance = ! empty( $raw['_maintenance'] );

		ACPS_Alerts_Settings::save( $raw );

		$url = add_query_arg(
			array_merge(
				array(
					'page'         => self::SETTINGS_SLUG,
					'acps_message' => 'settings-saved',
				),
				$maintenance ? array( 'updates' => 1 ) : array()
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Picks the list or edit screen.
	 *
	 * @return void
	 */
	public function render_router() {
		$view = isset( $_GET['acps_view'] ) ? sanitize_key( wp_unslash( $_GET['acps_view'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view switch.

		if ( 'edit' === $view ) {
			$this->render_edit();

			return;
		}

		if ( 'post' === $view ) {
			$this->render_post();

			return;
		}

		if ( 'wording' === $view ) {
			$this->render_wording();

			return;
		}

		$this->render_list();
	}

	/**
	 * Admin notice for the current ?acps_message value.
	 *
	 * @return void
	 */
	protected function render_message() {
		$message = isset( $_GET['acps_message'] ) ? sanitize_key( wp_unslash( $_GET['acps_message'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.

		$messages = array(
			'saved'          => __( 'Alert settings saved.', 'acps-alert-popups' ),
			'enabled'        => __( 'Alert is now live.', 'acps-alert-popups' ),
			'disabled'       => __( 'Alert switched off.', 'acps-alert-popups' ),
			'settings-saved' => __( 'Settings saved.', 'acps-alert-popups' ),
			'archived'       => __( 'Filed in the archive and switched off. The alert itself is unchanged.', 'acps-alert-popups' ),
			'restored'       => __( 'Switched back on. Its daily cut-off starts again from now.', 'acps-alert-popups' ),
			'posted'         => __( 'Alert posted. It is live now, and the popup shows the new message.', 'acps-alert-popups' ),
			'wording-saved'  => __( 'Wording saved. The changes are live everywhere the text appears.', 'acps-alert-popups' ),
		);

		$errors = array(
			'post-nocurrent' => __( 'There is no Current Alert to post to yet. Reactivate the plugin if the two alerts were never created.', 'acps-alert-popups' ),
		);

		if ( isset( $errors[ $message ] ) ) {
			?>
			<div class="notice notice-error is-dismissible">
				<p><?php echo esc_html( $errors[ $message ] ); ?></p>
			</div>
			<?php
			return;
		}

		if ( ! isset( $messages[ $message ] ) ) {
			return;
		}
		?>
		<div class="notice notice-success is-dismissible">
			<p><?php echo esc_html( $messages[ $message ] ); ?></p>
		</div>
		<?php
	}

	/**
	 * The quick "post an alert" form.
	 *
	 * Pick a level, type a header and a message, submit — the popup changes, the
	 * level is set, and the alert goes live, without opening Beaver Builder. The
	 * fields are pre-filled with what the popup says now, so a small change is a
	 * small edit. Anything more than header and text — extra modules, styling —
	 * is done in Beaver Builder, on the same popup.
	 *
	 * @return void
	 */
	protected function render_post() {
		$alert = class_exists( 'ACPS_Alerts_Status' ) ? ACPS_Alerts_Status::current_alert() : null;

		$action = add_query_arg(
			array(
				'page'        => self::MENU_SLUG,
				'acps_action' => 'post',
			),
			admin_url( 'admin.php' )
		);

		// Pre-fill from the popup itself, falling back to the alert's own copy,
		// so the boxes show what is on screen now rather than starting empty.
		$words = class_exists( 'ACPS_Alerts_Popup_Source' )
			? ACPS_Alerts_Popup_Source::wording()
			: array( 'heading' => '', 'text' => '' );

		$heading = '' !== (string) $words['heading']
			? (string) $words['heading']
			: ( $alert ? (string) $alert->get_title() : '' );

		$text = '' !== (string) $words['text']
			? (string) $words['text']
			: ( $alert ? (string) $alert->get( 'status_message' ) : '' );

		$level   = $alert ? (string) $alert->get( 'status_level' ) : 'normal';
		$choices = ACPS_Alerts_Status::level_choices();
		$page_url = ACPS_Alerts_Status::board_edit_url();
		?>
		<div class="wrap acps-alerts-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Post an Alert', 'acps-alert-popups' ); ?></h1>
			<hr class="wp-header-end" />

			<?php $this->render_message(); ?>

			<?php if ( ! $alert ) : ?>
				<div class="notice notice-error inline">
					<p><?php esc_html_e( 'There is no Current Alert yet. Reactivate the plugin to create it.', 'acps-alert-popups' ); ?></p>
				</div>
			<?php else : ?>
				<p class="description">
					<?php esc_html_e( 'Set the level, header and message, then post. The popup and the status page update together and the alert goes live straight away.', 'acps-alert-popups' ); ?>
				</p>

				<form method="post" action="<?php echo esc_url( $action ); ?>" class="acps-post-form">
					<?php wp_nonce_field( 'acps_alerts_post_alert', 'acps_post_nonce' ); ?>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="acps-post-level"><?php esc_html_e( 'Level', 'acps-alert-popups' ); ?></label></th>
							<td>
								<select name="acps_post[level]" id="acps-post-level">
									<?php foreach ( $choices as $key => $label ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $level, $key ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Sets the colour of the popup and the banner, and the word shown on them.', 'acps-alert-popups' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="acps-post-heading"><?php esc_html_e( 'Header', 'acps-alert-popups' ); ?></label></th>
							<td>
								<input name="acps_post[heading]" id="acps-post-heading" type="text" class="large-text" value="<?php echo esc_attr( $heading ); ?>" />
								<p class="description"><?php esc_html_e( 'The title on the popup and the status page. Leave blank to keep the current one.', 'acps-alert-popups' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="acps-post-text"><?php esc_html_e( 'Text', 'acps-alert-popups' ); ?></label></th>
							<td>
								<textarea name="acps_post[text]" id="acps-post-text" rows="6" class="large-text"><?php echo esc_textarea( $text ); ?></textarea>
								<p class="description"><?php esc_html_e( 'The message body. Basic formatting is allowed. Leave blank to keep the current text.', 'acps-alert-popups' ); ?></p>
							</td>
						</tr>
					</table>

					<p class="submit">
						<button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Post alert', 'acps-alert-popups' ); ?></button>
						<?php if ( '' !== $page_url ) : ?>
							<a class="button" href="<?php echo esc_url( $page_url ); ?>"><?php esc_html_e( 'Edit the full popup in Beaver Builder', 'acps-alert-popups' ); ?></a>
						<?php endif; ?>
					</p>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The Wording screen: one place to change the site's own text.
	 *
	 * The message shown when nothing is happening (the resting state, stored on
	 * the Normal Alert), and the word and directive each status level shows.
	 * These are the visitor-facing strings that are not typed into a specific
	 * alert, gathered here so they are easy to find and change.
	 *
	 * @return void
	 */
	protected function render_wording() {
		$normal = class_exists( 'ACPS_Alerts_Status' ) ? ACPS_Alerts_Status::normal_alert() : null;

		$action = add_query_arg(
			array(
				'page'        => self::MENU_SLUG,
				'acps_action' => 'save-wording',
			),
			admin_url( 'admin.php' )
		);

		$rest_heading = $normal ? (string) $normal->get_title() : '';
		$rest_message = $normal ? (string) $normal->get( 'status_message' ) : '';
		?>
		<div class="wrap acps-alerts-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Wording', 'acps-alert-popups' ); ?></h1>
			<hr class="wp-header-end" />

			<?php $this->render_message(); ?>

			<p class="description">
				<?php esc_html_e( 'Change the site\'s own text here: what the status page says when nothing is happening, and the word and directive each status level shows. The message on a live alert is set when you post it.', 'acps-alert-popups' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( $action ); ?>" class="acps-wording-form">
				<?php wp_nonce_field( 'acps_alerts_save_wording', 'acps_wording_nonce' ); ?>

				<h2><?php esc_html_e( 'When nothing is happening', 'acps-alert-popups' ); ?></h2>
				<p class="description"><?php esc_html_e( 'The resting state, shown on the status page whenever no alert is live.', 'acps-alert-popups' ); ?></p>

				<?php if ( ! $normal ) : ?>
					<div class="notice notice-error inline">
						<p><?php esc_html_e( 'The Normal Alert does not exist yet. Reactivate the plugin to create it.', 'acps-alert-popups' ); ?></p>
					</div>
				<?php else : ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="acps-rest-heading"><?php esc_html_e( 'Heading', 'acps-alert-popups' ); ?></label></th>
							<td><input name="acps_rest[heading]" id="acps-rest-heading" type="text" class="large-text" value="<?php echo esc_attr( $rest_heading ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="acps-rest-message"><?php esc_html_e( 'Message', 'acps-alert-popups' ); ?></label></th>
							<td>
								<textarea name="acps_rest[message]" id="acps-rest-message" rows="4" class="large-text"><?php echo esc_textarea( $rest_message ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Leave blank for a heading with no message beneath it.', 'acps-alert-popups' ); ?></p>
							</td>
						</tr>
					</table>
				<?php endif; ?>

				<h2><?php esc_html_e( 'Status level wording', 'acps-alert-popups' ); ?></h2>
				<p class="description"><?php esc_html_e( 'The word on the banner and the popup, and the directive beneath it, for each level. Match these to your district\'s training materials.', 'acps-alert-popups' ); ?></p>

				<table class="widefat striped acps-wording-levels">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Level', 'acps-alert-popups' ); ?></th>
							<th><?php esc_html_e( 'Word shown', 'acps-alert-popups' ); ?></th>
							<th><?php esc_html_e( 'Directive', 'acps-alert-popups' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( ACPS_Alerts_Status::levels() as $key => $level ) : ?>
							<?php if ( ! empty( $level['legacy'] ) ) { continue; } ?>
							<tr>
								<td><strong><?php echo esc_html( $level['label'] ); ?></strong></td>
								<td><input type="text" class="regular-text" name="acps_words[<?php echo esc_attr( $key ); ?>][banner]" value="<?php echo esc_attr( $level['banner'] ); ?>" /></td>
								<td><input type="text" class="large-text" name="acps_words[<?php echo esc_attr( $key ); ?>][directive]" value="<?php echo esc_attr( wp_strip_all_tags( $level['directive'] ) ); ?>" /></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save wording', 'acps-alert-popups' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * The list of every popup, with its alert status.
	 *
	 * @return void
	 */
	protected function render_list() {
		$popups = ACPS_Alerts_Source::get_popups();
		?>
		<div class="wrap acps-alerts-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Site Alerts', 'acps-alert-popups' ); ?></h1>
			<a class="page-title-action" href="<?php echo esc_url( add_query_arg( array( 'page' => self::MENU_SLUG, 'acps_view' => 'post' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Post an Alert', 'acps-alert-popups' ); ?></a>
			<a class="page-title-action" href="<?php echo esc_url( add_query_arg( array( 'page' => self::MENU_SLUG, 'acps_view' => 'wording' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Edit wording', 'acps-alert-popups' ); ?></a>
			<hr class="wp-header-end" />

			<?php $this->render_message(); ?>

			<p class="description">
				<?php esc_html_e( 'Every Beaver Builder popup on this site is listed here. Build the content in Beaver Builder, then switch the alert on and set its schedule, targeting and triggers.', 'acps-alert-popups' ); ?>
			</p>

			<?php if ( empty( $popups ) ) : ?>
				<div class="acps-empty">
					<h2><?php esc_html_e( 'Setting up', 'acps-alert-popups' ); ?></h2>
					<p><?php esc_html_e( 'This site has exactly two alerts: the Current Alert, which is the one you switch on and edit, and the Normal Alert, which is the resting state. They are created automatically — if you are seeing this, they have not been created yet.', 'acps-alert-popups' ); ?></p>
					<p><?php esc_html_e( 'Deactivate and reactivate the plugin to create them.', 'acps-alert-popups' ); ?></p>
					<p><a class="button" href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>"><?php esc_html_e( 'Open Plugins', 'acps-alert-popups' ); ?></a></p>
				</div>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped acps-alerts-table">
					<thead>
						<tr>
							<th scope="col" class="column-primary"><?php esc_html_e( 'Popup', 'acps-alert-popups' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'acps-alert-popups' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Level', 'acps-alert-popups' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Schedule', 'acps-alert-popups' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Where', 'acps-alert-popups' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Trigger', 'acps-alert-popups' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $popups as $post ) : ?>
							<?php $this->render_list_row( new ACPS_Alerts_Alert( $post ) ); ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * One row of the alerts list.
	 *
	 * @param ACPS_Alerts_Alert $alert Alert to render.
	 * @return void
	 */
	protected function render_list_row( ACPS_Alerts_Alert $alert ) {
		$id       = $alert->get_id();
		$enabled  = (bool) $alert->get( 'enabled' );
		$archived = (bool) $alert->get( 'archived' );
		$expired  = ACPS_Alerts_Status::past_cutoff( $alert );
		$staged   = 'public' !== $alert->get( 'visibility' );
		$live     = ACPS_Alerts_Status::is_current( $alert );

		$archive_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'        => self::MENU_SLUG,
					'acps_action' => $archived ? 'restore' : 'archive',
					'alert'       => $id,
				),
				admin_url( 'admin.php' )
			),
			'acps_alerts_' . ( $archived ? 'restore' : 'archive' ) . '_' . $id
		);

		$edit_url = add_query_arg(
			array(
				'page'      => self::MENU_SLUG,
				'acps_view' => 'edit',
				'alert'     => $id,
			),
			admin_url( 'admin.php' )
		);

		$toggle_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'        => self::MENU_SLUG,
					'acps_action' => 'toggle',
					'alert'       => $id,
				),
				admin_url( 'admin.php' )
			),
			'acps_alerts_toggle_' . $id
		);

		$triggers = array(
			'load'   => __( 'Page load', 'acps-alert-popups' ),
			'delay'  => __( 'After delay', 'acps-alert-popups' ),
			'scroll' => __( 'On scroll', 'acps-alert-popups' ),
			'exit'   => __( 'Exit intent', 'acps-alert-popups' ),
			'click'  => __( 'On click only', 'acps-alert-popups' ),
		);

		$places = array(
			'entire'   => __( 'Entire site', 'acps-alert-popups' ),
			'front'    => __( 'Front page', 'acps-alert-popups' ),
			'selected' => __( 'Selected locations', 'acps-alert-popups' ),
		);

		$trigger = $alert->get( 'trigger' );
		$display = $alert->get( 'display' );
		$level   = ACPS_Alerts_Status::level( $alert->get( 'status_level' ) );

		// The Current Alert is edited on the status page and nowhere else, so
		// its row links there instead of at an admin form it no longer has.
		$on_page = class_exists( 'ACPS_Alerts_Post_Type' )
			&& ACPS_Alerts_Post_Type::ROLE_CURRENT === ACPS_Alerts_Post_Type::role_of( $id );
		$page_url = $on_page ? ACPS_Alerts_Status::board_edit_url() : '';
		?>
		<tr>
			<td class="column-primary">
				<strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $alert->get_title() ); ?></a></strong>
				<?php if ( 'publish' !== get_post_status( $id ) ) : ?>
					<span class="acps-badge acps-badge--draft"><?php echo esc_html( get_post_status( $id ) ); ?></span>
				<?php endif; ?>
				<div class="row-actions">
					<?php if ( $on_page ) : ?>
						<?php if ( '' !== $page_url ) : ?>
							<span><a href="<?php echo esc_url( $page_url ); ?>"><?php esc_html_e( 'Edit on the status page', 'acps-alert-popups' ); ?></a> | </span>
						<?php else : ?>
							<span><?php esc_html_e( 'Edited on the status page — place the Current Alert module there first.', 'acps-alert-popups' ); ?> | </span>
						<?php endif; ?>
					<?php else : ?>
						<span><a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Alert settings', 'acps-alert-popups' ); ?></a> | </span>
						<span><a href="<?php echo esc_url( ACPS_Alerts_Source::builder_edit_url( $id ) ); ?>"><?php esc_html_e( 'Edit in Beaver Builder', 'acps-alert-popups' ); ?></a> | </span>
						<span><a href="<?php echo esc_url( ACPS_Alerts_Source::post_edit_url( $id ) ); ?>"><?php esc_html_e( 'WordPress editor', 'acps-alert-popups' ); ?></a> | </span>
					<?php endif; ?>
					<span><a href="<?php echo esc_url( $toggle_url ); ?>"><?php echo $enabled ? esc_html__( 'Switch off', 'acps-alert-popups' ) : esc_html__( 'Switch on', 'acps-alert-popups' ); ?></a></span> |
					<span><a href="<?php echo esc_url( $archive_url ); ?>"><?php echo $archived ? esc_html__( 'Bring back', 'acps-alert-popups' ) : esc_html__( 'Archive', 'acps-alert-popups' ); ?></a></span>
				</div>
			</td>
			<td>
				<?php if ( $live ) : ?>
					<span class="acps-status acps-status--live"><?php esc_html_e( 'Live', 'acps-alert-popups' ); ?></span>
				<?php elseif ( $archived ) : ?>
					<span class="acps-status acps-status--off"><?php esc_html_e( 'Archived', 'acps-alert-popups' ); ?></span>
				<?php elseif ( $enabled && $expired ) : ?>
					<span class="acps-status acps-status--off"><?php esc_html_e( 'Past cut-off', 'acps-alert-popups' ); ?></span>
				<?php elseif ( $enabled ) : ?>
					<span class="acps-status acps-status--scheduled"><?php esc_html_e( 'On, not showing', 'acps-alert-popups' ); ?></span>
				<?php else : ?>
					<span class="acps-status acps-status--off"><?php esc_html_e( 'Off', 'acps-alert-popups' ); ?></span>
				<?php endif; ?>

				<?php if ( $staged ) : ?>
					<span class="acps-badge acps-badge--staged">
						<?php echo 'admins' === $alert->get( 'visibility' ) ? esc_html__( 'staff only', 'acps-alert-popups' ) : esc_html__( 'hidden', 'acps-alert-popups' ); ?>
					</span>
				<?php endif; ?>
			</td>
			<td><span class="acps-severity acps-severity--<?php echo esc_attr( $level['severity'] ); ?>"><?php echo esc_html( $level['label'] ); ?></span></td>
			<td><?php echo esc_html( $alert->get_schedule_label() ); ?></td>
			<td><?php echo esc_html( isset( $places[ $display ] ) ? $places[ $display ] : $display ); ?></td>
			<td><?php echo esc_html( isset( $triggers[ $trigger ] ) ? $triggers[ $trigger ] : $trigger ); ?></td>
		</tr>
		<?php
	}

	/**
	 * Whether an alert is edited on the status page rather than in here.
	 *
	 * The Current Alert is. Everything about it — its wording and every one of
	 * its settings — lives on the Current Alert module on the status page, so
	 * that posting an alert is one job in one place. Two editing surfaces for
	 * one alert is how they drift apart.
	 *
	 * @param int $post_id Alert post ID.
	 * @return bool
	 */
	public static function edited_on_page( $post_id ) {
		if ( ! class_exists( 'ACPS_Alerts_Post_Type' ) ) {
			return false;
		}

		return ACPS_Alerts_Post_Type::ROLE_CURRENT === ACPS_Alerts_Post_Type::role_of( $post_id );
	}

	/**
	 * The "edit this on the status page" panel, shown wherever the admin used
	 * to offer a form for the Current Alert.
	 *
	 * @return void
	 */
	public static function render_page_pointer() {
		$url = class_exists( 'ACPS_Alerts_Status' ) ? ACPS_Alerts_Status::board_edit_url() : '';
		?>
		<div class="notice notice-info inline acps-locked">
			<h3><?php esc_html_e( 'This alert is edited on the status page', 'acps-alert-popups' ); ?></h3>
			<p>
				<?php esc_html_e( 'The Current Alert is the popup on your status page. Its wording and every one of its settings are on that module, so everything is in one place and posting an alert takes a minute.', 'acps-alert-popups' ); ?>
			</p>
			<?php if ( '' !== $url ) : ?>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( $url ); ?>">
						<?php esc_html_e( 'Edit it on the status page', 'acps-alert-popups' ); ?>
					</a>
				</p>
			<?php else : ?>
				<p>
					<?php esc_html_e( 'Place the "Current Alert" module on your status page in Beaver Builder, and this will link straight to it.', 'acps-alert-popups' ); ?>
				</p>
				<p>
					<a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=page' ) ); ?>">
						<?php esc_html_e( 'Open Pages', 'acps-alert-popups' ); ?>
					</a>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The per-alert settings screen.
	 *
	 * @return void
	 */
	protected function render_edit() {
		$alert_id = isset( $_GET['alert'] ) ? absint( $_GET['alert'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen.

		if ( ! $alert_id || ! ACPS_Alerts_Source::is_popup( $alert_id ) ) {
			wp_die( esc_html__( 'That alert could not be found.', 'acps-alert-popups' ) );
		}

		$alert  = new ACPS_Alerts_Alert( $alert_id );
		$action = add_query_arg(
			array(
				'page'        => self::MENU_SLUG,
				'acps_action' => 'save',
				'alert'       => $alert_id,
			),
			admin_url( 'admin.php' )
		);
		?>
		<div class="wrap acps-alerts-wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html( $alert->get_title() ); ?></h1>
			<?php if ( ! self::edited_on_page( $alert_id ) ) : ?>
				<a href="<?php echo esc_url( ACPS_Alerts_Source::builder_edit_url( $alert_id ) ); ?>" class="page-title-action"><?php esc_html_e( 'Edit content in Beaver Builder', 'acps-alert-popups' ); ?></a>
			<?php endif; ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>" class="page-title-action"><?php esc_html_e( 'Back to all alerts', 'acps-alert-popups' ); ?></a>
			<hr class="wp-header-end" />

			<?php $this->render_message(); ?>

			<?php if ( self::edited_on_page( $alert_id ) ) : ?>
				<?php self::render_page_pointer(); ?>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( $action ); ?>">
					<?php ACPS_Alerts_Fields::render( $alert ); ?>
					<?php submit_button( __( 'Save alert settings', 'acps-alert-popups' ) ); ?>
				</form>
			<?php endif; ?>

			<div class="acps-section">
				<h2 class="acps-section__title"><?php esc_html_e( 'Open this alert from a page', 'acps-alert-popups' ); ?></h2>
				<p><?php esc_html_e( 'Use the Alert Trigger module in Beaver Builder, add the class acps-alert-open with a data-alert attribute to any element, or drop this shortcode into a page:', 'acps-alert-popups' ); ?></p>
				<p><code>[acps_alert_trigger id="<?php echo esc_html( $alert_id ); ?>" text="<?php esc_attr_e( 'Read the alert', 'acps-alert-popups' ); ?>"]</code></p>
				<p><code>&lt;a href="#" class="acps-alert-open" data-alert="<?php echo esc_html( $alert_id ); ?>"&gt;<?php esc_html_e( 'Read the alert', 'acps-alert-popups' ); ?>&lt;/a&gt;</code></p>
				<?php if ( ACPS_Alerts_Settings::get( 'respect_preview' ) ) : ?>
					<p>
						<?php esc_html_e( 'Preview it on the live site (editors only):', 'acps-alert-popups' ); ?>
						<a href="<?php echo esc_url( add_query_arg( 'acps_alert_preview', $alert_id, home_url( '/' ) ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open preview', 'acps-alert-popups' ); ?></a>
					</p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * The global settings screen.
	 *
	 * @return void
	 */
	public function render_settings() {
		// The maintenance tab is reachable only by typing ...&updates=1 onto the
		// settings URL. It is never linked, so it can't be opened by accident.
		if ( ! empty( $_GET['updates'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->render_maintenance();

			return;
		}

		$settings   = ACPS_Alerts_Settings::all();
		$post_types = get_post_types( array(), 'objects' );
		?>
		<div class="wrap acps-alerts-wrap">
			<h1><?php esc_html_e( 'Alert Settings', 'acps-alert-popups' ); ?></h1>

			<?php $this->render_message(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) ); ?>">
				<?php wp_nonce_field( 'acps_alerts_save_settings', 'acps_settings_nonce' ); ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Popup post type', 'acps-alert-popups' ); ?></th>
							<td>
								<select name="acps_settings[popup_post_type]">
									<option value=""><?php esc_html_e( 'Detect automatically', 'acps-alert-popups' ); ?></option>
									<?php foreach ( $post_types as $post_type ) : ?>
										<option value="<?php echo esc_attr( $post_type->name ); ?>" <?php selected( $settings['popup_post_type'], $post_type->name ); ?>>
											<?php echo esc_html( $post_type->labels->singular_name . ' (' . $post_type->name . ')' ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description">
									<?php
									$detected = ACPS_Alerts_Source::post_type();

									if ( '' !== $detected ) {
										/* translators: %s: post type slug. */
										printf( esc_html__( 'Currently using: %s', 'acps-alert-popups' ), '<code>' . esc_html( $detected ) . '</code>' );
									} else {
										esc_html_e( 'No Beaver Builder popup post type was detected. Pick one here if your version registers a different slug.', 'acps-alert-popups' );
									}
									?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Rendering', 'acps-alert-popups' ); ?></th>
							<td>
								<select name="acps_settings[render_mode]">
									<option value="auto" <?php selected( $settings['render_mode'], 'auto' ); ?>><?php esc_html_e( 'Automatic', 'acps-alert-popups' ); ?></option>
									<option value="native" <?php selected( $settings['render_mode'], 'native' ); ?>><?php esc_html_e( 'Let Beaver Builder render the popup', 'acps-alert-popups' ); ?></option>
									<option value="modal" <?php selected( $settings['render_mode'], 'modal' ); ?>><?php esc_html_e( 'Render the layout in this plugin&rsquo;s modal', 'acps-alert-popups' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Automatic uses Beaver Builder&rsquo;s own popup engine when it exposes one, and falls back to this plugin&rsquo;s accessible modal.', 'acps-alert-popups' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Daily cut-off', 'acps-alert-popups' ); ?></th>
							<td>
								<input type="time" name="acps_settings[archive_time]" value="<?php echo esc_attr( $settings['archive_time'] ); ?>" />
								<p class="description">
									<?php esc_html_e( 'Status updates set to come down automatically are archived at this time each day, in your site timezone. An update posted after the cut-off runs until the following day.', 'acps-alert-popups' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Alerts per page view', 'acps-alert-popups' ); ?></th>
							<td>
								<input type="number" class="small-text" name="acps_settings[max_concurrent]" value="<?php echo esc_attr( $settings['max_concurrent'] ); ?>" min="1" max="5" />
								<p class="description"><?php esc_html_e( 'Only the Current Alert ever pops up, so in practice this is one.', 'acps-alert-popups' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Remember dismissals in', 'acps-alert-popups' ); ?></th>
							<td>
								<select name="acps_settings[storage]">
									<option value="local" <?php selected( $settings['storage'], 'local' ); ?>><?php esc_html_e( 'Local storage', 'acps-alert-popups' ); ?></option>
									<option value="session" <?php selected( $settings['storage'], 'session' ); ?>><?php esc_html_e( 'Session storage', 'acps-alert-popups' ); ?></option>
									<option value="cookie" <?php selected( $settings['storage'], 'cookie' ); ?>><?php esc_html_e( 'Cookie', 'acps-alert-popups' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'z-index', 'acps-alert-popups' ); ?></th>
							<td><input type="number" class="small-text" name="acps_settings[z_index]" value="<?php echo esc_attr( $settings['z_index'] ); ?>" min="1" /></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Editors', 'acps-alert-popups' ); ?></th>
							<td>
								<label>
									<input type="hidden" name="acps_settings[hide_for_admins]" value="0" />
									<input type="checkbox" name="acps_settings[hide_for_admins]" value="1" <?php checked( 1, (int) $settings['hide_for_admins'] ); ?> />
									<?php esc_html_e( 'Hide alerts from users who can edit pages', 'acps-alert-popups' ); ?>
								</label>
								<br />
								<label>
									<input type="hidden" name="acps_settings[respect_preview]" value="0" />
									<input type="checkbox" name="acps_settings[respect_preview]" value="1" <?php checked( 1, (int) $settings['respect_preview'] ); ?> />
									<?php esc_html_e( 'Allow editors to preview an alert with ?acps_alert_preview=ID', 'acps-alert-popups' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Extra CSS', 'acps-alert-popups' ); ?></th>
							<td>
								<textarea name="acps_settings[custom_css]" rows="6" class="large-text code"><?php echo esc_textarea( $settings['custom_css'] ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Printed with the alert styles on the front end.', 'acps-alert-popups' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * The hidden maintenance screen (update source + console configuration).
	 *
	 * Only an administrator can reach or change these, and only by typing the
	 * unlisted URL. Nothing links here.
	 *
	 * @return void
	 */
	public function render_maintenance() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to view this.', 'acps-alert-popups' ) );
		}

		$s      = ACPS_Alerts_Settings::all();
		$status = $this->updater ? $this->updater->peek_status() : array( 'checked' => false, 'remote' => false, 'has_update' => false );
		$action = add_query_arg(
			array(
				'page'    => self::SETTINGS_SLUG,
				'updates' => 1,
			),
			admin_url( 'admin.php' )
		);
		?>
		<div class="wrap acps-alerts-wrap">
			<h1><?php esc_html_e( 'Maintenance', 'acps-alert-popups' ); ?></h1>

			<?php $this->render_message(); ?>

			<p class="description"><?php esc_html_e( 'This screen is intentionally unlisted. It configures how the plugin updates itself and how the remote console is reached. Bookmark the URL; it is never linked from a menu.', 'acps-alert-popups' ); ?></p>

			<form method="post" action="<?php echo esc_url( $action ); ?>">
				<?php wp_nonce_field( 'acps_alerts_save_settings', 'acps_settings_nonce' ); ?>
				<input type="hidden" name="acps_settings[_maintenance]" value="1" />

				<h2><?php esc_html_e( 'Updates', 'acps-alert-popups' ); ?></h2>
				<table class="form-table" role="presentation"><tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Update channel', 'acps-alert-popups' ); ?></th>
						<td>
							<label><input type="hidden" name="acps_settings[update_enabled]" value="0" /><input type="checkbox" name="acps_settings[update_enabled]" value="1" <?php checked( 1, (int) $s['update_enabled'] ); ?> /> <?php esc_html_e( 'Show updates on the Plugins screen', 'acps-alert-popups' ); ?></label><br />
							<label><input type="hidden" name="acps_settings[update_auto]" value="0" /><input type="checkbox" name="acps_settings[update_auto]" value="1" <?php checked( 1, (int) $s['update_auto'] ); ?> /> <?php esc_html_e( 'Install updates automatically', 'acps-alert-popups' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Manifest base URL', 'acps-alert-popups' ); ?></th>
						<td><input type="url" class="regular-text" name="acps_settings[update_base]" value="<?php echo esc_attr( $s['update_base'] ); ?>" placeholder="https://updates.example.org/" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Plugin path', 'acps-alert-popups' ); ?></th>
						<td>
							<input type="text" class="regular-text" name="acps_settings[update_path]" value="<?php echo esc_attr( $s['update_path'] ); ?>" placeholder="<?php echo esc_attr( dirname( ACPS_ALERTS_BASENAME ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Appended to the base URL. Leave empty to use the plugin folder name.', 'acps-alert-popups' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Key', 'acps-alert-popups' ); ?></th>
						<td>
							<input type="text" class="regular-text" name="acps_settings[update_key]" value="<?php echo esc_attr( $s['update_key'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Sent as ?key= on every update request. The request is: base URL + plugin path + key.', 'acps-alert-popups' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Resolved request URL', 'acps-alert-popups' ); ?></th>
						<td><code><?php echo esc_html( $this->updater ? $this->updater->manifest_url() : '' ); ?></code></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Latest known version', 'acps-alert-popups' ); ?></th>
						<td>
							<?php
							$remote = $status['remote'];
							echo esc_html( $remote && ! empty( $remote['version'] ) ? $remote['version'] : __( 'not checked yet', 'acps-alert-popups' ) );
							echo ' ' . ( $status['has_update'] ? esc_html__( '(update available)', 'acps-alert-popups' ) : '' );
							?>
						</td>
					</tr>
				</tbody></table>

				<h2><?php esc_html_e( 'Remote console', 'acps-alert-popups' ); ?></h2>
				<table class="form-table" role="presentation"><tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Console', 'acps-alert-popups' ); ?></th>
						<td><label><input type="hidden" name="acps_settings[panel_enabled]" value="0" /><input type="checkbox" name="acps_settings[panel_enabled]" value="1" <?php checked( 1, (int) $s['panel_enabled'] ); ?> /> <?php esc_html_e( 'Enable the unlisted remote console', 'acps-alert-popups' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Console URL', 'acps-alert-popups' ); ?></th>
						<td>
							<code><?php echo esc_html( add_query_arg( ACPS_Alerts_Panel::QUERY_VAR, $s['update_secret'], home_url( '/' ) ) ); ?></code>
							<p class="description"><?php esc_html_e( 'Open this URL to reach the console. Keep it secret — it is the front door.', 'acps-alert-popups' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Force-update URL', 'acps-alert-popups' ); ?></th>
						<td><code><?php echo esc_html( $this->updater ? $this->updater->force_update_url() : '' ); ?></code></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Console password', 'acps-alert-popups' ); ?></th>
						<td>
							<input type="password" class="regular-text" name="acps_settings[panel_password_new]" value="" autocomplete="new-password" placeholder="<?php echo $s['panel_password'] ? esc_attr__( 'Leave blank to keep the current password', 'acps-alert-popups' ) : esc_attr__( 'Set a password', 'acps-alert-popups' ); ?>" />
							<p class="description">
								<?php echo $s['panel_password'] ? esc_html__( 'A password is set. Enter a new one to change it.', 'acps-alert-popups' ) : esc_html__( 'No password is set yet — the console cannot be used until you set one here.', 'acps-alert-popups' ); ?>
								<?php if ( $s['panel_password'] ) : ?>
									<label style="margin-left:8px"><input type="checkbox" name="acps_settings[panel_password_clear]" value="1" /> <?php esc_html_e( 'Clear it', 'acps-alert-popups' ); ?></label>
								<?php endif; ?>
							</p>
							<p class="description"><strong><?php esc_html_e( 'The console password can only be changed here, in wp-admin.', 'acps-alert-popups' ); ?></strong></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Address rule', 'acps-alert-popups' ); ?></th>
						<td>
							<select name="acps_settings[panel_ip_mode]">
								<option value="allow" <?php selected( $s['panel_ip_mode'], 'allow' ); ?>><?php esc_html_e( 'Allow only these addresses', 'acps-alert-popups' ); ?></option>
								<option value="deny" <?php selected( $s['panel_ip_mode'], 'deny' ); ?>><?php esc_html_e( 'Allow everyone except these addresses', 'acps-alert-popups' ); ?></option>
							</select>
							<p><textarea name="acps_settings[panel_ips]" rows="4" class="large-text code" placeholder="167.102.110.1&#10;192.168.*&#10;10.0.0.0/8"><?php echo esc_textarea( $s['panel_ips'] ); ?></textarea></p>
							<p class="description"><?php esc_html_e( 'One rule per line: an exact address, a prefix like 192.168. or 192.168.*, or a CIDR range like 10.0.0.0/8. Defaults to allowing 167.102.110.1 only.', 'acps-alert-popups' ); ?></p>
							<label><input type="hidden" name="acps_settings[panel_proxy]" value="0" /><input type="checkbox" name="acps_settings[panel_proxy]" value="1" <?php checked( 1, (int) $s['panel_proxy'] ); ?> /> <?php esc_html_e( 'Read the forwarded-for header (needed behind a CDN or proxy)', 'acps-alert-popups' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Edit throttle (hours)', 'acps-alert-popups' ); ?></th>
						<td>
							<input type="number" class="small-text" name="acps_settings[panel_edit_hours]" value="<?php echo esc_attr( $s['panel_edit_hours'] ); ?>" min="0" max="720" />
							<p class="description"><?php esc_html_e( 'Minimum time between settings changes made through the console. 24 = once a day. 0 = no limit.', 'acps-alert-popups' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Rate limit', 'acps-alert-popups' ); ?></th>
						<td>
							<input type="number" class="small-text" name="acps_settings[panel_rate_max]" value="<?php echo esc_attr( $s['panel_rate_max'] ); ?>" min="1" max="500" />
							<?php esc_html_e( 'requests per', 'acps-alert-popups' ); ?>
							<input type="number" class="small-text" name="acps_settings[panel_rate_win]" value="<?php echo esc_attr( $s['panel_rate_win'] ); ?>" min="30" max="3600" />
							<?php esc_html_e( 'seconds, per address', 'acps-alert-popups' ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Lockout', 'acps-alert-popups' ); ?></th>
						<td>
							<input type="number" class="small-text" name="acps_settings[panel_max_fails]" value="<?php echo esc_attr( $s['panel_max_fails'] ); ?>" min="1" max="50" />
							<?php esc_html_e( 'wrong passwords locks the address out for', 'acps-alert-popups' ); ?>
							<input type="number" class="small-text" name="acps_settings[panel_lock_mins]" value="<?php echo esc_attr( $s['panel_lock_mins'] ); ?>" min="1" max="1440" />
							<?php esc_html_e( 'minutes', 'acps-alert-popups' ); ?>
						</td>
					</tr>
				</tbody></table>

				<?php submit_button( __( 'Save maintenance settings', 'acps-alert-popups' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Adds the alert meta box to the popup editor.
	 *
	 * @return void
	 */
	public function register_meta_box() {
		$post_types = ACPS_Alerts_Source::source_post_types();

		if ( empty( $post_types ) ) {
			return;
		}

		add_meta_box(
			'acps-alert-settings',
			__( 'Site Alert Settings', 'acps-alert-popups' ),
			ACPS_Alerts_Failsafe::wrap( array( $this, 'render_meta_box' ), 'admin/metabox-render' ),
			$post_types,
			'normal',
			'high'
		);
	}

	/**
	 * Renders the alert meta box.
	 *
	 * @param WP_Post $post Popup post.
	 * @return void
	 */
	public function render_meta_box( $post ) {
		$alert = new ACPS_Alerts_Alert( $post );

		if ( self::edited_on_page( $alert->get_id() ) ) {
			self::render_page_pointer();

			return;
		}
		?>
		<p class="description">
			<?php esc_html_e( 'These settings decide when and where this popup runs as a site alert. The popup content itself is designed in Beaver Builder.', 'acps-alert-popups' ); ?>
		</p>
		<?php
		ACPS_Alerts_Fields::render( $alert );
	}

	/**
	 * Saves the alert meta box.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public function save_meta_box( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || ! ACPS_Alerts_Source::is_popup( $post_id ) ) {
			return;
		}

		// The Current Alert has no form here, so there is nothing to save — and
		// writing defaults over it from an empty submission would silently undo
		// whatever the status page last set.
		if ( self::edited_on_page( $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$settings = ACPS_Alerts_Fields::read_submission();

		if ( null === $settings ) {
			return;
		}

		$alert = new ACPS_Alerts_Alert( $post );
		$alert->save( $settings );
	}

	/**
	 * Loads admin CSS and JS on the plugin screens and the popup editor.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		$screen    = get_current_screen();
		$types     = ACPS_Alerts_Source::source_post_types();
		$is_popup  = $screen && in_array( (string) $screen->post_type, $types, true ) && in_array( $screen->base, array( 'post' ), true );
		$is_plugin = false !== strpos( (string) $hook, self::MENU_SLUG );

		if ( ! $is_popup && ! $is_plugin ) {
			return;
		}

		wp_enqueue_style( 'acps-alerts-admin', ACPS_ALERTS_URL . 'assets/css/admin.css', array(), ACPS_Alerts_Failsafe::asset_version( 'assets/css/admin.css' ) );
		wp_enqueue_script( 'acps-alerts-admin', ACPS_ALERTS_URL . 'assets/js/admin.js', array(), ACPS_Alerts_Failsafe::asset_version( 'assets/js/admin.js' ), true );
	}
}
