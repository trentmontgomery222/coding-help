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
	 * Reads this module instance's settings into a shortcode tag and a
	 * name => value map. Anything missing, blank or malformed is simply
	 * left out, so this always returns a usable structure.
	 *
	 * @return array{tag:string, values:array<string,string>}
	 */
	public function get_values() {
		$result = array(
			'tag'    => '',
			'values' => array(),
		);

		$settings = is_object( $this->settings ) ? $this->settings : new stdClass();

		$tag = isset( $settings->snippet_tag ) ? trim( (string) $settings->snippet_tag ) : '';

		// Tolerate someone pasting the whole shortcode, brackets and all.
		$tag = preg_replace( '/[^a-zA-Z0-9_\-]/', '', $tag );

		if ( '' === $tag ) {
			return $result;
		}

		$result['tag'] = $tag;

		$slots = defined( 'WPCODEBBV_SLOTS' ) ? (int) WPCODEBBV_SLOTS : 8;

		for ( $i = 1; $i <= $slots; $i++ ) {
			$name_key  = 'name_' . $i;
			$value_key = 'value_' . $i;

			$name = isset( $settings->{$name_key} ) ? trim( (string) $settings->{$name_key} ) : '';

			// Shortcode attribute names cannot contain spaces, so turn
			// a name typed as "Button Text" into "button_text" rather
			// than silently mangling it to "buttontext".
			$name = preg_replace( '/[\s\-]+/', '_', $name );
			$name = function_exists( 'sanitize_key' ) ? sanitize_key( $name ) : strtolower( $name );

			if ( '' === $name ) {
				continue;
			}

			$result['values'][ $name ] = isset( $settings->{$value_key} ) ? (string) $settings->{$value_key} : '';
		}

		return $result;
	}

	/**
	 * Builds the shortcode string this module will run.
	 *
	 * @return string Empty string when there is no snippet tag set.
	 */
	public function get_shortcode() {
		$data = $this->get_values();

		if ( '' === $data['tag'] ) {
			return '';
		}

		$shortcode = '[' . $data['tag'];

		foreach ( $data['values'] as $name => $value ) {
			// Double quotes would end the attribute early, so they are
			// the one thing that has to be encoded here.
			$shortcode .= ' ' . $name . '="' . str_replace( '"', '&quot;', $value ) . '"';
		}

		return $shortcode . ']';
	}
}
