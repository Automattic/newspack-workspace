'use strict';

const fs = require( 'fs' );
const path = require( 'path' );

const utils = require( '../utils/index.js' );

const repoName = process.env.GITHUB_REPOSITORY?.split( '/' )[ 1 ];
const releaseAssetPath = path.resolve( `./release/${ repoName }.zip` );

async function prepare() {
	if ( ! repoName ) {
		throw new Error(
			'GITHUB_REPOSITORY is not set; cannot determine release asset path.'
		);
	}
	if ( ! fs.existsSync( releaseAssetPath ) ) {
		throw new Error(
			`Release asset not found at ${ releaseAssetPath }. ` +
				'The `release:archive` script must produce this file before ' +
				'semantic-release publishes the GitHub release.'
		);
	}
	utils.log( `Verified release asset at ${ releaseAssetPath }.` );
}

module.exports = { prepare };
