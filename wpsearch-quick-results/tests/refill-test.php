<?php
/**
 * Refilling the cache after it is emptied.
 *
 * The scheduling is a debounce with a cap, which is easy to get subtly wrong
 * in both directions: too eager and an import re-warms after every save; too
 * patient and the cache stays cold for the whole import. Cron is shimmed so
 * the decisions can be checked without WordPress.
 *
 *   php wpsearch-quick-results/tests/refill-test.php
 */

define( 'ABSPATH', __DIR__ );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['cron']     = array();
$GLOBALS['options']  = array();
$GLOBALS['settings'] = array( 'warm_enabled' => 1, 'warm_on_flush' => 1, 'warm_count' => 25, 'per_page' => 20 );

function wp_next_scheduled( $hook ) {
	return isset( $GLOBALS['cron'][ $hook ] ) ? $GLOBALS['cron'][ $hook ] : false;
}
function wp_schedule_single_event( $when, $hook ) { $GLOBALS['cron'][ $hook ] = $when; }
function wp_schedule_event( $when, $recurrence, $hook ) { $GLOBALS['cron'][ $hook ] = $when; }
function wp_unschedule_event( $when, $hook ) { unset( $GLOBALS['cron'][ $hook ] ); }
function wp_clear_scheduled_hook( $hook ) { unset( $GLOBALS['cron'][ $hook ] ); }
function get_option( $name, $default = false ) { return $GLOBALS['options'][ $name ] ?? $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['options'][ $name ] = $value; return true; }
function delete_option( $name ) { unset( $GLOBALS['options'][ $name ] ); return true; }
function current_time( $type ) { return gmdate( 'Y-m-d H:i:s' ); }
function add_action() {}
function add_filter() {}
function __( $s, $d = null ) { return $s; }

class WPSQR_Plugin { public static function settings() { return $GLOBALS['settings']; } }
class WPSQR_Stats { public static function popular( $n, $d ) { return array(); } }
class WPSQR_Cache { public static function get( $k ) { return null; } public static function sweep() {} }
class WPSQR_Engine { public static function search( $t, $a = array() ) { return array(); } }
class WPSQR_Normalizer { public static function key( $t, $a = array() ) { return md5( $t ); } }

require_once __DIR__ . '/../includes/class-wpsqr-warmer.php';

$pass = 0;
$fail = 0;

function check( $name, $actual, $expected ) {
	global $pass, $fail;

	if ( $actual === $expected ) {
		$pass++;
		echo "  ok   {$name}\n";
	} else {
		$fail++;
		echo "  FAIL {$name}\n       expected " . var_export( $expected, true ) . "\n       actual   " . var_export( $actual, true ) . "\n";
	}
}

function reset_state() {
	$GLOBALS['cron']    = array();
	$GLOBALS['options'] = array();
	$GLOBALS['settings'] = array( 'warm_enabled' => 1, 'warm_on_flush' => 1, 'warm_count' => 25, 'per_page' => 20 );
}

function queued() { return wp_next_scheduled( WPSQR_Warmer::REFILL ); }

echo "\nwhen a refill is queued at all\n";
reset_state();
WPSQR_Warmer::schedule_refill();
check( 'emptying the cache queues one', queued() !== false, true );
check( 'and it waits, rather than running immediately',
	queued() - time() >= WPSQR_Warmer::REFILL_DELAY - 2, true );

reset_state();
$GLOBALS['settings']['warm_on_flush'] = 0;
WPSQR_Warmer::schedule_refill();
check( 'turned off, nothing is queued', queued(), false );

reset_state();
$GLOBALS['settings']['warm_enabled'] = 0;
WPSQR_Warmer::schedule_refill();
check( 'warming off entirely, nothing is queued', queued(), false );

echo "\ndebouncing a burst of saves\n";
reset_state();
WPSQR_Warmer::schedule_refill();
$first = queued();

// A second save a moment later should move the run, not add another.
$GLOBALS['cron'][ WPSQR_Warmer::REFILL ] = $first - 30; // pretend time passed
WPSQR_Warmer::schedule_refill();
check( 'a second flush pushes the run back rather than queueing a second',
	count( $GLOBALS['cron'] ), 1 );
check( 'and the new time is later than the one it replaced',
	queued() > $first - 30, true );

echo "\nbut never defers past the cap\n";
reset_state();
WPSQR_Warmer::schedule_refill();
$pending = queued();

// Simulate a long import: the debounce window opened well over the cap ago.
$GLOBALS['options']['wpsqr_refill_since'] = time() - ( WPSQR_Warmer::REFILL_MAX_DEFER + 60 );
WPSQR_Warmer::schedule_refill();
check( 'once the cap is passed, the queued run is left alone', queued(), $pending );

// Just inside the cap, it should still be moving.
reset_state();
WPSQR_Warmer::schedule_refill();
$pending = queued();
$GLOBALS['options']['wpsqr_refill_since'] = time() - 10;
$GLOBALS['cron'][ WPSQR_Warmer::REFILL ]  = $pending - 30;
WPSQR_Warmer::schedule_refill();
check( 'inside the cap, it still moves', queued() !== $pending - 30, true );

echo "\nafter the refill runs\n";
reset_state();
WPSQR_Warmer::schedule_refill();
check( 'the debounce window is recorded', isset( $GLOBALS['options']['wpsqr_refill_since'] ), true );
WPSQR_Warmer::run_refill();
check( 'and cleared once it has run', isset( $GLOBALS['options']['wpsqr_refill_since'] ), false );
check( 'the run records what triggered it',
	$GLOBALS['options']['wpsqr_last_warm']['trigger'], 'refill' );

reset_state();
WPSQR_Warmer::run( null, 'manual' );
check( 'a manual run is recorded as such',
	$GLOBALS['options']['wpsqr_last_warm']['trigger'], 'manual' );

reset_state();
WPSQR_Warmer::run();
check( 'a scheduled run defaults to cron',
	$GLOBALS['options']['wpsqr_last_warm']['trigger'], 'cron' );

echo "\n{$pass} passed, {$fail} failed\n\n";
exit( $fail ? 1 : 0 );
