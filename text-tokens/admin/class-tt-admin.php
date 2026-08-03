<?php
/**
 * Admin settings screen: Settings → Text Tokens.
 *
 * @package TextTokens
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TT_Admin
 *
 * Renders and processes the single settings page. The page is intentionally a
 * server-rendered form (progressive enhancement) so it works without JS; the
 * accompanying script only improves the experience (showing the relevant
 * config fields, live preview, aria-live announcements).
 */
class TT_Admin {

	const MENU_SLUG = 'text-tokens';
	const NONCE     = 'tt_save_token';

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_post_tt_save_token', array( $this, 'handle_save' ) );
		add_action( 'admin_post_tt_delete_token', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_tt_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_tt_preview', array( $this, 'ajax_preview' ) );
	}

	/**
	 * Add the page under the Settings menu (single-site only).
	 *
	 * @return void
	 */
	public function add_settings_page() {
		add_options_page(
			__( 'Text Tokens', 'text-tokens' ),
			__( 'Text Tokens', 'text-tokens' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue admin CSS/JS only on our screen.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( 'settings_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'tt-admin',
			TT_PLUGIN_URL . 'admin/css/admin.css',
			array(),
			TT_VERSION
		);

		wp_enqueue_script(
			'tt-admin',
			TT_PLUGIN_URL . 'admin/js/admin.js',
			array( 'jquery' ),
			TT_VERSION,
			true
		);

		wp_localize_script(
			'tt-admin',
			'ttAdmin',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'previewNonce' => wp_create_nonce( 'tt_preview' ),
				'rules'       => $this->rules_for_js(),
				'i18n'        => array(
					'rowAdded'   => __( 'New token row added.', 'text-tokens' ),
					'rowRemoved' => __( 'Token row removed.', 'text-tokens' ),
					'previewing' => __( 'Calculating preview…', 'text-tokens' ),
				),
			)
		);
	}

	/**
	 * Build a JS-friendly description of rule config fields.
	 *
	 * @return array
	 */
	private function rules_for_js() {
		$out = array();
		foreach ( TT_Rules::definitions() as $slug => $def ) {
			$out[ $slug ] = array(
				'label'  => $def['label'],
				'desc'   => $def['desc'],
				'fields' => $def['fields'],
			);
		}
		return $out;
	}

	/**
	 * Verify the current user may manage tokens.
	 *
	 * @return void
	 */
	private function require_cap() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage text tokens.', 'text-tokens' ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * Form processing
	 * ------------------------------------------------------------------- */

	/**
	 * Handle create/update of a single token.
	 *
	 * @return void
	 */
	public function handle_save() {
		$this->require_cap();
		check_admin_referer( self::NONCE );

		$id   = isset( $_POST['token_id'] ) ? sanitize_text_field( wp_unslash( $_POST['token_id'] ) ) : '';
		$code = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
		$code = TT_Tokens::normalize_code( $code );
		$type = isset( $_POST['type'] ) && 'dynamic' === $_POST['type'] ? 'dynamic' : 'static';

		if ( '' === $code ) {
			$this->redirect_with_notice( 'error', __( 'Token code cannot be empty.', 'text-tokens' ) );
		}

		if ( ! preg_match( '/^[A-Z0-9 _\-]+$/', $code ) ) {
			$this->redirect_with_notice( 'error', __( 'Token code may only contain letters, numbers, spaces, hyphens and underscores.', 'text-tokens' ) );
		}

		if ( '' === $id ) {
			$id = TT_Tokens::generate_id();
		}

		if ( TT_Tokens::code_exists( $code, $id ) ) {
			/* translators: %s: token code. */
			$this->redirect_with_notice( 'error', sprintf( __( 'A token with the code [%s] already exists.', 'text-tokens' ), $code ) );
		}

		$description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';

		$token = array(
			'id'          => $id,
			'code'        => $code,
			'type'        => $type,
			'description' => $description,
			'value'       => '',
			'rule'        => '',
			'config'      => array(),
		);

		if ( 'static' === $type ) {
			// Static values may legitimately contain HTML entities/markup an
			// editor typed; store as sanitized post-style content.
			$token['value'] = isset( $_POST['value'] ) ? wp_kses_post( wp_unslash( $_POST['value'] ) ) : '';
		} else {
			$rule = isset( $_POST['rule'] ) ? sanitize_key( wp_unslash( $_POST['rule'] ) ) : '';
			if ( ! TT_Rules::exists( $rule ) ) {
				$this->redirect_with_notice( 'error', __( 'Please choose a valid dynamic rule.', 'text-tokens' ) );
			}
			$token['rule']   = $rule;
			$token['config'] = $this->sanitize_rule_config( $rule );
		}

		TT_Tokens::upsert( $token );
		$this->redirect_with_notice( 'success', __( 'Token saved.', 'text-tokens' ) );
	}

	/**
	 * Sanitize the posted config for a given rule against its field definitions.
	 *
	 * @param string $rule Rule slug.
	 * @return array
	 */
	private function sanitize_rule_config( $rule ) {
		$definitions = TT_Rules::definitions();
		$fields      = isset( $definitions[ $rule ]['fields'] ) ? $definitions[ $rule ]['fields'] : array();
		$posted      = isset( $_POST['config'] ) && is_array( $_POST['config'] ) ? wp_unslash( $_POST['config'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked by caller.

		$config = array();
		foreach ( $fields as $slug => $field ) {
			$raw  = isset( $posted[ $slug ] ) ? $posted[ $slug ] : ( isset( $field['default'] ) ? $field['default'] : '' );
			$type = isset( $field['type'] ) ? $field['type'] : 'text';

			switch ( $type ) {
				case 'select':
					$raw     = sanitize_text_field( $raw );
					$options = isset( $field['options'] ) ? array_map( 'strval', array_keys( $field['options'] ) ) : array();
					if ( ! empty( $options ) && ! in_array( (string) $raw, $options, true ) ) {
						$raw = isset( $field['default'] ) ? $field['default'] : reset( $options );
					}
					break;
				case 'date':
					$raw = sanitize_text_field( $raw );
					if ( '' !== $raw && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) {
						$raw = '';
					}
					break;
				default:
					$raw = sanitize_text_field( $raw );
			}
			$config[ $slug ] = $raw;
		}
		return $config;
	}

	/**
	 * Handle deletion of a token.
	 *
	 * @return void
	 */
	public function handle_delete() {
		$this->require_cap();
		$id = isset( $_REQUEST['token_id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['token_id'] ) ) : '';
		check_admin_referer( 'tt_delete_' . $id );

		if ( '' !== $id ) {
			TT_Tokens::delete( $id );
		}
		$this->redirect_with_notice( 'success', __( 'Token deleted.', 'text-tokens' ) );
	}

	/**
	 * Handle global settings save (cache TTL).
	 *
	 * @return void
	 */
	public function handle_save_settings() {
		$this->require_cap();
		check_admin_referer( 'tt_save_settings' );

		$ttl_minutes = isset( $_POST['cache_ttl_minutes'] ) ? (int) $_POST['cache_ttl_minutes'] : 60;
		$ttl_minutes = max( 0, min( 1440, $ttl_minutes ) );

		update_option(
			TT_OPTION_SETTINGS,
			array(
				'cache_ttl' => $ttl_minutes * MINUTE_IN_SECONDS,
			)
		);
		TT_Resolver::flush_cache();
		$this->redirect_with_notice( 'success', __( 'Settings saved and cache cleared.', 'text-tokens' ) );
	}

	/**
	 * AJAX: live preview of a code's resolved value (fresh, uncached).
	 *
	 * @return void
	 */
	public function ajax_preview() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'text-tokens' ) ), 403 );
		}
		check_ajax_referer( 'tt_preview', 'nonce' );

		$type = isset( $_POST['type'] ) && 'dynamic' === $_POST['type'] ? 'dynamic' : 'static';

		if ( 'static' === $type ) {
			$value = isset( $_POST['value'] ) ? wp_kses_post( wp_unslash( $_POST['value'] ) ) : '';
			wp_send_json_success( array( 'value' => $value ) );
		}

		$rule = isset( $_POST['rule'] ) ? sanitize_key( wp_unslash( $_POST['rule'] ) ) : '';
		if ( ! TT_Rules::exists( $rule ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown rule.', 'text-tokens' ) ) );
		}
		$config = $this->sanitize_rule_config( $rule );
		$value  = TT_Rules::evaluate( $rule, $config );
		wp_send_json_success( array( 'value' => $value ) );
	}

	/**
	 * Redirect back to the settings page with a transient notice.
	 *
	 * @param string $type    'success' | 'error'.
	 * @param string $message Message text.
	 * @return void
	 */
	private function redirect_with_notice( $type, $message ) {
		set_transient( 'tt_notice_' . get_current_user_id(), array( 'type' => $type, 'message' => $message ), 30 );
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::MENU_SLUG ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------------- */

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_page() {
		$this->require_cap();

		$tokens   = TT_Tokens::all();
		$settings = get_option( TT_OPTION_SETTINGS, array() );
		$ttl_min  = isset( $settings['cache_ttl'] ) ? (int) round( $settings['cache_ttl'] / MINUTE_IN_SECONDS ) : 60;

		// Edit context.
		$edit_id    = isset( $_GET['edit'] ) ? sanitize_text_field( wp_unslash( $_GET['edit'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$editing    = '' !== $edit_id ? TT_Tokens::get( $edit_id ) : null;
		$adding_new = isset( $_GET['add'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$show_form  = $editing || $adding_new;

		$notice = get_transient( 'tt_notice_' . get_current_user_id() );
		if ( $notice ) {
			delete_transient( 'tt_notice_' . get_current_user_id() );
		}
		?>
		<div class="wrap tt-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Text Tokens', 'text-tokens' ); ?></h1>
			<?php if ( ! $show_form ) : ?>
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=' . self::MENU_SLUG . '&add=1' ) ); ?>" class="page-title-action">
					<?php esc_html_e( 'Add New Token', 'text-tokens' ); ?>
				</a>
			<?php endif; ?>
			<hr class="wp-header-end">

			<p class="tt-intro">
				<?php esc_html_e( 'Define placeholder tokens that are automatically replaced with real values wherever text appears on your site. Type a code such as SCHOOL-YEAR (brackets are added for you) and use [SCHOOL-YEAR] in your content.', 'text-tokens' ); ?>
			</p>

			<?php if ( $notice && is_array( $notice ) ) : ?>
				<div class="notice notice-<?php echo 'error' === $notice['type'] ? 'error' : 'success'; ?> is-dismissible" role="alert">
					<p><?php echo esc_html( $notice['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<?php
			if ( $show_form ) {
				$this->render_form( $editing );
			} else {
				$this->render_table( $tokens );
				$this->render_settings_form( $ttl_min );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render the main tokens table.
	 *
	 * @param array $tokens Token list.
	 * @return void
	 */
	private function render_table( $tokens ) {
		?>
		<h2 class="screen-reader-text"><?php esc_html_e( 'Defined tokens', 'text-tokens' ); ?></h2>
		<div aria-live="polite" class="screen-reader-text" id="tt-live-region"></div>
		<table class="widefat striped tt-table">
			<caption class="screen-reader-text"><?php esc_html_e( 'List of defined text tokens and their resolved values.', 'text-tokens' ); ?></caption>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Code', 'text-tokens' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Type', 'text-tokens' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Value / Rule', 'text-tokens' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Current Preview', 'text-tokens' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Description', 'text-tokens' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'text-tokens' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $tokens ) ) : ?>
					<tr>
						<td colspan="6"><?php esc_html_e( 'No tokens defined yet. Choose “Add New Token” to create one.', 'text-tokens' ); ?></td>
					</tr>
				<?php else : ?>
					<?php
					$definitions = TT_Rules::definitions();
					foreach ( $tokens as $token ) :
						$code    = isset( $token['code'] ) ? $token['code'] : '';
						$type    = isset( $token['type'] ) ? $token['type'] : 'static';
						$preview = TT_Resolver::resolve_code( $code, true );
						$valid   = null !== $preview && '' !== $preview;

						if ( 'dynamic' === $type ) {
							$rule_label = isset( $definitions[ $token['rule'] ]['label'] ) ? $definitions[ $token['rule'] ]['label'] : $token['rule'];
							$value_desc = $rule_label;
						} else {
							$value_desc = isset( $token['value'] ) ? wp_strip_all_tags( $token['value'] ) : '';
						}

						$edit_url   = admin_url( 'options-general.php?page=' . self::MENU_SLUG . '&edit=' . rawurlencode( $token['id'] ) );
						$delete_url = wp_nonce_url(
							admin_url( 'admin-post.php?action=tt_delete_token&token_id=' . rawurlencode( $token['id'] ) ),
							'tt_delete_' . $token['id']
						);
						?>
						<tr>
							<th scope="row"><code>[<?php echo esc_html( $code ); ?>]</code></th>
							<td>
								<?php echo 'dynamic' === $type ? esc_html__( 'Dynamic Rule', 'text-tokens' ) : esc_html__( 'Static Text', 'text-tokens' ); ?>
							</td>
							<td><?php echo esc_html( $value_desc ); ?></td>
							<td>
								<?php if ( $valid ) : ?>
									<span class="tt-status tt-status--ok">
										<span class="tt-status__icon" aria-hidden="true">&#10003;</span>
										<span class="tt-status__text"><?php echo esc_html( wp_strip_all_tags( $preview ) ); ?></span>
									</span>
								<?php else : ?>
									<span class="tt-status tt-status--warn">
										<span class="tt-status__icon" aria-hidden="true">&#9888;</span>
										<span class="tt-status__text"><?php esc_html_e( 'No value — check configuration', 'text-tokens' ); ?></span>
									</span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( isset( $token['description'] ) ? $token['description'] : '' ); ?></td>
							<td class="tt-row-actions">
								<a class="button button-small" href="<?php echo esc_url( $edit_url ); ?>">
									<?php esc_html_e( 'Edit', 'text-tokens' ); ?>
									<span class="screen-reader-text"><?php echo esc_html( sprintf( /* translators: %s: token code. */ __( '%s token', 'text-tokens' ), $code ) ); ?></span>
								</a>
								<a class="button button-small button-link-delete" href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this token? This cannot be undone.', 'text-tokens' ) ); ?>');">
									<?php esc_html_e( 'Delete', 'text-tokens' ); ?>
									<span class="screen-reader-text"><?php echo esc_html( sprintf( /* translators: %s: token code. */ __( '%s token', 'text-tokens' ), $code ) ); ?></span>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render the add/edit form for a single token.
	 *
	 * @param array|null $token Token being edited, or null when adding.
	 * @return void
	 */
	private function render_form( $token ) {
		$is_edit = is_array( $token );
		$code    = $is_edit && isset( $token['code'] ) ? $token['code'] : '';
		$type    = $is_edit && isset( $token['type'] ) ? $token['type'] : 'static';
		$value   = $is_edit && isset( $token['value'] ) ? $token['value'] : '';
		$rule    = $is_edit && isset( $token['rule'] ) ? $token['rule'] : '';
		$desc    = $is_edit && isset( $token['description'] ) ? $token['description'] : '';
		$config  = $is_edit && isset( $token['config'] ) && is_array( $token['config'] ) ? $token['config'] : array();
		$id      = $is_edit && isset( $token['id'] ) ? $token['id'] : '';

		$definitions = TT_Rules::definitions();
		$back_url    = admin_url( 'options-general.php?page=' . self::MENU_SLUG );
		?>
		<h2><?php echo $is_edit ? esc_html__( 'Edit Token', 'text-tokens' ) : esc_html__( 'Add New Token', 'text-tokens' ); ?></h2>
		<div aria-live="polite" class="screen-reader-text" id="tt-live-region"></div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tt-form">
			<input type="hidden" name="action" value="tt_save_token">
			<input type="hidden" name="token_id" value="<?php echo esc_attr( $id ); ?>">
			<?php wp_nonce_field( self::NONCE ); ?>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<label for="tt-code"><?php esc_html_e( 'Code', 'text-tokens' ); ?></label>
						</th>
						<td>
							<span class="tt-code-wrap">
								<span class="tt-bracket" aria-hidden="true">[</span>
								<input name="code" id="tt-code" type="text" class="regular-text" value="<?php echo esc_attr( $code ); ?>" required
									pattern="[A-Za-z0-9 _\-]+" aria-describedby="tt-code-help">
								<span class="tt-bracket" aria-hidden="true">]</span>
							</span>
							<p class="description" id="tt-code-help">
								<?php esc_html_e( 'Type only the inner text; the brackets are added automatically. Matching is case-insensitive and stored uppercase. Letters, numbers, spaces, hyphens and underscores only.', 'text-tokens' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="tt-type"><?php esc_html_e( 'Type', 'text-tokens' ); ?></label>
						</th>
						<td>
							<select name="type" id="tt-type" aria-describedby="tt-type-help">
								<option value="static" <?php selected( $type, 'static' ); ?>><?php esc_html_e( 'Static Text', 'text-tokens' ); ?></option>
								<option value="dynamic" <?php selected( $type, 'dynamic' ); ?>><?php esc_html_e( 'Dynamic Rule', 'text-tokens' ); ?></option>
							</select>
							<p class="description" id="tt-type-help"><?php esc_html_e( 'Static text is replaced verbatim. A dynamic rule calculates its value automatically.', 'text-tokens' ); ?></p>
						</td>
					</tr>

					<tr class="tt-field-static" <?php echo 'static' === $type ? '' : 'style="display:none"'; ?>>
						<th scope="row">
							<label for="tt-value"><?php esc_html_e( 'Static value', 'text-tokens' ); ?></label>
						</th>
						<td>
							<input name="value" id="tt-value" type="text" class="large-text" value="<?php echo esc_attr( $value ); ?>">
							<p class="description"><?php esc_html_e( 'The text this token is replaced with.', 'text-tokens' ); ?></p>
						</td>
					</tr>

					<tr class="tt-field-dynamic" <?php echo 'dynamic' === $type ? '' : 'style="display:none"'; ?>>
						<th scope="row">
							<label for="tt-rule"><?php esc_html_e( 'Dynamic rule', 'text-tokens' ); ?></label>
						</th>
						<td>
							<select name="rule" id="tt-rule">
								<option value=""><?php esc_html_e( '— Select a rule —', 'text-tokens' ); ?></option>
								<?php foreach ( $definitions as $slug => $def ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $rule, $slug ); ?>>
										<?php echo esc_html( $def['label'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description tt-rule-desc"></p>

							<?php foreach ( $definitions as $slug => $def ) : ?>
								<?php if ( empty( $def['fields'] ) ) { continue; } ?>
								<fieldset class="tt-rule-config" data-rule="<?php echo esc_attr( $slug ); ?>" <?php echo $rule === $slug ? '' : 'style="display:none"'; ?>>
									<legend><?php echo esc_html( sprintf( /* translators: %s: rule name. */ __( '%s options', 'text-tokens' ), $def['label'] ) ); ?></legend>
									<?php foreach ( $def['fields'] as $fslug => $field ) : ?>
										<?php
										$field_id  = 'tt-config-' . $slug . '-' . $fslug;
										$field_val = isset( $config[ $fslug ] ) ? $config[ $fslug ] : ( isset( $field['default'] ) ? $field['default'] : '' );
										$ftype     = isset( $field['type'] ) ? $field['type'] : 'text';
										?>
										<p class="tt-config-field">
											<label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $field['label'] ); ?></label><br>
											<?php if ( 'select' === $ftype ) : ?>
												<select name="config[<?php echo esc_attr( $fslug ); ?>]" id="<?php echo esc_attr( $field_id ); ?>">
													<?php foreach ( $field['options'] as $okey => $olabel ) : ?>
														<option value="<?php echo esc_attr( $okey ); ?>" <?php selected( (string) $field_val, (string) $okey ); ?>><?php echo esc_html( $olabel ); ?></option>
													<?php endforeach; ?>
												</select>
											<?php elseif ( 'date' === $ftype ) : ?>
												<input type="date" name="config[<?php echo esc_attr( $fslug ); ?>]" id="<?php echo esc_attr( $field_id ); ?>" value="<?php echo esc_attr( $field_val ); ?>">
											<?php else : ?>
												<input type="text" name="config[<?php echo esc_attr( $fslug ); ?>]" id="<?php echo esc_attr( $field_id ); ?>" value="<?php echo esc_attr( $field_val ); ?>" class="regular-text">
											<?php endif; ?>
											<?php if ( ! empty( $field['help'] ) ) : ?>
												<span class="description"><?php echo esc_html( $field['help'] ); ?></span>
											<?php endif; ?>
										</p>
									<?php endforeach; ?>
								</fieldset>
							<?php endforeach; ?>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Current preview', 'text-tokens' ); ?></th>
						<td>
							<span class="tt-status tt-status--ok tt-preview-box" id="tt-preview" aria-live="polite">
								<span class="tt-status__text"><?php esc_html_e( 'Save or change a field to preview.', 'text-tokens' ); ?></span>
							</span>
							<button type="button" class="button button-secondary" id="tt-preview-btn"><?php esc_html_e( 'Refresh preview', 'text-tokens' ); ?></button>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="tt-description"><?php esc_html_e( 'Description / Notes', 'text-tokens' ); ?></label>
						</th>
						<td>
							<textarea name="description" id="tt-description" rows="2" class="large-text"><?php echo esc_textarea( $desc ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Optional note for other editors, e.g. “Use in header for current enrollment year.”', 'text-tokens' ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php echo $is_edit ? esc_html__( 'Update Token', 'text-tokens' ) : esc_html__( 'Add Token', 'text-tokens' ); ?></button>
				<a href="<?php echo esc_url( $back_url ); ?>" class="button button-secondary"><?php esc_html_e( 'Cancel', 'text-tokens' ); ?></a>
			</p>
		</form>
		<?php
	}

	/**
	 * Render the global settings (cache) form.
	 *
	 * @param int $ttl_min Current cache TTL in minutes.
	 * @return void
	 */
	private function render_settings_form( $ttl_min ) {
		?>
		<h2><?php esc_html_e( 'Performance', 'text-tokens' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tt-settings-form">
			<input type="hidden" name="action" value="tt_save_settings">
			<?php wp_nonce_field( 'tt_save_settings' ); ?>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<label for="tt-cache-ttl"><?php esc_html_e( 'Cache resolved values for', 'text-tokens' ); ?></label>
						</th>
						<td>
							<input type="number" name="cache_ttl_minutes" id="tt-cache-ttl" min="0" max="1440" step="1" value="<?php echo esc_attr( $ttl_min ); ?>" class="small-text">
							<?php esc_html_e( 'minutes', 'text-tokens' ); ?>
							<p class="description"><?php esc_html_e( 'Dynamic values (year, date, school year) change at most once a day, so caching avoids recalculating on every page load. Set to 0 to disable caching. Saving here clears the current cache.', 'text-tokens' ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>
			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'text-tokens' ); ?></button>
			</p>
		</form>
		<?php
	}
}
