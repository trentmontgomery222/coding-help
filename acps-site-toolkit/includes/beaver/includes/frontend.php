<?php
/**
 * Beaver Builder front-end template for the ACPS Form module.
 *
 * Beaver Builder renders a module by including this file ({module dir}/
 * includes/frontend.php) — it does NOT call the module class's own methods.
 * Without this file the module outputs nothing on the page even though it can
 * be added and configured in the editor. BB makes the current module instance
 * available here as $module (and its settings as $settings).
 *
 * @package ACPS\SiteToolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( isset( $module ) && is_object( $module ) && method_exists( $module, 'render_form' ) ) {
	$module->render_form();
}
