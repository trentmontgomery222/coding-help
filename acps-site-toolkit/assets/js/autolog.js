/**
 * Cayden Form Manager — auto-log beacon.
 *
 * Fires once on page load and POSTs the visitor's device/session context to the
 * uncached /auto-log endpoint, which records it as an entry. Used on 404 pages
 * so every "page not found" hit is captured with enough detail to reproduce it.
 * All per-visitor data is gathered here in the browser (or server-side from the
 * real request), so it works even when the page HTML is edge-cached.
 */
( function () {
	'use strict';

	var cfg = window.ACPS_ST_AUTOLOG || {};
	if ( ! cfg.url ) {
		return;
	}

	function cookie( name ) {
		var m = document.cookie.match( '(^|;)\\s*' + name + '\\s*=\\s*([^;]+)' );
		return m ? decodeURIComponent( m.pop() ) : '';
	}
	function tz() {
		try {
			return Intl.DateTimeFormat().resolvedOptions().timeZone || '';
		} catch ( e ) {
			return '';
		}
	}

	var payload = {
		url:      location.href,
		referrer: document.referrer || '',
		ua:       navigator.userAgent || '',
		viewport: window.innerWidth + 'x' + window.innerHeight,
		screen:   ( screen.width + 'x' + screen.height ) + ' @' + ( window.devicePixelRatio || 1 ) + 'x',
		language: navigator.language || '',
		timezone: tz(),
		platform: ( navigator.userAgentData && navigator.userAgentData.platform ) || navigator.platform || '',
		session:  cookie( 'acps_st_sid' ),
		post_id:  cfg.postId || 0
	};

	var body = JSON.stringify( payload );

	// sendBeacon is non-blocking and survives the page being closed.
	try {
		if ( navigator.sendBeacon ) {
			navigator.sendBeacon( cfg.url, new Blob( [ body ], { type: 'application/json' } ) );
			return;
		}
	} catch ( e ) {}

	fetch( cfg.url, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: body,
		keepalive: true,
		credentials: 'same-origin'
	} ).catch( function () {} );
} )();
