/**
 * The warning before this plugin is deleted, in a real browser.
 *
 * Loads the real assets/js/plugins-screen.js into a page shaped like the
 * WordPress Plugins screen: one row for this plugin, one for another, the bulk
 * actions form, and a stand-in for WordPress's own delete handler, registered
 * the way core's is (an ordinary bubbling click listener). The test is whether
 * the warning really stops that handler, and really lets it through on
 * "Delete anyway".
 *
 * Run: node tests/plugins-screen-test.js (uses the globally installed
 * Playwright and its Chromium).
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

const script = fs.readFileSync( path.join( __dirname, '..', 'acps-alert-popups', 'assets', 'js', 'plugins-screen.js' ), 'utf8' );
const OURS = 'acps-alert-popups/acps-alert-popups.php';

const html = `<!doctype html><html><head><meta charset="utf-8"></head><body>
<form id="bulk-action-form">
	<select name="action"><option value="-1">Bulk actions</option><option value="delete-selected">Delete</option></select>
	<button type="button" id="doaction">Apply</button>
	<table><tbody id="the-list">
		<tr data-plugin="${ OURS }"><th><input type="checkbox" name="checked[]" value="${ OURS }"></th>
			<td><span class="delete"><a href="#" class="delete" id="del-ours">Delete</a></span></td></tr>
		<tr data-plugin="hello-dolly/hello.php"><th><input type="checkbox" name="checked[]" value="hello-dolly/hello.php"></th>
			<td><span class="delete"><a href="#" class="delete" id="del-other">Delete</a></span></td></tr>
	</tbody></table>
	<select name="action2"><option value="-1">Bulk actions</option><option value="delete-selected">Delete</option></select>
	<button type="button" id="doaction2">Apply</button>
</form>
<script>
	// WordPress's own delete handling, standing in for wp-admin/js/updates.js.
	window.coreDeletes = [];
	window.coreBulk = 0;
	document.addEventListener( 'click', function ( e ) {
		var link = e.target.closest( '#the-list a.delete' );
		if ( link ) { e.preventDefault(); window.coreDeletes.push( link.closest( 'tr' ).getAttribute( 'data-plugin' ) ); }
		if ( e.target.closest( '#doaction, #doaction2' ) ) { window.coreBulk++; }
	} );
	window.ACPSAlertsPlugins = {
		basename: ${ JSON.stringify( OURS ) },
		settingsUrl: 'https://example.org/wp-admin/admin.php?page=acps-alerts-settings#acps-features',
		title: 'Deleting ACPS Alert Popups is not advised',
		body: [ 'Features that depend on it will stop working.', 'Every feature can be switched off on its own in Site Alerts → Settings → Features.' ],
		openSettings: 'Open Settings → Features',
		cancel: 'Cancel',
		deleteAnyway: 'Delete anyway'
	};
</script>
<script>${ script }</script>
</body></html>`;

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

	page.on( 'pageerror', ( e ) => errors.push( e.message ) );

	const visible = () => page.evaluate( () => {
		const d = document.querySelector( '.acps-del' );
		return !! d && ! d.hasAttribute( 'hidden' );
	} );
	const deletes = () => page.evaluate( () => window.coreDeletes.slice() );

	await page.setContent( html );

	check( 'nothing is added to the screen until someone asks to delete', await page.evaluate( () => !! document.querySelector( '.acps-del' ) ), false );

	// Another plugin's delete is none of our business.
	await page.click( '#del-other' );
	check( 'deleting another plugin shows no warning', await visible(), false );
	check( 'and goes straight through', await deletes(), [ 'hello-dolly/hello.php' ] );

	// Ours: warned first, and WordPress's delete does not run.
	await page.click( '#del-ours' );
	check( 'deleting this plugin shows the warning', await visible(), true );
	check( 'and WordPress\'s own delete has not run', await deletes(), [ 'hello-dolly/hello.php' ] );

	const text = await page.textContent( '.acps-del' );
	check( 'the warning says it is not advised', text.includes( 'not advised' ), true );
	check( 'that features will stop working', text.includes( 'stop working' ), true );
	check( 'and that features can be switched off in Settings instead', text.includes( 'Settings → Features' ), true );
	check( 'it offers the way to Settings → Features', await page.getAttribute( '.acps-del a.button-primary', 'href' ), 'https://example.org/wp-admin/admin.php?page=acps-alerts-settings#acps-features' );
	check( 'the safe choice has the focus', await page.evaluate( () => document.activeElement.classList.contains( 'button-primary' ) ), true );

	// Cancel: nothing deleted.
	await page.click( '.acps-del button:not(.acps-del__delete)' );
	check( 'Cancel closes the warning', await visible(), false );
	check( 'and deletes nothing', await deletes(), [ 'hello-dolly/hello.php' ] );

	// Escape: nothing deleted.
	await page.click( '#del-ours' );
	await page.keyboard.press( 'Escape' );
	check( 'Escape closes the warning', await visible(), false );
	check( 'and deletes nothing', await deletes(), [ 'hello-dolly/hello.php' ] );

	// Delete anyway: WordPress's own delete runs, exactly once.
	await page.click( '#del-ours' );
	await page.click( '.acps-del__delete' );
	check( '"Delete anyway" closes the warning', await visible(), false );
	check( 'and hands over to WordPress\'s delete, once', await deletes(), [ 'hello-dolly/hello.php', OURS ] );

	// Bulk delete with this plugin ticked is warned about too.
	await page.selectOption( 'select[name="action"]', 'delete-selected' );
	await page.check( `input[value="${ OURS }"]` );
	await page.click( '#doaction' );
	check( 'bulk-deleting with this plugin ticked shows the warning', await visible(), true );
	check( 'and the bulk delete has not run', await page.evaluate( () => window.coreBulk ), 0 );
	await page.click( '.acps-del__delete' );
	check( 'until "Delete anyway"', await page.evaluate( () => window.coreBulk ), 1 );

	// The bottom Apply button reads its own select.
	await page.selectOption( 'select[name="action"]', '-1' );
	await page.selectOption( 'select[name="action2"]', 'delete-selected' );
	await page.click( '#doaction2' );
	check( 'the bottom bulk button is covered too', await visible(), true );
	await page.keyboard.press( 'Escape' );

	// A bulk delete without this plugin ticked is left alone.
	await page.uncheck( `input[value="${ OURS }"]` );
	await page.click( '#doaction2' );
	check( 'a bulk delete of other plugins shows no warning', await visible(), false );
	check( 'and goes straight through', await page.evaluate( () => window.coreBulk ), 2 );

	check( 'no script errors on the page', errors, [] );

	await browser.close();

	console.log( failures ? `\n${ failures } failing case(s)` : 'All plugins-screen cases passed' );
	process.exit( failures ? 1 : 0 );
} )().catch( ( e ) => {
	console.log( 'FAIL the browser test could not run: ' + e.message );
	process.exit( 1 );
} );
