<?php
/**
 * Pins the "everything is twice everywhere" bug.
 *
 * WordPress normally protects you from registering the same callback twice:
 * add_action( $hook, array( $obj, 'method' ) ) builds a stable id from the
 * object and method name, so a second identical call is a no-op.
 *
 * Wrapping every hook in a closure removed that protection, because each
 * closure is a new object with its own hash. Anything that ran the wiring
 * twice then duplicated every menu, notice and fragment the plugin printed.
 *
 * These checks assert the guarantee is back.
 */

define( 'ABSPATH', '/tmp/wp/' );
define( 'ACPS_ALERTS_DIR', dirname( __DIR__ ) . '/acps-alert-popups/' );
define( 'WP_DEBUG', false );

$GLOBALS['hooks'] = array();

/**
 * A stand-in for WordPress' hook store, including its real de-duplication:
 * callbacks are keyed by a unique id, which is stable for [$obj,'method'] and
 * per-object for a closure.
 *
 * @param string $hook Hook name.
 * @param mixed  $cb   Callback.
 * @return void
 */
function add_action( $hook, $cb = null, $priority = 10, $args = 1 ) {
	$id = is_object( $cb ) ? spl_object_hash( $cb )
		: ( is_array( $cb ) ? ( is_object( $cb[0] ) ? spl_object_hash( $cb[0] ) : $cb[0] ) . '::' . $cb[1] : (string) $cb );

	$GLOBALS['hooks'][ $hook ][ $priority ][ $id ] = $cb;
}
function add_filter( $hook, $cb = null, $priority = 10, $args = 1 ) { add_action( $hook, $cb, $priority, $args ); }

/**
 * Runs every callback on a hook and returns how many ran.
 *
 * @param string $hook Hook name.
 * @return int
 */
function fire( $hook ) {
	$ran = 0;

	foreach ( ( isset( $GLOBALS['hooks'][ $hook ] ) ? $GLOBALS['hooks'][ $hook ] : array() ) as $callbacks ) {
		foreach ( $callbacks as $cb ) {
			call_user_func( $cb );
			$ran++;
		}
	}

	return $ran;
}

function get_option( $k, $d = false ) { return $d; }
function update_option( $k, $v, $a = null ) { return true; }
function delete_option( $k ) { return true; }
function get_transient( $k ) { return false; }
function set_transient( $k, $v, $t = 0 ) { return true; }
function delete_transient( $k ) { return true; }
function current_user_can( $c ) { return true; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = '' ) { return $s; }
function __( $s, $d = '' ) { return $s; }
function wp_convert_hr_to_bytes( $v ) { return (int) $v * 1024 * 1024; }

require ACPS_ALERTS_DIR . 'includes/class-acps-alerts-failsafe.php';

$fails = 0;
function check( $label, $actual, $expected ) {
	global $fails;
	if ( $actual !== $expected ) {
		$fails++;
		printf( "FAIL %s: expected %s, got %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
	}
}

/* ---- the regression: wiring the same hook twice ---- */
$calls = 0;
$menu  = function () use ( &$calls ) { $calls++; };

ACPS_Alerts_Failsafe::action( 'admin_menu', $menu, 'admin/menu' );
ACPS_Alerts_Failsafe::action( 'admin_menu', $menu, 'admin/menu' );

check( 'wiring the same hook twice registers it once', fire( 'admin_menu' ), 1 );
check( 'and the callback runs once', $calls, 1 );

/* ---- a filter is protected the same way ---- */
$GLOBALS['hooks'] = array();

ACPS_Alerts_Failsafe::filter( 'plugin_action_links', $menu, 'admin/links' );
ACPS_Alerts_Failsafe::filter( 'plugin_action_links', $menu, 'admin/links' );

check( 'a filter registers once', fire( 'plugin_action_links' ), 1 );

/* ---- genuinely different callbacks still both register ---- */
$GLOBALS['hooks'] = array();

ACPS_Alerts_Failsafe::action( 'admin_notices', $menu, 'admin/notice-a' );
ACPS_Alerts_Failsafe::action( 'admin_notices', $menu, 'admin/notice-b' );

check( 'two different callbacks on one hook both register', fire( 'admin_notices' ), 2 );

/* ---- the same context on a different hook is not swallowed ---- */
$GLOBALS['hooks'] = array();

ACPS_Alerts_Failsafe::action( 'wp_footer', $menu, 'frontend/render' );
ACPS_Alerts_Failsafe::action( 'wp_head', $menu, 'frontend/render' );

check( 'the same context on another hook still registers', fire( 'wp_footer' ) + fire( 'wp_head' ), 2 );

/* ---- the same callback at a different priority is not swallowed ---- */
$GLOBALS['hooks'] = array();

ACPS_Alerts_Failsafe::action( 'init', $menu, 'cpt/register', 5 );
ACPS_Alerts_Failsafe::action( 'init', $menu, 'cpt/register', 20 );

check( 'the same context at another priority still registers', fire( 'init' ), 2 );

/* ---- the wrapper still works after de-duplication ---- */
$GLOBALS['hooks'] = array();
$threw = false;

ACPS_Alerts_Failsafe::action(
	'wp_footer',
	function () use ( &$threw ) { $threw = true; throw new Exception( 'boom' ); },
	'frontend/throws'
);

fire( 'wp_footer' );

check( 'a de-duplicated wrapper still catches a throw', $threw, true );

/* ---- an admin screen registered twice is still drawn once ---- */
//
// add_menu_page() and add_submenu_page() are deliberately called with the same
// slug (the usual way to rename the first submenu item) and both resolve to one
// hook. WordPress de-duplicates by callback identity, so the renderer handed to
// each call has to be the SAME object or the screen draws twice.

define( 'ACPS_ALERTS_FILE', dirname( __DIR__ ) . '/acps-alert-popups/acps-alert-popups.php' );

function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return $s; }
function _n( $a, $b, $n, $d = '' ) { return 1 === $n ? $a : $b; }
function apply_filters( $t, $v ) { return $v; }
function plugin_basename( $f ) { return 'acps-alert-popups/acps-alert-popups.php'; }
function admin_url( $p = '' ) { return 'https://example.org/wp-admin/' . $p; }
function add_menu_page( $t, $m, $c, $slug, $cb ) { add_action( 'toplevel_page_' . $slug, $cb ); }
function add_submenu_page( $parent, $t, $m, $c, $slug, $cb ) {
	// Mirrors get_plugin_page_hookname(): a submenu whose slug matches its
	// parent lands on the parent's own hook.
	add_action( $slug === $parent ? 'toplevel_page_' . $slug : $parent . '_page_' . $slug, $cb );
}

require ACPS_ALERTS_DIR . 'includes/class-acps-alerts-admin.php';

/**
 * Exposes the protected renderer factory and counts draws.
 */
class AdminProbe extends ACPS_Alerts_Admin {
	public static $drawn = 0;

	public function renderer( $method ) { return $this->safe_render( $method ); }
	public function render_probe() { self::$drawn++; echo '<div class="wrap">screen</div>'; }
}

$admin = new AdminProbe();

$a = $admin->renderer( 'render_probe' );
$b = $admin->renderer( 'render_probe' );

check( 'the same screen yields the same renderer object', $a === $b, true );
check( 'a different screen yields a different one', $a === $admin->renderer( 'render_settings' ), false );

// Register it the way register_menu() does: top-level plus a same-slug submenu.
$GLOBALS['hooks'] = array();
add_menu_page( 'Site Alerts', 'Site Alerts', 'edit_pages', 'acps-alerts', $admin->renderer( 'render_probe' ) );
add_submenu_page( 'acps-alerts', 'All Alerts', 'All Alerts', 'edit_pages', 'acps-alerts', $admin->renderer( 'render_probe' ) );

check( 'both registrations land on one hook', count( $GLOBALS['hooks']['toplevel_page_acps-alerts'][10] ), 1 );

ob_start();
fire( 'toplevel_page_acps-alerts' );
$screen = ob_get_clean();

check( 'the screen is drawn once', AdminProbe::$drawn, 1 );
check( 'and its markup appears once', substr_count( $screen, 'class="wrap"' ), 1 );

/* ---- the main plugin file refuses to define itself twice ---- */
$main = file_get_contents( ACPS_ALERTS_DIR . 'acps-alert-popups.php' );

check(
	'the plugin file bails out if it is loaded a second time',
	(bool) preg_match( '/if \(\s*defined\(\s*\'ACPS_ALERTS_VERSION\'\s*\)\s*\)\s*\{/', $main ),
	true
);

check(
	'boot() runs only once per request',
	(bool) preg_match( '/function acps_alerts_boot\(\)\s*\{\s*(\/\/[^\n]*\n\s*)*static \$booted/', $main ),
	true
);

$plugin = file_get_contents( ACPS_ALERTS_DIR . 'includes/class-acps-alerts-plugin.php' );

check(
	'the container wires its hooks only once',
	(bool) strpos( $plugin, '$this->wired' ),
	true
);

echo $fails ? "\n$fails failing case(s)\n" : "All idempotency cases passed\n";
exit( $fails ? 1 : 0 );
