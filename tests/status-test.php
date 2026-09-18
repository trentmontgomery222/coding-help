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
$GLOBALS['posts']     = array();
$GLOBALS['post_meta'] = array();

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
		// update_entry() constructs this with just an id.
		if ( is_int( $data ) ) { $id = $data; $data = array(); }
		$this->data = $data; $this->id = $id;
	}
	public function get( $k, $d = null ) {
		if ( array_key_exists( $k, $this->data ) ) { return $this->data[ $k ]; }
		// Fall back to whatever update_entry() saved for this id, so an alert
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

class ACPS_Alerts_Post_Type {
	const SLUG        = 'acps_alert';
	const ROLE_META   = '_acps_alert_role';
	const ROLE_NORMAL = 'normal';
	const ROLE_CURRENT = 'current';
	public static function roles() { return array( 'normal' => 'Normal Alert', 'current' => 'Current Alert' ); }
	public static function get_alert( $role ) { return 'current' === $role ? 50 : 51; }
}

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

/* ---- the two fixed alerts ---- */
check( 'there are exactly two alert roles', count( ACPS_Alerts_Post_Type::roles() ), 2 );
ok( 'one of them is the Current Alert', array_key_exists( 'current', ACPS_Alerts_Post_Type::roles() ) );
ok( 'the other is the Normal Alert', array_key_exists( 'normal', ACPS_Alerts_Post_Type::roles() ) );

/* ---- the archive is records, not posts ---- */
$GLOBALS['options'] = array();

check( 'the archive starts empty', ACPS_Alerts_Status::archive(), array() );

$id1 = ACPS_Alerts_Status::add_archive_record( array(
	'title' => 'Phishing campaign contained', 'level' => 'info', 'message' => 'Handled.', 'date' => '2026-09-04',
) );
$id2 = ACPS_Alerts_Status::add_archive_record( array(
	'title' => 'Snow day', 'level' => 'closure', 'message' => 'Closed.', 'date' => '2026-01-05',
) );

ok( 'a record is filed', '' !== $id1 );
check( 'both records are in the archive', count( ACPS_Alerts_Status::archive( 50 ) ), 2 );

$archive = ACPS_Alerts_Status::archive( 50 );
check( 'the archive is newest first', $archive[0]['title'], 'Phishing campaign contained' );
check( 'a record keeps the date it happened', gmdate( 'Y-m-d', $archive[0]['date'] ), '2026-09-04' );

check( 'a record with no headline is refused', ACPS_Alerts_Status::add_archive_record( array( 'title' => '  ' ) ), '' );
check( 'the archive respects its limit', count( ACPS_Alerts_Status::archive( 1 ) ), 1 );

check( 'a record can be removed', ACPS_Alerts_Status::delete_archive_record( $id2 ), true );
check( 'and is gone', count( ACPS_Alerts_Status::archive( 50 ) ), 1 );
check( 'removing an unknown record reports nothing removed', ACPS_Alerts_Status::delete_archive_record( 'nope' ), false );

// No post was created for any of that: archive records are not posts.
check( 'filing archive records creates no posts', count( $GLOBALS['posts'] ), 0 );

/* ---- editing the Current Alert never creates another ---- */
$GLOBALS['posts']   = array( 50 => array( 'post_title' => 'Current Alert' ) );
$GLOBALS['saved']   = array( 50 => array( 'enabled' => 1, 'expires_mode' => 'daily', 'posted_at' => time(), 'archived' => 0 ) );
$GLOBALS['options'] = array();

foreach ( array( 'now day huray', 'lsnow day huray', 'snow day hooray' ) as $fixed ) {
	check( "rewriting to '$fixed' succeeds", ACPS_Alerts_Status::update_entry( 50, array(
		'title' => $fixed, 'level' => 'closure', 'message' => 'Closed.',
	) ), true );
}

check( 'three wording fixes created no new posts', count( $GLOBALS['posts'] ), 1 );
check( 'the alert reads the corrected wording', $GLOBALS['posts'][50]['post_title'], 'snow day hooray' );

/* ---- the daily sweep files it and switches it off, never deletes it ---- */
$GLOBALS['saved'][50]['posted_at'] = time() - 3 * DAY_IN_SECONDS;
$GLOBALS['archived_ids'] = array();

check( 'the sweep archives the stale alert', ACPS_Alerts_Status::run_daily_archive(), 1 );
check( 'a record was filed for it', count( ACPS_Alerts_Status::archive( 50 ) ), 1 );
check( 'the alert itself still exists', isset( $GLOBALS['posts'][50] ), true );
check( 'and it was switched off', $GLOBALS['post_meta'][50]['_acps_alert_enabled'], 0 );
check( 'its wording was left alone for next time', $GLOBALS['posts'][50]['post_title'], 'snow day hooray' );

// Running again does nothing: it is already off.
$GLOBALS['saved'][50]['enabled'] = 0;
check( 'a second sweep archives nothing', ACPS_Alerts_Status::run_daily_archive(), 0 );

/* ---- "keep" opts out of the sweep ---- */
$GLOBALS['saved'][50] = array( 'enabled' => 1, 'expires_mode' => 'keep', 'posted_at' => time() - 30 * DAY_IN_SECONDS, 'archived' => 0 );
check( 'a kept alert is never swept', ACPS_Alerts_Status::run_daily_archive(), 0 );

echo $fails ? "\n$fails failing case(s)\n" : "All status cases passed\n";
exit( $fails ? 1 : 0 );
