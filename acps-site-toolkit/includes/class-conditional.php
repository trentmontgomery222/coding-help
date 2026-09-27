<?php
/**
 * Conditional shortcode — [acps_if condition="…"]…[/acps_if].
 *
 * Lets a Beaver Builder module (or any content) show/hide based on the plugin's
 * state — most usefully, show a fallback message when the plugin is disabled /
 * crashed into safe mode. It is registered VERY early and even while the plugin
 * is dormant in safe mode, so the "disabled" case actually renders instead of
 * leaving a raw shortcode on the page.
 *
 * Conditions (case-insensitive):
 *   enabled | ok | active | running   → plugin running normally
 *   disabled | off | safe_mode | crashed → plugin dormant in safe mode
 *   gf | gf_active | gravity          → Gravity Forms is active
 *   no_gf | gf_inactive               → Gravity Forms is not active
 * Prefix any condition with "!" to negate it, e.g. condition="!disabled".
 *
 * @package ACPS\SiteToolkit
 */

namespace ACPS\SiteToolkit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Conditional.
 */
class Conditional {

	/**
	 * Register the shortcode. Safe to call in normal boot and in safe mode.
	 */
	public static function register() {
		if ( function_exists( 'add_shortcode' ) ) {
			add_shortcode( 'acps_if', array( __CLASS__, 'shortcode' ) );
		}
	}

	/**
	 * [acps_if condition="disabled"]…[/acps_if].
	 *
	 * @param array  $atts    Attributes.
	 * @param string $content Enclosed content.
	 * @return string
	 */
	public static function shortcode( $atts, $content = '' ) {
		$atts = shortcode_atts( array( 'condition' => 'enabled' ), $atts, 'acps_if' );
		try {
			$show = self::evaluate( (string) $atts['condition'] );
		} catch ( \Throwable $e ) {
			$show = false;
		}
		return $show ? do_shortcode( (string) $content ) : '';
	}

	/**
	 * Evaluate a condition string (supports a leading "!" to negate).
	 *
	 * @param string $condition Condition.
	 * @return bool
	 */
	private static function evaluate( $condition ) {
		$condition = strtolower( trim( $condition ) );
		$negate    = false;
		if ( '' !== $condition && '!' === $condition[0] ) {
			$negate    = true;
			$condition = trim( substr( $condition, 1 ) );
		}

		$safe = ( function_exists( __NAMESPACE__ . '\\is_safe_mode' ) && is_safe_mode() );
		$gf   = ( class_exists( __NAMESPACE__ . '\\Gravity_Forms' ) && Gravity_Forms::is_active() );

		switch ( $condition ) {
			case 'disabled':
			case 'off':
			case 'safe_mode':
			case 'safemode':
			case 'crashed':
			case 'broken':
				$result = $safe;
				break;
			case 'gf':
			case 'gf_active':
			case 'gravity':
			case 'gravityforms':
				$result = $gf;
				break;
			case 'no_gf':
			case 'gf_inactive':
			case 'nogravity':
				$result = ! $gf;
				break;
			case 'enabled':
			case 'ok':
			case 'active':
			case 'running':
			default:
				$result = ! $safe;
				break;
		}

		return $negate ? ! $result : $result;
	}
}
