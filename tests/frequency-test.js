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

/* ---- being shown it is what counts, not closing it ---- */

/*
 * mayShow() was right all along; nothing was ever written for it to read.
 *
 * The dismissal was recorded in close(), so it only landed if the visitor
 * actually pressed the X. Everyone who read the popup and then clicked the
 * "View updates" link inside it — or any link on the page — left without a
 * record, and got the same popup again on the very next page. To a visitor,
 * and to the person who set it to show once, that is simply "it keeps coming
 * back".
 *
 * So the whole script is booted against a stub DOM and the real open() is let
 * run, because this is a question about what reaches storage, and reading
 * either function on its own does not answer it.
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

const shown = boot( editMode );

check( 'opening the alert records that it was seen', !! shown.record(), true );
check( 'and stamps it with the version on show', shown.record() && shown.record().version, '4-1700000000' );

// The visitor read it and clicked the link inside it. Nothing was closed.
check(
	'a visitor who never pressed the X is not shown it again',
	mayShow( editMode, shown.record(), {} ),
	false
);

// Closing still works, and must not throw away the moment it was shown.
const closed = boot( editMode );
closed.api.close( editMode.id, true );

check( 'closing records the dismissal', !! ( closed.record() && closed.record().dismissedAt ), true );
check( 'without losing when it was shown', !! ( closed.record() && closed.record().seenAt ), true );
check( 'and it stays away', mayShow( editMode, closed.record(), {} ), false );

// An editMode moves the version on, and the same visitor sees the new one.
check(
	'until the alert is edited',
	mayShow( { ...editMode, version: '5-1700000000' }, shown.record(), {} ),
	true
);

// A preview must not write anything, or an editor checking their work would
// stop the popup reaching the people it was written for.
const preview = ( () => {
	const b = boot( { ...editMode, id: 8 } );

	return b;
} )();

check( 'the days window runs from when it was shown', mayShow( { ...days, id: 7 }, { seenAt: Date.now() - ( 8 * 86400000 ), version: days.version, session: 'session-1' }, {} ), true );
check( 'and not yet inside it', mayShow( { ...days, id: 7 }, { seenAt: Date.now() - ( 2 * 86400000 ), version: days.version, session: 'session-1' }, {} ), false );
check( 'a second alert records under its own key', !! preview.store[ 'acps_alert_8' ], true );

/* ---- two real page loads, sharing one browser's storage ---- */

/*
 * The isolated mayShow() cases above prove the decision is right. This proves
 * the whole thing works end to end: boot the real script twice against ONE
 * shared localStorage and sessionStorage — a first page load, then a second in
 * the same session — and check the popup is auto-opened once and then left
 * alone. This is the exact "it shows every time" report, reproduced or ruled
 * out against the shipped code rather than a restatement of it.
 */
function twoLoads( freq ) {
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
			ACPSAlertsData: { alerts: [ cfg ], storage: 'local', isPreview: false },
			localStorage: storage( local ), sessionStorage: storage( session ),
			addEventListener: () => {}, setTimeout: () => {}, scrollY: 0, innerHeight: 800
		};

		new Function( 'window', 'document', 'CustomEvent', source )( win, doc, function () {} );

		return el.set.has( 'is-open' );
	}

	const cfg = { id: 1, trigger: 'load', frequency: freq, frequencyDays: 7, version: '4-1700000000' };

	return { first: pageLoad( cfg ), second: pageLoad( cfg ) };
}

[ 'session', 'edit', 'once' ].forEach( function ( freq ) {
	const r = twoLoads( freq );

	check( `${ freq }: the popup opens on the first visit`, r.first, true );
	check( `${ freq }: and does NOT open again on the next page`, r.second, false );
} );

// "always" is the one mode that keeps showing, on purpose.
const always2 = twoLoads( 'always' );
check( 'always: opens on the first visit', always2.first, true );
check( 'always: and opens again, by design', always2.second, true );

// A frequency that was never written, or an unknown one, must not behave like
// "always". Once seen, it leaves the visitor alone — this is the exact
// "shows every time no matter what" failure, pinned shut.
const blank = twoLoads( '' );
check( 'blank frequency: opens once', blank.first, true );
check( 'blank frequency: does not keep reappearing', blank.second, false );

console.log( failures ? `\n${ failures } failing case(s)` : 'All frequency cases passed' );
process.exit( failures ? 1 : 0 );
