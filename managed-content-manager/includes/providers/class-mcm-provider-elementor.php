<?php
/**
 * Elementor provider.
 *
 * Elementor stores a page as a JSON element tree in the `_elementor_data` post
 * meta; each widget has an `id` (also emitted in the DOM as data-id) and a
 * `settings` object. That mirrors Beaver Builder closely, so Elementor also
 * gets true click-on-the-page editing.
 *
 * @package mcm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCM_Provider_Elementor extends MCM_Provider {

	public function key() {
		return 'elementor';
	}

	public function name() {
		return __( 'Elementor', 'mcm' );
	}

	public function is_active() {
		return did_action( 'elementor/loaded' ) || class_exists( '\Elementor\Plugin' );
	}

	public function handles_post( $post_id ) {
		if ( 'builder' === (string) get_post_meta( $post_id, '_elementor_edit_mode', true ) ) {
			return true;
		}
		return ! empty( get_post_meta( $post_id, '_elementor_data', true ) );
	}

	public function get_pages() {
		$q = new WP_Query(
			array(
				'post_type'      => array( 'page', 'post' ),
				'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'     => '_elementor_edit_mode',
						'value'   => 'builder',
						'compare' => '=',
					),
				),
			)
		);
		return $q->posts;
	}

	public function supports_inplace() {
		return true;
	}

	public function dom_selector() {
		return '.elementor-widget[data-id]';
	}

	public function dom_id_attr() {
		return 'data-id';
	}

	/**
	 * Curated editable fields per Elementor widget type. Keys may be dot paths
	 * into the settings object (e.g. link.url).
	 *
	 * @param string $widget_type
	 * @return array<int,array> { key, label, widget }
	 */
	private function schema( $widget_type ) {
		$map = array(
			'heading'     => array(
				array( 'key' => 'title', 'label' => __( 'Heading text', 'mcm' ), 'widget' => 'text' ),
				array( 'key' => 'link.url', 'label' => __( 'Link URL', 'mcm' ), 'widget' => 'url' ),
			),
			'text-editor' => array(
				array( 'key' => 'editor', 'label' => __( 'Text', 'mcm' ), 'widget' => 'richtext' ),
			),
			'button'      => array(
				array( 'key' => 'text', 'label' => __( 'Button text', 'mcm' ), 'widget' => 'text' ),
				array( 'key' => 'link.url', 'label' => __( 'Link URL', 'mcm' ), 'widget' => 'url' ),
			),
			'image'       => array(
				array( 'key' => 'image', 'label' => __( 'Image', 'mcm' ), 'widget' => 'image' ),
				array( 'key' => 'caption', 'label' => __( 'Caption', 'mcm' ), 'widget' => 'text' ),
			),
			'icon'        => array(
				array( 'key' => 'selected_icon.value', 'label' => __( 'Icon', 'mcm' ), 'widget' => 'icon' ),
			),
			'icon-box'    => array(
				array( 'key' => 'title_text', 'label' => __( 'Title', 'mcm' ), 'widget' => 'text' ),
				array( 'key' => 'description_text', 'label' => __( 'Text', 'mcm' ), 'widget' => 'richtext' ),
				array( 'key' => 'selected_icon.value', 'label' => __( 'Icon', 'mcm' ), 'widget' => 'icon' ),
				array( 'key' => 'link.url', 'label' => __( 'Link URL', 'mcm' ), 'widget' => 'url' ),
			),
			'image-box'   => array(
				array( 'key' => 'title_text', 'label' => __( 'Title', 'mcm' ), 'widget' => 'text' ),
				array( 'key' => 'description_text', 'label' => __( 'Text', 'mcm' ), 'widget' => 'richtext' ),
				array( 'key' => 'image', 'label' => __( 'Image', 'mcm' ), 'widget' => 'image' ),
			),
			'testimonial' => array(
				array( 'key' => 'testimonial_content', 'label' => __( 'Testimonial', 'mcm' ), 'widget' => 'richtext' ),
				array( 'key' => 'testimonial_name', 'label' => __( 'Name', 'mcm' ), 'widget' => 'text' ),
				array( 'key' => 'testimonial_job', 'label' => __( 'Role', 'mcm' ), 'widget' => 'text' ),
			),
		);
		return isset( $map[ $widget_type ] ) ? $map[ $widget_type ] : array();
	}

	public function list_nodes( $post_id ) {
		$tree = $this->load_tree( $post_id );
		$out  = array();
		$this->walk(
			$tree,
			function ( $el ) use ( &$out ) {
				if ( 'widget' !== ( $el['elType'] ?? '' ) ) {
					return;
				}
				$type = $el['widgetType'] ?? '';
				if ( empty( $this->schema( $type ) ) ) {
					return;
				}
				$out[] = array(
					'node_id' => (string) ( $el['id'] ?? '' ),
					'label'   => $this->widget_label( $type ),
					'preview' => $this->preview( $el ),
				);
			}
		);
		return $out;
	}

	public function describe_node( $post_id, $node_id ) {
		$el = $this->find( $this->load_tree( $post_id ), $node_id );
		if ( null === $el ) {
			return new WP_Error( 'mcm_el_node', __( 'That Elementor widget could not be found.', 'mcm' ) );
		}
		$type     = $el['widgetType'] ?? '';
		$settings = isset( $el['settings'] ) && is_array( $el['settings'] ) ? $el['settings'] : array();

		$primary = array();
		foreach ( $this->schema( $type ) as $spec ) {
			$value = '';
			if ( 'image' === $spec['widget'] ) {
				$img   = $this->get_path( $settings, $spec['key'] );
				$value = is_array( $img ) && isset( $img['url'] ) ? $img['url'] : '';
			} else {
				$value = (string) $this->get_path( $settings, $spec['key'] );
			}
			$primary[] = array(
				'key'     => $spec['key'],
				'label'   => $spec['label'],
				'widget'  => $spec['widget'],
				'options' => array(),
				'on'      => 'yes',
				'off'     => 'no',
				'value'   => $value,
			);
		}

		return array(
			'slug'     => $type,
			'label'    => $this->widget_label( $type ),
			'primary'  => $primary,
			'advanced' => array(),
		);
	}

	public function update_node( $post_id, $node_id, $assoc ) {
		$tree    = $this->load_tree( $post_id );
		$updated = $this->walk_update(
			$tree,
			$node_id,
			function ( &$el ) use ( $assoc ) {
				if ( ! isset( $el['settings'] ) || ! is_array( $el['settings'] ) ) {
					$el['settings'] = array();
				}
				foreach ( $assoc as $key => $value ) {
					if ( is_array( $value ) && isset( $value['id'], $value['url'] ) ) {
						// Image field: Elementor stores { id, url }.
						$this->set_path( $el['settings'], $key, array( 'id' => (int) $value['id'], 'url' => $value['url'] ) );
					} else {
						$this->set_path( $el['settings'], $key, $value );
					}
				}
			}
		);

		if ( ! $updated ) {
			return new WP_Error( 'mcm_el_node', __( 'That Elementor widget could not be found. It may have been deleted.', 'mcm' ) );
		}

		$this->save_tree( $post_id, $tree );
		return true;
	}

	public function node_image_src( $post_id, $node_id ) {
		$el = $this->find( $this->load_tree( $post_id ), $node_id );
		if ( null === $el || empty( $el['settings'] ) ) {
			return '';
		}
		foreach ( $this->schema( $el['widgetType'] ?? '' ) as $spec ) {
			if ( 'image' === $spec['widget'] ) {
				$img = $this->get_path( $el['settings'], $spec['key'] );
				if ( is_array( $img ) && ! empty( $img['url'] ) ) {
					return (string) $img['url'];
				}
			}
		}
		return '';
	}

	// -----------------------------------------------------------------------
	// Tree helpers
	// -----------------------------------------------------------------------

	private function load_tree( $post_id ) {
		$raw = get_post_meta( absint( $post_id ), '_elementor_data', true );
		if ( empty( $raw ) ) {
			return array();
		}
		if ( is_array( $raw ) ) {
			return $raw;
		}
		$data = json_decode( $raw, true );
		if ( null === $data ) {
			$data = json_decode( wp_unslash( $raw ), true );
		}
		return is_array( $data ) ? $data : array();
	}

	private function save_tree( $post_id, $tree ) {
		// Elementor stores the JSON slashed; match that so it reads it back.
		update_post_meta( absint( $post_id ), '_elementor_data', wp_slash( wp_json_encode( $tree ) ) );

		// Force Elementor to regenerate the page CSS.
		delete_post_meta( absint( $post_id ), '_elementor_css' );
		if ( class_exists( '\Elementor\Plugin' ) ) {
			$plugin = \Elementor\Plugin::$instance;
			if ( isset( $plugin->files_manager ) && method_exists( $plugin->files_manager, 'clear_cache' ) ) {
				$plugin->files_manager->clear_cache();
			}
		}
		clean_post_cache( absint( $post_id ) );
	}

	/**
	 * Depth-first read-only walk.
	 *
	 * @param array    $elements
	 * @param callable $cb receives each element array
	 */
	private function walk( $elements, $cb ) {
		if ( ! is_array( $elements ) ) {
			return;
		}
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$cb( $el );
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$this->walk( $el['elements'], $cb );
			}
		}
	}

	/**
	 * @param array  $elements
	 * @param string $id
	 * @return array|null element (copy)
	 */
	private function find( $elements, $id ) {
		$found = null;
		$this->walk(
			$elements,
			function ( $el ) use ( &$found, $id ) {
				if ( null === $found && (string) ( $el['id'] ?? '' ) === (string) $id ) {
					$found = $el;
				}
			}
		);
		return $found;
	}

	/**
	 * Depth-first walk that mutates the matching element in place.
	 *
	 * @param array    $elements  by reference
	 * @param string   $id
	 * @param callable $mutate    receives &$element
	 * @return bool found
	 */
	private function walk_update( &$elements, $id, $mutate ) {
		if ( ! is_array( $elements ) ) {
			return false;
		}
		foreach ( $elements as &$el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			if ( (string) ( $el['id'] ?? '' ) === (string) $id ) {
				$mutate( $el );
				return true;
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				if ( $this->walk_update( $el['elements'], $id, $mutate ) ) {
					return true;
				}
			}
		}
		return false;
	}

	private function get_path( $arr, $path ) {
		$parts = explode( '.', $path );
		$cur   = $arr;
		foreach ( $parts as $p ) {
			if ( is_array( $cur ) && array_key_exists( $p, $cur ) ) {
				$cur = $cur[ $p ];
			} else {
				return '';
			}
		}
		return $cur;
	}

	private function set_path( &$arr, $path, $value ) {
		$parts = explode( '.', $path );
		$cur    = &$arr;
		foreach ( $parts as $i => $p ) {
			if ( $i === count( $parts ) - 1 ) {
				$cur[ $p ] = $value;
			} else {
				if ( ! isset( $cur[ $p ] ) || ! is_array( $cur[ $p ] ) ) {
					$cur[ $p ] = array();
				}
				$cur = &$cur[ $p ];
			}
		}
		unset( $cur );
	}

	private function widget_label( $type ) {
		return ucwords( str_replace( array( '-', '_' ), ' ', (string) $type ) );
	}

	private function preview( $el ) {
		$settings = $el['settings'] ?? array();
		foreach ( array( 'title', 'text', 'editor', 'title_text', 'testimonial_name', 'testimonial_content', 'caption' ) as $k ) {
			if ( ! empty( $settings[ $k ] ) && is_string( $settings[ $k ] ) ) {
				$p = trim( wp_strip_all_tags( $settings[ $k ] ) );
				if ( '' !== $p ) {
					return mb_strlen( $p ) > 60 ? mb_substr( $p, 0, 60 ) . '…' : $p;
				}
			}
		}
		if ( ! empty( $settings['image']['url'] ) ) {
			return __( '(image)', 'mcm' );
		}
		return '';
	}
}
