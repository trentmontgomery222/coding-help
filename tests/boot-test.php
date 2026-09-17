<?php
/**
 * End-to-end check of the crash-containment promise.
 *
 * Boots the real plugin file against WordPress stubs in three scenarios and
 * asserts the request survives each one:
 *
 *   1. healthy      — boots normally
 *   2. missing file — stays dormant, no fatal
 *   3. safe mode    — stays dormant, no fatal
 *   4. kill switch  — never boots at all
 *
 * Each scenario runs in its own PHP process, because a real fatal would end
 * the process; the exit status is the assertion.
 */

$plugin_src = dirname( __DIR__ ) . '/acps-alert-popups';
$scenario   = isset( $argv[1] ) ? $argv[1] : 'healthy';
$work       = sys_get_temp_dir() . '/acps-boot-' . $scenario;

// ---- copy the plugin to a scratch directory -------------------------------
function rcopy( $src, $dst ) {
	@mkdir( $dst, 0777, true );
	foreach ( scandir( $src ) as $item ) {
		if ( '.' === $item || '..' === $item ) { continue; }
		$s = $src . '/' . $item;
		$d = $dst . '/' . $item;
		is_dir( $s ) ? rcopy( $s, $d ) : copy( $s, $d );
	}
}
function rrm( $dir ) {
	if ( ! is_dir( $dir ) ) { return; }
	foreach ( scandir( $dir ) as $item ) {
		if ( '.' === $item || '..' === $item ) { continue; }
		$p = $dir . '/' . $item;
		is_dir( $p ) ? rrm( $p ) : unlink( $p );
	}
	rmdir( $dir );
}

rrm( $work );
rcopy( $plugin_src, $work );

if ( 'missing-file' === $scenario ) {
	// Simulate a half-finished update / corrupt upload.
	unlink( $work . '/includes/class-acps-alerts-frontend.php' );
}

// ---- minimal WordPress ----------------------------------------------------
define( 'ABSPATH', '/tmp/wp/' );
define( 'WP_DEBUG', false );

$GLOBALS['options'] = array();
$GLOBALS['actions'] = array();

if ( 'safe-mode' === $scenario ) {
	$GLOBALS['options']['acps_alerts_safe_mode'] = array( 'msg' => 'previous fatal', 'time' => time() );
}

if ( 'kill-switch' === $scenario ) {
	define( 'ACPS_ALERTS_DISABLE', true );
}

function plugin_basename( $f ) { return 'acps-alert-popups/acps-alert-popups.php'; }
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function plugin_dir_url( $f ) { return 'https://example.org/wp-content/plugins/acps-alert-popups/'; }
function add_action( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['actions'][ $h ][] = $cb; }
function add_filter( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['actions'][ $h ][] = $cb; }
function do_action_stub( $h ) {
	foreach ( ( isset( $GLOBALS['actions'][ $h ] ) ? $GLOBALS['actions'][ $h ] : array() ) as $cb ) { $cb(); }
}
function add_shortcode( $t, $cb ) {}
function register_activation_hook( $f, $cb ) {}
function register_deactivation_hook( $f, $cb ) {}
function get_option( $k, $d = false ) { return isset( $GLOBALS['options'][ $k ] ) ? $GLOBALS['options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['options'][ $k ] ); return true; }
function get_transient( $k ) { return false; }
function set_transient( $k, $v, $t = 0 ) { return true; }
function delete_transient( $k ) { return true; }
function is_admin() { return false; }
function current_user_can( $c ) { return false; }
function wp_parse_args( $a, $d ) { return array_merge( $d, (array) $a ); }
function post_type_exists( $t ) { return 'fl-popup' === $t; }
function load_plugin_textdomain() {}
function apply_filters( $t, $v ) { return $v; }
function __( $s, $d = '' ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s ); }
function esc_html__( $s, $d = '' ) { return $s; }
function esc_attr( $s ) { return htmlspecialchars( (string) $s ); }
function esc_url( $s ) { return $s; }
function esc_url_raw( $s ) { return $s; }
function esc_textarea( $s ) { return htmlspecialchars( (string) $s ); }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_generate_password( $l = 12, $s = true, $x = true ) { return str_repeat( 'x', $l ); }
function wp_hash_password( $p ) { return 'hashed'; }
function wp_roles() { return new class { public function get_names() { return array( 'administrator' => 'Administrator' ); } }; }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function wp_nonce_field() {}
function home_url( $p = '/' ) { return 'https://example.org' . $p; }
function admin_url( $p = '' ) { return 'https://example.org/wp-admin/' . $p; }
function trailingslashit( $s ) { return rtrim( (string) $s, '/' ) . '/'; }
function untrailingslashit( $s ) { return rtrim( (string) $s, '/' ); }
function add_query_arg() { return ''; }
function wp_doing_ajax() { return false; }
function wp_doing_cron() { return false; }
function is_feed() { return false; }
function is_embed() { return false; }
function nocache_headers() {}
function status_header( $c ) {}
function wp_convert_hr_to_bytes( $v ) { return (int) $v * 1024 * 1024; }
function get_post_types( $a = array(), $o = 'names' ) { return array(); }
function size_format( $b ) { return (string) $b; }

// ---- boot it --------------------------------------------------------------
$file = $work . '/acps-alert-popups.php';

try {
	require $file;
	do_action_stub( 'plugins_loaded' );
} catch ( \Throwable $e ) {
	// A throw that escapes boot is exactly the failure this plugin promises
	// cannot happen.
	echo "ESCAPED THROWABLE: " . $e->getMessage() . " @ " . $e->getFile() . ':' . $e->getLine() . "\n";
	rrm( $work );
	exit( 1 );
}

// ---- assert the expected posture -----------------------------------------
$booted = class_exists( 'ACPS_Alerts_Frontend', false );

switch ( $scenario ) {
	case 'healthy':
		$ok  = $booted;
		$why = 'plugin should have loaded its classes';
		break;
	case 'missing-file':
		$ok  = ! $booted && ! isset( $GLOBALS['options']['acps_alerts_safe_mode'] );
		$why = 'plugin should stay dormant without arming safe mode';
		break;
	case 'safe-mode':
	case 'kill-switch':
		$ok  = ! $booted;
		$why = 'plugin should stay dormant';
		break;
	default:
		$ok  = false;
		$why = 'unknown scenario';
}

rrm( $work );

printf( "%-14s request survived: yes | %s: %s\n", $scenario, $why, $ok ? 'yes' : 'NO' );
exit( $ok ? 0 : 1 );
