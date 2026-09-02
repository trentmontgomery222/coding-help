<?php
/**
 * The Beaver Builder module class itself. Field registration happens
 * externally in WPCodeBB_BB_Module::register(), since the field list
 * depends on the Configurations an admin has defined.
 *
 * This file is only ever require()'d after confirming FLBuilderModule
 * exists (see WPCodeBB_BB_Module::register()), so extending it here is
 * safe; the guard below is a second line of defence.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Only the parent check is meaningful here. PHP early-binds this class
 * at compile time whenever FLBuilderModule is already loaded, so a
 * "did I already declare myself?" test would be true on the first load
 * and is useless; the require_once in WPCodeBB_BB_Module::register()
 * is what prevents a double load. When Beaver Builder is NOT loaded,
 * the declaration cannot be early-bound, and this return stops it from
 * ever running - so we never try to extend a class that isn't there.
 */
if ( ! class_exists( 'FLBuilderModule' ) ) {
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

		$config_id = isset( $this->settings->wpcode_config ) ? $this->settings->wpcode_config : '';

		if ( ! $config_id ) {
			return $empty;
		}

		if ( '__custom__' === $config_id ) {
			return $this->get_custom_render_data( $empty );
		}

		if ( ! class_exists( 'WPCodeBB_Config_CPT' ) ) {
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

	/**
	 * Render data for "Custom" mode: a shortcode tag plus a free-form
	 * block of "key = value" lines typed directly into the module by
	 * whoever is editing the page - no pre-defined Configuration
	 * needed. Fully defensive: any unexpected input just yields fewer
	 * (or no) variables rather than an error.
	 *
	 * @param array $empty The empty fallback shape to return on failure.
	 * @return array{tag:string|null, atts:array, values:array}
	 */
	private function get_custom_render_data( $empty ) {
		$tag = isset( $this->settings->custom_shortcode_tag ) ? trim( (string) $this->settings->custom_shortcode_tag ) : '';
		$tag = preg_replace( '/[^a-zA-Z0-9_\-]/', '', $tag );

		if ( '' === $tag ) {
			return $empty;
		}

		$text = isset( $this->settings->custom_variables ) ? (string) $this->settings->custom_variables : '';

		try {
			$atts = self::parse_custom_variables( $text );
		} catch ( \Throwable $e ) {
			$atts = array();
		}

		return array(
			'tag'    => $tag,
			'atts'   => $atts,
			'values' => $atts,
		);
	}

	/**
	 * Parses "Variables" textarea content into a key => value array.
	 * Format, one per line:
	 *   key = value
	 *   # a comment line, ignored
	 * Blank lines and lines without an "=" are ignored. Values may
	 * optionally be wrapped in matching quotes.
	 *
	 * @param string $text
	 * @return array<string, string>
	 */
	public static function parse_custom_variables( $text ) {
		$atts = array();

		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			return $atts;
		}

		$reserved = array( '', 'id', 'type', 'node', 'parent', 'position', 'settings', 'wpcode_config', 'custom_shortcode_tag', 'custom_variables' );
		$lines    = preg_split( '/\r\n|\r|\n/', $text );

		if ( ! is_array( $lines ) ) {
			return $atts;
		}

		foreach ( $lines as $line ) {
			$line = trim( (string) $line );

			if ( '' === $line || '#' === substr( $line, 0, 1 ) || '//' === substr( $line, 0, 2 ) ) {
				continue;
			}

			$eq = strpos( $line, '=' );

			if ( false === $eq ) {
				continue;
			}

			$key = sanitize_key( trim( substr( $line, 0, $eq ) ) );
			$val = trim( substr( $line, $eq + 1 ) );

			if ( in_array( $key, $reserved, true ) ) {
				continue;
			}

			if ( strlen( $val ) >= 2 ) {
				$first = substr( $val, 0, 1 );
				$last  = substr( $val, -1 );

				if ( ( '"' === $first && '"' === $last ) || ( "'" === $first && "'" === $last ) ) {
					$val = substr( $val, 1, -1 );
				}
			}

			$atts[ $key ] = $val;
		}

		return $atts;
	}
}
