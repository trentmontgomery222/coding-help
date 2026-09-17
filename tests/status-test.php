<?php
/**
 * The status board: the daily cut-off maths, and who may see a staged entry.
 *
 * The cut-off is the part worth pinning. It has to work in site time, roll to
 * the following day for anything posted after the cut-off, and be enforced on
 * every request rather than trusting cron to have fired.
 */

define( 'ABSPATH', '/tmp/wp/' );
define( 'ACPS_ALERTS_DIR', dirname( __DIR__ ) . '/acps-alert-popups/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['settings']  = array();
$GLOBALS['options']   = array();
$GLOBALS['gmt_offset'] = 0;
$GLOBALS['is_staff']  = false;
$GLOBALS['logged_in'] = false;

function add_action() {}
function add_filter() {}
function apply_filters( $tag, $value ) { return $value; }
function do_action() {}
function __( $s, $d = '' ) { return $s; }
function get_option( $k, $d = false ) {
	if ( 'gmt_offset' === $k ) { return $GLOBALS['gmt_offset']; }
	return isset( $GLOBALS['options'][ $k ] ) ? $GLOBALS['options'][ $k ] : $d;
}
function update_option( $k, $v, $a = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function is_user_logged_in() { return $GLOBALS['logged_in']; }
function current_user_can( $c ) { return $GLOBALS['is_staff']; }
function get_post_status( $id ) { return isset( $GLOBALS['posts'][ $id ] ) || $id < 100 ? 'publish' : false; }
function get_post_time( $f, $gmt = false, $id = 0 ) { return 0; }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function wp_kses_post( $s ) { return $s; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['post_meta'][ $id ][ $k ] = $v; return true; }
function wp_update_post( $arr, $wp_error = false ) {
	$id = (int) $arr['ID'];
	if ( ! isset( $GLOBALS['posts'][ $id ] ) ) { return 0; }
	$GLOBALS['posts'][ $id ] = array_merge( $GLOBALS['posts'][ $id ], $arr );
	return $id;
}
function wp_insert_post( $arr, $wp_error = false ) {
	$GLOBALS['next_id'] = isset( $GLOBALS['next_id'] ) ? $GLOBALS['next_id'] + 1 : 100;
	$GLOBALS['posts'][ $GLOBALS['next_id'] ] = $arr;
	return $GLOBALS['next_id'];
}
function is_wp_error( $t ) { return false; }
function wp_next_scheduled() { return false; }
function wp_schedule_event() { return true; }
function wp_unschedule_event() { return true; }

class ACPS_Alerts_Settings {
	public static function get( $k, $d = null ) { return isset( $GLOBALS['settings'][ $k ] ) ? $GLOBALS['settings'][ $k ] : $d; }
}
class ACPS_Alerts_Failsafe {
	public static function action() {}
	public static function filter() {}
	public static function record() {}
	public static function log() {}
}
class ACPS_Alerts_Admin {
	public static function capability() { return 'edit_pages'; }
}
class ACPS_Alerts_Conditions {
	public static function passes_schedule( $a ) { return true; }
}
class ACPS_Alerts_Source {
	public static function get_enabled_alerts() { return array(); }
	public static function get_popups() { return array(); }
}
class ACPS_Alerts_Alert {
	const META_PREFIX = '_acps_alert_';
	private $data;
	private $id;
	public function __construct( $data = array(), $id = 1 ) {
		// post_entry() constructs this with just an id.
		if ( is_int( $data ) ) { $id = $data; $data = array(); }
		$this->data = $data; $this->id = $id;
	}
	public function get( $k, $d = null ) {
		if ( array_key_exists( $k, $this->data ) ) { return $this->data[ $k ]; }
		// Fall back to whatever post_entry() saved for this id, so an alert
		// looked up by id behaves like the real one.
		if ( isset( $GLOBALS['saved'][ $this->id ][ $k ] ) ) { return $GLOBALS['saved'][ $this->id ][ $k ]; }
		return $d;
	}
	public function get_title() { return isset( $GLOBALS['posts'][ $this->id ]['post_title'] ) ? $GLOBALS['posts'][ $this->id ]['post_title'] : ''; }
	public function get_id() { return $this->id; }
	public function is_valid() { return true; }
	public static function default_settings() { return array(); }
	public function save( $s ) { $GLOBALS['saved'][ $this->id ] = $s; return $s; }
}

class ACPS_Alerts_Post_Type { const SLUG = 'acps_alert'; }

require ACPS_ALERTS_DIR . 'includes/class-acps-alerts-status.php';

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

/* ---- the cut-off time is validated ---- */
check( 'defaults to 17:50', ACPS_Alerts_Status::cutoff_time(), '17:50' );

$GLOBALS['settings']['archive_time'] = '09:05';
check( 'a valid time is used', ACPS_Alerts_Status::cutoff_time(), '09:05' );

foreach ( array( '25:00', '17:60', 'nonsense', '', '5:5', '<script>' ) as $bad ) {
	$GLOBALS['settings']['archive_time'] = $bad;
	check( "a bad time falls back to the default: '$bad'", ACPS_Alerts_Status::cutoff_time(), '17:50' );
}

/* ---- deadline maths, UTC site ---- */
$GLOBALS['settings']['archive_time'] = '17:50';
$GLOBALS['gmt_offset'] = 0;

// Monday 2026-01-05, 09:00 UTC.
$morning = gmmktime( 9, 0, 0, 1, 5, 2026 );
check(
	'something posted in the morning comes down at 17:50 the same day',
	ACPS_Alerts_Status::deadline_for( $morning ),
	gmmktime( 17, 50, 0, 1, 5, 2026 )
);

// 17:49 — one minute before the cut-off, still today.
check(
	'one minute before the cut-off still comes down today',
	ACPS_Alerts_Status::deadline_for( gmmktime( 17, 49, 0, 1, 5, 2026 ) ),
	gmmktime( 17, 50, 0, 1, 5, 2026 )
);

// Exactly at the cut-off rolls to tomorrow, so posting at 17:50 is not
// instantly archived.
check(
	'posting exactly at the cut-off runs until tomorrow',
	ACPS_Alerts_Status::deadline_for( gmmktime( 17, 50, 0, 1, 5, 2026 ) ),
	gmmktime( 17, 50, 0, 1, 6, 2026 )
);

// Evening post rolls to the following day.
check(
	'an evening post runs until the following evening',
	ACPS_Alerts_Status::deadline_for( gmmktime( 21, 30, 0, 1, 5, 2026 ) ),
	gmmktime( 17, 50, 0, 1, 6, 2026 )
);

// Just before midnight still rolls to the next day, not two days on.
check(
	'just before midnight rolls only one day',
	ACPS_Alerts_Status::deadline_for( gmmktime( 23, 59, 0, 1, 5, 2026 ) ),
	gmmktime( 17, 50, 0, 1, 6, 2026 )
);

/* ---- deadline maths respects the site timezone ---- */
$GLOBALS['gmt_offset'] = -5; // US Eastern, standard time.

// 09:00 local on 2026-01-05 is 14:00 UTC. The cut-off is 17:50 LOCAL, which is
// 22:50 UTC the same day.
check(
	'the cut-off is 17:50 in site time, not UTC',
	ACPS_Alerts_Status::deadline_for( gmmktime( 14, 0, 0, 1, 5, 2026 ) ),
	gmmktime( 22, 50, 0, 1, 5, 2026 )
);

// 23:00 UTC is 18:00 local — past the local cut-off, so it rolls a day.
check(
	'past the local cut-off rolls to the next local day',
	ACPS_Alerts_Status::deadline_for( gmmktime( 23, 0, 0, 1, 5, 2026 ) ),
	gmmktime( 22, 50, 0, 1, 6, 2026 )
);

$GLOBALS['gmt_offset'] = 0;

/* ---- past_cutoff ---- */
$old = new ACPS_Alerts_Alert( array( 'expires_mode' => 'daily', 'posted_at' => time() - 3 * DAY_IN_SECONDS ) );
check( 'a three-day-old daily entry is past its cut-off', ACPS_Alerts_Status::past_cutoff( $old ), true );

$fresh = new ACPS_Alerts_Alert( array( 'expires_mode' => 'daily', 'posted_at' => time() ) );
check( 'an entry posted just now is not', ACPS_Alerts_Status::past_cutoff( $fresh ), false );

$kept = new ACPS_Alerts_Alert( array( 'expires_mode' => 'keep', 'posted_at' => time() - 30 * DAY_IN_SECONDS ) );
check( 'a "keep" entry never passes the cut-off', ACPS_Alerts_Status::past_cutoff( $kept ), false );

$custom = new ACPS_Alerts_Alert( array( 'expires_mode' => 'custom', 'posted_at' => time() - 30 * DAY_IN_SECONDS ) );
check( 'a "custom" entry is left to its own schedule', ACPS_Alerts_Status::past_cutoff( $custom ), false );

$undated = new ACPS_Alerts_Alert( array( 'expires_mode' => 'daily', 'posted_at' => 0 ) );
check( 'an entry with no posted time is not archived by guesswork', ACPS_Alerts_Status::past_cutoff( $undated ), false );

/* ---- is_current ---- */
$live = new ACPS_Alerts_Alert( array( 'enabled' => 1, 'archived' => 0, 'expires_mode' => 'daily', 'posted_at' => time() ) );
check( 'a fresh, enabled, unarchived entry is current', ACPS_Alerts_Status::is_current( $live ), true );

$off = new ACPS_Alerts_Alert( array( 'enabled' => 0, 'archived' => 0, 'expires_mode' => 'keep' ) );
check( 'a switched-off entry is not current', ACPS_Alerts_Status::is_current( $off ), false );

$archived = new ACPS_Alerts_Alert( array( 'enabled' => 1, 'archived' => 1, 'expires_mode' => 'keep' ) );
check( 'an archived entry is not current', ACPS_Alerts_Status::is_current( $archived ), false );

$stale = new ACPS_Alerts_Alert( array( 'enabled' => 1, 'archived' => 0, 'expires_mode' => 'daily', 'posted_at' => time() - 3 * DAY_IN_SECONDS ) );
check( 'an entry past its cut-off is not current, even if cron never ran', ACPS_Alerts_Status::is_current( $stale ), false );

/* ---- visibility: the admin-only view ---- */
$public = new ACPS_Alerts_Alert( array( 'visibility' => 'public' ) );
$staff  = new ACPS_Alerts_Alert( array( 'visibility' => 'admins' ) );
$hidden = new ACPS_Alerts_Alert( array( 'visibility' => 'preview' ) );

$GLOBALS['logged_in'] = false;
$GLOBALS['is_staff']  = false;
check( 'a visitor sees a public entry', ACPS_Alerts_Status::viewer_may_see( $public ), true );
check( 'a visitor does not see a staff-only entry', ACPS_Alerts_Status::viewer_may_see( $staff ), false );
check( 'a visitor does not see a hidden entry', ACPS_Alerts_Status::viewer_may_see( $hidden ), false );

// Logged in, but without the capability: still not staff.
$GLOBALS['logged_in'] = true;
$GLOBALS['is_staff']  = false;
check( 'a logged-in subscriber does not see a staff-only entry', ACPS_Alerts_Status::viewer_may_see( $staff ), false );

$GLOBALS['is_staff'] = true;
check( 'staff see a staff-only entry', ACPS_Alerts_Status::viewer_may_see( $staff ), true );
check( 'even staff do not see a hidden entry on the board', ACPS_Alerts_Status::viewer_may_see( $hidden ), false );
check( 'staff still see public entries', ACPS_Alerts_Status::viewer_may_see( $public ), true );

/* ---- levels ---- */
$levels = ACPS_Alerts_Status::levels();
ok( 'there are levels', ! empty( $levels ) );

foreach ( $levels as $key => $level ) {
	foreach ( array( 'label', 'banner', 'severity', 'rank' ) as $field ) {
		ok( "level '$key' has '$field'", array_key_exists( $field, $level ) );
	}
	ok(
		"level '$key' maps to a real severity",
		in_array( $level['severity'], array( 'info', 'success', 'warning', 'critical' ), true )
	);
}

/* ---- SRP: the five response actions ---- */
foreach ( array( 'hold', 'secure', 'shelter', 'evacuate', 'lockdown' ) as $action ) {
	$l = ACPS_Alerts_Status::level( $action );
	ok( "'$action' is marked as an SRP action", ! empty( $l['srp'] ) );
	ok( "'$action' carries the directive staff are trained on", '' !== $l['directive'] );
	ok( "'$action' has its own colour", (bool) preg_match( '/^#[0-9a-f]{6}$/i', $l['color'] ) );
}

check( 'HOLD reads as the drill does', ACPS_Alerts_Status::level( 'hold' )['banner'], 'HOLD' );
check( 'and carries its directive', ACPS_Alerts_Status::level( 'hold' )['directive'], 'In Your Classroom or Area' );
check( 'LOCKDOWN carries its directive', ACPS_Alerts_Status::level( 'lockdown' )['directive'], 'Locks, Lights, Out of Sight' );
check( 'SECURE carries its directive', ACPS_Alerts_Status::level( 'secure' )['directive'], 'Get Inside. Lock Outside Doors' );
check( 'EVACUATE carries its directive', ACPS_Alerts_Status::level( 'evacuate' )['directive'], 'To a Location' );

// Normal and Information are deliberately NOT response actions: calling them
// SRP would water the protocol down.
ok( 'normal is not an SRP action', empty( ACPS_Alerts_Status::level( 'normal' )['srp'] ) );
ok( 'information is not an SRP action', empty( ACPS_Alerts_Status::level( 'info' )['srp'] ) );

/* ---- ranking decides which update takes the banner ---- */
ok( 'lockdown outranks evacuate', ACPS_Alerts_Status::level( 'lockdown' )['rank'] > ACPS_Alerts_Status::level( 'evacuate' )['rank'] );
ok( 'evacuate outranks shelter', ACPS_Alerts_Status::level( 'evacuate' )['rank'] > ACPS_Alerts_Status::level( 'shelter' )['rank'] );
ok( 'shelter outranks secure', ACPS_Alerts_Status::level( 'shelter' )['rank'] > ACPS_Alerts_Status::level( 'secure' )['rank'] );
ok( 'secure outranks hold', ACPS_Alerts_Status::level( 'secure' )['rank'] > ACPS_Alerts_Status::level( 'hold' )['rank'] );
ok( 'every action outranks information', ACPS_Alerts_Status::level( 'hold' )['rank'] > ACPS_Alerts_Status::level( 'info' )['rank'] );

/* ---- fallbacks and the picker ---- */
check( 'an unknown level falls back to information', ACPS_Alerts_Status::level( 'nonsense' ), ACPS_Alerts_Status::level( 'info' ) );

$choices = ACPS_Alerts_Status::level_choices();
ok( 'the picker offers every SRP action', 5 === count( array_intersect( array_keys( $choices ), array( 'hold', 'secure', 'shelter', 'evacuate', 'lockdown' ) ) ) );
ok( 'the picker hides retired wording', ! isset( $choices['advisory'] ) && ! isset( $choices['closure'] ) );
ok( 'an SRP choice shows its directive in the label', false !== strpos( $choices['lockdown'], 'Locks, Lights, Out of Sight' ) );

/* ---- entries written before the move to SRP still render ---- */
foreach ( array( 'advisory', 'warning', 'closure', 'emergency' ) as $old ) {
	$l = ACPS_Alerts_Status::level( $old );
	ok( "the retired level '$old' still resolves", '' !== $l['banner'] );
	ok( "the retired level '$old' is flagged legacy", ! empty( $l['legacy'] ) );
}

check( 'every level key is saveable', count( ACPS_Alerts_Status::level_keys() ), count( $levels ) );

/* ---- premade archive entries (backfilling past events) ---- */
$GLOBALS['gmt_offset'] = 0;
$GLOBALS['saved'] = array();

check( 'a bare date reads as midday, so no timezone shift moves the day',
	gmdate( 'Y-m-d H:i', ACPS_Alerts_Status::parse_date( '2026-09-04' ) ), '2026-09-04 12:00' );
check( 'a full datetime is read as given',
	gmdate( 'Y-m-d H:i', ACPS_Alerts_Status::parse_date( '2026-09-04 08:30' ) ), '2026-09-04 08:30' );
check( 'an empty date reads as 0', ACPS_Alerts_Status::parse_date( '' ), 0 );
check( 'nonsense reads as 0', ACPS_Alerts_Status::parse_date( 'not a date' ), 0 );

// Backfill a past event.
$id = ACPS_Alerts_Status::post_entry( array(
	'title'    => 'Phishing campaign identified & contained',
	'level'    => 'advisory',
	'message'  => 'Handled.',
	'archived' => true,
	'date'     => '2026-09-04',
	'as_popup' => true,
) );

ok( 'a backfilled entry is created', $id > 0 );
$e = $GLOBALS['saved'][ $id ];
check( 'it is archived on arrival', $e['archived'], 1 );
check( 'it never pops up, even if asked', $e['as_popup'], 0 );
check( 'it keeps the date it happened',
	gmdate( 'Y-m-d', $e['posted_at'] ), '2026-09-04' );
check( 'the post itself is dated to match',
	substr( $GLOBALS['posts'][ $id ]['post_date_gmt'], 0, 10 ), '2026-09-04' );

// A live entry is unaffected.
$live_id = ACPS_Alerts_Status::post_entry( array(
	'title' => 'Snow day', 'level' => 'closure', 'as_popup' => true,
) );
$l = $GLOBALS['saved'][ $live_id ];
check( 'a live entry is not archived', $l['archived'], 0 );
check( 'a live entry may pop up', $l['as_popup'], 1 );
check( 'a live entry is dated now', abs( $l['posted_at'] - time() ) < 5, true );
ok( 'a live entry does not force a post date', ! isset( $GLOBALS['posts'][ $live_id ]['post_date_gmt'] ) );

// An archived entry is never "current", so it cannot reach the banner.
$backfilled = new ACPS_Alerts_Alert( array(
	'enabled' => 1, 'archived' => 1, 'expires_mode' => 'keep', 'posted_at' => ACPS_Alerts_Status::parse_date( '2026-09-04' ),
) );
check( 'a backfilled entry is never current', ACPS_Alerts_Status::is_current( $backfilled ), false );

// Ordering: the archive sorts by the date it happened, not when it was typed.
$older = new ACPS_Alerts_Alert( array( 'posted_at' => ACPS_Alerts_Status::parse_date( '2025-01-01' ) ) );
$newer = new ACPS_Alerts_Alert( array( 'posted_at' => ACPS_Alerts_Status::parse_date( '2026-09-04' ) ) );
ok( 'a later event sorts above an earlier one',
	ACPS_Alerts_Status::posted_time( $newer ) > ACPS_Alerts_Status::posted_time( $older ) );

/* ---- saving the page again must not post the update again ---- */
$GLOBALS['options'] = array();
$GLOBALS['saved']   = array();

$compose = array(
	'title'   => 'Snow Day',
	'level'   => 'closure',
	'message' => 'All schools closed.',
	'expires' => 'daily',
);

check( 'nothing posted yet, so the first save goes ahead',
	ACPS_Alerts_Status::already_posted( 'node1', $compose, false ), false );

$first = ACPS_Alerts_Status::post_entry( $compose );
ACPS_Alerts_Status::remember_posted( 'node1', $compose, $first );

check( 'saving the page again does NOT post it again',
	ACPS_Alerts_Status::already_posted( 'node1', $compose, false ), true );

// Twelve more saves of an unchanged page.
$posted_again = 0;
for ( $i = 0; $i < 12; $i++ ) {
	if ( ! ACPS_Alerts_Status::already_posted( 'node1', $compose, false ) ) {
		$posted_again++;
	}
}
check( 'twelve repeat saves post nothing', $posted_again, 0 );

// Changing any field makes it a new update.
$changed = array_merge( $compose, array( 'message' => 'Now reopening.' ) );
check( 'changing the message posts a new update',
	ACPS_Alerts_Status::already_posted( 'node1', $changed, false ), false );

$changed_title = array_merge( $compose, array( 'title' => 'Snow Day 2' ) );
check( 'changing the headline posts a new update',
	ACPS_Alerts_Status::already_posted( 'node1', $changed_title, false ), false );

$changed_level = array_merge( $compose, array( 'level' => 'warning' ) );
check( 'changing the level posts a new update',
	ACPS_Alerts_Status::already_posted( 'node1', $changed_level, false ), false );

// A different board is independent.
check( 'another board is not blocked by this one',
	ACPS_Alerts_Status::already_posted( 'node2', $compose, false ), false );

// Once the entry has come down, the same wording may be used again.
$GLOBALS['saved'][ $first ]['archived'] = 1;
check( 'the same wording may be posted again after it is archived',
	ACPS_Alerts_Status::already_posted( 'node1', $compose, false ), false );

// A backfilled record is never posted twice, archived or not.
$GLOBALS['options'] = array();
$back = array( 'title' => 'Phishing contained', 'level' => 'advisory', 'message' => 'Handled.', 'archived' => true, 'date' => '2026-09-04' );
$bid  = ACPS_Alerts_Status::post_entry( $back );
ACPS_Alerts_Status::remember_posted( 'node1', $back, $bid );
check( 'a backfilled record is never posted twice',
	ACPS_Alerts_Status::already_posted( 'node1', $back, true ), true );

// If the entry was deleted, the same update may be posted afresh.
$GLOBALS['options'] = array();
ACPS_Alerts_Status::remember_posted( 'node1', $compose, 99999 );
check( 'a deleted entry does not block a repost',
	ACPS_Alerts_Status::already_posted( 'node1', $compose, false ), false );

/* ---- editing the wording must correct the live update, not add another ---- */
//
// Reported: fixing a typo on the status board published a second entry each
// time, so one announcement became four near-identical ones.
$GLOBALS['options'] = array();
$GLOBALS['saved']   = array();
$GLOBALS['posts']   = array();

$before = count( $GLOBALS['posts'] );

$typed = array( 'title' => 'snow day huray', 'level' => 'closure', 'message' => 'Closed.', 'expires' => 'daily' );
$id1   = ACPS_Alerts_Status::post_entry( $typed );
ACPS_Alerts_Status::remember_posted( 'node1', $typed, $id1 );

check( 'the board is now driving that entry', ACPS_Alerts_Status::tracked_entry( 'node1' ), $id1 );

// Three typo fixes, exactly as reported.
foreach ( array( 'now day huray', 'lsnow day huray', 'snow day hooray' ) as $fixed ) {
	$typed['title'] = $fixed;
	$tracked = ACPS_Alerts_Status::tracked_entry( 'node1' );
	ok( 'the board still has a live entry to correct', $tracked > 0 );
	check( "rewriting to '$fixed' succeeds", ACPS_Alerts_Status::update_entry( $tracked, $typed ), true );
}

check( 'three wording fixes created no new entries', count( $GLOBALS['posts'] ), $before + 1 );
check( 'and the live entry now reads the corrected wording',
	$GLOBALS['posts'][ $id1 ]['post_title'], 'snow day hooray' );
check( 'it is still the same entry', ACPS_Alerts_Status::tracked_entry( 'node1' ), $id1 );

/* ---- an edit must not restart the daily cut-off ---- */
$posted_at_before = $GLOBALS['saved'][ $id1 ]['posted_at'];
ACPS_Alerts_Status::update_entry( $id1, $typed );
check( 'editing leaves the posted time alone, so the cut-off is not pushed back',
	isset( $GLOBALS['post_meta'][ $id1 ]['_acps_alert_posted_at'] ), false );
check( 'the original posted time is untouched', $GLOBALS['saved'][ $id1 ]['posted_at'], $posted_at_before );

/* ---- an edit does change what people read ---- */
$typed['level']   = 'lockdown';
$typed['message'] = 'Different now.';
ACPS_Alerts_Status::update_entry( $id1, $typed );
check( 'the level really changes', $GLOBALS['post_meta'][ $id1 ]['_acps_alert_status_level'], 'lockdown' );
check( 'the summary really changes', $GLOBALS['post_meta'][ $id1 ]['_acps_alert_status_message'], 'Different now.' );
check( 'the severity follows the level', $GLOBALS['post_meta'][ $id1 ]['_acps_alert_severity'], 'critical' );

/* ---- a genuinely new update is a separate entry ---- */
$new   = array( 'title' => 'All clear', 'level' => 'info', 'message' => 'Back to normal.' );
$count = count( $GLOBALS['posts'] );
$id2   = ACPS_Alerts_Status::post_entry( $new );
check( 'posting a new update adds one entry', count( $GLOBALS['posts'] ), $count + 1 );
ok( 'and it is a different entry', $id2 !== $id1 );

/* ---- update_entry refuses what it should ---- */
check( 'a missing entry is not updated', ACPS_Alerts_Status::update_entry( 987654, $typed ), false );
check( 'an empty headline is not written', ACPS_Alerts_Status::update_entry( $id1, array( 'title' => '   ' ) ), false );
check( 'a zero id is not written', ACPS_Alerts_Status::update_entry( 0, $typed ), false );

/* ---- once archived, the board has nothing to correct ---- */
$GLOBALS['saved'][ $id1 ]['archived'] = 1;
check( 'an archived entry is no longer tracked as live', ACPS_Alerts_Status::tracked_entry( 'node1' ), 0 );

echo $fails ? "\n$fails failing case(s)\n" : "All status cases passed\n";
exit( $fails ? 1 : 0 );
