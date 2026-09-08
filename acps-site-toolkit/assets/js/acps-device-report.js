/**
 * Cayden Form Manager — device fingerprint reporter.
 *
 * Adapted from the "Device Bridge" reporter. Runs the GPU/WebGL probe
 * (acps-gpu-fingerprint.js + acps-gpu-benchmark.js) as rarely as possible and
 * never during page load, then POSTs the resulting device hash + hardware
 * profile to /wp-json/acps-st/v1/device, where it's attached to the visitor.
 *
 * Three tiers (same performance design as the original):
 *   1. sessionStorage fast-path — already reported this session → exit.
 *   2. localStorage 7-day cache — valid cached hash → one fetch, no WebGL.
 *   3. requestIdleCallback slow-path — first visit / stale cache → full probe
 *      when the browser is idle, then cache for next time.
 *
 * Config: window.ACPS_ST_DEVICE = { url }.  Cache-safe: everything runs in the
 * browser and posts to an uncached endpoint, so the page HTML can be edge-cached.
 */
( function () {
	'use strict';

	var cfg = window.ACPS_ST_DEVICE || {};
	if ( ! cfg.url ) {
		return;
	}

	var SESSION_KEY = 'acps_dev_done';
	var CACHE_KEY   = 'acps_dev_fp';
	var CACHE_TTL   = 7 * 24 * 60 * 60 * 1000; // 7 days.

	function cookie( name ) {
		var m = document.cookie.match( '(^|;)\\s*' + name + '\\s*=\\s*([^;]+)' );
		return m ? decodeURIComponent( m.pop() ) : '';
	}

	// TIER 1 — already reported this session.
	try {
		if ( sessionStorage.getItem( SESSION_KEY ) ) {
			return;
		}
	} catch ( e ) {}

	function send( payload ) {
		payload.session = cookie( 'acps_st_sid' );
		var body = JSON.stringify( payload );
		try {
			if ( navigator.sendBeacon ) {
				navigator.sendBeacon( cfg.url, new Blob( [ body ], { type: 'application/json' } ) );
				try { sessionStorage.setItem( SESSION_KEY, '1' ); } catch ( e ) {}
				return;
			}
		} catch ( e ) {}
		fetch( cfg.url, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: body,
			keepalive: true,
			credentials: 'same-origin'
		} ).then( function () {
			try { sessionStorage.setItem( SESSION_KEY, '1' ); } catch ( e ) {}
		} ).catch( function () {} );
	}

	// TIER 2 — valid cache, skip WebGL.
	var cached = null;
	try {
		var raw = localStorage.getItem( CACHE_KEY );
		if ( raw ) {
			var parsed = JSON.parse( raw );
			if ( parsed && parsed.expires > Date.now() ) {
				cached = parsed;
			}
		}
	} catch ( e ) {}

	if ( cached ) {
		send( {
			hash:         cached.hash,
			rendererInfo: cached.rendererInfo,
			capabilities: cached.capabilities,
			gpuScore:     cached.gpuScore
		} );
		return;
	}

	// TIER 3 — full probe when idle.
	function runProbe() {
		if ( typeof GPUFingerprint === 'undefined' || ! GPUFingerprint.get ) {
			return;
		}
		Promise.all( [
			GPUFingerprint.get(),
			GPUFingerprint.profile ? GPUFingerprint.profile() : Promise.resolve( { supported: false } )
		] ).then( function ( results ) {
			var fp      = results[0];
			var profile = results[1];
			if ( ! fp || ! fp.supported ) {
				return;
			}
			var payload = {
				hash:         fp.hash,
				rendererInfo: fp.rendererInfo,
				capabilities: ( profile && profile.supported ) ? profile : null,
				gpuScore:     ( profile && profile.supported && profile.benchmarkScore !== null ) ? profile.benchmarkScore : null
			};
			try {
				localStorage.setItem( CACHE_KEY, JSON.stringify(
					Object.assign( { expires: Date.now() + CACHE_TTL }, payload )
				) );
			} catch ( e ) {}
			send( payload );
		} ).catch( function () {} );
	}

	if ( 'requestIdleCallback' in window ) {
		requestIdleCallback( runProbe, { timeout: 4000 } );
	} else {
		setTimeout( runProbe, 500 );
	}
}() );
