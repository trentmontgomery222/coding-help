<?php
/**
 * Entries data layer: create/query submissions, their values, and notes.
 *
 * @package ACPS\SiteToolkit
 */

namespace ACPS\SiteToolkit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Entries.
 */
class Entries {

	/**
	 * Valid entry statuses. Feedback uses the workflow set; generic forms use
	 * the triage set. Both are stored in the same column.
	 */
	const STATUSES_FORM     = array( 'new', 'read', 'spam', 'trashed' );
	const STATUSES_FEEDBACK = array( 'new', 'in_progress', 'resolved', 'wont_fix', 'spam', 'trashed' );

	/** @var string[]|null Cached lowercase column list for the entries table. */
	private static $columns_cache = null;

	/** Option key holding the most recent save failure, for the admin notice. */
	const SAVE_ERROR_OPTION = 'acps_st_last_save_error';

	/**
	 * Insert an entry plus its values.
	 *
	 * This is the single most important write in the plugin, so it is built to
	 * NEVER fail silently. If the INSERT is rejected (e.g. the schema drifted and
	 * a column is missing), it repairs the schema on the spot and retries once,
	 * and if it still can't save it records the exact database error so an admin
	 * notice can surface it. A submission either becomes a row or leaves a trail.
	 *
	 * @param array $entry  Entry columns.
	 * @param array $values Map of field_key => value (scalar or array).
	 * @return int Entry id, or 0 if the row could not be saved.
	 */
	public static function create( $entry, $values ) {
		global $wpdb;
		$now = current_time( 'mysql' );

		$data = array(
			'form_id'            => absint( $entry['form_id'] ),
			'submitted_at'       => $now,
			'session_id'         => ! empty( $entry['session_id'] ) ? absint( $entry['session_id'] ) : null,
			'page_id'            => ! empty( $entry['page_id'] ) ? absint( $entry['page_id'] ) : null,
			'page_url'           => isset( $entry['page_url'] ) ? esc_url_raw( $entry['page_url'] ) : null,
			'status'             => isset( $entry['status'] ) ? sanitize_key( $entry['status'] ) : 'new',
			'user_id'            => get_current_user_id() ?: null,
			'ip_anon'            => Session::anonymize_ip( Session::client_ip() ),
			'user_agent_summary' => Session::user_agent_summary(),
		);
		if ( ! empty( $entry['visitor_uid'] ) ) {
			$data['visitor_uid'] = Visitors::sanitize( $entry['visitor_uid'] );
		}

		$entry_id = self::insert_entry_row( $data );

		// Self-heal: if the insert was rejected, the schema has probably drifted
		// (missing table/column). Rebuild it and try exactly once more.
		if ( ! $entry_id ) {
			$first_error = $wpdb->last_error;
			Schema::install();
			self::$columns_cache = null; // re-read columns after the repair.
			$entry_id = self::insert_entry_row( $data );

			if ( ! $entry_id ) {
				self::record_save_error( $wpdb->last_error ? $wpdb->last_error : ( $first_error ? $first_error : 'unknown database error inserting entry' ) );
				return 0;
			}
		}

		// Store each submitted value. A value that can't be written shouldn't lose
		// the whole entry, but it should still be recorded as a problem.
		$vtable       = Schema::table( 'entry_values' );
		$value_failed = false;
		foreach ( $values as $key => $value ) {
			if ( is_array( $value ) ) {
				$ok = $wpdb->insert( // phpcs:ignore WordPress.DB
					$vtable,
					array(
						'entry_id'         => $entry_id,
						'field_key'        => sanitize_key( $key ),
						'value'            => implode( ', ', array_map( 'strval', $value ) ),
						'value_serialized' => wp_json_encode( array_values( $value ) ),
					)
				);
			} else {
				$ok = $wpdb->insert( // phpcs:ignore WordPress.DB
					$vtable,
					array(
						'entry_id'  => $entry_id,
						'field_key' => sanitize_key( $key ),
						'value'     => (string) $value,
					)
				);
			}
			if ( false === $ok ) {
				$value_failed = true;
			}
		}

		if ( $value_failed ) {
			self::record_save_error( 'Entry #' . $entry_id . ' saved, but one or more field values did not: ' . ( $wpdb->last_error ? $wpdb->last_error : 'unknown error' ) );
		} else {
			// A submission just saved cleanly — clear any stale failure notice.
			self::clear_save_error();
		}

		return $entry_id;
	}

	/**
	 * Insert the entry row itself, writing only columns that currently exist so a
	 * not-yet-upgraded schema can never reject the whole INSERT on an unknown
	 * column. Returns the new id, or 0 if the database rejected the write.
	 *
	 * @param array $data Column => value map.
	 * @return int
	 */
	private static function insert_entry_row( $data ) {
		global $wpdb;
		$cols = self::existing_columns();
		if ( $cols ) {
			$data = array_intersect_key( $data, array_flip( $cols ) );
		}
		if ( empty( $data ) ) {
			return 0; // table has no readable columns — nothing to write.
		}
		$ok = $wpdb->insert( Schema::table( 'entries' ), $data ); // phpcs:ignore WordPress.DB
		return ( false === $ok ) ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Lowercase list of columns on the entries table (cached per request).
	 *
	 * @return string[]
	 */
	private static function existing_columns() {
		if ( null === self::$columns_cache ) {
			global $wpdb;
			$table = Schema::table( 'entries' );
			$found = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ); // phpcs:ignore WordPress.DB
			self::$columns_cache = is_array( $found ) ? array_map( 'strtolower', $found ) : array();
		}
		return self::$columns_cache;
	}

	/**
	 * Record the most recent save failure so an admin notice can surface it, and
	 * log it for good measure. Auto-cleared the next time a submission saves.
	 *
	 * @param string $message Database error / description.
	 */
	private static function record_save_error( $message ) {
		update_option(
			self::SAVE_ERROR_OPTION,
			array(
				'when'    => current_time( 'mysql' ),
				'message' => (string) $message,
			),
			false
		);
		error_log( '[Cayden Form Manager] Entry save problem: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
	}

	/**
	 * Clear the stored save-failure marker (called after a clean save).
	 */
	public static function clear_save_error() {
		if ( false !== get_option( self::SAVE_ERROR_OPTION, false ) ) {
			delete_option( self::SAVE_ERROR_OPTION );
		}
	}

	/**
	 * The most recent save failure, or null if the last save was clean.
	 *
	 * @return array|null [ when, message ]
	 */
	public static function last_save_error() {
		$e = get_option( self::SAVE_ERROR_OPTION, null );
		return is_array( $e ) ? $e : null;
	}

	/**
	 * Fetch a single entry with its values as a map.
	 *
	 * @param int $id Entry id.
	 * @return array|null [ entry => object, values => array ]
	 */
	public static function get( $id ) {
		global $wpdb;
		$entry = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . Schema::table( 'entries' ) . " WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB
		if ( ! $entry ) {
			return null;
		}
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT field_key, value, value_serialized FROM " . Schema::table( 'entry_values' ) . " WHERE entry_id = %d", $id ) ); // phpcs:ignore WordPress.DB
		$values = array();
		foreach ( $rows as $r ) {
			$values[ $r->field_key ] = ( null !== $r->value_serialized )
				? json_decode( $r->value_serialized, true )
				: $r->value;
		}
		return array( 'entry' => $entry, 'values' => $values );
	}

	/**
	 * Query entries with filters. Returns rows + total for pagination.
	 *
	 * @param array $args form_id, status, search, page_id, date_from, date_to,
	 *                    orderby, order, per_page, paged.
	 * @return array [ 'rows' => object[], 'total' => int ]
	 */
	public static function query( $args = array() ) {
		global $wpdb;
		$table = Schema::table( 'entries' );

		$defaults = array(
			'form_id'   => 0,
			'status'    => '',
			'page_id'   => 0,
			'visitor'   => '',
			'search'    => '',
			'date_from' => '',
			'date_to'   => '',
			'orderby'   => 'submitted_at',
			'order'     => 'DESC',
			'per_page'  => 25,
			'paged'     => 1,
		);
		$args = wp_parse_args( $args, $defaults );

		$where  = array( '1=1' );
		$params = array();

		if ( $args['form_id'] ) {
			$where[]  = 'form_id = %d';
			$params[] = absint( $args['form_id'] );
		}
		if ( $args['status'] ) {
			$where[]  = 'status = %s';
			$params[] = sanitize_key( $args['status'] );
		} else {
			// Default view hides trashed.
			$where[] = "status <> 'trashed'";
		}
		if ( $args['page_id'] ) {
			$where[]  = 'page_id = %d';
			$params[] = absint( $args['page_id'] );
		}
		if ( '' !== $args['visitor'] ) {
			$where[]  = 'visitor_uid = %s';
			$params[] = Visitors::sanitize( $args['visitor'] );
		}
		if ( $args['date_from'] ) {
			$where[]  = 'submitted_at >= %s';
			$params[] = $args['date_from'] . ' 00:00:00';
		}
		if ( $args['date_to'] ) {
			$where[]  = 'submitted_at <= %s';
			$params[] = $args['date_to'] . ' 23:59:59';
		}
		if ( '' !== $args['search'] ) {
			// Search across values, the visitor id, and the visitor's name.
			$vtable   = Schema::table( 'entry_values' );
			$vis      = Schema::table( 'visitors' );
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = "( id IN (SELECT entry_id FROM {$vtable} WHERE value LIKE %s) OR visitor_uid LIKE %s OR visitor_uid IN (SELECT uid FROM {$vis} WHERE name LIKE %s) )";
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = implode( ' AND ', $where );

		$orderby = in_array( $args['orderby'], array( 'submitted_at', 'status', 'id' ), true ) ? $args['orderby'] : 'submitted_at';
		$order   = ( 'ASC' === strtoupper( $args['order'] ) ) ? 'ASC' : 'DESC';

		$per_page = max( 1, (int) $args['per_page'] );
		$offset   = ( max( 1, (int) $args['paged'] ) - 1 ) * $per_page;

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql ); // phpcs:ignore WordPress.DB

		$list_sql   = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$list_params = array_merge( $params, array( $per_page, $offset ) );
		$rows        = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ) ); // phpcs:ignore WordPress.DB

		return array( 'rows' => $rows ?: array(), 'total' => $total );
	}

	/**
	 * Update an entry's status.
	 *
	 * @param int    $id     Entry id.
	 * @param string $status New status.
	 */
	public static function set_status( $id, $status ) {
		global $wpdb;
		$wpdb->update( Schema::table( 'entries' ), array( 'status' => sanitize_key( $status ) ), array( 'id' => absint( $id ) ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Assign an entry to a user (spec §5.6).
	 *
	 * @param int $id      Entry id.
	 * @param int $user_id User id (0 to unassign).
	 */
	public static function assign( $id, $user_id ) {
		global $wpdb;
		$wpdb->update( Schema::table( 'entries' ), array( 'assigned_to' => absint( $user_id ) ?: null ), array( 'id' => absint( $id ) ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Add an internal note (spec §3.7).
	 *
	 * @param int    $entry_id Entry id.
	 * @param string $note     Note text.
	 * @param int    $author   Author user id.
	 * @return int Note id.
	 */
	public static function add_note( $entry_id, $note, $author = 0 ) {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB
			Schema::table( 'entry_notes' ),
			array(
				'entry_id'   => absint( $entry_id ),
				'author_id'  => $author ?: get_current_user_id(),
				'created_at' => current_time( 'mysql' ),
				'note'       => wp_kses_post( $note ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Notes for an entry, oldest first.
	 *
	 * @param int $entry_id Entry id.
	 * @return object[]
	 */
	public static function notes( $entry_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM " . Schema::table( 'entry_notes' ) . " WHERE entry_id = %d ORDER BY created_at ASC", absint( $entry_id ) ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Count non-spam, non-trashed submissions for a form (for a total cap).
	 *
	 * @param int $form_id Form id.
	 * @return int
	 */
	public static function count_for_form( $form_id ) {
		global $wpdb;
		$t = Schema::table( 'entries' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE form_id = %d AND status NOT IN ('spam','trashed')", absint( $form_id ) ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Count submissions for a form from one device fingerprint (anonymized IP +
	 * browser summary), matching the spam rate-limiter's notion of "device".
	 *
	 * @param int    $form_id Form id.
	 * @param string $ip_anon Anonymized IP.
	 * @param string $ua      User-agent summary.
	 * @return int
	 */
	public static function count_by_fingerprint( $form_id, $ip_anon, $ua ) {
		global $wpdb;
		$t = Schema::table( 'entries' );
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$t} WHERE form_id = %d AND ip_anon = %s AND user_agent_summary = %s AND status NOT IN ('spam','trashed')",
				absint( $form_id ),
				(string) $ip_anon,
				(string) $ua
			)
		);
	}

	/**
	 * Permanently delete one entry and its values + notes.
	 *
	 * @param int $id Entry id.
	 */
	public static function delete( $id ) {
		global $wpdb;
		$id = absint( $id );
		if ( ! $id ) {
			return;
		}
		$wpdb->delete( Schema::table( 'entry_values' ), array( 'entry_id' => $id ) ); // phpcs:ignore WordPress.DB
		$wpdb->delete( Schema::table( 'entry_notes' ), array( 'entry_id' => $id ) ); // phpcs:ignore WordPress.DB
		$wpdb->delete( Schema::table( 'entries' ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Permanently delete many entries.
	 *
	 * @param int[] $ids Entry ids.
	 * @return int Number deleted.
	 */
	public static function bulk_delete( $ids ) {
		$ids = array_filter( array_map( 'absint', (array) $ids ) );
		foreach ( $ids as $id ) {
			self::delete( $id );
		}
		return count( $ids );
	}

	/**
	 * Count entries grouped by page for the analytics overlay (spec §6.4).
	 *
	 * @param array $args form_id, category filters.
	 * @return array Map of page_id => count.
	 */
	public static function counts_by_page( $args = array() ) {
		global $wpdb;
		$table = Schema::table( 'entries' );
		$rows  = $wpdb->get_results( "SELECT page_id, COUNT(*) AS c FROM {$table} WHERE status <> 'trashed' AND status <> 'spam' GROUP BY page_id" ); // phpcs:ignore WordPress.DB
		$out   = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r->page_id ] = (int) $r->c;
		}
		return $out;
	}

	/**
	 * Delete all entries (and children) tied to a session — GDPR erase.
	 *
	 * @param int $session_id Session id.
	 */
	public static function delete_by_session( $session_id ) {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM " . Schema::table( 'entries' ) . " WHERE session_id = %d", absint( $session_id ) ) ); // phpcs:ignore WordPress.DB
		if ( ! $ids ) {
			return;
		}
		$in = implode( ',', array_map( 'absint', $ids ) );
		$wpdb->query( "DELETE FROM " . Schema::table( 'entry_values' ) . " WHERE entry_id IN ({$in})" ); // phpcs:ignore WordPress.DB
		$wpdb->query( "DELETE FROM " . Schema::table( 'entry_notes' ) . " WHERE entry_id IN ({$in})" ); // phpcs:ignore WordPress.DB
		$wpdb->query( "DELETE FROM " . Schema::table( 'entries' ) . " WHERE id IN ({$in})" ); // phpcs:ignore WordPress.DB
	}
}
