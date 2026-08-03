<?php
/**
 * Uninstall handler: remove all plugin data.
 *
 * @package TextTokens
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'tt_tokens' );
delete_option( 'tt_settings' );
delete_transient( 'tt_resolved_map' );
