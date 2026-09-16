<?php
/**
 * Global plugin settings.
 *
 * @package ACPS_Alert_Popups
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the site-wide alert settings.
 */
class ACPS_Alerts_Settings {

	const OPTION = 'acps_alerts_settings';

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'popup_post_type'  => '',      // Empty means auto-detect the Beaver Builder popup type.
			'render_mode'      => 'auto',  // auto | native | modal.
			'max_concurrent'   => 1,       // How many alerts may show on one page view.
			'z_index'          => 999999,
			'hide_for_admins'  => 0,       // Hide alerts from users who can edit the site.
			'respect_preview'  => 1,       // Allow ?acps_alert_preview=ID for editors.
			'storage'          => 'local', // local | session | cookie.
			'custom_css'       => '',
		);
	}

	/**
	 * All settings, merged over the defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return wp_parse_args( $stored, self::defaults() );
	}

	/**
	 * A single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Value used when the key is unknown.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Saves settings after sanitizing them.
	 *
	 * @param array $input Raw input.
	 * @return array The stored settings.
	 */
	public static function save( array $input ) {
		$clean = self::sanitize( $input );

		update_option( self::OPTION, $clean );

		return $clean;
	}

	/**
	 * Sanitizes a settings array.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function sanitize( array $input ) {
		$defaults = self::defaults();
		$clean    = array();

		$post_type                 = isset( $input['popup_post_type'] ) ? sanitize_key( $input['popup_post_type'] ) : '';
		$clean['popup_post_type']  = post_type_exists( $post_type ) ? $post_type : '';

		$render_mode           = isset( $input['render_mode'] ) ? sanitize_key( $input['render_mode'] ) : 'auto';
		$clean['render_mode']  = in_array( $render_mode, array( 'auto', 'native', 'modal' ), true ) ? $render_mode : 'auto';

		$storage          = isset( $input['storage'] ) ? sanitize_key( $input['storage'] ) : 'local';
		$clean['storage'] = in_array( $storage, array( 'local', 'session', 'cookie' ), true ) ? $storage : 'local';

		$clean['max_concurrent']  = isset( $input['max_concurrent'] ) ? max( 1, min( 5, absint( $input['max_concurrent'] ) ) ) : $defaults['max_concurrent'];
		$clean['z_index']         = isset( $input['z_index'] ) ? max( 1, absint( $input['z_index'] ) ) : $defaults['z_index'];
		$clean['hide_for_admins'] = empty( $input['hide_for_admins'] ) ? 0 : 1;
		$clean['respect_preview'] = empty( $input['respect_preview'] ) ? 0 : 1;
		$clean['custom_css']      = isset( $input['custom_css'] ) ? wp_strip_all_tags( (string) $input['custom_css'] ) : '';

		return $clean;
	}
}
