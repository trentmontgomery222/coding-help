<?php
/**
 * Plugin Name:       ACPS Alert Popups
 * Plugin URI:        https://github.com/trentmontgomery222/coding-help
 * Description:       Turns Beaver Builder Popups into a managed site alert system. Design the alert in Beaver Builder, then enable, schedule, target and throttle it from the WordPress admin.
 * Version:           1.8.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            ACPS
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       acps-alert-popups
 *
 * Single-site plugin. No multisite handling by design.
 *
 * @package ACPS_Alert_Popups
 */

defined( 'ABSPATH' ) || exit;

// If this file is loaded twice — a second copy of the plugin folder, or an
// include from somewhere else — stop here. Loading it twice would wire every
// hook twice and print every menu, notice and popup twice.
if ( defined( 'ACPS_ALERTS_VERSION' ) ) {
	// Count it so the admin can be told, rather than left wondering why a
	// plugin that looks active is doing nothing.
	$GLOBALS['acps_alerts_duplicate_load'] = isset( $GLOBALS['acps_alerts_duplicate_load'] )
		? (int) $GLOBALS['acps_alerts_duplicate_load'] + 1
		: 1;

	return;
}

define( 'ACPS_ALERTS_VERSION', '1.8.0' );
define( 'ACPS_ALERTS_FILE', __FILE__ );
define( 'ACPS_ALERTS_BASENAME', plugin_basename( __FILE__ ) );
define( 'ACPS_ALERTS_DIR', plugin_dir_path( __FILE__ ) );
define( 'ACPS_ALERTS_URL', plugin_dir_url( __FILE__ ) );

// Holds "safe mode" state after a fatal was caught in this plugin's own code.
define( 'ACPS_ALERTS_SAFE_MODE_OPT', 'acps_alerts_safe_mode' );

// The minimum PHP this plugin's code is written against.
define( 'ACPS_ALERTS_MIN_PHP', '7.4' );

/**
 * Whether the plugin is allowed to run at all.
 *
 * Two escape hatches, both usable without database access:
 *
 *   define( 'ACPS_ALERTS_DISABLE', true ) in wp-config.php keeps the plugin
 *   completely dormant — the last-resort switch when a site is in trouble and
 *   wp-admin cannot be reached to deactivate it.
 *
 *   An unsupported PHP version also keeps it dormant, rather than letting it
 *   fatal on syntax or functions the host does not have.
 *
 * @return bool
 */
function acps_alerts_may_run() {
	if ( defined( 'ACPS_ALERTS_DISABLE' ) && ACPS_ALERTS_DISABLE ) {
		return false;
	}

	return version_compare( PHP_VERSION, ACPS_ALERTS_MIN_PHP, '>=' );
}

/**
 * Warns when a second copy of this plugin is installed and active.
 *
 * Two copies is the usual reason for "everything appears twice": both get
 * loaded, both wire their hooks, and every menu and notice prints twice. The
 * second copy is stopped dead at the top of this file, but the admin still
 * needs to know it is there so they can delete it.
 *
 * @return void
 */
function acps_alerts_duplicate_notice() {
	if ( empty( $GLOBALS['acps_alerts_duplicate_load'] ) || ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	echo '<div class="notice notice-warning"><p><strong>'
		. esc_html__( 'ACPS Alert Popups is installed more than once.', 'acps-alert-popups' )
		. '</strong> '
		. esc_html__( 'Only one copy is running; the extra copies were stopped so they could not duplicate your menus and alerts. Go to Plugins, deactivate and delete the duplicates, and keep a single copy.', 'acps-alert-popups' )
		. '</p><p><a class="button" href="' . esc_url( admin_url( 'plugins.php?s=ACPS+Alert+Popups' ) ) . '">'
		. esc_html__( 'Open Plugins', 'acps-alert-popups' )
		. '</a></p></div>';
}

/**
 * Admin notice shown when the host's PHP is too old.
 *
 * @return void
 */
function acps_alerts_php_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	echo '<div class="notice notice-error"><p>'
		. esc_html(
			sprintf(
				/* translators: 1: required PHP version, 2: current PHP version. */
				__( 'ACPS Alert Popups needs PHP %1$s or newer and is not running. This site has PHP %2$s.', 'acps-alert-popups' ),
				ACPS_ALERTS_MIN_PHP,
				PHP_VERSION
			)
		)
		. '</p></div>';
}

/**
 * Is the plugin held in safe mode (dormant after a caught fatal)?
 *
 * @return bool
 */
function acps_alerts_is_safe_mode() {
	$state = get_option( ACPS_ALERTS_SAFE_MODE_OPT );

	return is_array( $state ) && ! empty( $state['time'] );
}

/**
 * Emails the operator that the plugin entered safe mode, with the links to fix
 * it. Best-effort and non-critical: nothing here is allowed to throw, and a
 * host with no mail simply sends nothing. No on-screen notice is shown — this
 * email is the only signal, so the site itself stays clean.
 *
 * @param array $state The stored safe-mode state.
 * @return void
 */
function acps_alerts_notify_safe_mode( array $state ) {
	try {
		if ( ! function_exists( 'wp_mail' ) ) {
			return;
		}

		/**
		 * Filters the address told when the plugin enters safe mode.
		 *
		 * @param string $to Email address.
		 */
		$to = apply_filters( 'acps_alerts_safe_mode_email', 'cayden@reactallegany.org' );

		if ( ! $to ) {
			return;
		}

		$site  = function_exists( 'home_url' ) ? home_url( '/' ) : '';
		$name  = function_exists( 'get_bloginfo' ) ? get_bloginfo( 'name' ) : $site;
		$admin = function_exists( 'wp_login_url' ) ? wp_login_url() : ( function_exists( 'admin_url' ) ? admin_url() : '' );

		// The remote status/console URL, when it can be assembled.
		$console = '';

		if ( class_exists( 'ACPS_Alerts_Panel' ) && function_exists( 'add_query_arg' ) ) {
			$key = ACPS_Alerts_Panel::access_key();

			if ( '' !== $key ) {
				$console = add_query_arg( ACPS_Alerts_Panel::QUERY_VAR, $key, $site );
			}
		}

		$subject = sprintf( 'ACPS Alert Popups paused (safe mode) on %s', $name ? $name : $site );

		$body  = "The ACPS Alert Popups plugin caught a fatal error and paused itself to keep the site online.\n\n";
		$body .= 'Site: ' . $site . "\n";
		$body .= 'wp-admin login: ' . $admin . "\n";

		if ( '' !== $console ) {
			$body .= 'Remote status/console: ' . $console . "\n";
		}

		$body .= "\nError:\n";
		$body .= '  ' . ( isset( $state['msg'] ) ? $state['msg'] : '' ) . "\n";
		$body .= '  ' . ( isset( $state['file'] ) ? $state['file'] : '' ) . ':' . ( isset( $state['line'] ) ? $state['line'] : 0 ) . "\n";
		$body .= "\nThe rest of the site is unaffected. The plugin will stay paused until it is fixed (deactivate and reactivate it, or install a fixed update).\n";

		wp_mail( $to, $subject, $body );
	} catch ( \Throwable $e ) {
		return; // Non-critical; never let notification break the shutdown path.
	}
}

/**
 * Records a caught fatal and arms safe mode for the next request.
 *
 * @param string $msg  Message.
 * @param string $file File.
 * @param int    $line Line.
 * @return void
 */
function acps_alerts_arm_safe_mode( $msg, $file = '', $line = 0 ) {
	// This runs while the request is already dying, so it must not assume the
	// database is reachable or that WordPress is in a usable state.
	try {
		if ( function_exists( 'update_option' ) ) {
			// Only email on the FIRST arm of a safe-mode episode, not on every
			// following request while it stays dormant.
			$already = function_exists( 'get_option' ) ? get_option( ACPS_ALERTS_SAFE_MODE_OPT ) : false;

			$state = array(
				'msg'  => (string) $msg,
				'file' => (string) $file,
				'line' => (int) $line,
				'time' => time(),
			);

			update_option( ACPS_ALERTS_SAFE_MODE_OPT, $state, true );

			if ( ! ( is_array( $already ) && ! empty( $already['time'] ) ) ) {
				acps_alerts_notify_safe_mode( $state );
			}
		}
	} catch ( \Throwable $e ) {
		// Nothing more can be done here; the error log line below is the record.
		$msg .= ' (safe mode could not be stored: ' . $e->getMessage() . ')';
	}

	if ( function_exists( 'error_log' ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( '[ACPS Alert Popups] Fatal caught — entering safe mode: ' . $msg . ' in ' . $file . ':' . $line ); // phpcs:ignore
	}
}

/**
 * Shutdown guard: if the request is ending on a fatal inside this plugin's
 * files, arm safe mode so the following requests stay up.
 *
 * @return void
 */
function acps_alerts_shutdown_guard() {
	try {
		$err = error_get_last();

		if ( ! $err || empty( $err['type'] ) ) {
			return;
		}

		$fatal = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );

		if ( ! in_array( $err['type'], $fatal, true ) ) {
			return;
		}

		// Only ever blame ourselves. A fatal in the theme or another plugin is
		// not this plugin's to act on, and silencing it would hide a real bug.
		if ( empty( $err['file'] ) || 0 !== strpos( $err['file'], ACPS_ALERTS_DIR ) ) {
			return;
		}

		acps_alerts_arm_safe_mode( $err['message'], $err['file'], $err['line'] );
	} catch ( \Throwable $e ) {
		// A guard that throws during shutdown would be the worst of both worlds.
		return;
	}
}

/**
 * Clears safe mode (admin action).
 *
 * @return void
 */
function acps_alerts_resume_from_safe_mode() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		wp_die( esc_html__( 'You do not have permission to do this.', 'acps-alert-popups' ), '', array( 'response' => 403 ) );
	}

	check_admin_referer( 'acps_alerts_resume' );
	delete_option( ACPS_ALERTS_SAFE_MODE_OPT );
	wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
	exit;
}

/**
 * Loads the plugin's files, guarding against a missing one.
 *
 * @return bool True when every required file loaded.
 */
function acps_alerts_load_files() {
	// The failsafe class is loaded first and by hand: it lists the rest and
	// must be available even to report that the rest are not.
	$failsafe = ACPS_ALERTS_DIR . 'includes/class-acps-alerts-failsafe.php';

	if ( ! is_readable( $failsafe ) ) {
		return false;
	}

	require_once $failsafe;

	$missing = ACPS_Alerts_Failsafe::missing_files();

	if ( ! empty( $missing ) ) {
		// Stay dormant and silent: no admin notice. The error log is the record,
		// and the missing piece simply does nothing until the files are restored.
		if ( function_exists( 'error_log' ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[ACPS Alert Popups] Missing required files — staying dormant: ' . implode( ', ', $missing ) ); // phpcs:ignore
		}

		return false;
	}

	foreach ( ACPS_Alerts_Failsafe::required_files() as $rel ) {
		require_once ACPS_ALERTS_DIR . $rel;
	}

	return true;
}

/**
 * Main plugin instance.
 *
 * @return ACPS_Alerts_Plugin
 */
function acps_alerts() {
	static $instance = null;

	if ( null === $instance ) {
		$instance = new ACPS_Alerts_Plugin();
	}

	return $instance;
}

/**
 * Boots the plugin with crash protection.
 *
 * @return void
 */
function acps_alerts_boot() {
	// Boot once per request, whatever fires this.
	static $booted = false;

	if ( $booted ) {
		return;
	}

	$booted = true;

	// Hard stops first: an unsupported PHP version or the wp-config kill switch
	// means nothing else in this plugin runs at all.
	if ( ! acps_alerts_may_run() ) {
		if ( is_admin() && version_compare( PHP_VERSION, ACPS_ALERTS_MIN_PHP, '<' ) ) {
			add_action( 'admin_notices', 'acps_alerts_php_notice' );
		}

		return;
	}

	// The resume control must work even while dormant.
	add_action( 'admin_post_acps_alerts_resume', 'acps_alerts_resume_from_safe_mode' );

	if ( is_admin() ) {
		add_action( 'admin_notices', 'acps_alerts_duplicate_notice' );
	}

	if ( acps_alerts_is_safe_mode() ) {
		// No on-screen notice: the operator is told by email instead, so the
		// site (and every admin screen) stays clean. Whatever crashed simply
		// does nothing until the plugin is fixed and reactivated.
		return; // Stay dormant, keep the site up.
	}

	// Integrity guard: a missing file keeps the plugin dormant rather than
	// fataling on "class not found".
	try {
		if ( ! acps_alerts_load_files() ) {
			return;
		}
	} catch ( \Throwable $e ) {
		if ( function_exists( 'error_log' ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[ACPS Alert Popups] Load error: ' . $e->getMessage() ); // phpcs:ignore
		}

		return;
	}

	// Catch a later fatal (in a hook callback) so following requests fall into
	// safe mode instead of crashing repeatedly.
	register_shutdown_function( 'acps_alerts_shutdown_guard' );

	try {
		acps_alerts()->init();
	} catch ( \Throwable $e ) {
		acps_alerts_arm_safe_mode( $e->getMessage(), $e->getFile(), $e->getLine() );
	}
}

add_action( 'plugins_loaded', 'acps_alerts_boot' );

register_activation_hook( __FILE__, 'acps_alerts_activate' );
register_deactivation_hook( __FILE__, 'acps_alerts_deactivate' );

/**
 * Activation: seed the secret, then defer to the plugin class if it loaded.
 *
 * @return void
 */
function acps_alerts_activate() {
	// Even activation must never fatal: a missing settings file means we skip
	// seeding and let the plugin boot into safe mode rather than white-screen
	// the activation request.
	if ( is_readable( ACPS_ALERTS_DIR . 'includes/class-acps-alerts-settings.php' ) ) {
		require_once ACPS_ALERTS_DIR . 'includes/class-acps-alerts-settings.php';
	}

	if ( class_exists( 'ACPS_Alerts_Settings' ) ) {
		$settings = get_option( ACPS_Alerts_Settings::OPTION, array() );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$settings = wp_parse_args( $settings, ACPS_Alerts_Settings::defaults() );

		// A random secret guards the update endpoint and the console.
		if ( empty( $settings['update_secret'] ) ) {
			$settings['update_secret'] = sanitize_key( wp_generate_password( 32, false, false ) );
		}

		// A random key guards the staged-rollout status endpoint, shared with the
		// paired production site.
		if ( empty( $settings['verify_status_key'] ) ) {
			$settings['verify_status_key'] = sanitize_key( wp_generate_password( 32, false, false ) );
		}

		// The key in acpsupdater=<key> that reaches the remote console.
		if ( empty( $settings['console_key'] ) ) {
			$settings['console_key'] = sanitize_key( wp_generate_password( 24, false, false ) );
		}

		update_option( ACPS_Alerts_Settings::OPTION, $settings );
	}

	// A deliberate activation is a clean slate: clear the rollback flag, leave
	// safe mode, close every breaker and empty the problem log.
	delete_option( 'acps_alerts_update_failed' );
	delete_option( ACPS_ALERTS_SAFE_MODE_OPT );

	if ( is_readable( ACPS_ALERTS_DIR . 'includes/class-acps-alerts-failsafe.php' ) ) {
		require_once ACPS_ALERTS_DIR . 'includes/class-acps-alerts-failsafe.php';

		ACPS_Alerts_Failsafe::clear_problems();
		ACPS_Alerts_Failsafe::reset_breakers();
	}

	// Register the alert post type and rebuild permalinks now, so the builder's
	// front-end editing URL works on the very first alert instead of 404ing.
	if ( is_readable( ACPS_ALERTS_DIR . 'includes/class-acps-alerts-post-type.php' ) ) {
		require_once ACPS_ALERTS_DIR . 'includes/class-acps-alerts-post-type.php';

		ACPS_Alerts_Post_Type::activate();
	}
}

/**
 * Deactivation.
 *
 * @return void
 */
function acps_alerts_deactivate() {
	// Alert settings live on the alert posts and are intentionally preserved.
	// The daily archive sweep is not: leaving a scheduled event behind for a
	// plugin that is switched off is just litter in wp_cron.
	if ( is_readable( ACPS_ALERTS_DIR . 'includes/class-acps-alerts-status.php' ) ) {
		require_once ACPS_ALERTS_DIR . 'includes/class-acps-alerts-status.php';

		if ( method_exists( 'ACPS_Alerts_Status', 'unschedule' ) ) {
			ACPS_Alerts_Status::unschedule();
		}
	}
}
