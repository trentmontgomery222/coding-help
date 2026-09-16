<?php
/**
 * Plugin Name:       WPSearch Quick Results
 * Plugin URI:        https://github.com/trentmontgomery222/coding-help
 * Description:       Serves popular searches from a cache instead of re-running the search engine, and filters what appears in the results.
 * Version:           1.2.0
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

define( 'WPSQR_VERSION', '1.2.0' );
define( 'WPSQR_FILE', __FILE__ );
define( 'WPSQR_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPSQR_URL', plugin_dir_url( __FILE__ ) );

// Schema version, bumped when the tables change.
define( 'WPSQR_DB_VERSION', 2 );

require_once WPSQR_PATH . 'includes/class-wpsqr-normalizer.php';
require_once WPSQR_PATH . 'includes/class-wpsqr-schema.php';
require_once WPSQR_PATH . 'includes/class-wpsqr-cache.php';
require_once WPSQR_PATH . 'includes/class-wpsqr-stats.php';
require_once WPSQR_PATH . 'includes/class-wpsqr-observer.php';
require_once WPSQR_PATH . 'includes/class-wpsqr-status.php';
require_once WPSQR_PATH . 'includes/class-wpsqr-rules.php';
require_once WPSQR_PATH . 'includes/class-wpsqr-postlist.php';
require_once WPSQR_PATH . 'includes/class-wpsqr-people.php';
require_once WPSQR_PATH . 'includes/class-wpsqr-directory.php';
require_once WPSQR_PATH . 'includes/class-wpsqr-hidden.php';
require_once WPSQR_PATH . 'includes/class-wpsqr-engine.php';
require_once WPSQR_PATH . 'includes/class-wpsqr-renderer.php';
require_once WPSQR_PATH . 'includes/class-wpsqr-warmer.php';
require_once WPSQR_PATH . 'includes/class-wpsqr-searchwp.php';
require_once WPSQR_PATH . 'includes/class-wpsqr-assets.php';
require_once WPSQR_PATH . 'includes/class-wpsqr-admin.php';
require_once WPSQR_PATH . 'includes/class-wpsqr-plugin.php';

/**
 * @return WPSQR_Plugin
 */
function wpsqr() {
	static $instance = null;

	if ( null === $instance ) {
		$instance = new WPSQR_Plugin();
	}

	return $instance;
}

add_action( 'plugins_loaded', array( wpsqr(), 'boot' ) );

register_activation_hook( __FILE__, array( 'WPSQR_Schema', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WPSQR_Warmer', 'unschedule' ) );
