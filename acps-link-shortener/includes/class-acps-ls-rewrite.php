<?php
/**
 * Rewrite rule + query var registration.
 *
 * @package ACPS_Link_Shortener
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes /link/{slug} to the plugin instead of WP's normal page lookup.
 */
class ACPS_LS_Rewrite {

	/**
	 * Hook into WordPress.
	 */
	public function register() {
		add_action( 'init', array( $this, 'add_rewrite_rule' ) );
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
	}

	/**
	 * Add the rewrite rule.
	 *
	 * Matches /{prefix}/{slug} and optional trailing slash, capturing the slug
	 * into our custom query var. Rules are flushed only on activation.
	 */
	public function add_rewrite_rule() {
		$prefix = ACPS_LS_SLUG_PREFIX;

		add_rewrite_rule(
			'^' . $prefix . '/([^/]+)/?$',
			'index.php?' . ACPS_LS_QUERY_VAR . '=$matches[1]',
			'top'
		);
	}

	/**
	 * Register our query var so WP will populate it.
	 *
	 * @param array $vars Existing query vars.
	 * @return array
	 */
	public function add_query_var( $vars ) {
		$vars[] = ACPS_LS_QUERY_VAR;
		return $vars;
	}
}
