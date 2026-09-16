/**
 * The rule builder on the settings screen.
 *
 * Adding a rule clones a hidden prototype whose field names carry __i__ and
 * __c__ placeholders, and fills those in with the real indexes. That replaced
 * an earlier approach using <template> plus a regex renumber, which failed
 * silently — the worst way for a builder to fail, since the page looks fine
 * and the button simply does nothing.
 *
 * Everything degrades: with no JavaScript the rules already saved still
 * render and still submit; only adding and removing needs this file.
 */
( function () {
	'use strict';

	function init( wrap ) {
		var name = wrap.getAttribute( 'data-name' );
		var list = wrap.querySelector( '.wpsqr-rules__list' );
		var empty = wrap.querySelector( '.wpsqr-no-rules' );
		var addButton = wrap.querySelector( '[data-add-rule]' );

		var ruleProto = wrap.querySelector( '[data-proto="rule"]' );
		var condProto = wrap.querySelector( '[data-proto="cond"]' );

		if ( ! list || ! ruleProto ) {
			return;
		}

		function rules() {
			return Array.prototype.slice.call( list.querySelectorAll( '.wpsqr-rule' ) );
		}

		/**
		 * Build a live node from a prototype, with the placeholders filled in.
		 *
		 * The prototype's fields are `disabled` so they never submit; the copy
		 * has that removed.
		 */
		function fromProto( proto, replacements ) {
			var html = proto.innerHTML;

			Object.keys( replacements ).forEach( function ( token ) {
				html = html.split( token ).join( replacements[ token ] );
			} );

			var holder = document.createElement( 'div' );
			holder.innerHTML = html.trim();

			var node = holder.firstElementChild;

			Array.prototype.forEach.call( node.querySelectorAll( '[disabled]' ), function ( field ) {
				field.removeAttribute( 'disabled' );
			} );

			return node;
		}

		/** Field names carry their position, so removals have to renumber. */
		function renumber() {
			rules().forEach( function ( rule, ruleIndex ) {
				Array.prototype.forEach.call( rule.querySelectorAll( '[name]' ), function ( field ) {
					field.setAttribute(
						'name',
						field.getAttribute( 'name' ).replace( /^([^[]+)\[[^\]]*\]/, '$1[' + ruleIndex + ']' )
					);
				} );

				Array.prototype.forEach.call( rule.querySelectorAll( '.wpsqr-cond' ), function ( cond, condIndex ) {
					Array.prototype.forEach.call( cond.querySelectorAll( '[name]' ), function ( field ) {
						field.setAttribute(
							'name',
							field.getAttribute( 'name' ).replace( /\[conds\]\[[^\]]*\]/, '[conds][' + condIndex + ']' )
						);
					} );
				} );

				var num = rule.querySelector( '.wpsqr-rule__num' );
				if ( num ) {
					num.textContent = String( ruleIndex + 1 );
				}

				summarise( rule );
			} );

			if ( empty ) {
				empty.hidden = rules().length > 0;
			}
		}

		/** Only the fields the chosen action needs. */
		function syncExtras( rule ) {
			var action = rule.querySelector( '.wpsqr-f-then' );
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

		function labelOf( select ) {
			if ( ! select || ! select.options.length ) {
				return '';
			}
			var option = select.options[ select.selectedIndex ];
			return option ? option.textContent.trim() : '';
		}

		/**
		 * A plain-English restatement of the rule, in its header bar.
		 *
		 * Reading a row of dropdowns back as a sentence is the quickest way to
		 * catch a rule that says something other than what you meant.
		 */
		function summarise( rule ) {
			var target = rule.querySelector( '.wpsqr-rule__summary' );
			if ( ! target ) {
				return;
			}

			var tests = Array.prototype.slice.call( rule.querySelectorAll( '.wpsqr-test' ) );

			var text = tests.map( function ( test, index ) {
				var join = index === 0 ? 'If' : labelOf( test.querySelector( '.wpsqr-f-join' ) );
				var when = labelOf( test.querySelector( '.wpsqr-f-when' ) ) ||
					( test.querySelector( '.wpsqr-test__fixed' ) || {} ).textContent || '';
				var op = labelOf( test.querySelector( '.wpsqr-f-op' ) );
				var not = test.querySelector( '.wpsqr-test__not input' );
				var value = ( test.querySelector( '.wpsqr-f-value' ) || {} ).value || '';

				return [
					join,
					when.trim(),
					( not && not.checked ) ? 'does NOT' : '',
					op,
					value ? '“' + value + '”' : '…'
				].filter( Boolean ).join( ' ' );
			} ).join( ' ' );

			var action = labelOf( rule.querySelector( '.wpsqr-f-then' ) );
			if ( action ) {
				text += ' → ' + action;
			}

			target.textContent = text;
		}

		function wireRule( rule ) {
			rule.addEventListener( 'change', function () {
				syncExtras( rule );
				summarise( rule );
			} );

			rule.addEventListener( 'input', function () {
				summarise( rule );
			} );

			var remove = rule.querySelector( '[data-remove-rule]' );
			if ( remove ) {
				remove.addEventListener( 'click', function () {
					rule.parentNode.removeChild( rule );
					renumber();
				} );
			}

			var addCond = rule.querySelector( '[data-add-cond]' );
			if ( addCond && condProto ) {
				addCond.addEventListener( 'click', function () {
					var holder = rule.querySelector( '.wpsqr-conds' );
					var index = holder.querySelectorAll( '.wpsqr-cond' ).length;

					var cond = fromProto( condProto, {
						__i__: String( rules().indexOf( rule ) ),
						__c__: String( index )
					} );

					holder.appendChild( cond );
					wireCondition( cond, rule );
					renumber();

					var field = cond.querySelector( '.wpsqr-f-value' );
					if ( field ) {
						field.focus();
					}
				} );
			}

			Array.prototype.forEach.call( rule.querySelectorAll( '.wpsqr-cond' ), function ( cond ) {
				wireCondition( cond, rule );
			} );

			syncExtras( rule );
			summarise( rule );
		}

		function wireCondition( cond, rule ) {
			var remove = cond.querySelector( '[data-remove-cond]' );
			if ( remove ) {
				remove.addEventListener( 'click', function () {
					cond.parentNode.removeChild( cond );
					renumber();
					summarise( rule );
				} );
			}
		}

		rules().forEach( wireRule );
		renumber();

		if ( addButton ) {
			addButton.addEventListener( 'click', function () {
				var rule = fromProto( ruleProto, { __i__: String( rules().length ) } );

				list.appendChild( rule );
				wireRule( rule );
				renumber();

				var field = rule.querySelector( '.wpsqr-f-value' );
				if ( field ) {
					field.focus();
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
