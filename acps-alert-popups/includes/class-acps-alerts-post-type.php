<?php
/**
 * The alert post type.
 *
 * Alerts used to be stored on whatever post type Beaver Builder registered for
 * its popups. That turned out to be a bad bet: the popup feature is not in
 * every Beaver Builder version, the slug has changed between them, and when
 * detection fell through to Beaver Themer layouts the "Add New" screen had no
 * ordinary title field or Publish button — so there was no way to save a new
 * alert at all.
 *
 * So the plugin now owns the post type. We control the editing screen, which
 * means a title and a Publish button are always there, and we hand the post
 * type to Beaver Builder so it can be designed in the builder exactly as
 * before. Popups that already exist on a Beaver Builder popup type are still
 * picked up and listed alongside these.
 *
 * @package ACPS_Alert_Popups
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and configures the alert post type.
 */
class ACPS_Alerts_Post_Type {

	const SLUG = 'acps_alert';

	/**
	 * Hooks registration up.
	 *
	 * @return void
	 */
	public function init() {
		ACPS_Alerts_Failsafe::action( 'init', array( __CLASS__, 'register' ), 'cpt/register', 5 );

		// Tell Beaver Builder it may edit this post type. This is what puts the
		// "Launch Beaver Builder" button on the alert, and it means the site
		// owner does not have to enable anything in the builder's own settings.
		ACPS_Alerts_Failsafe::filter( 'fl_builder_post_types', array( __CLASS__, 'enable_builder' ), 'cpt/builder-types' );

		// Alerts are fragments, not pages: keep them out of search engines.
		ACPS_Alerts_Failsafe::filter( 'wp_robots', array( __CLASS__, 'robots' ), 'cpt/robots' );

		ACPS_Alerts_Failsafe::action( 'edit_form_after_title', array( __CLASS__, 'edit_screen_banner' ), 'cpt/banner' );
	}

	/**
	 * Registers the post type.
	 *
	 * Public on purpose: Beaver Builder edits a layout on its own front-end
	 * URL, so the alert needs one. It is kept out of search results, out of
	 * archives and out of search engines instead.
	 *
	 * @return void
	 */
	public static function register() {
		$labels = array(
			'name'                  => __( 'Alerts', 'acps-alert-popups' ),
			'singular_name'         => __( 'Alert', 'acps-alert-popups' ),
			'add_new'               => __( 'Add New Alert', 'acps-alert-popups' ),
			'add_new_item'          => __( 'Add New Alert', 'acps-alert-popups' ),
			'edit_item'             => __( 'Edit Alert', 'acps-alert-popups' ),
			'new_item'              => __( 'New Alert', 'acps-alert-popups' ),
			'view_item'             => __( 'Preview Alert', 'acps-alert-popups' ),
			'search_items'          => __( 'Search Alerts', 'acps-alert-popups' ),
			'not_found'             => __( 'No alerts yet.', 'acps-alert-popups' ),
			'not_found_in_trash'    => __( 'No alerts in the bin.', 'acps-alert-popups' ),
			'all_items'             => __( 'All Alerts', 'acps-alert-popups' ),
			'menu_name'             => __( 'Site Alerts', 'acps-alert-popups' ),
			'item_published'        => __( 'Alert published. Switch it on to show it to visitors.', 'acps-alert-popups' ),
			'item_updated'          => __( 'Alert updated.', 'acps-alert-popups' ),
		);

		register_post_type(
			self::SLUG,
			array(
				'labels'              => $labels,
				'public'              => true,
				'publicly_queryable'  => true,
				'show_ui'             => true,
				// Listed under our own menu rather than its own top-level item.
				'show_in_menu'        => false,
				'show_in_admin_bar'   => false,
				'show_in_nav_menus'   => false,
				'exclude_from_search' => true,
				'has_archive'         => false,
				'hierarchical'        => false,
				'menu_icon'           => 'dashicons-megaphone',
				'supports'            => array( 'title', 'editor', 'revisions', 'author' ),
				'capability_type'     => 'page',
				'map_meta_cap'        => true,
				'rewrite'             => array(
					'slug'       => 'site-alert',
					'with_front' => false,
				),
				'show_in_rest'        => true,
			)
		);
	}

	/**
	 * Adds the alert post type to the ones Beaver Builder can edit.
	 *
	 * @param array $types Post types the builder is enabled for.
	 * @return array
	 */
	public static function enable_builder( $types ) {
		if ( ! is_array( $types ) ) {
			$types = array();
		}

		$types[] = self::SLUG;

		return array_values( array_unique( $types ) );
	}

	/**
	 * Keeps alerts out of search engines when viewed directly.
	 *
	 * @param array $robots Robots directives.
	 * @return array
	 */
	public static function robots( $robots ) {
		if ( is_singular( self::SLUG ) ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}

		return $robots;
	}

	/**
	 * Explains the two-step job at the top of the alert editor, because this is
	 * exactly where people get stuck: they write the alert, save it, and expect
	 * it to be live.
	 *
	 * @param WP_Post $post Post being edited.
	 * @return void
	 */
	public static function edit_screen_banner( $post ) {
		if ( ! $post || self::SLUG !== $post->post_type ) {
			return;
		}

		$published = 'publish' === $post->post_status;
		$builder   = ACPS_Alerts_Source::builder_active();
		?>
		<div class="acps-editor-banner">
			<p class="acps-editor-banner__lede">
				<?php esc_html_e( 'Two steps: write it here, then switch it on below.', 'acps-alert-popups' ); ?>
			</p>
			<ol>
				<li>
					<?php if ( $builder ) : ?>
						<?php esc_html_e( 'Give it a title, then design it — use the editor below, or click Launch Beaver Builder for the full builder.', 'acps-alert-popups' ); ?>
					<?php else : ?>
						<?php esc_html_e( 'Give it a title and write the content in the editor below.', 'acps-alert-popups' ); ?>
					<?php endif; ?>
				</li>
				<li>
					<strong><?php esc_html_e( 'Publish it', 'acps-alert-popups' ); ?></strong>
					<?php esc_html_e( '— a draft never shows, however it is configured.', 'acps-alert-popups' ); ?>
					<?php if ( ! $published ) : ?>
						<span class="acps-editor-banner__todo"><?php esc_html_e( 'not published yet', 'acps-alert-popups' ); ?></span>
					<?php endif; ?>
				</li>
				<li>
					<?php esc_html_e( 'Scroll down to Site Alert Settings and tick "Alert is live".', 'acps-alert-popups' ); ?>
				</li>
			</ol>
			<?php if ( $builder && $published ) : ?>
				<p>
					<a class="button button-secondary" href="<?php echo esc_url( ACPS_Alerts_Source::builder_edit_url( $post->ID ) ); ?>">
						<?php esc_html_e( 'Launch Beaver Builder', 'acps-alert-popups' ); ?>
					</a>
				</p>
			<?php elseif ( $builder ) : ?>
				<p class="acps-editor-banner__hint">
					<?php esc_html_e( 'Publish the alert first, then the Launch Beaver Builder button appears here.', 'acps-alert-popups' ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Registers the type and flushes rewrite rules. Activation only.
	 *
	 * @return void
	 */
	public static function activate() {
		self::register();
		flush_rewrite_rules();
	}
}
