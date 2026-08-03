<?php
/**
 * Admin view: Permissions (spec Section 6 & 7).
 *
 * The capability list is whatever the registry reports (core + third-party), so
 * new capabilities appear here automatically.
 *
 * @var EXP_Admin $this
 * @package ExternalPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$registry = EXP_Registry::instance();
$caps     = $registry->capabilities();
$user_id  = isset( $_GET['user'] ) ? (int) $_GET['user'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
$user     = $user_id ? EXP_Users::get( $user_id ) : null;

/**
 * Resolve a capability's target options to [value => label].
 *
 * @param array $def Capability def.
 * @return array
 */
$resolve_options = static function ( $def ) {
	$opts = $def['target_options'];
	if ( is_callable( $opts ) ) {
		$opts = call_user_func( $opts );
	}
	return is_array( $opts ) ? $opts : array();
};
?>

<h2><?php esc_html_e( 'Permissions', 'external-portal' ); ?></h2>

<form method="get" class="exp-admin-filter">
	<input type="hidden" name="page" value="<?php echo esc_attr( EXP_Admin::PAGE ); ?>" />
	<input type="hidden" name="tab" value="permissions" />
	<label for="exp-perm-user"><?php esc_html_e( 'Choose a portal user:', 'external-portal' ); ?></label>
	<select id="exp-perm-user" name="user">
		<option value="0"><?php esc_html_e( '— Select —', 'external-portal' ); ?></option>
		<?php
		$all = EXP_Users::query( array( 'per_page' => 500 ) );
		foreach ( $all['rows'] as $u ) {
			printf(
				'<option value="%1$d"%2$s>%3$s</option>',
				(int) $u->id,
				selected( $user_id, $u->id, false ),
				esc_html( $u->email . ( $u->display_name ? ' (' . $u->display_name . ')' : '' ) )
			);
		}
		?>
	</select>
	<?php submit_button( __( 'Go', 'external-portal' ), 'secondary', '', false ); ?>
</form>

<?php if ( $user ) : ?>
	<h3><?php echo esc_html( sprintf( __( 'Grants for %s', 'external-portal' ), $user->email ) ); ?></h3>

	<?php
	// Presets.
	$presets = EXP_Admin::presets();
	if ( ! empty( $presets ) ) :
		?>
		<div class="exp-presets">
			<h4><?php esc_html_e( 'Apply a preset', 'external-portal' ); ?></h4>
			<?php foreach ( $presets as $pkey => $preset ) : ?>
				<form method="post" class="exp-inline-form">
					<?php echo $this->form_fields( 'apply_preset', 'permissions' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<input type="hidden" name="user_id" value="<?php echo esc_attr( $user->id ); ?>" />
					<input type="hidden" name="preset" value="<?php echo esc_attr( $pkey ); ?>" />
					<button type="submit" class="button"><?php echo esc_html( $preset['label'] ); ?></button>
				</form>
			<?php endforeach; ?>
			<p class="description"><?php esc_html_e( 'Presets add grants on top of the current selection. Review and save below.', 'external-portal' ); ?></p>
		</div>
	<?php endif; ?>

	<form method="post">
		<?php echo $this->form_fields( 'save_permissions', 'permissions' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<input type="hidden" name="user_id" value="<?php echo esc_attr( $user->id ); ?>" />

		<?php foreach ( $caps as $key => $def ) : ?>
			<fieldset class="exp-cap">
				<legend><strong><?php echo esc_html( $def['label'] ); ?></strong> <code><?php echo esc_html( $key ); ?></code></legend>
				<?php if ( $def['description'] ) : ?>
					<p class="description"><?php echo esc_html( $def['description'] ); ?></p>
				<?php endif; ?>

				<?php
				$granted = array_map( 'strval', EXP_Permissions::targets_for( $user->id, $key ) );

				if ( 'none' === $def['target_type'] ) :
					?>
					<label><input type="checkbox" name="grants[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( in_array( '', $granted, true ) ); ?> /> <?php esc_html_e( 'Granted', 'external-portal' ); ?></label>
				<?php else : ?>
					<?php
					$options = $resolve_options( $def );
					if ( empty( $options ) ) :
						?>
						<p class="description"><?php esc_html_e( 'No targets are available to grant yet.', 'external-portal' ); ?></p>
					<?php else : ?>
						<ul class="exp-target-list">
						<?php foreach ( $options as $value => $label ) : ?>
							<li><label><input type="checkbox" name="grants[<?php echo esc_attr( $key ); ?>][]" value="<?php echo esc_attr( $value ); ?>" <?php checked( in_array( (string) $value, $granted, true ) ); ?> /> <?php echo esc_html( $label ); ?></label></li>
						<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				<?php endif; ?>
			</fieldset>
		<?php endforeach; ?>

		<?php submit_button( __( 'Save permissions', 'external-portal' ) ); ?>
	</form>
<?php else : ?>
	<p><?php esc_html_e( 'Select a user above to manage their grants.', 'external-portal' ); ?></p>
<?php endif; ?>

<hr />

<h3><?php esc_html_e( 'Bulk: revoke a capability from everyone', 'external-portal' ); ?></h3>
<form method="post" class="exp-inline-form" onsubmit="return confirm('<?php echo esc_js( __( 'Revoke this capability from all portal users?', 'external-portal' ) ); ?>');">
	<?php echo $this->form_fields( 'revoke_cap_everywhere', 'permissions' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	<label class="screen-reader-text" for="exp-revoke-cap"><?php esc_html_e( 'Capability', 'external-portal' ); ?></label>
	<select id="exp-revoke-cap" name="capability">
		<?php foreach ( $caps as $key => $def ) : ?>
			<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $def['label'] . ' (' . $key . ')' ); ?></option>
		<?php endforeach; ?>
	</select>
	<button type="submit" class="button button-secondary"><?php esc_html_e( 'Revoke everywhere', 'external-portal' ); ?></button>
</form>
