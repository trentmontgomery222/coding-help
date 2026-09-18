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

console.log( failures ? `\n${ failures } failing case(s)` : 'All frequency cases passed' );
process.exit( failures ? 1 : 0 );
