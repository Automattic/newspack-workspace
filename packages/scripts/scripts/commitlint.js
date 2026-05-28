'use strict';

const spawn = require( 'cross-spawn' );
const path = require( 'path' );
const commitlint = require.resolve( '@commitlint/cli/cli' );

const result = spawn.sync(
	commitlint,
	[ '--config', path.resolve( __dirname, '../config/commitlint.config.js' ) ],
	{
		stdio: 'inherit',
	}
);

process.exit( result.status );
