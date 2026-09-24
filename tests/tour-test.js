/**
 * The guided-tour engine, in a real browser, across real page loads.
 *
 * Loads the real assets/js/tour.js into two stand-in admin screens ("list" and
 * "post") served from a fake origin, and walks a four-step tour across them:
 *
 *   0  list  (url)       — the first step of the list screen's block
 *   1  list              — same screen, no url
 *   2  post  (url)       — the first step of the post screen's block
 *   3  post  (url)       — its element is missing on this install
 *
 * What is under test is the part that makes the tour "physically take you
 * there": the button that moves you to the next screen, the tour picking up
 * where it left off after the page load, Back walking to the previous screen,
 * and no endless reloading when a step's element is not on the page.
 *
 * Run: node tests/tour-test.js (uses the globally installed Playwright).
 */

'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const { execSync } = require( 'child_process' );

let playwright;

try {
	playwright = require( 'playwright' );
} catch ( e ) {
	playwright = require( path.join( execSync( 'npm root -g' ).toString().trim(), 'playwright' ) );
}

const engine = fs.readFileSync( path.join( __dirname, '..', 'acps-alert-popups', 'assets', 'js', 'tour.js' ), 'utf8' );
const css = fs.readFileSync( path.join( __dirname, '..', 'acps-alert-popups', 'assets', 'css', 'tour.css' ), 'utf8' );
const ORIGIN = 'https://wp.test';

const tours = {
	t: {
		title: 'Test tour',
		steps: [
			{ screen: 'list', url: ORIGIN + '/list', selector: '#a', title: 'List A', html: '<p>a</p>', goLabel: 'Go to list' },
			{ screen: 'list', selector: '#b', title: 'List B', html: '<p>b</p>' },
			{ screen: 'post', url: ORIGIN + '/post', selector: '#p', title: 'Post P', html: '<p>p</p>', goLabel: 'Go to post' },
			{ screen: 'post', url: ORIGIN + '/post', selector: '#missing', title: 'Post Missing', html: '<p>m</p>', goLabel: 'Go to post again' }
		]
	}
};

function screen( name, own ) {
	return `<!doctype html><html><head><meta charset="utf-8"><style>${ css }</style></head><body>
<div class="wrap"><h1>${ name }</h1>
${ 'list' === name ? '<p id="a">A</p><p id="b">B</p>' : '<p id="p">P</p>' }
<button type="button" data-acps-tour="t" id="start">Start</button></div>
<script>
window.ACPSAlertsTour = ${ JSON.stringify( {
		screen: name,
		own: own,
		tours: tours,
		resume: {},
		i18n: { next: 'Next', back: 'Back', skip: 'Skip', finish: 'Finish', close: 'Close', takeMeThere: 'Take me there', stepOf: 'Step %1$s of %2$s', done: 'Tour finished.' }
	} ) };
// The tour resumes from the URL, as ACPS_Alerts_Help::enqueue() passes it on.
var q = new URLSearchParams( location.search );
if ( q.get( 'acps_tour' ) ) {
	window.ACPSAlertsTour.resume = { tour: q.get( 'acps_tour' ), step: parseInt( q.get( 'acps_tour_step' ), 10 ) };
}
</script>
<script>${ engine }</script>
</body></html>`;
}

let failures = 0;

function check( label, actual, expected ) {
	if ( JSON.stringify( actual ) !== JSON.stringify( expected ) ) {
		failures++;
		console.log( `FAIL ${ label }: expected ${ JSON.stringify( expected ) }, got ${ JSON.stringify( actual ) }` );
	}
}

( async () => {
	const browser = await playwright.chromium.launch();
	const page = await browser.newPage();
	const errors = [];
	let own = true;
	let loads = 0;

	page.on( 'pageerror', ( e ) => errors.push( e.message ) );

	await page.route( ORIGIN + '/**', ( route ) => {
		loads++;
		const name = new URL( route.request().url() ).pathname.slice( 1 );
		route.fulfill( { contentType: 'text/html', body: screen( name, own ) } );
	} );

	const title = () => page.textContent( '.acps-tour__title' );
	const next = () => page.textContent( '.acps-tour__next' );

	await page.goto( ORIGIN + '/list' );
	await page.click( '#start' );

	check( 'the tour opens on its first step', await title(), 'List A' );
	await page.click( '.acps-tour__next' );
	check( 'Next moves along the same screen', await title(), 'List B' );

	// The next step lives on another screen: the button offers to go there.
	await page.click( '.acps-tour__next' );
	check( 'a step on another screen is announced', await title(), 'Post P' );
	check( 'with a button that takes you there', await next(), 'Go to post' );

	// ...and it really does: a page load, and the tour picks up on arrival.
	await Promise.all( [ page.waitForURL( /\/post\?/ ), page.click( '.acps-tour__next' ) ] );
	const url = new URL( page.url() );
	check( 'the button physically moves you to that screen', url.pathname, '/post' );
	check( 'carrying the tour with it', url.searchParams.get( 'acps_tour' ), 't' );
	check( 'and the step it was on', url.searchParams.get( 'acps_tour_step' ), '2' );
	await page.waitForSelector( '.acps-tour__bubble' );
	check( 'the tour picks up where it left off on the new screen', await title(), 'Post P' );
	check( 'now as an ordinary step', await next(), 'Next' );

	// A step whose element is not on this install, on the right screen, is
	// explained in place — not "taken there", which would reload this same page
	// for ever.
	const loadsBefore = loads;
	await page.click( '.acps-tour__next' );
	check( 'a step whose element is missing still shows', await title(), 'Post Missing' );
	check( 'as the last step, not as a trip to the page it is already on', await next(), 'Finish' );
	check( 'without reloading the page', loads, loadsBefore );

	// Back across the screen boundary walks to the start of the previous
	// screen's steps, which knows the way back there.
	await page.click( '.acps-tour__back' );
	check( 'Back goes to the previous step', await title(), 'Post P' );
	await page.click( '.acps-tour__back' );
	check( 'Back across screens reaches the previous screen\'s steps', await title(), 'List A' );
	check( 'and offers to take you back there', await next(), 'Go to list' );

	await Promise.all( [ page.waitForURL( /\/list\?/ ), page.click( '.acps-tour__next' ) ] );
	await page.waitForSelector( '.acps-tour__bubble' );
	check( 'and does take you back', new URL( page.url() ).pathname, '/list' );
	check( 'resuming at that step', await title(), 'List A' );

	// Finishing on one of the plugin's own screens shows the well-done note.
	await page.evaluate( () => document.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'Escape' } ) ) );
	await page.goto( ORIGIN + '/post?acps_tour=t&acps_tour_step=3' );
	await page.waitForSelector( '.acps-tour__bubble' );
	await page.click( '.acps-tour__next' );
	check( 'finishing on the plugin\'s own screen shows the well-done note', await page.evaluate( () => !! document.querySelector( '.acps-tour-done' ) ), true );

	// ...but never on a screen that is not the plugin's own.
	own = false;
	await page.goto( ORIGIN + '/post?acps_tour=t&acps_tour_step=3' );
	await page.waitForSelector( '.acps-tour__bubble' );
	await page.click( '.acps-tour__next' );
	check( 'finishing on another screen adds nothing to that page', await page.evaluate( () => !! document.querySelector( '.acps-tour-done, .notice' ) ), false );

	check( 'no script errors on any page', errors, [] );

	await browser.close();

	console.log( failures ? `\n${ failures } failing case(s)` : 'All tour cases passed' );
	process.exit( failures ? 1 : 0 );
} )().catch( ( e ) => {
	console.log( 'FAIL the browser test could not run: ' + e.message );
	process.exit( 1 );
} );
