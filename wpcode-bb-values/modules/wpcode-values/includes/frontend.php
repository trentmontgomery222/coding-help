<?php
/**
 * Beaver Builder includes this file directly to render the module, and
 * an include is compiled before a single line of it runs - so nothing
 * written inside a template can protect the page from a parse error in
 * that same template. A damaged render template would take down every
 * page using the module.
 *
 * So this file stays deliberately tiny and stable, and the actual
 * rendering - which is where the real code lives and where edits
 * happen - sits in frontend-render.php, loaded from here inside
 * try/catch. A parse error there is caught (ParseError is a Throwable
 * on PHP 7+), a missing file is skipped, and the page carries on.
 *
 * @var WPCodeBBV_Module $module
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

try {
	$wpcodebbv_render = __DIR__ . '/frontend-render.php';

	if ( file_exists( $wpcodebbv_render ) ) {
		include $wpcodebbv_render;
	}
} catch ( \Throwable $wpcodebbv_e ) {
	if ( function_exists( 'wpcodebbv_log' ) ) {
		wpcodebbv_log( 'render template failed: ' . $wpcodebbv_e->getMessage() );
	}
	// Visitors see nothing for this one module; the page is untouched.
}
