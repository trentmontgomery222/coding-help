<?php
/**
 * The unlisted maintenance console: its address gate, and the boundary on what
 * it is allowed to change about itself.
 *
 * The address gate is the front door, so its edge cases matter more than most
 * things in this plugin — particularly that an empty allow list fails CLOSED.
 */

define( 'ABSPATH', '/tmp/wp/' );
define( 'ACPS_ALERTS_DIR', dirname( __DIR__ ) . '/acps-alert-popups/' );
define( 'ACPS_ALERTS_VERSION', '1.0.0' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['settings'] = array();
$GLOBALS['types']    = array( 'post', 'page', 'acps_alert' );

function add_action() {}
function add_filter() {}
function apply_filters( $t, $v ) { return $v; }
function __( $s, $d = '' ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = '' ) { return $s; }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr__( $s, $d = '' ) { return $s; }
function esc_textarea( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return $s; }
function esc_url_raw( $s ) { return $s; }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function wp_unslash( $v ) { return $v; }
function absint( $v ) { return abs( (int) $v ); }
function post_type_exists( $t ) { return in_array( $t, $GLOBALS['types'], true ); }
function get_transient( $k ) { return isset( $GLOBALS['transients'][ $k ] ) ? $GLOBALS['transients'][ $k ] : false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); return true; }
function get_option( $k, $d = false ) { return isset( $GLOBALS['options'][ $k ] ) ? $GLOBALS['options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
function nocache_headers() {}
function status_header( $c ) {}
function is_ssl() { return false; }
function home_url( $p = '/' ) { return 'https://example.org' . $p; }
function admin_url( $p = '' ) { return 'https://example.org/wp-admin/' . $p; }
function add_query_arg() { return ''; }
function wp_safe_redirect( $u ) {}
function wp_generate_password( $l = 12, $s = true, $x = true ) { return str_repeat( 'a', $l ); }
function wp_check_password( $p, $h ) { return $h === 'hash:' . $p; }
function wp_die( $m = '', $t = '', $a = array() ) {}
function size_format( $b ) { return (string) $b; }
function human_time_diff( $a, $b ) { return 'a while'; }
function checked( $a, $b, $e = true ) { return ''; }
function selected( $a, $b, $e = true ) { return ''; }
function current_user_can( $c ) { return false; }
function is_user_logged_in() { return false; }

class ACPS_Alerts_Settings {
	const OPTION = 'acps_alerts_settings';
	public static function get( $k, $d = null ) { return isset( $GLOBALS['settings'][ $k ] ) ? $GLOBALS['settings'][ $k ] : $d; }
	public static function defaults() {
		return array(
			'archive_time' => '17:50', 'popup_post_type' => '', 'render_mode' => 'auto',
			'storage' => 'local', 'max_concurrent' => 1, 'z_index' => 999999,
			'custom_css' => '', 'hide_for_admins' => 0, 'respect_preview' => 1,
		);
	}
	public static function patch( $c ) { $GLOBALS['patched'] = $c; return $c; }
}
class ACPS_Alerts_Failsafe {
	public static function action() {}
	public static function filter() {}
	public static function record() {}
	public static function log() {}
	public static function missing_files() { return array(); }
	public static function missing_optional_files() { return array(); }
	public static function problems( $n = 10 ) { return array(); }
	public static function breaker_tripped( $c ) { return false; }
	public static function clear_problems() {}
	public static function reset_breakers() {}
}
class ACPS_Alerts_Source {
	public static function get_popups() { return array(); }
	public static function get_enabled_alerts() { return array(); }
	public static function is_ready() { return true; }
}
class ACPS_Alerts_Updater {
	const FAILED_OPTION = 'acps_alerts_update_failed';
	const HEALTH_OPTION = 'acps_alerts_health';
	public static function flush_cache() {}
	public function peek_status() { return array( 'checked' => false, 'remote' => false, 'has_update' => false ); }
}

require ACPS_ALERTS_DIR . 'includes/class-acps-alerts-panel.php';

/**
 * Reaches the protected gates.
 */
class PanelProbe extends ACPS_Alerts_Panel {
	public function allowed( $ip ) { return $this->ip_allowed( $ip ); }
	public function clean( array $raw ) { return $this->sanitize_console_input( $raw ); }
	public function rate( $ip ) { return $this->rate_ok( $ip ); }
}

$fails = 0;
function check( $label, $actual, $expected ) {
	global $fails;
	if ( $actual !== $expected ) {
		$fails++;
		printf( "FAIL %s: expected %s, got %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
	}
}
function ok( $label, $cond ) {
	global $fails;
	if ( ! $cond ) { $fails++; printf( "FAIL %s\n", $label ); }
}

$panel = new PanelProbe();

/* ---- address matching: exact, prefix, wildcard, CIDR ---- */
$cases = array(
	array( '167.102.110.1', '167.102.110.1', true ),
	array( '167.102.110.2', '167.102.110.1', false ),
	array( '192.168.1.55', '192.168.', true ),
	array( '192.168.1.55', '192.168.*', true ),
	array( '192.169.1.55', '192.168.*', false ),
	array( '10.4.3.2', '10.0.0.0/8', true ),
	array( '11.4.3.2', '10.0.0.0/8', false ),
	array( '192.168.1.5', '192.168.1.0/24', true ),
	array( '192.168.2.5', '192.168.1.0/24', false ),
	array( '2001:db8::1', '2001:db8::/32', true ),
	array( '2002:db8::1', '2001:db8::/32', false ),
	array( '1.2.3.4', '', false ),
	array( '', '1.2.3.4', false ),
	array( '1.2.3.4', 'garbage', false ),
	array( '1.2.3.4', '10.0.0.0/999', false ),
	// A prefix must not match a longer number that merely starts the same way.
	array( '1.2.3.4', '1.2.3.4.5', false ),
	// A bare partial IPv4 (no trailing dot or star) is an octet-boundary prefix.
	array( '196.168.5.9', '196.168', true ),
	array( '196.168.5.9', '196.16', false ),   // 196.16 is not an octet of 196.168
	array( '196.1680.5.9', '196.168', false ), // not a real address, but the dot boundary still holds
	array( '10.4.3.2', '10', true ),
	array( '100.4.3.2', '10', false ),         // 10 must not match 100.x
	array( '167.102.5.9', '167.102.110', false ), // three octets, but a different third
	array( '167.102.110.9', '167.102.110', true ),
);

foreach ( $cases as $i => $c ) {
	check( "ip_matches #$i ({$c[0]} vs '{$c[1]}')", ACPS_Alerts_Panel::ip_matches( $c[0], $c[1] ), $c[2] );
}

/* ---- the shipped default: allow one address, block the world ---- */
$GLOBALS['settings'] = array( 'panel_ip_mode' => 'allow', 'panel_ips' => '167.102.110.1' );
check( 'the default address is let in', $panel->allowed( '167.102.110.1' ), true );
check( 'everything else is blocked', $panel->allowed( '8.8.8.8' ), false );

/* ---- allow a range plus a host ---- */
$GLOBALS['settings']['panel_ips'] = "167.102.110.1\n192.168.";
check( 'a prefix rule lets the office in', $panel->allowed( '192.168.44.7' ), true );
check( 'outsiders stay out', $panel->allowed( '203.0.113.9' ), false );

/* ---- deny mode: everyone except ---- */
$GLOBALS['settings'] = array( 'panel_ip_mode' => 'deny', 'panel_ips' => "203.0.113.9\n10." );
check( 'a listed address is blocked', $panel->allowed( '203.0.113.9' ), false );
check( 'a listed prefix is blocked', $panel->allowed( '10.1.2.3' ), false );
check( 'everyone else gets through', $panel->allowed( '167.102.110.1' ), true );

/* ---- empty lists: allow fails CLOSED, deny fails open ---- */
$GLOBALS['settings'] = array( 'panel_ip_mode' => 'allow', 'panel_ips' => '' );
check( 'allow mode with no rules blocks everyone', $panel->allowed( '167.102.110.1' ), false );

$GLOBALS['settings'] = array( 'panel_ip_mode' => 'deny', 'panel_ips' => '' );
check( 'deny mode with no rules lets everyone through', $panel->allowed( '167.102.110.1' ), true );

/* ---- the console may change operations ---- */
$GLOBALS['settings'] = array();
$clean = $panel->clean(
	array(
		'archive_time'    => '18:30',
		'max_concurrent'  => '3',
		'z_index'         => '1234',
		'render_mode'     => 'modal',
		'storage'         => 'cookie',
		'popup_post_type' => 'acps_alert',
		'custom_css'      => '.x{color:red}',
		'hide_for_admins' => '1',
		'respect_preview' => '1',
		'update_base'     => 'https://updates.example.org/',
		'update_path'     => 'acps-alert-popups',
		'update_key'      => 'sekret',
		'update_enabled'  => '1',
		'update_auto'     => '1',
	)
);

check( 'the daily cut-off can be changed from the console', $clean['archive_time'], '18:30' );
check( 'alerts per page view can be changed', $clean['max_concurrent'], 3 );
check( 'z-index can be changed', $clean['z_index'], 1234 );
check( 'the rendering mode can be changed', $clean['render_mode'], 'modal' );
check( 'the update source can be changed', $clean['update_base'], 'https://updates.example.org/' );
check( 'the update key can be changed', $clean['update_key'], 'sekret' );

/* ---- but NOT anything guarding the console itself ---- */
$attack = $panel->clean(
	array(
		'panel_password'       => 'letmein',
		'panel_password_new'   => 'letmein',
		'panel_ips'            => '0.0.0.0/0',
		'panel_ip_mode'        => 'deny',
		'panel_proxy'          => '1',
		'panel_rate_max'       => '9999',
		'panel_rate_win'       => '30',
		'panel_max_fails'      => '9999',
		'panel_lock_mins'      => '1',
		'panel_edit_hours'     => '0',
		'panel_enabled'        => '1',
		'update_secret'        => 'stolen',
	)
);

foreach ( array( 'panel_password', 'panel_password_new', 'panel_ips', 'panel_ip_mode', 'panel_proxy', 'panel_rate_max', 'panel_rate_win', 'panel_max_fails', 'panel_lock_mins', 'panel_edit_hours', 'panel_enabled', 'update_secret' ) as $key ) {
	ok( "the console cannot change '$key' — it guards the console itself", ! array_key_exists( $key, $attack ) );
}

/* ---- bad values fall back rather than being stored ---- */
$GLOBALS['settings'] = array();
$bad = $panel->clean(
	array(
		'archive_time'    => '99:99',
		'render_mode'     => 'nonsense',
		'storage'         => 'nonsense',
		'max_concurrent'  => '999',
		'popup_post_type' => 'no_such_type',
		'custom_css'      => '<script>alert(1)</script>.x{color:red}',
	)
);

check( 'a bad cut-off falls back to the default', $bad['archive_time'], '17:50' );
check( 'a bad rendering mode falls back', $bad['render_mode'], 'auto' );
check( 'a bad storage mode falls back', $bad['storage'], 'local' );
check( 'an out-of-range count is clamped', $bad['max_concurrent'], 5 );
check( 'an unregistered post type falls back', $bad['popup_post_type'], '' );
ok( 'script tags are stripped from extra CSS', false === strpos( $bad['custom_css'], '<script>' ) );

/* ---- rate limiting ---- */
$GLOBALS['transients'] = array();
$GLOBALS['settings']   = array( 'panel_rate_max' => 3, 'panel_rate_win' => 300 );

$allowed = 0;

for ( $i = 0; $i < 6; $i++ ) {
	if ( $panel->rate( '1.2.3.4' ) ) {
		$allowed++;
	}
}

check( 'requests beyond the cap are refused', $allowed, 3 );
check( 'a different address has its own budget', $panel->rate( '5.6.7.8' ), true );

echo $fails ? "\n$fails failing case(s)\n" : "All panel cases passed\n";
exit( $fails ? 1 : 0 );
