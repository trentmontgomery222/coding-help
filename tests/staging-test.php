<?php
/**
 * The self-contained install plumbing in the main plugin file: the recursive
 * copy/remove helpers, the staged-install apply, and the rollback of a bad
 * update. These run in the earliest bootstrap window (before any include loads)
 * and must depend on nothing but the main file and WordPress core, so this test
 * points the plugin's ACPS_ALERTS_DIR / WP_CONTENT_DIR at throwaway temp dirs
 * (via stubbed plugin_dir_path) and drives the functions directly.
 */

error_reporting( E_ALL & ~E_DEPRECATED );

$fails = 0;

/** Assert helper. */
function s_ok( $cond, $label ) {
	global $fails;

	if ( $cond ) {
		echo "PASS: $label\n";
	} else {
		++$fails;
		echo "FAIL: $label\n";
	}
}

/* ------------------------------------------------------------------ *
 * A private temp sandbox, with the plugin dir and wp-content inside it.
 * ------------------------------------------------------------------ */
$sandbox     = sys_get_temp_dir() . '/acps-staging-' . getmypid();
$plugin_dir  = $sandbox . '/plugins/acps-alert-popups';
$content_dir = $sandbox . '/wp-content';

@mkdir( $plugin_dir, 0777, true );
@mkdir( $content_dir, 0777, true );

define( 'ABSPATH', $sandbox . '/' );
define( 'WP_DEBUG', false );
define( 'WP_CONTENT_DIR', $content_dir );

$GLOBALS['options'] = array();

// ---- minimal WordPress surface the main file and helpers touch.
function get_option( $k, $d = false ) { return isset( $GLOBALS['options'][ $k ] ) ? $GLOBALS['options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['options'][ $k ] ); return true; }
function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }
function add_action() {}
function add_filter() {}
function remove_filter() {}
function nocache_headers() {}
function status_header() {}
function wp_parse_args( $a, $d ) { return array_merge( (array) $d, (array) $a ); }
function register_activation_hook() {}
function register_deactivation_hook() {}
function plugin_basename( $f ) { return 'acps-alert-popups/' . basename( $f ); }
function plugin_dir_url() { return 'https://example.org/wp-content/plugins/acps-alert-popups/'; }
function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0777, true ); }
function untrailingslashit( $s ) { return rtrim( (string) $s, '/\\' ); }
function trailingslashit( $s ) { return rtrim( (string) $s, '/\\' ) . '/'; }
// The plugin is "active" already, so ensure_active_min never touches a missing
// wp-admin include in this harness.
function is_plugin_active() { return true; }

// Force ACPS_ALERTS_DIR to the sandbox plugin dir.
$GLOBALS['acps_plugin_dir'] = $plugin_dir . '/';
function plugin_dir_path() { return $GLOBALS['acps_plugin_dir']; }

require dirname( __DIR__ ) . '/acps-alert-popups/acps-alert-popups.php';

/** Write a file, making parent dirs. */
function s_put( $path, $body ) {
	@mkdir( dirname( $path ), 0777, true );
	file_put_contents( $path, $body );
}

/** Reset the sandbox plugin dir to a known set of files. */
function s_seed_plugin( array $files ) {
	global $plugin_dir;
	acps_alerts_remove_tree( $plugin_dir );
	@mkdir( $plugin_dir, 0777, true );

	foreach ( $files as $rel => $body ) {
		s_put( $plugin_dir . '/' . $rel, $body );
	}
}

/* ================================================================== *
 * 1. copy_tree copies a whole tree, nested files included.
 * ================================================================== */
$src  = $sandbox . '/ct-src';
$dest = $sandbox . '/ct-dest';
s_put( $src . '/a.txt', 'A' );
s_put( $src . '/sub/b.php', '<?php // B' );
s_put( $src . '/sub/deep/c.css', 'C' );

$failed = array();
$ok     = acps_alerts_copy_tree( $src, $dest, $failed );

s_ok( $ok && empty( $failed ), 'copy_tree reports success with no failures' );
s_ok( 'A' === @file_get_contents( $dest . '/a.txt' ), 'copy_tree copied a top-level file' );
s_ok( '<?php // B' === @file_get_contents( $dest . '/sub/b.php' ), 'copy_tree copied a nested .php file' );
s_ok( 'C' === @file_get_contents( $dest . '/sub/deep/c.css' ), 'copy_tree copied a deeply nested file' );

/* ================================================================== *
 * 2. remove_tree deletes everything.
 * ================================================================== */
acps_alerts_remove_tree( $dest );
s_ok( ! is_dir( $dest ), 'remove_tree deleted the whole tree' );

/* ================================================================== *
 * 3. maybe_apply_staged copies staged files over the live plugin,
 *    arms a rollback of the old files, clears safe mode, and returns true.
 * ================================================================== */
s_seed_plugin( array(
	'acps-alert-popups.php' => "OLD-MAIN",
	'includes/thing.php'    => "OLD-INCLUDE",
) );

$stage = $sandbox . '/stage-1';
s_put( $stage . '/acps-alert-popups.php', 'NEW-MAIN' );
s_put( $stage . '/includes/thing.php', 'NEW-INCLUDE' );
s_put( $stage . '/includes/added.php', 'NEW-ADDED' );

$GLOBALS['options'][ ACPS_ALERTS_STAGED_OPT ]    = array( 'dir' => $stage, 'base' => $stage, 'version' => '9.9.9' );
$GLOBALS['options'][ ACPS_ALERTS_SAFE_MODE_OPT ] = array( 'msg' => 'boom', 'time' => time(), 'version' => ACPS_ALERTS_VERSION );

$applied = acps_alerts_maybe_apply_staged();

s_ok( true === $applied, 'maybe_apply_staged returned true (caller should stop)' );
s_ok( 'NEW-MAIN' === @file_get_contents( $plugin_dir . '/acps-alert-popups.php' ), 'staged main file was copied over the live one' );
s_ok( 'NEW-INCLUDE' === @file_get_contents( $plugin_dir . '/includes/thing.php' ), 'staged include overwrote the old one' );
s_ok( 'NEW-ADDED' === @file_get_contents( $plugin_dir . '/includes/added.php' ), 'a brand-new staged file was added' );
s_ok( ! isset( $GLOBALS['options'][ ACPS_ALERTS_STAGED_OPT ] ), 'the staged-install marker was cleared' );
s_ok( ! isset( $GLOBALS['options'][ ACPS_ALERTS_SAFE_MODE_OPT ] ), 'safe mode was lifted by the apply' );
s_ok( ! is_dir( $stage ), 'the staging directory was cleaned up' );

$rollback = get_option( ACPS_ALERTS_ROLLBACK_OPT );
s_ok( is_array( $rollback ) && ! empty( $rollback['dir'] ), 'a rollback was armed before the swap' );
s_ok( is_array( $rollback ) && 'OLD-MAIN' === @file_get_contents( $rollback['dir'] . '/acps-alert-popups.php' ), 'the rollback backup holds the OLD files' );
s_ok( is_array( $rollback ) && '9.9.9' === (string) $rollback['to_version'], 'the rollback records the version it moved to' );

/* ================================================================== *
 * 4. No staged marker -> maybe_apply_staged is a no-op returning false.
 * ================================================================== */
acps_alerts_disarm_rollback();
unset( $GLOBALS['options'][ ACPS_ALERTS_STAGED_OPT ] );
s_ok( false === acps_alerts_maybe_apply_staged(), 'maybe_apply_staged returns false with nothing staged' );

/* ================================================================== *
 * 5. maybe_rollback restores the backup when the applied update crashed
 *    (safe mode armed at/after the backup was taken).
 * ================================================================== */
s_seed_plugin( array( 'acps-alert-popups.php' => 'NEW-BROKEN' ) );

$backup = $sandbox . '/rb-backup';
acps_alerts_remove_tree( $backup );
s_put( $backup . '/acps-alert-popups.php', 'OLD-GOOD' );

$armed_at = time() - 5;
$GLOBALS['options'][ ACPS_ALERTS_ROLLBACK_OPT ]  = array( 'dir' => $backup, 'from_version' => '1.0.0', 'to_version' => '9.9.9', 'time' => $armed_at );
$GLOBALS['options'][ ACPS_ALERTS_SAFE_MODE_OPT ] = array( 'msg' => 'boom again', 'time' => $armed_at + 1, 'version' => '9.9.9' );

$rolled = acps_alerts_maybe_rollback();

s_ok( true === $rolled, 'maybe_rollback returned true after a post-update crash' );
s_ok( 'OLD-GOOD' === @file_get_contents( $plugin_dir . '/acps-alert-popups.php' ), 'the old, good files were restored' );
s_ok( ! isset( $GLOBALS['options'][ ACPS_ALERTS_SAFE_MODE_OPT ] ), 'safe mode was cleared by the rollback' );
s_ok( ! isset( $GLOBALS['options'][ ACPS_ALERTS_ROLLBACK_OPT ] ), 'the rollback marker was consumed' );

/* ================================================================== *
 * 6. maybe_rollback does NOT roll back when there is no crash; instead it
 *    disarms once the new version is the one running.
 * ================================================================== */
s_seed_plugin( array( 'acps-alert-popups.php' => 'CURRENT' ) );

$backup2 = $sandbox . '/rb-backup2';
acps_alerts_remove_tree( $backup2 );
s_put( $backup2 . '/acps-alert-popups.php', 'PREV' );

// to_version matches the version actually running, and no safe mode is set.
$GLOBALS['options'][ ACPS_ALERTS_ROLLBACK_OPT ] = array( 'dir' => $backup2, 'from_version' => '1.0.0', 'to_version' => ACPS_ALERTS_VERSION, 'time' => time() - 10 );

$rolled2 = acps_alerts_maybe_rollback();

s_ok( false === $rolled2, 'maybe_rollback returns false when the new code is healthy' );
s_ok( 'CURRENT' === @file_get_contents( $plugin_dir . '/acps-alert-popups.php' ), 'a healthy install is not disturbed' );
s_ok( ! isset( $GLOBALS['options'][ ACPS_ALERTS_ROLLBACK_OPT ] ), 'the backup is disarmed once the new code runs clean' );
s_ok( ! is_dir( $backup2 ), 'the disarmed backup directory was removed' );

/* ------------------------------------------------------------------ *
 * Tidy up.
 * ------------------------------------------------------------------ */
acps_alerts_remove_tree( $sandbox );

echo $fails ? "\n$fails failing assertion(s)\n" : "\nAll staging cases passed\n";
exit( $fails ? 1 : 0 );
