<?php
/**
 * Self-update system + hidden remote console.
 *
 * This file is loaded EARLY (before the fatal-error safe-mode early return in
 * the main plugin file) so the updater keeps working even when the rest of the
 * plugin is paused after a crash — that is the recovery path, so an update must
 * never be able to disable its own updater.
 *
 * Two faces, both hidden from every menu:
 *
 *   1. Admin panel  — wp-admin settings page URL + "&updates=1"
 *        options-general.php?page=CAYDENDIR-staff-directory&updates=1
 *      Logged-in administrators only. Full control: update source, background
 *      auto-update, the shared key, the remote-console password, the IP
 *      allow/block rules, check / install, and diagnostics.
 *
 *   2. Remote console — the shared secret URL, no login required
 *        /?wp_update=<plugin-slug>/<key>
 *      Gated by IP rules (default: only 167.102.110.1) AND rate-limited AND
 *      password-protected. Shows diagnostics (performance / issues / problems),
 *      can force an update, and can edit ALL plugin settings — but only once per
 *      day. The password is set only from the admin panel above.
 *
 * The outbound "Update Request URL" the site asks for a manifest is always:
 *   <Manifest URL> + ?plugin=<slug>&key=<set key>
 *
 * Every entry point is wrapped in try/catch(\Throwable); nothing here can
 * white-screen the site, and it is inert until its exact secret URL is hit.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'CAYDENDIR_SD_UPDATER_KEY_OPTION' ) ) {
	define( 'CAYDENDIR_SD_UPDATER_KEY_OPTION', 'wp_updaterKey' );
}
if ( ! defined( 'CAYDENDIR_SD_UPDATER_GATE' ) ) {
	// Security/state for the remote console. A DEDICATED option so a normal
	// settings save (or reset) can never touch the updater's access rules.
	define( 'CAYDENDIR_SD_UPDATER_GATE', 'CAYDENDIR_sd_updater_gate' );
}

/* =========================================================================
 * Shared cross-plugin key (unlocks the remote console URL)
 * ====================================================================== */

/**
 * The shared, cross-plugin updater key. All "Caydens Plugins" read/write the
 * SAME option (wp_updaterKey), generated once and reused. Which plugin the URL
 * acts on is chosen by the slug in front of the key: /?wp_update=<slug>/<key>.
 */
function CAYDENDIR_sd_updater_key() {
	$opt = CAYDENDIR_SD_UPDATER_KEY_OPTION;
	$key = get_option( $opt, '' );
	if ( is_string( $key ) && strlen( $key ) >= 20 ) {
		return $key;
	}
	$key = CAYDENDIR_sd_updater_random( 32 );
	update_option( $opt, $key, true ); // autoloaded: tiny, read on the console URL
	return $key;
}

/** Rotate the shared key (changes the console URL for every Cayden plugin). */
function CAYDENDIR_sd_rotate_updater_key() {
	$key = CAYDENDIR_sd_updater_random( 32 );
	update_option( CAYDENDIR_SD_UPDATER_KEY_OPTION, $key, true );
	return $key;
}

/** A strong alphanumeric random string (falls back if wp_generate_password is absent). */
function CAYDENDIR_sd_updater_random( $len = 32 ) {
	if ( function_exists( 'wp_generate_password' ) ) {
		return wp_generate_password( $len, false, false );
	}
	$pool = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
	return substr( str_shuffle( str_repeat( $pool, (int) ceil( $len / strlen( $pool ) ) + 1 ) ), 0, $len );
}

/* =========================================================================
 * Remote-console gate: IP rules, password, once-a-day state
 * ====================================================================== */

/** Default gate config. Enforced allowlist with the one requested IP. */
function CAYDENDIR_sd_updater_gate_defaults() {
	return array(
		'pw_hash'            => '',                      // console password (set in wp-admin only)
		'ip_enforce'         => '1',                     // '1' allowlist mode, '0' blocklist mode
		'ip_allow'           => array( '167.102.110.1' ), // exact IP, prefix ("196.168") or CIDR
		'ip_block'           => array(),                 // always wins over the allow list
		'trust_proxy'        => '0',                      // '1' trust X-Forwarded-For (behind a proxy/CDN)
		'settings_edit_day'  => '',                       // 'Ymd' of the last remote settings edit
	);
}

/** Merged gate config (defaults under saved). Never touched by the settings sanitizer. */
function CAYDENDIR_sd_updater_gate() {
	$saved = get_option( CAYDENDIR_SD_UPDATER_GATE, array() );
	$saved = is_array( $saved ) ? $saved : array();
	$gate  = array_merge( CAYDENDIR_sd_updater_gate_defaults(), $saved );
	foreach ( array( 'ip_allow', 'ip_block' ) as $k ) {
		if ( ! is_array( $gate[ $k ] ) ) {
			$gate[ $k ] = array();
		}
	}
	return $gate;
}

/** Persist the gate config (merging over what is stored). */
function CAYDENDIR_sd_updater_gate_save( $changes ) {
	$gate = CAYDENDIR_sd_updater_gate();
	if ( is_array( $changes ) ) {
		$gate = array_merge( $gate, $changes );
	}
	update_option( CAYDENDIR_SD_UPDATER_GATE, $gate, true );
	return $gate;
}

/** The visitor's IP. REMOTE_ADDR by default; first X-Forwarded-For hop only if trusted. */
function CAYDENDIR_sd_client_ip( $gate = null ) {
	$gate = is_array( $gate ) ? $gate : CAYDENDIR_sd_updater_gate();
	if ( ! empty( $gate['trust_proxy'] ) && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
		$xff  = (string) wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] );
		$part = trim( (string) strtok( $xff, ',' ) );
		if ( filter_var( $part, FILTER_VALIDATE_IP ) ) {
			return $part;
		}
	}
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
}

/**
 * Does an IP match a rule? A rule is an exact IP, an octet-aligned IPv4 prefix
 * ("196.168" matches 196.168.*.* but not 196.1689.*), or CIDR ("10.0.0.0/8").
 */
function CAYDENDIR_sd_ip_match( $ip, $pattern ) {
	$ip      = trim( (string) $ip );
	$pattern = trim( (string) $pattern );
	if ( '' === $ip || '' === $pattern ) {
		return false;
	}
	if ( $ip === $pattern ) {
		return true;
	}
	if ( false !== strpos( $pattern, '/' ) ) {
		return CAYDENDIR_sd_ip_in_cidr( $ip, $pattern );
	}
	// Octet-aligned IPv4 prefix match.
	if ( false === strpos( $ip, '.' ) || false === strpos( $pattern, '.' ) ) {
		return false; // not a dotted-quad prefix (IPv6 handled by exact/CIDR only)
	}
	$pp = explode( '.', rtrim( $pattern, '.' ) );
	$ii = explode( '.', $ip );
	if ( count( $pp ) > count( $ii ) || count( $pp ) === 4 ) {
		return false; // a full 4-octet pattern would have matched exactly above
	}
	foreach ( $pp as $k => $oct ) {
		if ( '' === $oct ) {
			continue;
		}
		if ( ! isset( $ii[ $k ] ) || $ii[ $k ] !== $oct ) {
			return false;
		}
	}
	return true;
}

/** IPv4 CIDR match (e.g. 192.168.0.0/16). Non-IPv4 or bad CIDR → false. */
function CAYDENDIR_sd_ip_in_cidr( $ip, $cidr ) {
	$parts = explode( '/', $cidr, 2 );
	if ( 2 !== count( $parts ) ) {
		return false;
	}
	$subnet = $parts[0];
	$bits   = (int) $parts[1];
	if ( $bits < 0 || $bits > 32 ) {
		return false;
	}
	$ip_l  = ip2long( $ip );
	$sub_l = ip2long( $subnet );
	if ( false === $ip_l || false === $sub_l ) {
		return false;
	}
	if ( 0 === $bits ) {
		return true;
	}
	$mask = -1 << ( 32 - $bits );
	return ( $ip_l & $mask ) === ( $sub_l & $mask );
}

/** Is this IP allowed to reach the remote console? Block list always wins. */
function CAYDENDIR_sd_ip_allowed( $ip, $gate ) {
	if ( '' === $ip ) {
		return false; // unknown IP → deny (fail closed)
	}
	$block = isset( $gate['ip_block'] ) && is_array( $gate['ip_block'] ) ? $gate['ip_block'] : array();
	foreach ( $block as $p ) {
		if ( CAYDENDIR_sd_ip_match( $ip, $p ) ) {
			return false;
		}
	}
	if ( empty( $gate['ip_enforce'] ) ) {
		return true; // blocklist mode: anything not blocked is allowed
	}
	$allow = isset( $gate['ip_allow'] ) && is_array( $gate['ip_allow'] ) ? $gate['ip_allow'] : array();
	foreach ( $allow as $p ) {
		if ( CAYDENDIR_sd_ip_match( $ip, $p ) ) {
			return true;
		}
	}
	return false;
}

/** Fixed-window per-bucket rate limit. Returns true when the hit is allowed. */
function CAYDENDIR_sd_updater_rate_ok( $bucket, $limit, $window ) {
	if ( ! function_exists( 'get_transient' ) || ! function_exists( 'set_transient' ) ) {
		return true;
	}
	$key = 'CAYDENDIR_rl_' . md5( (string) $bucket );
	$n   = (int) get_transient( $key );
	if ( $n >= $limit ) {
		return false;
	}
	set_transient( $key, $n + 1, $window );
	return true;
}

/** Parse a textarea (one IP rule per line) into a clean list. */
function CAYDENDIR_sd_updater_parse_ip_list( $text ) {
	$out   = array();
	$lines = preg_split( '/[\r\n,]+/', (string) $text );
	foreach ( (array) $lines as $line ) {
		$line = trim( $line );
		if ( '' !== $line ) {
			$out[] = $line;
		}
	}
	return array_values( array_unique( $out ) );
}

/* =========================================================================
 * Diagnostics (performance / issues / problems)
 * ====================================================================== */

function CAYDENDIR_sd_updater_diagnostics() {
	$metrics  = array();
	$problems = array();
	try {
		$start = defined( 'WP_START_TIMESTAMP' ) ? WP_START_TIMESTAMP
			: ( isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true ) );

		$metrics['Plugin version']   = defined( 'CAYDENDIR_SD_VERSION' ) ? CAYDENDIR_SD_VERSION : '?';
		$metrics['WordPress']        = function_exists( 'get_bloginfo' ) ? get_bloginfo( 'version' ) : '?';
		$metrics['PHP']              = PHP_VERSION;
		$metrics['Peak memory']      = CAYDENDIR_sd_size_format( memory_get_peak_usage( true ) );
		$metrics['Memory limit']     = (string) ini_get( 'memory_limit' );
		$metrics['Request time']     = number_format( ( microtime( true ) - $start ) * 1000, 1 ) . ' ms';
		$metrics['Server time']      = gmdate( 'Y-m-d H:i:s' ) . ' UTC';

		// Safe-mode / crash state.
		$paused = function_exists( 'CAYDENDIR_sd_is_paused' ) ? CAYDENDIR_sd_is_paused() : false;
		$metrics['Safe mode']        = $paused ? 'PAUSED (crash recovery active)' : 'normal';
		if ( $paused ) {
			$problems[] = 'The plugin is in safe mode after a fatal error. Its main features are paused; only this updater is running. Resume it from wp-admin or push a fixed update.';
			$info = defined( 'CAYDENDIR_SD_SAFE_OPTION' ) ? get_option( CAYDENDIR_SD_SAFE_OPTION, array() ) : array();
			if ( is_array( $info ) && ! empty( $info['message'] ) ) {
				$metrics['Last fatal'] = substr( (string) $info['message'], 0, 300 );
			}
		}

		// Missing companion files.
		if ( function_exists( 'CAYDENDIR_sd_missing_files' ) ) {
			$missing = CAYDENDIR_sd_missing_files();
			$metrics['Missing files'] = empty( $missing ) ? 'none' : implode( ', ', $missing );
			if ( ! empty( $missing ) ) {
				$problems[] = 'Missing plugin files: ' . implode( ', ', $missing ) . '. Re-upload the complete ZIP.';
			}
		}

		// Sync status.
		if ( defined( 'CAYDENDIR_SD_META_OPTION' ) ) {
			$meta = get_option( CAYDENDIR_SD_META_OPTION, array() );
			if ( is_array( $meta ) ) {
				if ( ! empty( $meta['last_sync'] ) ) {
					$metrics['Last sync'] = is_numeric( $meta['last_sync'] ) ? gmdate( 'Y-m-d H:i:s', (int) $meta['last_sync'] ) . ' UTC' : (string) $meta['last_sync'];
				}
				if ( isset( $meta['count'] ) ) {
					$metrics['Records'] = (string) (int) $meta['count'];
				}
				if ( ! empty( $meta['last_error'] ) ) {
					$problems[] = 'Last sync error: ' . (string) $meta['last_error'];
				}
			}
		}

		// Data sizes.
		if ( defined( 'CAYDENDIR_SD_DATA_OPTION' ) ) {
			$d = get_option( CAYDENDIR_SD_DATA_OPTION, array() );
			$metrics['Synced rows'] = is_array( $d ) ? (string) count( $d ) : '0';
		}
		if ( defined( 'CAYDENDIR_SD_MANUAL_OPTION' ) ) {
			$m = get_option( CAYDENDIR_SD_MANUAL_OPTION, array() );
			$metrics['Manual overrides'] = is_array( $m ) ? (string) count( $m ) : '0';
		}

		// Scheduled sync.
		if ( function_exists( 'wp_next_scheduled' ) && defined( 'CAYDENDIR_SD_CRON_HOOK' ) ) {
			$next = wp_next_scheduled( CAYDENDIR_SD_CRON_HOOK );
			$metrics['Next sync'] = $next ? gmdate( 'Y-m-d H:i:s', (int) $next ) . ' UTC' : 'not scheduled';
			if ( ! $next ) {
				$problems[] = 'The daily sync is not scheduled. Deactivate/reactivate the plugin to restore it.';
			}
		}
	} catch ( \Throwable $e ) {
		$problems[] = 'Diagnostics error: ' . $e->getMessage();
	}
	return array( 'metrics' => $metrics, 'problems' => $problems );
}

/** size_format() but guarded (it may be unavailable very early). */
function CAYDENDIR_sd_size_format( $bytes ) {
	if ( function_exists( 'size_format' ) ) {
		$s = size_format( $bytes );
		if ( $s ) {
			return $s;
		}
	}
	return number_format( (int) $bytes / 1048576, 1 ) . ' MB';
}

/* =========================================================================
 * Updater
 * ====================================================================== */

if ( ! class_exists( 'CAYDENDIR_SD_Updater' ) ) {

	class CAYDENDIR_SD_Updater {

		const CACHE_TTL      = 21600; // 6h on success
		const CACHE_TTL_FAIL = 900;   // 15m on failure

		/** @var string plugin basename, e.g. cayden-staff-directory/cayden-staff-directory.php */
		protected $basename;
		/** @var string plugin folder slug, e.g. cayden-staff-directory */
		protected $slug;

		public function __construct() {
			$this->basename = defined( 'CAYDENDIR_SD_BASENAME' ) ? CAYDENDIR_SD_BASENAME : plugin_basename( CAYDENDIR_SD_DIR . 'cayden-staff-directory.php' );
			$dir            = dirname( $this->basename );
			$this->slug     = ( '.' === $dir || '' === $dir ) ? preg_replace( '/\.php$/', '', basename( $this->basename ) ) : $dir;
		}

		/** Register hooks. The console + admin panel ALWAYS run (recovery path). */
		public function register() {
			try {
				// Recovery paths — always available, even in safe mode / disabled.
				add_action( 'init', array( $this, 'maybe_handle_control_url' ) );
				add_action( 'admin_init', array( $this, 'maybe_handle_admin_panel' ) );

				// Plugins-screen / auto-update integration — only when enabled.
				$s = $this->settings();
				if ( empty( $s['update_enabled'] ) ) {
					return;
				}
				add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
				add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
				add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
				add_filter( 'upgrader_pre_download', array( $this, 'maybe_download_private_asset' ), 10, 3 );
				add_filter( 'auto_update_plugin', array( $this, 'auto_update' ), 10, 2 );
				add_action( 'upgrader_process_complete', array( $this, 'flush_after_upgrade' ), 10, 2 );
			} catch ( \Throwable $e ) {
				CAYDENDIR_sd_log( 'updater register', $e );
			}
		}

		/** Source config. Defensive so it also works in safe mode. */
		protected function settings() {
			if ( function_exists( 'CAYDENDIR_sd_get_settings' ) ) {
				return CAYDENDIR_sd_get_settings();
			}
			$s = get_option( CAYDENDIR_SD_SETTINGS, array() );
			$s = is_array( $s ) ? $s : array();
			return array_merge( $this->settings_defaults(), $s );
		}

		protected function settings_defaults() {
			return array(
				'update_enabled'      => '1',
				'update_auto'         => '0',
				'update_source'       => 'url',
				'update_manifest'     => '',
				'update_manifest_key' => '',
				'gh_owner'            => '',
				'gh_repo'             => '',
				'gh_asset'            => 'cayden-staff-directory.zip',
				'gh_token'            => '',
			);
		}

		public function control_url( $key = null ) {
			$key = ( null === $key ) ? CAYDENDIR_sd_updater_key() : $key;
			return home_url( '/?wp_update=' . rawurlencode( $this->slug ) . '/' . rawurlencode( $key ) );
		}

		/** The outbound "Update Request URL": manifest + plugin + key. */
		public function update_request_url( $s = null ) {
			$s   = is_array( $s ) ? $s : $this->settings();
			$url = isset( $s['update_manifest'] ) ? trim( (string) $s['update_manifest'] ) : '';
			if ( '' === $url ) {
				return '';
			}
			$url = add_query_arg( 'plugin', rawurlencode( $this->slug ), $url );
			if ( ! empty( $s['update_manifest_key'] ) ) {
				$url = add_query_arg( 'key', rawurlencode( $s['update_manifest_key'] ), $url );
			}
			return $url;
		}

		/* ---- normalized remote lookup (cached) ---- */

		public function remote( $force = false ) {
			try {
				if ( ! $force ) {
					$cached = get_transient( CAYDENDIR_SD_UPDATE_CACHE );
					if ( is_array( $cached ) ) {
						return $cached;
					}
					if ( 'none' === $cached ) {
						return false;
					}
				}
				$s      = $this->settings();
				$source = ( isset( $s['update_source'] ) && 'github' === $s['update_source'] ) ? 'github' : 'url';
				$remote = ( 'github' === $source ) ? $this->remote_github( $s ) : $this->remote_manifest( $s );

				if ( ! is_array( $remote ) || empty( $remote['version'] ) || empty( $remote['package'] ) ) {
					set_transient( CAYDENDIR_SD_UPDATE_CACHE, 'none', self::CACHE_TTL_FAIL );
					return false;
				}
				set_transient( CAYDENDIR_SD_UPDATE_CACHE, $remote, self::CACHE_TTL );
				return $remote;
			} catch ( \Throwable $e ) {
				CAYDENDIR_sd_log( 'updater remote', $e );
				set_transient( CAYDENDIR_SD_UPDATE_CACHE, 'none', self::CACHE_TTL_FAIL );
				return false;
			}
		}

		protected function remote_manifest( $s ) {
			$url = $this->update_request_url( $s ); // manifest + plugin + key
			if ( '' === $url ) {
				return false;
			}
			$res = wp_remote_get( $url, array( 'timeout' => 15, 'headers' => array( 'Accept' => 'application/json' ) ) );
			if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
				return false;
			}
			$json = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( ! is_array( $json ) || empty( $json['version'] ) || empty( $json['download_url'] ) ) {
				return false;
			}
			return array(
				'version'      => ltrim( (string) $json['version'], 'vV' ),
				'package'      => esc_url_raw( (string) $json['download_url'] ),
				'is_asset'     => false,
				'html_url'     => isset( $json['homepage'] ) ? esc_url_raw( (string) $json['homepage'] ) : '',
				'body'         => isset( $json['changelog'] ) ? (string) $json['changelog'] : '',
				'requires_php' => isset( $json['requires_php'] ) ? (string) $json['requires_php'] : '',
			);
		}

		protected function remote_github( $s ) {
			$owner = isset( $s['gh_owner'] ) ? trim( (string) $s['gh_owner'] ) : '';
			$repo  = isset( $s['gh_repo'] ) ? trim( (string) $s['gh_repo'] ) : '';
			if ( '' === $owner || '' === $repo ) {
				return false;
			}
			$token = isset( $s['gh_token'] ) ? trim( (string) $s['gh_token'] ) : '';
			$asset = ( isset( $s['gh_asset'] ) && '' !== trim( (string) $s['gh_asset'] ) ) ? trim( (string) $s['gh_asset'] ) : 'cayden-staff-directory.zip';

			$headers = array(
				'Accept'               => 'application/vnd.github+json',
				'X-GitHub-Api-Version' => '2022-11-28',
				'User-Agent'           => 'CaydenStaffDirectory-Updater',
			);
			if ( '' !== $token ) {
				$headers['Authorization'] = 'Bearer ' . $token;
			}
			$res = wp_remote_get(
				'https://api.github.com/repos/' . rawurlencode( $owner ) . '/' . rawurlencode( $repo ) . '/releases/latest',
				array( 'timeout' => 15, 'headers' => $headers )
			);
			if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
				return false;
			}
			$json = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( ! is_array( $json ) || empty( $json['tag_name'] ) ) {
				return false;
			}
			$package  = '';
			$is_asset = false;
			if ( ! empty( $json['assets'] ) && is_array( $json['assets'] ) ) {
				foreach ( $json['assets'] as $a ) {
					if ( isset( $a['name'] ) && $a['name'] === $asset ) {
						if ( '' !== $token && ! empty( $a['url'] ) ) {
							$package  = (string) $a['url'];
							$is_asset = true;
						} elseif ( ! empty( $a['browser_download_url'] ) ) {
							$package = (string) $a['browser_download_url'];
						}
						break;
					}
				}
			}
			if ( '' === $package ) {
				return false;
			}
			return array(
				'version'      => ltrim( (string) $json['tag_name'], 'vV' ),
				'package'      => $package,
				'is_asset'     => $is_asset,
				'html_url'     => isset( $json['html_url'] ) ? esc_url_raw( (string) $json['html_url'] ) : '',
				'body'         => isset( $json['body'] ) ? (string) $json['body'] : '',
				'requires_php' => '',
			);
		}

		/* ---- tell WordPress an update exists ---- */

		public function inject_update( $transient ) {
			try {
				if ( ! is_object( $transient ) ) {
					return $transient;
				}
				$remote = $this->remote();
				if ( ! is_array( $remote ) ) {
					if ( isset( $transient->response[ $this->basename ] ) ) {
						unset( $transient->response[ $this->basename ] );
					}
					return $transient;
				}
				if ( version_compare( $remote['version'], CAYDENDIR_SD_VERSION, '>' ) ) {
					$obj = (object) array(
						'id'           => $this->basename,
						'slug'         => $this->slug,
						'plugin'       => $this->basename,
						'new_version'  => $remote['version'],
						'package'      => $remote['package'],
						'url'          => $remote['html_url'],
						'icons'        => array(),
						'banners'      => array(),
						'tested'       => '',
						'requires_php' => $remote['requires_php'],
					);
					if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
						$transient->response = array();
					}
					$transient->response[ $this->basename ] = $obj;
					if ( isset( $transient->no_update[ $this->basename ] ) ) {
						unset( $transient->no_update[ $this->basename ] );
					}
				} else {
					if ( isset( $transient->response[ $this->basename ] ) ) {
						unset( $transient->response[ $this->basename ] );
					}
					if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
						$transient->no_update = array();
					}
					$transient->no_update[ $this->basename ] = (object) array(
						'id'          => $this->basename,
						'slug'        => $this->slug,
						'plugin'      => $this->basename,
						'new_version' => CAYDENDIR_SD_VERSION,
						'package'     => '',
						'url'         => '',
						'icons'       => array(),
						'banners'     => array(),
					);
				}
				return $transient;
			} catch ( \Throwable $e ) {
				CAYDENDIR_sd_log( 'updater inject', $e );
				return $transient;
			}
		}

		/* ---- "View details" popup ---- */

		public function plugin_info( $result, $action, $args ) {
			try {
				if ( 'plugin_information' !== $action ) {
					return $result;
				}
				if ( empty( $args->slug ) || $args->slug !== $this->slug ) {
					return $result;
				}
				$remote = $this->remote();
				if ( ! is_array( $remote ) ) {
					return $result;
				}
				return (object) array(
					'name'          => 'Cayden Staff Directory',
					'slug'          => $this->slug,
					'version'       => $remote['version'],
					'author'        => 'Cayden Riddle',
					'homepage'      => $remote['html_url'],
					'requires_php'  => $remote['requires_php'],
					'download_link' => $remote['package'],
					'trunk'         => $remote['package'],
					'sections'      => array(
						'changelog' => '' !== $remote['body'] ? wp_kses_post( wpautop( $remote['body'] ) ) : 'No changelog provided.',
					),
				);
			} catch ( \Throwable $e ) {
				CAYDENDIR_sd_log( 'updater info', $e );
				return $result;
			}
		}

		/* ---- keep the plugin active: rename the unpacked folder to our slug ---- */

		public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = array() ) {
			try {
				if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->basename ) {
					return $source;
				}
				if ( basename( untrailingslashit( $source ) ) === $this->slug ) {
					return $source;
				}
				global $wp_filesystem;
				if ( ! $wp_filesystem ) {
					return $source;
				}
				$desired = trailingslashit( $remote_source ) . $this->slug;
				if ( $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $desired ), true ) ) {
					return trailingslashit( $desired );
				}
				return $source;
			} catch ( \Throwable $e ) {
				CAYDENDIR_sd_log( 'updater fix_source', $e );
				return $source;
			}
		}

		/* ---- private GitHub asset: resolve the signed redirect ourselves ---- */

		public function maybe_download_private_asset( $reply, $package, $upgrader ) {
			try {
				$s      = $this->settings();
				$token  = isset( $s['gh_token'] ) ? trim( (string) $s['gh_token'] ) : '';
				$remote = $this->remote();
				if ( ! is_array( $remote ) || empty( $remote['is_asset'] ) || '' === $token || $package !== $remote['package'] ) {
					return $reply;
				}
				$res = wp_remote_get(
					$package,
					array(
						'timeout'     => 30,
						'redirection' => 0,
						'headers'     => array(
							'Accept'        => 'application/octet-stream',
							'Authorization' => 'Bearer ' . $token,
							'User-Agent'    => 'CaydenStaffDirectory-Updater',
						),
					)
				);
				if ( is_wp_error( $res ) ) {
					return $res;
				}
				$location = wp_remote_retrieve_header( $res, 'location' );
				if ( ! function_exists( 'download_url' ) ) {
					require_once ABSPATH . 'wp-admin/includes/file.php';
				}
				if ( $location ) {
					$tmp = download_url( $location );
					return $tmp;
				}
				if ( 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
					$body = wp_remote_retrieve_body( $res );
					if ( '' !== $body ) {
						$tmp = wp_tempnam( $this->slug . '.zip' );
						if ( $tmp && false !== file_put_contents( $tmp, $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
							return $tmp;
						}
					}
				}
				return $reply;
			} catch ( \Throwable $e ) {
				CAYDENDIR_sd_log( 'updater private download', $e );
				return $reply;
			}
		}

		/* ---- background auto-update for THIS plugin only ---- */

		public function auto_update( $update, $item ) {
			try {
				$plugin = '';
				if ( is_object( $item ) && isset( $item->plugin ) ) {
					$plugin = $item->plugin;
				} elseif ( is_array( $item ) && isset( $item['plugin'] ) ) {
					$plugin = $item['plugin'];
				}
				if ( $plugin !== $this->basename ) {
					return $update;
				}
				$s = $this->settings();
				return ! empty( $s['update_auto'] );
			} catch ( \Throwable $e ) {
				CAYDENDIR_sd_log( 'updater auto', $e );
				return $update;
			}
		}

		public function flush_after_upgrade( $upgrader = null, $hook_extra = array() ) {
			try {
				delete_transient( CAYDENDIR_SD_UPDATE_CACHE );
			} catch ( \Throwable $e ) {
				CAYDENDIR_sd_log( 'updater flush', $e );
			}
		}

		/* ---- shared install routine ---- */

		protected function perform_install() {
			foreach ( array( 'plugin', 'file', 'misc', 'class-wp-upgrader' ) as $f ) {
				$p = ABSPATH . 'wp-admin/includes/' . $f . '.php';
				if ( is_readable( $p ) ) {
					require_once $p;
				}
			}
			$remote = $this->remote( true );
			if ( ! is_array( $remote ) ) {
				return array( 'status' => 'nolatest', 'installed' => CAYDENDIR_SD_VERSION, 'latest' => '', 'messages' => 'Lookup failed — check the update source.' );
			}
			if ( ! version_compare( $remote['version'], CAYDENDIR_SD_VERSION, '>' ) ) {
				return array( 'status' => 'uptodate', 'installed' => CAYDENDIR_SD_VERSION, 'latest' => $remote['version'], 'messages' => '' );
			}
			if ( ! class_exists( 'Plugin_Upgrader' ) || ! class_exists( 'Automatic_Upgrader_Skin' ) ) {
				return array( 'status' => 'failed', 'installed' => CAYDENDIR_SD_VERSION, 'latest' => $remote['version'], 'messages' => 'Upgrader unavailable.' );
			}
			delete_site_transient( 'update_plugins' );
			if ( function_exists( 'wp_update_plugins' ) ) {
				wp_update_plugins();
			}
			$skin     = new Automatic_Upgrader_Skin();
			$upgrader = new Plugin_Upgrader( $skin );
			$result   = $upgrader->upgrade( $this->basename );
			$messages = method_exists( $skin, 'get_upgrade_messages' ) ? $skin->get_upgrade_messages() : array();
			$msg      = is_array( $messages ) ? implode( "\n", array_map( 'wp_strip_all_tags', $messages ) ) : '';
			if ( is_wp_error( $result ) ) {
				return array( 'status' => 'failed', 'installed' => CAYDENDIR_SD_VERSION, 'latest' => $remote['version'], 'messages' => trim( $msg . "\n" . $result->get_error_message() ) );
			}
			if ( false === $result || null === $result ) {
				return array( 'status' => 'failed', 'installed' => CAYDENDIR_SD_VERSION, 'latest' => $remote['version'], 'messages' => $msg );
			}
			return array( 'status' => 'success', 'installed' => CAYDENDIR_SD_VERSION, 'latest' => $remote['version'], 'messages' => $msg );
		}

		/* =====================================================================
		 * Remote console (no login) — IP + rate-limit + password gated
		 * ================================================================== */

		public function maybe_handle_control_url() {
			try {
				$key = $this->url_key();
				if ( '' === $key ) {
					return; // not our control URL
				}
				$shared = CAYDENDIR_sd_updater_key();
				if ( '' === $shared || ! hash_equals( $shared, $key ) ) {
					return; // wrong or missing key — behave as if the URL does not exist
				}

				// IP gate — if not allowed, vanish (let WordPress render normally).
				$gate = CAYDENDIR_sd_updater_gate();
				$ip   = CAYDENDIR_sd_client_ip( $gate );
				if ( ! CAYDENDIR_sd_ip_allowed( $ip, $gate ) ) {
					return;
				}

				// Endpoint rate limit (anti-spam): 30 requests / 60s per IP.
				if ( ! CAYDENDIR_sd_updater_rate_ok( 'sd_page:' . $ip, 30, 60 ) ) {
					$this->console_deny( 429, 'Too many requests. Slow down and try again in a minute.' );
				}

				$this->render_console( $ip, $gate );
			} catch ( \Throwable $e ) {
				CAYDENDIR_sd_log( 'updater control url', $e );
			}
		}

		/** Extract the key from ?wp_update=<slug>/<key> or a trailing path, or '' if not ours. */
		protected function url_key() {
			$slug = '';
			$key  = '';
			$raw  = ( isset( $_GET['wp_update'] ) && is_string( $_GET['wp_update'] ) ) ? (string) wp_unslash( $_GET['wp_update'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			$raw  = trim( $raw, " \t\n\r\0\x0B/" );
			if ( '' !== $raw && false !== strpos( $raw, '/' ) ) {
				$pos  = strrpos( $raw, '/' );
				$slug = substr( $raw, 0, $pos );
				$key  = substr( $raw, $pos + 1 );
			}
			if ( '' === $key ) {
				$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : '';
				$segs = array_values( array_filter( explode( '/', (string) $path ), 'strlen' ) );
				$n    = count( $segs );
				if ( $n >= 2 ) {
					$slug = $segs[ $n - 2 ];
					$key  = $segs[ $n - 1 ];
				}
			}
			return ( '' !== $key && $slug === $this->slug ) ? $key : '';
		}

		protected function console_deny( $code, $message ) {
			if ( function_exists( 'status_header' ) ) {
				status_header( (int) $code );
			}
			nocache_headers();
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo esc_html( $message ) . "\n";
			exit;
		}

		/** Is the console password satisfied? True when none is set OR the given one matches. */
		protected function password_ok( $gate, $supplied ) {
			$hash = isset( $gate['pw_hash'] ) ? (string) $gate['pw_hash'] : '';
			if ( '' === $hash ) {
				return true; // no password configured yet
			}
			if ( '' === (string) $supplied ) {
				return false;
			}
			if ( function_exists( 'wp_check_password' ) ) {
				return wp_check_password( (string) $supplied, $hash );
			}
			return hash_equals( $hash, (string) $supplied );
		}

		protected function render_console( $ip, $gate ) {
			nocache_headers();
			header( 'Content-Type: text/html; charset=utf-8' );

			$has_pw   = ( '' !== (string) $gate['pw_hash'] );
			$is_post  = ( 'POST' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : '' ) );
			$supplied = '';
			if ( $is_post && isset( $_POST['cayden_pw'] ) ) {
				$supplied = (string) wp_unslash( $_POST['cayden_pw'] ); // phpcs:ignore WordPress.Security
			} elseif ( isset( $_GET['pw'] ) ) {
				$supplied = (string) wp_unslash( $_GET['pw'] ); // phpcs:ignore WordPress.Security
			}
			$authed = $this->password_ok( $gate, $supplied );

			$notice   = '';
			$install  = null;
			$is_error = false;

			if ( $is_post && $authed ) {
				$action = isset( $_POST['cayden_action'] ) ? sanitize_key( wp_unslash( $_POST['cayden_action'] ) ) : ''; // phpcs:ignore WordPress.Security
				if ( 'install' === $action ) {
					if ( ! CAYDENDIR_sd_updater_rate_ok( 'sd_install:' . $ip, 5, 300 ) ) {
						$notice   = 'Install is rate-limited — wait a few minutes before trying again.';
						$is_error = true;
					} else {
						$install = $this->perform_install();
						$notice  = 'Install run — see the result below.';
					}
				} elseif ( 'savesettings' === $action ) {
					$res      = $this->save_settings_from_console();
					$notice   = $res['message'];
					$is_error = ! $res['ok'];
				}
			} elseif ( $is_post && ! $authed ) {
				$notice   = 'Wrong password.';
				$is_error = true;
			}

			$diag     = CAYDENDIR_sd_updater_diagnostics();
			$s        = $this->settings();
			$req_url  = $this->update_request_url( $s );
			$self     = $this->control_url();
			$today    = gmdate( 'Ymd' );
			$edited   = ( isset( $gate['settings_edit_day'] ) && $gate['settings_edit_day'] === $today );
			$settings_json = '';
			if ( function_exists( 'CAYDENDIR_sd_get_settings' ) ) {
				$settings_json = wp_json_encode( CAYDENDIR_sd_get_settings(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			}

			$e = 'esc_attr';
			?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Console</title>
<style>
 body{font:14px/1.5 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;max-width:820px;margin:2rem auto;padding:0 1rem;color:#1d2327;background:#fff;}
 h1{font-size:1.3rem;} h2{font-size:1.02rem;margin-top:1.6rem;border-top:1px solid #e2e4e7;padding-top:1rem;}
 .box{border:1px solid #c3c4c7;border-radius:6px;padding:10px 14px;margin:12px 0;background:#fff;}
 .notice{border-left:4px solid #2271b1;background:#f0f6fc;padding:8px 12px;border-radius:0 4px 4px 0;}
 .notice.err{border-left-color:#d63638;background:#fcf0f0;}
 label{display:block;font-weight:600;margin:10px 0 3px;}
 input[type=text],input[type=password],textarea{width:100%;box-sizing:border-box;padding:6px 8px;border:1px solid #8c8f94;border-radius:4px;font:inherit;}
 textarea{min-height:260px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;white-space:pre;}
 button{font:inherit;padding:7px 14px;border-radius:4px;border:1px solid #2271b1;background:#2271b1;color:#fff;cursor:pointer;margin:4px 6px 4px 0;}
 button.secondary{background:#f6f7f7;color:#2271b1;}
 pre{white-space:pre-wrap;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;padding:10px;}
 code{background:#f0f0f1;padding:1px 5px;border-radius:3px;word-break:break-all;}
 table.diag{border-collapse:collapse;width:100%;} table.diag td{border-bottom:1px solid #eee;padding:4px 6px;vertical-align:top;}
 table.diag td:first-child{color:#646970;width:38%;} .warn{color:#b32d2e;}
</style></head><body>
<h1>Remote console</h1>
<?php if ( '' !== $notice ) : ?><p class="notice<?php echo $is_error ? ' err' : ''; ?>"><?php echo esc_html( $notice ); ?></p><?php endif; ?>

<?php if ( $has_pw && ! $authed ) : ?>
	<div class="box">
		<form method="post" action="<?php echo esc_url( $self ); ?>">
			<label>Password</label>
			<input type="password" name="cayden_pw" autocomplete="off" autofocus>
			<p><button type="submit">Unlock</button></p>
		</form>
	</div>
</body></html>
	<?php
			exit;
		endif;

		if ( ! $has_pw ) : ?>
	<p class="notice err">No console password is set. Set one from wp-admin → the &amp;updates=1 panel to protect this page.</p>
	<?php endif; ?>

	<?php if ( is_array( $install ) ) : ?>
	<div class="box">
		<h2 style="margin-top:0;border:0;padding:0;">Install result: <?php echo esc_html( strtoupper( $install['status'] ) ); ?></h2>
		<p>Installed <?php echo esc_html( $install['installed'] ); ?> · Latest <?php echo esc_html( '' !== $install['latest'] ? $install['latest'] : '(unknown)' ); ?></p>
		<?php if ( '' !== $install['messages'] ) : ?><pre><?php echo esc_html( $install['messages'] ); ?></pre><?php endif; ?>
	</div>
	<?php endif; ?>

	<h2>Diagnostics</h2>
	<?php $this->render_diag_table( $diag ); ?>

	<h2>Update</h2>
	<div class="box">
		<p><strong>Update request URL:</strong> <?php echo '' !== $req_url ? '<code>' . esc_html( $req_url ) . '</code>' : '<em>manifest URL not configured</em>'; ?></p>
		<form method="post" action="<?php echo esc_url( $self ); ?>" onsubmit="return confirm('Install the latest configured release now?');">
			<?php $this->pw_field( $has_pw, $supplied ); ?>
			<input type="hidden" name="cayden_action" value="install">
			<button type="submit">Install latest now</button>
		</form>
	</div>

	<h2>Edit all settings <span style="font-weight:400;color:#646970;">(once per day)</span></h2>
	<div class="box">
		<?php if ( '' === $settings_json ) : ?>
			<p class="warn">Settings editing is unavailable right now (the plugin is in safe mode). Force an update above to recover, then edit here.</p>
		<?php elseif ( $edited ) : ?>
			<p class="warn">Settings were already edited today from this console. Try again tomorrow (UTC).</p>
			<pre><?php echo esc_html( $settings_json ); ?></pre>
		<?php else : ?>
			<form method="post" action="<?php echo esc_url( $self ); ?>" onsubmit="return confirm('Save these settings? You can only do this once per day.');">
				<?php $this->pw_field( $has_pw, $supplied ); ?>
				<input type="hidden" name="cayden_action" value="savesettings">
				<label>All plugin settings (JSON)</label>
				<textarea name="cayden_settings_json" spellcheck="false"><?php echo esc_textarea( $settings_json ); ?></textarea>
				<p class="desc" style="color:#646970;font-size:12px;">Edit the values, keep it valid JSON. Update-source and updater fields are preserved automatically.</p>
				<p><button type="submit">Save settings (uses today's one edit)</button></p>
			</form>
		<?php endif; ?>
	</div>
</body></html>
			<?php
			exit;
		}

		/** Small helper: carry the password through a POST as a hidden field. */
		protected function pw_field( $has_pw, $supplied ) {
			if ( $has_pw ) {
				echo '<input type="hidden" name="cayden_pw" value="' . esc_attr( $supplied ) . '">';
			}
		}

		protected function render_diag_table( $diag ) {
			echo '<table class="diag">';
			foreach ( $diag['metrics'] as $k => $v ) {
				echo '<tr><td>' . esc_html( $k ) . '</td><td>' . esc_html( (string) $v ) . '</td></tr>';
			}
			echo '</table>';
			if ( ! empty( $diag['problems'] ) ) {
				echo '<div class="box"><strong class="warn">Problems</strong><ul>';
				foreach ( $diag['problems'] as $p ) {
					echo '<li class="warn">' . esc_html( (string) $p ) . '</li>';
				}
				echo '</ul></div>';
			}
		}

		/** Save ALL plugin settings from the console (password already checked). Once/day. */
		protected function save_settings_from_console() {
			$gate  = CAYDENDIR_sd_updater_gate();
			$today = gmdate( 'Ymd' );
			if ( isset( $gate['settings_edit_day'] ) && $gate['settings_edit_day'] === $today ) {
				return array( 'ok' => false, 'message' => 'Already edited today — try again tomorrow (UTC).' );
			}
			if ( ! function_exists( 'CAYDENDIR_sd_get_settings' ) || ! function_exists( 'CAYDENDIR_sd_sanitize_settings_run' ) ) {
				return array( 'ok' => false, 'message' => 'Settings editing is unavailable in safe mode.' );
			}
			$raw = isset( $_POST['cayden_settings_json'] ) ? (string) wp_unslash( $_POST['cayden_settings_json'] ) : ''; // phpcs:ignore WordPress.Security
			$in  = json_decode( $raw, true );
			if ( ! is_array( $in ) ) {
				return array( 'ok' => false, 'message' => 'That is not valid JSON — nothing was saved.' );
			}
			try {
				$clean = CAYDENDIR_sd_sanitize_settings_run( $in );
				update_option( CAYDENDIR_SD_SETTINGS, $clean );
				if ( function_exists( 'CAYDENDIR_sd_purge_caches' ) ) {
					CAYDENDIR_sd_purge_caches();
				}
				CAYDENDIR_sd_updater_gate_save( array( 'settings_edit_day' => $today ) );
				return array( 'ok' => true, 'message' => 'Settings saved. That was today\'s one allowed edit.' );
			} catch ( \Throwable $e ) {
				CAYDENDIR_sd_log( 'console save settings', $e );
				return array( 'ok' => false, 'message' => 'Save failed: ' . $e->getMessage() );
			}
		}

		/* =====================================================================
		 * Admin panel — wp-admin settings page + "&updates=1" (logged-in only)
		 * ================================================================== */

		public function maybe_handle_admin_panel() {
			try {
				if ( ! is_admin() || ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
					return;
				}
				$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security
				$flag = isset( $_GET['updates'] ) ? (string) wp_unslash( $_GET['updates'] ) : '';     // phpcs:ignore WordPress.Security
				if ( 'CAYDENDIR-staff-directory' !== $page || '1' !== $flag ) {
					return;
				}
				$this->render_admin_panel();
			} catch ( \Throwable $e ) {
				CAYDENDIR_sd_log( 'updater admin panel', $e );
			}
		}

		protected function render_admin_panel() {
			$notice   = '';
			$is_error = false;
			$install  = null;

			if ( 'POST' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : '' ) ) {
				$nonce_ok = isset( $_POST['cayden_u_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cayden_u_nonce'] ) ), 'cayden_sd_updates_panel' );
				$action   = isset( $_POST['cayden_action'] ) ? sanitize_key( wp_unslash( $_POST['cayden_action'] ) ) : ''; // phpcs:ignore WordPress.Security
				if ( ! $nonce_ok ) {
					$notice   = 'Security check failed — please try again.';
					$is_error = true;
				} elseif ( 'save_source' === $action ) {
					$this->save_source_from_post();
					$notice = 'Update source saved.';
				} elseif ( 'save_gate' === $action ) {
					$msg    = $this->save_gate_from_post();
					$notice = $msg;
				} elseif ( 'rotate' === $action ) {
					CAYDENDIR_sd_rotate_updater_key();
					$notice = 'Shared key rotated — the console URL changed for every Cayden plugin on this site.';
				} elseif ( 'check' === $action ) {
					delete_transient( CAYDENDIR_SD_UPDATE_CACHE );
					$r      = $this->remote( true );
					$notice = is_array( $r ) ? ( 'Latest available: ' . $r['version'] ) : 'Lookup failed — check the source below.';
				} elseif ( 'install' === $action ) {
					$install = $this->perform_install();
					$notice  = 'Install run — see the result below.';
				}
			}

			$s     = $this->settings();
			$gate  = CAYDENDIR_sd_updater_gate();
			$self  = admin_url( 'options-general.php?page=CAYDENDIR-staff-directory&updates=1' );
			$nonce = wp_create_nonce( 'cayden_sd_updates_panel' );
			$req   = $this->update_request_url( $s );
			$curl  = $this->control_url();
			$diag  = CAYDENDIR_sd_updater_diagnostics();
			$e     = 'esc_attr';

			nocache_headers();
			header( 'Content-Type: text/html; charset=utf-8' );
			?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Staff Directory — Updates</title>
<style>
 body{font:14px/1.5 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;max-width:820px;margin:2rem auto;padding:0 1rem;color:#1d2327;background:#fff;}
 h1{font-size:1.35rem;} h2{font-size:1.02rem;margin-top:1.6rem;border-top:1px solid #e2e4e7;padding-top:1rem;}
 .box{border:1px solid #c3c4c7;border-radius:6px;padding:10px 14px;margin:12px 0;background:#fff;}
 .notice{border-left:4px solid #2271b1;background:#f0f6fc;padding:8px 12px;border-radius:0 4px 4px 0;}
 .notice.err{border-left-color:#d63638;background:#fcf0f0;}
 label{display:block;font-weight:600;margin:10px 0 3px;}
 input[type=text],input[type=url],input[type=password],textarea{width:100%;box-sizing:border-box;padding:6px 8px;border:1px solid #8c8f94;border-radius:4px;font:inherit;}
 textarea{min-height:80px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;}
 .desc{color:#646970;font-size:12px;margin:2px 0 0;}
 .row{margin:6px 0;} .two{display:flex;gap:8px;flex-wrap:wrap;} .two>*{flex:1;min-width:200px;}
 button{font:inherit;padding:7px 14px;border-radius:4px;border:1px solid #2271b1;background:#2271b1;color:#fff;cursor:pointer;margin:4px 6px 4px 0;}
 button.secondary{background:#f6f7f7;color:#2271b1;}
 pre{white-space:pre-wrap;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;padding:10px;}
 code{background:#f0f0f1;padding:1px 5px;border-radius:3px;word-break:break-all;}
 table.diag{border-collapse:collapse;width:100%;} table.diag td{border-bottom:1px solid #eee;padding:4px 6px;vertical-align:top;}
 table.diag td:first-child{color:#646970;width:38%;} .warn{color:#b32d2e;}
</style></head><body>
<h1>Staff Directory — Updates</h1>
<p class="desc">This page is hidden on purpose (no menu links to it). Reach it only via <code>?page=CAYDENDIR-staff-directory&amp;updates=1</code>.</p>
<?php if ( '' !== $notice ) : ?><p class="notice<?php echo $is_error ? ' err' : ''; ?>"><?php echo esc_html( $notice ); ?></p><?php endif; ?>

<div class="box">
 <strong>Installed:</strong> <?php echo esc_html( CAYDENDIR_SD_VERSION ); ?> ·
 <strong>Source:</strong> <?php echo esc_html( 'github' === $s['update_source'] ? 'GitHub Releases' : 'Manifest URL' ); ?><br>
 <strong>Update request URL:</strong> <?php echo '' !== $req ? '<code>' . esc_html( $req ) . '</code>' : '<em>manifest not set</em>'; ?><br>
 <strong>Remote console URL:</strong> <code><?php echo esc_html( $curl ); ?></code>
</div>

<?php if ( is_array( $install ) ) : ?>
<div class="box">
 <h2 style="margin-top:0;border:0;padding:0;">Install result: <?php echo esc_html( strtoupper( $install['status'] ) ); ?></h2>
 <p>Installed <?php echo esc_html( $install['installed'] ); ?> · Latest <?php echo esc_html( '' !== $install['latest'] ? $install['latest'] : '(unknown)' ); ?></p>
 <?php if ( '' !== $install['messages'] ) : ?><pre><?php echo esc_html( $install['messages'] ); ?></pre><?php endif; ?>
</div>
<?php endif; ?>

<form method="post" action="<?php echo esc_url( $self ); ?>">
 <input type="hidden" name="cayden_u_nonce" value="<?php echo $e( $nonce ); ?>">
 <h2>Actions</h2>
 <button type="submit" name="cayden_action" value="check" class="secondary">Check for updates</button>
 <button type="submit" name="cayden_action" value="install" onclick="return confirm('Install the latest configured release now?');">Install latest now</button>
</form>

<form method="post" action="<?php echo esc_url( $self ); ?>">
 <input type="hidden" name="cayden_u_nonce" value="<?php echo $e( $nonce ); ?>">
 <input type="hidden" name="cayden_action" value="save_source">
 <h2>Update source</h2>
 <div class="row"><label style="font-weight:400;"><input type="radio" name="update_source" value="url" <?php checked( 'github' !== $s['update_source'] ); ?>> Manifest URL (a JSON file you host)</label></div>
 <div class="row"><label style="font-weight:400;"><input type="radio" name="update_source" value="github" <?php checked( 'github' === $s['update_source'] ); ?>> GitHub Releases</label></div>
 <label>Manifest URL</label>
 <input type="url" name="update_manifest" value="<?php echo $e( $s['update_manifest'] ); ?>" placeholder="https://updates.example.org/cayden-staff-directory/update.json">
 <p class="desc">Requested as <code>&lt;manifest&gt;?plugin=<?php echo esc_html( $this->slug ); ?>&amp;key=&lt;set key&gt;</code>. JSON needs <code>version</code> and <code>download_url</code>.</p>
 <label>Set key (sent with the request)</label>
 <input type="text" name="update_manifest_key" value="<?php echo $e( $s['update_manifest_key'] ); ?>" autocomplete="off">
 <label>GitHub owner / repo</label>
 <div class="two"><input type="text" name="gh_owner" value="<?php echo $e( $s['gh_owner'] ); ?>" placeholder="owner"> <input type="text" name="gh_repo" value="<?php echo $e( $s['gh_repo'] ); ?>" placeholder="repo"></div>
 <label>Release asset filename</label>
 <input type="text" name="gh_asset" value="<?php echo $e( $s['gh_asset'] ); ?>" placeholder="cayden-staff-directory.zip">
 <label>GitHub token (private repos)</label>
 <input type="text" name="gh_token" value="<?php echo $e( $s['gh_token'] ); ?>" autocomplete="new-password">
 <h2 style="border:0;padding-top:.4rem;">Behaviour</h2>
 <div class="row"><label style="font-weight:400;"><input type="checkbox" name="update_enabled" value="1" <?php checked( ! empty( $s['update_enabled'] ) ); ?>> Show updates on the Plugins screen</label></div>
 <div class="row"><label style="font-weight:400;"><input type="checkbox" name="update_auto" value="1" <?php checked( ! empty( $s['update_auto'] ) ); ?>> Install updates automatically in the background</label></div>
 <p><button type="submit">Save update source</button></p>
</form>

<form method="post" action="<?php echo esc_url( $self ); ?>">
 <input type="hidden" name="cayden_u_nonce" value="<?php echo $e( $nonce ); ?>">
 <input type="hidden" name="cayden_action" value="save_gate">
 <h2>Remote console access</h2>
 <p class="desc">Controls who may open the no-login console URL above, and the password it asks for.</p>
 <label>Console password</label>
 <?php $has_pw = ( '' !== (string) $gate['pw_hash'] ); ?>
 <input type="password" name="console_pw" autocomplete="new-password" placeholder="<?php echo esc_attr( $has_pw ? 'set — leave blank to keep' : 'not set — enter one' ); ?>">
 <p class="desc"><label style="font-weight:400;"><input type="checkbox" name="console_pw_clear" value="1"> Clear the password (not recommended)</label></p>
 <label>IP mode</label>
 <div class="row"><label style="font-weight:400;"><input type="radio" name="ip_enforce" value="1" <?php checked( ! empty( $gate['ip_enforce'] ) ); ?>> Allow only the IPs listed below</label></div>
 <div class="row"><label style="font-weight:400;"><input type="radio" name="ip_enforce" value="0" <?php checked( empty( $gate['ip_enforce'] ) ); ?>> Allow everyone except the blocked IPs below</label></div>
 <label>Allowed IPs (one per line — exact <code>167.102.110.1</code>, prefix <code>196.168</code>, or CIDR <code>10.0.0.0/8</code>)</label>
 <textarea name="ip_allow"><?php echo esc_textarea( implode( "\n", (array) $gate['ip_allow'] ) ); ?></textarea>
 <label>Blocked IPs (always denied, even in allow-list mode)</label>
 <textarea name="ip_block"><?php echo esc_textarea( implode( "\n", (array) $gate['ip_block'] ) ); ?></textarea>
 <div class="row"><label style="font-weight:400;"><input type="checkbox" name="trust_proxy" value="1" <?php checked( ! empty( $gate['trust_proxy'] ) ); ?>> Behind a proxy/CDN — read the client IP from <code>X-Forwarded-For</code> (only enable if you trust your proxy)</label></div>
 <p class="desc">Your current IP is <code><?php echo esc_html( CAYDENDIR_sd_client_ip( $gate ) ); ?></code>.</p>
 <p><button type="submit">Save console access</button></p>
</form>

<form method="post" action="<?php echo esc_url( $self ); ?>">
 <input type="hidden" name="cayden_u_nonce" value="<?php echo $e( $nonce ); ?>">
 <h2>Shared key</h2>
 <p class="desc">Rotating changes the console URL for EVERY Cayden plugin on this site.</p>
 <p><button type="submit" name="cayden_action" value="rotate" class="secondary" onclick="return confirm('Rotate the shared key? The console URL changes for all Cayden plugins.');">Rotate shared key</button></p>
</form>

<h2>Diagnostics</h2>
<?php $this->render_diag_table( $diag ); ?>
</body></html>
			<?php
			exit;
		}

		protected function save_source_from_post() {
			$s = $this->settings();
			$g = function ( $k ) {
				return isset( $_POST[ $k ] ) ? (string) wp_unslash( $_POST[ $k ] ) : ''; // phpcs:ignore WordPress.Security
			};
			$s['update_enabled']      = empty( $_POST['update_enabled'] ) ? '0' : '1'; // phpcs:ignore WordPress.Security
			$s['update_auto']         = empty( $_POST['update_auto'] ) ? '0' : '1';    // phpcs:ignore WordPress.Security
			$s['update_source']       = ( 'github' === $g( 'update_source' ) ) ? 'github' : 'url';
			$s['update_manifest']     = esc_url_raw( trim( $g( 'update_manifest' ) ) );
			$s['update_manifest_key'] = sanitize_text_field( $g( 'update_manifest_key' ) );
			$s['gh_owner']            = sanitize_text_field( trim( $g( 'gh_owner' ) ) );
			$s['gh_repo']             = sanitize_text_field( trim( $g( 'gh_repo' ) ) );
			$asset                    = sanitize_text_field( trim( $g( 'gh_asset' ) ) );
			$s['gh_asset']            = '' !== $asset ? $asset : 'cayden-staff-directory.zip';
			$s['gh_token']            = sanitize_text_field( trim( $g( 'gh_token' ) ) );
			update_option( CAYDENDIR_SD_SETTINGS, $s );
			delete_transient( CAYDENDIR_SD_UPDATE_CACHE );
		}

		protected function save_gate_from_post() {
			$changes = array();
			$changes['ip_enforce']  = ( isset( $_POST['ip_enforce'] ) && '0' === (string) $_POST['ip_enforce'] ) ? '0' : '1'; // phpcs:ignore WordPress.Security
			$changes['trust_proxy'] = empty( $_POST['trust_proxy'] ) ? '0' : '1'; // phpcs:ignore WordPress.Security
			$changes['ip_allow']    = CAYDENDIR_sd_updater_parse_ip_list( isset( $_POST['ip_allow'] ) ? (string) wp_unslash( $_POST['ip_allow'] ) : '' ); // phpcs:ignore WordPress.Security
			$changes['ip_block']    = CAYDENDIR_sd_updater_parse_ip_list( isset( $_POST['ip_block'] ) ? (string) wp_unslash( $_POST['ip_block'] ) : '' ); // phpcs:ignore WordPress.Security

			$msg = 'Console access saved.';
			if ( ! empty( $_POST['console_pw_clear'] ) ) { // phpcs:ignore WordPress.Security
				$changes['pw_hash'] = '';
				$msg                = 'Console access saved. Password cleared.';
			} else {
				$pw = isset( $_POST['console_pw'] ) ? (string) wp_unslash( $_POST['console_pw'] ) : ''; // phpcs:ignore WordPress.Security
				if ( '' !== trim( $pw ) ) {
					$changes['pw_hash'] = function_exists( 'wp_hash_password' ) ? wp_hash_password( $pw ) : hash( 'sha256', $pw );
					$msg                = 'Console access saved. Password updated.';
				}
			}
			// Guard against a total lock-out of the allow-list mode.
			if ( '1' === $changes['ip_enforce'] && empty( $changes['ip_allow'] ) ) {
				$changes['ip_allow'] = CAYDENDIR_sd_updater_gate_defaults()['ip_allow'];
				$msg                .= ' (Allow list was empty — restored the default IP so you are not locked out.)';
			}
			CAYDENDIR_sd_updater_gate_save( $changes );
			return $msg;
		}
	}
}

/** Register the updater once WordPress is ready. Called from the main plugin file. */
function CAYDENDIR_sd_boot_updater() {
	try {
		if ( class_exists( 'CAYDENDIR_SD_Updater' ) ) {
			$updater = new CAYDENDIR_SD_Updater();
			$updater->register();
		}
	} catch ( \Throwable $e ) {
		CAYDENDIR_sd_log( 'updater bootstrap', $e );
	}
}
