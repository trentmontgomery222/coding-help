<?php
/**
 * Public API — global helper functions for third-party plugins (spec Section 7).
 *
 * These proxy to the registry singleton. Call them from a callback hooked to the
 * `exp_register_extensions` action:
 *
 *     add_action( 'exp_register_extensions', function () {
 *         exp_register_menu_item( array(
 *             'slug'       => 'newsletter',
 *             'label'      => 'Newsletter',
 *             'capability' => 'manage_newsletter_signup',
 *             'render'     => 'my_newsletter_render',
 *         ) );
 *     } );
 *
 * See docs/EXTENSION-API.md for the full contract.
 *
 * @package ExternalPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register a portal dashboard menu item.
 *
 * @param array $args See EXP_Registry::register_menu_item().
 * @return bool|WP_Error
 */
function exp_register_menu_item( array $args ) {
	return EXP_Registry::instance()->register_menu_item( $args );
}

/**
 * Register a permission/capability key (auto-appears on the admin grants screen).
 *
 * @param array $args See EXP_Registry::register_capability().
 * @return bool|WP_Error
 */
function exp_register_capability( array $args ) {
	return EXP_Registry::instance()->register_capability( $args );
}

/**
 * Register a queue submission type for the shared review screen.
 *
 * @param array $args See EXP_Registry::register_queue_type().
 * @return bool|WP_Error
 */
function exp_register_queue_type( array $args ) {
	return EXP_Registry::instance()->register_queue_type( $args );
}

/**
 * Register an activity formatter for the "My Activity" view.
 *
 * @param string   $type      Queue type.
 * @param callable $formatter callable( $item ): string.
 * @return bool
 */
function exp_register_activity_formatter( $type, $formatter ) {
	return EXP_Registry::instance()->register_activity_formatter( $type, $formatter );
}

/**
 * Submit an item into the shared Content Update Queue.
 *
 * @param array $args type, submitted_by, content_ref, payload.
 * @return int|WP_Error Queue id or error.
 */
function exp_queue_submit( array $args ) {
	return EXP_Queue::submit( $args );
}

/**
 * The currently authenticated portal user (or false). Handy inside render/handle
 * callbacks, though callbacks are also passed a $ctx array with 'user'.
 *
 * @return object|false
 */
function exp_current_portal_user() {
	return EXP_Session::current_user();
}

/**
 * Whether the current request has an authenticated portal session.
 *
 * @return bool
 */
function exp_is_portal_authenticated() {
	return EXP_Session::is_authenticated();
}

/**
 * Check a portal permission.
 *
 * @param int    $user_id    Portal user id.
 * @param string $capability Capability key.
 * @param string $target     Target ('' for global).
 * @return bool
 */
function exp_user_can( $user_id, $capability, $target = '' ) {
	return EXP_Permissions::user_can( $user_id, $capability, $target );
}

/**
 * Print the hidden fields a module form needs (exp_action/exp_module/exp_csrf).
 * Use inside a third-party module's render callback.
 *
 * @param array $ctx Module context (expects 'slug' and 'csrf').
 * @return string
 */
function exp_module_form_fields( array $ctx ) {
	return EXP_UI::module_hidden_fields( $ctx );
}

/**
 * Escape+wrap a chunk of module HTML in the portal's accessible container.
 * Third-party render callbacks normally just return their body and the portal
 * wraps it, but this is exposed for advanced use.
 *
 * @param string $slug  Menu slug.
 * @param string $title Panel title.
 * @param string $html  Body HTML.
 * @return string
 */
function exp_wrap_module_output( $slug, $title, $html ) {
	return EXP_UI::wrap_module( $slug, $title, $html );
}
