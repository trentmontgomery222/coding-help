<?php
/**
 * Remote console — a hidden, front-end (non-admin) control page reached through
 * a secret URL (?acpsupdater=KEY). It mirrors what you can do in wp-admin from
 * OUTSIDE wp-admin: view diagnostics, edit ALL settings, and update / reinstall
 * the plugin remotely.
 *
 * CRITICAL DESIGN RULE: this class depends on NOTHING but WordPress core, the
 * options table, and the main plugin file's own constants + is_safe_mode()
 * helper. It never references Settings, Updater, Failsafe or any other plugin
 * class — because any of those could be the very file that is broken. That is
 * what lets the console still load and reinstall a good copy even when the rest
 * of the plugin is fataling. It reads its config directly with get_option() and
 * performs the reinstall with WordPress' own upgrader.
 *
 * Locked down in depth (no WordPress login needed):
 *   1. IP gate   — allow-list (default: only 167.102.110.1) or deny-list, with
 *                  prefix matching (e.g. 168.1 matches 168.1.*.*). Blocked → 404.
 *   2. URL key   — a secret key in the URL, compared in constant time.
 *   3. Password  — hashed, settable ONLY from wp-admin. Attempts rate-limited.
 *   4. Rate limit— the whole page is throttled per IP.
 *
 * Output is PLAIN (no CSS/JS) so a simple script can drive it. Field names:
 *   - login / inline auth: POST field `pw`
 *   - actions: POST `do` = save | update | reinstall | logout
 *   - settings JSON: POST `json`
 * A script can authenticate and act in ONE request by POSTing pw + do together.
 *
 * @package ACPS\SiteToolkit
 */

namespace ACPS\SiteToolkit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Remote_Console.
 */
class Remote_Console {

	/** Query vars that open the console (first is canonical). */
	const QUERY_VARS = array( 'acpsupdater', 'acps_console' );
	const COOKIE     = 'acps_console_sid';
	const SESS_TTL   = 1800; // 30-minute browser session.

	/** Settings keys never editable through the console (managed only in wp-admin). */
	private static function protected_keys() {
		return array( 'console_pass_hash' );
	}

	/* ------------------------------------------------------------------ *
	 * Self-contained config access (raw options — NOT the Settings class).
	 * ------------------------------------------------------------------ */

	/** The whole stored settings array, straight from the options table. */
	private static function raw() {
		$o = get_option( ACPS_ST_OPT_SETTINGS );
		return is_array( $o ) ? $o : array();
	}

	/** One stored setting, with a fallback. Never touches the Settings class. */
	private static function opt( $key, $default = '' ) {
		$o = self::raw();
		return array_key_exists( $key, $o ) ? $o[ $key ] : $default;
	}

	/* ------------------------------------------------------------------ *
	 * Entry point.
	 * ------------------------------------------------------------------ */

	private static function requested() {
		foreach ( self::QUERY_VARS as $var ) {
			if ( isset( $_GET[ $var ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return array( $var, (string) wp_unslash( $_GET[ $var ] ) ); // phpcs:ignore
			}
		}
		return array( '', '' );
	}

	public static function register() {
		add_action( 'init', array( __CLASS__, 'maybe_handle' ), 0 );
		// The auto-heal cron uses the console's OWN self-contained installer, so
		// recovery never depends on the (possibly broken) Updater/Settings classes.
		add_action( 'acps_st_autoheal', array( __CLASS__, 'auto_reinstall' ) );
	}

	/**
	 * Auto-heal failsafe (cron): if enabled and the plugin is dormant in safe
	 * mode, reinstall the latest version using the console's self-contained
	 * installer, then clear safe mode. Depends on nothing but core + options.
	 */
	public static function auto_reinstall() {
		try {
			if ( ! self::opt( 'console_auto_recover' ) ) {
				return;
			}
			if ( ! ( function_exists( __NAMESPACE__ . '\\is_safe_mode' ) && is_safe_mode() ) ) {
				return; // Only heal when actually broken.
			}
			self::do_update( true );
		} catch ( \Throwable $e ) {
			if ( function_exists( 'error_log' ) ) {
				error_log( '[Cayden Form Manager] console auto_reinstall: ' . $e->getMessage() ); // phpcs:ignore
			}
		}
	}

	public static function maybe_handle() {
		list( $var ) = self::requested();
		if ( '' === $var ) {
			return;
		}
		try {
			self::handle();
		} catch ( \Throwable $e ) {
			self::respond( 500, "Console error.\n" . $e->getMessage() );
		}
	}

	private static function handle() {
		if ( ! self::opt( 'console_enabled' ) ) {
			self::not_found();
		}
		$key = trim( (string) self::opt( 'console_key' ) );
		if ( '' === $key ) {
			self::not_found();
		}
		list( , $given ) = self::requested();

		$ip = self::client_ip();

		if ( ! self::rate_ok( 'page_' . md5( $ip ), 120, 5 * MINUTE_IN_SECONDS ) ) {
			self::respond( 429, 'Too many requests. Slow down and try again shortly.' );
		}
		if ( ! self::ip_allowed( $ip ) ) {
			self::not_found();
		}
		if ( ! hash_equals( $key, $given ) ) {
			self::not_found();
		}

		$hash = (string) self::opt( 'console_pass_hash' );
		if ( '' === $hash ) {
			self::page( "The remote console has no password set yet.\nSet one in wp-admin (Settings -> Forms, then add &updates=1 to the URL) before it can be used." );
		}

		$posted_pw = isset( $_POST['pw'] ) ? (string) wp_unslash( $_POST['pw'] ) : ''; // phpcs:ignore
		$inline    = false;
		$session   = self::current_session( $ip );

		if ( '' !== $posted_pw ) {
			if ( ! self::rate_ok( 'login_' . md5( $ip ), 8, 15 * MINUTE_IN_SECONDS ) ) {
				self::page( 'Too many attempts. Wait 15 minutes and try again.' );
			}
			if ( wp_check_password( $posted_pw, $hash ) ) {
				$inline = true;
				if ( ! $session ) {
					$session = self::start_session( $ip );
				}
			} else {
				self::login_page( 'Incorrect password.' );
			}
		}

		if ( ! $inline && ! $session ) {
			self::login_page( '' );
		}

		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : ''; // phpcs:ignore

		if ( $do && ! $inline ) {
			$csrf = isset( $_POST['csrf'] ) ? (string) wp_unslash( $_POST['csrf'] ) : ''; // phpcs:ignore
			if ( empty( $session['csrf'] ) || ! hash_equals( (string) $session['csrf'], $csrf ) ) {
				self::page( 'Security check failed. Reload and try again.' );
			}
		}

		if ( 'logout' === $do ) {
			self::destroy_session();
			self::redirect_self();
		}

		$msg = '';
		if ( 'save' === $do ) {
			$msg = self::do_save();
		} elseif ( 'update' === $do ) {
			$msg = self::do_update( false );
		} elseif ( 'reinstall' === $do ) {
			$msg = self::do_update( true );
		}

		self::dashboard_page( $session, $msg );
	}

	/* ------------------------------------------------------------------ *
	 * Actions.
	 * ------------------------------------------------------------------ */

	private static function do_save() {
		$raw     = isset( $_POST['json'] ) ? (string) wp_unslash( $_POST['json'] ) : ''; // phpcs:ignore
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return 'ERROR: that is not valid JSON. Nothing was saved.';
		}
		$current = self::raw();
		foreach ( self::protected_keys() as $pk ) {
			unset( $decoded[ $pk ] );
		}
		update_option( ACPS_ST_OPT_SETTINGS, array_merge( $current, $decoded ) );
		// Clear the updater's cached lookup (core transients — no class needed).
		delete_transient( 'acps_st_update_remote' );
		delete_transient( 'acps_st_devstatus' );
		return 'OK: settings saved.';
	}

	/**
	 * Update / reinstall the plugin — completely self-contained (WordPress core
	 * only). Reads the update source straight from the options table so it works
	 * even if the Updater class itself is the broken file.
	 *
	 * @param bool $force Reinstall even if already latest.
	 * @return string
	 */
	private static function do_update( $force ) {
		if ( ! self::rate_ok( 'update', 6, 10 * MINUTE_IN_SECONDS ) ) {
			return 'ERROR: too many update attempts, wait a few minutes.';
		}
		$info = self::fetch_package();
		if ( ! $info || empty( $info['package'] ) ) {
			return 'ERROR: could not reach the configured update source (check the manifest/GitHub settings).';
		}
		$from = ACPS_ST_VERSION;
		$to   = (string) $info['version'];
		if ( ! $force && '' !== $to && version_compare( $to, $from, '<=' ) ) {
			return 'OK: already up to date (' . $from . ').';
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		// Force an offer into the update transient so upgrade() has a package.
		$t = get_site_transient( 'update_plugins' );
		if ( ! is_object( $t ) ) {
			$t = new \stdClass();
		}
		if ( empty( $t->response ) || ! is_array( $t->response ) ) {
			$t->response = array();
		}
		$slug                            = dirname( ACPS_ST_BASENAME );
		$t->response[ ACPS_ST_BASENAME ] = (object) array(
			'id'          => $slug,
			'slug'        => $slug,
			'plugin'      => ACPS_ST_BASENAME,
			'new_version' => '' !== $to ? $to : $from,
			'package'     => $info['package'],
			'url'         => '',
		);
		set_site_transient( 'update_plugins', $t );

		// Self-contained install filters (folder rename + private GitHub asset).
		self::add_install_filters( $info );
		$skin     = new \Automatic_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$result   = $upgrader->upgrade( ACPS_ST_BASENAME );
		self::remove_install_filters();

		$ok = ( ! is_wp_error( $result ) && $result );
		if ( $ok ) {
			// A clean install should clear any armed safe mode so the fresh code
			// loads normally next request.
			delete_option( ACPS_ST_SAFE_MODE_OPT );
			delete_option( 'acps_st_update_failed' );
		}
		$note = '';
		if ( is_wp_error( $result ) ) {
			$note = ' ' . $result->get_error_message();
		} else {
			$m    = $skin->get_upgrade_messages();
			$note = $m ? ' ' . implode( ' | ', array_map( 'wp_strip_all_tags', (array) $m ) ) : '';
		}
		return ( $ok ? 'OK: ' : 'ERROR: ' )
			. ( $ok ? ( $force ? 'reinstalled ' . $to . '.' : 'updated ' . $from . ' -> ' . $to . '.' ) : 'install failed.' )
			. $note;
	}

	/**
	 * Resolve the downloadable package from whichever source is configured, read
	 * directly from the options table. Returns [version, package, auth, token] or
	 * null. No plugin classes involved.
	 *
	 * @return array|null
	 */
	private static function fetch_package() {
		$o      = self::raw();
		$source = ! empty( $o['update_source'] ) ? $o['update_source'] : 'url';

		if ( 'github' === $source ) {
			$owner = isset( $o['gh_owner'] ) ? trim( (string) $o['gh_owner'] ) : '';
			$repo  = isset( $o['gh_repo'] ) ? trim( (string) $o['gh_repo'] ) : '';
			if ( '' === $owner || '' === $repo ) {
				return null;
			}
			$asset = isset( $o['gh_asset'] ) ? trim( (string) $o['gh_asset'] ) : '';
			$asset = '' !== $asset ? $asset : 'acps-site-toolkit.zip';
			$token = isset( $o['gh_token'] ) ? trim( (string) $o['gh_token'] ) : '';

			$headers = array( 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'ACPS-Console', 'X-GitHub-Api-Version' => '2022-11-28' );
			if ( '' !== $token ) {
				$headers['Authorization'] = 'Bearer ' . $token;
			}
			$resp = wp_remote_get( sprintf( 'https://api.github.com/repos/%s/%s/releases/latest', rawurlencode( $owner ), rawurlencode( $repo ) ), array( 'timeout' => 20, 'headers' => $headers ) );
			if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
				return null;
			}
			$rel = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
			if ( ! is_array( $rel ) || empty( $rel['tag_name'] ) ) {
				return null;
			}
			$package = '';
			$auth    = false;
			foreach ( (array) ( isset( $rel['assets'] ) ? $rel['assets'] : array() ) as $a ) {
				if ( ! isset( $a['name'] ) || $a['name'] !== $asset ) {
					continue;
				}
				if ( '' !== $token && ! empty( $a['url'] ) ) {
					$package = (string) $a['url'];
					$auth    = true;
				} elseif ( ! empty( $a['browser_download_url'] ) ) {
					$package = (string) $a['browser_download_url'];
				}
				break;
			}
			if ( '' === $package ) {
				return null;
			}
			return array( 'version' => ltrim( (string) $rel['tag_name'], 'vV' ), 'package' => $package, 'auth' => $auth, 'token' => $token );
		}

		// 'url' manifest source.
		$manifest = isset( $o['update_manifest'] ) ? trim( (string) $o['update_manifest'] ) : '';
		if ( '' === $manifest ) {
			return null;
		}
		$args = array( 'site' => home_url( '/' ) );
		$mkey = isset( $o['update_manifest_key'] ) ? trim( (string) $o['update_manifest_key'] ) : '';
		if ( '' !== $mkey ) {
			$args['key'] = $mkey;
		}
		$resp = wp_remote_get( add_query_arg( array_map( 'rawurlencode', $args ), $manifest ), array( 'timeout' => 20 ) );
		if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
			return null;
		}
		$b = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $b ) || empty( $b['version'] ) || empty( $b['download_url'] ) ) {
			return null;
		}
		return array( 'version' => ltrim( (string) $b['version'], 'vV' ), 'package' => esc_url_raw( (string) $b['download_url'] ), 'auth' => false, 'token' => '' );
	}

	/** Temporary install filters used only during our own upgrade. */
	private static $filter_folder = null;
	private static $filter_dl     = null;

	private static function add_install_filters( $info ) {
		$slug = dirname( ACPS_ST_BASENAME );
		self::$filter_folder = function ( $source, $remote_source, $upgrader, $args = array() ) use ( $slug ) {
			$plugin = isset( $args['plugin'] ) ? $args['plugin'] : '';
			if ( ACPS_ST_BASENAME !== $plugin ) {
				return $source;
			}
			$desired = trailingslashit( $remote_source ) . $slug;
			$source  = untrailingslashit( $source );
			if ( untrailingslashit( $desired ) === $source ) {
				return trailingslashit( $source );
			}
			global $wp_filesystem;
			if ( $wp_filesystem && $wp_filesystem->move( $source, untrailingslashit( $desired ), true ) ) {
				return trailingslashit( $desired );
			}
			return trailingslashit( $source );
		};
		add_filter( 'upgrader_source_selection', self::$filter_folder, 10, 4 );

		if ( ! empty( $info['auth'] ) && ! empty( $info['token'] ) ) {
			$token = $info['token'];
			self::$filter_dl = function ( $reply, $package, $upgrader ) use ( $token ) {
				if ( false !== $reply || ! is_string( $package ) || false === strpos( $package, 'api.github.com' ) || false === strpos( $package, '/releases/assets/' ) ) {
					return $reply;
				}
				$resp = wp_remote_get( $package, array( 'timeout' => 30, 'redirection' => 0, 'headers' => array( 'Accept' => 'application/octet-stream', 'Authorization' => 'Bearer ' . $token, 'User-Agent' => 'ACPS-Console' ) ) );
				$loc  = is_wp_error( $resp ) ? '' : wp_remote_retrieve_header( $resp, 'location' );
				if ( ! $loc ) {
					return $reply;
				}
				if ( ! function_exists( 'download_url' ) ) {
					require_once ABSPATH . 'wp-admin/includes/file.php';
				}
				$tmp = download_url( $loc );
				return is_wp_error( $tmp ) ? $reply : $tmp;
			};
			add_filter( 'upgrader_pre_download', self::$filter_dl, 10, 3 );
		}
	}

	private static function remove_install_filters() {
		if ( self::$filter_folder ) {
			remove_filter( 'upgrader_source_selection', self::$filter_folder, 10 );
			self::$filter_folder = null;
		}
		if ( self::$filter_dl ) {
			remove_filter( 'upgrader_pre_download', self::$filter_dl, 10 );
			self::$filter_dl = null;
		}
	}

	/* ------------------------------------------------------------------ *
	 * IP gate.
	 * ------------------------------------------------------------------ */

	public static function client_ip() {
		$ip = '';
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$parts = explode( ',', (string) wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ); // phpcs:ignore
			$ip    = trim( $parts[0] );
		} elseif ( ! empty( $_SERVER['HTTP_X_REAL_IP'] ) ) {
			$ip = trim( (string) wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) ); // phpcs:ignore
		} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = trim( (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) ); // phpcs:ignore
		}
		$ip = preg_replace( '/[^0-9a-f:\.]/i', '', $ip );
		return (string) apply_filters( 'acps_st_console_client_ip', $ip );
	}

	public static function ip_allowed( $ip ) {
		$mode = 'deny' === self::opt( 'console_ip_mode', 'allow' ) ? 'deny' : 'allow';
		$list = preg_split( '/[\s,]+/', (string) self::opt( 'console_ips', '' ) );
		$list = array_filter( array_map( 'trim', (array) $list ) );

		$match = false;
		foreach ( $list as $pattern ) {
			if ( self::ip_matches( $ip, $pattern ) ) {
				$match = true;
				break;
			}
		}
		return ( 'allow' === $mode ) ? $match : ! $match;
	}

	private static function ip_matches( $ip, $pattern ) {
		if ( '' === $ip || '' === $pattern ) {
			return false;
		}
		$pattern = rtrim( $pattern, '*' );
		if ( '' === $pattern ) {
			return false;
		}
		if ( $ip === $pattern ) {
			return true;
		}
		$prefix = rtrim( $pattern, '.' ) . '.';
		return 0 === strpos( $ip, $prefix );
	}

	/* ------------------------------------------------------------------ *
	 * Sessions.
	 * ------------------------------------------------------------------ */

	private static function sess_transient( $token ) {
		return 'acps_console_sess_' . $token;
	}

	private static function current_session( $ip ) {
		$token = isset( $_COOKIE[ self::COOKIE ] ) ? preg_replace( '/[^a-f0-9]/i', '', (string) $_COOKIE[ self::COOKIE ] ) : '';
		if ( '' === $token ) {
			return null;
		}
		$data = get_transient( self::sess_transient( $token ) );
		if ( ! is_array( $data ) || empty( $data['ip'] ) || ! hash_equals( (string) $data['ip'], (string) $ip ) ) {
			return null;
		}
		$data['token'] = $token;
		return $data;
	}

	private static function start_session( $ip ) {
		$token = bin2hex( random_bytes( 16 ) );
		$data  = array( 'ip' => $ip, 'csrf' => bin2hex( random_bytes( 16 ) ), 'started' => time(), 'token' => $token );
		set_transient( self::sess_transient( $token ), $data, self::SESS_TTL );
		if ( ! headers_sent() ) {
			setcookie( self::COOKIE, $token, array( 'expires' => time() + self::SESS_TTL, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Strict' ) );
		}
		$_COOKIE[ self::COOKIE ] = $token;
		return $data;
	}

	private static function destroy_session() {
		$token = isset( $_COOKIE[ self::COOKIE ] ) ? preg_replace( '/[^a-f0-9]/i', '', (string) $_COOKIE[ self::COOKIE ] ) : '';
		if ( '' !== $token ) {
			delete_transient( self::sess_transient( $token ) );
		}
		if ( ! headers_sent() ) {
			setcookie( self::COOKIE, '', array( 'expires' => time() - 3600, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Strict' ) );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Rate limiting.
	 * ------------------------------------------------------------------ */

	private static function rate_ok( $bucket, $max, $window ) {
		$k = 'acps_console_rl_' . $bucket;
		$n = (int) get_transient( $k );
		if ( $n >= $max ) {
			return false;
		}
		set_transient( $k, $n + 1, $window );
		return true;
	}

	/* ------------------------------------------------------------------ *
	 * Diagnostics — self-contained (core + options + is_safe_mode only).
	 * ------------------------------------------------------------------ */

	private static function diagnostics() {
		$safe      = ( function_exists( __NAMESPACE__ . '\\is_safe_mode' ) && is_safe_mode() );
		$safe_info = get_option( ACPS_ST_SAFE_MODE_OPT );
		$o         = self::raw();

		$perf = array(
			'Plugin version' => defined( 'ACPS_ST_VERSION' ) ? ACPS_ST_VERSION : '(unknown)',
			'PHP version'    => PHP_VERSION,
			'WordPress'      => get_bloginfo( 'version' ),
			'Memory limit'   => (string) ini_get( 'memory_limit' ),
			'Peak memory'    => size_format( memory_get_peak_usage( true ) ),
			'Server time'    => current_time( 'mysql' ),
			'Safe mode'      => $safe ? 'YES (plugin dormant)' : 'no',
		);

		$problems = array();
		if ( $safe ) {
			$problems[] = 'SAFE MODE: a fatal was caught; plugin dormant.'
				. ( is_array( $safe_info ) && ! empty( $safe_info['msg'] ) ? ' ' . $safe_info['msg'] . ( ! empty( $safe_info['file'] ) ? ' (' . $safe_info['file'] . ':' . (int) $safe_info['line'] . ')' : '' ) : '' );
		}
		$failed = get_option( 'acps_st_update_failed' );
		if ( is_array( $failed ) ) {
			$problems[] = 'A recent update failed its load test and was rolled back (' . ( isset( $failed['when'] ) ? $failed['when'] : '' ) . ').';
		}
		// Inline integrity check for a few core files (no Failsafe class needed).
		$must = array( 'includes/class-plugin.php', 'includes/class-settings.php', 'includes/class-rest-controller.php', 'includes/class-form.php', 'includes/admin/class-admin.php' );
		$miss = array();
		foreach ( $must as $rel ) {
			if ( defined( 'ACPS_ST_PATH' ) && ! is_readable( ACPS_ST_PATH . $rel ) ) {
				$miss[] = $rel;
			}
		}
		if ( $miss ) {
			$problems[] = 'Missing/unreadable core files: ' . implode( ', ', $miss );
		}

		$update = array(
			'Update source' => isset( $o['update_source'] ) ? (string) $o['update_source'] : 'url',
			'Manifest URL'  => isset( $o['update_manifest'] ) ? (string) $o['update_manifest'] : '',
			'GitHub repo'   => ( isset( $o['gh_owner'] ) ? (string) $o['gh_owner'] : '' ) . '/' . ( isset( $o['gh_repo'] ) ? (string) $o['gh_repo'] : '' ),
		);

		return array( 'safe_mode' => $safe, 'perf' => $perf, 'update' => $update, 'problems' => $problems );
	}

	/* ------------------------------------------------------------------ *
	 * URL + HTTP helpers.
	 * ------------------------------------------------------------------ */

	public static function console_url() {
		if ( ! self::opt( 'console_enabled' ) ) {
			return '';
		}
		$key = trim( (string) self::opt( 'console_key' ) );
		if ( '' === $key ) {
			return '';
		}
		return add_query_arg( self::QUERY_VARS[0], $key, home_url( '/' ) );
	}

	private static function redirect_self() {
		$key = trim( (string) self::opt( 'console_key' ) );
		wp_safe_redirect( add_query_arg( self::QUERY_VARS[0], $key, home_url( '/' ) ) );
		exit;
	}

	private static function not_found() {
		self::respond( 404, '' );
	}

	private static function respond( $code, $text ) {
		if ( ! headers_sent() ) {
			status_header( $code );
			nocache_headers();
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'X-Robots-Tag: noindex, nofollow' );
		}
		if ( '' !== $text ) {
			echo esc_html( $text );
		}
		exit;
	}

	/* ------------------------------------------------------------------ *
	 * Rendering — plain, unstyled HTML.
	 * ------------------------------------------------------------------ */

	private static function page_shell( $body ) {
		if ( ! headers_sent() ) {
			status_header( 200 );
			nocache_headers();
			header( 'Content-Type: text/html; charset=utf-8' );
			header( 'X-Robots-Tag: noindex, nofollow' );
			header( 'Referrer-Policy: no-referrer' );
		}
		echo '<!doctype html><html><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow"><title>ACPS Updater Console</title></head><body>'
			. $body
			. '</body></html>';
		exit;
	}

	private static function page( $text ) {
		self::page_shell( '<h1>ACPS Updater Console</h1><pre>' . esc_html( $text ) . '</pre>' );
	}

	private static function login_page( $error ) {
		$b = '<h1>ACPS Updater Console</h1>';
		if ( '' !== $error ) {
			$b .= '<p><strong>' . esc_html( $error ) . '</strong></p>';
		}
		$b .= '<form method="post" action="">'
			. '<p><label>Password: <input type="password" name="pw" autofocus></label></p>'
			. '<p><button type="submit">Sign in</button></p>'
			. '</form>';
		self::page_shell( $b );
	}

	private static function dashboard_page( $session, $msg ) {
		$d = self::diagnostics();
		$b = '<h1>ACPS Updater Console</h1>';

		if ( '' !== $msg ) {
			$b .= '<p><strong>' . esc_html( $msg ) . '</strong></p><hr>';
		}

		$links = self::custom_links();
		if ( $links ) {
			$b .= '<h2>Links</h2><ul>';
			foreach ( $links as $l ) {
				$b .= '<li><a href="' . esc_url( $l['url'] ) . '">' . esc_html( $l['label'] ) . '</a></li>';
			}
			$b .= '</ul>';
		}

		$b .= '<h2>Issues &amp; problems</h2>';
		if ( empty( $d['problems'] ) ) {
			$b .= '<p>None detected.</p>';
		} else {
			$b .= '<ul>';
			foreach ( $d['problems'] as $p ) {
				$b .= '<li>' . esc_html( $p ) . '</li>';
			}
			$b .= '</ul>';
		}

		$b .= '<h2>Status</h2><table border="1" cellpadding="4"><tbody>';
		foreach ( $d['perf'] as $k => $v ) {
			$b .= '<tr><td>' . esc_html( $k ) . '</td><td>' . esc_html( (string) $v ) . '</td></tr>';
		}
		foreach ( $d['update'] as $k => $v ) {
			$b .= '<tr><td>' . esc_html( $k ) . '</td><td>' . esc_html( (string) $v ) . '</td></tr>';
		}
		$b .= '</tbody></table>';

		$csrf   = ( is_array( $session ) && ! empty( $session['csrf'] ) ) ? $session['csrf'] : '';
		$hidden = '<input type="hidden" name="csrf" value="' . esc_attr( $csrf ) . '">';

		$b .= '<h2>Update</h2>';
		$b .= '<form method="post" action="" style="display:inline">' . $hidden
			. '<input type="hidden" name="do" value="update"><button type="submit">Update to latest</button></form> ';
		$b .= '<form method="post" action="" style="display:inline">' . $hidden
			. '<input type="hidden" name="do" value="reinstall"><button type="submit" onclick="return confirm(\'Re-download and reinstall the latest version now?\')">Reinstall latest (repair)</button></form>';
		$b .= '<p>Reinstall re-downloads and re-applies the latest package even if the version is unchanged — use it if a file was edited wrong.</p>';

		$settings = self::raw();
		foreach ( self::protected_keys() as $pk ) {
			if ( isset( $settings[ $pk ] ) ) {
				$settings[ $pk ] = '(managed in wp-admin)';
			}
		}
		$json = wp_json_encode( $settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$b   .= '<h2>Edit all settings</h2>'
			. '<form method="post" action="">' . $hidden
			. '<input type="hidden" name="do" value="save">'
			. '<p><textarea name="json" rows="24" cols="100" spellcheck="false">' . esc_textarea( (string) $json ) . '</textarea></p>'
			. '<p><button type="submit">Save settings</button></p></form>';

		$b .= '<hr><form method="post" action="">' . $hidden
			. '<input type="hidden" name="do" value="logout"><button type="submit">Sign out</button></form>';

		self::page_shell( $b );
	}

	private static function custom_links() {
		$raw   = (string) self::opt( 'console_links', '' );
		$out   = array();
		$lines = preg_split( '/\r\n|\r|\n/', $raw );
		foreach ( (array) $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$parts = array_map( 'trim', explode( '|', $line, 2 ) );
			if ( count( $parts ) === 2 && '' !== $parts[1] ) {
				$out[] = array( 'label' => $parts[0], 'url' => esc_url_raw( $parts[1] ) );
			} else {
				$out[] = array( 'label' => $parts[0], 'url' => esc_url_raw( $parts[0] ) );
			}
		}
		return $out;
	}
}
