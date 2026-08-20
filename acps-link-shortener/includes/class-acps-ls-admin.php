<?php
/**
 * Admin UI: menu, add/edit form, settings, and action handling.
 *
 * @package ACPS_Link_Shortener
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin controller.
 */
class ACPS_LS_Admin {

	const MENU_SLUG     = 'acps-link-shortener';
	const SETTINGS_SLUG = 'acps-link-shortener-settings';

	/**
	 * Notices to render, [type => [messages]].
	 *
	 * @var array
	 */
	private $notices = array();

	/**
	 * Hook everything.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'maybe_add_dashboard_widget' ) );
	}

	/**
	 * Page URL for the main list screen.
	 *
	 * @return string
	 */
	private function page_url() {
		return admin_url( 'admin.php?page=' . self::MENU_SLUG );
	}

	/**
	 * URL of the settings screen (now under the core Settings menu).
	 *
	 * @return string
	 */
	private function settings_url() {
		return admin_url( 'options-general.php?page=' . self::SETTINGS_SLUG );
	}

	/**
	 * URL of the Link Checker screen.
	 *
	 * @return string
	 */
	private function checker_url() {
		return admin_url( 'admin.php?page=' . self::MENU_SLUG . '-checker' );
	}

	/**
	 * Register the admin menu.
	 *
	 * The top-level "Link Shortener" menu (All Links + Add New Link) stays put.
	 * Settings live under wp-admin -> Settings -> Link Shortener.
	 */
	public function add_menu() {
		$cap = acps_ls_manage_capability();

		add_menu_page(
			__( 'Link Shortener', 'acps-link-shortener' ),
			__( 'Link Shortener', 'acps-link-shortener' ),
			$cap,
			self::MENU_SLUG,
			array( $this, 'render_list_page' ),
			'dashicons-admin-links',
			30
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'All Links', 'acps-link-shortener' ),
			__( 'All Links', 'acps-link-shortener' ),
			$cap,
			self::MENU_SLUG,
			array( $this, 'render_list_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Add New Link', 'acps-link-shortener' ),
			__( 'Add New Link', 'acps-link-shortener' ),
			$cap,
			self::MENU_SLUG . '-add',
			array( $this, 'render_form_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Link Checker', 'acps-link-shortener' ),
			__( 'Link Checker', 'acps-link-shortener' ),
			$cap,
			self::MENU_SLUG . '-checker',
			array( $this, 'render_checker_page' )
		);

		// Settings moved to the core Settings menu.
		add_options_page(
			__( 'Link Shortener', 'acps-link-shortener' ),
			__( 'Link Shortener', 'acps-link-shortener' ),
			$cap,
			self::SETTINGS_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Enqueue admin CSS/JS only on our screens.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, self::MENU_SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'acps-ls-admin',
			ACPS_LS_URL . 'admin/css/admin.css',
			array(),
			ACPS_LS_VERSION
		);

		wp_enqueue_script(
			'acps-ls-admin',
			ACPS_LS_URL . 'admin/js/admin.js',
			array(),
			ACPS_LS_VERSION,
			true
		);

		wp_localize_script(
			'acps-ls-admin',
			'acpsLsL10n',
			array(
				'copied'     => __( 'Short URL copied to clipboard.', 'acps-link-shortener' ),
				'copyFailed' => __( 'Could not copy. Press Ctrl+C or Cmd+C to copy.', 'acps-link-shortener' ),
			)
		);
	}

	/* --------------------------------------------------------------------- */
	/* Action handling                                                        */
	/* --------------------------------------------------------------------- */

	/**
	 * Route non-render actions (save, delete, toggle, settings save).
	 *
	 * Wrapped so an admin action error is logged instead of breaking wp-admin.
	 */
	public function handle_actions() {
		try {
			$this->handle_actions_inner();
		} catch ( Throwable $e ) {
			if ( function_exists( 'acps_ls_log_error' ) ) {
				acps_ls_log_error( 'admin action', $e );
			}
		}
	}

	/**
	 * The actual action router (wrapped by handle_actions()).
	 */
	private function handle_actions_inner() {
		if ( ! isset( $_GET['page'] ) ) {
			return;
		}

		$page = sanitize_key( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 0 !== strpos( $page, self::MENU_SLUG ) ) {
			return;
		}

		if ( ! current_user_can( acps_ls_manage_capability() ) ) {
			return;
		}

		// Save (add/edit) form submission.
		if ( isset( $_POST['acps_ls_save'] ) ) {
			$this->handle_save();
			return;
		}

		// "Test connection" button (checked before the save handler).
		if ( isset( $_POST['acps_ls_test_sync'] ) ) {
			$this->handle_sync_test();
			return;
		}

		// "Generate setup link" button.
		if ( isset( $_POST['acps_ls_gen_setup'] ) ) {
			$this->handle_generate_setup();
			return;
		}

		// Settings submission.
		if ( isset( $_POST['acps_ls_save_settings'] ) ) {
			$this->handle_settings_save();
			return;
		}

		// Link Checker actions.
		if ( isset( $_POST['acps_ls_checker_action'] ) ) {
			$this->handle_checker_action();
			return;
		}

		// Row actions via GET.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		$id     = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;

		if ( ! $id ) {
			return;
		}

		switch ( $action ) {
			case 'delete':
				$this->handle_delete( $id );
				break;
			case 'activate':
			case 'deactivate':
				$this->handle_toggle( $id, 'activate' === $action );
				break;
		}
	}

	/**
	 * Handle add/edit save.
	 */
	private function handle_save() {
		check_admin_referer( 'acps_ls_save_link', 'acps_ls_nonce' );

		if ( ! current_user_can( acps_ls_manage_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to manage links.', 'acps-link-shortener' ) );
		}

		$id            = isset( $_POST['link_id'] ) ? absint( wp_unslash( $_POST['link_id'] ) ) : 0;
		$title         = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$redirect_type = isset( $_POST['redirect_type'] ) ? absint( wp_unslash( $_POST['redirect_type'] ) ) : 302;
		$is_active     = isset( $_POST['is_active'] ) ? 1 : 0;

		// Permanent (301) is disabled unless explicitly allowed: force 302 so a
		// tampered/disabled control can never store a permanent redirect.
		if ( ! acps_ls_allow_permanent() ) {
			$redirect_type = 302;
		}

		/*
		 * EDIT: the slug and destination are LOCKED once a link exists, so a
		 * short link can never be repointed to a different destination. Only the
		 * title, status, and (if allowed) redirect type can change.
		 */
		if ( $id ) {
			if ( ! ACPS_LS_DB::get( $id ) ) {
				wp_safe_redirect( add_query_arg( 'acps_ls_notice', 'deleted', $this->page_url() ) );
				exit;
			}
			ACPS_LS_DB::update(
				$id,
				array(
					'title'         => $title,
					'redirect_type' => $redirect_type,
					'is_active'     => $is_active,
				)
			);
			wp_safe_redirect( add_query_arg( 'acps_ls_notice', 'updated', $this->page_url() ) );
			exit;
		}

		// CREATE: validate slug + destination.
		$slug     = isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : '';
		$raw_dest = isset( $_POST['destination'] ) ? wp_unslash( $_POST['destination'] ) : '';
		$errors   = array();

		$slug_check = ACPS_LS_DB::validate_slug( $slug, 0 );
		if ( is_wp_error( $slug_check ) ) {
			$errors['slug'] = $slug_check->get_error_message();
		}

		$destination = ACPS_LS_DB::validate_destination( $raw_dest );
		if ( is_wp_error( $destination ) ) {
			$errors['destination'] = $destination->get_error_message();
		}

		if ( $errors ) {
			set_transient(
				'acps_ls_form_errors_' . get_current_user_id(),
				array(
					'errors' => $errors,
					'values' => array(
						'link_id'       => 0,
						'slug'          => $slug,
						'destination'   => $raw_dest,
						'title'         => $title,
						'redirect_type' => $redirect_type,
						'is_active'     => $is_active,
					),
				),
				60
			);
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '-add' ) );
			exit;
		}

		$current = wp_get_current_user();
		ACPS_LS_DB::create(
			array(
				'slug'          => $slug,
				'destination'   => $destination,
				'title'         => $title,
				'redirect_type' => $redirect_type,
				'is_active'     => $is_active,
				'source'        => 'manual',
				'creator_label' => $current ? $current->display_name : '',
			)
		);

		wp_safe_redirect( add_query_arg( 'acps_ls_notice', 'created', $this->page_url() ) );
		exit;
	}

	/**
	 * Handle delete.
	 *
	 * @param int $id Row id.
	 */
	private function handle_delete( $id ) {
		check_admin_referer( 'acps_ls_delete_' . $id );
		ACPS_LS_DB::delete( $id );
		wp_safe_redirect( add_query_arg( 'acps_ls_notice', 'deleted', $this->page_url() ) );
		exit;
	}

	/**
	 * Handle activate/deactivate.
	 *
	 * @param int  $id     Row id.
	 * @param bool $active Target state.
	 */
	private function handle_toggle( $id, $active ) {
		$action = $active ? 'activate' : 'deactivate';
		check_admin_referer( 'acps_ls_' . $action . '_' . $id );
		ACPS_LS_DB::update( $id, array( 'is_active' => $active ? 1 : 0 ) );
		wp_safe_redirect( add_query_arg( 'acps_ls_notice', $active ? 'activated' : 'deactivated', $this->page_url() ) );
		exit;
	}

	/**
	 * Handle settings save.
	 */
	private function handle_settings_save() {
		check_admin_referer( 'acps_ls_settings', 'acps_ls_settings_nonce' );

		if ( ! current_user_can( acps_ls_manage_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to change settings.', 'acps-link-shortener' ) );
		}

		$existing = get_option( ACPS_LS_OPT_SETTINGS, array() );
		$existing = is_array( $existing ) ? $existing : array();

		// Custom short-link domain (optional).
		$link_domain = isset( $_POST['link_domain'] ) ? esc_url_raw( wp_unslash( $_POST['link_domain'] ) ) : '';

		// Page that hosts the [acps_link_shortener] shortcode (for setup links).
		$shortcode_page = isset( $_POST['shortcode_page'] ) ? esc_url_raw( wp_unslash( $_POST['shortcode_page'] ) ) : '';

		// Google Sheet sync.
		$sync_enabled = isset( $_POST['sync_enabled'] ) ? 1 : 0;
		$sheet_url    = isset( $_POST['sheet_url'] ) ? esc_url_raw( wp_unslash( $_POST['sheet_url'] ), array( 'https' ) ) : '';
		$sheet_secret = isset( $_POST['sheet_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['sheet_secret'] ) ) : '';

		// People (name + password + optional per-user limit + namespace).
		// Passwords are hashed; a blank password on an existing person keeps theirs.
		$existing_people = array();
		foreach ( acps_ls_get_people() as $p ) {
			$existing_people[ strtolower( $p['label'] ) ] = $p['hash'];
		}

		$people = array();
		if ( isset( $_POST['person_label'] ) && is_array( $_POST['person_label'] ) ) {
			$labels     = wp_unslash( $_POST['person_label'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$passwords  = isset( $_POST['person_password'] ) ? wp_unslash( $_POST['person_password'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$maxes      = isset( $_POST['person_max'] ) ? wp_unslash( $_POST['person_max'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$namespaces = isset( $_POST['person_namespace'] ) ? wp_unslash( $_POST['person_namespace'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

			foreach ( $labels as $i => $raw_label ) {
				$label = sanitize_text_field( $raw_label );
				if ( '' === $label ) {
					continue; // Skip empty rows (also lets an admin delete a person).
				}
				$pw = isset( $passwords[ $i ] ) ? (string) $passwords[ $i ] : '';

				if ( '' !== $pw ) {
					$hash = wp_hash_password( $pw );
				} elseif ( isset( $existing_people[ strtolower( $label ) ] ) ) {
					$hash = $existing_people[ strtolower( $label ) ]; // Keep existing (may be pending).
				} else {
					$hash = ''; // New person, pending — password set later via a setup link.
				}

				$people[] = array(
					'label'     => $label,
					'hash'      => $hash,
					'max_links' => isset( $maxes[ $i ] ) ? max( 0, absint( $maxes[ $i ] ) ) : 0,
					'namespace' => isset( $namespaces[ $i ] ) ? sanitize_title( $namespaces[ $i ] ) : '',
				);
			}
		}

		// Link checker settings.
		$check_enabled  = isset( $_POST['check_enabled'] ) ? 1 : 0;
		$scan_content   = isset( $_POST['scan_content'] ) ? 1 : 0;
		$scan_comments  = isset( $_POST['scan_comments'] ) ? 1 : 0;
		$recheck_hours  = isset( $_POST['recheck_hours'] ) ? max( 1, absint( wp_unslash( $_POST['recheck_hours'] ) ) ) : 72;
		$timeout        = isset( $_POST['timeout'] ) ? max( 1, absint( wp_unslash( $_POST['timeout'] ) ) ) : 15;
		$warnings       = isset( $_POST['warnings'] ) ? 1 : 0;
		$notify_admin   = isset( $_POST['notify_admin'] ) ? 1 : 0;
		$notify_authors = isset( $_POST['notify_authors'] ) ? 1 : 0;
		$notify_email   = isset( $_POST['notify_email'] ) ? sanitize_email( wp_unslash( $_POST['notify_email'] ) ) : '';
		$quiet_enabled  = isset( $_POST['quiet_enabled'] ) ? 1 : 0;
		$quiet_start    = isset( $_POST['quiet_start'] ) ? min( 23, max( 0, absint( wp_unslash( $_POST['quiet_start'] ) ) ) ) : 20;
		$quiet_end      = isset( $_POST['quiet_end'] ) ? min( 23, max( 0, absint( wp_unslash( $_POST['quiet_end'] ) ) ) ) : 8;
		$check_night    = isset( $_POST['check_night_only'] ) ? 1 : 0;
		$check_start    = isset( $_POST['check_start'] ) ? min( 23, max( 0, absint( wp_unslash( $_POST['check_start'] ) ) ) ) : 0;
		$check_end      = isset( $_POST['check_end'] ) ? min( 23, max( 0, absint( wp_unslash( $_POST['check_end'] ) ) ) ) : 6;
		$link_html      = isset( $_POST['link_html'] ) ? 1 : 0;
		$link_images    = isset( $_POST['link_images'] ) ? 1 : 0;
		$link_plaintext = isset( $_POST['link_plaintext'] ) ? 1 : 0;
		$widget_enabled = isset( $_POST['widget_enabled'] ) ? 1 : 0;

		$exclusions = array();
		if ( isset( $_POST['exclusions'] ) ) {
			$lines = preg_split( '/[\r\n]+/', sanitize_textarea_field( wp_unslash( $_POST['exclusions'] ) ) );
			foreach ( (array) $lines as $line ) {
				$line = trim( $line );
				if ( '' !== $line ) {
					$exclusions[] = $line;
				}
			}
		}

		$scan_types    = isset( $_POST['scan_types'] ) && is_array( $_POST['scan_types'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['scan_types'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$scan_statuses = isset( $_POST['scan_statuses'] ) && is_array( $_POST['scan_statuses'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['scan_statuses'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		// Replacement rules.
		$rules = array();
		if ( isset( $_POST['rule_pattern'] ) && is_array( $_POST['rule_pattern'] ) ) {
			$patterns = wp_unslash( $_POST['rule_pattern'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$replaces = isset( $_POST['rule_replace'] ) ? wp_unslash( $_POST['rule_replace'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$types    = isset( $_POST['rule_type'] ) ? wp_unslash( $_POST['rule_type'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$modes    = isset( $_POST['rule_mode'] ) ? wp_unslash( $_POST['rule_mode'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$enabled  = isset( $_POST['rule_enabled'] ) && is_array( $_POST['rule_enabled'] ) ? array_map( 'absint', wp_unslash( $_POST['rule_enabled'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

			foreach ( $patterns as $i => $raw ) {
				$pattern = sanitize_text_field( $raw );
				if ( '' === $pattern ) {
					continue;
				}
				$rules[] = array(
					'type'    => in_array( ( $types[ $i ] ?? '' ), array( 'exact', 'contains', 'regex' ), true ) ? $types[ $i ] : 'contains',
					'pattern' => $pattern,
					'replace' => isset( $replaces[ $i ] ) ? sanitize_text_field( $replaces[ $i ] ) : '',
					'mode'    => ( 'flag' === ( $modes[ $i ] ?? '' ) ) ? 'flag' : 'rewrite',
					'enabled' => ! empty( $enabled[ $i ] ),
				);
			}
		}

		$settings                   = $existing;
		$settings['link_domain']    = $link_domain;
		$settings['shortcode_page'] = $shortcode_page;
		$settings['people']         = $people;
		$settings['sync_enabled']   = $sync_enabled;
		$settings['sheet_url']      = $sheet_url;
		$settings['sheet_secret']   = $sheet_secret;
		$settings['check_enabled']  = $check_enabled;
		$settings['scan_content']   = $scan_content;
		$settings['scan_comments']  = $scan_comments;
		$settings['recheck_hours']  = $recheck_hours;
		$settings['timeout']        = $timeout;
		$settings['warnings']       = $warnings;
		$settings['notify_admin']   = $notify_admin;
		$settings['notify_authors'] = $notify_authors;
		$settings['notify_email']   = $notify_email;
		$settings['quiet_enabled']  = $quiet_enabled;
		$settings['quiet_start']    = $quiet_start;
		$settings['quiet_end']      = $quiet_end;
		$settings['check_night_only'] = $check_night;
		$settings['check_start']      = $check_start;
		$settings['check_end']        = $check_end;
		$settings['link_html']      = $link_html;
		$settings['link_images']    = $link_images;
		$settings['link_plaintext'] = $link_plaintext;
		$settings['widget_enabled'] = $widget_enabled;
		$settings['exclusions']     = $exclusions;
		$settings['scan_types']     = $scan_types;
		$settings['scan_statuses']  = $scan_statuses;
		$settings['rules']          = $rules;
		unset( $settings['default_type'] ); // obsolete

		update_option( ACPS_LS_OPT_SETTINGS, $settings );

		// Ensure the cron events exist when enabling.
		if ( $sync_enabled && ! wp_next_scheduled( ACPS_LS_CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, ACPS_LS_CRON_INTERVAL, ACPS_LS_CRON_HOOK );
		}
		if ( $check_enabled && ! wp_next_scheduled( ACPS_LS_CHECK_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, ACPS_LS_CHECK_INTERVAL, ACPS_LS_CHECK_HOOK );
		}

		wp_safe_redirect( add_query_arg( 'acps_ls_notice', 'settings', $this->settings_url() ) );
		exit;
	}

	/**
	 * Handle the "Test connection" button on the settings screen.
	 */
	private function handle_sync_test() {
		check_admin_referer( 'acps_ls_settings', 'acps_ls_settings_nonce' );

		if ( ! current_user_can( acps_ls_manage_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'acps-link-shortener' ) );
		}

		$sync   = new ACPS_LS_Sync();
		$test   = $sync->test_connection();
		$notice = $test['ok'] ? 'test_ok' : 'test_fail';

		set_transient( 'acps_ls_test_msg_' . get_current_user_id(), $test['message'], 60 );
		wp_safe_redirect( add_query_arg( 'acps_ls_notice', $notice, $this->settings_url() ) );
		exit;
	}

	/**
	 * Handle the "Generate setup link" button: mint a one-time link for a person.
	 */
	private function handle_generate_setup() {
		check_admin_referer( 'acps_ls_settings', 'acps_ls_settings_nonce' );

		if ( ! current_user_can( acps_ls_manage_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'acps-link-shortener' ) );
		}

		$label  = isset( $_POST['setup_label'] ) ? sanitize_text_field( wp_unslash( $_POST['setup_label'] ) ) : '';
		$person = $label ? acps_ls_get_person( $label ) : null;

		if ( ! $person ) {
			set_transient( 'acps_ls_setup_link_' . get_current_user_id(), array( 'error' => __( 'Pick a saved person first (add and save them above).', 'acps-link-shortener' ) ), 300 );
			wp_safe_redirect( add_query_arg( 'acps_ls_notice', 'setup_link', $this->settings_url() ) );
			exit;
		}

		$token = acps_ls_create_setup_token( $person['label'] );
		$url   = add_query_arg( 'acps_ls_setup', rawurlencode( $token ), acps_ls_shortcode_page_url() );

		set_transient(
			'acps_ls_setup_link_' . get_current_user_id(),
			array(
				'label' => $person['label'],
				'url'   => $url,
			),
			300
		);
		wp_safe_redirect( add_query_arg( 'acps_ls_notice', 'setup_link', $this->settings_url() ) );
		exit;
	}

	/**
	 * Handle Link Checker actions (scan/check now, recheck, replace, apply rules).
	 */
	private function handle_checker_action() {
		check_admin_referer( 'acps_ls_checker', 'acps_ls_checker_nonce' );

		if ( ! current_user_can( acps_ls_manage_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'acps-link-shortener' ) );
		}

		$action  = sanitize_key( wp_unslash( $_POST['acps_ls_checker_action'] ) );
		$checker = new ACPS_LS_Checker();
		$notice  = 'checker';
		$msg     = '';

		switch ( $action ) {
			case 'scan_now':
				$checker->collect_shortener_occurrences();
				$checker->scan_content_batch();
				$checker->scan_comment_batch();
				$msg = __( 'Scan run. New links are queued for checking.', 'acps-link-shortener' );
				break;

			case 'check_now':
				$settings = ACPS_LS_Checker::settings();
				$n        = $checker->check_batch( 30, (int) $settings['recheck_hours'] );
				$msg      = sprintf( /* translators: %d: count. */ __( 'Checked %d links.', 'acps-link-shortener' ), $n );
				break;

			case 'recheck_all':
				ACPS_LS_Checker::mark_recheck( 0 );
				$msg = __( 'All links queued for rechecking.', 'acps-link-shortener' );
				break;

			case 'recheck':
				$id = isset( $_POST['url_id'] ) ? absint( wp_unslash( $_POST['url_id'] ) ) : 0;
				if ( $id ) {
					$row = ACPS_LS_Checker::get_url( $id );
					if ( $row ) {
						$result = $checker->check_url( $row->url );
						// Reuse save via mark + immediate check.
						ACPS_LS_Checker::mark_recheck( $id );
						$checker->check_batch( 1, 0 );
					}
					$msg = __( 'Link rechecked.', 'acps-link-shortener' );
				}
				break;

			case 'apply_rules':
				$n   = $checker->apply_rules_to_shortener();
				$msg = sprintf( /* translators: %d: count. */ __( 'Applied rewrite rules to %d short links.', 'acps-link-shortener' ), $n );
				break;

			case 'dismiss':
				$id = isset( $_POST['url_id'] ) ? absint( wp_unslash( $_POST['url_id'] ) ) : 0;
				if ( $id ) {
					ACPS_LS_Checker::set_flag( $id, 'dismissed', 1 );
					$msg = __( 'Link dismissed (hidden).', 'acps-link-shortener' );
				}
				break;

			case 'restore':
				$id = isset( $_POST['url_id'] ) ? absint( wp_unslash( $_POST['url_id'] ) ) : 0;
				if ( $id ) {
					ACPS_LS_Checker::set_flag( $id, 'dismissed', 0 );
					$msg = __( 'Link restored.', 'acps-link-shortener' );
				}
				break;

			case 'not_broken':
				$id = isset( $_POST['url_id'] ) ? absint( wp_unslash( $_POST['url_id'] ) ) : 0;
				if ( $id ) {
					ACPS_LS_Checker::set_flag( $id, 'false_positive', 1 );
					$msg = __( 'Marked as not broken.', 'acps-link-shortener' );
				}
				break;

			case 'unlink':
				$hash = isset( $_POST['url_hash'] ) ? sanitize_text_field( wp_unslash( $_POST['url_hash'] ) ) : '';
				if ( $hash ) {
					$n   = $checker->unlink_everywhere( $hash );
					$msg = sprintf( /* translators: %d: count. */ __( 'Unlinked in %d place(s).', 'acps-link-shortener' ), $n );
				}
				break;

			case 'fix_redirect':
				$id = isset( $_POST['url_id'] ) ? absint( wp_unslash( $_POST['url_id'] ) ) : 0;
				if ( $id ) {
					$n   = $checker->fix_redirect( $id );
					$msg = sprintf( /* translators: %d: count. */ __( 'Updated %d place(s) to the redirect target.', 'acps-link-shortener' ), $n );
				}
				break;

			case 'forced_recheck':
				$checker->forced_recheck();
				$msg = __( 'Database cleared. The whole site will be rediscovered and rechecked.', 'acps-link-shortener' );
				break;

			case 'force_notify':
				$n   = $checker->force_notify();
				$msg = $n
					? sprintf( /* translators: %d: count. */ __( 'Sent a report of %d broken link(s).', 'acps-link-shortener' ), $n )
					: __( 'No broken links to report.', 'acps-link-shortener' );
				break;

			case 'replace':
				$hash    = isset( $_POST['url_hash'] ) ? sanitize_text_field( wp_unslash( $_POST['url_hash'] ) ) : '';
				$new_url = isset( $_POST['new_url'] ) ? esc_url_raw( wp_unslash( $_POST['new_url'] ) ) : '';
				if ( $hash && $new_url ) {
					$n   = $checker->replace_everywhere( $hash, $new_url );
					$msg = sprintf( /* translators: %d: count. */ __( 'Replaced the URL in %d place(s).', 'acps-link-shortener' ), $n );
				} else {
					$msg = __( 'Enter a valid replacement URL.', 'acps-link-shortener' );
				}
				break;
		}

		if ( $msg ) {
			set_transient( 'acps_ls_checker_msg_' . get_current_user_id(), $msg, 60 );
		}

		$redirect = $this->checker_url();
		if ( isset( $_POST['return_state'] ) ) {
			$redirect = add_query_arg( 'state', sanitize_key( wp_unslash( $_POST['return_state'] ) ), $redirect );
		}
		wp_safe_redirect( add_query_arg( 'acps_ls_notice', $notice, $redirect ) );
		exit;
	}

	/* --------------------------------------------------------------------- */
	/* Rendering                                                              */
	/* --------------------------------------------------------------------- */

	/**
	 * Print a queued notice bar based on ?acps_ls_notice=.
	 */
	private function render_notice_from_query() {
		if ( ! isset( $_GET['acps_ls_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$map = array(
			'created'     => __( 'Link created.', 'acps-link-shortener' ),
			'updated'     => __( 'Link updated.', 'acps-link-shortener' ),
			'deleted'     => __( 'Link deleted.', 'acps-link-shortener' ),
			'activated'   => __( 'Link activated.', 'acps-link-shortener' ),
			'deactivated' => __( 'Link deactivated.', 'acps-link-shortener' ),
			'settings'    => __( 'Settings saved.', 'acps-link-shortener' ),
		);

		$key = sanitize_key( wp_unslash( $_GET['acps_ls_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Link Checker action result.
		if ( 'checker' === $key ) {
			$msg = get_transient( 'acps_ls_checker_msg_' . get_current_user_id() );
			delete_transient( 'acps_ls_checker_msg_' . get_current_user_id() );
			if ( $msg ) {
				printf( '<div class="notice notice-success is-dismissible" role="status"><p>%s</p></div>', esc_html( $msg ) );
			}
			return;
		}

		// A freshly generated one-time setup link (shown once).
		if ( 'setup_link' === $key ) {
			$data = get_transient( 'acps_ls_setup_link_' . get_current_user_id() );
			delete_transient( 'acps_ls_setup_link_' . get_current_user_id() );
			if ( is_array( $data ) && ! empty( $data['error'] ) ) {
				printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( $data['error'] ) );
			} elseif ( is_array( $data ) && ! empty( $data['url'] ) ) {
				echo '<div class="notice notice-success"><p>';
				printf(
					/* translators: %s: person name. */
					esc_html__( 'One-time setup link for %s (copy and send it now — it is shown only once and works one time):', 'acps-link-shortener' ),
					'<strong>' . esc_html( $data['label'] ) . '</strong>'
				);
				echo '</p><p><input type="text" class="large-text code" readonly onfocus="this.select();" value="' . esc_attr( $data['url'] ) . '" style="max-width:640px;" /></p></div>';
			}
			return;
		}

		// Connection-test results carry a stored message.
		if ( 'test_ok' === $key || 'test_fail' === $key ) {
			$msg = get_transient( 'acps_ls_test_msg_' . get_current_user_id() );
			delete_transient( 'acps_ls_test_msg_' . get_current_user_id() );
			printf(
				'<div class="notice %1$s is-dismissible" role="status"><p>%2$s</p></div>',
				'test_ok' === $key ? 'notice-success' : 'notice-error',
				esc_html( $msg ? $msg : __( 'Connection test finished.', 'acps-link-shortener' ) )
			);
			return;
		}

		if ( isset( $map[ $key ] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible" role="status"><p>%s</p></div>',
				esc_html( $map[ $key ] )
			);
		}
	}

	/**
	 * Render the list screen.
	 */
	public function render_list_page() {
		if ( ! current_user_can( acps_ls_manage_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'acps-link-shortener' ) );
		}

		// Editing? Route to the form instead.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'edit' === $action ) {
			$this->render_form_page();
			return;
		}

		require_once ACPS_LS_PATH . 'includes/class-acps-ls-list-table.php';
		$table = new ACPS_LS_List_Table( $this->page_url() );
		$table->prepare_items();

		$add_url = admin_url( 'admin.php?page=' . self::MENU_SLUG . '-add' );
		?>
		<div class="wrap acps-ls-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Link Shortener', 'acps-link-shortener' ); ?></h1>
			<a href="<?php echo esc_url( $add_url ); ?>" class="page-title-action"><?php esc_html_e( 'Add New Link', 'acps-link-shortener' ); ?></a>
			<hr class="wp-header-end" />

			<?php $this->render_notice_from_query(); ?>

			<p class="description">
				<?php
				printf(
					/* translators: %s: short-link base such as example.com/link/. */
					esc_html__( 'Short links are served from %s.', 'acps-link-shortener' ),
					'<code>' . esc_html( acps_ls_link_base() . '/' . ( '' !== ACPS_LS_SLUG_PREFIX ? ACPS_LS_SLUG_PREFIX . '/' : '' ) ) . '</code>'
				);
				echo ' ';
				printf(
					/* translators: 1: shortcode, 2: settings URL. */
					wp_kses_post( __( 'Put %1$s on any page to let staff create links. Manage passwords and domain under %2$s.', 'acps-link-shortener' ) ),
					'<code>[acps_link_shortener]</code>',
					'<a href="' . esc_url( $this->settings_url() ) . '">' . esc_html__( 'Settings → Link Shortener', 'acps-link-shortener' ) . '</a>'
				);
				?>
			</p>

			<!-- ARIA live region: copy-to-clipboard success is announced here. -->
			<div id="acps-ls-live" class="screen-reader-text" role="status" aria-live="polite"></div>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
				<?php
				$table->search_box( __( 'Search links', 'acps-link-shortener' ), 'acps-ls-search' );
				$table->display();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the add/edit form.
	 */
	public function render_form_page() {
		if ( ! current_user_can( acps_ls_manage_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'acps-link-shortener' ) );
		}

		$id   = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$link = $id ? ACPS_LS_DB::get( $id ) : null;

		// Defaults / current values.
		$values = array(
			'link_id'       => $id,
			'slug'          => $link ? $link->slug : '',
			'destination'   => $link ? $link->destination : '',
			'title'         => $link ? $link->title : '',
			'redirect_type' => $link ? (int) $link->redirect_type : 301,
			'is_active'     => $link ? (int) $link->is_active : 1,
		);

		// Carry over failed-submission values + errors, if any.
		$errors    = array();
		$transient = get_transient( 'acps_ls_form_errors_' . get_current_user_id() );
		if ( $transient ) {
			delete_transient( 'acps_ls_form_errors_' . get_current_user_id() );
			$errors = $transient['errors'];
			$values = array_merge( $values, $transient['values'] );
		}

		$is_edit    = (bool) $id;
		$page_title = $is_edit ? __( 'Edit Link', 'acps-link-shortener' ) : __( 'Add New Link', 'acps-link-shortener' );
		$prefix_url = home_url( '/' . ( '' !== ACPS_LS_SLUG_PREFIX ? ACPS_LS_SLUG_PREFIX . '/' : '' ) );
		?>
		<div class="wrap acps-ls-wrap">
			<h1><?php echo esc_html( $page_title ); ?></h1>

			<?php if ( $errors ) : ?>
				<div class="notice notice-error" role="alert">
					<p><?php esc_html_e( 'Please correct the errors below.', 'acps-link-shortener' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>" class="acps-ls-form">
				<?php wp_nonce_field( 'acps_ls_save_link', 'acps_ls_nonce' ); ?>
				<input type="hidden" name="link_id" value="<?php echo esc_attr( $values['link_id'] ); ?>" />

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="acps-ls-title"><?php esc_html_e( 'Title', 'acps-link-shortener' ); ?></label>
							</th>
							<td>
								<input name="title" id="acps-ls-title" type="text" class="regular-text"
									value="<?php echo esc_attr( $values['title'] ); ?>" />
								<p class="description" id="acps-ls-title-desc"><?php esc_html_e( 'A human-friendly label used only in this admin list.', 'acps-link-shortener' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="acps-ls-slug"><?php esc_html_e( 'Slug (shortened link name)', 'acps-link-shortener' ); ?> <span class="acps-ls-required" aria-hidden="true">*</span></label>
							</th>
							<td>
								<span class="acps-ls-prefix"><?php echo esc_html( $prefix_url ); ?></span><input
									name="slug" id="acps-ls-slug" type="text" class="regular-text" <?php echo $is_edit ? 'readonly' : 'required'; ?>
									value="<?php echo esc_attr( $values['slug'] ); ?>"
									aria-describedby="acps-ls-slug-desc<?php echo isset( $errors['slug'] ) ? ' acps-ls-slug-error' : ''; ?>"
									<?php echo isset( $errors['slug'] ) ? 'aria-invalid="true"' : ''; ?> />
								<?php if ( isset( $errors['slug'] ) ) : ?>
									<p class="acps-ls-field-error" id="acps-ls-slug-error" role="alert"><?php echo esc_html( $errors['slug'] ); ?></p>
								<?php endif; ?>
								<p class="description" id="acps-ls-slug-desc">
									<?php
									echo $is_edit
										? esc_html__( 'Locked — the short name cannot be changed after creation.', 'acps-link-shortener' )
										: esc_html__( 'Lowercase letters, numbers and hyphens. Must be unique.', 'acps-link-shortener' );
									?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="acps-ls-destination"><?php esc_html_e( 'Destination URL', 'acps-link-shortener' ); ?> <span class="acps-ls-required" aria-hidden="true">*</span></label>
							</th>
							<td>
								<input name="destination" id="acps-ls-destination" type="url" class="large-text" <?php echo $is_edit ? 'readonly' : 'required'; ?>
									placeholder="https://example.com/some/long/page"
									value="<?php echo esc_attr( $values['destination'] ); ?>"
									aria-describedby="acps-ls-destination-desc<?php echo isset( $errors['destination'] ) ? ' acps-ls-destination-error' : ''; ?>"
									<?php echo isset( $errors['destination'] ) ? 'aria-invalid="true"' : ''; ?> />
								<?php if ( isset( $errors['destination'] ) ) : ?>
									<p class="acps-ls-field-error" id="acps-ls-destination-error" role="alert"><?php echo esc_html( $errors['destination'] ); ?></p>
								<?php endif; ?>
								<p class="description" id="acps-ls-destination-desc">
									<?php
									echo $is_edit
										? esc_html__( 'Locked — a short link cannot be repointed to a different destination. Delete and recreate if the target changed.', 'acps-link-shortener' )
										: esc_html__( 'The real http/https URL visitors are sent to.', 'acps-link-shortener' );
									?>
								</p>
							</td>
						</tr>

						<?php
						$allow_permanent = acps_ls_allow_permanent();
						// With permanent disabled, always present/force 302.
						$type_value = $allow_permanent ? $values['redirect_type'] : 302;
						?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Redirect type', 'acps-link-shortener' ); ?></th>
							<td>
								<fieldset>
									<legend class="screen-reader-text"><?php esc_html_e( 'Redirect type', 'acps-link-shortener' ); ?></legend>
									<label for="acps-ls-type-301" class="<?php echo $allow_permanent ? '' : 'acps-ls-disabled'; ?>">
										<input type="radio" name="redirect_type" id="acps-ls-type-301" value="301"
											<?php checked( 301, $type_value ); ?>
											<?php disabled( ! $allow_permanent ); ?>
											<?php echo $allow_permanent ? '' : 'aria-disabled="true"'; ?> />
										<?php esc_html_e( '301 — Permanent (best for SEO; may be cached at the edge)', 'acps-link-shortener' ); ?>
										<?php if ( ! $allow_permanent ) : ?>
											<span class="description">— <?php esc_html_e( 'disabled', 'acps-link-shortener' ); ?></span>
										<?php endif; ?>
									</label><br />
									<label for="acps-ls-type-302">
										<input type="radio" name="redirect_type" id="acps-ls-type-302" value="302" <?php checked( 302, $type_value ); ?> />
										<?php esc_html_e( '302 — Temporary (edits take effect immediately)', 'acps-link-shortener' ); ?>
									</label>
								</fieldset>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'Status', 'acps-link-shortener' ); ?></th>
							<td>
								<label for="acps-ls-active">
									<input type="checkbox" name="is_active" id="acps-ls-active" value="1" <?php checked( 1, $values['is_active'] ); ?> />
									<?php esc_html_e( 'Active (uncheck to disable this link without deleting it)', 'acps-link-shortener' ); ?>
								</label>
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button( $is_edit ? __( 'Update Link', 'acps-link-shortener' ) : __( 'Create Link', 'acps-link-shortener' ), 'primary', 'acps_ls_save' ); ?>
				<a href="<?php echo esc_url( $this->page_url() ); ?>" class="button button-secondary"><?php esc_html_e( 'Cancel', 'acps-link-shortener' ); ?></a>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the settings screen (short-link domain + front-end people).
	 */
	public function render_settings_page() {
		if ( ! current_user_can( acps_ls_manage_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'acps-link-shortener' ) );
		}

		$settings        = get_option( ACPS_LS_OPT_SETTINGS, array() );
		$settings        = is_array( $settings ) ? $settings : array();
		$link_domain     = ! empty( $settings['link_domain'] ) ? $settings['link_domain'] : '';
		$shortcode_page  = ! empty( $settings['shortcode_page'] ) ? $settings['shortcode_page'] : '';
		$sync_enabled    = ! empty( $settings['sync_enabled'] );
		$sheet_url    = ! empty( $settings['sheet_url'] ) ? $settings['sheet_url'] : '';
		$sheet_secret = ! empty( $settings['sheet_secret'] ) ? $settings['sheet_secret'] : '';
		$last_sync    = get_option( 'acps_ls_last_sync' );
		$chk           = ACPS_LS_Checker::settings();
		$notify_email  = $chk['notify_email'] ? $chk['notify_email'] : get_option( 'admin_email' );
		$exclusions    = implode( "\n", $chk['exclusions'] );
		$rules         = acps_ls_get_rules();
		$rule_rows     = array_merge( $rules, array( array( 'type' => 'contains', 'pattern' => '', 'replace' => '', 'mode' => 'rewrite', 'enabled' => false ), array( 'type' => 'contains', 'pattern' => '', 'replace' => '', 'mode' => 'rewrite', 'enabled' => false ) ) );
		$people       = acps_ls_get_people();
		// Always render a few blank rows for adding new people.
		$blank = array( 'label' => '', 'max_links' => 0, 'namespace' => '' );
		$rows  = array_merge( $people, array( $blank, $blank, $blank ) );
		?>
		<div class="wrap acps-ls-wrap">
			<h1><?php esc_html_e( 'Link Shortener Settings', 'acps-link-shortener' ); ?></h1>

			<?php $this->render_notice_from_query(); ?>

			<form method="post" action="<?php echo esc_url( $this->settings_url() ); ?>">
				<?php wp_nonce_field( 'acps_ls_settings', 'acps_ls_settings_nonce' ); ?>

				<h2><?php esc_html_e( 'Short-link domain', 'acps-link-shortener' ); ?></h2>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="acps-ls-link-domain"><?php esc_html_e( 'Custom domain', 'acps-link-shortener' ); ?></label>
							</th>
							<td>
								<input type="url" name="link_domain" id="acps-ls-link-domain" class="regular-text"
									value="<?php echo esc_attr( $link_domain ); ?>"
									placeholder="https://go.example.com"
									aria-describedby="acps-ls-link-domain-desc" />
								<p class="description" id="acps-ls-link-domain-desc">
									<?php esc_html_e( 'Optional. The domain short links are built on. Leave blank to use this site’s own address. The domain must point to this WordPress install (DNS + WP Engine domain mapping) or the links will not resolve.', 'acps-link-shortener' ); ?>
									<br />
									<?php
									printf(
										/* translators: %s: current short-link base URL. */
										esc_html__( 'Current base: %s', 'acps-link-shortener' ),
										'<code>' . esc_html( trailingslashit( acps_ls_link_base() . ( '' !== ACPS_LS_SLUG_PREFIX ? '/' . ACPS_LS_SLUG_PREFIX : '' ) ) ) . '</code>'
									);
									?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="acps-ls-shortcode-page"><?php esc_html_e( 'Shortcode page', 'acps-link-shortener' ); ?></label>
							</th>
							<td>
								<input type="url" name="shortcode_page" id="acps-ls-shortcode-page" class="regular-text"
									value="<?php echo esc_attr( $shortcode_page ); ?>"
									placeholder="<?php echo esc_attr( home_url( '/make-links/' ) ); ?>" aria-describedby="acps-ls-shortcode-page-desc" />
								<p class="description" id="acps-ls-shortcode-page-desc"><?php esc_html_e( 'The page where you placed the [acps_link_shortener] shortcode. Setup links point here so invitees can set their password.', 'acps-link-shortener' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Front-end users (shortcode access)', 'acps-link-shortener' ); ?></h2>
				<p class="description">
					<?php
					printf(
						/* translators: %s: the shortcode. */
						esc_html__( 'These people can create links from any page that contains the %s shortcode. Each has their own password. To change a password, type a new one; leave it blank to keep the current password. To remove someone, clear their name and save.', 'acps-link-shortener' ),
						'<code>[acps_link_shortener]</code>'
					);
					?>
				</p>

				<table class="widefat striped acps-ls-people" style="max-width:820px;">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Name', 'acps-link-shortener' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Password', 'acps-link-shortener' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Max links', 'acps-link-shortener' ); ?></th>
							<th scope="col"><?php esc_html_e( 'URL namespace', 'acps-link-shortener' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $i => $person ) : ?>
							<?php
							$has     = ! empty( $person['label'] );
							$pending = $has && empty( $person['hash'] );
							$mx      = isset( $person['max_links'] ) ? (int) $person['max_links'] : 0;
							$ns      = isset( $person['namespace'] ) ? $person['namespace'] : '';
							if ( $has && $pending ) {
								$pw_placeholder = esc_attr__( 'no password yet — send a setup link', 'acps-link-shortener' );
							} elseif ( $has ) {
								$pw_placeholder = esc_attr__( '•••••• (blank = keep)', 'acps-link-shortener' );
							} else {
								$pw_placeholder = esc_attr__( 'set a password (or leave blank + send a setup link)', 'acps-link-shortener' );
							}
							?>
							<tr>
								<td>
									<label class="screen-reader-text" for="acps-ls-person-label-<?php echo (int) $i; ?>"><?php esc_html_e( 'Name', 'acps-link-shortener' ); ?></label>
									<input type="text" name="person_label[<?php echo (int) $i; ?>]" id="acps-ls-person-label-<?php echo (int) $i; ?>"
										value="<?php echo esc_attr( $has ? $person['label'] : '' ); ?>" autocomplete="off" />
								</td>
								<td>
									<label class="screen-reader-text" for="acps-ls-person-pw-<?php echo (int) $i; ?>"><?php esc_html_e( 'Password', 'acps-link-shortener' ); ?></label>
									<input type="text" name="person_password[<?php echo (int) $i; ?>]" id="acps-ls-person-pw-<?php echo (int) $i; ?>"
										value="" autocomplete="off"
										placeholder="<?php echo esc_attr( $pw_placeholder ); ?>" />
								</td>
								<td>
									<label class="screen-reader-text" for="acps-ls-person-max-<?php echo (int) $i; ?>"><?php esc_html_e( 'Max links (0 = unlimited)', 'acps-link-shortener' ); ?></label>
									<input type="number" min="0" step="1" style="width:6em;" name="person_max[<?php echo (int) $i; ?>]" id="acps-ls-person-max-<?php echo (int) $i; ?>"
										value="<?php echo esc_attr( $mx ); ?>" />
								</td>
								<td>
									<label class="screen-reader-text" for="acps-ls-person-ns-<?php echo (int) $i; ?>"><?php esc_html_e( 'URL namespace', 'acps-link-shortener' ); ?></label>
									<input type="text" name="person_namespace[<?php echo (int) $i; ?>]" id="acps-ls-person-ns-<?php echo (int) $i; ?>"
										value="<?php echo esc_attr( $ns ); ?>" placeholder="<?php esc_attr_e( 'e.g. katherine', 'acps-link-shortener' ); ?>" />
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description">
					<?php esc_html_e( 'Passwords are hashed and cannot be shown again. Max links = 0 means unlimited (counts only links a person made via the shortcode). URL namespace forces the first path segment, e.g. “katherine” makes their links look like example.com/katherine/name.', 'acps-link-shortener' ); ?>
				</p>

				<h3><?php esc_html_e( 'Send a one-time setup link', 'acps-link-shortener' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'Let a person set their own password with a single-use link — you never see or set it. Add and save their name above (leave the password blank), then generate a link and send it to them. The link works once and expires in 72 hours.', 'acps-link-shortener' ); ?>
				</p>
				<p>
					<label for="acps-ls-setup-label" class="screen-reader-text"><?php esc_html_e( 'Person', 'acps-link-shortener' ); ?></label>
					<select name="setup_label" id="acps-ls-setup-label">
						<option value=""><?php esc_html_e( '— choose a person —', 'acps-link-shortener' ); ?></option>
						<?php foreach ( $people as $p ) : ?>
							<option value="<?php echo esc_attr( $p['label'] ); ?>">
								<?php
								echo esc_html( $p['label'] );
								echo empty( $p['hash'] ) ? esc_html__( ' (no password yet)', 'acps-link-shortener' ) : '';
								?>
							</option>
						<?php endforeach; ?>
					</select>
					<?php submit_button( __( 'Generate setup link', 'acps-link-shortener' ), 'secondary', 'acps_ls_gen_setup', false ); ?>
					<span class="description"><?php esc_html_e( '(Save any name changes first.)', 'acps-link-shortener' ); ?></span>
				</p>

				<h2><?php esc_html_e( 'Google Sheet sync', 'acps-link-shortener' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'WordPress sends its links to a Google Apps Script web app every 3 minutes; the script mirrors them into your spreadsheet and returns the sheet’s rows, which WordPress applies (adds, updates, and deletes of sheet-made links). Deploy the bundled google-apps-script/Code.gs and paste its /exec URL below.', 'acps-link-shortener' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Enable sync', 'acps-link-shortener' ); ?></th>
							<td>
								<label for="acps-ls-sync-enabled">
									<input type="checkbox" name="sync_enabled" id="acps-ls-sync-enabled" value="1" <?php checked( true, $sync_enabled ); ?> />
									<?php esc_html_e( 'Sync links with the Google Sheet every 3 minutes.', 'acps-link-shortener' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="acps-ls-sheet-url"><?php esc_html_e( 'Web app URL', 'acps-link-shortener' ); ?></label></th>
							<td>
								<input type="url" name="sheet_url" id="acps-ls-sheet-url" class="large-text"
									value="<?php echo esc_attr( $sheet_url ); ?>"
									placeholder="https://script.google.com/macros/s/…/exec" aria-describedby="acps-ls-sheet-url-desc" />
								<p class="description" id="acps-ls-sheet-url-desc"><?php esc_html_e( 'The deployed Apps Script web app URL (https). Deploy Code.gs as “Anyone” access.', 'acps-link-shortener' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="acps-ls-sheet-secret"><?php esc_html_e( 'Shared secret', 'acps-link-shortener' ); ?></label></th>
							<td>
								<input type="text" name="sheet_secret" id="acps-ls-sheet-secret" class="regular-text"
									value="<?php echo esc_attr( $sheet_secret ); ?>" autocomplete="off" aria-describedby="acps-ls-sheet-secret-desc" />
								<p class="description" id="acps-ls-sheet-secret-desc"><?php esc_html_e( 'Sent with each request so only this site can drive the sheet. Must match the SECRET in Code.gs.', 'acps-link-shortener' ); ?></p>
							</td>
						</tr>
						<?php if ( is_array( $last_sync ) ) : ?>
							<tr>
								<th scope="row"><?php esc_html_e( 'Last sync', 'acps-link-shortener' ); ?></th>
								<td>
									<?php
									if ( isset( $last_sync['error'] ) ) {
										echo '<span style="color:#b32d2e;">' . esc_html( $last_sync['error'] ) . '</span>';
									} else {
										printf(
											/* translators: 1: time, 2: created, 3: updated, 4: deleted, 5: skipped, 6: errors. */
											esc_html__( '%1$s — created %2$d, updated %3$d, deleted %4$d, skipped %5$d, errors %6$d.', 'acps-link-shortener' ),
											esc_html( isset( $last_sync['time'] ) ? $last_sync['time'] : '' ),
											(int) ( $last_sync['created'] ?? 0 ),
											(int) ( $last_sync['updated'] ?? 0 ),
											(int) ( $last_sync['deleted'] ?? 0 ),
											(int) ( $last_sync['skipped'] ?? 0 ),
											(int) ( $last_sync['errors'] ?? 0 )
										);
									}
									?>
								</td>
							</tr>
						<?php endif; ?>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Link checker', 'acps-link-shortener' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Verifies that links are alive and applies your replacement rules. Checks each unique URL once and works in small batches for efficiency.', 'acps-link-shortener' ); ?></p>
				<?php
				$all_types    = get_post_types( array( 'public' => true ), 'objects' );
				$all_statuses = array( 'publish' => __( 'Published', 'acps-link-shortener' ), 'future' => __( 'Scheduled', 'acps-link-shortener' ), 'draft' => __( 'Draft', 'acps-link-shortener' ), 'pending' => __( 'Pending', 'acps-link-shortener' ), 'private' => __( 'Private', 'acps-link-shortener' ) );
				?>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Enable checker', 'acps-link-shortener' ); ?></th>
							<td>
								<label><input type="checkbox" name="check_enabled" value="1" <?php checked( 1, $chk['check_enabled'] ); ?> /> <?php esc_html_e( 'Run automatically every 10 minutes (scan + check).', 'acps-link-shortener' ); ?></label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Check links only at night', 'acps-link-shortener' ); ?></th>
							<td>
								<fieldset>
									<label><input type="checkbox" name="check_night_only" value="1" <?php checked( 1, $chk['check_night_only'] ); ?> /> <?php esc_html_e( 'Run the outbound link checks only during this window (avoids extra load during the day).', 'acps-link-shortener' ); ?></label>
									<p>
										<label for="acps-ls-check-start"><?php esc_html_e( 'Check from', 'acps-link-shortener' ); ?></label>
										<?php $this->hour_select( 'check_start', 'acps-ls-check-start', (int) $chk['check_start'] ); ?>
										<label for="acps-ls-check-end"><?php esc_html_e( 'until', 'acps-link-shortener' ); ?></label>
										<?php $this->hour_select( 'check_end', 'acps-ls-check-end', (int) $chk['check_end'] ); ?>
									</p>
									<p class="description"><?php esc_html_e( 'Site timezone. New links are still discovered any time; only the link checks (which make outbound requests) are held to this window. The manual “Check now” button on the Link Checker screen ignores it.', 'acps-link-shortener' ); ?></p>
								</fieldset>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Look for links in', 'acps-link-shortener' ); ?></th>
							<td>
								<fieldset>
									<label><input type="checkbox" name="scan_content" value="1" <?php checked( 1, $chk['scan_content'] ); ?> /> <?php esc_html_e( 'Posts and pages (content)', 'acps-link-shortener' ); ?></label><br />
									<label><input type="checkbox" name="scan_comments" value="1" <?php checked( 1, $chk['scan_comments'] ); ?> /> <?php esc_html_e( 'Comments', 'acps-link-shortener' ); ?></label>
									<p class="description"><?php esc_html_e( 'The shortener’s own destinations are always checked.', 'acps-link-shortener' ); ?></p>
								</fieldset>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Post types', 'acps-link-shortener' ); ?></th>
							<td>
								<fieldset>
									<?php foreach ( $all_types as $pt ) : ?>
										<label style="display:inline-block;min-width:160px;">
											<input type="checkbox" name="scan_types[]" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( in_array( $pt->name, $chk['scan_types'], true ) ); ?> />
											<?php echo esc_html( $pt->labels->name ); ?>
										</label>
									<?php endforeach; ?>
								</fieldset>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Post statuses', 'acps-link-shortener' ); ?></th>
							<td>
								<fieldset>
									<?php foreach ( $all_statuses as $key => $label ) : ?>
										<label style="display:inline-block;min-width:130px;">
											<input type="checkbox" name="scan_statuses[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $chk['scan_statuses'], true ) ); ?> />
											<?php echo esc_html( $label ); ?>
										</label>
									<?php endforeach; ?>
								</fieldset>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Link types', 'acps-link-shortener' ); ?></th>
							<td>
								<fieldset>
									<label><input type="checkbox" name="link_html" value="1" <?php checked( 1, $chk['link_html'] ); ?> /> <?php esc_html_e( 'HTML links (a href)', 'acps-link-shortener' ); ?></label><br />
									<label><input type="checkbox" name="link_images" value="1" <?php checked( 1, $chk['link_images'] ); ?> /> <?php esc_html_e( 'HTML images (img src)', 'acps-link-shortener' ); ?></label><br />
									<label><input type="checkbox" name="link_plaintext" value="1" <?php checked( 1, $chk['link_plaintext'] ); ?> /> <?php esc_html_e( 'Plain-text URLs', 'acps-link-shortener' ); ?></label>
								</fieldset>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Warnings', 'acps-link-shortener' ); ?></th>
							<td>
								<label><input type="checkbox" name="warnings" value="1" <?php checked( 1, $chk['warnings'] ); ?> /> <?php esc_html_e( 'Show uncertain problems (timeouts, connection errors) as “warnings” instead of “broken”.', 'acps-link-shortener' ); ?></label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="acps-ls-recheck-hours"><?php esc_html_e( 'Recheck every', 'acps-link-shortener' ); ?></label></th>
							<td>
								<input type="number" min="1" step="1" style="width:6em;" name="recheck_hours" id="acps-ls-recheck-hours" value="<?php echo esc_attr( $chk['recheck_hours'] ); ?>" /> <?php esc_html_e( 'hours', 'acps-link-shortener' ); ?>
								<p class="description"><?php esc_html_e( 'How long a result is trusted before the URL is checked again.', 'acps-link-shortener' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="acps-ls-timeout"><?php esc_html_e( 'Timeout', 'acps-link-shortener' ); ?></label></th>
							<td>
								<input type="number" min="1" step="1" style="width:6em;" name="timeout" id="acps-ls-timeout" value="<?php echo esc_attr( $chk['timeout'] ); ?>" /> <?php esc_html_e( 'seconds', 'acps-link-shortener' ); ?>
								<p class="description"><?php esc_html_e( 'Links slower than this are treated as failing.', 'acps-link-shortener' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="acps-ls-exclusions"><?php esc_html_e( 'Exclusion list', 'acps-link-shortener' ); ?></label></th>
							<td>
								<textarea name="exclusions" id="acps-ls-exclusions" rows="4" class="large-text code" placeholder="example.com/skip"><?php echo esc_textarea( $exclusions ); ?></textarea>
								<p class="description"><?php esc_html_e( 'One URL fragment per line. Any link whose URL contains one of these is skipped.', 'acps-link-shortener' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'E-mail notifications', 'acps-link-shortener' ); ?></th>
							<td>
								<fieldset>
									<label><input type="checkbox" name="notify_admin" value="1" <?php checked( 1, $chk['notify_admin'] ); ?> /> <?php esc_html_e( 'E-mail me when new broken links are detected.', 'acps-link-shortener' ); ?></label><br />
									<label><input type="checkbox" name="notify_authors" value="1" <?php checked( 1, $chk['notify_authors'] ); ?> /> <?php esc_html_e( 'E-mail authors about broken links in their own posts.', 'acps-link-shortener' ); ?></label>
									<p>
										<label for="acps-ls-notify-email"><?php esc_html_e( 'Notification e-mail:', 'acps-link-shortener' ); ?></label>
										<input type="email" name="notify_email" id="acps-ls-notify-email" class="regular-text" value="<?php echo esc_attr( $chk['notify_email'] ); ?>" placeholder="<?php echo esc_attr( $notify_email ); ?>" />
									</p>
								</fieldset>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Quiet hours', 'acps-link-shortener' ); ?></th>
							<td>
								<fieldset>
									<label><input type="checkbox" name="quiet_enabled" value="1" <?php checked( 1, $chk['quiet_enabled'] ); ?> /> <?php esc_html_e( 'Don’t e-mail overnight. Hold anything found and send it after quiet hours end.', 'acps-link-shortener' ); ?></label>
									<p>
										<label for="acps-ls-quiet-start"><?php esc_html_e( 'Quiet from', 'acps-link-shortener' ); ?></label>
										<?php $this->hour_select( 'quiet_start', 'acps-ls-quiet-start', (int) $chk['quiet_start'] ); ?>
										<label for="acps-ls-quiet-end"><?php esc_html_e( 'until', 'acps-link-shortener' ); ?></label>
										<?php $this->hour_select( 'quiet_end', 'acps-ls-quiet-end', (int) $chk['quiet_end'] ); ?>
									</p>
									<p class="description">
										<?php
										printf(
											/* translators: %s: site timezone string. */
											esc_html__( 'Uses the site timezone (%s). With the default 8 PM–8 AM, overnight breakages are e-mailed in the first check after 8 AM.', 'acps-link-shortener' ),
											esc_html( wp_timezone_string() )
										);
										?>
									</p>
								</fieldset>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Dashboard widget', 'acps-link-shortener' ); ?></th>
							<td>
								<label><input type="checkbox" name="widget_enabled" value="1" <?php checked( 1, $chk['widget_enabled'] ); ?> /> <?php esc_html_e( 'Show a “Broken links” widget on the admin dashboard.', 'acps-link-shortener' ); ?></label>
							</td>
						</tr>
					</tbody>
				</table>

				<h3><?php esc_html_e( 'Replacement rules', 'acps-link-shortener' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'Rewrite rules change a matching URL automatically wherever a destination is saved (and via “Apply rewrite rules” on the Link Checker screen). Flag rules only mark matches on the Link Checker screen. Match types: contains (substring), exact (whole URL), or regex.', 'acps-link-shortener' ); ?>
				</p>
				<table class="widefat striped" style="max-width:900px;">
					<thead>
						<tr>
							<th scope="col" style="width:60px;"><?php esc_html_e( 'On', 'acps-link-shortener' ); ?></th>
							<th scope="col" style="width:110px;"><?php esc_html_e( 'Match', 'acps-link-shortener' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Pattern', 'acps-link-shortener' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Replace with', 'acps-link-shortener' ); ?></th>
							<th scope="col" style="width:110px;"><?php esc_html_e( 'Action', 'acps-link-shortener' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rule_rows as $i => $rule ) : ?>
							<tr>
								<td style="text-align:center;">
									<input type="checkbox" name="rule_enabled[<?php echo (int) $i; ?>]" value="1" <?php checked( true, ! empty( $rule['enabled'] ) ); ?> aria-label="<?php esc_attr_e( 'Enabled', 'acps-link-shortener' ); ?>" />
								</td>
								<td>
									<select name="rule_type[<?php echo (int) $i; ?>]" aria-label="<?php esc_attr_e( 'Match type', 'acps-link-shortener' ); ?>">
										<option value="contains" <?php selected( 'contains', $rule['type'] ); ?>><?php esc_html_e( 'contains', 'acps-link-shortener' ); ?></option>
										<option value="exact" <?php selected( 'exact', $rule['type'] ); ?>><?php esc_html_e( 'exact', 'acps-link-shortener' ); ?></option>
										<option value="regex" <?php selected( 'regex', $rule['type'] ); ?>><?php esc_html_e( 'regex', 'acps-link-shortener' ); ?></option>
									</select>
								</td>
								<td><input type="text" name="rule_pattern[<?php echo (int) $i; ?>]" value="<?php echo esc_attr( $rule['pattern'] ); ?>" class="regular-text" style="width:100%;" placeholder="http://old.example.com" aria-label="<?php esc_attr_e( 'Pattern', 'acps-link-shortener' ); ?>" /></td>
								<td><input type="text" name="rule_replace[<?php echo (int) $i; ?>]" value="<?php echo esc_attr( $rule['replace'] ); ?>" class="regular-text" style="width:100%;" placeholder="https://new.example.com" aria-label="<?php esc_attr_e( 'Replacement', 'acps-link-shortener' ); ?>" /></td>
								<td>
									<select name="rule_mode[<?php echo (int) $i; ?>]" aria-label="<?php esc_attr_e( 'Action', 'acps-link-shortener' ); ?>">
										<option value="rewrite" <?php selected( 'rewrite', $rule['mode'] ); ?>><?php esc_html_e( 'rewrite', 'acps-link-shortener' ); ?></option>
										<option value="flag" <?php selected( 'flag', $rule['mode'] ); ?>><?php esc_html_e( 'flag only', 'acps-link-shortener' ); ?></option>
									</select>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description"><?php esc_html_e( 'To delete a rule, clear its pattern and save. Empty rows are ignored.', 'acps-link-shortener' ); ?></p>

				<p>
					<?php submit_button( __( 'Save Settings', 'acps-link-shortener' ), 'primary', 'acps_ls_save_settings', false ); ?>
					<?php submit_button( __( 'Test connection', 'acps-link-shortener' ), 'secondary', 'acps_ls_test_sync', false ); ?>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the Link Checker screen.
	 */
	public function render_checker_page() {
		if ( ! current_user_can( acps_ls_manage_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'acps-link-shortener' ) );
		}

		$counts = ACPS_LS_Checker::counts();
		$state  = isset( $_GET['state'] ) ? sanitize_key( wp_unslash( $_GET['state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$result   = ACPS_LS_Checker::get_urls( array( 'state' => $state, 'search' => $search, 'paged' => $paged, 'per_page' => 20 ) );
		$items    = $result['items'];
		$total    = $result['total'];
		$pages    = (int) ceil( $total / 20 );
		$last     = get_option( 'acps_ls_last_check' );
		$settings = ACPS_LS_Checker::settings();

		$tabs = array(
			''          => sprintf( /* translators: %d: count. */ __( 'All (%d)', 'acps-link-shortener' ), $counts['all'] ),
			'broken'    => sprintf( __( 'Broken (%d)', 'acps-link-shortener' ), $counts['broken'] ),
			'warning'   => sprintf( __( 'Warnings (%d)', 'acps-link-shortener' ), $counts['warning'] ),
			'redirect'  => sprintf( __( 'Redirects (%d)', 'acps-link-shortener' ), $counts['redirect'] ),
			'ok'        => sprintf( __( 'OK (%d)', 'acps-link-shortener' ), $counts['ok'] ),
			'unchecked' => sprintf( __( 'Unchecked (%d)', 'acps-link-shortener' ), $counts['unchecked'] ),
			'dismissed' => sprintf( __( 'Dismissed (%d)', 'acps-link-shortener' ), $counts['dismissed'] ),
		);
		$info = ACPS_LS_Checker::status_info();
		?>
		<div class="wrap acps-ls-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Link Checker', 'acps-link-shortener' ); ?></h1>
			<hr class="wp-header-end" />

			<?php $this->render_notice_from_query(); ?>

			<?php if ( empty( $settings['check_enabled'] ) ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php
					printf(
						/* translators: %s: settings link. */
						wp_kses_post( __( 'The link checker is turned off. Enable it under %s to run automatically every 10 minutes.', 'acps-link-shortener' ) ),
						'<a href="' . esc_url( $this->settings_url() ) . '">' . esc_html__( 'Settings → Link Shortener', 'acps-link-shortener' ) . '</a>'
					);
					?>
				</p></div>
			<?php endif; ?>

			<div class="acps-ls-status-panel" style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:.75rem 1rem;margin:.75rem 0;display:flex;flex-wrap:wrap;gap:1.5rem;">
				<span><strong><?php echo esc_html( number_format_i18n( $info['broken'] ) ); ?></strong> <?php esc_html_e( 'broken', 'acps-link-shortener' ); ?></span>
				<span><strong><?php echo esc_html( number_format_i18n( $info['queue'] ) ); ?></strong> <?php esc_html_e( 'in the check queue', 'acps-link-shortener' ); ?></span>
				<span><strong><?php echo esc_html( number_format_i18n( $info['unique'] ) ); ?></strong> <?php esc_html_e( 'unique URLs', 'acps-link-shortener' ); ?> (<?php echo esc_html( number_format_i18n( $info['occ_total'] ) ); ?> <?php esc_html_e( 'occurrences', 'acps-link-shortener' ); ?>)</span>
				<span class="description">PHP <?php echo esc_html( $info['php'] ); ?> · MySQL <?php echo esc_html( $info['mysql'] ); ?> · cURL <?php echo esc_html( $info['curl'] ); ?> · <?php printf( esc_html__( 'timeout %ds', 'acps-link-shortener' ), (int) $info['timeout'] ); ?></span>
				<span class="description"><?php printf( esc_html__( 'Last e-mail: %s', 'acps-link-shortener' ), esc_html( $info['last_email'] ) ); ?></span>
			</div>

			<p>
				<?php $this->checker_button( 'scan_now', __( 'Scan now', 'acps-link-shortener' ), $state ); ?>
				<?php $this->checker_button( 'check_now', __( 'Check now', 'acps-link-shortener' ), $state ); ?>
				<?php $this->checker_button( 'recheck_all', __( 'Recheck all', 'acps-link-shortener' ), $state ); ?>
				<?php $this->checker_button( 'apply_rules', __( 'Apply rewrite rules to short links', 'acps-link-shortener' ), $state ); ?>
				<?php $this->checker_button( 'force_notify', __( 'Force notify (e-mail all broken links)', 'acps-link-shortener' ), $state, __( 'E-mail a report of every currently broken link now?', 'acps-link-shortener' ) ); ?>
				<?php $this->checker_button( 'forced_recheck', __( 'Forced recheck (clear & rescan)', 'acps-link-shortener' ), $state, __( 'This clears the checker database and rechecks the whole site from scratch. Continue?', 'acps-link-shortener' ) ); ?>
				<?php if ( is_array( $last ) && ! empty( $last['time'] ) ) : ?>
					<span class="description" style="margin-left:.5rem;"><?php printf( esc_html__( 'Last check: %s', 'acps-link-shortener' ), esc_html( $last['time'] ) ); ?></span>
				<?php endif; ?>
			</p>

			<ul class="subsubsub">
				<?php
				$i = 0;
				foreach ( $tabs as $key => $label ) :
					$url = add_query_arg( array_filter( array( 'page' => self::MENU_SLUG . '-checker', 'state' => $key ) ), admin_url( 'admin.php' ) );
					?>
					<li>
						<a href="<?php echo esc_url( $url ); ?>" class="<?php echo $state === $key ? 'current' : ''; ?>"><?php echo esc_html( $label ); ?></a><?php echo ( ++$i < count( $tabs ) ) ? ' |' : ''; ?>
					</li>
				<?php endforeach; ?>
			</ul>

			<form method="get" style="margin:.5rem 0;">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG . '-checker' ); ?>" />
				<?php if ( $state ) : ?><input type="hidden" name="state" value="<?php echo esc_attr( $state ); ?>" /><?php endif; ?>
				<p class="search-box">
					<label class="screen-reader-text" for="acps-ls-check-search"><?php esc_html_e( 'Search URLs', 'acps-link-shortener' ); ?></label>
					<input type="search" id="acps-ls-check-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search URLs', 'acps-link-shortener' ); ?>" />
					<?php submit_button( __( 'Search', 'acps-link-shortener' ), '', '', false ); ?>
				</p>
			</form>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'URL', 'acps-link-shortener' ); ?></th>
						<th scope="col" style="width:130px;"><?php esc_html_e( 'Status', 'acps-link-shortener' ); ?></th>
						<th scope="col" style="width:180px;"><?php esc_html_e( 'Found in', 'acps-link-shortener' ); ?></th>
						<th scope="col" style="width:280px;"><?php esc_html_e( 'Actions', 'acps-link-shortener' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $items ) : ?>
						<tr><td colspan="4"><?php esc_html_e( 'No links found. Run “Scan now”, or add short links.', 'acps-link-shortener' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $items as $row ) : ?>
							<?php $this->render_checker_row( $row, $state ); ?>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $pages > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => add_query_arg( 'paged', '%#%' ),
								'format'    => '',
								'current'   => $paged,
								'total'     => $pages,
								'prev_text' => '‹',
								'next_text' => '›',
							)
						)
					);
					?>
				</div></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * A small POST button used on the checker toolbar.
	 *
	 * @param string $action Action key.
	 * @param string $label  Button label.
	 * @param string $state  Current state filter (preserved on redirect).
	 */
	/**
	 * Render an hour-of-day (0–23) select control.
	 *
	 * @param string $name     Field name.
	 * @param string $id       Field id.
	 * @param int    $selected Currently selected hour.
	 */
	private function hour_select( $name, $id, $selected ) {
		echo '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '">';
		for ( $h = 0; $h < 24; $h++ ) {
			$ampm  = ( 0 === $h ) ? '12 AM' : ( 12 === $h ? '12 PM' : ( $h < 12 ? $h . ' AM' : ( $h - 12 ) . ' PM' ) );
			echo '<option value="' . esc_attr( $h ) . '" ' . selected( $h, $selected, false ) . '>' . esc_html( $ampm ) . '</option>';
		}
		echo '</select>';
	}

	private function checker_button( $action, $label, $state, $confirm = '' ) {
		$onsubmit = $confirm ? ' onsubmit="return confirm(\'' . esc_js( $confirm ) . '\');"' : '';
		?>
		<form method="post" action="<?php echo esc_url( $this->checker_url() ); ?>" style="display:inline;"<?php echo $onsubmit; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<?php wp_nonce_field( 'acps_ls_checker', 'acps_ls_checker_nonce' ); ?>
			<input type="hidden" name="acps_ls_checker_action" value="<?php echo esc_attr( $action ); ?>" />
			<input type="hidden" name="return_state" value="<?php echo esc_attr( $state ); ?>" />
			<button type="submit" class="button"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * Render one checker table row.
	 *
	 * @param object $row   URL row (with occ_count).
	 * @param string $state Current state filter.
	 */
	private function render_checker_row( $row, $state ) {
		$badges = array(
			'ok'        => array( '#135e28', __( 'OK', 'acps-link-shortener' ) ),
			'broken'    => array( '#b32d2e', __( 'Broken', 'acps-link-shortener' ) ),
			'redirect'  => array( '#8a6d00', __( 'Redirect', 'acps-link-shortener' ) ),
			'unchecked' => array( '#50575e', __( 'Not checked', 'acps-link-shortener' ) ),
			'warning'   => array( '#8a6d00', __( 'Warning', 'acps-link-shortener' ) ),
		);
		$b   = isset( $badges[ $row->state ] ) ? $badges[ $row->state ] : $badges['unchecked'];
		$occ = ACPS_LS_Checker::occurrences_for( $row->url_hash );

		$flag = acps_ls_flagging_rule( $row->url );

		$sources = array();
		foreach ( $occ as $o ) {
			if ( 'shortener' === $o->source_type ) {
				$sources[] = esc_html__( 'Short link', 'acps-link-shortener' ) . ' /' . esc_html( $o->anchor );
			} elseif ( 'post' === $o->source_type ) {
				$sources[] = '<a href="' . esc_url( get_edit_post_link( $o->source_id ) ) . '">' . esc_html( get_the_title( $o->source_id ) ? get_the_title( $o->source_id ) : ( 'post #' . $o->source_id ) ) . '</a>';
			} elseif ( 'comment' === $o->source_type ) {
				$sources[] = esc_html__( 'Comment', 'acps-link-shortener' ) . ' #' . (int) $o->source_id;
			}
		}
		$sources = array_slice( $sources, 0, 4 );
		?>
		<tr>
			<td>
				<a href="<?php echo esc_url( $row->url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $row->url ); ?></a>
				<?php if ( $row->final_url ) : ?>
					<br /><span class="description">&rarr; <?php echo esc_html( $row->final_url ); ?></span>
				<?php endif; ?>
				<?php if ( $flag ) : ?>
					<br /><span class="description" style="color:#8a6d00;">&#9873; <?php echo esc_html( sprintf( __( 'matches flag rule: %s', 'acps-link-shortener' ), $flag['pattern'] ) ); ?></span>
				<?php endif; ?>
			</td>
			<td>
				<span style="font-weight:600;color:<?php echo esc_attr( $b[0] ); ?>;"><?php echo esc_html( $b[1] ); ?></span>
				<?php if ( $row->http_code ) : ?><br /><span class="description"><?php echo esc_html( 'HTTP ' . (int) $row->http_code ); ?></span><?php endif; ?>
				<?php if ( $row->status_text && 0 === (int) $row->http_code ) : ?><br /><span class="description"><?php echo esc_html( $row->status_text ); ?></span><?php endif; ?>
			</td>
			<td>
				<?php echo wp_kses_post( implode( '<br />', $sources ) ); ?>
				<?php if ( (int) $row->occ_count > count( $sources ) ) : ?>
					<br /><span class="description"><?php printf( esc_html__( '+%d more', 'acps-link-shortener' ), (int) $row->occ_count - count( $sources ) ); ?></span>
				<?php endif; ?>
			</td>
			<td>
				<form method="post" action="<?php echo esc_url( $this->checker_url() ); ?>" style="display:inline;">
					<?php wp_nonce_field( 'acps_ls_checker', 'acps_ls_checker_nonce' ); ?>
					<input type="hidden" name="acps_ls_checker_action" value="recheck" />
					<input type="hidden" name="url_id" value="<?php echo (int) $row->id; ?>" />
					<input type="hidden" name="return_state" value="<?php echo esc_attr( $state ); ?>" />
					<button type="submit" class="button button-small"><?php esc_html_e( 'Recheck', 'acps-link-shortener' ); ?></button>
				</form>
				<form method="post" action="<?php echo esc_url( $this->checker_url() ); ?>" style="display:flex;gap:.25rem;margin-top:.35rem;">
					<?php wp_nonce_field( 'acps_ls_checker', 'acps_ls_checker_nonce' ); ?>
					<input type="hidden" name="acps_ls_checker_action" value="replace" />
					<input type="hidden" name="url_hash" value="<?php echo esc_attr( $row->url_hash ); ?>" />
					<input type="hidden" name="return_state" value="<?php echo esc_attr( $state ); ?>" />
					<label class="screen-reader-text" for="acps-ls-new-<?php echo (int) $row->id; ?>"><?php esc_html_e( 'Replacement URL', 'acps-link-shortener' ); ?></label>
					<input type="url" id="acps-ls-new-<?php echo (int) $row->id; ?>" name="new_url" class="regular-text" style="max-width:170px;" placeholder="<?php esc_attr_e( 'replace with…', 'acps-link-shortener' ); ?>" />
					<button type="submit" class="button button-small"><?php esc_html_e( 'Replace', 'acps-link-shortener' ); ?></button>
				</form>
				<div style="margin-top:.35rem;font-size:.85em;">
					<?php if ( 'redirect' === $row->state && $row->final_url ) : ?>
						<?php $this->row_action_link( 'fix_redirect', __( 'Fix redirect', 'acps-link-shortener' ), $row, $state ); ?> |
					<?php endif; ?>
					<?php $this->row_action_link( 'unlink', __( 'Unlink', 'acps-link-shortener' ), $row, $state, __( 'Remove this link from your content, keeping the text?', 'acps-link-shortener' ) ); ?> |
					<?php if ( $row->false_positive ) : ?>
						<span class="description"><?php esc_html_e( 'marked OK', 'acps-link-shortener' ); ?></span> |
					<?php else : ?>
						<?php $this->row_action_link( 'not_broken', __( 'Not broken', 'acps-link-shortener' ), $row, $state ); ?> |
					<?php endif; ?>
					<?php if ( $row->dismissed ) : ?>
						<?php $this->row_action_link( 'restore', __( 'Restore', 'acps-link-shortener' ), $row, $state ); ?>
					<?php else : ?>
						<?php $this->row_action_link( 'dismiss', __( 'Dismiss', 'acps-link-shortener' ), $row, $state ); ?>
					<?php endif; ?>
				</div>
			</td>
		</tr>
		<?php
	}

	/**
	 * A small inline link-styled POST action inside a checker row.
	 *
	 * @param string $action  Action key.
	 * @param string $label   Label.
	 * @param object $row      URL row.
	 * @param string $state   Current state filter.
	 * @param string $confirm Optional confirm message.
	 */
	private function row_action_link( $action, $label, $row, $state, $confirm = '' ) {
		$onsubmit = $confirm ? ' onsubmit="return confirm(\'' . esc_js( $confirm ) . '\');"' : '';
		?>
		<form method="post" action="<?php echo esc_url( $this->checker_url() ); ?>" style="display:inline;"<?php echo $onsubmit; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<?php wp_nonce_field( 'acps_ls_checker', 'acps_ls_checker_nonce' ); ?>
			<input type="hidden" name="acps_ls_checker_action" value="<?php echo esc_attr( $action ); ?>" />
			<input type="hidden" name="url_id" value="<?php echo (int) $row->id; ?>" />
			<input type="hidden" name="url_hash" value="<?php echo esc_attr( $row->url_hash ); ?>" />
			<input type="hidden" name="return_state" value="<?php echo esc_attr( $state ); ?>" />
			<button type="submit" class="button-link" style="color:#2271b1;"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * Register + render the dashboard "Broken links" widget.
	 */
	public function maybe_add_dashboard_widget() {
		try {
			$settings = ACPS_LS_Checker::settings();
			if ( empty( $settings['widget_enabled'] ) || ! current_user_can( acps_ls_manage_capability() ) ) {
				return;
			}
			wp_add_dashboard_widget(
				'acps_ls_broken_links',
				__( 'Broken links', 'acps-link-shortener' ),
				array( $this, 'render_dashboard_widget' )
			);
		} catch ( Throwable $e ) {
			if ( function_exists( 'acps_ls_log_error' ) ) {
				acps_ls_log_error( 'dashboard widget', $e );
			}
		}
	}

	/**
	 * Dashboard widget body.
	 */
	public function render_dashboard_widget() {
		try {
			$counts = ACPS_LS_Checker::counts();
		} catch ( Throwable $e ) {
			if ( function_exists( 'acps_ls_log_error' ) ) {
				acps_ls_log_error( 'dashboard widget render', $e );
			}
			echo '<p>' . esc_html__( 'Link status is temporarily unavailable.', 'acps-link-shortener' ) . '</p>';
			return;
		}
		$url    = $this->checker_url();
		echo '<p>';
		if ( $counts['broken'] > 0 ) {
			printf(
				wp_kses_post( _n( '<strong>%1$d</strong> broken link found. <a href="%2$s">Review</a>', '<strong>%1$d</strong> broken links found. <a href="%2$s">Review</a>', $counts['broken'], 'acps-link-shortener' ) ),
				(int) $counts['broken'],
				esc_url( add_query_arg( 'state', 'broken', $url ) )
			);
		} else {
			esc_html_e( 'No broken links detected. 🎉', 'acps-link-shortener' );
		}
		echo '</p>';
		if ( $counts['warning'] > 0 ) {
			echo '<p>' . sprintf( esc_html__( '%d warnings.', 'acps-link-shortener' ), (int) $counts['warning'] ) . ' <a href="' . esc_url( add_query_arg( 'state', 'warning', $url ) ) . '">' . esc_html__( 'View', 'acps-link-shortener' ) . '</a></p>';
		}
	}
}
