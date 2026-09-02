<?php
/**
 * The Beaver Builder module class itself. Field registration happens
 * externally in WPCodeBB_BB_Module::register(), since the field list
 * depends on the Configurations an admin has defined.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPCodeBB_Value_Module extends FLBuilderModule {

	public function __construct() {
		parent::__construct(
			array(
				'name'            => __( 'WPCode Value', 'wpcode-bb-bridge' ),
				'description'     => __( 'Renders a WPCode snippet with editable values.', 'wpcode-bb-bridge' ),
				'category'        => __( 'WPCode', 'wpcode-bb-bridge' ),
				'dir'             => WPCODEBB_DIR . 'includes/modules/wpcode-value/',
				'url'             => WPCODEBB_URL . 'includes/modules/wpcode-value/',
				'editor_export'   => true,
				'enabled'         => true,
				'partial_refresh' => true,
			)
		);
	}

	/**
	 * Builds the final shortcode string for this module instance based
	 * on its selected Configuration and the values the editor entered.
	 *
	 * @return array{tag:string|null, atts:array, values:array}
	 */
	public function get_render_data() {
		$config_id = isset( $this->settings->wpcode_config ) ? $this->settings->wpcode_config : '';

		if ( ! $config_id ) {
			return array(
				'tag'    => null,
				'atts'   => array(),
				'values' => array(),
			);
		}

		$configs = WPCodeBB_Config_CPT::get_configs();

		if ( empty( $configs[ $config_id ] ) ) {
			return array(
				'tag'    => null,
				'atts'   => array(),
				'values' => array(),
			);
		}

		$config = $configs[ $config_id ];
		$atts   = array();
		$values = array();

		foreach ( $config['fields'] as $field ) {
			$key = $field['key'];
			$val = isset( $this->settings->{$key} ) ? $this->settings->{$key} : $field['default'];

			if ( 'checkbox' === $field['type'] ) {
				$val = ( 'yes' === $val ) ? '1' : '0';
			}

			if ( 'color' === $field['type'] && $val && '#' !== substr( $val, 0, 1 ) ) {
				$val = '#' . $val;
			}

			$atts[ $key ]   = $val;
			$values[ $key ] = $val;
		}

		return array(
			'tag'    => $config['shortcode_tag'],
			'atts'   => $atts,
			'values' => $values,
		);
	}
}
