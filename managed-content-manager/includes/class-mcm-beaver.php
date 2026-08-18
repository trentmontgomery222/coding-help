<?php
/**
 * Beaver Builder integration.
 *
 * Beaver Builder stores a page's layout as a node tree in the `_fl_builder_data`
 * post meta (published) and `_fl_builder_draft` (draft). Each module node has a
 * `settings` object whose `type` is the module slug (e.g. "heading", "rich-text",
 * "callout"), and the editable text lives in specific settings keys.
 *
 * This class reads that tree, lets the admin expose individual text fields, and
 * writes edits back through Beaver Builder's own API (falling back to post meta),
 * clearing Beaver Builder's asset cache so the change shows on the front end.
 *
 * All methods are static: no state, usable from both wp-admin and the portal.
 *
 * @package mcm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCM_Beaver {

	/**
	 * Is Beaver Builder available in this request?
	 *
	 * @return bool
	 */
	public static function is_active() {
		return class_exists( 'FLBuilderModel' );
	}

	/**
	 * Known text-bearing settings keys per module slug. Verified against a real
	 * Beaver Builder site export. Unknown modules fall back to a generic scan
	 * (see candidate_keys()).
	 *
	 * @return array<string,array<int,string>>
	 */
	public static function field_map() {
		return array(
			'heading'      => array( 'heading' ),
			'rich-text'    => array( 'text' ),
			'text'         => array( 'text' ),
			'text-editor'  => array( 'text' ),
			'callout'      => array( 'title', 'text', 'cta_text' ),
			'cta'          => array( 'heading', 'text', 'cta_text', 'btn_text' ),
			'icon'         => array( 'text' ),
			'button'       => array( 'text' ),
			'button-group' => array( 'text' ),
			'photo'        => array( 'caption' ),
			'testimonial'  => array( 'text' ),
			'pricing-box'  => array( 'title', 'description' ),
			'number-counter' => array( 'before', 'after' ),
			'html'         => array( 'html' ),
			'subheading'   => array( 'text' ),
		);
	}

	/**
	 * Suggested editor field type for a given settings key.
	 *
	 * @param string $key
	 * @return string text|textarea|richtext
	 */
	public static function suggested_type( $key ) {
		$rich = array( 'text', 'html', 'content', 'description' );
		return in_array( $key, $rich, true ) ? 'richtext' : 'text';
	}

	/**
	 * Get a post's Beaver Builder layout node tree.
	 *
	 * @param int    $post_id
	 * @param string $status published|draft
	 * @return array node_id => stdClass
	 */
	public static function get_layout( $post_id, $status = 'published' ) {
		$post_id = absint( $post_id );

		if ( self::is_active() && method_exists( 'FLBuilderModel', 'get_layout_data' ) ) {
			$data = FLBuilderModel::get_layout_data( $status, $post_id );
			if ( is_array( $data ) ) {
				return $data;
			}
		}

		// Fallback: read the raw meta directly.
		$meta_key = ( 'draft' === $status ) ? '_fl_builder_draft' : '_fl_builder_data';
		$data     = get_post_meta( $post_id, $meta_key, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Save a modified layout tree back for a post.
	 *
	 * @param int    $post_id
	 * @param array  $data
	 * @param string $status published|draft
	 */
	public static function save_layout( $post_id, $data, $status = 'published' ) {
		$post_id = absint( $post_id );

		if ( self::is_active() && method_exists( 'FLBuilderModel', 'update_layout_data' ) ) {
			FLBuilderModel::update_layout_data( $data, $status, $post_id );
			return;
		}

		$meta_key = ( 'draft' === $status ) ? '_fl_builder_draft' : '_fl_builder_data';
		update_post_meta( $post_id, $meta_key, $data );
	}

	/**
	 * Posts/pages that are built with Beaver Builder.
	 *
	 * @return WP_Post[]
	 */
	public static function get_bb_posts() {
		$q = new WP_Query(
			array(
				'post_type'      => array( 'page', 'post' ),
				'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'meta_query'     => array(
					array(
						'key'     => '_fl_builder_enabled',
						'value'   => '1',
						'compare' => '=',
					),
				),
				'no_found_rows'  => true,
			)
		);
		return $q->posts;
	}

	/**
	 * Discover editable text fields on a post.
	 *
	 * @param int $post_id
	 * @return array<int,array> list of { node_id, slug, field_key, module_label, value, preview, suggested_type }
	 */
	public static function scan_fields( $post_id ) {
		$nodes  = self::get_layout( $post_id, 'published' );
		$map    = self::field_map();
		$fields = array();

		if ( empty( $nodes ) ) {
			return $fields;
		}

		foreach ( $nodes as $node_id => $node ) {
			if ( ! is_object( $node ) || ( $node->type ?? '' ) !== 'module' ) {
				continue;
			}
			$settings = $node->settings ?? null;
			if ( ! is_object( $settings ) ) {
				continue;
			}
			$slug = isset( $settings->type ) ? (string) $settings->type : ( $node->slug ?? 'module' );

			$keys = isset( $map[ $slug ] ) ? $map[ $slug ] : self::candidate_keys( $settings );

			foreach ( $keys as $key ) {
				if ( ! isset( $settings->$key ) || ! is_string( $settings->$key ) ) {
					continue;
				}
				$value = (string) $settings->$key;
				if ( '' === trim( wp_strip_all_tags( $value ) ) ) {
					continue; // Skip empty fields.
				}
				$preview = wp_strip_all_tags( $value );
				$preview = mb_strlen( $preview ) > 70 ? mb_substr( $preview, 0, 70 ) . '…' : $preview;

				$fields[] = array(
					'node_id'        => (string) $node_id,
					'slug'           => $slug,
					'field_key'      => $key,
					'module_label'   => self::module_label( $slug ) . ' · ' . $key,
					'value'          => $value,
					'preview'        => $preview,
					'suggested_type' => self::suggested_type( $key ),
				);
			}
		}

		return $fields;
	}

	/**
	 * Generic fallback: pull "content-looking" string settings from an unknown
	 * module. Conservative on purpose — we only surface keys whose names read
	 * like content, and skip anything that looks like a style/size/flag value.
	 *
	 * @param object $settings
	 * @return array<int,string>
	 */
	private static function candidate_keys( $settings ) {
		$content_names = array( 'heading', 'subheading', 'sub_heading', 'title', 'subtitle', 'text', 'content', 'html', 'caption', 'description', 'label', 'cta_text', 'btn_text', 'before', 'after' );
		$out           = array();

		foreach ( get_object_vars( $settings ) as $k => $v ) {
			if ( ! is_string( $v ) || '' === trim( $v ) ) {
				continue;
			}
			if ( ! in_array( $k, $content_names, true ) ) {
				continue;
			}
			// Skip obvious non-prose values.
			if ( preg_match( '/^(#|rgb|https?:|\d+(px|%|em)?$)/i', trim( $v ) ) ) {
				continue;
			}
			$out[] = $k;
		}
		return $out;
	}

	/**
	 * Read the current value of one module field (live from Beaver Builder).
	 *
	 * @param int    $post_id
	 * @param string $node_id
	 * @param string $field_key
	 * @return string
	 */
	public static function get_field_value( $post_id, $node_id, $field_key ) {
		$nodes = self::get_layout( $post_id, 'published' );
		if ( isset( $nodes[ $node_id ] ) && is_object( $nodes[ $node_id ] ) ) {
			$settings = $nodes[ $node_id ]->settings ?? null;
			if ( is_object( $settings ) && isset( $settings->$field_key ) ) {
				return (string) $settings->$field_key;
			}
		}
		return '';
	}

	/**
	 * Write a new value into one module field, in both the published layout and
	 * the draft (if the node exists there), then clear Beaver Builder's cache.
	 *
	 * @param int    $post_id
	 * @param string $node_id
	 * @param string $field_key
	 * @param string $value already sanitized
	 * @return true|WP_Error
	 */
	public static function update_field_value( $post_id, $node_id, $field_key, $value ) {
		$post_id = absint( $post_id );
		$updated = false;

		foreach ( array( 'published', 'draft' ) as $status ) {
			$nodes = self::get_layout( $post_id, $status );
			if ( empty( $nodes ) || ! isset( $nodes[ $node_id ] ) || ! is_object( $nodes[ $node_id ] ) ) {
				continue;
			}
			if ( ! isset( $nodes[ $node_id ]->settings ) || ! is_object( $nodes[ $node_id ]->settings ) ) {
				continue;
			}
			$nodes[ $node_id ]->settings->$field_key = $value;
			self::save_layout( $post_id, $nodes, $status );
			$updated = true;
		}

		if ( ! $updated ) {
			return new WP_Error( 'mcm_bb_node', __( 'That Beaver Builder module could not be found. It may have been deleted.', 'mcm' ) );
		}

		self::clear_cache( $post_id );
		return true;
	}

	/**
	 * Clear Beaver Builder's cached CSS/JS for a post so edits appear.
	 *
	 * @param int $post_id
	 */
	public static function clear_cache( $post_id ) {
		if ( self::is_active() && method_exists( 'FLBuilderModel', 'delete_asset_cache' ) ) {
			FLBuilderModel::delete_asset_cache( $post_id );
		}
		clean_post_cache( $post_id );
	}

	/**
	 * Human label for a module slug.
	 *
	 * @param string $slug
	 * @return string
	 */
	public static function module_label( $slug ) {
		$slug = (string) $slug;
		return ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );
	}
}
