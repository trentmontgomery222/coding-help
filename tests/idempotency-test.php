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
