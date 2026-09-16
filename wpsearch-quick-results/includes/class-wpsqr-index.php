<?php
/**
 * The search index.
 *
 * WordPress's own search is a LIKE over post_content with no ranking: a page
 * whose title is exactly the search term sorts below one that mentions it once
 * in a footer, because the only ordering available is by date. That is the
 * whole reason sites reach for a search plugin.
 *
 * So this keeps its own table: one row per post, holding the title separately
 * from everything else worth matching, both full-text indexed. Ranking then
 * comes from MySQL's own relevance scoring, weighted so the title counts for
 * far more than a passing mention.
 *
 * The index is a derived cache. Losing it costs a rebuild, never data.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPSQR_Index {

	/** Posts per batch when rebuilding. Small enough to finish inside a cron run. */
	const BATCH = 100;

	const REINDEX_HOOK = 'wpsqr_reindex_batch';

	public function hooks() {
		add_action( 'save_post', array( __CLASS__, 'index_post' ), 20 );
		add_action( 'deleted_post', array( __CLASS__, 'remove_post' ) );
		add_action( 'trashed_post', array( __CLASS__, 'remove_post' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'index_post' ) );
		add_action( self::REINDEX_HOOK, array( __CLASS__, 'run_batch' ) );
	}

	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'wpsqr_index';
	}

	/* ---- Schema -------------------------------------------------------- */

	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		// The full-text keys are added separately: dbDelta's parser is
		// unreliable with FULLTEXT and will happily drop and re-add them on
		// every run, which on a large table is an expensive surprise.
		dbDelta(
			"CREATE TABLE {$table} (
				post_id BIGINT UNSIGNED NOT NULL,
				post_type VARCHAR(32) NOT NULL DEFAULT '',
				post_date DATETIME NULL,
				title TEXT NULL,
				search_text MEDIUMTEXT NULL,
				indexed_at DATETIME NOT NULL,
				PRIMARY KEY (post_id),
				KEY post_type (post_type),
				KEY post_date (post_date)
			) ENGINE=InnoDB {$charset};"
		);

		self::ensure_fulltext();
	}

	/** Add the full-text keys if they are not already there. */
	public static function ensure_fulltext() {
		global $wpdb;

		$table = self::table();

		$existing = $wpdb->get_col( "SHOW INDEX FROM {$table}", 2 ); // phpcs:ignore

		if ( ! is_array( $existing ) ) {
			return false;
		}

		$ok = true;

		if ( ! in_array( 'ft_title', $existing, true ) ) {
			$ok = false !== $wpdb->query( "ALTER TABLE {$table} ADD FULLTEXT KEY ft_title (title)" ) && $ok; // phpcs:ignore
		}

		if ( ! in_array( 'ft_main', $existing, true ) ) {
			$ok = false !== $wpdb->query( "ALTER TABLE {$table} ADD FULLTEXT KEY ft_main (search_text)" ) && $ok; // phpcs:ignore
		}

		update_option( 'wpsqr_fulltext_ok', $ok ? 1 : 0, false );

		return $ok;
	}

	/**
	 * Is full-text search available?
	 *
	 * Without it the engine falls back to LIKE, which still works and is
	 * still better than core search — it just cannot rank as well.
	 */
	public static function has_fulltext() {
		return (bool) get_option( 'wpsqr_fulltext_ok', 0 );
	}

	/* ---- Writing ------------------------------------------------------- */

	/** Post types that go in the index. */
	public static function post_types() {
		$configured = (array) WPSQR_Plugin::settings()['index_types'];

		if ( ! $configured ) {
			$configured = array_values( get_post_types( array( 'public' => true, 'exclude_from_search' => false ) ) );
		}

		return apply_filters( 'wpsqr_index_post_types', $configured );
	}

	public static function index_post( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return;
		}

		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}

		if ( ! in_array( $post->post_type, self::post_types(), true ) ) {
			self::remove_post( $post_id );
			return;
		}

		// Only publicly visible content. Private and draft posts belong to
		// their authors, and an index is the easiest place to leak them from.
		if ( 'publish' !== $post->post_status ) {
			self::remove_post( $post_id );
			return;
		}

		global $wpdb;

		$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'post_id'     => $post->ID,
				'post_type'   => $post->post_type,
				'post_date'   => $post->post_date,
				'title'       => self::clean( $post->post_title ),
				'search_text' => self::build_text( $post ),
				'indexed_at'  => current_time( 'mysql' ),
			)
		);

		WPSQR_Cache::flush();
	}

	public static function remove_post( $post_id ) {
		global $wpdb;

		$wpdb->delete( self::table(), array( 'post_id' => (int) $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Everything worth matching, as one blob.
	 *
	 * The title is repeated because MySQL's relevance scoring counts term
	 * frequency: a word appearing in the title several times over scores far
	 * above the same word buried in a paragraph, which is the ranking most
	 * people expect without having to ask for it.
	 */
	public static function build_text( $post ) {
		$parts = array(
			str_repeat( $post->post_title . ' ', 3 ),
			str_replace( '-', ' ', $post->post_name ),
			$post->post_excerpt,
			self::clean( $post->post_content ),
		);

		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$terms = get_the_terms( $post, $taxonomy );

			if ( is_array( $terms ) ) {
				$parts[] = implode( ' ', wp_list_pluck( $terms, 'name' ) );
			}
		}

		/**
		 * Anything else that should be searchable for this post — a custom
		 * field, an attached document's text, a list of alternative names.
		 *
		 * @param string[] $parts
		 * @param WP_Post  $post
		 */
		$parts = apply_filters( 'wpsqr_index_text_parts', $parts, $post );

		return self::clean( implode( ' ', $parts ) );
	}

	/** Strip everything that is markup rather than words. */
	public static function clean( $text ) {
		$text = (string) $text;

		// Block comments survive strip_tags and would otherwise fill the
		// index with the word "wp".
		$text = preg_replace( '/<!--.*?-->/s', ' ', $text );
		$text = strip_shortcodes( $text );
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text );

		return trim( $text );
	}

	/* ---- Rebuilding ----------------------------------------------------- */

	/** Start a full rebuild, processed in batches on cron. */
	public static function start_rebuild() {
		update_option(
			'wpsqr_reindex',
			array(
				'offset'  => 0,
				'total'   => self::countable(),
				'started' => current_time( 'mysql' ),
				'done'    => 0,
			),
			false
		);

		if ( ! wp_next_scheduled( self::REINDEX_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::REINDEX_HOOK );
		}
	}

	public static function countable() {
		global $wpdb;

		$types = self::post_types();

		if ( ! $types ) {
			return 0;
		}

		$in = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$in})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$types
			)
		);
	}

	/** One batch. Re-schedules itself until finished. */
	public static function run_batch() {
		$state = get_option( 'wpsqr_reindex', array() );

		if ( ! is_array( $state ) || ! isset( $state['offset'] ) ) {
			return;
		}

		$posts = get_posts(
			array(
				'post_type'              => self::post_types(),
				'post_status'            => 'publish',
				'posts_per_page'         => self::BATCH,
				'offset'                 => (int) $state['offset'],
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_term_cache' => true,
				'suppress_filters'       => true,
			)
		);

		foreach ( $posts as $post ) {
			self::index_post( $post->ID );
		}

		$state['offset'] += count( $posts );
		$state['done']    = $state['offset'];

		if ( count( $posts ) < self::BATCH ) {
			$state['finished'] = current_time( 'mysql' );
			update_option( 'wpsqr_reindex', $state, false );
			WPSQR_Cache::flush();
			return;
		}

		update_option( 'wpsqr_reindex', $state, false );

		wp_schedule_single_event( time() + 5, self::REINDEX_HOOK );
	}

	public static function stats() {
		global $wpdb;

		$table = self::table();

		return array(
			'rows'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ), // phpcs:ignore
			'expected'  => self::countable(),
			'fulltext'  => self::has_fulltext(),
			'rebuild'   => get_option( 'wpsqr_reindex', array() ),
			'last'      => $wpdb->get_var( "SELECT MAX(indexed_at) FROM {$table}" ), // phpcs:ignore
		);
	}

	public static function truncate() {
		global $wpdb;

		$wpdb->query( 'TRUNCATE TABLE ' . self::table() ); // phpcs:ignore
	}
}
