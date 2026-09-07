/**
 * Capture the WordPress.org listing screenshots from a running wp-env site.
 *
 * Everything it photographs is a real screen rendered by the plugin against
 * real rows in the database — seed them first with seed-demo.php. Nothing here
 * mocks a state the plugin cannot actually produce.
 *
 *   npm run env:start
 *   npx wp-env run cli wp eval-file \
 *     wp-content/plugins/jobs-sync-for-zoho-recruit/.wordpress-org/src/seed-demo.php
 *   node .wordpress-org/src/render-screenshots.mjs
 *
 * Environment: SITE_URL, WP_USER, WP_PASS, CHROME_PATH.
 */
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import puppeteer from 'puppeteer-core';

const here = dirname( fileURLToPath( import.meta.url ) );
const out = join( here, '..' );

const SITE = process.env.SITE_URL || 'http://localhost:8888';
const USER = process.env.WP_USER || 'admin';
const PASS = process.env.WP_PASS || 'password';
const CHROME =
	process.env.CHROME_PATH ||
	'C:/Program Files/Google/Chrome/Application/chrome.exe';

const VIEWPORT = { width: 1440, height: 900, deviceScaleFactor: 1 };

const browser = await puppeteer.launch( {
	executablePath: CHROME,
	headless: true,
	args: [ '--hide-scrollbars', '--window-size=1440,900' ],
} );

const page = await browser.newPage();
await page.setViewport( VIEWPORT );

// Sign in once; every admin capture reuses the session.
await page.goto( `${ SITE }/wp-login.php`, { waitUntil: 'networkidle0' } );
await page.type( '#user_login', USER );
await page.type( '#user_pass', PASS );
await Promise.all( [
	page.waitForNavigation( { waitUntil: 'networkidle0' } ),
	page.click( '#wp-submit' ),
] );

/**
 * Capture one screen.
 *
 * @param {string} path   Path on the site, relative to SITE.
 * @param {string} target Output file name.
 * @param {Object} opts   Optional settings.
 * @param {number} opts.scrollTo Pixels to scroll before capturing.
 * @param {number} opts.settle   Extra milliseconds to wait.
 */
async function capture( path, target, opts = {} ) {
	await page.goto( `${ SITE }${ path }`, { waitUntil: 'networkidle0' } );

	if ( opts.scrollTo ) {
		await page.evaluate( ( y ) => window.scrollTo( 0, y ), opts.scrollTo );
	}

	await new Promise( ( resolve ) =>
		setTimeout( resolve, opts.settle ?? 700 )
	);

	await page.screenshot( { path: join( out, target ), type: 'png' } );

	console.log( `wrote ${ target } <- ${ path }` );
}

// The front end is captured signed out, in a clean context, so the admin bar
// does not sit across the top of a visitor-facing screenshot.
const visitorContext = await browser.createBrowserContext();
const visitor = await visitorContext.newPage();
await visitor.setViewport( VIEWPORT );

/**
 * Capture one front-end screen as a signed-out visitor.
 *
 * @param {string} path     Path on the site.
 * @param {string} target   Output file name.
 * @param {number} scrollTo Pixels to scroll before capturing.
 */
async function captureVisitor( path, target, scrollTo = 0 ) {
	await visitor.goto( `${ SITE }${ path }`, { waitUntil: 'networkidle0' } );

	if ( scrollTo ) {
		await visitor.evaluate( ( y ) => window.scrollTo( 0, y ), scrollTo );
	}

	await new Promise( ( resolve ) => setTimeout( resolve, 700 ) );
	await visitor.screenshot( { path: join( out, target ), type: 'png' } );

	console.log( `wrote ${ target } <- ${ path } (signed out)` );
}

// 1. The front end: the block, with filters and search, on a block theme.
await captureVisitor( '/careers/', 'screenshot-1.png', 210 );

// 2. A single job page: meta, description and the apply button.
await captureVisitor( '/jobs/senior-backend-engineer/', 'screenshot-2.png', 0 );

// 3. The admin job list, with the columns and status filter the plugin adds.
await capture(
	'/wp-admin/edit.php?post_type=zoho_job',
	'screenshot-3.png'
);

// 4. Field mapping.
await capture(
	'/wp-admin/edit.php?post_type=zoho_job&page=jszr-mapping',
	'screenshot-4.png'
);

// 5. Connection settings, showing the redirect URI and the requested scopes.
await capture(
	'/wp-admin/edit.php?post_type=zoho_job&page=jszr-settings&tab=connection',
	'screenshot-5.png'
);

await browser.close();
