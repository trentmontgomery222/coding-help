<?php
/**
 * The Beaver Builder module class itself. Field registration happens
 * externally in WPCodeBB_BB_Module::register(), since the field list
 * depends on the Configurations an admin has defined.
 *
 * This file is only ever require()'d after confirming FLBuilderModule
 * exists (see WPCodeBB_BB_Module::register()), so extending it here is
 * safe.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'WPCodeBB_Value_Module', false ) || ! class_exists( 'FLBuilderModule' ) ) {
	return;
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
	 * Fully defensive: bad/missing/corrupted data always falls back to
	 * an empty result rather than a notice or error.
	 *
	 * @return array{tag:string|null, atts:array, values:array}
	 */
	public function get_render_data() {
		$empty = array(
			'tag'    => null,
			'atts'   => array(),
			'values' => array(),
		);

		if ( ! class_exists( 'WPCodeBB_Config_CPT' ) ) {
			return $empty;
		}

		$config_id = isset( $this->settings->wpcode_config ) ? $this->settings->wpcode_config : '';

		if ( ! $config_id ) {
			return $empty;
		}

		try {
			$configs = WPCodeBB_Config_CPT::get_configs();
		} catch ( \Throwable $e ) {
			return $empty;
		}

		if ( ! is_array( $configs ) || empty( $configs[ $config_id ] ) || ! is_array( $configs[ $config_id ] ) ) {
			return $empty;
		}

		$config = $configs[ $config_id ];
		$tag    = isset( $config['shortcode_tag'] ) ? $config['shortcode_tag'] : '';
		$fields = isset( $config['fields'] ) && is_array( $config['fields'] ) ? $config['fields'] : array();

		if ( ! $tag ) {
			return $empty;
		}

		$atts   = array();
		$values = array();

		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || empty( $field['key'] ) ) {
				continue;
			}

			$key     = $field['key'];
			$type    = isset( $field['type'] ) ? $field['type'] : 'text';
			$default = isset( $field['default'] ) ? $field['default'] : '';
			$val     = isset( $this->settings->{$key} ) ? $this->settings->{$key} : $default;

			if ( 'checkbox' === $type ) {
				$val = ( 'yes' === $val ) ? '1' : '0';
			}

			if ( 'color' === $type && $val && '#' !== substr( (string) $val, 0, 1 ) ) {
				$val = '#' . $val;
			}

			$atts[ $key ]   = $val;
			$values[ $key ] = $val;
		}

		return array(
			'tag'    => $tag,
			'atts'   => $atts,
			'values' => $values,
		);
	}
}
