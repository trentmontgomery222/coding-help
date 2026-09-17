<?php
/**
 * Crash containment.
 *
 * The rule is absolute: nothing this plugin does may take the whole site down.
 * The defences, earliest to last:
 *
 *   0. Kill switch + environment guard (main plugin file): a wrong PHP version,
 *      or ACPS_ALERTS_DISABLE in wp-config.php, and the plugin never boots.
 *
 *   1. File-integrity guard (this class + boot()): before the plugin runs, it
 *      confirms every required file is present. A partial upload or a
 *      half-finished update leaves the plugin dormant with an admin notice
 *      naming the missing files, instead of a "class not found" fatal.
 *
 *   2. Guarded callbacks (this class): every hook this plugin registers is
 *      wrapped, so a thrown Error/Exception is caught, recorded, and turned
 *      into a safe empty result. A broken alert renders nothing instead of
 *      white-screening the page it sits on.
 *
 *   3. Circuit breakers (this class): a subsystem that throws repeatedly is
 *      switched off for a cooling-off period rather than throwing on every
 *      request for the rest of time.
 *
 *   4. Boot try/catch + shutdown guard + safe mode (main plugin file): a fatal
 *      that still slips through arms safe mode, so the next request keeps the
 *      site up and the admin gets a Resume control.
 *
 * Everything here is static and dependency-free so it can run even when the
 * rest of the plugin cannot.
 *
 * @package ACPS_Alert_Popups
 */

defined( 'ABSPATH' ) || exit;

/**
 * Failsafe.
 */
class ACPS_Alerts_Failsafe {

	/** Option holding the recent problem log. */
	const PROBLEMS_OPTION = 'acps_alerts_problems';

	/** How many problems to keep. */
	const PROBLEMS_MAX = 30;

	/** Failures within the window before a subsystem is switched off. */
	const BREAKER_LIMIT = 5;

	/** How long failures are counted for, and how long a tripped breaker holds. */
	const BREAKER_WINDOW = 600;

	/**
	 * Files the plugin cannot run without. Relative to ACPS_ALERTS_DIR.
	 *
	 * @return string[]
	 */
	public static function required_files() {
		return array(
			'includes/class-acps-alerts-settings.php',
			'includes/class-acps-alerts-source.php',
			'includes/class-acps-alerts-alert.php',
			'includes/class-acps-alerts-conditions.php',
			'includes/class-acps-alerts-fields.php',
			'includes/class-acps-alerts-admin.php',
			'includes/class-acps-alerts-frontend.php',
			'includes/class-acps-alerts-builder.php',
			'includes/class-acps-alerts-updater.php',
			'includes/class-acps-alerts-panel.php',
			'includes/class-acps-alerts-plugin.php',
		);
	}

	/**
	 * Files that are nice to have but not fatal if absent. A missing asset
	 * degrades the look, never the load.
	 *
	 * @return string[]
	 */
	public static function optional_files() {
		return array(
			'assets/css/alerts.css',
			'assets/js/alerts.js',
			'assets/css/admin.css',
			'assets/js/admin.js',
			'assets/css/help.css',
			'assets/js/help.js',
			'assets/css/tour.css',
			'assets/js/tour.js',
			'modules/alert-trigger/alert-trigger.php',
			'modules/alert-trigger/includes/frontend.php',
			// The whole teaching layer is optional on purpose: losing it costs
			// the tutorials, never the plugin.
			'includes/class-acps-alerts-help.php',
			'includes/class-acps-alerts-art.php',
			'includes/views/help-page.php',
		);
	}

	/**
	 * Which required files are missing right now.
	 *
	 * @return string[]
	 */
	public static function missing_files() {
		$missing = array();

		foreach ( self::required_files() as $rel ) {
			if ( ! is_readable( ACPS_ALERTS_DIR . $rel ) ) {
				$missing[] = $rel;
			}
		}

		return $missing;
	}

	/**
	 * Which optional files are missing.
	 *
	 * @return string[]
	 */
	public static function missing_optional_files() {
		$missing = array();

		foreach ( self::optional_files() as $rel ) {
			if ( ! is_readable( ACPS_ALERTS_DIR . $rel ) ) {
				$missing[] = $rel;
			}
		}

		return $missing;
	}

	/**
	 * Whether a plugin file exists and can be read.
	 *
	 * @param string $rel Path relative to the plugin root.
	 * @return bool
	 */
	public static function has_file( $rel ) {
		return is_readable( ACPS_ALERTS_DIR . ltrim( $rel, '/' ) );
	}

	/**
	 * Admin notice naming the missing files.
	 *
	 * @param string[] $missing Missing files.
	 * @return void
	 */
	public static function missing_files_notice( $missing ) {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p><strong>'
			. esc_html__( 'ACPS Alert Popups is paused.', 'acps-alert-popups' )
			. '</strong> '
			. esc_html__( 'Some of its files are missing, so it stopped loading to keep the site online. Re-upload the plugin to restore it.', 'acps-alert-popups' )
			. '</p><p><code>'
			. esc_html( implode( ', ', (array) $missing ) )
			. '</code></p></div>';
	}

	/* ------------------------------------------------------------------ *
	 * Guarded execution.
	 * ------------------------------------------------------------------ */

	/**
	 * Runs a callable, catching any Error or Exception. On failure the problem
	 * is recorded and $fallback is returned, so a broken subsystem degrades
	 * quietly instead of crashing the request.
	 *
	 * @param callable $callable Callable.
	 * @param array    $args     Positional args.
	 * @param string   $context  Label used for the log and the breaker.
	 * @param mixed    $fallback Value returned on failure.
	 * @return mixed
	 */
	public static function guard( $callable, $args = array(), $context = '', $fallback = null ) {
		if ( ! is_callable( $callable ) ) {
			self::record( $context, 'callback is not callable' );

			return $fallback;
		}

		if ( '' !== $context && self::breaker_tripped( $context ) ) {
			return $fallback;
		}

		try {
			return call_user_func_array( $callable, (array) $args );
		} catch ( \Throwable $e ) {
			// PHP 7+: catches both Error and Exception.
			self::record( $context, $e->getMessage(), $e->getFile(), $e->getLine() );
			self::note_failure( $context );

			return $fallback;
		}
	}

	/**
	 * Wraps a callable so it can be handed straight to add_action/add_filter.
	 *
	 * A filter must hand back something usable, so pass the index of the
	 * argument that should be returned unchanged on failure (0 for the first).
	 *
	 * @param callable $callable    Callable.
	 * @param string   $context     Label.
	 * @param int|null $passthrough Index of the argument to return on failure.
	 * @return callable
	 */
	public static function wrap( $callable, $context, $passthrough = null ) {
		return function () use ( $callable, $context, $passthrough ) {
			$args     = func_get_args();
			$fallback = ( null !== $passthrough && isset( $args[ $passthrough ] ) ) ? $args[ $passthrough ] : null;

			return self::guard( $callable, $args, $context, $fallback );
		};
	}

	/**
	 * Registers an action whose callback can never escape a failure.
	 *
	 * @param string   $hook     Hook name.
	 * @param callable $callable Callback.
	 * @param string   $context  Label.
	 * @param int      $priority Priority.
	 * @param int      $args     Accepted args.
	 * @return void
	 */
	public static function action( $hook, $callable, $context, $priority = 10, $args = 1 ) {
		add_action( $hook, self::wrap( $callable, $context ), $priority, $args );
	}

	/**
	 * Registers a filter whose callback can never escape a failure. On failure
	 * the first argument is returned unchanged, so the filter chain is intact.
	 *
	 * @param string   $hook     Hook name.
	 * @param callable $callable Callback.
	 * @param string   $context  Label.
	 * @param int      $priority Priority.
	 * @param int      $args     Accepted args.
	 * @return void
	 */
	public static function filter( $hook, $callable, $context, $priority = 10, $args = 1 ) {
		add_filter( $hook, self::wrap( $callable, $context, 0 ), $priority, $args );
	}

	/**
	 * Captures anything a callable echoes, discarding the output if it throws
	 * partway through. Stops a half-rendered fragment reaching the page.
	 *
	 * @param callable $callable Callable.
	 * @param array    $args     Positional args.
	 * @param string   $context  Label.
	 * @return string The captured output, or '' on failure.
	 */
	public static function capture( $callable, $args = array(), $context = '' ) {
		if ( '' !== $context && self::breaker_tripped( $context ) ) {
			return '';
		}

		$level = ob_get_level();

		ob_start();

		try {
			call_user_func_array( $callable, (array) $args );

			return (string) ob_get_clean();
		} catch ( \Throwable $e ) {
			// Unwind any buffers the callable opened and left behind.
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}

			self::record( $context, $e->getMessage(), $e->getFile(), $e->getLine() );
			self::note_failure( $context );

			return '';
		}
	}

	/* ------------------------------------------------------------------ *
	 * Circuit breakers.
	 * ------------------------------------------------------------------ */

	/**
	 * Whether a subsystem is currently switched off after repeated failures.
	 *
	 * @param string $context Label.
	 * @return bool
	 */
	public static function breaker_tripped( $context ) {
		if ( '' === $context ) {
			return false;
		}

		return (bool) get_transient( 'acps_ap_brk_' . md5( $context ) );
	}

	/**
	 * Counts a failure and trips the breaker once the limit is reached.
	 *
	 * @param string $context Label.
	 * @return void
	 */
	public static function note_failure( $context ) {
		if ( '' === $context ) {
			return;
		}

		$key   = 'acps_ap_fail_' . md5( $context );
		$count = (int) get_transient( $key ) + 1;

		set_transient( $key, $count, self::BREAKER_WINDOW );

		if ( $count >= self::BREAKER_LIMIT ) {
			set_transient( 'acps_ap_brk_' . md5( $context ), 1, self::BREAKER_WINDOW );
			delete_transient( $key );
			self::record( $context, 'switched off after repeated failures' );
		}
	}

	/**
	 * Clears every breaker and failure counter.
	 *
	 * @return void
	 */
	public static function reset_breakers() {
		global $wpdb;

		if ( ! isset( $wpdb ) ) {
			return;
		}

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_acps_ap_brk_%' OR option_name LIKE '_transient_timeout_acps_ap_brk_%' OR option_name LIKE '_transient_acps_ap_fail_%' OR option_name LIKE '_transient_timeout_acps_ap_fail_%'"
		);
	}

	/* ------------------------------------------------------------------ *
	 * Resource guards.
	 * ------------------------------------------------------------------ */

	/**
	 * Whether there is enough memory headroom to safely render extra output.
	 *
	 * Rendering a page-builder layout costs memory; if the request is already
	 * near the limit, skipping the alert is far better than exhausting it.
	 *
	 * @param int $needed_bytes Headroom wanted, in bytes.
	 * @return bool
	 */
	public static function memory_ok( $needed_bytes = 8388608 ) {
		$limit = self::memory_limit_bytes();

		if ( $limit <= 0 ) {
			return true; // No limit, or unreadable: don't second-guess it.
		}

		return ( $limit - memory_get_usage( true ) ) > $needed_bytes;
	}

	/**
	 * The memory limit in bytes. 0 when unlimited or unknown.
	 *
	 * @return int
	 */
	public static function memory_limit_bytes() {
		$raw = ini_get( 'memory_limit' );

		if ( false === $raw || '' === $raw || '-1' === (string) $raw ) {
			return 0;
		}

		if ( function_exists( 'wp_convert_hr_to_bytes' ) ) {
			return (int) wp_convert_hr_to_bytes( $raw );
		}

		return (int) $raw;
	}

	/* ------------------------------------------------------------------ *
	 * Problem log.
	 * ------------------------------------------------------------------ */

	/**
	 * Records a caught problem, both to the rolling log and to the PHP error
	 * log when WP_DEBUG is on.
	 *
	 * @param string $context Label.
	 * @param string $message Message.
	 * @param string $file    File.
	 * @param int    $line    Line.
	 * @return void
	 */
	public static function record( $context, $message, $file = '', $line = 0 ) {
		self::log( $message, $context );

		try {
			$log = get_option( self::PROBLEMS_OPTION, array() );

			if ( ! is_array( $log ) ) {
				$log = array();
			}

			$log[] = array(
				'time'    => time(),
				'context' => (string) $context,
				'message' => (string) $message,
				'file'    => $file ? str_replace( ABSPATH, '', (string) $file ) : '',
				'line'    => (int) $line,
			);

			update_option( self::PROBLEMS_OPTION, array_slice( $log, -self::PROBLEMS_MAX ), false );
		} catch ( \Throwable $e ) {
			// Recording a problem must never itself become one.
			self::log( 'could not record problem: ' . $e->getMessage(), 'failsafe' );
		}
	}

	/**
	 * The recent problem log, newest first.
	 *
	 * @param int $limit How many entries.
	 * @return array[]
	 */
	public static function problems( $limit = 10 ) {
		$log = get_option( self::PROBLEMS_OPTION, array() );

		if ( ! is_array( $log ) || empty( $log ) ) {
			return array();
		}

		return array_reverse( array_slice( $log, -(int) $limit ) );
	}

	/**
	 * Empties the problem log.
	 *
	 * @return void
	 */
	public static function clear_problems() {
		delete_option( self::PROBLEMS_OPTION );
	}

	/**
	 * Logs a caught failure, only when WP_DEBUG is on.
	 *
	 * @param string $message Message.
	 * @param string $context Context label.
	 * @return void
	 */
	public static function log( $message, $context = '' ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) {
			error_log( '[ACPS Alert Popups] ' . ( $context ? $context . ': ' : '' ) . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}
}
