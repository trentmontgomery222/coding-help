<?php
/**
 * Front end markup for the Status Board module.
 *
 * @package ACPS_Alert_Popups
 */

defined( 'ABSPATH' ) || exit;

if ( ! isset( $settings ) || ! is_object( $settings ) || ! class_exists( 'ACPS_Alerts_Status' ) ) {
	return;
}

// One banner: the Current Alert when it is showing, the resting state when not.
$acps_live   = ACPS_Alerts_Status::board_entry();
$acps_normal = ACPS_Alerts_Status::normal_alert();

$acps_show_archive = ! isset( $settings->show_archive ) || '1' === (string) $settings->show_archive;
$acps_show_dates   = ! isset( $settings->archive_dates ) || '1' === (string) $settings->archive_dates;
$acps_count        = isset( $settings->archive_count ) ? absint( $settings->archive_count ) : 10;
$acps_date_format  = get_option( 'date_format' );

// Banner classes come from the module class, not a function declared here: two
// status boards on one page would redeclare it and fatal.
?>
<div class="acps-board">

	<?php if ( $acps_live ) : ?>
		<?php
		$acps_msg    = (string) $acps_live->get( 'status_message' );
		$acps_staged = 'admins' === $acps_live->get( 'visibility' );
		?>

		<?php if ( $acps_staged ) : ?>
			<p class="acps-board__staged">
				<?php esc_html_e( 'Staff preview — visitors do not see this alert yet.', 'acps-alert-popups' ); ?>
			</p>
		<?php endif; ?>

		<div class="<?php echo esc_attr( ACPS_Status_Board_Module::banner_classes( $acps_live, $settings ) ); ?>" style="<?php echo esc_attr( ACPS_Status_Board_Module::banner_style( $acps_live, $settings ) ); ?>" role="status">
			<?php
			/*
			 * The banner is the heading and the message, and nothing else. No
			 * badge, no level word, no directive — the level shows in the
			 * banner's colour, and anywhere those pieces are wanted they can be
			 * placed with [schoolstatus], which is what that shortcode is for.
			 *
			 * The head carries its own background so the heading reads as a
			 * heading rather than as the first line of the message.
			 */
			?>
			<div class="acps-board__head">
				<h2 class="acps-board__title"><?php echo esc_html( $acps_live->get_title() ); ?></h2>
			</div>

			<?php if ( '' !== trim( $acps_msg ) ) : ?>
				<div class="acps-board__message"><?php echo wp_kses_post( wpautop( $acps_msg ) ); ?></div>
			<?php endif; ?>
		</div>

	<?php else : ?>
		<?php
		// The resting state. Its wording comes from the Normal Alert when that
		// has been written, so it can be edited like anything else; the module's
		// own text is the fallback for a site that has not touched it.
		$acps_normal_title = isset( $settings->normal_title ) ? $settings->normal_title : __( 'School Status: NORMAL', 'acps-alert-popups' );
		$acps_normal_msg   = isset( $settings->normal_message ) ? $settings->normal_message : '';

		if ( $acps_normal ) {
			$acps_normal_stored = (string) $acps_normal->get( 'status_message' );

			if ( '' !== trim( $acps_normal_stored ) ) {
				$acps_normal_msg = $acps_normal_stored;
			}
		}
		?>
		<div class="<?php echo esc_attr( ACPS_Status_Board_Module::banner_classes( null, $settings ) ); ?>" style="<?php echo esc_attr( ACPS_Status_Board_Module::banner_style( null, $settings ) ); ?>" role="status">
			<div class="acps-board__head">
				<h2 class="acps-board__title"><?php echo esc_html( $acps_normal_title ); ?></h2>
			</div>

			<?php if ( '' !== trim( (string) $acps_normal_msg ) ) : ?>
				<div class="acps-board__message"><?php echo wp_kses_post( wpautop( $acps_normal_msg ) ); ?></div>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php
	// The archive is internal now: past updates are kept for the office to look
	// back on in wp-admin (Site Alerts → Archive), not shown to visitors. The
	// board on the public page is only the current status.
	unset( $acps_show_archive, $acps_show_dates, $acps_count );
	?>

	<?php
	// Inside the builder, say plainly what this module drives.
	if ( class_exists( 'FLBuilderModel' ) && FLBuilderModel::is_builder_active() ) :
		?>
		<p class="acps-board__hint">
			<?php esc_html_e( 'Status Board: this is only the template. It draws whatever the Current Alert says, in the colour of its status level.', 'acps-alert-popups' ); ?>
			<br />
			<?php esc_html_e( 'To change the alert, edit the Current Alert popup module on this page. This module only holds the normal-day wording, the archive, and the board colours.', 'acps-alert-popups' ); ?>
			<?php if ( $acps_live ) : ?>
				<br /><?php esc_html_e( 'The Current Alert is showing now.', 'acps-alert-popups' ); ?>
			<?php else : ?>
				<br /><?php esc_html_e( 'The Current Alert is switched off, so the board is showing the normal state.', 'acps-alert-popups' ); ?>
			<?php endif; ?>
		</p>
	<?php endif; ?>
</div>
