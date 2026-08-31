<?php
/**
 * Settings view (spec §9.2). One page, WordPress Settings API, site options.
 *
 * The page is organised into tabs (Features, Feedback, Forms, Analytics, Spam,
 * Appearance, Help, Access). All fields live in ONE form so a single Save
 * persists every tab; the tabs are a client-side show/hide over that one form.
 *
 * @package ACPS\SiteToolkit
 */

namespace ACPS\SiteToolkit\Admin;

use ACPS\SiteToolkit\Settings;
use ACPS\SiteToolkit\Updater;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$s   = Settings::all();
$opt = ACPS_ST_OPT_SETTINGS;

/**
 * Small helpers for repetitive field markup.
 */
$name = function ( $key ) use ( $opt ) {
	return $opt . '[' . $key . ']';
};
$checked = function ( $key ) use ( $s ) {
	return checked( ! empty( $s[ $key ] ), true, false );
};
?>
<div class="wrap acps-admin">
	<h1><?php esc_html_e( 'Cayden Form Manager Settings', 'acps-site-toolkit' ); ?></h1>

	<?php
	$db_msg = isset( $_GET['db'] ) ? sanitize_key( $_GET['db'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	if ( 'repaired' === $db_msg ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Database repaired — any missing tables or columns were created.', 'acps-site-toolkit' ) . '</p></div>';
	} elseif ( 'reset' === $db_msg ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Database reset — all plugin tables were rebuilt fresh and the built-in forms recreated.', 'acps-site-toolkit' ) . '</p></div>';
	}
	?>

	<div class="acps-card" style="border-left:4px solid #2271b1;max-width:48rem">
		<h2><?php esc_html_e( 'Database tools', 'acps-site-toolkit' ); ?></h2>
		<p><strong><?php esc_html_e( 'Repair', 'acps-site-toolkit' ); ?></strong> — <?php esc_html_e( 'safely creates any missing tables/columns without deleting data. Try this first if submissions aren’t saving.', 'acps-site-toolkit' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
			<?php wp_nonce_field( 'acps_st_db_action' ); ?>
			<input type="hidden" name="action" value="acps_st_db_action">
			<button type="submit" name="do" value="repair" class="button button-primary"><?php esc_html_e( 'Repair database', 'acps-site-toolkit' ); ?></button>
		</form>

		<hr>
		<p><strong style="color:#b32d2e"><?php esc_html_e( 'Reset (destructive)', 'acps-site-toolkit' ); ?></strong> — <?php esc_html_e( 'drops and rebuilds all plugin tables. This permanently deletes every form, entry, visitor and feedback item, then recreates the built-in forms. Settings are kept.', 'acps-site-toolkit' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
			<?php wp_nonce_field( 'acps_st_db_action' ); ?>
			<input type="hidden" name="action" value="acps_st_db_action">
			<button type="submit" name="do" value="reset" class="button acps-danger" onclick="return confirm('<?php echo esc_js( __( 'This permanently deletes all forms, entries, visitors and feedback, then rebuilds empty tables. Continue?', 'acps-site-toolkit' ) ); ?>');"><?php esc_html_e( 'Reset all plugin data', 'acps-site-toolkit' ); ?></button>
		</form>
	</div>

	<h2 class="nav-tab-wrapper" id="acps-tabs">
		<a href="#acps-tab-features" class="nav-tab nav-tab-active"><?php esc_html_e( 'Features', 'acps-site-toolkit' ); ?></a>
		<a href="#acps-tab-feedback" class="nav-tab"><?php esc_html_e( 'Feedback', 'acps-site-toolkit' ); ?></a>
		<a href="#acps-tab-forms" class="nav-tab"><?php esc_html_e( 'Forms', 'acps-site-toolkit' ); ?></a>
		<a href="#acps-tab-emails" class="nav-tab"><?php esc_html_e( 'Emails', 'acps-site-toolkit' ); ?></a>
		<a href="#acps-tab-analytics" class="nav-tab"><?php esc_html_e( 'Analytics', 'acps-site-toolkit' ); ?></a>
		<a href="#acps-tab-spam" class="nav-tab"><?php esc_html_e( 'Spam', 'acps-site-toolkit' ); ?></a>
		<a href="#acps-tab-appearance" class="nav-tab"><?php esc_html_e( 'Appearance', 'acps-site-toolkit' ); ?></a>
		<a href="#acps-tab-help" class="nav-tab"><?php esc_html_e( 'Help', 'acps-site-toolkit' ); ?></a>
		<a href="#acps-tab-access" class="nav-tab"><?php esc_html_e( 'Access & data', 'acps-site-toolkit' ); ?></a>
		<a href="#acps-tab-updates" class="nav-tab"><?php esc_html_e( 'Updates', 'acps-site-toolkit' ); ?></a>
	</h2>

	<form method="post" action="options.php">
		<?php settings_fields( Settings::GROUP ); ?>

		<!-- ============================ FEATURES ============================ -->
		<div class="acps-tab-panel" id="acps-tab-features">
			<h2 class="title"><?php esc_html_e( 'Features', 'acps-site-toolkit' ); ?></h2>
			<p class="description" style="max-width:48rem"><?php esc_html_e( 'Turn whole parts of the plugin on or off. When a feature is off, its front-end script is not loaded at all — so it adds nothing to page weight. Each feature also has its own detailed settings in the other tabs.', 'acps-site-toolkit' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Feedback & contact widget', 'acps-site-toolkit' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $name( 'feedback_enabled' ) ); ?>" value="1" <?php echo $checked( 'feedback_enabled' ); ?>> <?php esc_html_e( 'On — show the floating button and its popup form', 'acps-site-toolkit' ); ?></label>
						<p class="description"><?php esc_html_e( 'Appearance, position, categories and recipients are in the Feedback tab.', 'acps-site-toolkit' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Q&A / help widget', 'acps-site-toolkit' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $name( 'qa_enabled' ) ); ?>" value="1" <?php echo $checked( 'qa_enabled' ); ?>> <?php esc_html_e( 'On — allow the [acps_qa] question-and-answer widget', 'acps-site-toolkit' ); ?></label>
						<p class="description"><?php esc_html_e( 'When off, the [acps_qa] shortcode outputs nothing and its script never loads. Manage questions under Cayden Form Manager → Q&A.', 'acps-site-toolkit' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Restricted / secret-link forms', 'acps-site-toolkit' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $name( 'restricted_forms_enabled' ) ); ?>" value="1" <?php echo $checked( 'restricted_forms_enabled' ); ?>> <?php esc_html_e( 'On — allow password-gated forms and secret share links', 'acps-site-toolkit' ); ?></label>
						<p class="description"><?php esc_html_e( 'When off, secret ?acps_key links no longer open a form and the access script never loads. Per-form access is set in the form builder.', 'acps-site-toolkit' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Analytics & tracking', 'acps-site-toolkit' ); ?></th>
					<td>
						<p style="margin-top:0"><?php echo wp_kses_post( sprintf( __( 'Currently <strong>%s</strong>.', 'acps-site-toolkit' ), Settings::get( 'analytics_enabled' ) ? __( 'on', 'acps-site-toolkit' ) : __( 'off', 'acps-site-toolkit' ) ) ); ?></p>
						<p class="description"><?php esc_html_e( 'The master switch and all fine-grained control (what to collect, sampling, which dashboard cards to show) are in the Analytics tab.', 'acps-site-toolkit' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<!-- ============================ FEEDBACK ============================ -->
		<div class="acps-tab-panel" id="acps-tab-feedback" hidden>
			<h2 class="title"><?php esc_html_e( 'Feedback', 'acps-site-toolkit' ); ?></h2>
			<p class="description" style="max-width:48rem"><?php esc_html_e( 'On/off for this widget is in the Features tab. These settings control how it looks and behaves.', 'acps-site-toolkit' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="acps-trigger-display"><?php esc_html_e( 'Show trigger on', 'acps-site-toolkit' ); ?></label></th>
					<td>
						<select id="acps-trigger-display" name="<?php echo esc_attr( $name( 'trigger_display' ) ); ?>">
							<option value="all" <?php selected( $s['trigger_display'], 'all' ); ?>><?php esc_html_e( 'All pages', 'acps-site-toolkit' ); ?></option>
							<option value="include" <?php selected( $s['trigger_display'], 'include' ); ?>><?php esc_html_e( 'Only specific pages', 'acps-site-toolkit' ); ?></option>
							<option value="exclude" <?php selected( $s['trigger_display'], 'exclude' ); ?>><?php esc_html_e( 'All pages except…', 'acps-site-toolkit' ); ?></option>
						</select>
						<p><label for="acps-trigger-pages"><?php esc_html_e( 'Page IDs (comma-separated)', 'acps-site-toolkit' ); ?></label><br>
						<input type="text" id="acps-trigger-pages" name="<?php echo esc_attr( $name( 'trigger_pages' ) ); ?>" value="<?php echo esc_attr( implode( ', ', (array) $s['trigger_pages'] ) ); ?>" class="regular-text"></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="acps-trigger-position"><?php esc_html_e( 'Trigger position', 'acps-site-toolkit' ); ?></label></th>
					<td>
						<select id="acps-trigger-position" name="<?php echo esc_attr( $name( 'trigger_position' ) ); ?>">
							<?php
							$positions = array(
								'bottom-right' => __( 'Bottom right', 'acps-site-toolkit' ),
								'bottom-left'  => __( 'Bottom left', 'acps-site-toolkit' ),
								'edge-right'   => __( 'Right edge tab', 'acps-site-toolkit' ),
								'edge-left'    => __( 'Left edge tab', 'acps-site-toolkit' ),
							);
							foreach ( $positions as $val => $lbl ) {
								printf( '<option value="%s" %s>%s</option>', esc_attr( $val ), selected( $s['trigger_position'], $val, false ), esc_html( $lbl ) );
							}
							?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="acps-trigger-label"><?php esc_html_e( 'Trigger label', 'acps-site-toolkit' ); ?></label></th>
					<td><input type="text" id="acps-trigger-label" name="<?php echo esc_attr( $name( 'trigger_label' ) ); ?>" value="<?php echo esc_attr( $s['trigger_label'] ); ?>" class="regular-text"><p class="description"><?php esc_html_e( 'Used as the button\'s screen-reader name when an icon image is set.', 'acps-site-toolkit' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="acps-trigger-icon"><?php esc_html_e( 'Trigger icon URL', 'acps-site-toolkit' ); ?></label></th>
					<td>
						<input type="url" id="acps-trigger-icon" name="<?php echo esc_attr( $name( 'trigger_icon_url' ) ); ?>" value="<?php echo esc_attr( $s['trigger_icon_url'] ); ?>" class="large-text code" placeholder="https://…/icon.png">
						<p class="description"><?php esc_html_e( 'Image shown in a circle. Leave blank to use the default chat-bubble icon with the label.', 'acps-site-toolkit' ); ?></p>
						<?php if ( ! empty( $s['trigger_icon_url'] ) ) : ?>
							<img src="<?php echo esc_url( $s['trigger_icon_url'] ); ?>" alt="" style="width:64px;height:64px;border-radius:50%;object-fit:cover;margin-top:.5rem;border:1px solid #ccd0d4">
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="acps-trigger-icon-hover"><?php esc_html_e( 'Trigger icon URL (hover / open)', 'acps-site-toolkit' ); ?></label></th>
					<td>
						<input type="url" id="acps-trigger-icon-hover" name="<?php echo esc_attr( $name( 'trigger_icon_hover_url' ) ); ?>" value="<?php echo esc_attr( $s['trigger_icon_hover_url'] ); ?>" class="large-text code" placeholder="https://…/icon-hover.png">
						<p class="description"><?php esc_html_e( 'A second image shown when the button is hovered, focused, or open. Leave blank to keep the resting icon in every state.', 'acps-site-toolkit' ); ?></p>
						<?php if ( ! empty( $s['trigger_icon_hover_url'] ) ) : ?>
							<img src="<?php echo esc_url( $s['trigger_icon_hover_url'] ); ?>" alt="" style="width:64px;height:64px;border-radius:50%;object-fit:cover;margin-top:.5rem;border:1px solid #ccd0d4">
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Button size per device (px)', 'acps-site-toolkit' ); ?></th>
					<td>
						<label for="acps-trigger-size"><?php esc_html_e( 'Laptop / desktop', 'acps-site-toolkit' ); ?></label>
						<input type="number" id="acps-trigger-size" name="<?php echo esc_attr( $name( 'trigger_size' ) ); ?>" value="<?php echo esc_attr( $s['trigger_size'] ); ?>" min="24" max="200" class="small-text">
						&nbsp;
						<label for="acps-trigger-size-t"><?php esc_html_e( 'Tablet', 'acps-site-toolkit' ); ?></label>
						<input type="number" id="acps-trigger-size-t" name="<?php echo esc_attr( $name( 'trigger_size_tablet' ) ); ?>" value="<?php echo esc_attr( $s['trigger_size_tablet'] ); ?>" min="24" max="200" class="small-text">
						&nbsp;
						<label for="acps-trigger-size-m"><?php esc_html_e( 'Phone', 'acps-site-toolkit' ); ?></label>
						<input type="number" id="acps-trigger-size-m" name="<?php echo esc_attr( $name( 'trigger_size_mobile' ) ); ?>" value="<?php echo esc_attr( $s['trigger_size_mobile'] ); ?>" min="24" max="200" class="small-text">
						<p class="description"><?php esc_html_e( 'Diameter of the circular button. Tablet ≤1024px, phone ≤600px.', 'acps-site-toolkit' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="acps-modal-width"><?php esc_html_e( 'Popup width (px)', 'acps-site-toolkit' ); ?></label></th>
					<td>
						<input type="number" id="acps-modal-width" name="<?php echo esc_attr( $name( 'modal_max_width' ) ); ?>" value="<?php echo esc_attr( $s['modal_max_width'] ); ?>" min="320" max="2000" class="small-text">
						<p class="description"><?php esc_html_e( 'Max width of the popup on laptop/desktop (e.g. 1200). It automatically shrinks to fit smaller screens.', 'acps-site-toolkit' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="acps-trigger-bg"><?php esc_html_e( 'Trigger background colour', 'acps-site-toolkit' ); ?></label></th>
					<td><input type="text" id="acps-trigger-bg" name="<?php echo esc_attr( $name( 'trigger_bg' ) ); ?>" value="<?php echo esc_attr( $s['trigger_bg'] ); ?>" placeholder="#0b5fa5" class="regular-text"><p class="description"><?php esc_html_e( 'Behind a transparent icon. Leave blank to use the theme accent.', 'acps-site-toolkit' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Transparent background', 'acps-site-toolkit' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( $name( 'trigger_transparent' ) ); ?>" value="1" <?php echo $checked( 'trigger_transparent' ); ?>> <?php esc_html_e( 'No circle, ring or shadow — show just the icon image on a transparent background', 'acps-site-toolkit' ); ?></label><p class="description"><?php esc_html_e( 'Best with an icon image that already has its own shape/transparency.', 'acps-site-toolkit' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="acps-categories"><?php esc_html_e( 'Feedback categories', 'acps-site-toolkit' ); ?></label></th>
					<td><textarea id="acps-categories" name="<?php echo esc_attr( $name( 'feedback_categories' ) ); ?>" rows="6" class="large-text"><?php echo esc_textarea( implode( "\n", (array) $s['feedback_categories'] ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One per line.', 'acps-site-toolkit' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="acps-recent-count"><?php esc_html_e( 'Recent pages to pre-fill', 'acps-site-toolkit' ); ?></label></th>
					<td><input type="number" id="acps-recent-count" name="<?php echo esc_attr( $name( 'recent_pages_count' ) ); ?>" value="<?php echo esc_attr( $s['recent_pages_count'] ); ?>" min="1" max="10" class="small-text"></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Screenshots', 'acps-site-toolkit' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( $name( 'feedback_allow_screenshot' ) ); ?>" value="1" <?php echo $checked( 'feedback_allow_screenshot' ); ?>> <?php esc_html_e( 'Offer an optional screenshot upload on "something\'s broken" reports', 'acps-site-toolkit' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="acps-notify"><?php esc_html_e( 'Notification recipients', 'acps-site-toolkit' ); ?></label></th>
					<td><input type="text" id="acps-notify" name="<?php echo esc_attr( $name( 'notify_recipients' ) ); ?>" value="<?php echo esc_attr( $s['notify_recipients'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
					<p class="description"><?php esc_html_e( 'Comma-separated. Blank uses the site admin email. Used for feedback and any form without its own recipients.', 'acps-site-toolkit' ); ?></p></td>
				</tr>
			</table>
		</div>

		<!-- ============================= FORMS ============================= -->
		<div class="acps-tab-panel" id="acps-tab-forms" hidden>
			<h2 class="title"><?php esc_html_e( 'Forms', 'acps-site-toolkit' ); ?></h2>
			<p class="description" style="max-width:48rem"><?php esc_html_e( 'Global defaults that apply to every form. Each form’s own fields, confirmation message, response limits and access rules are edited in the form builder (Cayden Form Manager → Forms) and override these where they overlap.', 'acps-site-toolkit' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Store submitter IP & browser', 'acps-site-toolkit' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $name( 'entry_store_ip' ) ); ?>" value="1" <?php echo $checked( 'entry_store_ip' ); ?>> <?php esc_html_e( 'Save the submitter’s anonymised IP + browser summary on each entry', 'acps-site-toolkit' ); ?></label>
						<p class="description"><?php esc_html_e( 'Used to power per-device response limits. Turning this off is more private, but per-device response limits will no longer work.', 'acps-site-toolkit' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="acps-max-upload"><?php esc_html_e( 'Max upload size (MB)', 'acps-site-toolkit' ); ?></label></th>
					<td>
						<input type="number" id="acps-max-upload" name="<?php echo esc_attr( $name( 'max_upload_mb' ) ); ?>" value="<?php echo esc_attr( $s['max_upload_mb'] ); ?>" min="1" max="100" class="small-text">
						<p class="description"><?php esc_html_e( 'Largest single file a visitor can attach to a form. Your server’s own upload limit still applies if it is lower.', 'acps-site-toolkit' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Auto-log 404 pages', 'acps-site-toolkit' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $name( 'autolog_404' ) ); ?>" value="1" <?php echo $checked( 'autolog_404' ); ?>> <?php esc_html_e( 'Record an entry automatically whenever a visitor hits a “page not found” (404)', 'acps-site-toolkit' ); ?></label>
						<p class="description"><?php esc_html_e( 'Each 404 is logged with the requested URL, where the visitor came from, and their device, browser, screen, language, time zone and session — enough to reproduce what happened. No email is sent; entries appear under the “Site error log (404s)” form.', 'acps-site-toolkit' ); ?></p>
						<?php
						$err_form = \ACPS\SiteToolkit\Error_Log::ensure_form();
						if ( $err_form ) :
							?>
							<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=acps-st-entries&form_id=' . (int) $err_form->id ) ); ?>" class="button"><?php esc_html_e( 'View the 404 log', 'acps-site-toolkit' ); ?></a></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Manage forms', 'acps-site-toolkit' ); ?></th>
					<td>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=acps-st-forms' ) ); ?>" class="button"><?php esc_html_e( 'Open the form builder', 'acps-site-toolkit' ); ?></a>
						<p class="description"><?php esc_html_e( 'Create and edit forms, import a Google Form, set per-form confirmations, response limits and access.', 'acps-site-toolkit' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<!-- ============================= EMAILS ============================ -->
		<div class="acps-tab-panel" id="acps-tab-emails" hidden>
			<h2 class="title"><?php esc_html_e( 'Emails', 'acps-site-toolkit' ); ?></h2>
			<p class="description" style="max-width:48rem"><?php esc_html_e( 'Reply address and the default wording sent to a submitter when you change a feedback item’s status. Every message the plugin sends is also blind-copied to the internal team automatically.', 'acps-site-toolkit' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="acps-reply-to"><?php esc_html_e( 'Reply-To address', 'acps-site-toolkit' ); ?></label></th>
					<td>
						<input type="email" id="acps-reply-to" name="<?php echo esc_attr( $name( 'email_reply_to' ) ); ?>" value="<?php echo esc_attr( $s['email_reply_to'] ); ?>" class="regular-text" placeholder="info@acpsmd.org">
						<p class="description"><?php esc_html_e( 'When a recipient replies to any email the plugin sends, it goes here. Leave blank to add no Reply-To.', 'acps-site-toolkit' ); ?></p>
					</td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'Status update messages', 'acps-site-toolkit' ); ?></h3>
			<p class="description" style="max-width:48rem"><?php esc_html_e( 'The default message offered when you email a submitter about a status change. You can still edit it per message before sending. Leave a box blank to use the built-in wording.', 'acps-site-toolkit' ); ?></p>
			<table class="form-table" role="presentation">
				<?php
				$stored_msgs = is_array( $s['status_messages'] ) ? $s['status_messages'] : array();
				foreach ( \ACPS\SiteToolkit\Entries::feedback_status_labels() as $st => $st_label ) :
					$val = isset( $stored_msgs[ $st ] ) && '' !== $stored_msgs[ $st ]
						? $stored_msgs[ $st ]
						: \ACPS\SiteToolkit\Notifications::default_status_message( $st );
					?>
					<tr>
						<th scope="row"><label for="acps-msg-<?php echo esc_attr( $st ); ?>"><?php echo esc_html( $st_label ); ?></label></th>
						<td>
							<textarea id="acps-msg-<?php echo esc_attr( $st ); ?>" name="<?php echo esc_attr( $name( 'status_messages' ) . '[' . $st . ']' ); ?>" rows="3" class="large-text"><?php echo esc_textarea( $val ); ?></textarea>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
		</div>

		<!-- =========================== ANALYTICS =========================== -->
		<div class="acps-tab-panel" id="acps-tab-analytics" hidden>
			<h2 class="title"><?php esc_html_e( 'Analytics & tracking', 'acps-site-toolkit' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Master switch', 'acps-site-toolkit' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $name( 'analytics_enabled' ) ); ?>" value="1" <?php echo $checked( 'analytics_enabled' ); ?>> <?php esc_html_e( 'Enable analytics & tracking', 'acps-site-toolkit' ); ?></label>
						<p class="description"><?php esc_html_e( 'Turns everything below on or off at once. When off, nothing tracks at all and the Analytics/Visitors screens are hidden. Forms, feedback and Q&A always keep working.', 'acps-site-toolkit' ); ?></p>
					</td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'Collect', 'acps-site-toolkit' ); ?></h3>
			<p class="description" style="max-width:48rem"><?php esc_html_e( 'What the tracking beacon gathers. These are the settings that affect requests and stored data — turn off what you don’t need.', 'acps-site-toolkit' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Data to collect', 'acps-site-toolkit' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $name( 'track_pageviews' ) ); ?>" value="1" <?php echo $checked( 'track_pageviews' ); ?>> <?php esc_html_e( 'Page views & journeys (also powers device/browser stats and paths)', 'acps-site-toolkit' ); ?></label><br>
						<label><input type="checkbox" name="<?php echo esc_attr( $name( 'track_time_on_page' ) ); ?>" value="1" <?php echo $checked( 'track_time_on_page' ); ?>> <?php esc_html_e( 'Time on page (adds a small write per view; off = one fewer database write per beacon)', 'acps-site-toolkit' ); ?></label><br>
						<label><input type="checkbox" name="<?php echo esc_attr( $name( 'track_referrers' ) ); ?>" value="1" <?php echo $checked( 'track_referrers' ); ?>> <?php esc_html_e( 'Referrers (where visitors came from)', 'acps-site-toolkit' ); ?></label><br>
						<label><input type="checkbox" name="<?php echo esc_attr( $name( 'track_visitors' ) ); ?>" value="1" <?php echo $checked( 'track_visitors' ); ?>> <?php esc_html_e( 'Unique users (by anonymised IP + browser, like the spam filter — clearing cookies/cache can’t create a new one)', 'acps-site-toolkit' ); ?></label><br>
						<label><input type="checkbox" name="<?php echo esc_attr( $name( 'track_admins' ) ); ?>" value="1" <?php echo $checked( 'track_admins' ); ?>> <?php esc_html_e( 'Also track logged-in admins/staff (off by default so your own browsing doesn’t skew the numbers — turn on to record staff visits or to test tracking)', 'acps-site-toolkit' ); ?></label>
						<p class="description"><?php esc_html_e( 'Page views + unique users share a single background request per page view, so keeping both on costs nothing extra.', 'acps-site-toolkit' ); ?></p>
						<?php
						$rq = \ACPS\SiteToolkit\Analytics::requests_summary();
						/* translators: 1: last hour, 2: today, 3: total */
						printf(
							'<p class="description"><strong>%s</strong> %s</p>',
							esc_html__( 'Tracking requests so far:', 'acps-site-toolkit' ),
							esc_html( sprintf( __( '%1$s in the last hour · %2$s today · %3$s total', 'acps-site-toolkit' ), number_format_i18n( $rq['hour'] ), number_format_i18n( $rq['today'] ), number_format_i18n( $rq['total'] ) ) )
						);
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="acps-sample"><?php esc_html_e( 'Sampling rate (%)', 'acps-site-toolkit' ); ?></label></th>
					<td>
						<input type="number" id="acps-sample" name="<?php echo esc_attr( $name( 'analytics_sample_rate' ) ); ?>" value="<?php echo esc_attr( $s['analytics_sample_rate'] ); ?>" min="1" max="100" class="small-text">
						<p class="description"><?php esc_html_e( 'The share of page views that actually send a tracking request. 100% records every view; on a busy site, lowering this (for example to 25%) cuts origin load to a quarter while stats stay proportional. This is the single biggest lever if tracking is slowing the site down. Does not affect form submissions, which are always recorded.', 'acps-site-toolkit' ); ?></p>
					</td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'Show on dashboard', 'acps-site-toolkit' ); ?></h3>
			<p class="description" style="max-width:48rem"><?php esc_html_e( 'Which cards appear on the Analytics screen. Hiding a card also skips the database queries that build it, so these are safe to turn off for speed as well as tidiness.', 'acps-site-toolkit' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Cards', 'acps-site-toolkit' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $name( 'show_live' ) ); ?>" value="1" <?php echo $checked( 'show_live' ); ?>> <?php esc_html_e( 'Who’s on the site now (live)', 'acps-site-toolkit' ); ?></label><br>
						<label><input type="checkbox" name="<?php echo esc_attr( $name( 'show_unique_users' ) ); ?>" value="1" <?php echo $checked( 'show_unique_users' ); ?>> <?php esc_html_e( 'Unique users', 'acps-site-toolkit' ); ?></label><br>
						<label><input type="checkbox" name="<?php echo esc_attr( $name( 'show_pages' ) ); ?>" value="1" <?php echo $checked( 'show_pages' ); ?>> <?php esc_html_e( 'Pages — traffic & feedback overlay', 'acps-site-toolkit' ); ?></label><br>
						<label><input type="checkbox" name="<?php echo esc_attr( $name( 'show_devices' ) ); ?>" value="1" <?php echo $checked( 'show_devices' ); ?>> <?php esc_html_e( 'Devices, browsers & operating systems', 'acps-site-toolkit' ); ?></label><br>
						<label><input type="checkbox" name="<?php echo esc_attr( $name( 'show_journeys' ) ); ?>" value="1" <?php echo $checked( 'show_journeys' ); ?>> <?php esc_html_e( 'Common paths & dead ends', 'acps-site-toolkit' ); ?></label><br>
						<label><input type="checkbox" name="<?php echo esc_attr( $name( 'show_trend' ) ); ?>" value="1" <?php echo $checked( 'show_trend' ); ?>> <?php esc_html_e( 'Views over the last 30 days', 'acps-site-toolkit' ); ?></label>
						<p class="description"><?php esc_html_e( 'A card also needs its data to be collected above — e.g. Unique users needs “Unique users” collection on.', 'acps-site-toolkit' ); ?></p>
					</td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'Data & privacy', 'acps-site-toolkit' ); ?></h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Journey tracking', 'acps-site-toolkit' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( $name( 'tracking_enabled' ) ); ?>" value="1" <?php echo $checked( 'tracking_enabled' ); ?>> <?php esc_html_e( 'Record the page journey per session (via the cache-safe beacon)', 'acps-site-toolkit' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Consent mode', 'acps-site-toolkit' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( $name( 'consent_mode' ) ); ?>" value="1" <?php echo $checked( 'consent_mode' ); ?>> <?php esc_html_e( 'Only track after the visitor consents (forms still work without consent)', 'acps-site-toolkit' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="acps-idle"><?php esc_html_e( 'Session idle window (minutes)', 'acps-site-toolkit' ); ?></label></th>
					<td><input type="number" id="acps-idle" name="<?php echo esc_attr( $name( 'session_idle_minutes' ) ); ?>" value="<?php echo esc_attr( $s['session_idle_minutes'] ); ?>" min="5" class="small-text"></td>
				</tr>
				<tr>
					<th scope="row"><label for="acps-retention"><?php esc_html_e( 'Data retention (months)', 'acps-site-toolkit' ); ?></label></th>
					<td><input type="number" id="acps-retention" name="<?php echo esc_attr( $name( 'retention_months' ) ); ?>" value="<?php echo esc_attr( $s['retention_months'] ); ?>" min="0" class="small-text">
					<p class="description"><?php esc_html_e( 'Visit rows older than this are auto-purged daily. 0 = keep forever.', 'acps-site-toolkit' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'User agents', 'acps-site-toolkit' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( $name( 'store_full_user_agent' ) ); ?>" value="1" <?php echo $checked( 'store_full_user_agent' ); ?>> <?php esc_html_e( 'Store full user-agent strings (off = parsed browser/OS summary only)', 'acps-site-toolkit' ); ?></label></td>
				</tr>
			</table>
		</div>

		<!-- ============================== SPAM ============================= -->
		<div class="acps-tab-panel" id="acps-tab-spam" hidden>
			<h2 class="title"><?php esc_html_e( 'Spam prevention', 'acps-site-toolkit' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Layers', 'acps-site-toolkit' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $name( 'spam_honeypot' ) ); ?>" value="1" <?php echo $checked( 'spam_honeypot' ); ?>> <?php esc_html_e( 'Honeypot field', 'acps-site-toolkit' ); ?></label><br>
						<label><input type="checkbox" name="<?php echo esc_attr( $name( 'spam_time_trap' ) ); ?>" value="1" <?php echo $checked( 'spam_time_trap' ); ?>> <?php esc_html_e( 'Time trap (reject submissions faster than a threshold)', 'acps-site-toolkit' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="acps-time-threshold"><?php esc_html_e( 'Time trap threshold (seconds)', 'acps-site-toolkit' ); ?></label></th>
					<td><input type="number" id="acps-time-threshold" name="<?php echo esc_attr( $name( 'spam_time_threshold' ) ); ?>" value="<?php echo esc_attr( $s['spam_time_threshold'] ); ?>" min="0" class="small-text"></td>
				</tr>
				<tr>
					<th scope="row"><label for="acps-rate-limit"><?php esc_html_e( 'Rate limit', 'acps-site-toolkit' ); ?></label></th>
					<td>
						<input type="number" id="acps-rate-limit" name="<?php echo esc_attr( $name( 'spam_rate_limit' ) ); ?>" value="<?php echo esc_attr( $s['spam_rate_limit'] ); ?>" min="0" class="small-text">
						<?php esc_html_e( 'submissions per', 'acps-site-toolkit' ); ?>
						<label class="screen-reader-text" for="acps-rate-window"><?php esc_html_e( 'window in minutes', 'acps-site-toolkit' ); ?></label>
						<input type="number" id="acps-rate-window" name="<?php echo esc_attr( $name( 'spam_rate_window' ) ); ?>" value="<?php echo esc_attr( $s['spam_rate_window'] ); ?>" min="1" class="small-text"> <?php esc_html_e( 'minutes', 'acps-site-toolkit' ); ?>
						<p class="description"><?php esc_html_e( '0 disables rate limiting.', 'acps-site-toolkit' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="acps-blocklist"><?php esc_html_e( 'Keyword blocklist', 'acps-site-toolkit' ); ?></label></th>
					<td><textarea id="acps-blocklist" name="<?php echo esc_attr( $name( 'spam_blocklist' ) ); ?>" rows="4" class="large-text"><?php echo esc_textarea( $s['spam_blocklist'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One term per line. Submissions containing any term are discarded.', 'acps-site-toolkit' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Accessible challenge', 'acps-site-toolkit' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $name( 'spam_challenge_enable' ) ); ?>" value="1" <?php echo $checked( 'spam_challenge_enable' ); ?>> <?php esc_html_e( 'Ask a plain-text question (readable by screen readers — no image CAPTCHA)', 'acps-site-toolkit' ); ?></label>
						<p><label for="acps-challenge-q"><?php esc_html_e( 'Question', 'acps-site-toolkit' ); ?></label><br>
						<input type="text" id="acps-challenge-q" name="<?php echo esc_attr( $name( 'spam_challenge_q' ) ); ?>" value="<?php echo esc_attr( $s['spam_challenge_q'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'What color is the sky?', 'acps-site-toolkit' ); ?>"></p>
						<p><label for="acps-challenge-a"><?php esc_html_e( 'Expected answer', 'acps-site-toolkit' ); ?></label><br>
						<input type="text" id="acps-challenge-a" name="<?php echo esc_attr( $name( 'spam_challenge_a' ) ); ?>" value="<?php echo esc_attr( $s['spam_challenge_a'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'blue', 'acps-site-toolkit' ); ?>"></p>
					</td>
				</tr>
			</table>
		</div>

		<!-- =========================== APPEARANCE ========================== -->
		<div class="acps-tab-panel" id="acps-tab-appearance" hidden>
			<h2 class="title"><?php esc_html_e( 'Appearance — stylesheet', 'acps-site-toolkit' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="acps-custom-css"><?php esc_html_e( 'Cayden Form Manager CSS', 'acps-site-toolkit' ); ?></label></th>
					<td>
						<?php
						// Prefill with the full default stylesheet so you can see and
						// edit every rule. When empty, the default file is used as-is.
						$css_value = '' !== $s['custom_css']
							? $s['custom_css']
							: (string) @file_get_contents( ACPS_ST_PATH . 'assets/css/frontend.css' ); // phpcs:ignore
						?>
						<textarea id="acps-custom-css" name="<?php echo esc_attr( $name( 'custom_css' ) ); ?>" rows="24" class="large-text code" spellcheck="false" style="font-family:monospace;white-space:pre;"><?php echo esc_textarea( $css_value ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'This is the full front-end stylesheet — edit any rule here. What you save loads on top of the maintained default, so your changes win. Leave it empty to revert to the shipped defaults.', 'acps-site-toolkit' ); ?>
						</p>
						<p class="description">
							<strong><?php esc_html_e( 'Please keep these intact', 'acps-site-toolkit' ); ?>:</strong>
							<?php esc_html_e( 'the .acps-hp rule (hides the spam honeypot) and the :focus-visible outlines (keyboard accessibility). Removing them can break spam protection or accessibility.', 'acps-site-toolkit' ); ?>
						</p>
					</td>
				</tr>
			</table>
		</div>

		<!-- ============================== HELP ============================= -->
		<div class="acps-tab-panel" id="acps-tab-help" hidden>
			<h2 class="title"><?php esc_html_e( 'Help', 'acps-site-toolkit' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="acps-help-url"><?php esc_html_e( 'Help Guide URL', 'acps-site-toolkit' ); ?></label></th>
					<td>
						<input type="url" id="acps-help-url" name="<?php echo esc_attr( $name( 'help_guide_url' ) ); ?>" value="<?php echo esc_attr( $s['help_guide_url'] ); ?>" class="large-text code" placeholder="https://…">
						<p class="description"><?php esc_html_e( 'Optional link to your own help doc/video. When set, a button to it appears on the built-in Help Guide page. Leave blank to use only the built-in guide (Cayden Form Manager → Help Guide).', 'acps-site-toolkit' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<!-- =========================== ACCESS & DATA ======================= -->
		<div class="acps-tab-panel" id="acps-tab-access" hidden>
			<h2 class="title"><?php esc_html_e( 'Access & data', 'acps-site-toolkit' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Editor access', 'acps-site-toolkit' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( $name( 'editors_view_reports' ) ); ?>" value="1" <?php echo $checked( 'editors_view_reports' ); ?>> <?php esc_html_e( 'Let Editors view Feedback and Analytics (read-only; no settings access)', 'acps-site-toolkit' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'On uninstall', 'acps-site-toolkit' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( $name( 'preserve_data' ) ); ?>" value="1" <?php echo $checked( 'preserve_data' ); ?>> <?php esc_html_e( 'Preserve all data when the plugin is deleted (recommended)', 'acps-site-toolkit' ); ?></label>
					<p class="description"><?php esc_html_e( 'When off, deleting the plugin drops all tables. Deactivating never removes data.', 'acps-site-toolkit' ); ?></p></td>
				</tr>
			</table>
		</div>

		<!-- ============================= UPDATES =========================== -->
		<div class="acps-tab-panel" id="acps-tab-updates" hidden>
			<h2 class="title"><?php esc_html_e( 'Updates', 'acps-site-toolkit' ); ?></h2>
			<p class="description" style="max-width:48rem"><?php esc_html_e( 'This plugin does not live on wordpress.org, so it checks a source you control for new versions. When a newer version is found, "Update now" appears on the Plugins screen exactly like a wordpress.org plugin.', 'acps-site-toolkit' ); ?></p>

			<?php
			if ( isset( $_GET['checked'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				if ( ! empty( $_GET['found'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
					echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'A newer version was found — see the Plugins screen to install it.', 'acps-site-toolkit' ) . '</p></div>';
				} else {
					echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__( 'Checked — this is already the latest version.', 'acps-site-toolkit' ) . '</p></div>';
				}
			}
			?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Self-updates', 'acps-site-toolkit' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $name( 'update_enabled' ) ); ?>" value="1" <?php echo $checked( 'update_enabled' ); ?>> <?php esc_html_e( 'On — check the source below and offer "Update now" when a newer version exists', 'acps-site-toolkit' ); ?></label><br>
						<label><input type="checkbox" name="<?php echo esc_attr( $name( 'update_auto' ) ); ?>" value="1" <?php echo $checked( 'update_auto' ); ?>> <?php esc_html_e( 'Also install new versions automatically in the background (WordPress\' normal auto-update cron)', 'acps-site-toolkit' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="acps-update-source"><?php esc_html_e( 'Update source', 'acps-site-toolkit' ); ?></label></th>
					<td>
						<select id="acps-update-source" name="<?php echo esc_attr( $name( 'update_source' ) ); ?>">
							<option value="url" <?php selected( $s['update_source'], 'url' ); ?>><?php esc_html_e( 'Self-hosted manifest (a JSON file + zip you host)', 'acps-site-toolkit' ); ?></option>
							<option value="github" <?php selected( $s['update_source'], 'github' ); ?>><?php esc_html_e( 'GitHub releases', 'acps-site-toolkit' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Only the fields for the selected source are used — the other section is ignored.', 'acps-site-toolkit' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="acps-update-manifest"><?php esc_html_e( 'Manifest URL', 'acps-site-toolkit' ); ?></label></th>
					<td>
						<input type="url" id="acps-update-manifest" name="<?php echo esc_attr( $name( 'update_manifest' ) ); ?>" value="<?php echo esc_attr( $s['update_manifest'] ); ?>" class="large-text code" placeholder="https://updates.example.org/acps-site-toolkit/update.json">
						<p class="description"><?php esc_html_e( 'Used when the source above is "Self-hosted manifest". Must return HTTP 200 JSON with at least version and download_url.', 'acps-site-toolkit' ); ?></p>
						<label for="acps-update-manifest-key"><?php esc_html_e( 'Optional shared secret', 'acps-site-toolkit' ); ?></label>
						<input type="text" id="acps-update-manifest-key" name="<?php echo esc_attr( $name( 'update_manifest_key' ) ); ?>" value="<?php echo esc_attr( $s['update_manifest_key'] ); ?>" class="regular-text">
						<p class="description"><?php esc_html_e( 'If set, sent as ?key=… on every request to the manifest URL. Leave blank if your host doesn\'t check it.', 'acps-site-toolkit' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'GitHub repository', 'acps-site-toolkit' ); ?></th>
					<td>
						<label for="acps-gh-owner"><?php esc_html_e( 'Owner', 'acps-site-toolkit' ); ?></label>
						<input type="text" id="acps-gh-owner" name="<?php echo esc_attr( $name( 'gh_owner' ) ); ?>" value="<?php echo esc_attr( $s['gh_owner'] ); ?>" class="regular-text" placeholder="acps">
						&nbsp;
						<label for="acps-gh-repo"><?php esc_html_e( 'Repo', 'acps-site-toolkit' ); ?></label>
						<input type="text" id="acps-gh-repo" name="<?php echo esc_attr( $name( 'gh_repo' ) ); ?>" value="<?php echo esc_attr( $s['gh_repo'] ); ?>" class="regular-text" placeholder="acps-site-toolkit">
						<p class="description"><?php esc_html_e( 'Used when the source above is "GitHub releases". Reads the latest release from the GitHub Releases API.', 'acps-site-toolkit' ); ?></p>

						<label for="acps-gh-asset"><?php esc_html_e( 'Release asset filename', 'acps-site-toolkit' ); ?></label>
						<input type="text" id="acps-gh-asset" name="<?php echo esc_attr( $name( 'gh_asset' ) ); ?>" value="<?php echo esc_attr( $s['gh_asset'] ); ?>" class="regular-text code" placeholder="acps-site-toolkit.zip">
						<p class="description"><?php esc_html_e( 'The exact filename of the build zip attached to each GitHub release (not the auto-generated source zip).', 'acps-site-toolkit' ); ?></p>

						<label for="acps-gh-token"><?php esc_html_e( 'Access token (private repos only)', 'acps-site-toolkit' ); ?></label>
						<input type="password" id="acps-gh-token" name="<?php echo esc_attr( $name( 'gh_token' ) ); ?>" value="" class="regular-text" autocomplete="off" placeholder="<?php echo $s['gh_token'] ? esc_attr__( '••••••••  (leave blank to keep)', 'acps-site-toolkit' ) : esc_attr__( 'not set — repo is public', 'acps-site-toolkit' ); ?>">
						<label><input type="checkbox" name="<?php echo esc_attr( $name( 'gh_token_clear' ) ); ?>" value="1"> <?php esc_html_e( 'Clear the saved token', 'acps-site-toolkit' ); ?></label>
						<p class="description"><?php esc_html_e( 'Leave blank for a public repo. For a private repo, a token is required and is never shown again once saved — leave it blank on later saves to keep it.', 'acps-site-toolkit' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="acps-update-trigger"><?php esc_html_e( 'Force-update secret', 'acps-site-toolkit' ); ?></label></th>
					<td>
						<input type="text" id="acps-update-trigger" name="<?php echo esc_attr( $name( 'update_trigger' ) ); ?>" value="<?php echo esc_attr( $s['update_trigger'] ); ?>" class="regular-text code">
						<label><input type="checkbox" name="<?php echo esc_attr( $name( 'update_trigger_regenerate' ) ); ?>" value="1"> <?php esc_html_e( 'Regenerate on save', 'acps-site-toolkit' ); ?></label>
						<?php $force_url = Updater::force_update_url(); ?>
						<?php if ( $force_url ) : ?>
							<p class="description">
								<?php esc_html_e( 'Visiting this URL forces an immediate check + install (e.g. from cron or a deploy hook). Keep it private — it is the only thing guarding it:', 'acps-site-toolkit' ); ?><br>
								<input type="text" readonly value="<?php echo esc_url( $force_url ); ?>" class="large-text code" onclick="this.select();">
							</p>
						<?php endif; ?>
					</td>
				</tr>
			</table>
		</div>

		<?php submit_button(); ?>
	</form>

	<!-- Deliberately OUTSIDE the form above (which posts to options.php) —
	     this is its own form posting to admin-post.php, same pattern as the
	     "Database tools" card near the top. A <form> nested inside another
	     <form> is invalid HTML; browsers silently close the outer one early,
	     which detaches Save from the fields above it. -->
	<div class="acps-card" style="border-left:4px solid #2271b1;max-width:48rem">
		<h2><?php esc_html_e( 'Update status', 'acps-site-toolkit' ); ?></h2>
		<p><?php echo esc_html( sprintf(
			/* translators: %s: installed plugin version */
			__( 'Installed version: %s', 'acps-site-toolkit' ),
			ACPS_ST_VERSION
		) ); ?></p>
		<?php $status = Updater::peek_status(); ?>
		<?php if ( ! $status['checked'] ) : ?>
			<p class="description"><?php esc_html_e( 'Not checked yet in this cache window.', 'acps-site-toolkit' ); ?></p>
		<?php elseif ( $status['remote'] ) : ?>
			<p><?php echo esc_html( sprintf(
				/* translators: %s: latest known version */
				__( 'Latest known version: %s', 'acps-site-toolkit' ),
				$status['remote']['version']
			) ); ?>
			<?php echo $status['has_update'] ? '— <strong>' . esc_html__( 'update available', 'acps-site-toolkit' ) . '</strong>' : '— ' . esc_html__( 'up to date', 'acps-site-toolkit' ); ?></p>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'The last check could not reach the configured source. Confirm the settings on the Updates tab above.', 'acps-site-toolkit' ); ?></p>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'acps_st_check_update' ); ?>
			<input type="hidden" name="action" value="acps_st_check_update">
			<button type="submit" class="button"><?php esc_html_e( 'Check for updates now', 'acps-site-toolkit' ); ?></button>
		</form>
		<p class="description"><?php esc_html_e( 'This checks the configured source right away instead of waiting for the normal cache window, and refreshes the Plugins screen\'s update status. Save any changed settings on the Updates tab first.', 'acps-site-toolkit' ); ?></p>
	</div>
</div>

<script>
( function () {
	var wrap   = document.getElementById( 'acps-tabs' );
	if ( ! wrap ) { return; }
	var tabs   = wrap.querySelectorAll( '.nav-tab' );
	var panels = document.querySelectorAll( '.acps-tab-panel' );
	function show( id ) {
		var found = false;
		panels.forEach( function ( p ) {
			var on = ( p.id === id );
			p.hidden = ! on;
			if ( on ) { found = true; }
		} );
		if ( ! found ) { return; }
		tabs.forEach( function ( t ) {
			t.classList.toggle( 'nav-tab-active', t.getAttribute( 'href' ) === '#' + id );
		} );
		try { sessionStorage.setItem( 'acpsStTab', id ); } catch ( e ) {}
	}
	tabs.forEach( function ( t ) {
		t.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			show( t.getAttribute( 'href' ).slice( 1 ) );
		} );
	} );
	// Restore the last tab after a save (options.php redirects back), or honour a #hash.
	var initial = '';
	if ( location.hash && document.getElementById( location.hash.slice( 1 ) ) ) {
		initial = location.hash.slice( 1 );
	} else {
		try { var saved = sessionStorage.getItem( 'acpsStTab' ); if ( saved && document.getElementById( saved ) ) { initial = saved; } } catch ( e ) {}
	}
	show( initial || 'acps-tab-features' );
} )();
</script>
