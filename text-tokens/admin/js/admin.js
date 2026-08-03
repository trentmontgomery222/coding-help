/**
 * Text Tokens admin enhancements.
 *
 * Progressive enhancement only — the form works without JS. This script:
 *  - toggles Static vs Dynamic fields,
 *  - shows the config fieldset for the selected rule,
 *  - fetches a live preview,
 *  - announces dynamic changes through an aria-live region.
 */
( function ( $ ) {
	'use strict';

	var data = window.ttAdmin || {};

	function announce( message ) {
		var region = document.getElementById( 'tt-live-region' );
		if ( region ) {
			region.textContent = message;
		}
	}

	function toggleType() {
		var type = $( '#tt-type' ).val();
		$( '.tt-field-static' ).toggle( 'static' === type );
		$( '.tt-field-dynamic' ).toggle( 'dynamic' === type );
	}

	function toggleRuleConfig() {
		var rule = $( '#tt-rule' ).val();
		$( '.tt-rule-config' ).hide();
		if ( rule ) {
			$( '.tt-rule-config[data-rule="' + rule + '"]' ).show();
			if ( data.rules && data.rules[ rule ] ) {
				$( '.tt-rule-desc' ).text( data.rules[ rule ].desc || '' );
			}
		} else {
			$( '.tt-rule-desc' ).text( '' );
		}
	}

	function collectConfig() {
		var config = {};
		$( '.tt-rule-config:visible' ).find( 'input, select' ).each( function () {
			var name = $( this ).attr( 'name' ) || '';
			var m = name.match( /^config\[(.+)\]$/ );
			if ( m ) {
				config[ m[ 1 ] ] = $( this ).val();
			}
		} );
		return config;
	}

	function renderPreview( ok, text ) {
		var box = $( '#tt-preview' );
		box.removeClass( 'tt-status--ok tt-status--warn' );
		box.addClass( ok ? 'tt-status--ok' : 'tt-status--warn' );

		var icon = ok ? '✓' : '⚠';
		box.html(
			'<span class="tt-status__icon" aria-hidden="true"></span> <span class="tt-status__text"></span>'
		);
		box.find( '.tt-status__icon' ).text( icon );
		box.find( '.tt-status__text' ).text( text );
	}

	function refreshPreview() {
		if ( ! data.ajaxUrl ) {
			return;
		}
		var type = $( '#tt-type' ).val();
		var payload = {
			action: 'tt_preview',
			nonce: data.previewNonce,
			type: type
		};

		if ( 'static' === type ) {
			payload.value = $( '#tt-value' ).val();
		} else {
			payload.rule = $( '#tt-rule' ).val();
			if ( ! payload.rule ) {
				renderPreview( false, ( data.i18n && data.i18n.rowRemoved ) ? 'Select a rule to preview.' : 'Select a rule to preview.' );
				return;
			}
			var config = collectConfig();
			Object.keys( config ).forEach( function ( key ) {
				payload[ 'config[' + key + ']' ] = config[ key ];
			} );
		}

		announce( ( data.i18n && data.i18n.previewing ) || 'Calculating preview…' );

		$.post( data.ajaxUrl, payload )
			.done( function ( response ) {
				if ( response && response.success ) {
					var val = response.data.value;
					if ( val === '' || val === null || typeof val === 'undefined' ) {
						renderPreview( false, 'No value — check the configuration.' );
					} else {
						renderPreview( true, val );
					}
				} else {
					renderPreview( false, ( response && response.data && response.data.message ) || 'Unable to preview.' );
				}
			} )
			.fail( function () {
				renderPreview( false, 'Unable to preview.' );
			} );
	}

	$( function () {
		if ( ! $( '.tt-form' ).length ) {
			return;
		}

		toggleType();
		toggleRuleConfig();

		$( '#tt-type' ).on( 'change', function () {
			toggleType();
			refreshPreview();
		} );

		$( '#tt-rule' ).on( 'change', function () {
			toggleRuleConfig();
			refreshPreview();
		} );

		$( '.tt-form' ).on( 'change keyup', '#tt-value, .tt-rule-config :input', function () {
			// Debounce lightly.
			clearTimeout( window.ttPreviewTimer );
			window.ttPreviewTimer = setTimeout( refreshPreview, 350 );
		} );

		$( '#tt-preview-btn' ).on( 'click', refreshPreview );

		// Initial preview for an existing token.
		refreshPreview();
	} );
}( jQuery ) );
