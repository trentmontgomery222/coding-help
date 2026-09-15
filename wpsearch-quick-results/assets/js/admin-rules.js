/**
 * The rule builder on the settings screen.
 *
 * No dependencies and no build step. Each rule is a block containing a main
 * condition, any number of extra conditions, and an action; the field names
 * are renumbered whenever anything is added or removed so the array arrives
 * in order on the server.
 */
( function () {
	'use strict';

	function init( wrap ) {
		var name = wrap.getAttribute( 'data-name' );
		var add = document.querySelector( '[data-add-rule="' + name + '"]' );

		/**
		 * Rewrite every field name to match its position.
		 *
		 * Both indexes are replaced in one pass, so a condition inside rule 3
		 * becomes name[3][conds][1][value] however it got there — cloned from
		 * a template, or left behind when the rule above it was deleted.
		 */
		function renumber() {
			Array.prototype.forEach.call( wrap.querySelectorAll( '.wpsqr-rule' ), function ( rule, ruleIndex ) {
				Array.prototype.forEach.call( rule.querySelectorAll( '[name]' ), function ( field ) {
					field.setAttribute(
						'name',
						field.getAttribute( 'name' ).replace( /^[^[]+\[\d+\]/, name + '[' + ruleIndex + ']' )
					);
				} );

				Array.prototype.forEach.call( rule.querySelectorAll( '.wpsqr-cond' ), function ( cond, condIndex ) {
					Array.prototype.forEach.call( cond.querySelectorAll( '[name]' ), function ( field ) {
						field.setAttribute(
							'name',
							field.getAttribute( 'name' ).replace( /\[conds\]\[\d+\]/, '[conds][' + condIndex + ']' )
						);
					} );
				} );
			} );
		}

		// Only the fields the chosen action needs are shown. Rewrite is the
		// one action wanting two: what to replace with, and where.
		function syncExtras( rule ) {
			var action = rule.querySelector( '.wpsqr-then' );
			if ( ! action ) {
				return;
			}

			var needs = {
				rewrite: [ 'target', 'replace' ],
				setDesc: [ 'desc' ],
				badge: [ 'label' ],
				noResults: [ 'message' ],
				notice: [ 'message' ],
				redirect: [ 'url' ]
			}[ action.value ] || [];

			Array.prototype.forEach.call( rule.querySelectorAll( '[data-extra]' ), function ( field ) {
				field.hidden = needs.indexOf( field.getAttribute( 'data-extra' ) ) === -1;
			} );
		}

		function wireRule( rule ) {
			var action = rule.querySelector( '.wpsqr-then' );
			if ( action ) {
				action.addEventListener( 'change', function () {
					syncExtras( rule );
				} );
			}

			var remove = rule.querySelector( '.wpsqr-rule-head .wpsqr-remove' );
			if ( remove ) {
				remove.addEventListener( 'click', function () {
					rule.parentNode.removeChild( rule );
					renumber();
					updateEmpty();
				} );
			}

			var addCond = rule.querySelector( '.wpsqr-add-cond' );
			if ( addCond ) {
				addCond.addEventListener( 'click', function () {
					var template = document.getElementById( 'wpsqr-cond-' + name );
					if ( ! template ) {
						return;
					}

					var holder = document.createElement( 'div' );
					holder.innerHTML = template.innerHTML.trim();

					var cond = holder.querySelector( '.wpsqr-cond' );
					rule.querySelector( '.wpsqr-conds' ).appendChild( cond );

					renumber();
					wireCondition( cond );

					var first = cond.querySelector( 'input[type="text"]' );
					if ( first ) {
						first.focus();
					}
				} );
			}

			Array.prototype.forEach.call( rule.querySelectorAll( '.wpsqr-cond' ), wireCondition );
			syncExtras( rule );
		}

		function wireCondition( cond ) {
			var remove = cond.querySelector( '.wpsqr-remove-cond' );
			if ( remove ) {
				remove.addEventListener( 'click', function () {
					cond.parentNode.removeChild( cond );
					renumber();
				} );
			}
		}

		function updateEmpty() {
			var note = wrap.parentNode.querySelector( '.wpsqr-no-rules' );
			if ( note ) {
				note.hidden = wrap.querySelectorAll( '.wpsqr-rule' ).length > 0;
			}
		}

		Array.prototype.forEach.call( wrap.querySelectorAll( '.wpsqr-rule' ), wireRule );
		updateEmpty();

		if ( add ) {
			add.addEventListener( 'click', function () {
				var template = document.getElementById( 'wpsqr-template-' + name );
				if ( ! template ) {
					return;
				}

				var holder = document.createElement( 'div' );
				holder.innerHTML = template.innerHTML.trim();

				var rule = holder.querySelector( '.wpsqr-rule' );
				wrap.appendChild( rule );

				renumber();
				wireRule( rule );
				updateEmpty();

				var first = rule.querySelector( 'input[type="text"], select' );
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
