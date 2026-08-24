<?php
/**
 * Integration surface (spec §10): shortcode, Gutenberg block, and the Beaver
 * Builder module. All three funnel through the one renderer.
 *
 * @package ACPS\SiteToolkit
 */

namespace ACPS\SiteToolkit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Integrations.
 */
class Integrations {

	/**
	 * Register everything.
	 */
	public function register() {
		add_shortcode( 'acps_form', array( $this, 'shortcode_form' ) );
		add_shortcode( 'acps_feedback', array( $this, 'shortcode_feedback' ) );
		add_shortcode( 'acps_contact', array( $this, 'shortcode_contact' ) );
		add_shortcode( 'acps_qa', array( $this, 'shortcode_qa' ) );

		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'init', array( $this, 'register_beaver_module' ) );

		// Elementor widget — registered only when Elementor is present. Every
		// other builder is already covered: GeneratePress/GenerateBlocks and the
		// block editor via the Gutenberg block, Beaver via its module, and any
		// builder with an HTML/shortcode widget via [acps_form].
		add_action( 'elementor/widgets/register', array( $this, 'register_elementor_widget' ) );
	}

	/**
	 * [acps_form id="12"] or [acps_form slug="contact"].
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public function shortcode_form( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0, 'slug' => '' ), $atts, 'acps_form' );

		$form = null;
		if ( $atts['slug'] ) {
			$form = Form::find_by_slug( $atts['slug'] );
		} elseif ( $atts['id'] ) {
			$form = Form::find( (int) $atts['id'] );
		}

		if ( ! $form ) {
			return current_user_can( 'edit_posts' )
				? '<p class="acps-form-missing">' . esc_html__( 'ACPS form not found.', 'acps-site-toolkit' ) . '</p>'
				: '';
		}
		if ( 'published' !== $form->status && ! current_user_can( 'edit_posts' ) ) {
			return '';
		}

		// Apply per-form access control (login/roles, password, secret link).
		return Access::render_guarded( $form, array( 'post_id' => get_the_ID() ?: 0 ) );
	}

	/**
	 * [acps_feedback] — the dedicated feedback page (entry point B).
	 *
	 * @return string
	 */
	public function shortcode_feedback() {
		return Feedback::render_page();
	}

	/**
	 * [acps_contact] — the "Contact us" message form (emails the team).
	 *
	 * @return string
	 */
	public function shortcode_contact() {
		return Help::render_contact();
	}

	/**
	 * [acps_qa show_contact="1" title="…"] — the Q&A / help widget.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public function shortcode_qa( $atts ) {
		$atts = shortcode_atts(
			array( 'show_contact' => '1', 'title' => __( 'Questions & answers', 'acps-site-toolkit' ) ),
			$atts,
			'acps_qa'
		);
		return Help::render_qa(
			array(
				'show_contact' => '1' === (string) $atts['show_contact'] || 'true' === $atts['show_contact'],
				'title'        => sanitize_text_field( $atts['title'] ),
			)
		);
	}

	/**
	 * Register a Gutenberg block that wraps the shortcode. Uses a server-render
	 * callback so the accessible markup and cache-safe tokens are identical.
	 */
	public function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		wp_register_script(
			'acps-st-block',
			ACPS_ST_URL . 'assets/js/block.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-i18n' ),
			ACPS_ST_VERSION,
			true
		);

		// Provide the form list to the editor.
		$choices = array( array( 'value' => 0, 'label' => __( '— Select a form —', 'acps-site-toolkit' ) ) );
		foreach ( Form::all() as $f ) {
			$choices[] = array( 'value' => $f->id, 'label' => $f->title );
		}
		wp_localize_script( 'acps-st-block', 'ACPS_ST_BLOCK', array( 'forms' => $choices ) );

		register_block_type(
			'acps/form',
			array(
				'api_version'     => 2,
				'editor_script'   => 'acps-st-block',
				'attributes'      => array(
					'formId' => array( 'type' => 'number', 'default' => 0 ),
				),
				'render_callback' => array( $this, 'render_block' ),
			)
		);
	}

	/**
	 * Server render for the block.
	 *
	 * @param array $attrs Block attributes.
	 * @return string
	 */
	public function render_block( $attrs ) {
		$id = isset( $attrs['formId'] ) ? (int) $attrs['formId'] : 0;
		if ( ! $id ) {
			return '';
		}
		return $this->shortcode_form( array( 'id' => $id ) );
	}

	/**
	 * Register the Beaver Builder module if Beaver Builder is active (spec §10).
	 * The module file self-registers with FLBuilder.
	 */
	public function register_beaver_module() {
		if ( class_exists( 'FLBuilder' ) && ! class_exists( __NAMESPACE__ . '\\ACPS_Form_Module' ) ) {
			require_once ACPS_ST_PATH . 'includes/beaver/class-acps-form-module.php';
		}
	}

	/**
	 * Register the Elementor widget when Elementor is active. Fired on
	 * 'elementor/widgets/register', which only runs when Elementor is loaded, so
	 * this is a no-op on sites without it.
	 *
	 * @param mixed $widgets_manager Elementor's widgets manager.
	 */
	public function register_elementor_widget( $widgets_manager ) {
		if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
			return;
		}
		require_once ACPS_ST_PATH . 'includes/elementor/class-acps-form-widget.php';
		if ( ! class_exists( __NAMESPACE__ . '\\ACPS_Form_Widget' ) ) {
			return;
		}
		$widget = new ACPS_Form_Widget();
		// register() is Elementor 3.5+; older versions use register_widget_type().
		if ( method_exists( $widgets_manager, 'register' ) ) {
			$widgets_manager->register( $widget );
		} elseif ( method_exists( $widgets_manager, 'register_widget_type' ) ) {
			$widgets_manager->register_widget_type( $widget );
		}
	}
}
