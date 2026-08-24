<?php
/**
 * Elementor widget: Staff Directory.
 *
 * Wraps the shared renderer so the directory can be dropped in from the
 * Elementor panel. This file is only required when Elementor is active (the
 * class extends an Elementor base class), and registration is guarded in the
 * main plugin file.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
	return;
}

if ( ! class_exists( 'CAYDENDIR_Elementor_Widget' ) ) {

	class CAYDENDIR_Elementor_Widget extends \Elementor\Widget_Base {

		public function get_name() {
			return 'caydendir_staff_directory';
		}

		public function get_title() {
			return __( 'Staff Directory', 'cayden-staff-directory' );
		}

		public function get_icon() {
			return 'eicon-table-of-contents';
		}

		public function get_categories() {
			return array( 'caydendir', 'general' );
		}

		public function get_keywords() {
			return array( 'staff', 'directory', 'people', 'cayden' );
		}

		protected function register_controls() {
			$this->start_controls_section(
				'section_directory',
				array( 'label' => __( 'Directory', 'cayden-staff-directory' ) )
			);

			$this->add_control(
				'heading',
				array(
					'label'   => __( 'Heading', 'cayden-staff-directory' ),
					'type'    => \Elementor\Controls_Manager::TEXT,
					'default' => 'Staff Directory',
				)
			);

			$this->add_control(
				'layout',
				array(
					'label'   => __( 'Layout', 'cayden-staff-directory' ),
					'type'    => \Elementor\Controls_Manager::SELECT,
					'default' => '',
					'options' => array(
						''      => __( 'Use plugin setting', 'cayden-staff-directory' ),
						'table' => __( 'Table (rows)', 'cayden-staff-directory' ),
						'cards' => __( 'Cards (grid)', 'cayden-staff-directory' ),
					),
				)
			);

			$this->add_control(
				'match',
				array(
					'label'   => __( 'Tag match', 'cayden-staff-directory' ),
					'type'    => \Elementor\Controls_Manager::SELECT,
					'default' => 'any',
					'options' => array(
						'any' => __( 'Any selected tag', 'cayden-staff-directory' ),
						'all' => __( 'All selected tags', 'cayden-staff-directory' ),
					),
				)
			);

			$this->end_controls_section();
		}

		protected function render() {
			if ( ! function_exists( 'CAYDENDIR_sd_render' ) ) {
				return;
			}
			$s      = $this->get_settings_for_display();
			$layout = isset( $s['layout'] ) ? $s['layout'] : '';
			$match  = isset( $s['match'] ) ? $s['match'] : 'any';
			$head   = ( isset( $s['heading'] ) && '' !== $s['heading'] ) ? $s['heading'] : 'Staff Directory';

			// CAYDENDIR_sd_render() returns escaped markup and is itself wrapped
			// in a try/catch, so a directory error can never break the Elementor
			// canvas or the page.
			echo CAYDENDIR_sd_render( array(  // phpcs:ignore WordPress.Security.EscapeOutput -- renderer returns escaped markup
				'heading' => $head,
				'layout'  => $layout,
				'match'   => $match,
			) );
		}
	}
}
