/**
 * Cayden Link Shortener — in-admin guided tour.
 *
 * A dependency-free spotlight tour: it dims the screen, highlights a real
 * element, and shows a tooltip with Back / Next / End controls. It walks across
 * multiple admin screens by remembering its place in localStorage and
 * navigating between pages. Every path is wrapped so it can never break wp-admin.
 */
( function () {
	'use strict';

	var CFG = window.acpsLsTour || null;
	if ( ! CFG || ! CFG.stops ) {
		return;
	}

	var STORE = 'acpsLsTourState';
	var i18n = CFG.i18n || {};
	var stops = CFG.stops;
	var screen = CFG.screen || '';

	/* ---- tiny helpers ---- */
	function log() { /* no-op; never throw from the tour */ }

	function readState() {
		try {
			var raw = window.localStorage.getItem( STORE );
			return raw ? JSON.parse( raw ) : null;
		} catch ( e ) { return null; }
	}
	function writeState( s ) {
		try { window.localStorage.setItem( STORE, JSON.stringify( s ) ); } catch ( e ) {}
	}
	function clearState() {
		try { window.localStorage.removeItem( STORE ); } catch ( e ) {}
	}

	function stopByKey( key ) {
		for ( var i = 0; i < stops.length; i++ ) {
			if ( stops[ i ].key === key ) { return { stop: stops[ i ], index: i }; }
		}
		return null;
	}

	/* ---- DOM building ---- */
	var els = {};
	function build() {
		if ( els.root ) { return; }
		var root = document.createElement( 'div' );
		root.className = 'acps-ls-tour';
		root.setAttribute( 'role', 'dialog' );
		root.setAttribute( 'aria-modal', 'true' );
		root.innerHTML =
			'<div class="acps-ls-tour-veil"></div>' +
			'<div class="acps-ls-tour-ring"></div>' +
			'<div class="acps-ls-tour-pop">' +
				'<div class="acps-ls-tour-progress"></div>' +
				'<h3 class="acps-ls-tour-title"></h3>' +
				'<div class="acps-ls-tour-body"></div>' +
				'<div class="acps-ls-tour-controls">' +
					'<button type="button" class="button-link acps-ls-tour-end"></button>' +
					'<span class="acps-ls-tour-spacer"></span>' +
					'<button type="button" class="button acps-ls-tour-back"></button>' +
					'<button type="button" class="button button-primary acps-ls-tour-next"></button>' +
				'</div>' +
			'</div>';
		document.body.appendChild( root );

		els.root = root;
		els.veil = root.querySelector( '.acps-ls-tour-veil' );
		els.ring = root.querySelector( '.acps-ls-tour-ring' );
		els.pop = root.querySelector( '.acps-ls-tour-pop' );
		els.progress = root.querySelector( '.acps-ls-tour-progress' );
		els.title = root.querySelector( '.acps-ls-tour-title' );
		els.body = root.querySelector( '.acps-ls-tour-body' );
		els.end = root.querySelector( '.acps-ls-tour-end' );
		els.back = root.querySelector( '.acps-ls-tour-back' );
		els.next = root.querySelector( '.acps-ls-tour-next' );

		els.end.textContent = i18n.end || 'End tour';
		els.back.textContent = i18n.back || 'Back';

		els.end.addEventListener( 'click', finish );
		els.back.addEventListener( 'click', function () { go( -1 ); } );
		els.next.addEventListener( 'click', function () { go( 1 ); } );
		els.veil.addEventListener( 'click', function () { /* keep modal; ignore */ } );
		window.addEventListener( 'resize', reposition );
		window.addEventListener( 'scroll', reposition, true );
		document.addEventListener( 'keydown', onKey );
	}

	function onKey( e ) {
		if ( ! els.root || els.root.style.display === 'none' ) { return; }
		if ( e.key === 'Escape' ) { finish(); }
		else if ( e.key === 'ArrowRight' ) { go( 1 ); }
		else if ( e.key === 'ArrowLeft' ) { go( -1 ); }
	}

	/* ---- tour run state (this page = one stop) ---- */
	var run = { steps: [], i: 0, mode: 'single', stopKey: '' };

	function startOnThisScreen( mode, stopKey ) {
		var found = stopByKey( screen );
		if ( ! found ) { clearState(); return; }
		run.steps = ( found.stop.steps || [] ).slice();
		run.i = 0;
		run.mode = mode;
		run.stopKey = screen;
		if ( ! run.steps.length ) { advanceStopOrFinish(); return; }
		build();
		els.root.style.display = '';
		render();
	}

	function target( sel ) {
		if ( ! sel ) { return null; }
		try { return document.querySelector( sel ); } catch ( e ) { return null; }
	}

	function render() {
		var step = run.steps[ run.i ];
		if ( ! step ) { advanceStopOrFinish(); return; }

		els.title.innerHTML = step.title || '';
		els.body.innerHTML = step.html || '';
		var total = run.steps.length;
		els.progress.textContent = ( i18n.stepfmt || 'Step %1$d of %2$d' )
			.replace( '%1$d', run.i + 1 ).replace( '%2$d', total );

		// Last step of a full tour that has more stops -> "Take me there";
		// last step of the final stop -> "Finish".
		var isLastStep = run.i === total - 1;
		var moreStops = run.mode === 'full' && nextStopIndex() !== -1;
		els.next.textContent = isLastStep
			? ( moreStops ? ( i18n.gonext || 'Next →' ) : ( i18n.done || 'Finish' ) )
			: ( i18n.next || 'Next →' );
		els.back.style.visibility = run.i === 0 ? 'hidden' : '';

		var t = target( step.target );
		if ( t ) {
			scrollTo( t, function () { placeAt( t ); } );
		} else {
			placeCentered();
		}
	}

	function scrollTo( el, cb ) {
		try {
			var r = el.getBoundingClientRect();
			var vh = window.innerHeight || document.documentElement.clientHeight;
			if ( r.top < 80 || r.bottom > vh - 40 ) {
				el.scrollIntoView( { block: 'center', behavior: 'smooth' } );
				setTimeout( cb, 320 );
				return;
			}
		} catch ( e ) {}
		cb();
	}

	function placeAt( el ) {
		try {
			var r = el.getBoundingClientRect();
			var pad = 6;
			// The ring's big box-shadow does the dimming; hide the flat veil so the
			// highlighted element stays bright and visible.
			els.veil.style.display = 'none';
			els.ring.style.display = '';
			els.ring.style.top = ( r.top - pad ) + 'px';
			els.ring.style.left = ( r.left - pad ) + 'px';
			els.ring.style.width = ( r.width + pad * 2 ) + 'px';
			els.ring.style.height = ( r.height + pad * 2 ) + 'px';

			// Position the popover: prefer below, else above, else centered.
			els.pop.style.position = 'fixed';
			var popW = Math.min( 360, ( window.innerWidth || 800 ) - 24 );
			els.pop.style.width = popW + 'px';
			var top = r.bottom + 12;
			var left = Math.max( 12, Math.min( r.left, ( window.innerWidth - popW - 12 ) ) );
			var vh = window.innerHeight || 600;
			// Estimate popover height after paint.
			var ph = els.pop.offsetHeight || 180;
			if ( top + ph > vh - 12 ) {
				top = r.top - ph - 12;
				if ( top < 12 ) { // doesn't fit above either -> center
					return placeCentered( true );
				}
			}
			els.pop.style.top = top + 'px';
			els.pop.style.left = left + 'px';
			els.pop.style.transform = '';
		} catch ( e ) {
			placeCentered( true );
		}
	}

	function placeCentered( keepRing ) {
		if ( keepRing ) {
			// Target exists but the popover didn't fit beside it: keep the ring
			// (it dims), hide the flat veil.
			els.veil.style.display = 'none';
		} else {
			// No target for this step: dim the whole screen with the veil.
			els.ring.style.display = 'none';
			els.veil.style.display = '';
		}
		els.pop.style.position = 'fixed';
		els.pop.style.top = '50%';
		els.pop.style.left = '50%';
		els.pop.style.width = Math.min( 380, ( window.innerWidth || 800 ) - 24 ) + 'px';
		els.pop.style.transform = 'translate(-50%,-50%)';
	}

	function reposition() {
		if ( ! els.root || els.root.style.display === 'none' ) { return; }
		var step = run.steps[ run.i ];
		if ( ! step ) { return; }
		var t = target( step.target );
		if ( t ) { placeAt( t ); } else { placeCentered(); }
	}

	function go( dir ) {
		var n = run.i + dir;
		if ( n < 0 ) { return; }
		if ( n >= run.steps.length ) { advanceStopOrFinish(); return; }
		run.i = n;
		render();
	}

	function nextStopIndex() {
		var found = stopByKey( run.stopKey );
		if ( ! found ) { return -1; }
		return ( found.index + 1 < stops.length ) ? found.index + 1 : -1;
	}

	function advanceStopOrFinish() {
		if ( run.mode === 'full' ) {
			var ni = nextStopIndex();
			if ( ni !== -1 ) {
				var next = stops[ ni ];
				writeState( { active: true, mode: 'full', stopKey: next.key } );
				// Navigate to the next screen; the tour resumes on load.
				window.location.href = next.url;
				return;
			}
		}
		finish();
	}

	function finish() {
		clearState();
		if ( els.root ) { els.root.style.display = 'none'; }
	}

	/* ---- entry points ---- */
	function startFromButton( value ) {
		var mode = ( value === 'full' ) ? 'full' : 'single';
		var startKey = ( value === 'full' ) ? ( stops[ 0 ] && stops[ 0 ].key ) : value;
		var found = stopByKey( startKey );
		if ( ! found ) { return; }
		writeState( { active: true, mode: mode, stopKey: startKey } );
		if ( startKey === screen ) {
			startOnThisScreen( mode, startKey );
		} else {
			window.location.href = found.stop.url;
		}
	}

	function boot() {
		try {
			// Wire any "start tour" buttons on this page.
			var btns = document.querySelectorAll( '[data-acps-tour-start]' );
			for ( var i = 0; i < btns.length; i++ ) {
				( function ( b ) {
					b.addEventListener( 'click', function ( e ) {
						e.preventDefault();
						startFromButton( b.getAttribute( 'data-acps-tour-start' ) );
					} );
				} )( btns[ i ] );
			}

			// URL trigger (?acps_ls_start_tour=full) from the first-run notice.
			var qs = window.location.search || '';
			var m = qs.match( /[?&]acps_ls_start_tour=([a-z]+)/ );
			if ( m ) {
				startFromButton( m[ 1 ] );
				return;
			}

			// Resume an in-progress tour if this screen is the expected stop.
			var st = readState();
			if ( st && st.active && st.stopKey === screen ) {
				startOnThisScreen( st.mode || 'full', st.stopKey );
			}
		} catch ( e ) {
			log( e );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
