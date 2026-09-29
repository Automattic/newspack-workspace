/**
 * Type-check the calling package with tsc against its own tsconfig.json.
 *
 * This is the canonical type gate: the build pipeline strips types via Babel
 * without checking them, and ESLint runs without a type-aware parserOptions.project,
 * so this script is the only place type errors are actually caught.
 */

'use strict';

const spawn = require( 'cross-spawn' );
const utils = require( './utils/index.js' );
const tsc = require.resolve( 'typescript/bin/tsc' );

utils.log( 'Starting TypeScript check…' );

const result = spawn.sync( tsc, [ '--noEmit', ...process.argv.slice( 2 ) ], {
	stdio: 'inherit',
} );

if ( result.status === 0 ) {
	utils.log( 'All good!' );
}

process.exit( result.status );
