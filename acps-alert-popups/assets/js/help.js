/**
 * Help & Tutorials screen behaviour.
 *
 * Builds a contents list from the page's own headings, and lets someone search
 * the questions instead of scrolling. Everything here is an enhancement: with
 * JavaScript off the page is still a complete, readable guide.
 */
( function () {
	'use strict';

	/**
	 * Builds a sticky contents list from the section headings.
	 */
	function buildContents() {
		var wrap = document.querySelector( '.acps-help' );
		var lede = document.querySelector( '.acps-help__lede' );
		var headings = document.querySelectorAll( '.acps-help-section > h2' );

		if ( ! wrap || ! lede || headings.length < 3 ) {
			return;
		}

		var nav = document.createElement( 'nav' );
		nav.className = 'acps-toc';
		nav.setAttribute( 'aria-label', 'Contents' );

		var list = document.createElement( 'ul' );

		Array.prototype.forEach.call( headings, function ( heading, i ) {
			if ( ! heading.id ) {
				heading.id = 'acps-help-s' + i;
			}

			var li = document.createElement( 'li' );
			var a = document.createElement( 'a' );

			a.href = '#' + heading.id;
			a.textContent = heading.textContent;
			li.appendChild( a );
			list.appendChild( li );
		} );

		nav.appendChild( list );
		lede.parentNode.insertBefore( nav, lede.nextSibling );
	}

	/**
	 * Adds a search box that filters the question lists.
	 */
	function buildSearch() {
		var groups = document.querySelectorAll( '.acps-faq' );

		if ( ! groups.length ) {
			return;
		}

		Array.prototype.forEach.call( groups, function ( group ) {
			var box = document.createElement( 'div' );
			box.className = 'acps-faq-search';

			var input = document.createElement( 'input' );
			input.type = 'search';
			input.className = 'regular-text';
			input.placeholder = 'Type a word to filter these…';
			input.setAttribute( 'aria-label', 'Filter these questions' );

			var count = document.createElement( 'span' );
			count.className = 'acps-faq-search__count';
			count.setAttribute( 'role', 'status' );

			box.appendChild( input );
			box.appendChild( count );
			group.parentNode.insertBefore( box, group );

			input.addEventListener( 'input', function () {
				var term = input.value.trim().toLowerCase();
				var items = group.querySelectorAll( '.acps-faq__item' );
				var shown = 0;

				Array.prototype.forEach.call( items, function ( item ) {
					var match = '' === term || item.textContent.toLowerCase().indexOf( term ) !== -1;

					item.style.display = match ? '' : 'none';

					if ( match ) {
						shown++;

						// Open matches so the answer is visible straight away.
						if ( '' !== term ) {
							item.setAttribute( 'open', 'open' );
						}
					}
				} );

				if ( '' === term ) {
					count.textContent = '';
				} else if ( 0 === shown ) {
					count.textContent = 'Nothing matches that word.';
				} else {
					count.textContent = shown + ( 1 === shown ? ' match' : ' matches' );
				}
			} );
		} );
	}

	/**
	 * Boots the enhancements.
	 */
	function start() {
		// Each enhancement is optional; one failing never takes the other down.
		try {
			buildContents();
		} catch ( e ) {}

		try {
			buildSearch();
		} catch ( e ) {}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
