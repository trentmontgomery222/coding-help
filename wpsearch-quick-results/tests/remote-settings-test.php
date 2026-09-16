<?php
/**
 * The generic settings coercion the remote editor relies on.
 *
 * The remote page can now change every setting, so the type-based sanitizer
 * is what stands between a pasted value and a corrupt option. Tested without
 * WordPress.
 *
 *   php wpsearch-quick-results/tests/remote-settings-test.php
 */

define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );

function esc_url_raw( $u ) { $u = trim( (string) $u ); return preg_match( '#^https?://#i', $u ) ? $u : ''; }
function sanitize_text_field( $s ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $s ) ) ); }
function apply_filters( $t, $v ) { return $v; }
function wp_parse_args( $a, $d ) { return array_merge( $d, (array) $a ); }
function get_option( $k, $d = false ) { return $GLOBALS['o'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['o'][ $k ] = $v; return true; }
function current_user_can( $c ) { return false; }
function class_exists_stub() {}

$GLOBALS['o'] = array();

// Load just enough of the plugin class: it references other classes only
// inside methods we do not call here.
$src = file_get_contents( __DIR__ . '/../includes/class-wpsqr-plugin.php' );
$src = preg_replace( '/^<\?php/', '', $src );
eval( $src );

$pass = 0; $fail = 0;
function check( $name, $actual, $expected ) {
	global $pass, $fail;
	if ( $actual === $expected ) { $pass++; echo "  ok   {$name}\n"; }
	else { $fail++; echo "  FAIL {$name}\n       expected " . var_export( $expected, true ) . "\n       actual   " . var_export( $actual, true ) . "\n"; }
}

function co( $key, $raw, $current ) { return WPSQR_Plugin::coerce( $key, $raw, $current ); }

echo "\nbooleans\n";
check( 'checked box is 1', co( 'native_search', '1', 0 ), 1 );
check( 'absent box is 0', co( 'native_search', '', 1 ), 0 );
check( 'on is 1', co( 'warm_enabled', 'on', 0 ), 1 );

echo "\nintegers\n";
check( 'a number', co( 'per_page', '25', 20 ), 25 );
check( 'text becomes 0', co( 'per_page', 'lots', 20 ), 0 );
check( 'negatives are clamped', co( 'age_days', '-5', 730 ), 0 );

echo "\nenums reject anything off the list\n";
check( 'a valid engine', co( 'engine_mode', 'builtin', 'auto' ), 'builtin' );
check( 'an invalid engine keeps the current value', co( 'engine_mode', 'hackme', 'auto' ), 'auto' );
check( 'a valid age mode', co( 'age_mode', 'hide', 'off' ), 'hide' );
check( 'an invalid age mode is ignored', co( 'age_mode', 'nuke', 'off' ), 'off' );

echo "\nurls\n";
check( 'a real url', co( 'people_more_url', 'https://x.org/s/', '' ), 'https://x.org/s/' );
check( 'javascript: is rejected', co( 'people_more_url', 'javascript:alert(1)', '' ), '' );

echo "\nline lists\n";
check( 'lines split and trim', co( 'manual_ids', "12\n 34 \n\n56", array() ), array( '12', '34', '56' ) );
check( 'duplicates collapse', co( 'directory_pages', "/a/\n/a/", array() ), array( '/a/' ) );

echo "\njson keeps structure, rejects garbage\n";
check( 'valid json decodes',
	co( 'result_rules', '[{"when":"url","op":"contains","value":"/x/","then":"hide"}]', array() ),
	array( array( 'when' => 'url', 'op' => 'contains', 'value' => '/x/', 'then' => 'hide' ) ) );
check( 'broken json keeps the current value',
	co( 'result_rules', '[{bad', array( 'kept' => 1 ) ), array( 'kept' => 1 ) );
check( 'a json scalar is not accepted as an array',
	co( 'query_rules', '"nope"', array( 'x' ) ), array( 'x' ) );

echo "\ntext is stripped of markup\n";
check( 'tags gone', co( 'empty_message', 'No <b>results</b>', '' ), 'No results' );

echo "\napply_input only touches listed fields, reports changes\n";
$GLOBALS['o']['wpsqr_settings'] = array();
// seed a full settings blob via defaults
$before = WPSQR_Plugin::settings();
check( 'defaults present', isset( $before['engine_mode'] ), true );

echo "\n{$pass} passed, {$fail} failed\n\n";
exit( $fail ? 1 : 0 );
