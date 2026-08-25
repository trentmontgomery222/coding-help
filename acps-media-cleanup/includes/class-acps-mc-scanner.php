<?php
/**
 * The usage scanner: the safety-critical heart of the plugin.
 *
 * Strategy
 * --------
 * A media file is considered USED if any reference to it is found anywhere we
 * look. We deliberately bias toward "used": a false "used" only keeps a file,
 * while a false "unused" could delete a file that is actually on the site. So
 * every ambiguous case resolves to "used".
 *
 * Where we look (the "haystack"):
 *   - post_content / post_excerpt of every post & page (classic editor, blocks)
 *   - EVERY postmeta value  -> covers Beaver Builder (_fl_builder_data),
 *     featured images (_thumbnail_id), ACF, Yoast OG images, nav-menu items,
 *     Robo Gallery, page-builder data, etc.
 *   - EVERY option value    -> site logo, site icon, theme mods, widgets,
 *     plugin settings
 *   - term meta & user meta
 *   - featured images, site icon and custom logo (explicit)
 *   - (optional) active theme + child theme template/CSS/JS files
 *   - (optional) the Beaver Builder CSS cache in /uploads/bb-plugin/cache
 *
 * How we match:
 *   - By FILENAME. Page builders store the file URL (Beaver Builder saves
 *     `photo_src`), so matching the base filename catches builder usage even
 *     when it lives in serialized meta. Resized variants, `-scaled` and edited
 *     copies all normalise to the same stem.
 *   - By ATTACHMENT ID for the common ID-only references (wp-image-ID,
 *     [gallery ids], data-attachment-id, Beaver Builder photo IDs, featured
 *     images, and — optionally — custom fields whose value is an attachment ID).
 *
 * The scan runs in small batches driven by AJAX so it never times out.
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

	/** @var ACPS_MC_Folders */
	protected $folders;

	public function __construct() {
		$this->folders = new ACPS_MC_Folders();
	}

	/* -----------------------------------------------------------------
	 * Index (used-reference set) storage
	 * ----------------------------------------------------------------- */

	protected function get_index() {
		$idx = get_option( ACPS_MC_TRANSIENT_INDEX, null );
		if ( ! is_array( $idx ) || ! isset( $idx['urls'], $idx['ids'] ) ) {
			$idx = array( 'urls' => array(), 'ids' => array() );
		}
		return $idx;
	}

	protected function save_index( $idx ) {
		update_option( ACPS_MC_TRANSIENT_INDEX, $idx, false );
	}

	/* -----------------------------------------------------------------
	 * Public API
	 * ----------------------------------------------------------------- */

	/**
	 * Initialise a fresh scan.
	 *
	 * @return array Scan meta describing totals per phase.
	 */
	public function start() {
		global $wpdb;

		// Reset index and results.
		$this->save_index( array( 'urls' => array(), 'ids' => array() ) );
		update_option( ACPS_MC_OPT_RESULTS, array(), false );

		$totals = array(
			'index_posts'    => (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status NOT IN ('trash','auto-draft') AND post_type NOT IN ('revision','attachment')"
			),
			'index_postmeta' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta}" ),
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
			'totals'      => $totals,
			'grand_total' => array_sum( $totals ),
			'batch_size'  => (int) $settings['batch_size'],
			'counts'      => array(
				'attachments'  => $totals['classify'],
				'used'         => 0,
				'unused'       => 0,
				'unused_bytes' => 0,
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
	 * @return array {
	 *     @type string $step        Phase just run.
	 *     @type int    $next_offset Offset for the next call of this phase.
	 *     @type bool   $step_done   True when this phase is finished.
	 *     @type string $next_step   Phase to run next ('' when all done).
	 *     @type bool   $all_done    True when the whole scan is finished.
	 *     @type int    $percent     Overall percentage complete.
	 *     @type string $label       Human label for the current phase.
	 *     @type array  $counts      Running counts (after classify).
	 * }
	 */
	public function run_step( $step, $offset ) {
		$offset   = max( 0, (int) $offset );
		$settings = ACPS_MC_Settings::all();
		$batch    = max( 5, (int) $settings['batch_size'] );

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
			default:
				return $this->progress_response( '', 0, true, '', true );
		}

		$meta        = get_option( ACPS_MC_OPT_SCANMETA, array() );
		$step_total  = isset( $meta['totals'][ $step ] ) ? (int) $meta['totals'][ $step ] : 0;
		$next_offset = $offset + $processed;
		$step_done   = ( 0 === $processed ) || ( $next_offset >= $step_total );

		$next_step = $step;
		$all_done  = false;
		if ( $step_done ) {
			$next_step   = $this->next_step( $step );
			$next_offset = 0;
			if ( '' === $next_step ) {
				$all_done = true;
				$this->finalize();
			}
		}

		return $this->progress_response( $step, $next_offset, $step_done, $next_step, $all_done );
	}

	/**
	 * Build the response with overall progress percentage.
	 */
	protected function progress_response( $step, $next_offset, $step_done, $next_step, $all_done ) {
		$meta   = get_option( ACPS_MC_OPT_SCANMETA, array() );
		$totals = isset( $meta['totals'] ) ? $meta['totals'] : array();
		$grand  = isset( $meta['grand_total'] ) ? max( 1, (int) $meta['grand_total'] ) : 1;

		// Units completed = all fully-finished phases + progress within current.
		$done_units = 0;
		$reached    = false;
		foreach ( self::STEPS as $s ) {
			if ( $all_done ) {
				$done_units = $grand;
				break;
			}
			if ( $s === ( $step_done ? $next_step : $step ) ) {
				$reached = true;
			}
			if ( ! $reached ) {
				$done_units += isset( $totals[ $s ] ) ? (int) $totals[ $s ] : 0;
			}
		}
		if ( ! $all_done ) {
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
			'label'       => $this->step_label( $all_done ? '' : ( $step_done ? $next_step : $step ) ),
			'counts'      => isset( $meta['counts'] ) ? $meta['counts'] : array(),
		);
	}

	protected function next_step( $step ) {
		$keys = self::STEPS;
		$pos  = array_search( $step, $keys, true );
		if ( false === $pos || $pos + 1 >= count( $keys ) ) {
			return '';
		}
		return $keys[ $pos + 1 ];
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
	 * Indexing phases
	 * ----------------------------------------------------------------- */

	protected function index_posts( $offset, $batch ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_content, post_excerpt FROM {$wpdb->posts}
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
		$idx = $this->get_index();
		foreach ( $rows as $r ) {
			$this->extract( $idx, $r['post_content'] );
			$this->extract( $idx, $r['post_excerpt'] );
		}
		$this->save_index( $idx );
		return count( $rows );
	}

	protected function index_postmeta( $offset, $batch, $settings ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_key, meta_value FROM {$wpdb->postmeta} ORDER BY meta_id LIMIT %d OFFSET %d",
				$batch,
				$offset
			),
			ARRAY_A
		);
		if ( ! $rows ) {
			return 0;
		}
		$idx = $this->get_index();
		foreach ( $rows as $r ) {
			$key = $r['meta_key'];
			$val = $r['meta_value'];

			$this->extract( $idx, $val );

			// Featured images: the value is the attachment ID.
			if ( '_thumbnail_id' === $key && is_numeric( $val ) ) {
				$idx['ids'][ (int) $val ] = true;
			}

			// Custom fields whose value is exactly an attachment ID (ACF etc.).
			if ( ! empty( $settings['treat_id_meta_as_used'] )
				&& '' !== $key && '_' !== $key[0]
				&& is_numeric( $val ) && (string) (int) $val === trim( (string) $val )
				&& (int) $val > 0 ) {
				$idx['ids'][ (int) $val ] = true;
			}
		}
		$this->save_index( $idx );
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
		$idx = $this->get_index();
		foreach ( $rows as $r ) {
			if ( in_array( $r['option_name'], $skip, true ) ) {
				continue;
			}
			$this->extract( $idx, $r['option_value'] );
		}
		$this->save_index( $idx );
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
		$idx = $this->get_index();
		foreach ( $rows as $val ) {
			$this->extract( $idx, $val );
		}
		$this->save_index( $idx );
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
		$idx = $this->get_index();
		foreach ( $rows as $val ) {
			$this->extract( $idx, $val );
		}
		$this->save_index( $idx );
		return count( $rows );
	}

	/**
	 * One-off extras: explicit logo/icon, and (optionally) theme files and the
	 * Beaver Builder CSS cache.
	 */
	protected function index_extras( $settings ) {
		$idx = $this->get_index();

		// Site icon.
		$icon = (int) get_option( 'site_icon' );
		if ( $icon > 0 ) {
			$idx['ids'][ $icon ] = true;
		}

		// Custom logo + header/background images (current theme).
		$logo = (int) get_theme_mod( 'custom_logo' );
		if ( $logo > 0 ) {
			$idx['ids'][ $logo ] = true;
		}
		foreach ( array( 'header_image', 'background_image' ) as $mod ) {
			$val = get_theme_mod( $mod );
			if ( is_string( $val ) && '' !== $val ) {
				$this->extract( $idx, $val );
			}
		}

		// Theme files.
		if ( ! empty( $settings['scan_theme_files'] ) ) {
			$dirs = array_unique( array( get_stylesheet_directory(), get_template_directory() ) );
			foreach ( $dirs as $dir ) {
				$this->scan_dir_files( $idx, $dir, array( 'php', 'css', 'js', 'html', 'twig', 'json' ), 400 );
			}
		}

		// Beaver Builder (and generic) cache in uploads.
		if ( ! empty( $settings['scan_builder_cache'] ) ) {
			$uploads = wp_get_upload_dir();
			if ( ! empty( $uploads['basedir'] ) ) {
				$this->scan_dir_files( $idx, trailingslashit( $uploads['basedir'] ) . 'bb-plugin/cache', array( 'css', 'js' ), 2000 );
			}
		}

		$this->save_index( $idx );
	}

	/**
	 * Read text files from a directory (recursively, bounded) and extract
	 * media references from them.
	 *
	 * @param array  $idx        Index (by reference).
	 * @param string $dir        Directory.
	 * @param array  $exts       Allowed extensions.
	 * @param int    $file_limit Max files to read.
	 */
	protected function scan_dir_files( &$idx, $dir, $exts, $file_limit ) {
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
				continue; // Skip very large files.
			}
			$contents = @file_get_contents( $file->getPathname() );
			if ( false !== $contents ) {
				$this->extract( $idx, $contents );
				$count++;
			}
		}
	}

	/* -----------------------------------------------------------------
	 * Extraction
	 * ----------------------------------------------------------------- */

	/**
	 * Extract media filenames and attachment IDs from a blob of text and add
	 * them to the index.
	 *
	 * @param array  $idx  Index (by reference).
	 * @param string $text Haystack.
	 */
	protected function extract( &$idx, $text ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return;
		}

		// --- Filenames (any media extension). ---
		if ( preg_match_all(
			'#([\w~%\-.]+?)\.(jpe?g|png|gif|webp|avif|svg|bmp|ico|tiff?|heic|pdf|docx?|dotx?|pptx?|potx?|xlsx?|xltx?|csv|txt|rtf|zip|rar|7z|gz|mp4|m4v|mov|avi|wmv|flv|webm|mkv|mp3|m4a|wav|ogg|oga|aac|flac)#i',
			$text,
			$m
		) ) {
			foreach ( $m[1] as $name ) {
				$stem = $this->stem( $name );
				if ( '' !== $stem ) {
					$idx['urls'][ $stem ] = true;
				}
			}
		}

		// --- Attachment IDs from targeted, media-specific patterns. ---
		$id_patterns = array(
			'/wp-image-(\d+)/i',
			'/wp-att-(\d+)/i',
			'/data-attachment-id=["\']?(\d+)/i',
			'/data-id=["\']?(\d+)/i',
			'/"photo";s:\d+:"(\d+)"/',   // Beaver Builder (serialized string).
			'/"photo";i:(\d+)/',          // Beaver Builder (serialized int).
			'/"id";s:\d+:"(\d+)"/',      // Serialized media reference.
			'/"id";i:(\d+)/',             // Serialized media reference.
		);
		foreach ( $id_patterns as $pat ) {
			if ( preg_match_all( $pat, $text, $mm ) ) {
				foreach ( $mm[1] as $id ) {
					$idx['ids'][ (int) $id ] = true;
				}
			}
		}

		// --- Gallery shortcode id lists: [gallery ids="1,2,3"]. ---
		if ( preg_match_all( '/\[gallery[^\]]*?ids=["\']?([0-9,\s]+)/i', $text, $g ) ) {
			foreach ( $g[1] as $list ) {
				foreach ( preg_split( '/[,\s]+/', $list, -1, PREG_SPLIT_NO_EMPTY ) as $id ) {
					$idx['ids'][ (int) $id ] = true;
				}
			}
		}
	}

	/**
	 * Normalise a filename to a comparable stem: lowercase, no extension, no
	 * size suffix (-300x200), no -scaled / -rotated / edited (-eNNNN) suffix.
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

		$index    = $this->get_index();
		$results  = get_option( ACPS_MC_OPT_RESULTS, array() );
		if ( ! is_array( $results ) ) {
			$results = array();
		}
		$meta = get_option( ACPS_MC_OPT_SCANMETA, array() );

		foreach ( $ids as $id ) {
			$id  = (int) $id;
			$row = $this->classify_one( $id, $index, $settings );
			$results[ $id ] = $row;

			if ( $row['used'] ) {
				$meta['counts']['used']++;
			} else {
				$meta['counts']['unused']++;
				$meta['counts']['unused_bytes'] += (int) $row['size'];
			}
		}

		update_option( ACPS_MC_OPT_RESULTS, $results, false );
		update_option( ACPS_MC_OPT_SCANMETA, $meta, false );

		return count( $ids );
	}

	/**
	 * Decide whether one attachment is used, and why.
	 *
	 * @param int   $id       Attachment ID.
	 * @param array $index    Usage index.
	 * @param array $settings Settings.
	 * @return array Result row.
	 */
	public function classify_one( $id, $index, $settings ) {
		$file = get_post_meta( $id, '_wp_attached_file', true );
		$path = get_attached_file( $id );
		$url  = wp_get_attachment_url( $id );
		$mime = get_post_mime_type( $id );
		$post = get_post( $id );

		$basename = $file ? wp_basename( $file ) : ( $path ? wp_basename( $path ) : '' );
		$ext      = strtolower( pathinfo( $basename, PATHINFO_EXTENSION ) );

		// Build this attachment's candidate stems (main file + all sizes + original).
		$stems = array();
		if ( $basename ) {
			$stems[ $this->stem( $basename ) ] = true;
		}
		$att_meta = wp_get_attachment_metadata( $id );
		if ( is_array( $att_meta ) ) {
			if ( ! empty( $att_meta['file'] ) ) {
				$stems[ $this->stem( $att_meta['file'] ) ] = true;
			}
			if ( ! empty( $att_meta['original_image'] ) ) {
				$stems[ $this->stem( $att_meta['original_image'] ) ] = true;
			}
			if ( ! empty( $att_meta['sizes'] ) && is_array( $att_meta['sizes'] ) ) {
				foreach ( $att_meta['sizes'] as $s ) {
					if ( ! empty( $s['file'] ) ) {
						$stems[ $this->stem( $s['file'] ) ] = true;
					}
				}
			}
		}

		$used   = false;
		$reason = '';

		// 1) ID reference (featured image, gallery, builder, wp-image-ID, ...).
		if ( isset( $index['ids'][ $id ] ) ) {
			$used   = true;
			$reason = __( 'Referenced by ID (featured image, gallery, or builder)', 'acps-media-cleanup' );
		}

		// 2) Filename / URL reference anywhere we scanned.
		if ( ! $used ) {
			foreach ( array_keys( $stems ) as $stem ) {
				if ( '' !== $stem && isset( $index['urls'][ $stem ] ) ) {
					$used   = true;
					$reason = __( 'Referenced by URL / filename in content, a builder, or options', 'acps-media-cleanup' );
					break;
				}
			}
		}

		// 3) Attached to a live post (conservative safety net).
		if ( ! $used && ! empty( $settings['treat_attached_as_used'] ) && $post && $post->post_parent > 0 ) {
			$parent = get_post( $post->post_parent );
			if ( $parent && 'trash' !== $parent->post_status ) {
				$used   = true;
				$reason = sprintf(
					/* translators: %d: parent post ID */
					__( 'Attached to post #%d', 'acps-media-cleanup' ),
					(int) $post->post_parent
				);
			}
		}

		$size = $this->attachment_disk_size( $path, $att_meta );

		return array(
			'id'       => $id,
			'file'     => (string) $file,
			'url'      => (string) $url,
			'filename' => $basename,
			'ext'      => $ext,
			'mime'     => (string) $mime,
			'date'     => $post ? get_the_date( 'Y-m-d', $post ) : '',
			'title'    => $post ? $post->post_title : '',
			'size'     => (int) $size,
			'used'     => (bool) $used,
			'reason'   => $reason,
		);
	}

	/**
	 * Total disk space used by an attachment (original + every generated size).
	 *
	 * @param string     $path     Absolute path to original.
	 * @param array|bool $att_meta Attachment metadata.
	 * @return int Bytes.
	 */
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

	/**
	 * Finish the scan: record counts and free the index.
	 */
	protected function finalize() {
		$meta                = get_option( ACPS_MC_OPT_SCANMETA, array() );
		$meta['in_progress'] = false;
		$meta['time']        = time();
		update_option( ACPS_MC_OPT_SCANMETA, $meta, false );

		// Index is no longer needed once every attachment is classified.
		delete_option( ACPS_MC_TRANSIENT_INDEX );
	}
}
