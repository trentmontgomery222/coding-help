<?php
/**
 * Exercises the failsafe helpers and the address matcher against WP stubs.
 */

define( 'ABSPATH', '/tmp/wp/' );
define( 'ACPS_ALERTS_DIR', dirname( __DIR__ ) . '/acps-alert-popups/' );
define( 'WP_DEBUG', false );

$GLOBALS['transients'] = array();
$GLOBALS['options']    = array();

function get_transient( $k ) { return isset( $GLOBALS['transients'][ $k ] ) ? $GLOBALS['transients'][ $k ] : false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); return true; }
function get_option( $k, $d = false ) { return isset( $GLOBALS['options'][ $k ] ) ? $GLOBALS['options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['options'][ $k ] ); return true; }
function add_action() {} function add_filter() {}
function current_user_can( $c ) { return true; }
function esc_html( $s ) { return htmlspecialchars( (string) $s ); }
function esc_html__( $s, $d = '' ) { return $s; }
function __( $s, $d = '' ) { return $s; }
function wp_convert_hr_to_bytes( $v ) {
	$v = trim( (string) $v ); $last = strtolower( substr( $v, -1 ) ); $n = (int) $v;
	if ( 'g' === $last ) { $n *= 1024 * 1024 * 1024; } elseif ( 'm' === $last ) { $n *= 1024 * 1024; } elseif ( 'k' === $last ) { $n *= 1024; }
	return $n;
}

require ACPS_ALERTS_DIR . 'includes/class-acps-alerts-failsafe.php';

$fails = 0;
function check( $label, $actual, $expected ) {
	global $fails;
	$ok = ( $actual === $expected );
	if ( ! $ok ) {
		$fails++;
		printf( "FAIL %s: expected %s, got %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
	}
}

/* ---- guard() ---- */
check( 'guard returns value', ACPS_Alerts_Failsafe::guard( function () { return 'ok'; }, array(), 'test/plain' ), 'ok' );
check(
	'guard catches Exception',
	ACPS_Alerts_Failsafe::guard( function () { throw new Exception( 'boom' ); }, array(), 'test/ex', 'fallback' ),
	'fallback'
);
check(
	'guard catches Error (TypeError on bad call)',
	ACPS_Alerts_Failsafe::guard( function () { null_fn_that_does_not_exist(); }, array(), 'test/err', 'fallback' ),
	'fallback'
);
check(
	'guard handles non-callable',
	ACPS_Alerts_Failsafe::guard( 'definitely_not_a_function', array(), 'test/nc', 'fallback' ),
	'fallback'
);
check( 'guard passes args', ACPS_Alerts_Failsafe::guard( function ( $a, $b ) { return $a + $b; }, array( 2, 3 ), 'test/args' ), 5 );

/* ---- wrap() ---- */
$filter = ACPS_Alerts_Failsafe::wrap( function ( $links ) { throw new Exception( 'nope' ); }, 'test/filter', 0 );
check( 'wrapped filter returns first arg on failure', $filter( array( 'a', 'b' ) ), array( 'a', 'b' ) );

$action = ACPS_Alerts_Failsafe::wrap( function () { throw new Exception( 'nope' ); }, 'test/action' );
check( 'wrapped action swallows and returns null', $action(), null );

$good = ACPS_Alerts_Failsafe::wrap( function ( $x ) { return strtoupper( $x ); }, 'test/good', 0 );
check( 'wrapped filter passes through on success', $good( 'hi' ), 'HI' );

/* ---- capture() ---- */
check(
	'capture returns output',
	ACPS_Alerts_Failsafe::capture( function () { echo 'hello'; }, array(), 'test/cap' ),
	'hello'
);
check(
	'capture discards partial output when it throws',
	ACPS_Alerts_Failsafe::capture(
		function () { echo '<div>partial'; throw new Exception( 'mid-render' ); },
		array(),
		'test/cap-throw'
	),
	''
);
$before = ob_get_level();
ACPS_Alerts_Failsafe::capture( function () { ob_start(); echo 'leaked buffer'; throw new Exception( 'x' ); }, array(), 'test/cap-leak' );
check( 'capture unwinds buffers the callable leaked', ob_get_level(), $before );

/* ---- circuit breaker ---- */
$GLOBALS['transients'] = array();
check( 'breaker starts closed', ACPS_Alerts_Failsafe::breaker_tripped( 'test/brk' ), false );
for ( $i = 0; $i < ACPS_Alerts_Failsafe::BREAKER_LIMIT; $i++ ) {
	ACPS_Alerts_Failsafe::guard( function () { throw new Exception( 'repeat' ); }, array(), 'test/brk', null );
}
check( 'breaker trips at the limit', ACPS_Alerts_Failsafe::breaker_tripped( 'test/brk' ), true );

$ran = false;
ACPS_Alerts_Failsafe::guard( function () use ( &$ran ) { $ran = true; return 'x'; }, array(), 'test/brk', 'short' );
check( 'tripped breaker short-circuits without running the callable', $ran, false );

/* ---- problem log ---- */
$problems = ACPS_Alerts_Failsafe::problems( 50 );
check( 'problems recorded', count( $problems ) > 0, true );
check( 'problems newest first', $problems[0]['context'], 'test/brk' );
ACPS_Alerts_Failsafe::clear_problems();
check( 'problems cleared', ACPS_Alerts_Failsafe::problems(), array() );

/* ---- memory guard ---- */
check( 'memory_ok with tiny requirement', ACPS_Alerts_Failsafe::memory_ok( 1 ), true );
check( 'memory_ok refuses an absurd requirement', ACPS_Alerts_Failsafe::memory_ok( PHP_INT_MAX ), ACPS_Alerts_Failsafe::memory_limit_bytes() <= 0 );

/* ---- file integrity ---- */
check( 'no required files missing', ACPS_Alerts_Failsafe::missing_files(), array() );
check( 'no optional files missing', ACPS_Alerts_Failsafe::missing_optional_files(), array() );
check( 'has_file finds a real file', ACPS_Alerts_Failsafe::has_file( 'includes/class-acps-alerts-plugin.php' ), true );
check( 'has_file rejects a missing file', ACPS_Alerts_Failsafe::has_file( 'includes/nope.php' ), false );

echo $fails ? "\n$fails failing case(s)\n" : "All failsafe cases passed\n";
exit( $fails ? 1 : 0 );
