/**
 * Cayden Form Manager — programmatic event logging.
 *
 * Exposes a global you can call from any page's own JavaScript:
 *
 *   acpsLog( { event: 'signup_click', plan: 'pro' } );
 *   acpsLog( { step: 3 }, { form: 'my-form-slug' } );   // target another form
 *
 * Each call POSTs your fields to the uncached /log endpoint, which stores them
 * as an entry. Session/device context is added server-side. Cache-safe: the
 * page HTML can be edge-cached; this runs per call in the browser.
 */
( function () {
	'use strict';

	var cfg = window.ACPS_ST_LOG || {};

	function cookie( name ) {
		var m = document.cookie.match( '(^|;)\\s*' + name + '\\s*=\\s*([^;]+)' );
		return m ? decodeURIComponent( m.pop() ) : '';
	}

	/**
	 * Send a log event.
	 *
	 * @param {Object} fields   Key/value data you choose (values are stringified).
	 * @param {Object} [options] { form: 'slug' } to target a specific form.
	 * @returns {boolean} false if logging isn't configured/enabled.
	 */
	window.acpsLog = function ( fields, options ) {
		if ( ! cfg.url ) {
			return false;
		}
		options = options || {};

		var payload = {
			fields:   ( fields && typeof fields === 'object' ) ? fields : {},
			form:     options.form || cfg.form || '',
			url:      location.href,
			session:  cookie( 'acps_st_sid' ),
			post_id:  cfg.postId || 0
		};

		var body = JSON.stringify( payload );

		// Prefer sendBeacon (non-blocking, survives navigation). Fall back to fetch.
		try {
			if ( navigator.sendBeacon ) {
				navigator.sendBeacon( cfg.url, new Blob( [ body ], { type: 'application/json' } ) );
				return true;
			}
		} catch ( e ) {}

		fetch( cfg.url, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: body,
			keepalive: true,
			credentials: 'same-origin'
		} ).catch( function () {} );

		return true;
	};
} )();
