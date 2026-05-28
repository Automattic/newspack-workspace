/**
 * DEPRECATION NOTICE: As of v5.8.0, this script is deprecated as @wordpress/eslint-plugin
 * will perform TypeScript checks on .ts and .tsx files.
 *
 * This script and its dependencies will be removed in a future version of Newspack Scripts.
 */

'use strict';

const spawn = require( 'cross-spawn' );
const utils = require( './utils/index.js' );
const tsc = require.resolve( 'typescript/bin/tsc' );

utils.log( 'Starting TypeScript check…' );

const result = spawn.sync( tsc, [], {
	stdio: 'inherit',
} );

if ( result.status === 0 ) {
	utils.log( 'All good!' );
}

process.exit( result.status );
