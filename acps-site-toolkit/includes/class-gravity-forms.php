<?php
/**
 * Gravity Forms overlap.
 *
 * When Gravity Forms is installed, this plugin "overlaps" it: our extra tools
 * (Feedback triage, Analytics, Visitors, guided Help) are surfaced under the
 * Gravity Forms menu, and the dashboard widget lists Gravity Forms' own forms
 * with the same Unread / Total counts so both live in one place.
 *
 * When Gravity Forms is NOT installed, none of this runs and the plugin behaves
 * exactly as it does on its own.
 *
 * Everything here is guarded (class/method_exists) so a Gravity Forms API change
 * degrades quietly instead of breaking the screen.
 *
 * @package ACPS\SiteToolkit
 */

namespace ACPS\SiteToolkit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gravity_Forms.
 */
class Gravity_Forms {

	/** Gravity Forms' top-level admin menu slug. */
	const GF_PARENT = 'gf_edit_forms';

	/**
	 * Is Gravity Forms active?
	 *
	 * @return bool
	 */
	public static function is_active() {
		return class_exists( 'GFForms' ) || class_exists( 'GFCommon' );
	}

	/**
	 * Gravity Forms' forms, normalized with Unread / Total counts and a link to
	 * their entries — the same shape our dashboard widget uses for our own forms.
	 *
	 * @return array[] Each: id, title, total, unread, entries_url.
	 */
	public static function forms() {
		if ( ! self::is_active() || ! class_exists( 'GFAPI' ) ) {
			return array();
		}
		$out = array();
		try {
			$forms = \GFAPI::get_forms(); // active, non-trashed.
			if ( ! is_array( $forms ) ) {
				return array();
			}
			foreach ( $forms as $form ) {
				$id    = isset( $form['id'] ) ? (int) $form['id'] : 0;
				$title = isset( $form['title'] ) ? (string) $form['title'] : '';
				if ( ! $id ) {
					continue;
				}
				$total  = 0;
				$unread = 0;
				if ( class_exists( 'GFFormsModel' ) && method_exists( 'GFFormsModel', 'get_form_counts' ) ) {
					$counts = \GFFormsModel::get_form_counts( $id );
					if ( is_array( $counts ) ) {
						$total  = isset( $counts['total'] ) ? (int) $counts['total'] : 0;
						$unread = isset( $counts['unread'] ) ? (int) $counts['unread'] : 0;
					}
				}
				$out[] = array(
					'id'          => $id,
					'title'       => $title,
					'total'       => $total,
					'unread'      => $unread,
					'entries_url' => admin_url( 'admin.php?page=gf_entries&id=' . $id ),
					'edit_url'    => admin_url( 'admin.php?page=gf_edit_forms&id=' . $id ),
				);
			}
		} catch ( \Throwable $e ) {
			return array();
		}
		return $out;
	}

	/**
	 * Add our tools under the Gravity Forms menu so they overlap GF's own menu.
	 * Registered on admin_menu at a late priority so GF's parent menu exists.
	 * Uses our existing render callbacks under fresh slugs.
	 *
	 * @param Admin\Admin $admin The admin controller (for render callbacks).
	 */
	public static function register_menu( $admin ) {
		if ( ! self::is_active() ) {
			return;
		}
		$reports = Settings::CAP_READ;

		// House everything under the Gravity Forms menu. "All forms" is our
		// unified list (Gravity Forms' forms AND ours together); Entries and the
		// rest are our add-ons that GF doesn't have.
		add_submenu_page( self::GF_PARENT, __( 'All forms', 'acps-site-toolkit' ), __( 'All forms', 'acps-site-toolkit' ), 'manage_options', 'acps-st-forms', array( $admin, 'render_forms' ) );
		add_submenu_page( self::GF_PARENT, __( 'Entries (add-on)', 'acps-site-toolkit' ), __( 'Entries (add-on)', 'acps-site-toolkit' ), 'manage_options', 'acps-st-entries', array( $admin, 'render_entries' ) );
		add_submenu_page( self::GF_PARENT, __( 'Feedback inbox', 'acps-site-toolkit' ), __( 'Feedback inbox', 'acps-site-toolkit' ), $reports, 'acps-st', array( $admin, 'render_feedback' ) );

		if ( Settings::get( 'analytics_enabled' ) ) {
			add_submenu_page( self::GF_PARENT, __( 'Form analytics', 'acps-site-toolkit' ), __( 'Analytics', 'acps-site-toolkit' ), $reports, 'acps-st-analytics', array( $admin, 'render_analytics' ) );
		}
		if ( ( Settings::get( 'analytics_enabled' ) && Settings::get( 'track_visitors' ) ) || Settings::get( 'device_fp_enabled' ) ) {
			add_submenu_page( self::GF_PARENT, __( 'Visitors', 'acps-site-toolkit' ), __( 'Visitors', 'acps-site-toolkit' ), 'manage_options', 'acps-st-visitors', array( $admin, 'render_visitors' ) );
		}
		add_submenu_page( self::GF_PARENT, __( 'Q&A / Help', 'acps-site-toolkit' ), __( 'Q&A / Help', 'acps-site-toolkit' ), 'manage_options', 'acps-st-qa', array( $admin, 'render_qa' ) );
		add_submenu_page( self::GF_PARENT, __( 'Guided help', 'acps-site-toolkit' ), __( 'Guided help', 'acps-site-toolkit' ), $reports, 'acps-st-help', array( $admin, 'render_help' ) );
	}
}
