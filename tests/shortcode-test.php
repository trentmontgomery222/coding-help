<?php
/**
 * The [schoolstatus] shortcode.
 *
 * It puts the current status wherever it is typed — at the top of the popup, in
 * a header, in a sidebar. Two things make it worth pinning: it has to agree
 * with the status board, because two places reporting different statuses is
 * worse than either being wrong; and it has to style itself, because it can be
 * typed into a page that loads none of this plugin's stylesheets.
 */

define( 'ABSPATH', '/tmp/wp/' );
define( 'ACPS_ALERTS_DIR', dirname( __DIR__ ) . '/acps-alert-popups/' );

$GLOBALS['registered_shortcodes'] = array();
$GLOBALS['entry']      = null;
$GLOBALS['normal']     = null;
$GLOBALS['board_page'] = 0;

function add_shortcode( $tag, $cb ) { $GLOBALS['registered_shortcodes'][ $tag ] = $cb; }
function __( $s, $d = '' ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
function sanitize_html_class( $s ) { return preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $s ); }
function absint( $v ) { return abs( (int) $v ); }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function get_permalink( $id ) { return $id ? 'https://example.org/school-status/' : ''; }
function shortcode_atts( $pairs, $atts, $tag = '' ) {
	$atts = (array) $atts;
	$out  = array();
	foreach ( $pairs as $name => $default ) {
		$out[ $name ] = array_key_exists( $name, $atts ) ? $atts[ $name ] : $default;
	}
	return $out;
}

class ACPS_Alerts_Failsafe {
	public static function wrap( $cb, $ctx = '' ) { return $cb; }
}

/**
 * Just enough of an alert to be asked what it says.
 */
class StubAlert {
	public $title;
	public $level;
	public $message;

	public function __construct( $title, $level, $message ) {
		$this->title   = $title;
		$this->level   = $level;
		$this->message = $message;
	}

	public function get_title() { return $this->title; }
	public function get( $k, $d = null ) {
		if ( 'status_level' === $k ) { return $this->level; }
		if ( 'status_message' === $k ) { return $this->message; }
		return $d;
	}
}

class ACPS_Alerts_Status {
	public static function board_entry() { return $GLOBALS['entry']; }
	public static function normal_alert() { return $GLOBALS['normal']; }
	public static function board_page() { return (int) $GLOBALS['board_page']; }
	public static function level( $key ) {
		$levels = array(
			'normal' => array( 'banner' => 'NORMAL', 'directive' => '', 'color' => '#1b2f5e' ),
			'hold'   => array( 'banner' => 'HOLD', 'directive' => 'In Your Classroom or Area', 'color' => '#7a1c82' ),
		);
		return isset( $levels[ $key ] ) ? $levels[ $key ] : $levels['normal'];
	}
	public static function level_icon( $key, $size = 64 ) {
		return '<span class="acps-level-icon" style="width:' . (int) $size . 'px">ICON</span>';
	}
}

require ACPS_ALERTS_DIR . 'includes/class-acps-alerts-shortcodes.php';

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

$acps_shortcodes = new ACPS_Alerts_Shortcodes();
$acps_shortcodes->init();

/**
 * Runs the shortcode the way WordPress would.
 *
 * @param array $atts Attributes.
 * @return string
 */
function sc( array $atts = array() ) {
	return call_user_func( $GLOBALS['registered_shortcodes']['schoolstatus'], $atts );
}

/* ---- it is registered under both names ---- */

ok( 'the shortcode is registered', isset( $GLOBALS['registered_shortcodes']['schoolstatus'] ) );
ok( 'and under its underscored alias, because people type both', isset( $GLOBALS['registered_shortcodes']['school_status'] ) );

/* ---- the resting state ---- */

$GLOBALS['entry']  = null;
$GLOBALS['normal'] = new StubAlert( 'All schools open', 'normal', 'Buses are running as usual.' );

$out = sc();

ok( 'with nothing happening it still says something', '' !== trim( $out ) );
ok( 'and says NORMAL', false !== strpos( $out, 'NORMAL' ) );
ok( 'marked as the resting state', false !== strpos( $out, 'acps-status--normal' ) );
ok( 'and not as live', false === strpos( $out, 'acps-status--live' ) );

/* ---- a live alert ---- */

$GLOBALS['entry'] = new StubAlert( 'West Side Elementary', 'hold', 'The HOLD was lifted by 1:45 PM.' );

$out = sc();

ok( 'a live alert reports its own level', false !== strpos( $out, 'HOLD' ) );
ok( 'marked as live', false !== strpos( $out, 'acps-status--live' ) );
ok( 'and carries the level as a class, so a site can style it', false !== strpos( $out, 'acps-status--hold' ) );

// It has to agree with the board. The board shows the Current Alert while it is
// showing and the resting state otherwise, and so does this.
ok( 'the live alert wins over the normal wording', false === strpos( $out, 'All schools open' ) );

/* ---- choosing what to show ---- */

check( 'the badge alone is just the badge', substr_count( sc( array( 'show' => 'icon' ) ), 'acps-level-icon' ), 1 );
ok( 'and carries no level word', false === strpos( sc( array( 'show' => 'icon' ) ), 'HOLD' ) );

$level_only = sc( array( 'show' => 'level' ) );

ok( 'the level alone is just the word', false !== strpos( $level_only, 'HOLD' ) );
ok( 'with no badge', false === strpos( $level_only, 'acps-level-icon' ) );

$all = sc( array( 'show' => 'all' ) );

foreach ( array( 'acps-level-icon', 'HOLD', 'In Your Classroom or Area', 'West Side Elementary', 'The HOLD was lifted by 1:45 PM.' ) as $wanted ) {
	ok( "'all' includes: $wanted", false !== strpos( $all, $wanted ) );
}

// Commas and spaces both, because people will type both.
foreach ( array( 'icon,headline', 'icon, headline', 'icon headline' ) as $spelling ) {
	$picked = sc( array( 'show' => $spelling ) );

	ok( "'$spelling' gives the badge", false !== strpos( $picked, 'acps-level-icon' ) );
	ok( "'$spelling' gives the headline", false !== strpos( $picked, 'West Side Elementary' ) );
	ok( "'$spelling' gives nothing else", false === strpos( $picked, 'In Your Classroom' ) );
}

// A part nobody has heard of is ignored rather than printed.
$junk = sc( array( 'show' => 'icon nonsense' ) );

ok( 'an unknown part is ignored', false === strpos( $junk, 'nonsense' ) );
ok( 'and the known one still draws', false !== strpos( $junk, 'acps-level-icon' ) );

check( 'asking for nothing draws nothing', sc( array( 'show' => 'nonsense' ) ), '' );

/* ---- only when something is happening ---- */

check( 'when="live" draws nothing on a normal day', '', ( function () {
	$GLOBALS['entry'] = null;
	return sc( array( 'when' => 'live' ) );
} )() );

$GLOBALS['entry'] = new StubAlert( 'West Side Elementary', 'hold', 'Lifted.' );

ok( 'but draws when there is an alert', '' !== trim( sc( array( 'when' => 'live' ) ) ) );

/* ---- it styles itself ---- */

/*
 * The shortcode can be typed into a page that loads none of this plugin's
 * stylesheets — including the popup, which is rendered onto pages that are not
 * the one it was built on. So the layout has to be in the markup.
 */
$bare = sc( array( 'show' => 'icon level', 'layout' => 'row', 'align' => 'left' ) );

ok( 'the wrapper lays itself out', false !== strpos( $bare, 'display:flex' ) );
ok( 'in the direction asked for', false !== strpos( $bare, 'flex-direction:row' ) );
ok( 'and aligned as asked', false !== strpos( $bare, 'text-align:left' ) );

$stacked = sc( array( 'layout' => 'stack' ) );

ok( 'stacking is the other direction', false !== strpos( $stacked, 'flex-direction:column' ) );

// An alignment nobody recognises must not reach a style attribute.
$odd = sc( array( 'align' => 'sideways"; evil' ) );

ok( 'a junk alignment falls back to centre', false !== strpos( $odd, 'text-align:center' ) );
ok( 'and never reaches the markup', false === strpos( $odd, 'evil' ) );

// The badge size is passed through.
ok( 'the badge size is honoured', false !== strpos( sc( array( 'show' => 'icon', 'size' => 96 ) ), 'width:96px' ) );

/* ---- linking to the status page ---- */

ok( 'by default it is not a link', false === strpos( sc(), '<a ' ) );

$GLOBALS['board_page'] = 0;

ok( 'asking for a link with no status page set does not make one', false === strpos( sc( array( 'link' => 'yes' ) ), '<a ' ) );

$GLOBALS['board_page'] = 42;

$linked = sc( array( 'link' => 'yes' ) );

ok( 'with a status page it links there', false !== strpos( $linked, 'https://example.org/school-status/' ) );

echo $fails ? "\n$fails failing case(s)\n" : "All shortcode cases passed\n";
exit( $fails ? 1 : 0 );
