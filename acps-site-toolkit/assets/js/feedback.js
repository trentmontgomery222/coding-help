/**
 * ACPS Site Toolkit — feedback modal + page-picker pre-fill.
 *
 * Accessibility (spec §8.1): focus trap while open, Escape closes, focus
 * returns to the trigger on close, role="dialog" + aria-modal, labelled by the
 * heading. Motion respects prefers-reduced-motion (handled in CSS).
 *
 * Page picker (spec §5.3): "the page I was just on" first, then the last N
 * pages by TITLE (fetched from the uncached recent-pages endpoint), then
 * "the site in general", then an "another page" free-text fallback.
 */
( function () {
	'use strict';

	var cfg = window.ACPS_ST || {};
	var restUrl = ( cfg.restUrl || '' ).replace( /\/$/, '' );
	var strings = cfg.strings || {};

	function ready( fn ) {
		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', fn );
		} else {
			fn();
		}
	}

	ready( function () {
		var root = document.querySelector( '.acps-feedback-root' );
		if ( root ) {
			initModal( root );
		}
		// Secret-link forms: an auto-opening popup when ?acps_key is present.
		var autopopup = document.querySelector( '[data-acps-autopopup]' );
		if ( autopopup ) {
			initAutoPopup( autopopup );
		}
		// Populate every page picker on the page (modal or dedicated page).
		var pickers = document.querySelectorAll( '[data-acps-pagepicker]' );
		Array.prototype.forEach.call( pickers, function ( sel ) {
			initPagePicker( sel, root );
		} );
	} );

	/* An already-visible modal that traps focus and closes on Esc / backdrop /
	   the close button. Used for secret-link form popups. */
	function initAutoPopup( overlay ) {
		var modal = overlay.querySelector( '.acps-modal' );
		var closeBtn = overlay.querySelector( '.acps-modal__close' );
		if ( ! modal ) {
			return;
		}
		document.body.classList.add( 'acps-modal-open' );

		function close() {
			overlay.hidden = true;
			document.body.classList.remove( 'acps-modal-open' );
			document.removeEventListener( 'keydown', onKeydown, true );
		}
		function onKeydown( e ) {
			if ( e.key === 'Escape' || e.keyCode === 27 ) {
				e.preventDefault();
				close();
			} else if ( e.key === 'Tab' || e.keyCode === 9 ) {
				trapFocus( e, modal );
			}
		}

		document.addEventListener( 'keydown', onKeydown, true );
		if ( closeBtn ) {
			closeBtn.addEventListener( 'click', close );
		}
		overlay.addEventListener( 'mousedown', function ( e ) {
			if ( e.target === overlay ) {
				close();
			}
		} );

		var focusable = getFocusable( modal );
		if ( focusable.length ) {
			focusable[ 0 ].focus();
		} else {
			modal.focus();
		}
	}

	/* ---------------------------------------------------------------- *
	 * Modal
	 * ---------------------------------------------------------------- */
	function initModal( root ) {
		var trigger = root.querySelector( '.acps-feedback-trigger' );
		var overlay = root.querySelector( '.acps-modal-overlay' );
		var modal = root.querySelector( '.acps-modal' );
		var closeBtn = root.querySelector( '.acps-modal__close' );
		if ( ! trigger || ! overlay || ! modal ) {
			return;
		}

		var lastFocused = null;

		function open() {
			lastFocused = document.activeElement;
			overlay.hidden = false;
			document.body.classList.add( 'acps-modal-open' );
			// Focus the first focusable element inside the dialog.
			var focusable = getFocusable( modal );
			if ( focusable.length ) {
				focusable[ 0 ].focus();
			} else {
				modal.focus();
			}
			document.addEventListener( 'keydown', onKeydown, true );
		}

		function close() {
			overlay.hidden = true;
			document.body.classList.remove( 'acps-modal-open' );
			document.removeEventListener( 'keydown', onKeydown, true );
			// Return focus to the triggering element (spec §8.1).
			if ( lastFocused && typeof lastFocused.focus === 'function' ) {
				lastFocused.focus();
			} else {
				trigger.focus();
			}
		}

		function onKeydown( e ) {
			if ( e.key === 'Escape' || e.keyCode === 27 ) {
				e.preventDefault();
				close();
				return;
			}
			if ( e.key === 'Tab' || e.keyCode === 9 ) {
				trapFocus( e, modal );
			}
		}

		trigger.addEventListener( 'click', open );
		if ( closeBtn ) {
			closeBtn.addEventListener( 'click', close );
		}
		// Click on the backdrop (outside the modal) closes.
		overlay.addEventListener( 'mousedown', function ( e ) {
			if ( e.target === overlay ) {
				close();
			}
		} );
	}

	function getFocusable( container ) {
		return Array.prototype.slice.call(
			container.querySelectorAll(
				'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'
			)
		).filter( function ( el ) {
			return el.offsetParent !== null || el === document.activeElement;
		} );
	}

	function trapFocus( e, modal ) {
		var focusable = getFocusable( modal );
		if ( ! focusable.length ) {
			return;
		}
		var first = focusable[ 0 ];
		var last = focusable[ focusable.length - 1 ];
		if ( e.shiftKey ) {
			if ( document.activeElement === first ) {
				e.preventDefault();
				last.focus();
			}
		} else if ( document.activeElement === last ) {
			e.preventDefault();
			first.focus();
		}
	}

	/* ---------------------------------------------------------------- *
	 * Page picker
	 * ---------------------------------------------------------------- */
	function initPagePicker( select, root ) {
		var otherWrap = select.parentNode.querySelector( '.acps-page-picker-other' );
		var otherInput = otherWrap ? otherWrap.querySelector( 'input' ) : null;

		// Toggle the free-text fallback when "another page" is chosen.
		select.addEventListener( 'change', function () {
			if ( otherWrap ) {
				var isOther = select.value === '__other__';
				otherWrap.hidden = ! isOther;
				if ( isOther && otherInput ) {
					otherInput.focus();
				}
			}
		} );

		// Mirror the typed page into a real, selected option so the value is
		// actually submitted as page_ref (not the "__other__" sentinel).
		if ( otherInput ) {
			otherInput.addEventListener( 'input', function () {
				var custom = select.querySelector( 'option[data-custom]' );
				var val = otherInput.value.trim();
				if ( ! val ) {
					if ( custom ) { select.removeChild( custom ); }
					return;
				}
				if ( ! custom ) {
					custom = document.createElement( 'option' );
					custom.setAttribute( 'data-custom', '1' );
					var general = select.querySelector( 'option[value="__general__"]' );
					select.insertBefore( custom, general );
				}
				custom.value = val;
				custom.textContent = val;
				custom.selected = true;
			} );
		}

		var options = [];

		// "The page I was just on" — only meaningful inside the modal, where the
		// current page IS the page in question.
		if ( root ) {
			var curId = root.getAttribute( 'data-current-page-id' );
			var curTitle = root.getAttribute( 'data-current-page-title' );
			if ( curTitle ) {
				options.push( {
					value: curTitle,
					label: ( strings.thePageIWasOn || 'The page I was just on' ) + ' — ' + curTitle
				} );
			}
		}

		// Recent pages from the uncached endpoint.
		var rt = window.ACPS_ST_RT || {};
		var done = function () {
			rebuild( select, options );
		};

		if ( rt.token ) {
			fetch( restUrl + '/recent-pages?session=' + encodeURIComponent( rt.token ), { credentials: 'same-origin' } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( data ) {
					if ( data && data.pages ) {
						data.pages.forEach( function ( p ) {
							// Skip a duplicate of the current page.
							if ( ! options.length || options[ 0 ].value !== p.title ) {
								options.push( { value: p.title, label: p.title } );
							}
						} );
					}
					done();
				} )
				.catch( done );
		} else {
			done();
		}
	}

	function rebuild( select, options ) {
		// Keep the two trailing structural options (__general__, __other__).
		var general = select.querySelector( 'option[value="__general__"]' );
		var other = select.querySelector( 'option[value="__other__"]' );
		select.innerHTML = '';

		var placeholder = document.createElement( 'option' );
		placeholder.value = '';
		placeholder.textContent = options.length ? 'Select a page…' : 'The site in general';
		select.appendChild( placeholder );

		options.forEach( function ( o ) {
			var opt = document.createElement( 'option' );
			opt.value = o.value;
			opt.textContent = o.label;
			select.appendChild( opt );
		} );

		if ( general ) { select.appendChild( general ); }
		if ( other ) { select.appendChild( other ); }
	}
} )();
