<?php
/**
 * The Media Manager: a full-screen replacement for the Media Library screen,
 * plus additive enhancements to the core media modal (copy URL, folder,
 * where-used) that are safe for Beaver Builder / FileBird.
 *
 * @package ACPS_Media_Cleanup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACPS_MC_Manager {

	const SLUG = 'acps-media-manager';

	/** @var ACPS_MC_Admin */
	protected $admin;

	public function __construct( $admin = null ) {
		$this->admin = $admin;
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_modal' ) );

		// Additive fields in the attachment details (modal + edit screen).
		add_filter( 'attachment_fields_to_edit', array( $this, 'attachment_fields' ), 20, 2 );

		// Optionally make this the default Media screen.
		add_action( 'load-upload.php', array( $this, 'maybe_redirect_media' ) );
	}

	public function register_menu() {
		// Everything lives UNDER the core "Media" menu — no separate top-level tab.
		add_submenu_page(
			'upload.php',
			__( 'Media Manager', 'acps-media-cleanup' ),
			__( 'Media Manager', 'acps-media-cleanup' ),
			'upload_files',
			self::SLUG,
			array( $this, 'render' )
		);

		if ( $this->admin ) {
			add_submenu_page(
				'upload.php',
				__( 'Media Trash & Log', 'acps-media-cleanup' ),
				__( 'Media Trash', 'acps-media-cleanup' ),
				ACPS_MC_CAP,
				ACPS_MC_Admin::TRASH_SLUG,
				array( $this->admin, 'render_trash_page' )
			);
			add_submenu_page(
				'upload.php',
				__( 'Media Manager Settings', 'acps-media-cleanup' ),
				__( 'Media Settings', 'acps-media-cleanup' ),
				ACPS_MC_CAP,
				ACPS_MC_Admin::SETTINGS_SLUG,
				array( $this->admin, 'render_settings_page' )
			);
		}
	}

	public static function page_url() {
		return add_query_arg( 'page', self::SLUG, admin_url( 'upload.php' ) );
	}

	/**
	 * Redirect the classic Media Library list to the manager when enabled.
	 */
	public function maybe_redirect_media() {
		if ( ! ACPS_MC_Settings::get( 'replace_media_screen' ) ) {
			return;
		}
		// Let people opt back to the classic screen, never touch the single-item
		// detail view, and never loop when already on one of our own pages.
		if ( isset( $_GET['classic'] ) || isset( $_GET['item'] ) || isset( $_GET['page'] ) ) {
			return;
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}
		wp_safe_redirect( self::page_url() );
		exit;
	}

	public function enqueue( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}

		// The uploader (plupload / wp.Uploader) and media models.
		wp_enqueue_media();

		wp_enqueue_style( 'acps-mm', ACPS_MC_URL . 'assets/manager.css', array( 'dashicons' ), ACPS_MC_VERSION );
		wp_enqueue_script( 'acps-mm', ACPS_MC_URL . 'assets/manager.js', array( 'jquery' ), ACPS_MC_VERSION, true );

		wp_localize_script(
			'acps-mm',
			'ACPS_MM',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'acps_mm' ),
				'scanNonce'    => wp_create_nonce( 'acps_mc' ),
				'heicSupport'  => ACPS_MC_Heic::supported(),
				'i18n'    => array(
					'allMedia'      => __( 'All media', 'acps-media-cleanup' ),
					'unfiled'       => __( 'Uncategorized', 'acps-media-cleanup' ),
					'unused'        => __( 'Unused', 'acps-media-cleanup' ),
					'used'          => __( 'Used', 'acps-media-cleanup' ),
					'loading'       => __( 'Loading…', 'acps-media-cleanup' ),
					'noResults'     => __( 'No media found here.', 'acps-media-cleanup' ),
					'copied'        => __( 'Copied!', 'acps-media-cleanup' ),
					'copyUrl'       => __( 'Copy URL', 'acps-media-cleanup' ),
					'whereUsed'     => __( 'Where is this used?', 'acps-media-cleanup' ),
					'checking'      => __( 'Checking…', 'acps-media-cleanup' ),
					'notUsed'       => __( 'Not found anywhere on the site.', 'acps-media-cleanup' ),
					'usedIn'        => __( 'Used in', 'acps-media-cleanup' ),
					'saved'         => __( 'Saved', 'acps-media-cleanup' ),
					'move'          => __( 'Move', 'acps-media-cleanup' ),
					'moveToFolder'  => __( 'Move to folder…', 'acps-media-cleanup' ),
					'newFolder'     => __( 'New folder', 'acps-media-cleanup' ),
					'newFolderName' => __( 'New folder name:', 'acps-media-cleanup' ),
					'selected'      => __( 'selected', 'acps-media-cleanup' ),
					'deleteSel'     => __( 'Delete selected', 'acps-media-cleanup' ),
					'confirmTrash'  => __( 'Move the selected files to Trash? They can be restored from Media Cleanup › Trash.', 'acps-media-cleanup' ),
					'usedWarn'      => __( 'Warning: some of these files appear to be USED on the site:', 'acps-media-cleanup' ),
					'deleteAnyway'  => __( 'Delete anyway', 'acps-media-cleanup' ),
					'cancel'        => __( 'Cancel', 'acps-media-cleanup' ),
					'uploaded'      => __( 'Uploaded', 'acps-media-cleanup' ),
					'placeInFolder' => __( 'Place in folder', 'acps-media-cleanup' ),
					'done'          => __( 'Done', 'acps-media-cleanup' ),
					'bulkAlt'       => __( 'Set alt text on selected', 'acps-media-cleanup' ),
					'altPrompt'     => __( 'Alt text to apply to all selected files:', 'acps-media-cleanup' ),
					'error'         => __( 'Something went wrong. Please try again.', 'acps-media-cleanup' ),
					'readonly'      => __( 'Folders are read-only (no FileBird detected).', 'acps-media-cleanup' ),
					'dropHere'      => __( 'Drop files to upload', 'acps-media-cleanup' ),
					'uploading'     => __( 'Uploading…', 'acps-media-cleanup' ),
					'scanNow'       => __( 'Scan usage now', 'acps-media-cleanup' ),
					'scanning'      => __( 'Scanning…', 'acps-media-cleanup' ),
					'scanDone'      => __( 'Scan complete', 'acps-media-cleanup' ),
					'convertHeic'   => __( 'Convert to JPEG', 'acps-media-cleanup' ),
					'converting'    => __( 'Converting…', 'acps-media-cleanup' ),
					'usedItem'      => __( 'Used on the site', 'acps-media-cleanup' ),
					'unusedItem'    => __( 'Not used anywhere', 'acps-media-cleanup' ),
					'unknownItem'   => __( 'Usage unknown (run a scan)', 'acps-media-cleanup' ),
					'rename'        => __( 'Rename file', 'acps-media-cleanup' ),
					'renamePrompt'  => __( 'New file name (without extension):', 'acps-media-cleanup' ),
					'renameUsed'    => __( 'This file is used in %d place(s). Renaming will break those links. Rename anyway?', 'acps-media-cleanup' ),
					'renameFolder'  => __( 'Rename folder', 'acps-media-cleanup' ),
					'deleteFolder'  => __( 'Delete folder', 'acps-media-cleanup' ),
					'deleteFolderQ' => __( 'Delete this folder? Files inside are kept and moved up a level — nothing is deleted.', 'acps-media-cleanup' ),
					'anyPage'       => __( '— Used on page: any —', 'acps-media-cleanup' ),
					'copyLink'      => __( 'Copy link', 'acps-media-cleanup' ),
					'genericName'   => __( 'This looks like a generic camera name (e.g. IMG_1234). Please give it a descriptive name before continuing.', 'acps-media-cleanup' ),
					'renameToGo'    => __( 'Rename to continue', 'acps-media-cleanup' ),
					'sizeSmall'     => __( 'Small', 'acps-media-cleanup' ),
					'sizeMedium'    => __( 'Medium', 'acps-media-cleanup' ),
					'sizeLarge'     => __( 'Large', 'acps-media-cleanup' ),
					'viewClassic'   => __( 'Classic', 'acps-media-cleanup' ),
					'viewRefined'   => __( 'Refined', 'acps-media-cleanup' ),
					'folderCount'   => __( '%d files', 'acps-media-cleanup' ),
				),
			)
		);
	}

	/**
	 * Tiny always-on admin script that powers the copy-URL button, the folder
	 * selector and the where-used lookup inside the core media modal / edit page.
	 */
	public function enqueue_modal( $hook ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}
		// Skip our own manager page (it has its own script).
		if ( false !== strpos( (string) $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'acps-mm-modal', ACPS_MC_URL . 'assets/modal.css', array(), ACPS_MC_VERSION );
		wp_enqueue_script( 'acps-mm-modal', ACPS_MC_URL . 'assets/modal.js', array( 'jquery' ), ACPS_MC_VERSION, true );
		wp_localize_script(
			'acps-mm-modal',
			'ACPS_MM_MODAL',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'acps_mm' ),
				'managerUrl'  => self::page_url(),
				'i18n'        => array(
					'copied'    => __( 'Copied!', 'acps-media-cleanup' ),
					'checking'  => __( 'Checking…', 'acps-media-cleanup' ),
					'notUsed'   => __( 'Not found anywhere on the site.', 'acps-media-cleanup' ),
					'usedIn'    => __( 'Used in', 'acps-media-cleanup' ),
					'saved'     => __( 'Saved', 'acps-media-cleanup' ),
				),
			)
		);
	}

	/**
	 * Add a Copy-URL field, a folder selector and a where-used control to the
	 * attachment details panel (shown in the modal and the edit-media screen).
	 *
	 * @param array   $fields Existing fields.
	 * @param WP_Post $post   Attachment.
	 * @return array
	 */
	public function attachment_fields( $fields, $post ) {
		$url = wp_get_attachment_url( $post->ID );

		$fields['acps_copy_url'] = array(
			'label' => __( 'File URL', 'acps-media-cleanup' ),
			'input' => 'html',
			'html'  => '<input type="text" class="widefat acps-mm-url-field" readonly value="' . esc_attr( $url ) . '" />'
				. '<button type="button" class="button acps-mm-copy-btn" data-url="' . esc_attr( $url ) . '">' . esc_html__( 'Copy URL', 'acps-media-cleanup' ) . '</button>',
		);

		$folders = new ACPS_MC_Folders();
		if ( $folders->is_writable() ) {
			$current = $folders->folder_for( $post->ID );
			$opts    = '<option value="0">' . esc_html__( '— Unfiled —', 'acps-media-cleanup' ) . '</option>';
			foreach ( $folders->flat_tree() as $f ) {
				$opts .= '<option value="' . esc_attr( $f['id'] ) . '" ' . selected( $current, (int) $f['id'], false ) . '>'
					. esc_html( str_repeat( '— ', (int) $f['depth'] ) . $f['name'] ) . '</option>';
			}
			$fields['acps_folder'] = array(
				'label' => __( 'Folder', 'acps-media-cleanup' ),
				'input' => 'html',
				'html'  => '<select class="acps-mm-folder-select" data-id="' . esc_attr( $post->ID ) . '">' . $opts . '</select>',
			);
		}

		$fields['acps_where_used'] = array(
			'label' => __( 'Where used', 'acps-media-cleanup' ),
			'input' => 'html',
			'html'  => '<button type="button" class="button acps-mm-where-btn" data-id="' . esc_attr( $post->ID ) . '">' . esc_html__( 'Where is this used?', 'acps-media-cleanup' ) . '</button>'
				. '<div class="acps-mm-where-out"></div>',
		);

		return $fields;
	}

	/**
	 * Render the full-screen manager shell (JS fills it in).
	 */
	public function render() {
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'acps-media-cleanup' ) );
		}
		$folders  = new ACPS_MC_Folders();
		$writable = $folders->is_writable();
		?>
		<div class="wrap acps-mm-wrap">
			<!-- Top-right view/size controls -->
			<div class="acps-mm-headright">
				<span class="acps-mm-viewtoggle" role="group" aria-label="<?php esc_attr_e( 'View style', 'acps-media-cleanup' ); ?>">
					<button type="button" class="button acps-mm-viewbtn" data-view="classic"><span class="dashicons dashicons-grid-view"></span> <?php esc_html_e( 'Classic', 'acps-media-cleanup' ); ?></button>
					<button type="button" class="button acps-mm-viewbtn" data-view="refined"><span class="dashicons dashicons-screenoptions"></span> <?php esc_html_e( 'Refined', 'acps-media-cleanup' ); ?></button>
				</span>
				<label class="acps-mm-sizelbl"><?php esc_html_e( 'Size', 'acps-media-cleanup' ); ?>
					<select id="acps-mm-size">
						<option value="130"><?php esc_html_e( 'Small', 'acps-media-cleanup' ); ?></option>
						<option value="180" selected><?php esc_html_e( 'Medium', 'acps-media-cleanup' ); ?></option>
						<option value="250"><?php esc_html_e( 'Large', 'acps-media-cleanup' ); ?></option>
					</select>
				</label>
			</div>

			<h1 class="wp-heading-inline"><span class="dashicons dashicons-format-gallery"></span> <?php esc_html_e( 'Media Manager', 'acps-media-cleanup' ); ?></h1>
			<button type="button" class="page-title-action" id="acps-mm-upload"><?php esc_html_e( 'Upload files', 'acps-media-cleanup' ); ?></button>
			<a href="<?php echo esc_url( add_query_arg( 'page', 'acps-mc-trash', admin_url( 'upload.php' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Trash', 'acps-media-cleanup' ); ?></a>
			<a href="<?php echo esc_url( add_query_arg( 'page', 'acps-mc-settings', admin_url( 'upload.php' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Settings', 'acps-media-cleanup' ); ?></a>
			<a href="<?php echo esc_url( admin_url( 'upload.php?classic=1' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Classic library', 'acps-media-cleanup' ); ?></a>
			<hr class="wp-header-end">

			<?php if ( ! $writable ) : ?>
				<div class="notice notice-info inline"><p><?php esc_html_e( 'No FileBird folders detected — files are grouped by upload date and cannot be moved between folders.', 'acps-media-cleanup' ); ?></p></div>
			<?php endif; ?>

			<!-- Cleanup / scan bar -->
			<div class="acps-mm-scanbar">
				<span class="acps-mm-legend">
					<span class="acps-mm-dot used"></span> <?php esc_html_e( 'Used', 'acps-media-cleanup' ); ?>
					<span class="acps-mm-dot unused"></span> <?php esc_html_e( 'Unused', 'acps-media-cleanup' ); ?>
					<span class="acps-mm-dot unknown"></span> <?php esc_html_e( 'Not scanned', 'acps-media-cleanup' ); ?>
				</span>
				<span class="acps-mm-scaninfo" id="acps-mm-scaninfo"></span>
				<span class="acps-mm-toolbar-spacer"></span>
				<div class="acps-mm-scanprog" id="acps-mm-scanprog" style="display:none;"><div class="acps-mm-scanprog-fill"></div></div>
				<button type="button" class="button" id="acps-mm-scannow"><?php esc_html_e( 'Scan usage now', 'acps-media-cleanup' ); ?></button>
			</div>

			<div class="acps-mm-layout">
				<aside class="acps-mm-sidebar" id="acps-mm-folders">
					<p class="acps-mm-muted"><?php esc_html_e( 'Loading folders…', 'acps-media-cleanup' ); ?></p>
				</aside>

				<main class="acps-mm-main">
					<div class="acps-mm-toolbar">
						<input type="search" id="acps-mm-search" class="acps-mm-search" placeholder="<?php esc_attr_e( 'Search files…', 'acps-media-cleanup' ); ?>">
						<select id="acps-mm-type">
							<option value=""><?php esc_html_e( 'All types', 'acps-media-cleanup' ); ?></option>
							<option value="image"><?php esc_html_e( 'Images', 'acps-media-cleanup' ); ?></option>
							<option value="application/pdf"><?php esc_html_e( 'PDFs', 'acps-media-cleanup' ); ?></option>
							<option value="video"><?php esc_html_e( 'Video', 'acps-media-cleanup' ); ?></option>
							<option value="audio"><?php esc_html_e( 'Audio', 'acps-media-cleanup' ); ?></option>
							<option value="application"><?php esc_html_e( 'Documents', 'acps-media-cleanup' ); ?></option>
						</select>
						<select id="acps-mm-sort">
							<option value="date"><?php esc_html_e( 'Newest first', 'acps-media-cleanup' ); ?></option>
							<option value="date_asc"><?php esc_html_e( 'Oldest first', 'acps-media-cleanup' ); ?></option>
							<option value="title"><?php esc_html_e( 'Name A–Z', 'acps-media-cleanup' ); ?></option>
						</select>
						<select id="acps-mm-page"><option value=""><?php esc_html_e( '— Used on page: any —', 'acps-media-cleanup' ); ?></option></select>
						<label class="acps-mm-subtoggle"><input type="checkbox" id="acps-mm-recursive"> <?php esc_html_e( 'Include sub-folders', 'acps-media-cleanup' ); ?></label>
						<span class="acps-mm-toolbar-spacer"></span>
						<label class="acps-mm-selectall-lbl"><input type="checkbox" id="acps-mm-selectall"> <?php esc_html_e( 'Select', 'acps-media-cleanup' ); ?></label>
					</div>

					<div class="acps-mm-bulkbar" id="acps-mm-bulkbar" style="display:none;">
						<span><strong id="acps-mm-selcount">0</strong> <?php esc_html_e( 'selected', 'acps-media-cleanup' ); ?></span>
						<?php if ( $writable ) : ?>
							<button type="button" class="button" id="acps-mm-bulk-move"><?php esc_html_e( 'Move to folder…', 'acps-media-cleanup' ); ?></button>
						<?php endif; ?>
						<button type="button" class="button" id="acps-mm-bulk-alt"><?php esc_html_e( 'Set alt text…', 'acps-media-cleanup' ); ?></button>
						<button type="button" class="button button-link-delete" id="acps-mm-bulk-delete"><?php esc_html_e( 'Delete selected', 'acps-media-cleanup' ); ?></button>
						<button type="button" class="button-link" id="acps-mm-bulk-clear"><?php esc_html_e( 'Clear', 'acps-media-cleanup' ); ?></button>
					</div>

					<div class="acps-mm-count" id="acps-mm-count"></div>
					<div class="acps-mm-grid" id="acps-mm-grid"></div>
				</main>
			</div>
		</div>

		<!-- Upload progress panel -->
		<div class="acps-mm-uploads" id="acps-mm-uploads" style="display:none;">
			<div class="acps-mm-uploads-head">
				<strong><?php esc_html_e( 'Uploads', 'acps-media-cleanup' ); ?></strong>
				<button type="button" class="button-link acps-mm-uploads-close" id="acps-mm-uploads-close">&times;</button>
			</div>
			<div class="acps-mm-uploads-list" id="acps-mm-uploads-list"></div>
		</div>

		<!-- Full-page drop overlay -->
		<div class="acps-mm-dropmask" id="acps-mm-dropmask"><div class="acps-mm-dropmask-inner"><span class="dashicons dashicons-upload"></span><p><?php esc_html_e( 'Drop files to upload', 'acps-media-cleanup' ); ?></p></div></div>

		<!-- Detail drawer -->
		<div class="acps-mm-drawer" id="acps-mm-drawer" aria-hidden="true">
			<div class="acps-mm-drawer-inner" id="acps-mm-drawer-inner"></div>
		</div>
		<div class="acps-mm-backdrop" id="acps-mm-backdrop" style="display:none;"></div>
		<?php
	}
}
