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

	/** The key a snippet uses to mark a setting as site-wide. */
	const WIDE_KEY = 'siteWide';

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
				'settings' => self::collect_settings( $node, $js ),
			);
		}

		return $found;
	}

	/**
	 * Finds settings marked with a "Configurable" comment, in any
	 * language a WPCode snippet can be written in:
	 *
	 *   JS    var calendarId = 'c_x';        // Configurable: the calendar
	 *   PHP   $api_key = 'AIza-x';           // Configurable siteWide
	 *   PHP   define( 'CACHE_TTL', 3600 );   # Configurable - seconds
	 *   CSS   --accent: #1A73E8;             /* Configurable: brand colour *\/
	 *   CSS   font-family: "Google Sans", Roboto, sans-serif; /* Configurable *\/
	 *
	 * The trick that makes one reader work for all of them: the comment
	 * marks where the value ENDS. Everything between the "=" or ":" and
	 * the comment is the value, whatever the language thinks of it. So
	 * there is no language to detect and no CSS/PHP/JS parser to get
	 * wrong - a font stack with commas and quotes in it survives exactly
	 * as written, and so does array( 'a', 'b' ) or #1A73E8.
	 *
	 * The assignment has to start its line, and the comment has to be on
	 * that same line. Both keep this predictable rather than clever.
	 *
	 * @param string $js
	 * @return array<string, array>
	 */
	public static function scan_markers( $js ) {
		$settings = array();

		if ( ! is_string( $js ) || '' === $js || false === stripos( $js, 'configurable' ) ) {
			return $settings;
		}

		$lines  = preg_split( '/\n/', $js );
		$offset = 0;

		if ( ! is_array( $lines ) ) {
			return $settings;
		}

		foreach ( $lines as $line ) {
			$line_start = $offset;
			$offset    += strlen( $line ) + 1; // +1 for the newline we split on.

			// Any of //, # or /* - but only when "Configurable" follows,
			// which is also what stops a CSS colour like #1A73E8 from
			// being mistaken for the start of a comment.
			if ( ! preg_match( '/(\/\/|#|\/\*)[ \t]*Configurable\b[ \t]*(.*)$/i', $line, $m, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}

			$comment_at = $m[0][1];
			$rest       = rtrim( $m[2][0] );
			$rest       = preg_replace( '/\*\/\s*$/', '', $rest ); // Drop a closing */.
			$rest       = trim( $rest );
			$wide       = false;

			if ( 0 === stripos( $rest, 'sitewide' ) ) {
				$wide = true;
				$rest = trim( substr( $rest, strlen( 'sitewide' ) ) );
			}

			$help = trim( $rest, " \t:-–—" );
			$code = substr( $line, 0, $comment_at );

			$name        = '';
			$value_start = 0;
			$closes      = false; // define( ... ) needs its ")" trimmed back off.

			// define( 'NAME', value ) - the usual way a PHP snippet
			// declares something worth exposing.
			if ( preg_match( '/^\s*(?:@?define)\s*\(\s*[\x27"]([A-Za-z_][A-Za-z0-9_]*)[\x27"]\s*,\s*/', $code, $d ) ) {
				$name        = $d[1];
				$value_start = strlen( $d[0] );
				$closes      = true;
			} elseif ( preg_match( '/^\s*(?:var|let|const|public|private|protected|static)?\s*(\$?[A-Za-z_\-][A-Za-z0-9_\-]*)\s*[:=]\s*/', $code, $a ) ) {
				$name        = $a[1];
				$value_start = strlen( $a[0] );
			} else {
				continue;
			}

			if ( '' === $name || self::WIDE_KEY === $name || isset( $settings[ $name ] ) ) {
				continue;
			}

			$raw = substr( $code, $value_start );

			// Trim back the punctuation that ends a statement rather than
			// belonging to the value: ");" for define, then ";" or ",".
			$trimmed = rtrim( $raw );

			if ( $closes ) {
				$trimmed = rtrim( $trimmed );
				$trimmed = preg_replace( '/\)\s*;?\s*$/', '', $trimmed );
			}

			$trimmed = rtrim( $trimmed );
			$trimmed = preg_replace( '/[;,]\s*$/', '', $trimmed );
			$trimmed = rtrim( $trimmed );

			if ( '' === $trimmed ) {
				continue;
			}

			$leading = strlen( $raw ) - strlen( ltrim( $raw ) );
			$literal = ltrim( $trimmed );

			if ( '' === $literal ) {
				continue;
			}

			$literal_at = $line_start + $value_start + $leading;

			// wpcodebbv_cfg( 'name', <default> ) - how a PHP snippet asks
			// for a value, since its source is executed rather than
			// printed and cannot be rewritten on the way out. The name
			// comes from the first argument and the editable value is the
			// default in the second.
			if ( preg_match( '/^wpcodebbv_cfg\s*\(\s*[\x27"]([^\x27"]+)[\x27"]\s*,\s*/i', $literal, $c ) ) {
				$inner = rtrim( substr( $literal, strlen( $c[0] ) ) );
				$inner = preg_replace( '/\)\s*$/', '', $inner );
				$inner = rtrim( $inner );

				if ( '' === $inner ) {
					continue;
				}

				$name        = $c[1];
				$literal_at += strlen( $c[0] );
				$literal     = $inner;

				if ( isset( $settings[ $name ] ) ) {
					continue;
				}
			}

			$leaf            = self::classify_literal( $literal );
			$leaf['start']   = $literal_at;
			$leaf['end']     = $leaf['start'] + strlen( $literal );
			$leaf['comment'] = $help;
			$leaf['global']  = $wide;
			$leaf['marked']  = true;

			$settings[ $name ] = $leaf;
		}

		return $settings;
	}

	/**
	 * Works out what one marked value is, from the literal text alone.
	 *
	 * A quoted string is unwrapped so the editor sees the text rather
	 * than the quotes. A bracketed or array() list becomes a comma
	 * separated list. Everything else - a colour, a number, a font
	 * stack, true/false, a PHP constant - is left exactly as written and
	 * written back the same way.
	 *
	 * @param string $literal
	 * @return array{value:string, kind:string, wrap:string, quote:string}
	 */
	private static function classify_literal( $literal ) {
		$first = substr( $literal, 0, 1 );
		$last  = substr( $literal, -1 );

		if ( strlen( $literal ) >= 2 && $first === $last && ( "'" === $first || '"' === $first || '`' === $first ) ) {
			$inner = substr( $literal, 1, -1 );

			return array(
				'value' => str_replace( array( '\\' . $first, '\\\\' ), array( $first, '\\' ), $inner ),
				'kind'  => 'string',
				'wrap'  => 'string',
				'quote' => $first,
			);
		}

		$inner = null;
		$wrap  = '';

		if ( '[' === $first && ']' === $last ) {
			$inner = substr( $literal, 1, -1 );
			$wrap  = 'brackets';
		} elseif ( preg_match( '/^array\s*\((.*)\)$/is', $literal, $m ) ) {
			$inner = $m[1];
			$wrap  = 'array';
		}

		if ( null !== $inner ) {
			$parts = array();

			foreach ( explode( ',', $inner ) as $part ) {
				$part = trim( $part );

				if ( '' === $part ) {
					continue;
				}

				$pf = substr( $part, 0, 1 );
				$pl = substr( $part, -1 );

				if ( strlen( $part ) >= 2 && $pf === $pl && ( "'" === $pf || '"' === $pf ) ) {
					$part = substr( $part, 1, -1 );
				}

				$parts[] = $part;
			}

			return array(
				'value' => implode( ', ', $parts ),
				'kind'  => 'list',
				'wrap'  => $wrap,
				'quote' => "'",
			);
		}

		return array(
			'value' => $literal,
			'kind'  => 'raw',
			'wrap'  => 'raw',
			'quote' => '',
		);
	}

	/**
	 * Writes a marked value back in the shape it was found in.
	 *
	 * @param string $value
	 * @param array  $leaf
	 * @return string
	 */
	private static function marker_literal( $value, $leaf ) {
		$wrap  = isset( $leaf['wrap'] ) ? $leaf['wrap'] : 'raw';
		$quote = isset( $leaf['quote'] ) && '' !== $leaf['quote'] ? $leaf['quote'] : "'";

		if ( 'string' === $wrap ) {
			$escaped = str_replace( array( '\\', $quote ), array( '\\\\', '\\' . $quote ), $value );
			$escaped = str_replace( array( "\r\n", "\r", "\n" ), '\\n', $escaped );

			return $quote . $escaped . $quote;
		}

		if ( 'brackets' === $wrap || 'array' === $wrap ) {
			$parts = array();

			foreach ( explode( ',', $value ) as $part ) {
				$part = trim( $part );

				if ( '' !== $part ) {
					$parts[] = $quote . str_replace( $quote, '\\' . $quote, $part ) . $quote;
				}
			}

			$inner = implode( ', ', $parts );

			return 'array' === $wrap ? 'array( ' . $inner . ' )' : '[' . $inner . ']';
		}

		// Raw: a colour, a number, true/false, a font stack. Written back
		// exactly as typed, which is the only thing that works across CSS,
		// PHP and JS at once.
		return trim( $value );
	}

	/**
	 * Flattens one parsed array into path => leaf.
	 *
	 * @param array  $array_node
	 * @param string $js Source, so each setting can pick up the comment
	 *                   written beside it.
	 * @return array<string, array>
	 */
	private static function collect_settings( $array_node, $js = '' ) {
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

			// A "siteWide" marker sitting beside key/value marks the whole
			// entry: {key: 'calendarID', value: 'c_x', siteWide: 'true'}
			$entry_wide = isset( $item['props'][ self::WIDE_KEY ] )
				? self::truthy( $item['props'][ self::WIDE_KEY ] )
				: false;

			if ( 'object' === $value['kind'] ) {
				// Inside a block it can mark the block, or name the
				// individual settings:
				//   siteWide: 'true'
				//   siteWide: ['badgeText', 'primaryColor']
				$block_wide = false;
				$named_wide = array();

				if ( isset( $value['props'][ self::WIDE_KEY ] ) ) {
					$marker = $value['props'][ self::WIDE_KEY ];

					if ( 'list' === $marker['kind'] ) {
						foreach ( $marker['items'] as $named ) {
							if ( 'string' === $named['kind'] ) {
								$named_wide[] = $named['text'];
							}
						}
					} else {
						$block_wide = self::truthy( $marker );
					}
				}

				foreach ( $value['props'] as $prop_name => $prop_node ) {
					if ( self::WIDE_KEY === $prop_name ) {
						continue; // The marker itself is not a setting.
					}

					if ( 'object' === $prop_node['kind'] ) {
						continue; // Only one level of nesting is editable.
					}

					$leaf           = self::leaf( $prop_node, $js );
					$leaf['global'] = $entry_wide || $block_wide || in_array( $prop_name, $named_wide, true );

					$settings[ $root . '.' . $prop_name ] = $leaf;
				}

				continue;
			}

			$leaf           = self::leaf( $value, $js );
			$leaf['global'] = $entry_wide;

			$settings[ $root ] = $leaf;
		}

		return $settings;
	}

	/**
	 * Whether a parsed node reads as true. Snippets write these as the
	 * string 'true' as often as the literal, so both count.
	 *
	 * @param array|null $node
	 * @return bool
	 */
	private static function truthy( $node ) {
		if ( ! is_array( $node ) ) {
			return false;
		}

		if ( 'string' === $node['kind'] ) {
			return 'true' === strtolower( trim( $node['text'] ) );
		}

		if ( 'raw' === $node['kind'] ) {
			return 'true' === strtolower( trim( $node['raw'] ) );
		}

		return false;
	}

	/**
	 * @param array  $node
	 * @param string $js
	 * @return array{value:string, kind:string, start:int, end:int, comment:string}
	 */
	private static function leaf( $node, $js = '' ) {
		$comment = '' === $js ? '' : self::comment_for( $js, $node['start'], $node['end'] );

		if ( 'list' === $node['kind'] ) {
			$parts = array();

			foreach ( $node['items'] as $item ) {
				$parts[] = 'string' === $item['kind'] ? $item['text'] : $item['raw'];
			}

			return array(
				'value'   => implode( ', ', $parts ),
				'kind'    => 'list',
				'start'   => $node['start'],
				'end'     => $node['end'],
				'comment' => $comment,
			);
		}

		return array(
			'value'   => 'string' === $node['kind'] ? $node['text'] : $node['raw'],
			'kind'    => $node['kind'],
			'start'   => $node['start'],
			'end'     => $node['end'],
			'comment' => $comment,
		);
	}

	/**
	 * Finds the comment a snippet author wrote for one setting: either
	 * trailing it on the same line, or sitting on the line above it.
	 * Whoever wrote the snippet knows what a setting does, so their own
	 * words make far better help text than anything guessed from a name.
	 *
	 * @param string $js
	 * @param int    $start Start of the value literal.
	 * @param int    $end   End of the value literal.
	 * @return string
	 */
	private static function comment_for( $js, $start, $end ) {
		$length = strlen( $js );

		// Same line, after the value: value: 'auto', // what this does
		$line_end = strpos( $js, "\n", $end );
		$line_end = false === $line_end ? $length : $line_end;
		$trailing = substr( $js, $end, $line_end - $end );
		$marker   = strpos( $trailing, '//' );

		if ( false !== $marker ) {
			$text = trim( substr( $trailing, $marker + 2 ) );

			if ( '' !== $text ) {
				return $text;
			}
		}

		// The line above, when it is nothing but a comment.
		$line_start = strrpos( substr( $js, 0, $start ), "\n" );

		if ( false === $line_start ) {
			return '';
		}

		$previous_start = strrpos( substr( $js, 0, $line_start ), "\n" );
		$previous_start = false === $previous_start ? 0 : $previous_start + 1;
		$previous       = trim( substr( $js, $previous_start, $line_start - $previous_start ) );

		if ( 0 === strpos( $previous, '//' ) ) {
			return trim( substr( $previous, 2 ) );
		}

		if ( 0 === strpos( $previous, '/*' ) ) {
			$text = trim( $previous, "/* \t" );

			return trim( str_replace( '*/', '', $text ) );
		}

		return '';
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

		foreach ( self::scan_markers( $js ) as $name => $leaf ) {
			if ( ! array_key_exists( $name, $overrides ) ) {
				continue;
			}

			$edits[] = array(
				'start'   => $leaf['start'],
				'end'     => $leaf['end'],
				'literal' => self::marker_literal( (string) $overrides[ $name ], $leaf ),
			);
		}

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
