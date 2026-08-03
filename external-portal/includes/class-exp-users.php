<?php
/**
 * Portal user model (spec Section 4, table 1).
 *
 * Portal users are NOT WordPress users. They never touch wp_users and never
 * receive a WP role or capability.
 *
 * @package ExternalPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD for portal users.
 */
class EXP_Users {

	const STATUS_ACTIVE   = 'active';
	const STATUS_INVITED  = 'invited';
	const STATUS_DISABLED = 'disabled';

	const AUTH_OTP           = 'otp';           // OTP only.
	const AUTH_PASSWORD_OTP  = 'password_otp';  // Password with OTP fallback.

	/**
	 * Get a user row by id.
	 *
	 * @param int $id User id.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = EXP_Install::table( 'users' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Get a user row by email (case-insensitive).
	 *
	 * @param string $email Email.
	 * @return object|null
	 */
	public static function get_by_email( $email ) {
		global $wpdb;
		$table = EXP_Install::table( 'users' );
		$email = sanitize_email( $email );
		if ( ! $email ) {
			return null;
		}
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s", $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Create a portal account.
	 *
	 * @param array $args email (required), display_name, status, auth_mode.
	 * @return int|WP_Error New user id or error.
	 */
	public static function create( array $args ) {
		global $wpdb;

		$email = isset( $args['email'] ) ? sanitize_email( $args['email'] ) : '';
		if ( ! $email || ! is_email( $email ) ) {
			return new WP_Error( 'exp_invalid_email', __( 'A valid email address is required.', 'external-portal' ) );
		}
		if ( self::get_by_email( $email ) ) {
			return new WP_Error( 'exp_email_exists', __( 'A portal account with that email already exists.', 'external-portal' ) );
		}

		$now  = EXP_Util::now();
		$data = array(
			'email'        => $email,
			'display_name' => isset( $args['display_name'] ) ? sanitize_text_field( $args['display_name'] ) : '',
			'status'       => self::sanitize_status( $args['status'] ?? self::STATUS_INVITED ),
			'auth_mode'    => self::sanitize_auth_mode( $args['auth_mode'] ?? self::AUTH_OTP ),
			'created_at'   => $now,
			'updated_at'   => $now,
		);

		$ok = $wpdb->insert(
			EXP_Install::table( 'users' ),
			$data,
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( ! $ok ) {
			return new WP_Error( 'exp_db_error', __( 'Could not create the portal account.', 'external-portal' ) );
		}

		$id = (int) $wpdb->insert_id;
		EXP_Audit::log(
			'user.created',
			array(
				'actor_type' => 'admin',
				'actor_id'   => get_current_user_id(),
				'object_ref' => 'user:' . $id,
				'detail'     => array( 'email' => $email ),
			)
		);
		return $id;
	}

	/**
	 * Update mutable fields on a user.
	 *
	 * @param int   $id     User id.
	 * @param array $fields Whitelisted fields.
	 * @return bool
	 */
	public static function update( $id, array $fields ) {
		global $wpdb;

		$allowed = array();
		$formats = array();

		if ( isset( $fields['display_name'] ) ) {
			$allowed['display_name'] = sanitize_text_field( $fields['display_name'] );
			$formats[]               = '%s';
		}
		if ( isset( $fields['status'] ) ) {
			$allowed['status'] = self::sanitize_status( $fields['status'] );
			$formats[]         = '%s';
		}
		if ( isset( $fields['auth_mode'] ) ) {
			$allowed['auth_mode'] = self::sanitize_auth_mode( $fields['auth_mode'] );
			$formats[]            = '%s';
		}
		if ( array_key_exists( 'password_hash', $fields ) ) {
			$allowed['password_hash'] = $fields['password_hash']; // Already hashed or null.
			$formats[]                = '%s';
		}
		if ( array_key_exists( 'last_login_at', $fields ) ) {
			$allowed['last_login_at'] = $fields['last_login_at'];
			$formats[]                = '%s';
		}
		if ( array_key_exists( 'failed_logins', $fields ) ) {
			$allowed['failed_logins'] = (int) $fields['failed_logins'];
			$formats[]                = '%d';
		}
		if ( array_key_exists( 'locked_until', $fields ) ) {
			$allowed['locked_until'] = $fields['locked_until'];
			$formats[]               = '%s';
		}

		if ( empty( $allowed ) ) {
			return false;
		}

		$allowed['updated_at'] = EXP_Util::now();
		$formats[]             = '%s';

		return false !== $wpdb->update(
			EXP_Install::table( 'users' ),
			$allowed,
			array( 'id' => (int) $id ),
			$formats,
			array( '%d' )
		);
	}

	/**
	 * Set (or clear) a portal user's password. Passing an empty string clears it.
	 *
	 * @param int    $id       User id.
	 * @param string $password Plain password (validated by caller for policy).
	 * @return bool
	 */
	public static function set_password( $id, $password ) {
		$hash = '' === $password ? null : wp_hash_password( $password );
		return self::update( $id, array( 'password_hash' => $hash ) );
	}

	/**
	 * Delete a portal user and their dependent rows.
	 *
	 * @param int $id User id.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;
		$id = (int) $id;
		foreach ( array( 'otp', 'sessions', 'grants' ) as $key ) {
			$wpdb->delete( EXP_Install::table( $key ), array( 'user_id' => $id ), array( '%d' ) );
		}
		$deleted = $wpdb->delete( EXP_Install::table( 'users' ), array( 'id' => $id ), array( '%d' ) );
		EXP_Audit::log(
			'user.deleted',
			array(
				'actor_type' => 'admin',
				'actor_id'   => get_current_user_id(),
				'object_ref' => 'user:' . $id,
			)
		);
		return (bool) $deleted;
	}

	/**
	 * Whether the account can currently authenticate.
	 *
	 * @param object $user Row.
	 * @return bool
	 */
	public static function is_login_allowed( $user ) {
		if ( ! $user ) {
			return false;
		}
		if ( self::STATUS_DISABLED === $user->status ) {
			return false;
		}
		if ( ! empty( $user->locked_until ) && ! EXP_Util::is_past( $user->locked_until ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Paginated/filterable listing for the admin Users screen.
	 *
	 * @param array $args search, status, per_page, page, orderby, order.
	 * @return array{rows:array,total:int}
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$table = EXP_Install::table( 'users' );

		$args = wp_parse_args(
			$args,
			array(
				'search'   => '',
				'status'   => '',
				'per_page' => 20,
				'page'     => 1,
				'orderby'  => 'created_at',
				'order'    => 'DESC',
			)
		);

		$where  = 'WHERE 1=1';
		$params = array();

		if ( '' !== $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where   .= ' AND ( email LIKE %s OR display_name LIKE %s )';
			$params[] = $like;
			$params[] = $like;
		}
		if ( '' !== $args['status'] ) {
			$where   .= ' AND status = %s';
			$params[] = self::sanitize_status( $args['status'] );
		}

		$orderby  = in_array( $args['orderby'], array( 'created_at', 'email', 'display_name', 'last_login_at', 'status' ), true ) ? $args['orderby'] : 'created_at';
		$order    = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';
		$per_page = max( 1, (int) $args['per_page'] );
		$offset   = ( max( 1, (int) $args['page'] ) - 1 ) * $per_page;

		$total_sql = "SELECT COUNT(*) FROM {$table} {$where}";
		$total     = $params
			? (int) $wpdb->get_var( $wpdb->prepare( $total_sql, $params ) ) // phpcs:ignore WordPress.DB.PreparedSQL
			: (int) $wpdb->get_var( $total_sql ); // phpcs:ignore WordPress.DB

		$rows_sql          = "SELECT * FROM {$table} {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$params_with_limit = array_merge( $params, array( $per_page, $offset ) );
		$rows              = $wpdb->get_results( $wpdb->prepare( $rows_sql, $params_with_limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		return array(
			'rows'  => $rows ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Validate a status string.
	 *
	 * @param string $status Candidate.
	 * @return string
	 */
	public static function sanitize_status( $status ) {
		$valid = array( self::STATUS_ACTIVE, self::STATUS_INVITED, self::STATUS_DISABLED );
		return in_array( $status, $valid, true ) ? $status : self::STATUS_INVITED;
	}

	/**
	 * Validate an auth-mode string.
	 *
	 * @param string $mode Candidate.
	 * @return string
	 */
	public static function sanitize_auth_mode( $mode ) {
		$valid = array( self::AUTH_OTP, self::AUTH_PASSWORD_OTP );
		return in_array( $mode, $valid, true ) ? $mode : self::AUTH_OTP;
	}
}
