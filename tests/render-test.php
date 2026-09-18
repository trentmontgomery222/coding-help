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
function esc_url( $s ) { return (string) $s; }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function get_permalink( $id ) { return 'https://example.org/school-status/'; }

$GLOBALS['alert_over'] = array();

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
		$map = array_merge(
			array(
				'aria_label'   => '',
				'position'     => 'center',
				'width'        => 640,
				'show_overlay' => 1,
				'dismissible'  => 1,
				'status_level' => 'hold',
				'show_icon'    => 1,
				'show_word'    => 0,
				'cta_text'     => '',
				'cta_url'      => '',
			),
			isset( $GLOBALS['alert_over'][ $this->id ] ) ? $GLOBALS['alert_over'][ $this->id ] : array()
		);

		return array_key_exists( $k, $map ) ? $map[ $k ] : $d;
	}
}

class ACPS_Alerts_Status {
	public static function level( $k ) {
		return array(
			'banner'    => 'HOLD',
			'directive' => 'In Your Classroom or Area',
			'color'     => '#7a1c82',
			'severity'  => 'warning',
			'icon'      => 'M4 12.5l5 5L20 6.5',
		);
	}
	public static function level_icon( $k, $size = 64 ) {
		return '<span class="acps-level-icon">ICON</span>';
	}
	public static function board_page() { return 77; }
}

class ACPS_Alerts_Post_Type {
	const ROLE_CURRENT = 'current';
	public static function role_of( $id ) { return ''; }
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

/* ---- the popup's own furniture: badge, heading, link ---- */

$GLOBALS['content'][30]    = '<p>Cresaptown Elementary was placed in a brief HOLD.</p>';
$GLOBALS['meta'][30]       = array();
$GLOBALS['alert_over'][30] = array( 'cta_text' => 'View updates' );

$probe->queue_alerts( array( new ACPS_Alerts_Alert( 30 ) ) );

ob_start();
$probe->render_alerts();
$out = ob_get_clean();

check( 'the level badge is drawn', substr_count( $out, 'acps-level-icon' ), 1 );
check( 'the heading is the alert title, once', substr_count( $out, '<h2 class="acps-alert__heading">Alert 30</h2>' ), 1 );
check( 'the message is there too', substr_count( $out, 'brief HOLD' ), 1 );
check( 'the call to action is drawn', substr_count( $out, 'View updates' ), 1 );
ok( 'an empty link box falls back to the status page', false !== strpos( $out, 'https://example.org/school-status/' ) );
check( 'the level word is off by default', substr_count( $out, '>HOLD' ), 0 );

/* ---- the level word, when asked for ---- */

$GLOBALS['content'][31]    = '<p>body</p>';
$GLOBALS['meta'][31]       = array();
$GLOBALS['alert_over'][31] = array( 'show_word' => 1, 'show_icon' => 0 );

$probe->queue_alerts( array( new ACPS_Alerts_Alert( 31 ) ) );

ob_start();
$probe->render_alerts();
$out = ob_get_clean();

check( 'the level word is drawn when switched on', substr_count( $out, 'HOLD' ), 1 );
check( 'and its directive with it', substr_count( $out, 'In Your Classroom or Area' ), 1 );
check( 'the badge is off when switched off', substr_count( $out, 'acps-level-icon' ), 0 );

/* ---- a popup designed in Beaver Builder keeps its own heading ---- */

$GLOBALS['content'][32]    = '<p>editor</p>';
$GLOBALS['meta'][32]       = array( '_fl_builder_enabled' => 1, '_fl_builder_data' => array( 'node' => 'x' ) );
$GLOBALS['alert_over'][32] = array( 'cta_text' => 'View updates' );

$probe->queue_alerts( array( new ACPS_Alerts_Alert( 32 ) ) );

ob_start();
$probe->render_alerts();
$out = ob_get_clean();

check( 'a builder layout still renders', substr_count( $out, 'BUILDER_LAYOUT' ), 1 );
check( 'but gets no heading of ours on top of its own', substr_count( $out, 'acps-alert__heading' ), 0 );
check( 'and no badge', substr_count( $out, 'acps-level-icon' ), 0 );
check( 'and no link of ours', substr_count( $out, 'View updates' ), 0 );

echo $fails ? "\n$fails failing case(s)\n" : "All render cases passed\n";
exit( $fails ? 1 : 0 );
