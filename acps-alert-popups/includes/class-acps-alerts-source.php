<?php
/**
 * Locates the Beaver Builder popups that back the alerts.
 *
 * Beaver Builder has shipped its popups under more than one post type slug, and
 * some sites build them as Beaver Themer layouts instead. Rather than hard-code
 * one slug, the plugin auto-detects and lets an admin pin the post type by hand.
 *
 * @package ACPS_Alert_Popups
 */

defined( 'ABSPATH' ) || exit;

/**
 * Popup post type discovery and lookups.
 */
class ACPS_Alerts_Source {

	/**
	 * Post type slugs Beaver Builder is known to use for popups.
	 *
	 * @return array
	 */
	public static function candidates() {
		$candidates = array(
			'fl-popup',
			'fl_popup',
			'fl-builder-popup',
			'flbuilder_popup',
			'fl-theme-layout',
		);

		/**
		 * Filters the popup post type slugs the plugin looks for.
		 *
		 * @param array $candidates Candidate post type slugs, most specific first.
		 */
		return (array) apply_filters( 'acps_alerts_post_type_candidates', $candidates );
	}

	/**
	 * The post type used for alert popups.
	 *
	 * @return string Empty string when nothing suitable is registered.
	 */
	public static function post_type() {
		$configured = ACPS_Alerts_Settings::get( 'popup_post_type' );

		if ( $configured && post_type_exists( $configured ) ) {
			return $configured;
		}

		foreach ( self::candidates() as $slug ) {
			if ( post_type_exists( $slug ) ) {
				return $slug;
			}
		}

		return '';
	}

	/**
	 * Whether the popup source is a Beaver Themer layout post type.
	 *
	 * Themer layouts hold several layout types in one post type, so popups have
	 * to be filtered by their layout meta.
	 *
	 * @return bool
	 */
	public static function is_themer_layout() {
		return 'fl-theme-layout' === self::post_type();
	}

	/**
	 * Whether Beaver Builder itself is active.
	 *
	 * @return bool
	 */
	public static function builder_active() {
		return class_exists( 'FLBuilderLoader' ) || class_exists( 'FLBuilder' );
	}

	/**
	 * Whether the plugin has everything it needs to run.
	 *
	 * @return bool
	 */
	public static function is_ready() {
		return self::builder_active() && '' !== self::post_type();
	}

	/**
	 * Every popup post, regardless of alert status.
	 *
	 * @param array $args Extra WP_Query arguments.
	 * @return WP_Post[]
	 */
	public static function get_popups( array $args = array() ) {
		$post_type = self::post_type();

		if ( '' === $post_type ) {
			return array();
		}

		$query_args = wp_parse_args(
			$args,
			array(
				'post_type'              => $post_type,
				'post_status'            => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page'         => 200,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'suppress_filters'       => false,
			)
		);

		if ( self::is_themer_layout() ) {
			$query_args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => '_fl_theme_layout_type',
					'value'   => 'popup',
					'compare' => '=',
				),
			);
		}

		$query = new WP_Query( $query_args );

		return $query->posts;
	}

	/**
	 * Popups that are published and switched on as alerts, ordered by priority.
	 *
	 * @return ACPS_Alerts_Alert[]
	 */
	public static function get_enabled_alerts() {
		$posts = self::get_popups(
			array(
				'post_status'  => 'publish',
				'meta_key'     => ACPS_Alerts_Alert::META_PREFIX . 'enabled', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'   => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		$alerts = array();

		foreach ( $posts as $post ) {
			$alerts[] = new ACPS_Alerts_Alert( $post );
		}

		usort(
			$alerts,
			static function ( $a, $b ) {
				$diff = $b->get( 'priority' ) - $a->get( 'priority' );

				return 0 !== $diff ? $diff : strcmp( $a->post->post_title, $b->post->post_title );
			}
		);

		return $alerts;
	}

	/**
	 * Whether a post ID is a popup this plugin manages.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_popup( $post_id ) {
		$post_type = self::post_type();

		if ( '' === $post_type || get_post_type( $post_id ) !== $post_type ) {
			return false;
		}

		if ( self::is_themer_layout() && 'popup' !== get_post_meta( $post_id, '_fl_theme_layout_type', true ) ) {
			return false;
		}

		return true;
	}

	/**
	 * URL that opens a popup inside the Beaver Builder editor.
	 *
	 * @param int $post_id Popup post ID.
	 * @return string
	 */
	public static function builder_edit_url( $post_id ) {
		$url = get_permalink( $post_id );

		if ( ! $url ) {
			$url = add_query_arg(
				array(
					'p'         => $post_id,
					'post_type' => get_post_type( $post_id ),
				),
				home_url( '/' )
			);
		}

		return add_query_arg( 'fl_builder', '', $url );
	}

	/**
	 * URL of the normal WordPress edit screen for a popup.
	 *
	 * @param int $post_id Popup post ID.
	 * @return string
	 */
	public static function post_edit_url( $post_id ) {
		return (string) get_edit_post_link( $post_id, 'raw' );
	}

	/**
	 * URL of the screen where new popups are created.
	 *
	 * @return string
	 */
	public static function new_popup_url() {
		$post_type = self::post_type();

		if ( '' === $post_type ) {
			return admin_url( 'admin.php?page=acps-alerts-settings' );
		}

		return admin_url( 'post-new.php?post_type=' . rawurlencode( $post_type ) );
	}
}
