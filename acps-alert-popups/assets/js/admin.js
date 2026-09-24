/**
 * Admin behaviour: show only the fields the current choices need.
 */
( function () {
	'use strict';

	/**
	 * Shows or hides an element.
	 *
	 * @param {HTMLElement} el      Element.
	 * @param {boolean}     visible Whether it should show.
	 */
	function toggle( el, visible ) {
		if ( el ) {
			el.style.display = visible ? '' : 'none';
		}
	}

	/**
	 * Applies conditional visibility inside one settings form.
	 *
	 * @param {HTMLElement} root Form container.
	 */
	function sync( root ) {
		var display = root.querySelector( '.acps-display-mode' );
		var audience = root.querySelector( '.acps-audience-mode' );
		var trigger = root.querySelector( '.acps-trigger-mode' );
		var frequency = root.querySelector( '.acps-frequency-mode' );

		if ( display ) {
			toggle( root.querySelector( '.acps-selected-only' ), 'selected' === display.value );
		}

		if ( audience ) {
			toggle( root.querySelector( '.acps-roles-only' ), 'roles' === audience.value );
		}

		if ( trigger ) {
			toggle( root.querySelector( '.acps-trigger-delay-only' ), 'delay' === trigger.value );
			toggle( root.querySelector( '.acps-trigger-scroll-only' ), 'scroll' === trigger.value );
		}

		if ( frequency ) {
			toggle( root.querySelector( '.acps-frequency-days-only' ), 'days' === frequency.value );
		}
	}

	/**
	 * Reads a field's value out of an alert form.
	 *
	 * @param {HTMLElement} root Form container.
	 * @param {string}      key  Setting key.
	 * @return {string} The value, or '' if the field is absent.
	 */
	function value( root, key ) {
		var selector = '[name="acps_alert[' + key + ']"]';

		// Every checkbox is preceded by a hidden input of the SAME name, so an
		// unticked box still posts a 0. querySelector returns the first match in
		// document order, which is that hidden input — reading it would report
		// "0" for a ticked box and never change. Ask for the checkbox by name.
		var checkbox = root.querySelector( 'input[type="checkbox"]' + selector );

		if ( checkbox ) {
			return checkbox.checked ? '1' : '0';
		}

		var field = root.querySelector( selector );

		return field ? field.value : '';
	}

	/**
	 * Builds a little live preview so the appearance settings can be seen
	 * rather than imagined. It is a drawing, not the real popup: the real
	 * content comes from Beaver Builder.
	 *
	 * @param {HTMLElement} root Form container.
	 */
	function buildPreview( root ) {
		var section = root.querySelector( '[data-acps-section="appearance"]' );

		if ( ! section || section.querySelector( '.acps-preview' ) ) {
			return;
		}

		var box = document.createElement( 'div' );
		box.className = 'acps-preview';
		box.innerHTML =
			'<p class="acps-preview__label">Live preview</p>' +
			'<div class="acps-preview__stage">' +
				'<div class="acps-preview__page">' +
					'<span></span><span></span><span></span>' +
				'</div>' +
				'<div class="acps-preview__scrim"></div>' +
				'<div class="acps-preview__dialog">' +
					'<i class="acps-preview__stripe"></i>' +
					'<b class="acps-preview__close">&times;</b>' +
					'<em class="acps-preview__line is-title"></em>' +
					'<em class="acps-preview__line"></em>' +
					'<em class="acps-preview__line is-short"></em>' +
				'</div>' +
			'</div>' +
			'<p class="acps-preview__note">A sketch of the frame only. What goes inside is whatever you built in Beaver Builder.</p>';

		section.appendChild( box );
		updatePreview( root );
	}

	/**
	 * Reflects the current appearance settings in the preview.
	 *
	 * @param {HTMLElement} root Form container.
	 */
	function updatePreview( root ) {
		var box = root.querySelector( '.acps-preview' );

		if ( ! box ) {
			return;
		}

		var stage = box.querySelector( '.acps-preview__stage' );
		var dialog = box.querySelector( '.acps-preview__dialog' );
		var scrim = box.querySelector( '.acps-preview__scrim' );
		var close = box.querySelector( '.acps-preview__close' );

		var position = value( root, 'position' ) || 'center';
		var width = parseInt( value( root, 'width' ), 10 ) || 640;

		// There is no separate severity any more: how urgent the popup looks
		// follows the status level, so the preview cannot disagree with it.
		var level = value( root, 'status_level' ) || 'info';

		stage.className = 'acps-preview__stage is-' + position;
		dialog.className = 'acps-preview__dialog lvl-' + level;

		// Map the real pixel width onto the small stage proportionally.
		var pct = Math.max( 24, Math.min( 92, ( width / 1200 ) * 100 ) );
		dialog.style.width = pct + '%';

		scrim.style.display = '1' === value( root, 'show_overlay' ) ? '' : 'none';
		close.style.display = '1' === value( root, 'dismissible' ) ? '' : 'none';
	}

	/**
	 * Warns when every way of closing the alert has been switched off, which
	 * would trap a keyboard visitor.
	 *
	 * @param {HTMLElement} root Form container.
	 */
	function checkEscapeRoutes( root ) {
		var section = root.querySelector( '[data-acps-section="appearance"]' );

		if ( ! section ) {
			return;
		}

		var trapped = '1' !== value( root, 'dismissible' )
			&& '1' !== value( root, 'overlay_close' )
			&& '1' !== value( root, 'esc_close' );

		var warning = section.querySelector( '.acps-trap-warning' );

		if ( trapped && ! warning ) {
			warning = document.createElement( 'p' );
			warning.className = 'acps-trap-warning';
			warning.setAttribute( 'role', 'alert' );
			warning.textContent = 'Every way of closing this alert is switched off. A keyboard visitor would have no way out — turn at least one back on.';
			section.appendChild( warning );
		} else if ( ! trapped && warning ) {
			warning.parentNode.removeChild( warning );
		}
	}

	/**
	 * Boots every alert form on the page.
	 */
	function start() {
		var forms = document.querySelectorAll( '.acps-alert-fields' );

		Array.prototype.forEach.call( forms, function ( root ) {
			// A failure here costs this form its live preview, never the form
			// itself: the fields still post normally without this script.
			try {
				sync( root );
				buildPreview( root );
				checkEscapeRoutes( root );
			} catch ( e ) {
				return;
			}

			root.addEventListener( 'change', function () {
				sync( root );
				updatePreview( root );
				checkEscapeRoutes( root );
			} );

			root.addEventListener( 'input', function () {
				updatePreview( root );
			} );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
