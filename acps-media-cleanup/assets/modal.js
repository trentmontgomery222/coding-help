/* global jQuery, ACPS_MM_MODAL */
( function ( $ ) {
	'use strict';

	var A = window.ACPS_MM_MODAL || {};
	var i18n = A.i18n || {};

	function post( action, data ) {
		data = data || {};
		data.action = 'acps_mm_' + action;
		data.nonce = A.nonce;
		return $.post( A.ajaxUrl, data );
	}
	function esc( s ) {
		return $( '<div/>' ).text( s == null ? '' : String( s ) ).html();
	}
	function copyText( text ) {
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text );
			return;
		}
		var $t = $( '<textarea>' ).val( text ).appendTo( 'body' ).select();
		try { document.execCommand( 'copy' ); } catch ( e ) {}
		$t.remove();
	}

	$( document ).on( 'click', '.acps-mm-copy-btn', function ( e ) {
		e.preventDefault();
		var $b = $( this );
		copyText( $b.data( 'url' ) );
		var t = $b.text();
		$b.text( i18n.copied || 'Copied!' );
		setTimeout( function () { $b.text( t ); }, 1200 );
	} );

	$( document ).on( 'change', '.acps-mm-folder-select', function () {
		var $s = $( this );
		var id = $s.data( 'id' );
		var fid = parseInt( this.value, 10 ) || 0;
		post( 'move', { ids: [ id ], folder_id: fid } ).done( function ( res ) {
			if ( res && res.success ) {
				$s.after( ' <span class="acps-mm-inline-msg">' + esc( i18n.saved || 'Saved' ) + '</span>' );
				setTimeout( function () { $s.siblings( '.acps-mm-inline-msg' ).remove(); }, 1500 );
			}
		} );
	} );

	$( document ).on( 'click', '.acps-mm-where-btn', function ( e ) {
		e.preventDefault();
		var id = $( this ).data( 'id' );
		var $out = $( this ).siblings( '.acps-mm-where-out' );
		$out.html( '<em>' + esc( i18n.checking || 'Checking…' ) + '</em>' );
		post( 'where_used', { id: id } ).done( function ( res ) {
			var locs = ( res && res.success ) ? res.data.locations : [];
			if ( ! locs || ! locs.length ) {
				$out.html( '<span class="acps-mm-notused">' + esc( i18n.notUsed || 'Not found anywhere on the site.' ) + '</span>' );
				return;
			}
			var h = '<strong>' + esc( i18n.usedIn || 'Used in' ) + ':</strong><ul class="acps-mm-where-ul">';
			locs.forEach( function ( l ) {
				h += '<li>' + ( l.url ? '<a href="' + esc( l.url ) + '" target="_blank" rel="noopener">' + esc( l.label ) + '</a>' : esc( l.label ) ) + '</li>';
			} );
			h += '</ul>';
			$out.html( h );
		} );
	} );
} )( jQuery );
