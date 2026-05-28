#!/usr/bin/env node

const fs = require( 'fs' );
const path = require( 'path' );
const spawn = require( 'cross-spawn' );
const utils = require( '../scripts/utils/index.js' );

const [ scriptName, ...nodeArgs ] = process.argv.slice( 2 );

/**
 * Resolve script path. If running in GitHub Actions, try to find the script
 * in `scripts/github/` first, otherwise fall back to `scripts/`.
 *
 * @param {string} name Script name.
 * @return {string} Resolved script path.
 */
const resolveScript = ( name ) => {
	if ( process.env.GITHUB_ACTIONS ) {
		const githubScriptPath = path.resolve(
			__dirname,
			'../scripts/github/',
			name + '.js'
		);
		if ( fs.existsSync( githubScriptPath ) ) {
			return githubScriptPath;
		}
	}
	return require.resolve( '../scripts/' + name );
};

if (
	[
		'test',
		'commit',
		'commitlint',
		'release',
		'semantic-release',
		'typescript-check',
		'wp-scripts',
	].includes( scriptName )
) {
	const result = spawn.sync(
		process.execPath,
		[ resolveScript( scriptName ), ...nodeArgs ],
		{ stdio: 'inherit' }
	);
	if ( result.signal ) {
		if ( result.signal === 'SIGKILL' ) {
			utils.log(
				'The build failed because the process exited too early. ' +
					'This probably means the system ran out of memory or someone called ' +
					'`kill -9` on the process.'
			);
		} else if ( result.signal === 'SIGTERM' ) {
			utils.log(
				'The build failed because the process exited too early. ' +
					'Someone might have called `kill` or `killall`, or the system could ' +
					'be shutting down.'
			);
		}
		process.exit( 1 );
	}
	process.exit( result.status );
} else {
	utils.log( `Unknown script "${ scriptName }".` );
}
