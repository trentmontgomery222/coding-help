/**
 * The rule builder on the settings screen.
 *
 * No dependencies and no build step — it clones a template row, renumbers the
 * field names, and shows only the extra field the chosen action needs.
 */
( function () {
	'use strict';

	function init( table ) {
		var body = table.querySelector( 'tbody' );
		var name = table.getAttribute( 'data-name' );
		var add = document.querySelector( '[data-add-rule="' + name + '"]' );

		function renumber() {
			Array.prototype.forEach.call( body.querySelectorAll( 'tr' ), function ( row, index ) {
				Array.prototype.forEach.call( row.querySelectorAll( '[name]' ), function ( field ) {
					field.setAttribute(
						'name',
						field.getAttribute( 'name' ).replace( /\[\d+\]/, '[' + index + ']' )
					);
				} );
			} );
		}

		// Only one of replace / label / message / url is ever relevant, so the
		// row shows the one the chosen action actually uses.
		function syncExtras( row ) {
			var action = row.querySelector( '.wpsqr-then' );
			if ( ! action ) {
				return;
			}

			var needs = {
				rewrite: 'replace',
				badge: 'label',
				noResults: 'message',
				notice: 'message',
				redirect: 'url'
			}[ action.value ] || null;

			Array.prototype.forEach.call( row.querySelectorAll( '[data-extra]' ), function ( field ) {
				field.hidden = field.getAttribute( 'data-extra' ) !== needs;
			} );
		}

		function wire( row ) {
			var action = row.querySelector( '.wpsqr-then' );
			if ( action ) {
				action.addEventListener( 'change', function () {
					syncExtras( row );
				} );
			}

			var remove = row.querySelector( '.wpsqr-remove' );
			if ( remove ) {
				remove.addEventListener( 'click', function () {
					row.parentNode.removeChild( row );
					renumber();
					empty();
				} );
			}

			syncExtras( row );
		}

		function empty() {
			var note = table.parentNode.querySelector( '.wpsqr-no-rules' );
			if ( note ) {
				note.hidden = body.querySelectorAll( 'tr' ).length > 0;
			}
		}

		Array.prototype.forEach.call( body.querySelectorAll( 'tr' ), wire );
		empty();

		if ( add ) {
			add.addEventListener( 'click', function () {
				var template = document.getElementById( 'wpsqr-template-' + name );
				if ( ! template ) {
					return;
				}

				var row = document.createElement( 'tbody' );
				row.innerHTML = template.innerHTML.trim();

				var tr = row.querySelector( 'tr' );
				body.appendChild( tr );

				renumber();
				wire( tr );
				empty();

				var first = tr.querySelector( 'input, select' );
				if ( first ) {
					first.focus();
				}
			} );
		}
	}

	function boot() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-wpsqr-rules]' ), init );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
