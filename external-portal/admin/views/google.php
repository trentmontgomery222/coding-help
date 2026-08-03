<?php
/**
 * Admin view: Google Integration (spec Section 5.3 & 6).
 *
 * @var EXP_Admin $this
 * @package ExternalPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$has_creds   = '' !== (string) EXP_Settings::get( 'google_service_account', '' );
$impersonate = (string) EXP_Settings::get( 'google_impersonate_user', '' );
$whitelist   = (array) EXP_Settings::get( 'google_calendar_whitelist', array() );
if ( empty( $whitelist ) ) {
	$whitelist = array( array( 'id' => '', 'label' => '' ) );
}
?>
<h2><?php esc_html_e( 'Google Calendar Integration', 'external-portal' ); ?></h2>
<p><?php esc_html_e( 'One shared service account connects the portal to Google Calendar. Portal users never sign in to Google — sharing changes are made on their behalf using these credentials.', 'external-portal' ); ?></p>

<form method="post">
	<?php echo $this->form_fields( 'save_google', 'google' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="exp-sa"><?php esc_html_e( 'Service account JSON', 'external-portal' ); ?></label></th>
			<td>
				<textarea id="exp-sa" name="service_account" rows="8" class="large-text code" placeholder="<?php esc_attr_e( 'Paste the service account key JSON to update it', 'external-portal' ); ?>"></textarea>
				<p class="description">
					<?php
					echo $has_creds
						? esc_html__( 'Credentials are currently stored. Paste new JSON only to replace them; leave blank to keep the existing key.', 'external-portal' )
						: esc_html__( 'No credentials stored yet. Paste the downloaded service account key JSON.', 'external-portal' );
					?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="exp-imp"><?php esc_html_e( 'Impersonate user (optional)', 'external-portal' ); ?></label></th>
			<td>
				<input type="email" id="exp-imp" name="impersonate" class="regular-text" value="<?php echo esc_attr( $impersonate ); ?>" />
				<p class="description"><?php esc_html_e( 'For domain-wide delegation: the Workspace user the service account acts as. Leave blank if the service account owns/shares the calendars directly.', 'external-portal' ); ?></p>
			</td>
		</tr>
	</table>

	<h3><?php esc_html_e( 'Calendar whitelist', 'external-portal' ); ?></h3>
	<p class="description"><?php esc_html_e( 'Only calendars listed here can be assigned to portal users on the Permissions screen.', 'external-portal' ); ?></p>
	<table class="widefat exp-whitelist">
		<thead><tr><th scope="col"><?php esc_html_e( 'Calendar ID', 'external-portal' ); ?></th><th scope="col"><?php esc_html_e( 'Label', 'external-portal' ); ?></th></tr></thead>
		<tbody>
		<?php foreach ( $whitelist as $row ) : ?>
			<tr>
				<td><input type="text" name="cal_id[]" class="regular-text" value="<?php echo esc_attr( $row['id'] ?? '' ); ?>" placeholder="example@group.calendar.google.com" /></td>
				<td><input type="text" name="cal_label[]" class="regular-text" value="<?php echo esc_attr( $row['label'] ?? '' ); ?>" /></td>
			</tr>
		<?php endforeach; ?>
			<tr>
				<td><input type="text" name="cal_id[]" class="regular-text" value="" placeholder="example@group.calendar.google.com" /></td>
				<td><input type="text" name="cal_label[]" class="regular-text" value="" /></td>
			</tr>
		</tbody>
	</table>
	<p class="description"><?php esc_html_e( 'Save to add more rows.', 'external-portal' ); ?></p>

	<?php submit_button( __( 'Save Google settings', 'external-portal' ) ); ?>
</form>

<hr />
<form method="post">
	<?php echo $this->form_fields( 'test_google', 'google' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	<button type="submit" class="button button-secondary"><?php esc_html_e( 'Test connection', 'external-portal' ); ?></button>
	<p class="description"><?php esc_html_e( 'Attempts to obtain an access token with the stored credentials.', 'external-portal' ); ?></p>
</form>
