/**
 * Render the WordPress.org listing artwork from the HTML sources beside this
 * file.
 *
 * The banner and icon are drawn as HTML/SVG and screenshotted by Chrome, so the
 * artwork source stays editable text rather than a binary nobody can change.
 * Each source is laid out once at its natural size; the smaller required
 * variants come from the device scale factor, so both sizes are the same
 * drawing rather than two that can drift apart.
 *
 *   node .wordpress-org/src/render-assets.mjs
 *
 * Set CHROME_PATH if Chrome is not at the default Windows location.
 */
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import puppeteer from 'puppeteer-core';

const here = dirname( fileURLToPath( import.meta.url ) );
const out = join( here, '..' );

const CHROME =
	process.env.CHROME_PATH ||
	'C:/Program Files/Google/Chrome/Application/chrome.exe';

/**
 * Capture the #stage element of one source file.
 *
 * @param {import('puppeteer-core').Browser} browser Browser instance.
 * @param {string}                           file    Source HTML file name.
 * @param {number}                           width   Natural stage width.
 * @param {number}                           height  Natural stage height.
 * @param {number}                           scale   Device scale factor.
 * @param {string}                           target  Output file name.
 */
async function shot( browser, file, width, height, scale, target ) {
	const page = await browser.newPage();

	await page.setViewport( { width, height, deviceScaleFactor: scale } );

	await page.goto( 'file:///' + join( here, file ).replace( /\\/g, '/' ), {
		waitUntil: 'networkidle0',
	} );

	// Let webfonts swap in before capturing, or the banner renders in a
	// fallback face.
	await page.evaluate( () => document.fonts.ready );

	const stage = await page.$( '#stage' );

	await stage.screenshot( { path: join( out, target ), type: 'png' } );
	await page.close();

	console.log(
		`wrote ${ target } (${ Math.round( width * scale ) }x${ Math.round(
			height * scale
		) })`
	);
}

const browser = await puppeteer.launch( {
	executablePath: CHROME,
	headless: true,
	args: [ '--hide-scrollbars' ],
} );

await shot( browser, 'banner.html', 1544, 500, 1, 'banner-1544x500.png' );
await shot( browser, 'banner.html', 1544, 500, 0.5, 'banner-772x250.png' );
await shot( browser, 'icon.html', 512, 512, 0.5, 'icon-256x256.png' );
await shot( browser, 'icon.html', 512, 512, 0.25, 'icon-128x128.png' );

await browser.close();
