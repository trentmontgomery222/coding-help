<?php
/**
 * Admin UI: menu, add/edit form, settings, and action handling.
 *
 * @package ACPS_Link_Shortener
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin controller.
 */
class ACPS_LS_Admin {

	const MENU_SLUG     = 'acps-link-shortener';
	const SETTINGS_SLUG = 'acps-link-shortener-settings';

	/**
	 * Notices to render, [type => [messages]].
	 *
	 * @var array
	 */
	private $notices = array();

	/**
	 * Hook everything.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Page URL for the main list screen.
	 *
	 * @return string
	 */
	private function page_url() {
		return admin_url( 'admin.php?page=' . self::MENU_SLUG );
	}

	/**
	 * Register the admin menu.
	 */
	public function add_menu() {
		$cap = acps_ls_manage_capability();

		add_menu_page(
			__( 'Link Shortener', 'acps-link-shortener' ),
			__( 'Link Shortener', 'acps-link-shortener' ),
			$cap,
			self::MENU_SLUG,
			array( $this, 'render_list_page' ),
			'dashicons-admin-links',
			30
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'All Links', 'acps-link-shortener' ),
			__( 'All Links', 'acps-link-shortener' ),
			$cap,
			self::MENU_SLUG,
			array( $this, 'render_list_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Add New Link', 'acps-link-shortener' ),
			__( 'Add New Link', 'acps-link-shortener' ),
			$cap,
			self::MENU_SLUG . '-add',
			array( $this, 'render_form_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'acps-link-shortener' ),
			__( 'Settings', 'acps-link-shortener' ),
			$cap,
			self::SETTINGS_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Enqueue admin CSS/JS only on our screens.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, self::MENU_SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'acps-ls-admin',
			ACPS_LS_URL . 'admin/css/admin.css',
			array(),
			ACPS_LS_VERSION
		);

		wp_enqueue_script(
			'acps-ls-admin',
			ACPS_LS_URL . 'admin/js/admin.js',
			array(),
			ACPS_LS_VERSION,
			true
		);

		wp_localize_script(
			'acps-ls-admin',
			'acpsLsL10n',
			array(
				'copied'     => __( 'Short URL copied to clipboard.', 'acps-link-shortener' ),
				'copyFailed' => __( 'Could not copy. Press Ctrl+C or Cmd+C to copy.', 'acps-link-shortener' ),
			)
		);
	}

	/* --------------------------------------------------------------------- */
	/* Action handling                                                        */
	/* --------------------------------------------------------------------- */

	/**
	 * Route non-render actions (save, delete, toggle, settings save).
	 */
	public function handle_actions() {
		if ( ! isset( $_GET['page'] ) ) {
			return;
		}

		$page = sanitize_key( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 0 !== strpos( $page, self::MENU_SLUG ) ) {
			return;
		}

		if ( ! current_user_can( acps_ls_manage_capability() ) ) {
			return;
		}

		// Save (add/edit) form submission.
		if ( isset( $_POST['acps_ls_save'] ) ) {
			$this->handle_save();
			return;
		}

		// Settings submission.
		if ( isset( $_POST['acps_ls_save_settings'] ) ) {
			$this->handle_settings_save();
			return;
		}

		// Row actions via GET.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		$id     = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;

		if ( ! $id ) {
			return;
		}

		switch ( $action ) {
			case 'delete':
				$this->handle_delete( $id );
				break;
			case 'activate':
			case 'deactivate':
				$this->handle_toggle( $id, 'activate' === $action );
				break;
		}
	}

	/**
	 * Handle add/edit save.
	 */
	private function handle_save() {
		check_admin_referer( 'acps_ls_save_link', 'acps_ls_nonce' );

		if ( ! current_user_can( acps_ls_manage_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to manage links.', 'acps-link-shortener' ) );
		}

		$id            = isset( $_POST['link_id'] ) ? absint( wp_unslash( $_POST['link_id'] ) ) : 0;
		$slug          = isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : '';
		$raw_dest      = isset( $_POST['destination'] ) ? wp_unslash( $_POST['destination'] ) : '';
		$title         = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$redirect_type = isset( $_POST['redirect_type'] ) ? absint( wp_unslash( $_POST['redirect_type'] ) ) : 301;
		$is_active     = isset( $_POST['is_active'] ) ? 1 : 0;

		$errors = array();

		$slug_check = ACPS_LS_DB::validate_slug( $slug, $id );
		if ( is_wp_error( $slug_check ) ) {
			$errors['slug'] = $slug_check->get_error_message();
		}

		$destination = ACPS_LS_DB::validate_destination( $raw_dest );
		if ( is_wp_error( $destination ) ) {
			$errors['destination'] = $destination->get_error_message();
		}

		if ( $errors ) {
			// Re-render the form with errors + the user's values (transient carry-over).
			set_transient(
				'acps_ls_form_errors_' . get_current_user_id(),
				array(
					'errors' => $errors,
					'values' => array(
						'link_id'       => $id,
						'slug'          => $slug,
						'destination'   => $raw_dest,
						'title'         => $title,
						'redirect_type' => $redirect_type,
						'is_active'     => $is_active,
					),
				),
				60
			);

			$redirect = add_query_arg(
				array_filter(
					array(
						'page'   => $id ? self::MENU_SLUG : self::MENU_SLUG . '-add',
						'action' => $id ? 'edit' : false,
						'id'     => $id ? $id : false,
					)
				),
				admin_url( 'admin.php' )
			);
			wp_safe_redirect( $redirect );
			exit;
		}

		$data = array(
			'slug'          => $slug,
			'destination'   => $destination,
			'title'         => $title,
			'redirect_type' => $redirect_type,
			'is_active'     => $is_active,
		);

		if ( $id ) {
			ACPS_LS_DB::update( $id, $data );
			$notice = 'updated';
		} else {
			$data['source'] = 'manual';
			ACPS_LS_DB::create( $data );
			$notice = 'created';
		}

		wp_safe_redirect( add_query_arg( 'acps_ls_notice', $notice, $this->page_url() ) );
		exit;
	}

	/**
	 * Handle delete.
	 *
	 * @param int $id Row id.
	 */
	private function handle_delete( $id ) {
		check_admin_referer( 'acps_ls_delete_' . $id );
		ACPS_LS_DB::delete( $id );
		wp_safe_redirect( add_query_arg( 'acps_ls_notice', 'deleted', $this->page_url() ) );
		exit;
	}

	/**
	 * Handle activate/deactivate.
	 *
	 * @param int  $id     Row id.
	 * @param bool $active Target state.
	 */
	private function handle_toggle( $id, $active ) {
		$action = $active ? 'activate' : 'deactivate';
		check_admin_referer( 'acps_ls_' . $action . '_' . $id );
		ACPS_LS_DB::update( $id, array( 'is_active' => $active ? 1 : 0 ) );
		wp_safe_redirect( add_query_arg( 'acps_ls_notice', $active ? 'activated' : 'deactivated', $this->page_url() ) );
		exit;
	}

	/**
	 * Handle settings save.
	 */
	private function handle_settings_save() {
		check_admin_referer( 'acps_ls_settings', 'acps_ls_settings_nonce' );

		if ( ! current_user_can( acps_ls_manage_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to change settings.', 'acps-link-shortener' ) );
		}

		$settings = array(
			'sync_enabled' => isset( $_POST['sync_enabled'] ) ? 1 : 0,
			'sheet_url'    => isset( $_POST['sheet_url'] ) ? esc_url_raw( wp_unslash( $_POST['sheet_url'] ), array( 'https' ) ) : '',
			'sheet_secret' => isset( $_POST['sheet_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['sheet_secret'] ) ) : '',
			'default_type' => ( isset( $_POST['default_type'] ) && 302 === absint( wp_unslash( $_POST['default_type'] ) ) ) ? 302 : 301,
		);

		update_option( ACPS_LS_OPT_SETTINGS, $settings );

		// Make sure the sync event exists when enabling.
		if ( $settings['sync_enabled'] && ! wp_next_scheduled( ACPS_LS_CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, ACPS_LS_CRON_INTERVAL, ACPS_LS_CRON_HOOK );
		}

		wp_safe_redirect( add_query_arg( 'acps_ls_notice', 'settings', admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) ) );
		exit;
	}

	/* --------------------------------------------------------------------- */
	/* Rendering                                                              */
	/* --------------------------------------------------------------------- */

	/**
	 * Print a queued notice bar based on ?acps_ls_notice=.
	 */
	private function render_notice_from_query() {
		if ( ! isset( $_GET['acps_ls_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$map = array(
			'created'     => __( 'Link created.', 'acps-link-shortener' ),
			'updated'     => __( 'Link updated.', 'acps-link-shortener' ),
			'deleted'     => __( 'Link deleted.', 'acps-link-shortener' ),
			'activated'   => __( 'Link activated.', 'acps-link-shortener' ),
			'deactivated' => __( 'Link deactivated.', 'acps-link-shortener' ),
			'settings'    => __( 'Settings saved.', 'acps-link-shortener' ),
		);

		$key = sanitize_key( wp_unslash( $_GET['acps_ls_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $map[ $key ] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible" role="status"><p>%s</p></div>',
				esc_html( $map[ $key ] )
			);
		}
	}

	/**
	 * Render the list screen.
	 */
	public function render_list_page() {
		if ( ! current_user_can( acps_ls_manage_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'acps-link-shortener' ) );
		}

		// Editing? Route to the form instead.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'edit' === $action ) {
			$this->render_form_page();
			return;
		}

		require_once ACPS_LS_PATH . 'includes/class-acps-ls-list-table.php';
		$table = new ACPS_LS_List_Table( $this->page_url() );
		$table->prepare_items();

		$add_url = admin_url( 'admin.php?page=' . self::MENU_SLUG . '-add' );
		?>
		<div class="wrap acps-ls-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Link Shortener', 'acps-link-shortener' ); ?></h1>
			<a href="<?php echo esc_url( $add_url ); ?>" class="page-title-action"><?php esc_html_e( 'Add New Link', 'acps-link-shortener' ); ?></a>
			<hr class="wp-header-end" />

			<?php $this->render_notice_from_query(); ?>

			<p class="description">
				<?php
				printf(
					/* translators: %s: short-link base such as acpsmd.org/link/. */
					esc_html__( 'Short links are served from %s and shared across the whole network.', 'acps-link-shortener' ),
					'<code>' . esc_html( home_url( '/' . ACPS_LS_SLUG_PREFIX . '/' ) ) . '</code>'
				);
				?>
			</p>

			<!-- ARIA live region: copy-to-clipboard success is announced here. -->
			<div id="acps-ls-live" class="screen-reader-text" role="status" aria-live="polite"></div>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
				<?php
				$table->search_box( __( 'Search links', 'acps-link-shortener' ), 'acps-ls-search' );
				$table->display();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the add/edit form.
	 */
	public function render_form_page() {
		if ( ! current_user_can( acps_ls_manage_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'acps-link-shortener' ) );
		}

		$id   = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$link = $id ? ACPS_LS_DB::get( $id ) : null;

		// Defaults / current values.
		$values = array(
			'link_id'       => $id,
			'slug'          => $link ? $link->slug : '',
			'destination'   => $link ? $link->destination : '',
			'title'         => $link ? $link->title : '',
			'redirect_type' => $link ? (int) $link->redirect_type : 301,
			'is_active'     => $link ? (int) $link->is_active : 1,
		);

		// Carry over failed-submission values + errors, if any.
		$errors    = array();
		$transient = get_transient( 'acps_ls_form_errors_' . get_current_user_id() );
		if ( $transient ) {
			delete_transient( 'acps_ls_form_errors_' . get_current_user_id() );
			$errors = $transient['errors'];
			$values = array_merge( $values, $transient['values'] );
		}

		$is_edit    = (bool) $id;
		$page_title = $is_edit ? __( 'Edit Link', 'acps-link-shortener' ) : __( 'Add New Link', 'acps-link-shortener' );
		$prefix_url = home_url( '/' . ACPS_LS_SLUG_PREFIX . '/' );
		?>
		<div class="wrap acps-ls-wrap">
			<h1><?php echo esc_html( $page_title ); ?></h1>

			<?php if ( $errors ) : ?>
				<div class="notice notice-error" role="alert">
					<p><?php esc_html_e( 'Please correct the errors below.', 'acps-link-shortener' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>" class="acps-ls-form">
				<?php wp_nonce_field( 'acps_ls_save_link', 'acps_ls_nonce' ); ?>
				<input type="hidden" name="link_id" value="<?php echo esc_attr( $values['link_id'] ); ?>" />

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="acps-ls-title"><?php esc_html_e( 'Title', 'acps-link-shortener' ); ?></label>
							</th>
							<td>
								<input name="title" id="acps-ls-title" type="text" class="regular-text"
									value="<?php echo esc_attr( $values['title'] ); ?>" />
								<p class="description" id="acps-ls-title-desc"><?php esc_html_e( 'A human-friendly label used only in this admin list.', 'acps-link-shortener' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="acps-ls-slug"><?php esc_html_e( 'Slug (shortened link name)', 'acps-link-shortener' ); ?> <span class="acps-ls-required" aria-hidden="true">*</span></label>
							</th>
							<td>
								<span class="acps-ls-prefix"><?php echo esc_html( $prefix_url ); ?></span><input
									name="slug" id="acps-ls-slug" type="text" class="regular-text" required
									value="<?php echo esc_attr( $values['slug'] ); ?>"
									aria-describedby="acps-ls-slug-desc<?php echo isset( $errors['slug'] ) ? ' acps-ls-slug-error' : ''; ?>"
									<?php echo isset( $errors['slug'] ) ? 'aria-invalid="true"' : ''; ?> />
								<?php if ( isset( $errors['slug'] ) ) : ?>
									<p class="acps-ls-field-error" id="acps-ls-slug-error" role="alert"><?php echo esc_html( $errors['slug'] ); ?></p>
								<?php endif; ?>
								<p class="description" id="acps-ls-slug-desc"><?php esc_html_e( 'Lowercase letters, numbers and hyphens. Must be unique.', 'acps-link-shortener' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="acps-ls-destination"><?php esc_html_e( 'Destination URL', 'acps-link-shortener' ); ?> <span class="acps-ls-required" aria-hidden="true">*</span></label>
							</th>
							<td>
								<input name="destination" id="acps-ls-destination" type="url" class="large-text" required
									placeholder="https://example.com/some/long/page"
									value="<?php echo esc_attr( $values['destination'] ); ?>"
									aria-describedby="acps-ls-destination-desc<?php echo isset( $errors['destination'] ) ? ' acps-ls-destination-error' : ''; ?>"
									<?php echo isset( $errors['destination'] ) ? 'aria-invalid="true"' : ''; ?> />
								<?php if ( isset( $errors['destination'] ) ) : ?>
									<p class="acps-ls-field-error" id="acps-ls-destination-error" role="alert"><?php echo esc_html( $errors['destination'] ); ?></p>
								<?php endif; ?>
								<p class="description" id="acps-ls-destination-desc"><?php esc_html_e( 'The real http/https URL visitors are sent to.', 'acps-link-shortener' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'Redirect type', 'acps-link-shortener' ); ?></th>
							<td>
								<fieldset>
									<legend class="screen-reader-text"><?php esc_html_e( 'Redirect type', 'acps-link-shortener' ); ?></legend>
									<label for="acps-ls-type-301">
										<input type="radio" name="redirect_type" id="acps-ls-type-301" value="301" <?php checked( 301, $values['redirect_type'] ); ?> />
										<?php esc_html_e( '301 — Permanent (best for SEO; may be cached at the edge)', 'acps-link-shortener' ); ?>
									</label><br />
									<label for="acps-ls-type-302">
										<input type="radio" name="redirect_type" id="acps-ls-type-302" value="302" <?php checked( 302, $values['redirect_type'] ); ?> />
										<?php esc_html_e( '302 — Temporary (edits take effect immediately)', 'acps-link-shortener' ); ?>
									</label>
								</fieldset>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'Status', 'acps-link-shortener' ); ?></th>
							<td>
								<label for="acps-ls-active">
									<input type="checkbox" name="is_active" id="acps-ls-active" value="1" <?php checked( 1, $values['is_active'] ); ?> />
									<?php esc_html_e( 'Active (uncheck to disable this link without deleting it)', 'acps-link-shortener' ); ?>
								</label>
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button( $is_edit ? __( 'Update Link', 'acps-link-shortener' ) : __( 'Create Link', 'acps-link-shortener' ), 'primary', 'acps_ls_save' ); ?>
				<a href="<?php echo esc_url( $this->page_url() ); ?>" class="button button-secondary"><?php esc_html_e( 'Cancel', 'acps-link-shortener' ); ?></a>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the settings screen (Sheet sync configuration).
	 */
	public function render_settings_page() {
		if ( ! current_user_can( acps_ls_manage_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'acps-link-shortener' ) );
		}

		$defaults = array(
			'sync_enabled' => 0,
			'sheet_url'    => '',
			'sheet_secret' => '',
			'default_type' => 301,
		);
		$settings = wp_parse_args( get_option( ACPS_LS_OPT_SETTINGS, array() ), $defaults );
		$last     = get_option( 'acps_ls_last_sync' );
		$next     = wp_next_scheduled( ACPS_LS_CRON_HOOK );
		?>
		<div class="wrap acps-ls-wrap">
			<h1><?php esc_html_e( 'Link Shortener Settings', 'acps-link-shortener' ); ?></h1>

			<?php $this->render_notice_from_query(); ?>

			<h2><?php esc_html_e( 'Google Sheet sync', 'acps-link-shortener' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Every 3 minutes the plugin fetches rows from a Google Apps Script web app and creates a short link for each new row. The slug (shortened link name) comes from the sheet, so it is fully customizable per row. See the bundled google-apps-script/Code.gs for the endpoint to deploy.', 'acps-link-shortener' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) ); ?>">
				<?php wp_nonce_field( 'acps_ls_settings', 'acps_ls_settings_nonce' ); ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Enable sync', 'acps-link-shortener' ); ?></th>
							<td>
								<label for="acps-ls-sync-enabled">
									<input type="checkbox" name="sync_enabled" id="acps-ls-sync-enabled" value="1" <?php checked( 1, $settings['sync_enabled'] ); ?> />
									<?php esc_html_e( 'Automatically import new rows from the Google Sheet every 3 minutes.', 'acps-link-shortener' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="acps-ls-sheet-url"><?php esc_html_e( 'Web app URL', 'acps-link-shortener' ); ?></label>
							</th>
							<td>
								<input type="url" name="sheet_url" id="acps-ls-sheet-url" class="large-text"
									value="<?php echo esc_attr( $settings['sheet_url'] ); ?>"
									placeholder="https://script.google.com/macros/s/…/exec"
									aria-describedby="acps-ls-sheet-url-desc" />
								<p class="description" id="acps-ls-sheet-url-desc"><?php esc_html_e( 'The deployed Apps Script web app that returns your sheet as JSON (https only).', 'acps-link-shortener' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="acps-ls-sheet-secret"><?php esc_html_e( 'Shared secret (optional)', 'acps-link-shortener' ); ?></label>
							</th>
							<td>
								<input type="text" name="sheet_secret" id="acps-ls-sheet-secret" class="regular-text"
									value="<?php echo esc_attr( $settings['sheet_secret'] ); ?>"
									autocomplete="off" aria-describedby="acps-ls-sheet-secret-desc" />
								<p class="description" id="acps-ls-sheet-secret-desc"><?php esc_html_e( 'If set, it is sent as a token so only this site can read the feed. Must match the SECRET in Code.gs.', 'acps-link-shortener' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Default redirect type', 'acps-link-shortener' ); ?></th>
							<td>
								<fieldset>
									<legend class="screen-reader-text"><?php esc_html_e( 'Default redirect type for synced links', 'acps-link-shortener' ); ?></legend>
									<label for="acps-ls-default-301"><input type="radio" name="default_type" id="acps-ls-default-301" value="301" <?php checked( 301, (int) $settings['default_type'] ); ?> /> <?php esc_html_e( '301 Permanent', 'acps-link-shortener' ); ?></label><br />
									<label for="acps-ls-default-302"><input type="radio" name="default_type" id="acps-ls-default-302" value="302" <?php checked( 302, (int) $settings['default_type'] ); ?> /> <?php esc_html_e( '302 Temporary', 'acps-link-shortener' ); ?></label>
								</fieldset>
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button( __( 'Save Settings', 'acps-link-shortener' ), 'primary', 'acps_ls_save_settings' ); ?>
			</form>

			<h2><?php esc_html_e( 'Sync status', 'acps-link-shortener' ); ?></h2>
			<p>
				<?php
				if ( $next ) {
					printf(
						/* translators: %s: human-readable time. */
						esc_html__( 'Next scheduled sync: %s', 'acps-link-shortener' ),
						esc_html( mysql2date( 'Y-m-d H:i:s', gmdate( 'Y-m-d H:i:s', $next ) ) )
					);
				} else {
					esc_html_e( 'No sync currently scheduled.', 'acps-link-shortener' );
				}
				?>
			</p>
			<?php if ( is_array( $last ) ) : ?>
				<p>
					<?php
					printf(
						/* translators: 1: datetime, 2: created count, 3: skipped count, 4: error count. */
						esc_html__( 'Last run %1$s — created %2$d, skipped %3$d, errors %4$d.', 'acps-link-shortener' ),
						esc_html( $last['time'] ),
						(int) $last['created'],
						(int) $last['skipped'],
						(int) $last['errors']
					);
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}
}
