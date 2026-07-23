<?php
/**
 * Form model — CRUD for the forms table. Fields and settings are JSON blobs
 * (spec §3.4: JSON is simpler and adequate).
 *
 * @package ACPS\SiteToolkit
 */

namespace ACPS\SiteToolkit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Form.
 */
class Form {

	/** @var int */
	public $id = 0;
	/** @var string */
	public $title = '';
	/** @var string */
	public $slug = '';
	/** @var string draft|published */
	public $status = 'draft';
	/** @var bool */
	public $is_feedback = false;
	/** @var array[] Field definitions. */
	public $fields = array();
	/** @var array Settings blob. */
	public $settings = array();
	/** @var string */
	public $created_at = '';
	/** @var string */
	public $modified_at = '';

	/**
	 * Default form-level settings.
	 *
	 * @return array
	 */
	public static function default_settings() {
		return array(
			'confirmation_type'    => 'message', // message | redirect | both.
			'confirmation_message' => 'Thank you — your response has been recorded.',
			'confirmation_redirect' => '',
			'notify_admin'         => 1,
			'notify_recipients'    => '', // blank => global setting => admin_email.
			'notify_subject'       => 'New submission: {form_title}',
			'autoreply_enable'     => 0,
			'autoreply_field'      => '', // field key holding submitter email.
			'autoreply_subject'    => 'We received your message',
			'autoreply_body'       => "Thanks for getting in touch. We'll be in contact if a response is needed.",
			'multipage'            => 0,
			'submit_label'         => 'Submit',
			'style'                => array(
				'accent'      => '', // blank => inherit theme.
				'width'       => 'full',
			),
			// Per-form spam overrides fall back to global settings when unset.
		);
	}

	/**
	 * Hydrate a Form from a DB row (object or array).
	 *
	 * @param object|array $row Row.
	 * @return Form
	 */
	public static function from_row( $row ) {
		$row  = (object) $row;
		$form = new self();

		$form->id          = (int) $row->id;
		$form->title       = (string) $row->title;
		$form->slug        = (string) $row->slug;
		$form->status      = (string) $row->status;
		$form->is_feedback = ! empty( $row->is_feedback );
		$form->fields      = self::decode_json( $row->fields, array() );
		$form->settings    = wp_parse_args( self::decode_json( $row->settings, array() ), self::default_settings() );
		$form->created_at  = (string) $row->created_at;
		$form->modified_at = (string) $row->modified_at;

		return $form;
	}

	/**
	 * Load a form by id.
	 *
	 * @param int $id Form id.
	 * @return Form|null
	 */
	public static function find( $id ) {
		global $wpdb;
		$table = Schema::table( 'forms' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB
		return $row ? self::from_row( $row ) : null;
	}

	/**
	 * Load a form by slug.
	 *
	 * @param string $slug Slug.
	 * @return Form|null
	 */
	public static function find_by_slug( $slug ) {
		global $wpdb;
		$table = Schema::table( 'forms' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s", sanitize_title( $slug ) ) ); // phpcs:ignore WordPress.DB
		return $row ? self::from_row( $row ) : null;
	}

	/**
	 * The single feedback form, if it exists.
	 *
	 * @return Form|null
	 */
	public static function feedback_form() {
		global $wpdb;
		$table = Schema::table( 'forms' );
		$row   = $wpdb->get_row( "SELECT * FROM {$table} WHERE is_feedback = 1 ORDER BY id ASC LIMIT 1" ); // phpcs:ignore WordPress.DB
		return $row ? self::from_row( $row ) : null;
	}

	/**
	 * List forms.
	 *
	 * @param array $args status, include_feedback, orderby.
	 * @return Form[]
	 */
	public static function all( $args = array() ) {
		global $wpdb;
		$table = Schema::table( 'forms' );
		$where = '1=1';

		$rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE {$where} ORDER BY modified_at DESC" ); // phpcs:ignore WordPress.DB
		return array_map( array( __CLASS__, 'from_row' ), $rows ?: array() );
	}

	/**
	 * Persist this form (insert or update).
	 *
	 * @return int Form id.
	 */
	public function save() {
		global $wpdb;
		$table = Schema::table( 'forms' );
		$now   = current_time( 'mysql' );

		if ( '' === $this->slug ) {
			$this->slug = $this->unique_slug( $this->title ? $this->title : 'form' );
		}

		$data = array(
			'title'       => $this->title,
			'slug'        => $this->slug,
			'status'      => in_array( $this->status, array( 'draft', 'published' ), true ) ? $this->status : 'draft',
			'is_feedback' => $this->is_feedback ? 1 : 0,
			'fields'      => wp_json_encode( array_values( $this->fields ) ),
			'settings'    => wp_json_encode( $this->settings ),
			'modified_at' => $now,
		);

		if ( $this->id ) {
			$wpdb->update( $table, $data, array( 'id' => $this->id ) ); // phpcs:ignore WordPress.DB
		} else {
			$data['created_at'] = $now;
			$wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB
			$this->id = (int) $wpdb->insert_id;
		}
		return $this->id;
	}

	/**
	 * Delete a form and all its entries/values/notes.
	 *
	 * @param int $id Form id.
	 */
	public static function delete( $id ) {
		global $wpdb;
		$id = absint( $id );

		$entries = Schema::table( 'entries' );
		$values  = Schema::table( 'entry_values' );
		$notes   = Schema::table( 'entry_notes' );
		$forms   = Schema::table( 'forms' );

		$entry_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$entries} WHERE form_id = %d", $id ) ); // phpcs:ignore WordPress.DB
		if ( $entry_ids ) {
			$in = implode( ',', array_map( 'absint', $entry_ids ) );
			$wpdb->query( "DELETE FROM {$values} WHERE entry_id IN ({$in})" ); // phpcs:ignore WordPress.DB
			$wpdb->query( "DELETE FROM {$notes} WHERE entry_id IN ({$in})" ); // phpcs:ignore WordPress.DB
			$wpdb->query( "DELETE FROM {$entries} WHERE form_id = {$id}" ); // phpcs:ignore WordPress.DB
		}
		$wpdb->delete( $forms, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Duplicate a form (spec §7.3).
	 *
	 * @param int $id Source form id.
	 * @return int|null New form id.
	 */
	public static function duplicate( $id ) {
		$src = self::find( $id );
		if ( ! $src ) {
			return null;
		}
		$copy              = new self();
		$copy->title       = $src->title . ' (copy)';
		$copy->status      = 'draft';
		$copy->is_feedback = false; // never duplicate the feedback flag.
		$copy->fields      = $src->fields;
		$copy->settings    = $src->settings;
		return $copy->save();
	}

	/**
	 * Generate a unique slug.
	 *
	 * @param string $base Base string.
	 * @return string
	 */
	private function unique_slug( $base ) {
		global $wpdb;
		$table = Schema::table( 'forms' );
		$slug  = sanitize_title( $base );
		$slug  = $slug ? $slug : 'form';
		$try   = $slug;
		$i     = 2;
		while ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s AND id <> %d", $try, $this->id ) ) ) { // phpcs:ignore WordPress.DB
			$try = $slug . '-' . $i;
			$i++;
		}
		return $try;
	}

	/**
	 * Decode a JSON column safely.
	 *
	 * @param mixed $json     Raw column value.
	 * @param mixed $fallback Fallback.
	 * @return mixed
	 */
	private static function decode_json( $json, $fallback ) {
		if ( empty( $json ) ) {
			return $fallback;
		}
		$decoded = json_decode( $json, true );
		return ( JSON_ERROR_NONE === json_last_error() && null !== $decoded ) ? $decoded : $fallback;
	}
}
