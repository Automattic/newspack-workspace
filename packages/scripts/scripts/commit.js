'use strict';

const path = require( 'path' );
const bootstrap = require( 'commitizen/dist/cli/git-cz' ).bootstrap;
const cliPath = path.resolve( require.resolve( 'commitizen' ), '../..' );

bootstrap( {
	cliPath,
	config: {
		path: 'cz-conventional-changelog',
	},
} );
