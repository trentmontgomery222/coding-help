<?php
/**
 * Field-type registry (spec §7.1).
 *
 * Each type declares whether it holds a value, whether it supports options,
 * and its default validation. Adding a type here makes it available to the
 * renderer, the builder, and the validator uniformly.
 *
 * @package ACPS\SiteToolkit
 */

namespace ACPS\SiteToolkit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Field_Types.
 */
class Field_Types {

	/**
	 * The registry.
	 *
	 * @return array Keyed by type slug.
	 */
	public static function all() {
		$types = array(
			'short_text'   => array( 'label' => 'Short text', 'input' => true, 'options' => false ),
			'long_text'    => array( 'label' => 'Long text', 'input' => true, 'options' => false ),
			'email'        => array( 'label' => 'Email', 'input' => true, 'options' => false, 'autocomplete' => 'email' ),
			'number'       => array( 'label' => 'Number', 'input' => true, 'options' => false ),
			'dropdown'     => array( 'label' => 'Dropdown', 'input' => true, 'options' => true ),
			'radio'        => array( 'label' => 'Radio group', 'input' => true, 'options' => true, 'group' => true ),
			'checkbox'     => array( 'label' => 'Checkbox group', 'input' => true, 'options' => true, 'group' => true, 'multi' => true ),
			'date'         => array( 'label' => 'Date', 'input' => true, 'options' => false ),
			'time'         => array( 'label' => 'Time', 'input' => true, 'options' => false ),
			'file'         => array( 'label' => 'File upload', 'input' => true, 'options' => false, 'file' => true ),
			'scale'        => array( 'label' => 'Linear scale', 'input' => true, 'options' => false ),
			'rating'       => array( 'label' => 'Rating', 'input' => true, 'options' => false ),
			'chips'        => array( 'label' => 'Choice chips', 'input' => true, 'options' => true, 'group' => true ),
			'page_picker'  => array( 'label' => 'Page picker (feedback)', 'input' => true, 'options' => false ),
			'section'      => array( 'label' => 'Section break', 'input' => false, 'options' => false ),
			'heading'      => array( 'label' => 'Static text / heading', 'input' => false, 'options' => false ),
			'hidden'       => array( 'label' => 'Hidden field', 'input' => true, 'options' => false, 'hidden' => true ),
		);

		/**
		 * Filter the registered field types.
		 *
		 * @param array $types Field types.
		 */
		return apply_filters( 'acps_st_field_types', $types );
	}

	/**
	 * Is this a real input that produces an entry value?
	 *
	 * @param string $type Type slug.
	 * @return bool
	 */
	public static function is_input( $type ) {
		$all = self::all();
		return isset( $all[ $type ] ) && ! empty( $all[ $type ]['input'] );
	}

	/**
	 * Metadata for a type.
	 *
	 * @param string $type Type slug.
	 * @return array
	 */
	public static function meta( $type ) {
		$all = self::all();
		return isset( $all[ $type ] ) ? $all[ $type ] : array();
	}

	/**
	 * Normalize a raw field definition (from JSON or the builder) into a
	 * predictable shape with a stable key.
	 *
	 * @param array $field Raw field.
	 * @param int   $index Position, used to derive a key if absent.
	 * @return array
	 */
	public static function normalize( $field, $index = 0 ) {
		$field = is_array( $field ) ? $field : array();
		$type  = isset( $field['type'] ) && isset( self::all()[ $field['type'] ] ) ? $field['type'] : 'short_text';

		$key = isset( $field['key'] ) ? sanitize_key( $field['key'] ) : '';
		if ( '' === $key ) {
			$key = 'field_' . ( $index + 1 );
		}

		$options = array();
		if ( ! empty( $field['options'] ) && is_array( $field['options'] ) ) {
			foreach ( $field['options'] as $opt ) {
				if ( is_array( $opt ) ) {
					$label = isset( $opt['label'] ) ? sanitize_text_field( $opt['label'] ) : '';
					$value = isset( $opt['value'] ) && '' !== $opt['value'] ? sanitize_text_field( $opt['value'] ) : $label;
				} else {
					$label = sanitize_text_field( $opt );
					$value = $label;
				}
				if ( '' !== $label ) {
					$options[] = array( 'label' => $label, 'value' => $value );
				}
			}
		}

		return array(
			'key'         => $key,
			'type'        => $type,
			'label'       => isset( $field['label'] ) ? sanitize_text_field( $field['label'] ) : '',
			'help'        => isset( $field['help'] ) ? sanitize_text_field( $field['help'] ) : '',
			'placeholder' => isset( $field['placeholder'] ) ? sanitize_text_field( $field['placeholder'] ) : '',
			'default'     => isset( $field['default'] ) ? sanitize_text_field( $field['default'] ) : '',
			'required'    => ! empty( $field['required'] ),
			'options'     => $options,
			'validation'  => isset( $field['validation'] ) && is_array( $field['validation'] ) ? $field['validation'] : array(),
			'conditional' => isset( $field['conditional'] ) && is_array( $field['conditional'] ) ? $field['conditional'] : array(),
			'page'        => isset( $field['page'] ) ? absint( $field['page'] ) : 1,
			'scale_min'   => isset( $field['scale_min'] ) ? (int) $field['scale_min'] : 1,
			'scale_max'   => isset( $field['scale_max'] ) ? (int) $field['scale_max'] : 5,
			'content'     => isset( $field['content'] ) ? wp_kses_post( $field['content'] ) : '',
		);
	}

	/**
	 * Normalize a whole field list.
	 *
	 * @param array $fields Raw list.
	 * @return array
	 */
	public static function normalize_list( $fields ) {
		$out  = array();
		$seen = array();
		$i    = 0;
		foreach ( (array) $fields as $f ) {
			$field = self::normalize( $f, $i );
			// Guarantee unique keys.
			$base = $field['key'];
			$n    = 2;
			while ( isset( $seen[ $field['key'] ] ) ) {
				$field['key'] = $base . '_' . $n;
				$n++;
			}
			$seen[ $field['key'] ] = true;
			$out[]                 = $field;
			$i++;
		}
		return $out;
	}
}
