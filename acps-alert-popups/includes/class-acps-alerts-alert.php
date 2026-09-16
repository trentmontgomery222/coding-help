<?php
/**
 * A single alert: a Beaver Builder popup plus the settings that drive it.
 *
 * @package ACPS_Alert_Popups
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wraps a popup post and its alert meta.
 */
class ACPS_Alerts_Alert {

	const META_PREFIX = '_acps_alert_';

	/**
	 * The popup post.
	 *
	 * @var WP_Post
	 */
	public $post;

	/**
	 * Cached settings.
	 *
	 * @var array|null
	 */
	protected $settings = null;

	/**
	 * Constructor.
	 *
	 * @param WP_Post|int $post Popup post or ID.
	 */
	public function __construct( $post ) {
		$this->post = get_post( $post );
	}

	/**
	 * Alert setting definitions: default value and sanitizer per key.
	 *
	 * @return array
	 */
	public static function schema() {
		return array(
			'enabled'          => array( 'default' => 0, 'type' => 'bool' ),
			'severity'         => array( 'default' => 'info', 'type' => 'choice', 'choices' => array( 'info', 'success', 'warning', 'critical' ) ),
			'priority'         => array( 'default' => 10, 'type' => 'int', 'min' => 0, 'max' => 100 ),
			'start'            => array( 'default' => '', 'type' => 'datetime' ),
			'end'              => array( 'default' => '', 'type' => 'datetime' ),
			'trigger'          => array( 'default' => 'load', 'type' => 'choice', 'choices' => array( 'load', 'delay', 'scroll', 'exit', 'click' ) ),
			'trigger_delay'    => array( 'default' => 3, 'type' => 'int', 'min' => 0, 'max' => 600 ),
			'trigger_scroll'   => array( 'default' => 40, 'type' => 'int', 'min' => 1, 'max' => 100 ),
			'frequency'        => array( 'default' => 'session', 'type' => 'choice', 'choices' => array( 'always', 'session', 'days', 'once' ) ),
			'frequency_days'   => array( 'default' => 7, 'type' => 'int', 'min' => 1, 'max' => 365 ),
			'audience'         => array( 'default' => 'all', 'type' => 'choice', 'choices' => array( 'all', 'logged_in', 'logged_out', 'roles' ) ),
			'roles'            => array( 'default' => array(), 'type' => 'roles' ),
			'display'          => array( 'default' => 'entire', 'type' => 'choice', 'choices' => array( 'entire', 'front', 'selected' ) ),
			'post_types'       => array( 'default' => array(), 'type' => 'slug_list' ),
			'post_ids'         => array( 'default' => array(), 'type' => 'id_list' ),
			'include_urls'     => array( 'default' => '', 'type' => 'patterns' ),
			'exclude_urls'     => array( 'default' => '', 'type' => 'patterns' ),
			'position'         => array( 'default' => 'center', 'type' => 'choice', 'choices' => array( 'center', 'top', 'bottom', 'bottom-right', 'bottom-left' ) ),
			'width'            => array( 'default' => 640, 'type' => 'int', 'min' => 200, 'max' => 1600 ),
			'dismissible'      => array( 'default' => 1, 'type' => 'bool' ),
			'overlay_close'    => array( 'default' => 1, 'type' => 'bool' ),
			'esc_close'        => array( 'default' => 1, 'type' => 'bool' ),
			'show_overlay'     => array( 'default' => 1, 'type' => 'bool' ),
			'aria_label'       => array( 'default' => '', 'type' => 'text' ),
			'notes'            => array( 'default' => '', 'type' => 'textarea' ),
		);
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function default_settings() {
		$defaults = array();

		foreach ( self::schema() as $key => $field ) {
			$defaults[ $key ] = $field['default'];
		}

		return $defaults;
	}

	/**
	 * The popup post ID.
	 *
	 * @return int
	 */
	public function get_id() {
		return $this->post ? (int) $this->post->ID : 0;
	}

	/**
	 * The popup title.
	 *
	 * @return string
	 */
	public function get_title() {
		if ( ! $this->post ) {
			return '';
		}

		$title = get_the_title( $this->post );

		return '' !== trim( $title ) ? $title : sprintf( /* translators: %d: post ID. */ __( 'Popup #%d', 'acps-alert-popups' ), $this->get_id() );
	}

	/**
	 * All alert settings for this popup.
	 *
	 * @return array
	 */
	public function get_settings() {
		if ( null !== $this->settings ) {
			return $this->settings;
		}

		$settings = self::default_settings();

		foreach ( array_keys( $settings ) as $key ) {
			$stored = get_post_meta( $this->get_id(), self::META_PREFIX . $key, true );

			if ( '' !== $stored && null !== $stored ) {
				$settings[ $key ] = $stored;
			}
		}

		$this->settings = self::sanitize( $settings );

		return $this->settings;
	}

	/**
	 * A single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Value used when the key is unknown.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		$settings = $this->get_settings();

		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	/**
	 * Saves alert settings for this popup.
	 *
	 * @param array $input Raw input.
	 * @return array Stored settings.
	 */
	public function save( array $input ) {
		$clean = self::sanitize( $input );

		foreach ( $clean as $key => $value ) {
			update_post_meta( $this->get_id(), self::META_PREFIX . $key, $value );
		}

		$this->settings = $clean;

		/**
		 * Fires after an alert's settings are saved.
		 *
		 * @param int   $post_id Popup post ID.
		 * @param array $clean   Sanitized settings.
		 */
		do_action( 'acps_alerts_saved', $this->get_id(), $clean );

		return $clean;
	}

	/**
	 * Turns the alert on or off without touching its other settings.
	 *
	 * @param bool $enabled Whether the alert is live.
	 * @return void
	 */
	public function set_enabled( $enabled ) {
		update_post_meta( $this->get_id(), self::META_PREFIX . 'enabled', $enabled ? 1 : 0 );

		$this->settings = null;
	}

	/**
	 * Sanitizes a settings array against the schema.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function sanitize( array $input ) {
		$clean = array();

		foreach ( self::schema() as $key => $field ) {
			$value = array_key_exists( $key, $input ) ? $input[ $key ] : $field['default'];

			switch ( $field['type'] ) {
				case 'bool':
					$clean[ $key ] = empty( $value ) ? 0 : 1;
					break;

				case 'int':
					$value         = absint( $value );
					$clean[ $key ] = max( $field['min'], min( $field['max'], $value ) );
					break;

				case 'choice':
					$value         = sanitize_key( $value );
					$clean[ $key ] = in_array( $value, $field['choices'], true ) ? $value : $field['default'];
					break;

				case 'datetime':
					$clean[ $key ] = self::sanitize_datetime( $value );
					break;

				case 'roles':
					$roles         = is_array( $value ) ? $value : array();
					$valid         = array_keys( wp_roles()->get_names() );
					$clean[ $key ] = array_values( array_intersect( array_map( 'sanitize_key', $roles ), $valid ) );
					break;

				case 'slug_list':
					$slugs         = is_array( $value ) ? $value : array();
					$clean[ $key ] = array_values( array_filter( array_map( 'sanitize_key', $slugs ) ) );
					break;

				case 'id_list':
					if ( is_string( $value ) ) {
						$value = preg_split( '/[\s,]+/', $value );
					}

					$ids           = is_array( $value ) ? array_map( 'absint', $value ) : array();
					$clean[ $key ] = array_values( array_unique( array_filter( $ids ) ) );
					break;

				case 'patterns':
					$clean[ $key ] = self::sanitize_patterns( $value );
					break;

				case 'textarea':
					$clean[ $key ] = sanitize_textarea_field( (string) $value );
					break;

				case 'text':
				default:
					$clean[ $key ] = sanitize_text_field( (string) $value );
					break;
			}
		}

		return $clean;
	}

	/**
	 * Normalizes a datetime-local value to Y-m-d H:i, in site time.
	 *
	 * @param mixed $value Raw value.
	 * @return string Empty string when the value is not a usable date.
	 */
	public static function sanitize_datetime( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		$value     = str_replace( 'T', ' ', $value );
		$timestamp = strtotime( $value );

		if ( ! $timestamp ) {
			return '';
		}

		return gmdate( 'Y-m-d H:i', $timestamp );
	}

	/**
	 * Cleans a newline separated list of URL patterns.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_patterns( $value ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $value );
		$clean = array();

		foreach ( (array) $lines as $line ) {
			$line = trim( wp_strip_all_tags( $line ) );

			if ( '' !== $line ) {
				$clean[] = $line;
			}
		}

		return implode( "\n", array_unique( $clean ) );
	}

	/**
	 * Human readable summary of when the alert runs.
	 *
	 * @return string
	 */
	public function get_schedule_label() {
		$start = $this->get( 'start' );
		$end   = $this->get( 'end' );

		if ( '' === $start && '' === $end ) {
			return __( 'Always', 'acps-alert-popups' );
		}

		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		if ( '' !== $start && '' !== $end ) {
			return sprintf(
				/* translators: 1: start date, 2: end date. */
				__( '%1$s &rarr; %2$s', 'acps-alert-popups' ),
				date_i18n( $format, strtotime( $start ) ),
				date_i18n( $format, strtotime( $end ) )
			);
		}

		if ( '' !== $start ) {
			/* translators: %s: start date. */
			return sprintf( __( 'From %s', 'acps-alert-popups' ), date_i18n( $format, strtotime( $start ) ) );
		}

		/* translators: %s: end date. */
		return sprintf( __( 'Until %s', 'acps-alert-popups' ), date_i18n( $format, strtotime( $end ) ) );
	}
}
