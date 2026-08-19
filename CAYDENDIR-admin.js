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

	/* ---------------------------------------------------------------------
	 * Column display editor: insert-field buttons + live preview.
	 * The preview is an approximate, client-side mirror of the PHP template
	 * engine (conditionals, fallbacks, {field} substitution) run against a
	 * sample person, so admins can see roughly what a template produces.
	 * ------------------------------------------------------------------- */
	$( function () {
		var $box = $( '#CAYDENDIR-col-templates' );
		if ( ! $box.length ) {
			return;
		}

		var SAMPLE = {
			firstname: 'Jane',
			lastname: 'Doe',
			name: 'Jane Doe',
			publictitle: 'Math Teacher',
			job: 'Teacher',
			location: 'Beall Elementary School',
			email: 'jane.doe@acpsmd.org',
			id: 'WP-12345-SD-1-E',
			tags: 'Science, PTA',
			initials: 'JD',
			photo_url: ''
		};

		function esc( s ) {
			return String( s ).replace( /[&<>"']/g, function ( c ) {
				return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[ c ];
			} );
		}

		function evalCond( cond, data ) {
			cond = String( cond ).trim();
			var field = cond, op = '', value = '', m;
			if ( ( m = cond.match( /^([a-z_]+)\s*(==|!=)\s*([\s\S]*)$/i ) ) ) {
				field = m[ 1 ]; op = m[ 2 ]; value = m[ 3 ];
			} else if ( ( m = cond.match( /^([a-z_]+)\s+contains\s+([\s\S]*)$/i ) ) ) {
				field = m[ 1 ]; op = 'contains'; value = m[ 2 ];
			}
			field = field.toLowerCase().trim();
			value = value.trim();
			if ( value.length >= 2 ) {
				var q = value.charAt( 0 );
				if ( ( q === '"' || q === "'" ) && value.slice( -1 ) === q ) {
					value = value.slice( 1, -1 );
				}
			}
			var fv = ( data[ field ] != null ? String( data[ field ] ) : '' ).trim();
			if ( op === '==' ) { return fv.toLowerCase() === value.toLowerCase(); }
			if ( op === '!=' ) { return fv.toLowerCase() !== value.toLowerCase(); }
			if ( op === 'contains' ) { return value !== '' && fv.toLowerCase().indexOf( value.toLowerCase() ) !== -1; }
			return fv !== '';
		}

		function renderTemplate( tpl, data ) {
			var s = String( tpl == null ? '' : tpl );

			// Conditionals, innermost first.
			var re = /\[if\s+([^\]]+?)\]((?:(?!\[if\s|\[\/if\])[\s\S])*?)\[\/if\]/i;
			var guard = 0;
			while ( re.test( s ) && guard++ < 50 ) {
				s = s.replace( new RegExp( re.source, 'i' ), function ( whole, cond, body ) {
					var has = evalCond( cond, data );
					var parts = body.split( /\[else\]/i );
					return has ? parts[ 0 ] : ( parts[ 1 ] || '' );
				} );
			}

			// {field} and {field|fallback}, values escaped.
			s = s.replace( /\{([a-z_]+)(?:\|([^{}]*))?\}/g, function ( whole, field, fallback ) {
				var v = data[ field ] != null ? String( data[ field ] ) : '';
				if ( v.trim() === '' ) {
					return esc( fallback || '' );
				}
				return esc( v );
			} );

			// Tidy plain-text templates only.
			if ( s.indexOf( '<' ) === -1 ) {
				s = s.replace( /\s+/g, ' ' )
					.replace( /\s*([,|·•])\s*\1\s*/g, '$1 ' )
					.replace( /^[\s,|·•-]+|[\s,|·•-]+$/g, '' );
			}
			s = s.replace( /<\s*script/gi, '&lt;script' ); // never run scripts in the preview
			return s.trim();
		}

		function insertAtCursor( el, text ) {
			var start = el.selectionStart != null ? el.selectionStart : el.value.length;
			var end = el.selectionEnd != null ? el.selectionEnd : el.value.length;
			el.value = el.value.slice( 0, start ) + text + el.value.slice( end );
			var pos = start + text.length;
			el.focus();
			try { el.setSelectionRange( pos, pos ); } catch ( e ) {}
		}

		$box.find( '[data-CAYDENDIR-tpl]' ).each( function () {
			var $row = $( this );
			var input = $row.find( '[data-CAYDENDIR-tpl-input]' ).get( 0 );
			var preview = $row.find( '[data-CAYDENDIR-tpl-preview]' ).get( 0 );
			if ( ! input || ! preview ) {
				return;
			}
			function refresh() {
				preview.innerHTML = renderTemplate( input.value, SAMPLE );
			}
			$row.find( '[data-CAYDENDIR-insert]' ).on( 'click', function () {
				insertAtCursor( input, $( this ).attr( 'data-CAYDENDIR-insert' ) );
				refresh();
			} );
			$( input ).on( 'input', refresh );
			refresh();
		} );
	} );
})( jQuery );
