#!/usr/bin/env node
/**
 * Read, check and set the plugin version.
 *
 * The version lives in four places that must agree: the plugin header, the
 * VERSION constant, readme.txt's Stable tag and package.json. CI runs `check`
 * on every push and the release job relies on it, so a release can never ship
 * a ZIP whose header says one version and whose readme says another.
 *
 *   node bin/version.js get              Print the version from the plugin header.
 *   node bin/version.js check [x.y.z]    Fail unless every place agrees (and
 *                                        matches x.y.z when given, e.g. a tag).
 *   node bin/version.js notes [x.y.z]    Print that version's CHANGELOG.md section.
 *   node bin/version.js set x.y.z        Write x.y.z to all four places.
 */

'use strict';

const fs = require( 'fs' );
const path = require( 'path' );

const root = path.resolve( __dirname, '..' );
const files = {
	plugin: path.join( root, 'jobs-sync-for-zoho-recruit.php' ),
	readme: path.join( root, 'readme.txt' ),
	pkg: path.join( root, 'package.json' ),
	lock: path.join( root, 'package-lock.json' ),
	changelog: path.join( root, 'CHANGELOG.md' ),
};

// WordPress-style versions: 1.2, 1.2.3, optionally -beta.1 / -rc.2.
const VERSION_PATTERN = /^\d+\.\d+(\.\d+)?(-[0-9A-Za-z.]+)?$/;

const sources = {
	'plugin header (Version:)': {
		file: files.plugin,
		pattern: /^(\s*\*\s*Version:\s*)(\S+)/m,
	},
	'VERSION constant': {
		file: files.plugin,
		pattern: /^(const VERSION = ')([^']+)/m,
	},
	'readme.txt Stable tag': {
		file: files.readme,
		pattern: /^(Stable tag:\s*)(\S+)/m,
	},
	'package.json version': {
		file: files.pkg,
		pattern: /^(\s*"version":\s*")([^"]+)/m,
	},
};

function read( file ) {
	return fs.readFileSync( file, 'utf8' );
}

function fail( message ) {
	process.stderr.write( `${ message }\n` );
	process.exit( 1 );
}

function normalise( version ) {
	return String( version || '' ).replace( /^v/, '' );
}

function readAll() {
	const found = {};

	for ( const [ label, source ] of Object.entries( sources ) ) {
		const match = read( source.file ).match( source.pattern );

		found[ label ] = match ? match[ 2 ] : null;
	}

	return found;
}

function get() {
	const version = readAll()[ 'plugin header (Version:)' ];

	if ( ! version ) {
		fail( 'No Version: line found in the plugin header.' );
	}

	return version;
}

function check( expected ) {
	const found = readAll();
	const version = found[ 'plugin header (Version:)' ];
	const problems = [];

	if ( ! version || ! VERSION_PATTERN.test( version ) ) {
		problems.push(
			`The plugin header version "${ version }" is not a valid version number.`
		);
	}

	for ( const [ label, value ] of Object.entries( found ) ) {
		if ( value !== version ) {
			problems.push(
				`${ label } is "${ value }", but the plugin header says "${ version }".`
			);
		}
	}

	if ( expected && normalise( expected ) !== version ) {
		problems.push(
			`Expected version "${ normalise(
				expected
			) }" (from the tag), but the plugin says "${ version }".`
		);
	}

	if ( version && ! read( files.readme ).includes( `= ${ version } =` ) ) {
		problems.push(
			`readme.txt has no "= ${ version } =" changelog entry.`
		);
	}

	if (
		version &&
		! read( files.changelog ).includes( `## [${ version }]` )
	) {
		problems.push( `CHANGELOG.md has no "## [${ version }]" section.` );
	}

	if ( problems.length ) {
		fail( `Version check failed:\n- ${ problems.join( '\n- ' ) }` );
	}

	return version;
}

function notes( version ) {
	const wanted = normalise( version ) || get();
	const lines = read( files.changelog ).split( /\r?\n/ );
	const start = lines.findIndex( ( line ) =>
		line.startsWith( `## [${ wanted }]` )
	);

	if ( -1 === start ) {
		fail( `CHANGELOG.md has no "## [${ wanted }]" section.` );
	}

	let end = lines.findIndex(
		( line, index ) => index > start && line.startsWith( '## ' )
	);

	if ( -1 === end ) {
		end = lines.length;
	}

	return lines
		.slice( start + 1, end )
		.join( '\n' )
		.trim();
}

function set( version ) {
	version = normalise( version );

	if ( ! VERSION_PATTERN.test( version ) ) {
		fail(
			`"${ version }" is not a valid version number (expected e.g. 1.2.0).`
		);
	}

	const contents = {};

	for ( const source of Object.values( sources ) ) {
		const text = contents[ source.file ] ?? read( source.file );

		if ( ! source.pattern.test( text ) ) {
			fail(
				`Could not find the version in ${ path.relative(
					root,
					source.file
				) }.`
			);
		}

		contents[ source.file ] = text.replace(
			source.pattern,
			( match, prefix ) => prefix + version
		);
	}

	for ( const [ file, text ] of Object.entries( contents ) ) {
		fs.writeFileSync( file, text );
	}

	// Keep the lockfile's own copy in step so `npm ci` does not rewrite it.
	if ( fs.existsSync( files.lock ) ) {
		const lock = JSON.parse( read( files.lock ) );

		lock.version = version;

		if ( lock.packages && lock.packages[ '' ] ) {
			lock.packages[ '' ].version = version;
		}

		fs.writeFileSync(
			files.lock,
			`${ JSON.stringify( lock, null, '\t' ) }\n`
		);
	}

	process.stdout.write(
		`Set the version to ${ version }. Add "## [${ version }]" to CHANGELOG.md and "= ${ version } =" to readme.txt before committing.\n`
	);
}

const [ command, argument ] = process.argv.slice( 2 );

switch ( command ) {
	case 'get':
		process.stdout.write( `${ get() }\n` );
		break;
	case 'check':
		process.stdout.write( `${ check( argument ) }\n` );
		break;
	case 'notes':
		process.stdout.write( `${ notes( argument ) }\n` );
		break;
	case 'set':
		if ( ! argument ) {
			fail( 'Usage: node bin/version.js set x.y.z' );
		}
		set( argument );
		break;
	default:
		fail(
			'Usage: node bin/version.js get | check [x.y.z] | notes [x.y.z] | set x.y.z'
		);
}
