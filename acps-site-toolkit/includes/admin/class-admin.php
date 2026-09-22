<?php
/**
 * Admin controller: the plugin's top-level menu and its subpages (spec §9),
 * plus the Settings page (registered under the WordPress Settings menu) and
 * the POST/AJAX handlers behind them.
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
		add_action( 'admin_post_acps_st_check_update', array( $this, 'handle_check_update' ) );
		add_action( 'wp_ajax_acps_st_active', array( $this, 'ajax_active' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'dashboard_widget' ) );
	}

	/**
	 * Register the "At a glance" style dashboard widget — a compact list of forms
	 * with their unread + total submission counts, à la Gravity Forms.
	 */
	public function dashboard_widget() {
		if ( ! current_user_can( Settings::CAP_READ ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_add_dashboard_widget(
			'acps_st_dashboard',
			__( 'Cayden Form Manager', 'acps-site-toolkit' ),
			array( $this, 'render_dashboard_widget' )
		);
	}

	/**
	 * Render the dashboard widget: Title / Unread / Total per form, newest and
	 * busiest first, with a "View all forms" button.
	 */
	public function render_dashboard_widget() {
		$forms = Form::all();
		if ( ! $forms ) {
			echo '<p>' . esc_html__( 'No forms yet.', 'acps-site-toolkit' ) . '</p>';
			echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=acps-st-forms&action=new' ) ) . '">' . esc_html__( 'Create your first form', 'acps-site-toolkit' ) . '</a></p>';
			return;
		}

		$counts = Entries::counts_by_form();

		// Order: forms with unread first, then by total, then by title — so the
		// things that need attention rise to the top.
		usort(
			$forms,
			function ( $a, $b ) use ( $counts ) {
				$ca = isset( $counts[ $a->id ] ) ? $counts[ $a->id ] : array( 'total' => 0, 'unread' => 0 );
				$cb = isset( $counts[ $b->id ] ) ? $counts[ $b->id ] : array( 'total' => 0, 'unread' => 0 );
				if ( $ca['unread'] !== $cb['unread'] ) {
					return $cb['unread'] - $ca['unread'];
				}
				if ( $ca['total'] !== $cb['total'] ) {
					return $cb['total'] - $ca['total'];
				}
				return strcasecmp( $a->title, $b->title );
			}
		);

		echo '<table class="acps-dash-forms widefat striped"><thead><tr>'
			. '<th>' . esc_html__( 'Title', 'acps-site-toolkit' ) . '</th>'
			. '<th class="acps-dash-num">' . esc_html__( 'Unread', 'acps-site-toolkit' ) . '</th>'
			. '<th class="acps-dash-num">' . esc_html__( 'Total', 'acps-site-toolkit' ) . '</th>'
			. '</tr></thead><tbody>';

		foreach ( $forms as $form ) {
			$c        = isset( $counts[ $form->id ] ) ? $counts[ $form->id ] : array( 'total' => 0, 'unread' => 0 );
			$entries  = admin_url( 'admin.php?page=acps-st-entries&form_id=' . $form->id );
			$title    = $form->title ? $form->title : __( '(untitled form)', 'acps-site-toolkit' );

			echo '<tr>';
			echo '<td><a href="' . esc_url( $entries ) . '"><strong>' . esc_html( $title ) . '</strong></a></td>';
			if ( $c['unread'] > 0 ) {
				echo '<td class="acps-dash-num"><a href="' . esc_url( add_query_arg( 'status', 'new', $entries ) ) . '"><strong>' . esc_html( number_format_i18n( $c['unread'] ) ) . '</strong></a></td>';
			} else {
				echo '<td class="acps-dash-num">0</td>';
			}
			echo '<td class="acps-dash-num"><a href="' . esc_url( $entries ) . '">' . esc_html( number_format_i18n( $c['total'] ) ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '<p class="acps-dash-actions"><a class="button" href="' . esc_url( admin_url( 'admin.php?page=acps-st-forms' ) ) . '">' . esc_html__( 'View all forms', 'acps-site-toolkit' ) . '</a></p>';

		// Minimal inline styling so the widget looks right without loading the
		// full admin stylesheet on the dashboard.
		echo '<style>'
			. '#acps_st_dashboard .acps-dash-num{text-align:right;white-space:nowrap}'
			. '#acps_st_dashboard table{margin:-4px 0 8px}'
			. '#acps_st_dashboard thead th{font-style:italic;color:#50575e}'
			. '#acps_st_dashboard td,#acps_st_dashboard th{padding:8px 10px}'
			. '#acps_st_dashboard .acps-dash-actions{text-align:right;margin:0}'
			. '</style>';
	}

	/**
	 * Force a fresh update check right now (spec Part A) and refresh core's
	 * own `update_plugins` transient so the Plugins screen reflects it
	 * immediately, without waiting on the next cron run.
	 */
	public function handle_check_update() {
		$this->require_cap( 'manage_options' );
		check_admin_referer( 'acps_st_check_update' );

		$remote = false;
		if ( class_exists( '\\ACPS\\SiteToolkit\\Updater' ) ) {
			$updater = new \ACPS\SiteToolkit\Updater();
			$remote  = $updater->remote( true );
		}

		if ( function_exists( 'wp_update_plugins' ) ) {
			delete_site_transient( 'update_plugins' );
			wp_update_plugins();
		}

		$found = ( $remote && ! empty( $remote['version'] ) && version_compare( $remote['version'], ACPS_ST_VERSION, '>' ) );
		$url   = add_query_arg(
			array(
				'checked' => 1,
				'found'   => $found ? 1 : 0,
			),
			self::settings_url()
		);
		wp_safe_redirect( $url . '#acps-tab-updates' );
		exit;
	}

	/**
	 * Repair or reset the plugin database tables.
	 */
	public function handle_db_action() {
		$this->require_cap( 'manage_options' );
		check_admin_referer( 'acps_st_db_action' );

		// Accept the action from POST (settings form) or GET (the save-failure
		// admin notice's Repair button). Both are nonce-verified above.
		$do  = isset( $_REQUEST['do'] ) ? sanitize_key( wp_unslash( $_REQUEST['do'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
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

		wp_safe_redirect( self::settings_url() . ( $msg ? '&db=' . $msg : '' ) );
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

		wp_send_json_success(
			array(
				'total' => \ACPS\SiteToolkit\Analytics::active_count( 5 ),
				'pages' => $out,
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
		}
		// Visitors: shown when unique-user tracking is on, OR when device
		// fingerprinting is on (so the device data it collects is viewable).
		if ( ( Settings::get( 'analytics_enabled' ) && Settings::get( 'track_visitors' ) ) || Settings::get( 'device_fp_enabled' ) ) {
			add_submenu_page( self::SLUG, __( 'Visitors', 'acps-site-toolkit' ), __( 'Visitors', 'acps-site-toolkit' ), 'manage_options', self::SLUG . '-visitors', array( $this, 'render_visitors' ) );
		}
		add_submenu_page( self::SLUG, __( 'Q&A / Help', 'acps-site-toolkit' ), __( 'Q&A / Help', 'acps-site-toolkit' ), 'manage_options', self::SLUG . '-qa', array( $this, 'render_qa' ) );
		add_submenu_page( self::SLUG, __( 'Help Guide', 'acps-site-toolkit' ), __( 'Help Guide', 'acps-site-toolkit' ), $reports, self::SLUG . '-help', array( $this, 'render_help' ) );

		// Settings lives under the WordPress “Settings” menu (Settings → Cayden
		// Form Manager), not the plugin’s own menu.
		add_options_page(
			__( 'Cayden Form Manager', 'acps-site-toolkit' ),
			__( 'Cayden Form Manager', 'acps-site-toolkit' ),
			'manage_options',
			self::SLUG . '-settings',
			array( $this, 'render_settings' )
		);
	}

	/**
	 * The admin URL of the settings page (now under Settings → …).
	 *
	 * @return string
	 */
	public static function settings_url() {
		return admin_url( 'options-general.php?page=' . self::SLUG . '-settings' );
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

		// Google Forms bridge (forward submissions to a backing Google Form).
		$s['gforms_bridge'] = array(
			'enabled' => ! empty( $_POST['settings']['gforms_enabled'] ) ? 1 : 0,
			'url'     => isset( $_POST['settings']['gforms_url'] ) ? esc_url_raw( wp_unslash( $_POST['settings']['gforms_url'] ) ) : '',
		);

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
			$new_status = sanitize_key( wp_unslash( $_POST['status'] ) );
			Entries::set_status( $id, $new_status );

			// Optionally email the submitter about the new status.
			if ( ! empty( $_POST['notify_submitter'] ) ) {
				$message = isset( $_POST['notify_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notify_message'] ) ) : '';
				$result  = \ACPS\SiteToolkit\Notifications::send_status_update( $id, $new_status, $message );
				$return  = add_query_arg( 'emailed', $result, $return );
			}
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

	/**
	 * Interactive-tour definitions handed to admin-tour.js. Each tour is a list
	 * of steps: el (CSS selector to spotlight, optional), title, html, side.
	 * A step whose element isn't on the current screen shows as a centered card,
	 * so tours never break when a feature is toggled off.
	 *
	 * @return array
	 */
	public static function tour_data() {
		$forms_new = admin_url( 'admin.php?page=acps-st-forms&action=new' );

		$tours = array(
			// A gentle orientation that works on any of our screens.
			'overview' => array(
				'label' => __( 'Take the 2-minute tour', 'acps-site-toolkit' ),
				'steps' => array(
					array(
						'title' => __( 'Welcome! 👋', 'acps-site-toolkit' ),
						'html'  => '<p>' . esc_html__( 'This quick tour shows you the whole plugin in about two minutes. Use Next and Back, or your arrow keys. Press Esc any time to stop.', 'acps-site-toolkit' ) . '</p>',
					),
					array(
						'el'    => '#toplevel_page_acps-st',
						'side'  => 'right',
						'title' => __( 'Everything lives here', 'acps-site-toolkit' ),
						'html'  => '<p>' . esc_html__( 'This is your menu. Feedback, Forms, Entries, Analytics, Visitors, Q&A and the Help Guide are all under it.', 'acps-site-toolkit' ) . '</p>',
					),
					array(
						'title' => __( 'Build forms', 'acps-site-toolkit' ),
						'html'  => '<p>' . esc_html__( 'Under Forms you build forms by clicking field types — no code. You can also import a Google Form and even keep filing responses back to it.', 'acps-site-toolkit' ) . '</p><p>' . esc_html__( 'The Forms screen has its own “Show me how” button that walks you through building one.', 'acps-site-toolkit' ) . '</p>',
					),
					array(
						'title' => __( 'The “Chat with us” button', 'acps-site-toolkit' ),
						'html'  => '<p>' . esc_html__( 'A floating button on your site opens a contact form that emails your team. Change its look, position and icon under Settings → Feedback.', 'acps-site-toolkit' ) . '</p>',
					),
					array(
						'title' => __( 'One important habit: clear the cache', 'acps-site-toolkit' ),
						'html'  => '<p>' . esc_html__( 'Your site is cached. After you change a setting or upload files, purge the cache (and on WP Engine, Restart PHP) so visitors see the change.', 'acps-site-toolkit' ) . '</p>',
					),
					array(
						'title' => __( 'You’re set 🎉', 'acps-site-toolkit' ),
						'html'  => '<p>' . esc_html__( 'The Help Guide page has step-by-step guides with pictures and more tours whenever you need them. Have fun!', 'acps-site-toolkit' ) . '</p>',
					),
				),
			),

			// The main event: building a form, pointing at real controls.
			'build-form' => array(
				'label' => __( 'Show me how to build a form', 'acps-site-toolkit' ),
				'steps' => array(
					array(
						'title' => __( 'Let’s build a form together', 'acps-site-toolkit' ),
						'html'  => '<p>' . esc_html__( 'I’ll point at each part of the builder. Follow along — you can add real fields as we go.', 'acps-site-toolkit' ) . '</p>',
					),
					array(
						'el'    => '.acps-builder-topbar',
						'side'  => 'bottom',
						'title' => __( '1. Name it, set its status', 'acps-site-toolkit' ),
						'html'  => '<p>' . esc_html__( 'Give the form a title. Leave Status as Draft while you work; switch it to Published when it’s ready to go live.', 'acps-site-toolkit' ) . '</p>',
					),
					array(
						'el'    => '.acps-pane--types',
						'side'  => 'right',
						'title' => __( '2. Add fields', 'acps-site-toolkit' ),
						'html'  => '<p>' . esc_html__( 'Click any field type — Short text, Email, Dropdown and so on — to add it to your form. They’re grouped so they’re easy to find.', 'acps-site-toolkit' ) . '</p>',
					),
					array(
						'el'    => '#acps-canvas',
						'side'  => 'left',
						'title' => __( '3. Your form', 'acps-site-toolkit' ),
						'html'  => '<p>' . esc_html__( 'Fields you add appear here. Reorder them with the ▲ ▼ buttons or the number box — no dragging needed. Click a field to edit it.', 'acps-site-toolkit' ) . '</p>',
					),
					array(
						'el'    => '#acps-field-settings',
						'side'  => 'left',
						'title' => __( '4. Edit the selected field', 'acps-site-toolkit' ),
						'html'  => '<p>' . esc_html__( 'Change the label, help text, whether it’s required, its options, and more. Advanced: show/hide a field based on another answer.', 'acps-site-toolkit' ) . '</p>',
					),
					array(
						'el'    => '#acps-preview-toggle',
						'side'  => 'bottom',
						'title' => __( '5. Preview', 'acps-site-toolkit' ),
						'html'  => '<p>' . esc_html__( 'See how the form will look to visitors, then switch back to keep editing.', 'acps-site-toolkit' ) . '</p>',
					),
					array(
						'el'    => '.acps-builder-formsettings',
						'side'  => 'top',
						'title' => __( '6. Form settings', 'acps-site-toolkit' ),
						'html'  => '<p>' . esc_html__( 'Below the builder: the confirmation message, who gets notified, an auto-reply to the submitter, response limits, and access control (login, password, or a private link).', 'acps-site-toolkit' ) . '</p>',
					),
					array(
						'el'    => '#acps-gforms',
						'side'  => 'top',
						'title' => __( '7. Google Form bridge (optional)', 'acps-site-toolkit' ),
						'html'  => '<p>' . esc_html__( 'Want responses to also land in a Google Form? Turn this on and paste the Google Form link. If you imported from Google, it’s already set up.', 'acps-site-toolkit' ) . '</p>',
					),
					array(
						'el'    => '.acps-builder-actions .button-primary',
						'side'  => 'bottom',
						'title' => __( '8. Save', 'acps-site-toolkit' ),
						'html'  => '<p>' . esc_html__( 'Set Status to Published and click Save form. That’s it — your form exists!', 'acps-site-toolkit' ) . '</p>',
					),
					array(
						'title' => __( 'Last step: put it on a page', 'acps-site-toolkit' ),
						'html'  => '<p>' . esc_html__( 'Copy the form’s shortcode from the Forms list (it looks like [acps_form id="12"]) and paste it into any page or post. Or use the “ACPS Form” block / Beaver module.', 'acps-site-toolkit' ) . '</p>',
					),
				),
			),

			// Working the feedback inbox.
			'feedback-inbox' => array(
				'label' => __( 'Show me the Feedback inbox', 'acps-site-toolkit' ),
				'steps' => array(
					array(
						'title' => __( 'The Feedback inbox', 'acps-site-toolkit' ),
						'html'  => '<p>' . esc_html__( 'Every message from your forms lands here. Let me show you how to work through it.', 'acps-site-toolkit' ) . '</p>',
					),
					array(
						'el'    => '#acps-inbox-form',
						'side'  => 'bottom',
						'title' => __( 'Switch which form you’re reading', 'acps-site-toolkit' ),
						'html'  => '<p>' . esc_html__( 'This inbox can show any form’s submissions — pick a form here to triage it with the same tools.', 'acps-site-toolkit' ) . '</p>',
					),
					array(
						'el'    => '#acps-filter-status',
						'side'  => 'bottom',
						'title' => __( 'Filter by status', 'acps-site-toolkit' ),
						'html'  => '<p>' . esc_html__( 'Narrow the list to New, In progress, Resolved, and so on.', 'acps-site-toolkit' ) . '</p>',
					),
					array(
						'el'    => '.page-title-action',
						'side'  => 'bottom',
						'title' => __( 'Export to a spreadsheet', 'acps-site-toolkit' ),
						'html'  => '<p>' . esc_html__( 'Download everything as a CSV to open in Excel or Google Sheets.', 'acps-site-toolkit' ) . '</p>',
					),
					array(
						'title' => __( 'Open one to reply', 'acps-site-toolkit' ),
						'html'  => '<p>' . esc_html__( 'Click any row to read the full message, set its status (which can email the person back), assign it to a teammate, and add private notes.', 'acps-site-toolkit' ) . '</p>',
					),
				),
			),
		);

		return array(
			'tours' => $tours,
			'i18n'  => array(
				'next'         => __( 'Next', 'acps-site-toolkit' ),
				'back'         => __( 'Back', 'acps-site-toolkit' ),
				'done'         => __( 'Done', 'acps-site-toolkit' ),
				'close'        => __( 'End tour', 'acps-site-toolkit' ),
				'step'         => __( 'Step', 'acps-site-toolkit' ),
				'welcomeTitle' => __( 'New to Cayden Form Manager?', 'acps-site-toolkit' ),
				'welcomeBody'  => __( 'Take a quick guided tour and I’ll show you around — it takes about two minutes.', 'acps-site-toolkit' ),
				'welcomeGo'    => __( 'Take the tour', 'acps-site-toolkit' ),
				'welcomeSkip'  => __( 'Maybe later', 'acps-site-toolkit' ),
			),
			'forms_new_url' => $forms_new,
		);
	}
}
