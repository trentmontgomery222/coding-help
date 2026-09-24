/**
 * The Plugins screen: a warning before this plugin is deleted.
 *
 * Deleting the plugin removes the site's alerts, its settings and the archive,
 * and switches off everything built on it. Almost always the better answer is
 * to switch off the one part that is misbehaving, which Settings → Features
 * does without removing anything. So before WordPress's own delete runs, this
 * says so and offers the way there.
 *
 * It adds nothing to the screen until someone asks to delete this plugin — no
 * notice, no banner — and a failure in here never gets in the way of the
 * delete itself.
 */
( function () {
	'use strict';

	var data = window.ACPSAlertsPlugins || {};
	var confirmed = false;
	var dialog = null;

	if ( ! data.basename ) {
		return;
	}

	/**
	 * Whether a click is a request to delete this plugin: its row's Delete
	 * link, or the bulk Delete with its box ticked.
	 *
	 * @param {Event} event Click event.
	 * @return {boolean} True when it is.
	 */
	function isDeleteRequest( event ) {
		var node = event.target;

		if ( ! node || ! node.closest ) {
			return false;
		}

		var link = node.closest( 'a' );

		if ( link && link.closest( '.delete' ) ) {
			var row = link.closest( 'tr' );

			return !! row && row.getAttribute( 'data-plugin' ) === data.basename;
		}

		var button = node.closest( '#doaction, #doaction2' );

		if ( ! button ) {
			return false;
		}

		var select = document.querySelector( 'doaction2' === button.id ? 'select[name="action2"]' : 'select[name="action"]' );
		var box = document.querySelector( 'input[type="checkbox"][name="checked[]"][value="' + data.basename + '"]' );

		return !! select && 'delete-selected' === select.value && !! box && box.checked;
	}

	/**
	 * Builds the dialog once.
	 *
	 * @return {HTMLElement} The dialog's backdrop.
	 */
	function build() {
		if ( dialog ) {
			return dialog;
		}

		var style = document.createElement( 'style' );
		// [hidden] needs stating: the display:flex below would otherwise
		// override it, and a "closed" dialog would go on covering the screen.
		style.textContent = '.acps-del{position:fixed;inset:0;z-index:100100;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;padding:16px}'
			+ '.acps-del[hidden]{display:none}'
			+ '.acps-del__box{background:#fff;max-width:520px;width:100%;border-radius:4px;box-shadow:0 10px 40px rgba(0,0,0,.3);padding:24px 24px 20px;border-top:4px solid #b32d2e}'
			+ '.acps-del__box h2{margin:0 0 12px;font-size:18px;line-height:1.3}'
			+ '.acps-del__box p{margin:0 0 10px;font-size:14px;line-height:1.5}'
			+ '.acps-del__actions{display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-end;margin-top:18px}'
			+ '.acps-del__actions .acps-del__delete{color:#b32d2e;border-color:#b32d2e}';
		document.head.appendChild( style );

		dialog = document.createElement( 'div' );
		dialog.className = 'acps-del';
		dialog.setAttribute( 'hidden', 'hidden' );

		var box = document.createElement( 'div' );
		box.className = 'acps-del__box';
		box.setAttribute( 'role', 'alertdialog' );
		box.setAttribute( 'aria-modal', 'true' );
		box.setAttribute( 'aria-labelledby', 'acps-del-title' );
		box.setAttribute( 'aria-describedby', 'acps-del-body' );

		var title = document.createElement( 'h2' );
		title.id = 'acps-del-title';
		title.textContent = data.title;
		box.appendChild( title );

		var body = document.createElement( 'div' );
		body.id = 'acps-del-body';

		( data.body || [] ).forEach( function ( line ) {
			var p = document.createElement( 'p' );
			p.textContent = line;
			body.appendChild( p );
		} );

		box.appendChild( body );

		var actions = document.createElement( 'div' );
		actions.className = 'acps-del__actions';

		var settings = document.createElement( 'a' );
		settings.className = 'button button-primary';
		settings.href = data.settingsUrl;
		settings.textContent = data.openSettings;

		var cancel = document.createElement( 'button' );
		cancel.type = 'button';
		cancel.className = 'button';
		cancel.textContent = data.cancel;
		cancel.addEventListener( 'click', close );

		var remove = document.createElement( 'button' );
		remove.type = 'button';
		remove.className = 'button acps-del__delete';
		remove.textContent = data.deleteAnyway;

		actions.appendChild( settings );
		actions.appendChild( cancel );
		actions.appendChild( remove );
		box.appendChild( actions );
		dialog.appendChild( box );

		dialog.addEventListener( 'click', function ( event ) {
			if ( event.target === dialog ) {
				close();
			}
		} );

		dialog.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key ) {
				event.preventDefault();
				close();

				return;
			}

			// Keep Tab inside the dialog.
			if ( 'Tab' === event.key ) {
				var first = settings;
				var last = remove;

				if ( event.shiftKey && document.activeElement === first ) {
					event.preventDefault();
					last.focus();
				} else if ( ! event.shiftKey && document.activeElement === last ) {
					event.preventDefault();
					first.focus();
				}
			}
		} );

		document.body.appendChild( dialog );

		dialog.acpsRemove = remove;
		dialog.acpsFocus = settings;

		return dialog;
	}

	var lastTrigger = null;

	/**
	 * Closes the dialog without deleting anything.
	 */
	function close() {
		if ( dialog ) {
			dialog.setAttribute( 'hidden', 'hidden' );
		}

		if ( lastTrigger && lastTrigger.focus ) {
			lastTrigger.focus();
		}
	}

	/**
	 * Shows the warning; "Delete anyway" replays the original click, which
	 * WordPress then handles exactly as it would have.
	 *
	 * @param {HTMLElement} trigger The link or button that was clicked.
	 */
	function warn( trigger ) {
		var d = build();

		lastTrigger = trigger;

		d.acpsRemove.onclick = function () {
			d.setAttribute( 'hidden', 'hidden' );
			confirmed = true;

			try {
				trigger.click();
			} finally {
				confirmed = false;
			}
		};

		d.removeAttribute( 'hidden' );
		d.acpsFocus.focus();
	}

	// Capture phase, so this runs before WordPress's own delete handler.
	document.addEventListener( 'click', function ( event ) {
		if ( confirmed ) {
			return;
		}

		var request;

		try {
			request = isDeleteRequest( event );
		} catch ( e ) {
			return; // Never stand in the way of the delete over a bug here.
		}

		if ( ! request ) {
			return;
		}

		event.preventDefault();
		event.stopImmediatePropagation();

		try {
			warn( event.target.closest( 'a, #doaction, #doaction2' ) );
		} catch ( e ) {
			// If the warning cannot be shown, fall back to a plain confirm so the
			// user is still warned and still able to go ahead.
			if ( window.confirm( ( data.body || [] ).join( '\n\n' ) ) ) {
				confirmed = true;

				try {
					event.target.click();
				} finally {
					confirmed = false;
				}
			}
		}
	}, true );
}() );
