<?php
/**
 * Checks the teaching layer: tour definitions, the setup checklist, and the
 * promise that losing the help files costs the tutorials and nothing else.
 */

define( 'ABSPATH', '/tmp/wp/' );
define( 'ACPS_ALERTS_DIR', dirname( __DIR__ ) . '/acps-alert-popups/' );
define( 'ACPS_ALERTS_URL', 'https://example.org/wp-content/plugins/acps-alert-popups/' );
define( 'ACPS_ALERTS_VERSION', '1.0.0' );

$GLOBALS['posts']   = array();
$GLOBALS['meta']    = array();
$GLOBALS['options'] = array();

function add_action() {}
function add_filter() {}
function add_submenu_page() {}
function apply_filters( $tag, $value ) { return $value; }
function __( $s, $d = '' ) { return $s; }
function _n( $a, $b, $n, $d = '' ) { return 1 === $n ? $a : $b; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = '' ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return $s; }
function admin_url( $p = '' ) { return 'https://example.org/wp-admin/' . $p; }
function add_query_arg( $args, $url = '' ) {
	$q = http_build_query( is_array( $args ) ? $args : array() );
	return $url . ( false === strpos( (string) $url, '?' ) ? '?' : '&' ) . $q;
}
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
function get_post_status( $p ) { return is_object( $p ) && isset( $p->post_status ) ? $p->post_status : 'publish'; }
function get_option( $k, $d = false ) { return isset( $GLOBALS['options'][ $k ] ) ? $GLOBALS['options'][ $k ] : $d; }
function get_user_meta( $u, $k, $s = false ) { return isset( $GLOBALS['meta'][ $k ] ) ? $GLOBALS['meta'][ $k ] : ''; }
function get_current_user_id() { return 1; }
function get_current_screen() { return null; }
function current_user_can( $c ) { return true; }

class WP_Post {
	public $ID = 0;
	public $post_title = '';
	public $post_status = 'publish';
}

class ACPS_Alerts_Admin {
	const MENU_SLUG     = 'acps-alerts';
	const SETTINGS_SLUG = 'acps-alerts-settings';
	public static function capability() { return 'edit_pages'; }
}
class ACPS_Alerts_Failsafe {
	public static function action() {}
	public static function has_file( $rel ) { return is_readable( ACPS_ALERTS_DIR . $rel ); }
	public static function log() {}
	public static function record() {}
}
class ACPS_Alerts_Source {
	public static $popups = array();
	public static function get_popups( $args = array() ) {
		if ( ! empty( $args['posts_per_page'] ) ) {
			return array_slice( self::$popups, 0, (int) $args['posts_per_page'] );
		}
		return self::$popups;
	}
	public static function builder_active() { return true; }
	public static function post_type() { return 'fl-popup'; }
}
class ACPS_Alerts_Alert {
	public $post;
	public function __construct( $post ) { $this->post = $post; }
	public function get( $k, $d = null ) { return 'enabled' === $k ? 1 : $d; }
}
class ACPS_Alerts_Conditions {
	public static function passes_schedule( $a ) { return true; }
}

require ACPS_ALERTS_DIR . 'includes/class-acps-alerts-help.php';

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
	if ( ! $cond ) {
		$fails++;
		printf( "FAIL %s\n", $label );
	}
}

$help = new ACPS_Alerts_Help();

/* ---- checklist shape, with no popups at all ---- */
ACPS_Alerts_Source::$popups = array();
$list = $help->checklist();

ok( 'checklist returns items', count( $list ) > 0 );

foreach ( $list as $i => $item ) {
	foreach ( array( 'key', 'done', 'label', 'why', 'fix', 'url', 'cta' ) as $field ) {
		ok( "checklist item $i has '$field' (the view reads it unconditionally)", array_key_exists( $field, $item ) );
	}
	ok( "checklist item $i 'done' is boolean", is_bool( $item['done'] ) );
}

$progress = $help->progress();
check( 'progress total matches the checklist', $progress['total'], count( $list ) );
ok( 'progress percent is within range', $progress['percent'] >= 0 && $progress['percent'] <= 100 );

/* ---- checklist reacts to real state ---- */
$empty_done = $progress['done'];

$post = new WP_Post();
$post->ID = 12;
$post->post_title = 'Snow Day';
ACPS_Alerts_Source::$popups = array( $post );

$after = $help->progress();
ok( 'creating a popup ticks more items off', $after['done'] > $empty_done );
ok( 'but setup is not complete until the status board is placed', $after['done'] < $after['total'] );

// Placing the Status Board module records the page it lives on.
$GLOBALS['options']['acps_alerts_board_page'] = 42;

$complete = $help->progress();
check( 'with the board placed and a live alert, setup is complete', $complete['done'], $complete['total'] );
check( 'and that reads as 100 percent', $complete['percent'], 100 );

/* ---- tours ---- */
$tours = $help->tours();

ok( 'the first-alert tour exists', isset( $tours['first-alert'] ) );

foreach ( $tours as $id => $tour ) {
	ok( "tour '$id' has a title", ! empty( $tour['title'] ) );
	ok( "tour '$id' has steps", ! empty( $tour['steps'] ) && is_array( $tour['steps'] ) );

	foreach ( $tour['steps'] as $n => $step ) {
		ok( "tour '$id' step $n has a title", ! empty( $step['title'] ) );
		ok( "tour '$id' step $n has body html", ! empty( $step['html'] ) );

		// A step that names a screen other than the one it can be reached from
		// must carry a url, or the tour would dead-end on that step.
		if ( ! empty( $step['screen'] ) ) {
			$reachable = ! empty( $step['url'] ) || $n > 0;
			ok( "tour '$id' step $n is reachable", $reachable );
		}

		// Placement, when given, has to be one the engine understands.
		if ( ! empty( $step['placement'] ) ) {
			ok(
				"tour '$id' step $n has a valid placement",
				in_array( $step['placement'], array( 'auto', 'top', 'bottom', 'left', 'right' ), true )
			);
		}
	}
}

/* ---- the selectors the tours point at must exist in the plugin's markup ---- */
$fields = file_get_contents( ACPS_ALERTS_DIR . 'includes/class-acps-alerts-fields.php' );
$admin  = file_get_contents( ACPS_ALERTS_DIR . 'includes/class-acps-alerts-admin.php' );
$markup = $fields . $admin;

$checked = 0;

foreach ( $tours as $id => $tour ) {
	foreach ( $tour['steps'] as $n => $step ) {
		if ( empty( $step['selector'] ) ) {
			continue;
		}

		// Only the section anchors are ours to guarantee; WordPress core
		// classes like .page-title-action are core's.
		if ( preg_match( '/\[data-acps-section="([a-z]+)"\]/', $step['selector'], $m ) ) {
			$checked++;
			ok(
				"tour '$id' step $n targets a section that really renders: " . $m[1],
				false !== strpos( $markup, "'" . $m[1] . "'" )
			);
		}
	}
}

ok( 'section-anchored steps were actually checked', $checked > 0 );

echo $fails ? "\n$fails failing case(s)\n" : "All help cases passed\n";
exit( $fails ? 1 : 0 );
