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
		<div id="acps-gf-builtin" class="acps-gf-builtin" style="display:none;margin-top:24px">
			<h2 style="display:flex;align-items:center;gap:10px">
				<?php esc_html_e( 'Built-in forms', 'acps-site-toolkit' ); ?>
				<a class="button button-secondary" href="<?php echo esc_url( $new ); ?>"><?php esc_html_e( 'Add built-in form', 'acps-site-toolkit' ); ?></a>
				<a class="button button-secondary" href="<?php echo esc_url( $import ); ?>"><?php esc_html_e( 'Import Google Form', 'acps-site-toolkit' ); ?></a>
			</h2>
			<p class="description"><?php esc_html_e( 'Forms from Cayden Riddle’s built-in builder (accessible forms, the Google Form bridge, feedback, etc.). Gravity Forms’ own forms are listed above.', 'acps-site-toolkit' ); ?></p>
			<?php if ( $forms ) : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead><tr>
						<th><?php esc_html_e( 'Title', 'acps-site-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Status', 'acps-site-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Entries', 'acps-site-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Shortcode', 'acps-site-toolkit' ); ?></th>
					</tr></thead>
					<tbody>
						<?php
						foreach ( $forms as $f ) :
							$edit    = admin_url( 'admin.php?page=acps-st-forms&action=edit&form=' . $f->id );
							$entries = admin_url( 'admin.php?page=acps-st-entries&form_id=' . $f->id );
							$c       = isset( $counts[ $f->id ] ) ? $counts[ $f->id ] : array( 'total' => 0 );
							?>
							<tr>
								<td><strong><a href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( $f->title ? $f->title : __( '(untitled form)', 'acps-site-toolkit' ) ); ?></a></strong><?php echo $f->is_feedback ? ' <span class="acps-badge">' . esc_html__( 'Feedback', 'acps-site-toolkit' ) . '</span>' : ''; ?></td>
								<td><?php echo esc_html( $f->status ); ?></td>
								<td><a href="<?php echo esc_url( $entries ); ?>"><?php echo esc_html( number_format_i18n( $c['total'] ) ); ?></a></td>
								<td><code>[acps_form id="<?php echo esc_html( $f->id ); ?>"]</code></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p><?php esc_html_e( 'No built-in forms yet.', 'acps-site-toolkit' ); ?></p>
			<?php endif; ?>
		</div>
		<script>
		( function () {
			var el = document.getElementById( 'acps-gf-builtin' );
			if ( ! el ) { return; }
			var host = document.querySelector( '#gform_list_container' ) || document.querySelector( '.gform-settings' ) || document.querySelector( '.wrap' );
			if ( host ) {
				if ( host.classList.contains( 'wrap' ) ) { host.appendChild( el ); }
				else { host.parentNode.appendChild( el ); }
				el.style.display = '';
			}
		}() );
		</script>
		<?php
		echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
