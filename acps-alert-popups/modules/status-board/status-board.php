<?php
/**
 * The Status Board module for Beaver Builder.
 *
 * This is the template, not the control surface. It draws the banner for
 * whatever the Current Alert currently says — heading, text and the colour of
 * its status level — plus the archive of past updates underneath.
 *
 * The Current Alert itself is edited in the Current Alert module, which sits on
 * this same page as a popup. Nothing here changes what the alert says; the only
 * things this module owns are the resting-state wording, how the archive is
 * displayed, and the board's own colours.
 *
 * @package ACPS_Alert_Popups
 */

defined( 'ABSPATH' ) || exit;

/**
 * Status board.
 */
class ACPS_Status_Board_Module extends FLBuilderModule {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'name'            => __( 'School Status Board', 'acps-alert-popups' ),
				'description'     => __( 'Shows the current status and the archive, and posts new status updates.', 'acps-alert-popups' ),
				'category'        => __( 'Actions', 'acps-alert-popups' ),
				'group'           => __( 'Site Alerts', 'acps-alert-popups' ),
				'dir'             => ACPS_ALERTS_DIR . 'modules/status-board/',
				'url'             => ACPS_ALERTS_URL . 'modules/status-board/',
				'icon'            => 'megaphone.svg',
				'partial_refresh' => true,
			)
		);
	}

	/**
	 * Banner classes for one entry, or for the normal state.
	 *
	 * Lives on the class rather than in the template, because two status boards
	 * on one page would redeclare a template-level function and fatal.
	 *
	 * @param ACPS_Alerts_Alert|null $alert    Entry, or null for the normal state.
	 * @param object                 $settings Module settings.
	 * @return string
	 */
	public static function banner_classes( $alert, $settings ) {
		$level    = $alert ? $alert->get( 'status_level' ) : 'normal';
		$by_level = ! isset( $settings->use_level_color ) || '1' === (string) $settings->use_level_color;

		$classes = array(
			'acps-board__banner',
			'acps-board__banner--' . sanitize_html_class( $level ),
		);

		// The normal state always uses the colour picked in the module, and so
		// does everything else when colour-by-level is switched off.
		if ( ! $by_level || ! $alert ) {
			$classes[] = 'acps-board__banner--custom';
		}

		return implode( ' ', $classes );
	}

	/**
	 * Inline style for a banner: alignment, plus the SRP colour when the status
	 * is one of the response actions.
	 *
	 * The colour has to be inline rather than in the stylesheet, because it
	 * comes from the level definition — which a site can change with the
	 * acps_alerts_status_levels filter.
	 *
	 * @param ACPS_Alerts_Alert|null $alert    Entry, or null for the normal state.
	 * @param object                 $settings Module settings.
	 * @return string
	 */
	public static function banner_style( $alert, $settings ) {
		$align = isset( $settings->banner_align ) ? $settings->banner_align : 'center';
		$style = 'text-align:' . preg_replace( '/[^a-z]/', '', (string) $align ) . ';';

		$by_level = ! isset( $settings->use_level_color ) || '1' === (string) $settings->use_level_color;

		if ( ! $alert || ! $by_level ) {
			return $style; // The module's own colour applies, from its stylesheet.
		}

		$level = ACPS_Alerts_Status::level( $alert->get( 'status_level' ) );
		$color = isset( $level['color'] ) ? (string) $level['color'] : '';

		// Only ever emit a colour we recognise as one.
		if ( preg_match( '/^#[0-9a-f]{3,8}$/i', $color ) ) {
			$style .= 'background:' . $color . ';';
		}

		return $style;
	}

	/**
	 * Applies the module's fields to the Current Alert.
	 *
	 * @param object $settings Submitted module settings.
	 * @return object Settings to store.
	 */
	public function update( $settings ) {
		if ( ! class_exists( 'ACPS_Alerts_Status' ) ) {
			return $settings;
		}

		// Remember which page carries the board, so the admin can link to it
		// and the setup checklist knows it has been placed.
		if ( class_exists( 'FLBuilderModel' ) && method_exists( 'FLBuilderModel', 'get_post_id' ) ) {
			$page_id = (int) FLBuilderModel::get_post_id();

			if ( $page_id ) {
				update_option( 'acps_alerts_board_page', $page_id, false );
			}
		}

		// Only someone who may manage alerts files a record from here, even
		// though Beaver Builder already gates who can edit the layout.
		if ( ! current_user_can( ACPS_Alerts_Admin::capability() ) ) {
			return $settings;
		}

		$this->maybe_add_archive_record( $settings );

		return $settings;
	}

	/**
	 * Adds a past event to the archive, when one has been typed in.
	 *
	 * Records are not alerts: they never pop up and never reach the banner.
	 * The date box is cleared afterwards so a repeat save cannot file it twice.
	 *
	 * @param object $settings Module settings.
	 * @return void
	 */
	protected function maybe_add_archive_record( $settings ) {
		$title = isset( $settings->archive_title ) ? trim( (string) $settings->archive_title ) : '';

		if ( '' === $title ) {
			return;
		}

		ACPS_Alerts_Status::add_archive_record(
			array(
				'title'   => $title,
				'level'   => isset( $settings->archive_level ) ? $settings->archive_level : 'info',
				'message' => isset( $settings->archive_message ) ? $settings->archive_message : '',
				'date'    => isset( $settings->archive_date ) ? $settings->archive_date : '',
			)
		);

		$settings->archive_title   = '';
		$settings->archive_message = '';
		$settings->archive_date    = '';
	}

}

FLBuilder::register_module(
	'ACPS_Status_Board_Module',
	array(
		'alert' => array(
			'title'    => __( 'Past updates', 'acps-alert-popups' ),
			'sections' => array(
				'pointer' => array(
					'title'       => __( 'Editing the current alert', 'acps-alert-popups' ),
					'description' => __( 'The alert itself is not edited here. Open the Current Alert module on this page — it is the popup — and everything about the alert is on its tabs. This board just draws whatever that alert says.', 'acps-alert-popups' ),
					'fields'      => array(),
				),
				'archive' => array(
					'title'       => __( 'Add a past event to the archive', 'acps-alert-popups' ),
					'description' => __( 'For writing up something that already happened. These become records in the list of past updates — they never pop up and never reach the banner. The boxes empty themselves once filed.', 'acps-alert-popups' ),
					'fields'      => array(
						'archive_title'   => array(
							'type'        => 'text',
							'label'       => __( 'Headline', 'acps-alert-popups' ),
							'default'     => '',
							'placeholder' => __( 'Phishing campaign identified &amp; contained', 'acps-alert-popups' ),
						),
						'archive_level'   => array(
							'type'    => 'select',
							'label'   => __( 'Status level', 'acps-alert-popups' ),
							'default' => 'info',
							'options' => ACPS_Alerts_Status::level_choices(),
						),
						'archive_message' => array(
							'type'    => 'textarea',
							'label'   => __( 'Message', 'acps-alert-popups' ),
							'default' => '',
							'rows'    => 4,
						),
						'archive_date'    => array(
							'type'        => 'text',
							'label'       => __( 'Date it happened', 'acps-alert-popups' ),
							'default'     => '',
							'placeholder' => 'YYYY-MM-DD',
							'help'        => __( 'Decides where it sits in the list. Leave empty for today.', 'acps-alert-popups' ),
						),
					),
				),
			),
		),
		'board' => array(
			'title'    => __( 'Board', 'acps-alert-popups' ),
			'sections' => array(
				'banner'  => array(
					'title'  => __( 'The banner', 'acps-alert-popups' ),
					'fields' => array(
						'show_icon' => array(
							'type'    => 'select',
							'label'   => __( 'Show the level badge', 'acps-alert-popups' ),
							'default' => '1',
							'options' => array(
								'1' => __( 'Yes — the coloured disc above the heading', 'acps-alert-popups' ),
								'0' => __( 'No', 'acps-alert-popups' ),
							),
							'help'    => __( 'The heading underneath it is always the alert\'s own title.', 'acps-alert-popups' ),
						),
						'icon_size' => array(
							'type'    => 'unit',
							'label'   => __( 'Badge size', 'acps-alert-popups' ),
							'default' => '64',
							'units'   => array( 'px' ),
							'slider'  => array( 'min' => 16, 'max' => 160, 'step' => 4 ),
						),
					),
				),
				'normal'  => array(
					'title'  => __( 'When nothing is happening', 'acps-alert-popups' ),
					'fields' => array(
						'normal_title'   => array(
							'type'    => 'text',
							'label'   => __( 'Heading', 'acps-alert-popups' ),
							'default' => __( 'School Status: NORMAL', 'acps-alert-popups' ),
						),
						'normal_message' => array(
							'type'    => 'textarea',
							'label'   => __( 'Message', 'acps-alert-popups' ),
							'rows'    => 4,
							'default' => __( 'All schools are operating as normal. This information will be updated as needed to provide families, students, staff, and the community with the latest information regarding school operations and safety.', 'acps-alert-popups' ),
						),
					),
				),
				'archive' => array(
					'title'  => __( 'Archive', 'acps-alert-popups' ),
					'fields' => array(
						'show_archive'  => array(
							'type'    => 'select',
							'label'   => __( 'Show past updates', 'acps-alert-popups' ),
							'default' => '1',
							'options' => array(
								'1' => __( 'Yes', 'acps-alert-popups' ),
								'0' => __( 'No', 'acps-alert-popups' ),
							),
						),
						'archive_count' => array(
							'type'    => 'unit',
							'label'   => __( 'How many', 'acps-alert-popups' ),
							'default' => '10',
							'slider'  => array(
								'min'  => 1,
								'max'  => 50,
								'step' => 1,
							),
						),
						'archive_dates' => array(
							'type'    => 'select',
							'label'   => __( 'Show the date on each one', 'acps-alert-popups' ),
							'default' => '1',
							'options' => array(
								'1' => __( 'Yes', 'acps-alert-popups' ),
								'0' => __( 'No', 'acps-alert-popups' ),
							),
						),
					),
				),
				'style'   => array(
					'title'  => __( 'Style', 'acps-alert-popups' ),
					'fields' => array(
						'banner_color'   => array(
							'type'       => 'color',
							'label'      => __( 'Normal banner colour', 'acps-alert-popups' ),
							'default'    => '1b2f5e',
							'show_reset' => true,
							'help'       => __( 'Used when the status is normal. Other levels use their own colour.', 'acps-alert-popups' ),
						),
						'text_color'     => array(
							'type'       => 'color',
							'label'      => __( 'Banner text colour', 'acps-alert-popups' ),
							'default'    => 'ffffff',
							'show_reset' => true,
						),
						'banner_align'   => array(
							'type'    => 'align',
							'label'   => __( 'Banner text alignment', 'acps-alert-popups' ),
							'default' => 'center',
						),
						'use_level_color' => array(
							'type'    => 'select',
							'label'   => __( 'Colour by status level', 'acps-alert-popups' ),
							'default' => '1',
							'options' => array(
								'1' => __( 'Yes — warnings amber, closures red', 'acps-alert-popups' ),
								'0' => __( 'No — always use the colour above', 'acps-alert-popups' ),
							),
						),
					),
				),
			),
		),
	)
);
