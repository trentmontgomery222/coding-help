/**
 * Managed Content Manager — front-end portal.
 * Live character counters for fields that declare a max length.
 * (Server still enforces the real limit on save; this is only UX.)
 */
( function () {
	'use strict';

	function textLength( el ) {
		// For rich text we count the visible characters, matching the server.
		if ( el.getAttribute( 'data-countsource' ) === 'text' ) {
			var tmp = document.createElement( 'div' );
			tmp.innerHTML = el.value;
			return ( tmp.textContent || tmp.innerText || '' ).length;
		}
		return el.value.length;
	}

	function attach( form ) {
		var counter = form.querySelector( '.mcm-count' );
		if ( ! counter ) {
			return;
		}
		var max = parseInt( counter.getAttribute( 'data-max' ), 10 );
		var input = form.querySelector( '.mcm-input' );
		if ( ! input || ! max ) {
			return;
		}

		function update() {
			var len = textLength( input );
			counter.textContent = len + ' / ' + max;
			counter.classList.toggle( 'mcm-over', len > max );
		}

		input.addEventListener( 'input', update );
		input.addEventListener( 'keyup', update );
		update();
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var forms = document.querySelectorAll( '.mcm-block-form' );
		Array.prototype.forEach.call( forms, attach );
	} );
} )();
