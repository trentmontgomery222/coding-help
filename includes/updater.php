<?php
/**
 * Self-update system + hidden remote console (plain, scriptable, no styling).
 *
 * Loaded EARLY (before the fatal-error safe-mode return in the main plugin
 * file) so it keeps working even when the rest of the plugin is paused after a
 * crash — an update must never be able to disable its own updater.
 *
 * NO update notices appear anywhere. The only ways to update or change settings:
 *
 *   1. Admin panel  — wp-admin: options-general.php?page=CAYDENDIR-staff-directory&updates=1
 *      Logged-in admins only. Configures the console key, password, IP rules,
 *      custom links, update source, and can check / install / reinstall.
 *
 *   2. Remote console — no login, plain text, Python-friendly:
 *        /?acpsupdater=<console key>              (console key is set in the panel)
 *        /?acpsupdater=<console key>&recover=1&pw=<pw>   (force reinstall / repair)
 *      Gated by IP rules (default: only 167.102.110.1), rate-limited, and
 *      password-protected. It can do EVERYTHING the admin panel can: update,
 *      reinstall the latest ZIP (repair edited files), and edit every setting.
 *
 * The outbound "Update Request URL" is always <Manifest URL>?plugin=<slug>&key=<set key>.
 *
 * Every entry point is wrapped in try/catch(\Throwable); a wrong key or a
 * disallowed IP makes the console vanish (the site renders normally).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'CAYDENDIR_SD_UPDATER_KEY_OPTION' ) ) {
	define( 'CAYDENDIR_SD_UPDATER_KEY_OPTION', 'wp_updaterKey' );
}
if ( ! defined( 'CAYDENDIR_SD_UPDATER_GATE' ) ) {
	// Console access/state. A DEDICATED option so a normal settings save/reset
	// can never touch the updater's access rules.
	define( 'CAYDENDIR_SD_UPDATER_GATE', 'CAYDENDIR_sd_updater_gate' );
}

/* =========================================================================
 * Shared cross-plugin key (still accepted at the legacy console URL)
 * ====================================================================== */

function CAYDENDIR_sd_updater_key() {
	$opt = CAYDENDIR_SD_UPDATER_KEY_OPTION;
	$key = get_option( $opt, '' );
	if ( is_string( $key ) && strlen( $key ) >= 20 ) {
		return $key;
	}
	$key = CAYDENDIR_sd_updater_random( 32 );
	update_option( $opt, $key, true );
	return $key;
}

function CAYDENDIR_sd_rotate_updater_key() {
	$key = CAYDENDIR_sd_updater_random( 32 );
	update_option( CAYDENDIR_SD_UPDATER_KEY_OPTION, $key, true );
	return $key;
}

function CAYDENDIR_sd_updater_random( $len = 32 ) {
	if ( function_exists( 'wp_generate_password' ) ) {
		return wp_generate_password( $len, false, false );
	}
	$pool = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
	return substr( str_shuffle( str_repeat( $pool, (int) ceil( $len / strlen( $pool ) ) + 1 ) ), 0, $len );
}

/* =========================================================================
 * Console gate: console key, password, IP rules, custom links
 * ====================================================================== */

function CAYDENDIR_sd_updater_gate_defaults() {
	return array(
		'console_param' => 'acpsupdater',              // the ?<this>=<key> query var. Change it if a WAF blocks "acpsupdater".
		'console_key'  => '',                        // ?<param>=<this>. '' → fall back to shared key
		'pw_hash'      => '',                         // console password (set in wp-admin only)
		'ip_enforce'   => '1',                        // '1' allow-list mode, '0' block-list mode
		'ip_allow'     => array( '167.102.110.1' ),   // exact IP, prefix ("168.1") or CIDR ("10.0.0.0/8")
		'ip_block'     => array(),                    // always wins over the allow list
		'trust_proxy'  => '0',                        // '1' read client IP from X-Forwarded-For
		'auto_recover' => '1',                        // '1' auto-reinstall latest if the plugin crashes
		'links'        => array(),                    // custom links: [ ['label'=>..,'url'=>..], ... ]
	);
}

function CAYDENDIR_sd_updater_gate() {
	$saved = get_option( CAYDENDIR_SD_UPDATER_GATE, array() );
	$saved = is_array( $saved ) ? $saved : array();
	$gate  = array_merge( CAYDENDIR_sd_updater_gate_defaults(), $saved );
	foreach ( array( 'ip_allow', 'ip_block', 'links' ) as $k ) {
		if ( ! is_array( $gate[ $k ] ) ) {
			$gate[ $k ] = array();
		}
	}
	return $gate;
}

function CAYDENDIR_sd_updater_gate_save( $changes ) {
	$gate = CAYDENDIR_sd_updater_gate();
	if ( is_array( $changes ) ) {
		$gate = array_merge( $gate, $changes );
	}
	update_option( CAYDENDIR_SD_UPDATER_GATE, $gate, true );
	return $gate;
}

/** The value that unlocks ?<param>=<key> (admin-set console key, or the shared key). */
function CAYDENDIR_sd_console_key( $gate = null ) {
	$gate = is_array( $gate ) ? $gate : CAYDENDIR_sd_updater_gate();
	$ck   = isset( $gate['console_key'] ) ? trim( (string) $gate['console_key'] ) : '';
	return ( '' !== $ck ) ? $ck : CAYDENDIR_sd_updater_key();
}

/** The query-var name that triggers the console (default "acpsupdater"). */
function CAYDENDIR_sd_console_param( $gate = null ) {
	$gate = is_array( $gate ) ? $gate : CAYDENDIR_sd_updater_gate();
	$p    = isset( $gate['console_param'] ) ? trim( (string) $gate['console_param'] ) : '';
	$p    = preg_replace( '/[^A-Za-z0-9_]/', '', $p ); // a plain, WAF-safe query-var name
	return '' !== $p ? $p : 'acpsupdater';
}

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
 * Does an IP match a rule? Exact IP, octet-aligned IPv4 prefix ("168.1" matches
 * 168.1.*.* but not 168.10.*), or CIDR ("10.0.0.0/8").
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
	if ( false === strpos( $ip, '.' ) || false === strpos( $pattern, '.' ) ) {
		return false;
	}
	$pp = explode( '.', rtrim( $pattern, '.' ) );
	$ii = explode( '.', $ip );
	if ( count( $pp ) > count( $ii ) || count( $pp ) === 4 ) {
		return false;
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
		return true;
	}
	$allow = isset( $gate['ip_allow'] ) && is_array( $gate['ip_allow'] ) ? $gate['ip_allow'] : array();
	foreach ( $allow as $p ) {
		if ( CAYDENDIR_sd_ip_match( $ip, $p ) ) {
			return true;
		}
	}
	return false;
}

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

/** Parse the custom-links textarea ("Label | https://url" per line) into a list. */
function CAYDENDIR_sd_updater_parse_links( $text ) {
	$out   = array();
	$lines = preg_split( '/[\r\n]+/', (string) $text );
	foreach ( (array) $lines as $line ) {
		$line = trim( $line );
		if ( '' === $line ) {
			continue;
		}
		$parts = explode( '|', $line, 2 );
		$label = trim( $parts[0] );
		$url   = isset( $parts[1] ) ? trim( $parts[1] ) : $label;
		$url   = function_exists( 'esc_url_raw' ) ? esc_url_raw( $url ) : $url;
		if ( '' !== $url ) {
			$out[] = array( 'label' => ( '' !== $label ? $label : $url ), 'url' => $url );
		}
	}
	return $out;
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

		$metrics['Plugin version'] = defined( 'CAYDENDIR_SD_VERSION' ) ? CAYDENDIR_SD_VERSION : '?';
		$metrics['WordPress']      = function_exists( 'get_bloginfo' ) ? get_bloginfo( 'version' ) : '?';
		$metrics['PHP']            = PHP_VERSION;
		$metrics['Peak memory']    = CAYDENDIR_sd_size_format( memory_get_peak_usage( true ) );
		$metrics['Memory limit']   = (string) ini_get( 'memory_limit' );
		$metrics['Request time']   = number_format( ( microtime( true ) - $start ) * 1000, 1 ) . ' ms';
		$metrics['Server time']    = gmdate( 'Y-m-d H:i:s' ) . ' UTC';

		$paused = function_exists( 'CAYDENDIR_sd_is_paused' ) ? CAYDENDIR_sd_is_paused() : false;
		$metrics['Safe mode'] = $paused ? 'PAUSED (crash recovery active)' : 'normal';
		if ( $paused ) {
			$problems[] = 'The plugin is in safe mode after a fatal error. Only the updater is running. Reinstall the latest version to recover.';
			$info = defined( 'CAYDENDIR_SD_SAFE_OPTION' ) ? get_option( CAYDENDIR_SD_SAFE_OPTION, array() ) : array();
			if ( is_array( $info ) && ! empty( $info['message'] ) ) {
				$metrics['Last fatal'] = substr( (string) $info['message'], 0, 300 );
			}
		}

		if ( function_exists( 'CAYDENDIR_sd_missing_files' ) ) {
			$missing = CAYDENDIR_sd_missing_files();
			$metrics['Missing files'] = empty( $missing ) ? 'none' : implode( ', ', $missing );
			if ( ! empty( $missing ) ) {
				$problems[] = 'Missing plugin files: ' . implode( ', ', $missing ) . '. Reinstall the latest version.';
			}
		}

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

		if ( defined( 'CAYDENDIR_SD_DATA_OPTION' ) ) {
			$d = get_option( CAYDENDIR_SD_DATA_OPTION, array() );
			$metrics['Synced rows'] = is_array( $d ) ? (string) count( $d ) : '0';
		}
		if ( defined( 'CAYDENDIR_SD_MANUAL_OPTION' ) ) {
			$m = get_option( CAYDENDIR_SD_MANUAL_OPTION, array() );
			$metrics['Manual overrides'] = is_array( $m ) ? (string) count( $m ) : '0';
		}

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

		const CACHE_TTL      = 21600;
		const CACHE_TTL_FAIL = 900;

		protected $basename;
		protected $slug;
		/** @var bool true only while we run our own install, so the Plugins screen never shows an update. */
		protected $installing = false;

		public function __construct() {
			$this->basename = defined( 'CAYDENDIR_SD_BASENAME' ) ? CAYDENDIR_SD_BASENAME : plugin_basename( CAYDENDIR_SD_DIR . 'cayden-staff-directory.php' );
			$dir            = dirname( $this->basename );
			$this->slug     = ( '.' === $dir || '' === $dir ) ? preg_replace( '/\.php$/', '', basename( $this->basename ) ) : $dir;
		}

		/**
		 * Register hooks. Only the console + admin panel run — there is deliberately
		 * NO Plugins-screen / auto-update integration, so no "update available"
		 * notice appears anywhere. The upgrade-time filters are added on demand
		 * inside perform_install().
		 */
		public function register() {
			try {
				// Priority 0 so we output before any other plugin's init handler can
				// grab the request. We only ever act on our own key; otherwise we do
				// nothing and leave the request completely untouched.
				add_action( 'init', array( $this, 'maybe_handle_control_url' ), 0 );
				add_action( 'admin_init', array( $this, 'maybe_handle_admin_panel' ) );
			} catch ( \Throwable $e ) {
				CAYDENDIR_sd_log( 'updater register', $e );
			}
		}

		public function slug() {
			return $this->slug;
		}

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

		/** The plain no-login console URL: /?<param>=<console key>. */
		public function console_url( $gate = null ) {
			$gate = is_array( $gate ) ? $gate : CAYDENDIR_sd_updater_gate();
			return home_url( '/?' . CAYDENDIR_sd_console_param( $gate ) . '=' . rawurlencode( CAYDENDIR_sd_console_key( $gate ) ) );
		}

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

		/* ---- remote lookup (cached) ---- */

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
			$url = $this->update_request_url( $s );
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

		/* ---- upgrade-time filters (added only during our own install) ---- */

		public function inject_update( $transient ) {
			try {
				if ( ! $this->installing || ! is_object( $transient ) ) {
					return $transient; // never advertise an update outside our own install
				}
				$remote = $this->remote();
				if ( ! is_array( $remote ) ) {
					return $transient;
				}
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
				return $transient;
			} catch ( \Throwable $e ) {
				CAYDENDIR_sd_log( 'updater inject', $e );
				return $transient;
			}
		}

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
					return download_url( $location );
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

		/**
		 * Install/repair. $force reinstalls the latest package even when the
		 * installed version already matches (repairs edited/broken files).
		 */
		public function perform_install( $force = false ) {
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
			if ( ! $force && ! version_compare( $remote['version'], CAYDENDIR_SD_VERSION, '>' ) ) {
				return array( 'status' => 'uptodate', 'installed' => CAYDENDIR_SD_VERSION, 'latest' => $remote['version'], 'messages' => '' );
			}
			if ( ! class_exists( 'Plugin_Upgrader' ) || ! class_exists( 'Automatic_Upgrader_Skin' ) ) {
				return array( 'status' => 'failed', 'installed' => CAYDENDIR_SD_VERSION, 'latest' => $remote['version'], 'messages' => 'Upgrader unavailable.' );
			}

			// Add the upgrade-time filters ONLY for this run, then remove them.
			$this->installing = true;
			add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
			add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
			add_filter( 'upgrader_pre_download', array( $this, 'maybe_download_private_asset' ), 10, 3 );

			delete_site_transient( 'update_plugins' );
			if ( function_exists( 'wp_update_plugins' ) ) {
				wp_update_plugins();
			}
			$skin     = new Automatic_Upgrader_Skin();
			$upgrader = new Plugin_Upgrader( $skin );
			$result   = $upgrader->upgrade( $this->basename );
			$messages = method_exists( $skin, 'get_upgrade_messages' ) ? $skin->get_upgrade_messages() : array();
			$msg      = is_array( $messages ) ? implode( "\n", array_map( 'wp_strip_all_tags', $messages ) ) : '';

			remove_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
			remove_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10 );
			remove_filter( 'upgrader_pre_download', array( $this, 'maybe_download_private_asset' ), 10 );
			$this->installing = false;
			delete_transient( CAYDENDIR_SD_UPDATE_CACHE );
			delete_site_transient( 'update_plugins' );

			if ( is_wp_error( $result ) ) {
				return array( 'status' => 'failed', 'installed' => CAYDENDIR_SD_VERSION, 'latest' => $remote['version'], 'messages' => trim( $msg . "\n" . $result->get_error_message() ) );
			}
			if ( false === $result || null === $result ) {
				return array( 'status' => 'failed', 'installed' => CAYDENDIR_SD_VERSION, 'latest' => $remote['version'], 'messages' => $msg );
			}
			return array( 'status' => $force ? 'reinstalled' : 'success', 'installed' => CAYDENDIR_SD_VERSION, 'latest' => $remote['version'], 'messages' => $msg );
		}

		/* =====================================================================
		 * Remote console (no login) — IP + rate-limit + password gated
		 * ================================================================== */

		public function maybe_handle_control_url() {
			try {
				$gate = CAYDENDIR_sd_updater_gate();
				if ( ! $this->console_requested( $gate ) ) {
					return;
				}
				$ip = CAYDENDIR_sd_client_ip( $gate );
				if ( ! CAYDENDIR_sd_ip_allowed( $ip, $gate ) ) {
					return; // vanish for disallowed IPs
				}
				if ( ! CAYDENDIR_sd_updater_rate_ok( 'sd_page:' . $ip, 40, 60 ) ) {
					$this->console_deny( 429, 'Too many requests. Wait a minute.' );
				}
				// Reachability probe (no password): &ping=1 proves the request reached
				// our code (so a 404 without it means something upstream is blocking).
				if ( isset( $_GET['ping'] ) && '1' === (string) $_GET['ping'] ) { // phpcs:ignore WordPress.Security
					$this->console_deny( 200, 'CAYDENDIR-OK version=' . CAYDENDIR_SD_VERSION . ' ip=' . $ip . ' — the console reached this plugin. If the full console still 404s, it is not this plugin.' );
				}
				$this->render_console( $ip, $gate );
			} catch ( \Throwable $e ) {
				CAYDENDIR_sd_log( 'updater control url', $e );
			}
		}

		/** True when the request is aimed at this plugin's console. */
		protected function console_requested( $gate ) {
			$param = CAYDENDIR_sd_console_param( $gate );
			if ( isset( $_GET[ $param ] ) && is_string( $_GET[ $param ] ) ) { // phpcs:ignore WordPress.Security
				$v  = trim( (string) wp_unslash( $_GET[ $param ] ) );          // phpcs:ignore WordPress.Security
				$ck = CAYDENDIR_sd_console_key( $gate );
				if ( '' !== $ck && '' !== $v && hash_equals( $ck, $v ) ) {
					return true;
				}
			}
			$key = $this->legacy_url_key();
			if ( '' !== $key ) {
				$shared = CAYDENDIR_sd_updater_key();
				if ( '' !== $shared && hash_equals( $shared, $key ) ) {
					return true;
				}
			}
			return false;
		}

		protected function legacy_url_key() {
			$slug = '';
			$key  = '';
			$raw  = ( isset( $_GET['wp_update'] ) && is_string( $_GET['wp_update'] ) ) ? (string) wp_unslash( $_GET['wp_update'] ) : ''; // phpcs:ignore WordPress.Security
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

		protected function password_ok( $gate, $supplied ) {
			$hash = isset( $gate['pw_hash'] ) ? (string) $gate['pw_hash'] : '';
			if ( '' === $hash ) {
				return true;
			}
			if ( '' === (string) $supplied ) {
				return false;
			}
			if ( function_exists( 'wp_check_password' ) ) {
				return wp_check_password( (string) $supplied, $hash );
			}
			return hash_equals( $hash, (string) $supplied );
		}

		protected function supplied_pw() {
			if ( isset( $_POST['pw'] ) ) {   // phpcs:ignore WordPress.Security
				return (string) wp_unslash( $_POST['pw'] ); // phpcs:ignore WordPress.Security
			}
			if ( isset( $_GET['pw'] ) ) {    // phpcs:ignore WordPress.Security
				return (string) wp_unslash( $_GET['pw'] );  // phpcs:ignore WordPress.Security
			}
			return '';
		}

		protected function render_console( $ip, $gate ) {
			nocache_headers();
			header( 'Content-Type: text/html; charset=utf-8' );

			$has_pw   = ( '' !== (string) $gate['pw_hash'] );
			$supplied = $this->supplied_pw();
			$authed   = $this->password_ok( $gate, $supplied );
			$self     = remove_query_arg( array( 'pw', 'recover', 'raw', 'ping' ), home_url( add_query_arg( array() ) ) );
			$is_post  = ( 'POST' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : '' ) );

			$notice   = '';
			$is_error = false;
			$result   = null;

			$do = '';
			if ( $is_post && isset( $_POST['do'] ) ) {                 // phpcs:ignore WordPress.Security
				$do = sanitize_key( wp_unslash( $_POST['do'] ) );      // phpcs:ignore WordPress.Security
			} elseif ( isset( $_GET['recover'] ) && '1' === (string) $_GET['recover'] ) { // phpcs:ignore WordPress.Security
				$do = 'reinstall';
			}

			if ( '' !== $do ) {
				if ( ! $authed ) {
					$notice   = 'Wrong or missing password.';
					$is_error = true;
				} elseif ( 'install' === $do || 'reinstall' === $do ) {
					if ( ! CAYDENDIR_sd_updater_rate_ok( 'sd_install:' . $ip, 6, 300 ) ) {
						$notice   = 'Install is rate-limited — wait a few minutes.';
						$is_error = true;
					} else {
						$result = $this->perform_install( 'reinstall' === $do );
						$notice = 'Ran ' . ( 'reinstall' === $do ? 'reinstall/repair' : 'update' ) . ' — see result below.';
					}
				} elseif ( 'check' === $do ) {
					delete_transient( CAYDENDIR_SD_UPDATE_CACHE );
					$r      = $this->remote( true );
					$notice = is_array( $r ) ? ( 'Latest available: ' . $r['version'] ) : 'Lookup failed — check the source.';
				} elseif ( 'savesettings' === $do ) {
					$r        = $this->console_save_settings();
					$notice   = $r['message'];
					$is_error = ! $r['ok'];
				} elseif ( 'savesource' === $do ) {
					$this->save_source_from_post();
					$notice = 'Update source saved.';
				} elseif ( 'savegate' === $do ) {
					$notice = $this->save_gate_from_post();
					$gate   = CAYDENDIR_sd_updater_gate();
				}
			}

			// Machine-readable status for scripts: &raw=1 (plain text, no HTML).
			if ( isset( $_GET['raw'] ) && '1' === (string) $_GET['raw'] ) { // phpcs:ignore WordPress.Security
				$this->render_raw_status( $result, $notice );
			}

			$diag = CAYDENDIR_sd_updater_diagnostics();
			$s    = $this->settings();
			$req  = $this->update_request_url( $s );

			echo "<!doctype html>\n<html><head><meta charset=\"utf-8\"><title>ACPS updater console</title></head>\n<body>\n";
			echo '<h1>ACPS updater console</h1>' . "\n";
			if ( '' !== $notice ) {
				echo '<p><strong>' . ( $is_error ? 'ERROR: ' : '' ) . esc_html( $notice ) . '</strong></p>' . "\n";
			}

			if ( $has_pw && ! $authed ) {
				echo '<form method="post" action="' . esc_url( $self ) . '">' . "\n";
				echo 'Password: <input type="password" name="pw" autocomplete="off"> <button type="submit">Unlock</button>' . "\n";
				echo '</form>' . "\n</body></html>";
				exit;
			}
			if ( ! $has_pw ) {
				echo '<p><em>No console password set. Set one in wp-admin &rarr; the &amp;updates=1 panel.</em></p>' . "\n";
			}

			if ( is_array( $result ) ) {
				echo '<h2>Result: ' . esc_html( strtoupper( (string) $result['status'] ) ) . '</h2>' . "\n";
				echo '<p>Installed ' . esc_html( $result['installed'] ) . ' &middot; Latest ' . esc_html( '' !== $result['latest'] ? $result['latest'] : '(unknown)' ) . '</p>' . "\n";
				if ( '' !== $result['messages'] ) {
					echo '<pre>' . esc_html( $result['messages'] ) . '</pre>' . "\n";
				}
			}

			if ( ! empty( $gate['links'] ) ) {
				echo '<h2>Links</h2>' . "\n<ul>";
				foreach ( $gate['links'] as $lnk ) {
					if ( ! is_array( $lnk ) || empty( $lnk['url'] ) ) {
						continue;
					}
					echo '<li><a href="' . esc_url( $lnk['url'] ) . '">' . esc_html( isset( $lnk['label'] ) ? $lnk['label'] : $lnk['url'] ) . '</a></li>';
				}
				echo "</ul>\n";
			}

			echo '<h2>Status</h2>' . "\n";
			$this->render_diag_text( $diag );

			$pwf = $has_pw ? ( '<input type="hidden" name="pw" value="' . esc_attr( $supplied ) . '">' ) : '';

			echo '<h2>Update / repair</h2>' . "\n";
			echo '<p>Update request URL: ' . ( '' !== $req ? '<code>' . esc_html( $req ) . '</code>' : '<em>manifest not set</em>' ) . '</p>' . "\n";
			echo '<form method="post" action="' . esc_url( $self ) . '">' . $pwf;
			echo '<button type="submit" name="do" value="check">Check latest</button> ';
			echo '<button type="submit" name="do" value="install">Install if newer</button> ';
			echo '<button type="submit" name="do" value="reinstall">Reupload / reinstall latest (repair)</button>';
			echo "</form>\n";

			echo '<h2>Settings (everything)</h2>' . "\n";
			$this->render_settings_form( $self, $pwf );

			echo '<h2>Update source</h2>' . "\n";
			$this->render_source_form( $self, $pwf, $s );
			echo '<h2>Console access &amp; links</h2>' . "\n";
			$this->render_gate_form( $self, $pwf, $gate, $ip );

			echo "</body></html>";
			exit;
		}

		protected function render_raw_status( $result, $notice ) {
			nocache_headers();
			header( 'Content-Type: text/plain; charset=utf-8' );
			$diag = CAYDENDIR_sd_updater_diagnostics();
			echo "ACPS updater status\n";
			if ( '' !== $notice ) {
				echo 'notice=' . $notice . "\n";
			}
			if ( is_array( $result ) ) {
				echo 'result=' . $result['status'] . "\n";
				echo 'installed=' . $result['installed'] . "\n";
				echo 'latest=' . ( '' !== $result['latest'] ? $result['latest'] : 'unknown' ) . "\n";
			}
			foreach ( $diag['metrics'] as $k => $v ) {
				echo str_replace( ' ', '_', strtolower( $k ) ) . '=' . $v . "\n";
			}
			foreach ( $diag['problems'] as $p ) {
				echo 'problem=' . $p . "\n";
			}
			exit;
		}

		protected function render_diag_text( $diag ) {
			echo '<pre>';
			foreach ( $diag['metrics'] as $k => $v ) {
				echo esc_html( $k ) . ': ' . esc_html( (string) $v ) . "\n";
			}
			echo '</pre>';
			if ( ! empty( $diag['problems'] ) ) {
				echo '<p><strong>Problems</strong></p><ul>';
				foreach ( $diag['problems'] as $p ) {
					echo '<li>' . esc_html( (string) $p ) . '</li>';
				}
				echo '</ul>';
			}
		}

		/** A form with one field per setting (scalars as inputs, arrays as JSON). */
		protected function render_settings_form( $self, $pwf ) {
			if ( ! function_exists( 'CAYDENDIR_sd_get_settings' ) ) {
				echo '<p><em>Settings are unavailable in safe mode. Reinstall to recover, then edit here.</em></p>';
				return;
			}
			$s = CAYDENDIR_sd_get_settings();
			echo '<form method="post" action="' . esc_url( $self ) . '">' . $pwf;
			echo '<input type="hidden" name="do" value="savesettings">';
			echo '<table>';
			foreach ( $s as $k => $v ) {
				echo '<tr><td valign="top"><label for="set_' . esc_attr( $k ) . '">' . esc_html( $k ) . '</label></td><td>';
				if ( is_array( $v ) ) {
					$json = wp_json_encode( $v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
					echo '<textarea id="set_' . esc_attr( $k ) . '" name="setjson[' . esc_attr( $k ) . ']" rows="4" cols="70" spellcheck="false">' . esc_textarea( (string) $json ) . '</textarea>';
				} else {
					$str = is_bool( $v ) ? ( $v ? '1' : '0' ) : (string) $v;
					if ( strlen( $str ) > 80 || false !== strpos( $str, "\n" ) ) {
						echo '<textarea id="set_' . esc_attr( $k ) . '" name="set[' . esc_attr( $k ) . ']" rows="4" cols="70" spellcheck="false">' . esc_textarea( $str ) . '</textarea>';
					} else {
						echo '<input id="set_' . esc_attr( $k ) . '" type="text" name="set[' . esc_attr( $k ) . ']" value="' . esc_attr( $str ) . '" size="60">';
					}
				}
				echo '</td></tr>';
			}
			echo '</table><p><button type="submit">Save all settings</button></p></form>';
		}

		/** Rebuild settings from the console form and save. Full parity with wp-admin. */
		protected function console_save_settings() {
			if ( ! function_exists( 'CAYDENDIR_sd_get_settings' ) || ! function_exists( 'CAYDENDIR_sd_sanitize_settings_run' ) ) {
				return array( 'ok' => false, 'message' => 'Settings editing is unavailable in safe mode.' );
			}
			try {
				$out = CAYDENDIR_sd_get_settings();
				if ( isset( $_POST['set'] ) && is_array( $_POST['set'] ) ) { // phpcs:ignore WordPress.Security
					foreach ( wp_unslash( $_POST['set'] ) as $k => $v ) {    // phpcs:ignore WordPress.Security
						$out[ (string) $k ] = is_string( $v ) ? $v : '';
					}
				}
				if ( isset( $_POST['setjson'] ) && is_array( $_POST['setjson'] ) ) { // phpcs:ignore WordPress.Security
					foreach ( wp_unslash( $_POST['setjson'] ) as $k => $v ) {          // phpcs:ignore WordPress.Security
						$trim = trim( (string) $v );
						$dec  = json_decode( (string) $v, true );
						if ( null === $dec && '' !== $trim && 'null' !== $trim ) {
							return array( 'ok' => false, 'message' => 'Invalid JSON in field "' . $k . '" — nothing saved.' );
						}
						$out[ (string) $k ] = $dec;
					}
				}
				$clean = CAYDENDIR_sd_sanitize_settings_run( $out );
				update_option( CAYDENDIR_SD_SETTINGS, $clean );
				if ( function_exists( 'CAYDENDIR_sd_purge_caches' ) ) {
					CAYDENDIR_sd_purge_caches();
				}
				return array( 'ok' => true, 'message' => 'All settings saved.' );
			} catch ( \Throwable $e ) {
				CAYDENDIR_sd_log( 'console save settings', $e );
				return array( 'ok' => false, 'message' => 'Save failed: ' . $e->getMessage() );
			}
		}

		protected function render_source_form( $self, $pwf, $s ) {
			echo '<form method="post" action="' . esc_url( $self ) . '">' . $pwf;
			echo '<input type="hidden" name="do" value="savesource">';
			echo '<p>Source: ';
			echo '<label><input type="radio" name="update_source" value="url" ' . ( 'github' !== $s['update_source'] ? 'checked' : '' ) . '> Manifest URL</label> ';
			echo '<label><input type="radio" name="update_source" value="github" ' . ( 'github' === $s['update_source'] ? 'checked' : '' ) . '> GitHub</label></p>';
			echo '<p>Manifest URL: <input type="text" size="70" name="update_manifest" value="' . esc_attr( $s['update_manifest'] ) . '"></p>';
			echo '<p>Set key: <input type="text" size="40" name="update_manifest_key" value="' . esc_attr( $s['update_manifest_key'] ) . '"></p>';
			echo '<p>GitHub owner: <input type="text" name="gh_owner" value="' . esc_attr( $s['gh_owner'] ) . '"> repo: <input type="text" name="gh_repo" value="' . esc_attr( $s['gh_repo'] ) . '"></p>';
			echo '<p>Asset: <input type="text" name="gh_asset" value="' . esc_attr( $s['gh_asset'] ) . '"></p>';
			echo '<p>GitHub token: <input type="text" size="50" name="gh_token" value="' . esc_attr( $s['gh_token'] ) . '"></p>';
			echo '<p><button type="submit">Save update source</button></p></form>';
		}

		protected function render_gate_form( $self, $pwf, $gate, $ip ) {
			$links_text = '';
			foreach ( (array) $gate['links'] as $lnk ) {
				if ( is_array( $lnk ) && ! empty( $lnk['url'] ) ) {
					$links_text .= ( isset( $lnk['label'] ) ? $lnk['label'] : '' ) . ' | ' . $lnk['url'] . "\n";
				}
			}
			echo '<form method="post" action="' . esc_url( $self ) . '">' . $pwf;
			echo '<input type="hidden" name="do" value="savegate">';
			echo '<p>Console query-var name (change this if a firewall blocks "acpsupdater"): <input type="text" size="24" name="console_param" value="' . esc_attr( CAYDENDIR_sd_console_param( $gate ) ) . '"> &rarr; URL becomes <code>/?' . esc_html( CAYDENDIR_sd_console_param( $gate ) ) . '=&lt;key&gt;</code></p>';
			echo '<p>Console key (the value after the query-var above): <input type="text" size="40" name="console_key" value="' . esc_attr( $gate['console_key'] ) . '"></p>';
			echo '<p>Set/replace password: <input type="password" name="console_pw" autocomplete="new-password"> <label><input type="checkbox" name="console_pw_clear" value="1"> clear</label></p>';
			echo '<p>IP mode: <label><input type="radio" name="ip_enforce" value="1" ' . ( ! empty( $gate['ip_enforce'] ) ? 'checked' : '' ) . '> allow only listed</label> ';
			echo '<label><input type="radio" name="ip_enforce" value="0" ' . ( empty( $gate['ip_enforce'] ) ? 'checked' : '' ) . '> allow all except blocked</label></p>';
			echo '<p>Allowed IPs (exact, prefix like 168.1, or CIDR; one per line):<br><textarea name="ip_allow" rows="4" cols="40">' . esc_textarea( implode( "\n", (array) $gate['ip_allow'] ) ) . '</textarea></p>';
			echo '<p>Blocked IPs (one per line):<br><textarea name="ip_block" rows="3" cols="40">' . esc_textarea( implode( "\n", (array) $gate['ip_block'] ) ) . '</textarea></p>';
			echo '<p><label><input type="checkbox" name="trust_proxy" value="1" ' . ( ! empty( $gate['trust_proxy'] ) ? 'checked' : '' ) . '> behind a proxy (use X-Forwarded-For)</label> &mdash; your IP now: <code>' . esc_html( $ip ) . '</code></p>';
			echo '<p><label><input type="checkbox" name="auto_recover" value="1" ' . ( ! empty( $gate['auto_recover'] ) ? 'checked' : '' ) . '> auto-reinstall the latest version if the plugin crashes</label></p>';
			echo '<p>Custom links ("Label | https://url", one per line):<br><textarea name="links" rows="4" cols="60">' . esc_textarea( $links_text ) . '</textarea></p>';
			echo '<p><button type="submit">Save console access &amp; links</button></p></form>';
		}

		/* =====================================================================
		 * Admin panel — wp-admin settings page + "&updates=1"
		 * ================================================================== */

		public function maybe_handle_admin_panel() {
			try {
				if ( ! is_admin() || ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
					return;
				}
				// NOTE: don't use sanitize_key() here — it lowercases, but the menu
				// slug is mixed-case ("CAYDENDIR-staff-directory"), so the compare
				// would never match. Keep case and compare case-insensitively.
				$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security
				$flag = isset( $_GET['updates'] ) ? (string) wp_unslash( $_GET['updates'] ) : '';         // phpcs:ignore WordPress.Security
				if ( 0 !== strcasecmp( 'CAYDENDIR-staff-directory', $page ) || '1' !== $flag ) {
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
			$result   = null;
			$self     = admin_url( 'options-general.php?page=CAYDENDIR-staff-directory&updates=1' );

			if ( 'POST' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : '' ) ) {
				$nonce_ok = isset( $_POST['cayden_u_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cayden_u_nonce'] ) ), 'cayden_sd_updates_panel' );
				$do       = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : ''; // phpcs:ignore WordPress.Security
				if ( ! $nonce_ok ) {
					$notice   = 'Security check failed — try again.';
					$is_error = true;
				} elseif ( 'savesource' === $do ) {
					$this->save_source_from_post();
					$notice = 'Update source saved.';
				} elseif ( 'savegate' === $do ) {
					$notice = $this->save_gate_from_post();
				} elseif ( 'rotate' === $do ) {
					CAYDENDIR_sd_rotate_updater_key();
					$notice = 'Shared key rotated.';
				} elseif ( 'check' === $do ) {
					delete_transient( CAYDENDIR_SD_UPDATE_CACHE );
					$r      = $this->remote( true );
					$notice = is_array( $r ) ? ( 'Latest available: ' . $r['version'] ) : 'Lookup failed.';
				} elseif ( 'install' === $do ) {
					$result = $this->perform_install( false );
					$notice = 'Update run — see result below.';
				} elseif ( 'reinstall' === $do ) {
					$result = $this->perform_install( true );
					$notice = 'Reinstall/repair run — see result below.';
				}
			}

			$s     = $this->settings();
			$gate  = CAYDENDIR_sd_updater_gate();
			$nonce = wp_create_nonce( 'cayden_sd_updates_panel' );
			$req   = $this->update_request_url( $s );
			$curl  = $this->console_url( $gate );
			$diag  = CAYDENDIR_sd_updater_diagnostics();
			$pwf   = '<input type="hidden" name="cayden_u_nonce" value="' . esc_attr( $nonce ) . '">';
			$ip    = CAYDENDIR_sd_client_ip( $gate );

			nocache_headers();
			header( 'Content-Type: text/html; charset=utf-8' );
			echo "<!doctype html>\n<html><head><meta charset=\"utf-8\"><title>Staff Directory — Updates</title></head>\n<body>\n";
			echo '<h1>Staff Directory — Updates (hidden panel)</h1>' . "\n";
			echo '<p>No menu links here. Reach it only via <code>?page=CAYDENDIR-staff-directory&amp;updates=1</code>.</p>' . "\n";
			if ( '' !== $notice ) {
				echo '<p><strong>' . ( $is_error ? 'ERROR: ' : '' ) . esc_html( $notice ) . '</strong></p>' . "\n";
			}
			echo '<pre>';
			echo 'Installed: ' . esc_html( CAYDENDIR_SD_VERSION ) . "\n";
			echo 'Source: ' . esc_html( 'github' === $s['update_source'] ? 'GitHub Releases' : 'Manifest URL' ) . "\n";
			echo 'Update request URL: ' . esc_html( '' !== $req ? $req : '(manifest not set)' ) . "\n";
			echo 'Remote console URL: ' . esc_html( $curl ) . "\n";
			echo '</pre>';

			// Make the #1 gotcha obvious: is THIS browser's IP allowed to open the
			// console? If not, the console URL returns nothing (looks like a 404).
			$ip_ok = CAYDENDIR_sd_ip_allowed( $ip, $gate );
			$has_c = ( '' !== (string) $gate['pw_hash'] );
			echo '<p><strong>Your IP:</strong> <code>' . esc_html( '' !== $ip ? $ip : 'unknown' ) . '</code> — ';
			echo $ip_ok
				? '<strong style="color:green">ALLOWED</strong> to open the console.'
				: '<strong style="color:#b32d2e">NOT allowed</strong> — the console URL will look like a 404 from here until you add this IP to the allow list below.';
			echo '</p>';
			if ( ! $has_c ) {
				echo '<p style="color:#b32d2e"><strong>No console password is set yet — set one below before using the remote console.</strong></p>';
			}

			if ( is_array( $result ) ) {
				echo '<h2>Result: ' . esc_html( strtoupper( (string) $result['status'] ) ) . '</h2>';
				echo '<p>Installed ' . esc_html( $result['installed'] ) . ' &middot; Latest ' . esc_html( '' !== $result['latest'] ? $result['latest'] : '(unknown)' ) . '</p>';
				if ( '' !== $result['messages'] ) {
					echo '<pre>' . esc_html( $result['messages'] ) . '</pre>';
				}
			}

			echo '<h2>Actions</h2><form method="post" action="' . esc_url( $self ) . '">' . $pwf;
			echo '<button type="submit" name="do" value="check">Check</button> ';
			echo '<button type="submit" name="do" value="install">Install if newer</button> ';
			echo '<button type="submit" name="do" value="reinstall">Reupload / reinstall latest</button> ';
			echo '<button type="submit" name="do" value="rotate">Rotate shared key</button>';
			echo '</form>';

			echo '<h2>Update source</h2>';
			$this->render_source_form( $self, $pwf, $s );
			echo '<h2>Console access &amp; links</h2>';
			$this->render_gate_form( $self, $pwf, $gate, $ip );

			echo '<h2>Status</h2>';
			$this->render_diag_text( $diag );
			echo "</body></html>";
			exit;
		}

		protected function save_source_from_post() {
			$s = $this->settings();
			$g = function ( $k ) {
				return isset( $_POST[ $k ] ) ? (string) wp_unslash( $_POST[ $k ] ) : ''; // phpcs:ignore WordPress.Security
			};
			$s['update_source']       = ( 'github' === $g( 'update_source' ) ) ? 'github' : 'url';
			$s['update_manifest']     = esc_url_raw( trim( $g( 'update_manifest' ) ) );
			$s['update_manifest_key'] = sanitize_text_field( $g( 'update_manifest_key' ) );
			$s['gh_owner']            = sanitize_text_field( trim( $g( 'gh_owner' ) ) );
			$s['gh_repo']             = sanitize_text_field( trim( $g( 'gh_repo' ) ) );
			$asset                    = sanitize_text_field( trim( $g( 'gh_asset' ) ) );
			$s['gh_asset']            = '' !== $asset ? $asset : 'cayden-staff-directory.zip';
			$s['gh_token']            = sanitize_text_field( trim( $g( 'gh_token' ) ) );
			$s['update_enabled']      = '1';
			update_option( CAYDENDIR_SD_SETTINGS, $s );
			delete_transient( CAYDENDIR_SD_UPDATE_CACHE );
		}

		protected function save_gate_from_post() {
			$changes = array();
			$changes['console_key']   = sanitize_text_field( isset( $_POST['console_key'] ) ? (string) wp_unslash( $_POST['console_key'] ) : '' );   // phpcs:ignore WordPress.Security
			$param                    = preg_replace( '/[^A-Za-z0-9_]/', '', isset( $_POST['console_param'] ) ? (string) wp_unslash( $_POST['console_param'] ) : '' ); // phpcs:ignore WordPress.Security
			$changes['console_param'] = '' !== $param ? $param : 'acpsupdater';
			$changes['ip_enforce']  = ( isset( $_POST['ip_enforce'] ) && '0' === (string) $_POST['ip_enforce'] ) ? '0' : '1';                    // phpcs:ignore WordPress.Security
			$changes['trust_proxy'] = empty( $_POST['trust_proxy'] ) ? '0' : '1';                                                                 // phpcs:ignore WordPress.Security
			$changes['auto_recover']= empty( $_POST['auto_recover'] ) ? '0' : '1';                                                                // phpcs:ignore WordPress.Security
			$changes['ip_allow']    = CAYDENDIR_sd_updater_parse_ip_list( isset( $_POST['ip_allow'] ) ? (string) wp_unslash( $_POST['ip_allow'] ) : '' ); // phpcs:ignore WordPress.Security
			$changes['ip_block']    = CAYDENDIR_sd_updater_parse_ip_list( isset( $_POST['ip_block'] ) ? (string) wp_unslash( $_POST['ip_block'] ) : '' ); // phpcs:ignore WordPress.Security
			$changes['links']       = CAYDENDIR_sd_updater_parse_links( isset( $_POST['links'] ) ? (string) wp_unslash( $_POST['links'] ) : '' );  // phpcs:ignore WordPress.Security

			$msg = 'Console access saved.';
			if ( ! empty( $_POST['console_pw_clear'] ) ) { // phpcs:ignore WordPress.Security
				$changes['pw_hash'] = '';
				$msg               .= ' Password cleared.';
			} else {
				$pw = isset( $_POST['console_pw'] ) ? (string) wp_unslash( $_POST['console_pw'] ) : ''; // phpcs:ignore WordPress.Security
				if ( '' !== trim( $pw ) ) {
					$changes['pw_hash'] = function_exists( 'wp_hash_password' ) ? wp_hash_password( $pw ) : hash( 'sha256', $pw );
					$msg               .= ' Password updated.';
				}
			}
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
