<?php
/**
 * End-to-end check of the crash-containment promise.
 *
 * Boots the real plugin file against WordPress stubs in each scenario and
 * asserts the request survives it:
 *
 *   healthy / admin-healthy — boots normally
 *   missing-file            — dormant, not paused, one email
 *   missing-help            — boots fully without the tutorials
 *   broken-file             — a parse error: paused, one email
 *   safe-mode               — dormant, loads nothing, no second email
 *   safe-mode-console       — paused, but the console URL still answers
 *   safe-mode-new-version   — a newer version lifts the pause and boots
 *   kill-switch             — never boots at all
 *
 * Each scenario runs in its own PHP process, because a real fatal would end
 * the process; the exit status is the assertion.
 */

$plugin_src = dirname( __DIR__ ) . '/acps-alert-popups';
$scenario   = isset( $argv[1] ) ? $argv[1] : 'healthy';
$GLOBALS['acps_scenario'] = $scenario;
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

if ( 'broken-file' === $scenario ) {
	// Present but broken: a half-uploaded file that no longer parses.
	file_put_contents( $work . '/includes/class-acps-alerts-conditions.php', "<?php\nclass ACPS_Alerts_Conditions {\n\tpublic function ( {\n" );
}

if ( 'missing-help' === $scenario ) {
	// The teaching layer is optional: losing it must cost the tutorials only.
	unlink( $work . '/includes/class-acps-alerts-help.php' );
	unlink( $work . '/includes/class-acps-alerts-art.php' );
	unlink( $work . '/includes/views/help-page.php' );
}

// ---- minimal WordPress ----------------------------------------------------
define( 'ABSPATH', '/tmp/wp/' );
define( 'WP_DEBUG', false );

$GLOBALS['options'] = array();
$GLOBALS['actions'] = array();

$GLOBALS['mails'] = array();

if ( in_array( $scenario, array( 'safe-mode', 'safe-mode-console' ), true ) ) {
	// No 'version' key: a pause recorded by an older release stays paused.
	$GLOBALS['options']['acps_alerts_safe_mode'] = array( 'msg' => 'previous fatal', 'time' => time() );
}

if ( 'safe-mode-console' === $scenario ) {
	// The console's own URL: the one thing that still answers while paused.
	$_GET['acpsupdater'] = 'anything';
}

if ( 'safe-mode-new-version' === $scenario ) {
	// Paused on an older version; this code is newer, so the fix has arrived.
	$GLOBALS['options']['acps_alerts_safe_mode'] = array( 'msg' => 'previous fatal', 'time' => time(), 'version' => '0.0.1' );
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
// The help layer only loads in the admin, so the scenarios that care about it
// have to boot as an admin request or they would pass for the wrong reason.
function is_admin() {
	return in_array( $GLOBALS['acps_scenario'], array( 'admin-healthy', 'missing-help' ), true );
}
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
function wp_login_url() { return 'https://example.org/wp-login.php'; }
function get_bloginfo( $k = 'name' ) { return 'Example'; }
function wp_mail( $to, $subject, $body ) { $GLOBALS['mails'][] = array( $to, $subject, $body ); return true; }
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
		// Dormant, NOT in safe mode (restoring the file must bring it straight
		// back), and the operator told exactly once, naming the file.
		$ok  = ! $booted
			&& ! isset( $GLOBALS['options']['acps_alerts_safe_mode'] )
			&& 1 === count( $GLOBALS['mails'] )
			&& false !== strpos( $GLOBALS['mails'][0][2], 'class-acps-alerts-frontend.php' );
		$why = 'plugin should stay dormant without arming safe mode, and email once';
		break;
	case 'broken-file':
		// A file that is there but does not parse: caught, paused, one email.
		$ok  = ! $booted
			&& isset( $GLOBALS['options']['acps_alerts_safe_mode']['version'] )
			&& 1 === count( $GLOBALS['mails'] )
			&& 'cayden@reactallegany.org' === $GLOBALS['mails'][0][0];
		$why = 'a parse error should arm safe mode and email the operator once';
		break;
	case 'safe-mode-console':
		// Paused, but the console's own URL still gets the console: its class
		// loads and it hooks init. Nothing of the front end is wired.
		$ok  = class_exists( 'ACPS_Alerts_Panel', false )
			&& ! empty( $GLOBALS['actions']['init'] )
			&& empty( $GLOBALS['actions']['wp_footer'] )
			&& empty( $GLOBALS['actions']['wp'] )
			&& isset( $GLOBALS['options']['acps_alerts_safe_mode'] );
		$why = 'the console should still answer while the rest stays paused';
		break;
	case 'safe-mode-new-version':
		$ok  = $booted && ! isset( $GLOBALS['options']['acps_alerts_safe_mode'] );
		$why = 'a new version on disk should lift the pause and boot';
		break;
	case 'admin-healthy':
		// The control for missing-help: with the files present the help layer
		// really does load, so the missing-help result below means something.
		$ok  = $booted && class_exists( 'ACPS_Alerts_Help', false );
		$why = 'plugin and help system should both load';
		break;
	case 'missing-help':
		// The plugin must come up completely; only the tutorials go missing.
		$ok  = $booted && ! class_exists( 'ACPS_Alerts_Help', false );
		$why = 'plugin should load fully, just without the help system';
		break;
	case 'safe-mode':
		// An ordinary request while paused must not even load the paused code:
		// no console class, no hooks beyond the boot itself.
		$ok  = ! $booted && ! class_exists( 'ACPS_Alerts_Panel', false ) && empty( $GLOBALS['mails'] );
		$why = 'plugin should stay dormant, load nothing, and not re-send the email';
		break;
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
