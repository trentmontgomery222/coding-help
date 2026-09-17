<?php
/**
 * The Status Board module for Beaver Builder.
 *
 * This is the control surface. You drop it on the status page, and from then on
 * everything happens in one place: type the update into the module, save, and
 * the plugin posts it as a status entry — which the board shows as a banner and
 * (if you asked for it) the rest of the site shows as a popup.
 *
 * The posting happens in update(), which Beaver Builder calls when the module's
 * settings are saved. It returns the settings it wants stored, so the compose
 * fields are cleared afterwards and saving the layout again cannot post the
 * same update twice.
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
	 * Posts a status update when one has been typed into the module.
	 *
	 * Beaver Builder stores whatever this returns, so the compose fields are
	 * emptied once the entry exists. That is what stops a second save of the
	 * same layout posting a duplicate.
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

		$headline = isset( $settings->post_headline ) ? trim( (string) $settings->post_headline ) : '';

		if ( '' === $headline ) {
			return $settings;
		}

		// Only someone who may manage alerts can publish one from here, even
		// though Beaver Builder already gates who can edit the layout.
		if ( ! current_user_can( ACPS_Alerts_Admin::capability() ) ) {
			return $settings;
		}

		$archived = isset( $settings->post_state ) && 'archive' === $settings->post_state;

		$data = array(
			'title'      => $headline,
			'level'      => isset( $settings->post_level ) ? $settings->post_level : 'advisory',
			'message'    => isset( $settings->post_message ) ? $settings->post_message : '',
			'expires'    => isset( $settings->post_expires ) ? $settings->post_expires : 'daily',
			'as_popup'   => ! empty( $settings->post_as_popup ),
			'visibility' => isset( $settings->post_visibility ) ? $settings->post_visibility : 'public',
			'archived'   => $archived,
			'date'       => isset( $settings->post_date ) ? $settings->post_date : '',
		);

		$node = isset( $this->node ) ? (string) $this->node : 'status-board';

		/*
		 * These fields ARE the current status, not a blank form that posts and
		 * empties. Beaver Builder calls update() on every save and keeps
		 * whatever the editor typed, so the natural reading is the right one:
		 * what is in these boxes is what the site is saying.
		 *
		 * So an edit rewrites the live entry in place. Fixing a typo has to
		 * change the update people are reading, not publish a second one
		 * underneath it. Only "Post as a new update" starts another.
		 */
		$tracked = ACPS_Alerts_Status::tracked_entry( $node );
		$fresh   = isset( $settings->post_mode ) && 'new' === $settings->post_mode;

		if ( ! $archived && $tracked && ! $fresh ) {
			ACPS_Alerts_Status::update_entry( $tracked, $data );

			$settings->last_posted = $tracked;

			return $settings;
		}

		// A record of a past event, or a deliberate new update. The fingerprint
		// stops a repeat save creating a second copy of either.
		if ( ACPS_Alerts_Status::already_posted( $node, $data, $archived ) ) {
			return $settings;
		}

		// Starting a new update retires the one it replaces, so the board never
		// shows two versions of the same announcement.
		if ( $fresh && $tracked && ! $archived ) {
			ACPS_Alerts_Status::archive_entry( $tracked );
		}

		$post_id = ACPS_Alerts_Status::post_entry( $data );

		if ( $post_id ) {
			// A backfilled record is not what the board is driving, so it must
			// not become the entry that later edits rewrite.
			if ( ! $archived ) {
				ACPS_Alerts_Status::remember_posted( $node, $data, $post_id );

				// Drop back to editing, so the next save corrects this update
				// rather than starting yet another one.
				$settings->post_mode = 'update';
			}

			$settings->post_date      = '';
			$settings->last_posted    = $post_id;
			$settings->last_posted_at = time();
		}

		return $settings;
	}
}

FLBuilder::register_module(
	'ACPS_Status_Board_Module',
	array(
		'post'  => array(
			'title'    => __( 'Post an update', 'acps-alert-popups' ),
			'sections' => array(
				'compose' => array(
					'title'       => __( 'The current status', 'acps-alert-popups' ),
					'description' => __( 'What is in these boxes is what the site is saying. Edit and save to correct the live update — fixing a typo changes the update people are reading, it does not publish a second one. To replace it with something genuinely new, switch "When you save" below. Leave the headline empty to change nothing.', 'acps-alert-popups' ),
					'fields'      => array(
						'post_mode'       => array(
							'type'    => 'select',
							'label'   => __( 'When you save', 'acps-alert-popups' ),
							'default' => 'update',
							'options' => array(
								'update' => __( 'Update the current status', 'acps-alert-popups' ),
								'new'    => __( 'Post as a new update (archives the current one)', 'acps-alert-popups' ),
							),
							'help'    => __( 'Leave this on "Update" for wording fixes. It returns to "Update" on its own after a new update is posted.', 'acps-alert-popups' ),
						),
						'post_headline'   => array(
							'type'        => 'text',
							'label'       => __( 'Headline', 'acps-alert-popups' ),
							'default'     => '',
							'placeholder' => __( 'Snow Day — All Schools Closed', 'acps-alert-popups' ),
							'help'        => __( 'This is the title people see on the board and in the archive.', 'acps-alert-popups' ),
						),
						'post_level'      => array(
							'type'    => 'select',
							'label'   => __( 'Status level', 'acps-alert-popups' ),
							'default' => 'advisory',
							'options' => ACPS_Alerts_Status::level_choices(),
						),
						'post_message'    => array(
							'type'    => 'textarea',
							'label'   => __( 'Message', 'acps-alert-popups' ),
							'default' => '',
							'rows'    => 5,
							'help'    => __( 'A sentence or two. Shown on the board and used for the popup unless you design one in Beaver Builder.', 'acps-alert-popups' ),
						),
						'post_state'      => array(
							'type'    => 'select',
							'label'   => __( 'Post it as', 'acps-alert-popups' ),
							'default' => 'live',
							'options' => array(
								'live'    => __( 'A live update — show it now', 'acps-alert-popups' ),
								'archive' => __( 'Straight into the archive — a past event', 'acps-alert-popups' ),
							),
							'help'    => __( 'Use the archive option to fill in things that already happened. They never pop up; they just appear in the list of past updates.', 'acps-alert-popups' ),
							'toggle'  => array(
								'live'    => array( 'fields' => array( 'post_expires', 'post_as_popup', 'post_visibility' ) ),
								'archive' => array( 'fields' => array( 'post_date' ) ),
							),
						),
						'post_date'       => array(
							'type'        => 'text',
							'label'       => __( 'Date it happened', 'acps-alert-popups' ),
							'default'     => '',
							'placeholder' => 'YYYY-MM-DD',
							'help'        => __( 'Sets where it sits in the archive. Leave empty to use today.', 'acps-alert-popups' ),
						),
						'post_expires'    => array(
							'type'    => 'select',
							'label'   => __( 'Take it down', 'acps-alert-popups' ),
							'default' => 'daily',
							'options' => array(
								'daily'  => __( 'Automatically, at the daily cut-off', 'acps-alert-popups' ),
								'keep'   => __( 'Keep it up until I archive it', 'acps-alert-popups' ),
								'custom' => __( 'On its own schedule (set in Site Alerts)', 'acps-alert-popups' ),
							),
							'help'    => __( 'The cut-off is set in Site Alerts → Settings. It defaults to 5:50pm.', 'acps-alert-popups' ),
						),
						'post_as_popup'   => array(
							'type'    => 'select',
							'label'   => __( 'Also pop up across the site', 'acps-alert-popups' ),
							'default' => '1',
							'options' => array(
								'1' => __( 'Yes — show it everywhere', 'acps-alert-popups' ),
								'0' => __( 'No — status page only', 'acps-alert-popups' ),
							),
						),
						'post_visibility' => array(
							'type'    => 'select',
							'label'   => __( 'Who can see it', 'acps-alert-popups' ),
							'default' => 'public',
							'options' => array(
								'public' => __( 'Everybody', 'acps-alert-popups' ),
								'admins' => __( 'Staff only — stage it before it goes out', 'acps-alert-popups' ),
							),
							'help'    => __( 'Staff only lets you check it on the real site. Nobody else sees it, on the board or in the popup.', 'acps-alert-popups' ),
						),
					),
				),
			),
		),
		'board' => array(
			'title'    => __( 'Board', 'acps-alert-popups' ),
			'sections' => array(
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
