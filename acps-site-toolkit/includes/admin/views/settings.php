<?php
/**
 * Settings view (spec §9.2). One page, WordPress Settings API, site options.
 *
 * @package ACPS\SiteToolkit
 */

namespace ACPS\SiteToolkit\Admin;

use ACPS\SiteToolkit\Settings;

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
	<h1><?php esc_html_e( 'Site Toolkit Settings', 'acps-site-toolkit' ); ?></h1>

	<form method="post" action="options.php">
		<?php settings_fields( Settings::GROUP ); ?>

		<h2 class="title"><?php esc_html_e( 'Feedback', 'acps-site-toolkit' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Feedback widget', 'acps-site-toolkit' ); ?></th>
				<td><label><input type="checkbox" name="<?php echo esc_attr( $name( 'feedback_enabled' ) ); ?>" value="1" <?php echo $checked( 'feedback_enabled' ); ?>> <?php esc_html_e( 'Show the floating feedback trigger', 'acps-site-toolkit' ); ?></label></td>
			</tr>
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
				<p class="description"><?php esc_html_e( 'Comma-separated. Blank uses the site admin email.', 'acps-site-toolkit' ); ?></p></td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Journey tracking & privacy', 'acps-site-toolkit' ); ?></h2>
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

		<h2 class="title"><?php esc_html_e( 'Stylesheet', 'acps-site-toolkit' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="acps-custom-css"><?php esc_html_e( 'Site Toolkit CSS', 'acps-site-toolkit' ); ?></label></th>
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

		<?php submit_button(); ?>
	</form>
</div>
