<?php
/**
 * Person-row sanitizing, runnable without WordPress.
 *
 * This is the boundary where data written by someone else — another plugin,
 * another AI session — reaches a public page, so it is worth testing on its
 * own rather than trusting the provider to behave.
 *
 *   php wpsearch-quick-results/tests/people-test.php
 */

define( 'ABSPATH', __DIR__ );
define( 'WPSQR_DB_VERSION', 2 );

$GLOBALS['wpsqr_test_settings'] = array(
	'people_show_email' => 0,
	'people_show_phone' => 0,
);

function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function esc_url_raw( $url ) {
	$url = trim( (string) $url );
	// Mirrors the protocol allow-list: anything else becomes empty.
	return preg_match( '#^(https?:|/|\#)#i', $url ) ? $url : '';
}
function sanitize_email( $e ) { return filter_var( $e, FILTER_SANITIZE_EMAIL ); }
function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function apply_filters( $tag, $value ) { return $value; }
function has_filter( $tag ) { return false; }
function add_action() {}
function mb_strlen_shim( $s ) { return mb_strlen( $s ); }

class WPSQR_Plugin {
	public static function settings() { return $GLOBALS['wpsqr_test_settings']; }
}
class WPSQR_Normalizer {
	public static function normalize( $t ) { return strtolower( trim( $t ) ); }
	public static function viewer_bucket() { return 'visitor'; }
}

require_once __DIR__ . '/../includes/class-wpsqr-people.php';

$pass = 0;
$fail = 0;

function check( $name, $actual, $expected ) {
	global $pass, $fail;

	if ( $actual === $expected ) {
		$pass++;
		echo "  ok   {$name}\n";
	} else {
		$fail++;
		echo "  FAIL {$name}\n       expected " . var_export( $expected, true ) . "\n       actual   " . var_export( $actual, true ) . "\n";
	}
}

function n( $person ) { return WPSQR_People::normalize( $person ); }

echo "\nrequired fields\n";
check( 'a row without a name is dropped', n( array( 'url' => '/x/' ) ), null );
check( 'an empty name is dropped', n( array( 'name' => '   ' ) ), null );
check( 'a non-array is dropped', n( 'Aaron Kerr' ), null );
check( 'a name alone is enough', n( array( 'name' => 'Aaron Kerr' ) )['name'], 'Aaron Kerr' );

echo "\nmarkup from the provider never survives\n";
check( 'tags stripped from the name', n( array( 'name' => '<b>Aaron</b> Kerr' ) )['name'], 'Aaron Kerr' );
check( 'a script tag in the name is stripped',
	n( array( 'name' => 'Aaron<script>alert(1)</script>' ) )['name'], 'Aaronalert(1)' );
check( 'tags stripped from the job title',
	n( array( 'name' => 'A', 'job_title' => '<em>Driver</em>' ) )['job_title'], 'Driver' );
check( 'a javascript: url is rejected',
	n( array( 'name' => 'A', 'url' => 'javascript:alert(1)' ) )['url'], '' );
check( 'a data: photo url is rejected',
	n( array( 'name' => 'A', 'photo' => 'data:text/html;base64,xxx' ) )['photo'], '' );
check( 'a normal url survives',
	n( array( 'name' => 'A', 'url' => 'https://example.org/staff/a/' ) )['url'], 'https://example.org/staff/a/' );
check( 'a relative url survives',
	n( array( 'name' => 'A', 'url' => '/staff/a/' ) )['url'], '/staff/a/' );

echo "\ncontact details are opt-in\n";
check( 'email withheld by default',
	n( array( 'name' => 'A', 'email' => 'a@acpsmd.org' ) )['email'], '' );
check( 'phone withheld by default',
	n( array( 'name' => 'A', 'phone' => '301-555-0100' ) )['phone'], '' );

$GLOBALS['wpsqr_test_settings']['people_show_email'] = 1;
$GLOBALS['wpsqr_test_settings']['people_show_phone'] = 1;

check( 'email shown once enabled',
	n( array( 'name' => 'A', 'email' => 'a@acpsmd.org' ) )['email'], 'a@acpsmd.org' );
check( 'an invalid email is still withheld',
	n( array( 'name' => 'A', 'email' => 'not-an-email' ) )['email'], '' );
check( 'phone shown once enabled',
	n( array( 'name' => 'A', 'phone' => '301-555-0100' ) )['phone'], '301-555-0100' );

$GLOBALS['wpsqr_test_settings']['people_show_email'] = 0;
$GLOBALS['wpsqr_test_settings']['people_show_phone'] = 0;

echo "\nunknown keys are discarded\n";
$row = n( array( 'name' => 'A', 'salary' => '90000', 'ssn' => '000-00-0000' ) );
check( 'a field the contract does not define never appears',
	array_key_exists( 'salary', $row ) || array_key_exists( 'ssn', $row ), false );
check( 'the row has exactly the documented keys',
	array_keys( $row ),
	array( 'id', 'name', 'url', 'job_title', 'department', 'location', 'photo', 'email', 'phone' ) );

echo "\nids are opaque strings\n";
// Reported by the directory implementer: their records are identified as
// "WP-1-SD-1-E", not by post ID. Casting to int made every id 0, which
// collapsed every result into one during de-duplication.
check( 'a non-numeric id survives intact',
	n( array( 'name' => 'Aaron Kerr', 'id' => 'WP-1-SD-1-E' ) )['id'], 'WP-1-SD-1-E' );
check( 'a numeric id is kept as a string, not zeroed',
	n( array( 'name' => 'Aaron Kerr', 'id' => 482 ) )['id'], '482' );
check( 'a missing id is empty, not 0',
	n( array( 'name' => 'Aaron Kerr' ) )['id'], '' );
check( 'markup in an id is stripped',
	n( array( 'name' => 'A', 'id' => '<b>X-1</b>' ) )['id'], 'X-1' );
check( 'two people with different string ids both survive',
	count( WPSQR_People::normalize_all( array(
		array( 'name' => 'Aaron Kerr', 'id' => 'WP-1-SD-1-E' ),
		array( 'name' => 'Mary Sibley', 'id' => 'WP-1-SD-2-E' ),
	), 10 ) ), 2 );
check( 'the same id twice is one person',
	count( WPSQR_People::normalize_all( array(
		array( 'name' => 'Aaron Kerr', 'id' => 'WP-1-SD-1-E', 'url' => '/a/' ),
		array( 'name' => 'Aaron Kerr', 'id' => 'WP-1-SD-1-E', 'url' => '/b/' ),
	), 10 ) ), 1 );
check( 'id wins over url for de-duplication',
	count( WPSQR_People::normalize_all( array(
		array( 'name' => 'Aaron Kerr', 'id' => 'A', 'url' => '/same/' ),
		array( 'name' => 'Aaron Kerr', 'id' => 'B', 'url' => '/same/' ),
	), 10 ) ), 2 );

echo "\nlists\n";
$many = array();
for ( $i = 0; $i < 20; $i++ ) {
	$many[] = array( 'name' => 'Person ' . $i, 'url' => '/p/' . $i . '/' );
}
check( 'the limit is enforced even if the provider ignores it',
	count( WPSQR_People::normalize_all( $many, 5 ) ), 5 );
check( 'duplicates by url are collapsed',
	count( WPSQR_People::normalize_all( array(
		array( 'name' => 'Aaron Kerr', 'url' => '/staff/kerr/' ),
		array( 'name' => 'Aaron Kerr', 'url' => '/staff/kerr/' ),
	), 10 ) ), 1 );
check( 'duplicates by name are collapsed when there is no url',
	count( WPSQR_People::normalize_all( array(
		array( 'name' => 'Aaron Kerr' ),
		array( 'name' => 'aaron kerr' ),
	), 10 ) ), 1 );
check( 'two different people both survive',
	count( WPSQR_People::normalize_all( array(
		array( 'name' => 'Aaron Kerr', 'url' => '/staff/kerr/' ),
		array( 'name' => 'Mary Sibley', 'url' => '/staff/sibley/' ),
	), 10 ) ), 2 );
check( 'provider order is preserved',
	WPSQR_People::normalize_all( array(
		array( 'name' => 'Zoe Adams' ),
		array( 'name' => 'Aaron Kerr' ),
	), 10 )[0]['name'], 'Zoe Adams' );
check( 'a bad row in the middle does not lose the good ones',
	count( WPSQR_People::normalize_all( array(
		array( 'name' => 'Aaron Kerr' ),
		array( 'nope' => true ),
		array( 'name' => 'Mary Sibley' ),
	), 10 ) ), 2 );
check( 'a non-array response yields nothing', WPSQR_People::normalize_all( 'nope', 10 ), array() );

echo "\n{$pass} passed, {$fail} failed\n\n";
exit( $fail ? 1 : 0 );
