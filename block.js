/**
 * Gutenberg block: Staff Directory (server-rendered).
 *
 * Registered with no build step — plain JS against the wp.* globals. The block
 * is dynamic: PHP renders it (render_callback), and the editor shows a live
 * ServerSideRender preview plus a few controls. Works in the stock block
 * editor and block plugins built on it (GenerateBlocks, Kadence, etc.).
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.element ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var __ = ( wp.i18n && wp.i18n.__ ) ? wp.i18n.__ : function ( s ) { return s; };
	var blockEditor = wp.blockEditor || wp.editor || {};
	var InspectorControls = blockEditor.InspectorControls || function () { return null; };
	var components = wp.components || {};
	var PanelBody = components.PanelBody;
	var TextControl = components.TextControl;
	var SelectControl = components.SelectControl;
	var ServerSideRender = wp.serverSideRender || ( wp.components && wp.components.ServerSideRender );

	wp.blocks.registerBlockType( 'caydendir/staff-directory', {
		apiVersion: 2,
		title: __( 'Staff Directory', 'cayden-staff-directory' ),
		description: __( 'Searchable staff directory with the plugin\'s data, layout and styling.', 'cayden-staff-directory' ),
		icon: 'groups',
		category: 'caydens-plugins',
		keywords: [ __( 'staff' ), __( 'directory' ), __( 'people' ) ],
		supports: { html: false },
		attributes: {
			heading: { type: 'string', default: 'Staff Directory' },
			layout: { type: 'string', default: '' },
			match: { type: 'string', default: 'any' }
		},

		edit: function ( props ) {
			var a = props.attributes;
			var controls = [];

			if ( PanelBody && TextControl && SelectControl ) {
				controls.push(
					el( InspectorControls, { key: 'insp' },
						el( PanelBody, { title: __( 'Directory', 'cayden-staff-directory' ), initialOpen: true },
							el( TextControl, {
								label: __( 'Heading', 'cayden-staff-directory' ),
								value: a.heading,
								onChange: function ( v ) { props.setAttributes( { heading: v } ); }
							} ),
							el( SelectControl, {
								label: __( 'Layout', 'cayden-staff-directory' ),
								value: a.layout,
								options: [
									{ label: __( 'Use plugin setting', 'cayden-staff-directory' ), value: '' },
									{ label: __( 'Table (rows)', 'cayden-staff-directory' ), value: 'table' },
									{ label: __( 'Cards (grid)', 'cayden-staff-directory' ), value: 'cards' }
								],
								onChange: function ( v ) { props.setAttributes( { layout: v } ); }
							} ),
							el( SelectControl, {
								label: __( 'Tag match', 'cayden-staff-directory' ),
								value: a.match,
								options: [
									{ label: __( 'Any selected tag', 'cayden-staff-directory' ), value: 'any' },
									{ label: __( 'All selected tags', 'cayden-staff-directory' ), value: 'all' }
								],
								onChange: function ( v ) { props.setAttributes( { match: v } ); }
							} )
						)
					)
				);
			}

			var preview;
			if ( ServerSideRender ) {
				preview = el( ServerSideRender, {
					key: 'ssr',
					block: 'caydendir/staff-directory',
					attributes: a
				} );
			} else {
				preview = el( 'p', { key: 'ph', style: { padding: '1rem', border: '1px dashed #ccc' } },
					__( 'Staff Directory', 'cayden-staff-directory' ) + ( a.heading ? ' — ' + a.heading : '' ) );
			}

			return el( Fragment, {}, controls.concat( [ preview ] ) );
		},

		// Dynamic block — PHP renders the front end.
		save: function () { return null; }
	} );
} )( window.wp );
