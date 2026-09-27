<?php
/**
 * The [wpsqr_if] conditional shortcode.
 *
 * Verifies each condition (and its negation) gates the inner content, without
 * a WordPress runtime — the plugin class is loaded and the classes it consults
 * are stubbed so their state can be set per case.
 *
 *   php wpsearch-quick-results/tests/shortcode-if-test.php
 */

define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );

function esc_url_raw( $u ) { $u = trim( (string) $u ); return preg_match( '#^https?://#i', $u ) ? $u : ''; }
function sanitize_text_field( $s ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $s ) ) ); }
function apply_filters( $t, $v ) { return $v; }
function add_filter() {}
function add_shortcode() {}
function wp_parse_args( $a, $d ) { return array_merge( $d, (array) $a ); }
function get_option( $k, $d = false ) { return $GLOBALS['o'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['o'][ $k ] = $v; return true; }
function get_search_query() { return $GLOBALS['term'] ?? ''; }
function wp_unslash( $v ) { return $v; }
function shortcode_atts( $defaults, $atts ) { return array_merge( $defaults, (array) $atts ); }
function do_shortcode( $c ) { return $c; }

$GLOBALS['o']    = array();
$GLOBALS['term'] = '';

// Stubs the plugin consults. Real classes so class_exists() is true.
class WPSQR_Guard {
	public static $safe = false;
	public static function is_safe_mode() { return self::$safe; }
}
class WPSQR_Index {
	public static $rows = 0;
	public static function stats() { return array( 'rows' => self::$rows, 'expected' => 100, 'fulltext' => true ); }
}

$src = file_get_contents( __DIR__ . '/../includes/class-wpsqr-plugin.php' );
$src = preg_replace( '/^<\?php/', '', $src );
eval( $src );

define( 'WPSQR_VERSION', '3.1.0' );

$plugin = new WPSQR_Plugin();

$pass = 0; $fail = 0;
function check( $name, $actual, $expected ) {
	global $pass, $fail;
	if ( $actual === $expected ) { $pass++; echo "  ok   {$name}\n"; }
	else { $fail++; echo "  FAIL {$name}\n       expected " . var_export( $expected, true ) . "\n       actual   " . var_export( $actual, true ) . "\n"; }
}

/** Run the shortcode with a condition; return whether the inner content showed. */
function shows( $plugin, $condition ) {
	return 'YES' === $plugin->conditional_shortcode( array( 'condition' => $condition ), 'YES' );
}

// Engine is 'core' when the index is empty; 'builtin' once it has rows
// (SearchWP class is absent here, so 'auto' falls back to those).
$GLOBALS['o']['wpsqr_settings'] = array( 'engine_mode' => 'auto', 'people_enabled' => 1 );

echo "\nsafe mode gates enabled/disabled\n";
WPSQR_Guard::$safe = false;
check( 'enabled shows when running', shows( $plugin, 'enabled' ), true );
check( 'disabled hidden when running', shows( $plugin, 'disabled' ), false );
WPSQR_Guard::$safe = true;
check( 'disabled shows in safe mode', shows( $plugin, 'disabled' ), true );
check( 'safe_mode alias', shows( $plugin, 'safe_mode' ), true );
check( 'enabled hidden in safe mode', shows( $plugin, 'enabled' ), false );

echo "\nnegation\n";
WPSQR_Guard::$safe = false;
check( '!disabled shows when running', shows( $plugin, '!disabled' ), true );
check( '!enabled hidden when running', shows( $plugin, '!enabled' ), false );

echo "\nengine\n";
WPSQR_Index::$rows = 0;
check( 'core when index empty', shows( $plugin, 'core' ), true );
check( 'builtin hidden when index empty', shows( $plugin, 'builtin' ), false );
WPSQR_Index::$rows = 10;
check( 'builtin when index has rows', shows( $plugin, 'builtin' ), true );
check( 'indexed true with rows', shows( $plugin, 'indexed' ), true );

echo "\npeople and searching\n";
check( 'people on', shows( $plugin, 'people' ), true );
$GLOBALS['term'] = '';
check( 'searching false with no term', shows( $plugin, 'searching' ), false );
$GLOBALS['term'] = 'buses';
check( 'searching true with a term', shows( $plugin, 'searching' ), true );

echo "\nunknown and empty fail closed\n";
check( 'unknown condition shows nothing', shows( $plugin, 'totally_made_up' ), false );
check( 'empty condition shows nothing', shows( $plugin, '' ), false );

echo "\n{$pass} passed, {$fail} failed\n\n";
exit( $fail ? 1 : 0 );
