<?php
/**
 * Plugin Name:       WPCode Values for Beaver Builder
 * Plugin URI:        https://acpsmd.org
 * Description:       Reads the "configurations" array out of your WPCode snippets and lets you pick and edit those settings from a Beaver Builder module, per page.
 * Version:           3.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.0
 * Author:            ACPS
 * Text Domain:       wpcode-bb-values
 *
 * ---------------------------------------------------------------------
 * DESIGN
 * ---------------------------------------------------------------------
 * This is a deliberate rewrite of an earlier version that built its
 * Beaver Builder fields at runtime and swapped them with BB's "toggle"
 * mechanism. That is the most fragile thing you can hand BB's settings
 * form, and it is not needed here.
 *
 * Everything below is therefore as boring as possible:
 *
 *  - The module's field schema is FIXED. Same fields, same order, every
 *    request, on every site. Nothing about it depends on the database.
 *  - Every field is 'text', and every key used in the schema below is
 *    one Beaver Builder's own modules use. Beaver Builder renders each
 *    field by loading a file named after its 'type', so an invented
 *    type does not degrade - the include fails, PHP prints a warning
 *    into the middle of the AJAX response, and Beaver Builder reports
 *    a plugin conflict the moment you open the module to edit it. An
 *    earlier version of this plugin did exactly that with a made-up
 *    'html' field type.
 *  - The plugin hooks exactly one thing: 'init', to register the
 *    module. It adds no filters to anything Beaver Builder owns.
 *  - The snippet's own output is buffered, so a stray notice from your
 *    snippet cannot land in the middle of a Beaver Builder AJAX
 *    response.
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

define( 'WPCODEBBV_VERSION', '3.0.0' );
define( 'WPCODEBBV_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPCODEBBV_URL', plugin_dir_url( __FILE__ ) );

/** How many settings one module instance can override. */
define( 'WPCODEBBV_SLOTS', 12 );

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
 * Reads the code of every published WPCode snippet and returns every
 * setting found in a "configurations" array in any of them, as
 * path => label.
 *
 * WPCode's storage is not a public API, so this looks in the snippet's
 * post content first and then at the meta keys WPCode has used, and
 * simply finds nothing if none of them pan out. Nothing here is
 * required for the plugin to work - it only populates the dropdowns.
 *
 * The result is cached, and the cache is dropped whenever a snippet is
 * saved.
 *
 * @param bool $force Skip the cache.
 * @return array<string, string>
 */
function wpcodebbv_settings_index( $force = false ) {
	if ( ! $force ) {
		$cached = get_transient( WPCODEBBV_CACHE );

		if ( is_array( $cached ) ) {
			return $cached;
		}
	}

	$index      = array();
	$candidates = array();

	if ( ! class_exists( 'WPCodeBBV_Scanner' ) || ! post_type_exists( 'wpcode' ) ) {
		set_transient( WPCODEBBV_CACHE, $index, HOUR_IN_SECONDS );

		return $index;
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

		$title = isset( $snippet->post_title ) && '' !== $snippet->post_title
			? $snippet->post_title
			: sprintf( '#%d', (int) $snippet->ID );

		foreach ( $arrays as $array ) {
			foreach ( $array['settings'] as $path => $leaf ) {
				$label = $path;

				if ( '' !== (string) $leaf['value'] ) {
					$short  = (string) $leaf['value'];
					$short  = strlen( $short ) > 30 ? substr( $short, 0, 30 ) . '...' : $short;
					$label .= '  (' . $short . ')';
				}

				$candidates[ $path ][] = array(
					'name'  => $array['name'],
					'title' => $title,
					'label' => $label,
				);
			}
		}
	}

	// A path only needs to be qualified by its array's name when two
	// snippets really do define the same one. With a single snippet -
	// the normal case - the list stays short and readable.
	foreach ( $candidates as $path => $sources ) {
		if ( 1 === count( $sources ) ) {
			$index[ $path ] = $sources[0]['label'];
			continue;
		}

		foreach ( $sources as $source ) {
			$index[ $source['name'] . ':' . $path ] = $source['title'] . ' - ' . $source['label'];
		}
	}

	// Shortest paths first, then alphabetically, so the plain
	// "badgeText" style entries come before the scoped duplicates.
	uksort(
		$index,
		function ( $a, $b ) {
			$a_scoped = false !== strpos( $a, ':' );
			$b_scoped = false !== strpos( $b, ':' );

			if ( $a_scoped !== $b_scoped ) {
				return $a_scoped ? 1 : -1;
			}

			return strcasecmp( $a, $b );
		}
	);

	set_transient( WPCODEBBV_CACHE, $index, DAY_IN_SECONDS );

	return $index;
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
 * The module's field schema.
 *
 * The SHAPE of this is fixed: the same tabs, the same sections, the same
 * number of fields, in the same order, on every request. Only the option
 * list inside the "setting" dropdowns is read from the database, and an
 * options array is plain data - unlike a field 'type', which Beaver
 * Builder turns into a file it has to load.
 *
 * Every field type here ('text', 'select', 'textarea') is one Beaver
 * Builder's own modules use.
 *
 * @return array
 */
function wpcodebbv_form() {
	$options = array( '' => __( '— not used —', 'wpcode-bb-values' ) );

	try {
		foreach ( wpcodebbv_settings_index() as $path => $label ) {
			$options[ $path ] = $label;
		}
	} catch ( \Throwable $e ) {
		wpcodebbv_log( 'could not build the settings list: ' . $e->getMessage() );
	}

	$found_any = count( $options ) > 1;

	$rows = array();

	for ( $i = 1; $i <= WPCODEBBV_SLOTS; $i++ ) {
		$rows[ 'setting_' . $i ] = array(
			'type'    => 'select',
			'label'   => sprintf(
				/* translators: %d: row number */
				__( 'Setting %d', 'wpcode-bb-values' ),
				$i
			),
			'default' => '',
			'options' => $options,
			'help'    => 1 === $i
				? __( 'Pick one of the settings found in your snippet\'s configurations array, then type the value you want for this page in the box below. The value in brackets is what the snippet uses by default.', 'wpcode-bb-values' )
				: '',
		);

		$rows[ 'value_' . $i ] = array(
			'type'    => 'text',
			'label'   => sprintf(
				/* translators: %d: row number */
				__( 'Value %d', 'wpcode-bb-values' ),
				$i
			),
			'default' => '',
		);
	}

	return array(
		'general' => array(
			'title'    => __( 'WPCode Values', 'wpcode-bb-values' ),
			'sections' => array(
				'snippet'  => array(
					'title'  => __( 'Snippet', 'wpcode-bb-values' ),
					'fields' => array(
						'snippet_tag' => array(
							'type'    => 'text',
							'label'   => __( 'Shortcode tag', 'wpcode-bb-values' ),
							'default' => '',
							'help'    => __( 'The tag WPCode gave your snippet, without the square brackets. For example: wpcode_snippet_123', 'wpcode-bb-values' ),
						),
					),
				),
				'settings' => array(
					'title'  => $found_any
						? __( 'Settings', 'wpcode-bb-values' )
						: __( 'Settings (none found yet)', 'wpcode-bb-values' ),
					'fields' => $rows,
				),
				'advanced' => array(
					'title'  => __( 'Advanced', 'wpcode-bb-values' ),
					'fields' => array(
						'custom_settings' => array(
							'type'    => 'textarea',
							'rows'    => 6,
							'label'   => __( 'Extra settings', 'wpcode-bb-values' ),
							'default' => '',
							'help'    => __( 'One per line, as path = value, for anything the dropdowns did not pick up. Example: noSchoolEvent.badgeText = No School. For a list of words, separate them with commas. Lines starting with # are ignored.', 'wpcode-bb-values' ),
						),
					),
				),
			),
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

	$index = wpcodebbv_settings_index();
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
		<p><?php esc_html_e( 'This plugin reads that array and lists every setting in it. Drop the "WPCode Values" module on a page, put the snippet\'s shortcode tag in the Snippet tab, then pick the settings you want to change on this page and type new values. Everything you do not pick keeps the value written in the snippet.', 'wpcode-bb-values' ); ?></p>
		<p><?php esc_html_e( 'Nothing in your snippet needs to change. The values are edited in the script the snippet outputs, on the way to the browser, only on the page holding that module. A setting inside a nested block is written with a dot: noSchoolEvent.badgeText. A list of words is typed with commas between them.', 'wpcode-bb-values' ); ?></p>

		<h2>
			<?php esc_html_e( 'Settings found in your snippets', 'wpcode-bb-values' ); ?>
			<a href="<?php echo esc_url( add_query_arg( 'wpcodebbv_rescan', '1' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Rescan', 'wpcode-bb-values' ); ?></a>
		</h2>

		<?php if ( empty( $index ) ) : ?>
			<p>
				<?php esc_html_e( 'No configurations array was found in any snippet yet. The dropdowns in the module will be empty, but you can still type settings by hand in the module\'s Advanced tab, as "path = value" lines - those are applied to whatever the snippet prints, so they work either way.', 'wpcode-bb-values' ); ?>
			</p>
		<?php else : ?>
			<p><?php esc_html_e( 'These are the settings you can pick in the module. The value in brackets is the one written in the snippet.', 'wpcode-bb-values' ); ?></p>
			<table class="widefat striped" style="max-width: 820px;">
				<tbody>
				<?php
				foreach ( $index as $path => $label ) {
					if ( false !== strpos( $path, ':' ) ) {
						continue; // Skip the snippet-scoped duplicates.
					}
					?>
					<tr>
						<td style="width: 320px;"><code><?php echo esc_html( $path ); ?></code></td>
						<td><?php echo esc_html( $label ); ?></td>
					</tr>
					<?php
				}
				?>
				</tbody>
			</table>
		<?php endif; ?>

		<h2><?php esc_html_e( 'If the module is not listed in the editor', 'wpcode-bb-values' ); ?></h2>
		<p><?php esc_html_e( 'Check Settings > Beaver Builder > Modules. If that list has ever been narrowed down, a newly installed module stays off until you tick it.', 'wpcode-bb-values' ); ?></p>
	</div>
	<?php
}
