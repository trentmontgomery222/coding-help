//3.1js stuff
(function () {
	'use strict';

	function ready( fn ) {
		if ( document.readyState !== 'loading' ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	function debounce( fn, wait ) {
		var t;
		return function () {
			var ctx = this,
				args = arguments;
			clearTimeout( t );
			t = setTimeout( function () {
				fn.apply( ctx, args );
			}, wait );
		};
	}

	function initDirectory( root ) {
		var search = root.querySelector( '[data-CAYDENDIR-search]' );
		var status = root.querySelector( '[data-CAYDENDIR-status]' );
		var clearBtn = root.querySelector( '[data-CAYDENDIR-clear]' );
		var chips = Array.prototype.slice.call( root.querySelectorAll( '[data-CAYDENDIR-tag]' ) );
		var items = Array.prototype.slice.call( root.querySelectorAll( '[data-CAYDENDIR-item]' ) );
		var matchMode = ( root.getAttribute( 'data-match' ) || 'any' ).toLowerCase();
		var selectable = root.getAttribute( 'data-selectable' ) === '1';
		if ( ! items.length ) {
			return;
		}
		function activeTags() {
			return chips
				.filter( function ( c ) {
					return c.getAttribute( 'aria-pressed' ) === 'true';
				} )
				.map( function ( c ) {
					return c.getAttribute( 'data-CAYDENDIR-tag' );
				} );
		}
		function apply() {
			var q = ( search ? search.value : '' ).trim().toLowerCase();
			var mode = matchMode;
			if ( q.indexOf('!') !== -1 ) { mode = 'not'; }
			var tags = activeTags();
			var visible = 0;
			items.forEach( function ( item ) {
				var blob = item.getAttribute( 'data-search' ) || '';
				var itemTags = ( item.getAttribute( 'data-tags' ) || '' )
					.split( '|' )
					.filter( Boolean );
				var term = q.replace(/!/g, '').trim();
				var textOk = term === '' ? true
				  : ( mode === 'not' ? blob.indexOf(term) === -1 : blob.indexOf(term) !== -1 );
				var tagOk = true;
				if ( tags.length ) {
					if ( matchMode === 'all' ) {
						tagOk = tags.every( function ( t ) {
							return itemTags.indexOf( t ) !== -1;
						} );
					} else {
						if ( mode === 'not' ) {
							tagOk = tags.every(t => itemTags.indexOf(t) === -1);
						} else {
							tagOk = tags.some( function ( t ) {
								return itemTags.indexOf( t ) !== -1;
							} );
						}
					}
				}
				var show = textOk && tagOk;
				item.hidden = ! show;
				if ( show ) {
					visible++;
				}
			} );
			if ( status ) {
				status.textContent = visible === 1 ? '1 result' : visible + ' results';
			}
			if ( clearBtn ) {
				clearBtn.hidden = tags.length === 0 && q === '';
			}
		}
		if ( search ) {
			search.addEventListener( 'input', debounce( apply, 150 ) );
		}
		chips.forEach( function ( chip ) {
			chip.addEventListener( 'click', function () {
				var pressed = chip.getAttribute( 'aria-pressed' ) === 'true';
				chip.setAttribute( 'aria-pressed', pressed ? 'false' : 'true' );
				apply();
			} );
		} );
		if ( clearBtn ) {
			clearBtn.addEventListener( 'click', function () {
				chips.forEach( function ( c ) {
					c.setAttribute( 'aria-pressed', 'false' );
				} );
				if ( search ) {
					search.value = '';
				}
				apply();
				if ( search ) {
					search.focus();
				}
			} );
		}
		// Row selection (native checkbox = fully accessible). Clicking the
		// row toggles the checkbox unless the click landed on a link/control.
		if ( selectable ) {
			items.forEach( function ( item ) {
				var box = item.querySelector( '[data-CAYDENDIR-select]' );
				if ( ! box ) {
					return;
				}
				function reflect() {
					if ( box.checked ) {
						item.setAttribute( 'data-selected', '' );
					} else {
						item.removeAttribute( 'data-selected' );
					}
				}
				box.addEventListener( 'change', reflect );
				item.addEventListener( 'click', function ( e ) {
					if ( e.target.closest( 'a, button, input, label, [href]' ) ) {
						return; // let real controls behave normally
					}
					box.checked = ! box.checked;
					reflect();
				} );
				reflect();
			} );
		}
		apply();

		// Manual edit system (only active for users who can edit).
		initEditing( root, items, apply );
	}

	/* ---------------------------------------------------------------------
	 * Manual edit system
	 * Opens an accessible dialog for a row, saves the record to WordPress as
	 * a persistent manual override via admin-ajax, and updates the row in
	 * place. "Remove manual override" puts the synced (automatic) data back.
	 * ------------------------------------------------------------------- */
	function initEditing( root, items, applyFilters ) {
		if ( root.getAttribute( 'data-can-edit' ) !== '1' || typeof window.CAYDENDIRSD === 'undefined' ) {
			return;
		}
		var overlay = root.querySelector( '[data-CAYDENDIR-modal]' );
		if ( ! overlay ) {
			return;
		}
		var dialog = overlay.querySelector( '[data-CAYDENDIR-dialog]' );
		var form = overlay.querySelector( '[data-CAYDENDIR-edit-form]' );
		var statusEl = overlay.querySelector( '[data-CAYDENDIR-edit-status]' );
		var keyline = overlay.querySelector( '[data-CAYDENDIR-keyline]' );
		var saveBtn = overlay.querySelector( '[data-CAYDENDIR-save]' );
		var cancelBtn = overlay.querySelector( '[data-CAYDENDIR-cancel]' );
		var removeBtn = overlay.querySelector( '[data-CAYDENDIR-remove]' );
		var announceEl = root.querySelector( '[data-CAYDENDIR-announce]' );

		var fields = {};
		[ 'key', 'firstname', 'lastname', 'email', 'photo', 'publictitle', 'job', 'location', 'tags', 'id', 'hidden' ].forEach( function ( k ) {
			fields[ k ] = form.querySelector( '[data-CAYDENDIR-f="' + k + '"]' );
		} );

		var lastTrigger = null;
		var currentItem = null;
		var busy = false;

		function announce( msg ) {
			if ( ! announceEl ) {
				return;
			}
			announceEl.textContent = '';
			setTimeout( function () {
				announceEl.textContent = msg;
			}, 40 );
		}

		function setStatus( msg ) {
			if ( statusEl ) {
				statusEl.textContent = msg || '';
			}
		}

		function setBusy( state ) {
			busy = state;
			if ( saveBtn ) {
				saveBtn.disabled = state;
			}
			if ( removeBtn ) {
				removeBtn.disabled = state;
			}
		}

		function readRecord( item ) {
			try {
				return JSON.parse( item.getAttribute( 'data-record' ) || '{}' );
			} catch ( e ) {
				return {};
			}
		}

		function writeRecord( item, r ) {
			item.setAttribute( 'data-key', r.key || '' );
			item.setAttribute( 'data-record', JSON.stringify( {
				key: r.key || '',
				manual: !! r.manual,
				name: r.name || '',
				firstname: r.firstname || '',
				lastname: r.lastname || '',
				email: r.email || '',
				photo: r.photo || '',
				publictitle: r.publictitle || '',
				job: r.job || '',
				location: r.location || '',
				id: r.id || '',
				tags: typeof r.tags === 'string' ? r.tags : ( r.tags_joined || '' ),
				hidden: ( r.hidden === '1' || r.hidden === true ) ? '1' : '0'
			} ) );
		}

		function getFocusable() {
			var nodes = dialog.querySelectorAll( 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])' );
			return Array.prototype.slice.call( nodes ).filter( function ( el ) {
				return ! el.hidden && el.offsetParent !== null;
			} );
		}

		function openFor( item, trigger ) {
			currentItem = item;
			lastTrigger = trigger || null;
			var r = readRecord( item );
			fields.key.value = r.key || '';
			fields.firstname.value = r.firstname || '';
			fields.lastname.value = r.lastname || '';
			fields.email.value = r.email || '';
			fields.photo.value = r.photo || '';
			fields.publictitle.value = r.publictitle || '';
			fields.job.value = r.job || '';
			fields.location.value = r.location || '';
			fields.tags.value = r.tags || '';
			fields.id.value = r.id || '';
			if ( fields.hidden ) {
				fields.hidden.checked = ( r.hidden === '1' || r.hidden === true );
			}
			if ( removeBtn ) {
				removeBtn.hidden = ! r.manual;
			}
			if ( keyline ) {
				keyline.textContent = 'Match key: ' + ( r.key || '—' );
			}
			setStatus( '' );
			setBusy( false );
			overlay.hidden = false;
			document.body.classList.add( 'CAYDENDIR-noscroll' );
			fields.firstname.focus();
		}

		function close() {
			overlay.hidden = true;
			document.body.classList.remove( 'CAYDENDIR-noscroll' );
			currentItem = null;
			if ( lastTrigger && document.body.contains( lastTrigger ) ) {
				lastTrigger.focus();
			}
			lastTrigger = null;
		}

		// Esc closes; Tab is trapped inside the dialog (2.1.2 — the trap can
		// always be exited with Esc or the Cancel button).
		overlay.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' || e.key === 'Esc' ) {
				e.preventDefault();
				close();
				return;
			}
			if ( e.key !== 'Tab' ) {
				return;
			}
			var focusable = getFocusable();
			if ( ! focusable.length ) {
				return;
			}
			var first = focusable[ 0 ];
			var last = focusable[ focusable.length - 1 ];
			if ( e.shiftKey && document.activeElement === first ) {
				e.preventDefault();
				last.focus();
			} else if ( ! e.shiftKey && document.activeElement === last ) {
				e.preventDefault();
				first.focus();
			}
		} );

		// Clicking the dimmed backdrop closes the dialog.
		overlay.addEventListener( 'mousedown', function ( e ) {
			if ( e.target === overlay ) {
				close();
			}
		} );

		if ( cancelBtn ) {
			cancelBtn.addEventListener( 'click', close );
		}

		function post( action, data ) {
			var body = new URLSearchParams();
			body.append( 'action', action );
			body.append( 'nonce', window.CAYDENDIRSD.nonce );
			Object.keys( data ).forEach( function ( k ) {
				body.append( k, data[ k ] );
			} );
			return window.fetch( window.CAYDENDIRSD.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
				body: body.toString()
			} ).then( function ( res ) {
				return res.json();
			} );
		}

		function setFieldText( item, field, value, dashWhenEmpty ) {
			var el = item.querySelector( '[data-CAYDENDIR-field="' + field + '"]' );
			if ( el ) {
				el.textContent = value || ( dashWhenEmpty ? '—' : '' );
			}
			var wrap = item.querySelector( '[data-CAYDENDIR-wrap="' + field + '"]' );
			if ( wrap ) {
				wrap.hidden = ! value;
			}
		}

		function applyRecordToItem( item, d ) {
			// Use the server's templated display strings (fall back to the raw
			// field if a display value was not provided).
			setFieldText( item, 'name', d.name_display !== undefined ? d.name_display : d.name, true );
			setFieldText( item, 'publictitle', d.publictitle_display !== undefined ? d.publictitle_display : d.publictitle, true );
			setFieldText( item, 'job', d.job_display !== undefined ? d.job_display : d.job, true );
			setFieldText( item, 'location', d.location_display !== undefined ? d.location_display : d.location, true );

			// Email (rebuilt with DOM APIs — no HTML injection). The link text
			// is the templated display; the href is always the real address.
			var emailWrap = item.querySelector( '[data-CAYDENDIR-email-wrap]' );
			if ( emailWrap ) {
				emailWrap.textContent = '';
				if ( d.email ) {
					var a = document.createElement( 'a' );
					a.className = 'CAYDENDIR-sd__email';
					a.href = 'mailto:' + d.email;
					a.textContent = ( d.email_display !== undefined && d.email_display !== '' ) ? d.email_display : d.email;
					emailWrap.appendChild( a );
				} else if ( emailWrap.getAttribute( 'data-dash' ) === '1' ) {
					emailWrap.textContent = '—';
				}
			}

			// Photo (server-rendered, server-escaped markup).
			var photoWrap = item.querySelector( '[data-CAYDENDIR-photo-wrap]' );
			if ( photoWrap && typeof d.photo_html === 'string' ) {
				photoWrap.innerHTML = d.photo_html;
			}

			// Tags list (cards layout).
			var tagsList = item.querySelector( '[data-CAYDENDIR-tags]' );
			if ( tagsList ) {
				tagsList.textContent = '';
				( d.tags || [] ).forEach( function ( t ) {
					var li = document.createElement( 'li' );
					li.className = 'CAYDENDIR-sd__tag';
					li.textContent = t;
					tagsList.appendChild( li );
				} );
				tagsList.hidden = ! ( d.tags && d.tags.length );
			}

			// "Edited" badge.
			var badge = item.querySelector( '[data-CAYDENDIR-manual-badge]' );
			if ( badge ) {
				badge.hidden = ! d.manual;
			}

			// "Hidden" badge (hide-from-directory flag, editors only).
			var hiddenBadge = item.querySelector( '[data-CAYDENDIR-hidden-badge]' );
			if ( hiddenBadge ) {
				hiddenBadge.hidden = ( d.hidden !== '1' );
			}

			// Accessible name on the Edit button.
			var srName = item.querySelector( '[data-CAYDENDIR-edit-name]' );
			if ( srName ) {
				srName.textContent = ' ' + ( d.name || d.email || 'entry' );
			}

			// Search / filter metadata + stored record.
			item.setAttribute( 'data-search', d.search || '' );
			item.setAttribute( 'data-tags', d.tagstr || '' );
			writeRecord( item, d );
		}

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			if ( busy || ! currentItem ) {
				return;
			}
			setBusy( true );
			setStatus( 'Saving…' );
			post( 'CAYDENDIR_sd_save_manual', {
				key: fields.key.value,
				firstname: fields.firstname.value,
				lastname: fields.lastname.value,
				email: fields.email.value,
				photo: fields.photo.value,
				publictitle: fields.publictitle.value,
				job: fields.job.value,
				location: fields.location.value,
				tags: fields.tags.value,
				id: fields.id.value,
				hidden: ( fields.hidden && fields.hidden.checked ) ? '1' : '0'
			} ).then( function ( res ) {
				setBusy( false );
				if ( ! res || ! res.success ) {
					setStatus( ( res && res.data && res.data.message ) ? res.data.message : 'Save failed. Please try again.' );
					return;
				}
				applyRecordToItem( currentItem, res.data );
				close();
				applyFilters();
				announce( 'Directory entry saved as a manual override.' );
			} ).catch( function () {
				setBusy( false );
				setStatus( 'Save failed. Check your connection and try again.' );
			} );
		} );

		if ( removeBtn ) {
			removeBtn.addEventListener( 'click', function () {
				if ( busy || ! currentItem ) {
					return;
				}
				if ( ! window.confirm( 'Remove this manual override? The entry will go back to the synced (automatic) data.' ) ) {
					return;
				}
				setBusy( true );
				setStatus( 'Removing…' );
				post( 'CAYDENDIR_sd_delete_manual', { key: fields.key.value } ).then( function ( res ) {
					setBusy( false );
					if ( ! res || ! res.success ) {
						setStatus( ( res && res.data && res.data.message ) ? res.data.message : 'Could not remove the override.' );
						return;
					}
					if ( res.data.restored ) {
						applyRecordToItem( currentItem, res.data );
						announce( 'Manual override removed. Synced data restored.' );
					} else {
						// The override had no matching synced row — remove it.
						var idx = items.indexOf( currentItem );
						if ( idx > -1 ) {
							items.splice( idx, 1 );
						}
						if ( currentItem.parentNode ) {
							currentItem.parentNode.removeChild( currentItem );
						}
						announce( 'Manual entry removed.' );
					}
					close();
					applyFilters();
				} ).catch( function () {
					setBusy( false );
					setStatus( 'Could not remove the override. Check your connection and try again.' );
				} );
			} );
		}

		// Open the dialog from any Edit button in this directory.
		root.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest ? e.target.closest( '[data-CAYDENDIR-edit]' ) : null;
			if ( ! btn || ! root.contains( btn ) ) {
				return;
			}
			var item = btn.closest( '[data-CAYDENDIR-item]' );
			if ( item ) {
				openFor( item, btn );
			}
		} );
	}

	ready( function () {
		var roots = document.querySelectorAll( '.CAYDENDIR-sd' );
		Array.prototype.forEach.call( roots, initDirectory );
	} );
})();
