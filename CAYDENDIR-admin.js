/**
 * Settings screen: the "Sort rules" builder.
 *
 * Lets an admin add, remove and drag-reorder sort rules, and shows the
 * priority-list textarea only for rules using the "Custom priority list" mode.
 * The visible (top-to-bottom) order of the rows is the priority order, so the
 * rows' field-name indexes are renumbered to match the DOM after any change;
 * PHP then reads them in that order.
 */
(function ( $ ) {
	'use strict';

	$( function () {
		var $wrap = $( '#CAYDENDIR-sort-rules' );
		if ( ! $wrap.length ) {
			return;
		}
		var $tpl = $( '[data-CAYDENDIR-rule-template]' );

		// Rewrite every row's input names to a sequential [sort_rules][N] index
		// matching its position, and refresh the visible level number.
		function renumber() {
			$wrap.children( '[data-CAYDENDIR-rule]' ).each( function ( i ) {
				$( this ).find( '[data-CAYDENDIR-level]' ).first().text( i + 1 );
				$( this ).find( '[name]' ).each( function () {
					this.name = this.name.replace( /\[sort_rules\]\[[^\]]*\]/, '[sort_rules][' + i + ']' );
				} );
			} );
		}

		// Show the priority textarea only when this rule's mode is "priority".
		function toggleOrder( $row ) {
			var mode = $row.find( '[data-CAYDENDIR-mode]' ).val();
			$row.find( '[data-CAYDENDIR-order-wrap]' ).prop( 'hidden', 'priority' !== mode );
		}

		function bindRow( $row ) {
			$row.find( '[data-CAYDENDIR-mode]' ).on( 'change', function () {
				toggleOrder( $row );
			} );
			$row.find( '[data-CAYDENDIR-remove]' ).on( 'click', function () {
				$row.remove();
				renumber();
			} );
			toggleOrder( $row );
		}

		$wrap.children( '[data-CAYDENDIR-rule]' ).each( function () {
			bindRow( $( this ) );
		} );

		$( '[data-CAYDENDIR-add-rule]' ).on( 'click', function () {
			var $row = $tpl.children( '[data-CAYDENDIR-rule]' ).first().clone();
			// The template's inputs are disabled so the hidden template never
			// submits; re-enable them on the live copy.
			$row.find( ':disabled' ).prop( 'disabled', false );
			$wrap.append( $row );
			bindRow( $row );
			renumber();
			$row.find( '[data-CAYDENDIR-field]' ).trigger( 'focus' );
		} );

		if ( $.fn.sortable ) {
			$wrap.sortable( {
				handle: '[data-CAYDENDIR-handle]',
				axis: 'y',
				placeholder: 'ui-sortable-placeholder',
				forcePlaceholderSize: true,
				update: renumber
			} );
		}

		renumber();
	} );
})( jQuery );
