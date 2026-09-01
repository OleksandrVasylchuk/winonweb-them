/**
 * Pixel comparison: every built page against the design file it came from.
 *
 * The fidelity measure inside the build counts words and elements, which
 * catches lost rows and stamped repeats — but not a wrong colour, a
 * collapsed container or a font that fell back. Those are pixels, so this
 * renders both sides in the same browser at the same width and counts the
 * pixels that differ.
 *
 * Setup, once:  npm install && npx playwright install chromium
 *
 * Input: artifacts/pixel-manifest.json — an array of pages to compare:
 *   [ { "name": "en/home", "live": "http://…/", "origin": "D:/…/en/index.html" } ]
 * The importer knows both halves of every pair; a small site script writes
 * the manifest from the last build's report (see the Handbook).
 *
 * Output: artifacts/pixels/<name>-{origin,live,diff}.png and report.html —
 * the two renders and a red overlay of what moved, with a percentage per
 * page. The percentage is honest, not absolute: the live page carries a
 * real menu and live records where the design carried samples, so identical
 * is not the target — stable-and-low is. Read the diff image, not only the
 * number.
 */

import { createRequire } from 'node:module';
import { createServer } from 'node:http';
import { mkdirSync, readFileSync, writeFileSync, existsSync } from 'node:fs';
import { resolve, dirname, extname, normalize, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire( import.meta.url );
const { chromium } = require( 'playwright' );

const root = resolve( dirname( fileURLToPath( import.meta.url ) ), '..' );

const args = process.argv.slice( 2 );
const flag = ( name, fallback ) => {
	const at = args.indexOf( `--${ name }` );
	return at > -1 && args[ at + 1 ] ? args[ at + 1 ] : fallback;
};

const manifestPath = resolve( root, flag( 'manifest', 'artifacts/pixel-manifest.json' ) );
const outDir       = resolve( root, flag( 'out', 'artifacts/pixels' ) );
const width        = Number( flag( 'width', '1280' ) );

/* Per-channel difference below this is the same pixel: antialiasing and
 * JPEG-adjacent noise, not a defect. */
const TOLERANCE = 24;

let manifest;
try {
	manifest = JSON.parse( readFileSync( manifestPath, 'utf8' ) );
} catch {
	console.error( `No manifest at ${ manifestPath }.` );
	console.error( 'Write one as [ { "name": "...", "live": "http://...", "origin": "/abs/path.html" } ].' );
	process.exit( 1 );
}

mkdirSync( outDir, { recursive: true } );

/* Animations and carets off, the admin bar gone: what is compared is the
 * page, not the moment it was photographed in. */
const CALM = `
	*, *::before, *::after { animation: none !important; transition: none !important; caret-color: transparent !important; }
	#wpadminbar { display: none !important; }
	html { margin-top: 0 !important; scroll-behavior: auto !important; }
`;

/*
 * The originals are served over a local port rather than file:// — Windows
 * cuts file URLs off at its old path limit, and a handoff nests its pages
 * two hundred and seventy characters deep without blinking. Each manifest
 * entry is mounted at /<index>/, with its page's own directory as the root
 * so relative stylesheets and pictures resolve exactly as on disk.
 */
const MIME = {
	'.html': 'text/html; charset=utf-8',
	'.css': 'text/css',
	'.js': 'text/javascript',
	'.png': 'image/png',
	'.jpg': 'image/jpeg',
	'.jpeg': 'image/jpeg',
	'.webp': 'image/webp',
	'.svg': 'image/svg+xml',
	'.gif': 'image/gif',
	'.woff': 'font/woff',
	'.woff2': 'font/woff2',
};

/*
 * Each page is mounted at its real depth below a roof five directories up,
 * so a `../assets/styles.css` climbs exactly as far in the URL as it does on
 * disk and stays inside the mount. Mounted at the root instead, the first
 * `../` left the mount, the stylesheet 404ed silently, and every "design"
 * screenshot was an unstyled column of text that nothing could match.
 */
const mounts = manifest.map( ( entry ) => {
	const origin = resolve( entry.origin );
	const roof   = normalize( resolve( dirname( origin ), '../../../../..' ) );

	return {
		roof,
		path: origin.slice( roof.length + 1 ).split( sep ).join( '/' ),
	};
} );

const server = createServer( ( request, response ) => {
	const [ , index, ...rest ] = decodeURIComponent( request.url.split( '?' )[ 0 ] ).split( '/' );
	const mount = mounts[ Number( index ) ];

	if ( ! mount ) {
		response.writeHead( 404 ).end();
		return;
	}

	const file = normalize( resolve( mount.roof, rest.join( '/' ) ) );

	// Never above the roof, however many ../ the URL carries.
	if ( ! file.startsWith( mount.roof + sep ) || ! existsSync( file ) ) {
		response.writeHead( 404 ).end();
		return;
	}

	response.writeHead( 200, { 'content-type': MIME[ extname( file ).toLowerCase() ] || 'application/octet-stream' } );
	response.end( readFileSync( file ) );
} );

await new Promise( ( ready ) => server.listen( 0, '127.0.0.1', ready ) );

const port = server.address().port;

const browser = await chromium.launch();
const rows    = [];

async function shoot( page, target, file ) {
	await page.goto( target, { waitUntil: 'networkidle', timeout: 60000 } );
	await page.addStyleTag( { content: CALM } );

	/* Walk the page once so lazy pictures load before the photograph. */
	await page.evaluate( async () => {
		await new Promise( ( done ) => {
			let y = 0;
			const step = () => {
				y += 900;
				window.scrollTo( 0, y );
				if ( y < document.body.scrollHeight ) {
					setTimeout( step, 60 );
				} else {
					window.scrollTo( 0, 0 );
					setTimeout( done, 200 );
				}
			};
			step();
		} );
	} );

	await page.screenshot( { path: file, fullPage: true } );
}

/* The diff runs inside the same browser: two canvases, one pass over the
 * pixels, a red overlay where they part ways. No native image dependency. */
async function diff( page, originFile, liveFile, diffFile ) {
	const originData = readFileSync( originFile ).toString( 'base64' );
	const liveData   = readFileSync( liveFile ).toString( 'base64' );

	await page.goto( 'about:blank' );

	const result = await page.evaluate(
		async ( { a, b, tolerance } ) => {
			const load = ( source ) =>
				new Promise( ( done, fail ) => {
					const image = new Image();
					image.onload = () => done( image );
					image.onerror = fail;
					image.src = `data:image/png;base64,${ source }`;
				} );

			const [ one, two ] = await Promise.all( [ load( a ), load( b ) ] );
			const w = Math.max( one.width, two.width );
			const h = Math.max( one.height, two.height );

			/*
			 * The share of changed pixels is counted over the height both
			 * pages HAVE. A built page an accordion taller than its design is
			 * a fact worth reporting — as a height difference, once — not as
			 * a million "changed" pixels of padding drowning the real signal.
			 */
			const shared = Math.min( one.height, two.height );

			const draw = ( image ) => {
				const canvas = document.createElement( 'canvas' );
				canvas.width = w;
				canvas.height = h;
				const context = canvas.getContext( '2d' );
				context.fillStyle = '#ffffff';
				context.fillRect( 0, 0, w, h );
				context.drawImage( image, 0, 0 );
				return context.getImageData( 0, 0, w, h );
			};

			const left  = draw( one );
			const right = draw( two );

			const overlay = document.createElement( 'canvas' );
			overlay.width = w;
			overlay.height = h;
			const context = overlay.getContext( '2d' );
			const out = context.createImageData( w, h );

			let changed = 0;
			const cap  = shared * w * 4;

			for ( let i = 0; i < left.data.length; i += 4 ) {
				const dr = Math.abs( left.data[ i ] - right.data[ i ] );
				const dg = Math.abs( left.data[ i + 1 ] - right.data[ i + 1 ] );
				const db = Math.abs( left.data[ i + 2 ] - right.data[ i + 2 ] );

				if ( dr > tolerance || dg > tolerance || db > tolerance ) {
					if ( i < cap ) {
						changed++;
					}
					out.data[ i ] = 220;
					out.data[ i + 1 ] = 30;
					out.data[ i + 2 ] = 30;
					out.data[ i + 3 ] = 255;
				} else {
					const grey = ( left.data[ i ] + left.data[ i + 1 ] + left.data[ i + 2 ] ) / 3;
					out.data[ i ] = grey;
					out.data[ i + 1 ] = grey;
					out.data[ i + 2 ] = grey;
					out.data[ i + 3 ] = 70;
				}
			}

			context.putImageData( out, 0, 0 );

			return {
				percent: Math.round( ( changed / ( w * shared ) ) * 1000 ) / 10,
				grew: two.height - one.height,
				image: overlay.toDataURL( 'image/png' ).split( ',' )[ 1 ],
			};
		},
		{ a: originData, b: liveData, tolerance: TOLERANCE }
	);

	writeFileSync( diffFile, Buffer.from( result.image, 'base64' ) );

	return result;
}

for ( const [ index, entry ] of manifest.entries() ) {
	const name = String( entry.name || '' ).replace( /[^a-z0-9_-]+/gi, '-' );

	if ( ! name || ! entry.live || ! entry.origin ) {
		continue;
	}

	const page = await browser.newPage( { viewport: { width, height: 900 } } );

	const originFile = resolve( outDir, `${ name }-origin.png` );
	const liveFile   = resolve( outDir, `${ name }-live.png` );
	const diffFile   = resolve( outDir, `${ name }-diff.png` );

	try {
		await shoot( page, `http://127.0.0.1:${ port }/${ index }/${ mounts[ index ].path }`, originFile );
		await shoot( page, entry.live, liveFile );

		const result = await diff( page, originFile, liveFile, diffFile );

		rows.push( { name, percent: result.percent, grew: result.grew } );
		console.log( `${ String( result.percent ).padStart( 5 ) }%  ${ name }${ result.grew ? `  (height ${ result.grew > 0 ? '+' : '' }${ result.grew }px)` : '' }` );
	} catch ( error ) {
		rows.push( { name, percent: null, error: String( error.message || error ) } );
		console.log( `  err  ${ name } — ${ error.message }` );
	} finally {
		await page.close();
	}
}

await browser.close();
server.close();

const cells = rows
	.map( ( row ) => {
		const label = row.percent === null ? `failed: ${ row.error }` : `${ row.percent }% of pixels differ`;

		return `<section><h2>${ row.name } — ${ label }</h2>
			<div class="strip">
				<figure><img src="${ row.name }-origin.png" loading="lazy"><figcaption>design</figcaption></figure>
				<figure><img src="${ row.name }-live.png" loading="lazy"><figcaption>built</figcaption></figure>
				<figure><img src="${ row.name }-diff.png" loading="lazy"><figcaption>difference</figcaption></figure>
			</div></section>`;
	} )
	.join( '\n' );

writeFileSync(
	resolve( outDir, 'report.html' ),
	`<!doctype html><meta charset="utf-8"><title>Pixel report</title>
	<style>
		body { font: 14px/1.5 system-ui; margin: 2rem; }
		.strip { display: flex; gap: 1rem; align-items: flex-start; }
		figure { margin: 0; flex: 1; min-width: 0; }
		img { width: 100%; border: 1px solid #ccc; }
		figcaption { color: #666; font-size: 12px; }
		h2 { font-size: 15px; }
	</style>
	<h1>Built pages against the design</h1>
	${ cells }`
);

const measured = rows.filter( ( row ) => row.percent !== null );

if ( measured.length ) {
	const worst = measured.reduce( ( a, b ) => ( a.percent > b.percent ? a : b ) );
	console.log( `\n${ measured.length } pages compared — worst ${ worst.percent }% (${ worst.name }).` );
	console.log( `Report: ${ resolve( outDir, 'report.html' ) }` );
}
