<?php
/**
 * Normalizer tests, runnable without WordPress.
 *
 * The normalizer decides the cache hit rate, so it is worth testing on its
 * own. A handful of WordPress functions are shimmed below — only the few this
 * class actually touches.
 *
 *   php wpsearch-quick-results/tests/normalizer-test.php
 */

define( 'ABSPATH', __DIR__ );
define( 'WPSQR_DB_VERSION', 1 );

function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function apply_filters( $tag, $value ) { return $value; }
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, (array) $args ); }
function current_user_can( $cap ) { return false; }

require_once __DIR__ . '/../includes/class-wpsqr-normalizer.php';

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

function n( $s ) { return WPSQR_Normalizer::normalize( $s ); }

echo "\ncase and whitespace collapse to one entry\n";
check( 'lowercases', n( 'STAFF' ), 'staff' );
check( 'trims', n( '  staff  ' ), 'staff' );
check( 'collapses inner whitespace', n( "staff\t\n  directory" ), 'staff directory' );
check( 'non-breaking space is a space', n( "staff\xC2\xA0directory" ), 'staff directory' );

echo "\npunctuation\n";
check( 'trailing punctuation dropped', n( 'staff?' ), 'staff' );
check( 'surrounding quotes dropped', n( '"staff directory"' ), 'staff directory' );
check( 'curly quotes dropped', n( "\u{201C}staff\u{201D}" ), 'staff' );
check( 'curly apostrophe normalised to straight', n( "parent\u{2019}s guide" ), "parent's guide" );
check( 'em dash becomes a break', n( "staff \u{2014} directory" ), 'staff directory' );
check( 'inner hyphen kept', n( 'title-ix' ), 'title-ix' );
check( 'year range kept', n( '2026-2027 calendar' ), '2026-2027 calendar' );
check( 'leading hyphen dropped', n( '-staff' ), 'staff' );
check( 'apostrophe inside a word kept', n( "parent's guide" ), "parent's guide" );
check( 'ampersand becomes a break', n( 'safety & security' ), 'safety security' );
check( 'entities decoded first', n( 'safety &amp; security' ), 'safety security' );
check( 'tags stripped', n( '<b>staff</b>' ), 'staff' );

echo "\nstopwords\n";
check( 'leading article dropped', n( 'the staff' ), 'staff' );
check( 'preposition dropped', n( 'board of education' ), 'board education' );
check( 'a stopword-only search survives', n( 'the' ), 'the' );

echo "\nsynonyms\n";
check( 'plural folded', n( 'staffs' ), 'staff' );
check( 'employee folded to staff', n( 'employee' ), 'staff' );
check( 'enrollment folded', n( 'enrollment' ), 'enroll' );
check( 'policies folded', n( 'board policies' ), 'board policy' );

echo "\nempty and junk input\n";
check( 'empty string', n( '' ), '' );
check( 'whitespace only', n( "   \n " ), '' );
check( 'punctuation only', n( '!!!???' ), '' );

echo "\nequivalent searches share a key\n";
$variants = array( 'staff', 'STAFF', ' Staff ', 'the staff', 'staff!', 'Staffs' );
$keys     = array_map( function ( $v ) { return WPSQR_Normalizer::key( $v ); }, $variants );
check( 'six spellings, one cache entry', count( array_unique( $keys ) ), 1 );

check( 'genuinely different searches do not collide',
	WPSQR_Normalizer::key( 'staff' ) === WPSQR_Normalizer::key( 'staff directory' ), false );
check( 'page number changes the key',
	WPSQR_Normalizer::key( 'staff', array( 'page' => 1 ) ) === WPSQR_Normalizer::key( 'staff', array( 'page' => 2 ) ), false );
check( 'viewer bucket changes the key',
	WPSQR_Normalizer::key( 'staff', array( 'viewer' => 'visitor' ) ) === WPSQR_Normalizer::key( 'staff', array( 'viewer' => 'editor' ) ), false );

echo "\n{$pass} passed, {$fail} failed\n\n";
exit( $fail ? 1 : 0 );
