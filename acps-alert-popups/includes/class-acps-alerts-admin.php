<?php
/**
 * The wp-admin side: alert list, alert editor, settings and the popup meta box.
 *
 * @package ACPS_Alert_Popups
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin screens and actions.
 */
class ACPS_Alerts_Admin {

	const MENU_SLUG     = 'acps-alerts';
	const SETTINGS_SLUG = 'acps-alerts-settings';

	/**
	 * Hooks the admin up.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
		add_action( 'save_post', array( $this, 'save_meta_box' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'render_requirement_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( ACPS_ALERTS_FILE ), array( $this, 'plugin_action_links' ) );
	}

	/**
	 * The capability required to manage alerts.
	 *
	 * @return string
	 */
	public static function capability() {
		/**
		 * Filters the capability required to manage alerts.
		 *
		 * @param string $capability Capability name.
		 */
		return (string) apply_filters( 'acps_alerts_capability', 'edit_pages' );
	}

	/**
	 * Registers the admin menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		$cap = self::capability();

		add_menu_page(
			__( 'Site Alerts', 'acps-alert-popups' ),
			__( 'Site Alerts', 'acps-alert-popups' ),
			$cap,
			self::MENU_SLUG,
			array( $this, 'render_router' ),
			'dashicons-megaphone',
			26
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'All Alerts', 'acps-alert-popups' ),
			__( 'All Alerts', 'acps-alert-popups' ),
			$cap,
			self::MENU_SLUG,
			array( $this, 'render_router' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Add New Alert', 'acps-alert-popups' ),
			__( 'Add New Alert', 'acps-alert-popups' ),
			$cap,
			'acps-alerts-new',
			array( $this, 'render_new' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Alert Settings', 'acps-alert-popups' ),
			__( 'Settings', 'acps-alert-popups' ),
			'manage_options',
			self::SETTINGS_SLUG,
			array( $this, 'render_settings' )
		);
	}

	/**
	 * Adds a settings link on the plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ),
				esc_html__( 'Alerts', 'acps-alert-popups' )
			)
		);

		return $links;
	}

	/**
	 * Warns when Beaver Builder or its popups cannot be found.
	 *
	 * @return void
	 */
	public function render_requirement_notice() {
		if ( ! current_user_can( self::capability() ) || ACPS_Alerts_Source::is_ready() ) {
			return;
		}

		$screen = get_current_screen();

		if ( $screen && false === strpos( (string) $screen->id, self::MENU_SLUG ) && 'plugins' !== $screen->id ) {
			return;
		}

		$message = ACPS_Alerts_Source::builder_active()
			? __( 'ACPS Alert Popups could not find a Beaver Builder popup post type. Choose one under Site Alerts &rarr; Settings.', 'acps-alert-popups' )
			: __( 'ACPS Alert Popups needs the Beaver Builder plugin to be active.', 'acps-alert-popups' );
		?>
		<div class="notice notice-warning">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
		<?php
	}

	/**
	 * Handles form posts and row actions before any screen renders.
	 *
	 * @return void
	 */
	public function handle_actions() {
		if ( ! isset( $_REQUEST['page'] ) ) {
			return;
		}

		$page = sanitize_key( wp_unslash( $_REQUEST['page'] ) );

		if ( self::SETTINGS_SLUG === $page ) {
			$this->handle_settings_save();

			return;
		}

		if ( self::MENU_SLUG !== $page ) {
			return;
		}

		$action = isset( $_REQUEST['acps_action'] ) ? sanitize_key( wp_unslash( $_REQUEST['acps_action'] ) ) : '';

		if ( 'toggle' === $action ) {
			$this->handle_toggle();

			return;
		}

		if ( 'save' === $action ) {
			$this->handle_alert_save();
		}
	}

	/**
	 * Saves the global settings form.
	 *
	 * @return void
	 */
	protected function handle_settings_save() {
		if ( ! isset( $_POST['acps_settings_nonce'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'acps-alert-popups' ) );
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['acps_settings_nonce'] ) );

		if ( ! wp_verify_nonce( $nonce, 'acps_alerts_save_settings' ) ) {
			wp_die( esc_html__( 'The settings form expired. Please try again.', 'acps-alert-popups' ) );
		}

		$raw = isset( $_POST['acps_settings'] ) ? wp_unslash( $_POST['acps_settings'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below.

		ACPS_Alerts_Settings::save( (array) $raw );

		wp_safe_redirect( add_query_arg( 'acps_message', 'settings-saved', admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) ) );
		exit;
	}

	/**
	 * Flips an alert on or off from the list screen.
	 *
	 * @return void
	 */
	protected function handle_toggle() {
		$alert_id = isset( $_GET['alert'] ) ? absint( $_GET['alert'] ) : 0;
		$nonce    = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! $alert_id || ! wp_verify_nonce( $nonce, 'acps_alerts_toggle_' . $alert_id ) ) {
			wp_die( esc_html__( 'That link expired. Please reload the alerts list and try again.', 'acps-alert-popups' ) );
		}

		if ( ! current_user_can( self::capability() ) || ! ACPS_Alerts_Source::is_popup( $alert_id ) ) {
			wp_die( esc_html__( 'You are not allowed to change this alert.', 'acps-alert-popups' ) );
		}

		$alert   = new ACPS_Alerts_Alert( $alert_id );
		$enabled = ! $alert->get( 'enabled' );

		$alert->set_enabled( $enabled );

		wp_safe_redirect(
			add_query_arg(
				'acps_message',
				$enabled ? 'enabled' : 'disabled',
				admin_url( 'admin.php?page=' . self::MENU_SLUG )
			)
		);
		exit;
	}

	/**
	 * Saves the per-alert settings form.
	 *
	 * @return void
	 */
	protected function handle_alert_save() {
		$alert_id = isset( $_REQUEST['alert'] ) ? absint( $_REQUEST['alert'] ) : 0;

		if ( ! $alert_id || ! ACPS_Alerts_Source::is_popup( $alert_id ) ) {
			return;
		}

		if ( ! current_user_can( self::capability() ) || ! current_user_can( 'edit_post', $alert_id ) ) {
			wp_die( esc_html__( 'You are not allowed to edit this alert.', 'acps-alert-popups' ) );
		}

		$settings = ACPS_Alerts_Fields::read_submission();

		if ( null === $settings ) {
			wp_die( esc_html__( 'The alert form expired. Please try again.', 'acps-alert-popups' ) );
		}

		$alert = new ACPS_Alerts_Alert( $alert_id );
		$alert->save( $settings );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => self::MENU_SLUG,
					'acps_view'    => 'edit',
					'alert'        => $alert_id,
					'acps_message' => 'saved',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Picks the list or edit screen.
	 *
	 * @return void
	 */
	public function render_router() {
		$view = isset( $_GET['acps_view'] ) ? sanitize_key( wp_unslash( $_GET['acps_view'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view switch.

		if ( 'edit' === $view ) {
			$this->render_edit();

			return;
		}

		$this->render_list();
	}

	/**
	 * Admin notice for the current ?acps_message value.
	 *
	 * @return void
	 */
	protected function render_message() {
		$message = isset( $_GET['acps_message'] ) ? sanitize_key( wp_unslash( $_GET['acps_message'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.

		$messages = array(
			'saved'          => __( 'Alert settings saved.', 'acps-alert-popups' ),
			'enabled'        => __( 'Alert is now live.', 'acps-alert-popups' ),
			'disabled'       => __( 'Alert switched off.', 'acps-alert-popups' ),
			'settings-saved' => __( 'Settings saved.', 'acps-alert-popups' ),
		);

		if ( ! isset( $messages[ $message ] ) ) {
			return;
		}
		?>
		<div class="notice notice-success is-dismissible">
			<p><?php echo esc_html( $messages[ $message ] ); ?></p>
		</div>
		<?php
	}

	/**
	 * The list of every popup, with its alert status.
	 *
	 * @return void
	 */
	protected function render_list() {
		$popups = ACPS_Alerts_Source::get_popups();
		?>
		<div class="wrap acps-alerts-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Site Alerts', 'acps-alert-popups' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=acps-alerts-new' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New Alert', 'acps-alert-popups' ); ?></a>
			<hr class="wp-header-end" />

			<?php $this->render_message(); ?>

			<p class="description">
				<?php esc_html_e( 'Every Beaver Builder popup on this site is listed here. Build the content in Beaver Builder, then switch the alert on and set its schedule, targeting and triggers.', 'acps-alert-popups' ); ?>
			</p>

			<?php if ( empty( $popups ) ) : ?>
				<div class="notice notice-info inline">
					<p><?php esc_html_e( 'No Beaver Builder popups found yet. Create one and it will appear here.', 'acps-alert-popups' ); ?></p>
				</div>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped acps-alerts-table">
					<thead>
						<tr>
							<th scope="col" class="column-primary"><?php esc_html_e( 'Popup', 'acps-alert-popups' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'acps-alert-popups' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Severity', 'acps-alert-popups' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Schedule', 'acps-alert-popups' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Where', 'acps-alert-popups' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Trigger', 'acps-alert-popups' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Priority', 'acps-alert-popups' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $popups as $post ) : ?>
							<?php $this->render_list_row( new ACPS_Alerts_Alert( $post ) ); ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * One row of the alerts list.
	 *
	 * @param ACPS_Alerts_Alert $alert Alert to render.
	 * @return void
	 */
	protected function render_list_row( ACPS_Alerts_Alert $alert ) {
		$id      = $alert->get_id();
		$enabled = (bool) $alert->get( 'enabled' );
		$live    = $enabled && ACPS_Alerts_Conditions::passes_schedule( $alert ) && 'publish' === get_post_status( $id );

		$edit_url = add_query_arg(
			array(
				'page'      => self::MENU_SLUG,
				'acps_view' => 'edit',
				'alert'     => $id,
			),
			admin_url( 'admin.php' )
		);

		$toggle_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'        => self::MENU_SLUG,
					'acps_action' => 'toggle',
					'alert'       => $id,
				),
				admin_url( 'admin.php' )
			),
			'acps_alerts_toggle_' . $id
		);

		$triggers = array(
			'load'   => __( 'Page load', 'acps-alert-popups' ),
			'delay'  => __( 'After delay', 'acps-alert-popups' ),
			'scroll' => __( 'On scroll', 'acps-alert-popups' ),
			'exit'   => __( 'Exit intent', 'acps-alert-popups' ),
			'click'  => __( 'On click only', 'acps-alert-popups' ),
		);

		$places = array(
			'entire'   => __( 'Entire site', 'acps-alert-popups' ),
			'front'    => __( 'Front page', 'acps-alert-popups' ),
			'selected' => __( 'Selected locations', 'acps-alert-popups' ),
		);

		$severity = $alert->get( 'severity' );
		$trigger  = $alert->get( 'trigger' );
		$display  = $alert->get( 'display' );
		?>
		<tr>
			<td class="column-primary">
				<strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $alert->get_title() ); ?></a></strong>
				<?php if ( 'publish' !== get_post_status( $id ) ) : ?>
					<span class="acps-badge acps-badge--draft"><?php echo esc_html( get_post_status( $id ) ); ?></span>
				<?php endif; ?>
				<div class="row-actions">
					<span><a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Alert settings', 'acps-alert-popups' ); ?></a> | </span>
					<span><a href="<?php echo esc_url( ACPS_Alerts_Source::builder_edit_url( $id ) ); ?>"><?php esc_html_e( 'Edit in Beaver Builder', 'acps-alert-popups' ); ?></a> | </span>
					<span><a href="<?php echo esc_url( ACPS_Alerts_Source::post_edit_url( $id ) ); ?>"><?php esc_html_e( 'WordPress editor', 'acps-alert-popups' ); ?></a> | </span>
					<span><a href="<?php echo esc_url( $toggle_url ); ?>"><?php echo $enabled ? esc_html__( 'Switch off', 'acps-alert-popups' ) : esc_html__( 'Switch on', 'acps-alert-popups' ); ?></a></span>
				</div>
			</td>
			<td>
				<?php if ( $live ) : ?>
					<span class="acps-status acps-status--live"><?php esc_html_e( 'Live', 'acps-alert-popups' ); ?></span>
				<?php elseif ( $enabled ) : ?>
					<span class="acps-status acps-status--scheduled"><?php esc_html_e( 'On, not showing', 'acps-alert-popups' ); ?></span>
				<?php else : ?>
					<span class="acps-status acps-status--off"><?php esc_html_e( 'Off', 'acps-alert-popups' ); ?></span>
				<?php endif; ?>
			</td>
			<td><span class="acps-severity acps-severity--<?php echo esc_attr( $severity ); ?>"><?php echo esc_html( ucfirst( $severity ) ); ?></span></td>
			<td><?php echo esc_html( $alert->get_schedule_label() ); ?></td>
			<td><?php echo esc_html( isset( $places[ $display ] ) ? $places[ $display ] : $display ); ?></td>
			<td><?php echo esc_html( isset( $triggers[ $trigger ] ) ? $triggers[ $trigger ] : $trigger ); ?></td>
			<td><?php echo esc_html( $alert->get( 'priority' ) ); ?></td>
		</tr>
		<?php
	}

	/**
	 * The per-alert settings screen.
	 *
	 * @return void
	 */
	protected function render_edit() {
		$alert_id = isset( $_GET['alert'] ) ? absint( $_GET['alert'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen.

		if ( ! $alert_id || ! ACPS_Alerts_Source::is_popup( $alert_id ) ) {
			wp_die( esc_html__( 'That alert could not be found.', 'acps-alert-popups' ) );
		}

		$alert  = new ACPS_Alerts_Alert( $alert_id );
		$action = add_query_arg(
			array(
				'page'        => self::MENU_SLUG,
				'acps_action' => 'save',
				'alert'       => $alert_id,
			),
			admin_url( 'admin.php' )
		);
		?>
		<div class="wrap acps-alerts-wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html( $alert->get_title() ); ?></h1>
			<a href="<?php echo esc_url( ACPS_Alerts_Source::builder_edit_url( $alert_id ) ); ?>" class="page-title-action"><?php esc_html_e( 'Edit content in Beaver Builder', 'acps-alert-popups' ); ?></a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>" class="page-title-action"><?php esc_html_e( 'Back to all alerts', 'acps-alert-popups' ); ?></a>
			<hr class="wp-header-end" />

			<?php $this->render_message(); ?>

			<form method="post" action="<?php echo esc_url( $action ); ?>">
				<?php ACPS_Alerts_Fields::render( $alert ); ?>
				<?php submit_button( __( 'Save alert settings', 'acps-alert-popups' ) ); ?>
			</form>

			<div class="acps-section">
				<h2 class="acps-section__title"><?php esc_html_e( 'Open this alert from a page', 'acps-alert-popups' ); ?></h2>
				<p><?php esc_html_e( 'Use the Alert Trigger module in Beaver Builder, add the class acps-alert-open with a data-alert attribute to any element, or drop this shortcode into a page:', 'acps-alert-popups' ); ?></p>
				<p><code>[acps_alert_trigger id="<?php echo esc_html( $alert_id ); ?>" text="<?php esc_attr_e( 'Read the alert', 'acps-alert-popups' ); ?>"]</code></p>
				<p><code>&lt;a href="#" class="acps-alert-open" data-alert="<?php echo esc_html( $alert_id ); ?>"&gt;<?php esc_html_e( 'Read the alert', 'acps-alert-popups' ); ?>&lt;/a&gt;</code></p>
				<?php if ( ACPS_Alerts_Settings::get( 'respect_preview' ) ) : ?>
					<p>
						<?php esc_html_e( 'Preview it on the live site (editors only):', 'acps-alert-popups' ); ?>
						<a href="<?php echo esc_url( add_query_arg( 'acps_alert_preview', $alert_id, home_url( '/' ) ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open preview', 'acps-alert-popups' ); ?></a>
					</p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * The "Add New Alert" screen.
	 *
	 * @return void
	 */
	public function render_new() {
		?>
		<div class="wrap acps-alerts-wrap">
			<h1><?php esc_html_e( 'Add New Alert', 'acps-alert-popups' ); ?></h1>

			<?php if ( ! ACPS_Alerts_Source::is_ready() ) : ?>
				<div class="notice notice-warning inline">
					<p><?php esc_html_e( 'Beaver Builder popups are not available yet, so alerts cannot be created. Check Site Alerts &rarr; Settings.', 'acps-alert-popups' ); ?></p>
				</div>
			<?php else : ?>
				<ol class="acps-steps">
					<li><?php esc_html_e( 'Create the popup in Beaver Builder and design its content there.', 'acps-alert-popups' ); ?></li>
					<li><?php esc_html_e( 'Publish the popup.', 'acps-alert-popups' ); ?></li>
					<li><?php esc_html_e( 'Come back to Site Alerts, open the popup and switch the alert on.', 'acps-alert-popups' ); ?></li>
				</ol>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( ACPS_Alerts_Source::new_popup_url() ); ?>"><?php esc_html_e( 'Create a Beaver Builder popup', 'acps-alert-popups' ); ?></a>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>"><?php esc_html_e( 'Back to all alerts', 'acps-alert-popups' ); ?></a>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The global settings screen.
	 *
	 * @return void
	 */
	public function render_settings() {
		$settings   = ACPS_Alerts_Settings::all();
		$post_types = get_post_types( array(), 'objects' );
		?>
		<div class="wrap acps-alerts-wrap">
			<h1><?php esc_html_e( 'Alert Settings', 'acps-alert-popups' ); ?></h1>

			<?php $this->render_message(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) ); ?>">
				<?php wp_nonce_field( 'acps_alerts_save_settings', 'acps_settings_nonce' ); ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Popup post type', 'acps-alert-popups' ); ?></th>
							<td>
								<select name="acps_settings[popup_post_type]">
									<option value=""><?php esc_html_e( 'Detect automatically', 'acps-alert-popups' ); ?></option>
									<?php foreach ( $post_types as $post_type ) : ?>
										<option value="<?php echo esc_attr( $post_type->name ); ?>" <?php selected( $settings['popup_post_type'], $post_type->name ); ?>>
											<?php echo esc_html( $post_type->labels->singular_name . ' (' . $post_type->name . ')' ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description">
									<?php
									$detected = ACPS_Alerts_Source::post_type();

									if ( '' !== $detected ) {
										/* translators: %s: post type slug. */
										printf( esc_html__( 'Currently using: %s', 'acps-alert-popups' ), '<code>' . esc_html( $detected ) . '</code>' );
									} else {
										esc_html_e( 'No Beaver Builder popup post type was detected. Pick one here if your version registers a different slug.', 'acps-alert-popups' );
									}
									?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Rendering', 'acps-alert-popups' ); ?></th>
							<td>
								<select name="acps_settings[render_mode]">
									<option value="auto" <?php selected( $settings['render_mode'], 'auto' ); ?>><?php esc_html_e( 'Automatic', 'acps-alert-popups' ); ?></option>
									<option value="native" <?php selected( $settings['render_mode'], 'native' ); ?>><?php esc_html_e( 'Let Beaver Builder render the popup', 'acps-alert-popups' ); ?></option>
									<option value="modal" <?php selected( $settings['render_mode'], 'modal' ); ?>><?php esc_html_e( 'Render the layout in this plugin&rsquo;s modal', 'acps-alert-popups' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Automatic uses Beaver Builder&rsquo;s own popup engine when it exposes one, and falls back to this plugin&rsquo;s accessible modal.', 'acps-alert-popups' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Alerts per page view', 'acps-alert-popups' ); ?></th>
							<td>
								<input type="number" class="small-text" name="acps_settings[max_concurrent]" value="<?php echo esc_attr( $settings['max_concurrent'] ); ?>" min="1" max="5" />
								<p class="description"><?php esc_html_e( 'When more alerts qualify, the highest priority ones win.', 'acps-alert-popups' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Remember dismissals in', 'acps-alert-popups' ); ?></th>
							<td>
								<select name="acps_settings[storage]">
									<option value="local" <?php selected( $settings['storage'], 'local' ); ?>><?php esc_html_e( 'Local storage', 'acps-alert-popups' ); ?></option>
									<option value="session" <?php selected( $settings['storage'], 'session' ); ?>><?php esc_html_e( 'Session storage', 'acps-alert-popups' ); ?></option>
									<option value="cookie" <?php selected( $settings['storage'], 'cookie' ); ?>><?php esc_html_e( 'Cookie', 'acps-alert-popups' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'z-index', 'acps-alert-popups' ); ?></th>
							<td><input type="number" class="small-text" name="acps_settings[z_index]" value="<?php echo esc_attr( $settings['z_index'] ); ?>" min="1" /></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Editors', 'acps-alert-popups' ); ?></th>
							<td>
								<label>
									<input type="hidden" name="acps_settings[hide_for_admins]" value="0" />
									<input type="checkbox" name="acps_settings[hide_for_admins]" value="1" <?php checked( 1, (int) $settings['hide_for_admins'] ); ?> />
									<?php esc_html_e( 'Hide alerts from users who can edit pages', 'acps-alert-popups' ); ?>
								</label>
								<br />
								<label>
									<input type="hidden" name="acps_settings[respect_preview]" value="0" />
									<input type="checkbox" name="acps_settings[respect_preview]" value="1" <?php checked( 1, (int) $settings['respect_preview'] ); ?> />
									<?php esc_html_e( 'Allow editors to preview an alert with ?acps_alert_preview=ID', 'acps-alert-popups' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Extra CSS', 'acps-alert-popups' ); ?></th>
							<td>
								<textarea name="acps_settings[custom_css]" rows="6" class="large-text code"><?php echo esc_textarea( $settings['custom_css'] ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Printed with the alert styles on the front end.', 'acps-alert-popups' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Adds the alert meta box to the popup editor.
	 *
	 * @return void
	 */
	public function register_meta_box() {
		$post_type = ACPS_Alerts_Source::post_type();

		if ( '' === $post_type ) {
			return;
		}

		add_meta_box(
			'acps-alert-settings',
			__( 'Site Alert Settings', 'acps-alert-popups' ),
			array( $this, 'render_meta_box' ),
			$post_type,
			'normal',
			'high'
		);
	}

	/**
	 * Renders the alert meta box.
	 *
	 * @param WP_Post $post Popup post.
	 * @return void
	 */
	public function render_meta_box( $post ) {
		$alert = new ACPS_Alerts_Alert( $post );
		?>
		<p class="description">
			<?php esc_html_e( 'These settings decide when and where this popup runs as a site alert. The popup content itself is designed in Beaver Builder.', 'acps-alert-popups' ); ?>
		</p>
		<?php
		ACPS_Alerts_Fields::render( $alert );
	}

	/**
	 * Saves the alert meta box.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public function save_meta_box( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || ! ACPS_Alerts_Source::is_popup( $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$settings = ACPS_Alerts_Fields::read_submission();

		if ( null === $settings ) {
			return;
		}

		$alert = new ACPS_Alerts_Alert( $post );
		$alert->save( $settings );
	}

	/**
	 * Loads admin CSS and JS on the plugin screens and the popup editor.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		$screen    = get_current_screen();
		$post_type = ACPS_Alerts_Source::post_type();
		$is_popup  = $screen && $post_type && $screen->post_type === $post_type && in_array( $screen->base, array( 'post' ), true );
		$is_plugin = false !== strpos( (string) $hook, self::MENU_SLUG );

		if ( ! $is_popup && ! $is_plugin ) {
			return;
		}

		wp_enqueue_style( 'acps-alerts-admin', ACPS_ALERTS_URL . 'assets/css/admin.css', array(), ACPS_ALERTS_VERSION );
		wp_enqueue_script( 'acps-alerts-admin', ACPS_ALERTS_URL . 'assets/js/admin.js', array(), ACPS_ALERTS_VERSION, true );
	}
}
