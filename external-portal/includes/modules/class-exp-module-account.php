<?php
/**
 * Module: My Account.
 *
 * Lets a signed-in portal user set/change their own password (spec Section 3 —
 * this is only reachable after authenticating). Uses the dedicated `set_password`
 * router action rather than the generic module dispatcher.
 *
 * @package ExternalPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Account module.
 */
class EXP_Module_Account {

	const SLUG = 'account';

	/**
	 * Register.
	 *
	 * @param EXP_Registry $r Registry.
	 */
	public static function register( $r ) {
		$r->register_menu_item(
			array(
				'slug'       => self::SLUG,
				'label'      => __( 'My Account', 'external-portal' ),
				'icon'       => 'admin-users',
				'capability' => '', // Always available.
				'render'     => array( __CLASS__, 'render' ),
				'position'   => 90,
				'core'       => true,
			)
		);
	}

	/**
	 * Render the account panel.
	 *
	 * @param array $ctx Context.
	 * @return string
	 */
	public static function render( array $ctx ) {
		$user = $ctx['user'];
		$min  = (int) EXP_Settings::get( 'password_min_length', 12 );
		$has  = ! empty( $user->password_hash );

		$html  = '<dl class="exp-deflist">';
		$html .= '<dt>' . esc_html__( 'Email', 'external-portal' ) . '</dt><dd>' . esc_html( $user->email ) . '</dd>';
		$html .= '<dt>' . esc_html__( 'Name', 'external-portal' ) . '</dt><dd>' . esc_html( $user->display_name ? $user->display_name : '—' ) . '</dd>';
		$html .= '<dt>' . esc_html__( 'Password set', 'external-portal' ) . '</dt><dd>' . ( $has ? esc_html__( 'Yes', 'external-portal' ) : esc_html__( 'No (you sign in with an emailed code)', 'external-portal' ) ) . '</dd>';
		$html .= '</dl>';

		$html .= '<h3 class="exp-subhead">' . ( $has ? esc_html__( 'Change your password', 'external-portal' ) : esc_html__( 'Set a password', 'external-portal' ) ) . '</h3>';
		$html .= '<form class="exp-form" method="post">';
		$html .= '<input type="hidden" name="exp_action" value="set_password" />';
		$html .= '<input type="hidden" name="exp_csrf" value="' . esc_attr( $ctx['csrf'] ) . '" />';
		$html .= EXP_UI::field(
			array(
				'name'         => 'exp_new_password',
				'label'        => __( 'New password', 'external-portal' ),
				'type'         => 'password',
				'required'     => true,
				'autocomplete' => 'new-password',
				'help'         => sprintf(
					/* translators: %d: minimum length */
					__( 'At least %d characters, including letters and numbers.', 'external-portal' ),
					$min
				),
			)
		);
		$html .= EXP_UI::field(
			array(
				'name'         => 'exp_new_password_confirm',
				'label'        => __( 'Confirm new password', 'external-portal' ),
				'type'         => 'password',
				'required'     => true,
				'autocomplete' => 'new-password',
			)
		);
		$html .= '<button type="submit" class="exp-button">' . esc_html__( 'Save password', 'external-portal' ) . '</button>';
		$html .= '</form>';
		return $html;
	}
}
