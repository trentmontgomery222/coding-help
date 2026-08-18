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

	// =======================================================================
	// WHOLE-MODULE EDITING
	// =======================================================================

	/**
	 * Structural / unsafe settings we never expose to editors.
	 *
	 * @return array<int,string>
	 */
	public static function internal_keys() {
		return array(
			'type', 'node_label', 'export', 'import', 'connections', 'data',
			'responsive_display_filtered', 'visibility_logic', 'visibility_display',
			'visibility_user_capability', 'bb_css_code', 'bb_js_code', 'id', 'class',
			'container_element', 'responsive_display', 'flag',
		);
	}

	/**
	 * Curated, content-relevant fields per module type, in the order and with
	 * the widgets that make sense for a non-technical editor. Everything not
	 * listed here still shows up under "advanced" (see describe_module()).
	 *
	 * Each entry: key => array( label, widget, options? , on/off tokens for toggle )
	 *
	 * @param string $slug
	 * @return array<string,array>
	 */
	public static function content_schema( $slug ) {
		$h_tags = array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'div', 'span' );

		$schemas = array(
			'heading'      => array(
				'heading' => array( 'label' => __( 'Heading text', 'mcm' ), 'widget' => 'text' ),
				'tag'     => array( 'label' => __( 'Heading tag', 'mcm' ), 'widget' => 'select', 'options' => $h_tags ),
				'link'    => array( 'label' => __( 'Link URL', 'mcm' ), 'widget' => 'url' ),
			),
			'rich-text'    => array(
				'text' => array( 'label' => __( 'Text', 'mcm' ), 'widget' => 'richtext' ),
			),
			'text'         => array(
				'text' => array( 'label' => __( 'Text', 'mcm' ), 'widget' => 'richtext' ),
			),
			'text-editor'  => array(
				'text' => array( 'label' => __( 'Text', 'mcm' ), 'widget' => 'richtext' ),
			),
			'subheading'   => array(
				'text' => array( 'label' => __( 'Text', 'mcm' ), 'widget' => 'richtext' ),
			),
			'html'         => array(
				'html' => array( 'label' => __( 'HTML', 'mcm' ), 'widget' => 'richtext' ),
			),
			'callout'      => array(
				'title'     => array( 'label' => __( 'Title', 'mcm' ), 'widget' => 'text' ),
				'title_tag' => array( 'label' => __( 'Title tag', 'mcm' ), 'widget' => 'select', 'options' => $h_tags ),
				'text'      => array( 'label' => __( 'Text', 'mcm' ), 'widget' => 'richtext' ),
				'cta_text'  => array( 'label' => __( 'Button / CTA text', 'mcm' ), 'widget' => 'text' ),
				'link'      => array( 'label' => __( 'Link URL', 'mcm' ), 'widget' => 'url' ),
				'icon'      => array( 'label' => __( 'Icon', 'mcm' ), 'widget' => 'icon' ),
				'photo'     => array( 'label' => __( 'Image', 'mcm' ), 'widget' => 'image' ),
			),
			'icon'         => array(
				'icon'    => array( 'label' => __( 'Icon', 'mcm' ), 'widget' => 'icon' ),
				'text'    => array( 'label' => __( 'Text', 'mcm' ), 'widget' => 'richtext' ),
				'link'    => array( 'label' => __( 'Link URL', 'mcm' ), 'widget' => 'url' ),
				'sr_text' => array( 'label' => __( 'Screen-reader text', 'mcm' ), 'widget' => 'text' ),
			),
			'button'       => array(
				'text' => array( 'label' => __( 'Button text', 'mcm' ), 'widget' => 'text' ),
				'link' => array( 'label' => __( 'Link URL', 'mcm' ), 'widget' => 'url' ),
				'icon' => array( 'label' => __( 'Icon', 'mcm' ), 'widget' => 'icon' ),
			),
			'button-group' => array(
				'text' => array( 'label' => __( 'Button text', 'mcm' ), 'widget' => 'text' ),
				'link' => array( 'label' => __( 'Link URL', 'mcm' ), 'widget' => 'url' ),
				'icon' => array( 'label' => __( 'Icon', 'mcm' ), 'widget' => 'icon' ),
			),
			'photo'        => array(
				'photo'        => array( 'label' => __( 'Image', 'mcm' ), 'widget' => 'image' ),
				'caption'      => array( 'label' => __( 'Caption', 'mcm' ), 'widget' => 'textarea' ),
				'show_caption' => array( 'label' => __( 'Show caption', 'mcm' ), 'widget' => 'toggle', 'on' => '1', 'off' => '0' ),
				'link_url'     => array( 'label' => __( 'Link URL', 'mcm' ), 'widget' => 'url' ),
			),
			'image'        => array(
				'photo'    => array( 'label' => __( 'Image', 'mcm' ), 'widget' => 'image' ),
				'caption'  => array( 'label' => __( 'Caption', 'mcm' ), 'widget' => 'textarea' ),
				'link_url' => array( 'label' => __( 'Link URL', 'mcm' ), 'widget' => 'url' ),
			),
		);

		return isset( $schemas[ $slug ] ) ? $schemas[ $slug ] : array();
	}

	/**
	 * Fetch a module node's settings as an associative array.
	 *
	 * @param int    $post_id
	 * @param string $node_id
	 * @param string $status
	 * @return array|null [ slug, settings(assoc) ] or null if not found
	 */
	public static function get_module( $post_id, $node_id, $status = 'published' ) {
		$nodes = self::get_layout( $post_id, $status );
		if ( ! isset( $nodes[ $node_id ] ) || ! is_object( $nodes[ $node_id ] ) ) {
			return null;
		}
		$node     = $nodes[ $node_id ];
		$settings = isset( $node->settings ) && is_object( $node->settings ) ? get_object_vars( $node->settings ) : array();
		$slug     = $settings['type'] ?? ( $node->slug ?? 'module' );
		return array( 'slug' => $slug, 'settings' => $settings );
	}

	/**
	 * Build the full editable description of a module: curated "primary" fields
	 * plus every remaining scalar setting under "advanced". Arrays/objects and
	 * internal keys are skipped (and preserved untouched on save).
	 *
	 * @param int    $post_id
	 * @param string $node_id
	 * @return array|WP_Error { slug, label, primary[], advanced[] }
	 */
	public static function describe_module( $post_id, $node_id ) {
		$mod = self::get_module( $post_id, $node_id );
		if ( null === $mod ) {
			return new WP_Error( 'mcm_bb_node', __( 'That Beaver Builder module could not be found.', 'mcm' ) );
		}

		$slug     = $mod['slug'];
		$settings = $mod['settings'];
		$schema   = self::content_schema( $slug );
		$internal = self::internal_keys();

		$primary = array();
		foreach ( $schema as $key => $spec ) {
			if ( ! array_key_exists( $key, $settings ) ) {
				// Image fields are worth showing even if empty; others need a value.
				if ( 'image' !== ( $spec['widget'] ?? '' ) ) {
					continue;
				}
			}
			$value = $settings[ $key ] ?? '';
			if ( is_array( $value ) || is_object( $value ) ) {
				continue;
			}
			$primary[] = array(
				'key'     => $key,
				'label'   => $spec['label'],
				'widget'  => $spec['widget'],
				'options' => $spec['options'] ?? array(),
				'on'      => $spec['on'] ?? 'yes',
				'off'     => $spec['off'] ?? 'no',
				'value'   => (string) $value,
			);
		}

		$primary_keys = array_keys( $schema );
		$advanced     = array();
		foreach ( $settings as $key => $value ) {
			if ( in_array( $key, $internal, true ) || in_array( $key, $primary_keys, true ) ) {
				continue;
			}
			if ( is_array( $value ) || is_object( $value ) ) {
				continue; // Complex settings preserved but not exposed.
			}
			$value  = (string) $value;
			$widget = self::infer_widget( $key, $value );
			$advanced[] = array(
				'key'     => $key,
				'label'   => self::humanize( $key ),
				'widget'  => $widget,
				'options' => array(),
				'on'      => 'yes',
				'off'     => 'no',
				'value'   => $value,
			);
		}

		return array(
			'slug'     => $slug,
			'label'    => self::module_label( $slug ),
			'primary'  => $primary,
			'advanced' => $advanced,
		);
	}

	/**
	 * Guess a sensible widget for an arbitrary scalar setting.
	 *
	 * @param string $key
	 * @param string $value
	 * @return string widget name
	 */
	public static function infer_widget( $key, $value ) {
		$v = trim( (string) $value );

		if ( 'yes' === $v || 'no' === $v ) {
			return 'toggle';
		}
		if ( preg_match( '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $v ) ) {
			return 'color';
		}
		if ( preg_match( '/(link|url|href)/i', $key ) || preg_match( '#^https?://#i', $v ) ) {
			return 'url';
		}
		if ( '' !== $v && is_numeric( $v ) ) {
			return 'number';
		}
		if ( false !== strpos( $v, '<' ) ) {
			return 'richtext';
		}
		if ( mb_strlen( $v ) > 60 ) {
			return 'textarea';
		}
		return 'text';
	}

	/**
	 * Sanitize a posted value according to its widget.
	 *
	 * @param string $widget
	 * @param mixed  $raw
	 * @param array  $spec  descriptor (for select options / toggle tokens)
	 * @return string
	 */
	public static function sanitize_widget_value( $widget, $raw, $spec = array() ) {
		$raw = is_string( $raw ) ? $raw : '';

		switch ( $widget ) {
			case 'richtext':
				return wp_kses_post( $raw );
			case 'textarea':
				return sanitize_textarea_field( $raw );
			case 'url':
				return esc_url_raw( trim( $raw ) );
			case 'color':
				$c = sanitize_hex_color( trim( $raw ) );
				return $c ? $c : '';
			case 'number':
				return preg_replace( '/[^0-9.\-]/', '', $raw );
			case 'icon':
				// Font Awesome style class list: letters, numbers, spaces, dashes.
				return trim( preg_replace( '/[^a-zA-Z0-9 _-]/', '', $raw ) );
			case 'toggle':
				$on  = $spec['on'] ?? 'yes';
				$off = $spec['off'] ?? 'no';
				return ( (string) $raw === (string) $on ) ? $on : $off;
			case 'select':
				$options = $spec['options'] ?? array();
				return in_array( $raw, $options, true ) ? $raw : ( $options[0] ?? sanitize_text_field( $raw ) );
			case 'text':
			default:
				return sanitize_text_field( $raw );
		}
	}

	/**
	 * Write a whole set of settings back onto a module (published + draft),
	 * then clear cache.
	 *
	 * @param int    $post_id
	 * @param string $node_id
	 * @param array  $assoc  key => already-sanitized value
	 * @return true|WP_Error
	 */
	public static function update_module_settings( $post_id, $node_id, $assoc ) {
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
			foreach ( $assoc as $key => $value ) {
				$nodes[ $node_id ]->settings->$key = $value;
			}
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
	 * List every module on a page (for the admin "make whole module editable"
	 * screen), with a short human summary.
	 *
	 * @param int $post_id
	 * @return array<int,array> { node_id, slug, label, preview }
	 */
	public static function scan_modules( $post_id ) {
		$nodes = self::get_layout( $post_id, 'published' );
		$out   = array();
		if ( empty( $nodes ) ) {
			return $out;
		}
		foreach ( $nodes as $node_id => $node ) {
			if ( ! is_object( $node ) || ( $node->type ?? '' ) !== 'module' ) {
				continue;
			}
			$settings = $node->settings ?? null;
			if ( ! is_object( $settings ) ) {
				continue;
			}
			$slug    = isset( $settings->type ) ? (string) $settings->type : ( $node->slug ?? 'module' );
			$preview = self::module_preview( $slug, $settings );
			$out[]   = array(
				'node_id' => (string) $node_id,
				'slug'    => $slug,
				'label'   => self::module_label( $slug ),
				'preview' => $preview,
			);
		}
		return $out;
	}

	/**
	 * Best-effort short preview of a module's content.
	 *
	 * @param string $slug
	 * @param object $settings
	 * @return string
	 */
	public static function module_preview( $slug, $settings ) {
		$candidates = array( 'heading', 'title', 'text', 'caption', 'cta_text', 'html', 'icon' );
		foreach ( $candidates as $k ) {
			if ( isset( $settings->$k ) && is_string( $settings->$k ) ) {
				$p = trim( wp_strip_all_tags( $settings->$k ) );
				if ( '' !== $p ) {
					return mb_strlen( $p ) > 60 ? mb_substr( $p, 0, 60 ) . '…' : $p;
				}
			}
		}
		if ( isset( $settings->photo_src ) && is_string( $settings->photo_src ) && '' !== $settings->photo_src ) {
			return __( '(image)', 'mcm' );
		}
		return '';
	}

	/**
	 * Turn a setting key into a human label.
	 *
	 * @param string $key
	 * @return string
	 */
	public static function humanize( $key ) {
		return ucwords( str_replace( array( '-', '_' ), ' ', (string) $key ) );
	}

	/**
	 * The current image URL for a photo/image module setting, for preview.
	 *
	 * @param int    $post_id
	 * @param string $node_id
	 * @return string
	 */
	public static function module_image_src( $post_id, $node_id ) {
		$mod = self::get_module( $post_id, $node_id );
		if ( null === $mod ) {
			return '';
		}
		$s = $mod['settings'];
		if ( ! empty( $s['photo_src'] ) ) {
			return (string) $s['photo_src'];
		}
		if ( ! empty( $s['photo'] ) && is_numeric( $s['photo'] ) ) {
			$url = wp_get_attachment_url( (int) $s['photo'] );
			return $url ? $url : '';
		}
		return '';
	}
}
