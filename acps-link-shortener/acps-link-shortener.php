<?php
/**
 * Plugin Name:       Cayden Link Shortener
 * Plugin URI:        https://caydenriddle.com/
 * Description:       Self-hosted, branded URL shortener. Creates short-link redirects with click tracking, an accessible admin UI, a password-gated front-end dashboard for staff, and two-way Google Sheet sync.
 * Version:           1.8.0
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
define( 'ACPS_LS_VERSION', '1.8.0' );
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

// WP-Cron hook + interval for the two-way Google Sheet sync.
define( 'ACPS_LS_CRON_HOOK', 'acps_ls_sheet_sync' );
define( 'ACPS_LS_CRON_INTERVAL', 'acps_ls_three_minutes' );

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
		'includes/class-acps-ls-sync.php',
		'includes/class-acps-ls-checker.php',
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
function acps_ls_bootstrap() {
	if ( empty( $GLOBALS['acps_ls_loaded'] ) ) {
		return; // A required file was missing; stay out of the way.
	}

	try {
		// Run migrations if the stored DB version is behind the code.
		ACPS_LS_Install::maybe_upgrade();

		// Rewrite rule + query var so /link/{slug} routes to us.
		( new ACPS_LS_Rewrite() )->register();

		// Redirect handler.
		( new ACPS_LS_Redirect() )->register();

		// Front-end shortcode (password-gated link creator).
		( new ACPS_LS_Shortcode() )->register();

		// Two-way Google Sheet sync (WP-Cron).
		( new ACPS_LS_Sync() )->register();

		// Link checker (scan + HTTP checks + replacement rules).
		( new ACPS_LS_Checker() )->register();

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
		acps_ls_log_error( 'bootstrap', $e );
	}
}
add_action( 'plugins_loaded', 'acps_ls_bootstrap' );

/**
 * Register a 3-minute cron schedule for the Sheet sync.
 *
 * @param array $schedules Existing schedules.
 * @return array
 */
function acps_ls_cron_schedules( $schedules ) {
	$schedules[ ACPS_LS_CRON_INTERVAL ] = array(
		'interval' => 3 * MINUTE_IN_SECONDS,
		'display'  => __( 'Every 3 minutes (Cayden Link Shortener sync)', 'acps-link-shortener' ),
	);
	$schedules[ ACPS_LS_CHECK_INTERVAL ] = array(
		'interval' => 10 * MINUTE_IN_SECONDS,
		'display'  => __( 'Every 10 minutes (Cayden Link Shortener checker)', 'acps-link-shortener' ),
	);
	return $schedules;
}
add_filter( 'cron_schedules', 'acps_ls_cron_schedules' );
