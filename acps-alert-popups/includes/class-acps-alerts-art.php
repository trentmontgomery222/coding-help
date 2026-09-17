<?php
/**
 * Inline illustrations for the help system.
 *
 * Diagrams rather than screenshots, on purpose: a screenshot of one site's
 * admin goes stale the moment WordPress, the theme or this plugin changes, and
 * cannot be translated or read by a screen reader. These are inline SVG, so
 * they scale, print, respond to the admin colour scheme, and carry their own
 * text alternatives.
 *
 * Every method returns a string and prints nothing, so callers stay in control
 * of escaping and placement. The SVG is authored here and is not user input.
 *
 * @package ACPS_Alert_Popups
 */

defined( 'ABSPATH' ) || exit;

/**
 * Illustrations.
 */
class ACPS_Alerts_Art {

	/**
	 * Wraps a figure with its caption and accessible description.
	 *
	 * @param string $svg     SVG markup.
	 * @param string $title   Accessible title.
	 * @param string $caption Visible caption.
	 * @return string
	 */
	protected static function figure( $svg, $title, $caption = '' ) {
		$html = '<figure class="acps-figure">' . $svg;

		if ( '' !== $caption ) {
			$html .= '<figcaption>' . esc_html( $caption ) . '</figcaption>';
		}

		return $html . '</figure>';
	}

	/**
	 * The big picture: where each part of the job happens.
	 *
	 * @return string
	 */
	public static function flow() {
		$svg = '
<svg viewBox="0 0 760 210" class="acps-art" role="img" aria-labelledby="acps-art-flow-t acps-art-flow-d">
	<title id="acps-art-flow-t">' . esc_html__( 'How the two halves fit together', 'acps-alert-popups' ) . '</title>
	<desc id="acps-art-flow-d">' . esc_html__( 'Beaver Builder designs what the alert looks like. WordPress admin decides when, where and who sees it. The visitor sees the result.', 'acps-alert-popups' ) . '</desc>

	<g class="acps-art-box">
		<rect x="8" y="34" width="210" height="130" rx="10"/>
		<text x="113" y="66" class="acps-art-step">' . esc_html__( 'STEP 1', 'acps-alert-popups' ) . '</text>
		<text x="113" y="94" class="acps-art-h">' . esc_html__( 'Beaver Builder', 'acps-alert-popups' ) . '</text>
		<text x="113" y="119" class="acps-art-p">' . esc_html__( 'Design the popup:', 'acps-alert-popups' ) . '</text>
		<text x="113" y="138" class="acps-art-p">' . esc_html__( 'words, colours, buttons', 'acps-alert-popups' ) . '</text>
	</g>

	<g class="acps-art-arrow">
		<path d="M226 99 L266 99" marker-end="url(#acps-arrow)"/>
	</g>

	<g class="acps-art-box acps-art-box--accent">
		<rect x="275" y="34" width="210" height="130" rx="10"/>
		<text x="380" y="66" class="acps-art-step">' . esc_html__( 'STEP 2', 'acps-alert-popups' ) . '</text>
		<text x="380" y="94" class="acps-art-h">' . esc_html__( 'Site Alerts', 'acps-alert-popups' ) . '</text>
		<text x="380" y="119" class="acps-art-p">' . esc_html__( 'Decide when, where', 'acps-alert-popups' ) . '</text>
		<text x="380" y="138" class="acps-art-p">' . esc_html__( 'and who sees it', 'acps-alert-popups' ) . '</text>
	</g>

	<g class="acps-art-arrow">
		<path d="M493 99 L533 99" marker-end="url(#acps-arrow)"/>
	</g>

	<g class="acps-art-box">
		<rect x="542" y="34" width="210" height="130" rx="10"/>
		<text x="647" y="66" class="acps-art-step">' . esc_html__( 'RESULT', 'acps-alert-popups' ) . '</text>
		<text x="647" y="94" class="acps-art-h">' . esc_html__( 'Your visitor', 'acps-alert-popups' ) . '</text>
		<text x="647" y="119" class="acps-art-p">' . esc_html__( 'Sees the alert at', 'acps-alert-popups' ) . '</text>
		<text x="647" y="138" class="acps-art-p">' . esc_html__( 'the right moment', 'acps-alert-popups' ) . '</text>
	</g>

	' . self::marker() . '
</svg>';

		return self::figure(
			$svg,
			__( 'How the two halves fit together', 'acps-alert-popups' ),
			__( 'You only ever do two jobs: design it in Beaver Builder, then switch it on and aim it here.', 'acps-alert-popups' )
		);
	}

	/**
	 * The parts of an alert on screen.
	 *
	 * @return string
	 */
	public static function anatomy() {
		$svg = '
<svg viewBox="0 0 760 330" class="acps-art" role="img" aria-labelledby="acps-art-an-t acps-art-an-d">
	<title id="acps-art-an-t">' . esc_html__( 'The parts of an alert', 'acps-alert-popups' ) . '</title>
	<desc id="acps-art-an-d">' . esc_html__( 'A dimmed page behind, a white panel in front holding your Beaver Builder content, a close button in its corner, and a coloured stripe along the top showing severity.', 'acps-alert-popups' ) . '</desc>

	<rect x="8" y="8" width="470" height="300" rx="8" class="acps-art-page"/>
	<text x="24" y="34" class="acps-art-faint">' . esc_html__( 'your web page', 'acps-alert-popups' ) . '</text>
	<rect x="24" y="48" width="180" height="8" rx="4" class="acps-art-faint-bar"/>
	<rect x="24" y="66" width="290" height="8" rx="4" class="acps-art-faint-bar"/>
	<rect x="24" y="252" width="240" height="8" rx="4" class="acps-art-faint-bar"/>
	<rect x="24" y="270" width="170" height="8" rx="4" class="acps-art-faint-bar"/>

	<rect x="8" y="8" width="470" height="300" rx="8" class="acps-art-scrim"/>

	<g>
		<rect x="88" y="92" width="310" height="150" rx="8" class="acps-art-dialog"/>
		<rect x="88" y="92" width="310" height="6" rx="3" class="acps-art-stripe"/>
		<circle cx="378" cy="116" r="12" class="acps-art-close"/>
		<path d="M373 111 L383 121 M383 111 L373 121" class="acps-art-close-x"/>
		<rect x="112" y="124" width="150" height="12" rx="6" class="acps-art-content"/>
		<rect x="112" y="150" width="240" height="8" rx="4" class="acps-art-content-soft"/>
		<rect x="112" y="166" width="205" height="8" rx="4" class="acps-art-content-soft"/>
		<rect x="112" y="194" width="104" height="28" rx="5" class="acps-art-button"/>
		<text x="164" y="213" class="acps-art-btn-label">' . esc_html__( 'Read more', 'acps-alert-popups' ) . '</text>
	</g>

	<g class="acps-art-call">
		<path d="M398 95 L520 66"/>
		<circle cx="520" cy="66" r="3"/>
		<text x="532" y="63" class="acps-art-label">' . esc_html__( 'Severity stripe', 'acps-alert-popups' ) . '</text>
		<text x="532" y="80" class="acps-art-note">' . esc_html__( 'colour set by you', 'acps-alert-popups' ) . '</text>
	</g>
	<g class="acps-art-call">
		<path d="M390 116 L520 116"/>
		<circle cx="520" cy="116" r="3"/>
		<text x="532" y="113" class="acps-art-label">' . esc_html__( 'Close button', 'acps-alert-popups' ) . '</text>
		<text x="532" y="130" class="acps-art-note">' . esc_html__( 'always leave one way out', 'acps-alert-popups' ) . '</text>
	</g>
	<g class="acps-art-call">
		<path d="M360 170 L520 170"/>
		<circle cx="520" cy="170" r="3"/>
		<text x="532" y="167" class="acps-art-label">' . esc_html__( 'Your Beaver Builder content', 'acps-alert-popups' ) . '</text>
		<text x="532" y="184" class="acps-art-note">' . esc_html__( 'this plugin never touches it', 'acps-alert-popups' ) . '</text>
	</g>
	<g class="acps-art-call">
		<path d="M200 262 L520 224"/>
		<circle cx="520" cy="224" r="3"/>
		<text x="532" y="221" class="acps-art-label">' . esc_html__( 'Dimmed page', 'acps-alert-popups' ) . '</text>
		<text x="532" y="238" class="acps-art-note">' . esc_html__( 'the overlay, optional', 'acps-alert-popups' ) . '</text>
	</g>
</svg>';

		return self::figure(
			$svg,
			__( 'The parts of an alert', 'acps-alert-popups' ),
			__( 'Everything inside the white panel comes from Beaver Builder. Everything around it is what you set here.', 'acps-alert-popups' )
		);
	}

	/**
	 * How the four "show it again" settings behave over time.
	 *
	 * @return string
	 */
	public static function frequency() {
		$rows = array(
			array(
				'label' => __( 'Every page view', 'acps-alert-popups' ),
				'note'  => __( 'Comes back on every single page. Use only for a real emergency.', 'acps-alert-popups' ),
				'shows' => array( true, true, true, true, true ),
			),
			array(
				'label' => __( 'Once per session', 'acps-alert-popups' ),
				'note'  => __( 'Shows once, then leaves them alone until they come back another day.', 'acps-alert-popups' ),
				'shows' => array( true, false, false, false, false ),
			),
			array(
				'label' => __( 'Once every X days', 'acps-alert-popups' ),
				'note'  => __( 'Shows again after the number of days you choose.', 'acps-alert-popups' ),
				'shows' => array( true, false, false, true, false ),
			),
			array(
				'label' => __( 'Once, then never', 'acps-alert-popups' ),
				'note'  => __( 'Once they close it, that is the last they see of it.', 'acps-alert-popups' ),
				'shows' => array( true, false, false, false, false ),
			),
		);

		$svg = '<svg viewBox="0 0 760 ' . ( 60 + count( $rows ) * 62 ) . '" class="acps-art" role="img" aria-labelledby="acps-art-fr-t acps-art-fr-d">
	<title id="acps-art-fr-t">' . esc_html__( 'How often the alert comes back', 'acps-alert-popups' ) . '</title>
	<desc id="acps-art-fr-d">' . esc_html__( 'A row for each setting, showing across five visits whether the alert appears or stays hidden.', 'acps-alert-popups' ) . '</desc>';

		$visits = array(
			__( 'visit 1', 'acps-alert-popups' ),
			__( 'visit 2', 'acps-alert-popups' ),
			__( 'visit 3', 'acps-alert-popups' ),
			__( 'next week', 'acps-alert-popups' ),
			__( 'later', 'acps-alert-popups' ),
		);

		foreach ( $visits as $i => $visit ) {
			$x = 300 + $i * 88;
			$svg .= '<text x="' . $x . '" y="30" class="acps-art-colhead">' . esc_html( $visit ) . '</text>';
		}

		foreach ( $rows as $r => $row ) {
			$y = 58 + $r * 62;

			$svg .= '<text x="8" y="' . ( $y + 4 ) . '" class="acps-art-rowhead">' . esc_html( $row['label'] ) . '</text>';
			$svg .= '<text x="8" y="' . ( $y + 24 ) . '" class="acps-art-note">' . esc_html( $row['note'] ) . '</text>';

			foreach ( $row['shows'] as $i => $shown ) {
				$x = 300 + $i * 88;

				if ( $shown ) {
					$svg .= '<rect x="' . ( $x - 26 ) . '" y="' . ( $y - 14 ) . '" width="52" height="26" rx="5" class="acps-art-on"/>';
					$svg .= '<text x="' . $x . '" y="' . ( $y + 4 ) . '" class="acps-art-on-label">' . esc_html__( 'shows', 'acps-alert-popups' ) . '</text>';
				} else {
					$svg .= '<rect x="' . ( $x - 26 ) . '" y="' . ( $y - 14 ) . '" width="52" height="26" rx="5" class="acps-art-off"/>';
					$svg .= '<text x="' . $x . '" y="' . ( $y + 4 ) . '" class="acps-art-off-label">' . esc_html__( 'quiet', 'acps-alert-popups' ) . '</text>';
				}
			}
		}

		$svg .= '</svg>';

		return self::figure(
			$svg,
			__( 'How often the alert comes back', 'acps-alert-popups' ),
			__( 'Counting starts when the visitor closes the alert. "Once per session" is the friendly default.', 'acps-alert-popups' )
		);
	}

	/**
	 * The five ways an alert can open.
	 *
	 * @return string
	 */
	public static function triggers() {
		$items = array(
			array( 'load', __( 'Page load', 'acps-alert-popups' ), __( 'Opens straight away', 'acps-alert-popups' ) ),
			array( 'delay', __( 'After a delay', 'acps-alert-popups' ), __( 'Waits a few seconds', 'acps-alert-popups' ) ),
			array( 'scroll', __( 'On scroll', 'acps-alert-popups' ), __( 'Waits until they read down', 'acps-alert-popups' ) ),
			array( 'exit', __( 'Exit intent', 'acps-alert-popups' ), __( 'When the mouse leaves', 'acps-alert-popups' ) ),
			array( 'click', __( 'On click only', 'acps-alert-popups' ), __( 'Only when a button opens it', 'acps-alert-popups' ) ),
		);

		$svg = '<svg viewBox="0 0 760 170" class="acps-art" role="img" aria-labelledby="acps-art-tr-t acps-art-tr-d">
	<title id="acps-art-tr-t">' . esc_html__( 'The five ways an alert can open', 'acps-alert-popups' ) . '</title>
	<desc id="acps-art-tr-d">' . esc_html__( 'Page load, after a delay, on scroll, on exit intent, or only when something is clicked.', 'acps-alert-popups' ) . '</desc>';

		foreach ( $items as $i => $item ) {
			$x = 8 + $i * 150;

			$svg .= '<g class="acps-art-box"><rect x="' . $x . '" y="20" width="138" height="124" rx="9"/></g>';
			$svg .= '<g class="acps-art-icon" transform="translate(' . ( $x + 69 ) . ',62)">' . self::trigger_icon( $item[0] ) . '</g>';
			$svg .= '<text x="' . ( $x + 69 ) . '" y="110" class="acps-art-h-sm">' . esc_html( $item[1] ) . '</text>';
			$svg .= '<text x="' . ( $x + 69 ) . '" y="130" class="acps-art-note">' . esc_html( $item[2] ) . '</text>';
		}

		$svg .= '</svg>';

		return self::figure( $svg, __( 'The five ways an alert can open', 'acps-alert-popups' ) );
	}

	/**
	 * A small glyph per trigger type, drawn around the origin.
	 *
	 * @param string $type Trigger type.
	 * @return string
	 */
	protected static function trigger_icon( $type ) {
		switch ( $type ) {
			case 'delay':
				return '<circle cx="0" cy="0" r="16" class="acps-art-glyph-o"/><path d="M0 -9 L0 0 L7 5" class="acps-art-glyph"/>';

			case 'scroll':
				return '<rect x="-11" y="-16" width="22" height="32" rx="11" class="acps-art-glyph-o"/><path d="M0 -8 L0 0" class="acps-art-glyph"/><path d="M-5 6 L0 12 L5 6" class="acps-art-glyph"/>';

			case 'exit':
				return '<rect x="-16" y="-13" width="20" height="26" rx="3" class="acps-art-glyph-o"/><path d="M2 0 L18 0 M12 -6 L18 0 L12 6" class="acps-art-glyph"/>';

			case 'click':
				return '<path d="M-8 -12 L6 2 L-1 3 L3 12 L-1 14 L-5 5 L-10 10 Z" class="acps-art-glyph-fill"/>';

			case 'load':
			default:
				return '<rect x="-16" y="-12" width="32" height="24" rx="3" class="acps-art-glyph-o"/><path d="M-16 -5 L16 -5" class="acps-art-glyph"/><circle cx="-11" cy="-8.5" r="1.6" class="acps-art-glyph-fill"/>';
		}
	}

	/**
	 * Where an alert shows, as a little site map.
	 *
	 * @return string
	 */
	public static function targeting() {
		$svg = '
<svg viewBox="0 0 760 240" class="acps-art" role="img" aria-labelledby="acps-art-tg-t acps-art-tg-d">
	<title id="acps-art-tg-t">' . esc_html__( 'Aiming an alert at part of the site', 'acps-alert-popups' ) . '</title>
	<desc id="acps-art-tg-d">' . esc_html__( 'Entire site covers every page. Selected locations covers only the pages you list. The never-show list always wins, even over a page you selected.', 'acps-alert-popups' ) . '</desc>

	<text x="8" y="24" class="acps-art-rowhead">' . esc_html__( 'Entire site', 'acps-alert-popups' ) . '</text>
	<g class="acps-art-map">
		<rect x="8" y="36" width="90" height="44" rx="6" class="acps-art-on-soft"/><text x="53" y="63" class="acps-art-map-label">' . esc_html__( 'Home', 'acps-alert-popups' ) . '</text>
		<rect x="106" y="36" width="90" height="44" rx="6" class="acps-art-on-soft"/><text x="151" y="63" class="acps-art-map-label">' . esc_html__( 'About', 'acps-alert-popups' ) . '</text>
		<rect x="204" y="36" width="90" height="44" rx="6" class="acps-art-on-soft"/><text x="249" y="63" class="acps-art-map-label">' . esc_html__( 'News', 'acps-alert-popups' ) . '</text>
		<rect x="302" y="36" width="90" height="44" rx="6" class="acps-art-on-soft"/><text x="347" y="63" class="acps-art-map-label">' . esc_html__( 'Apply', 'acps-alert-popups' ) . '</text>
		<rect x="400" y="36" width="90" height="44" rx="6" class="acps-art-on-soft"/><text x="445" y="63" class="acps-art-map-label">' . esc_html__( 'Contact', 'acps-alert-popups' ) . '</text>
	</g>
	<text x="506" y="63" class="acps-art-note">' . esc_html__( 'every page shows it', 'acps-alert-popups' ) . '</text>

	<text x="8" y="126" class="acps-art-rowhead">' . esc_html__( 'Selected locations: /news/*', 'acps-alert-popups' ) . '</text>
	<g class="acps-art-map">
		<rect x="8" y="138" width="90" height="44" rx="6" class="acps-art-off-soft"/><text x="53" y="165" class="acps-art-map-label-off">' . esc_html__( 'Home', 'acps-alert-popups' ) . '</text>
		<rect x="106" y="138" width="90" height="44" rx="6" class="acps-art-off-soft"/><text x="151" y="165" class="acps-art-map-label-off">' . esc_html__( 'About', 'acps-alert-popups' ) . '</text>
		<rect x="204" y="138" width="90" height="44" rx="6" class="acps-art-on-soft"/><text x="249" y="165" class="acps-art-map-label">' . esc_html__( 'News', 'acps-alert-popups' ) . '</text>
		<rect x="302" y="138" width="90" height="44" rx="6" class="acps-art-off-soft"/><text x="347" y="165" class="acps-art-map-label-off">' . esc_html__( 'Apply', 'acps-alert-popups' ) . '</text>
		<rect x="400" y="138" width="90" height="44" rx="6" class="acps-art-off-soft"/><text x="445" y="165" class="acps-art-map-label-off">' . esc_html__( 'Contact', 'acps-alert-popups' ) . '</text>
	</g>
	<text x="506" y="165" class="acps-art-note">' . esc_html__( 'only what you listed', 'acps-alert-popups' ) . '</text>

	<g class="acps-art-map">
		<rect x="302" y="196" width="90" height="34" rx="6" class="acps-art-block"/>
		<text x="347" y="217" class="acps-art-block-label">' . esc_html__( 'blocked', 'acps-alert-popups' ) . '</text>
	</g>
	<text x="406" y="217" class="acps-art-note">' . esc_html__( 'Never show on /apply — exclusions always win.', 'acps-alert-popups' ) . '</text>
</svg>';

		return self::figure(
			$svg,
			__( 'Aiming an alert at part of the site', 'acps-alert-popups' ),
			__( 'If a page is on the never-show list it stays quiet, no matter what else you picked.', 'acps-alert-popups' )
		);
	}

	/**
	 * The four severity colours.
	 *
	 * @return string
	 */
	public static function severity() {
		$levels = array(
			array( 'info', __( 'Information', 'acps-alert-popups' ), __( 'A notice, nothing urgent', 'acps-alert-popups' ) ),
			array( 'success', __( 'Good news', 'acps-alert-popups' ), __( 'Something positive', 'acps-alert-popups' ) ),
			array( 'warning', __( 'Warning', 'acps-alert-popups' ), __( 'Heads up, plan around it', 'acps-alert-popups' ) ),
			array( 'critical', __( 'Critical', 'acps-alert-popups' ), __( 'Closure, emergency', 'acps-alert-popups' ) ),
		);

		$svg = '<svg viewBox="0 0 760 120" class="acps-art" role="img" aria-labelledby="acps-art-sv-t acps-art-sv-d">
	<title id="acps-art-sv-t">' . esc_html__( 'The four severity levels', 'acps-alert-popups' ) . '</title>
	<desc id="acps-art-sv-d">' . esc_html__( 'Information is blue, good news green, warning amber, critical red.', 'acps-alert-popups' ) . '</desc>';

		foreach ( $levels as $i => $level ) {
			$x = 8 + $i * 188;

			$svg .= '<rect x="' . $x . '" y="20" width="176" height="76" rx="8" class="acps-art-sev-box"/>';
			$svg .= '<rect x="' . $x . '" y="20" width="176" height="6" rx="3" class="acps-art-sev acps-art-sev--' . esc_attr( $level[0] ) . '"/>';
			$svg .= '<text x="' . ( $x + 88 ) . '" y="58" class="acps-art-h-sm">' . esc_html( $level[1] ) . '</text>';
			$svg .= '<text x="' . ( $x + 88 ) . '" y="78" class="acps-art-note">' . esc_html( $level[2] ) . '</text>';
		}

		$svg .= '</svg>';

		return self::figure(
			$svg,
			__( 'The four severity levels', 'acps-alert-popups' ),
			__( 'Severity only sets the colour stripe and sorts your list. It does not change who sees the alert.', 'acps-alert-popups' )
		);
	}

	/**
	 * A labelled mock of the All Alerts screen, for the written guide.
	 *
	 * @return string
	 */
	public static function screen_list() {
		$svg = '
<svg viewBox="0 0 760 250" class="acps-art" role="img" aria-labelledby="acps-art-sl-t acps-art-sl-d">
	<title id="acps-art-sl-t">' . esc_html__( 'The All Alerts screen', 'acps-alert-popups' ) . '</title>
	<desc id="acps-art-sl-d">' . esc_html__( 'A table with one row per popup, showing its name, whether it is live, its severity, schedule, where it shows and how it opens.', 'acps-alert-popups' ) . '</desc>

	<rect x="8" y="8" width="744" height="234" rx="8" class="acps-art-screen"/>
	<text x="28" y="40" class="acps-art-h">' . esc_html__( 'Site Alerts', 'acps-alert-popups' ) . '</text>
	<rect x="150" y="24" width="112" height="24" rx="4" class="acps-art-button"/>
	<text x="206" y="41" class="acps-art-btn-label">' . esc_html__( 'Add New Alert', 'acps-alert-popups' ) . '</text>

	<rect x="28" y="62" width="704" height="30" class="acps-art-thead"/>
	<text x="44" y="82" class="acps-art-th">' . esc_html__( 'Popup', 'acps-alert-popups' ) . '</text>
	<text x="264" y="82" class="acps-art-th">' . esc_html__( 'Status', 'acps-alert-popups' ) . '</text>
	<text x="384" y="82" class="acps-art-th">' . esc_html__( 'Severity', 'acps-alert-popups' ) . '</text>
	<text x="494" y="82" class="acps-art-th">' . esc_html__( 'Schedule', 'acps-alert-popups' ) . '</text>
	<text x="634" y="82" class="acps-art-th">' . esc_html__( 'Where', 'acps-alert-popups' ) . '</text>

	<line x1="28" y1="92" x2="732" y2="92" class="acps-art-rule"/>
	<text x="44" y="118" class="acps-art-cell-strong">' . esc_html__( 'Snow Day Closure', 'acps-alert-popups' ) . '</text>
	<rect x="264" y="104" width="46" height="18" rx="9" class="acps-art-pill-live"/>
	<text x="287" y="117" class="acps-art-pill-label">' . esc_html__( 'Live', 'acps-alert-popups' ) . '</text>
	<text x="384" y="118" class="acps-art-cell">' . esc_html__( 'Critical', 'acps-alert-popups' ) . '</text>
	<text x="494" y="118" class="acps-art-cell">' . esc_html__( 'Always', 'acps-alert-popups' ) . '</text>
	<text x="634" y="118" class="acps-art-cell">' . esc_html__( 'Entire site', 'acps-alert-popups' ) . '</text>
	<text x="44" y="136" class="acps-art-rowactions">' . esc_html__( 'Alert settings | Edit in Beaver Builder | Switch off', 'acps-alert-popups' ) . '</text>

	<line x1="28" y1="148" x2="732" y2="148" class="acps-art-rule"/>
	<text x="44" y="174" class="acps-art-cell-strong">' . esc_html__( 'Open House Reminder', 'acps-alert-popups' ) . '</text>
	<rect x="264" y="160" width="46" height="18" rx="9" class="acps-art-pill-off"/>
	<text x="287" y="173" class="acps-art-pill-label-off">' . esc_html__( 'Off', 'acps-alert-popups' ) . '</text>
	<text x="384" y="174" class="acps-art-cell">' . esc_html__( 'Information', 'acps-alert-popups' ) . '</text>
	<text x="494" y="174" class="acps-art-cell">' . esc_html__( 'From 1 Oct', 'acps-alert-popups' ) . '</text>
	<text x="634" y="174" class="acps-art-cell">' . esc_html__( 'Front page', 'acps-alert-popups' ) . '</text>

	<g class="acps-art-call">
		<path d="M287 128 L287 206"/>
		<circle cx="287" cy="206" r="3"/>
		<text x="298" y="210" class="acps-art-note">' . esc_html__( 'Click "Switch on" here to make an alert live. That is the only switch that matters.', 'acps-alert-popups' ) . '</text>
	</g>
</svg>';

		return self::figure(
			$svg,
			__( 'The All Alerts screen', 'acps-alert-popups' ),
			__( 'Every Beaver Builder popup on the site appears here automatically. You do not have to import anything.', 'acps-alert-popups' )
		);
	}

	/**
	 * Shared arrowhead marker.
	 *
	 * @return string
	 */
	protected static function marker() {
		return '<defs><marker id="acps-arrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse"><path d="M0 0 L10 5 L0 10 z"/></marker></defs>';
	}
}
