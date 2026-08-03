<?php
/**
 * Registry and evaluation of built-in dynamic rule types.
 *
 * @package TextTokens
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TT_Rules
 *
 * Defines the fixed set of dynamic calculation rules an admin can pick from,
 * describes the per-rule configuration fields, and evaluates a rule to a value.
 */
class TT_Rules {

	/**
	 * Return the definition of every built-in dynamic rule.
	 *
	 * Each rule declares:
	 *  - label:  human readable name shown in the Type dropdown.
	 *  - desc:   short helper description.
	 *  - fields: associative array of config field slug => field definition.
	 *            Field definition keys: label, type (text|select|date), default,
	 *            options (for select), help.
	 *
	 * @return array
	 */
	public static function definitions() {
		$definitions = array(
			'current_year'  => array(
				'label'  => __( 'Current Year', 'text-tokens' ),
				'desc'   => __( 'The current four-digit year, e.g. 2026.', 'text-tokens' ),
				'fields' => array(),
			),
			'copyright_year' => array(
				'label'  => __( 'Copyright Year', 'text-tokens' ),
				'desc'   => __( 'Current year, or a start–current range for footers, e.g. 2010–2026.', 'text-tokens' ),
				'fields' => array(
					'start_year' => array(
						'label'   => __( 'Start year (optional)', 'text-tokens' ),
						'type'    => 'text',
						'default' => '',
						'help'    => __( 'Leave blank to show only the current year. Set to produce a range like 2010–2026.', 'text-tokens' ),
					),
				),
			),
			'school_year'   => array(
				'label'  => __( 'School Year', 'text-tokens' ),
				'desc'   => __( 'A two-year span such as 2026-2027 that rolls over on a chosen date.', 'text-tokens' ),
				'fields' => array(
					'rollover_month' => array(
						'label'   => __( 'Rollover month', 'text-tokens' ),
						'type'    => 'select',
						'default' => '6',
						'options' => self::month_options(),
					),
					'rollover_day'   => array(
						'label'   => __( 'Rollover day', 'text-tokens' ),
						'type'    => 'select',
						'default' => '1',
						'options' => self::day_options(),
					),
					'separator'      => array(
						'label'   => __( 'Separator', 'text-tokens' ),
						'type'    => 'text',
						'default' => '-',
						'help'    => __( 'Character(s) placed between the two years, e.g. "-" for 2026-2027.', 'text-tokens' ),
					),
				),
			),
			'current_date'  => array(
				'label'  => __( 'Current Date', 'text-tokens' ),
				'desc'   => __( "Today's date, formatted as you choose.", 'text-tokens' ),
				'fields' => array(
					'date_format' => array(
						'label'   => __( 'Date format', 'text-tokens' ),
						'type'    => 'select',
						'default' => 'F j, Y',
						'options' => self::date_format_options(),
						'help'    => __( 'Uses standard PHP date formats.', 'text-tokens' ),
					),
				),
			),
			'day_of_week'   => array(
				'label'  => __( 'Current Day of Week', 'text-tokens' ),
				'desc'   => __( 'The current weekday name, e.g. Monday.', 'text-tokens' ),
				'fields' => array(
					'abbreviated' => array(
						'label'   => __( 'Length', 'text-tokens' ),
						'type'    => 'select',
						'default' => 'full',
						'options' => array(
							'full'  => __( 'Full (Monday)', 'text-tokens' ),
							'short' => __( 'Abbreviated (Mon)', 'text-tokens' ),
						),
					),
				),
			),
			'days_until'    => array(
				'label'  => __( 'Days Until Date', 'text-tokens' ),
				'desc'   => __( 'A countdown of whole days until a target date.', 'text-tokens' ),
				'fields' => array(
					'target_date' => array(
						'label'   => __( 'Target date', 'text-tokens' ),
						'type'    => 'date',
						'default' => '',
						'help'    => __( 'The date to count down to (YYYY-MM-DD).', 'text-tokens' ),
					),
					'past_text'   => array(
						'label'   => __( 'Text when date has passed', 'text-tokens' ),
						'type'    => 'text',
						'default' => '0',
						'help'    => __( 'Shown once the target date is today or in the past.', 'text-tokens' ),
					),
				),
			),
			'semester'      => array(
				'label'  => __( 'Current Semester', 'text-tokens' ),
				'desc'   => __( 'Fall or Spring, based on rollover dates you set.', 'text-tokens' ),
				'fields' => array(
					'fall_month'   => array(
						'label'   => __( 'Fall starts — month', 'text-tokens' ),
						'type'    => 'select',
						'default' => '8',
						'options' => self::month_options(),
					),
					'fall_day'     => array(
						'label'   => __( 'Fall starts — day', 'text-tokens' ),
						'type'    => 'select',
						'default' => '1',
						'options' => self::day_options(),
					),
					'spring_month' => array(
						'label'   => __( 'Spring starts — month', 'text-tokens' ),
						'type'    => 'select',
						'default' => '1',
						'options' => self::month_options(),
					),
					'spring_day'   => array(
						'label'   => __( 'Spring starts — day', 'text-tokens' ),
						'type'    => 'select',
						'default' => '1',
						'options' => self::day_options(),
					),
					'fall_label'   => array(
						'label'   => __( 'Fall label', 'text-tokens' ),
						'type'    => 'text',
						'default' => 'Fall',
					),
					'spring_label' => array(
						'label'   => __( 'Spring label', 'text-tokens' ),
						'type'    => 'text',
						'default' => 'Spring',
					),
				),
			),
		);

		/**
		 * Filter the set of available dynamic rule definitions.
		 *
		 * @param array $definitions Rule definitions keyed by rule slug.
		 */
		return apply_filters( 'tt_rule_definitions', $definitions );
	}

	/**
	 * Whether a given rule slug is known.
	 *
	 * @param string $rule Rule slug.
	 * @return bool
	 */
	public static function exists( $rule ) {
		$definitions = self::definitions();
		return isset( $definitions[ $rule ] );
	}

	/**
	 * Evaluate a dynamic rule to its resolved string value.
	 *
	 * @param string $rule   Rule slug.
	 * @param array  $config Per-rule configuration values.
	 * @param int    $now    Optional timestamp to evaluate against (defaults to current time).
	 * @return string Resolved value; empty string for unknown rules.
	 */
	public static function evaluate( $rule, $config = array(), $now = null ) {
		if ( null === $now ) {
			$now = current_time( 'timestamp' ); // Respects site timezone.
		}

		$config = is_array( $config ) ? $config : array();

		switch ( $rule ) {
			case 'current_year':
				return wp_date( 'Y', $now );

			case 'copyright_year':
				$current = (int) wp_date( 'Y', $now );
				$start   = isset( $config['start_year'] ) ? (int) $config['start_year'] : 0;
				if ( $start > 0 && $start < $current ) {
					return $start . '–' . $current;
				}
				return (string) $current;

			case 'school_year':
				return self::eval_school_year( $config, $now );

			case 'current_date':
				$format = ! empty( $config['date_format'] ) ? $config['date_format'] : 'F j, Y';
				return wp_date( $format, $now );

			case 'day_of_week':
				$fmt = ( isset( $config['abbreviated'] ) && 'short' === $config['abbreviated'] ) ? 'D' : 'l';
				return wp_date( $fmt, $now );

			case 'days_until':
				return self::eval_days_until( $config, $now );

			case 'semester':
				return self::eval_semester( $config, $now );
		}

		return '';
	}

	/**
	 * Evaluate the "School Year" rule.
	 *
	 * @param array $config Config values.
	 * @param int   $now    Timestamp.
	 * @return string
	 */
	private static function eval_school_year( $config, $now ) {
		$month = isset( $config['rollover_month'] ) ? max( 1, min( 12, (int) $config['rollover_month'] ) ) : 6;
		$day   = isset( $config['rollover_day'] ) ? max( 1, min( 31, (int) $config['rollover_day'] ) ) : 1;
		$sep   = isset( $config['separator'] ) && '' !== $config['separator'] ? $config['separator'] : '-';

		$year        = (int) wp_date( 'Y', $now );
		$this_month  = (int) wp_date( 'n', $now );
		$this_day    = (int) wp_date( 'j', $now );

		// Before the rollover date, we are still in the year that began last calendar year.
		$before_rollover = ( $this_month < $month ) || ( $this_month === $month && $this_day < $day );

		$start_year = $before_rollover ? $year - 1 : $year;

		return $start_year . $sep . ( $start_year + 1 );
	}

	/**
	 * Evaluate the "Days Until Date" rule.
	 *
	 * @param array $config Config values.
	 * @param int   $now    Timestamp.
	 * @return string
	 */
	private static function eval_days_until( $config, $now ) {
		$target = isset( $config['target_date'] ) ? trim( (string) $config['target_date'] ) : '';
		if ( '' === $target ) {
			return '';
		}

		$tz         = wp_timezone();
		$target_dt  = date_create( $target . ' 00:00:00', $tz );
		if ( false === $target_dt ) {
			return '';
		}

		// Compare at day granularity using the site timezone.
		$today = date_create( wp_date( 'Y-m-d', $now ) . ' 00:00:00', $tz );
		$diff  = (int) $today->diff( $target_dt )->format( '%r%a' );

		if ( $diff <= 0 ) {
			return isset( $config['past_text'] ) ? (string) $config['past_text'] : '0';
		}

		return (string) $diff;
	}

	/**
	 * Evaluate the "Current Semester" rule.
	 *
	 * @param array $config Config values.
	 * @param int   $now    Timestamp.
	 * @return string
	 */
	private static function eval_semester( $config, $now ) {
		$fall_month   = isset( $config['fall_month'] ) ? (int) $config['fall_month'] : 8;
		$fall_day     = isset( $config['fall_day'] ) ? (int) $config['fall_day'] : 1;
		$spring_month = isset( $config['spring_month'] ) ? (int) $config['spring_month'] : 1;
		$spring_day   = isset( $config['spring_day'] ) ? (int) $config['spring_day'] : 1;
		$fall_label   = isset( $config['fall_label'] ) && '' !== $config['fall_label'] ? $config['fall_label'] : 'Fall';
		$spring_label = isset( $config['spring_label'] ) && '' !== $config['spring_label'] ? $config['spring_label'] : 'Spring';

		$this_month = (int) wp_date( 'n', $now );
		$this_day   = (int) wp_date( 'j', $now );

		$md      = $this_month * 100 + $this_day;
		$fall_md = $fall_month * 100 + $fall_day;
		$spr_md  = $spring_month * 100 + $spring_day;

		// Fall runs from its start until spring's start; spring runs from its start until fall's start.
		if ( $md >= $fall_md || $md < $spr_md ) {
			return $fall_label;
		}
		return $spring_label;
	}

	/**
	 * Month number => name options.
	 *
	 * @return array
	 */
	public static function month_options() {
		$months = array();
		for ( $m = 1; $m <= 12; $m++ ) {
			$months[ (string) $m ] = wp_date( 'F', mktime( 0, 0, 0, $m, 1, 2000 ) );
		}
		return $months;
	}

	/**
	 * Day-of-month 1..31 options.
	 *
	 * @return array
	 */
	public static function day_options() {
		$days = array();
		for ( $d = 1; $d <= 31; $d++ ) {
			$days[ (string) $d ] = (string) $d;
		}
		return $days;
	}

	/**
	 * Common date format presets keyed by format string, valued by an example.
	 *
	 * @return array
	 */
	public static function date_format_options() {
		$now     = current_time( 'timestamp' );
		$formats = array( 'F j, Y', 'm/d/Y', 'j F Y', 'D, M j, Y', 'Y-m-d', 'l, F j, Y' );
		$options = array();
		foreach ( $formats as $format ) {
			$options[ $format ] = wp_date( $format, $now );
		}
		return $options;
	}
}
