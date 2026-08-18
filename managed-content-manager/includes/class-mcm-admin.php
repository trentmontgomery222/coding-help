<?php
/**
 * wp-admin screens: content blocks, editor accounts, settings.
 *
 * All screens require the manage_options capability (i.e. real site admins).
 *
 * @package mcm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCM_Admin {

	/** @var MCM_Admin|null */
	private static $instance = null;

	const CAP = 'manage_options';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );

		// Form handlers (admin-post.php).
		add_action( 'admin_post_mcm_save_block', array( $this, 'handle_save_block' ) );
		add_action( 'admin_post_mcm_delete_block', array( $this, 'handle_delete_block' ) );
		add_action( 'admin_post_mcm_save_editor', array( $this, 'handle_save_editor' ) );
		add_action( 'admin_post_mcm_delete_editor', array( $this, 'handle_delete_editor' ) );
		add_action( 'admin_post_mcm_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_mcm_add_beaver_block', array( $this, 'handle_add_beaver_block' ) );
		add_action( 'admin_post_mcm_add_beaver_module', array( $this, 'handle_add_beaver_module' ) );
	}

	public function assets( $hook ) {
		if ( false === strpos( $hook, 'mcm' ) ) {
			return;
		}
		wp_enqueue_style( 'mcm-admin', MCM_URL . 'assets/admin.css', array(), MCM_VERSION );
	}

	// -----------------------------------------------------------------------
	// Menu
	// -----------------------------------------------------------------------
	public function menu() {
		add_menu_page(
			__( 'Content Manager', 'mcm' ),
			__( 'Content Manager', 'mcm' ),
			self::CAP,
			'mcm-blocks',
			array( $this, 'render_blocks' ),
			'dashicons-edit-page',
			58
		);
		add_submenu_page( 'mcm-blocks', __( 'Content Blocks', 'mcm' ), __( 'Content Blocks', 'mcm' ), self::CAP, 'mcm-blocks', array( $this, 'render_blocks' ) );
		add_submenu_page( 'mcm-blocks', __( 'Beaver Builder', 'mcm' ), __( 'Beaver Builder', 'mcm' ), self::CAP, 'mcm-beaver', array( $this, 'render_beaver' ) );
		add_submenu_page( 'mcm-blocks', __( 'Editors', 'mcm' ), __( 'Editors', 'mcm' ), self::CAP, 'mcm-editors', array( $this, 'render_editors' ) );
		add_submenu_page( 'mcm-blocks', __( 'Settings', 'mcm' ), __( 'Settings', 'mcm' ), self::CAP, 'mcm-settings', array( $this, 'render_settings' ) );
	}

	private function guard() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'mcm' ) );
		}
	}

	/**
	 * Small helper to build an admin URL back to one of our screens.
	 */
	private function screen_url( $page, $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	private function notice() {
		if ( isset( $_GET['mcm_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$msg = sanitize_text_field( wp_unslash( $_GET['mcm_msg'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}
		if ( isset( $_GET['mcm_err'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$err = sanitize_text_field( wp_unslash( $_GET['mcm_err'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $err ) . '</p></div>';
		}
	}

	// =======================================================================
	// BLOCKS
	// =======================================================================
	public function render_blocks() {
		$this->guard();
		$edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="wrap mcm-wrap">';
		echo '<h1>' . esc_html__( 'Content Blocks', 'mcm' ) . '</h1>';
		$this->notice();

		echo '<p class="description">' . esc_html__( 'A content block is one editable piece of text. Drop it into any page or post with the shortcode shown below, then assign it to an editor so they can update it from the front-end portal.', 'mcm' ) . '</p>';

		$this->render_block_form( $edit_id );
		$this->render_block_list();
		echo '</div>';
	}

	private function render_block_form( $edit_id = 0 ) {
		$block     = $edit_id ? MCM_DB::get_block( $edit_id ) : null;
		$is_beaver = $block && in_array( $block->source, array( 'beaver', 'beaver_module' ), true );
		$is_module = $block && 'beaver_module' === $block->source;
		$types     = array(
			'text'     => __( 'Single line text', 'mcm' ),
			'textarea' => __( 'Multi-line text', 'mcm' ),
			'richtext' => __( 'Rich text', 'mcm' ),
		);
		?>
		<div class="mcm-card">
			<h2>
				<?php
				echo $block
					? ( $is_beaver ? esc_html__( 'Edit Beaver Builder block', 'mcm' ) : esc_html__( 'Edit block', 'mcm' ) )
					: esc_html__( 'Add a block', 'mcm' );
				?>
			</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'mcm_save_block' ); ?>
				<input type="hidden" name="action" value="mcm_save_block" />
				<input type="hidden" name="id" value="<?php echo esc_attr( $block->id ?? 0 ); ?>" />
				<table class="form-table" role="presentation">
					<?php if ( $is_beaver ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Beaver Builder source', 'mcm' ); ?></th>
							<td>
								<?php
								$src_post  = get_post( (int) $block->post_id );
								$src_title = $src_post ? $src_post->post_title : ( '#' . (int) $block->post_id );
								?>
								<code><?php echo esc_html( $src_title ); ?></code>
								&nbsp;→&nbsp;
								<code><?php echo esc_html( $block->node_id . ( $is_module ? ' · ' . __( 'whole module', 'mcm' ) : ' · ' . $block->field_key ) ); ?></code>
								<p class="description">
									<?php
									echo $is_module
										? esc_html__( 'This block lets editors change the entire Beaver Builder module (all its fields). Content is stored by Beaver Builder, not here.', 'mcm' )
										: esc_html__( 'This block edits a live Beaver Builder module field. Its content is stored by Beaver Builder, not here.', 'mcm' );
									?>
								</p>
							</td>
						</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><label for="mcm-label"><?php esc_html_e( 'Label', 'mcm' ); ?></label></th>
						<td><input name="label" id="mcm-label" type="text" class="regular-text" required value="<?php echo esc_attr( $block->label ?? '' ); ?>" /></td>
					</tr>
					<?php if ( ! $is_beaver ) : ?>
						<tr>
							<th scope="row"><label for="mcm-slug"><?php esc_html_e( 'Slug', 'mcm' ); ?></label></th>
							<td>
								<input name="slug" id="mcm-slug" type="text" class="regular-text" value="<?php echo esc_attr( $block->slug ?? '' ); ?>" placeholder="hero-title" />
								<p class="description"><?php esc_html_e( 'Lowercase identifier used in the shortcode. Leave label filled and this can be derived automatically.', 'mcm' ); ?></p>
							</td>
						</tr>
					<?php endif; ?>
					<?php if ( ! $is_module ) : ?>
						<tr>
							<th scope="row"><label for="mcm-type"><?php esc_html_e( 'Field type', 'mcm' ); ?></label></th>
							<td>
								<select name="type" id="mcm-type">
									<?php foreach ( $types as $key => $lbl ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $block->type ?? 'text', $key ); ?>><?php echo esc_html( $lbl ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="mcm-max"><?php esc_html_e( 'Max length', 'mcm' ); ?></label></th>
							<td>
								<input name="max_length" id="mcm-max" type="number" min="0" value="<?php echo esc_attr( $block->max_length ?? 0 ); ?>" />
								<p class="description"><?php esc_html_e( '0 = no limit. Enforced both in the editor UI and on save.', 'mcm' ); ?></p>
							</td>
						</tr>
					<?php endif; ?>
					<?php if ( ! $is_beaver ) : ?>
						<tr>
							<th scope="row"><label for="mcm-content"><?php esc_html_e( 'Content', 'mcm' ); ?></label></th>
							<td><textarea name="content" id="mcm-content" rows="4" class="large-text"><?php echo esc_textarea( $block->content ?? '' ); ?></textarea></td>
						</tr>
					<?php endif; ?>
				</table>
				<p class="submit">
					<button type="submit" class="button button-primary"><?php echo $block ? esc_html__( 'Update block', 'mcm' ) : esc_html__( 'Add block', 'mcm' ); ?></button>
					<?php if ( $block ) : ?>
						<a href="<?php echo esc_url( $this->screen_url( 'mcm-blocks' ) ); ?>" class="button"><?php esc_html_e( 'Cancel', 'mcm' ); ?></a>
					<?php endif; ?>
				</p>
			</form>
		</div>
		<?php
	}

	private function render_block_list() {
		$blocks = MCM_DB::get_blocks();
		echo '<h2 class="mcm-h2">' . esc_html__( 'All blocks', 'mcm' ) . '</h2>';
		if ( empty( $blocks ) ) {
			echo '<p>' . esc_html__( 'No blocks yet.', 'mcm' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Label', 'mcm' ) . '</th>';
		echo '<th>' . esc_html__( 'Source', 'mcm' ) . '</th>';
		echo '<th>' . esc_html__( 'Where', 'mcm' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'mcm' ) . '</th>';
		echo '<th>' . esc_html__( 'Preview', 'mcm' ) . '</th>';
		echo '<th>' . esc_html__( 'Last updated', 'mcm' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'mcm' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $blocks as $b ) {
			$is_module = 'beaver_module' === $b->source;
			$is_beaver = 'beaver' === $b->source || $is_module;
			$preview   = wp_strip_all_tags( (string) $b->content );
			$preview   = mb_strlen( $preview ) > 60 ? mb_substr( $preview, 0, 60 ) . '…' : $preview;
			$updated   = $b->updated_at ? esc_html( $b->updated_at ) : '—';
			$by        = $b->updated_by ? ' <span class="mcm-muted">(' . esc_html( $b->updated_by ) . ')</span>' : '';
			$del_url   = wp_nonce_url(
				$this->screen_url_admin_post( 'mcm_delete_block', array( 'id' => $b->id ) ),
				'mcm_delete_block_' . $b->id
			);

			if ( $is_beaver ) {
				$src_post = get_post( (int) $b->post_id );
				$title    = $src_post ? $src_post->post_title : ( '#' . (int) $b->post_id );
				$detail   = $is_module ? esc_html__( 'whole module', 'mcm' ) : esc_html( $b->field_key );
				$where    = esc_html( $title ) . ' <span class="mcm-muted">(' . $detail . ')</span>';
				$source   = '<span class="mcm-tag mcm-tag-bb">' . ( $is_module ? esc_html__( 'BB module', 'mcm' ) : esc_html__( 'BB field', 'mcm' ) ) . '</span>';
			} else {
				$where  = '<code class="mcm-code">[managed_content slug="' . esc_html( $b->slug ) . '"]</code>';
				$source = '<span class="mcm-tag">' . esc_html__( 'Custom', 'mcm' ) . '</span>';
			}

			echo '<tr>';
			echo '<td><strong>' . esc_html( $b->label ) . '</strong></td>';
			echo '<td>' . $source . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped above.
			echo '<td>' . $where . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped above.
			echo '<td>' . esc_html( $b->type ) . '</td>';
			echo '<td>' . esc_html( $preview ) . '</td>';
			echo '<td>' . $updated . $by . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped above.
			echo '<td>';
			echo '<a class="button button-small" href="' . esc_url( $this->screen_url( 'mcm-blocks', array( 'edit' => $b->id ) ) ) . '">' . esc_html__( 'Edit', 'mcm' ) . '</a> ';
			echo '<a class="button button-small button-link-delete" href="' . esc_url( $del_url ) . '" onclick="return confirm(\'' . esc_js( __( 'Delete this block?', 'mcm' ) ) . '\');">' . esc_html__( 'Delete', 'mcm' ) . '</a>';
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Build an admin-post.php URL for a given action.
	 */
	private function screen_url_admin_post( $action, $args = array() ) {
		return add_query_arg( array_merge( array( 'action' => $action ), $args ), admin_url( 'admin-post.php' ) );
	}

	public function handle_save_block() {
		$this->guard();
		check_admin_referer( 'mcm_save_block' );

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		// Beaver-sourced blocks: only their label/type/max-length are editable
		// here; slug, content and the module reference must not be rewritten.
		if ( $id ) {
			$existing = MCM_DB::get_block( $id );
			if ( $existing && in_array( $existing->source, array( 'beaver', 'beaver_module' ), true ) ) {
				MCM_DB::update_block_meta(
					$id,
					isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : $existing->label,
					isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : $existing->type,
					isset( $_POST['max_length'] ) ? absint( $_POST['max_length'] ) : (int) $existing->max_length
				);
				$this->redirect_back( 'mcm-blocks', array( 'mcm_msg' => __( 'Block saved.', 'mcm' ) ) );
			}
		}

		$result = MCM_DB::save_block(
			array(
				'id'         => isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0,
				'label'      => isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '',
				'slug'       => isset( $_POST['slug'] ) && '' !== trim( (string) $_POST['slug'] )
					? sanitize_title( wp_unslash( $_POST['slug'] ) )
					: sanitize_title( isset( $_POST['label'] ) ? wp_unslash( $_POST['label'] ) : '' ),
				'type'       => isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'text',
				'max_length' => isset( $_POST['max_length'] ) ? absint( $_POST['max_length'] ) : 0,
				'content'    => isset( $_POST['content'] ) ? wp_unslash( $_POST['content'] ) : '',
			),
			$this->current_admin_name()
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect_back( 'mcm-blocks', array( 'mcm_err' => $result->get_error_message() ) );
		}
		$this->redirect_back( 'mcm-blocks', array( 'mcm_msg' => __( 'Block saved.', 'mcm' ) ) );
	}

	public function handle_delete_block() {
		$this->guard();
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'mcm_delete_block_' . $id );
		MCM_DB::delete_block( $id );
		$this->redirect_back( 'mcm-blocks', array( 'mcm_msg' => __( 'Block deleted.', 'mcm' ) ) );
	}

	// =======================================================================
	// EDITORS
	// =======================================================================
	public function render_editors() {
		$this->guard();
		$edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="wrap mcm-wrap">';
		echo '<h1>' . esc_html__( 'Editors', 'mcm' ) . '</h1>';
		$this->notice();
		echo '<p class="description">' . esc_html__( 'Editor accounts are completely separate from WordPress users. They log in through the front-end portal and can only touch the blocks you assign here.', 'mcm' ) . '</p>';
		$this->render_editor_form( $edit_id );
		$this->render_editor_list();
		echo '</div>';
	}

	private function render_editor_form( $edit_id = 0 ) {
		$editor    = $edit_id ? MCM_DB::get_editor( $edit_id ) : null;
		$blocks    = MCM_DB::get_blocks();
		$allowed   = MCM_DB::editor_allowed_ids( $editor );
		$pages     = MCM_Beaver::is_active() ? MCM_Beaver::get_bb_posts() : array();
		$allow_pg  = MCM_DB::editor_allowed_page_ids( $editor );
		?>
		<div class="mcm-card">
			<h2><?php echo $editor ? esc_html__( 'Edit editor', 'mcm' ) : esc_html__( 'Add an editor', 'mcm' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'mcm_save_editor' ); ?>
				<input type="hidden" name="action" value="mcm_save_editor" />
				<input type="hidden" name="id" value="<?php echo esc_attr( $editor->id ?? 0 ); ?>" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="mcm-username"><?php esc_html_e( 'Username', 'mcm' ); ?></label></th>
						<td><input name="username" id="mcm-username" type="text" class="regular-text" required value="<?php echo esc_attr( $editor->username ?? '' ); ?>" autocomplete="off" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="mcm-display"><?php esc_html_e( 'Display name', 'mcm' ); ?></label></th>
						<td><input name="display_name" id="mcm-display" type="text" class="regular-text" value="<?php echo esc_attr( $editor->display_name ?? '' ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="mcm-password"><?php esc_html_e( 'Password', 'mcm' ); ?></label></th>
						<td>
							<input name="password" id="mcm-password" type="text" class="regular-text" autocomplete="new-password" value="" />
							<p class="description">
								<?php
								echo $editor
									? esc_html__( 'Leave blank to keep the current password.', 'mcm' )
									: esc_html__( 'Required. Shown in the clear here so you can copy it to the editor.', 'mcm' );
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Active', 'mcm' ); ?></th>
						<td><label><input type="checkbox" name="active" value="1" <?php checked( 1, (int) ( $editor->active ?? 1 ) ); ?> /> <?php esc_html_e( 'This editor may log in', 'mcm' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Editable pages', 'mcm' ); ?></th>
						<td>
							<?php if ( empty( $pages ) ) : ?>
								<p class="mcm-muted"><?php esc_html_e( 'No Beaver Builder pages found (or Beaver Builder is inactive).', 'mcm' ); ?></p>
							<?php else : ?>
								<fieldset class="mcm-checklist">
									<?php foreach ( $pages as $p ) : ?>
										<label>
											<input type="checkbox" name="allowed_pages[]" value="<?php echo esc_attr( $p->ID ); ?>" <?php checked( in_array( (int) $p->ID, $allow_pg, true ) ); ?> />
											<?php echo esc_html( $p->post_title ? $p->post_title : ( '#' . $p->ID ) ); ?>
										</label>
									<?php endforeach; ?>
								</fieldset>
								<p class="description"><?php esc_html_e( 'The editor can open each of these pages and edit every module on it, live, in the exact page layout.', 'mcm' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Allowed blocks', 'mcm' ); ?></th>
						<td>
							<?php if ( empty( $blocks ) ) : ?>
								<p><?php esc_html_e( 'No blocks defined yet. Create some first.', 'mcm' ); ?></p>
							<?php else : ?>
								<fieldset class="mcm-checklist">
									<?php foreach ( $blocks as $b ) : ?>
										<label>
											<input type="checkbox" name="allowed_blocks[]" value="<?php echo esc_attr( $b->id ); ?>" <?php checked( in_array( (int) $b->id, $allowed, true ) ); ?> />
											<?php echo esc_html( $b->label ); ?> <span class="mcm-muted">(<?php echo esc_html( $b->slug ); ?>)</span>
										</label>
									<?php endforeach; ?>
								</fieldset>
							<?php endif; ?>
						</td>
					</tr>
				</table>
				<p class="submit">
					<button type="submit" class="button button-primary"><?php echo $editor ? esc_html__( 'Update editor', 'mcm' ) : esc_html__( 'Add editor', 'mcm' ); ?></button>
					<?php if ( $editor ) : ?>
						<a href="<?php echo esc_url( $this->screen_url( 'mcm-editors' ) ); ?>" class="button"><?php esc_html_e( 'Cancel', 'mcm' ); ?></a>
					<?php endif; ?>
				</p>
			</form>
		</div>
		<?php
	}

	private function render_editor_list() {
		$editors = MCM_DB::get_editors();
		echo '<h2 class="mcm-h2">' . esc_html__( 'All editors', 'mcm' ) . '</h2>';
		if ( empty( $editors ) ) {
			echo '<p>' . esc_html__( 'No editors yet.', 'mcm' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Username', 'mcm' ) . '</th>';
		echo '<th>' . esc_html__( 'Display name', 'mcm' ) . '</th>';
		echo '<th>' . esc_html__( 'Blocks', 'mcm' ) . '</th>';
		echo '<th>' . esc_html__( 'Active', 'mcm' ) . '</th>';
		echo '<th>' . esc_html__( 'Last login', 'mcm' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'mcm' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $editors as $e ) {
			$count   = count( MCM_DB::editor_allowed_ids( $e ) );
			$del_url = wp_nonce_url(
				$this->screen_url_admin_post( 'mcm_delete_editor', array( 'id' => $e->id ) ),
				'mcm_delete_editor_' . $e->id
			);
			echo '<tr>';
			echo '<td><strong>' . esc_html( $e->username ) . '</strong></td>';
			echo '<td>' . esc_html( $e->display_name ) . '</td>';
			echo '<td>' . esc_html( $count ) . '</td>';
			echo '<td>' . ( (int) $e->active === 1 ? '✓' : '—' ) . '</td>';
			echo '<td>' . ( $e->last_login ? esc_html( $e->last_login ) : '—' ) . '</td>';
			echo '<td>';
			echo '<a class="button button-small" href="' . esc_url( $this->screen_url( 'mcm-editors', array( 'edit' => $e->id ) ) ) . '">' . esc_html__( 'Edit', 'mcm' ) . '</a> ';
			echo '<a class="button button-small button-link-delete" href="' . esc_url( $del_url ) . '" onclick="return confirm(\'' . esc_js( __( 'Delete this editor?', 'mcm' ) ) . '\');">' . esc_html__( 'Delete', 'mcm' ) . '</a>';
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	public function handle_save_editor() {
		$this->guard();
		check_admin_referer( 'mcm_save_editor' );

		$result = MCM_DB::save_editor(
			array(
				'id'             => isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0,
				'username'       => isset( $_POST['username'] ) ? sanitize_user( wp_unslash( $_POST['username'] ), true ) : '',
				'display_name'   => isset( $_POST['display_name'] ) ? sanitize_text_field( wp_unslash( $_POST['display_name'] ) ) : '',
				'password'       => isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '',
				'allowed_blocks' => isset( $_POST['allowed_blocks'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['allowed_blocks'] ) ) : array(),
				'allowed_pages'  => isset( $_POST['allowed_pages'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['allowed_pages'] ) ) : array(),
				'active'         => isset( $_POST['active'] ) ? 1 : 0,
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect_back( 'mcm-editors', array( 'mcm_err' => $result->get_error_message() ) );
		}
		$this->redirect_back( 'mcm-editors', array( 'mcm_msg' => __( 'Editor saved.', 'mcm' ) ) );
	}

	public function handle_delete_editor() {
		$this->guard();
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'mcm_delete_editor_' . $id );
		MCM_DB::delete_editor( $id );
		$this->redirect_back( 'mcm-editors', array( 'mcm_msg' => __( 'Editor deleted.', 'mcm' ) ) );
	}

	// =======================================================================
	// SETTINGS
	// =======================================================================
	public function render_settings() {
		$this->guard();
		$settings = mcm_get_settings();
		$pages    = get_pages();
		echo '<div class="wrap mcm-wrap">';
		echo '<h1>' . esc_html__( 'Settings', 'mcm' ) . '</h1>';
		$this->notice();
		?>
		<div class="mcm-card">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'mcm_save_settings' ); ?>
				<input type="hidden" name="action" value="mcm_save_settings" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="mcm-portal"><?php esc_html_e( 'Portal page', 'mcm' ); ?></label></th>
						<td>
							<select name="portal_page_id" id="mcm-portal">
								<option value="0"><?php esc_html_e( '— none selected —', 'mcm' ); ?></option>
								<?php foreach ( $pages as $p ) : ?>
									<option value="<?php echo esc_attr( $p->ID ); ?>" <?php selected( (int) $settings['portal_page_id'], (int) $p->ID ); ?>><?php echo esc_html( $p->post_title ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Create a page (e.g. "Editor Login") containing the shortcode', 'mcm' ); ?>
								<code>[content_editor_portal]</code>
								<?php esc_html_e( 'and select it here. Editors visit that page to log in.', 'mcm' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mcm-lifetime"><?php esc_html_e( 'Session lifetime (hours)', 'mcm' ); ?></label></th>
						<td><input name="session_lifetime" id="mcm-lifetime" type="number" min="1" max="720" value="<?php echo esc_attr( $settings['session_lifetime'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="mcm-maxfails"><?php esc_html_e( 'Max failed logins', 'mcm' ); ?></label></th>
						<td><input name="max_login_fails" id="mcm-maxfails" type="number" min="1" max="50" value="<?php echo esc_attr( $settings['max_login_fails'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="mcm-lockout"><?php esc_html_e( 'Lockout minutes', 'mcm' ); ?></label></th>
						<td><input name="lockout_minutes" id="mcm-lockout" type="number" min="1" max="1440" value="<?php echo esc_attr( $settings['lockout_minutes'] ); ?>" /></td>
					</tr>
				</table>
				<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'mcm' ); ?></button></p>
			</form>
		</div>

		<div class="mcm-card">
			<h2><?php esc_html_e( 'How to use', 'mcm' ); ?></h2>
			<ol class="mcm-steps">
				<li><?php esc_html_e( 'Create content blocks under Content Manager → Content Blocks.', 'mcm' ); ?></li>
				<li><?php echo wp_kses_post( __( 'Place each block on any page/post with its shortcode, e.g. <code>[managed_content slug="hero-title"]</code>.', 'mcm' ) ); ?></li>
				<li><?php echo wp_kses_post( __( 'Create a page containing <code>[content_editor_portal]</code> and pick it above.', 'mcm' ) ); ?></li>
				<li><?php esc_html_e( 'Add editor accounts and tick the blocks each one is allowed to change.', 'mcm' ); ?></li>
				<li><?php esc_html_e( 'For live per-page editing, tick “Editable pages” on the editor instead — they can then open those Beaver Builder pages and edit every module in place, in the exact page layout.', 'mcm' ); ?></li>
				<li><?php esc_html_e( 'Send editors the portal URL + their username/password. They edit only what you allowed — no wp-admin access at all.', 'mcm' ); ?></li>
			</ol>
		</div>
		<?php
		echo '</div>';
	}

	public function handle_save_settings() {
		$this->guard();
		check_admin_referer( 'mcm_save_settings' );
		$settings = array(
			'portal_page_id'   => isset( $_POST['portal_page_id'] ) ? absint( $_POST['portal_page_id'] ) : 0,
			'session_lifetime' => isset( $_POST['session_lifetime'] ) ? max( 1, absint( $_POST['session_lifetime'] ) ) : 8,
			'max_login_fails'  => isset( $_POST['max_login_fails'] ) ? max( 1, absint( $_POST['max_login_fails'] ) ) : 5,
			'lockout_minutes'  => isset( $_POST['lockout_minutes'] ) ? max( 1, absint( $_POST['lockout_minutes'] ) ) : 15,
		);
		update_option( 'mcm_settings', $settings );
		$this->redirect_back( 'mcm-settings', array( 'mcm_msg' => __( 'Settings saved.', 'mcm' ) ) );
	}

	// =======================================================================
	// BEAVER BUILDER
	// =======================================================================
	public function render_beaver() {
		$this->guard();
		echo '<div class="wrap mcm-wrap">';
		echo '<h1>' . esc_html__( 'Beaver Builder content', 'mcm' ) . '</h1>';
		$this->notice();

		if ( ! MCM_Beaver::is_active() ) {
			echo '<div class="notice notice-warning"><p>' .
				esc_html__( 'Beaver Builder is not active. Activate Beaver Builder (or Beaver Builder Lite) to expose its module fields here.', 'mcm' ) .
				'</p></div></div>';
			return;
		}

		echo '<p class="description">' . esc_html__( 'Pick a page built with Beaver Builder. You can expose a whole module (image, text, link, icon, colours — the entire box) or just a single field. Each becomes an editable block you assign under Editors — no Beaver Builder access required for them.', 'mcm' ) . '</p>';

		$posts   = MCM_Beaver::get_bb_posts();
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Page picker.
		?>
		<div class="mcm-card">
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="mcm-beaver" />
				<label for="mcm-bb-post"><strong><?php esc_html_e( 'Page:', 'mcm' ); ?></strong></label>
				<select name="post" id="mcm-bb-post" onchange="this.form.submit()">
					<option value="0"><?php esc_html_e( '— select a Beaver Builder page —', 'mcm' ); ?></option>
					<?php foreach ( $posts as $p ) : ?>
						<option value="<?php echo esc_attr( $p->ID ); ?>" <?php selected( $post_id, (int) $p->ID ); ?>>
							<?php echo esc_html( $p->post_title ? $p->post_title : ( '#' . $p->ID ) ); ?> (<?php echo esc_html( $p->post_status ); ?>)
						</option>
					<?php endforeach; ?>
				</select>
				<noscript><button class="button"><?php esc_html_e( 'Go', 'mcm' ); ?></button></noscript>
			</form>
			<?php if ( empty( $posts ) ) : ?>
				<p class="mcm-muted"><?php esc_html_e( 'No Beaver Builder pages found on this site yet.', 'mcm' ); ?></p>
			<?php endif; ?>
		</div>
		<?php

		if ( ! $post_id ) {
			echo '</div>';
			return;
		}

		$fields   = MCM_Beaver::scan_fields( $post_id );
		$modules  = MCM_Beaver::scan_modules( $post_id );
		$existing = MCM_DB::get_beaver_blocks_for_post( $post_id );
		$already  = array();
		$mod_done = array();
		foreach ( $existing as $b ) {
			if ( 'beaver_module' === $b->source ) {
				$mod_done[ $b->node_id ] = $b;
			} else {
				$already[ $b->node_id . '|' . $b->field_key ] = $b;
			}
		}

		// ---- Whole modules --------------------------------------------------
		echo '<h2 class="mcm-h2">' . esc_html__( 'Whole modules (edit everything in the box)', 'mcm' ) . '</h2>';
		if ( empty( $modules ) ) {
			echo '<p>' . esc_html__( 'No Beaver Builder modules were detected on this page.', 'mcm' ) . '</p>';
		} else {
			echo '<table class="widefat striped mcm-bb-table">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__( 'Module', 'mcm' ) . '</th>';
			echo '<th>' . esc_html__( 'Preview', 'mcm' ) . '</th>';
			echo '<th>' . esc_html__( 'Make editable', 'mcm' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $modules as $mod ) {
				echo '<tr>';
				echo '<td><strong>' . esc_html( $mod['label'] ) . '</strong><br /><span class="mcm-muted">' . esc_html( $mod['node_id'] ) . '</span></td>';
				echo '<td>' . esc_html( $mod['preview'] ) . '</td>';
				echo '<td>';
				if ( isset( $mod_done[ $mod['node_id'] ] ) ) {
					$b = $mod_done[ $mod['node_id'] ];
					echo '<span class="mcm-tag mcm-tag-bb">' . esc_html__( 'Added', 'mcm' ) . '</span> ';
					echo '<a class="button button-small" href="' . esc_url( $this->screen_url( 'mcm-blocks', array( 'edit' => $b->id ) ) ) . '">' . esc_html__( 'Edit block', 'mcm' ) . '</a>';
				} else {
					?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mcm-bb-add">
						<?php wp_nonce_field( 'mcm_add_beaver_module' ); ?>
						<input type="hidden" name="action" value="mcm_add_beaver_module" />
						<input type="hidden" name="post_id" value="<?php echo esc_attr( $post_id ); ?>" />
						<input type="hidden" name="node_id" value="<?php echo esc_attr( $mod['node_id'] ); ?>" />
						<input type="text" name="label" required placeholder="<?php esc_attr_e( 'Label for editors', 'mcm' ); ?>" value="<?php echo esc_attr( $mod['label'] . ( $mod['preview'] ? ' — ' . $mod['preview'] : '' ) ); ?>" />
						<button type="submit" class="button button-primary button-small"><?php esc_html_e( 'Edit whole module', 'mcm' ); ?></button>
					</form>
					<?php
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}

		// ---- Single fields --------------------------------------------------
		echo '<h2 class="mcm-h2">' . esc_html__( 'Single fields (fine-grained)', 'mcm' ) . '</h2>';

		if ( empty( $fields ) ) {
			echo '<p>' . esc_html__( 'No editable text fields were detected on this page. It may use only modules we do not recognise yet.', 'mcm' ) . '</p></div>';
			return;
		}

		$types = array(
			'text'     => __( 'Single line text', 'mcm' ),
			'textarea' => __( 'Multi-line text', 'mcm' ),
			'richtext' => __( 'Rich text', 'mcm' ),
		);

		echo '<table class="widefat striped mcm-bb-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Module · field', 'mcm' ) . '</th>';
		echo '<th>' . esc_html__( 'Current value', 'mcm' ) . '</th>';
		echo '<th>' . esc_html__( 'Make editable', 'mcm' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $fields as $f ) {
			$key = $f['node_id'] . '|' . $f['field_key'];
			echo '<tr>';
			echo '<td><strong>' . esc_html( $f['module_label'] ) . '</strong><br /><span class="mcm-muted">' . esc_html( $f['node_id'] ) . '</span></td>';
			echo '<td>' . esc_html( $f['preview'] ) . '</td>';
			echo '<td>';

			if ( isset( $already[ $key ] ) ) {
				$b = $already[ $key ];
				echo '<span class="mcm-tag mcm-tag-bb">' . esc_html__( 'Added', 'mcm' ) . '</span> ';
				echo '<a class="button button-small" href="' . esc_url( $this->screen_url( 'mcm-blocks', array( 'edit' => $b->id ) ) ) . '">' . esc_html__( 'Edit block', 'mcm' ) . '</a>';
			} else {
				?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mcm-bb-add">
					<?php wp_nonce_field( 'mcm_add_beaver_block' ); ?>
					<input type="hidden" name="action" value="mcm_add_beaver_block" />
					<input type="hidden" name="post_id" value="<?php echo esc_attr( $post_id ); ?>" />
					<input type="hidden" name="node_id" value="<?php echo esc_attr( $f['node_id'] ); ?>" />
					<input type="hidden" name="field_key" value="<?php echo esc_attr( $f['field_key'] ); ?>" />
					<input type="text" name="label" required placeholder="<?php esc_attr_e( 'Label for editors', 'mcm' ); ?>" value="<?php echo esc_attr( $f['module_label'] ); ?>" />
					<select name="type">
						<?php foreach ( $types as $tk => $tl ) : ?>
							<option value="<?php echo esc_attr( $tk ); ?>" <?php selected( $f['suggested_type'], $tk ); ?>><?php echo esc_html( $tl ); ?></option>
						<?php endforeach; ?>
					</select>
					<input type="number" name="max_length" min="0" value="0" title="<?php esc_attr_e( 'Max length (0 = unlimited)', 'mcm' ); ?>" style="width:80px" />
					<button type="submit" class="button button-primary button-small"><?php esc_html_e( 'Add', 'mcm' ); ?></button>
				</form>
				<?php
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo '</div>';
	}

	public function handle_add_beaver_block() {
		$this->guard();
		check_admin_referer( 'mcm_add_beaver_block' );

		$post_id   = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$node_id   = isset( $_POST['node_id'] ) ? sanitize_text_field( wp_unslash( $_POST['node_id'] ) ) : '';
		$field_key = isset( $_POST['field_key'] ) ? sanitize_key( wp_unslash( $_POST['field_key'] ) ) : '';

		// Cache the current live value for previews.
		$current = MCM_Beaver::get_field_value( $post_id, $node_id, $field_key );

		$result = MCM_DB::save_beaver_block(
			array(
				'label'      => isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '',
				'type'       => isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'text',
				'max_length' => isset( $_POST['max_length'] ) ? absint( $_POST['max_length'] ) : 0,
				'post_id'    => $post_id,
				'node_id'    => $node_id,
				'field_key'  => $field_key,
				'content'    => $current,
				'updated_by' => $this->current_admin_name(),
			)
		);

		$args = is_wp_error( $result )
			? array( 'mcm_err' => $result->get_error_message(), 'post' => $post_id )
			: array( 'mcm_msg' => __( 'Field added. Now assign it to an editor.', 'mcm' ), 'post' => $post_id );

		wp_safe_redirect( $this->screen_url( 'mcm-beaver', $args ) );
		exit;
	}

	public function handle_add_beaver_module() {
		$this->guard();
		check_admin_referer( 'mcm_add_beaver_module' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$node_id = isset( $_POST['node_id'] ) ? sanitize_text_field( wp_unslash( $_POST['node_id'] ) ) : '';

		$mod     = MCM_Beaver::get_module( $post_id, $node_id );
		$preview = is_array( $mod ) ? MCM_Beaver::module_preview( $mod['slug'], (object) $mod['settings'] ) : '';

		$result = MCM_DB::save_beaver_module_block(
			array(
				'label'      => isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '',
				'post_id'    => $post_id,
				'node_id'    => $node_id,
				'content'    => $preview,
				'updated_by' => $this->current_admin_name(),
			)
		);

		$args = is_wp_error( $result )
			? array( 'mcm_err' => $result->get_error_message(), 'post' => $post_id )
			: array( 'mcm_msg' => __( 'Module added. Now assign it to an editor.', 'mcm' ), 'post' => $post_id );

		wp_safe_redirect( $this->screen_url( 'mcm-beaver', $args ) );
		exit;
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------
	private function current_admin_name() {
		$user = wp_get_current_user();
		return $user && $user->exists() ? 'admin:' . $user->user_login : 'admin';
	}

	private function redirect_back( $page, $args = array() ) {
		wp_safe_redirect( $this->screen_url( $page, $args ) );
		exit;
	}
}
