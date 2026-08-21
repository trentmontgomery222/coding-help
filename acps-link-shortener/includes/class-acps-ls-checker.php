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
		$saved = get_option( ACPS_LS_OPT_SETTINGS, array() );
		$saved = is_array( $saved ) ? $saved : array();

		return array(
			'check_enabled'  => ! empty( $saved['check_enabled'] ) ? 1 : 0,
			'scan_content'   => ! isset( $saved['scan_content'] ) || ! empty( $saved['scan_content'] ) ? 1 : 0,
			'scan_comments'  => ! isset( $saved['scan_comments'] ) || ! empty( $saved['scan_comments'] ) ? 1 : 0,
			'recheck_hours'  => isset( $saved['recheck_hours'] ) ? max( 1, (int) $saved['recheck_hours'] ) : 72,
			'timeout'        => isset( $saved['timeout'] ) ? max( 1, (int) $saved['timeout'] ) : 15,
			'warnings'       => ! isset( $saved['warnings'] ) || ! empty( $saved['warnings'] ) ? 1 : 0,
			'notify_admin'   => ! empty( $saved['notify_admin'] ) ? 1 : 0,
			'notify_authors' => ! empty( $saved['notify_authors'] ) ? 1 : 0,
			'notify_email'   => isset( $saved['notify_email'] ) ? (string) $saved['notify_email'] : '',
			'exclusions'     => isset( $saved['exclusions'] ) && is_array( $saved['exclusions'] ) ? $saved['exclusions'] : array(),
			'link_html'      => ! isset( $saved['link_html'] ) || ! empty( $saved['link_html'] ) ? 1 : 0,
			'link_images'    => ! isset( $saved['link_images'] ) || ! empty( $saved['link_images'] ) ? 1 : 0,
			'link_plaintext' => ! empty( $saved['link_plaintext'] ) ? 1 : 0,
			'scan_types'     => isset( $saved['scan_types'] ) && is_array( $saved['scan_types'] ) ? $saved['scan_types'] : array( 'post', 'page' ),
			'scan_statuses'  => isset( $saved['scan_statuses'] ) && is_array( $saved['scan_statuses'] ) ? $saved['scan_statuses'] : array( 'publish' ),
			'widget_enabled' => ! empty( $saved['widget_enabled'] ) ? 1 : 0,
			// Quiet hours: hold automatic e-mails overnight and send after the
			// window ends (default 8 PM -> 8 AM, using the site's timezone).
			'quiet_enabled'  => ! isset( $saved['quiet_enabled'] ) || ! empty( $saved['quiet_enabled'] ) ? 1 : 0,
			'quiet_start'    => isset( $saved['quiet_start'] ) ? min( 23, max( 0, (int) $saved['quiet_start'] ) ) : 20,
			'quiet_end'      => isset( $saved['quiet_end'] ) ? min( 23, max( 0, (int) $saved['quiet_end'] ) ) : 8,
			// Night-only checking: run the outbound HTTP checks only inside this
			// window (default 12 AM -> 6 AM). Discovery/scanning still runs any
			// time so the queue stays fresh; manual "Check now" ignores this.
			'check_night_only' => ! isset( $saved['check_night_only'] ) || ! empty( $saved['check_night_only'] ) ? 1 : 0,
			'check_start'      => isset( $saved['check_start'] ) ? min( 23, max( 0, (int) $saved['check_start'] ) ) : 0,
			'check_end'        => isset( $saved['check_end'] ) ? min( 23, max( 0, (int) $saved['check_end'] ) ) : 6,
			// How often to scan for links when NOT in the checking window. During
			// the window it scans every cron tick; outside it, no more often than
			// this many minutes (default 60).
			'scan_idle_minutes' => isset( $saved['scan_idle_minutes'] ) ? max( 10, (int) $saved['scan_idle_minutes'] ) : 60,
		);
	}

	/**
	 * Whether "now" (site timezone) is inside the link-checking window.
	 *
	 * @return bool
	 */
	private function in_check_window() {
		$s = self::settings();
		if ( empty( $s['check_night_only'] ) ) {
			return true; // Check any time.
		}
		$start = (int) $s['check_start'];
		$end   = (int) $s['check_end'];
		if ( $start === $end ) {
			return true; // Degenerate window = always.
		}
		$hour = (int) current_time( 'G' );
		if ( $start < $end ) {
			return $hour >= $start && $hour < $end;
		}
		return $hour >= $start || $hour < $end; // Wraps midnight.
	}

	/**
	 * Whether "now" (site timezone) falls inside the quiet-hours window.
	 *
	 * @return bool
	 */
	private function in_quiet_hours() {
		$s = self::settings();
		if ( empty( $s['quiet_enabled'] ) ) {
			return false;
		}
		$start = (int) $s['quiet_start'];
		$end   = (int) $s['quiet_end'];
		if ( $start === $end ) {
			return false; // No window.
		}
		$hour = (int) current_time( 'G' ); // 0-23, site timezone.
		if ( $start < $end ) {
			return $hour >= $start && $hour < $end;
		}
		// Window wraps past midnight (e.g. 20 -> 8).
		return $hour >= $start || $hour < $end;
	}

	/**
	 * Whether a URL is excluded by the exclusion list.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private function is_excluded( $url ) {
		$settings = self::settings();
		foreach ( $settings['exclusions'] as $fragment ) {
			$fragment = trim( (string) $fragment );
			if ( '' !== $fragment && false !== stripos( $url, $fragment ) ) {
				return true;
			}
		}
		return false;
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
		try {
			$settings = self::settings();
			if ( empty( $settings['check_enabled'] ) ) {
				return array( 'skipped' => true );
			}

			$in_window = $this->in_check_window();

			// Scan cadence: every tick while checking (the window), but only
			// once per "scan_idle_minutes" the rest of the time — no reason to
			// re-scan every 10 minutes when we aren't checking anyway.
			$do_scan = $in_window;
			if ( ! $do_scan ) {
				$last = (int) get_option( 'acps_ls_last_scan_ts', 0 );
				$gap  = (int) $settings['scan_idle_minutes'] * MINUTE_IN_SECONDS;
				$do_scan = ( time() - $last ) >= $gap;
			}

			if ( $do_scan ) {
				$this->collect_shortener_occurrences();
				if ( ! empty( $settings['scan_content'] ) ) {
					$this->scan_content_batch();
					if ( ! empty( $settings['scan_comments'] ) ) {
						$this->scan_comment_batch();
					}
				}
				update_option( 'acps_ls_last_scan_ts', time() );
			}

			// Only run the outbound HTTP checks during the checking window
			// (default overnight) so the site isn't loaded during the day.
			// The manual "Check now" button bypasses this window.
			$checked = 0;
			if ( $in_window ) {
				$checked = $this->check_batch( self::CHECK_BATCH, (int) $settings['recheck_hours'] );
			}

			$this->maybe_notify();

			update_option(
				'acps_ls_last_check',
				array(
					'time'    => current_time( 'mysql' ),
					'checked' => $checked,
				)
			);

			return array(
				'checked' => $checked,
				'scanned' => $do_scan,
			);
		} catch ( Throwable $e ) {
			// A checker error must never break the request that triggered cron.
			if ( function_exists( 'acps_ls_log_error' ) ) {
				acps_ls_log_error( 'checker run', $e );
			}
			return array( 'error' => true );
		}
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

		$cursor   = (int) get_option( 'acps_ls_scan_post_cursor', 0 );
		$types    = $this->in_list_sql( self::settings()['scan_types'], array( 'post', 'page' ) );
		$statuses = $this->in_list_sql( self::settings()['scan_statuses'], array( 'publish' ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT ID, post_content FROM {$wpdb->posts}
				 WHERE post_status IN ({$statuses}) AND ID > %d AND post_type IN ({$types})
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

		$settings = self::settings();
		$found    = array();

		if ( ! empty( $settings['link_html'] ) ) {
			foreach ( $this->extract_links( $html ) as $l ) {
				$found[ 'link|' . $l['url'] ] = array( 'url' => $l['url'], 'anchor' => $l['anchor'], 'type' => 'link' );
			}
		}
		if ( ! empty( $settings['link_images'] ) ) {
			foreach ( $this->extract_images( $html ) as $l ) {
				$found[ 'image|' . $l['url'] ] = array( 'url' => $l['url'], 'anchor' => $l['anchor'], 'type' => 'image' );
			}
		}
		if ( ! empty( $settings['link_plaintext'] ) ) {
			foreach ( $this->extract_plaintext( $html ) as $l ) {
				if ( ! isset( $found[ 'link|' . $l['url'] ] ) ) {
					$found[ 'url|' . $l['url'] ] = array( 'url' => $l['url'], 'anchor' => '', 'type' => 'url' );
				}
			}
		}

		foreach ( $found as $l ) {
			if ( ! $this->is_checkable( $l['url'] ) ) {
				continue;
			}
			$hash = $this->upsert_url( $l['url'] );
			$this->insert_occurrence( $hash, $type, $id, $field, $l['anchor'], $l['type'] );
		}
	}

	/**
	 * Extract <img src> URLs.
	 *
	 * @param string $html HTML.
	 * @return array[]
	 */
	public function extract_images( $html ) {
		$out = array();
		if ( '' === (string) $html ) {
			return $out;
		}
		if ( preg_match_all( '/<img\s[^>]*src\s*=\s*["\']([^"\']+)["\']/i', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $one ) {
				$url             = html_entity_decode( trim( $one[1] ), ENT_QUOTES );
				$out[ md5( $url ) ] = array( 'url' => $url, 'anchor' => '' );
			}
		}
		return array_values( $out );
	}

	/**
	 * Extract bare http(s) URLs that are not already inside an anchor/img.
	 *
	 * @param string $html HTML.
	 * @return array[]
	 */
	public function extract_plaintext( $html ) {
		$out = array();
		if ( '' === (string) $html ) {
			return $out;
		}
		// Strip tags so we only match text URLs, then find bare links.
		$text = wp_strip_all_tags( $html );
		if ( preg_match_all( '#https?://[^\s"\'<>()]+#i', $text, $m ) ) {
			foreach ( $m[0] as $url ) {
				$url             = rtrim( html_entity_decode( $url, ENT_QUOTES ), '.,;:' );
				$out[ md5( $url ) ] = array( 'url' => $url, 'anchor' => '' );
			}
		}
		return array_values( $out );
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
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return false;
		}
		return ! $this->is_excluded( $url );
	}

	/**
	 * Build a safe quoted IN() list from an array of keys.
	 *
	 * @param array $values   Values.
	 * @param array $fallback Used when $values is empty.
	 * @return string
	 */
	private function in_list_sql( $values, $fallback ) {
		global $wpdb;
		$values = array_filter( array_map( 'sanitize_key', (array) $values ) );
		if ( ! $values ) {
			$values = $fallback;
		}
		$quoted = array_map(
			function ( $v ) use ( $wpdb ) {
				return $wpdb->prepare( '%s', $v );
			},
			$values
		);
		return implode( ',', $quoted );
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
	private function insert_occurrence( $url_hash, $type, $id, $field, $anchor, $link_type = 'link' ) {
		global $wpdb;
		$occ      = acps_ls_occ_table();
		$occ_hash = md5( $type . '|' . $id . '|' . $field . '|' . $link_type . '|' . $url_hash );

		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"INSERT INTO {$occ} (occ_hash, url_hash, source_type, source_id, source_field, link_type, anchor, seen_at)
				 VALUES (%s, %s, %s, %d, %s, %s, %s, %s)
				 ON DUPLICATE KEY UPDATE anchor = VALUES(anchor), seen_at = VALUES(seen_at)",
				$occ_hash,
				$url_hash,
				$type,
				$id,
				$field,
				$link_type,
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
		$settings = self::settings();
		$args     = array(
			'timeout'     => (int) $settings['timeout'],
			'redirection' => 0, // Inspect redirects ourselves.
			'sslverify'   => true,
			'user-agent'  => 'CaydenRiddle-LinkChecker/1.0 (+' . home_url( '/' ) . ')',
		);

		$resp = wp_remote_head( $url, $args );
		$code = (int) wp_remote_retrieve_response_code( $resp );

		// Many servers reject HEAD; retry with GET.
		if ( is_wp_error( $resp ) || 0 === $code || in_array( $code, array( 403, 405, 501 ), true ) ) {
			$resp = wp_remote_get( $url, $args );
			$code = (int) wp_remote_retrieve_response_code( $resp );
		}

		if ( is_wp_error( $resp ) ) {
			// A connection error / timeout is uncertain: show as a warning when
			// warnings are enabled, otherwise treat it as broken.
			$state = ! empty( $settings['warnings'] ) ? 'warning' : 'broken';
			return array( 'state' => $state, 'code' => 0, 'text' => $resp->get_error_message(), 'final' => '' );
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
		$now  = current_time( 'mysql' );

		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$urls}
				 SET state = %s, http_code = %d, final_url = %s, status_text = %s,
				     fail_count = IF(%d = 1, fail_count + 1, 0),
				     first_failure = IF(%d = 1, IFNULL(first_failure, %s), NULL),
				     notified = IF(%d = 1, notified, 0),
				     last_checked = %s
				 WHERE id = %d",
				$result['state'],
				(int) $result['code'],
				$result['final'],
				mb_substr( (string) $result['text'], 0, 190 ),
				$fail,
				$fail,
				$now,
				$fail,
				$now,
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
	 * Automatic notification for NEWLY detected broken links (throttled).
	 */
	public function maybe_notify() {
		$s = self::settings();
		if ( empty( $s['notify_admin'] ) && empty( $s['notify_authors'] ) ) {
			return 0;
		}
		// Quiet hours: hold overnight and send after the window ends (e.g. 8 AM).
		// Broken links stay un-notified until then, so they go out in the first
		// send after quiet hours end.
		if ( $this->in_quiet_hours() ) {
			return 0;
		}
		// Batch: don't send more than once an hour automatically.
		$last = get_option( 'acps_ls_last_email' );
		if ( is_array( $last ) && ! empty( $last['ts'] ) && ( time() - (int) $last['ts'] ) < HOUR_IN_SECONDS ) {
			return 0;
		}
		return $this->send_broken_report( false );
	}

	/**
	 * Force a report of ALL current broken links, on demand.
	 *
	 * Sends regardless of the once-an-hour throttle and the per-link "notified"
	 * flag, and always e-mails the notification address even if the automatic
	 * admin toggle is off (this is an explicit button press).
	 *
	 * @return int Number of broken links reported.
	 */
	public function force_notify() {
		return $this->send_broken_report( true );
	}

	/**
	 * Build and send the broken-links e-mail report.
	 *
	 * @param bool $force When true, include EVERY broken link and ignore toggles.
	 * @return int Number of broken links reported.
	 */
	private function send_broken_report( $force ) {
		global $wpdb;
		$s    = self::settings();
		$urls = acps_ls_urls_table();

		// Forced: every current broken link. Automatic: only un-notified ones.
		$where = $force
			? "state = 'broken' AND dismissed = 0 AND false_positive = 0"
			: "state = 'broken' AND notified = 0 AND dismissed = 0 AND false_positive = 0";

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$broken = $wpdb->get_results( "SELECT * FROM {$urls} WHERE {$where} LIMIT 500" );
		if ( ! $broken ) {
			return 0;
		}

		$site = get_bloginfo( 'name' );
		$link = admin_url( 'admin.php?page=acps-link-shortener-checker&state=broken' );

		// Admin/notification address: always on force; on the toggle otherwise.
		if ( $force || ! empty( $s['notify_admin'] ) ) {
			$to    = $s['notify_email'] ? $s['notify_email'] : get_option( 'admin_email' );
			$lines = array();
			foreach ( $broken as $u ) {
				$lines[] = '• ' . $u->url . ' — ' . ( $u->status_text ? $u->status_text : ( 'HTTP ' . (int) $u->http_code ) );
			}
			$intro = $force
				? __( 'All currently broken links:', 'acps-link-shortener' )
				: __( 'The following links were detected as broken:', 'acps-link-shortener' );
			$body  = $intro . "\n\n" . implode( "\n", $lines ) . "\n\n" . __( 'Review them here:', 'acps-link-shortener' ) . ' ' . $link;
			wp_mail( $to, '[' . $site . '] ' . __( 'Broken links report', 'acps-link-shortener' ), $body );
		}

		if ( ! empty( $s['notify_authors'] ) ) {
			$by_author = array();
			foreach ( $broken as $u ) {
				foreach ( self::occurrences_for( $u->url_hash ) as $o ) {
					if ( 'post' === $o->source_type ) {
						$author = (int) get_post_field( 'post_author', $o->source_id );
						if ( $author ) {
							$by_author[ $author ][] = $u->url . ' (' . get_the_title( $o->source_id ) . ')';
						}
					}
				}
			}
			foreach ( $by_author as $author => $lines ) {
				$user = get_userdata( $author );
				if ( $user && $user->user_email ) {
					$body = __( 'Broken links were found in your posts:', 'acps-link-shortener' ) . "\n\n" . implode( "\n", array_unique( $lines ) );
					wp_mail( $user->user_email, '[' . $site . '] ' . __( 'Broken links in your posts', 'acps-link-shortener' ), $body );
				}
			}
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "UPDATE {$urls} SET notified = 1 WHERE state = 'broken'" );
		update_option( 'acps_ls_last_email', array( 'ts' => time(), 'time' => current_time( 'mysql' ), 'count' => count( $broken ) ) );

		return count( $broken );
	}

	/**
	 * Count URLs by state (broken excludes dismissed / false positives).
	 *
	 * @return array
	 */
	public static function counts() {
		global $wpdb;
		$urls = acps_ls_urls_table();

		$counts = array( 'all' => 0, 'ok' => 0, 'broken' => 0, 'redirect' => 0, 'unchecked' => 0, 'warning' => 0, 'dismissed' => 0 );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$counts['all']       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$urls} WHERE dismissed = 0" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$counts['broken']    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$urls} WHERE state='broken' AND dismissed=0 AND false_positive=0" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$counts['warning']   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$urls} WHERE state='warning' AND dismissed=0 AND false_positive=0" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$counts['redirect']  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$urls} WHERE state='redirect' AND dismissed=0" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$counts['ok']        = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$urls} WHERE state='ok' AND dismissed=0" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$counts['unchecked'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$urls} WHERE state='unchecked' AND dismissed=0" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$counts['dismissed'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$urls} WHERE dismissed=1" );

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

		if ( 'dismissed' === $state ) {
			$where .= ' AND u.dismissed = 1';
		} elseif ( in_array( $state, array( 'ok', 'redirect', 'unchecked' ), true ) ) {
			$where   .= ' AND u.state = %s AND u.dismissed = 0';
			$params[] = $state;
		} elseif ( in_array( $state, array( 'broken', 'warning' ), true ) ) {
			$where   .= ' AND u.state = %s AND u.dismissed = 0 AND u.false_positive = 0';
			$params[] = $state;
		} else {
			$where .= ' AND u.dismissed = 0';
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
	 * Set a boolean flag column on a URL row.
	 *
	 * @param int    $id    Row id.
	 * @param string $field 'dismissed' or 'false_positive'.
	 * @param int    $value 0/1.
	 */
	public static function set_flag( $id, $field, $value ) {
		global $wpdb;
		if ( ! in_array( $field, array( 'dismissed', 'false_positive' ), true ) ) {
			return;
		}
		$urls = acps_ls_urls_table();
		$wpdb->update( $urls, array( $field => $value ? 1 : 0 ), array( 'id' => (int) $id ), array( '%d' ), array( '%d' ) );
	}

	/**
	 * "Fix redirect": repoint a URL to its final (redirect) target everywhere.
	 *
	 * @param int $id URL row id.
	 * @return int Occurrences changed.
	 */
	public function fix_redirect( $id ) {
		$row = self::get_url( $id );
		if ( ! $row || empty( $row->final_url ) ) {
			return 0;
		}
		return $this->replace_everywhere( $row->url_hash, $row->final_url );
	}

	/**
	 * "Unlink": remove the <a> wrapper (keep the text) everywhere a URL appears
	 * in post/comment content. Does not affect shortener destinations.
	 *
	 * @param string $url_hash URL hash.
	 * @return int Sources changed.
	 */
	public function unlink_everywhere( $url_hash ) {
		$row = self::get_url_by_hash( $url_hash );
		if ( ! $row ) {
			return 0;
		}
		$changed = 0;
		foreach ( self::occurrences_for( $url_hash ) as $o ) {
			if ( 'post' === $o->source_type ) {
				$post = get_post( $o->source_id );
				if ( $post ) {
					$new = $this->strip_anchor( $post->post_content, $row->url );
					if ( $new !== $post->post_content ) {
						wp_update_post( array( 'ID' => $o->source_id, 'post_content' => $new ) );
						$this->index_source( 'post', (int) $o->source_id, 'content', $new );
						$changed++;
					}
				}
			} elseif ( 'comment' === $o->source_type ) {
				$comment = get_comment( $o->source_id );
				if ( $comment ) {
					$new = $this->strip_anchor( $comment->comment_content, $row->url );
					if ( $new !== $comment->comment_content ) {
						wp_update_comment( array( 'comment_ID' => $o->source_id, 'comment_content' => $new ) );
						$this->index_source( 'comment', (int) $o->source_id, 'content', $new );
						$changed++;
					}
				}
			}
		}
		return $changed;
	}

	/**
	 * Replace <a href="URL"...>text</a> with just its text.
	 *
	 * @param string $html HTML.
	 * @param string $url  URL to unlink.
	 * @return string
	 */
	private function strip_anchor( $html, $url ) {
		$quoted = preg_quote( $url, '#' );
		return preg_replace( '#<a\s[^>]*href\s*=\s*["\']' . $quoted . '["\'][^>]*>(.*?)</a>#is', '$1', $html );
	}

	/**
	 * Forced recheck ("nuclear option"): empty the checker tables and cursors so
	 * everything is rediscovered and rechecked from scratch.
	 */
	public function forced_recheck() {
		global $wpdb;
		$urls = acps_ls_urls_table();
		$occ  = acps_ls_occ_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "TRUNCATE TABLE {$urls}" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "TRUNCATE TABLE {$occ}" );
		update_option( 'acps_ls_scan_post_cursor', 0 );
		update_option( 'acps_ls_scan_comment_cursor', 0 );
		$this->collect_shortener_occurrences();
	}

	/**
	 * System / status information for the checker screen.
	 *
	 * @return array
	 */
	public static function status_info() {
		global $wpdb;
		$urls   = acps_ls_urls_table();
		$occ    = acps_ls_occ_table();
		$counts = self::counts();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$unique = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$urls}" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$occ_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$occ}" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$queue = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$urls} WHERE state='unchecked' OR last_checked IS NULL" );

		$last_email = get_option( 'acps_ls_last_email' );

		return array(
			'broken'      => $counts['broken'],
			'queue'       => $queue,
			'unique'      => $unique,
			'occ_total'   => $occ_total,
			'php'         => PHP_VERSION,
			'mysql'       => $wpdb->db_version(),
			'curl'        => function_exists( 'curl_version' ) && curl_version() ? curl_version()['version'] : __( 'n/a', 'acps-link-shortener' ),
			'timeout'     => (int) self::settings()['timeout'],
			'last_email'  => is_array( $last_email ) && ! empty( $last_email['time'] ) ? $last_email['time'] : __( 'never', 'acps-link-shortener' ),
			'last_check'  => ( is_array( get_option( 'acps_ls_last_check' ) ) ? ( get_option( 'acps_ls_last_check' )['time'] ?? '' ) : '' ),
		);
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
