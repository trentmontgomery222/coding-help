<?php
/**
 * The Current Alert module: the one place the alert is edited.
 *
 * What matters here is that editing the module modifies the single Current
 * Alert rather than making another one, that a complete save cannot quietly
 * reset the bookkeeping the daily cut-off depends on, and that switching the
 * alert on starts its clock while merely editing it does not.
 */

define( 'ABSPATH', '/tmp/wp/' );
define( 'ACPS_ALERTS_DIR', dirname( __DIR__ ) . '/acps-alert-popups/' );
define( 'ACPS_ALERTS_URL', 'https://example.org/wp-content/plugins/acps-alert-popups/' );

$GLOBALS['posts']      = array();
$GLOBALS['post_meta']  = array();
$GLOBALS['options']    = array();
$GLOBALS['registered'] = array();
$GLOBALS['can']        = true;
$GLOBALS['inserted']   = 0;

function __( $s, $d = '' ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url_raw( $s ) { return (string) $s; }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_kses_post( $s ) { return $s; }
function current_user_can( $c ) { return $GLOBALS['can']; }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function update_option( $k, $v, $a = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
function get_option( $k, $d = false ) { return isset( $GLOBALS['options'][ $k ] ) ? $GLOBALS['options'][ $k ] : $d; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['post_meta'][ $id ][ $k ] = $v; return true; }
function get_post_types( $a = array(), $o = 'names' ) { return array(); }
function get_editable_roles() { return array(); }
function wp_insert_post( $arr ) { $GLOBALS['inserted']++; return 900 + $GLOBALS['inserted']; }
function wp_update_post( $arr, $err = false ) {
	$id                      = (int) $arr['ID'];
	$GLOBALS['posts'][ $id ] = array_merge( isset( $GLOBALS['posts'][ $id ] ) ? $GLOBALS['posts'][ $id ] : array(), $arr );
	return $id;
}

class WP_Error {
	public function get_error_message() { return 'error'; }
}

/* ---- the plugin's own classes, stubbed down to what the module touches ---- */

class ACPS_Alerts_Failsafe {
	public static function guard( $cb, $args = array(), $ctx = '', $fallback = null ) {
		return call_user_func_array( $cb, $args );
	}
	public static function record( $ctx, $msg ) {}
}

class ACPS_Alerts_Admin {
	public static function capability() { return 'edit_pages'; }
}

class ACPS_Alerts_Status {
	public static function level_choices() { return array( 'info' => 'Information', 'lockdown' => 'Lockdown' ); }
	public static function cutoff_time() { return '17:50'; }
	public static function level( $k ) { return array( 'banner' => strtoupper( $k ), 'directive' => '', 'color' => '#d81440', 'severity' => 'critical' ); }
	public static function current_alert() { return $GLOBALS['alert']; }
}

/**
 * A stand-in for the real alert: remembers what was saved, and answers get()
 * from whatever was saved last, exactly as the real one does.
 */
class ACPS_Alerts_Alert {
	const META_PREFIX = '_acps_alert_';

	public $id;
	public $saved = array();
	public $saves = 0;

	public function __construct( $id, array $initial = array() ) {
		$this->id    = $id;
		$this->saved = array_merge(
			array( 'enabled' => 0, 'posted_at' => 0, 'archived' => 0, 'status_level' => 'info' ),
			$initial
		);
	}

	public function get_id() { return $this->id; }
	public function get( $k, $d = null ) { return array_key_exists( $k, $this->saved ) ? $this->saved[ $k ] : $d; }

	public function save( array $input ) {
		$this->saves++;
		$this->saved = $input;

		return $input;
	}
}

class FLBuilderModule {
	public $settings;
	public function __construct( $args = array() ) {}
}

class FLBuilder {
	public static function register_module( $class, $fields ) {
		$GLOBALS['registered'][ $class ] = $fields;
	}
}

$GLOBALS['alert'] = new ACPS_Alerts_Alert( 50 );

require ACPS_ALERTS_DIR . 'modules/alert-popup/alert-popup.php';

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

/**
 * Module settings, as Beaver Builder hands them over.
 *
 * @param array $over Values to override.
 * @return object
 */
function settings( array $over = array() ) {
	return (object) array_merge(
		array(
			'heading'    => 'Snow Day — All Schools Closed',
			'text'       => '<p>All ACPS schools are closed today.</p>',
			'level'      => 'lockdown',
			'active'     => '0',
			'as_popup'   => '1',
			'visibility' => 'public',
			'expires'    => 'daily',
		),
		$over
	);
}

$module = new ACPS_Alert_Popup_Module();

/* ---- the module's fields really are registered ---- */

ok( 'the module registers itself with Beaver Builder', isset( $GLOBALS['registered']['ACPS_Alert_Popup_Module'] ) );

$tabs   = $GLOBALS['registered']['ACPS_Alert_Popup_Module'];
$fields = array();

foreach ( $tabs as $tab ) {
	foreach ( $tab['sections'] as $section ) {
		foreach ( $section['fields'] as $key => $field ) {
			$fields[ $key ] = $field;
		}
	}
}

// Everything the alert has must be reachable from this module: the whole point
// is that nobody has to open wp-admin to change a setting.
$expected = array(
	'heading', 'text', 'level', 'active', 'as_popup', 'visibility',
	'expires', 'start', 'end',
	'display', 'post_types', 'post_ids', 'include_urls', 'exclude_urls',
	'audience', 'roles',
	'trigger', 'trigger_delay', 'trigger_scroll', 'frequency', 'frequency_days',
	'position', 'width', 'show_overlay', 'dismissible', 'overlay_close', 'esc_close',
	'aria_label', 'notes',
	'cta_text', 'cta_url', 'show_icon', 'show_word',
);

foreach ( $expected as $key ) {
	ok( "the module offers a '$key' field, so it need not be set in wp-admin", isset( $fields[ $key ] ) );
}

// Severity and priority are gone, and must not creep back in.
ok( 'there is no severity field', ! isset( $fields['severity'] ) );
ok( 'there is no priority field', ! isset( $fields['priority'] ) );

/* ---- editing modifies the one alert ---- */

$alert = new ACPS_Alerts_Alert( 50 );
$GLOBALS['alert'] = $alert;

ACPS_Alert_Popup_Module::apply( $alert, settings() );

check( 'the heading becomes the alert title', $GLOBALS['posts'][50]['post_title'], 'Snow Day — All Schools Closed' );
check( 'the text becomes the alert body', $GLOBALS['posts'][50]['post_content'], '<p>All ACPS schools are closed today.</p>' );
check( 'the level is carried over', $alert->get( 'status_level' ), 'lockdown' );
check( 'the board summary is the text, stripped', $alert->get( 'status_message' ), 'All ACPS schools are closed today.' );
check( 'saving while switched off leaves it off', $alert->get( 'enabled' ), 0 );
check( 'nothing new was created', $GLOBALS['inserted'], 0 );

// Three wording fixes in a row.
ACPS_Alert_Popup_Module::apply( $alert, settings( array( 'heading' => 'Snow Day — Schools Closed' ) ) );
ACPS_Alert_Popup_Module::apply( $alert, settings( array( 'heading' => 'Snow Day' ) ) );

check( 'editing never creates another alert', $GLOBALS['inserted'], 0 );
check( 'and the one alert carries the latest wording', $GLOBALS['posts'][50]['post_title'], 'Snow Day' );

/* ---- an empty heading is "leave it alone", not "blank it" ---- */

ACPS_Alert_Popup_Module::apply( $alert, settings( array( 'heading' => '   ' ) ) );

check( 'an empty heading leaves the title as it was', $GLOBALS['posts'][50]['post_title'], 'Snow Day' );

/* ---- switching it on starts the clock; editing does not restart it ---- */

$alert            = new ACPS_Alerts_Alert( 50 );
$GLOBALS['alert'] = $alert;
$GLOBALS['post_meta'] = array();

ACPS_Alert_Popup_Module::apply( $alert, settings( array( 'active' => '1' ) ) );

$posted = isset( $GLOBALS['post_meta'][50]['_acps_alert_posted_at'] ) ? (int) $GLOBALS['post_meta'][50]['_acps_alert_posted_at'] : 0;

ok( 'switching it on records when it went up', $posted > 0 );
check( 'and clears any earlier archived flag', $GLOBALS['post_meta'][50]['_acps_alert_archived'], 0 );
check( 'the alert is now live', $alert->get( 'enabled' ), 1 );

// It is on now. Pretend it went up an hour ago, then edit the wording.
$alert->saved['posted_at'] = 1000;
$alert->saved['enabled']   = 1;
unset( $GLOBALS['post_meta'][50]['_acps_alert_posted_at'] );

ACPS_Alert_Popup_Module::apply( $alert, settings( array( 'active' => '1', 'heading' => 'Snow Day — corrected' ) ) );

ok(
	'editing an alert that is already up does not restart its cut-off clock',
	! isset( $GLOBALS['post_meta'][50]['_acps_alert_posted_at'] )
);
check( 'and the complete save carries the old timestamp through', $alert->get( 'posted_at' ), 1000 );

/* ---- the custom schedule is the only thing that uses start and end ---- */

$alert            = new ACPS_Alerts_Alert( 50 );
$GLOBALS['alert'] = $alert;

ACPS_Alert_Popup_Module::apply( $alert, settings( array( 'expires' => 'daily', 'start' => '2026-01-01 08:00', 'end' => '2026-01-02 08:00' ) ) );

check( 'on the daily cut-off, a stray start date is ignored', $alert->get( 'start' ), '' );
check( 'and so is a stray end date', $alert->get( 'end' ), '' );

ACPS_Alert_Popup_Module::apply( $alert, settings( array( 'expires' => 'custom', 'start' => '2026-01-01 08:00', 'end' => '2026-01-02 08:00' ) ) );

check( 'on a custom schedule the start date is used', $alert->get( 'start' ), '2026-01-01 08:00' );
check( 'and so is the end date', $alert->get( 'end' ), '2026-01-02 08:00' );

/* ---- the badge, the level word and the link ---- */

$alert            = new ACPS_Alerts_Alert( 50 );
$GLOBALS['alert'] = $alert;

ACPS_Alert_Popup_Module::apply(
	$alert,
	settings( array( 'cta_text' => 'View updates', 'cta_url' => 'https://example.org/status/', 'show_icon' => '1', 'show_word' => '1' ) )
);

check( 'the link text reaches the alert', $alert->get( 'cta_text' ), 'View updates' );
check( 'and the link itself', $alert->get( 'cta_url' ), 'https://example.org/status/' );
check( 'the badge is on', $alert->get( 'show_icon' ), 1 );
check( 'the level word is on', $alert->get( 'show_word' ), 1 );

ACPS_Alert_Popup_Module::apply( $alert, settings( array( 'show_icon' => '0', 'show_word' => '0' ) ) );

check( 'switching the badge off really clears it', $alert->get( 'show_icon' ), 0 );
check( 'and so does switching the level word off', $alert->get( 'show_word' ), 0 );

/* ---- somebody who may not manage alerts changes nothing ---- */

$alert            = new ACPS_Alerts_Alert( 50 );
$GLOBALS['alert'] = $alert;
$GLOBALS['can']   = false;

$module->update( settings( array( 'heading' => 'Posted by someone without the capability' ) ) );

check( 'a user without the capability saves nothing', $alert->saves, 0 );

$GLOBALS['can'] = true;

echo $fails ? "\n$fails failing case(s)\n" : "All popup module cases passed\n";
exit( $fails ? 1 : 0 );
