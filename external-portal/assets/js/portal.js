/**
 * External Portal — front-end behaviour.
 *
 * The only client-side feature is the accessible session-expiry warning
 * (spec Section 8, Q4): announced through an ARIA live region, never colour/visual
 * only. Keeping a session alive is a real server round-trip (the server slides the
 * idle window on any request), so "Stay signed in" pings the current URL.
 */
( function () {
	'use strict';

	if ( typeof window.EXPortal === 'undefined' ) {
		return;
	}

	var cfg  = window.EXPortal;
	var live = document.querySelector( '.exp-live' );
	if ( ! live || ! cfg.idleExpires || ! cfg.warnSeconds ) {
		return;
	}

	// Server clock offset so we don't rely on the client's wall clock being right.
	var offset = Math.floor( Date.now() / 1000 ) - ( cfg.now || Math.floor( Date.now() / 1000 ) );

	var warned  = false;
	var expired = false;

	function nowServer() {
		return Math.floor( Date.now() / 1000 ) - offset;
	}

	function clearLive() {
		while ( live.firstChild ) {
			live.removeChild( live.firstChild );
		}
	}

	function announce( message, withButton ) {
		clearLive();
		var p = document.createElement( 'p' );
		p.textContent = message;
		live.appendChild( p );

		if ( withButton ) {
			var wrap = document.createElement( 'div' );
			wrap.className = 'exp-live__actions';
			var btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'exp-button exp-button--small';
			btn.textContent = stayLabel();
			btn.addEventListener( 'click', keepAlive );
			wrap.appendChild( btn );
			live.appendChild( wrap );
		}
	}

	function stayLabel() {
		return 'Stay signed in';
	}

	function keepAlive() {
		// A same-origin GET slides the server's idle window.
		fetch( window.location.href, { credentials: 'same-origin', cache: 'no-store' } )
			.then( function () {
				cfg.idleExpires = nowServer() + ( cfg.idleMinutes ? cfg.idleMinutes * 60 : cfg.warnSeconds + 60 );
				warned = false;
				expired = false;
				clearLive();
			} )
			.catch( function () {
				window.location.href = cfg.loginUrl || window.location.href;
			} );
	}

	function tick() {
		var remaining = cfg.idleExpires - nowServer();

		if ( remaining <= 0 && ! expired ) {
			expired = true;
			announce( cfg.i18n.expired, false );
			return;
		}
		if ( remaining > 0 && remaining <= cfg.warnSeconds && ! warned ) {
			warned = true;
			announce( cfg.i18n.expiringSoon, true );
		}
		if ( remaining > cfg.warnSeconds && warned && ! expired ) {
			warned = false;
			clearLive();
		}
	}

	setInterval( tick, 5000 );
	tick();
} )();
