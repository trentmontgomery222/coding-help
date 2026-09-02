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

	/**
	 * A short "is this thing actually working?" panel. If an admin can
	 * see this screen at all, the plugin is loaded - so the rows below
	 * are about the pieces the plugin depends on, which are the usual
	 * reason the Beaver Builder module doesn't show up.
	 */
	public function render_status() {
		$bb_active     = class_exists( 'FLBuilder' );
		$wpcode_active = function_exists( 'wpcode_init' ) || post_type_exists( 'wpcode' );
		$module_ready  = $bb_active && class_exists( 'WPCodeBB_Value_Module' );

		$configs = array();

		if ( class_exists( 'WPCodeBB_Config_CPT' ) && is_callable( array( 'WPCodeBB_Config_CPT', 'get_configs' ) ) ) {
			try {
				$configs = (array) WPCodeBB_Config_CPT::get_configs();
			} catch ( \Throwable $e ) {
				$configs = array();
			}
		}

		$rows = array(
			array(
				'label' => __( 'Plugin loaded', 'wpcode-bb-bridge' ),
				'ok'    => true,
				'note'  => sprintf( 'v%s, PHP %s', WPCODEBB_VERSION, PHP_VERSION ),
			),
			array(
				'label' => __( 'Beaver Builder active', 'wpcode-bb-bridge' ),
				'ok'    => $bb_active,
				'note'  => $bb_active ? '' : __( 'The "WPCode Value" module cannot appear in the Beaver Builder editor until Beaver Builder itself is active.', 'wpcode-bb-bridge' ),
			),
			array(
				'label' => __( 'WPCode active', 'wpcode-bb-bridge' ),
				'ok'    => $wpcode_active,
				'note'  => $wpcode_active ? '' : __( 'Snippet detection is unavailable, but you can still type a shortcode tag by hand.', 'wpcode-bb-bridge' ),
			),
			array(
				'label' => __( '"WPCode Value" module registered', 'wpcode-bb-bridge' ),
				'ok'    => $module_ready,
				'note'  => $module_ready
					? __( 'If it still is not listed in the editor, check Settings > Beaver Builder > Modules and make sure the "WPCode" group is enabled.', 'wpcode-bb-bridge' )
					: __( 'Not registered. This is expected while Beaver Builder is inactive.', 'wpcode-bb-bridge' ),
			),
			array(
				'label' => __( 'Configurations published', 'wpcode-bb-bridge' ),
				'ok'    => ! empty( $configs ),
				'note'  => sprintf(
					/* translators: %d: number of published configurations */
					_n( '%d configuration.', '%d configurations.', count( $configs ), 'wpcode-bb-bridge' ),
					count( $configs )
				) . ' ' . __( 'None is required - the module\'s "Custom" mode works without any.', 'wpcode-bb-bridge' ),
			),
		);
		?>
		<h2><?php esc_html_e( 'Status', 'wpcode-bb-bridge' ); ?></h2>
		<table class="widefat striped" style="max-width: 780px;">
			<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td style="width: 30px;"><span class="dashicons <?php echo $row['ok'] ? 'dashicons-yes' : 'dashicons-warning'; ?>" style="color: <?php echo $row['ok'] ? '#1a7f37' : '#b26200'; ?>;"></span></td>
					<td style="width: 260px;"><strong><?php echo esc_html( $row['label'] ); ?></strong></td>
					<td><?php echo esc_html( $row['note'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	public function render_help_page() {
		?>
		<div class="wrap wpcodebb-help">
			<h1><?php esc_html_e( 'WPCode Values for Beaver Builder', 'wpcode-bb-bridge' ); ?></h1>

			<?php $this->render_status(); ?>

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
