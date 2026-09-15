<?php
/**
 * Marking content hidden from inside the editor, ported from WPCode snippet #4.
 *
 * Writes the same meta key the hide-plugin uses, so flagging something here or
 * there is the same act and the two never disagree.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPSQR_PostList {

	public function hooks() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post', array( $this, 'save_meta_box' ) );

		add_filter( 'post_row_actions', array( $this, 'row_action' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'row_action' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'handle_row_action' ) );

		add_action( 'admin_init', array( $this, 'register_list_hooks' ) );
		add_action( 'admin_notices', array( $this, 'bulk_notice' ) );
	}

	protected function post_types() {
		return apply_filters( 'wpsqr_hideable_post_types', array_keys( get_post_types( array( 'public' => true ) ) ) );
	}

	protected function meta_key() {
		return WPSQR_Plugin::settings()['hide_meta_key'];
	}

	public static function is_hidden( $post_id ) {
		$settings = WPSQR_Plugin::settings();
		$key      = $settings['hide_meta_key'];

		if ( '' === $key ) {
			return false;
		}

		$value = get_post_meta( $post_id, $key, true );

		return ( '' === $settings['hide_meta_value'] )
			? ( '' !== $value )
			: ( (string) $value === (string) $settings['hide_meta_value'] );
	}

	public static function set_hidden( $post_id, $hidden ) {
		$settings = WPSQR_Plugin::settings();
		$key      = $settings['hide_meta_key'];

		if ( '' === $key ) {
			return;
		}

		if ( $hidden ) {
			update_post_meta( $post_id, $key, '' === $settings['hide_meta_value'] ? '1' : $settings['hide_meta_value'] );
		} else {
			delete_post_meta( $post_id, $key );
		}

		WPSQR_Hidden::flush();
	}

	/* ---- Edit screen --------------------------------------------------- */

	public function add_meta_box() {
		add_meta_box( 'wpsqr-visibility', __( 'Search results', 'wpsqr' ), array( $this, 'render_meta_box' ), $this->post_types(), 'side' );
	}

	protected function desc_meta_key() {
		return WPSQR_Plugin::settings()['desc_meta_key'];
	}

	public function render_meta_box( $post ) {
		wp_nonce_field( 'wpsqr_hide_' . $post->ID, 'wpsqr_hide_nonce' );

		$desc_key = $this->desc_meta_key();
		$desc     = '' === $desc_key ? '' : (string) get_post_meta( $post->ID, $desc_key, true );
		?>
		<?php if ( '' !== $this->meta_key() ) : ?>
			<p>
				<label>
					<input type="checkbox" name="wpsqr_hide" value="1" <?php checked( self::is_hidden( $post->ID ) ); ?>>
					<?php esc_html_e( 'Hide from search results', 'wpsqr' ); ?>
				</label>
			</p>
			<p class="description">
				<?php esc_html_e( 'Stays published and reachable by direct link — it just will not be listed in search.', 'wpsqr' ); ?>
			</p>
		<?php endif; ?>

		<?php if ( '' !== $desc_key ) : ?>
			<p style="margin-top:1em">
				<label for="wpsqr-desc"><strong><?php esc_html_e( 'Search description', 'wpsqr' ); ?></strong></label>
				<textarea id="wpsqr-desc" name="wpsqr_desc" rows="4" style="width:100%"
					placeholder="<?php echo esc_attr( wp_strip_all_tags( WPSQR_Renderer::description( $post ) ) ); ?>"><?php
					echo esc_textarea( $desc );
				?></textarea>
			</p>
			<p class="description">
				<?php esc_html_e( 'What this page should say when it turns up in search. Leave empty to use the automatic summary shown above — worth writing when that summary reads poorly out of context.', 'wpsqr' ); ?>
			</p>
		<?php endif; ?>
		<?php
	}

	public function save_meta_box( $post_id ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		$nonce = isset( $_POST['wpsqr_hide_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['wpsqr_hide_nonce'] ) ) : '';

		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wpsqr_hide_' . $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( '' !== $this->meta_key() ) {
			self::set_hidden( $post_id, ! empty( $_POST['wpsqr_hide'] ) );
		}

		$desc_key = $this->desc_meta_key();

		if ( '' !== $desc_key && isset( $_POST['wpsqr_desc'] ) ) {
			$desc = sanitize_textarea_field( wp_unslash( $_POST['wpsqr_desc'] ) );

			if ( '' === trim( $desc ) ) {
				delete_post_meta( $post_id, $desc_key );
			} else {
				update_post_meta( $post_id, $desc_key, $desc );
			}

			WPSQR_Hidden::flush();
		}
	}

	/* ---- Posts list ---------------------------------------------------- */

	public function row_action( $actions, $post ) {
		if ( '' === $this->meta_key() ) {
			return $actions;
		}

		if ( ! in_array( $post->post_type, $this->post_types(), true ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}

		$hidden = self::is_hidden( $post->ID );

		$url = wp_nonce_url(
			add_query_arg(
				array(
					'wpsqr_toggle' => $post->ID,
					'wpsqr_to'     => $hidden ? '0' : '1',
				),
				admin_url( 'edit.php?post_type=' . $post->post_type )
			),
			'wpsqr_toggle_' . $post->ID
		);

		$actions['wpsqr_hide'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $url ),
			$hidden ? esc_html__( 'Show in search', 'wpsqr' ) : esc_html__( 'Hide from search', 'wpsqr' )
		);

		return $actions;
	}

	public function handle_row_action() {
		if ( empty( $_GET['wpsqr_toggle'] ) ) {
			return;
		}

		$post_id = (int) $_GET['wpsqr_toggle'];
		$nonce   = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'wpsqr_toggle_' . $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Not allowed.', 'wpsqr' ) );
		}

		self::set_hidden( $post_id, ! empty( $_GET['wpsqr_to'] ) );

		wp_safe_redirect( remove_query_arg( array( 'wpsqr_toggle', 'wpsqr_to', '_wpnonce' ) ) );
		exit;
	}

	public function register_list_hooks() {
		if ( '' === $this->meta_key() ) {
			return;
		}

		foreach ( $this->post_types() as $type ) {
			add_filter( "bulk_actions-edit-{$type}", array( $this, 'bulk_actions' ) );
			add_filter( "handle_bulk_actions-edit-{$type}", array( $this, 'handle_bulk' ), 10, 3 );
			add_filter( "manage_{$type}_posts_columns", array( $this, 'add_column' ) );
			add_action( "manage_{$type}_posts_custom_column", array( $this, 'render_column' ), 10, 2 );
		}
	}

	public function bulk_actions( $actions ) {
		$actions['wpsqr_hide']   = __( 'Hide from search', 'wpsqr' );
		$actions['wpsqr_unhide'] = __( 'Show in search', 'wpsqr' );

		return $actions;
	}

	public function handle_bulk( $redirect, $action, $post_ids ) {
		if ( 'wpsqr_hide' !== $action && 'wpsqr_unhide' !== $action ) {
			return $redirect;
		}

		$count = 0;

		foreach ( $post_ids as $post_id ) {
			if ( current_user_can( 'edit_post', $post_id ) ) {
				self::set_hidden( $post_id, 'wpsqr_hide' === $action );
				$count++;
			}
		}

		return add_query_arg( 'wpsqr_bulk', $count, $redirect );
	}

	public function bulk_notice() {
		if ( ! isset( $_GET['wpsqr_bulk'] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of posts */
					_n( 'Search visibility updated for %d item.', 'Search visibility updated for %d items.', (int) $_GET['wpsqr_bulk'], 'wpsqr' ),
					(int) $_GET['wpsqr_bulk']
				)
			)
		);
	}

	public function add_column( $columns ) {
		$columns['wpsqr_search'] = __( 'Search', 'wpsqr' );

		return $columns;
	}

	public function render_column( $column, $post_id ) {
		if ( 'wpsqr_search' !== $column ) {
			return;
		}

		if ( self::is_hidden( $post_id ) ) {
			printf(
				'<span style="color:#d63638" title="%s">&#9679; %s</span>',
				esc_attr__( 'Hidden from search results', 'wpsqr' ),
				esc_html__( 'Hidden', 'wpsqr' )
			);
			return;
		}

		echo '<span style="color:#8c8f94">&mdash;</span>';
	}
}
