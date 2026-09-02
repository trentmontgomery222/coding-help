<?php
/**
 * Plugin Name:       WPCode Values for Beaver Builder
 * Plugin URI:        https://acpsmd.org
 * Description:       Exposes editable "value" fields for your WPCode snippets as a Beaver Builder module, so page editors can tweak snippet values (via shortcode attributes) directly from the Beaver Builder editor without touching code.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            ACPS
 * Text Domain:       wpcode-bb-bridge
 * Network:           false
 *
 * This plugin is site-managed only. It intentionally does NOT support
 * network activation - it is meant to be activated per-site from the
 * normal wp-admin > Plugins screen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'WPCODEBB_VERSION', '1.0.0' );
define( 'WPCODEBB_FILE', __FILE__ );
define( 'WPCODEBB_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPCODEBB_URL', plugin_dir_url( __FILE__ ) );
define( 'WPCODEBB_CPT', 'wpcodebb_config' );

/**
 * Refuse to run as a network-activated plugin. This plugin manages
 * per-site configurations only and is not designed for multisite
 * network administration.
 */
function wpcodebb_is_network_activated() {
	if ( ! is_multisite() ) {
		return false;
	}

	if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	return is_plugin_active_for_network( plugin_basename( WPCODEBB_FILE ) );
}

/**
 * Bootstrap the plugin once all other plugins have loaded, so we can
 * reliably detect whether WPCode and Beaver Builder are active.
 */
function wpcodebb_bootstrap() {
	if ( wpcodebb_is_network_activated() ) {
		add_action( 'admin_notices', 'wpcodebb_network_activation_notice' );
		return;
	}

	require_once WPCODEBB_DIR . 'includes/class-wpcodebb-config-cpt.php';
	require_once WPCODEBB_DIR . 'includes/class-wpcodebb-admin.php';
	require_once WPCODEBB_DIR . 'includes/class-wpcodebb-bb-module.php';

	WPCodeBB_Config_CPT::instance();
	WPCodeBB_Admin::instance();
	WPCodeBB_BB_Module::instance();
}
add_action( 'plugins_loaded', 'wpcodebb_bootstrap' );

/**
 * Friendly admin notices for missing requirements. These do not block
 * activation - Beaver Builder or WPCode may simply not be active yet,
 * or may be activated afterwards.
 */
function wpcodebb_network_activation_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<?php esc_html_e( 'WPCode Values for Beaver Builder is not supported as a network-activated plugin. Please network-deactivate it and activate it individually on each site that needs it.', 'wpcode-bb-bridge' ); ?>
		</p>
	</div>
	<?php
}

function wpcodebb_missing_requirements_notice() {
	$missing = array();

	if ( ! class_exists( 'FLBuilder' ) ) {
		$missing[] = __( 'Beaver Builder', 'wpcode-bb-bridge' );
	}

	if ( ! function_exists( 'wpcode_init' ) && ! post_type_exists( 'wpcode' ) ) {
		$missing[] = __( 'WPCode', 'wpcode-bb-bridge' );
	}

	if ( empty( $missing ) ) {
		return;
	}
	?>
	<div class="notice notice-warning">
		<p>
			<?php
			printf(
				/* translators: %s: comma separated list of missing plugin names */
				esc_html__( 'WPCode Values for Beaver Builder works best with %s installed and active. You can still create configurations now, but the Beaver Builder module and snippet detection will be limited until then.', 'wpcode-bb-bridge' ),
				esc_html( implode( ', ', $missing ) )
			);
			?>
		</p>
	</div>
	<?php
}
add_action( 'admin_notices', 'wpcodebb_missing_requirements_notice' );

/**
 * Register the configuration CPT on activation too, so rewrite rules
 * (not that we need any, since the CPT is not public) and capabilities
 * are ready immediately.
 */
function wpcodebb_activate() {
	require_once WPCODEBB_DIR . 'includes/class-wpcodebb-config-cpt.php';
	WPCodeBB_Config_CPT::instance()->register_post_type();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'wpcodebb_activate' );

function wpcodebb_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'wpcodebb_deactivate' );
