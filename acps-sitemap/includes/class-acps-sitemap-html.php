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
	}

	/**
	 * Render the shortcode.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render( $atts ) {
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
					'post_type' => $post_type,
					'exclude'   => implode( ',', $exclude ),
					'title_li'  => '',
					'echo'      => 0,
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
