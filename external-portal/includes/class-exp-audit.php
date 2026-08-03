<?php
/**
 * Audit logging (spec Section 8, Q6).
 *
 * @package ExternalPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records security- and governance-relevant events.
 */
class EXP_Audit {

	/**
	 * Record an event.
	 *
	 * @param string $event      Short event slug, e.g. 'login.success'.
	 * @param array  $args       Optional: actor_type, actor_id, object_ref, detail.
	 */
	public static function log( $event, array $args = array() ) {
		global $wpdb;

		$defaults = array(
			'actor_type' => 'portal',
			'actor_id'   => 0,
			'object_ref' => '',
			'detail'     => '',
		);
		$args = wp_parse_args( $args, $defaults );

		if ( is_array( $args['detail'] ) ) {
			$args['detail'] = wp_json_encode( $args['detail'] );
		}

		$wpdb->insert(
			EXP_Install::table( 'audit' ),
			array(
				'actor_type' => substr( (string) $args['actor_type'], 0, 20 ),
				'actor_id'   => (int) $args['actor_id'],
				'event'      => substr( (string) $event, 0, 60 ),
				'object_ref' => substr( (string) $args['object_ref'], 0, 191 ),
				'detail'     => (string) $args['detail'],
				'ip'         => EXP_Util::client_ip(),
				'created_at' => EXP_Util::now(),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Fetch a page of audit rows with optional filters.
	 *
	 * @param array $args event, actor_id, per_page, page.
	 * @return array{rows:array,total:int}
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$table = EXP_Install::table( 'audit' );

		$args = wp_parse_args(
			$args,
			array(
				'event'    => '',
				'per_page' => 25,
				'page'     => 1,
			)
		);

		$where  = 'WHERE 1=1';
		$params = array();
		if ( '' !== $args['event'] ) {
			$where   .= ' AND event = %s';
			$params[] = $args['event'];
		}

		$per_page = max( 1, (int) $args['per_page'] );
		$offset   = ( max( 1, (int) $args['page'] ) - 1 ) * $per_page;

		$total_sql = "SELECT COUNT(*) FROM {$table} {$where}";
		$total     = $params
			? (int) $wpdb->get_var( $wpdb->prepare( $total_sql, $params ) ) // phpcs:ignore WordPress.DB.PreparedSQL
			: (int) $wpdb->get_var( $total_sql ); // phpcs:ignore WordPress.DB

		$rows_sql          = "SELECT * FROM {$table} {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
		$params_with_limit = array_merge( $params, array( $per_page, $offset ) );
		$rows              = $wpdb->get_results( $wpdb->prepare( $rows_sql, $params_with_limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		return array(
			'rows'  => $rows ? $rows : array(),
			'total' => $total,
		);
	}
}
