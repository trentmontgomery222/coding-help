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
	 * URL of the settings screen (now under the core Settings menu).
	 *
	 * @return string
	 */
	private function settings_url() {
		return admin_url( 'options-general.php?page=' . self::SETTINGS_SLUG );
	}

	/**
	 * Register the admin menu.
	 *
	 * The top-level "Link Shortener" menu (All Links + Add New Link) stays put.
	 * Settings live under wp-admin -> Settings -> Link Shortener.
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

		// Settings moved to the core Settings menu.
		add_options_page(
			__( 'Link Shortener', 'acps-link-shortener' ),
			__( 'Link Shortener', 'acps-link-shortener' ),
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

		// "Test connection" button (checked before the save handler).
		if ( isset( $_POST['acps_ls_test_sync'] ) ) {
			$this->handle_sync_test();
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
		$title         = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$redirect_type = isset( $_POST['redirect_type'] ) ? absint( wp_unslash( $_POST['redirect_type'] ) ) : 302;
		$is_active     = isset( $_POST['is_active'] ) ? 1 : 0;

		// Permanent (301) is disabled unless explicitly allowed: force 302 so a
		// tampered/disabled control can never store a permanent redirect.
		if ( ! acps_ls_allow_permanent() ) {
			$redirect_type = 302;
		}

		/*
		 * EDIT: the slug and destination are LOCKED once a link exists, so a
		 * short link can never be repointed to a different destination. Only the
		 * title, status, and (if allowed) redirect type can change.
		 */
		if ( $id ) {
			if ( ! ACPS_LS_DB::get( $id ) ) {
				wp_safe_redirect( add_query_arg( 'acps_ls_notice', 'deleted', $this->page_url() ) );
				exit;
			}
			ACPS_LS_DB::update(
				$id,
				array(
					'title'         => $title,
					'redirect_type' => $redirect_type,
					'is_active'     => $is_active,
				)
			);
			wp_safe_redirect( add_query_arg( 'acps_ls_notice', 'updated', $this->page_url() ) );
			exit;
		}

		// CREATE: validate slug + destination.
		$slug     = isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : '';
		$raw_dest = isset( $_POST['destination'] ) ? wp_unslash( $_POST['destination'] ) : '';
		$errors   = array();

		$slug_check = ACPS_LS_DB::validate_slug( $slug, 0 );
		if ( is_wp_error( $slug_check ) ) {
			$errors['slug'] = $slug_check->get_error_message();
		}

		$destination = ACPS_LS_DB::validate_destination( $raw_dest );
		if ( is_wp_error( $destination ) ) {
			$errors['destination'] = $destination->get_error_message();
		}

		if ( $errors ) {
			set_transient(
				'acps_ls_form_errors_' . get_current_user_id(),
				array(
					'errors' => $errors,
					'values' => array(
						'link_id'       => 0,
						'slug'          => $slug,
						'destination'   => $raw_dest,
						'title'         => $title,
						'redirect_type' => $redirect_type,
						'is_active'     => $is_active,
					),
				),
				60
			);
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '-add' ) );
			exit;
		}

		$current = wp_get_current_user();
		ACPS_LS_DB::create(
			array(
				'slug'          => $slug,
				'destination'   => $destination,
				'title'         => $title,
				'redirect_type' => $redirect_type,
				'is_active'     => $is_active,
				'source'        => 'manual',
				'creator_label' => $current ? $current->display_name : '',
			)
		);

		wp_safe_redirect( add_query_arg( 'acps_ls_notice', 'created', $this->page_url() ) );
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

		$existing = get_option( ACPS_LS_OPT_SETTINGS, array() );
		$existing = is_array( $existing ) ? $existing : array();

		// Custom short-link domain (optional).
		$link_domain = isset( $_POST['link_domain'] ) ? esc_url_raw( wp_unslash( $_POST['link_domain'] ) ) : '';

		// Google Sheet sync.
		$sync_enabled = isset( $_POST['sync_enabled'] ) ? 1 : 0;
		$sheet_url    = isset( $_POST['sheet_url'] ) ? esc_url_raw( wp_unslash( $_POST['sheet_url'] ), array( 'https' ) ) : '';
		$sheet_secret = isset( $_POST['sheet_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['sheet_secret'] ) ) : '';

		// People (name + password + optional per-user limit + namespace).
		// Passwords are hashed; a blank password on an existing person keeps theirs.
		$existing_people = array();
		foreach ( acps_ls_get_people() as $p ) {
			$existing_people[ strtolower( $p['label'] ) ] = $p['hash'];
		}

		$people = array();
		if ( isset( $_POST['person_label'] ) && is_array( $_POST['person_label'] ) ) {
			$labels     = wp_unslash( $_POST['person_label'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$passwords  = isset( $_POST['person_password'] ) ? wp_unslash( $_POST['person_password'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$maxes      = isset( $_POST['person_max'] ) ? wp_unslash( $_POST['person_max'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$namespaces = isset( $_POST['person_namespace'] ) ? wp_unslash( $_POST['person_namespace'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

			foreach ( $labels as $i => $raw_label ) {
				$label = sanitize_text_field( $raw_label );
				if ( '' === $label ) {
					continue; // Skip empty rows (also lets an admin delete a person).
				}
				$pw = isset( $passwords[ $i ] ) ? (string) $passwords[ $i ] : '';

				if ( '' !== $pw ) {
					$hash = wp_hash_password( $pw );
				} elseif ( isset( $existing_people[ strtolower( $label ) ] ) ) {
					$hash = $existing_people[ strtolower( $label ) ]; // Keep existing.
				} else {
					continue; // New person with no password: ignore.
				}

				$people[] = array(
					'label'     => $label,
					'hash'      => $hash,
					'max_links' => isset( $maxes[ $i ] ) ? max( 0, absint( $maxes[ $i ] ) ) : 0,
					'namespace' => isset( $namespaces[ $i ] ) ? sanitize_title( $namespaces[ $i ] ) : '',
				);
			}
		}

		$settings                 = $existing;
		$settings['link_domain']  = $link_domain;
		$settings['people']       = $people;
		$settings['sync_enabled'] = $sync_enabled;
		$settings['sheet_url']    = $sheet_url;
		$settings['sheet_secret'] = $sheet_secret;
		unset( $settings['default_type'] ); // obsolete

		update_option( ACPS_LS_OPT_SETTINGS, $settings );

		// Ensure the cron event exists when enabling.
		if ( $sync_enabled && ! wp_next_scheduled( ACPS_LS_CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, ACPS_LS_CRON_INTERVAL, ACPS_LS_CRON_HOOK );
		}

		wp_safe_redirect( add_query_arg( 'acps_ls_notice', 'settings', $this->settings_url() ) );
		exit;
	}

	/**
	 * Handle the "Test connection" button on the settings screen.
	 */
	private function handle_sync_test() {
		check_admin_referer( 'acps_ls_settings', 'acps_ls_settings_nonce' );

		if ( ! current_user_can( acps_ls_manage_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'acps-link-shortener' ) );
		}

		$sync   = new ACPS_LS_Sync();
		$test   = $sync->test_connection();
		$notice = $test['ok'] ? 'test_ok' : 'test_fail';

		set_transient( 'acps_ls_test_msg_' . get_current_user_id(), $test['message'], 60 );
		wp_safe_redirect( add_query_arg( 'acps_ls_notice', $notice, $this->settings_url() ) );
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

		// Connection-test results carry a stored message.
		if ( 'test_ok' === $key || 'test_fail' === $key ) {
			$msg = get_transient( 'acps_ls_test_msg_' . get_current_user_id() );
			delete_transient( 'acps_ls_test_msg_' . get_current_user_id() );
			printf(
				'<div class="notice %1$s is-dismissible" role="status"><p>%2$s</p></div>',
				'test_ok' === $key ? 'notice-success' : 'notice-error',
				esc_html( $msg ? $msg : __( 'Connection test finished.', 'acps-link-shortener' ) )
			);
			return;
		}

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
					esc_html__( 'Short links are served from %s.', 'acps-link-shortener' ),
					'<code>' . esc_html( acps_ls_link_base() . '/' . ( '' !== ACPS_LS_SLUG_PREFIX ? ACPS_LS_SLUG_PREFIX . '/' : '' ) ) . '</code>'
				);
				echo ' ';
				printf(
					/* translators: 1: shortcode, 2: settings URL. */
					wp_kses_post( __( 'Put %1$s on any page to let staff create links. Manage passwords and domain under %2$s.', 'acps-link-shortener' ) ),
					'<code>[acps_link_shortener]</code>',
					'<a href="' . esc_url( $this->settings_url() ) . '">' . esc_html__( 'Settings → Link Shortener', 'acps-link-shortener' ) . '</a>'
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
		$prefix_url = home_url( '/' . ( '' !== ACPS_LS_SLUG_PREFIX ? ACPS_LS_SLUG_PREFIX . '/' : '' ) );
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
									name="slug" id="acps-ls-slug" type="text" class="regular-text" <?php echo $is_edit ? 'readonly' : 'required'; ?>
									value="<?php echo esc_attr( $values['slug'] ); ?>"
									aria-describedby="acps-ls-slug-desc<?php echo isset( $errors['slug'] ) ? ' acps-ls-slug-error' : ''; ?>"
									<?php echo isset( $errors['slug'] ) ? 'aria-invalid="true"' : ''; ?> />
								<?php if ( isset( $errors['slug'] ) ) : ?>
									<p class="acps-ls-field-error" id="acps-ls-slug-error" role="alert"><?php echo esc_html( $errors['slug'] ); ?></p>
								<?php endif; ?>
								<p class="description" id="acps-ls-slug-desc">
									<?php
									echo $is_edit
										? esc_html__( 'Locked — the short name cannot be changed after creation.', 'acps-link-shortener' )
										: esc_html__( 'Lowercase letters, numbers and hyphens. Must be unique.', 'acps-link-shortener' );
									?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="acps-ls-destination"><?php esc_html_e( 'Destination URL', 'acps-link-shortener' ); ?> <span class="acps-ls-required" aria-hidden="true">*</span></label>
							</th>
							<td>
								<input name="destination" id="acps-ls-destination" type="url" class="large-text" <?php echo $is_edit ? 'readonly' : 'required'; ?>
									placeholder="https://example.com/some/long/page"
									value="<?php echo esc_attr( $values['destination'] ); ?>"
									aria-describedby="acps-ls-destination-desc<?php echo isset( $errors['destination'] ) ? ' acps-ls-destination-error' : ''; ?>"
									<?php echo isset( $errors['destination'] ) ? 'aria-invalid="true"' : ''; ?> />
								<?php if ( isset( $errors['destination'] ) ) : ?>
									<p class="acps-ls-field-error" id="acps-ls-destination-error" role="alert"><?php echo esc_html( $errors['destination'] ); ?></p>
								<?php endif; ?>
								<p class="description" id="acps-ls-destination-desc">
									<?php
									echo $is_edit
										? esc_html__( 'Locked — a short link cannot be repointed to a different destination. Delete and recreate if the target changed.', 'acps-link-shortener' )
										: esc_html__( 'The real http/https URL visitors are sent to.', 'acps-link-shortener' );
									?>
								</p>
							</td>
						</tr>

						<?php
						$allow_permanent = acps_ls_allow_permanent();
						// With permanent disabled, always present/force 302.
						$type_value = $allow_permanent ? $values['redirect_type'] : 302;
						?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Redirect type', 'acps-link-shortener' ); ?></th>
							<td>
								<fieldset>
									<legend class="screen-reader-text"><?php esc_html_e( 'Redirect type', 'acps-link-shortener' ); ?></legend>
									<label for="acps-ls-type-301" class="<?php echo $allow_permanent ? '' : 'acps-ls-disabled'; ?>">
										<input type="radio" name="redirect_type" id="acps-ls-type-301" value="301"
											<?php checked( 301, $type_value ); ?>
											<?php disabled( ! $allow_permanent ); ?>
											<?php echo $allow_permanent ? '' : 'aria-disabled="true"'; ?> />
										<?php esc_html_e( '301 — Permanent (best for SEO; may be cached at the edge)', 'acps-link-shortener' ); ?>
										<?php if ( ! $allow_permanent ) : ?>
											<span class="description">— <?php esc_html_e( 'disabled', 'acps-link-shortener' ); ?></span>
										<?php endif; ?>
									</label><br />
									<label for="acps-ls-type-302">
										<input type="radio" name="redirect_type" id="acps-ls-type-302" value="302" <?php checked( 302, $type_value ); ?> />
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
	 * Render the settings screen (short-link domain + front-end people).
	 */
	public function render_settings_page() {
		if ( ! current_user_can( acps_ls_manage_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'acps-link-shortener' ) );
		}

		$settings     = get_option( ACPS_LS_OPT_SETTINGS, array() );
		$settings     = is_array( $settings ) ? $settings : array();
		$link_domain  = ! empty( $settings['link_domain'] ) ? $settings['link_domain'] : '';
		$sync_enabled = ! empty( $settings['sync_enabled'] );
		$sheet_url    = ! empty( $settings['sheet_url'] ) ? $settings['sheet_url'] : '';
		$sheet_secret = ! empty( $settings['sheet_secret'] ) ? $settings['sheet_secret'] : '';
		$last_sync    = get_option( 'acps_ls_last_sync' );
		$people       = acps_ls_get_people();
		// Always render a few blank rows for adding new people.
		$blank = array( 'label' => '', 'max_links' => 0, 'namespace' => '' );
		$rows  = array_merge( $people, array( $blank, $blank, $blank ) );
		?>
		<div class="wrap acps-ls-wrap">
			<h1><?php esc_html_e( 'Link Shortener Settings', 'acps-link-shortener' ); ?></h1>

			<?php $this->render_notice_from_query(); ?>

			<form method="post" action="<?php echo esc_url( $this->settings_url() ); ?>">
				<?php wp_nonce_field( 'acps_ls_settings', 'acps_ls_settings_nonce' ); ?>

				<h2><?php esc_html_e( 'Short-link domain', 'acps-link-shortener' ); ?></h2>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="acps-ls-link-domain"><?php esc_html_e( 'Custom domain', 'acps-link-shortener' ); ?></label>
							</th>
							<td>
								<input type="url" name="link_domain" id="acps-ls-link-domain" class="regular-text"
									value="<?php echo esc_attr( $link_domain ); ?>"
									placeholder="https://go.acpsmd.org"
									aria-describedby="acps-ls-link-domain-desc" />
								<p class="description" id="acps-ls-link-domain-desc">
									<?php esc_html_e( 'Optional. The domain short links are built on. Leave blank to use this site’s own address. The domain must point to this WordPress install (DNS + WP Engine domain mapping) or the links will not resolve.', 'acps-link-shortener' ); ?>
									<br />
									<?php
									printf(
										/* translators: %s: current short-link base URL. */
										esc_html__( 'Current base: %s', 'acps-link-shortener' ),
										'<code>' . esc_html( trailingslashit( acps_ls_link_base() . ( '' !== ACPS_LS_SLUG_PREFIX ? '/' . ACPS_LS_SLUG_PREFIX : '' ) ) ) . '</code>'
									);
									?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Front-end users (shortcode access)', 'acps-link-shortener' ); ?></h2>
				<p class="description">
					<?php
					printf(
						/* translators: %s: the shortcode. */
						esc_html__( 'These people can create links from any page that contains the %s shortcode. Each has their own password. To change a password, type a new one; leave it blank to keep the current password. To remove someone, clear their name and save.', 'acps-link-shortener' ),
						'<code>[acps_link_shortener]</code>'
					);
					?>
				</p>

				<table class="widefat striped acps-ls-people" style="max-width:820px;">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Name', 'acps-link-shortener' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Password', 'acps-link-shortener' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Max links', 'acps-link-shortener' ); ?></th>
							<th scope="col"><?php esc_html_e( 'URL namespace', 'acps-link-shortener' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $i => $person ) : ?>
							<?php
							$has = ! empty( $person['label'] );
							$mx  = isset( $person['max_links'] ) ? (int) $person['max_links'] : 0;
							$ns  = isset( $person['namespace'] ) ? $person['namespace'] : '';
							?>
							<tr>
								<td>
									<label class="screen-reader-text" for="acps-ls-person-label-<?php echo (int) $i; ?>"><?php esc_html_e( 'Name', 'acps-link-shortener' ); ?></label>
									<input type="text" name="person_label[<?php echo (int) $i; ?>]" id="acps-ls-person-label-<?php echo (int) $i; ?>"
										value="<?php echo esc_attr( $has ? $person['label'] : '' ); ?>" autocomplete="off" />
								</td>
								<td>
									<label class="screen-reader-text" for="acps-ls-person-pw-<?php echo (int) $i; ?>"><?php esc_html_e( 'Password', 'acps-link-shortener' ); ?></label>
									<input type="text" name="person_password[<?php echo (int) $i; ?>]" id="acps-ls-person-pw-<?php echo (int) $i; ?>"
										value="" autocomplete="off"
										placeholder="<?php echo $has ? esc_attr__( '•••••• (blank = keep)', 'acps-link-shortener' ) : esc_attr__( 'set a password', 'acps-link-shortener' ); ?>" />
								</td>
								<td>
									<label class="screen-reader-text" for="acps-ls-person-max-<?php echo (int) $i; ?>"><?php esc_html_e( 'Max links (0 = unlimited)', 'acps-link-shortener' ); ?></label>
									<input type="number" min="0" step="1" style="width:6em;" name="person_max[<?php echo (int) $i; ?>]" id="acps-ls-person-max-<?php echo (int) $i; ?>"
										value="<?php echo esc_attr( $mx ); ?>" />
								</td>
								<td>
									<label class="screen-reader-text" for="acps-ls-person-ns-<?php echo (int) $i; ?>"><?php esc_html_e( 'URL namespace', 'acps-link-shortener' ); ?></label>
									<input type="text" name="person_namespace[<?php echo (int) $i; ?>]" id="acps-ls-person-ns-<?php echo (int) $i; ?>"
										value="<?php echo esc_attr( $ns ); ?>" placeholder="<?php esc_attr_e( 'e.g. katherine', 'acps-link-shortener' ); ?>" />
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description">
					<?php esc_html_e( 'Passwords are hashed and cannot be shown again. Max links = 0 means unlimited (counts only links a person made via the shortcode). URL namespace forces the first path segment, e.g. “katherine” makes their links look like acpsmd.org/katherine/name.', 'acps-link-shortener' ); ?>
				</p>

				<h2><?php esc_html_e( 'Google Sheet sync', 'acps-link-shortener' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'WordPress sends its links to a Google Apps Script web app every 3 minutes; the script mirrors them into your spreadsheet and returns the sheet’s rows, which WordPress applies (adds, updates, and deletes of sheet-made links). Deploy the bundled google-apps-script/Code.gs and paste its /exec URL below.', 'acps-link-shortener' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Enable sync', 'acps-link-shortener' ); ?></th>
							<td>
								<label for="acps-ls-sync-enabled">
									<input type="checkbox" name="sync_enabled" id="acps-ls-sync-enabled" value="1" <?php checked( true, $sync_enabled ); ?> />
									<?php esc_html_e( 'Sync links with the Google Sheet every 3 minutes.', 'acps-link-shortener' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="acps-ls-sheet-url"><?php esc_html_e( 'Web app URL', 'acps-link-shortener' ); ?></label></th>
							<td>
								<input type="url" name="sheet_url" id="acps-ls-sheet-url" class="large-text"
									value="<?php echo esc_attr( $sheet_url ); ?>"
									placeholder="https://script.google.com/macros/s/…/exec" aria-describedby="acps-ls-sheet-url-desc" />
								<p class="description" id="acps-ls-sheet-url-desc"><?php esc_html_e( 'The deployed Apps Script web app URL (https). Deploy Code.gs as “Anyone” access.', 'acps-link-shortener' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="acps-ls-sheet-secret"><?php esc_html_e( 'Shared secret', 'acps-link-shortener' ); ?></label></th>
							<td>
								<input type="text" name="sheet_secret" id="acps-ls-sheet-secret" class="regular-text"
									value="<?php echo esc_attr( $sheet_secret ); ?>" autocomplete="off" aria-describedby="acps-ls-sheet-secret-desc" />
								<p class="description" id="acps-ls-sheet-secret-desc"><?php esc_html_e( 'Sent with each request so only this site can drive the sheet. Must match the SECRET in Code.gs.', 'acps-link-shortener' ); ?></p>
							</td>
						</tr>
						<?php if ( is_array( $last_sync ) ) : ?>
							<tr>
								<th scope="row"><?php esc_html_e( 'Last sync', 'acps-link-shortener' ); ?></th>
								<td>
									<?php
									if ( isset( $last_sync['error'] ) ) {
										echo '<span style="color:#b32d2e;">' . esc_html( $last_sync['error'] ) . '</span>';
									} else {
										printf(
											/* translators: 1: time, 2: created, 3: updated, 4: deleted, 5: skipped, 6: errors. */
											esc_html__( '%1$s — created %2$d, updated %3$d, deleted %4$d, skipped %5$d, errors %6$d.', 'acps-link-shortener' ),
											esc_html( isset( $last_sync['time'] ) ? $last_sync['time'] : '' ),
											(int) ( $last_sync['created'] ?? 0 ),
											(int) ( $last_sync['updated'] ?? 0 ),
											(int) ( $last_sync['deleted'] ?? 0 ),
											(int) ( $last_sync['skipped'] ?? 0 ),
											(int) ( $last_sync['errors'] ?? 0 )
										);
									}
									?>
								</td>
							</tr>
						<?php endif; ?>
					</tbody>
				</table>

				<p>
					<?php submit_button( __( 'Save Settings', 'acps-link-shortener' ), 'primary', 'acps_ls_save_settings', false ); ?>
					<?php submit_button( __( 'Test connection', 'acps-link-shortener' ), 'secondary', 'acps_ls_test_sync', false ); ?>
				</p>
			</form>
		</div>
		<?php
	}
}
