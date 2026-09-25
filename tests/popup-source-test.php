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
define( 'ACPS_ALERTS_VERSION', '1.0.0' );
define( 'DAY_IN_SECONDS', 86400 );

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
function update_post_meta( $id, $k, $v ) { $GLOBALS['meta'][ $id ][ $k ] = $v; return true; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['options'] ) ? $GLOBALS['options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['options'][ $k ] ); return true; }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function shortcode_exists( $t ) { return false; }
$GLOBALS['shortcode_ran'] = false;
function do_shortcode( $html ) { $GLOBALS['shortcode_ran'] = true; return str_replace( '[schoolstatus show="icon"]', '<span class="acps-status">BADGE</span>', (string) $html ); }
function function_exists_stub() {}
function is_singular( $t = '' ) { return $GLOBALS['singular']; }
function get_queried_object_id() { return $GLOBALS['queried']; }
function get_post_modified_time( $f = 'U', $gmt = false, $id = 0 ) { return 1700000000; }
function get_transient( $k ) { return isset( $GLOBALS['transients'][ $k ] ) ? $GLOBALS['transients'][ $k ] : false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); return true; }

$GLOBALS['transients'] = array();
$GLOBALS['styles']     = array();
$GLOBALS['registered_styles'] = array( 'fl-builder-layout' );

function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = null ) {
	// wp_enqueue_style( $handle ) with no src enqueues an already-registered
	// style without changing it — it must not wipe the registration.
	if ( '' === $src && isset( $GLOBALS['styles'][ $handle ] ) ) {
		return;
	}
	$GLOBALS['styles'][ $handle ] = array(
		'src'    => $src,
		'deps'   => (array) $deps,
		'ver'    => $ver,
		'inline' => isset( $GLOBALS['styles'][ $handle ]['inline'] ) ? $GLOBALS['styles'][ $handle ]['inline'] : '',
	);
}
function wp_register_style( $handle, $src = '', $deps = array(), $ver = null ) {
	// A no-src registered style still carries inline CSS; record it like an
	// enqueue so the assertions can read it back.
	$GLOBALS['styles'][ $handle ] = array(
		'src'    => $src,
		'deps'   => (array) $deps,
		'ver'    => $ver,
		'inline' => '',
	);
}
function wp_add_inline_style( $handle, $css ) {
	if ( ! isset( $GLOBALS['styles'][ $handle ] ) ) {
		return false;
	}
	$GLOBALS['styles'][ $handle ]['inline'] .= (string) $css;
	return true;
}
function wp_dequeue_style( $handle ) {
	$GLOBALS['dequeued'][] = $handle;
	unset( $GLOBALS['styles'][ $handle ] );
}
function wp_parse_url( $url, $component = -1 ) { return parse_url( (string) $url, $component ); }
function wp_style_is( $handle, $list = 'enqueued' ) {
	if ( 'registered' === $list ) {
		return in_array( $handle, $GLOBALS['registered_styles'], true ) || isset( $GLOBALS['styles'][ $handle ] );
	}
	return isset( $GLOBALS['styles'][ $handle ] );
}
$GLOBALS['dequeued'] = array();
// Stands in for wp_styles()->registered, so dequeue_raw_layout_css() can find
// the raw compiled stylesheet Beaver Builder enqueued and drop it.
class ACPS_WP_Styles {
	public $registered = array();
}
$GLOBALS['wp_styles_obj'] = new ACPS_WP_Styles();
function wp_styles() { return $GLOBALS['wp_styles_obj']; }

/**
 * Stands in for Beaver Builder's asset reader, which reports where it cached a
 * page's generated stylesheet.
 */
class FLBuilderModel {
	public static $info = array();
	public static function get_asset_info() { return self::$info; }
	public static function set_post_id( $id ) {}
	public static function reset_post_id() {}
	public static function get_nodes( $type = null, $parent = null ) { return array(); }
	public static function get_node( $id ) { $n = new stdClass(); $n->settings = new stdClass(); $n->settings->type = 'popup'; return $n; }
}

class FLBuilder {
	/**
	 * Empty stands for a Beaver Builder that will not say where it lives, so
	 * no base stylesheet can be enqueued and that handle stays unknown.
	 *
	 * @var string
	 */
	public static $url = 'https://example.org/wp-content/plugins/bb-plugin/';

	public static function plugin_url() { return self::$url; }

	/**
	 * Records whether Beaver Builder's own layout SCRIPTS were asked for. The
	 * popup engine rides in on this call, so on a normal page it must stay
	 * false — the plugin, not Beaver Builder, opens the popup.
	 *
	 * @var bool
	 */
	public static $scripts_loaded = false;

	public static function enqueue_layout_styles_scripts_by_id( $id ) { self::$scripts_loaded = true; }
	public static function render_module_html( $type, $settings, $node ) { return '<div class="fl-popup">[schoolstatus show="icon"]</div>'; }
}

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

	// The cache key folds in what the status currently is, so that a
	// [schoolstatus] shortcode baked into the popup's cached markup is not
	// frozen at whatever it said when that markup was stored.
	public static function board_entry() { return $GLOBALS['entry']; }
}

$GLOBALS['entry'] = null;

/**
 * An entry the status stamp can be taken from.
 */
class StubEntry {
	public $level;
	public $rev;

	public function __construct( $level, $rev ) {
		$this->level = $level;
		$this->rev   = $rev;
	}

	public function get( $k, $d = null ) { return 'status_level' === $k ? $this->level : $d; }
	public function revision() { return $this->rev; }
}

require ACPS_ALERTS_DIR . 'includes/class-acps-alerts-popup-source.php';

/**
 * Reaches the protected cache, which is otherwise only written at the end of a
 * render that needs Beaver Builder present.
 */
class CacheProbe extends ACPS_Alerts_Popup_Source {
	public static function cache_probe( $html ) { self::cache( $html ); }
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

/* ---- a lifted popup is no longer a popover ---- */

/*
 * Beaver Builder's popup is a real HTML popover. The browser keeps any element
 * carrying `popover` at display:none until showPopover() is called — so once it
 * has been lifted into the alert dialog, where nothing is going to call that, it
 * sits in the DOM greyed out and the alert appears empty. This is the markup
 * that came off the real site.
 */
$real = '<div id="testpop" class="fl-popup fl-animation fl-fade-in" popover="manual" data-animation-delay="0" data-animation-duration="0.5"><p>Body</p></div>';

$inlined = ACPS_Alerts_Popup_Source::inline_popup( $real );

ok( 'the popover attribute is gone', false === stripos( $inlined, 'popover=' ) );
ok( 'and so is any bare one', ! preg_match( '/<[^>]*\spopover[\s>]/i', $inlined ) );
ok( 'the popup itself is untouched', false !== strpos( $inlined, 'class="fl-popup fl-animation fl-fade-in"' ) );
ok( 'its id survives', false !== strpos( $inlined, 'id="testpop"' ) );
ok( 'its data attributes survive', false !== strpos( $inlined, 'data-animation-duration="0.5"' ) );
ok( 'and its body survives', false !== strpos( $inlined, '<p>Body</p>' ) );

// Every spelling of the attribute, since it is valid bare and unquoted too.
foreach ( array( '<div popover="auto">x</div>', "<div popover='manual'>x</div>", '<div popover>x</div>', '<div popover=manual>x</div>' ) as $variant ) {
	$out = ACPS_Alerts_Popup_Source::inline_popup( $variant );

	ok( 'popover is stripped from: ' . $variant, ! preg_match( '/<[^>]*\spopover/i', $out ) );
	ok( 'and the element survives it: ' . $variant, false !== strpos( $out, '>x</div>' ) );
}

// Several elements in one fragment, not just the first.
$many = '<div popover="manual"><span popover="auto">a</span></div>';

check( 'every popover in the fragment is stripped', preg_match_all( '/<[^>]*\spopover/i', ACPS_Alerts_Popup_Source::inline_popup( $many ) ), 0 );

// The word in someone's alert text is not an attribute and must be left alone.
$prose = '<div class="fl-popup" popover="manual"><p>This popover explains the closure.</p></div>';
$kept  = ACPS_Alerts_Popup_Source::inline_popup( $prose );

ok( 'the word "popover" in the text is left alone', false !== strpos( $kept, 'This popover explains the closure.' ) );
ok( 'while the attribute on the tag is still removed', ! preg_match( '/<[^>]*\spopover\s*=/i', $kept ) );

// Things that look close but are not the attribute.
$near = '<div popovertarget="x" data-popover="y">z</div>';
$out  = ACPS_Alerts_Popup_Source::inline_popup( $near );

ok( 'data-popover is not mistaken for popover', false !== strpos( $out, 'data-popover="y"' ) );

check( 'empty markup stays empty', ACPS_Alerts_Popup_Source::inline_popup( '' ), '' );

/* ---- an alert with nothing in it is not an alert ---- */

/*
 * Pins "it shows but there is no content besides the X". The popup is a
 * CONTAINER: its heading, text and buttons are separate nodes naming it as
 * their parent, so rendering the popup module on its own returns the shell and
 * none of the content. That shell is a non-empty string, which is exactly why
 * a plain "is it empty" check let it through and the alert reached the page
 * holding nothing but a close button.
 */
$shell = '<div id="testpop" class="fl-popup fl-animation fl-fade-in"></div>';

ok( 'a popup shell with no children does not count as content', ! ACPS_Alerts_Popup_Source::has_content( $shell ) );

$nested_shell = '<div class="fl-popup"><div class="fl-row"><div class="fl-col"></div></div></div>';

ok( 'and neither do empty rows and columns inside it', ! ACPS_Alerts_Popup_Source::has_content( $nested_shell ) );

ok( 'nothing at all is not content', ! ACPS_Alerts_Popup_Source::has_content( '' ) );
ok( 'and neither is whitespace', ! ACPS_Alerts_Popup_Source::has_content( "  \n\t " ) );

ok(
	'a popup with words in it is content',
	ACPS_Alerts_Popup_Source::has_content( '<div class="fl-popup"><p>All schools are closed.</p></div>' )
);

// A popup can legitimately be a picture with no words in it.
ok(
	'a popup that is only an image is content',
	ACPS_Alerts_Popup_Source::has_content( '<div class="fl-popup"><img src="/snow.png" alt="" /></div>' )
);

ok(
	'and so is one that is only a video',
	ACPS_Alerts_Popup_Source::has_content( '<div class="fl-popup"><video src="/a.mp4"></video></div>' )
);

/* ---- a shortcode inside the popup must not be frozen by the cache ---- */

/*
 * The rendered popup is cached, and the popup can contain [schoolstatus]. A
 * shortcode baked into cached markup says whatever it said when the markup was
 * stored, so the status folds into the cache key: the moment the alert changes,
 * the old markup is simply never read again.
 */
$GLOBALS['meta'][42]['_fl_builder_data'] = layout();
ACPS_Alerts_Popup_Source::forget();

$GLOBALS['transients'] = array();
$GLOBALS['entry']      = null;

CacheProbe::cache_probe( 'RESTING MARKUP' );

$resting_keys = array_keys( $GLOBALS['transients'] );

// An alert goes up.
$GLOBALS['entry'] = new StubEntry( 'hold', 7 );

CacheProbe::cache_probe( 'HOLD MARKUP' );

ok( 'a different status is stored under a different key', count( $GLOBALS['transients'] ) > count( $resting_keys ) );

// Editing the alert moves its revision, which has to count as a change too.
$before = array_keys( $GLOBALS['transients'] );
$GLOBALS['entry'] = new StubEntry( 'hold', 8 );

CacheProbe::cache_probe( 'EDITED MARKUP' );

ok( 'and so does an edit to the same alert', count( $GLOBALS['transients'] ) > count( $before ) );

// Nothing changing must reuse the key, or the popup would be re-rendered on
// every page view — the most expensive thing this plugin does.
$steady = count( $GLOBALS['transients'] );

CacheProbe::cache_probe( 'EDITED MARKUP' );

check( 'an unchanged status reuses its key', count( $GLOBALS['transients'] ), $steady );

$GLOBALS['entry'] = null;

/* ---- the popup's own close button ---- */

/*
 * The popup was built with a close button styled and positioned against its
 * own corner. Ours is positioned against the alert's dialog, and that dialog
 * spans the page so the popup's percentage width has something to be a
 * percentage of — so ours lands in the corner of the WINDOW instead. The
 * popup's own button is used, wired to close the alert, because it calls
 * hidePopover() on something that is no longer a popover.
 */
$with_close = '<div class="fl-popup"><button class="fl-popup-close" aria-label="Close"><svg></svg></button><p>Body</p></div>';
$adopted    = ACPS_Alerts_Popup_Source::adopt_close_button( $with_close );

ok( 'the popup close button is wired to the alert', false !== strpos( $adopted, 'data-acps-close' ) );
ok( 'and is reported as usable', ACPS_Alerts_Popup_Source::has_close_button( $adopted ) );
ok( 'the button keeps its own class, so it keeps its styling', false !== strpos( $adopted, 'class="fl-popup-close"' ) );
ok( 'and everything it contained', false !== strpos( $adopted, '<svg></svg>' ) );

// Running twice — a cached render re-adopted — must not stack attributes.
$twice = ACPS_Alerts_Popup_Source::adopt_close_button( $adopted );

check( 'wiring it twice adds the attribute once', substr_count( $twice, 'data-acps-close' ), 1 );

// A self-closing tag keeps its slash rather than being broken.
$self = ACPS_Alerts_Popup_Source::adopt_close_button( '<span class="fl-popup-close" />' );

ok( 'a self-closing button stays self-closing', false !== strpos( $self, '/>' ) );
ok( 'and is still wired', false !== strpos( $self, 'data-acps-close' ) );

// Single-quoted class attributes are just as valid.
$single = ACPS_Alerts_Popup_Source::adopt_close_button( "<button class='fl-popup-close'>x</button>" );

ok( 'a single-quoted class is matched too', false !== strpos( $single, 'data-acps-close' ) );

// A popup built without a close button leaves the alert to supply its own.
$without = '<div class="fl-popup"><p>Body</p></div>';

check( 'a popup with no close button is untouched', ACPS_Alerts_Popup_Source::adopt_close_button( $without ), $without );
ok( 'and reports that the alert must draw one', ! ACPS_Alerts_Popup_Source::has_close_button( $without ) );

// The words must not be mistaken for the button.
$prose = '<div class="fl-popup"><p>Use the fl-popup-close button to dismiss.</p></div>';

ok( 'the class name in prose is not wired', ! ACPS_Alerts_Popup_Source::has_close_button( ACPS_Alerts_Popup_Source::adopt_close_button( $prose ) ) );

/* ---- the popup has to stay inside the container its CSS names ---- */

/*
 * Pins the transparent popup and the unstyled button. Beaver Builder writes
 * most of a layout's CSS against an ancestor:
 *
 *     .fl-builder-content .fl-node-xxx.fl-button-group .fl-button { background: ... }
 *     .fl-builder-content-10240 .fl-node-yyy.fl-popup { max-width: 55%; ... }
 *
 * Lift the node out of its page and that ancestor is gone, so every one of
 * those rules stops matching — silently, because the stylesheet did load and
 * the node classes are all still right. What survives is exactly the rules that
 * happen not to need an ancestor. That is why the icon kept its purple disc and
 * the heading its font, while the popup lost its background, border, radius and
 * width, and the button lost its fill.
 */
$node = '<div id="testpop" class="fl-popup fl-node-0jstg31kvzho"><a class="fl-button">Go to the school status page</a></div>';

$wrapped = ACPS_Alerts_Popup_Source::wrap( $node, 10240 );

ok( 'the bare container class is restored', false !== strpos( $wrapped, 'fl-builder-content' ) );
ok( 'and the one carrying the post id', false !== strpos( $wrapped, 'fl-builder-content-10240' ) );
ok( 'the popup is inside it', strpos( $wrapped, 'fl-builder-content-10240' ) < strpos( $wrapped, 'id="testpop"' ) );
ok( 'the popup itself is untouched', false !== strpos( $wrapped, 'class="fl-popup fl-node-0jstg31kvzho"' ) );
ok( 'and so is everything in it', false !== strpos( $wrapped, 'Go to the school status page' ) );

// Beaver Builder's own post-id attribute goes on too, since its scripts read it.
ok( 'the post id is on the wrapper as data too', false !== strpos( $wrapped, 'data-post-id="10240"' ) );

// The whole-layout render already brings the wrapper. Wrapping again would nest
// one inside the other for nothing.
$already = '<div class="fl-builder-content fl-builder-content-10240"><div class="fl-popup">x</div></div>';

check( 'markup that already has the wrapper is left alone', ACPS_Alerts_Popup_Source::wrap( $already, 10240 ), $already );

// A wrapper for a DIFFERENT page is not this page's wrapper, and the rules
// scoped to this one would still not match, so it is wrapped.
$other = '<div class="fl-builder-content fl-builder-content-999"><div class="fl-popup">x</div></div>';

ok( 'a wrapper for another page does not count', false !== strpos( ACPS_Alerts_Popup_Source::wrap( $other, 10240 ), 'fl-builder-content-10240' ) );

check( 'nothing to wrap stays nothing', ACPS_Alerts_Popup_Source::wrap( '', 10240 ), '' );
check( 'and with no page there is no wrapper to name', ACPS_Alerts_Popup_Source::wrap( $node, 0 ), $node );

/* ---- loading the status page's stylesheet ---- */

/*
 * The popup's design lives in the status page's generated stylesheet, and that
 * file is on no other page. Without it the popup arrives with its structure and
 * none of its look — unstyled buttons, and every width gone. But that stylesheet
 * must NOT be loaded as-is: Beaver Builder scopes many of its rules to the
 * generic .fl-builder-content wrapper, present on every builder page, so loaded
 * raw its column and row rules land on the host page too. Every rule is confined
 * under .acps-alert (the dialog the lifted popup sits in) and printed inline.
 */
$css_file = sys_get_temp_dir() . '/acps-layout-test.css';
file_put_contents(
	$css_file,
	'.fl-node-x .fl-button{background:#2b4a8b;}'
	. '.fl-builder-content .fl-col{float:left;}'
	. '@media (max-width:768px){.fl-col{float:none;width:100%;}}'
	. '@font-face{font-family:"BB";src:url(bb.woff2);}'
);

$GLOBALS['transients'] = array();
FLBuilderModel::$info = array(
	'css'     => $css_file,
	'css_url' => 'https://example.org/cache/42.css',
);

$GLOBALS['styles'] = array();
ACPS_Alerts_Popup_Source::enqueue_assets();

$layout = isset( $GLOBALS['styles']['acps-alerts-popup-layout'] ) ? $GLOBALS['styles']['acps-alerts-popup-layout'] : null;

ok( 'the status page stylesheet is loaded', null !== $layout );
ok( 'as inline CSS, not a raw link that would leak globally', null !== $layout && '' === (string) $layout['src'] );

$inline = null !== $layout ? (string) $layout['inline'] : '';

ok( 'a node rule is scoped', false !== strpos( $inline, '.acps-alert .fl-node-x .fl-button' ) );
ok( "the leaking .fl-col rule is scoped so it cannot reach the host page", false !== strpos( $inline, '.acps-alert .fl-builder-content .fl-col' ) );
ok( 'no .fl-col rule survives unscoped', ! preg_match( '/(^|[},])\s*\.fl-/', $inline ) );
ok( 'the media query is kept, with its inner rule scoped', false !== strpos( $inline, '@media (max-width:768px)' ) && false !== strpos( $inline, '.acps-alert .fl-col{float:none' ) );
ok( 'the @media at-rule itself is not scoped', false === strpos( $inline, '.acps-alert @media' ) );
ok( '@font-face is left untouched so the font still loads', false !== strpos( $inline, '@font-face{font-family:"BB"' ) && false === strpos( $inline, '.acps-alert @font-face' ) && false === strpos( $inline, '.acps-alert src:' ) );
ok( 'versioned by the file, so an edit busts the browser cache', null !== $layout && '' !== (string) $layout['ver'] );
ok( 'and the base layout stylesheet comes with it', isset( $GLOBALS['styles']['fl-builder-layout'] ) );

/* ---- the raw, unscoped compiled stylesheet Beaver Builder enqueues is dropped ---- */

/*
 * FLBuilder::enqueue_layout_styles_scripts() loads the layout's fonts, icons and
 * scripts (wanted) but ALSO enqueues the compiled stylesheet unscoped — the very
 * leak we are removing. It must be dequeued, matched by the file it points at so
 * the handle's name across Beaver Builder versions does not matter.
 */
$GLOBALS['styles']    = array();
$GLOBALS['dequeued']  = array();
$GLOBALS['transients'] = array();
$GLOBALS['wp_styles_obj']->registered = array(
	// However Beaver Builder named it, its src is the compiled file.
	'fl-builder-layout-42' => (object) array( 'src' => 'https://example.org/cache/42.css?ver=9' ),
	'some-theme-style'     => (object) array( 'src' => 'https://example.org/theme.css' ),
);
ACPS_Alerts_Popup_Source::forget();
ACPS_Alerts_Popup_Source::enqueue_assets();

ok( 'the raw compiled stylesheet is dequeued', in_array( 'fl-builder-layout-42', $GLOBALS['dequeued'], true ) );
ok( 'an unrelated stylesheet is left alone', ! in_array( 'some-theme-style', $GLOBALS['dequeued'], true ) );
ok( 'and our own scoped handle is never dequeued', ! in_array( 'acps-alerts-popup-layout', $GLOBALS['dequeued'], true ) );

$GLOBALS['wp_styles_obj']->registered = array();

/* ---- the popup engine must not be loaded onto the page ---- */

/*
 * The popup is built on the status page, so on every other page Beaver Builder's
 * own layout assets have to be loaded or the popup arrives half-styled — a
 * heading and a button but no card. So the builder's enqueue runs by default.
 * Its popup engine cannot open THIS popup because inline_popup() has already
 * stripped the popover attribute; opening stays with the plugin's own runtime.
 */
FLBuilder::$scripts_loaded = false;
$GLOBALS['styles']         = array();
ACPS_Alerts_Popup_Source::forget();
ACPS_Alerts_Popup_Source::enqueue_assets();

ok( 'Beaver Builder\'s layout assets are enqueued by default, so the popup is fully styled', true === FLBuilder::$scripts_loaded );
ok( 'and the compiled stylesheet is loaded as a backstop too', isset( $GLOBALS['styles']['acps-alerts-popup-layout'] ) );

// A site that needs the builder's scripts kept off every page can opt out.
$GLOBALS['filters']['acps_alerts_load_bb_scripts'] = function () { return false; };
FLBuilder::$scripts_loaded = false;
ACPS_Alerts_Popup_Source::forget();
ACPS_Alerts_Popup_Source::enqueue_assets();

ok( 'the filter can turn the builder assets off', false === FLBuilder::$scripts_loaded );

unset( $GLOBALS['filters']['acps_alerts_load_bb_scripts'] );
ACPS_Alerts_Popup_Source::forget();

/*
 * Pins a stylesheet that never reaches the page. WordPress silently declines to
 * print a style whose dependency it has not heard of, so naming Beaver
 * Builder's base handle unconditionally would mean that on a site where it is
 * called something else, this stylesheet is dropped without a word and the
 * popup has no design at all.
 */
$GLOBALS['registered_styles'] = array();
$GLOBALS['styles']            = array();
FLBuilder::$url               = '';

ACPS_Alerts_Popup_Source::forget();
ACPS_Alerts_Popup_Source::enqueue_assets();

ok( 'with nowhere to load it from, no base stylesheet is registered', ! isset( $GLOBALS['styles']['fl-builder-layout'] ) );

$deps = isset( $GLOBALS['styles']['acps-alerts-popup-layout'] ) ? $GLOBALS['styles']['acps-alerts-popup-layout']['deps'] : null;

ok( 'the stylesheet is still loaded when the base handle is unknown', null !== $deps );

foreach ( (array) $deps as $dep ) {
	ok( "it never depends on an unregistered handle: $dep", wp_style_is( $dep, 'registered' ) );
}

// No cached file on disk — a status page nobody has visited yet — must not
// enqueue a stylesheet pointing at nothing.
FLBuilderModel::$info = array(
	'css'     => $css_file . '.missing',
	'css_url' => 'https://example.org/cache/nope.css',
);

$GLOBALS['styles'] = array();
ACPS_Alerts_Popup_Source::forget();
ACPS_Alerts_Popup_Source::enqueue_assets();

ok( 'a stylesheet that is not on disk is not linked to', ! isset( $GLOBALS['styles']['acps-alerts-popup-layout'] ) );

FLBuilder::$url = 'https://example.org/wp-content/plugins/bb-plugin/';

unlink( $css_file );

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

/* ---- writing the popup's wording, from a quick admin form ---- */

/*
 * The inverse of wording(). A quick admin form (SRP level, header, text)
 * changes what the popup says without opening Beaver Builder, so the write has
 * to land in the popup's own heading and rich-text modules — the same two
 * wording() reads — and nowhere else on the page.
 */
$GLOBALS['meta'][42]['_fl_builder_data'] = layout();
unset( $GLOBALS['meta'][42]['_fl_builder_draft'] );
ACPS_Alerts_Popup_Source::forget();

$wrote = ACPS_Alerts_Popup_Source::write_wording( 'Buses running late', '<p>All routes delayed 30 minutes.</p>', 42 );

check( 'the heading is reported written', $wrote['heading'], true );
check( 'and the body is reported written', $wrote['text'], true );

$after = ACPS_Alerts_Popup_Source::wording();

check( 'the new heading is what the popup now reads back', $after['heading'], 'Buses running late' );
check( 'and the new body too', $after['text'], 'All routes delayed 30 minutes.' );

// The write must stay inside the popup: the status page's own heading module,
// which sits outside it, must be untouched.
$page_head = $GLOBALS['meta'][42]['_fl_builder_data']['pagehead'];
check( 'the heading outside the popup is left alone', $page_head->settings->heading, 'School Status Page' );

// A draft copy, when the builder has one open, is kept in step so a later Save
// in the builder does not republish the old wording over this.
$draft = layout();
$draft['phead']->settings->heading = 'stale draft heading';
$GLOBALS['meta'][42]['_fl_builder_draft'] = $draft;
ACPS_Alerts_Popup_Source::forget();

ACPS_Alerts_Popup_Source::write_wording( 'Early dismissal', '<p>Out at noon.</p>', 42 );

check(
	'the builder draft is updated alongside the published layout',
	$GLOBALS['meta'][42]['_fl_builder_draft']['phead']->settings->heading,
	'Early dismissal'
);

// An empty field leaves that piece alone rather than blanking it.
ACPS_Alerts_Popup_Source::write_wording( '', '<p>Body only.</p>', 42 );

$partial = ACPS_Alerts_Popup_Source::wording();

check( 'an empty heading leaves the old heading standing', $partial['heading'], 'Early dismissal' );
check( 'while the body is still updated', $partial['text'], 'Body only.' );

// The scope check is load-bearing: an outside heading listed BEFORE the popup's
// own must still be passed over, or the write lands on the page title.
$ordered = array(
	'pagehead' => (object) array( 'type' => 'module', 'parent' => 'col1', 'settings' => (object) array( 'type' => 'heading', 'heading' => 'School Status Page' ) ),
	'row1'     => (object) array( 'type' => 'row', 'parent' => null ),
	'col1'     => (object) array( 'type' => 'column', 'parent' => 'row1' ),
	'popup1'   => (object) array( 'type' => 'module', 'parent' => 'col1', 'settings' => (object) array( 'type' => 'popup' ) ),
	'phead'    => (object) array( 'type' => 'module', 'parent' => 'popup1', 'settings' => (object) array( 'type' => 'heading', 'heading' => 'old' ) ),
);
$GLOBALS['meta'][42]['_fl_builder_data'] = $ordered;
unset( $GLOBALS['meta'][42]['_fl_builder_draft'] );
ACPS_Alerts_Popup_Source::forget();

ACPS_Alerts_Popup_Source::write_wording( 'Lockdown lifted', '', 42 );

check( 'a heading outside the popup, listed first, is skipped', $GLOBALS['meta'][42]['_fl_builder_data']['pagehead']->settings->heading, 'School Status Page' );
check( 'and the popup heading further down is the one written', $GLOBALS['meta'][42]['_fl_builder_data']['phead']->settings->heading, 'Lockdown lifted' );

// No popup on the page: nothing to write, and nothing claimed.
$GLOBALS['meta'][42]['_fl_builder_data'] = array( 'row1' => (object) array( 'type' => 'row', 'parent' => null ) );
unset( $GLOBALS['meta'][42]['_fl_builder_draft'] );
ACPS_Alerts_Popup_Source::forget();

$nowhere = ACPS_Alerts_Popup_Source::write_wording( 'Nowhere to put this', 'x', 42 );

check( 'with no popup the heading is not claimed as written', $nowhere['heading'], false );
check( 'nor the body', $nowhere['text'], false );

/* ---- a shortcode inside the popup is executed, not printed ---- */

/*
 * [schoolstatus] typed into the popup can survive Beaver Builder's render as
 * literal text when it sits in a module that does not expand shortcodes. On a
 * live site that showed the raw "[schoolstatus source=\"board\" show=\"icon\"]"
 * on the page. render() runs the processor over the finished popup so it fires.
 */
$GLOBALS['meta'][42]['_fl_builder_data'] = layout();
$GLOBALS['shortcode_ran']                = false;
ACPS_Alerts_Popup_Source::forget();

$rendered = ACPS_Alerts_Popup_Source::render();

ok( 'the popup is run through the shortcode processor', true === $GLOBALS['shortcode_ran'] );
ok( 'so a shortcode inside it is executed', false !== strpos( $rendered, 'BADGE' ) );
ok( 'and its literal text is gone', false === strpos( $rendered, '[schoolstatus' ) );

/* ---- scope_css() in detail ---- */

$scope = '.acps-alert';

// The plain case, and the leak that started all this.
check(
	'a bare selector is confined to the dialog',
	ACPS_Alerts_Popup_Source::scope_css( '.fl-col{float:left}', $scope ),
	'.acps-alert .fl-col{float:left}'
);

// A comma list scopes each selector, and a comma inside :not() is not a split.
check(
	'each selector in a list is scoped',
	ACPS_Alerts_Popup_Source::scope_css( 'a,b{color:red}', $scope ),
	'.acps-alert a,.acps-alert b{color:red}'
);
check(
	'a comma inside :not() is not treated as a separator',
	ACPS_Alerts_Popup_Source::scope_css( '.fl-col:not(.a,.b){x:1}', $scope ),
	'.acps-alert .fl-col:not(.a,.b){x:1}'
);

// A media query keeps its prelude and scopes the rules inside it.
check(
	'the inside of @media is scoped, the @media itself is not',
	ACPS_Alerts_Popup_Source::scope_css( '@media (max-width:600px){.fl-col{width:100%}}', $scope ),
	'@media (max-width:600px){.acps-alert .fl-col{width:100%}}'
);

// @font-face and @keyframes are declarations, not selectors: left alone.
check(
	'@font-face is left untouched',
	ACPS_Alerts_Popup_Source::scope_css( '@font-face{font-family:x;src:url(a.woff2)}', $scope ),
	'@font-face{font-family:x;src:url(a.woff2)}'
);
$keyframes = ACPS_Alerts_Popup_Source::scope_css( '@keyframes spin{from{x:0}to{x:1}}', $scope );
ok( 'the from/to inside @keyframes are not scoped', false === strpos( $keyframes, '.acps-alert from' ) && false !== strpos( $keyframes, '@keyframes spin{from{x:0}to{x:1}}' ) );

// @import stays a whole statement and is never scoped.
check(
	'@import is left as a statement',
	ACPS_Alerts_Popup_Source::scope_css( "@import url(a.css);.fl-col{x:1}", $scope ),
	'@import url(a.css);.acps-alert .fl-col{x:1}'
);

// Comments are stripped, so a "}" inside one cannot throw the brace matching.
check(
	'a comment (even one holding a brace) is stripped',
	ACPS_Alerts_Popup_Source::scope_css( '/* a } b */.fl-col{x:1}', $scope ),
	'.acps-alert .fl-col{x:1}'
);

// A brace inside a string value must not be read as the end of the rule.
$stringy = ACPS_Alerts_Popup_Source::scope_css( '.x{content:"}"}', $scope );
ok( 'a brace inside a string does not break rule matching', false !== strpos( $stringy, '.acps-alert .x{content:"}"}' ) );

// :root / html / body hold a layout's CSS variables: the scope replaces the
// root token so the variables land on the dialog and inherit inward, rather
// than being prefixed into a selector that can never match.
check(
	':root is replaced by the scope, not prefixed',
	ACPS_Alerts_Popup_Source::scope_css( ':root{--c:red}', $scope ),
	'.acps-alert{--c:red}'
);
check(
	'body is replaced by the scope',
	ACPS_Alerts_Popup_Source::scope_css( 'body .fl-col{x:1}', $scope ),
	'.acps-alert .fl-col{x:1}'
);
check(
	'body.fl-builder keeps its trailing compound on the scope',
	ACPS_Alerts_Popup_Source::scope_css( 'body.fl-x{x:1}', $scope ),
	'.acps-alert.fl-x{x:1}'
);

// Empty and whitespace-only input.
check( 'empty CSS stays empty', ACPS_Alerts_Popup_Source::scope_css( '', $scope ), '' );
check( 'whitespace-only CSS stays empty', ACPS_Alerts_Popup_Source::scope_css( "  \n\t", $scope ), '' );

// Every top-level rule really is prefixed — no selector escapes.
$sheet = ACPS_Alerts_Popup_Source::scope_css(
	'.fl-row{a:1}.fl-col{b:2}@media screen{.fl-module{c:3}}',
	$scope
);
ok( 'no rule in a whole sheet escapes the scope', ! preg_match( '/(^|[{},])\s*\.fl-/', $sheet ) );

echo $fails ? "\n$fails failing case(s)\n" : "All popup source cases passed\n";
exit( $fails ? 1 : 0 );
