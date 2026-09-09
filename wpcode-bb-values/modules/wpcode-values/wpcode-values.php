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
	 * The WPCode snippet ID this module renders.
	 *
	 * @return int Zero when nothing is set.
	 */
	public function get_snippet_id() {
		$settings = is_object( $this->settings ) ? $this->settings : new stdClass();
		$raw      = isset( $settings->wpcode_id ) ? (string) $settings->wpcode_id : '';

		// Tolerate a pasted [wpcode id="123"] or a bare number.
		if ( preg_match( '/(\d+)/', $raw, $match ) ) {
			return (int) $match[1];
		}

		return 0;
	}

	/**
	 * The shortcode this module runs.
	 *
	 * @return string Empty when no snippet ID is set.
	 */
	public function get_shortcode() {
		$id = $this->get_snippet_id();

		return $id > 0 ? '[wpcode id="' . $id . '"]' : '';
	}

	/**
	 * The overrides to write into the snippet's configurations array, as
	 * path => value.
	 *
	 * Every setting of the chosen snippet has a field, pre-filled with
	 * the value the snippet itself uses, so normally all of them are
	 * sent and the ones nobody touched simply write back what was
	 * already there. Clearing a box removes that override, which lets
	 * the snippet's own value through again.
	 *
	 * @return array<string, string>
	 */
	public function get_overrides() {
		$overrides = array();
		$settings  = is_object( $this->settings ) ? $this->settings : new stdClass();
		$id        = $this->get_snippet_id();

		if ( $id > 0 && function_exists( 'wpcodebbv_snippets' ) ) {
			$snippets = array();
			$globals  = array();

			try {
				$snippets = wpcodebbv_snippets();
				$globals  = wpcodebbv_globals_for( $id );
			} catch ( \Throwable $e ) {
				$snippets = array();
				$globals  = array();
			}

			if ( isset( $snippets[ $id ]['settings'] ) && is_array( $snippets[ $id ]['settings'] ) ) {
				foreach ( $snippets[ $id ]['settings'] as $path => $leaf ) {
					$key     = wpcodebbv_field_key( $id, $path );
					$snippet = (string) $leaf['value'];
					$stored  = isset( $settings->{$key} ) ? (string) $settings->{$key} : '';

					/*
					 * Precedence, narrowest wins:
					 *
					 *   1. what this module's box says, if it has been
					 *      changed away from the snippet's own value
					 *   2. the site-wide value, if one is set
					 *   3. the value written in the snippet
					 *
					 * The box is pre-filled with the snippet's own value,
					 * so "still equal to it" is what tells a page that was
					 * never edited apart from one that was. That is what
					 * lets a site-wide value keep reaching pages nobody
					 * has touched, while a page that was edited keeps its
					 * own value.
					 */
					if ( '' !== trim( $stored ) && $stored !== $snippet ) {
						$value = $stored;
					} elseif ( isset( $globals[ $path ] ) && '' !== (string) $globals[ $path ] ) {
						$value = (string) $globals[ $path ];
					} else {
						continue; // Nothing to change - leave the snippet alone.
					}

					if ( $value !== $snippet ) {
						$overrides[ $path ] = $value;
					}
				}
			}
		}

		if ( isset( $settings->custom_settings ) && function_exists( 'wpcodebbv_parse_lines' ) ) {
			foreach ( wpcodebbv_parse_lines( (string) $settings->custom_settings ) as $path => $value ) {
				$overrides[ $path ] = $value;
			}
		}

		return $overrides;
	}
}
