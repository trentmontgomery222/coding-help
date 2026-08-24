<?php
/**
 * Elementor widget — drop any form (or the feedback form) into an Elementor
 * layout by picking it from a dropdown. Registered only when Elementor is
 * active; every other builder keeps using the shortcode, Gutenberg block, or
 * Beaver module.
 *
 * @package ACPS\SiteToolkit
 */

namespace ACPS\SiteToolkit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Elementor must be loaded for its base class to exist.
if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
	return;
}

/**
 * ACPS_Form_Widget.
 */
class ACPS_Form_Widget extends \Elementor\Widget_Base {

	/**
	 * Widget slug.
	 */
	public function get_name() {
		return 'acps_form';
	}

	/**
	 * Widget title in the panel.
	 */
	public function get_title() {
		return __( 'ACPS Form', 'acps-site-toolkit' );
	}

	/**
	 * Panel icon.
	 */
	public function get_icon() {
		return 'eicon-form-horizontal';
	}

	/**
	 * Panel category.
	 */
	public function get_categories() {
		return array( 'general' );
	}

	/**
	 * Search keywords.
	 */
	public function get_keywords() {
		return array( 'form', 'feedback', 'contact', 'acps', 'cayden' );
	}

	/**
	 * Settings controls.
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'section_form',
			array( 'label' => __( 'Form', 'acps-site-toolkit' ) )
		);

		$this->add_control(
			'source',
			array(
				'label'   => __( 'Source', 'acps-site-toolkit' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'form',
				'options' => array(
					'form'     => __( 'A specific form', 'acps-site-toolkit' ),
					'feedback' => __( 'Site feedback form', 'acps-site-toolkit' ),
				),
			)
		);

		$choices = array( 0 => __( '— Select —', 'acps-site-toolkit' ) );
		foreach ( Form::all() as $f ) {
			$choices[ $f->id ] = $f->title;
		}

		$this->add_control(
			'form_id',
			array(
				'label'     => __( 'Choose form', 'acps-site-toolkit' ),
				'type'      => \Elementor\Controls_Manager::SELECT,
				'default'   => 0,
				'options'   => $choices,
				'condition' => array( 'source' => 'form' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Front-end + editor render. Funnels through the same renderer as every
	 * other integration so markup, accessibility, and cache-safe tokens match.
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();
		$source   = isset( $settings['source'] ) ? $settings['source'] : 'form';

		if ( 'feedback' === $source ) {
			echo Feedback::render_page(); // phpcs:ignore WordPress.Security.EscapeOutput
			return;
		}

		$id = isset( $settings['form_id'] ) ? (int) $settings['form_id'] : 0;
		if ( ! $id ) {
			// Show a hint in the editor only; render nothing on the live page.
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				echo '<p>' . esc_html__( 'Select a form in the widget settings.', 'acps-site-toolkit' ) . '</p>';
			}
			return;
		}

		$form = Form::find( $id );
		if ( $form ) {
			echo Access::render_guarded( $form, array( 'post_id' => get_the_ID() ?: 0 ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}
}
