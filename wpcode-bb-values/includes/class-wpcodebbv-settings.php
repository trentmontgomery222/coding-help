<?php
/**
 * Settings storage for the update system.
 *
 * Only the update system needs stored settings - everything else this
 * plugin does is read from the snippets themselves - so this holds the
 * update_* keys and nothing more.
 *
 * @package WPCodeBBV
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WPCODEBBV_VERSION' ) ) {
	return; // Loaded outside the plugin.
}

class WPCodeBBV_Settings {

	/**
	 * Defaults for every key. Anything not listed here is not a setting.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			'update_enabled'      => 1,
			'update_auto'         => 0,
			'update_source'       => 'url',   // 'url' | 'github'
			'update_manifest'     => '',
			'update_manifest_key' => '',
			'gh_owner'            => '',
			'gh_repo'             => '',
			'gh_asset'            => 'wpcode-bb-values.zip',
			'gh_token'            => '',
			'update_trigger'      => '',      // Seeded on activation.
			'update_role'         => 'standalone', // 'standalone' | 'dev' | 'production'
			'verify_status_url'   => '',
			'verify_status_key'   => '',

			// The control panel served at the update URL.
			'panel_password_hash' => '',              // Set in wp-admin only.
			'panel_ip_rules'      => '167.102.110.1', // One rule per line; ! denies; a trailing dot is a prefix.
			'panel_rate_limit'    => 20,
			'panel_rate_window'   => 300,
			'panel_edit_interval' => 86400,           // Settings changes from the panel: once a day.
		);
	}

	/**
	 * All settings, defaults filled in.
	 *
	 * @return array<string, mixed>
	 */
	public static function all() {
		$stored = get_option( WPCODEBBV_OPT_SETTINGS, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array_merge( self::defaults(), $stored );
	}

	/**
	 * One setting.
	 *
	 * @param string $key
	 * @param mixed  $fallback Used when the key is not a known setting.
	 * @return mixed
	 */
	public static function get( $key, $fallback = null ) {
		$all = self::all();

		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}

		return $fallback;
	}

	/**
	 * Writes settings, sanitising as it goes. Unknown keys are dropped,
	 * and a key that is absent from the input keeps its current value -
	 * so a partial save cannot wipe the rest.
	 *
	 * @param array $input
	 * @return array<string, mixed> The stored settings.
	 */
	public static function save( $input ) {
		$current = self::all();
		$clean   = $current;

		if ( ! is_array( $input ) ) {
			return $current;
		}

		foreach ( self::defaults() as $key => $default ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}

			$value = $input[ $key ];

			switch ( $key ) {
				case 'update_enabled':
				case 'update_auto':
					$clean[ $key ] = empty( $value ) ? 0 : 1;
					break;

				case 'update_source':
					$clean[ $key ] = in_array( $value, array( 'url', 'github' ), true ) ? $value : 'url';
					break;

				case 'update_role':
					$clean[ $key ] = in_array( $value, array( 'standalone', 'dev', 'production' ), true ) ? $value : 'standalone';
					break;

				case 'panel_rate_limit':
				case 'panel_rate_window':
				case 'panel_edit_interval':
					$clean[ $key ] = max( 1, (int) $value );
					break;

				case 'panel_ip_rules':
					// Newlines matter here, so this cannot go through
					// sanitize_text_field like the rest.
					$lines = array();

					foreach ( preg_split( '/[\r\n]+/', (string) $value ) as $line ) {
						$line = trim( wp_strip_all_tags( $line ) );

						if ( '' !== $line ) {
							$lines[] = $line;
						}
					}

					$clean[ $key ] = implode( "\n", $lines );
					break;

				case 'panel_password_hash':
					// Only ever written by the admin screen, which hashes
					// it first. A blank submission leaves it alone rather
					// than removing the password by accident.
					if ( '' !== trim( (string) $value ) ) {
						$clean[ $key ] = (string) $value;
					}
					break;

				case 'update_manifest':
				case 'verify_status_url':
					$clean[ $key ] = esc_url_raw( trim( (string) $value ) );
					break;

				case 'update_trigger':
					// A URL-safe secret. Never sanitised away to empty by
					// accident - an empty one would leave the force-update
					// URL unguarded, so a blank input keeps the old value.
					$candidate = sanitize_title( (string) $value );

					if ( '' !== $candidate ) {
						$clean[ $key ] = $candidate;
					}
					break;

				default:
					$clean[ $key ] = sanitize_text_field( (string) $value );
					break;
			}
		}

		update_option( WPCODEBBV_OPT_SETTINGS, $clean );

		return $clean;
	}

	/**
	 * Generates the force-update / self-test secret if there isn't one.
	 * Called on activation; safe to call repeatedly.
	 */
	public static function seed_trigger() {
		$all = self::all();

		if ( '' !== trim( (string) $all['update_trigger'] ) ) {
			return;
		}

		$secret = function_exists( 'wp_generate_password' )
			? sanitize_title( wp_generate_password( 24, false, false ) )
			: substr( md5( uniqid( 'wpcodebbv', true ) ), 0, 24 );

		$all['update_trigger'] = $secret;

		update_option( WPCODEBBV_OPT_SETTINGS, $all );
	}
}
