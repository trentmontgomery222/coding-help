<?php
/**
 * Plugin Name:       Cayden Link Shortener
 * Plugin URI:        https://caydenriddle.com/
 * Description:       Self-hosted, branded URL shortener. Creates short-link redirects with click tracking, an accessible admin UI, and a password-gated front-end dashboard for staff.
 * Version:           1.23.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Cayden
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       acps-link-shortener
 *
 * @package ACPS_Link_Shortener
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bail safely on unsupported PHP instead of white-screening on activation.
 * A too-old PHP is shown a readable notice; the plugin simply does not load.
 */
if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html(
				sprintf(
					/* translators: %s: current PHP version. */
					__( 'Cayden Link Shortener requires PHP 7.4 or newer. This server runs PHP %s. Please update PHP, then activate the plugin.', 'acps-link-shortener' ),
					PHP_VERSION
				)
			);
			echo '</p></div>';
		}
	);
	return;
}

/**
 * Core constants.
 *
 * ACPS_LS_SLUG_PREFIX is the single source of truth for the path segment used
 * in front of every short link.
 *
 *   'link' -> acpsmd.org/link/{slug}   (prefixed mode; uses a rewrite rule)
 *   ''     -> acpsmd.org/{slug}         (bare mode; no prefix)
 *
 * In bare mode there is NO catch-all rewrite rule (that would hijack every
 * page). Instead a short link only fires when WordPress would otherwise return
 * a 404, so every real page, post, category, etc. always wins. A short-link
 * slug that matches an existing page/post slug will therefore never redirect —
 * pick slugs that are not already real URLs on the site.
 *
 * Re-flush rewrite rules after changing this (Settings -> Permalinks -> Save,
 * or deactivate + reactivate the plugin).
 */
define( 'ACPS_LS_VERSION', '1.23.0' );
define( 'ACPS_LS_DB_VERSION', '1.3.0' );
define( 'ACPS_LS_SLUG_PREFIX', '' );
define( 'ACPS_LS_QUERY_VAR', 'acps_ls_slug' );
define( 'ACPS_LS_FILE', __FILE__ );
define( 'ACPS_LS_PATH', plugin_dir_path( __FILE__ ) );
define( 'ACPS_LS_URL', plugin_dir_url( __FILE__ ) );
define( 'ACPS_LS_BASENAME', plugin_basename( __FILE__ ) );

// Option keys.
define( 'ACPS_LS_OPT_DB_VERSION', 'acps_ls_db_version' );
define( 'ACPS_LS_OPT_SETTINGS', 'acps_ls_settings' );
define( 'ACPS_LS_OPT_SETUP_TOKENS', 'acps_ls_setup_tokens' );

// Option keys used by staging / rollback / background install (must be defined
// BEFORE the pristine-window staged-apply/rollback run below).
define( 'ACPS_LS_OPT_STAGED', 'acps_ls_staged_install' );
define( 'ACPS_LS_OPT_ROLLBACK', 'acps_ls_rollback' );
define( 'ACPS_LS_OPT_PENDING', 'acps_ls_pending_update' );
define( 'ACPS_LS_OPT_SHOULD_ACTIVE', 'acps_ls_should_be_active' );

// REST namespace (used by the updater's staged-rollout status endpoint).
define( 'ACPS_LS_REST_NAMESPACE', 'acps-ls/v1' );

// Option holding "safe mode" state after a fatal is caught in our own code.
define( 'ACPS_LS_SAFE_MODE_OPT', 'acps_ls_safe_mode' );

// WP-Cron hook + interval for the link checker (scan + HTTP checks).
define( 'ACPS_LS_CHECK_HOOK', 'acps_ls_link_check' );
define( 'ACPS_LS_CHECK_INTERVAL', 'acps_ls_ten_minutes' );

/**
 * Log a plugin error without ever surfacing it to visitors.
 *
 * @param string    $context Where it happened.
 * @param Throwable $e       The error/exception.
 */
function acps_ls_log_error( $context, $e ) {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( sprintf( '[Cayden Link Shortener] %s: %s in %s:%d', $context, $e->getMessage(), $e->getFile(), $e->getLine() ) );
	}
}

/**
 * Safely load the plugin's class files.
 *
 * If any file is missing (e.g. an incomplete/failed upload) the plugin does NOT
 * fatal the whole site — it logs, shows an admin notice, and simply does not
 * boot. The rest of WordPress keeps working normally.
 *
 * @return bool True if every required file loaded.
 */
function acps_ls_load_files() {
	$files = array(
		'includes/class-acps-ls-install.php',
		'includes/class-acps-ls-db.php',
		'includes/class-acps-ls-rewrite.php',
		'includes/class-acps-ls-redirect.php',
		'includes/class-acps-ls-shortcode.php',
		'includes/class-acps-ls-checker.php',
		'includes/class-acps-ls-updater.php',
		'includes/class-acps-ls-control.php',
		'includes/class-acps-ls-help.php',
	);

	$missing = array();
	foreach ( $files as $rel ) {
		$path = ACPS_LS_PATH . $rel;
		if ( is_readable( $path ) ) {
			try {
				require_once $path;
			} catch ( Throwable $e ) {
				acps_ls_log_error( 'load ' . $rel, $e );
				$missing[] = $rel;
			}
		} else {
			$missing[] = $rel;
		}
	}

	if ( $missing ) {
		add_action(
			'admin_notices',
			function () use ( $missing ) {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}
				echo '<div class="notice notice-error"><p><strong>Cayden Link Shortener</strong> could not load and has been paused to protect your site. Missing file(s): ';
				echo esc_html( implode( ', ', $missing ) );
				echo '. Re-upload the plugin (Plugins → Add New → Upload Plugin) to fix it.</p></div>';
			}
		);
		return false;
	}

	return true;
}

// PRISTINE WINDOW: before the plugin's own includes are loaded, roll back a bad
// update if one is armed and the last request tripped safe mode, then apply any
// staged install. This is the one instant when the plugin's in-use .php files
// are not yet loaded, so they can be overwritten even on hosts that block
// overwriting in-use PHP from a normal request (see the update recipe).
acps_ls_maybe_rollback();
acps_ls_apply_staged();

$acps_ls_loaded = acps_ls_load_files();

/**
 * Return the capability required to manage links.
 *
 * Defaults to `manage_options` (a site administrator). Filterable so a site can
 * grant a custom role instead.
 *
 * @return string
 */
function acps_ls_manage_capability() {
	return apply_filters( 'acps_ls_manage_capability', 'manage_options' );
}

/**
 * Whether the 301 (permanent) redirect option is allowed.
 *
 * Defaults to false: the permanent option is disabled/grayed out in the admin
 * and every link is forced to 302 (temporary) so edits take effect immediately
 * and stale 301s are never cached at the edge. Re-enable with:
 *
 *     add_filter( 'acps_ls_allow_permanent', '__return_true' );
 *
 * @return bool
 */
function acps_ls_allow_permanent() {
	return (bool) apply_filters( 'acps_ls_allow_permanent', false );
}

/**
 * Base URL that short links are built on ("the first part" of the short URL).
 *
 * Returns the custom short-link domain from Settings when one is configured
 * (e.g. https://go.acpsmd.org), otherwise falls back to this site's own URL.
 * The returned value never has a trailing slash.
 *
 * IMPORTANT: a custom domain only *works* if it actually resolves to this
 * WordPress install (DNS + host/WP Engine domain mapping). This function only
 * controls how the URL is generated and displayed.
 *
 * @return string
 */
function acps_ls_link_base() {
	$settings = get_option( ACPS_LS_OPT_SETTINGS, array() );
	$custom   = ( is_array( $settings ) && ! empty( $settings['link_domain'] ) ) ? trim( $settings['link_domain'] ) : '';

	if ( '' !== $custom ) {
		return untrailingslashit( $custom );
	}

	return untrailingslashit( home_url() );
}

/**
 * Return the configured front-end people.
 *
 * @return array[] Each: [
 *     'label'     => string,  // display name / sign-in name
 *     'hash'      => string,  // hashed password
 *     'max_links' => int,     // 0 = unlimited (shortcode-created links only)
 *     'namespace' => string,  // optional first path segment, e.g. 'katherine'
 * ].
 */
function acps_ls_get_people() {
	$settings = get_option( ACPS_LS_OPT_SETTINGS, array() );
	$people   = ( is_array( $settings ) && ! empty( $settings['people'] ) && is_array( $settings['people'] ) )
		? $settings['people']
		: array();

	$clean = array();
	foreach ( $people as $person ) {
		// A person may be "pending" (name set, no password yet) while waiting to
		// use a setup link, so an empty hash is allowed here; authentication
		// separately rejects an empty hash.
		if ( ! empty( $person['label'] ) ) {
			$clean[] = array(
				'label'     => (string) $person['label'],
				'hash'      => isset( $person['hash'] ) ? (string) $person['hash'] : '',
				'max_links' => isset( $person['max_links'] ) ? max( 0, (int) $person['max_links'] ) : 0,
				'namespace' => isset( $person['namespace'] ) ? (string) $person['namespace'] : '',
			);
		}
	}
	return $clean;
}

/**
 * Fetch a single person record by label (case-insensitive), or null.
 *
 * @param string $label Person label.
 * @return array|null
 */
function acps_ls_get_person( $label ) {
	foreach ( acps_ls_get_people() as $person ) {
		if ( strtolower( $person['label'] ) === strtolower( (string) $label ) ) {
			return $person;
		}
	}
	return null;
}

/**
 * Verify a front-end name + password against the configured people.
 *
 * @param string $name     Person name (case-insensitive match).
 * @param string $password Submitted password.
 * @return string|false The canonical person label on success, false otherwise.
 */
function acps_ls_authenticate_person( $name, $password ) {
	$name = trim( (string) $name );
	if ( '' === $name || '' === (string) $password ) {
		return false;
	}

	foreach ( acps_ls_get_people() as $person ) {
		if ( '' === $person['hash'] ) {
			continue; // Pending invitee — no password set yet.
		}
		if ( strtolower( $person['label'] ) === strtolower( $name ) && wp_check_password( $password, $person['hash'] ) ) {
			return $person['label'];
		}
	}
	return false;
}

/**
 * One-time setup tokens are stored keyed by a SHA-256 hash of the token, so the
 * raw token is never persisted. Each entry: [ 'label' => string, 'expires' => ts ].
 *
 * @return array
 */
function acps_ls_setup_token_store() {
	$store = get_option( ACPS_LS_OPT_SETUP_TOKENS, array() );
	return is_array( $store ) ? $store : array();
}

/**
 * Create a one-time setup token for a person and return the RAW token (shown
 * once). Expired tokens are pruned on write.
 *
 * @param string $label     Person label.
 * @param int    $ttl_hours Validity window in hours.
 * @return string Raw token.
 */
function acps_ls_create_setup_token( $label, $ttl_hours = 72 ) {
	$token = wp_generate_password( 32, false, false );
	$key   = hash( 'sha256', $token );
	$now   = time();

	$store = acps_ls_setup_token_store();
	foreach ( $store as $k => $entry ) {
		if ( empty( $entry['expires'] ) || (int) $entry['expires'] < $now ) {
			unset( $store[ $k ] );
		}
	}
	$store[ $key ] = array(
		'label'   => (string) $label,
		'expires' => $now + ( $ttl_hours * HOUR_IN_SECONDS ),
	);
	update_option( ACPS_LS_OPT_SETUP_TOKENS, $store );

	return $token;
}

/**
 * Look up a setup token. Returns the person label if valid + unexpired, else false.
 *
 * @param string $token Raw token.
 * @return string|false
 */
function acps_ls_lookup_setup_token( $token ) {
	$key   = hash( 'sha256', (string) $token );
	$store = acps_ls_setup_token_store();

	if ( empty( $store[ $key ] ) || empty( $store[ $key ]['expires'] ) || (int) $store[ $key ]['expires'] < time() ) {
		return false;
	}
	// The referenced person must still exist.
	$label = $store[ $key ]['label'];
	return acps_ls_get_person( $label ) ? $label : false;
}

/**
 * Consume (invalidate) a setup token so the link cannot be reused.
 *
 * @param string $token Raw token.
 */
function acps_ls_consume_setup_token( $token ) {
	$key   = hash( 'sha256', (string) $token );
	$store = acps_ls_setup_token_store();
	if ( isset( $store[ $key ] ) ) {
		unset( $store[ $key ] );
		update_option( ACPS_LS_OPT_SETUP_TOKENS, $store );
	}
}

/**
 * Set (or reset) a person's password by label. Returns true if the person was
 * found and updated.
 *
 * @param string $label    Person label.
 * @param string $password New plaintext password (will be hashed).
 * @return bool
 */
function acps_ls_set_person_password( $label, $password ) {
	$settings = get_option( ACPS_LS_OPT_SETTINGS, array() );
	if ( ! is_array( $settings ) || empty( $settings['people'] ) || ! is_array( $settings['people'] ) ) {
		return false;
	}

	$found = false;
	foreach ( $settings['people'] as &$person ) {
		if ( ! empty( $person['label'] ) && strtolower( $person['label'] ) === strtolower( $label ) ) {
			$person['hash'] = wp_hash_password( $password );
			$found          = true;
			break;
		}
	}
	unset( $person );

	if ( $found ) {
		update_option( ACPS_LS_OPT_SETTINGS, $settings );
	}
	return $found;
}

/**
 * Base URL of the page that holds the [acps_link_shortener] shortcode. Used to
 * build setup links. Falls back to the site root.
 *
 * @return string
 */
function acps_ls_shortcode_page_url() {
	$settings = get_option( ACPS_LS_OPT_SETTINGS, array() );
	$url      = ( is_array( $settings ) && ! empty( $settings['shortcode_page'] ) ) ? $settings['shortcode_page'] : '';
	return $url ? $url : home_url( '/' );
}

/**
 * Build the public short URL for a slug (honors the custom domain + prefix).
 *
 * @param string $slug Slug.
 * @return string
 */
function acps_ls_short_url( $slug ) {
	$prefix = ACPS_LS_SLUG_PREFIX;
	$path   = '/' . ( '' !== $prefix ? $prefix . '/' : '' ) . $slug;
	return acps_ls_link_base() . $path;
}

/**
 * Fully-qualified name of the links table.
 *
 * @return string
 */
function acps_ls_table_name() {
	global $wpdb;
	return $wpdb->prefix . 'acps_links';
}

/**
 * Checker table: one row per unique URL.
 *
 * @return string
 */
function acps_ls_urls_table() {
	global $wpdb;
	return $wpdb->prefix . 'acps_link_urls';
}

/**
 * Checker table: where each URL was found.
 *
 * @return string
 */
function acps_ls_occ_table() {
	global $wpdb;
	return $wpdb->prefix . 'acps_link_occurrences';
}

/**
 * Return the configured link-replacement rules.
 *
 * @return array[] Each: [
 *     'type'    => 'exact'|'contains'|'regex',
 *     'pattern' => string,
 *     'replace' => string,
 *     'mode'    => 'rewrite'|'flag',
 *     'enabled' => bool,
 * ].
 */
function acps_ls_get_rules() {
	$settings = get_option( ACPS_LS_OPT_SETTINGS, array() );
	$rules    = ( is_array( $settings ) && ! empty( $settings['rules'] ) && is_array( $settings['rules'] ) )
		? $settings['rules']
		: array();

	$clean = array();
	foreach ( $rules as $rule ) {
		if ( empty( $rule['pattern'] ) ) {
			continue;
		}
		$clean[] = array(
			'type'    => in_array( ( $rule['type'] ?? '' ), array( 'exact', 'contains', 'regex' ), true ) ? $rule['type'] : 'contains',
			'pattern' => (string) $rule['pattern'],
			'replace' => isset( $rule['replace'] ) ? (string) $rule['replace'] : '',
			'mode'    => ( 'flag' === ( $rule['mode'] ?? '' ) ) ? 'flag' : 'rewrite',
			'enabled' => ! empty( $rule['enabled'] ),
		);
	}
	return $clean;
}

/**
 * Whether a URL matches a single rule.
 *
 * @param array  $rule Rule.
 * @param string $url  URL.
 * @return bool
 */
function acps_ls_rule_matches( $rule, $url ) {
	switch ( $rule['type'] ) {
		case 'exact':
			return $url === $rule['pattern'];
		case 'regex':
			$delim   = "\1";
			$pattern = $delim . str_replace( $delim, '\\' . $delim, $rule['pattern'] ) . $delim;
			// Suppress warnings on an invalid pattern; treat as no match.
			return (bool) @preg_match( $pattern, $url ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		case 'contains':
		default:
			return '' !== $rule['pattern'] && false !== strpos( $url, $rule['pattern'] );
	}
}

/**
 * Apply all enabled REWRITE rules to a URL, returning the (possibly) new URL.
 *
 * @param string $url Original URL.
 * @return string
 */
function acps_ls_apply_rules( $url ) {
	foreach ( acps_ls_get_rules() as $rule ) {
		if ( ! $rule['enabled'] || 'rewrite' !== $rule['mode'] ) {
			continue;
		}
		switch ( $rule['type'] ) {
			case 'exact':
				if ( $url === $rule['pattern'] ) {
					$url = $rule['replace'];
				}
				break;
			case 'regex':
				$delim   = "\1";
				$pattern = $delim . str_replace( $delim, '\\' . $delim, $rule['pattern'] ) . $delim;
				$result  = @preg_replace( $pattern, $rule['replace'], $url ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( null !== $result ) {
					$url = $result;
				}
				break;
			case 'contains':
			default:
				if ( '' !== $rule['pattern'] ) {
					$url = str_replace( $rule['pattern'], $rule['replace'], $url );
				}
				break;
		}
	}
	return $url;
}

/**
 * The first enabled FLAG rule a URL matches, or null.
 *
 * @param string $url URL.
 * @return array|null
 */
function acps_ls_flagging_rule( $url ) {
	foreach ( acps_ls_get_rules() as $rule ) {
		if ( $rule['enabled'] && 'flag' === $rule['mode'] && acps_ls_rule_matches( $rule, $url ) ) {
			return $rule;
		}
	}
	return null;
}

/**
 * Activation: build the table, seed options, flush rewrite rules, schedule cron.
 *
 * Wrapped so a hiccup during activation shows a readable failure instead of a
 * fatal. Rewrite rules are flushed here ONLY (never on every load).
 */
function acps_ls_activate() {
	if ( ! class_exists( 'ACPS_LS_Install' ) ) {
		return;
	}
	try {
		ACPS_LS_Install::activate();
	} catch ( Throwable $e ) {
		acps_ls_log_error( 'activate', $e );
	}
}
register_activation_hook( __FILE__, 'acps_ls_activate' );

/**
 * Deactivation: clear the scheduled sync. Data + table are preserved.
 */
function acps_ls_deactivate() {
	if ( ! class_exists( 'ACPS_LS_Install' ) ) {
		return;
	}
	try {
		ACPS_LS_Install::deactivate();
	} catch ( Throwable $e ) {
		acps_ls_log_error( 'deactivate', $e );
	}
}
register_deactivation_hook( __FILE__, 'acps_ls_deactivate' );

/**
 * Boot the runtime pieces on every request.
 *
 * The whole body is wrapped: if anything throws (a broken file, a bad option,
 * an unexpected environment) it is logged and swallowed so the plugin can never
 * take the site down. WordPress continues to load normally.
 */
/**
 * Is the plugin currently held in safe mode (dormant after a caught fatal)?
 *
 * @return bool
 */
function acps_ls_is_safe_mode() {
	$s = get_option( ACPS_LS_SAFE_MODE_OPT );
	return is_array( $s ) && ! empty( $s['time'] );
}

/**
 * Record a caught fatal and arm safe mode so the NEXT request keeps the site up
 * by not loading the plugin's functional code.
 *
 * @param string $msg  Error message.
 * @param string $file File.
 * @param int    $line Line.
 */
function acps_ls_arm_safe_mode( $msg, $file = '', $line = 0 ) {
	update_option(
		ACPS_LS_SAFE_MODE_OPT,
		array(
			'msg'  => (string) $msg,
			'file' => (string) $file,
			'line' => (int) $line,
			'time' => time(),
		),
		true
	);
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[Cayden Link Shortener] Fatal caught — entering safe mode: ' . $msg . ' in ' . $file . ':' . $line );
	}
}

/**
 * Shutdown guard: if the request is ending on a fatal that originated inside
 * this plugin's files, arm safe mode so subsequent requests stay up. It can't
 * rescue the current request, but it stops a crash loop.
 */
function acps_ls_shutdown_guard() {
	$e = error_get_last();
	if ( ! $e || empty( $e['type'] ) ) {
		return;
	}
	$fatal = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );
	if ( ! in_array( $e['type'], $fatal, true ) ) {
		return;
	}
	if ( empty( $e['file'] ) || 0 !== strpos( $e['file'], ACPS_LS_PATH ) ) {
		return; // Not our file — leave it alone.
	}
	acps_ls_arm_safe_mode( $e['message'], $e['file'], $e['line'] );
}

/**
 * Admin notice + "Resume plugin" control shown while dormant in safe mode.
 */
function acps_ls_safe_mode_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	$s   = get_option( ACPS_LS_SAFE_MODE_OPT );
	$msg = is_array( $s ) && ! empty( $s['msg'] ) ? $s['msg'] : '';
	$url = wp_nonce_url( admin_url( 'admin-post.php?action=acps_ls_resume' ), 'acps_ls_resume' );
	echo '<div class="notice notice-error"><p><strong>'
		. esc_html__( 'Cayden Link Shortener is paused (safe mode).', 'acps-link-shortener' )
		. '</strong> '
		. esc_html__( 'A fatal error was caught in the plugin, so it stopped loading to keep the site online. The rest of the site is unaffected.', 'acps-link-shortener' )
		. '</p>'
		. ( $msg ? '<p><code>' . esc_html( $msg ) . '</code></p>' : '' )
		. '<p><a href="' . esc_url( $url ) . '" class="button button-primary">'
		. esc_html__( 'Resume plugin', 'acps-link-shortener' )
		. '</a> '
		. esc_html__( 'Use this once the problem is fixed (e.g. after an update).', 'acps-link-shortener' )
		. '</p></div>';
}

/**
 * Clear safe mode (admin action).
 */
function acps_ls_resume_from_safe_mode() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		wp_die( esc_html__( 'You do not have permission to do this.', 'acps-link-shortener' ), 403 );
	}
	check_admin_referer( 'acps_ls_resume' );
	delete_option( ACPS_LS_SAFE_MODE_OPT );
	wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
	exit;
}

function acps_ls_bootstrap() {
	if ( empty( $GLOBALS['acps_ls_loaded'] ) ) {
		return; // A required file was missing; stay out of the way.
	}

	// Always allow resuming, even while dormant.
	add_action( 'admin_post_acps_ls_resume', 'acps_ls_resume_from_safe_mode' );

	// Held dormant after a caught fatal: load only the resume notice, keep the
	// site up, and stay out of everything else until an admin resumes.
	if ( acps_ls_is_safe_mode() ) {
		if ( is_admin() ) {
			add_action( 'admin_notices', 'acps_ls_safe_mode_notice' );
		}
		return;
	}

	// Catch a fatal later in the request so following requests fall into safe
	// mode instead of crashing repeatedly.
	register_shutdown_function( 'acps_ls_shutdown_guard' );

	try {
		// Run migrations if the stored DB version is behind the code.
		ACPS_LS_Install::maybe_upgrade();

		// Rewrite rule + query var so /link/{slug} routes to us.
		( new ACPS_LS_Rewrite() )->register();

		// Redirect handler.
		( new ACPS_LS_Redirect() )->register();

		// Front-end shortcode (password-gated link creator).
		( new ACPS_LS_Shortcode() )->register();

		// Link checker (scan + HTTP checks + replacement rules).
		( new ACPS_LS_Checker() )->register();

		// Self-updater (hosted-URL or GitHub source + secret force-update URL).
		if ( class_exists( 'ACPS_LS_Updater' ) ) {
			( new ACPS_LS_Updater() )->register();
		}

		// In-admin help: Getting Started hub + guided tour + Help tabs.
		if ( is_admin() && class_exists( 'ACPS_LS_Help' ) ) {
			( new ACPS_LS_Help() )->register();
		}

		// Admin UI; load it lazily.
		if ( is_admin() ) {
			$admin_file = ACPS_LS_PATH . 'includes/class-acps-ls-admin.php';
			if ( is_readable( $admin_file ) ) {
				require_once $admin_file;
				if ( class_exists( 'ACPS_LS_Admin' ) ) {
					( new ACPS_LS_Admin() )->register();
				}
			}
		}
	} catch ( Throwable $e ) {
		// A throwable during boot (hook registration, migration, etc.): arm safe
		// mode so the next request stays up instead of crashing again.
		acps_ls_log_error( 'bootstrap', $e );
		acps_ls_arm_safe_mode( $e->getMessage(), $e->getFile(), $e->getLine() );
	}
}
add_action( 'plugins_loaded', 'acps_ls_bootstrap' );

/**
 * Register the public remote-control endpoint EARLY and OUTSIDE the safe-mode
 * gate, so it keeps working even if the rest of the plugin is dormant after a
 * caught fatal. This is the login-free recovery path (trigger an update, resume
 * from safe mode, view diagnostics). It stays fully IP/rate/password guarded.
 */
function acps_ls_boot_control() {
	if ( empty( $GLOBALS['acps_ls_loaded'] ) ) {
		return;
	}
	try {
		if ( class_exists( 'ACPS_LS_Control' ) ) {
			( new ACPS_LS_Control() )->register();
		}
	} catch ( Throwable $e ) {
		acps_ls_log_error( 'control boot', $e );
	}
}
add_action( 'plugins_loaded', 'acps_ls_boot_control', 5 );

/**
 * Post-update check: if a staged install marked "should be active" and THIS code
 * is now running cleanly, the new version loaded fine — so disarm the rollback
 * backup, make sure the plugin is active, and clear the marker.
 */
function acps_ls_post_update_check() {
	if ( ! get_option( defined( 'ACPS_LS_OPT_SHOULD_ACTIVE' ) ? ACPS_LS_OPT_SHOULD_ACTIVE : 'acps_ls_should_be_active' ) ) {
		return;
	}
	delete_option( defined( 'ACPS_LS_OPT_SHOULD_ACTIVE' ) ? ACPS_LS_OPT_SHOULD_ACTIVE : 'acps_ls_should_be_active' );
	if ( function_exists( 'opcache_reset' ) ) {
		@opcache_reset(); // phpcs:ignore
	}
	if ( function_exists( 'acps_ls_disarm_rollback' ) ) {
		acps_ls_disarm_rollback();
	}
	if ( ! empty( $GLOBALS['acps_ls_loaded'] ) && class_exists( 'ACPS_LS_Updater' ) ) {
		try {
			( new ACPS_LS_Updater() )->ensure_active();
		} catch ( Throwable $e ) {
			acps_ls_log_error( 'post_update_check', $e );
		}
	}
}
add_action( 'init', 'acps_ls_post_update_check', 20 );

/**
 * Cheap "is an update available?" test using only the cached lookup (no network).
 *
 * @return bool
 */
function acps_ls_update_available_quick() {
	$c = get_transient( 'acps_ls_update_remote' );
	if ( is_array( $c ) && ! empty( $c['version'] ) ) {
		return version_compare( $c['version'], ACPS_LS_VERSION, '>' );
	}
	return false;
}

/**
 * Conditional shortcode for Beaver Builder modules (or any content):
 *
 *   [acps_if state="ok"]Shown while the plugin is healthy[/acps_if]
 *   [acps_if state="disabled"]Shown while the plugin is paused / in safe mode[/acps_if]
 *   [acps_if state="update"]Shown when an update is available[/acps_if]
 *
 * Registered even while the plugin is dormant in safe mode, so a "temporarily
 * unavailable" message can still render. (If the plugin is fully DEACTIVATED,
 * no plugin code runs at all, so nothing — including this — can render.)
 *
 * @param array  $atts    Shortcode attributes.
 * @param string $content Enclosed content.
 * @return string
 */
function acps_ls_conditional_shortcode( $atts, $content = '' ) {
	try {
		$a       = shortcode_atts( array( 'state' => 'ok' ), $atts, 'acps_if' );
		$safe    = function_exists( 'acps_ls_is_safe_mode' ) ? acps_ls_is_safe_mode() : false;
		$loaded  = ! empty( $GLOBALS['acps_ls_loaded'] );
		$healthy = $loaded && ! $safe;
		$state   = strtolower( trim( (string) $a['state'] ) );

		switch ( $state ) {
			case 'ok':
			case 'healthy':
			case 'enabled':
			case 'active':
			case 'on':
				$match = $healthy;
				break;
			case 'disabled':
			case 'off':
			case 'safemode':
			case 'safe_mode':
			case 'paused':
			case 'broken':
			case 'down':
				$match = ! $healthy;
				break;
			case 'update':
			case 'updates':
			case 'update_available':
				$match = acps_ls_update_available_quick();
				break;
			case 'noupdate':
			case 'current':
			case 'uptodate':
				$match = ! acps_ls_update_available_quick();
				break;
			default:
				$match = $healthy;
		}
		return $match ? do_shortcode( $content ) : '';
	} catch ( Throwable $e ) {
		return '';
	}
}

/**
 * Register the conditional shortcode (always, independent of the load state).
 */
function acps_ls_register_conditional_shortcode() {
	if ( function_exists( 'add_shortcode' ) ) {
		add_shortcode( 'acps_if', 'acps_ls_conditional_shortcode' );
		add_shortcode( 'acps_ls_if', 'acps_ls_conditional_shortcode' );
	}
}
add_action( 'init', 'acps_ls_register_conditional_shortcode' );

/**
 * Minimal IP allow/block check for the failsafe (mirrors the control class but
 * has no dependencies, so it works when the plugin's classes failed to load).
 *
 * @param string $ip Client IP.
 * @param array  $s  Settings option.
 * @return bool
 */
function acps_ls_ip_ok_min( $ip, $s ) {
	$match = function ( $ip, $rule ) {
		$rule = trim( (string) $rule );
		if ( '' === $rule ) {
			return false;
		}
		if ( '*' === substr( $rule, -1 ) ) {
			$rule = substr( $rule, 0, -1 );
		}
		if ( $rule === $ip ) {
			return true;
		}
		return ( '' !== $rule && 0 === strpos( $ip, $rule ) );
	};

	$allow = ( isset( $s['ctrl_allow'] ) && is_array( $s['ctrl_allow'] ) ) ? $s['ctrl_allow'] : array();
	$block = ( isset( $s['ctrl_block'] ) && is_array( $s['ctrl_block'] ) ) ? $s['ctrl_block'] : array();
	if ( empty( $allow ) && empty( $block ) && isset( $s['ctrl_ips'] ) && is_array( $s['ctrl_ips'] ) ) {
		if ( isset( $s['ctrl_ip_mode'] ) && 'deny' === $s['ctrl_ip_mode'] ) {
			$block = $s['ctrl_ips'];
		} else {
			$allow = $s['ctrl_ips'];
		}
	}
	foreach ( (array) $block as $r ) {
		if ( $match( $ip, $r ) ) {
			return false;
		}
	}
	$allow = array_filter( array_map( 'trim', (array) $allow ) );
	if ( empty( $allow ) ) {
		return true;
	}
	foreach ( $allow as $r ) {
		if ( $match( $ip, $r ) ) {
			return true;
		}
	}
	return false;
}

/**
 * FAILSAFE recovery: if the plugin failed to load (a class file is missing or
 * corrupt) yet the control URL is hit with the correct key, re-download and
 * reinstall the latest package straight from the configured update source, using
 * only WordPress core — no plugin classes required. This makes the update/status
 * URL self-healing: a bad edit that breaks the plugin can be fixed by visiting
 * the URL, which pulls a clean copy and overwrites the files.
 */
function acps_ls_selfheal() {
	try {
		if ( ! isset( $_GET['acpsupdater'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		// If the plugin loaded normally, the control endpoint handles everything.
		if ( ! empty( $GLOBALS['acps_ls_loaded'] ) && class_exists( 'ACPS_LS_Control' ) ) {
			return;
		}

		$s = get_option( 'acps_ls_settings' );
		if ( ! is_array( $s ) || empty( $s['ctrl_enabled'] ) || empty( $s['ctrl_key'] ) ) {
			return;
		}
		$given = (string) wp_unslash( $_GET['acpsupdater'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput
		if ( ! hash_equals( (string) $s['ctrl_key'], $given ) ) {
			return;
		}

		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		if ( ! acps_ls_ip_ok_min( $ip, $s ) ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		// Light rate limit: at most 3 recovery attempts per 10 minutes per IP.
		$rk = 'acps_ls_heal_' . md5( $ip );
		$n  = (int) get_transient( $rk );
		if ( $n >= 3 ) {
			status_header( 429 );
			nocache_headers();
			exit( 'RATE_LIMITED' );
		}
		set_transient( $rk, $n + 1, 600 );

		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo "Cayden Link Shortener — FAILSAFE recovery\n";
		echo "The plugin files are incomplete or broken; re-downloading the latest package...\n\n";
		echo esc_html( acps_ls_selfheal_reinstall( $s ) );
		exit;
	} catch ( Throwable $e ) {
		acps_ls_log_error( 'selfheal', $e );
	}
}
add_action( 'init', 'acps_ls_selfheal', 0 );

/**
 * Download the latest package from the configured source and overwrite the
 * plugin directory. Core-only; returns a plain-text log.
 *
 * @param array $s Settings option.
 * @return string
 */
function acps_ls_selfheal_reinstall( $s ) {
	try {
		$package = '';
		$source  = ( isset( $s['update_source'] ) && 'github' === $s['update_source'] ) ? 'github' : 'url';

		if ( 'url' === $source ) {
			$manifest = isset( $s['update_manifest'] ) ? trim( (string) $s['update_manifest'] ) : '';
			if ( '' === $manifest ) {
				return "No manifest URL is configured, so automatic recovery is not possible. Re-upload the plugin ZIP in wp-admin.\n";
			}
			if ( ! empty( $s['update_manifest_key'] ) ) {
				$manifest = add_query_arg( 'key', rawurlencode( $s['update_manifest_key'] ), $manifest );
			}
			$resp = wp_remote_get( $manifest, array( 'timeout' => 20 ) );
			if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
				return "Could not fetch the manifest.\n";
			}
			$data = json_decode( wp_remote_retrieve_body( $resp ), true );
			if ( ! is_array( $data ) || empty( $data['download_url'] ) ) {
				return "Manifest did not contain a download_url.\n";
			}
			$package = (string) $data['download_url'];
		} else {
			$owner = isset( $s['gh_owner'] ) ? trim( (string) $s['gh_owner'] ) : '';
			$repo  = isset( $s['gh_repo'] ) ? trim( (string) $s['gh_repo'] ) : '';
			$asset = ( isset( $s['gh_asset'] ) && $s['gh_asset'] ) ? (string) $s['gh_asset'] : 'acps-link-shortener.zip';
			if ( '' === $owner || '' === $repo ) {
				return "No GitHub owner/repo is configured.\n";
			}
			$url  = sprintf( 'https://api.github.com/repos/%s/%s/releases/latest', rawurlencode( $owner ), rawurlencode( $repo ) );
			$resp = wp_remote_get( $url, array( 'timeout' => 20, 'headers' => array( 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'ACPS-LS-Selfheal' ) ) );
			if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
				return "Could not fetch the GitHub release.\n";
			}
			$rel = json_decode( wp_remote_retrieve_body( $resp ), true );
			if ( is_array( $rel ) && ! empty( $rel['assets'] ) && is_array( $rel['assets'] ) ) {
				foreach ( $rel['assets'] as $ga ) {
					if ( isset( $ga['name'] ) && $ga['name'] === $asset && ! empty( $ga['browser_download_url'] ) ) {
						$package = (string) $ga['browser_download_url'];
						break;
					}
				}
			}
			if ( '' === $package ) {
				return "Could not find a public release asset named {$asset}. (Private-repo recovery needs the plugin's own downloader, which is unavailable while broken. Re-upload the ZIP in wp-admin.)\n";
			}
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		// Force the credential-free direct filesystem method (logged-out request).
		$force_direct = static function () {
			return 'direct';
		};
		add_filter( 'filesystem_method', $force_direct, 99 );
		WP_Filesystem();
		remove_filter( 'filesystem_method', $force_direct, 99 );

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$dir      = dirname( plugin_basename( __FILE__ ) );
		$result   = $upgrader->run(
			array(
				'package'           => $package,
				'destination'       => WP_PLUGIN_DIR . '/' . $dir,
				'clear_destination' => true,
				'clear_working'     => true,
				'hook_extra'        => array( 'type' => 'plugin', 'action' => 'update' ),
			)
		);

		$out = '';
		foreach ( (array) $skin->get_upgrade_messages() as $m ) {
			$out .= ' - ' . wp_strip_all_tags( (string) $m ) . "\n";
		}
		if ( function_exists( 'opcache_reset' ) ) {
			@opcache_reset(); // phpcs:ignore
		}
		if ( is_wp_error( $result ) ) {
			return $out . "\nFAILED: " . $result->get_error_message() . "\n";
		}
		if ( false === $result || null === $result ) {
			return $out . "\nFAILED: the files could not be written (filesystem permissions?).\n";
		}
		// Clear safe mode so the freshly-installed copy loads next request.
		delete_option( 'acps_ls_safe_mode' );
		return $out . "\nSUCCESS: the latest version was reinstalled. Reload the page.\n";
	} catch ( Throwable $e ) {
		return 'Error: ' . $e->getMessage() . "\n";
	}
}

/**
 * Register the checker cron schedule.
 *
 * @param array $schedules Existing schedules.
 * @return array
 */
function acps_ls_cron_schedules( $schedules ) {
	$schedules[ ACPS_LS_CHECK_INTERVAL ] = array(
		'interval' => 10 * MINUTE_IN_SECONDS,
		'display'  => __( 'Every 10 minutes (Cayden Link Shortener checker)', 'acps-link-shortener' ),
	);
	return $schedules;
}
add_filter( 'cron_schedules', 'acps_ls_cron_schedules' );

// The Google Sheet sync was removed; make sure its old cron event is cleared.
add_action( 'plugins_loaded', function () {
	if ( wp_next_scheduled( 'acps_ls_sheet_sync' ) ) {
		wp_clear_scheduled_hook( 'acps_ls_sheet_sync' );
	}
}, 20 );

/* ------------------------------------------------------------------------- *
 * Robust self-update helpers (host-workaround recipe).
 *
 * On some hosts (e.g. WP Engine) a logged-out request may not overwrite a .php
 * file that is currently loaded, even though the folder is writable and brand
 * new files write fine. The staged-install pattern writes the new files to a
 * staging folder now (allowed), then swaps them in during the pristine bootstrap
 * window above — before this plugin's own includes are loaded — which is the one
 * instant those files are not in use. A file backup provides rollback if the new
 * code fatals. All of this is plain PHP so it works with as little loaded as
 * possible.
 * ------------------------------------------------------------------------- */

/**
 * Recursively copy $src into $dst with plain PHP. Continues past a single failed
 * file (retrying with chmod) and returns false if any file could not be copied.
 *
 * @param string $src Source dir.
 * @param string $dst Destination dir.
 * @return bool
 */
function acps_ls_copy_tree( $src, $dst ) {
	$src = untrailingslashit( (string) $src );
	$dst = untrailingslashit( (string) $dst );
	if ( '' === $src || ! is_dir( $src ) ) {
		return false;
	}
	if ( ! is_dir( $dst ) ) {
		@mkdir( $dst, 0755, true ); // phpcs:ignore
	}
	$ok = true;
	try {
		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ( $it as $item ) {
			$rel    = substr( $item->getPathname(), strlen( $src ) + 1 );
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
					$ok = false;
				}
			}
		}
	} catch ( Throwable $e ) {
		return false;
	}
	return $ok;
}

/**
 * Recursively delete a directory (plain PHP).
 *
 * @param string $dir Directory.
 */
function acps_ls_remove_tree( $dir ) {
	$dir = untrailingslashit( (string) $dir );
	if ( '' === $dir || ! is_dir( $dir ) ) {
		return;
	}
	try {
		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $it as $item ) {
			if ( $item->isDir() ) {
				@rmdir( $item->getPathname() ); // phpcs:ignore
			} else {
				@unlink( $item->getPathname() ); // phpcs:ignore
			}
		}
		@rmdir( $dir ); // phpcs:ignore
	} catch ( Throwable $e ) {
		// best effort
	}
}

/**
 * Back up the current plugin files before a swap, so a bad release can be undone.
 *
 * @param string $version Current version (recorded for reference).
 */
function acps_ls_arm_rollback( $version = '' ) {
	try {
		$backup = WP_CONTENT_DIR . '/acps-ls-rollback-' . wp_generate_password( 8, false, false );
		if ( acps_ls_copy_tree( untrailingslashit( ACPS_LS_PATH ), $backup ) ) {
			update_option( ACPS_LS_OPT_ROLLBACK, array( 'dir' => $backup, 'version' => (string) $version, 'time' => time() ), false );
		}
	} catch ( Throwable $e ) {
		acps_ls_log_error( 'arm_rollback', $e );
	}
}

/**
 * Drop the rollback backup (the new code loaded cleanly).
 */
function acps_ls_disarm_rollback() {
	$rb = get_option( ACPS_LS_OPT_ROLLBACK );
	if ( is_array( $rb ) && ! empty( $rb['dir'] ) ) {
		acps_ls_remove_tree( $rb['dir'] );
	}
	delete_option( ACPS_LS_OPT_ROLLBACK );
}

/**
 * If an update armed a rollback and the last request tripped safe mode (the new
 * code fataled), restore the backup now — in the pristine window, before the
 * broken includes load.
 */
function acps_ls_maybe_rollback() {
	try {
		$rb = get_option( ACPS_LS_OPT_ROLLBACK );
		if ( ! is_array( $rb ) || empty( $rb['dir'] ) || ! is_dir( $rb['dir'] ) ) {
			return;
		}
		if ( ! acps_ls_is_safe_mode() ) {
			return; // Healthy — leave the backup until the post-update check disarms it.
		}
		acps_ls_copy_tree( $rb['dir'], untrailingslashit( ACPS_LS_PATH ) );
		acps_ls_remove_tree( $rb['dir'] );
		delete_option( ACPS_LS_OPT_ROLLBACK );
		delete_option( ACPS_LS_SAFE_MODE_OPT );
		if ( function_exists( 'opcache_reset' ) ) {
			@opcache_reset(); // phpcs:ignore
		}
	} catch ( Throwable $e ) {
		acps_ls_log_error( 'maybe_rollback', $e );
	}
}

/**
 * Apply a staged install in the pristine window: back up current files, copy the
 * staged files over the live plugin directory, reset opcache, and mark that the
 * plugin should be active. Runs before the includes load.
 */
function acps_ls_apply_staged() {
	try {
		$stage = get_option( ACPS_LS_OPT_STAGED );
		if ( ! is_array( $stage ) || empty( $stage['dir'] ) || ! is_dir( $stage['dir'] ) ) {
			if ( false !== $stage ) {
				delete_option( ACPS_LS_OPT_STAGED );
			}
			return;
		}
		acps_ls_arm_rollback( ACPS_LS_VERSION );
		$ok = acps_ls_copy_tree( $stage['dir'], untrailingslashit( ACPS_LS_PATH ) );
		acps_ls_remove_tree( $stage['dir'] );
		delete_option( ACPS_LS_OPT_STAGED );

		if ( $ok ) {
			if ( function_exists( 'opcache_reset' ) ) {
				@opcache_reset(); // phpcs:ignore
			}
			update_option( ACPS_LS_OPT_SHOULD_ACTIVE, 1, false );
			delete_option( ACPS_LS_SAFE_MODE_OPT );
		} else {
			acps_ls_disarm_rollback();
		}
	} catch ( Throwable $e ) {
		acps_ls_log_error( 'apply_staged', $e );
	}
}

/**
 * Locate the folder inside an unzipped release that contains the main plugin
 * file (acps-link-shortener.php). Handles zips that unpack to a subfolder.
 *
 * @param string $base Unzip base dir.
 * @return string Absolute path to the plugin folder, or '' if not found.
 */
function acps_ls_locate_main_dir( $base ) {
	$base = untrailingslashit( (string) $base );
	if ( file_exists( $base . '/acps-link-shortener.php' ) ) {
		return $base;
	}
	foreach ( (array) glob( $base . '/*', GLOB_ONLYDIR ) as $d ) {
		if ( file_exists( $d . '/acps-link-shortener.php' ) ) {
			return $d;
		}
	}
	return '';
}
