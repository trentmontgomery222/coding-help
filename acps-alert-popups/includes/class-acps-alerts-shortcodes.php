<?php
/**
 * The [schoolstatus] shortcode.
 *
 * Puts the current school status wherever it is typed — in the popup, in a
 * header, in a sidebar, in a post. It reads the same Current Alert the status
 * board reads, so there is one answer to "what is the status" and every place
 * that shows it is showing the same thing.
 *
 * Everything it draws styles itself inline, because a shortcode can be typed
 * into a page that loads none of this plugin's stylesheets — including, for the
 * popup, a page that is not even the one the popup was built on.
 *
 * @package ACPS_Alert_Popups
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the status shortcodes.
 */
class ACPS_Alerts_Shortcodes {

	/**
	 * Hooks the shortcodes up.
	 *
	 * @return void
	 */
	public function init() {
		// The second name is an alias, so whichever way somebody remembers it
		// they get the status rather than the shortcode printed as text.
		foreach ( array( 'schoolstatus', 'school_status' ) as $tag ) {
			add_shortcode( $tag, ACPS_Alerts_Failsafe::wrap( array( $this, 'render' ), 'shortcode/status' ) );
		}
	}

	/**
	 * What the status is right now.
	 *
	 * The Current Alert while it is showing; the resting state otherwise. This
	 * is the same question the status board asks, deliberately — two places
	 * reporting different statuses is worse than either being wrong.
	 *
	 * @return array { level, banner, directive, color, headline, message, live }
	 */
	public static function current() {
		if ( ! class_exists( 'ACPS_Alerts_Status' ) ) {
			return array();
		}

		$alert = ACPS_Alerts_Status::board_entry();
		$key   = $alert ? (string) $alert->get( 'status_level' ) : 'normal';
		$level = ACPS_Alerts_Status::level( $key );

		$headline = '';
		$message  = '';

		if ( $alert ) {
			$headline = (string) $alert->get_title();
			$message  = (string) $alert->get( 'status_message' );
		} else {
			// The resting state. Its wording lives on the Normal Alert, so a
			// site can change what "normal" says without touching code.
			$normal = ACPS_Alerts_Status::normal_alert();

			if ( $normal ) {
				$headline = (string) $normal->get_title();
				$message  = (string) $normal->get( 'status_message' );
			}
		}

		return array(
			'level'     => $key,
			'banner'    => (string) $level['banner'],
			'directive' => wp_strip_all_tags( (string) $level['directive'] ),
			'color'     => (string) $level['color'],
			'headline'  => $headline,
			'message'   => $message,
			'live'      => (bool) $alert,
		);
	}

	/**
	 * Renders the shortcode.
	 *
	 * Public because the failsafe calls it from outside the class.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render( $atts ) {
		$atts = shortcode_atts(
			array(
				// Which parts to draw, in the order they are listed. "icon",
				// "level", "directive", "headline", "message", or "all".
				'show'    => 'icon level',

				// Badge size in pixels.
				'size'    => 56,

				// "row" puts the parts side by side, "stack" one above another.
				'layout'  => 'stack',

				// left, center or right.
				'align'   => 'center',

				// Draw nothing at all when nothing is happening. Useful for a
				// header that should stay empty on a normal day.
				'when'    => 'always',

				// Wrap the whole thing in a link to the status page.
				'link'    => 'no',
			),
			(array) $atts,
			'schoolstatus'
		);

		$status = self::current();

		if ( empty( $status ) ) {
			return '';
		}

		if ( 'live' === $atts['when'] && ! $status['live'] ) {
			return '';
		}

		$parts = self::parts( $atts['show'] );

		if ( empty( $parts ) ) {
			return '';
		}

		$pieces = array();

		foreach ( $parts as $part ) {
			$piece = self::piece( $part, $status, $atts );

			if ( '' !== $piece ) {
				$pieces[] = $piece;
			}
		}

		if ( empty( $pieces ) ) {
			return '';
		}

		$align = in_array( $atts['align'], array( 'left', 'center', 'right' ), true ) ? $atts['align'] : 'center';
		$row   = 'row' === $atts['layout'];

		$style = sprintf(
			'display:flex;flex-direction:%1$s;align-items:%2$s;gap:10px;text-align:%3$s;%4$s',
			$row ? 'row' : 'column',
			'left' === $align ? 'flex-start' : ( 'right' === $align ? 'flex-end' : 'center' ),
			$align,
			$row ? 'flex-wrap:wrap;justify-content:' . ( 'left' === $align ? 'flex-start' : ( 'right' === $align ? 'flex-end' : 'center' ) ) . ';' : ''
		);

		$html = sprintf(
			'<div class="acps-status acps-status--%1$s%2$s" style="%3$s">%4$s</div>',
			esc_attr( sanitize_html_class( $status['level'] ) ),
			$status['live'] ? ' acps-status--live' : ' acps-status--normal',
			esc_attr( $style ),
			implode( '', $pieces )
		);

		return self::maybe_link( $html, $atts['link'] );
	}

	/**
	 * The parts asked for, in the order they were asked for.
	 *
	 * @param string $show The show attribute.
	 * @return string[]
	 */
	protected static function parts( $show ) {
		$known = array( 'icon', 'level', 'directive', 'headline', 'message' );
		$show  = strtolower( trim( (string) $show ) );

		if ( 'all' === $show ) {
			return $known;
		}

		// Commas or spaces, because people will type both.
		$asked = preg_split( '/[\s,]+/', $show );
		$parts = array();

		foreach ( (array) $asked as $part ) {
			$part = sanitize_key( $part );

			if ( in_array( $part, $known, true ) && ! in_array( $part, $parts, true ) ) {
				$parts[] = $part;
			}
		}

		return $parts;
	}

	/**
	 * Renders one part.
	 *
	 * @param string $part   Part name.
	 * @param array  $status Current status.
	 * @param array  $atts   Shortcode attributes.
	 * @return string
	 */
	protected static function piece( $part, array $status, array $atts ) {
		$color = preg_match( '/^#[0-9a-f]{3,8}$/i', $status['color'] ) ? $status['color'] : '#1b2f5e';

		switch ( $part ) {
			case 'icon':
				return class_exists( 'ACPS_Alerts_Status' )
					? ACPS_Alerts_Status::level_icon( $status['level'], absint( $atts['size'] ) )
					: '';

			case 'level':
				if ( '' === $status['banner'] ) {
					return '';
				}

				return sprintf(
					'<span class="acps-status__level" style="color:%1$s;font-weight:800;letter-spacing:.1em;text-transform:uppercase;line-height:1.2">%2$s</span>',
					esc_attr( $color ),
					esc_html( $status['banner'] )
				);

			case 'directive':
				if ( '' === $status['directive'] ) {
					return '';
				}

				return sprintf(
					'<span class="acps-status__directive" style="font-weight:700;letter-spacing:.03em;text-transform:uppercase;line-height:1.3">%s</span>',
					esc_html( $status['directive'] )
				);

			case 'headline':
				if ( '' === trim( $status['headline'] ) ) {
					return '';
				}

				return sprintf(
					'<span class="acps-status__headline" style="font-weight:700;line-height:1.3">%s</span>',
					esc_html( $status['headline'] )
				);

			case 'message':
				if ( '' === trim( $status['message'] ) ) {
					return '';
				}

				return sprintf(
					'<span class="acps-status__message" style="line-height:1.5">%s</span>',
					esc_html( $status['message'] )
				);
		}

		return '';
	}

	/**
	 * Wraps the status in a link to the status page, when asked.
	 *
	 * @param string $html Rendered status.
	 * @param string $link The link attribute.
	 * @return string
	 */
	protected static function maybe_link( $html, $link ) {
		$wanted = in_array( strtolower( (string) $link ), array( 'yes', '1', 'true', 'on' ), true );

		if ( ! $wanted || ! class_exists( 'ACPS_Alerts_Status' ) ) {
			return $html;
		}

		$page = ACPS_Alerts_Status::board_page();

		if ( ! $page ) {
			return $html;
		}

		$url = (string) get_permalink( $page );

		if ( '' === $url ) {
			return $html;
		}

		return sprintf(
			'<a class="acps-status__link" href="%s" style="text-decoration:none;color:inherit;display:block">%s</a>',
			esc_url( $url ),
			$html
		);
	}
}
