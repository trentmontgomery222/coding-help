/**
 * Pins the false "every way of closing this alert is switched off" warning.
 *
 * Every checkbox in the alert form is preceded by a hidden input of the SAME
 * name, so an unticked box still posts a 0. That is correct on save — PHP takes
 * the last value for a repeated name — but it is a trap for the browser:
 * querySelector returns the FIRST match in document order, which is the hidden
 * input. Reading that reported "0" for a ticked box, so the accessibility
 * warning fired even with all three closing options on, and the live preview
 * always drew without an overlay or a close button.
 *
 * The DOM here is a small stand-in, but the one behaviour under test is modelled
 * faithfully: querySelector returns the first element matching the selector, in
 * document order.
 */

'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const assert = require( 'assert' );

let failures = 0;

function check( label, actual, expected ) {
	try {
		assert.strictEqual( actual, expected );
	} catch ( e ) {
		failures++;
		console.log( `FAIL ${ label }: expected ${ JSON.stringify( expected ) }, got ${ JSON.stringify( actual ) }` );
	}
}

/**
 * One form field.
 */
class El {
	constructor( tag, attrs ) {
		this.tag = tag;
		this.type = attrs.type || '';
		this.name = attrs.name || '';
		this.value = attrs.value || '';
		this.checked = !! attrs.checked;
	}

	matches( selector ) {
		// Only the two selector shapes the code under test uses.
		const nameMatch = selector.match( /\[name="([^"]+)"\]/ );

		if ( nameMatch && this.name !== nameMatch[ 1 ] ) {
			return false;
		}

		const typeMatch = selector.match( /^input\[type="([^"]+)"\]/ );

		if ( typeMatch && ( this.tag !== 'input' || this.type !== typeMatch[ 1 ] ) ) {
			return false;
		}

		return true;
	}
}

/**
 * A container that resolves querySelector in document order, as a browser does.
 */
class Root {
	constructor( children ) {
		this.children = children;
	}

	querySelector( selector ) {
		for ( const child of this.children ) {
			if ( child.matches( selector ) ) {
				return child;
			}
		}

		return null;
	}
}

/**
 * Builds the markup the plugin really emits for a checkbox: hidden input first,
 * then the checkbox, both sharing a name.
 *
 * @param {string}  key    Setting key.
 * @param {boolean} ticked Whether the box is ticked.
 * @return {El[]} The two inputs, in document order.
 */
function checkboxPair( key, ticked ) {
	const name = `acps_alert[${ key }]`;

	return [
		new El( 'input', { type: 'hidden', name, value: '0' } ),
		new El( 'input', { type: 'checkbox', name, value: '1', checked: ticked } ),
	];
}

// Confirm the markup really is shaped that way, rather than trusting the memo.
const fields = fs.readFileSync(
	path.join( __dirname, '..', 'acps-alert-popups', 'includes', 'class-acps-alerts-fields.php' ),
	'utf8'
);

check(
	'the form really does emit a hidden input before each checkbox',
	/<input type="hidden" name="%1\$s" value="0" \/><input type="checkbox" name="%1\$s"/.test( fields ),
	true
);

// Load value() out of the real admin script.
const source = fs.readFileSync(
	path.join( __dirname, '..', 'acps-alert-popups', 'assets', 'js', 'admin.js' ),
	'utf8'
);

const start = source.indexOf( '\tfunction value( root, key ) {' );
const end = source.indexOf( '\n\t}', start ) + 3;

check( 'value() was found in admin.js', start > -1, true );

// eslint-disable-next-line no-new-func
const value = new Function( `${ source.slice( start, end ) } return value;` )();

/* ---- the reported bug: all three closing options on ---- */
const allOn = new Root( [
	...checkboxPair( 'dismissible', true ),
	...checkboxPair( 'overlay_close', true ),
	...checkboxPair( 'esc_close', true ),
	...checkboxPair( 'show_overlay', true ),
] );

check( 'a ticked close button reads as on', value( allOn, 'dismissible' ), '1' );
check( 'a ticked overlay click reads as on', value( allOn, 'overlay_close' ), '1' );
check( 'a ticked escape key reads as on', value( allOn, 'esc_close' ), '1' );
check( 'a ticked overlay reads as on', value( allOn, 'show_overlay' ), '1' );

/* ---- genuinely off still reads as off ---- */
const allOff = new Root( [
	...checkboxPair( 'dismissible', false ),
	...checkboxPair( 'overlay_close', false ),
	...checkboxPair( 'esc_close', false ),
] );

check( 'an unticked close button reads as off', value( allOff, 'dismissible' ), '0' );
check( 'an unticked overlay click reads as off', value( allOff, 'overlay_close' ), '0' );
check( 'an unticked escape key reads as off', value( allOff, 'esc_close' ), '0' );

/* ---- a mix reads correctly ---- */
const mixed = new Root( [
	...checkboxPair( 'dismissible', false ),
	...checkboxPair( 'overlay_close', true ),
	...checkboxPair( 'esc_close', false ),
] );

check( 'the one ticked box in a mix reads as on', value( mixed, 'overlay_close' ), '1' );
check( 'and its neighbours read as off', value( mixed, 'dismissible' ), '0' );

/* ---- the warning only fires when every route really is off ---- */
const trapped = ( root ) =>
	value( root, 'dismissible' ) !== '1' &&
	value( root, 'overlay_close' ) !== '1' &&
	value( root, 'esc_close' ) !== '1';

check( 'no warning when all three are on', trapped( allOn ), false );
check( 'no warning when one route remains', trapped( mixed ), false );
check( 'the warning fires when all three are off', trapped( allOff ), true );

/* ---- non-checkbox fields are unaffected ---- */
const others = new Root( [
	new El( 'select', { name: 'acps_alert[position]', value: 'bottom-right' } ),
	new El( 'input', { type: 'number', name: 'acps_alert[width]', value: '820' } ),
] );

check( 'a select reads its value', value( others, 'position' ), 'bottom-right' );
check( 'a number reads its value', value( others, 'width' ), '820' );
check( 'a missing field reads as empty', value( others, 'nope' ), '' );

console.log( failures ? `\n${ failures } failing case(s)` : 'All admin field cases passed' );
process.exit( failures ? 1 : 0 );
