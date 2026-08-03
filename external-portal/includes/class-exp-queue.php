<?php
/**
 * Content Update Queue (spec Section 4 table 5, Section 5, Section 6).
 *
 * One shared table for ALL submissions — core modules and third-party plugins.
 * Nothing here saves live; approval routes through an applier callback.
 *
 * @package ExternalPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Submission, review and application of queued changes.
 */
class EXP_Queue {

	const STATUS_PENDING  = 'pending';
	const STATUS_APPROVED = 'approved';
	const STATUS_REJECTED = 'rejected';

	/**
	 * Submit an item to the queue.
	 *
	 * @param array $args type (required), submitted_by (required), content_ref, payload (array).
	 * @return int|WP_Error New id or error.
	 */
	public static function submit( array $args ) {
		global $wpdb;

		$type         = isset( $args['type'] ) ? sanitize_key( $args['type'] ) : '';
		$submitted_by = isset( $args['submitted_by'] ) ? (int) $args['submitted_by'] : 0;
		if ( '' === $type || $submitted_by <= 0 ) {
			return new WP_Error( 'exp_queue_args', __( 'Invalid submission.', 'external-portal' ) );
		}

		$payload = isset( $args['payload'] ) ? $args['payload'] : array();
		$now     = EXP_Util::now();

		$ok = $wpdb->insert(
			EXP_Install::table( 'queue' ),
			array(
				'type'         => $type,
				'content_ref'  => isset( $args['content_ref'] ) ? substr( (string) $args['content_ref'], 0, 191 ) : '',
				'payload'      => wp_json_encode( $payload ),
				'submitted_by' => $submitted_by,
				'status'       => self::STATUS_PENDING,
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
		if ( ! $ok ) {
			return new WP_Error( 'exp_queue_db', __( 'Could not save your submission.', 'external-portal' ) );
		}

		$id = (int) $wpdb->insert_id;

		EXP_Audit::log(
			'queue.submitted',
			array(
				'actor_id'   => $submitted_by,
				'object_ref' => 'queue:' . $id,
				'detail'     => array( 'type' => $type ),
			)
		);
		EXP_Mailer::notify_new_queue_item( $id, $type );

		return $id;
	}

	/**
	 * Get one item, payload decoded.
	 *
	 * @param int $id Queue id.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = EXP_Install::table( 'queue' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( $row ) {
			$row->payload_data = json_decode( (string) $row->payload, true );
		}
		return $row;
	}

	/**
	 * Approve an item: run the type's applier, then mark approved.
	 *
	 * @param int    $id    Queue id.
	 * @param string $notes Admin notes.
	 * @return true|WP_Error
	 */
	public static function approve( $id, $notes = '' ) {
		$item = self::get( $id );
		if ( ! $item ) {
			return new WP_Error( 'exp_queue_missing', __( 'That item no longer exists.', 'external-portal' ) );
		}
		if ( self::STATUS_PENDING !== $item->status ) {
			return new WP_Error( 'exp_queue_state', __( 'That item has already been reviewed.', 'external-portal' ) );
		}

		$type_def = EXP_Registry::instance()->queue_type( $item->type );
		if ( $type_def && is_callable( $type_def['applier'] ) ) {
			$applied = call_user_func( $type_def['applier'], $item );
			if ( is_wp_error( $applied ) ) {
				return $applied;
			}
		}

		self::set_status( $id, self::STATUS_APPROVED, $notes );
		EXP_Audit::log(
			'queue.approved',
			array(
				'actor_type' => 'admin',
				'actor_id'   => get_current_user_id(),
				'object_ref' => 'queue:' . (int) $id,
				'detail'     => array( 'type' => $item->type ),
			)
		);
		return true;
	}

	/**
	 * Reject an item.
	 *
	 * @param int    $id    Queue id.
	 * @param string $notes Admin notes (shown to the submitter in My Activity).
	 * @return true|WP_Error
	 */
	public static function reject( $id, $notes = '' ) {
		$item = self::get( $id );
		if ( ! $item ) {
			return new WP_Error( 'exp_queue_missing', __( 'That item no longer exists.', 'external-portal' ) );
		}
		if ( self::STATUS_PENDING !== $item->status ) {
			return new WP_Error( 'exp_queue_state', __( 'That item has already been reviewed.', 'external-portal' ) );
		}
		self::set_status( $id, self::STATUS_REJECTED, $notes );
		EXP_Audit::log(
			'queue.rejected',
			array(
				'actor_type' => 'admin',
				'actor_id'   => get_current_user_id(),
				'object_ref' => 'queue:' . (int) $id,
				'detail'     => array( 'type' => $item->type ),
			)
		);
		return true;
	}

	/**
	 * Set status + notes + reviewer stamp.
	 *
	 * @param int    $id     Queue id.
	 * @param string $status New status.
	 * @param string $notes  Admin notes.
	 */
	protected static function set_status( $id, $status, $notes ) {
		global $wpdb;
		$wpdb->update(
			EXP_Install::table( 'queue' ),
			array(
				'status'      => $status,
				'admin_notes' => sanitize_textarea_field( $notes ),
				'reviewed_by' => get_current_user_id(),
				'reviewed_at' => EXP_Util::now(),
				'updated_at'  => EXP_Util::now(),
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Query the queue with filters + pagination (admin review + My Activity).
	 *
	 * @param array $args type, status, submitted_by, per_page, page.
	 * @return array{rows:array,total:int}
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$table = EXP_Install::table( 'queue' );

		$args = wp_parse_args(
			$args,
			array(
				'type'         => '',
				'status'       => '',
				'submitted_by' => 0,
				'per_page'     => 20,
				'page'         => 1,
			)
		);

		$where  = 'WHERE 1=1';
		$params = array();

		if ( '' !== $args['type'] ) {
			$where   .= ' AND type = %s';
			$params[] = sanitize_key( $args['type'] );
		}
		if ( '' !== $args['status'] ) {
			$where   .= ' AND status = %s';
			$params[] = $args['status'];
		}
		if ( (int) $args['submitted_by'] > 0 ) {
			$where   .= ' AND submitted_by = %d';
			$params[] = (int) $args['submitted_by'];
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

		if ( $rows ) {
			foreach ( $rows as $row ) {
				$row->payload_data = json_decode( (string) $row->payload, true );
			}
		}

		return array(
			'rows'  => $rows ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Count of pending items (for admin menu bubble).
	 *
	 * @return int
	 */
	public static function pending_count() {
		global $wpdb;
		$table = EXP_Install::table( 'queue' );
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", self::STATUS_PENDING ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
	}

	/**
	 * A human label for a submission type (registry label if known).
	 *
	 * @param string $type Type.
	 * @return string
	 */
	public static function type_label( $type ) {
		$def = EXP_Registry::instance()->queue_type( $type );
		return $def ? $def['label'] : ucwords( str_replace( array( '_', '-' ), ' ', $type ) );
	}
}
