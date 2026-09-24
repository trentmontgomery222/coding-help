/**
 * Guided tour engine.
 *
 * Spotlights a real element on the real screen, explains it in a bubble, and
 * walks the user forward one step at a time. Steps can span screens: a step
 * that belongs on another page offers a button that goes there and picks the
 * tour up where it left off.
 *
 * Nothing here assumes an element exists. A step whose target has gone (a
 * different WordPress version, a missing popup, a screen the user cannot see)
 * is skipped rather than breaking the tour.
 */
( function () {
	'use strict';

	var data = window.ACPSAlertsTour || {};
	var tours = data.tours || {};

	var state = {
		id: null,
		steps: [],
		index: 0,
		open: false,
		lastFocus: null
	};

	var el = {};

	/* ----------------------------------------------------------------- *
	 * Small helpers.
	 * ----------------------------------------------------------------- */

	/**
	 * Creates an element with optional class and text.
	 *
	 * @param {string} tag   Tag name.
	 * @param {string} cls   Class name.
	 * @param {string} text  Text content.
	 * @return {HTMLElement} The element.
	 */
	function make( tag, cls, text ) {
		var node = document.createElement( tag );

		if ( cls ) {
			node.className = cls;
		}

		if ( text ) {
			node.textContent = text;
		}

		return node;
	}

	/**
	 * Finds a step's target element, if it is on this page and visible.
	 *
	 * @param {Object} step Step definition.
	 * @return {HTMLElement|null} Target element.
	 */
	function target( step ) {
		if ( ! step.selector ) {
			return null;
		}

		var selectors = step.selector.split( '|' );

		for ( var i = 0; i < selectors.length; i++ ) {
			var node;

			try {
				node = document.querySelector( selectors[ i ].trim() );
			} catch ( e ) {
				node = null;
			}

			if ( node && node.offsetParent !== null ) {
				return node;
			}
		}

		return null;
	}

	/**
	 * Whether a step can run on the page we are currently looking at.
	 *
	 * @param {Object} step Step definition.
	 * @return {boolean} True when it can run here.
	 */
	function stepIsHere( step ) {
		// A step with no selector is a talking step: it can show anywhere its
		// screen matches.
		if ( step.screen && data.screen !== step.screen ) {
			return false;
		}

		if ( ! step.selector ) {
			return true;
		}

		return null !== target( step );
	}

	/* ----------------------------------------------------------------- *
	 * Chrome.
	 * ----------------------------------------------------------------- */

	/**
	 * Builds the overlay and bubble once, then reuses them.
	 */
	function build() {
		if ( el.root ) {
			return;
		}

		el.root = make( 'div', 'acps-tour' );
		el.root.setAttribute( 'hidden', 'hidden' );

		// Four panels around the cutout, so the highlighted element itself
		// stays clickable and un-dimmed.
		el.scrim = make( 'div', 'acps-tour__scrim' );
		el.panes = [];

		for ( var i = 0; i < 4; i++ ) {
			var pane = make( 'div', 'acps-tour__pane' );
			el.panes.push( pane );
			el.scrim.appendChild( pane );
		}

		el.ring = make( 'div', 'acps-tour__ring' );
		el.scrim.appendChild( el.ring );

		el.bubble = make( 'div', 'acps-tour__bubble' );
		el.bubble.setAttribute( 'role', 'dialog' );
		el.bubble.setAttribute( 'aria-modal', 'true' );
		el.bubble.setAttribute( 'aria-live', 'polite' );

		el.arrow = make( 'div', 'acps-tour__arrow' );

		el.count = make( 'p', 'acps-tour__count' );
		el.title = make( 'h2', 'acps-tour__title' );
		el.title.id = 'acps-tour-title';
		el.bubble.setAttribute( 'aria-labelledby', 'acps-tour-title' );

		el.body = make( 'div', 'acps-tour__body' );

		el.dots = make( 'div', 'acps-tour__dots' );

		el.back = make( 'button', 'button acps-tour__back', data.i18n.back );
		el.back.type = 'button';
		el.next = make( 'button', 'button button-primary acps-tour__next', data.i18n.next );
		el.next.type = 'button';
		el.skip = make( 'button', 'acps-tour__skip', data.i18n.skip );
		el.skip.type = 'button';

		el.close = make( 'button', 'acps-tour__close' );
		el.close.type = 'button';
		el.close.setAttribute( 'aria-label', data.i18n.close );
		el.close.innerHTML = '&times;';

		var nav = make( 'div', 'acps-tour__nav' );
		nav.appendChild( el.dots );

		var buttons = make( 'div', 'acps-tour__buttons' );
		buttons.appendChild( el.back );
		buttons.appendChild( el.next );
		nav.appendChild( buttons );

		el.bubble.appendChild( el.arrow );
		el.bubble.appendChild( el.close );
		el.bubble.appendChild( el.count );
		el.bubble.appendChild( el.title );
		el.bubble.appendChild( el.body );
		el.bubble.appendChild( nav );
		el.bubble.appendChild( el.skip );

		el.root.appendChild( el.scrim );
		el.root.appendChild( el.bubble );
		document.body.appendChild( el.root );

		el.next.addEventListener( 'click', onNext );
		el.back.addEventListener( 'click', function () { go( state.index - 1 ); } );
		el.skip.addEventListener( 'click', function () { stop( false ); } );
		el.close.addEventListener( 'click', function () { stop( false ); } );
		el.scrim.addEventListener( 'click', function () { stop( false ); } );

		window.addEventListener( 'resize', reposition );
		window.addEventListener( 'scroll', reposition, true );
		document.addEventListener( 'keydown', onKey );
	}

	/**
	 * Positions the cutout panes and the bubble against the current target.
	 */
	function reposition() {
		if ( ! state.open ) {
			return;
		}

		var step = state.steps[ state.index ];

		if ( ! step ) {
			return;
		}

		var node = target( step );
		var vw = window.innerWidth;
		var vh = window.innerHeight;

		if ( ! node ) {
			// No target: centre the bubble and cover the whole screen.
			el.ring.style.display = 'none';
			el.panes[ 0 ].style.cssText = 'top:0;left:0;right:0;bottom:0';
			el.panes[ 1 ].style.cssText = 'display:none';
			el.panes[ 2 ].style.cssText = 'display:none';
			el.panes[ 3 ].style.cssText = 'display:none';

			el.bubble.classList.add( 'is-centered' );
			el.bubble.style.top = '';
			el.bubble.style.left = '';
			el.arrow.style.display = 'none';

			return;
		}

		el.bubble.classList.remove( 'is-centered' );
		el.ring.style.display = '';

		var r = node.getBoundingClientRect();
		var pad = 6;
		var top = Math.max( 0, r.top - pad );
		var left = Math.max( 0, r.left - pad );
		var right = Math.min( vw, r.right + pad );
		var bottom = Math.min( vh, r.bottom + pad );

		// Top, bottom, left, right of the cutout.
		el.panes[ 0 ].style.cssText = 'top:0;left:0;width:100%;height:' + top + 'px';
		el.panes[ 1 ].style.cssText = 'top:' + bottom + 'px;left:0;width:100%;bottom:0';
		el.panes[ 2 ].style.cssText = 'top:' + top + 'px;left:0;width:' + left + 'px;height:' + ( bottom - top ) + 'px';
		el.panes[ 3 ].style.cssText = 'top:' + top + 'px;left:' + right + 'px;right:0;height:' + ( bottom - top ) + 'px';

		el.ring.style.cssText = 'top:' + top + 'px;left:' + left + 'px;width:' + ( right - left ) + 'px;height:' + ( bottom - top ) + 'px';

		// Place the bubble on whichever side has room, preferring below.
		var bw = el.bubble.offsetWidth || 360;
		var bh = el.bubble.offsetHeight || 200;
		var gap = 14;
		var placement = step.placement || 'auto';
		var bTop;
		var bLeft;

		if ( 'auto' === placement ) {
			placement = ( vh - bottom > bh + gap ) ? 'bottom' : ( top > bh + gap ? 'top' : 'right' );
		}

		if ( 'bottom' === placement ) {
			bTop = bottom + gap;
			bLeft = r.left + r.width / 2 - bw / 2;
		} else if ( 'top' === placement ) {
			bTop = top - bh - gap;
			bLeft = r.left + r.width / 2 - bw / 2;
		} else if ( 'left' === placement ) {
			bTop = r.top + r.height / 2 - bh / 2;
			bLeft = left - bw - gap;
		} else {
			bTop = r.top + r.height / 2 - bh / 2;
			bLeft = right + gap;
		}

		// Keep it fully on screen.
		bLeft = Math.max( 12, Math.min( bLeft, vw - bw - 12 ) );
		bTop = Math.max( 12, Math.min( bTop, vh - bh - 12 ) );

		el.bubble.style.top = bTop + 'px';
		el.bubble.style.left = bLeft + 'px';

		// Point the arrow at the target.
		el.arrow.style.display = '';
		el.arrow.className = 'acps-tour__arrow is-' + placement;

		if ( 'bottom' === placement || 'top' === placement ) {
			var ax = r.left + r.width / 2 - bLeft;
			el.arrow.style.left = Math.max( 16, Math.min( ax, bw - 16 ) ) + 'px';
			el.arrow.style.top = '';
		} else {
			var ay = r.top + r.height / 2 - bTop;
			el.arrow.style.top = Math.max( 16, Math.min( ay, bh - 16 ) ) + 'px';
			el.arrow.style.left = '';
		}
	}

	/* ----------------------------------------------------------------- *
	 * Running the tour.
	 * ----------------------------------------------------------------- */

	/**
	 * Starts a tour by id.
	 *
	 * @param {string} id    Tour id.
	 * @param {number} start Step to start at.
	 */
	function start( id, start ) {
		var tour = tours[ id ];

		if ( ! tour || ! tour.steps || ! tour.steps.length ) {
			return;
		}

		build();

		state.id = id;
		state.steps = tour.steps;
		state.open = true;
		state.lastFocus = document.activeElement;

		el.root.removeAttribute( 'hidden' );
		document.body.classList.add( 'acps-tour-open' );

		go( typeof start === 'number' ? start : 0 );
	}

	/**
	 * Moves to a step, skipping any that cannot run here.
	 *
	 * @param {number} index Step index.
	 */
	function go( index ) {
		if ( index < 0 ) {
			index = 0;
		}

		if ( index >= state.steps.length ) {
			stop( true );

			return;
		}

		// Walk forward past steps whose target is not on this screen, unless the
		// step knows where it lives and can offer to take the user there.
		var i = index;

		while ( i < state.steps.length && ! stepIsHere( state.steps[ i ] ) && ! state.steps[ i ].url ) {
			i++;
		}

		if ( i >= state.steps.length ) {
			stop( true );

			return;
		}

		state.index = i;
		render();
	}

	/**
	 * Draws the current step.
	 */
	function render() {
		var step = state.steps[ state.index ];
		var here = stepIsHere( step );

		el.count.textContent = data.i18n.stepOf
			.replace( '%1$s', state.index + 1 )
			.replace( '%2$s', state.steps.length );

		el.title.textContent = step.title || '';
		el.body.innerHTML = step.html || '';

		// Dots.
		el.dots.innerHTML = '';

		for ( var d = 0; d < state.steps.length; d++ ) {
			var dot = make( 'span', 'acps-tour__dot' + ( d === state.index ? ' is-current' : ( d < state.index ? ' is-done' : '' ) ) );
			el.dots.appendChild( dot );
		}

		el.back.style.display = state.index > 0 ? '' : 'none';

		if ( ! here && step.url ) {
			// The step lives on another screen: offer to go there.
			el.next.textContent = step.goLabel || data.i18n.takeMeThere;
		} else {
			el.next.textContent = ( state.index === state.steps.length - 1 ) ? data.i18n.finish : data.i18n.next;
		}

		var node = target( step );

		if ( node ) {
			var r = node.getBoundingClientRect();

			if ( r.top < 60 || r.bottom > window.innerHeight - 60 ) {
				var y = window.scrollY + r.top - window.innerHeight / 2 + r.height / 2;
				window.scrollTo( { top: Math.max( 0, y ), behavior: 'smooth' } );
			}
		}

		// Let the scroll settle before measuring.
		window.setTimeout( reposition, 60 );
		reposition();

		el.next.focus();
		save();
	}

	/**
	 * Advances, or navigates to the step's own screen.
	 */
	function onNext() {
		var step = state.steps[ state.index ];

		if ( ! stepIsHere( step ) && step.url ) {
			var url = step.url
				+ ( step.url.indexOf( '?' ) === -1 ? '?' : '&' )
				+ 'acps_tour=' + encodeURIComponent( state.id )
				+ '&acps_tour_step=' + state.index;

			window.location.href = url;

			return;
		}

		go( state.index + 1 );
	}

	/**
	 * Closes the tour.
	 *
	 * @param {boolean} completed Whether it reached the end.
	 */
	function stop( completed ) {
		if ( ! state.open ) {
			return;
		}

		state.open = false;
		el.root.setAttribute( 'hidden', 'hidden' );
		document.body.classList.remove( 'acps-tour-open' );

		save( completed ? 'done' : 'stopped' );

		if ( completed ) {
			celebrate();
		}

		if ( state.lastFocus && state.lastFocus.focus ) {
			state.lastFocus.focus();
		}
	}

	/**
	 * A short well-done message after finishing.
	 */
	function celebrate() {
		var note = make( 'div', 'acps-tour-done notice notice-success is-dismissible' );
		var text = make( 'p', '', data.i18n.done );
		note.appendChild( text );

		var wrap = document.querySelector( '.wrap' );

		if ( wrap ) {
			wrap.insertBefore( note, wrap.firstChild );
			window.setTimeout( function () {
				note.parentNode && note.parentNode.removeChild( note );
			}, 6000 );
		}
	}

	/**
	 * Keyboard: Escape closes, arrows move, Tab stays inside the bubble.
	 *
	 * @param {KeyboardEvent} event Key event.
	 */
	function onKey( event ) {
		if ( ! state.open ) {
			return;
		}

		if ( 'Escape' === event.key ) {
			event.preventDefault();
			stop( false );

			return;
		}

		if ( 'ArrowRight' === event.key ) {
			event.preventDefault();
			onNext();

			return;
		}

		if ( 'ArrowLeft' === event.key ) {
			event.preventDefault();
			go( state.index - 1 );

			return;
		}

		if ( 'Tab' === event.key ) {
			var focusable = el.bubble.querySelectorAll( 'button, a[href], input, select, textarea' );

			if ( ! focusable.length ) {
				return;
			}

			var first = focusable[ 0 ];
			var last = focusable[ focusable.length - 1 ];

			if ( event.shiftKey && document.activeElement === first ) {
				event.preventDefault();
				last.focus();
			} else if ( ! event.shiftKey && document.activeElement === last ) {
				event.preventDefault();
				first.focus();
			}
		}
	}

	/**
	 * Remembers where the user got to, so the tour survives a page change.
	 *
	 * @param {string} status Optional status: done or stopped.
	 */
	function save( status ) {
		var payload = {
			tour: state.id,
			step: state.index,
			status: status || 'running'
		};

		try {
			window.localStorage.setItem( 'acps_tour_state', JSON.stringify( payload ) );
		} catch ( e ) {
			// Private mode: the tour still works, it just will not resume.
		}

		if ( ! data.ajaxUrl || ! data.nonce || ! status ) {
			return;
		}

		// Only the finished/abandoned result is worth a round trip.
		var body = new window.FormData();
		body.append( 'action', 'acps_alerts_tour_state' );
		body.append( 'nonce', data.nonce );
		body.append( 'tour', state.id );
		body.append( 'status', status );

		try {
			window.fetch( data.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } );
		} catch ( e ) {
			// Recording progress is a nicety, never a requirement.
		}
	}

	/* ----------------------------------------------------------------- *
	 * Wiring.
	 * ----------------------------------------------------------------- */

	/**
	 * Binds launch buttons and resumes a tour that crossed a page boundary.
	 */
	function init() {
		document.addEventListener( 'click', function ( event ) {
			var button = event.target.closest ? event.target.closest( '[data-acps-tour]' ) : null;

			if ( ! button ) {
				return;
			}

			event.preventDefault();
			start( button.getAttribute( 'data-acps-tour' ), 0 );
		} );

		// Resume after a cross-screen jump. A tour that cannot resume just
		// does not, rather than throwing into the admin screen.
		try {
			if ( data && data.resume && data.resume.tour ) {
				start( data.resume.tour, parseInt( data.resume.step, 10 ) || 0 );
			}
		} catch ( e ) {}
	}

	window.ACPSAlertsTourApi = {
		start: start,
		stop: stop
	};

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
