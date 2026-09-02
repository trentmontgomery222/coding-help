<?php
/**
 * Plugin Name:       WPCode Values for Beaver Builder
 * Plugin URI:        https://acpsmd.org
 * Description:       Adds one Beaver Builder module where you type values in the editor and pass them straight to a WPCode snippet as shortcode attributes.
 * Version:           2.0.1
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

define( 'WPCODEBBV_VERSION', '2.0.1' );
define( 'WPCODEBBV_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPCODEBBV_URL', plugin_dir_url( __FILE__ ) );

/** How many name/value slots the module offers. */
define( 'WPCODEBBV_SLOTS', 8 );

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
 * The module's field schema. Fixed at all times - this function takes
 * no arguments and reads nothing, so it returns the same structure on
 * every request.
 *
 * @return array
 */
function wpcodebbv_form() {
	$value_fields = array();

	for ( $i = 1; $i <= WPCODEBBV_SLOTS; $i++ ) {
		$value_fields[ 'name_' . $i ] = array(
			'type'    => 'text',
			'label'   => sprintf(
				/* translators: %d: slot number */
				__( 'Name %d', 'wpcode-bb-values' ),
				$i
			),
			'default' => '',
			'help'    => 1 === $i
				? __( 'Type a name here and its value in the box below. Each filled-in row reaches your snippet as a shortcode attribute, so the name "headline" arrives as $atts[\'headline\']. Leave a row blank to skip it.', 'wpcode-bb-values' )
				: '',
		);

		$value_fields[ 'value_' . $i ] = array(
			'type'    => 'text',
			'label'   => sprintf(
				/* translators: %d: slot number */
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
				'snippet' => array(
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
				'values'  => array(
					'title'  => __( 'Values', 'wpcode-bb-values' ),
					'fields' => $value_fields,
				),
			),
		),
	);
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
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'WPCode Values for Beaver Builder', 'wpcode-bb-values' ); ?></h1>

		<p>
			<?php
			printf(
				/* translators: 1: plugin version, 2: Beaver Builder status, 3: WPCode status */
				esc_html__( 'Version %1$s. Beaver Builder: %2$s. WPCode: %3$s.', 'wpcode-bb-values' ),
				esc_html( WPCODEBBV_VERSION ),
				$bb ? esc_html__( 'detected', 'wpcode-bb-values' ) : esc_html__( 'NOT detected - the module cannot appear until it is active', 'wpcode-bb-values' ),
				$wpcode ? esc_html__( 'detected', 'wpcode-bb-values' ) : esc_html__( 'not detected', 'wpcode-bb-values' )
			);
			?>
		</p>

		<h2><?php esc_html_e( 'Step 1 - give your snippet a shortcode', 'wpcode-bb-values' ); ?></h2>
		<p><?php esc_html_e( 'In WPCode, open the snippet and set Insertion to Shortcode. WPCode shows you a tag such as [wpcode_snippet_123].', 'wpcode-bb-values' ); ?></p>

		<h2><?php esc_html_e( 'Step 2 - read the values in your snippet', 'wpcode-bb-values' ); ?></h2>
		<p><?php esc_html_e( 'Your snippet receives whatever you typed in the editor as shortcode attributes:', 'wpcode-bb-values' ); ?></p>
		<pre>$atts = shortcode_atts( array(
    'headline' =&gt; 'Default headline',
    'button_text' =&gt; 'Click here',
), $atts );

echo esc_html( $atts['headline'] );
echo esc_html( $atts['button_text'] );</pre>
		<p><?php esc_html_e( 'The same values are also available as a global, if you prefer that:', 'wpcode-bb-values' ); ?></p>
		<pre>$values = isset( $GLOBALS['wpcode_bb_values'] ) ? $GLOBALS['wpcode_bb_values'] : array();
echo esc_html( isset( $values['headline'] ) ? $values['headline'] : '' );</pre>

		<h2><?php esc_html_e( 'Step 3 - add the module to a page', 'wpcode-bb-values' ); ?></h2>
		<p><?php esc_html_e( 'Open the page in Beaver Builder, add the "WPCode Values" module from the WPCode group, put your shortcode tag in the Snippet tab, then fill in the Values tab: Name 1 "headline", Value 1 "Welcome to our clinic", and so on. Change a value, and the snippet re-renders with it.', 'wpcode-bb-values' ); ?></p>
		<p><?php esc_html_e( 'The names and values are stored on that one module, so the same snippet can appear on several pages with different values on each. None of this is ever shown to visitors - they only see what your snippet outputs.', 'wpcode-bb-values' ); ?></p>

		<h2><?php esc_html_e( 'If the module is not listed in the editor', 'wpcode-bb-values' ); ?></h2>
		<p><?php esc_html_e( 'Check Settings > Beaver Builder > Modules. If that list has ever been narrowed down, a newly installed module stays switched off until you tick it.', 'wpcode-bb-values' ); ?></p>
	</div>
	<?php
}
