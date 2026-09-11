<?php
/**
 * Plugin Name:       WPCode Values for Beaver Builder
 * Plugin URI:        https://acpsmd.org
 * Description:       Reads the settings out of your WPCode snippets - configurations arrays and anything marked // Configurable - and puts them on a Beaver Builder module, so a page editor can change them per page.
 * Version:           7.4.1
 * Requires at least: 5.8
 * Requires PHP:      7.0
 * Author:            ACPS
 * Text Domain:       wpcode-bb-values
 *
 * ---------------------------------------------------------------------
 * DESIGN
 * ---------------------------------------------------------------------
 * A WPCode snippet keeps its settings in a JavaScript array:
 *
 *     var configurations = [ {key: 'eventColor', value: 'blue'}, ... ];
 *
 * Those values are literals in the script the snippet prints, so they
 * cannot be passed in as shortcode attributes. This plugin reads that
 * array, gives every setting in it a field on the Beaver Builder
 * module, and writes the edited values back into the snippet's output
 * on its way to the browser - only on the page holding that module.
 *
 * A snippet may hold several such arrays (configurations,
 * configurationsFooter, ...), each grouped separately, and any
 * assignment carrying a "// Configurable" comment is editable too,
 * wherever it sits in the file.
 *
 * The field list therefore does depend on the database, but its SHAPE
 * is held to what Beaver Builder can be relied on to render. Two rules,
 * both learned the hard way:
 *
 *  - Field 'type' is only ever 'text', 'select' or 'textarea'. Beaver
 *    Builder turns a field's type into a file it loads while rendering
 *    the settings form, so an invented type does not degrade: the
 *    include fails, PHP prints a warning into the middle of the AJAX
 *    response, and Beaver Builder reports a plugin conflict the moment
 *    you open the module to edit it. An earlier version of this plugin
 *    did exactly that with a made-up 'html' field type.
 *  - 'toggle' is used in exactly one place - the snippet picker, to hide
 *    every other snippet's settings - and it takes lists of NAMES that
 *    exist elsewhere in this same form: section slugs and field names,
 *    as strings. An earlier version handed it field DEFINITIONS
 *    instead, leaving the form pointing at fields that were never
 *    registered. That is the shape to keep in mind before touching it.
 *
 * The plugin hooks 'init' (register the module), 'admin_menu' (a
 * read-only help screen) and two snippet-save hooks that only clear a
 * cache. It filters nothing Beaver Builder owns. The snippet's own
 * output is buffered, so a stray notice from a snippet cannot land in
 * the middle of a Beaver Builder AJAX response either.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Double-load guard. This is a constant check on purpose: PHP
 * early-binds an unconditional class declaration at compile time, so
 * guarding on a class this file declares would already be true on the
 * first load and skip the whole plugin.
 */
if ( defined( 'WPCODEBBV_VERSION' ) ) {
	return;
}

define( 'WPCODEBBV_VERSION', '7.4.1' );
define( 'WPCODEBBV_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPCODEBBV_URL', plugin_dir_url( __FILE__ ) );

/** Cache key for the scan of every snippet's configurations array. */
define( 'WPCODEBBV_CACHE', 'wpcodebbv_settings_index' );

/** Option holding the site-wide values. */
define( 'WPCODEBBV_OPTION', 'wpcodebbv_global_values' );

/* ---------------------------------------------------------------------
 * Update system (ported from the ACPS Site Toolkit updater; see
 * UPDATE-SYSTEM.md for how the pieces fit together).
 * ------------------------------------------------------------------ */

define( 'WPCODEBBV_FILE', __FILE__ );
define( 'WPCODEBBV_BASENAME', plugin_basename( __FILE__ ) );
define( 'WPCODEBBV_REST_NAMESPACE', 'wpcode-bb-values/v1' );
define( 'WPCODEBBV_OPT_SETTINGS', 'wpcodebbv_settings' );

/** Option holding "safe mode" state after a fatal was caught in our own code. */
define( 'WPCODEBBV_SAFE_MODE_OPT', 'wpcodebbv_safe_mode' );

/**
 * Problems found while loading, surfaced as one admin notice instead of
 * a white screen.
 *
 * @var string[]
 */
$GLOBALS['wpcodebbv_load_errors'] = array();

/**
 * Loads one of the plugin's own files without ever letting it take the
 * site down.
 *
 * A missing file is skipped. A file that cannot be compiled - a parse
 * error left by a half-finished edit or a truncated upload - throws
 * ParseError on PHP 7+, which is a Throwable and so catchable here even
 * though it happens at compile time. Either way the feature in that
 * file is the only thing lost, and the loss is recorded rather than
 * swallowed.
 *
 * @param string $relative Path under the plugin directory.
 * @return bool True when the file is loaded and usable.
 */
function wpcodebbv_safe_require( $relative ) {
	$path = WPCODEBBV_DIR . ltrim( $relative, '/' );

	if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
		$GLOBALS['wpcodebbv_load_errors'][] = sprintf(
			/* translators: %s: file path */
			__( 'Missing or unreadable: %s', 'wpcode-bb-values' ),
			$relative
		);

		return false;
	}

	try {
		require_once $path;

		return true;
	} catch ( \Throwable $e ) {
		$GLOBALS['wpcodebbv_load_errors'][] = sprintf(
			/* translators: 1: file path, 2: error message */
			__( 'Could not load %1$s: %2$s', 'wpcode-bb-values' ),
			$relative,
			$e->getMessage()
		);

		wpcodebbv_log( 'could not load ' . $relative . ': ' . $e->getMessage() );

		return false;
	}
}

/**
 * Runs a hook callback with a net under it.
 *
 * Every hook this plugin registers goes through here, so a throw in one
 * of them costs that one feature for that one request instead of the
 * page. Filters hand back the value they were given, which is always a
 * safe answer: it means "I changed nothing".
 *
 * @param string   $hook
 * @param callable $callback
 * @param int      $priority
 * @param int      $args
 * @param bool     $is_filter Return the first argument on failure.
 */
function wpcodebbv_safe_hook( $hook, $callback, $priority = 10, $args = 1, $is_filter = false ) {
	add_filter(
		$hook,
		function () use ( $callback, $hook, $is_filter ) {
			$passed = func_get_args();

			try {
				return call_user_func_array( $callback, $passed );
			} catch ( \Throwable $e ) {
				wpcodebbv_log( 'error in ' . $hook . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );

				return $is_filter && isset( $passed[0] ) ? $passed[0] : null;
			}
		},
		$priority,
		$args
	);
}

/**
 * Shows what failed to load, once, to someone who can act on it. The
 * rest of the plugin - and the site - carries on regardless.
 */
function wpcodebbv_load_errors_notice() {
	if ( empty( $GLOBALS['wpcodebbv_load_errors'] ) || ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	?>
	<div class="notice notice-error">
		<p>
			<strong><?php esc_html_e( 'WPCode Values for Beaver Builder could not load part of itself.', 'wpcode-bb-values' ); ?></strong>
			<?php esc_html_e( 'Those features are switched off; everything else, and the rest of the site, is unaffected. This usually means an incomplete upload - try installing the plugin again.', 'wpcode-bb-values' ); ?>
		</p>
		<ul style="list-style: disc; padding-left: 20px;">
			<?php foreach ( $GLOBALS['wpcodebbv_load_errors'] as $wpcodebbv_error ) : ?>
				<li><code><?php echo esc_html( $wpcodebbv_error ); ?></code></li>
			<?php endforeach; ?>
		</ul>
	</div>
	<?php
}

wpcodebbv_safe_require( 'includes/class-wpcodebbv-scanner.php' );
wpcodebbv_safe_require( 'includes/class-wpcodebbv-settings.php' );
wpcodebbv_safe_require( 'includes/class-wpcodebbv-updater.php' );

if ( ! empty( $GLOBALS['wpcodebbv_load_errors'] ) ) {
	wpcodebbv_safe_hook( 'admin_notices', 'wpcodebbv_load_errors_notice' );
}

/* ---------------------------------------------------------------------
 * Bootstrap with crash protection.
 *
 * A fatal inside this plugin arms safe mode, and the NEXT request loads
 * only a notice with a Resume button instead of the plugin's code - so a
 * bad update cannot white-screen the site. This matters more than usual
 * here because the plugin can now update itself.
 * ------------------------------------------------------------------ */

/**
 * Is the plugin currently held dormant after a caught fatal?
 *
 * @return bool
 */
function wpcodebbv_is_safe_mode() {
	$state = get_option( WPCODEBBV_SAFE_MODE_OPT );

	return is_array( $state ) && ! empty( $state['time'] );
}

/**
 * Records a caught fatal and arms safe mode for the next request.
 *
 * @param string $message
 * @param string $file
 * @param int    $line
 */
function wpcodebbv_arm_safe_mode( $message, $file = '', $line = 0 ) {
	update_option(
		WPCODEBBV_SAFE_MODE_OPT,
		array(
			'msg'  => (string) $message,
			'file' => (string) $file,
			'line' => (int) $line,
			'time' => time(),
		),
		true
	);

	wpcodebbv_log( 'fatal caught - entering safe mode: ' . $message . ' in ' . $file . ':' . $line );
}

/**
 * Shutdown guard. If the request is ending on a fatal that started in
 * THIS plugin's files, arm safe mode so the next request stays up. It
 * cannot rescue the current request - PHP is already ending - but it
 * stops a crash loop. Fatals from anywhere else are left alone.
 */
function wpcodebbv_shutdown_guard() {
	$error = error_get_last();

	if ( ! $error || empty( $error['type'] ) ) {
		return;
	}

	if ( ! in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {
		return;
	}

	if ( empty( $error['file'] ) || 0 !== strpos( $error['file'], WPCODEBBV_DIR ) ) {
		return; // Not ours.
	}

	wpcodebbv_arm_safe_mode( $error['message'], $error['file'], $error['line'] );
}

/**
 * The notice shown while dormant, with the control that resumes.
 */
function wpcodebbv_safe_mode_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	$state   = get_option( WPCODEBBV_SAFE_MODE_OPT );
	$message = is_array( $state ) && ! empty( $state['msg'] ) ? $state['msg'] : '';
	$url     = wp_nonce_url( admin_url( 'admin-post.php?action=wpcodebbv_resume' ), 'wpcodebbv_resume' );
	?>
	<div class="notice notice-error">
		<p>
			<strong><?php esc_html_e( 'WPCode Values for Beaver Builder is paused (safe mode).', 'wpcode-bb-values' ); ?></strong>
			<?php esc_html_e( 'A fatal error was caught in the plugin, so it stopped loading to keep the site online. The rest of the site is unaffected.', 'wpcode-bb-values' ); ?>
		</p>
		<?php if ( '' !== $message ) : ?>
			<p><code><?php echo esc_html( $message ); ?></code></p>
		<?php endif; ?>
		<p>
			<a href="<?php echo esc_url( $url ); ?>" class="button button-primary"><?php esc_html_e( 'Resume plugin', 'wpcode-bb-values' ); ?></a>
			<?php esc_html_e( 'Use this once the problem is fixed - after a corrected update, say.', 'wpcode-bb-values' ); ?>
		</p>
	</div>
	<?php
}

/**
 * Clears safe mode.
 */
function wpcodebbv_resume_from_safe_mode() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		wp_die( esc_html__( 'You do not have permission to do this.', 'wpcode-bb-values' ), 403 );
	}

	check_admin_referer( 'wpcodebbv_resume' );
	delete_option( WPCODEBBV_SAFE_MODE_OPT );
	wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
	exit;
}

/**
 * Boots the update system. The plugin's own features register on their
 * own hooks below and are deliberately NOT gated on this - safe mode is
 * about not compounding a fatal, and the updater is the part that can
 * introduce one.
 */
function wpcodebbv_boot() {
	// Resuming has to work even while dormant.
	wpcodebbv_safe_hook( 'admin_post_wpcodebbv_resume', 'wpcodebbv_resume_from_safe_mode' );

	if ( wpcodebbv_is_safe_mode() ) {
		if ( is_admin() ) {
			wpcodebbv_safe_hook( 'admin_notices', 'wpcodebbv_safe_mode_notice' );
		}

		return; // Stay dormant - keep the site up.
	}

	register_shutdown_function( 'wpcodebbv_shutdown_guard' );

	try {
		if ( class_exists( 'WPCodeBBV_Updater' ) && class_exists( 'WPCodeBBV_Settings' ) ) {
			$updater = new WPCodeBBV_Updater();
			$updater->register();

			wpcodebbv_safe_hook( 'update_option_' . WPCODEBBV_OPT_SETTINGS, array( 'WPCodeBBV_Updater', 'flush_cache' ) );
		}
	} catch ( \Throwable $e ) {
		wpcodebbv_arm_safe_mode( $e->getMessage(), $e->getFile(), $e->getLine() );
	}
}
wpcodebbv_safe_hook( 'plugins_loaded', 'wpcodebbv_boot' );

/**
 * Activation: seed the update secret and clear any rollback flag left by
 * a previous failed update.
 */
function wpcodebbv_activate() {
	if ( class_exists( 'WPCodeBBV_Settings' ) ) {
		WPCodeBBV_Settings::seed_trigger();
	}

	delete_option( 'wpcodebbv_update_failed' );
	delete_option( WPCODEBBV_SAFE_MODE_OPT );
}
register_activation_hook( __FILE__, 'wpcodebbv_activate' );

/**
 * Writes to the PHP error log, prefixed so it is easy to grep for.
 *
 * @param string $message
 */
function wpcodebbv_log( $message ) {
	if ( function_exists( 'error_log' ) ) {
		error_log( '[WPCode Values] ' . $message );
	}
}

/**
 * Reads a configurable value from inside a PHP snippet.
 *
 * A PHP snippet is EXECUTED by WPCode, so its source never appears in
 * the output and there is nothing for this plugin to rewrite on the way
 * to the browser - the trick that works for JavaScript and CSS simply
 * does not apply. A PHP snippet therefore asks for its values instead:
 *
 *     $api_key = wpcodebbv_cfg( 'api_key', 'AIza-DEFAULT' );  // Configurable
 *
 * The default written here is what the module shows and what applies
 * when nothing has been changed; the module supplies anything the page
 * editor overrode. Outside this plugin's module - a snippet placed by
 * shortcode somewhere else - there is no override and the default is
 * returned, so the same snippet keeps working anywhere.
 *
 * The value is returned in the SHAPE of the default: a boolean default
 * gets a boolean back, a number a number, an array an array. Values
 * arrive from the editor as text, and a snippet should not have to
 * think about that.
 *
 * @param string $name    The name used in the module.
 * @param mixed  $default The value to use when nothing overrides it.
 * @return mixed
 */
function wpcodebbv_cfg( $name, $default = '' ) {
	$raw = null;

	// The module sets this while it renders, and it is snippet-specific,
	// so it wins when present.
	if ( isset( $GLOBALS['wpcode_bb_values'][ $name ] ) ) {
		$raw = (string) $GLOBALS['wpcode_bb_values'][ $name ];
	} elseif ( function_exists( 'wpcodebbv_globals' ) ) {
		// Otherwise read the stored value. PHP values are always
		// site-wide, and this is what makes that mean something: a PHP
		// snippet gets its configured value wherever it runs, including
		// where this plugin's module is nowhere in sight - a WPCode
		// auto-insert, a shortcode in a template, another page entirely.
		//
		// This runs INSIDE somebody's snippet, so it must never be the
		// thing that breaks their page: anything unexpected here falls
		// through to the default rather than throwing.
		try {
			foreach ( wpcodebbv_globals() as $snippet_values ) {
				if ( is_array( $snippet_values ) && isset( $snippet_values[ $name ] ) ) {
					$raw = (string) $snippet_values[ $name ];
					break;
				}
			}
		} catch ( \Throwable $e ) {
			$raw = null;
		}
	}

	if ( null === $raw ) {
		return $default;
	}

	if ( is_bool( $default ) ) {
		return in_array( strtolower( trim( $raw ) ), array( 'true', '1', 'yes', 'on' ), true );
	}

	if ( is_int( $default ) ) {
		return (int) $raw;
	}

	if ( is_float( $default ) ) {
		return (float) $raw;
	}

	if ( is_array( $default ) ) {
		if ( '' === trim( $raw ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map( 'trim', explode( ',', $raw ) ),
				function ( $part ) {
					return '' !== $part;
				}
			)
		);
	}

	return $raw;
}



/*
 * Everything else - the scanner-facing helpers, the module's field
 * schema, the admin screen - lives in one file loaded through
 * wpcodebbv_safe_require() above. Keeping it out of THIS file is the
 * point: a plugin's main file is compiled by PHP before any of its own
 * code can run, so nothing here can catch a parse error in it. Shrinking
 * this file to the loader, the crash guards and the two functions that
 * outside code calls leaves almost nothing that can break unprotected -
 * and a broken features file costs the features, not the site.
 */
wpcodebbv_safe_require( 'includes/functions-core.php' );
