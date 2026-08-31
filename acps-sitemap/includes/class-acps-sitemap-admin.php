<?php
/**
 * Admin settings page (single-site, under Settings -> ACPS Sitemap).
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
	 * Register the setting and its sanitizer. Flushes rewrite rules on save so
	 * enabling/disabling the XML sitemap takes effect immediately.
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
	 * Sanitize submitted settings.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public function sanitize( $input ) {
		$defaults = ACPS_Sitemap::defaults();
		$input    = is_array( $input ) ? $input : array();
		$clean    = array();

		$clean['enable_xml']           = empty( $input['enable_xml'] ) ? 0 : 1;
		$clean['public_only']          = empty( $input['public_only'] ) ? 0 : 1;
		$clean['disable_core_sitemap'] = empty( $input['disable_core_sitemap'] ) ? 0 : 1;
		$clean['add_to_robots']        = empty( $input['add_to_robots'] ) ? 0 : 1;

		// Post types: keep only registered, public types that were submitted.
		$valid_pts          = get_post_types( array( 'public' => true ) );
		$submitted_pts      = isset( $input['post_types'] ) ? (array) $input['post_types'] : array();
		$clean['post_types'] = array_values( array_intersect( $valid_pts, array_map( 'sanitize_key', $submitted_pts ) ) );

		// Taxonomies: keep only registered, public taxonomies that were submitted.
		$valid_tax          = get_taxonomies( array( 'public' => true ) );
		$submitted_tax      = isset( $input['taxonomies'] ) ? (array) $input['taxonomies'] : array();
		$clean['taxonomies'] = array_values( array_intersect( $valid_tax, array_map( 'sanitize_key', $submitted_tax ) ) );

		// Excluded IDs from a comma/space separated string.
		$raw_ids = isset( $input['exclude_ids'] ) ? (string) $input['exclude_ids'] : '';
		preg_match_all( '/\d+/', $raw_ids, $matches );
		$clean['exclude_ids'] = array_values( array_unique( array_map( 'intval', $matches[0] ) ) );

		// Max URLs per sitemap.
		$max                     = isset( $input['max_per_sitemap'] ) ? (int) $input['max_per_sitemap'] : $defaults['max_per_sitemap'];
		$clean['max_per_sitemap'] = max( 1, min( 50000, $max ) );

		// Content selection changed: rebuild rewrite rules and clear cache.
		ACPS_Sitemap_XML::add_rewrite_rules();
		flush_rewrite_rules();
		ACPS_Sitemap::bust_cache();

		return $clean;
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
				'page'            => self::PAGE,
				'acps_page_result' => $result,
				'acps_page_id'     => is_wp_error( $page_id ) ? 0 : (int) $page_id,
			),
			admin_url( 'options-general.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Show a notice after the create-page action.
	 */
	public function maybe_notice() {
		if ( empty( $_GET['acps_page_result'] ) || empty( $_GET['page'] ) || self::PAGE !== $_GET['page'] ) {
			return;
		}
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
						<th scope="row"><?php esc_html_e( 'Content visibility', 'acps-sitemap' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( ACPS_Sitemap::OPTION ); ?>[public_only]" value="1" <?php checked( $settings['public_only'], 1 ); ?> />
								<?php esc_html_e( 'Only include public content', 'acps-sitemap' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Leaves out password-protected posts/pages and anything marked "noindex" by your SEO plugin (Yoast SEO, Rank Math, All in One SEO, or SEOPress). Turn this off to list everything regardless of visibility.', 'acps-sitemap' ); ?>
							</p>
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

				<?php submit_button(); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'HTML sitemap page', 'acps-sitemap' ); ?></h2>
			<p><?php esc_html_e( 'Create a visitor-facing "Sitemap" page that uses the shortcode automatically.', 'acps-sitemap' ); ?></p>
			<p>
				<a href="<?php echo esc_url( $create_url ); ?>" class="button button-secondary">
					<?php esc_html_e( 'Create sitemap page', 'acps-sitemap' ); ?>
				</a>
			</p>
		</div>
		<?php
	}
}
