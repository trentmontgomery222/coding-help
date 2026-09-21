/**
 * Front end runtime for ACPS Alert Popups.
 *
 * Handles triggers, how often an alert may reappear, focus management and
 * closing. Beaver Builder owns everything inside the alert; this file only
 * owns the shell around it.
 */
( function () {
	'use strict';

	var data = window.ACPSAlertsData || {};
	var alerts = data.alerts || [];
	var storageKey = 'acps_alert_';
	var openStack = [];
	var lastFocused = null;

	/**
	 * Reads a dismissal record for an alert.
	 *
	 * @param {number} id Alert ID.
	 * @return {Object|null} Record, or null when nothing is stored.
	 */
	function readRecord( id ) {
		var raw = null;

		try {
			if ( 'cookie' === data.storage ) {
				raw = readCookie( storageKey + id );
			} else if ( 'session' === data.storage ) {
				raw = window.sessionStorage.getItem( storageKey + id );
			} else {
				raw = window.localStorage.getItem( storageKey + id );
			}
		} catch ( e ) {
			return null;
		}

		if ( ! raw ) {
			return null;
		}

		try {
			return JSON.parse( raw );
		} catch ( e ) {
			return null;
		}
	}

	/**
	 * Stores a dismissal record for an alert.
	 *
	 * @param {number} id     Alert ID.
	 * @param {Object} record Record to store.
	 */
	function writeRecord( id, record ) {
		var raw = JSON.stringify( record );

		try {
			if ( 'cookie' === data.storage ) {
				writeCookie( storageKey + id, raw, 365 );
			} else if ( 'session' === data.storage ) {
				window.sessionStorage.setItem( storageKey + id, raw );
			} else {
				window.localStorage.setItem( storageKey + id, raw );
			}
		} catch ( e ) {
			// Storage can be unavailable (private mode, blocked cookies). The
			// alert simply shows again next time.
		}
	}

	/**
	 * Reads a cookie value.
	 *
	 * @param {string} name Cookie name.
	 * @return {string|null} Value or null.
	 */
	function readCookie( name ) {
		var match = document.cookie.match( new RegExp( '(^|;\\s*)' + name + '=([^;]*)' ) );

		return match ? decodeURIComponent( match[ 2 ] ) : null;
	}

	/**
	 * Writes a cookie.
	 *
	 * @param {string} name  Cookie name.
	 * @param {string} value Value.
	 * @param {number} days  Lifetime in days.
	 */
	function writeCookie( name, value, days ) {
		var expires = new Date( Date.now() + days * 86400000 ).toUTCString();

		document.cookie = name + '=' + encodeURIComponent( value ) + ';expires=' + expires + ';path=/;SameSite=Lax';
	}

	/**
	 * Records what this visitor now knows about an alert.
	 *
	 * Merges into whatever is already stored rather than replacing it, so
	 * closing an alert keeps the moment it was shown.
	 *
	 * @param {number} id     Alert ID.
	 * @param {Object} fields Fields to write.
	 */
	function remember( id, fields ) {
		var cfg = config( id );

		if ( ! cfg || data.isPreview ) {
			return;
		}

		var record = readRecord( id ) || {};

		record.session = sessionId();
		record.version = cfg.version || '';

		for ( var key in fields ) {
			if ( Object.prototype.hasOwnProperty.call( fields, key ) ) {
				record[ key ] = fields[ key ];
			}
		}

		writeRecord( id, record );
	}

	/**
	 * Whether an alert is still allowed to auto-open for this visitor.
	 *
	 * @param {Object} config Alert config.
	 * @return {boolean} True when it may show.
	 */
	function mayShow( config ) {
		if ( data.isPreview || 'always' === config.frequency ) {
			return true;
		}

		var record = readRecord( config.id );

		// Being shown it is what counts, not closing it. A record written by an
		// older version only has the dismissal, so that still reads as seen.
		var seenAt = record ? ( record.seenAt || record.dismissedAt ) : 0;

		if ( ! seenAt ) {
			return true;
		}

		// "Once, then never again" has to mean exactly that, so it is settled
		// before the version is looked at. Everything else treats a changed
		// alert as a new one worth showing.
		if ( 'once' === config.frequency ) {
			return false;
		}

		// A record written before versioning has no version at all, which counts
		// as changed rather than as a match — otherwise those visitors would
		// never be shown the alert again.
		var changed = !! config.version && record.version !== config.version;

		if ( changed ) {
			return true;
		}

		// "Once, until I change it": seen, and nothing has changed since.
		if ( 'edit' === config.frequency ) {
			return false;
		}

		if ( 'session' === config.frequency ) {
			return record.session !== sessionId();
		}

		if ( 'days' === config.frequency ) {
			var elapsed = Date.now() - seenAt;

			return elapsed > config.frequencyDays * 86400000;
		}

		// Anything else is an unrecognised or blank frequency. The visitor has
		// been shown this already and nothing has changed, so err on the side of
		// leaving them alone rather than nagging on every page — only "always",
		// handled at the very top, keeps reappearing. This is what stops a
		// setting that never got written from behaving like "show every time".
		return false;
	}

	/**
	 * A stable ID for the current browser session.
	 *
	 * @return {string} Session ID.
	 */
	function sessionId() {
		var key = 'acps_alert_session';
		var id;

		try {
			id = window.sessionStorage.getItem( key );

			if ( ! id ) {
				id = String( Date.now() ) + String( Math.random() ).slice( 2, 8 );
				window.sessionStorage.setItem( key, id );
			}
		} catch ( e ) {
			id = 'no-storage';
		}

		return id;
	}

	/**
	 * The element for an alert.
	 *
	 * @param {number} id Alert ID.
	 * @return {HTMLElement|null} Alert element.
	 */
	function element( id ) {
		return document.getElementById( 'acps-alert-' + id );
	}

	/**
	 * The config for an alert.
	 *
	 * @param {number} id Alert ID.
	 * @return {Object|null} Alert config.
	 */
	function config( id ) {
		for ( var i = 0; i < alerts.length; i++ ) {
			if ( parseInt( alerts[ i ].id, 10 ) === parseInt( id, 10 ) ) {
				return alerts[ i ];
			}
		}

		return null;
	}

	/**
	 * Resolves a dotted global path, e.g. "FLBuilderPopup.open".
	 *
	 * @param {string} path Dotted path.
	 * @return {Function|null} The function, or null.
	 */
	function resolveCallback( path ) {
		if ( ! path ) {
			return null;
		}

		var parts = path.split( '.' );
		var scope = window;

		for ( var i = 0; i < parts.length; i++ ) {
			if ( ! scope || ! ( parts[ i ] in scope ) ) {
				return null;
			}

			scope = scope[ parts[ i ] ];
		}

		return 'function' === typeof scope ? scope : null;
	}

	/**
	 * Opens an alert.
	 *
	 * @param {number} id Alert ID.
	 */
	function open( id ) {
		var el = element( id );
		var cfg = config( id );

		if ( ! el || el.classList.contains( 'is-open' ) ) {
			return;
		}

		var native = resolveCallback( data.nativeOpen );

		// Written before anything is shown, because from here on the visitor
		// has seen it — whether they close it, follow the link inside it, or
		// just click away to another page.
		remember( id, { seenAt: Date.now() } );

		if ( native ) {
			// Beaver Builder owns the popup chrome in this mode.
			native( id );
			document.dispatchEvent( new CustomEvent( 'acps-alert:open', { detail: { id: id, config: cfg } } ) );

			return;
		}

		lastFocused = document.activeElement;

		el.hidden = false;
		el.classList.add( 'is-open' );
		document.body.classList.add( 'acps-alert-is-open' );
		openStack.push( id );

		focusFirst( el );

		document.dispatchEvent( new CustomEvent( 'acps-alert:open', { detail: { id: id, config: cfg } } ) );
	}

	/**
	 * Closes an alert and remembers the dismissal.
	 *
	 * @param {number}  id    Alert ID.
	 * @param {boolean} store Whether to record the dismissal.
	 */
	function close( id, store ) {
		var el = element( id );
		var cfg = config( id );

		if ( ! el || ! el.classList.contains( 'is-open' ) ) {
			return;
		}

		el.classList.remove( 'is-open' );
		el.hidden = true;
		openStack = openStack.filter( function ( openId ) {
			return parseInt( openId, 10 ) !== parseInt( id, 10 );
		} );

		if ( ! openStack.length ) {
			document.body.classList.remove( 'acps-alert-is-open' );
		}

		if ( false !== store ) {
			remember( id, { dismissedAt: Date.now() } );
		}

		if ( lastFocused && lastFocused.focus ) {
			lastFocused.focus();
		}

		document.dispatchEvent( new CustomEvent( 'acps-alert:close', { detail: { id: id, config: cfg } } ) );
	}

	/**
	 * Focusable children of an element.
	 *
	 * @param {HTMLElement} el Container.
	 * @return {Array} Focusable elements.
	 */
	function focusable( el ) {
		var selector = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

		return Array.prototype.slice.call( el.querySelectorAll( selector ) ).filter( function ( node ) {
			return null !== node.offsetParent;
		} );
	}

	/**
	 * Moves focus into an alert.
	 *
	 * @param {HTMLElement} el Alert element.
	 */
	function focusFirst( el ) {
		var targets = focusable( el );

		if ( targets.length ) {
			targets[ 0 ].focus();

			return;
		}

		el.setAttribute( 'tabindex', '-1' );
		el.focus();
	}

	/**
	 * Keeps Tab inside the topmost open alert.
	 *
	 * @param {KeyboardEvent} event Key event.
	 */
	function trapFocus( event ) {
		if ( 'Tab' !== event.key || ! openStack.length ) {
			return;
		}

		var el = element( openStack[ openStack.length - 1 ] );

		if ( ! el ) {
			return;
		}

		var targets = focusable( el );

		if ( ! targets.length ) {
			return;
		}

		var first = targets[ 0 ];
		var last = targets[ targets.length - 1 ];

		if ( event.shiftKey && document.activeElement === first ) {
			event.preventDefault();
			last.focus();
		} else if ( ! event.shiftKey && document.activeElement === last ) {
			event.preventDefault();
			first.focus();
		}
	}

	/**
	 * Schedules an alert according to its trigger.
	 *
	 * @param {Object} cfg Alert config.
	 */
	function schedule( cfg ) {
		if ( 'click' === cfg.trigger || ! mayShow( cfg ) ) {
			return;
		}

		if ( 'load' === cfg.trigger ) {
			open( cfg.id );

			return;
		}

		if ( 'delay' === cfg.trigger ) {
			window.setTimeout( function () {
				open( cfg.id );
			}, Math.max( 0, cfg.triggerDelay ) * 1000 );

			return;
		}

		if ( 'scroll' === cfg.trigger ) {
			var onScroll = function () {
				var height = document.documentElement.scrollHeight - window.innerHeight;
				var depth = height > 0 ? ( window.scrollY / height ) * 100 : 100;

				if ( depth >= cfg.triggerScroll ) {
					window.removeEventListener( 'scroll', onScroll );
					open( cfg.id );
				}
			};

			window.addEventListener( 'scroll', onScroll, { passive: true } );
			onScroll();

			return;
		}

		if ( 'exit' === cfg.trigger ) {
			var onLeave = function ( event ) {
				if ( event.clientY > 0 ) {
					return;
				}

				document.removeEventListener( 'mouseout', onLeave );
				open( cfg.id );
			};

			document.addEventListener( 'mouseout', onLeave );
		}
	}

	/**
	 * Safe closest() lookup for any event target.
	 *
	 * @param {EventTarget} target   Event target.
	 * @param {string}      selector CSS selector.
	 * @return {HTMLElement|null} Matching ancestor, or null.
	 */
	function closestFrom( target, selector ) {
		if ( ! target || 'function' !== typeof target.closest ) {
			return null;
		}

		return target.closest( selector );
	}

	/**
	 * Binds close buttons, overlays, triggers and the Escape key.
	 */
	function bindEvents() {
		document.addEventListener( 'click', function ( event ) {
			var closer = closestFrom( event.target, '[data-acps-close]' );

			if ( closer ) {
				var closeEl = closer.closest( '.acps-alert' );

				if ( closeEl ) {
					event.preventDefault();
					close( closeEl.getAttribute( 'data-alert' ), true );
				}

				return;
			}

			var overlay = closestFrom( event.target, '[data-acps-overlay]' );

			if ( overlay ) {
				var overlayEl = overlay.closest( '.acps-alert' );
				var overlayCfg = overlayEl ? config( overlayEl.getAttribute( 'data-alert' ) ) : null;

				if ( overlayCfg && overlayCfg.overlayClose ) {
					close( overlayEl.getAttribute( 'data-alert' ), true );
				}

				return;
			}

			var trigger = closestFrom( event.target, '.acps-alert-open' );

			if ( trigger ) {
				event.preventDefault();
				open( trigger.getAttribute( 'data-alert' ) );
			}
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && openStack.length ) {
				var id = openStack[ openStack.length - 1 ];
				var cfg = config( id );

				if ( ! cfg || cfg.escClose ) {
					close( id, true );
				}
			}

			trapFocus( event );
		} );
	}

	/**
	 * Boots the runtime.
	 */
	function start() {
		bindEvents();

		alerts.forEach( schedule );
	}

	window.ACPSAlerts = {
		open: open,
		close: close,
		config: config
	};

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
