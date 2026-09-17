/**
 * Cayden Form Manager — interactive admin tours ("show me how").
 *
 * A tiny, dependency-free coach-mark engine. It dims the screen, spotlights one
 * real control at a time, and shows a popover explaining it with Back / Next /
 * Done and a step counter — like a video, but pointing at the actual buttons.
 *
 * Tours are provided by the server in window.ACPS_ST_TOUR.tours, keyed by name.
 * A tour is a list of steps: { el: CSS selector (optional), title, html, side }.
 * A step whose element isn't on the current screen falls back to a centered
 * card, so a tour never breaks if something is toggled off.
 *
 * Launch: a click on any [data-acps-tour="name"] button, or ?acps_tour=name in
 * the URL (used by the Help page's launch links to auto-start after navigation).
 *
 * Accessibility: the popover is a focus-trapped dialog; Esc ends the tour and
 * returns focus to the launcher; Left/Right/Enter navigate; the spotlight is
 * decorative. Honors prefers-reduced-motion.
 */
( function () {
	'use strict';

	var cfg = window.ACPS_ST_TOUR || {};
	var TOURS = cfg.tours || {};
	var I18N = cfg.i18n || {};

	function t( key, fallback ) {
		return I18N[ key ] || fallback;
	}

	function ready( fn ) {
		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', fn );
		} else {
			fn();
		}
	}

	ready( function () {
		try {
			bindLaunchers();
			injectWelcome();
			autostart();
		} catch ( e ) {}
	} );

	// A one-time, dismissible welcome banner on the main dashboard that offers
	// the overview tour. Dismissal is remembered per-browser via localStorage.
	function injectWelcome() {
		if ( ! /[?&]page=acps-st(&|$)/.test( window.location.search ) ) {
			return; // Only the main (Feedback) landing screen.
		}
		if ( ! TOURS.overview ) { return; }
		try { if ( localStorage.getItem( 'acps_tour_welcomed' ) ) { return; } } catch ( e ) {}
		var wrap = document.querySelector( '.wrap.acps-admin' ) || document.querySelector( '.wrap' );
		if ( ! wrap || wrap.querySelector( '.acps-welcome' ) ) { return; }

		var h1 = wrap.querySelector( 'h1' );
		var box = document.createElement( 'div' );
		box.className = 'acps-welcome';
		box.innerHTML =
			'<div><h2>' + esc( t( 'welcomeTitle', 'New to Cayden Form Manager?' ) ) + '</h2>' +
			'<p>' + esc( t( 'welcomeBody', 'Take a quick guided tour and I’ll show you around — it takes about two minutes.' ) ) + '</p></div>' +
			'<div class="acps-welcome-actions">' +
				'<button type="button" class="button button-primary acps-welcome-go">' + esc( t( 'welcomeGo', 'Take the tour' ) ) + '</button>' +
				'<button type="button" class="button-link acps-welcome-x">' + esc( t( 'welcomeSkip', 'Maybe later' ) ) + '</button>' +
			'</div>';

		if ( h1 && h1.parentNode ) { h1.parentNode.insertBefore( box, h1.nextSibling ); }
		else { wrap.insertBefore( box, wrap.firstChild ); }

		function dismiss() {
			try { localStorage.setItem( 'acps_tour_welcomed', '1' ); } catch ( e ) {}
			remove( box );
		}
		box.querySelector( '.acps-welcome-go' ).addEventListener( 'click', function () {
			dismiss();
			new Tour( 'overview', null );
		} );
		box.querySelector( '.acps-welcome-x' ).addEventListener( 'click', dismiss );
	}

	function bindLaunchers() {
		var btns = document.querySelectorAll( '[data-acps-tour]' );
		Array.prototype.forEach.call( btns, function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				var name = btn.getAttribute( 'data-acps-tour' );
				if ( TOURS[ name ] ) {
					e.preventDefault();
					new Tour( name, btn );
				}
			} );
		} );
	}

	function autostart() {
		var name = cfg.autostart || paramTour();
		if ( name && TOURS[ name ] ) {
			// Let the page settle (fonts, layout) before measuring targets.
			setTimeout( function () { new Tour( name, null ); }, 350 );
		}
	}

	function paramTour() {
		var m = window.location.search.match( /[?&]acps_tour=([a-z0-9_-]+)/i );
		return m ? m[1] : '';
	}

	/* ---- Tour controller -------------------------------------------------- */
	function Tour( name, launcher ) {
		this.name = name;
		this.launcher = launcher;
		this.steps = ( TOURS[ name ] && TOURS[ name ].steps ) || [];
		this.i = 0;
		this.lastFocus = document.activeElement;
		if ( ! this.steps.length ) {
			return;
		}
		this.build();
		this.show( 0 );
	}

	Tour.prototype.build = function () {
		var self = this;

		this.backdrop = el( 'div', 'acps-tour-backdrop' );
		this.ring = el( 'div', 'acps-tour-ring' );
		this.ring.setAttribute( 'aria-hidden', 'true' );

		this.pop = el( 'div', 'acps-tour-pop' );
		this.pop.setAttribute( 'role', 'dialog' );
		this.pop.setAttribute( 'aria-modal', 'true' );
		this.pop.setAttribute( 'aria-labelledby', 'acps-tour-title' );
		this.pop.setAttribute( 'tabindex', '-1' );

		this.pop.innerHTML =
			'<button type="button" class="acps-tour-x" aria-label="' + esc( t( 'close', 'End tour' ) ) + '">&times;</button>' +
			'<p class="acps-tour-count" aria-hidden="true"></p>' +
			'<h2 id="acps-tour-title" class="acps-tour-title"></h2>' +
			'<div class="acps-tour-body"></div>' +
			'<div class="acps-tour-nav">' +
				'<button type="button" class="button acps-tour-back"></button>' +
				'<span class="acps-tour-dots" aria-hidden="true"></span>' +
				'<button type="button" class="button button-primary acps-tour-next"></button>' +
			'</div>';

		document.body.appendChild( this.backdrop );
		document.body.appendChild( this.ring );
		document.body.appendChild( this.pop );

		this.elTitle = this.pop.querySelector( '.acps-tour-title' );
		this.elBody = this.pop.querySelector( '.acps-tour-body' );
		this.elCount = this.pop.querySelector( '.acps-tour-count' );
		this.elDots = this.pop.querySelector( '.acps-tour-dots' );
		this.elBack = this.pop.querySelector( '.acps-tour-back' );
		this.elNext = this.pop.querySelector( '.acps-tour-next' );

		this.pop.querySelector( '.acps-tour-x' ).addEventListener( 'click', function () { self.end(); } );
		this.elBack.addEventListener( 'click', function () { self.show( self.i - 1 ); } );
		this.elNext.addEventListener( 'click', function () {
			if ( self.i >= self.steps.length - 1 ) { self.end(); } else { self.show( self.i + 1 ); }
		} );
		this.backdrop.addEventListener( 'click', function () { self.end(); } );

		this._key = function ( e ) { self.onKey( e ); };
		document.addEventListener( 'keydown', this._key, true );
		this._reflow = function () { self.place(); };
		window.addEventListener( 'resize', this._reflow );
		window.addEventListener( 'scroll', this._reflow, true );
	};

	Tour.prototype.onKey = function ( e ) {
		if ( e.key === 'Escape' ) { e.preventDefault(); this.end(); return; }
		if ( e.key === 'ArrowRight' ) { e.preventDefault(); if ( this.i < this.steps.length - 1 ) { this.show( this.i + 1 ); } else { this.end(); } return; }
		if ( e.key === 'ArrowLeft' ) { e.preventDefault(); this.show( this.i - 1 ); return; }
		if ( e.key === 'Tab' ) { this.trapTab( e ); }
	};

	Tour.prototype.trapTab = function ( e ) {
		var f = this.pop.querySelectorAll( 'button' );
		if ( ! f.length ) { return; }
		var first = f[0], last = f[ f.length - 1 ];
		if ( e.shiftKey && document.activeElement === first ) { e.preventDefault(); last.focus(); }
		else if ( ! e.shiftKey && document.activeElement === last ) { e.preventDefault(); first.focus(); }
	};

	Tour.prototype.show = function ( index ) {
		if ( index < 0 || index >= this.steps.length ) { return; }
		this.i = index;
		var step = this.steps[ index ];

		this.elTitle.innerHTML = esc( step.title || '' );
		this.elBody.innerHTML = step.html || '';
		this.elCount.textContent = ( t( 'step', 'Step' ) + ' ' + ( index + 1 ) + ' / ' + this.steps.length );
		this.elBack.textContent = t( 'back', 'Back' );
		this.elBack.disabled = index === 0;
		this.elNext.textContent = index >= this.steps.length - 1 ? t( 'done', 'Done' ) : t( 'next', 'Next' );

		// Progress dots.
		var dots = '';
		for ( var d = 0; d < this.steps.length; d++ ) {
			dots += '<span class="acps-tour-dot' + ( d === index ? ' is-on' : '' ) + '"></span>';
		}
		this.elDots.innerHTML = dots;

		this.target = step.el ? document.querySelector( step.el ) : null;
		if ( this.target ) {
			try { this.target.scrollIntoView( { block: 'center', behavior: reduceMotion() ? 'auto' : 'smooth' } ); } catch ( e ) { this.target.scrollIntoView(); }
		}
		var self = this;
		setTimeout( function () { self.place(); self.pop.focus(); }, this.target ? 260 : 0 );
	};

	Tour.prototype.place = function () {
		var side = this.steps[ this.i ].side || 'auto';
		if ( ! this.target || ! isVisible( this.target ) ) {
			// Centered card, plain dim (no hole).
			this.ring.style.display = 'none';
			center( this.pop );
			return;
		}
		var r = this.target.getBoundingClientRect();
		var pad = 6;
		this.ring.style.display = 'block';
		this.ring.style.top = ( r.top - pad ) + 'px';
		this.ring.style.left = ( r.left - pad ) + 'px';
		this.ring.style.width = ( r.width + pad * 2 ) + 'px';
		this.ring.style.height = ( r.height + pad * 2 ) + 'px';

		// Choose a side with room; measure popover.
		var pw = this.pop.offsetWidth, ph = this.pop.offsetHeight, gap = 14;
		var vw = window.innerWidth, vh = window.innerHeight;
		var pos = side;
		if ( pos === 'auto' ) {
			if ( r.right + gap + pw < vw ) { pos = 'right'; }
			else if ( r.left - gap - pw > 0 ) { pos = 'left'; }
			else if ( r.bottom + gap + ph < vh ) { pos = 'bottom'; }
			else { pos = 'top'; }
		}
		var top, left;
		if ( pos === 'right' ) { left = r.right + gap; top = r.top + r.height / 2 - ph / 2; }
		else if ( pos === 'left' ) { left = r.left - gap - pw; top = r.top + r.height / 2 - ph / 2; }
		else if ( pos === 'top' ) { top = r.top - gap - ph; left = r.left + r.width / 2 - pw / 2; }
		else { top = r.bottom + gap; left = r.left + r.width / 2 - pw / 2; }

		// Clamp to viewport.
		left = Math.max( 10, Math.min( left, vw - pw - 10 ) );
		top = Math.max( 10, Math.min( top, vh - ph - 10 ) );
		this.pop.style.top = top + 'px';
		this.pop.style.left = left + 'px';
	};

	Tour.prototype.end = function () {
		document.removeEventListener( 'keydown', this._key, true );
		window.removeEventListener( 'resize', this._reflow );
		window.removeEventListener( 'scroll', this._reflow, true );
		remove( this.backdrop ); remove( this.ring ); remove( this.pop );
		if ( this.launcher && this.launcher.focus ) { this.launcher.focus(); }
		else if ( this.lastFocus && this.lastFocus.focus ) { try { this.lastFocus.focus(); } catch ( e ) {} }
	};

	/* ---- helpers ---------------------------------------------------------- */
	function el( tag, cls ) { var e = document.createElement( tag ); if ( cls ) { e.className = cls; } return e; }
	function remove( n ) { if ( n && n.parentNode ) { n.parentNode.removeChild( n ); } }
	function center( n ) { n.style.top = Math.max( 10, window.innerHeight / 2 - n.offsetHeight / 2 ) + 'px'; n.style.left = Math.max( 10, window.innerWidth / 2 - n.offsetWidth / 2 ) + 'px'; }
	function isVisible( n ) { var r = n.getBoundingClientRect(); return r.width > 0 && r.height > 0; }
	function reduceMotion() { try { return window.matchMedia && matchMedia( '(prefers-reduced-motion: reduce)' ).matches; } catch ( e ) { return false; } }
	function esc( s ) { var d = document.createElement( 'div' ); d.textContent = s == null ? '' : String( s ); return d.innerHTML; }

	// Expose a tiny API so other scripts / inline buttons can start a tour.
	window.ACPS_ST_startTour = function ( name ) { if ( TOURS[ name ] ) { new Tour( name, null ); } };
}() );
