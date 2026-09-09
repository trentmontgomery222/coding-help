<?php
/**
 * A small, self-contained parser/serializer for the loose JS object/array
 * literal syntax used in hand-written config blocks like:
 *
 *   var configurations = [
 *     { key: 'eventColor', value: 'blue' },
 *     { key: 'noSchoolEvent', value: { primaryColor: 'red', ... } },
 *   ];
 *
 * This is NOT JSON (unquoted keys, single-quoted strings, trailing
 * commas are all valid here), so json_decode() can't read it. This
 * hand-rolled tokenizer/recursive-descent parser reads exactly the
 * subset of JS literal syntax needed for this kind of config array:
 * objects, arrays, strings (single/double quoted), numbers, true/false/
 * null, and trailing commas. It intentionally does NOT evaluate
 * arbitrary JavaScript - only literal data.
 *
 * Every node in the parsed tree is a plain array:
 *   array( 'kind' => 'string'|'number'|'bool'|'null'|'object'|'array',
 *          'value' => ... )
 * - scalar kinds: 'value' is the raw PHP scalar.
 * - 'object': 'value' is an ordered array of key => node (insertion order
 *   preserved, since PHP arrays are ordered).
 * - 'array': 'value' is a plain list of nodes.
 *
 * Preserving 'kind' matters: this config style often stores booleans as
 * the *strings* 'true'/'false' rather than real JS booleans, and
 * re-serializing must not silently change that (a snippet reading
 * `foo === 'true'` would break if we turned it into a real boolean).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'WPCodeBB_JS_Codec', false ) ) {
	return;
}

class WPCodeBB_JS_Codec {

	/**
	 * Parses a JS literal (optionally prefixed with "var name =" and a
	 * trailing ";") into a node tree.
	 *
	 * @param string $text
	 * @return array{ok:bool, node:array|null, error:string|null}
	 */
	public static function parse( $text ) {
		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			return array(
				'ok'    => false,
				'node'  => null,
				'error' => __( 'Nothing to parse - the box is empty.', 'wpcode-bb-bridge' ),
			);
		}

		// Strip a leading "var something =" / "const something =" / "let something ="
		// and a trailing ";" if present, so users can paste the whole
		// declaration or just the literal.
		$trimmed = trim( $text );
		$trimmed = preg_replace( '/^(?:var|const|let)\s+[A-Za-z_$][A-Za-z0-9_$]*\s*=\s*/', '', $trimmed );
		$trimmed = rtrim( $trimmed );
		$trimmed = rtrim( $trimmed, ';' );

		try {
			$tokens = self::tokenize( $trimmed );
			$pos    = 0;
			$node   = self::parse_value( $tokens, $pos );
			self::skip_ws_tokens( $tokens, $pos );

			if ( $pos < count( $tokens ) ) {
				throw new \Exception( __( 'Unexpected extra content after the array/object.', 'wpcode-bb-bridge' ) );
			}

			return array(
				'ok'    => true,
				'node'  => $node,
				'error' => null,
			);
		} catch ( \Throwable $e ) {
			return array(
				'ok'    => false,
				'node'  => null,
				'error' => $e->getMessage(),
			);
		}
	}

	/**
	 * Turns a node tree back into JS literal source text.
	 *
	 * @param array $node
	 * @param int   $indent Current indent depth (for pretty printing).
	 * @return string
	 */
	public static function serialize( $node, $indent = 0 ) {
		if ( ! is_array( $node ) || ! isset( $node['kind'] ) ) {
			return 'null';
		}

		$pad      = str_repeat( '  ', $indent );
		$pad_next = str_repeat( '  ', $indent + 1 );

		switch ( $node['kind'] ) {
			case 'string':
				return self::quote_string( (string) $node['value'] );

			case 'number':
				return self::format_number( $node['value'] );

			case 'bool':
				return $node['value'] ? 'true' : 'false';

			case 'null':
				return 'null';

			case 'array':
				if ( empty( $node['value'] ) ) {
					return '[]';
				}

				$items = array();

				foreach ( $node['value'] as $child ) {
					$items[] = $pad_next . self::serialize( $child, $indent + 1 );
				}

				return "[\n" . implode( ",\n", $items ) . "\n" . $pad . ']';

			case 'object':
				if ( empty( $node['value'] ) ) {
					return '{}';
				}

				$items = array();

				foreach ( $node['value'] as $key => $child ) {
					$items[] = $pad_next . self::format_key( $key ) . ': ' . self::serialize( $child, $indent + 1 );
				}

				return "{\n" . implode( ",\n", $items ) . "\n" . $pad . '}';

			default:
				return 'null';
		}
	}

	/**
	 * Collects editable "leaves" from a top-level array-of-{key,value}
	 * pairs node (the exact shape used by `configurations`). Each pair's
	 * own `value` is flattened up to $max_depth levels of nested plain
	 * objects; an array value (e.g. searchForWords: [...]) is treated as
	 * one leaf, not recursed into.
	 *
	 * @param array $root_node The parsed top-level array node.
	 * @param int   $max_depth How many nested-object levels to flatten.
	 * @return array<int, array{path:string, label:string, kind:string, value:mixed}>
	 */
	public static function collect_leaves( $root_node, $max_depth = 2 ) {
		$leaves = array();

		if ( ! is_array( $root_node ) || 'array' !== $root_node['kind'] ) {
			return $leaves;
		}

		foreach ( $root_node['value'] as $pair_node ) {
			if ( 'object' !== $pair_node['kind'] || ! isset( $pair_node['value']['key'], $pair_node['value']['value'] ) ) {
				continue;
			}

			$key_node = $pair_node['value']['key'];

			if ( 'string' !== $key_node['kind'] ) {
				continue;
			}

			$top_key = $key_node['value'];
			self::collect_leaves_recursive( $pair_node['value']['value'], $top_key, $top_key, $max_depth, $leaves );
		}

		return $leaves;
	}

	private static function collect_leaves_recursive( $node, $path, $label, $depth_left, &$leaves ) {
		if ( ! is_array( $node ) || ! isset( $node['kind'] ) ) {
			return;
		}

		if ( 'object' === $node['kind'] && $depth_left > 0 ) {
			foreach ( $node['value'] as $sub_key => $sub_node ) {
				self::collect_leaves_recursive( $sub_node, $path . '.' . $sub_key, $sub_key, $depth_left - 1, $leaves );
			}
			return;
		}

		// Scalars, arrays, and objects we've hit max depth on all become
		// one editable leaf.
		$leaves[] = array(
			'path'  => $path,
			'label' => $label,
			'kind'  => $node['kind'],
			'value' => self::leaf_display_value( $node ),
		);
	}

	/**
	 * A human/edit-friendly representation of a leaf's current value:
	 * scalars as-is, arrays of scalars joined with newlines.
	 */
	private static function leaf_display_value( $node ) {
		switch ( $node['kind'] ) {
			case 'array':
				$parts = array();

				foreach ( $node['value'] as $item ) {
					if ( isset( $item['value'] ) && ! is_array( $item['value'] ) ) {
						$parts[] = (string) $item['value'];
					}
				}

				return implode( "\n", $parts );

			case 'bool':
				return $node['value'] ? 'true' : 'false';

			case 'object':
				return ''; // Deeper than max_depth - not editable as text; left untouched.

			default:
				return isset( $node['value'] ) ? (string) $node['value'] : '';
		}
	}

	/**
	 * Writes a new value into the node tree at a dotted path (as
	 * produced by collect_leaves), preserving the original leaf's kind
	 * where it makes sense (e.g. a 'true'/'false' *string* stays a
	 * string, never becomes a real boolean).
	 *
	 * @param array  $root_node Parsed top-level array node (modified by reference).
	 * @param string $path      Dotted path, e.g. "noSchoolEvent.primaryColor".
	 * @param string $new_value New raw value as a string (from a BB field).
	 * @return bool True if the path was found and updated.
	 */
	public static function set_leaf( &$root_node, $path, $new_value ) {
		if ( ! is_array( $root_node ) || 'array' !== $root_node['kind'] ) {
			return false;
		}

		$segments = explode( '.', $path );
		$top_key  = array_shift( $segments );

		foreach ( $root_node['value'] as &$pair_node ) {
			if ( 'object' !== $pair_node['kind'] || ! isset( $pair_node['value']['key'], $pair_node['value']['value'] ) ) {
				continue;
			}

			$key_node = $pair_node['value']['key'];

			if ( 'string' !== $key_node['kind'] || $key_node['value'] !== $top_key ) {
				continue;
			}

			return self::set_leaf_recursive( $pair_node['value']['value'], $segments, $new_value );
		}

		return false;
	}

	private static function set_leaf_recursive( &$node, $segments, $new_value ) {
		if ( empty( $segments ) ) {
			return self::write_leaf_value( $node, $new_value );
		}

		if ( ! is_array( $node ) || 'object' !== $node['kind'] ) {
			return false;
		}

		$next = array_shift( $segments );

		if ( ! isset( $node['value'][ $next ] ) ) {
			return false;
		}

		return self::set_leaf_recursive( $node['value'][ $next ], $segments, $new_value );
	}

	/**
	 * Overwrites a scalar/array leaf node's value in place, keeping its
	 * original kind intact wherever the new value is compatible.
	 */
	private static function write_leaf_value( &$node, $new_value ) {
		switch ( $node['kind'] ) {
			case 'array':
				$lines        = preg_split( '/\r\n|\r|\n|,/', (string) $new_value );
				$node['value'] = array();

				foreach ( $lines as $line ) {
					$line = trim( $line );

					if ( '' === $line ) {
						continue;
					}

					$node['value'][] = array( 'kind' => 'string', 'value' => $line );
				}

				return true;

			case 'bool':
				$node['value'] = self::truthy( $new_value );
				return true;

			case 'number':
				$node['value'] = is_numeric( $new_value ) ? $new_value + 0 : $node['value'];
				return true;

			case 'null':
			case 'string':
			default:
				// Includes the common case of a 'true'/'false' *string*
				// leaf: it stays kind 'string', we just replace the text.
				$node['kind']  = 'string';
				$node['value'] = (string) $new_value;
				return true;
		}
	}

	private static function truthy( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		return in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'on' ), true );
	}

	// ------------------------------------------------------------------
	// Tokenizer / parser internals.
	// ------------------------------------------------------------------

	private static function tokenize( $text ) {
		$tokens = array();
		$len    = strlen( $text );
		$i      = 0;

		while ( $i < $len ) {
			$ch = $text[ $i ];

			if ( ctype_space( $ch ) ) {
				$i++;
				continue;
			}

			// Line comment.
			if ( '/' === $ch && $i + 1 < $len && '/' === $text[ $i + 1 ] ) {
				while ( $i < $len && "\n" !== $text[ $i ] ) {
					$i++;
				}
				continue;
			}

			// Block comment.
			if ( '/' === $ch && $i + 1 < $len && '*' === $text[ $i + 1 ] ) {
				$end = strpos( $text, '*/', $i + 2 );
				$i   = ( false === $end ) ? $len : $end + 2;
				continue;
			}

			if ( in_array( $ch, array( '{', '}', '[', ']', ':', ',' ), true ) ) {
				$tokens[] = array( 'type' => $ch );
				$i++;
				continue;
			}

			if ( "'" === $ch || '"' === $ch ) {
				$quote = $ch;
				$j     = $i + 1;
				$buf   = '';

				while ( $j < $len && $text[ $j ] !== $quote ) {
					if ( '\\' === $text[ $j ] && $j + 1 < $len ) {
						$next = $text[ $j + 1 ];
						$map  = array(
							'n'  => "\n",
							't'  => "\t",
							'r'  => "\r",
							"'"  => "'",
							'"'  => '"',
							'\\' => '\\',
						);
						$buf .= isset( $map[ $next ] ) ? $map[ $next ] : $next;
						$j   += 2;
						continue;
					}

					$buf .= $text[ $j ];
					$j++;
				}

				if ( $j >= $len ) {
					throw new \Exception( __( 'Unterminated string in the pasted array.', 'wpcode-bb-bridge' ) );
				}

				$tokens[] = array( 'type' => 'string', 'value' => $buf );
				$i        = $j + 1;
				continue;
			}

			if ( '-' === $ch || ctype_digit( $ch ) ) {
				$j = $i;

				if ( '-' === $text[ $j ] ) {
					$j++;
				}

				while ( $j < $len && ( ctype_digit( $text[ $j ] ) || '.' === $text[ $j ] ) ) {
					$j++;
				}

				$tokens[] = array( 'type' => 'number', 'value' => (float) substr( $text, $i, $j - $i ) );
				$i        = $j;
				continue;
			}

			if ( ctype_alpha( $ch ) || '_' === $ch || '$' === $ch ) {
				$j = $i;

				while ( $j < $len && ( ctype_alnum( $text[ $j ] ) || '_' === $text[ $j ] || '$' === $text[ $j ] ) ) {
					$j++;
				}

				$tokens[] = array( 'type' => 'ident', 'value' => substr( $text, $i, $j - $i ) );
				$i        = $j;
				continue;
			}

			throw new \Exception(
				sprintf(
					/* translators: %s: the offending character */
					__( 'Unexpected character "%s" in the pasted array.', 'wpcode-bb-bridge' ),
					$ch
				)
			);
		}

		return $tokens;
	}

	private static function skip_ws_tokens( $tokens, &$pos ) {
		// No-op placeholder: whitespace is already stripped by the
		// tokenizer. Kept for readability at call sites.
		unset( $tokens, $pos );
	}

	private static function parse_value( $tokens, &$pos ) {
		if ( ! isset( $tokens[ $pos ] ) ) {
			throw new \Exception( __( 'Unexpected end of input while parsing.', 'wpcode-bb-bridge' ) );
		}

		$token = $tokens[ $pos ];

		switch ( $token['type'] ) {
			case '{':
				return self::parse_object( $tokens, $pos );

			case '[':
				return self::parse_array( $tokens, $pos );

			case 'string':
				$pos++;
				return array( 'kind' => 'string', 'value' => $token['value'] );

			case 'number':
				$pos++;
				return array( 'kind' => 'number', 'value' => $token['value'] );

			case 'ident':
				$pos++;

				if ( 'true' === $token['value'] ) {
					return array( 'kind' => 'bool', 'value' => true );
				}

				if ( 'false' === $token['value'] ) {
					return array( 'kind' => 'bool', 'value' => false );
				}

				if ( 'null' === $token['value'] || 'undefined' === $token['value'] ) {
					return array( 'kind' => 'null', 'value' => null );
				}

				throw new \Exception(
					sprintf(
						/* translators: %s: the unexpected word */
						__( 'Unexpected word "%s" - only true/false/null are allowed here.', 'wpcode-bb-bridge' ),
						$token['value']
					)
				);

			default:
				throw new \Exception( __( 'Unexpected token while parsing a value.', 'wpcode-bb-bridge' ) );
		}
	}

	private static function parse_object( $tokens, &$pos ) {
		$pos++; // consume '{'
		$props = array();

		while ( true ) {
			if ( ! isset( $tokens[ $pos ] ) ) {
				throw new \Exception( __( 'Unterminated object - missing closing }.', 'wpcode-bb-bridge' ) );
			}

			if ( '}' === $tokens[ $pos ]['type'] ) {
				$pos++;
				break;
			}

			$key_token = $tokens[ $pos ];

			if ( 'string' === $key_token['type'] ) {
				$key = $key_token['value'];
			} elseif ( 'ident' === $key_token['type'] ) {
				$key = $key_token['value'];
			} else {
				throw new \Exception( __( 'Expected a property name inside an object.', 'wpcode-bb-bridge' ) );
			}

			$pos++;

			if ( ! isset( $tokens[ $pos ] ) || ':' !== $tokens[ $pos ]['type'] ) {
				throw new \Exception( __( 'Expected ":" after a property name.', 'wpcode-bb-bridge' ) );
			}

			$pos++; // consume ':'

			$props[ $key ] = self::parse_value( $tokens, $pos );

			if ( isset( $tokens[ $pos ] ) && ',' === $tokens[ $pos ]['type'] ) {
				$pos++;
				continue;
			}

			if ( isset( $tokens[ $pos ] ) && '}' === $tokens[ $pos ]['type'] ) {
				$pos++;
				break;
			}

			throw new \Exception( __( 'Expected "," or "}" inside an object.', 'wpcode-bb-bridge' ) );
		}

		return array( 'kind' => 'object', 'value' => $props );
	}

	private static function parse_array( $tokens, &$pos ) {
		$pos++; // consume '['
		$items = array();

		while ( true ) {
			if ( ! isset( $tokens[ $pos ] ) ) {
				throw new \Exception( __( 'Unterminated array - missing closing ].', 'wpcode-bb-bridge' ) );
			}

			if ( ']' === $tokens[ $pos ]['type'] ) {
				$pos++;
				break;
			}

			$items[] = self::parse_value( $tokens, $pos );

			if ( isset( $tokens[ $pos ] ) && ',' === $tokens[ $pos ]['type'] ) {
				$pos++;
				continue;
			}

			if ( isset( $tokens[ $pos ] ) && ']' === $tokens[ $pos ]['type'] ) {
				$pos++;
				break;
			}

			throw new \Exception( __( 'Expected "," or "]" inside an array.', 'wpcode-bb-bridge' ) );
		}

		return array( 'kind' => 'array', 'value' => $items );
	}

	private static function quote_string( $value ) {
		$escaped = str_replace(
			array( '\\', "'", "\n", "\r", "\t" ),
			array( '\\\\', "\\'", '\\n', '\\r', '\\t' ),
			$value
		);

		return "'" . $escaped . "'";
	}

	private static function format_number( $value ) {
		if ( is_float( $value ) && floor( $value ) === $value ) {
			return (string) (int) $value;
		}

		return (string) $value;
	}

	private static function format_key( $key ) {
		if ( is_string( $key ) && preg_match( '/^[A-Za-z_$][A-Za-z0-9_$]*$/', $key ) ) {
			return $key;
		}

		return self::quote_string( (string) $key );
	}
}
