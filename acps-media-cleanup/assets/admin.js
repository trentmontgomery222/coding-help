/* global jQuery, ACPS_MC */
( function ( $ ) {
	'use strict';

	var A = window.ACPS_MC || {};
	var i18n = A.i18n || {};

	function post( action, data ) {
		data = data || {};
		data.action = 'acps_mc_' + action;
		data.nonce = A.nonce;
		return $.post( A.ajaxUrl, data );
	}

	function humanSize( bytes ) {
		bytes = parseInt( bytes, 10 ) || 0;
		var units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		var i = 0;
		while ( bytes >= 1024 && i < units.length - 1 ) {
			bytes /= 1024;
			i++;
		}
		return ( i === 0 ? bytes : bytes.toFixed( 1 ) ) + ' ' + units[ i ];
	}

	function esc( s ) {
		return $( '<div/>' ).text( s == null ? '' : String( s ) ).html();
	}

	/* ============================================================
	 * SCAN
	 * ============================================================ */
	function runScan() {
		var $btn = $( '#acps-mc-scan-btn' );
		var $prog = $( '#acps-mc-progress' );
		var $fill = $prog.find( '.acps-mc-progress-fill' );
		var $label = $prog.find( '.acps-mc-progress-label' );

		$btn.prop( 'disabled', true );
		$prog.show();
		$fill.css( 'width', '2%' );
		$label.text( i18n.scanning || 'Scanning…' );

		post( 'scan_start' ).done( function ( res ) {
			if ( ! res || ! res.success ) {
				fail();
				return;
			}
			stepScan( res.data.step, res.data.offset );
		} ).fail( fail );

		function stepScan( step, offset ) {
			post( 'scan_step', { step: step, offset: offset } ).done( function ( res ) {
				if ( ! res || ! res.success ) {
					fail();
					return;
				}
				var d = res.data;
				$fill.css( 'width', Math.max( 2, d.percent ) + '%' );
				$label.text( d.label + ' (' + d.percent + '%)' );

				if ( d.all_done ) {
					$fill.css( 'width', '100%' );
					$label.text( ( i18n.done || 'Scan complete' ) );
					finishScan();
					return;
				}
				stepScan( d.next_step, d.next_offset );
			} ).fail( fail );
		}

		function finishScan() {
			post( 'state' ).done( function ( res ) {
				if ( res && res.success && res.data.summary ) {
					$( '#acps-mc-summary' ).html( res.data.summary );
				}
				$btn.prop( 'disabled', false );
				setTimeout( function () { $prog.fadeOut(); }, 900 );
			} );
		}

		function fail() {
			$label.text( i18n.workingError || 'Something went wrong.' );
			$btn.prop( 'disabled', false );
		}
	}

	/* ============================================================
	 * FOLDERS VIEW
	 * ============================================================ */
	var currentFolder = null;

	function loadTree() {
		post( 'state' ).done( function ( res ) {
			if ( ! res || ! res.success ) {
				return;
			}
			renderTree( res.data.tree || [] );
		} );
	}

	function renderTree( tree ) {
		var $tree = $( '#acps-mc-tree' );
		if ( ! $tree.length ) {
			return;
		}
		if ( ! tree.length ) {
			$tree.html( '<p class="acps-mc-muted">' + esc( i18n.noFolderFiles || 'Nothing to show.' ) + '</p>' );
			return;
		}
		var html = '<ul class="acps-mc-folderlist">';
		tree.forEach( function ( f ) {
			var dim = f.unused === 0 ? ' is-empty' : '';
			var active = ( currentFolder !== null && parseInt( currentFolder, 10 ) === parseInt( f.id, 10 ) ) ? ' is-active' : '';
			html += '<li class="acps-mc-folder' + dim + active + '" data-folder="' + esc( f.id ) + '" style="padding-left:' + ( 8 + f.depth * 16 ) + 'px">';
			html += '<span class="fname"><span class="dashicons dashicons-portfolio"></span> ' + esc( f.name ) + '</span>';
			html += '<span class="fmeta"><span class="badge">' + f.unused + '</span>';
			if ( f.unused_bytes > 0 ) {
				html += ' <span class="fsize">' + esc( humanSize( f.unused_bytes ) ) + '</span>';
			}
			html += '</span></li>';
		} );
		html += '</ul>';
		$tree.html( html );
	}

	function loadFolder( folderId ) {
		currentFolder = folderId;
		$( '.acps-mc-folder' ).removeClass( 'is-active' );
		$( '.acps-mc-folder[data-folder="' + folderId + '"]' ).addClass( 'is-active' );

		var $files = $( '#acps-mc-files' );
		$files.html( '<p class="acps-mc-muted">…</p>' );

		post( 'folder_files', {
			folder_id: folderId,
			include_sub: $( '#acps-mc-include-sub' ).is( ':checked' ) ? 1 : 0,
			show_used: $( '#acps-mc-show-used' ).is( ':checked' ) ? 1 : 0
		} ).done( function ( res ) {
			if ( ! res || ! res.success ) {
				$files.html( '<p class="acps-mc-muted">' + esc( i18n.workingError ) + '</p>' );
				return;
			}
			renderFiles( res.data );
		} );
	}

	function renderFiles( data ) {
		var files = data.files || [];
		var $files = $( '#acps-mc-files' );

		if ( ! files.length ) {
			$files.html( '<p class="acps-mc-muted">' + esc( i18n.noFolderFiles ) + '</p>' );
			updateActionBar();
			return;
		}

		var html = '';
		if ( data.capped ) {
			html += '<p class="acps-mc-muted">' + esc( 'Showing first ' + data.cap + ' of ' + data.total + ' files.' ) + '</p>';
		}
		html += '<table class="widefat striped acps-mc-table acps-mc-filetable">';
		html += '<thead><tr>';
		html += '<td class="check-column"><input type="checkbox" id="acps-mc-selectall" title="Select all unused"></td>';
		html += '<th>' + esc( 'File' ) + '</th>';
		html += '<th>' + esc( 'Type' ) + '</th>';
		html += '<th>' + esc( 'Uploaded' ) + '</th>';
		html += '<th>' + esc( 'Size' ) + '</th>';
		html += '<th>' + esc( 'Status' ) + '</th>';
		html += '<th></th>';
		html += '</tr></thead><tbody>';

		files.forEach( function ( f ) {
			var rowCls = f.used ? 'is-used' : 'is-unused';
			if ( f.excluded ) {
				rowCls += ' is-excluded';
			}
			html += '<tr class="' + rowCls + '" data-id="' + f.id + '" data-size="' + f.size + '">';

			html += '<th scope="row" class="check-column">';
			if ( ! f.used && ! f.excluded ) {
				html += '<input type="checkbox" class="acps-mc-file-check" value="' + f.id + '">';
			}
			html += '</th>';

			// File cell with thumb.
			html += '<td class="acps-mc-filecell">';
			if ( f.thumb ) {
				html += '<img src="' + esc( f.thumb ) + '" alt="" class="acps-mc-thumb">';
			} else {
				html += '<span class="acps-mc-thumb acps-mc-thumb-icon dashicons dashicons-media-default"></span>';
			}
			html += '<span class="acps-mc-fileinfo"><strong>' + esc( f.filename || f.title ) + '</strong>';
			if ( f.url ) {
				html += '<br><a href="' + esc( f.url ) + '" target="_blank" rel="noopener">' + esc( 'view' ) + '</a>';
				if ( f.edit ) {
					html += ' · <a href="' + esc( f.edit ) + '">' + esc( 'edit' ) + '</a>';
				}
			}
			html += '</span></td>';

			html += '<td>' + esc( ( f.ext || '' ).toUpperCase() ) + '</td>';
			html += '<td>' + esc( f.date ) + '</td>';
			html += '<td>' + esc( f.size_h ) + '</td>';

			// Status.
			html += '<td>';
			if ( f.used ) {
				html += '<span class="acps-mc-badge acps-mc-badge-used" title="' + esc( f.reason ) + '">' + esc( i18n.used ) + '</span>';
			} else if ( f.excluded ) {
				html += '<span class="acps-mc-badge acps-mc-badge-protected">' + esc( i18n.protected ) + '</span>';
			} else {
				html += '<span class="acps-mc-badge acps-mc-badge-unused">' + esc( i18n.unused ) + '</span>';
			}
			html += '</td>';

			// Protect toggle.
			html += '<td class="acps-mc-rowactions">';
			if ( ! f.used ) {
				var label = f.excluded ? ( 'Unprotect' ) : ( i18n.protect || 'Protect' );
				html += '<button type="button" class="button-link acps-mc-protect" data-id="' + f.id + '" data-on="' + ( f.excluded ? 0 : 1 ) + '">' + esc( label ) + '</button>';
			}
			html += '</td>';

			html += '</tr>';
		} );

		html += '</tbody></table>';
		$files.html( html );
		updateActionBar();
	}

	function selectedChecks() {
		return $( '.acps-mc-file-check:checked' );
	}

	function updateActionBar() {
		var $checks = selectedChecks();
		var count = $checks.length;
		var bytes = 0;
		$checks.each( function () {
			bytes += parseInt( $( this ).closest( 'tr' ).data( 'size' ), 10 ) || 0;
		} );
		$( '#acps-mc-selcount' ).text( count );
		$( '#acps-mc-selsize' ).text( count ? humanSize( bytes ) : '' );
		$( '#acps-mc-actionbar' ).toggle( count > 0 );
	}

	function doDelete() {
		var ids = [];
		selectedChecks().each( function () {
			ids.push( parseInt( this.value, 10 ) );
		} );
		if ( ! ids.length ) {
			window.alert( i18n.noneSelected );
			return;
		}

		if ( A.requireAck && ! $( '#acps-mc-ack' ).is( ':checked' ) ) {
			window.alert( i18n.ackRequired );
			return;
		}

		var msg = A.deleteMode === 'permanent' ? i18n.confirmPermanent : i18n.confirmTrash;
		if ( ! window.confirm( msg ) ) {
			return;
		}

		var $btn = $( '#acps-mc-delete-btn' ).prop( 'disabled', true );
		post( 'delete', { ids: ids, ack: $( '#acps-mc-ack' ).is( ':checked' ) ? 1 : 0 } ).done( function ( res ) {
			$btn.prop( 'disabled', false );
			if ( ! res || ! res.success ) {
				window.alert( ( res && res.data && res.data.message ) || i18n.workingError );
				return;
			}
			var d = res.data;
			// Remove successfully deleted rows.
			$.each( d.items, function ( id, r ) {
				if ( r.ok ) {
					$( 'tr[data-id="' + id + '"]' ).fadeOut( 200, function () { $( this ).remove(); } );
				}
			} );
			if ( d.skipped > 0 ) {
				var skippedMsgs = [];
				$.each( d.items, function ( id, r ) {
					if ( ! r.ok && r.reason ) {
						skippedMsgs.push( '#' + id + ': ' + r.reason );
					}
				} );
				window.alert( d.skipped + ' file(s) were kept for safety:\n' + skippedMsgs.slice( 0, 20 ).join( '\n' ) );
			}
			$( '#acps-mc-ack' ).prop( 'checked', false );
			setTimeout( function () { updateActionBar(); loadTree(); }, 250 );
		} ).fail( function () {
			$btn.prop( 'disabled', false );
			window.alert( i18n.workingError );
		} );
	}

	/* ============================================================
	 * BINDINGS
	 * ============================================================ */
	$( function () {
		// Scan.
		$( '#acps-mc-scan-btn' ).on( 'click', runScan );

		// Folders view init.
		if ( $( '#acps-mc-tree' ).length ) {
			loadTree();
		}

		$( document ).on( 'click', '.acps-mc-folder', function () {
			loadFolder( $( this ).data( 'folder' ) );
		} );

		$( '#acps-mc-include-sub, #acps-mc-show-used' ).on( 'change', function () {
			if ( currentFolder !== null ) {
				loadFolder( currentFolder );
			}
		} );

		$( document ).on( 'change', '.acps-mc-file-check', updateActionBar );

		$( document ).on( 'change', '#acps-mc-selectall', function () {
			var on = $( this ).is( ':checked' );
			$( '.acps-mc-file-check' ).prop( 'checked', on );
			updateActionBar();
		} );

		$( '#acps-mc-delete-btn' ).on( 'click', doDelete );

		// Protect / unprotect.
		$( document ).on( 'click', '.acps-mc-protect', function () {
			var $b = $( this );
			var id = $b.data( 'id' );
			var on = parseInt( $b.data( 'on' ), 10 );
			post( 'exclude', { id: id, on: on } ).done( function ( res ) {
				if ( res && res.success ) {
					if ( currentFolder !== null ) {
						loadFolder( currentFolder );
					}
				}
			} );
		} );

		// Trash tab.
		$( document ).on( 'click', '.acps-mc-restore', function () {
			var id = $( this ).data( 'id' );
			var $row = $( 'tr[data-id="' + id + '"]' );
			post( 'restore', { id: id } ).done( function ( res ) {
				if ( res && res.success ) {
					$row.fadeOut( 200, function () { $( this ).remove(); } );
				} else {
					window.alert( ( res && res.data && res.data.message ) || i18n.workingError );
				}
			} );
		} );

		$( document ).on( 'click', '.acps-mc-purge', function () {
			if ( ! window.confirm( i18n.confirmPermanent ) ) {
				return;
			}
			var id = $( this ).data( 'id' );
			var $row = $( 'tr[data-id="' + id + '"]' );
			post( 'purge', { id: id } ).done( function ( res ) {
				if ( res && res.success ) {
					$row.fadeOut( 200, function () { $( this ).remove(); } );
				} else {
					window.alert( ( res && res.data && res.data.message ) || i18n.workingError );
				}
			} );
		} );
	} );
} )( jQuery );
