<?php
/**
 * Module: Google Calendar Sharing Management (spec Section 5.3).
 *
 * Portal users manage sharing (ACL) on calendars they've been granted, using the
 * shared service account — they never see a Google login. Per the Q1 decision,
 * changes apply LIVE by default (audit-logged) and can be switched to route
 * through the Content Update Queue via the "calendar_requires_approval" setting.
 *
 * @package ExternalPortal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Google Calendar module.
 */
class EXP_Module_Google_Calendar {

	const CAP  = 'manage_calendar';
	const SLUG = 'google_calendar';
	const TYPE = 'calendar_acl';

	/**
	 * Roles a portal user may assign (owner deliberately excluded).
	 *
	 * @return array<string,string>
	 */
	public static function roles() {
		return array(
			'freeBusyReader' => __( 'See free/busy only', 'external-portal' ),
			'reader'         => __( 'See all event details', 'external-portal' ),
			'writer'         => __( 'Make changes to events', 'external-portal' ),
		);
	}

	/**
	 * Register.
	 *
	 * @param EXP_Registry $r Registry.
	 */
	public static function register( $r ) {
		$r->register_capability(
			array(
				'key'            => self::CAP,
				'label'          => __( 'Manage calendar sharing', 'external-portal' ),
				'description'    => __( 'Add/remove people and change access on specific Google calendars.', 'external-portal' ),
				'target_type'    => 'calendar',
				'target_options' => array( __CLASS__, 'calendar_options' ),
				'module'         => self::SLUG,
				'core'           => true,
			)
		);
		$r->register_menu_item(
			array(
				'slug'       => self::SLUG,
				'label'      => __( 'Calendars', 'external-portal' ),
				'icon'       => 'calendar-alt',
				'capability' => self::CAP,
				'render'     => array( __CLASS__, 'render' ),
				'handle'     => array( __CLASS__, 'handle' ),
				'position'   => 40,
				'core'       => true,
			)
		);
		$r->register_queue_type(
			array(
				'type'            => self::TYPE,
				'label'           => __( 'Calendar sharing change', 'external-portal' ),
				'review_renderer' => array( __CLASS__, 'review' ),
				'applier'         => array( __CLASS__, 'apply' ),
				'core'            => true,
			)
		);
		$r->register_activity_formatter( self::TYPE, array( __CLASS__, 'activity' ) );
	}

	/**
	 * Admin target options: the calendar whitelist.
	 *
	 * @return array<string,string> id => label.
	 */
	public static function calendar_options() {
		$out = array();
		foreach ( (array) EXP_Settings::get( 'google_calendar_whitelist', array() ) as $cal ) {
			if ( ! empty( $cal['id'] ) ) {
				$out[ $cal['id'] ] = ! empty( $cal['label'] ) ? $cal['label'] : $cal['id'];
			}
		}
		return $out;
	}

	/**
	 * Label for a calendar id from the whitelist.
	 *
	 * @param string $id Calendar id.
	 * @return string
	 */
	protected static function calendar_label( $id ) {
		$opts = self::calendar_options();
		return isset( $opts[ $id ] ) ? $opts[ $id ] : $id;
	}

	/**
	 * Render.
	 *
	 * @param array $ctx Context.
	 * @return string
	 */
	public static function render( array $ctx ) {
		$user    = $ctx['user'];
		$granted = EXP_Permissions::targets_for( $user->id, self::CAP );
		$granted = array_values( array_intersect( $granted, array_keys( self::calendar_options() ) ) );

		if ( empty( $granted ) ) {
			return EXP_UI::notice( 'info', __( 'You have not been granted access to manage any calendars yet.', 'external-portal' ) );
		}

		$cal = isset( $_GET['cal'] ) ? sanitize_text_field( wp_unslash( $_GET['cal'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' === $cal || ! in_array( $cal, $granted, true ) ) {
			if ( 1 === count( $granted ) ) {
				$cal = $granted[0];
			} else {
				return self::render_calendar_chooser( $granted );
			}
		}

		return self::render_calendar_manager( $ctx, $cal );
	}

	/**
	 * Calendar chooser.
	 *
	 * @param array $granted Granted calendar ids.
	 * @return string
	 */
	protected static function render_calendar_chooser( $granted ) {
		$html = '<p>' . esc_html__( 'Choose a calendar to manage.', 'external-portal' ) . '</p><ul class="exp-list">';
		foreach ( $granted as $id ) {
			$url   = add_query_arg(
				array(
					'view' => self::SLUG,
					'cal'  => rawurlencode( $id ),
				),
				external_portal()->dashboard_url()
			);
			$html .= '<li class="exp-list__item"><a class="exp-link" href="' . esc_url( $url ) . '">' . esc_html( self::calendar_label( $id ) ) . '</a></li>';
		}
		return $html . '</ul>';
	}

	/**
	 * The sharing manager for one calendar.
	 *
	 * @param array  $ctx Context.
	 * @param string $cal Calendar id.
	 * @return string
	 */
	protected static function render_calendar_manager( array $ctx, $cal ) {
		$live = ! EXP_Settings::get( 'calendar_requires_approval', 0 );

		$html = '<p>' . sprintf(
			/* translators: %s: calendar label */
			esc_html__( 'Sharing for: %s', 'external-portal' ),
			'<strong>' . esc_html( self::calendar_label( $cal ) ) . '</strong>'
		) . '</p>';

		$html .= $live
			? EXP_UI::notice( 'info', __( 'Changes take effect immediately in Google Calendar.', 'external-portal' ) )
			: EXP_UI::notice( 'info', __( 'Changes are submitted for administrator approval before taking effect.', 'external-portal' ) );

		// Current ACL (only meaningful when applying live and creds work).
		if ( $live ) {
			$client = self::get_client();
			if ( is_wp_error( $client ) ) {
				$html .= EXP_UI::notice( 'warning', $client->get_error_message() );
			} else {
				$rules = $client->list_acl( $cal );
				if ( is_wp_error( $rules ) ) {
					$html .= EXP_UI::notice( 'warning', $rules->get_error_message() );
				} else {
					$html .= self::render_acl_table( $ctx, $cal, $rules );
				}
			}
		}

		// Add-person form.
		$html .= self::render_add_form( $ctx, $cal );
		return $html;
	}

	/**
	 * Render the current ACL entries with change/remove controls.
	 *
	 * @param array  $ctx   Context.
	 * @param string $cal   Calendar id.
	 * @param array  $rules ACL rules.
	 * @return string
	 */
	protected static function render_acl_table( array $ctx, $cal, $rules ) {
		$roles = self::roles();

		$html  = '<h3 class="exp-subhead">' . esc_html__( 'People with access', 'external-portal' ) . '</h3>';
		$html .= '<table class="exp-table"><caption class="screen-reader-text">' . esc_html__( 'Current calendar sharing', 'external-portal' ) . '</caption>';
		$html .= '<thead><tr><th scope="col">' . esc_html__( 'Person', 'external-portal' ) . '</th><th scope="col">' . esc_html__( 'Access', 'external-portal' ) . '</th><th scope="col">' . esc_html__( 'Actions', 'external-portal' ) . '</th></tr></thead><tbody>';

		$count = 0;
		foreach ( $rules as $rule ) {
			$scope = isset( $rule['scope'] ) ? $rule['scope'] : array();
			// Only show individual users (not 'default'/'domain' system entries).
			if ( empty( $scope['type'] ) || 'user' !== $scope['type'] || empty( $scope['value'] ) ) {
				continue;
			}
			$count++;
			$email   = $scope['value'];
			$role    = isset( $rule['role'] ) ? $rule['role'] : 'reader';
			$rule_id = isset( $rule['id'] ) ? $rule['id'] : '';
			$sel_id  = 'exp-role-' . md5( $rule_id );

			$html .= '<tr><th scope="row">' . esc_html( $email ) . '</th>';

			// Change-role form.
			$html .= '<td><form method="post" class="exp-inline-form">';
			$html .= EXP_UI::module_hidden_fields( $ctx );
			$html .= '<input type="hidden" name="exp_cal" value="' . esc_attr( $cal ) . '" />';
			$html .= '<input type="hidden" name="exp_cal_op" value="update" />';
			$html .= '<input type="hidden" name="exp_rule_id" value="' . esc_attr( $rule_id ) . '" />';
			$html .= '<label class="screen-reader-text" for="' . esc_attr( $sel_id ) . '">' . esc_html__( 'Access level', 'external-portal' ) . '</label>';
			$html .= '<select id="' . esc_attr( $sel_id ) . '" name="exp_role">';
			foreach ( $roles as $value => $label ) {
				$html .= '<option value="' . esc_attr( $value ) . '"' . selected( $role, $value, false ) . '>' . esc_html( $label ) . '</option>';
			}
			$html .= '</select> <button type="submit" class="exp-button exp-button--small">' . esc_html__( 'Update', 'external-portal' ) . '</button>';
			$html .= '</form></td>';

			// Remove form.
			$html .= '<td><form method="post" class="exp-inline-form" onsubmit="return confirm(\'' . esc_js( __( 'Remove this person from the calendar?', 'external-portal' ) ) . '\');">';
			$html .= EXP_UI::module_hidden_fields( $ctx );
			$html .= '<input type="hidden" name="exp_cal" value="' . esc_attr( $cal ) . '" />';
			$html .= '<input type="hidden" name="exp_cal_op" value="remove" />';
			$html .= '<input type="hidden" name="exp_rule_id" value="' . esc_attr( $rule_id ) . '" />';
			$html .= '<button type="submit" class="exp-button exp-button--danger exp-button--small">' . esc_html__( 'Remove', 'external-portal' ) . '</button>';
			$html .= '</form></td></tr>';
		}

		if ( 0 === $count ) {
			$html .= '<tr><td colspan="3">' . esc_html__( 'No individual people are shared on this calendar yet.', 'external-portal' ) . '</td></tr>';
		}
		$html .= '</tbody></table>';
		return $html;
	}

	/**
	 * The add-a-person form.
	 *
	 * @param array  $ctx Context.
	 * @param string $cal Calendar id.
	 * @return string
	 */
	protected static function render_add_form( array $ctx, $cal ) {
		$roles = self::roles();

		$html  = '<h3 class="exp-subhead">' . esc_html__( 'Add a person', 'external-portal' ) . '</h3>';
		$html .= '<form method="post" class="exp-form">';
		$html .= EXP_UI::module_hidden_fields( $ctx );
		$html .= '<input type="hidden" name="exp_cal" value="' . esc_attr( $cal ) . '" />';
		$html .= '<input type="hidden" name="exp_cal_op" value="add" />';
		$html .= EXP_UI::field(
			array(
				'name'         => 'exp_person_email',
				'label'        => __( 'Person’s email address', 'external-portal' ),
				'type'         => 'email',
				'required'     => true,
				'autocomplete' => 'off',
				'inputmode'    => 'email',
			)
		);
		$html .= '<div class="exp-field"><label class="exp-field__label" for="exp-add-role">' . esc_html__( 'Access level', 'external-portal' ) . '</label>';
		$html .= '<select id="exp-add-role" name="exp_role" class="exp-field__input">';
		foreach ( $roles as $value => $label ) {
			$html .= '<option value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</option>';
		}
		$html .= '</select></div>';
		$html .= '<button type="submit" class="exp-button">' . esc_html__( 'Add person', 'external-portal' ) . '</button>';
		$html .= '</form>';
		return $html;
	}

	/**
	 * Handle add/remove/update.
	 *
	 * @param array $ctx Context.
	 * @return array Notices.
	 */
	public static function handle( array $ctx ) {
		$user = $ctx['user'];
		$cal  = isset( $_POST['exp_cal'] ) ? sanitize_text_field( wp_unslash( $_POST['exp_cal'] ) ) : '';
		$op   = isset( $_POST['exp_cal_op'] ) ? sanitize_key( wp_unslash( $_POST['exp_cal_op'] ) ) : '';

		if ( ! in_array( $cal, array_keys( self::calendar_options() ), true ) ) {
			return array( array( 'type' => 'error', 'text' => __( 'Unknown calendar.', 'external-portal' ) ) );
		}
		if ( ! EXP_Permissions::user_can( $user->id, self::CAP, $cal ) ) {
			return array( array( 'type' => 'error', 'text' => __( 'You do not have permission to manage that calendar.', 'external-portal' ) ) );
		}

		$role = isset( $_POST['exp_role'] ) ? sanitize_text_field( wp_unslash( $_POST['exp_role'] ) ) : '';
		if ( in_array( $op, array( 'add', 'update' ), true ) && ! array_key_exists( $role, self::roles() ) ) {
			return array( array( 'type' => 'error', 'text' => __( 'Please choose a valid access level.', 'external-portal' ) ) );
		}

		$payload = array(
			'calendar_id' => $cal,
			'op'          => $op,
			'role'        => $role,
			'email'       => isset( $_POST['exp_person_email'] ) ? sanitize_email( wp_unslash( $_POST['exp_person_email'] ) ) : '',
			'rule_id'     => isset( $_POST['exp_rule_id'] ) ? sanitize_text_field( wp_unslash( $_POST['exp_rule_id'] ) ) : '',
		);

		if ( 'add' === $op && ! is_email( $payload['email'] ) ) {
			return array( array( 'type' => 'error', 'text' => __( 'Please enter a valid email address.', 'external-portal' ) ) );
		}
		if ( in_array( $op, array( 'remove', 'update' ), true ) && '' === $payload['rule_id'] ) {
			return array( array( 'type' => 'error', 'text' => __( 'Missing sharing entry reference.', 'external-portal' ) ) );
		}

		// Q1 decision: route through the queue when approval is required, else apply live.
		if ( EXP_Settings::get( 'calendar_requires_approval', 0 ) ) {
			$id = EXP_Queue::submit(
				array(
					'type'         => self::TYPE,
					'submitted_by' => $user->id,
					'content_ref'  => 'calendar:' . $cal,
					'payload'      => $payload,
				)
			);
			if ( is_wp_error( $id ) ) {
				return array( array( 'type' => 'error', 'text' => $id->get_error_message() ) );
			}
			return array( array( 'type' => 'success', 'text' => __( 'Your calendar change was submitted for approval.', 'external-portal' ) ) );
		}

		$result = self::perform( $payload );
		if ( is_wp_error( $result ) ) {
			return array( array( 'type' => 'error', 'text' => $result->get_error_message() ) );
		}

		EXP_Audit::log(
			'calendar.acl_changed',
			array(
				'actor_id'   => $user->id,
				'object_ref' => 'calendar:' . $cal,
				'detail'     => array(
					'op'    => $op,
					'email' => $payload['email'],
					'role'  => $role,
				),
			)
		);
		return array( array( 'type' => 'success', 'text' => __( 'Calendar sharing updated.', 'external-portal' ) ) );
	}

	/**
	 * Execute an ACL operation against Google.
	 *
	 * @param array $payload Operation payload.
	 * @return true|WP_Error
	 */
	protected static function perform( array $payload ) {
		$client = self::get_client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		switch ( $payload['op'] ) {
			case 'add':
				$res = $client->insert_acl( $payload['calendar_id'], $payload['email'], $payload['role'] );
				break;
			case 'update':
				$res = $client->update_acl_role( $payload['calendar_id'], $payload['rule_id'], $payload['role'] );
				break;
			case 'remove':
				$res = $client->delete_acl( $payload['calendar_id'], $payload['rule_id'] );
				break;
			default:
				return new WP_Error( 'exp_cal_op', __( 'Unknown calendar operation.', 'external-portal' ) );
		}
		return is_wp_error( $res ) ? $res : true;
	}

	/**
	 * Applier used when calendar changes route through the queue.
	 *
	 * @param object $item Queue item.
	 * @return true|WP_Error
	 */
	public static function apply( $item ) {
		return self::perform( (array) $item->payload_data );
	}

	/**
	 * Build the shared client.
	 *
	 * @return EXP_Google_Calendar_Client|WP_Error
	 */
	protected static function get_client() {
		return EXP_Google_Calendar_Client::from_settings();
	}

	/**
	 * Review preview.
	 *
	 * @param object $item Queue item.
	 * @return string
	 */
	public static function review( $item ) {
		$d   = $item->payload_data;
		$map = array(
			'add'    => __( 'Add person', 'external-portal' ),
			'remove' => __( 'Remove person', 'external-portal' ),
			'update' => __( 'Change access', 'external-portal' ),
		);
		$op   = isset( $map[ $d['op'] ?? '' ] ) ? $map[ $d['op'] ] : ( $d['op'] ?? '' );
		$html = '<p><strong>' . esc_html__( 'Calendar:', 'external-portal' ) . '</strong> ' . esc_html( self::calendar_label( $d['calendar_id'] ?? '' ) ) . '</p>';
		$html .= '<p><strong>' . esc_html__( 'Operation:', 'external-portal' ) . '</strong> ' . esc_html( $op ) . '</p>';
		if ( ! empty( $d['email'] ) ) {
			$html .= '<p><strong>' . esc_html__( 'Person:', 'external-portal' ) . '</strong> ' . esc_html( $d['email'] ) . '</p>';
		}
		if ( ! empty( $d['role'] ) ) {
			$roles = self::roles();
			$html .= '<p><strong>' . esc_html__( 'Access:', 'external-portal' ) . '</strong> ' . esc_html( $roles[ $d['role'] ] ?? $d['role'] ) . '</p>';
		}
		return $html;
	}

	/**
	 * My Activity line.
	 *
	 * @param object $item Queue item.
	 * @return string
	 */
	public static function activity( $item ) {
		$d = $item->payload_data;
		return sprintf(
			/* translators: 1: operation, 2: calendar label */
			esc_html__( 'Calendar sharing (%1$s): %2$s', 'external-portal' ),
			esc_html( $d['op'] ?? '' ),
			esc_html( self::calendar_label( $d['calendar_id'] ?? '' ) )
		);
	}
}
