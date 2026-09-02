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

/*
 * No class_exists() guard here on purpose. These classes declare no
 * parent, so PHP early-binds them at compile time - the guard would
 * already be true on the first load and skip the rest of the file.
 * Single loading is guaranteed by the require_once in the bootstrap,
 * which is also the only thing that can prevent a redeclaration
 * fatal, since early binding happens before any runtime check.
 */

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
		add_filter( 'fl_builder_enabled_modules', array( $this, 'force_enable_module' ) );
	}

	/**
	 * Keeps the module out of Beaver Builder's "enabled modules" blind
	 * spot. If a site has ever saved a restricted module list under
	 * Settings > Beaver Builder > Modules, modules registered later are
	 * not in that list and never appear in the editor - which looks
	 * exactly like the module failing to register. Harmless when the
	 * list is unrestricted, and a no-op on Beaver Builder versions that
	 * do not offer this filter.
	 *
	 * @param mixed $modules Enabled module slugs, per Beaver Builder.
	 * @return mixed
	 */
	public function force_enable_module( $modules ) {
		if ( ! is_array( $modules ) || in_array( 'wpcode-value', $modules, true ) ) {
			return $modules;
		}

		$modules[] = 'wpcode-value';

		return $modules;
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
