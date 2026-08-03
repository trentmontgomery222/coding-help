<?php
/**
 * Permissions / grants (spec Section 4, table 4 + Section 7 dynamic caps).
 *
 * Nothing is granted by default. Every ability a portal user has is an explicit
 * (user, capability, target) row. The set of assignable capabilities is dynamic:
 * it is whatever the registry reports (core + third-party), so new capabilities
 * appear on the admin grants screen automatically — no hardcoded checkbox list.
 *
 * @package ExternalPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Grant storage and permission checks.
 */
class EXP_Permissions {

	/**
	 * Does a user hold a specific capability for a specific target?
	 *
	 * @param int    $user_id    Portal user id.
	 * @param string $capability Capability key.
	 * @param string $target     Target value ('' for global caps).
	 * @return bool
	 */
	public static function user_can( $user_id, $capability, $target = '' ) {
		global $wpdb;
		$table = EXP_Install::table( 'grants' );
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE user_id = %d AND capability = %s AND target = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
				(int) $user_id,
				$capability,
				(string) $target
			)
		);
		return (bool) $found;
	}

	/**
	 * Does a user hold a capability for ANY target? Useful to decide whether a
	 * whole module/menu item should be shown.
	 *
	 * @param int    $user_id    Portal user id.
	 * @param string $capability Capability key.
	 * @return bool
	 */
	public static function user_can_any( $user_id, $capability ) {
		global $wpdb;
		$table = EXP_Install::table( 'grants' );
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE user_id = %d AND capability = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
				(int) $user_id,
				$capability
			)
		);
		return (bool) $found;
	}

	/**
	 * All targets a user holds for a capability.
	 *
	 * @param int    $user_id    Portal user id.
	 * @param string $capability Capability key.
	 * @return string[]
	 */
	public static function targets_for( $user_id, $capability ) {
		global $wpdb;
		$table   = EXP_Install::table( 'grants' );
		$targets = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT target FROM {$table} WHERE user_id = %d AND capability = %s ORDER BY target ASC", // phpcs:ignore WordPress.DB.PreparedSQL
				(int) $user_id,
				$capability
			)
		);
		return $targets ? $targets : array();
	}

	/**
	 * All grants for a user.
	 *
	 * @param int $user_id Portal user id.
	 * @return array
	 */
	public static function grants_for_user( $user_id ) {
		global $wpdb;
		$table = EXP_Install::table( 'grants' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY capability, target", (int) $user_id ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
		return $rows ? $rows : array();
	}

	/**
	 * Grant a capability/target to a user (idempotent).
	 *
	 * @param int    $user_id    Portal user id.
	 * @param string $capability Capability key.
	 * @param string $target     Target ('' for global).
	 * @return bool
	 */
	public static function grant( $user_id, $capability, $target = '' ) {
		global $wpdb;

		// Only allow known capabilities (registry is the source of truth).
		if ( ! EXP_Registry::instance()->capability( $capability ) ) {
			return false;
		}

		$ok = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . EXP_Install::table( 'grants' ) . ' (user_id, capability, target, created_at) VALUES (%d, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL
				(int) $user_id,
				$capability,
				(string) $target,
				EXP_Util::now()
			)
		);

		if ( $ok ) {
			EXP_Audit::log(
				'permission.granted',
				array(
					'actor_type' => 'admin',
					'actor_id'   => get_current_user_id(),
					'object_ref' => 'user:' . (int) $user_id,
					'detail'     => array(
						'capability' => $capability,
						'target'     => $target,
					),
				)
			);
		}
		return (bool) $ok;
	}

	/**
	 * Revoke a specific grant.
	 *
	 * @param int    $user_id    Portal user id.
	 * @param string $capability Capability key.
	 * @param string $target     Target.
	 * @return bool
	 */
	public static function revoke( $user_id, $capability, $target = '' ) {
		global $wpdb;
		$deleted = $wpdb->delete(
			EXP_Install::table( 'grants' ),
			array(
				'user_id'    => (int) $user_id,
				'capability' => $capability,
				'target'     => (string) $target,
			),
			array( '%d', '%s', '%s' )
		);
		if ( $deleted ) {
			EXP_Audit::log(
				'permission.revoked',
				array(
					'actor_type' => 'admin',
					'actor_id'   => get_current_user_id(),
					'object_ref' => 'user:' . (int) $user_id,
					'detail'     => array(
						'capability' => $capability,
						'target'     => $target,
					),
				)
			);
		}
		return (bool) $deleted;
	}

	/**
	 * Revoke every grant of a capability across ALL users (admin bulk action).
	 *
	 * @param string $capability Capability key.
	 * @return int Rows removed.
	 */
	public static function revoke_capability_everywhere( $capability ) {
		global $wpdb;
		$removed = $wpdb->delete( EXP_Install::table( 'grants' ), array( 'capability' => $capability ), array( '%s' ) );
		EXP_Audit::log(
			'permission.bulk_revoked',
			array(
				'actor_type' => 'admin',
				'actor_id'   => get_current_user_id(),
				'detail'     => array( 'capability' => $capability ),
			)
		);
		return (int) $removed;
	}

	/**
	 * Replace a user's grants for a single capability with a new target set.
	 * Used by the admin Permissions form (submit all targets for a cap at once).
	 *
	 * @param int      $user_id    Portal user id.
	 * @param string   $capability Capability key.
	 * @param string[] $targets    Desired target values.
	 */
	public static function set_targets( $user_id, $capability, array $targets ) {
		$current = self::targets_for( $user_id, $capability );
		$targets = array_map( 'strval', $targets );

		foreach ( array_diff( $targets, $current ) as $add ) {
			self::grant( $user_id, $capability, $add );
		}
		foreach ( array_diff( $current, $targets ) as $remove ) {
			self::revoke( $user_id, $capability, $remove );
		}
	}

	/**
	 * Human label for a stored target value, resolved via the capability's type.
	 *
	 * @param array  $capability_def Capability definition.
	 * @param string $target         Stored target.
	 * @return string
	 */
	public static function target_label( array $capability_def, $target ) {
		if ( '' === $target ) {
			return __( '(all)', 'external-portal' );
		}
		switch ( $capability_def['target_type'] ) {
			case 'page':
				$title = get_the_title( (int) $target );
				return $title ? $title . ' (#' . (int) $target . ')' : '#' . (int) $target;
			case 'category':
				$term = get_term( (int) $target, 'category' );
				return ( $term && ! is_wp_error( $term ) ) ? $term->name : '#' . (int) $target;
			case 'calendar':
				foreach ( (array) EXP_Settings::get( 'google_calendar_whitelist', array() ) as $cal ) {
					if ( isset( $cal['id'] ) && $cal['id'] === $target ) {
						return ! empty( $cal['label'] ) ? $cal['label'] : $target;
					}
				}
				return $target;
			default:
				return $target;
		}
	}
}
