<?php
/**
 * The last-resort recovery URL: ?acps_alerts_resume=<key> lifts safe mode using
 * nothing but the main plugin file — no other files loaded, no login — so it
 * works even when the console cannot come up (a parse error in one of the files
 * the console itself would load).
 *
 * The recovery handler ends the request with exit(), so each case runs in its
 * own child process (like boot-test.php); the parent drives them and checks the
 * output and exit code.
 */

$plugin = dirname( __DIR__ ) . '/acps-alert-popups/acps-alert-popups.php';
$scenario = isset( $argv[1] ) ? $argv[1] : '';

/* ------------------------------------------------------------------ *
 * Parent: run each scenario in a child and check what it did.
 * ------------------------------------------------------------------ */
if ( '' === $scenario ) {
	$cases = array(
		// scenario        => [ expect option cleared?, expect "cleared" body? ]
		'match'            => array( true, true ),
		'fallback-secret'  => array( true, true ),
		'wrong-key'        => array( false, false ),
		'no-key-set'       => array( false, false ),
		'no-param'         => array( false, false ),
		'not-in-safe-mode' => array( true, true ), // idempotent: still responds, nothing to clear
		'via-boot'         => array( true, true ), // reached through acps_alerts_boot(), proving it is wired
		'reinstall-match'  => array( true, true ), // reinstall handler runs, then lifts the pause
		'reinstall-wrong'  => array( false, false ),
		'reinstall-via-boot' => array( true, true ), // reached through acps_alerts_boot(), proving it is wired
	);

	$fails = 0;

	foreach ( $cases as $name => $expect ) {
		list( $want_cleared, $want_body ) = $expect;

		$out  = array();
		$code = 0;
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $name ) . ' 2>&1', $out, $code );
		$text = implode( "\n", $out );

		$cleared = false !== strpos( $text, 'RESULT:CLEARED' );
		$body    = false !== stripos( $text, 'safe mode cleared' );

		if ( $cleared !== $want_cleared ) {
			$fails++;
			printf( "FAIL %s: expected option cleared=%s, got %s\n", $name, var_export( $want_cleared, true ), var_export( $cleared, true ) );
		}

		if ( $body !== $want_body ) {
			$fails++;
			printf( "FAIL %s: expected recovery body=%s, got %s\n", $name, var_export( $want_body, true ), var_export( $body, true ) );
		}

		// The reinstall URL must actually run the reinstall (which, with no
		// source configured in the test, reports it cannot reach the source)
		// before it lifts the pause.
		if ( 0 === strpos( $name, 'reinstall' ) && $want_body && false === strpos( $text, 'update source' ) ) {
			$fails++;
			printf( "FAIL %s: the reinstall handler did not run reinstall_now()\n", $name );
		}

		// A non-match must never end the request itself (it falls through so the
		// page loads as normal); a match must.
		$exited = false !== strpos( $text, 'REACHED-END' ) ? false : true;

		if ( $want_body && ! $exited ) {
			$fails++;
			printf( "FAIL %s: a matching recovery URL should end the request\n", $name );
		}

		if ( ! $want_body && $exited ) {
			$fails++;
			printf( "FAIL %s: a non-matching recovery URL must NOT end the request\n", $name );
		}
	}

	echo $fails ? "\n$fails failing case(s)\n" : "All resume cases passed\n";
	exit( $fails ? 1 : 0 );
}

/* ------------------------------------------------------------------ *
 * Child: set up one scenario, then run the recovery handler.
 * ------------------------------------------------------------------ */
error_reporting( E_ALL & ~E_DEPRECATED );

define( 'ABSPATH', '/tmp/wp-resume/' );
define( 'WP_DEBUG', false );

$GLOBALS['options'] = array();

// The safe-mode pause is present in every case but 'not-in-safe-mode'.
if ( 'not-in-safe-mode' !== $scenario ) {
	$GLOBALS['options']['acps_alerts_safe_mode'] = array( 'msg' => 'boom', 'time' => time() );
}

// The stored key the URL is checked against.
switch ( $scenario ) {
	case 'fallback-secret':
		// No console key: the update secret is used instead.
		$GLOBALS['options']['acps_alerts_settings'] = array( 'console_key' => '', 'update_secret' => 'sekret-secret-value' );
		break;
	case 'no-key-set':
		$GLOBALS['options']['acps_alerts_settings'] = array( 'console_key' => '', 'update_secret' => '' );
		break;
	default:
		$GLOBALS['options']['acps_alerts_settings'] = array( 'console_key' => 'right-key-123', 'update_secret' => 'other' );
}

// The key presented in the URL.
switch ( $scenario ) {
	case 'fallback-secret':
		$_GET['acps_alerts_resume'] = 'sekret-secret-value';
		break;
	case 'wrong-key':
		$_GET['acps_alerts_resume'] = 'not-the-key';
		break;
	case 'no-param':
		// No query var at all.
		break;
	case 'no-key-set':
		$_GET['acps_alerts_resume'] = 'anything';
		break;
	case 'reinstall-match':
	case 'reinstall-via-boot':
		$_GET['acps_alerts_reinstall'] = 'right-key-123';
		break;
	case 'reinstall-wrong':
		$_GET['acps_alerts_reinstall'] = 'not-the-key';
		break;
	default:
		$_GET['acps_alerts_resume'] = 'right-key-123';
}

// ---- just enough WordPress for the main file to load and the handler to run.
function get_option( $k, $d = false ) { return isset( $GLOBALS['options'][ $k ] ) ? $GLOBALS['options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['options'][ $k ] ); return true; }
function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }
function nocache_headers() {}
function status_header( $c ) {}
$GLOBALS['acts'] = array();
function add_action( $h = '', $cb = null, $p = 10, $a = 1 ) { $GLOBALS['acts'][ $h ][] = $cb; }
function add_filter() {}
function remove_filter() {}
// Enough for the updater to load and reach "no source configured" without HTTP.
function wp_parse_args( $a, $d ) { return array_merge( (array) $d, (array) $a ); }
$GLOBALS['transients'] = array();
function get_transient( $k ) { return isset( $GLOBALS['transients'][ $k ] ) ? $GLOBALS['transients'][ $k ] : false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); return true; }
function delete_site_transient( $k ) { return true; }
function trailingslashit( $s ) { return rtrim( (string) $s, '/' ) . '/'; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function esc_url_raw( $s ) { return (string) $s; }
function apply_filters( $t, $v ) { return $v; }
function register_activation_hook() {}
function register_deactivation_hook() {}
function plugin_basename( $f ) { return basename( $f ); }
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function plugin_dir_url( $f ) { return 'https://example.org/wp-content/plugins/acps-alert-popups/'; }

// Print the outcome no matter how the request ends — the handler exits on a
// match, so this shutdown line is how the parent sees what happened.
register_shutdown_function( function () {
	$paused = isset( $GLOBALS['options']['acps_alerts_safe_mode'] );
	echo "\nRESULT:" . ( $paused ? 'PAUSED' : 'CLEARED' ) . "\n";
} );

require $plugin;

if ( 'via-boot' === $scenario || 'reinstall-via-boot' === $scenario ) {
	// Prove the handler is actually wired into boot: fire plugins_loaded the
	// way WordPress would, and let acps_alerts_boot() reach it on its own.
	foreach ( ( isset( $GLOBALS['acts']['plugins_loaded'] ) ? $GLOBALS['acts']['plugins_loaded'] : array() ) as $cb ) {
		if ( is_callable( $cb ) ) {
			call_user_func( $cb );
		}
	}
} elseif ( 0 === strpos( $scenario, 'reinstall' ) ) {
	// The reinstall handler: with no source configured it reports it cannot
	// reach the source (before touching the WordPress upgrader), then lifts the
	// pause — so the whole path is exercised without a real download.
	acps_alerts_maybe_reinstall_via_url();
} else {
	// The handler runs before every other boot step; call it directly here.
	acps_alerts_maybe_resume_via_url();
}

// Only reached when the handler did NOT end the request (a non-matching URL).
echo "REACHED-END\n";
