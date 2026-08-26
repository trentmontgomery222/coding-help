/* global jQuery, wp, ACPS_MM */
( function ( $ ) {
	'use strict';

	var A = window.ACPS_MM || {};
	var i18n = A.i18n || {};

	var state = { folder: 'all', search: '', type: '', sort: 'date', recursive: false };
	var selection = {};
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

	function renderSidebar( data ) {
		var html = '<ul class="acps-mm-folderlist">';
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
		if ( writable ) {
			html += '<button type="button" class="button acps-mm-newfolder-btn" id="acps-mm-newfolder">+ ' + esc( i18n.newFolder || 'New folder' ) + '</button>';
		}
		$( '#acps-mm-folders' ).html( html );
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
		$( '#acps-mm-bulkbar' ).toggle( selCount() > 0 );
	}
	function clearSelection() {
		selection = {};
		$( '.acps-mm-cb' ).prop( 'checked', false );
		$( '.acps-mm-card' ).removeClass( 'is-selected' );
		$( '#acps-mm-selectall' ).prop( 'checked', false );
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

	/* ---------------- Upload (drag + progress) ---------------- */
	function showUploads() { $( '#acps-mm-uploads' ).show(); }
	function uploadRow( file ) {
		var $row = $( '<div class="acps-mm-uprow"><span class="acps-mm-upname">' + esc( file.name ) + '</span>' +
			'<span class="acps-mm-upbar"><span class="acps-mm-upfill"></span></span>' +
			'<span class="acps-mm-uppct">0%</span>' +
			'<button type="button" class="acps-mm-upcancel" title="' + esc( i18n.cancel || 'Cancel' ) + '">&times;</button></div>' );
		$row.on( 'click', '.acps-mm-upcancel', function () {
			if ( uploader && uploader.uploader && file ) {
				try { uploader.uploader.removeFile( file ); } catch ( e ) {}
			}
			$row.addClass( 'error' ).find( '.acps-mm-uppct' ).text( '✕' );
			$row.find( '.acps-mm-upcancel' ).remove();
		} );
		$( '#acps-mm-uploads-list' ).prepend( $row );
		uploadRows[ file.id ] = { $row: $row, file: file };
		showUploads();
		return $row;
	}
	function rowFor( file ) {
		var r = uploadRows[ file.id ];
		if ( ! r ) { uploadRow( file ); r = uploadRows[ file.id ]; }
		return r ? r.$row : null;
	}
	function setUploadPct( file, pct ) {
		var $row = rowFor( file );
		if ( $row ) { $row.find( '.acps-mm-upfill' ).css( 'width', pct + '%' ); $row.find( '.acps-mm-uppct' ).text( pct + '%' ); }
	}
	function markFirstRow( cls, mark ) {
		$.each( uploadRows, function ( fid, r ) {
			var $r = r.$row;
			if ( ! $r.hasClass( 'done' ) && ! $r.hasClass( 'error' ) ) {
				$r.addClass( cls ).find( '.acps-mm-upfill' ).css( 'width', '100%' );
				$r.find( '.acps-mm-uppct' ).text( mark );
				$r.find( '.acps-mm-upcancel' ).remove();
				return false;
			}
		} );
	}

	function initUploader() {
		if ( ! window.wp || ! wp.Uploader ) { return; }
		try {
			uploader = new wp.Uploader( {
				browser: $( '#acps-mm-upload' ),
				dropzone: $( '.acps-mm-wrap' ),
				added: function ( file ) { uploadRow( file ); },
				progress: function ( file ) { setUploadPct( file, file.percent || 0 ); },
				success: function ( attachment ) {
					var id = attachment.get ? attachment.get( 'id' ) : attachment.id;
					markFirstRow( 'done', '✓' );
					if ( id ) { uploadQueue.push( id ); if ( uploadQueue.length === 1 ) { showUploadPopup(); } }
				},
				error: function () { markFirstRow( 'error', '✕' ); }
			} );
		} catch ( e ) { /* uploader unavailable */ }
	}

	function showUploadPopup() {
		if ( ! uploadQueue.length ) { loadFolders(); return; }
		var id = uploadQueue[ 0 ];
		post( 'upload_saved', { id: id, folder_id: 0 } ).done( function ( res ) {
			if ( ! res || ! res.success ) { uploadQueue.shift(); showUploadPopup(); return; }
			var d = res.data;
			var card = d.card || null;
			var generic = isGeneric( d.filename || '' );
			var h = '<div class="acps-mm-modal"><div class="acps-mm-modal-box"><h3>' + esc( i18n.uploaded ) + '</h3>';

			// Filename (with generic-name guard).
			h += '<div class="acps-mm-field"><label>' + esc( 'File name' ) + '</label><div class="acps-mm-urlrow">';
			h += '<input type="text" class="acps-mm-upfname" value="' + esc( baseNameNoExt( d.filename ) ) + '">';
			h += '<button type="button" class="button acps-mm-uprename" data-id="' + id + '">' + esc( i18n.rename || 'Rename' ) + '</button></div>';
			h += '<p class="acps-mm-genwarn" style="' + ( generic ? '' : 'display:none' ) + '">' + esc( i18n.genericName ) + '</p></div>';

			h += '<div class="acps-mm-field"><label>' + esc( 'File URL' ) + '</label><div class="acps-mm-urlrow"><input type="text" readonly class="acps-mm-url" value="' + esc( d.url ) + '"><button type="button" class="button acps-mm-copy" data-url="' + esc( d.url ) + '">' + esc( i18n.copyUrl ) + '</button></div></div>';
			if ( writable ) {
				h += '<div class="acps-mm-field"><label>' + esc( i18n.placeInFolder ) + '</label>';
				if ( ( d.common || [] ).length ) {
					h += '<div class="acps-mm-chiprow">';
					d.common.forEach( function ( c ) { h += '<button type="button" class="button acps-mm-place-chip" data-id="' + id + '" data-fid="' + c.id + '">' + esc( c.name ) + '</button>'; } );
					h += '</div>';
				}
				h += folderSelectHtml() + ' <button type="button" class="button acps-mm-place-sel" data-id="' + id + '">' + esc( i18n.move ) + '</button><span class="acps-mm-place-msg"></span>';
			}
			h += '<p class="acps-mm-modal-actions">';
			h += '<button type="button" class="button acps-mm-upcancel-btn">' + esc( i18n.cancel || 'Cancel' ) + '</button> ';
			h += '<button type="button" class="button button-primary acps-mm-upnext' + ( generic ? ' needs-rename' : '' ) + '"' + ( generic ? ' disabled' : '' ) + '>' + esc( i18n.done || 'Done' ) + '</button>';
			h += '</p></div></div>';
			var $m = $( h ).appendTo( 'body' );

			var placedFid = 0;
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
			function finishPopup( addIt ) {
				$m.remove();
				if ( addIt && card && belongsInView( placedFid ) ) { prependCard( card ); }
				uploadQueue.shift();
				loadFolders(); // sidebar counts only — no grid reload
				showUploadPopup();
			}
			$m.on( 'click', '.acps-mm-upnext', function () { finishPopup( true ); } );
			$m.on( 'click', '.acps-mm-upcancel-btn', function () { finishPopup( false ); } );
		} );
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
		$( document ).on( 'click', '.acps-mm-card', function () {
			if ( $( this ).hasClass( 'acps-mm-foldercard' ) ) { selectFolder( $( this ).data( 'folder' ) ); return; }
			openDetail( $( this ).data( 'id' ) );
		} );

		$( '#acps-mm-selectall' ).on( 'change', function () {
			var on = this.checked;
			$( '.acps-mm-cb' ).each( function () {
				this.checked = on;
				if ( on ) { selection[ this.value ] = true; } else { delete selection[ this.value ]; }
				$( this ).closest( '.acps-mm-card' ).toggleClass( 'is-selected', on );
			} );
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
} )( jQuery );
