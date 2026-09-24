<?php
/**
 * Every method the plugin calls on itself must actually exist.
 *
 * This exists because four admin action handlers were referenced by
 * handle_actions() and never written: the on/off switch, the archive links,
 * the per-alert settings form and the settings form all dispatched into
 * nothing. PHP only complains when the line is reached, the failsafe swallows
 * the resulting error, and the button just appears to do nothing — so nothing
 * anywhere pointed at the missing code.
 *
 * Reading the source with the tokenizer catches that class of bug on every
 * file at once, without having to boot WordPress.
 */

$root  = dirname( __DIR__ ) . '/acps-alert-popups/';
$files = array();

$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) );

foreach ( $it as $file ) {
	if ( 'php' === strtolower( $file->getExtension() ) ) {
		$files[] = $file->getPathname();
	}
}

sort( $files );

// Methods inherited from classes that live in other plugins. We cannot read
// those, so calls to them are accepted rather than guessed at.
$inherited = array(
	'FLBuilderModule' => array( 'render', 'add_css', 'add_js', 'get_icon', 'get_classname' ),
);

$fails = 0;

/**
 * Reports a failure.
 *
 * @param string $message What went wrong.
 * @return void
 */
function fail( $message ) {
	global $fails;
	$fails++;
	echo "FAIL $message\n";
}

/**
 * Pulls the class declarations, method declarations and self-calls out of one
 * file.
 *
 * @param string $path File to read.
 * @return array { class, extends, declared, called }
 */
function inspect( $path ) {
	$tokens   = token_get_all( (string) file_get_contents( $path ) );
	$count    = count( $tokens );
	$class    = '';
	$parent   = '';
	$declared = array();
	$called   = array();

	/**
	 * The index of the next token that is not whitespace or a comment.
	 *
	 * @param int $from Index to start looking after.
	 * @return int|null
	 */
	$next_index = function ( $from ) use ( $tokens, $count ) {
		for ( $j = $from + 1; $j < $count; $j++ ) {
			if ( is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}

			return $j;
		}

		return null;
	};

	/**
	 * The next meaningful token itself.
	 *
	 * @param int $from Index to start looking after.
	 * @return array|string|null
	 */
	$next = function ( $from ) use ( $tokens, $next_index ) {
		$j = $next_index( $from );

		return null === $j ? null : $tokens[ $j ];
	};

	/**
	 * Matches "<something> <op> name (" starting just after $from, which is how
	 * both $this->method( and self::method( look to the tokenizer.
	 *
	 * @param int $from Index of the object or class token.
	 * @param int $op   The operator token constant to expect.
	 * @return string Method name, or '' when this is not a call.
	 */
	$call_name = function ( $from, $op ) use ( $tokens, $next_index ) {
		$a = $next_index( $from );

		if ( null === $a || ! is_array( $tokens[ $a ] ) || $op !== $tokens[ $a ][0] ) {
			return '';
		}

		$b = $next_index( $a );

		if ( null === $b || ! is_array( $tokens[ $b ] ) || T_STRING !== $tokens[ $b ][0] ) {
			return '';
		}

		$c = $next_index( $b );

		return ( null !== $c && '(' === $tokens[ $c ] ) ? $tokens[ $b ][1] : '';
	};

	for ( $i = 0; $i < $count; $i++ ) {
		$token = $tokens[ $i ];

		if ( ! is_array( $token ) ) {
			continue;
		}

		if ( T_CLASS === $token[0] && '' === $class ) {
			$name = $next( $i );

			if ( is_array( $name ) && T_STRING === $name[0] ) {
				$class = $name[1];

				// "class Foo extends Bar" — find Bar.
				for ( $j = $i + 1; $j < $count && $j < $i + 12; $j++ ) {
					if ( is_array( $tokens[ $j ] ) && T_EXTENDS === $tokens[ $j ][0] ) {
						$ext = $next( $j );

						if ( is_array( $ext ) && T_STRING === $ext[0] ) {
							$parent = $ext[1];
						}

						break;
					}
				}
			}

			continue;
		}

		if ( T_FUNCTION === $token[0] ) {
			$name = $next( $i );

			if ( is_array( $name ) && T_STRING === $name[0] ) {
				$declared[] = $name[1];
			}

			continue;
		}

		// $this->method(
		if ( T_VARIABLE === $token[0] && '$this' === $token[1] ) {
			$name = $call_name( $i, T_OBJECT_OPERATOR );

			if ( '' !== $name ) {
				$called[ $name ] = true;
			}

			continue;
		}

		// self::method( and static::method(
		if ( T_STRING === $token[0] && in_array( strtolower( $token[1] ), array( 'self', 'static' ), true ) ) {
			$name = $call_name( $i, T_DOUBLE_COLON );

			if ( '' !== $name ) {
				$called[ $name ] = true;
			}
		}
	}

	return array(
		'class'    => $class,
		'extends'  => $parent,
		'declared' => $declared,
		'called'   => array_keys( $called ),
	);
}

$checked = 0;

foreach ( $files as $path ) {
	$info = inspect( $path );

	if ( '' === $info['class'] ) {
		continue; // Bootstrap and template files have no class to check.
	}

	$allowed = $info['declared'];

	if ( '' !== $info['extends'] ) {
		$parent  = $info['extends'];
		$allowed = array_merge( $allowed, isset( $inherited[ $parent ] ) ? $inherited[ $parent ] : array() );

		// A parent we know nothing about could supply anything, so skip rather
		// than report calls we cannot judge.
		if ( ! isset( $inherited[ $parent ] ) ) {
			continue;
		}
	}

	$rel = str_replace( dirname( __DIR__ ) . '/', '', $path );

	foreach ( $info['called'] as $method ) {
		$checked++;

		if ( ! in_array( $method, $allowed, true ) ) {
			fail( "$rel: {$info['class']} calls {$method}(), which nothing declares" );
		}
	}
}

if ( $checked < 50 ) {
	fail( "only $checked self-calls were inspected, so this test is not really looking at the plugin" );
}

// The four handlers whose absence prompted this test, named explicitly so a
// refactor that drops one again fails with an obvious message.
$admin    = inspect( $root . 'includes/class-acps-alerts-admin.php' );
$required = array( 'handle_toggle', 'handle_archive', 'handle_alert_save', 'handle_settings_save' );

foreach ( $required as $method ) {
	if ( ! in_array( $method, $admin['declared'], true ) ) {
		fail( "the admin screen has no {$method}(), so that action silently does nothing" );
	}
}

// Every hook goes through the failsafe. A raw add_action/add_filter/
// add_shortcode hands WordPress a callback nothing is watching, so a throw in
// it escapes as a fatal — which is how the updater's and the console's public
// request handlers once slipped past. Only the boot file itself may register
// raw hooks, because it runs before the failsafe class is loaded.
$raw_hooks = 0;
$it        = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );

foreach ( $it as $file ) {
	$path = $file->getPathname();
	$rel  = substr( $path, strlen( $root ) );

	if ( '.php' !== substr( $path, -4 ) || 'acps-alert-popups.php' === $rel || 'includes/class-acps-alerts-failsafe.php' === $rel ) {
		continue;
	}

	$source = file_get_contents( $path );
	$lines  = explode( "\n", $source );

	foreach ( token_get_all( $source ) as $tok ) {
		if ( ! is_array( $tok ) || T_STRING !== $tok[0] || ! in_array( $tok[1], array( 'add_action', 'add_filter', 'add_shortcode' ), true ) ) {
			continue;
		}

		// A shortcode is fine when its callback is a Failsafe::wrap(), which
		// sits on the call's line or the two after it.
		$call = implode( ' ', array_slice( $lines, $tok[2] - 1, 3 ) );

		if ( 'add_shortcode' === $tok[1] && false !== strpos( $call, 'Failsafe::wrap' ) ) {
			continue;
		}

		$raw_hooks++;
		fail( "{$rel}:{$tok[2]} registers {$tok[1]}() directly — use ACPS_Alerts_Failsafe::action()/filter()/wrap() so a failure in it is contained" );
	}
}

// A notice at the top of a page is only ever printed on this plugin's own
// screens. So every callback on a notice hook must check where it is, through
// ACPS_Alerts_Admin::is_own_screen() or the help layer's current_screen_key(),
// which only recognises the plugin's own screens.
$notice_callbacks = 0;
$notice_hooks     = 'admin_notices|all_admin_notices|network_admin_notices|user_admin_notices|in_admin_header';

$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );

foreach ( $it as $file ) {
	$path = $file->getPathname();

	if ( '.php' !== substr( $path, -4 ) ) {
		continue;
	}

	$source = file_get_contents( $path );

	// Both add_action( 'admin_notices', 'fn' ) and
	// ACPS_Alerts_Failsafe::action( 'admin_notices', array( $this, 'fn' ), ...).
	preg_match_all( "/(?:add_action|Failsafe::action)\(\s*'(?:$notice_hooks)'\s*,\s*(?:array\(\s*[^,]+,\s*)?'([a-z0-9_]+)'/i", $source, $found );

	foreach ( $found[1] as $callback ) {
		$notice_callbacks++;

		// The body runs to the closing brace at the function's own indent.
		if ( ! preg_match( '/^(\t*)(?:(?:public|protected|private|static)\s+)*function\s+' . preg_quote( $callback, '/' ) . '\s*\([^)]*\)\s*\{(.*?)\n\1\}/ms', $source, $body ) ) {
			fail( "notice callback {$callback}() could not be found in " . basename( $path ) );
			continue;
		}

		if ( false === strpos( $body[2], 'is_own_screen' ) && false === strpos( $body[2], 'current_screen_key' ) ) {
			fail( "notice callback {$callback}() in " . basename( $path ) . ' does not check it is on one of the plugin\'s own screens, so it could show at the top of any page' );
		}
	}
}

if ( 0 === $notice_callbacks ) {
	fail( 'no notice callbacks were found to check — the scan is broken' );
}

echo $fails ? "\n$fails failing case(s)\n" : "All wiring cases passed ($checked self-calls inspected, no unguarded hooks, $notice_callbacks notice callbacks confined to the plugin's screens)\n";
exit( $fails ? 1 : 0 );
