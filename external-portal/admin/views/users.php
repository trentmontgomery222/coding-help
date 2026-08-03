<?php
/**
 * Admin view: Users (spec Section 6).
 *
 * @var EXP_Admin $this
 * @package ExternalPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
$status_f = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
$paged    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
$per_page = 20;

$result = EXP_Users::query(
	array(
		'search'   => $search,
		'status'   => $status_f,
		'per_page' => $per_page,
		'page'     => $paged,
	)
);
$rows  = $result['rows'];
$total = (int) $result['total'];
?>

<h2><?php esc_html_e( 'Create a portal account', 'external-portal' ); ?></h2>
<form method="post" class="exp-admin-form">
	<?php echo $this->form_fields( 'create_user', 'users' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="exp-new-email"><?php esc_html_e( 'Email address', 'external-portal' ); ?></label></th>
			<td><input name="email" id="exp-new-email" type="email" class="regular-text" required aria-required="true" /></td>
		</tr>
		<tr>
			<th scope="row"><label for="exp-new-name"><?php esc_html_e( 'Display name', 'external-portal' ); ?></label></th>
			<td><input name="display_name" id="exp-new-name" type="text" class="regular-text" /></td>
		</tr>
		<tr>
			<th scope="row"><label for="exp-new-mode"><?php esc_html_e( 'Sign-in method', 'external-portal' ); ?></label></th>
			<td>
				<select name="auth_mode" id="exp-new-mode">
					<option value="otp"><?php esc_html_e( 'One-time code by email only', 'external-portal' ); ?></option>
					<option value="password_otp"><?php esc_html_e( 'Password, with email code fallback', 'external-portal' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'Users can set their own password later from their dashboard.', 'external-portal' ); ?></p>
			</td>
		</tr>
	</table>
	<?php submit_button( __( 'Create account', 'external-portal' ) ); ?>
</form>

<hr />

<h2><?php esc_html_e( 'Portal users', 'external-portal' ); ?></h2>

<form method="get" class="exp-admin-filter">
	<input type="hidden" name="page" value="<?php echo esc_attr( EXP_Admin::PAGE ); ?>" />
	<input type="hidden" name="tab" value="users" />
	<label class="screen-reader-text" for="exp-user-search"><?php esc_html_e( 'Search users', 'external-portal' ); ?></label>
	<input type="search" id="exp-user-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search email or name', 'external-portal' ); ?>" />
	<label class="screen-reader-text" for="exp-user-status"><?php esc_html_e( 'Filter by status', 'external-portal' ); ?></label>
	<select id="exp-user-status" name="status">
		<option value=""><?php esc_html_e( 'All statuses', 'external-portal' ); ?></option>
		<?php
		foreach ( array( 'active', 'invited', 'disabled' ) as $st ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $st ), selected( $status_f, $st, false ), esc_html( ucfirst( $st ) ) );
		}
		?>
	</select>
	<?php submit_button( __( 'Filter', 'external-portal' ), 'secondary', '', false ); ?>
</form>

<form method="post">
	<?php echo $this->form_fields( 'user_bulk', 'users' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	<div class="tablenav top">
		<label class="screen-reader-text" for="exp-bulk"><?php esc_html_e( 'Bulk action', 'external-portal' ); ?></label>
		<select name="bulk_action" id="exp-bulk">
			<option value=""><?php esc_html_e( 'Bulk actions', 'external-portal' ); ?></option>
			<option value="enable"><?php esc_html_e( 'Enable', 'external-portal' ); ?></option>
			<option value="disable"><?php esc_html_e( 'Disable', 'external-portal' ); ?></option>
			<option value="revoke_sessions"><?php esc_html_e( 'Force sign-out (revoke sessions)', 'external-portal' ); ?></option>
			<option value="delete"><?php esc_html_e( 'Delete', 'external-portal' ); ?></option>
		</select>
		<?php submit_button( __( 'Apply', 'external-portal' ), 'secondary', '', false ); ?>
	</div>

	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<td class="check-column"><input type="checkbox" onclick="jQuery('.exp-ucb').prop('checked', this.checked)" aria-label="<?php esc_attr_e( 'Select all', 'external-portal' ); ?>" /></td>
				<th scope="col"><?php esc_html_e( 'Email', 'external-portal' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Name', 'external-portal' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'external-portal' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Last login', 'external-portal' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Actions', 'external-portal' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php if ( empty( $rows ) ) : ?>
			<tr><td colspan="6"><?php esc_html_e( 'No portal users found.', 'external-portal' ); ?></td></tr>
		<?php else : ?>
			<?php
			foreach ( $rows as $u ) :
				$locked = ! empty( $u->locked_until ) && ! EXP_Util::is_past( $u->locked_until );
				$last   = $u->last_login_at ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $u->last_login_at . ' UTC' ) ) : '—';
				$perm   = $this->page_url( array( 'tab' => 'permissions', 'user' => $u->id ) );
				?>
			<tr>
				<th scope="row" class="check-column"><input class="exp-ucb" type="checkbox" name="user_ids[]" value="<?php echo esc_attr( $u->id ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Select %s', 'external-portal' ), $u->email ) ); ?>" /></th>
				<td><?php echo esc_html( $u->email ); ?></td>
				<td><?php echo esc_html( $u->display_name ? $u->display_name : '—' ); ?></td>
				<td>
					<?php echo esc_html( ucfirst( $u->status ) ); ?>
					<?php if ( $locked ) : ?><br /><span class="exp-badge exp-badge--warn"><?php esc_html_e( 'Locked', 'external-portal' ); ?></span><?php endif; ?>
				</td>
				<td><?php echo esc_html( $last ); ?></td>
				<td class="exp-row-actions">
					<a href="<?php echo esc_url( $perm ); ?>"><?php esc_html_e( 'Permissions', 'external-portal' ); ?></a>
					<?php
					// Row actions are nonce-protected links (no nested forms inside the bulk form).
					$row_ops = array(
						'enable'          => __( 'Enable', 'external-portal' ),
						'disable'         => __( 'Disable', 'external-portal' ),
						'unlock'          => __( 'Unlock', 'external-portal' ),
						'revoke_sessions' => __( 'Sign out', 'external-portal' ),
						'delete'          => __( 'Delete', 'external-portal' ),
					);
					foreach ( $row_ops as $op => $label ) :
						$link = wp_nonce_url(
							$this->page_url( array( 'tab' => 'users', 'exp_row' => $op, 'user' => $u->id ) ),
							'exp_row_' . $u->id
						);
						$confirm = 'delete' === $op ? ' onclick="return confirm(\'' . esc_js( __( 'Delete this portal user and all their data?', 'external-portal' ) ) . '\');"' : '';
						printf(
							'<a class="button-link%1$s" href="%2$s"%3$s>%4$s</a>',
							'delete' === $op ? ' exp-row-delete' : '',
							esc_url( $link ),
							$confirm, // phpcs:ignore WordPress.Security.EscapeOutput -- static, esc_js applied.
							esc_html( $label )
						);
					endforeach;
					?>
				</td>
			</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
	</table>
</form>

<?php
$pages = (int) ceil( $total / $per_page );
if ( $pages > 1 ) {
	echo '<div class="tablenav"><div class="tablenav-pages">';
	echo wp_kses_post(
		paginate_links(
			array(
				'base'      => add_query_arg( 'paged', '%#%', $this->page_url( array( 'tab' => 'users', 's' => $search, 'status' => $status_f ) ) ),
				'format'    => '',
				'current'   => $paged,
				'total'     => $pages,
				'prev_text' => __( '&laquo; Previous', 'external-portal' ),
				'next_text' => __( 'Next &raquo;', 'external-portal' ),
			)
		)
	);
	echo '</div></div>';
}
