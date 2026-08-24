<?php
/**
 * In-place, per-page editing — builder-agnostic.
 *
 * When a logged-in editor (separate MCM session) opens a page they're allowed
 * to edit with ?mcm_edit=1, this class detects which page builder built that
 * page (Beaver Builder, Elementor, or the block editor) and lets them edit it:
 *   - builders that mark each unit in the DOM (Beaver Builder, Elementor) get
 *     click-on-the-page editing,
 *   - the block editor gets a "choose a block" list in the same drawer,
 * both over the REAL, fully-rendered page so the screen matches the live page.
 *
 * @package mcm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCM_Editmode {

	/** @var MCM_Editmode|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
		add_action( 'wp_footer', array( $this, 'render_chrome' ) );

		foreach ( array( 'mcm_edit_list', 'mcm_edit_form', 'mcm_edit_save' ) as $action ) {
			add_action( 'wp_ajax_' . $action, array( $this, 'ajax_' . $action ) );
			add_action( 'wp_ajax_nopriv_' . $action, array( $this, 'ajax_' . $action ) );
		}
	}

	/**
	 * The post the current editor may edit in place (0 if none).
	 *
	 * @return int
	 */
	public function editable_post_id() {
		if ( ! is_singular() ) {
			return 0;
		}
		$editor = MCM_Auth::instance()->current_editor();
		if ( ! $editor ) {
			return 0;
		}
		$post_id = (int) get_queried_object_id();
		if ( ! $post_id ) {
			return 0;
		}
		$allowed = MCM_DB::editor_allowed_page_ids( $editor );
		return in_array( $post_id, $allowed, true ) ? $post_id : 0;
	}

	/**
	 * Post id if edit mode is active (allowed + ?mcm_edit flag), else 0.
	 *
	 * @return int
	 */
	public function active_post_id() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['mcm_edit'] ) ) {
			return 0;
		}
		return $this->editable_post_id();
	}

	public function maybe_enqueue() {
		$post_id = $this->active_post_id();
		if ( ! $post_id ) {
			return;
		}
		$provider = MCM_Providers::for_post( $post_id );

		wp_enqueue_style( 'mcm-editmode', MCM_URL . 'assets/editmode.css', array(), MCM_VERSION );
		wp_enqueue_script( 'mcm-editmode', MCM_URL . 'assets/editmode.js', array(), MCM_VERSION, true );

		$settings   = mcm_get_settings();
		$portal_url = $settings['portal_page_id'] ? get_permalink( (int) $settings['portal_page_id'] ) : home_url( '/' );

		$provider_cfg = $provider
			? array(
				'key'      => $provider->key(),
				'name'     => $provider->name(),
				'inplace'  => $provider->supports_inplace(),
				'selector' => $provider->dom_selector(),
				'idAttr'   => $provider->dom_id_attr(),
			)
			: array( 'key' => '', 'name' => '', 'inplace' => false, 'selector' => '', 'idAttr' => '' );

		wp_localize_script(
			'mcm-editmode',
			'MCM_EDIT',
			array(
				'ajaxurl'   => admin_url( 'admin-ajax.php' ),
				'csrf'      => MCM_Auth::instance()->csrf_token(),
				'postId'    => $post_id,
				'portalUrl' => $portal_url,
				'provider'  => $provider_cfg,
				'i18n'      => array(
					'edit'      => __( 'Edit', 'mcm' ),
					'done'      => __( 'Done', 'mcm' ),
					'save'      => __( 'Save', 'mcm' ),
					'cancel'    => __( 'Cancel', 'mcm' ),
					'saving'    => __( 'Saving…', 'mcm' ),
					'loading'   => __( 'Loading…', 'mcm' ),
					'chooseBlk' => __( 'Choose a block', 'mcm' ),
					'noBlocks'  => __( 'No editable content found on this page.', 'mcm' ),
					'error'     => __( 'Something went wrong. Please try again.', 'mcm' ),
				),
			)
		);
	}

	/**
	 * Toolbar + drawer shell (edit mode only).
	 */
	public function render_chrome() {
		$post_id = $this->active_post_id();
		if ( ! $post_id ) {
			return;
		}
		$provider = MCM_Providers::for_post( $post_id );
		$bname    = $provider ? $provider->name() : __( 'Unknown builder', 'mcm' );
		?>
		<div id="mcm-editmode" class="mcm-em" aria-hidden="false">
			<div class="mcm-em-bar">
				<span class="mcm-em-badge"><?php esc_html_e( 'Content editor', 'mcm' ); ?></span>
				<span class="mcm-em-title"><?php echo esc_html( get_the_title( $post_id ) ); ?></span>
				<span class="mcm-em-builder"><?php echo esc_html( $bname ); ?></span>
				<button type="button" class="mcm-em-list-btn"><?php esc_html_e( 'Choose a block', 'mcm' ); ?></button>
				<a class="mcm-em-done" href="<?php echo esc_url( remove_query_arg( 'mcm_edit' ) ); ?>"><?php esc_html_e( 'Done', 'mcm' ); ?></a>
			</div>
			<div class="mcm-em-drawer" role="dialog" aria-modal="true" hidden>
				<div class="mcm-em-drawer-head">
					<strong class="mcm-em-drawer-title"></strong>
					<button type="button" class="mcm-em-close" aria-label="<?php esc_attr_e( 'Close', 'mcm' ); ?>">&times;</button>
				</div>
				<div class="mcm-em-list" hidden></div>
				<form class="mcm-em-form" enctype="multipart/form-data" hidden>
					<div class="mcm-em-fields"></div>
					<div class="mcm-em-actions">
						<button type="submit" class="mcm-btn mcm-btn-primary mcm-em-save"><?php esc_html_e( 'Save', 'mcm' ); ?></button>
						<button type="button" class="mcm-btn mcm-btn-ghost mcm-em-cancel"><?php esc_html_e( 'Cancel', 'mcm' ); ?></button>
						<span class="mcm-em-status" role="status"></span>
					</div>
				</form>
			</div>
			<div class="mcm-em-scrim" hidden></div>
		</div>
		<?php
	}

	// =======================================================================
	// AJAX
	// =======================================================================

	/**
	 * Shared guard. Returns [ editor, post_id, provider ] or sends JSON error.
	 *
	 * @return array
	 */
	private function ajax_guard() {
		$editor = MCM_Auth::instance()->current_editor();
		if ( ! $editor ) {
			wp_send_json_error( array( 'message' => __( 'Your session has expired. Please log in again.', 'mcm' ) ), 403 );
		}
		$csrf = isset( $_POST['csrf'] ) ? sanitize_text_field( wp_unslash( $_POST['csrf'] ) ) : '';
		if ( ! MCM_Auth::instance()->verify_csrf( $csrf ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Please reload the page.', 'mcm' ) ), 403 );
		}
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$allowed = MCM_DB::editor_allowed_page_ids( $editor );
		if ( ! $post_id || ! in_array( $post_id, $allowed, true ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to edit this page.', 'mcm' ) ), 403 );
		}
		$provider = MCM_Providers::for_post( $post_id );
		if ( ! $provider ) {
			wp_send_json_error( array( 'message' => __( 'No supported page builder was detected on this page.', 'mcm' ) ), 400 );
		}
		return array( $editor, $post_id, $provider );
	}

	/** Return the list of editable nodes for the page. */
	public function ajax_mcm_edit_list() {
		list( , $post_id, $provider ) = $this->ajax_guard();
		wp_send_json_success( array( 'nodes' => array_values( $provider->list_nodes( $post_id ) ) ) );
	}

	/** Return the drawer field HTML for one node. */
	public function ajax_mcm_edit_form() {
		list( , $post_id, $provider ) = $this->ajax_guard();
		$node_id = isset( $_POST['node_id'] ) ? sanitize_text_field( wp_unslash( $_POST['node_id'] ) ) : '';
		if ( '' === $node_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing block reference.', 'mcm' ) ), 400 );
		}
		$desc = $provider->describe_node( $post_id, $node_id );
		if ( is_wp_error( $desc ) ) {
			wp_send_json_error( array( 'message' => $desc->get_error_message() ), 404 );
		}
		wp_send_json_success(
			array(
				'title' => $desc['label'],
				'html'  => $this->render_fields_html( $provider, $post_id, $node_id, $desc ),
			)
		);
	}

	/** Save one node's edited settings. */
	public function ajax_mcm_edit_save() {
		list( , $post_id, $provider ) = $this->ajax_guard();
		$node_id = isset( $_POST['node_id'] ) ? sanitize_text_field( wp_unslash( $_POST['node_id'] ) ) : '';
		if ( '' === $node_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing block reference.', 'mcm' ) ), 400 );
		}
		$desc = $provider->describe_node( $post_id, $node_id );
		if ( is_wp_error( $desc ) ) {
			wp_send_json_error( array( 'message' => $desc->get_error_message() ), 404 );
		}

		$posted = isset( $_POST['mcm_fields'] ) && is_array( $_POST['mcm_fields'] ) ? wp_unslash( $_POST['mcm_fields'] ) : array(); // phpcs:ignore WordPress.Security.ValidationSanitization.InputNotSanitized,WordPress.Security.ValidationSanitization.MissingUnslash -- sanitized per-widget below.
		$values = array();
		$images = array();

		foreach ( array_merge( $desc['primary'], $desc['advanced'] ) as $f ) {
			if ( 'image' === $f['widget'] ) {
				$images[] = $f['key'];
				continue;
			}
			if ( ! array_key_exists( $f['key'], $posted ) ) {
				continue;
			}
			$values[ $f['key'] ] = MCM_Beaver::sanitize_widget_value( $f['widget'], $posted[ $f['key'] ], $f );
		}

		// Image uploads become a normalized { id, url } the provider maps itself.
		foreach ( $images as $key ) {
			$upload = $this->upload_image( $key );
			if ( is_wp_error( $upload ) ) {
				wp_send_json_error( array( 'message' => $upload->get_error_message() ), 400 );
			}
			if ( is_array( $upload ) ) {
				$values[ $key ] = array( 'id' => (int) $upload['id'], 'url' => $upload['url'] );
			}
		}

		if ( empty( $values ) ) {
			wp_send_json_success( array( 'message' => __( 'Nothing to change.', 'mcm' ) ) );
		}

		$res = $provider->update_node( $post_id, $node_id, $values );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ), 500 );
		}
		wp_send_json_success( array( 'message' => __( 'Saved.', 'mcm' ) ) );
	}

	// =======================================================================
	// Field rendering
	// =======================================================================

	private function render_fields_html( $provider, $post_id, $node_id, $desc ) {
		ob_start();
		foreach ( $desc['primary'] as $f ) {
			echo '<div class="mcm-field">';
			echo '<label class="mcm-field-label">' . esc_html( $f['label'] ) . '</label>';
			echo $this->widget_html( $provider, $post_id, $node_id, $f ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</div>';
		}
		if ( ! empty( $desc['advanced'] ) ) {
			echo '<details class="mcm-advanced"><summary>' . esc_html__( 'Advanced — all other settings', 'mcm' ) . '</summary>';
			echo '<div class="mcm-adv-grid">';
			foreach ( $desc['advanced'] as $f ) {
				echo '<div class="mcm-field mcm-adv-field">';
				echo '<label class="mcm-adv-label">' . esc_html( $f['label'] ) . '</label>';
				echo $this->widget_html( $provider, $post_id, $node_id, $f ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '</div>';
			}
			echo '</div></details>';
		}
		return ob_get_clean();
	}

	private function widget_html( $provider, $post_id, $node_id, $f ) {
		$name  = 'mcm_fields[' . $f['key'] . ']';
		$value = (string) $f['value'];

		ob_start();
		switch ( $f['widget'] ) {
			case 'image':
				$src = $provider->node_image_src( $post_id, $node_id );
				if ( $src ) {
					echo '<div class="mcm-img-preview"><img src="' . esc_url( $src ) . '" alt="" /></div>';
				}
				echo '<input type="file" accept="image/*" name="mcm_files[' . esc_attr( $f['key'] ) . ']" class="mcm-input" />';
				echo '<p class="mcm-help">' . esc_html__( 'Choose a new image to replace the current one. Leave empty to keep it.', 'mcm' ) . '</p>';
				break;

			case 'richtext':
				echo '<textarea name="' . esc_attr( $name ) . '" class="mcm-input mcm-richtext" rows="5">' . esc_textarea( $value ) . '</textarea>';
				break;

			case 'textarea':
				echo '<textarea name="' . esc_attr( $name ) . '" class="mcm-input" rows="3">' . esc_textarea( $value ) . '</textarea>';
				break;

			case 'select':
				echo '<select name="' . esc_attr( $name ) . '" class="mcm-input">';
				foreach ( (array) $f['options'] as $opt ) {
					echo '<option value="' . esc_attr( $opt ) . '" ' . selected( $value, $opt, false ) . '>' . esc_html( $opt ) . '</option>';
				}
				echo '</select>';
				break;

			case 'toggle':
				$checked = ( (string) $value === (string) $f['on'] ) ? ' checked' : '';
				echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $f['off'] ) . '" />';
				echo '<label class="mcm-toggle"><input type="checkbox" name="' . esc_attr( $name ) . '" value="' . esc_attr( $f['on'] ) . '"' . $checked . ' /> ' . esc_html__( 'On', 'mcm' ) . '</label>';
				break;

			case 'color':
				echo '<input type="text" name="' . esc_attr( $name ) . '" class="mcm-input mcm-color" value="' . esc_attr( $value ) . '" placeholder="#000000" />';
				break;

			case 'number':
				echo '<input type="number" step="any" name="' . esc_attr( $name ) . '" class="mcm-input" value="' . esc_attr( $value ) . '" />';
				break;

			case 'url':
				echo '<input type="url" name="' . esc_attr( $name ) . '" class="mcm-input" value="' . esc_attr( $value ) . '" placeholder="https://" />';
				break;

			case 'icon':
				echo '<input type="text" name="' . esc_attr( $name ) . '" class="mcm-input" value="' . esc_attr( $value ) . '" placeholder="fas fa-star" />';
				echo '<p class="mcm-help">' . esc_html__( 'Icon class, e.g. "fas fa-phone".', 'mcm' ) . '</p>';
				break;

			case 'text':
			default:
				echo '<input type="text" name="' . esc_attr( $name ) . '" class="mcm-input" value="' . esc_attr( $value ) . '" />';
				break;
		}
		return ob_get_clean();
	}

	/**
	 * Upload one image field (AJAX FormData: mcm_files[key]).
	 *
	 * @param string $key
	 * @return array|WP_Error|null
	 */
	private function upload_image( $key ) {
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

		$check   = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
		$allowed = array( 'jpg', 'jpeg', 'jpe', 'gif', 'png', 'webp' );
		if ( empty( $check['ext'] ) || ! in_array( strtolower( $check['ext'] ), $allowed, true ) || 0 !== strpos( (string) $check['type'], 'image/' ) ) {
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

		$attach_id = wp_insert_attachment(
			array(
				'post_mime_type' => $moved['type'],
				'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $moved['file'] ) ),
				'post_status'    => 'inherit',
			),
			$moved['file']
		);
		if ( is_wp_error( $attach_id ) || ! $attach_id ) {
			return new WP_Error( 'mcm_img_attach', __( 'The image could not be saved.', 'mcm' ) );
		}
		wp_update_attachment_metadata( $attach_id, wp_generate_attachment_metadata( $attach_id, $moved['file'] ) );

		return array( 'id' => (int) $attach_id, 'url' => wp_get_attachment_url( $attach_id ) );
	}
}
