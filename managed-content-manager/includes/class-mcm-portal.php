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

			<?php if ( empty( $blocks ) ) : ?>
				<p class="mcm-empty"><?php esc_html_e( 'You have not been assigned any content to edit yet.', 'mcm' ); ?></p>
			<?php else : ?>
				<?php foreach ( $blocks as $block ) : ?>
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
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
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
