<?php
/**
 * The crash guard's decisions, without WordPress.
 *
 * The point of this class is that a missing or broken file does not take the
 * site down, so the thing worth testing is exactly that: which files it
 * reports missing, and that it never throws while finding out.
 *
 *   php wpsearch-quick-results/tests/guard-test.php
 */

define( 'ABSPATH', __DIR__ );
define( 'WPSQR_PATH', sys_get_temp_dir() . '/wpsqr-guard-test-' . getmypid() . '/' );

$GLOBALS['options'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['options'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['options'][ $k ] ); return true; }
function wp_normalize_path( $p ) { return str_replace( '\\', '/', $p ); }
function untrailingslashit( $p ) { return rtrim( $p, '/\\' ); }
function trailingslashit( $p ) { return untrailingslashit( $p ) . '/'; }
function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0777, true ); }
define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/wpsqr-content-' . getmypid() );

require_once __DIR__ . '/../includes/class-wpsqr-guard.php';

$pass = 0; $fail = 0;
function check( $name, $actual, $expected ) {
	global $pass, $fail;
	if ( $actual === $expected ) { $pass++; echo "  ok   {$name}\n"; }
	else { $fail++; echo "  FAIL {$name}\n       expected " . var_export( $expected, true ) . "\n       actual   " . var_export( $actual, true ) . "\n"; }
}

// Build a fake plugin dir with every required file present, then remove some.
$dir = rtrim( WPSQR_PATH, '/' );
@mkdir( $dir . '/includes', 0777, true );
foreach ( WPSQR_Guard::required_files() as $rel ) {
	@mkdir( dirname( $dir . '/' . $rel ), 0777, true );
	file_put_contents( $dir . '/' . $rel, "<?php // stub\n" );
}

echo "\na complete install reports nothing missing\n";
check( 'no missing files', WPSQR_Guard::load_includes(), array() );
check( 'and no health record is left behind', isset( $GLOBALS['options']['wpsqr_last_health'] ), false );

echo "\na missing file is survived and reported, not fatal\n";
unlink( $dir . '/includes/class-wpsqr-index.php' );
$missing = WPSQR_Guard::load_includes();
check( 'the gap is reported', in_array( 'includes/class-wpsqr-index.php', $missing, true ), true );
check( 'exactly one file is missing', count( $missing ), 1 );
check( 'and a health record is written', isset( $GLOBALS['options']['wpsqr_last_health'] ), true );

echo "\ntwo missing files\n";
unlink( $dir . '/includes/class-wpsqr-remote.php' );
check( 'both are reported', count( WPSQR_Guard::load_includes() ), 2 );

echo "\nthe record clears once the files return\n";
file_put_contents( $dir . '/includes/class-wpsqr-index.php', "<?php // stub\n" );
file_put_contents( $dir . '/includes/class-wpsqr-remote.php', "<?php // stub\n" );
check( 'nothing missing now', WPSQR_Guard::load_includes(), array() );
check( 'the health record is gone', isset( $GLOBALS['options']['wpsqr_last_health'] ), false );

echo "\nsafe mode round-trips\n";
check( 'off by default', WPSQR_Guard::is_safe_mode(), false );
WPSQR_Guard::enter_safe_mode( 'boom' );
check( 'on after a crash', WPSQR_Guard::is_safe_mode(), true );
check( 'the reason is kept', $GLOBALS['options']['wpsqr_safe_mode']['reason'], 'boom' );
WPSQR_Guard::leave_safe_mode();
check( 'off after resume', WPSQR_Guard::is_safe_mode(), false );

echo "\nrollback restores a bad update to the previous version\n";
// Seed a working v1 in the plugin dir.
file_put_contents( $dir . '/marker.txt', 'version-1' );
$backup = WPSQR_Guard::arm_rollback( '1.0.0' );
check( 'a backup was made', is_string( $backup ) && is_dir( $backup ), true );
check( 'the rollback is armed', isset( $GLOBALS['options']['wpsqr_rollback'] ), true );

// Simulate a bad update overwriting the marker and tripping safe mode.
file_put_contents( $dir . '/marker.txt', 'version-2-broken' );
WPSQR_Guard::enter_safe_mode( 'fatal in v2' );

check( 'rollback runs and succeeds', WPSQR_Guard::maybe_rollback(), true );
check( 'the previous version file is back', trim( file_get_contents( $dir . '/marker.txt' ) ), 'version-1' );
check( 'safe mode is cleared', WPSQR_Guard::is_safe_mode(), false );
check( 'the rollback is disarmed', isset( $GLOBALS['options']['wpsqr_rollback'] ), false );
check( 'the backup is cleaned up', is_dir( $backup ), false );
check( 'the revert is recorded', $GLOBALS['options']['wpsqr_last_rollback']['reverted_to'], '1.0.0' );

echo "\nwith no armed rollback, maybe_rollback does nothing\n";
check( 'returns false', WPSQR_Guard::maybe_rollback(), false );

echo "\na clean update disarms the rollback instead\n";
file_put_contents( $dir . '/marker.txt', 'version-1' );
$backup2 = WPSQR_Guard::arm_rollback( '1.0.0' );
WPSQR_Guard::disarm_rollback();
check( 'the backup is removed', is_dir( $backup2 ), false );
check( 'and the option cleared', isset( $GLOBALS['options']['wpsqr_rollback'] ), false );

// Clean up.
@unlink( $dir . '/marker.txt' );
foreach ( glob( WP_CONTENT_DIR . '/wpsqr-rollback/*' ) as $g ) { is_dir( $g ) && array_map( 'unlink', glob( $g . '/*' ) ) && rmdir( $g ); }
array_map( 'unlink', glob( $dir . '/includes/*' ) );
@rmdir( $dir . '/includes' );
@rmdir( $dir );

echo "\n{$pass} passed, {$fail} failed\n\n";
exit( $fail ? 1 : 0 );
