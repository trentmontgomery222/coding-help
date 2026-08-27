/* global jQuery, wp, ACPS_MM */
( function ( $ ) {
	'use strict';

	var A = window.ACPS_MM || {};
	var i18n = A.i18n || {};

	var state = { folder: 'all', search: '', type: '', sort: 'date', recursive: false };
	var selection = {};
	var selectMode = false;   // click cards to highlight/select instead of opening them
	var lastClicked = null;   // anchor id for shift-click range selection
	var folderTree = [];      // [{id,name,depth,parent,total}]
	var lastFolderData = null;
	var writable = false;
	var uploadQueue = [];
	var uploadRows = {};      // plupload file id -> { $row, file }
	var uploader = null;      // wp.Uploader instance (for cancelling)
	var firstLoad = true;
	var EXPAND_KEY = 'acps_mm_expanded';   // folders are collapsed by default
	var SIZE_KEY = 'acps_mm_size';
	var VIEW_KEY = 'acps_mm_view';         // 'classic' (default) | 'refined'
	var FOLDER_KEY = 'acps_mm_folder';
	var OPEN_KEY = 'acps_mm_open';
	var SCROLL_KEY = 'acps_mm_scroll';
	var RECURSIVE_KEY = 'acps_mm_recursive';

	function lsSet( k, v ) { try { window.localStorage.setItem( k, v ); } catch ( e ) {} }
	function lsGet( k ) { try { return window.localStorage.getItem( k ); } catch ( e ) { return null; } }
	function lsDel( k ) { try { window.localStorage.removeItem( k ); } catch ( e ) {} }

	function post( action, data ) {
		data = data || {};
		data.action = 'acps_mm_' + action;
		data.nonce = A.nonce;
		return $.post( A.ajaxUrl, data );
	}
	function esc( s ) { return $( '<div/>' ).text( s == null ? '' : String( s ) ).html(); }
	function indent( d ) { var s = ''; for ( var i = 0; i < d; i++ ) { s += '— '; } return s; }

	/* expanded folder ids in localStorage (default = collapsed) */
	function getExpanded() {
		try { return JSON.parse( window.localStorage.getItem( EXPAND_KEY ) || '[]' ) || []; } catch ( e ) { return []; }
	}
	function setExpanded( arr ) {
		try { window.localStorage.setItem( EXPAND_KEY, JSON.stringify( arr ) ); } catch ( e ) {}
	}
	function isExpanded( id ) { return getExpanded().indexOf( String( id ) ) !== -1; }
	function toggleExpanded( id ) {
		var arr = getExpanded(); id = String( id );
		var i = arr.indexOf( id );
		if ( i === -1 ) { arr.push( id ); } else { arr.splice( i, 1 ); }
		setExpanded( arr );
	}

	function baseNameNoExt( name ) { return String( name || '' ).replace( /\.[^.]+$/, '' ); }
	function isGeneric( name ) {
		return /(^|[^a-z0-9])(img|dsc|dcim|pxl|mvimg|image|photo|screenshot|untitled|scan)[-_ ]?\d/i.test( String( name || '' ) );
	}
	function applyCardSize( px ) {
		try { document.documentElement.style.setProperty( '--acps-card', parseInt( px, 10 ) + 'px' ); } catch ( e ) {}
	}
	function getView() {
		try { var v = window.localStorage.getItem( VIEW_KEY ); return ( v === 'refined' ) ? 'refined' : 'classic'; } catch ( e ) { return 'classic'; }
	}
	function applyView( view ) {
		view = ( view === 'refined' ) ? 'refined' : 'classic';
		// Apply to the whole page so Classic restyles folders, toolbar, etc.
		$( '.acps-mm-wrap' ).removeClass( 'view-classic view-refined' ).addClass( 'view-' + view );
		$( '.acps-mm-viewbtn' ).removeClass( 'button-primary' ).filter( '[data-view="' + view + '"]' ).addClass( 'button-primary' );
		try { window.localStorage.setItem( VIEW_KEY, view ); } catch ( e ) {}
	}

	/* ---------------- Folders sidebar ---------------- */
	function loadFolders() {
		post( 'folders' ).done( function ( res ) {
			if ( ! res || ! res.success ) { return; }
			writable = res.data.writable;
			folderTree = res.data.tree || [];
			lastFolderData = res.data;
			renderSidebar( res.data );
			renderScanInfo( res.data );
		} );
	}

	function renderScanInfo( d ) {
		var $i = $( '#acps-mm-scaninfo' );
		if ( d.hasScan ) {
			$i.text( ( i18n.unused || 'Unused' ) + ': ' + d.unused + '  ·  ' + esc( d.scanTime ) );
		} else {
			$i.text( '' );
		}
	}

	function specialItem( key, label, active ) {
		return '<li class="acps-mm-folder acps-mm-special' + ( active ? ' is-active' : '' ) + '" data-folder="' + esc( key ) + '">' +
			'<span class="acps-mm-fname"><span class="dashicons ' + folderIcon( key ) + '"></span> ' + esc( label ) + '</span></li>';
	}
	function folderIcon( key ) {
		if ( key === 'all' ) { return 'dashicons-images-alt2'; }
		if ( key === 'unused' ) { return 'dashicons-warning'; }
		if ( key === 'used' ) { return 'dashicons-yes-alt'; }
		if ( key === 'unfiled' ) { return 'dashicons-open-folder'; }
		return 'dashicons-portfolio';
	}

	var folderQuery = '';  // current folder-search text (lowercased)

	function renderSidebar( data ) {
		var html = '';
		html += '<div class="acps-mm-foldersearch"><span class="dashicons dashicons-search"></span>';
		html += '<input type="search" id="acps-mm-folder-search" placeholder="' + esc( i18n.searchFolders || 'Search folders…' ) + '" value="' + esc( folderQuery ) + '">';
		html += '</div>';
		html += '<div class="acps-mm-folderlist-wrap">' + folderListHtml( data ) + '</div>';
		if ( writable ) {
			html += '<button type="button" class="button acps-mm-newfolder-btn" id="acps-mm-newfolder">+ ' + esc( i18n.newFolder || 'New folder' ) + '</button>';
		}
		$( '#acps-mm-folders' ).html( html );
	}

	// The <ul> of folders — the collapsible tree normally, or a flat list of
	// matches while a folder search is active.
	function folderListHtml( data ) {
		data = data || { total: 0 };
		var html = '<ul class="acps-mm-folderlist">';
		if ( folderQuery ) {
			var matches = folderTree.filter( function ( f ) { return String( f.name ).toLowerCase().indexOf( folderQuery ) !== -1; } );
			if ( ! matches.length ) {
				html += '<li class="acps-mm-muted acps-mm-nofolders">' + esc( i18n.noFolders || 'No folders match.' ) + '</li>';
			}
			matches.forEach( function ( f ) {
				var active = String( state.folder ) === String( f.id );
				html += '<li class="acps-mm-folder' + ( active ? ' is-active' : '' ) + '" data-folder="' + f.id + '">' +
					'<span class="acps-mm-caret empty"></span>' +
					'<span class="acps-mm-fname"><span class="dashicons dashicons-portfolio"></span> ' + esc( f.name ) + '</span>' +
					'<span class="acps-mm-fcount">' + f.total + '</span></li>';
			} );
			html += '</ul>';
			return html;
		}

		html += specialItem( 'all', ( i18n.allMedia || 'All media' ) + ' (' + data.total + ')', state.folder === 'all' );
		html += specialItem( 'unfiled', ( i18n.unfiled || 'Uncategorized' ), state.folder === 'unfiled' );
		if ( data.hasScan ) {
			html += specialItem( 'unused', ( i18n.unused || 'Unused' ) + ' (' + data.unused + ')', state.folder === 'unused' );
			html += specialItem( 'used', ( i18n.used || 'Used' ), state.folder === 'used' );
		}

		// Build hierarchy for collapsible tree.
		var byParent = {};
		folderTree.forEach( function ( f ) {
			( byParent[ f.parent ] = byParent[ f.parent ] || [] ).push( f );
		} );
		html += renderFolderNodes( byParent, 0 );
		html += '</ul>';
		return html;
	}

	function renderFolderNodes( byParent, parentId ) {
		var kids = byParent[ parentId ];
		if ( ! kids ) { return ''; }
		var html = '';
		kids.forEach( function ( f ) {
			var hasKids = !! byParent[ f.id ];
			var expanded = isExpanded( f.id );
			var active = String( state.folder ) === String( f.id );
			html += '<li class="acps-mm-folder' + ( active ? ' is-active' : '' ) + '" data-folder="' + f.id + '" style="padding-left:' + ( 8 + f.depth * 16 ) + 'px">';
			if ( hasKids ) {
				html += '<span class="acps-mm-caret' + ( expanded ? '' : ' collapsed' ) + '" data-fid="' + f.id + '"></span>';
			} else {
				html += '<span class="acps-mm-caret empty"></span>';
			}
			html += '<span class="acps-mm-fname"><span class="dashicons dashicons-portfolio"></span> ' + esc( f.name ) + '</span>';
			if ( writable ) {
				html += '<span class="acps-mm-factions">' +
					'<button type="button" class="acps-mm-frename" title="' + esc( i18n.renameFolder || 'Rename' ) + '" data-fid="' + f.id + '" data-name="' + esc( f.name ) + '"><span class="dashicons dashicons-edit"></span></button>' +
					'<button type="button" class="acps-mm-fdelete" title="' + esc( i18n.deleteFolder || 'Delete' ) + '" data-fid="' + f.id + '"><span class="dashicons dashicons-trash"></span></button></span>';
			}
			html += '<span class="acps-mm-fcount">' + f.total + '</span></li>';
			if ( hasKids && expanded ) {
				html += renderFolderNodes( byParent, f.id );
			}
		} );
		return html;
	}

	/* ---------------- Grid (load all at once) ---------------- */
	function loadGrid( onDone ) {
		$( '#acps-mm-grid' ).html( '<p class="acps-mm-muted">' + esc( i18n.loading ) + '</p>' );
		$( '#acps-mm-count' ).text( '' );
		post( 'query', {
			folder: state.folder,
			search: state.search,
			type: state.type,
			sort: state.sort,
			recursive: state.recursive ? 1 : 0,
			per_page: 20000
		} ).done( function ( res ) {
			if ( ! res || ! res.success ) {
				$( '#acps-mm-grid' ).html( '<p class="acps-mm-muted">' + esc( i18n.error ) + '</p>' );
				return;
			}
			renderAll( res.data.items, onDone, res.data.subfolders || [] );
			var cnt = res.data.returned + ( res.data.capped ? ' / ' + res.data.total : '' );
			$( '#acps-mm-count' ).text( cnt + ' ' + ( i18n.allMedia || 'items' ) );
		} );
	}

	function selectFolder( fid ) {
		state.folder = fid;
		lsSet( FOLDER_KEY, fid );
		$( '#acps-mm-page' ).val( '' );
		$( '.acps-mm-folder' ).removeClass( 'is-active' );
		$( '.acps-mm-folder[data-folder="' + fid + '"]' ).addClass( 'is-active' );
		closeDetail();
		loadGrid();
		try { window.scrollTo( 0, 0 ); } catch ( e ) {}
	}

	function folderCardHtml( f ) {
		var h = '<div class="acps-mm-card acps-mm-foldercard" data-folder="' + f.id + '" title="' + esc( f.name ) + '">';
		h += '<div class="acps-mm-thumb-wrap">';
		if ( f.cover ) { h += '<img src="' + esc( f.cover ) + '" alt="" loading="lazy">'; }
		h += '<span class="acps-mm-folderoverlay"><span class="dashicons dashicons-portfolio"></span></span>';
		h += '<span class="acps-mm-foldercount">' + ( parseInt( f.count, 10 ) || 0 ) + '</span>';
		h += '</div>';
		h += '<div class="acps-mm-cap">' + esc( f.name ) + '</div>';
		h += '</div>';
		return h;
	}

	function cardHtml( f ) {
		var sel = selection[ f.id ] ? ' is-selected' : '';
		var h = '<div class="acps-mm-card state-' + esc( f.state ) + sel + '" data-id="' + f.id + '" title="' + esc( stateLabel( f.state ) ) + '">';
		h += '<label class="acps-mm-check"><input type="checkbox" class="acps-mm-cb" value="' + f.id + '"' + ( selection[ f.id ] ? ' checked' : '' ) + '></label>';
		h += '<button type="button" class="acps-mm-cardcopy" data-url="' + esc( f.url ) + '" title="' + esc( i18n.copyLink || 'Copy link' ) + '"><span class="dashicons dashicons-admin-links"></span></button>';
		h += '<span class="acps-mm-state-dot"></span>';
		h += '<div class="acps-mm-thumb-wrap">';
		if ( f.thumb ) {
			h += '<img src="' + esc( f.thumb ) + '" alt="" loading="lazy">';
		} else {
			h += '<span class="dashicons dashicons-media-default"></span>';
		}
		h += '</div>';
		h += '<div class="acps-mm-cap" title="' + esc( f.filename ) + '">' + esc( f.filename || f.title ) + '</div>';
		h += '</div>';
		return h;
	}

	function stateLabel( s ) {
		if ( s === 'used' ) { return i18n.usedItem || 'Used'; }
		if ( s === 'unused' ) { return i18n.unusedItem || 'Unused'; }
		return i18n.unknownItem || 'Not scanned';
	}

	function renderAll( items, onDone, subfolders ) {
		var $grid = $( '#acps-mm-grid' ).empty();
		subfolders = subfolders || [];

		// Sub-folder tiles first (so you can click into them like a file explorer).
		if ( subfolders.length ) {
			var fh = '';
			subfolders.forEach( function ( f ) { fh += folderCardHtml( f ); } );
			$grid.append( fh );
		}

		if ( ! items.length ) {
			if ( ! subfolders.length ) {
				$grid.html( '<p class="acps-mm-muted">' + esc( i18n.noResults ) + '</p>' );
			}
			if ( onDone ) { onDone(); }
			return;
		}
		// Render in chunks so a few thousand cards don't freeze the tab.
		var idx = 0, CHUNK = 200;
		function step() {
			var html = '';
			var end = Math.min( idx + CHUNK, items.length );
			for ( ; idx < end; idx++ ) { html += cardHtml( items[ idx ] ); }
			$grid.append( html );
			if ( idx < items.length ) { window.requestAnimationFrame( step ); }
			else if ( onDone ) { onDone(); }
		}
		step();
	}

	// Insert a freshly-uploaded card at the top without reloading the whole grid.
	function prependCard( card ) {
		if ( ! card ) { return; }
		var $grid = $( '#acps-mm-grid' );
		$grid.find( '.acps-mm-muted' ).remove(); // clear "no results" placeholder
		$( cardHtml( card ) ).hide().prependTo( $grid ).fadeIn( 200 );
	}

	// Restore scroll position / re-open the last-open file after the first load.
	function restoreLastState() {
		var openId = lsGet( OPEN_KEY );
		var y = parseInt( lsGet( SCROLL_KEY ), 10 ) || 0;
		if ( openId ) {
			var $c = $( '.acps-mm-card[data-id="' + openId + '"]' );
			if ( $c.length ) {
				try { $c[ 0 ].scrollIntoView( { block: 'center' } ); } catch ( e ) { window.scrollTo( 0, y ); }
				openDetail( openId );
				return;
			}
		}
		if ( y ) { window.scrollTo( 0, y ); }
	}

	/* ---------------- Selection ---------------- */
	function selCount() { return Object.keys( selection ).length; }
	function updateBulkBar() {
		$( '#acps-mm-selcount' ).text( selCount() );
		// Visible while there's a selection, or whenever select mode is on (so the
		// "Select all" / "Clear" actions stay reachable).
		$( '#acps-mm-bulkbar' ).toggle( selCount() > 0 || selectMode );
	}
	function clearSelection() {
		selection = {};
		$( '.acps-mm-cb' ).prop( 'checked', false );
		$( '.acps-mm-card' ).removeClass( 'is-selected' );
		updateBulkBar();
	}
	// Highlight/select a single card (keeps its checkbox in sync).
	function setCardSelected( id, on ) {
		if ( on ) { selection[ id ] = true; } else { delete selection[ id ]; }
		var $c = $( '.acps-mm-card[data-id="' + id + '"]' );
		$c.toggleClass( 'is-selected', !! on );
		$c.find( '.acps-mm-cb' ).prop( 'checked', !! on );
	}
	function toggleCardSelection( id ) { setCardSelected( id, ! selection[ id ] ); updateBulkBar(); }
	// Shift-click: select every visible card between the anchor and this one.
	function selectRange( fromId, toId ) {
		var ids = $( '#acps-mm-grid .acps-mm-card[data-id]' ).map( function () { return String( $( this ).data( 'id' ) ); } ).get();
		var a = ids.indexOf( String( fromId ) ), b = ids.indexOf( String( toId ) );
		if ( a === -1 || b === -1 ) { toggleCardSelection( toId ); return; }
		if ( a > b ) { var t = a; a = b; b = t; }
		for ( var i = a; i <= b; i++ ) { setCardSelected( ids[ i ], true ); }
		updateBulkBar();
	}

	/* ---------------- Detail drawer ---------------- */
	function openDetail( id ) {
		lsSet( OPEN_KEY, id );
		$( '#acps-mm-drawer' ).addClass( 'open' ).attr( 'aria-hidden', 'false' );
		$( '#acps-mm-backdrop' ).show();
		$( '#acps-mm-drawer-inner' ).html( '<p class="acps-mm-muted">' + esc( i18n.loading ) + '</p>' );
		post( 'detail', { id: id } ).done( function ( res ) {
			if ( ! res || ! res.success ) { $( '#acps-mm-drawer-inner' ).html( '<p>' + esc( i18n.error ) + '</p>' ); return; }
			renderDetail( res.data );
		} );
	}
	function closeDetail() {
		lsDel( OPEN_KEY );
		$( '#acps-mm-drawer' ).removeClass( 'open' ).attr( 'aria-hidden', 'true' );
		$( '#acps-mm-backdrop' ).hide();
	}

	function renderDetail( d ) {
		var h = '';
		h += '<div class="acps-mm-drawer-head"><h2>' + esc( d.filename || d.title ) + '</h2>';
		h += '<button type="button" class="button-link acps-mm-drawer-close" aria-label="Close">&times;</button></div>';

		h += '<div class="acps-mm-preview">';
		if ( d.thumb ) { h += '<img src="' + esc( d.thumb ) + '" alt="">'; }
		h += '</div>';

		if ( d.isHeic ) {
			h += '<div class="acps-mm-heicbox">';
			h += '<span class="dashicons dashicons-info"></span> ' + esc( 'This is a HEIC file (may not display in browsers). ' );
			if ( d.heicSupport ) {
				h += '<button type="button" class="button acps-mm-convert" data-id="' + d.id + '">' + esc( i18n.convertHeic ) + '</button>';
			} else {
				h += '<em>' + esc( 'Conversion not available on this server.' ) + '</em>';
			}
			h += '<span class="acps-mm-convert-msg"></span></div>';
		}

		h += '<div class="acps-mm-field"><label>' + esc( 'File URL' ) + '</label>';
		h += '<div class="acps-mm-urlrow"><input type="text" readonly class="acps-mm-url" value="' + esc( d.url ) + '">';
		h += '<button type="button" class="button acps-mm-copy" data-url="' + esc( d.url ) + '">' + esc( i18n.copyUrl ) + '</button></div></div>';

		h += '<p class="acps-mm-facts">' + esc( d.mime ) + ( d.sizeH ? ' · ' + esc( d.sizeH ) : '' ) + ( d.date ? ' · ' + esc( d.date ) : '' ) + '</p>';

		if ( d.writable ) {
			h += '<div class="acps-mm-field"><label>' + esc( 'Folder' ) + '</label>';
			h += '<select class="acps-mm-detail-folder" data-id="' + d.id + '">';
			( d.folders || [] ).forEach( function ( f ) {
				h += '<option value="' + f.id + '"' + ( String( f.id ) === String( d.folderId ) ? ' selected' : '' ) + '>' + esc( indent( f.depth ) + f.name ) + '</option>';
			} );
			h += '</select></div>';
		}

		h += '<div class="acps-mm-field"><label>' + esc( 'Title' ) + '</label><input type="text" class="acps-mm-in" data-k="title" value="' + esc( d.title ) + '"></div>';
		h += '<div class="acps-mm-field"><label>' + esc( 'Alt text' ) + '</label><input type="text" class="acps-mm-in" data-k="alt" value="' + esc( d.alt ) + '"></div>';
		h += '<div class="acps-mm-field"><label>' + esc( 'Caption' ) + '</label><textarea class="acps-mm-in" data-k="caption" rows="2">' + esc( d.caption ) + '</textarea></div>';
		h += '<div class="acps-mm-field"><label>' + esc( 'Description' ) + '</label><textarea class="acps-mm-in" data-k="description" rows="2">' + esc( d.description ) + '</textarea></div>';
		h += '<p><button type="button" class="button button-primary acps-mm-save" data-id="' + d.id + '">' + esc( 'Save changes' ) + '</button> <span class="acps-mm-saved-msg"></span></p>';

		h += '<div class="acps-mm-field"><label>' + esc( i18n.usedIn ) + '</label>';
		h += '<button type="button" class="button acps-mm-where" data-id="' + d.id + '">' + esc( i18n.whereUsed ) + '</button>';
		h += '<div class="acps-mm-where-out"></div></div>';

		h += '<div class="acps-mm-drawer-actions">';
		h += '<button type="button" class="button acps-mm-rename" data-id="' + d.id + '" data-name="' + esc( baseNameNoExt( d.filename ) ) + '">' + esc( i18n.rename || 'Rename file' ) + '</button>';
		if ( d.imageEdit ) { h += '<a class="button" href="' + esc( d.imageEdit ) + '">' + esc( 'Edit image' ) + '</a>'; }
		h += '<button type="button" class="button button-link-delete acps-mm-detail-delete" data-id="' + d.id + '">' + esc( 'Delete' ) + '</button>';
		h += '</div>';

		$( '#acps-mm-drawer-inner' ).html( h );
	}

	function renderWhere( $out, locations ) {
		if ( ! locations || ! locations.length ) {
			$out.html( '<p class="acps-mm-notused">' + esc( i18n.notUsed ) + '</p>' );
			return;
		}
		var h = '<ul class="acps-mm-wherelist">';
		locations.forEach( function ( l ) {
			h += '<li>' + ( l.url ? '<a href="' + esc( l.url ) + '">' + esc( l.label ) + '</a>' : esc( l.label ) ) + '</li>';
		} );
		h += '</ul>';
		$out.html( h );
	}

	/* ---------------- Folder picker ---------------- */
	function folderSelectHtml() {
		var h = '<select class="acps-mm-picker-select"><option value="0">' + esc( '— Unfiled —' ) + '</option>';
		folderTree.forEach( function ( f ) {
			h += '<option value="' + f.id + '">' + esc( indent( f.depth ) + f.name ) + '</option>';
		} );
		h += '</select>';
		return h;
	}
	function openFolderPicker( title, onPick ) {
		var h = '<div class="acps-mm-modal"><div class="acps-mm-modal-box"><h3>' + esc( title ) + '</h3>' + folderSelectHtml();
		if ( writable ) { h += '<p><button type="button" class="button-link acps-mm-picker-new">+ ' + esc( i18n.newFolder ) + '</button></p>'; }
		h += '<p class="acps-mm-modal-actions"><button type="button" class="button button-primary acps-mm-picker-ok">' + esc( i18n.move ) + '</button> <button type="button" class="button acps-mm-picker-cancel">' + esc( i18n.cancel ) + '</button></p></div></div>';
		var $m = $( h ).appendTo( 'body' );
		$m.on( 'click', '.acps-mm-picker-cancel', function () { $m.remove(); } );
		$m.on( 'click', '.acps-mm-picker-new', function () {
			var name = window.prompt( i18n.newFolderName );
			if ( ! name ) { return; }
			post( 'create_folder', { name: name, parent: 0 } ).done( function ( res ) {
				if ( res && res.success ) {
					folderTree.push( { id: res.data.id, name: res.data.name, depth: 0, parent: 0, total: 0 } );
					$m.find( '.acps-mm-picker-select' ).append( '<option value="' + res.data.id + '" selected>' + esc( res.data.name ) + '</option>' );
				} else { window.alert( ( res && res.data && res.data.message ) || i18n.error ); }
			} );
		} );
		$m.on( 'click', '.acps-mm-picker-ok', function () {
			var fid = parseInt( $m.find( '.acps-mm-picker-select' ).val(), 10 ) || 0;
			$m.remove(); onPick( fid );
		} );
	}

	/* ---------------- Delete (optimistic / instant) ---------------- */
	function doDelete( ids ) {
		ids = ( ids || [] ).map( Number ).filter( Boolean );
		if ( ! ids.length ) { return; }

		// Client-side "used" check from the on-screen colour — no server round-trip.
		var anyUsed = ids.some( function ( id ) { return $( '.acps-mm-card[data-id="' + id + '"]' ).hasClass( 'state-used' ); } );
		if ( anyUsed && ! window.confirm( ( i18n.usedWarn || 'Some of these files are used.' ) + '\n\n' + ( i18n.deleteAnyway || 'Delete anyway' ) + '?' ) ) {
			return;
		}

		// Remove from the UI immediately; run the delete in the background.
		var openId = lsGet( OPEN_KEY );
		ids.forEach( function ( id ) {
			delete selection[ id ];
			$( '.acps-mm-card[data-id="' + id + '"]' ).remove();
			if ( String( openId ) === String( id ) ) { closeDetail(); }
		} );
		updateBulkBar();

		post( 'delete', { ids: ids, confirm: 1 } ).done( function ( res ) {
			if ( ! res || ! res.success ) { window.alert( i18n.error ); loadGrid(); return; }
			var failed = [];
			$.each( res.data.items || {}, function ( id, r ) { if ( ! r.ok ) { failed.push( id ); } } );
			if ( failed.length ) { window.alert( failed.length + ' item(s) could not be deleted (protected or missing).' ); loadGrid(); }
			loadFolders(); // sidebar counts only
		} ).fail( function () { window.alert( i18n.error ); loadGrid(); } );
	}

	/* ---------------- Upload (custom XHR: drag + progress + browser HEIC→JPEG) ----------------
	 * The manager uploads with its own XHR instead of plupload so it can:
	 *   - convert HEIC → JPEG in the browser first (works even when the server's
	 *     image library refuses these files),
	 *   - file each upload into the folder you're currently viewing,
	 *   - show real per-file progress, the time each took, and why one failed, and
	 *   - remember an interrupted batch so you can finish it after a refresh.
	 * Files upload one at a time (gentle on the server); HEIC conversion for a
	 * file happens just before that file uploads.
	 */
	var UP_RESUME_KEY = 'acps_mm_resume';  // names still unfinished from last time
	var uploadSeq     = 0;                 // unique id per upload row
	var activeUploads = 0;                 // queued + in-flight (drives finishBatch)
	var batchTotal    = 0;                 // files added in the current batch
	var running       = false;            // is a file currently being processed?
	var taskQueue     = [];                // rows waiting to upload, FIFO
	var singleUpload  = false;            // was the finished batch a single file?

	// New uploads go into the folder you're viewing (a real numbered folder),
	// otherwise Uncategorized (0) — All media, Uncategorized and the smart views.
	function currentUploadFolder() {
		var f = String( state.folder );
		return ( /^\d+$/.test( f ) && parseInt( f, 10 ) > 0 ) ? parseInt( f, 10 ) : 0;
	}
	function isHeicName( name, type ) {
		name = String( name || '' ).toLowerCase();
		type = String( type || '' ).toLowerCase();
		return /\.(heic|heif)$/.test( name ) || type === 'image/heic' || type === 'image/heif';
	}

	function showUploads() { $( '#acps-mm-uploads' ).show(); }

	function makeRow( name ) {
		var rid  = 'u' + ( ++uploadSeq );
		var $row = $( '<div class="acps-mm-uprow"><span class="acps-mm-upname"></span>' +
			'<span class="acps-mm-upbar"><span class="acps-mm-upfill"></span></span>' +
			'<span class="acps-mm-uppct">0%</span>' +
			'<button type="button" class="acps-mm-upcancel" title="' + esc( i18n.cancel || 'Cancel' ) + '">&times;</button></div>' );
		$row.attr( 'data-row', rid ).find( '.acps-mm-upname' ).text( name );
		$( '#acps-mm-uploads-list' ).prepend( $row );
		showUploads();
		var row = { id: rid, name: name, $row: $row, xhr: null, cancelled: false, start: 0, file: null };
		uploadRows[ rid ] = row;
		$row.on( 'click', '.acps-mm-upcancel', function () { cancelRow( row ); } );
		return row;
	}
	function rowDoneOrError( row ) { return row.$row.hasClass( 'done' ) || row.$row.hasClass( 'error' ); }
	function rowPct( row, pct ) {
		if ( rowDoneOrError( row ) ) { return; }
		pct = Math.max( 0, Math.min( 100, Math.round( pct ) || 0 ) );
		row.$row.find( '.acps-mm-upfill' ).css( 'width', pct + '%' );
		row.$row.find( '.acps-mm-uppct' ).text( pct + '%' );
	}
	function rowStage( row, text ) {  // indeterminate phase, e.g. converting
		if ( rowDoneOrError( row ) ) { return; }
		row.$row.find( '.acps-mm-upfill' ).addClass( 'acps-mm-indet' ).css( 'width', '40%' );
		row.$row.find( '.acps-mm-uppct' ).text( text );
	}
	function rowUnstage( row ) { row.$row.find( '.acps-mm-upfill' ).removeClass( 'acps-mm-indet' ); }
	function fmtDur( ms ) {
		var s = ms / 1000;
		if ( s < 1 ) { return Math.round( ms ) + ' ms'; }
		if ( s < 60 ) { return ( Math.round( s * 10 ) / 10 ) + ' s'; }
		var m = Math.floor( s / 60 );
		return m + 'm ' + Math.round( s - m * 60 ) + 's';
	}
	function whenStamp( d ) {
		try {
			var now = new Date();
			var sameDay = d.getFullYear() === now.getFullYear() && d.getMonth() === now.getMonth() && d.getDate() === now.getDate();
			var t = d.toLocaleTimeString( [], { hour: 'numeric', minute: '2-digit' } );
			return sameDay ? t : ( d.toLocaleDateString( [], { month: 'short', day: 'numeric' } ) + ', ' + t );
		} catch ( e ) { return ''; }
	}
	function rowDone( row ) {
		rowUnstage( row );
		row.$row.addClass( 'done' ).find( '.acps-mm-upfill' ).css( 'width', '100%' );
		row.$row.find( '.acps-mm-uppct' ).text( '✓' );
		row.$row.find( '.acps-mm-upcancel' ).remove();
		var dur = row.start ? ( Date.now() - row.start ) : 0;
		var tip = ( i18n.uploadedIn || 'Uploaded in %s' ).replace( '%s', fmtDur( dur ) );
		var w = whenStamp( new Date() );
		if ( w ) { tip += ' · ' + w; }
		row.$row.attr( 'title', tip );
	}
	function rowError( row, why ) {
		rowUnstage( row );
		row.$row.addClass( 'error' ).find( '.acps-mm-upfill' ).css( 'width', '100%' );
		row.$row.find( '.acps-mm-uppct' ).text( '✕' );
		row.$row.find( '.acps-mm-upcancel' ).remove();
		var tip = ( i18n.uploadFailed || 'Upload failed' );
		if ( why ) { tip += ': ' + why; }
		var w = whenStamp( new Date() );
		if ( w ) { tip += ' · ' + w; }
		row.$row.attr( 'title', tip );
	}
	function cancelRow( row ) {
		row.cancelled = true;
		var i = $.inArray( row, taskQueue );
		if ( i !== -1 ) {
			// Queued but not started yet — remove and account for it.
			taskQueue.splice( i, 1 );
			rowError( row, i18n.cancelled || 'Cancelled' );
			forgetResume( row.name );
			activeUploads--;
			if ( ! running && ! taskQueue.length && activeUploads <= 0 ) { finishBatch(); }
			return;
		}
		// In-flight — abort; onabort/afterRow handle the accounting.
		rowError( row, i18n.cancelled || 'Cancelled' );
		if ( row.xhr ) { try { row.xhr.abort(); } catch ( e ) {} }
	}

	// Public entry points (file input + drag-drop) hand their FileLists here.
	function handleFiles( list ) {
		if ( ! list || ! list.length ) { return; }
		for ( var i = 0; i < list.length; i++ ) {
			var file = list[ i ];
			var row  = makeRow( file.name || 'file' );
			row.file = file;
			batchTotal++;
			activeUploads++;
			taskQueue.push( row );
			rememberResume( file.name || 'file' );
		}
		pump();
	}
	function pump() {
		if ( running ) { return; }
		var row = taskQueue.shift();
		if ( ! row ) { return; }
		running = true;
		processRow( row );
	}
	function afterRow( row ) {
		running = false;
		activeUploads--;
		if ( taskQueue.length ) { pump(); }
		else if ( activeUploads <= 0 ) { finishBatch(); }
	}
	function processRow( row ) {
		if ( row.cancelled ) { afterRow( row ); return; }
		var file = row.file;
		row.start = Date.now();
		if ( A.convertHeicClient && window.heic2any && isHeicName( file.name, file.type ) ) {
			rowStage( row, i18n.heicConverting || 'Converting HEIC…' );
			var base = String( file.name || 'photo' ).replace( /\.(heic|heif)$/i, '' );
			var p;
			try { p = window.heic2any( { blob: file, toType: 'image/jpeg', quality: 0.9 } ); }
			catch ( e ) { p = null; }
			if ( p && p.then ) {
				p.then( function ( out ) {
					var blob = ( window.Array && Array.isArray( out ) ) ? out[ 0 ] : out;
					rowUnstage( row );
					if ( blob ) { uploadBlob( row, blob, base + '.jpg' ); }
					else { uploadBlob( row, file, file.name ); }
				} ).catch( function () {
					rowUnstage( row );
					uploadBlob( row, file, file.name ); // conversion failed → upload original
				} );
				return;
			}
			rowUnstage( row );
		}
		uploadBlob( row, file, file.name );
	}
	function uploadBlob( row, blob, filename ) {
		if ( row.cancelled ) { afterRow( row ); return; }
		var fd = new FormData();
		fd.append( 'action', 'acps_mm_upload' );
		fd.append( 'nonce', A.nonce );
		fd.append( 'folder_id', currentUploadFolder() );
		fd.append( 'file', blob, filename );

		var xhr = new XMLHttpRequest();
		row.xhr = xhr;
		xhr.open( 'POST', A.ajaxUrl, true );
		xhr.upload.onprogress = function ( e ) {
			if ( e.lengthComputable ) { rowPct( row, ( e.loaded / e.total ) * 100 ); }
		};
		xhr.onload = function () {
			row.xhr = null;
			var res = null;
			try { res = JSON.parse( xhr.responseText ); } catch ( e ) {}
			if ( xhr.status >= 200 && xhr.status < 300 && res && res.success && res.data && res.data.id ) {
				rowDone( row );
				uploadQueue.push( {
					id: res.data.id, url: res.data.url || '', filename: res.data.filename || filename,
					card: res.data.card || null, common: res.data.common || [], duplicate: res.data.duplicate || null
				} );
			} else {
				rowError( row, ( res && res.data && res.data.message ) || ( 'HTTP ' + xhr.status ) );
			}
			forgetResume( row.name );
			afterRow( row );
		};
		xhr.onerror = function () {
			row.xhr = null;
			rowError( row, i18n.networkError || 'Network error' );
			forgetResume( row.name );
			afterRow( row );
		};
		xhr.onabort = function () {
			row.xhr = null;
			forgetResume( row.name ); // row already marked cancelled by cancelRow
			afterRow( row );
		};
		xhr.send( fd );
	}

	/* Resume-assist: remember which files were still uploading, so if you leave
	 * or refresh mid-batch we can tell you which didn't finish when you return.
	 * (The browser can't re-read files after a reload, so this re-prompts rather
	 * than silently re-sending — the Google Drive drip-uploader is the true
	 * unattended path.) */
	function readResume() { try { return JSON.parse( lsGet( UP_RESUME_KEY ) || '[]' ) || []; } catch ( e ) { return []; } }
	function writeResume( list ) { lsSet( UP_RESUME_KEY, JSON.stringify( list ) ); }
	function rememberResume( name ) { var l = readResume(); l.push( { name: name, at: Date.now() } ); writeResume( l ); }
	function forgetResume( name ) {
		var l = readResume();
		for ( var i = 0; i < l.length; i++ ) { if ( l[ i ].name === name ) { l.splice( i, 1 ); break; } }
		writeResume( l );
	}
	function checkResume() {
		var l = readResume();
		// Drop anything older than 2 days so an old interruption doesn't nag forever.
		var cutoff = Date.now() - 2 * 24 * 60 * 60 * 1000;
		l = l.filter( function ( x ) { return x && x.at && x.at >= cutoff; } );
		writeResume( l );
		if ( ! l.length ) { return; }
		var names = l.map( function ( x ) { return x.name; } ).slice( 0, 12 );
		var extra = l.length - names.length;
		var msg = ( i18n.resumeNotice || '%d upload(s) did not finish last time. Your browser can\'t resume them automatically — drag them in again to finish:' ).replace( '%d', l.length );
		var $n = $( '<div class="notice notice-warning acps-mm-resume"><p></p><ul class="acps-mm-resume-list"></ul>' +
			'<p><button type="button" class="button acps-mm-resume-clear"></button></p></div>' );
		$n.find( 'p' ).first().text( msg );
		names.forEach( function ( nm ) { $n.find( '.acps-mm-resume-list' ).append( $( '<li></li>' ).text( nm ) ); } );
		if ( extra > 0 ) { $n.find( '.acps-mm-resume-list' ).append( $( '<li></li>' ).text( '… +' + extra ) ); }
		$n.find( '.acps-mm-resume-clear' ).text( i18n.dismiss || 'Dismiss' );
		$n.on( 'click', '.acps-mm-resume-clear', function () { writeResume( [] ); $n.remove(); } );
		$( '.acps-mm-main' ).prepend( $n );
	}

	function initUploader() {
		// Hidden multi-file input, opened by the "Upload files" button.
		var $input = $( '<input type="file" multiple class="acps-mm-fileinput" style="position:absolute;left:-9999px;top:-9999px;">' ).appendTo( 'body' );
		$( '#acps-mm-upload' ).on( 'click', function ( e ) { e.preventDefault(); $input.trigger( 'click' ); } );
		$input.on( 'change', function () { handleFiles( this.files ); this.value = ''; } );

		// Drag-and-drop anywhere on the manager.
		var $wrap = $( '.acps-mm-wrap' );
		function stop( e ) { e.preventDefault(); e.stopPropagation(); }
		$wrap.on( 'dragenter dragover', function ( e ) { stop( e ); $wrap.addClass( 'acps-mm-dragging' ); } );
		$wrap.on( 'dragleave dragend', function ( e ) { stop( e ); $wrap.removeClass( 'acps-mm-dragging' ); } );
		$wrap.on( 'drop', function ( e ) {
			stop( e );
			$wrap.removeClass( 'acps-mm-dragging' );
			var dt = e.originalEvent && e.originalEvent.dataTransfer;
			if ( dt && dt.files && dt.files.length ) { handleFiles( dt.files ); }
		} );

		checkResume();
	}

	// Called once every file in the batch has settled: decide what to show next.
	function finishBatch() {
		singleUpload = ( batchTotal === 1 );
		batchTotal = 0;
		activeUploads = 0;
		running = false;
		if ( ! uploadQueue.length ) { loadFolders(); return; } // all failed/cancelled
		if ( uploadQueue.length === 1 ) { showUploadPopup(); return; }
		showBatchChoice( uploadQueue.length );
	}

	// Bulk-upload fork: edit every file one at a time, or just leave them where
	// they were filed (the folder you were viewing). Files with generic camera
	// names are forced through the rename step either way.
	function showBatchChoice( total ) {
		var genericCount = uploadQueue.filter( function ( it ) { return isGeneric( it.filename || '' ); } ).length;
		var h = '<div class="acps-mm-modal"><div class="acps-mm-modal-box">';
		h += '<h3>' + esc( ( i18n.batchChoice || '%d files uploaded' ).replace( '%d', total ) ) + '</h3>';
		if ( genericCount ) {
			h += '<p class="acps-mm-genwarn">' + esc( ( i18n.batchGenericWarn || '%d file(s) have generic names and must be renamed before continuing.' ).replace( '%d', genericCount ) ) + '</p>';
		}
		h += '<p class="acps-mm-modal-actions acps-mm-batch-actions">';
		h += '<button type="button" class="button button-primary acps-mm-batch-edit">' + esc( i18n.editIndividually || 'Edit each individually' ) + '</button>';
		h += '<button type="button" class="button acps-mm-batch-just">' + esc( genericCount ? ( i18n.justUploadRename || 'Rename the generic ones only' ) : ( i18n.justUpload || 'Just upload' ) ) + '</button>';
		h += '</p></div></div>';
		var $m = $( h ).appendTo( 'body' );
		$m.on( 'click', '.acps-mm-batch-edit', function () { $m.remove(); showUploadPopup(); } );
		$m.on( 'click', '.acps-mm-batch-just', function () {
			$m.remove();
			// Files are already filed (by the upload endpoint) into the folder you
			// were viewing — no re-filing needed. Non-generic files are done; only
			// the generic-named ones still have to be renamed (forced in the popup).
			uploadQueue = uploadQueue.filter( function ( it ) { return isGeneric( it.filename || '' ); } );
			if ( uploadQueue.length ) { showUploadPopup(); }
			else { loadGrid(); loadFolders(); }
		} );
	}

	function showUploadPopup() {
		// Queue drained (all files placed / renamed): reconcile the grid against the
		// server once so newly-uploaded files are guaranteed to show in the current
		// view — belt-and-suspenders on top of the per-file prepend below.
		if ( ! uploadQueue.length ) { loadGrid(); loadFolders(); return; }
		var item = uploadQueue[ 0 ];
		var id = item.id;
		// The upload endpoint already returned the grid card, recent folders and a
		// duplicate check — no extra round trip needed. A rename below updates card.
		var card = item.card || null;
		var generic = isGeneric( item.filename || '' );

		var h = '<div class="acps-mm-modal"><div class="acps-mm-modal-box"><h3>' + esc( i18n.uploaded ) + '</h3>';
		h += '<div class="acps-mm-dupewarn" style="display:none"></div>';

		h += '<div class="acps-mm-field"><label>' + esc( 'File name' ) + '</label><div class="acps-mm-urlrow">';
		h += '<input type="text" class="acps-mm-upfname" value="' + esc( baseNameNoExt( item.filename ) ) + '">';
		h += '<button type="button" class="button acps-mm-uprename" data-id="' + id + '">' + esc( i18n.rename || 'Rename' ) + '</button></div>';
		h += '<p class="acps-mm-genwarn" style="' + ( generic ? '' : 'display:none' ) + '">' + esc( i18n.genericName ) + '</p></div>';

		h += '<div class="acps-mm-field"><label>' + esc( 'File URL' ) + '</label><div class="acps-mm-urlrow"><input type="text" readonly class="acps-mm-url" value="' + esc( item.url ) + '"><button type="button" class="button acps-mm-copy" data-url="' + esc( item.url ) + '">' + esc( i18n.copyUrl ) + '</button></div></div>';
		if ( writable ) {
			h += '<div class="acps-mm-field"><label>' + esc( i18n.placeInFolder ) + '</label>';
			h += '<div class="acps-mm-chiprow"></div>'; // recent-folder chips (from the upload response)
			h += folderSelectHtml() + ' <button type="button" class="button acps-mm-place-sel" data-id="' + id + '">' + esc( i18n.move ) + '</button><span class="acps-mm-place-msg"></span>';
		}
		h += '<p class="acps-mm-modal-actions">';
		// Generic-named files can't be skipped — you must rename them. Only a
		// non-generic file gets a plain Cancel (keep as-is, edit later).
		if ( ! generic ) {
			h += '<button type="button" class="button acps-mm-upcancel-btn">' + esc( i18n.cancel || 'Cancel' ) + '</button> ';
		}
		h += '<button type="button" class="button button-primary acps-mm-upnext' + ( generic ? ' needs-rename' : '' ) + '"' + ( generic ? ' disabled' : '' ) + '>' + esc( i18n.done || 'Done' ) + '</button>';
		h += '</p></div></div>';
		var $m = $( h ).appendTo( 'body' );

		// Default placement = the folder it was already filed into (the one you
		// were viewing when you uploaded). Picking another folder moves it.
		var placedFid = currentUploadFolder();

		// Recent-folder chips straight from the upload response.
		var $chips = $m.find( '.acps-mm-chiprow' );
		if ( item.common && item.common.length ) {
			var chipHtml = '';
			item.common.forEach( function ( c ) { chipHtml += '<button type="button" class="button acps-mm-place-chip" data-fid="' + c.id + '">' + esc( c.name ) + '</button>'; } );
			$chips.html( chipHtml );
		} else {
			$chips.remove();
		}

		$m.on( 'click', '.acps-mm-place-chip', function () { placedFid = parseInt( $( this ).data( 'fid' ), 10 ) || 0; placeUpload( id, placedFid, $m ); } );
		$m.on( 'click', '.acps-mm-place-sel', function () { placedFid = parseInt( $m.find( '.acps-mm-picker-select' ).val(), 10 ) || 0; placeUpload( id, placedFid, $m ); } );
		$m.on( 'click', '.acps-mm-uprename', function () {
			var nb = $.trim( $m.find( '.acps-mm-upfname' ).val() );
			if ( ! nb || isGeneric( nb ) ) { window.alert( i18n.genericName ); return; }
			post( 'rename_file', { id: id, name: nb, confirm: 1 } ).done( function ( r ) {
				if ( r && r.success ) {
					$m.find( '.acps-mm-url' ).val( r.data.url );
					$m.find( '.acps-mm-copy' ).data( 'url', r.data.url );
					$m.find( '.acps-mm-genwarn' ).hide();
					$m.find( '.acps-mm-upnext' ).prop( 'disabled', false ).removeClass( 'needs-rename' );
					if ( card ) { card.filename = r.data.filename || card.filename; card.url = r.data.url || card.url; card.thumb = r.data.thumb || card.thumb; }
				} else { window.alert( ( r && r.data && r.data.message ) || i18n.error ); }
			} );
		} );

		// Duplicate warning (also from the upload response).
		var deletedSelf = false;
		$m.on( 'click', '.acps-mm-dupe-keepboth', function () { $m.find( '.acps-mm-dupewarn' ).slideUp( 150 ); } );
		$m.on( 'click', '.acps-mm-dupe-delete-copy', function () {
			post( 'delete', { ids: [ id ], confirm: 1 } ).done( function () { deletedSelf = true; finishPopup( false ); } );
		} );
		if ( item.duplicate ) {
			var d = item.duplicate;
			var w = '<p>' + esc( i18n.dupeUploadWarn || 'This file looks identical to an existing one:' ) + '</p>';
			w += '<div class="acps-mm-dupewarn-file"><img src="' + esc( d.thumb ) + '" alt=""><div><strong>' + esc( d.filename ) + '</strong><br><span class="acps-mm-muted">' + esc( d.date ) + '</span></div></div>';
			w += '<p class="acps-mm-dupewarn-actions">';
			w += '<a href="' + esc( d.url ) + '" target="_blank" rel="noopener" class="button">' + esc( i18n.viewExisting || 'View existing file' ) + '</a> ';
			w += '<button type="button" class="button acps-mm-dupe-delete-copy">' + esc( i18n.deleteThisCopy || 'Delete this copy' ) + '</button> ';
			w += '<button type="button" class="button acps-mm-dupe-keepboth">' + esc( i18n.keepBoth || 'Keep both' ) + '</button>';
			w += '</p>';
			$m.find( '.acps-mm-dupewarn' ).html( w ).show();
		}

		function finishPopup( addIt ) {
			// For a single-file upload, drop the final URL onto the clipboard as a
			// convenience (this runs from the Done click — a user gesture — so the
			// browser allows the copy, and it reflects any rename).
			if ( addIt && singleUpload && ! deletedSelf ) {
				var url = $m.find( '.acps-mm-copy' ).data( 'url' ) || item.url;
				if ( url ) { copyText( url ); toast( i18n.urlCopied || 'Link copied to clipboard' ); }
			}
			$m.remove();
			if ( addIt && ! deletedSelf && card && belongsInView( placedFid ) ) { prependCard( card ); }
			uploadQueue.shift();
			loadFolders(); // sidebar counts only — no grid reload
			showUploadPopup();
		}
		$m.on( 'click', '.acps-mm-upnext', function () { finishPopup( true ); } );
		$m.on( 'click', '.acps-mm-upcancel-btn', function () { finishPopup( false ); } );
	}
	// Would a newly-uploaded file (placed in folder `fid`) appear in the current view?
	function belongsInView( fid ) {
		if ( state.folder === 'all' ) { return true; }
		if ( state.folder === 'unfiled' ) { return ! fid; }
		if ( state.folder === 'unused' || state.folder === 'used' || String( state.folder ).indexOf( 'page:' ) === 0 ) { return false; }
		return String( state.folder ) === String( fid );
	}
	function placeUpload( id, fid, $m ) {
		post( 'move', { ids: [ id ], folder_id: fid } ).done( function ( res ) {
			$m.find( '.acps-mm-place-msg' ).text( ' ' + ( ( res && res.success ) ? i18n.saved : i18n.error ) );
		} );
	}

	/* ---------------- Scan now (usage) ---------------- */
	function runScan() {
		var $btn = $( '#acps-mm-scannow' ).prop( 'disabled', true );
		var $p = $( '#acps-mm-scanprog' ).show();
		var $fill = $p.find( '.acps-mm-scanprog-fill' ).css( 'width', '2%' );
		$( '#acps-mm-scaninfo' ).text( i18n.scanning || 'Scanning…' );

		$.post( A.ajaxUrl, { action: 'acps_mc_scan_start', nonce: A.scanNonce, resume: 0 } ).done( function ( res ) {
			if ( ! res || ! res.success ) { fail(); return; }
			step( res.data.step, res.data.offset );
		} ).fail( fail );

		function step( s, o ) {
			$.post( A.ajaxUrl, { action: 'acps_mc_scan_step', nonce: A.scanNonce, step: s, offset: o } ).done( function ( res ) {
				if ( ! res || ! res.success ) { fail(); return; }
				var d = res.data;
				$fill.css( 'width', Math.max( 2, d.percent ) + '%' );
				$( '#acps-mm-scaninfo' ).text( d.label + ' (' + d.percent + '%)' );
				if ( d.all_done ) { $fill.css( 'width', '100%' ); done(); return; }
				step( d.next_step, d.next_offset );
			} ).fail( fail );
		}
		function done() {
			$btn.prop( 'disabled', false );
			setTimeout( function () { $p.hide(); }, 800 );
			loadFolders();
			loadGrid();
		}
		function fail() {
			$btn.prop( 'disabled', false );
			$p.hide();
			$( '#acps-mm-scaninfo' ).text( i18n.error );
		}
	}

	/* ---------------- Find duplicates ---------------- */
	function findDuplicates() {
		var $btn = $( '#acps-mm-finddupes' ).prop( 'disabled', true );
		var originalLabel = $btn.text();
		$btn.text( i18n.scanningDupes || 'Scanning for duplicates…' );
		post( 'duplicates' ).done( function ( res ) {
			$btn.prop( 'disabled', false ).text( originalLabel );
			if ( ! res || ! res.success ) { window.alert( i18n.error ); return; }
			showDuplicatesModal( res.data.groups || [], !! res.data.more );
		} ).fail( function () {
			$btn.prop( 'disabled', false ).text( originalLabel );
			window.alert( i18n.error );
		} );
	}

	function showDuplicatesModal( groups, more ) {
		var h = '<div class="acps-mm-modal"><div class="acps-mm-modal-box acps-mm-dupe-box">';
		h += '<h3>' + esc( i18n.dupeTitle || 'Duplicate files' ) + '</h3>';
		if ( ! groups.length ) {
			h += '<p class="acps-mm-muted">' + esc( i18n.noDupes || 'No duplicate files found.' ) + '</p>';
		} else {
			h += '<div class="acps-mm-dupe-groups">';
			groups.forEach( function ( g, gi ) {
				h += '<div class="acps-mm-dupe-group" data-hash="' + esc( g.hash ) + '">';
				h += '<div class="acps-mm-dupe-files">';
				g.files.forEach( function ( f, fi ) {
					h += '<label class="acps-mm-dupe-file' + ( 0 === fi ? ' is-checked' : '' ) + '">';
					h += '<input type="radio" name="acps-mm-dupe-keep-' + gi + '" value="' + f.id + '"' + ( 0 === fi ? ' checked' : '' ) + '>';
					h += '<img src="' + esc( f.thumb ) + '" alt="">';
					h += '<span class="acps-mm-dupe-fname">' + esc( f.filename ) + '</span>';
					h += '<span class="acps-mm-dupe-fmeta">' + esc( f.sizeH ) + ' · ' + esc( f.date ) + '</span>';
					h += '</label>';
				} );
				h += '</div>';
				h += '<button type="button" class="button acps-mm-dupe-trash">' + esc( i18n.trashExtras || 'Trash the rest' ) + '</button>';
				h += '<span class="acps-mm-dupe-status"></span>';
				h += '</div>';
			} );
			h += '</div>';
			if ( more ) { h += '<p class="acps-mm-muted">' + esc( i18n.moreToScan || '' ) + '</p>'; }
		}
		h += '<p class="acps-mm-modal-actions"><button type="button" class="button acps-mm-dupe-close">' + esc( i18n.cancel || 'Close' ) + '</button></p>';
		h += '</div></div>';
		var $m = $( h ).appendTo( 'body' );

		$m.on( 'click', '.acps-mm-dupe-close', function () { $m.remove(); } );
		$m.on( 'change', '.acps-mm-dupe-file input[type="radio"]', function () {
			$( this ).closest( '.acps-mm-dupe-files' ).find( '.acps-mm-dupe-file' ).removeClass( 'is-checked' );
			$( this ).closest( '.acps-mm-dupe-file' ).addClass( 'is-checked' );
		} );
		$m.on( 'click', '.acps-mm-dupe-trash', function () {
			var $btn = $( this ).prop( 'disabled', true );
			var $group = $btn.closest( '.acps-mm-dupe-group' );
			var hash = $group.data( 'hash' );
			var keep = parseInt( $group.find( 'input:checked' ).val(), 10 ) || 0;
			var trash = [];
			$group.find( 'input[type="radio"]' ).each( function () {
				var v = parseInt( $( this ).val(), 10 );
				if ( v && v !== keep ) { trash.push( v ); }
			} );
			post( 'dedupe', { hash: hash, keep: keep, trash: trash } ).done( function ( res ) {
				if ( res && res.success ) {
					$group.fadeOut( 200, function () { $( this ).remove(); } );
					loadGrid();
					loadFolders();
				} else {
					$btn.prop( 'disabled', false );
					window.alert( ( res && res.data && res.data.message ) || i18n.error );
				}
			} ).fail( function () {
				$btn.prop( 'disabled', false );
				window.alert( i18n.error );
			} );
		} );
	}

	/* ---------------- Pages + rename helpers ---------------- */
	function loadPages() {
		post( 'pages' ).done( function ( res ) {
			if ( ! res || ! res.success ) { return; }
			var $s = $( '#acps-mm-page' );
			( res.data.pages || [] ).forEach( function ( p ) {
				$s.append( '<option value="page:' + p.id + '">' + esc( p.title ) + '</option>' );
			} );
		} );
	}
	// Optimistic rename: update the label immediately, do the work in background.
	function renameFile( id, name ) {
		name = String( name || '' ).trim();
		if ( ! name ) { return; }
		var $card = $( '.acps-mm-card[data-id="' + id + '"]' );
		if ( $card.hasClass( 'state-used' ) ) {
			if ( ! window.confirm( ( i18n.renameUsed || 'This file is used. Rename anyway?' ).replace( '%d', '' ) ) ) { return; }
		}
		// Instant UI update.
		$card.find( '.acps-mm-cap' ).text( name );
		$( '#acps-mm-drawer-inner .acps-mm-drawer-head h2' ).text( name );
		post( 'rename_file', { id: id, name: name, confirm: 1 } ).done( function ( res ) {
			if ( ! res || ! res.success ) {
				window.alert( ( res && res.data && res.data.message ) || i18n.error );
				loadGrid();
				return;
			}
			if ( res.data.filename ) { $card.find( '.acps-mm-cap' ).text( res.data.filename ); }
			if ( res.data.thumb ) { $card.find( 'img' ).attr( 'src', res.data.thumb + '?' + Date.now() ); }
			if ( res.data.url ) { $card.find( '.acps-mm-cardcopy' ).data( 'url', res.data.url ); }
			if ( String( lsGet( OPEN_KEY ) ) === String( id ) ) { openDetail( id ); }
		} ).fail( function () { window.alert( i18n.error ); loadGrid(); } );
	}

	/* ---------------- Bindings ---------------- */
	$( function () {
		// Restore the last-open folder + sub-folder toggle before the first load.
		var savedFolder = lsGet( FOLDER_KEY );
		if ( savedFolder ) {
			state.folder = savedFolder;
			if ( savedFolder.indexOf( 'page:' ) === 0 ) { $( '#acps-mm-page' ).val( savedFolder ); }
		}
		if ( lsGet( RECURSIVE_KEY ) === '1' ) { state.recursive = true; $( '#acps-mm-recursive' ).prop( 'checked', true ); }

		loadFolders();
		loadGrid( function () {
			if ( firstLoad ) { firstLoad = false; restoreLastState(); }
		} );
		loadPages();
		initUploader();

		// Persist scroll position (throttled) for next time.
		var scrollTimer;
		$( window ).on( 'scroll', function () {
			clearTimeout( scrollTimer );
			scrollTimer = setTimeout( function () { lsSet( SCROLL_KEY, String( window.pageYOffset || 0 ) ); }, 200 );
		} );

		// Apply saved card size.
		var savedSize = 180;
		try { savedSize = parseInt( window.localStorage.getItem( SIZE_KEY ), 10 ) || 180; } catch ( e ) {}
		$( '#acps-mm-size' ).val( String( savedSize ) );
		applyCardSize( savedSize );
		$( '#acps-mm-size' ).on( 'change', function () {
			applyCardSize( this.value );
			try { window.localStorage.setItem( SIZE_KEY, this.value ); } catch ( e ) {}
		} );

		// Apply saved view style (Classic looks like the normal media library).
		applyView( getView() );
		$( document ).on( 'click', '.acps-mm-viewbtn', function () {
			applyView( $( this ).data( 'view' ) );
		} );

		// "Used on page" filter.
		$( '#acps-mm-page' ).on( 'change', function () {
			var v = this.value;
			$( '.acps-mm-folder' ).removeClass( 'is-active' );
			state.folder = v ? v : 'all';
			lsSet( FOLDER_KEY, state.folder );
			loadGrid();
		} );

		// Folder caret (expand/collapse) — must not change the active folder.
		$( document ).on( 'click', '.acps-mm-caret', function ( e ) {
			e.stopPropagation();
			if ( $( this ).hasClass( 'empty' ) ) { return; }
			toggleExpanded( $( this ).data( 'fid' ) );
			renderSidebarFromCache();
		} );

		$( document ).on( 'click', '.acps-mm-folder', function () {
			selectFolder( $( this ).data( 'folder' ) );
		} );

		// Folder search: filter the folder list as you type (re-renders only the
		// list, so the search box keeps focus).
		$( document ).on( 'input', '#acps-mm-folder-search', function () {
			folderQuery = String( $( this ).val() || '' ).toLowerCase().trim();
			$( '.acps-mm-folderlist-wrap' ).html( folderListHtml( lastFolderData ) );
		} );

		// Include sub-folders toggle (initial state restored in init).
		$( '#acps-mm-recursive' ).on( 'change', function () {
			state.recursive = this.checked;
			lsSet( RECURSIVE_KEY, this.checked ? '1' : '0' );
			loadGrid();
		} );

		// Folder rename / delete.
		$( document ).on( 'click', '.acps-mm-frename', function ( e ) {
			e.stopPropagation();
			var fid = $( this ).data( 'fid' );
			var name = window.prompt( ( i18n.renameFolder || 'Rename folder' ) + ':', $( this ).data( 'name' ) );
			if ( ! name ) { return; }
			post( 'rename_folder', { id: fid, name: name } ).done( function ( res ) {
				if ( res && res.success ) { loadFolders(); } else { window.alert( ( res && res.data && res.data.message ) || i18n.error ); }
			} );
		} );
		$( document ).on( 'click', '.acps-mm-fdelete', function ( e ) {
			e.stopPropagation();
			if ( ! window.confirm( i18n.deleteFolderQ || 'Delete this folder? Files are kept.' ) ) { return; }
			var fid = $( this ).data( 'fid' );
			post( 'delete_folder', { id: fid } ).done( function ( res ) {
				if ( res && res.success ) {
					if ( String( state.folder ) === String( fid ) ) { state.folder = 'all'; }
					loadFolders();
					loadGrid();
				} else { window.alert( ( res && res.data && res.data.message ) || i18n.error ); }
			} );
		} );

		// Copy link straight from a card.
		$( document ).on( 'click', '.acps-mm-cardcopy', function ( e ) {
			e.stopPropagation();
			copyText( $( this ).data( 'url' ) );
			var $b = $( this ).addClass( 'copied' );
			setTimeout( function () { $b.removeClass( 'copied' ); }, 900 );
		} );

		// Rename file from the detail drawer.
		$( document ).on( 'click', '.acps-mm-rename', function () {
			var id = $( this ).data( 'id' );
			var name = window.prompt( i18n.renamePrompt || 'New file name:', $( this ).data( 'name' ) );
			if ( ! name ) { return; }
			renameFile( id, name );
		} );

		$( document ).on( 'click', '#acps-mm-newfolder', function () {
			var name = window.prompt( i18n.newFolderName );
			if ( ! name ) { return; }
			post( 'create_folder', { name: name, parent: 0 } ).done( function ( res ) {
				if ( res && res.success ) { loadFolders(); } else { window.alert( ( res && res.data && res.data.message ) || i18n.error ); }
			} );
		} );

		var searchTimer;
		$( '#acps-mm-search' ).on( 'input', function () {
			var v = this.value; clearTimeout( searchTimer );
			searchTimer = setTimeout( function () { state.search = v; loadGrid(); }, 350 );
		} );
		$( '#acps-mm-type' ).on( 'change', function () { state.type = this.value; loadGrid(); } );
		$( '#acps-mm-sort' ).on( 'change', function () { state.sort = this.value; loadGrid(); } );

		$( document ).on( 'change', '.acps-mm-cb', function ( e ) {
			e.stopPropagation();
			if ( this.checked ) { selection[ this.value ] = true; } else { delete selection[ this.value ]; }
			$( this ).closest( '.acps-mm-card' ).toggleClass( 'is-selected', this.checked );
			updateBulkBar();
		} );
		$( document ).on( 'click', '.acps-mm-check', function ( e ) { e.stopPropagation(); } );
		$( document ).on( 'click', '.acps-mm-card', function ( e ) {
			if ( $( this ).hasClass( 'acps-mm-foldercard' ) ) { selectFolder( $( this ).data( 'folder' ) ); return; }
			var id = $( this ).data( 'id' );
			// In select mode a click highlights the card (shift-click = range)
			// instead of opening it.
			if ( selectMode ) {
				if ( e.shiftKey && lastClicked !== null ) { selectRange( lastClicked, id ); }
				else { toggleCardSelection( id ); lastClicked = id; }
				return;
			}
			openDetail( id );
		} );

		// "Select" toggles select mode: click cards to highlight them, shift-click
		// for a range. (Individual checkboxes still work too.)
		$( '#acps-mm-selectall' ).on( 'change', function () {
			selectMode = this.checked;
			$( '#acps-mm-grid' ).toggleClass( 'acps-mm-selecting', selectMode );
			if ( ! selectMode ) { lastClicked = null; }
			updateBulkBar();
		} );

		$( '#acps-mm-bulk-all' ).on( 'click', function () {
			$( '#acps-mm-grid .acps-mm-card[data-id]' ).each( function () { setCardSelected( $( this ).data( 'id' ), true ); } );
			updateBulkBar();
		} );
		$( '#acps-mm-bulk-clear' ).on( 'click', clearSelection );
		$( '#acps-mm-bulk-move' ).on( 'click', function () {
			var ids = Object.keys( selection ).map( Number );
			if ( ! ids.length ) { return; }
			openFolderPicker( i18n.moveToFolder, function ( fid ) {
				post( 'move', { ids: ids, folder_id: fid } ).done( function ( res ) {
					if ( res && res.success ) { clearSelection(); loadGrid(); loadFolders(); } else { window.alert( ( res && res.data && res.data.message ) || i18n.error ); }
				} );
			} );
		} );
		$( '#acps-mm-bulk-alt' ).on( 'click', function () {
			var ids = Object.keys( selection ).map( Number );
			if ( ! ids.length ) { return; }
			var alt = window.prompt( i18n.altPrompt );
			if ( alt === null ) { return; }
			post( 'bulk_alt', { ids: ids, alt: alt } ).done( function ( res ) { if ( res && res.success ) { window.alert( i18n.saved ); } } );
		} );
		$( '#acps-mm-bulk-delete' ).on( 'click', function () {
			doDelete( Object.keys( selection ).map( Number ) );
		} );

		$( document ).on( 'click', '.acps-mm-drawer-close', closeDetail );
		$( '#acps-mm-backdrop' ).on( 'click', closeDetail );

		$( document ).on( 'click', '.acps-mm-copy', function () {
			copyText( $( this ).data( 'url' ) );
			var $b = $( this ), t = $b.text();
			$b.text( i18n.copied );
			setTimeout( function () { $b.text( t ); }, 1200 );
		} );

		$( document ).on( 'change', '.acps-mm-detail-folder', function () {
			var id = $( this ).data( 'id' ), fid = parseInt( this.value, 10 ) || 0;
			post( 'move', { ids: [ id ], folder_id: fid } ).done( function () { loadFolders(); } );
		} );

		$( document ).on( 'click', '.acps-mm-save', function () {
			var id = $( this ).data( 'id' );
			var $box = $( this ).closest( '#acps-mm-drawer-inner' );
			var data = { id: id };
			$box.find( '.acps-mm-in' ).each( function () { data[ $( this ).data( 'k' ) ] = $( this ).val(); } );
			var $msg = $box.find( '.acps-mm-saved-msg' );
			post( 'update_meta', data ).done( function ( res ) {
				$msg.text( res && res.success ? i18n.saved : i18n.error );
				setTimeout( function () { $msg.text( '' ); }, 1500 );
			} );
		} );

		$( document ).on( 'click', '.acps-mm-where', function () {
			var id = $( this ).data( 'id' );
			var $out = $( this ).siblings( '.acps-mm-where-out' );
			$out.html( '<p class="acps-mm-muted">' + esc( i18n.checking ) + '</p>' );
			post( 'where_used', { id: id } ).done( function ( res ) { renderWhere( $out, res && res.success ? res.data.locations : [] ); } );
		} );

		$( document ).on( 'click', '.acps-mm-convert', function () {
			var id = $( this ).data( 'id' );
			var $btn = $( this ).prop( 'disabled', true );
			$btn.siblings( '.acps-mm-convert-msg' ).text( ' ' + ( i18n.converting || 'Converting…' ) );
			post( 'convert_heic', { id: id } ).done( function ( res ) {
				if ( res && res.success ) { openDetail( id ); loadGrid(); }
				else { $btn.prop( 'disabled', false ).siblings( '.acps-mm-convert-msg' ).text( ' ' + ( ( res && res.data && res.data.message ) || i18n.error ) ); }
			} );
		} );

		$( document ).on( 'click', '.acps-mm-detail-delete', function () {
			doDelete( [ $( this ).data( 'id' ) ] );
		} );

		$( '#acps-mm-scannow' ).on( 'click', runScan );
		$( '#acps-mm-finddupes' ).on( 'click', findDuplicates );
		$( '#acps-mm-uploads-close' ).on( 'click', function () { $( '#acps-mm-uploads' ).hide(); } );

		// Drag overlay.
		var dragDepth = 0;
		function hasFiles( e ) {
			var dt = e.originalEvent && e.originalEvent.dataTransfer;
			if ( ! dt || ! dt.types ) { return false; }
			return Array.prototype.indexOf.call( dt.types, 'Files' ) > -1;
		}
		$( document ).on( 'dragenter', function ( e ) {
			if ( hasFiles( e ) ) { dragDepth++; $( '#acps-mm-dropmask' ).addClass( 'show' ); }
		} );
		$( document ).on( 'dragleave', function () { dragDepth = Math.max( 0, dragDepth - 1 ); if ( ! dragDepth ) { $( '#acps-mm-dropmask' ).removeClass( 'show' ); } } );
		$( document ).on( 'drop dragend', function () { dragDepth = 0; $( '#acps-mm-dropmask' ).removeClass( 'show' ); } );
	} );

	// Re-render sidebar from cached data (for collapse toggles — no server call).
	function renderSidebarFromCache() {
		if ( lastFolderData ) { renderSidebar( lastFolderData ); }
	}

	function copyText( text ) {
		if ( navigator.clipboard && navigator.clipboard.writeText ) { navigator.clipboard.writeText( text ); return; }
		var $t = $( '<textarea>' ).val( text ).appendTo( 'body' ).select();
		try { document.execCommand( 'copy' ); } catch ( e ) {}
		$t.remove();
	}

	// Brief bottom-center toast message.
	var toastTimer = null;
	function toast( text ) {
		var $t = $( '#acps-mm-toast' );
		if ( ! $t.length ) { $t = $( '<div id="acps-mm-toast" class="acps-mm-toast"></div>' ).appendTo( 'body' ); }
		$t.text( text ).addClass( 'show' );
		clearTimeout( toastTimer );
		toastTimer = setTimeout( function () { $t.removeClass( 'show' ); }, 1800 );
	}
} )( jQuery );
