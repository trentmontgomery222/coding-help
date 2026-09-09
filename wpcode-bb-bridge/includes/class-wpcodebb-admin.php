<?php
/**
 * Adds a "How It Works" help screen under the Configurations menu.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'WPCodeBB_Admin', false ) ) {
	return;
}

class WPCodeBB_Admin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_help_page' ) );
	}

	public function add_help_page() {
		add_submenu_page(
			'edit.php?post_type=' . WPCODEBB_CPT,
			__( 'How It Works', 'wpcode-bb-bridge' ),
			__( 'How It Works', 'wpcode-bb-bridge' ),
			'edit_posts',
			'wpcodebb-help',
			array( $this, 'render_help_page' )
		);
	}

	public function render_help_page() {
		?>
		<div class="wrap wpcodebb-help">
			<h1><?php esc_html_e( 'WPCode Values for Beaver Builder', 'wpcode-bb-bridge' ); ?></h1>

			<p><?php esc_html_e( 'This plugin lets a page editor change the values a WPCode snippet uses, right from the Beaver Builder editor, without opening the Code Snippets screen.', 'wpcode-bb-bridge' ); ?></p>

			<h2><?php esc_html_e( '1. Set your WPCode snippet to use "Shortcode" insertion', 'wpcode-bb-bridge' ); ?></h2>
			<p><?php esc_html_e( 'In WPCode, open your snippet and set "Insertion" to Shortcode. WPCode will give it a tag such as [wpcode_snippet_123]. Your snippet code can read values two ways:', 'wpcode-bb-bridge' ); ?></p>
			<pre>// Option A - as shortcode attributes
$atts = shortcode_atts( array( 'headline' => '' ), $atts );
echo esc_html( $atts['headline'] );

// Option B - via the global this plugin sets right before rendering
$values = $GLOBALS['wpcode_bb_values'] ?? array();
echo esc_html( $values['headline'] ?? '' );</pre>

			<h2><?php esc_html_e( '2. Create a Configuration', 'wpcode-bb-bridge' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: %s: link to Add New configuration screen */
					esc_html__( '%s and enter the shortcode tag from step 1, then list the fields you want editable (e.g. "headline" as Text, "button_color" as Color).', 'wpcode-bb-bridge' ),
					'<a href="' . esc_url( admin_url( 'post-new.php?post_type=' . WPCODEBB_CPT ) ) . '">' . esc_html__( 'Add a new Configuration', 'wpcode-bb-bridge' ) . '</a>'
				);
				?>
			</p>

			<h2><?php esc_html_e( '3. Use the module in Beaver Builder', 'wpcode-bb-bridge' ); ?></h2>
			<p><?php esc_html_e( 'Open a page in the Beaver Builder editor, add the "WPCode Value" module (under the WPCode category), pick your Configuration from the dropdown, and the fields you defined will appear right there for editing. The snippet renders live with those values.', 'wpcode-bb-bridge' ); ?></p>

			<h2><?php esc_html_e( 'Skip the setup: "Custom" mode', 'wpcode-bb-bridge' ); ?></h2>
			<p><?php esc_html_e( 'Don\'t want to create a Configuration first? In the module\'s dropdown, choose "Custom (type your own variables)" instead. Two fields appear:', 'wpcode-bb-bridge' ); ?></p>
			<ul style="list-style: disc; padding-left: 20px;">
				<li><?php esc_html_e( 'Shortcode Tag - the tag from your WPCode snippet.', 'wpcode-bb-bridge' ); ?></li>
				<li><?php esc_html_e( 'Variables - a plain text editor box. Type one variable per line, as key = value:', 'wpcode-bb-bridge' ); ?></li>
			</ul>
			<pre>headline = Welcome to our clinic
button_color = #1a7f37
show_banner = yes
# a line starting with # is a comment and is ignored</pre>
			<p><?php esc_html_e( 'Each line becomes a shortcode attribute your snippet can read, exactly like the fields from a Configuration. This box only ever appears while you are editing the page in Beaver Builder - it is never shown on the live site, and never to regular visitors.', 'wpcode-bb-bridge' ); ?></p>

			<h2><?php esc_html_e( 'Got a JS config array instead of PHP? Use "JS Configuration Array" mode', 'wpcode-bb-bridge' ); ?></h2>
			<p><?php esc_html_e( 'Some snippets aren\'t PHP shortcodes at all - they\'re a plain JavaScript settings array right there in the snippet, like:', 'wpcode-bb-bridge' ); ?></p>
			<pre>var configurations = [
  { key: 'eventColor', value: 'blue' },
  { key: 'noSchoolEvent', value: {
      primaryColor: 'red',
      badgeText: 'No School',
      searchForWords: ['schools closed']
  } },
];</pre>
			<p><?php esc_html_e( 'For this, create a Configuration, and under "Source Type" choose "JS Configuration Array" instead of "Shortcode Attributes". Then:', 'wpcode-bb-bridge' ); ?></p>
			<ol style="padding-left: 20px;">
				<li><?php esc_html_e( 'Set "Variable name" to match your snippet (e.g. "configurations").', 'wpcode-bb-bridge' ); ?></li>
				<li><?php esc_html_e( 'Paste the whole array (the "var ... = [ ... ];" line is fine) into the box.', 'wpcode-bb-bridge' ); ?></li>
				<li><?php esc_html_e( 'Click Update/Save Draft. A "Detected fields" table appears below, listing every value it found - including nested ones like noSchoolEvent.primaryColor.', 'wpcode-bb-bridge' ); ?></li>
				<li><?php esc_html_e( 'Check the box next to each value you want editable, adjust its Label/Type if you like (Text, Color, Yes/No, or a List for things like searchForWords), and Update again.', 'wpcode-bb-bridge' ); ?></li>
			</ol>
			<p><?php esc_html_e( 'Now add the "WPCode Value" module in Beaver Builder and pick this Configuration - only the boxes you checked show up as editable fields. On the page, the module outputs an updated "var configurations = [...]" script with your edited values merged in; everything you didn\'t expose stays exactly as you pasted it.', 'wpcode-bb-bridge' ); ?></p>
			<p class="description">
				<strong><?php esc_html_e( 'Load order matters:', 'wpcode-bb-bridge' ); ?></strong>
				<?php esc_html_e( 'this module must render before whatever script actually reads that variable runs. Place the module above where the calendar/script normally appears on the page, or make sure your WPCode snippet is set to load after the page content (e.g. in the footer) rather than in the header.', 'wpcode-bb-bridge' ); ?>
			</p>

			<h2><?php esc_html_e( 'Notes', 'wpcode-bb-bridge' ); ?></h2>
			<ul style="list-style: disc; padding-left: 20px;">
				<li><?php esc_html_e( 'Field/variable keys become shortcode attribute names, so keep them lowercase with underscores (e.g. button_text).', 'wpcode-bb-bridge' ); ?></li>
				<li><?php esc_html_e( 'Values are stored per module instance, so the same Configuration (or the same snippet in Custom mode) can be reused on multiple pages with different values on each.', 'wpcode-bb-bridge' ); ?></li>
				<li><?php esc_html_e( 'This plugin is site-managed only and does not support multisite network activation.', 'wpcode-bb-bridge' ); ?></li>
			</ul>
		</div>
		<?php
	}
}
