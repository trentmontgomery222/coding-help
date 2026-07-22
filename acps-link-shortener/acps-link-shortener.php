<?php
/**
 * Plugin Name:       ACPS Link Shortener
 * Plugin URI:        https://acpsmd.org/
 * Description:       Self-hosted, branded URL shortener. Creates short-link redirects with click tracking, an accessible admin UI, a password-gated front-end dashboard for staff, and two-way Google Sheet sync.
 * Version:           1.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            ACPS
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
					__( 'ACPS Link Shortener requires PHP 7.4 or newer. This server runs PHP %s. Please update PHP, then activate the plugin.', 'acps-link-shortener' ),
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
define( 'ACPS_LS_VERSION', '1.2.0' );
define( 'ACPS_LS_DB_VERSION', '1.1.0' );
define( 'ACPS_LS_SLUG_PREFIX', '' );
define( 'ACPS_LS_QUERY_VAR', 'acps_ls_slug' );
define( 'ACPS_LS_FILE', __FILE__ );
define( 'ACPS_LS_PATH', plugin_dir_path( __FILE__ ) );
define( 'ACPS_LS_URL', plugin_dir_url( __FILE__ ) );
define( 'ACPS_LS_BASENAME', plugin_basename( __FILE__ ) );

// Option keys.
define( 'ACPS_LS_OPT_DB_VERSION', 'acps_ls_db_version' );
define( 'ACPS_LS_OPT_SETTINGS', 'acps_ls_settings' );

// WP-Cron hook + interval for the two-way Google Sheet sync.
define( 'ACPS_LS_CRON_HOOK', 'acps_ls_sheet_sync' );
define( 'ACPS_LS_CRON_INTERVAL', 'acps_ls_three_minutes' );

/**
 * Load plugin classes.
 */
require_once ACPS_LS_PATH . 'includes/class-acps-ls-install.php';
require_once ACPS_LS_PATH . 'includes/class-acps-ls-db.php';
require_once ACPS_LS_PATH . 'includes/class-acps-ls-rewrite.php';
require_once ACPS_LS_PATH . 'includes/class-acps-ls-redirect.php';
require_once ACPS_LS_PATH . 'includes/class-acps-ls-shortcode.php';
require_once ACPS_LS_PATH . 'includes/class-acps-ls-sync.php';

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
		if ( ! empty( $person['label'] ) && ! empty( $person['hash'] ) ) {
			$clean[] = array(
				'label'     => (string) $person['label'],
				'hash'      => (string) $person['hash'],
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
		if ( strtolower( $person['label'] ) === strtolower( $name ) && wp_check_password( $password, $person['hash'] ) ) {
			return $person['label'];
		}
	}
	return false;
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
 * Activation: build the table, seed options, flush rewrite rules, schedule cron.
 *
 * Rewrite rules are flushed here ONLY (never on every load).
 */
function acps_ls_activate() {
	ACPS_LS_Install::activate();
}
register_activation_hook( __FILE__, 'acps_ls_activate' );

/**
 * Deactivation: clear the scheduled sync. Data + table are preserved.
 */
function acps_ls_deactivate() {
	ACPS_LS_Install::deactivate();
}
register_deactivation_hook( __FILE__, 'acps_ls_deactivate' );

/**
 * Boot the runtime pieces on every request.
 */
function acps_ls_bootstrap() {
	// Run migrations if the stored DB version is behind the code.
	ACPS_LS_Install::maybe_upgrade();

	// Rewrite rule + query var so /link/{slug} routes to us.
	$rewrite = new ACPS_LS_Rewrite();
	$rewrite->register();

	// Redirect handler.
	$redirect = new ACPS_LS_Redirect();
	$redirect->register();

	// Front-end shortcode (password-gated link creator). Runs on the front end
	// and handles its own form submission, so it is always registered.
	$shortcode = new ACPS_LS_Shortcode();
	$shortcode->register();

	// Two-way Google Sheet sync (WP-Cron + REST test endpoint).
	$sync = new ACPS_LS_Sync();
	$sync->register();

	// Admin UI; load it lazily.
	if ( is_admin() ) {
		require_once ACPS_LS_PATH . 'includes/class-acps-ls-admin.php';
		$admin = new ACPS_LS_Admin();
		$admin->register();
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
		'display'  => __( 'Every 3 minutes (ACPS Link Shortener sync)', 'acps-link-shortener' ),
	);
	return $schedules;
}
add_filter( 'cron_schedules', 'acps_ls_cron_schedules' );
