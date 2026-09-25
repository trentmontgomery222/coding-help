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

		// A single coloured dot for a status level, to place inline in text.
		// Several can sit on one line, so a sentence can carry three statuses
		// at once.
		foreach ( array( 'statusdot', 'status_dot' ) as $tag ) {
			add_shortcode( $tag, ACPS_Alerts_Failsafe::wrap( array( $this, 'render_dot' ), 'shortcode/dot' ) );
		}

		// A plain value for Beaver Builder's (or any) conditional-logic display
		// rules: prints "1" when the plugin is running, and — with state="live"
		// / state="normal" — when an alert is or is not showing. When the plugin
		// is switched off or paused this shortcode is not registered, so it
		// yields nothing, which is exactly the "disabled" signal.
		foreach ( array( 'acps_active', 'acps_enabled' ) as $tag ) {
			add_shortcode( $tag, ACPS_Alerts_Failsafe::wrap( array( $this, 'render_active' ), 'shortcode/active' ) );
		}

		// An enclosing shortcode that shows its content only in the chosen
		// state, for sites without a conditional-logic add-on: wrap the content
		// in [acps_if when="live"] … [/acps_if].
		add_shortcode( 'acps_if', ACPS_Alerts_Failsafe::wrap( array( $this, 'render_if' ), 'shortcode/if' ) );
	}

	/**
	 * Whether an alert is showing right now.
	 *
	 * @return bool
	 */
	protected static function alert_is_live() {
		return class_exists( 'ACPS_Alerts_Status' ) && (bool) ACPS_Alerts_Status::board_entry();
	}

	/**
	 * Resolves a state word to one of: active | live | normal.
	 *
	 * "active" is "the plugin is running", which is always true when any of
	 * these shortcodes execute at all — that is the point, since a switched-off
	 * plugin runs none of them. "live" and "normal" ask whether an alert is
	 * showing. Deliberately no "enabled"/"disabled" aliases: those read as "the
	 * plugin", which is the default (bare) state, not the alert.
	 *
	 * @param string $word Raw state word.
	 * @return string
	 */
	protected static function resolve_state( $word ) {
		$word = strtolower( trim( (string) $word ) );

		$map = array(
			''        => 'active',
			'active'  => 'active',
			'running' => 'active',
			'on'      => 'active',
			'live'    => 'live',
			'alert'   => 'live',
			'showing' => 'live',
			'normal'  => 'normal',
			'resting' => 'normal',
			'quiet'   => 'normal',
		);

		return isset( $map[ $word ] ) ? $map[ $word ] : 'active';
	}

	/**
	 * Whether the current state matches the requested one.
	 *
	 * @param string $word Requested state word.
	 * @return bool
	 */
	protected static function state_matches( $word ) {
		switch ( self::resolve_state( $word ) ) {
			case 'live':
				return self::alert_is_live();
			case 'normal':
				return ! self::alert_is_live();
			default: // active
				return true;
		}
	}

	/**
	 * The value shortcode: prints "1" (or a chosen word) when the state matches,
	 * nothing otherwise. For conditional-logic display rules that compare a
	 * shortcode's result.
	 *
	 * Public because the failsafe calls it from outside the class.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_active( $atts ) {
		$atts = shortcode_atts(
			array(
				'state' => '',   // active (default) | live | normal.
				'yes'   => '1',  // printed when the state matches.
				'no'    => '',   // printed when it does not.
			),
			(array) $atts,
			'acps_active'
		);

		return self::state_matches( $atts['state'] ) ? (string) $atts['yes'] : (string) $atts['no'];
	}

	/**
	 * The enclosing shortcode: shows its content only when the state matches.
	 *
	 * Public because the failsafe calls it from outside the class.
	 *
	 * @param array       $atts    Shortcode attributes.
	 * @param string|null $content Enclosed content.
	 * @return string
	 */
	public function render_if( $atts, $content = null ) {
		$atts = shortcode_atts(
			array(
				'when' => 'active', // active | live | normal.
			),
			(array) $atts,
			'acps_if'
		);

		if ( null === $content || '' === $content || ! self::state_matches( $atts['when'] ) ) {
			return '';
		}

		// Expand any shortcodes nested inside, the way WordPress does for other
		// enclosing shortcodes.
		return function_exists( 'do_shortcode' ) ? do_shortcode( $content ) : $content;
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
	public static function current( $source = 'board' ) {
		if ( ! class_exists( 'ACPS_Alerts_Status' ) ) {
			return array();
		}

		/*
		 * Two different questions, and the popup needs the second one.
		 *
		 * "board" is what the status page says: the Current Alert while it is
		 * showing, the resting state otherwise.
		 *
		 * "alert" is what the Current Alert itself says, whether or not it is
		 * showing. Inside the popup that is the right answer — the popup IS
		 * that alert, so it should keep the alert's own SRP badge rather than
		 * flipping to Normal the moment the event is filed and the board goes
		 * back to resting.
		 */
		$alert = 'alert' === $source
			? ACPS_Alerts_Status::current_alert()
			: ACPS_Alerts_Status::board_entry();

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
			'color'     => ACPS_Alerts_Status::level_color( $key, 'shortcode' ),
			'headline'  => $headline,
			'message'   => $message,

			// "Is something happening", which is what when="live" asks. Read
			// from the board however the wording was sourced, because an alert
			// that exists but is switched off is not something happening.
			'live'      => (bool) ACPS_Alerts_Status::board_entry(),
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

				// "board" is what the status page says; "alert" is what the
				// Current Alert says whether or not it is showing.
				'source'  => 'board',

				// Override the level's colour for this one placement. Useful
				// on a dark background, where an SRP colour chosen to read on
				// white can be nearly invisible.
				'color'   => '',
			),
			(array) $atts,
			'schoolstatus'
		);

		$source = 'alert' === strtolower( trim( (string) $atts['source'] ) ) ? 'alert' : 'board';
		$status = self::current( $source );

		if ( empty( $status ) ) {
			return '';
		}

		$chosen = ACPS_Alerts_Status::colour( $atts['color'] );

		if ( '' !== $chosen ) {
			$status['color'] = $chosen;
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
		$color = ACPS_Alerts_Status::colour( $status['color'] );
		$color = '' !== $color ? $color : '#1b2f5e';

		switch ( $part ) {
			case 'icon':
				return class_exists( 'ACPS_Alerts_Status' )
					? ACPS_Alerts_Status::level_icon( $status['level'], absint( $atts['size'] ), $color )
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
	 * Renders a single coloured status dot, optionally with a label.
	 *
	 * The colour comes from the status level (Hold purple, Lockdown red, and so
	 * on), or from a colour you give it. Put several on one line to show more
	 * than one status at once:
	 *
	 *   [statusdot level="lockdown" label="West Side"]
	 *   [statusdot level="hold" label="Eckhart"]
	 *
	 * Public because the failsafe calls it from outside the class.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_dot( $atts ) {
		if ( ! class_exists( 'ACPS_Alerts_Status' ) ) {
			return '';
		}

		$atts = shortcode_atts(
			array(
				// Which level's colour to use.
				'level' => 'normal',

				// Text to show beside the dot. Optional.
				'label' => '',

				// Dot diameter in pixels.
				'size'  => 12,

				// Override the colour for this one dot.
				'color' => '',

				// Draw the level's word as the label when none is given.
				'word'  => 'no',
			),
			(array) $atts,
			'statusdot'
		);

		$word = in_array( strtolower( (string) $atts['word'] ), array( 'yes', '1', 'true', 'on' ), true );

		return self::dot_markup( $atts['level'], $atts['label'], absint( $atts['size'] ), $atts['color'], $word );
	}

	/**
	 * Builds the markup for one status dot.
	 *
	 * The single source of truth for a dot, shared by the [statusdot] shortcode
	 * and the Status Dot Beaver Builder module, so both look identical. Styles
	 * itself inline so it works on any page.
	 *
	 * @param string $level_key      Status level key.
	 * @param string $label          Text beside the dot; empty for none.
	 * @param int    $size           Diameter in pixels.
	 * @param string $color_override Colour to use instead of the level's.
	 * @param bool   $word           Use the level word as the label when none given.
	 * @return string
	 */
	public static function dot_markup( $level_key, $label = '', $size = 12, $color_override = '', $word = false ) {
		if ( ! class_exists( 'ACPS_Alerts_Status' ) ) {
			return '';
		}

		$key   = sanitize_key( (string) $level_key );
		$level = ACPS_Alerts_Status::level( $key );

		$color = ACPS_Alerts_Status::colour( (string) $color_override );

		if ( '' === $color ) {
			$color = ACPS_Alerts_Status::colour( isset( $level['color'] ) ? $level['color'] : '' );
		}

		if ( '' === $color ) {
			$color = '#1b2f5e';
		}

		$size  = max( 6, min( 48, absint( $size ) ) );
		$label = (string) $label;

		if ( '' === trim( $label ) && $word ) {
			$label = isset( $level['banner'] ) ? (string) $level['banner'] : '';
		}

		$dot = sprintf(
			'<span class="acps-dot" style="display:inline-block;width:%1$dpx;height:%1$dpx;border-radius:50%%;background:%2$s;vertical-align:middle;flex:0 0 auto" aria-hidden="true"></span>',
			$size,
			esc_attr( $color )
		);

		// A screen reader gets the level word even when the dot is decorative.
		$sr = isset( $level['banner'] ) ? (string) $level['banner'] : '';

		if ( '' === trim( $label ) ) {
			return sprintf(
				'<span class="acps-dot-wrap" style="display:inline-flex;align-items:center;gap:6px">%1$s<span class="screen-reader-text">%2$s</span></span>',
				$dot,
				esc_html( $sr )
			);
		}

		return sprintf(
			'<span class="acps-dot-wrap" style="display:inline-flex;align-items:center;gap:6px;line-height:1.3">%1$s<span class="acps-dot-label">%2$s</span></span>',
			$dot,
			esc_html( $label )
		);
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
