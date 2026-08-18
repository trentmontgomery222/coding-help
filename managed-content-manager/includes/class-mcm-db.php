<?php
/**
 * Database layer: table creation and all queries.
 *
 * All queries funnel through here and use $wpdb->prepare() so the rest of the
 * plugin never touches raw SQL.
 *
 * @package mcm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCM_DB {

	/** @return string blocks table name */
	public static function blocks_table() {
		global $wpdb;
		return $wpdb->prefix . 'mcm_blocks';
	}

	/** @return string editors table name */
	public static function editors_table() {
		global $wpdb;
		return $wpdb->prefix . 'mcm_editors';
	}

	/** @return string sessions table name */
	public static function sessions_table() {
		global $wpdb;
		return $wpdb->prefix . 'mcm_sessions';
	}

	/**
	 * Create/upgrade tables. Safe to call repeatedly (dbDelta).
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$blocks          = self::blocks_table();
		$editors         = self::editors_table();
		$sessions        = self::sessions_table();

		$sql_blocks = "CREATE TABLE {$blocks} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			slug VARCHAR(191) NOT NULL,
			label VARCHAR(255) NOT NULL DEFAULT '',
			type VARCHAR(20) NOT NULL DEFAULT 'text',
			source VARCHAR(20) NOT NULL DEFAULT 'custom',
			post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			node_id VARCHAR(191) NOT NULL DEFAULT '',
			field_key VARCHAR(191) NOT NULL DEFAULT '',
			content LONGTEXT NULL,
			max_length INT NOT NULL DEFAULT 0,
			updated_at DATETIME NULL,
			updated_by VARCHAR(191) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY source (source),
			KEY post_id (post_id)
		) {$charset_collate};";

		$sql_editors = "CREATE TABLE {$editors} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			username VARCHAR(60) NOT NULL,
			password_hash VARCHAR(255) NOT NULL,
			display_name VARCHAR(255) NOT NULL DEFAULT '',
			allowed_blocks TEXT NULL,
			allowed_pages TEXT NULL,
			active TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NULL,
			last_login DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY username (username)
		) {$charset_collate};";

		$sql_sessions = "CREATE TABLE {$sessions} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			editor_id BIGINT UNSIGNED NOT NULL,
			token_hash VARCHAR(255) NOT NULL,
			csrf VARCHAR(64) NOT NULL DEFAULT '',
			ip VARCHAR(45) NOT NULL DEFAULT '',
			expires_at DATETIME NULL,
			created_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY editor_id (editor_id),
			KEY token_hash (token_hash)
		) {$charset_collate};";

		dbDelta( $sql_blocks );
		dbDelta( $sql_editors );
		dbDelta( $sql_sessions );
	}

	// -----------------------------------------------------------------------
	// Blocks
	// -----------------------------------------------------------------------

	/** @return array of block rows */
	public static function get_blocks() {
		global $wpdb;
		$table = self::blocks_table();
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY label ASC, slug ASC" );
	}

	/**
	 * @param int $id
	 * @return object|null
	 */
	public static function get_block( $id ) {
		global $wpdb;
		$table = self::blocks_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
	}

	/**
	 * @param string $slug
	 * @return object|null
	 */
	public static function get_block_by_slug( $slug ) {
		global $wpdb;
		$table = self::blocks_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s", $slug ) );
	}

	/**
	 * @param int[] $ids
	 * @return array
	 */
	public static function get_blocks_by_ids( $ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
		if ( empty( $ids ) ) {
			return array();
		}
		$table        = self::blocks_table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built from int count.
		$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE id IN ({$placeholders}) ORDER BY label ASC", $ids );
		return $wpdb->get_results( $sql );
	}

	/**
	 * Insert or update a block.
	 *
	 * @param array $data  Fields: id, slug, label, type, content, max_length.
	 * @param string $editor_name Who saved (for the updated_by column).
	 * @return int|WP_Error block id
	 */
	public static function save_block( $data, $editor_name = '' ) {
		global $wpdb;
		$table = self::blocks_table();

		$id      = isset( $data['id'] ) ? absint( $data['id'] ) : 0;
		$row     = array(
			'slug'       => sanitize_title( $data['slug'] ),
			'label'      => sanitize_text_field( $data['label'] ),
			'type'       => in_array( $data['type'], array( 'text', 'textarea', 'richtext' ), true ) ? $data['type'] : 'text',
			'max_length' => isset( $data['max_length'] ) ? absint( $data['max_length'] ) : 0,
			'updated_at' => current_time( 'mysql' ),
			'updated_by' => sanitize_text_field( $editor_name ),
		);
		$formats = array( '%s', '%s', '%s', '%d', '%s', '%s' );

		if ( '' === $row['slug'] ) {
			return new WP_Error( 'mcm_slug', __( 'A slug is required.', 'mcm' ) );
		}

		// content is optional on a metadata update; only include when provided.
		if ( array_key_exists( 'content', $data ) ) {
			$row['content'] = self::sanitize_block_content( $row['type'], $data['content'], $row['max_length'] );
			$formats[]      = '%s';
		}

		// Enforce slug uniqueness (except self).
		$existing = self::get_block_by_slug( $row['slug'] );
		if ( $existing && (int) $existing->id !== $id ) {
			return new WP_Error( 'mcm_slug_dupe', __( 'That slug is already in use.', 'mcm' ) );
		}

		if ( $id ) {
			$wpdb->update( $table, $row, array( 'id' => $id ), $formats, array( '%d' ) );
			return $id;
		}

		$wpdb->insert( $table, $row, $formats );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Save only the content of a block (used by the editor portal).
	 *
	 * @param int    $id
	 * @param string $raw_content
	 * @param string $editor_name
	 * @return true|WP_Error
	 */
	public static function save_block_content( $id, $raw_content, $editor_name ) {
		global $wpdb;
		$block = self::get_block( $id );
		if ( ! $block ) {
			return new WP_Error( 'mcm_block_missing', __( 'That content block no longer exists.', 'mcm' ) );
		}
		$content = self::sanitize_block_content( $block->type, $raw_content, (int) $block->max_length );
		$wpdb->update(
			self::blocks_table(),
			array(
				'content'    => $content,
				'updated_at' => current_time( 'mysql' ),
				'updated_by' => sanitize_text_field( $editor_name ),
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
		return true;
	}

	/**
	 * @param int $id
	 */
	public static function delete_block( $id ) {
		global $wpdb;
		$wpdb->delete( self::blocks_table(), array( 'id' => absint( $id ) ), array( '%d' ) );
	}

	/**
	 * Create (or return existing) a block that points at a Beaver Builder
	 * module field. Content lives in Beaver Builder, not our table; the
	 * `content` column only caches the value for admin previews.
	 *
	 * @param array $data label, type, max_length, post_id, node_id, field_key, content
	 * @return int|WP_Error block id
	 */
	public static function save_beaver_block( $data ) {
		global $wpdb;

		$post_id   = absint( $data['post_id'] ?? 0 );
		$node_id   = sanitize_text_field( $data['node_id'] ?? '' );
		$field_key = sanitize_key( $data['field_key'] ?? '' );

		if ( ! $post_id || '' === $node_id || '' === $field_key ) {
			return new WP_Error( 'mcm_bb_ref', __( 'Missing Beaver Builder field reference.', 'mcm' ) );
		}

		// One block per (post, node, field) — reuse if it already exists.
		$existing = self::get_beaver_block( $post_id, $node_id, $field_key );
		if ( $existing ) {
			return (int) $existing->id;
		}

		$type = in_array( $data['type'] ?? 'text', array( 'text', 'textarea', 'richtext' ), true ) ? $data['type'] : 'text';

		// Auto slug, kept unique.
		$base = sanitize_title( 'bb-' . $node_id . '-' . $field_key );
		$slug = $base;
		$i    = 2;
		while ( self::get_block_by_slug( $slug ) ) {
			$slug = $base . '-' . $i;
			++$i;
		}

		$row = array(
			'slug'       => $slug,
			'label'      => sanitize_text_field( $data['label'] ?? '' ),
			'type'       => $type,
			'source'     => 'beaver',
			'post_id'    => $post_id,
			'node_id'    => $node_id,
			'field_key'  => $field_key,
			'content'    => isset( $data['content'] ) ? wp_kses_post( (string) $data['content'] ) : '',
			'max_length' => absint( $data['max_length'] ?? 0 ),
			'updated_at' => current_time( 'mysql' ),
			'updated_by' => sanitize_text_field( $data['updated_by'] ?? '' ),
		);
		$wpdb->insert(
			self::blocks_table(),
			$row,
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Create (or return existing) a block that edits an ENTIRE Beaver Builder
	 * module (every meaningful setting), not just one field. Stored with
	 * source 'beaver_module' and an empty field_key.
	 *
	 * @param array $data label, post_id, node_id, content(preview), updated_by
	 * @return int|WP_Error
	 */
	public static function save_beaver_module_block( $data ) {
		global $wpdb;

		$post_id = absint( $data['post_id'] ?? 0 );
		$node_id = sanitize_text_field( $data['node_id'] ?? '' );

		if ( ! $post_id || '' === $node_id ) {
			return new WP_Error( 'mcm_bb_ref', __( 'Missing Beaver Builder module reference.', 'mcm' ) );
		}

		$existing = self::get_beaver_module_block( $post_id, $node_id );
		if ( $existing ) {
			return (int) $existing->id;
		}

		$base = sanitize_title( 'bbmod-' . $node_id );
		$slug = $base;
		$i    = 2;
		while ( self::get_block_by_slug( $slug ) ) {
			$slug = $base . '-' . $i;
			++$i;
		}

		$row = array(
			'slug'       => $slug,
			'label'      => sanitize_text_field( $data['label'] ?? '' ),
			'type'       => 'module',
			'source'     => 'beaver_module',
			'post_id'    => $post_id,
			'node_id'    => $node_id,
			'field_key'  => '',
			'content'    => sanitize_text_field( $data['content'] ?? '' ),
			'max_length' => 0,
			'updated_at' => current_time( 'mysql' ),
			'updated_by' => sanitize_text_field( $data['updated_by'] ?? '' ),
		);
		$wpdb->insert(
			self::blocks_table(),
			$row,
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * @param int    $post_id
	 * @param string $node_id
	 * @return object|null
	 */
	public static function get_beaver_module_block( $post_id, $node_id ) {
		global $wpdb;
		$table = self::blocks_table();
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE source = 'beaver_module' AND post_id = %d AND node_id = %s",
				absint( $post_id ),
				$node_id
			)
		);
	}

	/**
	 * Find a Beaver block by its module-field reference.
	 *
	 * @param int    $post_id
	 * @param string $node_id
	 * @param string $field_key
	 * @return object|null
	 */
	public static function get_beaver_block( $post_id, $node_id, $field_key ) {
		global $wpdb;
		$table = self::blocks_table();
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE source = 'beaver' AND post_id = %d AND node_id = %s AND field_key = %s",
				absint( $post_id ),
				$node_id,
				$field_key
			)
		);
	}

	/**
	 * @param int    $post_id
	 * @return array beaver blocks for a given post
	 */
	public static function get_beaver_blocks_for_post( $post_id ) {
		global $wpdb;
		$table = self::blocks_table();
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE source IN ('beaver','beaver_module') AND post_id = %d ORDER BY label ASC", absint( $post_id ) )
		);
	}

	/**
	 * Update only the editable metadata of a block (label/type/max length).
	 * Used when editing a Beaver block, whose content + reference must not be
	 * rewritten here.
	 *
	 * @param int    $id
	 * @param string $label
	 * @param string $type
	 * @param int    $max_length
	 */
	public static function update_block_meta( $id, $label, $type, $max_length ) {
		global $wpdb;
		$type = in_array( $type, array( 'text', 'textarea', 'richtext' ), true ) ? $type : 'text';
		$wpdb->update(
			self::blocks_table(),
			array(
				'label'      => sanitize_text_field( $label ),
				'type'       => $type,
				'max_length' => absint( $max_length ),
			),
			array( 'id' => absint( $id ) ),
			array( '%s', '%s', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Refresh the cached preview value + timestamp for a block (used after a
	 * Beaver Builder field is saved through the portal).
	 *
	 * @param int    $id
	 * @param string $cached_value already-sanitized value
	 * @param string $editor_name
	 */
	public static function update_block_cache( $id, $cached_value, $editor_name ) {
		global $wpdb;
		$wpdb->update(
			self::blocks_table(),
			array(
				'content'    => (string) $cached_value,
				'updated_at' => current_time( 'mysql' ),
				'updated_by' => sanitize_text_field( $editor_name ),
			),
			array( 'id' => absint( $id ) ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Sanitize content according to the block's declared type. This is the
	 * heart of "they can only edit how and what we want".
	 *
	 * @param string $type       text|textarea|richtext
	 * @param string $raw
	 * @param int    $max_length 0 = unlimited
	 * @param string $rich_mode  'strict' (tiny allow-list, for custom blocks) or
	 *                           'post' (wp_kses_post, used for Beaver Builder
	 *                           rich-text so existing markup survives).
	 * @return string
	 */
	public static function sanitize_block_content( $type, $raw, $max_length = 0, $rich_mode = 'strict' ) {
		$raw = (string) $raw;

		switch ( $type ) {
			case 'richtext':
				if ( 'post' === $rich_mode ) {
					// Permissive but still safe (strips scripts/onclick/etc.).
					$clean = wp_kses_post( $raw );
					break;
				}
				// A deliberately small allow-list. No scripts, no styles,
				// no images, no arbitrary attributes.
				$allowed = array(
					'a'      => array(
						'href'  => true,
						'title' => true,
						'rel'   => true,
					),
					'strong' => array(),
					'b'      => array(),
					'em'     => array(),
					'i'      => array(),
					'u'      => array(),
					'br'     => array(),
					'p'      => array(),
					'ul'     => array(),
					'ol'     => array(),
					'li'     => array(),
					'h2'     => array(),
					'h3'     => array(),
				);
				$clean = wp_kses( $raw, $allowed );
				break;

			case 'textarea':
				$clean = sanitize_textarea_field( $raw );
				break;

			case 'text':
			default:
				$clean = sanitize_text_field( $raw );
				break;
		}

		if ( $max_length > 0 ) {
			// Count on the visible text for richtext so tags don't eat the budget.
			if ( 'richtext' === $type ) {
				if ( mb_strlen( wp_strip_all_tags( $clean ) ) > $max_length ) {
					// Truncate the plain text, then re-sanitize to keep valid markup.
					$plain = mb_substr( wp_strip_all_tags( $clean ), 0, $max_length );
					$clean = self::sanitize_block_content( 'richtext', $plain, 0 );
				}
			} elseif ( mb_strlen( $clean ) > $max_length ) {
				$clean = mb_substr( $clean, 0, $max_length );
			}
		}

		return $clean;
	}

	// -----------------------------------------------------------------------
	// Editors
	// -----------------------------------------------------------------------

	/** @return array */
	public static function get_editors() {
		global $wpdb;
		$table = self::editors_table();
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY display_name ASC, username ASC" );
	}

	/**
	 * @param int $id
	 * @return object|null
	 */
	public static function get_editor( $id ) {
		global $wpdb;
		$table = self::editors_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
	}

	/**
	 * @param string $username
	 * @return object|null
	 */
	public static function get_editor_by_username( $username ) {
		global $wpdb;
		$table = self::editors_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE username = %s", $username ) );
	}

	/**
	 * Insert or update an editor account.
	 *
	 * @param array $data id, username, display_name, password (plain, optional), allowed_blocks[], active
	 * @return int|WP_Error
	 */
	public static function save_editor( $data ) {
		global $wpdb;
		$table = self::editors_table();

		$id       = isset( $data['id'] ) ? absint( $data['id'] ) : 0;
		$username = sanitize_user( $data['username'], true );

		if ( '' === $username ) {
			return new WP_Error( 'mcm_username', __( 'A username is required.', 'mcm' ) );
		}

		$existing = self::get_editor_by_username( $username );
		if ( $existing && (int) $existing->id !== $id ) {
			return new WP_Error( 'mcm_username_dupe', __( 'That username is already taken.', 'mcm' ) );
		}

		$allowed = array_values( array_filter( array_map( 'absint', (array) ( $data['allowed_blocks'] ?? array() ) ) ) );
		$pages   = array_values( array_filter( array_map( 'absint', (array) ( $data['allowed_pages'] ?? array() ) ) ) );

		$row = array(
			'username'       => $username,
			'display_name'   => sanitize_text_field( $data['display_name'] ?? '' ),
			'allowed_blocks' => wp_json_encode( $allowed ),
			'allowed_pages'  => wp_json_encode( $pages ),
			'active'         => empty( $data['active'] ) ? 0 : 1,
		);
		$formats = array( '%s', '%s', '%s', '%s', '%d' );

		// Only (re)set the password when a non-empty one is supplied.
		if ( ! empty( $data['password'] ) ) {
			$row['password_hash'] = wp_hash_password( $data['password'] );
			$formats[]            = '%s';
		}

		if ( $id ) {
			$wpdb->update( $table, $row, array( 'id' => $id ), $formats, array( '%d' ) );
			return $id;
		}

		if ( empty( $row['password_hash'] ) ) {
			return new WP_Error( 'mcm_password', __( 'A password is required for a new editor.', 'mcm' ) );
		}
		$row['created_at'] = current_time( 'mysql' );
		$formats[]         = '%s';

		$wpdb->insert( $table, $row, $formats );
		return (int) $wpdb->insert_id;
	}

	/**
	 * @param int $id
	 */
	public static function delete_editor( $id ) {
		global $wpdb;
		$id = absint( $id );
		$wpdb->delete( self::editors_table(), array( 'id' => $id ), array( '%d' ) );
		$wpdb->delete( self::sessions_table(), array( 'editor_id' => $id ), array( '%d' ) );
	}

	/**
	 * @param int $id
	 */
	public static function touch_editor_login( $id ) {
		global $wpdb;
		$wpdb->update(
			self::editors_table(),
			array( 'last_login' => current_time( 'mysql' ) ),
			array( 'id' => absint( $id ) ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Decode the allowed_blocks JSON into an int[] list.
	 *
	 * @param object $editor
	 * @return int[]
	 */
	public static function editor_allowed_ids( $editor ) {
		if ( ! $editor || empty( $editor->allowed_blocks ) ) {
			return array();
		}
		$ids = json_decode( $editor->allowed_blocks, true );
		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/**
	 * Decode the allowed_pages JSON into an int[] list of post IDs the editor
	 * may edit in-place.
	 *
	 * @param object $editor
	 * @return int[]
	 */
	public static function editor_allowed_page_ids( $editor ) {
		if ( ! $editor || empty( $editor->allowed_pages ) ) {
			return array();
		}
		$ids = json_decode( $editor->allowed_pages, true );
		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	// -----------------------------------------------------------------------
	// Sessions
	// -----------------------------------------------------------------------

	/**
	 * @param int    $editor_id
	 * @param string $token_hash
	 * @param string $csrf
	 * @param string $ip
	 * @param string $expires_at MySQL datetime
	 * @return int session id
	 */
	public static function create_session( $editor_id, $token_hash, $csrf, $ip, $expires_at ) {
		global $wpdb;
		$wpdb->insert(
			self::sessions_table(),
			array(
				'editor_id'  => absint( $editor_id ),
				'token_hash' => $token_hash,
				'csrf'       => $csrf,
				'ip'         => $ip,
				'expires_at' => $expires_at,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * @param string $token_hash
	 * @return object|null
	 */
	public static function get_session_by_token_hash( $token_hash ) {
		global $wpdb;
		$table = self::sessions_table();
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE token_hash = %s AND expires_at > %s",
				$token_hash,
				current_time( 'mysql' )
			)
		);
	}

	/**
	 * @param string $token_hash
	 */
	public static function delete_session_by_token_hash( $token_hash ) {
		global $wpdb;
		$wpdb->delete( self::sessions_table(), array( 'token_hash' => $token_hash ), array( '%s' ) );
	}

	/**
	 * Remove expired sessions.
	 */
	public static function gc_sessions() {
		global $wpdb;
		$table = self::sessions_table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE expires_at <= %s", current_time( 'mysql' ) ) );
	}
}
