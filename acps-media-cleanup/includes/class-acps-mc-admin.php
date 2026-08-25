<?php
/**
 * Admin menu, pages and asset loading.
 *
 * @package ACPS_Media_Cleanup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACPS_MC_Admin {

	const MENU_SLUG = 'acps-media-cleanup';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_acps_mc_save_settings', array( $this, 'save_settings' ) );
	}

	public function register_menu() {
		$hook = add_menu_page(
			__( 'Unused Media Cleanup', 'acps-media-cleanup' ),
			__( 'Media Cleanup', 'acps-media-cleanup' ),
			ACPS_MC_CAP,
			self::MENU_SLUG,
			array( $this, 'render_page' ),
			'dashicons-trash',
			26
		);

		unset( $hook );
	}

	public function enqueue( $hook ) {
		if ( false === strpos( (string) $hook, self::MENU_SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'acps-mc-admin',
			ACPS_MC_URL . 'assets/admin.css',
			array(),
			ACPS_MC_VERSION
		);

		wp_enqueue_script(
			'acps-mc-admin',
			ACPS_MC_URL . 'assets/admin.js',
			array( 'jquery' ),
			ACPS_MC_VERSION,
			true
		);

		$settings = ACPS_MC_Settings::all();

		wp_localize_script(
			'acps-mc-admin',
			'ACPS_MC',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'acps_mc' ),
				'deleteMode'   => $settings['delete_mode'],
				'requireAck'   => (int) $settings['require_backup_ack'],
				'protectDays'  => (int) $settings['protect_recent_days'],
				'i18n'         => array(
					'confirmTrash'     => __( 'Move the selected files to Trash? They stay on disk and can be restored from the Trash tab.', 'acps-media-cleanup' ),
					'confirmPermanent' => __( 'PERMANENTLY delete the selected files? This cannot be undone.', 'acps-media-cleanup' ),
					'ackRequired'      => __( 'Please tick "I have a recent backup" before deleting.', 'acps-media-cleanup' ),
					'noneSelected'     => __( 'No files selected.', 'acps-media-cleanup' ),
					'scanning'         => __( 'Scanning…', 'acps-media-cleanup' ),
					'resuming'         => __( 'Resuming…', 'acps-media-cleanup' ),
					'done'             => __( 'Scan complete', 'acps-media-cleanup' ),
					'usedIn'           => __( 'Used in', 'acps-media-cleanup' ),
					'notFound'         => __( 'Not found anywhere scanned', 'acps-media-cleanup' ),
					'more'             => __( 'more', 'acps-media-cleanup' ),
					'checked'          => __( 'checked', 'acps-media-cleanup' ),
					'used'             => __( 'Used', 'acps-media-cleanup' ),
					'unused'           => __( 'Unused', 'acps-media-cleanup' ),
					'restore'          => __( 'Restore', 'acps-media-cleanup' ),
					'deleteForever'    => __( 'Delete forever', 'acps-media-cleanup' ),
					'protect'          => __( 'Protect', 'acps-media-cleanup' ),
					'protected'        => __( 'Protected', 'acps-media-cleanup' ),
					'noFolderFiles'    => __( 'No unused files in this folder. 🎉', 'acps-media-cleanup' ),
					'workingError'     => __( 'Something went wrong. Please reload and try again.', 'acps-media-cleanup' ),
				),
			)
		);
	}

	/**
	 * Build a URL to one of our tabs. Robust in every context (including AJAX,
	 * where menu_page_url() may not be populated).
	 *
	 * @param string $tab Tab key.
	 * @return string
	 */
	public static function page_url( $tab = '' ) {
		$args = array( 'page' => self::MENU_SLUG );
		if ( $tab ) {
			$args['tab'] = $tab;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	protected function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'scan';
		if ( ! in_array( $tab, array( 'scan', 'folders', 'trash', 'settings' ), true ) ) {
			$tab = 'scan';
		}
		return $tab;
	}

	public function render_page() {
		if ( ! current_user_can( ACPS_MC_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'acps-media-cleanup' ) );
		}
		$tab      = $this->current_tab();
		$base_url = self::page_url();
		?>
		<div class="wrap acps-mc">
			<h1><span class="dashicons dashicons-trash"></span> <?php esc_html_e( 'Unused Media Cleanup', 'acps-media-cleanup' ); ?></h1>
			<p class="acps-mc-tagline"><?php esc_html_e( 'Find media library files that are not used anywhere on your site, and clean them up safely — folder by folder.', 'acps-media-cleanup' ); ?></p>

			<h2 class="nav-tab-wrapper">
				<?php
				$tabs = array(
					'scan'     => __( 'Scan', 'acps-media-cleanup' ),
					'folders'  => __( 'Unused by Folder', 'acps-media-cleanup' ),
					'trash'    => __( 'Trash & Log', 'acps-media-cleanup' ),
					'settings' => __( 'Settings', 'acps-media-cleanup' ),
				);
				foreach ( $tabs as $key => $label ) {
					printf(
						'<a href="%s" class="nav-tab %s">%s</a>',
						esc_url( add_query_arg( 'tab', $key, $base_url ) ),
						$tab === $key ? 'nav-tab-active' : '',
						esc_html( $label )
					);
				}
				?>
			</h2>

			<div class="acps-mc-body">
				<?php
				switch ( $tab ) {
					case 'folders':
						$this->render_folders_tab();
						break;
					case 'trash':
						$this->render_trash_tab();
						break;
					case 'settings':
						$this->render_settings_tab();
						break;
					default:
						$this->render_scan_tab();
				}
				?>
			</div>
		</div>
		<?php
	}

	/* --------------------------------------------------------------- */

	protected function render_scan_tab() {
		$meta        = get_option( ACPS_MC_OPT_SCANMETA, array() );
		$in_progress = ! empty( $meta['in_progress'] );
		?>
		<div class="acps-mc-card">
			<h2><?php esc_html_e( 'Scan the media library', 'acps-media-cleanup' ); ?></h2>
			<p><?php esc_html_e( 'This checks every page, post, page-builder layout (Beaver Builder), widget, menu, site logo, custom field and (optionally) theme file to see which media files are actually referenced. Anything not referenced anywhere is reported as unused.', 'acps-media-cleanup' ); ?></p>

			<p class="acps-mc-safe">
				<span class="dashicons dashicons-shield"></span>
				<?php esc_html_e( 'Scanning changes nothing. It only reads your site and produces a report. Deleting is always a separate, deliberate step.', 'acps-media-cleanup' ); ?>
			</p>

			<?php if ( $in_progress ) : ?>
				<div class="notice notice-warning inline" id="acps-mc-resume-notice">
					<p>
						<?php esc_html_e( 'A previous scan did not finish. You can resume it where it left off, or start a new scan.', 'acps-media-cleanup' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<p>
				<?php if ( $in_progress ) : ?>
					<button type="button" class="button button-primary button-hero" id="acps-mc-resume-btn">
						<?php esc_html_e( 'Resume scan', 'acps-media-cleanup' ); ?>
					</button>
					<button type="button" class="button button-hero" id="acps-mc-scan-btn">
						<?php esc_html_e( 'Start a new scan', 'acps-media-cleanup' ); ?>
					</button>
				<?php else : ?>
					<button type="button" class="button button-primary button-hero" id="acps-mc-scan-btn">
						<?php esc_html_e( 'Scan now', 'acps-media-cleanup' ); ?>
					</button>
				<?php endif; ?>
			</p>

			<div id="acps-mc-progress" class="acps-mc-progress" style="display:none;">
				<div class="acps-mc-progress-bar"><div class="acps-mc-progress-fill"></div></div>
				<p class="acps-mc-progress-label"></p>
				<p class="acps-mc-progress-live" id="acps-mc-progress-live"></p>
			</div>

			<div id="acps-mc-summary" class="acps-mc-summary">
				<?php self::render_summary( $meta ); ?>
			</div>
		</div>
		<?php
	}

	public static function render_summary( $meta ) {
		if ( empty( $meta ) || empty( $meta['time'] ) ) {
			echo '<p class="acps-mc-muted">' . esc_html__( 'No scan has been run yet.', 'acps-media-cleanup' ) . '</p>';
			return;
		}
		$counts = isset( $meta['counts'] ) ? $meta['counts'] : array();
		$used   = isset( $counts['used'] ) ? (int) $counts['used'] : 0;
		$unused = isset( $counts['unused'] ) ? (int) $counts['unused'] : 0;
		$bytes  = isset( $counts['unused_bytes'] ) ? (int) $counts['unused_bytes'] : 0;
		?>
		<div class="acps-mc-stats">
			<div class="acps-mc-stat"><span class="num"><?php echo esc_html( number_format_i18n( $used + $unused ) ); ?></span><span class="lbl"><?php esc_html_e( 'Media files', 'acps-media-cleanup' ); ?></span></div>
			<div class="acps-mc-stat used"><span class="num"><?php echo esc_html( number_format_i18n( $used ) ); ?></span><span class="lbl"><?php esc_html_e( 'Used', 'acps-media-cleanup' ); ?></span></div>
			<div class="acps-mc-stat unused"><span class="num"><?php echo esc_html( number_format_i18n( $unused ) ); ?></span><span class="lbl"><?php esc_html_e( 'Unused', 'acps-media-cleanup' ); ?></span></div>
			<div class="acps-mc-stat reclaim"><span class="num"><?php echo esc_html( size_format( $bytes, 1 ) ); ?></span><span class="lbl"><?php esc_html_e( 'Reclaimable', 'acps-media-cleanup' ); ?></span></div>
		</div>
		<p class="acps-mc-muted">
			<?php
			printf(
				/* translators: 1: date/time, 2: folder backend */
				esc_html__( 'Last scanned %1$s · Folders: %2$s', 'acps-media-cleanup' ),
				esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $meta['time'] ) ),
				esc_html( isset( $meta['backend'] ) ? $meta['backend'] : '' )
			);
			?>
		</p>
		<?php if ( $unused > 0 ) : ?>
			<p><a class="button button-primary" href="<?php echo esc_url( self::page_url( 'folders' ) ); ?>"><?php esc_html_e( 'Review unused files by folder →', 'acps-media-cleanup' ); ?></a></p>
		<?php endif; ?>

		<div class="acps-mc-coverage">
			<h4><?php esc_html_e( 'What was checked', 'acps-media-cleanup' ); ?></h4>
			<ul>
				<li><?php esc_html_e( 'Page & post content (classic + block editor)', 'acps-media-cleanup' ); ?></li>
				<li><?php esc_html_e( 'Page-builder layouts, custom fields & all post meta', 'acps-media-cleanup' ); ?></li>
				<li><?php esc_html_e( 'Featured images, galleries, site logo & site icon', 'acps-media-cleanup' ); ?></li>
				<li><?php esc_html_e( 'Widgets, menus, theme options & term/user meta', 'acps-media-cleanup' ); ?></li>
				<?php if ( ! empty( $meta['coverage']['theme_files'] ) ) : ?>
					<li><?php esc_html_e( 'Active & child theme template / CSS / JS files', 'acps-media-cleanup' ); ?></li>
				<?php endif; ?>
			</ul>
			<p class="acps-mc-muted acps-mc-note">
				<?php esc_html_e( 'Note: references that live entirely outside this WordPress install (for example a hard-coded URL on another website, or a third-party service) cannot be detected. This is why files are moved to Trash first and can be restored. Always keep a recent backup before permanently deleting.', 'acps-media-cleanup' ); ?>
			</p>
		</div>
		<?php
	}

	protected function render_folders_tab() {
		$meta = get_option( ACPS_MC_OPT_SCANMETA, array() );
		if ( empty( $meta['time'] ) ) {
			echo '<div class="acps-mc-card"><p>' . esc_html__( 'Run a scan first.', 'acps-media-cleanup' ) . ' ';
			echo '<a href="' . esc_url( self::page_url( 'scan' ) ) . '">' . esc_html__( 'Go to Scan', 'acps-media-cleanup' ) . '</a></p></div>';
			return;
		}
		$settings = ACPS_MC_Settings::all();
		?>
		<div class="acps-mc-card">
			<div class="acps-mc-toolbar">
				<label><input type="checkbox" id="acps-mc-include-sub" checked> <?php esc_html_e( 'Include sub-folders', 'acps-media-cleanup' ); ?></label>
				<label><input type="checkbox" id="acps-mc-show-used"> <?php esc_html_e( 'Also show used files', 'acps-media-cleanup' ); ?></label>
			</div>
			<div class="acps-mc-columns">
				<aside class="acps-mc-tree" id="acps-mc-tree">
					<p class="acps-mc-muted"><?php esc_html_e( 'Loading folders…', 'acps-media-cleanup' ); ?></p>
				</aside>
				<section class="acps-mc-files" id="acps-mc-files">
					<p class="acps-mc-muted"><?php esc_html_e( 'Select a folder on the left to see its unused files.', 'acps-media-cleanup' ); ?></p>
				</section>
			</div>
		</div>

		<div class="acps-mc-actionbar" id="acps-mc-actionbar" style="display:none;">
			<span id="acps-mc-selcount">0</span> <?php esc_html_e( 'selected', 'acps-media-cleanup' ); ?>
			<?php echo '&nbsp;·&nbsp;<span id="acps-mc-selsize"></span>'; ?>
			<?php if ( ! empty( $settings['require_backup_ack'] ) ) : ?>
				<label class="acps-mc-ack"><input type="checkbox" id="acps-mc-ack"> <?php esc_html_e( 'I have a recent backup', 'acps-media-cleanup' ); ?></label>
			<?php endif; ?>
			<button type="button" class="button button-primary" id="acps-mc-delete-btn">
				<?php
				echo 'permanent' === $settings['delete_mode']
					? esc_html__( 'Delete selected permanently', 'acps-media-cleanup' )
					: esc_html__( 'Move selected to Trash', 'acps-media-cleanup' );
				?>
			</button>
		</div>
		<?php
	}

	protected function render_trash_tab() {
		$deleter = new ACPS_MC_Deleter();
		$trashed = $deleter->trashed_items();
		$log     = ACPS_MC_Logger::recent( 100 );
		?>
		<div class="acps-mc-card">
			<h2><?php esc_html_e( 'Trash', 'acps-media-cleanup' ); ?> <span class="acps-mc-count">(<?php echo esc_html( count( $trashed ) ); ?>)</span></h2>
			<p class="acps-mc-muted"><?php esc_html_e( 'Files here were moved to Trash by this plugin (or WordPress). Their files are still on disk and can be restored. Empty the trash to remove them permanently.', 'acps-media-cleanup' ); ?></p>

			<?php if ( empty( $trashed ) ) : ?>
				<p><?php esc_html_e( 'Trash is empty.', 'acps-media-cleanup' ); ?></p>
			<?php else : ?>
				<table class="widefat striped acps-mc-table" id="acps-mc-trash-table">
					<thead><tr>
						<th><?php esc_html_e( 'File', 'acps-media-cleanup' ); ?></th>
						<th><?php esc_html_e( 'Type', 'acps-media-cleanup' ); ?></th>
						<th><?php esc_html_e( 'Trashed', 'acps-media-cleanup' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'acps-media-cleanup' ); ?></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $trashed as $t ) : ?>
							<tr data-id="<?php echo esc_attr( $t['id'] ); ?>">
								<td><strong><?php echo esc_html( $t['filename'] ? $t['filename'] : $t['title'] ); ?></strong></td>
								<td><?php echo esc_html( $t['mime'] ); ?></td>
								<td><?php echo esc_html( $t['date'] ); ?></td>
								<td>
									<button type="button" class="button acps-mc-restore" data-id="<?php echo esc_attr( $t['id'] ); ?>"><?php esc_html_e( 'Restore', 'acps-media-cleanup' ); ?></button>
									<button type="button" class="button acps-mc-purge" data-id="<?php echo esc_attr( $t['id'] ); ?>"><?php esc_html_e( 'Delete forever', 'acps-media-cleanup' ); ?></button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<div class="acps-mc-card">
			<h2><?php esc_html_e( 'Activity log', 'acps-media-cleanup' ); ?></h2>
			<?php if ( empty( $log ) ) : ?>
				<p><?php esc_html_e( 'No actions recorded yet.', 'acps-media-cleanup' ); ?></p>
			<?php else : ?>
				<table class="widefat striped acps-mc-table">
					<thead><tr>
						<th><?php esc_html_e( 'When', 'acps-media-cleanup' ); ?></th>
						<th><?php esc_html_e( 'Action', 'acps-media-cleanup' ); ?></th>
						<th><?php esc_html_e( 'File', 'acps-media-cleanup' ); ?></th>
						<th><?php esc_html_e( 'Folder', 'acps-media-cleanup' ); ?></th>
						<th><?php esc_html_e( 'Size', 'acps-media-cleanup' ); ?></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $log as $r ) : ?>
							<tr>
								<td><?php echo esc_html( $r['created_at'] ); ?></td>
								<td><span class="acps-mc-badge acps-mc-badge-<?php echo esc_attr( $r['action'] ); ?>"><?php echo esc_html( $this->action_label( $r['action'] ) ); ?></span></td>
								<td><?php echo esc_html( $r['filename'] ); ?></td>
								<td><?php echo esc_html( $r['folder_name'] ); ?></td>
								<td><?php echo esc_html( $r['size_bytes'] ? size_format( (int) $r['size_bytes'], 1 ) : '—' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	protected function action_label( $action ) {
		$map = array(
			'trash'             => __( 'Trashed', 'acps-media-cleanup' ),
			'delete'            => __( 'Deleted', 'acps-media-cleanup' ),
			'restore'           => __( 'Restored', 'acps-media-cleanup' ),
			'delete_from_trash' => __( 'Purged', 'acps-media-cleanup' ),
		);
		return isset( $map[ $action ] ) ? $map[ $action ] : $action;
	}

	protected function render_settings_tab() {
		$s        = ACPS_MC_Settings::all();
		$folders  = new ACPS_MC_Folders();
		$tree     = $folders->folders();

		if ( isset( $_GET['acps_mc_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'acps-media-cleanup' ) . '</p></div>';
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="acps-mc-card">
			<?php wp_nonce_field( 'acps_mc_settings', 'acps_mc_settings_nonce' ); ?>
			<input type="hidden" name="action" value="acps_mc_save_settings">

			<h2><?php esc_html_e( 'Safety settings', 'acps-media-cleanup' ); ?></h2>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Deletion mode', 'acps-media-cleanup' ); ?></th>
					<td>
						<label><input type="radio" name="delete_mode" value="trash" <?php checked( $s['delete_mode'], 'trash' ); ?>> <strong><?php esc_html_e( 'Move to Trash (recommended, reversible)', 'acps-media-cleanup' ); ?></strong></label><br>
						<label><input type="radio" name="delete_mode" value="permanent" <?php checked( $s['delete_mode'], 'permanent' ); ?>> <?php esc_html_e( 'Delete permanently (removes files from disk)', 'acps-media-cleanup' ); ?></label>
						<p class="description"><?php esc_html_e( 'Trash keeps the files so you can restore them if something turns out to be needed. Only switch to permanent once you have verified the site is fine.', 'acps-media-cleanup' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="protect_recent_days"><?php esc_html_e( 'Protect recent uploads', 'acps-media-cleanup' ); ?></label></th>
					<td>
						<input type="number" min="0" id="protect_recent_days" name="protect_recent_days" value="<?php echo esc_attr( $s['protect_recent_days'] ); ?>" class="small-text"> <?php esc_html_e( 'days', 'acps-media-cleanup' ); ?>
						<p class="description"><?php esc_html_e( 'Never delete files uploaded within this many days (they may not be placed on a page yet). 0 disables this guard.', 'acps-media-cleanup' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Extra safety nets', 'acps-media-cleanup' ); ?></th>
					<td>
						<label><input type="checkbox" name="treat_attached_as_used" value="1" <?php checked( $s['treat_attached_as_used'] ); ?>> <?php esc_html_e( 'Treat files attached to a live post/page as used', 'acps-media-cleanup' ); ?></label><br>
						<label><input type="checkbox" name="treat_id_meta_as_used" value="1" <?php checked( $s['treat_id_meta_as_used'] ); ?>> <?php esc_html_e( 'Treat a custom field whose value is an attachment ID as used (ACF etc.)', 'acps-media-cleanup' ); ?></label><br>
						<label><input type="checkbox" name="scan_theme_files" value="1" <?php checked( $s['scan_theme_files'] ); ?>> <?php esc_html_e( 'Scan active & child theme files for image references', 'acps-media-cleanup' ); ?></label><br>
						<label><input type="checkbox" name="scan_builder_cache" value="1" <?php checked( $s['scan_builder_cache'] ); ?>> <?php esc_html_e( 'Scan the Beaver Builder CSS cache', 'acps-media-cleanup' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Confirmation', 'acps-media-cleanup' ); ?></th>
					<td>
						<label><input type="checkbox" name="require_backup_ack" value="1" <?php checked( $s['require_backup_ack'] ); ?>> <?php esc_html_e( 'Require me to confirm I have a backup before deleting', 'acps-media-cleanup' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="excluded_extensions"><?php esc_html_e( 'Never delete these file types', 'acps-media-cleanup' ); ?></label></th>
					<td>
						<input type="text" id="excluded_extensions" name="excluded_extensions" value="<?php echo esc_attr( implode( ', ', (array) $s['excluded_extensions'] ) ); ?>" class="regular-text" placeholder="pdf, svg">
						<p class="description"><?php esc_html_e( 'Comma separated, e.g. "pdf, svg". Leave blank to allow all types.', 'acps-media-cleanup' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Never delete these folders', 'acps-media-cleanup' ); ?></th>
					<td>
						<div class="acps-mc-folder-checks">
						<?php
						foreach ( $tree as $f ) {
							if ( ACPS_MC_Folders::UNCATEGORIZED === (int) $f['id'] ) {
								continue;
							}
							printf(
								'<label><input type="checkbox" name="excluded_folders[]" value="%d" %s> %s</label>',
								(int) $f['id'],
								checked( in_array( (int) $f['id'], array_map( 'intval', (array) $s['excluded_folders'] ), true ), true, false ),
								esc_html( $f['name'] )
							);
						}
						?>
						</div>
						<p class="description"><?php esc_html_e( 'Files in a protected folder (and its sub-folders) can never be deleted.', 'acps-media-cleanup' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="batch_size"><?php esc_html_e( 'Scan batch size', 'acps-media-cleanup' ); ?></label></th>
					<td>
						<input type="number" min="5" max="200" id="batch_size" name="batch_size" value="<?php echo esc_attr( $s['batch_size'] ); ?>" class="small-text">
						<p class="description"><?php esc_html_e( 'Items processed per step. Lower this if your server times out during a scan.', 'acps-media-cleanup' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Save settings', 'acps-media-cleanup' ) ); ?>
		</form>

		<?php if ( ! empty( $s['excluded_ids'] ) ) : ?>
			<div class="acps-mc-card">
				<h3><?php esc_html_e( 'Individually protected files', 'acps-media-cleanup' ); ?></h3>
				<p class="acps-mc-muted"><?php echo esc_html( count( $s['excluded_ids'] ) ); ?> <?php esc_html_e( 'file(s) are protected from deletion. Manage these from the "Unused by Folder" list.', 'acps-media-cleanup' ); ?></p>
			</div>
		<?php endif; ?>
		<?php
	}

	public function save_settings() {
		if ( ! current_user_can( ACPS_MC_CAP ) ) {
			wp_die( esc_html__( 'Permission denied.', 'acps-media-cleanup' ) );
		}
		check_admin_referer( 'acps_mc_settings', 'acps_mc_settings_nonce' );

		$clean = ACPS_MC_Settings::sanitize( wp_unslash( $_POST ) );
		update_option( ACPS_MC_OPT_SETTINGS, $clean );

		wp_safe_redirect( add_query_arg( 'acps_mc_saved', 1, self::page_url( 'settings' ) ) );
		exit;
	}
}
