<?php
/**
 * Data access layer. Every query with a variable uses $wpdb->prepare().
 *
 * @package ACPS_Link_Shortener
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * All reads/writes for the global links table.
 */
class ACPS_LS_DB {

	/**
	 * Slugs that must never be used because they collide with real paths
	 * (Open Question #5). Filterable so operators can extend the list.
	 *
	 * @return string[]
	 */
	public static function reserved_slugs() {
		$reserved = array(
			'wp-admin',
			'wp-login',
			'wp-content',
			'wp-includes',
			'wp-json',
			'admin',
			'login',
			'feed',
			'sitemap',
			'sitemap_index',
			'robots',
			'index',
			'link', // the prefix itself
		);
		return apply_filters( 'acps_ls_reserved_slugs', $reserved );
	}

	/**
	 * Validate a slug. Returns true or a WP_Error.
	 *
	 * @param string $slug     Raw slug candidate (already sanitize_title'd).
	 * @param int    $ignore_id Row id to ignore when checking uniqueness (edits).
	 * @return true|WP_Error
	 */
	public static function validate_slug( $slug, $ignore_id = 0 ) {
		if ( '' === $slug ) {
			return new WP_Error( 'acps_ls_slug_empty', __( 'The slug cannot be empty.', 'acps-link-shortener' ) );
		}

		if ( strlen( $slug ) > 190 ) {
			return new WP_Error( 'acps_ls_slug_long', __( 'The slug is too long (max 190 characters).', 'acps-link-shortener' ) );
		}

		if ( in_array( $slug, self::reserved_slugs(), true ) ) {
			return new WP_Error( 'acps_ls_slug_reserved', __( 'That slug is reserved and cannot be used.', 'acps-link-shortener' ) );
		}

		if ( self::slug_exists( $slug, $ignore_id ) ) {
			return new WP_Error( 'acps_ls_slug_taken', __( 'That slug is already in use. Choose another.', 'acps-link-shortener' ) );
		}

		return true;
	}

	/**
	 * Validate a destination URL. Returns clean URL or WP_Error.
	 *
	 * @param string $raw Raw destination.
	 * @return string|WP_Error
	 */
	public static function validate_destination( $raw ) {
		$url = esc_url_raw( trim( $raw ), array( 'http', 'https' ) );

		if ( '' === $url ) {
			return new WP_Error( 'acps_ls_dest_empty', __( 'Enter a valid http or https destination URL.', 'acps-link-shortener' ) );
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( empty( $host ) ) {
			return new WP_Error( 'acps_ls_dest_invalid', __( 'The destination must be a well-formed absolute URL.', 'acps-link-shortener' ) );
		}

		return $url;
	}

	/**
	 * Generate a short, unique, non-reserved slug (for auto-named front-end links).
	 *
	 * @param int $length Number of characters.
	 * @return string
	 */
	public static function generate_unique_slug( $length = 6 ) {
		$reserved = self::reserved_slugs();
		$attempts = 0;

		do {
			$candidate = strtolower( wp_generate_password( $length, false, false ) );
			$candidate = sanitize_title( $candidate );
			$attempts++;
			if ( $attempts > 20 ) {
				// Extremely unlikely; widen to avoid an infinite loop.
				$candidate = 'l' . strtolower( wp_generate_password( 8, false, false ) );
				$candidate = sanitize_title( $candidate );
				break;
			}
		} while ( '' === $candidate || in_array( $candidate, $reserved, true ) || self::slug_exists( $candidate ) );

		return $candidate;
	}

	/**
	 * Whether a slug already exists (optionally ignoring one row).
	 *
	 * @param string $slug      Slug.
	 * @param int    $ignore_id Row id to ignore.
	 * @return bool
	 */
	public static function slug_exists( $slug, $ignore_id = 0 ) {
		global $wpdb;
		$table = acps_ls_table_name();

		$id = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
				"SELECT id FROM {$table} WHERE slug = %s AND id <> %d LIMIT 1",
				$slug,
				$ignore_id
			)
		);

		return $id > 0;
	}

	/**
	 * Fetch an active link by slug (used by the redirect handler).
	 *
	 * @param string $slug Slug.
	 * @return object|null
	 */
	public static function get_active_by_slug( $slug ) {
		global $wpdb;
		$table = acps_ls_table_name();

		return $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE slug = %s AND is_active = 1 LIMIT 1",
				$slug
			)
		);
	}

	/**
	 * Fetch any link by slug regardless of active state.
	 *
	 * @param string $slug Slug.
	 * @return object|null
	 */
	public static function get_by_slug( $slug ) {
		global $wpdb;
		$table = acps_ls_table_name();

		return $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE slug = %s LIMIT 1",
				$slug
			)
		);
	}

	/**
	 * Fetch a link by id.
	 *
	 * @param int $id Row id.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = acps_ls_table_name();

		return $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE id = %d LIMIT 1",
				(int) $id
			)
		);
	}

	/**
	 * Insert a new link.
	 *
	 * @param array $data {
	 *     @type string $slug          Validated slug.
	 *     @type string $destination   Validated URL.
	 *     @type string $title         Label.
	 *     @type int    $redirect_type 301 or 302.
	 *     @type int    $is_active     0/1.
	 *     @type string $source        'manual' or 'shortcode'.
	 *     @type string $creator_label Human label for who created it (front end).
	 * }
	 * @return int|WP_Error Inserted id or error.
	 */
	public static function create( $data ) {
		global $wpdb;
		$table = acps_ls_table_name();
		$now   = current_time( 'mysql' );

		$redirect_type = ( 302 === (int) $data['redirect_type'] ) ? 302 : 301;

		$result = $wpdb->insert(
			$table,
			array(
				'slug'          => $data['slug'],
				'destination'   => $data['destination'],
				'title'         => isset( $data['title'] ) ? $data['title'] : '',
				'redirect_type' => $redirect_type,
				'is_active'     => empty( $data['is_active'] ) ? 0 : 1,
				'source'        => isset( $data['source'] ) ? $data['source'] : 'manual',
				'creator_label' => isset( $data['creator_label'] ) ? $data['creator_label'] : '',
				'created_by'    => get_current_user_id(),
				'created_at'    => $now,
				'updated_at'    => $now,
			),
			array( '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( false === $result ) {
			return new WP_Error( 'acps_ls_insert_failed', __( 'Could not save the link.', 'acps-link-shortener' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update an existing link.
	 *
	 * @param int   $id   Row id.
	 * @param array $data Fields to change (slug, destination, title, redirect_type, is_active).
	 * @return bool|WP_Error
	 */
	public static function update( $id, $data ) {
		global $wpdb;
		$table = acps_ls_table_name();

		$fields  = array();
		$formats = array();

		if ( isset( $data['slug'] ) ) {
			$fields['slug'] = $data['slug'];
			$formats[]      = '%s';
		}
		if ( isset( $data['destination'] ) ) {
			$fields['destination'] = $data['destination'];
			$formats[]             = '%s';
		}
		if ( isset( $data['title'] ) ) {
			$fields['title'] = $data['title'];
			$formats[]       = '%s';
		}
		if ( isset( $data['redirect_type'] ) ) {
			$fields['redirect_type'] = ( 302 === (int) $data['redirect_type'] ) ? 302 : 301;
			$formats[]               = '%d';
		}
		if ( isset( $data['is_active'] ) ) {
			$fields['is_active'] = empty( $data['is_active'] ) ? 0 : 1;
			$formats[]           = '%d';
		}

		if ( empty( $fields ) ) {
			return true;
		}

		$fields['updated_at'] = current_time( 'mysql' );
		$formats[]            = '%s';

		$result = $wpdb->update( $table, $fields, array( 'id' => (int) $id ), $formats, array( '%d' ) );

		if ( false === $result ) {
			return new WP_Error( 'acps_ls_update_failed', __( 'Could not update the link.', 'acps-link-shortener' ) );
		}

		return true;
	}

	/**
	 * Delete a link.
	 *
	 * @param int $id Row id.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;
		$table = acps_ls_table_name();

		return (bool) $wpdb->delete( $table, array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Increment click counter and stamp last_clicked_at.
	 *
	 * @param int $id Row id.
	 */
	public static function increment_clicks( $id ) {
		global $wpdb;
		$table = acps_ls_table_name();
		$now   = current_time( 'mysql' );

		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$table} SET clicks = clicks + 1, last_clicked_at = %s WHERE id = %d",
				$now,
				(int) $id
			)
		);
	}

	/**
	 * List links with search + pagination for the admin table.
	 *
	 * @param array $args {
	 *     @type string $search   Search term (title/slug/destination).
	 *     @type int    $per_page Rows per page.
	 *     @type int    $paged    1-based page number.
	 *     @type string $orderby  Column.
	 *     @type string $order    ASC|DESC.
	 * }
	 * @return array { items: object[], total: int }
	 */
	public static function get_links( $args = array() ) {
		global $wpdb;
		$table = acps_ls_table_name();

		$defaults = array(
			'search'   => '',
			'per_page' => 20,
			'paged'    => 1,
			'orderby'  => 'created_at',
			'order'    => 'DESC',
		);
		$args = wp_parse_args( $args, $defaults );

		// Whitelist orderable columns to keep the ORDER BY clause safe.
		$allowed_orderby = array( 'id', 'slug', 'title', 'clicks', 'is_active', 'created_at', 'last_clicked_at' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
		$order           = ( 'ASC' === strtoupper( $args['order'] ) ) ? 'ASC' : 'DESC';

		$per_page = max( 1, (int) $args['per_page'] );
		$paged    = max( 1, (int) $args['paged'] );
		$offset   = ( $paged - 1 ) * $per_page;

		$where  = 'WHERE 1=1';
		$params = array();

		if ( '' !== $args['search'] ) {
			$like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where  .= ' AND ( slug LIKE %s OR title LIKE %s OR destination LIKE %s )';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		// Total count.
		if ( $params ) {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT COUNT(*) FROM {$table} {$where}",
					$params
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" );
		}

		// Page of rows. orderby/order are whitelisted above; limit/offset are ints.
		$query_params   = $params;
		$query_params[] = $per_page;
		$query_params[] = $offset;

		$items = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
				$query_params
			)
		);

		return array(
			'items' => $items ? $items : array(),
			'total' => $total,
		);
	}
}
