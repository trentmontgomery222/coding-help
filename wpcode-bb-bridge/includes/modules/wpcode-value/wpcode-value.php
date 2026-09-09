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
	 * Builds this module instance's render data. Returns a discriminated
	 * shape keyed by 'mode':
	 *   array('mode' => null)                                      nothing to render
	 *   array('mode' => 'shortcode', 'tag', 'atts', 'values')       shortcode-attributes Configurations and Custom mode
	 *   array('mode' => 'js_array', 'varname', 'js')                JS-config-array Configurations
	 * Fully defensive throughout: bad/missing/corrupted data always
	 * falls back to array('mode' => null) rather than a notice or error.
	 *
	 * @return array
	 */
	public function get_render_data() {
		$empty = array( 'mode' => null );

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

		$config      = $configs[ $config_id ];
		$source_type = isset( $config['source_type'] ) ? $config['source_type'] : 'shortcode';

		if ( 'js_array' === $source_type ) {
			return $this->get_js_array_render_data( $config, $empty );
		}

		return $this->get_shortcode_render_data( $config, $empty );
	}

	/**
	 * Render data for a shortcode-attributes-mode Configuration.
	 *
	 * @return array
	 */
	private function get_shortcode_render_data( $config, $empty ) {
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

			if ( 'color' === $type ) {
				$val = self::normalize_color_value( $val );
			}

			$atts[ $key ]   = $val;
			$values[ $key ] = $val;
		}

		return array(
			'mode'   => 'shortcode',
			'tag'    => $tag,
			'atts'   => $atts,
			'values' => $values,
		);
	}

	/**
	 * Render data for a JS-config-array-mode Configuration: re-parses
	 * the stored source array, overlays whatever the editor changed at
	 * each exposed leaf's path, and serializes the result back to JS.
	 * Any failure anywhere in this (bad stored source, a leaf path that
	 * no longer exists, a serialize error) yields the "nothing to
	 * render" shape rather than breaking the page.
	 *
	 * @return array
	 */
	private function get_js_array_render_data( $config, $empty ) {
		if ( ! class_exists( 'WPCodeBB_JS_Codec' ) ) {
			return $empty;
		}

		$source = isset( $config['js_source'] ) ? $config['js_source'] : '';

		if ( ! $source ) {
			return $empty;
		}

		try {
			$parsed = WPCodeBB_JS_Codec::parse( $source );
		} catch ( \Throwable $e ) {
			return $empty;
		}

		if ( empty( $parsed['ok'] ) ) {
			return $empty;
		}

		$root   = $parsed['node'];
		$fields = isset( $config['js_fields'] ) && is_array( $config['js_fields'] ) ? $config['js_fields'] : array();

		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || empty( $field['path'] ) || ! class_exists( 'WPCodeBB_BB_Module' ) ) {
				continue;
			}

			$path   = $field['path'];
			$bb_key = WPCodeBB_BB_Module::path_to_field_key( $path );

			if ( ! isset( $this->settings->{$bb_key} ) ) {
				continue;
			}

			$value = $this->settings->{$bb_key};
			$type  = isset( $field['type'] ) ? $field['type'] : 'text';

			if ( 'color' === $type ) {
				$value = self::normalize_color_value( $value );
			}

			try {
				WPCodeBB_JS_Codec::set_leaf( $root, $path, $value );
			} catch ( \Throwable $e ) {
				// Skip just this one leaf; keep applying the rest.
				continue;
			}
		}

		try {
			$js = WPCodeBB_JS_Codec::serialize( $root );
		} catch ( \Throwable $e ) {
			return $empty;
		}

		$varname = isset( $config['js_varname'] ) && $config['js_varname'] ? $config['js_varname'] : 'configurations';
		$varname = preg_replace( '/[^A-Za-z0-9_$]/', '', $varname );

		if ( '' === $varname ) {
			$varname = 'configurations';
		}

		return array(
			'mode'    => 'js_array',
			'varname' => $varname,
			'js'      => $js,
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
	 * @return array
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
			'mode'   => 'shortcode',
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

	/**
	 * Only prepends "#" when the value actually looks like a hex color
	 * (all hex digits, 1-8 of them - covers BB's color-picker output,
	 * which is always hex with no "#"). A CSS named color like "blue" or
	 * "crimson" - common in hand-written config arrays like this
	 * plugin's JS Configuration Array mode - is left completely alone,
	 * since prepending "#" to it would produce invalid CSS.
	 */
	private static function normalize_color_value( $value ) {
		$value = (string) $value;

		if ( '' === $value || '#' === substr( $value, 0, 1 ) ) {
			return $value;
		}

		if ( ctype_xdigit( $value ) && strlen( $value ) <= 8 ) {
			return '#' . $value;
		}

		return $value;
	}
}
