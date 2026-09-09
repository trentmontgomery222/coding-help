<?php
/**
 * Finds "configurations" arrays in a block of JavaScript, lists the
 * settings inside them, and rewrites individual values in place.
 *
 * The arrays this understands look like the ones WPCode snippets use:
 *
 *     var configurations = [
 *         { key: 'calendarID', value: 'c_a13c...' },
 *         { key: 'noSchoolEvent', value: {
 *             background: 'auto',
 *             searchForWords: ['schools closed'],
 *             badgeText: 'No School'
 *         }}
 *     ];
 *
 * Each editable setting gets a dotted path - "calendarID",
 * "noSchoolEvent.badgeText", "noSchoolEvent.searchForWords" - and the
 * exact character span of its value literal, so a value can be replaced
 * without touching anything else in the snippet. Comments, formatting
 * and any code around the array all survive untouched.
 *
 * This class is deliberately free of WordPress functions so it can be
 * tested on its own.
 */

if ( defined( 'ABSPATH' ) && ! defined( 'WPCODEBBV_VERSION' ) ) {
	exit;
}

class WPCodeBBV_Scanner {

	/** Variable names to look for: configurations, configurationsCalendar, configurations_2 ... */
	const NAME_PATTERN = '/(?:var|let|const)?\s*(configurations[A-Za-z0-9_]*)\s*=\s*\[/';

	/**
	 * Finds every configurations array in $js.
	 *
	 * @param string $js
	 * @return array<int, array{name:string, start:int, end:int, settings:array}>
	 */
	public static function scan( $js ) {
		$found = array();

		if ( ! is_string( $js ) || '' === $js ) {
			return $found;
		}

		if ( ! preg_match_all( self::NAME_PATTERN, $js, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $found;
		}

		foreach ( $matches[0] as $index => $match ) {
			// Position of the "[" that opens the array.
			$bracket = strpos( $js, '[', $match[1] );

			if ( false === $bracket ) {
				continue;
			}

			$node = self::parse_value( $js, $bracket );

			if ( ! $node || 'list' !== $node['kind'] ) {
				continue;
			}

			$found[] = array(
				'name'     => $matches[1][ $index ][0],
				'start'    => $node['start'],
				'end'      => $node['end'],
				'settings' => self::collect_settings( $node ),
			);
		}

		return $found;
	}

	/**
	 * Flattens one parsed array into path => leaf.
	 *
	 * @param array $array_node
	 * @return array<string, array>
	 */
	private static function collect_settings( $array_node ) {
		$settings = array();

		foreach ( $array_node['items'] as $item ) {
			if ( 'object' !== $item['kind'] || ! isset( $item['props']['key'], $item['props']['value'] ) ) {
				continue;
			}

			$key_node = $item['props']['key'];

			if ( 'string' !== $key_node['kind'] || '' === $key_node['text'] ) {
				continue;
			}

			$root  = $key_node['text'];
			$value = $item['props']['value'];

			if ( 'object' === $value['kind'] ) {
				foreach ( $value['props'] as $prop_name => $prop_node ) {
					if ( 'object' === $prop_node['kind'] ) {
						continue; // Only one level of nesting is editable.
					}

					$settings[ $root . '.' . $prop_name ] = self::leaf( $prop_node );
				}

				continue;
			}

			$settings[ $root ] = self::leaf( $value );
		}

		return $settings;
	}

	/**
	 * @param array $node
	 * @return array{value:string, kind:string, start:int, end:int}
	 */
	private static function leaf( $node ) {
		if ( 'list' === $node['kind'] ) {
			$parts = array();

			foreach ( $node['items'] as $item ) {
				$parts[] = 'string' === $item['kind'] ? $item['text'] : $item['raw'];
			}

			return array(
				'value' => implode( ', ', $parts ),
				'kind'  => 'list',
				'start' => $node['start'],
				'end'   => $node['end'],
			);
		}

		return array(
			'value' => 'string' === $node['kind'] ? $node['text'] : $node['raw'],
			'kind'  => $node['kind'],
			'start' => $node['start'],
			'end'   => $node['end'],
		);
	}

	/**
	 * Rewrites values in $js.
	 *
	 * Overrides are keyed by path ("noSchoolEvent.badgeText") and may be
	 * scoped to one array by name ("configurationsTwo:eventColor").
	 * Anything that does not match a real setting is ignored.
	 *
	 * @param string               $js
	 * @param array<string,string> $overrides
	 * @return string
	 */
	public static function apply( $js, $overrides ) {
		if ( ! is_string( $js ) || empty( $overrides ) || ! is_array( $overrides ) ) {
			return $js;
		}

		$edits = array();

		foreach ( self::scan( $js ) as $array ) {
			foreach ( $array['settings'] as $path => $leaf ) {
				foreach ( array( $array['name'] . ':' . $path, $path ) as $candidate ) {
					if ( ! array_key_exists( $candidate, $overrides ) ) {
						continue;
					}

					$edits[] = array(
						'start'   => $leaf['start'],
						'end'     => $leaf['end'],
						'literal' => self::to_literal( (string) $overrides[ $candidate ], $leaf['kind'] ),
					);

					break;
				}
			}
		}

		if ( empty( $edits ) ) {
			return $js;
		}

		// Apply from the end backwards so earlier spans stay valid.
		usort(
			$edits,
			function ( $a, $b ) {
				return $b['start'] - $a['start'];
			}
		);

		foreach ( $edits as $edit ) {
			$js = substr( $js, 0, $edit['start'] ) . $edit['literal'] . substr( $js, $edit['end'] );
		}

		return $js;
	}

	/**
	 * Turns a value typed by a human into a JavaScript literal of the
	 * same shape as the one it replaces.
	 *
	 * @param string $value
	 * @param string $kind
	 * @return string
	 */
	private static function to_literal( $value, $kind ) {
		if ( 'list' === $kind ) {
			$parts = array();

			foreach ( explode( ',', $value ) as $part ) {
				$part = trim( $part );

				if ( '' !== $part ) {
					$parts[] = self::quote( $part );
				}
			}

			return '[' . implode( ', ', $parts ) . ']';
		}

		// A raw literal (number, true, false) keeps its shape only if the
		// replacement still looks like one. Otherwise it becomes a string,
		// which is always valid JavaScript.
		if ( 'raw' === $kind ) {
			$trimmed = trim( $value );

			if ( is_numeric( $trimmed ) || in_array( $trimmed, array( 'true', 'false', 'null' ), true ) ) {
				return $trimmed;
			}
		}

		return self::quote( $value );
	}

	/**
	 * Single-quotes a string for JavaScript.
	 *
	 * @param string $value
	 * @return string
	 */
	private static function quote( $value ) {
		$value = str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $value );
		$value = str_replace( array( "\r\n", "\r", "\n" ), '\\n', $value );

		// A literal </script> inside a string would end the script block.
		$value = str_ireplace( '</script', '<\\/script', $value );

		return "'" . $value . "'";
	}

	/* ------------------------------------------------------------------
	 * A small recursive-descent reader for JavaScript literals.
	 * ---------------------------------------------------------------- */

	/**
	 * @param string $s
	 * @param int    $i
	 * @return int
	 */
	private static function skip_ws( $s, $i ) {
		$n = strlen( $s );

		while ( $i < $n ) {
			$c = $s[ $i ];

			if ( " " === $c || "\t" === $c || "\n" === $c || "\r" === $c ) {
				$i++;
				continue;
			}

			if ( '/' === $c && $i + 1 < $n ) {
				if ( '/' === $s[ $i + 1 ] ) {
					while ( $i < $n && "\n" !== $s[ $i ] ) {
						$i++;
					}
					continue;
				}

				if ( '*' === $s[ $i + 1 ] ) {
					$i += 2;

					while ( $i + 1 < $n && ! ( '*' === $s[ $i ] && '/' === $s[ $i + 1 ] ) ) {
						$i++;
					}

					$i += 2;
					continue;
				}
			}

			break;
		}

		return $i;
	}

	/**
	 * Parses one value starting at $i.
	 *
	 * @param string $s
	 * @param int    $i
	 * @return array|null
	 */
	private static function parse_value( $s, $i ) {
		$n = strlen( $s );
		$i = self::skip_ws( $s, $i );

		if ( $i >= $n ) {
			return null;
		}

		$c = $s[ $i ];

		if ( "'" === $c || '"' === $c || '`' === $c ) {
			return self::parse_string( $s, $i );
		}

		if ( '[' === $c ) {
			return self::parse_list( $s, $i );
		}

		if ( '{' === $c ) {
			return self::parse_object( $s, $i );
		}

		// A bare token: number, true, false, null, an identifier.
		$start = $i;

		while ( $i < $n && false === strpos( ",}]\n\r", $s[ $i ] ) ) {
			$i++;
		}

		$raw = rtrim( substr( $s, $start, $i - $start ) );

		if ( '' === $raw ) {
			return null;
		}

		return array(
			'kind'  => 'raw',
			'raw'   => $raw,
			'start' => $start,
			'end'   => $start + strlen( $raw ),
		);
	}

	private static function parse_string( $s, $i ) {
		$n     = strlen( $s );
		$quote = $s[ $i ];
		$start = $i;
		$text  = '';
		$i++;

		while ( $i < $n ) {
			$c = $s[ $i ];

			if ( '\\' === $c && $i + 1 < $n ) {
				$next = $s[ $i + 1 ];
				$map  = array( 'n' => "\n", 't' => "\t", 'r' => "\r" );
				$text .= isset( $map[ $next ] ) ? $map[ $next ] : $next;
				$i    += 2;
				continue;
			}

			if ( $c === $quote ) {
				$i++;
				break;
			}

			$text .= $c;
			$i++;
		}

		return array(
			'kind'  => 'string',
			'text'  => $text,
			'start' => $start,
			'end'   => $i,
		);
	}

	private static function parse_list( $s, $i ) {
		$n     = strlen( $s );
		$start = $i;
		$items = array();
		$i++; // past "["

		while ( $i < $n ) {
			$i = self::skip_ws( $s, $i );

			if ( $i >= $n ) {
				break;
			}

			if ( ']' === $s[ $i ] ) {
				$i++;
				break;
			}

			if ( ',' === $s[ $i ] ) {
				$i++;
				continue;
			}

			$item = self::parse_value( $s, $i );

			if ( ! $item ) {
				break;
			}

			$items[] = $item;
			$i       = $item['end'];
		}

		return array(
			'kind'  => 'list',
			'items' => $items,
			'start' => $start,
			'end'   => $i,
		);
	}

	private static function parse_object( $s, $i ) {
		$n     = strlen( $s );
		$start = $i;
		$props = array();
		$i++; // past "{"

		while ( $i < $n ) {
			$i = self::skip_ws( $s, $i );

			if ( $i >= $n ) {
				break;
			}

			if ( '}' === $s[ $i ] ) {
				$i++;
				break;
			}

			if ( ',' === $s[ $i ] ) {
				$i++;
				continue;
			}

			// Property name: quoted or bare.
			if ( "'" === $s[ $i ] || '"' === $s[ $i ] || '`' === $s[ $i ] ) {
				$name_node = self::parse_string( $s, $i );
				$name      = $name_node['text'];
				$i         = $name_node['end'];
			} else {
				$name_start = $i;

				while ( $i < $n && ( ctype_alnum( $s[ $i ] ) || '_' === $s[ $i ] || '$' === $s[ $i ] ) ) {
					$i++;
				}

				$name = substr( $s, $name_start, $i - $name_start );

				if ( '' === $name ) {
					break; // Not something we understand; stop here.
				}
			}

			$i = self::skip_ws( $s, $i );

			if ( $i >= $n || ':' !== $s[ $i ] ) {
				break;
			}

			$i     = self::skip_ws( $s, $i + 1 );
			$value = self::parse_value( $s, $i );

			if ( ! $value ) {
				break;
			}

			$props[ $name ] = $value;
			$i              = $value['end'];
		}

		return array(
			'kind'  => 'object',
			'props' => $props,
			'start' => $start,
			'end'   => $i,
		);
	}
}
