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
$GLOBALS['gmt_offset'] = 0;
$GLOBALS['is_staff']  = false;
$GLOBALS['logged_in'] = false;

function add_action() {}
function add_filter() {}
function apply_filters( $tag, $value ) { return $value; }
function do_action() {}
function __( $s, $d = '' ) { return $s; }
function get_option( $k, $d = false ) { return 'gmt_offset' === $k ? $GLOBALS['gmt_offset'] : $d; }
function is_user_logged_in() { return $GLOBALS['logged_in']; }
function current_user_can( $c ) { return $GLOBALS['is_staff']; }
function get_post_status( $id ) { return 'publish'; }
function get_post_time( $f, $gmt = false, $id = 0 ) { return 0; }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function wp_kses_post( $s ) { return $s; }
function update_post_meta() { return true; }
function wp_insert_post() { return 0; }
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
	public function __construct( $data = array(), $id = 1 ) { $this->data = $data; $this->id = $id; }
	public function get( $k, $d = null ) { return array_key_exists( $k, $this->data ) ? $this->data[ $k ] : $d; }
	public function get_id() { return $this->id; }
	public function is_valid() { return true; }
	public static function default_settings() { return array(); }
	public function save( $s ) { return $s; }
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

ok( 'closure outranks advisory', ACPS_Alerts_Status::level( 'closure' )['rank'] > ACPS_Alerts_Status::level( 'advisory' )['rank'] );
ok( 'emergency outranks closure', ACPS_Alerts_Status::level( 'emergency' )['rank'] > ACPS_Alerts_Status::level( 'closure' )['rank'] );
check( 'an unknown level falls back to advisory', ACPS_Alerts_Status::level( 'nonsense' ), ACPS_Alerts_Status::level( 'advisory' ) );
check( 'level choices cover every level', count( ACPS_Alerts_Status::level_choices() ), count( $levels ) );

echo $fails ? "\n$fails failing case(s)\n" : "All status cases passed\n";
exit( $fails ? 1 : 0 );
