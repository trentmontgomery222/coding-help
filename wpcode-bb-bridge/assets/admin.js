/* global jQuery, WPCodeBBAdmin */
( function ( $ ) {
	'use strict';

	function nextIndex() {
		var max = -1;

		$( '#wpcodebb-fields-body .wpcodebb-field-row' ).each( function () {
			var name = $( this ).find( 'input, select' ).first().attr( 'name' ) || '';
			var match = name.match( /\[(\d+)\]/ );

			if ( match ) {
				max = Math.max( max, parseInt( match[ 1 ], 10 ) );
			}
		} );

		return max + 1;
	}

	function addRow() {
		var index = nextIndex();
		var template = $( '#tmpl-wpcodebb-field-row' ).html();
		template = template.replace( /__INDEX__/g, index );
		$( '#wpcodebb-fields-body' ).append( template );
	}

	$( document ).on( 'click', '#wpcodebb-add-field', function ( e ) {
		e.preventDefault();
		addRow();
	} );

	$( document ).on( 'click', '.wpcodebb-remove-field', function ( e ) {
		e.preventDefault();

		var $rows = $( '#wpcodebb-fields-body .wpcodebb-field-row' );

		if ( $rows.length <= 1 ) {
			$( this ).closest( 'tr' ).find( 'input' ).val( '' );
			return;
		}

		if ( window.confirm( ( WPCodeBBAdmin && WPCodeBBAdmin.i18n && WPCodeBBAdmin.i18n.confirmRemove ) || 'Remove this field?' ) ) {
			$( this ).closest( 'tr' ).remove();
		}
	} );

	$( document ).on( 'change', '#wpcodebb_detected_snippet', function () {
		var tag = $( this ).val();

		if ( tag ) {
			$( '#wpcodebb_shortcode_tag' ).val( tag );
		}
	} );

	$( function () {
		if ( $( '#wpcodebb-fields-body' ).children().length === 0 ) {
			addRow();
		}

		if ( $.fn.sortable ) {
			$( '#wpcodebb-fields-body' ).sortable( {
				handle: 'td:first-child',
				axis: 'y',
			} );
		}
	} );
} )( jQuery );
