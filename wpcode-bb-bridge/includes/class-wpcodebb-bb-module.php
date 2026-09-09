<?php
/**
 * Registers the "WPCode Value" Beaver Builder module. The module has a
 * single top-level "Configuration" select field; the fields belonging
 * to each configuration are attached to it via Beaver Builder's native
 * field "toggle" mechanism, so the settings panel only shows the fields
 * relevant to whichever Configuration is selected.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'WPCodeBB_BB_Module', false ) ) {
	return;
}

class WPCodeBB_BB_Module {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'register' ), 20 );
	}

	public function register() {
		if ( ! class_exists( 'FLBuilder' ) || ! class_exists( 'FLBuilderModule' ) ) {
			return;
		}

		if ( ! is_callable( array( 'FLBuilder', 'register_module' ) ) ) {
			return;
		}

		$module_file = WPCODEBB_DIR . 'includes/modules/wpcode-value/wpcode-value.php';

		if ( ! file_exists( $module_file ) ) {
			return;
		}

		try {
			if ( ! class_exists( 'WPCodeBB_Value_Module', false ) ) {
				require_once $module_file;
			}

			if ( ! class_exists( 'WPCodeBB_Value_Module' ) ) {
				return;
			}

			FLBuilder::register_module(
				'WPCodeBB_Value_Module',
				array(
					'general' => array(
						'title'    => __( 'WPCode Value', 'wpcode-bb-bridge' ),
						'sections' => array(
							'general' => array(
								'title'  => '',
								'fields' => $this->build_fields(),
							),
						),
					),
				)
			);
		} catch ( \Throwable $e ) {
			wpcodebb_log_error( 'register BB module', $e );
		}
	}

	/**
	 * Converts our simple field schema into Beaver Builder field
	 * definitions, and wires them up as toggle targets on the
	 * "wpcode_config" select field.
	 */
	private function build_fields() {
		try {
			$configs = WPCodeBB_Config_CPT::get_configs();
		} catch ( \Throwable $e ) {
			wpcodebb_log_error( 'get_configs', $e );
			$configs = array();
		}

		if ( ! is_array( $configs ) ) {
			$configs = array();
		}

		$config_options = array(
			''         => __( '— Select a Configuration —', 'wpcode-bb-bridge' ),
			'__custom__' => __( '✎ Custom (type your own variables)', 'wpcode-bb-bridge' ),
		);
		$toggle = array(
			''           => array( 'fields' => array() ),
			'__custom__' => array( 'fields' => $this->custom_mode_fields() ),
		);

		foreach ( $configs as $config_id => $config ) {
			if ( ! is_array( $config ) || ! is_numeric( $config_id ) ) {
				continue;
			}

			$config_options[ $config_id ] = isset( $config['title'] ) && '' !== $config['title'] ? $config['title'] : sprintf( '#%d', (int) $config_id );

			$is_js_array = isset( $config['source_type'] ) && 'js_array' === $config['source_type'];
			$bb_fields   = $is_js_array ? $this->build_js_fields( $config ) : $this->build_shortcode_fields( $config );

			$toggle[ $config_id ] = array(
				'fields' => $bb_fields,
			);
		}

		return array(
			'wpcode_config' => array(
				'type'    => 'select',
				'label'   => __( 'WPCode Configuration', 'wpcode-bb-bridge' ),
				'default' => '',
				'options' => $config_options,
				'toggle'  => $toggle,
				'help'    => __( 'Pick a saved Configuration, or choose "Custom" to type your own variables for this one instance without setting anything up in advance. This panel only ever appears here while editing the page - it is never shown on the live site.', 'wpcode-bb-bridge' ),
			),
		);
	}

	/**
	 * Builds the BB fields for a shortcode-attributes-mode Configuration
	 * from its manually-defined field schema.
	 */
	private function build_shortcode_fields( $config ) {
		$bb_fields = array();
		$fields    = isset( $config['fields'] ) && is_array( $config['fields'] ) ? $config['fields'] : array();

		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || empty( $field['key'] ) ) {
				continue;
			}

			$bb_field = $this->convert_field( $field );

			if ( $bb_field ) {
				$bb_fields[ $field['key'] ] = $bb_field;
			}
		}

		if ( empty( $bb_fields ) ) {
			$bb_fields['_no_fields_notice'] = array(
				'type' => 'html',
				'html' => '<p>' . esc_html__( 'This Configuration has no editable fields yet. Add some from Configurations > edit this Configuration.', 'wpcode-bb-bridge' ) . '</p>',
			);
		}

		return $bb_fields;
	}

	/**
	 * Builds the BB fields for a JS-config-array-mode Configuration from
	 * the leaf paths the admin chose to expose. Field keys are derived
	 * from the dotted path via path_to_field_key() so a real "." never
	 * ends up as a raw BB field key (that would risk PHP's top-level
	 * dot-to-underscore quirk on form submission).
	 */
	private function build_js_fields( $config ) {
		$bb_fields = array();
		$fields    = isset( $config['js_fields'] ) && is_array( $config['js_fields'] ) ? $config['js_fields'] : array();

		$defaults = array();

		if ( class_exists( 'WPCodeBB_JS_Codec' ) && ! empty( $config['js_source'] ) ) {
			try {
				$parsed = WPCodeBB_JS_Codec::parse( $config['js_source'] );

				if ( ! empty( $parsed['ok'] ) ) {
					foreach ( WPCodeBB_JS_Codec::collect_leaves( $parsed['node'], 3 ) as $leaf ) {
						$defaults[ $leaf['path'] ] = $leaf['value'];
					}
				}
			} catch ( \Throwable $e ) {
				wpcodebb_log_error( 'build_js_fields parse', $e );
			}
		}

		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || empty( $field['path'] ) ) {
				continue;
			}

			$path    = $field['path'];
			$bb_key  = self::path_to_field_key( $path );
			$label   = ! empty( $field['label'] ) ? $field['label'] : $path;
			$default = isset( $defaults[ $path ] ) ? $defaults[ $path ] : '';
			$type    = isset( $field['type'] ) ? $field['type'] : 'text';

			switch ( $type ) {
				case 'color':
					$bb_fields[ $bb_key ] = array(
						'type'       => 'color',
						'label'      => $label,
						'default'    => ltrim( $default, '#' ),
						'show_reset' => true,
					);
					break;

				case 'checkbox':
					$bb_fields[ $bb_key ] = array(
						'type'    => 'select',
						'label'   => $label,
						'default' => ( 'true' === strtolower( trim( $default ) ) ) ? 'true' : 'false',
						'options' => array(
							'true'  => __( 'Yes', 'wpcode-bb-bridge' ),
							'false' => __( 'No', 'wpcode-bb-bridge' ),
						),
					);
					break;

				case 'textarea_list':
					$bb_fields[ $bb_key ] = array(
						'type'    => 'textarea',
						'label'   => $label,
						'default' => $default,
						'rows'    => 3,
						'help'    => __( 'One value per line.', 'wpcode-bb-bridge' ),
					);
					break;

				case 'text':
				default:
					$bb_fields[ $bb_key ] = array(
						'type'    => 'text',
						'label'   => $label,
						'default' => $default,
					);
			}
		}

		if ( empty( $bb_fields ) ) {
			$bb_fields['_no_js_fields_notice'] = array(
				'type' => 'html',
				'html' => '<p>' . esc_html__( 'No fields are exposed for this Configuration yet. Edit it, check some boxes under "Detected fields", then Update.', 'wpcode-bb-bridge' ) . '</p>',
			);
		}

		return $bb_fields;
	}

	/**
	 * Deterministically turns a dotted leaf path (e.g.
	 * "noSchoolEvent.primaryColor") into a safe Beaver Builder field
	 * key. Used identically when registering the field and when reading
	 * it back at render time, so no reverse mapping is needed.
	 */
	public static function path_to_field_key( $path ) {
		return 'jsf__' . preg_replace( '/[^A-Za-z0-9_]/', '_', (string) $path );
	}

	/**
	 * Fields shown for the "Custom" mode: a shortcode tag plus a free-
	 * form, code-editor-style textbox where the admin can type whatever
	 * variables the snippet needs, one per line, without having to
	 * predefine a Configuration first.
	 */
	private function custom_mode_fields() {
		return array(
			'custom_shortcode_tag' => array(
				'type'    => 'text',
				'label'   => __( 'Shortcode Tag', 'wpcode-bb-bridge' ),
				'default' => '',
				'help'    => __( 'The shortcode tag from your WPCode snippet (WPCode > your snippet > Insertion > Shortcode), without the brackets. Example: wpcode_snippet_123', 'wpcode-bb-bridge' ),
			),
			'custom_variables'     => array(
				'type'    => 'code',
				'editor'  => 'html',
				'label'   => __( 'Variables', 'wpcode-bb-bridge' ),
				'default' => '',
				'help'    => __( 'One variable per line, as key = value. Lines starting with # are ignored. These become $atts[\'key\'] in your snippet (shortcode attributes) and are also available as $GLOBALS[\'wpcode_bb_values\'][\'key\'].', 'wpcode-bb-bridge' ),
			),
		);
	}

	/**
	 * @param array $field Our internal field schema (key, label, type, default, options, help).
	 * @return array|null Beaver Builder field definition, or null if the type is unsupported.
	 */
	private function convert_field( $field ) {
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

		$label = $field['label'] ? $field['label'] : $field['key'];
		$help  = ! empty( $field['help'] ) ? $field['help'] : '';

		switch ( $field['type'] ) {
			case 'textarea':
				return array(
					'type'    => 'textarea',
					'label'   => $label,
					'default' => $field['default'],
					'help'    => $help,
					'rows'    => 4,
				);

			case 'number':
				return array(
					'type'    => 'text',
					'label'   => $label,
					'default' => $field['default'],
					'help'    => $help,
					'class'   => 'wpcodebb-number-field',
				);

			case 'color':
				return array(
					'type'    => 'color',
					'label'   => $label,
					'default' => ltrim( $field['default'], '#' ),
					'help'    => $help,
					'show_reset' => true,
				);

			case 'url':
				return array(
					'type'    => 'link',
					'label'   => $label,
					'default' => $field['default'],
					'help'    => $help,
				);

			case 'image':
				return array(
					'type'    => 'photo',
					'label'   => $label,
					'help'    => $help,
				);

			case 'select':
				$options = array();

				foreach ( explode( ',', (string) $field['options'] ) as $option ) {
					$option = trim( $option );

					if ( '' !== $option ) {
						$options[ $option ] = $option;
					}
				}

				if ( empty( $options ) ) {
					$options[''] = __( '(no options configured)', 'wpcode-bb-bridge' );
				}

				return array(
					'type'    => 'select',
					'label'   => $label,
					'default' => $field['default'],
					'options' => $options,
					'help'    => $help,
				);

			case 'checkbox':
				return array(
					'type'    => 'select',
					'label'   => $label,
					'default' => $field['default'] ? 'yes' : 'no',
					'options' => array(
						'yes' => __( 'Yes', 'wpcode-bb-bridge' ),
						'no'  => __( 'No', 'wpcode-bb-bridge' ),
					),
					'help'    => $help,
				);

			case 'wysiwyg':
				return array(
					'type'    => 'editor',
					'label'   => $label,
					'default' => $field['default'],
					'help'    => $help,
				);

			case 'text':
			default:
				return array(
					'type'    => 'text',
					'label'   => $label,
					'default' => $field['default'],
					'help'    => $help,
				);
		}
	}
}
