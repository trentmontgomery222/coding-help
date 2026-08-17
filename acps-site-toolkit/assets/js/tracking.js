/**
 * Cayden Form Manager — journey tracking beacon.
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

	// Master off switch: when analytics/visitor/page tracking is disabled, do
	// nothing at all — no beacon, heartbeat, presence, cookies or network calls.
	// Forms still work: they read the config below and degrade without a session.
	if ( ! cfg.analytics ) {
		window.ACPS_ST_RT = window.ACPS_ST_RT || { token: '', active: false };
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

	/* Unique-user identity is now derived SERVER-SIDE from the anonymized IP +
	   browser (the same signal the spam guard uses), so there is deliberately no
	   client-side visitor id here — clearing cookies/cache can't mint a new one. */

	/* ---- consent ----------------------------------------------------- */
	function hasConsent() {
		return readCookie( CONSENT_COOKIE ) === '1';
	}
	function trackingActive() {
		if ( ! cfg.tracking ) {
			return false;
		}
		// Logged-in admins are excluded from analytics entirely.
		if ( cfg.suppress ) {
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

	/* MINIMAL-REQUEST DESIGN: exactly ONE non-blocking request per pageview.
	   navigator.sendBeacon hands the request to the browser to send in the
	   background — it never delays the page or competes with rendering. There is
	   deliberately NO heartbeat, NO unload beacon and NO polling: those were the
	   source of the request flood. Time-on-page is still filled in server-side
	   from the next pageview, and "active now" means a pageview in the last few
	   minutes. */
	function beacon() {
		if ( ! trackingActive() ) {
			return;
		}
		// Keep the session token available to forms even on pageviews we don't
		// record, so form submissions still link to a session.
		runtime.token = getToken();
		runtime.active = true;

		// Sampling: only a share of pageviews actually send a beacon. On a
		// high-traffic cached site this is the biggest lever on origin load —
		// e.g. 25 means one origin request for every four pageviews.
		var rate = cfg.sampleRate || 100;
		if ( rate < 100 && Math.random() * 100 >= rate ) {
			return;
		}

		send( '/beacon', {
			session: runtime.token,
			consent: cfg.consentMode ? ( hasConsent() ? 1 : 0 ) : 1,
			post_id: cfg.postId || 0,
			url: location.href,
			title: document.title,
			referrer: document.referrer || '',
			viewport: window.innerWidth + 'x' + window.innerHeight
		}, true );
	}

	// Expose a session token to forms even before the beacon fires.
	if ( trackingActive() ) {
		runtime.token = getToken();
	}

	// Staff presence (admins only): a single report on load, no timer.
	if ( cfg.presence ) {
		fetch( cfg.restUrl.replace( /\/$/, '' ) + '/presence', {
			method: 'POST',
			credentials: 'same-origin',
			keepalive: true,
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.restNonce || '' },
			body: JSON.stringify( { title: document.title, url: location.href, post_id: cfg.postId || 0 } )
		} ).catch( function () {} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', beacon );
	} else {
		beacon();
	}
} )();
