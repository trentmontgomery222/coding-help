<?php
/**
 * Pins the "it's duplicating everything" bug.
 *
 * Beaver Builder hooks its layout renderer onto `the_content`. Asking it to
 * render a post that ALSO has editor content therefore returns the layout and
 * that content together — which reached the page as the whole popup appearing
 * twice, once builder-styled and once theme-styled.
 *
 * The rule these checks enforce: exactly one source is used for a popup body,
 * never both, and no alert is printed twice in one request.
 */

define( 'ABSPATH', '/tmp/wp/' );
define( 'ACPS_ALERTS_DIR', dirname( __DIR__ ) . '/acps-alert-popups/' );
define( 'ACPS_ALERTS_URL', 'https://example.org/p/' );
define( 'ACPS_ALERTS_VERSION', '1.0.0' );

$GLOBALS['meta']           = array();
$GLOBALS['content']        = array();
$GLOBALS['shortcodes']     = array();
$GLOBALS['the_content_cb'] = null;
$GLOBALS['bb_active']      = true;

function add_action() {}
function add_filter() {}
function add_shortcode( $tag, $cb ) { $GLOBALS['shortcodes'][ $tag ] = $cb; }
function shortcode_exists( $tag ) { return isset( $GLOBALS['shortcodes'][ $tag ] ); }
function do_shortcode( $s ) {
	return preg_replace_callback(
		'/\[([a-z_]+) id="(\d+)"\]/',
		function ( $m ) {
			return isset( $GLOBALS['shortcodes'][ $m[1] ] ) ? call_user_func( $GLOBALS['shortcodes'][ $m[1] ], array( 'id' => $m[2] ) ) : '';
		},
		$s
	);
}
function apply_filters( $tag, $value ) {
	if ( 'the_content' === $tag && $GLOBALS['the_content_cb'] ) {
		return call_user_func( $GLOBALS['the_content_cb'], $value );
	}
	return $value;
}
function get_post_meta( $id, $k, $single = false ) { return isset( $GLOBALS['meta'][ $id ][ $k ] ) ? $GLOBALS['meta'][ $id ][ $k ] : ''; }
function get_post( $id ) {
	if ( ! isset( $GLOBALS['content'][ $id ] ) ) { return null; }
	$p = new stdClass();
	$p->ID = $id;
	$p->post_content = $GLOBALS['content'][ $id ];
	return $p;
}
function get_option( $k, $d = false ) { return $d; }
function wp_kses_post( $s ) { return $s; }
function is_singular( $t = '' ) { return false; }
function get_queried_object_id() { return 0; }
function __( $s, $d = '' ) { return $s; }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr_e( $s, $d = '' ) { echo htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = '' ) { return $s; }

class ACPS_Alerts_Failsafe {
	public static function action() {}
	public static function filter() {}
	public static function record() {}
	public static function log() {}
	public static function has_file( $f ) { return true; }
	public static function breaker_tripped( $c ) { return false; }
	public static function memory_ok( $n = 0 ) { return true; }
	public static function guard( $cb, $args = array(), $ctx = '', $fallback = null ) {
		if ( ! is_callable( $cb ) ) { return $fallback; }
		try { return call_user_func_array( $cb, (array) $args ); } catch ( \Throwable $e ) { return $fallback; }
	}
	public static function capture( $cb, $args = array(), $ctx = '' ) {
		ob_start();
		call_user_func_array( $cb, (array) $args );
		return ob_get_clean();
	}
}
class ACPS_Alerts_Settings { public static function get( $k, $d = null ) { return $d; } }
class ACPS_Alerts_Source {
	public static function is_ready() { return true; }
	public static function is_popup( $id ) { return true; }
	public static function get_enabled_alerts() { return array(); }
}
class ACPS_Alerts_Admin { public static function capability() { return 'edit_pages'; } }
class ACPS_Alerts_Conditions { public static function passes( $a ) { return true; } }
class ACPS_Alerts_Updater { const SELFTEST_VAR = 'acps_ap_st'; }
class ACPS_Alerts_Alert {
	private $id;
	public function __construct( $id ) { $this->id = (int) $id; }
	public function get_id() { return $this->id; }
	public function get_title() { return 'Alert ' . $this->id; }
	public function is_valid() { return true; }
	public function get( $k, $d = null ) {
		$map = array( 'aria_label' => '', 'position' => 'center', 'severity' => 'info', 'width' => 640, 'show_overlay' => 1, 'dismissible' => 1 );
		return array_key_exists( $k, $map ) ? $map[ $k ] : $d;
	}
}

// Beaver Builder stand-in. render_content_by_id reproduces the real bug:
// it runs the_content, onto which the builder has hooked its layout renderer.
class FLBuilder {}
class FLBuilderModel {}

// What Beaver Builder does to the_content: inject the layout, but only for a
// post the builder is actually enabled on. That conditional is the whole reason
// the old code duplicated — it asked BB to render a post that had both.
$GLOBALS['bb_the_content'] = function ( $content ) {
	$id = $GLOBALS['rendering_id'];

	$enabled = get_post_meta( $id, '_fl_builder_enabled', true );
	$data    = get_post_meta( $id, '_fl_builder_data', true );

	if ( $enabled && ! empty( $data ) ) {
		return '<div class="fl-builder-content">BUILDER_LAYOUT</div>' . $content;
	}

	return $content;
};

$GLOBALS['the_content_cb'] = $GLOBALS['bb_the_content'];

add_shortcode(
	'fl_builder_insert_layout',
	function ( $atts ) {
		return '<div class="fl-builder-content">BUILDER_LAYOUT</div>';
	}
);

require ACPS_ALERTS_DIR . 'includes/class-acps-alerts-frontend.php';

/**
 * Reaches the protected content resolver.
 */
class Probe extends ACPS_Alerts_Frontend {
	public function body( $id ) {
		// The stubbed the_content filter needs to know which post is being
		// rendered, the way WordPress would from the loop.
		$GLOBALS['rendering_id'] = (int) $id;

		return $this->get_popup_content( $id );
	}
	public function queue_alerts( array $alerts ) { $this->queue = $alerts; }
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

$probe = new Probe();

/* ---- the reported bug: builder layout AND editor content ---- */
$GLOBALS['content'][10] = '<h2>hello</h2><p>text here</p>';
$GLOBALS['meta'][10]    = array( '_fl_builder_enabled' => 1, '_fl_builder_data' => array( 'node' => 'x' ) );

$body = $probe->body( 10 );

check( 'the builder layout renders exactly once', substr_count( $body, 'BUILDER_LAYOUT' ), 1 );
check( 'the editor content is NOT rendered alongside it', substr_count( $body, 'text here' ), 0 );
ok( 'the body is not empty', '' !== trim( $body ) );

/* ---- no builder layout: the editor content is the whole popup ---- */
$GLOBALS['content'][11] = '<h2>plain</h2><p>just editor</p>';
$GLOBALS['meta'][11]    = array();

$body = $probe->body( 11 );

check( 'editor content renders once', substr_count( $body, 'just editor' ), 1 );
ok( 'no builder layout is invented', false === strpos( $body, 'BUILDER_LAYOUT' ) );

/* ---- builder switched off for the post, even with stale layout data ---- */
$GLOBALS['content'][12] = '<p>editor wins</p>';
$GLOBALS['meta'][12]    = array( '_fl_builder_enabled' => 0, '_fl_builder_data' => array( 'node' => 'stale' ) );

$body = $probe->body( 12 );

check( 'a disabled builder falls back to the editor', substr_count( $body, 'editor wins' ), 1 );
ok( 'and does not render the stale layout', false === strpos( $body, 'BUILDER_LAYOUT' ) );

/* ---- enabled but empty layout must not blank the popup ---- */
$GLOBALS['content'][13] = '<p>fallback body</p>';
$GLOBALS['meta'][13]    = array( '_fl_builder_enabled' => 1, '_fl_builder_data' => '' );

check( 'an empty layout falls back to the editor', substr_count( $probe->body( 13 ), 'fallback body' ), 1 );

/* ---- a missing post yields nothing, not a warning ---- */
check( 'a missing post renders nothing', $probe->body( 999 ), '' );

/* ---- re-entry guard: the_content reaching back in cannot double up ---- */
$GLOBALS['content'][14] = '<p>outer</p>';
$GLOBALS['meta'][14]    = array();

$reentered = 0;
$GLOBALS['the_content_cb'] = function ( $content ) use ( $probe, &$reentered ) {
	$reentered++;
	// Something hooked on the_content tries to render the same popup again.
	return $content . $probe->body( 14 );
};

$body = $probe->body( 14 );

check( 'the filter ran', $reentered, 1 );
check( 'the body is not duplicated by re-entry', substr_count( $body, 'outer' ), 1 );

/* ---- an alert is printed at most once per request ---- */
$GLOBALS['the_content_cb'] = null;
$GLOBALS['content'][20]    = '<p>once only</p>';
$GLOBALS['meta'][20]       = array();

$probe->queue_alerts( array( new ACPS_Alerts_Alert( 20 ), new ACPS_Alerts_Alert( 20 ) ) );

ob_start();
$probe->render_alerts();
$out = ob_get_clean();

check( 'the same alert queued twice prints once', substr_count( $out, 'id="acps-alert-20"' ), 1 );

// A second wp_footer (some themes do this) must not print it again.
ob_start();
$probe->render_alerts();
$again = ob_get_clean();

check( 'a second footer pass prints nothing more', substr_count( $again, 'id="acps-alert-20"' ), 0 );

echo $fails ? "\n$fails failing case(s)\n" : "All render cases passed\n";
exit( $fails ? 1 : 0 );
