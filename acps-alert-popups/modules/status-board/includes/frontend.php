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

$acps_entries = ACPS_Alerts_Status::current_entries( true );
$acps_live    = ! empty( $acps_entries ) ? $acps_entries[0] : null;
$acps_extra   = array_slice( $acps_entries, 1 );

$acps_show_archive = ! isset( $settings->show_archive ) || '1' === (string) $settings->show_archive;
$acps_show_dates   = ! isset( $settings->archive_dates ) || '1' === (string) $settings->archive_dates;
$acps_count        = isset( $settings->archive_count ) ? absint( $settings->archive_count ) : 10;
$acps_align        = isset( $settings->banner_align ) ? $settings->banner_align : 'center';
$acps_date_format  = get_option( 'date_format' );

// Banner classes come from the module class, not a function declared here: two
// status boards on one page would redeclare it and fatal.
?>
<div class="acps-board">

	<?php if ( $acps_live ) : ?>
		<?php
		$acps_level  = ACPS_Alerts_Status::level( $acps_live->get( 'status_level' ) );
		$acps_msg    = (string) $acps_live->get( 'status_message' );
		$acps_staged = 'admins' === $acps_live->get( 'visibility' );
		?>

		<?php if ( $acps_staged ) : ?>
			<p class="acps-board__staged">
				<?php esc_html_e( 'Staff preview — visitors do not see this update yet.', 'acps-alert-popups' ); ?>
			</p>
		<?php endif; ?>

		<div class="<?php echo esc_attr( ACPS_Status_Board_Module::banner_classes( $acps_live, $settings ) ); ?>" style="<?php echo esc_attr( ACPS_Status_Board_Module::banner_style( $acps_live, $settings ) ); ?>" role="status">
			<h2 class="acps-board__title">
				<?php
				printf(
					/* translators: %s: the status word, e.g. LOCKDOWN. */
					esc_html__( 'School Status: %s', 'acps-alert-popups' ),
					esc_html( $acps_level['banner'] )
				);
				?>
			</h2>
			<?php if ( '' !== $acps_level['directive'] ) : ?>
				<p class="acps-board__directive"><?php echo esc_html( wp_strip_all_tags( $acps_level['directive'] ) ); ?></p>
			<?php endif; ?>
			<p class="acps-board__headline"><?php echo esc_html( $acps_live->get_title() ); ?></p>
			<?php if ( '' !== trim( $acps_msg ) ) : ?>
				<div class="acps-board__message"><?php echo wp_kses_post( wpautop( $acps_msg ) ); ?></div>
			<?php endif; ?>
		</div>

		<?php foreach ( $acps_extra as $acps_other ) : ?>
			<?php $acps_other_level = ACPS_Alerts_Status::level( $acps_other->get( 'status_level' ) ); ?>
			<div class="acps-board__also acps-board__also--<?php echo esc_attr( sanitize_html_class( $acps_other->get( 'status_level' ) ) ); ?>">
				<strong style="color:<?php echo esc_attr( $acps_other_level['color'] ); ?>"><?php echo esc_html( $acps_other_level['banner'] ); ?></strong>
				<?php if ( '' !== $acps_other_level['directive'] ) : ?>
					<em class="acps-board__also-directive"><?php echo esc_html( wp_strip_all_tags( $acps_other_level['directive'] ) ); ?></em>
				<?php endif; ?>
				<span><?php echo esc_html( $acps_other->get_title() ); ?></span>
				<?php if ( '' !== trim( (string) $acps_other->get( 'status_message' ) ) ) : ?>
					<p><?php echo esc_html( $acps_other->get( 'status_message' ) ); ?></p>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>

	<?php else : ?>
		<div class="<?php echo esc_attr( ACPS_Status_Board_Module::banner_classes( null, $settings ) ); ?>" style="<?php echo esc_attr( ACPS_Status_Board_Module::banner_style( null, $settings ) ); ?>" role="status">
			<h2 class="acps-board__title">
				<?php echo esc_html( isset( $settings->normal_title ) ? $settings->normal_title : __( 'School Status: NORMAL', 'acps-alert-popups' ) ); ?>
			</h2>
			<?php if ( ! empty( $settings->normal_message ) ) : ?>
				<div class="acps-board__message"><?php echo wp_kses_post( wpautop( $settings->normal_message ) ); ?></div>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php
	if ( $acps_show_archive ) :
		$acps_archive = ACPS_Alerts_Status::archive( $acps_count );
		?>
		<?php if ( ! empty( $acps_archive ) ) : ?>
			<div class="acps-board__archive">
				<h3 class="screen-reader-text"><?php esc_html_e( 'Past updates', 'acps-alert-popups' ); ?></h3>

				<?php foreach ( $acps_archive as $acps_item ) : ?>
					<?php
					$acps_item_level = ACPS_Alerts_Status::level( $acps_item->get( 'status_level' ) );
					$acps_item_msg   = (string) $acps_item->get( 'status_message' );
					$acps_item_date  = ACPS_Alerts_Status::posted_time( $acps_item );
					$acps_item_label = $acps_item->get_title();

					if ( $acps_show_dates && $acps_item_date ) {
						$acps_item_label .= ' ' . date_i18n( $acps_date_format, $acps_item_date );
					}
					?>
					<details class="acps-board__entry acps-board__entry--<?php echo esc_attr( sanitize_html_class( $acps_item->get( 'status_level' ) ) ); ?>">
						<summary>
							<span class="acps-board__entry-title"><?php echo esc_html( $acps_item_label ); ?></span>
							<span class="acps-board__entry-mark" aria-hidden="true"></span>
						</summary>
						<div class="acps-board__entry-body">
							<p class="acps-board__entry-level" style="color:<?php echo esc_attr( $acps_item_level['color'] ); ?>">
								<?php
								echo esc_html( $acps_item_level['banner'] );

								if ( '' !== $acps_item_level['directive'] ) {
									echo ' — ' . esc_html( wp_strip_all_tags( $acps_item_level['directive'] ) );
								}
								?>
							</p>
							<?php if ( '' !== trim( $acps_item_msg ) ) : ?>
								<?php echo wp_kses_post( wpautop( $acps_item_msg ) ); ?>
							<?php else : ?>
								<p><?php esc_html_e( 'No further detail was recorded for this update.', 'acps-alert-popups' ); ?></p>
							<?php endif; ?>
						</div>
					</details>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	<?php endif; ?>

	<?php
	// Inside the builder, show the editor what this module is for even when
	// there is nothing posted yet.
	if ( class_exists( 'FLBuilderModel' ) && FLBuilderModel::is_builder_active() ) :
		?>
		<p class="acps-board__hint">
			<?php esc_html_e( 'Status Board: edit this module to post an update. It appears here and, if you choose, as a popup across the site.', 'acps-alert-popups' ); ?>
			<?php if ( ! empty( $settings->last_posted ) ) : ?>
				<br />
				<?php
				printf(
					/* translators: %s: the headline that was posted. */
					esc_html__( 'Last posted: %s', 'acps-alert-popups' ),
					esc_html( get_the_title( (int) $settings->last_posted ) )
				);
				?>
			<?php endif; ?>
		</p>
	<?php endif; ?>
</div>
