/**
 * ACPS Site Toolkit — Gutenberg block (editor side).
 *
 * A simple server-rendered block: pick a form from a dropdown. The front-end
 * markup comes from the same PHP renderer, so accessibility and the cache-safe
 * token flow are identical to the shortcode.
 */
( function ( blocks, element, blockEditor, components, i18n ) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;
	var data = window.ACPS_ST_BLOCK || { forms: [] };

	blocks.registerBlockType( 'acps/form', {
		title: __( 'ACPS Form', 'acps-site-toolkit' ),
		description: __( 'Insert an accessible ACPS form.', 'acps-site-toolkit' ),
		icon: 'feedback',
		category: 'widgets',
		attributes: {
			formId: { type: 'number', default: 0 }
		},
		edit: function ( props ) {
			var options = data.forms.map( function ( f ) {
				return { value: f.value, label: f.label };
			} );

			return el(
				'div',
				blockEditor.useBlockProps ? blockEditor.useBlockProps() : {},
				el( components.SelectControl, {
					label: __( 'Form', 'acps-site-toolkit' ),
					value: props.attributes.formId,
					options: options,
					onChange: function ( value ) {
						props.setAttributes( { formId: parseInt( value, 10 ) || 0 } );
					}
				} ),
				props.attributes.formId
					? el( 'p', {}, __( 'Form #', 'acps-site-toolkit' ) + props.attributes.formId + __( ' will render here.', 'acps-site-toolkit' ) )
					: el( 'p', {}, __( 'Select a form to display.', 'acps-site-toolkit' ) )
			);
		},
		save: function () {
			return null; // Rendered server-side.
		}
	} );
} )(
	window.wp.blocks,
	window.wp.element,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.i18n
);
