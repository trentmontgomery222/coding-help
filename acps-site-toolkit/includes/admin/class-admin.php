<?php
/**
 * Admin controller: the single top-level menu and its five subpages
 * (spec §9), plus the POST/AJAX handlers behind them.
 *
 * No Network Admin presence; no manage_network checks (spec §9.1).
 *
 * @package ACPS\SiteToolkit
 */

namespace ACPS\SiteToolkit\Admin;

use ACPS\SiteToolkit\Settings;
use ACPS\SiteToolkit\Form;
use ACPS\SiteToolkit\Entries;
use ACPS\SiteToolkit\Field_Types;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin.
 */
class Admin {

	const SLUG = 'acps-st';

	/**
	 * Register admin hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_acps_st_save_form', array( $this, 'handle_save_form' ) );
		add_action( 'admin_post_acps_st_form_action', array( $this, 'handle_form_action' ) );
		add_action( 'admin_post_acps_st_entry_action', array( $this, 'handle_entry_action' ) );
		add_action( 'admin_post_acps_st_export', array( $this, 'handle_export' ) );
		add_action( 'admin_post_acps_st_save_qa', array( $this, 'handle_save_qa' ) );
		add_action( 'admin_post_acps_st_import_google', array( $this, 'handle_import_google' ) );
		add_action( 'admin_post_acps_st_visitor_action', array( $this, 'handle_visitor_action' ) );
		add_action( 'admin_post_acps_st_db_action', array( $this, 'handle_db_action' ) );
		add_action( 'wp_ajax_acps_st_active', array( $this, 'ajax_active' ) );
	}

	/**
	 * Repair or reset the plugin database tables.
	 */
	public function handle_db_action() {
		$this->require_cap( 'manage_options' );
		check_admin_referer( 'acps_st_db_action' );

		$do  = isset( $_POST['do'] ) ? sanitize_key( $_POST['do'] ) : '';
		$msg = '';

		if ( 'repair' === $do ) {
			// Non-destructive: creates any missing tables/columns.
			\ACPS\SiteToolkit\Schema::install();
			$msg = 'repaired';
		} elseif ( 'reset' === $do ) {
			// Destructive: drop everything and rebuild empty, then recreate the
			// built-in forms. Settings option is preserved.
			\ACPS\SiteToolkit\Schema::drop_all();
			\ACPS\SiteToolkit\Schema::install();
			\ACPS\SiteToolkit\Feedback::ensure_feedback_form();
			\ACPS\SiteToolkit\Help::ensure_contact_form();
			\ACPS\SiteToolkit\Help::ensure_media_request_form();
			$msg = 'reset';
		}

		wp_safe_redirect( admin_url( 'admin.php?page=acps-st-settings' . ( $msg ? '&db=' . $msg : '' ) ) );
		exit;
	}

	/**
	 * AJAX: return the live "who's on the site now" data for auto-refresh.
	 */
	public function ajax_active() {
		if ( ! current_user_can( $this->reports_cap() ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		check_ajax_referer( 'acps_st_admin', 'nonce' );

		$pages = \ACPS\SiteToolkit\Analytics::active_pages( 5 );
		$out   = array();
		foreach ( $pages as $p ) {
			$out[] = array(
				'title' => $p['title'],
				'count' => (int) $p['count'],
			);
		}

		$staff     = \ACPS\SiteToolkit\Presence::active( 5 );
		$staff_out = array();
		foreach ( $staff as $p ) {
			$staff_out[] = array(
				'name' => $p['name'],
				'page' => $p['title'] ? $p['title'] : $p['url'],
			);
		}

		wp_send_json_success(
			array(
				'total' => \ACPS\SiteToolkit\Analytics::active_count( 5 ),
				'pages' => $out,
				'staff' => $staff_out,
				'time'  => date_i18n( get_option( 'time_format' ) ),
			)
		);
	}

	/**
	 * Access cap for reports screens (feedback + analytics): read-only cap OR
	 * manage_options. Builder/entries/settings require manage_options.
	 *
	 * @return string
	 */
	private function reports_cap() {
		return Settings::CAP_READ;
	}

	/**
	 * Build the menu.
	 */
	public function menu() {
		$reports = $this->reports_cap();

		add_menu_page(
			__( 'Cayden Form Manager', 'acps-site-toolkit' ),
			__( 'Cayden Form Manager', 'acps-site-toolkit' ),
			$reports,
			self::SLUG,
			array( $this, 'render_feedback' ),
			'dashicons-feedback',
			58
		);

		add_submenu_page( self::SLUG, __( 'Feedback', 'acps-site-toolkit' ), __( 'Feedback', 'acps-site-toolkit' ), $reports, self::SLUG, array( $this, 'render_feedback' ) );
		add_submenu_page( self::SLUG, __( 'Forms', 'acps-site-toolkit' ), __( 'Forms', 'acps-site-toolkit' ), 'manage_options', self::SLUG . '-forms', array( $this, 'render_forms' ) );
		add_submenu_page( self::SLUG, __( 'Entries', 'acps-site-toolkit' ), __( 'Entries', 'acps-site-toolkit' ), 'manage_options', self::SLUG . '-entries', array( $this, 'render_entries' ) );
		// Analytics + Visitors appear per their toggles.
		if ( Settings::get( 'analytics_enabled' ) ) {
			add_submenu_page( self::SLUG, __( 'Analytics', 'acps-site-toolkit' ), __( 'Analytics', 'acps-site-toolkit' ), $reports, self::SLUG . '-analytics', array( $this, 'render_analytics' ) );
			if ( Settings::get( 'track_visitors' ) ) {
				add_submenu_page( self::SLUG, __( 'Visitors', 'acps-site-toolkit' ), __( 'Visitors', 'acps-site-toolkit' ), 'manage_options', self::SLUG . '-visitors', array( $this, 'render_visitors' ) );
			}
		}
		add_submenu_page( self::SLUG, __( 'Q&A / Help', 'acps-site-toolkit' ), __( 'Q&A / Help', 'acps-site-toolkit' ), 'manage_options', self::SLUG . '-qa', array( $this, 'render_qa' ) );
		add_submenu_page( self::SLUG, __( 'Settings', 'acps-site-toolkit' ), __( 'Settings', 'acps-site-toolkit' ), 'manage_options', self::SLUG . '-settings', array( $this, 'render_settings' ) );
		add_submenu_page( self::SLUG, __( 'Help Guide', 'acps-site-toolkit' ), __( 'Help Guide', 'acps-site-toolkit' ), $reports, self::SLUG . '-help', array( $this, 'render_help' ) );
	}

	/* ------------------------------------------------------------------ *
	 * Page renderers (delegate to view files in includes/admin/views).
	 * ------------------------------------------------------------------ */

	/**
	 * Feedback inbox (spec §5.6).
	 */
	public function render_feedback() {
		$this->require_cap( $this->reports_cap() );
		require ACPS_ST_PATH . 'includes/admin/views/feedback.php';
	}

	/**
	 * Forms list + builder (spec §7.7).
	 */
	public function render_forms() {
		$this->require_cap( 'manage_options' );
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'edit' === $action || 'new' === $action ) {
			require ACPS_ST_PATH . 'includes/admin/views/form-builder.php';
		} elseif ( 'import' === $action ) {
			require ACPS_ST_PATH . 'includes/admin/views/import-google.php';
		} else {
			require ACPS_ST_PATH . 'includes/admin/views/forms-list.php';
		}
	}

	/**
	 * Entries (spec §7.6).
	 */
	public function render_entries() {
		$this->require_cap( 'manage_options' );
		require ACPS_ST_PATH . 'includes/admin/views/entries.php';
	}

	/**
	 * Analytics (spec §6).
	 */
	public function render_analytics() {
		$this->require_cap( $this->reports_cap() );
		require ACPS_ST_PATH . 'includes/admin/views/analytics.php';
	}

	/**
	 * Q&A / Help management.
	 */
	public function render_qa() {
		$this->require_cap( 'manage_options' );
		require ACPS_ST_PATH . 'includes/admin/views/qa.php';
	}

	/**
	 * Settings (spec §9.2).
	 */
	public function render_settings() {
		$this->require_cap( 'manage_options' );
		require ACPS_ST_PATH . 'includes/admin/views/settings.php';
	}

	/**
	 * Visitors list + single visitor.
	 */
	public function render_visitors() {
		$this->require_cap( 'manage_options' );
		require ACPS_ST_PATH . 'includes/admin/views/visitors.php';
	}

	/**
	 * Save a visitor's name / notes.
	 */
	public function handle_visitor_action() {
		$this->require_cap( 'manage_options' );
		check_admin_referer( 'acps_st_visitor_action' );

		$uid = isset( $_POST['uid'] ) ? sanitize_text_field( wp_unslash( $_POST['uid'] ) ) : '';
		if ( isset( $_POST['name'] ) ) {
			\ACPS\SiteToolkit\Visitors::set_name( $uid, wp_unslash( $_POST['name'] ) ); // phpcs:ignore
		}
		if ( isset( $_POST['notes'] ) ) {
			\ACPS\SiteToolkit\Visitors::set_notes( $uid, wp_unslash( $_POST['notes'] ) ); // phpcs:ignore
		}
		wp_safe_redirect( admin_url( 'admin.php?page=acps-st-visitors&visitor=' . rawurlencode( $uid ) . '&saved=1' ) );
		exit;
	}

	/**
	 * Built-in Help Guide.
	 */
	public function render_help() {
		$this->require_cap( $this->reports_cap() );
		require ACPS_ST_PATH . 'includes/admin/views/help-guide.php';
	}

	/**
	 * Save the Q&A items.
	 */
	public function handle_save_qa() {
		$this->require_cap( 'manage_options' );
		check_admin_referer( 'acps_st_save_qa' );

		$questions = isset( $_POST['q'] ) ? (array) wp_unslash( $_POST['q'] ) : array(); // phpcs:ignore
		$answers   = isset( $_POST['a'] ) ? (array) wp_unslash( $_POST['a'] ) : array(); // phpcs:ignore

		$items = array();
		foreach ( $questions as $i => $q ) {
			$items[] = array(
				'q' => $q,
				'a' => isset( $answers[ $i ] ) ? $answers[ $i ] : '',
			);
		}
		\ACPS\SiteToolkit\Help::save_qa( $items );

		wp_safe_redirect( admin_url( 'admin.php?page=acps-st-qa&saved=1' ) );
		exit;
	}

	/**
	 * Import a Google Form into a new draft form.
	 */
	public function handle_import_google() {
		$this->require_cap( 'manage_options' );
		check_admin_referer( 'acps_st_import_google' );

		$url  = isset( $_POST['gform_url'] ) ? esc_url_raw( wp_unslash( $_POST['gform_url'] ) ) : '';
		$html = isset( $_POST['gform_html'] ) ? wp_unslash( $_POST['gform_html'] ) : ''; // phpcs:ignore

		$result = \ACPS\SiteToolkit\Google_Forms_Importer::import( $url, $html );

		if ( is_wp_error( $result ) ) {
			$redirect = add_query_arg(
				array(
					'page'         => 'acps-st-forms',
					'action'       => 'import',
					'import_error' => rawurlencode( $result->get_error_message() ),
				),
				admin_url( 'admin.php' )
			);
			wp_safe_redirect( $redirect );
			exit;
		}

		// Success → open the new draft in the builder.
		wp_safe_redirect( admin_url( 'admin.php?page=acps-st-forms&action=edit&form=' . (int) $result . '&imported=1' ) );
		exit;
	}

	/* ------------------------------------------------------------------ *
	 * Handlers.
	 * ------------------------------------------------------------------ */

	/**
	 * Save (create/update) a form from the builder.
	 */
	public function handle_save_form() {
		$this->require_cap( 'manage_options' );
		check_admin_referer( 'acps_st_save_form' );

		$id   = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
		$form = $id ? Form::find( $id ) : new Form();
		if ( ! $form ) {
			$form = new Form();
		}

		$form->title  = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : $form->title;
		$form->status = ( isset( $_POST['status'] ) && 'published' === $_POST['status'] ) ? 'published' : 'draft';

		// Fields arrive as a JSON blob maintained by the builder JS.
		if ( isset( $_POST['fields_json'] ) ) {
			$decoded = json_decode( wp_unslash( $_POST['fields_json'] ), true ); // phpcs:ignore
			if ( is_array( $decoded ) ) {
				$form->fields = Field_Types::normalize_list( $decoded );
			}
		}

		// Form-level settings.
		$s = $form->settings;
		foreach ( array( 'confirmation_type', 'confirmation_message', 'confirmation_redirect', 'notify_subject', 'autoreply_subject', 'autoreply_body', 'autoreply_field', 'notify_recipients', 'submit_label' ) as $k ) {
			if ( isset( $_POST['settings'][ $k ] ) ) {
				$val      = wp_unslash( $_POST['settings'][ $k ] ); // phpcs:ignore
				$s[ $k ]  = in_array( $k, array( 'confirmation_message', 'autoreply_body' ), true ) ? wp_kses_post( $val ) : sanitize_text_field( $val );
			}
		}
		$s['notify_admin']      = ! empty( $_POST['settings']['notify_admin'] ) ? 1 : 0;
		$s['autoreply_enable']  = ! empty( $_POST['settings']['autoreply_enable'] ) ? 1 : 0;
		$s['multipage']         = ! empty( $_POST['settings']['multipage'] ) ? 1 : 0;
		$s['limit_per_device']  = isset( $_POST['settings']['limit_per_device'] ) ? absint( $_POST['settings']['limit_per_device'] ) : 0;
		$s['limit_total']       = isset( $_POST['settings']['limit_total'] ) ? absint( $_POST['settings']['limit_total'] ) : 0;
		$s['limit_message']     = isset( $_POST['settings']['limit_message'] ) ? sanitize_text_field( wp_unslash( $_POST['settings']['limit_message'] ) ) : '';
		if ( isset( $_POST['settings']['style_accent'] ) ) {
			$s['style']['accent'] = sanitize_text_field( wp_unslash( $_POST['settings']['style_accent'] ) );
		}

		// Access control (login/roles, password, secret link).
		$in_access = isset( $_POST['settings']['access'] ) && is_array( $_POST['settings']['access'] ) ? wp_unslash( $_POST['settings']['access'] ) : array(); // phpcs:ignore
		$access    = isset( $s['access'] ) && is_array( $s['access'] ) ? $s['access'] : \ACPS\SiteToolkit\Access::defaults();

		$access['require_login']    = empty( $in_access['require_login'] ) ? 0 : 1;
		$access['require_password'] = empty( $in_access['require_password'] ) ? 0 : 1;
		$access['require_token']    = empty( $in_access['require_token'] ) ? 0 : 1;
		$access['roles']            = ! empty( $in_access['roles'] ) ? array_map( 'sanitize_key', (array) $in_access['roles'] ) : array();
		$access['page_id']          = isset( $in_access['page_id'] ) ? absint( $in_access['page_id'] ) : 0;
		$access['denied_message']   = isset( $in_access['denied_message'] ) ? sanitize_text_field( $in_access['denied_message'] ) : '';

		// Password: hash a newly-typed one; otherwise keep the existing hash.
		if ( ! empty( $in_access['password'] ) ) {
			$access['password_hash'] = wp_hash_password( (string) $in_access['password'] );
		}
		if ( ! $access['require_password'] ) {
			// Turning the gate off clears the stored password.
			$access['password_hash'] = '';
		}

		// Secret link token: generate when enabling (or regenerating); clear off.
		if ( $access['require_token'] ) {
			if ( empty( $access['token'] ) || ! empty( $in_access['regenerate_token'] ) ) {
				$access['token'] = \ACPS\SiteToolkit\Access::generate_token();
			}
		} else {
			$access['token'] = '';
		}

		$s['access']    = $access;
		$form->settings = $s;

		$form->save();

		wp_safe_redirect( admin_url( 'admin.php?page=acps-st-forms&action=edit&form=' . $form->id . '&saved=1' ) );
		exit;
	}

	/**
	 * Duplicate / delete a form.
	 */
	public function handle_form_action() {
		$this->require_cap( 'manage_options' );
		check_admin_referer( 'acps_st_form_action' );

		$id  = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
		$do  = isset( $_POST['do'] ) ? sanitize_key( $_POST['do'] ) : '';

		if ( 'duplicate' === $do ) {
			Form::duplicate( $id );
		} elseif ( 'delete' === $do ) {
			$form = Form::find( $id );
			if ( $form && ! $form->is_feedback ) { // never delete the feedback form here.
				Form::delete( $id );
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=acps-st-forms' ) );
		exit;
	}

	/**
	 * Entry actions: status change, assign, add note (used by both the feedback
	 * inbox and the entries screen).
	 */
	public function handle_entry_action() {
		$this->require_cap( 'manage_options' );
		check_admin_referer( 'acps_st_entry_action' );

		$id     = isset( $_POST['entry_id'] ) ? absint( $_POST['entry_id'] ) : 0;
		$do     = isset( $_POST['do'] ) ? sanitize_key( $_POST['do'] ) : '';
		$return = isset( $_POST['return'] ) ? esc_url_raw( wp_unslash( $_POST['return'] ) ) : admin_url( 'admin.php?page=acps-st' );

		if ( 'status' === $do && isset( $_POST['status'] ) ) {
			Entries::set_status( $id, sanitize_key( wp_unslash( $_POST['status'] ) ) );
		} elseif ( 'assign' === $do ) {
			Entries::assign( $id, isset( $_POST['assigned_to'] ) ? absint( $_POST['assigned_to'] ) : 0 );
		} elseif ( 'note' === $do && ! empty( $_POST['note'] ) ) {
			Entries::add_note( $id, wp_unslash( $_POST['note'] ) ); // phpcs:ignore
		} elseif ( 'trash' === $do ) {
			Entries::set_status( $id, 'trashed' );
		} elseif ( 'delete' === $do ) {
			Entries::delete( $id );
			// If we were viewing that single entry, drop the ?entry= param.
			$return = remove_query_arg( 'entry', $return );
		} elseif ( 'bulk_delete' === $do && ! empty( $_POST['entry_ids'] ) ) {
			Entries::bulk_delete( wp_unslash( (array) $_POST['entry_ids'] ) ); // phpcs:ignore
		} elseif ( 'bulk_trash' === $do && ! empty( $_POST['entry_ids'] ) ) {
			foreach ( (array) $_POST['entry_ids'] as $bid ) { // phpcs:ignore
				Entries::set_status( absint( $bid ), 'trashed' );
			}
		}

		wp_safe_redirect( $return );
		exit;
	}

	/**
	 * CSV export for feedback / entries (spec §5.6, §6.5, §7.6).
	 */
	public function handle_export() {
		$this->require_cap( 'manage_options' );
		check_admin_referer( 'acps_st_export' );

		$form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;
		Exporter::stream_entries( $form_id );
		exit;
	}

	/**
	 * Bail with a friendly message if the user lacks a capability.
	 *
	 * @param string $cap Capability.
	 */
	private function require_cap( $cap ) {
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'acps-site-toolkit' ), 403 );
		}
	}
}
