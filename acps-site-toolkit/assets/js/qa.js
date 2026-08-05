/**
 * Cayden Form Manager — Q&A / help widget.
 *
 * Progressive enhancement over server-rendered, cache-safe markup: an
 * accessible accordion (aria-expanded / aria-controls), client-side search
 * filtering, and an "ask a question" toggle that reveals the contact form.
 */
( function () {
	'use strict';

	function ready( fn ) {
		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', fn );
		} else {
			fn();
		}
	}

	ready( function () {
		var widgets = document.querySelectorAll( '[data-acps-qa]' );
		Array.prototype.forEach.call( widgets, function ( w ) {
			initQA( w );
		} );
	} );

	function initQA( widget ) {
		// Accordion toggles.
		var toggles = widget.querySelectorAll( '.acps-qa__toggle' );
		Array.prototype.forEach.call( toggles, function ( btn ) {
			btn.addEventListener( 'click', function () {
				var panel = document.getElementById( btn.getAttribute( 'aria-controls' ) );
				var open = btn.getAttribute( 'aria-expanded' ) === 'true';
				btn.setAttribute( 'aria-expanded', open ? 'false' : 'true' );
				if ( panel ) {
					panel.hidden = open;
				}
				var icon = btn.querySelector( '.acps-qa__icon' );
				if ( icon ) { icon.textContent = open ? '+' : '−'; }
			} );
		} );

		// Search filtering.
		var search = widget.querySelector( '[data-acps-qa-search]' );
		var items = widget.querySelectorAll( '[data-acps-qa-item]' );
		var noResults = widget.querySelector( '[data-acps-qa-noresults]' );
		if ( search ) {
			search.addEventListener( 'input', function () {
				var q = search.value.trim().toLowerCase();
				var visible = 0;
				Array.prototype.forEach.call( items, function ( item ) {
					var hay = ( item.getAttribute( 'data-q' ) || '' ).toLowerCase();
					var show = q === '' || hay.indexOf( q ) !== -1;
					item.hidden = ! show;
					if ( show ) { visible++; }
				} );
				if ( noResults ) {
					noResults.hidden = visible !== 0;
				}
			} );
		}

		// "Ask a question" reveal.
		var askBtn = widget.querySelector( '[data-acps-qa-ask]' );
		var contact = widget.querySelector( '[data-acps-qa-contact]' );
		if ( askBtn && contact ) {
			askBtn.addEventListener( 'click', function () {
				var open = askBtn.getAttribute( 'aria-expanded' ) === 'true';
				askBtn.setAttribute( 'aria-expanded', open ? 'false' : 'true' );
				contact.hidden = open;
				if ( ! open ) {
					var first = contact.querySelector( 'input, textarea, select, button' );
					if ( first ) { first.focus(); }
				}
			} );
		}
	}
} )();
