'use strict';

const spawn = require( 'cross-spawn' );
const modules = require( './utils/modules' );
const utils = require( './utils/index.js' );
const wpScripts = require.resolve( '@wordpress/scripts/bin/wp-scripts' );

const args = process.argv.slice( 2 );
const cmd = args.shift();

utils.log( `Running ${ cmd }...` );

const result = spawn.sync( wpScripts, modules.buildArgs( cmd, args ), {
	cwd: modules.rootDirectory,
	stdio: 'inherit',
	env: { ...process.env, NODE_ENV: 'build' === cmd ? 'production' : 'development' },
} );

if ( result.status === 0 ) {
	utils.log( `${ cmd } complete!` );
}

process.exit( result.status );
