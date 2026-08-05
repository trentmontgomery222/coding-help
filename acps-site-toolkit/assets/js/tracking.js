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
	var UID_COOKIE = 'acps_st_uid';
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

	/* Persistent unique-user id (per browser). Stored in BOTH a long-lived
	   cookie AND localStorage and reconciled on every visit, so clearing just
	   cookies (or just site cache) does NOT create a new user — the id is
	   restored from whichever store survives. Only wiping all site data resets
	   it. Cookie lifetimes are capped near 400 days, so we renew each visit. */
	function lsGet( key ) {
		try { return window.localStorage.getItem( key ); } catch ( e ) { return null; }
	}
	function lsSet( key, val ) {
		try { window.localStorage.setItem( key, val ); } catch ( e ) {}
	}
	function getUid() {
		var valid = /^[a-f0-9]{40}$/;
		var u = readCookie( UID_COOKIE );
		if ( ! valid.test( u ) ) {
			u = lsGet( UID_COOKIE ); // cookie cleared → restore from localStorage.
		}
		if ( ! valid.test( u ) ) {
			u = makeToken(); // neither survived → genuinely new.
		}
		// Write back to both stores so a future clear of one is recoverable.
		writeCookie( UID_COOKIE, u, 400 * 24 * 60 ); // ~400 days in minutes.
		lsSet( UID_COOKIE, u );
		return u;
	}

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
		uid: '',
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
		runtime.uid = getUid();
		runtime.active = true;

		send( '/beacon', {
			session: runtime.token,
			uid: runtime.uid,
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

	// Heartbeat: keep the session "active" while the tab is visible, so the live
	// view stays accurate even when the visitor doesn't navigate. Stops after a
	// stretch of inactivity to avoid pinging forever on an abandoned tab.
	var lastInteraction = Date.now();
	[ 'mousemove', 'keydown', 'scroll', 'touchstart', 'click' ].forEach( function ( evt ) {
		window.addEventListener( evt, function () { lastInteraction = Date.now(); }, { passive: true } );
	} );
	function heartbeat() {
		if ( ! runtime.active || ! runtime.token ) {
			return;
		}
		if ( document.visibilityState !== 'visible' ) {
			return;
		}
		// Only if the visitor interacted in the last 5 minutes.
		if ( Date.now() - lastInteraction > 5 * 60 * 1000 ) {
			return;
		}
		send( '/ping', { session: runtime.token }, false );
	}
	setInterval( heartbeat, 45 * 1000 );

	// Staff presence: logged-in admins report their location to a SEPARATE
	// endpoint (not analytics) so admins can see where each other are. Needs the
	// REST nonce for cookie auth.
	if ( cfg.presence ) {
		var presenceUrl = cfg.restUrl.replace( /\/$/, '' ) + '/presence';
		var sendPresence = function () {
			if ( document.visibilityState !== 'visible' ) {
				return;
			}
			fetch( presenceUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.restNonce || '' },
				body: JSON.stringify( {
					title: document.title,
					url: location.href,
					post_id: cfg.postId || 0
				} )
			} ).catch( function () {} );
		};
		sendPresence();
		setInterval( sendPresence, 45 * 1000 );
	}

	// Expose a session token to forms only when tracking is genuinely active
	// (not suppressed for admins, and consent satisfied).
	if ( trackingActive() ) {
		runtime.token = getToken();
		runtime.uid = getUid();
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
