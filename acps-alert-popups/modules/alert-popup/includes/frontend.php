<?php
/**
 * Front end markup for the Current Alert module.
 *
 * On a live page this renders nothing. The module exists so the alert can be
 * written and styled on the status page; the popup itself is printed in the
 * footer of every *other* page by ACPS_Alerts_Frontend, which is what lets one
 * module drive the whole site.
 *
 * Inside the builder it draws itself as a popup card, so there is something to
 * look at and click on while you edit it.
 *
 * @package ACPS_Alert_Popups
 */

defined( 'ABSPATH' ) || exit;

// Beaver Builder includes this file directly, with no hook of ours on the
// stack to catch a failure. So it re-runs itself under the failsafe: a throw
// anywhere below draws nothing for this module instead of breaking the page.
if ( empty( $acps_guarded ) && class_exists( 'ACPS_Alerts_Failsafe' ) && method_exists( 'ACPS_Alerts_Failsafe', 'render_template' ) ) {
	echo ACPS_Alerts_Failsafe::render_template( __FILE__, get_defined_vars(), 'module/alert-popup' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The template escapes its own output.

	return;
}

if ( ! isset( $settings ) || ! is_object( $settings ) ) {
	return;
}

if ( ! class_exists( 'ACPS_Alert_Popup_Module' ) || ! ACPS_Alert_Popup_Module::in_builder() ) {
	return; // Live page: the popup is shown everywhere else, never here.
}

$acps_heading = isset( $settings->heading ) ? (string) $settings->heading : '';
$acps_text    = isset( $settings->text ) ? (string) $settings->text : '';
$acps_live    = isset( $settings->active ) && '1' === (string) $settings->active;
$acps_popup   = ! isset( $settings->as_popup ) || '1' === (string) $settings->as_popup;
$acps_staged  = isset( $settings->visibility ) && 'public' !== (string) $settings->visibility;

$acps_level = class_exists( 'ACPS_Alerts_Status' )
	? ACPS_Alerts_Status::level( isset( $settings->level ) ? $settings->level : 'info' )
	: array( 'banner' => '', 'directive' => '', 'color' => '' );

$acps_color = isset( $acps_level['color'] ) && preg_match( '/^#[0-9a-f]{3,8}$/i', (string) $acps_level['color'] )
	? (string) $acps_level['color']
	: '#1b2f5e';

$acps_key      = isset( $settings->level ) ? (string) $settings->level : 'info';
$acps_icon      = ! isset( $settings->show_icon ) || '1' === (string) $settings->show_icon;
$acps_icon_size = isset( $settings->icon_size ) ? absint( $settings->icon_size ) : 56;
$acps_word     = isset( $settings->show_word ) && '1' === (string) $settings->show_word;
$acps_cta      = isset( $settings->cta_text ) ? trim( (string) $settings->cta_text ) : '';

if ( '' === trim( $acps_heading ) ) {
	$acps_heading = __( 'Your alert heading goes here', 'acps-alert-popups' );
}
?>
<div class="acps-popup-edit<?php echo $acps_live ? ' is-live' : ''; ?>">

	<p class="acps-popup-edit__flag">
		<?php if ( $acps_live ) : ?>
			<span class="acps-popup-edit__dot" style="background:<?php echo esc_attr( $acps_color ); ?>"></span>
			<?php esc_html_e( 'This alert is ON. Visitors are seeing it right now.', 'acps-alert-popups' ); ?>
		<?php else : ?>
			<span class="acps-popup-edit__dot"></span>
			<?php esc_html_e( 'This alert is OFF. Nobody is seeing it.', 'acps-alert-popups' ); ?>
		<?php endif; ?>
	</p>

	<div class="acps-popup-edit__stage">
		<div class="acps-popup-edit__dialog" style="border-top-color:<?php echo esc_attr( $acps_color ); ?>">
			<span class="acps-popup-edit__close" aria-hidden="true">&times;</span>

			<?php if ( $acps_icon && class_exists( 'ACPS_Alerts_Status' ) ) : ?>
				<?php echo ACPS_Alerts_Status::level_icon( $acps_key, $acps_icon_size ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped at source. ?>
			<?php endif; ?>

			<?php if ( $acps_word && '' !== (string) $acps_level['banner'] ) : ?>
				<p class="acps-popup-edit__level" style="color:<?php echo esc_attr( $acps_color ); ?>">
					<?php echo esc_html( $acps_level['banner'] ); ?>
				</p>
			<?php endif; ?>

			<h2 class="acps-popup-edit__heading"><?php echo esc_html( $acps_heading ); ?></h2>

			<?php if ( '' !== trim( wp_strip_all_tags( $acps_text ) ) ) : ?>
				<div class="acps-popup-edit__text"><?php echo wp_kses_post( wpautop( $acps_text ) ); ?></div>
			<?php else : ?>
				<p class="acps-popup-edit__text acps-popup-edit__text--empty">
					<?php esc_html_e( 'Add the text of the alert on the Popup tab.', 'acps-alert-popups' ); ?>
				</p>
			<?php endif; ?>

			<?php if ( '' !== $acps_cta ) : ?>
				<p class="acps-popup-edit__cta" style="color:<?php echo esc_attr( $acps_color ); ?>">
					<?php echo esc_html( $acps_cta ); ?> &rarr;
				</p>
			<?php endif; ?>
		</div>
	</div>

	<?php
	$acps_has_bb = class_exists( 'ACPS_Alerts_Popup_Source' ) && ACPS_Alerts_Popup_Source::available();
	?>
	<p class="acps-popup-edit__note">
		<?php if ( $acps_has_bb ) : ?>
			<strong><?php esc_html_e( 'The popup people see is the Beaver Builder Popup module on this page.', 'acps-alert-popups' ); ?></strong>
			<?php esc_html_e( 'Build it there. This module does not draw the popup, and saving this page never changes the alert on its own. Post and switch the alert on or off from Site Alerts → Post an Alert. The one-time "Post this alert now" switch here is the only thing that writes the alert on save.', 'acps-alert-popups' ); ?>
		<?php else : ?>
			<strong><?php esc_html_e( 'No Beaver Builder Popup module found on this page.', 'acps-alert-popups' ); ?></strong>
			<?php esc_html_e( 'Add one and build the alert in it. Until then the plugin falls back to showing the heading and text below, so an alert still reaches people.', 'acps-alert-popups' ); ?>
		<?php endif; ?>
		<br />
		<?php if ( $acps_popup ) : ?>
			<?php esc_html_e( 'When it is on, this popup shows across the site. It never shows on the status page; the banner below says the same thing instead.', 'acps-alert-popups' ); ?>
		<?php else : ?>
			<?php esc_html_e( 'Popping up across the site is switched off, so this stays on the status page as the banner only.', 'acps-alert-popups' ); ?>
		<?php endif; ?>
		<?php if ( $acps_staged ) : ?>
			<br /><strong><?php esc_html_e( 'It is staged: the public cannot see it yet.', 'acps-alert-popups' ); ?></strong>
		<?php endif; ?>
	</p>
</div>
