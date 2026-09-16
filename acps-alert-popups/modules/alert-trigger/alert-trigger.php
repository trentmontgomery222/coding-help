<?php
/**
 * The Alert Trigger module for Beaver Builder.
 *
 * @package ACPS_Alert_Popups
 */

defined( 'ABSPATH' ) || exit;

/**
 * A button that opens one of the managed site alerts.
 */
class ACPS_Alert_Trigger_Module extends FLBuilderModule {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'name'            => __( 'Alert Trigger', 'acps-alert-popups' ),
				'description'     => __( 'A button that opens one of the site alerts built with Beaver Builder popups.', 'acps-alert-popups' ),
				'category'        => __( 'Actions', 'acps-alert-popups' ),
				'group'           => __( 'Site Alerts', 'acps-alert-popups' ),
				'dir'             => ACPS_ALERTS_DIR . 'modules/alert-trigger/',
				'url'             => ACPS_ALERTS_URL . 'modules/alert-trigger/',
				'icon'            => 'megaphone.svg',
				'partial_refresh' => true,
			)
		);
	}
}

FLBuilder::register_module(
	'ACPS_Alert_Trigger_Module',
	array(
		'general' => array(
			'title'    => __( 'Alert Trigger', 'acps-alert-popups' ),
			'sections' => array(
				'alert'  => array(
					'title'  => __( 'Alert', 'acps-alert-popups' ),
					'fields' => array(
						'alert_id' => array(
							'type'    => 'select',
							'label'   => __( 'Alert to open', 'acps-alert-popups' ),
							'default' => '',
							'options' => ACPS_Alerts_Builder::get_alert_choices(),
							'help'    => __( 'The alert must also be targeted to this page under Site Alerts, so its content is loaded here.', 'acps-alert-popups' ),
						),
					),
				),
				'button' => array(
					'title'  => __( 'Button', 'acps-alert-popups' ),
					'fields' => array(
						'text'       => array(
							'type'    => 'text',
							'label'   => __( 'Text', 'acps-alert-popups' ),
							'default' => __( 'Read the alert', 'acps-alert-popups' ),
						),
						'style'      => array(
							'type'    => 'select',
							'label'   => __( 'Style', 'acps-alert-popups' ),
							'default' => 'button',
							'options' => array(
								'button' => __( 'Button', 'acps-alert-popups' ),
								'link'   => __( 'Text link', 'acps-alert-popups' ),
							),
						),
						'alignment'  => array(
							'type'    => 'align',
							'label'   => __( 'Alignment', 'acps-alert-popups' ),
							'default' => 'left',
						),
						'css_class'  => array(
							'type'    => 'text',
							'label'   => __( 'Extra CSS class', 'acps-alert-popups' ),
							'default' => '',
						),
					),
				),
			),
		),
	)
);
