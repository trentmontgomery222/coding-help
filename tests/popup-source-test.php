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
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['options'] ) ? $GLOBALS['options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['options'][ $k ] ); return true; }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function shortcode_exists( $t ) { return false; }
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
	$GLOBALS['styles'][ $handle ] = array( 'src' => $src, 'deps' => (array) $deps, 'ver' => $ver );
}
function wp_style_is( $handle, $list = 'enqueued' ) {
	if ( 'registered' === $list ) {
		return in_array( $handle, $GLOBALS['registered_styles'], true ) || isset( $GLOBALS['styles'][ $handle ] );
	}
	return isset( $GLOBALS['styles'][ $handle ] );
}

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
 * none of its look — unstyled buttons, and every width gone.
 */
$css_file = sys_get_temp_dir() . '/acps-layout-test.css';
file_put_contents( $css_file, '.fl-node-x .fl-button{background:#2b4a8b;}' );

FLBuilderModel::$info = array(
	'css'     => $css_file,
	'css_url' => 'https://example.org/cache/42.css',
);

$GLOBALS['styles'] = array();
ACPS_Alerts_Popup_Source::enqueue_assets();

ok( 'the status page stylesheet is loaded', isset( $GLOBALS['styles']['acps-alerts-popup-layout'] ) );
check( 'from the url Beaver Builder reported', $GLOBALS['styles']['acps-alerts-popup-layout']['src'], 'https://example.org/cache/42.css' );
ok( 'versioned by the file, so an edit busts the browser cache', '' !== (string) $GLOBALS['styles']['acps-alerts-popup-layout']['ver'] );
ok( 'and the base layout stylesheet comes with it', isset( $GLOBALS['styles']['fl-builder-layout'] ) );

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

echo $fails ? "\n$fails failing case(s)\n" : "All popup source cases passed\n";
exit( $fails ? 1 : 0 );
