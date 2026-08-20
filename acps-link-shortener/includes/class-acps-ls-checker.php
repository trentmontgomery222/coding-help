<?php
/**
 * Link checker: discovers links (in the shortener's destinations AND across
 * site content), verifies each unique URL over HTTP, and applies replacement
 * rules.
 *
 * Efficiency notes:
 * - Each unique URL is checked ONCE, however many places it appears (dedupe by
 *   md5 hash). Occurrences map a URL back to its sources.
 * - Scanning and checking run in small WP-Cron batches so a large site never
 *   blocks a request.
 * - Checks use HEAD first and fall back to GET; results are cached until the
 *   recheck window elapses.
 *
 * This is an original implementation, not derived from any other plugin.
 *
 * @package ACPS_Link_Shortener
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The checker engine.
 */
class ACPS_LS_Checker {

	const SCAN_BATCH  = 15; // posts (and comments) processed per cron run.
	const CHECK_BATCH = 15; // URLs verified per cron run.

	/**
	 * Hook cron + the destination rewrite filter.
	 */
	public function register() {
		add_action( ACPS_LS_CHECK_HOOK, array( $this, 'run' ) );
		// Auto-apply rewrite rules wherever a destination is saved.
		add_filter( 'acps_ls_filter_destination', 'acps_ls_apply_rules' );
	}

	/**
	 * Settings with defaults.
	 *
	 * @return array
	 */
	public static function settings() {
		$defaults = array(
			'check_enabled' => 0,
			'scan_content'  => 1,
			'recheck_hours' => 72,
		);
		$saved = get_option( ACPS_LS_OPT_SETTINGS, array() );
		$saved = is_array( $saved ) ? $saved : array();
		return array(
			'check_enabled' => ! empty( $saved['check_enabled'] ) ? 1 : 0,
			'scan_content'  => isset( $saved['scan_content'] ) ? (int) ! empty( $saved['scan_content'] ) : 1,
			'recheck_hours' => isset( $saved['recheck_hours'] ) ? max( 1, (int) $saved['recheck_hours'] ) : 72,
		);
	}

	/* --------------------------------------------------------------------- */
	/* Cron                                                                   */
	/* --------------------------------------------------------------------- */

	/**
	 * Cron entry point: refresh sources, scan a batch, check a batch.
	 *
	 * @return array Summary.
	 */
	public function run() {
		$settings = self::settings();
		if ( empty( $settings['check_enabled'] ) ) {
			return array( 'skipped' => true );
		}

		$this->collect_shortener_occurrences();

		if ( ! empty( $settings['scan_content'] ) ) {
			$this->scan_content_batch();
			$this->scan_comment_batch();
		}

		$checked = $this->check_batch( self::CHECK_BATCH, (int) $settings['recheck_hours'] );

		update_option(
			'acps_ls_last_check',
			array(
				'time'    => current_time( 'mysql' ),
				'checked' => $checked,
			)
		);

		return array( 'checked' => $checked );
	}

	/* --------------------------------------------------------------------- */
	/* Discovery                                                              */
	/* --------------------------------------------------------------------- */

	/**
	 * Record every active shortener destination as an occurrence.
	 */
	public function collect_shortener_occurrences() {
		global $wpdb;
		$table = acps_ls_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$links = $wpdb->get_results( "SELECT id, slug, destination FROM {$table}" );
		if ( ! $links ) {
			return;
		}

		$occ = acps_ls_occ_table();
		// Refresh the shortener occurrences wholesale (cheap, bounded set).
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$occ} WHERE source_type = %s", 'shortener' ) );

		foreach ( $links as $link ) {
			$url = esc_url_raw( $link->destination );
			if ( ! $this->is_checkable( $url ) ) {
				continue;
			}
			$hash = $this->upsert_url( $url );
			$this->insert_occurrence( $hash, 'shortener', (int) $link->id, 'destination', $link->slug );
		}
	}

	/**
	 * Scan the next batch of post/page content for links.
	 */
	public function scan_content_batch() {
		global $wpdb;

		$cursor = (int) get_option( 'acps_ls_scan_post_cursor', 0 );
		$types  = $this->post_types_sql();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT ID, post_content FROM {$wpdb->posts}
				 WHERE post_status = 'publish' AND ID > %d AND post_type IN ({$types})
				 ORDER BY ID ASC LIMIT %d",
				$cursor,
				self::SCAN_BATCH
			)
		);

		if ( ! $rows ) {
			update_option( 'acps_ls_scan_post_cursor', 0 ); // Wrap around next cycle.
			$this->prune_orphan_urls();
			return;
		}

		foreach ( $rows as $row ) {
			$this->index_source( 'post', (int) $row->ID, 'content', $row->post_content );
			$cursor = (int) $row->ID;
		}
		update_option( 'acps_ls_scan_post_cursor', $cursor );
	}

	/**
	 * Scan the next batch of approved comments for links.
	 */
	public function scan_comment_batch() {
		global $wpdb;

		$cursor = (int) get_option( 'acps_ls_scan_comment_cursor', 0 );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT comment_ID, comment_content FROM {$wpdb->comments}
				 WHERE comment_approved = '1' AND comment_ID > %d
				 ORDER BY comment_ID ASC LIMIT %d",
				$cursor,
				self::SCAN_BATCH
			)
		);

		if ( ! $rows ) {
			update_option( 'acps_ls_scan_comment_cursor', 0 );
			return;
		}

		foreach ( $rows as $row ) {
			$this->index_source( 'comment', (int) $row->comment_ID, 'content', $row->comment_content );
			$cursor = (int) $row->comment_ID;
		}
		update_option( 'acps_ls_scan_comment_cursor', $cursor );
	}

	/**
	 * Replace all occurrences for one source with the links currently in it.
	 *
	 * @param string $type  Source type.
	 * @param int    $id    Source id.
	 * @param string $field Source field.
	 * @param string $html  Content to parse.
	 */
	private function index_source( $type, $id, $field, $html ) {
		global $wpdb;
		$occ = acps_ls_occ_table();

		$wpdb->delete( $occ, array( 'source_type' => $type, 'source_id' => $id ), array( '%s', '%d' ) );

		foreach ( $this->extract_links( $html ) as $link ) {
			if ( ! $this->is_checkable( $link['url'] ) ) {
				continue;
			}
			$hash = $this->upsert_url( $link['url'] );
			$this->insert_occurrence( $hash, $type, $id, $field, $link['anchor'] );
		}
	}

	/**
	 * Extract http(s) links + anchor text from HTML.
	 *
	 * @param string $html HTML.
	 * @return array[] Each: [ 'url' => string, 'anchor' => string ].
	 */
	public function extract_links( $html ) {
		$out = array();
		if ( '' === (string) $html ) {
			return $out;
		}

		if ( preg_match_all( '/<a\s[^>]*href\s*=\s*["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $m ) {
				$url = html_entity_decode( trim( $m[1] ), ENT_QUOTES );
				$out[ md5( $url ) ] = array(
					'url'    => $url,
					'anchor' => mb_substr( wp_strip_all_tags( $m[2] ), 0, 250 ),
				);
			}
		}
		return array_values( $out );
	}

	/**
	 * Whether a URL should be checked (absolute http/https only).
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private function is_checkable( $url ) {
		if ( '' === (string) $url ) {
			return false;
		}
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		return in_array( $scheme, array( 'http', 'https' ), true );
	}

	/**
	 * Post types to scan (filterable).
	 *
	 * @return string SQL-safe comma list of quoted types.
	 */
	private function post_types_sql() {
		global $wpdb;
		$types = apply_filters( 'acps_ls_scan_post_types', array( 'post', 'page' ) );
		$types = array_map( 'sanitize_key', (array) $types );
		$types = array_map(
			function ( $t ) use ( $wpdb ) {
				return $wpdb->prepare( '%s', $t );
			},
			$types ? $types : array( 'post' )
		);
		return implode( ',', $types );
	}

	/* --------------------------------------------------------------------- */
	/* Storage helpers                                                        */
	/* --------------------------------------------------------------------- */

	/**
	 * Insert a URL row if new; return its hash.
	 *
	 * @param string $url URL.
	 * @return string md5 hash.
	 */
	private function upsert_url( $url ) {
		global $wpdb;
		$urls = acps_ls_urls_table();
		$hash = md5( $url );

		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"INSERT INTO {$urls} (url_hash, url, state) VALUES (%s, %s, 'unchecked')
				 ON DUPLICATE KEY UPDATE url = VALUES(url)",
				$hash,
				$url
			)
		);
		return $hash;
	}

	/**
	 * Insert (or refresh) an occurrence.
	 *
	 * @param string $url_hash URL hash.
	 * @param string $type     Source type.
	 * @param int    $id       Source id.
	 * @param string $field    Source field.
	 * @param string $anchor   Anchor text.
	 */
	private function insert_occurrence( $url_hash, $type, $id, $field, $anchor ) {
		global $wpdb;
		$occ      = acps_ls_occ_table();
		$occ_hash = md5( $type . '|' . $id . '|' . $field . '|' . $url_hash );

		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"INSERT INTO {$occ} (occ_hash, url_hash, source_type, source_id, source_field, anchor, seen_at)
				 VALUES (%s, %s, %s, %d, %s, %s, %s)
				 ON DUPLICATE KEY UPDATE anchor = VALUES(anchor), seen_at = VALUES(seen_at)",
				$occ_hash,
				$url_hash,
				$type,
				$id,
				$field,
				(string) $anchor,
				current_time( 'mysql' )
			)
		);
	}

	/**
	 * Delete URL rows that no longer have any occurrence.
	 */
	public function prune_orphan_urls() {
		global $wpdb;
		$urls = acps_ls_urls_table();
		$occ  = acps_ls_occ_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE u FROM {$urls} u LEFT JOIN {$occ} o ON o.url_hash = u.url_hash WHERE o.id IS NULL" );
	}

	/* --------------------------------------------------------------------- */
	/* HTTP checking                                                          */
	/* --------------------------------------------------------------------- */

	/**
	 * Check a batch of stale/unchecked URLs.
	 *
	 * @param int $limit         Max URLs.
	 * @param int $recheck_hours Recheck window.
	 * @return int Number checked.
	 */
	public function check_batch( $limit, $recheck_hours ) {
		global $wpdb;
		$urls   = acps_ls_urls_table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $recheck_hours * HOUR_IN_SECONDS ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id, url FROM {$urls}
				 WHERE state = 'unchecked' OR last_checked IS NULL OR last_checked < %s
				 ORDER BY last_checked IS NULL DESC, last_checked ASC
				 LIMIT %d",
				$cutoff,
				(int) $limit
			)
		);

		if ( ! $rows ) {
			return 0;
		}

		foreach ( $rows as $row ) {
			$result = $this->check_url( $row->url );
			$this->save_check_result( (int) $row->id, $result );
		}
		return count( $rows );
	}

	/**
	 * Verify a single URL over HTTP (HEAD, then GET fallback).
	 *
	 * @param string $url URL.
	 * @return array { state, code, text, final }
	 */
	public function check_url( $url ) {
		$args = array(
			'timeout'     => 8,
			'redirection' => 0, // Inspect redirects ourselves.
			'sslverify'   => true,
			'user-agent'  => 'ACPS-LinkChecker/1.0 (+' . home_url( '/' ) . ')',
		);

		$resp = wp_remote_head( $url, $args );
		$code = (int) wp_remote_retrieve_response_code( $resp );

		// Many servers reject HEAD; retry with GET.
		if ( is_wp_error( $resp ) || 0 === $code || in_array( $code, array( 403, 405, 501 ), true ) ) {
			$resp = wp_remote_get( $url, $args );
			$code = (int) wp_remote_retrieve_response_code( $resp );
		}

		if ( is_wp_error( $resp ) ) {
			return array( 'state' => 'broken', 'code' => 0, 'text' => $resp->get_error_message(), 'final' => '' );
		}
		if ( $code >= 200 && $code < 300 ) {
			return array( 'state' => 'ok', 'code' => $code, 'text' => '', 'final' => '' );
		}
		if ( $code >= 300 && $code < 400 ) {
			return array(
				'state' => 'redirect',
				'code'  => $code,
				'text'  => __( 'Redirect', 'acps-link-shortener' ),
				'final' => (string) wp_remote_retrieve_header( $resp, 'location' ),
			);
		}
		return array(
			'state' => 'broken',
			'code'  => $code,
			'text'  => sprintf( /* translators: %d: HTTP status. */ __( 'HTTP %d', 'acps-link-shortener' ), $code ),
			'final' => '',
		);
	}

	/**
	 * Persist a check result.
	 *
	 * @param int   $id     URL row id.
	 * @param array $result Result from check_url().
	 */
	private function save_check_result( $id, $result ) {
		global $wpdb;
		$urls = acps_ls_urls_table();

		$fail = ( 'broken' === $result['state'] ) ? 1 : 0;

		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$urls}
				 SET state = %s, http_code = %d, final_url = %s, status_text = %s,
				     fail_count = IF(%d = 1, fail_count + 1, 0), last_checked = %s
				 WHERE id = %d",
				$result['state'],
				(int) $result['code'],
				$result['final'],
				mb_substr( (string) $result['text'], 0, 190 ),
				$fail,
				current_time( 'mysql' ),
				$id
			)
		);
	}

	/* --------------------------------------------------------------------- */
	/* Rules                                                                  */
	/* --------------------------------------------------------------------- */

	/**
	 * Apply enabled REWRITE rules to every shortener destination in place.
	 *
	 * @return int Number of links changed.
	 */
	public function apply_rules_to_shortener() {
		global $wpdb;
		$table = acps_ls_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$links   = $wpdb->get_results( "SELECT id, destination FROM {$table}" );
		$changed = 0;

		foreach ( $links as $link ) {
			$new = acps_ls_apply_rules( $link->destination );
			if ( $new !== $link->destination ) {
				$new = esc_url_raw( $new );
				if ( $new ) {
					$wpdb->update( $table, array( 'destination' => $new, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $link->id ), array( '%s', '%s' ), array( '%d' ) );
					$changed++;
				}
			}
		}
		return $changed;
	}

	/**
	 * Rewrite a URL inside a post/comment source and save it.
	 *
	 * @param string $type    Source type ('post'|'comment').
	 * @param int    $id      Source id.
	 * @param string $old_url URL to replace.
	 * @param string $new_url Replacement.
	 * @return bool
	 */
	public function replace_in_source( $type, $id, $old_url, $new_url ) {
		if ( '' === $new_url || $old_url === $new_url ) {
			return false;
		}

		if ( 'post' === $type ) {
			$post = get_post( $id );
			if ( ! $post ) {
				return false;
			}
			$content = str_replace( $old_url, $new_url, $post->post_content );
			if ( $content === $post->post_content ) {
				return false;
			}
			wp_update_post( array( 'ID' => $id, 'post_content' => $content ) );
			$this->index_source( 'post', $id, 'content', $content );
			return true;
		}

		if ( 'comment' === $type ) {
			$comment = get_comment( $id );
			if ( ! $comment ) {
				return false;
			}
			$content = str_replace( $old_url, $new_url, $comment->comment_content );
			if ( $content === $comment->comment_content ) {
				return false;
			}
			wp_update_comment( array( 'comment_ID' => $id, 'comment_content' => $content ) );
			$this->index_source( 'comment', $id, 'content', $content );
			return true;
		}

		return false;
	}

	/* --------------------------------------------------------------------- */
	/* Queries for the admin screen                                           */
	/* --------------------------------------------------------------------- */

	/**
	 * Count URLs grouped by state.
	 *
	 * @return array state => count (plus 'all').
	 */
	public static function counts() {
		global $wpdb;
		$urls = acps_ls_urls_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( "SELECT state, COUNT(*) AS n FROM {$urls} GROUP BY state", OBJECT_K );

		$counts = array( 'all' => 0, 'ok' => 0, 'broken' => 0, 'redirect' => 0, 'unchecked' => 0, 'warning' => 0 );
		if ( $rows ) {
			foreach ( $rows as $state => $r ) {
				$counts[ $state ] = (int) $r->n;
				$counts['all']   += (int) $r->n;
			}
		}
		return $counts;
	}

	/**
	 * Fetch a page of URLs (with occurrence count) for the list table.
	 *
	 * @param array $args state, search, per_page, paged.
	 * @return array { items, total }
	 */
	public static function get_urls( $args = array() ) {
		global $wpdb;
		$urls = acps_ls_urls_table();
		$occ  = acps_ls_occ_table();

		$state    = isset( $args['state'] ) ? sanitize_key( $args['state'] ) : '';
		$search   = isset( $args['search'] ) ? (string) $args['search'] : '';
		$per_page = max( 1, (int) ( $args['per_page'] ?? 20 ) );
		$paged    = max( 1, (int) ( $args['paged'] ?? 1 ) );
		$offset   = ( $paged - 1 ) * $per_page;

		$where  = 'WHERE 1=1';
		$params = array();

		if ( $state && in_array( $state, array( 'ok', 'broken', 'redirect', 'unchecked', 'warning' ), true ) ) {
			$where   .= ' AND u.state = %s';
			$params[] = $state;
		}
		if ( '' !== $search ) {
			$where   .= ' AND u.url LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$count_sql = "SELECT COUNT(*) FROM {$urls} u {$where}";
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );

		$list_params   = $params;
		$list_params[] = $per_page;
		$list_params[] = $offset;

		$items = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT u.*, ( SELECT COUNT(*) FROM {$occ} o WHERE o.url_hash = u.url_hash ) AS occ_count
				 FROM {$urls} u {$where}
				 ORDER BY ( u.state = 'broken' ) DESC, u.last_checked ASC
				 LIMIT %d OFFSET %d",
				$list_params
			)
		);

		return array( 'items' => $items ? $items : array(), 'total' => $total );
	}

	/**
	 * Occurrences for one URL hash.
	 *
	 * @param string $url_hash URL hash.
	 * @return object[]
	 */
	public static function occurrences_for( $url_hash ) {
		global $wpdb;
		$occ = acps_ls_occ_table();
		return $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$occ} WHERE url_hash = %s ORDER BY source_type, source_id",
				$url_hash
			)
		);
	}

	/**
	 * Get one URL row by id.
	 *
	 * @param int $id Row id.
	 * @return object|null
	 */
	public static function get_url( $id ) {
		global $wpdb;
		$urls = acps_ls_urls_table();
		return $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$urls} WHERE id = %d",
				(int) $id
			)
		);
	}

	/**
	 * Fetch a URL row by its hash.
	 *
	 * @param string $hash URL hash.
	 * @return object|null
	 */
	public static function get_url_by_hash( $hash ) {
		global $wpdb;
		$urls = acps_ls_urls_table();
		return $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$urls} WHERE url_hash = %s",
				$hash
			)
		);
	}

	/**
	 * Replace a URL everywhere it occurs (shortener destinations + content).
	 *
	 * @param string $url_hash Hash of the URL to replace.
	 * @param string $new_url  Replacement URL.
	 * @return int Number of occurrences changed.
	 */
	public function replace_everywhere( $url_hash, $new_url ) {
		global $wpdb;

		$row     = self::get_url_by_hash( $url_hash );
		$new_url = esc_url_raw( $new_url );
		if ( ! $row || '' === $new_url || $row->url === $new_url ) {
			return 0;
		}
		$old = $row->url;

		$changed = 0;
		foreach ( self::occurrences_for( $url_hash ) as $occ ) {
			if ( 'shortener' === $occ->source_type ) {
				$table = acps_ls_table_name();
				$wpdb->update( $table, array( 'destination' => $new_url, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $occ->source_id ), array( '%s', '%s' ), array( '%d' ) );
				$changed++;
			} elseif ( $this->replace_in_source( $occ->source_type, (int) $occ->source_id, $old, $new_url ) ) {
				$changed++;
			}
		}

		// Refresh shortener occurrences and mark the new URL for a fresh check.
		$this->collect_shortener_occurrences();
		self::mark_recheck_by_hash( md5( $new_url ) );

		return $changed;
	}

	/**
	 * Mark a URL (by hash) for recheck.
	 *
	 * @param string $hash URL hash.
	 */
	public static function mark_recheck_by_hash( $hash ) {
		global $wpdb;
		$urls = acps_ls_urls_table();
		$wpdb->update( $urls, array( 'state' => 'unchecked', 'last_checked' => null ), array( 'url_hash' => $hash ), array( '%s', '%s' ), array( '%s' ) );
	}

	/**
	 * Mark URLs for recheck (state -> unchecked).
	 *
	 * @param int $id Optional single id; 0 = all.
	 */
	public static function mark_recheck( $id = 0 ) {
		global $wpdb;
		$urls = acps_ls_urls_table();
		if ( $id ) {
			$wpdb->update( $urls, array( 'state' => 'unchecked', 'last_checked' => null ), array( 'id' => (int) $id ), array( '%s', '%s' ), array( '%d' ) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "UPDATE {$urls} SET state = 'unchecked', last_checked = NULL" );
		}
	}
}
