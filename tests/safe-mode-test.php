<?php
/**
 * Verifies the silent-safe-mode behaviour:
 *   - arming safe mode emails the operator exactly ONCE per episode, and the
 *     mail carries the site, login and remote-console links;
 *   - a second arm while already dormant sends nothing;
 *   - the Main CSS editor's default_css() returns the real plugin CSS.
 *
 * Boots the real plugin against WordPress stubs, like boot-test.php, then drives
 * the safe-mode helpers directly.
 */

error_reporting( E_ALL & ~E_DEPRECATED );

define( 'ABSPATH', '/tmp/wp-sm/' );
define( 'WP_DEBUG', false );

// The plugin defines ACPS_ALERTS_DIR itself from its own path; we only need the
// location to require it in the first place.
$acps_plugin_file = dirname( __DIR__ ) . '/acps-alert-popups/acps-alert-popups.php';

$GLOBALS['options']    = array();
$GLOBALS['transients'] = array();
$GLOBALS['acps_mails']   = array();
$GLOBALS['acps_hooks']   = array();
$GLOBALS['acps_actions'] = array();

$fails = 0;
function check( $label, $actual, $expected ) {
	global $fails;
	if ( $actual !== $expected ) {
		$fails++;
		printf( "FAIL %s: expected %s, got %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
	}
}

// ---- WordPress stubs ------------------------------------------------------
function get_option( $k, $d = false ) { return isset( $GLOBALS['options'][ $k ] ) ? $GLOBALS['options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['options'][ $k ] ); return true; }
function get_transient( $k ) { return isset( $GLOBALS['transients'][ $k ] ) ? $GLOBALS['transients'][ $k ] : false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); return true; }

function add_action( $h = '', $c = null, $p = 10, $a = 1 ) {
	$GLOBALS['acps_hooks'][] = $h;
	$GLOBALS['acps_actions'][ $h ][] = $c;
}
function acps_fire( $h ) {
	if ( empty( $GLOBALS['acps_actions'][ $h ] ) ) { return; }
	foreach ( $GLOBALS['acps_actions'][ $h ] as $cb ) {
		if ( is_callable( $cb ) ) { call_user_func( $cb ); }
	}
}
function add_filter() {}
function do_action_stub( $h ) {}
function apply_filters( $t, $v ) { return $v; }
function remove_action() {}

function is_admin() { return false; }
function current_user_can( $c ) { return false; }
function wp_parse_args( $a, $d ) { return array_merge( $d, (array) $a ); }
function post_type_exists( $t ) { return 'fl-popup' === $t; }
function load_plugin_textdomain() {}
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
function wp_login_url() { return 'https://example.org/wp-login.php'; }
function get_bloginfo( $k = 'name' ) { return 'Example School'; }
function trailingslashit( $s ) { return rtrim( (string) $s, '/' ) . '/'; }
function untrailingslashit( $s ) { return rtrim( (string) $s, '/' ); }
function add_query_arg( $k, $v, $u ) { return $u . ( strpos( $u, '?' ) === false ? '?' : '&' ) . $k . '=' . $v; }
function wp_doing_ajax() { return false; }
function wp_doing_cron() { return false; }
function is_feed() { return false; }
function is_embed() { return false; }
function nocache_headers() {}
function status_header( $c ) {}
function wp_convert_hr_to_bytes( $v ) { return (int) $v * 1024 * 1024; }
function get_post_types( $a = array(), $o = 'names' ) { return array(); }
function size_format( $b ) { return (string) $b; }
function wp_mail( $to, $subject, $body ) {
	$GLOBALS['acps_mails'][] = array( 'to' => $to, 'subject' => $subject, 'body' => $body );
	return true;
}
function plugin_basename( $f ) { return basename( $f ); }
function plugin_dir_url( $f ) { return 'https://example.org/wp-content/plugins/acps-alert-popups/'; }
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function register_activation_hook() {}
function register_deactivation_hook() {}
function register_uninstall_hook() {}
function get_bloginfo_url() { return 'https://example.org'; }
function wp_normalize_path( $p ) { return str_replace( '\\', '/', (string) $p ); }
function did_action() { return 0; }
function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }

// A console key so access_key() yields a URL in the email.
$GLOBALS['options']['acps_alerts_settings'] = array( 'console_key' => 'consolekey123' );

// ---- boot the real plugin -------------------------------------------------
try {
	require $acps_plugin_file;
	acps_fire( 'plugins_loaded' );
} catch ( \Throwable $e ) {
	echo 'ESCAPED THROWABLE on boot: ' . $e->getMessage() . "\n";
	exit( 1 );
}

check( 'plugin booted', class_exists( 'ACPS_Alerts_Frontend', false ), true );
check( 'notify function defined', function_exists( 'acps_alerts_notify_safe_mode' ), true );

// ---- first arm: emails exactly once ---------------------------------------
acps_alerts_arm_safe_mode( 'Call to undefined function foo()', '/x/acps.php', 42 );
check( 'first arm sends one email', count( $GLOBALS['acps_mails'] ), 1 );

if ( ! empty( $GLOBALS['acps_mails'] ) ) {
	$mail = $GLOBALS['acps_mails'][0];
	check( 'email goes to the operator', $mail['to'], 'cayden@reactallegany.org' );
	check( 'email names the site', strpos( $mail['body'], 'https://example.org/' ) !== false, true );
	check( 'email carries the login link', strpos( $mail['body'], 'wp-login.php' ) !== false, true );
	check( 'email carries the remote console link', strpos( $mail['body'], 'acpsupdater=consolekey123' ) !== false, true );
	check( 'email reports the error', strpos( $mail['body'], 'Call to undefined function foo()' ) !== false, true );
}

// ---- second arm while already dormant: sends nothing ----------------------
acps_alerts_arm_safe_mode( 'A later fatal on the next request', '/x/acps.php', 99 );
check( 'second arm sends no further email', count( $GLOBALS['acps_mails'] ), 1 );

// ---- the pause records which code crashed ---------------------------------
check( 'the pause records the crashing version', $GLOBALS['options']['acps_alerts_safe_mode']['version'], ACPS_ALERTS_VERSION );

// ---- missing files: one email per distinct set, never per request ---------
$GLOBALS['acps_mails'] = array();
acps_alerts_notify_missing_files( array( 'includes/class-acps-alerts-frontend.php' ) );
acps_alerts_notify_missing_files( array( 'includes/class-acps-alerts-frontend.php' ) );
check( 'the same missing file is reported once, not on every request', count( $GLOBALS['acps_mails'] ), 1 );

if ( ! empty( $GLOBALS['acps_mails'] ) ) {
	$mail = $GLOBALS['acps_mails'][0];
	check( 'the missing-files email says so in its subject', false !== strpos( $mail['subject'], 'files missing' ), true );
	check( 'and names the file', false !== strpos( $mail['body'], 'class-acps-alerts-frontend.php' ), true );
	check( 'and says it recovers by itself once restored', false !== strpos( $mail['body'], 'comes back by itself' ), true );
}

acps_alerts_notify_missing_files( array( 'includes/class-acps-alerts-status.php' ) );
check( 'a different breakage is reported afresh', count( $GLOBALS['acps_mails'] ), 2 );

// ---- the safe-mode email names every way out ------------------------------
$GLOBALS['acps_mails'] = array();
unset( $GLOBALS['options']['acps_alerts_safe_mode'] );
acps_alerts_arm_safe_mode( 'boom', '/x/acps.php', 1 );
check( 'the safe-mode email lists installing a new version as a way out', false !== strpos( $GLOBALS['acps_mails'][0]['body'], 'a new version is installed' ), true );
check( 'and carries the one-click recovery URL', false !== strpos( $GLOBALS['acps_mails'][0]['body'], 'acps_alerts_resume=' ), true );
check( 'built from the console key', false !== strpos( $GLOBALS['acps_mails'][0]['body'], 'acps_alerts_resume=consolekey123' ), true );

// ---- no on-screen safe-mode notice was ever hooked ------------------------
check( 'safe_mode_notice function removed', function_exists( 'acps_alerts_safe_mode_notice' ), false );
check( 'the PHP-version notice is gone', function_exists( 'acps_alerts_php_notice' ), false );

// Nothing in wp-admin or on the site may announce a failure, a pause or
// missing files. Scan every string the plugin can print, outside the two
// places that are allowed to talk about it: the unlisted console (which only
// the operator reaches) and the operator email in the main file.
$acps_dir   = dirname( $acps_plugin_file ) . '/';
$acps_found = array();
$acps_it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $acps_dir, FilesystemIterator::SKIP_DOTS ) );

foreach ( $acps_it as $acps_f ) {
	$acps_rel = substr( $acps_f->getPathname(), strlen( $acps_dir ) );

	if ( '.php' !== substr( $acps_rel, -4 ) || in_array( $acps_rel, array( 'includes/class-acps-alerts-panel.php', 'acps-alert-popups.php' ), true ) ) {
		continue;
	}

	foreach ( token_get_all( file_get_contents( $acps_f->getPathname() ) ) as $acps_tok ) {
		if ( ! is_array( $acps_tok ) || ! in_array( $acps_tok[0], array( T_CONSTANT_ENCAPSED_STRING, T_INLINE_HTML, T_ENCAPSED_AND_WHITESPACE ), true ) ) {
			continue;
		}

		if ( preg_match( '/safe mode|is paused|could not be drawn|not installed|reactivate the plugin|notice-error|notice-warning|failed its load test/i', $acps_tok[1] ) ) {
			$acps_found[] = $acps_rel . ':' . $acps_tok[2] . ' ' . trim( substr( $acps_tok[1], 0, 60 ) );
		}
	}
}

check( 'no failure, pause or missing-file notice can be printed anywhere', $acps_found, array() );

// ---- default_css() returns the real plugin CSS ----------------------------
if ( ! class_exists( 'ACPS_Alerts_Admin', false ) && is_readable( ACPS_ALERTS_DIR . 'includes/class-acps-alerts-admin.php' ) ) {
	require ACPS_ALERTS_DIR . 'includes/class-acps-alerts-admin.php';
}
check( 'admin class present for CSS editor', class_exists( 'ACPS_Alerts_Admin', false ), true );

if ( class_exists( 'ACPS_Alerts_Admin', false ) && method_exists( 'ACPS_Alerts_Admin', 'default_css' ) ) {
	$css = ACPS_Alerts_Admin::default_css();
	check( 'default_css is non-empty', strlen( $css ) > 100, true );
	// It should include content from both source stylesheets.
	$alerts = is_readable( ACPS_ALERTS_DIR . 'assets/css/alerts.css' ) ? file_get_contents( ACPS_ALERTS_DIR . 'assets/css/alerts.css' ) : '';
	$board  = is_readable( ACPS_ALERTS_DIR . 'assets/css/board.css' ) ? file_get_contents( ACPS_ALERTS_DIR . 'assets/css/board.css' ) : '';
	if ( '' !== $alerts ) {
		$needle = substr( trim( $alerts ), 0, 20 );
		check( 'default_css includes alerts.css', strpos( $css, $needle ) !== false, true );
	}
	if ( '' !== $board ) {
		$needle = substr( trim( $board ), 0, 20 );
		check( 'default_css includes board.css', strpos( $css, $needle ) !== false, true );
	}
	// A round-trip through the CSS sanitizer must not mangle the defaults
	// (no '<' in them, so strip_tags leaves them intact).
	check( 'sanitized default_css is unchanged', wp_strip_all_tags( $css ), $css );
} else {
	$fails++;
	echo "FAIL default_css method missing\n";
}

// ---- notices only ever on the plugin's own screens ----------------------
if ( class_exists( 'ACPS_Alerts_Admin', false ) ) {
	foreach ( array( 'acps-alerts', 'acps-alerts-settings', 'acps-alerts-help' ) as $acps_page ) {
		$_GET['page'] = $acps_page;
		check( "the plugin's own page '$acps_page' is its own screen", ACPS_Alerts_Admin::is_own_screen(), true );
	}

	foreach ( array( '', 'some-other-plugin', 'acps-alerts-evil' ) as $acps_page ) {
		$_GET['page'] = $acps_page;
		check( "'$acps_page' is not the plugin's screen", ACPS_Alerts_Admin::is_own_screen(), false );
	}

	unset( $_GET['page'] );
	check( 'the Dashboard, Plugins or any other WordPress screen is not the plugin\'s', ACPS_Alerts_Admin::is_own_screen(), false );
}

if ( $fails ) {
	printf( "\n%d safe-mode case(s) failed\n", $fails );
	exit( 1 );
}
echo "All safe-mode cases passed\n";
