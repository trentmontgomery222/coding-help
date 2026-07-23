/**
 * ACPS Site Toolkit — form builder (admin).
 *
 * Accessibility (spec §7.7 / SC 2.5.7): reordering never requires dragging.
 * Every field has Up / Down buttons and a "move to position" input. The whole
 * builder is keyboard-operable — it's a Section 508 surface.
 */
( function () {
	'use strict';

	var data = window.ACPS_ST_ADMIN || {};
	var TYPES = data.fieldTypes || {};

	function ready( fn ) {
		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', fn );
		} else {
			fn();
		}
	}

	ready( function () {
		var canvas = document.getElementById( 'acps-canvas' );
		if ( ! canvas ) {
			return;
		}
		new Builder( canvas );
	} );

	function Builder( canvas ) {
		this.canvas = canvas;
		this.jsonInput = document.getElementById( 'acps-fields-json' );
		this.settingsPane = document.getElementById( 'acps-field-settings' );
		this.preview = document.getElementById( 'acps-preview' );
		this.form = document.getElementById( 'acps-builder-form' );
		this.selected = null;
		this.uidCounter = 0;

		this.fields = this.parse();
		this.bind();
		this.render();
	}

	Builder.prototype.parse = function () {
		try {
			var arr = JSON.parse( this.jsonInput.value || '[]' );
			return Array.isArray( arr ) ? arr : [];
		} catch ( e ) {
			return [];
		}
	};

	Builder.prototype.bind = function () {
		var self = this;

		// Add field buttons (left pane).
		var addButtons = document.querySelectorAll( '.acps-add-field' );
		Array.prototype.forEach.call( addButtons, function ( btn ) {
			btn.addEventListener( 'click', function () {
				self.addField( btn.getAttribute( 'data-type' ) );
			} );
		} );

		// Serialize on submit.
		if ( this.form ) {
			this.form.addEventListener( 'submit', function () {
				self.jsonInput.value = JSON.stringify( self.fields );
			} );
		}

		// Preview toggle.
		var toggle = document.getElementById( 'acps-preview-toggle' );
		if ( toggle ) {
			toggle.addEventListener( 'click', function () {
				var showing = self.preview.hidden === false;
				self.preview.hidden = showing;
				self.canvas.hidden = ! showing;
				toggle.setAttribute( 'aria-pressed', showing ? 'false' : 'true' );
				if ( ! showing ) {
					self.renderPreview();
				}
			} );
		}
	};

	Builder.prototype.addField = function ( type ) {
		this.uidCounter++;
		var field = {
			key: 'field_' + ( this.fields.length + 1 ) + '_' + this.uidCounter,
			type: type,
			label: TYPES[ type ] ? TYPES[ type ].label : type,
			help: '',
			placeholder: '',
			default: '',
			required: false,
			options: needsOptions( type ) ? [ { label: 'Option 1', value: 'Option 1' } ] : [],
			page: 1,
			conditional: {}
		};
		this.fields.push( field );
		this.render();
		this.select( this.fields.length - 1 );
	};

	Builder.prototype.move = function ( index, delta ) {
		var target = index + delta;
		if ( target < 0 || target >= this.fields.length ) {
			return;
		}
		var tmp = this.fields[ index ];
		this.fields[ index ] = this.fields[ target ];
		this.fields[ target ] = tmp;
		this.selected = target;
		this.render();
		// Keep focus on the moved item's controls.
		var moved = this.canvas.querySelector( '[data-index="' + target + '"] .acps-canvas-edit' );
		if ( moved ) { moved.focus(); }
	};

	Builder.prototype.moveTo = function ( index, position ) {
		position = Math.max( 1, Math.min( this.fields.length, position ) ) - 1;
		var item = this.fields.splice( index, 1 )[ 0 ];
		this.fields.splice( position, 0, item );
		this.selected = position;
		this.render();
	};

	Builder.prototype.remove = function ( index ) {
		this.fields.splice( index, 1 );
		if ( this.selected === index ) {
			this.selected = null;
			this.renderSettings();
		}
		this.render();
	};

	Builder.prototype.select = function ( index ) {
		this.selected = index;
		this.renderSettings();
		this.render();
	};

	Builder.prototype.render = function () {
		var self = this;
		this.canvas.innerHTML = '';
		if ( ! this.fields.length ) {
			var empty = document.createElement( 'li' );
			empty.className = 'acps-canvas-empty';
			empty.textContent = 'No fields yet. Add one from the left.';
			this.canvas.appendChild( empty );
			return;
		}

		this.fields.forEach( function ( field, index ) {
			var li = document.createElement( 'li' );
			li.className = 'acps-canvas-item' + ( self.selected === index ? ' is-selected' : '' );
			li.setAttribute( 'data-index', index );

			var summary = document.createElement( 'div' );
			summary.className = 'acps-canvas-summary';
			summary.innerHTML = '<strong>' + escapeHtml( field.label || '(no label)' ) + '</strong> ' +
				'<span class="acps-canvas-type">' + escapeHtml( TYPES[ field.type ] ? TYPES[ field.type ].label : field.type ) + '</span>' +
				( field.required ? ' <span class="acps-canvas-req">required</span>' : '' );
			li.appendChild( summary );

			var controls = document.createElement( 'div' );
			controls.className = 'acps-canvas-controls';

			controls.appendChild( button( 'Edit', 'acps-canvas-edit', function () { self.select( index ); } ) );

			var up = button( '▲', 'acps-canvas-up', function () { self.move( index, -1 ); } );
			up.setAttribute( 'aria-label', 'Move ' + ( field.label || 'field' ) + ' up' );
			up.disabled = index === 0;
			controls.appendChild( up );

			var down = button( '▼', 'acps-canvas-down', function () { self.move( index, 1 ); } );
			down.setAttribute( 'aria-label', 'Move ' + ( field.label || 'field' ) + ' down' );
			down.disabled = index === self.fields.length - 1;
			controls.appendChild( down );

			// Move-to-position input (single-pointer / keyboard alternative).
			var posLabel = document.createElement( 'label' );
			posLabel.className = 'screen-reader-text';
			posLabel.setAttribute( 'for', 'acps-pos-' + index );
			posLabel.textContent = 'Move ' + ( field.label || 'field' ) + ' to position';
			var pos = document.createElement( 'input' );
			pos.type = 'number';
			pos.id = 'acps-pos-' + index;
			pos.min = 1;
			pos.max = self.fields.length;
			pos.value = index + 1;
			pos.className = 'acps-pos-input small-text';
			pos.addEventListener( 'change', function () {
				self.moveTo( index, parseInt( pos.value, 10 ) || ( index + 1 ) );
			} );
			controls.appendChild( posLabel );
			controls.appendChild( pos );

			var del = button( 'Delete', 'acps-canvas-del acps-danger', function () { self.remove( index ); } );
			del.setAttribute( 'aria-label', 'Delete ' + ( field.label || 'field' ) );
			controls.appendChild( del );

			li.appendChild( controls );
			self.canvas.appendChild( li );
		} );
	};

	Builder.prototype.renderSettings = function () {
		var self = this;
		var pane = this.settingsPane;
		pane.innerHTML = '';
		if ( this.selected === null || ! this.fields[ this.selected ] ) {
			pane.innerHTML = '<p class="acps-settings-empty">Select a field to edit its settings.</p>';
			return;
		}
		var field = this.fields[ this.selected ];

		pane.appendChild( textRow( 'Label', field.label, function ( v ) { field.label = v; self.render(); } ) );
		pane.appendChild( textRow( 'Help text', field.help, function ( v ) { field.help = v; } ) );
		pane.appendChild( textRow( 'Field key', field.key, function ( v ) { field.key = v.replace( /[^a-z0-9_]/gi, '_' ).toLowerCase(); } ) );

		if ( hasPlaceholder( field.type ) ) {
			pane.appendChild( textRow( 'Placeholder', field.placeholder, function ( v ) { field.placeholder = v; } ) );
		}

		if ( isInput( field.type ) && field.type !== 'section' && field.type !== 'heading' ) {
			pane.appendChild( checkRow( 'Required', field.required, function ( v ) { field.required = v; self.render(); } ) );
		}

		if ( needsOptions( field.type ) ) {
			var optText = ( field.options || [] ).map( function ( o ) { return o.label; } ).join( '\n' );
			pane.appendChild( areaRow( 'Options (one per line)', optText, function ( v ) {
				field.options = v.split( /\n/ ).map( function ( line ) {
					return line.trim();
				} ).filter( Boolean ).map( function ( line ) {
					return { label: line, value: line };
				} );
			} ) );
		}

		if ( field.type === 'heading' || field.type === 'section' ) {
			pane.appendChild( areaRow( 'Content', field.content || '', function ( v ) { field.content = v; } ) );
		}

		// Page number (multi-page).
		pane.appendChild( numberRow( 'Page number', field.page || 1, function ( v ) { field.page = v; } ) );

		// Conditional visibility — advanced: action + logic + multiple rules.
		pane.appendChild( this.renderConditional( field ) );
	};

	var OPS = [
		[ 'is', 'is' ],
		[ 'is_not', 'is not' ],
		[ 'contains', 'contains' ],
		[ 'not_contains', "doesn't contain" ],
		[ 'gt', 'greater than' ],
		[ 'lt', 'less than' ],
		[ 'is_empty', 'is empty' ],
		[ 'is_not_empty', 'is not empty' ]
	];

	Builder.prototype.renderConditional = function ( field ) {
		var self = this;
		// Migrate legacy single-rule shape.
		var cond = field.conditional || {};
		if ( cond.field && ! cond.rules ) {
			cond = { enabled: true, logic: 'and', action: 'show', rules: [ { field: cond.field, op: cond.op || 'is', value: cond.value || '' } ] };
		}
		cond.logic = cond.logic || 'and';
		cond.action = cond.action || 'show';
		cond.rules = cond.rules || [];
		field.conditional = cond;

		var otherFields = this.fields.filter( function ( f ) { return f.key !== field.key && isInput( f.type ); } );

		var fs = document.createElement( 'fieldset' );
		fs.className = 'acps-cond';
		fs.innerHTML = '<legend>Conditional visibility</legend>';

		// Enable toggle.
		var enableWrap = document.createElement( 'label' );
		var enable = document.createElement( 'input' );
		enable.type = 'checkbox';
		enable.checked = !! cond.enabled;
		enableWrap.appendChild( enable );
		enableWrap.appendChild( document.createTextNode( ' Only show/hide this field conditionally' ) );
		fs.appendChild( enableWrap );

		var body = document.createElement( 'div' );
		body.className = 'acps-cond-body';
		body.hidden = ! cond.enabled;
		fs.appendChild( body );

		enable.addEventListener( 'change', function () {
			cond.enabled = enable.checked;
			body.hidden = ! enable.checked;
			if ( enable.checked && ! cond.rules.length ) {
				cond.rules.push( { field: otherFields.length ? otherFields[ 0 ].key : '', op: 'is', value: '' } );
				renderRules();
			}
		} );

		// Action + logic row.
		var actionSel = selectEl( [ [ 'show', 'Show this field' ], [ 'hide', 'Hide this field' ] ], cond.action, 'Action' );
		actionSel.addEventListener( 'change', function () { cond.action = actionSel.value; } );
		var logicSel = selectEl( [ [ 'and', 'ALL rules match (AND)' ], [ 'or', 'ANY rule matches (OR)' ] ], cond.logic, 'Match logic' );
		logicSel.addEventListener( 'change', function () { cond.logic = logicSel.value; } );
		body.appendChild( wrapInline( [ actionSel, document.createTextNode( ' when ' ), logicSel ] ) );

		var rulesWrap = document.createElement( 'div' );
		rulesWrap.className = 'acps-cond-rules';
		body.appendChild( rulesWrap );

		function renderRules() {
			rulesWrap.innerHTML = '';
			cond.rules.forEach( function ( rule, ri ) {
				var row = document.createElement( 'div' );
				row.className = 'acps-cond-rule';

				var fSel = selectEl( otherFields.map( function ( f ) { return [ f.key, f.label || f.key ]; } ), rule.field, 'Field' );
				fSel.addEventListener( 'change', function () { rule.field = fSel.value; } );

				var oSel = selectEl( OPS, rule.op, 'Operator' );
				oSel.addEventListener( 'change', function () { rule.op = oSel.value; } );

				var vInput = document.createElement( 'input' );
				vInput.type = 'text';
				vInput.value = rule.value || '';
				vInput.setAttribute( 'aria-label', 'Value' );
				vInput.addEventListener( 'input', function () { rule.value = vInput.value; } );

				var del = document.createElement( 'button' );
				del.type = 'button';
				del.className = 'button-link acps-danger';
				del.textContent = 'Remove';
				del.setAttribute( 'aria-label', 'Remove rule ' + ( ri + 1 ) );
				del.addEventListener( 'click', function () {
					cond.rules.splice( ri, 1 );
					renderRules();
				} );

				row.appendChild( fSel );
				row.appendChild( oSel );
				row.appendChild( vInput );
				row.appendChild( del );
				rulesWrap.appendChild( row );
			} );

			var add = document.createElement( 'button' );
			add.type = 'button';
			add.className = 'button';
			add.textContent = '+ Add rule';
			add.addEventListener( 'click', function () {
				cond.rules.push( { field: otherFields.length ? otherFields[ 0 ].key : '', op: 'is', value: '' } );
				renderRules();
			} );
			rulesWrap.appendChild( add );
		}
		renderRules();

		return fs;
	};

	Builder.prototype.renderPreview = function () {
		var html = '<h3>Preview</h3>';
		this.fields.forEach( function ( f ) {
			html += '<div class="acps-preview-field"><strong>' + escapeHtml( f.label || '' ) + '</strong>' +
				( f.required ? ' <em>(required)</em>' : '' ) +
				'<br><span class="acps-preview-type">' + escapeHtml( f.type ) + '</span></div>';
		} );
		this.preview.innerHTML = html;
	};

	/* ---- small DOM builders ----------------------------------------- */
	function button( label, cls, onClick ) {
		var b = document.createElement( 'button' );
		b.type = 'button';
		b.className = 'button ' + cls;
		b.textContent = label;
		b.addEventListener( 'click', onClick );
		return b;
	}
	function labelledControl( labelText, control ) {
		var p = document.createElement( 'p' );
		var id = 'acps-set-' + ( ++uid );
		var l = document.createElement( 'label' );
		l.setAttribute( 'for', id );
		l.textContent = labelText;
		control.id = id;
		p.appendChild( l );
		p.appendChild( document.createElement( 'br' ) );
		p.appendChild( control );
		return p;
	}
	var uid = 0;
	function textRow( label, value, onChange ) {
		var i = document.createElement( 'input' );
		i.type = 'text';
		i.value = value || '';
		i.className = 'widefat';
		i.addEventListener( 'input', function () { onChange( i.value ); } );
		return labelledControl( label, i );
	}
	function areaRow( label, value, onChange ) {
		var t = document.createElement( 'textarea' );
		t.rows = 4;
		t.className = 'widefat';
		t.value = value || '';
		t.addEventListener( 'input', function () { onChange( t.value ); } );
		return labelledControl( label, t );
	}
	function numberRow( label, value, onChange ) {
		var i = document.createElement( 'input' );
		i.type = 'number';
		i.min = 1;
		i.value = value || 1;
		i.className = 'small-text';
		i.addEventListener( 'input', function () { onChange( parseInt( i.value, 10 ) || 1 ); } );
		return labelledControl( label, i );
	}
	function checkRow( label, checked, onChange ) {
		var p = document.createElement( 'p' );
		var l = document.createElement( 'label' );
		var i = document.createElement( 'input' );
		i.type = 'checkbox';
		i.checked = !! checked;
		i.addEventListener( 'change', function () { onChange( i.checked ); } );
		l.appendChild( i );
		l.appendChild( document.createTextNode( ' ' + label ) );
		p.appendChild( l );
		return p;
	}

	function selectEl( options, selected, ariaLabel ) {
		var s = document.createElement( 'select' );
		options.forEach( function ( o ) {
			var opt = document.createElement( 'option' );
			opt.value = o[ 0 ];
			opt.textContent = o[ 1 ];
			if ( String( o[ 0 ] ) === String( selected ) ) { opt.selected = true; }
			s.appendChild( opt );
		} );
		if ( ariaLabel ) { s.setAttribute( 'aria-label', ariaLabel ); }
		return s;
	}
	function wrapInline( nodes ) {
		var p = document.createElement( 'p' );
		p.className = 'acps-cond-inline';
		nodes.forEach( function ( n ) { p.appendChild( n ); } );
		return p;
	}

	function needsOptions( type ) {
		return TYPES[ type ] && TYPES[ type ].options;
	}
	function isInput( type ) {
		return TYPES[ type ] && TYPES[ type ].input;
	}
	function hasPlaceholder( type ) {
		return [ 'short_text', 'long_text', 'email', 'number' ].indexOf( type ) !== -1;
	}
	function escapeHtml( s ) {
		var d = document.createElement( 'div' );
		d.textContent = s == null ? '' : String( s );
		return d.innerHTML;
	}
} )();
