<?php
/**
 * Plugin Name:       WPCode Values for Beaver Builder
 * Plugin URI:        https://acpsmd.org
 * Description:       Reads the settings out of your WPCode snippets - configurations arrays and anything marked // Configurable - and puts them on a Beaver Builder module, so a page editor can change them per page.
 * Version:           7.1.0
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

define( 'WPCODEBBV_VERSION', '7.1.0' );
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

foreach ( array( 'class-wpcodebbv-scanner.php', 'class-wpcodebbv-settings.php', 'class-wpcodebbv-updater.php' ) as $wpcodebbv_include ) {
	$wpcodebbv_path = WPCODEBBV_DIR . 'includes/' . $wpcodebbv_include;

	if ( file_exists( $wpcodebbv_path ) ) {
		require_once $wpcodebbv_path;
	}
}

unset( $wpcodebbv_include, $wpcodebbv_path );

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
	add_action( 'admin_post_wpcodebbv_resume', 'wpcodebbv_resume_from_safe_mode' );

	if ( wpcodebbv_is_safe_mode() ) {
		if ( is_admin() ) {
			add_action( 'admin_notices', 'wpcodebbv_safe_mode_notice' );
		}

		return; // Stay dormant - keep the site up.
	}

	register_shutdown_function( 'wpcodebbv_shutdown_guard' );

	try {
		if ( class_exists( 'WPCodeBBV_Updater' ) && class_exists( 'WPCodeBBV_Settings' ) ) {
			$updater = new WPCodeBBV_Updater();
			$updater->register();

			add_action( 'update_option_' . WPCODEBBV_OPT_SETTINGS, array( 'WPCodeBBV_Updater', 'flush_cache' ) );
		}
	} catch ( \Throwable $e ) {
		wpcodebbv_arm_safe_mode( $e->getMessage(), $e->getFile(), $e->getLine() );
	}
}
add_action( 'plugins_loaded', 'wpcodebbv_boot' );

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
 * The code of one WPCode snippet.
 *
 * WPCode's storage is not a public API, so this looks in the post
 * content first and then at the meta keys WPCode has used, and returns
 * an empty string if none of them pan out.
 *
 * @param int|WP_Post $snippet Post ID or object.
 * @return string
 */
function wpcodebbv_snippet_code( $snippet ) {
	$post = is_object( $snippet ) ? $snippet : get_post( (int) $snippet );

	if ( ! is_object( $post ) || empty( $post->ID ) ) {
		return '';
	}

	$code = isset( $post->post_content ) ? (string) $post->post_content : '';

	if ( '' !== trim( $code ) ) {
		return $code;
	}

	foreach ( array( '_wpcode_code', 'wpcode_code', '_wpcode_snippet_code' ) as $meta_key ) {
		$stored = get_post_meta( $post->ID, $meta_key, true );

		if ( is_string( $stored ) && '' !== trim( $stored ) ) {
			return $stored;
		}
	}

	return '';
}

/**
 * True only inside the Beaver Builder editor, for someone allowed to
 * edit pages.
 *
 * Both halves matter. is_builder_active() is false on the live page and
 * in Beaver Builder's own preview, so a visitor never reaches this; the
 * capability check means that even if some other code left the builder
 * flag set, a logged-out visitor still sees nothing. The note this
 * gates is editorial chatter about snippet IDs and settings - useful to
 * whoever is building the page, noise or worse to everybody else.
 *
 * @return bool
 */
function wpcodebbv_is_editing() {
	if ( ! class_exists( 'FLBuilderModel' ) || ! is_callable( array( 'FLBuilderModel', 'is_builder_active' ) ) ) {
		return false;
	}

	if ( ! FLBuilderModel::is_builder_active() ) {
		return false;
	}

	return is_user_logged_in() && current_user_can( 'edit_posts' );
}

/**
 * The note shown above the snippet while the page is being edited:
 * which snippet this module runs and what has been changed on it.
 *
 * @param int   $snippet_id
 * @param array $overrides  What this module is about to apply.
 * @return string HTML, or '' when there is nothing to say.
 */
function wpcodebbv_editor_note( $snippet_id, $overrides = array() ) {
	$snippet_id = (int) $snippet_id;
	$snippets   = array();

	try {
		$snippets = wpcodebbv_snippets();
	} catch ( \Throwable $e ) {
		$snippets = array();
	}

	$known = isset( $snippets[ $snippet_id ] ) ? $snippets[ $snippet_id ] : null;
	$title = $known ? $known['title'] : '';

	$lines = array();

	if ( $known ) {
		$total     = count( $known['settings'] );
		$site_wide = 0;
		$php       = 0;

		foreach ( $known['settings'] as $leaf ) {
			if ( ! empty( $leaf['php'] ) ) {
				$php++;
			} elseif ( ! empty( $leaf['global'] ) ) {
				$site_wide++;
			}
		}

		$changed = count( (array) $overrides );

		$lines[] = sprintf(
			/* translators: 1: number of settings, 2: number changed here */
			_n( '%1$d setting, %2$d changed here.', '%1$d settings, %2$d changed here.', $total, 'wpcode-bb-values' ),
			$total,
			$changed
		);

		if ( $site_wide ) {
			$lines[] = sprintf(
				/* translators: %d: number of settings */
				_n( '%d is marked siteWide - changing it changes every page.', '%d are marked siteWide - changing them changes every page.', $site_wide, 'wpcode-bb-values' ),
				$site_wide
			);
		}

		if ( $php ) {
			$lines[] = sprintf(
				/* translators: %d: number of settings */
				_n( '%d is a PHP value, which is always site-wide.', '%d are PHP values, which are always site-wide.', $php, 'wpcode-bb-values' ),
				$php
			);
		}

		$lines[] = __( 'Edit them on this module\'s Settings tab.', 'wpcode-bb-values' );
	} else {
		$lines[] = __( 'This snippet\'s settings could not be read, so the Settings tab is empty. Use Extra settings on the Setup tab, as "path = value" lines.', 'wpcode-bb-values' );
	}

	$heading = $title
		? sprintf(
			/* translators: 1: snippet title, 2: snippet ID */
			__( '%1$s - [wpcode id="%2$d"]', 'wpcode-bb-values' ),
			$title,
			$snippet_id
		)
		: sprintf(
			/* translators: %d: snippet ID */
			__( '[wpcode id="%d"]', 'wpcode-bb-values' ),
			$snippet_id
		);

	return '<div class="wpcodebbv-editor-note">'
		. '<span class="wpcodebbv-editor-note-tag">' . esc_html__( 'WPCode Values', 'wpcode-bb-values' ) . '</span> '
		. '<strong>' . esc_html( $heading ) . '</strong> '
		. esc_html( implode( ' ', $lines ) ) . ' '
		. '<span class="wpcodebbv-editor-note-only">'
		. esc_html__( 'Only you see this, while editing. Visitors get just the snippet.', 'wpcode-bb-values' )
		. '</span>'
		. '</div>';
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

/**
 * Scans every WPCode snippet and returns what was found, keyed by
 * snippet ID:
 *
 *     7 => array(
 *         'title'    => 'ACPS Calendar',
 *         'settings' => array( 'noSchoolEvent.badgeText' => array( 'value' => 'No School', 'kind' => 'string' ), ... ),
 *     )
 *
 * WPCode's storage is not a public API, so this looks in the snippet's
 * post content first and then at the meta keys WPCode has used, and
 * simply finds nothing if none of them pan out.
 *
 * Cached, and the cache is dropped whenever a snippet is saved.
 *
 * @param bool $force Skip the cache.
 * @return array<int, array{title:string, settings:array}>
 */
function wpcodebbv_snippets( $force = false ) {
	if ( ! $force ) {
		$cached = get_transient( WPCODEBBV_CACHE );

		if ( is_array( $cached ) ) {
			return $cached;
		}
	}

	$found = array();

	if ( ! class_exists( 'WPCodeBBV_Scanner' ) || ! post_type_exists( 'wpcode' ) ) {
		set_transient( WPCODEBBV_CACHE, $found, HOUR_IN_SECONDS );

		return $found;
	}

	$snippets = get_posts(
		array(
			'post_type'        => 'wpcode',
			'post_status'      => array( 'publish', 'draft' ),
			'posts_per_page'   => 50,
			'no_found_rows'    => true,
			'suppress_filters' => true,
		)
	);

	if ( ! is_array( $snippets ) ) {
		$snippets = array();
	}

	foreach ( $snippets as $snippet ) {
		if ( ! is_object( $snippet ) || empty( $snippet->ID ) ) {
			continue;
		}

		$code = wpcodebbv_snippet_code( $snippet );

		if ( '' === trim( $code ) ) {
			continue;
		}

		try {
			$arrays = WPCodeBBV_Scanner::scan( $code );
		} catch ( \Throwable $e ) {
			wpcodebbv_log( 'could not scan snippet ' . (int) $snippet->ID . ': ' . $e->getMessage() );
			continue;
		}

		$settings = array();

		// A snippet may hold more than one configurations array, and may
		// also mark settings with a "Configurable" comment anywhere else
		// in the file. Both end up here, kept apart so two arrays that
		// happen to use the same key stay separately editable.
		$many_arrays = count( $arrays ) > 1;

		foreach ( $arrays as $array ) {
			foreach ( $array['settings'] as $path => $leaf ) {
				$dot   = strpos( $path, '.' );
				$block = false === $dot ? '' : substr( $path, 0, $dot );
				$label = false === $dot ? $path : substr( $path, $dot + 1 );

				// Scoping a path by its array's name is what makes two
				// arrays in one snippet addressable; the scanner accepts
				// that "name:path" form when applying overrides.
				$key = $many_arrays ? $array['name'] . ':' . $path : $path;

				if ( isset( $settings[ $key ] ) ) {
					continue;
				}

				$group = '' === $block ? __( 'General', 'wpcode-bb-values' ) : $block;

				if ( $many_arrays ) {
					$group = $array['name'] . ' - ' . $group;
				}

				$settings[ $key ] = array(
					'value'      => (string) $leaf['value'],
					'kind'       => $leaf['kind'],
					'comment'    => isset( $leaf['comment'] ) ? (string) $leaf['comment'] : '',
					'global'     => ! empty( $leaf['global'] ),
					'group'      => $group,
					'label'      => $label,
					'php'        => false,
					'php_static' => false,
				);
			}
		}

		try {
			$marked = WPCodeBBV_Scanner::scan_markers( $code );
		} catch ( \Throwable $e ) {
			wpcodebbv_log( 'could not read Configurable markers in snippet ' . (int) $snippet->ID . ': ' . $e->getMessage() );
			$marked = array();
		}

		foreach ( $marked as $name => $leaf ) {
			if ( isset( $settings[ $name ] ) ) {
				continue; // An array setting of the same name already won.
			}

			$settings[ $name ] = array(
				'value'      => (string) $leaf['value'],
				'kind'       => $leaf['kind'],
				'comment'    => isset( $leaf['comment'] ) ? (string) $leaf['comment'] : '',
				'global'     => ! empty( $leaf['global'] ),
				'group'      => empty( $leaf['php'] )
					? __( 'Marked variables', 'wpcode-bb-values' )
					: __( 'PHP values (site-wide)', 'wpcode-bb-values' ),
				'label'      => $name,
				'php'        => ! empty( $leaf['php'] ),
				'php_static' => ! empty( $leaf['php_static'] ),
			);
		}

		if ( empty( $settings ) ) {
			continue;
		}

		$found[ (int) $snippet->ID ] = array(
			'title'    => isset( $snippet->post_title ) && '' !== $snippet->post_title
				? $snippet->post_title
				: sprintf( '#%d', (int) $snippet->ID ),
			'settings' => $settings,
		);
	}

	set_transient( WPCODEBBV_CACHE, $found, DAY_IN_SECONDS );

	return $found;
}

/**
 * Drops the cached scan when a snippet is edited.
 */
function wpcodebbv_clear_index() {
	delete_transient( WPCODEBBV_CACHE );
}
add_action( 'save_post_wpcode', 'wpcodebbv_clear_index' );
add_action( 'deleted_post', 'wpcodebbv_clear_index' );

/**
 * The Beaver Builder setting name for one snippet's setting. Derived,
 * never stored, so the module can work back to the path at render time
 * from the snippet ID alone.
 *
 * @param int    $snippet_id
 * @param string $path
 * @return string
 */
function wpcodebbv_field_key( $snippet_id, $path ) {
	$safe = preg_replace( '/[^A-Za-z0-9]/', '_', $path );

	// Array paths only ever use these characters, so they keep the plain
	// key they have always had. Marked names can contain $ and -, which
	// both flatten to "_" and could collide ($_x and --x both become
	// __x), so those get a short digest of the real path appended.
	if ( ! preg_match( '/^[A-Za-z0-9._:]+$/', $path ) ) {
		$safe .= '_' . substr( md5( $path ), 0, 6 );
	}

	return 's' . (int) $snippet_id . '_' . $safe;
}

/**
 * True when a snippet's value is being used as a yes/no flag. These are
 * usually written as the strings 'true' and 'false' rather than real
 * booleans, and either way they should be a dropdown rather than a text
 * box someone can typo into.
 *
 * @param string $value
 * @return bool
 */
function wpcodebbv_is_boolean( $value ) {
	return in_array( strtolower( trim( (string) $value ) ), array( 'true', 'false' ), true );
}

/**
 * Handles the "reset site-wide values" button on Tools > WPCode Values.
 * Nothing else is editable there: which settings are site-wide is
 * decided by the snippet, and their values are set by editing a module.
 */
function wpcodebbv_handle_reset() {
	if ( ! isset( $_POST['wpcodebbv_reset'], $_POST['wpcodebbv_globals_nonce'] ) ) {
		return;
	}

	if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['wpcodebbv_globals_nonce'] ) ), 'wpcodebbv_globals' ) ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	wpcodebbv_reset_globals( (int) $_POST['wpcodebbv_reset'] );

	add_settings_error( 'wpcodebbv', 'wpcodebbv_reset', __( 'Site-wide values cleared. Those settings now use the values written in the snippet.', 'wpcode-bb-values' ), 'updated' );
}

/**
 * The stored site-wide values, as snippet ID => path => value.
 *
 * Which settings are site-wide is decided in the snippet, not here: a
 * setting marked siteWide is edited on any module and the value is kept
 * here, so every module running that snippet picks it up. Everything
 * else stays on the module that was edited.
 *
 * @return array<int, array<string, string>>
 */
function wpcodebbv_globals() {
	$stored = get_option( WPCODEBBV_OPTION, array() );

	return is_array( $stored ) ? $stored : array();
}

/**
 * Stored site-wide values for one snippet.
 *
 * @param int $snippet_id
 * @return array<string, string>
 */
function wpcodebbv_globals_for( $snippet_id ) {
	$all = wpcodebbv_globals();

	return isset( $all[ (int) $snippet_id ] ) && is_array( $all[ (int) $snippet_id ] )
		? $all[ (int) $snippet_id ]
		: array();
}

/**
 * Records a new value for a site-wide setting. Called when a module is
 * saved in the Beaver Builder editor, which is the only place these are
 * edited - one module changes the value for every page.
 *
 * @param int    $snippet_id
 * @param string $path
 * @param string $value Pass '' to drop back to the snippet's own value.
 */
function wpcodebbv_set_global( $snippet_id, $path, $value ) {
	$all        = wpcodebbv_globals();
	$snippet_id = (int) $snippet_id;
	$value      = (string) $value;

	if ( '' === trim( $value ) ) {
		unset( $all[ $snippet_id ][ $path ] );

		if ( isset( $all[ $snippet_id ] ) && empty( $all[ $snippet_id ] ) ) {
			unset( $all[ $snippet_id ] );
		}
	} else {
		$all[ $snippet_id ][ $path ] = $value;
	}

	update_option( WPCODEBBV_OPTION, $all );
}

/**
 * Clears every stored site-wide value for one snippet, so all of them
 * fall back to what the snippet itself says.
 *
 * @param int $snippet_id
 */
function wpcodebbv_reset_globals( $snippet_id ) {
	$all = wpcodebbv_globals();

	unset( $all[ (int) $snippet_id ] );

	update_option( WPCODEBBV_OPTION, $all );
}

/**
 * Returns the snippet's configurations array with a comment added to
 * every setting that does not already have one, ready to be pasted back
 * into WPCode. The rest of the snippet is returned exactly as written -
 * the comments are inserted at the end of the lines they describe and
 * nothing else is touched.
 *
 * @param string $code
 * @return string
 */
function wpcodebbv_annotate( $code ) {
	if ( ! class_exists( 'WPCodeBBV_Scanner' ) || ! is_string( $code ) || '' === $code ) {
		return (string) $code;
	}

	try {
		$arrays = WPCodeBBV_Scanner::scan( $code );
	} catch ( \Throwable $e ) {
		return $code;
	}

	$insertions = array();

	foreach ( $arrays as $array ) {
		foreach ( $array['settings'] as $path => $leaf ) {
			// Never write over a comment the author already put there.
			if ( ! empty( $leaf['comment'] ) ) {
				continue;
			}

			$line_end = strpos( $code, "\n", $leaf['end'] );
			$line_end = false === $line_end ? strlen( $code ) : $line_end;

			// Two settings on one line share an insertion point; the
			// first one to claim it wins rather than stacking comments.
			if ( isset( $insertions[ $line_end ] ) ) {
				continue;
			}

			$insertions[ $line_end ] = ' // ' . wpcodebbv_describe_base( $path, $leaf );
		}
	}

	if ( empty( $insertions ) ) {
		return $code;
	}

	krsort( $insertions );

	foreach ( $insertions as $offset => $comment ) {
		$code = substr( $code, 0, $offset ) . rtrim( $comment ) . substr( $code, $offset );
	}

	return $code;
}

/**
 * The help text for one setting, shown behind the "?" icon next to its
 * box in Beaver Builder.
 *
 * A comment written next to the setting in the snippet always wins -
 * whoever wrote the snippet knows what it does. Failing that, the name
 * itself says a good deal, so common ones get a real description rather
 * than a restatement of the label. Either way the value the snippet
 * ships with is quoted at the end, which is the thing an editor most
 * often wants to know before changing it.
 *
 * @param string $path
 * @param array  $leaf
 * @return string
 */
function wpcodebbv_describe_base( $path, $leaf ) {
	$comment = isset( $leaf['comment'] ) ? trim( (string) $leaf['comment'] ) : '';
	$name    = strtolower( substr( $path, strrpos( $path, '.' ) === false ? 0 : strrpos( $path, '.' ) + 1 ) );
	$is_list = isset( $leaf['kind'] ) && 'list' === $leaf['kind'];

	if ( '' !== $comment ) {
		// An author's comment rarely ends in a full stop, and it is
		// followed here by more sentences.
		$text = in_array( substr( $comment, -1 ), array( '.', '!', '?', ':' ), true )
			? $comment
			: $comment . '.';
	} elseif ( 'searchforwords' === $name ) {
		$text = __( 'Words to look for in an event\'s title. An event matching any of them is treated as this kind of event.', 'wpcode-bb-values' );
	} elseif ( 'badgetext' === $name ) {
		$text = __( 'The short label printed on the badge for these events.', 'wpcode-bb-values' );
	} elseif ( 'primarycolor' === $name ) {
		$text = __( 'The main colour for these events. A CSS colour name (red, royalblue) or a hex code (#a13c7f).', 'wpcode-bb-values' );
	} elseif ( 'secondarycolor' === $name ) {
		$text = __( 'The secondary colour for these events, used alongside the main one. A CSS colour name or a hex code.', 'wpcode-bb-values' );
	} elseif ( false !== strpos( $name, 'color' ) || false !== strpos( $name, 'colour' ) ) {
		$text = __( 'A CSS colour name (red, royalblue) or a hex code (#a13c7f).', 'wpcode-bb-values' );
	} elseif ( 'background' === $name ) {
		$text = __( 'The background for these events. "auto" lets the snippet work it out from the colours above.', 'wpcode-bb-values' );
	} elseif ( in_array( $name, array( 'activated', 'active', 'enabled' ), true ) ) {
		$text = __( 'Turns this whole rule on or off. Set it to false and these events are treated like any other.', 'wpcode-bb-values' );
	} elseif ( 0 === strpos( $name, 'show' ) || 0 === strpos( $name, 'hide' ) || false !== strpos( $name, 'badge' ) ) {
		$text = __( 'Turns this part of the display on or off.', 'wpcode-bb-values' );
	} elseif ( false !== strpos( $name, 'combine' ) ) {
		$text = __( 'Whether an event running over several days is drawn as one continuous block.', 'wpcode-bb-values' );
	} elseif ( false !== strpos( $name, 'watermark' ) ) {
		$text = __( 'Whether the small credit line is shown.', 'wpcode-bb-values' );
	} elseif ( 'calendarid' === $name || ( false !== strpos( $name, 'calendar' ) && false !== strpos( $name, 'id' ) ) ) {
		$text = __( 'Which calendar the events come from. This is the calendar ID from the calendar\'s own settings page.', 'wpcode-bb-values' );
	} elseif ( 'id' === $name || false !== strpos( $name, 'id' ) ) {
		$text = __( 'An identifier the snippet looks things up by. Change it only if you know what it points at.', 'wpcode-bb-values' );
	} elseif ( false !== strpos( $name, 'name' ) || false !== strpos( $name, 'title' ) || false !== strpos( $name, 'label' ) ) {
		$text = __( 'A name shown on screen.', 'wpcode-bb-values' );
	} elseif ( false !== strpos( $name, 'text' ) ) {
		$text = __( 'Text shown on screen.', 'wpcode-bb-values' );
	} elseif ( false !== strpos( $name, 'url' ) || false !== strpos( $name, 'link' ) ) {
		$text = __( 'A web address.', 'wpcode-bb-values' );
	} else {
		$text = sprintf(
			/* translators: %s: the setting's name */
			__( 'Sets "%s" in the snippet.', 'wpcode-bb-values' ),
			$path
		);
	}

	if ( $is_list ) {
		$text .= ' ' . __( 'Separate several with commas.', 'wpcode-bb-values' );
	}

	return $text;
}

/**
 * The full help text shown behind the "?" icon: the description above,
 * plus what the snippet itself uses, plus the global value when one is
 * set, since that is what this box falls back to.
 *
 * @param string $path
 * @param array  $leaf
 * @param string $global Global value for this setting, or ''.
 * @return string
 */
function wpcodebbv_describe( $path, $leaf, $shared = '' ) {
	$text  = wpcodebbv_describe_base( $path, $leaf );
	$value = (string) $leaf['value'];

	if ( '' !== $value ) {
		$text .= ' ' . sprintf(
			/* translators: %s: the value written in the snippet */
			__( 'The snippet\'s own value is "%s".', 'wpcode-bb-values' ),
			$value
		);
	}

	if ( ! empty( $leaf['php'] ) ) {
		$text .= ' ' . __( 'This is a PHP value, so it is always site-wide: changing it here changes it everywhere the snippet runs, including where this module is not involved at all.', 'wpcode-bb-values' );

		if ( '' !== (string) $shared && (string) $shared !== $value ) {
			$text .= ' ' . sprintf(
				/* translators: %s: the current site-wide value */
				__( 'It is currently set to "%s". Put it back to the snippet\'s own value to clear that.', 'wpcode-bb-values' ),
				$shared
			);
		}

		return $text;
	}

	if ( ! empty( $leaf['global'] ) ) {
		$text .= ' ' . __( 'This one is marked siteWide in the snippet: changing it here changes it on every page that runs this snippet through this module, not just this one.', 'wpcode-bb-values' );

		if ( '' !== (string) $shared && (string) $shared !== $value ) {
			$text .= ' ' . sprintf(
				/* translators: %s: the current site-wide value */
				__( 'It is currently set site-wide to "%s". Put it back to the snippet\'s own value to clear that.', 'wpcode-bb-values' ),
				$shared
			);
		}
	} else {
		$text .= ' ' . __( 'Changing it affects this page only. Clear the box to go back to the snippet\'s value.', 'wpcode-bb-values' );
	}

	return $text;
}

/**
 * The module's field schema.
 *
 * Every setting found in every snippet gets its own field, grouped into
 * one section per snippet and pre-filled with the value the snippet
 * currently uses. Nothing has to be picked from a list: open the module
 * and the settings are already there.
 *
 * Notes on the two things that have broken this plugin before:
 *
 *  - Field TYPES here are only 'text', 'select' and 'textarea'. Beaver
 *    Builder turns a field's type into a file it loads while rendering
 *    the settings form, so an invented type takes the whole form down.
 *  - There is no 'toggle' anywhere. Beaver Builder's toggle expects a
 *    list of field NAMES that exist elsewhere in the form; handing it
 *    field definitions instead, as an earlier version of this plugin
 *    did, leaves the form referring to fields that were never
 *    registered.
 *
 * @return array
 */
function wpcodebbv_form() {
	$sections = array();

	$snippets = array();

	try {
		$snippets = wpcodebbv_snippets();
	} catch ( \Throwable $e ) {
		wpcodebbv_log( 'could not build the settings list: ' . $e->getMessage() );
	}

	$many_snippets = count( $snippets ) > 1;

	// Which sections and fields belong to which snippet, so the module
	// can show only the one it is actually running.
	$owned = array();

	foreach ( $snippets as $snippet_id => $snippet ) {
		$globals = wpcodebbv_globals_for( $snippet_id );

		// Each setting already knows which group it belongs in - a block
		// like noSchoolEvent, an array name when a snippet holds more
		// than one, or "Marked variables" for // Configurable ones.
		$groups = array();

		foreach ( $snippet['settings'] as $path => $leaf ) {
			// A plain PHP $variable or define() is discovered so it can be
			// reported, but it cannot be changed from here - WPCode
			// executes a PHP snippet, so there is no printed source to
			// rewrite. Offering a box for it would be a box that quietly
			// does nothing. Tools > WPCode Values lists these and says
			// what to change them to.
			if ( ! empty( $leaf['php_static'] ) ) {
				continue;
			}

			$groups[ $leaf['group'] ][ $path ] = array(
				'label' => $leaf['label'],
				'leaf'  => $leaf,
			);
		}

		foreach ( $groups as $group => $members ) {
			$fields = array();

			foreach ( $members as $path => $member ) {
				$key    = wpcodebbv_field_key( $snippet_id, $path );
				$leaf   = $member['leaf'];
				$shared = isset( $globals[ $path ] ) ? (string) $globals[ $path ] : '';
				$help   = wpcodebbv_describe( $path, $leaf, $shared );

				// A site-wide setting shows the value in force everywhere,
				// so the box is editing the real thing rather than a copy.
				$current = ! empty( $leaf['global'] ) && '' !== $shared
					? $shared
					: (string) $leaf['value'];

				if ( ! empty( $leaf['php'] ) ) {
					$label = $member['label'] . ' ' . __( '(PHP, site-wide)', 'wpcode-bb-values' );
				} elseif ( ! empty( $leaf['global'] ) ) {
					$label = $member['label'] . ' ' . __( '(site-wide)', 'wpcode-bb-values' );
				} else {
					$label = $member['label'];
				}

				if ( wpcodebbv_is_boolean( $current ) ) {
					// A yes/no setting can only ever be true or false.
					$fields[ $key ] = array(
						'type'    => 'select',
						'label'   => $label,
						'default' => strtolower( trim( $current ) ),
						'options' => array(
							'true'  => __( 'true', 'wpcode-bb-values' ),
							'false' => __( 'false', 'wpcode-bb-values' ),
						),
						'help'    => $help,
					);

					continue;
				}

				$fields[ $key ] = array(
					'type'    => 'text',
					'label'   => $label,
					'default' => $current,
					'help'    => $help,
				);
			}

			if ( empty( $fields ) ) {
				continue;
			}

			$title = $many_snippets ? $snippet['title'] . ' - ' . $group : $group;

			$section_slug = 's' . $snippet_id . '_' . preg_replace( '/[^A-Za-z0-9]/', '_', $group );

			$owned[ $snippet_id ]['sections'][] = $section_slug;

			foreach ( array_keys( $fields ) as $field_name ) {
				$owned[ $snippet_id ]['fields'][] = $field_name;
			}

			$sections[ $section_slug ] = array(
				'title'  => $title,
				'fields' => $fields,
				// Groups start closed so the panel opens as a short list
				// of headings rather than every setting at once. Beaver
				// Builder versions that do not know this key just render
				// the section open, which is only a cosmetic difference -
				// unlike a field 'type', a section key is read or ignored,
				// never turned into a file to load.
				'collapsed' => __( 'General', 'wpcode-bb-values' ) !== $group,
			);
		}
	}

	// The snippet picker, and the toggle that hides every other
	// snippet's settings behind it.
	$snippet_options = array( '' => __( '— select a snippet —', 'wpcode-bb-values' ) );
	$toggle          = array( '' => array( 'sections' => array(), 'fields' => array() ) );

	foreach ( $snippets as $snippet_id => $snippet ) {
		if ( empty( $owned[ $snippet_id ] ) ) {
			continue;
		}

		$snippet_options[ $snippet_id ] = sprintf(
			/* translators: 1: snippet title, 2: snippet ID */
			__( '%1$s (ID %2$d)', 'wpcode-bb-values' ),
			$snippet['title'],
			$snippet_id
		);

		$toggle[ $snippet_id ] = array(
			'sections' => $owned[ $snippet_id ]['sections'],
			'fields'   => $owned[ $snippet_id ]['fields'],
		);
	}

	if ( empty( $sections ) ) {
		// Nothing was found to edit. Say so rather than showing an empty
		// tab with no explanation.
		$sections['none'] = array(
			'title'  => __( 'Settings', 'wpcode-bb-values' ),
			'fields' => array(
				'wpcodebbv_notice' => array(
					'type'    => 'text',
					'label'   => __( 'No settings found', 'wpcode-bb-values' ),
					'default' => '',
					'help'    => __( 'No configurations array was found in any WPCode snippet. Set the snippet ID on the Setup tab, then use the Extra settings box there. Tools > WPCode Values has a Rescan button.', 'wpcode-bb-values' ),
				),
			),
		);
	}

	return array(
		'general' => array(
			'title'    => __( 'Settings', 'wpcode-bb-values' ),
			'sections' => $sections,
		),
		// A second tab, so the snippet this module points at is not
		// sitting under the cursor next to the values people edit every
		// day. Deliberately NOT keyed 'advanced': Beaver Builder adds a
		// tab of its own under that name to every module.
		'setup'   => array(
			'title'    => __( 'Setup', 'wpcode-bb-values' ),
			'sections' => array(
				'snippet'  => array(
					'title'  => __( 'Snippet', 'wpcode-bb-values' ),
					'fields' => array(
						'wpcode_id' => array(
							'type'    => 'select',
							'label'   => __( 'WPCode snippet', 'wpcode-bb-values' ),
							'default' => '',
							'options' => $snippet_options,
							/*
							 * This is what keeps a module showing only its
							 * own snippet's settings. Beaver Builder's
							 * toggle takes lists of NAMES that exist
							 * elsewhere in this same form - section slugs
							 * and field names, as strings. An early version
							 * of this plugin handed it field definitions
							 * instead and left the form pointing at fields
							 * that were never registered, which is the
							 * mistake this comment exists to prevent
							 * repeating. Sections and fields are both
							 * listed so that hiding still works if only
							 * one of the two is honoured.
							 */
							'toggle'  => $toggle,
							'help'    => __( 'Pick the snippet this module runs. Only that snippet\'s settings appear on the Settings tab. Changing this swaps which snippet the module runs, which is why it lives here rather than beside the values.', 'wpcode-bb-values' ),
						),
						'wpcode_id_manual' => array(
							'type'    => 'text',
							'label'   => __( 'Snippet ID (if not listed)', 'wpcode-bb-values' ),
							'default' => '',
							'help'    => __( 'Only needed when a snippet is missing from the list above - the number in [wpcode id="123"]. The module will run it, but its settings cannot be listed, so use the Extra settings box below.', 'wpcode-bb-values' ),
						),
					),
				),
				'extra'    => array(
					'title'  => __( 'Extra settings', 'wpcode-bb-values' ),
					'fields' => array(
						'custom_settings' => array(
							'type'    => 'textarea',
							'rows'    => 6,
							'label'   => __( 'Extra settings', 'wpcode-bb-values' ),
							'default' => '',
							'help'    => __( 'One per line, as path = value, for anything the Settings tab did not pick up. Example: noSchoolEvent.badgeText = No School. These win over everything else. Lines starting with # are ignored.', 'wpcode-bb-values' ),
						),
					),
				),
			),
		),
	);
}

/**
 * Parses the "Extra settings" box into path => value.
 *
 * @param string $text
 * @return array<string, string>
 */
function wpcodebbv_parse_lines( $text ) {
	$values = array();

	if ( ! is_string( $text ) || '' === trim( $text ) ) {
		return $values;
	}

	$lines = preg_split( '/\r\n|\r|\n/', $text );

	if ( ! is_array( $lines ) ) {
		return $values;
	}

	foreach ( $lines as $line ) {
		$line = trim( $line );

		if ( '' === $line || '#' === substr( $line, 0, 1 ) || '//' === substr( $line, 0, 2 ) ) {
			continue;
		}

		$split = strpos( $line, '=' );

		if ( false === $split ) {
			continue;
		}

		$path = trim( substr( $line, 0, $split ) );

		// A path is a setting name, optionally scoped and dotted:
		// "badgeText", "noSchoolEvent.badgeText", "configurations:x.y",
		// and for marked settings "$api_key" or "--accent".
		if ( ! preg_match( '/^[A-Za-z0-9_:.$\-]+$/', $path ) ) {
			continue;
		}

		$values[ $path ] = trim( substr( $line, $split + 1 ) );
	}

	return $values;
}

/**
 * Registers the module with Beaver Builder. Does nothing at all unless
 * Beaver Builder is present, so this plugin is inert on a site without
 * it rather than being an error.
 */
function wpcodebbv_register_module() {
	if ( ! class_exists( 'FLBuilder' ) || ! class_exists( 'FLBuilderModule' ) ) {
		return;
	}

	if ( ! is_callable( array( 'FLBuilder', 'register_module' ) ) ) {
		return;
	}

	$module_file = WPCODEBBV_DIR . 'modules/wpcode-values/wpcode-values.php';

	if ( ! file_exists( $module_file ) ) {
		return;
	}

	try {
		require_once $module_file;

		if ( ! class_exists( 'WPCodeBBV_Module' ) ) {
			return;
		}

		FLBuilder::register_module( 'WPCodeBBV_Module', wpcodebbv_form() );
	} catch ( \Throwable $e ) {
		// A failure here costs the module, never the site.
		wpcodebbv_log( 'could not register the module: ' . $e->getMessage() );
	}
}
add_action( 'init', 'wpcodebbv_register_module', 20 );

/**
 * A short help screen under Tools. Read-only: it stores no settings and
 * registers no post type, so there is nothing here that can interfere
 * with editing or saving a page.
 */
function wpcodebbv_help_menu() {
	add_management_page(
		__( 'WPCode Values', 'wpcode-bb-values' ),
		__( 'WPCode Values', 'wpcode-bb-values' ),
		'edit_posts',
		'wpcode-bb-values',
		'wpcodebbv_help_page'
	);
}
add_action( 'admin_menu', 'wpcodebbv_help_menu' );

/**
 * Saves the Updates form. Hidden behind ?wpcodebbv_updates=1 on the
 * help screen, since this is deployment plumbing rather than something
 * a page editor should meet.
 */
function wpcodebbv_handle_update_settings() {
	if ( ! isset( $_POST['wpcodebbv_updates_nonce'] ) ) {
		return;
	}

	if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['wpcodebbv_updates_nonce'] ) ), 'wpcodebbv_updates' ) ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) || ! class_exists( 'WPCodeBBV_Settings' ) ) {
		return;
	}

	$posted = isset( $_POST['wpcodebbv_settings'] ) && is_array( $_POST['wpcodebbv_settings'] )
		? wp_unslash( $_POST['wpcodebbv_settings'] )
		: array();

	// Unticked checkboxes do not post at all.
	foreach ( array( 'update_enabled', 'update_auto' ) as $flag ) {
		if ( ! isset( $posted[ $flag ] ) ) {
			$posted[ $flag ] = 0;
		}
	}

	WPCodeBBV_Settings::save( $posted );

	add_settings_error( 'wpcodebbv', 'wpcodebbv_updates_saved', __( 'Update settings saved.', 'wpcode-bb-values' ), 'updated' );
}

/**
 * The Updates panel. Only rendered when ?wpcodebbv_updates=1 is on the
 * URL, so the help screen stays about snippets for everyone else.
 */
function wpcodebbv_render_update_settings() {
	if ( empty( $_GET['wpcodebbv_updates'] ) || ! current_user_can( 'manage_options' ) || ! class_exists( 'WPCodeBBV_Settings' ) ) {
		return;
	}

	$s       = WPCodeBBV_Settings::all();
	$trigger = trim( (string) $s['update_trigger'] );
	?>
	<hr />
	<h2><?php esc_html_e( 'Updates', 'wpcode-bb-values' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'This plugin does not live on wordpress.org, so it checks a source you control and then shows "Update now" on the Plugins screen like any other plugin. A release that fails its load test after installing is rolled back rather than left broken.', 'wpcode-bb-values' ); ?>
	</p>

	<form method="post">
		<?php wp_nonce_field( 'wpcodebbv_updates', 'wpcodebbv_updates_nonce' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Updates', 'wpcode-bb-values' ); ?></th>
				<td>
					<label><input type="checkbox" name="wpcodebbv_settings[update_enabled]" value="1" <?php checked( $s['update_enabled'], 1 ); ?> /> <?php esc_html_e( 'Check for updates', 'wpcode-bb-values' ); ?></label><br />
					<label><input type="checkbox" name="wpcodebbv_settings[update_auto]" value="1" <?php checked( $s['update_auto'], 1 ); ?> /> <?php esc_html_e( 'Install them automatically', 'wpcode-bb-values' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wpcodebbv_source"><?php esc_html_e( 'Source', 'wpcode-bb-values' ); ?></label></th>
				<td>
					<select id="wpcodebbv_source" name="wpcodebbv_settings[update_source]">
						<option value="url" <?php selected( $s['update_source'], 'url' ); ?>><?php esc_html_e( 'Manifest URL', 'wpcode-bb-values' ); ?></option>
						<option value="github" <?php selected( $s['update_source'], 'github' ); ?>><?php esc_html_e( 'GitHub releases', 'wpcode-bb-values' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wpcodebbv_manifest"><?php esc_html_e( 'Manifest URL', 'wpcode-bb-values' ); ?></label></th>
				<td>
					<input type="url" class="regular-text" id="wpcodebbv_manifest" name="wpcodebbv_settings[update_manifest]" value="<?php echo esc_attr( $s['update_manifest'] ); ?>" />
					<p class="description"><?php esc_html_e( 'JSON returning at least { "version": "1.2.3", "download_url": "https://…/wpcode-bb-values.zip" }.', 'wpcode-bb-values' ); ?></p>
					<input type="text" class="regular-text" name="wpcodebbv_settings[update_manifest_key]" value="<?php echo esc_attr( $s['update_manifest_key'] ); ?>" placeholder="<?php esc_attr_e( 'Optional key sent with the request', 'wpcode-bb-values' ); ?>" />
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'GitHub', 'wpcode-bb-values' ); ?></th>
				<td>
					<input type="text" name="wpcodebbv_settings[gh_owner]" value="<?php echo esc_attr( $s['gh_owner'] ); ?>" placeholder="<?php esc_attr_e( 'owner', 'wpcode-bb-values' ); ?>" />
					<input type="text" name="wpcodebbv_settings[gh_repo]" value="<?php echo esc_attr( $s['gh_repo'] ); ?>" placeholder="<?php esc_attr_e( 'repo', 'wpcode-bb-values' ); ?>" />
					<input type="text" name="wpcodebbv_settings[gh_asset]" value="<?php echo esc_attr( $s['gh_asset'] ); ?>" placeholder="<?php esc_attr_e( 'asset.zip', 'wpcode-bb-values' ); ?>" />
					<br />
					<input type="password" class="regular-text" name="wpcodebbv_settings[gh_token]" value="<?php echo esc_attr( $s['gh_token'] ); ?>" placeholder="<?php esc_attr_e( 'Token (private repos only)', 'wpcode-bb-values' ); ?>" autocomplete="new-password" />
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wpcodebbv_role"><?php esc_html_e( 'Rollout role', 'wpcode-bb-values' ); ?></label></th>
				<td>
					<select id="wpcodebbv_role" name="wpcodebbv_settings[update_role]">
						<option value="standalone" <?php selected( $s['update_role'], 'standalone' ); ?>><?php esc_html_e( 'Standalone - update as soon as a release appears', 'wpcode-bb-values' ); ?></option>
						<option value="dev" <?php selected( $s['update_role'], 'dev' ); ?>><?php esc_html_e( 'Dev / staging - update first and publish the result', 'wpcode-bb-values' ); ?></option>
						<option value="production" <?php selected( $s['update_role'], 'production' ); ?>><?php esc_html_e( 'Production - wait until dev has verified the release', 'wpcode-bb-values' ); ?></option>
					</select>
					<p class="description">
						<?php esc_html_e( 'On dev, set a status key and give production the status URL below. Production then only offers a version once dev has installed it and passed its own load test.', 'wpcode-bb-values' ); ?>
					</p>
					<input type="url" class="regular-text" name="wpcodebbv_settings[verify_status_url]" value="<?php echo esc_attr( $s['verify_status_url'] ); ?>" placeholder="<?php esc_attr_e( 'Dev status URL (production only)', 'wpcode-bb-values' ); ?>" />
					<input type="text" name="wpcodebbv_settings[verify_status_key]" value="<?php echo esc_attr( $s['verify_status_key'] ); ?>" placeholder="<?php esc_attr_e( 'Shared status key', 'wpcode-bb-values' ); ?>" />
					<p class="description">
						<?php esc_html_e( 'This site publishes its own status at:', 'wpcode-bb-values' ); ?>
						<code><?php echo esc_html( rest_url( WPCODEBBV_REST_NAMESPACE . '/update-status' ) ); ?></code>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Force an update', 'wpcode-bb-values' ); ?></th>
				<td>
					<?php if ( '' !== $trigger ) : ?>
						<code><?php echo esc_html( add_query_arg( 'wpcodebbv_update', $trigger, home_url( '/' ) ) ); ?></code>
						<p class="description">
							<?php esc_html_e( 'Requesting this URL checks and installs immediately - useful from a deploy hook or cron. It is guarded only by the secret in it, so treat it as a password.', 'wpcode-bb-values' ); ?>
						</p>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'No secret yet. Deactivate and reactivate the plugin to generate one.', 'wpcode-bb-values' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save update settings', 'wpcode-bb-values' ); ?></button></p>
	</form>
	<?php
}

function wpcodebbv_help_page() {
	$bb     = class_exists( 'FLBuilder' );
	$wpcode = post_type_exists( 'wpcode' );

	wpcodebbv_handle_reset();
	wpcodebbv_handle_update_settings();

	if ( isset( $_GET['wpcodebbv_rescan'] ) ) {
		wpcodebbv_clear_index();
	}

	$snippets = wpcodebbv_snippets();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'WPCode Values for Beaver Builder', 'wpcode-bb-values' ); ?></h1>

		<?php settings_errors( 'wpcodebbv' ); ?>

		<p>
			<?php
			printf(
				/* translators: 1: version, 2: Beaver Builder status, 3: WPCode status */
				esc_html__( 'Version %1$s. Beaver Builder: %2$s. WPCode: %3$s.', 'wpcode-bb-values' ),
				esc_html( WPCODEBBV_VERSION ),
				$bb ? esc_html__( 'detected', 'wpcode-bb-values' ) : esc_html__( 'NOT detected - the module cannot appear until it is active', 'wpcode-bb-values' ),
				$wpcode ? esc_html__( 'detected', 'wpcode-bb-values' ) : esc_html__( 'not detected', 'wpcode-bb-values' )
			);
			?>
		</p>

		<h2><?php esc_html_e( 'How it works', 'wpcode-bb-values' ); ?></h2>
		<p><?php esc_html_e( 'Your snippet already keeps its settings in an array called configurations, like this:', 'wpcode-bb-values' ); ?></p>
		<pre>var configurations = [
    {key: 'eventColor', value: 'blue'},
    {key: 'noSchoolEvent', value: {
        badgeText: 'No School',
        searchForWords: ['schools closed']
    }}
];</pre>
		<p><?php esc_html_e( 'Drop the "WPCode Values" module on a page and put your snippet\'s ID in it - that is the number in [wpcode id="123"]. Every setting in that snippet\'s configurations array then appears in the module, already filled in with the value the snippet uses. Change the ones you want for this page and leave the rest alone. Clear a box to let the snippet\'s own value through again.', 'wpcode-bb-values' ); ?></p>
		<p><?php esc_html_e( 'Settings written as true or false become a true/false dropdown, so they cannot be given a value the snippet will not understand. Settings that belong to a block - everything under noSchoolEvent, say - are grouped under that name and start collapsed, so the panel opens as a short list of headings.', 'wpcode-bb-values' ); ?></p>
		<p><?php esc_html_e( 'Every box has a help icon. If you put a comment next to a setting in the snippet, that comment becomes its help text, so the person who wrote the snippet decides what the editor is told.', 'wpcode-bb-values' ); ?></p>
		<p><?php esc_html_e( 'Nothing in your snippet needs to change. The values are edited in the script the snippet outputs, on the way to the browser, only on the page holding that module. A setting inside a nested block is written with a dot: noSchoolEvent.badgeText. A list of words is typed with commas between them.', 'wpcode-bb-values' ); ?></p>

		<h2>
			<?php esc_html_e( 'What was found in your snippets', 'wpcode-bb-values' ); ?>
			<a href="<?php echo esc_url( add_query_arg( 'wpcodebbv_rescan', '1' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Rescan', 'wpcode-bb-values' ); ?></a>
		</h2>

		<?php if ( empty( $snippets ) ) : ?>
			<p>
				<?php esc_html_e( 'No configurations array was found in any snippet yet, so the module\'s Settings tab will be empty. You can still set values by hand on the module\'s Setup tab, as "path = value" lines - those are applied to whatever the snippet prints, so they work either way.', 'wpcode-bb-values' ); ?>
			</p>
		<?php else : ?>
			<form method="post">
				<?php wp_nonce_field( 'wpcodebbv_globals', 'wpcodebbv_globals_nonce' ); ?>

				<?php foreach ( $snippets as $snippet_id => $snippet ) : ?>
					<?php
					$globals   = wpcodebbv_globals_for( $snippet_id );
					$wide_here = 0;

					foreach ( $snippet['settings'] as $leaf ) {
						if ( ! empty( $leaf['global'] ) ) {
							$wide_here++;
						}
					}
					?>

					<h3>
						<?php echo esc_html( $snippet['title'] ); ?>
						<code>[wpcode id="<?php echo (int) $snippet_id; ?>"]</code>
						<span class="description">
							<?php
							printf(
								/* translators: 1: number of settings, 2: number marked siteWide */
								esc_html__( '%1$d settings, %2$d marked siteWide', 'wpcode-bb-values' ),
								count( $snippet['settings'] ),
								$wide_here
							);
							?>
						</span>
					</h3>

					<table class="widefat striped" style="max-width: 1000px; margin-bottom: 10px;">
						<thead>
							<tr>
								<th style="width: 230px;"><?php esc_html_e( 'Setting', 'wpcode-bb-values' ); ?></th>
								<th style="width: 90px;"><?php esc_html_e( 'Scope', 'wpcode-bb-values' ); ?></th>
								<th style="width: 180px;"><?php esc_html_e( 'In the snippet', 'wpcode-bb-values' ); ?></th>
								<th style="width: 180px;"><?php esc_html_e( 'Site-wide now', 'wpcode-bb-values' ); ?></th>
								<th><?php esc_html_e( 'What it does', 'wpcode-bb-values' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php
						$last_group = null;

						foreach ( $snippet['settings'] as $path => $leaf ) :
							$group = $leaf['group'];

							if ( $group !== $last_group ) :
								$last_group = $group;
								?>
								<tr><th colspan="5" style="text-align: left;"><?php echo esc_html( $group ); ?></th></tr>
								<?php
							endif;
							?>
							<tr>
								<td><code><?php echo esc_html( $leaf['label'] ); ?></code></td>
								<td>
									<?php if ( ! empty( $leaf['php_static'] ) ) : ?>
										<strong style="color:#b26200;"><?php esc_html_e( 'not editable', 'wpcode-bb-values' ); ?></strong>
									<?php elseif ( ! empty( $leaf['php'] ) ) : ?>
										<strong><?php esc_html_e( 'PHP, site-wide', 'wpcode-bb-values' ); ?></strong>
									<?php elseif ( ! empty( $leaf['global'] ) ) : ?>
										<strong><?php esc_html_e( 'site-wide', 'wpcode-bb-values' ); ?></strong>
									<?php else : ?>
										<?php esc_html_e( 'per page', 'wpcode-bb-values' ); ?>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $leaf['value'] ); ?></td>
								<td>
									<?php
									if ( empty( $leaf['global'] ) ) {
										echo '&mdash;';
									} elseif ( isset( $globals[ $path ] ) && '' !== $globals[ $path ] ) {
										echo '<strong>' . esc_html( $globals[ $path ] ) . '</strong>';
									} else {
										esc_html_e( 'same as the snippet', 'wpcode-bb-values' );
									}
									?>
								</td>
								<td>
									<span class="description"><?php echo esc_html( wpcodebbv_describe_base( $path, $leaf ) ); ?></span>
									<?php if ( ! empty( $leaf['php_static'] ) ) : ?>
										<br /><span class="description" style="color:#b26200;">
											<?php
											printf(
												/* translators: %s: the suggested code */
												esc_html__( 'This one cannot be changed from a module: WPCode runs a PHP snippet rather than printing it, so there is no output to rewrite. To make it editable, wrap the value: %s', 'wpcode-bb-values' ),
												'<code>' . esc_html( $leaf['label'] . " = wpcodebbv_cfg( '" . ltrim( $leaf['label'], '$' ) . "', " . ( 'string' === $leaf['kind'] ? "'" . $leaf['value'] . "'" : $leaf['value'] ) . ' );' ) . '</code>'
											);
											?>
										</span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>

					<?php if ( ! empty( $globals ) ) : ?>
						<p>
							<button type="submit" name="wpcodebbv_reset" value="<?php echo (int) $snippet_id; ?>" class="button">
								<?php esc_html_e( 'Reset this snippet\'s site-wide values', 'wpcode-bb-values' ); ?>
							</button>
							<span class="description"><?php esc_html_e( 'Puts every site-wide setting back to the value written in the snippet.', 'wpcode-bb-values' ); ?></span>
						</p>
					<?php endif; ?>
				<?php endforeach; ?>
			</form>

			<h2><?php esc_html_e( 'Comments to paste into your snippet', 'wpcode-bb-values' ); ?></h2>
			<p><?php esc_html_e( 'Below is your own configurations array with a comment added to each setting that does not have one. Copy it back over the array in WPCode and those comments become the help text in the module, replacing the descriptions worked out here. Edit the wording first - you know what these do better than this does.', 'wpcode-bb-values' ); ?></p>

			<?php foreach ( $snippets as $snippet_id => $snippet ) : ?>
				<?php
				$code      = wpcodebbv_snippet_code( $snippet_id );
				$annotated = wpcodebbv_annotate( $code );

				if ( '' === trim( $annotated ) ) {
					continue;
				}
				?>
				<h3><?php echo esc_html( $snippet['title'] ); ?></h3>
				<textarea readonly="readonly" rows="18" class="widefat code" onclick="this.select();"><?php echo esc_textarea( $annotated ); ?></textarea>
			<?php endforeach; ?>
		<?php endif; ?>

		<h2><?php esc_html_e( 'PHP and CSS snippets', 'wpcode-bb-values' ); ?></h2>
		<p><?php esc_html_e( 'A "Configurable" comment works in any snippet, not just JavaScript. Put it after an assignment and that value becomes editable in the module:', 'wpcode-bb-values' ); ?></p>
		<pre>/* CSS */
:root{
  --accent: #1A73E8;          /* Configurable siteWide: brand colour */
  --radius: 8px;              /* Configurable: corner rounding */
  --font-body: "Google Sans", Roboto, sans-serif;  /* Configurable: body font */
}</pre>
		<p><?php esc_html_e( 'The comment marks where the value ends, so whatever is in front of it is kept exactly as written - a colour, a size, a font stack with its own commas and quotes. Anything without the comment is left alone.', 'wpcode-bb-values' ); ?></p>

		<p><strong><?php esc_html_e( 'PHP is different, and needs one extra thing.', 'wpcode-bb-values' ); ?></strong>
			<?php esc_html_e( 'WPCode runs a PHP snippet rather than printing it, so its source never reaches the browser and there is nothing to rewrite on the way out. A PHP snippet asks for its values instead:', 'wpcode-bb-values' ); ?></p>
		<pre>&lt;?php
$api_key   = wpcodebbv_cfg( 'api_key', 'AIza-DEFAULT' );   // Configurable siteWide: the API key
$debug     = wpcodebbv_cfg( 'debug', false );              // Configurable - turn logging on
$max_items = wpcodebbv_cfg( 'max_items', 25 );             // Configurable: how many to show
$roles     = wpcodebbv_cfg( 'roles', array( 'editor' ) );  // Configurable: who sees the panel</pre>
		<p><?php esc_html_e( 'The default you write is what the module shows and what applies until someone changes it. You get back the same TYPE you passed as the default - a boolean default returns a boolean, a number a number, an array an array - so the snippet never has to think about the fact that the editor types text.', 'wpcode-bb-values' ); ?></p>
		<p><?php esc_html_e( 'A PHP snippet written this way still works anywhere else on the site: with no module supplying values, wpcodebbv_cfg() simply returns the default.', 'wpcode-bb-values' ); ?></p>
		<p><?php esc_html_e( 'One caution for CSS: a plain property like "background" is used all over a stylesheet, and only the first one marked wins. Mark custom properties (--accent, --radius) rather than plain declarations wherever you can - they are unique by nature and are the thing worth exposing anyway.', 'wpcode-bb-values' ); ?></p>

		<h2><?php esc_html_e( 'Reading the settings in your snippet', 'wpcode-bb-values' ); ?></h2>
		<p><?php esc_html_e( 'The configurations array is a list of {key, value} pairs, which is awkward to read from directly. Paste this below the array and you get one object for looking settings up by name:', 'wpcode-bb-values' ); ?></p>
		<pre>CONFIG.get('calendarID')                     // 'c_a13c7383...'
CONFIG.get('noSchoolEvent.badgeText')        // 'No School'
CONFIG.bool('noSchoolEvent.showBottomBadge') // true - a real boolean
CONFIG.list('noSchoolEvent.searchForWords')  // ['schools closed']
CONFIG.set('eventColor', 'crimson')          // updates the array too
CONFIG.match('Schools Closed Friday')        // 'noSchoolEvent'</pre>
		<p>
			<strong><?php esc_html_e( 'Watch out for the on/off settings.', 'wpcode-bb-values' ); ?></strong>
			<?php esc_html_e( 'They are the strings "true" and "false", not real booleans, so if (CONFIG.get(\'x\')) is true even when the setting says false, because "false" is a non-empty string. Use CONFIG.bool() for those.', 'wpcode-bb-values' ); ?>
		</p>

		<?php
		$helper_file = WPCODEBBV_DIR . 'assets/configurations-helper.js';
		$helper      = file_exists( $helper_file ) ? file_get_contents( $helper_file ) : '';

		if ( '' !== $helper ) :
			?>
			<textarea readonly="readonly" rows="16" class="widefat code" onclick="this.select();"><?php echo esc_textarea( $helper ); ?></textarea>
		<?php endif; ?>

		<?php wpcodebbv_render_update_settings(); ?>

		<h2><?php esc_html_e( 'If the module is not listed in the editor', 'wpcode-bb-values' ); ?></h2>
		<p><?php esc_html_e( 'Check Settings > Beaver Builder > Modules. If that list has ever been narrowed down, a newly installed module stays off until you tick it.', 'wpcode-bb-values' ); ?></p>
	</div>
	<?php
}
