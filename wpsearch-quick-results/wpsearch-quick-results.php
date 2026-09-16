<?php
/**
 * Plugin Name:       WPSearch Quick Results
 * Plugin URI:        https://github.com/trentmontgomery222/coding-help
 * Description:       Serves popular searches from a cache instead of re-running the search engine, and filters what appears in the results.
 * Version:           1.6.4
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Allegany County Public Schools
 * License:           GPL-2.0-or-later
 * Text Domain:       wpsqr
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS DOES, AND WHAT IT DOESN'T
 *
 * A search on this site is slow because SearchWP scores every indexed row
 * against the term on every request. But the same handful of terms get typed
 * over and over — "staff", "calendar", "lunch menu", "enrollment". Scoring
 * them again each time is the waste.
 *
 * So: the first time a term is searched, the result IDs are stored. Every
 * search after that is a primary-key lookup and a post fetch, with no index
 * scoring at all. A cron job keeps the most popular terms warm, so the common
 * searches are usually already waiting.
 *
 * This is a caching layer. It does not make an uncached search faster — if
 * every search on the site were unique, this would do nothing but add a
 * write. It is worth having because real search traffic is extremely
 * top-heavy, which the Popular Searches screen will show you directly.
 * ---------------------------------------------------------------------------
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPSQR_VERSION', '1.6.4' );
define( 'WPSQR_FILE', __FILE__ );
define( 'WPSQR_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPSQR_URL', plugin_dir_url( __FILE__ ) );

// Schema version, bumped when the tables change.
define( 'WPSQR_DB_VERSION', 3 );

/*
 * The guard loads first and by itself, because it is what runs when nothing
 * else could. A require on a missing file is fatal, so even this one is
 * guarded — absent, the plugin simply does not load, rather than white-
 * screening the site.
 */
if ( ! is_readable( WPSQR_PATH . 'includes/class-wpsqr-guard.php' ) ) {
	return;
}

require_once WPSQR_PATH . 'includes/class-wpsqr-guard.php';

if ( ! class_exists( 'WPSQR_Guard' ) ) {
	return;
}

// Catch a fatal in this plugin's own code and trip safe mode for next time,
// so a bad release cannot take the site down until someone reaches the server.
WPSQR_Guard::register_shutdown_guard();

// Already in safe mode from a previous crash. If it was a bad update that
// tripped it, revert to the version before the update rather than sit paused —
// the plugin is never left disabled; at worst it runs the previous version.
if ( WPSQR_Guard::is_safe_mode() ) {
	if ( WPSQR_Guard::maybe_rollback() ) {
		// The previous version's files are back. This request has already
		// loaded none of the plugin; the next one loads the reverted code
		// normally, with safe mode cleared. Nothing more to do here.
		return;
	}

	// No rollback available — a crash that was not an update. Show the
	// recovery notice, still without loading whatever crashed.
	if ( is_admin() ) {
		WPSQR_Guard::run_safe_mode();
	}

	return;
}

// Load the rest, tolerating a missing include rather than fataling on it.
WPSQR_Guard::load_includes();

/**
 * @return WPSQR_Plugin|null
 */
function wpsqr() {
	static $instance = null;

	if ( null === $instance && class_exists( 'WPSQR_Plugin' ) ) {
		$instance = new WPSQR_Plugin();
	}

	return $instance;
}

/*
 * boot() runs inside a try/catch: a thrown error during setup is turned into
 * safe mode rather than a broken request. A fatal (which cannot be caught) is
 * still handled by the shutdown guard above.
 */
add_action(
	'plugins_loaded',
	static function () {
		if ( ! WPSQR_Guard::core_loaded() ) {
			// Core classes did not load — a corrupt install. Say so in admin,
			// but do not try to boot into a second fatal.
			if ( is_admin() ) {
				add_action(
					'admin_notices',
					static function () {
						if ( current_user_can( 'activate_plugins' ) ) {
							echo '<div class="notice notice-error"><p><strong>WPSearch Quick Results could not load.</strong> Some of its files are missing — reinstall the plugin.</p></div>';
						}
					}
				);
			}

			return;
		}

		try {
			$plugin = wpsqr();

			if ( $plugin ) {
				$plugin->boot();
			}
		} catch ( \Throwable $e ) {
			WPSQR_Guard::enter_safe_mode( 'boot: ' . $e->getMessage() );

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'WPSQR boot failed: ' . $e->getMessage() ); // phpcs:ignore
			}
		}
	}
);

register_activation_hook(
	__FILE__,
	static function () {
		// A resume after a bad version that is then reinstalled clean.
		WPSQR_Guard::leave_safe_mode();

		if ( class_exists( 'WPSQR_Schema' ) ) {
			WPSQR_Schema::activate();
		}

		// Give the remote endpoint a key so it exists from first activation,
		// rather than only once someone visits its settings.
		if ( class_exists( 'WPSQR_Remote' ) ) {
			WPSQR_Remote::ensure_configured();
		}
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		if ( class_exists( 'WPSQR_Warmer' ) ) {
			WPSQR_Warmer::unschedule();
		}
	}
);
