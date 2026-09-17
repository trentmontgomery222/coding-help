<?php
/**
 * Pins the bug this post type exists to fix: "there's no way to save the stuff
 * to make a popup".
 *
 * The plugin used to send people to post-new.php for whatever post type Beaver
 * Builder registered for popups. Whether that screen had a title field or a
 * Publish button was entirely up to Beaver Builder — and when detection fell
 * through to Beaver Themer layouts, it had neither. These checks make sure the
 * plugin owns a post type that can always be created and saved.
 */

define( 'ABSPATH', '/tmp/wp/' );
define( 'ACPS_ALERTS_DIR', dirname( __DIR__ ) . '/acps-alert-popups/' );

$GLOBALS['registered']     = array();
$GLOBALS['existing_types'] = array( 'post', 'page' );
$GLOBALS['settings']       = array();

function register_post_type( $slug, $args ) { $GLOBALS['registered'][ $slug ] = $args; $GLOBALS['existing_types'][] = $slug; }
function post_type_exists( $t ) { return in_array( $t, $GLOBALS['existing_types'], true ); }
function add_action() {}
function add_filter() {}
function apply_filters( $tag, $value ) { return $value; }
function __( $s, $d = '' ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s, $d = '' ) { return $s; }
function esc_url( $s ) { return $s; }
function admin_url( $p = '' ) { return 'https://example.org/wp-admin/' . $p; }
function is_singular( $t = '' ) { return false; }
function get_post_type( $id ) { return isset( $GLOBALS['post_types_by_id'][ $id ] ) ? $GLOBALS['post_types_by_id'][ $id ] : false; }
function get_post_meta( $id, $k, $s = false ) { return isset( $GLOBALS['post_meta'][ $id ][ $k ] ) ? $GLOBALS['post_meta'][ $id ][ $k ] : ''; }
function wp_parse_args( $a, $d ) { return array_merge( $d, (array) $a ); }
function get_permalink( $id ) { return 'https://example.org/site-alert/x/'; }
function add_query_arg( $k, $v = null, $u = null ) { return ( null === $u ? 'https://example.org/?' : $u . '?' ) . $k; }
function home_url( $p = '/' ) { return 'https://example.org' . $p; }
function get_edit_post_link( $id, $c = '' ) { return 'https://example.org/wp-admin/post.php?post=' . $id; }
function trailingslashit( $s ) { return rtrim( (string) $s, '/' ) . '/'; }

class ACPS_Alerts_Settings {
	const OPTION = 'acps_alerts_settings';
	public static function get( $k, $d = null ) { return isset( $GLOBALS['settings'][ $k ] ) ? $GLOBALS['settings'][ $k ] : $d; }
}
class ACPS_Alerts_Failsafe {
	public static function action() {}
	public static function filter() {}
	public static function record() {}
	public static function log() {}
}

require ACPS_ALERTS_DIR . 'includes/class-acps-alerts-post-type.php';
require ACPS_ALERTS_DIR . 'includes/class-acps-alerts-source.php';

$fails = 0;
function ok( $label, $cond ) {
	global $fails;
	if ( ! $cond ) { $fails++; printf( "FAIL %s\n", $label ); }
}
function check( $label, $actual, $expected ) {
	global $fails;
	if ( $actual !== $expected ) {
		$fails++;
		printf( "FAIL %s: expected %s, got %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
	}
}

ACPS_Alerts_Post_Type::register();

$args = $GLOBALS['registered'][ ACPS_Alerts_Post_Type::SLUG ];

/* ---- the screen has to be usable and savable ---- */
ok( 'the alert post type is registered', ! empty( $args ) );
check( 'it has an admin UI at all', $args['show_ui'], true );
ok( 'it supports a title field', in_array( 'title', $args['supports'], true ) );
ok( 'it supports an editor, so there is content to save', in_array( 'editor', $args['supports'], true ) );
ok( 'it keeps revisions, so a bad edit is recoverable', in_array( 'revisions', $args['supports'], true ) );

/* ---- Beaver Builder edits a layout on a front-end URL ---- */
check( 'it is publicly queryable, which the builder needs to edit it', $args['publicly_queryable'], true );
ok( 'it has a rewrite slug so that URL exists', ! empty( $args['rewrite']['slug'] ) );

/* ---- but it is not a browsable page ---- */
check( 'it stays out of site search', $args['exclude_from_search'], true );
check( 'it has no archive', $args['has_archive'], false );
check( 'it is not offered in nav menus', $args['show_in_nav_menus'], false );

$robots = ACPS_Alerts_Post_Type::robots( array() );
check( 'robots are untouched for other post types', $robots, array() );

/* ---- Beaver Builder is told it may edit this type ---- */
$types = ACPS_Alerts_Post_Type::enable_builder( array( 'page' ) );
ok( 'the alert type is handed to Beaver Builder', in_array( ACPS_Alerts_Post_Type::SLUG, $types, true ) );
ok( 'existing builder post types are preserved', in_array( 'page', $types, true ) );
check( 'the list has no duplicates', count( $types ), count( array_unique( $types ) ) );
check( 'a non-array from another filter is handled', ACPS_Alerts_Post_Type::enable_builder( null ), array( ACPS_Alerts_Post_Type::SLUG ) );

/* ---- "Add New" must lead to OUR type, never someone else's ---- */
$url = ACPS_Alerts_Source::new_popup_url();
ok( 'Add New points at the plugin’s own post type', false !== strpos( $url, 'post_type=' . ACPS_Alerts_Post_Type::SLUG ) );
ok( 'Add New is a real post-new.php screen', false !== strpos( $url, 'post-new.php' ) );

/* ---- even with a Beaver Builder popup type present, new alerts go to ours ---- */
$GLOBALS['existing_types'][] = 'fl-popup';
check( 'the primary type stays ours when a BB popup type also exists', ACPS_Alerts_Source::post_type(), ACPS_Alerts_Post_Type::SLUG );
ok( 'Add New still points at ours', false !== strpos( ACPS_Alerts_Source::new_popup_url(), 'post_type=' . ACPS_Alerts_Post_Type::SLUG ) );

/* ---- but pre-existing Beaver Builder popups are still listed ---- */
$sources = ACPS_Alerts_Source::source_post_types();
ok( 'our type is a source', in_array( ACPS_Alerts_Post_Type::SLUG, $sources, true ) );
ok( 'an existing BB popup type is also a source', in_array( 'fl-popup', $sources, true ) );
ok( 'themer layouts are not queried alongside the others', ! in_array( 'fl-theme-layout', $sources, true ) );

/* ---- is_popup accepts every source, and rejects everything else ---- */
$GLOBALS['post_types_by_id'] = array( 1 => ACPS_Alerts_Post_Type::SLUG, 2 => 'fl-popup', 3 => 'page', 4 => 'fl-theme-layout', 5 => 'fl-theme-layout' );
$GLOBALS['post_meta']        = array( 4 => array( '_fl_theme_layout_type' => 'popup' ), 5 => array( '_fl_theme_layout_type' => 'header' ) );

check( 'our own alert is a popup', ACPS_Alerts_Source::is_popup( 1 ), true );
check( 'a Beaver Builder popup is a popup', ACPS_Alerts_Source::is_popup( 2 ), true );
check( 'an ordinary page is not', ACPS_Alerts_Source::is_popup( 3 ), false );
check( 'a themer popup layout is', ACPS_Alerts_Source::is_popup( 4 ), true );
check( 'a themer header layout is not', ACPS_Alerts_Source::is_popup( 5 ), false );

/* ---- the plugin no longer needs Beaver Builder to be usable ---- */
check( 'the plugin is ready without Beaver Builder', ACPS_Alerts_Source::is_ready(), true );

/* ---- an explicit override in settings still wins ---- */
$GLOBALS['settings']['popup_post_type'] = 'fl-popup';
check( 'an admin override is honoured', ACPS_Alerts_Source::post_type(), 'fl-popup' );
ok( 'but Add New still goes somewhere savable', false !== strpos( ACPS_Alerts_Source::new_popup_url(), 'post_type=' . ACPS_Alerts_Post_Type::SLUG ) );

echo $fails ? "\n$fails failing case(s)\n" : "All post type cases passed\n";
exit( $fails ? 1 : 0 );
