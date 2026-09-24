<?php
/**
 * The Current Alert module for Beaver Builder.
 *
 * This module *is* the Current Alert. You drop one on the status page and edit
 * it there like any other popup: the content tab is what the popup says, and
 * the rest of the tabs are every setting the alert has. Nothing about the
 * Current Alert is edited in wp-admin any more.
 *
 * Where it shows:
 *
 * - In the Beaver Builder editor, on the status page, it draws itself as a
 *   popup card so you can see and click it.
 * - On the live status page it renders nothing at all. The status page shows
 *   the Status Board banner instead, which is generated from this module's
 *   heading and text.
 * - Everywhere else on the site, the plugin renders it as the real popup, when
 *   the Current Alert is switched on.
 *
 * @package ACPS_Alert_Popups
 */

defined( 'ABSPATH' ) || exit;

/**
 * The Current Alert, edited as a popup on the status page.
 */
class ACPS_Alert_Popup_Module extends FLBuilderModule {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'name'            => __( 'Current Alert', 'acps-alert-popups' ),
				'description'     => __( 'The alert popup. Edit it here; the plugin shows it across the site.', 'acps-alert-popups' ),
				'category'        => __( 'Actions', 'acps-alert-popups' ),
				'group'           => __( 'Site Alerts', 'acps-alert-popups' ),
				'dir'             => ACPS_ALERTS_DIR . 'modules/alert-popup/',
				'url'             => ACPS_ALERTS_URL . 'modules/alert-popup/',
				'icon'            => 'megaphone.svg',
				'partial_refresh' => true,
			)
		);
	}

	/* ------------------------------------------------------------------ *
	 * Saving.
	 * ------------------------------------------------------------------ */

	/**
	 * Writes the whole module onto the Current Alert.
	 *
	 * Every setting the alert has is on this module, so the alert is saved as
	 * one complete set rather than patched key by key. That means a setting
	 * cleared here is really cleared, and the alert can never drift out of step
	 * with what the page shows.
	 *
	 * @param object $settings Submitted module settings.
	 * @return object Settings to store.
	 */
	public function update( $settings ) {
		if ( ! class_exists( 'ACPS_Alerts_Status' ) || ! class_exists( 'ACPS_Alerts_Alert' ) || ! class_exists( 'ACPS_Alerts_Failsafe' ) ) {
			return $settings;
		}

		// Beaver Builder calls this on every layout save. Whatever fails in
		// here, the editor's save must still go through: the work is guarded,
		// the one-shot switch is always reset, and the settings always return.
		ACPS_Alerts_Failsafe::guard( array( __CLASS__, 'apply_update' ), array( $settings ), 'alert-popup/update' );

		if ( is_object( $settings ) ) {
			// Reset the one-shot switch so the same post is not re-applied on
			// the next layout save, which would clobber anything posted since
			// from Site Alerts.
			$settings->active = '0';
		}

		return $settings;
	}

	/**
	 * The work behind update(), guarded by it.
	 *
	 * @param object $settings Submitted module settings.
	 * @return void
	 */
	public static function apply_update( $settings ) {
		// Always worth doing: it costs nothing and keeps the "which page is the
		// board on" answer correct.
		self::remember_page();

		/*
		 * The switch on this module is a one-shot "post this now", NOT a live
		 * on/off state.
		 *
		 * Beaver Builder calls update() every time the layout is saved — which
		 * happens whenever anyone edits ANYTHING on the status page (the board,
		 * the popup, an unrelated module) and clicks Done. If this module wrote
		 * the alert on every one of those saves, saving the page to fix a typo
		 * elsewhere would blank the alert and switch it off, because the stored
		 * switch here reads "off". That is exactly the bug this guards against.
		 *
		 * So a save only touches the alert when the switch is explicitly set to
		 * "post now", and even then the switch is immediately reset to off in
		 * the stored settings, so the next incidental page save is a no-op. The
		 * alert's real on/off, wording and settings live in Site Alerts (Post an
		 * Alert, the on/off toggle, Wording) and are never disturbed by saving
		 * the page.
		 */
		$post_now = isset( $settings->active ) && '1' === (string) $settings->active;

		if ( ! $post_now ) {
			return;
		}

		// Beaver Builder already decides who may edit the layout; this is the
		// plugin's own check on who may change what the whole site sees.
		if ( ! current_user_can( ACPS_Alerts_Admin::capability() ) ) {
			return;
		}

		$alert = ACPS_Alerts_Status::current_alert();

		if ( ! $alert ) {
			return;
		}

		self::apply( $alert, $settings );
	}

	/**
	 * Remembers which page the alert lives on.
	 *
	 * The admin links to it, the setup checklist looks for it, and the front
	 * end needs to know which page must never show the popup.
	 *
	 * @return void
	 */
	protected static function remember_page() {
		if ( ! class_exists( 'FLBuilderModel' ) || ! method_exists( 'FLBuilderModel', 'get_post_id' ) ) {
			return;
		}

		$page_id = (int) FLBuilderModel::get_post_id();

		if ( $page_id ) {
			update_option( 'acps_alerts_board_page', $page_id, false );
		}
	}

	/**
	 * Applies module settings to the alert.
	 *
	 * Public so the failsafe can call it from outside the class.
	 *
	 * @param ACPS_Alerts_Alert $alert    The Current Alert.
	 * @param object            $settings Module settings.
	 * @return void
	 */
	public static function apply( ACPS_Alerts_Alert $alert, $settings ) {
		$post_id  = $alert->get_id();
		$heading  = isset( $settings->heading ) ? trim( wp_strip_all_tags( (string) $settings->heading ) ) : '';
		$text     = isset( $settings->text ) ? (string) $settings->text : '';

		// Nothing typed here: read the wording out of the Beaver Builder popup
		// on this page instead, so the banner and the admin list still have
		// something to show for a popup built entirely in the builder.
		if ( '' === $heading || '' === trim( wp_strip_all_tags( $text ) ) ) {
			$from_popup = class_exists( 'ACPS_Alerts_Popup_Source' )
				? ACPS_Alerts_Popup_Source::wording()
				: array( 'heading' => '', 'text' => '' );

			if ( '' === $heading ) {
				$heading = trim( (string) $from_popup['heading'] );
			}

			if ( '' === trim( wp_strip_all_tags( $text ) ) ) {
				$text = (string) $from_popup['text'];
			}
		}
		$was_live = (bool) $alert->get( 'enabled' );
		$is_live  = isset( $settings->active ) && '1' === (string) $settings->active;

		// The heading is the alert's title, and a post with no title is a post
		// with no name in every list in WordPress. An empty heading means the
		// module has not been filled in yet, so leave the wording alone.
		if ( '' !== $heading ) {
			$result = wp_update_post(
				array(
					'ID'           => $post_id,
					'post_title'   => $heading,
					'post_content' => wp_kses_post( $text ),
				),
				true
			);

			if ( is_wp_error( $result ) ) {
				ACPS_Alerts_Failsafe::record( 'alert-popup/save', $result->get_error_message() );

				return;
			}
		}

		$alert->save( self::to_alert_settings( $alert, $settings, $text ) );

		// Switching it on starts its clock, so the daily cut-off measures from
		// when it went up rather than from whenever it was last edited. An edit
		// while it is already up must not push the cut-off back.
		if ( $is_live && ! $was_live ) {
			update_post_meta( $post_id, ACPS_Alerts_Alert::META_PREFIX . 'posted_at', time() );
			update_post_meta( $post_id, ACPS_Alerts_Alert::META_PREFIX . 'archived', 0 );
		}
	}

	/**
	 * Maps module settings onto the alert's own settings schema.
	 *
	 * Returns a complete set. ACPS_Alerts_Alert::sanitize() fills anything
	 * missing from the defaults and rejects anything out of range, so nothing
	 * here has to be trusted.
	 *
	 * @param ACPS_Alerts_Alert $alert    The alert being written to.
	 * @param object            $settings Module settings.
	 * @param string            $text     The popup body, for the board summary.
	 * @return array
	 */
	protected static function to_alert_settings( ACPS_Alerts_Alert $alert, $settings, $text ) {
		$get = function ( $key, $default = '' ) use ( $settings ) {
			return isset( $settings->{$key} ) ? $settings->{$key} : $default;
		};

		$expires = (string) $get( 'expires', 'daily' );

		return array(
			'enabled'        => '1' === (string) $get( 'active', '0' ) ? 1 : 0,
			'status_level'   => $get( 'level', 'info' ),
			'status_message' => wp_strip_all_tags( $text ),
			'on_board'       => 1,
			'as_popup'       => '1' === (string) $get( 'as_popup', '1' ) ? 1 : 0,
			'expires_mode'   => $expires,

			// The start and end boxes only mean anything on the custom schedule;
			// on the other two they are ignored rather than half-applied.
			'start'          => 'custom' === $expires ? $get( 'start' ) : '',
			'end'            => 'custom' === $expires ? $get( 'end' ) : '',

			'visibility'     => $get( 'visibility', 'public' ),
			'audience'       => $get( 'audience', 'all' ),
			'roles'          => (array) $get( 'roles', array() ),
			'display'        => $get( 'display', 'entire' ),
			'post_types'     => (array) $get( 'post_types', array() ),
			'post_ids'       => (string) $get( 'post_ids', '' ),
			'include_urls'   => $get( 'include_urls', '' ),
			'exclude_urls'   => $get( 'exclude_urls', '' ),
			'trigger'        => $get( 'trigger', 'load' ),
			'trigger_delay'  => $get( 'trigger_delay', 3 ),
			'trigger_scroll' => $get( 'trigger_scroll', 40 ),
			'frequency'      => $get( 'frequency', 'session' ),
			'frequency_days' => $get( 'frequency_days', 7 ),
			'position'       => $get( 'position', 'center' ),
			'width'          => $get( 'width', 640 ),
			'show_overlay'   => '1' === (string) $get( 'show_overlay', '1' ) ? 1 : 0,
			'dismissible'    => '1' === (string) $get( 'dismissible', '1' ) ? 1 : 0,
			'overlay_close'  => '1' === (string) $get( 'overlay_close', '1' ) ? 1 : 0,
			'esc_close'      => '1' === (string) $get( 'esc_close', '1' ) ? 1 : 0,
			'aria_label'     => $get( 'aria_label', '' ),
			'notes'          => $get( 'notes', '' ),
			'cta_text'       => $get( 'cta_text', '' ),
			'cta_url'        => $get( 'cta_url', '' ),
			'show_icon'      => '1' === (string) $get( 'show_icon', '1' ) ? 1 : 0,
			'icon_size'      => $get( 'icon_size', 56 ),
			'show_word'      => '1' === (string) $get( 'show_word', '0' ) ? 1 : 0,

			// Not edited here, and a complete save would otherwise reset them to
			// their defaults: when the alert went up, and whether it has already
			// been filed. Losing either would restart or skip the daily cut-off.
			'posted_at'      => $alert->get( 'posted_at' ),
			'archived'       => $alert->get( 'archived' ),
		);
	}

	/* ------------------------------------------------------------------ *
	 * Field choices.
	 * ------------------------------------------------------------------ */

	/**
	 * Public post types, for the targeting field.
	 *
	 * @return array
	 */
	public static function post_type_choices() {
		$choices = array();
		$types   = get_post_types( array( 'public' => true ), 'objects' );

		foreach ( $types as $type ) {
			if ( 'attachment' === $type->name ) {
				continue;
			}

			$choices[ $type->name ] = $type->labels->singular_name;
		}

		return $choices;
	}

	/**
	 * Editable roles, for the audience field.
	 *
	 * @return array
	 */
	public static function role_choices() {
		$choices = array();
		$roles   = function_exists( 'get_editable_roles' ) ? get_editable_roles() : array();

		foreach ( $roles as $slug => $role ) {
			$choices[ $slug ] = isset( $role['name'] ) ? $role['name'] : $slug;
		}

		return $choices;
	}

	/**
	 * Whether a Beaver Builder editing session is open.
	 *
	 * @return bool
	 */
	public static function in_builder() {
		return class_exists( 'FLBuilderModel' )
			&& method_exists( 'FLBuilderModel', 'is_builder_active' )
			&& FLBuilderModel::is_builder_active();
	}
}

FLBuilder::register_module(
	'ACPS_Alert_Popup_Module',
	array(
		'popup'    => array(
			'title'    => __( 'Popup', 'acps-alert-popups' ),
			'sections' => array(
				'content' => array(
					'title'       => __( 'The banner wording', 'acps-alert-popups' ),
					'description' => __( 'The popup itself is the Beaver Builder Popup module on this page — build it there, and the plugin shows that popup across the site. These boxes are only for the status page banner, which is text rather than a popup. Leave them empty and the banner takes the popup\'s own heading and text.', 'acps-alert-popups' ),
					'fields'      => array(
						'heading' => array(
							'type'        => 'text',
							'label'       => __( 'Banner heading', 'acps-alert-popups' ),
							'default'     => '',
							'placeholder' => __( 'Snow Day — All Schools Closed', 'acps-alert-popups' ),
							'connections' => array( 'string' ),
							'help'        => __( 'Also the alert\'s name in wp-admin. Leave empty to use the popup\'s own heading.', 'acps-alert-popups' ),
						),
						'text'    => array(
							'type'    => 'editor',
							'label'   => __( 'Banner text', 'acps-alert-popups' ),
							'default' => '',
							'media_buttons' => false,
							'rows'    => 8,
							'help'    => __( 'Shown under the heading on the status page. Leave empty to use the popup\'s own text.', 'acps-alert-popups' ),
						),
						'cta_text' => array(
							'type'        => 'text',
							'label'       => __( 'Link text', 'acps-alert-popups' ),
							'default'     => __( 'View updates', 'acps-alert-popups' ),
							'placeholder' => __( 'View updates', 'acps-alert-popups' ),
							'help'        => __( 'Only used when there is no Beaver Builder popup on this page to take instead — a popup has its own buttons.', 'acps-alert-popups' ),
						),
						'cta_url'  => array(
							'type'        => 'link',
							'label'       => __( 'Link goes to', 'acps-alert-popups' ),
							'default'     => '',
							'help'        => __( 'Leave empty to send people to the status page.', 'acps-alert-popups' ),
						),
					),
				),
				'status'  => array(
					'title'       => __( 'Status level', 'acps-alert-popups' ),
					'description' => __( 'This is the severity. It sets the word on the banner, the colour of the banner and the popup stripe, and the badge above the heading. It is the only urgency setting there is.', 'acps-alert-popups' ),
					'fields'      => array(
						'level'     => array(
							'type'    => 'select',
							'label'   => __( 'Status level', 'acps-alert-popups' ),
							'default' => 'info',
							'options' => ACPS_Alerts_Status::level_choices(),
							'help'    => __( 'The response actions are the SRP ones your staff and students are trained on.', 'acps-alert-popups' ),
						),
						'show_icon' => array(
							'type'    => 'select',
							'label'   => __( 'Show the level badge', 'acps-alert-popups' ),
							'default' => '1',
							'options' => array(
								'1' => __( 'Yes — the coloured disc above the heading', 'acps-alert-popups' ),
								'0' => __( 'No', 'acps-alert-popups' ),
							),
						),
						'icon_size' => array(
							'type'    => 'unit',
							'label'   => __( 'Badge size', 'acps-alert-popups' ),
							'default' => '56',
							'units'   => array( 'px' ),
							'slider'  => array( 'min' => 16, 'max' => 160, 'step' => 4 ),
						),
						'show_word' => array(
							'type'    => 'select',
							'label'   => __( 'Show the level word', 'acps-alert-popups' ),
							'default' => '0',
							'options' => array(
								'1' => __( 'Yes — HOLD, LOCKDOWN and so on', 'acps-alert-popups' ),
								'0' => __( 'No — the heading speaks for itself', 'acps-alert-popups' ),
							),
							'help'    => __( 'The heading is always your own title; this only adds the level word above it.', 'acps-alert-popups' ),
						),
					),
				),
			),
		),
		'alert'    => array(
			'title'    => __( 'On/off', 'acps-alert-popups' ),
			'sections' => array(
				'live'    => array(
					'title'  => __( 'Is it showing?', 'acps-alert-popups' ),
					'fields' => array(
						'active'     => array(
							'type'    => 'select',
							'label'   => __( 'Post this alert now', 'acps-alert-popups' ),
							'default' => '0',
							'options' => array(
								'1' => __( 'Yes — post it from this module on save', 'acps-alert-popups' ),
								'0' => __( 'No — leave the alert alone', 'acps-alert-popups' ),
							),
							'help'    => __( 'A one-time action: set to Yes and save to post this module\'s wording and settings as the current alert. It switches itself back to No afterwards. Day to day, post and switch the alert on or off from Site Alerts → Post an Alert — saving this page never changes the alert unless you set this to Yes.', 'acps-alert-popups' ),
						),
						'as_popup'   => array(
							'type'    => 'select',
							'label'   => __( 'Pop up across the site', 'acps-alert-popups' ),
							'default' => '1',
							'options' => array(
								'1' => __( 'Yes — show the popup everywhere', 'acps-alert-popups' ),
								'0' => __( 'No — status page banner only', 'acps-alert-popups' ),
							),
						),
						'visibility' => array(
							'type'    => 'select',
							'label'   => __( 'Who can see it', 'acps-alert-popups' ),
							'default' => 'public',
							'options' => array(
								'public'  => __( 'Everybody', 'acps-alert-popups' ),
								'admins'  => __( 'Staff only — stage it before it goes out', 'acps-alert-popups' ),
								'preview' => __( 'Hidden — only through the preview link', 'acps-alert-popups' ),
							),
						),
					),
				),
				'expires' => array(
					'title'  => __( 'When it comes down', 'acps-alert-popups' ),
					'fields' => array(
						'expires' => array(
							'type'    => 'select',
							'label'   => __( 'Take it down', 'acps-alert-popups' ),
							'default' => 'daily',
							'options' => array(
								'daily'  => sprintf(
									/* translators: %s: cut-off time, e.g. 17:50. */
									__( 'Automatically, at the daily cut-off (%s)', 'acps-alert-popups' ),
									ACPS_Alerts_Status::cutoff_time()
								),
								'keep'   => __( 'Keep it up until I switch it off', 'acps-alert-popups' ),
								'custom' => __( 'Between the dates below', 'acps-alert-popups' ),
							),
							'toggle'  => array(
								'custom' => array( 'fields' => array( 'start', 'end' ) ),
							),
						),
						'start'   => array(
							'type'        => 'text',
							'label'       => __( 'Start', 'acps-alert-popups' ),
							'default'     => '',
							'placeholder' => '2026-01-15 07:00',
							'help'        => __( 'Site timezone. Leave empty to start straight away.', 'acps-alert-popups' ),
						),
						'end'     => array(
							'type'        => 'text',
							'label'       => __( 'End', 'acps-alert-popups' ),
							'default'     => '',
							'placeholder' => '2026-01-16 17:50',
							'help'        => __( 'Site timezone. Leave empty to run until you switch it off.', 'acps-alert-popups' ),
						),
					),
				),
			),
		),
		'where'    => array(
			'title'    => __( 'Where &amp; who', 'acps-alert-popups' ),
			'sections' => array(
				'pages'    => array(
					'title'  => __( 'Which pages show it', 'acps-alert-popups' ),
					'fields' => array(
						'display'      => array(
							'type'    => 'select',
							'label'   => __( 'Display on', 'acps-alert-popups' ),
							'default' => 'entire',
							'options' => array(
								'entire'   => __( 'Entire site', 'acps-alert-popups' ),
								'front'    => __( 'Front page only', 'acps-alert-popups' ),
								'selected' => __( 'Selected locations', 'acps-alert-popups' ),
							),
							'toggle'  => array(
								'selected' => array( 'fields' => array( 'post_types', 'post_ids', 'include_urls' ) ),
							),
							'help'    => __( 'The status page itself never shows the popup, whatever this says.', 'acps-alert-popups' ),
						),
						'post_types'   => array(
							'type'         => 'select',
							'label'        => __( 'Post types', 'acps-alert-popups' ),
							'default'      => array(),
							'options'      => ACPS_Alert_Popup_Module::post_type_choices(),
							'multi-select' => true,
						),
						'post_ids'     => array(
							'type'        => 'text',
							'label'       => __( 'Specific page or post IDs', 'acps-alert-popups' ),
							'default'     => '',
							'placeholder' => '12, 34, 56',
						),
						'include_urls' => array(
							'type'        => 'textarea',
							'label'       => __( 'URL paths', 'acps-alert-popups' ),
							'default'     => '',
							'rows'        => 4,
							'placeholder' => "/enrollment\n/news/*",
							'help'        => __( 'One path per line. * is a wildcard.', 'acps-alert-popups' ),
						),
						'exclude_urls' => array(
							'type'        => 'textarea',
							'label'       => __( 'Never show on', 'acps-alert-popups' ),
							'default'     => '',
							'rows'        => 4,
							'placeholder' => "/apply/thank-you\n/staff/*",
							'help'        => __( 'Exclusions always win, whatever the targeting above says.', 'acps-alert-popups' ),
						),
					),
				),
				'audience' => array(
					'title'  => __( 'Who sees it', 'acps-alert-popups' ),
					'fields' => array(
						'audience' => array(
							'type'    => 'select',
							'label'   => __( 'Audience', 'acps-alert-popups' ),
							'default' => 'all',
							'options' => array(
								'all'        => __( 'Everyone', 'acps-alert-popups' ),
								'logged_out' => __( 'Logged out visitors', 'acps-alert-popups' ),
								'logged_in'  => __( 'Logged in users', 'acps-alert-popups' ),
								'roles'      => __( 'Specific roles', 'acps-alert-popups' ),
							),
							'toggle'  => array(
								'roles' => array( 'fields' => array( 'roles' ) ),
							),
						),
						'roles'    => array(
							'type'         => 'select',
							'label'        => __( 'Roles', 'acps-alert-popups' ),
							'default'      => array(),
							'options'      => ACPS_Alert_Popup_Module::role_choices(),
							'multi-select' => true,
						),
					),
				),
			),
		),
		'opening'  => array(
			'title'    => __( 'How it opens', 'acps-alert-popups' ),
			'sections' => array(
				'trigger' => array(
					'title'  => __( 'Opening', 'acps-alert-popups' ),
					'fields' => array(
						'trigger'        => array(
							'type'    => 'select',
							'label'   => __( 'Trigger', 'acps-alert-popups' ),
							'default' => 'load',
							'options' => array(
								'load'   => __( 'As soon as the page loads', 'acps-alert-popups' ),
								'delay'  => __( 'After a delay', 'acps-alert-popups' ),
								'scroll' => __( 'After scrolling down the page', 'acps-alert-popups' ),
								'exit'   => __( 'On exit intent', 'acps-alert-popups' ),
								'click'  => __( 'Only when something opens it', 'acps-alert-popups' ),
							),
							'toggle'  => array(
								'delay'  => array( 'fields' => array( 'trigger_delay' ) ),
								'scroll' => array( 'fields' => array( 'trigger_scroll' ) ),
							),
							'help'    => __( 'For a closure or an emergency use "as soon as the page loads".', 'acps-alert-popups' ),
						),
						'trigger_delay'  => array(
							'type'    => 'unit',
							'label'   => __( 'Delay', 'acps-alert-popups' ),
							'default' => '3',
							'units'   => array( 'seconds' ),
							'slider'  => array( 'min' => 0, 'max' => 60, 'step' => 1 ),
						),
						'trigger_scroll' => array(
							'type'    => 'unit',
							'label'   => __( 'Scroll depth', 'acps-alert-popups' ),
							'default' => '40',
							'units'   => array( '%' ),
							'slider'  => array( 'min' => 1, 'max' => 100, 'step' => 1 ),
						),
					),
				),
				'again'   => array(
					'title'  => __( 'How often it comes back', 'acps-alert-popups' ),
					'fields' => array(
						'frequency'      => array(
							'type'    => 'select',
							'label'   => __( 'Show again', 'acps-alert-popups' ),
							'default' => 'session',
							'options' => array(
								'always'  => __( 'Every page view', 'acps-alert-popups' ),
								'session' => __( 'Once per browser session', 'acps-alert-popups' ),
								'edit'    => __( 'Once, until I change this alert', 'acps-alert-popups' ),
								'days'    => __( 'Once every X days', 'acps-alert-popups' ),
								'once'    => __( 'Once, then never again', 'acps-alert-popups' ),
							),
							'toggle'  => array(
								'days' => array( 'fields' => array( 'frequency_days' ) ),
							),
							'help'    => __( '"Once per browser session" is right nearly every time. "Once, until I change this alert" shows it once and then leaves people alone until you edit the wording or any setting here — then everybody sees it again. "Every page view" is for genuine emergencies.', 'acps-alert-popups' ),
						),
						'frequency_days' => array(
							'type'    => 'unit',
							'label'   => __( 'Days between showings', 'acps-alert-popups' ),
							'default' => '7',
							'units'   => array( 'days' ),
							'slider'  => array( 'min' => 1, 'max' => 90, 'step' => 1 ),
						),
					),
				),
			),
		),
		'appearance' => array(
			'title'    => __( 'Style', 'acps-alert-popups' ),
			'sections' => array(
				'layout'  => array(
					'title'  => __( 'The popup box', 'acps-alert-popups' ),
					'fields' => array(
						'position'     => array(
							'type'    => 'select',
							'label'   => __( 'Position', 'acps-alert-popups' ),
							'default' => 'center',
							'options' => array(
								'center'       => __( 'Centered', 'acps-alert-popups' ),
								'top'          => __( 'Top of the screen', 'acps-alert-popups' ),
								'bottom'       => __( 'Bottom of the screen', 'acps-alert-popups' ),
								'bottom-right' => __( 'Bottom right corner', 'acps-alert-popups' ),
								'bottom-left'  => __( 'Bottom left corner', 'acps-alert-popups' ),
							),
						),
						'width'        => array(
							'type'    => 'unit',
							'label'   => __( 'Maximum width', 'acps-alert-popups' ),
							'default' => '640',
							'units'   => array( 'px' ),
							'slider'  => array( 'min' => 200, 'max' => 1200, 'step' => 10 ),
						),
						'show_overlay' => array(
							'type'    => 'select',
							'label'   => __( 'Dim the page behind it', 'acps-alert-popups' ),
							'default' => '1',
							'options' => array(
								'1' => __( 'Yes', 'acps-alert-popups' ),
								'0' => __( 'No', 'acps-alert-popups' ),
							),
						),
					),
				),
				'closing' => array(
					'title'       => __( 'Closing it', 'acps-alert-popups' ),
					'description' => __( 'Leave at least one of these on. An alert with no way out traps keyboard visitors.', 'acps-alert-popups' ),
					'fields'      => array(
						'dismissible'   => array(
							'type'    => 'select',
							'label'   => __( 'Close button', 'acps-alert-popups' ),
							'default' => '1',
							'options' => array(
								'1' => __( 'Show one', 'acps-alert-popups' ),
								'0' => __( 'Hide it', 'acps-alert-popups' ),
							),
						),
						'overlay_close' => array(
							'type'    => 'select',
							'label'   => __( 'Clicking the background', 'acps-alert-popups' ),
							'default' => '1',
							'options' => array(
								'1' => __( 'Closes the alert', 'acps-alert-popups' ),
								'0' => __( 'Does nothing', 'acps-alert-popups' ),
							),
						),
						'esc_close'     => array(
							'type'    => 'select',
							'label'   => __( 'The Escape key', 'acps-alert-popups' ),
							'default' => '1',
							'options' => array(
								'1' => __( 'Closes the alert', 'acps-alert-popups' ),
								'0' => __( 'Does nothing', 'acps-alert-popups' ),
							),
						),
						'aria_label'    => array(
							'type'        => 'text',
							'label'       => __( 'Screen reader label', 'acps-alert-popups' ),
							'default'     => '',
							'placeholder' => __( 'Site alert', 'acps-alert-popups' ),
							'help'        => __( 'Announced when the alert opens. Defaults to the heading.', 'acps-alert-popups' ),
						),
						'notes'         => array(
							'type'    => 'textarea',
							'label'   => __( 'Internal notes', 'acps-alert-popups' ),
							'default' => '',
							'rows'    => 3,
							'help'    => __( 'Never shown to anybody. A note to whoever edits this next.', 'acps-alert-popups' ),
						),
					),
				),
			),
		),
	)
);
