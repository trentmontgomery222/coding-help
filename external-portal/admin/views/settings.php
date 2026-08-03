<?php
/**
 * Admin view: General Settings (spec Section 6 & Section 8 decisions).
 *
 * @var EXP_Admin $this
 * @package ExternalPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$s = EXP_Settings::all();

$page_dropdown = static function ( $name, $selected ) {
	return wp_dropdown_pages(
		array(
			'name'              => $name,
			'id'                => 'exp-' . $name,
			'selected'          => (int) $selected,
			'show_option_none'  => __( '— Select a page —', 'external-portal' ),
			'option_none_value' => 0,
			'echo'              => 0,
		)
	);
};
?>
<h2><?php esc_html_e( 'General Settings', 'external-portal' ); ?></h2>
<form method="post">
	<?php echo $this->form_fields( 'save_settings', 'settings' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>

	<h3><?php esc_html_e( 'Portal pages', 'external-portal' ); ?></h3>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="exp-login_page_id"><?php esc_html_e( 'Login page', 'external-portal' ); ?></label></th>
			<td><?php echo $page_dropdown( 'login_page_id', $s['login_page_id'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<p class="description"><?php echo esc_html__( 'The page containing the', 'external-portal' ) . ' <code>[external_portal_login]</code> ' . esc_html__( 'shortcode.', 'external-portal' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="exp-dashboard_page_id"><?php esc_html_e( 'Dashboard page', 'external-portal' ); ?></label></th>
			<td><?php echo $page_dropdown( 'dashboard_page_id', $s['dashboard_page_id'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<p class="description"><?php echo esc_html__( 'The page containing the', 'external-portal' ) . ' <code>[external_portal_dashboard]</code> ' . esc_html__( 'shortcode.', 'external-portal' ); ?></p>
			</td>
		</tr>
	</table>

	<h3><?php esc_html_e( 'One-time codes', 'external-portal' ); ?></h3>
	<table class="form-table" role="presentation">
		<tr><th scope="row"><label for="exp-otp_length"><?php esc_html_e( 'Code length', 'external-portal' ); ?></label></th>
			<td><input type="number" min="4" max="10" id="exp-otp_length" name="otp_length" value="<?php echo esc_attr( $s['otp_length'] ); ?>" class="small-text" /></td></tr>
		<tr><th scope="row"><label for="exp-otp_ttl_minutes"><?php esc_html_e( 'Code lifetime (minutes)', 'external-portal' ); ?></label></th>
			<td><input type="number" min="1" id="exp-otp_ttl_minutes" name="otp_ttl_minutes" value="<?php echo esc_attr( $s['otp_ttl_minutes'] ); ?>" class="small-text" /></td></tr>
		<tr><th scope="row"><label for="exp-otp_max_attempts"><?php esc_html_e( 'Max attempts per code', 'external-portal' ); ?></label></th>
			<td><input type="number" min="1" id="exp-otp_max_attempts" name="otp_max_attempts" value="<?php echo esc_attr( $s['otp_max_attempts'] ); ?>" class="small-text" /></td></tr>
	</table>

	<h3><?php esc_html_e( 'Sessions', 'external-portal' ); ?></h3>
	<table class="form-table" role="presentation">
		<tr><th scope="row"><label for="exp-session_idle_minutes"><?php esc_html_e( 'Inactivity timeout (minutes)', 'external-portal' ); ?></label></th>
			<td><input type="number" min="1" id="exp-session_idle_minutes" name="session_idle_minutes" value="<?php echo esc_attr( $s['session_idle_minutes'] ); ?>" class="small-text" /></td></tr>
		<tr><th scope="row"><label for="exp-session_absolute_hours"><?php esc_html_e( 'Maximum session length (hours)', 'external-portal' ); ?></label></th>
			<td><input type="number" min="1" id="exp-session_absolute_hours" name="session_absolute_hours" value="<?php echo esc_attr( $s['session_absolute_hours'] ); ?>" class="small-text" /></td></tr>
		<tr><th scope="row"><label for="exp-session_warn_seconds"><?php esc_html_e( 'Expiry warning lead time (seconds)', 'external-portal' ); ?></label></th>
			<td><input type="number" min="0" id="exp-session_warn_seconds" name="session_warn_seconds" value="<?php echo esc_attr( $s['session_warn_seconds'] ); ?>" class="small-text" />
				<p class="description"><?php esc_html_e( 'A warning is announced (ARIA live region) this many seconds before an idle session expires. 0 disables it.', 'external-portal' ); ?></p></td></tr>
	</table>

	<h3><?php esc_html_e( 'Lockout & passwords', 'external-portal' ); ?></h3>
	<table class="form-table" role="presentation">
		<tr><th scope="row"><label for="exp-login_lockout_threshold"><?php esc_html_e( 'Failed attempts before lockout', 'external-portal' ); ?></label></th>
			<td><input type="number" min="1" id="exp-login_lockout_threshold" name="login_lockout_threshold" value="<?php echo esc_attr( $s['login_lockout_threshold'] ); ?>" class="small-text" /></td></tr>
		<tr><th scope="row"><label for="exp-login_lockout_minutes"><?php esc_html_e( 'Lockout duration (minutes)', 'external-portal' ); ?></label></th>
			<td><input type="number" min="1" id="exp-login_lockout_minutes" name="login_lockout_minutes" value="<?php echo esc_attr( $s['login_lockout_minutes'] ); ?>" class="small-text" /></td></tr>
		<tr><th scope="row"><label for="exp-password_min_length"><?php esc_html_e( 'Minimum password length', 'external-portal' ); ?></label></th>
			<td><input type="number" min="8" id="exp-password_min_length" name="password_min_length" value="<?php echo esc_attr( $s['password_min_length'] ); ?>" class="small-text" /></td></tr>
	</table>

	<h3><?php esc_html_e( 'Notifications', 'external-portal' ); ?></h3>
	<table class="form-table" role="presentation">
		<tr><th scope="row"><label for="exp-admin_notify_email"><?php esc_html_e( 'Admin notification email', 'external-portal' ); ?></label></th>
			<td><input type="email" id="exp-admin_notify_email" name="admin_notify_email" value="<?php echo esc_attr( $s['admin_notify_email'] ); ?>" class="regular-text" /></td></tr>
		<tr><th scope="row"><label for="exp-email_from_name"><?php esc_html_e( 'Email “from” name', 'external-portal' ); ?></label></th>
			<td><input type="text" id="exp-email_from_name" name="email_from_name" value="<?php echo esc_attr( $s['email_from_name'] ); ?>" class="regular-text" /></td></tr>
		<tr><th scope="row"><?php esc_html_e( 'New queue items', 'external-portal' ); ?></th>
			<td><label><input type="checkbox" name="notify_on_new_queue_item" value="1" <?php checked( (int) $s['notify_on_new_queue_item'], 1 ); ?> /> <?php esc_html_e( 'Email the admin when a portal user submits something', 'external-portal' ); ?></label></td></tr>
	</table>

	<h3><?php esc_html_e( 'Governance', 'external-portal' ); ?></h3>
	<table class="form-table" role="presentation">
		<tr><th scope="row"><?php esc_html_e( 'Calendar changes', 'external-portal' ); ?></th>
			<td><label><input type="checkbox" name="calendar_requires_approval" value="1" <?php checked( (int) $s['calendar_requires_approval'], 1 ); ?> /> <?php esc_html_e( 'Require admin approval for calendar sharing changes (otherwise applied live)', 'external-portal' ); ?></label></td></tr>
		<tr><th scope="row"><?php esc_html_e( 'Third-party extensions', 'external-portal' ); ?></th>
			<td><label><input type="checkbox" name="extensions_require_approval" value="1" <?php checked( (int) $s['extensions_require_approval'], 1 ); ?> /> <?php esc_html_e( 'Require admin approval before a registered extension appears to portal users', 'external-portal' ); ?></label></td></tr>
	</table>

	<?php submit_button( __( 'Save settings', 'external-portal' ) ); ?>
</form>
