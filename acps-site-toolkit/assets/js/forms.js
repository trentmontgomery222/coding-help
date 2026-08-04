/**
 * ACPS Site Toolkit — form runtime.
 *
 * CRITICAL (spec §7.5 / §13): the nonce and time-trap timestamp are fetched
 * AFTER load from an uncached endpoint and injected into the form. They are
 * never printed into the (edge-cached) HTML, so they can't go stale. If you
 * bake a nonce into the markup, submissions fail intermittently in production.
 *
 * Also owns accessible validation UI: an error summary that receives focus,
 * inline messages announced via role="alert", conditional field visibility,
 * and multi-page navigation.
 */
( function () {
	'use strict';

	var cfg = window.ACPS_ST || {};
	var strings = cfg.strings || {};
	var restUrl = ( cfg.restUrl || '' ).replace( /\/$/, '' );

	function ready( fn ) {
		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', fn );
		} else {
			fn();
		}
	}

	ready( function () {
		var forms = document.querySelectorAll( '.acps-form' );
		Array.prototype.forEach.call( forms, function ( form ) {
			new ACPSForm( form );
		} );
	} );

	function ACPSForm( form ) {
		this.form = form;
		this.errorSummary = form.querySelector( '.acps-error-summary' );
		this.statusRegion = form.querySelector( '.acps-status' );
		this.multipage = form.getAttribute( 'data-multipage' ) === '1';
		this.pages = form.querySelectorAll( '.acps-page' );
		this.current = 0;

		this.hydrateTokens();
		this.bindHoneypot();
		this.bindConditional();
		if ( this.multipage && this.pages.length > 1 ) {
			this.bindPaging();
		}
		this.bindSubmit();
	}

	/* Fetch nonce + timestamp after load and inject them. */
	ACPSForm.prototype.hydrateTokens = function () {
		var form = this.form;
		fetch( restUrl + '/token', { credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				setVal( form, 'acps_nonce', data.nonce );
				setVal( form, 'acps_ts', data.ts );
			} )
			.catch( function () {} );

		// Attach the journey session token and the persistent visitor id so the
		// submission carries the path and links to the visitor record.
		var rt = window.ACPS_ST_RT || {};
		if ( rt.token ) {
			setVal( form, 'acps_session', rt.token );
		}
		var uid = rt.uid || readUidCookie();
		if ( uid ) {
			setVal( form, 'acps_uid', uid );
		}
	};

	/* The honeypot: give it a submit name only in JS, keep it empty. */
	ACPSForm.prototype.bindHoneypot = function () {
		var hp = this.form.querySelector( '[data-acps-hp]' );
		if ( hp ) {
			hp.setAttribute( 'name', 'acps_hp' );
			hp.value = '';
		}
	};

	/* Conditional visibility (spec §7.3) — multi-rule AND/OR with operators. */
	ACPSForm.prototype.bindConditional = function () {
		var form = this.form;
		var conditionals = form.querySelectorAll( '[data-acps-cond]' );
		if ( ! conditionals.length ) {
			return;
		}

		function ruleMatches( rule, vals ) {
			var want = ( rule.value == null ? '' : String( rule.value ) ).toLowerCase();
			var joined = vals.join( '' ).toLowerCase();
			switch ( rule.op ) {
				case 'is_not':
					return vals.map( lc ).indexOf( want ) === -1;
				case 'contains':
					return joined.indexOf( want ) !== -1;
				case 'not_contains':
					return joined.indexOf( want ) === -1;
				case 'gt':
					return vals.some( function ( v ) { return parseFloat( v ) > parseFloat( rule.value ); } );
				case 'lt':
					return vals.some( function ( v ) { return parseFloat( v ) < parseFloat( rule.value ); } );
				case 'is_empty':
					return vals.length === 0 || ( vals.length === 1 && vals[ 0 ] === '' );
				case 'is_not_empty':
					return vals.length > 0 && ! ( vals.length === 1 && vals[ 0 ] === '' );
				case 'is':
				default:
					return vals.map( lc ).indexOf( want ) !== -1;
			}
		}
		function lc( s ) { return String( s ).toLowerCase(); }

		var evaluate = function () {
			Array.prototype.forEach.call( conditionals, function ( el ) {
				var cfgAttr = el.getAttribute( 'data-acps-cond' );
				var conf;
				try { conf = JSON.parse( cfgAttr ); } catch ( e ) { return; }
				if ( ! conf || ! conf.rules || ! conf.rules.length ) { return; }

				var results = conf.rules.map( function ( rule ) {
					return ruleMatches( rule, collectValues( form, rule.field ) );
				} );
				var conditionMet = ( conf.logic === 'or' )
					? results.some( Boolean )
					: results.every( Boolean );

				// action "show": visible only when met. "hide": hidden when met.
				var visible = ( conf.action === 'hide' ) ? ! conditionMet : conditionMet;
				el.hidden = ! visible;
			} );
		};
		form.addEventListener( 'change', evaluate );
		form.addEventListener( 'input', evaluate );
		evaluate();
	};

	/* Multi-page navigation with a live step indicator. */
	ACPSForm.prototype.bindPaging = function () {
		var self = this;
		var prev = this.form.querySelector( '.acps-prev' );
		var next = this.form.querySelector( '.acps-next' );
		var submit = this.form.querySelector( '.acps-submit' );
		var stepCur = this.form.querySelector( '.acps-step-current' );

		var show = function ( idx ) {
			Array.prototype.forEach.call( self.pages, function ( p, i ) {
				p.hidden = i !== idx;
			} );
			self.current = idx;
			if ( stepCur ) {
				stepCur.textContent = idx + 1;
			}
			if ( prev ) { prev.hidden = idx === 0; }
			var last = idx === self.pages.length - 1;
			if ( next ) { next.hidden = last; }
			if ( submit ) { submit.hidden = ! last; }
			// Move focus to the first control on the newly shown page.
			var focusable = self.pages[ idx ].querySelector( 'input, select, textarea, button' );
			if ( focusable ) { focusable.focus(); }
		};

		if ( next ) {
			next.addEventListener( 'click', function () {
				if ( self.validatePage( self.current ) ) {
					show( Math.min( self.current + 1, self.pages.length - 1 ) );
				}
			} );
		}
		if ( prev ) {
			prev.addEventListener( 'click', function () {
				show( Math.max( self.current - 1, 0 ) );
			} );
		}
		show( 0 );
	};

	ACPSForm.prototype.validatePage = function ( idx ) {
		var page = this.pages[ idx ];
		var errors = this.collectErrors( page );
		this.renderErrors( errors );
		return errors.length === 0;
	};

	/* Client-side required/format checks — mirrors the server, for fast UX.
	   The server remains the source of truth. */
	ACPSForm.prototype.collectErrors = function ( scope ) {
		scope = scope || this.form;
		var errors = [];
		var fields = scope.querySelectorAll( '.acps-field' );
		Array.prototype.forEach.call( fields, function ( field ) {
			if ( field.hidden ) {
				return;
			}
			var key = field.getAttribute( 'data-key' );
			var required = field.querySelector( '[aria-required="true"], [required]' );
			if ( ! required ) {
				return;
			}
			var controls = field.querySelectorAll( 'input, select, textarea' );
			var hasValue = false;
			Array.prototype.forEach.call( controls, function ( c ) {
				if ( c.type === 'checkbox' || c.type === 'radio' ) {
					if ( c.checked ) { hasValue = true; }
				} else if ( c.value && c.value.trim() !== '' ) {
					hasValue = true;
				}
			} );
			if ( ! hasValue ) {
				var label = field.querySelector( '.acps-label, .acps-legend' );
				errors.push( {
					field: key,
					message: ( label ? label.textContent.replace( /\s*\(required\)\s*$/, '' ) : 'This field' ) + ' is required.'
				} );
			}
		} );
		return errors;
	};

	ACPSForm.prototype.bindSubmit = function () {
		var self = this;
		this.form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			self.submit();
		} );
	};

	ACPSForm.prototype.submit = function () {
		var self = this;
		var form = this.form;

		var errors = this.collectErrors();
		if ( errors.length ) {
			this.renderErrors( errors );
			return;
		}
		this.clearErrors();

		var submitBtn = form.querySelector( '.acps-submit' );
		if ( submitBtn ) {
			submitBtn.disabled = true;
		}
		this.announce( strings.submitting || 'Sending…' );

		var data = new FormData( form );

		fetch( restUrl + '/submit', {
			method: 'POST',
			body: data,
			credentials: 'same-origin'
		} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				if ( submitBtn ) { submitBtn.disabled = false; }
				if ( res && res.success ) {
					self.onSuccess( res.confirmation );
				} else if ( res && res.summary ) {
					self.renderServerErrors( res );
				} else {
					self.announce( strings.genericError || 'Something went wrong.' );
				}
			} )
			.catch( function () {
				if ( submitBtn ) { submitBtn.disabled = false; }
				self.announce( strings.genericError || 'Something went wrong.' );
			} );
	};

	ACPSForm.prototype.onSuccess = function ( confirmation ) {
		confirmation = confirmation || {};
		if ( ( confirmation.type === 'redirect' || confirmation.type === 'both' ) && confirmation.redirect ) {
			if ( confirmation.type === 'both' && confirmation.message ) {
				this.showMessage( confirmation.message );
			}
			window.location.href = confirmation.redirect;
			return;
		}
		this.showMessage( confirmation.message || 'Thank you.' );
	};

	ACPSForm.prototype.showMessage = function ( msg ) {
		// Replace the form body with the confirmation, announced via role=status.
		var wrap = this.form.closest( '.acps-form-wrap' ) || this.form;
		var box = document.createElement( 'div' );
		box.className = 'acps-confirmation';
		box.setAttribute( 'role', 'status' );
		box.setAttribute( 'tabindex', '-1' );
		box.innerHTML = msg;
		this.form.parentNode.replaceChild( box, this.form );
		box.focus();
	};

	/* Render a server error payload: field map + summary. */
	ACPSForm.prototype.renderServerErrors = function ( res ) {
		this.clearErrors();
		var errors = res.summary || [];
		// Inline field errors from res.errors map.
		if ( res.errors ) {
			for ( var key in res.errors ) {
				if ( res.errors.hasOwnProperty( key ) ) {
					this.setFieldError( key, res.errors[ key ] );
				}
			}
		}
		this.renderSummary( errors );
	};

	ACPSForm.prototype.renderErrors = function ( errors ) {
		this.clearErrors();
		var self = this;
		errors.forEach( function ( err ) {
			if ( err.field ) {
				self.setFieldError( err.field, err.message );
			}
		} );
		this.renderSummary( errors );
	};

	ACPSForm.prototype.setFieldError = function ( key, message ) {
		var field = this.form.querySelector( '.acps-field[data-key="' + cssEscape( key ) + '"]' );
		if ( ! field ) {
			return;
		}
		var errEl = field.querySelector( '.acps-field-error' );
		if ( errEl ) {
			errEl.textContent = message;
		}
		field.classList.add( 'acps-has-error' );
	};

	ACPSForm.prototype.renderSummary = function ( errors ) {
		if ( ! this.errorSummary || ! errors.length ) {
			return;
		}
		var list = this.errorSummary.querySelector( '.acps-error-summary__list' );
		list.innerHTML = '';
		var self = this;
		errors.forEach( function ( err ) {
			var li = document.createElement( 'li' );
			if ( err.field ) {
				var a = document.createElement( 'a' );
				a.href = '#';
				a.textContent = err.message;
				a.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					self.focusField( err.field );
				} );
				li.appendChild( a );
			} else {
				li.textContent = err.message;
			}
			list.appendChild( li );
		} );
		this.errorSummary.hidden = false;
		// Move focus to the summary on failed submit (spec §8.2).
		this.errorSummary.focus();
	};

	ACPSForm.prototype.focusField = function ( key ) {
		var field = this.form.querySelector( '.acps-field[data-key="' + cssEscape( key ) + '"]' );
		if ( ! field ) {
			return;
		}
		var control = field.querySelector( 'input, select, textarea' );
		if ( control ) {
			control.focus();
		}
	};

	ACPSForm.prototype.clearErrors = function () {
		if ( this.errorSummary ) {
			this.errorSummary.hidden = true;
			var list = this.errorSummary.querySelector( '.acps-error-summary__list' );
			if ( list ) { list.innerHTML = ''; }
		}
		var errs = this.form.querySelectorAll( '.acps-field-error' );
		Array.prototype.forEach.call( errs, function ( e ) { e.textContent = ''; } );
		var flagged = this.form.querySelectorAll( '.acps-has-error' );
		Array.prototype.forEach.call( flagged, function ( f ) { f.classList.remove( 'acps-has-error' ); } );
	};

	ACPSForm.prototype.announce = function ( msg ) {
		if ( this.statusRegion ) {
			this.statusRegion.textContent = msg;
		}
	};

	/* ---- helpers ----------------------------------------------------- */
	function setVal( form, name, value ) {
		var el = form.querySelector( '[name="' + name + '"]' );
		if ( el ) {
			el.value = value;
		}
	}
	function collectValues( form, key ) {
		var out = [];
		var els = form.querySelectorAll( '[name="fields[' + key + ']"], [name="fields[' + key + '][]"]' );
		Array.prototype.forEach.call( els, function ( el ) {
			if ( el.type === 'checkbox' || el.type === 'radio' ) {
				if ( el.checked ) { out.push( el.value ); }
			} else if ( el.value !== '' ) {
				out.push( el.value );
			}
		} );
		return out;
	}
	function cssEscape( s ) {
		return String( s ).replace( /"/g, '\\"' );
	}
	function readUidCookie() {
		var m = document.cookie.match( '(^|;)\\s*acps_st_uid\\s*=\\s*([^;]+)' );
		return m ? decodeURIComponent( m.pop() ) : '';
	}

	// Expose for feedback.js.
	window.ACPSForm = ACPSForm;
} )();
