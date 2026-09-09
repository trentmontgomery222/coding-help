<?php
/**
 * The Beaver Builder module class.
 *
 * The field schema lives in wpcodebbv_form() in the main plugin file;
 * this class only turns the saved settings into a shortcode.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Only the parent check is meaningful here: PHP early-binds this class
 * at compile time whenever FLBuilderModule is already loaded, so a
 * "have I declared myself already?" test would be true on the first
 * load and useless. The require_once in wpcodebbv_register_module() is
 * what prevents a double load. When Beaver Builder is not loaded the
 * declaration cannot be early-bound, and this return stops it running,
 * so we never try to extend a class that is not there.
 */
if ( ! class_exists( 'FLBuilderModule' ) ) {
	return;
}

class WPCodeBBV_Module extends FLBuilderModule {

	public function __construct() {
		parent::__construct(
			array(
				'name'            => __( 'WPCode Values', 'wpcode-bb-values' ),
				'description'     => __( 'Runs a WPCode snippet with values you type here.', 'wpcode-bb-values' ),
				'category'        => __( 'WPCode', 'wpcode-bb-values' ),
				'dir'             => WPCODEBBV_DIR . 'modules/wpcode-values/',
				'url'             => WPCODEBBV_URL . 'modules/wpcode-values/',
				'partial_refresh' => true,
			)
		);
	}

	/**
	 * The overrides this module instance should apply, as
	 * path => value. Rows come first, then anything from the
	 * "Extra settings" box, which wins on a clash.
	 *
	 * @return array<string, string>
	 */
	public function get_overrides() {
		$overrides = array();
		$settings  = is_object( $this->settings ) ? $this->settings : new stdClass();
		$slots     = defined( 'WPCODEBBV_SLOTS' ) ? (int) WPCODEBBV_SLOTS : 12;

		for ( $i = 1; $i <= $slots; $i++ ) {
			$path_key  = 'setting_' . $i;
			$value_key = 'value_' . $i;

			$path = isset( $settings->{$path_key} ) ? trim( (string) $settings->{$path_key} ) : '';

			if ( '' === $path ) {
				continue;
			}

			$overrides[ $path ] = isset( $settings->{$value_key} ) ? (string) $settings->{$value_key} : '';
		}

		if ( isset( $settings->custom_settings ) && function_exists( 'wpcodebbv_parse_lines' ) ) {
			foreach ( wpcodebbv_parse_lines( (string) $settings->custom_settings ) as $path => $value ) {
				$overrides[ $path ] = $value;
			}
		}

		return $overrides;
	}

	/**
	 * The shortcode this module runs.
	 *
	 * @return string Empty when no snippet tag is set.
	 */
	public function get_shortcode() {
		$settings = is_object( $this->settings ) ? $this->settings : new stdClass();
		$tag      = isset( $settings->snippet_tag ) ? trim( (string) $settings->snippet_tag ) : '';

		// Tolerate someone pasting the whole shortcode, brackets and all.
		$tag = preg_replace( '/[^a-zA-Z0-9_\-]/', '', $tag );

		return '' === $tag ? '' : '[' . $tag . ']';
	}
}
