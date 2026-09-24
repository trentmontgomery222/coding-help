/**
 * How often a visitor is shown the same alert.
 *
 * The rule that needed pinning is "Once, until I change this alert": show it
 * once, then leave people alone until the alert is edited — wording OR any of
 * its settings — at which point everybody sees it again.
 *
 * That only works if two things hold, and neither is obvious from reading one
 * file:
 *
 *   1. The version stamp has to move on a SETTINGS-only edit. WordPress stamps
 *      a post when its content changes, but most of an alert is post meta, so
 *      post_modified alone would sit still while the level, the targeting and
 *      the schedule all changed underneath it.
 *   2. "Once, then never again" has to be settled BEFORE the version is looked
 *      at, or the two options do exactly the same thing and the plain "once"
 *      quietly stops meaning once.
 *
 * mayShow() is loaded out of the real script rather than restated here, so the
 * test fails when the shipped logic changes.
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

/* ---- load mayShow() out of the real front end script ---- */

const source = fs.readFileSync(
	path.join( __dirname, '..', 'acps-alert-popups', 'assets', 'js', 'alerts.js' ),
	'utf8'
);

const start = source.indexOf( '\tfunction mayShow( config ) {' );
const end = source.indexOf( '\n\t}', start ) + 3;

check( 'mayShow() was found in alerts.js', start > -1, true );

// The function reads three things from its surroundings: the localised data
// blob, the dismissal record for the alert, and the browser session id. Each is
// supplied here so the real body can run unchanged.
const harness = new Function(
	'state',
	`
	var data = state.data;
	function readRecord() { return state.record; }
	function sessionId() { return state.session; }
	function previewing() { var v = data.isPreview; return true === v || 1 === v || '1' === v; }
	${ source.slice( start, end ) }
	return mayShow;
	`
);

/**
 * Runs the real mayShow() against a given visitor state.
 *
 * @param {Object} config Alert config, as the PHP side localises it.
 * @param {Object|null} record What this browser remembers, or null for a first visit.
 * @param {Object} opts Extra state: session id, preview mode.
 * @return {boolean} Whether the alert may auto-open.
 */
function mayShow( config, record, opts ) {
	const state = {
		data: { isPreview: !! ( opts && opts.isPreview ) },
		record: record,
		session: ( opts && opts.session ) || 'session-1'
	};

	return harness( state )( config );
}

/* ---- "once, until I change this alert" ---- */

const edit = { id: 1, frequency: 'edit', version: '4-1700000000' };

check(
	'a visitor who has never seen it is shown it',
	mayShow( edit, null, {} ),
	true
);

const seen = { dismissedAt: Date.now(), session: 'session-1', version: '4-1700000000' };

check(
	'having seen it, they are not shown it again',
	mayShow( edit, seen, {} ),
	false
);

check(
	'not in a new browser session either — that is what "until I change it" means',
	mayShow( edit, seen, { session: 'session-2' } ),
	false
);

// The revision counter is the first half of the version, and it is what moves
// when only settings change. This is the case post_modified would miss.
check(
	'editing a SETTING shows it again',
	mayShow( { ...edit, version: '5-1700000000' }, seen, {} ),
	true
);

check(
	'editing the wording shows it again too',
	mayShow( { ...edit, version: '4-1700009999' }, seen, {} ),
	true
);

// A record stored before this existed has no version. Treating that as a match
// would leave those visitors never seeing the alert again.
check(
	'a record from before versioning counts as stale',
	mayShow( edit, { dismissedAt: Date.now(), session: 'session-1' }, {} ),
	true
);

/* ---- "once, then never again" really is never ---- */

const once = { id: 1, frequency: 'once', version: '4-1700000000' };

check( 'once: a first-time visitor sees it', mayShow( once, null, {} ), true );
check( 'once: having seen it, never again', mayShow( once, seen, {} ), false );

// This is the check that keeps the two options distinct. If the version gate
// ran first, "once" would reset on every edit and be identical to "edit".
check(
	'once: still never, even after the alert is rewritten',
	mayShow( { ...once, version: '9-1799999999' }, seen, {} ),
	false
);

/* ---- the modes that were already there still behave ---- */

const session = { id: 1, frequency: 'session', version: '4-1700000000' };

check( 'session: not again in the same session', mayShow( session, seen, {} ), false );
check( 'session: shown again in a new one', mayShow( session, seen, { session: 'session-2' } ), true );
check( 'session: an edit shows it again immediately', mayShow( { ...session, version: '5-1700000000' }, seen, {} ), true );

const always = { id: 1, frequency: 'always', version: '4-1700000000' };

check( 'always: shown every time regardless', mayShow( always, seen, {} ), true );

const days = { id: 1, frequency: 'days', frequencyDays: 7, version: '4-1700000000' };
const longAgo = { dismissedAt: Date.now() - ( 8 * 86400000 ), session: 'session-1', version: '4-1700000000' };
const recently = { dismissedAt: Date.now() - ( 2 * 86400000 ), session: 'session-1', version: '4-1700000000' };

check( 'days: not yet', mayShow( days, recently, {} ), false );
check( 'days: past the window', mayShow( days, longAgo, {} ), true );

/* ---- a preview ignores all of it ---- */

check(
	'an editor previewing sees it however often they look',
	mayShow( once, seen, { isPreview: true } ),
	true
);

/* ---- only the X records a dismissal ---- */

/*
 * The popup keeps coming back until the visitor physically clicks the X.
 * Being shown it records nothing; closing it with Escape or the background
 * records nothing; only the X writes the dismissal that gates future showings.
 * The whole script is booted against a stub DOM and the real open()/close()
 * are let run, because this is a question about what reaches storage.
 */

/**
 * Boots alerts.js against a stub DOM and returns what it stored.
 *
 * @param {Object} cfg Alert config, as the PHP side localises it.
 * @return {Object} { store, close, record }
 */
function boot( cfg ) {
	const store = {};
	const storage = {
		getItem: ( k ) => ( k in store ? store[ k ] : null ),
		setItem: ( k, v ) => {
			store[ k ] = String( v );
		}
	};

	const classList = ( set ) => ( {
		contains: ( c ) => set.has( c ),
		add: ( c ) => set.add( c ),
		remove: ( c ) => set.delete( c )
	} );

	const el = {
		hidden: true,
		classes: new Set(),
		querySelectorAll: () => [],
		setAttribute: () => {},
		focus: () => {},
		getAttribute: () => String( cfg.id )
	};
	el.classList = classList( el.classes );

	const doc = {
		readyState: 'complete',
		activeElement: null,
		cookie: '',
		body: { classList: classList( new Set() ) },
		documentElement: { scrollHeight: 1000 },
		addEventListener: () => {},
		dispatchEvent: () => {},
		getElementById: ( id ) => ( id === 'acps-alert-' + cfg.id ? el : null )
	};

	const win = {
		ACPSAlertsData: { alerts: [ cfg ], storage: 'local', isPreview: false },
		localStorage: storage,
		sessionStorage: storage,
		addEventListener: () => {},
		setTimeout: () => {},
		scrollY: 0,
		innerHeight: 800
	};

	new Function( 'window', 'document', 'CustomEvent', source )( win, doc, function () {} );

	return {
		store,
		api: win.ACPSAlerts,
		record: () => {
			const raw = store[ 'acps_alert_' + cfg.id ];

			return raw ? JSON.parse( raw ) : null;
		}
	};
}

const editMode = { id: 7, frequency: 'edit', trigger: 'load', version: '4-1700000000' };

// Opening records nothing now, so a visitor shown the popup who then navigates
// away has no record and is shown it again.
const shown = boot( editMode );
check( 'opening the alert records nothing on its own', shown.record(), null );
check( 'so a visitor who never pressed the X is shown it again', mayShow( editMode, shown.record(), {} ), true );

// Escape (or a background click) closes it for now but records nothing.
const escd = boot( editMode );
escd.api.close( editMode.id, false );
check( 'closing with Escape records nothing', escd.record(), null );
check( 'so it comes back on the next page', mayShow( editMode, escd.record(), {} ), true );

// The X records the dismissal, and only then does it stay away.
const xed = boot( editMode );
xed.api.close( editMode.id, true );
check( 'the X records the dismissal', !! ( xed.record() && xed.record().dismissedAt ), true );
check( 'and stamps it with the version', xed.record() && xed.record().version, '4-1700000000' );
check( 'and now it stays away', mayShow( editMode, xed.record(), {} ), false );

// Editing the alert moves the version on, and the dismissed visitor sees it again.
check( 'until the alert is edited', mayShow( { ...editMode, version: '5-1700000000' }, xed.record(), {} ), true );

// The days window runs from the dismissal (the X), not from being shown.
check( 'the days window runs from the dismissal', mayShow( { ...days, id: 7 }, { dismissedAt: Date.now() - ( 8 * 86400000 ), version: days.version, session: 'session-1' }, {} ), true );
check( 'and not yet inside it', mayShow( { ...days, id: 7 }, { dismissedAt: Date.now() - ( 2 * 86400000 ), version: days.version, session: 'session-1' }, {} ), false );

// A second alert's X records under its own key, not the first's.
const second = boot( { ...editMode, id: 8 } );
second.api.close( 8, true );
check( 'a second alert records under its own key', !! second.store[ 'acps_alert_8' ], true );

/* ---- the real bindings: X dismisses, Escape and background do not ---- */

/*
 * The rule lives in the event handlers, so this captures the real document
 * listeners the script registers, then fires an X click, an Escape key and an
 * overlay click, and checks which one actually wrote a dismissal. Calling
 * close() directly would not prove the bindings pass the right flag.
 */
function bootEvents() {
	const store = {};
	const storage = {
		getItem: ( k ) => ( k in store ? store[ k ] : null ),
		setItem: ( k, v ) => { store[ k ] = String( v ); }
	};
	const set = new Set();
	const el = {
		hidden: true,
		classList: { contains: ( c ) => set.has( c ), add: ( c ) => set.add( c ), remove: ( c ) => set.delete( c ) },
		querySelectorAll: () => [],
		setAttribute: () => {},
		focus: () => {},
		getAttribute: () => '1'
	};
	const listeners = {};
	const doc = {
		readyState: 'complete', activeElement: null, cookie: '',
		body: { classList: { contains: () => false, add: () => {}, remove: () => {} } },
		documentElement: { scrollHeight: 1000 },
		addEventListener: ( type, fn ) => { ( listeners[ type ] = listeners[ type ] || [] ).push( fn ); },
		dispatchEvent: () => {},
		getElementById: ( id ) => ( id === 'acps-alert-1' ? el : null )
	};
	const cfg = { id: 1, trigger: 'load', frequency: 'edit', version: '4-1700000000', escClose: true, overlayClose: true, dismissible: true };
	const win = {
		ACPSAlertsData: { alerts: [ cfg ], storage: 'local', isPreview: '0' },
		localStorage: storage, sessionStorage: storage,
		addEventListener: () => {}, setTimeout: () => {}, scrollY: 0, innerHeight: 800
	};

	new Function( 'window', 'document', 'CustomEvent', source )( win, doc, function () {} );

	const fire = ( type, event ) => ( listeners[ type ] || [] ).forEach( ( fn ) => fn( event ) );
	const record = () => ( store[ 'acps_alert_1' ] ? JSON.parse( store[ 'acps_alert_1' ] ) : null );

	return { fire, record, el, alertNode: { getAttribute: () => '1' } };
}

// A node whose closest() answers for one selector, standing in for the DOM.
function targetFor( selector, alertNode ) {
	return {
		closest: ( sel ) => {
			if ( sel === selector ) {
				return { closest: ( s ) => ( s === '.acps-alert' ? alertNode : null ) };
			}
			return null;
		}
	};
}

// The X button carries data-acps-close.
const xClick = bootEvents();
xClick.fire( 'click', { target: targetFor( '[data-acps-close]', xClick.alertNode ), preventDefault: () => {} } );
check( 'clicking the X records the dismissal', !! ( xClick.record() && xClick.record().dismissedAt ), true );

// Escape closes it but records nothing.
const escKey = bootEvents();
escKey.fire( 'keydown', { key: 'Escape', preventDefault: () => {} } );
check( 'pressing Escape records nothing', escKey.record(), null );

// Clicking the background (overlay) records nothing either.
const bgClick = bootEvents();
bgClick.fire( 'click', { target: targetFor( '[data-acps-overlay]', bgClick.alertNode ), preventDefault: () => {} } );
check( 'clicking the background records nothing', bgClick.record(), null );

/* ---- two real page loads, sharing one browser's storage ---- */

/*
 * The isolated mayShow() cases above prove the decision is right. This proves
 * the whole thing works end to end: boot the real script twice against ONE
 * shared localStorage and sessionStorage — a first page load, then a second in
 * the same session — and check the popup is auto-opened once and then left
 * alone. This is the exact "it shows every time" report, reproduced or ruled
 * out against the shipped code rather than a restatement of it.
 */
function twoLoads( freq, opts ) {
	opts = opts || {};
	const preview = undefined === opts.preview ? '0' : opts.preview; // what wp_localize_script sends for a non-preview visitor.
	const dismiss = opts.dismiss || 'none'; // 'none' | 'x' | 'esc'
	const local = {};
	const session = {};

	function storage( store ) {
		return {
			getItem: ( k ) => ( k in store ? store[ k ] : null ),
			setItem: ( k, v ) => { store[ k ] = String( v ); },
			removeItem: ( k ) => { delete store[ k ]; }
		};
	}

	function classes( set ) {
		return { contains: ( c ) => set.has( c ), add: ( c ) => set.add( c ), remove: ( c ) => set.delete( c ) };
	}

	function pageLoad( cfg ) {
		const el = { hidden: true, set: new Set(), querySelectorAll: () => [], setAttribute: () => {}, focus: () => {}, getAttribute: () => String( cfg.id ) };
		el.classList = classes( el.set );

		const doc = {
			readyState: 'complete', activeElement: null, cookie: '',
			body: { classList: classes( new Set() ) },
			documentElement: { scrollHeight: 1000 },
			addEventListener: () => {}, dispatchEvent: () => {},
			getElementById: ( id ) => ( id === 'acps-alert-' + cfg.id ? el : null )
		};

		const win = {
			ACPSAlertsData: { alerts: [ cfg ], storage: 'local', isPreview: preview },
			localStorage: storage( local ), sessionStorage: storage( session ),
			addEventListener: () => {}, setTimeout: () => {}, scrollY: 0, innerHeight: 800
		};

		new Function( 'window', 'document', 'CustomEvent', source )( win, doc, function () {} );

		return { opened: el.set.has( 'is-open' ), api: win.ACPSAlerts };
	}

	const cfg = { id: 1, trigger: 'load', frequency: freq, frequencyDays: 7, version: '4-1700000000' };

	const first = pageLoad( cfg );

	// Between the two page loads, maybe the visitor closes the popup. The X
	// dismisses it (store true); Escape and the background do not (store false).
	if ( 'x' === dismiss ) {
		first.api.close( cfg.id, true );
	} else if ( 'esc' === dismiss ) {
		first.api.close( cfg.id, false );
	}

	const second = pageLoad( cfg );

	return { first: first.opened, second: second.opened, local };
}

/* ---- only the X makes it stay away ---- */

/*
 * The rule: the popup keeps coming back until the visitor physically clicks the
 * X. Closing it with Escape or by clicking the background dismisses it for that
 * moment only — it returns on the next page. Being shown it, or navigating
 * away, records nothing at all.
 */
[ 'session', 'edit', 'once' ].forEach( function ( freq ) {
	// Shown, then navigated away without touching the X: it comes back.
	const seen = twoLoads( freq, { dismiss: 'none' } );
	check( `${ freq }: opens on the first visit`, seen.first, true );
	check( `${ freq }: and comes back on the next page when the X was not clicked`, seen.second, true );

	// Closed with the X: now it stays away.
	const xed = twoLoads( freq, { dismiss: 'x' } );
	check( `${ freq }: opens the first time`, xed.first, true );
	check( `${ freq }: and does NOT come back once the X is clicked`, xed.second, false );

	// Closed with Escape (or the background): it still comes back.
	const esc = twoLoads( freq, { dismiss: 'esc' } );
	check( `${ freq }: Escape closes it for now`, esc.first, true );
	check( `${ freq }: but it returns, because Escape is not the X`, esc.second, true );
} );

// "always" is the one mode that keeps showing regardless, X or no X.
const always2 = twoLoads( 'always', { dismiss: 'x' } );
check( 'always: opens on the first visit', always2.first, true );
check( 'always: and opens again even after the X, by design', always2.second, true );

// A blank/unknown frequency, once dismissed with the X, must not keep coming
// back like "always".
const blank = twoLoads( '', { dismiss: 'x' } );
check( 'blank frequency: opens once', blank.first, true );
check( 'blank frequency: does not keep reappearing after the X', blank.second, false );

/* ---- the "0" that WordPress sends is not a preview ---- */

/*
 * wp_localize_script stringifies everything, so a non-preview visitor arrives
 * with isPreview === "0" — and "0" is truthy. Read naively that made every
 * visitor look like an editor previewing: the popup showed on every page and
 * nothing was ever written to storage. Here the visitor clicks the X, which for
 * a real visitor must record the dismissal.
 */
const notPreview = twoLoads( 'session', { preview: '0', dismiss: 'x' } );
check( 'isPreview "0": opens on the first visit', notPreview.first, true );
check( 'isPreview "0": and is gated after the X, because "0" is not a preview', notPreview.second, false );
check( 'isPreview "0": and the X actually recorded the dismissal', !! notPreview.local[ 'acps_alert_1' ], true );

// A genuine preview ("1") shows every time and records nothing, even on an X,
// so an editor checking their work never burns the visitor's dismissal.
const realPreview = twoLoads( 'session', { preview: '1', dismiss: 'x' } );
check( 'isPreview "1": shows on the first visit', realPreview.first, true );
check( 'isPreview "1": and again, because previewing ignores frequency', realPreview.second, true );
check( 'isPreview "1": and writes nothing to storage even on an X', undefined, realPreview.local[ 'acps_alert_1' ] );

console.log( failures ? `\n${ failures } failing case(s)` : 'All frequency cases passed' );
process.exit( failures ? 1 : 0 );
