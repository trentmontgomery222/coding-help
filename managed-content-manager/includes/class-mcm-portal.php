<?php
/**
 * Front-end portal: the separate login + the locked-down editor dashboard,
 * plus the [managed_content] display shortcode.
 *
 * @package mcm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCM_Portal {

	/** @var MCM_Portal|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( 'managed_content', array( $this, 'shortcode_content' ) );
		add_shortcode( 'content_editor_portal', array( $this, 'shortcode_portal' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );

		// Front-end form handlers. nopriv variants let logged-out visitors post.
		add_action( 'admin_post_nopriv_mcm_portal_login', array( $this, 'handle_login' ) );
		add_action( 'admin_post_mcm_portal_login', array( $this, 'handle_login' ) );
		add_action( 'admin_post_nopriv_mcm_portal_logout', array( $this, 'handle_logout' ) );
		add_action( 'admin_post_mcm_portal_logout', array( $this, 'handle_logout' ) );
		add_action( 'admin_post_nopriv_mcm_portal_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_mcm_portal_save', array( $this, 'handle_save' ) );
	}

	public function assets() {
		// Only load where the portal actually appears, to keep the rest of the
		// site clean: the configured portal page, or any post that contains the
		// [content_editor_portal] shortcode.
		$settings   = mcm_get_settings();
		$is_portal  = $settings['portal_page_id'] && is_page( (int) $settings['portal_page_id'] );
		$has_portal = false;

		if ( ! $is_portal ) {
			global $post;
			$has_portal = ( $post instanceof WP_Post ) && has_shortcode( (string) $post->post_content, 'content_editor_portal' );
		}

		if ( ! $is_portal && ! $has_portal ) {
			return;
		}

		wp_enqueue_style( 'mcm-portal', MCM_URL . 'assets/portal.css', array(), MCM_VERSION );
		wp_enqueue_script( 'mcm-portal', MCM_URL . 'assets/portal.js', array(), MCM_VERSION, true );
	}

	// =======================================================================
	// [managed_content slug="..."]
	// =======================================================================
	public function shortcode_content( $atts ) {
		$atts  = shortcode_atts( array( 'slug' => '' ), $atts, 'managed_content' );
		$slug  = sanitize_title( $atts['slug'] );
		if ( '' === $slug ) {
			return '';
		}
		$block = MCM_DB::get_block_by_slug( $slug );
		if ( ! $block ) {
			return '';
		}
		return $this->render_block_output( $block );
	}

	/**
	 * Render a block's stored content for public display, respecting its type.
	 *
	 * @param object $block
	 * @return string
	 */
	private function render_block_output( $block ) {
		// Whole-module blocks are rendered by Beaver Builder on their own page;
		// there is no single value to inline via shortcode.
		if ( 'beaver_module' === $block->source ) {
			return '';
		}
		$is_bb   = 'beaver' === $block->source;
		$content = $is_bb
			? MCM_Beaver::get_field_value( (int) $block->post_id, $block->node_id, $block->field_key )
			: (string) $block->content;

		switch ( $block->type ) {
			case 'richtext':
				// Re-sanitize on output. Beaver rich text keeps post-level markup.
				return MCM_DB::sanitize_block_content( 'richtext', $content, 0, $is_bb ? 'post' : 'strict' );
			case 'textarea':
				return nl2br( esc_html( $content ) );
			case 'text':
			default:
				return esc_html( $content );
		}
	}

	// =======================================================================
	// [content_editor_portal]
	// =======================================================================
	public function shortcode_portal( $atts ) {
		$auth   = MCM_Auth::instance();
		$editor = $auth->current_editor();

		ob_start();
		echo '<div class="mcm-portal">';

		$this->flash();

		if ( $editor ) {
			$this->render_dashboard( $editor );
		} else {
			$this->render_login_form();
		}

		echo '</div>';
		return ob_get_clean();
	}

	private function flash() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['mcm_pmsg'] ) ) {
			echo '<div class="mcm-flash mcm-flash-ok">' . esc_html( sanitize_text_field( wp_unslash( $_GET['mcm_pmsg'] ) ) ) . '</div>';
		}
		if ( isset( $_GET['mcm_perr'] ) ) {
			echo '<div class="mcm-flash mcm-flash-err">' . esc_html( sanitize_text_field( wp_unslash( $_GET['mcm_perr'] ) ) ) . '</div>';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	private function render_login_form() {
		?>
		<div class="mcm-login">
			<h2><?php esc_html_e( 'Content Editor Login', 'mcm' ); ?></h2>
			<p class="mcm-sub"><?php esc_html_e( 'Sign in with the editor account you were given. This is separate from the main site admin.', 'mcm' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'mcm_portal_login', 'mcm_login_nonce' ); ?>
				<input type="hidden" name="action" value="mcm_portal_login" />
				<input type="hidden" name="redirect" value="<?php echo esc_attr( $this->current_url() ); ?>" />
				<label>
					<span><?php esc_html_e( 'Username', 'mcm' ); ?></span>
					<input type="text" name="mcm_username" autocomplete="username" required />
				</label>
				<label>
					<span><?php esc_html_e( 'Password', 'mcm' ); ?></span>
					<input type="password" name="mcm_password" autocomplete="current-password" required />
				</label>
				<button type="submit" class="mcm-btn mcm-btn-primary"><?php esc_html_e( 'Log in', 'mcm' ); ?></button>
			</form>
		</div>
		<?php
	}

	private function render_dashboard( $editor ) {
		$auth      = MCM_Auth::instance();
		$allowed   = MCM_DB::editor_allowed_ids( $editor );
		$blocks    = MCM_DB::get_blocks_by_ids( $allowed );
		$name      = $editor->display_name ? $editor->display_name : $editor->username;
		$logouturl = admin_url( 'admin-post.php' );
		?>
		<div class="mcm-dash">
			<div class="mcm-dash-head">
				<div>
					<h2><?php esc_html_e( 'Your content', 'mcm' ); ?></h2>
					<p class="mcm-sub">
						<?php
						/* translators: %s editor name */
						printf( esc_html__( 'Signed in as %s', 'mcm' ), '<strong>' . esc_html( $name ) . '</strong>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
					</p>
				</div>
				<form method="post" action="<?php echo esc_url( $logouturl ); ?>" class="mcm-logout-form">
					<?php wp_nonce_field( 'mcm_portal_logout', 'mcm_logout_nonce' ); ?>
					<input type="hidden" name="action" value="mcm_portal_logout" />
					<input type="hidden" name="redirect" value="<?php echo esc_attr( $this->current_url() ); ?>" />
					<button type="submit" class="mcm-btn mcm-btn-ghost"><?php esc_html_e( 'Log out', 'mcm' ); ?></button>
				</form>
			</div>

			<?php $this->render_editable_pages( $editor ); ?>

			<?php if ( empty( $blocks ) ) : ?>
				<?php if ( empty( MCM_DB::editor_allowed_page_ids( $editor ) ) ) : ?>
					<p class="mcm-empty"><?php esc_html_e( 'You have not been assigned any content to edit yet.', 'mcm' ); ?></p>
				<?php endif; ?>
			<?php else : ?>
				<?php
				foreach ( $blocks as $block ) {
					if ( 'beaver_module' === $block->source ) {
						$this->render_module_form( $block, $auth );
					} else {
						$this->render_single_field_form( $block, $auth );
					}
				}
				?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * "Pages you can edit" — links that open the real page in in-place edit mode.
	 *
	 * @param object $editor
	 */
	private function render_editable_pages( $editor ) {
		$ids = MCM_DB::editor_allowed_page_ids( $editor );
		if ( empty( $ids ) ) {
			return;
		}
		echo '<div class="mcm-pages">';
		echo '<h3 class="mcm-pages-h">' . esc_html__( 'Pages you can edit', 'mcm' ) . '</h3>';
		echo '<p class="mcm-sub">' . esc_html__( 'Open a page to edit it live, right on the page, exactly as it looks to visitors.', 'mcm' ) . '</p>';
		echo '<ul class="mcm-page-list">';
		foreach ( $ids as $pid ) {
			$post = get_post( $pid );
			if ( ! $post || 'trash' === $post->post_status ) {
				continue;
			}
			$url = add_query_arg( 'mcm_edit', '1', get_permalink( $pid ) );
			echo '<li class="mcm-page-item">';
			echo '<span class="mcm-page-title">' . esc_html( get_the_title( $pid ) ) . '</span>';
			echo '<a class="mcm-btn mcm-btn-primary" href="' . esc_url( $url ) . '">' . esc_html__( 'Edit page', 'mcm' ) . '</a>';
			echo '</li>';
		}
		echo '</ul></div>';
	}

	/**
	 * The original one-input form (custom blocks + single Beaver fields).
	 *
	 * @param object   $block
	 * @param MCM_Auth $auth
	 */
	private function render_single_field_form( $block, $auth ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mcm-block-form">
			<input type="hidden" name="action" value="mcm_portal_save" />
			<input type="hidden" name="mcm_csrf" value="<?php echo esc_attr( $auth->csrf_token() ); ?>" />
			<input type="hidden" name="block_id" value="<?php echo esc_attr( $block->id ); ?>" />
			<input type="hidden" name="redirect" value="<?php echo esc_attr( $this->current_url() ); ?>" />

			<div class="mcm-field">
				<label class="mcm-field-label" for="mcm-f-<?php echo esc_attr( $block->id ); ?>">
					<?php echo esc_html( $block->label ); ?>
					<span class="mcm-type-badge"><?php echo esc_html( $this->type_label( $block->type ) ); ?></span>
				</label>

				<?php $this->render_editor_field( $block ); ?>

				<?php if ( (int) $block->max_length > 0 ) : ?>
					<div class="mcm-count" data-max="<?php echo esc_attr( $block->max_length ); ?>"></div>
				<?php endif; ?>

				<div class="mcm-field-actions">
					<button type="submit" class="mcm-btn mcm-btn-primary"><?php esc_html_e( 'Save', 'mcm' ); ?></button>
					<span class="mcm-last">
						<?php if ( $block->updated_at ) : ?>
							<?php
							/* translators: %s datetime */
							printf( esc_html__( 'Last saved %s', 'mcm' ), esc_html( $block->updated_at ) );
							?>
						<?php endif; ?>
					</span>
				</div>
			</div>
		</form>
		<?php
	}

	/**
	 * The whole-module editor: one form with a widget per meaningful setting,
	 * plus an "advanced" section exposing every remaining scalar setting.
	 *
	 * @param object   $block
	 * @param MCM_Auth $auth
	 */
	private function render_module_form( $block, $auth ) {
		$desc = MCM_Beaver::describe_module( (int) $block->post_id, $block->node_id );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mcm-block-form mcm-module-form" enctype="multipart/form-data">
			<input type="hidden" name="action" value="mcm_portal_save" />
			<input type="hidden" name="mcm_csrf" value="<?php echo esc_attr( $auth->csrf_token() ); ?>" />
			<input type="hidden" name="block_id" value="<?php echo esc_attr( $block->id ); ?>" />
			<input type="hidden" name="redirect" value="<?php echo esc_attr( $this->current_url() ); ?>" />

			<div class="mcm-module-head">
				<span class="mcm-field-label"><?php echo esc_html( $block->label ); ?></span>
				<?php if ( ! is_wp_error( $desc ) ) : ?>
					<span class="mcm-type-badge"><?php echo esc_html( $desc['label'] ); ?></span>
				<?php endif; ?>
			</div>

			<?php if ( is_wp_error( $desc ) ) : ?>
				<p class="mcm-flash mcm-flash-err"><?php echo esc_html( $desc->get_error_message() ); ?></p>
			<?php else : ?>
				<?php foreach ( $desc['primary'] as $f ) : ?>
					<div class="mcm-field">
						<label class="mcm-field-label" for="<?php echo esc_attr( 'mcm-' . $block->id . '-' . $f['key'] ); ?>"><?php echo esc_html( $f['label'] ); ?></label>
						<?php $this->render_widget( $block, $f ); ?>
					</div>
				<?php endforeach; ?>

				<?php if ( ! empty( $desc['advanced'] ) ) : ?>
					<details class="mcm-advanced">
						<summary><?php esc_html_e( 'Advanced — all other settings', 'mcm' ); ?></summary>
						<p class="mcm-help"><?php esc_html_e( 'These control appearance and layout. Change them only if you know what they do.', 'mcm' ); ?></p>
						<div class="mcm-adv-grid">
							<?php foreach ( $desc['advanced'] as $f ) : ?>
								<div class="mcm-field mcm-adv-field">
									<label class="mcm-adv-label" for="<?php echo esc_attr( 'mcm-' . $block->id . '-' . $f['key'] ); ?>"><?php echo esc_html( $f['label'] ); ?></label>
									<?php $this->render_widget( $block, $f ); ?>
								</div>
							<?php endforeach; ?>
						</div>
					</details>
				<?php endif; ?>

				<div class="mcm-field-actions">
					<button type="submit" class="mcm-btn mcm-btn-primary"><?php esc_html_e( 'Save module', 'mcm' ); ?></button>
					<span class="mcm-last">
						<?php if ( $block->updated_at ) : ?>
							<?php
							/* translators: %s datetime */
							printf( esc_html__( 'Last saved %s', 'mcm' ), esc_html( $block->updated_at ) );
							?>
						<?php endif; ?>
					</span>
				</div>
			<?php endif; ?>
		</form>
		<?php
	}

	/**
	 * Render one widget inside a module form. All names are namespaced under
	 * mcm_fields[key]; images post under mcm_files[key].
	 *
	 * @param object $block
	 * @param array  $f descriptor
	 */
	private function render_widget( $block, $f ) {
		$id    = 'mcm-' . (int) $block->id . '-' . $f['key'];
		$name  = 'mcm_fields[' . $f['key'] . ']';
		$value = (string) $f['value'];

		switch ( $f['widget'] ) {
			case 'image':
				$src = MCM_Beaver::module_image_src( (int) $block->post_id, $block->node_id );
				if ( $src ) {
					echo '<div class="mcm-img-preview"><img src="' . esc_url( $src ) . '" alt="" /></div>';
				}
				echo '<input type="file" accept="image/*" name="mcm_files[' . esc_attr( $f['key'] ) . ']" id="' . esc_attr( $id ) . '" class="mcm-input" />';
				echo '<p class="mcm-help">' . esc_html__( 'Choose a new image to replace the current one. Leave empty to keep it.', 'mcm' ) . '</p>';
				break;

			case 'richtext':
				printf(
					'<textarea id="%1$s" name="%2$s" class="mcm-input mcm-richtext" rows="5">%3$s</textarea>',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_textarea( $value )
				);
				break;

			case 'textarea':
				printf(
					'<textarea id="%1$s" name="%2$s" class="mcm-input" rows="3">%3$s</textarea>',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_textarea( $value )
				);
				break;

			case 'select':
				echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" class="mcm-input">';
				foreach ( (array) $f['options'] as $opt ) {
					echo '<option value="' . esc_attr( $opt ) . '" ' . selected( $value, $opt, false ) . '>' . esc_html( $opt ) . '</option>';
				}
				echo '</select>';
				break;

			case 'toggle':
				$on      = $f['on'];
				$off     = $f['off'];
				$checked = ( (string) $value === (string) $on ) ? ' checked' : '';
				// Hidden "off" value so unchecking submits the off token.
				echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $off ) . '" />';
				echo '<label class="mcm-toggle"><input type="checkbox" name="' . esc_attr( $name ) . '" value="' . esc_attr( $on ) . '"' . $checked . ' /> ' . esc_html__( 'On', 'mcm' ) . '</label>';
				break;

			case 'color':
				$hex = preg_match( '/^#/', $value ) ? $value : ( '' !== $value ? '#' . ltrim( $value, '#' ) : '' );
				echo '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" class="mcm-input mcm-color" value="' . esc_attr( $value ) . '" placeholder="#000000" />';
				break;

			case 'number':
				echo '<input type="number" step="any" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" class="mcm-input" value="' . esc_attr( $value ) . '" />';
				break;

			case 'url':
				echo '<input type="url" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" class="mcm-input" value="' . esc_attr( $value ) . '" placeholder="https://" />';
				break;

			case 'icon':
				echo '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" class="mcm-input" value="' . esc_attr( $value ) . '" placeholder="fas fa-star" />';
				echo '<p class="mcm-help">' . esc_html__( 'Font Awesome class, e.g. "fas fa-phone".', 'mcm' ) . '</p>';
				break;

			case 'text':
			default:
				echo '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" class="mcm-input" value="' . esc_attr( $value ) . '" />';
				break;
		}
	}

	/**
	 * Render the correct, constrained input for a block's type.
	 *
	 * @param object $block
	 */
	private function render_editor_field( $block ) {
		$id      = 'mcm-f-' . (int) $block->id;
		$maxattr = (int) $block->max_length > 0 ? ' maxlength="' . (int) $block->max_length . '"' : '';
		$value   = $this->block_current_value( $block );
		$is_bb   = 'beaver' === $block->source;

		switch ( $block->type ) {
			case 'richtext':
				printf(
					'<textarea id="%1$s" name="mcm_content" class="mcm-input mcm-richtext" rows="6" data-countsource="text"%2$s>%3$s</textarea>',
					esc_attr( $id ),
					$maxattr, // safe: literal maxlength attr.
					esc_textarea( $value )
				);
				if ( $is_bb ) {
					echo '<p class="mcm-help">' . esc_html__( 'HTML is allowed here (this is a rich-text area). Scripts and unsafe markup are removed on save.', 'mcm' ) . '</p>';
				} else {
					echo '<p class="mcm-help">' . esc_html__( 'Allowed: bold, italic, links, lists, H2/H3. Everything else is stripped on save.', 'mcm' ) . '</p>';
				}
				break;

			case 'textarea':
				printf(
					'<textarea id="%1$s" name="mcm_content" class="mcm-input" rows="5"%2$s>%3$s</textarea>',
					esc_attr( $id ),
					$maxattr,
					esc_textarea( $value )
				);
				break;

			case 'text':
			default:
				printf(
					'<input id="%1$s" name="mcm_content" type="text" class="mcm-input"%2$s value="%3$s" />',
					esc_attr( $id ),
					$maxattr,
					esc_attr( $value )
				);
				break;
		}
	}

	/**
	 * The value to prefill into the editor: live from Beaver Builder for beaver
	 * blocks, otherwise our stored content.
	 *
	 * @param object $block
	 * @return string
	 */
	private function block_current_value( $block ) {
		if ( 'beaver' === $block->source ) {
			return MCM_Beaver::get_field_value( (int) $block->post_id, $block->node_id, $block->field_key );
		}
		return (string) $block->content;
	}

	private function type_label( $type ) {
		switch ( $type ) {
			case 'richtext':
				return __( 'rich text', 'mcm' );
			case 'textarea':
				return __( 'multi-line', 'mcm' );
			default:
				return __( 'text', 'mcm' );
		}
	}

	// =======================================================================
	// Handlers
	// =======================================================================
	public function handle_login() {
		if ( ! isset( $_POST['mcm_login_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mcm_login_nonce'] ) ), 'mcm_portal_login' ) ) {
			$this->bounce( array( 'mcm_perr' => __( 'Security check failed. Please try again.', 'mcm' ) ) );
		}

		$username = isset( $_POST['mcm_username'] ) ? sanitize_user( wp_unslash( $_POST['mcm_username'] ), true ) : '';
		$password = isset( $_POST['mcm_password'] ) ? (string) wp_unslash( $_POST['mcm_password'] ) : '';

		$result = MCM_Auth::instance()->login( $username, $password );
		if ( is_wp_error( $result ) ) {
			$this->bounce( array( 'mcm_perr' => $result->get_error_message() ) );
		}
		$this->bounce( array( 'mcm_pmsg' => __( 'Welcome back.', 'mcm' ) ) );
	}

	public function handle_logout() {
		if ( ! isset( $_POST['mcm_logout_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mcm_logout_nonce'] ) ), 'mcm_portal_logout' ) ) {
			$this->bounce( array( 'mcm_perr' => __( 'Security check failed.', 'mcm' ) ) );
		}
		MCM_Auth::instance()->logout();
		$this->bounce( array( 'mcm_pmsg' => __( 'You have been logged out.', 'mcm' ) ) );
	}

	public function handle_save() {
		$auth   = MCM_Auth::instance();
		$editor = $auth->current_editor();

		if ( ! $editor ) {
			$this->bounce( array( 'mcm_perr' => __( 'Your session has expired. Please log in again.', 'mcm' ) ) );
		}

		// CSRF tied to the editor's own session (nopriv nonces are weak).
		$csrf = isset( $_POST['mcm_csrf'] ) ? sanitize_text_field( wp_unslash( $_POST['mcm_csrf'] ) ) : '';
		if ( ! $auth->verify_csrf( $csrf ) ) {
			$this->bounce( array( 'mcm_perr' => __( 'Security check failed. Please reload and try again.', 'mcm' ) ) );
		}

		$block_id = isset( $_POST['block_id'] ) ? absint( $_POST['block_id'] ) : 0;
		$allowed  = MCM_DB::editor_allowed_ids( $editor );

		// The core authorization check: is this block assigned to this editor?
		if ( ! $block_id || ! in_array( $block_id, $allowed, true ) ) {
			$this->bounce( array( 'mcm_perr' => __( 'You are not allowed to edit that item.', 'mcm' ) ) );
		}

		$raw   = isset( $_POST['mcm_content'] ) ? (string) wp_unslash( $_POST['mcm_content'] ) : '';
		$name  = 'editor:' . $editor->username;
		$block = MCM_DB::get_block( $block_id );

		if ( $block && 'beaver_module' === $block->source ) {
			$this->save_module_block( $block, $name );
		}

		if ( $block && 'beaver' === $block->source ) {
			// Rich-text Beaver fields keep their existing markup (wp_kses_post);
			// text/textarea are reduced to plain text.
			$clean = MCM_DB::sanitize_block_content( $block->type, $raw, (int) $block->max_length, 'post' );
			$res   = MCM_Beaver::update_field_value( (int) $block->post_id, $block->node_id, $block->field_key, $clean );
			if ( is_wp_error( $res ) ) {
				$this->bounce( array( 'mcm_perr' => $res->get_error_message() ) );
			}
			// Refresh our cached preview value.
			MCM_DB::update_block_cache( $block_id, $clean, $name );
			$this->bounce( array( 'mcm_pmsg' => __( 'Saved to the page.', 'mcm' ) ) );
		}

		$res = MCM_DB::save_block_content( $block_id, $raw, $name );
		if ( is_wp_error( $res ) ) {
			$this->bounce( array( 'mcm_perr' => $res->get_error_message() ) );
		}
		$this->bounce( array( 'mcm_pmsg' => __( 'Saved.', 'mcm' ) ) );
	}

	/**
	 * Save a whole-module edit: sanitize each posted setting by its declared
	 * widget, handle image uploads, then write everything back to the module.
	 * Ends the request (via bounce()).
	 *
	 * @param object $block
	 * @param string $name editor label for the audit column
	 */
	private function save_module_block( $block, $name ) {
		$desc = MCM_Beaver::describe_module( (int) $block->post_id, $block->node_id );
		if ( is_wp_error( $desc ) ) {
			$this->bounce( array( 'mcm_perr' => $desc->get_error_message() ) );
		}

		$posted = isset( $_POST['mcm_fields'] ) && is_array( $_POST['mcm_fields'] ) ? wp_unslash( $_POST['mcm_fields'] ) : array(); // phpcs:ignore WordPress.Security.ValidationSanitization.MissingUnslash,WordPress.Security.ValidationSanitization.InputNotSanitized -- sanitized per-widget below.
		$assoc  = array();
		$images = array();

		// Only settings we actually described are writable — never trust extra
		// posted keys. Text-type widgets first; image uploads are applied last
		// so a fresh upload always wins over any posted photo_src field.
		foreach ( array_merge( $desc['primary'], $desc['advanced'] ) as $f ) {
			$key = $f['key'];

			if ( 'image' === $f['widget'] ) {
				$images[] = $key;
				continue;
			}
			if ( ! array_key_exists( $key, $posted ) ) {
				continue;
			}
			$assoc[ $key ] = MCM_Beaver::sanitize_widget_value( $f['widget'], $posted[ $key ], $f );
		}

		foreach ( $images as $key ) {
			$upload = $this->maybe_upload_image( $key );
			if ( is_wp_error( $upload ) ) {
				$this->bounce( array( 'mcm_perr' => $upload->get_error_message() ) );
			}
			if ( is_array( $upload ) ) {
				// Standard Beaver Builder photo settings; these override any
				// posted photo_src/photo_source.
				$assoc['photo']        = (int) $upload['id'];
				$assoc['photo_src']    = $upload['url'];
				$assoc['photo_source'] = 'library';
			}
		}

		if ( empty( $assoc ) ) {
			$this->bounce( array( 'mcm_pmsg' => __( 'Nothing to change.', 'mcm' ) ) );
		}

		$res = MCM_Beaver::update_module_settings( (int) $block->post_id, $block->node_id, $assoc );
		if ( is_wp_error( $res ) ) {
			$this->bounce( array( 'mcm_perr' => $res->get_error_message() ) );
		}

		// Refresh cached preview.
		$mod = MCM_Beaver::get_module( (int) $block->post_id, $block->node_id );
		if ( is_array( $mod ) ) {
			$preview = MCM_Beaver::module_preview( $mod['slug'], (object) $mod['settings'] );
			MCM_DB::update_block_cache( (int) $block->id, $preview, $name );
		}

		$this->bounce( array( 'mcm_pmsg' => __( 'Module saved to the page.', 'mcm' ) ) );
	}

	/**
	 * Handle an optional image upload for one module field.
	 *
	 * @param string $key field key posted under mcm_files[key]
	 * @return array|WP_Error|null [id,url] on upload, null if no file, WP_Error on failure
	 */
	private function maybe_upload_image( $key ) {
		if ( empty( $_FILES['mcm_files']['name'][ $key ] ) ) {
			return null;
		}

		$file = array(
			'name'     => sanitize_file_name( $_FILES['mcm_files']['name'][ $key ] ), // phpcs:ignore WordPress.Security.ValidationSanitization.InputNotSanitized
			'type'     => isset( $_FILES['mcm_files']['type'][ $key ] ) ? sanitize_text_field( $_FILES['mcm_files']['type'][ $key ] ) : '',
			'tmp_name' => isset( $_FILES['mcm_files']['tmp_name'][ $key ] ) ? $_FILES['mcm_files']['tmp_name'][ $key ] : '',
			'error'    => isset( $_FILES['mcm_files']['error'][ $key ] ) ? (int) $_FILES['mcm_files']['error'][ $key ] : UPLOAD_ERR_NO_FILE,
			'size'     => isset( $_FILES['mcm_files']['size'][ $key ] ) ? (int) $_FILES['mcm_files']['size'][ $key ] : 0,
		);

		if ( UPLOAD_ERR_NO_FILE === $file['error'] ) {
			return null;
		}

		// Enforce image types + a size cap regardless of what the browser sent.
		$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
		$allowed = array( 'jpg|jpeg|jpe', 'gif', 'png', 'webp' );
		$ok_ext  = false;
		foreach ( $allowed as $ext ) {
			if ( isset( $check['ext'] ) && preg_match( '/^(' . $ext . ')$/', $check['ext'] ) ) {
				$ok_ext = true;
				break;
			}
		}
		if ( ! $ok_ext || 0 !== strpos( (string) $check['type'], 'image/' ) ) {
			return new WP_Error( 'mcm_img_type', __( 'Please choose an image file (jpg, png, gif or webp).', 'mcm' ) );
		}
		if ( $file['size'] > 8 * MB_IN_BYTES ) {
			return new WP_Error( 'mcm_img_size', __( 'That image is too large (max 8 MB).', 'mcm' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$overrides = array(
			'test_form' => false,
			'mimes'     => array(
				'jpg|jpeg|jpe' => 'image/jpeg',
				'gif'          => 'image/gif',
				'png'          => 'image/png',
				'webp'         => 'image/webp',
			),
		);
		$moved = wp_handle_upload( $file, $overrides );
		if ( isset( $moved['error'] ) ) {
			return new WP_Error( 'mcm_img_upload', $moved['error'] );
		}

		$attachment = array(
			'post_mime_type' => $moved['type'],
			'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $moved['file'] ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);
		$attach_id = wp_insert_attachment( $attachment, $moved['file'] );
		if ( is_wp_error( $attach_id ) || ! $attach_id ) {
			return new WP_Error( 'mcm_img_attach', __( 'The image could not be saved to the media library.', 'mcm' ) );
		}
		$meta = wp_generate_attachment_metadata( $attach_id, $moved['file'] );
		wp_update_attachment_metadata( $attach_id, $meta );

		return array(
			'id'  => (int) $attach_id,
			'url' => wp_get_attachment_url( $attach_id ),
		);
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	/**
	 * Current front-end URL (used to return the visitor to the portal page).
	 *
	 * @return string
	 */
	private function current_url() {
		$settings = mcm_get_settings();
		if ( $settings['portal_page_id'] ) {
			$permalink = get_permalink( (int) $settings['portal_page_id'] );
			if ( $permalink ) {
				return $permalink;
			}
		}
		$ref = wp_get_referer();
		return $ref ? $ref : home_url( '/' );
	}

	/**
	 * Redirect back to the portal, only ever to a same-host URL.
	 *
	 * @param array $args query args (message/error)
	 */
	private function bounce( $args = array() ) {
		$target = isset( $_POST['redirect'] ) ? esc_url_raw( wp_unslash( $_POST['redirect'] ) ) : $this->current_url(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$target = wp_validate_redirect( $target, $this->current_url() );
		wp_safe_redirect( add_query_arg( $args, $target ) );
		exit;
	}
}
