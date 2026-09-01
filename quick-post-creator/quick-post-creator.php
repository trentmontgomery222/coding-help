<?php
/**
 * Plugin Name: Quick Post Creator
 * Description: A stripped-down admin page for making a post fast: a title, some text, a featured image, and a few extra images. No frills, no extra meta boxes to hunt through. Activate per-site (not a network/multisite plugin).
 * Version: 1.0.0
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Author:
 * License: GPL v2 or later
 */

// Bail if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Quick_Post_Creator {

	const SLUG   = 'quick-post-creator';
	const NONCE  = 'qpc_create_post_action';
	const ACTION = 'qpc_create_post';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_submit' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
	}

	/**
	 * Add a single top-level admin page. This is intentionally NOT hooked to
	 * network_admin_menu — it only ever shows up in the regular per-site
	 * wp-admin sidebar, never in the multisite network admin.
	 */
	public function add_menu_page() {
		add_menu_page(
			'Quick Post',
			'Quick Post',
			'publish_posts',
			self::SLUG,
			array( $this, 'render_page' ),
			'dashicons-edit-page',
			4
		);
	}

	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style(
			'qpc-admin',
			plugins_url( 'assets/quick-post.css', __FILE__ ),
			array(),
			'1.0.0'
		);
		wp_enqueue_script(
			'qpc-admin',
			plugins_url( 'assets/quick-post.js', __FILE__ ),
			array( 'jquery' ),
			'1.0.0',
			true
		);
	}

	public function render_page() {
		if ( ! current_user_can( 'publish_posts' ) ) {
			wp_die( 'You do not have permission to create posts.' );
		}
		?>
		<div class="wrap qpc-wrap">
			<h1>Quick Post</h1>
			<p>Title, some text, a featured image, a few extra images. That's it.</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" id="qpc-form">
				<?php wp_nonce_field( self::NONCE, 'qpc_nonce' ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">

				<table class="form-table" role="presentation">
					<tr>
						<th><label for="qpc_title">Title</label></th>
						<td>
							<input type="text" id="qpc_title" name="qpc_title" class="large-text" required>
						</td>
					</tr>
					<tr>
						<th><label for="qpc_content">Content</label></th>
						<td>
							<?php
							wp_editor(
								'',
								'qpc_content',
								array(
									'textarea_name' => 'qpc_content',
									'textarea_rows' => 14,
									'media_buttons' => true,
									'teeny'         => false,
								)
							);
							?>
						</td>
					</tr>
					<tr>
						<th><label>Featured Image</label></th>
						<td>
							<input type="hidden" id="qpc_featured_image_id" name="qpc_featured_image_id" value="">
							<div id="qpc-featured-preview" class="qpc-preview"></div>
							<p>
								<button type="button" class="button" id="qpc-select-featured">Select Featured Image</button>
								<button type="button" class="button" id="qpc-remove-featured" style="display:none;">Remove</button>
							</p>
						</td>
					</tr>
					<tr>
						<th><label>Additional Images</label></th>
						<td>
							<input type="hidden" id="qpc_gallery_ids" name="qpc_gallery_ids" value="">
							<div id="qpc-gallery-preview" class="qpc-preview qpc-preview-multi"></div>
							<p>
								<button type="button" class="button" id="qpc-select-gallery">Add Images</button>
							</p>
							<p class="description">Selected images are added to the bottom of the post as a gallery.</p>
						</td>
					</tr>
					<tr>
						<th><label for="qpc_category">Category</label></th>
						<td>
							<?php
							wp_dropdown_categories(
								array(
									'name'             => 'qpc_category',
									'id'               => 'qpc_category',
									'show_option_none' => 'Uncategorized',
									'hide_empty'       => 0,
								)
							);
							?>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" name="qpc_status" value="publish" class="button button-primary">Publish</button>
					<button type="submit" name="qpc_status" value="draft" class="button">Save Draft</button>
				</p>
			</form>
		</div>
		<?php
	}

	public function handle_submit() {
		if ( ! isset( $_POST['qpc_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['qpc_nonce'] ) ), self::NONCE ) ) {
			wp_die( 'Security check failed.' );
		}

		if ( ! current_user_can( 'publish_posts' ) ) {
			wp_die( 'You do not have permission to create posts.' );
		}

		$title   = isset( $_POST['qpc_title'] ) ? sanitize_text_field( wp_unslash( $_POST['qpc_title'] ) ) : '';
		$content = isset( $_POST['qpc_content'] ) ? wp_kses_post( wp_unslash( $_POST['qpc_content'] ) ) : '';
		$status  = ( isset( $_POST['qpc_status'] ) && 'publish' === $_POST['qpc_status'] ) ? 'publish' : 'draft';
		$cat_id  = isset( $_POST['qpc_category'] ) ? absint( $_POST['qpc_category'] ) : 0;

		if ( '' === trim( $title ) ) {
			$this->redirect_with_message( 'error', 0 );
		}

		$gallery_ids = array();
		if ( ! empty( $_POST['qpc_gallery_ids'] ) ) {
			$raw_ids = explode( ',', sanitize_text_field( wp_unslash( $_POST['qpc_gallery_ids'] ) ) );
			foreach ( $raw_ids as $raw_id ) {
				$id = absint( $raw_id );
				if ( $id > 0 ) {
					$gallery_ids[] = $id;
				}
			}
		}

		if ( ! empty( $gallery_ids ) ) {
			$content .= "\n[gallery ids=\"" . implode( ',', $gallery_ids ) . "\"]";
		}

		$postarr = array(
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => $status,
			'post_type'    => 'post',
			'post_author'  => get_current_user_id(),
		);

		if ( $cat_id > 0 ) {
			$postarr['post_category'] = array( $cat_id );
		}

		$post_id = wp_insert_post( $postarr, true );

		if ( is_wp_error( $post_id ) ) {
			$this->redirect_with_message( 'error', 0 );
		}

		$featured_id = isset( $_POST['qpc_featured_image_id'] ) ? absint( $_POST['qpc_featured_image_id'] ) : 0;
		if ( $featured_id > 0 ) {
			set_post_thumbnail( $post_id, $featured_id );
		}

		$this->redirect_with_message( 'success', $post_id );
	}

	private function redirect_with_message( $type, $post_id ) {
		$url = add_query_arg(
			array(
				'page'         => self::SLUG,
				'qpc_message'  => $type,
				'qpc_post_id'  => $post_id,
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	public function admin_notices() {
		if ( ! isset( $_GET['page'] ) || self::SLUG !== $_GET['page'] || ! isset( $_GET['qpc_message'] ) ) {
			return;
		}

		$message  = sanitize_text_field( wp_unslash( $_GET['qpc_message'] ) );
		$post_id  = isset( $_GET['qpc_post_id'] ) ? absint( $_GET['qpc_post_id'] ) : 0;

		if ( 'success' === $message && $post_id > 0 ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>Post created. <a href="%1$s">Edit it</a> or <a href="%2$s">view it</a>.</p></div>',
				esc_url( get_edit_post_link( $post_id, 'raw' ) ),
				esc_url( get_permalink( $post_id ) )
			);
		} elseif ( 'error' === $message ) {
			echo '<div class="notice notice-error is-dismissible"><p>Could not create the post. Make sure it has a title and try again.</p></div>';
		}
	}
}

new Quick_Post_Creator();
