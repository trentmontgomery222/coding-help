<?php
/**
 * The settings: feature switches, and each screen saving only its own half.
 *
 * Runs the real ACPS_Alerts_Settings against WordPress stubs.
 */

define( 'ABSPATH', '/tmp/wp/' );
define( 'ACPS_ALERTS_DIR', dirname( __DIR__ ) . '/acps-alert-popups/' );

$GLOBALS['options'] = array();

function get_option( $k, $d = false ) { return isset( $GLOBALS['options'][ $k ] ) ? $GLOBALS['options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
function wp_parse_args( $a, $d ) { return array_merge( $d, (array) $a ); }
function __( $s, $d = '' ) { return $s; }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_file_name( $s ) { return preg_replace( '/[^A-Za-z0-9._\-]/', '', (string) $s ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function esc_url_raw( $s ) { return (string) $s; }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function absint( $v ) { return abs( (int) $v ); }
function post_type_exists( $t ) { return 'acps_alert' === $t; }
function wp_hash_password( $p ) { return 'hashed:' . $p; }

require ACPS_ALERTS_DIR . 'includes/class-acps-alerts-settings.php';

$fails = 0;
function check( $label, $actual, $expected ) {
	global $fails;
	if ( $actual !== $expected ) {
		$fails++;
		printf( "FAIL %s: expected %s, got %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
	}
}

$features = array_keys( ACPS_Alerts_Settings::features() );

/* ---- every feature is on out of the box ---- */
check( 'there are feature switches', count( $features ) >= 8, true );

foreach ( $features as $key ) {
	check( "feature '$key' is on by default", ACPS_Alerts_Settings::feature( $key ), true );
	check( "feature '$key' has a label and a description", ! empty( ACPS_Alerts_Settings::features()[ $key ]['label'] ) && ! empty( ACPS_Alerts_Settings::features()[ $key ]['description'] ), true );
}

/* ---- the Settings screen: an unticked box switches its feature off ---- */
$posted = array( 'archive_time' => '16:00' );

foreach ( $features as $key ) {
	$posted[ 'feature_' . $key ] = '1';
}

$posted['feature_popup'] = '0'; // The hidden input posted for an unticked box.

ACPS_Alerts_Settings::save( $posted );

check( 'an unticked feature is saved off', ACPS_Alerts_Settings::feature( 'popup' ), false );
check( 'the others stay on', ACPS_Alerts_Settings::feature( 'board' ), true );

// A form or request that leaves a feature out entirely keeps it on: nothing is
// switched off by accident.
ACPS_Alerts_Settings::save( array( 'archive_time' => '16:00' ) );
check( 'a feature missing from the request is left on', ACPS_Alerts_Settings::feature( 'popup' ), true );

/* ---- saving the hidden maintenance screen keeps the ordinary settings ---- */
$GLOBALS['options'] = array();

ACPS_Alerts_Settings::save(
	array(
		'archive_time'    => '15:30',
		'render_mode'     => 'modal',
		'storage'         => 'session',
		'z_index'         => '5000',
		'hide_for_admins' => '1',
		'respect_preview' => '0',
		'custom_css'      => '.acps-board{color:red}',
		'feature_dots'    => '0',
	)
);

// The maintenance form posts only its own keys, plus its marker.
ACPS_Alerts_Settings::save(
	array(
		'_maintenance'   => '1',
		'update_enabled' => '1',
		'update_base'    => 'https://updates.example.org/',
	)
);

$after = ACPS_Alerts_Settings::all();

check( 'the Main CSS survives a maintenance save', $after['custom_css'], '.acps-board{color:red}' );
check( 'the cut-off survives', $after['archive_time'], '15:30' );
check( 'the rendering mode survives', $after['render_mode'], 'modal' );
check( 'dismissal storage survives', $after['storage'], 'session' );
check( 'z-index survives', $after['z_index'], 5000 );
check( 'hide-for-editors survives', $after['hide_for_admins'], 1 );
check( 'previews stay as set (off), not reset', $after['respect_preview'], 0 );
check( 'a switched-off feature stays off', ACPS_Alerts_Settings::feature( 'dots' ), false );
check( 'and the maintenance change itself is saved', $after['update_base'], 'https://updates.example.org/' );

// And the other way round: the ordinary screen never touches maintenance keys.
ACPS_Alerts_Settings::save( array( 'archive_time' => '14:00' ) );
check( 'an ordinary save keeps the maintenance settings', ACPS_Alerts_Settings::get( 'update_base' ), 'https://updates.example.org/' );

/* ---- every switch is actually enforced somewhere ---- */
// A switch nothing reads would be a lie on the Settings screen, and in the
// uninstall warning that sends people to it.
$code = '';
$it   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( ACPS_ALERTS_DIR, FilesystemIterator::SKIP_DOTS ) );

foreach ( $it as $file ) {
	$rel = substr( $file->getPathname(), strlen( ACPS_ALERTS_DIR ) );

	// The screens that only display or edit the switches do not count.
	if ( '.php' === substr( $rel, -4 ) && ! in_array( $rel, array( 'includes/class-acps-alerts-settings.php', 'includes/class-acps-alerts-admin.php', 'includes/class-acps-alerts-panel.php' ), true ) ) {
		$code .= file_get_contents( $file->getPathname() );
	}
}

foreach ( $features as $key ) {
	check( "feature '$key' is enforced in the code", false !== strpos( $code, "feature( '" . $key . "' )" ), true );
}

echo $fails ? "\n$fails failing case(s)\n" : "All settings cases passed\n";
exit( $fails ? 1 : 0 );
