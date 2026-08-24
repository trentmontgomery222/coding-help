/**
 * Managed Content Manager — in-place page editing (builder-agnostic).
 *
 * Beaver Builder / Elementor: each unit is marked in the DOM, so we add an Edit
 * button on hover (provider.inplace = true, using provider.selector/idAttr).
 * Block editor: no per-block DOM hook, so editors pick a block from a list
 * (the "Choose a block" toolbar button). Either way, the drawer loads/saves one
 * node over AJAX and reloads on success so the true layout is shown.
 */
( function () {
	'use strict';

	if ( typeof window.MCM_EDIT === 'undefined' ) {
		return;
	}
	var CFG = window.MCM_EDIT;
	var I18N = CFG.i18n || {};
	var P = CFG.provider || {};

	var root, drawer, scrim, form, fieldsBox, listBox, titleEl, statusEl, saveBtn, listBtn;
	var currentNode = null;

	function t( key, fb ) { return I18N[ key ] || fb; }

	function ready( fn ) {
		if ( document.readyState !== 'loading' ) { fn(); }
		else { document.addEventListener( 'DOMContentLoaded', fn ); }
	}

	function post( action, formData ) {
		formData.append( 'action', action );
		formData.append( 'csrf', CFG.csrf );
		formData.append( 'post_id', CFG.postId );
		return fetch( CFG.ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			body: formData
		} ).then( function ( r ) { return r.json(); } );
	}

	// --- In-place decoration (Beaver Builder / Elementor) -------------------
	function decorate() {
		if ( ! P.inplace || ! P.selector ) { return; }
		var attr = P.idAttr || 'data-node';
		var units = document.querySelectorAll( P.selector );
		Array.prototype.forEach.call( units, function ( el ) {
			if ( el.classList.contains( 'mcm-editable' ) ) { return; }
			el.classList.add( 'mcm-editable' );
			var btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'mcm-em-edit-btn';
			btn.textContent = '✎ ' + t( 'edit', 'Edit' );
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				e.stopPropagation();
				openNode( el.getAttribute( attr ) );
			} );
			el.appendChild( btn );
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
		listBox = root.querySelector( '.mcm-em-list' );
		titleEl = root.querySelector( '.mcm-em-drawer-title' );
		statusEl = root.querySelector( '.mcm-em-status' );
		saveBtn = root.querySelector( '.mcm-em-save' );
		listBtn = root.querySelector( '.mcm-em-list-btn' );
		return true;
	}

	function openDrawer() { drawer.hidden = false; scrim.hidden = false; }
	function closeDrawer() {
		drawer.hidden = true; scrim.hidden = true;
		currentNode = null; fieldsBox.innerHTML = ''; listBox.innerHTML = '';
		setStatus( '', '' );
	}
	function showForm() { form.hidden = false; listBox.hidden = true; }
	function showList() { form.hidden = true; listBox.hidden = false; }
	function setStatus( msg, kind ) {
		statusEl.textContent = msg || '';
		statusEl.className = 'mcm-em-status' + ( kind ? ' mcm-em-' + kind : '' );
	}

	// Load the list of editable nodes into the drawer.
	function openList() {
		titleEl.textContent = t( 'chooseBlk', 'Choose a block' );
		listBox.innerHTML = '<p class="mcm-help">' + t( 'loading', 'Loading…' ) + '</p>';
		showList();
		openDrawer();

		post( 'mcm_edit_list', new FormData() ).then( function ( res ) {
			if ( ! res || ! res.success ) {
				listBox.innerHTML = '<p class="mcm-em-status mcm-em-err">' + ( ( res && res.data && res.data.message ) || t( 'error', 'Error' ) ) + '</p>';
				return;
			}
			var nodes = res.data.nodes || [];
			if ( ! nodes.length ) {
				listBox.innerHTML = '<p class="mcm-help">' + t( 'noBlocks', 'No editable content found.' ) + '</p>';
				return;
			}
			var ul = document.createElement( 'div' );
			ul.className = 'mcm-em-node-list';
			nodes.forEach( function ( n ) {
				var item = document.createElement( 'button' );
				item.type = 'button';
				item.className = 'mcm-em-node';
				item.innerHTML = '<span class="mcm-em-node-label"></span><span class="mcm-em-node-prev"></span>';
				item.querySelector( '.mcm-em-node-label' ).textContent = n.label || '';
				item.querySelector( '.mcm-em-node-prev' ).textContent = n.preview || '';
				item.addEventListener( 'click', function () { openNode( n.node_id ); } );
				ul.appendChild( item );
			} );
			listBox.innerHTML = '';
			listBox.appendChild( ul );
		} ).catch( function () {
			listBox.innerHTML = '<p class="mcm-em-status mcm-em-err">' + t( 'error', 'Error' ) + '</p>';
		} );
	}

	// Load one node's form into the drawer.
	function openNode( node ) {
		if ( node === null || typeof node === 'undefined' ) { return; }
		currentNode = String( node );
		titleEl.textContent = t( 'loading', 'Loading…' );
		fieldsBox.innerHTML = '';
		setStatus( '', '' );
		showForm();
		openDrawer();

		var fd = new FormData();
		fd.append( 'node_id', currentNode );
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
		listBtn.addEventListener( 'click', openList );
		root.querySelector( '.mcm-em-close' ).addEventListener( 'click', closeDrawer );
		root.querySelector( '.mcm-em-cancel' ).addEventListener( 'click', closeDrawer );
		document.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' && ! drawer.hidden ) { closeDrawer(); }
		} );
	} );
} )();
