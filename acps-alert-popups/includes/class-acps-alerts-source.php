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

		// The plugin's own type is the one new alerts are created in, because
		// it is the only one we can guarantee has a title field and a Publish
		// button. Beaver Builder's own popup types are still read (see
		// source_post_types()), they are just not where new alerts go.
		if ( post_type_exists( ACPS_Alerts_Post_Type::SLUG ) ) {
			return ACPS_Alerts_Post_Type::SLUG;
		}

		foreach ( self::candidates() as $slug ) {
			if ( post_type_exists( $slug ) ) {
				return $slug;
			}
		}

		return '';
	}

	/**
	 * Every post type alerts may be read from: ours, plus any Beaver Builder
	 * popup type that exists, so popups built before this plugin still appear.
	 *
	 * @return string[]
	 */
	public static function source_post_types() {
		$types = array();

		$primary = self::post_type();

		if ( '' !== $primary ) {
			$types[] = $primary;
		}

		foreach ( self::candidates() as $slug ) {
			// Themer layouts hold every kind of layout in one post type, so they
			// are read separately, filtered by their layout meta.
			if ( 'fl-theme-layout' === $slug ) {
				continue;
			}

			if ( post_type_exists( $slug ) ) {
				$types[] = $slug;
			}
		}

		return array_values( array_unique( $types ) );
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
		// Beaver Builder is no longer a hard requirement. The plugin owns its
		// post type, so alerts can be written in the ordinary editor and shown
		// even if the builder is switched off — losing the builder should cost
		// the design tools, not the alerts that are already running.
		return '' !== self::post_type();
	}

	/**
	 * Every popup post, regardless of alert status.
	 *
	 * @param array $args Extra WP_Query arguments.
	 * @return WP_Post[]
	 */
	public static function get_popups( array $args = array() ) {
		$post_types = self::source_post_types();

		if ( empty( $post_types ) ) {
			return array();
		}

		$query_args = wp_parse_args(
			$args,
			array(
				'post_type'              => $post_types,
				'post_status'            => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page'         => 200,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'suppress_filters'       => false,
			)
		);

		// A Themer layout post type holds every kind of layout, so it can only be
		// read with a meta filter — which would wrongly exclude posts of the
		// other types. When Themer is the chosen source, query it on its own.
		if ( self::is_themer_layout() ) {
			$query_args['post_type']  = 'fl-theme-layout';
			$query_args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => '_fl_theme_layout_type',
					'value'   => 'popup',
					'compare' => '=',
				),
			);
		}

		// A query can throw if another plugin filters it badly, or if the
		// database is unwell. No popups is always a safe answer.
		try {
			$query = new WP_Query( $query_args );
			$posts = $query->posts;
		} catch ( \Throwable $e ) {
			ACPS_Alerts_Failsafe::record( 'source/query', $e->getMessage(), $e->getFile(), $e->getLine() );

			return array();
		}

		return is_array( $posts ) ? array_filter( $posts, array( __CLASS__, 'is_usable_post' ) ) : array();
	}

	/**
	 * Whether a queried row is really a usable post object.
	 *
	 * Guards against a filtered query handing back IDs or malformed rows, which
	 * would otherwise fatal the moment a property is read.
	 *
	 * @param mixed $post Candidate.
	 * @return bool
	 */
	public static function is_usable_post( $post ) {
		return ( $post instanceof WP_Post ) && ! empty( $post->ID );
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
			$alert = new ACPS_Alerts_Alert( $post );

			// Skip anything that did not resolve to a real post, so nothing
			// downstream has to defend against a half-built alert.
			if ( $alert->is_valid() ) {
				$alerts[] = $alert;
			}
		}

		usort(
			$alerts,
			static function ( $a, $b ) {
				$diff = (int) $b->get( 'priority' ) - (int) $a->get( 'priority' );

				if ( 0 !== $diff ) {
					return $diff;
				}

				return strcmp( (string) $a->get_title(), (string) $b->get_title() );
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
		$type = get_post_type( $post_id );

		if ( ! $type ) {
			return false;
		}

		if ( 'fl-theme-layout' === $type ) {
			return 'popup' === get_post_meta( $post_id, '_fl_theme_layout_type', true );
		}

		return in_array( $type, self::source_post_types(), true );
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
		// Always the plugin's own type: it is the one guaranteed to give an
		// editing screen you can actually save from.
		$post_type = post_type_exists( ACPS_Alerts_Post_Type::SLUG ) ? ACPS_Alerts_Post_Type::SLUG : self::post_type();

		if ( '' === $post_type ) {
			return admin_url( 'admin.php?page=acps-alerts-settings' );
		}

		return admin_url( 'post-new.php?post_type=' . rawurlencode( $post_type ) );
	}
}
