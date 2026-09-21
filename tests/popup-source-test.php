<?php
/**
 * Taking the alert from Beaver Builder's own Popup module.
 *
 * The alert is not markup this plugin draws. Beaver Builder's Popup module goes
 * on the status page, gets built there like any other popup, and the plugin
 * finds that node and shows it everywhere else.
 *
 * Everything worth pinning here is the finding and the lifting: which node in
 * another post's layout is the popup, how that answer is cached without going
 * stale when the popup moves, how one node is pulled back out of a rendered
 * layout, and how the banner gets words out of a popup that was built entirely
 * in the builder.
 *
 * The Beaver Builder calls themselves are not exercised — they are another
 * plugin's internals and are guarded at every call site — but everything around
 * them is ours and is.
 */

define( 'ABSPATH', '/tmp/wp/' );
define( 'ACPS_ALERTS_DIR', dirname( __DIR__ ) . '/acps-alert-popups/' );

$GLOBALS['meta']      = array();
$GLOBALS['options']   = array();
$GLOBALS['board']     = 42;
$GLOBALS['queried']   = 0;
$GLOBALS['singular']  = true;
$GLOBALS['filters']   = array();

function add_action() {}
function add_filter() {}
function apply_filters( $tag, $value ) {
	return isset( $GLOBALS['filters'][ $tag ] ) ? call_user_func( $GLOBALS['filters'][ $tag ], $value ) : $value;
}
function __( $s, $d = '' ) { return $s; }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function get_post_meta( $id, $k, $single = false ) { return isset( $GLOBALS['meta'][ $id ][ $k ] ) ? $GLOBALS['meta'][ $id ][ $k ] : ''; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['options'] ) ? $GLOBALS['options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['options'][ $k ] ); return true; }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function shortcode_exists( $t ) { return false; }
function is_singular( $t = '' ) { return $GLOBALS['singular']; }
function get_queried_object_id() { return $GLOBALS['queried']; }

class ACPS_Alerts_Failsafe {
	public static function action() {}
	public static function record() {}
	public static function guard( $cb, $args = array(), $ctx = '', $fallback = null ) {
		if ( ! is_callable( $cb ) ) { return $fallback; }
		try { return call_user_func_array( $cb, (array) $args ); } catch ( \Throwable $e ) { return $fallback; }
	}
	public static function capture( $cb, $args = array(), $ctx = '' ) {
		ob_start();
		try { call_user_func_array( $cb, (array) $args ); } catch ( \Throwable $e ) { ob_get_clean(); return ''; }
		return ob_get_clean();
	}
}

class ACPS_Alerts_Status {
	public static function board_page() { return (int) $GLOBALS['board']; }
}

require ACPS_ALERTS_DIR . 'includes/class-acps-alerts-popup-source.php';

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

/**
 * A Beaver Builder layout, in the shape it is really stored: a flat map of
 * nodes, each naming its parent.
 *
 * @param string $popup_type Module slug to give the popup node.
 * @return array
 */
function layout( $popup_type = 'popup' ) {
	return array(
		'row1'    => (object) array( 'type' => 'row', 'parent' => null ),
		'col1'    => (object) array( 'type' => 'column', 'parent' => 'row1' ),
		'board'   => (object) array( 'type' => 'module', 'parent' => 'col1', 'settings' => (object) array( 'type' => 'status-board' ) ),
		'popup1'  => (object) array( 'type' => 'module', 'parent' => 'col1', 'settings' => (object) array( 'type' => $popup_type ) ),
		// Inside the popup: its own row, column, heading and text.
		'prow'    => (object) array( 'type' => 'row', 'parent' => 'popup1' ),
		'pcol'    => (object) array( 'type' => 'column', 'parent' => 'prow' ),
		'phead'   => (object) array( 'type' => 'module', 'parent' => 'pcol', 'settings' => (object) array( 'type' => 'heading', 'heading' => 'West Side &amp; Restart: HOLD' ) ),
		'ptext'   => (object) array( 'type' => 'module', 'parent' => 'pcol', 'settings' => (object) array( 'type' => 'rich-text', 'text' => '<p>The HOLD was lifted by 1:45 PM.</p>' ) ),
		// A heading OUTSIDE the popup, which must never be mistaken for it.
		'pagehead' => (object) array( 'type' => 'module', 'parent' => 'col1', 'settings' => (object) array( 'type' => 'heading', 'heading' => 'School Status Page' ) ),
	);
}

/* ---- finding the popup ---- */

$GLOBALS['meta'][42]['_fl_builder_data'] = layout();

check( 'the popup module on the status page is found', ACPS_Alerts_Popup_Source::find_node( 42 ), 'popup1' );
check( 'and the status board is not mistaken for it', ACPS_Alerts_Popup_Source::find_node( 42 ), 'popup1' );

// The slug has moved between Beaver Builder versions, so each spelling counts.
foreach ( array( 'popup', 'fl-popup', 'fl_popup', 'unified-popup' ) as $slug ) {
	$GLOBALS['meta'][42]['_fl_builder_data'] = layout( $slug );
	ACPS_Alerts_Popup_Source::forget();

	check( "a popup registered as '$slug' is still found", ACPS_Alerts_Popup_Source::find_node( 42 ), 'popup1' );
}

// A site with a differently named popup module can say so.
$GLOBALS['meta'][42]['_fl_builder_data'] = layout( 'acme-modal' );
ACPS_Alerts_Popup_Source::forget();

check( 'an unknown popup module is not guessed at', ACPS_Alerts_Popup_Source::find_node( 42 ), '' );

$GLOBALS['filters']['acps_alerts_popup_module_types'] = function ( $types ) {
	$types[] = 'acme-modal';
	return $types;
};

check( 'but the filter can teach it one', ACPS_Alerts_Popup_Source::find_node( 42 ), 'popup1' );

unset( $GLOBALS['filters']['acps_alerts_popup_module_types'] );

// A page with no popup on it, and a page with no layout at all.
$GLOBALS['meta'][42]['_fl_builder_data'] = array(
	'row1' => (object) array( 'type' => 'row', 'parent' => null ),
);

check( 'a page with no popup reports none', ACPS_Alerts_Popup_Source::find_node( 42 ), '' );
check( 'and neither does a page with no layout', ACPS_Alerts_Popup_Source::find_node( 99 ), '' );
check( 'nor does asking about no page at all', ACPS_Alerts_Popup_Source::find_node( 0 ) === '' ? '' : 'x', '' );

/* ---- remembering it, without going stale ---- */

$GLOBALS['meta'][42]['_fl_builder_data'] = layout();
ACPS_Alerts_Popup_Source::forget();

check( 'the node is detected and cached', ACPS_Alerts_Popup_Source::node_id(), 'popup1' );
ok( 'the cache records which page it came from', 42 === (int) $GLOBALS['options']['acps_alerts_popup_node']['page'] );

// Deleting the popup must not leave the old id behind: a cached node that no
// longer exists would have the front end asking Beaver Builder to render
// nothing on every page of the site.
$stale = layout();
unset( $stale['popup1'] );
$GLOBALS['meta'][42]['_fl_builder_data'] = $stale;

check( 'a cached node that has gone is not trusted', ACPS_Alerts_Popup_Source::node_id(), '' );

// Moving the popup to a different page must not serve the old page's node.
$GLOBALS['meta'][42]['_fl_builder_data'] = layout();
ACPS_Alerts_Popup_Source::forget();
ACPS_Alerts_Popup_Source::node_id();

$GLOBALS['board'] = 77;
$GLOBALS['meta'][77]['_fl_builder_data'] = array(
	'other' => (object) array( 'type' => 'module', 'parent' => null, 'settings' => (object) array( 'type' => 'popup' ) ),
);

check( 'moving the board to another page re-detects', ACPS_Alerts_Popup_Source::node_id(), 'other' );

$GLOBALS['board'] = 42;
ACPS_Alerts_Popup_Source::forget();

/* ---- lifting one node back out of a rendered layout ---- */

$rendered = '<div class="fl-row fl-node-row1"><div class="fl-col fl-node-col1">'
	. '<div class="fl-module fl-node-board">BOARD</div>'
	. '<div class="fl-module fl-node-popup1"><div class="fl-popup-inner">POPUP BODY</div></div>'
	. '</div></div>';

$lifted = ACPS_Alerts_Popup_Source::extract_node( $rendered, 'popup1' );

ok( 'the popup node is lifted out', false !== strpos( $lifted, 'POPUP BODY' ) );
ok( 'and its own wrapper comes with it', false !== strpos( $lifted, 'fl-node-popup1' ) );
ok( 'while the rest of the page does not', false === strpos( $lifted, 'BOARD' ) );

check( 'a node that is not there yields nothing', ACPS_Alerts_Popup_Source::extract_node( $rendered, 'nope' ), '' );
check( 'and neither does an empty node id', ACPS_Alerts_Popup_Source::extract_node( $rendered, '' ), '' );

// The node id reaches an XPath expression, so anything that is not a node id is
// stripped rather than escaped and hoped for.
check( 'a node id carrying quotes is refused', ACPS_Alerts_Popup_Source::extract_node( $rendered, '" or "1' ), '' );

// "fl-node-popup1" must not match "fl-node-popup10": a prefix match would lift
// the wrong popup out of a page with more than one.
$two = '<div class="fl-module fl-node-popup10">WRONG ONE</div><div class="fl-module fl-node-popup1">RIGHT ONE</div>';
$one = ACPS_Alerts_Popup_Source::extract_node( $two, 'popup1' );

ok( 'a longer node id is not matched by a shorter one', false === strpos( $one, 'WRONG ONE' ) );
ok( 'and the right node is the one returned', false !== strpos( $one, 'RIGHT ONE' ) );

/* ---- reading the popup's wording, for the banner ---- */

$GLOBALS['meta'][42]['_fl_builder_data'] = layout();
ACPS_Alerts_Popup_Source::forget();

$words = ACPS_Alerts_Popup_Source::wording();

check( 'the heading inside the popup is read', $words['heading'], 'West Side &amp; Restart: HOLD' );
check( 'and the text inside it', $words['text'], 'The HOLD was lifted by 1:45 PM.' );

// The status page has its own heading module. Reading that instead would put
// the page title on the banner rather than the alert.
ok( 'a heading outside the popup is ignored', 'School Status Page' !== $words['heading'] );

// No popup, nothing to read.
$GLOBALS['meta'][42]['_fl_builder_data'] = array( 'row1' => (object) array( 'type' => 'row', 'parent' => null ) );
ACPS_Alerts_Popup_Source::forget();

$none = ACPS_Alerts_Popup_Source::wording();

check( 'with no popup there is no heading to read', $none['heading'], '' );
check( 'and no text', $none['text'], '' );

// A layout whose parents form a loop must not hang the request.
$GLOBALS['meta'][42]['_fl_builder_data'] = array(
	'popup1' => (object) array( 'type' => 'module', 'parent' => 'a', 'settings' => (object) array( 'type' => 'popup' ) ),
	'a'      => (object) array( 'type' => 'row', 'parent' => 'b' ),
	'b'      => (object) array( 'type' => 'row', 'parent' => 'a' ),
	'head'   => (object) array( 'type' => 'module', 'parent' => 'a', 'settings' => (object) array( 'type' => 'heading', 'heading' => 'Looped' ) ),
);
ACPS_Alerts_Popup_Source::forget();

$looped = ACPS_Alerts_Popup_Source::wording();

ok( 'a layout with a parent loop returns rather than hanging', is_array( $looped ) );

/* ---- the popup never opens on the page it is built on ---- */

$GLOBALS['meta'][42]['_fl_builder_data'] = layout();
ACPS_Alerts_Popup_Source::forget();

$GLOBALS['queried'] = 42;

ob_start();
ACPS_Alerts_Popup_Source::hide_on_source_page();
$head = ob_get_clean();

ok( 'the popup is hidden on the status page itself', false !== strpos( $head, '.fl-node-popup1{display:none !important;}' ) );

$GLOBALS['queried'] = 7;

ob_start();
ACPS_Alerts_Popup_Source::hide_on_source_page();
$other = ob_get_clean();

check( 'and left alone on every other page, where it is the alert', $other, '' );

echo $fails ? "\n$fails failing case(s)\n" : "All popup source cases passed\n";
exit( $fails ? 1 : 0 );
