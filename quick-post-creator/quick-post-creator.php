<?php
/**
 * Plugin Name: Quick Post Creator
 * Description: A stripped-down admin page for making a post fast: a title, some text, a featured image, extra images, full category/tag support, and Yoast SEO fields when Yoast is active. No frills, no extra meta boxes to hunt through. Activate per-site (not a network/multisite plugin).
 * Version: 1.1.0
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

	private function yoast_active() {
		return defined( 'WPSEO_VERSION' );
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
			'1.1.0'
		);
		wp_enqueue_script(
			'qpc-admin',
			plugins_url( 'assets/quick-post.js', __FILE__ ),
			array( 'jquery' ),
			'1.1.0',
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
			<p>Title, some text, a featured image, a few extra images, categories, tags<?php echo $this->yoast_active() ? ', and SEO' : ''; ?>. That's it.</p>

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
						<th><label>Categories</label></th>
						<td>
							<div class="categorydiv">
								<ul class="categorychecklist qpc-cat-list">
									<?php
									wp_terms_checklist(
										0,
										array(
											'taxonomy' => 'category',
											'echo'     => true,
										)
									);
									?>
								</ul>
							</div>
							<?php if ( current_user_can( 'manage_categories' ) ) : ?>
								<p>
									<label for="qpc_new_categories">Add new categories (comma-separated)</label><br>
									<input type="text" id="qpc_new_categories" name="qpc_new_categories" class="regular-text" placeholder="e.g. News, Events">
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><label for="qpc_tags">Tags</label></th>
						<td>
							<input type="text" id="qpc_tags" name="qpc_tags" class="large-text" placeholder="comma, separated, tags">
							<?php
							$popular_tags = get_terms(
								array(
									'taxonomy'   => 'post_tag',
									'orderby'    => 'count',
									'order'      => 'DESC',
									'number'     => 20,
									'hide_empty' => false,
								)
							);
							if ( ! is_wp_error( $popular_tags ) && ! empty( $popular_tags ) ) :
								?>
								<p class="qpc-tag-chips">
									<span class="description">Popular tags:</span>
									<?php foreach ( $popular_tags as $tag ) : ?>
										<button type="button" class="button button-small qpc-tag-chip" data-tag="<?php echo esc_attr( $tag->name ); ?>"><?php echo esc_html( $tag->name ); ?></button>
									<?php endforeach; ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<?php if ( $this->yoast_active() ) : ?>
					<h2>SEO (Yoast)</h2>
					<table class="form-table" role="presentation">
						<tr>
							<th><label for="qpc_seo_title">SEO Title</label></th>
							<td>
								<input type="text" id="qpc_seo_title" name="qpc_seo_title" class="large-text">
								<p class="description">Leave blank to let Yoast use its default title template.</p>
							</td>
						</tr>
						<tr>
							<th><label for="qpc_seo_desc">Meta Description</label></th>
							<td>
								<textarea id="qpc_seo_desc" name="qpc_seo_desc" class="large-text" rows="3"></textarea>
							</td>
						</tr>
						<tr>
							<th><label for="qpc_seo_focus_kw">Focus Keyphrase</label></th>
							<td>
								<input type="text" id="qpc_seo_focus_kw" name="qpc_seo_focus_kw" class="regular-text">
							</td>
						</tr>
					</table>
				<?php endif; ?>

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

		// Existing categories checked in the checklist.
		$category_ids = array();
		if ( ! empty( $_POST['post_category'] ) && is_array( $_POST['post_category'] ) ) {
			$category_ids = array_map( 'absint', wp_unslash( $_POST['post_category'] ) );
		}

		// Brand-new categories, only if the current user is allowed to create them.
		if ( current_user_can( 'manage_categories' ) && ! empty( $_POST['qpc_new_categories'] ) ) {
			$new_cat_names = explode( ',', sanitize_text_field( wp_unslash( $_POST['qpc_new_categories'] ) ) );
			foreach ( $new_cat_names as $new_cat_name ) {
				$new_cat_name = trim( $new_cat_name );
				if ( '' === $new_cat_name ) {
					continue;
				}
				$existing = term_exists( $new_cat_name, 'category' );
				if ( $existing ) {
					$category_ids[] = (int) ( is_array( $existing ) ? $existing['term_id'] : $existing );
					continue;
				}
				$created = wp_insert_term( $new_cat_name, 'category' );
				if ( ! is_wp_error( $created ) ) {
					$category_ids[] = (int) $created['term_id'];
				}
			}
		}
		$category_ids = array_values( array_unique( array_filter( $category_ids ) ) );

		// Tags: comma-separated names, WordPress creates any that don't exist yet.
		$tag_names = array();
		if ( ! empty( $_POST['qpc_tags'] ) ) {
			$raw_tags = explode( ',', sanitize_text_field( wp_unslash( $_POST['qpc_tags'] ) ) );
			foreach ( $raw_tags as $raw_tag ) {
				$raw_tag = trim( $raw_tag );
				if ( '' !== $raw_tag ) {
					$tag_names[] = $raw_tag;
				}
			}
		}

		$postarr = array(
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => $status,
			'post_type'    => 'post',
			'post_author'  => get_current_user_id(),
		);

		if ( ! empty( $category_ids ) ) {
			$postarr['post_category'] = $category_ids;
		}

		if ( ! empty( $tag_names ) ) {
			$postarr['tags_input'] = $tag_names;
		}

		$post_id = wp_insert_post( $postarr, true );

		if ( is_wp_error( $post_id ) ) {
			$this->redirect_with_message( 'error', 0 );
		}

		$featured_id = isset( $_POST['qpc_featured_image_id'] ) ? absint( $_POST['qpc_featured_image_id'] ) : 0;
		if ( $featured_id > 0 ) {
			set_post_thumbnail( $post_id, $featured_id );
		}

		if ( $this->yoast_active() ) {
			$seo_title    = isset( $_POST['qpc_seo_title'] ) ? sanitize_text_field( wp_unslash( $_POST['qpc_seo_title'] ) ) : '';
			$seo_desc     = isset( $_POST['qpc_seo_desc'] ) ? sanitize_textarea_field( wp_unslash( $_POST['qpc_seo_desc'] ) ) : '';
			$seo_focus_kw = isset( $_POST['qpc_seo_focus_kw'] ) ? sanitize_text_field( wp_unslash( $_POST['qpc_seo_focus_kw'] ) ) : '';

			if ( '' !== $seo_title ) {
				update_post_meta( $post_id, '_yoast_wpseo_title', $seo_title );
			}
			if ( '' !== $seo_desc ) {
				update_post_meta( $post_id, '_yoast_wpseo_metadesc', $seo_desc );
			}
			if ( '' !== $seo_focus_kw ) {
				update_post_meta( $post_id, '_yoast_wpseo_focuskw', $seo_focus_kw );
			}
		}

		$this->redirect_with_message( 'success', $post_id );
	}

	private function redirect_with_message( $type, $post_id ) {
		$url = add_query_arg(
			array(
				'page'        => self::SLUG,
				'qpc_message' => $type,
				'qpc_post_id' => $post_id,
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

		$message = sanitize_text_field( wp_unslash( $_GET['qpc_message'] ) );
		$post_id = isset( $_GET['qpc_post_id'] ) ? absint( $_GET['qpc_post_id'] ) : 0;

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
