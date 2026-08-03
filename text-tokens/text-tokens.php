<?php
/**
 * Plugin Name:       Text Tokens
 * Plugin URI:        https://github.com/trentmontgomery222/coding-help
 * Description:       Define placeholder tokens like [SCHOOL-YEAR] and have them automatically replaced with static or dynamically calculated values everywhere text is rendered — including Beaver Builder modules, widgets, and post content.
 * Version:           1.0.0
 * Requires at least: 5.6
 * Requires PHP:      7.2
 * Author:            ACPS
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       text-tokens
 *
 * @package TextTokens
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TT_VERSION', '1.0.0' );
define( 'TT_PLUGIN_FILE', __FILE__ );
define( 'TT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Option keys used by the plugin.
 *
 * TT_OPTION_TOKENS   Stores the array of token definitions.
 * TT_OPTION_SETTINGS Stores global plugin settings (e.g. cache TTL).
 */
define( 'TT_OPTION_TOKENS', 'tt_tokens' );
define( 'TT_OPTION_SETTINGS', 'tt_settings' );

require_once TT_PLUGIN_DIR . 'includes/class-tt-rules.php';
require_once TT_PLUGIN_DIR . 'includes/class-tt-tokens.php';
require_once TT_PLUGIN_DIR . 'includes/class-tt-resolver.php';
require_once TT_PLUGIN_DIR . 'includes/class-tt-replacer.php';

if ( is_admin() ) {
	require_once TT_PLUGIN_DIR . 'admin/class-tt-admin.php';
}

/**
 * Boot the plugin.
 *
 * @return void
 */
function tt_bootstrap() {
	// Front-end / everywhere replacement.
	$replacer = new TT_Replacer();
	$replacer->register_hooks();

	// Admin settings screen.
	if ( is_admin() ) {
		$admin = new TT_Admin();
		$admin->register_hooks();
	}
}
add_action( 'plugins_loaded', 'tt_bootstrap' );

/**
 * Activation: seed default settings and flush the resolved-value cache.
 *
 * @return void
 */
function tt_activate() {
	if ( false === get_option( TT_OPTION_SETTINGS ) ) {
		add_option(
			TT_OPTION_SETTINGS,
			array(
				'cache_ttl' => HOUR_IN_SECONDS,
			)
		);
	}

	if ( false === get_option( TT_OPTION_TOKENS ) ) {
		add_option( TT_OPTION_TOKENS, array() );
	}

	TT_Resolver::flush_cache();
}
register_activation_hook( __FILE__, 'tt_activate' );

/**
 * Deactivation: clear the transient cache of resolved values.
 *
 * @return void
 */
function tt_deactivate() {
	TT_Resolver::flush_cache();
}
register_deactivation_hook( __FILE__, 'tt_deactivate' );
