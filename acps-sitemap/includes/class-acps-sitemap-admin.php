<?php
/**
 * Admin settings page (single-site, under Settings -> ACPS Sitemap).
 *
 * The page carries two independent forms that both write to the single
 * settings option: the sitemap options, and the self-hosted "Updates" panel.
 * A hidden `_form` marker lets sanitize() update one section without wiping
 * the other.
 *
 * @package ACPS_Sitemap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACPS_Sitemap_Admin {

	/** Settings page slug. */
	const PAGE = 'acps-sitemap';

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_acps_sitemap_create_page', array( $this, 'handle_create_page' ) );
		add_action( 'admin_post_acps_sitemap_check_updates', array( $this, 'handle_check_updates' ) );
		add_filter(
			'plugin_action_links_' . plugin_basename( ACPS_SITEMAP_FILE ),
			array( $this, 'action_links' )
		);
		add_action( 'admin_notices', array( $this, 'maybe_notice' ) );
	}

	/**
	 * Add the options page. Uses add_options_page (Settings menu) so it is a
	 * per-site screen — never a Network Admin screen.
	 */
	public function add_menu() {
		add_options_page(
			__( 'ACPS Sitemap', 'acps-sitemap' ),
			__( 'ACPS Sitemap', 'acps-sitemap' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	/**
	 * "Settings" link on the Plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ) {
		$url  = admin_url( 'options-general.php?page=' . self::PAGE );
		$link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'acps-sitemap' ) . '</a>';
		array_unshift( $links, $link );
		return $links;
	}

	/* --------------------------------------------------------------------- *
	 * Settings API.
	 * --------------------------------------------------------------------- */

	/**
	 * Register the setting and its sanitizer.
	 */
	public function register_settings() {
		register_setting(
			'acps_sitemap_group',
			ACPS_Sitemap::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => ACPS_Sitemap::defaults(),
			)
		);
	}

	/**
	 * Sanitize submitted settings. Only the fields of the submitted section
	 * (marked by `_form`) are changed; the other section is preserved.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$clean = ACPS_Sitemap::get_settings(); // Start from current values.
		$form  = isset( $input['_form'] ) ? sanitize_key( $input['_form'] ) : 'general';

		if ( 'updates' === $form ) {
			$this->sanitize_updates( $input, $clean );
		} else {
			$this->sanitize_general( $input, $clean );
			// Content selection changed: rebuild rewrite rules and clear cache.
			ACPS_Sitemap_XML::add_rewrite_rules();
			flush_rewrite_rules();
			ACPS_Sitemap::bust_cache();
		}

		unset( $clean['_form'] );
		return $clean;
	}

	/**
	 * Sanitize the sitemap-options section into $clean (by reference).
	 *
	 * @param array $input Raw input.
	 * @param array $clean Settings being built.
	 */
	private function sanitize_general( $input, &$clean ) {
		$defaults = ACPS_Sitemap::defaults();

		$clean['enable_xml']           = empty( $input['enable_xml'] ) ? 0 : 1;
		$clean['disable_core_sitemap'] = empty( $input['disable_core_sitemap'] ) ? 0 : 1;
		$clean['add_to_robots']        = empty( $input['add_to_robots'] ) ? 0 : 1;

		$valid_pts           = get_post_types( array( 'public' => true ) );
		$submitted_pts       = isset( $input['post_types'] ) ? (array) $input['post_types'] : array();
		$clean['post_types'] = array_values( array_intersect( $valid_pts, array_map( 'sanitize_key', $submitted_pts ) ) );

		$valid_tax           = get_taxonomies( array( 'public' => true ) );
		$submitted_tax       = isset( $input['taxonomies'] ) ? (array) $input['taxonomies'] : array();
		$clean['taxonomies'] = array_values( array_intersect( $valid_tax, array_map( 'sanitize_key', $submitted_tax ) ) );

		$raw_ids = isset( $input['exclude_ids'] ) ? (string) $input['exclude_ids'] : '';
		preg_match_all( '/\d+/', $raw_ids, $matches );
		$clean['exclude_ids'] = array_values( array_unique( array_map( 'intval', $matches[0] ) ) );

		$max                      = isset( $input['max_per_sitemap'] ) ? (int) $input['max_per_sitemap'] : $defaults['max_per_sitemap'];
		$clean['max_per_sitemap'] = max( 1, min( 50000, $max ) );
	}

	/**
	 * Sanitize the updates section into $clean (by reference). Note: the
	 * force-update secret (update_trigger) is not editable here, so it is
	 * carried over untouched.
	 *
	 * @param array $input Raw input.
	 * @param array $clean Settings being built.
	 */
	private function sanitize_updates( $input, &$clean ) {
		$clean['update_enabled'] = empty( $input['update_enabled'] ) ? 0 : 1;
		$clean['update_auto']    = empty( $input['update_auto'] ) ? 0 : 1;

		$source                 = isset( $input['update_source'] ) ? sanitize_key( $input['update_source'] ) : 'github';
		$clean['update_source'] = in_array( $source, array( 'url', 'github' ), true ) ? $source : 'github';

		$clean['update_manifest']     = isset( $input['update_manifest'] ) ? esc_url_raw( trim( (string) $input['update_manifest'] ) ) : '';
		$clean['update_manifest_key'] = isset( $input['update_manifest_key'] ) ? sanitize_text_field( $input['update_manifest_key'] ) : '';

		$clean['gh_owner'] = isset( $input['gh_owner'] ) ? sanitize_text_field( $input['gh_owner'] ) : '';
		$clean['gh_repo']  = isset( $input['gh_repo'] ) ? sanitize_text_field( $input['gh_repo'] ) : '';
		$clean['gh_asset'] = isset( $input['gh_asset'] ) ? sanitize_file_name( $input['gh_asset'] ) : 'acps-sitemap.zip';
		$clean['gh_token'] = isset( $input['gh_token'] ) ? trim( sanitize_text_field( $input['gh_token'] ) ) : '';

		$role                 = isset( $input['update_role'] ) ? sanitize_key( $input['update_role'] ) : 'standalone';
		$clean['update_role'] = in_array( $role, array( 'standalone', 'dev', 'production' ), true ) ? $role : 'standalone';

		$clean['verify_status_url'] = isset( $input['verify_status_url'] ) ? esc_url_raw( trim( (string) $input['verify_status_url'] ) ) : '';
		$clean['verify_status_key'] = isset( $input['verify_status_key'] ) ? sanitize_text_field( $input['verify_status_key'] ) : '';
	}

	/* --------------------------------------------------------------------- *
	 * "Create HTML sitemap page" action.
	 * --------------------------------------------------------------------- */

	/**
	 * Create (or reuse) a page containing the [acps_sitemap] shortcode.
	 */
	public function handle_create_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'acps-sitemap' ) );
		}
		check_admin_referer( 'acps_sitemap_create_page' );

		$existing = get_page_by_path( 'sitemap' );
		if ( $existing instanceof WP_Post ) {
			$page_id = $existing->ID;
			$result  = 'exists';
		} else {
			$page_id = wp_insert_post(
				array(
					'post_title'   => __( 'Sitemap', 'acps-sitemap' ),
					'post_name'    => 'sitemap',
					'post_content' => '[acps_sitemap]',
					'post_status'  => 'publish',
					'post_type'    => 'page',
				)
			);
			$result = ( $page_id && ! is_wp_error( $page_id ) ) ? 'created' : 'error';
		}

		$redirect = add_query_arg(
			array(
				'page'             => self::PAGE,
				'acps_page_result' => $result,
				'acps_page_id'     => is_wp_error( $page_id ) ? 0 : (int) $page_id,
			),
			admin_url( 'options-general.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Force a fresh check of the update source (clears the cached lookup).
	 */
	public function handle_check_updates() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'acps-sitemap' ) );
		}
		check_admin_referer( 'acps_sitemap_check_updates' );

		ACPS_Sitemap_Updater::flush_cache();
		if ( isset( acps_sitemap()->updater ) ) {
			acps_sitemap()->updater->remote( true ); // Re-populate the cache.
		}
		delete_site_transient( 'update_plugins' ); // Make the Plugins screen recheck.

		$redirect = add_query_arg(
			array(
				'page'         => self::PAGE,
				'acps_updates' => '1', // Stay on the hidden Updates view.
				'acps_checked' => '1',
			),
			admin_url( 'options-general.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Notices after the create-page / check-updates actions.
	 */
	public function maybe_notice() {
		if ( empty( $_GET['page'] ) || self::PAGE !== $_GET['page'] ) {
			return;
		}

		if ( ! empty( $_GET['acps_page_result'] ) ) {
			$result  = sanitize_key( wp_unslash( $_GET['acps_page_result'] ) );
			$page_id = isset( $_GET['acps_page_id'] ) ? (int) $_GET['acps_page_id'] : 0;
			$view    = $page_id ? ' <a href="' . esc_url( get_permalink( $page_id ) ) . '">' . esc_html__( 'View page', 'acps-sitemap' ) . '</a>' : '';

			if ( 'created' === $result ) {
				echo '<div class="notice notice-success is-dismissible"><p>'
					. esc_html__( 'Sitemap page created.', 'acps-sitemap' ) . wp_kses_post( $view )
					. '</p></div>';
			} elseif ( 'exists' === $result ) {
				echo '<div class="notice notice-info is-dismissible"><p>'
					. esc_html__( 'A page with the slug "sitemap" already exists.', 'acps-sitemap' ) . wp_kses_post( $view )
					. '</p></div>';
			} else {
				echo '<div class="notice notice-error is-dismissible"><p>'
					. esc_html__( 'Could not create the sitemap page.', 'acps-sitemap' )
					. '</p></div>';
			}
		}

		if ( ! empty( $_GET['acps_checked'] ) ) {
			$status = ACPS_Sitemap_Updater::peek_status();
			if ( $status['has_update'] && ! empty( $status['remote']['version'] ) ) {
				echo '<div class="notice notice-warning is-dismissible"><p>'
					. sprintf(
						/* translators: %s: version number. */
						esc_html__( 'Update available: version %s. It will appear on the Plugins screen.', 'acps-sitemap' ),
						esc_html( $status['remote']['version'] )
					)
					. '</p></div>';
			} elseif ( $status['checked'] && $status['remote'] ) {
				echo '<div class="notice notice-success is-dismissible"><p>'
					. esc_html__( 'You are running the latest version.', 'acps-sitemap' )
					. '</p></div>';
			} else {
				echo '<div class="notice notice-error is-dismissible"><p>'
					. esc_html__( 'Could not reach the configured update source. Check the source settings below.', 'acps-sitemap' )
					. '</p></div>';
			}
		}
	}

	/* --------------------------------------------------------------------- *
	 * Page rendering.
	 * --------------------------------------------------------------------- */

	/**
	 * Render the settings page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings   = ACPS_Sitemap::get_settings();
		$xml        = acps_sitemap()->xml;
		$index_url  = $xml->index_url();
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		$taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
		$create_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=acps_sitemap_create_page' ),
			'acps_sitemap_create_page'
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'ACPS Sitemap', 'acps-sitemap' ); ?></h1>

			<div class="notice notice-info inline" style="margin:15px 0;padding:12px 12px;">
				<p style="margin:0 0 6px;">
					<strong><?php esc_html_e( 'Your XML sitemap:', 'acps-sitemap' ); ?></strong>
					<a href="<?php echo esc_url( $index_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $index_url ); ?></a>
				</p>
				<p style="margin:0;">
					<?php esc_html_e( 'HTML sitemap for visitors: add the shortcode', 'acps-sitemap' ); ?>
					<code>[acps_sitemap]</code>
					<?php esc_html_e( 'to any page.', 'acps-sitemap' ); ?>
				</p>
			</div>

			<form action="options.php" method="post">
				<?php settings_fields( 'acps_sitemap_group' ); ?>
				<input type="hidden" name="<?php echo esc_attr( ACPS_Sitemap::OPTION ); ?>[_form]" value="general" />

				<h2><?php esc_html_e( 'Sitemap options', 'acps-sitemap' ); ?></h2>
				<table class="form-table" role="presentation">
					<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'XML sitemap', 'acps-sitemap' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( ACPS_Sitemap::OPTION ); ?>[enable_xml]" value="1" <?php checked( $settings['enable_xml'], 1 ); ?> />
								<?php esc_html_e( 'Enable the XML sitemap for search engines', 'acps-sitemap' ); ?>
							</label>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Include post types', 'acps-sitemap' ); ?></th>
						<td>
							<?php foreach ( $post_types as $pt ) : ?>
								<label style="display:inline-block;min-width:180px;margin:0 0 6px;">
									<input type="checkbox"
										name="<?php echo esc_attr( ACPS_Sitemap::OPTION ); ?>[post_types][]"
										value="<?php echo esc_attr( $pt->name ); ?>"
										<?php checked( in_array( $pt->name, (array) $settings['post_types'], true ) ); ?> />
									<?php echo esc_html( $pt->labels->name ); ?>
									<span style="color:#888;">(<?php echo esc_html( $pt->name ); ?>)</span>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Include taxonomies', 'acps-sitemap' ); ?></th>
						<td>
							<?php if ( empty( $taxonomies ) ) : ?>
								<em><?php esc_html_e( 'No public taxonomies found.', 'acps-sitemap' ); ?></em>
							<?php else : ?>
								<?php foreach ( $taxonomies as $tax ) : ?>
									<label style="display:inline-block;min-width:180px;margin:0 0 6px;">
										<input type="checkbox"
											name="<?php echo esc_attr( ACPS_Sitemap::OPTION ); ?>[taxonomies][]"
											value="<?php echo esc_attr( $tax->name ); ?>"
											<?php checked( in_array( $tax->name, (array) $settings['taxonomies'], true ) ); ?> />
										<?php echo esc_html( $tax->labels->name ); ?>
										<span style="color:#888;">(<?php echo esc_html( $tax->name ); ?>)</span>
									</label>
								<?php endforeach; ?>
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'Category and tag archive pages, if you want them in the sitemap.', 'acps-sitemap' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="acps-exclude"><?php esc_html_e( 'Exclude IDs', 'acps-sitemap' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="acps-exclude"
								name="<?php echo esc_attr( ACPS_Sitemap::OPTION ); ?>[exclude_ids]"
								value="<?php echo esc_attr( implode( ', ', (array) $settings['exclude_ids'] ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Comma-separated post/page IDs to leave out of the sitemap.', 'acps-sitemap' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="acps-max"><?php esc_html_e( 'URLs per sitemap', 'acps-sitemap' ); ?></label></th>
						<td>
							<input type="number" min="1" max="50000" step="1" id="acps-max"
								name="<?php echo esc_attr( ACPS_Sitemap::OPTION ); ?>[max_per_sitemap]"
								value="<?php echo esc_attr( (int) $settings['max_per_sitemap'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Larger sets are split across multiple sitemap files automatically.', 'acps-sitemap' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Search engines', 'acps-sitemap' ); ?></th>
						<td>
							<label style="display:block;margin-bottom:6px;">
								<input type="checkbox" name="<?php echo esc_attr( ACPS_Sitemap::OPTION ); ?>[add_to_robots]" value="1" <?php checked( $settings['add_to_robots'], 1 ); ?> />
								<?php esc_html_e( 'Reference the sitemap in robots.txt', 'acps-sitemap' ); ?>
							</label>
							<label style="display:block;">
								<input type="checkbox" name="<?php echo esc_attr( ACPS_Sitemap::OPTION ); ?>[disable_core_sitemap]" value="1" <?php checked( $settings['disable_core_sitemap'], 1 ); ?> />
								<?php esc_html_e( 'Turn off the built-in WordPress sitemap (wp-sitemap.xml) to avoid duplicates', 'acps-sitemap' ); ?>
							</label>
						</td>
					</tr>
					</tbody>
				</table>

				<?php submit_button( __( 'Save sitemap options', 'acps-sitemap' ) ); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'HTML sitemap page', 'acps-sitemap' ); ?></h2>
			<p><?php esc_html_e( 'Create a visitor-facing "Sitemap" page that uses the shortcode automatically.', 'acps-sitemap' ); ?></p>
			<p>
				<a href="<?php echo esc_url( $create_url ); ?>" class="button button-secondary">
					<?php esc_html_e( 'Create sitemap page', 'acps-sitemap' ); ?>
				</a>
			</p>

			<?php
			// The Updates panel is intentionally hidden. It renders only when the
			// URL carries ?acps_updates=1, so there is no visible link or mention
			// of it anywhere in the admin; reach it by typing that URL directly.
			if ( isset( $_GET['acps_updates'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				?>
				<hr />
				<?php $this->render_updates_section( $settings ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the self-hosted updates panel.
	 *
	 * @param array $settings Current settings.
	 */
	private function render_updates_section( $settings ) {
		$status     = ACPS_Sitemap_Updater::peek_status();
		$force_url  = ACPS_Sitemap_Updater::force_update_url();
		$status_url = rest_url( ACPS_SITEMAP_REST_NAMESPACE . '/update-status' );
		$check_url  = wp_nonce_url(
			admin_url( 'admin-post.php?action=acps_sitemap_check_updates' ),
			'acps_sitemap_check_updates'
		);
		$opt = esc_attr( ACPS_Sitemap::OPTION );
		?>
		<h2><?php esc_html_e( 'Updates', 'acps-sitemap' ); ?></h2>
		<p class="description" style="max-width:46em;">
			<?php esc_html_e( 'Let this plugin update itself from a source you control (a GitHub release or a JSON manifest), even though it is not on the WordPress.org directory. A new version is crash-tested after install; if it fails to load it is rolled back automatically.', 'acps-sitemap' ); ?>
		</p>

		<table class="form-table" role="presentation">
			<tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Status', 'acps-sitemap' ); ?></th>
				<td>
					<p style="margin:0 0 4px;">
						<?php
						printf(
							/* translators: %s: version number. */
							esc_html__( 'Installed version: %s', 'acps-sitemap' ),
							'<strong>' . esc_html( ACPS_SITEMAP_VERSION ) . '</strong>'
						);
						?>
					</p>
					<p style="margin:0 0 8px;">
						<?php
						if ( $status['has_update'] && ! empty( $status['remote']['version'] ) ) {
							printf(
								/* translators: %s: version number. */
								esc_html__( 'Update available: %s', 'acps-sitemap' ),
								'<strong>' . esc_html( $status['remote']['version'] ) . '</strong>'
							);
						} elseif ( $status['checked'] && $status['remote'] ) {
							esc_html_e( 'Up to date.', 'acps-sitemap' );
						} else {
							esc_html_e( 'Not checked yet (or source unreachable).', 'acps-sitemap' );
						}
						?>
					</p>
					<?php $failed = get_option( 'acps_sitemap_update_failed' ); ?>
					<?php if ( is_array( $failed ) && ! empty( $failed ) ) : ?>
						<p class="notice notice-error" style="padding:8px 10px;margin:0 0 8px;">
							<?php
							esc_html_e( 'A recent update failed its load test and was rolled back / kept disabled to protect the site.', 'acps-sitemap' );
							if ( ! empty( $failed['when'] ) ) {
								echo ' ' . esc_html( $failed['when'] );
							}
							?>
						</p>
					<?php endif; ?>
					<a href="<?php echo esc_url( $check_url ); ?>" class="button button-secondary"><?php esc_html_e( 'Check for updates now', 'acps-sitemap' ); ?></a>
				</td>
			</tr>
			</tbody>
		</table>

		<form action="options.php" method="post">
			<?php settings_fields( 'acps_sitemap_group' ); ?>
			<input type="hidden" name="<?php echo $opt; ?>[_form]" value="updates" />

			<table class="form-table" role="presentation">
				<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable updates', 'acps-sitemap' ); ?></th>
					<td>
						<label style="display:block;margin-bottom:6px;">
							<input type="checkbox" name="<?php echo $opt; ?>[update_enabled]" value="1" <?php checked( $settings['update_enabled'], 1 ); ?> />
							<?php esc_html_e( 'Check the source and show "Update now" on the Plugins screen', 'acps-sitemap' ); ?>
						</label>
						<label style="display:block;">
							<input type="checkbox" name="<?php echo $opt; ?>[update_auto]" value="1" <?php checked( $settings['update_auto'], 1 ); ?> />
							<?php esc_html_e( 'Install updates automatically in the background', 'acps-sitemap' ); ?>
						</label>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="acps-update-source"><?php esc_html_e( 'Update source', 'acps-sitemap' ); ?></label></th>
					<td>
						<select id="acps-update-source" name="<?php echo $opt; ?>[update_source]">
							<option value="github" <?php selected( $settings['update_source'], 'github' ); ?>><?php esc_html_e( 'GitHub Releases', 'acps-sitemap' ); ?></option>
							<option value="url" <?php selected( $settings['update_source'], 'url' ); ?>><?php esc_html_e( 'JSON manifest URL', 'acps-sitemap' ); ?></option>
						</select>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'GitHub source', 'acps-sitemap' ); ?></th>
					<td>
						<p style="margin:0 0 6px;">
							<input type="text" class="regular-text" placeholder="<?php esc_attr_e( 'owner', 'acps-sitemap' ); ?>"
								name="<?php echo $opt; ?>[gh_owner]" value="<?php echo esc_attr( $settings['gh_owner'] ); ?>" />
							<span>/</span>
							<input type="text" class="regular-text" placeholder="<?php esc_attr_e( 'repo', 'acps-sitemap' ); ?>"
								name="<?php echo $opt; ?>[gh_repo]" value="<?php echo esc_attr( $settings['gh_repo'] ); ?>" />
						</p>
						<p style="margin:0 0 6px;">
							<label><?php esc_html_e( 'Release asset filename', 'acps-sitemap' ); ?>
								<input type="text" class="regular-text"
									name="<?php echo $opt; ?>[gh_asset]" value="<?php echo esc_attr( $settings['gh_asset'] ); ?>" />
							</label>
						</p>
						<p style="margin:0;">
							<label><?php esc_html_e( 'Access token (only for private repos)', 'acps-sitemap' ); ?>
								<input type="password" class="regular-text" autocomplete="new-password"
									name="<?php echo $opt; ?>[gh_token]" value="<?php echo esc_attr( $settings['gh_token'] ); ?>" />
							</label>
						</p>
						<p class="description"><?php esc_html_e( 'The release tag (minus a leading "v") is the version. The asset should be a zip that unpacks to the plugin folder.', 'acps-sitemap' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Manifest source', 'acps-sitemap' ); ?></th>
					<td>
						<p style="margin:0 0 6px;">
							<input type="url" class="large-text" placeholder="https://example.org/acps-sitemap.json"
								name="<?php echo $opt; ?>[update_manifest]" value="<?php echo esc_attr( $settings['update_manifest'] ); ?>" />
						</p>
						<p style="margin:0;">
							<label><?php esc_html_e( 'Optional access key (sent as ?key=)', 'acps-sitemap' ); ?>
								<input type="text" class="regular-text"
									name="<?php echo $opt; ?>[update_manifest_key]" value="<?php echo esc_attr( $settings['update_manifest_key'] ); ?>" />
							</label>
						</p>
						<p class="description"><?php esc_html_e( 'A JSON file returning at least {"version","download_url"}.', 'acps-sitemap' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="acps-update-role"><?php esc_html_e( 'Rollout role', 'acps-sitemap' ); ?></label></th>
					<td>
						<select id="acps-update-role" name="<?php echo $opt; ?>[update_role]">
							<option value="standalone" <?php selected( $settings['update_role'], 'standalone' ); ?>><?php esc_html_e( 'Standalone (update directly)', 'acps-sitemap' ); ?></option>
							<option value="dev" <?php selected( $settings['update_role'], 'dev' ); ?>><?php esc_html_e( 'Dev / staging (verify first)', 'acps-sitemap' ); ?></option>
							<option value="production" <?php selected( $settings['update_role'], 'production' ); ?>><?php esc_html_e( 'Production (wait for dev to verify)', 'acps-sitemap' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Optional staged rollout. A production site only updates once the paired dev site has installed and verified the version.', 'acps-sitemap' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Staged rollout link', 'acps-sitemap' ); ?></th>
					<td>
						<p style="margin:0 0 6px;">
							<label><?php esc_html_e( 'Dev status URL (production only)', 'acps-sitemap' ); ?>
								<input type="url" class="large-text"
									name="<?php echo $opt; ?>[verify_status_url]" value="<?php echo esc_attr( $settings['verify_status_url'] ); ?>" />
							</label>
						</p>
						<p style="margin:0;">
							<label><?php esc_html_e( 'Shared status key', 'acps-sitemap' ); ?>
								<input type="text" class="regular-text"
									name="<?php echo $opt; ?>[verify_status_key]" value="<?php echo esc_attr( $settings['verify_status_key'] ); ?>" />
							</label>
						</p>
						<p class="description">
							<?php esc_html_e( 'This site publishes its verified status at:', 'acps-sitemap' ); ?>
							<code><?php echo esc_html( $status_url ); ?></code>
						</p>
					</td>
				</tr>

				<?php if ( '' !== $force_url ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Force-update URL', 'acps-sitemap' ); ?></th>
					<td>
						<input type="text" class="large-text code" readonly onclick="this.select();" value="<?php echo esc_attr( $force_url ); ?>" />
						<p class="description"><?php esc_html_e( 'Keep this secret. Loading it (from curl, cron, or a deploy hook) forces an immediate check and install.', 'acps-sitemap' ); ?></p>
					</td>
				</tr>
				<?php endif; ?>
				</tbody>
			</table>

			<?php submit_button( __( 'Save update settings', 'acps-sitemap' ) ); ?>
		</form>
		<?php
	}
}
