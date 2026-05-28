/**
 * A proxy for the semantic-release executable.
 * Note: most Newspack repos use the `release` script.
 * Only newspack-theme currently uses this script.
 */

'use strict';

const spawn = require( 'cross-spawn' );
const utils = require( './utils/index.js' );
const sr = require.resolve( 'semantic-release/bin/semantic-release' );

utils.log( 'Starting TypeScript check…' );

const args = process.argv.slice( 2 ) || [];

const result = spawn.sync( sr, args, {
	stdio: 'inherit',
} );

if ( result.status === 0 ) {
	utils.log( 'All good!' );
}

process.exit( result.status );
