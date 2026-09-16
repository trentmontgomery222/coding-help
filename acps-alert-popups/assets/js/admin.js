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
	 * Boots every alert form on the page.
	 */
	function start() {
		var forms = document.querySelectorAll( '.acps-alert-fields' );

		Array.prototype.forEach.call( forms, function ( root ) {
			sync( root );

			root.addEventListener( 'change', function () {
				sync( root );
			} );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
