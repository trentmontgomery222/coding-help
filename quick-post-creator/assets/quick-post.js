/* global jQuery, wp */
jQuery( function ( $ ) {
	'use strict';

	// --- Featured image (single select) ---
	var featuredFrame;

	$( '#qpc-select-featured' ).on( 'click', function ( e ) {
		e.preventDefault();

		if ( featuredFrame ) {
			featuredFrame.open();
			return;
		}

		featuredFrame = wp.media( {
			title: 'Select Featured Image',
			button: { text: 'Use as Featured Image' },
			multiple: false,
		} );

		featuredFrame.on( 'select', function () {
			var attachment = featuredFrame.state().get( 'selection' ).first().toJSON();
			$( '#qpc_featured_image_id' ).val( attachment.id );

			var thumbUrl = attachment.sizes && attachment.sizes.thumbnail
				? attachment.sizes.thumbnail.url
				: attachment.url;

			$( '#qpc-featured-preview' ).html(
				$( '<img>' ).attr( 'src', thumbUrl )
			);
			$( '#qpc-remove-featured' ).show();
		} );

		featuredFrame.open();
	} );

	$( '#qpc-remove-featured' ).on( 'click', function ( e ) {
		e.preventDefault();
		$( '#qpc_featured_image_id' ).val( '' );
		$( '#qpc-featured-preview' ).empty();
		$( this ).hide();
	} );

	// --- Additional images (multi select, appended) ---
	var galleryFrame;
	var galleryIds = [];

	$( '#qpc-select-gallery' ).on( 'click', function ( e ) {
		e.preventDefault();

		galleryFrame = wp.media( {
			title: 'Add Images',
			button: { text: 'Add to Post' },
			multiple: true,
		} );

		galleryFrame.on( 'select', function () {
			var selection = galleryFrame.state().get( 'selection' );

			selection.each( function ( attachment ) {
				var data = attachment.toJSON();

				if ( galleryIds.indexOf( data.id ) !== -1 ) {
					return;
				}

				galleryIds.push( data.id );

				var thumbUrl = data.sizes && data.sizes.thumbnail
					? data.sizes.thumbnail.url
					: data.url;

				var $thumb = $( '<div>' )
					.addClass( 'qpc-gallery-thumb' )
					.attr( 'data-id', data.id );

				$( '<img>' ).attr( 'src', thumbUrl ).appendTo( $thumb );

				$( '<button>' )
					.attr( 'type', 'button' )
					.addClass( 'qpc-gallery-remove' )
					.text( '×' )
					.appendTo( $thumb );

				$( '#qpc-gallery-preview' ).append( $thumb );
			} );

			$( '#qpc_gallery_ids' ).val( galleryIds.join( ',' ) );
		} );

		galleryFrame.open();
	} );

	$( '#qpc-gallery-preview' ).on( 'click', '.qpc-gallery-remove', function () {
		var $thumb = $( this ).closest( '.qpc-gallery-thumb' );
		var id = parseInt( $thumb.attr( 'data-id' ), 10 );

		galleryIds = galleryIds.filter( function ( existingId ) {
			return existingId !== id;
		} );

		$( '#qpc_gallery_ids' ).val( galleryIds.join( ',' ) );
		$thumb.remove();
	} );

	// --- Popular tag chips ---
	$( '.qpc-tag-chips' ).on( 'click', '.qpc-tag-chip', function ( e ) {
		e.preventDefault();

		var tag = $( this ).data( 'tag' ).toString();
		var $field = $( '#qpc_tags' );
		var current = $field.val();
		var existing = current
			? current.split( ',' ).map( function ( t ) {
				return t.trim().toLowerCase();
			} )
			: [];

		if ( existing.indexOf( tag.toLowerCase() ) !== -1 ) {
			return;
		}

		$field.val( current ? current.replace( /\s*,\s*$/, '' ) + ', ' + tag : tag );
	} );
} );
