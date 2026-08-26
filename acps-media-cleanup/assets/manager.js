/* global jQuery, wp, ACPS_MM */
( function ( $ ) {
	'use strict';

	var A = window.ACPS_MM || {};
	var i18n = A.i18n || {};

	var state = { folder: 'all', search: '', type: '', sort: 'date' };
	var selection = {};
	var folderTree = [];      // [{id,name,depth,parent,total}]
	var lastFolderData = null;
	var writable = false;
	var uploadQueue = [];
	var uploadRows = {};      // plupload file id -> jQuery row
	var EXPAND_KEY = 'acps_mm_expanded';   // folders are collapsed by default
	var SIZE_KEY = 'acps_mm_size';
	var VIEW_KEY = 'acps_mm_view';         // 'classic' (default) | 'refined'

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
		$( '#acps-mm-grid' ).removeClass( 'view-classic view-refined' ).addClass( 'view-' + view );
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
	function loadGrid() {
		$( '#acps-mm-grid' ).html( '<p class="acps-mm-muted">' + esc( i18n.loading ) + '</p>' );
		$( '#acps-mm-count' ).text( '' );
		post( 'query', {
			folder: state.folder,
			search: state.search,
			type: state.type,
			sort: state.sort,
			per_page: 20000
		} ).done( function ( res ) {
			if ( ! res || ! res.success ) {
				$( '#acps-mm-grid' ).html( '<p class="acps-mm-muted">' + esc( i18n.error ) + '</p>' );
				return;
			}
			renderAll( res.data.items );
			var cnt = res.data.returned + ( res.data.capped ? ' / ' + res.data.total : '' );
			$( '#acps-mm-count' ).text( cnt + ' ' + ( i18n.allMedia || 'items' ) );
		} );
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

	function renderAll( items ) {
		var $grid = $( '#acps-mm-grid' ).empty();
		if ( ! items.length ) {
			$grid.html( '<p class="acps-mm-muted">' + esc( i18n.noResults ) + '</p>' );
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
		}
		step();
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
		$( '#acps-mm-drawer' ).addClass( 'open' ).attr( 'aria-hidden', 'false' );
		$( '#acps-mm-backdrop' ).show();
		$( '#acps-mm-drawer-inner' ).html( '<p class="acps-mm-muted">' + esc( i18n.loading ) + '</p>' );
		post( 'detail', { id: id } ).done( function ( res ) {
			if ( ! res || ! res.success ) { $( '#acps-mm-drawer-inner' ).html( '<p>' + esc( i18n.error ) + '</p>' ); return; }
			renderDetail( res.data );
		} );
	}
	function closeDetail() {
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

	/* ---------------- Delete ---------------- */
	function doDelete( ids, afterFn ) {
		post( 'delete', { ids: ids, confirm: 0 } ).done( function ( res ) {
			if ( ! res || ! res.success ) { window.alert( ( res && res.data && res.data.message ) || i18n.error ); return; }
			if ( res.data.needs_confirm ) {
				var lines = res.data.used.map( function ( u ) { return '• ' + u.filename + ' (' + u.count + ')'; } ).join( '\n' );
				if ( ! window.confirm( i18n.usedWarn + '\n\n' + lines + '\n\n' + i18n.deleteAnyway + '?' ) ) { return; }
				post( 'delete', { ids: ids, confirm: 1 } ).done( function ( r2 ) {
					if ( r2 && r2.success ) { afterFn( r2.data ); } else { window.alert( i18n.error ); }
				} );
				return;
			}
			afterFn( res.data );
		} );
	}
	function afterDelete( data ) {
		$.each( data.items || {}, function ( id, r ) {
			if ( r.ok ) { delete selection[ id ]; $( '.acps-mm-card[data-id="' + id + '"]' ).fadeOut( 200, function () { $( this ).remove(); } ); }
		} );
		updateBulkBar();
		loadFolders();
	}

	/* ---------------- Upload (drag + progress) ---------------- */
	function showUploads() { $( '#acps-mm-uploads' ).show(); }
	function uploadRow( file ) {
		var $row = $( '<div class="acps-mm-uprow"><span class="acps-mm-upname">' + esc( file.name ) + '</span>' +
			'<span class="acps-mm-upbar"><span class="acps-mm-upfill"></span></span>' +
			'<span class="acps-mm-uppct">0%</span></div>' );
		$( '#acps-mm-uploads-list' ).prepend( $row );
		uploadRows[ file.id ] = $row;
		showUploads();
		return $row;
	}
	function setUploadPct( file, pct ) {
		var $row = uploadRows[ file.id ];
		if ( ! $row ) { $row = uploadRow( file ); }
		$row.find( '.acps-mm-upfill' ).css( 'width', pct + '%' );
		$row.find( '.acps-mm-uppct' ).text( pct + '%' );
	}
	function setUploadDone( file ) {
		var $row = uploadRows[ file.id ];
		if ( $row ) { $row.addClass( 'done' ).find( '.acps-mm-uppct' ).text( '✓' ); }
	}
	function setUploadError( file ) {
		var $row = uploadRows[ file.id ];
		if ( $row ) { $row.addClass( 'error' ).find( '.acps-mm-uppct' ).text( '✕' ); }
	}

	function initUploader() {
		if ( ! window.wp || ! wp.Uploader ) { return; }
		try {
			// eslint-disable-next-line no-new
			new wp.Uploader( {
				browser: $( '#acps-mm-upload' ),
				dropzone: $( '.acps-mm-wrap' ),
				added: function ( file ) { uploadRow( file ); },
				progress: function ( file ) { setUploadPct( file, file.percent || 0 ); },
				success: function ( attachment ) {
					var id = attachment.get ? attachment.get( 'id' ) : attachment.id;
					var file = attachment.get ? { id: attachment.get( 'id' ), name: attachment.get( 'filename' ) } : null;
					// Mark the matching row done by filename if we can.
					$.each( uploadRows, function ( fid, $r ) {
						if ( ! $r.hasClass( 'done' ) && ! $r.hasClass( 'error' ) ) { $r.addClass( 'done' ).find( '.acps-mm-upfill' ).css( 'width', '100%' ); $r.find( '.acps-mm-uppct' ).text( '✓' ); return false; }
					} );
					if ( id ) { uploadQueue.push( id ); if ( uploadQueue.length === 1 ) { showUploadPopup(); } }
				},
				error: function () {
					// Mark the first still-running row as failed (args vary by version).
					$.each( uploadRows, function ( fid, $r ) {
						if ( ! $r.hasClass( 'done' ) && ! $r.hasClass( 'error' ) ) { $r.addClass( 'error' ).find( '.acps-mm-uppct' ).text( '✕' ); return false; }
					} );
				}
			} );
		} catch ( e ) { /* uploader unavailable */ }
	}

	function showUploadPopup() {
		if ( ! uploadQueue.length ) { loadGrid(); loadFolders(); return; }
		var id = uploadQueue[ 0 ];
		post( 'upload_saved', { id: id, folder_id: 0 } ).done( function ( res ) {
			if ( ! res || ! res.success ) { uploadQueue.shift(); showUploadPopup(); return; }
			var d = res.data;
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
			h += '<p class="acps-mm-modal-actions"><button type="button" class="button button-primary acps-mm-upnext' + ( generic ? ' needs-rename' : '' ) + '"' + ( generic ? ' disabled' : '' ) + '>' + esc( i18n.done ) + '</button></p></div></div>';
			var $m = $( h ).appendTo( 'body' );
			$m.on( 'click', '.acps-mm-place-chip', function () { placeUpload( $( this ).data( 'id' ), $( this ).data( 'fid' ), $m ); } );
			$m.on( 'click', '.acps-mm-place-sel', function () { placeUpload( $( this ).data( 'id' ), parseInt( $m.find( '.acps-mm-picker-select' ).val(), 10 ) || 0, $m ); } );
			$m.on( 'click', '.acps-mm-uprename', function () {
				var nb = $.trim( $m.find( '.acps-mm-upfname' ).val() );
				if ( ! nb || isGeneric( nb ) ) { window.alert( i18n.genericName ); return; }
				post( 'rename_file', { id: id, name: nb, confirm: 1 } ).done( function ( r ) {
					if ( r && r.success ) {
						$m.find( '.acps-mm-url' ).val( r.data.url );
						$m.find( '.acps-mm-copy' ).data( 'url', r.data.url );
						$m.find( '.acps-mm-genwarn' ).hide();
						$m.find( '.acps-mm-upnext' ).prop( 'disabled', false ).removeClass( 'needs-rename' );
					} else { window.alert( ( r && r.data && r.data.message ) || i18n.error ); }
				} );
			} );
			$m.on( 'click', '.acps-mm-upnext', function () { $m.remove(); uploadQueue.shift(); showUploadPopup(); } );
		} );
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
	function renameFile( id, name, confirm ) {
		post( 'rename_file', { id: id, name: name, confirm: confirm ? 1 : 0 } ).done( function ( res ) {
			if ( ! res || ! res.success ) { window.alert( ( res && res.data && res.data.message ) || i18n.error ); return; }
			if ( res.data.needs_confirm ) {
				if ( window.confirm( ( i18n.renameUsed || 'Used in %d place(s). Rename anyway?' ).replace( '%d', res.data.count ) ) ) {
					renameFile( id, name, true );
				}
				return;
			}
			openDetail( id );
			loadGrid();
		} );
	}

	/* ---------------- Bindings ---------------- */
	$( function () {
		loadFolders();
		loadGrid();
		loadPages();
		initUploader();

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
			state.folder = $( this ).data( 'folder' );
			$( '#acps-mm-page' ).val( '' );
			$( '.acps-mm-folder' ).removeClass( 'is-active' );
			$( this ).addClass( 'is-active' );
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
			renameFile( id, name, false );
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
		$( document ).on( 'click', '.acps-mm-card', function () { openDetail( $( this ).data( 'id' ) ); } );

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
			var ids = Object.keys( selection ).map( Number );
			if ( ! ids.length ) { return; }
			if ( ! window.confirm( i18n.confirmTrash ) ) { return; }
			doDelete( ids, afterDelete );
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
			var id = $( this ).data( 'id' );
			doDelete( [ id ], function ( data ) {
				afterDelete( data );
				if ( data.items && data.items[ id ] && data.items[ id ].ok ) { closeDetail(); }
			} );
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
