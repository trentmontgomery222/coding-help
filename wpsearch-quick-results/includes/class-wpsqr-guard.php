<?php
/**
 * Crash protection.
 *
 * A plugin that can white-screen the whole site on a bad file or a fatal in
 * one feature is not one you want auto-updating from a URL. This is the floor
 * everything else stands on: a missing include is survived, and a fatal in
 * this plugin's own code trips a safe mode that loads nothing but a notice on
 * the next request, rather than taking the site down until someone reaches
 * the server by FTP.
 *
 * Nothing here depends on the rest of the plugin having loaded, on purpose —
 * it is what runs when the rest of the plugin could not.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPSQR_Guard {

	const SAFE_MODE_OPTION = 'wpsqr_safe_mode';
	const HEALTH_OPTION    = 'wpsqr_last_health';
	const ROLLBACK_OPTION  = 'wpsqr_rollback';

	/** Every include the plugin needs, relative to WPSQR_PATH. */
	public static function required_files() {
		return array(
			'includes/class-wpsqr-normalizer.php',
			'includes/class-wpsqr-schema.php',
			'includes/class-wpsqr-cache.php',
			'includes/class-wpsqr-stats.php',
			'includes/class-wpsqr-observer.php',
			'includes/class-wpsqr-status.php',
			'includes/class-wpsqr-age.php',
			'includes/class-wpsqr-rules.php',
			'includes/class-wpsqr-postlist.php',
			'includes/class-wpsqr-people.php',
			'includes/class-wpsqr-directory.php',
			'includes/class-wpsqr-hidden.php',
			'includes/class-wpsqr-index.php',
			'includes/class-wpsqr-search.php',
			'includes/class-wpsqr-engine.php',
			'includes/class-wpsqr-native.php',
			'includes/class-wpsqr-renderer.php',
			'includes/class-wpsqr-warmer.php',
			'includes/class-wpsqr-searchwp.php',
			'includes/class-wpsqr-assets.php',
			'includes/class-wpsqr-netgate.php',
			'includes/class-wpsqr-updater.php',
			'includes/class-wpsqr-remote.php',
			'includes/class-wpsqr-admin.php',
			'includes/class-wpsqr-plugin.php',
		);
	}

	/**
	 * Load every include, tolerating a missing or broken one.
	 *
	 * A require that fails on a parse error is a fatal PHP can't be caught out
	 * of, and the shutdown guard handles that. A file that is simply absent —
	 * an interrupted update, a half-uploaded zip — is caught here so the rest
	 * of the plugin still loads and the health check can report the gap.
	 *
	 * @return string[] The files that were missing.
	 */
	public static function load_includes() {
		$missing = array();

		foreach ( self::required_files() as $relative ) {
			$path = WPSQR_PATH . $relative;

			if ( ! is_readable( $path ) ) {
				$missing[] = $relative;
				continue;
			}

			require_once $path;
		}

		if ( $missing ) {
			update_option(
				self::HEALTH_OPTION,
				array(
					'time'    => time(),
					'missing' => $missing,
				),
				false
			);
		} elseif ( get_option( self::HEALTH_OPTION ) ) {
			// A previous gap has healed — an update finished, the file is back.
			delete_option( self::HEALTH_OPTION );
		}

		return $missing;
	}

	/**
	 * Enough of the plugin to be worth running?
	 *
	 * If the core classes did not load there is nothing to boot, and trying
	 * would only produce a second fatal. The bootstrap checks this before
	 * calling into the plugin.
	 */
	public static function core_loaded() {
		return class_exists( 'WPSQR_Plugin' ) && class_exists( 'WPSQR_Engine' );
	}

	/* ---- Safe mode ----------------------------------------------------- */

	public static function is_safe_mode() {
		return (bool) get_option( self::SAFE_MODE_OPTION );
	}

	public static function enter_safe_mode( $reason = '' ) {
		update_option(
			self::SAFE_MODE_OPTION,
			array(
				'time'   => time(),
				'reason' => (string) $reason,
			),
			false
		);
	}

	public static function leave_safe_mode() {
		delete_option( self::SAFE_MODE_OPTION );
	}

	/**
	 * Register the fatal-error catcher.
	 *
	 * A fatal cannot be caught with try/catch, but it can be seen on shutdown.
	 * When the last error is fatal AND its file is inside this plugin, safe
	 * mode is set so the next request loads only the recovery notice. The file
	 * check is what keeps an unrelated plugin's fatal from disabling this one.
	 */
	public static function register_shutdown_guard() {
		register_shutdown_function(
			static function () {
				$error = error_get_last();

				if ( ! $error || ! in_array( $error['type'], array( E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR ), true ) ) {
					return;
				}

				$file = isset( $error['file'] ) ? (string) $error['file'] : '';

				if ( '' === $file || 0 !== strpos( wp_normalize_path( $file ), wp_normalize_path( WPSQR_PATH ) ) ) {
					return; // not ours
				}

				self::enter_safe_mode( $error['message'] . ' @ ' . $file . ':' . $error['line'] );
			}
		);
	}

	/**
	 * What to run instead of the plugin while in safe mode.
	 *
	 * The one thing an admin needs is a way out, so this adds a "Resume"
	 * action and an explanatory notice, and nothing else. Every other hook
	 * the plugin would register stays unregistered, so whatever crashed cannot
	 * crash again.
	 */
	public static function run_safe_mode() {
		add_action( 'admin_notices', array( __CLASS__, 'safe_mode_notice' ) );
		add_action( 'admin_post_wpsqr_resume', array( __CLASS__, 'handle_resume' ) );
	}

	public static function safe_mode_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$state  = get_option( self::SAFE_MODE_OPTION );
		$reason = is_array( $state ) && ! empty( $state['reason'] ) ? $state['reason'] : '';

		$url = wp_nonce_url( admin_url( 'admin-post.php?action=wpsqr_resume' ), 'wpsqr_resume' );

		echo '<div class="notice notice-error"><p><strong>WPSearch Quick Results is paused.</strong> ';
		echo 'It hit a fatal error and stopped itself so the rest of the site keeps working. ';
		echo '<a href="' . esc_url( $url ) . '">Try resuming it</a>.';

		if ( $reason ) {
			echo '<br><code style="font-size:11px">' . esc_html( $reason ) . '</code>';
		}

		echo '</p></div>';
	}

	public static function handle_resume() {
		if ( ! current_user_can( 'activate_plugins' ) || ! check_admin_referer( 'wpsqr_resume' ) ) {
			wp_die( 'Not allowed.' );
		}

		self::leave_safe_mode();

		wp_safe_redirect( admin_url( 'plugins.php' ) );
		exit;
	}

	/* ---- Rollback ------------------------------------------------------ */

	/**
	 * Remember the current plugin directory so a bad update can be undone.
	 *
	 * Called just before an update installs. If the new version then fatals,
	 * the bootstrap restores this copy on the next request instead of leaving
	 * the plugin paused — the plugin is never left disabled; the worst case is
	 * that it is running the previous version.
	 *
	 * @param string $version The version being backed up (the current one).
	 * @return string|false The backup path, or false if it could not be made.
	 */
	public static function arm_rollback( $version ) {
		$backup = self::backup_dir() . 'v' . preg_replace( '/[^0-9A-Za-z._-]/', '', (string) $version ) . '-' . time();

		if ( ! self::copy_tree( untrailingslashit( WPSQR_PATH ), $backup ) ) {
			return false;
		}

		update_option(
			self::ROLLBACK_OPTION,
			array(
				'backup'  => $backup,
				'version' => (string) $version,
				'time'    => time(),
			),
			false
		);

		return $backup;
	}

	/** An update that loaded cleanly no longer needs its backup. */
	public static function disarm_rollback() {
		$state = get_option( self::ROLLBACK_OPTION );

		if ( is_array( $state ) && ! empty( $state['backup'] ) ) {
			self::remove_tree( $state['backup'] );
		}

		delete_option( self::ROLLBACK_OPTION );
	}

	/**
	 * If a bad update tripped safe mode, restore the previous version.
	 *
	 * Runs from the bootstrap's safe-mode branch, before anything else loads,
	 * so it works even when the new code cannot. On success the plugin is left
	 * running the old version with safe mode cleared; the next request loads
	 * it normally.
	 *
	 * @return bool Whether a rollback was performed.
	 */
	public static function maybe_rollback() {
		$state = get_option( self::ROLLBACK_OPTION );

		if ( ! is_array( $state ) || empty( $state['backup'] ) || ! is_dir( $state['backup'] ) ) {
			return false;
		}

		if ( ! self::copy_tree( $state['backup'], untrailingslashit( WPSQR_PATH ) ) ) {
			return false;
		}

		self::remove_tree( $state['backup'] );
		delete_option( self::ROLLBACK_OPTION );
		self::leave_safe_mode();

		update_option(
			self::HEALTH_OPTION,
			false,
			false
		);

		update_option(
			'wpsqr_last_rollback',
			array(
				'reverted_to' => (string) $state['version'],
				'time'        => time(),
			),
			false
		);

		return true;
	}

	protected static function backup_dir() {
		$base = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : dirname( dirname( dirname( WPSQR_PATH ) ) );
		$dir  = trailingslashit( $base ) . 'wpsqr-rollback/';

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		return $dir;
	}

	/**
	 * Copy a directory tree, overwriting the destination.
	 *
	 * Plain filesystem functions rather than WP_Filesystem, because this must
	 * work in the safe-mode path where as little as possible is loaded and the
	 * new code may be broken.
	 */
	protected static function copy_tree( $from, $to ) {
		$from = untrailingslashit( $from );
		$to   = untrailingslashit( $to );

		if ( ! is_dir( $from ) ) {
			return false;
		}

		if ( ! is_dir( $to ) && ! wp_mkdir_p( $to ) ) {
			return false;
		}

		$items = @scandir( $from ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( false === $items ) {
			return false;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$src = $from . '/' . $item;
			$dst = $to . '/' . $item;

			if ( is_dir( $src ) ) {
				if ( ! self::copy_tree( $src, $dst ) ) {
					return false;
				}
			} elseif ( ! @copy( $src, $dst ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				return false;
			}
		}

		return true;
	}

	protected static function remove_tree( $dir ) {
		$dir = untrailingslashit( $dir );

		if ( ! is_dir( $dir ) ) {
			return;
		}

		$items = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( false === $items ) {
			return;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$path = $dir . '/' . $item;

			if ( is_dir( $path ) ) {
				self::remove_tree( $path );
			} else {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}

		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
}
