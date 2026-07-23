<?php
/**
 * Beaver Builder module — drop any form (or the feedback form) into a row by
 * picking it from a dropdown (spec §10).
 *
 * @package ACPS\SiteToolkit
 */

namespace ACPS\SiteToolkit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'FLBuilderModule' ) ) {
	return;
}

/**
 * ACPS_Form_Module.
 */
class ACPS_Form_Module extends \FLBuilderModule {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'name'            => __( 'ACPS Form', 'acps-site-toolkit' ),
				'description'     => __( 'Insert an accessible ACPS form or the site feedback form.', 'acps-site-toolkit' ),
				'category'        => __( 'ACPS Site Toolkit', 'acps-site-toolkit' ),
				'dir'             => ACPS_ST_PATH . 'includes/beaver/',
				'url'             => ACPS_ST_URL . 'includes/beaver/',
				'editor_export'   => true,
				'enabled'         => true,
			)
		);
	}

	/**
	 * Render the selected form.
	 */
	public function render_form() {
		$form_id = isset( $this->settings->form_id ) ? (int) $this->settings->form_id : 0;
		if ( 'feedback' === ( $this->settings->source ?? '' ) ) {
			echo Feedback::render_page(); // phpcs:ignore
			return;
		}
		if ( ! $form_id ) {
			return;
		}
		$form = Form::find( $form_id );
		if ( $form ) {
			echo Form_Renderer::render( $form, array( 'post_id' => get_the_ID() ?: 0 ) ); // phpcs:ignore
		}
	}
}

/*
 * Build the form-choice list for the settings dropdown.
 */
$acps_form_choices = array( 0 => __( '— Select —', 'acps-site-toolkit' ) );
foreach ( Form::all() as $acps_f ) {
	$acps_form_choices[ $acps_f->id ] = $acps_f->title;
}

\FLBuilder::register_module(
	__NAMESPACE__ . '\\ACPS_Form_Module',
	array(
		'general' => array(
			'title'    => __( 'Form', 'acps-site-toolkit' ),
			'sections' => array(
				'source' => array(
					'title'  => __( 'Form', 'acps-site-toolkit' ),
					'fields' => array(
						'source'  => array(
							'type'    => 'select',
							'label'   => __( 'Source', 'acps-site-toolkit' ),
							'default' => 'form',
							'options' => array(
								'form'     => __( 'A specific form', 'acps-site-toolkit' ),
								'feedback' => __( 'Site feedback form', 'acps-site-toolkit' ),
							),
							'toggle'  => array(
								'form' => array( 'fields' => array( 'form_id' ) ),
							),
						),
						'form_id' => array(
							'type'    => 'select',
							'label'   => __( 'Choose form', 'acps-site-toolkit' ),
							'default' => 0,
							'options' => $acps_form_choices,
						),
					),
				),
			),
		),
	)
);
