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
			'acps-board__banner--' . self::style_of( $settings ),
		);

		// The normal state always uses the colour picked in the module, and so
		// does everything else when colour-by-level is switched off. Only the
		// solid treatment floods a background, so only it can be overridden.
		if ( self::is_solid( $settings ) && ( ! $by_level || ! $alert ) ) {
			$classes[] = 'acps-board__banner--custom';
		}

		return implode( ' ', $classes );
	}

	/**
	 * Which of the two banner treatments this board is set to.
	 *
	 * @param object $settings Module settings.
	 * @return string 'card' or 'solid'.
	 */
	public static function style_of( $settings ) {
		return isset( $settings->banner_style ) && 'solid' === (string) $settings->banner_style ? 'solid' : 'card';
	}

	/**
	 * Whether the banner floods its background with the level colour.
	 *
	 * @param object $settings Module settings.
	 * @return bool
	 */
	public static function is_solid( $settings ) {
		return 'solid' === self::style_of( $settings );
	}

	/**
	 * The level's colour, when there is one to use.
	 *
	 * @param ACPS_Alerts_Alert|null $alert Entry, or null for the normal state.
	 * @return string Hex colour, or an empty string.
	 */
	public static function level_color( $alert, $settings = null ) {
		if ( ! $alert ) {
			return '';
		}

		return ACPS_Alerts_Status::level_color(
			$alert->get( 'status_level' ),
			'board',
			self::level_overrides( $settings )
		);
	}

	/**
	 * The board's own colour for each level, where one has been picked.
	 *
	 * An SRP colour is chosen to read on white. On a dark banner the same
	 * colour can be nearly invisible, so the board may say what each level
	 * should look like on it without changing the level anywhere else.
	 *
	 * @param object|null $settings Module settings.
	 * @return array Level key => colour.
	 */
	public static function level_overrides( $settings ) {
		if ( ! is_object( $settings ) ) {
			return array();
		}

		$out = array();

		foreach ( self::colourable_levels() as $key => $label ) {
			$field = 'level_color_' . $key;

			if ( isset( $settings->{$field} ) && '' !== trim( (string) $settings->{$field} ) ) {
				$out[ $key ] = $settings->{$field};
			}
		}

		return $out;
	}

	/**
	 * The levels worth offering a colour for: the ones still in the picker.
	 *
	 * @return array Level key => label.
	 */
	public static function colourable_levels() {
		return ACPS_Alerts_Status::level_choices();
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
		$color = self::level_color( $alert, $settings );

		// The card treatment matches the popup: a white card with the level
		// colour as a stripe along the top, rather than flooding the whole
		// banner. The heading stays dark, so it reads as a heading.
		if ( ! self::is_solid( $settings ) ) {
			return '' !== $color ? $style . 'border-top-color:' . $color . ';' : $style;
		}

		$by_level = ! isset( $settings->use_level_color ) || '1' === (string) $settings->use_level_color;

		if ( ! $alert || ! $by_level || '' === $color ) {
			return $style; // The module's own colour applies, from its stylesheet.
		}

		return $style . 'background:' . $color . ';';
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

/*
 * One colour field per status level, built from the levels themselves so a site
 * that filters in a new one gets a field for it without touching this file.
 */
$acps_board_level_fields = array();

foreach ( ACPS_Status_Board_Module::colourable_levels() as $acps_level_key => $acps_level_label ) {
	$acps_board_level_fields[ 'level_color_' . $acps_level_key ] = array(
		'type'        => 'color',
		'label'       => $acps_level_label,
		'default'     => '',
		'show_reset'  => true,
		'show_alpha'  => false,
	);
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
						'banner_style' => array(
							'type'    => 'select',
							'label'   => __( 'Banner style', 'acps-alert-popups' ),
							'default' => 'card',
							'options' => array(
								'card'  => __( 'Card — white, like the popup', 'acps-alert-popups' ),
								'solid' => __( 'Solid — the whole banner in the level colour', 'acps-alert-popups' ),
							),
							'help'    => __( 'The banner is the heading and the message, and nothing else. The card is a rounded panel with a stripe along the top; the solid style is a flat full-width block. Either way the whole banner is one colour. For a badge or the level word anywhere on the page, use the [schoolstatus] shortcode.', 'acps-alert-popups' ),
						),
					),
				),
				'levels'  => array(
					'title'       => __( 'Level colours on this board', 'acps-alert-popups' ),
					'description' => __( 'Each status level has a colour staff are trained on, and that is what the board uses. Set one here only when a level needs to look different on this board — an SRP colour is chosen to read on white, and the same colour on a dark banner can be nearly invisible. Leave one empty to keep the standard colour.', 'acps-alert-popups' ),
					'fields'      => $acps_board_level_fields,
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
							'help'       => __( 'The heading and the message both sit on the banner colour, so this is the one colour that has to read against it.', 'acps-alert-popups' ),
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
