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

	/** Submenu page slugs (registered by the Media Manager). */
	const TRASH_SLUG    = 'acps-mc-trash';
	const SETTINGS_SLUG = 'acps-mc-settings';

	/** Hidden updates page slug — NOT linked from any menu (type the URL). */
	const UPDATES_SLUG = 'acps-mc-updates';

	/** Hidden remote-photo-API page slug — NOT linked from any menu. */
	const REMOTE_SLUG = 'acps-mc-remote';

	public function __construct() {
		// The menu itself is registered by ACPS_MC_Manager so everything lives
		// under one "Media Manager" top-level menu (cleanup is not a separate tab).
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_acps_mc_save_settings', array( $this, 'save_settings' ) );
		// Hidden pages (registered with no menu entry) — each has its own save.
		add_action( 'admin_post_acps_mc_save_updates', array( $this, 'save_updates' ) );
		add_action( 'admin_post_acps_mc_run_update', array( $this, 'run_update' ) );
		add_action( 'admin_post_acps_mc_save_remote', array( $this, 'save_remote' ) );
	}

	public function enqueue( $hook ) {
		if ( false === strpos( (string) $hook, self::TRASH_SLUG )
			&& false === strpos( (string) $hook, self::SETTINGS_SLUG )
			&& false === strpos( (string) $hook, self::UPDATES_SLUG )
			&& false === strpos( (string) $hook, self::REMOTE_SLUG ) ) {
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

	/**
	 * Standalone "Trash & Log" page (submenu of the Media Manager).
	 */
	public function render_trash_page() {
		if ( ! current_user_can( ACPS_MC_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'acps-media-cleanup' ) );
		}
		echo '<div class="wrap acps-mc">';
		echo '<h1><span class="dashicons dashicons-trash"></span> ' . esc_html__( 'Media Trash & Activity Log', 'acps-media-cleanup' ) . '</h1>';
		echo '<p><a href="' . esc_url( ACPS_MC_Manager::page_url() ) . '">&larr; ' . esc_html__( 'Back to FileMedia', 'acps-media-cleanup' ) . '</a></p>';
		$this->render_trash_tab();
		echo '</div>';
	}

	/**
	 * Standalone "Settings" page (submenu of the Media Manager).
	 */
	public function render_settings_page() {
		if ( ! current_user_can( ACPS_MC_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'acps-media-cleanup' ) );
		}
		echo '<div class="wrap acps-mc">';
		echo '<h1><span class="dashicons dashicons-admin-generic"></span> ' . esc_html__( 'FileMedia Settings', 'acps-media-cleanup' ) . '</h1>';
		echo '<p><a href="' . esc_url( ACPS_MC_Manager::page_url() ) . '">&larr; ' . esc_html__( 'Back to FileMedia', 'acps-media-cleanup' ) . '</a></p>';
		$this->render_settings_tab();
		echo '</div>';
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
			<p><a class="button button-primary" href="<?php echo esc_url( ACPS_MC_Manager::page_url() ); ?>"><?php esc_html_e( 'Review unused files in FileMedia →', 'acps-media-cleanup' ); ?></a></p>
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
					<th scope="row"><?php esc_html_e( 'FileMedia', 'acps-media-cleanup' ); ?></th>
					<td>
						<label><input type="checkbox" name="replace_media_screen" value="1" <?php checked( $s['replace_media_screen'] ); ?>> <?php esc_html_e( 'Open the enhanced FileMedia screen when I click "Media"', 'acps-media-cleanup' ); ?></label>
						<p class="description"><?php esc_html_e( 'The classic Media Library stays available via the "Classic library" link. The media picker inside the editor / Beaver Builder is never changed.', 'acps-media-cleanup' ); ?></p>
						<br>
						<label><input type="checkbox" name="replace_media_uploader" value="1" <?php checked( $s['replace_media_uploader'] ); ?>> <?php esc_html_e( 'Use the FileMedia uploader on the "Add Media File" page (drop zone, folder chooser, HEIC conversion, resumable progress)', 'acps-media-cleanup' ); ?></label>
						<p class="description"><?php esc_html_e( 'Replaces the plain WordPress uploader at Media › Add New. The classic uploader stays available via the "Classic uploader" link on the FileMedia upload screen.', 'acps-media-cleanup' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Automatic scan', 'acps-media-cleanup' ); ?></th>
					<td>
						<label><input type="checkbox" name="auto_nightly_scan" value="1" <?php checked( $s['auto_nightly_scan'] ); ?>> <?php esc_html_e( 'Scan for where media is used automatically every night (around 2am)', 'acps-media-cleanup' ); ?></label>
						<p class="description"><?php esc_html_e( 'Keeps the used / unused colours and the "Unused" view up to date without you running a scan by hand.', 'acps-media-cleanup' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'HEIC conversion', 'acps-media-cleanup' ); ?></th>
					<td>
						<label><input type="checkbox" name="convert_heic_on_upload" value="1" <?php checked( $s['convert_heic_on_upload'] ); ?>> <?php esc_html_e( 'Convert HEIC/HEIF uploads to JPEG automatically', 'acps-media-cleanup' ); ?></label>
						<p class="description">
							<?php esc_html_e( 'In FileMedia, HEIC photos are converted to JPEG right in your browser before they upload — no server support needed and nothing is sent to any third party.', 'acps-media-cleanup' ); ?>
							<br>
							<?php
							if ( ACPS_MC_Heic::supported() ) {
								esc_html_e( 'Uploads made elsewhere (the classic media library) are also converted on this server. ✓', 'acps-media-cleanup' );
							} else {
								esc_html_e( 'This server itself cannot convert HEIC (Imagick without HEIC support), so uploads made outside FileMedia will stay as HEIC.', 'acps-media-cleanup' );
							}
							?>
						</p>
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
				<p class="acps-mc-muted"><?php echo esc_html( count( $s['excluded_ids'] ) ); ?> <?php esc_html_e( 'file(s) are protected from deletion.', 'acps-media-cleanup' ); ?></p>
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

		// Reconcile the nightly-scan schedule with the new setting immediately.
		if ( ! empty( $clean['auto_nightly_scan'] ) ) {
			ACPS_MC_Cron::schedule();
		} else {
			ACPS_MC_Cron::unschedule();
		}

		// Drop any cached update lookup so a changed source/token takes effect now.
		if ( class_exists( 'ACPS_MC_Updater' ) ) {
			ACPS_MC_Updater::flush_cache();
		}

		wp_safe_redirect(
			add_query_arg(
				array( 'page' => self::SETTINGS_SLUG, 'acps_mc_saved' => 1 ),
				admin_url( 'upload.php' )
			)
		);
		exit;
	}

	/* --------------------------------------------------------------- *
	 * Hidden self-hosted updates page.
	 *
	 * Registered with NO menu entry (see ACPS_MC_Manager::register_menu), so it
	 * exists only for someone who types the URL directly:
	 *     wp-admin/admin.php?page=acps-mc-updates
	 * This keeps the update configuration out of sight so it can't be changed by
	 * accident. Access still requires the manage_options capability.
	 * --------------------------------------------------------------- */

	public function render_updates_page() {
		if ( ! current_user_can( ACPS_MC_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'acps-media-cleanup' ) );
		}
		$s          = ACPS_MC_Settings::all();
		$upd_status = class_exists( 'ACPS_MC_Updater' ) ? ACPS_MC_Updater::peek_status() : array( 'checked' => false, 'remote' => false, 'has_update' => false );
		$force_url  = class_exists( 'ACPS_MC_Updater' ) ? ACPS_MC_Updater::force_update_url() : '';
		?>
		<div class="wrap acps-mc">
			<h1><span class="dashicons dashicons-update"></span> <?php esc_html_e( 'FileMedia — Software updates (hidden)', 'acps-media-cleanup' ); ?></h1>
			<?php if ( isset( $_GET['acps_mc_saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Update settings saved.', 'acps-media-cleanup' ); ?></p></div>
			<?php endif; ?>
			<p class="description" style="max-width:720px;">
				<?php esc_html_e( 'This page has no menu link on purpose — it is reachable only by typing its URL — so the self-update configuration can’t be changed by accident. Let this plugin update itself from a source you control (a GitHub release or a JSON manifest), showing “Update now” on the Plugins screen. A failed update is crash-tested and rolled back, and a fatal error puts the plugin into a safe paused mode instead of taking the site down.', 'acps-media-cleanup' ); ?>
			</p>

			<?php
			$last_log = get_transient( 'acps_mc_last_update_log' );
			delete_transient( 'acps_mc_last_update_log' );
			if ( is_array( $last_log ) && $last_log ) :
				?>
				<div class="notice notice-info"><p><strong><?php esc_html_e( 'Last update run:', 'acps-media-cleanup' ); ?></strong></p><pre style="white-space:pre-wrap;margin:0 0 8px;"><?php echo esc_html( implode( "\n", $last_log ) ); ?></pre></div>
			<?php endif; ?>

			<div class="acps-mc-card">
				<h2><?php esc_html_e( 'Update now', 'acps-media-cleanup' ); ?></h2>
				<p class="description"><?php printf( esc_html__( 'Installed version: %s. These run the same self-contained installer used by the console URL (works even in silent mode).', 'acps-media-cleanup' ), esc_html( ACPS_MC_VERSION ) ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
					<?php wp_nonce_field( 'acps_mc_run_update', 'acps_mc_run_update_nonce' ); ?>
					<input type="hidden" name="action" value="acps_mc_run_update">
					<button type="submit" name="mode" value="update" class="button button-primary"><?php esc_html_e( 'Update to latest', 'acps-media-cleanup' ); ?></button>
					<button type="submit" name="mode" value="reinstall" class="button" onclick="return confirm('<?php echo esc_js( __( 'Re-download and overwrite the plugin with the latest version (fixes a wrongly-edited file)?', 'acps-media-cleanup' ) ); ?>');"><?php esc_html_e( 'Reinstall latest (fix broken files)', 'acps-media-cleanup' ); ?></button>
					<button type="submit" name="mode" value="probe" class="button"><?php esc_html_e( 'Write probe (diagnose host)', 'acps-media-cleanup' ); ?></button>
				</form>
				<p class="description" style="margin-top:8px;"><?php esc_html_e( 'If a normal Update fails with "could not write files" (common on WP Engine, which blocks overwriting in-use PHP), use Stage: it writes the new files, then applies them on the next page load in the pristine bootstrap window. Run the probe first to see which case your host is in.', 'acps-media-cleanup' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
					<?php wp_nonce_field( 'acps_mc_run_update', 'acps_mc_run_update_nonce' ); ?>
					<input type="hidden" name="action" value="acps_mc_run_update">
					<button type="submit" name="mode" value="stage" class="button button-primary"><?php esc_html_e( 'Stage update (then reload)', 'acps-media-cleanup' ); ?></button>
					<button type="submit" name="mode" value="stageforce" class="button" onclick="return confirm('<?php echo esc_js( __( 'Stage a fresh copy of the latest version even if already current?', 'acps-media-cleanup' ) ); ?>');"><?php esc_html_e( 'Stage reinstall (force)', 'acps-media-cleanup' ); ?></button>
					<button type="submit" name="mode" value="queue" class="button"><?php esc_html_e( 'Queue in background', 'acps-media-cleanup' ); ?></button>
				</form>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="acps-mc-card">
				<?php wp_nonce_field( 'acps_mc_updates', 'acps_mc_updates_nonce' ); ?>
				<input type="hidden" name="action" value="acps_mc_save_updates">

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable self-updates', 'acps-media-cleanup' ); ?></th>
						<td>
							<label><input type="checkbox" name="update_enabled" value="1" <?php checked( $s['update_enabled'] ); ?>> <?php esc_html_e( 'Check the source below for new versions and offer them on the Plugins screen', 'acps-media-cleanup' ); ?></label>
							<br>
							<label><input type="checkbox" name="update_auto" value="1" <?php checked( $s['update_auto'] ); ?>> <?php esc_html_e( 'Also install updates automatically in the background (uses the same crash-test protection)', 'acps-media-cleanup' ); ?></label>
							<br>
							<label><input type="checkbox" name="update_silent" value="1" <?php checked( $s['update_silent'] ); ?>> <?php esc_html_e( 'Silent — never show any update notice in wp-admin (no “Update now” on the Plugins screen). Update only from this page or the console URL.', 'acps-media-cleanup' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Update source', 'acps-media-cleanup' ); ?></th>
						<td>
							<label><input type="radio" name="update_source" value="github" <?php checked( $s['update_source'], 'github' ); ?>> <?php esc_html_e( 'GitHub Releases', 'acps-media-cleanup' ); ?></label><br>
							<label><input type="radio" name="update_source" value="url" <?php checked( $s['update_source'], 'url' ); ?>> <?php esc_html_e( 'JSON manifest URL', 'acps-media-cleanup' ); ?></label>
						</td>
					</tr>
				</table>

				<h3><?php esc_html_e( 'GitHub Releases', 'acps-media-cleanup' ); ?></h3>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="gh_owner"><?php esc_html_e( 'Owner / repo', 'acps-media-cleanup' ); ?></label></th>
						<td>
							<input type="text" id="gh_owner" name="gh_owner" value="<?php echo esc_attr( $s['gh_owner'] ); ?>" class="regular-text" placeholder="acps" style="width:14em;">
							<span aria-hidden="true"> / </span>
							<input type="text" id="gh_repo" name="gh_repo" value="<?php echo esc_attr( $s['gh_repo'] ); ?>" class="regular-text" placeholder="acps-media-cleanup" style="width:16em;">
							<p class="description"><?php esc_html_e( 'The plugin reads the latest release: its tag is the version, and the named asset below is the zip that gets installed.', 'acps-media-cleanup' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gh_asset"><?php esc_html_e( 'Release asset filename', 'acps-media-cleanup' ); ?></label></th>
						<td><input type="text" id="gh_asset" name="gh_asset" value="<?php echo esc_attr( $s['gh_asset'] ); ?>" class="regular-text code" placeholder="acps-media-cleanup.zip"></td>
					</tr>
					<tr>
						<th scope="row"><label for="gh_token"><?php esc_html_e( 'Access token (private repos)', 'acps-media-cleanup' ); ?></label></th>
						<td>
							<input type="password" id="gh_token" name="gh_token" value="<?php echo esc_attr( $s['gh_token'] ); ?>" class="regular-text code" autocomplete="new-password">
							<p class="description"><?php esc_html_e( 'Leave blank for a public repo. For a private repo, paste a fine-grained personal access token with read access to the repository’s contents.', 'acps-media-cleanup' ); ?></p>
						</td>
					</tr>
				</table>

				<h3><?php esc_html_e( 'JSON manifest', 'acps-media-cleanup' ); ?></h3>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="update_manifest"><?php esc_html_e( 'Manifest URL', 'acps-media-cleanup' ); ?></label></th>
						<td>
							<input type="url" id="update_manifest" name="update_manifest" value="<?php echo esc_attr( $s['update_manifest'] ); ?>" class="regular-text code" style="width:32em;max-width:100%;" placeholder="https://example.org/acps-media-cleanup.json">
							<p class="description"><?php esc_html_e( 'A JSON file returning at least { "version": "1.2.3", "download_url": "https://…/acps-media-cleanup.zip" }. Optional: homepage, changelog, requires_php, requires_wp.', 'acps-media-cleanup' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="update_manifest_key"><?php esc_html_e( 'Manifest key (optional)', 'acps-media-cleanup' ); ?></label></th>
						<td><input type="text" id="update_manifest_key" name="update_manifest_key" value="<?php echo esc_attr( $s['update_manifest_key'] ); ?>" class="regular-text code"><p class="description"><?php esc_html_e( 'If your manifest is protected, this is sent as ?key=… on the request.', 'acps-media-cleanup' ); ?></p></td>
					</tr>
				</table>

				<h3><?php esc_html_e( 'Staged rollout (optional)', 'acps-media-cleanup' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Run two sites in a chain: a “dev” site installs and crash-tests a new version first, then publishes that it passed; a “production” site only offers/applies a version once its paired dev site has verified it.', 'acps-media-cleanup' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'This site’s role', 'acps-media-cleanup' ); ?></th>
						<td>
							<label><input type="radio" name="update_role" value="" <?php checked( $s['update_role'], '' ); ?>> <?php esc_html_e( 'Standalone (no staging)', 'acps-media-cleanup' ); ?></label><br>
							<label><input type="radio" name="update_role" value="dev" <?php checked( $s['update_role'], 'dev' ); ?>> <?php esc_html_e( 'Dev (verifies first)', 'acps-media-cleanup' ); ?></label><br>
							<label><input type="radio" name="update_role" value="production" <?php checked( $s['update_role'], 'production' ); ?>> <?php esc_html_e( 'Production (waits for dev)', 'acps-media-cleanup' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="verify_status_url"><?php esc_html_e( 'Dev status URL (production only)', 'acps-media-cleanup' ); ?></label></th>
						<td><input type="url" id="verify_status_url" name="verify_status_url" value="<?php echo esc_attr( $s['verify_status_url'] ); ?>" class="regular-text code" style="width:32em;max-width:100%;" placeholder="https://dev.example.org/wp-json/acps-mc/v1/update-status"></td>
					</tr>
					<tr>
						<th scope="row"><label for="verify_status_key"><?php esc_html_e( 'Shared status key', 'acps-media-cleanup' ); ?></label></th>
						<td>
							<input type="text" id="verify_status_key" name="verify_status_key" value="<?php echo esc_attr( $s['verify_status_key'] ); ?>" class="regular-text code">
							<p class="description"><?php esc_html_e( 'Set the SAME value on both the dev and production sites. Guards the /update-status endpoint.', 'acps-media-cleanup' ); ?></p>
						</td>
					</tr>
				</table>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Status', 'acps-media-cleanup' ); ?></th>
						<td>
							<p>
								<?php
								/* translators: %s: installed plugin version */
								echo esc_html( sprintf( __( 'Installed version: %s', 'acps-media-cleanup' ), ACPS_MC_VERSION ) );
								?>
								<br>
								<?php
								if ( ! empty( $s['update_enabled'] ) && ! empty( $upd_status['checked'] ) && ! empty( $upd_status['remote']['version'] ) ) {
									/* translators: %s: latest available version */
									echo esc_html( sprintf( __( 'Latest from source: %s', 'acps-media-cleanup' ), $upd_status['remote']['version'] ) );
									echo $upd_status['has_update'] ? ' — <strong>' . esc_html__( 'update available', 'acps-media-cleanup' ) . '</strong>' : ' — ' . esc_html__( 'up to date', 'acps-media-cleanup' );
								} elseif ( ! empty( $s['update_enabled'] ) ) {
									esc_html_e( 'No successful check yet (it runs when WordPress next checks for plugin updates).', 'acps-media-cleanup' );
								} else {
									esc_html_e( 'Self-updates are turned off.', 'acps-media-cleanup' );
								}
								?>
							</p>
							<?php if ( '' !== $force_url ) : ?>
								<p class="description">
									<?php esc_html_e( 'Secret force-update URL (check + install now, e.g. from a deploy hook or cron):', 'acps-media-cleanup' ); ?><br>
									<input type="text" readonly onclick="this.select()" value="<?php echo esc_attr( $force_url ); ?>" class="large-text code">
									<br><?php esc_html_e( 'Keep this URL secret — anyone with it can trigger an update check on this site.', 'acps-media-cleanup' ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<h3><?php esc_html_e( 'External control console', 'acps-media-cleanup' ); ?></h3>
				<p class="description" style="max-width:760px;">
					<?php
					$console_url = ( '' !== (string) $s['console_key'] ) ? home_url( '/?' . rawurlencode( 'acpsupdater' ) . '=' . rawurlencode( $s['console_key'] ) ) : '';
					esc_html_e( 'A plain-text, no-styling control panel outside wp-admin — open a fast page instead of the slow Beaver Builder / wp-admin. It can update, reinstall, resume paused mode, and view/edit every plugin setting, all behind the password below. It works even if the plugin is paused, so a broken site can always be fixed from it. A Python script can log in and drive it (see the “For scripts” lines it prints).', 'acps-media-cleanup' );
					?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Console URL', 'acps-media-cleanup' ); ?></th>
						<td>
							<?php if ( '' !== $console_url ) : ?>
								<input type="text" readonly onclick="this.select()" value="<?php echo esc_attr( $console_url ); ?>" class="large-text code">
								<p><label><input type="checkbox" name="console_regenerate" value="1"> <?php esc_html_e( 'Generate a NEW console key when I save (invalidates the current URL)', 'acps-media-cleanup' ); ?></label></p>
							<?php else : ?>
								<p class="description"><?php esc_html_e( 'Set a password and save to activate the console.', 'acps-media-cleanup' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="console_password"><?php esc_html_e( 'Console password', 'acps-media-cleanup' ); ?></label></th>
						<td>
							<input type="text" id="console_password" name="console_password" value="<?php echo esc_attr( $s['console_password'] ); ?>" class="regular-text code" autocomplete="off">
							<p class="description"><?php esc_html_e( 'Required for every console action. Sent as the “pw” field/param — a Python script posts pw=THIS. Blank turns the console off.', 'acps-media-cleanup' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="console_ip_allow"><?php esc_html_e( 'Allow only these IPs', 'acps-media-cleanup' ); ?></label></th>
						<td>
							<textarea id="console_ip_allow" name="console_ip_allow" rows="3" class="large-text code" placeholder="168.1&#10;203.0.113.7"><?php echo esc_textarea( $s['console_ip_allow'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One IP or prefix per line (or comma-separated). A prefix like 168.1 matches 168.1.*. Leave blank to allow any IP.', 'acps-media-cleanup' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="console_ip_block"><?php esc_html_e( 'Block these IPs', 'acps-media-cleanup' ); ?></label></th>
						<td>
							<textarea id="console_ip_block" name="console_ip_block" rows="3" class="large-text code" placeholder="10.0&#10;192.168"><?php echo esc_textarea( $s['console_ip_block'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Always blocked, even if in the allow list. Same prefix rules.', 'acps-media-cleanup' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="console_links"><?php esc_html_e( 'Extra links on the console', 'acps-media-cleanup' ); ?></label></th>
						<td>
							<?php
							$link_lines = '';
							if ( is_array( $s['console_links'] ) ) {
								foreach ( $s['console_links'] as $l ) {
									if ( is_array( $l ) && ! empty( $l['url'] ) ) {
										$link_lines .= ( ! empty( $l['label'] ) ? $l['label'] : $l['url'] ) . ' | ' . $l['url'] . "\n";
									}
								}
							}
							?>
							<textarea id="console_links" name="console_links" rows="4" class="large-text code" placeholder="Label | https://example.org/page"><?php echo esc_textarea( $link_lines ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One per line: “Label | https://url”. These appear as clickable links on the console page.', 'acps-media-cleanup' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save update settings', 'acps-media-cleanup' ) ); ?>
			</form>
		</div>
		<?php
	}

	public function save_updates() {
		if ( ! current_user_can( ACPS_MC_CAP ) ) {
			wp_die( esc_html__( 'Permission denied.', 'acps-media-cleanup' ) );
		}
		check_admin_referer( 'acps_mc_updates', 'acps_mc_updates_nonce' );

		$clean = ACPS_MC_Settings::sanitize_updates( wp_unslash( $_POST ) );
		update_option( ACPS_MC_OPT_SETTINGS, $clean );

		// A changed source/token takes effect immediately.
		if ( class_exists( 'ACPS_MC_Updater' ) ) {
			ACPS_MC_Updater::flush_cache();
		}

		wp_safe_redirect(
			add_query_arg(
				array( 'page' => self::UPDATES_SLUG, 'acps_mc_saved' => 1 ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Run the self-contained installer (update or reinstall) from the hidden
	 * Updates page, then redirect back with the log for display.
	 */
	public function run_update() {
		if ( ! current_user_can( ACPS_MC_CAP ) ) {
			wp_die( esc_html__( 'Permission denied.', 'acps-media-cleanup' ) );
		}
		check_admin_referer( 'acps_mc_run_update', 'acps_mc_run_update_nonce' );

		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'update';
		switch ( $mode ) {
			case 'reinstall':
				$log = function_exists( 'acps_mc_perform_install' ) ? (array) acps_mc_perform_install( true ) : array( 'ERROR: installer core unavailable.' );
				break;
			case 'stage':
				$log = function_exists( 'acps_mc_stage_install' ) ? (array) acps_mc_stage_install( false ) : array( 'ERROR: installer core unavailable.' );
				break;
			case 'stageforce':
				$log = function_exists( 'acps_mc_stage_install' ) ? (array) acps_mc_stage_install( true ) : array( 'ERROR: installer core unavailable.' );
				break;
			case 'queue':
				if ( function_exists( 'acps_mc_queue_install' ) ) {
					acps_mc_queue_install( false );
					$log = array( 'Queued a background install — it will be staged on the next admin request or cron run, then applied.' );
				} else {
					$log = array( 'ERROR: installer core unavailable.' );
				}
				break;
			case 'probe':
				$log = function_exists( 'acps_mc_write_probe' ) ? (array) acps_mc_write_probe() : array( 'ERROR: probe unavailable.' );
				break;
			case 'update':
			default:
				$log = function_exists( 'acps_mc_perform_install' ) ? (array) acps_mc_perform_install( false ) : array( 'ERROR: installer core unavailable.' );
		}
		set_transient( 'acps_mc_last_update_log', $log, 5 * MINUTE_IN_SECONDS );

		wp_safe_redirect(
			add_query_arg(
				array( 'page' => self::UPDATES_SLUG ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/* --------------------------------------------------------------- *
	 * Hidden remote-photo-API page (no menu entry — type the URL:
	 *     wp-admin/admin.php?page=acps-mc-remote
	 * Keeps the remote-upload configuration out of sight so it can't be
	 * changed by accident. Access still requires manage_options.
	 * --------------------------------------------------------------- */

	public function render_remote_page() {
		if ( ! current_user_can( ACPS_MC_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'acps-media-cleanup' ) );
		}
		$s        = ACPS_MC_Settings::all();
		$base     = class_exists( 'ACPS_MC_Remote_Api' ) ? ACPS_MC_Remote_Api::base_url() : '';
		$folders  = new ACPS_MC_Folders();
		$tree     = $folders->flat_tree();
		?>
		<div class="wrap acps-mc">
			<h1><span class="dashicons dashicons-cloud-upload"></span> <?php esc_html_e( 'FileMedia — Remote photo API (hidden)', 'acps-media-cleanup' ); ?></h1>
			<?php if ( isset( $_GET['acps_mc_saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Remote API settings saved.', 'acps-media-cleanup' ); ?></p></div>
			<?php endif; ?>
			<p class="description" style="max-width:760px;">
				<?php esc_html_e( 'A private, unadvertised API for uploading and managing photos from off-site (a phone shortcut, a script, another server). It has no menu link, is off until you enable it, is protected by the secret key below, and is rate-limited and anti-spam hardened. The routes are hidden from the public REST index.', 'acps-media-cleanup' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="acps-mc-card">
				<?php wp_nonce_field( 'acps_mc_remote', 'acps_mc_remote_nonce' ); ?>
				<input type="hidden" name="action" value="acps_mc_save_remote">

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable remote API', 'acps-media-cleanup' ); ?></th>
						<td><label><input type="checkbox" name="remote_api_enabled" value="1" <?php checked( $s['remote_api_enabled'] ); ?>> <?php esc_html_e( 'Accept authenticated photo uploads / management over the API', 'acps-media-cleanup' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Secret key', 'acps-media-cleanup' ); ?></th>
						<td>
							<input type="text" readonly onclick="this.select()" value="<?php echo esc_attr( $s['remote_api_key'] ); ?>" class="large-text code">
							<p><label><input type="checkbox" name="remote_api_regenerate" value="1"> <?php esc_html_e( 'Generate a NEW key when I save (invalidates the current one)', 'acps-media-cleanup' ); ?></label></p>
							<p class="description"><?php esc_html_e( 'Send this on every request as the header X-ACPS-Key, or as a "key" field. Keep it secret.', 'acps-media-cleanup' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="remote_api_folder"><?php esc_html_e( 'Default folder for uploads', 'acps-media-cleanup' ); ?></label></th>
						<td>
							<select id="remote_api_folder" name="remote_api_folder">
								<option value="0"><?php esc_html_e( '— Uncategorized —', 'acps-media-cleanup' ); ?></option>
								<?php foreach ( (array) $tree as $f ) : ?>
									<option value="<?php echo esc_attr( $f['id'] ); ?>" <?php selected( (int) $s['remote_api_folder'], (int) $f['id'] ); ?>><?php echo esc_html( str_repeat( '— ', (int) $f['depth'] ) . $f['name'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Uploads with no folder_id given are filed here. A request can override this with a folder_id field.', 'acps-media-cleanup' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Limits', 'acps-media-cleanup' ); ?></th>
						<td>
							<label><?php esc_html_e( 'Max requests per minute (per IP):', 'acps-media-cleanup' ); ?> <input type="number" min="1" max="1000" name="remote_api_rate_per_min" value="<?php echo esc_attr( $s['remote_api_rate_per_min'] ); ?>" class="small-text"></label><br>
							<label><?php esc_html_e( 'Max uploads per day (all clients, 0 = unlimited):', 'acps-media-cleanup' ); ?> <input type="number" min="0" max="100000" name="remote_api_daily_cap" value="<?php echo esc_attr( $s['remote_api_daily_cap'] ); ?>" class="small-text"></label><br>
							<label><?php esc_html_e( 'Max upload size (MB):', 'acps-media-cleanup' ); ?> <input type="number" min="1" max="512" name="remote_api_max_mb" value="<?php echo esc_attr( $s['remote_api_max_mb'] ); ?>" class="small-text"></label>
							<p class="description"><?php esc_html_e( 'After too many wrong-key attempts an IP is locked out for 15 minutes. Only real image files are accepted.', 'acps-media-cleanup' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Deleting', 'acps-media-cleanup' ); ?></th>
						<td><label><input type="checkbox" name="remote_api_allow_delete" value="1" <?php checked( $s['remote_api_allow_delete'] ); ?>> <?php esc_html_e( 'Allow the delete endpoint (moves files to Trash — always reversible)', 'acps-media-cleanup' ); ?></label></td>
					</tr>
				</table>

				<?php if ( '' !== $base ) : ?>
					<h3><?php esc_html_e( 'Endpoints', 'acps-media-cleanup' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Send the key as the X-ACPS-Key header. Example (upload a photo):', 'acps-media-cleanup' ); ?></p>
					<textarea readonly rows="5" class="large-text code" onclick="this.select()">POST <?php echo esc_textarea( $base ); ?>/upload
  Header: X-ACPS-Key: <?php echo esc_textarea( $s['remote_api_key'] ); ?>

  multipart form field "file"  (or JSON { "filename":"a.jpg", "content_base64":"…", "folder_id":0 })

GET  <?php echo esc_textarea( $base ); ?>/list?key=…
POST <?php echo esc_textarea( $base ); ?>/move    { id, folder_id }
POST <?php echo esc_textarea( $base ); ?>/delete  { id }
GET  <?php echo esc_textarea( $base ); ?>/ping</textarea>
				<?php endif; ?>

				<?php submit_button( __( 'Save remote API settings', 'acps-media-cleanup' ) ); ?>
			</form>
		</div>
		<?php
	}

	public function save_remote() {
		if ( ! current_user_can( ACPS_MC_CAP ) ) {
			wp_die( esc_html__( 'Permission denied.', 'acps-media-cleanup' ) );
		}
		check_admin_referer( 'acps_mc_remote', 'acps_mc_remote_nonce' );

		$clean = ACPS_MC_Settings::sanitize_remote( wp_unslash( $_POST ) );
		update_option( ACPS_MC_OPT_SETTINGS, $clean );

		wp_safe_redirect(
			add_query_arg(
				array( 'page' => self::REMOTE_SLUG, 'acps_mc_saved' => 1 ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
