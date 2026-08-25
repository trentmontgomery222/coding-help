/* global jQuery, wp, ACPS_MM */
( function ( $ ) {
	'use strict';

	var A = window.ACPS_MM || {};
	var i18n = A.i18n || {};

	var state = {
		folder: 'all',
		search: '',
		type: '',
		sort: 'date',
		paged: 1,
		pages: 1
	};
	var selection = {};       // id -> true
	var folderList = [];      // flat [{id,name,depth,total}]
	var writable = false;
	var uploadQueue = [];

	function post( action, data ) {
		data = data || {};
		data.action = 'acps_mm_' + action;
		data.nonce = A.nonce;
		return $.post( A.ajaxUrl, data );
	}
	function esc( s ) {
		return $( '<div/>' ).text( s == null ? '' : String( s ) ).html();
	}
	function indent( d ) {
		var s = '';
		for ( var i = 0; i < d; i++ ) { s += '— '; }
		return s;
	}

	/* ---------------- Folders sidebar ---------------- */
	function loadFolders() {
		post( 'folders' ).done( function ( res ) {
			if ( ! res || ! res.success ) { return; }
			writable = res.data.writable;
			folderList = res.data.tree || [];
			renderSidebar( res.data );
		} );
	}

	function renderSidebar( data ) {
		var html = '<ul class="acps-mm-folderlist">';
		html += folderItem( 'all', i18n.allMedia, 0, data.total, state.folder === 'all' );
		html += folderItem( 'unfiled', i18n.unfiled, 0, '', state.folder === 'unfiled' );
		( data.tree || [] ).forEach( function ( f ) {
			html += folderItem( f.id, f.name, f.depth + 1, f.total, String( state.folder ) === String( f.id ) );
		} );
		html += '</ul>';
		if ( writable ) {
			html += '<button type="button" class="button acps-mm-newfolder-btn" id="acps-mm-newfolder">+ ' + esc( i18n.newFolder ) + '</button>';
		}
		$( '#acps-mm-folders' ).html( html );
	}

	function folderItem( id, name, depth, count, active ) {
		var badge = ( count === '' || count == null ) ? '' : '<span class="acps-mm-fcount">' + count + '</span>';
		return '<li class="acps-mm-folder' + ( active ? ' is-active' : '' ) + '" data-folder="' + esc( id ) + '" style="padding-left:' + ( 10 + depth * 14 ) + 'px">' +
			'<span class="acps-mm-fname">' + esc( indent( depth ? depth - 1 : 0 ) ) + '<span class="dashicons dashicons-portfolio"></span> ' + esc( name ) + '</span>' + badge + '</li>';
	}

	/* ---------------- Grid ---------------- */
	function loadGrid( reset ) {
		if ( reset ) {
			state.paged = 1;
			$( '#acps-mm-grid' ).html( '<p class="acps-mm-muted">' + esc( i18n.loading ) + '</p>' );
		}
		post( 'query', {
			folder: state.folder,
			search: state.search,
			type: state.type,
			sort: state.sort,
			paged: state.paged,
			per_page: A.perPage
		} ).done( function ( res ) {
			if ( ! res || ! res.success ) {
				$( '#acps-mm-grid' ).html( '<p class="acps-mm-muted">' + esc( i18n.error ) + '</p>' );
				return;
			}
			state.pages = res.data.pages;
			renderCards( res.data.items, reset );
			$( '#acps-mm-loadmore' ).toggle( state.paged < state.pages );
		} );
	}

	function renderCards( items, reset ) {
		var $grid = $( '#acps-mm-grid' );
		if ( reset ) { $grid.empty(); }
		if ( reset && ! items.length ) {
			$grid.html( '<p class="acps-mm-muted">' + esc( i18n.noResults ) + '</p>' );
			return;
		}
		var html = '';
		items.forEach( function ( f ) {
			var sel = selection[ f.id ] ? ' is-selected' : '';
			html += '<div class="acps-mm-card' + sel + '" data-id="' + f.id + '">';
			html += '<label class="acps-mm-check"><input type="checkbox" class="acps-mm-cb" value="' + f.id + '"' + ( selection[ f.id ] ? ' checked' : '' ) + '></label>';
			html += '<div class="acps-mm-thumb-wrap">';
			if ( f.thumb ) {
				html += '<img src="' + esc( f.thumb ) + '" alt="" loading="lazy">';
			} else {
				html += '<span class="dashicons dashicons-media-default"></span>';
			}
			html += '</div>';
			html += '<div class="acps-mm-cap" title="' + esc( f.filename ) + '">' + esc( f.filename || f.title ) + '</div>';
			html += '</div>';
		} );
		$grid.append( html );
	}

	/* ---------------- Selection ---------------- */
	function selCount() { return Object.keys( selection ).length; }
	function updateBulkBar() {
		var n = selCount();
		$( '#acps-mm-selcount' ).text( n );
		$( '#acps-mm-bulkbar' ).toggle( n > 0 );
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
			if ( ! res || ! res.success ) {
				$( '#acps-mm-drawer-inner' ).html( '<p>' + esc( i18n.error ) + '</p>' );
				return;
			}
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
		if ( d.isImage && d.url ) {
			h += '<img src="' + esc( d.url ) + '" alt="">';
		} else if ( d.thumb ) {
			h += '<img src="' + esc( d.thumb ) + '" alt="">';
		}
		h += '</div>';

		// URL + copy
		h += '<div class="acps-mm-field"><label>' + esc( 'File URL' ) + '</label>';
		h += '<div class="acps-mm-urlrow"><input type="text" readonly class="acps-mm-url" value="' + esc( d.url ) + '">';
		h += '<button type="button" class="button acps-mm-copy" data-url="' + esc( d.url ) + '">' + esc( i18n.copyUrl ) + '</button></div></div>';

		// Meta facts
		h += '<p class="acps-mm-facts">' + esc( d.mime ) + ( d.sizeH ? ' · ' + esc( d.sizeH ) : '' ) + ( d.date ? ' · ' + esc( d.date ) : '' ) + '</p>';

		// Folder select
		if ( d.writable ) {
			h += '<div class="acps-mm-field"><label>' + esc( 'Folder' ) + '</label>';
			h += '<select class="acps-mm-detail-folder" data-id="' + d.id + '">';
			( d.folders || [] ).forEach( function ( f ) {
				h += '<option value="' + f.id + '"' + ( String( f.id ) === String( d.folderId ) ? ' selected' : '' ) + '>' + esc( indent( f.depth ) + f.name ) + '</option>';
			} );
			h += '</select></div>';
		}

		// Editable fields
		h += '<div class="acps-mm-field"><label>' + esc( 'Title' ) + '</label><input type="text" class="acps-mm-in" data-k="title" value="' + esc( d.title ) + '"></div>';
		h += '<div class="acps-mm-field"><label>' + esc( 'Alt text' ) + '</label><input type="text" class="acps-mm-in" data-k="alt" value="' + esc( d.alt ) + '"></div>';
		h += '<div class="acps-mm-field"><label>' + esc( 'Caption' ) + '</label><textarea class="acps-mm-in" data-k="caption" rows="2">' + esc( d.caption ) + '</textarea></div>';
		h += '<div class="acps-mm-field"><label>' + esc( 'Description' ) + '</label><textarea class="acps-mm-in" data-k="description" rows="2">' + esc( d.description ) + '</textarea></div>';
		h += '<p><button type="button" class="button button-primary acps-mm-save" data-id="' + d.id + '">' + esc( 'Save changes' ) + '</button> <span class="acps-mm-saved-msg"></span></p>';

		// Where used
		h += '<div class="acps-mm-field"><label>' + esc( i18n.usedIn ) + '</label>';
		h += '<button type="button" class="button acps-mm-where" data-id="' + d.id + '">' + esc( i18n.whereUsed ) + '</button>';
		h += '<div class="acps-mm-where-out"></div></div>';

		// Actions
		h += '<div class="acps-mm-drawer-actions">';
		if ( d.imageEdit ) {
			h += '<a class="button" href="' + esc( d.imageEdit ) + '">' + esc( 'Edit image' ) + '</a>';
		}
		h += '<button type="button" class="button button-link-delete acps-mm-detail-delete" data-id="' + d.id + '">' + esc( 'Delete' ) + '</button>';
		h += '</div>';

		$( '#acps-mm-drawer-inner' ).html( h );
	}

	/* ---------------- Where used render ---------------- */
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

	/* ---------------- Folder picker overlay ---------------- */
	function folderSelectHtml( selectedId ) {
		var h = '<select class="acps-mm-picker-select"><option value="0">' + esc( '— Unfiled —' ) + '</option>';
		folderList.forEach( function ( f ) {
			h += '<option value="' + f.id + '"' + ( String( f.id ) === String( selectedId ) ? ' selected' : '' ) + '>' + esc( indent( f.depth ) + f.name ) + '</option>';
		} );
		h += '</select>';
		return h;
	}

	function openFolderPicker( title, onPick ) {
		var h = '<div class="acps-mm-modal"><div class="acps-mm-modal-box">';
		h += '<h3>' + esc( title ) + '</h3>';
		h += folderSelectHtml( '' );
		if ( writable ) {
			h += '<p><button type="button" class="button-link acps-mm-picker-new">+ ' + esc( i18n.newFolder ) + '</button></p>';
		}
		h += '<p class="acps-mm-modal-actions"><button type="button" class="button button-primary acps-mm-picker-ok">' + esc( i18n.move ) + '</button> ';
		h += '<button type="button" class="button acps-mm-picker-cancel">' + esc( i18n.cancel ) + '</button></p>';
		h += '</div></div>';
		var $m = $( h ).appendTo( 'body' );

		$m.on( 'click', '.acps-mm-picker-cancel', function () { $m.remove(); } );
		$m.on( 'click', '.acps-mm-picker-new', function () {
			var name = window.prompt( i18n.newFolderName );
			if ( ! name ) { return; }
			post( 'create_folder', { name: name, parent: 0 } ).done( function ( res ) {
				if ( res && res.success ) {
					folderList.push( { id: res.data.id, name: res.data.name, depth: 0, total: 0 } );
					$m.find( '.acps-mm-picker-select' ).append( '<option value="' + res.data.id + '" selected>' + esc( res.data.name ) + '</option>' );
				} else {
					window.alert( ( res && res.data && res.data.message ) || i18n.error );
				}
			} );
		} );
		$m.on( 'click', '.acps-mm-picker-ok', function () {
			var fid = parseInt( $m.find( '.acps-mm-picker-select' ).val(), 10 ) || 0;
			$m.remove();
			onPick( fid );
		} );
	}

	/* ---------------- Delete flow ---------------- */
	function doDelete( ids, afterFn ) {
		post( 'delete', { ids: ids, confirm: 0 } ).done( function ( res ) {
			if ( ! res || ! res.success ) {
				window.alert( ( res && res.data && res.data.message ) || i18n.error );
				return;
			}
			if ( res.data.needs_confirm ) {
				var lines = res.data.used.map( function ( u ) {
					return '• ' + u.filename + ' (' + u.count + ')';
				} ).join( '\n' );
				if ( ! window.confirm( i18n.usedWarn + '\n\n' + lines + '\n\n' + i18n.deleteAnyway + '?' ) ) {
					return;
				}
				post( 'delete', { ids: ids, confirm: 1 } ).done( function ( r2 ) {
					if ( r2 && r2.success ) { afterFn( r2.data ); }
					else { window.alert( i18n.error ); }
				} );
				return;
			}
			afterFn( res.data );
		} );
	}

	function afterDelete( data ) {
		$.each( data.items || {}, function ( id, r ) {
			if ( r.ok ) {
				delete selection[ id ];
				$( '.acps-mm-card[data-id="' + id + '"]' ).fadeOut( 200, function () { $( this ).remove(); } );
			}
		} );
		var skipped = [];
		$.each( data.items || {}, function ( id, r ) {
			if ( ! r.ok && r.reason ) { skipped.push( '#' + id + ': ' + r.reason ); }
		} );
		if ( skipped.length ) { window.alert( skipped.join( '\n' ) ); }
		updateBulkBar();
		loadFolders();
	}

	/* ---------------- Upload ---------------- */
	function initUploader() {
		if ( ! window.wp || ! wp.Uploader ) { return; }
		try {
			// eslint-disable-next-line no-new
			new wp.Uploader( {
				browser: $( '#acps-mm-upload' ),
				dropzone: $( '.acps-mm-main' ),
				success: function ( attachment ) {
					var id = attachment.get ? attachment.get( 'id' ) : attachment.id;
					if ( id ) {
						uploadQueue.push( id );
						if ( uploadQueue.length === 1 ) { showUploadPopup(); }
					}
				}
			} );
		} catch ( e ) { /* uploader unavailable */ }
	}

	function showUploadPopup() {
		if ( ! uploadQueue.length ) { loadGrid( true ); loadFolders(); return; }
		var id = uploadQueue[ 0 ];
		post( 'upload_saved', { id: id, folder_id: 0 } ).done( function ( res ) {
			if ( ! res || ! res.success ) { uploadQueue.shift(); showUploadPopup(); return; }
			var d = res.data;
			var h = '<div class="acps-mm-modal"><div class="acps-mm-modal-box">';
			h += '<h3>' + esc( i18n.uploaded ) + '</h3>';
			h += '<div class="acps-mm-field"><label>' + esc( 'File URL' ) + '</label><div class="acps-mm-urlrow"><input type="text" readonly class="acps-mm-url" value="' + esc( d.url ) + '"><button type="button" class="button acps-mm-copy" data-url="' + esc( d.url ) + '">' + esc( i18n.copyUrl ) + '</button></div></div>';

			if ( writable ) {
				h += '<div class="acps-mm-field"><label>' + esc( i18n.placeInFolder ) + '</label>';
				if ( ( d.common || [] ).length ) {
					h += '<div class="acps-mm-chiprow">';
					d.common.forEach( function ( c ) {
						h += '<button type="button" class="button acps-mm-place-chip" data-id="' + id + '" data-fid="' + c.id + '">' + esc( c.name ) + '</button>';
					} );
					h += '</div>';
				}
				h += folderSelectHtml( '' );
				h += ' <button type="button" class="button acps-mm-place-sel" data-id="' + id + '">' + esc( i18n.move ) + '</button>';
				h += '<span class="acps-mm-place-msg"></span>';
			}

			h += '<p class="acps-mm-modal-actions"><button type="button" class="button button-primary acps-mm-upnext">' + esc( i18n.done ) + '</button></p>';
			h += '</div></div>';
			var $m = $( h ).appendTo( 'body' );

			$m.on( 'click', '.acps-mm-place-chip', function () {
				placeUpload( $( this ).data( 'id' ), $( this ).data( 'fid' ), $m );
			} );
			$m.on( 'click', '.acps-mm-place-sel', function () {
				var fid = parseInt( $m.find( '.acps-mm-picker-select' ).val(), 10 ) || 0;
				placeUpload( $( this ).data( 'id' ), fid, $m );
			} );
			$m.on( 'click', '.acps-mm-upnext', function () {
				$m.remove();
				uploadQueue.shift();
				showUploadPopup();
			} );
		} );
	}

	function placeUpload( id, fid, $m ) {
		post( 'move', { ids: [ id ], folder_id: fid } ).done( function ( res ) {
			var msg = ( res && res.success ) ? i18n.saved : i18n.error;
			$m.find( '.acps-mm-place-msg' ).text( ' ' + msg );
		} );
	}

	/* ---------------- Bindings ---------------- */
	$( function () {
		loadFolders();
		loadGrid( true );
		initUploader();

		// Folder click.
		$( document ).on( 'click', '.acps-mm-folder', function () {
			state.folder = $( this ).data( 'folder' );
			$( '.acps-mm-folder' ).removeClass( 'is-active' );
			$( this ).addClass( 'is-active' );
			loadGrid( true );
		} );

		// New folder (sidebar).
		$( document ).on( 'click', '#acps-mm-newfolder', function () {
			var name = window.prompt( i18n.newFolderName );
			if ( ! name ) { return; }
			post( 'create_folder', { name: name, parent: 0 } ).done( function ( res ) {
				if ( res && res.success ) { loadFolders(); }
				else { window.alert( ( res && res.data && res.data.message ) || i18n.error ); }
			} );
		} );

		// Toolbar.
		var searchTimer;
		$( '#acps-mm-search' ).on( 'input', function () {
			var v = this.value;
			clearTimeout( searchTimer );
			searchTimer = setTimeout( function () { state.search = v; loadGrid( true ); }, 350 );
		} );
		$( '#acps-mm-type' ).on( 'change', function () { state.type = this.value; loadGrid( true ); } );
		$( '#acps-mm-sort' ).on( 'change', function () { state.sort = this.value; loadGrid( true ); } );

		// Load more.
		$( '#acps-mm-loadmore-btn' ).on( 'click', function () { state.paged++; loadGrid( false ); } );

		// Card select / open.
		$( document ).on( 'change', '.acps-mm-cb', function ( e ) {
			e.stopPropagation();
			var id = this.value;
			if ( this.checked ) { selection[ id ] = true; } else { delete selection[ id ]; }
			$( this ).closest( '.acps-mm-card' ).toggleClass( 'is-selected', this.checked );
			updateBulkBar();
		} );
		$( document ).on( 'click', '.acps-mm-check', function ( e ) { e.stopPropagation(); } );
		$( document ).on( 'click', '.acps-mm-card', function () { openDetail( $( this ).data( 'id' ) ); } );

		$( '#acps-mm-selectall' ).on( 'change', function () {
			var on = this.checked;
			$( '.acps-mm-cb' ).each( function () {
				this.checked = on;
				var id = this.value;
				if ( on ) { selection[ id ] = true; } else { delete selection[ id ]; }
				$( this ).closest( '.acps-mm-card' ).toggleClass( 'is-selected', on );
			} );
			updateBulkBar();
		} );

		// Bulk actions.
		$( '#acps-mm-bulk-clear' ).on( 'click', clearSelection );
		$( '#acps-mm-bulk-move' ).on( 'click', function () {
			var ids = Object.keys( selection ).map( Number );
			if ( ! ids.length ) { return; }
			openFolderPicker( i18n.moveToFolder, function ( fid ) {
				post( 'move', { ids: ids, folder_id: fid } ).done( function ( res ) {
					if ( res && res.success ) { clearSelection(); loadGrid( true ); loadFolders(); }
					else { window.alert( ( res && res.data && res.data.message ) || i18n.error ); }
				} );
			} );
		} );
		$( '#acps-mm-bulk-alt' ).on( 'click', function () {
			var ids = Object.keys( selection ).map( Number );
			if ( ! ids.length ) { return; }
			var alt = window.prompt( i18n.altPrompt );
			if ( alt === null ) { return; }
			post( 'bulk_alt', { ids: ids, alt: alt } ).done( function ( res ) {
				if ( res && res.success ) { window.alert( i18n.saved ); }
			} );
		} );
		$( '#acps-mm-bulk-delete' ).on( 'click', function () {
			var ids = Object.keys( selection ).map( Number );
			if ( ! ids.length ) { return; }
			if ( ! window.confirm( i18n.confirmTrash ) ) { return; }
			doDelete( ids, afterDelete );
		} );

		// Drawer interactions.
		$( document ).on( 'click', '.acps-mm-drawer-close', closeDetail );
		$( '#acps-mm-backdrop' ).on( 'click', closeDetail );

		$( document ).on( 'click', '.acps-mm-copy', function () {
			var url = $( this ).data( 'url' );
			copyText( url );
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
			post( 'where_used', { id: id } ).done( function ( res ) {
				renderWhere( $out, res && res.success ? res.data.locations : [] );
			} );
		} );

		$( document ).on( 'click', '.acps-mm-detail-delete', function () {
			var id = $( this ).data( 'id' );
			doDelete( [ id ], function ( data ) {
				afterDelete( data );
				if ( data.items && data.items[ id ] && data.items[ id ].ok ) { closeDetail(); }
			} );
		} );
	} );

	function copyText( text ) {
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text );
			return;
		}
		var $t = $( '<textarea>' ).val( text ).appendTo( 'body' ).select();
		try { document.execCommand( 'copy' ); } catch ( e ) {}
		$t.remove();
	}
} )( jQuery );
