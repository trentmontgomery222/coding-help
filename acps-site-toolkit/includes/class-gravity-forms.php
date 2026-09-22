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

		// Our built-in forms are shown INSIDE Gravity Forms' own Forms page (see
		// render_builtin_on_gf) rather than a separate list. We still register the
		// built-in builder/importer + entries pages so Edit / New / Import / Entries
		// links resolve, but as hidden pages (null parent) — reachable only by URL,
		// never shown as their own menu items.
		add_submenu_page( null, __( 'Built-in form editor', 'acps-site-toolkit' ), '', 'manage_options', 'acps-st-forms', array( $admin, 'render_forms' ) );
		add_submenu_page( null, __( 'Built-in entries', 'acps-site-toolkit' ), '', 'manage_options', 'acps-st-entries', array( $admin, 'render_entries' ) );

		// Genuinely new tools that Gravity Forms doesn't have get their own items.
		add_submenu_page( self::GF_PARENT, __( 'Feedback inbox', 'acps-site-toolkit' ), __( 'Feedback inbox', 'acps-site-toolkit' ), $reports, 'acps-st', array( $admin, 'render_feedback' ) );
		if ( Settings::get( 'analytics_enabled' ) ) {
			add_submenu_page( self::GF_PARENT, __( 'Form analytics', 'acps-site-toolkit' ), __( 'Analytics', 'acps-site-toolkit' ), $reports, 'acps-st-analytics', array( $admin, 'render_analytics' ) );
		}
		if ( ( Settings::get( 'analytics_enabled' ) && Settings::get( 'track_visitors' ) ) || Settings::get( 'device_fp_enabled' ) ) {
			add_submenu_page( self::GF_PARENT, __( 'Visitors', 'acps-site-toolkit' ), __( 'Visitors', 'acps-site-toolkit' ), 'manage_options', 'acps-st-visitors', array( $admin, 'render_visitors' ) );
		}
		add_submenu_page( self::GF_PARENT, __( 'Q&A / Help', 'acps-site-toolkit' ), __( 'Q&A / Help', 'acps-site-toolkit' ), 'manage_options', 'acps-st-qa', array( $admin, 'render_qa' ) );
		add_submenu_page( self::GF_PARENT, __( 'Guided help', 'acps-site-toolkit' ), __( 'Guided help', 'acps-site-toolkit' ), $reports, 'acps-st-help', array( $admin, 'render_help' ) );

		// Merge our built-in forms straight into Gravity Forms' Forms list page.
		// Hook the generic footer and gate on the page param, so it works
		// regardless of how GF names its page hook suffix.
		add_action( 'admin_footer', array( __CLASS__, 'render_builtin_on_gf' ) );
	}

	/**
	 * Append our built-in forms to Gravity Forms' own Forms list page, so both
	 * live on one screen. Rendered into the admin footer, then moved under GF's
	 * list with a tiny script. Only on the forms LIST view, not the editor.
	 */
	public static function render_builtin_on_gf() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// Only on Gravity Forms' Forms LIST view: ?page=gf_edit_forms with no
		// form id / sub-view (the editor uses &id= / &view=).
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( self::GF_PARENT !== $page ) {
			return;
		}
		if ( ! empty( $_GET['id'] ) || ! empty( $_GET['view'] ) || ! empty( $_GET['gf_form_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		$forms  = Form::all();
		$counts = Entries::counts_by_form();
		$new    = admin_url( 'admin.php?page=acps-st-forms&action=new' );
		$import = admin_url( 'admin.php?page=acps-st-forms&action=import' );

		ob_start();
		?>
		<div id="acps-gf-builtin" style="display:none">
			<span id="acps-gf-btns">
				<a class="page-title-action" href="<?php echo esc_url( $new ); ?>"><?php esc_html_e( 'Add built-in form', 'acps-site-toolkit' ); ?></a>
				<a class="page-title-action" href="<?php echo esc_url( $import ); ?>"><?php esc_html_e( 'Import Google Form', 'acps-site-toolkit' ); ?></a>
			</span>
			<table><tbody id="acps-gf-src">
				<?php foreach ( $forms as $f ) : ?>
					<?php
					$edit      = admin_url( 'admin.php?page=acps-st-forms&action=edit&form=' . $f->id );
					$entries   = admin_url( 'admin.php?page=acps-st-entries&form_id=' . $f->id );
					$c         = isset( $counts[ $f->id ] ) ? $counts[ $f->id ] : array( 'total' => 0 );
					$is_pub    = ( 'published' === $f->status );
					$pill_bg   = $is_pub ? '#e6f4ea' : '#f0f0f1';
					$pill_fg   = $is_pub ? '#1a7f37' : '#50575e';
					$pill_text = $is_pub ? __( 'Active', 'acps-site-toolkit' ) : __( 'Draft', 'acps-site-toolkit' );
					$title     = $f->title ? $f->title : __( '(untitled form)', 'acps-site-toolkit' );
					?>
					<tr class="acps-builtin-row">
						<th scope="row" class="check-column"></th>
						<td class="is_active column-is_active" data-colname="Status">
							<span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:600;background:<?php echo esc_attr( $pill_bg ); ?>;color:<?php echo esc_attr( $pill_fg ); ?>"><?php echo esc_html( $pill_text ); ?></span>
						</td>
						<td class="title column-title has-row-actions column-primary" data-colname="Title">
							<strong><a href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( $title ); ?></a></strong>
							<span class="acps-badge" style="background:#5b3fb0;margin-left:6px"><?php esc_html_e( 'Built-in', 'acps-site-toolkit' ); ?></span>
							<?php if ( $f->is_feedback ) : ?><span class="acps-badge"><?php esc_html_e( 'Feedback', 'acps-site-toolkit' ); ?></span><?php endif; ?>
							<div class="row-actions">
								<span><a href="<?php echo esc_url( $edit ); ?>"><?php esc_html_e( 'Edit', 'acps-site-toolkit' ); ?></a> | </span>
								<span><a href="<?php echo esc_url( $entries ); ?>"><?php esc_html_e( 'Entries', 'acps-site-toolkit' ); ?></a> | </span>
								<span class="acps-sc"><?php esc_html_e( 'Shortcode:', 'acps-site-toolkit' ); ?> <code>[acps_form id="<?php echo esc_html( $f->id ); ?>"]</code></span>
							</div>
							<button type="button" class="toggle-row"><span class="screen-reader-text"><?php esc_html_e( 'Show more details', 'acps-site-toolkit' ); ?></span></button>
						</td>
						<td class="id column-id" data-colname="ID"><?php echo esc_html( 'B' . $f->id ); ?></td>
						<td class="entry_count column-entry_count" data-colname="Entries"><a href="<?php echo esc_url( $entries ); ?>"><?php echo esc_html( number_format_i18n( $c['total'] ) ); ?></a></td>
						<td class="view_count column-view_count" data-colname="Views">&mdash;</td>
						<td class="conversion column-conversion" data-colname="Conversion">&mdash;</td>
					</tr>
				<?php endforeach; ?>
			</tbody></table>
		</div>
		<style>
			tr.acps-builtin-row > td, tr.acps-builtin-row > th { background: #fbfaff; }
			tr.acps-builtin-row .acps-sc code { font-size: 12px; }
		</style>
		<script>
		( function () {
			// Move our built-in form rows straight into Gravity Forms' list table
			// so they sit in the SAME list, and our action buttons next to "Add New".
			var src  = document.getElementById( 'acps-gf-src' );
			var dest = document.getElementById( 'the-list' );
			if ( src && dest ) {
				while ( src.firstElementChild ) { dest.appendChild( src.firstElementChild ); }
			}
			var btns = document.getElementById( 'acps-gf-btns' );
			if ( btns ) {
				var head = document.querySelector( '.wp-heading-inline' );
				var addNew = document.querySelector( '.wrap .page-title-action' );
				if ( addNew && addNew.parentNode ) {
					// Place after GF's existing "Add New" button.
					while ( btns.firstElementChild ) { addNew.parentNode.insertBefore( btns.firstElementChild, addNew.nextSibling ); }
				} else if ( head && head.parentNode ) {
					head.parentNode.insertBefore( btns, head.nextSibling );
					btns.style.display = '';
				}
			}
			var host = document.getElementById( 'acps-gf-builtin' );
			if ( host && host.parentNode ) { host.parentNode.removeChild( host ); }
		}() );
		</script>
		<?php
		echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
