<?php
/**
 * Update guard — robust self-update that survives WP Engine's "can't overwrite
 * in-use PHP" block, ported from the WPSearch Quick Results recipe.
 *
 * The host allows writing NEW .php files but refuses to overwrite .php files
 * that are currently loaded — from a logged-out / front-end request (which is
 * exactly how the remote console installs). So a normal Plugin_Upgrader run
 * fails with "could not write files". The fix is a STAGED install:
 *   1. Stage now: download + unzip the new version to a staging folder (writing
 *      new files, which the host allows) and record a marker option.
 *   2. Apply early: in the plugin bootstrap, BEFORE our own includes/*.php are
 *      loaded (the pristine window the rollback uses), copy the staged files
 *      over the live ones — at that instant our PHP isn't "in use" yet.
 * Plus: opcache_reset after every self-copy, ensure_active so the plugin is
 * never left disabled, and a backup/rollback so a bad version is undone.
 *
 * SELF-CONTAINED: depends only on WordPress core, the options table, and the
 * main plugin file's constants — never on Settings/Updater/etc., so it still
 * works when one of those is the broken file.
 *
 * @package ACPS\SiteToolkit
 */

namespace ACPS\SiteToolkit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Update_Guard.
 */
class Update_Guard {

	const STAGE_OPT    = 'acps_st_staged_install';
	const ROLLBACK_OPT = 'acps_st_rollback';
	const PENDING_OPT  = 'acps_st_pending_update';
	const POST_OPT     = 'acps_st_post_update';
	const CRON_HOOK    = 'acps_st_apply_pending';

	/* ------------------------------------------------------------------ *
	 * Config (raw options — no Settings class).
	 * ------------------------------------------------------------------ */

	private static function raw() {
		$o = get_option( ACPS_ST_OPT_SETTINGS );
		return is_array( $o ) ? $o : array();
	}
	private static function opt( $key, $default = '' ) {
		$o = self::raw();
		return array_key_exists( $key, $o ) ? $o[ $key ] : $default;
	}

	/**
	 * A unique folder suffix that does NOT depend on pluggable functions
	 * (wp_generate_password lives in pluggable.php, which may not be loaded yet
	 * at plugins_loaded when the early rollback/apply runs).
	 */
	private static function suffix() {
		return substr( md5( uniqid( (string) mt_rand(), true ) ), 0, 12 );
	}

	/* ------------------------------------------------------------------ *
	 * Early-bootstrap hooks (called from the main file, before includes load).
	 * ------------------------------------------------------------------ */

	/**
	 * Restore the pre-update backup if the last staged apply left the plugin in
	 * safe mode (the new version fataled); otherwise disarm a proven-good backup.
	 * Runs at the very top of boot(), before our includes load.
	 */
	public static function maybe_rollback() {
		try {
			$backup = get_option( self::ROLLBACK_OPT );
			if ( ! is_array( $backup ) || empty( $backup['dir'] ) || ! is_dir( $backup['dir'] ) ) {
				if ( false !== $backup ) {
					delete_option( self::ROLLBACK_OPT );
				}
				return;
			}
			$safe = ( function_exists( __NAMESPACE__ . '\\is_safe_mode' ) && is_safe_mode() );
			if ( $safe ) {
				// The freshly-applied version crashed — put the old files back.
				self::copy_tree( $backup['dir'], untrailingslashit( ACPS_ST_PATH ) );
				self::opcache();
				self::remove_tree( $backup['dir'] );
				delete_option( self::ROLLBACK_OPT );
				delete_option( self::POST_OPT );
				delete_option( ACPS_ST_SAFE_MODE_OPT ); // restored code should load
				if ( function_exists( 'error_log' ) ) {
					error_log( '[Cayden Form Manager] Update rolled back — a bad version crashed, previous files restored.' ); // phpcs:ignore
				}
				return;
			}
			// New version booted at least once without crashing → disarm.
			if ( get_option( self::POST_OPT ) ) {
				self::remove_tree( $backup['dir'] );
				delete_option( self::ROLLBACK_OPT );
				delete_option( self::POST_OPT );
			}
		} catch ( \Throwable $e ) { /* never break boot */ }
	}

	/**
	 * Apply a staged install in the pristine early-bootstrap window — this is the
	 * step that beats "can't overwrite in-use PHP". Runs before our includes load.
	 *
	 * @return bool True if a staged install was applied.
	 */
	public static function maybe_apply_staged() {
		try {
			$stage = get_option( self::STAGE_OPT );
			if ( ! is_array( $stage ) || empty( $stage['dir'] ) || ! is_dir( $stage['dir'] ) ) {
				if ( false !== $stage ) {
					delete_option( self::STAGE_OPT );
				}
				return false;
			}

			// Back up the current files first so a bad release can be undone.
			self::arm_rollback();

			$failed   = self::copy_tree( $stage['dir'], untrailingslashit( ACPS_ST_PATH ) );
			$main     = basename( ACPS_ST_FILE );
			$critical = array_values( array_filter( $failed, function ( $f ) use ( $main ) { return $f !== $main; } ) );

			self::remove_tree( ! empty( $stage['root'] ) ? $stage['root'] : $stage['dir'] );
			delete_option( self::STAGE_OPT );

			if ( empty( $critical ) ) {
				self::opcache();
				update_option( self::POST_OPT, time(), false );
				self::ensure_active();
				delete_option( ACPS_ST_SAFE_MODE_OPT ); // fresh code, clear any old pause
				return true;
			}

			// A critical include failed to copy → half-updated. Restore old files
			// so the plugin stays on a consistent (old) version.
			$backup = get_option( self::ROLLBACK_OPT );
			if ( is_array( $backup ) && ! empty( $backup['dir'] ) && is_dir( $backup['dir'] ) ) {
				self::copy_tree( $backup['dir'], untrailingslashit( ACPS_ST_PATH ) );
				self::remove_tree( $backup['dir'] );
			}
			delete_option( self::ROLLBACK_OPT );
			self::opcache();
			if ( function_exists( 'error_log' ) ) {
				error_log( '[Cayden Form Manager] Staged apply failed on: ' . implode( ', ', $critical ) . ' — kept previous version.' ); // phpcs:ignore
			}
			return false;
		} catch ( \Throwable $e ) {
			if ( function_exists( 'error_log' ) ) {
				error_log( '[Cayden Form Manager] maybe_apply_staged error: ' . $e->getMessage() ); // phpcs:ignore
			}
			return false;
		}
	}

	/* ------------------------------------------------------------------ *
	 * Install (direct, then staged fallback).
	 * ------------------------------------------------------------------ */

	/**
	 * Install / reinstall the latest version. Tries a normal (direct) install
	 * first; if the host refuses to overwrite in-use PHP, falls back to staging
	 * the files for the next page load to apply.
	 *
	 * @param bool $force Reinstall even if already the latest version.
	 * @return array{ok:bool,message:string}
	 */
	public static function install( $force = false ) {
		$info = self::resolve_package();
		if ( ! $info || empty( $info['package'] ) ) {
			return array( 'ok' => false, 'message' => 'Could not reach the configured update source.' );
		}
		$from = defined( 'ACPS_ST_VERSION' ) ? ACPS_ST_VERSION : '0';
		$to   = (string) $info['version'];
		if ( ! $force && '' !== $to && version_compare( $to, $from, '<=' ) ) {
			return array( 'ok' => true, 'message' => 'Already up to date (' . $from . ').' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		// 1) Try a normal direct install (works where the host allows it).
		$direct = self::direct_install( $info );
		if ( $direct['ok'] ) {
			self::opcache();
			self::ensure_active();
			delete_option( ACPS_ST_SAFE_MODE_OPT );
			delete_option( 'acps_st_update_failed' );
			return array( 'ok' => true, 'message' => ( $force ? 'Reinstalled ' . $to . '.' : 'Updated ' . $from . ' -> ' . $to . '.' ) );
		}

		// 2) Direct failed (likely the in-use-PHP block) → stage for early apply.
		if ( self::stage( $info['package'], $to ) ) {
			return array( 'ok' => true, 'message' => 'Direct install blocked by the host (in-use PHP). Staged ' . $to . ' — reload any page once to finish applying.' );
		}
		return array( 'ok' => false, 'message' => 'Direct install failed and staging failed. ' . $direct['message'] );
	}

	/**
	 * Normal WordPress upgrader install, forcing the credential-free "direct"
	 * filesystem method (a logged-out request has no page to ask for FTP creds).
	 *
	 * @param array $info Resolved package info.
	 * @return array{ok:bool,message:string}
	 */
	private static function direct_install( $info ) {
		$force_direct = static function () { return 'direct'; };
		add_filter( 'filesystem_method', $force_direct, 99 );

		$t    = get_site_transient( 'update_plugins' );
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
			'new_version' => '' !== $info['version'] ? $info['version'] : ACPS_ST_VERSION,
			'package'     => $info['package'],
			'url'         => '',
		);
		// Make core think the checked version differs, so a same-version force
		// reinstall isn't short-circuited.
		if ( isset( $t->checked[ ACPS_ST_BASENAME ] ) ) {
			unset( $t->checked[ ACPS_ST_BASENAME ] );
		}
		set_site_transient( 'update_plugins', $t );

		self::add_install_filters( $info );
		$skin     = new \Automatic_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$result   = $upgrader->upgrade( ACPS_ST_BASENAME );
		self::remove_install_filters();
		remove_filter( 'filesystem_method', $force_direct, 99 );

		$ok  = ( ! is_wp_error( $result ) && $result );
		$msg = is_wp_error( $result ) ? $result->get_error_message() : '';
		return array( 'ok' => (bool) $ok, 'message' => $msg );
	}

	/* ------------------------------------------------------------------ *
	 * Staging.
	 * ------------------------------------------------------------------ */

	/**
	 * Download + unzip the package to a fresh staging folder (writes NEW files,
	 * which the host allows) and record the marker for the early apply.
	 *
	 * @param string $download_url Package URL.
	 * @param string $version      Version being staged.
	 * @return bool
	 */
	public static function stage( $download_url, $version ) {
		try {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			if ( ! function_exists( 'download_url' ) || ! function_exists( 'unzip_file' ) ) {
				return false;
			}
			// A "direct" filesystem is needed for unzip on a logged-out request.
			$force_direct = static function () { return 'direct'; };
			add_filter( 'filesystem_method', $force_direct, 99 );
			WP_Filesystem();

			$package = download_url( $download_url );
			if ( is_wp_error( $package ) ) {
				remove_filter( 'filesystem_method', $force_direct, 99 );
				return false;
			}
			$base = WP_CONTENT_DIR . '/acps-st-staging-' . self::suffix();
			$unz  = unzip_file( $package, $base );
			@unlink( $package ); // phpcs:ignore
			remove_filter( 'filesystem_method', $force_direct, 99 );
			if ( is_wp_error( $unz ) ) {
				self::remove_tree( $base );
				return false;
			}
			$source = self::locate_source( $base );
			if ( ! $source ) {
				self::remove_tree( $base );
				return false;
			}
			update_option( self::STAGE_OPT, array( 'dir' => $source, 'root' => $base, 'version' => $version, 'time' => time() ), false );
			return true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Find the folder inside an unzipped release that holds the main plugin file.
	 *
	 * @param string $base Unzip root.
	 * @return string|false
	 */
	private static function locate_source( $base ) {
		$main = basename( ACPS_ST_FILE );
		if ( is_readable( $base . '/' . $main ) ) {
			return $base;
		}
		foreach ( (array) glob( $base . '/*', GLOB_ONLYDIR ) as $dir ) {
			if ( is_readable( $dir . '/' . $main ) ) {
				return $dir;
			}
		}
		return false;
	}

	/* ------------------------------------------------------------------ *
	 * Background queue (for hosts whose writable context is cron/admin).
	 * ------------------------------------------------------------------ */

	/** Register the cron + admin-request appliers. Call from boot(). */
	public static function register() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'apply_pending' ) );
		add_action( 'admin_init', array( __CLASS__, 'apply_pending' ) );
	}

	public static function queue_install( $force = false ) {
		update_option( self::PENDING_OPT, array( 'force' => (bool) $force, 'requested' => time(), 'attempts' => 0 ), false );
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 20, self::CRON_HOOK );
		}
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
		return 'Queued — it will apply on the next cron run or admin request.';
	}

	public static function apply_pending() {
		$pending = get_option( self::PENDING_OPT );
		if ( ! is_array( $pending ) ) {
			return;
		}
		// Short transient lock so two contexts don't run at once.
		if ( get_transient( 'acps_st_apply_lock' ) ) {
			return;
		}
		set_transient( 'acps_st_apply_lock', 1, 120 );

		$attempts = isset( $pending['attempts'] ) ? (int) $pending['attempts'] : 0;
		if ( $attempts > 10 || ( isset( $pending['requested'] ) && ( time() - (int) $pending['requested'] ) > DAY_IN_SECONDS ) ) {
			delete_option( self::PENDING_OPT );
			delete_transient( 'acps_st_apply_lock' );
			return;
		}

		try {
			$res = self::install( ! empty( $pending['force'] ) );
			if ( ! empty( $res['ok'] ) ) {
				delete_option( self::PENDING_OPT );
				self::ensure_active();
			} else {
				$pending['attempts'] = $attempts + 1;
				update_option( self::PENDING_OPT, $pending, false );
				if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::CRON_HOOK ) ) {
					wp_schedule_single_event( time() + 60, self::CRON_HOOK );
				}
			}
			update_option( 'acps_st_last_install', array( 'time' => time(), 'result' => isset( $res['message'] ) ? $res['message'] : '' ), false );
		} catch ( \Throwable $e ) { /* try again later */ }

		delete_transient( 'acps_st_apply_lock' );
	}

	/* ------------------------------------------------------------------ *
	 * Write probe (diagnose, don't guess).
	 * ------------------------------------------------------------------ */

	/**
	 * Live write test: create + delete a throwaway file of each extension in the
	 * plugin folder. Reveals whether the host blocks only in-use PHP (staging
	 * works) or ALL php writes (only SFTP/cron-as-owner can update).
	 *
	 * @return string Human-readable report.
	 */
	public static function probe() {
		$dir  = untrailingslashit( ACPS_ST_PATH );
		$tag  = self::suffix();
		$out  = array();
		foreach ( array( 'md', 'txt', 'js', 'css', 'php' ) as $ext ) {
			$f  = $dir . '/acps-writetest-' . $tag . '.' . $ext;
			$ok = ( false !== @file_put_contents( $f, "test\n" ) ); // phpcs:ignore
			if ( $ok ) {
				@unlink( $f ); // phpcs:ignore
			}
			$out[] = 'new .' . $ext . ' : ' . ( $ok ? 'OK' : 'FAILED' );
		}
		$php_ok = ( false !== strpos( implode( '', $out ), 'new .php : OK' ) );
		$verdict = $php_ok
			? 'New .php writes OK -> host only blocks overwriting in-use PHP -> Stage/Reinstall works.'
			: 'New .php writes FAILED -> host blocks all PHP writes for the web user -> use SFTP or cron-as-owner.';
		return implode( "\n", $out ) . "\n" . $verdict;
	}

	/* ------------------------------------------------------------------ *
	 * Package resolution (manifest / GitHub) — raw options only.
	 * ------------------------------------------------------------------ */

	public static function resolve_package() {
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

		$manifest = isset( $o['update_manifest'] ) ? trim( (string) $o['update_manifest'] ) : '';
		if ( '' === $manifest ) {
			return null;
		}
		$args = array( 'plugin' => dirname( ACPS_ST_BASENAME ), 'site' => home_url( '/' ) );
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

	/* ------------------------------------------------------------------ *
	 * Upgrader filters (folder rename + private GitHub asset).
	 * ------------------------------------------------------------------ */

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
	 * Small helpers: opcache, ensure_active, rollback, plain file trees.
	 * ------------------------------------------------------------------ */

	private static function opcache() {
		if ( function_exists( 'opcache_reset' ) ) {
			@opcache_reset(); // phpcs:ignore
		}
	}

	/** Re-enable the plugin and clear any WSOD/recovery pause, same request. */
	public static function ensure_active() {
		try {
			if ( ! function_exists( 'is_plugin_active' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			if ( function_exists( 'is_plugin_active' ) && ! is_plugin_active( ACPS_ST_BASENAME ) ) {
				activate_plugin( ACPS_ST_BASENAME );
			}
			if ( function_exists( 'wp_paused_plugins' ) ) {
				$paused = wp_paused_plugins();
				foreach ( array( ACPS_ST_BASENAME, dirname( ACPS_ST_BASENAME ) ) as $key ) {
					if ( method_exists( $paused, 'delete' ) ) {
						if ( ! method_exists( $paused, 'get' ) || $paused->get( $key ) ) {
							$paused->delete( $key );
						}
					}
				}
			}
		} catch ( \Throwable $e ) { /* best effort */ }
	}

	/** Back up the current plugin files to a sibling dir for possible rollback. */
	private static function arm_rollback() {
		$dir = WP_CONTENT_DIR . '/acps-st-backup-' . self::suffix();
		if ( self::copy_tree( untrailingslashit( ACPS_ST_PATH ), $dir ) === array() ) {
			update_option( self::ROLLBACK_OPT, array( 'dir' => $dir, 'version' => defined( 'ACPS_ST_VERSION' ) ? ACPS_ST_VERSION : '', 'time' => time() ), false );
		} else {
			// Backup incomplete — don't rely on it.
			self::remove_tree( $dir );
			delete_option( self::ROLLBACK_OPT );
		}
	}

	/**
	 * Recursively copy $src into $dst using plain PHP (no WP_Filesystem, so it
	 * works with as little loaded as possible). Continues past any single file
	 * it can't write and returns the list of relative paths that failed.
	 *
	 * @param string $src Source dir.
	 * @param string $dst Destination dir.
	 * @return string[] Failed relative paths ( empty = full success ).
	 */
	public static function copy_tree( $src, $dst ) {
		$failed = array();
		$src    = untrailingslashit( $src );
		$dst    = untrailingslashit( $dst );
		if ( ! is_dir( $src ) ) {
			return array( '(missing source)' );
		}
		if ( ! is_dir( $dst ) ) {
			@mkdir( $dst, 0755, true ); // phpcs:ignore
		}
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $src, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ( $it as $item ) {
			$rel    = ltrim( str_replace( $src, '', $item->getPathname() ), '/\\' );
			$target = $dst . '/' . $rel;
			if ( $item->isDir() ) {
				if ( ! is_dir( $target ) ) {
					@mkdir( $target, 0755, true ); // phpcs:ignore
				}
				continue;
			}
			if ( ! @copy( $item->getPathname(), $target ) ) { // phpcs:ignore
				@chmod( $target, 0644 ); // phpcs:ignore
				if ( ! @copy( $item->getPathname(), $target ) ) { // phpcs:ignore
					$failed[] = $rel;
				}
			}
		}
		return $failed;
	}

	/** Recursively delete a directory (plain PHP). */
	public static function remove_tree( $dir ) {
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return;
		}
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $it as $item ) {
			if ( $item->isDir() ) {
				@rmdir( $item->getPathname() ); // phpcs:ignore
			} else {
				@unlink( $item->getPathname() ); // phpcs:ignore
			}
		}
		@rmdir( $dir ); // phpcs:ignore
	}
}
