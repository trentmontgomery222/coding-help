<?php
/**
 * Adds a "How It Works" help screen under the Configurations menu.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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

			<h2><?php esc_html_e( 'Notes', 'wpcode-bb-bridge' ); ?></h2>
			<ul style="list-style: disc; padding-left: 20px;">
				<li><?php esc_html_e( 'Field keys become shortcode attribute names, so keep them lowercase with underscores (e.g. button_text).', 'wpcode-bb-bridge' ); ?></li>
				<li><?php esc_html_e( 'Values are stored per module instance, so the same Configuration can be reused on multiple pages with different values on each.', 'wpcode-bb-bridge' ); ?></li>
				<li><?php esc_html_e( 'This plugin is site-managed only and does not support multisite network activation.', 'wpcode-bb-bridge' ); ?></li>
			</ul>
		</div>
		<?php
	}
}
