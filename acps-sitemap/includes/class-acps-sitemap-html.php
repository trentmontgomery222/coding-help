<?php
/**
 * HTML sitemap via the [acps_sitemap] shortcode.
 *
 * Renders a human-readable, grouped list of published content for site
 * visitors. Drop the shortcode onto any page:
 *
 *     [acps_sitemap]
 *     [acps_sitemap post_types="page,post" show_taxonomies="1"]
 *
 * @package ACPS_Sitemap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACPS_Sitemap_HTML {

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_shortcode( 'acps_sitemap', array( $this, 'render' ) );
		add_shortcode( 'acps_if', array( $this, 'render_conditional' ) );
	}

	/**
	 * Conditional shortcode for page builders (BeaverBuilder etc.):
	 *
	 *   [acps_if condition="safe_mode"]Shown only in safe mode[/acps_if]
	 *   [acps_if condition="update_available"]An update is ready[/acps_if]
	 *   [acps_if condition="xml_enabled"]Sitemap is on[/acps_if]
	 *   [acps_if plugin_active="woocommerce/woocommerce.php"]…[/acps_if]
	 *   [acps_if plugin_inactive="bb-plugin/fl-builder.php"]BeaverBuilder is off[/acps_if]
	 *
	 * Prefix any named condition with "!" to negate it. Note: while THIS plugin
	 * is parked in safe mode its shortcodes do not run, so use a page-builder
	 * fallback for the "plugin totally down" case; `plugin_active`/`plugin_inactive`
	 * work for any OTHER plugin.
	 *
	 * @param array|string $atts    Attributes.
	 * @param string|null  $content Enclosed content.
	 * @return string
	 */
	public function render_conditional( $atts, $content = null ) {
		try {
			$atts = shortcode_atts(
				array(
					'condition'       => '',
					'plugin_active'   => '',
					'plugin_inactive' => '',
				),
				$atts,
				'acps_if'
			);

			$show = true;

			if ( '' !== $atts['plugin_active'] ) {
				$show = $show && $this->is_plugin_active( $atts['plugin_active'] );
			}
			if ( '' !== $atts['plugin_inactive'] ) {
				$show = $show && ! $this->is_plugin_active( $atts['plugin_inactive'] );
			}
			if ( '' !== $atts['condition'] ) {
				$cond = trim( $atts['condition'] );
				$neg  = ( 0 === strpos( $cond, '!' ) );
				$cond = ltrim( $cond, '!' );
				$res  = $this->eval_condition( $cond );
				$show = $show && ( $neg ? ! $res : $res );
			}

			return $show ? do_shortcode( (string) $content ) : '';
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * Evaluate a named condition.
	 *
	 * @param string $cond Condition name.
	 * @return bool
	 */
	private function eval_condition( $cond ) {
		switch ( $cond ) {
			case 'safe_mode':
				return function_exists( 'acps_sitemap_is_safe_mode' ) && acps_sitemap_is_safe_mode();
			case 'not_safe_mode':
				return ! ( function_exists( 'acps_sitemap_is_safe_mode' ) && acps_sitemap_is_safe_mode() );
			case 'xml_enabled':
				return (bool) ACPS_Sitemap::get_setting( 'enable_xml' );
			case 'xml_disabled':
				return ! ACPS_Sitemap::get_setting( 'enable_xml' );
			case 'remote_enabled':
				return (bool) ACPS_Sitemap::get_setting( 'remote_enabled' );
			case 'update_available':
				if ( class_exists( 'ACPS_Sitemap_Updater' ) ) {
					$s = ACPS_Sitemap_Updater::peek_status();
					return ! empty( $s['has_update'] );
				}
				return false;
			case 'no_update':
				if ( class_exists( 'ACPS_Sitemap_Updater' ) ) {
					$s = ACPS_Sitemap_Updater::peek_status();
					return empty( $s['has_update'] );
				}
				return true;
			case 'update_failed':
				$f = get_option( 'acps_sitemap_update_failed' );
				return is_array( $f ) && ! empty( $f );
		}
		return false;
	}

	/**
	 * Whether a plugin basename is active (loads the helper if needed).
	 *
	 * @param string $basename e.g. "woocommerce/woocommerce.php".
	 * @return bool
	 */
	private function is_plugin_active( $basename ) {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active( $basename );
	}

	/**
	 * Render the shortcode. Guarded so a failure returns an empty string rather
	 * than breaking the page the shortcode sits on.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render( $atts ) {
		try {
			return $this->build( $atts );
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[ACPS Sitemap] shortcode: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			}
			return '';
		}
	}

	/**
	 * Build the shortcode output.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	private function build( $atts ) {
		$settings = ACPS_Sitemap::get_settings();

		$atts = shortcode_atts(
			array(
				'post_types'      => implode( ',', (array) $settings['post_types'] ),
				'show_taxonomies' => ! empty( $settings['taxonomies'] ) ? '1' : '0',
				'title'           => '',
			),
			$atts,
			'acps_sitemap'
		);

		$post_types = array_filter( array_map( 'trim', explode( ',', $atts['post_types'] ) ) );
		$exclude    = array_values( array_filter( array_map( 'intval', (array) $settings['exclude_ids'] ) ) );

		$out = '<div class="acps-sitemap">';

		if ( '' !== $atts['title'] ) {
			$out .= '<h2 class="acps-sitemap__title">' . esc_html( $atts['title'] ) . '</h2>';
		}

		foreach ( $post_types as $post_type ) {
			if ( ! post_type_exists( $post_type ) ) {
				continue;
			}
			$out .= $this->render_post_type( $post_type, $exclude );
		}

		if ( '1' === (string) $atts['show_taxonomies'] ) {
			foreach ( (array) $settings['taxonomies'] as $taxonomy ) {
				if ( taxonomy_exists( $taxonomy ) ) {
					$out .= $this->render_taxonomy( $taxonomy );
				}
			}
		}

		$out .= '</div>';

		return $out;
	}

	/**
	 * Render a section for one post type.
	 *
	 * @param string $post_type Post type.
	 * @param int[]  $exclude   IDs to skip.
	 * @return string
	 */
	private function render_post_type( $post_type, $exclude ) {
		$object = get_post_type_object( $post_type );
		$label  = $object ? $object->labels->name : $post_type;

		// Hierarchical types (pages) get a nested list; others a flat list.
		if ( is_post_type_hierarchical( $post_type ) ) {
			$items = wp_list_pages(
				array(
					'post_type'   => $post_type,
					'exclude'     => implode( ',', $exclude ),
					'title_li'    => '',
					'echo'        => 0,
					'sort_column' => 'menu_order, post_title',
				)
			);
			if ( '' === trim( (string) $items ) ) {
				return '';
			}
			return '<section class="acps-sitemap__group">'
				. '<h3 class="acps-sitemap__heading">' . esc_html( $label ) . '</h3>'
				. '<ul class="acps-sitemap__list">' . $items . '</ul>'
				. '</section>';
		}

		$posts = get_posts(
			array(
				'post_type'        => $post_type,
				'post_status'      => 'publish',
				'posts_per_page'   => 1000,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'post__not_in'     => $exclude,
				'suppress_filters' => false,
			)
		);

		if ( empty( $posts ) ) {
			return '';
		}

		$out  = '<section class="acps-sitemap__group">';
		$out .= '<h3 class="acps-sitemap__heading">' . esc_html( $label ) . '</h3>';
		$out .= '<ul class="acps-sitemap__list">';
		foreach ( $posts as $post ) {
			$out .= '<li><a href="' . esc_url( get_permalink( $post ) ) . '">'
				. esc_html( get_the_title( $post ) ) . '</a></li>';
		}
		$out .= '</ul></section>';

		return $out;
	}

	/**
	 * Render a section for one taxonomy.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return string
	 */
	private function render_taxonomy( $taxonomy ) {
		$object = get_taxonomy( $taxonomy );
		$label  = $object ? $object->labels->name : $taxonomy;

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return '';
		}

		$out  = '<section class="acps-sitemap__group">';
		$out .= '<h3 class="acps-sitemap__heading">' . esc_html( $label ) . '</h3>';
		$out .= '<ul class="acps-sitemap__list">';
		foreach ( $terms as $term ) {
			$link = get_term_link( $term );
			if ( is_wp_error( $link ) ) {
				continue;
			}
			$out .= '<li><a href="' . esc_url( $link ) . '">' . esc_html( $term->name ) . '</a></li>';
		}
		$out .= '</ul></section>';

		return $out;
	}
}
