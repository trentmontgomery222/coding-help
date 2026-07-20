/**
 * ACPS Link Shortener admin JS.
 *
 * Copy-to-clipboard for short URLs. Success/failure is announced through an
 * ARIA live region (#acps-ls-live, aria-live="polite") so screen reader users
 * get the same feedback sighted users get from the button state — not just a
 * visual change (WCAG 4.1.3 Status Messages).
 */
( function () {
	'use strict';

	var l10n = window.acpsLsL10n || {
		copied: 'Short URL copied to clipboard.',
		copyFailed: 'Could not copy. Press Ctrl+C or Cmd+C to copy.'
	};

	function announce( message ) {
		var live = document.getElementById( 'acps-ls-live' );
		if ( ! live ) {
			return;
		}
		// Clear then set so repeated identical messages are re-announced.
		live.textContent = '';
		window.setTimeout( function () {
			live.textContent = message;
		}, 50 );
	}

	function flashButton( button, ok ) {
		var original = button.getAttribute( 'data-original-label' );
		if ( null === original ) {
			original = button.textContent;
			button.setAttribute( 'data-original-label', original );
		}
		button.textContent = ok ? '✓' : '✗';
		button.classList.toggle( 'is-copied', ok );
		window.setTimeout( function () {
			button.textContent = original;
			button.classList.remove( 'is-copied' );
		}, 2000 );
	}

	function fallbackCopy( text ) {
		var area = document.createElement( 'textarea' );
		area.value = text;
		area.setAttribute( 'readonly', '' );
		area.style.position = 'absolute';
		area.style.left = '-9999px';
		document.body.appendChild( area );
		area.select();
		var ok = false;
		try {
			ok = document.execCommand( 'copy' );
		} catch ( e ) {
			ok = false;
		}
		document.body.removeChild( area );
		return ok;
	}

	function handleCopy( button ) {
		var text = button.getAttribute( 'data-clipboard-text' ) || '';

		function onOk() {
			announce( l10n.copied );
			flashButton( button, true );
		}
		function onFail() {
			announce( l10n.copyFailed );
			flashButton( button, false );
		}

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( onOk ).catch( function () {
				if ( fallbackCopy( text ) ) {
					onOk();
				} else {
					onFail();
				}
			} );
		} else if ( fallbackCopy( text ) ) {
			onOk();
		} else {
			onFail();
		}
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( '.acps-ls-copy' ) : null;
		if ( ! button ) {
			return;
		}
		event.preventDefault();
		handleCopy( button );
	} );
}() );
