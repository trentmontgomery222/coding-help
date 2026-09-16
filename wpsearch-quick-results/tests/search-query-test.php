<?php
/**
 * Tokenizing and boolean-expression building, without WordPress.
 *
 * These decide what the engine actually asks the database, and the boolean
 * operators are the part where a careless search term becomes query syntax —
 * a search for "c++" or "-19" must be text, not a directive. Worth testing on
 * its own.
 *
 *   php wpsearch-quick-results/tests/search-query-test.php
 */

define( 'ABSPATH', __DIR__ );
define( 'WPSQR_DB_VERSION', 3 );

function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function apply_filters( $tag, $value ) { return $value; }
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, (array) $args ); }
function current_user_can( $cap ) { return false; }

require_once __DIR__ . '/../includes/class-wpsqr-normalizer.php';

// Only the pure half of WPSQR_Search is under test; the rest needs a database.
class WPSQR_Index { public static function has_fulltext() { return true; } }

eval(
	preg_replace(
		'/^<\?php/',
		'',
		preg_replace(
			'/\/\*\*.*?\*\//s',
			'',
			file_get_contents( __DIR__ . '/../includes/class-wpsqr-search.php' )
		)
	)
);

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

function tok( $s ) { return WPSQR_Search::tokenize( $s ); }
function expr( $s ) { return WPSQR_Search::boolean_expression( tok( $s )['tokens'] ); }

echo "\ntokenizing\n";
check( 'splits on whitespace', tok( 'staff directory' )['tokens'], array( 'staff', 'directory' ) );
check( 'lower-cases', tok( 'STAFF' )['tokens'], array( 'staff' ) );
check( 'drops duplicates', tok( 'staff staff' )['tokens'], array( 'staff' ) );
check( 'separates tokens too short to index', tok( 'ap us history' )['short'], array( 'ap', 'us' ) );
check( 'and keeps the long ones', tok( 'ap us history' )['tokens'], array( 'history' ) );
check( 'an all-short search still yields something', tok( 'ap us' )['short'], array( 'ap', 'us' ) );
check( 'empty in, empty out', tok( '' )['tokens'], array() );
check( 'punctuation alone yields nothing', tok( '!!!' )['tokens'], array() );

echo "\nboolean operators are never taken as syntax\n";
// Each of these would change the meaning of the query if passed through.
check( 'a leading minus cannot exclude', expr( '-19' ), '' );
check( 'covid-19 keeps both halves', expr( 'covid-19' ), '+covid19*' );
check( 'plus signs are stripped', expr( 'c++' ), '' );
check( 'quotes cannot open a phrase', expr( '"staff' ), '+staff*' );
check( 'asterisks cannot be injected', expr( 'staff*' ), '+staff*' );
check( 'parentheses cannot group', expr( '(staff)' ), '+staff*' );
check( 'a tilde cannot flip relevance', expr( '~staff' ), '+staff*' );
// The at sign splits the token rather than surviving inside it, so "2" is
// left too short to index and drops out.
check( 'an at sign cannot become a distance operator', expr( 'staff@2' ), '+staff*' );
// Tag-stripping runs first and eats "<directory" as an unclosed tag. Losing a
// word to that is acceptable; letting "<" reach MySQL as a weighting operator
// would not be.
check( 'angle brackets cannot weight', expr( '>staff <directory' ), '+staff*' );
check( 'an angle bracket with a space keeps the word', expr( '> staff > directory' ), '+staff* +directory*' );

echo "\nthe expression itself\n";
check( 'every token is required and allowed to prefix-match',
	expr( 'staff directory' ), '+staff* +directory*' );
check( 'one token', expr( 'transportation' ), '+transportation*' );
check( 'no tokens, no expression', expr( '' ), '' );
check( 'stopwords are dropped before querying', expr( 'the staff' ), '+staff*' );
check( 'a synonym is folded first', expr( 'employees' ), '+staff*' );

echo "\nprefix matching is what makes short searches work\n";
check( 'aust would match Austin', expr( 'aust' ), '+aust*' );
check( 'a three-character search survives', tok( 'ker' )['tokens'], array( 'ker' ) );
check( 'a two-character search goes to the fallback', tok( 'ke' )['tokens'], array() );

echo "\n{$pass} passed, {$fail} failed\n\n";
exit( $fail ? 1 : 0 );
