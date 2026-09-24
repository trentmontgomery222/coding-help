<?php
/**
 * The Status Dot module for Beaver Builder.
 *
 * A drag-in coloured dot for a status level, so a dot can be placed on a page
 * by picking a level from a dropdown rather than typing the [statusdot]
 * shortcode. Several can be dropped in a row to show more than one status at
 * once. The dot itself is built by the same code the shortcode uses, so the two
 * always look identical.
 *
 * @package ACPS_Alert_Popups
 */

defined( 'ABSPATH' ) || exit;

/**
 * A single coloured status dot.
 */
class ACPS_Status_Dot_Module extends FLBuilderModule {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'name'            => __( 'Status Dot', 'acps-alert-popups' ),
				'description'     => __( 'A coloured dot for a status level, placed by picking the level — no shortcode to type.', 'acps-alert-popups' ),
				'category'        => __( 'Actions', 'acps-alert-popups' ),
				'group'           => __( 'Site Alerts', 'acps-alert-popups' ),
				'dir'             => ACPS_ALERTS_DIR . 'modules/status-dot/',
				'url'             => ACPS_ALERTS_URL . 'modules/status-dot/',
				'icon'            => 'megaphone.svg',
				'partial_refresh' => true,
			)
		);
	}
}

FLBuilder::register_module(
	'ACPS_Status_Dot_Module',
	array(
		'general' => array(
			'title'    => __( 'Status Dot', 'acps-alert-popups' ),
			'sections' => array(
				'dot' => array(
					'title'  => __( 'The dot', 'acps-alert-popups' ),
					'fields' => array(
						'level'     => array(
							'type'    => 'select',
							'label'   => __( 'Status level', 'acps-alert-popups' ),
							'default' => 'hold',
							'options' => ACPS_Alerts_Status::level_choices(),
							'help'    => __( 'The dot takes this level\'s colour.', 'acps-alert-popups' ),
						),
						'label'     => array(
							'type'        => 'text',
							'label'       => __( 'Label', 'acps-alert-popups' ),
							'default'     => '',
							'placeholder' => __( 'West Side', 'acps-alert-popups' ),
							'help'        => __( 'Text shown beside the dot. Leave empty for the dot on its own.', 'acps-alert-popups' ),
						),
						'word'      => array(
							'type'    => 'select',
							'label'   => __( 'Use the level word when no label', 'acps-alert-popups' ),
							'default' => '0',
							'options' => array(
								'0' => __( 'No', 'acps-alert-popups' ),
								'1' => __( 'Yes — show HOLD, LOCKDOWN, etc.', 'acps-alert-popups' ),
							),
						),
					),
				),
				'look' => array(
					'title'  => __( 'Look', 'acps-alert-popups' ),
					'fields' => array(
						'size'      => array(
							'type'    => 'unit',
							'label'   => __( 'Size', 'acps-alert-popups' ),
							'default' => 14,
							'slider'  => array(
								'min'  => 6,
								'max'  => 48,
								'step' => 1,
							),
							'help'    => __( 'Dot diameter in pixels.', 'acps-alert-popups' ),
						),
						'color'     => array(
							'type'       => 'color',
							'label'      => __( 'Colour', 'acps-alert-popups' ),
							'default'    => '',
							'show_reset' => true,
							'show_alpha' => false,
							'help'       => __( 'Override the level colour for this one dot. Leave empty to use the level\'s own colour.', 'acps-alert-popups' ),
						),
						'alignment' => array(
							'type'    => 'align',
							'label'   => __( 'Alignment', 'acps-alert-popups' ),
							'default' => 'left',
						),
					),
				),
			),
		),
	)
);
