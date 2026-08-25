<?php
/**
 * The usage scanner: the safety-critical heart of the plugin.
 *
 * Strategy
 * --------
 * A media file is considered USED if any reference to it is found anywhere we
 * look. We deliberately bias toward "used": a false "used" only keeps a file,
 * while a false "unused" could delete a file that is actually on the site.
 *
 * IMPORTANT accuracy rule: we NEVER treat an attachment's own data as usage of
 * itself. Attachment posts and their bookkeeping meta (_wp_attached_file,
 * _wp_attachment_metadata, backup sizes, optimiser data, alt text) all contain
 * the file's own name and every thumbnail size — indexing them would make every
 * image "match itself" and appear used. So attachment-owned rows are excluded.
 *
 * Where we look (on NON-attachment content):
 *   - post_content / post_excerpt of every post & page
 *   - post meta of non-attachment posts -> Beaver Builder (_fl_builder_data),
 *     featured images (_thumbnail_id), ACF & other custom fields, nav menus,
 *     Robo Gallery, other page-builder data
 *   - options -> site logo, site icon, theme mods, widgets, plugin settings
 *   - term meta & user meta
 *   - featured images, galleries, site logo & site icon (explicit)
 *   - (optional) active + child theme template/CSS/JS files
 *   - (optional) the Beaver Builder CSS cache in /uploads/bb-plugin/cache
 *
 * How we match: by FILENAME (page builders store the file URL) and by
 * ATTACHMENT ID. Every reference also records WHERE it was found so the report
 * can show the exact pages/settings a file is used in.
 *
 * The scan runs in batches driven by AJAX and is fully resumable: the index is
 * stored in a database table (append-only) and progress is saved after every
 * batch, so an interrupted scan continues where it left off.
 *
 * @package ACPS_Media_Cleanup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACPS_MC_Scanner {

	/** Ordered scan phases. */
	const STEPS = array(
		'index_posts',
		'index_postmeta',
		'index_options',
		'index_termmeta',
		'index_usermeta',
		'index_extras',
		'classify',
	);

	const KIND_URL = 0;
	const KIND_ID  = 1;

	/** Max source locations recorded/shown per file. */
	const MAX_SOURCES = 12;

	/**
	 * Attachment-owned meta keys that describe the file itself, never usage.
	 * (Belt-and-braces: we also exclude ALL meta on attachment posts.)
	 */
	const SELF_META_KEYS = array(
		'_wp_attached_file',
		'_wp_attachment_metadata',
		'_wp_attachment_backup_sizes',
		'_wp_attachment_image_alt',
	);

	/** @var ACPS_MC_Folders */
	protected $folders;

	public function __construct() {
		$this->folders = new ACPS_MC_Folders();
	}

	/* -----------------------------------------------------------------
	 * Index table
	 * ----------------------------------------------------------------- */

	public static function index_table() {
		global $wpdb;
		return $wpdb->prefix . 'acps_mc_index';
	}

	public static function install_index_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::index_table();
		$charset_collate = $wpdb->get_charset_collate();

		// Prefix-limited unique key keeps duplicate (reference, source) pairs out
		// while staying within InnoDB index length limits.
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			kind TINYINT UNSIGNED NOT NULL DEFAULT 0,
			needle VARCHAR(150) NOT NULL DEFAULT '',
			src_label VARCHAR(255) NOT NULL DEFAULT '',
			src_url VARCHAR(255) NOT NULL DEFAULT '',
			PRIMARY KEY (id),
			KEY lookup (kind, needle),
			UNIQUE KEY dedupe (kind, needle, src_label(80))
		) {$charset_collate};";

		dbDelta( $sql );
	}

	protected function ensure_index_table() {
		global $wpdb;
		$table = self::index_table();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			self::install_index_table();
		}
	}

	/* -----------------------------------------------------------------
	 * Public API
	 * ----------------------------------------------------------------- */

	public function is_in_progress() {
		$meta = get_option( ACPS_MC_OPT_SCANMETA, array() );
		return ! empty( $meta['in_progress'] );
	}

	/**
	 * Resume point saved from the last batch, or null.
	 *
	 * @return array|null array( 'step' => ..., 'offset' => ... )
	 */
	public function resume_point() {
		$meta = get_option( ACPS_MC_OPT_SCANMETA, array() );
		if ( empty( $meta['in_progress'] ) || empty( $meta['cursor'] ) ) {
			return null;
		}
		$c = $meta['cursor'];
		if ( empty( $c['step'] ) ) {
			return null;
		}
		return array( 'step' => $c['step'], 'offset' => (int) $c['offset'] );
	}

	/**
	 * Initialise a fresh scan (clears the index and previous results).
	 *
	 * @return array Scan meta.
	 */
	public function start() {
		global $wpdb;

		$this->ensure_index_table();
		$wpdb->query( 'TRUNCATE TABLE ' . self::index_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		update_option( ACPS_MC_OPT_RESULTS, array(), false );

		$totals = array(
			'index_posts'    => (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status NOT IN ('trash','auto-draft') AND post_type NOT IN ('revision','attachment')"
			),
			'index_postmeta' => (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type <> 'attachment'"
			),
			'index_options'  => (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name NOT LIKE '\_transient\_%' AND option_name NOT LIKE '\_site\_transient\_%'"
			),
			'index_termmeta' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->termmeta}" ),
			'index_usermeta' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->usermeta}" ),
			'index_extras'   => 1,
			'classify'       => (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status <> 'trash'"
			),
		);

		$settings = ACPS_MC_Settings::all();

		$meta = array(
			'time'        => time(),
			'in_progress' => true,
			'cursor'      => array( 'step' => self::STEPS[0], 'offset' => 0 ),
			'totals'      => $totals,
			'grand_total' => array_sum( $totals ),
			'batch_size'  => (int) $settings['batch_size'],
			'max_att_id'  => (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts} WHERE post_type='attachment'" ),
			'counts'      => array(
				'attachments'  => $totals['classify'],
				'used'         => 0,
				'unused'       => 0,
				'unused_bytes' => 0,
				'classified'   => 0,
			),
			'coverage'    => array(
				'theme_files'   => (bool) $settings['scan_theme_files'],
				'builder_cache' => (bool) $settings['scan_builder_cache'],
				'attached_used' => (bool) $settings['treat_attached_as_used'],
				'id_meta_used'  => (bool) $settings['treat_id_meta_as_used'],
			),
			'backend'     => $this->folders->backend_label(),
		);
		update_option( ACPS_MC_OPT_SCANMETA, $meta, false );

		return $meta;
	}

	/**
	 * Run one batch of one phase.
	 *
	 * @param string $step   Phase key.
	 * @param int    $offset Row offset within the phase.
	 * @return array Progress response (see progress_response()).
	 */
	public function run_step( $step, $offset ) {
		$offset   = max( 0, (int) $offset );
		$settings = ACPS_MC_Settings::all();
		$batch    = max( 5, (int) $settings['batch_size'] );

		if ( ! in_array( $step, self::STEPS, true ) ) {
			return $this->progress_response( '', 0, true, '', true );
		}

		$processed = 0;
		switch ( $step ) {
			case 'index_posts':
				$processed = $this->index_posts( $offset, $batch );
				break;
			case 'index_postmeta':
				$processed = $this->index_postmeta( $offset, $batch, $settings );
				break;
			case 'index_options':
				$processed = $this->index_options( $offset, $batch );
				break;
			case 'index_termmeta':
				$processed = $this->index_termmeta( $offset, $batch );
				break;
			case 'index_usermeta':
				$processed = $this->index_usermeta( $offset, $batch );
				break;
			case 'index_extras':
				$this->index_extras( $settings );
				$processed = 1;
				break;
			case 'classify':
				$processed = $this->classify_batch( $offset, $batch, $settings );
				break;
		}

		$meta       = get_option( ACPS_MC_OPT_SCANMETA, array() );
		$step_total = isset( $meta['totals'][ $step ] ) ? (int) $meta['totals'][ $step ] : 0;
		$next_offset = $offset + $processed;
		$step_done   = ( 0 === $processed ) || ( $next_offset >= $step_total );

		$next_step = $step;
		$all_done  = false;
		if ( $step_done ) {
			$next_step   = $this->next_step( $step );
			$next_offset = 0;
			if ( '' === $next_step ) {
				$all_done = true;
			}
		}

		// Persist resume cursor after every batch.
		$meta['cursor'] = $all_done
			? array( 'step' => '', 'offset' => 0 )
			: array( 'step' => $next_step, 'offset' => $next_offset );
		update_option( ACPS_MC_OPT_SCANMETA, $meta, false );

		if ( $all_done ) {
			$this->finalize();
		}

		return $this->progress_response( $step, $next_offset, $step_done, $next_step, $all_done );
	}

	protected function progress_response( $step, $next_offset, $step_done, $next_step, $all_done ) {
		$meta   = get_option( ACPS_MC_OPT_SCANMETA, array() );
		$totals = isset( $meta['totals'] ) ? $meta['totals'] : array();
		$grand  = isset( $meta['grand_total'] ) ? max( 1, (int) $meta['grand_total'] ) : 1;

		$current    = $all_done ? '' : ( $step_done ? $next_step : $step );
		$done_units = 0;
		if ( $all_done ) {
			$done_units = $grand;
		} else {
			foreach ( self::STEPS as $s ) {
				if ( $s === $current ) {
					break;
				}
				$done_units += isset( $totals[ $s ] ) ? (int) $totals[ $s ] : 0;
			}
			$done_units += (int) $next_offset;
		}

		$percent = (int) floor( min( 100, ( $done_units / $grand ) * 100 ) );

		return array(
			'step'        => $step,
			'next_offset' => (int) $next_offset,
			'step_done'   => (bool) $step_done,
			'next_step'   => $next_step,
			'all_done'    => (bool) $all_done,
			'percent'     => $all_done ? 100 : $percent,
			'label'       => $this->step_label( $current ),
			'counts'      => isset( $meta['counts'] ) ? $meta['counts'] : array(),
		);
	}

	protected function next_step( $step ) {
		$pos = array_search( $step, self::STEPS, true );
		if ( false === $pos || $pos + 1 >= count( self::STEPS ) ) {
			return '';
		}
		return self::STEPS[ $pos + 1 ];
	}

	protected function step_label( $step ) {
		$labels = array(
			'index_posts'    => __( 'Scanning page & post content…', 'acps-media-cleanup' ),
			'index_postmeta' => __( 'Scanning page-builder & custom field data…', 'acps-media-cleanup' ),
			'index_options'  => __( 'Scanning site options (logo, widgets, theme)…', 'acps-media-cleanup' ),
			'index_termmeta' => __( 'Scanning category / term data…', 'acps-media-cleanup' ),
			'index_usermeta' => __( 'Scanning user profile data…', 'acps-media-cleanup' ),
			'index_extras'   => __( 'Scanning theme files & featured images…', 'acps-media-cleanup' ),
			'classify'       => __( 'Checking each media file…', 'acps-media-cleanup' ),
			''               => __( 'Finished', 'acps-media-cleanup' ),
		);
		return isset( $labels[ $step ] ) ? $labels[ $step ] : '';
	}

	/* -----------------------------------------------------------------
	 * Reference collection + storage
	 * ----------------------------------------------------------------- */

	/**
	 * Collect media filenames and attachment IDs from a blob of text.
	 *
	 * @param string $text Haystack.
	 * @param array  $urls Set of stems (by reference).
	 * @param array  $ids  Set of ids (by reference).
	 */
	protected function collect( $text, &$urls, &$ids ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return;
		}

		// Filenames (any media extension).
		if ( preg_match_all(
			'#([\w~%\-.]+?)\.(jpe?g|png|gif|webp|avif|svg|bmp|ico|tiff?|heic|pdf|docx?|dotx?|pptx?|potx?|xlsx?|xltx?|csv|txt|rtf|zip|rar|7z|gz|mp4|m4v|mov|avi|wmv|flv|webm|mkv|mp3|m4a|wav|ogg|oga|aac|flac)#i',
			$text,
			$m
		) ) {
			foreach ( $m[1] as $name ) {
				$stem = $this->stem( $name );
				if ( '' !== $stem ) {
					$urls[ $stem ] = true;
				}
			}
		}

		// Attachment IDs from targeted, media-specific patterns.
		$id_patterns = array(
			'/wp-image-(\d+)/i',
			'/wp-att-(\d+)/i',
			'/data-attachment-id=["\']?(\d+)/i',
			'/data-id=["\']?(\d+)/i',
			'/"photo";s:\d+:"(\d+)"/',
			'/"photo";i:(\d+)/',
			'/"id";s:\d+:"(\d+)"/',
			'/"id";i:(\d+)/',
		);
		foreach ( $id_patterns as $pat ) {
			if ( preg_match_all( $pat, $text, $mm ) ) {
				foreach ( $mm[1] as $id ) {
					$ids[ (int) $id ] = true;
				}
			}
		}

		// Gallery shortcode id lists.
		if ( preg_match_all( '/\[gallery[^\]]*?ids=["\']?([0-9,\s]+)/i', $text, $g ) ) {
			foreach ( $g[1] as $list ) {
				foreach ( preg_split( '/[,\s]+/', $list, -1, PREG_SPLIT_NO_EMPTY ) as $id ) {
					$ids[ (int) $id ] = true;
				}
			}
		}
	}

	/**
	 * Record references extracted from one text blob, all attributed to a single
	 * source (label + optional url).
	 *
	 * @param array  $bucket Row accumulator (by reference).
	 * @param string $text   Haystack.
	 * @param string $label  Human source label.
	 * @param string $url    Optional edit/admin URL.
	 */
	protected function add_from_text( &$bucket, $text, $label, $url = '' ) {
		$urls = array();
		$ids  = array();
		$this->collect( $text, $urls, $ids );
		foreach ( array_keys( $urls ) as $stem ) {
			$this->push_row( $bucket, self::KIND_URL, (string) $stem, $label, $url );
		}
		foreach ( array_keys( $ids ) as $id ) {
			$this->push_row( $bucket, self::KIND_ID, (string) (int) $id, $label, $url );
		}
	}

	protected function push_row( &$bucket, $kind, $needle, $label, $url ) {
		$needle = substr( $needle, 0, 150 );
		$label  = substr( (string) $label, 0, 255 );
		$url    = substr( (string) $url, 0, 255 );
		$key    = $kind . '|' . $needle . '|' . substr( $label, 0, 80 );
		$bucket[ $key ] = array( $kind, $needle, $label, $url );
	}

	/**
	 * Bulk INSERT IGNORE a bucket of rows into the index table.
	 *
	 * @param array $bucket Rows keyed for de-duplication.
	 */
	protected function flush_rows( $bucket ) {
		global $wpdb;
		if ( empty( $bucket ) ) {
			return;
		}
		$table  = self::index_table();
		$chunks = array_chunk( array_values( $bucket ), 200 );
		foreach ( $chunks as $chunk ) {
			$values = array();
			$args   = array();
			foreach ( $chunk as $row ) {
				$values[] = '(%d,%s,%s,%s)';
				$args[]   = $row[0];
				$args[]   = $row[1];
				$args[]   = $row[2];
				$args[]   = $row[3];
			}
			$sql = "INSERT IGNORE INTO {$table} (kind, needle, src_label, src_url) VALUES " . implode( ',', $values );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( $sql, $args ) );
		}
	}

	/* -----------------------------------------------------------------
	 * Indexing phases
	 * ----------------------------------------------------------------- */

	protected function index_posts( $offset, $batch ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_type, post_content, post_excerpt FROM {$wpdb->posts}
				 WHERE post_status NOT IN ('trash','auto-draft')
				   AND post_type NOT IN ('revision','attachment')
				 ORDER BY ID LIMIT %d OFFSET %d",
				$batch,
				$offset
			),
			ARRAY_A
		);
		if ( ! $rows ) {
			return 0;
		}
		$bucket = array();
		foreach ( $rows as $r ) {
			$label = $this->post_label( $r['post_type'], $r['post_title'], (int) $r['ID'] );
			$url   = get_edit_post_link( (int) $r['ID'], 'raw' );
			$this->add_from_text( $bucket, $r['post_content'], $label, (string) $url );
			$this->add_from_text( $bucket, $r['post_excerpt'], $label, (string) $url );
		}
		$this->flush_rows( $bucket );
		return count( $rows );
	}

	protected function index_postmeta( $offset, $batch, $settings ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.post_id, pm.meta_key, pm.meta_value, p.post_title, p.post_type
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE p.post_type <> 'attachment'
				 ORDER BY pm.meta_id LIMIT %d OFFSET %d",
				$batch,
				$offset
			),
			ARRAY_A
		);
		if ( ! $rows ) {
			return 0;
		}

		$scan_meta = get_option( ACPS_MC_OPT_SCANMETA );
		$max_att   = ( is_array( $scan_meta ) && isset( $scan_meta['max_att_id'] ) ) ? (int) $scan_meta['max_att_id'] : 0;
		$bucket    = array();

		foreach ( $rows as $r ) {
			$key = $r['meta_key'];
			$val = $r['meta_value'];
			$pid = (int) $r['post_id'];

			// Skip attachment-owned bookkeeping keys (defensive; they live on
			// attachment posts which are already excluded by the JOIN).
			if ( in_array( $key, self::SELF_META_KEYS, true ) ) {
				continue;
			}

			// Featured image: labelled specially, points at the parent post.
			if ( '_thumbnail_id' === $key && is_numeric( $val ) ) {
				$label = $this->post_label( $r['post_type'], $r['post_title'], $pid );
				$this->push_row(
					$bucket,
					self::KIND_ID,
					(string) (int) $val,
					sprintf( __( 'Featured image of %s', 'acps-media-cleanup' ), $label ),
					(string) get_edit_post_link( $pid, 'raw' )
				);
				continue;
			}

			$label = $this->post_label( $r['post_type'], $r['post_title'], $pid );
			$url   = (string) get_edit_post_link( $pid, 'raw' );
			$this->add_from_text( $bucket, $val, $label, $url );

			// Custom field whose value is exactly a real attachment ID (ACF etc.).
			if ( ! empty( $settings['treat_id_meta_as_used'] )
				&& '' !== $key && '_' !== $key[0]
				&& is_numeric( $val ) && (string) (int) $val === trim( (string) $val )
				&& (int) $val > 0 && ( 0 === $max_att || (int) $val <= $max_att ) ) {
				$this->push_row(
					$bucket,
					self::KIND_ID,
					(string) (int) $val,
					sprintf( __( 'Custom field "%1$s" on %2$s', 'acps-media-cleanup' ), $key, $label ),
					$url
				);
			}
		}
		$this->flush_rows( $bucket );
		return count( $rows );
	}

	protected function index_options( $offset, $batch ) {
		global $wpdb;
		$skip = array( ACPS_MC_OPT_RESULTS, ACPS_MC_OPT_SCANMETA, ACPS_MC_TRANSIENT_INDEX );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options}
				 WHERE option_name NOT LIKE '\_transient\_%'
				   AND option_name NOT LIKE '\_site\_transient\_%'
				 ORDER BY option_id LIMIT %d OFFSET %d",
				$batch,
				$offset
			),
			ARRAY_A
		);
		if ( ! $rows ) {
			return 0;
		}
		$bucket = array();
		foreach ( $rows as $r ) {
			if ( in_array( $r['option_name'], $skip, true ) ) {
				continue;
			}
			list( $label, $url ) = $this->option_source( $r['option_name'] );
			$this->add_from_text( $bucket, $r['option_value'], $label, $url );
		}
		$this->flush_rows( $bucket );
		return count( $rows );
	}

	protected function index_termmeta( $offset, $batch ) {
		global $wpdb;
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->termmeta} ORDER BY meta_id LIMIT %d OFFSET %d",
				$batch,
				$offset
			)
		);
		if ( ! $rows ) {
			return 0;
		}
		$bucket = array();
		foreach ( $rows as $val ) {
			$this->add_from_text( $bucket, $val, __( 'Category / term settings', 'acps-media-cleanup' ), admin_url( 'edit-tags.php' ) );
		}
		$this->flush_rows( $bucket );
		return count( $rows );
	}

	protected function index_usermeta( $offset, $batch ) {
		global $wpdb;
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->usermeta} ORDER BY umeta_id LIMIT %d OFFSET %d",
				$batch,
				$offset
			)
		);
		if ( ! $rows ) {
			return 0;
		}
		$bucket = array();
		foreach ( $rows as $val ) {
			$this->add_from_text( $bucket, $val, __( 'User profile field', 'acps-media-cleanup' ), '' );
		}
		$this->flush_rows( $bucket );
		return count( $rows );
	}

	protected function index_extras( $settings ) {
		$bucket = array();

		$icon = (int) get_option( 'site_icon' );
		if ( $icon > 0 ) {
			$this->push_row( $bucket, self::KIND_ID, (string) $icon, __( 'Site icon', 'acps-media-cleanup' ), admin_url( 'options-general.php' ) );
		}

		$logo = (int) get_theme_mod( 'custom_logo' );
		if ( $logo > 0 ) {
			$this->push_row( $bucket, self::KIND_ID, (string) $logo, __( 'Site logo', 'acps-media-cleanup' ), admin_url( 'customize.php' ) );
		}
		foreach ( array( 'header_image', 'background_image' ) as $mod ) {
			$val = get_theme_mod( $mod );
			if ( is_string( $val ) && '' !== $val ) {
				$this->add_from_text( $bucket, $val, __( 'Theme customizer', 'acps-media-cleanup' ), admin_url( 'customize.php' ) );
			}
		}

		if ( ! empty( $settings['scan_theme_files'] ) ) {
			$dirs = array_unique( array( get_stylesheet_directory(), get_template_directory() ) );
			foreach ( $dirs as $dir ) {
				$this->scan_dir_files( $bucket, $dir, array( 'php', 'css', 'js', 'html', 'twig', 'json' ), 400, __( 'Theme file', 'acps-media-cleanup' ) );
			}
		}

		if ( ! empty( $settings['scan_builder_cache'] ) ) {
			$uploads = wp_get_upload_dir();
			if ( ! empty( $uploads['basedir'] ) ) {
				$this->scan_dir_files( $bucket, trailingslashit( $uploads['basedir'] ) . 'bb-plugin/cache', array( 'css', 'js' ), 2000, __( 'Beaver Builder cache', 'acps-media-cleanup' ) );
			}
		}

		$this->flush_rows( $bucket );
	}

	protected function scan_dir_files( &$bucket, $dir, $exts, $file_limit, $label_prefix ) {
		if ( ! is_dir( $dir ) || ! is_readable( $dir ) ) {
			return;
		}
		$count = 0;
		try {
			$it = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
			);
		} catch ( Exception $e ) {
			return;
		}
		foreach ( $it as $file ) {
			if ( $count >= $file_limit ) {
				break;
			}
			if ( ! $file->isFile() ) {
				continue;
			}
			$ext = strtolower( pathinfo( $file->getFilename(), PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, $exts, true ) ) {
				continue;
			}
			if ( $file->getSize() > 2 * 1024 * 1024 ) {
				continue;
			}
			$contents = @file_get_contents( $file->getPathname() );
			if ( false !== $contents ) {
				$this->add_from_text( $bucket, $contents, $label_prefix . ': ' . $file->getFilename(), '' );
				$count++;
			}
		}
	}

	/* -----------------------------------------------------------------
	 * Labels
	 * ----------------------------------------------------------------- */

	protected function post_label( $post_type, $title, $id ) {
		$obj  = get_post_type_object( $post_type );
		$name = ( $obj && ! empty( $obj->labels->singular_name ) ) ? $obj->labels->singular_name : ucfirst( (string) $post_type );
		$t    = trim( (string) $title );
		if ( '' === $t ) {
			$t = sprintf( __( '(no title) #%d', 'acps-media-cleanup' ), (int) $id );
		}
		return $name . ': ' . $t;
	}

	protected function option_source( $name ) {
		$url = '';
		if ( 0 === strpos( $name, 'widget_' ) || 'sidebars_widgets' === $name ) {
			$label = __( 'Widget', 'acps-media-cleanup' );
			$url   = admin_url( 'widgets.php' );
		} elseif ( 0 === strpos( $name, 'theme_mods_' ) || in_array( $name, array( 'site_logo', 'custom_logo' ), true ) ) {
			$label = __( 'Theme / customizer setting', 'acps-media-cleanup' );
			$url   = admin_url( 'customize.php' );
		} elseif ( 'site_icon' === $name ) {
			$label = __( 'Site icon', 'acps-media-cleanup' );
		} else {
			$label = sprintf( __( 'Site option: %s', 'acps-media-cleanup' ), $name );
		}
		return array( $label, $url );
	}

	/**
	 * Normalise a filename to a comparable stem.
	 *
	 * @param string $filename Basename or bare name.
	 * @return string
	 */
	public function stem( $filename ) {
		$b = strtolower( wp_basename( (string) $filename ) );
		$b = preg_replace( '/\.[a-z0-9]+$/', '', $b );   // extension
		$b = preg_replace( '/-\d+x\d+$/', '', $b );       // -300x200
		$b = preg_replace( '/-scaled$/', '', $b );        // -scaled
		$b = preg_replace( '/-rotated$/', '', $b );       // -rotated
		$b = preg_replace( '/-e\d{10,}$/', '', $b );      // edited copy -e1699999999
		return $b;
	}

	/* -----------------------------------------------------------------
	 * Classification
	 * ----------------------------------------------------------------- */

	protected function classify_batch( $offset, $batch, $settings ) {
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_type = 'attachment' AND post_status <> 'trash'
				 ORDER BY ID LIMIT %d OFFSET %d",
				$batch,
				$offset
			)
		);
		if ( ! $ids ) {
			return 0;
		}

		$results = get_option( ACPS_MC_OPT_RESULTS, array() );
		if ( ! is_array( $results ) ) {
			$results = array();
		}
		$meta = get_option( ACPS_MC_OPT_SCANMETA, array() );

		foreach ( $ids as $id ) {
			$id             = (int) $id;
			$results[ $id ] = $this->classify_one( $id, $settings );
		}

		// Recompute counts from the full results set so a re-run batch (after an
		// interrupted/resumed scan) can never double-count.
		$used = 0; $unused = 0; $bytes = 0;
		foreach ( $results as $r ) {
			if ( ! empty( $r['used'] ) ) {
				$used++;
			} else {
				$unused++;
				$bytes += isset( $r['size'] ) ? (int) $r['size'] : 0;
			}
		}
		$meta['counts']['used']         = $used;
		$meta['counts']['unused']       = $unused;
		$meta['counts']['unused_bytes'] = $bytes;
		$meta['counts']['classified']   = count( $results );

		update_option( ACPS_MC_OPT_RESULTS, $results, false );
		update_option( ACPS_MC_OPT_SCANMETA, $meta, false );

		return count( $ids );
	}

	/**
	 * Look up where a set of stems / an id are referenced.
	 *
	 * @param string[] $stems Candidate filename stems.
	 * @param int      $id    Attachment id.
	 * @return array array( 'used' => bool, 'locations' => array(array(label,url)) )
	 */
	protected function lookup( $stems, $id ) {
		global $wpdb;
		$table     = self::index_table();
		$locations = array();

		$stems = array_values( array_unique( array_filter( $stems, 'strlen' ) ) );
		if ( $stems ) {
			$place = implode( ',', array_fill( 0, count( $stems ), '%s' ) );
			$sql   = "SELECT src_label, src_url FROM {$table} WHERE kind = %d AND needle IN ($place) LIMIT %d";
			$args  = array_merge( array( self::KIND_URL ), $stems, array( self::MAX_SOURCES ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
			foreach ( (array) $rows as $r ) {
				$locations[] = array( 'label' => $r['src_label'], 'url' => $r['src_url'] );
			}
		}

		if ( count( $locations ) < self::MAX_SOURCES ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT src_label, src_url FROM {$table} WHERE kind = %d AND needle = %s LIMIT %d",
					self::KIND_ID,
					(string) $id,
					self::MAX_SOURCES
				),
				ARRAY_A
			);
			foreach ( (array) $rows as $r ) {
				$locations[] = array( 'label' => $r['src_label'], 'url' => $r['src_url'] );
			}
		}

		// De-duplicate by label, cap.
		$seen = array();
		$out  = array();
		foreach ( $locations as $loc ) {
			if ( isset( $seen[ $loc['label'] ] ) ) {
				continue;
			}
			$seen[ $loc['label'] ] = true;
			$out[]                 = $loc;
			if ( count( $out ) >= self::MAX_SOURCES ) {
				break;
			}
		}

		return array( 'used' => ! empty( $out ), 'locations' => $out );
	}

	/**
	 * Decide whether one attachment is used, and where.
	 *
	 * @param int   $id       Attachment ID.
	 * @param array $settings Settings.
	 * @return array Result row.
	 */
	public function classify_one( $id, $settings ) {
		$file = get_post_meta( $id, '_wp_attached_file', true );
		$path = get_attached_file( $id );
		$url  = wp_get_attachment_url( $id );
		$mime = get_post_mime_type( $id );
		$post = get_post( $id );

		$basename = $file ? wp_basename( $file ) : ( $path ? wp_basename( $path ) : '' );
		$ext      = strtolower( pathinfo( $basename, PATHINFO_EXTENSION ) );

		$stems = array();
		if ( $basename ) {
			$stems[] = $this->stem( $basename );
		}
		$att_meta = wp_get_attachment_metadata( $id );
		if ( is_array( $att_meta ) ) {
			if ( ! empty( $att_meta['file'] ) ) {
				$stems[] = $this->stem( $att_meta['file'] );
			}
			if ( ! empty( $att_meta['original_image'] ) ) {
				$stems[] = $this->stem( $att_meta['original_image'] );
			}
			if ( ! empty( $att_meta['sizes'] ) && is_array( $att_meta['sizes'] ) ) {
				foreach ( $att_meta['sizes'] as $s ) {
					if ( ! empty( $s['file'] ) ) {
						$stems[] = $this->stem( $s['file'] );
					}
				}
			}
		}

		$found     = $this->lookup( $stems, $id );
		$used      = $found['used'];
		$locations = $found['locations'];

		// Conservative safety net: attached to a live post.
		if ( ! $used && ! empty( $settings['treat_attached_as_used'] ) && $post && $post->post_parent > 0 ) {
			$parent = get_post( $post->post_parent );
			if ( $parent && 'trash' !== $parent->post_status ) {
				$used        = true;
				$locations[] = array(
					'label' => sprintf( __( 'Attached to: %s', 'acps-media-cleanup' ), get_the_title( $parent ) ),
					'url'   => (string) get_edit_post_link( $parent->ID, 'raw' ),
				);
			}
		}

		$size = $this->attachment_disk_size( $path, $att_meta );

		return array(
			'id'        => $id,
			'file'      => (string) $file,
			'url'       => (string) $url,
			'filename'  => $basename,
			'ext'       => $ext,
			'mime'      => (string) $mime,
			'date'      => $post ? get_the_date( 'Y-m-d', $post ) : '',
			'title'     => $post ? $post->post_title : '',
			'size'      => (int) $size,
			'used'      => (bool) $used,
			'locations' => array_slice( $locations, 0, self::MAX_SOURCES ),
		);
	}

	protected function attachment_disk_size( $path, $att_meta ) {
		$total = 0;
		if ( $path && file_exists( $path ) ) {
			$total += (int) filesize( $path );
			$dir    = trailingslashit( dirname( $path ) );
			if ( is_array( $att_meta ) && ! empty( $att_meta['sizes'] ) ) {
				foreach ( $att_meta['sizes'] as $s ) {
					if ( ! empty( $s['file'] ) && file_exists( $dir . $s['file'] ) ) {
						$total += (int) filesize( $dir . $s['file'] );
					}
				}
			}
			if ( is_array( $att_meta ) && ! empty( $att_meta['original_image'] ) && file_exists( $dir . $att_meta['original_image'] ) ) {
				$total += (int) filesize( $dir . $att_meta['original_image'] );
			}
		}
		return $total;
	}

	protected function finalize() {
		global $wpdb;
		$meta                = get_option( ACPS_MC_OPT_SCANMETA, array() );
		$meta['in_progress'] = false;
		$meta['cursor']      = array( 'step' => '', 'offset' => 0 );
		$meta['time']        = time();
		update_option( ACPS_MC_OPT_SCANMETA, $meta, false );

		// The index table is only needed during a scan; free it afterwards.
		$wpdb->query( 'TRUNCATE TABLE ' . self::index_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
