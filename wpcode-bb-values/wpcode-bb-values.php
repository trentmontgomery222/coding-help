<?php
/**
 * Plugin Name:       WPCode Values for Beaver Builder
 * Plugin URI:        https://acpsmd.org
 * Description:       Reads the "configurations" array out of your WPCode snippets and puts every setting in it on a Beaver Builder module, so a page editor can change them per page.
 * Version:           4.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.0
 * Author:            ACPS
 * Text Domain:       wpcode-bb-values
 *
 * ---------------------------------------------------------------------
 * DESIGN
 * ---------------------------------------------------------------------
 * A WPCode snippet keeps its settings in a JavaScript array:
 *
 *     var configurations = [ {key: 'eventColor', value: 'blue'}, ... ];
 *
 * Those values are literals in the script the snippet prints, so they
 * cannot be passed in as shortcode attributes. This plugin reads that
 * array, gives every setting in it a field on the Beaver Builder
 * module, and writes the edited values back into the snippet's output
 * on its way to the browser - only on the page holding that module.
 *
 * The field list therefore does depend on the database, but its SHAPE
 * is held to what Beaver Builder can be relied on to render. Two rules,
 * both learned the hard way:
 *
 *  - Field 'type' is only ever 'text', 'select' or 'textarea'. Beaver
 *    Builder turns a field's type into a file it loads while rendering
 *    the settings form, so an invented type does not degrade: the
 *    include fails, PHP prints a warning into the middle of the AJAX
 *    response, and Beaver Builder reports a plugin conflict the moment
 *    you open the module to edit it. An earlier version of this plugin
 *    did exactly that with a made-up 'html' field type.
 *  - No 'toggle'. Beaver Builder's toggle takes a list of field NAMES
 *    that exist elsewhere in the form; the same earlier version handed
 *    it field definitions instead, leaving the form pointing at fields
 *    that were never registered. One section per snippet needs none.
 *
 * The plugin hooks 'init' (register the module), 'admin_menu' (a
 * read-only help screen) and two snippet-save hooks that only clear a
 * cache. It filters nothing Beaver Builder owns. The snippet's own
 * output is buffered, so a stray notice from a snippet cannot land in
 * the middle of a Beaver Builder AJAX response either.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Double-load guard. This is a constant check on purpose: PHP
 * early-binds an unconditional class declaration at compile time, so
 * guarding on a class this file declares would already be true on the
 * first load and skip the whole plugin.
 */
if ( defined( 'WPCODEBBV_VERSION' ) ) {
	return;
}

define( 'WPCODEBBV_VERSION', '4.1.0' );
define( 'WPCODEBBV_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPCODEBBV_URL', plugin_dir_url( __FILE__ ) );

/** Cache key for the scan of every snippet's configurations array. */
define( 'WPCODEBBV_CACHE', 'wpcodebbv_settings_index' );

$wpcodebbv_scanner = WPCODEBBV_DIR . 'includes/class-wpcodebbv-scanner.php';

if ( file_exists( $wpcodebbv_scanner ) ) {
	require_once $wpcodebbv_scanner;
}

unset( $wpcodebbv_scanner );

/**
 * Writes to the PHP error log, prefixed so it is easy to grep for.
 *
 * @param string $message
 */
function wpcodebbv_log( $message ) {
	if ( function_exists( 'error_log' ) ) {
		error_log( '[WPCode Values] ' . $message );
	}
}

/**
 * Scans every WPCode snippet and returns what was found, keyed by
 * snippet ID:
 *
 *     7 => array(
 *         'title'    => 'ACPS Calendar',
 *         'settings' => array( 'noSchoolEvent.badgeText' => array( 'value' => 'No School', 'kind' => 'string' ), ... ),
 *     )
 *
 * WPCode's storage is not a public API, so this looks in the snippet's
 * post content first and then at the meta keys WPCode has used, and
 * simply finds nothing if none of them pan out.
 *
 * Cached, and the cache is dropped whenever a snippet is saved.
 *
 * @param bool $force Skip the cache.
 * @return array<int, array{title:string, settings:array}>
 */
function wpcodebbv_snippets( $force = false ) {
	if ( ! $force ) {
		$cached = get_transient( WPCODEBBV_CACHE );

		if ( is_array( $cached ) ) {
			return $cached;
		}
	}

	$found = array();

	if ( ! class_exists( 'WPCodeBBV_Scanner' ) || ! post_type_exists( 'wpcode' ) ) {
		set_transient( WPCODEBBV_CACHE, $found, HOUR_IN_SECONDS );

		return $found;
	}

	$snippets = get_posts(
		array(
			'post_type'        => 'wpcode',
			'post_status'      => array( 'publish', 'draft' ),
			'posts_per_page'   => 50,
			'no_found_rows'    => true,
			'suppress_filters' => true,
		)
	);

	if ( ! is_array( $snippets ) ) {
		$snippets = array();
	}

	foreach ( $snippets as $snippet ) {
		if ( ! is_object( $snippet ) || empty( $snippet->ID ) ) {
			continue;
		}

		$code = isset( $snippet->post_content ) ? (string) $snippet->post_content : '';

		if ( '' === trim( $code ) ) {
			foreach ( array( '_wpcode_code', 'wpcode_code', '_wpcode_snippet_code' ) as $meta_key ) {
				$stored = get_post_meta( $snippet->ID, $meta_key, true );

				if ( is_string( $stored ) && '' !== trim( $stored ) ) {
					$code = $stored;
					break;
				}
			}
		}

		if ( '' === trim( $code ) ) {
			continue;
		}

		try {
			$arrays = WPCodeBBV_Scanner::scan( $code );
		} catch ( \Throwable $e ) {
			wpcodebbv_log( 'could not scan snippet ' . (int) $snippet->ID . ': ' . $e->getMessage() );
			continue;
		}

		$settings = array();

		foreach ( $arrays as $array ) {
			foreach ( $array['settings'] as $path => $leaf ) {
				// First array wins if two in one snippet share a path.
				if ( ! isset( $settings[ $path ] ) ) {
					$settings[ $path ] = array(
						'value'   => (string) $leaf['value'],
						'kind'    => $leaf['kind'],
						'comment' => isset( $leaf['comment'] ) ? (string) $leaf['comment'] : '',
					);
				}
			}
		}

		if ( empty( $settings ) ) {
			continue;
		}

		$found[ (int) $snippet->ID ] = array(
			'title'    => isset( $snippet->post_title ) && '' !== $snippet->post_title
				? $snippet->post_title
				: sprintf( '#%d', (int) $snippet->ID ),
			'settings' => $settings,
		);
	}

	set_transient( WPCODEBBV_CACHE, $found, DAY_IN_SECONDS );

	return $found;
}

/**
 * Drops the cached scan when a snippet is edited.
 */
function wpcodebbv_clear_index() {
	delete_transient( WPCODEBBV_CACHE );
}
add_action( 'save_post_wpcode', 'wpcodebbv_clear_index' );
add_action( 'deleted_post', 'wpcodebbv_clear_index' );

/**
 * The Beaver Builder setting name for one snippet's setting. Derived,
 * never stored, so the module can work back to the path at render time
 * from the snippet ID alone.
 *
 * @param int    $snippet_id
 * @param string $path
 * @return string
 */
function wpcodebbv_field_key( $snippet_id, $path ) {
	return 's' . (int) $snippet_id . '_' . preg_replace( '/[^A-Za-z0-9]/', '_', $path );
}

/**
 * True when a snippet's value is being used as a yes/no flag. These are
 * usually written as the strings 'true' and 'false' rather than real
 * booleans, and either way they should be a dropdown rather than a text
 * box someone can typo into.
 *
 * @param string $value
 * @return bool
 */
function wpcodebbv_is_boolean( $value ) {
	return in_array( strtolower( trim( (string) $value ) ), array( 'true', 'false' ), true );
}

/**
 * The help text for one setting, shown behind the "?" icon next to its
 * box in Beaver Builder.
 *
 * A comment written next to the setting in the snippet always wins -
 * whoever wrote the snippet knows what it does. Failing that, the name
 * itself says a good deal, so common ones get a real description rather
 * than a restatement of the label. Either way the value the snippet
 * ships with is quoted at the end, which is the thing an editor most
 * often wants to know before changing it.
 *
 * @param string $path
 * @param array  $leaf
 * @return string
 */
function wpcodebbv_describe( $path, $leaf ) {
	$comment = isset( $leaf['comment'] ) ? trim( (string) $leaf['comment'] ) : '';
	$value   = (string) $leaf['value'];
	$name    = strtolower( substr( $path, strrpos( $path, '.' ) === false ? 0 : strrpos( $path, '.' ) + 1 ) );
	$is_list = isset( $leaf['kind'] ) && 'list' === $leaf['kind'];

	if ( '' !== $comment ) {
		// An author's comment rarely ends in a full stop, and it is
		// followed here by more sentences.
		$text = in_array( substr( $comment, -1 ), array( '.', '!', '?', ':' ), true )
			? $comment
			: $comment . '.';
	} elseif ( 'searchforwords' === $name ) {
		$text = __( 'Words to look for in an event\'s title. An event matching any of them is treated as this kind of event.', 'wpcode-bb-values' );
	} elseif ( 'badgetext' === $name ) {
		$text = __( 'The short label printed on the badge for these events.', 'wpcode-bb-values' );
	} elseif ( 'primarycolor' === $name ) {
		$text = __( 'The main colour for these events. A CSS colour name (red, royalblue) or a hex code (#a13c7f).', 'wpcode-bb-values' );
	} elseif ( 'secondarycolor' === $name ) {
		$text = __( 'The secondary colour for these events, used alongside the main one. A CSS colour name or a hex code.', 'wpcode-bb-values' );
	} elseif ( false !== strpos( $name, 'color' ) || false !== strpos( $name, 'colour' ) ) {
		$text = __( 'A CSS colour name (red, royalblue) or a hex code (#a13c7f).', 'wpcode-bb-values' );
	} elseif ( 'background' === $name ) {
		$text = __( 'The background for these events. "auto" lets the snippet work it out from the colours above.', 'wpcode-bb-values' );
	} elseif ( in_array( $name, array( 'activated', 'active', 'enabled' ), true ) ) {
		$text = __( 'Turns this whole rule on or off. Set it to false and these events are treated like any other.', 'wpcode-bb-values' );
	} elseif ( 0 === strpos( $name, 'show' ) || 0 === strpos( $name, 'hide' ) || false !== strpos( $name, 'badge' ) ) {
		$text = __( 'Turns this part of the display on or off.', 'wpcode-bb-values' );
	} elseif ( false !== strpos( $name, 'combine' ) ) {
		$text = __( 'Whether an event running over several days is drawn as one continuous block.', 'wpcode-bb-values' );
	} elseif ( false !== strpos( $name, 'watermark' ) ) {
		$text = __( 'Whether the small credit line is shown.', 'wpcode-bb-values' );
	} elseif ( 'calendarid' === $name || ( false !== strpos( $name, 'calendar' ) && false !== strpos( $name, 'id' ) ) ) {
		$text = __( 'Which calendar the events come from. This is the calendar ID from the calendar\'s own settings page.', 'wpcode-bb-values' );
	} elseif ( 'id' === $name || false !== strpos( $name, 'id' ) ) {
		$text = __( 'An identifier the snippet looks things up by. Change it only if you know what it points at.', 'wpcode-bb-values' );
	} elseif ( false !== strpos( $name, 'name' ) || false !== strpos( $name, 'title' ) || false !== strpos( $name, 'label' ) ) {
		$text = __( 'A name shown on screen.', 'wpcode-bb-values' );
	} elseif ( false !== strpos( $name, 'text' ) ) {
		$text = __( 'Text shown on screen.', 'wpcode-bb-values' );
	} elseif ( false !== strpos( $name, 'url' ) || false !== strpos( $name, 'link' ) ) {
		$text = __( 'A web address.', 'wpcode-bb-values' );
	} else {
		$text = sprintf(
			/* translators: %s: the setting's name */
			__( 'Sets "%s" in the snippet.', 'wpcode-bb-values' ),
			$path
		);
	}

	if ( $is_list ) {
		$text .= ' ' . __( 'Separate several with commas.', 'wpcode-bb-values' );
	}

	if ( '' !== $value ) {
		$text .= ' ' . sprintf(
			/* translators: %s: the value written in the snippet */
			__( 'The snippet\'s own value is "%s".', 'wpcode-bb-values' ),
			$value
		);
	}

	$text .= ' ' . __( 'Clear this box to go back to the snippet\'s value.', 'wpcode-bb-values' );

	return $text;
}

/**
 * The module's field schema.
 *
 * Every setting found in every snippet gets its own field, grouped into
 * one section per snippet and pre-filled with the value the snippet
 * currently uses. Nothing has to be picked from a list: open the module
 * and the settings are already there.
 *
 * Notes on the two things that have broken this plugin before:
 *
 *  - Field TYPES here are only 'text', 'select' and 'textarea'. Beaver
 *    Builder turns a field's type into a file it loads while rendering
 *    the settings form, so an invented type takes the whole form down.
 *  - There is no 'toggle' anywhere. Beaver Builder's toggle expects a
 *    list of field NAMES that exist elsewhere in the form; handing it
 *    field definitions instead, as an earlier version of this plugin
 *    did, leaves the form referring to fields that were never
 *    registered.
 *
 * @return array
 */
function wpcodebbv_form() {
	$sections = array(
		'snippet' => array(
			'title'  => __( 'Snippet', 'wpcode-bb-values' ),
			'fields' => array(
				'wpcode_id' => array(
					'type'    => 'text',
					'label'   => __( 'WPCode snippet ID', 'wpcode-bb-values' ),
					'default' => '',
					'help'    => __( 'Just the number. WPCode shows it as [wpcode id="123"] on the snippet, and it is also the id= number in the address bar while editing that snippet.', 'wpcode-bb-values' ),
				),
			),
		),
	);

	$snippets = array();

	try {
		$snippets = wpcodebbv_snippets();
	} catch ( \Throwable $e ) {
		wpcodebbv_log( 'could not build the settings list: ' . $e->getMessage() );
	}

	$many_snippets = count( $snippets ) > 1;

	foreach ( $snippets as $snippet_id => $snippet ) {
		// Group by the top-level key, so everything belonging to
		// noSchoolEvent sits together in its own panel rather than in one
		// long list. Settings with no nesting share a "General" group.
		$groups = array();

		foreach ( $snippet['settings'] as $path => $leaf ) {
			$dot   = strpos( $path, '.' );
			$group = false === $dot ? '' : substr( $path, 0, $dot );
			$label = false === $dot ? $path : substr( $path, $dot + 1 );

			$groups[ $group ][ $path ] = array(
				'label' => $label,
				'leaf'  => $leaf,
			);
		}

		foreach ( $groups as $group => $members ) {
			$fields = array();

			foreach ( $members as $path => $member ) {
				$key     = wpcodebbv_field_key( $snippet_id, $path );
				$leaf    = $member['leaf'];
				$current = (string) $leaf['value'];
				$help    = wpcodebbv_describe( $path, $leaf );

				if ( wpcodebbv_is_boolean( $current ) ) {
					// A yes/no setting can only ever be true or false.
					$fields[ $key ] = array(
						'type'    => 'select',
						'label'   => $member['label'],
						'default' => strtolower( trim( $current ) ),
						'options' => array(
							'true'  => __( 'true', 'wpcode-bb-values' ),
							'false' => __( 'false', 'wpcode-bb-values' ),
						),
						'help'    => $help,
					);

					continue;
				}

				$fields[ $key ] = array(
					'type'    => 'text',
					'label'   => $member['label'],
					'default' => $current,
					'help'    => $help,
				);
			}

			if ( empty( $fields ) ) {
				continue;
			}

			$title = '' === $group
				? __( 'General', 'wpcode-bb-values' )
				: $group;

			if ( $many_snippets ) {
				$title = $snippet['title'] . ' - ' . $title;
			}

			$sections[ 's' . $snippet_id . '_' . ( '' === $group ? 'general' : preg_replace( '/[^A-Za-z0-9]/', '_', $group ) ) ] = array(
				'title'  => $title,
				'fields' => $fields,
				// Groups start closed so the panel opens as a short list
				// of headings rather than every setting at once. Beaver
				// Builder versions that do not know this key just render
				// the section open, which is only a cosmetic difference -
				// unlike a field 'type', a section key is read or ignored,
				// never turned into a file to load.
				'collapsed' => '' !== $group,
			);
		}
	}

	$sections['advanced'] = array(
		'title'  => __( 'Advanced', 'wpcode-bb-values' ),
		'fields' => array(
			'custom_settings' => array(
				'type'    => 'textarea',
				'rows'    => 6,
				'label'   => __( 'Extra settings', 'wpcode-bb-values' ),
				'default' => '',
				'help'    => __( 'One per line, as path = value, for anything above that was not picked up. Example: noSchoolEvent.badgeText = No School. These win over the boxes above. Lines starting with # are ignored.', 'wpcode-bb-values' ),
			),
		),
	);

	return array(
		'general' => array(
			'title'    => __( 'WPCode Values', 'wpcode-bb-values' ),
			'sections' => $sections,
		),
	);
}

/**
 * Parses the "Extra settings" box into path => value.
 *
 * @param string $text
 * @return array<string, string>
 */
function wpcodebbv_parse_lines( $text ) {
	$values = array();

	if ( ! is_string( $text ) || '' === trim( $text ) ) {
		return $values;
	}

	$lines = preg_split( '/\r\n|\r|\n/', $text );

	if ( ! is_array( $lines ) ) {
		return $values;
	}

	foreach ( $lines as $line ) {
		$line = trim( $line );

		if ( '' === $line || '#' === substr( $line, 0, 1 ) || '//' === substr( $line, 0, 2 ) ) {
			continue;
		}

		$split = strpos( $line, '=' );

		if ( false === $split ) {
			continue;
		}

		$path = trim( substr( $line, 0, $split ) );

		// A path is a setting name, optionally scoped and dotted -
		// "badgeText", "noSchoolEvent.badgeText", "configurations:x.y".
		if ( ! preg_match( '/^[A-Za-z0-9_:.\-]+$/', $path ) ) {
			continue;
		}

		$values[ $path ] = trim( substr( $line, $split + 1 ) );
	}

	return $values;
}

/**
 * Registers the module with Beaver Builder. Does nothing at all unless
 * Beaver Builder is present, so this plugin is inert on a site without
 * it rather than being an error.
 */
function wpcodebbv_register_module() {
	if ( ! class_exists( 'FLBuilder' ) || ! class_exists( 'FLBuilderModule' ) ) {
		return;
	}

	if ( ! is_callable( array( 'FLBuilder', 'register_module' ) ) ) {
		return;
	}

	$module_file = WPCODEBBV_DIR . 'modules/wpcode-values/wpcode-values.php';

	if ( ! file_exists( $module_file ) ) {
		return;
	}

	try {
		require_once $module_file;

		if ( ! class_exists( 'WPCodeBBV_Module' ) ) {
			return;
		}

		FLBuilder::register_module( 'WPCodeBBV_Module', wpcodebbv_form() );
	} catch ( \Throwable $e ) {
		// A failure here costs the module, never the site.
		wpcodebbv_log( 'could not register the module: ' . $e->getMessage() );
	}
}
add_action( 'init', 'wpcodebbv_register_module', 20 );

/**
 * A short help screen under Tools. Read-only: it stores no settings and
 * registers no post type, so there is nothing here that can interfere
 * with editing or saving a page.
 */
function wpcodebbv_help_menu() {
	add_management_page(
		__( 'WPCode Values', 'wpcode-bb-values' ),
		__( 'WPCode Values', 'wpcode-bb-values' ),
		'edit_posts',
		'wpcode-bb-values',
		'wpcodebbv_help_page'
	);
}
add_action( 'admin_menu', 'wpcodebbv_help_menu' );

function wpcodebbv_help_page() {
	$bb     = class_exists( 'FLBuilder' );
	$wpcode = post_type_exists( 'wpcode' );

	if ( isset( $_GET['wpcodebbv_rescan'] ) ) {
		wpcodebbv_clear_index();
	}

	$snippets = wpcodebbv_snippets();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'WPCode Values for Beaver Builder', 'wpcode-bb-values' ); ?></h1>

		<p>
			<?php
			printf(
				/* translators: 1: version, 2: Beaver Builder status, 3: WPCode status */
				esc_html__( 'Version %1$s. Beaver Builder: %2$s. WPCode: %3$s.', 'wpcode-bb-values' ),
				esc_html( WPCODEBBV_VERSION ),
				$bb ? esc_html__( 'detected', 'wpcode-bb-values' ) : esc_html__( 'NOT detected - the module cannot appear until it is active', 'wpcode-bb-values' ),
				$wpcode ? esc_html__( 'detected', 'wpcode-bb-values' ) : esc_html__( 'not detected', 'wpcode-bb-values' )
			);
			?>
		</p>

		<h2><?php esc_html_e( 'How it works', 'wpcode-bb-values' ); ?></h2>
		<p><?php esc_html_e( 'Your snippet already keeps its settings in an array called configurations, like this:', 'wpcode-bb-values' ); ?></p>
		<pre>var configurations = [
    {key: 'eventColor', value: 'blue'},
    {key: 'noSchoolEvent', value: {
        badgeText: 'No School',
        searchForWords: ['schools closed']
    }}
];</pre>
		<p><?php esc_html_e( 'Drop the "WPCode Values" module on a page and put your snippet\'s ID in it - that is the number in [wpcode id="123"]. Every setting in that snippet\'s configurations array then appears in the module, already filled in with the value the snippet uses. Change the ones you want for this page and leave the rest alone. Clear a box to let the snippet\'s own value through again.', 'wpcode-bb-values' ); ?></p>
		<p><?php esc_html_e( 'Settings written as true or false become a true/false dropdown, so they cannot be given a value the snippet will not understand. Settings that belong to a block - everything under noSchoolEvent, say - are grouped under that name and start collapsed, so the panel opens as a short list of headings.', 'wpcode-bb-values' ); ?></p>
		<p><?php esc_html_e( 'Every box has a help icon. If you put a comment next to a setting in the snippet, that comment becomes its help text, so the person who wrote the snippet decides what the editor is told.', 'wpcode-bb-values' ); ?></p>
		<p><?php esc_html_e( 'Nothing in your snippet needs to change. The values are edited in the script the snippet outputs, on the way to the browser, only on the page holding that module. A setting inside a nested block is written with a dot: noSchoolEvent.badgeText. A list of words is typed with commas between them.', 'wpcode-bb-values' ); ?></p>

		<h2>
			<?php esc_html_e( 'What was found in your snippets', 'wpcode-bb-values' ); ?>
			<a href="<?php echo esc_url( add_query_arg( 'wpcodebbv_rescan', '1' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Rescan', 'wpcode-bb-values' ); ?></a>
		</h2>

		<?php if ( empty( $snippets ) ) : ?>
			<p>
				<?php esc_html_e( 'No configurations array was found in any snippet yet, so the module will only show the Snippet and Advanced boxes. You can still set values by hand in the module\'s Advanced tab, as "path = value" lines - those are applied to whatever the snippet prints, so they work either way.', 'wpcode-bb-values' ); ?>
			</p>
		<?php else : ?>
			<?php foreach ( $snippets as $snippet_id => $snippet ) : ?>
				<h3>
					<?php echo esc_html( $snippet['title'] ); ?>
					<code>[wpcode id="<?php echo (int) $snippet_id; ?>"]</code>
					<span class="description">
						<?php
						printf(
							/* translators: %d: number of settings */
							esc_html( _n( '%d setting', '%d settings', count( $snippet['settings'] ), 'wpcode-bb-values' ) ),
							count( $snippet['settings'] )
						);
						?>
					</span>
				</h3>
				<table class="widefat striped" style="max-width: 820px; margin-bottom: 20px;">
					<tbody>
					<?php
					$last_group = null;

					foreach ( $snippet['settings'] as $path => $leaf ) :
						$dot   = strpos( $path, '.' );
						$group = false === $dot ? __( 'General', 'wpcode-bb-values' ) : substr( $path, 0, $dot );

						if ( $group !== $last_group ) :
							$last_group = $group;
							?>
							<tr>
								<th colspan="3" style="text-align: left;"><?php echo esc_html( $group ); ?></th>
							</tr>
							<?php
						endif;
						?>
						<tr>
							<td style="width: 260px;"><code><?php echo esc_html( false === $dot ? $path : substr( $path, $dot + 1 ) ); ?></code></td>
							<td style="width: 90px;">
								<?php
								if ( wpcodebbv_is_boolean( $leaf['value'] ) ) {
									esc_html_e( 'true/false', 'wpcode-bb-values' );
								} elseif ( 'list' === $leaf['kind'] ) {
									esc_html_e( 'list', 'wpcode-bb-values' );
								} else {
									esc_html_e( 'text', 'wpcode-bb-values' );
								}
								?>
							</td>
							<td>
								<strong><?php echo esc_html( $leaf['value'] ); ?></strong><br />
								<span class="description"><?php echo esc_html( wpcodebbv_describe( $path, $leaf ) ); ?></span>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endforeach; ?>
		<?php endif; ?>

		<h2><?php esc_html_e( 'If the module is not listed in the editor', 'wpcode-bb-values' ); ?></h2>
		<p><?php esc_html_e( 'Check Settings > Beaver Builder > Modules. If that list has ever been narrowed down, a newly installed module stays off until you tick it.', 'wpcode-bb-values' ); ?></p>
	</div>
	<?php
}
