<?php
/**
 * wp-admin settings UI (spec Section 6). Single-site only — registered under the
 * Settings menu, never Network Admin.
 *
 * @package ExternalPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin controller: menu, action handling, and tabbed screens.
 */
class EXP_Admin {

	const CAP  = 'manage_options';
	const PAGE = 'external-portal';

	/**
	 * Hook up.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_init', array( $this, 'handle_row_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Handle nonce-protected per-user row-action links (GET).
	 */
	public function handle_row_actions() {
		if ( empty( $_GET['exp_row'] ) || empty( $_GET['user'] ) ) {
			return;
		}
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$id = (int) $_GET['user'];
		check_admin_referer( 'exp_row_' . $id );

		// Reuse the single-user operation logic.
		$_POST['user_id'] = $id;
		$_POST['op']      = sanitize_key( wp_unslash( $_GET['exp_row'] ) );
		$res              = $this->do_user_single();

		$url = $this->url( array( 'tab' => 'users' ) );
		if ( ! empty( $res['notices'] ) ) {
			$url = add_query_arg( 'exp_msg', EXP_Notices::set( $res['notices'] ), $url );
		}
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Add the Settings sub-page, with a pending-queue count bubble.
	 */
	public function add_menu() {
		$pending = EXP_Queue::pending_count();
		$title   = __( 'External Portal', 'external-portal' );
		$menu    = $title;
		if ( $pending > 0 ) {
			$menu .= ' <span class="awaiting-mod"><span class="pending-count">' . (int) $pending . '</span></span>';
		}
		add_options_page( $title, $menu, self::CAP, self::PAGE, array( $this, 'render' ) );
	}

	/**
	 * Enqueue admin CSS on our screen only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( $hook ) {
		if ( 'settings_page_' . self::PAGE !== $hook ) {
			return;
		}
		wp_enqueue_style( 'external-portal-admin', EXP_PLUGIN_URL . 'assets/css/admin.css', array(), EXP_VERSION );
	}

	/**
	 * The tabs.
	 *
	 * @return array<string,string>
	 */
	protected function tabs() {
		return array(
			'users'       => __( 'Users', 'external-portal' ),
			'permissions' => __( 'Permissions', 'external-portal' ),
			'queue'       => __( 'Content Queue', 'external-portal' ),
			'google'      => __( 'Google Integration', 'external-portal' ),
			'extensions'  => __( 'Extensions', 'external-portal' ),
			'settings'    => __( 'Settings', 'external-portal' ),
			'audit'       => __( 'Audit Log', 'external-portal' ),
		);
	}

	/**
	 * Current tab.
	 *
	 * @return string
	 */
	protected function current_tab() {
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'users'; // phpcs:ignore WordPress.Security.NonceVerification
		$tabs = $this->tabs();
		return isset( $tabs[ $tab ] ) ? $tab : 'users';
	}

	/**
	 * Build an admin URL for the plugin page.
	 *
	 * @param array $args Extra query args.
	 * @return string
	 */
	protected function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'options-general.php' ) );
	}

	/**
	 * Render the whole page.
	 */
	public function render() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'external-portal' ) );
		}
		// Ensure registrations have loaded so capability/extension lists are complete.
		EXP_Registry::instance()->load();

		$tab = $this->current_tab();

		echo '<div class="wrap exp-admin">';
		echo '<h1>' . esc_html__( 'External Portal', 'external-portal' ) . '</h1>';

		$this->render_admin_notices();
		$this->render_tab_nav( $tab );

		switch ( $tab ) {
			case 'permissions':
				$this->render_permissions();
				break;
			case 'queue':
				$this->render_queue();
				break;
			case 'google':
				$this->render_google();
				break;
			case 'extensions':
				$this->render_extensions();
				break;
			case 'settings':
				$this->render_settings();
				break;
			case 'audit':
				$this->render_audit();
				break;
			case 'users':
			default:
				$this->render_users();
		}

		echo '</div>';
	}

	/**
	 * Notices from a redirect token.
	 */
	protected function render_admin_notices() {
		foreach ( EXP_Notices::from_request() as $n ) {
			$class = 'notice notice-' . ( in_array( $n['type'], array( 'error', 'success', 'warning', 'info' ), true ) ? ( 'error' === $n['type'] ? 'error' : $n['type'] ) : 'info' );
			echo '<div class="' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $n['text'] ) . '</p></div>';
		}
	}

	/**
	 * Tab navigation.
	 *
	 * @param string $current Current tab.
	 */
	protected function render_tab_nav( $current ) {
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Portal settings sections', 'external-portal' ) . '">';
		foreach ( $this->tabs() as $slug => $label ) {
			$class = 'nav-tab' . ( $slug === $current ? ' nav-tab-active' : '' );
			$badge = '';
			if ( 'queue' === $slug ) {
				$pending = EXP_Queue::pending_count();
				if ( $pending ) {
					$badge = ' (' . (int) $pending . ')';
				}
			}
			printf(
				'<a class="%1$s" href="%2$s"%3$s>%4$s%5$s</a>',
				esc_attr( $class ),
				esc_url( $this->url( array( 'tab' => $slug ) ) ),
				$slug === $current ? ' aria-current="page"' : '',
				esc_html( $label ),
				esc_html( $badge )
			);
		}
		echo '</nav>';
	}

	// =====================================================================
	// Action handling.
	// =====================================================================

	/**
	 * Process all admin POST actions (nonce-protected), then redirect (PRG).
	 */
	public function handle_actions() {
		if ( empty( $_POST['exp_admin_action'] ) ) {
			return;
		}
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		check_admin_referer( 'exp_admin' );

		$action  = sanitize_key( wp_unslash( $_POST['exp_admin_action'] ) );
		$tab     = isset( $_POST['exp_tab'] ) ? sanitize_key( wp_unslash( $_POST['exp_tab'] ) ) : 'users';
		$notices = array();
		$extra   = array( 'tab' => $tab );

		switch ( $action ) {
			case 'create_user':
				$notices = $this->do_create_user();
				break;
			case 'user_bulk':
				$notices = $this->do_user_bulk();
				break;
			case 'user_single':
				$res     = $this->do_user_single();
				$notices = $res['notices'];
				break;
			case 'save_permissions':
				$res            = $this->do_save_permissions();
				$notices        = $res['notices'];
				$extra['user']  = $res['user_id'];
				break;
			case 'apply_preset':
				$res            = $this->do_apply_preset();
				$notices        = $res['notices'];
				$extra['user']  = $res['user_id'];
				break;
			case 'revoke_cap_everywhere':
				$notices = $this->do_revoke_cap_everywhere();
				break;
			case 'queue_review':
				$notices = $this->do_queue_review();
				break;
			case 'save_google':
				$notices = $this->do_save_google();
				break;
			case 'test_google':
				$notices = $this->do_test_google();
				break;
			case 'save_extensions':
				$notices = $this->do_save_extensions();
				break;
			case 'save_settings':
				$notices = $this->do_save_settings();
				break;
		}

		$url = $this->url( $extra );
		if ( ! empty( $notices ) ) {
			$url = add_query_arg( 'exp_msg', EXP_Notices::set( $notices ), $url );
		}
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Create a portal user.
	 *
	 * @return array Notices.
	 */
	protected function do_create_user() {
		$res = EXP_Users::create(
			array(
				'email'        => isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '',
				'display_name' => isset( $_POST['display_name'] ) ? sanitize_text_field( wp_unslash( $_POST['display_name'] ) ) : '',
				'auth_mode'    => isset( $_POST['auth_mode'] ) ? sanitize_key( wp_unslash( $_POST['auth_mode'] ) ) : 'otp',
				'status'       => 'invited',
			)
		);
		if ( is_wp_error( $res ) ) {
			return array( array( 'type' => 'error', 'text' => $res->get_error_message() ) );
		}
		return array( array( 'type' => 'success', 'text' => __( 'Portal account created.', 'external-portal' ) ) );
	}

	/**
	 * Bulk user actions.
	 *
	 * @return array Notices.
	 */
	protected function do_user_bulk() {
		$ids    = isset( $_POST['user_ids'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['user_ids'] ) ) : array();
		$bulk   = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
		$ids    = array_filter( $ids );
		if ( empty( $ids ) || '' === $bulk ) {
			return array( array( 'type' => 'error', 'text' => __( 'Select users and an action.', 'external-portal' ) ) );
		}
		$n = 0;
		foreach ( $ids as $id ) {
			switch ( $bulk ) {
				case 'disable':
					EXP_Users::update( $id, array( 'status' => EXP_Users::STATUS_DISABLED ) );
					EXP_Session::revoke_all_for_user( $id );
					$n++;
					break;
				case 'enable':
					EXP_Users::update( $id, array( 'status' => EXP_Users::STATUS_ACTIVE ) );
					$n++;
					break;
				case 'revoke_sessions':
					EXP_Session::revoke_all_for_user( $id );
					$n++;
					break;
				case 'delete':
					EXP_Users::delete( $id );
					$n++;
					break;
			}
		}
		return array( array( 'type' => 'success', 'text' => sprintf( /* translators: %d: count */ __( 'Bulk action applied to %d user(s).', 'external-portal' ), $n ) ) );
	}

	/**
	 * Single-user row actions.
	 *
	 * @return array{notices:array}
	 */
	protected function do_user_single() {
		$id  = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
		$op  = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$msg = __( 'Done.', 'external-portal' );

		switch ( $op ) {
			case 'disable':
				EXP_Users::update( $id, array( 'status' => EXP_Users::STATUS_DISABLED ) );
				EXP_Session::revoke_all_for_user( $id );
				$msg = __( 'User disabled and sessions revoked.', 'external-portal' );
				break;
			case 'enable':
				EXP_Users::update( $id, array( 'status' => EXP_Users::STATUS_ACTIVE ) );
				$msg = __( 'User enabled.', 'external-portal' );
				break;
			case 'unlock':
				EXP_Users::update( $id, array( 'failed_logins' => 0, 'locked_until' => null ) );
				$msg = __( 'Account unlocked.', 'external-portal' );
				break;
			case 'revoke_sessions':
				EXP_Session::revoke_all_for_user( $id );
				$msg = __( 'Sessions revoked.', 'external-portal' );
				break;
			case 'delete':
				EXP_Users::delete( $id );
				$msg = __( 'User deleted.', 'external-portal' );
				break;
		}
		return array( 'notices' => array( array( 'type' => 'success', 'text' => $msg ) ) );
	}

	/**
	 * Save the full permission set for one user.
	 *
	 * @return array{notices:array,user_id:int}
	 */
	protected function do_save_permissions() {
		$user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
		if ( ! $user_id || ! EXP_Users::get( $user_id ) ) {
			return array( 'notices' => array( array( 'type' => 'error', 'text' => __( 'Unknown user.', 'external-portal' ) ) ), 'user_id' => 0 );
		}

		$submitted = isset( $_POST['grants'] ) && is_array( $_POST['grants'] ) ? wp_unslash( $_POST['grants'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		foreach ( EXP_Registry::instance()->capabilities() as $key => $def ) {
			if ( 'none' === $def['target_type'] ) {
				// Single checkbox: present => grant '' target, absent => revoke.
				$on = ! empty( $submitted[ $key ] );
				if ( $on ) {
					EXP_Permissions::grant( $user_id, $key, '' );
				} else {
					EXP_Permissions::revoke( $user_id, $key, '' );
				}
			} else {
				$targets = isset( $submitted[ $key ] ) ? array_map( 'sanitize_text_field', (array) $submitted[ $key ] ) : array();
				EXP_Permissions::set_targets( $user_id, $key, $targets );
			}
		}
		return array( 'notices' => array( array( 'type' => 'success', 'text' => __( 'Permissions saved.', 'external-portal' ) ) ), 'user_id' => $user_id );
	}

	/**
	 * Apply a permission preset/bundle to a user.
	 *
	 * @return array{notices:array,user_id:int}
	 */
	protected function do_apply_preset() {
		$user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
		$preset  = isset( $_POST['preset'] ) ? sanitize_key( wp_unslash( $_POST['preset'] ) ) : '';
		$presets = self::presets();

		if ( ! $user_id || ! isset( $presets[ $preset ] ) ) {
			return array( 'notices' => array( array( 'type' => 'error', 'text' => __( 'Unknown preset or user.', 'external-portal' ) ) ), 'user_id' => $user_id );
		}
		foreach ( $presets[ $preset ]['grants'] as $g ) {
			EXP_Permissions::grant( $user_id, $g['capability'], isset( $g['target'] ) ? (string) $g['target'] : '' );
		}
		return array(
			'notices' => array( array( 'type' => 'success', 'text' => sprintf( /* translators: %s: preset */ __( 'Applied preset: %s', 'external-portal' ), $presets[ $preset ]['label'] ) ) ),
			'user_id' => $user_id,
		);
	}

	/**
	 * Bulk-revoke a capability across all users.
	 *
	 * @return array Notices.
	 */
	protected function do_revoke_cap_everywhere() {
		$cap = isset( $_POST['capability'] ) ? sanitize_key( wp_unslash( $_POST['capability'] ) ) : '';
		if ( '' === $cap ) {
			return array( array( 'type' => 'error', 'text' => __( 'Choose a capability.', 'external-portal' ) ) );
		}
		$n = EXP_Permissions::revoke_capability_everywhere( $cap );
		return array( array( 'type' => 'success', 'text' => sprintf( /* translators: %d: count */ __( 'Revoked %d grant(s).', 'external-portal' ), $n ) ) );
	}

	/**
	 * Approve/reject a queue item.
	 *
	 * @return array Notices.
	 */
	protected function do_queue_review() {
		$id    = isset( $_POST['queue_id'] ) ? (int) $_POST['queue_id'] : 0;
		$op    = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$notes = isset( $_POST['admin_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['admin_notes'] ) ) : '';

		$res = ( 'approve' === $op ) ? EXP_Queue::approve( $id, $notes ) : EXP_Queue::reject( $id, $notes );
		if ( is_wp_error( $res ) ) {
			return array( array( 'type' => 'error', 'text' => $res->get_error_message() ) );
		}
		return array( array( 'type' => 'success', 'text' => ( 'approve' === $op ? __( 'Item approved and applied.', 'external-portal' ) : __( 'Item rejected.', 'external-portal' ) ) ) );
	}

	/**
	 * Save Google integration settings.
	 *
	 * @return array Notices.
	 */
	protected function do_save_google() {
		$json = isset( $_POST['service_account'] ) ? trim( (string) wp_unslash( $_POST['service_account'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$update = array(
			'google_impersonate_user' => isset( $_POST['impersonate'] ) ? sanitize_email( wp_unslash( $_POST['impersonate'] ) ) : '',
		);

		if ( '' !== $json ) {
			$decoded = json_decode( $json, true );
			if ( ! is_array( $decoded ) || empty( $decoded['client_email'] ) || empty( $decoded['private_key'] ) ) {
				return array( array( 'type' => 'error', 'text' => __( 'That does not look like a valid service account JSON.', 'external-portal' ) ) );
			}
			// Stored base64-encoded (light obfuscation at rest; still treat DB as sensitive).
			$update['google_service_account'] = base64_encode( $json );
		}

		// Whitelist rows.
		$ids    = isset( $_POST['cal_id'] ) ? (array) wp_unslash( $_POST['cal_id'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$labels = isset( $_POST['cal_label'] ) ? (array) wp_unslash( $_POST['cal_label'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$whitelist = array();
		foreach ( $ids as $i => $cid ) {
			$cid = sanitize_text_field( $cid );
			if ( '' === $cid ) {
				continue;
			}
			$whitelist[] = array(
				'id'    => $cid,
				'label' => isset( $labels[ $i ] ) ? sanitize_text_field( $labels[ $i ] ) : '',
			);
		}
		$update['google_calendar_whitelist'] = $whitelist;

		EXP_Settings::update( $update );
		return array( array( 'type' => 'success', 'text' => __( 'Google settings saved.', 'external-portal' ) ) );
	}

	/**
	 * Test the Google connection.
	 *
	 * @return array Notices.
	 */
	protected function do_test_google() {
		$client = EXP_Google_Calendar_Client::from_settings();
		if ( is_wp_error( $client ) ) {
			return array( array( 'type' => 'error', 'text' => $client->get_error_message() ) );
		}
		$token = $client->access_token();
		if ( is_wp_error( $token ) ) {
			return array( array( 'type' => 'error', 'text' => $token->get_error_message() ) );
		}
		return array( array( 'type' => 'success', 'text' => __( 'Connection succeeded — an access token was obtained.', 'external-portal' ) ) );
	}

	/**
	 * Approve/unapprove extension menu items.
	 *
	 * @return array Notices.
	 */
	protected function do_save_extensions() {
		$approved = isset( $_POST['approved'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['approved'] ) ) : array();
		$registry = EXP_Registry::instance();
		foreach ( $registry->extension_records() as $rec ) {
			$registry->set_extension_approved( $rec->slug, in_array( $rec->slug, $approved, true ) );
		}
		return array( array( 'type' => 'success', 'text' => __( 'Extension approvals updated.', 'external-portal' ) ) );
	}

	/**
	 * Save general settings.
	 *
	 * @return array Notices.
	 */
	protected function do_save_settings() {
		$p = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		EXP_Settings::update(
			array(
				'otp_length'                  => max( 4, min( 10, (int) ( $p['otp_length'] ?? 6 ) ) ),
				'otp_ttl_minutes'             => max( 1, (int) ( $p['otp_ttl_minutes'] ?? 10 ) ),
				'otp_max_attempts'            => max( 1, (int) ( $p['otp_max_attempts'] ?? 5 ) ),
				'session_idle_minutes'        => max( 1, (int) ( $p['session_idle_minutes'] ?? 30 ) ),
				'session_absolute_hours'      => max( 1, (int) ( $p['session_absolute_hours'] ?? 12 ) ),
				'session_warn_seconds'        => max( 0, (int) ( $p['session_warn_seconds'] ?? 120 ) ),
				'login_lockout_threshold'     => max( 1, (int) ( $p['login_lockout_threshold'] ?? 5 ) ),
				'login_lockout_minutes'       => max( 1, (int) ( $p['login_lockout_minutes'] ?? 15 ) ),
				'password_min_length'         => max( 8, (int) ( $p['password_min_length'] ?? 12 ) ),
				'login_page_id'               => (int) ( $p['login_page_id'] ?? 0 ),
				'dashboard_page_id'           => (int) ( $p['dashboard_page_id'] ?? 0 ),
				'admin_notify_email'          => sanitize_email( $p['admin_notify_email'] ?? '' ),
				'email_from_name'             => sanitize_text_field( $p['email_from_name'] ?? '' ),
				'notify_on_new_queue_item'    => empty( $p['notify_on_new_queue_item'] ) ? 0 : 1,
				'calendar_requires_approval'  => empty( $p['calendar_requires_approval'] ) ? 0 : 1,
				'extensions_require_approval' => empty( $p['extensions_require_approval'] ) ? 0 : 1,
			)
		);
		return array( array( 'type' => 'success', 'text' => __( 'Settings saved.', 'external-portal' ) ) );
	}

	// =====================================================================
	// Presets (spec Section 6 — reusable permission bundles).
	// =====================================================================

	/**
	 * Permission presets. Extendable via the `exp_permission_presets` filter.
	 *
	 * @return array<string,array>
	 */
	public static function presets() {
		$presets = array(
			'general_contributor' => array(
				'label'  => __( 'General Contributor', 'external-portal' ),
				'grants' => array(
					array( 'capability' => EXP_Module_General_Submission::CAP, 'target' => '' ),
				),
			),
		);
		/**
		 * Filter the permission presets.
		 *
		 * @param array $presets Presets keyed by slug.
		 */
		return apply_filters( 'exp_permission_presets', $presets );
	}

	// =====================================================================
	// Screens (require these files to keep this class readable).
	// =====================================================================

	/**
	 * Render helper: include a view file with $this in scope.
	 *
	 * @param string $view View basename (without extension).
	 */
	protected function view( $view ) {
		$file = EXP_PLUGIN_DIR . 'admin/views/' . $view . '.php';
		if ( is_readable( $file ) ) {
			include $file;
		}
	}

	/** Render the Users screen. */
	protected function render_users() {
		$this->view( 'users' );
	}
	/** Render the Permissions screen. */
	protected function render_permissions() {
		$this->view( 'permissions' );
	}
	/** Render the Queue screen. */
	protected function render_queue() {
		$this->view( 'queue' );
	}
	/** Render the Google screen. */
	protected function render_google() {
		$this->view( 'google' );
	}
	/** Render the Extensions screen. */
	protected function render_extensions() {
		$this->view( 'extensions' );
	}
	/** Render the Settings screen. */
	protected function render_settings() {
		$this->view( 'settings' );
	}
	/** Render the Audit screen. */
	protected function render_audit() {
		$this->view( 'audit' );
	}

	/**
	 * Shared nonce + tab hidden fields for admin forms.
	 *
	 * @param string $action Value for exp_admin_action.
	 * @param string $tab    Current tab.
	 * @return string
	 */
	public function form_fields( $action, $tab ) {
		return wp_nonce_field( 'exp_admin', '_wpnonce', true, false )
			. '<input type="hidden" name="exp_admin_action" value="' . esc_attr( $action ) . '" />'
			. '<input type="hidden" name="exp_tab" value="' . esc_attr( $tab ) . '" />';
	}

	/**
	 * Expose the page URL builder to views.
	 *
	 * @param array $args Query args.
	 * @return string
	 */
	public function page_url( array $args = array() ) {
		return $this->url( $args );
	}
}
