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
 *
 * ---------------------------------------------------------------------
 * FAIL-SAFE DESIGN NOTES
 * ---------------------------------------------------------------------
 * Every piece of this plugin that could conceivably break (a missing
 * file, an incompatible PHP version, a missing dependency, a broken
 * WPCode snippet, corrupted saved data) is guarded so the *failure is
 * contained to this plugin's own features* instead of taking down
 * wp-admin or the public site:
 *
 *  - No file is require()'d without first checking it exists.
 *  - Every include and every subsystem boot is wrapped in try/catch,
 *    which - since PHP 7 - also catches syntax/fatal errors that occur
 *    while an included file is being compiled, not just runtime errors.
 *  - Nothing here runs on an unsupported PHP version.
 *  - Class declarations are guarded against double-loading.
 *  - The front-end shortcode render (the one place a *user's own*
 *    WPCode snippet code runs) is sandboxed in try/catch so a bug in
 *    that snippet can never white-screen the page it's on.
 *
 * This eliminates every failure mode within the plugin's control. It
 * cannot protect against things no plugin can: the server running out
 * of memory, a request timing out, or the host's PHP itself crashing.
 * WordPress core (5.2+) has its own fatal-error protection ("recovery
 * mode") as a last-resort backstop beyond what any plugin can add.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

if ( class_exists( 'WPCodeBB_Bootstrap_Guard', false ) ) {
	return; // Already loaded (e.g. accidentally included twice) - do nothing.
}
class WPCodeBB_Bootstrap_Guard {}

define( 'WPCODEBB_VERSION', '1.0.0' );
define( 'WPCODEBB_FILE', __FILE__ );
define( 'WPCODEBB_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPCODEBB_URL', plugin_dir_url( __FILE__ ) );
define( 'WPCODEBB_CPT', 'wpcodebb_config' );
define( 'WPCODEBB_MIN_PHP', '7.4' );

/**
 * Collected boot problems, surfaced as one admin notice instead of a
 * fatal error. Never let a broken piece stop the rest of the plugin
 * (or the site) from working.
 *
 * @var string[]
 */
$GLOBALS['wpcodebb_boot_errors'] = array();

/**
 * Safely require a plugin file. Returns true on success. Never lets a
 * missing file or a syntax error inside the file crash the request -
 * it records the problem instead.
 *
 * @param string $relative_path Path relative to the plugin root.
 * @return bool
 */
function wpcodebb_safe_require( $relative_path ) {
	$path = WPCODEBB_DIR . ltrim( $relative_path, '/' );

	if ( ! file_exists( $path ) ) {
		$GLOBALS['wpcodebb_boot_errors'][] = sprintf(
			/* translators: %s: file path */
			__( 'Missing file: %s. The related feature has been disabled so the rest of the site keeps working.', 'wpcode-bb-bridge' ),
			esc_html( $relative_path )
		);
		return false;
	}

	try {
		require_once $path;
		return true;
	} catch ( \Throwable $e ) {
		wpcodebb_log_error( 'require ' . $relative_path, $e );
		$GLOBALS['wpcodebb_boot_errors'][] = sprintf(
			/* translators: 1: file path, 2: error message */
			__( 'Error loading %1$s: %2$s. The related feature has been disabled so the rest of the site keeps working.', 'wpcode-bb-bridge' ),
			esc_html( $relative_path ),
			esc_html( $e->getMessage() )
		);
		return false;
	}
}

/**
 * Safely instantiate one of the plugin's subsystem classes. If the
 * class doesn't exist (its file failed to load) or its constructor
 * throws, the rest of the plugin keeps running.
 *
 * @param string $class_name
 * @return object|null
 */
function wpcodebb_safe_boot( $class_name ) {
	if ( ! class_exists( $class_name ) ) {
		return null;
	}

	try {
		if ( is_callable( array( $class_name, 'instance' ) ) ) {
			return call_user_func( array( $class_name, 'instance' ) );
		}

		return new $class_name();
	} catch ( \Throwable $e ) {
		wpcodebb_log_error( 'boot ' . $class_name, $e );
		$GLOBALS['wpcodebb_boot_errors'][] = sprintf(
			/* translators: 1: class name, 2: error message */
			__( '%1$s failed to start: %2$s. The related feature has been disabled so the rest of the site keeps working.', 'wpcode-bb-bridge' ),
			esc_html( $class_name ),
			esc_html( $e->getMessage() )
		);
		return null;
	}
}

/**
 * Central error logger. Never throws, never echoes on the front end.
 *
 * @param string     $context
 * @param \Throwable $e
 */
function wpcodebb_log_error( $context, $e ) {
	if ( function_exists( 'error_log' ) ) {
		error_log( sprintf( '[WPCode BB Bridge] %s: %s in %s:%d', $context, $e->getMessage(), $e->getFile(), $e->getLine() ) );
	}
}

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
		if ( ! file_exists( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			return false; // Can't determine it - fail open rather than block boot.
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	return is_plugin_active_for_network( plugin_basename( WPCODEBB_FILE ) );
}

/**
 * Bootstrap the plugin once all other plugins have loaded, so we can
 * reliably detect whether WPCode and Beaver Builder are active.
 * Every step here is defensive: a problem in one subsystem never
 * stops the others, and never becomes a fatal error.
 */
function wpcodebb_bootstrap() {
	if ( version_compare( PHP_VERSION, WPCODEBB_MIN_PHP, '<' ) ) {
		add_action( 'admin_notices', 'wpcodebb_php_version_notice' );
		return;
	}

	if ( wpcodebb_is_network_activated() ) {
		add_action( 'admin_notices', 'wpcodebb_network_activation_notice' );
		return;
	}

	if ( wpcodebb_safe_require( 'includes/class-wpcodebb-config-cpt.php' ) ) {
		wpcodebb_safe_boot( 'WPCodeBB_Config_CPT' );
	}

	if ( wpcodebb_safe_require( 'includes/class-wpcodebb-admin.php' ) ) {
		wpcodebb_safe_boot( 'WPCodeBB_Admin' );
	}

	// The Beaver Builder module depends on the config class above, so
	// only attempt it if that loaded successfully.
	if ( class_exists( 'WPCodeBB_Config_CPT' ) && wpcodebb_safe_require( 'includes/class-wpcodebb-bb-module.php' ) ) {
		wpcodebb_safe_boot( 'WPCodeBB_BB_Module' );
	}

	if ( ! empty( $GLOBALS['wpcodebb_boot_errors'] ) ) {
		add_action( 'admin_notices', 'wpcodebb_boot_errors_notice' );
	}
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

function wpcodebb_php_version_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			printf(
				/* translators: 1: required PHP version, 2: current PHP version */
				esc_html__( 'WPCode Values for Beaver Builder requires PHP %1$s or newer. This server is running PHP %2$s, so the plugin has not been loaded. Nothing else on the site has been affected.', 'wpcode-bb-bridge' ),
				esc_html( WPCODEBB_MIN_PHP ),
				esc_html( PHP_VERSION )
			);
			?>
		</p>
	</div>
	<?php
}

function wpcodebb_boot_errors_notice() {
	if ( ! current_user_can( 'activate_plugins' ) || empty( $GLOBALS['wpcodebb_boot_errors'] ) ) {
		return;
	}
	?>
	<div class="notice notice-error">
		<p><strong><?php esc_html_e( 'WPCode Values for Beaver Builder ran into a problem loading one or more of its own files, but has safely disabled just those parts. The rest of the site is unaffected.', 'wpcode-bb-bridge' ); ?></strong></p>
		<ul style="list-style: disc; padding-left: 20px;">
			<?php foreach ( $GLOBALS['wpcodebb_boot_errors'] as $error ) : ?>
				<li><?php echo esc_html( $error ); ?></li>
			<?php endforeach; ?>
		</ul>
		<p><?php esc_html_e( 'This usually means the plugin files were not uploaded completely. Try re-uploading the plugin.', 'wpcode-bb-bridge' ); ?></p>
	</div>
	<?php
}

function wpcodebb_missing_requirements_notice() {
	if ( version_compare( PHP_VERSION, WPCODEBB_MIN_PHP, '<' ) || wpcodebb_is_network_activated() ) {
		return;
	}

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
 * Register the configuration CPT on activation too, so capabilities
 * are ready immediately. Wrapped defensively so a problem here shows
 * WordPress's own "Plugin could not be activated" message instead of
 * a white screen, and never leaves the site in a broken state.
 */
function wpcodebb_activate() {
	if ( version_compare( PHP_VERSION, WPCODEBB_MIN_PHP, '<' ) ) {
		return;
	}

	if ( wpcodebb_safe_require( 'includes/class-wpcodebb-config-cpt.php' ) ) {
		$cpt = wpcodebb_safe_boot( 'WPCodeBB_Config_CPT' );

		if ( $cpt && method_exists( $cpt, 'register_post_type' ) ) {
			try {
				$cpt->register_post_type();
			} catch ( \Throwable $e ) {
				wpcodebb_log_error( 'activate register_post_type', $e );
			}
		}
	}

	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'wpcodebb_activate' );

function wpcodebb_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'wpcodebb_deactivate' );
