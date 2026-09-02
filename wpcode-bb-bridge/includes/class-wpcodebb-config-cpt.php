<?php
/**
 * Registers the "WPCode BB Configuration" post type, which is where an
 * admin maps a WPCode snippet's shortcode tag to a schema of editable
 * fields. Each published configuration becomes one selectable option
 * inside the Beaver Builder module.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPCodeBB_Config_CPT {

	/** @var self */
	private static $instance = null;

	/** Field types we know how to render, both in wp-admin and in Beaver Builder. */
	public static $field_types = array();

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		self::$field_types = array(
			'text'     => __( 'Text', 'wpcode-bb-bridge' ),
			'textarea' => __( 'Textarea', 'wpcode-bb-bridge' ),
			'number'   => __( 'Number', 'wpcode-bb-bridge' ),
			'color'    => __( 'Color', 'wpcode-bb-bridge' ),
			'url'      => __( 'URL / Link', 'wpcode-bb-bridge' ),
			'image'    => __( 'Image', 'wpcode-bb-bridge' ),
			'select'   => __( 'Select (dropdown)', 'wpcode-bb-bridge' ),
			'checkbox' => __( 'Yes / No', 'wpcode-bb-bridge' ),
			'wysiwyg'  => __( 'Rich text', 'wpcode-bb-bridge' ),
		);

		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_' . WPCODEBB_CPT, array( $this, 'save_meta' ), 10, 2 );
		add_filter( 'manage_' . WPCODEBB_CPT . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . WPCODEBB_CPT . '_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function register_post_type() {
		$labels = array(
			'name'               => __( 'WPCode BB Configs', 'wpcode-bb-bridge' ),
			'singular_name'      => __( 'Configuration', 'wpcode-bb-bridge' ),
			'add_new'            => __( 'Add New', 'wpcode-bb-bridge' ),
			'add_new_item'       => __( 'Add New Configuration', 'wpcode-bb-bridge' ),
			'edit_item'          => __( 'Edit Configuration', 'wpcode-bb-bridge' ),
			'new_item'           => __( 'New Configuration', 'wpcode-bb-bridge' ),
			'view_item'          => __( 'View Configuration', 'wpcode-bb-bridge' ),
			'search_items'       => __( 'Search Configurations', 'wpcode-bb-bridge' ),
			'not_found'          => __( 'No configurations found. Add one to expose a WPCode snippet inside Beaver Builder.', 'wpcode-bb-bridge' ),
			'not_found_in_trash' => __( 'No configurations found in Trash.', 'wpcode-bb-bridge' ),
			'menu_name'          => __( 'WPCode BB Configs', 'wpcode-bb-bridge' ),
		);

		register_post_type(
			WPCODEBB_CPT,
			array(
				'labels'              => $labels,
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_admin_bar'   => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'capability_type'     => 'page',
				'map_meta_cap'        => true,
				'hierarchical'        => false,
				'supports'            => array( 'title' ),
				'menu_icon'           => 'dashicons-editor-code',
				'menu_position'       => 58,
			)
		);
	}

	public function enqueue( $hook ) {
		global $post_type;

		if ( WPCODEBB_CPT !== $post_type ) {
			return;
		}

		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		wp_enqueue_style( 'wpcodebb-admin', WPCODEBB_URL . 'assets/admin.css', array(), WPCODEBB_VERSION );
		wp_enqueue_script( 'wpcodebb-admin', WPCODEBB_URL . 'assets/admin.js', array( 'jquery', 'wp-util', 'jquery-ui-sortable' ), WPCODEBB_VERSION, true );
		wp_localize_script(
			'wpcodebb-admin',
			'WPCodeBBAdmin',
			array(
				'fieldTypes' => self::$field_types,
				'snippets'   => $this->get_detected_snippets(),
				'i18n'       => array(
					'confirmRemove' => __( 'Remove this field?', 'wpcode-bb-bridge' ),
					'newFieldKey'   => __( 'new_field', 'wpcode-bb-bridge' ),
					'newFieldLabel' => __( 'New Field', 'wpcode-bb-bridge' ),
				),
			)
		);
	}

	public function add_meta_boxes() {
		add_meta_box(
			'wpcodebb_shortcode',
			__( 'WPCode Snippet', 'wpcode-bb-bridge' ),
			array( $this, 'render_shortcode_box' ),
			WPCODEBB_CPT,
			'normal',
			'high'
		);

		add_meta_box(
			'wpcodebb_fields',
			__( 'Editable Fields', 'wpcode-bb-bridge' ),
			array( $this, 'render_fields_box' ),
			WPCODEBB_CPT,
			'normal',
			'high'
		);
	}

	/**
	 * Attempts to detect published WPCode snippets that use the
	 * "Shortcode" insertion method, so the admin can pick one instead
	 * of typing the tag by hand. This is a convenience only - WPCode's
	 * internal structure is not a stable public API, so this fails
	 * silently (returns an empty array) if it can't find anything.
	 *
	 * @return array<int, array{tag:string, title:string}>
	 */
	public function get_detected_snippets() {
		if ( ! post_type_exists( 'wpcode' ) ) {
			return array();
		}

		$snippets = get_posts(
			array(
				'post_type'      => 'wpcode',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			)
		);

		$results = array();

		foreach ( $snippets as $snippet ) {
			$insert_method = get_post_meta( $snippet->ID, 'wpcode_auto_insert_method', true );

			// Only surface snippets that are actually usable as a shortcode.
			if ( $insert_method && 'shortcode' !== $insert_method ) {
				continue;
			}

			$custom_tag = get_post_meta( $snippet->ID, 'wpcode_shortcode_tag', true );
			$tag        = $custom_tag ? $custom_tag : 'wpcode_snippet_' . $snippet->ID;

			$results[] = array(
				'tag'   => $tag,
				'title' => $snippet->post_title ? $snippet->post_title : $tag,
			);
		}

		return $results;
	}

	public function render_shortcode_box( $post ) {
		wp_nonce_field( 'wpcodebb_save', 'wpcodebb_nonce' );

		$tag      = get_post_meta( $post->ID, '_wpcodebb_shortcode_tag', true );
		$snippets = $this->get_detected_snippets();
		?>
		<p>
			<label for="wpcodebb_shortcode_tag"><strong><?php esc_html_e( 'Shortcode tag', 'wpcode-bb-bridge' ); ?></strong></label><br />
			<input type="text" id="wpcodebb_shortcode_tag" name="wpcodebb_shortcode_tag" class="widefat" value="<?php echo esc_attr( $tag ); ?>" placeholder="wpcode_snippet_123" />
		</p>
		<?php if ( ! empty( $snippets ) ) : ?>
			<p>
				<label for="wpcodebb_detected_snippet"><?php esc_html_e( 'Or pick a detected WPCode snippet:', 'wpcode-bb-bridge' ); ?></label><br />
				<select id="wpcodebb_detected_snippet">
					<option value=""><?php esc_html_e( '— Select a snippet —', 'wpcode-bb-bridge' ); ?></option>
					<?php foreach ( $snippets as $snippet ) : ?>
						<option value="<?php echo esc_attr( $snippet['tag'] ); ?>"><?php echo esc_html( $snippet['title'] ); ?> (<?php echo esc_html( $snippet['tag'] ); ?>)</option>
					<?php endforeach; ?>
				</select>
			</p>
		<?php else : ?>
			<p class="description">
				<?php esc_html_e( 'No WPCode shortcode snippets were detected. Make sure WPCode is active and at least one snippet uses "Shortcode" as its insertion method, or just type the tag manually above.', 'wpcode-bb-bridge' ); ?>
			</p>
		<?php endif; ?>
		<p class="description">
			<?php esc_html_e( 'This is the shortcode tag WPCode generated for your snippet (Code Snippets > your snippet > Insertion > Shortcode). The values below will be passed to it as shortcode attributes when the Beaver Builder module renders.', 'wpcode-bb-bridge' ); ?>
		</p>
		<?php
	}

	public function render_fields_box( $post ) {
		$fields = get_post_meta( $post->ID, '_wpcodebb_fields', true );

		if ( ! is_array( $fields ) ) {
			$fields = array();
		}
		?>
		<p class="description">
			<?php esc_html_e( 'Define the values a page editor should be able to change from inside Beaver Builder. Each field becomes a shortcode attribute (accessible in your snippet as $atts[\'key\']) and is also available as $GLOBALS[\'wpcode_bb_values\'][\'key\'].', 'wpcode-bb-bridge' ); ?>
		</p>
		<table class="widefat wpcodebb-fields-table" id="wpcodebb-fields-table">
			<thead>
				<tr>
					<th style="width:16%"><?php esc_html_e( 'Key', 'wpcode-bb-bridge' ); ?></th>
					<th style="width:18%"><?php esc_html_e( 'Label', 'wpcode-bb-bridge' ); ?></th>
					<th style="width:14%"><?php esc_html_e( 'Type', 'wpcode-bb-bridge' ); ?></th>
					<th style="width:16%"><?php esc_html_e( 'Default value', 'wpcode-bb-bridge' ); ?></th>
					<th style="width:16%"><?php esc_html_e( 'Options (for Select, comma separated)', 'wpcode-bb-bridge' ); ?></th>
					<th><?php esc_html_e( 'Help text', 'wpcode-bb-bridge' ); ?></th>
					<th style="width:24px;"></th>
				</tr>
			</thead>
			<tbody id="wpcodebb-fields-body">
				<?php
				if ( empty( $fields ) ) {
					$this->render_field_row( array() );
				} else {
					foreach ( $fields as $field ) {
						$this->render_field_row( $field );
					}
				}
				?>
			</tbody>
		</table>
		<p>
			<button type="button" class="button" id="wpcodebb-add-field"><?php esc_html_e( '+ Add Field', 'wpcode-bb-bridge' ); ?></button>
		</p>
		<script type="text/html" id="tmpl-wpcodebb-field-row">
			<?php $this->render_field_row( array(), '__INDEX__' ); ?>
		</script>
		<?php
	}

	private function render_field_row( $field, $index = null ) {
		static $auto_index = 0;

		if ( null === $index ) {
			$index = $auto_index++;
		}

		$field = wp_parse_args(
			$field,
			array(
				'key'     => '',
				'label'   => '',
				'type'    => 'text',
				'default' => '',
				'options' => '',
				'help'    => '',
			)
		);
		?>
		<tr class="wpcodebb-field-row">
			<td><input type="text" class="widefat" name="wpcodebb_fields[<?php echo esc_attr( $index ); ?>][key]" value="<?php echo esc_attr( $field['key'] ); ?>" placeholder="field_key" /></td>
			<td><input type="text" class="widefat" name="wpcodebb_fields[<?php echo esc_attr( $index ); ?>][label]" value="<?php echo esc_attr( $field['label'] ); ?>" placeholder="<?php esc_attr_e( 'Field Label', 'wpcode-bb-bridge' ); ?>" /></td>
			<td>
				<select class="widefat" name="wpcodebb_fields[<?php echo esc_attr( $index ); ?>][type]">
					<?php foreach ( self::$field_types as $type_key => $type_label ) : ?>
						<option value="<?php echo esc_attr( $type_key ); ?>" <?php selected( $field['type'], $type_key ); ?>><?php echo esc_html( $type_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td><input type="text" class="widefat" name="wpcodebb_fields[<?php echo esc_attr( $index ); ?>][default]" value="<?php echo esc_attr( $field['default'] ); ?>" /></td>
			<td><input type="text" class="widefat" name="wpcodebb_fields[<?php echo esc_attr( $index ); ?>][options]" value="<?php echo esc_attr( $field['options'] ); ?>" placeholder="<?php esc_attr_e( 'red, green, blue', 'wpcode-bb-bridge' ); ?>" /></td>
			<td><input type="text" class="widefat" name="wpcodebb_fields[<?php echo esc_attr( $index ); ?>][help]" value="<?php echo esc_attr( $field['help'] ); ?>" /></td>
			<td><button type="button" class="button-link wpcodebb-remove-field" title="<?php esc_attr_e( 'Remove field', 'wpcode-bb-bridge' ); ?>">&times;</button></td>
		</tr>
		<?php
	}

	public function save_meta( $post_id, $post ) {
		if ( ! isset( $_POST['wpcodebb_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['wpcodebb_nonce'] ), 'wpcodebb_save' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$tag = isset( $_POST['wpcodebb_shortcode_tag'] ) ? sanitize_text_field( wp_unslash( $_POST['wpcodebb_shortcode_tag'] ) ) : '';
		$tag = preg_replace( '/[^a-zA-Z0-9_\-]/', '', $tag );
		update_post_meta( $post_id, '_wpcodebb_shortcode_tag', $tag );

		$raw_fields    = isset( $_POST['wpcodebb_fields'] ) && is_array( $_POST['wpcodebb_fields'] ) ? wp_unslash( $_POST['wpcodebb_fields'] ) : array();
		$reserved_keys = array( 'id', 'type', 'node', 'parent', 'position', 'settings', 'template_id', 'template_node_id', 'panel', 'template_settings', 'animation', 'css_id', 'css_class', 'visibility_display', 'wpcode_config', 'class', 'style' );
		$clean_fields  = array();
		$seen_keys     = array();

		foreach ( $raw_fields as $row ) {
			$key = isset( $row['key'] ) ? sanitize_key( $row['key'] ) : '';

			if ( '' === $key || in_array( $key, $reserved_keys, true ) || isset( $seen_keys[ $key ] ) ) {
				continue;
			}

			$seen_keys[ $key ] = true;
			$type              = isset( $row['type'] ) && array_key_exists( $row['type'], self::$field_types ) ? $row['type'] : 'text';

			$clean_fields[] = array(
				'key'     => $key,
				'label'   => isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : $key,
				'type'    => $type,
				'default' => isset( $row['default'] ) ? sanitize_text_field( $row['default'] ) : '',
				'options' => isset( $row['options'] ) ? sanitize_text_field( $row['options'] ) : '',
				'help'    => isset( $row['help'] ) ? sanitize_text_field( $row['help'] ) : '',
			);
		}

		update_post_meta( $post_id, '_wpcodebb_fields', $clean_fields );
	}

	public function columns( $columns ) {
		$new = array();

		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;

			if ( 'title' === $key ) {
				$new['shortcode_tag'] = __( 'Shortcode Tag', 'wpcode-bb-bridge' );
				$new['field_count']   = __( 'Fields', 'wpcode-bb-bridge' );
			}
		}

		return $new;
	}

	public function render_column( $column, $post_id ) {
		if ( 'shortcode_tag' === $column ) {
			$tag = get_post_meta( $post_id, '_wpcodebb_shortcode_tag', true );
			echo $tag ? '<code>[' . esc_html( $tag ) . ']</code>' : '<em>' . esc_html__( 'not set', 'wpcode-bb-bridge' ) . '</em>';
		}

		if ( 'field_count' === $column ) {
			$fields = get_post_meta( $post_id, '_wpcodebb_fields', true );
			echo is_array( $fields ) ? (int) count( $fields ) : 0;
		}
	}

	/**
	 * Returns all published configurations as id => title, plus their
	 * shortcode tag and field schema, ready to feed into the Beaver
	 * Builder module registration.
	 *
	 * @return array<int, array{title:string, shortcode_tag:string, fields:array}>
	 */
	public static function get_configs() {
		$posts = get_posts(
			array(
				'post_type'      => WPCODEBB_CPT,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);

		$configs = array();

		foreach ( $posts as $post ) {
			$fields = get_post_meta( $post->ID, '_wpcodebb_fields', true );

			$configs[ $post->ID ] = array(
				'title'         => $post->post_title,
				'shortcode_tag' => get_post_meta( $post->ID, '_wpcodebb_shortcode_tag', true ),
				'fields'        => is_array( $fields ) ? $fields : array(),
			);
		}

		return $configs;
	}
}
