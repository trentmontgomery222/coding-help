/**
 * Cayden Form Manager — password gate for restricted forms.
 *
 * The protected form is NOT in the page markup (so it can't leak from cache).
 * On unlock we POST the password to the uncached /unlock endpoint, receive the
 * rendered form HTML, drop it in, and boot the normal form runtime on it.
 */
( function () {
	'use strict';

	var cfg = window.ACPS_ST || {};
	var restUrl = ( cfg.restUrl || '' ).replace( /\/$/, '' );
	var strings = cfg.strings || {};

	function ready( fn ) {
		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', fn );
		} else {
			fn();
		}
	}

	ready( function () {
		var locks = document.querySelectorAll( '[data-acps-lock]' );
		Array.prototype.forEach.call( locks, function ( lock ) {
			initLock( lock );
		} );
	} );

	function initLock( lock ) {
		var formId = lock.getAttribute( 'data-acps-lock' );
		var input = lock.querySelector( 'input[type="password"]' );
		var button = lock.querySelector( '[data-acps-lock-submit]' );
		var errorEl = lock.querySelector( '[data-acps-lock-error]' );

		function attempt() {
			if ( ! input.value ) {
				return;
			}
			button.disabled = true;
			errorEl.textContent = '';
			fetch( restUrl + '/unlock', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				credentials: 'same-origin',
				body: JSON.stringify( {
					form_id: parseInt( formId, 10 ),
					password: input.value,
					post_id: cfg.postId || 0
				} )
			} )
				.then( function ( r ) { return r.json(); } )
				.then( function ( res ) {
					button.disabled = false;
					if ( res && res.success && res.html ) {
						reveal( lock, res.html );
					} else {
						errorEl.textContent = ( res && res.message ) || strings.genericError || 'Incorrect password.';
						input.focus();
					}
				} )
				.catch( function () {
					button.disabled = false;
					errorEl.textContent = strings.genericError || 'Something went wrong.';
				} );
		}

		button.addEventListener( 'click', attempt );
		input.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' || e.keyCode === 13 ) {
				e.preventDefault();
				attempt();
			}
		} );
	}

	function reveal( lock, html ) {
		var container = document.createElement( 'div' );
		container.innerHTML = html;
		var replacement = container.firstElementChild || container;
		lock.parentNode.replaceChild( replacement, lock );

		// Boot the form runtime on the freshly-inserted form.
		var formEl = replacement.querySelector ? replacement.querySelector( '.acps-form' ) : null;
		if ( ! formEl && replacement.classList && replacement.classList.contains( 'acps-form' ) ) {
			formEl = replacement;
		}
		if ( formEl && window.ACPSForm ) {
			new window.ACPSForm( formEl );
			// Move focus to the now-available form for keyboard/AT users.
			var firstLabel = formEl.querySelector( 'label' );
			if ( firstLabel ) {
				var first = formEl.querySelector( 'input, select, textarea' );
				if ( first ) { first.focus(); }
			}
		}
	}
} )();
