/**
 * ACPS Site Toolkit — journey tracking beacon.
 *
 * CRITICAL (spec §4.2 / §13): this is the ONLY place a page visit is recorded.
 * The site runs behind WP Engine Global Edge Security, which serves cached
 * HTML; a PHP write during render never fires for a cached page. So tracking
 * must be a client-side beacon to an uncached REST endpoint. If you ever move
 * visit-recording server-side, the journey feature silently dies in production.
 */
( function () {
	'use strict';

	var cfg = window.ACPS_ST || {};
	if ( ! cfg.restUrl ) {
		return;
	}

	var SID_COOKIE = 'acps_st_sid';
	var CONSENT_COOKIE = 'acps_st_consent';

	/* ---- tiny cookie helpers (first-party only) ---------------------- */
	function readCookie( name ) {
		var m = document.cookie.match( '(^|;)\\s*' + name + '\\s*=\\s*([^;]+)' );
		return m ? decodeURIComponent( m.pop() ) : '';
	}
	function writeCookie( name, value, minutes ) {
		var expires = '';
		if ( minutes ) {
			var d = new Date();
			d.setTime( d.getTime() + minutes * 60 * 1000 );
			expires = '; expires=' + d.toUTCString();
		}
		document.cookie = name + '=' + encodeURIComponent( value ) + expires +
			'; path=/; SameSite=Lax' + ( location.protocol === 'https:' ? '; Secure' : '' );
	}

	/* ---- session token (session-scoped, first-party) ----------------- */
	function makeToken() {
		var bytes = new Uint8Array( 20 );
		( window.crypto || window.msCrypto ).getRandomValues( bytes );
		var hex = '';
		for ( var i = 0; i < bytes.length; i++ ) {
			hex += ( '0' + bytes[ i ].toString( 16 ) ).slice( -2 );
		}
		return hex;
	}
	function getToken() {
		var t = readCookie( SID_COOKIE );
		if ( ! /^[a-f0-9]{40}$/.test( t ) ) {
			t = makeToken();
		}
		// Refresh the idle window on every activity.
		writeCookie( SID_COOKIE, t, cfg.idleMinutes || 30 );
		return t;
	}

	/* ---- consent ----------------------------------------------------- */
	function hasConsent() {
		return readCookie( CONSENT_COOKIE ) === '1';
	}
	function trackingActive() {
		if ( ! cfg.tracking ) {
			return false;
		}
		if ( cfg.consentMode && ! hasConsent() ) {
			return false;
		}
		return true;
	}

	/* Public: let a cookie banner grant/revoke consent. Forms keep working
	   regardless (spec §4.4). */
	window.acpsStGrantConsent = function () {
		writeCookie( CONSENT_COOKIE, '1', 60 * 24 * 365 );
		beacon();
	};
	window.acpsStRevokeConsent = function () {
		writeCookie( CONSENT_COOKIE, '0', 60 * 24 * 365 );
	};

	/* Shared runtime other scripts read (forms/feedback). */
	var runtime = window.ACPS_ST_RT = {
		token: '',
		active: false,
		restUrl: cfg.restUrl
	};

	function send( path, payload, useBeacon ) {
		var url = cfg.restUrl.replace( /\/$/, '' ) + path;
		var body = JSON.stringify( payload );
		if ( useBeacon && navigator.sendBeacon ) {
			var blob = new Blob( [ body ], { type: 'application/json' } );
			navigator.sendBeacon( url, blob );
			return;
		}
		fetch( url, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: body,
			keepalive: true,
			credentials: 'same-origin'
		} ).catch( function () {} );
	}

	var pageStart = Date.now();

	function beacon() {
		if ( ! trackingActive() ) {
			return;
		}
		runtime.token = getToken();
		runtime.active = true;

		send( '/beacon', {
			session: runtime.token,
			consent: cfg.consentMode ? ( hasConsent() ? 1 : 0 ) : 1,
			post_id: cfg.postId || 0,
			url: location.href,
			title: document.title,
			referrer: document.referrer || '',
			viewport: window.innerWidth + 'x' + window.innerHeight
		}, false );
	}

	function unload() {
		if ( ! runtime.active || ! runtime.token ) {
			return;
		}
		var seconds = Math.round( ( Date.now() - pageStart ) / 1000 );
		send( '/unload', { session: runtime.token, seconds: seconds }, true );
	}

	// Even when tracking is inactive, expose a token to forms IF tracking is
	// simply disabled by consent — but never write a session server-side.
	if ( cfg.tracking && ( ! cfg.consentMode || hasConsent() ) ) {
		runtime.token = getToken();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', beacon );
	} else {
		beacon();
	}

	// Write time-on-page on the way out.
	window.addEventListener( 'pagehide', unload );
	document.addEventListener( 'visibilitychange', function () {
		if ( document.visibilityState === 'hidden' ) {
			unload();
		}
	} );
} )();
