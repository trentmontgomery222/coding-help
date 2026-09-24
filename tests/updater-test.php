<?php
/**
 * The self-update system's new source and staged-rollout logic.
 *
 * The updater is exercised through its public surface — remote(), inject_update(),
 * rest_status() and maybe_resolve_private_download() — against stubbed WordPress
 * HTTP and settings. What is pinned here is the security-sensitive behaviour:
 * a production install holds until its dev site has verified a version, the
 * status endpoint refuses a wrong key, and the GitHub source normalises a
 * release the same way the manifest source normalises a manifest.
 */

error_reporting( E_ALL & ~E_DEPRECATED );

define( 'ABSPATH', '/tmp/wp/' );
define( 'ACPS_ALERTS_DIR', dirname( __DIR__ ) . '/acps-alert-popups/' );
define( 'ACPS_ALERTS_VERSION', '1.5.1' );
define( 'ACPS_ALERTS_BASENAME', 'acps-alert-popups/acps-alert-popups.php' );
define( 'MINUTE_IN_SECONDS', 60 );

$GLOBALS['settings']   = array();
$GLOBALS['options']    = array();
$GLOBALS['transients'] = array();
$GLOBALS['http']       = array(); // FIFO queue of responses, each { code, body, headers }.
$GLOBALS['downloads']  = array();

function get_transient( $k ) { return array_key_exists( $k, $GLOBALS['transients'] ) ? $GLOBALS['transients'][ $k ] : false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); return true; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['options'] ) ? $GLOBALS['options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['options'][ $k ] ); return true; }

class WP_Error { public $msg; public function __construct( $m = 'err' ) { $this->msg = $m; } public function get_error_message() { return $this->msg; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }

function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['last_url'] = $url;
	if ( empty( $GLOBALS['http'] ) ) { return new WP_Error( 'no stub response queued' ); }
	$r = array_shift( $GLOBALS['http'] );
	return $r instanceof WP_Error ? $r : $r;
}
function wp_remote_retrieve_response_code( $r ) { return isset( $r['code'] ) ? $r['code'] : 0; }
function wp_remote_retrieve_body( $r ) { return isset( $r['body'] ) ? $r['body'] : ''; }
function wp_remote_retrieve_header( $r, $h ) { return isset( $r['headers'][ $h ] ) ? $r['headers'][ $h ] : ''; }

function add_query_arg( $k, $v, $url ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $k . '=' . $v; }
function esc_url_raw( $u ) { return $u; }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
function home_url( $p = '/' ) { return 'https://example.org' . $p; }
function rest_url( $p = '' ) { return 'https://example.org/wp-json/' . $p; }
function nocache_headers() {}
function current_time( $t ) { return '2026-01-01 00:00:00'; }
function download_url( $url ) { $GLOBALS['downloads'][] = $url; return '/tmp/downloaded.zip'; }

class WP_REST_Response {
	public $data;
	public $status;
	public function __construct( $data, $status = 200 ) { $this->data = $data; $this->status = $status; }
}

class WP_REST_Request {
	private $params;
	public function __construct( $params = array() ) { $this->params = $params; }
	public function get_param( $k ) { return isset( $this->params[ $k ] ) ? $this->params[ $k ] : ''; }
}

class ACPS_Alerts_Settings {
	public static function get( $k, $d = null ) {
		return array_key_exists( $k, $GLOBALS['settings'] ) ? $GLOBALS['settings'][ $k ] : $d;
	}
}

require ACPS_ALERTS_DIR . 'includes/class-acps-alerts-updater.php';

$fails = 0;
function check( $label, $actual, $expected ) {
	global $fails;
	if ( $actual !== $expected ) { $fails++; printf( "FAIL %s: expected %s, got %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) ); }
}
function ok( $label, $cond ) { global $fails; if ( ! $cond ) { $fails++; printf( "FAIL %s\n", $label ); } }

$u = new ACPS_Alerts_Updater();

/* ---- the GitHub source normalises a release ---- */

$GLOBALS['settings'] = array(
	'update_enabled' => 1,
	'update_source'  => 'github',
	'gh_owner'       => 'acme',
	'gh_repo'        => 'widget',
	'gh_asset'       => 'acps-alert-popups.zip',
	'gh_token'       => '',
	'update_role'    => 'standalone',
);
$GLOBALS['transients'] = array();
$GLOBALS['http'] = array(
	array(
		'code' => 200,
		'body' => json_encode( array(
			'tag_name'  => 'v2.0.0',
			'html_url'  => 'https://github.com/acme/widget/releases/tag/v2.0.0',
			'body'      => 'notes',
			'assets'    => array(
				array( 'name' => 'other.zip', 'browser_download_url' => 'https://example.org/other.zip' ),
				array( 'name' => 'acps-alert-popups.zip', 'browser_download_url' => 'https://example.org/acps-alert-popups.zip', 'url' => 'https://api.github.com/repos/acme/widget/releases/assets/9' ),
			),
		) ),
	),
);

$remote = $u->remote( true );
check( 'the github tag becomes the version, without the v', $remote['version'], '2.0.0' );
check( 'the named public asset becomes the package', $remote['package'], 'https://example.org/acps-alert-popups.zip' );
ok( 'the github api was the url fetched', false !== strpos( $GLOBALS['last_url'], 'api.github.com/repos/acme/widget/releases/latest' ) );

// A private repo (token set) stores the API asset url instead, for later resolving.
$GLOBALS['settings']['gh_token'] = 'ghp_secret';
$GLOBALS['transients'] = array();
$GLOBALS['http'] = array(
	array( 'code' => 200, 'body' => json_encode( array(
		'tag_name' => '2.1.0',
		'assets'   => array( array( 'name' => 'acps-alert-popups.zip', 'browser_download_url' => 'https://example.org/x.zip', 'url' => 'https://api.github.com/repos/acme/widget/releases/assets/42' ) ),
	) ) ),
);
$priv = $u->remote( true );
check( 'a private repo stores the API asset url for signed resolving', $priv['package'], 'https://api.github.com/repos/acme/widget/releases/assets/42' );

/* ---- staged rollout: production holds until dev verifies ---- */

function transient_obj() { $t = new stdClass(); $t->response = array(); $t->no_update = array(); return $t; }

// A newer version is available from the source.
function stub_source_version( $v ) {
	$GLOBALS['transients'] = array();
	$GLOBALS['http'] = array( array( 'code' => 200, 'body' => json_encode( array( 'tag_name' => $v, 'assets' => array( array( 'name' => 'acps-alert-popups.zip', 'browser_download_url' => 'https://example.org/a.zip' ) ) ) ) ) );
}

// Standalone: the update is offered.
$GLOBALS['settings']['gh_token'] = '';
$GLOBALS['settings']['update_role'] = 'standalone';
stub_source_version( '2.0.0' );
$t = $u->inject_update( transient_obj() );
ok( 'a standalone install is offered the update', isset( $t->response[ ACPS_ALERTS_BASENAME ] ) );

// Production, no dev status configured: HOLD (nothing offered).
$GLOBALS['settings']['update_role'] = 'production';
$GLOBALS['settings']['verify_status_url'] = '';
$GLOBALS['settings']['verify_status_key'] = '';
stub_source_version( '2.0.0' );
$t = $u->inject_update( transient_obj() );
ok( 'production with no dev status holds', ! isset( $t->response[ ACPS_ALERTS_BASENAME ] ) );

// Production, dev has verified the SAME version: offered.
$GLOBALS['settings']['verify_status_url'] = 'https://dev.example.org/wp-json/acps-alerts/v1/update-status';
$GLOBALS['settings']['verify_status_key'] = 'sharedkey';
stub_source_version( '2.0.0' );
// The dev-status fetch happens after the source fetch, so queue it second.
$GLOBALS['http'][] = array( 'code' => 200, 'body' => json_encode( array( 'ok' => true, 'verified' => '2.0.0' ) ) );
$t = $u->inject_update( transient_obj() );
ok( 'production is offered a version the dev site verified', isset( $t->response[ ACPS_ALERTS_BASENAME ] ) );

// Production, dev has only verified an OLDER version: HOLD.
$GLOBALS['transients'] = array();
stub_source_version( '2.0.0' );
$GLOBALS['http'][] = array( 'code' => 200, 'body' => json_encode( array( 'ok' => true, 'verified' => '1.9.0' ) ) );
$t = $u->inject_update( transient_obj() );
ok( 'production holds a version the dev site has not verified', ! isset( $t->response[ ACPS_ALERTS_BASENAME ] ) );

/* ---- the status endpoint is key-guarded ---- */

$GLOBALS['settings']['verify_status_key'] = 'sharedkey';
$GLOBALS['options']['acps_alerts_update_verified'] = array( 'version' => '2.0.0', 'time' => 123 );

$bad = $u->rest_status( new WP_REST_Request( array( 'key' => 'wrong' ) ) );
check( 'a wrong key is refused', $bad->status, 403 );

$good = $u->rest_status( new WP_REST_Request( array( 'key' => 'sharedkey' ) ) );
check( 'the right key is accepted', $good->status, 200 );
check( 'and reports the verified version', $good->data['verified'], '2.0.0' );

// With no key configured, the endpoint refuses everyone.
$GLOBALS['settings']['verify_status_key'] = '';
$none = $u->rest_status( new WP_REST_Request( array( 'key' => '' ) ) );
check( 'an unconfigured key refuses everyone', $none->status, 403 );

/* ---- the private-download resolver only touches our github asset urls ---- */

$GLOBALS['settings']['gh_token'] = 'ghp_secret';

// A non-github package is left to WordPress.
check( 'a plain package is left alone', $u->maybe_resolve_private_download( false, 'https://example.org/a.zip', null ), false );

// Our github API asset url is resolved to the signed redirect and downloaded.
$GLOBALS['http'] = array( array( 'code' => 302, 'headers' => array( 'location' => 'https://signed.example.org/asset?sig=abc' ) ) );
$GLOBALS['downloads'] = array();
$res = $u->maybe_resolve_private_download( false, 'https://api.github.com/repos/acme/widget/releases/assets/42', null );
check( 'the api asset url is resolved to a downloaded file', $res, '/tmp/downloaded.zip' );
check( 'and the signed redirect is what was downloaded', $GLOBALS['downloads'][0], 'https://signed.example.org/asset?sig=abc' );

// With no token, it never intervenes.
$GLOBALS['settings']['gh_token'] = '';
check( 'with no token the resolver stands down', $u->maybe_resolve_private_download( false, 'https://api.github.com/repos/acme/widget/releases/assets/42', null ), false );

echo $fails ? "\n$fails failing case(s)\n" : "All updater cases passed\n";
exit( $fails ? 1 : 0 );
