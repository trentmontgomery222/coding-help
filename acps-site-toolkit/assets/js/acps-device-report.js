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
			gpuScore:     cached.gpuScore,
			timing:       cached.timing || null
		} );
		return;
	}

	function mm( q ) {
		try { return !! ( window.matchMedia && matchMedia( q ).matches ); } catch ( e ) { return false; }
	}
	function major( v ) {
		return v ? String( v ).split( '.' )[0] : '';
	}

	// Collect device stats (no permission prompts). Client Hints give accurate
	// OS/browser/arch/model on Chromium; everything else has a universal path.
	function collectDeviceStats() {
		var s = {};
		s.languages     = ( navigator.languages || [ navigator.language ] ).slice( 0, 5 );
		try { s.timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch ( e ) { s.timezone = ''; }
		s.tzOffset      = new Date().getTimezoneOffset();
		s.cpuCores      = navigator.hardwareConcurrency || null;
		s.deviceMemoryGB = navigator.deviceMemory || null;
		s.maxTouchPoints = navigator.maxTouchPoints || 0;
		s.pointerCoarse = mm( '(pointer: coarse)' );
		s.hover         = mm( '(hover: hover)' );
		s.colorDepth    = screen.colorDepth;
		s.screenW       = screen.width;
		s.screenH       = screen.height;
		s.availW        = screen.availWidth;
		s.availH        = screen.availHeight;
		s.dpr           = window.devicePixelRatio || 1;
		s.orientation   = ( screen.orientation && screen.orientation.type ) || '';
		s.multiMonitor  = ( typeof screen.isExtended === 'boolean' ) ? screen.isExtended : null;
		s.colorScheme   = mm( '(prefers-color-scheme: dark)' ) ? 'dark' : 'light';
		s.reducedMotion = mm( '(prefers-reduced-motion: reduce)' );
		try {
			var c = navigator.connection;
			if ( c ) { s.netType = c.effectiveType; s.downlink = c.downlink; s.rtt = c.rtt; s.saveData = !! c.saveData; }
		} catch ( e ) {}

		var chain = Promise.resolve();
		// User-Agent Client Hints (Chromium).
		if ( navigator.userAgentData && navigator.userAgentData.getHighEntropyValues ) {
			s.mobile = !! navigator.userAgentData.mobile;
			chain = chain.then( function () {
				return navigator.userAgentData.getHighEntropyValues(
					[ 'platform', 'platformVersion', 'architecture', 'bitness', 'model', 'uaFullVersion', 'fullVersionList' ]
				).then( function ( uh ) {
					s.os          = uh.platform || '';
					s.osVersion   = uh.platformVersion || '';
					s.arch        = uh.architecture || '';
					s.bitness     = uh.bitness || '';
					s.model       = uh.model || '';
					var list = uh.fullVersionList || [];
					var b = null;
					for ( var i = 0; i < list.length; i++ ) {
						if ( ! /not.?a.?brand/i.test( list[i].brand ) ) { b = list[i]; break; }
					}
					if ( ! b && list.length ) { b = list[0]; }
					if ( b ) { s.browser = b.brand; s.browserVersion = b.version; }
					else { s.browserVersion = uh.uaFullVersion || ''; }
				} ).catch( function () {} );
			} );
		}
		// Rough storage quota.
		if ( navigator.storage && navigator.storage.estimate ) {
			chain = chain.then( function () {
				return navigator.storage.estimate().then( function ( est ) {
					if ( est && est.quota ) { s.storageQuotaMB = Math.round( est.quota / 1048576 ); }
				} ).catch( function () {} );
			} );
		}
		return chain.then( function () { return s; } );
	}

	// The STABLE subset that goes into the hash (nothing that changes between
	// visits — no benchmark, timing, network, window size, color-scheme, etc.).
	function stableHashInput( s, profile ) {
		return {
			os:     s.os || '',
			osv:    major( s.osVersion ),
			br:     s.browser || '',
			brv:    major( s.browserVersion ),
			arch:   s.arch || '',
			bits:   s.bitness || '',
			model:  s.model || '',
			mobile: !! s.mobile,
			scr:    ( s.screenW || 0 ) + 'x' + ( s.screenH || 0 ),
			depth:  s.colorDepth || 0,
			dpr:    s.dpr || 1,
			cores:  s.cpuCores || 0,
			mem:    s.deviceMemoryGB || 0,
			touch:  s.maxTouchPoints || 0,
			tz:     s.timezone || '',
			langs:  ( s.languages || [] ).join( ',' ),
			tier:   ( profile && profile.tier ) || '',
			maxTex: ( profile && profile.maxTextureSize ) || 0,
			webgl2: !! ( profile && profile.webgl2 )
		};
	}

	// TIER 3 — full probe when idle. Runs only when there's no valid cache; the
	// result is written to localStorage (7 days) + sessionStorage so it never
	// runs again until the cache expires.
	function runProbe() {
		if ( typeof GPUFingerprint === 'undefined' || ! GPUFingerprint.get ) {
			return;
		}
		Promise.all( [
			GPUFingerprint.profile ? GPUFingerprint.profile() : Promise.resolve( { supported: false } ),
			collectDeviceStats(),
			GPUFingerprint.timing ? GPUFingerprint.timing() : Promise.resolve( null )
		] ).then( function ( results ) {
			var profile = results[0];
			var stats   = results[1];
			var timing  = results[2];

			// Fold the stable device stats into the GPU hash → combined identity.
			return GPUFingerprint.get( stableHashInput( stats, profile ) ).then( function ( fp ) {
				if ( ! fp || ! fp.supported ) {
					return;
				}
				// Store the full stats (+ GPU capabilities) for display/search.
				var caps = Object.assign( {}, ( profile && profile.supported ) ? profile : {}, stats );

				var payload = {
					hash:         fp.hash,
					rendererInfo: fp.rendererInfo,
					capabilities: caps,
					gpuScore:     ( profile && profile.supported && profile.benchmarkScore !== null ) ? profile.benchmarkScore : null,
					timing:       timing || null
				};
				try {
					localStorage.setItem( CACHE_KEY, JSON.stringify(
						Object.assign( { expires: Date.now() + CACHE_TTL }, payload )
					) );
				} catch ( e ) {}
				send( payload );
			} );
		} ).catch( function () {} );
	}

	if ( 'requestIdleCallback' in window ) {
		requestIdleCallback( runProbe, { timeout: 4000 } );
	} else {
		setTimeout( runProbe, 500 );
	}
}() );
