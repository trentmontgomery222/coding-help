<?php
/**
 * Failsafe layer — the plugin's crash-containment system.
 *
 * The design goal is absolute: nothing this plugin does may take the whole
 * site down. There are three lines of defence, from earliest to last-resort:
 *
 *   1. File-integrity guard (this class + boot()): before the plugin runs, we
 *      confirm every required file is present. A partial/corrupt upload or a
 *      half-finished update leaves the plugin dormant with a clear admin notice
 *      naming the missing files — instead of fataling on a "class not found".
 *
 *   2. Guarded callbacks (this class): every front-end-facing hook (footer
 *      output, asset enqueue, shortcodes, block/widget render) is wrapped so a
 *      thrown Error/Exception is caught, logged, and turned into a safe empty
 *      result — a single broken form renders nothing rather than white-screening
 *      the page it's on.
 *
 *   3. Boot try/catch + shutdown guard + safe mode (acps-site-toolkit.php): a
 *      fatal that still slips through arms "safe mode" so the NEXT request keeps
 *      the site up, and the admin gets a Resume control once it's fixed.
 *
 * Everything here is static and dependency-free so it can run even when the
 * rest of the plugin can't.
 *
 * @package ACPS\SiteToolkit
 */

namespace ACPS\SiteToolkit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Failsafe.
 */
class Failsafe {

	/**
	 * Files the plugin cannot run without. If any of these is missing we keep
	 * the plugin dormant rather than risk a "class not found" fatal mid-request.
	 *
	 * Paths are relative to the plugin root (ACPS_ST_PATH).
	 *
	 * @return string[]
	 */
	public static function required_files() {
		return array(
			// Core engine.
			'includes/class-plugin.php',
			'includes/class-settings.php',
			'includes/class-schema.php',
			'includes/class-rest-controller.php',
			'includes/class-session.php',
			'includes/class-privacy.php',
			'includes/class-integrations.php',
			// Forms + submissions.
			'includes/class-form.php',
			'includes/class-form-renderer.php',
			'includes/class-field-types.php',
			'includes/class-entries.php',
			'includes/class-submission.php',
			'includes/class-access.php',
			'includes/class-spam.php',
			'includes/class-notifications.php',
			// Feedback / help / logging surfaces.
			'includes/class-feedback.php',
			'includes/class-help.php',
			'includes/class-log.php',
			'includes/class-error-log.php',
			// Analytics + visitors.
			'includes/class-analytics.php',
			'includes/class-visitors.php',
			'includes/class-tracking.php',
			// Lifecycle.
			'includes/class-activator.php',
			'includes/class-deactivator.php',
			// Admin.
			'includes/admin/class-admin.php',
		);
	}

	/**
	 * Which required files are missing right now.
	 *
	 * @return string[] Relative paths of any missing files (empty = all present).
	 */
	public static function missing_files() {
		$missing = array();
		foreach ( self::required_files() as $rel ) {
			if ( ! is_readable( ACPS_ST_PATH . $rel ) ) {
				$missing[] = $rel;
			}
		}
		return $missing;
	}

	/**
	 * Run a callable, catching ANY Error or Exception it throws. On failure the
	 * problem is logged and $fallback is returned, so a broken subsystem degrades
	 * quietly instead of crashing the request.
	 *
	 * @param callable $callable The thing to run.
	 * @param array    $args     Positional arguments for the callable.
	 * @param string   $context  Short label for the error log.
	 * @param mixed    $fallback Value to return if it throws.
	 * @return mixed The callable's return value, or $fallback on failure.
	 */
	public static function guard( $callable, $args = array(), $context = '', $fallback = null ) {
		if ( ! is_callable( $callable ) ) {
			self::log_msg( 'not callable', $context );
			return $fallback;
		}
		try {
			return call_user_func_array( $callable, (array) $args );
		} catch ( \Throwable $e ) { // PHP 7+: Error and Exception both.
			self::log( $e, $context );
			return $fallback;
		}
	}

	/**
	 * Wrap an action callback so a throw can never bubble up to the request.
	 * Actions have no return value, so a caught failure simply produces nothing.
	 *
	 * @param callable $callable Original callback.
	 * @param string   $context  Label for the log.
	 * @return \Closure
	 */
	public static function wrap_action( $callable, $context = '' ) {
		return function () use ( $callable, $context ) {
			return self::guard( $callable, func_get_args(), $context, null );
		};
	}

	/**
	 * Wrap a shortcode / render callback. On failure it returns a safe empty
	 * string (or a small editor-only notice) rather than crashing the page the
	 * form is embedded on.
	 *
	 * @param callable $callable Original render callback.
	 * @param string   $context  Label for the log.
	 * @return \Closure
	 */
	public static function wrap_render( $callable, $context = '' ) {
		return function () use ( $callable, $context ) {
			$out = self::guard( $callable, func_get_args(), $context, '__acps_failed__' );
			if ( '__acps_failed__' === $out ) {
				// Only editors see that something broke; visitors see nothing.
				if ( function_exists( 'current_user_can' ) && current_user_can( 'edit_posts' ) ) {
					return '<p class="acps-form-missing">'
						. esc_html__( 'This content could not be displayed. The site owner has been notified.', 'acps-site-toolkit' )
						. '</p>';
				}
				return '';
			}
			return is_string( $out ) ? $out : '';
		};
	}

	/**
	 * Wrap a filter callback. Filters MUST return a value, so on failure we
	 * return the first argument unchanged (a safe pass-through).
	 *
	 * @param callable $callable Original filter callback.
	 * @param string   $context  Label for the log.
	 * @return \Closure
	 */
	public static function wrap_filter( $callable, $context = '' ) {
		return function () use ( $callable, $context ) {
			$args = func_get_args();
			$passthrough = isset( $args[0] ) ? $args[0] : null;
			return self::guard( $callable, $args, $context, $passthrough );
		};
	}

	/**
	 * Log a caught throwable to the PHP error log (never to the screen).
	 *
	 * @param \Throwable $e       The caught error/exception.
	 * @param string     $context Short label.
	 */
	public static function log( $e, $context = '' ) {
		self::log_msg(
			get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(),
			$context
		);
	}

	/**
	 * Low-level logger.
	 *
	 * @param string $message Message.
	 * @param string $context Context label.
	 */
	private static function log_msg( $message, $context = '' ) {
		if ( function_exists( 'error_log' ) ) {
			error_log( '[Cayden Form Manager] Failsafe caught' . ( $context ? " [{$context}]" : '' ) . ': ' . $message ); // phpcs:ignore
		}
	}

	/**
	 * Admin notice listing missing files, shown while the plugin is held dormant
	 * by the integrity guard. Tells the admin exactly what to re-upload.
	 *
	 * @param string[] $missing Relative paths of missing files.
	 */
	public static function missing_files_notice( $missing ) {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>'
			. esc_html__( 'Cayden Form Manager is paused — some plugin files are missing.', 'acps-site-toolkit' )
			. '</strong> '
			. esc_html__( 'To keep your site online, the plugin will not run until the files below are restored. Re-upload the plugin (or re-run the update) to fix this.', 'acps-site-toolkit' )
			. '</p><ul style="list-style:disc;margin-left:1.5rem">';
		foreach ( $missing as $rel ) {
			echo '<li><code>' . esc_html( $rel ) . '</code></li>';
		}
		echo '</ul></div>';
	}
}
