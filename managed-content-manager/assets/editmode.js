/**
 * Managed Content Manager — in-place page editing.
 *
 * Runs on the real, fully-rendered page. Finds every Beaver Builder module
 * (BB marks each with a data-node attribute), adds an "Edit" button, and opens
 * a drawer that loads/saves that module's fields over AJAX. On success the page
 * reloads so the editor sees the true, updated layout.
 */
( function () {
	'use strict';

	if ( typeof window.MCM_EDIT === 'undefined' ) {
		return;
	}
	var CFG = window.MCM_EDIT;
	var I18N = CFG.i18n || {};

	var root, drawer, scrim, form, fieldsBox, titleEl, statusEl, saveBtn;
	var currentNode = null;

	function t( key, fallback ) {
		return I18N[ key ] || fallback;
	}

	function ready( fn ) {
		if ( document.readyState !== 'loading' ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	function post( action, formData ) {
		formData.append( 'action', action );
		formData.append( 'csrf', CFG.csrf );
		formData.append( 'post_id', CFG.postId );
		return fetch( CFG.ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			body: formData
		} ).then( function ( r ) {
			return r.json();
		} );
	}

	// --- Decorate modules with Edit buttons ---------------------------------
	function decorate() {
		document.documentElement.classList.add( 'mcm-editing' );

		var modules = document.querySelectorAll( '.fl-module[data-node]' );
		Array.prototype.forEach.call( modules, function ( mod ) {
			if ( mod.classList.contains( 'mcm-editable' ) ) {
				return;
			}
			mod.classList.add( 'mcm-editable' );

			var btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'mcm-em-edit-btn';
			btn.textContent = '✎ ' + t( 'edit', 'Edit' );
			btn.setAttribute( 'data-node', mod.getAttribute( 'data-node' ) );
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				e.stopPropagation();
				openModule( mod.getAttribute( 'data-node' ) );
			} );
			mod.appendChild( btn );
		} );
	}

	// --- Drawer -------------------------------------------------------------
	function cacheEls() {
		root = document.getElementById( 'mcm-editmode' );
		if ( ! root ) { return false; }
		drawer = root.querySelector( '.mcm-em-drawer' );
		scrim = root.querySelector( '.mcm-em-scrim' );
		form = root.querySelector( '.mcm-em-form' );
		fieldsBox = root.querySelector( '.mcm-em-fields' );
		titleEl = root.querySelector( '.mcm-em-drawer-title' );
		statusEl = root.querySelector( '.mcm-em-status' );
		saveBtn = root.querySelector( '.mcm-em-save' );
		return true;
	}

	function openDrawer() {
		drawer.hidden = false;
		scrim.hidden = false;
	}
	function closeDrawer() {
		drawer.hidden = true;
		scrim.hidden = true;
		currentNode = null;
		fieldsBox.innerHTML = '';
		setStatus( '', '' );
	}

	function setStatus( msg, kind ) {
		statusEl.textContent = msg || '';
		statusEl.className = 'mcm-em-status' + ( kind ? ' mcm-em-' + kind : '' );
	}

	function openModule( node ) {
		currentNode = node;
		titleEl.textContent = t( 'loading', 'Loading…' );
		fieldsBox.innerHTML = '';
		setStatus( '', '' );
		openDrawer();

		var fd = new FormData();
		fd.append( 'node_id', node );
		post( 'mcm_edit_form', fd ).then( function ( res ) {
			if ( ! res || ! res.success ) {
				titleEl.textContent = t( 'edit', 'Edit' );
				setStatus( ( res && res.data && res.data.message ) || t( 'error', 'Error' ), 'err' );
				return;
			}
			titleEl.textContent = res.data.title;
			fieldsBox.innerHTML = res.data.html;
		} ).catch( function () {
			setStatus( t( 'error', 'Error' ), 'err' );
		} );
	}

	function submit( e ) {
		e.preventDefault();
		if ( ! currentNode ) { return; }

		var fd = new FormData( form );
		fd.append( 'node_id', currentNode );
		saveBtn.disabled = true;
		setStatus( t( 'saving', 'Saving…' ), '' );

		post( 'mcm_edit_save', fd ).then( function ( res ) {
			if ( res && res.success ) {
				setStatus( t( 'save', 'Saved' ), 'ok' );
				// Reload so the real, updated layout is shown.
				window.location.reload();
			} else {
				saveBtn.disabled = false;
				setStatus( ( res && res.data && res.data.message ) || t( 'error', 'Error' ), 'err' );
			}
		} ).catch( function () {
			saveBtn.disabled = false;
			setStatus( t( 'error', 'Error' ), 'err' );
		} );
	}

	ready( function () {
		if ( ! cacheEls() ) { return; }
		decorate();

		form.addEventListener( 'submit', submit );
		scrim.addEventListener( 'click', closeDrawer );
		root.querySelector( '.mcm-em-close' ).addEventListener( 'click', closeDrawer );
		root.querySelector( '.mcm-em-cancel' ).addEventListener( 'click', closeDrawer );
		document.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' && ! drawer.hidden ) { closeDrawer(); }
		} );
	} );
} )();
