<?php
/**
 * Admin view: Extensions approval gate (spec Section 7 governance decision).
 *
 * @var EXP_Admin $this
 * @package ExternalPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$registry = EXP_Registry::instance();
$records  = $registry->extension_records();
$gate_on  = (bool) EXP_Settings::get( 'extensions_require_approval', 1 );
?>
<h2><?php esc_html_e( 'Registered Extensions', 'external-portal' ); ?></h2>
<p>
	<?php
	echo $gate_on
		? esc_html__( 'Approval gate is ON: a third-party menu item is hidden from portal users until you approve it here.', 'external-portal' )
		: esc_html__( 'Approval gate is OFF: registered menu items are visible to portal users immediately. You can turn the gate on under Settings.', 'external-portal' );
	?>
</p>

<?php if ( empty( $records ) ) : ?>
	<p><?php esc_html_e( 'No third-party extensions have registered yet. (Core modules are always available and are not listed here.)', 'external-portal' ); ?></p>
<?php else : ?>
	<form method="post">
		<?php echo $this->form_fields( 'save_extensions', 'extensions' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Approved', 'external-portal' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Menu item', 'external-portal' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Slug', 'external-portal' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Source', 'external-portal' ); ?></th>
					<th scope="col"><?php esc_html_e( 'First seen', 'external-portal' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $records as $rec ) : ?>
				<tr>
					<td>
						<label class="screen-reader-text" for="exp-ext-<?php echo esc_attr( $rec->slug ); ?>"><?php echo esc_html( sprintf( __( 'Approve %s', 'external-portal' ), $rec->label ) ); ?></label>
						<input type="checkbox" id="exp-ext-<?php echo esc_attr( $rec->slug ); ?>" name="approved[]" value="<?php echo esc_attr( $rec->slug ); ?>" <?php checked( (int) $rec->approved, 1 ); ?> />
					</td>
					<td><?php echo esc_html( $rec->label ? $rec->label : $rec->slug ); ?></td>
					<td><code><?php echo esc_html( $rec->slug ); ?></code></td>
					<td><?php echo esc_html( $rec->source_plugin ? $rec->source_plugin : '—' ); ?></td>
					<td><?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $rec->first_seen_at . ' UTC' ) ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php submit_button( __( 'Save approvals', 'external-portal' ) ); ?>
	</form>
<?php endif; ?>
